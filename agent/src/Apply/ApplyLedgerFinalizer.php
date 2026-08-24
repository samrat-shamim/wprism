<?php
namespace Duo;

require_once __DIR__ . '/../Scope/ScopedApply.php';
require_once __DIR__ . '/../Scope/ScopedApplyCoordinator.php';
require_once __DIR__ . '/../Scope/ScopedApplySession.php';
require_once __DIR__ . '/../Delete/DeleteGuardEvaluator.php';
if (!class_exists(CompiledRepository::class, false)) {
    require_once __DIR__ . '/../Repository/CompiledArtifact.php';
}
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}

/** Atomically publishes convergence metadata after every rebuild verifies. */
final class ApplyLedgerFinalizer {
    /** Finalization is a bounded receipt boundary, never a bulk ledger API. */
    private const MAX_LOCKED_MAP_ROWS = 100000;
    /** @var \Closure():void */
    private readonly \Closure $renewLease;

    public function __construct(\Closure $renewLease) {
        $this->renewLease = $renewLease;
    }

    public function finalize(
        CompiledRepository $compiled,
        array $plan,
        array $tree,
        array $work,
        array $deleteWork,
        bool $executeDeletes,
        bool $scoped,
        ?array $scopeContract,
        ?ScopedApplySession $scopedSession,
        array $verification,
        string $requestedRevision
    ): void {
        $transactionStarted = false;
        $scopedTransactionBoundary = false;
        try {
            ($this->renewLease)();
            if ($scoped) {
                Db::start_repeatable_read('scoped ledger transaction start');
                $transactionStarted = true;
                DeleteGuardEvaluator::begin_authored_transaction();
                $scopedTransactionBoundary = true;
                global $wpdb;
                DeleteGuardEvaluator::assert_innodb_tables([
                    $wpdb->prefix . 'duo_map',
                    $wpdb->prefix . 'duo_state',
                    $wpdb->prefix . 'duo_kv',
                ], 'scoped ledger finalization');
                DeleteGuardEvaluator::assert_transaction_isolation('scoped ledger finalization');
            } else {
                Db::start('ledger transaction start');
                $transactionStarted = true;
            }
            $lockedAuthorMap = null;
            $authorizedMapDeletes = [];
            if ($scoped) {
                if ($scopedSession === null
                    || $scopedSession->phase() !== ScopedApplySession::PHASE_VERIFYING) {
                    throw new \RuntimeException('duo: scoped ledger finalization has no exact verifying session');
                }
                $authorReceipt = ScopedApplyCoordinator::receipt_at($scopedSession, 1);
                $authorMapHash = is_array($authorReceipt) ? ($authorReceipt['after_hash'] ?? null) : null;
                $verifiedAuthorMapHash = $verification['authored_ledger_map_hash'] ?? null;
                if (!is_string($authorMapHash)
                    || preg_match('/^[a-f0-9]{64}$/D', $authorMapHash) !== 1
                    || !is_string($verifiedAuthorMapHash)
                    || !hash_equals($authorMapHash, $verifiedAuthorMapHash)) {
                    throw new \RuntimeException(
                        'duo: scoped ledger finalization has no exact converged authored-map witness'
                    );
                }
                $lockedAuthorMap = self::locked_map_inventory();
                $lockedAuthorRoots = ScopedApply::ledger_map_roots(
                    (array) $scopedSession->authority()['selection']['ledger_map_identity_hashes'],
                    $lockedAuthorMap
                );
                if (!hash_equals(
                    $authorMapHash,
                    ScopedApplyCoordinator::authored_ledger_map_hash($lockedAuthorRoots)
                ) || !hash_equals(
                    (string) $scopedSession->authority()['target']['protected_ledger_map_hash'],
                    (string) $lockedAuthorRoots['protected_ledger_map_root']
                )) {
                    throw new \RuntimeException(
                        'duo: selected identity map changed before scoped ledger finalization'
                    );
                }
                if ($executeDeletes) {
                    foreach (array_merge($deleteWork, $plan['deleted']) as $row) {
                        $uuid = is_array($row) ? ($row['uuid'] ?? null) : null;
                        if (!is_string($uuid) || $uuid === '') {
                            throw new \RuntimeException(
                                'duo: scoped ledger finalization received a malformed authorized map deletion'
                            );
                        }
                        $authorizedMapDeletes[$uuid] = true;
                    }
                }
            }
            if (!$scoped) {
                Ledger::kv_delete('apply_in_progress');
            }
            foreach ($scoped ? $work : array_merge($plan['unchanged'], $work) as $row) {
                $entity = $tree[$row['uuid']];
                if ($scoped
                    && ($row['uuid'] ?? null) === 'options/core'
                    && $scopeContract !== null
                    && ScopedApply::has_record_scoped_options($scopeContract)) {
                    $document = ScopedApply::selected_option_document(
                        $entity['data'],
                        $scopeContract,
                        $row
                    );
                    foreach (ScopedApply::option_state_hashes($document, $scopeContract) as $identity => $hash) {
                        Ledger::set_state_hash($identity, 'option', $hash);
                    }
                    continue;
                }
                Ledger::set_state_hash($row['uuid'], $entity['type'], $entity['hash']);
            }
            if ($executeDeletes) {
                foreach (array_merge($deleteWork, $plan['deleted']) as $row) {
                    Ledger::forget($row['uuid']);
                    Ledger::set_state_hash($row['uuid'], 'deletion', $row['receipt_hash']);
                }
            }
            if ($scoped) {
                $convergenceHash = (string) ($verification['receipt_hash'] ?? '');
                if (preg_match('/^[a-f0-9]{64}$/D', $convergenceHash) !== 1) {
                    throw new \RuntimeException('duo: scoped convergence receipt has no valid identity');
                }
                $expectedTerminalMap = array_values(array_filter(
                    (array) $lockedAuthorMap,
                    static fn(array $row): bool => !isset($authorizedMapDeletes[(string) $row['uuid']])
                ));
                $expectedTerminalRoots = ScopedApply::ledger_map_roots(
                    (array) $scopedSession->authority()['selection']['ledger_map_identity_hashes'],
                    $expectedTerminalMap
                );
                $terminalMapRoots = ScopedApply::ledger_map_roots(
                    (array) $scopedSession->authority()['selection']['ledger_map_identity_hashes'],
                    self::locked_map_inventory()
                );
                if (!hash_equals(
                    (string) $expectedTerminalRoots['ledger_map_root'],
                    (string) $terminalMapRoots['ledger_map_root']
                ) || !hash_equals(
                    (string) $scopedSession->authority()['target']['protected_ledger_map_hash'],
                    (string) $terminalMapRoots['protected_ledger_map_root']
                )) {
                    throw new \RuntimeException(
                        'duo: identity map changed outside authorized tombstone cleanup during scoped ledger finalization'
                    );
                }
                $scopedSession->complete($convergenceHash, [
                    'protected_ledger_map_hash' => (string) $terminalMapRoots['protected_ledger_map_root'],
                    'selected_ledger_map_hash' => (string) $terminalMapRoots['selected_ledger_map_root'],
                ]);
            } else {
                Ledger::kv_set(
                    'applied_revision',
                    !empty($requestedRevision) ? $requestedRevision : $compiled->revision_hash()
                );
            }
            Db::commit('ledger transaction commit');
            $transactionStarted = false;
            if ($scopedTransactionBoundary) {
                DeleteGuardEvaluator::end_authored_transaction();
                $scopedTransactionBoundary = false;
            }
        } catch (\Throwable $failure) {
            if ($transactionStarted) {
                Db::rollback_after_failure($failure, 'ledger transaction rollback');
            }
            throw $failure;
        } finally {
            if ($scopedTransactionBoundary) {
                DeleteGuardEvaluator::end_authored_transaction();
            }
        }
    }

    /**
     * Traverse the complete PRIMARY map range under the finalizer's exact RR
     * transaction. Re-proving the unique savepoint on both sides makes a
     * callback commit/reconnect loud; reaching the index end locks every row
     * and the supremum gap until the terminal receipt commits.
     *
     * @return array<int,array{uuid:string,entity_type:string,id_kind:string,local_id:int}>
     */
    private static function locked_map_inventory(): array {
        global $wpdb;
        DeleteGuardEvaluator::assert_transaction_isolation('scoped ledger map inventory');
        $limit = self::MAX_LOCKED_MAP_ROWS + 1;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results(
            "SELECT uuid, entity_type, id_kind, local_id FROM {$wpdb->prefix}duo_map "
            . "FORCE INDEX (PRIMARY) ORDER BY uuid ASC, id_kind ASC LIMIT $limit FOR UPDATE",
            ARRAY_A
        );
        if (!is_array($rows) || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('duo: scoped ledger map inventory could not be locked and read');
        }
        DeleteGuardEvaluator::assert_transaction_isolation('scoped ledger map inventory readback');
        if (count($rows) > self::MAX_LOCKED_MAP_ROWS) {
            throw new \RuntimeException('duo: scoped ledger map inventory exceeds the bounded row frontier');
        }
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (array_keys($row) !== ['uuid', 'entity_type', 'id_kind', 'local_id']) {
                throw new \RuntimeException('duo: scoped ledger map inventory returned a malformed row shape');
            }
            $uuid = $row['uuid'];
            $entityType = $row['entity_type'];
            $idKind = $row['id_kind'];
            $rawLocalId = $row['local_id'];
            if (!is_string($uuid) || $uuid === ''
                || !is_string($entityType) || $entityType === ''
                || !is_string($idKind) || $idKind === ''
                || (!is_int($rawLocalId) && !is_string($rawLocalId))
                || preg_match('/^[1-9][0-9]*$/D', (string) $rawLocalId) !== 1
                || (int) $rawLocalId <= 0) {
                throw new \RuntimeException('duo: scoped ledger map inventory returned malformed row values');
            }
            $key = $uuid . "\0" . $idKind;
            if (isset($seen[$key])) {
                throw new \RuntimeException('duo: scoped ledger map inventory contains a duplicate primary identity');
            }
            $seen[$key] = true;
            $out[] = [
                'uuid' => $uuid,
                'entity_type' => $entityType,
                'id_kind' => $idKind,
                'local_id' => (int) $rawLocalId,
            ];
        }
        return $out;
    }
}
