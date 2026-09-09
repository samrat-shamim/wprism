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

$validated = AdapterPackageValidator::validate($root, 'wordpress-popup');
wprism_check_same('wordpress-popup', $validated['adapter'], 'the isolated capsule passes its complete package validator');

$package = dirname(__DIR__, 2);
$manifest = json_decode((string) file_get_contents($package . '/package/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$disposition = json_decode((string) file_get_contents($package . '/package/disposition.json'), true, 512, JSON_THROW_ON_ERROR);
$artifacts = json_decode((string) file_get_contents($package . '/evidence/artifacts.lock.json'), true, 512, JSON_THROW_ON_ERROR);
$policy = Policy::load(null, ['core', 'wordpress-popup'], adapterLibrary: AdapterLibrary::fromSourcePackage($root, 'wordpress-popup'));

wprism_check_same('certified', $disposition['status'], 'the reviewed claim is certified, and the assertions below are what stands behind it');
wprism_check_same([], $policy->adapter_readiness_blockers(), 'a certified capsule projects no structured readiness blocker');
wprism_check_same(true, $policy->capability_report()['ready'], 'and the capability report reaches production-ready');

$readiness = AdapterProductionReadiness::record($root, 'wordpress-popup');
wprism_check_same('ready', $readiness['readiness'], 'every scenario family is accounted for without an open gap');
wprism_check_same([], $readiness['gaps'], 'no family is left as unfinished work');
wprism_check_same([], $readiness['blocked'], 'and none is blocked by an external cause');
wprism_check_same(['derived-state'], array_keys($readiness['not_applicable']), 'exactly one family is not applicable: Hustle declares no derived surface');

// Certification is only meaningful if the operations it claims are the ones the
// evidence exercised, and the lifecycle phases deploy actually runs.
wprism_check_same(
    ['capture', 'compile', 'plan', 'deploy', 'apply', 'recapture', 'render-api'],
    $disposition['capabilities']['operations'],
    'the claimed operations are exactly those the round trip exercises'
);
wprism_check_same(['retire', 'activate', 'verify'], $disposition['capabilities']['lifecycle_phases'], 'and the lifecycle phases deploy drives');
wprism_check_same([], $manifest['deletions'] ?? [], 'no deletion selector is declared, which is why deletion intent refuses at capture');
foreach (['providers', 'regenerators', 'interpreter', 'actions', 'lifecycle_effects'] as $hook) {
    wprism_check(!array_key_exists($hook, $manifest), "certification rests on shared machinery, without a $hook executable");
}

// The exact-artifact boundary: one admitted release and one refusal fixture.
wprism_check_same(
    ['7.8.14.1', '7.8.14.2'],
    array_keys(ArtifactLibrary::loadPackage($root, 'wordpress-popup')['plugins']['wordpress-popup']),
    'the capsule pins both the admitted contract and the adjacent release its matrix refuses'
);
wprism_check_same('certified-boundary', $artifacts['plugins']['wordpress-popup']['7.8.14.2']['role'], 'the admitted release carries the certified-boundary role');
wprism_check_same('refusal-fixture', $artifacts['plugins']['wordpress-popup']['7.8.14.1']['role'], 'and the adjacent release is a refusal fixture, not a second contract');
wprism_check(in_array('exact-artifact-version-matrix', $disposition['evidence']['tests'], true), 'the reviewed claim cites the exact-artifact matrix that produces its boundary');

wprism_check_same(
    'https://downloads.wordpress.org/plugin/wordpress-popup.7.8.14.2.zip',
    $artifacts['plugins']['wordpress-popup']['7.8.14.2']['url'],
    'the pin names an exact version, never the unversioned redirect'
);
wprism_check(!isset(ArtifactLibrary::loadPlatform($root)['plugins']['wordpress-popup']), 'platform bootstrap does not duplicate this plugin artifact ownership');

wprism_check_same(['min' => '7.8.14.2', 'max' => '7.8.14.3'], $manifest['version_range'], 'the manifest names the exact exercised release window');
wprism_check_same($manifest['version_range'], $disposition['supported_versions']['range'], 'the reviewed window restates the declared one');
wprism_check_same($manifest['plugin'], $disposition['supported_versions']['plugin'], 'both halves name one plugin subject');

// Hustle keeps no post type and no taxonomy: its authored content is entirely
// custom-table state. A capsule that quietly grew either section would be
// claiming a keyspace this plugin does not register.
wprism_check_same([], $policy->declared_post_types(), 'the capsule declares no post type, because the plugin registers none');
wprism_check_same([], $policy->declared_taxonomies(), 'and no taxonomy, for the same reason');

wprism_check_summary('regress_wordpress_popup_package_contract');
