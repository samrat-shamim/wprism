<?php
declare(strict_types=1);

// Normal library/Policy/compiler/reader identity path. Actual plugin loading
// and the fixed engine's two WordPress children belong to the live fixture.
$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
wprism_test_define_agent_versions();
require_once $root . '/tools/src/AdapterPackageValidator.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Apply/ApplyPlanner.php';
require_once $root . '/agent/src/Policy/ScopeContract.php';
require_once $root . '/sandbox/tests/lib/frozen_policy.php';
require_once dirname(__DIR__, 2) . '/fixtures/location-provider/provider-library.php';
require_once dirname(__DIR__, 2) . '/fixtures/location-provider/apply-evidence.php';

$scratch = WPrismTest\FrozenPolicy::library();
$original = WPrism\Policy::load(null, ['wpforms-lite'], adapterLibrary: WPrism\AdapterLibrary::fromSourcePackage($root, 'wpforms-lite'));
$before = WPrism\RepositoryCompiler::manifest_hash($original);
$library = WPFormsLocationProviderLibrary::create($root, $scratch . '/candidate');
$repo = $scratch . '/repo';
mkdir($repo . '/state', 0700, true);
WPrism\Canon::write_file($repo . '/site.wprism.json', WPrism\Canon::encode([
    'manifests' => ['wpforms-lite'], 'spec_version' => 3,
    'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
]));
[$policy, $compiled] = WPFormsLocationProviderLibrary::compile_and_load($repo, $library);
$actions = $policy->actions_for(['post:wpforms']);
wprism_check_same(1, count($actions), 'normal Policy selects exactly the declared candidate action');
wprism_check_same(WPFormsLocationProviderLibrary::PROVIDER, $actions[0]['provider'], 'the action reaches the actual named provider');
wprism_check(!array_key_exists('triggers', $actions[0]), 'native location dependencies use the existing global provider contract');
foreach (['post:page', 'post:post', 'post:wpforms-template', 'post:registered_later',
    'entity:sidebar', 'term:category', 'option:home', 'option:permalink_structure', 'option:widget_wpforms-widget',
    'option:widget_text', 'option:widget_block', 'option:sidebars_widgets'] as $surface) {
    wprism_check_same($actions, $policy->actions_for([$surface]),
        'form bytes need not change for native locations to require repair: ' . $surface);
}
wprism_check_same($actions, $policy->actions_for(['post:page', 'option:widget_text', 'option:home']),
    'multiple changed dependencies select one complete action declaration, not repeated repairs');
wprism_check_same([], $policy->actions_for([]), 'no authored work grants no global repair authority');
// The actual widget writer projects sidebar entities, not raw widget option
// names (CanonicalSurfaces::for_entity). Exercise Apply's work projection as
// well as Policy's selector so a form-only trigger cannot hide behind a fake
// option surface. The live lane checks the public target plan itself.
$planner = new WPrism\ApplyPlanner($policy, [], static fn(): ?int => null, static fn(): ?int => null);
foreach (['page' => ['type' => 'post', 'data' => ['type' => 'page']],
    'widget' => ['type' => 'sidebar', 'data' => []],
    'routing' => ['type' => 'post', 'data' => ['type' => 'post']]] as $case => $entity) {
    $work = $planner->rebuild_work(['update' => [['uuid' => $case]]], [$case => $entity], [], false);
    $surfaces = WPrism\CanonicalSurfaces::for_apply($work['work'], [$case => $entity], $work['rebuild_delete_work'], $policy);
    wprism_check_same([$case === 'widget' ? 'entity:sidebar' : 'post:' . $entity['data']['type']], $surfaces,
        'real Apply work projects the non-form ' . $case . ' dependency');
    wprism_check_same($actions, $policy->actions_for($surfaces), 'real Apply work selects the global provider for ' . $case);
}
wprism_check_same([], WPrism\Providers::packaging_problems($policy, $actions), 'normal loader resolves the private candidate executable');
$declaration = $policy->provider_declarations()[WPFormsLocationProviderLibrary::PROVIDER];
wprism_check_same(['table:options', 'table:postmeta', 'table:posts', 'table:term_relationships', 'table:term_taxonomy',
    'table:termmeta', 'table:terms', 'table:usermeta', 'table:users'],
    $declaration['contracts'][WPFormsLocationProviderLibrary::CAPABILITY]['reads'], 'private contract explicitly names every complete physical read');
wprism_check_same([WPFormsLocationProviderLibrary::CAPABILITY], $declaration['fresh_process_capabilities'], 'both engine children remain mandatory');
wprism_check_same($compiled->artifact_hash(), $policy->execution_artifact_identity()['artifact_hash'], 'persisted artifact reader binds the real artifact');
wprism_check_same(false, $policy->capability_report()['ready'], 'excluded fixture never authorizes production');
$package = $scratch . '/candidate/adapter-packages/wpforms-lite/package';
$disposition = json_decode((string) file_get_contents($package . '/disposition.json'), true, 64, JSON_THROW_ON_ERROR);
wprism_check_same('excluded', $disposition['status'], 'fixture is excluded by disposition, not a name-prefix bypass');
wprism_check_same(hash_file('sha256', dirname(__DIR__, 2) . '/fixtures/location-provider/wpforms-form-locations.php'),
    hash_file('sha256', $package . '/runtime/providers/wpforms-form-locations.php'), 'the real candidate bytes enter its identity');
wprism_check($compiled->manifest_hash() !== $before, 'candidate declaration and executable move only the private identity');
wprism_check_same($before, WPrism\RepositoryCompiler::manifest_hash($original), 'shipped experimental identity stays byte-identical');

// JSON identity is canonical semantic content; executable identity is raw
// bytes. Changing either after compile must refuse through the real reader.
foreach (['manifest.json', 'disposition.json', 'runtime/providers/wpforms-form-locations.php'] as $member) {
    $path = $package . '/' . $member;
    $bytes = (string) file_get_contents($path);
    $changedBytes = $bytes . "\n";
    if ($member !== 'runtime/providers/wpforms-form-locations.php') {
        $json = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if ($member === 'manifest.json') $json['post_meta']['wpforms_form_locations']['note'] .= ' Changed fixture identity.';
        else $json['reason'] .= ' Changed fixture identity.';
        $changedBytes = json_encode($json, JSON_THROW_ON_ERROR);
    }
    file_put_contents($path, $changedBytes);
    $changed = WPrism\Policy::load($repo, adapterLibrary: $library);
    wprism_check_throws(static fn() => WPrism\RepositoryCompiler::read_artifact($repo . '/.wprism/compiled/provider.json', $changed),
        RuntimeException::class, 'changed private ' . $member . ' cannot reuse the compiled authority',
        'compiled manifest/interpreter set does not match active pins');
    file_put_contents($path, $bytes);
}
wprism_check_throws(static fn() => WPFormsLocationProviderLibrary::create($root, $scratch . '/candidate'),
    RuntimeException::class, 'private library refuses destination collisions', 'unoccupied library root');

// Scope evidence contains the full immutable declaration; plans publish only
// its three-field identity. This real compiled page needs no form entity to
// retain the global provider/effects in its dependency closure.
$pageId = '10000000-0000-4000-8000-000000000001';
$scopeRepo = $scratch . '/scope-repo';
mkdir($scopeRepo . '/state/posts/page', 0700, true);
WPrism\Canon::write_file($scopeRepo . '/site.wprism.json', WPrism\Canon::encode([
    'manifests' => ['core', 'wpforms-lite'], 'spec_version' => 3,
    'policy' => ['post_types' => ['page', 'wpforms', 'wpforms-template'], 'taxonomies' => [],
        'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) []],
]));
WPrism\Canon::write_file($scopeRepo . '/state/posts/page/' . $pageId . '--placement.md', WPrism\Canon::post_file([
    'author' => 'user:admin', 'comment_status' => 'open', 'date' => '2026-09-01 00:00:00',
    'date_gmt' => '2026-09-01 00:00:00', 'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
    'modified_gmt' => '2026-09-01 00:00:00', 'parent' => null, 'ping_status' => 'closed',
    'slug' => 'placement', 'status' => 'publish', 'terms' => (object) [], 'title' => 'Placement',
    'type' => 'page', 'uuid' => $pageId,
], 'A placement removed its last form.'));
[$scopePolicy, $scopeCompiled] = WPFormsLocationProviderLibrary::compile_and_load($scopeRepo, $library);
$contract = WPrism\ScopeContract::resolve($scopeCompiled, $scopePolicy, ['post:' . $pageId]);
wprism_check_same(null, $scopeCompiled->code_descriptor(), 'new authored-state evidence does not invent a managed-code baseline');
wprism_check_same(1, count($contract['potential_actions']), 'page-only scope retains one global provider');
$potential = $contract['potential_actions'][0];
wprism_check_same($actions[0], $potential['declaration'], 'scope publishes the complete candidate action and effects');
wprism_check_same(['post:page'], $potential['eligible_surfaces'], 'scope authority remains the real non-form page surface');
wprism_check_same([['declaration_hash' => hash('sha256', WPrism\Canon::encode($potential['declaration'])),
    'index' => $potential['index'], 'manifest' => $potential['manifest']]],
    WPrism\Policy::action_identities([$potential['declaration']]), 'public action identity hashes the full contract declaration');
WPFormsApplyEvidence::source($contract, $scopeCompiled, $scopePolicy);
wprism_check(true, 'actual Apply admission associates the complete retained source and candidate authority');
foreach (['artifact' => static function (&$row): void { $row['source']['artifact_hash'] = str_repeat('b', 64); },
    'declaration' => static function (&$row): void { $row['potential_actions'][0]['declaration']['args'] = ['unexpected' => true]; },
    'selector' => static function (&$row): void { $row['selectors'] = ['all']; }] as $label => $mutate) {
    $changedContract = $contract;
    $mutate($changedContract);
    unset($changedContract['scope_hash']);
    $changedContract['scope_hash'] = hash('sha256', WPrism\Canon::encode($changedContract));
    wprism_check_throws(static fn() => WPFormsApplyEvidence::source($changedContract, $scopeCompiled, $scopePolicy),
        RuntimeException::class, 'actual Apply admission refuses self-hashed changed ' . $label, 'wprism: scope contract');
}
$providerPath = $package . '/runtime/providers/wpforms-form-locations.php';
$providerBytes = (string) file_get_contents($providerPath);
file_put_contents($providerPath, $providerBytes . "\n");
$differentPolicy = WPrism\Policy::load($scopeRepo, adapterLibrary: $library);
$differentCompiled = WPrism\RepositoryCompiler::compile($scopeRepo, $differentPolicy);
wprism_check($differentCompiled->artifact_hash() !== $scopeCompiled->artifact_hash(), 'recompilation binds different candidate executable bytes');
wprism_check_throws(static fn() => WPFormsApplyEvidence::source($contract, $differentCompiled, $differentPolicy),
    RuntimeException::class, 'retained Apply cannot be re-admitted under a different recompiled executable',
    'not associated with this exact compiled artifact/policy');
file_put_contents($providerPath, $providerBytes);
wprism_check_summary('regress_wpforms_location_provider_library');
