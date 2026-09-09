<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/agent_version.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Adapter/AdapterContractGrammar.php';
require_once $root . '/agent/src/Policy/ScopeContract.php';
wprism_test_define_agent_versions();

use WPrism\AdapterContractGrammar;
use WPrism\ApplyFieldMaterializer;
use WPrism\AttachmentMaterializer;
use WPrism\CacheInvalidationTransaction;
use WPrism\Canon;
use WPrism\Db;
use WPrism\EntityMetaCapture;
use WPrism\NativeDatabaseProfile;
use WPrism\Policy;
use WPrism\PostMaterializer;
use WPrism\PostMetaInvalidation as Invalidation;
use WPrism\ReferenceRules;
use WPrism\ReferenceShapeGrammar;
use WPrism\RelationshipMaterializer;
use WPrism\RepositoryCompiler;
use WPrism\ScopeContract;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$rule = ['class' => 'derived', Invalidation::FIELD => 'delete'];
$manifest = ['name' => 'cache-fixture', 'spec_version' => 3,
    'engine_features' => [Invalidation::FEATURE, 'spec-window/v1'],
    'post_types' => ['page' => ['class' => 'authored']],
    'post_meta' => ['fixture_cache' => $rule, 'fixture_absent' => $rule,
        'fixture_authored' => ['class' => 'authored'], 'fixture_runtime' => ['class' => 'runtime'],
        'fixture_derived' => ['class' => 'derived'], 'fixture_env' => ['class' => 'env']],
    'term_meta' => ['fixture_cache' => ['class' => 'derived']]];
$site = FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['page'];
$site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy([$manifest], $site);
wprism_check_same($rule, $policy->post_meta_rule('fixture_cache'), 'frozen policy admits an exact negotiated static deletion grant');
wprism_check_same(Invalidation::declaration_grammar(), AdapterContractGrammar::implemented_feature_rows()[Invalidation::FEATURE]['value_constraint'],
    'host schema publishes the same closed grammar the rule validator uses');
foreach (['feature', 'version', 'site', 'class', 'operation', 'null', 'extra', 'note', 'ref', 'repeated'] as $fault) {
    $bad = $manifest;
    if ($fault === 'feature') $bad['engine_features'] = ['spec-window/v1'];
    if ($fault === 'version') $bad['spec_version'] = 2;
    if ($fault === 'class') $bad['post_meta']['fixture_cache']['class'] = 'authored';
    if ($fault === 'operation') $bad['post_meta']['fixture_cache'][Invalidation::FIELD] = 'rebuild';
    if ($fault === 'null') $bad['post_meta']['fixture_cache'][Invalidation::FIELD] = null;
    if ($fault === 'extra') $bad['post_meta']['fixture_cache']['callback'] = 'fixture';
    if ($fault === 'note') $bad['post_meta']['fixture_cache']['note'] = [];
    if ($fault === 'ref') $bad['post_meta']['fixture_cache']['ref'] = 'post';
    if ($fault === 'repeated') $bad['post_meta']['fixture_cache']['repeated_rows'] = null;
    wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes($bad, 'fixture', $fault !== 'site'),
        RuntimeException::class, "$fault cannot broaden the repair contract", 'on_post_write');
}
foreach (['options', 'term_meta', 'user_meta', 'dynamic_options', 'option_patterns', 'post_meta_patterns', 'meta_patterns', 'option_name_refs'] as $section) {
    $bad = ['spec_version' => 3, 'engine_features' => [Invalidation::FEATURE]];
    $bad[$section] = str_contains($section, 'patterns') || $section === 'option_name_refs'
        ? [$rule + ['match' => '^fixture_cache$']] : ['fixture_cache' => $rule];
    wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes($bad, 'fixture', true),
        RuntimeException::class, "$section cannot grant a post write effect", 'on_post_write');
}
foreach (['options', 'dynamic_options'] as $section) {
    $bad = ['spec_version' => 3, 'engine_features' => [Invalidation::FEATURE],
        $section => ['fixture' => ['class' => 'authored', 'sub_keys' => ['nested' => $rule]]]];
    wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes($bad, 'fixture', true),
        RuntimeException::class, "$section subkeys cannot hide a deletion grant", 'on_post_write');
}
wprism_check_throws(static fn() => ReferenceRules::value_rule($rule, 'attached or block value'), RuntimeException::class,
    'other value-rule consumers refuse the effect by default', 'on_post_write');
foreach (['post_types', 'taxonomies', 'table', 'column', 'attached-meta'] as $surface) {
    $bad = ['spec_version' => 3, 'engine_features' => [Invalidation::FEATURE]];
    if ($surface === 'table') $bad['tables']['fixture'] = $rule;
    elseif ($surface === 'column') $bad['tables']['fixture'] = ['class' => 'authored_snapshot', 'columns' => ['cache' => $rule]];
    elseif ($surface === 'attached-meta') $bad['tables']['fixture'] = ['class' => 'authored_snapshot_meta', 'keys' => ['cache' => $rule]];
    else $bad[$surface]['fixture'] = $rule;
    wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes($bad, 'fixture', true), RuntimeException::class,
        "$surface cannot silently ignore a misplaced repair declaration", 'on_post_write');
}
foreach (['exact', 'post-pattern', 'shared-pattern', 'core-exact', 'core-pattern'] as $form) {
    $other = ['name' => str_starts_with($form, 'core-') ? 'core' : 'other-fixture', 'spec_version' => 3];
    if (str_ends_with($form, 'exact')) $other['post_meta']['fixture_cache'] = ['class' => 'derived'];
    else $other[$form === 'shared-pattern' ? 'meta_patterns' : 'post_meta_patterns'] = [['match' => '^fixture_', 'class' => 'runtime']];
    foreach ([[$manifest, $other], [$other, $manifest]] as $order) {
        wprism_check_throws(static fn() => FrozenPolicy::policy($order, FrozenPolicy::site($order, WPRISM_SPEC_VERSION)),
            RuntimeException::class, "$form refuses both pin orders at pure policy load", 'conflicting classification owners');
    }
}
foreach (['authored', 'runtime', 'derived', 'env'] as $class) {
    $override = $site;
    $override['policy']['post_meta']['fixture_cache'] = ['class' => $class];
    wprism_check_throws(static fn() => FrozenPolicy::policy([$manifest], $override), RuntimeException::class,
        "site $class cannot silently remove an authored post's repair dependency", 'cannot replace');
}
$sameOwner = $manifest;
$sameOwner['post_meta_patterns'] = [['match' => '^fixture_', 'class' => 'runtime']];
wprism_check_same($rule, FrozenPolicy::policy([$sameOwner], $site)->post_meta_rule('fixture_cache'),
    'one owner can refine its own general classification with an exact grant');
foreach (['introduce', 'replace', 'echo', 'nested'] as $case) {
    $declared = $manifest;
    $declared['interpreter'] = 'cache-fixture';
    if (in_array($case, ['introduce', 'nested'], true)) unset($declared['post_meta']['fixture_cache']);
    $answer = $case === 'replace' ? ['class' => 'runtime'] : $rule;
    if ($case === 'nested') $answer = ['class' => 'authored', 'sub_keys' => ['nested' => $rule]];
    $dynamic = FrozenPolicy::policy([$declared], $site);
    $interpreter = new class($answer) {
        public function __construct(private array $answer) {}
        public function post_meta_rule(string $key, array $all): ?array { return $key === 'fixture_cache' ? $this->answer : null; }
    };
    (new ReflectionProperty(Policy::class, 'interpreterInstances'))->setValue($dynamic, ['cache-fixture' => $interpreter]);
    wprism_check_throws(static fn() => $dynamic->meta_rule_for_post('fixture_cache', []), RuntimeException::class,
        "interpreter cannot $case a static deletion grant", 'cannot introduce or replace');
}

$scratch = sys_get_temp_dir() . '/wprism-post-cache-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/posts/page', 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$fieldLibrary = $scratch . '/field-library';
mkdir($fieldLibrary, 0700);
foreach (['post_types.page.fields.title', 'menu_fields.locations'] as $surface) {
    foreach ([false, true] as $misplaced) {
        $fields = $manifest;
        $fieldRule = $misplaced ? $rule : ['class' => 'derived'];
        if ($surface === 'menu_fields.locations') $fields['menu_fields']['locations'] = $fieldRule;
        else $fields['post_types']['page']['fields']['title'] = $fieldRule;
        $envelope = FrozenPolicy::envelope([$fields], $site, $fieldLibrary);
        $library = FrozenPolicy::adapterLibrary($fieldLibrary);
        foreach ([
            'frozen' => static fn() => Policy::from_snapshot($envelope, $library),
            'live' => static fn() => Policy::load(null, [$fields['name']], adapterLibrary: $library),
        ] as $loader => $load) {
            if ($misplaced) {
                wprism_check_throws($load, RuntimeException::class,
                    "$loader policy refuses a misplaced repair declaration on $surface", "$surface.on_post_write");
            } else {
                wprism_check($load() instanceof Policy, "$loader policy preserves ordinary derived $surface declarations");
            }
        }
    }
}
$uuid = '11111111-1111-4111-8111-111111111111';
$front = ['uuid' => $uuid, 'type' => 'page', 'slug' => 'cache', 'title' => 'Cache',
    'author' => 'user:admin', 'menu_order' => 0, 'parent' => null,
    'date' => '2026-09-09 00:00:00', 'date_gmt' => '2026-09-09 00:00:00',
    'modified' => '2026-09-09 00:00:00', 'modified_gmt' => '2026-09-09 00:00:00',
    'excerpt' => '', 'status' => 'publish', 'comment_status' => 'closed', 'ping_status' => 'closed',
    'meta' => ['fixture_authored' => 'after'], 'terms' => []];
$body = '<p>' . implode(' ', array_fill(0, 795, 'word')) . '</p>';
$postPath = $scratch . '/state/posts/page/' . $uuid . '--cache.md';
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
Canon::write_file($postPath, Canon::post_file($front, $body));
$policy = FrozenPolicy::policy([$manifest], $site);
wprism_check(!isset($GLOBALS['wpdb']) && !function_exists('get_post_meta'), 'compiler and policy tests start without WordPress or a database');
$artifact = RepositoryCompiler::compile($scratch, $policy);
wprism_check_same(1, count($artifact->tree()), 'portable compiler accepts the repair dependency without running it');
$withCache = $front;
$withCache['meta']['fixture_cache'] = '265';
Canon::write_file($postPath, Canon::post_file($withCache, $body));
wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), RuntimeException::class,
    'repository edits cannot promote a derived cache to canonical intent');
Canon::write_file($postPath, Canon::post_file($front, $body));
$beforeSite = Canon::read_file($scratch . '/site.wprism.json');
wprism_check_throws(static fn() => Policy::set_rule($scratch, 'post_meta', 'fixture_cache', $rule), RuntimeException::class,
    'classify refuses a site-authored deletion grant before publishing');
wprism_check_same($beforeSite, Canon::read_file($scratch . '/site.wprism.json'), 'refused classify preserves exact policy bytes');
$preserving = $manifest;
foreach (['fixture_cache', 'fixture_absent'] as $key) unset($preserving['post_meta'][$key][Invalidation::FIELD]);
$preservingPolicy = FrozenPolicy::policy([$preserving], $site);
$preservingArtifact = RepositoryCompiler::compile($scratch, $preservingPolicy);
wprism_check($artifact->manifest_hash() !== $preservingArtifact->manifest_hash(),
    'the existing compiled manifest identity binds the effect without a second policy digest');
$policy = FrozenPolicy::policy([$manifest], $site);
$scope = ScopeContract::resolve($artifact, $policy, ['post:' . $uuid]);
$preservingScope = ScopeContract::resolve($preservingArtifact, $preservingPolicy, ['post:' . $uuid]);
wprism_check_same([$uuid], $scope['resolution']['live_root_entities'], 'cache repair adds no entity to the selected post scope');
wprism_check($scope['scope_hash'] !== $preservingScope['scope_hash'], 'scope evidence binds the repair declaration through its existing manifest identity');
$front = $artifact->tree()[$uuid]['data'];
$body = $artifact->tree()[$uuid]['body'];

require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../support/wp-block-parser-stub.php';
require_once __DIR__ . '/../../support/wp-shortcode-stub.php';
require_once $root . '/agent/src/Apply/PostMaterializer.php';
require_once $root . '/agent/src/Capture/EntityMetaCapture.php';

$index = static fn(string $column): array => ['Key_name' => $column, 'Column_name' => $column, 'Seq_in_index' => 1,
    'Sub_part' => null, 'Non_unique' => 1, 'Index_type' => 'BTREE'];
$meta = [
    ['meta_id' => 1, 'post_id' => 804, 'meta_key' => 'fixture_cache', 'meta_value' => '265'],
    ['meta_id' => 2, 'post_id' => 804, 'meta_key' => 'fixture_cache', 'meta_value' => null],
    ['meta_id' => 3, 'post_id' => 804, 'meta_key' => 'fixture_authored', 'meta_value' => 'before'],
    ['meta_id' => 4, 'post_id' => 804, 'meta_key' => 'fixture_runtime', 'meta_value' => '23'],
    ['meta_id' => 5, 'post_id' => 804, 'meta_key' => 'fixture_derived', 'meta_value' => 'local derived'],
    ['meta_id' => 6, 'post_id' => 804, 'meta_key' => 'fixture_env', 'meta_value' => 'local env'],
    ['meta_id' => 7, 'post_id' => 804, 'meta_key' => 'FIXTURE_CACHE', 'meta_value' => 'case alias'],
    ['meta_id' => 8, 'post_id' => 804, 'meta_key' => 'fixture_cache ', 'meta_value' => 'space alias'],
    ['meta_id' => 9, 'post_id' => 999, 'meta_key' => 'fixture_cache', 'meta_value' => '17'],
    ['meta_id' => 10, 'post_id' => 804, 'meta_key' => '_wprism_uuid', 'meta_value' => $uuid],
];
$database = static function () use ($meta, $uuid, $index): FakeWpdb {
    $db = FakeWpdb::install()->enableInformationSchema()
        ->seedTable('wp_posts', [['ID' => 804, 'post_type' => 'page', 'post_content' => '<p>Before</p>'],
            ['ID' => 999, 'post_type' => 'page', 'post_content' => '<p>Foreign</p>']])
        ->setColumns('wp_posts', ['ID' => 'bigint unsigned', 'post_type' => 'varchar(20)', 'post_content' => 'longtext'])
        ->seedTable('wp_users', [['ID' => 801, 'user_login' => 'admin']])
        ->setColumns('wp_users', ['ID' => 'bigint unsigned', 'user_login' => 'varchar(60)'])
        ->seedTable('wp_postmeta', $meta)
        ->seedTable('wp_termmeta', [['meta_id' => 1, 'term_id' => 804, 'meta_key' => 'fixture_cache', 'meta_value' => 'term-local']])
        ->seedTable('wp_term_relationships', [])
        ->setColumns('wp_term_relationships', ['object_id' => 'bigint unsigned', 'term_taxonomy_id' => 'bigint unsigned', 'term_order' => 'int'])
        ->seedTable('wp_wprism_map', [['uuid' => $uuid, 'entity_type' => 'post:page', 'id_kind' => 'post', 'local_id' => 804]])
        ->setColumns('wp_wprism_map', ['uuid' => 'varchar(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned'])
        ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id']);
    foreach (['wp_postmeta' => 'post_id', 'wp_termmeta' => 'term_id'] as $table => $owner) {
        $db->setColumns($table, ['meta_id' => 'bigint unsigned', $owner => 'bigint unsigned', 'meta_key' => 'varchar(255)', 'meta_value' => 'longtext'])
            ->setIndexes($table, [$index($owner)]);
    }
    foreach (['wp_posts', 'wp_users', 'wp_postmeta', 'wp_termmeta', 'wp_term_relationships', 'wp_wprism_map'] as $table) $db->setTableEngine($table, 'InnoDB');
    WpStore::reset()->seedOptions(['home' => 'https://target.test']);
    return $db;
};
$tables = ['wp_posts', 'wp_users', 'wp_postmeta', 'wp_termmeta', 'wp_term_relationships', 'wp_wprism_map'];
$snapshot = static function (FakeWpdb $db) use ($tables): array {
    $out = [];
    foreach ($tables as $table) $out[$table] = $db->rows($table);
    return $out;
};
$write = static function (Policy $selected, string $path = 'post') use ($front, $body, $artifact, $scratch, $tables): void {
    $tokens = new Tokens('https://target.test', 'https://target.test/uploads');
    $tokens->policy = $selected;
    $fields = new ApplyFieldMaterializer($selected, $tokens);
    $posts = new PostMaterializer($selected, $tokens, $fields, new RelationshipMaterializer($selected, $fields),
        new AttachmentMaterializer($selected, $fields, $artifact, $scratch));
    Db::start_repeatable_read('post cache fixture', new NativeDatabaseProfile($tables, $tables));
    $fields->begin_authored_transaction();
    CacheInvalidationTransaction::begin();
    try {
        $warnings = [];
        if ($path === 'post') $posts->finalize_post($front, $body, null, $warnings, []);
        elseif ($path === 'term') $fields->reconcile_authored_term_meta(804, []);
        else $fields->reconcile_authored_meta(804, $front['meta'], 'menu item');
        Db::commit('post cache fixture');
        CacheInvalidationTransaction::finish();
        wprism_check_same([], $warnings, "$path cache repair has no diagnostic waiver");
    } catch (Throwable $failure) {
        Db::rollback('post cache fixture');
        throw $failure;
    } finally {
        $fields->end_authored_transaction();
        CacheInvalidationTransaction::end();
    }
};
$expectedMeta = array_values(array_filter($meta, static fn(array $row): bool => !in_array($row['meta_id'], [1, 2], true)));
$expectedMeta[0]['meta_value'] = 'after';
foreach (['post', 'menu'] as $path) {
    $db = $database();
    $before = $snapshot($db);
    $write($policy, $path);
    wprism_check_same($expectedMeta, $db->rows('wp_postmeta'), "$path removes every exact duplicate, preserves every local/alias/foreign row, and writes authored intent");
    wprism_check_same($before['wp_posts'][1], $db->rows('wp_posts')[1], "$path never discovers or writes the other post");
    if ($path === 'post') wprism_check_same($body, $db->rows('wp_posts')[0]['post_content'], 'checked post writer resolves the canonical UUID to target ID 804 and changes its body');
    foreach (['wp_termmeta', 'wp_term_relationships', 'wp_wprism_map'] as $table) wprism_check_same($before[$table], $db->rows($table), "$path preserves $table");
    $once = $snapshot($db);
    $write($policy, $path);
    wprism_check_same($once, $snapshot($db), "$path repeated materialization preserves the exact repaired fixed point, including absence");
    wprism_check(in_array(['op' => 'delete', 'group' => 'post_meta', 'key' => '804'], WpStore::instance()->cacheEvents, true),
        "$path uses the existing owner-local WordPress cache invalidation queue");
}
$db = $database();
$write($preservingPolicy);
$preserved = $meta;
$preserved[2]['meta_value'] = 'after';
wprism_check_same($preserved, $db->rows('wp_postmeta'), 'ordinary derived classification preserves stale cache bytes unless explicitly opted in');
$db = $database();
$before = $snapshot($db);
$write($policy, 'term');
wprism_check_same($before, $snapshot($db), 'term metadata reconciliation has no post-meta effect');

// The shared checked SQL driver, owner lock, and transaction authority execute
// every fault. These are materializer rollback witnesses, not a full Apply run.
foreach (['delete', 'late-write', 'readback', 'surviving-cache', 'oversize', 'missing-index'] as $fault) {
    $db = $database();
    if ($fault === 'oversize') {
        $oversize = $meta;
        $oversize[0]['meta_value'] = str_repeat('x', \WPrism\MetaRows::MAX_META_VALUE_BYTES + 1);
        $db->seedTable('wp_postmeta', $oversize);
    }
    if ($fault === 'missing-index') $db->setIndexes('wp_postmeta', []);
    $before = $snapshot($db);
    $deleted = false;
    $sawRemovedRows = false;
    $db->onQuery(static function (string $sql, string $method, FakeWpdb $db) use ($fault, &$deleted, &$sawRemovedRows, $meta): ?string {
        if ($deleted && array_intersect([1, 2], array_column($db->rows('wp_postmeta'), 'meta_id')) === []) $sawRemovedRows = true;
        if (str_starts_with($sql, 'DELETE FROM `wp_postmeta`')) {
            if ($fault === 'delete') return 'fixture delete denied';
            $deleted = true;
        }
        if ($deleted && str_starts_with($sql, 'UPDATE `wp_postmeta`') && $fault === 'late-write') return 'fixture late write denied';
        if ($deleted && str_contains($sql, 'FROM `wp_postmeta`') && str_contains($sql, 'FOR UPDATE')) {
            if ($fault === 'readback') return 'fixture readback denied';
            if ($fault === 'surviving-cache') {
                $db->seedTable('wp_postmeta', array_merge($db->rows('wp_postmeta'), [$meta[0]]));
                $deleted = false;
            }
        }
        return null;
    });
    wprism_check_throws(static fn() => $write($policy), RuntimeException::class, "$fault refuses the checked post write");
    if (in_array($fault, ['late-write', 'readback', 'surviving-cache'], true)) wprism_check($sawRemovedRows, "$fault occurs after the exact cache rows have actually been deleted");
    wprism_check_same($before, $snapshot($db), "$fault rollback restores complete post, metadata, relationship and identity tables");
    $db->onQuery(null);
    if (in_array($fault, ['oversize', 'missing-index'], true)) $db = $database();
    $write($policy);
    wprism_check_same($expectedMeta, $db->rows('wp_postmeta'), "$fault retry converges through the same checked writer");
}
$tokens = new Tokens('https://target.test', 'https://target.test/uploads');
$tokens->policy = $policy;
$nothing = static function (...$arguments): void {};
$capture = new EntityMetaCapture($policy, $tokens, $nothing, $nothing, $nothing);
wprism_check_same([false, null], $capture->classifyValue('fixture_cache', ['265'], ['fixture_cache' => '265'], 'fixture', 'fixture', false),
    'ordinary Capture excludes the derived cache; repair intent never becomes canonical data');
wprism_check_summary('post-meta invalidation');
