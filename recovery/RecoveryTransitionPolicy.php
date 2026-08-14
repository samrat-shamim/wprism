<?php
declare(strict_types=1);

namespace Duo\Recovery;

/** Pure transition vocabulary shared by the controller and fault inventory. */
final class RecoveryTransitionPolicy {
    /** @var array<string,list<string>> */
    private const NEXT = [
        'prepared' => ['promoting', 'rollback_pending'],
        'promoting' => ['verifying_new', 'rollback_pending'],
        'verifying_new' => ['committed', 'rollback_pending'],
        'rollback_pending' => ['rolling_back'],
        'rolling_back' => ['verifying_prior'],
        'verifying_prior' => ['rolled_back'],
        'committed' => [],
        'rolled_back' => [],
    ];

    /** @var array<string,string> */
    private const SCOPED_OPERATIONS = [
        'prepared>promoting' => 'scoped_promotion_start',
        'promoting>verifying_new' => 'scoped_fresh_verification',
        'verifying_new>committed' => 'scoped_committed_verified',
        'prepared>rollback_pending' => 'scoped_promotion_failed',
        'promoting>rollback_pending' => 'scoped_promotion_failed',
        'rollback_pending>rolling_back' => 'scoped_rollback_start',
        'rolling_back>verifying_prior' => 'scoped_verifying_prior',
        'verifying_prior>rolled_back' => 'scoped_rolled_back_verified',
    ];

    public static function allows(string $from, string $to, string $profile = 'ordinary'): bool {
        if (!in_array($to, self::NEXT[$from] ?? [], true)) {
            return false;
        }
        if ($profile === 'scoped-checkpoint-v1'
            && !array_key_exists($from . '>' . $to, self::SCOPED_OPERATIONS)) {
            return false;
        }
        return $profile === 'ordinary' || $profile === 'scoped-checkpoint-v1';
    }

    public static function operation(string $from, string $to, string $profile = 'ordinary'): ?string {
        if (!self::allows($from, $to, $profile)) {
            return null;
        }
        return $profile === 'scoped-checkpoint-v1'
            ? self::SCOPED_OPERATIONS[$from . '>' . $to]
            : 'transition';
    }
}
