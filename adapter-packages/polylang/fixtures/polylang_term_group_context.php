<?php
declare(strict_types=1);

/**
 * Child-process fixture for Polylang's native language ordering field.
 *
 * Drives the real policy, taxonomy grammar, term capture, schema validator,
 * ledger lookup and term materializer against the shared row-backed FakeWpdb.
 */

$root = dirname(__DIR__, 3);
require_once $root . '/sandbox/tests/lib/agent_version.php';
wprism_test_define_agent_versions();
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/Db.php';
require_once $root . '/agent/src/Kernel/Uuid.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/agent/src/Capture/EntityMetaCapture.php';
require_once $root . '/agent/src/Capture/TermCapture.php';
require_once $root . '/agent/src/Apply/ApplyFieldMaterializer.php';
require_once $root . '/agent/src/Apply/TermMaterializer.php';
require_once $root . '/agent/src/Repository/RepositorySchemaValidator.php';

$wpdb = new \WPrismTest\FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;
$wpdb->setColumns('termmeta', [
    'meta_id' => 'bigint unsigned',
    'term_id' => 'bigint unsigned',
    'meta_key' => 'varchar(255)',
    'meta_value' => 'longtext',
]);
$wpdb->seedTable('termmeta', []);
$wpdb->setIndexes('termmeta', [[
    'Key_name' => 'term_id',
    'Seq_in_index' => 1,
    'Column_name' => 'term_id',
    'Sub_part' => null,
    'Non_unique' => 1,
    'Index_type' => 'BTREE',
]]);
$wpdb->setColumns('options', [
    'option_id' => 'bigint unsigned',
    'option_name' => 'varchar(191)',
    'option_value' => 'longtext',
    'autoload' => 'varchar(20)',
]);
$wpdb->seedTable('options', []);
$wpdb->setUniqueKey('options', ['option_name']);
$wpdb->setIndexes('options', [[
    'Key_name' => 'option_name',
    'Seq_in_index' => 1,
    'Column_name' => 'option_name',
    'Sub_part' => null,
    'Non_unique' => 0,
    'Index_type' => 'BTREE',
]]);

$policy = \WPrism\Policy::load(
    null,
    ['core', 'polylang'],
    adapterLibrary: \WPrism\AdapterLibrary::fromSourcePackage($root, 'polylang')
);
$tokens = new \WPrism\Tokens('http://source.test', 'http://source.test/wp-content/uploads');
$meta = new \WPrism\EntityMetaCapture(
    $policy,
    $tokens,
    static function (): void {},
    static function (): void {},
    static function (): void {}
);
$capture = new \WPrism\TermCapture($policy, $tokens, $meta);
$uuid = '00000000-0000-4000-8000-000000000201';
$languageDescription = serialize(['locale' => 'ar', 'rtl' => 1, 'flag_code' => 'sa']);
$language = $capture->capture((object) [
    'term_id' => 19,
    'taxonomy' => 'language',
    'name' => 'العربية',
    'slug' => 'ar',
    'description' => $languageDescription,
    'parent' => 0,
    'term_group' => 2,
], $uuid, []);
$languageFront = \WPrism\Canon::decode($language['content']);
$category = $capture->capture((object) [
    'term_id' => 20,
    'taxonomy' => 'category',
    'name' => 'Ordinary',
    'slug' => 'ordinary',
    'description' => '',
    'parent' => 0,
    'term_group' => 77,
], '00000000-0000-4000-8000-000000000202', []);
$categoryFront = \WPrism\Canon::decode($category['content']);
$malformedDescriptionRefusal = '';
try {
    $capture->capture((object) [
        'term_id' => 21,
        'taxonomy' => 'language',
        'name' => 'Broken',
        'slug' => 'broken',
        'description' => 'a:3:{broken',
        'parent' => 0,
        'term_group' => 3,
    ], '00000000-0000-4000-8000-000000000203', []);
} catch (\Throwable $failure) {
    $malformedDescriptionRefusal = $failure->getMessage();
}
$termGroupRefusals = [];
foreach ([
    'negative-int' => -1,
    'negative-string' => '-1',
    'leading-zero' => '02',
    'overflow' => '999999999999999999999999999999999999',
    'float' => 2.5,
    'scientific' => '2e0',
    'junk' => '2junk',
    'array' => [2],
    'object' => (object) ['value' => 2],
    'null' => null,
    'boolean' => false,
] as $label => $termGroupValue) {
    try {
        $capture->capture((object) [
            'term_id' => 100,
            'taxonomy' => 'language',
            'name' => 'Boundary',
            'slug' => 'boundary',
            'description' => $languageDescription,
            'parent' => 0,
            'term_group' => $termGroupValue,
        ], '00000000-0000-4000-8000-000000000299', []);
        $termGroupRefusals[$label] = '';
    } catch (\Throwable $failure) {
        $termGroupRefusals[$label] = $failure->getMessage();
    }
}
$stringTermGroup = $capture->capture((object) [
    'term_id' => 101,
    'taxonomy' => 'language',
    'name' => 'String boundary',
    'slug' => 'string-boundary',
    'description' => $languageDescription,
    'parent' => 0,
    'term_group' => '2',
], '00000000-0000-4000-8000-000000000298', []);
$stringTermGroupFront = \WPrism\Canon::decode($stringTermGroup['content']);

$findings = [];
$validator = new \WPrism\RepositorySchemaValidator(
    $policy,
    'sidebar',
    static function (string $code, string $path, string $locator, string $message) use (&$findings): void {
        $findings[] = compact('code', 'path', 'locator', 'message');
    }
);
$validator->validate('term', $language['path'], $languageFront, null);
$validFindingCount = count($findings);
$missing = $languageFront;
unset($missing['term_group']);
$validator->validate('term', 'terms/language/missing.json', $missing, null);
$stringly = $languageFront;
$stringly['term_group'] = '2';
$validator->validate('term', 'terms/language/stringly.json', $stringly, null);
$foreign = $categoryFront;
$foreign['term_group'] = 77;
$validator->validate('term', 'terms/category/foreign.json', $foreign, null);

$wpdb->setColumns('wprism_map', [
    'uuid' => 'char(36)',
    'entity_type' => 'varchar(32)',
    'id_kind' => 'varchar(32)',
    'local_id' => 'bigint unsigned',
]);
$wpdb->seedTable('wprism_map', [[
    'uuid' => $uuid,
    'entity_type' => 'term',
    'id_kind' => \WPrism\Ledger::KIND_TERM,
    'local_id' => 29,
]]);
$wpdb->setUniqueKey('wprism_map', ['uuid', 'id_kind']);
$wpdb->seedTable('terms', [[
    'term_id' => 29,
    'name' => 'Target Arabic',
    'slug' => 'ar',
    'term_group' => 0,
]]);
$wpdb->seedTable('term_taxonomy', [[
    'term_taxonomy_id' => 39,
    'term_id' => 29,
    'taxonomy' => 'language',
    'description' => '',
    'parent' => 0,
    'count' => 0,
]]);
foreach ([
    $wpdb->terms,
    $wpdb->term_taxonomy,
    $wpdb->termmeta,
    $wpdb->options,
    $wpdb->prefix . 'wprism_map',
] as $table) {
    $wpdb->setTableEngine($table, 'InnoDB');
}
$wpdb->enableInformationSchema();
$fieldMaterializer = new \WPrism\ApplyFieldMaterializer($policy, $tokens);
$materializer = new \WPrism\TermMaterializer(
    $policy,
    $tokens,
    $fieldMaterializer
);
\WPrism\Db::start_repeatable_read(
    'Polylang term fixture transaction start',
    new \WPrism\NativeDatabaseProfile(
        [$wpdb->prefix . 'wprism_map'],
        [$wpdb->terms, $wpdb->term_taxonomy, $wpdb->termmeta]
    )
);
$fieldMaterializer->begin_authored_transaction();
$materializer->begin_authored_transaction();
\WPrism\CacheInvalidationTransaction::begin();
\WPrism\CacheInvalidationTransaction::prepare_term_hierarchy_options(['language' => false]);
$materializer->finalize_term($languageFront, []);
\WPrism\Db::commit('Polylang term fixture transaction commit');
\WPrism\CacheInvalidationTransaction::finish();
\WPrism\CacheInvalidationTransaction::end();
$materializer->end_authored_transaction();
$fieldMaterializer->end_authored_transaction();
$materializedRows = $wpdb->rows('terms');
$materializedTaxonomyRows = $wpdb->rows('term_taxonomy');

$invalidExact = '';
try {
    \WPrism\TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'bad-exact',
        'taxonomies' => ['language' => ['term_group' => 'derived']],
    ]);
} catch (\Throwable $failure) {
    $invalidExact = $failure->getMessage();
}
$invalidPattern = '';
try {
    \WPrism\TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'bad-pattern',
        'taxonomy_patterns' => [['match' => '^lang_', 'term_group' => 'authored']],
    ]);
} catch (\Throwable $failure) {
    $invalidPattern = $failure->getMessage();
}

echo \WPrism\Canon::encode([
    'captured_category_has_term_group' => array_key_exists('term_group', $categoryFront),
    'captured_language_term_group' => $languageFront['term_group'] ?? null,
    'invalid_exact' => $invalidExact,
    'invalid_pattern' => $invalidPattern,
    'language_description' => $languageFront['description'] ?? null,
    'malformed_description_refusal' => $malformedDescriptionRefusal,
    'materialized_description' => $materializedTaxonomyRows[0]['description'] ?? null,
    'materialized_term_group' => $materializedRows[0]['term_group'] ?? null,
    'schema_findings' => array_slice($findings, $validFindingCount),
    'string_term_group' => $stringTermGroupFront['term_group'] ?? null,
    'term_group_refusals' => $termGroupRefusals,
    'valid_schema_findings' => $validFindingCount,
]);
