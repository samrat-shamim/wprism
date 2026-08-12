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
 * AdapterRegistry.php transitively supplies CapabilityRegistry.php on its
 * own (the class-loading gap DUO-3440/DUO-3441/DUO-3442 each fixed one file
 * at a time — mutation-tested at review time by deleting that require and
 * confirming this suite's first check below catches it).
 *
 * This bare fixture has no dispositions.json/capabilities registry, so every
 * value these 6 methods return on it is null/empty (the "unreviewed" path
 * every one of those richer suites also has to pass through first). The
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
// Policy.php itself. Deliberately NOT requiring CapabilityRegistry.php here
// — the whole point of one check below is proving Policy.php's own require
// chain (through the new AdapterRegistry.php) supplies it without help.
require __DIR__ . '/../../agent/src/Canon.php';
require __DIR__ . '/../../agent/src/OptionState.php';
require __DIR__ . '/../../agent/src/Policy.php';

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

$check(
    class_exists(\Duo\CapabilityRegistry::class),
    'requiring only Canon/OptionState/Policy.php (never CapabilityRegistry.php or the full agent/duo.php bootstrap) '
        . 'still defines Duo\CapabilityRegistry — proves Policy.php -> AdapterRegistry.php -> CapabilityRegistry.php '
        . 'carries its own transitive require rather than relying on some OTHER file having loaded it first'
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
    'capability_claim(): delegates exactly to adapter_sources()->claim() when no CapabilityRegistry is loaded'
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
$check($report['schema_version'] === \Duo\CapabilityRegistry::FORMAT, 'capability_report(): schema_version is CapabilityRegistry::FORMAT');
$check($report['registry_sha256'] === null, 'capability_report(): registry_sha256 is null with no registry loaded');
$check($report['ready'] === false, 'capability_report(): not ready with no external disposition registry');
$check(count($report['blockers']) === 1, 'capability_report(): exactly one blocker names the missing registry');
$check(($report['blockers'][0]['name'] ?? null) === 'registry', 'capability_report(): the blocker names "registry"');
$check(($report['blockers'][0]['status'] ?? null) === 'unreviewed', 'capability_report(): the blocker status is "unreviewed"');
$check($report['manifests'] === [], 'capability_report(): manifests is empty with no registry to report against');

// === Prove genuine delegation to a real AdapterRegistry, not leftover inline
// logic quietly still doing the work under the old method names.
$rm = new ReflectionMethod(Policy::class, 'adapter_registry');
$rm->setAccessible(true);
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

$policySource = file_get_contents(__DIR__ . '/../../agent/src/Policy.php');
$check(
    !str_contains($policySource, '$this->capabilityRegistry->blockers('),
    'Policy.php no longer inlines certification_readiness_blockers()\'s body (moved to AdapterRegistry.php)'
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
