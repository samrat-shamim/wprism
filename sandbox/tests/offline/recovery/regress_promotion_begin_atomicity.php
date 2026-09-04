<?php
/**
 * Offline regression for ordinary promotion-begin's external-debt election.
 *
 * The host's provider-settlement publisher and a new promotion contender use
 * the same target advisory fence. That serialization is useful only if the
 * contender checks external debt before any ledger mutation and publishes its
 * durable lease + promotion session in one transaction. Otherwise a process
 * death can leave a new owner's lease or session between those two facts and
 * strand the exact checkpoint recovery that already owns the target.
 *
 * FakeWpdb runs the real PromotionLease/Ledger/Db path. The first case refuses
 * in the external-authority callback and observes no CREATE/INSERT/transaction;
 * the second fails the session upsert after the lease upsert and proves the
 * transaction restores the prior session with no contender lease.
 */
declare(strict_types=1);

// From offline/recovery/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/TransientDbException.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/ProcessFence.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../../../agent/src/Promotion/PromotionSessionJournal.php';
require_once __DIR__ . '/../../../../agent/src/Promotion/LifecycleJournal.php';
require_once __DIR__ . '/../../../../agent/src/Promotion/StateTransitionJournal.php';
require_once __DIR__ . '/../../../../agent/src/Promotion/PromotionLease.php';

use WPrism\DatabaseMutationException;
use WPrism\ProcessFence;
use WPrism\PromotionLease;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

const BEGIN_ATOMICITY_PRIOR_OWNER = 'prior-promotion-owner';
const BEGIN_ATOMICITY_PRIOR_ARTIFACT = '1111111111111111111111111111111111111111111111111111111111111111';
const BEGIN_ATOMICITY_NEXT_OWNER = 'next-promotion-owner';
const BEGIN_ATOMICITY_NEXT_ARTIFACT = '2222222222222222222222222222222222222222222222222222222222222222';

/** @return array<string,mixed> */
function begin_atomicity_prior_session(): array {
    return [
        'owner' => BEGIN_ATOMICITY_PRIOR_OWNER,
        'artifact_hash' => BEGIN_ATOMICITY_PRIOR_ARTIFACT,
        'begun_at' => 1788400000,
        'session_id' => 'ps-' . str_repeat('12', 16),
    ];
}

/** Install the current InnoDB ledger schema with exactly one prior session. */
function begin_atomicity_database(): FakeWpdb {
    WpStore::reset();
    $wpdb = FakeWpdb::install()
        ->enableInformationSchema()
        ->enableFullApplySqlExtensions();
    $wpdb->seedTable('wp_wprism_map', [])
        ->setColumns('wp_wprism_map', [
            'uuid' => 'char(36)',
            'entity_type' => 'varchar(64)',
            'id_kind' => 'varchar(64)',
            'local_id' => 'bigint unsigned',
        ])
        ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])
        ->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id'])
        ->setTableEngine('wp_wprism_map', 'InnoDB');
    $wpdb->seedTable('wp_wprism_state', [])
        ->setColumns('wp_wprism_state', [
            'uuid' => 'varchar(64)',
            'entity_type' => 'varchar(64)',
            'content_hash' => 'char(64)',
        ])
        ->setUniqueKey('wp_wprism_state', ['uuid'])
        ->setTableEngine('wp_wprism_state', 'InnoDB');
    $wpdb->seedTable('wp_wprism_kv', [[
        'k' => 'promotion_session',
        'v' => json_encode(begin_atomicity_prior_session(), JSON_UNESCAPED_SLASHES),
    ]])
        ->setColumns('wp_wprism_kv', ['k' => 'varchar(191)', 'v' => 'longtext'])
        ->setUniqueKey('wp_wprism_kv', ['k'])
        ->setTableEngine('wp_wprism_kv', 'InnoDB');
    $wpdb->seedTable('wp_wprism_journal', [])
        ->setColumns('wp_wprism_journal', [
            'id' => 'bigint unsigned',
            't' => 'datetime',
            'op' => 'varchar(8)',
            'tbl' => 'varchar(64)',
            'item' => 'varchar(191)',
            'surface' => 'varchar(32)',
            'actor' => 'bigint unsigned',
            'caps' => 'varchar(64)',
            'hook' => 'varchar(191)',
            'proposal' => 'varchar(16)',
        ])
        ->setTableEngine('wp_wprism_journal', 'InnoDB');
    $wpdb->setLockResult(1);

    return $wpdb;
}

/** @return array<string,mixed>|null */
function begin_atomicity_row(FakeWpdb $wpdb, string $key): ?array {
    foreach ($wpdb->rows('wp_wprism_kv') as $row) {
        if (($row['k'] ?? null) !== $key) {
            continue;
        }
        $decoded = json_decode((string) ($row['v'] ?? ''), true);
        return is_array($decoded) ? $decoded : null;
    }
    return null;
}

// External provider/recovery debt is the election gate. Advisory-lock SELECTs
// may precede it; schema creation, transaction control, and durable writes may
// not. A rejected contender therefore leaves the prior recovery generation
// byte-identical and cannot need compensating cleanup.
$debtDb = begin_atomicity_database();
$debtChecked = false;
$debtFailure = null;
try {
    PromotionLease::begin_with_external_fence(
        BEGIN_ATOMICITY_NEXT_OWNER,
        BEGIN_ATOMICITY_NEXT_ARTIFACT,
        static function () use ($debtDb, &$debtChecked): void {
            ProcessFence::assertHeld();
            $debtChecked = true;
            foreach ($debtDb->queries() as $sql) {
                $head = strtoupper(strtok(ltrim($sql), " \t\r\n") ?: '');
                if (in_array($head, ['CREATE', 'ALTER', 'INSERT', 'UPDATE', 'DELETE', 'START', 'BEGIN'], true)) {
                    throw new \LogicException('durable mutation preceded external debt classification');
                }
            }
            throw new \RuntimeException('fixture: unresolved provider settlement debt');
        }
    );
} catch (\Throwable $failure) {
    $debtFailure = $failure;
}
wprism_check($debtChecked, 'promotion-begin checks external debt while holding the target process fence');
wprism_check(
    $debtFailure instanceof \RuntimeException
        && $debtFailure->getMessage() === 'fixture: unresolved provider settlement debt',
    'external debt refusal propagates before ledger creation or lease election'
);
wprism_check_same(
    begin_atomicity_prior_session(),
    begin_atomicity_row($debtDb, 'promotion_session'),
    'external debt refusal preserves the prior durable promotion session byte-for-byte'
);
wprism_check_same(null, begin_atomicity_row($debtDb, 'promotion_lock'), 'external debt refusal publishes no contender lease');

// The lease upsert succeeds, then the second durable write fails. Both rows
// must roll back as one InnoDB handoff; retaining either B row would let a
// crashed/stale contender supersede A despite A's external settlement debt.
$writeDb = begin_atomicity_database();
$writeDb->failNextQuery(
    'fixture: promotion session publication failed',
    "SELECT 'promotion_session',"
);
$rollbackContender = (new FakeWpdb())
    ->setConnectionId(2)
    ->shareAdvisoryLocksWith($writeDb);
$rollbackFenceBlocked = false;
$writeDb->onQuery(static function (string $sql) use ($rollbackContender, &$rollbackFenceBlocked): null {
    if (strtoupper(trim($sql)) !== 'ROLLBACK AND NO CHAIN NO RELEASE') {
        return null;
    }
    $fence = ProcessFence::name();
    $rollbackFenceBlocked = $rollbackContender->get_var(
        $rollbackContender->prepare('SELECT GET_LOCK(%s, 0)', $fence)
    ) === '0';
    return null;
});
$writeFailure = null;
try {
    PromotionLease::begin_with_external_fence(
        BEGIN_ATOMICITY_NEXT_OWNER,
        BEGIN_ATOMICITY_NEXT_ARTIFACT,
        static function (): void {
            ProcessFence::assertHeld();
        }
    );
} catch (\Throwable $failure) {
    $writeFailure = $failure;
}
wprism_check(
    $writeFailure instanceof DatabaseMutationException,
    'a failed session publication reaches the caller as a typed database mutation failure'
);
wprism_check_same(
    begin_atomicity_prior_session(),
    begin_atomicity_row($writeDb, 'promotion_session'),
    'failed session publication rolls back to the exact prior recovery generation'
);
wprism_check_same(null, begin_atomicity_row($writeDb, 'promotion_lock'), 'failed session publication rolls back the contender lease too');
$writeQueries = $writeDb->queries();
$start = array_search('START TRANSACTION', $writeQueries, true);
$rollback = array_search('ROLLBACK AND NO CHAIN NO RELEASE', $writeQueries, true);
$release = null;
foreach ($writeQueries as $index => $sql) {
    if (str_starts_with($sql, 'SELECT RELEASE_LOCK(')) {
        $release = $index;
        break;
    }
}
wprism_check(
    is_int($start) && is_int($rollback) && is_int($release) && $start < $rollback && $rollback < $release,
    'lease and session publication roll back before the caller-owned target fence is released'
);
wprism_check(
    $rollbackFenceBlocked,
    'a second database connection cannot enter the target fence before rollback is classified'
);
wprism_check(
    count(array_filter(
        $writeQueries,
        static fn(string $sql): bool => str_contains($sql, "SELECT 'promotion_lock',")
    )) === 1,
    'the failure is injected after the real contender lease upsert executes'
);

wprism_check_summary('promotion begin external-fence atomicity');
