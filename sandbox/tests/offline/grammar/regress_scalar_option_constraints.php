<?php
declare(strict_types=1);

// An ordinary untyped subkey admitted text that its native integer consumer
// could not read. Exercise the immutable compiler and checked option writer,
// including the locked target state and target-local siblings.
$root = dirname(__DIR__, 4);
if (($argv[1] ?? '') === '--compile-without-wordpress') {
    require_once "$root/sandbox/tests/lib/agent_version.php";
    require_once "$root/sandbox/tests/lib/frozen_policy.php";
    require_once "$root/agent/src/Repository/RepositoryCompiler.php";
    wprism_test_define_agent_versions();
    $input = json_decode(file_get_contents($argv[2] . '/probe.json'), true, flags: JSON_THROW_ON_ERROR);
    $policy = WPrismTest\FrozenPolicy::policy([$input['manifest']], $input['site']);
    try {
        $artifact = WPrism\RepositoryCompiler::compile($argv[2], $policy);
        $result = ['entities' => count($artifact->tree())];
    } catch (RuntimeException $failure) {
        $result = ['refused' => str_contains($failure->getMessage(), 'repository_scalar_value_invalid')];
    }
    echo json_encode($result + ['wordpress' => function_exists('get_option'), 'database' => isset($GLOBALS['wpdb'])], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) {
    require_once "$root/sandbox/tests/lib/$file";
}
wprism_test_define_agent_versions();
require_once "$root/agent/src/Grammar/Tokens.php";
require_once "$root/agent/src/Capture/OptionsCapture.php";
require_once "$root/agent/src/Capture/CaptureSafetyGates.php";
require_once "$root/agent/src/Repository/RepositoryCompiler.php";
require_once "$root/agent/src/Apply/OptionsMaterializer.php";
require_once "$root/agent/src/Review/Lint.php";

use WPrism\ApplyFieldMaterializer;
use WPrism\CacheInvalidationTransaction;
use WPrism\Canon;
use WPrism\CaptureSafetyGates;
use WPrism\Db;
use WPrism\Lint;
use WPrism\NativeDatabaseProfile;
use WPrism\OptionsCapture;
use WPrism\OptionsMaterializer;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\ReferenceShapeGrammar;
use WPrism\RepositoryCompiler;
use WPrism\ScalarValueConstraint;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$integer = ['class' => 'authored', 'lint_ok' => true,
    'value_constraint' => ['type' => 'integer', 'minimum' => -10, 'maximum' => 900]];
$enum = ['class' => 'authored', 'lint_ok' => true, 'value_constraint' => ['enum' => [0, '1', false, null, 'quick']]];
$parent = ['class' => 'env', 'required' => false, 'sub_keys' => ['seconds' => $integer, 'choice' => $enum]];
$manifest = ['name' => 'scalar-option-fixture', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'engine_features' => [ScalarValueConstraint::FEATURE, 'spec-window/v1'],
    'options' => ['fixture_settings' => $parent, 'fixture_runtime' => ['class' => 'runtime']]];
$site = FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION);
$policy = FrozenPolicy::policy([$manifest], $site);

foreach (['feature', 'spec', 'site'] as $fault) {
    $bad = $manifest;
    if ($fault === 'feature') unset($bad['engine_features']);
    if ($fault === 'spec') $bad['spec_version'] = 2;
    wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes($bad, 'fixture', $fault !== 'site'),
        RuntimeException::class, "$fault cannot waive feature authority", ScalarValueConstraint::FEATURE);
}
foreach (['options', 'post_meta', 'term_meta', 'user_meta', 'option_patterns', 'option_name_refs', 'dynamic_options',
    'dynamic-subkey', 'attached-meta', 'table-column', 'post_types', 'taxonomies', 'nested-subkey'] as $scope) {
    $bad = ['spec_version' => 3, 'engine_features' => [ScalarValueConstraint::FEATURE]];
    if (in_array($scope, ['options', 'post_meta', 'term_meta', 'user_meta', 'post_types', 'taxonomies'], true)) $bad[$scope] = ['fixture' => $integer];
    if (in_array($scope, ['option_patterns', 'option_name_refs'], true)) $bad[$scope] = [$integer + ['match' => '^fixture$']];
    if ($scope === 'dynamic_options') $bad[$scope] = ['fixture' => $integer];
    if ($scope === 'dynamic-subkey') $bad['dynamic_options'] = ['fixture' => ['sub_keys' => ['seconds' => $integer]]];
    if ($scope === 'attached-meta') $bad['tables'] = ['fixture' => ['class' => 'authored_snapshot_meta', 'keys' => ['seconds' => $integer]]];
    if ($scope === 'table-column') $bad['tables'] = ['fixture' => ['class' => 'authored_snapshot', 'columns' => ['seconds' => $integer]]];
    if ($scope === 'nested-subkey') $bad['options'] = ['fixture' => ['sub_keys' => ['outer' => $parent]]];
    wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes($bad, 'fixture', true),
        RuntimeException::class, "$scope refuses unsupported placement");
}
foreach ([null, [], ['type' => 'number'], ['type' => 'integer', 'minimum' => '0'], ['type' => 'integer', 'maximum' => null],
    ['type' => 'integer', 'minimum' => 2, 'maximum' => 1], ['type' => 'integer', 'default' => 0],
    ['enum' => []], ['enum' => [1, 1]], ['enum' => ['a.b']], ['enum' => [1.5]], ['enum' => [new stdClass()]],
    ['enum' => range(0, 64)], ['enum' => [str_repeat('a', 129)]], ['enum' => [0], 'minimum' => 0]] as $constraint) {
    $bad = $manifest;
    $bad['options']['fixture_settings']['sub_keys']['seconds']['value_constraint'] = $constraint;
    wprism_check_throws(static fn() => FrozenPolicy::policy([$bad], FrozenPolicy::site([$bad], WPRISM_SPEC_VERSION)),
        RuntimeException::class, 'loader refuses malformed scalar predicates');
}
foreach (['ref', 'cast', 'json_refs', 'key_refs', 'json_encoded', 'plain_data', 'php_containers', 'record_fields', 'text_encoding',
    'sub_keys', 'repeated_rows', 'order_preserving', 'native_value_validation', 'object_fields', 'enum', 'on_unmapped'] as $field) {
    wprism_check_throws(static fn() => ScalarValueConstraint::assert_rule($integer + [$field => null], 'fixture', true),
        RuntimeException::class, "$field cannot combine with a predicate even when null", "cannot combine with $field");
}
foreach (['runtime', 'derived', 'env'] as $class) {
    wprism_check_throws(static fn() => ScalarValueConstraint::assert_rule(array_replace($integer, ['class' => $class]), 'fixture', true),
        RuntimeException::class, "$class cannot acquire authored scalar constraints", 'class authored');
}
foreach ([$parent, ['class' => 'authored'], ['class' => 'env', 'required' => false, 'sub_keys' => ['seconds' => ['class' => 'authored']]]] as $override) {
    $overridden = $site;
    $overridden['policy']['options']['fixture_settings'] = $override;
    wprism_check_throws(static function () use ($manifest, $overridden): void {
        FrozenPolicy::policy([$manifest], $overridden)->option_rule('fixture_settings');
    }, RuntimeException::class, 'site policy cannot erase a manifest predicate');
}
$excluded = $site;
$excluded['policy']['options']['fixture_settings'] = ['class' => 'runtime'];
wprism_check_same('runtime', FrozenPolicy::policy([$manifest], $excluded)->option_rule('fixture_settings')['class'],
    'explicit whole-option exclusion remains available');
$other = ['name' => 'other-fixture', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'option_patterns' => [['match' => '^fixture_settings$', 'class' => 'authored']]];
foreach ([[$manifest, $other], [$other, $manifest]] as $order) {
    wprism_check_throws(static fn() => FrozenPolicy::policy($order, FrozenPolicy::site($order, WPRISM_SPEC_VERSION))->option_rule('fixture_settings'),
        RuntimeException::class, 'neither pin order may hide a competing predicate owner', 'conflicting classification owners');
}
foreach ([true, false] as $staticOwner) {
    $declared = $manifest;
    $declared['interpreter'] = 'scalar-option-fixture';
    if (!$staticOwner) unset($declared['options']['fixture_settings']);
    $loaded = FrozenPolicy::policy([$declared], FrozenPolicy::site([$declared], WPRISM_SPEC_VERSION));
    $interpreter = new class($staticOwner ? ['class' => 'authored'] : $parent) {
        public function __construct(private array $answer) {}
        public function option_rule(string $key, array $all): ?array { return $key === 'fixture_settings' ? $this->answer : null; }
    };
    (new ReflectionProperty(Policy::class, 'interpreterInstances'))->setValue($loaded, ['scalar-option-fixture' => $interpreter]);
    wprism_check_throws(static fn() => $loaded->meta_rule_for_option('fixture_settings', []), RuntimeException::class,
        'interpreter cannot introduce or erase static predicates', 'static scalar value constraint');
}

$scratch = sys_get_temp_dir() . '/wprism-scalar-options-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/options', 0700, true);

$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
WpStore::reset()->seedOptions(['home' => 'https://fixture.test']);
$tokens = new Tokens('https://fixture.test', 'https://fixture.test/wp-content/uploads');
$gates = new CaptureSafetyGates($scratch);
$capture = static function () use ($policy, $tokens, $gates): array {
    $result = (new OptionsCapture($policy, $tokens, static function (string $section, string $key, mixed $value, array $rule) use ($gates): void {
        $gates->guardSecret($section, $key, $value, $rule);
        $gates->guardPersonalData($section, $key, $value, $rule);
    }, static function (): never { throw new LogicException('no identity refs'); },
        static function (): never { throw new LogicException('no table refs'); }))->capture(false);
    $gates->assertOptions($result['unclassified'], $result['unscoped_refs'], $result['unscoped_option_name_refs'], $tokens);
    return $result['document'];
};
$database = static function (mixed $settings): FakeWpdb {
    return FakeWpdb::install()->enableInformationSchema()->seedTable('wp_options', [
        ['option_id' => 1, 'option_name' => 'fixture_settings', 'option_value' => serialize($settings), 'autoload' => 'auto'],
        ['option_id' => 2, 'option_name' => 'fixture_runtime', 'option_value' => 'local-runtime', 'autoload' => 'no'],
    ])->setColumns('wp_options', ['option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)'])
        ->setAutoIncrement('wp_options', 3, 'option_id')->setUniqueKey('wp_options', ['option_name'])
        ->setIndexes('wp_options', [['Key_name' => 'option_name', 'Column_name' => 'option_name', 'Seq_in_index' => 1,
            'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE']])->setTableEngine('wp_options', 'InnoDB');
};
$document = static fn(array $values): array => OptionState::document(['fixture_settings' => OptionState::present($values, 'auto')]);
$compile = static function (array $values) use ($scratch, $policy, $document) {
    Canon::write_file($scratch . '/state/options/core.json', Canon::encode($document($values)));
    return RepositoryCompiler::compile($scratch, $policy);
};
$fields = new ApplyFieldMaterializer($policy, $tokens);
$materializer = new OptionsMaterializer($policy, $tokens, $fields);
$write = static function (array $state, bool $abort = false) use ($fields, $materializer): array {
    Db::start_repeatable_read('scalar option predicates', new NativeDatabaseProfile(['wp_options'], ['wp_options']));
    $fields->begin_authored_transaction();
    $materializer->begin_authored_transaction();
    CacheInvalidationTransaction::begin();
    try {
        $warnings = [];
        $materializer->apply_options($state, false, $warnings);
        if ($abort) throw new RuntimeException('fixture post-write failure');
        Db::commit('scalar option commit');
        $materializer->commit_authored_transaction();
        CacheInvalidationTransaction::finish();
        return $warnings;
    } catch (Throwable $failure) {
        $materializer->rollback_authored_transaction();
        Db::rollback_after_failure($failure, 'scalar option rollback');
        throw $failure;
    } finally {
        $materializer->end_authored_transaction();
        $fields->end_authored_transaction();
        CacheInvalidationTransaction::end();
    }
};
$local = ['other_module' => ['seconds' => 'private local value']];
foreach ([-10, 0, 900] as $seconds) foreach ($enum['value_constraint']['enum'] as $choice) {
    $values = compact('seconds', 'choice');
    $db = $database($values + $local);
    $before = $db->rows('wp_options');
    wprism_check_same($document($values), $capture(), 'Capture preserves allowed scalar types and omits local siblings');
    $queryCount = count($db->queries());
    $artifact = $compile($values);
    wprism_check_same($queryCount, count($db->queries()), 'compilation validates without database reads');
    wprism_check_same([], $write($artifact->tree()['options/core']['data']), 'checked Apply accepts inclusive bounds and literal choices');
    wprism_check_same($before, $db->rows('wp_options'), 'repeat preserves exact option bytes, autoload and runtime sibling');
    wprism_check_same($document($values), $capture(), 'recapture returns the exact constrained document');
}
$valid = ['seconds' => 600, 'choice' => 'quick'];
foreach ([['seconds', 'invalid-seconds'], ['seconds', '600'], ['seconds', 600.5], ['seconds', true], ['seconds', null],
    ['seconds', []], ['seconds', -11], ['seconds', 901], ['choice', 1], ['choice', '0'], ['choice', true], ['choice', ['quick']]] as [$key, $value]) {
    $bad = array_replace($valid, [$key => $value]);
    $db = $database($bad + $local);
    $before = $db->rows('wp_options');
    wprism_check_throws($capture, RuntimeException::class, "$key invalid native value refuses Capture", 'scalar value constraint');
    wprism_check_same($before, $db->rows('wp_options'), 'Capture refusal is byte-still');
    wprism_check_throws(static fn() => $compile($bad), RuntimeException::class, "$key invalid canonical value refuses compilation", 'repository_scalar_value_invalid');
    wprism_check_throws(static fn() => Lint::scan_tree($scratch . '/state', $policy), RuntimeException::class,
        'lint cannot be waived by lint_ok on an invalid predicate', 'scalar value constraint');
    wprism_check_throws(static fn() => $write($document($valid)), RuntimeException::class,
        'locked invalid authored preimage refuses replacement', 'scalar value constraint');
    wprism_check_same($before, $db->rows('wp_options'), 'target preimage refusal preserves every byte');
    $db = $database($valid + $local);
    $before = $db->rows('wp_options');
    wprism_check_throws(static fn() => $write($document($bad)), RuntimeException::class,
        'lower materializer rejects invalid incoming values', 'scalar value constraint');
    wprism_check_same($before, $db->rows('wp_options'), 'incoming refusal preserves every byte');
}
$db = $database(['seconds' => 0, 'choice' => false] + $local);
$before = $db->rows('wp_options');
wprism_check_throws(static fn() => $write($document($valid), true), RuntimeException::class,
    'post-write failure rolls back the checked mutation', 'fixture post-write failure');
wprism_check_same($before, $db->rows('wp_options'), 'rollback restores exact scalar spellings');
wprism_check_same([], $write($document($valid)), 'fresh transaction succeeds after rollback');
wprism_check_same($document($valid), $capture(), 'successful Apply recaptures changed scalar values');
$warnings = $write($document(['seconds' => 600]));
wprism_check_same(1, count($warnings), 'omitted authored choice removes exactly one subkey');
wprism_check_same($document(['seconds' => 600]), $capture(), 'absence does not synthesize a default');
wprism_check_same($local['other_module'], unserialize($db->rows('wp_options')[0]['option_value'])['other_module'], 'foreign sibling survives removal');
$before = $db->rows('wp_options');
wprism_check_same([], $write(OptionState::document(['fixture_settings' => OptionState::absent()])), 'whole-option absence retains established no-op behavior');
wprism_check_same($before, $db->rows('wp_options'), 'absent option does not remove the shared row');

// The previous untyped declaration admits the same harmful value. Its native
// PHP consumer refuses, without executing the plugin or touching a live site.
$untyped = $manifest;
$untyped['engine_features'] = ['spec-window/v1'];
foreach ($untyped['options']['fixture_settings']['sub_keys'] as &$rule) unset($rule['value_constraint']);
unset($rule);
$untypedSite = FrozenPolicy::site([$untyped], WPRISM_SPEC_VERSION);
$untypedPolicy = FrozenPolicy::policy([$untyped], $untypedSite);
$bad = ['seconds' => 'invalid-seconds', 'choice' => 'quick'];
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($untypedSite));
Canon::write_file($scratch . '/state/options/core.json', Canon::encode($document($bad)));
wprism_check_same(1, count(RepositoryCompiler::compile($scratch, $untypedPolicy)->tree()), 'untyped control reproduces compiler admission of the native failure');
wprism_check_throws(static fn() => set_time_limit($bad['seconds']), TypeError::class, 'the native integer consumer rejects the admitted control value');
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
Canon::write_file($scratch . '/probe.json', Canon::encode(['manifest' => $manifest, 'site' => $site]));
foreach ([true, false] as $invalid) {
    // Keep an explicit JSON float spelling; Canon::encode normally emits the
    // integer spelling of an integral float before a file reaches compilation.
    $state = $document($invalid ? ['seconds' => 600.0, 'choice' => 'quick'] : $valid);
    Canon::write_file($scratch . '/state/options/core.json', json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    $child = proc_open([PHP_BINARY, __FILE__, '--compile-without-wordpress', $scratch],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($child)) throw new RuntimeException('cannot start isolated compiler fixture');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    wprism_check_same(0, proc_close($child), 'isolated compiler fixture completes');
    wprism_check_same('', $stderr, 'isolated compiler emits no diagnostics');
    wprism_check_same(($invalid ? ['refused' => true] : ['entities' => 1]) + ['wordpress' => false, 'database' => false],
        json_decode($stdout, true), 'host compiler applies exact type constraints without WordPress or a database');
}
wprism_check_summary('scalar option constraints');
