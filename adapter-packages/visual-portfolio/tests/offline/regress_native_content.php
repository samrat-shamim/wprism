<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) {
    require_once "$root/sandbox/tests/lib/$file";
}
require_once "$root/sandbox/tests/support/wp-block-parser-stub.php";
require_once "$root/sandbox/tests/support/wp-shortcode-stub.php";
require_once "$root/agent/src/Grammar/Blocks.php";
require_once "$root/agent/src/Kernel/BlockAttributeReader.php";
require_once "$root/agent/src/Kernel/BlockValueGrammar.php";
require_once "$root/agent/src/Repository/RepositoryCompiler.php";
require_once "$root/agent/src/Repository/Ledger.php";
wprism_test_define_agent_versions();

use WPrism\BlockAttributeReader;
use WPrism\BlockValueGrammar;
use WPrism\Blocks;
use WPrism\Canon;
use WPrism\RepositoryCompilationException;
use WPrism\RepositoryAuthorizationException;
use WPrism\RepositoryCompiler;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;

$capsule = dirname(__DIR__, 2);
$fixtures = $capsule . '/fixtures/native';
$manifest = Canon::decode(Canon::read_file($capsule . '/package/manifest.json'));
$core = Canon::decode(Canon::read_file("$root/platform/adapter-library/core/manifest.json"));
$site = FrozenPolicy::site([$core, $manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['page', 'portfolio'];
$site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy([$core, $manifest], $site);
$uuid = static fn(int $id): string => '11111111-1111-4111-8111-' . sprintf('%012d', $id);
$database = static function (int $offset) use ($uuid): FakeWpdb {
    $rows = [];
    foreach ([3, 4, 9, 10] as $id) {
        $rows[] = ['uuid' => $uuid($id), 'id_kind' => 'post', 'entity_type' => 'post:page', 'local_id' => $id + $offset];
    }
    $rows[] = ['uuid' => $uuid(2), 'id_kind' => 'term', 'entity_type' => 'term:portfolio_category', 'local_id' => 2 + $offset];
    return FakeWpdb::install()->seedTable('wp_wprism_map', $rows);
};
$sourceHome = 'http://localhost:9186';
$targetHome = 'https://target.example.test/longer-prefix';
$source = new Tokens($sourceHome, $sourceHome . '/wp-content/uploads');
$target = new Tokens($targetHome, $targetHome . '/wp-content/uploads');
$values = BlockValueGrammar::attribute_maps($manifest);
$names = Canon::decode(Canon::read_file($fixtures . '/attribute-names.json'));
foreach ($names as $block => $attributes) {
    $declared = array_merge(array_keys($values[$block] ?? []), array_column($manifest['block_attrs'][$block] ?? [], 'path'));
    sort($declared, SORT_STRING);
    wprism_check_same($attributes, $declared, "$block accounts for the complete observed attribute registry");
}
$provenance = Canon::decode(Canon::read_file($fixtures . '/provenance.json'));
foreach ($provenance['files'] as $file => $expected) {
    wprism_check_same($expected, ['bytes' => filesize($fixtures . '/' . $file), 'sha256' => hash_file('sha256', $fixtures . '/' . $file)],
        "$file retains the admitted native fixture bytes");
}
wprism_check_same(false, $provenance['qualification'], 'native source discovery is not adapter qualification');
$scratch = sys_get_temp_dir() . '/wprism-vp-content-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/posts/page', 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
$front = static fn(int $id): array => ['uuid' => $uuid($id), 'type' => 'page', 'slug' => 'fixture-' . $id, 'title' => 'Fixture ' . $id,
    'author' => 'user:admin', 'parent' => null, 'menu_order' => 0, 'status' => 'publish', 'comment_status' => 'closed',
    'ping_status' => 'closed', 'date' => '2026-09-09 00:00:00', 'date_gmt' => '2026-09-09 00:00:00',
    'modified' => '2026-09-09 00:00:00', 'modified_gmt' => '2026-09-09 00:00:00', 'excerpt' => '', 'meta' => (object) [], 'terms' => (object) []];
$write = static function (int $id, string $body) use ($scratch, $front, $uuid): void {
    Canon::write_file($scratch . '/state/posts/page/' . $uuid($id) . '--fixture-' . $id . '.md', Canon::post_file($front($id), $body));
};
foreach ([3, 4, 9, 10] as $id) $write($id, 'Reference fixture');
$canonical = [];
foreach (['gallery' => 9, 'archive' => 10] as $name => $id) {
    $native = Canon::read_file($fixtures . '/' . $name . '.html');
    $db = $database(0);
    $before = $db->rows('wp_wprism_map');
    $canonical[$name] = Blocks::capture_rewrite($native, $policy, $source);
    wprism_check_same($before, $db->rows('wp_wprism_map'), "$name capture does not mint or change identities");
    wprism_check(!str_contains($canonical[$name], $sourceHome) && !str_contains($canonical[$name], rawurlencode($sourceHome)),
        "$name captures source URLs even inside encoded CSS");
    $database(800);
    $applied = Blocks::apply_rewrite($canonical[$name], $policy, $target);
    wprism_check_same($canonical[$name], Blocks::capture_rewrite($applied, $policy, $target), "$name codec reaches an exact canonical fixed point");
    wprism_check(!str_contains($applied, '{{post:') && !str_contains($applied, '{{uploads}}'), "$name restores native values without unresolved tokens");
    $write($id, $canonical[$name]);
}
wprism_check_same([], array_merge($source->warnings, $target->warnings), 'native block fixtures emit no reference warnings');
$gallery = BlockAttributeReader::read($canonical['gallery'], array_keys($values));
wprism_check_same(12, count($gallery), 'the exact native gallery contains twelve block instances');
$images = $gallery[0]['attrs']['imagesQuery']['images'];
wprism_check_same(['{{post:' . $uuid(3) . '}}', '{{post:' . $uuid(4) . '}}'], array_column($images, 'id'), 'ordered image IDs are durable references');
wprism_check_same('Harbor — আলো', $images[0]['title'], 'native Unicode image title survives');
wprism_check_same("Field notes\nLight & shadow", $images[0]['description'], 'native multiline description survives');
wprism_check_same(['x' => 0.25, 'y' => 0.7], $images[0]['focalPoint'], 'authored focal point remains exact');
$filters = array_values(array_filter($gallery, static fn(array $b): bool => $b['blockName'] === 'visual-portfolio/loop-filter-item'));
wprism_check_same([1], array_column(array_column($filters, 'attrs'), 'count'), 'saved filter counts remain available to the native renderer');
wprism_check_same([], array_column(array_column($filters, 'attrs'), 'taxonomyId'), 'image category term defaults remain absent as natively saved');
$archive = BlockAttributeReader::read($canonical['archive'], ['visual-portfolio/block']);
wprism_check_same('selector { --native-media: url("{{uploads}}/2026/09/harbor.png"); outline: 2px solid #102a43; }',
    $archive[0]['attrs']['custom_css'], 'CSS decoding distinguishes double-hyphen escape from literal single hyphens');
$db = $database(800);
$queries = $db->queries();
$compiled = RepositoryCompiler::compile($scratch, $policy);
wprism_check_same(4, count($compiled->tree()), 'immutable compilation admits the complete native fixture reference graph');
wprism_check_same($queries, $db->queries(), 'immutable compilation makes no database queries');

// Source-reviewed controls absent from the native Save get explicit codec
// probes. These synthetic inputs do not establish native picker qualification.
$manual = '<!-- wp:visual-portfolio/block ' . serialize_block_attributes([
    'posts_ids' => ['3', '4'], 'posts_excluded_ids' => ['10'], 'posts_taxonomies' => ['2'],
    'images' => [['id' => 3, 'url' => $sourceHome . '/image.png', 'title' => 'Portable image']],
]) . ' /-->';
$database(0);
$portable = Blocks::capture_rewrite($manual, $policy, $source);
$database(800);
$restored = Blocks::apply_rewrite($portable, $policy, $target);
$attrs = BlockAttributeReader::read($restored, ['visual-portfolio/block'])[0]['attrs'];
wprism_check_same(['803', '804'], $attrs['posts_ids'], 'manual post IDs keep native string element types');
wprism_check_same(['810'], $attrs['posts_excluded_ids'], 'excluded post IDs rebind independently');
wprism_check_same(['802'], $attrs['posts_taxonomies'], 'legacy term selections use the term identity namespace');
wprism_check_same(803, $attrs['images'][0]['id'], 'legacy image records use post identities');
wprism_check_same($portable, Blocks::capture_rewrite($restored, $policy, $target), 'manual selection codec has an exact fixed point');
$termFilter = '<!-- wp:visual-portfolio/loop-filter-item {"taxonomyId":2,"filter":"field-notes","count":3} /-->';
$database(0);
$portable = Blocks::capture_rewrite($termFilter, $policy, $source);
$database(800);
$restored = Blocks::apply_rewrite($portable, $policy, $target);
$attrs = BlockAttributeReader::read($restored, ['visual-portfolio/loop-filter-item'])[0]['attrs'];
wprism_check_same(['taxonomyId' => 802, 'filter' => 'field-notes', 'count' => 3], $attrs, 'filter term ID rebinds while its native slug and count remain data');
$db = $database(800);
$queries = $db->queries();

// These are adversarial canonical edits, not native editor observations. The
// compiler must refuse them before a target is contacted, even for null/empty.
foreach ($manifest['block_attrs'] as $block => $rules) foreach ($rules as $rule) {
    foreach ([null, '', 'p=3&post_parent=3', ['customQuery' => 'p=3']] as $bad) {
        $body = '<!-- wp:' . $block . ' ' . serialize_block_attributes([$rule['path'] => $bad]) . ' /-->';
        foreach (['capture_rewrite', 'apply_rewrite'] as $method) {
            wprism_check_throws(static fn() => Blocks::$method($body, $policy, $source), RuntimeException::class,
                "$method refuses $block.{$rule['path']} presence", 'explicitly unsupported');
        }
        $write(9, $body);
        $codes = [];
        try { RepositoryCompiler::compile($scratch, $policy); }
        catch (RepositoryCompilationException $failure) { $codes = array_column($failure->diagnostics, 'code'); }
        wprism_check_same(['repository_block_attr_unsupported'], $codes, 'compiler enforces the same unsupported declaration');
    }
}
$write(9, $canonical['gallery']);
foreach (['api_key=MixedCredential-2026-Value' => 'repository_secret_not_allowed', 'private@example.test' => 'repository_pii_not_allowed'] as $value => $reason) {
    $hostile = str_replace('Harbor — আলো', $value, $canonical['gallery']);
    $write(9, $hostile);
    $codes = [];
    try { RepositoryCompiler::compile($scratch, $policy); }
    catch (RepositoryAuthorizationException $failure) { $codes = array_column($failure->diagnostics, 'code'); }
    wprism_check(in_array($reason, $codes, true), 'declared image data retains compiler privacy protection');
}
wprism_check_same($queries, $db->queries(), 'adversarial compilation does not contact the database');
if (wprism_check_failed() > 0) exit(1);
