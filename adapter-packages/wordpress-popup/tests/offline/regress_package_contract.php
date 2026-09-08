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

wprism_check_same('experimental', $disposition['status'], 'source-read reconnaissance does not authorize production');
$blockers = array_values(array_filter(
    $policy->adapter_readiness_blockers(),
    static fn(array $row): bool => ($row['code'] ?? null) === 'authored_state_not_certified'
        && ($row['name'] ?? null) === 'wordpress-popup'
));
wprism_check_same(1, count($blockers), 'the actual capsule projects a structured production-readiness blocker');
wprism_check_same(false, $policy->capability_report()['ready'], 'experimental capture support never reports production-ready');
wprism_check_same('unready', AdapterProductionReadiness::record($root, 'wordpress-popup')['readiness'], 'all unfinished scenario families stay explicit');

wprism_check_same([], array_values(array_intersect(['apply', 'deploy'], $disposition['capabilities']['operations'])), 'no target mutation operation is claimed');
wprism_check_same([], $manifest['deletions'] ?? [], 'module and module-meta deletion authority is withheld');
wprism_check_same([], $disposition['capabilities']['lifecycle_phases'], 'no unexercised lifecycle claim');
foreach (['providers', 'regenerators', 'interpreter', 'actions', 'lifecycle_effects'] as $hook) {
    wprism_check(!array_key_exists($hook, $manifest), "portable declarations use shared machinery without a $hook executable");
}

wprism_check_same(
    ['7.8.14.2'],
    array_keys(ArtifactLibrary::loadPackage($root, 'wordpress-popup')['plugins']['wordpress-popup']),
    'the capsule owns exactly the exercised official artifact'
);
wprism_check_same('exercise-fixture', $artifacts['plugins']['wordpress-popup']['7.8.14.2']['role'], 'the observed artifact is not labeled certified');
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
