<?php
namespace Duo;

require_once __DIR__ . '/../Scope/ScopedApply.php';
require_once __DIR__ . '/../Scope/ScopedApplySession.php';
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
        try {
            ($this->renewLease)();
            Db::start('ledger transaction start');
            $transactionStarted = true;
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
                if ($scopedSession === null
                    || $scopedSession->phase() !== ScopedApplySession::PHASE_VERIFYING) {
                    throw new \RuntimeException('duo: scoped ledger finalization has no exact verifying session');
                }
                $convergenceHash = (string) ($verification['receipt_hash'] ?? '');
                if (preg_match('/^[a-f0-9]{64}$/D', $convergenceHash) !== 1) {
                    throw new \RuntimeException('duo: scoped convergence receipt has no valid identity');
                }
                $terminalMapRoots = ScopedApply::ledger_map_roots(
                    (array) $scopedSession->authority()['selection']['ledger_map_identity_hashes']
                );
                if (!hash_equals(
                    (string) $scopedSession->authority()['target']['protected_ledger_map_hash'],
                    (string) $terminalMapRoots['protected_ledger_map_root']
                )) {
                    throw new \RuntimeException(
                        'duo: protected identity map changed during scoped ledger finalization'
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
        } catch (\Throwable $failure) {
            if ($transactionStarted) {
                Db::rollback_after_failure($failure, 'ledger transaction rollback');
            }
            throw $failure;
        }
    }
}
