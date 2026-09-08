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
require_once $root . '/sandbox/tests/lib/frozen_policy.php';
require_once dirname(__DIR__, 2) . '/fixtures/location-provider/provider-library.php';

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
wprism_check_same([], $policy->actions_for(['post:page']), 'the fixture does not imply full placement trigger coverage');
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
wprism_check_summary('regress_wpforms_location_provider_library');
