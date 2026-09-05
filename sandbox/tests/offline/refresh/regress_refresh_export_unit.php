<?php
/**
 * Offline boundary regression: explicit ledger schema and rows run through
 * the shared interpreter. Mutation SQL refuses before execution, so strict
 * ledger/export helpers remain observers rather than capture repair paths.
 */
$root = realpath(__DIR__ . '/../../../..');
if ($root === false) throw new RuntimeException('FAIL: root missing');
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once $root . '/agent/src/Kernel/Uuid.php';
require_once $root . '/agent/src/Kernel/Db.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Capture/RefreshExport.php';
require_once $root . '/agent/src/Repository/Snapshot.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/agent/src/Policy/ScopeDiscovery.php';

use WPrism\Canon;
use WPrism\Ledger;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\RefreshExport;
use WPrism\ScopeDiscovery;
use WPrism\Snapshot;
use WPrism\Tokens;
use WPrism\Uuid;
use WPrismTest\FakeWpdb;

function fail_re(string $message): never { throw new RuntimeException("FAIL: $message"); }
function check_re(bool $ok, string $message): void { if (!$ok) fail_re($message); }
if (!function_exists('get_taxonomy')) {
    function get_taxonomy(string $_taxonomy): false { return false; }
}

$uuid = '123e4567-e89b-42d3-a456-426614174000';
$renamedNaturalUuid = Uuid::v5(
    Uuid::NAMESPACE_WPRISM,
    'woocommerce_attribute_taxonomies:original-name'
);
$wpdb = FakeWpdb::install()->seedTable('wp_wprism_map', [
    ['uuid' => $uuid, 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => 7],
    [
        'uuid' => $renamedNaturalUuid,
        'entity_type' => 'woocommerce_attribute_taxonomies',
        'id_kind' => 'attr_taxonomy',
        'local_id' => 42,
    ],
])->setColumns('wp_wprism_map', [
    'uuid' => 'char(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint(20) unsigned',
])->setColumns('wp_wprism_state', [
    'uuid' => 'varchar(64)', 'entity_type' => 'varchar(64)', 'content_hash' => 'char(64)',
])->setColumns('wp_wprism_kv', ['k' => 'varchar(191)', 'v' => 'longtext']);
$index = static fn(string $name, string $column, int $sequence): array => [
    'Key_name' => $name, 'Non_unique' => 0, 'Seq_in_index' => $sequence,
    'Column_name' => $column, 'Sub_part' => null, 'Index_type' => 'BTREE',
];
$wpdb->setIndexes('wp_wprism_map', [
    $index('PRIMARY', 'uuid', 1), $index('PRIMARY', 'id_kind', 2),
    $index('kind_local', 'id_kind', 1), $index('kind_local', 'local_id', 2),
])->setIndexes('wp_wprism_state', [$index('PRIMARY', 'uuid', 1)])
    ->setIndexes('wp_wprism_kv', [$index('PRIMARY', 'k', 1)])
    ->onQuery(static function (string $sql): ?string {
        if (preg_match('/^(?:SELECT|SHOW)\b/i', $sql) !== 1) {
            throw new RuntimeException('FAIL: read-only fixture attempted mutation SQL');
        }
        return null;
    });
Ledger::assert_read_only_schema();
Ledger::require_read_only_mapping($uuid, 'post', 'post', 7, 'fixture post');
check_re($wpdb->ddlLog() === [], 'read-only ledger helper attempted schema repair');

$identify = new ReflectionMethod(Snapshot::class, 'identify_row');
// issue #3318: identify_row() takes the capture-direction tokenizer, because a
// parent-scoped natural key's ref component derives from the REFERENCED row's
// uuid. The strict read-only branch under test returns before touching it —
// asserted below by the unchanged zero-mutation-query check — so an
// uninitialized instance is exactly the right fixture: it proves that path
// never reaches for one.
$identifyTokens = (new ReflectionClass(Tokens::class))->newInstanceWithoutConstructor();
$retained = $identify->invoke(null, 'woocommerce_attribute_taxonomies', [
    'id_kind' => 'attr_taxonomy',
    'identity' => ['mode' => 'natural_key', 'column' => 'attribute_name'],
], ['attribute_name' => 'renamed-value'], 42, $identifyTokens, false, true);
check_re($retained === $renamedNaturalUuid,
    'strict export did not preserve durable natural-key identity across an authored rename');
check_re($wpdb->ddlLog() === [], 'natural-key continuity check attempted schema repair');

// The isolated control bootstrap deliberately skips user plugins. An exact
// plugin taxonomy can therefore be in policy scope without being registered.
// Production truth must refuse that unknown relationship ownership rather
// than returning a warning plus a silently incomplete P snapshot.
$policy = new Policy();
$warnings = [];
$scope = new ScopeDiscovery(
    $policy,
    null,
    static function (string $warning) use (&$warnings): void {
        $warnings[] = $warning;
    }
);
try {
    $scope->taxonomyOwnership(['plugin_exact_taxonomy'], ['post'], true);
    fail_re('strict export silently accepted an unregistered scoped plugin taxonomy');
} catch (RuntimeException $e) {
    check_re(str_contains($e->getMessage(), 'refresh export refused')
        && str_contains($e->getMessage(), 'plugin-owned'),
        'unregistered scoped taxonomy refusal was not actionable');
}
check_re($warnings === [], 'strict taxonomy refusal degraded to a warning');

try {
    Ledger::require_read_only_mapping($uuid, 'term', 'post', 7, 'contradictory fixture');
    fail_re('contradictory durable identity was accepted');
} catch (RuntimeException $e) {
    check_re(str_contains($e->getMessage(), 'contradicts'), 'contradiction did not fail loudly');
}

$source = file_get_contents($root . '/agent/src/Capture/RefreshExport.php');
if ($source === false) fail_re('cannot read exporter source');
$code = '';
foreach (token_get_all($source) as $token) {
    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) continue;
    $code .= is_array($token) ? $token[1] : $token;
}
check_re(
    str_contains($source, 'Db::start_read_only_consistent_snapshot(')
        && str_contains($source, "'starting read-only production snapshot',")
        && str_contains($source, '$profile')
        && !str_contains($source, "self::query(\$wpdb, 'START TRANSACTION"),
    'read-only export delegates exact isolation, connection, transaction outcome, and table-profile proof to Db'
);
check_re(preg_match('/Ledger::(?:ensure|set|forget|prune_state|prune_dead_map|prune_dead_table_map|kv_set|kv_delete)\(/', $code) !== 1, 'exporter calls a forbidden ledger mutation');
check_re(preg_match('/Snapshot::(?:repair_truncated_entity_types|prune_dead_map|prune_option_name_ref_map)\(/', $code) !== 1, 'exporter calls a forbidden snapshot repair');
check_re(!str_contains($code, 'Canon::write_file('), 'exporter writes filesystem state');

$records = new ReflectionMethod(RefreshExport::class, 'records');
$result = $records->invoke(null, [[
    'uuid' => 'options/core', 'type' => 'options', 'path' => 'options/core.json', 'content' => "{}\n",
], [
    'uuid' => $uuid, 'type' => 'post', 'path' => "posts/post/$uuid--fixture.md", 'content' => "visible\n", 'hash_basis' => "semantic\n",
]]);
check_re(array_keys($result) === [$uuid, 'options/core'], 'records are not keyed by semantic identity');
check_re($result[$uuid]['hash'] === hash('sha256', "semantic\n"), 'post semantic hash basis was not retained');
check_re($result['options/core']['content'] === "{}\n", 'canonical content was not preserved');
try {
    $records->invoke(null, [[
        'uuid' => 'options/core', 'type' => 'options', 'path' => '../options/core.json', 'content' => "{}\n",
    ]]);
    fail_re('unsafe export record path was accepted');
} catch (RuntimeException $e) {
    check_re(str_contains($e->getMessage(), 'invalid'), 'unsafe path refusal was unclear');
}

// A per-option root is a virtual state identity. The real exporter helper
// must emit a minimal options/core carrier rather than accidentally sending
// every sibling option through the scoped-refresh wire envelope.
$optionContent = Canon::encode(OptionState::document([
    'blogdescription' => OptionState::present('excluded sibling', 'yes'),
    'blogname' => OptionState::present('selected title', 'yes'),
]));
$scopedLive = new ReflectionMethod(RefreshExport::class, 'scoped_live_entities');
$projected = $scopedLive->invoke(null, [
    ['uuid' => 'options/core', 'type' => 'options', 'path' => 'options/core.json', 'content' => $optionContent],
    ['uuid' => $uuid, 'type' => 'post', 'path' => "posts/post/$uuid--fixture.md", 'content' => "visible\n"],
], [$uuid, 'option:blogname'], ['blogname']);
$projectedOptions = OptionState::records(Canon::decode((string) $projected['options/core']['content']));
$projectedKeys = array_keys($projected);
sort($projectedKeys, SORT_STRING);
check_re($projectedKeys === [$uuid, 'options/core']
    && array_keys($projectedOptions) === ['blogname']
    && ($projectedOptions['blogname']['value'] ?? null) === 'selected title'
    && ($projected['options/core']['hash_basis'] ?? null) === (string) $projected['options/core']['content'],
    'option-root refresh projection emits only the selected record with matching carrier hash basis');
$wholeOptionsProjection = $scopedLive->invoke(null, [
    ['uuid' => 'options/core', 'type' => 'options', 'path' => 'options/core.json', 'content' => $optionContent],
], ['options/core', 'option:blogname'], ['blogname']);
check_re(($wholeOptionsProjection['options/core']['content'] ?? null) === $optionContent
    && !array_key_exists('hash_basis', $wholeOptionsProjection['options/core']),
    'a whole-options root remains whole when a redundant option root is also selected');
try {
    $scopedLive->invoke(null, [
        ['uuid' => 'options/core', 'type' => 'options', 'path' => 'options/core.json', 'content' => $optionContent],
    ], ['option:missing'], ['missing']);
    fail_re('option-root refresh projection accepted a missing selected record');
} catch (RuntimeException $e) {
    check_re(str_contains($e->getMessage(), "lost selected option 'missing'"),
        'missing selected option did not fail closed at the export boundary');
}
echo "REGRESS_REFRESH_EXPORT_UNIT PASSED\n";
