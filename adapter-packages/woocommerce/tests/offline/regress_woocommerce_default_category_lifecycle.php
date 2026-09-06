<?php
declare(strict_types=1);

// The optional argv root lets this capsule-owned regression exercise an
// integration candidate before its production files are committed. Ordinary
// adapter-package runs use the capsule's own repository root.
$root = isset($argv[1]) && is_string($argv[1]) && $argv[1] !== ''
    ? rtrim($argv[1], '/')
    : dirname(__DIR__, 4);

require_once $root . '/sandbox/tests/lib/agent_version.php';
wprism_test_define_agent_versions();
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/OptionState.php';
require_once $root . '/agent/src/Kernel/Uuid.php';
require_once $root . '/agent/src/Kernel/Db.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Repository/Identity.php';
require_once $root . '/agent/src/Kernel/Canary.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Repository/Snapshot.php';
require_once $root . '/agent/src/Repository/SidebarState.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/agent/src/Capture/Capture.php';
require_once $root . '/agent/src/Promotion/StateHandoffVerifier.php';

use WPrism\AdapterLibrary;
use WPrism\Canon;
use WPrism\CommandRefusalException;
use WPrism\CompiledRepository;
use WPrism\Db;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\StateHandoffVerifier;
use WPrism\TableSchema;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

/**
 * Install the complete schema Capture::snapshot_options_core() proves, while
 * varying only the Woo lifecycle state under test.
 *
 * @param list<array<string,mixed>> $options
 * @param list<array<string,mixed>> $map
 * @param list<array<string,mixed>> $terms
 * @param list<array<string,mixed>> $termTaxonomy
 */
function woo_default_lifecycle_database(
    array $options = [],
    array $map = [],
    array $terms = [],
    array $termTaxonomy = []
): FakeWpdb {
    Db::forget_transaction_tracking();
    WpStore::reset()->seedOptions(['home' => 'https://target.example.test']);
    $db = FakeWpdb::install()->enableInformationSchema()->enableFullApplySqlExtensions();
    foreach (TableSchema::core_capture_required_columns() as $property => $columns) {
        $db->setColumns($db->$property, array_fill_keys($columns, 'longtext'))
            ->setTableEngine($db->$property, 'InnoDB');
    }
    $db->seedTable($db->options, $options)
        ->seedTable($db->terms, $terms)
        ->seedTable($db->term_taxonomy, $termTaxonomy)
        ->seedTable('wp_wprism_map', $map)
        ->setColumns('wp_wprism_map', [
            'uuid' => 'char(36)',
            'entity_type' => 'varchar(64)',
            'id_kind' => 'varchar(64)',
            'local_id' => 'bigint unsigned',
        ])
        ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])
        ->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id'])
        ->setTableEngine('wp_wprism_map', 'InnoDB')
        ->seedTable('wp_wprism_state', [])
        ->setColumns('wp_wprism_state', [
            'uuid' => 'varchar(64)',
            'entity_type' => 'varchar(64)',
            'content_hash' => 'char(64)',
        ])
        ->setUniqueKey('wp_wprism_state', ['uuid'])
        ->setTableEngine('wp_wprism_state', 'InnoDB')
        ->seedTable('wp_wprism_kv', [])
        ->setColumns('wp_wprism_kv', ['k' => 'varchar(191)', 'v' => 'longtext'])
        ->setUniqueKey('wp_wprism_kv', ['k'])
        ->setTableEngine('wp_wprism_kv', 'InnoDB')
        ->seedTable('wp_wprism_journal', [])
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
        ->setPrimaryKey('wp_wprism_journal', 'id')
        ->setAutoIncrement('wp_wprism_journal', 1, 'id')
        ->setTableEngine('wp_wprism_journal', 'InnoDB');
    return $db;
}

/** @return array<string,list<array<string,mixed>>> */
function woo_default_lifecycle_rows(FakeWpdb $db): array {
    $tables = array_values(array_unique(array_merge(
        array_map(static fn(string $property): string => (string) $db->$property,
            array_keys(TableSchema::core_capture_required_columns())),
        ['wp_wprism_map', 'wp_wprism_state', 'wp_wprism_kv', 'wp_wprism_journal']
    )));
    $rows = [];
    foreach ($tables as $table) {
        $rows[$table] = $db->rows($table);
    }
    ksort($rows, SORT_STRING);
    return $rows;
}

/** @return array{snapshot_starts:int,commits:int,rollbacks:int,transaction_dml:int} */
function woo_default_lifecycle_transaction_evidence(FakeWpdb $db): array {
    $evidence = ['snapshot_starts' => 0, 'commits' => 0, 'rollbacks' => 0, 'transaction_dml' => 0];
    $inside = false;
    foreach ($db->queries() as $sql) {
        if ($sql === 'START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT') {
            $evidence['snapshot_starts']++;
            $inside = true;
            continue;
        }
        if ($inside && $sql === 'COMMIT AND NO CHAIN NO RELEASE') {
            $evidence['commits']++;
            $inside = false;
            continue;
        }
        if ($inside && $sql === 'ROLLBACK AND NO CHAIN NO RELEASE') {
            $evidence['rollbacks']++;
            $inside = false;
            continue;
        }
        if ($inside && preg_match('/^(?:INSERT|UPDATE|DELETE|REPLACE)\b/D', $sql) === 1) {
            $evidence['transaction_dml']++;
        }
    }
    return $evidence;
}

$manifest = json_decode((string) file_get_contents(
    $root . '/adapter-packages/woocommerce/package/manifest.json'
), true, flags: JSON_THROW_ON_ERROR);
$policy = Policy::from_snapshot([
    'dispositions' => null,
    'format' => 'wprism-policy-snapshot/v6',
    'adapter_sources' => ['certificates' => [], 'format' => 'wprism-adapter-sources/v2', 'out_of_tree' => []],
    'manifests' => [$manifest],
    'site' => ['manifests' => ['woocommerce'], 'policy' => [], 'spec_version' => WPRISM_SPEC_VERSION],
], AdapterLibrary::fromSourcePackage($root, 'woocommerce'));

$category = '11111111-1111-7111-8111-111111111111';
$neighbor = '22222222-2222-7222-8222-222222222222';
$desiredRecord = OptionState::present('{{term:' . $category . '}}', 'yes');
$desired = OptionState::document(['default_product_cat' => $desiredRecord]);
$compiled = CompiledRepository::create([
    'tree' => ['options/core' => ['type' => 'options', 'path' => 'options/core.json', 'data' => $desired]],
    'deletions' => [],
    'revision_hash' => str_repeat('a', 64),
    'manifest_hash' => str_repeat('b', 64),
]);
$snapshot = static fn(): array => StateHandoffVerifier::options_snapshot('/unused', $policy, $compiled, false);

// Before activation, the desired-but-absent option is bound to its frozen
// desired record. The hook may create a real category before identity minting;
// strict observation projects that wholly-unmapped tuple back to the same
// bound tombstone, so state apply sees no invented lifecycle change.
$db = woo_default_lifecycle_database();
$before = $snapshot();
$beforeRecord = OptionState::records($before['document'])['default_product_cat'] ?? null;
wprism_check_same(
    Canon::encode(OptionState::deleted($desiredRecord)),
    Canon::encode($beforeRecord),
    'preactivation absence is bound to the frozen desired default-category record'
);
wprism_check_same(null, $db->activeTransactionIsolation(), 'preactivation observation returns its database session idle');

$db->seedTable('wp_options', [[
    'option_id' => 1,
    'option_name' => 'default_product_cat',
    'option_value' => '41',
    'autoload' => 'yes',
]])->seedTable('wp_terms', [[
    'term_id' => 41,
    'name' => 'Uncategorized',
    'slug' => 'uncategorized',
]])->seedTable('wp_term_taxonomy', [[
    'term_taxonomy_id' => 41,
    'term_id' => 41,
    'taxonomy' => 'product_cat',
    'description' => '',
    'parent' => 0,
]])->resetLog();
$after = $snapshot();
wprism_check_same(
    [$before['hash'], Canon::encode($before['document'])],
    [$after['hash'], Canon::encode($after['document'])],
    'a physically valid wholly-unmapped hook-created category preserves the bound desired handoff hash and document'
);
wprism_check_same(
    [],
    StateHandoffVerifier::unexpected_lifecycle_state_changes($before['document'], $after['document'], $desired),
    'the real lifecycle record gate accepts only the hash-bound transient projection'
);
wprism_check_same(
    ['snapshot_starts' => 1, 'commits' => 1, 'rollbacks' => 0, 'transaction_dml' => 0],
    woo_default_lifecycle_transaction_evidence($db),
    'the accepted posthook observation is one read-only transaction with no transactional DML'
);

$physicalTerm = [[
    'term_id' => 41,
    'name' => 'Uncategorized',
    'slug' => 'uncategorized',
]];
$validTt = [[
    'term_taxonomy_id' => 41,
    'term_id' => 41,
    'taxonomy' => 'product_cat',
    'description' => '',
    'parent' => 0,
]];
$option = [[
    'option_id' => 1,
    'option_name' => 'default_product_cat',
    'option_value' => '41',
    'autoload' => 'yes',
]];
$mapped = static fn(string $uuid, string $kind, int $id = 41): array => [
    'uuid' => $uuid,
    'entity_type' => 'term',
    'id_kind' => $kind,
    'local_id' => $id,
];

$refusals = [
    'partial primary identity intersection' => static fn(): FakeWpdb => woo_default_lifecycle_database(
        $option,
        [$mapped($category, 'term')],
        $physicalTerm,
        $validTt
    ),
    'partial alternate identity intersection' => static fn(): FakeWpdb => woo_default_lifecycle_database(
        $option,
        [$mapped($category, 'term_taxonomy')],
        $physicalTerm,
        $validTt
    ),
    'wrong physical taxonomy' => static fn(): FakeWpdb => woo_default_lifecycle_database(
        $option,
        [],
        $physicalTerm,
        [array_replace($validTt[0], ['taxonomy' => 'category'])]
    ),
    'divergent physical coordinate' => static fn(): FakeWpdb => woo_default_lifecycle_database(
        $option,
        [],
        $physicalTerm,
        [array_replace($validTt[0], ['term_id' => 99])]
    ),
    'database read failure' => static fn(): FakeWpdb => woo_default_lifecycle_database(
        $option,
        [$mapped($category, 'term'), $mapped($category, 'term_taxonomy')],
        $physicalTerm,
        $validTt
    )->failNextQuery('private fixture driver detail', 'FROM wp_terms'),
];

foreach ($refusals as $label => $fixture) {
    $db = $fixture();
    $beforeRows = woo_default_lifecycle_rows($db);
    $failure = null;
    try {
        $snapshot();
    } catch (Throwable $caught) {
        $failure = $caught;
    }
    $expectedRefusal = $label === 'database read failure'
        ? $failure instanceof RuntimeException && !$failure instanceof CommandRefusalException
        : $failure instanceof CommandRefusalException
            && $failure->reasonCode === 'reference_intersection_failed';
    wprism_check($expectedRefusal, "$label fails closed through the real options-only snapshot");
    wprism_check_same(
        $beforeRows,
        woo_default_lifecycle_rows($db),
        "$label leaves every registered source and ledger row unchanged"
    );
    wprism_check_same(
        ['snapshot_starts' => 1, 'commits' => 0, 'rollbacks' => 1, 'transaction_dml' => 0],
        woo_default_lifecycle_transaction_evidence($db),
        "$label rolls back one read-only snapshot without transactional DML"
    );
    wprism_check_same(null, $db->activeTransactionIsolation(), "$label returns the database session idle");
}

wprism_check_summary('WooCommerce default-category lifecycle handoff');
