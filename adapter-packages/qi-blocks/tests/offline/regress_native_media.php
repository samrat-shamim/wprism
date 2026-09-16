<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) {
    require_once "$root/sandbox/tests/lib/$file";
}
require_once "$root/sandbox/tests/support/wp-block-parser-stub.php";
require_once "$root/agent/src/Grammar/Blocks.php";
require_once "$root/agent/src/Repository/Ledger.php";
require_once "$root/agent/src/Repository/RepositoryCompiler.php";
require_once "$root/agent/src/Apply/MediaDerivativeWorkset.php";
wprism_test_define_agent_versions();

use WPrism\Blocks;
use WPrism\Canon;
use WPrism\MediaDerivativeWorkset;
use WPrism\RepositoryCompiler;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;

$fixture = dirname(__DIR__, 2) . '/fixtures/native-media';
$manifest = Canon::decode(Canon::read_file(dirname(__DIR__, 2) . '/package/manifest.json'));
$core = Canon::decode(Canon::read_file("$root/platform/adapter-library/core/manifest.json"));
$site = FrozenPolicy::site([$core, $manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['page', 'attachment'];
$site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy([$core, $manifest], $site);
$attachment = '11111111-1111-4111-8111-111111111111';
$page = '22222222-2222-4222-8222-222222222222';
$source = new Tokens('http://localhost:9176', 'http://localhost:9176/wp-content/uploads');
$target = new Tokens('https://target.example.test', 'https://target.example.test/wp-content/uploads');
$database = static function (int $id) use ($attachment): void {
    FakeWpdb::install()->seedTable('wp_wprism_map', [
        ['uuid' => $attachment, 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => $id],
    ]);
};
$native = Canon::read_file($fixture . '/blocks.html');
wprism_check_same('aa4d08e52ab6b1e751cbd011f749f4af408c062445de104bcc3c7365ca88f6b8', hash('sha256', $native),
    'fixture retains the three exact block fragments from native Save and valid reopen');
$database(1);
$body = Blocks::capture_rewrite($native, $policy, $source);
wprism_check_same([], $source->warnings, 'four native crop controls capture without dangling-reference warnings');
$database(901);
$applied = Blocks::apply_rewrite($body, $policy, $target);
wprism_check_same($body, Blocks::capture_rewrite($applied, $policy, $target), 'all selected crop attributes and HTML retain an exact cross-ID canonical fixed point');
wprism_check(!str_contains($applied, 'localhost:9176') && !str_contains($applied, '{{post:'), 'native crop content resolves its target identity and URLs');

$scratch = sys_get_temp_dir() . '/wprism-qi-media-' . bin2hex(random_bytes(8));
foreach (['state/posts/page', 'state/posts/attachment', 'media'] as $dir) mkdir($scratch . '/' . $dir, 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$png = Canon::read_file($fixture . '/original.png');
$media = hash('sha256', $png) . '.png';
Canon::write_file($scratch . '/media/' . $media, $png);
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
$front = ['author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-08 00:00:00',
    'date_gmt' => '2026-09-08 00:00:00', 'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
    'modified' => '2026-09-08 00:00:00', 'modified_gmt' => '2026-09-08 00:00:00', 'parent' => null,
    'ping_status' => 'closed', 'slug' => 'image', 'status' => 'inherit', 'terms' => (object) [],
    'title' => 'Image', 'type' => 'attachment', 'uuid' => $attachment, 'file' => '2026/09/tmp-qi-image.png',
    'media' => $media, 'mime' => 'image/png', 'alt' => ''];
Canon::write_file($scratch . '/state/posts/attachment/' . $attachment . '--image.md', Canon::post_file($front, ''));
$pageFront = array_replace($front, ['type' => 'page', 'uuid' => $page, 'slug' => 'page', 'title' => 'Page', 'status' => 'publish']);
foreach (['file', 'media', 'mime', 'alt'] as $key) unset($pageFront[$key]);
$write = static function (string $value) use ($scratch, $page, $pageFront): void {
    Canon::write_file($scratch . '/state/posts/page/' . $page . '--page.md', Canon::post_file($pageFront, $value));
};
$write($body);
$compiled = RepositoryCompiler::compile($scratch, $policy);
$recipes = $compiled->media_derivatives();
wprism_check_same([251, 293, 317, 347], array_column($recipes, 'width'),
    'immutable compilation schedules all four native consumers, including the Parallax item that previously rendered 404');
wprism_check_same([157, 181, 193, 219], array_column($recipes, 'height'), 'each crop keeps its own correlated dimensions');
wprism_check_same(array_map(static fn(string $size): string => '2026/09/tmp-qi-image-' . $size . '.png',
    ['251x157', '293x181', '317x193', '347x219']), array_column($recipes, 'target_path'), 'every saved native crop URL has exact file work');
wprism_check_same(array_fill(0, 4, $attachment), array_column($recipes, 'attachment_uuid'), 'all four crops bind the immutable original attachment');
wprism_check_same(array_fill(0, 4, [$page]), array_column($recipes, 'consumers'), 'each crop records its actual page consumer');

$prior = $compiled->tree();
$prior[$page]['body'] = '<!-- wp:paragraph --><p>Prior authored content</p><!-- /wp:paragraph -->';
$work = MediaDerivativeWorkset::select($compiled, $policy, $prior, [['uuid' => $page]], []);
wprism_check_same([$attachment], $work->attachments, 'content-only crop changes schedule the unchanged attachment');
wprism_check_same($recipes, $work->recipes, 'the shared attachment transaction receives every selected native crop');

$mutate = static function (callable $edit) use ($body): string {
    $blocks = parse_blocks($body);
    foreach ($blocks as &$block) if ($block['blockName'] === 'qi-blocks/parallax-images') $edit($block['attrs']);
    return serialize_blocks($blocks);
};
foreach (['stale-width', 'wrong-original', 'missing-dimension'] as $fault) {
    $changed = $mutate(static function (array &$attrs) use ($fault): void {
        $item =& $attrs['items'][0]['itemImage'];
        if ($fault === 'stale-width') $item['imageCustomWidth'] = 295;
        if ($fault === 'wrong-original') $item['image']['url'] = '{{uploads}}/2026/09/other-293x181.png';
        if ($fault === 'missing-dimension') unset($item['imageCustomHeight']);
    });
    $write($changed);
    wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), RuntimeException::class,
        "nested native crop $fault refuses before target contact");
}
$write($mutate(static function (array &$attrs): void {
    $attrs['items'][0]['itemImage']['image']['url'] = '{{uploads}}/2026/09/tmp-qi-image.png';
    unset($attrs['items'][0]['itemImage']['imageCustomWidth'], $attrs['items'][0]['itemImage']['imageCustomHeight']);
}));
wprism_check_same([251, 317, 347], array_column(RepositoryCompiler::compile($scratch, $policy)->media_derivatives(), 'width'),
    'pending nested Custom control still displaying the original creates no crop work');
$write($mutate(static function (array &$attrs): void {
    $attrs['mainImageTablet'] = ['id' => '{{post:11111111-1111-4111-8111-111111111111}}', 'url' => '{{uploads}}/2026/09/tmp-qi-image-171x129.png'];
    $attrs['mainImageSizeTablet'] = 'custom';
    $attrs['mainImageCustomWidthTablet'] = 171;
    $attrs['mainImageCustomHeightTablet'] = 129;
}));
wprism_check_same($recipes, RepositoryCompiler::compile($scratch, $policy)->media_derivatives(),
    'declared unused responsive schema placeholders cannot grant image-generation work');
if (wprism_check_failed() > 0) exit(1);
