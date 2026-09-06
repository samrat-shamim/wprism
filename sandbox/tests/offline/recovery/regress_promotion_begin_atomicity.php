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
 * Scoped replacement consumes the same core storage proof; its old second
 * metadata census crossed the already-bound query profile on a real target.
 */
declare(strict_types=1);

// From offline/recovery/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';

$runtimeRoot = $argv[1] ?? dirname(__DIR__, 4);
require_once $runtimeRoot . '/agent/src/Kernel/CommandRefusal.php';
require_once $runtimeRoot . '/agent/src/Kernel/TransientDbException.php';
require_once $runtimeRoot . '/agent/src/Kernel/Db.php';
require_once $runtimeRoot . '/agent/src/Kernel/ProcessFence.php';
require_once $runtimeRoot . '/agent/src/Repository/Ledger.php';
require_once $runtimeRoot . '/agent/src/Promotion/PromotionSessionJournal.php';
require_once $runtimeRoot . '/agent/src/Promotion/LifecycleJournal.php';
require_once $runtimeRoot . '/agent/src/Promotion/StateTransitionJournal.php';
require_once $runtimeRoot . '/agent/src/Promotion/PromotionLease.php';

use WPrism\DatabaseMutationException;
use WPrism\DatabaseQueryIsolation;
use WPrism\Db;
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
function begin_atomicity_database(?string $kvEngine = 'InnoDB'): FakeWpdb {
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
        ->setUniqueKey('wp_wprism_kv', ['k']);
    if ($kvEngine !== null) {
        $wpdb->setTableEngine('wp_wprism_kv', $kvEngine);
    }
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

/** @return array<string,mixed> */
function begin_atomicity_scoped_witness(): array {
    return [
        'active' => true,
        'allow_deletes' => false,
        'artifact_hash' => BEGIN_ATOMICITY_NEXT_ARTIFACT,
        'exclusion_state' => 'held',
        'format' => 'wprism-scoped-promotion-witness/v1',
        'generation' => 1,
        'ok' => true,
        'owner' => BEGIN_ATOMICITY_NEXT_OWNER,
        'receipt_format' => 'wprism-scoped-promotion-receipt/v1',
        'receipt_id' => str_repeat('3', 32),
        'receipt_payload_sha256' => str_repeat('4', 64),
        'recovery_ready' => true,
        'scope_hash' => str_repeat('5', 64),
        'signing_key_id' => 'offline-scoped-key',
        'state' => 'promoting',
        'target_id' => str_repeat('6', 32),
        'terminal' => false,
    ];
}

/** @return array<string,mixed> */
function begin_atomicity_scoped(): array {
    $witness = begin_atomicity_scoped_witness();
    return PromotionLease::begin_scoped(
        BEGIN_ATOMICITY_NEXT_OWNER,
        BEGIN_ATOMICITY_NEXT_ARTIFACT,
        $witness['receipt_payload_sha256'],
        $witness['scope_hash'],
        $witness
    );
}

// This fixture starts at the durable post-abort state: one ordinary session,
// no lease. Unlike the scoped semantic suite, all SQL crosses real Db's
// installed gate and the shared interpreter; a second raw metadata census
// after profile binding therefore reproduces the exact live refusal.
$scopedDb = begin_atomicity_database();
$scopedContender = (new FakeWpdb())
    ->setConnectionId(2)
    ->shareAdvisoryLocksWith($scopedDb);
$profiledHandoffReads = [];
$metadataAfterBinding = 0;
$publicationChecked = false;
$publicationWriteAuthority = false;
$publicationFenceHeld = false;
$scopedDb->onQuery(static function (string $sql) use (
    $scopedContender,
    &$profiledHandoffReads,
    &$metadataAfterBinding,
    &$publicationChecked,
    &$publicationWriteAuthority,
    &$publicationFenceHeld
): null {
    if (DatabaseQueryIsolation::has_bound_profile()) {
        if (str_contains($sql, 'information_schema.')) {
            ++$metadataAfterBinding;
        }
        foreach (['promotion_lock', 'promotion_session'] as $key) {
            if (str_contains($sql, "SELECT k, v FROM wp_wprism_kv WHERE k = '$key'")) {
                $profiledHandoffReads[$key] = true;
            }
        }
    }
    if (!$publicationChecked && str_starts_with($sql, 'INSERT INTO `wp_wprism_kv`')) {
        $publicationChecked = true;
        DatabaseQueryIsolation::assert_profile_contains(
            ['wp_wprism_kv'],
            true,
            'scoped replacement publication authority control'
        );
        $publicationWriteAuthority = true;
        $publicationFenceHeld = $scopedContender->get_var(
            $scopedContender->prepare('SELECT GET_LOCK(%s, 0)', ProcessFence::name())
        ) === '0';
    }
    return null;
});
$scopedResult = null;
$scopedFailure = null;
try {
    $scopedResult = begin_atomicity_scoped();
} catch (Throwable $failure) {
    $scopedFailure = $failure;
}
wprism_check(
    $scopedFailure === null,
    'scoped replacement succeeds through real Db/Ledger and its installed query gate'
        . ($scopedFailure === null ? '' : ' (' . get_class($scopedFailure) . ': ' . $scopedFailure->getMessage() . ')')
);
wprism_check(
    isset($profiledHandoffReads['promotion_lock'], $profiledHandoffReads['promotion_session']),
    'both handoff rows are re-read through the bound native profile before scoped publication'
);
wprism_check(
    $publicationChecked && $publicationWriteAuthority && $publicationFenceHeld,
    'scoped publication retains exact core-proven write authority under the original process fence'
);
wprism_check_same(0, $metadataAfterBinding, 'no feature-local metadata census escapes the bound scoped profile');
$scopedQueries = $scopedDb->queries();
$scopedFenceAt = null;
$scopedStartAt = array_search('START TRANSACTION', $scopedQueries, true);
$scopedMetadataAt = array_search('SELECT 1 FROM `wp_wprism_kv` LIMIT 0', $scopedQueries, true);
foreach ($scopedQueries as $index => $sql) {
    if (str_contains($sql, 'GET_LOCK(')) {
        $scopedFenceAt = $index;
        break;
    }
}
wprism_check(
    is_int($scopedFenceAt) && is_int($scopedStartAt) && is_int($scopedMetadataAt)
        && $scopedFenceAt < $scopedStartAt && $scopedStartAt < $scopedMetadataAt,
    'the process fence still precedes START and the core transactional metadata proof'
);
$scopedSession = begin_atomicity_row($scopedDb, 'promotion_session');
$scopedLease = begin_atomicity_row($scopedDb, 'promotion_lock');
wprism_check(
    is_array($scopedResult) && is_array($scopedSession) && is_array($scopedLease)
        && ($scopedSession['profile'] ?? null) === 'scoped-checkpoint-v1'
        && ($scopedSession['owner'] ?? null) === BEGIN_ATOMICITY_NEXT_OWNER
        && ($scopedSession['artifact_hash'] ?? null) === BEGIN_ATOMICITY_NEXT_ARTIFACT
        && ($scopedSession['session_id'] ?? null) === ($scopedResult['session_id'] ?? null)
        && ($scopedSession['session_id'] ?? null) !== begin_atomicity_prior_session()['session_id']
        && ($scopedSession['scoped_receipt_sha256'] ?? null) === str_repeat('4', 64)
        && ($scopedSession['scoped_scope_hash'] ?? null) === str_repeat('5', 64)
        && ($scopedLease['owner'] ?? null) === BEGIN_ATOMICITY_NEXT_OWNER,
    'one fresh scoped session and matching lease replace the completed ordinary session atomically'
);
wprism_check(
    count(array_filter($scopedQueries, static fn(string $sql): bool => $sql === 'COMMIT AND NO CHAIN NO RELEASE')) === 1
        && !in_array('ROLLBACK AND NO CHAIN NO RELEASE', $scopedQueries, true)
        && $scopedDb->activeTransactionIsolation() === null
        && !DatabaseQueryIsolation::is_active(),
    'successful scoped replacement commits once and settles its transaction and query gate'
);
if ($scopedFailure === null) {
    $retryBefore = $scopedDb->rows('wp_wprism_kv');
    $scopedDb->resetLog();
    $retry = begin_atomicity_scoped();
    wprism_check_same($scopedResult['session_id'], $retry['session_id'], 'exact scoped retry does not rotate the target generation');
    wprism_check_same($scopedSession, begin_atomicity_row($scopedDb, 'promotion_session'), 'exact scoped retry preserves every receipt-bound session field');
    wprism_check(
        count($retryBefore) === 2 && count($scopedDb->rows('wp_wprism_kv')) === 2
            && array_filter($scopedDb->queries(), static fn(string $sql): bool => str_contains($sql, "SELECT 'promotion_session',")) === [],
        'exact scoped retry renews only the existing lease and publishes no replacement session'
    );
}
ProcessFence::release();

foreach (['failed engine read', 'unknown engine', 'nontransactional engine', 'failed session publication'] as $case) {
    $db = begin_atomicity_database($case === 'unknown engine' ? null : 'InnoDB');
    $before = $db->rows('wp_wprism_kv');
    if ($case === 'failed engine read') {
        $db->failNextQuery('fixture: scoped storage read failed', 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES');
    } elseif ($case === 'nontransactional engine') {
        $db->setTableEngine('wp_wprism_kv', 'MyISAM');
    } elseif ($case === 'failed session publication') {
        $db->failNextQuery('fixture: scoped session publication failed', "SELECT 'promotion_session',");
    }
    $contender = (new FakeWpdb())->setConnectionId(2)->shareAdvisoryLocksWith($db);
    $rollbackFenceHeld = false;
    $db->onQuery(static function (string $sql) use ($contender, &$rollbackFenceHeld): null {
        if ($sql === 'ROLLBACK AND NO CHAIN NO RELEASE') {
            $rollbackFenceHeld = $contender->get_var(
                $contender->prepare('SELECT GET_LOCK(%s, 0)', ProcessFence::name())
            ) === '0';
        }
        return null;
    });
    $caught = null;
    try {
        begin_atomicity_scoped();
    } catch (Throwable $failure) {
        $caught = $failure;
    }
    $expected = match ($case) {
        'failed engine read' => 'fixture: scoped storage read failed',
        'unknown engine' => 'unknown engine: wp_wprism_kv (engine: NULL/unknown)',
        'nontransactional engine' => 'unsupported engine (InnoDB required): wp_wprism_kv (engine: MYISAM)',
        default => 'fixture: scoped session publication failed',
    };
    $expectedFailure = match ($case) {
        'failed engine read' => $caught instanceof mysqli_sql_exception
            && $caught->getMessage() === $expected,
        'failed session publication' => $caught instanceof DatabaseMutationException
            && $caught->getPrevious() instanceof mysqli_sql_exception
            && $caught->getPrevious()->getMessage() === $expected,
        default => $caught instanceof RuntimeException
            && str_contains($caught->getMessage(), $expected),
    };
    wprism_check(
        $expectedFailure,
        "$case refuses at its actual storage/publication boundary"
    );
    wprism_check_same($before, $db->rows('wp_wprism_kv'), "$case preserves prior session bytes with no contender lease");
    $queries = $db->queries();
    $leaseWrites = count(array_filter($queries, static fn(string $sql): bool => str_contains($sql, "SELECT 'promotion_lock',")));
    wprism_check_same(
        $case === 'failed session publication' ? 1 : 0,
        $leaseWrites,
        "$case reaches exactly its intended pre-write or provisional-write boundary"
    );
    wprism_check(
        $rollbackFenceHeld
            && count(array_filter($queries, static fn(string $sql): bool => $sql === 'ROLLBACK AND NO CHAIN NO RELEASE')) === 1
            && !in_array('COMMIT AND NO CHAIN NO RELEASE', $queries, true)
            && $db->activeTransactionIsolation() === null
            && !DatabaseQueryIsolation::is_active()
            && !ProcessFence::isContinuous(),
        "$case rolls back under the original fence and releases transaction, query and process authority"
    );
    $db->onQuery(null);
    Db::start('scoped refusal settled retry', new \WPrism\NativeDatabaseProfile([]));
    Db::rollback('scoped refusal settled retry rollback');
    wprism_check(true, "$case leaves the physical session usable for an independent transaction");
}

wprism_check_summary('promotion begin external-fence and scoped atomicity');
