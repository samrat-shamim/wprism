<?php
namespace Duo;

if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}
require_once __DIR__ . '/../Adapter/ProviderActionBatchBuilder.php';
require_once __DIR__ . '/RegenerationContext.php';
require_once __DIR__ . '/RegenerationContextStore.php';

/**
 * Executes legacy and batch regeneration contracts, including durable retry
 * marker ownership, adapter invocation, and declarative verification.
 */
final class DependencyRegenerator {
    private const REGEN_PENDING_PREFIX = ProviderActionBatchBuilder::REGEN_PENDING_PREFIX;
    private const REGEN_DELETE_CONTEXT_PREFIX = RegenerationContextStore::DELETE_PREFIX;
    private const REGEN_REPARENT_CONTEXT_PREFIX = RegenerationContextStore::REPARENT_PREFIX;

    /** @var \Closure(string):bool */
    private readonly \Closure $selectionDeclaresEntityBatchFor;
    /** @var \Closure(string,string):bool */
    private readonly \Closure $selectionDeclaresChannelFor;
    /** @var \Closure(string):bool */
    private readonly \Closure $selectionTriggersProviderActionFor;
    /** @var \Closure(string):bool */
    private readonly \Closure $pinnedProviderActionOwns;
    /** @var \Closure():void */
    private readonly \Closure $heartbeat;

    public function __construct(
        private readonly Policy $policy,
        private readonly RegenerationContextStore $contextStore,
        \Closure $selectionDeclaresEntityBatchFor,
        \Closure $selectionDeclaresChannelFor,
        \Closure $selectionTriggersProviderActionFor,
        \Closure $pinnedProviderActionOwns,
        \Closure $heartbeat
    ) {
        $this->selectionDeclaresEntityBatchFor = $selectionDeclaresEntityBatchFor;
        $this->selectionDeclaresChannelFor = $selectionDeclaresChannelFor;
        $this->selectionTriggersProviderActionFor = $selectionTriggersProviderActionFor;
        $this->pinnedProviderActionOwns = $pinnedProviderActionOwns;
        $this->heartbeat = $heartbeat;
    }

    public function run(array $work, array $tree, array $deleteContext, array &$warnings): void {
        global $wpdb;

        // Batch/refresh dependencies are an explicit opt-in.  They run
        // before the legacy path so a batch adapter can reconcile an existing
        // (but stale) row even when the generic existence check would have
        // skipped it.  TEC and every pre-batch manifest continue through the
        // single-id path below unchanged.
        $this->regen_batch_dependencies($work, $tree, $deleteContext, $warnings);

        $candidates = []; // uuid => post_type
        foreach ($work as $r) {
            $e = $tree[$r['uuid']] ?? null;
            if ($e === null || $e['type'] !== 'post') {
                continue;
            }
            $postType = (string) ($e['data']['type'] ?? '');
            if ($postType !== '' && $this->policy->regen_dependency($postType) !== null
                && $this->policy->regen_batch($postType) === null) {
                $candidates[$r['uuid']] = $postType;
            }
        }
        foreach (Ledger::kv_prefix(self::REGEN_PENDING_PREFIX) as $k => $postType) {
            $uuid = substr($k, strlen(self::REGEN_PENDING_PREFIX));
            if (isset($candidates[$uuid])) {
                continue; // already covered by $work above
            }
            if ($this->policy->regen_dependency($postType) !== null
                && $this->policy->regen_batch($postType) === null) {
                $candidates[$uuid] = $postType;
                continue;
            }
            if ($this->policy->regen_batch($postType) !== null) {
                continue; // batch path owns this marker
            }
            if (($this->selectionDeclaresEntityBatchFor)('post:' . $postType)) {
                // DUO-3342: the provider dispatch owns this marker. It arms
                // regen_pending for the entities it delivers and clears them
                // only on a verified receipt, so a marker armed by a failed
                // provider invocation reaches this loop with no
                // regen_dependency declared for its post type at all — the
                // exact shape the sweep below was written to remove. Sweeping
                // it here would delete the retry evidence between the failure
                // and the retry it exists for.
                continue;
            }
            // Manifest no longer declares this post type's dependency
            // (unpinned, or the declaration was removed) — nothing safe to
            // verify or regenerate against. DUO-3234 design review,
            // addition 2: a marker like this would otherwise sit in duo_kv
            // forever with nothing ever consulting it again — sweep it
            // here, in the same pass that would otherwise have processed
            // it, and say so loudly (an operator auditing duo_kv later has
            // no other way to learn a marker silently vanished, or why).
            Ledger::kv_delete($k);
            $warnings[] = "regen_pending marker for post $uuid (type '$postType') dropped: "
                . "manifest no longer declares a regen_dependency for post type '$postType'";
        }
        if (!$candidates) {
            return;
        }

        $regenerators = $this->policy->regenerators();
        foreach ($candidates as $uuid => $postType) {
            $localId = Ledger::id_for($uuid, Ledger::KIND_POST);
            if ($localId === null) {
                // A $work-sourced candidate was just applied and always
                // resolves; one sourced ONLY from a regen_pending marker
                // (the common retry case this mechanism exists for) may
                // not — the post was deleted since the marker was set, or
                // the marker outlived an apply that never actually created
                // it. Either way nothing is live to verify against, and
                // (DUO-3234 design review, addition 2) a marker for a uuid
                // that will never resolve again must not sit forever —
                // sweep it, loudly, but only if a marker for it actually
                // exists (a $work-sourced candidate with no local id would
                // be a different, more serious bug, not an orphan marker).
                $markerKey = self::REGEN_PENDING_PREFIX . $uuid;
                if (Ledger::kv_get($markerKey) !== null) {
                    Ledger::kv_delete($markerKey);
                    $warnings[] = "regen_pending marker for post $uuid (type '$postType') dropped: "
                        . 'uuid no longer resolves to a local post id';
                }
                continue;
            }
            $decl = $this->policy->regen_dependency($postType);
            $verify = $decl['verify'];
            $markerKey = self::REGEN_PENDING_PREFIX . $uuid;

            if ($this->regen_verify_exists($verify, $localId)) {
                Ledger::kv_delete($markerKey); // self-heals a marker left over from a since-resolved failure
                continue;
            }

            $regenName = (string) $decl['regenerator'];
            $regenerator = $regenerators[$regenName]
                ?? throw new \RuntimeException(
                    "duo: post type '$postType' declares regen_dependency.regenerator='$regenName' "
                    . 'but it did not load (see Policy::regenerators())'
                );
            try {
                $regenerator->regenerate($localId);
            } catch (\Throwable $t) {
                Ledger::kv_set($markerKey, $postType);
                throw new \RuntimeException(
                    "duo: regenerator '$regenName' failed for post $localId (uuid $uuid, type '$postType'): "
                    . $t->getMessage(),
                    0, $t
                );
            }

            if (!$this->regen_verify_exists($verify, $localId)) {
                Ledger::kv_set($markerKey, $postType);
                throw new \RuntimeException(
                    "duo: regen_dependency verification failed for post $localId (uuid $uuid, type '$postType') — "
                    . "expected a row in {$verify['table']} where {$verify['column']} = $localId after calling "
                    . "regenerator '$regenName', found none. Re-running apply will retry (a regen_pending marker "
                    . 'was recorded), but the underlying regeneration mechanism needs investigation.'
                );
            }
            Ledger::kv_delete($markerKey);
        }
    }

    /**
     * Execute opt-in manifest batch regenerators.  The generic engine owns
     * candidate discovery, marker/retry bookkeeping, and the small existence
     * verification contract; the manifest adapter owns plugin-specific exact
     * value verification and root/deletion semantics.
     *
     * A batch declaration with always_on_write=true receives every write
     * candidate of its declared type, even when its verification row already
     * exists. Candidates are this apply's authored work, pending retry
     * markers, and durable deletion receipts; an unrelated/no-op apply does
     * not scan the catalog or load the plugin. Deletion context is captured
     * before the raw delete and remains available until the rebuild pass
     * succeeds.
     */
    private function regen_batch_dependencies(array $work, array $tree, array $deleteContext, array &$warnings): void {
        $jobs = []; // regenerator => {ids, id_types, uuids, deletions, post_types}
        $batchTypes = $this->policy->regen_batch_post_types();

        $ensureJob = function (string $postType, ?int $id = null, ?string $uuid = null) use (&$jobs): void {
            $decl = $this->policy->regen_dependency($postType);
            $batch = $this->policy->regen_batch($postType);
            if ($decl === null || $batch === null || empty($batch['enabled'])) {
                return;
            }
            $name = (string) ($decl['regenerator'] ?? '');
            if ($name === '') {
                return; // Policy validation names malformed declarations.
            }
            if (!isset($jobs[$name])) {
                $jobs[$name] = [
                    'ids' => [],
                    'id_types' => [],
                    'uuids' => [],
                    'deletions' => [],
                    'post_types' => [],
                ];
            }
            $jobs[$name]['post_types'][$postType] = true;
            if ($id !== null && $id > 0) {
                $jobs[$name]['ids'][$id] = $id;
                $jobs[$name]['id_types'][$id] = $postType;
            }
            if ($uuid !== null && $uuid !== '') {
                $jobs[$name]['uuids'][$uuid] = $postType;
            }
        };

        foreach ($work as $entry) {
            $entity = $tree[$entry['uuid']] ?? null;
            if (($entity['type'] ?? '') !== 'post') {
                continue;
            }
            $postType = (string) ($entity['data']['type'] ?? '');
            if (!isset($batchTypes[$postType])) {
                continue;
            }
            $id = Ledger::id_for((string) $entry['uuid'], Ledger::KIND_POST);
            $batch = $batchTypes[$postType];
            if (empty($batch['always_on_write']) && $id !== null) {
                $decl = $this->policy->regen_dependency($postType);
                if ($decl !== null && $this->regen_verify_exists($decl['verify'], $id)) {
                    // A conditional batch declaration retains the original
                    // existence-gated behavior for a changed work item.
                    // Pending markers and deletion receipts below remain
                    // unconditional retries/cleanup candidates.
                    continue;
                }
            }
            $ensureJob($postType, $id, (string) $entry['uuid']);
        }

        // A marker can outlive the content hash and is deliberately a
        // candidate in its own right.  It is also how a failed batch retries
        // naturally when apply_in_progress is no longer the only signal.
        foreach (Ledger::kv_prefix(self::REGEN_PENDING_PREFIX) as $key => $postType) {
            $postType = (string) $postType;
            if (!isset($batchTypes[$postType])) {
                continue;
            }
            $uuid = substr((string) $key, strlen(self::REGEN_PENDING_PREFIX));
            $id = Ledger::id_for($uuid, Ledger::KIND_POST);
            if ($id === null) {
                Ledger::kv_delete((string) $key);
                $warnings[] = "regen_pending marker for post $uuid (type '$postType') dropped: "
                    . 'uuid no longer resolves to a local post id';
                continue;
            }
            $ensureJob($postType, $id, $uuid);
        }

        // Durable derived-state contexts are candidates in their own right.  A
        // previous apply can have committed the raw delete and failed before
        // this rebuild, so the retry must not depend on the current plan still
        // carrying the tombstone.  This is a small marker scan, never a
        // mapped-post/catalog scan.  Drop malformed or now-nonbatch receipts
        // rather than replaying them through an unrelated plugin path.
        $durableContext = [];
        foreach ([
            self::REGEN_DELETE_CONTEXT_PREFIX,
            self::REGEN_REPARENT_CONTEXT_PREFIX,
        ] as $contextPrefix) {
            $channel = $contextPrefix === self::REGEN_REPARENT_CONTEXT_PREFIX ? 'reparents' : 'deletions';
            foreach (Ledger::kv_prefix($contextPrefix) as $key => $encoded) {
                $context = is_string($encoded) ? json_decode($encoded, true) : null;
                $postType = is_array($context) ? (string) ($context['post_type'] ?? '') : '';
                $id = is_array($context) ? (int) ($context['id'] ?? 0) : 0;
                $label = $contextPrefix === self::REGEN_REPARENT_CONTEXT_PREFIX
                    ? 'regen_reparent_context'
                    : 'regen_delete_context';
                $markerUuid = is_array($context) && (string) ($context['uuid'] ?? '') !== ''
                    ? (string) $context['uuid']
                    : substr((string) $key, strlen($contextPrefix));
                if ($postType === '' || $id <= 0) {
                    // Malformed either way: no dispatcher can replay a receipt
                    // with no post type or no captured id. Said out loud for
                    // the same reason the pending-marker sweep below says it:
                    // an operator auditing duo_kv has no other way to learn a
                    // durable receipt vanished, or why.
                    Ledger::kv_delete((string) $key);
                    $warnings[] = "$label marker for post $markerUuid dropped: the stored receipt "
                        . 'carries no post type or no captured local id, so no dispatcher can replay it';
                    continue;
                }
                $surface = 'post:' . $postType;
                if ($this->policy->regen_batch($postType) === null) {
                    if (($this->selectionDeclaresChannelFor)($channel, $surface)) {
                        // DUO-3342: owned by the provider dispatch, which
                        // delivers this marker's row on its matching channel
                        // and deletes it only after a verified receipt. This
                        // pass is not its owner and must not sweep it — doing
                        // so is what made the channel a one-shot read rather
                        // than a durable retry queue.
                        continue;
                    }
                    if (!($this->selectionTriggersProviderActionFor)($surface)
                        && ($this->pinnedProviderActionOwns)($surface)) {
                        // Independent review, F1: the check above asks whether
                        // the owner is running, which an apply that touched
                        // nothing on this surface answers "no" for a marker
                        // that is perfectly valid — silently deleting the retry
                        // evidence a later apply owes work against. The sweep
                        // is therefore run-INDEPENDENT, exactly like the
                        // regen_batch() test one line up: a pinned provider
                        // action claiming this surface keeps the marker when
                        // this run selected nothing there. When the selection
                        // DOES reach the surface and no negotiated capability
                        // wanted the channel, the first branch already fell
                        // through and the sweep below is the right answer —
                        // that is a live claim about consumers, not silence.
                        continue;
                    }
                    Ledger::kv_delete((string) $key);
                    $warnings[] = "$label marker for post $markerUuid (type '$postType') dropped: "
                        . "post type '$postType' declares no batch regen_dependency, and no capability "
                        . "consuming the '$channel' channel claims $surface";
                    continue;
                }
                if (!isset($context['uuid']) || (string) $context['uuid'] === '') {
                    $context['uuid'] = substr((string) $key, strlen($contextPrefix));
                }
                if (!isset($context['kind']) || (string) $context['kind'] === '') {
                    $context['kind'] = $contextPrefix === self::REGEN_REPARENT_CONTEXT_PREFIX
                        ? 'reparent'
                        : 'delete';
                }
                $context['_marker_key'] = (string) $key;
                $durableContext[] = $context;
            }
        }

        // Deletion/reparent contexts are supplied even when there are no live
        // ids in this revision (for example, deleting the last declared parent).
        // Dedupe by kind+UUID+id so a tombstone and a retry receipt cannot
        // cause duplicate plugin calls.
        foreach (array_merge($durableContext, $deleteContext) as $context) {
            $postType = (string) ($context['post_type'] ?? '');
            if (!isset($batchTypes[$postType])) {
                continue;
            }
            $decl = $this->policy->regen_dependency($postType);
            $name = (string) ($decl['regenerator'] ?? '');
            if ($name === '') {
                continue;
            }
            $kind = (string) ($context['kind'] ?? 'delete');
            if ($kind === 'reparent') {
                // A marker-only retry still needs the live child id so a
                // plugin adapter can discover the new root after the old
                // parent receipt was captured. If its ledger mapping has
                // already disappeared, retain old/new context without
                // inventing a live id.
                $uuid = (string) ($context['uuid'] ?? '');
                $liveId = $uuid !== '' ? Ledger::id_for($uuid, Ledger::KIND_POST) : null;
                $ensureJob($postType, $liveId, $uuid !== '' ? $uuid : null);
            } else {
                $ensureJob($postType);
            }
            $identity = (string) ($context['kind'] ?? 'delete') . ':'
                . (string) ($context['uuid'] ?? '') . ':' . (int) ($context['id'] ?? 0);
            if (isset($jobs[$name]['deletions'][$identity])) {
                $context = RegenerationContext::merge(
                    $jobs[$name]['deletions'][$identity],
                    $context
                );
            }
            $jobs[$name]['deletions'][$identity] = $context;
        }

        if (!$jobs) {
            return;
        }

        $regenerators = $this->policy->regenerators();
        foreach ($jobs as $name => $job) {
            $ids = array_values(array_map('intval', $job['ids']));
            $deletions = array_values($job['deletions']);
            // A failed reparent can be followed by a tombstone before the
            // retry. The stale Ledger mapping is intentionally retained until
            // this rebuild succeeds, so pending/reparent discovery may still
            // put the deleted id in $job['ids']. Never hand that id to an
            // adapter as live work: it must observe the deletion context
            // instead. Keep all reparent roots and delete
            // cleanup contexts in the same call.
            $deletedIds = [];
            $deletedUuids = [];
            foreach ($deletions as $context) {
                if (($context['kind'] ?? 'delete') === 'reparent') {
                    continue;
                }
                $id = (int) ($context['id'] ?? 0);
                if ($id > 0) {
                    $deletedIds[$id] = true;
                }
                foreach ((array) ($context['child_ids'] ?? []) as $childId) {
                    $childId = (int) $childId;
                    if ($childId > 0) {
                        $deletedIds[$childId] = true;
                    }
                }
                $uuid = (string) ($context['uuid'] ?? '');
                $postType = (string) ($context['post_type'] ?? '');
                if ($uuid !== '' && $postType !== '') {
                    $deletedUuids[$uuid] = $postType;
                }
            }
            if ($deletedIds) {
                $ids = array_values(array_filter(
                    $ids,
                    static fn(int $id): bool => !isset($deletedIds[$id])
                ));
            }
            $idTypes = $job['id_types'];
            foreach (array_keys($deletedIds) as $deletedId) {
                unset($idTypes[$deletedId]);
            }
            if (!$ids && !$deletions) {
                continue;
            }
            $regenerator = $regenerators[$name]
                ?? throw new \RuntimeException(
                    "duo: batch regen_dependency regenerator '$name' did not load (see Policy::regenerators())"
                );
            $markers = array_fill_keys(array_keys($job['uuids']), true);
            $markFailure = function (\Throwable $t) use ($markers, $job, $name): void {
                foreach ($markers as $uuid => $_) {
                    $postType = (string) ($job['uuids'][$uuid] ?? '');
                    if ($postType !== '') {
                        Ledger::kv_set(self::REGEN_PENDING_PREFIX . $uuid, $postType);
                    }
                }
                throw new \RuntimeException(
                    "duo: batch regenerator '$name' failed: " . $t->getMessage(),
                    0,
                    $t
                );
            };

            if (!method_exists($regenerator, 'regenerate_batch')) {
                $markFailure(new \RuntimeException(
                    "regenerator '$name' opted into batch regeneration but does not define "
                    . 'regenerate_batch(array $liveIds, array $deletionContext, ?callable $heartbeat = null): void'
                ));
            }

            // Renew before and after the opaque plugin call. Newer adapters
            // may also call this callback during their own long live-id,
            // root, deletion, or verification loops; older two-argument
            // adapters remain source-compatible.
            $heartbeat = $this->heartbeat;
            try {
                $heartbeat();
                $batchMethod = new \ReflectionMethod($regenerator, 'regenerate_batch');
                if ($batchMethod->isVariadic() || $batchMethod->getNumberOfParameters() >= 3) {
                    $regenerator->regenerate_batch($ids, $deletions, $heartbeat);
                } else {
                    $regenerator->regenerate_batch($ids, $deletions);
                }
                $heartbeat();
            } catch (\Throwable $t) {
                $markFailure($t);
            }

            // Keep the old declarative verification as a cheap generic
            // safety net. Adapters additionally check their own exact values
            // and exact absence.
            foreach ($idTypes as $id => $postType) {
                $heartbeat();
                $decl = $this->policy->regen_dependency((string) $postType);
                if ($decl === null || !$this->regen_verify_exists($decl['verify'], (int) $id)) {
                    $uuid = Ledger::uuid_for((int) $id, Ledger::KIND_POST);
                    if ($uuid !== null) {
                        Ledger::kv_set(self::REGEN_PENDING_PREFIX . $uuid, (string) $postType);
                    }
                    throw new \RuntimeException(
                        "duo: batch regen_dependency verification failed for post " . (int) $id
                        . " (type '$postType') — expected a row in {$decl['verify']['table']} where "
                        . "{$decl['verify']['column']} = " . (int) $id
                    );
                }
                $uuid = Ledger::uuid_for((int) $id, Ledger::KIND_POST);
                if ($uuid !== null) {
                    Ledger::kv_delete(self::REGEN_PENDING_PREFIX . $uuid);
                }
            }
            foreach ($deletions as $context) {
                $heartbeat();
                $uuid = (string) ($context['uuid'] ?? '');
                if ($uuid !== '') {
                    $markerKey = (string) ($context['_marker_key'] ?? '');
                    if ($markerKey === '') {
                        $markerKey = (($context['kind'] ?? 'delete') === 'reparent'
                            ? self::REGEN_REPARENT_CONTEXT_PREFIX
                            : self::REGEN_DELETE_CONTEXT_PREFIX) . $uuid;
                    }
                    Ledger::kv_delete($markerKey);
                }
            }
            // A deleted UUID may still have a pending marker because its
            // stale Ledger mapping was needed to discover the reparent job.
            // The adapter's successful exact deletion verification is now the
            // convergence boundary for that marker too; retain it on any
            // failure so the next retry repeats both cleanup and roots.
            foreach ($deletedUuids as $uuid => $_postType) {
                Ledger::kv_delete(self::REGEN_PENDING_PREFIX . $uuid);
            }
        }
    }

    /** Declarative existence check ({table, column} only — no plugin
     *  knowledge needed), matching `invalidate`'s own precedent for the
     *  purely-mechanical half of a typed-snapshot declaration. A missing
     *  table is treated as "not satisfied," not skipped — a manifest
     *  declaring a verify table this environment doesn't have is a real
     *  configuration problem the loud failure above should surface, not a
     *  silent pass. */
    private function regen_verify_exists(array $verify, int $localId): bool {
        global $wpdb;
        $table = preg_replace('/[^A-Za-z0-9_]/', '', (string) $verify['table']);
        $col = preg_replace('/[^A-Za-z0-9_]/', '', (string) $verify['column']);
        $prefixed = $wpdb->prefix . $table;
        if (!$this->contextStore->checked_get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $prefixed),
            "verify table $table"
        )) {
            return false;
        }
        return (bool) $this->contextStore->checked_get_var(
            $wpdb->prepare("SELECT 1 FROM `$prefixed` WHERE `$col` = %d LIMIT 1", $localId),
            "verify row in $table"
        );
    }
}
