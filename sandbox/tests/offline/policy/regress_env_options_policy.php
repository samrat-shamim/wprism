<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * issue #3232's Policy.php-side wiring: validate_env_options()'s mandatory-
 * `required`-boolean load-time gate and env_options()'s enumeration
 * (merge precedence, ksort, with_option_autoload() wiring). Uses FAKE
 * fixture manifests through one explicit flat AdapterLibrary, never the real shipped
 * manifests — this file proves the MECHANISM works in isolation.
 *
 * What this file deliberately does NOT test:
 *   - Apply::set_env_option() itself (the actual wp_options write) — like
 *     every other Apply.php-touching change in this codebase, that needs
 *     a live $wpdb and gets a live, docker-based proof instead (see
 *     sandbox/tests/live/regress_env_set.sh), not a FakeWpdb offline harness.
 * issue #3255 extends this harness with the cross-manifest contradiction
 * gate, identical-rule dedupe, first-match bulk resolution, and a real
 * fixture site.wprism.json proving the explicit site-policy escape path.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

$fixtureDir = sys_get_temp_dir() . '/wprism_regress_env_options_' . bin2hex(random_bytes(4));
mkdir($fixtureDir, 0777, true);
register_shutdown_function(function () use ($fixtureDir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($fixtureDir);
});
require __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require __DIR__ . '/manifest_fixtures.php';

use WPrism\Policy;

// issue #3247: spec_version is mandatory at Policy::load() — this file never
// requires agent/wprism.php, so WPRISM_SPEC_VERSION would otherwise be
// undefined here (same fallback-define regress_regen_dependency_policy.php
// uses). Every fixture below must declare it just to get past that gate.
if (!defined('WPRISM_SPEC_VERSION')) {
    define('WPRISM_SPEC_VERSION', 0);
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
echo "\n== validate_env_options() — mandatory 'required' boolean ==\n";

write_manifest($fixtureDir, 'a', [
    'name' => 'a',
    'spec_version' => WPRISM_SPEC_VERSION,
    'options' => ['api_key' => ['class' => 'env']], // missing required entirely
]);
check_throws(fn() => manifest_fixture_policy_load($fixtureDir, null, ['a']), 'options.api_key.class="env" needs an explicit boolean',
    'missing required key refuses at load()');

write_manifest($fixtureDir, 'b', [
    'name' => 'b',
    'spec_version' => WPRISM_SPEC_VERSION,
    'options' => ['api_key' => ['class' => 'env', 'required' => 'true']], // string, not bool
]);
check_throws(fn() => manifest_fixture_policy_load($fixtureDir, null, ['b']), 'options.api_key.class="env" needs an explicit boolean',
    'string "true" (not a real bool) refuses at load()');

write_manifest($fixtureDir, 'c', [
    'name' => 'c',
    'spec_version' => WPRISM_SPEC_VERSION,
    'options' => ['api_key' => ['class' => 'env', 'required' => 1]], // int, not bool
]);
check_throws(fn() => manifest_fixture_policy_load($fixtureDir, null, ['c']), 'options.api_key.class="env" needs an explicit boolean',
    'int 1 (not a real bool) refuses at load()');

// Non-env classes never need 'required' — the gate is scoped to class:"env"
// only, same as validate_option_storage()'s own authored/managed scoping.
write_manifest($fixtureDir, 'd', [
    'name' => 'd',
    'spec_version' => WPRISM_SPEC_VERSION,
    'options' => [
        'runtime_thing' => ['class' => 'runtime'],
        'derived_thing' => ['class' => 'derived'],
    ],
]);
try {
    manifest_fixture_policy_load($fixtureDir, null, ['d']);
    check(true, 'non-env classes never require the required flag');
} catch (\Throwable $t) {
    check(false, 'non-env classes never require the required flag (threw: ' . $t->getMessage() . ')');
}

// Well-formed declarations, both required values, load cleanly.
write_manifest($fixtureDir, 'e', [
    'name' => 'e',
    'spec_version' => WPRISM_SPEC_VERSION,
    'options' => [
        'gateway_key' => ['class' => 'env', 'required' => true],
        'install_marker' => ['class' => 'env', 'required' => false],
        'a_runtime_option' => ['class' => 'runtime'],
    ],
]);
try {
    manifest_fixture_policy_load($fixtureDir, null, ['e']);
    check(true, 'a well-formed mix of required:true/required:false/non-env options loads without error');
} catch (\Throwable $t) {
    check(false, 'a well-formed mix loads without error (threw: ' . $t->getMessage() . ')');
}

// ======================================================================
echo "\n== env_options() — enumeration, merge, ksort ==\n";

$policy = manifest_fixture_policy_load($fixtureDir, null, ['e']);
$envOpts = $policy->env_options();
check(count($envOpts) === 2, 'env_options() returns exactly the 2 class="env" rules, excluding the runtime one');
check(($envOpts['gateway_key']['required'] ?? null) === true, 'required:true round-trips exactly (strict ===, not merely truthy)');
check(($envOpts['install_marker']['required'] ?? null) === false, 'required:false round-trips exactly (strict ===, not merely falsy)');
check(!isset($envOpts['a_runtime_option']), 'a runtime-classified option never appears in env_options()');
check(array_keys($envOpts) === ['gateway_key', 'install_marker'], 'ksort(SORT_STRING) orders keys alphabetically regardless of declaration order');

// with_option_autoload(): a manifest-level option_autoload default must
// flow into an env rule's resolved 'autoload' the same way it already
// does for authored_options()/sub_keyed_options() — issue #3232 extended
// env_options() to call the identical helper those two already used.
write_manifest($fixtureDir, 'f', [
    'name' => 'f',
    'spec_version' => WPRISM_SPEC_VERSION,
    'option_autoload' => 'preserve',
    'options' => [
        'inherits_default' => ['class' => 'env', 'required' => true],
        'overrides_default' => ['class' => 'env', 'required' => true, 'autoload' => 'no'],
    ],
]);
$policyF = manifest_fixture_policy_load($fixtureDir, null, ['f']);
$envOptsF = $policyF->env_options();
check(($envOptsF['inherits_default']['autoload'] ?? null) === 'preserve',
    "a rule with no own 'autoload' inherits the manifest's option_autoload default");
check(($envOptsF['overrides_default']['autoload'] ?? null) === 'no',
    "a rule's own explicit 'autoload' wins over the manifest default, not merely present alongside it");

// Two non-core manifests declaring materially different rules for one
// option refuse at load. The same class is not enough: required is part of
// an env option's effective contract, so the empirical issue #3232 collision
// that previously demonstrated last-pin-wins now demonstrates the owner's
// issue #3255 loud-refusal ruling instead.
write_manifest($fixtureDir, 'g1', [
    'name' => 'g1',
    'spec_version' => WPRISM_SPEC_VERSION,
    'options' => ['shared_name' => ['class' => 'env', 'required' => true], 'only_in_g1' => ['class' => 'env', 'required' => false]],
]);
write_manifest($fixtureDir, 'g2', [
    'name' => 'g2',
    'spec_version' => WPRISM_SPEC_VERSION,
    'options' => ['shared_name' => ['class' => 'env', 'required' => false], 'only_in_g2' => ['class' => 'env', 'required' => false]],
]);
check_throws(fn() => manifest_fixture_policy_load($fixtureDir, null, ['g1', 'g2']),
    "manifests 'g1' and 'g2' declare contradictory rules for options.shared_name",
    'same-class env declarations with different required contracts refuse at load');
check_throws(fn() => manifest_fixture_policy_load($fixtureDir, null, ['g2', 'g1']),
    'Add an explicit site.wprism.json policy.options.shared_name override',
    'refusal is pin-order independent and names the explicit site-policy resolution path');

// Identical declarations are harmless and dedupe. The bulk env map now
// resolves through the same first-non-core rule_details() path as a direct
// lookup, rather than independently overwriting with the later pin.
write_manifest($fixtureDir, 'g3', [
    'name' => 'g3',
    'spec_version' => WPRISM_SPEC_VERSION,
    'options' => ['shared_name' => ['class' => 'env', 'required' => false], 'only_in_g3' => ['class' => 'env', 'required' => false]],
]);
write_manifest($fixtureDir, 'g4', [
    'name' => 'g4',
    'spec_version' => WPRISM_SPEC_VERSION,
    'options' => ['shared_name' => ['class' => 'env', 'required' => false], 'only_in_g4' => ['class' => 'env', 'required' => false]],
]);
$policyG = manifest_fixture_policy_load($fixtureDir, null, ['g3', 'g4']);
$envOptsG = $policyG->env_options();
check(count($envOptsG) === 3, 'identical declarations dedupe while non-colliding names from both manifests survive');
check(($policyG->option_rule_details('shared_name')['source'] ?? null) === 'g3',
    'direct lookup resolves an identical collision to the first non-core pin');
check(($envOptsG['shared_name']['required'] ?? null) === false,
    'bulk env lookup returns that same resolved rule, never an independent later-pin overwrite');
check(isset($envOptsG['only_in_g3']) && isset($envOptsG['only_in_g4']),
    'non-colliding names from both manifests both survive the union');

// A checked-in site policy override is authored intent about the collision,
// so it bypasses the manifest contradiction gate and wins in both lookup
// shapes on a fresh Policy::load() with no capture-time memory.
$repo = $fixtureDir . '-site-override';
mkdir($repo, 0777, true);
register_shutdown_function(static function () use ($repo): void {
    @unlink("$repo/site.wprism.json");
    @rmdir($repo);
});
file_put_contents("$repo/site.wprism.json", json_encode([
    'manifests' => ['g1', 'g2'],
    'policy' => [
        'options' => [
            'shared_name' => ['class' => 'env', 'required' => true],
        ],
    ],
], JSON_PRETTY_PRINT));
$resolved = manifest_fixture_policy_load($fixtureDir, $repo);
$resolvedDetails = $resolved->option_rule_details('shared_name');
check(($resolvedDetails['source'] ?? null) === 'site.wprism.json',
    'an explicit site policy override resolves the synthetic contradiction on a fresh load');
check(($resolved->env_options()['shared_name']['required'] ?? null) === true,
    'bulk env enumeration uses the exact same site-resolved rule end to end');

// The pure declaration checks belong to OptionGrammar, while Policy retains
// only the runtime effective-rule/query behavior. Keep both loader paths and
// the published sentinel vocabulary wired directly to the collaborator.
//
// issue #3496 adds a THIRD call site inside Policy — set_rule()'s write boundary,
// which runs the same two validators over the single rule it is about to write
// so `wp wprism classify` can no longer produce a site.wprism.json the next
// Policy::load() refuses. That is the guard's own point rather than an
// exception to it: the alternative was restating the two checks inside
// set_rule, which is exactly the duplication this assertion exists to catch.
// It stays pinned at one call each so a second, drifting copy still fails.
$policySource = file_get_contents(__DIR__ . '/../../../../agent/src/Policy/Policy.php');
$manifestValidatorSource = file_get_contents(__DIR__ . '/../../../../agent/src/Policy/ManifestValidator.php');
$sitePolicyValidatorSource = file_get_contents(__DIR__ . '/../../../../agent/src/Policy/SitePolicyValidator.php');
$optionGrammar = new \ReflectionClass('WPrism\\OptionGrammar');
$policyReflection = new \ReflectionClass(Policy::class);
check(
    $optionGrammar->hasMethod('validate_env_options')
        && $optionGrammar->getMethod('validate_env_options')->isPublic()
        && $optionGrammar->hasMethod('validate_option_storage')
        && $optionGrammar->getMethod('validate_option_storage')->isPublic()
        && $optionGrammar->hasMethod('optionAutoloadSentinels')
        && !$policyReflection->hasMethod('validate_env_options')
        && !$policyReflection->hasMethod('validate_option_storage')
        && substr_count($policySource, 'OptionGrammar::validate_env_options(') === 1
        && substr_count($policySource, 'OptionGrammar::validate_option_storage(') === 1
        && str_contains($policySource, 'private static function assert_option_rule_loads(')
        && strpos($policySource, 'assert_option_rule_loads(') < strpos($policySource, 'OptionGrammar::validate_option_storage(')
        && substr_count($manifestValidatorSource, 'OptionGrammar::validate_env_options(') === 1
        && substr_count($manifestValidatorSource, 'OptionGrammar::validate_option_storage(') === 1
        && substr_count($sitePolicyValidatorSource, 'OptionGrammar::validate_env_options(') === 1
        && substr_count($sitePolicyValidatorSource, 'OptionGrammar::validate_option_storage(') === 1
        && substr_count($policySource, 'OptionGrammar::optionAutoloadSentinels()') === 1
        && !str_contains($policySource, 'OPTION_AUTOLOAD_SENTINELS'),
    'option declaration/storage validation and sentinel publication live in OptionGrammar; Policy has no duplicate validators, only set_rule\'s write-boundary call'
);

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
