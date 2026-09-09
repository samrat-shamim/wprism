<?php
declare(strict_types=1);

// Native style writers mix ordered PHP arrays and stdClass. Canonical JSON
// erases both distinctions; this suite crosses capture, compilation and the
// checked option transaction to prove the negotiated codec preserves them.
$root = dirname(__DIR__, 4);
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/agent_version.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once $root . '/agent/src/Kernel/PhpContainerValue.php';
require_once $root . '/agent/src/Capture/OptionsCapture.php';
require_once $root . '/agent/src/Capture/CaptureSafetyGates.php';
require_once $root . '/agent/src/Apply/ApplyFieldMaterializer.php';
require_once $root . '/agent/src/Apply/OptionsMaterializer.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Review/StructuredReferenceScanner.php';
wprism_test_define_agent_versions();

use WPrism\ApplyFieldMaterializer;
use WPrism\CacheInvalidationTransaction;
use WPrism\Canon;
use WPrism\CaptureSafetyGates;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\OptionsCapture;
use WPrism\OptionsMaterializer;
use WPrism\OptionState;
use WPrism\PhpContainerValue;
use WPrism\Policy;
use WPrism\ReferenceShapeGrammar;
use WPrism\RepositoryCompiler;
use WPrism\StructuredReferenceCodec;
use WPrism\StructuredReferenceScanner;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$uuid4 = '99999999-9999-4999-8999-999999999999';
$uuid9 = '11111111-1111-4111-8111-111111111111';
$token4 = '{{post:' . $uuid4 . '}}';
$token9 = '{{post:' . $uuid9 . '}}';
$keyRule = ['path' => '$.root.items.posts', 'kind' => 'post', 'container' => 'php'];
$rule = ['class' => 'authored', 'php_containers' => true, 'key_refs' => $keyRule];
$plainRule = ['class' => 'authored', 'plain_data' => true];
$manifest = ['name' => 'php-container-fixture', 'spec_version' => 3,
    'engine_features' => [PhpContainerValue::FEATURE, 'spec-window/v1'], 'option_autoload' => 'preserve',
    'post_types' => ['page' => ['class' => 'authored']],
    'options' => ['fixture_a_plain' => $plainRule, 'fixture_styles' => $rule,
        'fixture_b_text' => $plainRule + ['php_containers' => true],
        'fixture_c_selected' => ['class' => 'authored', 'php_containers' => true,
            'json_refs' => [['path' => '$.root.items.selected', 'kind' => 'post']]],
        'fixture_environment' => ['class' => 'env', 'required' => false], 'fixture_runtime' => ['class' => 'runtime']]];
$site = FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['page'];
$site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy([$manifest], $site);
wprism_check_same($rule + ['autoload' => 'preserve'], $policy->option_rule('fixture_styles'), 'policy retains the negotiated whole-option container contract');
foreach (['feature', 'spec', 'site', 'meta', 'pattern-meta', 'name-ref', 'dynamic', 'subkey'] as $fault) {
    $bad = $manifest;
    if ($fault === 'feature') $bad['engine_features'] = ['spec-window/v1'];
    if ($fault === 'spec') $bad['spec_version'] = 2;
    if ($fault === 'meta') $bad['post_meta'] = ['fixture' => $rule];
    if ($fault === 'pattern-meta') $bad['post_meta_patterns'] = [$rule + ['pattern' => '^fixture_']];
    if ($fault === 'name-ref') $bad['option_name_refs'] = [$rule + ['pattern' => '^fixture_([0-9]+)$']];
    if ($fault === 'dynamic') $bad['dynamic_options'] = ['fixture' => $rule];
    if ($fault === 'subkey') $bad['options']['fixture_styles'] = ['class' => 'authored', 'sub_keys' => ['nested' => $rule]];
    wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes($bad, 'fixture', $fault !== 'site'),
        RuntimeException::class, "container declaration refuses ineligible $fault", PhpContainerValue::FEATURE);
}
foreach (['class', 'false', 'ref', 'cast', 'json_encoded', 'sub_keys', 'order_preserving', 'repeated_rows', 'untyped-map', 'no-map-path', 'no-values'] as $fault) {
    $bad = $manifest;
    $badRule = $rule;
    if ($fault === 'class') $badRule['class'] = 'runtime';
    elseif ($fault === 'false') $badRule['php_containers'] = false;
    elseif ($fault === 'untyped-map') unset($badRule['key_refs']['container']);
    elseif ($fault === 'no-map-path') unset($badRule['key_refs']['path']);
    elseif ($fault === 'no-values') unset($badRule['key_refs']);
    else $badRule[$fault] = true;
    $bad['options']['fixture_styles'] = $badRule;
    wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes($bad, 'fixture', true),
        RuntimeException::class, "container contract rejects incompatible $fault");
}
foreach (['exact', 'pattern'] as $scope) {
    $declared = $manifest;
    if ($scope === 'pattern') {
        unset($declared['options']['fixture_styles']);
        $declared['option_patterns'] = [$rule + ['match' => '^fixture_styles$']];
    }
    foreach (['authored', 'runtime', 'env', 'derived'] as $classification) {
        $overridden = FrozenPolicy::site([$declared], WPRISM_SPEC_VERSION);
        $overridden['policy']['options']['fixture_styles'] = ['class' => $classification];
        if ($classification === 'authored') $overridden['policy']['options']['fixture_styles']['autoload'] = 'preserve';
        if ($classification === 'env') $overridden['policy']['options']['fixture_styles']['required'] = false;
        if ($classification === 'authored') {
            wprism_check_throws(static fn() => FrozenPolicy::policy([$declared], $overridden), RuntimeException::class,
                "$scope site override cannot silently strip the storage codec", 'cannot replace');
        } else {
            $excluded = FrozenPolicy::policy([$declared], $overridden);
            wprism_check_same($classification, $excluded->option_rule('fixture_styles')['class'],
                "$scope whole-option $classification exclusion remains available");
        }
    }
}
$dynamicPolicy = static function (array $answer, bool $staticOwner) use ($manifest): Policy {
    $declared = $manifest;
    $declared['interpreter'] = 'php-container-fixture';
    if (!$staticOwner) unset($declared['options']['fixture_styles']);
    $loaded = FrozenPolicy::policy([$declared], FrozenPolicy::site([$declared], WPRISM_SPEC_VERSION));
    $interpreter = new class($answer) {
        public function __construct(private array $rule) {}
        public function option_rule(string $key, array $all): ?array { return $key === 'fixture_styles' ? $this->rule : null; }
        public function post_meta_rule(string $key, array $all): ?array { return $this->option_rule($key, $all); }
    };
    (new ReflectionProperty(Policy::class, 'interpreterInstances'))->setValue($loaded, ['php-container-fixture' => $interpreter]);
    return $loaded;
};
foreach ([$rule, ['class' => 'authored'], ['class' => 'runtime']] as $answer) {
    $dynamic = $dynamicPolicy($answer, true);
    wprism_check_throws(static fn() => $dynamic->meta_rule_for_option('fixture_styles', []), RuntimeException::class,
        'an interpreter cannot add a second authority over a static container codec', 'static PHP container');
}
$dynamic = $dynamicPolicy($rule, false);
foreach (['meta_rule_for_option', 'meta_rule_for_post'] as $hook) {
    wprism_check_throws(static fn() => $dynamic->$hook('fixture_styles', []), RuntimeException::class,
        "$hook cannot introduce an executable container codec", 'static PHP container');
}

$native = static function (string $home, int $offset = 0): array {
    $numeric = new stdClass();
    $numeric->{'9'} = 'numeric property';
    $numeric->{'01'} = 'leading-zero property';
    return ['posts' => [4 + $offset => (object) ['z' => $home . '/asset.png', 'a' => (object) [
        'empty_array' => [], 'empty_object' => new stdClass(), 'float' => 2.0, 'negative_zero' => -0.0,
        'large_float' => 1.1e99, 'boolean' => false, 'null' => null, 'text' => 'বাংলা E:21:"InitRiskMissing:Value";',
        'kind' => 'array', 'order' => ['literal'], 'items' => ['literal' => 'authored'], 'numeric' => $numeric]],
        9 + $offset => (object) ['content' => 'Second']],
        'widgets' => (object) ['z' => ['b' => 2, 'a' => 1]], 'templates' => [], 'undefined' => new stdClass()];
};
$raw = serialize($native('https://source.test'));
$packed = PhpContainerValue::capture_raw($raw, 'fixture');
$roundtrip = PhpContainerValue::restore(Canon::decode(Canon::encode($packed)), 'fixture');
wprism_check_same($raw, serialize($roundtrip), 'canonical JSON preserves native types, key order, floats, UTF-8 and metadata-like authored keys');
foreach ([[], new stdClass(), [PHP_INT_MIN => 'min', PHP_INT_MAX => 'max', '01' => 'string', '' => 'empty']] as $value) {
    $bytes = serialize($value);
    wprism_check_same($bytes, serialize(PhpContainerValue::restore(Canon::decode(Canon::encode(PhpContainerValue::capture_raw($bytes, 'fixture'))), 'fixture')),
        'root container and integer-boundary keys retain exact native serialization');
}
$warnings = [];
$warn = static function (string $message) use (&$warnings): void { $warnings[] = $message; };
$captureId = static fn(int $id, string $kind): ?string => $kind !== 'post' ? null : match ($id) { 4 => $token4, 9 => $token9, default => null };
$portable = StructuredReferenceCodec::capture($packed, [], $keyRule, $captureId, $warn);
$rewritten = StructuredReferenceCodec::apply($portable, [], $keyRule, static fn(string $token): int => $token === $token4 ? 804 : 809);
wprism_check_same(serialize($native('https://source.test', 800)), serialize(PhpContainerValue::restore($rewritten, 'fixture')),
    'ordered key references remap divergent IDs without sorting the native map');
$dropped = StructuredReferenceCodec::capture($packed, [], $keyRule,
    static fn(int $id): ?string => $id === 4 ? $token4 : null, $warn);
wprism_check_same([['key' => $token4]], $dropped['root']['items']['posts']['order'], 'dangling key removal updates the ordering record atomically');
wprism_check_same([$token4], array_keys($dropped['root']['items']['posts']['items']), 'dangling key removal drops the corresponding value');
wprism_check_same(1, count($warnings), 'one dangling entry produces one existing generic warning');
wprism_check_same([], StructuredReferenceScanner::scan($portable, 'fixture', 'option', [], $keyRule, static fn(): ?array => null),
    'lint recognizes the declared typed map tokens');
wprism_check_throws(static fn() => StructuredReferenceCodec::apply($portable, [], $keyRule, static fn(): int => 804),
    RuntimeException::class, 'colliding resolved keys refuse instead of losing an entry', 'collided');
foreach ([0, -1, '04', '4x', (string) PHP_INT_MAX . '0'] as $key) {
    $invalidKeys = PhpContainerValue::capture_raw(serialize(['posts' => [$key => new stdClass()]]), 'fixture');
    wprism_check_throws(static fn() => StructuredReferenceCodec::capture($invalidKeys, [], $keyRule, $captureId, $warn),
        RuntimeException::class, 'typed reference maps refuse malformed native IDs before coercion', 'canonical positive integer');
}
$objectMap = new stdClass();
$objectMap->{'4'} = new stdClass();
$objectPacked = PhpContainerValue::capture_raw(serialize(['posts' => $objectMap]), 'fixture');
$objectPortable = StructuredReferenceCodec::capture($objectPacked, [], $keyRule, $captureId, $warn);
$objectApplied = StructuredReferenceCodec::apply($objectPortable, [], $keyRule, static fn(): int => 804);
$objectExpected = new stdClass();
$objectExpected->{'804'} = new stdClass();
wprism_check_same(serialize(['posts' => $objectExpected]), serialize(PhpContainerValue::restore($objectApplied, 'fixture')),
    'ID-keyed stdClass maps retain string property keys after reference materialization');

final class ContainerExecutableFixture {
    public static int $wakeups = 0;
    public function __wakeup(): void { ++self::$wakeups; }
}
$reference = 'shared';
$referenced = [&$reference, &$reference];
$recursive = [];
$recursive['self'] = & $recursive;
$shared = new stdClass();
$cycle = new stdClass();
$cycle->self = $cycle;
$mangled = (object) ["\0private" => 'hidden'];
$deep = [];
for ($i = 0; $i < 66; ++$i) $deep = [$deep];
$badNative = ['class' => [new ContainerExecutableFixture()], 'references' => $referenced, 'recursive' => $recursive,
    'shared-objects' => [$shared, $shared], 'object-cycle' => [$cycle], 'mangled' => [$mangled],
    'binary' => ["\xff"], 'nonfinite' => [INF], 'depth' => $deep, 'nodes' => array_fill(0, PhpContainerValue::MAX_NODES, 0)];
foreach ($badNative as $label => $value) {
    wprism_check_throws(static fn() => PhpContainerValue::capture_raw(serialize($value), 'fixture'), RuntimeException::class,
        "native $label is refused before it can become a repository value");
}
wprism_check_same(0, ContainerExecutableFixture::$wakeups, 'arbitrary native class hooks never execute');
$autoloads = [];
$loader = static function (string $name) use (&$autoloads): void { $autoloads[] = $name; };
spl_autoload_register($loader);
try {
    foreach (['a:1:{i:0;E:22:"ContainerMissing:Value";}', $raw . 'trailing', 's:3:"abc";', 'a:1:{', str_repeat('x', PhpContainerValue::MAX_BYTES + 1)] as $bytes) {
        wprism_check_throws(static fn() => PhpContainerValue::capture_raw($bytes, 'fixture'), RuntimeException::class,
            'unsafe, malformed, scalar or oversized native input refuses');
    }
} finally { spl_autoload_unregister($loader); }
wprism_check_same([], $autoloads, 'container capture never invokes an enum autoloader');

$scratch = sys_get_temp_dir() . '/wprism-php-containers-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/posts/page', 0700, true);
mkdir($scratch . '/state/options', 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path);
    return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
foreach ([$uuid4 => 'first', $uuid9 => 'second'] as $uuid => $slug) {
    $front = ['author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-08 00:00:00',
        'date_gmt' => '2026-09-08 00:00:00', 'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
        'modified' => '2026-09-08 00:00:00', 'modified_gmt' => '2026-09-08 00:00:00', 'parent' => null,
        'ping_status' => 'closed', 'slug' => $slug, 'status' => 'publish', 'terms' => (object) [],
        'title' => $slug, 'type' => 'page', 'uuid' => $uuid];
    Canon::write_file($scratch . '/state/posts/page/' . $uuid . '--' . $slug . '.md', Canon::post_file($front, $slug));
}
$database = static function (int $offset, array $rows) use ($uuid4, $uuid9): FakeWpdb {
    return FakeWpdb::install()->enableInformationSchema()->seedTable('wp_options', $rows)
        ->setColumns('wp_options', ['option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)'])
        ->setAutoIncrement('wp_options', 10, 'option_id')->setUniqueKey('wp_options', ['option_name'])
        ->setIndexes('wp_options', [['Key_name' => 'option_name', 'Column_name' => 'option_name', 'Seq_in_index' => 1,
            'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE']])->setTableEngine('wp_options', 'InnoDB')
        ->seedTable('wp_wprism_map', [
            ['uuid' => $uuid4, 'entity_type' => 'post:page', 'id_kind' => 'post', 'local_id' => 4 + $offset],
            ['uuid' => $uuid9, 'entity_type' => 'post:page', 'id_kind' => 'post', 'local_id' => 9 + $offset]])
        ->setColumns('wp_wprism_map', ['uuid' => 'varchar(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned'])
        ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id'])
        ->setTableEngine('wp_wprism_map', 'InnoDB');
};
$captureOptions = static function (string $home, ?Policy $capturePolicy = null) use ($policy, $scratch): array {
    WpStore::reset()->seedOptions(['home' => $home]);
    $tokens = new Tokens($home, $home . '/wp-content/uploads');
    $gates = new CaptureSafetyGates($scratch);
    $reader = new OptionsCapture($capturePolicy ?? $policy, $tokens,
        static function (string $section, string $key, mixed $value, array $rule) use ($gates): void {
            $gates->guardSecret($section, $key, $value, $rule);
            $gates->guardPersonalData($section, $key, $value, $rule);
        }, static function (): never { throw new LogicException('container fixture has no scalar reference scope'); },
        static function (): never { throw new LogicException('container fixture has no scalar reference existence'); });
    $result = $reader->capture(false);
    $gates->assertOptions($result['unclassified'], $result['unscoped_refs'], $result['unscoped_option_name_refs'], $tokens);
    return $result['document'];
};
$localRows = [
    ['option_id' => 3, 'option_name' => 'fixture_environment', 'option_value' => 'local-environment', 'autoload' => 'off'],
    ['option_id' => 4, 'option_name' => 'fixture_runtime', 'option_value' => 'local-runtime', 'autoload' => 'auto-off']];
$sourceDb = $database(0, [
    ['option_id' => 1, 'option_name' => 'fixture_a_plain', 'option_value' => 'plain source', 'autoload' => 'yes'],
    ['option_id' => 2, 'option_name' => 'fixture_styles', 'option_value' => $raw, 'autoload' => 'auto'],
    ['option_id' => 5, 'option_name' => 'fixture_b_text', 'option_value' => serialize((object) ['url' => 'https://source.test/text/', 'empty' => []]), 'autoload' => 'off'],
    ['option_id' => 6, 'option_name' => 'fixture_c_selected', 'option_value' => serialize(['selected' => 4, 'text' => 'https://source.test/selected/']), 'autoload' => 'no'], ...$localRows]);
$sourceBefore = $sourceDb->rows('wp_options');
$document = $captureOptions('https://source.test');
$portable = OptionState::values($document)['fixture_styles'];
wprism_check_same([$token4, $token9], array_column($portable['root']['items']['posts']['order'], 'key'),
    'real capture records native map order using canonical identity tokens');
wprism_check_same('{{home}}/asset.png', $portable['root']['items']['posts']['items'][$token4]['items']['z'],
    'the existing text codec tokenizes nested container URLs');
wprism_check_same($sourceBefore, $sourceDb->rows('wp_options'), 'capture does not mutate any native row');
foreach (['class', 'references', 'recursive', 'binary', 'nonfinite'] as $fault) {
    $badRows = $sourceBefore;
    $badRows[1]['option_value'] = serialize($badNative[$fault]);
    $sourceDb->seedTable('wp_options', $badRows);
    wprism_check_throws(static fn() => $captureOptions('https://source.test'), RuntimeException::class,
        "real authored-option capture refuses unsafe $fault");
    wprism_check_same($badRows, $sourceDb->rows('wp_options'), 'failed capture leaves all source rows untouched');
}
$sourceDb->seedTable('wp_options', $sourceBefore);
$patternManifest = $manifest;
unset($patternManifest['options']['fixture_styles']);
$patternManifest['option_patterns'] = [$rule + ['match' => '^fixture_styles$']];
$patternManifest['option_namespaces'] = [['match' => '^fixture_styles$']];
$patternPolicy = FrozenPolicy::policy([$patternManifest], FrozenPolicy::site([$patternManifest], WPRISM_SPEC_VERSION));
wprism_check_same($document, $captureOptions('https://source.test', $patternPolicy), 'pattern-owned options use the same typed capture path');
$compile = static function (array $value) use ($scratch, $policy) {
    Canon::write_file($scratch . '/state/options/core.json', Canon::encode($value));
    return RepositoryCompiler::compile($scratch, $policy);
};
$queriesBeforeCompile = $sourceDb->queries();
$artifact = $compile($document);
wprism_check_same($queriesBeforeCompile, $sourceDb->queries(), 'immutable compilation makes no target database query');
wprism_check_same(Canon::encode($document), Canon::encode($artifact->tree()['options/core']['data']),
    'immutable compilation retains the complete canonical option document');
$malformed = [];
foreach (['format', 'kind', 'duplicate-order', 'missing-order', 'extra-item', 'object', 'float', 'raw-id', 'wrong-kind', 'secret', 'pii'] as $fault) {
    $bad = $portable;
    if ($fault === 'format') $bad['format'] = 'unreviewed';
    if ($fault === 'kind') $bad['root']['kind'] = 'Executable';
    if ($fault === 'duplicate-order') $bad['root']['order'][1] = $bad['root']['order'][0];
    if ($fault === 'missing-order') array_pop($bad['root']['order']);
    if ($fault === 'extra-item') $bad['root']['items']['extra'] = 'orphan';
    if ($fault === 'object') $bad['root']['items']['templates'] = new stdClass();
    if ($fault === 'float') $bad['root']['items']['templates'] = ['kind' => 'float', 'value' => '2.00'];
    if (in_array($fault, ['raw-id', 'wrong-kind'], true)) {
        $replacement = $fault === 'raw-id' ? 4 : '{{term:' . $uuid4 . '}}';
        $bad['root']['items']['posts']['items'][$replacement] = $bad['root']['items']['posts']['items'][$token4];
        unset($bad['root']['items']['posts']['items'][$token4]);
        $bad['root']['items']['posts']['order'][0]['key'] = $replacement;
    }
    if (in_array($fault, ['secret', 'pii'], true)) {
        $bad['root']['items']['posts']['items'][$token4]['items']['z'] = $fault === 'secret'
            ? 'sk_live_' . str_repeat('a', 32) : 'person@example.test';
    }
    $badDocument = $document;
    $badDocument['records']['fixture_styles']['value'] = $bad;
    $malformed[$fault] = $badDocument;
    $queriesBeforeRefusal = $sourceDb->queries();
    wprism_check_throws(static fn() => $compile($badDocument), RuntimeException::class,
        "immutable compilation refuses $fault without target writes");
    wprism_check_same($sourceBefore, $sourceDb->rows('wp_options'), 'compiler refusal leaves every database row unchanged');
    wprism_check_same($queriesBeforeRefusal, $sourceDb->queries(), 'malformed immutable revisions refuse without database contact');
}
$artifact = $compile($document);
$targetHome = 'https://a-longer-target.example.test';
$targetDb = $database(800, $localRows);
WpStore::reset()->seedOptions(['home' => $targetHome]);
$targetTokens = new Tokens($targetHome, $targetHome . '/wp-content/uploads');
$fields = new ApplyFieldMaterializer($policy, $targetTokens);
$materializer = new OptionsMaterializer($policy, $targetTokens, $fields);
$write = static function (array $desired) use ($materializer, $fields): void {
    Db::start_repeatable_read('container fixture transaction', new NativeDatabaseProfile(['wp_options', 'wp_wprism_map'], ['wp_options']));
    $fields->begin_authored_transaction();
    $materializer->begin_authored_transaction();
    CacheInvalidationTransaction::begin();
    try {
        $warnings = [];
        $materializer->apply_options($desired, false, $warnings);
        Db::commit('container fixture commit');
        $materializer->commit_authored_transaction();
        CacheInvalidationTransaction::finish();
        wprism_check_same([], $warnings, 'checked container apply has no warnings');
    } catch (Throwable $failure) {
        Db::rollback('container fixture rollback');
        throw $failure;
    } finally {
        $materializer->end_authored_transaction();
        $fields->end_authored_transaction();
        CacheInvalidationTransaction::end();
    }
};
$beforeFailure = $targetDb->rows('wp_options');
$mapBefore = $targetDb->rows('wp_wprism_map');
$sawEarlierWrite = false;
$targetDb->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (&$sawEarlierWrite): ?string {
    if (str_starts_with($sql, 'INSERT INTO') && str_contains($sql, 'fixture_styles')) {
        $sawEarlierWrite = in_array('fixture_a_plain', array_column($db->rows('wp_options'), 'option_name'), true);
        return 'injected late typed-option insert failure';
    }
    return null;
});
wprism_check_throws(static fn() => $write($document), RuntimeException::class, 'late checked SQL failure is loud');
wprism_check($sawEarlierWrite, 'failure occurs after an earlier authored option was written');
wprism_check_same($beforeFailure, $targetDb->rows('wp_options'), 'late failure rolls back every earlier authored and local row');
wprism_check_same($mapBefore, $targetDb->rows('wp_wprism_map'), 'late failure preserves the identity ledger');
$targetDb->onQuery(null);
$write($artifact->tree()['options/core']['data']);
$targetValues = array_column($targetDb->rows('wp_options'), 'option_value', 'option_name');
wprism_check_same(serialize($native($targetHome, 800)), $targetValues['fixture_styles'],
    'checked SQL recomputes serialization lengths while preserving native types, order, remapped IDs and URLs');
wprism_check_same(serialize((object) ['url' => $targetHome . '/text/', 'empty' => []]), $targetValues['fixture_b_text'],
    'plain_data container options reuse URL rebinding without a fake reference declaration');
wprism_check_same(serialize(['selected' => 804, 'text' => $targetHome . '/selected/']), $targetValues['fixture_c_selected'],
    'ordinary json_refs compose with the typed container and URL codecs');
wprism_check_same('local-environment', $targetValues['fixture_environment'], 'apply preserves target environment values');
wprism_check_same('local-runtime', $targetValues['fixture_runtime'], 'apply preserves target runtime values');
$beforeRepeat = $targetDb->rows('wp_options');
$write($document);
wprism_check_same($beforeRepeat, $targetDb->rows('wp_options'), 'repeated apply preserves exact target rows and autoload bytes');
wprism_check_same(Canon::encode($document), Canon::encode($captureOptions($targetHome)), 'full target option recapture is canonically identical to source');
foreach ($malformed as $fault => $badDocument) {
    if (in_array($fault, ['secret', 'pii'], true)) continue; // The immutable compiler owns publication privacy policy.
    wprism_check_throws(static fn() => $write($badDocument), RuntimeException::class, "SQL boundary independently refuses $fault");
    wprism_check_same($beforeRepeat, $targetDb->rows('wp_options'), 'malformed apply leaves the complete target unchanged');
}

wprism_check_summary('PHP container values');
