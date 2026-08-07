<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * DUO-3272's Policy.php-side wiring: menu_field_rule_details()/
 * menu_field_class() reusing rule_details()'s DUO-3249 core-yields-to-
 * plugin precedence under a new 'menu_fields' section, and
 * active_menu_field_reclassifications()'s plan-visible reporting.
 * Deliberately the exact same shape as
 * regress_manifest_reclassification_policy.php (DUO-3249's own offline
 * test) — see that file's docblock for why: this proves the same
 * mechanism, generalized to a second section, not a new one. Uses FAKE
 * fixture manifests via DUO_MANIFESTS_DIR, never the real
 * manifests/core.json or manifests/polylang.json — this file proves the
 * MECHANISM works in isolation; sandbox/tests/grind_r3a_multilingual.sh is
 * the live proof against the real shipped manifests and a real Polylang
 * install (menu-location capture actually staying deterministic across a
 * simulated default-language flip).
 *
 * What this file deliberately does NOT re-litigate: DUO-3255 (two non-core
 * manifests colliding) — not re-proven here a second time; DUO-3249's own
 * test already proves that case is unaffected by the shared rule_details()
 * engine, and this file's own fixtures never exercise two non-core
 * manifests declaring the same menu_fields name.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

$fixtureDir = sys_get_temp_dir() . '/duo_regress_menu_reclass_policy_' . bin2hex(random_bytes(4));
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

function write_manifest(string $dir, string $name, array $json): void {
    file_put_contents("$dir/$name.json", json_encode($json, JSON_PRETTY_PRINT));
}

// ======================================================================
echo "\n== fixtures: a 'core' manifest declaring menu_fields.locations authored + a plugin that reclassifies it ==\n";

write_manifest($fixtureDir, 'core', [
    'name' => 'core',
    'spec_version' => DUO_SPEC_VERSION,
    'menu_fields' => [
        'locations' => ['class' => 'authored'],
    ],
]);
write_manifest($fixtureDir, 'plugin', [
    'name' => 'plugin',
    'spec_version' => DUO_SPEC_VERSION,
    'menu_fields' => [
        'locations' => ['class' => 'derived', 'note' => 'plugin manages per-language menu locations'],
    ],
]);

// ======================================================================
echo "\n== core alone: unaffected ==\n";

$core = Policy::load(null, ['core']);
check($core->menu_field_class('locations') === 'authored', 'locations stays authored with core alone');
$d = $core->menu_field_rule_details('locations');
check(($d['source'] ?? null) === 'core', 'source is core with core alone');
check($core->active_menu_field_reclassifications() === [], 'active_menu_field_reclassifications() is empty with core alone');

// ======================================================================
echo "\n== a field nobody declares at all: defensive 'authored' fallback ==\n";

check($core->menu_field_class('nonexistent_field') === 'authored', "an undeclared field name still falls back to 'authored' defensively");

// ======================================================================
echo "\n== core + plugin, pinned core FIRST (the universal shipped convention) ==\n";

$both = Policy::load(null, ['core', 'plugin']);
check($both->menu_field_class('locations') === 'derived', "plugin's reclassification wins over core's own declaration (core pinned first)");
$d = $both->menu_field_rule_details('locations');
check(($d['source'] ?? null) === 'plugin', 'source is the plugin manifest, not core');
$reclass = $both->active_menu_field_reclassifications();
check(count($reclass) === 1, 'active_menu_field_reclassifications() reports exactly one entry');
check(($reclass[0]['name'] ?? null) === 'locations', 'reclassification entry names locations');
check(($reclass[0]['core_class'] ?? null) === 'authored', "reclassification entry's core_class is authored");
check(($reclass[0]['active_class'] ?? null) === 'derived', "reclassification entry's active_class is derived");
check(($reclass[0]['overridden_by'] ?? null) === 'plugin', "reclassification entry's overridden_by names the plugin");

// ======================================================================
echo "\n== core + plugin, pinned REVERSED (plugin first, core last) — must be IDENTICAL ==\n";
// This is the actual point of reusing rule_details(): without its
// core-yields-to-plugin fix, a plain first-pin-order walk would already
// (accidentally) get this one right, masking the bug that only shows up
// in the universal core-first convention tested above.

$reversed = Policy::load(null, ['plugin', 'core']);
check($reversed->menu_field_class('locations') === 'derived', 'plugin still wins with reversed pin order');
$d = $reversed->menu_field_rule_details('locations');
check(($d['source'] ?? null) === 'plugin', 'source is still the plugin manifest with reversed pin order');

// ======================================================================
echo "\n== a plugin declaring the SAME class as core (no actual override) is not reported as a reclassification ==\n";

write_manifest($fixtureDir, 'agree', [
    'name' => 'agree',
    'spec_version' => DUO_SPEC_VERSION,
    'menu_fields' => ['locations' => ['class' => 'authored']],
]);
$agreeing = Policy::load(null, ['core', 'agree']);
check($agreeing->active_menu_field_reclassifications() === [], 'no reclassification reported when the plugin agrees with core\'s own class');

// ======================================================================
echo "\n== validate_menu_field_classes(): loud load-time rejection of an unsupported field name ==\n";

write_manifest($fixtureDir, 'badfield', [
    'name' => 'badfield',
    'spec_version' => DUO_SPEC_VERSION,
    'menu_fields' => ['items' => ['class' => 'derived']],
]);
$threw = false;
$msg = '';
try {
    Policy::load(null, ['core', 'badfield']);
} catch (\RuntimeException $e) {
    $threw = true;
    $msg = $e->getMessage();
}
check($threw, 'an unsupported menu_fields field name fails load() loudly, not silently');
check(str_contains($msg, 'menu_fields.items'), "the thrown message names the offending declaration (got: $msg)");

// ======================================================================
echo "\n== validate_menu_field_classes(): loud load-time rejection of an unsupported class value ==\n";

write_manifest($fixtureDir, 'badclass', [
    'name' => 'badclass',
    'spec_version' => DUO_SPEC_VERSION,
    'menu_fields' => ['locations' => ['class' => 'runtime']],
]);
$threw = false;
$msg = '';
try {
    Policy::load(null, ['core', 'badclass']);
} catch (\RuntimeException $e) {
    $threw = true;
    $msg = $e->getMessage();
}
check($threw, "an unsupported menu_fields.locations class value fails load() loudly, not silently");
check(str_contains($msg, 'menu_fields.locations.class'), "the thrown message names the offending declaration (got: $msg)");

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
