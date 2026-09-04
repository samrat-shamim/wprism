<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/DatabaseExceptions.php';

if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
require_once __DIR__ . '/../Kernel/TransactionAuthority.php';
require_once __DIR__ . '/CodeBaselineTransaction.php';
require_once __DIR__ . '/LifecyclePlanner.php';
require_once __DIR__ . '/PromotionLease.php';

/** Exact capture-time baseline decision inside CaptureTransaction's transaction. */
final class CodeBaselineCapture {
    /** @return list<array<string,mixed>> */
    public static function observe_or_publish(array $desired): array {
        PromotionLease::assert_process_fence();
        $authority = Db::transaction_authority('capture code baseline transaction proof');
        $locked = CodeBaselineTransaction::lock_current(
            $desired,
            $authority,
            'capture code baseline'
        );
        self::assert_authority($authority, 'after lifecycle and ledger locks');
        $snapshot = LifecyclePlanner::code_baseline_capture_snapshot(
            $desired,
            $locked->observation()
        );
        if ($snapshot['code_drift'] !== []) {
            return $snapshot['code_drift'];
        }

        $baseline = $snapshot['baseline_bytes'];
        $locked->publish_terminal($baseline);
        self::assert_authority($authority, 'after capture baseline publication');
        $readback = $locked->readback();
        if ($readback['receipt'] !== null
            || !is_string($readback['baseline'])
            || !hash_equals($baseline, $readback['baseline'])) {
            throw new \RuntimeException(
                'wprism: capture code baseline ledger readback disagrees with authored bytes'
            );
        }
        LifecyclePlanner::assert_code_baseline_publication(
            $desired,
            $snapshot['live_facts_sha256'],
            $baseline,
            $locked->reread_observation(
                $readback['baseline'],
                'capture code baseline publication proof'
            )
        );
        self::assert_authority($authority, 'after capture baseline publication proof');
        return [];
    }

    private static function assert_authority(
        TransactionAuthority $authority,
        string $context
    ): void {
        PromotionLease::assert_process_fence();
        if (!$authority->equals(Db::transaction_authority('capture code baseline ' . $context))) {
            throw new DatabaseTransactionOutcomeException(
                'capture code baseline changed database session authority ' . $context
            );
        }
    }
}
