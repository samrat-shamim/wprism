<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$capsule = dirname(__DIR__, 2);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) require_once "$root/sandbox/tests/lib/$file";
require_once "$root/sandbox/tests/support/wp-block-parser-stub.php";
require_once "$root/sandbox/tests/support/wp-shortcode-stub.php";
require_once "$root/agent/src/Grammar/Blocks.php";
require_once "$root/agent/src/Grammar/Tokens.php";
require_once "$root/agent/src/Repository/Ledger.php";
require_once "$root/agent/src/Policy/Policy.php";
require_once "$capsule/fixtures/conformance/corpus.php";
wprism_test_define_agent_versions();

use WPrism\{Blocks, Canon, Tokens};
use WPrismTest\{FakeWpdb, FrozenPolicy};

$ids = ['image' => 108, 'first' => 109, 'second' => 110, 'page' => 113];
$tokens = []; $rows = [];
foreach ($ids as $name => $id) {
    $uuid = '11111111-1111-4111-8111-' . sprintf('%012d', $id);
    $tokens[$name] = '{{post:' . $uuid . '}}';
    $rows[] = ['uuid' => $uuid, 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => $id];
}
FakeWpdb::install()->seedTable('wp_wprism_map', $rows);
$home = 'https://native.example.test/subdirectory';
$uploads = $home . '/wp-content/uploads';
$image = $uploads . '/2027/03/fixture.png';
$saved = file_get_contents($capsule . '/fixtures/native-blocks.html');
$body = QiConformanceCorpus::body($saved, $ids, $home, $image);
$names = QiConformanceCorpus::block_names($body);
wprism_check_same(50, count($names), 'native conformance preparation retains all 50 standalone block instances');
wprism_check_same(47, count(array_unique($names)), 'native conformance covers every standalone native block type');
wprism_check_same([], array_intersect(QiConformanceCorpus::EXTERNAL_BLOCKS, $names), 'integration participants stay outside the standalone conformance entry');
$manifests = [Canon::decode(file_get_contents($root . '/platform/adapter-library/core/manifest.json')),
    Canon::decode(file_get_contents($capsule . '/package/manifest.json'))];
$site = FrozenPolicy::site($manifests, WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['post', 'page', 'attachment']; $site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy($manifests, $site);
$codec = new Tokens($home, $uploads);
$canonical = Blocks::capture_rewrite($body, $policy, $codec);
$expected = QiConformanceCorpus::body($saved, $tokens, '{{home}}', '{{uploads}}/2027/03/fixture.png', true);
wprism_check_same([], $codec->warnings, 'native seed preparation leaves no stale fixture references');
wprism_check_same($expected, $canonical, 'independent native corpus expectation matches the complete actual block capture');
$styles = QiConformanceCorpus::styles(file_get_contents($capsule . '/fixtures/native-options.json'), $body, $ids, $home, $image);
$frames = 0;
foreach ($styles as $style) foreach ($style->values as $value) {
    $frames += substr_count($value->selector, 'body[class*="-113"]');
    wprism_check(!str_contains(serialize($value), QiConformanceCorpus::SOURCE_HOME), 'native style preparation carries no original environment URL');
}
wprism_check_same(84, $frames, 'standalone corpus retains exactly 84 native owning-page frames after removing both integration blocks');
wprism_check(!str_contains(serialize($styles), 'body[class*="-13"]'), 'prepared native CSS names only the newly authored page');
$process = proc_open(['bash', $capsule . '/tests/conformance/check.sh'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
if (!is_resource($process)) throw new RuntimeException('cannot run target qualification refusal');
fclose($pipes[0]); $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
wprism_check_same(1, proc_close($process), 'unqualified target conformance is an explicit failure');
wprism_check(str_contains($out, 'remain unqualified'), 'target hook explains the missing qualification instead of producing empty success');
if (wprism_check_failed() > 0) exit(1);
echo 'PASS: Qi conformance corpus (' . wprism_check_stats()['passed'] . " assertions)\n";
