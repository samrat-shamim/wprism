<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * DUO-3234's Policy.php-side wiring: regen_dependency() declaration
 * lookup, validate_regen_dependencies() load-time shape checking, and
 * regenerators() manifest-shipped-PHP loading (the interpreter()-mirrored
 * trust boundary). Uses a FAKE fixture manifest + fake regenerator via
 * DUO_MANIFESTS_DIR, never the real manifests/the-events-calendar.json or
 * manifests/regenerators/the-events-calendar.php — this file proves the
 * MECHANISM works, independent of whether TEC's real classes are
 * available (they can't be: no WordPress bootstrap here at all).
 *
 * What this file deliberately does NOT test: Apply::regen_dependencies()
 * itself (the candidate-gathering/verify/regenerate/marker cycle) — that
 * method's dependencies (Canary, RepositoryCompiler, live $wpdb writes
 * across a real transaction) match every OTHER Apply.php-touching change
 * in this codebase, none of which get a FakeWpdb offline harness (see
 * regress_snapshot_meta.sh/regress_shipping_zones.sh/regress_collision.sh
 * — all live, docker-based). sandbox/tests/regress_tec_regen.sh is that
 * live proof, including the hard-fail + marker-retry mechanics. Nor the
 * DUO-3360 digest binding of the regenerator FILE (a changed regenerator is a
 * changed adapter): that row is built by two implementations that cannot call
 * each other, so it is pinned as one row in
 * sandbox/tests/regress_actions_providers.php beside the interpreter/provider
 * entries it mirrors, rather than split across each mechanism's own suite.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

$fixtureDir = sys_get_temp_dir() . '/duo_regress_regen_policy_' . bin2hex(random_bytes(4));
mkdir($fixtureDir . '/regenerators', 0777, true);
register_shutdown_function(function () use ($fixtureDir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($fixtureDir);
});

// A well-formed fixture regenerator, matching the real contract exactly
// (regenerate(int $localId): void) — records its calls for assertions.
file_put_contents($fixtureDir . '/regenerators/fake-regen.php', <<<'PHP'
<?php
namespace Duo\Regenerators;
final class FakeRegen {
    public static array $calls = [];
    public function __construct($policy) {}
    public function regenerate(int $localId): void {
        self::$calls[] = $localId;
    }
}
PHP
);

// A "-" and "_" in the declared name must both CamelCase correctly —
// exercising the exact str_replace/ucwords transform regenerators() uses
// (mirrors interpreters()' own transform, unit-proven here for this new
// call site rather than assumed to behave identically).
file_put_contents($fixtureDir . '/regenerators/two-word_name.php', <<<'PHP'
<?php
namespace Duo\Regenerators;
final class TwoWordName {
    public function __construct($policy) {}
    public function regenerate(int $localId): void {}
}
PHP
);

putenv("DUO_MANIFESTS_DIR=$fixtureDir");

require __DIR__ . '/../../agent/src/Canon.php';
require __DIR__ . '/../../agent/src/OptionState.php';
require __DIR__ . '/../../agent/src/Policy.php';

use Duo\Policy;

// DUO-3247: spec_version is now mandatory at Policy::load() — this file
// never requires agent/duo.php, so DUO_SPEC_VERSION would otherwise be
// undefined here (same fallback-define regress_adapter_contract.php uses).
// Every fixture below must declare it just to get PAST that gate and reach
// the regen_dependency checks this file actually exists to test.
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 0);
}

$failures = 0;
function check(bool $cond, string $msg): void {
    global $failures;
    if ($cond) {
        echo "ok: $msg\n";
    } else {
        echo "FAIL: $msg\n";
        $failures++;
    }
}
function check_throws(callable $fn, string $needle, string $msg): void {
    global $failures;
    try {
        $fn();
        echo "FAIL: $msg (did not throw)\n";
        $failures++;
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), $needle)) {
            echo "ok: $msg (threw: {$e->getMessage()})\n";
        } else {
            echo "FAIL: $msg (threw, but message missing '$needle': {$e->getMessage()})\n";
            $failures++;
        }
    }
}

function write_manifest(string $dir, string $name, array $json): void {
    file_put_contents("$dir/$name.json", json_encode($json, JSON_PRETTY_PRINT));
}

// ======================================================================
echo "\n== regen_dependency() lookup ==\n";

write_manifest($fixtureDir, 'a', [
    'name' => 'a',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => [
        'widget' => [
            'regen_dependency' => [
                'regenerator' => 'fake-regen',
                'verify' => ['table' => 'widget_cache', 'column' => 'post_id'],
            ],
        ],
    ],
]);
$policy = Policy::load(null, ['a']);
$decl = $policy->regen_dependency('widget');
check($decl !== null, 'declared post type returns a non-null decl');
check(($decl['regenerator'] ?? null) === 'fake-regen', 'decl.regenerator round-trips');
check(($decl['verify']['table'] ?? null) === 'widget_cache', 'decl.verify.table round-trips');
check($policy->regen_dependency('gadget') === null, 'undeclared post type returns null, not a default/guess');

// ======================================================================
echo "\n== parent/child post-type declarations ==\n";

// These deliberately unrelated names prove that relationship semantics live
// in the manifest, not in a Woo/product naming convention. A child may occur
// under more than one parent: the inverse API is plural and deterministic.
write_manifest($fixtureDir, 'relations', [
    'name' => 'relations',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => [
        'duo_album' => ['children' => ['duo_chapter', 'duo_asset']],
        'duo_story' => ['children' => ['duo_chapter']],
        'duo_chapter' => [],
        'duo_asset' => [],
        'duo_isolated' => [],
    ],
]);
$relationPolicy = Policy::load(null, ['relations']);
$hasRelationApi = method_exists($relationPolicy, 'child_post_types')
    && method_exists($relationPolicy, 'parent_post_types')
    && method_exists($relationPolicy, 'post_type_relation_closure');
check($hasRelationApi, 'Policy exposes generic child, plural-parent, and bidirectional relation-closure APIs');
if ($hasRelationApi) {
    check($relationPolicy->child_post_types('duo_album') === ['duo_asset', 'duo_chapter'],
        'parent children are sorted deterministically, independent of declaration order');
    check($relationPolicy->child_post_types('duo_story') === ['duo_chapter'],
        'an unrelated declared parent returns only its manifest-declared child type');
    check($relationPolicy->parent_post_types('duo_chapter') === ['duo_album', 'duo_story'],
        'a shared child returns every declaring parent in sorted order');
    check($relationPolicy->parent_post_types('duo_asset') === ['duo_album'],
        'plural inverse retains a one-parent relationship as a one-element array');
    check($relationPolicy->parent_post_types('duo_isolated') === [],
        'a declared type without children has no inferred parent');
    check($relationPolicy->post_type_relation_closure(['duo_story', 'duo_isolated'])
        === ['duo_album', 'duo_asset', 'duo_chapter', 'duo_isolated', 'duo_story'],
        'relation closure walks child-to-parent and parent-to-child edges and retains every root');
    check($relationPolicy->post_type_relation_closure(['duo_not_declared']) === ['duo_not_declared'],
        'an undeclared root is safely self-only rather than guessed from a product convention');
}

// Omission is intentional and safe: a manifest need not declare children for
// every post type. Invalid declarations, on the other hand, must fail while
// loading the manifest before any Apply query can infer a relation.
write_manifest($fixtureDir, 'relation-invalid-shape', [
    'name' => 'relation-invalid-shape',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => [
        'duo_parent' => ['children' => 'duo_child'],
        'duo_child' => [],
    ],
]);
check_throws(fn() => Policy::load(null, ['relation-invalid-shape']), 'children',
    'a scalar children declaration refuses at manifest load');

write_manifest($fixtureDir, 'relation-missing-child', [
    'name' => 'relation-missing-child',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => [
        'duo_parent' => ['children' => ['duo_missing_child']],
    ],
]);
check_throws(fn() => Policy::load(null, ['relation-missing-child']), 'duo_missing_child',
    'a child type absent from post_types refuses at manifest load');

write_manifest($fixtureDir, 'relation-duplicate-child', [
    'name' => 'relation-duplicate-child',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => [
        'duo_parent' => ['children' => ['duo_child', 'duo_child']],
        'duo_child' => [],
    ],
]);
check_throws(fn() => Policy::load(null, ['relation-duplicate-child']), 'duo_child',
    'a duplicate child in one parent declaration refuses at manifest load');

write_manifest($fixtureDir, 'relation-self-child', [
    'name' => 'relation-self-child',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => [
        'duo_parent' => ['children' => ['duo_parent']],
    ],
]);
check_throws(fn() => Policy::load(null, ['relation-self-child']), 'duo_parent',
    'a post type cannot declare itself as a child');

// ======================================================================
echo "\n== validate_regen_dependencies() — load-time shape checking ==\n";

write_manifest($fixtureDir, 'b', [
    'name' => 'b',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => ['widget' => ['regen_dependency' => ['verify' => ['table' => 't', 'column' => 'c']]]], // missing regenerator
]);
check_throws(fn() => Policy::load(null, ['b']), "needs a non-empty string 'regenerator'", 'missing regenerator key refuses at load()');

write_manifest($fixtureDir, 'c', [
    'name' => 'c',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => ['widget' => ['regen_dependency' => ['regenerator' => 'x', 'verify' => ['table' => 't']]]], // missing column
]);
check_throws(fn() => Policy::load(null, ['c']), 'verify: {table:', 'missing verify.column refuses at load()');

write_manifest($fixtureDir, 'd', [
    'name' => 'd',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => ['widget' => ['regen_dependency' => ['regenerator' => '', 'verify' => ['table' => 't', 'column' => 'c']]]], // empty regenerator
]);
check_throws(fn() => Policy::load(null, ['d']), "needs a non-empty string 'regenerator'", 'empty-string regenerator refuses at load()');

// A well-formed declaration must load cleanly (no false-positive refusal).
write_manifest($fixtureDir, 'e', [
    'name' => 'e',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => ['widget' => ['regen_dependency' => ['regenerator' => 'fake-regen', 'verify' => ['table' => 't', 'column' => 'c']]]],
]);
try {
    Policy::load(null, ['e']);
    check(true, 'a well-formed regen_dependency declaration loads without error');
} catch (\Throwable $t) {
    check(false, 'a well-formed regen_dependency declaration loads without error (threw: ' . $t->getMessage() . ')');
}

write_manifest($fixtureDir, 'i', [
    'name' => 'i',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => ['widget' => ['regen_dependency' => [
        'regenerator' => 'fake-regen',
        'verify' => ['table' => 't', 'column' => 'c'],
        'batch' => ['enabled' => true],
        'refresh' => ['enabled' => true],
    ]]],
]);
check_throws(fn() => Policy::load(null, ['i']), 'cannot declare both',
    'ambiguous batch+refresh declarations refuse at load()');

write_manifest($fixtureDir, 'j', [
    'name' => 'j',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => ['widget' => ['regen_dependency' => [
        'regenerator' => 'fake-regen',
        'verify' => ['table' => 't', 'column' => 'c'],
        'batch' => ['enabled' => true, 'typo' => true],
    ]]],
]);
check_throws(fn() => Policy::load(null, ['j']), 'contains unknown key(s): typo',
    'unknown batch configuration keys refuse at load()');

write_manifest($fixtureDir, 'k', [
    'name' => 'k',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => ['widget' => ['regen_dependency' => [
        'regenerator' => 'fake-regen',
        'verify' => ['table' => 't', 'column' => 'c'],
        'always_on_write' => true,
        'batch' => ['enabled' => true],
    ]]],
]);
check_throws(fn() => Policy::load(null, ['k']), 'always_on_write is ambiguous',
    'top-level always_on_write beside batch refuses at load()');

// ======================================================================
echo "\n== regenerators() — manifest-shipped-PHP loading ==\n";

$policy = Policy::load(null, ['e']);
$regens = $policy->regenerators();
check(isset($regens['fake-regen']), 'declared regenerator name is loaded and keyed correctly');
check(get_class($regens['fake-regen']) === 'Duo\\Regenerators\\FakeRegen', 'CamelCase class-name transform matches the real class (hyphenated name)');
$regens['fake-regen']->regenerate(42);
check(\Duo\Regenerators\FakeRegen::$calls === [42], 'the loaded instance is genuinely callable — regenerate() ran and recorded the call');

write_manifest($fixtureDir, 'f', [
    'name' => 'f',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => ['widget' => ['regen_dependency' => ['regenerator' => 'two-word_name', 'verify' => ['table' => 't', 'column' => 'c']]]],
]);
$policy2 = Policy::load(null, ['f']);
$regens2 = $policy2->regenerators();
check(isset($regens2['two-word_name']) && get_class($regens2['two-word_name']) === 'Duo\\Regenerators\\TwoWordName',
    'a name mixing hyphen AND underscore CamelCases correctly (two-word_name -> TwoWordName), matching the file it resolves to');

write_manifest($fixtureDir, 'g', [
    'name' => 'g',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => ['widget' => ['regen_dependency' => ['regenerator' => 'does-not-exist', 'verify' => ['table' => 't', 'column' => 'c']]]],
]);
$policy3 = Policy::load(null, ['g']);
check_throws(fn() => $policy3->regenerators(), 'but ' . $fixtureDir . '/regenerators/does-not-exist.php is missing',
    'a regenerator name with no matching file throws, naming the exact missing path');

// A regenerator file that exists but doesn't define the right class/method.
file_put_contents($fixtureDir . '/regenerators/broken.php', "<?php\nnamespace Duo\\Regenerators;\nfinal class Broken {}\n");
write_manifest($fixtureDir, 'h', [
    'name' => 'h',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => ['widget' => ['regen_dependency' => ['regenerator' => 'broken', 'verify' => ['table' => 't', 'column' => 'c']]]],
]);
$policy4 = Policy::load(null, ['h']);
check_throws(fn() => $policy4->regenerators(), 'must define', 'a regenerator file missing regenerate() throws a clear contract-violation message');

// The load-time calls must target the extracted pure grammar directly. Keep
// this seam asserted so a future compatibility facade cannot silently put the
// validator back into Policy.php while the behavior suite remains green.
$policySource = file_get_contents(__DIR__ . '/../../agent/src/Policy.php');
$postTypeGrammar = new \ReflectionClass('Duo\\PostTypeGrammar');
$policyReflection = new \ReflectionClass(Policy::class);
check(
    $postTypeGrammar->hasMethod('validate_regen_dependencies')
        && $postTypeGrammar->getMethod('validate_regen_dependencies')->isPublic()
        && !$policyReflection->hasMethod('validate_regen_dependencies')
        && substr_count($policySource, 'PostTypeGrammar::validate_regen_dependencies($manifest)') === 2,
    'regen_dependency shape validation lives in PostTypeGrammar and both Policy load paths call it directly'
);

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
