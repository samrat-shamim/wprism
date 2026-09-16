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
require_once "$root/agent/src/Capture/CaptureSafetyGates.php";
require_once "$capsule/fixtures/conformance/corpus.php";
wprism_test_define_agent_versions();

use WPrism\{Blocks, Canon, Tokens};
use WPrismTest\{FakeWpdb, FrozenPolicy};

$ids = ['image' => 108, 'first' => 109, 'second' => 110, 'page' => 113];
$png = QiConformanceCorpus::image_png();
wprism_check_same([[0, 0, 0, 0], [255, 0, 0, 0], [0, 255, 173, 0], [255, 255, 173, 0], [127, 127, 0, 0]],
    QiConformanceCorpus::image_samples($png), 'fresh native image carries an independently pinned asymmetric crop signal');
wprism_check_same($png, QiConformanceCorpus::image_png(), 'asymmetric raster generation is deterministic');
wprism_check_throws(static fn() => QiConformanceCorpus::image_samples(file_get_contents($capsule . '/fixtures/native-media/original.png')),
    RuntimeException::class, 'historical uniform original cannot qualify crop-origin correctness');
$nativeSource = file_get_contents($capsule . '/fixtures/conformance/native.php');
wprism_check(str_contains($nativeSource, '$png = QiConformanceCorpus::image_png();')
    && str_contains($nativeSource, 'QiConformanceCorpus::image_samples($png);'), 'native upload writer exercises and observes the asymmetric premise before publication');
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
$gates = new WPrism\CaptureSafetyGates('/native-qi-source');
$entity = static fn(string $body): array => ['type' => 'post', 'path' => 'posts/page/native-qi-source.md',
    'content' => Canon::post_file(['type' => 'page', 'title' => 'Qi native corpus'], $body)];
$gates->assertCanonicalContent([$entity($canonical)], $policy);
wprism_check(true, 'complete native Qi corpus passes the final publication clearance without a privacy exception');
foreach (['+1 (415) 555-2671' => 'personal_data_refused', 'private@example.test' => 'personal_data_refused',
    'api_key=MixedCredential-2026-Value' => 'secret_state_refused'] as $private => $reason) {
    $refusal = null;
    try { $gates->assertCanonicalContent([$entity($canonical . '<p>' . $private . '</p>')], $policy); }
    catch (WPrism\CommandRefusalException $failure) { $refusal = $failure; }
    wprism_check_same($reason, $refusal?->reasonCode, 'generated block identities do not clear unrelated private content');
    wprism_check($refusal !== null && !str_contains(json_encode($refusal->payload()), $private), 'publication refusal keeps private content out of its diagnostics');
}
$styles = QiConformanceCorpus::styles(file_get_contents($capsule . '/fixtures/native-options.json'), $body, $ids, $home, $image);
$frames = 0;
foreach ($styles as $style) foreach ($style->values as $value) {
    $frames += substr_count($value->selector, 'body[class*="-113"]');
    wprism_check(!str_contains(serialize($value), QiConformanceCorpus::SOURCE_HOME), 'native style preparation carries no original environment URL');
}
wprism_check_same(84, $frames, 'standalone corpus retains exactly 84 native owning-page frames after removing both integration blocks');
wprism_check(!str_contains(serialize($styles), 'body[class*="-13"]'), 'prepared native CSS names only the newly authored page');
$entry = Canon::decode(file_get_contents($capsule . '/tests/conformance/entry.json'));
$disposition = Canon::decode(file_get_contents($capsule . '/package/disposition.json'));
wprism_check_same('agent-roundtrip', $entry['entry']['mode'] ?? null, 'shared conformance uses the generic experimental deploy and Apply profile');
wprism_check_same(['terms', 'posts'], $entry['entry']['adopt_by_slug'] ?? null, 'shared conformance declares its exact adoption fixture policy');
wprism_check_same(['capture', 'compile', 'plan', 'deploy', 'apply', 'recapture'],
    $disposition['capabilities']['operations'] ?? null, 'reviewed operations match the paths exercised by shared conformance');
foreach (['postdeploy.sh', 'postapply.sh', 'check.sh'] as $hook) {
    wprism_check(is_file($capsule . '/tests/conformance/' . $hook), "target roundtrip owns its $hook hook");
}
$checkSource = file_get_contents($capsule . '/tests/conformance/check.sh');
wprism_check(str_contains($checkSource, '. tests/lib/conformance_private_command.sh')
    && str_contains($checkSource, '--admit-roundtrip-fixed-point') && str_contains($checkSource, 'QI_REPEAT'),
    'target hook loads the shared private-command ABI and requires the native oracle plus a zero-write repeated Apply');
if (wprism_check_failed() > 0) exit(1);
echo 'PASS: Qi conformance corpus (' . wprism_check_stats()['passed'] . " assertions)\n";
