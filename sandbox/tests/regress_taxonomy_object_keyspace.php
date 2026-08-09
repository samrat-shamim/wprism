<?php
/**
 * Offline regression for DUO-3316: taxonomy relationship object ownership is
 * a version-pinned manifest contract (`object_keyspace`), never an engine
 * inference from a plugin's literal `term` object_type sentinel.
 *
 * The independent fixture plugin registers a term-keyspace taxonomy using an
 * intentionally opaque object_type string. This harness executes that plugin
 * against a tiny WordPress registration stub, then exercises the real Policy,
 * Capture, Lint, RepositoryCompiler, and Apply decision points without a
 * database, Docker, or a WordPress bootstrap.
 */

$root = realpath(__DIR__ . '/../..');
if ($root === false) {
    throw new RuntimeException('FAIL: repository root is unavailable');
}
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

/** @var array<string,list<callable>> */
$GLOBALS['duo_keyspace_actions'] = [];
/** @var array<string,object> */
$GLOBALS['duo_keyspace_taxonomies'] = [];

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback): void {
        $GLOBALS['duo_keyspace_actions'][$hook][] = $callback;
    }
}
if (!function_exists('register_taxonomy')) {
    function register_taxonomy(string $taxonomy, array|string $objectType, array $args = []): object {
        $object = (object) ['object_type' => (array) $objectType, 'args' => $args];
        $GLOBALS['duo_keyspace_taxonomies'][$taxonomy] = $object;
        return $object;
    }
}
if (!function_exists('get_taxonomy')) {
    function get_taxonomy(string $taxonomy): object|false {
        return $GLOBALS['duo_keyspace_taxonomies'][$taxonomy] ?? false;
    }
}
if (!function_exists('get_option')) {
    function get_option(string $name): string {
        return $name === 'home' ? 'https://keyspace.test' : '';
    }
}
if (!function_exists('untrailingslashit')) {
    function untrailingslashit(string $value): string {
        return rtrim($value, '/');
    }
}

require_once $root . '/agent/src/Canon.php';
require_once $root . '/agent/src/OptionState.php';
require_once $root . '/agent/src/Policy.php';
require_once $root . '/agent/src/Ledger.php';
require_once $root . '/agent/src/Tokens.php';
require_once $root . '/agent/src/Capture.php';
require_once $root . '/agent/src/Lint.php';
require_once $root . '/agent/src/SidebarState.php';
require_once $root . '/agent/src/RepositoryCompiler.php';
require_once $root . '/agent/src/Apply.php';

use Duo\Apply;
use Duo\Canon;
use Duo\Capture;
use Duo\Lint;
use Duo\Policy;
use Duo\RepositoryCompiler;
use Duo\Tokens;

$tmp = sys_get_temp_dir() . '/duo_regress_taxonomy_object_keyspace_' . bin2hex(random_bytes(6));
mkdir($tmp, 0777, true);
register_shutdown_function(static function () use ($tmp): void {
    if (!is_dir($tmp)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($tmp);
});

$failures = 0;
function tok_check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    echo "FAIL: $message\n";
    $failures++;
}

function tok_write_manifest(string $dir, string $name, array $manifest): void {
    file_put_contents($dir . '/' . $name . '.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function tok_expect_failure(callable $run, string $needle, string $message): void {
    global $failures;
    $threw = false;
    $actual = '';
    try {
        $run();
    } catch (Throwable $t) {
        $threw = true;
        $actual = $t->getMessage();
    }
    tok_check($threw, "$message refuses loudly");
    tok_check(str_contains($actual, $needle), "$message names its object_keyspace cause (got: $actual)");
}

function tok_has_finding(array $findings, string $class, string $locator): bool {
    foreach ($findings as $finding) {
        if (($finding['class'] ?? null) === $class && ($finding['locator'] ?? null) === $locator) {
            return true;
        }
    }
    return false;
}

function tok_has_code(array $diagnostics, string $code): bool {
    return in_array($code, array_column($diagnostics, 'code'), true);
}

$manifestDir = $tmp . '/manifests';
mkdir($manifestDir, 0777, true);
putenv("DUO_MANIFESTS_DIR=$manifestDir");

$fixture = [
    'name' => 'fixture',
    'spec_version' => DUO_SPEC_VERSION,
    'plugin' => 'duo-taxonomy-keyspace/duo-taxonomy-keyspace.php',
    'version_range' => ['min' => '0.1.0', 'max' => '1.0.0'],
    'taxonomies' => [
        'duo_keyspace_post_links' => ['object_keyspace' => 'post'],
        'duo_keyspace_term_links' => ['object_keyspace' => 'term'],
    ],
    'taxonomy_patterns' => [[
        'match' => '^duo_keyspace_dynamic_',
        'object_type' => ['duo_keyspace_post'],
        'object_keyspace' => 'term',
    ]],
];
tok_write_manifest($manifestDir, 'fixture', $fixture);

echo "\n== fixture plugin registration ==\n";
require $root . '/sandbox/fixtures/duo-taxonomy-keyspace/duo-taxonomy-keyspace.php';
foreach ($GLOBALS['duo_keyspace_actions']['init'] ?? [] as $callback) {
    $callback();
}
tok_check(isset($GLOBALS['duo_keyspace_taxonomies']['duo_keyspace_term_links']), 'fixture plugin registered its opaque term-keyspace taxonomy');
tok_check(
    ($GLOBALS['duo_keyspace_taxonomies']['duo_keyspace_term_links']->object_type ?? []) === ['duo_fixture_term_object'],
    'fixture term taxonomy deliberately uses a non-sentinel object_type'
);

echo "\n== policy declaration resolution and legacy compatibility ==\n";
$policy = Policy::load(null, ['fixture']);
tok_check($policy->taxonomy_object_keyspace('duo_keyspace_post_links', ['duo_keyspace_post']) === 'post', 'exact post object_keyspace resolves');
tok_check($policy->taxonomy_object_keyspace('duo_keyspace_term_links', ['duo_fixture_term_object']) === 'term', 'exact opaque term object_keyspace resolves');
tok_check($policy->taxonomy_object_keyspace('duo_keyspace_dynamic_term_links', ['duo_fixture_dynamic_term_object']) === 'term', 'matching taxonomy pattern object_keyspace resolves');
tok_check($policy->taxonomy_object_keyspace('duo_keyspace_legacy_post_links', ['duo_keyspace_post']) === 'post', 'undeclared legacy post-only taxonomy remains post-keyspace');
tok_expect_failure(
    fn() => $policy->taxonomy_object_keyspace('duo_keyspace_undeclared_term_links', ['term']),
    'no manifest object_keyspace declaration',
    'undeclared runtime term taxonomy'
);
tok_expect_failure(
    fn() => $policy->taxonomy_object_keyspace('duo_keyspace_undeclared_mixed_links', ['duo_keyspace_post', 'term']),
    'no manifest object_keyspace declaration',
    'undeclared runtime mixed taxonomy'
);

$legacyBytes = Canon::post_file([
    'meta' => (object) ['legacy_marker' => 'same'],
    'terms' => (object) ['duo_keyspace_legacy_post_links' => []],
    'type' => 'duo_keyspace_post',
], '');
[$legacyFront, $legacyBody] = Canon::parse_post_file($legacyBytes);
tok_check(
    Canon::post_file($legacyFront, $legacyBody) === $legacyBytes && !str_contains($legacyBytes, 'object_keyspace'),
    'legacy canonical post bytes remain grammar-identical (no new wire field)'
);

echo "\n== manifest-load validation and ambiguity refusal ==\n";
$badValue = $fixture;
$badValue['name'] = 'bad_value';
$badValue['taxonomies'] = ['bad' => ['object_keyspace' => 'comment']];
unset($badValue['taxonomy_patterns']);
tok_write_manifest($manifestDir, 'bad_value', $badValue);
tok_expect_failure(fn() => Policy::load(null, ['bad_value']), 'taxonomies.bad.object_keyspace', 'unsupported exact value');

$badShape = $fixture;
$badShape['name'] = 'bad_shape';
$badShape['taxonomy_patterns'] = [[
    'match' => '^bad_', 'object_type' => ['post'], 'object_keyspace' => [],
]];
unset($badShape['taxonomies']);
tok_write_manifest($manifestDir, 'bad_shape', $badShape);
tok_expect_failure(fn() => Policy::load(null, ['bad_shape']), 'taxonomy_patterns[0].object_keyspace', 'non-string pattern value');

$conflictPost = ['name' => 'conflict_post', 'spec_version' => DUO_SPEC_VERSION, 'taxonomies' => ['shared' => ['object_keyspace' => 'post']]];
$conflictTerm = ['name' => 'conflict_term', 'spec_version' => DUO_SPEC_VERSION, 'taxonomies' => ['shared' => ['object_keyspace' => 'term']]];
tok_write_manifest($manifestDir, 'conflict_post', $conflictPost);
tok_write_manifest($manifestDir, 'conflict_term', $conflictTerm);
tok_expect_failure(fn() => Policy::load(null, ['conflict_post', 'conflict_term']), 'conflicting object_keyspace declarations', 'contradictory exact declarations');

$samePatternPost = ['name' => 'same_pattern_post', 'spec_version' => DUO_SPEC_VERSION, 'taxonomy_patterns' => [[
    'match' => '^same_', 'object_type' => ['post'], 'object_keyspace' => 'post',
]]];
$samePatternTerm = ['name' => 'same_pattern_term', 'spec_version' => DUO_SPEC_VERSION, 'taxonomy_patterns' => [[
    'match' => '^same_', 'object_type' => ['post'], 'object_keyspace' => 'term',
]]];
tok_write_manifest($manifestDir, 'same_pattern_post', $samePatternPost);
tok_write_manifest($manifestDir, 'same_pattern_term', $samePatternTerm);
tok_expect_failure(fn() => Policy::load(null, ['same_pattern_post', 'same_pattern_term']), 'conflicting object_keyspace declarations', 'contradictory identical pattern declarations');

$overlap = ['name' => 'overlap', 'spec_version' => DUO_SPEC_VERSION, 'taxonomy_patterns' => [[
    'match' => '^duo_keyspace_dynamic_.*_links$', 'object_type' => ['duo_keyspace_post'], 'object_keyspace' => 'post',
]]];
tok_write_manifest($manifestDir, 'overlap', $overlap);
$overlappingPolicy = Policy::load(null, ['fixture', 'overlap']);
tok_expect_failure(
    fn() => $overlappingPolicy->taxonomy_object_keyspace('duo_keyspace_dynamic_term_links', ['duo_fixture_dynamic_term_object']),
    'ambiguous object_keyspace declarations',
    'concrete taxonomy matching contradictory dynamic patterns'
);

echo "\n== Capture uses the resolved keyspace, not a sentinel ==\n";
$captureReflection = new ReflectionClass(Capture::class);
$capture = $captureReflection->newInstanceWithoutConstructor();
$tokens = (new ReflectionClass(Tokens::class))->newInstanceWithoutConstructor();
foreach (['policy' => $policy, 'tokens' => $tokens] as $property => $value) {
    $slot = $captureReflection->getProperty($property);
    $slot->setAccessible(true);
    $slot->setValue($capture, $value);
}
$captureTaxonomies = $captureReflection->getMethod('taxes_by_object_type');
$captureTaxonomies->setAccessible(true);
$captured = $captureTaxonomies->invoke($capture, [
    'duo_keyspace_post_links',
    'duo_keyspace_legacy_post_links',
    'duo_keyspace_term_links',
    'duo_keyspace_dynamic_term_links',
], ['duo_keyspace_post'], false);
tok_check(
    ($captured['by_post_type']['duo_keyspace_post'] ?? []) === ['duo_keyspace_post_links', 'duo_keyspace_legacy_post_links'],
    'Capture maps declared and legacy post-keyspace taxonomies only to their post type'
);
tok_check(
    ($captured['term_object'] ?? []) === ['duo_keyspace_term_links', 'duo_keyspace_dynamic_term_links'],
    'Capture maps opaque exact and dynamic term keyspaces without literal term inference'
);
tok_expect_failure(
    fn() => $captureTaxonomies->invoke($capture, ['duo_keyspace_undeclared_term_links'], ['duo_keyspace_post'], false),
    'no manifest object_keyspace declaration',
    'Capture of undeclared runtime term taxonomy'
);

echo "\n== Lint and compiler preflight consume the same resolver ==\n";
$state = $tmp . '/state';
mkdir($state . '/posts/duo_keyspace_post', 0777, true);
mkdir($state . '/terms/duo_keyspace_term_links', 0777, true);
file_put_contents(
    $state . '/posts/duo_keyspace_post/00000000-0000-4000-8000-000000000001--fixture.md',
    Canon::post_file([
        'meta' => (object) [],
        'terms' => (object) ['duo_keyspace_term_links' => []],
        'type' => 'duo_keyspace_post',
    ], '')
);
file_put_contents(
    $state . '/terms/duo_keyspace_term_links/00000000-0000-4000-8000-000000000002--bad.json',
    Canon::encode([
        'description' => '', 'meta' => (object) [], 'relationships' => (object) ['duo_keyspace_post_links' => []],
        'taxonomy' => 'duo_keyspace_term_links',
    ])
);
file_put_contents(
    $state . '/terms/duo_keyspace_term_links/00000000-0000-4000-8000-000000000003--good.json',
    Canon::encode([
        'description' => '', 'meta' => (object) [], 'relationships' => (object) ['duo_keyspace_term_links' => []],
        'taxonomy' => 'duo_keyspace_term_links',
    ])
);
$findings = Lint::scan_tree($state, $policy);
tok_check(tok_has_finding($findings, 'taxonomy_object_keyspace_mismatch', 'terms.duo_keyspace_term_links'), 'Lint flags a term-keyspace taxonomy in post terms');
tok_check(tok_has_finding($findings, 'taxonomy_object_keyspace_mismatch', 'relationships.duo_keyspace_post_links'), 'Lint flags a post-keyspace taxonomy in term relationships');
tok_check(!tok_has_finding($findings, 'taxonomy_object_keyspace_mismatch', 'relationships.duo_keyspace_term_links'), 'Lint accepts correctly placed term-keyspace relationships');

$compilerReflection = new ReflectionClass(RepositoryCompiler::class);
$compiler = $compilerReflection->newInstanceWithoutConstructor();
$compilerPolicy = $compilerReflection->getProperty('policy');
$compilerPolicy->setAccessible(true);
$compilerPolicy->setValue($compiler, $policy);
$validateSchema = $compilerReflection->getMethod('validate_schema');
$validateSchema->setAccessible(true);
$validateSchema->invoke($compiler, 'post', 'posts/fixture.md', [
    'meta' => [], 'terms' => ['duo_keyspace_term_links' => []],
], '');
$diagnostics = $compilerReflection->getProperty('diagnostics');
$diagnostics->setAccessible(true);
tok_check(tok_has_code($diagnostics->getValue($compiler), 'taxonomy_object_keyspace_mismatch'), 'RepositoryCompiler blocks a term-keyspace taxonomy in a post file');

$termCompiler = $compilerReflection->newInstanceWithoutConstructor();
$compilerPolicy->setValue($termCompiler, $policy);
$validateSchema->invoke($termCompiler, 'term', 'terms/fixture.json', [
    'meta' => [], 'relationships' => ['duo_keyspace_post_links' => []],
], null);
tok_check(tok_has_code($diagnostics->getValue($termCompiler), 'taxonomy_object_keyspace_mismatch'), 'RepositoryCompiler blocks a post-keyspace taxonomy in a term file');

echo "\n== Apply defends the same boundary before database access ==\n";
$applyReflection = new ReflectionClass(Apply::class);
$apply = $applyReflection->newInstanceWithoutConstructor();
$applyPolicy = $applyReflection->getProperty('policy');
$applyPolicy->setAccessible(true);
$applyPolicy->setValue($apply, $policy);
$postReconcile = $applyReflection->getMethod('reconcile_relationships');
$postReconcile->setAccessible(true);
tok_expect_failure(
    fn() => $postReconcile->invoke($apply, 17, 'duo_keyspace_post', ['duo_keyspace_term_links' => []], []),
    'object_keyspace',
    'Apply post relationship reconcile with a term-keyspace taxonomy'
);
$termReconcile = $applyReflection->getMethod('reconcile_term_relationships');
$termReconcile->setAccessible(true);
tok_expect_failure(
    fn() => $termReconcile->invoke($apply, 23, 'duo_keyspace_term_links', ['duo_keyspace_post_links' => []]),
    'object_keyspace',
    'Apply term relationship reconcile with a post-keyspace taxonomy'
);

echo "\n== shipped Polylang migration ==\n";
putenv('DUO_MANIFESTS_DIR');
$polylang = Policy::load(null, ['polylang']);
tok_check($polylang->taxonomy_object_keyspace('language') === 'post', 'Polylang language declares post keyspace');
tok_check($polylang->taxonomy_object_keyspace('post_translations') === 'post', 'Polylang post_translations declares post keyspace');
tok_check($polylang->taxonomy_object_keyspace('term_language') === 'term', 'Polylang term_language declares term keyspace');
tok_check($polylang->taxonomy_object_keyspace('term_translations') === 'term', 'Polylang term_translations declares term keyspace');

if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
