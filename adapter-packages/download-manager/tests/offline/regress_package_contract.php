<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/tools/src/AdapterPackageValidator.php';

use WPrism\AdapterLibrary;
use WPrism\Policy;
use WPrism\Tooling\AdapterPackageValidator;
use WPrism\Tooling\AdapterProductionReadiness;
use WPrism\Tooling\ArtifactLibrary;

$validated = AdapterPackageValidator::validate($root, 'download-manager');
wprism_check_same('download-manager', $validated['adapter'], 'the isolated capsule passes its complete package validator');

$package = dirname(__DIR__, 2);
$manifest = json_decode((string) file_get_contents($package . '/package/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$disposition = json_decode((string) file_get_contents($package . '/package/disposition.json'), true, 512, JSON_THROW_ON_ERROR);
$artifacts = json_decode((string) file_get_contents($package . '/evidence/artifacts.lock.json'), true, 512, JSON_THROW_ON_ERROR);
$policy = Policy::load(null, ['core', 'download-manager'], adapterLibrary: AdapterLibrary::fromSourcePackage($root, 'download-manager'));

wprism_check_same('experimental', $disposition['status'], 'source-read reconnaissance does not authorize production');
$blockers = array_values(array_filter(
    $policy->adapter_readiness_blockers(),
    static fn(array $row): bool => ($row['code'] ?? null) === 'authored_state_not_certified'
        && ($row['name'] ?? null) === 'download-manager'
));
wprism_check_same(1, count($blockers), 'the actual capsule projects a structured production-readiness blocker');
wprism_check_same(false, $policy->capability_report()['ready'], 'experimental capture support never reports production-ready');
wprism_check_same('unready', AdapterProductionReadiness::record($root, 'download-manager')['readiness'], 'all unfinished scenario families stay explicit');

wprism_check_same([], array_values(array_intersect(['apply', 'deploy'], $disposition['capabilities']['operations'])), 'no target mutation operation is claimed');
wprism_check_same([], $manifest['deletions'] ?? [], 'package, category and tag deletion authority is withheld');
wprism_check_same([], $disposition['capabilities']['lifecycle_phases'], 'no unexercised lifecycle claim');
foreach (['providers', 'regenerators', 'interpreter', 'actions', 'lifecycle_effects'] as $hook) {
    wprism_check(!array_key_exists($hook, $manifest), "portable declarations use shared machinery without a $hook executable");
}

// A one-plugin capsule owns its artifact pins outright: a second owner would
// defeat the aggregate reader's duplicate-subject refusal.
wprism_check_same(
    ['3.3.68'],
    array_keys(ArtifactLibrary::loadPackage($root, 'download-manager')['plugins']['download-manager']),
    'the capsule owns exactly the exercised official artifact'
);
wprism_check_same('exercise-fixture', $artifacts['plugins']['download-manager']['3.3.68']['role'], 'the observed artifact is not labeled certified');
wprism_check_same(
    'https://downloads.wordpress.org/plugin/download-manager.3.3.68.zip',
    $artifacts['plugins']['download-manager']['3.3.68']['url'],
    'the pin names an exact version, never the unversioned redirect'
);
wprism_check(!isset(ArtifactLibrary::loadPlatform($root)['plugins']['download-manager']), 'platform bootstrap does not duplicate this plugin artifact ownership');

// download-manager.php:277-320 declares the range endpoints, and the
// disposition must restate them Canon-byte-equal or the two claims can drift.
wprism_check_same(['min' => '3.3.68', 'max' => '3.3.69'], $manifest['version_range'], 'the manifest names the exact exercised release window');
wprism_check_same($manifest['version_range'], $disposition['supported_versions']['range'], 'the reviewed window restates the declared one');
wprism_check_same($manifest['plugin'], $disposition['supported_versions']['plugin'], 'both halves name one plugin subject');

// The deploy blocker is measured, not theoretical: it must stay recorded so a
// later status change cannot quietly step over it. Welcome::activationRedirect
// exits during activated_plugin, LifecycleExecutor.php:102 never returns from
// activate_plugin(), and the next apply refuses on the unresolved attempt.
$readiness = AdapterProductionReadiness::record($root, 'download-manager');
wprism_check_same(
    ['clean-target', 'lifecycle'],
    array_keys($readiness['blocked']),
    'the two families an external cause blocks are named, separately from ordinary unfinished work'
);
foreach ($readiness['blocked'] as $family => $reason) {
    wprism_check(
        str_contains($reason, 'Welcome::activationRedirect') && str_contains($reason, 'LifecycleExecutor.php:102'),
        "the $family blocker names both halves of the measured cause rather than a vague external note"
    );
}
wprism_check_same([], array_intersect(['clean-target', 'lifecycle'], array_keys($readiness['gaps'])), 'a blocked family is not also counted as an open gap');

wprism_check_summary('regress_download_manager_package_contract');
