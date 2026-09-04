<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/DatabaseExceptions.php';

if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
require_once __DIR__ . '/../Kernel/NativeDatabaseProfile.php';
require_once __DIR__ . '/../Kernel/TransientDbException.php';
require_once __DIR__ . '/../Kernel/TransactionAuthority.php';
require_once __DIR__ . '/CodeBaselineTransaction.php';
require_once __DIR__ . '/LifecyclePlanner.php';
require_once __DIR__ . '/PromotionLease.php';

/** Exact terminal publication after WordPress lifecycle hooks have finished. */
final class CodeBaselinePublication {
    public static function publish_terminal(
        array $desired,
        ?string $expectedCodeBoundary
    ): void {
        global $wpdb;
        if ($expectedCodeBoundary !== null
            && preg_match('/^[a-f0-9]{64}$/D', $expectedCodeBoundary) !== 1) {
            throw new \InvalidArgumentException('terminal code baseline boundary is malformed');
        }
        PromotionLease::assert_process_fence();
        $transactionOpen = false;
        $terminalControlAttempted = false;
        try {
            $ledgerTable = $wpdb->prefix . 'wprism_kv';
            Db::start_repeatable_read(
                'terminal code baseline transaction start',
                new NativeDatabaseProfile([$wpdb->options, $ledgerTable], [$ledgerTable])
            );
            $transactionOpen = true;
            $authority = Db::transaction_authority(
                'terminal code baseline initial transaction proof'
            );
            self::assert_authority($authority, 'after transaction start');
            $locked = CodeBaselineTransaction::lock_current(
                $desired,
                $authority,
                'terminal code baseline publication'
            );
            self::assert_authority($authority, 'after lifecycle and ledger locks');
            $snapshot = LifecyclePlanner::code_baseline_publication_snapshot(
                $desired,
                $locked->observation()
            );
            if ($expectedCodeBoundary !== null
                && !hash_equals($expectedCodeBoundary, $snapshot['code_boundary_sha256'])) {
                throw new \RuntimeException(
                    'wprism: installed code or its baseline changed during lifecycle reconciliation; baseline was not published'
                );
            }

            $baseline = $snapshot['baseline_bytes'];
            $locked->publish_terminal($baseline);
            self::assert_authority($authority, 'after terminal baseline publication');
            $readback = $locked->readback();
            if ($readback['receipt'] !== null
                || !is_string($readback['baseline'])
                || !hash_equals($baseline, $readback['baseline'])) {
                throw new \RuntimeException(
                    'wprism: terminal code baseline ledger readback disagrees with authored bytes'
                );
            }
            LifecyclePlanner::assert_code_baseline_publication(
                $desired,
                $snapshot['live_facts_sha256'],
                $baseline,
                $locked->reread_observation(
                    $readback['baseline'],
                    'terminal code baseline publication proof'
                )
            );
            self::assert_authority($authority, 'before terminal baseline commit');
            $terminalControlAttempted = true;
            try {
                Db::commit('terminal code baseline transaction commit');
            } catch (DatabaseMutationException|TransientDbException $notCommitted) {
                Db::rollback_after_failure(
                    $notCommitted,
                    'terminal code baseline rollback after refused commit'
                );
                $transactionOpen = false;
                $terminalControlAttempted = false;
                throw $notCommitted;
            }
            $transactionOpen = false;
        } catch (\Throwable $failure) {
            if ($transactionOpen && !$terminalControlAttempted) {
                Db::rollback_after_failure($failure, 'terminal code baseline transaction rollback');
            }
            throw $failure;
        }
    }

    private static function assert_authority(
        TransactionAuthority $authority,
        string $context
    ): void {
        PromotionLease::assert_process_fence();
        if (!$authority->equals(Db::transaction_authority('terminal code baseline ' . $context))) {
            throw new DatabaseTransactionOutcomeException(
                'terminal code baseline changed database session authority ' . $context
            );
        }
    }
}
