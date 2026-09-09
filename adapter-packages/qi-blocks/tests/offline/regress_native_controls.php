<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) {
    require_once "$root/sandbox/tests/lib/$file";
}
require_once "$root/sandbox/tests/support/wp-block-parser-stub.php";
require_once "$root/agent/src/Grammar/Blocks.php";
require_once "$root/agent/src/Repository/Ledger.php";
require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Policy/Policy.php";
require_once "$root/agent/src/Grammar/Tokens.php";
wprism_test_define_agent_versions();

use WPrism\Blocks;
use WPrism\Canon;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;

$manifest = Canon::decode(Canon::read_file(dirname(__DIR__, 2) . '/package/manifest.json'));
$core = Canon::decode(Canon::read_file("$root/platform/adapter-library/core/manifest.json"));
$site = FrozenPolicy::site([$core, $manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['page', 'attachment'];
$site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy([$core, $manifest], $site);
$uuid = '11111111-1111-4111-8111-111111111111';
$database = static function (int $id) use ($uuid): void {
    FakeWpdb::install()->seedTable('wp_wprism_map', [
        ['uuid' => $uuid, 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => $id],
    ]);
};
$source = new Tokens('http://localhost:9176', 'http://localhost:9176/wp-content/uploads');
$target = new Tokens('https://target.example.test', 'https://target.example.test/wp-content/uploads');
$native = Canon::read_file(dirname(__DIR__, 2) . '/fixtures/native-controls/blocks.html');
wprism_check_same('4d26fead2c183c392f01dae7d5838dc47bcf957c07ef5540f90f714fa7bb9f86', hash('sha256', $native),
    'six real picker fragments retain their saved bytes except the three documented nonce substitutions');
$database(1);
$canonical = Blocks::capture_rewrite($native, $policy, $source);
wprism_check_same([], $source->warnings, 'native selected controls capture without dangling-reference warnings');
$blocks = array_values(array_filter(parse_blocks($canonical), static fn(array $block): bool => $block['blockName'] !== null));
wprism_check_same(6, count($blocks), 'all six native selected controls survive capture');
$fields = ['id', 'url', 'alt', 'caption'];
foreach ($blocks as $block) {
    $attrs = $block['attrs'];
    $value = $attrs['gallery'] ?? [$attrs['signature'] ?? $attrs['patternImage']];
    foreach ($value as $image) {
        wprism_check_same('{{post:' . $uuid . '}}', $image['id'], $block['blockName'] . ' selection retains its portable identity');
        if (isset($attrs['gallery'])) {
            wprism_check_same($fields, array_keys($image), $block['blockName'] . ' publishes only fields consumed by its native saver');
        } else {
            wprism_check_same(1200, $image['width'], $block['blockName'] . ' native simple-media width remains authored');
            wprism_check_same(800, $image['height'], $block['blockName'] . ' native simple-media height remains authored');
        }
    }
}
wprism_check(!str_contains($canonical, 'nonce') && !str_contains($canonical, 'editLink') && !str_contains($canonical, 'authorLink'),
    'native attachment response credentials and admin metadata cannot enter the repository');
$database(901);
$applied = Blocks::apply_rewrite($canonical, $policy, $target);
wprism_check(!str_contains($applied, 'localhost:9176') && !str_contains($applied, '{{post:'), 'target selections resolve native IDs and URLs');
wprism_check_same($canonical, Blocks::capture_rewrite($applied, $policy, $target), 'six native media controls have an exact cross-ID canonical fixed point');
if (wprism_check_failed() > 0) exit(1);
