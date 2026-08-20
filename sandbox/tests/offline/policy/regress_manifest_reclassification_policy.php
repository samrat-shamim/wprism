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
 * isolation; sandbox/tests/grind/grind_r3a_multilingual.sh is the live
 * proof against the real shipped manifests and a real Polylang install
 * (en/de, and the reclassification is what lets its zero-exclusion
 * byte-identity diff hold with no default_category carve-out — see that
 * script's own note at the DIFF_OUT assertion, and manifests/
 * polylang.json's DUO-3249 note, which cites the same run).
 *
 * DUO-3255 later ratified the formerly-undecided non-core collision case:
 * contradictory rules refuse, identical rules dedupe. The final checks
 * preserve this file's original boundary proof while asserting that new
 * ruling does not disturb core-yields-to-plugin reclassification.
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

require __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require __DIR__ . '/../../../../agent/src/Policy/Policy.php';

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
echo "\n== non-core collisions: contradictions refuse, identical rules dedupe (DUO-3255) ==\n";

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
check_throws(fn() => Policy::load(null, ['p1', 'p2']), 'contradictory rules for options.shared_name',
    'two non-core manifests with different classes refuse instead of selecting a pin-order winner');

write_manifest($fixtureDir, 'p3', [
    'name' => 'p3',
    'spec_version' => DUO_SPEC_VERSION,
    'options' => ['shared_name' => ['class' => 'runtime']],
]);
write_manifest($fixtureDir, 'p4', [
    'name' => 'p4',
    'spec_version' => DUO_SPEC_VERSION,
    'options' => ['shared_name' => ['class' => 'runtime']],
]);
$identical = Policy::load(null, ['p3', 'p4']);
$d = $identical->option_rule_details('shared_name');
check(($d['source'] ?? null) === 'p3', 'identical non-core declarations dedupe and direct lookup uses the first pin');

write_manifest($fixtureDir, 'p5', [
    'name' => 'p5',
    'spec_version' => DUO_SPEC_VERSION,
    'option_autoload' => 'preserve',
    'options' => ['shared_authored' => ['class' => 'authored', 'ref' => 'post']],
]);
write_manifest($fixtureDir, 'p6', [
    'name' => 'p6',
    'spec_version' => DUO_SPEC_VERSION,
    'option_autoload' => 'preserve',
    'options' => ['shared_authored' => ['class' => 'authored', 'ref' => 'post']],
]);
$identicalAuthored = Policy::load(null, ['p5', 'p6']);
check(($identicalAuthored->option_rule_details('shared_authored')['source'] ?? null) === 'p5',
    'identical authored declarations use the first direct-lookup winner');
check(isset($identicalAuthored->authored_options()['shared_authored']),
    'authored_options() enumerates that same resolved declaration exactly once');

write_manifest($fixtureDir, 'p7', [
    'name' => 'p7',
    'spec_version' => DUO_SPEC_VERSION,
    'option_autoload' => 'preserve',
    'options' => [
        'shared_subkeys' => [
            'class' => 'env',
            'required' => false,
            'sub_keys' => ['portable' => ['class' => 'authored']],
        ],
    ],
]);
write_manifest($fixtureDir, 'p8', [
    'name' => 'p8',
    'spec_version' => DUO_SPEC_VERSION,
    'option_autoload' => 'preserve',
    'options' => [
        'shared_subkeys' => [
            'class' => 'env',
            'required' => false,
            'sub_keys' => ['portable' => ['class' => 'authored']],
        ],
    ],
]);
$identicalSubkeys = Policy::load(null, ['p7', 'p8']);
check(($identicalSubkeys->option_rule_details('shared_subkeys')['source'] ?? null) === 'p7',
    'identical sub-key declarations use the first direct-lookup winner');
check(isset($identicalSubkeys->sub_keyed_options()['shared_subkeys']),
    'sub_keyed_options() enumerates that same resolved declaration exactly once');

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
