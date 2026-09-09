<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$fixture = dirname(__DIR__, 2) . '/fixtures';
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) {
    require_once "$root/sandbox/tests/lib/$file";
}
require_once "$root/sandbox/tests/support/wp-block-parser-stub.php";
require_once "$root/sandbox/tests/support/wp-shortcode-stub.php";
require_once "$root/agent/src/Grammar/Blocks.php";
require_once "$root/agent/src/Grammar/Tokens.php";
require_once "$root/agent/src/Policy/Policy.php";
require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Kernel/JsonRefs.php";
require_once "$root/agent/src/Repository/Ledger.php";
require_once "$root/agent/src/Review/Lint.php";
require_once "$root/agent/src/Kernel/BlockAttributeReader.php";
require_once "$root/agent/src/Kernel/BlockValueGrammar.php";
wprism_test_define_agent_versions();

use WPrism\BlockAttributeReader;
use WPrism\Blocks;
use WPrism\Canon;
use WPrism\JsonRefs;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;

$manifest = Canon::decode(Canon::read_file(dirname(__DIR__, 2) . '/package/manifest.json'));
$blockValues = \WPrism\BlockValueGrammar::attribute_maps($manifest);
$core = Canon::decode(Canon::read_file("$root/platform/adapter-library/core/manifest.json"));
$site = FrozenPolicy::site([$core, $manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['post', 'page', 'attachment', 'product', 'wpcf7_contact_form'];
$site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy([$core, $manifest], $site);
$originalValues = $blockValues;
foreach (['image-gallery', 'image-gallery-pinterest', 'image-slider'] as $name) {
    wprism_check_same(['container' => 'list', 'fields' => ['id', 'url', 'alt', 'caption']],
        $originalValues['qi-blocks/' . $name]['gallery']['record_fields'], 'only the three native full-response gallery controls declare record projection');
    unset($originalValues['qi-blocks/' . $name]['gallery']['record_fields']);
}
wprism_check_same('5672b59ca5db32ed09c1a1c1798a08cf341b6d93bb3ab2650e10118134eafd27',
    hash('sha256', Canon::encode($originalValues)), 'compact Qi declarations preserve every reviewed attribute rule apart from the three intentional gallery projections');
$inventory = Canon::decode(Canon::read_file($fixture . '/authoring-inventory.json'));
$uuid = static fn(int $id): string => '11111111-1111-4111-8111-' . sprintf('%012d', $id);
$database = static function (int $offset) use ($uuid): FakeWpdb {
    $rows = [];
    foreach (range(8, 13) as $id) {
        $rows[] = ['uuid' => $uuid($id), 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => $id + $offset];
    }
    return FakeWpdb::install()->seedTable('wp_wprism_map', $rows);
};
$source = new Tokens('http://localhost:9164', 'http://localhost:9164/wp-content/uploads');
$target = new Tokens('https://target.example.test/longer-prefix', 'https://target.example.test/longer-prefix/wp-content/uploads');
$native = Canon::read_file($fixture . '/native-blocks.html');
$database(0);
$captured = Blocks::capture_rewrite($native, $policy, $source);
wprism_check_same([], $source->warnings, 'native Qi content captures without dangling-reference warnings');
wprism_check(!str_contains($captured, 'http://localhost:9164'), 'all saved native source URLs leave body and declared attributes');
$database(800);
$applied = Blocks::apply_rewrite($captured, $policy, $target);
wprism_check_same($captured, Blocks::capture_rewrite($applied, $policy, $target), 'native Qi content has an exact canonical fixed point across different IDs and URLs');
wprism_check(!str_contains($applied, '{{post:') && !str_contains($applied, '{{home}}') && !str_contains($applied, '{{uploads}}'),
    'target Qi body contains resolved native references and URLs');
$names = array_keys($blockValues);
$sourceBlocks = BlockAttributeReader::read($native, $names);
$canonicalBlocks = BlockAttributeReader::read($captured, $names);
$targetBlocks = BlockAttributeReader::read($applied, $names);
$observedNames = array_values(array_unique(array_column($sourceBlocks, 'blockName')));
sort($observedNames, SORT_STRING);
sort($names, SORT_STRING);
wprism_check_same($names, $observedNames, 'native fixture contains every registered Qi block including nested Column and both integration blocks');
wprism_check_same(52, count($sourceBlocks), 'native fixture retains all 52 Qi block instances');
wprism_check_same(count($sourceBlocks), count($targetBlocks), 'capture/apply preserve all native Qi blocks');
$referenceCount = 0;
foreach ($sourceBlocks as $i => $block) {
    $canonical = $canonicalBlocks[$i]['attrs'];
    $after = $targetBlocks[$i]['attrs'];
    foreach ($blockValues[$block['blockName']] as $attribute => $rule) {
        if (!array_key_exists($attribute, $block['attrs'])) continue;
        $before = $block['attrs'][$attribute];
        if ($rule['class'] === 'derived') {
            wprism_check(!array_key_exists($attribute, $canonical) && !array_key_exists($attribute, $after),
                "{$block['blockName']}.$attribute preview is absent from canonical and target content");
            continue;
        }
        foreach ($rule['json_refs'] ?? [] as $reference) {
            $read = static function (mixed $value) use ($reference): array {
                $found = [];
                JsonRefs::walk($value, JsonRefs::parse_path($reference['path']), static function (&$parent, $key) use (&$found): void {
                    $found[] = $parent[$key];
                }, 'Qi native media');
                return $found;
            };
            $beforeIds = $read($before);
            $canonicalIds = $read($canonical[$attribute]);
            $targetIds = $read($after[$attribute]);
            foreach ($beforeIds as $j => $id) {
                if ($id === null || $id === '' || $id === 0) continue;
                ++$referenceCount;
                wprism_check_same('{{post:' . $uuid((int) $id) . '}}', $canonicalIds[$j], "{$block['blockName']}.$attribute records a portable attachment identity");
                wprism_check_same((int) $id + 800, $targetIds[$j], "{$block['blockName']}.$attribute restores its target attachment identity");
            }
        }
        if (($rule['cast'] ?? null) === 'csv') {
            $ids = $before === '' ? [] : explode(',', $before);
            wprism_check_same(array_map(static fn(string $id): string => '{{post:' . $uuid((int) $id) . '}}', $ids), $canonical[$attribute],
                "{$block['blockName']} query captures its ordered selection");
            wprism_check_same(implode(',', array_map(static fn(string $id): int => (int) $id + 800, $ids)), $after[$attribute],
                "{$block['blockName']} query restores native CSV");
        } elseif (($rule['ref'] ?? null) === 'post' && $before !== '') {
            wprism_check_same((string) ((int) $before + 800), $after[$attribute], 'Contact Form 7 selection retains its native string type');
        }
    }
}
wprism_check($referenceCount >= 200, 'expanded native Save fixture exercises at least 200 media coordinates');
wprism_check_same(22, substr_count($applied, 'wp-image-808'), 'saved native HTML image classes follow the same target media identity');

// The native registry's empty defaults cannot exercise these reachable media
// controls. These synthetic cases pin transport only; native Save/reopen is
// still required and is explicitly absent from the capsule's current claim.
foreach (['author-info' => 'signature', 'progress-bar-horizontal' => 'patternImage', 'progress-bar-vertical' => 'patternImage',
    'image-gallery' => 'gallery', 'image-gallery-pinterest' => 'gallery'] as $block => $field) {
    $value = ['id' => 8, 'url' => 'http://localhost:9164/wp-content/uploads/native.png', 'alt' => 'Native media'];
    if ($field === 'gallery') $value = [$value, $value];
    $body = '<!-- wp:qi-blocks/' . $block . ' ' . serialize_block_attributes([$field => $value]) . ' /-->';
    $database(0);
    $portable = Blocks::capture_rewrite($body, $policy, $source);
    $database(800);
    $restored = Blocks::apply_rewrite($portable, $policy, $target);
    wprism_check_same($portable, Blocks::capture_rewrite($restored, $policy, $target), "$block empty-default media control transports without identity drift");
    wprism_check(str_contains($restored, '"id":808') && !str_contains($restored, 'localhost:9164'), "$block empty-default media control resolves target media and URL");
}
if (wprism_check_failed() > 0) exit(1);
