<?php
declare(strict_types=1);

// Export metadata and import values may occupy the same physical column.
// Applying one privacy role to both is not a faithful declaration of either.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/agent_version.php';
require_once __DIR__ . '/../policy/manifest_fixtures.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';
$root = dirname(__DIR__, 4);
wprism_test_define_agent_versions();
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/agent/src/Capture/TypedTableCapture.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Apply/TypedTableMaterializer.php';
require_once $root . '/agent/src/Review/Lint.php';

use WPrism\Canon;
use WPrism\ColumnCodecGrammar;
use WPrism\Policy;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

$labels = ['class' => 'authored', 'field_labels' => 'label_enabled'];
$literals = ['class' => 'authored', 'plain_data' => true];
$codec = ['container' => 'json', 'value_cases' => ['column' => 'mode', 'cases' => [
    ['equals' => 'export', 'value' => $labels], ['equals' => 'import', 'value' => $literals],
]]];
$manifest = ['name' => 'column-cases', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'engine_features' => ['column-field-labels/v1', 'column-value-cases/v1', 'json-column-codecs/v1',
        'spec-window/v1', 'table-row-scope-sets/v1', 'table-row-scopes/v1', 'typed-column-codecs/v1', 'typed-column-values/v1'],
    'tables' => ['authored_templates' => ['class' => 'authored_snapshot', 'pk' => 'id', 'id_kind' => 'auth_template',
        'slug_column' => 'name', 'identity' => ['mode' => 'mapped'],
        'row_scope' => ['item_type' => 'user', 'mode' => ['export', 'import']],
        'columns' => ['name' => ['class' => 'authored'], 'item_type' => ['class' => 'authored'],
            'mode' => ['class' => 'authored'], 'data' => ['class' => 'authored']], 'refs' => []]],
    'column_codecs' => ['authored_templates' => ['data' => $codec]]];
$scratch = sys_get_temp_dir() . '/wprism-column-cases-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700, true);
register_shutdown_function(static fn() => manifest_fixture_remove_tree($scratch));
$load = static function (array $input) use ($scratch): Policy {
    $dir = $scratch . '/library-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    Canon::write_file($dir . '/column-cases.json', Canon::encode($input));
    return Policy::load(null, ['column-cases'], adapterLibrary: manifest_fixture_adapter_library($dir));
};
$policy = $load($manifest);
wprism_check_same(Canon::encode(['data' => $codec]), Canon::encode($policy->column_codec_rules('authored_templates')),
    'real policy load admits bounded row-selected value contracts');


foreach (['column-value-cases/v1', 'typed-column-values/v1', 'table-row-scope-sets/v1', 'table-row-scopes/v1'] as $feature) {
    $bad = $manifest;
    $bad['engine_features'] = array_values(array_diff($bad['engine_features'], [$feature]));
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, "selector requires $feature", $feature);
}
$badSelectors = [
    'unknown selector key' => $codec['value_cases'] + ['default' => $labels],
    'missing selector' => ['cases' => $codec['value_cases']['cases']],
    'runtime selector' => ['column' => 'missing', 'cases' => $codec['value_cases']['cases']],
    'scalar scope selector' => ['column' => 'item_type', 'cases' => $codec['value_cases']['cases']],
    'missing case' => ['column' => 'mode', 'cases' => [$codec['value_cases']['cases'][0]]],
    'duplicate case' => ['column' => 'mode', 'cases' => array_fill(0, 2, $codec['value_cases']['cases'][0])],
    'unordered cases' => ['column' => 'mode', 'cases' => array_reverse($codec['value_cases']['cases'])],
    'foreign case' => ['column' => 'mode', 'cases' => [['equals' => 'export', 'value' => $labels], ['equals' => 'other', 'value' => $literals]]],
];
foreach ($badSelectors as $label => $selector) {
    $bad = $manifest;
    $bad['column_codecs']['authored_templates']['data']['value_cases'] = $selector;
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, $label);
}
foreach ([['class' => 'derived'], ['class' => 'authored', 'unknown' => true],
    ['class' => 'authored', 'ref' => 'undeclared_kind[]', 'on_unmapped' => 'refuse'],
    ['class' => 'authored', 'ref' => 'user[]']] as $invalidRule) {
    $bad = $manifest;
    $bad['column_codecs']['authored_templates']['data']['value_cases']['cases'][1]['value'] = $invalidRule;
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, 'unused case receives full contract and keyspace validation');
}
foreach (['leaves' => 'text', 'value' => $labels] as $key => $extra) {
    $bad = $manifest;
    $bad['column_codecs']['authored_templates']['data'][$key] = $extra;
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, 'selector cannot coexist with another value owner');
}
$bad = $manifest;
$bad['engine_features'][] = 'mixed-column-codecs/v1';
sort($bad['engine_features'], SORT_STRING);
$bad['column_codecs']['authored_templates']['data']['container'] = 'php_serialized_or_text';
wprism_check_throws(static fn() => $load($bad), RuntimeException::class, 'cases retain strict container framing', 'strict container');

// Numeric strings are valid scope values; using object keys for cases would
// turn these into integer keys (or a JSON list) in PHP's associative decoder.
$numeric = $manifest;
$numeric['tables']['authored_templates']['row_scope']['mode'] = ['0', '1'];
$numeric['column_codecs']['authored_templates']['data']['value_cases']['cases'][0]['equals'] = '0';
$numeric['column_codecs']['authored_templates']['data']['value_cases']['cases'][1]['equals'] = '1';
$numericCodec = $load($numeric)->column_codec_rules('authored_templates')['data'];
wprism_check_same($labels, WPrism\ColumnValueCases::resolve($numericCodec, ['mode' => '0'], 'data')['value'],
    'numeric string selector survives actual JSON policy loading');
foreach ([[], ['mode' => 0], ['mode' => null], ['mode' => false], ['mode' => 'EXPORT'], ['mode' => 'export '], ['mode' => 'private-discriminator']] as $row) {
    wprism_check_throws(static fn() => WPrism\ColumnValueCases::resolve($codec, $row, 'data'), RuntimeException::class,
        'absent, nonstring and foreign discriminators cannot select a default', 'no declared column value case');
}
$tokens = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
foreach (['capture_value', 'apply_value', 'decode_canonical_value', 'decode_for_clearance'] as $method) {
    $args = in_array($method, ['capture_value', 'apply_value'], true) ? ['[]', $codec, $tokens, 'data'] : ['[]', $codec, 'data'];
    wprism_check_throws(static fn() => ColumnCodecGrammar::$method(...$args), RuntimeException::class,
        'unselected codec never silently falls through to ordinary text', 'row selection');
}

WpStore::reset()->seedOptions(['home' => 'https://source.test']);
$wpdb = FakeWpdb::install()->enableInformationSchema()->enableJoinedCaptureSql()
    ->setPrimaryKey('authored_templates', 'id')->setAutoIncrement('authored_templates', 800, 'id')
    ->setColumns('authored_templates', ['id' => 'int(11)', 'name' => 'varchar(255)', 'data' => 'longtext',
        'item_type' => 'varchar(32)', 'mode' => 'varchar(32)'])->setTableEngine('authored_templates', 'InnoDB');
$uuids = ['export' => '11111111-1111-4111-8111-111111111111', 'import' => '22222222-2222-4222-8222-222222222222'];
$nativeLabels = json_encode(['first_name' => ['First name', 1]], JSON_THROW_ON_ERROR);
$nativeLiteral = json_encode(['url' => 'https://source.test/input'], JSON_THROW_ON_ERROR);
$foreign = ['id' => 9, 'name' => 'Foreign', 'item_type' => 'product', 'mode' => 'import', 'data' => '{broken'];
$source = [
    ['id' => 2, 'name' => 'Export', 'item_type' => 'user', 'mode' => 'export', 'data' => $nativeLabels],
    ['id' => 3, 'name' => 'Import', 'item_type' => 'user', 'mode' => 'import', 'data' => $nativeLiteral],
    $foreign,
];
$wpdb->seedTable('authored_templates', $source);
$decl = $manifest['tables']['authored_templates'];
$identity = new class($uuids) {
    public function __construct(private array $uuids) {}
    public function identifyRow(string $table, array $decl, array $row, int $id): string { return $this->uuids[$row['mode']]; }
};
$capture = new WPrism\TypedTableCapture($identity, static function (): void {}, static fn() => null, static fn($v) => strtolower($v));
$captureRows = static fn(Tokens $input): array => $capture->capture_table('authored_templates', $decl, [], $input,
    true, false, $policy->column_codec_rules('authored_templates'));
$entities = $captureRows($tokens);
wprism_check_same(2, count($entities), 'real Capture selects both owned variants and excludes malformed foreign rows');
wprism_check_same($nativeLabels, Canon::decode($entities[0]['content'])['columns']['data'], 'export retains label metadata');
wprism_check_same('{"url":"{{home}}\/input"}', Canon::decode($entities[1]['content'])['columns']['data'], 'import uses ordinary authored text transport');
$repo = $scratch . '/repository';
$compile = static function (array $inputs, array $inputManifest) use ($repo): array {
    manifest_fixture_remove_tree($repo);
    $site = WPrismTest\FrozenPolicy::site([$inputManifest], 3);
    $frozen = WPrismTest\FrozenPolicy::policy([$inputManifest], $site);
    Canon::write_file($repo . '/site.wprism.json', Canon::encode($site));
    foreach ($inputs as $entity) Canon::write_file($repo . '/state/' . $entity['path'], $entity['content']);
    return WPrism\RepositoryCompiler::compile($repo, $frozen)->tree();
};
$tree = $compile($entities, $manifest);
wprism_check_same(2, count($tree), 'compiler authorizes each variant under its own value contract');
wprism_check_same([], WPrism\Lint::scan_tree($repo . '/state', $policy), 'selected portable values lint cleanly');
$edit = static function (array $entity, array $columns): array {
    $front = Canon::decode($entity['content']);
    $front['columns'] = array_replace($front['columns'], $columns);
    $entity['content'] = Canon::encode($front);
    $entity['data'] = $front;
    return $entity;
};
$borrowed = $edit($entities[0], ['mode' => 'import']);
wprism_check_throws(static fn() => $compile([$borrowed], $manifest), RuntimeException::class,
    'edited import cannot borrow the export field-label privacy role', 'repository_pii_not_allowed');
$wrongShape = $edit($entities[0], ['data' => '{"url":"plain"}']);
wprism_check_throws(static fn() => $compile([$wrongShape], $manifest), RuntimeException::class,
    'selected export contract refuses the import shape', 'schema_content_mismatch');
wprism_check_same(['invalid_column_value'], array_column(WPrism\Lint::scan_tree($repo . '/state', $policy), 'class'),
    'lint uses the same row-selected contract');
foreach (['OTHER', null, 0] as $invalid) {
    $changed = $edit($entities[0], ['mode' => $invalid]);
    wprism_check_throws(static fn() => $compile([$changed], $manifest), RuntimeException::class,
        'immutable compilation refuses unknown or mistyped selectors');
}
$privateRows = $source;
$privateRows[0]['mode'] = 'import';
$wpdb->seedTable('authored_templates', $privateRows);
wprism_check_throws(static fn() => $captureRows($tokens), RuntimeException::class,
    'native import cannot borrow label metadata clearance', 'PII guard');
$wpdb->seedTable('authored_templates', [$foreign]);
$targetTokens = new Tokens('https://target.test', 'https://target.test/wp-content/uploads');
$mapping = [];
$writer = new WPrism\TypedTableMaterializer(static fn() => ['authored_templates' => $decl], static fn() => [],
    static function (string $uuid) use (&$mapping): ?int { return $mapping[$uuid] ?? null; },
    static function (string $uuid, string $table, string $kind, int $id) use (&$mapping): void { $mapping[$uuid] = $id; },
    static fn() => 0, static fn() => [], static fn() => false, static fn($v) => $v, static function (): void {},
    static fn() => $policy->column_codec_rules('authored_templates'));
$transaction = static function (callable $action) use (&$mapping): mixed {
    $before = $mapping;
    WPrism\Db::start_repeatable_read('column cases', new WPrism\NativeDatabaseProfile(['wp_authored_templates'], ['wp_authored_templates']));
    try {
        $result = $action();
        WPrism\Db::commit('column cases');
        return $result;
    } catch (Throwable $failure) {
        WPrism\Db::rollback_after_failure($failure, 'column cases');
        $mapping = $before;
        throw $failure;
    }
};
// Check the first materialization phase directly, before any rollback could
// conceal a transient insert or published mapping from an invalid value.
$before = $wpdb->rows('authored_templates');
wprism_check_throws(static fn() => $writer->ensureRow($wrongShape), RuntimeException::class,
    'phase one validates the selected contract before inserting', 'field labels');
wprism_check_same($before, $wpdb->rows('authored_templates'), 'invalid phase one has no physical write');
wprism_check_same([], $mapping, 'invalid phase one publishes no identity');
$apply = static function () use ($writer, $tree, $targetTokens): void {
    foreach ($tree as $entity) { $writer->ensureRow($entity); $writer->finalizeRow($targetTokens, $entity); }
};
$transaction($apply);
$target = $wpdb->rows('authored_templates');
wprism_check_same($foreign, $target[0], 'Apply preserves the foreign row byte for byte');
wprism_check_same($nativeLabels, $target[1]['data'], 'Apply restores the selected label shape');
wprism_check_same(json_encode(['url' => 'https://target.test/input']), $target[2]['data'], 'Apply rewrites the selected plain-data value');
wprism_check_same(array_column($entities, 'content'), array_column($captureRows($targetTokens), 'content'), 'both complete row variants recapture to fixed points');
$transaction($apply);
wprism_check_same($target, $wpdb->rows('authored_templates'), 'repeated selected Apply retains rows and identities');
$swapped = $edit($entities[0], ['mode' => 'import', 'data' => '{"url":"{{home}}\\/switched"}']);
$transaction(static function () use ($writer, $swapped, $targetTokens): void { $writer->ensureRow($swapped); $writer->finalizeRow($targetTokens, $swapped); });
wprism_check_same('import', $wpdb->rows('authored_templates')[1]['mode'], 'Apply uses the authored discriminator when an owned row changes variant');
wprism_check_same(json_encode(['url' => 'https://target.test/switched']), $wpdb->rows('authored_templates')[1]['data'], 'variant transition applies the new contract in the same row write');
wprism_check_throws(static function () use ($transaction, $apply): void {
    $transaction(static function () use ($apply): void { $apply(); throw new RuntimeException('later failure'); });
}, RuntimeException::class, 'selected writes preserve transaction rollback', 'later failure');
wprism_check_same('import', $wpdb->rows('authored_templates')[1]['mode'], 'rollback restores the prior variant and payload together');


// Reference selection must reach immutable compilation and environment lint,
// while all branches retain their own independent keyspace validation.
$references = $manifest;
$references['column_codecs']['authored_templates']['data']['value_cases']['cases'][1]['value'] = [
    'class' => 'authored', 'ref' => 'user[]', 'cast' => 'string', 'on_unmapped' => 'refuse'];
$referencePolicy = $load($references);
$referenceRows = $source;
$referenceRows[1]['data'] = '["2"]';
$wpdb->seedTable('authored_templates', $referenceRows)->seedTable('wp_users', [['ID' => 2, 'user_login' => 'reader']]);
$referenceEntities = $capture->capture_table('authored_templates', $decl, [], $tokens, true, false,
    $referencePolicy->column_codec_rules('authored_templates'));
wprism_check_same('["user:reader"]', Canon::decode($referenceEntities[1]['content'])['columns']['data'],
    'Capture selects the declared user reference contract');
wprism_check_same(2, count($compile($referenceEntities, $references)), 'selected user references compile without resolving the environment');
wprism_check_same([], WPrism\Lint::scan_tree($repo . '/state', $referencePolicy), 'selected resolvable references lint cleanly');
$referenceEntities[1] = $edit($referenceEntities[1], ['data' => '["999"]']);
wprism_check_throws(static fn() => $compile($referenceEntities, $references), RuntimeException::class,
    'selected canonical reference contracts reject local IDs');
wprism_check_same(['invalid_column_value'], array_column(WPrism\Lint::scan_tree($repo . '/state', $referencePolicy), 'class'),
    'lint traverses references inside the selected arm');
foreach (['json', 'php_serialized'] as $container) {
    $variant = $references;
    $variant['column_codecs']['authored_templates']['data']['container'] = $container;
    $selected = WPrism\ColumnValueCases::resolve($load($variant)->column_codec_rules('authored_templates')['data'], ['mode' => 'import'], 'data');
    $encode = static fn($value) => $container === 'json' ? json_encode($value, JSON_THROW_ON_ERROR) : serialize($value);
    $canonical = ColumnCodecGrammar::capture_value($encode(['2']), $selected, new Tokens('https://source.test', 'https://source.test/uploads'), 'data');
    $wpdb->seedTable('wp_users', [['ID' => 82, 'user_login' => 'reader']]);
    wprism_check_same($encode(['82']), ColumnCodecGrammar::apply_value($canonical, $selected,
        new Tokens('https://target.test', 'https://target.test/uploads'), 'data'), "selected $container references retain native string casts at divergent IDs");
    $wpdb->seedTable('wp_users', [['ID' => 2, 'user_login' => 'reader']]);
}
$budget = $manifest;
$wide = [];
for ($i = 0; $i < 256; ++$i) $wide['field' . $i] = ['class' => 'authored', 'enum' => ['yes', 'no']];
$deep = [];
for ($i = 0; $i < 128; ++$i) $deep['record' . $i] = ['class' => 'authored', 'object_fields' => $wide];
$budget['column_codecs']['authored_templates']['data']['value_cases']['cases'] = [
    ['equals' => 'export', 'value' => ['class' => 'authored', 'object_fields' => $deep]],
    ['equals' => 'import', 'value' => ['class' => 'authored', 'object_fields' => $deep]],
];
wprism_check_throws(static fn() => ColumnCodecGrammar::validate_column_codecs($budget, 'budget probe'), RuntimeException::class,
    'all cases share the existing manifest-wide rule budget', 'bounded column value contracts');

wprism_check_summary('column value cases');
