<?php
/**
 * Offline regression for manifest-backed post front-matter fields.
 *
 * WooCommerce updates post_modified/post_modified_gmt during target-only
 * product and variation saves, even when the authored product inputs are
 * unchanged. This suite proves the generic field contract without
 * WordPress/Docker:
 *   - only manifest-declared derived timestamps disappear from the hash basis;
 *   - complete path-to-semantic-hash projections accept only those declared
 *     derived differences while retaining identity and non-post strictness;
 *   - existing Woo rows preserve those target timestamps while authored
 *     columns are still written;
 *   - new rows still receive captured starting timestamps;
 *   - undeclared post types retain authored timestamp behavior; and
 *   - unsupported field names/classes fail Policy::load() loudly.
 */

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

function get_option(string $name) {
    return $name === 'home' ? 'https://offline.example.test' : null;
}
function wp_upload_dir($time = null, bool $create = false): array {
    return ['baseurl' => 'https://offline.example.test/wp-content/uploads'];
}
function untrailingslashit(string $value): string {
    return rtrim($value, '/\\');
}
function maybe_serialize($value) {
    return is_array($value) || is_object($value) || $value === null || is_bool($value)
        ? serialize($value)
        : $value;
}

require __DIR__ . '/../../agent/src/TransientDbException.php';
require __DIR__ . '/../../agent/src/Db.php';
require __DIR__ . '/../../agent/src/Uuid.php';
require __DIR__ . '/../../agent/src/Canon.php';
require __DIR__ . '/../../agent/src/OrderPreserved.php';
require __DIR__ . '/../../agent/src/Secrets.php';
require __DIR__ . '/../../agent/src/OptionState.php';
require __DIR__ . '/../../agent/src/Policy.php';
require __DIR__ . '/../../agent/src/RepositoryAuthorization.php';
require __DIR__ . '/../../agent/src/Ledger.php';
require __DIR__ . '/../../agent/src/Tokens.php';
require __DIR__ . '/../../agent/src/Blocks.php';
require __DIR__ . '/../../agent/src/Apply.php';

use Duo\Apply;
use Duo\Canon;
use Duo\OptionState;
use Duo\Policy;
use Duo\RepositoryAuthorization;
use Duo\RepositoryAuthorizationException;
use Duo\Tokens;

final class PostFieldFakeWpdb {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $term_relationships = 'wp_term_relationships';
    public string $last_error = '';
    public int $insert_id = 100;
    /** @var array<string,int> */
    public array $map = [];
    /** @var array<int,array{table:string,data:array,where:array}> */
    public array $updates = [];
    /** @var array<int,array{table:string,data:array}> */
    public array $inserts = [];

    public function prepare(string $sql, ...$args): string {
        foreach ($args as $arg) {
            $replacement = is_int($arg)
                ? (string) $arg
                : "'" . str_replace("'", "''", (string) $arg) . "'";
            $sql = preg_replace('/%[ds]/', $replacement, $sql, 1);
        }
        return $sql;
    }

    public function get_var(string $sql) {
        if (str_contains($sql, 'SELECT local_id FROM wp_duo_map')) {
            preg_match("/uuid = '([^']+)'/", $sql, $m);
            return $this->map[$m[1] ?? ''] ?? null;
        }
        return null;
    }

    public function get_row(string $sql, $format = null) {
        return null;
    }

    public function get_results(string $sql, $format = null): array {
        return [];
    }

    public function update(string $table, array $data, array $where, $format = null, $whereFormat = null): int {
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
        return 1;
    }

    public function insert(string $table, array $data, $format = null): int {
        $this->inserts[] = ['table' => $table, 'data' => $data];
        $this->insert_id++;
        return 1;
    }

    public function query(string $sql): int {
        return 1;
    }
}

function set_private(object $object, string $property, $value): void {
    $reflection = new ReflectionProperty($object, $property);
    $reflection->setValue($object, $value);
}

function invoke_private(object $object, string $method, ...$args) {
    $reflection = new ReflectionMethod($object, $method);
    return $reflection->invoke($object, ...$args);
}

$failures = 0;
function check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "ok: $message\n";
    } else {
        echo "FAIL: $message\n";
        $failures++;
    }
}

function check_throws(callable $callable, string $needle, string $message): void {
    global $failures;
    try {
        $callable();
        echo "FAIL: $message (did not throw)\n";
        $failures++;
    } catch (Throwable $throwable) {
        if (str_contains($throwable->getMessage(), $needle)) {
            echo "ok: $message\n";
        } else {
            echo "FAIL: $message (wrong error: {$throwable->getMessage()})\n";
            $failures++;
        }
    }
}

function write_manifest(string $dir, string $name, array $manifest): void {
    file_put_contents("$dir/$name.json", json_encode($manifest, JSON_PRETTY_PRINT));
}

function post_front(string $type, string $uuid, string $modified = '2026-08-08 00:00:00'): array {
    return [
        'uuid' => $uuid,
        'type' => $type,
        'author' => '',
        'date' => '2026-08-01 00:00:00',
        'date_gmt' => '2026-08-01 00:00:00',
        'title' => ucfirst($type) . ' title',
        'slug' => $type . '-slug',
        'status' => 'publish',
        'comment_status' => 'open',
        'ping_status' => 'closed',
        'excerpt' => '',
        'modified' => $modified,
        'modified_gmt' => $modified,
        'parent' => null,
        'menu_order' => 0,
        'meta' => [],
        'terms' => [],
    ];
}

/** @return array{path:string,hash:string} */
function semantic_post_entity(Policy $policy, array $front, string $path): array {
    return [
        'path' => $path,
        'hash' => hash('sha256', Canon::post_hash_basis($front, '', $policy)),
    ];
}

/** @param array<int,array{path:string,hash:string}> $entities */
function semantic_path_hash_projection(array $entities): array {
    $projection = [];
    foreach ($entities as $entity) {
        if (array_key_exists($entity['path'], $projection)) {
            throw new RuntimeException('duplicate compiled state path: ' . $entity['path']);
        }
        $projection[$entity['path']] = $entity['hash'];
    }
    ksort($projection, SORT_STRING);
    return $projection;
}

/** @return array<int,array<string,mixed>> */
function post_authorization_diagnostics(Policy $policy, array $front): array {
    $entity = [
        'type' => 'post',
        'post_type' => (string) $front['type'],
        'path' => 'posts/' . $front['type'] . '/' . $front['uuid'] . '--fixture.md',
        'hash' => hash('sha256', Canon::post_hash_basis($front, '', $policy)),
        'content' => Canon::post_file($front, ''),
        'data' => $front,
        'body' => '',
    ];
    try {
        RepositoryAuthorization::assert_tree($policy, [(string) $front['uuid'] => $entity]);
        return [];
    } catch (RepositoryAuthorizationException $exception) {
        return $exception->diagnostics;
    }
}

/** @return array<int,array<string,mixed>> */
function option_authorization_diagnostics(Policy $policy, array $document): array {
    $entity = [
        'type' => 'options',
        'path' => 'options/core.json',
        'hash' => hash('sha256', Canon::encode($document)),
        'content' => Canon::encode($document),
        'data' => $document,
    ];
    try {
        RepositoryAuthorization::assert_tree($policy, ['options/core' => $entity]);
        return [];
    } catch (RepositoryAuthorizationException $exception) {
        return $exception->diagnostics;
    }
}

function apply_instance(Policy $policy, Tokens $tokens): Apply {
    $apply = (new ReflectionClass(Apply::class))->newInstanceWithoutConstructor();
    set_private($apply, 'repo', '/offline');
    set_private($apply, 'policy', $policy);
    set_private($apply, 'tokens', $tokens);
    return $apply;
}

$root = dirname(__DIR__, 2);
$fixtureDir = sys_get_temp_dir() . '/duo_regress_post_fields_' . bin2hex(random_bytes(4));
mkdir($fixtureDir, 0777, true);
register_shutdown_function(static function () use ($fixtureDir): void {
    if (!is_dir($fixtureDir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixtureDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($fixtureDir);
});

$validManifest = [
    'name' => 'woo-fields',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => [
        'product' => [
            'fields' => [
                'modified' => ['class' => 'derived'],
                'modified_gmt' => ['class' => 'derived'],
            ],
        ],
        'product_variation' => [
            'fields' => [
                'modified' => ['class' => 'derived'],
                'modified_gmt' => ['class' => 'derived'],
                'title' => ['class' => 'derived'],
            ],
        ],
    ],
];
write_manifest($fixtureDir, 'woo-fields', $validManifest);
write_manifest($fixtureDir, 'bad-field-name', [
    'name' => 'bad-field-name',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => ['product' => ['fields' => ['slug' => ['class' => 'derived']]]],
]);
write_manifest($fixtureDir, 'bad-field-class', [
    'name' => 'bad-field-class',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => ['product' => ['fields' => ['modified' => ['class' => 'runtime']]]],
]);
putenv('DUO_MANIFESTS_DIR=' . $fixtureDir);

$policy = Policy::load(null, ['woo-fields']);
$policy->site = ['policy' => [
    'post_types' => ['product', 'product_variation', 'article'],
    'taxonomies' => [],
    'post_meta' => [
        'derived_projection' => ['class' => 'derived'],
        'runtime_projection' => ['class' => 'runtime'],
    ],
    'options' => [
        'derived_projection' => ['class' => 'derived', 'autoload' => 'preserve'],
        'runtime_projection' => ['class' => 'runtime', 'autoload' => 'preserve'],
    ],
]];
check($policy->field_class('product', 'modified') === 'derived', 'fixture product.modified is derived');
check($policy->field_class('product_variation', 'modified_gmt') === 'derived', 'fixture product_variation.modified_gmt is derived');
check($policy->field_class('article', 'modified') === 'authored', 'undeclared post type keeps modified authored');

putenv('DUO_MANIFESTS_DIR=' . $root . '/manifests');
$realWooPolicy = Policy::load(null, ['woocommerce']);
check($realWooPolicy->field_class('product', 'modified') === 'derived', 'shipped Woo manifest declares product.modified derived');
check($realWooPolicy->field_class('product', 'modified_gmt') === 'derived', 'shipped Woo manifest declares product.modified_gmt derived');
check($realWooPolicy->field_class('product_variation', 'modified') === 'derived', 'shipped Woo manifest declares variation.modified derived');
check($realWooPolicy->field_class('product_variation', 'modified_gmt') === 'derived', 'shipped Woo manifest declares variation.modified_gmt derived');
putenv('DUO_MANIFESTS_DIR=' . $fixtureDir);

check_throws(
    fn() => Policy::load(null, ['bad-field-name']),
    'post_types.product.fields.slug',
    'unsupported post field name is rejected at manifest load'
);
check_throws(
    fn() => Policy::load(null, ['bad-field-class']),
    'post_types.product.fields.modified.class',
    'unsupported post field class is rejected at manifest load'
);

$uuid = '018f0000-0000-7000-8000-000000000001';
$source = post_front('product', $uuid, '2026-08-08 00:00:01');
$timestampOnly = $source;
$timestampOnly['modified'] = '2030-01-01 00:00:01';
$timestampOnly['modified_gmt'] = '2030-01-01 00:00:01';
check(
    Canon::post_hash_basis($source, '', $policy) === Canon::post_hash_basis($timestampOnly, '', $policy),
    'hash basis ignores only declared Woo timestamp differences'
);
$titleChanged = $source;
$titleChanged['title'] = 'authored title changed';
check(
    Canon::post_hash_basis($source, '', $policy) !== Canon::post_hash_basis($titleChanged, '', $policy),
    'hash basis still compares an authored Woo product title'
);
$generic = post_front('article', '018f0000-0000-7000-8000-000000000002');
$genericTimestampChanged = $generic;
$genericTimestampChanged['modified'] = '2030-01-01 00:00:01';
$genericTimestampChanged['modified_gmt'] = '2030-01-01 00:00:01';
check(
    Canon::post_hash_basis($generic, '', $policy) !== Canon::post_hash_basis($genericTimestampChanged, '', $policy),
    'hash basis keeps undeclared post-type timestamps authored'
);

$productPath = 'posts/product/' . $uuid . '--product-slug.md';
$sourceProjection = semantic_path_hash_projection([
    semantic_post_entity($policy, $source, $productPath),
]);
check(
    Canon::post_file($source, '') !== Canon::post_file($timestampOnly, ''),
    'derived timestamp probe changes honest raw capture bytes'
);
check(
    $sourceProjection === semantic_path_hash_projection([
        semantic_post_entity($policy, $timestampOnly, $productPath),
    ]),
    'complete semantic projection accepts declared derived-only timestamp differences'
);
check(
    $sourceProjection !== semantic_path_hash_projection([
        semantic_post_entity($policy, $titleChanged, $productPath),
    ]),
    'complete semantic projection rejects an authored product title difference'
);
check(
    semantic_path_hash_projection([
        semantic_post_entity($policy, $generic, 'posts/article/' . $generic['uuid'] . '--article-slug.md'),
    ]) !== semantic_path_hash_projection([
        semantic_post_entity($policy, $genericTimestampChanged, 'posts/article/' . $generic['uuid'] . '--article-slug.md'),
    ]),
    'complete semantic projection rejects undeclared post-type timestamp differences'
);
check(
    $sourceProjection !== semantic_path_hash_projection([
        semantic_post_entity($policy, $timestampOnly, 'posts/product/' . $uuid . '--moved.md'),
    ]),
    'complete semantic projection rejects an identity-bearing path difference'
);
$optionsContent = Canon::encode(OptionState::document([
    'fixture' => OptionState::present('one', 'yes'),
]));
$changedOptionsContent = Canon::encode(OptionState::document([
    'fixture' => OptionState::present('two', 'yes'),
]));
check(
    semantic_path_hash_projection([[
        'path' => 'options/core.json',
        'hash' => hash('sha256', $optionsContent),
    ]]) !== semantic_path_hash_projection([[
        'path' => 'options/core.json',
        'hash' => hash('sha256', $changedOptionsContent),
    ]]),
    'complete semantic projection rejects a non-post state difference'
);

check(
    post_authorization_diagnostics($policy, $source) === [],
    'RepositoryAuthorization accepts captured Woo product derived timestamps'
);
$unknownFrontField = $source;
$unknownFrontField['unsupported_front_field'] = 'must refuse';
$unknownFrontDiagnostics = post_authorization_diagnostics($policy, $unknownFrontField);
check(
    count(array_filter($unknownFrontDiagnostics, static fn(array $d): bool =>
        ($d['surface'] ?? '') === 'post_field'
        && ($d['field'] ?? '') === 'unsupported_front_field'
        && ($d['classification'] ?? '') === 'unclassified'
    )) === 1,
    'RepositoryAuthorization rejects unsupported post front fields'
);
$derivedMetaFront = $source;
$derivedMetaFront['meta'] = ['derived_projection' => 'must refuse'];
$derivedMetaDiagnostics = post_authorization_diagnostics($policy, $derivedMetaFront);
check(
    count(array_filter($derivedMetaDiagnostics, static fn(array $d): bool =>
        ($d['surface'] ?? '') === 'post_meta'
        && ($d['field'] ?? '') === 'derived_projection'
        && ($d['classification'] ?? '') === 'derived'
    )) === 1,
    'RepositoryAuthorization still rejects derived post_meta'
);
$runtimeMetaFront = $source;
$runtimeMetaFront['meta'] = ['runtime_projection' => 'must refuse'];
$runtimeMetaDiagnostics = post_authorization_diagnostics($policy, $runtimeMetaFront);
check(
    count(array_filter($runtimeMetaDiagnostics, static fn(array $d): bool =>
        ($d['surface'] ?? '') === 'post_meta'
        && ($d['field'] ?? '') === 'runtime_projection'
        && ($d['classification'] ?? '') === 'runtime'
    )) === 1,
    'RepositoryAuthorization still rejects runtime post_meta'
);
$derivedOptionDocument = OptionState::document([
    'derived_projection' => OptionState::present('must refuse', 'yes'),
]);
$derivedOptionDiagnostics = option_authorization_diagnostics($policy, $derivedOptionDocument);
check(
    count(array_filter($derivedOptionDiagnostics, static fn(array $d): bool =>
        ($d['surface'] ?? '') === 'option'
        && ($d['field'] ?? '') === 'derived_projection'
        && ($d['classification'] ?? '') === 'derived'
    )) === 1,
    'RepositoryAuthorization still rejects derived options'
);
$runtimeOptionDocument = OptionState::document([
    'runtime_projection' => OptionState::present('must refuse', 'yes'),
]);
$runtimeOptionDiagnostics = option_authorization_diagnostics($policy, $runtimeOptionDocument);
check(
    count(array_filter($runtimeOptionDiagnostics, static fn(array $d): bool =>
        ($d['surface'] ?? '') === 'option'
        && ($d['field'] ?? '') === 'runtime_projection'
        && ($d['classification'] ?? '') === 'runtime'
    )) === 1,
    'RepositoryAuthorization still rejects runtime options'
);

$wpdb = new PostFieldFakeWpdb();
$GLOBALS['wpdb'] = $wpdb;
$tokens = new Tokens();

$wpdb->map = [$uuid => 41];
$apply = apply_instance($policy, $tokens);
invoke_private($apply, 'finalize_post', $source, '');
$existingProductUpdate = $wpdb->updates[0]['data'] ?? [];
check(
    !array_key_exists('post_modified', $existingProductUpdate)
        && !array_key_exists('post_modified_gmt', $existingProductUpdate),
    'existing Woo product update preserves target modified timestamps'
);
check(
    ($existingProductUpdate['post_title'] ?? null) === $source['title'],
    'existing Woo product update still writes authored title'
);

$variationUuid = '018f0000-0000-7000-8000-000000000003';
$wpdb->map = [$variationUuid => 42];
$variation = post_front('product_variation', $variationUuid, '2026-08-08 00:00:02');
check(
    post_authorization_diagnostics($policy, $variation) === [],
    'RepositoryAuthorization accepts captured Woo variation derived title and timestamps'
);
invoke_private($apply, 'finalize_post', $variation, '');
$existingVariationUpdate = $wpdb->updates[1]['data'] ?? [];
check(
    !array_key_exists('post_modified', $existingVariationUpdate)
        && !array_key_exists('post_modified_gmt', $existingVariationUpdate)
        && !array_key_exists('post_title', $existingVariationUpdate),
    'existing Woo variation update preserves derived title and timestamps'
);

$articleUuid = '018f0000-0000-7000-8000-000000000004';
$wpdb->map = [$articleUuid => 43];
$article = post_front('article', $articleUuid, '2026-08-08 00:00:03');
invoke_private($apply, 'finalize_post', $article, '');
$existingArticleUpdate = $wpdb->updates[2]['data'] ?? [];
check(
    ($existingArticleUpdate['post_modified'] ?? null) === $article['modified']
        && ($existingArticleUpdate['post_modified_gmt'] ?? null) === $article['modified_gmt'],
    'undeclared post type update still writes authored timestamps'
);

$wpdb->map = [];
$wpdb->inserts = [];
$newProduct = post_front('product', '018f0000-0000-7000-8000-000000000005', '2026-08-08 00:00:04');
check(invoke_private($apply, 'ensure_post_row', $newProduct) === true, 'new Woo product row is inserted');
$insertedPost = $wpdb->inserts[0]['data'] ?? [];
check(
    ($insertedPost['post_modified'] ?? null) === $newProduct['modified']
        && ($insertedPost['post_modified_gmt'] ?? null) === $newProduct['modified_gmt'],
    'new Woo product receives captured timestamp starting values'
);

if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
