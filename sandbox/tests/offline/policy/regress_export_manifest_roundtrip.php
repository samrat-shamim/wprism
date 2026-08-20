<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * DUO-3284: proves Policy::export_manifest()'s own output is loadable by
 * Policy::load() -- the round trip the real `wp duo policy-to-manifest`
 * command promises (export today, pin and load tomorrow) but which was
 * never actually exercised end to end. That gap is exactly how the
 * command silently produced manifests missing spec_version for as long
 * as DUO-3247's mandatory-spec_version gate has existed: nothing ever
 * fed an export back into load() to notice.
 *
 * This is the real deliverable, not the one-line export_manifest() fix
 * that makes it pass -- the fix alone re-closes today's instance of the
 * gap; this test is what keeps it closed the next time load()'s
 * requirements change.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

$fixtureDir = sys_get_temp_dir() . '/duo_regress_export_roundtrip_' . bin2hex(random_bytes(4));
$repoDir = $fixtureDir . '/repo';
$manifestsDir = $fixtureDir . '/manifests';
mkdir($repoDir, 0777, true);
mkdir($manifestsDir, 0777, true);
register_shutdown_function(function () use ($fixtureDir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($fixtureDir);
});

// Same fallback-define convention as regress_regen_dependency_policy.php
// and regress_env_options_policy.php: this file never requires
// agent/duo.php, so DUO_SPEC_VERSION would otherwise be undefined. The
// exact numeric value doesn't matter for what this test proves (that
// export and load agree with EACH OTHER, sourced from the same
// constant) -- it matters that export_manifest() reads it from the
// constant, never a literal, so it can never silently drift from
// whatever load() actually requires.
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 0);
}

putenv("DUO_MANIFESTS_DIR=$manifestsDir");

require __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require __DIR__ . '/../../../../agent/src/Policy/Policy.php';

use Duo\Policy;
use Duo\Canon;

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

// A minimal core manifest export_manifest()/load() both need present in
// DUO_MANIFESTS_DIR (every site.duo.json pins at least core by default).
file_put_contents("$manifestsDir/core.json", json_encode([
    'name' => 'core',
    'spec_version' => DUO_SPEC_VERSION,
    'options' => (object) [],
    'post_meta' => (object) [],
    'term_meta' => (object) [],
], JSON_PRETTY_PRINT));

// ======================================================================
echo "\n== export_manifest() writes spec_version, sourced from the constant ==\n";

file_put_contents("$repoDir/site.duo.json", json_encode([
    'manifests' => ['core'],
    'spec_version' => DUO_SPEC_VERSION,
    'policy' => [
        'options' => [
            'test_export_hero' => ['class' => 'authored', 'autoload' => 'preserve'],
            'unrelated_option' => ['class' => 'runtime'],
        ],
        'post_meta' => [],
        'term_meta' => [],
        'post_types' => [],
        'taxonomies' => [],
    ],
], JSON_PRETTY_PRINT));

$exported = Policy::export_manifest($repoDir, '^test_export_', 'export-roundtrip-test');
check(($exported['spec_version'] ?? null) === DUO_SPEC_VERSION,
    'exported manifest declares spec_version === DUO_SPEC_VERSION (got: ' . var_export($exported['spec_version'] ?? null, true) . ')');
// export_manifest() casts every section to (object), even non-empty
// ones ("force {} not [] when empty, matching manifest style" — its own
// comment), so property access, not array access.
check(isset($exported['options']->test_export_hero),
    'exported manifest still carries the matched option rule (spec_version addition did not disturb the rest of the shape)');
check(!isset($exported['options']->unrelated_option),
    '--match regex still correctly excludes non-matching keys');

// ======================================================================
echo "\n== the exported manifest is genuinely loadable by Policy::load() ==\n";

file_put_contents("$manifestsDir/export-roundtrip-test.json", Canon::encode($exported));

try {
    $reloaded = Policy::load(null, ['export-roundtrip-test']);
    check(true, 'Policy::load() accepted the exported manifest without throwing -- the actual round trip wp duo policy-to-manifest promises');
    check($reloaded->authored_options()['test_export_hero']['class'] === 'authored',
        'the reloaded policy carries the exported rule through intact');
} catch (\Throwable $t) {
    check(false, 'Policy::load() accepted the exported manifest without throwing (threw: ' . $t->getMessage() . ')');
}

// ======================================================================
echo "\n== negative control: an export with no spec_version (the pre-fix bug) is REFUSED by load(), proving this test would have caught it ==\n";

$broken = $exported;
unset($broken['spec_version']);
// A negative control must be broken in exactly ONE way. The copy lands under a
// second file name, and DUO-3371 refuses a manifest whose declared name is not
// its file name before any grammar validator runs — so without this line the
// check below would pass or fail on the identity refusal instead of the missing
// spec_version it exists to prove.
$broken['name'] = 'export-roundtrip-broken';
file_put_contents("$manifestsDir/export-roundtrip-broken.json", Canon::encode($broken));
try {
    Policy::load(null, ['export-roundtrip-broken']);
    check(false, 'load() should refuse a manifest with no spec_version (it did not -- this would have masked DUO-3284 entirely)');
} catch (\Throwable $t) {
    check(str_contains($t->getMessage(), 'spec_version'),
        'load() refuses a spec_version-less manifest, naming spec_version in the error (threw: ' . $t->getMessage() . ')');
}

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
