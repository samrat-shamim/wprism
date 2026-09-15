<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) {
    require_once "$root/sandbox/tests/lib/$file";
}
wprism_test_define_agent_versions();
require_once "$root/agent/src/Adapter/StoragePrerequisites.php";

use WPrism\StoragePrerequisites;
use WPrism\StoragePrerequisiteGrammar;
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
    try { StoragePrerequisites::assert_ready($policy); } catch (RuntimeException $error) { $refusal = $error; }
    wprism_check_same($raw === '3.8.1', $refusal === null, 'only the exact durable string satisfies the prerequisite');
    if ($refusal !== null) {
        wprism_check($refusal instanceof WPrism\CommandRefusalException
            && $refusal->reasonCode === 'storage_prerequisite_unmet', 'mismatch has a stable typed refusal');
        wprism_check(!str_contains($refusal->getMessage(), '3.8.'), 'refusal does not print stored or expected values');
    }
}
$db->onQuery(static function (string $sql): ?string {
    throw new RuntimeException('unexpected database contact');
});
$empty = $manifest;
unset($empty['storage_prerequisites']);
StoragePrerequisites::assert_ready(FrozenPolicy::policy([$empty], $site));
wprism_check(true, 'policies without prerequisites perform no new target reads');
wprism_check_summary('storage prerequisites');
