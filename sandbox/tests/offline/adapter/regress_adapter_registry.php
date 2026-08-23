<?php
/**
 * Offline regression for AdapterRegistry.php (DUO-3348 slice 4: adapter
 * provenance / capability-readiness resolution, moved out of Policy.php).
 * Existing suites already exercise capability_report()/adapter_readiness_
 * blockers()/provider_readiness_blockers()/certification_readiness_blockers()/
 * manifest_disposition()/capability_claim() in depth through real, populated
 * dispositions+registry fixtures (regress_actions_providers.php,
 * regress_manifest_dispositions.php, regress_site_adapter_certification.php,
 * regress_provider_contract.php, regress_adapter_sources.php,
 * regress_adapter_catalog.php, regress_adapter_observation.php,
 * regress_plugin_adapter_source.php) — same "deliberately narrow" idiom as
 * regress_compiled_artifact.php (DUO-3348 slice 2): this file proves the
 * EXTRACTION itself is correct rather than re-covering that business logic —
 * that Policy's public methods are thin facades genuinely delegating to a
 * real AdapterRegistry instance (not leftover inline logic — the source-text
 * checks near the bottom are the load-bearing proof of that, along with the
 * byte-identical-body diff done at review time), and that requiring
 * AdapterRegistry.php transitively supplies every class it names statically
 * on its own (the class-loading gap DUO-3440/DUO-3441/DUO-3442 each fixed one
 * file at a time — mutation-tested at review time by deleting one of those
 * requires and confirming this suite's first check below catches it).
 *
 * This bare fixture has no dispositions.json, so every value these 6 methods
 * return on it is null/empty (the "unreviewed" path every one of those richer
 * suites also has to pass through first). The
 * manifest_disposition()/capability_claim() comparisons below therefore
 * reduce to null === null on THIS fixture and don't independently prove a
 * non-trivial value flows through — they check that the facade's answer is
 * internally consistent with calling adapter_sources() directly, not that
 * delegation happened at all (the source-text checks own that). Left as-is
 * rather than built out into a populated-registry fixture: the richer
 * existing suites already prove these methods correct end-to-end, and a
 * fixture that could produce a non-null provenance/claim record here would
 * duplicate that coverage rather than add to it.
 *
 * Exit 0 and "all AdapterRegistry checks passed" on success; any failed
 * check prints "FAIL: ..." and the script exits 1.
 */
declare(strict_types=1);

$fixtureDir = sys_get_temp_dir() . '/duo_regress_adapter_registry_' . bin2hex(random_bytes(4));
mkdir($fixtureDir, 0777, true);
register_shutdown_function(function () use ($fixtureDir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($fixtureDir);
});
putenv("DUO_MANIFESTS_DIR=$fixtureDir");

// Same minimal require set as regress_env_options_policy.php's idiom: Canon
// (manifest JSON decode) + OptionState (Policy's with_option_autoload()) +
// Policy.php itself. Deliberately NOT requiring AdapterRegistry's own
// dependencies here — the whole point of one check below is proving Policy.php's
// require chain (through AdapterRegistry.php) supplies them without help.
require __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require __DIR__ . '/../../../../agent/src/Policy/Policy.php';

use Duo\AdapterRegistry;
use Duo\Policy;

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 0);
}

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

// TargetProbe is the newest of the four and the one a partial require chain
// would silently miss: it is reached only from certification_readiness_
// blockers()/report()/target_reasons(), so a missing require would fatal on a
// live capability query rather than at load. AdapterSources and
// ManifestDispositions are named statically in the same file for the same
// reason and are checked beside it.
foreach ([\Duo\AdapterSources::class, \Duo\ManifestDispositions::class, \Duo\TargetProbe::class] as $dependency) {
    $check(
        class_exists($dependency),
        "requiring only Canon/OptionState/Policy.php (never $dependency's own file, nor the full agent/duo.php "
            . "bootstrap) still defines $dependency — proves Policy.php -> AdapterRegistry.php carries its own "
            . 'transitive require rather than relying on some OTHER file having loaded it first'
    );
}
$registryConstructor = new ReflectionMethod(AdapterRegistry::class, '__construct');
$check(
    $registryConstructor->getNumberOfParameters() === 2
        && $registryConstructor->getParameters()[0]->getName() === 'policy'
        && $registryConstructor->getParameters()[1]->getName() === 'manifestDispositions',
    'AdapterRegistry takes exactly the owning Policy and its reviewed dispositions — the third parameter was the '
        . 'generated capability registry, and a constructor that still accepted one would be a slot for a document '
        . 'nothing produces'
);
$check(
    (new ReflectionMethod(AdapterRegistry::class, 'report'))->isStatic()
        && (new ReflectionMethod(AdapterRegistry::class, 'report'))->isPublic(),
    'report() is public static, so `wp duo capabilities --all` can project the whole shipped library with no site '
        . 'repository to load'
);

file_put_contents("$fixtureDir/m.json", json_encode([
    'name' => 'm',
    'spec_version' => DUO_SPEC_VERSION,
], JSON_PRETTY_PRINT));

$policy = Policy::load(null, ['m']);

// This bare fixture directory has no dispositions.json/capabilities registry,
// so Policy::load() leaves manifestDispositions/capabilityRegistry both null
// (same "unreviewed" path every richer existing suite also starts from) —
// exercising every early-return branch these 6 methods have.

$check(
    $policy->manifest_disposition('m') === $policy->adapter_sources()->provenance('m'),
    'manifest_disposition(): delegates exactly to adapter_sources()->provenance() when no ManifestDispositions is loaded'
);
$check($policy->manifest_disposition('nonexistent-xyz') === null, 'manifest_disposition(): unknown name is null');

$check(
    $policy->capability_claim('m') === $policy->adapter_sources()->claim('m'),
    'capability_claim(): delegates exactly to adapter_sources()->claim() when no ManifestDispositions is loaded'
);
$check($policy->capability_claim('nonexistent-xyz') === null, 'capability_claim(): unknown name is null');

$check(
    $policy->certification_readiness_blockers() === [],
    'certification_readiness_blockers(): no ManifestDispositions -> empty (nothing to certify against)'
);

$check(
    $policy->provider_readiness_blockers([]) === [],
    'provider_readiness_blockers(): empty action list short-circuits to empty without touching Deploy.php/Providers.php'
);
$check(
    $policy->provider_readiness_blockers($policy->actions()) === [],
    "provider_readiness_blockers(): fixture 'm' declares no provider actions -> empty"
);

$check(
    $policy->adapter_readiness_blockers() === [],
    'adapter_readiness_blockers(): certification blockers + provider blockers both empty -> empty'
);

$report = $policy->capability_report();
$check(
    $report['schema_version'] === AdapterRegistry::REPORT_FORMAT
        && AdapterRegistry::REPORT_FORMAT === 'duo-capability-report/v1',
    'capability_report(): schema_version is the report wire version, not the retired duo-capability-registry/v2 — a '
        . 'consumer pinned to the old string would read the absent digest/subject-record/evidence-status as data '
        . 'loss in a document it believed was the same shape'
);
$check($report['registry_sha256'] === null, 'capability_report(): registry_sha256 is null with no reviewed bytes to address');
$check($report['ready'] === false, 'capability_report(): not ready with no external disposition registry');
$check(count($report['blockers']) === 1, 'capability_report(): exactly one blocker names the missing registry');
$check(($report['blockers'][0]['name'] ?? null) === 'registry', 'capability_report(): the blocker names "registry"');
$check(($report['blockers'][0]['status'] ?? null) === 'unreviewed', 'capability_report(): the blocker status is "unreviewed"');
$check($report['manifests'] === [], 'capability_report(): manifests is empty with no registry to report against');

// === Prove genuine delegation to a real AdapterRegistry, not leftover inline
// logic quietly still doing the work under the old method names.
$rm = new ReflectionMethod(Policy::class, 'adapter_registry');
$registry = $rm->invoke($policy);
$check($registry instanceof AdapterRegistry, 'Policy::adapter_registry() constructs a real Duo\AdapterRegistry instance');
$check(
    $registry->capability_claim('m') === $policy->capability_claim('m'),
    "calling AdapterRegistry::capability_claim() directly matches Policy's own facade result exactly"
);
// capability_report()'s 'profiles' key is a fresh `new \stdClass()` on every
// call (both branches), so two independently-called results are never ===
// even when identical -- json_encode() compares structurally instead, same
// idiom the codebase already leans on for canonical-shape comparisons.
$check(
    json_encode($registry->capability_report()) === json_encode($policy->capability_report()),
    "calling AdapterRegistry::capability_report() directly matches Policy's own facade result exactly"
);

// === Topology is a WHOLE-REPORT fact, never a per-surface reason.
// report() appends one `{name: platform, code: site_mode_unsupported}` blocker
// when the probe says multisite (which flips `ready`, since that is
// `$blockers === []`), and target_reasons() must stay silent about topology:
// SurfaceCatalog::registryFacts() reads `report()['manifests'][]['verdict']
// ['reasons']` and hands those codes to ProjectionVocabulary::project(), which
// deliberately retired `multisite_unsupported` from its blocker vocabulary
// (cli/src/Contract/ProjectionVocabulary.php:219-235). A topology reason on a
// surface row would be a code that projection has no entry for.
//
// The end-to-end report comparison (one extra blocker, byte-identical manifest
// rows, ready flipped) lives in sandbox/tests/offline/policy/regress_topology_gate.php:
// this fixture deliberately defines DUO_SPEC_VERSION as 0, and
// ManifestDispositions::platform_boundary() refuses any value that disagrees
// with manifests/capabilities/platform.json, so report()'s static path cannot
// be entered from here without breaking the unreviewed-path fixture above.
$targetReasons = new ReflectionMethod(AdapterRegistry::class, 'target_reasons');
foreach ([true, false] as $multisite) {
    $reasons = $targetReasons->invoke(null, ['supported_versions' => []], [
        'multisite' => $multisite,
        'plugins' => [],
    ]);
    $check(
        $reasons === [],
        'target_reasons() reports no topology reason for a multisite=' . var_export($multisite, true)
            . ' target — the registry answers topology once, at report level, and never contaminates a surface row'
    );
}
$registrySource = file_get_contents(__DIR__ . '/../../../../agent/src/Adapter/AdapterRegistry.php');
$check(
    str_contains($registrySource, "'code' => 'site_mode_unsupported',")
        && str_contains($registrySource, "'name' => 'platform',"),
    "report() carries the whole-report topology blocker under name 'platform' with code site_mode_unsupported"
);
$check(
    !str_contains($registrySource, "self::reason('site_mode_unsupported'")
        && !str_contains($registrySource, "self::reason('multisite_unsupported'"),
    'and neither code is ever minted as a per-surface reason() — the shape ProjectionVocabulary cannot project'
);

$policySource = file_get_contents(__DIR__ . '/../../../../agent/src/Policy/Policy.php');
$check(
    !str_contains($policySource, '$this->capabilityRegistry'),
    'Policy.php holds no capabilityRegistry field at all — the generated registry was a second document to keep in '
        . 'step with the reviewed one, and a leftover null field is where it would grow back'
);
$check(
    str_contains($policySource, "private const SNAPSHOT_FORMAT = 'duo-policy-snapshot/v6';")
        && !str_contains($policySource, "'capabilities' => \$this->capabilityRegistry?->data()"),
    'and export_snapshot() emits no `capabilities` record under the v6 wire generation'
);
$check(
    !str_contains($policySource, 'Providers::packaging_problems($this,'),
    'Policy.php no longer inlines provider_readiness_blockers()\'s body (moved to AdapterRegistry.php)'
);
$check(
    str_contains($policySource, 'return $this->adapter_registry()->capability_report($query);'),
    'Policy::capability_report() is a thin facade delegating to AdapterRegistry'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall AdapterRegistry checks passed\n";
exit(0);
