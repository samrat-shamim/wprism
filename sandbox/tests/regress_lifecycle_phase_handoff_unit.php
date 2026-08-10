<?php
/**
 * Offline contract for the retire -> fresh-process activate lifecycle witness.
 * The first phase may persist only a pending receipt. Apply can observe a
 * final before/after authorization only after activation proves it started
 * from retirement's exact post-hook canonical hash.
 */

namespace Duo;

function wp_json_encode(mixed $value): string|false {
    return json_encode($value, JSON_UNESCAPED_SLASHES);
}

final class Ledger {
    /** @var array<string,string> */
    public static array $rows = [];

    public static function kv_get(string $key): ?string {
        return self::$rows[$key] ?? null;
    }

    public static function kv_set(string $key, string $value): void {
        self::$rows[$key] = $value;
    }
}

final class FakePromotionWpdb {
    public string $prefix = 'wp_duo_';
    public string $dbname = 'duo_unit';
    /** @var list<mixed> */
    public array $preparedArgs = [];
    public bool $fenceHeld = false;

    public function prepare(string $sql, mixed ...$args): string {
        $this->preparedArgs = $args;
        return $sql;
    }

    public function get_var(string $sql): mixed {
        if (str_contains($sql, 'CONNECTION_ID()')) {
            return 17;
        }
        if (str_contains($sql, 'GET_LOCK(')) {
            $this->fenceHeld = true;
            return 1;
        }
        if (str_contains($sql, 'IS_USED_LOCK(')) {
            return $this->fenceHeld ? 17 : null;
        }
        if (str_contains($sql, 'RELEASE_LOCK(')) {
            $this->fenceHeld = false;
            return 1;
        }
        return 0;
    }
}

final class Db {
    public static function query(string $sql, string $label): int {
        global $wpdb;
        if ($label === 'promotion lock acquire') {
            Ledger::kv_set('promotion_lock', (string) ($wpdb->preparedArgs[1] ?? ''));
            return 1;
        }
        if ($label === 'promotion lock release') {
            unset(Ledger::$rows['promotion_lock']);
            return 1;
        }
        if ($label === 'promotion lock heartbeat') {
            Ledger::kv_set('promotion_lock', (string) ($wpdb->preparedArgs[0] ?? ''));
            return 1;
        }
        throw new \RuntimeException("unexpected unit Db::query operation: $label");
    }
}

$wpdb = new FakePromotionWpdb();

require_once dirname(__DIR__, 2) . '/agent/src/PromotionLock.php';

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new \RuntimeException('FAIL: ' . $message);
    }
};
$throws = static function (callable $operation, string $needle, string $message) use ($check): void {
    try {
        $operation();
    } catch (\RuntimeException $e) {
        $check(str_contains($e->getMessage(), $needle), $message . ': ' . $e->getMessage());
        return;
    }
    throw new \RuntimeException('FAIL: ' . $message . ': operation unexpectedly succeeded');
};

$owner = 'phase-handoff-owner';
$artifact = str_repeat('a', 64);
$before = str_repeat('b', 64);
$retired = str_repeat('c', 64);
$interfered = str_repeat('d', 64);
$activated = str_repeat('e', 64);

// A direct apply's lease is target-authoritative during planning, but a
// refusal before mutation must not replace the previous durable session. The
// session becomes truthful recovery evidence only after the locked provider
// and plan gates pass and begin_apply_session() is called explicitly.
$priorSession = json_encode([
    'owner' => 'previous-session-owner',
    'artifact_hash' => str_repeat('9', 64),
    'begun_at' => 99,
], JSON_THROW_ON_ERROR);
Ledger::$rows['promotion_session'] = $priorSession;
PromotionLock::acquire_apply_preflight('direct-preflight-owner', $artifact);
$check(
    Ledger::$rows['promotion_session'] === $priorSession,
    'direct apply preflight changed the previous promotion session'
);
PromotionLock::release('direct-preflight-owner', $artifact);
$check(
    Ledger::$rows['promotion_session'] === $priorSession,
    'refused direct apply cleanup changed the previous promotion session'
);

unset(Ledger::$rows['promotion_session']);
PromotionLock::acquire_apply_preflight('direct-preflight-owner', $artifact);
$check(
    !isset(Ledger::$rows['promotion_session']),
    'direct apply preflight without a prior session published recovery evidence'
);
PromotionLock::release('direct-preflight-owner', $artifact);

PromotionLock::acquire_apply_preflight('direct-preflight-owner', $artifact);
$expiredLease = json_decode(Ledger::$rows['promotion_lock'], true, 512, JSON_THROW_ON_ERROR);
$expiredLease['expires_at'] = time() - 1;
Ledger::$rows['promotion_lock'] = json_encode($expiredLease, JSON_THROW_ON_ERROR);
PromotionLock::begin_apply_session('direct-preflight-owner', $artifact);
$directSession = json_decode(Ledger::$rows['promotion_session'], true, 512, JSON_THROW_ON_ERROR);
$renewedLease = json_decode(Ledger::$rows['promotion_lock'], true, 512, JSON_THROW_ON_ERROR);
$check(
    ($directSession['owner'] ?? null) === 'direct-preflight-owner'
        && ($directSession['artifact_hash'] ?? null) === $artifact
        && is_int($directSession['begun_at'] ?? null)
        && preg_match('/^ps-[a-f0-9]{32}$/D', (string) ($directSession['session_id'] ?? '')) === 1,
    'successful direct apply preflight did not publish its exact random-generation session'
);
$check(
    ($renewedLease['phase'] ?? null) === 'apply-session-begin'
        && (int) ($renewedLease['expires_at'] ?? 0) > time(),
    'continuously fenced direct apply could not renew an expired preflight row before session publication'
);
$throws(
    static fn() => PromotionLock::begin_apply_session('direct-preflight-owner', $artifact),
    'already begun',
    'direct apply session could be published twice'
);
PromotionLock::release('direct-preflight-owner', $artifact);

Ledger::$rows = [];

Ledger::$rows['promotion_lock'] = json_encode([
    'owner' => $owner,
    'artifact_hash' => $artifact,
    'phase' => 'deploy-retire',
], JSON_THROW_ON_ERROR);
Ledger::$rows['promotion_session'] = json_encode([
    'owner' => $owner,
    'artifact_hash' => $artifact,
    'begun_at' => 123,
    'state_transition' => [
        'entity' => 'options/core',
        'before_hash' => str_repeat('1', 64),
        'after_hash' => str_repeat('2', 64),
    ],
], JSON_THROW_ON_ERROR);

$sessionBeforeMissingAttempt = Ledger::$rows['promotion_session'];
$throws(
    static fn() => PromotionLock::assert_lifecycle_phase_start($owner, $artifact, 'activate'),
    'out of order',
    'activation started before a successful retirement receipt'
);
$throws(
    static fn() => PromotionLock::assert_lifecycle_complete($owner, $artifact),
    'has not completed lifecycle retirement',
    'stage-only session was accepted as lifecycle-complete'
);
$throws(
    static fn() => PromotionLock::begin_state_transition(
        $owner,
        $artifact,
        'options/core',
        $before,
        $retired
    ),
    'no matching pre-hook attempt receipt',
    'a changed lifecycle transition published without its pre-hook boundary'
);
$check(
    Ledger::$rows['promotion_session'] === $sessionBeforeMissingAttempt,
    'missing-attempt refusal mutated the promotion session'
);

PromotionLock::begin_lifecycle_attempt($owner, $artifact, 'options/core', 'retire', $before);
$session = json_decode(Ledger::$rows['promotion_session'], true, 512, JSON_THROW_ON_ERROR);
$check(($session['lifecycle_attempt'] ?? null) === [
    'entity' => 'options/core',
    'phase' => 'retire',
    'before_hash' => $before,
], 'retirement did not publish its pre-hook ambiguity receipt');
$check(PromotionLock::incomplete_lifecycle() === [
    'owner' => $owner,
    'artifact_hash' => $artifact,
    'entity' => 'options/core',
    'phase' => 'retire',
    'before_hash' => $before,
], 'read-only lifecycle status did not expose the exact unresolved receipt');
$throws(
    static fn() => PromotionLock::complete_lifecycle_phase($owner, $artifact, 'retire'),
    'unresolved hook attempt',
    'failed retirement published a successful phase receipt'
);
PromotionLock::begin_state_transition($owner, $artifact, 'options/core', $before, $retired);
$session = json_decode(Ledger::$rows['promotion_session'], true, 512, JSON_THROW_ON_ERROR);
$check(!isset($session['lifecycle_attempt']), 'successful retirement retained its pre-hook ambiguity receipt');
$check(PromotionLock::incomplete_lifecycle() === null, 'successful retirement remained lifecycle-incomplete');
PromotionLock::complete_lifecycle_phase($owner, $artifact, 'retire');
PromotionLock::complete_lifecycle_phase($owner, $artifact, 'retire');
$throws(
    static fn() => PromotionLock::assert_lifecycle_complete($owner, $artifact),
    'has not completed lifecycle retirement',
    'retirement-only session was accepted as lifecycle-complete'
);
$check(!isset($session['state_transition']), 'retirement exposed a final apply authorization');
$check(($session['pending_state_transition'] ?? null) === [
    'entity' => 'options/core',
    'before_hash' => $before,
    'after_hash' => $retired,
], 'retirement did not persist its exact pending transition');
$check(
    PromotionLock::state_transition($owner, $artifact, 'options/core') === null,
    'Apply-facing state_transition became visible before activation'
);

// Model a process crash after retirement published H0->H1. A same-session
// retry must preserve that exact pending receipt rather than replacing it
// with the retry process's already-retired H1->H1 snapshot. Any different
// attempted replacement is a fail-closed conflict.
$pendingBeforeRetry = Ledger::$rows['promotion_session'];
PromotionLock::begin_state_transition($owner, $artifact, 'options/core', $before, $retired);
$check(
    Ledger::$rows['promotion_session'] === $pendingBeforeRetry,
    'an exact same-session retirement retry rewrote the pending receipt'
);
$throws(
    static fn() => PromotionLock::begin_state_transition(
        $owner,
        $artifact,
        'options/core',
        $retired,
        $activated
    ),
    'already has a different pending state transition',
    'a same-session retirement retry replaced the original pending receipt'
);
$check(
    Ledger::$rows['promotion_session'] === $pendingBeforeRetry,
    'a conflicting retirement retry corrupted the original pending receipt'
);

$throws(
    static fn() => PromotionLock::assert_pending_state_transition_start(
        $owner,
        $artifact,
        'options/core',
        $interfered
    ),
    'activation was not attempted',
    'an interphase state edit did not fail before activation hooks'
);
$check(
    PromotionLock::assert_pending_state_transition_start(
        $owner,
        $artifact,
        'options/core',
        $retired
    ),
    'the exact retirement post-hash did not authorize activation to start'
);
PromotionLock::assert_lifecycle_phase_start($owner, $artifact, 'activate');
PromotionLock::begin_lifecycle_attempt($owner, $artifact, 'options/core', 'activate', $retired);
$check((PromotionLock::incomplete_lifecycle()['phase'] ?? null) === 'activate', 'activation ambiguity was not plan-visible');
$throws(
    static fn() => PromotionLock::assert_no_lifecycle_attempt($owner, $artifact, 'apply'),
    'unresolved lifecycle attempt activate',
    'Apply crossed an unresolved activation hook window'
);
$throws(
    static fn() => PromotionLock::complete_state_transition(
        $owner,
        $artifact,
        'options/core',
        $interfered,
        $activated
    ),
    'changed between retirement and activation',
    'activation completed from a state other than retirement post-hash'
);

PromotionLock::complete_state_transition(
    $owner,
    $artifact,
    'options/core',
    $retired,
    $activated
);
$check(!PromotionLock::has_pending_state_transition($owner, $artifact, 'options/core'), 'completed handoff retained a pending witness');
$session = json_decode(Ledger::$rows['promotion_session'], true, 512, JSON_THROW_ON_ERROR);
$check(!isset($session['lifecycle_attempt']), 'successful activation retained its pre-hook ambiguity receipt');
$check(PromotionLock::incomplete_lifecycle() === null, 'successful activation remained lifecycle-incomplete');
PromotionLock::complete_lifecycle_phase($owner, $artifact, 'activate');
PromotionLock::complete_lifecycle_phase($owner, $artifact, 'activate');
PromotionLock::assert_lifecycle_complete($owner, $artifact);
$check(PromotionLock::state_transition($owner, $artifact, 'options/core') === [
    'entity' => 'options/core',
    'before_hash' => $before,
    'after_hash' => $activated,
], 'completed handoff did not span the original pre-retire to final post-activate hashes');
$throws(
    static fn() => PromotionLock::complete_state_transition(
        $owner,
        $artifact,
        'options/core',
        $activated,
        str_repeat('f', 64)
    ),
    'no pending retirement',
    'a completed pending witness was reusable'
);

// A lifecycle-only deploy has no Apply handoff, but it still must leave an
// ambiguity receipt across any failed hook. Only its successful post-hook
// boundary may clear that exact receipt.
unset($session['state_transition'], $session['pending_state_transition']);
Ledger::$rows['promotion_session'] = json_encode($session, JSON_THROW_ON_ERROR);
PromotionLock::begin_lifecycle_attempt($owner, $artifact, 'options/core', 'all', $activated);
$throws(
    static fn() => PromotionLock::begin_lifecycle_attempt(
        $owner,
        $artifact,
        'options/core',
        'all',
        $activated
    ),
    'must be recovered',
    'a second hook window replaced an unresolved lifecycle attempt'
);
PromotionLock::complete_lifecycle_attempt(
    $owner,
    $artifact,
    'options/core',
    'all',
    $activated,
    $activated
);
PromotionLock::assert_no_lifecycle_attempt($owner, $artifact, 'apply');

echo "ok: lifecycle attempts, state handoffs, and ordered positive phase receipts remain exact\n";
