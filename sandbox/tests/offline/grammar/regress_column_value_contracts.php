<?php
declare(strict_types=1);

// Native importer templates encode user ID lists inside JSON. A text-only
// container preserves those local integers; an explicit value contract must
// use the existing user-login reference codec and refuse absent bindings.
$root = dirname(__DIR__, 4);
if (($argv[1] ?? '') === '--compile-without-wordpress') {
    require_once __DIR__ . '/../../lib/agent_version.php';
    require_once __DIR__ . '/../../lib/frozen_policy.php';
    require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
    wprism_test_define_agent_versions();
    $input = json_decode(file_get_contents($argv[2] . '/probe.json'), true, flags: JSON_THROW_ON_ERROR);
    $pinned = WPrismTest\FrozenPolicy::policy([$input['manifest']], $input['site']);
    $compiled = WPrism\RepositoryCompiler::compile($argv[2], $pinned);
    echo json_encode(['entities' => count($compiled->tree()), 'wordpress' => function_exists('get_option'),
        'database' => isset($GLOBALS['wpdb'])], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/agent_version.php';
require_once __DIR__ . '/../policy/manifest_fixtures.php';
$root = dirname(__DIR__, 4);
wprism_test_define_agent_versions();
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Grammar/ColumnCodecGrammar.php';
require_once $root . '/agent/src/Capture/TypedTableCapture.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Apply/TypedTableMaterializer.php';
require_once $root . '/agent/src/Review/Lint.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';

use WPrism\Canon;
use WPrism\ColumnCodecGrammar;
use WPrism\Policy;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

$valueRule = ['class' => 'authored', 'object_fields' => [
    'method_export_form_data' => ['class' => 'authored', 'plain_data' => true,
        'record_fields' => ['container' => 'object', 'fields' => ['method_export', 'mapping_enabled_fields']]],
    'label_fields' => ['class' => 'authored', 'field_labels' => 'label_enabled'],
    'selected_labels' => ['class' => 'authored', 'field_labels' => 'label'],
    'filter_form_data' => ['class' => 'authored', 'object_fields' => [
        'wt_iew_email' => ['class' => 'authored', 'ref' => 'user[]', 'cast' => 'string', 'on_unmapped' => 'refuse'],
    ]],
    'advanced_form_data' => ['class' => 'authored', 'object_fields' => [
        'wt_iew_limit' => ['class' => 'authored', 'plain_data' => true],
    ]],
]];
$codec = ['container' => 'json', 'value' => $valueRule];
$manifest = ['name' => 'column-values', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'engine_features' => ['column-field-labels/v1', 'column-record-fields/v1', 'json-column-codecs/v1', 'spec-window/v1', 'typed-column-codecs/v1', 'typed-column-values/v1'],
    'tables' => ['authored_templates' => ['class' => 'authored_snapshot', 'pk' => 'id', 'id_kind' => 'auth_template',
        'slug_column' => 'name', 'identity' => ['mode' => 'mapped'],
        'columns' => ['name' => ['class' => 'authored'], 'data' => ['class' => 'authored']], 'refs' => []]],
    'column_codecs' => ['authored_templates' => ['data' => $codec]]];
$scratch = sys_get_temp_dir() . '/wprism-column-values-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700, true);
register_shutdown_function(static fn() => manifest_fixture_remove_tree($scratch));
$load = static function (array $input) use ($scratch): Policy {
    $dir = $scratch . '/library-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    Canon::write_file($dir . '/column-values.json', Canon::encode($input));
    return Policy::load(null, ['column-values'], adapterLibrary: manifest_fixture_adapter_library($dir));
};
$policy = $load($manifest);
wprism_check_same(Canon::encode(['data' => $codec]), Canon::encode($policy->column_codec_rules('authored_templates')),
    'real policy loader admits the explicit typed value alternative');
$withoutFeature = $manifest;
array_pop($withoutFeature['engine_features']);
wprism_check_throws(static fn() => $load($withoutFeature), RuntimeException::class,
    'typed value rules cannot borrow framing authority', 'typed-column-values/v1');
$ambiguous = $manifest;
$ambiguous['column_codecs']['authored_templates']['data']['leaves'] = 'text';
wprism_check_throws(static fn() => $load($ambiguous), RuntimeException::class,
    'a container cannot declare both ordinary leaves and an explicit value owner');

WpStore::reset()->seedOptions(['home' => 'https://source.test']);
FakeWpdb::install()->seedTable('wp_users', [
    ['ID' => 2, 'user_login' => 'reader'], ['ID' => 3, 'user_login' => 'editor'],
]);
$sourceTokens = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
$labelFields = ['user_email' => ['Email address', 1], 'display_name' => ['Display name', 0],
    'first_name' => ['First name', 1], 'last_name' => ['Last name', 0], 'nickname' => ['Nickname', 0],
    'user_pass' => ['user_pass', 0], 'session_tokens' => ['session_tokens', 0], 'বাংলা' => ['', 1]];
$selectedLabels = ['user_email' => 'Email address', 'display_name' => 'Display name'];
$native = json_encode(['filter_form_data' => ['wt_iew_email' => ['2', '3']],
    'method_export_form_data' => ['method_export' => 'template', 'selected_template' => '1'],
    'advanced_form_data' => ['wt_iew_limit' => 25], 'label_fields' => $labelFields, 'selected_labels' => $selectedLabels], JSON_THROW_ON_ERROR);
$expected = json_encode(['filter_form_data' => ['wt_iew_email' => ['user:reader', 'user:editor']],
    'method_export_form_data' => ['method_export' => 'template'],
    'advanced_form_data' => ['wt_iew_limit' => 25], 'label_fields' => $labelFields, 'selected_labels' => $selectedLabels], JSON_THROW_ON_ERROR);
$captured = ColumnCodecGrammar::capture_value($native, $codec, $sourceTokens, 'template data', 'data');
wprism_check_same($expected, $captured, 'declared nested user IDs become user-login bindings without a column-wide PII exemption');
FakeWpdb::install()->seedTable('wp_users', [
    ['ID' => 82, 'user_login' => 'reader'], ['ID' => 93, 'user_login' => 'editor'],
]);
$targetTokens = new Tokens('https://target.test', 'https://target.test/wp-content/uploads');
$target = ColumnCodecGrammar::apply_value($captured, $codec, $targetTokens, 'template data');
wprism_check_same(['filter_form_data' => ['wt_iew_email' => ['82', '93']],
    'method_export_form_data' => ['method_export' => 'template'],
    'advanced_form_data' => ['wt_iew_limit' => 25], 'label_fields' => $labelFields, 'selected_labels' => $selectedLabels], json_decode($target, true, flags: JSON_THROW_ON_ERROR),
    'nested user bindings retain native string type at divergent target IDs');
wprism_check_same($captured, ColumnCodecGrammar::capture_value($target, $codec, $targetTokens, 'template data', 'data'),
    'typed JSON values reach a canonical fixed point');


// Capability ownership and closed shape are checked by the real manifest loader.
$leaf = ['class' => 'authored', 'ref' => 'user[]', 'cast' => 'string', 'on_unmapped' => 'refuse'];
$badRules = [
    'derived column root' => ['class' => 'derived'],
    'unknown contract field' => $valueRule + ['invented' => true],
    'missing strict reference policy' => ['class' => 'authored', 'ref' => 'user[]'],
    'unsupported reference fallback' => array_replace($leaf, ['on_unmapped' => 'drop']),
    'unregistered reference keyspace' => array_replace($leaf, ['ref' => 'missing_kind[]']),
    'nested unregistered reference keyspace' => ['class' => 'authored', 'object_fields' => [
        'chosen' => array_replace($leaf, ['ref' => 'missing_kind[]'])]],
    'structured users have no durable token grammar' => ['class' => 'authored', 'json_refs' => [['path' => '$.id', 'kind' => 'user']], 'on_unmapped' => 'refuse'],
    'malformed record projection' => ['class' => 'authored', 'plain_data' => true, 'record_fields' => []],
    'column cannot borrow encoded text authority' => ['class' => 'authored', 'text_encoding' => 'html_entities'],
    'enum has exact types and bounded codes' => ['class' => 'authored', 'enum' => ['https://site.test']],
    'object cannot include a second codec' => $valueRule + ['plain_data' => true],
    'object field names must be exact' => ['class' => 'authored', 'object_fields' => ['a.b' => $leaf]],
];
$deep = $leaf;
for ($i = 0; $i < 5; ++$i) $deep = ['class' => 'authored', 'object_fields' => ['child' => $deep]];
$badRules['depth is bounded'] = $deep;
$fields = [];
for ($i = 0; $i < 257; ++$i) $fields['f' . $i] = $leaf;
$badRules['field count is bounded'] = ['class' => 'authored', 'object_fields' => $fields];
foreach ($badRules as $label => $badRule) {
    $badManifest = $manifest;
    $badManifest['column_codecs']['authored_templates']['data']['value'] = $badRule;
    wprism_check_throws(static fn() => $load($badManifest), RuntimeException::class, $label);
}
$mixed = $manifest;
$mixed['engine_features'][] = 'mixed-column-codecs/v1';
sort($mixed['engine_features'], SORT_STRING);
$mixed['column_codecs']['authored_templates']['data']['container'] = 'php_serialized_or_text';
wprism_check_throws(static fn() => $load($mixed), RuntimeException::class,
    'mixed scalar framing cannot bypass typed value validation', 'strict container');
$serialized = $manifest;
$serialized['column_codecs']['authored_templates']['data']['container'] = 'php_serialized';
wprism_check($load($serialized) instanceof Policy, 'strict PHP serialization shares the explicit value grammar');
$serializedCodec = $serialized['column_codecs']['authored_templates']['data'];
$targetDecoded = json_decode($target, true);
$serializedCaptured = ColumnCodecGrammar::capture_value(serialize($targetDecoded), $serializedCodec, $targetTokens, 'serialized typed data');
wprism_check_same(serialize(json_decode($expected, true)), $serializedCaptured, 'serialized typed references recompute string lengths');
wprism_check_same(serialize($targetDecoded), ColumnCodecGrammar::apply_value($serializedCaptured, $serializedCodec, $targetTokens, 'serialized typed data'),
    'serialized typed references retain string IDs on Apply');

foreach ([['0'], [0], [82], ['082'], ['-82'], [null], ['9223372036854775808'], ['reader@example.test'], ['82', '0']] as $invalidIds) {
    $invalid = json_encode(['filter_form_data' => ['wt_iew_email' => $invalidIds]], JSON_THROW_ON_ERROR);
    wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value($invalid, $codec, $targetTokens, 'typed data'),
        RuntimeException::class, 'invalid user list fails before reference PII projection', 'positive reference');
}
$unknown = json_encode(['filter_form_data' => ['wt_iew_email' => ['82'], 'unknown' => 'reader@example.test']], JSON_THROW_ON_ERROR);
wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value($unknown, $codec, $targetTokens, 'typed data'),
    RuntimeException::class, 'unknown siblings cannot disappear during PII projection', 'only declared fields');
$missing = json_encode(['filter_form_data' => ['wt_iew_email' => ['999']]], JSON_THROW_ON_ERROR);
wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value($missing, $codec, $targetTokens, 'typed data'),
    RuntimeException::class, 'missing source users refuse the complete value', 'unmapped user');
$missingCanonical = json_encode(['filter_form_data' => ['wt_iew_email' => ['user:absent']]], JSON_THROW_ON_ERROR);
wprism_check_throws(static fn() => ColumnCodecGrammar::apply_value($missingCanonical, $codec, $targetTokens, 'typed data'),
    RuntimeException::class, 'missing target users never fall back to the administrator', 'strict user reference');

// Real immutable compilation must permit references while rejecting edited
// private siblings; it must not need source or target users to do either.
$uuid = '11111111-1111-4111-8111-111111111111';
$path = "tables/authored_templates/$uuid--selected.json";
$repo = $scratch . '/repository';
$compile = static function (array $input, string $value) use ($repo, $uuid, $path): array {
    $site = WPrismTest\FrozenPolicy::site([$input], 3);
    $pinned = WPrismTest\FrozenPolicy::policy([$input], $site);
    Canon::write_file($repo . '/site.wprism.json', Canon::encode($site));
    Canon::write_file($repo . '/state/' . $path, Canon::encode(['uuid' => $uuid, 'table' => 'authored_templates',
        'meta' => (object) [], 'columns' => ['name' => 'Selected', 'data' => $value]]));
    return WPrism\RepositoryCompiler::compile($repo, $pinned)->tree();
};
wprism_check_same(1, count($compile($manifest, $expected)), 'real compiler admits declared user references under a PII-shaped field');
wprism_check_same(1, count($compile($manifest, $missingCanonical)), 'binding existence remains environment-owned, not compiler-owned');
foreach ([$native, '{broken', json_encode(['filter_form_data' => ['wt_iew_email' => ['user:']]]), $unknown] as $invalid) {
    wprism_check_throws(static fn() => $compile($manifest, $invalid), RuntimeException::class,
        'compiler rejects malformed framing, reference shape and unknown fields', 'schema_content_mismatch');
}
$privacyManifest = $manifest;
$privacyManifest['column_codecs']['authored_templates']['data']['value']['object_fields']['notes'] = ['class' => 'authored', 'plain_data' => true];
$privacyCodec = $privacyManifest['column_codecs']['authored_templates']['data'];
foreach ([['email' => 'reader@example.test'], ['email' => ['value' => 'reader']], ['api_key' => 'private-credential-material']] as $private) {
    $privateNative = json_encode(['filter_form_data' => ['wt_iew_email' => ['82']], 'notes' => $private], JSON_THROW_ON_ERROR);
    $privateCanonical = str_replace('"82"', '"user:reader"', $privateNative);
    wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value($privateNative, $privacyCodec, $targetTokens, 'typed data'),
        RuntimeException::class, 'typed reference clearance preserves private sibling roles');
    wprism_check_throws(static fn() => $compile($privacyManifest, $privateCanonical), RuntimeException::class,
        'compiled edits repeat private sibling clearance');
}
$secretManifest = $manifest;
$secretManifest['column_codecs']['authored_templates']['data']['value'] = ['class' => 'authored', 'object_fields' => ['AKIAIOSFODNN7EXAMPLE' => $leaf]];
$secretNative = json_encode(['AKIAIOSFODNN7EXAMPLE' => ['82']], JSON_THROW_ON_ERROR);
wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value($secretNative,
    $secretManifest['column_codecs']['authored_templates']['data'], $targetTokens, 'typed data'),
    RuntimeException::class, 'validated reference leaves still participate in full secret clearance', 'secret guard');
wprism_check_throws(static fn() => $compile($secretManifest, str_replace('"82"', '"user:reader"', $secretNative)),
    RuntimeException::class, 'compiler never removes reference leaves from secret clearance', 'repository_secret_not_allowed');
$referenceOnly = ['container' => 'json', 'value' => $leaf];
wprism_check_same('["user:reader"]', ColumnCodecGrammar::capture_value('["82"]', $referenceOnly, $targetTokens,
    'reference column', 'email'), 'root reference list has no residual PII subject');

// Durable references use the existing canonical graph, including deletion
// closure; a new framing surface does not grant dangling entity authority.
$durable = $manifest;
$durable['column_codecs']['authored_templates']['data']['value'] = ['class' => 'authored', 'object_fields' => [
    'selected' => ['class' => 'authored', 'ref' => 'auth_template[]', 'on_unmapped' => 'refuse']]];
$load($durable);
$durableValue = json_encode(['selected' => ['{{auth_template:' . $uuid . '}}']], JSON_THROW_ON_ERROR);
wprism_check_same(1, count($compile($durable, $durableValue)), 'typed column durable references participate in the existing graph');
$dangling = str_replace($uuid, '22222222-2222-4222-8222-222222222222', $durableValue);
wprism_check_throws(static fn() => $compile($durable, $dangling), RuntimeException::class,
    'typed column cannot conceal a reference to an absent canonical entity');

$compile($manifest, $expected);
$site = WPrismTest\FrozenPolicy::site([$manifest], 3);
Canon::write_file($repo . '/probe.json', Canon::encode(['manifest' => $manifest, 'site' => $site]));
$child = proc_open([PHP_BINARY, __FILE__, '--compile-without-wordpress', $repo],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($child)) throw new RuntimeException('could not start isolated typed value compiler');
fclose($pipes[0]);
$childOut = stream_get_contents($pipes[1]);
$childErr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
wprism_check_same(0, proc_close($child), 'typed column compiles in an isolated process');
wprism_check_same('', $childErr, 'isolated typed column compilation emits no diagnostics');
wprism_check_same(['entities' => 1, 'wordpress' => false, 'database' => false], json_decode($childOut, true),
    'typed column validation requires neither WordPress nor a database');
// The complete row path combines ownership, typed user bindings, compiler
// authorization, new target identities, rollback and foreign preservation.
$scoped = $manifest;
$scoped['engine_features'][] = 'table-row-scopes/v1';
sort($scoped['engine_features'], SORT_STRING);
$scoped['tables']['authored_templates']['row_scope'] = ['item_type' => 'user'];
$scoped['tables']['authored_templates']['columns']['item_type'] = ['class' => 'authored'];
$scoped['tables']['authored_templates']['columns']['hits'] = ['class' => 'runtime'];
$scopedPolicy = $load($scoped);
$decl = $scopedPolicy->declared_tables()['authored_templates'];
$wpdb = FakeWpdb::install()->enableInformationSchema()->enableJoinedCaptureSql()
    ->setPrimaryKey('authored_templates', 'id')->setAutoIncrement('authored_templates', 800, 'id')
    ->setColumns('authored_templates', ['id' => 'int(11)', 'name' => 'varchar(255)', 'data' => 'longtext',
        'item_type' => 'varchar(32)', 'hits' => 'int(11)'])->setTableEngine('authored_templates', 'InnoDB');
$users = [['ID' => 2, 'user_login' => 'reader'], ['ID' => 3, 'user_login' => 'editor']];
$wpdb->seedTable('wp_users', $users)->setTableEngine('wp_users', 'InnoDB');
$foreign = [['id' => 3, 'name' => 'Foreign', 'item_type' => 'product', 'data' => '{broken', 'hits' => 91]];
$wpdb->seedTable('authored_templates', [['id' => 2, 'name' => 'Selected', 'item_type' => 'user', 'data' => $native, 'hits' => 42], ...$foreign]);
$identity = new class($uuid) {
    public function __construct(private string $uuid) {}
    public function identifyRow(string $table, array $decl, array $row, int $id): string { return $this->uuid; }
};
$capture = new WPrism\TypedTableCapture($identity, static function (): void {}, static fn() => null,
    static fn(string $name): string => strtolower($name));
$captureRows = static fn(Tokens $tokens): array => $capture->capture_table(
    'authored_templates', $decl, [], $tokens, true, false, $scopedPolicy->column_codec_rules('authored_templates'));
$entities = $captureRows(new Tokens('https://source.test', 'https://source.test/wp-content/uploads'));
wprism_check_same(1, count($entities), 'scoped Capture excludes foreign malformed payloads before decoding');
wprism_check_same($expected, Canon::decode($entities[0]['content'])['columns']['data'], 'real row Capture records portable user bindings');
$scopedSite = WPrismTest\FrozenPolicy::site([$scoped], 3);
$scopedPolicy = WPrismTest\FrozenPolicy::policy([$scoped], $scopedSite);
Canon::write_file($repo . '/site.wprism.json', Canon::encode($scopedSite));
Canon::write_file($repo . '/state/' . $entities[0]['path'], $entities[0]['content']);
$tree = WPrism\RepositoryCompiler::compile($repo, $scopedPolicy)->tree();
wprism_check_same(1, count($tree), 'actual scoped Capture output compiles');
$entity = $tree[$uuid];
wprism_check_same([], WPrism\Lint::scan_tree($repo . '/state', $scopedPolicy), 'typed counts and portable user bindings produce no false lint findings');
$edited = $entity['data'];
$edited['columns']['data'] = $native;
Canon::write_file($repo . '/state/' . $entity['path'], Canon::encode($edited));
$lint = WPrism\Lint::scan_tree($repo . '/state', $scopedPolicy);
wprism_check_same(['invalid_column_value'], array_column($lint, 'class'), 'real lint sees raw IDs inside an edited JSON container');
wprism_check_same(['columns.data'], array_column($lint, 'locator'), 'typed column lint points to the framing owner');
Canon::write_file($repo . '/state/' . $entity['path'], $entity['content']);
$targetUsers = [['ID' => 82, 'user_login' => 'reader'], ['ID' => 93, 'user_login' => 'editor']];
$wpdb->seedTable('wp_users', $targetUsers)->seedTable('authored_templates', $foreign);
$targetTokens = new Tokens('https://target.test', 'https://target.test/wp-content/uploads');
$mappedId = null;
$writer = new WPrism\TypedTableMaterializer(static fn() => ['authored_templates' => $decl], static fn() => [],
    static function () use (&$mappedId): ?int { return $mappedId; },
    static function (string $uuid, string $table, string $kind, int $id) use (&$mappedId): void { $mappedId = $id; },
    static fn() => 0, static fn() => [], static fn() => false, static fn($value) => $value,
    static function (): void {}, static fn() => $scopedPolicy->column_codec_rules('authored_templates'));
$transaction = static function (callable $write) use (&$mappedId): mixed {
    $mapBefore = $mappedId;
    WPrism\Db::start_repeatable_read('typed column fixture', new WPrism\NativeDatabaseProfile(['wp_authored_templates', 'wp_users'], ['wp_authored_templates']));
    try {
        $result = $write();
        WPrism\Db::commit('typed column fixture');
        return $result;
    } catch (Throwable $failure) {
        WPrism\Db::rollback_after_failure($failure, 'typed column fixture');
        $mappedId = $mapBefore;
        throw $failure;
    }
};
$transaction(static function () use ($writer, $entity, $targetTokens): void {
    wprism_check($writer->ensureRow($entity), 'Apply creates a distinct target row');
    $writer->finalizeRow($targetTokens, $entity);
});
wprism_check_same($target, $wpdb->rows('authored_templates')[1]['data'], 'checked materializer stores divergent native user IDs with exact string casts');
wprism_check_same($targetUsers, $wpdb->rows('wp_users'), 'materialization never migrates or edits users');
wprism_check_same($foreign, array_slice($wpdb->rows('authored_templates'), 0, 1), 'Apply preserves every foreign payload byte');
wprism_check_same($entities[0]['content'], $captureRows($targetTokens)[0]['content'], 'complete canonical recapture is a fixed point');
$transaction(static function () use ($writer, $entity, $targetTokens): void {
    wprism_check(!$writer->ensureRow($entity), 'repeated Apply retains the same target identity');
    $writer->finalizeRow($targetTokens, $entity);
});
$beforeFailure = $wpdb->rows('authored_templates');
$changed = $entity;
$changed['data']['columns']['data'] = json_encode(['filter_form_data' => ['wt_iew_email' => ['user:editor']]], JSON_THROW_ON_ERROR);
$observed = false;
wprism_check_throws(static function () use ($transaction, $writer, $changed, $targetTokens, $wpdb, &$observed): void {
    $transaction(static function () use ($writer, $changed, $targetTokens, $wpdb, &$observed): void {
        $writer->finalizeRow($targetTokens, $changed);
        $observed = json_decode($wpdb->rows('authored_templates')[1]['data'], true)['filter_form_data']['wt_iew_email'] === ['93'];
        throw new RuntimeException('injected later typed column failure');
    });
}, RuntimeException::class, 'later failure rolls back the rewritten row', 'injected later typed column failure');
wprism_check($observed, 'rollback probe reaches the rewritten native reference');
wprism_check_same($beforeFailure, $wpdb->rows('authored_templates'), 'rollback restores the entire physical table');
$changed['data']['columns']['data'] = $missingCanonical;
wprism_check_throws(static fn() => $transaction(static fn() => $writer->finalizeRow($targetTokens, $changed)),
    RuntimeException::class, 'missing target binding aborts checked materialization', 'strict user reference');
wprism_check_same($beforeFailure, $wpdb->rows('authored_templates'), 'missing binding preserves every native row');
$changed['data']['columns']['data'] = $native;
wprism_check_throws(static fn() => $transaction(static fn() => $writer->finalizeRow($targetTokens, $changed)),
    RuntimeException::class, 'materialization independently refuses raw source IDs', 'declared reference keyspace');
wprism_check_same($beforeFailure, $wpdb->rows('authored_templates'), 'invalid canonical references preserve every native row');
$drifted = $beforeFailure;
$drifted[1]['item_type'] = 'product';
$wpdb->seedTable('authored_templates', $drifted);
wprism_check_throws(static fn() => $transaction(static fn() => $writer->finalizeRow($targetTokens, $entity)),
    RuntimeException::class, 'late ownership drift blocks nested reference writes', 'row_scope');
wprism_check_same($drifted, $wpdb->rows('authored_templates'), 'ownership refusal preserves all now-foreign data');
$wpdb->seedTable('authored_templates', $beforeFailure);
$transaction(static fn() => $writer->deleteLocalRow('authored_templates', $mappedId));
wprism_check_same($foreign, $wpdb->rows('authored_templates'), 'typed row deletion preserves foreign malformed payloads');
wprism_check_same($targetUsers, $wpdb->rows('wp_users'), 'deleting a reference owner never deletes its user bindings');


// Scalar/list casts, composed literals and structured value/key references all
// use the same runtime machinery after either declared container is opened.
$wpdb->seedTable('wp_wprism_map', [
    ['uuid' => $uuid, 'id_kind' => 'auth_template', 'local_id' => 211, 'entity_type' => 'authored_templates']]);
$referenceTokens = new Tokens('https://source.test', 'https://source.test/wp-content/uploads',
    static fn(int $id, string $kind): ?string => $id === 7 && $kind === 'auth_template' ? $uuid : null);
$shapes = [
    [['class' => 'authored', 'object_fields' => ['choice' => ['class' => 'authored', 'ref' => 'auth_template', 'on_unmapped' => 'refuse']]],
        ['choice' => 7], ['choice' => 211]],
    [['class' => 'authored', 'object_fields' => ['choice' => ['class' => 'authored', 'ref' => 'auth_template[]', 'cast' => 'csv', 'on_unmapped' => 'refuse']]],
        ['choice' => '7,7'], ['choice' => '211,211']],
    [['class' => 'authored', 'json_refs' => [['path' => '$.choice', 'kind' => 'auth_template']], 'on_unmapped' => 'refuse'],
        ['choice' => 7], ['choice' => 211]],
    [['class' => 'authored', 'key_refs' => ['kind' => 'auth_template'], 'on_unmapped' => 'refuse'],
        [7 => ['label' => 'selected']], [211 => ['label' => 'selected']]],
    [['class' => 'authored', 'object_fields' => ['mode' => ['class' => 'authored', 'enum' => [false, 0, '0']]]],
        ['mode' => false], ['mode' => false]],
];
foreach ($shapes as [$rule, $input, $output]) {
    foreach (['json', 'php_serialized'] as $container) {
        $encode = static fn($v) => $container === 'json' ? json_encode($v, JSON_THROW_ON_ERROR) : serialize($v);
        $typed = ['container' => $container, 'value' => $rule];
        $variant = $manifest;
        $variant['column_codecs']['authored_templates']['data'] = $typed;
        $load($variant);
        $canonical = ColumnCodecGrammar::capture_value($encode($input), $typed, $referenceTokens, 'typed shape');
        wprism_check_same($encode($output), ColumnCodecGrammar::apply_value($canonical, $typed, $targetTokens, 'typed shape'),
            "shared reference and literal codecs preserve native shape through $container framing");
        wprism_check_same(1, count($compile($variant, $canonical)), 'the same typed canonical shape compiles through the real graph');
    }
}

// Native export templates map field codes to labels, not customer values.
// The real loader and compiler must require explicit metadata authority.
$labelsManifest = $manifest;
$labelsManifest['column_codecs']['authored_templates']['data']['value'] = [
    'class' => 'authored', 'field_labels' => 'label_enabled',
];
$labelsPolicy = $load($labelsManifest);
$labelsCodec = $labelsPolicy->column_codec_rules('authored_templates')['data'];
$labelsNative = json_encode(['user_email' => ['Email address', 1], 'display_name' => ['Display name', 0]], JSON_THROW_ON_ERROR);
$labelsCanonical = ColumnCodecGrammar::capture_value($labelsNative, $labelsCodec, $sourceTokens, 'field definitions', 'data');
wprism_check_same($labelsNative, $labelsCanonical, 'declared field labels survive native capture without whole-column privacy clearance');
wprism_check_same(1, count($compile($labelsManifest, $labelsCanonical)), 'immutable compilation admits declared field-label metadata');

$unnegotiated = $labelsManifest;
$unnegotiated['engine_features'] = array_values(array_diff($unnegotiated['engine_features'], ['column-field-labels/v1']));
wprism_check_throws(static fn() => $load($unnegotiated), RuntimeException::class,
    'typed value authority alone cannot clear field-code roles', 'negotiated column field labels');
$badLabelRules = [
    ['class' => 'authored', 'field_labels' => null],
    ['class' => 'authored', 'field_labels' => 'unknown'],
    ['class' => 'derived', 'field_labels' => 'label'],
    ['class' => 'authored', 'field_labels' => 'label', 'plain_data' => true],
    ['class' => 'authored', 'field_labels' => 'label', 'allow_pii' => true],
    ['class' => 'authored', 'field_labels' => 'label', 'ref' => 'user'],
    ['class' => 'authored', 'field_labels' => 'label', 'object_fields' => ['f' => $leaf]],
];
$deepLabels = ['class' => 'authored', 'field_labels' => 'label'];
for ($i = 0; $i < 5; ++$i) $deepLabels = ['class' => 'authored', 'object_fields' => ['child' => $deepLabels]];
$badLabelRules[] = $deepLabels;
foreach ($badLabelRules as $rule) {
    $bad = $labelsManifest;
    $bad['column_codecs']['authored_templates']['data']['value'] = $rule;
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, 'field-label declarations have one negotiated bounded owner');
}
foreach (['options', 'post_meta', 'term_meta', 'user_meta', 'post_types', 'taxonomies'] as $surface) {
    $bad = $labelsManifest;
    $bad[$surface]['fixture'] = ['class' => 'authored', 'field_labels' => 'label'];
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, "$surface cannot borrow column metadata authority");
}
$bad = $labelsManifest;
$bad['tables']['authored_templates']['columns']['data']['field_labels'] = 'label';
wprism_check_throws(static fn() => $load($bad), RuntimeException::class, 'ordinary table classification cannot carry an inert label contract');
$bad = $labelsManifest;
$bad['engine_features'] = array_merge($bad['engine_features'], ['block-attribute-values/v1', 'block-value-contracts/v1']);
sort($bad['engine_features'], SORT_STRING);
$bad['block_values']['fixture/labels']['fields'] = ['class' => 'authored', 'field_labels' => 'label'];
wprism_check_throws(static fn() => $load($bad), RuntimeException::class, 'block contracts cannot borrow column privacy projection', 'negotiated column field labels');

foreach (['label' => $selectedLabels, 'label_enabled' => $labelFields] as $format => $valid) {
    foreach (['json', 'php_serialized'] as $container) {
        $variant = $labelsManifest;
        $variant['column_codecs']['authored_templates']['data'] = ['container' => $container,
            'value' => ['class' => 'authored', 'field_labels' => $format]];
        $labelCodec = $load($variant)->column_codec_rules('authored_templates')['data'];
        $encode = static fn($v) => $container === 'json' ? json_encode($v, JSON_THROW_ON_ERROR) : serialize($v);
        foreach ([$valid, []] as $input) {
            $bytes = $encode($input);
            $canonical = ColumnCodecGrammar::capture_value($bytes, $labelCodec, $sourceTokens, 'label map', 'data');
            wprism_check_same($bytes, $canonical, "$format preserves complete $container metadata and empty maps");
            wprism_check_same($bytes, ColumnCodecGrammar::apply_value($canonical, $labelCodec, $targetTokens, 'label map'),
                'field labels retain native scalar types on Apply');
            wprism_check_same(1, count($compile($variant, $canonical)), 'both label formats compile through strict framing');
        }
    }
}

$invalidLabels = [null, 'label', ['label'], ['' => ['label', 1]], [7 => ['label', 1]],
    ['field' => 'label'], ['field' => ['label']], ['field' => ['label', 1, 'extra']],
    ['field' => ['label', true]], ['field' => ['label', '1']], ['field' => ['label', 2]],
    ['field' => ['label', 1.0]], ['field' => [null, 1]], ['field' => [['nested' => 'label'], 1]],
    ['field' => ['label' => 'label', 'enabled' => 1]]];
$wpdb->seedTable('authored_templates', $beforeFailure);
foreach ($invalidLabels as $invalid) {
    $bytes = json_encode($invalid, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value($bytes, $labelsCodec, $sourceTokens, 'label map'),
        RuntimeException::class, 'malformed native label metadata refuses before role projection');
    wprism_check_throws(static fn() => $compile($labelsManifest, $bytes), RuntimeException::class,
        'immutable compilation refuses malformed label metadata', 'schema_content_mismatch');
    $labelSite = WPrismTest\FrozenPolicy::site([$labelsManifest], 3);
    $labelPolicy = WPrismTest\FrozenPolicy::policy([$labelsManifest], $labelSite);
    $lint = WPrism\Lint::scan_tree($repo . '/state', $labelPolicy);
    wprism_check(in_array('invalid_column_value', array_column($lint, 'class'), true), 'lint identifies malformed field-label values');
    $changed = $entity;
    $changed['data']['columns']['data'] = json_encode(['label_fields' => $invalid], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    wprism_check_throws(static fn() => $transaction(static fn() => $writer->finalizeRow($targetTokens, $changed)),
        RuntimeException::class, 'checked materialization independently rejects malformed label metadata');
    wprism_check_same($beforeFailure, $wpdb->rows('authored_templates'), 'invalid labels preserve the complete target table');
}

foreach (['reader@example.test', '203.0.113.24', '+1 202 555 0182', 'AKIAIOSFODNN7EXAMPLE'] as $private) {
    foreach ([[$private => ['Label', 1]], ['user_email' => [$private, 1]]] as $input) {
        $bytes = json_encode($input, JSON_THROW_ON_ERROR);
        wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value($bytes, $labelsCodec, $sourceTokens, 'label map'),
            RuntimeException::class, 'label metadata retains actual private key and value byte scans');
        wprism_check_throws(static fn() => $compile($labelsManifest, $bytes), RuntimeException::class,
            'repository edits cannot introduce private bytes into label metadata');
    }
}
$credential = json_encode(['user_pass' => ['s3cr3t-Credential-0123456789!', 1]], JSON_THROW_ON_ERROR);
wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value($credential, $labelsCodec, $sourceTokens, 'label map'),
    RuntimeException::class, 'credential-role scanning receives the original map', 'secret guard');
wprism_check_throws(static fn() => $compile($labelsManifest, $credential), RuntimeException::class,
    'compiler retains original credential roles', 'repository_secret_not_allowed');
foreach (['customer_address', 'first_name', 'api_key'] as $ancestor) {
    $wrapped = $labelsManifest;
    $wrapped['column_codecs']['authored_templates']['data']['value'] = ['class' => 'authored',
        'object_fields' => [$ancestor => ['class' => 'authored', 'field_labels' => 'label_enabled']]];
    $bytes = json_encode([$ancestor => ['user_email' => ['ordinary-private-material', 1]]], JSON_THROW_ON_ERROR);
    wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value($bytes,
        $wrapped['column_codecs']['authored_templates']['data'], $sourceTokens, 'nested labels'),
        RuntimeException::class, 'label projection preserves enclosing personal-data and credential roles');
    wprism_check_throws(static fn() => $compile($wrapped, $bytes), RuntimeException::class,
        'compiler preserves enclosing personal-data and credential roles');
}
$plain = $labelsCodec;
$plain['value'] = ['class' => 'authored', 'plain_data' => true];
wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value($labelsNative, $plain, $sourceTokens, 'plain data'),
    RuntimeException::class, 'existing plain-data declarations retain their original PII roles', 'PII guard');
wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value($labelsNative, $labelsCodec, $sourceTokens, 'root role', 'email'),
    RuntimeException::class, 'the enclosing column role is never erased by label metadata', 'PII guard');

// Saved wizard cursors are derived context beside authored method fields.
// Column admission reuses RecordFields; it does not introduce a second projector.
$recordRule = ['class' => 'authored', 'plain_data' => true,
    'record_fields' => ['container' => 'object', 'fields' => ['method', 'selected']]];
$recordManifest = $manifest;
$recordManifest['column_codecs']['authored_templates']['data']['value'] = $recordRule;
foreach ([[], ['block-record-fields/v1']] as $borrowed) {
    $bad = $recordManifest;
    $bad['engine_features'] = array_values(array_merge(array_diff($bad['engine_features'], ['column-record-fields/v1']), $borrowed));
    sort($bad['engine_features'], SORT_STRING);
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class,
        'column record projection requires its own feature', 'negotiated column value and record field features');
}
$bad = $recordManifest;
$bad['engine_features'] = array_merge($bad['engine_features'], ['block-attribute-values/v1', 'block-value-contracts/v1']);
sort($bad['engine_features'], SORT_STRING);
$bad['block_values']['fixture/record']['context'] = $recordRule;
wprism_check_throws(static fn() => $load($bad), RuntimeException::class,
    'block records cannot borrow column record authority', 'negotiated block value and record field features');
$badRecordRules = [
    array_replace($recordRule, ['record_fields' => ['container' => 'object', 'fields' => ['method', 'method']]]),
    array_replace($recordRule, ['record_fields' => ['container' => 'object', 'fields' => ['method.*']]]),
    array_replace($recordRule, ['record_fields' => ['container' => 'object', 'fields' => ['method'], 'extra' => true]]),
    $leaf + ['record_fields' => $recordRule['record_fields']],
    $valueRule + ['record_fields' => $recordRule['record_fields']],
];
foreach (['$.discarded.id', '$.*.id', '$..id'] as $path) {
    $badRecordRules[] = ['class' => 'authored', 'record_fields' => $recordRule['record_fields'],
        'json_refs' => [['path' => $path, 'kind' => 'auth_template']], 'on_unmapped' => 'refuse'];
}
$badRecordRules[] = ['class' => 'authored', 'record_fields' => $recordRule['record_fields'],
    'key_refs' => ['kind' => 'auth_template'], 'on_unmapped' => 'refuse'];
foreach ($badRecordRules as $rule) {
    $bad = $recordManifest;
    $bad['column_codecs']['authored_templates']['data']['value'] = $rule;
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class,
        'column records retain the shared closed projection and reference ownership rules');
}
foreach (['object', 'list'] as $shape) {
    foreach (['json', 'php_serialized'] as $container) {
        $variant = $recordManifest;
        $variant['column_codecs']['authored_templates']['data'] = ['container' => $container,
            'value' => ['class' => 'authored', 'record_fields' => ['container' => $shape, 'fields' => ['id', 'label']],
                'json_refs' => [['path' => '$.id', 'kind' => 'auth_template']], 'on_unmapped' => 'refuse']];
        $recordCodec = $load($variant)->column_codec_rules('authored_templates')['data'];
        $encode = static fn($v) => $container === 'json' ? json_encode($v, JSON_THROW_ON_ERROR) : serialize($v);
        $input = ['label' => 'Selected', 'cursor' => '12', 'id' => 7];
        $output = ['label' => 'Selected', 'id' => 211];
        if ($shape === 'list') { $input = [$input, $input]; $output = [$output, $output]; }
        $canonical = ColumnCodecGrammar::capture_value($encode($input), $recordCodec, $referenceTokens, 'record');
        wprism_check_same($encode($output), ColumnCodecGrammar::apply_value($canonical, $recordCodec, $targetTokens, 'record'),
            "projected $shape records retain order, duplicates and native references through $container");
        wprism_check_same(1, count($compile($variant, $canonical)), 'projected record references compile in the canonical graph');
        if ($shape === 'list') {
            wprism_check_same($encode([]), ColumnCodecGrammar::capture_value($encode([]), $recordCodec, $referenceTokens, 'empty records'),
                'an empty record list remains empty');
        }
        foreach ([null, ['cursor' => 12], [], [7]] as $invalid) {
            if ($shape === 'list') $invalid = [$invalid];
            wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value($encode($invalid), $recordCodec, $referenceTokens, 'invalid record'),
                RuntimeException::class, 'native empty, malformed or fully discarded records refuse');
        }
        $excluded = $container === 'json' ? json_decode($canonical, true, flags: JSON_THROW_ON_ERROR)
            : unserialize($canonical, ['allowed_classes' => false]);
        if ($shape === 'object') $excluded['cursor'] = '12';
        else $excluded[0]['cursor'] = '12';
        wprism_check_throws(static fn() => $compile($variant, $encode($excluded)), RuntimeException::class,
            'canonical edits cannot restore an excluded field', 'schema_content_mismatch');
    }
}
$cursorEdit = json_decode($expected, true, flags: JSON_THROW_ON_ERROR);
$cursorEdit['method_export_form_data']['selected_template'] = '99';
$cursorEdit = json_encode($cursorEdit, JSON_THROW_ON_ERROR);
wprism_check_throws(static fn() => $compile($manifest, $cursorEdit), RuntimeException::class,
    'compiler refuses a reintroduced saved wizard cursor', 'schema_content_mismatch');
$lint = WPrism\Lint::scan_tree($repo . '/state', WPrismTest\FrozenPolicy::policy([$manifest], WPrismTest\FrozenPolicy::site([$manifest], 3)));
wprism_check(in_array('invalid_column_value', array_column($lint, 'class'), true), 'lint identifies an excluded canonical record field');
$changed = $entity;
$changed['data']['columns']['data'] = $cursorEdit;
wprism_check_throws(static fn() => $transaction(static fn() => $writer->finalizeRow($targetTokens, $changed)),
    RuntimeException::class, 'materializer independently refuses a reintroduced wizard cursor', 'excluded canonical record fields');
wprism_check_same($beforeFailure, $wpdb->rows('authored_templates'), 'excluded canonical fields preserve the complete target table');
foreach ([['email' => 'reader@example.test'], ['api_key' => 'private-credential-material']] as $private) {
    $input = json_encode(['method_export_form_data' => ['method_export' => 'template'] + $private], JSON_THROW_ON_ERROR);
    wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value($input, $codec, $targetTokens, 'record clearance'),
        RuntimeException::class, 'discarded native fields still undergo original PII and secret clearance');
}

wprism_check_summary('regress_column_value_contracts');
