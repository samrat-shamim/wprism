<?php
namespace Duo;

require_once __DIR__ . '/../Apply/ApplyPlanner.php';
require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Apply/ConvergenceVerifier.php';
require_once __DIR__ . '/../Kernel/OptionState.php';
require_once __DIR__ . '/../Adapter/Providers.php';
require_once __DIR__ . '/ScopedApply.php';
require_once __DIR__ . '/ScopedApplySession.php';
if (!class_exists(CompiledRepository::class, false)) {
    require_once __DIR__ . '/../Repository/CompiledArtifact.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(PromotionLock::class, false)) {
    require_once __DIR__ . '/../Promotion/PromotionLock.php';
}

/**
 * Stateless implementation of the hash-only scoped mutation protocol.
 * Apply owns sequencing; this collaborator owns canonical authority bytes,
 * intent/receipt binding, recovery evidence checks, and derived readbacks.
 */
final class ScopedApplyCoordinator {
    public static function authority(
        array $plan,
        array $work,
        array $deleteWork,
        array $negotiation,
        CompiledRepository $compiled,
        bool $allowDeletes,
        array $scopeContract,
        array $observation,
        array $selectedActions,
        string $promotionOwner,
        string $promotionArtifact,
        ?array $promotionWitness
    ): array {
        $sessionId = PromotionLock::scoped_session_id($promotionOwner, $promotionArtifact);
        $workRows = [];
        foreach ($work as $row) {
            $identity = (string) ($row['uuid'] ?? '');
            $entity = $compiled->tree()[$identity] ?? null;
            if (!is_array($entity)) {
                throw new \RuntimeException('duo: scoped work identity disappeared from frozen artifact');
            }
            if ($identity === 'options/core' && ScopedApply::has_record_scoped_options($scopeContract)) {
                $document = ScopedApply::selected_option_document($entity['data'], $scopeContract, $row);
                foreach (OptionState::records($document) as $name => $record) {
                    $workRows[] = [
                        'identity_hash' => hash('sha256', 'options/core#' . $name),
                        'type' => 'option',
                        'desired_hash' => OptionState::record_hash($record),
                    ];
                }
                continue;
            }
            $workRows[] = [
                'identity_hash' => hash('sha256', $identity),
                'type' => (string) ($entity['type'] ?? ''),
                'desired_hash' => ConvergenceVerifier::hash($entity),
            ];
        }
        self::sort_rows($workRows);

        $deletionRows = [];
        foreach ($deleteWork as $row) {
            $deletionRows[] = [
                'identity_hash' => hash('sha256', (string) ($row['uuid'] ?? '')),
                'receipt_hash' => (string) ($row['receipt_hash'] ?? ''),
                'deletion_kind' => (string) ($row['deletion_kind'] ?? ''),
                'deletion_type' => (string) ($row['deletion_type'] ?? ''),
            ];
        }
        self::sort_rows($deletionRows);

        $actionRows = [];
        $effectRows = [];
        foreach ($selectedActions as $action) {
            $index = (int) ($action['index'] ?? 0);
            $row = [
                'manifest' => (string) ($action['manifest'] ?? ''),
                'index' => $index,
                'declaration_hash' => hash('sha256', Canon::encode($action)),
            ];
            $actionRows[] = $row;
            foreach (Policy::action_effects($action, $index) as $effect) {
                $effectRows[] = [
                    'action_hash' => hash('sha256', Canon::encode($row)),
                    'effect_hash' => hash('sha256', Canon::encode($effect)),
                ];
            }
        }
        self::sort_rows($actionRows);
        self::sort_rows($effectRows);
        $capabilities = (array) ($negotiation['scoped_capabilities'] ?? []);
        if ($selectedActions !== [] && !method_exists(Providers::class, 'negotiate_scoped')) {
            throw new \RuntimeException(
                'duo: scoped apply requires operation-bound action reconciliation before target mutation'
            );
        }
        $ledgerMapIdentityHashes = self::observation_ledger_map_identity_hashes($observation);
        return ScopedApplySession::make_authority(
            (string) $scopeContract['scope_hash'],
            [
                'artifact_hash' => $compiled->artifact_hash(),
                'state_revision_hash' => $compiled->revision_hash(),
                'manifest_hash' => $compiled->manifest_hash(),
            ],
            [
                'owner' => $promotionOwner,
                'artifact_hash' => $promotionArtifact,
                'session_id' => $sessionId,
            ],
            [
                'selected_before_hash' => (string) $observation['selected_before_root'],
                'selected_before_ledger_map_hash' => (string) $observation['selected_ledger_map_root'],
                'protected_ledger_map_hash' => (string) $observation['protected_ledger_map_root'],
                'protected_out_of_scope_hash' => (string) $observation['protected_out_of_scope_root'],
                'ledger_roots_hash' => hash('sha256', Canon::encode([
                    'selected' => (string) $observation['selected_ledger_map_root'],
                    'protected' => (string) $observation['protected_ledger_map_root'],
                ])),
            ],
            [
                'precondition_hash' => ApplyPlanner::plan_precondition_hash($plan),
                'guard_witnesses_hash' => self::guard_witnesses_hash($deleteWork),
            ],
            [
                'work_hash' => hash('sha256', Canon::encode($workRows)),
                'work_items' => $workRows,
                'deletions_hash' => hash('sha256', Canon::encode($deletionRows)),
                'deletion_items' => $deletionRows,
                'action_declarations_hash' => hash('sha256', Canon::encode($actionRows)),
                'action_items' => $actionRows,
                'capabilities_hash' => hash('sha256', Canon::encode($capabilities)),
                'effects_hash' => hash('sha256', Canon::encode($effectRows)),
                'effect_items' => $effectRows,
                'ledger_map_identity_hashes' => $ledgerMapIdentityHashes,
                'ledger_map_identity_set_hash' => ScopedApplySession::hash_value($ledgerMapIdentityHashes),
            ],
            ScopedApply::code_witness_hash($plan, $compiled),
            $promotionWitness === null
                ? null
                : ScopedApplySession::external_promotion_binding($promotionWitness, $allowDeletes)
        );
    }

    /** @return ?list<string> */
    public static function session_ledger_map_identity_hashes(?ScopedApplySession $session): ?array {
        if ($session === null) {
            return null;
        }
        return self::assert_ledger_map_identity_hashes(
            $session->authority()['selection']['ledger_map_identity_hashes'] ?? null,
            'authority'
        );
    }

    public static function allows_target_old_menu_items(
        ?ScopedApplySession $session,
        ?array $scopeContract,
        array $actual
    ): bool {
        if ($session === null) {
            return true;
        }
        $effectivePhase = $session->recorded_recovery_phase() ?? $session->phase();
        if (!in_array($effectivePhase, [
            ScopedApplySession::PHASE_PLANNED,
            ScopedApplySession::PHASE_AUTHORING,
        ], true) || $scopeContract === null) {
            return false;
        }
        return ScopedApply::selected_observation_matches_before(
            $actual,
            $scopeContract,
            (string) ($session->authority()['target']['selected_before_hash'] ?? '')
        );
    }

    /** @return list<string> */
    public static function observation_ledger_map_identity_hashes(array $observation): array {
        return self::assert_ledger_map_identity_hashes(
            $observation['_ledger_map_identity_hashes'] ?? null,
            'observation'
        );
    }

    /** @return list<string> */
    public static function assert_ledger_map_identity_hashes(mixed $hashes, string $source): array {
        if (!is_array($hashes) || !array_is_list($hashes)) {
            throw new \RuntimeException("duo: scoped ledger-map $source has no canonical opaque identity hashes");
        }
        $previous = null;
        foreach ($hashes as $identityHash) {
            if (!is_string($identityHash)
                || preg_match('/^[a-f0-9]{64}$/D', $identityHash) !== 1
                || ($previous !== null && strcmp($previous, $identityHash) >= 0)) {
                throw new \RuntimeException("duo: scoped ledger-map $source has invalid opaque identity hashes");
            }
            $previous = $identityHash;
        }
        return $hashes;
    }

    public static function guard_witnesses_hash(array $deleteWork): string {
        $rows = [];
        foreach ($deleteWork as $row) {
            $rows[] = [
                'identity_hash' => hash('sha256', (string) ($row['uuid'] ?? '')),
                'witnesses_hash' => hash('sha256', Canon::encode((array) ($row['guard_witnesses'] ?? []))),
            ];
        }
        self::sort_rows($rows);
        return hash('sha256', Canon::encode($rows));
    }

    /** Bind the physical selected map generation committed with authored rows. */
    public static function authored_ledger_map_hash(array $observation): string {
        return ScopedApply::authored_ledger_map_hash($observation);
    }

    public static function assert_recovery_selection(
        ScopedApplySession $session,
        array $selectedActions,
        array $negotiation
    ): void {
        $selection = $session->authority()['selection'];
        $actions = [];
        $effects = [];
        foreach ($selectedActions as $action) {
            $index = (int) ($action['index'] ?? 0);
            $row = [
                'manifest' => (string) ($action['manifest'] ?? ''),
                'index' => $index,
                'declaration_hash' => hash('sha256', Canon::encode($action)),
            ];
            $actions[] = $row;
            foreach (Policy::action_effects($action, $index) as $effect) {
                $effects[] = [
                    'action_hash' => hash('sha256', Canon::encode($row)),
                    'effect_hash' => hash('sha256', Canon::encode($effect)),
                ];
            }
        }
        self::sort_rows($actions);
        self::sort_rows($effects);
        if (Canon::encode($actions) !== Canon::encode((array) ($selection['action_items'] ?? []))
            || Canon::encode($effects) !== Canon::encode((array) ($selection['effect_items'] ?? []))
            || !hash_equals(
                (string) ($selection['capabilities_hash'] ?? ''),
                hash('sha256', Canon::encode((array) ($negotiation['scoped_capabilities'] ?? [])))
            )) {
            throw new \RuntimeException(
                'duo: scoped apply recovery action/capability evidence changed; opaque effects were not replayed'
            );
        }
    }

    public static function intent(
        ScopedApplySession $session,
        int $ordinal,
        string $actionIdentity,
        string $operationIdentity,
        string $inputHash,
        string $effectHash,
        string $beforeHash
    ): array {
        return [
            'ordinal' => $ordinal,
            'authority_hash' => $session->authority_hash_value(),
            'lease_hash' => ScopedApplySession::lease_hash($session->lease()),
            'action_hash' => hash('sha256', $actionIdentity),
            'operation_hash' => hash('sha256', $operationIdentity),
            'input_hash' => $inputHash,
            'effect_hash' => $effectHash,
            'before_hash' => $beforeHash,
        ];
    }

    public static function receipt(array $intent, string $afterHash): array {
        return $intent + ['after_hash' => $afterHash];
    }

    public static function action_effect_hash(array $action): string {
        return hash('sha256', Canon::encode(Policy::action_effects(
            $action,
            (int) ($action['index'] ?? 0)
        )));
    }

    public static function effect_operation(
        ScopedApplySession $session,
        int $ordinal,
        string $inputHash,
        string $effectHash
    ): array {
        return [
            'authority_hash' => $session->authority_hash_value(),
            'lease_session_id' => $session->session_id(),
            'operation_id' => 'effect-' . substr($session->authority_hash_value(), 0, 24) . '-' . $ordinal,
            'input_hash' => $inputHash,
            'effect_hash' => $effectHash,
        ];
    }

    public static function assert_effect_result(
        array $result,
        array $operation,
        string $capabilityDigest
    ): void {
        $keys = array_keys($result);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'after_hash', 'before_hash', 'capability_digest', 'format',
            'operation', 'status', 'verified',
        ]
            || ($result['format'] ?? '') !== Providers::SCOPED_RECEIPT_FORMAT
            || ($result['status'] ?? '') !== 'verified'
            || ($result['verified'] ?? null) !== true
            || Canon::encode((array) ($result['operation'] ?? [])) !== Canon::encode($operation)
            || !hash_equals($capabilityDigest, (string) ($result['capability_digest'] ?? ''))
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($result['before_hash'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($result['after_hash'] ?? '')) !== 1) {
            throw new \RuntimeException('duo: scoped effect returned an invalid reviewed receipt');
        }
    }

    public static function public_action_receipt(
        string $source,
        string $kind,
        array $operation,
        string $capabilityDigest,
        ?array $receipt,
        string $status = 'verified'
    ): array {
        if ($receipt === null) {
            throw new \RuntimeException('duo: scoped action has no durable outer receipt');
        }
        return [
            'format' => Providers::SCOPED_RECEIPT_FORMAT,
            'source_hash' => hash('sha256', $source),
            'kind' => $kind,
            'operation_hash' => hash('sha256', Canon::encode($operation)),
            'capability_digest' => $capabilityDigest,
            'receipt_hash' => hash('sha256', Canon::encode($receipt)),
            'status' => $status,
            'verified' => true,
        ];
    }

    public static function receipt_at(?ScopedApplySession $session, int $ordinal): ?array {
        if ($session === null) {
            return null;
        }
        foreach ($session->receipts() as $receipt) {
            if ((int) ($receipt['ordinal'] ?? 0) === $ordinal) {
                return $receipt;
            }
        }
        return null;
    }

    public static function core_readback_hash(Policy $policy, array $work, array $tree): string {
        global $wpdb;
        $schedules = [];
        foreach ($work as $entry) {
            $identity = (string) ($entry['uuid'] ?? '');
            $entity = $tree[$identity] ?? null;
            if (!is_array($entity) || ($entity['type'] ?? '') !== 'post') {
                continue;
            }
            $id = Ledger::id_for($identity, Ledger::KIND_POST);
            if ($id === null) {
                continue;
            }
            $schedules[] = [
                'identity_hash' => hash('sha256', $identity),
                'next_publish_hash' => hash('sha256', Canon::encode(
                    wp_next_scheduled('publish_future_post', [$id])
                )),
            ];
        }
        self::sort_rows($schedules);
        $counts = [];
        $taxonomies = array_values(array_unique(array_merge($policy->taxonomies(), ['nav_menu'])));
        sort($taxonomies, SORT_STRING);
        foreach ($taxonomies as $taxonomy) {
            $wpdb->last_error = '';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT term_taxonomy_id, count FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s ORDER BY term_taxonomy_id",
                $taxonomy
            ), ARRAY_A);
            if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException(
                    'duo: scoped engine-effect taxonomy-count readback failed; recovery_required'
                );
            }
            foreach ($rows as $row) {
                $counts[] = [
                    'taxonomy_hash' => hash('sha256', (string) $taxonomy),
                    'target_identity_hash' => hash('sha256', (string) ($row['term_taxonomy_id'] ?? '')),
                    'count' => (int) ($row['count'] ?? 0),
                ];
            }
        }
        return hash('sha256', Canon::encode([
            'future_schedules' => $schedules,
            'taxonomy_counts' => $counts,
        ]));
    }

    /** @param list<array<string,mixed>> $rows */
    private static function sort_rows(array &$rows): void {
        usort($rows, static fn(array $a, array $b): int =>
            strcmp(Canon::encode($a), Canon::encode($b))
        );
    }
}
