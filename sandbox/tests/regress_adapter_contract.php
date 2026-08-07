<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * DUO-3222: the version-pinned adapter compatibility contract.
 *
 * Policy::load()'s new validators (validate_adapter_contract(),
 * validate_no_conflicting_adapter_claims()) and RepositoryCompiler's new
 * per-manifest digest/resolved_adapters() computation are pure — no $wpdb,
 * no WordPress function, by design (RepositoryCompiler's own class
 * docblock: repository + manifest inputs become a validated IR "before
 * Tokens, Ledger, Capture, or a target query can be constructed"). Every
 * check here runs against REAL manifest fixture files this test writes to
 * a scratch DUO_MANIFESTS_DIR, using the REAL, unmodified
 * agent/src/{Canon,Policy,RepositoryCompiler}.php — not reimplementations.
 *
 * What this file does NOT cover (needs a live WordPress + real installed
 * plugin/theme, so it's out of reach offline): Deploy::code_mismatch()'s
 * live version read for plugins (already covered by the existing
 * sandbox/tests/spike_g_code.sh (e), unmodified by this issue) and its new
 * theme counterpart (covered by
 * sandbox/tests/regress_adapter_theme_range.sh instead — own sandbox pair,
 * own conformance sweep, see that script's header). Deploy::in_range()'s
 * OWN min-inclusive/max-exclusive arithmetic is exercised here via
 * Reflection (same private-method-testing idiom
 * regress_capture_publish.php already uses for
 * Capture::check_transient_db_error()) — that piece IS pure.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

require __DIR__ . '/../../agent/src/Canon.php';
require __DIR__ . '/../../agent/src/Policy.php';
require __DIR__ . '/../../agent/src/RepositoryCompiler.php';
require __DIR__ . '/../../agent/src/RepositoryAuthorization.php';
require __DIR__ . '/../../agent/src/Deploy.php';

use Duo\Canon;
use Duo\Policy;
use Duo\RepositoryCompiler;

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

function expect_throw(callable $fn, string $needle, string $msg): void {
    try {
        $fn();
        check(false, "$msg (expected a RuntimeException containing '$needle', none thrown)");
    } catch (\RuntimeException $e) {
        check(
            str_contains($e->getMessage(), $needle),
            "$msg (message: {$e->getMessage()})"
        );
    }
}

/** Fresh scratch manifests dir for one test group; auto-removed at exit. */
function fresh_manifests_dir(array $files): string {
    $root = sys_get_temp_dir() . '/duo_regress_adapter_contract_' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    foreach ($files as $name => $content) {
        Canon::write_file("$root/$name.json", is_string($content) ? $content : json_encode($content, JSON_PRETTY_PRINT));
    }
    register_shutdown_function(function () use ($root) {
        foreach (glob("$root/*") as $f) {
            unlink($f);
        }
        rmdir($root);
    });
    putenv("DUO_MANIFESTS_DIR=$root");
    return $root;
}

// ======================================================================
echo "\n== positive path: well-formed plugin/theme + spec_version load cleanly ==\n";

fresh_manifests_dir([
    'good' => [
        'name' => 'good',
        'spec_version' => DUO_SPEC_VERSION,
        'plugin' => 'acme/acme.php',
        'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
        'theme' => 'acme-theme',
        'theme_version_range' => ['min' => '3.0.0', 'max' => '4.0.0'],
    ],
]);
$p = Policy::load(null, ['good']);
check(true, 'well-formed manifest (spec_version + plugin/version_range + theme/theme_version_range) loads without throwing');
$vr = $p->version_ranges();
check(
    isset($vr['acme/acme.php']) && $vr['acme/acme.php'] === ['min' => '1.0.0', 'max' => '2.0.0', 'manifest' => 'good'],
    'version_ranges() returns the declared plugin range keyed by basename'
);
$tr = $p->theme_ranges();
check(
    isset($tr['acme-theme']) && $tr['acme-theme'] === ['min' => '3.0.0', 'max' => '4.0.0', 'manifest' => 'good'],
    'theme_ranges() returns the declared theme range keyed by theme directory name'
);

echo "\n== spec_version: MANDATORY (DUO-3247) — absent hard-fails, present-and-correct passes, present-and-WRONG hard-fails ==\n";

// DUO-3247: absence stopped being lenient the moment DUO_SPEC_VERSION got a
// second historical value (DUO-3210's 0->1 bump) — this is the pre-committed
// flip from DUO-3222's own design review, actioned here. Absent and
// declared-and-wrong are now the SAME failure (see validate_adapter_contract()'s
// docblock), so both assertions below check for the same 'spec_version'
// needle through the one throw site.
fresh_manifests_dir(['noSpec' => ['name' => 'noSpec']]);
expect_throw(
    fn() => Policy::load(null, ['noSpec']),
    'spec_version',
    'absent spec_version hard-fails (DUO-3247: mandatory now, not lenient — was the DUO-3222-era behavior before this issue)'
);

fresh_manifests_dir(['rightSpec' => ['name' => 'rightSpec', 'spec_version' => DUO_SPEC_VERSION]]);
Policy::load(null, ['rightSpec']);
check(true, 'declared-and-correct spec_version loads cleanly');

fresh_manifests_dir(['wrongSpec' => ['name' => 'wrongSpec', 'spec_version' => DUO_SPEC_VERSION + 1]]);
expect_throw(
    fn() => Policy::load(null, ['wrongSpec']),
    'spec_version',
    'declared-and-WRONG spec_version hard-fails (same failure as absent now, per DUO-3247)'
);

echo "\n== unbounded/malformed ranges are refused — 'no latest/wildcard/unbounded support may be certified' ==\n";

// DUO-3247: every fixture below now needs 'spec_version' => DUO_SPEC_VERSION
// just to get PAST the (now mandatory) spec_version gate and actually reach
// the version_range check each one exists to exercise — without it every
// one of these would hard-fail on the spec_version needle instead.
fresh_manifests_dir(['noRange' => ['name' => 'noRange', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php']]);
expect_throw(
    fn() => Policy::load(null, ['noRange']),
    'unbounded',
    'plugin declared with NO version_range at all is refused (today\'s silent-skip is the failure mode DUO-3222 closes)'
);

fresh_manifests_dir(['missingMax' => ['name' => 'missingMax', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['min' => '1.0.0']]]);
expect_throw(fn() => Policy::load(null, ['missingMax']), 'malformed range', 'version_range missing max is refused');

fresh_manifests_dir(['missingMin' => ['name' => 'missingMin', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['max' => '2.0.0']]]);
expect_throw(fn() => Policy::load(null, ['missingMin']), 'malformed range', 'version_range missing min is refused');

fresh_manifests_dir(['minGteMax' => ['name' => 'minGteMax', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['min' => '2.0.0', 'max' => '2.0.0']]]);
expect_throw(fn() => Policy::load(null, ['minGteMax']), 'malformed range', 'version_range with min == max (not strictly less) is refused');

fresh_manifests_dir(['minGtMax' => ['name' => 'minGtMax', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['min' => '3.0.0', 'max' => '2.0.0']]]);
expect_throw(fn() => Policy::load(null, ['minGtMax']), 'malformed range', 'version_range with min > max is refused');

fresh_manifests_dir(['wildcard' => ['name' => 'wildcard', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['min' => '*', 'max' => '*']]]);
expect_throw(fn() => Policy::load(null, ['wildcard']), 'malformed range', 'wildcard "*" min/max is refused, not silently treated as unbounded');

fresh_manifests_dir(['emptyPlugin' => ['name' => 'emptyPlugin', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => '']]);
expect_throw(fn() => Policy::load(null, ['emptyPlugin']), "non-string or empty", 'empty-string plugin identity is refused');

echo "\n== theme mirrors every plugin malformation exactly (same validator, same code path) ==\n";

fresh_manifests_dir(['themeNoRange' => ['name' => 'themeNoRange', 'spec_version' => DUO_SPEC_VERSION, 'theme' => 'acme-theme']]);
expect_throw(fn() => Policy::load(null, ['themeNoRange']), 'unbounded', 'theme declared with NO theme_version_range is refused');

fresh_manifests_dir(['themeBadRange' => ['name' => 'themeBadRange', 'spec_version' => DUO_SPEC_VERSION, 'theme' => 'acme-theme', 'theme_version_range' => ['min' => '5.0.0', 'max' => '1.0.0']]]);
expect_throw(fn() => Policy::load(null, ['themeBadRange']), 'malformed range', 'theme_version_range with min > max is refused');

echo "\n== conflicting ownership: same plugin/theme, different ranges, no v1 composition escape hatch ==\n";

fresh_manifests_dir([
    'confA' => ['name' => 'confA', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['min' => '1.0.0', 'max' => '2.0.0']],
    'confB' => ['name' => 'confB', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['min' => '2.0.0', 'max' => '3.0.0']],
]);
expect_throw(
    fn() => Policy::load(null, ['confA', 'confB']),
    'conflicting ownership',
    'two pinned manifests naming the SAME plugin with DIFFERENT ranges is refused (load-order-independent — this is the guard against it)'
);

fresh_manifests_dir([
    'confThemeA' => ['name' => 'confThemeA', 'spec_version' => DUO_SPEC_VERSION, 'theme' => 'acme-theme', 'theme_version_range' => ['min' => '1.0.0', 'max' => '2.0.0']],
    'confThemeB' => ['name' => 'confThemeB', 'spec_version' => DUO_SPEC_VERSION, 'theme' => 'acme-theme', 'theme_version_range' => ['min' => '9.0.0', 'max' => '10.0.0']],
]);
expect_throw(
    fn() => Policy::load(null, ['confThemeA', 'confThemeB']),
    'conflicting ownership',
    'two pinned manifests naming the SAME theme with DIFFERENT ranges is refused'
);

// Identical ranges: redundant, not ambiguous — deliberately ALLOWED (see
// validate_no_conflicting_adapter_claims()'s own docblock for why).
fresh_manifests_dir([
    'dupA' => ['name' => 'dupA', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['min' => '1.0.0', 'max' => '2.0.0']],
    'dupB' => ['name' => 'dupB', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['min' => '1.0.0', 'max' => '2.0.0']],
]);
Policy::load(null, ['dupA', 'dupB']);
check(true, 'two pinned manifests naming the SAME plugin with the IDENTICAL range load cleanly (redundant, not conflicting)');

echo "\n== resolved_adapters(): digest determinism + \"schema change without version change\" detection ==\n";

// DUO-3247: spec_version is declared here (was deliberately absent before
// this issue, to test resolved_adapters()'s own null-reporting fallback —
// that scenario is now UNREACHABLE, since Policy::load() hard-fails on an
// undeclared spec_version before a Policy object naming this manifest can
// exist at all; the assertion below was repointed to the declared-value
// case instead of deleted, so resolved_adapters()'s spec_version field is
// still covered).
$dirA = fresh_manifests_dir(['woo' => ['name' => 'woo', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'woocommerce/woocommerce.php', 'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'], 'options' => ['a' => ['class' => 'authored']]]]);
$pA = Policy::load(null, ['woo']);
$adaptersA = RepositoryCompiler::resolved_adapters($pA);
check(count($adaptersA) === 1 && $adaptersA[0]['name'] === 'woo', 'resolved_adapters() returns one row per pinned manifest, correctly named');
check($adaptersA[0]['plugin'] === 'woocommerce/woocommerce.php', 'resolved_adapters() row carries the declared plugin identity');
check($adaptersA[0]['version_range'] === ['min' => '1.0.0', 'max' => '2.0.0'], 'resolved_adapters() row carries the declared version_range verbatim');
check($adaptersA[0]['spec_version'] === DUO_SPEC_VERSION, 'resolved_adapters() row carries the declared (now-mandatory) spec_version');
check(preg_match('/^[0-9a-f]{64}$/', $adaptersA[0]['digest']) === 1, 'resolved_adapters() digest is a real sha256 hex string');

// SAME name/plugin/version_range, but a DIFFERENT rule elsewhere in the
// manifest (the exact "schema change without version change" case the
// issue's own Evidence-required list names — no version field moved at
// all, only unrelated manifest content did).
$dirB = fresh_manifests_dir(['woo' => ['name' => 'woo', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'woocommerce/woocommerce.php', 'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'], 'options' => ['a' => ['class' => 'env']]]]);
$pB = Policy::load(null, ['woo']);
$adaptersB = RepositoryCompiler::resolved_adapters($pB);
check(
    $adaptersA[0]['digest'] !== $adaptersB[0]['digest'],
    'digest CHANGES when manifest content changes even though name/plugin/version_range are byte-identical — "schema change without version change" is caught by content-addressing, not by the version fields alone'
);

// Re-loading the IDENTICAL first fixture reproduces the IDENTICAL digest —
// determinism, not just "differs when different."
putenv("DUO_MANIFESTS_DIR=$dirA");
$pA2 = Policy::load(null, ['woo']);
check(
    RepositoryCompiler::resolved_adapters($pA2)[0]['digest'] === $adaptersA[0]['digest'],
    'digest is deterministic — identical manifest content re-hashes to the identical digest'
);

check(
    RepositoryCompiler::manifest_hash($pA) !== RepositoryCompiler::manifest_hash($pB),
    'the pre-existing combined manifest_hash() also moves — resolved_adapters() digests are the SAME underlying bytes exposed per-adapter, not a second independently-maintained notion of identity'
);

echo "\n== Deploy::in_range() edge arithmetic (min inclusive, max exclusive) — via Reflection, the same private-method idiom regress_capture_publish.php already uses ==\n";

// setAccessible() is a no-op on PHP 8.1+ (private methods are always
// Reflection-invokable) and deprecated to call at all on newer PHP —
// omitted deliberately, not missing.
$inRange = new \ReflectionMethod(\Duo\Deploy::class, 'in_range');
check($inRange->invoke(null, '1.0.0', '1.0.0', '2.0.0') === true, 'installed == min is IN range (min inclusive)');
check($inRange->invoke(null, '2.0.0', '1.0.0', '2.0.0') === false, 'installed == max is OUTSIDE range (max exclusive)');
check($inRange->invoke(null, '1.9.9', '1.0.0', '2.0.0') === true, 'installed just below max is in range');
check($inRange->invoke(null, '0.9.9', '1.0.0', '2.0.0') === false, 'installed just below min is outside range');
check($inRange->invoke(null, '2.0.1', '1.0.0', '2.0.0') === false, 'installed above max is outside range (unsupported upgrade — the exact scenario a real plugin/theme update out of a pinned range produces)');

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
