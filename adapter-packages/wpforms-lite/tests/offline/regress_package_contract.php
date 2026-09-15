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

$validated = AdapterPackageValidator::validate($root, 'wpforms-lite');
wprism_check_same('wpforms-lite', $validated['adapter'], 'the isolated capsule passes its complete package validator');
$policy = Policy::load(null, ['core', 'wpforms-lite'], adapterLibrary: AdapterLibrary::fromSourcePackage($root, 'wpforms-lite'));
$package = dirname(__DIR__, 2);
$manifest = json_decode((string) file_get_contents($package . '/package/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$disposition = json_decode((string) file_get_contents($package . '/package/disposition.json'), true, 512, JSON_THROW_ON_ERROR);
$fixture = json_decode((string) file_get_contents($package . '/fixtures/native-authoring.json'), true, 512, JSON_THROW_ON_ERROR);
$artifacts = json_decode((string) file_get_contents($package . '/evidence/artifacts.lock.json'), true, 512, JSON_THROW_ON_ERROR);
wprism_check_same('certified', $disposition['status'], 'the bounded native claim authorizes production');
$blockers = array_values(array_filter(
    $policy->adapter_readiness_blockers(),
    static fn(array $row): bool => ($row['code'] ?? null) === 'authored_state_not_certified'
        && ($row['name'] ?? null) === 'wpforms-lite'
));
wprism_check_same([], $blockers, 'the bounded capsule projects no production-readiness blocker');
wprism_check_same(true, $policy->capability_report()['ready'], 'the bounded capsule reports production-ready support');
wprism_check_same('ready', AdapterProductionReadiness::record($root, 'wpforms-lite')['readiness'], 'the readiness ledger authorizes the bounded claim');
wprism_check_same(
    ['capture', 'compile', 'plan', 'deploy', 'apply', 'recapture', 'render-api'],
    $disposition['capabilities']['operations'],
    'the certified claim covers its exercised product operations'
);
wprism_check_same([], $manifest['deletions'] ?? [], 'form, template and tag deletion authority is withheld');
wprism_check_same(['retire', 'activate', 'verify'], $disposition['capabilities']['lifecycle_phases'], 'lifecycle evidence is part of the certified claim');
foreach (['regenerators', 'interpreter'] as $hook) {
    wprism_check(!array_key_exists($hook, $manifest), "portable declarations use shared machinery without a $hook executable");
}
wprism_check_same(['wpforms-form-locations'], array_column($manifest['providers'], 'id'), 'one experimental SDK provider owns native derived-state semantics');
wprism_check_same($policy->actions_for(['post:wpforms']), $policy->actions_for(['entity:sidebar']), 'native placements use the generic global action contract');
wprism_check_same([], $policy->actions_for([]), 'empty authored work never runs the global provider');
wprism_check_same($fixture['artifact_sha256'], $artifacts['plugins']['wpforms-lite'][$fixture['version']]['sha256'], 'native fixtures name the exact locked official artifact');
wprism_check_same('certified-boundary', $artifacts['plugins']['wpforms-lite'][$fixture['version']]['role'], 'the observed artifact is the certified boundary');
wprism_check_same(['2.0.0.4', '2.0.1.1'], array_keys(ArtifactLibrary::loadPackage($root, 'wpforms-lite')['plugins']['wpforms-lite']), 'the capsule owns both the historical and new exercise artifacts');
wprism_check_same('refusal-fixture', $artifacts['plugins']['wpforms-lite']['2.0.0.4']['role'], 'the adjacent artifact is an explicit refusal fixture');
wprism_check_same('3deaa115c6ed18fe3ef21f327239cf9573f78f97b1f4cc8204abfaf910689608', $artifacts['plugins']['wpforms-lite']['2.0.0.4']['sha256'], 'the refusal fixture retains its exact artifact bytes');
wprism_check(!isset(ArtifactLibrary::loadPlatform($root)['plugins']['wpforms-lite']), 'platform bootstrap no longer duplicates plugin artifact ownership');
foreach (['wpforms', 'wpforms-template'] as $type) {
    wprism_check_same('json', $policy->body_mode($type), "$type reaches the engine JSON body mode");
    wprism_check_same($manifest['body_refs'][$type], array_diff_key($policy->body_ref_rule($type), ['adapter' => true]), "$type retains exactly its own reference and privacy declarations");
}
$settings = $policy->option_rule('wpforms_settings');
wprism_check_same('env', $settings['class'], 'the settings blob is target-local by default');
wprism_check_same(false, $settings['required'], 'plugin-owned mixed storage is not an impossible whole-option provisioning requirement');
wprism_check_same('authored', $settings['sub_keys']['disable-css']['class'], 'native presentation intent is portable');
wprism_check_same('authored', $settings['sub_keys']['validation-email']['class'], 'native validation copy is portable');
wprism_check_same('env', $settings['sub_keys']['lite-connect-enabled']['class'], 'cloud enrollment stays target-local');
wprism_check_same('env', $settings['sub_keys']['modern-markup-is-set']['class'], 'installation markers stay target-local');
foreach (['gdpr-disable-uuid', 'gdpr-disable-details'] as $key) {
    wprism_check_same('env', $settings['sub_keys'][$key]['class'], 'disabled Pro-only GDPR controls are not Lite-authored intent');
    wprism_check(!isset($settings['sub_keys'][$key]['lint_ok']), 'target-local Pro residue has no authored lint clearance');
}
wprism_check(!isset($settings['sub_keys']['license-key']), 'no credential carve-out exists');
wprism_check_same('env', $policy->option_rule('wpforms_crypto_secret_key')['class'], 'encryption material never becomes authored configuration');
wprism_check_same('derived', $policy->post_meta_rule('wpforms_form_locations')['class'], 'location index values never enter authored metadata');
foreach (array_keys($manifest['tables']) as $table) {
    wprism_check_same('runtime', $policy->table_rule($table)['class'], "$table remains outside the portable row set");
}
wprism_check(!isset($manifest['tables']['actionscheduler_actions']), 'shared scheduler storage is not claimed by one consumer');
wprism_check_summary('regress_wpforms_lite_package_contract');
