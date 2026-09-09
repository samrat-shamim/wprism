<?php
declare(strict_types=1);

// A repository edit bypasses Capture. A declared unsupported native query
// must therefore refuse in the target-free compiler as well as at rewrite.
$root = dirname(__DIR__, 4);
require_once __DIR__ . '/../../lib/agent_version.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
wprism_test_define_agent_versions();

use WPrism\BlockReferenceScanner;
use WPrism\Blocks;
use WPrism\Canon;
use WPrism\RepositoryCompilationException;
use WPrism\RepositoryCompiler;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;

if (($argv[1] ?? '') === '--compile-without-wordpress') {
    $input = Canon::decode(Canon::read_file($argv[2] . '/probe.json'));
    $policy = FrozenPolicy::policy([$input['manifest']], $input['site']);
    try {
        $artifact = RepositoryCompiler::compile($argv[2], $policy);
        $result = ['entities' => count($artifact->tree()), 'diagnostics' => []];
    } catch (RepositoryCompilationException $e) {
        $result = ['entities' => null, 'diagnostics' => $e->diagnostics];
    }
    echo json_encode($result + ['wordpress' => function_exists('parse_blocks'), 'database' => isset($GLOBALS['wpdb'])], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php'] as $file) require_once __DIR__ . '/../../lib/' . $file;
require_once __DIR__ . '/../../support/wp-block-parser-stub.php';
require_once __DIR__ . '/../../support/wp-shortcode-stub.php';
require_once $root . '/agent/src/Grammar/Blocks.php';
require_once $root . '/agent/src/Review/BlockReferenceScanner.php';

$reason = 'Custom query references have no declared transport.';
$manifest = ['name' => 'unsupported-block-fixture', 'spec_version' => 2, 'option_autoload' => 'preserve',
    'post_types' => ['page' => ['class' => 'authored']],
    'widgets' => ['block' => ['settings' => ['content' => ['class' => 'authored', 'codec' => 'blocks']]]],
    'block_attrs' => ['fixture/query' => [
        ['path' => 'query', 'unsupported' => $reason],
        ['path' => 'label', 'tokenize' => 'text'],
        ['path' => 'queryId', 'lint_ok' => true],
    ]]];
$site = FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['page'];
$site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy([$manifest], $site);
$db = FakeWpdb::install();
$source = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
$target = new Tokens('https://target.test', 'https://target.test/wp-content/uploads');
$block = static fn(array $attrs): string => '<!-- wp:fixture/query ' . serialize_block_attributes($attrs) . ' /-->';
$nested = static fn(string $body): string => "<!-- wp:group -->\n<div class=\"wp-block-group\">$body</div>\n<!-- /wp:group -->";
$scratch = sys_get_temp_dir() . '/wprism-unsupported-block-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/posts/page', 0700, true);
mkdir($scratch . '/state/sidebars', 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$uuid = '11111111-1111-4111-8111-000000000003';
$front = ['author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-09 00:00:00',
    'date_gmt' => '2026-09-09 00:00:00', 'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
    'modified' => '2026-09-09 00:00:00', 'modified_gmt' => '2026-09-09 00:00:00', 'parent' => null,
    'ping_status' => 'closed', 'slug' => 'query', 'status' => 'publish', 'terms' => (object) [],
    'title' => 'Query', 'type' => 'page', 'uuid' => $uuid];
$postPath = 'state/posts/page/' . $uuid . '--query.md';
$sidebarPath = 'state/sidebars/main.json';
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
Canon::write_file($scratch . '/probe.json', Canon::encode(['manifest' => $manifest, 'site' => $site]));
$write = static function (string $post, string $widget) use ($scratch, $postPath, $sidebarPath, $front): void {
    Canon::write_file($scratch . '/' . $postPath, Canon::post_file($front, $post));
    Canon::write_file($scratch . '/' . $sidebarPath, Canon::encode(['widgets' => [[
        'uuid' => '33333333-3333-4333-8333-333333333333', 'type' => 'block', 'settings' => ['content' => $widget],
    ]]]));
};
$compile = static function () use ($scratch): array {
    $child = proc_open([PHP_BINARY, __FILE__, '--compile-without-wordpress', $scratch],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($child)) throw new RuntimeException('cannot start isolated compiler fixture');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($child);
    wprism_check_same(0, $status, 'isolated compiler completes its result protocol');
    wprism_check_same('', $error, 'isolated compiler emits no PHP diagnostics');
    $result = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
    wprism_check_same([false, false], [$result['wordpress'], $result['database']], 'compilation needs neither WordPress nor a database');
    return $result;
};

$safe = $block(['label' => 'A portable gallery', 'queryId' => 12]);
$write($nested($safe), $safe);
wprism_check_same(2, $compile()['entities'], 'absent unsupported attribute admits posts and block widgets');
wprism_check_same($safe, Blocks::apply_rewrite(Blocks::capture_rewrite($safe, $policy, $source), $policy, $target),
    'other declared attributes retain their normal round trip');
$queriesBefore = $db->queries();
foreach (['query-string' => 'p=3&post_parent=3', 'null' => null, 'empty-string' => '', 'zero' => 0,
    'false' => false, 'array' => [3], 'empty-object' => (object) []] as $name => $value) {
    $body = $nested($block(['query' => $value]));
    $message = "block 'fixture/query' attribute 'query' is explicitly unsupported: $reason";
    foreach (['capture_rewrite', 'apply_rewrite'] as $method) {
        wprism_check_throws(static fn() => Blocks::$method($body, $policy, $source), RuntimeException::class,
            "$method refuses a present $name attribute", $message);
    }
    $findings = BlockReferenceScanner::scan(parse_blocks($body), $policy->block_attr_rules(), $postPath, 'https://source.test');
    wprism_check_same(['unsupported_block_attr'], array_column($findings, 'class'), "lint refuses the same $name presence");
    foreach (['post', 'widget'] as $surface) {
        $write($surface === 'post' ? $body : $safe, $surface === 'widget' ? $body : $safe);
        $result = $compile();
        wprism_check_same(null, $result['entities'], "repository $surface cannot compile unsupported $name");
        $diagnostics = $result['diagnostics'];
        wprism_check_same(['repository_block_attr_unsupported'], array_column($diagnostics, 'code'),
            "$surface $name produces the specific unsupported-attribute diagnostic");
        if (count($diagnostics) === 1) {
            $diagnostic = $diagnostics[0];
            wprism_check_same(substr($surface === 'post' ? $postPath : $sidebarPath, strlen('state/')),
                $diagnostic['path'], 'refusal identifies its owning state file relative to the state root');
            wprism_check_same('wprism: ' . $message, $diagnostic['message'], 'refusal contains the reviewed reason without echoing the native value');
            $locator = ($surface === 'post' ? 'body' : 'widgets[0].settings.content') . '@' . strpos($body, '<!-- wp:fixture/query') . '.attrs.query';
            wprism_check_same($locator, $diagnostic['locator'], 'refusal identifies the nested comment and attribute');
        }
    }
}
wprism_check_same($queriesBefore, $db->queries(), 'unsupported admission never resolves IDs or contacts the target');

// Legacy declarations coexist with negotiated values through one registry.
// Empty/absent unsupported values cannot acquire a new meaning in v3.
$manifest['spec_version'] = 3;
$manifest['engine_features'] = ['block-attribute-values/v1', 'spec-window/v1'];
$manifest['block_values'] = ['fixture/query' => ['layout' => ['class' => 'authored', 'plain_data' => true]]];
$site = FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['page'];
$site['policy']['taxonomies'] = [];
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
Canon::write_file($scratch . '/probe.json', Canon::encode(['manifest' => $manifest, 'site' => $site]));
$safe = $block(['layout' => ['columns' => 3], 'queryId' => 12]);
$write($safe, $safe);
wprism_check_same(2, $compile()['entities'], 'v3 value rules coexist with absent legacy unsupported attributes');
$write($block(['query' => null, 'layout' => ['columns' => 3]]), $safe);
wprism_check_same(['repository_block_attr_unsupported'], array_column($compile()['diagnostics'], 'code'),
    'v3 value declarations do not mask unsupported legacy fields');

exit(wprism_check_failed() > 0 ? 1 : 0);
