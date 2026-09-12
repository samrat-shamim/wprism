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
foreach (json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/native-editor-saves.json'), true, 512, JSON_THROW_ON_ERROR)['cases'] as $case) {
    $nativeCanonical = Blocks::capture_rewrite($case['body'], $policy, $tokens);
    wprism_check(!str_contains($nativeCanonical, 'map-fixture-target-key') && !str_contains($nativeCanonical, 'historical-fixture-key'), $case['label'] . ' native editor capture removes keys');
    $tokens->bind_block_environment_options(['gmw-map-block-key' => 'new-target-key']);
    wprism_check_same($nativeCanonical, Blocks::capture_rewrite(Blocks::apply_rewrite($nativeCanonical, $policy, $tokens), $policy, $tokens), $case['label'] . ' native editor fixture is a fixed point');
    wprism_check_same([], $policy->repository_constraint_diagnostics([['type' => 'post', 'body' => $nativeCanonical]]), $case['label'] . ' immutable schema accepts the native-derived canonical form');
}
$tokens->bind_block_environment_options([]);
Canon::write_file($path, Canon::post_file($front, $canonical));
$compiled = RepositoryCompiler::compile($scratch, $policy);
wprism_check(isset($compiled->tree()[$uuid]), 'real immutable compiler accepts the credential-free map');
foreach ([
    'missing-core-wrapper-class' => preg_replace('/<div class="wp-block-webfactory-map">/', '<div>', $fixture, 1),
    'mismatched-key' => str_replace('"zoom":12', '"api_key":"different-key","zoom":12', $fixture),
    'duplicate-src' => str_replace('src="', 'src="https://evil.test/" src="', $fixture),
    'extra-html' => str_replace('</iframe>', '</iframe><script>private-key</script>', $fixture),
    'null-address' => str_replace('"Dhaka, Bangladesh"', 'null', $fixture),
    'fractional-zoom' => str_replace('"zoom":12', '"zoom":12.5', $fixture),
    'unknown-field' => str_replace('"zoom":12', '"className":"private-key","zoom":12', $fixture),
] as $label => $body) {
    wprism_check_throws(static fn() => Blocks::capture_rewrite($body, $policy, $tokens, true), RuntimeException::class, "$label refuses even with force", 'static map schema');
}
foreach (['bad&key', '0'] as $invalidKey) {
    $tokens->bind_block_environment_options(['gmw-map-block-key' => $invalidKey]);
    wprism_check_throws(static fn() => Blocks::apply_rewrite($canonical, $policy, $tokens), RuntimeException::class, 'invalid or native-falsey target key refuses without coercion', 'static map schema');
}
$tokens->bind_block_environment_options(['gmw-map-block-key' => 'rotated-target-key']);
wprism_check_same($canonical, Blocks::capture_rewrite(Blocks::apply_rewrite($canonical, $policy, $tokens), $policy, $tokens), 'key rotation does not change authored canonical identity');
$ordinary = '<!-- wp:paragraph --><p>Ordinary content.</p><!-- /wp:paragraph -->';
wprism_check_same($ordinary, Blocks::capture_rewrite($ordinary, $policy, $tokens), 'unrelated block capture remains byte-identical');
wprism_check_same($ordinary, Blocks::apply_rewrite($ordinary, $policy, $tokens), 'unrelated block materialization remains byte-identical');
wprism_check_same([], $tokens->warnings, 'refusals do not downgrade to warnings');

// The real post materializer must roll back an already rebound iframe when
// a later post fails; testing only Blocks cannot establish that boundary.
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Apply/PostMaterializer.php';
require_once $root . '/agent/src/Apply/BlockEnvironmentOptions.php';
$second = $front;
$second['uuid'] = '99999999-9999-4999-8999-999999999999';
$second['slug'] = 'map-second';
Canon::write_file($scratch . '/state/posts/page/' . $second['uuid'] . '--map-second.md', Canon::post_file($second, $canonical));
$compiled = RepositoryCompiler::compile($scratch, $policy);
$row = static fn(int $id, string $slug): array => [
    'ID' => $id, 'post_author' => 1, 'post_date' => '2026-09-12 00:00:00', 'post_date_gmt' => '2026-09-12 00:00:00',
    'post_content' => '<p>Existing target ' . $slug . '</p>', 'post_title' => $slug, 'post_excerpt' => '', 'post_status' => 'publish',
    'comment_status' => 'closed', 'ping_status' => 'closed', 'post_password' => '', 'post_name' => $slug,
    'post_modified' => '2026-09-12 00:00:00', 'post_modified_gmt' => '2026-09-12 00:00:00',
    'post_parent' => 0, 'menu_order' => 0, 'post_type' => 'page', 'post_mime_type' => '', 'guid' => 'local-' . $id,
];
$db = WPrismTest\FakeWpdb::install()->enableInformationSchema()
    ->seedTable('wp_posts', [$row(804, 'map'), $row(809, 'map-second'), $row(900, 'target-only')])
    ->seedTable('wp_users', [['ID' => 1, 'user_login' => 'admin']])
    ->seedTable('wp_postmeta', [['meta_id' => 1, 'post_id' => 804, 'meta_key' => '_edit_lock', 'meta_value' => 'target-runtime']])
    ->seedTable('wp_wprism_map', [
        ['uuid' => $uuid, 'entity_type' => 'post:page', 'id_kind' => 'post', 'local_id' => 804],
        ['uuid' => $second['uuid'], 'entity_type' => 'post:page', 'id_kind' => 'post', 'local_id' => 809],
    ])
    ->seedTable('wp_options', [['option_id' => 1, 'option_name' => 'gmw-map-block-key', 'option_value' => 'map-fixture-target-key', 'autoload' => 'off']]);
foreach (['wp_posts', 'wp_users', 'wp_postmeta', 'wp_wprism_map', 'wp_options'] as $table) $db->setTableEngine($table, 'InnoDB');
$db->setColumns('wp_wprism_map', ['uuid' => 'varchar(36)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned', 'entity_type' => 'varchar(64)'])
    ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id'])
    ->setColumns('wp_posts', ['ID' => 'bigint unsigned', 'post_content' => 'longtext', 'post_type' => 'varchar(20)'])
    ->setColumns('wp_users', ['ID' => 'bigint unsigned', 'user_login' => 'varchar(60)'])
    ->setColumns('wp_postmeta', ['meta_id' => 'bigint unsigned', 'post_id' => 'bigint unsigned', 'meta_key' => 'varchar(255)', 'meta_value' => 'longtext'])
    ->setIndexes('wp_postmeta', [['Key_name' => 'post_id', 'Column_name' => 'post_id', 'Seq_in_index' => 1, 'Non_unique' => 1, 'Sub_part' => null, 'Index_type' => 'BTREE']])
    ->setColumns('wp_options', ['option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)'])
    ->setIndexes('wp_options', [['Key_name' => 'option_name', 'Column_name' => 'option_name', 'Seq_in_index' => 1, 'Non_unique' => 0, 'Sub_part' => null, 'Index_type' => 'BTREE']]);
WPrismTest\WpStore::reset()->seedOptions(['home' => 'https://target.example.test']);
WPrism\EnvironmentValues::set($scratch, 'gmw-map-block-key', 'map-fixture-target-key');
$targetTokens = new Tokens('https://target.example.test', 'https://target.example.test/wp-content/uploads');
$fields = new WPrism\ApplyFieldMaterializer($policy, $targetTokens);
$materializer = new WPrism\PostMaterializer($policy, $targetTokens, $fields,
    new WPrism\RelationshipMaterializer($policy, $fields), new WPrism\AttachmentMaterializer($policy, $fields, $compiled, $scratch));
$write = static function () use ($policy, $scratch, $targetTokens, $fields, $materializer, $compiled): void {
    WPrism\Db::start_repeatable_read('map rollback fixture', new WPrism\NativeDatabaseProfile(
        ['wp_posts', 'wp_postmeta', 'wp_wprism_map', 'wp_users', 'wp_options'], ['wp_posts', 'wp_postmeta']));
    $fields->begin_authored_transaction();
    WPrism\CacheInvalidationTransaction::begin();
    try {
        $targetTokens->bind_block_environment_options(WPrism\BlockEnvironmentOptions::lock($policy, $scratch));
        $warnings = [];
        foreach ($compiled->tree() as $entity) $materializer->finalize_post($entity['data'], $entity['body'], 1, $warnings, []);
        WPrism\Db::commit('map rollback fixture');
        WPrism\CacheInvalidationTransaction::finish();
        wprism_check_same([], $warnings, 'map post materialization has no warnings');
    } catch (Throwable $failure) {
        WPrism\Db::rollback('map rollback fixture');
        throw $failure;
    } finally {
        $targetTokens->bind_block_environment_options([]);
        $fields->end_authored_transaction();
        WPrism\CacheInvalidationTransaction::end();
    }
};
$before = [];
foreach (['wp_posts', 'wp_postmeta', 'wp_wprism_map', 'wp_options'] as $table) $before[$table] = $db->rows($table);
$updates = 0;
$earlierBound = false;
$db->onQuery(static function (string $sql, string $method, WPrismTest\FakeWpdb $observed) use (&$updates, &$earlierBound): ?string {
    if (str_starts_with($sql, 'UPDATE `wp_posts` ') && ++$updates === 2) {
        $earlierBound = substr_count($observed->rows('wp_posts')[0]['post_content'], 'map-fixture-target-key') === 2;
        return 'injected later map post update failure';
    }
    return null;
});
wprism_check_throws($write, RuntimeException::class, 'later checked SQL failure refuses the batch', 'database mutation failed: apply update post');
wprism_check($earlierBound, 'fault occurs after a real target-bound iframe write');
foreach ($before as $table => $rows) wprism_check_same($rows, $db->rows($table), "$table is byte-identical after rollback");
wprism_check_throws(static fn() => Blocks::apply_rewrite($canonical, $policy, $targetTokens), RuntimeException::class, 'rollback clears transaction credentials', 'transaction-bound');
$db->onQuery(null);
$write();
$after = $db->rows('wp_posts');
foreach (array_slice($after, 0, 2) as $post) {
    wprism_check_same(2, substr_count($post['post_content'], 'map-fixture-target-key'), 'retry materializes both target key locations');
    wprism_check_same($canonical, Blocks::capture_rewrite($post['post_content'], $policy, $targetTokens), 'checked SQL output recaptures exactly');
}
wprism_check_same($before['wp_posts'][2], $after[2], 'retry preserves unrelated target-only post');
foreach (['wp_postmeta', 'wp_wprism_map', 'wp_options'] as $table) wprism_check_same($before[$table], $db->rows($table), "retry preserves $table");
$write();
wprism_check_same($after, $db->rows('wp_posts'), 'repeated complete map materialization is idempotent');
wprism_check_summary('map-block-gutenberg credential boundary');
