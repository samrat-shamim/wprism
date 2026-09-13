<?php
declare(strict_types=1);

// CSV headers are identities; literals still become destination field values.
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
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Capture/TypedTableCapture.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Apply/TypedTableMaterializer.php';
require_once $root . '/agent/src/Review/Lint.php';

use WPrism\AuthoredValueCodec;
use WPrism\Canon;
use WPrism\ColumnCodecGrammar;
use WPrism\FieldTemplateMap;
use WPrism\Policy;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

$leaf = ['class' => 'authored', 'field_templates' => 'brace'];
$enabled = ['class' => 'authored', 'field_templates' => 'brace_enabled'];
$contract = ['class' => 'authored', 'object_fields' => ['selected' => $leaf, 'definitions' => $enabled,
    'ordinary' => ['class' => 'authored', 'plain_data' => true]]];
$codec = ['container' => 'json', 'value' => $contract];
$manifest = ['name' => 'column-templates', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'engine_features' => ['column-field-templates/v1', 'json-column-codecs/v1', 'spec-window/v1', 'typed-column-codecs/v1', 'typed-column-values/v1'],
    'tables' => ['authored_templates' => ['class' => 'authored_snapshot', 'pk' => 'id', 'id_kind' => 'auth_template',
        'slug_column' => 'name', 'identity' => ['mode' => 'mapped'],
        'columns' => ['name' => ['class' => 'authored'], 'data' => ['class' => 'authored']], 'refs' => []]],
    'column_codecs' => ['authored_templates' => ['data' => $codec]]];
$scratch = sys_get_temp_dir() . '/wprism-column-templates-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700, true);
register_shutdown_function(static fn() => manifest_fixture_remove_tree($scratch));
$load = static function (array $input) use ($scratch): Policy {
    $dir = $scratch . '/library-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    Canon::write_file($dir . '/column-templates.json', Canon::encode($input));
    return Policy::load(null, ['column-templates'], adapterLibrary: manifest_fixture_adapter_library($dir));
};
$policy = $load($manifest);
wprism_check_same(Canon::encode(['data' => $codec]), Canon::encode($policy->column_codec_rules('authored_templates')),
    'real policy loading admits nested expression contracts');
foreach (['column-field-templates/v1', 'typed-column-values/v1', 'typed-column-codecs/v1'] as $feature) {
    $bad = $manifest;
    $bad['engine_features'] = array_values(array_diff($bad['engine_features'], [$feature]));
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, "field templates require $feature");
}
foreach ([['class' => 'authored', 'field_templates' => null], ['class' => 'authored', 'field_templates' => 'unknown'],
    $leaf + ['plain_data' => true], $leaf + ['allow_pii' => true], $leaf + ['field_labels' => 'label'],
    $leaf + ['ref' => 'user'], ['class' => 'derived', 'field_templates' => 'brace']] as $rule) {
    $bad = $manifest;
    $bad['column_codecs']['authored_templates']['data']['value'] = $rule;
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, 'expression declaration has one closed authored owner');
}
foreach (['options', 'post_meta', 'term_meta', 'user_meta', 'post_types', 'taxonomies'] as $surface) {
    $bad = $manifest;
    $bad[$surface]['fixture'] = $leaf;
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, "$surface cannot borrow column expression authority");
}
$bad = $manifest;
$bad['engine_features'] = array_merge($bad['engine_features'], ['block-attribute-values/v1', 'block-value-contracts/v1']);
sort($bad['engine_features'], SORT_STRING);
$bad['block_values']['fixture/templates']['fields'] = $leaf;
wprism_check_throws(static fn() => $load($bad), RuntimeException::class, 'block values cannot borrow column expression authority');

WpStore::reset()->seedOptions(['home' => 'https://source.test']);
$wpdb = FakeWpdb::install()->enableInformationSchema()->enableJoinedCaptureSql()
    ->setPrimaryKey('authored_templates', 'id')->setAutoIncrement('authored_templates', 800, 'id')
    ->setColumns('authored_templates', ['id' => 'int(11)', 'name' => 'varchar(255)', 'data' => 'longtext'])
    ->setTableEngine('authored_templates', 'InnoDB');
$sourceTokens = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
$targetTokens = new Tokens('https://target.test', 'https://target.test/wp-content/uploads');
$expressions = ['first_name' => '{First name}', 'user_email' => '{Email address}',
    'user_pass' => '{Customer Password Column}', 'description' => 'https://source.test/public/{https://source.test/header}',
    'url_query_header' => '{https://source.test/?p=41}', 'escaped_header' => '{https:\/\/source.test\/header}',
    'empty' => '', 'braces' => '{}{Unclosed', 'adjacent' => '{First}{Last}',
    'nested' => '{{home}}', 'date' => '{ Birthwt_iew_@!Y-m-d }', 'arithmetic' => '[{Count}+1]',
    'unicode' => '{নাম}', 'unmatched' => 'a}b{', 'literal' => 'Public constant'];
$fragments = ['first_name' => [['field' => 'First name']], 'user_email' => [['field' => 'Email address']],
    'user_pass' => [['field' => 'Customer Password Column']],
    'description' => [['text' => '{{home}}/public/'], ['field' => 'https://source.test/header']],
    'url_query_header' => [['field' => 'https://source.test/?p=41']], 'escaped_header' => [['field' => 'https:\/\/source.test\/header']],
    'empty' => [], 'braces' => [['text' => '{}{Unclosed']],
    'adjacent' => [['field' => 'First'], ['field' => 'Last']],
    'nested' => [['field' => '{home'], ['text' => '}']], 'date' => [['field' => ' Birthwt_iew_@!Y-m-d ']],
    'arithmetic' => [['text' => '['], ['field' => 'Count'], ['text' => '+1]']],
    'unicode' => [['field' => 'নাম']], 'unmatched' => [['text' => 'a}b{']], 'literal' => [['text' => 'Public constant']]];
$toTarget = $expressions;
$toTarget['description'] = 'https://target.test/public/{https://source.test/header}';
foreach (['brace', 'brace_enabled'] as $format) {
    foreach (['json', 'php_serialized'] as $container) {
        $input = $format === 'brace' ? $expressions : array_map(static fn($v) => [$v, 0], $expressions);
        $expected = $format === 'brace' ? $fragments : array_map(static fn($v) => [$v, 0], $fragments);
        $target = $format === 'brace' ? $toTarget : array_map(static fn($v) => [$v, 0], $toTarget);
        $one = ['container' => $container, 'value' => ['class' => 'authored', 'field_templates' => $format]];
        $encode = static fn($v) => $container === 'json' ? json_encode($v, JSON_THROW_ON_ERROR) : serialize($v);
        $canonical = ColumnCodecGrammar::capture_value($encode($input), $one, $sourceTokens, 'expression map', 'data');
        wprism_check_same($encode($expected), $canonical, "$format/$container separates opaque headers from literal transport");
        wprism_check_same($encode($target), ColumnCodecGrammar::apply_value($canonical, $one, $targetTokens, 'expression map'),
            "$format/$container preserves references, disabled flags and native expression syntax");
        wprism_check_same($canonical, ColumnCodecGrammar::capture_value($encode($target), $one, $targetTokens, 'expression map'),
            "$format/$container reaches a fixed point across different environment URLs");
        wprism_check_same($encode([]), ColumnCodecGrammar::capture_value($encode([]), $one, $sourceTokens, 'empty map'), 'empty map is preserved');
    }
}

// Compare the streaming parser with the independently stated dialect, including
// every short brace/text string. This catches first-open and empty-brace drift.
$strings = [''];
for ($depth = 0; $depth < 6; ++$depth) {
    $next = [];
    foreach ($strings as $s) foreach (['{', '}', 'x'] as $c) $next[] = $s . $c;
    $strings = $next;
}
$parserMatches = true;
foreach ($strings as $input) {
    preg_match_all('/\{([^}]+)\}/m', $input, $matches, PREG_OFFSET_CAPTURE);
    $expected = [];
    $offset = 0;
    foreach ($matches[0] as $i => [$match, $at]) {
        if ($at > $offset) $expected[] = ['text' => substr($input, $offset, $at - $offset)];
        $expected[] = ['field' => $matches[1][$i][0]];
        $offset = $at + strlen($match);
    }
    if ($offset < strlen($input)) $expected[] = ['text' => substr($input, $offset)];
    $budget = 0;
    $parserMatches = $parserMatches && FieldTemplateMap::parse($input, $budget, 'dialect') === $expected;
}
wprism_check($parserMatches, '729 independently parsed brace strings preserve the exact native dialect');

$one = ['container' => 'json', 'value' => $leaf];
$invalidParts = [null, 'raw', ['text' => 'raw'], [['text' => '']], [['field' => '']], [['field' => 'a}b']],
    [['unknown' => 'x']], [['text' => 'x', 'field' => 'a']], [['field' => 1]],
    [['text' => 'a'], ['text' => 'b']], [['text' => '{Header}']], [['text' => '{{unknown}}']],
    [['text' => '{'], ['field' => 'Header']], [['text' => '{{home'], ['text' => '}}']]];
foreach ($invalidParts as $parts) {
    $bytes = json_encode(['description' => $parts], JSON_THROW_ON_ERROR);
    wprism_check_throws(static fn() => ColumnCodecGrammar::decode_canonical_value($bytes, $one, 'invalid parts'),
        RuntimeException::class, 'canonical parts refuse ambiguous, mistyped and non-normalized shapes');
    wprism_check_throws(static fn() => ColumnCodecGrammar::apply_value($bytes, $one, $targetTokens, 'invalid parts'),
        RuntimeException::class, 'Apply independently refuses malformed parts');
}
foreach ([['description' => 1], ['' => 'x'], [0 => 'x']] as $invalid) {
    wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value(json_encode($invalid), $one, $sourceTokens, 'native shape'),
        RuntimeException::class, 'native maps require exact field keys and strings');
}
foreach ([['x'], ['x', true], ['x', '1'], ['x', 2], ['x', 1.0], ['x', 1, 'date'], [[], 0]] as $invalid) {
    wprism_check_throws(static fn() => AuthoredValueCodec::assert_value(['description' => $invalid], $enabled, false, 'native tuple'),
        RuntimeException::class, 'native enabled expressions require exact two-slot integer tuples');
}
$hostileTarget = new Tokens('https://target.test/{Injected}', 'https://target.test/uploads');
$urlParts = json_encode(['description' => [['text' => '{{home}}/public']]]);
wprism_check_throws(static fn() => ColumnCodecGrammar::apply_value($urlParts, $one, $hostileTarget, 'target URL'),
    RuntimeException::class, 'target token expansion cannot inject native field syntax', 'unambiguous');

$privateCases = [['first_name' => 'Alice Example'], ['first_name' => 'Dr. {First name}'],
    ['user_email' => 'reader@example.test'], ['user_pass' => 's3cr3t-Credential-0123456789!'],
    ['user_pass' => 's3cr3t-{Header}Credential-0123456789!'], ['description' => 'reader@{Header}example.test'],
    ['description' => '{AKIAIOSFODNN7EXAMPLE}'], ['AKIAIOSFODNN7EXAMPLE' => '{Header}'],
    ['description' => '{api_key = s3cr3t-Credential-0123456789!}']];
foreach ($privateCases as $input) {
    foreach ([0, 1] as $flag) {
        $native = array_map(static fn($v) => [$v, $flag], $input);
        wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value(json_encode($native),
            ['container' => 'json', 'value' => $enabled], $sourceTokens, 'private expression'), RuntimeException::class,
            'literal roles, concatenated literals and complete metadata bytes retain clearance even when disabled');
    }
}
foreach (['first_name', 'customer_address', 'api_key'] as $ancestor) {
    $wrapped = ['class' => 'authored', 'object_fields' => [$ancestor => $leaf]];
    wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value(json_encode([$ancestor => ['field' => '{Long Header Definition}']]),
        ['container' => 'json', 'value' => $wrapped], $sourceTokens, 'enclosing role'), RuntimeException::class,
        'expression metadata retains enclosing personal and credential roles');
}
foreach ([FieldTemplateMap::MAX_BYTES + 1, FieldTemplateMap::MAX_BYTES] as $length) {
    $value = ['description' => str_repeat('x', $length)];
    if ($length > FieldTemplateMap::MAX_BYTES) {
        wprism_check_throws(static fn() => AuthoredValueCodec::assert_value($value, $leaf, false, 'byte bound'), RuntimeException::class,
            'oversized native expressions refuse before fragment allocation', 'byte budget');
    } else {
        AuthoredValueCodec::assert_value($value, $leaf, false, 'byte bound');
        wprism_check(true, 'the exact expression byte bound is admitted');
    }
}
$many = ['description' => str_repeat('{X}', FieldTemplateMap::MAX_FRAGMENTS)];
AuthoredValueCodec::assert_value($many, $leaf, false, 'fragment bound');
wprism_check(true, 'the exact root fragment budget is admitted');
$wide = ['class' => 'authored', 'object_fields' => ['a' => $leaf, 'b' => $leaf]];
foreach ([false, true] as $canonical) {
    $manyValue = $canonical ? ['description' => array_fill(0, FieldTemplateMap::MAX_FRAGMENTS, ['field' => 'X'])] : $many;
    wprism_check_throws(static fn() => AuthoredValueCodec::assert_value(['a' => $manyValue, 'b' => $manyValue], $wide, $canonical, 'shared bound'),
        RuntimeException::class, 'fragment budget is shared across the complete root contract', 'fragment budget');
}

// Exercise canonical authoring and actual table materialization, not just the
// new parser: the compiler must repeat every role decision without WordPress.
$uuid = '11111111-1111-4111-8111-111111111111';
$nativeMap = ['selected' => $expressions, 'definitions' => array_map(static fn($v) => [$v, 0], $expressions),
    'ordinary' => ['url' => 'https://source.test/public']];
$wpdb->seedTable('authored_templates', [['id' => 2, 'name' => 'Template', 'data' => json_encode($nativeMap, JSON_THROW_ON_ERROR)]]);
$decl = $manifest['tables']['authored_templates'];
$identity = new class($uuid) {
    public function __construct(private string $uuid) {}
    public function identifyRow(string $table, array $decl, array $row, int $id): string { return $this->uuid; }
};
$capture = new WPrism\TypedTableCapture($identity, static function (): void {}, static fn() => null, static fn($v) => strtolower($v));
$captureRows = static fn(Tokens $tokens): array => $capture->capture_table('authored_templates', $decl, [], $tokens,
    true, false, $policy->column_codec_rules('authored_templates'));
$entities = $captureRows($sourceTokens);
wprism_check_same(1, count($entities), 'real Capture admits references in personal and password destination fields');
$capturedData = json_decode(Canon::decode($entities[0]['content'])['columns']['data'], true, flags: JSON_THROW_ON_ERROR);
wprism_check_same($fragments['description'], $capturedData['selected']['description'], 'Capture preserves URL-shaped CSV headers beside tokenized literal text');
$repo = $scratch . '/repository';
$compile = static function (array $inputs, array $inputManifest) use ($repo): array {
    manifest_fixture_remove_tree($repo);
    $site = WPrismTest\FrozenPolicy::site([$inputManifest], 3);
    $frozen = WPrismTest\FrozenPolicy::policy([$inputManifest], $site);
    Canon::write_file($repo . '/site.wprism.json', Canon::encode($site));
    foreach ($inputs as $entity) Canon::write_file($repo . '/state/' . $entity['path'], $entity['content']);
    return WPrism\RepositoryCompiler::compile($repo, $frozen)->tree();
};
$editData = static function (array $entity, array $data): array {
    $front = Canon::decode($entity['content']);
    $front['columns']['data'] = json_encode($data, JSON_THROW_ON_ERROR);
    $entity['content'] = Canon::encode($front);
    $entity['data'] = $front;
    return $entity;
};
$tree = $compile($entities, $manifest);
wprism_check_same(1, count($tree), 'immutable compiler applies the same literal and reference privacy semantics');
wprism_check_same([], WPrism\Lint::scan_tree($repo . '/state', $policy), 'opaque source-URL headers do not cause environment lint findings');
$site = WPrismTest\FrozenPolicy::site([$manifest], 3);
Canon::write_file($repo . '/probe.json', Canon::encode(['manifest' => $manifest, 'site' => $site]));
$child = proc_open([PHP_BINARY, __DIR__ . '/regress_column_value_contracts.php', '--compile-without-wordpress', $repo],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($child)) throw new RuntimeException('could not start isolated template compiler');
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
wprism_check_same(0, proc_close($child), 'template compiler runs outside WordPress');
wprism_check_same('', $stderr, 'isolated compiler emits no diagnostics');
wprism_check_same(['entities' => 1, 'wordpress' => false, 'database' => false], json_decode($stdout, true),
    'semantic expression compilation has no database or plugin dependency');
foreach ($privateCases as $input) {
    // The editing path intentionally starts with canonical data, bypassing
    // Capture so repository authorization must independently detect the defect.
    $parts = AuthoredValueCodec::capture($input, $leaf, $sourceTokens, static function (): void {}, 'private edit');
    $edited = $editData($entities[0], ['selected' => $parts]);
    wprism_check_throws(static fn() => $compile([$edited], $manifest), RuntimeException::class,
        'immutable edits cannot insert private literal or metadata bytes');
}
foreach ([['first_name' => 'Alice Example'], ['api_key' => 'private-credential-material']] as $privateSibling) {
    $edited = $editData($entities[0], ['selected' => $fragments, 'ordinary' => $privateSibling]);
    wprism_check_throws(static fn() => $compile([$edited], $manifest), RuntimeException::class,
        'ordinary siblings retain full PII and credential roles beside expressions');
    wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value(json_encode(['selected' => $expressions, 'ordinary' => $privateSibling]),
        $codec, $sourceTokens, 'private sibling'), RuntimeException::class, 'Capture also retains ordinary sibling roles');
}
foreach ($invalidParts as $parts) {
    $badEntity = $editData($entities[0], ['selected' => ['description' => $parts]]);
    wprism_check_throws(static fn() => $compile([$badEntity], $manifest), RuntimeException::class,
        'compiler rejects malformed canonical template fragments', 'schema_content_mismatch');
    wprism_check(in_array('invalid_column_value', array_column(WPrism\Lint::scan_tree($repo . '/state', $policy), 'class'), true),
        'lint identifies the same malformed expression contract');
}
$unrewritten = $editData($entities[0], ['selected' => ['description' => [['text' => 'https:\/\/source.test\/public']]]]);
$compile([$unrewritten], $manifest);
wprism_check(WPrism\Lint::scan_tree($repo . '/state', $policy) !== [], 'literal source URLs retain environment lint checks');
$selfReference = $editData($entities[0], ['selected' => ['description' => [['text' => '{{auth_template:' . $uuid . '}}']]]]);
wprism_check_same(1, count($compile([$selfReference], $manifest)), 'canonical literal identity tokens participate in the shared graph');
$dangling = $editData($entities[0], ['selected' => ['description' => [['text' => '{{auth_template:22222222-2222-4222-8222-222222222222}}']]]]);
wprism_check_throws(static fn() => $compile([$dangling], $manifest), RuntimeException::class,
    'literal identity tokens cannot hide absent graph entities');

$wpdb->seedTable('authored_templates', []);
$mapping = [];
$writer = new WPrism\TypedTableMaterializer(static fn() => ['authored_templates' => $decl], static fn() => [],
    static function (string $id) use (&$mapping): ?int { return $mapping[$id] ?? null; },
    static function (string $id, string $table, string $kind, int $local) use (&$mapping): void { $mapping[$id] = $local; },
    static fn() => 0, static fn() => [], static fn() => false, static fn($v) => $v, static function (): void {},
    static fn() => $policy->column_codec_rules('authored_templates'));
$transaction = static function (callable $action) use (&$mapping): mixed {
    $before = $mapping;
    WPrism\Db::start_repeatable_read('field templates', new WPrism\NativeDatabaseProfile(['wp_authored_templates'], ['wp_authored_templates']));
    try { $result = $action(); WPrism\Db::commit('field templates'); return $result; }
    catch (Throwable $failure) { WPrism\Db::rollback_after_failure($failure, 'field templates'); $mapping = $before; throw $failure; }
};
$badEntity = $editData($entities[0], ['selected' => ['description' => [['text' => '{Header}']]]]);
wprism_check_throws(static fn() => $writer->ensureRow($badEntity), RuntimeException::class,
    'phase one refuses ambiguous expression data before insertion', 'unambiguous');
wprism_check_same([], $wpdb->rows('authored_templates'), 'malformed phase one performs no native insert');
wprism_check_same([], $mapping, 'malformed phase one publishes no identity');
$apply = static function () use ($writer, $tree, $targetTokens): void {
    foreach ($tree as $entity) { $writer->ensureRow($entity); $writer->finalizeRow($targetTokens, $entity); }
};
$transaction($apply);
$targetRows = $wpdb->rows('authored_templates');
$targetData = json_decode($targetRows[0]['data'], true, flags: JSON_THROW_ON_ERROR);
wprism_check_same($toTarget, $targetData['selected'], 'real materializer restores native expressions and target literal URLs');
wprism_check_same(array_map(static fn($v) => [$v, 0], $toTarget), $targetData['definitions'], 'real materializer preserves disabled definitions');
wprism_check_same(array_column($entities, 'content'), array_column($captureRows($targetTokens), 'content'), 'complete target rows recapture to the source canonical tree');
$transaction($apply);
wprism_check_same($targetRows, $wpdb->rows('authored_templates'), 'repeated Apply retains native bytes and row identity');
wprism_check_throws(static function () use ($transaction, $writer, $tree, $hostileTarget): void {
    $transaction(static function () use ($writer, $tree, $hostileTarget): void {
        foreach ($tree as $entity) { $writer->ensureRow($entity); $writer->finalizeRow($hostileTarget, $entity); }
    });
}, RuntimeException::class, 'target brace injection refuses through the real write path', 'unambiguous');
wprism_check_same($targetRows, $wpdb->rows('authored_templates'), 'target expression ambiguity leaves every row intact');
$changed = $editData($entities[0], ['selected' => ['description' => [['text' => 'Changed constant']]]]);
$observed = false;
wprism_check_throws(static function () use ($transaction, $writer, $changed, $targetTokens, $wpdb, &$observed): void {
    $transaction(static function () use ($writer, $changed, $targetTokens, $wpdb, &$observed): void {
        $writer->ensureRow($changed); $writer->finalizeRow($targetTokens, $changed);
        $observed = json_decode($wpdb->rows('authored_templates')[0]['data'], true)['selected']['description'] === 'Changed constant';
        throw new RuntimeException('later failure');
    });
}, RuntimeException::class, 'later transaction failure rolls back authored expression changes', 'later failure');
wprism_check($observed, 'rollback evidence reaches the changed native expression before failure');
wprism_check_same($targetRows, $wpdb->rows('authored_templates'), 'rollback restores the complete prior row');

$cases = $manifest;
$cases['engine_features'] = array_merge($cases['engine_features'], ['column-field-labels/v1', 'column-value-cases/v1',
    'table-row-scopes/v1', 'table-row-scope-sets/v1']);
sort($cases['engine_features'], SORT_STRING);
$cases['tables']['authored_templates']['row_scope'] = ['mode' => ['export', 'import']];
$cases['tables']['authored_templates']['columns']['mode'] = ['class' => 'authored'];
$cases['column_codecs']['authored_templates']['data'] = ['container' => 'json', 'value_cases' => ['column' => 'mode', 'cases' => [
    ['equals' => 'export', 'value' => ['class' => 'authored', 'field_labels' => 'label']], ['equals' => 'import', 'value' => $leaf],
]]];
$casePolicy = $load($cases);
$caseCodec = WPrism\ColumnValueCases::resolve($casePolicy->column_codec_rules('authored_templates')['data'], ['mode' => 'import'], 'selected case');
wprism_check_same($leaf, $caseCodec['value'], 'row variants compose with the expression contract');
$import = $entities[0];
$front = Canon::decode($import['content']);
$front['columns']['mode'] = 'import';
$front['columns']['data'] = json_encode($fragments, JSON_THROW_ON_ERROR);
$import['content'] = Canon::encode($front);
wprism_check_same(1, count($compile([$import], $cases)), 'selected import expression values compile alongside export metadata contracts');
$front['columns']['mode'] = 'export';
$import['content'] = Canon::encode($front);
wprism_check_throws(static fn() => $compile([$import], $cases), RuntimeException::class,
    'a template representation cannot borrow the export label shape', 'schema_content_mismatch');

$large = ['selected' => $many, 'ordinary' => array_fill(0, 70000, '')];
wprism_check_throws(static fn() => AuthoredValueCodec::capture($large, $contract, $sourceTokens, static function (): void {}, 'expanded nodes'),
    RuntimeException::class, 'fragment expansion retains the complete canonical JSON node bound', 'bounded plain JSON');

$postUuid = '33333333-3333-4333-8333-333333333333';
$wpdb->seedTable('wp_wprism_map', [['uuid' => $postUuid, 'id_kind' => 'post', 'local_id' => 211, 'entity_type' => 'post']]);
$queryTokens = new Tokens('https://source.test', 'https://source.test/uploads',
    static fn(int $id, string $kind): ?string => $id === 41 && $kind === 'post' ? $postUuid : null);
$queryInput = json_encode(['description' => 'https://source.test/?p=41&label={Header}']);
$queryCanonical = ColumnCodecGrammar::capture_value($queryInput, $one, $queryTokens, 'query expression');
wprism_check_same([['text' => '{{home}}/?p={{post:' . $postUuid . '}}&label='], ['field' => 'Header']],
    json_decode($queryCanonical, true)['description'], 'literal URL queries reuse durable post reference tokenization');
wprism_check_same(json_encode(['description' => 'https://target.test/?p=211&label={Header}']),
    ColumnCodecGrammar::apply_value($queryCanonical, $one, $targetTokens, 'query expression'),
    'literal query tokens rebind to a different native target ID beside a CSV reference');
$wpdb->seedTable('wp_wprism_map', []);
wprism_check_throws(static fn() => ColumnCodecGrammar::apply_value($queryCanonical, $one,
    new Tokens('https://target.test', 'https://target.test/uploads'), 'query expression'), RuntimeException::class,
    'missing literal query bindings refuse through the existing reference machinery');

wprism_check_summary('column field templates');
