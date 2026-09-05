<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/agent_version.php';
wprism_test_define_agent_versions();
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/wp_stubs.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/FakeWpdb.php';
require_once dirname(__DIR__, 4) . '/agent/src/Capture/OptionsCapture.php';
require_once dirname(__DIR__, 4) . '/agent/src/Apply/OptionsMaterializer.php';

use WPrism\AdapterLibrary;
use WPrism\ApplyFieldMaterializer;
use WPrism\CacheInvalidationTransaction;
use WPrism\CommandRefusalException;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\OptionsCapture;
use WPrism\OptionsMaterializer;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

// Woo 11.0.1's installer writes a TT id; its admin/default-term APIs read a
// term id while AssignDefaultCategory inserts the same value as a TT id.
// The fd8 four-plugin capture omitted 3300014 with a warning. A collision
// can be worse: the old term-only rule publishes another category's UUID.
$root = dirname(__DIR__, 4);
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
$physicalRows = static function (array $bindings): array {
    $terms = [];
    $byUuid = [];
    foreach ($bindings as [$uuid, $kind, $id]) {
        if ($kind === 'term') {
            $terms[] = ['term_id' => $id];
            $byUuid[$uuid] = $id;
        }
    }
    $tt = [];
    foreach ($bindings as [$uuid, $kind, $id]) {
        if ($kind === 'term_taxonomy') {
            $tt[] = ['term_taxonomy_id' => $id, 'term_id' => $byUuid[$uuid] ?? 0, 'taxonomy' => 'product_cat'];
        }
    }
    return [$terms, $tt];
};
$capture = static function (
    string $stored, array $bindings, bool $force = false, bool $strict = false, ?array $physical = null
) use ($policy, $physicalRows): array {
    WpStore::reset()->seedOptions(['home' => 'https://source.example.test']);
    $wpdb = FakeWpdb::install();
    $wpdb->seedTable('wp_options', [[
        'option_id' => 1, 'option_name' => 'default_product_cat',
        'option_value' => $stored, 'autoload' => 'yes',
    ]]);
    $wpdb->seedTable('wp_wprism_map', array_map(
        static fn(array $row): array => [
            'uuid' => $row[0], 'entity_type' => 'term', 'id_kind' => $row[1], 'local_id' => $row[2],
        ], $bindings
    ));
    [$terms, $tt] = $physical ?? $physicalRows($bindings);
    $wpdb->seedTable('wp_terms', $terms)->seedTable('wp_term_taxonomy', $tt);
    $tokens = new Tokens('https://source.example.test', 'https://source.example.test/uploads');
    $tokens->policy = $policy;
    $reader = new OptionsCapture(
        $policy, $tokens, static function (): void {},
        static fn(): ?string => null, static fn(): bool => false
    );
    $before = $wpdb->rows('wp_options');
    try {
        $result = $reader->capture(false, $force, null, [], false, $strict);
    } finally {
        wprism_check_same($before, $wpdb->rows('wp_options'), 'default-category capture does not mutate its source row');
    }
    return [$result['document']['records']['default_product_cat'] ?? null, $tokens->warnings];
};

[$record, $warnings] = $capture('41', [[$category, 'term', 41], [$category, 'term_taxonomy', 41]]);
wprism_check_same([
    'state' => 'present', 'autoload' => 'yes', 'value' => '{{term:' . $category . '}}',
], $record, 'a natively coherent default remains an ordinary portable term token');
wprism_check_same([], $warnings, 'coherent default capture is warning-free');

foreach ([
    'installer TT spelling' => ['71', [[$category, 'term', 41], [$category, 'term_taxonomy', 71]]],
    'admin term spelling' => ['41', [[$category, 'term', 41], [$category, 'term_taxonomy', 71]]],
    'plausible wrong-category collision' => ['71', [
        [$category, 'term', 41], [$category, 'term_taxonomy', 71],
        [$neighbor, 'term', 71], [$neighbor, 'term_taxonomy', 91],
    ]],
    'missing alternate binding' => ['41', [[$category, 'term', 41]]],
] as $label => [$value, $bindings]) {
    foreach ([false, true] as $force) {
        wprism_check_throws(
            static fn() => $capture($value, $bindings, $force),
            RuntimeException::class,
            "$label refuses instead of omitting or misbinding authored intent (force=" . (int) $force . ')'
        );
    }
}

// Run the actual option materializer inside the same authored transaction
// protocol as AuthoredTransactionExecutor. A preceding option write proves
// that rejection rolls back durable work, not merely that a pure codec throws.
$apply = static function (array $bindings, bool $refuses, ?array $physical = null) use ($policy, $category, $physicalRows): void {
    WpStore::reset()->seedOptions(['home' => 'https://target.example.test']);
    $wpdb = FakeWpdb::install()
        ->seedTable('wp_options', [[
            'option_id' => 1, 'option_name' => 'default_product_cat',
            'option_value' => '17', 'autoload' => 'yes',
        ]])
        ->setColumns('wp_options', [
            'option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)',
            'option_value' => 'longtext', 'autoload' => 'varchar(20)',
        ])
        ->setAutoIncrement('wp_options', 2, 'option_id')
        ->setUniqueKey('wp_options', ['option_name'])
        ->setIndexes('wp_options', [[
            'Key_name' => 'option_name', 'Column_name' => 'option_name', 'Seq_in_index' => 1,
            'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE',
        ]])
        ->setTableEngine('wp_options', 'InnoDB')
        ->seedTable('wp_wprism_map', array_map(
            static fn(array $row): array => [
                'uuid' => $row[0], 'entity_type' => 'term', 'id_kind' => $row[1], 'local_id' => $row[2],
            ], $bindings
        ))
        ->setColumns('wp_wprism_map', [
            'uuid' => 'varchar(36)', 'entity_type' => 'varchar(64)',
            'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned',
        ])
        ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])
        ->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id'])
        ->setTableEngine('wp_wprism_map', 'InnoDB')
        ->enableInformationSchema();
    [$terms, $tt] = $physical ?? $physicalRows($bindings);
    $wpdb->seedTable('wp_terms', $terms)
        ->setColumns('wp_terms', ['term_id' => 'bigint unsigned'])
        ->setTableEngine('wp_terms', 'InnoDB')
        ->seedTable('wp_term_taxonomy', $tt)
        ->setColumns('wp_term_taxonomy', [
            'term_taxonomy_id' => 'bigint unsigned', 'term_id' => 'bigint unsigned', 'taxonomy' => 'varchar(32)',
        ])
        ->setTableEngine('wp_term_taxonomy', 'InnoDB');
    $tokens = new Tokens('https://target.example.test', 'https://target.example.test/uploads');
    $tokens->policy = $policy;
    $fields = new ApplyFieldMaterializer($policy, $tokens);
    $writer = new OptionsMaterializer($policy, $tokens, $fields);
    $before = $wpdb->rows('wp_options');
    $warnings = [];
    $caught = null;
    Db::start_repeatable_read(
        'Woo default-category authored test',
        new NativeDatabaseProfile(['wp_options', 'wp_wprism_map', 'wp_terms', 'wp_term_taxonomy'], ['wp_options'])
    );
    $fields->begin_authored_transaction();
    $writer->begin_authored_transaction();
    CacheInvalidationTransaction::begin();
    try {
        $writer->apply_options(OptionState::document([
            'woocommerce_enable_reviews' => OptionState::present('yes', 'yes'),
        ]), false, $warnings);
        wprism_check(count($wpdb->rows('wp_options')) === 2, 'the transaction has a real preceding authored write');
        $writer->apply_options(OptionState::document([
            'default_product_cat' => OptionState::present('{{term:' . $category . '}}', 'yes'),
        ]), false, $warnings);
        Db::commit('Woo default-category authored commit');
        $writer->commit_authored_transaction();
        CacheInvalidationTransaction::finish();
    } catch (CommandRefusalException $failure) {
        $caught = $failure;
        $writer->rollback_authored_transaction();
        Db::rollback('Woo default-category authored rollback');
    } finally {
        $writer->end_authored_transaction();
        $fields->end_authored_transaction();
        CacheInvalidationTransaction::end();
    }
    wprism_check_same($refuses, $caught !== null, 'actual option write agrees with the jointly representable target domain');
    wprism_check_same([], $warnings, 'a constraint is never downgraded to an option warning');
    wprism_check_same(null, $wpdb->activeTransactionIsolation(), 'the authored transaction returns idle');
    if ($refuses) {
        wprism_check_same('reference_intersection_failed', $caught?->reasonCode, 'the target refusal retains the reviewed category');
        wprism_check_same($before, $wpdb->rows('wp_options'), 'target refusal rolls back the complete option transaction');
    } else {
        $rows = array_column($wpdb->rows('wp_options'), null, 'option_name');
        wprism_check_same('73', $rows['default_product_cat']['option_value'], 'actual native option storage holds the jointly valid target-local integer');
        wprism_check_same('yes', $rows['default_product_cat']['autoload'], 'target materialization retains declared autoload');
        $locks = array_values(array_filter($wpdb->queries(), static fn(string $sql): bool =>
            str_contains($sql, 'FOR UPDATE') && (str_contains($sql, 'FROM wp_terms ') || str_contains($sql, 'FROM wp_term_taxonomy '))));
        wprism_check(count($locks) === 2 && str_contains($locks[0], 'FROM wp_terms ')
            && str_contains($locks[1], 'FROM wp_term_taxonomy ')
            && str_contains($locks[0], 'CONNECTION_ID()') && str_contains($locks[1], '@wprism_tx_session'),
            'physical term then TT rows are locked under exact connection/session authority before the option write');
    }
};
$apply([
    [$category, 'term', 73], [$category, 'term_taxonomy', 73],
    [$neighbor, 'term', 99], [$neighbor, 'term_taxonomy', 141],
], false);
foreach ([
    [[$category, 'term', 73], [$category, 'term_taxonomy', 99]],
    [[$category, 'term', 73]],
    [[$category, 'term_taxonomy', 73]],
    [[$category, 'term', 73], [$category, 'term_taxonomy', 99], [$neighbor, 'term_taxonomy', 73]],
] as $bindings) {
    $apply($bindings, true);
}

wprism_check_same('product_cat', $manifest['options']['default_product_cat']['ref_taxonomy'],
    'Woo declares its native semantic domain as policy data, without adding executable logic');
$sourceTuple = [[['term_id' => 41]], [['term_taxonomy_id' => 41, 'term_id' => 41, 'taxonomy' => 'product_cat']]];
wprism_check_throws(static fn() => $capture('41', [], false, false, $sourceTuple), CommandRefusalException::class,
    'ordinary capture never omits a physically valid but wholly unmanaged default');
[$unmapped, $warnings] = $capture('41', [], false, true, $sourceTuple);
wprism_check_same(['state' => 'absent'], $unmapped, 'strict lifecycle observation retains the existing wholly-unmapped transient projection');
wprism_check_same([], $warnings, 'the transient lifecycle projection is explicit and warning-free');
foreach ([
    [[], []],
    [[['term_id' => 41]], [['term_taxonomy_id' => 41, 'term_id' => 99, 'taxonomy' => 'product_cat']]],
    [[['term_id' => 41]], [['term_taxonomy_id' => 41, 'term_id' => 41, 'taxonomy' => 'category']]],
    [[['term_id' => 41], ['term_id' => 41]], [['term_taxonomy_id' => 41, 'term_id' => 41, 'taxonomy' => 'product_cat']]],
] as $physical) {
    wprism_check_throws(static fn() => $capture('41', [], false, true, $physical), CommandRefusalException::class,
        'strict lifecycle observation refuses missing, cross-wired, wrong-taxonomy, or ambiguous physical tuples');
    wprism_check_throws(static fn() => $capture('41', [[$category, 'term', 41], [$category, 'term_taxonomy', 41]], false, false, $physical),
        CommandRefusalException::class, 'coincident ledger integers cannot authorize contradictory native source rows');
}
foreach ([
    [[], []],
    [[['term_id' => 73]], [['term_taxonomy_id' => 73, 'term_id' => 99, 'taxonomy' => 'product_cat']]],
    [[['term_id' => 73]], [['term_taxonomy_id' => 73, 'term_id' => 73, 'taxonomy' => 'category']]],
] as $physical) {
    $apply([[$category, 'term', 73], [$category, 'term_taxonomy', 73]], true, $physical);
}

wprism_check_summary('WooCommerce default category');
