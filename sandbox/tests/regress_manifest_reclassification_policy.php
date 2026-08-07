<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * DUO-3249's Policy.php-side wiring: rule_details()'s core-yields-to-
 * plugin precedence, authored_options()'s reclassification-away
 * reconciliation pass, and active_reclassifications()'s plan-visible
 * reporting. Uses FAKE fixture manifests via DUO_MANIFESTS_DIR (one named
 * literally "core", to exercise the exact structural-recognition path the
 * fix depends on), never the real manifests/core.json or
 * manifests/polylang.json — this file proves the MECHANISM works in
 * isolation; sandbox/tests/regress_polylang_default_category.sh is the
 * live proof against the real shipped manifests and a real Polylang
 * install (default_category actually converging correctly across two
 * languages).
 *
 * What this file deliberately does NOT re-litigate: the SEPARATE,
 * undecided question of two NON-core manifests declaring the same name
 * (DUO-3255) — the last check below proves that case is completely
 * UNCHANGED by this fix (still first-pin-order-wins, exactly as before),
 * not that it is now "correct" in some new sense.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

$fixtureDir = sys_get_temp_dir() . '/duo_regress_reclass_policy_' . bin2hex(random_bytes(4));
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
echo "\n== fixtures: a 'core' manifest + a plugin that reclassifies one of its options ==\n";

write_manifest($fixtureDir, 'core', [
    'name' => 'core',
    'spec_version' => DUO_SPEC_VERSION,
    'option_autoload' => 'preserve',
    'options' => [
        'default_category' => ['class' => 'authored', 'ref' => 'term'],
        'page_on_front' => ['class' => 'authored', 'ref' => 'post'],
    ],
]);
write_manifest($fixtureDir, 'plugin', [
    'name' => 'plugin',
    'spec_version' => DUO_SPEC_VERSION,
    'options' => [
        'default_category' => ['class' => 'derived', 'note' => 'plugin manages per-language'],
    ],
]);

// ======================================================================
echo "\n== core alone: unaffected ==\n";

$core = Policy::load(null, ['core']);
$d = $core->option_rule_details('default_category');
check(($d['rule']['class'] ?? null) === 'authored', 'default_category stays authored with core alone');
check(($d['source'] ?? null) === 'core', 'source is core with core alone');
check(isset($core->authored_options()['default_category']), 'default_category is in authored_options() with core alone');
check($core->active_reclassifications() === [], 'active_reclassifications() is empty with core alone');

// ======================================================================
echo "\n== core + plugin, pinned core FIRST (the universal shipped convention) ==\n";

$both = Policy::load(null, ['core', 'plugin']);
$d = $both->option_rule_details('default_category');
check(($d['rule']['class'] ?? null) === 'derived', "plugin's reclassification wins over core's own declaration (core pinned first)");
check(($d['source'] ?? null) === 'plugin', 'source is the plugin manifest, not core');
check(!isset($both->authored_options()['default_category']), 'default_category is correctly EXCLUDED from authored_options() once reclassified derived');
$reclass = $both->active_reclassifications();
check(count($reclass) === 1, 'active_reclassifications() reports exactly one entry');
check(($reclass[0]['name'] ?? null) === 'default_category', 'reclassification entry names default_category');
check(($reclass[0]['core_class'] ?? null) === 'authored', "reclassification entry's core_class is authored");
check(($reclass[0]['active_class'] ?? null) === 'derived', "reclassification entry's active_class is derived");
check(($reclass[0]['overridden_by'] ?? null) === 'plugin', "reclassification entry's overridden_by names the plugin");

// ======================================================================
echo "\n== core + plugin, pinned REVERSED (plugin first, core last) — must be IDENTICAL ==\n";
// This is the actual point of the fix: without it, a plain first-pin-order
// walk would already (accidentally) get this one right, masking the bug
// that only shows up in the universal core-first convention tested above.

$reversed = Policy::load(null, ['plugin', 'core']);
$d = $reversed->option_rule_details('default_category');
check(($d['rule']['class'] ?? null) === 'derived', 'plugin still wins with reversed pin order');
check(($d['source'] ?? null) === 'plugin', 'source is still the plugin manifest with reversed pin order');
check(!isset($reversed->authored_options()['default_category']), 'still excluded from authored_options() with reversed pin order');

// ======================================================================
echo "\n== an unrelated core option the plugin never touches is completely unaffected ==\n";

$d = $both->option_rule_details('page_on_front');
check(($d['rule']['class'] ?? null) === 'authored', 'page_on_front stays authored');
check(($d['source'] ?? null) === 'core', 'page_on_front source stays core');
check(isset($both->authored_options()['page_on_front']), 'page_on_front stays in authored_options()');

// ======================================================================
echo "\n== a plugin declaring the SAME class as core (no actual override) is not reported as a reclassification ==\n";

write_manifest($fixtureDir, 'agree', [
    'name' => 'agree',
    'spec_version' => DUO_SPEC_VERSION,
    'option_autoload' => 'preserve',
    'options' => ['default_category' => ['class' => 'authored', 'ref' => 'term']],
]);
$agreeing = Policy::load(null, ['core', 'agree']);
check($agreeing->active_reclassifications() === [], 'no reclassification reported when the plugin agrees with core\'s own class');

// ======================================================================
echo "\n== NOT touched by this fix: two non-core manifests colliding stays first-pin-order-wins, unchanged (DUO-3255's own separate territory) ==\n";

write_manifest($fixtureDir, 'p1', [
    'name' => 'p1',
    'spec_version' => DUO_SPEC_VERSION,
    'option_autoload' => 'preserve',
    'options' => ['shared_name' => ['class' => 'authored']],
]);
write_manifest($fixtureDir, 'p2', [
    'name' => 'p2',
    'spec_version' => DUO_SPEC_VERSION,
    'options' => ['shared_name' => ['class' => 'runtime']],
]);
$collide = Policy::load(null, ['p1', 'p2']);
$d = $collide->option_rule_details('shared_name');
check(($d['source'] ?? null) === 'p1', 'two non-core manifests colliding still resolves to the FIRST one in pin order, exactly as before this fix (DUO-3255 territory, deliberately untouched)');
$collideReversed = Policy::load(null, ['p2', 'p1']);
$d2 = $collideReversed->option_rule_details('shared_name');
check(($d2['source'] ?? null) === 'p2', 'and reversing THEIR pin order still flips the winner, confirming this case is genuinely order-dependent still, not fixed here');

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
