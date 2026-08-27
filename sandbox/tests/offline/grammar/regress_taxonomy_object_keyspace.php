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

$root = realpath(__DIR__ . '/../../../..');
if ($root === false) {
    throw new RuntimeException('FAIL: repository root is unavailable');
}
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 3);
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

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/OptionState.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/agent/src/Capture/Capture.php';
require_once $root . '/agent/src/Review/Lint.php';
require_once $root . '/agent/src/Repository/SidebarState.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Repository/RepositorySchemaValidator.php';
require_once $root . '/agent/src/Apply/Apply.php';
require_once __DIR__ . '/../policy/manifest_fixtures.php';

use Duo\Apply;
use Duo\Canon;
use Duo\Capture;
use Duo\Lint;
use Duo\Policy;
use Duo\RepositoryCompiler;
use Duo\RepositorySchemaValidator;
use Duo\ScopeDiscovery;
use Duo\SidebarState;
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
$loadPolicy = static fn(array $names): Policy => Policy::load(
    null,
    $names,
    adapterLibrary: manifest_fixture_adapter_library($manifestDir)
);

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
$policy = $loadPolicy(['fixture']);
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
tok_expect_failure(
    fn() => $policy->taxonomy_object_keyspace('duo_keyspace_post_links', ['term']),
    'declaration/runtime relationship ownership contradicts',
    'declared post keyspace with runtime term ownership'
);
tok_expect_failure(
    fn() => $policy->taxonomy_object_keyspace('duo_keyspace_term_links', ['duo_keyspace_post', 'term']),
    'mixed runtime object_type values',
    'declared keyspace cannot describe a mixed runtime registry'
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
tok_expect_failure(fn() => $loadPolicy(['bad_value']), 'taxonomies.bad.object_keyspace', 'unsupported exact value');

$badShape = $fixture;
$badShape['name'] = 'bad_shape';
$badShape['taxonomy_patterns'] = [[
    'match' => '^bad_', 'object_type' => ['post'], 'object_keyspace' => [],
]];
unset($badShape['taxonomies']);
tok_write_manifest($manifestDir, 'bad_shape', $badShape);
tok_expect_failure(fn() => $loadPolicy(['bad_shape']), 'taxonomy_patterns[0].object_keyspace', 'non-string pattern value');

$conflictPost = ['name' => 'conflict_post', 'spec_version' => DUO_SPEC_VERSION, 'taxonomies' => ['shared' => ['object_keyspace' => 'post']]];
$conflictTerm = ['name' => 'conflict_term', 'spec_version' => DUO_SPEC_VERSION, 'taxonomies' => ['shared' => ['object_keyspace' => 'term']]];
tok_write_manifest($manifestDir, 'conflict_post', $conflictPost);
tok_write_manifest($manifestDir, 'conflict_term', $conflictTerm);
tok_expect_failure(
    fn() => $loadPolicy(['conflict_post', 'conflict_term']),
    'both declare taxonomies.shared',
    'contradictory exact declarations use the stronger one-owner refusal'
);

$samePatternPost = ['name' => 'same_pattern_post', 'spec_version' => DUO_SPEC_VERSION, 'taxonomy_patterns' => [[
    'match' => '^same_', 'object_type' => ['post'], 'object_keyspace' => 'post',
]]];
$samePatternTerm = ['name' => 'same_pattern_term', 'spec_version' => DUO_SPEC_VERSION, 'taxonomy_patterns' => [[
    'match' => '^same_', 'object_type' => ['post'], 'object_keyspace' => 'term',
]]];
tok_write_manifest($manifestDir, 'same_pattern_post', $samePatternPost);
tok_write_manifest($manifestDir, 'same_pattern_term', $samePatternTerm);
tok_expect_failure(fn() => $loadPolicy(['same_pattern_post', 'same_pattern_term']), 'conflicting object_keyspace declarations', 'contradictory identical pattern declarations');

$legacyPatternPost = ['name' => 'legacy_pattern_post', 'spec_version' => DUO_SPEC_VERSION, 'taxonomy_patterns' => [[
    'match' => '^legacy_shared$', 'object_type' => ['duo_keyspace_post'],
]]];
$explicitPatternTerm = ['name' => 'explicit_pattern_term', 'spec_version' => DUO_SPEC_VERSION, 'taxonomy_patterns' => [[
    'match' => '^legacy_shared$', 'object_type' => ['duo_keyspace_post'], 'object_keyspace' => 'term',
]]];
tok_write_manifest($manifestDir, 'legacy_pattern_post', $legacyPatternPost);
tok_write_manifest($manifestDir, 'explicit_pattern_term', $explicitPatternTerm);
tok_expect_failure(
    fn() => $loadPolicy(['legacy_pattern_post', 'explicit_pattern_term']),
    'conflicting object_keyspace declarations',
    'omitted legacy pattern keyspace conflicts with explicit term at manifest load'
);

$legacyExactPost = ['name' => 'legacy_exact_post', 'spec_version' => DUO_SPEC_VERSION, 'taxonomies' => [
    'exact_pattern_shared' => ['class' => 'authored'],
]];
$matchingPatternTerm = ['name' => 'matching_pattern_term', 'spec_version' => DUO_SPEC_VERSION, 'taxonomy_patterns' => [[
    'match' => '^exact_pattern_shared$', 'object_type' => ['duo_keyspace_post'], 'object_keyspace' => 'term',
]]];
tok_write_manifest($manifestDir, 'legacy_exact_post', $legacyExactPost);
tok_write_manifest($manifestDir, 'matching_pattern_term', $matchingPatternTerm);
tok_expect_failure(
    fn() => $loadPolicy(['legacy_exact_post', 'matching_pattern_term']),
    'exact and matching pattern declarations must agree',
    'omitted exact keyspace conflicts eagerly with an explicit matching term pattern'
);

$patternContractA = ['name' => 'pattern_contract_a', 'spec_version' => DUO_SPEC_VERSION, 'taxonomy_patterns' => [[
    'match' => '^contract_', 'object_type' => ['duo_keyspace_post'], 'object_keyspace' => 'post',
]]];
$patternContractB = ['name' => 'pattern_contract_b', 'spec_version' => DUO_SPEC_VERSION, 'taxonomy_patterns' => [[
    'match' => '^contract_shared$', 'object_type' => ['other_post_type'], 'object_keyspace' => 'post',
]]];
tok_write_manifest($manifestDir, 'pattern_contract_a', $patternContractA);
tok_write_manifest($manifestDir, 'pattern_contract_b', $patternContractB);
$ambiguousPatternPolicy = $loadPolicy(['pattern_contract_a', 'pattern_contract_b']);
tok_expect_failure(
    fn() => $ambiguousPatternPolicy->pattern_object_type('contract_shared'),
    'ambiguous taxonomy_patterns contracts',
    'overlapping patterns cannot choose object_type by pin order'
);

$overlap = ['name' => 'overlap', 'spec_version' => DUO_SPEC_VERSION, 'taxonomy_patterns' => [[
    'match' => '^duo_keyspace_dynamic_.*_links$', 'object_type' => ['duo_keyspace_post'], 'object_keyspace' => 'post',
]]];
tok_write_manifest($manifestDir, 'overlap', $overlap);
$overlappingPolicy = $loadPolicy(['fixture', 'overlap']);
tok_expect_failure(
    fn() => $overlappingPolicy->taxonomy_object_keyspace('duo_keyspace_dynamic_term_links', ['duo_fixture_dynamic_term_object']),
    'ambiguous object_keyspace declarations',
    'concrete taxonomy matching contradictory dynamic patterns'
);

echo "\n== Capture uses the resolved keyspace, not a sentinel ==\n";
$captureScope = new ScopeDiscovery($policy);
$captured = $captureScope->taxonomyOwnership([
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
    fn() => $captureScope->taxonomyOwnership(
        ['duo_keyspace_undeclared_term_links'],
        ['duo_keyspace_post'],
        false
    ),
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

// ---------------------------------------------------------------------------
// DUO-3403 (PR #176 finding 5, site 2): Lint::scan_taxonomy_relationship_
// keyspaces() copies a Policy::taxonomy_object_keyspace() throw's getMessage()
// into a `taxonomy_object_keyspace_invalid` finding note, and
// `lint --format=json` publishes that note on a machine surface. The source is
// engine-internal, and every message it can throw embeds only a taxonomy slug
// plus manifest declaration sources — a CLOSED, reviewed set with no path or
// credential — so the note is KEPT verbatim. This pin proves the set stays
// closed: it screens the REAL finding note plus every reachable resolver throw
// through the same secret/path authority the JSON refusal envelope uses
// (CommandRefusalException::containsSensitivePublicDetail()).
$koScreenClean = static fn(string $m): bool =>
    !\Duo\CommandRefusalException::containsSensitivePublicDetail(['message' => $m]);

// Reproduce the actual Lint wire value: a post referencing an ambiguously
// declared taxonomy makes the resolver throw, which Lint captures verbatim
// into a taxonomy_object_keyspace_invalid finding note.
$invalidState = $tmp . '/state-invalid';
mkdir($invalidState . '/posts/duo_keyspace_post', 0777, true);
file_put_contents(
    $invalidState . '/posts/duo_keyspace_post/00000000-0000-4000-8000-000000000009--ambiguous.md',
    Canon::post_file([
        'meta' => (object) [],
        'terms' => (object) ['duo_keyspace_dynamic_term_links' => []],
        'type' => 'duo_keyspace_post',
    ], '')
);
$invalidFindings = Lint::scan_tree($invalidState, $overlappingPolicy);
$invalidNote = null;
foreach ($invalidFindings as $finding) {
    if (($finding['class'] ?? null) === 'taxonomy_object_keyspace_invalid'
        && ($finding['locator'] ?? null) === 'terms.duo_keyspace_dynamic_term_links') {
        $invalidNote = (string) ($finding['note'] ?? '');
        break;
    }
}
tok_check($invalidNote !== null,
    'DUO-3403: Lint emits a taxonomy_object_keyspace_invalid finding carrying the resolver throw as its note');
tok_check($invalidNote !== null && $koScreenClean($invalidNote),
    'DUO-3403: the taxonomy_object_keyspace_invalid finding note is path/credential-free on the lint JSON surface (' . (string) $invalidNote . ')');

// Enumerate every taxonomy_object_keyspace() throw and screen each message.
$koThrows = [];
$collectKo = static function (callable $run) use (&$koThrows): void {
    try {
        $run();
    } catch (Throwable $t) {
        $koThrows[] = $t->getMessage();
    }
};
$collectKo(fn() => $overlappingPolicy->taxonomy_object_keyspace('duo_keyspace_dynamic_term_links')); // pattern ambiguity — the Lint-reachable single-arg throw
$collectKo(fn() => $policy->taxonomy_object_keyspace('duo_keyspace_undeclared_term_links', ['term'])); // undeclared runtime term
$collectKo(fn() => $policy->taxonomy_object_keyspace('duo_keyspace_undeclared_mixed_links', ['duo_keyspace_post', 'term'])); // undeclared runtime mixed
$collectKo(fn() => $policy->taxonomy_object_keyspace('duo_keyspace_post_links', ['term'])); // declaration/runtime contradiction
$collectKo(fn() => $policy->taxonomy_object_keyspace('duo_keyspace_term_links', ['duo_keyspace_post', 'term'])); // mixed runtime registry
tok_check(count($koThrows) === 5,
    'DUO-3403: every POLICY-REACHABLE taxonomy_object_keyspace() throw is enumerated for the lint-note closed-set pin');
foreach ($koThrows as $koMessage) {
    tok_check($koScreenClean($koMessage),
        'DUO-3403: taxonomy_object_keyspace() throw is path/credential-free (' . $koMessage . ')');
}
// The direct exact/pattern-conflict template is load-guarded unreachable
// (validate_no_conflicting_taxonomy_object_keyspaces rejects the conflict at
// load, Policy.php ~1948), so no runtime path produces it — but it is in the
// closed set and interpolates only a taxonomy slug + declaration sources, so
// its literal is screened directly rather than left unsampled.
tok_check($koScreenClean("duo: taxonomy 'duo_keyspace_term_links' has ambiguous object_keyspace declarations (manifest 'a' says post; manifest 'b' says term) — every exact or matching pattern declaration must agree"),
    'DUO-3403: the load-unreachable ambiguous-declarations template is also path/credential-free (closed set fully covered, not sampled)');
// Self-test: the screen this pin trusts MUST flag a path- and a credential-
// shaped variant, or the checks above would pass vacuously.
tok_check(!$koScreenClean("duo: taxonomy '/home/deploy/site' matches ambiguous object_keyspace declarations"),
    'DUO-3403 self-test: a path-shaped keyspace message would be caught by this pin');
tok_check(!$koScreenClean("duo: taxonomy 'x' owner exposed sk_live_0123456789abcdef in its declaration"),
    'DUO-3403 self-test: a credential-shaped keyspace message would be caught by this pin');

$schemaDiagnostics = [];
$schemaValidator = new RepositorySchemaValidator(
    $policy,
    SidebarState::ENTITY_TYPE,
    static function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null) use (&$schemaDiagnostics): void {
        $schemaDiagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
    }
);
$schemaValidator->validate('post', 'posts/fixture.md', [
    'meta' => [], 'terms' => ['duo_keyspace_term_links' => []],
], '');
tok_check(tok_has_code($schemaDiagnostics, 'taxonomy_object_keyspace_mismatch'), 'RepositorySchemaValidator blocks a term-keyspace taxonomy in a post file');

$schemaDiagnostics = [];
$schemaValidator->validate('term', 'terms/fixture.json', [
    'meta' => [], 'relationships' => ['duo_keyspace_post_links' => []],
], null);
tok_check(tok_has_code($schemaDiagnostics, 'taxonomy_object_keyspace_mismatch'), 'RepositorySchemaValidator blocks a post-keyspace taxonomy in a term file');

echo "\n== Apply defends the same boundary before database access ==\n";
// DUO-3347 slice 8: reconcile_relationships() moved from Apply onto
// RelationshipMaterializer (Apply keeps only the facade). This early
// keyspace-mismatch throw fires from the method's first loop, before its
// new $taxesForPostType parameter is ever read, so an empty array is a
// safe, correct stand-in here -- same reasoning as the TermMaterializer
// reconcile below, which this mirrors.
$relationshipMaterializerReflection = new ReflectionClass(\Duo\RelationshipMaterializer::class);
$relationshipMaterializer = $relationshipMaterializerReflection->newInstanceWithoutConstructor();
$relationshipMaterializerPolicy = $relationshipMaterializerReflection->getProperty('policy');
$relationshipMaterializerPolicy->setValue($relationshipMaterializer, $policy);
$postReconcile = $relationshipMaterializerReflection->getMethod('reconcile_relationships');
tok_expect_failure(
    fn() => $postReconcile->invoke($relationshipMaterializer, 17, 'duo_keyspace_post', ['duo_keyspace_term_links' => []], [], []),
    'object_keyspace',
    'RelationshipMaterializer post relationship reconcile with a term-keyspace taxonomy'
);
// DUO-3347 slice 6: reconcile_term_relationships() moved from Apply onto
// TermMaterializer (Apply keeps only finalize_term() as a facade); this
// early keyspace-mismatch throw fires from the method's first loop, before
// its new $termObjectTaxes parameter is ever read, so an empty array is a
// safe, correct stand-in here.
$termMaterializerReflection = new ReflectionClass(\Duo\TermMaterializer::class);
$termMaterializer = $termMaterializerReflection->newInstanceWithoutConstructor();
$termMaterializerPolicy = $termMaterializerReflection->getProperty('policy');
$termMaterializerPolicy->setValue($termMaterializer, $policy);
$termReconcile = $termMaterializerReflection->getMethod('reconcile_term_relationships');
tok_expect_failure(
    fn() => $termReconcile->invoke($termMaterializer, 23, 'duo_keyspace_term_links', ['duo_keyspace_post_links' => []], []),
    'object_keyspace',
    'TermMaterializer term relationship reconcile with a post-keyspace taxonomy'
);

echo "\n== shipped Polylang migration ==\n";
$polylang = Policy::load(
    null,
    ['polylang'],
    adapterLibrary: \Duo\AdapterLibrary::fromSourceTree($root)
);
tok_check($polylang->taxonomy_object_keyspace('language') === 'post', 'Polylang language declares post keyspace');
tok_check($polylang->taxonomy_object_keyspace('post_translations') === 'post', 'Polylang post_translations declares post keyspace');
tok_check($polylang->taxonomy_object_keyspace('term_language') === 'term', 'Polylang term_language declares term keyspace');
tok_check($polylang->taxonomy_object_keyspace('term_translations') === 'term', 'Polylang term_translations declares term keyspace');

if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
