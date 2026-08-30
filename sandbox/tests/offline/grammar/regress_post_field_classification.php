<?php
/**
 * Offline regression for manifest-backed post front-matter fields.
 *
 * WooCommerce updates post_modified/post_modified_gmt during target-only
 * product and variation saves, even when the authored product inputs are
 * unchanged. This suite proves the generic field contract without
 * WordPress/Docker:
 *   - only manifest-declared derived timestamps disappear from the hash basis;
 *   - the final raw-post projection preserves object insertion order and
 *     accepts only those declared derived differences;
 *   - complete path-to-semantic-hash projections retain identity, deletion,
 *     body, and non-post strictness;
 *   - existing Woo rows preserve those target timestamps while authored
 *     columns are still written;
 *   - new rows still receive captured starting timestamps;
 *   - undeclared post types retain authored timestamp behavior; and
 *   - unsupported field names/classes fail Policy::load() loudly.
 */

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('WPRISM_SPEC_VERSION')) {
    define('WPRISM_SPEC_VERSION', 3);
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
function wp_cache_delete($key, string $group = ''): bool { return false; }

require __DIR__ . '/../../../../agent/src/Kernel/TransientDbException.php';
require __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require __DIR__ . '/../../../../agent/src/Kernel/Uuid.php';
require __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require __DIR__ . '/../../../../agent/src/Kernel/OrderPreserved.php';
require __DIR__ . '/../../../../agent/src/Kernel/Secrets.php';
require __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require __DIR__ . '/../../../../agent/src/Repository/RepositoryAuthorization.php';
require __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require __DIR__ . '/../../../../agent/src/Grammar/Tokens.php';
require __DIR__ . '/../../../../agent/src/Grammar/Blocks.php';
require __DIR__ . '/../../../../agent/src/Repository/CompiledArtifact.php';
require __DIR__ . '/../../../../agent/src/Apply/Apply.php';
require __DIR__ . '/../policy/manifest_fixtures.php';

use WPrism\Apply;
use WPrism\Canon;
use WPrism\CompiledRepository;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\RepositoryAuthorization;
use WPrism\RepositoryAuthorizationException;
use WPrism\Tokens;

final class PostFieldFakeWpdb {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $term_relationships = 'wp_term_relationships';
    public string $last_error = '';
    public int $insert_id = 100;
    public bool $savepointExists = false;
    /** @var array<string,int> */
    public array $map = [];
    /** @var array<int,array{table:string,data:array,where:array}> */
    public array $updates = [];
    /** @var array<int,array{table:string,data:array}> */
    public array $inserts = [];
    /** @var list<array{meta_id:int,post_id:int,meta_key:string,meta_value:string}> */
    public array $metaRows = [];

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
        if ($sql === 'SELECT @@in_transaction') {
            return '1';
        }
        if ($sql === 'SELECT 1 FROM `wp_postmeta` LIMIT 1') {
            return '1';
        }
        if (str_contains($sql, 'SELECT local_id FROM wp_wprism_map')) {
            preg_match("/uuid = '([^']+)'/", $sql, $m);
            return $this->map[$m[1] ?? ''] ?? null;
        }
        return null;
    }

    public function get_row(string $sql, $format = null) {
        return null;
    }

    public function get_results(string $sql, $format = null): array {
        if (str_contains($sql, 'information_schema.TABLES')) {
            return [['TABLE_NAME' => 'wp_postmeta', 'ENGINE' => 'InnoDB']];
        }
        if (str_starts_with($sql, 'SHOW INDEX FROM `wp_postmeta`')) {
            return [[
                'Key_name' => 'post_id',
                'Column_name' => 'post_id',
                'Seq_in_index' => '1',
                'Sub_part' => null,
                'Non_unique' => '1',
                'Index_type' => 'BTREE',
                'Visible' => 'YES',
            ]];
        }
        if (str_contains($sql, 'FROM `wp_postmeta` FORCE INDEX')) {
            preg_match('/`post_id` = ([0-9]+)/', $sql, $ownerMatch);
            $owner = (int) ($ownerMatch[1] ?? 0);
            $rows = array_values(array_filter(
                $this->metaRows,
                static fn(array $row): bool => $row['post_id'] === $owner
            ));
            if (str_contains($sql, 'AND meta_key =')) {
                preg_match("/meta_key = '((?:''|[^'])*)'/", $sql, $keyMatch);
                $key = str_replace("''", "'", (string) ($keyMatch[1] ?? ''));
                return array_map(
                    static fn(array $row): array => [
                        'meta_id' => (string) $row['meta_id'],
                        'meta_key' => $row['meta_key'],
                    ],
                    array_values(array_filter(
                        $rows,
                        static fn(array $row): bool => strcasecmp($row['meta_key'], $key) === 0
                    ))
                );
            }
            if (str_contains($sql, 'OCTET_LENGTH(meta_key)')) {
                return array_map(static fn(array $row): array => [
                    'meta_id' => (string) $row['meta_id'],
                    'meta_key_bytes' => (string) strlen($row['meta_key']),
                    'meta_value_bytes' => (string) strlen($row['meta_value']),
                ], $rows);
            }
            if (str_contains($sql, 'SHA2(meta_key, 256)')) {
                return array_map(static fn(array $row): array => [
                    'meta_id' => (string) $row['meta_id'],
                    'meta_key_sha256' => hash('sha256', $row['meta_key']),
                    'meta_value_sha256' => hash('sha256', $row['meta_value']),
                ], $rows);
            }
            return array_map(static fn(array $row): array => [
                'meta_id' => (string) $row['meta_id'],
                'meta_key' => $row['meta_key'],
                'meta_value' => $row['meta_value'],
            ], $rows);
        }
        return [];
    }

    public function update(string $table, array $data, array $where, $format = null, $whereFormat = null): int {
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
        return 1;
    }

    public function insert(string $table, array $data, $format = null): int {
        $this->inserts[] = ['table' => $table, 'data' => $data];
        $this->insert_id++;
        if ($table === $this->postmeta) {
            $this->metaRows[] = [
                'meta_id' => $this->insert_id,
                'post_id' => (int) $data['post_id'],
                'meta_key' => (string) $data['meta_key'],
                'meta_value' => (string) $data['meta_value'],
            ];
        }
        return 1;
    }

    public function query(string $sql): int|false {
        if (str_starts_with($sql, 'SAVEPOINT `')) {
            $this->savepointExists = true;
            return 0;
        }
        if (str_starts_with($sql, 'RELEASE SAVEPOINT `')) {
            if (!$this->savepointExists) {
                $this->last_error = 'SAVEPOINT does not exist';
                return false;
            }
            $this->savepointExists = false;
            return 0;
        }
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

function raw_post_content(array $front, string $body): string {
    $json = json_encode(
        $front,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    return "---\n" . $json . "\n---\n" . $body . "\n";
}

function write_raw_post_file(string $path, array $front, string $body = ''): string {
    $content = raw_post_content($front, $body);
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('could not write fixture post file: ' . $path);
    }
    return $content;
}

function ordered_post_projection_hash(Policy $policy, string $path): string {
    $text = file_get_contents($path);
    if ($text === false) {
        throw new RuntimeException('could not read fixture post file: ' . $path);
    }
    if (!str_starts_with($text, "---\n")) {
        throw new RuntimeException('bad post file (missing front matter fence): ' . $path);
    }
    $end = strpos($text, "\n---\n", 3);
    if ($end === false) {
        throw new RuntimeException('bad post file (unterminated front matter): ' . $path);
    }
    $front = json_decode(substr($text, 4, $end - 3), false, 512, JSON_THROW_ON_ERROR);
    if (!$front instanceof stdClass) {
        throw new RuntimeException('bad post file (front matter must be an object): ' . $path);
    }
    $body = substr($text, $end + 5);
    if (str_ends_with($body, "\n")) {
        $body = substr($body, 0, -1);
    }
    $postType = (string) ($front->type ?? '');
    foreach (array_keys(get_object_vars($front)) as $key) {
        if ($policy->field_class($postType, (string) $key) === 'derived') {
            unset($front->{$key});
        }
    }
    $json = json_encode(
        $front,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    return hash('sha256', "---\n" . $json . "\n---\n" . $body . "\n");
}

/** @return array<int,array<string,mixed>> */
function post_authorization_diagnostics(Policy $policy, array $front, string $body = ''): array {
    $entity = [
        'type' => 'post',
        'post_type' => (string) $front['type'],
        'path' => 'posts/' . $front['type'] . '/' . $front['uuid'] . '--fixture.md',
        'hash' => hash('sha256', Canon::post_hash_basis($front, $body, $policy)),
        'content' => Canon::post_file($front, $body),
        'data' => $front,
        'body' => $body,
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

function apply_instance(Policy $policy, Tokens $tokens, string $repositoryRoot): \WPrism\PostMaterializer {
    $fieldMaterializer = new \WPrism\ApplyFieldMaterializer($policy, $tokens);
    $fieldMaterializer->begin_authored_transaction();
    \WPrism\CacheInvalidationTransaction::begin();
    $compiled = (new ReflectionClass(CompiledRepository::class))->newInstanceWithoutConstructor();
    return new \WPrism\PostMaterializer(
        $policy,
        $tokens,
        $fieldMaterializer,
        new \WPrism\RelationshipMaterializer($policy, $fieldMaterializer),
        new \WPrism\AttachmentMaterializer($policy, $fieldMaterializer, $compiled, $repositoryRoot)
    );
}

$root = dirname(__DIR__, 4);
$fixtureDir = sys_get_temp_dir() . '/wprism_regress_post_fields_' . bin2hex(random_bytes(4));
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
    'spec_version' => WPRISM_SPEC_VERSION,
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
    'spec_version' => WPRISM_SPEC_VERSION,
    'post_types' => ['product' => ['fields' => ['slug' => ['class' => 'derived']]]],
]);
write_manifest($fixtureDir, 'bad-field-class', [
    'name' => 'bad-field-class',
    'spec_version' => WPRISM_SPEC_VERSION,
    'post_types' => ['product' => ['fields' => ['modified' => ['class' => 'runtime']]]],
]);
write_manifest($fixtureDir, 'json-body-clearance', [
    'name' => 'json-body-clearance',
    'spec_version' => WPRISM_SPEC_VERSION,
    'engine_features' => ['spec-window/v1', 'structured-body-refs/v1'],
    'post_types' => ['form' => ['class' => 'authored', 'body' => 'json']],
    'body_refs' => [
        'form' => [
            'json_refs' => [['path' => '$.target', 'kind' => 'post']],
        ],
    ],
]);
$fixtureLibrary = manifest_fixture_adapter_library($fixtureDir);

$policy = Policy::load(null, ['woo-fields'], adapterLibrary: $fixtureLibrary);
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

$sourceLibrary = \WPrism\AdapterLibrary::fromSourceTree($root);
$realWooPolicy = Policy::load(null, ['woocommerce'], adapterLibrary: $sourceLibrary);
check($realWooPolicy->field_class('product', 'modified') === 'derived', 'shipped Woo manifest declares product.modified derived');
check($realWooPolicy->field_class('product', 'modified_gmt') === 'derived', 'shipped Woo manifest declares product.modified_gmt derived');
check($realWooPolicy->field_class('product_variation', 'modified') === 'derived', 'shipped Woo manifest declares variation.modified derived');
check($realWooPolicy->field_class('product_variation', 'modified_gmt') === 'derived', 'shipped Woo manifest declares variation.modified_gmt derived');
check_throws(
    fn() => Policy::load(null, ['bad-field-name'], adapterLibrary: $fixtureLibrary),
    'post_types.product.fields.slug',
    'unsupported post field name is rejected at manifest load'
);
check_throws(
    fn() => Policy::load(null, ['bad-field-class'], adapterLibrary: $fixtureLibrary),
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

$sourceRawPath = $fixtureDir . '/source.md';
$sourceRaw = write_raw_post_file($sourceRawPath, $source);
$timestampRawPath = $fixtureDir . '/timestamp.md';
$timestampRaw = write_raw_post_file($timestampRawPath, $timestampOnly);
$sourceOrderedHash = ordered_post_projection_hash($policy, $sourceRawPath);
check(
    $sourceRaw !== $timestampRaw,
    'ordered post projection fixture changes honest raw bytes for a derived timestamp'
);
check(
    $sourceOrderedHash === ordered_post_projection_hash($policy, $timestampRawPath),
    'ordered post projection accepts only declared derived timestamp differences'
);

$titleRawPath = $fixtureDir . '/title.md';
write_raw_post_file($titleRawPath, $titleChanged);
check(
    $sourceOrderedHash !== ordered_post_projection_hash($policy, $titleRawPath),
    'ordered post projection rejects an authored title difference'
);

$bodyRawPath = $fixtureDir . '/body.md';
write_raw_post_file($bodyRawPath, $source, 'authored body changed');
check(
    $sourceOrderedHash !== ordered_post_projection_hash($policy, $bodyRawPath),
    'ordered post projection rejects an authored body difference'
);

$movedProductProjection = semantic_path_hash_projection([[
    'path' => 'posts/product/' . $uuid . '--moved.md',
    'hash' => $sourceOrderedHash,
]]);
check(
    semantic_path_hash_projection([[
        'path' => $productPath,
        'hash' => $sourceOrderedHash,
    ]]) !== $movedProductProjection,
    'ordered post projection rejects an identity-bearing path difference'
);

$genericRawPath = $fixtureDir . '/generic.md';
$genericTimestampRawPath = $fixtureDir . '/generic-timestamp.md';
write_raw_post_file($genericRawPath, $generic);
write_raw_post_file($genericTimestampRawPath, $genericTimestampChanged);
check(
    ordered_post_projection_hash($policy, $genericRawPath)
        !== ordered_post_projection_hash($policy, $genericTimestampRawPath),
    'ordered post projection rejects undeclared post-type timestamp differences'
);

$variationProjection = post_front(
    'product_variation',
    '018f0000-0000-7000-8000-000000000006',
    '2026-08-08 00:00:05'
);
$variationDerivedProjection = $variationProjection;
$variationDerivedProjection['title'] = 'derived variation title';
$variationDerivedProjection['modified'] = '2030-01-01 00:00:05';
$variationDerivedProjection['modified_gmt'] = '2030-01-01 00:00:05';
$variationProjectionPath = $fixtureDir . '/variation.md';
$variationDerivedProjectionPath = $fixtureDir . '/variation-derived.md';
write_raw_post_file($variationProjectionPath, $variationProjection);
write_raw_post_file($variationDerivedProjectionPath, $variationDerivedProjection);
check(
    ordered_post_projection_hash($policy, $variationProjectionPath)
        === ordered_post_projection_hash($policy, $variationDerivedProjectionPath),
    'ordered post projection accepts product variation derived title and timestamps'
);

$metaChanged = $source;
$metaChanged['meta'] = ['derived_projection' => 'still authored for this comparator'];
$metaChangedPath = $fixtureDir . '/meta.md';
write_raw_post_file($metaChangedPath, $metaChanged);
check(
    $sourceOrderedHash !== ordered_post_projection_hash($policy, $metaChangedPath),
    'ordered post projection keeps derived post_meta strict'
);

$orderedFrontA = $source;
$orderedFrontA['meta'] = [
    '_product_attributes' => [
        'zzz_attribute' => [
            'name' => 'pa_size',
            'value' => 'Large',
            'is_visible' => 1,
            'is_variation' => 1,
            'is_taxonomy' => 1,
        ],
        'aaa_attribute' => [
            'is_taxonomy' => 1,
            'is_visible' => 1,
            'name' => 'pa_color',
            'value' => 'Blue',
            'is_variation' => 1,
        ],
    ],
    'ordinary_meta' => 'unchanged',
];
$orderedFrontB = $source;
$orderedFrontB['meta'] = [
    '_product_attributes' => [
        'aaa_attribute' => [
            'is_variation' => 1,
            'value' => 'Blue',
            'name' => 'pa_color',
            'is_visible' => 1,
            'is_taxonomy' => 1,
        ],
        'zzz_attribute' => [
            'is_taxonomy' => 1,
            'is_variation' => 1,
            'is_visible' => 1,
            'value' => 'Large',
            'name' => 'pa_size',
        ],
    ],
    'ordinary_meta' => 'unchanged',
];
$orderedAPath = $fixtureDir . '/ordered-a.md';
$orderedBPath = $fixtureDir . '/ordered-b.md';
$orderedAContent = write_raw_post_file($orderedAPath, $orderedFrontA);
$orderedBContent = write_raw_post_file($orderedBPath, $orderedFrontB);
check(
    $orderedAContent !== $orderedBContent,
    'nested _product_attributes insertion-order fixture changes raw post bytes'
);
check(
    Canon::post_hash_basis($orderedFrontA, '', $policy) === Canon::post_hash_basis($orderedFrontB, '', $policy),
    'canonical post hash basis reproduces the nested metadata order collision'
);
check(
    ordered_post_projection_hash($policy, $orderedAPath)
        !== ordered_post_projection_hash($policy, $orderedBPath),
    'ordered post projection rejects nested _product_attributes insertion-order changes'
);

$deletionEntity = [
    'path' => 'deletions/' . $uuid . '.json',
    'hash' => hash('sha256', Canon::encode([
        'format' => 'wprism-deletion/v1',
        'kind' => 'post',
        'type' => 'product',
        'uuid' => $uuid,
    ])),
];
check(
    semantic_path_hash_projection([$deletionEntity]) !== semantic_path_hash_projection([]),
    'complete semantic projection rejects an added deletion intent'
);
$changedDeletionEntity = $deletionEntity;
$changedDeletionEntity['hash'] = hash('sha256', Canon::encode([
    'format' => 'wprism-deletion/v1',
    'kind' => 'post',
    'type' => 'product',
    'uuid' => '018f0000-0000-7000-8000-000000000099',
]));
check(
    semantic_path_hash_projection([$deletionEntity])
        !== semantic_path_hash_projection([$changedDeletionEntity]),
    'complete semantic projection rejects a deletion intent content difference'
);

check(
    post_authorization_diagnostics($policy, $source) === [],
    'RepositoryAuthorization accepts captured Woo product derived timestamps'
);
$secretBodyDiagnostics = post_authorization_diagnostics(
    $policy,
    $source,
    'Hand-edited deployment note: api_key=CredentialShape-2026-Blocked'
);
check(
    count(array_filter($secretBodyDiagnostics, static fn(array $d): bool =>
        ($d['code'] ?? '') === 'repository_secret_not_allowed'
        && ($d['surface'] ?? '') === 'post_field'
        && ($d['field'] ?? '') === 'body'
    )) === 1,
    'RepositoryAuthorization prevents a Git edit from bypassing canonical-content secret clearance'
);
$piiBodyDiagnostics = post_authorization_diagnostics(
    $policy,
    $source,
    'Hand-edited private contact is person@example.test'
);
check(
    count(array_filter($piiBodyDiagnostics, static fn(array $d): bool =>
        ($d['code'] ?? '') === 'repository_pii_not_allowed'
        && ($d['surface'] ?? '') === 'post_field'
        && ($d['field'] ?? '') === 'body'
    )) === 1,
    'RepositoryAuthorization prevents a Git edit from bypassing canonical-content PII clearance'
);

$acfPolicy = Policy::load(null, ['acf'], adapterLibrary: $sourceLibrary);
$acfPolicy->site = ['policy' => [
    'post_types' => ['acf-field'],
    'taxonomies' => [],
    'post_meta' => [],
    'options' => [],
]];
$serializedFront = post_front('acf-field', '018f0000-0000-7000-8000-000000000091', '2026-08-08 00:00:01');
$serializedDiagnostics = post_authorization_diagnostics(
    $acfPolicy,
    $serializedFront,
    serialize(['integration' => ['Authorization' => 'GeneratedValue-2026-Blocked']])
);
check(
    count(array_filter($serializedDiagnostics, static fn(array $d): bool =>
        ($d['code'] ?? '') === 'repository_secret_not_allowed'
        && ($d['field'] ?? '') === 'body'
    )) === 1,
    'RepositoryAuthorization opens serialized bodies before credential-key clearance'
);
$serializedSecretKeyDiagnostics = post_authorization_diagnostics(
    $acfPolicy,
    $serializedFront,
    serialize(['integration' => ['sk_live_REPOSITORYKEY1234567890' => 'enabled']])
);
check(
    count(array_filter($serializedSecretKeyDiagnostics, static fn(array $d): bool =>
        ($d['code'] ?? '') === 'repository_secret_not_allowed'
        && ($d['field'] ?? '') === 'body'
    )) === 1,
    'RepositoryAuthorization opens serialized bodies and refuses hard secrets in associative keys'
);
$serializedPiiKeyDiagnostics = post_authorization_diagnostics(
    $acfPolicy,
    $serializedFront,
    serialize(['audience' => ['alice@example.test' => 'enabled']])
);
check(
    count(array_filter($serializedPiiKeyDiagnostics, static fn(array $d): bool =>
        ($d['code'] ?? '') === 'repository_pii_not_allowed'
        && ($d['field'] ?? '') === 'body'
    )) === 1,
    'RepositoryAuthorization opens serialized bodies and refuses PII in associative keys'
);

$jsonPolicy = Policy::load(null, ['json-body-clearance'], adapterLibrary: $fixtureLibrary);
$jsonPolicy->site = ['policy' => [
    'post_types' => ['form'],
    'taxonomies' => [],
    'post_meta' => [],
    'options' => [],
]];
$jsonFront = post_front('form', '018f0000-0000-7000-8000-000000000092', '2026-08-08 00:00:01');
$jsonDiagnostics = post_authorization_diagnostics(
    $jsonPolicy,
    $jsonFront,
    json_encode([
        'target' => '{{post:018f0000-0000-7000-8000-000000000001}}',
        'customerProfile' => ['firstName' => 'Private Customer'],
    ], JSON_THROW_ON_ERROR)
);
check(
    count(array_filter($jsonDiagnostics, static fn(array $d): bool =>
        ($d['code'] ?? '') === 'repository_pii_not_allowed'
        && ($d['field'] ?? '') === 'body'
    )) === 1,
    'RepositoryAuthorization opens structured JSON bodies before personal-data-key clearance'
);
$jsonSecretKeyDiagnostics = post_authorization_diagnostics(
    $jsonPolicy,
    $jsonFront,
    json_encode([
        'target' => '{{post:018f0000-0000-7000-8000-000000000001}}',
        'integration' => ['sk_live_REPOSITORYJSONKEY123456' => 'enabled'],
    ], JSON_THROW_ON_ERROR)
);
check(
    count(array_filter($jsonSecretKeyDiagnostics, static fn(array $d): bool =>
        ($d['code'] ?? '') === 'repository_secret_not_allowed'
        && ($d['field'] ?? '') === 'body'
    )) === 1,
    'RepositoryAuthorization opens structured JSON bodies and refuses hard secrets in associative keys'
);
$jsonPiiKeyDiagnostics = post_authorization_diagnostics(
    $jsonPolicy,
    $jsonFront,
    json_encode([
        'target' => '{{post:018f0000-0000-7000-8000-000000000001}}',
        'audience' => ['alice@example.test' => 'enabled'],
    ], JSON_THROW_ON_ERROR)
);
check(
    count(array_filter($jsonPiiKeyDiagnostics, static fn(array $d): bool =>
        ($d['code'] ?? '') === 'repository_pii_not_allowed'
        && ($d['field'] ?? '') === 'body'
    )) === 1,
    'RepositoryAuthorization opens structured JSON bodies and refuses PII in associative keys'
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
$apply = apply_instance($policy, $tokens, $fixtureDir);
$materializerWarnings = [];
$apply->finalize_post($source, '', null, $materializerWarnings, []);
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
$apply->finalize_post($variation, '', null, $materializerWarnings, []);
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
$apply->finalize_post($article, '', null, $materializerWarnings, []);
$existingArticleUpdate = $wpdb->updates[2]['data'] ?? [];
check(
    ($existingArticleUpdate['post_modified'] ?? null) === $article['modified']
        && ($existingArticleUpdate['post_modified_gmt'] ?? null) === $article['modified_gmt'],
    'undeclared post type update still writes authored timestamps'
);

$wpdb->map = [];
$wpdb->inserts = [];
$newProduct = post_front('product', '018f0000-0000-7000-8000-000000000005', '2026-08-08 00:00:04');
// issue #3347 slice 10: ensure_post_row() moved from Apply onto
// PostMaterializer (Apply keeps only the facade). Fetched via Apply's own
// post_materializer() factory rather than hand-built, so this test's
// PostMaterializer is wired with the exact same Tokens instance the real
// facade would use.
check($apply->ensure_post_row($newProduct) === true, 'new Woo product row is inserted');
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
