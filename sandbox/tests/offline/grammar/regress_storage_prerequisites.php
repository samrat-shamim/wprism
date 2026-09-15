<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) {
    require_once "$root/sandbox/tests/lib/$file";
}
wprism_test_define_agent_versions();
require_once "$root/agent/src/Kernel/StoragePrerequisites.php";
require_once "$root/agent/src/Kernel/StoragePrerequisiteSettlement.php";
require_once "$root/agent/src/Promotion/StoragePrerequisiteStatus.php";
require_once "$root/agent/src/Policy/Policy.php";

use WPrism\StoragePrerequisites;
use WPrism\StoragePrerequisiteGrammar;
use WPrism\StoragePrerequisiteSettlement;
use WPrism\StoragePrerequisiteStatus;
use WPrism\Canon;
use WPrism\RepositoryCompiler;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$manifest = ['name' => 'storage-fixture', 'spec_version' => 3,
    'engine_features' => ['spec-window/v1', StoragePrerequisiteGrammar::FEATURE],
    'options' => ['fixture_version' => ['class' => 'runtime']],
    'storage_prerequisites' => [['option' => 'fixture_version', 'equals' => '3.8.1']]];
$site = FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION);
$policy = FrozenPolicy::policy([$manifest], $site);
wprism_check_same([['manifest' => 'storage-fixture', 'option' => 'fixture_version', 'equals' => '3.8.1']],
    StoragePrerequisiteGrammar::project($policy->manifests), 'frozen policy retains exact prerequisite identity');
wprism_check_same([[
    'manifest' => 'storage-fixture', 'option' => 'fixture_version', 'equals' => '3.8.1',
    'settlement' => StoragePrerequisiteSettlement::MANUAL,
]], StoragePrerequisiteSettlement::inventory($policy->manifests),
    'a prerequisite alone remains a manual native migration boundary');

$compileRoot = sys_get_temp_dir() . '/wprism-storage-prerequisite-' . bin2hex(random_bytes(8));
mkdir($compileRoot . '/state', 0700, true);
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path) || is_link($path)) {
        if (file_exists($path) || is_link($path)) unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $entry) $removeTree($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $removeTree($compileRoot));
Canon::write_file($compileRoot . '/site.wprism.json', Canon::encode($site));
$compiled = RepositoryCompiler::compile($compileRoot, $policy);
wprism_check_same(StoragePrerequisiteSettlement::inventory($policy->manifests),
    $compiled->storage_prerequisites_inventory(),
    'immutable compilation carries the manifest-bound host selection witness');
$withoutPrerequisites = $manifest;
unset($withoutPrerequisites['storage_prerequisites']);
$withoutPrerequisites['engine_features'] = ['spec-window/v1'];
$withoutPolicy = FrozenPolicy::policy([$withoutPrerequisites],
    FrozenPolicy::site([$withoutPrerequisites], WPRISM_SPEC_VERSION));
$withoutArtifact = RepositoryCompiler::compile($compileRoot, $withoutPolicy);
wprism_check(!array_key_exists('storage_prerequisites_inventory', $withoutArtifact->export()),
    'compilation preserves unrelated artifact bytes by omitting an empty prerequisite projection');

$covered = $manifest;
$covered['actions'] = [[
    'phase' => 'lifecycle_settle',
    'effects' => [[
        'kind' => 'database', 'mode' => 'restorable',
        'selector' => ['scope' => 'database_checkpoint', 'type' => 'option', 'value' => 'fixture_version'],
    ]],
]];
wprism_check_same(StoragePrerequisiteSettlement::LIFECYCLE,
    StoragePrerequisiteSettlement::inventory([$covered])[0]['settlement'],
    'a same-manifest lifecycle action with an exact restorable option effect authorizes automatic settlement');
$covered['actions'][0]['effects'][0]['selector'] = [
    'scope' => 'database_checkpoint', 'type' => 'table', 'value' => 'options',
];
wprism_check_same(StoragePrerequisiteSettlement::LIFECYCLE,
    StoragePrerequisiteSettlement::inventory([$covered])[0]['settlement'],
    'the already-declared restorable options-table boundary covers a prerequisite without another manifest key');
$combinationTarget = $covered;
$combinationTarget['actions'][] = [
    'phase' => 'lifecycle_settle',
    'effects' => [[
        'kind' => 'external', 'mode' => 'irreversible',
        'selector' => ['scope' => 'external', 'type' => 'provider_resource', 'value' => 'target-unrelated'],
    ]],
];
$combinationOther = [
    'name' => 'unrelated-lifecycle-fixture',
    'actions' => [[
        'phase' => 'lifecycle_settle',
        'effects' => [[
            'kind' => 'database', 'mode' => 'restorable',
            'selector' => ['scope' => 'database_checkpoint', 'type' => 'table', 'value' => 'options'],
        ], [
            'kind' => 'external', 'mode' => 'irreversible',
            'selector' => ['scope' => 'external', 'type' => 'provider_resource', 'value' => 'other-external'],
        ]],
    ]],
];
$selectedStorageActions = StoragePrerequisiteSettlement::actions_for_readiness(
    [$combinationTarget, $combinationOther],
    [['manifest' => 'storage-fixture', 'option' => 'fixture_version', 'ready' => false]]
);
wprism_check(count($selectedStorageActions) === 1
    && ($selectedStorageActions[0]['manifest'] ?? null) === 'storage-fixture'
    && ($selectedStorageActions[0]['index'] ?? null) === 0,
    'storage-only settlement selects the exact covering action without same-manifest or cross-adapter effects');
wprism_check_same([], StoragePrerequisiteSettlement::actions_for_readiness(
    [$combinationTarget, $combinationOther],
    [['manifest' => 'storage-fixture', 'option' => 'fixture_version', 'ready' => true]]
), 'a concurrently satisfied cursor runs no storage settlement action');
$manualSelectionRefused = false;
try {
    StoragePrerequisiteSettlement::actions_for_readiness(
        [$manifest],
        [['manifest' => 'storage-fixture', 'option' => 'fixture_version', 'ready' => false]]
    );
} catch (RuntimeException $failure) {
    $manualSelectionRefused = str_contains($failure->getMessage(), 'no effect-covered');
}
wprism_check($manualSelectionRefused,
    'target-side storage selection independently refuses unmet manual debt');
foreach (['phase', 'manifest', 'mode', 'scope', 'selector'] as $fault) {
    $uncovered = $covered;
    if ($fault === 'phase') $uncovered['actions'][0]['phase'] = 'schema_settle';
    if ($fault === 'manifest') {
        $action = $uncovered['actions'][0];
        unset($uncovered['actions']);
        $otherManifest = ['name' => 'other', 'actions' => [$action]];
        $uncovered = [$uncovered, $otherManifest];
    }
    if ($fault === 'mode') $uncovered['actions'][0]['effects'][0]['mode'] = 'irreversible';
    if ($fault === 'scope') $uncovered['actions'][0]['effects'][0]['selector']['scope'] = 'external';
    if ($fault === 'selector') $uncovered['actions'][0]['effects'][0]['selector']['value'] = 'posts';
    $inventory = StoragePrerequisiteSettlement::inventory(
        $fault === 'manifest' ? $uncovered : [$uncovered]
    );
    wprism_check_same(StoragePrerequisiteSettlement::MANUAL, $inventory[0]['settlement'],
        "$fault cannot confer implicit migration authority");
}

foreach (['feature', 'empty', 'unknown', 'pattern', 'undeclared', 'authored', 'subkeys', 'duplicate', 'value-type', 'oversize', 'many'] as $fault) {
    $bad = $manifest;
    switch ($fault) {
        case 'feature': $bad['engine_features'] = ['spec-window/v1']; break;
        case 'empty': $bad['storage_prerequisites'] = []; break;
        case 'unknown': $bad['storage_prerequisites'][0]['callback'] = 'migrate'; break;
        case 'pattern': $bad['storage_prerequisites'][0]['option'] = '^fixture_'; break;
        case 'undeclared': $bad['options'] = []; break;
        case 'authored': $bad['options']['fixture_version']['class'] = 'authored'; break;
        case 'subkeys': $bad['options']['fixture_version']['sub_keys'] = []; break;
        case 'duplicate': $bad['storage_prerequisites'][] = $bad['storage_prerequisites'][0]; break;
        case 'value-type': $bad['storage_prerequisites'][0]['equals'] = 381; break;
        case 'oversize': $bad['storage_prerequisites'][0]['equals'] = str_repeat('a', 257); break;
        case 'many': $bad['storage_prerequisites'] = array_fill(0, 17, $bad['storage_prerequisites'][0]); break;
    }
    $refused = false;
    try { FrozenPolicy::policy([$bad], $site); } catch (RuntimeException) { $refused = true; }
    wprism_check($refused, "$fault cannot become an inert or writable prerequisite");
}
$other = $manifest;
$other['name'] = 'second-storage-fixture';
$other['storage_prerequisites'][0]['equals'] = '3.8.2';
$refused = false;
try { StoragePrerequisiteGrammar::project([$manifest, $other]); } catch (RuntimeException) { $refused = true; }
wprism_check($refused, 'conflicting pinned expectations refuse before target observation');

$override = $site;
$override['policy']['options']['fixture_version'] = ['class' => 'authored'];
$refused = false;
try { FrozenPolicy::policy([$manifest], $override); } catch (RuntimeException) { $refused = true; }
wprism_check($refused, 'site policy cannot turn a runtime prerequisite into portable authored intent');
$unsorted = $manifest;
$unsorted['options']['a_version'] = ['class' => 'runtime'];
$unsorted['storage_prerequisites'][] = ['option' => 'a_version', 'equals' => '3.8.1'];
$refused = false;
try { FrozenPolicy::policy([$unsorted], $site); } catch (RuntimeException) { $refused = true; }
wprism_check($refused, 'unsorted prerequisite identities refuse during frozen policy loading');

$db = FakeWpdb::install();
WpStore::reset()->seedOptions(['fixture_version' => '3.8.1']);
foreach ([null, '3.8.0', '3.8.1', '3.8.2', serialize(381), serialize(['version' => '3.8.1'])] as $raw) {
    $db->seedTable('options', $raw === null ? [] : [[
        'option_id' => 1, 'option_name' => 'fixture_version', 'option_value' => $raw, 'autoload' => 'no',
    ]]);
    $refusal = null;
    try { StoragePrerequisites::assert_ready($policy->manifests); } catch (RuntimeException $error) { $refusal = $error; }
    wprism_check_same($raw === '3.8.1', $refusal === null, 'only the exact durable string satisfies the prerequisite');
    $readiness = StoragePrerequisites::readiness($policy->manifests);
    wprism_check_same([[
        'manifest' => 'storage-fixture', 'option' => 'fixture_version', 'ready' => $raw === '3.8.1',
    ]], $readiness, 'read-only host readiness carries coordinates and verdict without values');
    $status = StoragePrerequisiteStatus::for_policy($policy);
    wprism_check_same($raw === '3.8.1' ? 'ready' : 'required', $status['state'],
        'host status derives one coherent state from exact physical storage');
    wprism_check(!array_key_exists('equals', $status['prerequisites'][0])
        && !array_key_exists('value', $status['prerequisites'][0]),
        'host status does not publish expected or observed cursor values');
    if ($refusal !== null) {
        wprism_check($refusal instanceof WPrism\CommandRefusalException
            && $refusal->reasonCode === 'storage_prerequisite_unmet', 'mismatch has a stable typed refusal');
        wprism_check(!str_contains($refusal->getMessage(), '3.8.'), 'refusal does not print stored or expected values');
    }
}
$db->enableInformationSchema()
    ->setColumns('wp_options', ['option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)'])
    ->setUniqueKey('wp_options', ['option_name'])
    ->setIndexes('wp_options', [['Key_name' => 'option_name', 'Column_name' => 'option_name', 'Seq_in_index' => 1,
        'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE']])->setTableEngine('wp_options', 'InnoDB');
foreach ([null, '3.8.0', '3.8.1', str_repeat('a', 1025)] as $raw) {
    $db->seedTable('options', $raw === null ? [] : [[
        'option_id' => 1, 'option_name' => 'fixture_version', 'option_value' => $raw, 'autoload' => 'no',
    ]]);
    $before = $db->rows('options');
    $queryStart = count($db->queries());
    $authority = WPrism\Db::start_repeatable_read('storage lock fixture', new WPrism\NativeDatabaseProfile([], ['wp_options']));
    $refusal = null;
    try {
        WPrism\DatabaseQueryIsolation::with_engine_work_units($authority,
            static fn() => StoragePrerequisites::lock($policy->manifests));
    } catch (RuntimeException $error) { $refusal = $error; }
    WPrism\Db::rollback();
    wprism_check_same($raw === '3.8.1', $refusal === null, 'locked current read admits only the expected bounded value');
    wprism_check_same($before, $db->rows('options'), 'locking never repairs the cursor');
    if (is_string($raw) && strlen($raw) > 1024) {
        wprism_check($refusal !== null && str_contains($refusal->getMessage(), 'oversized'), 'narrowed lock bound refuses during compact preflight');
        wprism_check(count(array_filter(array_slice($db->queries(), $queryStart), static fn(string $sql): bool =>
            str_contains($sql, 'SELECT option_name, option_value, autoload'))) === 0, 'oversized cursor payload is never allocated');
    }
}
wprism_check(count(array_filter($db->queries(), static fn(string $sql): bool => str_contains($sql, 'FOR UPDATE')
    && str_contains($sql, 'fixture_version') && str_contains($sql, 'FORCE INDEX'))) > 0,
    'prerequisite uses the existing indexed row/gap lock machinery');
$db->onQuery(static function (string $sql): ?string {
    throw new RuntimeException('unexpected database contact');
});
$empty = $manifest;
unset($empty['storage_prerequisites']);
StoragePrerequisites::assert_ready(FrozenPolicy::policy([$empty], $site)->manifests);
StoragePrerequisites::lock(FrozenPolicy::policy([$empty], $site)->manifests);
wprism_check(true, 'policies without prerequisites perform no new target reads');
wprism_check_summary('storage prerequisites');
