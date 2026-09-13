<?php
declare(strict_types=1);

// A saved cursor may share an object with typed references and target-local input.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/agent_version.php';
require_once __DIR__ . '/../policy/manifest_fixtures.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';
require_once __DIR__ . '/../../support/wp-block-parser-stub.php';
require_once __DIR__ . '/../../support/wp-shortcode-stub.php';
$root = dirname(__DIR__, 4);
wprism_test_define_agent_versions();
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/agent/src/Grammar/Blocks.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Review/Lint.php';

use WPrism\AuthoredValueCodec;
use WPrism\Blocks;
use WPrism\Canon;
use WPrism\ColumnCodecGrammar;
use WPrism\InputFileBinding;
use WPrism\Policy;
use WPrism\RepositoryCompiler;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$scratch = sys_get_temp_dir() . '/wprism-object-records-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
register_shutdown_function(static fn() => manifest_fixture_remove_tree($scratch));
$fields = ['mode' => ['class' => 'authored', 'enum' => ['local']],
    'text' => ['class' => 'authored', 'plain_data' => true],
    'owner' => ['class' => 'authored', 'ref' => 'user', 'cast' => 'string', 'on_unmapped' => 'refuse'],
    'input' => ['class' => 'authored', 'input_file' => ['directory' => 'inputs', 'extensions' => ['csv']]]];
$record = ['class' => 'authored', 'object_fields' => $fields,
    'record_fields' => ['container' => 'object', 'fields' => array_keys($fields)]];
$contract = ['class' => 'authored', 'object_fields' => ['method' => $record]];
$codec = ['container' => 'json', 'value' => $contract];
$manifest = ['name' => 'object-records', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'engine_features' => ['column-input-files/v1', 'column-record-fields/v1', 'json-column-codecs/v1', 'object-record-fields/v1',
        'spec-window/v1', 'typed-column-codecs/v1', 'typed-column-values/v1'],
    'tables' => ['records' => ['class' => 'authored_snapshot', 'pk' => 'id', 'id_kind' => 'record',
        'identity' => ['mode' => 'mapped'], 'slug_column' => 'name',
        'columns' => ['name' => ['class' => 'authored'], 'data' => ['class' => 'authored']], 'refs' => []]],
    'column_codecs' => ['records' => ['data' => $codec]]];
$load = static function (array $input) use ($scratch): Policy {
    $directory = $scratch . '/library-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    Canon::write_file($directory . '/object-records.json', Canon::encode($input));
    return Policy::load(null, ['object-records'], adapterLibrary: manifest_fixture_adapter_library($directory));
};
$policy = $load($manifest);
wprism_check_same(Canon::encode(['data' => $codec]), Canon::encode($policy->column_codec_rules('records')), 'policy admits negotiated typed object projection');
foreach (['object-record-fields/v1', 'column-record-fields/v1', 'typed-column-values/v1'] as $feature) {
    $bad = $manifest;
    $bad['engine_features'] = array_values(array_diff($bad['engine_features'], [$feature]));
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, "object projection requires $feature");
}
foreach ([null, [], ['container' => 'list', 'fields' => array_keys($fields)],
    ['container' => 'object', 'fields' => ['mode']], ['container' => 'object', 'fields' => [...array_keys($fields), 'cursor']],
    ['container' => 'object', 'fields' => [...array_keys($fields), 'mode']]] as $badRecord) {
    $bad = $manifest;
    $bad['column_codecs']['records']['data']['value']['object_fields']['method']['record_fields'] = $badRecord;
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, 'projection cannot hide typed fields or retain untyped ones');
}
$reordered = $manifest;
$reordered['column_codecs']['records']['data']['value']['object_fields']['method']['record_fields']['fields'] = array_reverse(array_keys($fields));
wprism_check($load($reordered) instanceof Policy, 'retained membership is a set, not a native reordering instruction');
$db = FakeWpdb::install();
WpStore::reset()->seedOptions(['home' => 'https://source.test']);
$native = ['method' => ['cursor' => '17', 'mode' => 'local', 'text' => 'https://source.test/public', 'owner' => '2',
    'input' => 'https://source.test/content/inputs/source.csv']];
$expected = ['method' => ['mode' => 'local', 'text' => '{{home}}/public', 'owner' => 'user:record-editor', 'input' => InputFileBinding::MARKER]];
$target = ['method' => ['mode' => 'local', 'text' => 'https://target.test/public', 'owner' => '82', 'input' => 'https://target.test/content/inputs/target.csv']];
foreach (['json', 'php_serialized'] as $container) {
    $one = ['container' => $container, 'value' => $contract];
    $encode = static fn($value) => $container === 'json' ? json_encode($value, JSON_THROW_ON_ERROR) : serialize($value);
    $db->seedTable('wp_users', [['ID' => 2, 'user_login' => 'record-editor']]);
    $sourceTokens = new Tokens('https://source.test', 'https://source.test/uploads');
    $canonical = ColumnCodecGrammar::capture_value($encode($native), $one, $sourceTokens, 'object records', 'data');
    wprism_check_same($encode($expected), $canonical, "$container projects the cursor and preserves typed children");
    $db->seedTable('wp_users', [['ID' => 82, 'user_login' => 'record-editor']]);
    $targetTokens = new Tokens('https://target.test', 'https://target.test/uploads');
    $paths = [];
    $applied = ColumnCodecGrammar::apply_value($canonical, $one, $targetTokens, 'object records', static function ($spec, $path) use (&$paths, $target): string {
        $paths[] = $path;
        return $target['method']['input'];
    });
    wprism_check_same([['method', 'input']], $paths, 'projection preserves the exact binding coordinate');
    wprism_check_same($encode($target), $applied, "$container Apply uses target references and file intent");
    wprism_check_same($canonical, ColumnCodecGrammar::capture_value($applied, $one, $targetTokens, 'recapture', 'data'), "$container recapture is stable");
    $partial = ['method' => ['cursor' => '17', 'mode' => 'local']];
    $partialCanonical = $encode(['method' => ['mode' => 'local']]);
    wprism_check_same($partialCanonical, ColumnCodecGrammar::capture_value($encode($partial), $one, $sourceTokens, 'partial', 'data'),
        'projection preserves absent children without creating reference or input defaults');
    wprism_check_same($partialCanonical, ColumnCodecGrammar::apply_value($partialCanonical, $one, $targetTokens, 'partial'),
        'absent input requires no target binding');
    foreach ([['cursor' => '17'], [], null, ['mode' => 'remote']] as $invalid) {
        wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value($encode(['method' => $invalid]), $one, $sourceTokens, 'invalid', 'data'),
            RuntimeException::class, 'native shape and retained child rules are not waived by projection');
    }
    foreach (['cursor', 'api_key', 'unexpected'] as $field) {
        $bad = $expected;
        $bad['method'][$field] = '17';
        $bindingCalls = 0;
        $binding = static function () use (&$bindingCalls): string { ++$bindingCalls; return 'https://target.test/content/inputs/target.csv'; };
        wprism_check_throws(static fn() => ColumnCodecGrammar::apply_value($encode($bad), $one, $targetTokens, 'canonical', $binding),
            RuntimeException::class, 'excluded canonical fields refuse before target capability lookup', 'excluded canonical');
        wprism_check_same(0, $bindingCalls, 'malformed canonical input never resolves a binding');
    }
}
$sourceTokens = new Tokens('https://source.test', 'https://source.test/uploads');
$db->seedTable('wp_users', [['ID' => 2, 'user_login' => 'record-editor']]);
foreach ([['api_key' => 'private-credential-material'], ['email' => 'private@example.net'], ['cache' => ['password' => 'private-credential-material']]] as $extra) {
    $bad = $native;
    $bad['method'] += $extra;
    wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value(json_encode($bad), $codec, $sourceTokens, 'native private field', 'data'),
        RuntimeException::class, 'discarded native fields retain full privacy checks');
}
$deep = 'discarded';
for ($i = 0; $i < 65; ++$i) $deep = ['cache' => $deep];
foreach ([$deep, array_fill(0, 100001, 0), new stdClass(), "\xff"] as $discarded) {
    $bad = $native;
    $bad['method']['cache'] = $discarded;
    wprism_check_throws(static fn() => AuthoredValueCodec::pii_subject($bad, $contract, false, 'discarded native JSON'),
        RuntimeException::class, 'projection cannot bypass whole-value JSON bounds and shape', 'bounded plain JSON');
}
$secret = $native;
$secret['method']['cache'] = ['api_key' => 'private-credential-material'];
wprism_check_same($secret['method']['cache'], AuthoredValueCodec::secret_subject($secret, $contract, false, 'secret subject')['method']['cache'],
    'input projection leaves discarded native secret subjects byte exact');
$tooDeep = $record;
for ($i = 0; $i < 5; ++$i) $tooDeep = ['class' => 'authored', 'object_fields' => ['nested' => $tooDeep]];
$bad = $manifest;
$bad['column_codecs']['records']['data']['value'] = $tooDeep;
wprism_check_throws(static fn() => $load($bad), RuntimeException::class, 'projected objects retain the existing contract depth limit');
$repo = $scratch . '/repo';
$site = FrozenPolicy::site([$manifest], 3);
$frozen = FrozenPolicy::policy([$manifest], $site);
Canon::write_file($repo . '/site.wprism.json', Canon::encode($site));
$uuid = '11111111-1111-4111-8111-111111111111';
$front = ['uuid' => $uuid, 'table' => 'records', 'columns' => ['name' => 'Record', 'data' => json_encode($expected)], 'meta' => (object) []];
$path = $repo . '/state/tables/records/' . $uuid . '--record.json';
Canon::write_file($path, Canon::encode($front));
wprism_check_same(1, count(RepositoryCompiler::compile($repo, $frozen)->tree()), 'immutable compilation admits projected typed records');
wprism_check_same([], WPrism\Lint::scan_tree($repo . '/state', $frozen), 'projected canonical typed records pass lint');
$bad = $front;
$badValue = $expected;
$badValue['method']['cursor'] = '17';
$bad['columns']['data'] = json_encode($badValue);
Canon::write_file($path, Canon::encode($bad));
wprism_check_throws(static fn() => RepositoryCompiler::compile($repo, $frozen), RuntimeException::class, 'compiler independently refuses a restored canonical cursor');

$blockRecord = $record;
unset($blockRecord['object_fields']['input']);
$blockRecord['record_fields']['fields'] = array_keys($blockRecord['object_fields']);
$blockManifest = ['name' => 'object-records', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'engine_features' => ['block-attribute-values/v1', 'block-record-fields/v1', 'block-value-contracts/v1', 'object-record-fields/v1', 'spec-window/v1'],
    'block_values' => ['fixture/record' => ['data' => $blockRecord]]];
$blockPolicy = $load($blockManifest);
foreach (['object-record-fields/v1', 'block-record-fields/v1', 'block-value-contracts/v1'] as $feature) {
    $bad = $blockManifest;
    $bad['engine_features'] = array_values(array_diff($bad['engine_features'], [$feature]));
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, "block object projection requires $feature");
}
$blockValue = $native['method']; unset($blockValue['input']);
$body = '<!-- wp:fixture/record ' . json_encode(['data' => $blockValue]) . ' /-->';
$captured = Blocks::capture_rewrite($body, $blockPolicy, $sourceTokens);
$expectedBlock = $expected['method']; unset($expectedBlock['input']);
wprism_check_same($expectedBlock, parse_blocks($captured)[0]['attrs']['data'], 'public block Capture composes projection and typed children');
$db->seedTable('wp_users', [['ID' => 82, 'user_login' => 'record-editor']]);
$targetTokens = new Tokens('https://target.test', 'https://target.test/uploads');
$applied = Blocks::apply_rewrite($captured, $blockPolicy, $targetTokens);
$targetBlock = $target['method']; unset($targetBlock['input']);
wprism_check_same($targetBlock, parse_blocks($applied)[0]['attrs']['data'], 'public block Apply rebinds retained typed children');
wprism_check_same($captured, Blocks::capture_rewrite($applied, $blockPolicy, $targetTokens), 'block record projection reaches a fixed point');
$badBlock = $expectedBlock;
$badBlock['cursor'] = '17';
wprism_check_throws(static fn() => Blocks::apply_rewrite('<!-- wp:fixture/record ' . json_encode(['data' => $badBlock]) . ' /-->', $blockPolicy, $targetTokens),
    RuntimeException::class, 'block Apply also refuses excluded canonical fields');
wprism_check_summary('object record fields');
