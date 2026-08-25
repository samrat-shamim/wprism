<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * DUO-3222/DUO-3243: the version-pinned adapter compatibility contract and
 * optional content-addressed site manifest pins.
 *
 * Policy::load()'s adapter contract validators and RepositoryCompiler's new
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
 * live version read. Both legs have their own live suite, each with its own
 * sandbox pair — the theme leg is
 * sandbox/tests/live/regress_adapter_theme_range.sh (DUO-3222) and the
 * plugin leg is sandbox/tests/live/regress_adapter_plugin_range.sh
 * (DUO-3487, rebuilding the proof #478 deleted along with the
 * duo-loop-demo-versioned demo manifest it had been built on). See each
 * script's header for what it asserts. Deploy::in_range()'s
 * OWN min-inclusive/max-exclusive arithmetic is exercised here via
 * Reflection (same private-method-testing idiom
 * regress_capture_publish.php already uses for
 * Capture::check_transient_db_error()) — that piece IS pure, and proving it
 * says nothing about where the installed version came from, which is
 * precisely what the two live legs are for.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

// WordPress supplies this in production. The offline harness exposes a
// switchable equivalent so Policy::load()'s real v1 single-site gate is
// exercised without bootstrapping WordPress or replacing the product path.
$GLOBALS['duo_test_is_multisite'] = false;
function is_multisite(): bool {
    return (bool) $GLOBALS['duo_test_is_multisite'];
}

require __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require __DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php';
require_once __DIR__ . '/../../../../agent/src/Repository/SidebarState.php';
require __DIR__ . '/../../../../agent/src/Repository/RepositoryAuthorization.php';
require __DIR__ . '/../../../../agent/src/Promotion/Deploy.php';

use Duo\Canon;
use Duo\Policy;
use Duo\RepositoryCompiler;

/** Minimal command runner surface for exercising the real Cli handler offline. */
final class WP_CLI {
    public static array $lines = [];

    public static function add_command($name, $class): void {}

    public static function line($line): void {
        self::$lines[] = (string) $line;
    }

    public static function error($message): void {
        throw new \RuntimeException((string) $message);
    }
}

require __DIR__ . '/../../../../agent/src/Command/Cli.php';

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

/** Fresh site repo containing only the policy contract under test. */
function fresh_site_repo(array $manifests): string {
    $root = sys_get_temp_dir() . '/duo_regress_manifest_pin_' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    Canon::write_file("$root/site.duo.json", Canon::encode([
        'manifests' => $manifests,
        'policy' => new \stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]));
    register_shutdown_function(function () use ($root) {
        @unlink("$root/site.duo.json");
        @rmdir($root);
    });
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

echo "\n== scope boundary: multisite refuses before policy loading or mutation ==\n";
$GLOBALS['duo_test_is_multisite'] = true;
expect_throw(
    fn() => Policy::load(null, ['good']),
    'multisite is unsupported by the certified v1 contract',
    'multisite fails closed through the real Policy::load() entry path'
);
$GLOBALS['duo_test_is_multisite'] = false;
Policy::load(null, ['good']);
check(true, 'single-site policy loading remains available after the refusal probe');

echo "\n== spec_version: MANDATORY (DUO-3247) — absent hard-fails, present-and-correct passes, present-and-WRONG hard-fails ==\n";

// DUO-3247: absence stopped being lenient the moment DUO_SPEC_VERSION got a
// second historical value (DUO-3210's 0->1 bump) — this is the pre-committed
// flip from DUO-3222's own design review, actioned here. Absent and
// declared-and-wrong are now the SAME failure (see AdapterContractGrammar's
// contract), so both assertions below check for the same 'spec_version'
// needle through the one throw site.
fresh_manifests_dir(['no-spec' => ['name' => 'no-spec']]);
expect_throw(
    fn() => Policy::load(null, ['no-spec']),
    'spec_version',
    'absent spec_version hard-fails (DUO-3247: mandatory now, not lenient — was the DUO-3222-era behavior before this issue)'
);

fresh_manifests_dir(['right-spec' => ['name' => 'right-spec', 'spec_version' => DUO_SPEC_VERSION]]);
Policy::load(null, ['right-spec']);
check(true, 'declared-and-correct spec_version loads cleanly');

fresh_manifests_dir(['wrong-spec' => ['name' => 'wrong-spec', 'spec_version' => DUO_SPEC_VERSION + 1]]);
expect_throw(
    fn() => Policy::load(null, ['wrong-spec']),
    'spec_version',
    'declared-and-WRONG spec_version hard-fails (same failure as absent now, per DUO-3247)'
);

echo "\n== unbounded/malformed ranges are refused — 'no latest/wildcard/unbounded support may be certified' ==\n";

// DUO-3247: every fixture below now needs 'spec_version' => DUO_SPEC_VERSION
// just to get PAST the (now mandatory) spec_version gate and actually reach
// the version_range check each one exists to exercise — without it every
// one of these would hard-fail on the spec_version needle instead.
fresh_manifests_dir(['no-range' => ['name' => 'no-range', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php']]);
expect_throw(
    fn() => Policy::load(null, ['no-range']),
    'unbounded',
    'plugin declared with NO version_range at all is refused (today\'s silent-skip is the failure mode DUO-3222 closes)'
);

fresh_manifests_dir(['missing-max' => ['name' => 'missing-max', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['min' => '1.0.0']]]);
expect_throw(fn() => Policy::load(null, ['missing-max']), 'malformed range', 'version_range missing max is refused');

fresh_manifests_dir(['missing-min' => ['name' => 'missing-min', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['max' => '2.0.0']]]);
expect_throw(fn() => Policy::load(null, ['missing-min']), 'malformed range', 'version_range missing min is refused');

fresh_manifests_dir(['min-gte-max' => ['name' => 'min-gte-max', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['min' => '2.0.0', 'max' => '2.0.0']]]);
expect_throw(fn() => Policy::load(null, ['min-gte-max']), 'malformed range', 'version_range with min == max (not strictly less) is refused');

fresh_manifests_dir(['min-gt-max' => ['name' => 'min-gt-max', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['min' => '3.0.0', 'max' => '2.0.0']]]);
expect_throw(fn() => Policy::load(null, ['min-gt-max']), 'malformed range', 'version_range with min > max is refused');

fresh_manifests_dir(['wildcard' => ['name' => 'wildcard', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['min' => '*', 'max' => '*']]]);
expect_throw(fn() => Policy::load(null, ['wildcard']), 'malformed range', 'wildcard "*" min/max is refused, not silently treated as unbounded');

fresh_manifests_dir(['empty-plugin' => ['name' => 'empty-plugin', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => '']]);
expect_throw(fn() => Policy::load(null, ['empty-plugin']), "non-string or empty", 'empty-string plugin identity is refused');

echo "\n== theme mirrors every plugin malformation exactly (same validator, same code path) ==\n";

fresh_manifests_dir(['theme-no-range' => ['name' => 'theme-no-range', 'spec_version' => DUO_SPEC_VERSION, 'theme' => 'acme-theme']]);
expect_throw(fn() => Policy::load(null, ['theme-no-range']), 'unbounded', 'theme declared with NO theme_version_range is refused');

fresh_manifests_dir(['theme-bad-range' => ['name' => 'theme-bad-range', 'spec_version' => DUO_SPEC_VERSION, 'theme' => 'acme-theme', 'theme_version_range' => ['min' => '5.0.0', 'max' => '1.0.0']]]);
expect_throw(fn() => Policy::load(null, ['theme-bad-range']), 'malformed range', 'theme_version_range with min > max is refused');

echo "\n== conflicting ownership: same plugin/theme, different ranges, no v1 composition escape hatch ==\n";

fresh_manifests_dir([
    'conf-a' => ['name' => 'conf-a', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['min' => '1.0.0', 'max' => '2.0.0']],
    'conf-b' => ['name' => 'conf-b', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['min' => '2.0.0', 'max' => '3.0.0']],
]);
expect_throw(
    fn() => Policy::load(null, ['conf-a', 'conf-b']),
    'conflicting ownership',
    'two pinned manifests naming the SAME plugin with DIFFERENT ranges is refused (load-order-independent — this is the guard against it)'
);

fresh_manifests_dir([
    'conf-theme-a' => ['name' => 'conf-theme-a', 'spec_version' => DUO_SPEC_VERSION, 'theme' => 'acme-theme', 'theme_version_range' => ['min' => '1.0.0', 'max' => '2.0.0']],
    'conf-theme-b' => ['name' => 'conf-theme-b', 'spec_version' => DUO_SPEC_VERSION, 'theme' => 'acme-theme', 'theme_version_range' => ['min' => '9.0.0', 'max' => '10.0.0']],
]);
expect_throw(
    fn() => Policy::load(null, ['conf-theme-a', 'conf-theme-b']),
    'conflicting ownership',
    'two pinned manifests naming the SAME theme with DIFFERENT ranges is refused'
);

// Identical ranges: redundant, not ambiguous — deliberately ALLOWED (see
// AdapterContractGrammar's contract for why).
fresh_manifests_dir([
    'dup-a' => ['name' => 'dup-a', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['min' => '1.0.0', 'max' => '2.0.0']],
    'dup-b' => ['name' => 'dup-b', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'acme/acme.php', 'version_range' => ['min' => '1.0.0', 'max' => '2.0.0']],
]);
Policy::load(null, ['dup-a', 'dup-b']);
check(true, 'two pinned manifests naming the SAME plugin with the IDENTICAL range load cleanly (redundant, not conflicting)');

echo "\n== resolved_adapters(): digest determinism + \"schema change without version change\" detection ==\n";

// DUO-3247: spec_version is declared here (was deliberately absent before
// this issue, to test resolved_adapters()'s own null-reporting fallback —
// that scenario is now UNREACHABLE, since Policy::load() hard-fails on an
// undeclared spec_version before a Policy object naming this manifest can
// exist at all; the assertion below was repointed to the declared-value
// case instead of deleted, so resolved_adapters()'s spec_version field is
// still covered).
$dirA = fresh_manifests_dir(['woo' => ['name' => 'woo', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'woocommerce/woocommerce.php', 'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'], 'option_autoload' => 'preserve', 'options' => ['a' => ['class' => 'authored']]]]);
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
$dirB = fresh_manifests_dir(['woo' => ['name' => 'woo', 'spec_version' => DUO_SPEC_VERSION, 'plugin' => 'woocommerce/woocommerce.php', 'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'], 'option_autoload' => 'preserve', 'options' => ['a' => ['class' => 'env', 'required' => false]]]]);
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

echo "\n== site.duo.json optional content pins: legacy, match, mismatch, reviewed update ==\n";

$pinDir = fresh_manifests_dir(['pinned' => [
    'name' => 'pinned',
    'spec_version' => DUO_SPEC_VERSION,
    'option_autoload' => 'preserve',
    'options' => ['example' => ['class' => 'authored']],
]]);
$unpinnedRepo = fresh_site_repo(['pinned']);
Policy::load($unpinnedRepo);
check(true, 'legacy string manifest pin loads with byte-for-byte historical behavior');

$originalPolicy = Policy::load(null, ['pinned']);
$originalDigest = RepositoryCompiler::resolved_adapters($originalPolicy)[0]['digest'];
$pinnedRepo = fresh_site_repo([['name' => 'pinned', 'digest' => $originalDigest]]);
Policy::load($pinnedRepo);
check(true, 'object manifest pin with the exact current digest loads normally');

$wrongDigest = str_repeat('0', 64);
$wrongRepo = fresh_site_repo([['name' => 'pinned', 'digest' => $wrongDigest]]);
try {
    Policy::load($wrongRepo);
    check(false, 'mismatched manifest digest is refused (expected RuntimeException, none thrown)');
} catch (\RuntimeException $e) {
    check(
        str_contains($e->getMessage(), "manifest 'pinned' digest mismatch")
            && str_contains($e->getMessage(), "expected $wrongDigest")
            && str_contains($e->getMessage(), "actual $originalDigest"),
        'mismatched manifest digest refuses loudly with manifest name, expected digest, and actual digest'
    );
}

Canon::write_file("$pinDir/pinned.json", Canon::encode([
    'name' => 'pinned',
    'spec_version' => DUO_SPEC_VERSION,
    'option_autoload' => 'preserve',
    'options' => ['example' => ['class' => 'env', 'required' => false]],
]));
expect_throw(
    fn() => Policy::load($pinnedRepo),
    'digest mismatch',
    'a legitimate on-disk manifest change invalidates the old site pin before any policy consumer proceeds'
);

WP_CLI::$lines = [];
(new \Duo\Cli())->manifest_pin([], ['name' => 'pinned']);
$emittedPin = Canon::decode(implode("\n", WP_CLI::$lines));
$changedDigest = RepositoryCompiler::resolved_adapters(Policy::load(null, ['pinned']))[0]['digest'];
check(
    // DUO-3314 added the explicit adapter source to the emitted pin; the
    // write-back below is what proves it stays copy-pasteable.
    $emittedPin === ['digest' => $changedDigest, 'name' => 'pinned', 'source' => 'shipped'],
    'wp duo manifest-pin emits the exact current copy-pasteable {name,digest,source} object without loading a stale site repo'
);
Canon::write_file("$pinnedRepo/site.duo.json", Canon::encode([
    'manifests' => [$emittedPin],
    'policy' => new \stdClass(),
    'spec_version' => DUO_SPEC_VERSION,
]));
Policy::load($pinnedRepo);
check(
    $changedDigest !== $originalDigest,
    'reviewed manifest update workflow succeeds only after the site pin is updated to the newly emitted digest'
);

expect_throw(
    fn() => Policy::load(fresh_site_repo([['name' => 'pinned', 'digest' => 'not-a-sha256']])),
    'invalid digest',
    'malformed declared digest is refused instead of being treated as an absent optional pin'
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

$adapterContractGrammar = new \ReflectionClass('Duo\\AdapterContractGrammar');
$policySource = file_get_contents(__DIR__ . '/../../../../agent/src/Policy/Policy.php');
$manifestValidatorSource = file_get_contents(__DIR__ . '/../../../../agent/src/Policy/ManifestValidator.php');
$finalizerSource = file_get_contents(__DIR__ . '/../../../../agent/src/Policy/PolicyLoadFinalizer.php');
$policyReflection = new \ReflectionClass(Policy::class);
check(
    $adapterContractGrammar->hasMethod('validate_adapter_contract')
        && $adapterContractGrammar->getMethod('validate_adapter_contract')->isPublic()
        && $adapterContractGrammar->hasMethod('validate_no_conflicting_adapter_claims')
        && $adapterContractGrammar->getMethod('validate_no_conflicting_adapter_claims')->isPublic()
        && !$policyReflection->hasMethod('validate_adapter_contract')
        && !$policyReflection->hasMethod('validate_no_conflicting_adapter_claims')
        && substr_count($policySource, 'AdapterContractGrammar::validate_adapter_contract($manifest)') === 0
        && substr_count($manifestValidatorSource, 'AdapterContractGrammar::validate_adapter_contract($manifest)') === 1
        && substr_count($policySource, 'PolicyLoadFinalizer::finalize(') === 2
        // `($` and not `(`: Policy.php's prose names the method with an empty
        // pair of parens several times, and a needle that matched those would
        // be asserting the absence of a docblock rather than of a call.
        && substr_count($policySource, 'AdapterContractGrammar::validate_no_conflicting_adapter_claims($') === 0
        // WP-5.5 gave the guard a second argument — the operator's claim
        // resolutions — so the pinned needle is the call, not its whole
        // argument list. Still exactly one call site, still the finalizer's.
        && substr_count($finalizerSource, 'AdapterContractGrammar::validate_no_conflicting_adapter_claims($policy->manifests, $claimResolutions)') === 1,
    'adapter compatibility grammar lives in ManifestValidator while PolicyLoadFinalizer owns its cross-manifest guard'
);

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
