<?php
/** Row-backed characterization of shared UUID locks, not a database concurrency simulation. */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
$runtimeRoot = realpath($argv[1] ?? dirname(__DIR__, 4));
$ownerFile = is_string($runtimeRoot) ? $runtimeRoot . '/agent/src/Repository/LockedEmbeddedUuidOwners.php' : '';
wprism_check(is_file($ownerFile), 'shared locked ownership is a real runtime entry point');
if (!is_file($ownerFile)) {
    wprism_check_summary('locked embedded UUID owners');
}
require_once $ownerFile;
require_once $runtimeRoot . '/agent/src/Apply/ProtectedPostIdentity.php';

use WPrism\DatabaseTransactionOutcomeException;
use WPrism\Db;
use WPrism\DeleteGuardEvaluator;
use WPrism\LockedEmbeddedUuidOwners;
use WPrism\NativeDatabaseProfile;
use WPrism\ProtectedPostIdentity;
use WPrismTest\FakeWpdb;

const LOCKED_OLD_UUID = '019200cc-0000-7000-8000-0000000000c7';
const LOCKED_FRESH_UUID = '019200cc-0000-7000-8000-0000000000c8';
const LOCKED_TABLES = ['wp_posts', 'wp_terms', 'wp_postmeta', 'wp_termmeta', 'wp_wprism_map'];
const LOCKED_MISMATCH = 'wprism: protected post binding identity does not match its unique exact live backing row';

function locked_index(string $name, string $column, bool $unique, ?int $prefix = null, int $position = 1): array {
    return ['Key_name' => $name, 'Non_unique' => $unique ? 0 : 1, 'Seq_in_index' => $position,
        'Column_name' => $column, 'Sub_part' => $prefix, 'Index_type' => 'BTREE'];
}

function locked_fixture(): FakeWpdb {
    Db::forget_transaction_tracking();
    DeleteGuardEvaluator::end_authored_transaction();
    $wpdb = FakeWpdb::install()->enableInformationSchema();
    foreach (LOCKED_TABLES as $table) {
        $wpdb->seedTable($table, [])->setTableEngine($table, 'InnoDB');
    }
    return $wpdb
        ->setColumns('wp_posts', ['ID' => 'bigint', 'post_type' => 'varchar(20)', 'post_password' => 'varchar(255)'])
        ->setIndexes('wp_posts', [locked_index('PRIMARY', 'ID', true)])
        ->setColumns('wp_terms', ['term_id' => 'bigint'])
        ->setIndexes('wp_terms', [locked_index('PRIMARY', 'term_id', true)])
        ->setColumns('wp_postmeta', ['meta_id' => 'bigint', 'post_id' => 'bigint', 'meta_key' => 'varchar(255)', 'meta_value' => 'longtext'])
        ->setIndexes('wp_postmeta', [locked_index('meta_key', 'meta_key', false, 191)])
        ->setColumns('wp_termmeta', ['meta_id' => 'bigint', 'term_id' => 'bigint', 'meta_key' => 'varchar(255)', 'meta_value' => 'longtext'])
        ->setIndexes('wp_termmeta', [locked_index('meta_key', 'meta_key', false, 191)])
        ->setColumns('wp_wprism_map', ['uuid' => 'char(36)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint', 'entity_type' => 'varchar(64)'])
        ->setIndexes('wp_wprism_map', [locked_index('PRIMARY', 'uuid', true), locked_index('PRIMARY', 'id_kind', true, null, 2)])
        ->seedTable('wp_posts', [['ID' => 41, 'post_type' => 'post', 'post_password' => 'secret']])
        ->seedTable('wp_postmeta', [locked_meta(1, 41)])
        ->seedTable('wp_wprism_map', [['uuid' => LOCKED_OLD_UUID, 'id_kind' => 'post', 'local_id' => 41, 'entity_type' => 'post']]);
}

function locked_meta(int $metaId, int $ownerId, ?string $uuid = LOCKED_OLD_UUID, string $kind = 'post', string $key = '_wprism_uuid'): array {
    return ['meta_id' => $metaId, $kind . '_id' => $ownerId, 'meta_key' => $key, 'meta_value' => $uuid];
}

function locked_begin(): LockedEmbeddedUuidOwners {
    Db::start_repeatable_read('shared identity fixture', NativeDatabaseProfile::read_only(LOCKED_TABLES));
    return LockedEmbeddedUuidOwners::prepare(Db::repeatable_read_authority('fixture preparation'), 'protected post identity');
}

function locked_failure(callable $operation): ?Throwable {
    try {
        $operation();
    } catch (Throwable $failure) {
        return $failure;
    }
    return null;
}

function locked_rows(FakeWpdb $wpdb): array {
    return array_combine(LOCKED_TABLES, array_map($wpdb->rows(...), LOCKED_TABLES));
}

/** Control/savepoint SQL is expected; the primitive has no product mutation authority. */
function locked_data_writes(FakeWpdb $wpdb): array {
    return array_values(array_filter($wpdb->queries(), static fn(string $sql): bool =>
        preg_match('/^(?:INSERT|REPLACE|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $sql) === 1));
}

function locked_selects(FakeWpdb $wpdb): array {
    return array_values(array_filter($wpdb->queries(), static fn(string $sql): bool => str_ends_with($sql, 'FOR UPDATE')));
}

$wpdb = locked_fixture();
$before = locked_rows($wpdb);
$owners = locked_begin();
wprism_check_same('PRIMARY', $owners->post_index(), 'prepared post index permits the caller to lock its original row first');
$wpdb->resetLog();
$inventory = $owners->lock([LOCKED_OLD_UUID, LOCKED_FRESH_UUID]);
wprism_check_same([['meta_id' => '1', 'owner_id' => '41', 'meta_key' => '_wprism_uuid',
    'meta_value_prefix' => LOCKED_OLD_UUID, 'meta_value_bytes' => '36']], $inventory['post_rows'],
    'complete reserved-key rows retain exact text-protocol shapes');
wprism_check_same([], $inventory['term_rows'], 'empty term range is still observed');
wprism_check_same([41 => true], $inventory['live_post_owners'], 'old UUID has its exact live post');
wprism_check_same([], $inventory['live_term_owners'], 'fresh UUID and empty term range invent no owners');
$locks = locked_selects($wpdb);
wprism_check_same([
    "SELECT meta_id, `post_id` AS owner_id, meta_key, LEFT(meta_value, 37) AS meta_value_prefix, OCTET_LENGTH(meta_value) AS meta_value_bytes FROM `wp_postmeta` FORCE INDEX (`meta_key`) WHERE meta_key = '_wprism_uuid' ORDER BY meta_id ASC LIMIT 100001 FOR UPDATE",
    "SELECT meta_id, `term_id` AS owner_id, meta_key, LEFT(meta_value, 37) AS meta_value_prefix, OCTET_LENGTH(meta_value) AS meta_value_bytes FROM `wp_termmeta` FORCE INDEX (`meta_key`) WHERE meta_key = '_wprism_uuid' ORDER BY meta_id ASC LIMIT 100001 FOR UPDATE",
    'SELECT `ID` AS owner_id FROM `wp_posts` FORCE INDEX (`PRIMARY`) WHERE `ID` IN (41) ORDER BY `ID` ASC LIMIT 2 FOR UPDATE',
], $locks, 'indexed postmeta then termmeta ranges precede sorted candidate owner locks');
wprism_check_same($before, locked_rows($wpdb), 'successful observation preserves every fixture row');
wprism_check_same([], locked_data_writes($wpdb), 'shared observation emits no DML or DDL');
Db::rollback('shared identity fixture rollback');
wprism_check(locked_failure(static fn() => $owners->lock([LOCKED_OLD_UUID])) instanceof DatabaseTransactionOutcomeException,
    'prepared locks cannot be reused after settlement');

$wpdb = locked_fixture()->seedTable('wp_posts', [
    ['ID' => 43, 'post_type' => 'post', 'post_password' => ''],
    ['ID' => 41, 'post_type' => 'post', 'post_password' => 'secret'],
])->seedTable('wp_terms', [['term_id' => 9]])->seedTable('wp_postmeta', [
    locked_meta(9, 900), locked_meta(7, 43), locked_meta(1, 41), locked_meta(3, 43),
    locked_meta(10, 77, LOCKED_OLD_UUID, 'post', '_WPRISM_UUID'),
    locked_meta(11, 78, strtoupper(LOCKED_OLD_UUID)), locked_meta(12, 79, LOCKED_OLD_UUID . 'x'),
    locked_meta(13, 80, 'not-a-uuid'), locked_meta(14, 81, null),
    locked_meta(15, 82, 'ordinary value', 'post', 'other_key'),
])->seedTable('wp_termmeta', [locked_meta(2, 9, LOCKED_FRESH_UUID, 'term'), locked_meta(4, 901, LOCKED_OLD_UUID, 'term')]);
$owners = locked_begin();
$wpdb->resetLog();
$inventory = $owners->lock([LOCKED_OLD_UUID, LOCKED_FRESH_UUID]);
wprism_check_same([41 => true, 43 => true], $inventory['live_post_owners'], 'duplicate live rows remain facts, not a shared one-owner refusal');
wprism_check_same([9 => true], $inventory['live_term_owners'], 'the second requested UUID is also classified across kinds');
wprism_check_same(['1', '3', '7', '9', '10', '11', '12', '13', '14'], array_column($inventory['post_rows'], 'meta_id'),
    'collation aliases and unrelated malformed or null UUIDs remain visible without new semantics');
wprism_check_same('_WPRISM_UUID', $inventory['post_rows'][4]['meta_key'], 'collation-equal key retains its original bytes');
$locks = locked_selects($wpdb);
wprism_check(str_contains($locks[2], 'IN (41,43,900)'), 'post candidates are deduplicated and sorted including the orphan absence gap');
wprism_check(str_contains($locks[3], 'IN (9,901)'), 'term candidates include their orphan absence gap after post locks');
wprism_check_same([], locked_data_writes($wpdb), 'multiple owners and unrelated malformed values do not trigger repair');
Db::rollback('multi-owner observation rollback');

$wpdb = locked_fixture();
$owners = locked_begin();
foreach ([[], ['named' => LOCKED_OLD_UUID], [LOCKED_OLD_UUID, LOCKED_OLD_UUID], [42], ['invalid'],
    [strtoupper(LOCKED_OLD_UUID)], [LOCKED_OLD_UUID . "\n"], array_fill(0, 17, LOCKED_OLD_UUID)] as $invalid) {
    $wpdb->resetLog();
    wprism_check(locked_failure(static fn() => $owners->lock($invalid)) instanceof InvalidArgumentException,
        'invalid or unbounded requested UUID set refuses');
    wprism_check_same([], locked_selects($wpdb), 'invalid UUID request takes no semantic row locks');
}
Db::rollback('invalid requests rollback');

foreach (['wp_posts', 'wp_terms', 'wp_postmeta', 'wp_termmeta'] as $table) {
    $wpdb = locked_fixture()->setTableEngine($table, 'MyISAM');
    $failure = locked_failure(locked_begin(...));
    wprism_check($failure instanceof RuntimeException && str_contains($failure->getMessage(), 'InnoDB'),
        "$table must pass physical admission before shared lock preparation");
    wprism_check_same([], locked_selects($wpdb), "$table nontransactional refusal takes no semantic row locks");
}
$wpdb = locked_fixture();
Db::start_repeatable_read('incomplete identity profile', NativeDatabaseProfile::read_only(['wp_posts']));
$failure = locked_failure(static fn() => LockedEmbeddedUuidOwners::prepare(Db::repeatable_read_authority('scope'), 'protected post identity'));
wprism_check($failure instanceof RuntimeException, 'prepared identity requires all four tables in the admitted read profile');
wprism_check_same([], locked_selects($wpdb), 'incomplete profile cannot become a partial lock witness');
Db::rollback('incomplete profile rollback');

foreach ([
    ['wp_posts', [locked_index('ID', 'ID', false)]],
    ['wp_terms', [locked_index('term_id', 'term_id', true, 1)]],
    ['wp_postmeta', [locked_index('meta_key', 'meta_key', false, 11)]],
    ['wp_termmeta', []],
] as [$table, $indexes]) {
    $wpdb = locked_fixture()->setIndexes($table, $indexes);
    $failure = locked_failure(locked_begin(...));
    wprism_check($failure instanceof RuntimeException, "$table inadequate index refuses lock preparation");
    wprism_check_same([], locked_selects($wpdb), "$table inadequate index cannot grant semantic locks");
    Db::rollback('rejected index rollback');
}

$wpdb = locked_fixture();
Db::start('plain identity transaction', NativeDatabaseProfile::read_only(LOCKED_TABLES));
$plainAuthority = Db::transaction_authority('plain witness');
wprism_check(locked_failure(static fn() => LockedEmbeddedUuidOwners::prepare($plainAuthority, 'protected post identity')) instanceof DatabaseTransactionOutcomeException,
    'ordinary session continuity cannot stand in for controlled repeatable-read');
Db::rollback('plain identity rollback');
$wpdb = locked_fixture();
$owners = locked_begin();
$oldAuthority = Db::repeatable_read_authority('old generation');
Db::rollback('old generation rollback');
Db::start_repeatable_read('replacement controlled transaction', NativeDatabaseProfile::read_only(LOCKED_TABLES));
wprism_check(locked_failure(static fn() => $owners->post_index()) instanceof DatabaseTransactionOutcomeException,
    'even a new controlled transaction on the same connection cannot reuse preparation');
wprism_check(locked_failure(static fn() => LockedEmbeddedUuidOwners::prepare($oldAuthority, 'protected post identity')) instanceof DatabaseTransactionOutcomeException,
    'preparation refuses an old transaction authority');
Db::rollback('replacement controlled rollback');

foreach (['postmeta', 'termmeta', 'post-owner', 'term-owner'] as $stage) {
    $wpdb = locked_fixture()->seedTable('wp_terms', [['term_id' => 9]])
        ->seedTable('wp_termmeta', [locked_meta(2, 9, LOCKED_OLD_UUID, 'term')]);
    $owners = locked_begin();
    $matching = match ($stage) {
        'postmeta' => 'FROM `wp_postmeta` FORCE INDEX', 'termmeta' => 'FROM `wp_termmeta` FORCE INDEX',
        'post-owner' => 'SELECT `ID` AS owner_id', 'term-owner' => 'SELECT `term_id` AS owner_id',
    };
    $wpdb->failNextQuery('deliberate read failure', $matching);
    $failure = locked_failure(static fn() => $owners->lock([LOCKED_OLD_UUID]));
    wprism_check_same(mysqli_sql_exception::class, $failure === null ? null : $failure::class,
        "$stage driver error is loud under the bound strict transport");
    wprism_check_same('deliberate read failure', $failure?->getMessage(), "$stage preserves the exact driver cause");
    wprism_check_same([], locked_data_writes($wpdb), "$stage read failure never repairs product rows");
    Db::rollback("$stage failed read rollback");
    foreach (['new-id', 'same-id', 'implicit-commit'] as $loss) {
        $wpdb = locked_fixture()->seedTable('wp_terms', [['term_id' => 9]])
            ->seedTable('wp_termmeta', [locked_meta(2, 9, LOCKED_OLD_UUID, 'term')]);
        $owners = locked_begin();
        $authority = Db::repeatable_read_authority('loss fixture');
        $injected = false;
        $wpdb->onQuery(static function (string $sql) use ($wpdb, $matching, $loss, $authority, &$injected): ?string {
            if (!$injected && str_contains($sql, $matching)) {
                $injected = true;
                if ($loss === 'implicit-commit') {
                    $wpdb->simulateImplicitCommit();
                } else {
                    $wpdb->setConnectionId((int) $authority->connection_id() + ($loss === 'new-id' ? 1 : 0));
                }
            }
            return null;
        });
        $failure = locked_failure(static fn() => $owners->lock([LOCKED_OLD_UUID]));
        wprism_check($injected && $failure instanceof DatabaseTransactionOutcomeException,
            "$stage $loss refuses rather than returning a reusable observation");
        wprism_check_same([], locked_data_writes($wpdb), "$stage $loss performs no product mutation");
        $wpdb->onQuery(null);
        Db::connection_transaction_active('lost identity transaction idle proof');
        Db::forget_transaction_tracking();
    }
}

$wpdb = locked_fixture();
$owners = locked_begin();
$wpdb->posts = 'wp_other_posts';
$failure = locked_failure(static fn() => $owners->lock([LOCKED_OLD_UUID]));
wprism_check($failure instanceof DatabaseTransactionOutcomeException && str_contains($failure->getMessage(), 'bindings'),
    'prepared physical table bindings cannot drift before a later lock');
$wpdb->posts = 'wp_posts';
Db::rollback('binding drift rollback');

// The real supported transport throws; retain defensive checks for malformed
// wpdb-compatible results separately, without calling them native evidence.
$validRow = ['meta_id' => '1', 'owner_id' => '41', 'meta_key' => '_wprism_uuid',
    'meta_value_prefix' => LOCKED_OLD_UUID, 'meta_value_bytes' => '36'];
foreach ([
    'null-result' => null, 'false-result' => false, 'not-list' => ['row' => $validRow],
    'scalar-row' => ['not a row'], 'missing-column' => [array_diff_key($validRow, ['owner_id' => true])],
    'extra-column' => [$validRow + ['extra' => 'x']],
    'zero-meta' => [array_replace($validRow, ['meta_id' => '0'])],
    'padded-meta' => [array_replace($validRow, ['meta_id' => '01'])],
    'overflow-owner' => [array_replace($validRow, ['owner_id' => '9223372036854775808'])],
    'duplicate-meta' => [$validRow, $validRow],
    'descending-meta' => [array_replace($validRow, ['meta_id' => '2']), $validRow],
    'null-key' => [array_replace($validRow, ['meta_key' => null])],
    'overlong-key' => [array_replace($validRow, ['meta_key' => str_repeat('a', 1021)])],
    'non-string-value' => [array_replace($validRow, ['meta_value_prefix' => 36])],
    'numeric-bytes' => [array_replace($validRow, ['meta_value_bytes' => 36])],
    'noncanonical-bytes' => [array_replace($validRow, ['meta_value_bytes' => '036'])],
    'row-overflow' => array_fill(0, 100001, $validRow),
] as $label => $badRows) {
    $wpdb = locked_fixture();
    $owners = locked_begin();
    $wpdb->returnNextGetResultsAs($badRows, 'FROM `wp_postmeta` FORCE INDEX');
    $failure = locked_failure(static fn() => $owners->lock([LOCKED_OLD_UUID]));
    wprism_check($failure instanceof RuntimeException && str_starts_with($failure->getMessage(), 'wprism: protected post identity'),
        "$label reserved-range observation refuses with the existing subject");
    wprism_check_same([], locked_data_writes($wpdb), "$label observation cannot mutate product rows");
    Db::rollback("$label observation rollback");
}

foreach ([
    'unrequested' => [['owner_id' => '99']], 'zero' => [['owner_id' => '0']],
    'duplicate' => [['owner_id' => '41'], ['owner_id' => '41']],
    'not-row' => ['bad'], 'extra-column' => [['owner_id' => '41', 'ID' => '41']],
    'chunk-overflow' => [['owner_id' => '41'], ['owner_id' => '42']],
] as $label => $badRows) {
    $wpdb = locked_fixture();
    $owners = locked_begin();
    $wpdb->returnNextGetResultsAs($badRows, 'SELECT `ID` AS owner_id');
    $failure = locked_failure(static fn() => $owners->lock([LOCKED_OLD_UUID]));
    wprism_check($failure instanceof RuntimeException && str_contains($failure->getMessage(), 'live-owner lookup'),
        "$label owner observation cannot supply invented or oversized live-owner facts");
    Db::rollback("$label owner rollback");
}

$wpdb = locked_fixture();
$owners = locked_begin();
$wpdb->onQuery(static function (string $sql) use ($wpdb): ?string {
    if (str_contains($sql, 'FROM `wp_postmeta` FORCE INDEX')) {
        $wpdb->last_error = 'compatible-driver leftover error';
    }
    return null;
});
$failure = locked_failure(static fn() => $owners->lock([LOCKED_OLD_UUID]));
wprism_check_same('wprism: protected post identity locked wp_postmeta identity range failed', $failure?->getMessage(),
    'a defensive leftover read error cannot disappear in the following continuity queries');
$wpdb->onQuery(null);
Db::rollback('leftover error rollback');

$wpdb = locked_fixture();
$manyPosts = [];
$manyMeta = [];
foreach (range(502, 1) as $id) {
    $manyMeta[] = locked_meta($id, $id);
    if ($id !== 501) {
        $manyPosts[] = ['ID' => $id, 'post_type' => 'post', 'post_password' => ''];
    }
}
$wpdb->seedTable('wp_posts', $manyPosts)->seedTable('wp_postmeta', $manyMeta);
$owners = locked_begin();
$wpdb->resetLog();
$inventory = $owners->lock([LOCKED_OLD_UUID]);
$locks = locked_selects($wpdb);
wprism_check_same(4, count($locks), '502 candidate owners split into two bounded reads after the two metadata ranges');
wprism_check_same(501, count($inventory['live_post_owners']), 'cross-chunk orphan is excluded from the complete live set');
wprism_check(str_contains($locks[2], 'IN (' . implode(',', range(1, 500)) . ')')
    && str_ends_with($locks[2], 'LIMIT 501 FOR UPDATE'), 'first owner chunk locks 500 sorted candidate rows or gaps');
wprism_check(str_contains($locks[3], 'IN (501,502)') && str_ends_with($locks[3], 'LIMIT 3 FOR UPDATE'),
    'second owner chunk locks the missing 501 gap and live 502 row');
Db::rollback('multiple owner chunks rollback');

// The existing consumer owns one-owner semantics; exercise its public lock
// against actual rows, not a private projection or a canned SELECT answer.
foreach (['one', 'orphan', 'unrelated-malformed', 'duplicate-post', 'duplicate-term', 'local-alias',
    'local-missing', 'local-duplicate-meta', 'wrong-type', 'missing-post', 'missing-map', 'wrong-map-kind'] as $case) {
    $wpdb = locked_fixture();
    match ($case) {
        'orphan' => $wpdb->seedTable('wp_postmeta', [locked_meta(1, 41), locked_meta(2, 901)])
            ->seedTable('wp_termmeta', [locked_meta(3, 902, LOCKED_OLD_UUID, 'term')]),
        'unrelated-malformed' => $wpdb->seedTable('wp_postmeta', [locked_meta(1, 41), locked_meta(2, 901, 'invalid')]),
        'duplicate-post' => $wpdb->seedTable('wp_posts', [
            ['ID' => 41, 'post_type' => 'post', 'post_password' => 'secret'],
            ['ID' => 42, 'post_type' => 'post', 'post_password' => '']])
            ->seedTable('wp_postmeta', [locked_meta(1, 41), locked_meta(2, 42)]),
        'duplicate-term' => $wpdb->seedTable('wp_terms', [['term_id' => 9]])
            ->seedTable('wp_termmeta', [locked_meta(3, 9, LOCKED_OLD_UUID, 'term')]),
        'local-alias' => $wpdb->seedTable('wp_postmeta', [locked_meta(1, 41), locked_meta(2, 41, LOCKED_OLD_UUID, 'post', '_WPRISM_UUID')]),
        'local-missing' => $wpdb->seedTable('wp_postmeta', []),
        'local-duplicate-meta' => $wpdb->seedTable('wp_postmeta', [locked_meta(1, 41), locked_meta(2, 41)]),
        'wrong-type' => $wpdb->seedTable('wp_posts', [['ID' => 41, 'post_type' => 'page', 'post_password' => 'secret']]),
        'missing-post' => $wpdb->seedTable('wp_posts', []),
        'missing-map' => $wpdb->seedTable('wp_wprism_map', []),
        'wrong-map-kind' => $wpdb->seedTable('wp_wprism_map', [['uuid' => LOCKED_OLD_UUID, 'id_kind' => 'post', 'local_id' => 41, 'entity_type' => 'term']]),
        default => null,
    };
    $before = locked_rows($wpdb);
    locked_begin();
    DeleteGuardEvaluator::begin_authored_transaction();
    $wpdb->resetLog();
    $result = null;
    $failure = locked_failure(static function () use (&$result): void {
        $result = ProtectedPostIdentity::lock(LOCKED_OLD_UUID, 'post');
    });
    if (in_array($case, ['one', 'orphan', 'unrelated-malformed'], true)) {
        wprism_check_same(null, $failure, "$case retains the existing protected-post admission");
        wprism_check_same(['post_id' => 41, 'post_password' => 'secret'], $result, "$case returns the exact locked password");
        $locks = locked_selects($wpdb);
        wprism_check(str_starts_with($locks[0], 'SELECT uuid, id_kind, local_id, entity_type')
            && str_starts_with($locks[1], 'SELECT ID, post_type, post_password')
            && str_contains($locks[2], 'FROM `wp_postmeta`') && str_contains($locks[3], 'FROM `wp_termmeta`'),
            "$case preserves map then original post then global range lock order");
    } elseif ($case === 'missing-map') {
        wprism_check_same(null, $failure, 'missing map remains a normal absent protected binding');
        wprism_check_same(null, $result, 'missing map returns null without inventing a mapping');
        wprism_check_same(1, count(locked_selects($wpdb)), 'missing map stops before physical post or global range locks');
    } else {
        wprism_check_same(LOCKED_MISMATCH, $failure?->getMessage(), "$case retains the exact existing identity refusal");
    }
    wprism_check_same($before, locked_rows($wpdb), "$case preserves all physical and ledger rows");
    wprism_check_same([], locked_data_writes($wpdb), "$case cannot repair identity or mutate a password");
    Db::rollback("protected $case rollback");
    DeleteGuardEvaluator::end_authored_transaction();
}

wprism_check_summary('locked embedded UUID owners');
