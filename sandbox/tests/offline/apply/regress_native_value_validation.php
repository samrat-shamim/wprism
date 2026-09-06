<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/agent_version.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Code/Code.php';
require_once $root . '/agent/src/Code/CodeStateContract.php';
require_once $root . '/agent/src/Repository/RepositoryAuthorization.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
wprism_test_define_agent_versions();

use WPrism\ApplyFieldMaterializer;
use WPrism\ApplyPlanBuilder;
use WPrism\CacheInvalidationTransaction;
use WPrism\Canon;
use WPrism\EntityMetaCapture;
use WPrism\NativeValueValidation as Native;
use WPrism\Policy;
use WPrism\ReferenceShapeGrammar;
use WPrism\RepositoryCompiler;
use WPrism\RepositoryValueValidation;
use WPrism\Tokens;
use WPrism\UserMetaCapture;
use WPrism\UserMetaMaterializer;
use WPrism\UserMetaState;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$rule = ['class' => 'authored', Native::FIELD => Native::KSES];
$repeated = $rule + ['repeated_rows' => ['cardinality' => 'one_or_more', 'duplicates' => 'forbid', 'order' => 'preserve']];
$manifest = ['name' => 'native-fixture', 'spec_version' => 3, 'engine_features' => [Native::FEATURE, 'spec-window/v1'],
    'post_meta' => ['bio' => $rule, 'many' => $repeated], 'term_meta' => ['bio' => $rule, 'many' => $repeated],
    'user_meta' => ['bio' => $rule + ['allow_pii' => true, 'missing_user' => 'block']]];
$site = FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = $site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy([$manifest], $site);
$dynamicPolicy = static function (array $declaration, array $answer) use ($site): Policy {
    $declaration['interpreter'] = 'native-fixture';
    $dynamicSite = $site;
    $dynamicSite['manifests'] = [$declaration['name']];
    $loaded = FrozenPolicy::policy([$declaration], $dynamicSite);
    $interpreter = new class($answer) {
        public function __construct(private array $rule) {}
        public function post_meta_rule(string $key, array $all): ?array { return $key === 'bio' ? $this->rule : null; }
        public function term_meta_rule(string $key, array $all): ?array { return $this->post_meta_rule($key, $all); }
        public function user_meta_rule(string $key, array $all): ?array { return $this->post_meta_rule($key, $all); }
        public function option_rule(string $key, array $all): ?array { return $this->post_meta_rule($key, $all); }
    };
    (new ReflectionProperty(Policy::class, 'interpreterInstances'))->setValue($loaded, ['native-fixture' => $interpreter]);
    return $loaded;
};
$dynamic = $dynamicPolicy($manifest, $rule);
wprism_check_same(Native::KSES, $dynamic->meta_rule_for_post('bio', [])[Native::FIELD], 'feature-enrolled exact interpreter owner can return a native rule');
foreach (['missing-feature', 'missing-owner', 'stripped-static', 'option'] as $fault) {
    $declaration = $manifest;
    $answer = $rule;
    if (in_array($fault, ['missing-feature', 'missing-owner'], true)) {
        foreach (['post_meta', 'term_meta', 'user_meta'] as $section) unset($declaration[$section]['bio'][Native::FIELD], $declaration[$section]['many']);
        $declaration['engine_features'] = ['spec-window/v1'];
    }
    if ($fault === 'stripped-static') unset($answer[Native::FIELD]);
    $casePolicy = $dynamicPolicy($declaration, $answer);
    if ($fault === 'missing-owner') {
        (new ReflectionProperty(Policy::class, 'manifests'))->setValue($casePolicy, []);
    }
    wprism_check_throws(static fn() => $fault === 'option' ? $casePolicy->meta_rule_for_option('bio', []) : $casePolicy->meta_rule_for_post('bio', []),
        RuntimeException::class, "interpreter $fault cannot weaken native authority");
}
$other = ['name' => 'other-native-fixture', 'spec_version' => WPRISM_SPEC_VERSION, 'post_meta' => ['bio' => ['class' => 'authored']]];
foreach ([[$manifest, $other], [$other, $manifest]] as $pinOrder) {
    $mixed = FrozenPolicy::policy($pinOrder, FrozenPolicy::site($pinOrder, WPRISM_SPEC_VERSION));
    wprism_check_throws(static fn() => $mixed->meta_rule_for_post('bio', []), RuntimeException::class,
        'both static adapter pin orders refuse overlapping native ownership', 'conflicting classification owners');
}
foreach (['post_meta', 'term_meta', 'user_meta'] as $section) {
    foreach (['feature', 'spec', 'site', 'profile', 'context', 'extra', 'null', 'class'] as $fault) {
        $source = ['spec_version' => 3, 'engine_features' => [Native::FEATURE], $section => ['bio' => $rule]];
        if ($fault === 'feature') unset($source['engine_features']);
        if ($fault === 'spec') $source['spec_version'] = 2;
        if ($fault === 'profile') $source[$section]['bio'][Native::FIELD]['profile'] = 'custom';
        if ($fault === 'context') $source[$section]['bio'][Native::FIELD]['context'] = 'post';
        if ($fault === 'extra') $source[$section]['bio'][Native::FIELD]['callback'] = 'anything';
        if ($fault === 'null') $source[$section]['bio'][Native::FIELD] = null;
        if ($fault === 'class') $source[$section]['bio']['class'] = 'runtime';
        wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes($source, 'fixture', $fault !== 'site'),
            RuntimeException::class, "$section refuses $fault predicate declaration");
    }
    $override = $site;
    $override['policy'][$section]['bio'] = ['class' => 'authored'];
    $overridden = FrozenPolicy::policy([$manifest], $override);
    $hook = ['post_meta' => 'meta_rule_for_post', 'term_meta' => 'meta_rule_for_term', 'user_meta' => 'meta_rule_for_user'][$section];
    wprism_check_throws(static fn() => $overridden->$hook('bio', ['bio' => 'safe']), RuntimeException::class,
        "$section authored site override cannot drop a static native predicate", 'cannot replace');
    $override['policy'][$section]['bio'] = ['class' => 'runtime'];
    $excluded = FrozenPolicy::policy([$manifest], $override);
    wprism_check_same('runtime', $excluded->$hook('bio', [])['class'], "$section can explicitly exclude the entire owned value");
}
foreach (['options', 'option_patterns', 'option_name_refs'] as $section) {
    $declarations = $section === 'options' ? ['bio' => $rule] : [$rule + ['match' => '^bio$']];
    wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes([
        'spec_version' => 3, 'engine_features' => [Native::FEATURE], $section => $declarations,
    ], 'fixture', true), RuntimeException::class, "$section cannot smuggle a metadata-only predicate");
}
foreach (['post_meta_patterns', 'meta_patterns'] as $section) {
    $patternManifest = ['name' => 'native-pattern', 'spec_version' => 3, 'engine_features' => [Native::FEATURE, 'spec-window/v1'],
        $section => [$rule + ['match' => '^bio$']]];
    $patternSite = FrozenPolicy::site([$patternManifest], WPRISM_SPEC_VERSION);
    $patternSite['policy']['post_meta']['bio'] = ['class' => 'authored'];
    $patternPolicy = FrozenPolicy::policy([$patternManifest], $patternSite);
    wprism_check_throws(static fn() => $patternPolicy->meta_rule_for_post('bio', []), RuntimeException::class,
        "$section predicate cannot be stripped by an exact site override", 'cannot replace');
}

$scratch = sys_get_temp_dir() . '/wprism-native-values-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/user-meta', 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) unlink($path);
};
register_shutdown_function(static fn() => $remove($scratch));
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
$path = $scratch . '/state/' . UserMetaState::path('editor');
$compile = static function (mixed $value) use ($path, $scratch, $policy) {
    Canon::write_file($path, Canon::encode(UserMetaState::document('editor', ['bio' => $value])));
    return RepositoryCompiler::compile($scratch, $policy);
};
wprism_check(!function_exists('wp_kses'), 'host compilation starts without WordPress');
$valid = '<a href="https://source.test/about">東京 বাংলা</a>';
$unsafe = '<script>fixture</script>';
$artifact = $compile($valid);
$unsafeArtifact = $compile($unsafe);
wprism_check_same($valid, $artifact->tree()[UserMetaState::key('editor')]['data']['meta']['bio'],
    'real standalone compiler retains exact valid native-profile bytes');
wprism_check_same($unsafe, $unsafeArtifact->tree()[UserMetaState::key('editor')]['data']['meta']['bio'],
    'portable compilation has no hidden WordPress oracle; native authorization is explicit');
wprism_check_throws(static fn() => RepositoryValueValidation::assert_native_tree($artifact->tree(), $policy),
    RuntimeException::class, 'native boundary fails closed if WordPress sanitizer is absent', 'cannot prove');
foreach ([42, false, [], "bad\x01byte", str_repeat('x', Native::MAX_BYTES + 1)] as $bad) {
    wprism_check_throws(static fn() => $compile($bad), RuntimeException::class, 'compiler refuses non-portable shape or bound');
}
$siteBefore = Canon::read_file($scratch . '/site.wprism.json');
wprism_check_throws(static fn() => Policy::set_rule($scratch, 'post_meta', 'bio', $rule), RuntimeException::class,
    'site classify refuses unsupported native declaration before publication');
wprism_check_same($siteBefore, Canon::read_file($scratch . '/site.wprism.json'), 'failed site classify preserves exact bytes');

// A controlled native seam, not an implementation of KSES. Actual WordPress
// semantics belong to Polylang conformance and the four-plugin live scenario.
$GLOBALS['native_value_calls'] = [];
if (!function_exists('wp_kses')) {
    function wp_kses(string $value, string|array $context): mixed {
        $GLOBALS['native_value_calls'][] = [$value, $context];
        if (isset($GLOBALS['native_value_override'])) return ($GLOBALS['native_value_override'])($value);
        return str_contains($value, '<script>') ? '' : $value;
    }
}
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once $root . '/agent/src/Capture/EntityMetaCapture.php';
require_once $root . '/agent/src/Capture/UserMetaCapture.php';
require_once $root . '/agent/src/Apply/ApplyFieldMaterializer.php';
require_once $root . '/agent/src/Apply/UserMetaMaterializer.php';
require_once $root . '/agent/src/Apply/ApplyPlanBuilder.php';
WpStore::reset()->seedOptions(['home' => 'https://source.test']);
$db = FakeWpdb::install()->enableInformationSchema()->enableJoinedCaptureSql();
foreach (['post' => 'post', 'term' => 'term', 'user-meta' => 'user-meta'] as $type) {
    foreach ([$valid, $unsafe] as $value) {
        $tree = [['type' => $type, 'path' => 'fixture', 'data' => ['meta' => ['bio' => $value]]]];
        if ($value === $valid) {
            RepositoryValueValidation::assert_native_tree($tree, $policy);
            wprism_check(true, "$type native tree preserves already-canonical text");
        } else {
            wprism_check_throws(static fn() => RepositoryValueValidation::assert_native_tree($tree, $policy), RuntimeException::class,
                "$type native tree refuses altered sanitizer output", 'not already canonical');
        }
    }
}
foreach ([static fn(string $v) => false, static fn(string $v) => throw new RuntimeException('native exploded')] as $override) {
    $GLOBALS['native_value_override'] = $override;
    wprism_check_throws(static fn() => Native::assert_native($valid, $rule, 'fixture'), RuntimeException::class,
        'native non-string and throwing callbacks cannot authorize a value');
}
unset($GLOBALS['native_value_override']);
$beforeCalls = count($GLOBALS['native_value_calls']);
$compile($valid);
wprism_check_same($beforeCalls, count($GLOBALS['native_value_calls']), 'compiler stays portable even when the native API is loaded');
foreach ([[$rule, ['class' => 'authored']], [['class' => 'authored'], $rule]] as [$before, $after]) {
    wprism_check_throws(static fn() => Native::assert_same_predicate($before, $after, 'fixture'), RuntimeException::class,
        'locked context cannot remove or introduce a native predicate', 'locked target context');
}
$reorderedRule = $rule;
$reorderedRule[Native::FIELD] = array_reverse(Native::KSES, true);
Native::assert_same_predicate($rule, $reorderedRule, 'fixture');
wprism_check(true, 'profile object order does not change native predicate identity');
foreach (['post', 'term'] as $type) {
    $badRoster = [['type' => $type, 'data' => ['meta' => ['many' => []]]]];
    wprism_check_throws(static fn() => RepositoryValueValidation::assert_native_tree($badRoster, $policy), RuntimeException::class,
        "$type native tree cannot vacuously validate an empty repeated roster", 'malformed metadata roster');
}
$tokens = new Tokens('https://source.test', 'https://source.test/uploads');
$tokens->policy = $policy;
$nothing = static function (...$args): void {};
$capture = new EntityMetaCapture($policy, $tokens, $nothing, $nothing, $nothing);
foreach ([false, true] as $term) {
    wprism_check_same([true, '<a href="{{home}}/about">東京 বাংলা</a>'],
        $capture->classifyValue('bio', [$valid], ['bio' => $valid], 'fixture', 'fixture', $term),
        'post/term Capture validates native text before ordinary URL tokenization');
    wprism_check_throws(static fn() => $capture->classifyValue('bio', [$unsafe], ['bio' => $unsafe], 'fixture', 'fixture', $term),
        RuntimeException::class, 'post/term Capture refuses unsafe raw metadata before tokenization', 'not already canonical');
    wprism_check_throws(static fn() => $capture->classifyValue('many', [$valid, $unsafe], ['many' => $valid], 'fixture', 'fixture', $term),
        RuntimeException::class, 'every repeated native row is validated, not just the first', 'not already canonical');
}
$db->seedTable('wp_users', [['ID' => 7, 'user_login' => 'editor']])->setColumns('wp_users', ['ID' => 'bigint', 'user_login' => 'varchar(60)']);
$userCapture = new UserMetaCapture($policy, $tokens, $nothing, $nothing, $nothing);
$db->seedTable('wp_usermeta', [['umeta_id' => 1, 'user_id' => 7, 'meta_key' => 'bio', 'meta_value' => $valid]]);
$capturedUsers = $userCapture->capture([]);
wprism_check_same('<a href="{{home}}/about">東京 বাংলা</a>', Canon::decode($capturedUsers[0]['content'])['meta']['bio'],
    'real user Capture validates before URL tokenization');
$db->seedTable('wp_usermeta', [['umeta_id' => 1, 'user_id' => 7, 'meta_key' => 'bio', 'meta_value' => $unsafe]]);
wprism_check_throws(static fn() => $userCapture->capture([]), RuntimeException::class,
    'real user Capture refuses unsafe biography', 'not already canonical');

// Only policy is needed before this refusal. Uninitialized later collaborators
// are deliberate tripwires against moving native validation after snapshot.
$planBuilder = (new ReflectionClass(ApplyPlanBuilder::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(ApplyPlanBuilder::class, 'policy'))->setValue($planBuilder, $policy);
$db->resetLog();
wprism_check_throws(static fn() => $planBuilder->build([], $unsafeArtifact), RuntimeException::class,
    'target Plan refuses unsafe compiled bytes before snapshot or target mutation', 'not already canonical');
wprism_check_same([], $db->queryLog(), 'native Plan preflight touched no target rows');

// Real row/range locks and writers against the shared SQL interpreter. The
// fixtures declare their schema explicitly; unknown SQL remains a failure.
$index = static fn(string $key, string $column): array => ['Key_name' => $key, 'Column_name' => $column,
    'Seq_in_index' => 1, 'Sub_part' => null, 'Non_unique' => 1, 'Index_type' => 'BTREE'];
$db->setIndexes('wp_users', [$index('user_login_key', 'user_login')])->setTableEngine('wp_users', 'InnoDB');
foreach (['post' => ['wp_postmeta', 'post_id', 'meta_id'], 'term' => ['wp_termmeta', 'term_id', 'meta_id'],
    'user' => ['wp_usermeta', 'user_id', 'umeta_id']] as $surface => [$table, $owner, $id]) {
    $db->setColumns($table, [$id => 'bigint', $owner => 'bigint', 'meta_key' => 'varchar(255)', 'meta_value' => 'longtext'])
        ->setIndexes($table, [$index($owner, $owner)])->setTableEngine($table, 'InnoDB')->setAutoIncrement($table, 10, $id);
    foreach (['valid', 'unsafe-desired', 'unsafe-omitted', 'unsafe-duplicate', 'unsafe-rebound'] as $case) {
        $rows = [[$id => 1, $owner => 7, 'meta_key' => 'bio', 'meta_value' => $case === 'unsafe-omitted' ? $unsafe : 'Before']];
        if ($case === 'unsafe-duplicate') $rows[] = [$id => 2, $owner => 7, 'meta_key' => 'bio', 'meta_value' => $unsafe];
        $db->seedTable($table, $rows);
        \WPrism\Db::start_repeatable_read('native value fixture start', new \WPrism\NativeDatabaseProfile(['wp_users'], [$table]));
        CacheInvalidationTransaction::begin();
        $targetTokens = new Tokens($case === 'unsafe-rebound' ? 'https://target.test/<script>' : 'https://target.test', 'https://target.test/uploads');
        $targetTokens->policy = $policy;
        $fields = new ApplyFieldMaterializer($policy, $targetTokens);
        $fields->begin_authored_transaction();
        $meta = $case === 'unsafe-omitted' ? [] : ['bio' => $case === 'unsafe-desired' ? $unsafe : '<a href="{{home}}/about">東京 বাংলা</a>'];
        $apply = static function () use ($surface, $fields, $policy, $targetTokens, $meta): void {
            if ($surface === 'user') (new UserMetaMaterializer($policy, $targetTokens, $fields))->finalize_user_meta(['login' => 'editor', 'meta' => $meta]);
            elseif ($surface === 'term') $fields->reconcile_authored_term_meta(7, $meta);
            else $fields->reconcile_authored_meta(7, $meta, 'post');
        };
        try {
            if ($case === 'valid') {
                $apply();
                wprism_check_same('<a href="https://target.test/about">東京 বাংলা</a>', $db->rows($table)[0]['meta_value'],
                    "$surface native Apply writes exact resolved safe text");
            } else {
                wprism_check_throws($apply, RuntimeException::class, "$surface $case refuses at native materialization", 'not already canonical');
                wprism_check_same($rows, $db->rows($table), "$surface $case preserves all rows before outer rollback");
            }
        } finally {
            $fields->end_authored_transaction();
            CacheInvalidationTransaction::end();
            \WPrism\Db::rollback('native value fixture rollback');
        }
    }
}
wprism_check(count($GLOBALS['native_value_calls']) > 0
    && array_unique(array_column($GLOBALS['native_value_calls'], 1)) === ['pre_user_description'],
    'all native boundaries use the exact closed WordPress context');
wprism_check_summary('native value validation boundaries');
