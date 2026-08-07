<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * DUO-3232's Policy.php-side wiring: validate_env_options()'s mandatory-
 * `required`-boolean load-time gate and env_options()'s enumeration
 * (merge precedence, ksort, with_option_autoload() wiring). Uses FAKE
 * fixture manifests via DUO_MANIFESTS_DIR, never the real shipped
 * manifests — this file proves the MECHANISM works in isolation.
 *
 * What this file deliberately does NOT test:
 *   - Apply::set_env_option() itself (the actual wp_options write) — like
 *     every other Apply.php-touching change in this codebase, that needs
 *     a live $wpdb and gets a live, docker-based proof instead (see
 *     sandbox/tests/regress_env_set.sh), not a FakeWpdb offline harness.
 *   - site.duo.json policy-override precedence (a manifest's env rule
 *     replaced or reclassified away by a site policy override) — no
 *     offline regress_*_policy.php test in this repo constructs a fixture
 *     site.duo.json (Policy::load()'s $repo-null path never populates
 *     $p->site at all), so this follows the same established split and
 *     leaves that path to the live test too, alongside `wp duo plan`'s
 *     env_missing rendering and `wp duo env-set` end-to-end.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

$fixtureDir = sys_get_temp_dir() . '/duo_regress_env_options_' . bin2hex(random_bytes(4));
mkdir($fixtureDir, 0777, true);
register_shutdown_function(function () use ($fixtureDir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($fixtureDir);
});
putenv("DUO_MANIFESTS_DIR=$fixtureDir");

require __DIR__ . '/../../agent/src/Canon.php';
require __DIR__ . '/../../agent/src/OptionState.php';
require __DIR__ . '/../../agent/src/Policy.php';

use Duo\Policy;

// DUO-3247: spec_version is mandatory at Policy::load() — this file never
// requires agent/duo.php, so DUO_SPEC_VERSION would otherwise be
// undefined here (same fallback-define regress_regen_dependency_policy.php
// uses). Every fixture below must declare it just to get past that gate.
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
echo "\n== validate_env_options() — mandatory 'required' boolean ==\n";

write_manifest($fixtureDir, 'a', [
    'name' => 'a',
    'spec_version' => DUO_SPEC_VERSION,
    'options' => ['api_key' => ['class' => 'env']], // missing required entirely
]);
check_throws(fn() => Policy::load(null, ['a']), 'options.api_key.class="env" needs an explicit boolean',
    'missing required key refuses at load()');

write_manifest($fixtureDir, 'b', [
    'name' => 'b',
    'spec_version' => DUO_SPEC_VERSION,
    'options' => ['api_key' => ['class' => 'env', 'required' => 'true']], // string, not bool
]);
check_throws(fn() => Policy::load(null, ['b']), 'options.api_key.class="env" needs an explicit boolean',
    'string "true" (not a real bool) refuses at load()');

write_manifest($fixtureDir, 'c', [
    'name' => 'c',
    'spec_version' => DUO_SPEC_VERSION,
    'options' => ['api_key' => ['class' => 'env', 'required' => 1]], // int, not bool
]);
check_throws(fn() => Policy::load(null, ['c']), 'options.api_key.class="env" needs an explicit boolean',
    'int 1 (not a real bool) refuses at load()');

// Non-env classes never need 'required' — the gate is scoped to class:"env"
// only, same as validate_option_storage()'s own authored/managed scoping.
write_manifest($fixtureDir, 'd', [
    'name' => 'd',
    'spec_version' => DUO_SPEC_VERSION,
    'options' => [
        'runtime_thing' => ['class' => 'runtime'],
        'derived_thing' => ['class' => 'derived'],
    ],
]);
try {
    Policy::load(null, ['d']);
    check(true, 'non-env classes never require the required flag');
} catch (\Throwable $t) {
    check(false, 'non-env classes never require the required flag (threw: ' . $t->getMessage() . ')');
}

// Well-formed declarations, both required values, load cleanly.
write_manifest($fixtureDir, 'e', [
    'name' => 'e',
    'spec_version' => DUO_SPEC_VERSION,
    'options' => [
        'gateway_key' => ['class' => 'env', 'required' => true],
        'install_marker' => ['class' => 'env', 'required' => false],
        'a_runtime_option' => ['class' => 'runtime'],
    ],
]);
try {
    Policy::load(null, ['e']);
    check(true, 'a well-formed mix of required:true/required:false/non-env options loads without error');
} catch (\Throwable $t) {
    check(false, 'a well-formed mix loads without error (threw: ' . $t->getMessage() . ')');
}

// ======================================================================
echo "\n== env_options() — enumeration, merge, ksort ==\n";

$policy = Policy::load(null, ['e']);
$envOpts = $policy->env_options();
check(count($envOpts) === 2, 'env_options() returns exactly the 2 class="env" rules, excluding the runtime one');
check(($envOpts['gateway_key']['required'] ?? null) === true, 'required:true round-trips exactly (strict ===, not merely truthy)');
check(($envOpts['install_marker']['required'] ?? null) === false, 'required:false round-trips exactly (strict ===, not merely falsy)');
check(!isset($envOpts['a_runtime_option']), 'a runtime-classified option never appears in env_options()');
check(array_keys($envOpts) === ['gateway_key', 'install_marker'], 'ksort(SORT_STRING) orders keys alphabetically regardless of declaration order');

// with_option_autoload(): a manifest-level option_autoload default must
// flow into an env rule's resolved 'autoload' the same way it already
// does for authored_options()/sub_keyed_options() — DUO-3232 extended
// env_options() to call the identical helper those two already used.
write_manifest($fixtureDir, 'f', [
    'name' => 'f',
    'spec_version' => DUO_SPEC_VERSION,
    'option_autoload' => 'preserve',
    'options' => [
        'inherits_default' => ['class' => 'env', 'required' => true],
        'overrides_default' => ['class' => 'env', 'required' => true, 'autoload' => 'no'],
    ],
]);
$policyF = Policy::load(null, ['f']);
$envOptsF = $policyF->env_options();
check(($envOptsF['inherits_default']['autoload'] ?? null) === 'preserve',
    "a rule with no own 'autoload' inherits the manifest's option_autoload default");
check(($envOptsF['overrides_default']['autoload'] ?? null) === 'no',
    "a rule's own explicit 'autoload' wins over the manifest default, not merely present alongside it");

// Two manifests pinned together: env options from both are present,
// same-named collision resolved by pin order (first pin wins — matches
// rule_details()'s own "first manifest in pin order" precedent, since
// env_options() walks $this->manifests in the same order Policy::load()
// populated it, in).
write_manifest($fixtureDir, 'g1', [
    'name' => 'g1',
    'spec_version' => DUO_SPEC_VERSION,
    'options' => ['shared_name' => ['class' => 'env', 'required' => true], 'only_in_g1' => ['class' => 'env', 'required' => false]],
]);
write_manifest($fixtureDir, 'g2', [
    'name' => 'g2',
    'spec_version' => DUO_SPEC_VERSION,
    'options' => ['shared_name' => ['class' => 'env', 'required' => false], 'only_in_g2' => ['class' => 'env', 'required' => false]],
]);
$policyG = Policy::load(null, ['g1', 'g2']);
$envOptsG = $policyG->env_options();
check(count($envOptsG) === 3, 'two pinned manifests union their env options (3 distinct names, not 4 — shared_name collides to one)');
check(($envOptsG['shared_name']['required'] ?? null) === false,
    'a same-named rule declared in both pinned manifests resolves to the LATER pin (g2) — env_options() walks '
    . 'manifests in pin order and a later foreach iteration overwrites $out[$name], same as authored_options()\'s '
    . 'own loop shape, not "first pin wins"');
check(isset($envOptsG['only_in_g1']) && isset($envOptsG['only_in_g2']), 'non-colliding names from both manifests both survive the union');

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
