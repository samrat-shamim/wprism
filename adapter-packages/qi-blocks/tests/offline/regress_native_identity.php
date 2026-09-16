<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'agent_version.php', 'historical_identity_library.php'] as $file) require_once "$root/sandbox/tests/lib/$file";
require_once "$root/agent/src/Policy/Policy.php";
require_once "$root/agent/src/Policy/ArtifactPolicyIdentity.php";
wprism_test_define_agent_versions();
if (!function_exists('is_multisite')) { function is_multisite(): bool { return false; } }

use WPrism\{AdapterLibrary, ArtifactPolicyIdentity, BlockValueGrammar, Canon, Policy};

$library = AdapterLibrary::fromSourcePackage($root, 'qi-blocks');
$policy = Policy::load(null, ['core', 'qi-blocks'], adapterLibrary: $library);
$digest = static function (Policy $input): string {
    foreach (ArtifactPolicyIdentity::resolved_adapters($input) as $row) if ($row['name'] === 'qi-blocks') return $row['digest'];
    throw new RuntimeException('Qi identity row is absent');
};
wprism_check_same('9a55de4ff86712cc87118261445f21efb737332186e20d0c0d71d569be3c5782', $digest($policy),
    'the live Qi package intentionally pins its complete closed-roster and global-value identity in its own capsule');
$manifest = Canon::decode(Canon::read_file($library->package('qi-blocks')->manifestPath()));
$rows = ArtifactPolicyIdentity::manifest_rows($policy);
$qi = array_values(array_filter($rows, static fn(array $row): bool => $row['name'] === 'qi-blocks'))[0];
wprism_check_same($manifest, $qi['manifest'], 'Qi adapter identity binds authored declaration bytes before group expansion');
wprism_check_same(['name', 'manifest', 'disposition'], array_keys($qi), 'Qi uses only generic engine machinery and ships no executable identity input');

$historical = WPrismHistoricalIdentityLibrary::materialize();
$prior = Policy::load(null, ['core', 'qi-blocks'], adapterLibrary: $historical);
wprism_check_same('f8653dbdf712bee4bdb404eacf0fc29d4b767ce4f9335f119c72981e5e3a15e2', $digest($prior),
    'the pre-closure Qi predecessor remains an independently retained product-path identity');
wprism_check($digest($prior) !== $digest($policy), 'new numeric/boolean declarations and closure require deliberate Qi recompile and re-pin');
$open = clone $policy;
foreach ($open->manifests as &$owner) if ($owner['name'] === 'qi-blocks') unset($owner[BlockValueGrammar::CLOSURE_FIELD]);
unset($owner);
foreach ($open->manifests as $owner) BlockValueGrammar::validate($owner);
wprism_check($digest($open) !== $digest($policy), 'removing legal closure authority moves Qi identity even when all field value rules stay equal');
wprism_check(ArtifactPolicyIdentity::manifest_hash($open) !== ArtifactPolicyIdentity::manifest_hash($policy),
    'the containing compiled manifest address binds closure authority too');
$looseName = clone $policy;
foreach ($looseName->manifests as &$owner) if ($owner['name'] === 'qi-blocks') {
    foreach ($owner['block_values']['groups'] as &$group) if (($group['attributes'] ?? []) === ['metadata']) {
        unset($group['value']['object_fields']['name']['scalar_type']);
    }
    unset($group);
}
unset($owner);
foreach ($looseName->manifests as $owner) BlockValueGrammar::validate($owner);
wprism_check($digest($looseName) !== $digest($policy), 'relaxing the strict global name predicate changes Qi identity despite equal owner and attribute rosters');
$snapshot = $policy->export_snapshot();
$snapshot['site']['manifests'] = ['core', 'qi-blocks'];
wprism_check_same($snapshot, Policy::from_snapshot($snapshot, $library)->export_snapshot(),
    'the current isolated Qi library survives the ordinary complete frozen policy reader');
wprism_check_summary('Qi native identity');
