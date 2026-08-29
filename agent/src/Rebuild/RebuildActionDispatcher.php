<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/NativeActions.php';
require_once __DIR__ . '/../Adapter/ProviderActionBatchBuilder.php';
require_once __DIR__ . '/../Adapter/Providers.php';
require_once __DIR__ . '/../Scope/ScopedApplyCoordinator.php';
require_once __DIR__ . '/../Scope/ScopedApplySession.php';
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}

/**
 * Dispatches selected native/provider rebuild actions and owns their retry
 * markers, scoped effect reconciliation, public receipts, and cache boundary.
 */
final class RebuildActionDispatcher {
    private const REGEN_PENDING_PREFIX = ProviderActionBatchBuilder::REGEN_PENDING_PREFIX;

    /** @var \Closure():void */
    private readonly \Closure $renewLease;

    public function __construct(
        private readonly Policy $policy,
        private readonly ProviderActionBatchBuilder $batchBuilder,
        \Closure $renewLease
    ) {
        $this->renewLease = $renewLease;
    }

    public function dispatch(
        array $selectedActions,
        array $negotiatedProviders,
        array $work,
        array $tree,
        array $appliedDeletions,
        array $regenContext,
        array $durableReparents,
        array $durableDeletions,
        bool $retryingIncompleteApply,
        bool $scoped,
        ?ScopedApplySession $scopedSession,
        ?array $scopedObservation,
        array &$warnings,
        array &$actionReceipts
    ): void {
        if ($scoped && ($scopedSession === null || $scopedObservation === null)) {
            throw new \RuntimeException('wprism: scoped rebuild action dispatch has no durable session observation');
        }
        $batchBuilder = $this->batchBuilder;

        if ($selectedActions !== []) {
            Db::checkpoint('rebuild object cache (pre-action)');
            if (wp_cache_flush() === false) {
                throw new \RuntimeException('wprism: required pre-action object-cache flush failed');
            }
        }

        $declarations = $selectedActions === [] ? [] : $this->policy->provider_declarations();
        $scopedOrdinal = 3;
        foreach ($selectedActions as $action) {
            $actionOrdinal = $scopedOrdinal++;
            $source = Policy::action_source($action, (int) ($action['index'] ?? 0));
            try {
                if (($action['kind'] ?? '') === 'native') {
                    if ($scoped) {
                        $nativeAction = (string) $action['action'];
                        $nativeArgs = (array) ($action['args'] ?? []);
                        $inputHash = NativeActions::scoped_input_hash($nativeAction, $nativeArgs);
                        $capabilityDigest = NativeActions::scoped_action_digest($nativeAction);
                        $effectHash = ScopedApplyCoordinator::action_effect_hash($action);
                        $operation = ScopedApplyCoordinator::effect_operation($scopedSession, $actionOrdinal, $inputHash, $effectHash);
                        $intent = ScopedApplyCoordinator::intent($scopedSession,
                            $actionOrdinal,
                            $capabilityDigest,
                            $operation['operation_id'],
                            $inputHash,
                            $effectHash,
                            (string) $scopedObservation['selected_before_root']
                        );
                        $scopedSession->append_intent($intent);
                        $receipt = ScopedApplyCoordinator::receipt_at($scopedSession, $actionOrdinal);
                        if ($receipt === null) {
                            $reviewed = NativeActions::reconcile_scoped($nativeAction, $nativeArgs, $operation);
                            if (($reviewed['status'] ?? '') === 'not_started') {
                                ($this->renewLease)();
                                $reviewed = NativeActions::invoke_scoped($nativeAction, $nativeArgs, $operation);
                                ($this->renewLease)();
                            }
                            ScopedApplyCoordinator::assert_effect_result($reviewed, $operation, $capabilityDigest);
                            $scopedSession->append_receipt(ScopedApplyCoordinator::receipt(
                                $intent,
                                hash('sha256', Canon::encode($reviewed))
                            ));
                            $receipt = ScopedApplyCoordinator::receipt_at($scopedSession, $actionOrdinal);
                        } else {
                            // The outer receipt proves what the previous
                            // process observed, not that the postcondition is
                            // still true. Recovery always re-reads it before
                            // trusting completion and never invokes again.
                            $reviewed = NativeActions::reconcile_scoped(
                                $nativeAction,
                                $nativeArgs,
                                $operation
                            );
                            ScopedApplyCoordinator::assert_effect_result(
                                $reviewed,
                                $operation,
                                $capabilityDigest
                            );
                        }
                        ScopedApplySession::require_reviewed_effect_receipt($receipt, $reviewed);
                        $warnings[] = "native action fired: $nativeAction (scoped, verified)";
                        $actionReceipts[] = ScopedApplyCoordinator::public_action_receipt(
                            $source,
                            'native',
                            $operation,
                            $capabilityDigest,
                            $receipt
                        );
                        continue;
                    }
                    $receipt = NativeActions::execute(
                        (string) $action['action'],
                        (array) ($action['args'] ?? [])
                    );
                    // issue #3282: unconditional per-declaration confirmation
                    // that this pass actually invoked the declaration — the
                    // layer that was previously unverifiable from outside (a
                    // caller could only ever infer it indirectly, e.g. by
                    // querying a rebuilder's own side-effect table after the
                    // fact, as issue #3267's grind script did before that fix
                    // existed). The structured receipt below carries the
                    // observed before/after state the warning line cannot.
                    $warnings[] = "native action fired: {$action['action']} (verified)";
                    $actionReceipts[] = [
                        'manifest' => (string) $action['manifest'],
                        'source' => $source,
                        'kind' => 'native',
                        'before' => $receipt['before'],
                        'after' => $receipt['after'],
                        'verified' => true,
                    ];
                    continue;
                }
                $id = (string) $action['provider'];
                $capability = (string) $action['capability'];
                $declaration = $negotiatedProviders['capabilities'][$id][$capability] ?? null;
                if ($declaration === null) {
                    // Unreachable: run() negotiates the same selection before
                    // any mutation and refuses on any problem. Fail closed
                    // rather than fatal on a null instance if that ordering
                    // is ever changed.
                    throw new \RuntimeException(
                        "wprism: required manifest action '$source' was never negotiated before mutation"
                    );
                }
                $entities = [];
                $context = [];
                $pendingMarkers = [];
                $deliveredMarkerKeys = [];
                $deletedUuids = [];
                if ($declaration['scope'] === 'entity') {
                    $context = $batchBuilder->action_context(
                        $action,
                        $declaration,
                        $appliedDeletions,
                        $regenContext,
                        $durableReparents,
                        $durableDeletions,
                        $retryingIncompleteApply
                    );
                    // Deletion rows are assembled whether or not the capability
                    // asked for them, because they answer a second question no
                    // declaration can waive: which of the ids the entity batch
                    // would otherwise carry are for entities that are GONE
                    // (regen_batch_dependencies()'s own deleted-id filter, same
                    // rows, same child_ids). action_context() still assembles
                    // only declared channels — an undeclared channel delivers
                    // nothing — so this is the identical projection reused, not
                    // a second delivery path.
                    $deletionRows = $context['deletions']
                        ?? $batchBuilder->action_deletions($action, $appliedDeletions, $durableDeletions);
                    $batch = $batchBuilder->action_entities(
                        $action,
                        $work,
                        $tree,
                        $deletionRows,
                        !$scoped
                    );
                    $entities = $batch['entities'];
                    $pendingMarkers = $batch['markers'];
                    foreach ($batch['warnings'] as $warning) {
                        $warnings[] = $warning;
                    }
                    if (Providers::declares_channel($declaration, 'deletions')) {
                        $deliveredMarkerKeys = array_merge(
                            $deliveredMarkerKeys,
                            $batchBuilder->action_marker_keys($action, $durableDeletions)
                        );
                        foreach ($deletionRows as $row) {
                            $deletedUuid = (string) ($row['uuid'] ?? '');
                            if ($deletedUuid !== '' && (string) ($row['post_type'] ?? '') !== '') {
                                $deletedUuids[$deletedUuid] = (string) $row['post_type'];
                            }
                        }
                    }
                    if (Providers::declares_channel($declaration, 'reparents')) {
                        $deliveredMarkerKeys = array_merge(
                            $deliveredMarkerKeys,
                            $batchBuilder->action_marker_keys($action, $durableReparents)
                        );
                    }
                }
                if ($declaration['scope'] === 'entity'
                    && !$batchBuilder->action_batch_has_work($declaration, $entities, $context)) {
                    // A trigger can select this action off deletion or retry-
                    // tombstone surfaces alone (rebuild_surfaces() includes
                    // both), and a deleted entity has no generated data left
                    // to regenerate. Invoking with an empty batch would let
                    // the provider verify the nothing it received and record
                    // a repair as done — so the skip is explicit, in both the
                    // human line and the machine receipt, never silent.
                    //
                    // issue #3369 narrowed WHEN that is true rather than
                    // loosening it: a capability that declared the `deletions`
                    // channel asked to be told about tombstones, so a
                    // deletion-only selection is real work for it and no
                    // longer skipped. The skip survives for exactly the case
                    // it was written for — nothing declared, or nothing
                    // declared carried work. The receipt strings below stay
                    // byte-identical on the channel-less path.
                    $declared = (array) ($declaration['context'] ?? []);
                    $channelState = $batchBuilder->skipped_channel_states($declared, $context);
                    if ($scoped) {
                        $inputHash = Providers::scoped_input_hash($action, $declaration, $entities, $context);
                        $capabilityDigest = (string) ($negotiatedProviders['scoped_capabilities'][$id][$capability]['capability_digest'] ?? '');
                        $effectHash = ScopedApplyCoordinator::action_effect_hash($action);
                        $operation = ScopedApplyCoordinator::effect_operation($scopedSession, $actionOrdinal, $inputHash, $effectHash);
                        $intent = ScopedApplyCoordinator::intent($scopedSession,
                            $actionOrdinal,
                            $capabilityDigest,
                            $operation['operation_id'],
                            $inputHash,
                            $effectHash,
                            (string) $scopedObservation['selected_before_root']
                        );
                        $scopedSession->append_intent($intent);
                        if (ScopedApplyCoordinator::receipt_at($scopedSession, $actionOrdinal) === null) {
                            $skipHash = hash('sha256', Canon::encode([
                                'status' => 'bounded_skip',
                                'operation' => $operation,
                                'capability_digest' => $capabilityDigest,
                            ]));
                            $scopedSession->append_receipt(
                                ScopedApplyCoordinator::receipt($intent, $skipHash)
                            );
                        }
                        $warnings[] = "provider capability skipped: $id $capability (scoped empty batch)";
                        $actionReceipts[] = ScopedApplyCoordinator::public_action_receipt(
                            $source,
                            'provider',
                            $operation,
                            $capabilityDigest,
                            ScopedApplyCoordinator::receipt_at($scopedSession, $actionOrdinal),
                            'bounded_skip'
                        );
                        continue;
                    }
                    $warnings[] = "provider capability skipped: $id $capability "
                        . '(entity-scoped; no created/updated entity matched its triggers this run'
                        . ($declared === [] ? '' : ', and no declared batch channel carried work: '
                            . $channelState) . ')';
                    $actionReceipts[] = [
                        'manifest' => (string) $action['manifest'],
                        'source' => $source,
                        'kind' => 'provider',
                        'skipped' => $declared === []
                            ? 'empty entity batch (deletion/tombstone-only trigger match)'
                            : 'empty entity batch and no declared batch channel carried work ('
                                . $channelState . ')',
                    ];
                    continue;
                }
                if ($scoped) {
                    $inputHash = Providers::scoped_input_hash($action, $declaration, $entities, $context);
                    $capabilityDigest = (string) ($negotiatedProviders['scoped_capabilities'][$id][$capability]['capability_digest'] ?? '');
                    $effectHash = ScopedApplyCoordinator::action_effect_hash($action);
                    $operation = ScopedApplyCoordinator::effect_operation($scopedSession, $actionOrdinal, $inputHash, $effectHash);
                    $intent = ScopedApplyCoordinator::intent($scopedSession,
                        $actionOrdinal,
                        $capabilityDigest,
                        $operation['operation_id'],
                        $inputHash,
                        $effectHash,
                        (string) $scopedObservation['selected_before_root']
                    );
                    $scopedSession->append_intent($intent);
                    $outerReceipt = ScopedApplyCoordinator::receipt_at($scopedSession, $actionOrdinal);
                    if ($outerReceipt === null) {
                        ($this->renewLease)();
                        $reviewed = Providers::reconcile_scoped(
                            $negotiatedProviders['providers'][$id],
                            $action,
                            $declaration,
                            $operation,
                            $entities,
                            $context
                        );
                        if (($reviewed['status'] ?? '') === 'not_started') {
                            $reviewed = Providers::invoke_scoped(
                                $negotiatedProviders['providers'][$id],
                                $action,
                                $declaration,
                                $operation,
                                $entities,
                                $context
                            );
                        }
                        ($this->renewLease)();
                        ScopedApplyCoordinator::assert_effect_result($reviewed, $operation, $capabilityDigest);
                        $scopedSession->append_receipt(ScopedApplyCoordinator::receipt(
                            $intent,
                            hash('sha256', Canon::encode($reviewed))
                        ));
                        $outerReceipt = ScopedApplyCoordinator::receipt_at($scopedSession, $actionOrdinal);
                    } else {
                        ($this->renewLease)();
                        $reviewed = Providers::reconcile_scoped(
                            $negotiatedProviders['providers'][$id],
                            $action,
                            $declaration,
                            $operation,
                            $entities,
                            $context
                        );
                        ($this->renewLease)();
                        ScopedApplyCoordinator::assert_effect_result(
                            $reviewed,
                            $operation,
                            $capabilityDigest
                        );
                    }
                    ScopedApplySession::require_reviewed_effect_receipt($outerReceipt, $reviewed);
                    $version = (string) ($declarations[$id]['version'] ?? '?');
                    $warnings[] = "provider capability fired: $id@$version $capability (scoped, verified)";
                    $actionReceipts[] = ScopedApplyCoordinator::public_action_receipt(
                        $source,
                        'provider',
                        $operation,
                        $capabilityDigest,
                        $outerReceipt
                    );
                    continue;
                }
                // Arm the retry vocabulary BEFORE the opaque call, not after a
                // caught failure: a provider can exhaust memory or hit a fatal
                // that no catch block here observes, and the marker's whole
                // job is to survive that. Clearing happens only once the
                // receipt says verified === true (below), so failure — caught,
                // uncaught, or a crash mid-flight — leaves every marker armed
                // and the next apply re-delivers exactly this batch.
                foreach ($pendingMarkers as $markerUuid => $markerPostType) {
                    Ledger::kv_set(self::REGEN_PENDING_PREFIX . $markerUuid, (string) $markerPostType);
                }
                // Renew the promotion lease either side of the call, the same
                // bracket regen_batch_dependencies() puts around its own
                // opaque plugin call. The provider contract has no heartbeat
                // parameter (invoke() takes a capability name and typed args,
                // nothing else), so this bracket is all the engine can honestly
                // offer: a call that runs longer than the lease TTL is bounded
                // by timeout_seconds rather than kept alive mid-flight.
                ($this->renewLease)();
                $receipt = Providers::invoke(
                    $negotiatedProviders['providers'][$id],
                    $action,
                    $declaration,
                    $entities,
                    $context
                );
                ($this->renewLease)();
                // Clear-on-verified, marker-key addressed: exactly the markers
                // whose rows this invocation delivered, never a prefix sweep.
                // A verified receipt is the convergence boundary the batch
                // path's own exact-verification clearing uses (:6114-6134);
                // anything short of it keeps the marker armed.
                foreach ($deliveredMarkerKeys as $markerKey) {
                    Ledger::kv_delete($markerKey);
                }
                foreach ($pendingMarkers as $markerUuid => $_markerPostType) {
                    Ledger::kv_delete(self::REGEN_PENDING_PREFIX . $markerUuid);
                }
                // A deleted uuid can still carry a pending marker, because its
                // stale ledger mapping is what discovered the entity in the
                // first place. Only a capability that was actually TOLD about
                // the deletion may retire it — the batch path clears it on the
                // strength of the adapter's exact absence verification, and a
                // capability that never declared `deletions` performed no such
                // check. Left armed, it is swept by the resolve-or-drop pass in
                // action_entities() once the ledger forgets the mapping.
                foreach ($deletedUuids as $deletedUuid => $_deletedPostType) {
                    Ledger::kv_delete(self::REGEN_PENDING_PREFIX . $deletedUuid);
                }
                $version = (string) ($declarations[$id]['version'] ?? '?');
                $warnings[] = "provider capability fired: $id@$version $capability ("
                    . $receipt['duration_seconds'] . 's, verified)';
                $actionReceipts[] = [
                    'manifest' => (string) $action['manifest'],
                    'source' => $source,
                    'kind' => 'provider',
                    'provider_version' => $version,
                    'duration_seconds' => $receipt['duration_seconds'],
                    'before' => $receipt['before'],
                    'after' => $receipt['after'],
                    'verified' => true,
                ];
            } catch (\Throwable $t) {
                if ($scoped && $scopedSession !== null
                    && !$scopedSession->is_recovery_required()
                    && !$scopedSession->is_terminal()) {
                    $scopedSession->recover(hash('sha256', 'wprism:scoped-effect-reconciliation-refused'));
                }
                // issue #3206 posture, unchanged by the channel swap: a failed
                // required rebuild is a hard apply failure, never a warning,
                // so the target stays truthfully unapplied and retryable.
                if (str_starts_with($t->getMessage(), 'wprism: required manifest action')) {
                    throw $t;
                }
                // The inner message rides in the wrapper because nothing in
                // the product path renders getPrevious() — Cli's handlers all
                // print getMessage() alone. Providers assemble exit codes and
                // stdout/stderr tails precisely so an operator sees the real
                // error (issue #3282); swallowing them here would recreate the
                // "exited 255, go reproduce it by hand" experience that issue
                // closed (independent review of this change caught exactly
                // that regression before it shipped).
                throw new \RuntimeException(
                    "wprism: required manifest action '$source' failed — " . $t->getMessage(),
                    0,
                    $t
                );
            }
        }

        Db::checkpoint('rebuild object cache');
        if (wp_cache_flush() === false) {
            throw new \RuntimeException('wprism: required object-cache flush failed');
        }
    }
}
