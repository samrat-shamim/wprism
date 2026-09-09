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

$validated = AdapterPackageValidator::validate($root, 'speculation-rules');
wprism_check_same('speculation-rules', $validated['adapter'], 'the isolated capsule passes its complete package validator');

$package = dirname(__DIR__, 2);
$manifest = json_decode((string) file_get_contents($package . '/package/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$disposition = json_decode((string) file_get_contents($package . '/package/disposition.json'), true, 512, JSON_THROW_ON_ERROR);
$artifacts = json_decode((string) file_get_contents($package . '/evidence/artifacts.lock.json'), true, 512, JSON_THROW_ON_ERROR);
$policy = Policy::load(null, ['core', 'speculation-rules'], adapterLibrary: AdapterLibrary::fromSourcePackage($root, 'speculation-rules'));

wprism_check_same('certified', $disposition['status'], 'the reviewed claim is certified, and the assertions below are what stands behind it');
wprism_check_same([], $policy->adapter_readiness_blockers(), 'a certified capsule projects no structured readiness blocker');
wprism_check_same(true, $policy->capability_report()['ready'], 'and the capability report reaches production-ready');

$readiness = AdapterProductionReadiness::record($root, 'speculation-rules');
wprism_check_same('ready', $readiness['readiness'], 'every scenario family is accounted for without an open gap');
wprism_check_same([], $readiness['gaps'], 'no family is left as unfinished work');
wprism_check_same([], $readiness['blocked'], 'and none is blocked by an external cause');

// The whole point of this capsule: one option, three sealed sub-keys, nothing
// else. Every assertion below would fail the moment the capsule grew a
// keyspace the plugin does not actually register.
wprism_check_same(
    ['plsr_speculation_rules'],
    array_keys($manifest['options']),
    'the plugin persists exactly one option, so the manifest declares exactly one'
);
foreach (['option_patterns', 'post_meta', 'term_meta', 'user_meta', 'tables', 'post_types', 'taxonomies', 'shortcodes', 'widgets', 'block_attrs', 'actions', 'providers', 'regenerators', 'deletions'] as $section) {
    wprism_check(
        !array_key_exists($section, $manifest),
        "the capsule declares no $section, because Speculative Loading registers none"
    );
}
wprism_check_same([], $policy->declared_post_types(), 'no post type is claimed');
wprism_check_same([], $policy->declared_taxonomies(), 'and no taxonomy is claimed');

$rule = $manifest['options']['plsr_speculation_rules'];
wprism_check_same('env', $rule['class'], 'the parent blob is environment-owned; only its named sub-keys are portable');
wprism_check_same(true, $rule['closed_sub_keys'], 'the sub-key set is closed, so a future fourth key aborts capture instead of riding along');
wprism_check_same(false, $rule['required'], 'the row is not required, because a fresh install has none until the first save');
wprism_check_same('auto', $rule['absent_autoload'], 'and an absent row declares its exact insertion storage rather than letting the engine guess');
wprism_check_same(
    ['authentication', 'eagerness', 'mode'],
    array_keys($rule['sub_keys']),
    "the three sub-keys are exactly plsr_get_setting_default()'s key set"
);
foreach ($rule['sub_keys'] as $subKey => $subRule) {
    wprism_check_same(['class' => 'authored'], $subRule, "$subKey is plainly authored: no ref, no lint_ok, no plain_data, nothing withheld");
}

// A withholding claim is only meaningful if nothing is actually withheld. All
// three values are word enums the plugin clamps, so none of the escape hatches
// that mark a risky value may appear anywhere in this manifest.
$manifestJson = (string) file_get_contents($package . '/package/manifest.json');
foreach (['"lint_ok"', '"allow_secret"', '"allow_pii"', '"ref"', '"plain_data"', '"json_refs"'] as $hatch) {
    wprism_check(
        !str_contains($manifestJson, $hatch . ':'),
        "no $hatch escape hatch appears: the three values are word enums, not ids, secrets or URLs"
    );
}

// The interpreter exists for exactly one reason and must not quietly grow a
// second: completing an absent row from the plugin's own default function.
wprism_check_same('speculation-rules', $manifest['interpreter'], 'the capsule names its interpreter');
$interpreterFile = $package . '/package/runtime/interpreters/speculation-rules.php';
wprism_check(is_file($interpreterFile), 'the interpreter ships inside the capsule, beside the manifest that names it');
$interpreterSource = (string) file_get_contents($interpreterFile);
wprism_check(
    str_contains($interpreterSource, 'plsr_get_setting_default'),
    "the interpreter reads the plugin's own default function, so a plugin-side default change moves with it"
);
foreach (['prerender', 'moderate', 'logged_out'] as $defaultValue) {
    wprism_check(
        !str_contains($interpreterSource, "'" . $defaultValue . "'"),
        "the interpreter hardcodes no '$defaultValue' literal that could silently disagree with the plugin"
    );
}
wprism_check(
    str_contains($interpreterSource, '$strictReadOnly'),
    'and it honours the strict read-only lifecycle snapshot, where the plugin runtime may be absent by design'
);

// Deletion is not expressible for this option, and the reviewed record has to
// say so rather than leaving a reader to infer it from silence.
$deletionSurfaces = array_column($disposition['unsupported'], 'surface');
wprism_check(
    in_array('option:plsr_speculation_rules deletion', $deletionSurfaces, true),
    'the disposition records that an absent row means defaults, not removal, so deletion intent is unsupported'
);
wprism_check(
    in_array('authored removal of plsr_speculation_rules', $disposition['capabilities']['deletion_semantics']['unsupported'], true),
    'and the deletion semantics restate the same boundary'
);
wprism_check(in_array('multisite', $deletionSurfaces, true), "uninstall.php's network teardown stays outside a v1 single-site adapter");

// The exact-artifact boundary: one admitted release and one refusal fixture
// whose option surface is byte-identical, so the refusal can only be the range.
wprism_check_same(
    ['1.6.0', '1.7.0'],
    array_keys(ArtifactLibrary::loadPackage($root, 'speculation-rules')['plugins']['speculation-rules']),
    'the capsule pins both the admitted contract and the adjacent release its matrix refuses'
);
wprism_check_same('certified-boundary', $artifacts['plugins']['speculation-rules']['1.7.0']['role'], 'the admitted release carries the certified-boundary role');
wprism_check_same('refusal-fixture', $artifacts['plugins']['speculation-rules']['1.6.0']['role'], 'and the adjacent release is a refusal fixture, not a second contract');
wprism_check_same(
    'https://downloads.wordpress.org/plugin/speculation-rules.1.7.0.zip',
    $artifacts['plugins']['speculation-rules']['1.7.0']['url'],
    'the pin names an exact version, never the unversioned redirect'
);
wprism_check(!isset(ArtifactLibrary::loadPlatform($root)['plugins']['speculation-rules']), 'platform bootstrap does not duplicate this plugin artifact ownership');

wprism_check_same(['min' => '1.7.0', 'max' => '1.7.1'], $manifest['version_range'], 'the manifest names the exact exercised release window');
wprism_check_same(
    wprism_check_ksort_recursive($manifest['version_range']),
    wprism_check_ksort_recursive($disposition['supported_versions']['range']),
    'the reviewed window restates the declared one'
);
wprism_check_same($manifest['plugin'], $disposition['supported_versions']['plugin'], 'both halves name one plugin subject');
wprism_check_same('speculation-rules/load.php', $manifest['plugin'], "the subject is the plugin's real main file, which is load.php rather than a slug-named file");

wprism_check_same(
    ['capture', 'compile', 'plan', 'deploy', 'apply', 'recapture', 'render-api'],
    $disposition['capabilities']['operations'],
    'the claimed operations are exactly those the round trip exercises'
);
wprism_check_same(['retire', 'activate', 'verify'], $disposition['capabilities']['lifecycle_phases'], 'and the lifecycle phases deploy drives');
wprism_check_same(['options'], $disposition['capabilities']['field_sections'], 'options are the only field section, matching the single declared row');
wprism_check_same([], $disposition['capabilities']['entity_sections'], 'and no entity section, because the plugin owns no entity');

foreach (['conformance-speculation-rules', 'exact-artifact-version-matrix', 'regress-package-contract'] as $test) {
    wprism_check(in_array($test, $disposition['evidence']['tests'], true), "the reviewed claim cites $test");
}

wprism_check_summary('regress_speculation_rules_package_contract');
