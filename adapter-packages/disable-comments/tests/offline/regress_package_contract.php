<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/tools/src/AdapterPackageValidator.php';
require_once $root . '/tools/src/AdapterProductionReadiness.php';
require_once $root . '/tools/src/ArtifactLibrary.php';

use WPrism\AdapterLibrary;
use WPrism\Policy;
use WPrism\Tooling\AdapterPackageValidator;
use WPrism\Tooling\AdapterProductionReadiness;
use WPrism\Tooling\ArtifactLibrary;

$validated = AdapterPackageValidator::validate($root, 'disable-comments');
wprism_check_same('disable-comments', $validated['adapter'], 'the isolated capsule passes its complete package validator');

$package = dirname(__DIR__, 2);
$manifest = json_decode((string) file_get_contents($package . '/package/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$disposition = json_decode((string) file_get_contents($package . '/package/disposition.json'), true, 512, JSON_THROW_ON_ERROR);
$artifacts = json_decode((string) file_get_contents($package . '/evidence/artifacts.lock.json'), true, 512, JSON_THROW_ON_ERROR);
$policy = Policy::load(null, ['core', 'disable-comments'], adapterLibrary: AdapterLibrary::fromSourcePackage($root, 'disable-comments'));

wprism_check_same('certified', $disposition['status'], 'the reviewed claim is certified');
wprism_check_same([], $policy->adapter_readiness_blockers(), 'the policy projects no production-readiness blocker');
wprism_check_same(true, $policy->capability_report()['ready'], 'the certified support reports ready');
wprism_check_same('ready', AdapterProductionReadiness::record($root, 'disable-comments')['readiness'], 'all applicable scenario families are accounted for');

wprism_check_same(
    ['capture', 'compile', 'plan', 'deploy', 'apply', 'recapture', 'render-api'],
    $disposition['capabilities']['operations'],
    'the certified claim covers the exercised product operations'
);
wprism_check_same([], $manifest['deletions'] ?? [], 'no plugin deletion selector is declared');
wprism_check_same(['retire', 'activate', 'verify'], $disposition['capabilities']['lifecycle_phases'], 'lifecycle evidence is part of the certified claim');
foreach (['providers', 'regenerators', 'interpreter', 'actions', 'lifecycle_effects'] as $hook) {
    wprism_check(!array_key_exists($hook, $manifest), "the capsule uses shared declarations without a $hook executable");
}

wprism_check_same(
    ['2.8.0', '2.9.0'],
    array_keys(ArtifactLibrary::loadPackage($root, 'disable-comments')['plugins']['disable-comments']),
    'the capsule owns the certified artifact and its refusal control'
);
wprism_check_same('refusal-fixture', $artifacts['plugins']['disable-comments']['2.8.0']['role'], 'the adjacent release is a refusal fixture');
wprism_check_same(
    'c2285f8e429420cc74311ea4ce2735843d1793e8a1f7ab28de2bba41f27febe1',
    $artifacts['plugins']['disable-comments']['2.8.0']['sha256'],
    'the refusal fixture carries the measured SHA-256'
);
wprism_check_same('certified-boundary', $artifacts['plugins']['disable-comments']['2.9.0']['role'], 'the admitted release is the certified boundary');
wprism_check_same(
    'https://downloads.wordpress.org/plugin/disable-comments.2.8.0.zip',
    $artifacts['plugins']['disable-comments']['2.8.0']['url'],
    'the refusal fixture names an exact versioned URL'
);
wprism_check_same(
    'https://downloads.wordpress.org/plugin/disable-comments.2.9.0.zip',
    $artifacts['plugins']['disable-comments']['2.9.0']['url'],
    'the artifact pin names an exact versioned URL'
);
wprism_check_same(
    '17bc60cc872d77f36a88fa27638bb2e15774bdd15418d966559c32cbbfe3e2c5',
    $artifacts['plugins']['disable-comments']['2.9.0']['sha256'],
    'the artifact pin carries the measured SHA-256'
);
wprism_check(!isset(ArtifactLibrary::loadPlatform($root)['plugins']['disable-comments']), 'platform bootstrap does not duplicate plugin artifact ownership');

wprism_check_same(['min' => '2.9.0', 'max' => '2.9.1'], $manifest['version_range'], 'the manifest names the exact exercised release window');
wprism_check_same(
    wprism_check_ksort_recursive($manifest['version_range']),
    wprism_check_ksort_recursive($disposition['supported_versions']['range']),
    'the reviewed window restates the declared one'
);
wprism_check_same($manifest['plugin'], $disposition['supported_versions']['plugin'], 'both halves name one plugin subject');
wprism_check_same(['options', 'user_meta'], $disposition['capabilities']['field_sections'], 'options and runtime user metadata are the declared field sections');
wprism_check_same([], $disposition['capabilities']['entity_sections'], 'the capsule declares no plugin-owned entity');

foreach (['regress-classification-boundary', 'regress-package-contract', 'conformance-disable-comments', 'exact-artifact-version-matrix'] as $test) {
    wprism_check(in_array($test, $disposition['evidence']['tests'], true), "the reviewed claim cites $test");
}

wprism_check_summary('regress_disable_comments_package_contract');
