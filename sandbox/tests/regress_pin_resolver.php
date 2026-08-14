<?php
/**
 * Offline regression for PinResolver.php (DUO-3348 slice 5: manifest-pin
 * normalization/validation, moved out of Policy.php). All three methods
 * were pure — every dependency an explicit parameter, never `$this` — so
 * this suite drives them directly rather than through a full Policy::load()
 * cycle; Policy::load()'s own suites (regress_adapter_sources.php,
 * regress_adapter_contract.php, regress_site_adapter_certification.php,
 * etc.) already exercise the same logic in depth through the real pin flow,
 * including the digest-mismatch refusal validate_manifest_pins() throws
 * once RepositoryCompiler::resolved_adapters() is reachable — this file
 * deliberately does not require RepositoryCompiler.php, so it only proves
 * the early-return "no digest pins" branch directly and leaves the
 * digest-comparison branch to those suites (see PinResolver.php's own
 * docblock for why this file's own class deliberately does not
 * require_once RepositoryCompiler.php either).
 *
 * Exit 0 and "all PinResolver checks passed" on success; any failed check
 * prints "FAIL: ..." and the script exits 1.
 */
declare(strict_types=1);

require __DIR__ . '/../../agent/src/PinResolver.php';

use Duo\AdapterSources;
use Duo\PinResolver;
use Duo\Policy;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};
$check_throws = static function (callable $fn, string $needle, string $message) use (&$failures): void {
    try {
        $fn();
        echo "FAIL: $message (did not throw)\n";
        $failures[] = $message;
    } catch (\Throwable $e) {
        $ok = str_contains($e->getMessage(), $needle);
        echo ($ok ? 'ok: ' : 'FAIL: ') . "$message (threw: {$e->getMessage()})\n";
        if (!$ok) {
            $failures[] = $message;
        }
    }
};

// === normalize_manifest_pins() ==============================================

$check_throws(
    fn() => PinResolver::normalize_manifest_pins('not-an-array'),
    'must be a JSON array',
    'normalize_manifest_pins(): a non-array input is refused'
);
$check_throws(
    fn() => PinResolver::normalize_manifest_pins(['a' => 'core']),
    'must be a JSON array',
    'normalize_manifest_pins(): a non-list (keyed) array is refused'
);

$plain = PinResolver::normalize_manifest_pins(['core', 'woocommerce']);
$check(
    $plain === [
        ['name' => 'core', 'digest' => null, 'source' => null],
        ['name' => 'woocommerce', 'digest' => null, 'source' => null],
    ],
    'normalize_manifest_pins(): plain string names normalize to {name,digest:null,source:null}'
);

$check_throws(
    fn() => PinResolver::normalize_manifest_pins(['']),
    'must be a non-empty name string or an object',
    'normalize_manifest_pins(): an empty string name is refused'
);
$check_throws(
    fn() => PinResolver::normalize_manifest_pins([123]),
    'must be a non-empty name string or an object',
    'normalize_manifest_pins(): a non-string, non-object entry is refused'
);
$check_throws(
    fn() => PinResolver::normalize_manifest_pins([['name' => 'core', 'unknown_key' => 1]]),
    "declares unknown pin key(s) unknown_key",
    'normalize_manifest_pins(): an unknown pin key is refused, named exactly'
);
$check_throws(
    fn() => PinResolver::normalize_manifest_pins([['name' => 'core', 'digest' => 'too-short']]),
    'has an invalid digest',
    'normalize_manifest_pins(): a malformed digest is refused'
);
$check_throws(
    fn() => PinResolver::normalize_manifest_pins([['name' => 'core', 'digest' => strtoupper(str_repeat('a', 64))]]),
    'has an invalid digest',
    'normalize_manifest_pins(): an uppercase-hex digest is refused (lowercase only)'
);
$check_throws(
    fn() => PinResolver::normalize_manifest_pins([['name' => 'core', 'source' => 'vendor']]),
    'the installed adapter sources are "shipped", "site", and "plugin"',
    'normalize_manifest_pins(): an unknown source word is refused and all three real sources are named'
);

$validDigest = str_repeat('a', 64);
foreach ([AdapterSources::SHIPPED, AdapterSources::SITE, AdapterSources::PLUGIN] as $source) {
    $result = PinResolver::normalize_manifest_pins([['name' => 'core', 'digest' => $validDigest, 'source' => $source]]);
    $check(
        $result === [['name' => 'core', 'digest' => $validDigest, 'source' => $source]],
        "normalize_manifest_pins(): a well-formed {name,digest,source:\"$source\"} pin is preserved exactly"
    );
}

// === validate_manifest_sources() ============================================

$fixtureDir = sys_get_temp_dir() . '/duo_regress_pin_resolver_' . bin2hex(random_bytes(4));
mkdir($fixtureDir, 0777, true);
register_shutdown_function(function () use ($fixtureDir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($fixtureDir);
});
file_put_contents("$fixtureDir/m.json", json_encode(['name' => 'm', 'spec_version' => 0], JSON_PRETTY_PRINT));
$sources = AdapterSources::discover($fixtureDir, null);

try {
    PinResolver::validate_manifest_sources([['name' => 'm', 'digest' => null, 'source' => null]], $sources);
    $check(true, 'validate_manifest_sources(): a null-source pin never checks against the actual source');
} catch (\Throwable $t) {
    $check(false, 'validate_manifest_sources(): a null-source pin never checks against the actual source (threw: ' . $t->getMessage() . ')');
}

try {
    PinResolver::validate_manifest_sources([['name' => 'not-installed-anywhere', 'digest' => null, 'source' => AdapterSources::SITE]], $sources);
    $check(true, 'validate_manifest_sources(): a pin naming an uninstalled manifest has nothing to disagree with');
} catch (\Throwable $t) {
    $check(false, 'validate_manifest_sources(): a pin naming an uninstalled manifest has nothing to disagree with (threw: ' . $t->getMessage() . ')');
}

try {
    // 'm' actually resolves from the SHIPPED source (it sits directly in the
    // fixture manifests dir), so pinning it to shipped must pass.
    PinResolver::validate_manifest_sources([['name' => 'm', 'digest' => null, 'source' => AdapterSources::SHIPPED]], $sources);
    $check(true, "validate_manifest_sources(): a pin matching the manifest's actual resolved source passes");
} catch (\Throwable $t) {
    $check(false, "validate_manifest_sources(): a pin matching the manifest's actual resolved source passes (threw: {$t->getMessage()})");
}

$check_throws(
    fn() => PinResolver::validate_manifest_sources([['name' => 'm', 'digest' => null, 'source' => AdapterSources::PLUGIN]], $sources),
    "is pinned to the plugin adapter source but resolves",
    'validate_manifest_sources(): a pin disagreeing with the actual resolved source is refused, naming both'
);

// === validate_manifest_pins(): early-return branch only (see file docblock) ===

// validate_manifest_pins()'s Policy type hint means a real, loaded Policy
// class is unavoidable to call it at all (even via reflection -- resolving
// \Duo\Policy::class as a symbol needs the class defined) -- required here
// for exactly that, and for nothing else this suite still deliberately
// avoids requiring (RepositoryCompiler.php is never required in this file).
require_once __DIR__ . '/../../agent/src/Canon.php';
require_once __DIR__ . '/../../agent/src/OptionState.php';
require_once __DIR__ . '/../../agent/src/Policy.php';
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 0);
}
putenv("DUO_MANIFESTS_DIR=$fixtureDir");
// Policy::load() itself calls PinResolver::validate_manifest_pins()
// internally as part of loading 'm' (no digest pins here, so it already
// takes the early-return branch without incident) -- if that branch broke,
// load() itself would fatal right here, uncaught, before reaching the
// second, direct call below. This additionally calls validate_manifest_
// pins() a SECOND time, in isolation: not to catch a regression load()
// would otherwise hide (it wouldn't -- an uncaught fatal is already loud),
// but to pin the failure to this one function specifically, with a named
// assertion message, rather than an unattributed crash somewhere inside
// load()'s much larger body.
$policy = Policy::load(null, ['m']);
try {
    // No pin carries a digest, so this must return WITHOUT ever reaching
    // RepositoryCompiler::resolved_adapters() -- proven by this file never
    // requiring RepositoryCompiler.php at all: if the early return did not
    // fire, this would fatal with "Class RepositoryCompiler not found"
    // rather than merely fail an assertion.
    PinResolver::validate_manifest_pins([['name' => 'm', 'digest' => null, 'source' => null]], $policy);
    $check(true, 'validate_manifest_pins(): no digest-bearing pins short-circuits before touching RepositoryCompiler at all');
} catch (\Throwable $t) {
    $check(false, 'validate_manifest_pins(): no digest-bearing pins short-circuits before touching RepositoryCompiler at all (threw: ' . get_class($t) . ': ' . $t->getMessage() . ')');
}

// === Prove the extraction itself: Policy.php no longer inlines these bodies,
// and its call sites now reach PinResolver.
$policySource = file_get_contents(__DIR__ . '/../../agent/src/Policy.php');
$finalizerSource = file_get_contents(__DIR__ . '/../../agent/src/PolicyLoadFinalizer.php');
$check(
    !str_contains($policySource, "private static function normalize_manifest_pins("),
    'Policy.php no longer defines normalize_manifest_pins() itself (moved to PinResolver.php)'
);
$check(
    !str_contains($policySource, "private static function validate_manifest_sources("),
    'Policy.php no longer defines validate_manifest_sources() itself (moved to PinResolver.php)'
);
$check(
    !str_contains($policySource, "private static function validate_manifest_pins("),
    'Policy.php no longer defines validate_manifest_pins() itself (moved to PinResolver.php)'
);
$check(
    substr_count($policySource, 'PinResolver::normalize_manifest_pins(') === 2
        && substr_count($policySource, 'PinResolver::validate_manifest_sources(') === 2
        && substr_count($policySource, 'PolicyLoadFinalizer::finalize(') === 2
        && substr_count($policySource, 'PinResolver::validate_manifest_pins(') === 0
        && substr_count($finalizerSource, 'PinResolver::validate_manifest_pins(') === 1,
    'Policy keeps pin normalization/source checks at each loader while PolicyLoadFinalizer owns shared pin validation'
);

// === Corroborates, rather than independently proves, that the process
// stayed clean throughout: the actual proof that the early-return branch
// never touches RepositoryCompiler.php is the direct call above (:182)
// succeeding rather than fataling on a missing class -- this check confirms
// nothing ELSE along the way (Policy.php's own require chain, the fixture
// construction, etc.) accidentally loaded RepositoryCompiler.php either,
// which would have made that earlier proof coincidental rather than causal.
$check(
    !class_exists(\Duo\RepositoryCompiler::class, false),
    'RepositoryCompiler.php was never loaded anywhere in this process -- corroborates the early-return proof above'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall PinResolver checks passed\n";
exit(0);
