<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) require_once "$root/sandbox/tests/lib/$file";
require_once "$root/sandbox/tests/support/wp-block-parser-stub.php";
require_once "$root/agent/src/Grammar/Blocks.php";
require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Policy/Policy.php";
require_once "$root/agent/src/Grammar/Tokens.php";
wprism_test_define_agent_versions();

use WPrism\{Blocks, Canon, Tokens};
use WPrismTest\{FakeWpdb, FrozenPolicy};

$capsule = dirname(__DIR__, 2);
$bytes = Canon::read_file($capsule . '/fixtures/native-global-controls/observations.json');
wprism_check_same('3e1104098f4412cf43fc5977fdf6704f5d011ca36e1aa3b2163e8aec580d48c2', hash('sha256', $bytes),
    'native WordPress global-control fragments retain their independently observed saved bytes');
$record = Canon::decode($bytes);
$manifest = Canon::decode(Canon::read_file($capsule . '/package/manifest.json'));
$core = Canon::decode(Canon::read_file("$root/platform/adapter-library/core/manifest.json"));
$policy = FrozenPolicy::policy([$core, $manifest], FrozenPolicy::site([$core, $manifest], WPRISM_SPEC_VERSION));
$source = new Tokens('http://localhost:9508', 'http://localhost:9508/wp-content/uploads');
$target = new Tokens('https://target.example.test', 'https://target.example.test/wp-content/uploads');
$database = FakeWpdb::install()->seedTable('wp_wprism_map', [
    ['uuid' => '11111111-1111-4111-8111-111111111111', 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => 1],
]);
$queries = $database->queries();
$states = [];
foreach ($record['cases'] as $case) {
    $native = array_values(array_filter(parse_blocks($case['saved']), static fn(array $block): bool => $block['blockName'] !== null));
    wprism_check_same(1, count($native), 'each native global-control fixture has one complete selected owner');
    wprism_check_same('qi-blocks/single-image', $native[0]['blockName'], 'global-control fixture remains a real Qi block');
    wprism_check_same($case['lock'], $native[0]['attrs']['lock'], 'native lock readback preserves both strict boolean members');
    wprism_check_same($case['metadata'], $native[0]['attrs']['metadata'] ?? null, 'native metadata observations retain their complete active or absent shape');
    $states[Canon::encode($case['lock'])] = true;
    foreach (['capture', 'apply'] as $verb) {
        try { Blocks::{$verb . '_rewrite'}($case['saved'], $policy, $verb === 'capture' ? $source : $target); $message = ''; }
        catch (RuntimeException $e) { $message = $e->getMessage(); }
        wprism_check_same("wprism: block 'qi-blocks/single-image' contains an undeclared attribute outside its closed roster", $message,
            'unqualified native global attributes refuse instead of receiving a reference-blind catchall');
    }
}
wprism_check_same(4, count($states), 'native saves independently observed all four movement/removal lock states');
wprism_check_same($queries, $database->queries(), 'unqualified native attribute refusal precedes every database lookup or mutation');
wprism_check_summary('Qi native global control boundaries');
