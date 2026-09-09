<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'wp_stubs.php', 'agent_version.php', 'frozen_policy.php'] as $file) require_once "$root/sandbox/tests/lib/$file";
require_once "$root/agent/src/Kernel/BlockValueGrammar.php";
require_once "$root/agent/src/Kernel/BlockMediaDerivativeGrammar.php";
require_once "$root/agent/src/Review/BlockReferenceScanner.php";
require_once "$root/agent/src/Policy/Policy.php";
require_once "$root/agent/src/Policy/ArtifactPolicyIdentity.php";
wprism_test_define_agent_versions();

use WPrism\BlockMediaDerivativeGrammar;
use WPrism\BlockReferenceScanner;
use WPrism\BlockValueGrammar;
use WPrism\Canon;
use WPrismTest\FrozenPolicy;

$plain = ['class' => 'authored', 'plain_data' => true];
$media = ['class' => 'authored', 'json_refs' => [['path' => '$.id', 'kind' => 'post']]];
$derived = ['class' => 'derived'];
$attributes = ['image' => $media, 'preview' => $derived, 'text' => $plain];
$expanded = ['name' => 'block-group-fixture', 'spec_version' => 3,
    'engine_features' => [BlockValueGrammar::FEATURE, 'spec-window/v1'],
    'block_values' => ['fixture/one' => $attributes, 'fixture/two' => $attributes]];
$compact = $expanded; $compact['engine_features'][] = BlockValueGrammar::GROUP_FEATURE;
sort($compact['engine_features'], SORT_STRING);
$compact['block_values'] = ['groups' => [
    ['blocks' => ['fixture/one', 'fixture/two'], 'attributes' => ['text'], 'value' => $plain],
    ['blocks' => ['fixture/one', 'fixture/two'], 'attributes' => ['image'], 'value' => $media],
    ['blocks' => ['fixture/one', 'fixture/two'], 'attributes' => ['preview'], 'value' => $derived],
]];
BlockValueGrammar::validate($compact);
$before = Canon::encode($compact);
wprism_check_same($expanded['block_values'], BlockValueGrammar::attribute_maps($compact), 'groups expand to exactly the original block and attribute ownership');
wprism_check_same(BlockValueGrammar::project([$expanded]), BlockValueGrammar::project([$compact]), 'legacy and grouped declarations yield the same runtime rule lists');
wprism_check_same($before, Canon::encode($compact), 'normalization never changes manifest identity inputs');
$reordered = $compact; $reordered['block_values']['groups'] = array_reverse($reordered['block_values']['groups']);
wprism_check_same(BlockValueGrammar::project([$compact]), BlockValueGrammar::project([$reordered]), 'declaration group order cannot change effective field ownership or codec order');
$literalGroupsAttribute = $compact; $literalGroupsAttribute['block_values']['fixture/three'] = ['groups' => $plain];
BlockValueGrammar::validate($literalGroupsAttribute);
wprism_check_same($plain, BlockValueGrammar::attribute_maps($literalGroupsAttribute)['fixture/three']['groups'], 'a native attribute named groups remains an ordinary exact field');
$policy = FrozenPolicy::policy([$compact], FrozenPolicy::site([$compact], WPRISM_SPEC_VERSION));
wprism_check_same(BlockValueGrammar::project([$expanded]), $policy->block_attr_rules(), 'frozen policy projects the same grouped attribute contract');
wprism_check_same(\WPrism\AdapterContractGrammar::admitted_feature_key_arms($expanded),
    \WPrism\AdapterContractGrammar::admitted_feature_key_arms($compact), 'group syntax preserves the certificate surface and its field arm');
$expandedPolicy = FrozenPolicy::policy([$expanded], FrozenPolicy::site([$expanded], WPRISM_SPEC_VERSION));
$ratification = [
    'capabilities' => ['deletion_semantics' => ['supported' => [], 'unsupported' => ['every declared deletion selector']],
        'entity_sections' => [], 'field_sections' => ['block_values'], 'lifecycle_phases' => [],
        'operations' => ['apply', 'capture', 'compile', 'deploy', 'plan', 'recapture']],
    'default_authored_keyspaces' => [],
    'evidence' => ['bundle_schema' => \WPrism\AdapterCertification::BUNDLE_FORMAT, 'tests' => []],
    'reason' => 'Fixture grammar only.', 'status' => 'certified', 'supported_versions' => ['source' => 'site-operator'],
    'unsupported' => [['operation' => 'delete', 'reason' => 'A manifest grammar verdict reviews no deletion semantics.', 'surface' => 'deletions.*']],
];
foreach (['expanded' => $expanded, 'compact' => $compact] as $form => $manifest) {
    wprism_check(\WPrism\AdapterCertification::site_disposition_is_derived($manifest['name'], $manifest,
        $ratification['reason'], $ratification), "$form declaration derives the same exact certificate surfaces and capabilities");
}
$overclaim = $ratification; $overclaim['capabilities']['field_sections'][] = 'groups';
wprism_check(!\WPrism\AdapterCertification::site_disposition_is_derived($compact['name'], $compact,
    $ratification['reason'], $overclaim), 'certificate derivation cannot treat group syntax as another capability surface');
wprism_check(\WPrism\ArtifactPolicyIdentity::manifest_hash($expandedPolicy) !== \WPrism\ArtifactPolicyIdentity::manifest_hash($policy),
    'lossless regrouping still changes the authored adapter identity and requires re-pinning');
$unrecognized = [['blockName' => 'fixture/one', 'attrs' => ['newEntityId' => 13], 'innerBlocks' => []]];
$findings = BlockReferenceScanner::scan($unrecognized, $policy->block_attr_rules(), 'fixture.md', 'https://source.test', static fn(): ?array => null);
wprism_check_same(['unregistered_block_attr'], array_column($findings, 'class'), 'grouping cannot exempt an undeclared reference-shaped attribute');
foreach (['feature', 'base-feature', 'spec', 'empty', 'non-list', 'open', 'empty-blocks', 'empty-attributes', 'block-glob', 'attribute-glob',
    'duplicate-block', 'duplicate-attribute', 'duplicate-group', 'duplicate-exact', 'bad-value', 'bad-rule', 'unknown-kind',
    'null-exact', 'empty-exact', 'list-exact', 'group-limit', 'member-limit', 'expansion-limit'] as $fault) {
    $bad = $compact;
    if ($fault === 'feature') $bad['engine_features'] = $expanded['engine_features'];
    if ($fault === 'base-feature') $bad['engine_features'] = [BlockValueGrammar::GROUP_FEATURE, 'spec-window/v1'];
    if ($fault === 'spec') $bad['spec_version'] = 2;
    if ($fault === 'empty') $bad['block_values']['groups'] = [];
    if ($fault === 'non-list') $bad['block_values']['groups'] = ['named' => $compact['block_values']['groups'][0]];
    if ($fault === 'open') $bad['block_values']['groups'][0]['override'] = true;
    if ($fault === 'empty-blocks') $bad['block_values']['groups'][0]['blocks'] = [];
    if ($fault === 'empty-attributes') $bad['block_values']['groups'][0]['attributes'] = [];
    if ($fault === 'block-glob') $bad['block_values']['groups'][0]['blocks'] = ['fixture/*'];
    if ($fault === 'attribute-glob') $bad['block_values']['groups'][0]['attributes'] = ['image*'];
    if ($fault === 'duplicate-block') $bad['block_values']['groups'][0]['blocks'] = ['fixture/one', 'fixture/one'];
    if ($fault === 'duplicate-attribute') $bad['block_values']['groups'][0]['attributes'] = ['text', 'text'];
    if ($fault === 'duplicate-group') $bad['block_values']['groups'][] = $bad['block_values']['groups'][0];
    if ($fault === 'duplicate-exact') $bad['block_values']['fixture/one'] = ['text' => $plain];
    if ($fault === 'bad-value') $bad['block_values']['groups'][0]['value'] = 'plain';
    if ($fault === 'bad-rule') $bad['block_values']['groups'][0]['value']['ref'] = 'post';
    if ($fault === 'unknown-kind') $bad['block_values']['groups'][1]['value']['json_refs'][0]['kind'] = 'unowned';
    if ($fault === 'null-exact') $bad['block_values']['fixture/one'] = null;
    if ($fault === 'empty-exact') $bad['block_values']['fixture/one'] = [];
    if ($fault === 'list-exact') $bad['block_values']['fixture/one'] = [$plain];
    if ($fault === 'group-limit') $bad['block_values']['groups'] = array_fill(0, BlockValueGrammar::MAX_GROUPS + 1, $bad['block_values']['groups'][0]);
    if ($fault === 'member-limit') $bad['block_values']['groups'][0]['attributes'] = array_map(static fn(int $i): string => 'attr' . $i, range(0, BlockValueGrammar::MAX_GROUP_MEMBERS));
    if ($fault === 'expansion-limit') {
        $bad['block_values']['groups'] = [['blocks' => array_map(static fn(int $i): string => 'fixture/block' . $i, range(0, 256)),
            'attributes' => array_map(static fn(int $i): string => 'attr' . $i, range(0, 255)), 'value' => $plain]];
    }
    wprism_check_throws(static fn() => FrozenPolicy::policy([$bad], FrozenPolicy::site([$bad], WPRISM_SPEC_VERSION)), RuntimeException::class,
        "frozen manifest validation rejects $fault group declarations");
}
foreach (['same-path', 'whole-block', 'foreign-owner'] as $fault) {
    $bad = $compact;
    $legacy = ['path' => $fault === 'same-path' ? 'image' : 'another', 'kind' => 'post'];
    if ($fault === 'whole-block') $legacy = ['path' => 'text', 'codec' => 'fixture'];
    if ($fault === 'foreign-owner') {
        $other = ['name' => 'other-owner', 'block_attrs' => ['fixture/one' => [$legacy]]];
        $call = static fn() => BlockValueGrammar::project([$bad, $other]);
    } else {
        $bad['block_attrs']['fixture/one'] = [$legacy];
        $call = static fn() => BlockValueGrammar::validate($bad);
    }
    wprism_check_throws($call, RuntimeException::class, "groups retain the existing $fault ownership refusal");
}
$withCrop = $compact; $withCrop['engine_features'][] = BlockMediaDerivativeGrammar::FEATURE;
$withCrop['block_media_derivatives'] = ['fixture/one' => [['attachment' => '$.image.id', 'url' => '$.image.url',
    'width' => '$.width', 'height' => '$.height', 'crop' => true, 'filename' => 'requested-dimensions', 'dimension_cast' => 'integer']]];
BlockMediaDerivativeGrammar::validate($withCrop);
wprism_check_same($withCrop['block_media_derivatives'], BlockMediaDerivativeGrammar::project([$withCrop]), 'derivative ownership reads attachment references from the same normalized group map');
$withoutRef = $withCrop; $withoutRef['block_values']['groups'][1]['value'] = $plain;
wprism_check_throws(static fn() => BlockMediaDerivativeGrammar::validate($withoutRef), RuntimeException::class,
    'a plain grouped image value cannot authorize a derivative attachment', 'typed attachment reference');
$foreign = ['name' => 'foreign', 'block_attrs' => ['fixture/one' => [['path' => 'other', 'kind' => 'post']]]];
wprism_check_throws(static fn() => BlockMediaDerivativeGrammar::project([$withCrop, $foreign]), RuntimeException::class,
    'grouped block ownership cannot be split across derivative and legacy manifests');
$site = FrozenPolicy::site([$compact], WPRISM_SPEC_VERSION); $site['policy']['block_values'] = $compact['block_values'];
wprism_check_throws(static fn() => FrozenPolicy::policy([$compact], $site), RuntimeException::class,
    'site policy cannot introduce or replace grouped block grammar');
$process = proc_open([PHP_BINARY, __DIR__ . '/regress_block_attribute_values.php', '--grouped-attributes'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
if (!is_resource($process)) throw new RuntimeException('cannot start grouped block product-path proof');
fclose($pipes[0]);
// Capture the child stream rather than handing it a file-backed STDOUT:
// proc_open can otherwise overwrite the parent's earlier gate diagnostics.
echo stream_get_contents($pipes[1]);
fclose($pipes[1]);
wprism_check_same(0, proc_close($process), 'grouped declarations pass the complete block capture/compiler/SQL/rollback/widget suite');
if (wprism_check_failed() > 0) exit(1);
