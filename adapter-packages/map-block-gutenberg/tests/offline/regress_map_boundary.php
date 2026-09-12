<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Grammar/Blocks.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
wprism_test_define_agent_versions();

use WPrism\AdapterLibrary;
use WPrism\Blocks;
use WPrism\Canon;
use WPrism\Policy;
use WPrism\RepositoryCompiler;
use WPrism\Tokens;

$policy = Policy::load(null, ['core', 'map-block-gutenberg'], adapterLibrary: AdapterLibrary::fromSourcePackage($root, 'map-block-gutenberg'));
$policy->site['spec_version'] = WPRISM_SPEC_VERSION;
$fixture = (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/saved-default-key.html');
wprism_check(!str_contains($fixture, '"api_key"') && str_contains($fixture, 'key=map-fixture-source-key'), 'default-key fixture holds the key only in saved HTML');
$variants = [
    'saved-default' => $fixture,
    'explicit-key' => str_replace('"zoom":12', '"api_key":"map-fixture-source-key","zoom":12', $fixture),
    'nested' => '<!-- wp:group --><div class="wp-block-group">' . $fixture . '</div><!-- /wp:group -->',
    'no-attributes' => '<!-- wp:webfactory/map --><iframe src="https://www.google.com/maps/embed/v1/place?key=map-fixture-source-key"></iframe><!-- /wp:webfactory/map -->',
    'self-closing' => '<!-- wp:webfactory/map /-->',
    'empty-key' => '<!-- wp:webfactory/map {"api_key":""} /-->',
    'null-key' => '<!-- wp:webfactory/map {"api_key":null} /-->',
    'future-shape' => '<!-- wp:webfactory/map {"newSetting":{"secret":"map-fixture-source-key"}} /-->',
    'long-utf8' => '<!-- wp:webfactory/map ' . json_encode(['address' => str_repeat('ঢাকা / 東京 " & ', 500), 'height' => -1, 'zoom' => 999999]) . ' /-->',
];

// The repository-side constraint must work before WordPress has been loaded.
wprism_check(!function_exists('parse_blocks'), 'immutable repository checks begin without WordPress');
foreach ($variants + ['malformed' => '<!-- wp:webfactory/map {"api_key":broken} -->'] as $label => $body) {
    foreach (['post', 'sidebar'] as $type) {
        $entity = $type === 'post'
            ? ['type' => 'post', 'path' => 'state/posts/page/map.md', 'body' => $body]
            : ['type' => 'sidebar', 'path' => 'state/sidebars/main.json', 'data' => ['widgets' => [['type' => 'block', 'settings' => ['content' => $body]]]]];
        $diagnostics = $policy->repository_constraint_diagnostics([$entity]);
        wprism_check_same(1, count($diagnostics), "$label $type has one immutable refusal");
        wprism_check_same('adapter_schema_content_mismatch', $diagnostics[0]['code'] ?? '', "$label $type names the adapter constraint");
        wprism_check(!str_contains(Canon::encode($diagnostics), 'map-fixture-source-key'), "$label $type diagnostic excludes credentials");
    }
}
wprism_check_same([], $policy->repository_constraint_diagnostics([['type' => 'post', 'body' => '<!-- wp:paragraph --><p>webfactory/map is a plugin block name.</p><!-- /wp:paragraph -->']]), 'ordinary text mentioning the plugin is allowed');

// Exercise the real immutable compiler, not only the interpreter callback.
$scratch = sys_get_temp_dir() . '/wprism-map-boundary-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$uuid = '11111111-1111-4111-8111-111111111111';
$front = ['author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-12 00:00:00',
    'date_gmt' => '2026-09-12 00:00:00', 'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
    'modified' => '2026-09-12 00:00:00', 'modified_gmt' => '2026-09-12 00:00:00', 'parent' => null,
    'ping_status' => 'closed', 'slug' => 'map', 'status' => 'publish', 'terms' => (object) [],
    'title' => 'Map', 'type' => 'page', 'uuid' => $uuid];
$path = $scratch . '/state/posts/page/' . $uuid . '--map.md';
Canon::write_file($path, Canon::post_file($front, $fixture));
wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), RuntimeException::class,
    'immutable compiler refuses a repository-injected map without WordPress', 'webfactory/map');
wprism_check_same(Canon::post_file($front, $fixture), file_get_contents($path), 'compiler refusal preserves input bytes');

require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/frozen_policy.php';
require_once $root . '/sandbox/tests/support/wp-block-parser-stub.php';
require_once $root . '/sandbox/tests/support/wp-shortcode-stub.php';
$tokens = new Tokens('https://source.example.test', 'https://source.example.test/wp-content/uploads');
$attributeManifest = [
    'name' => 'map-attribute-only-fixture', 'spec_version' => 2,
    'block_attrs' => ['webfactory/map' => [['path' => 'api_key', 'unsupported' => 'API keys are environment-local']]],
];
$attributeOnly = \WPrismTest\FrozenPolicy::policy([$attributeManifest], \WPrismTest\FrozenPolicy::site([$attributeManifest], WPRISM_SPEC_VERSION));
wprism_check(str_contains(Blocks::capture_rewrite($fixture, $attributeOnly, $tokens), 'map-fixture-source-key'),
    'counterfactual attribute-only refusal leaks the default key through the real block codec');
foreach ($variants as $label => $body) {
    foreach ([false, true] as $force) {
        if (in_array($label, ['saved-default', 'explicit-key', 'nested'], true)) {
            $canonical = Blocks::capture_rewrite($body, $policy, $tokens, $force);
            wprism_check(!str_contains($canonical, 'map-fixture-source-key') && substr_count($canonical, '@env') === 2,
                "$label capture removes both credential locations with force=" . (int) $force);
            wprism_check_same([], $policy->repository_constraint_diagnostics([['type' => 'post', 'body' => $canonical]]), "$label canonical form passes immutable checks");
            $tokens->bind_block_environment_options(['gmw-map-block-key' => 'map-fixture-target-key']);
            $native = Blocks::apply_rewrite($canonical, $policy, $tokens);
            wprism_check_same(2, substr_count($native, 'map-fixture-target-key'), "$label binds both target credential locations");
            wprism_check_same($canonical, Blocks::capture_rewrite($native, $policy, $tokens), "$label recapture is byte-identical");
            $tokens->bind_block_environment_options([]);
            wprism_check_throws(static fn() => Blocks::apply_rewrite($canonical, $policy, $tokens), RuntimeException::class,
                "$label missing transaction binding refuses", 'transaction-bound');
        } else {
            wprism_check_throws(static fn() => Blocks::capture_rewrite($body, $policy, $tokens, $force), RuntimeException::class,
                "$label capture refuses with force=" . (int) $force, 'wprism:');
        }
    }
    wprism_check_throws(static fn() => Blocks::apply_rewrite($body, $policy, $tokens), RuntimeException::class,
        "$label raw materialization refuses", 'wprism:');
}
$canonical = Blocks::capture_rewrite($fixture, $policy, $tokens);
Canon::write_file($path, Canon::post_file($front, $canonical));
$compiled = RepositoryCompiler::compile($scratch, $policy);
wprism_check(isset($compiled->tree()[$uuid]), 'real immutable compiler accepts the credential-free map');
foreach ([
    'mismatched-key' => str_replace('"zoom":12', '"api_key":"different-key","zoom":12', $fixture),
    'duplicate-src' => str_replace('src="', 'src="https://evil.test/" src="', $fixture),
    'extra-html' => str_replace('</iframe>', '</iframe><script>private-key</script>', $fixture),
    'null-address' => str_replace('"Dhaka, Bangladesh"', 'null', $fixture),
    'fractional-zoom' => str_replace('"zoom":12', '"zoom":12.5', $fixture),
    'unknown-field' => str_replace('"zoom":12', '"className":"private-key","zoom":12', $fixture),
] as $label => $body) {
    wprism_check_throws(static fn() => Blocks::capture_rewrite($body, $policy, $tokens, true), RuntimeException::class, "$label refuses even with force", 'static map schema');
}
$tokens->bind_block_environment_options(['gmw-map-block-key' => 'bad&key']);
wprism_check_throws(static fn() => Blocks::apply_rewrite($canonical, $policy, $tokens), RuntimeException::class, 'invalid target key refuses without coercion', 'static map schema');
$tokens->bind_block_environment_options(['gmw-map-block-key' => 'rotated-target-key']);
wprism_check_same($canonical, Blocks::capture_rewrite(Blocks::apply_rewrite($canonical, $policy, $tokens), $policy, $tokens), 'key rotation does not change authored canonical identity');
$ordinary = '<!-- wp:paragraph --><p>Ordinary content.</p><!-- /wp:paragraph -->';
wprism_check_same($ordinary, Blocks::capture_rewrite($ordinary, $policy, $tokens), 'unrelated block capture remains byte-identical');
wprism_check_same($ordinary, Blocks::apply_rewrite($ordinary, $policy, $tokens), 'unrelated block materialization remains byte-identical');
wprism_check_same([], $tokens->warnings, 'refusals do not downgrade to warnings');
wprism_check_summary('map-block-gutenberg credential boundary');
