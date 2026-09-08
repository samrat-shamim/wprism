<?php
/**
 * Full product-path recovery proof for a post-commit apply failure.
 *
 * This is intentionally not an AttachmentMaterializer unit fixture: the
 * public ApplyRequestCoordinator::apply() path owns planning, capture,
 * authored commit, the post-authored lease boundary, native rebuild, and
 * durable cleanup. The first request fails only at the real
 * apply-rebuild heartbeat; the second request is the same public request
 * against the durable target and must recover the exact pending work.
 */
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/wprism-full-apply-' . bin2hex(random_bytes(6));
define('ABSPATH', __DIR__ . '/../../../../');
define('WP_CONTENT_DIR', $tmp . '/target/wp-content');
define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
define('WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins');

require_once __DIR__ . '/../../support/wp_cli_child_process_fake.php';

if (!class_exists('WP_Hook')) {
    final class WP_Hook {
        /** @var array<int,array<string,array{function:callable,accepted_args:int}>> */
        public array $callbacks = [];
    }
}
if (!class_exists('WP_CLI')) {
    final class WP_CLI {
        /** @var list<string> */
        public static array $commands = [];

        use \WPrismTest\WpCliChildRuntime;

        public static function accepts_verify_command(string $command): bool {
            $repo = (string) ($GLOBALS['full_apply_repo'] ?? '');
            $separator = ' -- ';
            $memory = " -d 'memory_limit=512M'";
            $prefix = 'exec ' . escapeshellarg(PHP_BINARY) . $memory . ' -r ';
            $separatorPosition = strpos($command, $separator);
            if ($repo === '' || !str_starts_with($command, $prefix) || $separatorPosition === false) return false;
            $inner = self::decode_shell_arg(substr($command, $separatorPosition + strlen($separator)));
            $argv0 = $GLOBALS['argv'][0] ?? null;
            $innerPrefix = is_string($argv0)
                ? escapeshellarg(PHP_BINARY) . $memory . ' ' . escapeshellarg($argv0) . '  '
                : '';
            if ($inner === null || $innerPrefix === '' || !str_starts_with($inner, $innerPrefix)) return false;
            $verify = substr($inner, strlen($innerPrefix));
            return preg_match(
                '/^wprism verify-canonical --repo=' . preg_quote(escapeshellarg($repo), '/')
                    . ' --expected-artifact=[a-f0-9]{64}'
                    . " --compiled='[^']+'"
                    . " --policy-snapshot='[^']+' --format=json$/D",
                $verify
            ) === 1;
        }

        private static function decode_shell_arg(string $encoded): ?string {
            if ($encoded === '' || $encoded[0] !== "'") return null;
            $out = '';
            $length = strlen($encoded);
            for ($index = 1; $index < $length; $index++) {
                if (substr($encoded, $index, 4) === "'\\''") {
                    $out .= "'";
                    $index += 3;
                    continue;
                }
                if ($encoded[$index] === "'") {
                    return trim(substr($encoded, $index + 1)) === '' ? $out : null;
                }
                $out .= $encoded[$index];
            }
            return null;
        }

        public static function runcommand(string $command, array $options): object {
            self::$commands[] = $command;
            if (!self::accepts_verify_command($command)) {
                return (object) ['return_code' => 126, 'stdout' => '', 'stderr' => 'unknown child command'];
            }
            return (object) [
                'return_code' => 1,
                'stdout' => '',
                'stderr' => 'offline verifier child has no shared target database; convergence refused',
            ];
        }
    }
}

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../support/wp-block-parser-stub.php';
require_once __DIR__ . '/../../support/wp-shortcode-stub.php';
require_once dirname(__DIR__, 4) . '/adapter-packages/polylang/fixtures/polylang_language_factory_double.php';

// AttachmentNativeMetadataGenerator audits WordPress's native callback
// registry directly. This target has no plugin bootstrap, so the exact
// shipped topology is the empty no-language Polylang registry.
$GLOBALS['wp_filter'] = [];
$GLOBALS['full_apply_metadata_calls'] = 0;

if (!class_exists('WP_Image_Editor_GD')) {
    class WP_Image_Editor_GD {
        public function __construct(private readonly string $file) {}
        public function resize(int $width, int $height, bool $crop): bool { return true; }
        public function save(string $path, string $mime): array {
            if (!copy($this->file, $path)) throw new RuntimeException('fixture editor save failed');
            return ['path' => $path, 'file' => basename($path), 'width' => 1, 'height' => 1,
                'mime-type' => $mime, 'filesize' => filesize($path)];
        }
    }
}
if (!function_exists('wp_get_image_editor')) {
    function wp_get_image_editor(string $file): object { return new WP_Image_Editor_GD($file); }
}
if (!function_exists('image_resize_dimensions')) {
    // The one-pixel source cannot be enlarged: this is the native no-op arm,
    // not evidence that this fixture implements GD's resizing algorithm.
    function image_resize_dimensions(int $oldWidth, int $oldHeight, int $width, int $height, bool $crop): bool { return false; }
}
if (!function_exists('wp_get_registered_image_subsizes')) {
    function wp_get_registered_image_subsizes(): array {
        return ['thumbnail' => ['width' => 1, 'height' => 1, 'crop' => false]];
    }
}
if (!function_exists('wp_generate_attachment_metadata')) {
    function wp_generate_attachment_metadata(int $id, string $file): array {
        ++$GLOBALS['full_apply_metadata_calls'];
        $derivative = dirname($file) . '/' . pathinfo($file, PATHINFO_FILENAME) . '-1x1.png';
        if (!copy($file, $derivative)) {
            throw new RuntimeException('offline core could not write its staged attachment derivative');
        }
        return [
            'width' => 1, 'height' => 1, 'file' => basename($file),
            'sizes' => [
                'thumbnail' => [
                    'file' => basename($derivative), 'width' => 1, 'height' => 1,
                    'mime-type' => 'image/png', 'filesize' => filesize($derivative),
                ],
            ],
        ];
    }
}
if (!function_exists('get_post')) {
    function get_post(int $id): object {
        return (object) ['ID' => $id, 'post_type' => 'attachment', 'post_mime_type' => 'image/png'];
    }
}
if (!function_exists('PLL')) {
    function PLL(): object {
        static $runtime;
        return $runtime ??= (object) [
            'model' => new class {
                public function has_languages(): bool { return false; }
                /** @return list<object> */
                public function get_languages_list(): array { return []; }
            },
            'options' => new class {
                /** @var array<string,mixed> */
                private array $values = [
                    'force_lang' => 1, 'domains' => [], 'hide_default' => true,
                    'rewrite' => true, 'redirect_lang' => false, 'browser' => false,
                    'media_support' => true, 'post_types' => [], 'taxonomies' => [],
                    'sync' => [], 'default_lang' => '', 'nav_menus' => [],
                    'first_activation' => false, 'previous_version' => '', 'version' => '3.8.6',
                ];

                public function get(string $key): mixed { return $this->values[$key] ?? null; }

                /** @return array{type:string,properties:array<string,array{default:mixed,type:string}>,additionalProperties:bool} */
                public function get_schema(): array {
                    $properties = [];
                    foreach (array_keys($this->values) as $key) {
                        $properties[$key] = ['default' => $this->values[$key], 'type' => 'fixture'];
                    }
                    return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
                }
            },
        ];
    }
}
if (!function_exists('get_taxonomies')) {
    function get_taxonomies(array $args = [], string $output = 'names'): array { return []; }
}
if (!function_exists('get_taxonomy')) {
    function get_taxonomy(string $taxonomy): object { return (object) ['hierarchical' => false]; }
}
if (!function_exists('get_post_types')) {
    function get_post_types(array $args = [], string $output = 'names'): array { return ['attachment', 'page']; }
}
if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook, array $args = []): int { return 0; }
}
if (!function_exists('taxonomy_exists')) {
    function taxonomy_exists(string $taxonomy): bool { return false; }
}
// Deploy's code-mismatch probe normally loads wp-admin/includes/plugin.php;
// this offline target has no WordPress checkout, so provide the exact empty
// installed-code answer needed by the real ApplyRequestCoordinator path.
if (!function_exists('validate_plugin')) {
    function validate_plugin(string $plugin): bool { return true; }
}
if (!function_exists('get_plugins')) {
    /** @return array<string,array<string,string>> */
    function get_plugins(): array { return []; }
}

require_once __DIR__ . '/../../../../agent/wprism.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyRequestCoordinator.php';

use WPrism\ApplyRequestCoordinator;
use WPrism\ApplyServices;
use WPrism\ApplyWorkset;
use WPrism\AuthoredTransactionRequest;
use WPrism\Canon;
use WPrism\DatabaseQueryIsolation;
use WPrism\DeleteGuardLockCoordinator;
use WPrism\DeleteGuardReferenceScanner;
use WPrism\DeletionAuthority;
use WPrism\Ledger;
use WPrism\Policy;
use WPrism\PromotionLock;
use WPrism\RepositoryCompiler;
use WPrism\Snapshot;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

/** @return array<string,mixed> */
function full_apply_attachment_post(string $uuid): array {
    return [
        'author' => 'user:admin', 'comment_status' => 'open',
        'date' => '2026-08-25 00:00:00', 'date_gmt' => '2026-08-25 00:00:00',
        'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
        'modified' => '2026-08-25 00:00:00',
        'modified_gmt' => '2026-08-25 00:00:00', 'parent' => null,
        'ping_status' => 'closed', 'slug' => 'recovery-note', 'status' => 'inherit',
        'terms' => (object) [], 'term_orders' => (object) [], 'title' => 'Recovery note', 'type' => 'attachment',
        'uuid' => $uuid,
        'alt' => '', 'file' => '2026/08/recovery-note.png', 'media' => '',
        'mime' => 'image/png',
    ];
}

/** @return array<string,string> */
function full_apply_attachment_core_columns(): array {
    return [
        'posts' => 'ID,post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,post_modified,post_modified_gmt,post_parent,menu_order,post_type,post_mime_type',
        'postmeta' => 'meta_id,post_id,meta_key,meta_value',
        'terms' => 'term_id,name,slug',
        'term_taxonomy' => 'term_taxonomy_id,term_id,taxonomy,description,parent',
        'term_relationships' => 'object_id,term_taxonomy_id,term_order',
        'termmeta' => 'meta_id,term_id,meta_key,meta_value',
        'options' => 'option_id,option_name,option_value,autoload',
        'users' => 'ID,user_login',
        'usermeta' => 'umeta_id,user_id,meta_key,meta_value',
    ];
}

$repo = $tmp . '/repo';
mkdir($repo . '/state/posts/attachment', 0777, true);
mkdir($repo . '/media', 0777, true);
mkdir(WP_CONTENT_DIR . '/themes/fixture-theme', 0777, true);
file_put_contents(
    WP_CONTENT_DIR . '/themes/fixture-theme/style.css',
    "/*\nTheme Name: Full Apply Fixture\nVersion: 1.0.0\n*/\n"
);
$repo = (string) realpath($repo);
$GLOBALS['full_apply_repo'] = $repo;
register_shutdown_function(static function () use ($tmp): void {
    if (!is_dir($tmp)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($tmp);
});

$repoRoot = dirname(__DIR__, 4);
$coordinatorSource = (string) file_get_contents($repoRoot . '/agent/src/Apply/ApplyRequestCoordinator.php');
$cliSource = (string) file_get_contents($repoRoot . '/agent/src/Command/Cli.php');
$captureSource = (string) file_get_contents($repoRoot . '/agent/src/Capture/CapturePublicationWorkflow.php');
$deploySource = (string) file_get_contents($repoRoot . '/agent/src/Promotion/Deploy.php');
wprism_check(
    str_contains($coordinatorSource, '$library = $opts[\'adapter_library\'] ?? null;')
        && substr_count($cliSource, 'self::internal_adapter_library($assoc)') === 5,
    'capture/plan/apply/deploy/lint retain one object-only AdapterLibrary handoff for hermetic engine evidence'
);
wprism_check(
    substr_count($captureSource, 'adapterLibrary: $adapterLibrary') === 4
        && substr_count($deploySource, 'adapterLibrary: $adapterLibrary') === 2,
    'capture and deploy reuse the injected library for every pre-lock and locked policy proof'
);
wprism_check(
    preg_match('/^\s*\* \[--adapter[-_]library/m', $cliSource) !== 1,
    'the in-process AdapterLibrary handoff is not a registered target-operator path flag'
);

$uuid = '11111111-1111-4111-8111-111111111111';
$bytes = "full-apply recovery bytes\n";
$bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
$mediaName = hash('sha256', $bytes) . '.png';
file_put_contents($repo . '/media/' . $mediaName, $bytes);
$post = full_apply_attachment_post($uuid);
$post['media'] = $mediaName;
file_put_contents($repo . '/site.wprism.json', Canon::encode([
    'manifests' => ['core', 'polylang'],
    'policy' => [
        'post_types' => ['attachment'], 'taxonomies' => [], 'options' => (object) [],
        'post_meta' => [
            '_wp_attached_file' => ['class' => 'managed'],
            '_wp_attachment_metadata' => ['class' => 'derived'],
            '_wprism_uuid' => ['class' => 'managed'],
            '_wp_attachment_image_alt' => ['class' => 'managed'],
        ], 'term_meta' => (object) [],
    ], 'spec_version' => 2,
]));
file_put_contents(
    $repo . '/state/posts/attachment/' . $uuid . '--recovery-note.md',
    Canon::post_file($post, '')
);

$adapterLibrary = \WPrism\AdapterLibrary::fromSourceTree($repoRoot);
$policy = Policy::load(
    $repo,
    adapterLibrary: $adapterLibrary
);
$compiled = RepositoryCompiler::compile($repo, $policy);
$artifact = $tmp . '/compiled.json';
$compiled->write($artifact);

$store = WpStore::reset()->seedOptions([
    'home' => 'https://full-apply.example.test',
    'siteurl' => 'https://full-apply.example.test',
    'admin_email' => 'admin@full-apply.example.test',
    'active_plugins' => [],
    'stylesheet' => 'fixture-theme',
    'template' => 'fixture-theme',
]);
$store->ensureUploadDir();
$wpdb = FakeWpdb::install()->enableInformationSchema()->enableFullApplySqlExtensions();
foreach (full_apply_attachment_core_columns() as $table => $columns) {
    $wpdb->seedTable('wp_' . $table, [])->setColumns(
        'wp_' . $table,
        array_fill_keys(explode(',', $columns), 'longtext')
    )->setTableEngine('wp_' . $table, 'InnoDB');
}
$wpdb->seedTable('wp_options', [
    ['option_id' => 1, 'option_name' => 'active_plugins', 'option_value' => 'a:0:{}', 'autoload' => 'yes'],
    ['option_id' => 2, 'option_name' => 'stylesheet', 'option_value' => 'fixture-theme', 'autoload' => 'yes'],
    ['option_id' => 3, 'option_name' => 'template', 'option_value' => 'fixture-theme', 'autoload' => 'yes'],
]);
$wpdb->seedTable('wp_users', [['ID' => 1, 'user_login' => 'admin']]);
$index = static function (string $name, int $unique, int $seq, string $column, ?int $subPart = null): array {
    return [
        'Key_name' => $name, 'Non_unique' => $unique, 'Seq_in_index' => $seq,
        'Column_name' => $column, 'Sub_part' => $subPart, 'Index_type' => 'BTREE',
    ];
};
foreach ([
    'wp_posts' => [$index('PRIMARY', 0, 1, 'ID'), $index('post_name', 1, 1, 'post_name'), $index('post_type', 1, 1, 'post_type')],
    'wp_postmeta' => [
        $index('PRIMARY', 0, 1, 'meta_id'), $index('post_id', 1, 1, 'post_id'),
        $index('meta_key', 1, 1, 'meta_key', 191),
    ],
    'wp_terms' => [$index('PRIMARY', 0, 1, 'term_id'), $index('slug', 0, 1, 'slug')],
    'wp_term_taxonomy' => [$index('PRIMARY', 0, 1, 'term_taxonomy_id'), $index('term_id_taxonomy', 0, 1, 'term_id')],
    'wp_term_relationships' => [$index('PRIMARY', 0, 1, 'object_id')],
    'wp_termmeta' => [$index('PRIMARY', 0, 1, 'meta_id'), $index('term_id', 1, 1, 'term_id')],
    'wp_options' => [$index('PRIMARY', 0, 1, 'option_id'), $index('option_name', 0, 1, 'option_name')],
    'wp_users' => [$index('PRIMARY', 0, 1, 'ID'), $index('user_login', 0, 1, 'user_login')],
    'wp_usermeta' => [$index('PRIMARY', 0, 1, 'umeta_id'), $index('user_id', 1, 1, 'user_id')],
    'wp_wprism_kv' => [$index('PRIMARY', 0, 1, 'k')],
    'wp_wprism_state' => [$index('PRIMARY', 0, 1, 'uuid')],
] as $table => $indexes) {
    $wpdb->setIndexes($table, $indexes);
}
$wpdb->setIndexes('wp_wprism_map', [
    $index('PRIMARY', 0, 1, 'uuid'),
    $index('PRIMARY', 0, 2, 'id_kind'),
    $index('id_kind_local_id', 0, 1, 'id_kind'),
    $index('id_kind_local_id', 0, 2, 'local_id'),
]);
foreach (['wp_wprism_map', 'wp_wprism_state', 'wp_wprism_kv', 'wp_wprism_journal'] as $table) {
    $wpdb->seedTable($table, [])->setTableEngine($table, 'InnoDB');
}
$wpdb->setColumns('wp_wprism_map', ['uuid' => 'varchar(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned'])
    ->setColumns('wp_wprism_state', ['uuid' => 'varchar(64)', 'entity_type' => 'varchar(64)', 'content_hash' => 'varchar(64)'])
    ->setColumns('wp_wprism_kv', ['k' => 'varchar(191)', 'v' => 'longtext'])
    ->setColumns('wp_wprism_journal', ['id' => 'bigint', 't' => 'datetime', 'op' => 'varchar(32)', 'tbl' => 'varchar(64)', 'item' => 'varchar(191)', 'surface' => 'varchar(32)', 'actor' => 'bigint', 'caps' => 'text', 'hook' => 'text', 'proposal' => 'varchar(32)'])
    ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])
    ->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id'])
    ->setUniqueKey('wp_wprism_state', ['uuid'])
    ->setUniqueKey('wp_wprism_kv', ['k']);

// Fail once at the actual post-authored lease renewal, after the DB/filesystem
// intent is durable and before native rebuild can consume it.
$failedHeartbeat = false;
$wpdb->onQuery(static function (string $sql, string $method) use (&$failedHeartbeat): ?string {
    if (!$failedHeartbeat && str_contains($sql, 'apply-rebuild')) {
        $failedHeartbeat = true;
        return 'injected apply-rebuild heartbeat failure';
    }
    return null;
});

$firstFailure = null;
try {
    ApplyRequestCoordinator::apply($repo, [
        'compiled' => $artifact,
        'adapter_library' => $adapterLibrary,
    ]);
} catch (Throwable $failure) {
    $firstFailure = $failure;
}
wprism_check($firstFailure !== null, 'first public apply fails at the injected post-authored apply-rebuild lease boundary');
if ($firstFailure !== null) wprism_check_detail(get_class($firstFailure) . ': ' . $firstFailure->getMessage());
if ($firstFailure?->getPrevious() !== null) {
    wprism_check_detail('previous: ' . get_class($firstFailure->getPrevious()) . ': ' . $firstFailure->getPrevious()->getMessage());
}
wprism_check($failedHeartbeat, 'the failure seam observed the real apply-rebuild heartbeat');
wprism_check(count($wpdb->rows('wp_posts')) === 1, 'first apply committed the authored attachment row before the rebuild boundary');
wprism_check(count($wpdb->rows('wp_wprism_map')) === 1, 'first apply sealed the attachment identity map before the rebuild boundary');
wprism_check(Ledger::kv_get('apply_in_progress') !== null, 'first apply left the durable incomplete-apply marker for recovery');
$attachmentMarkers = array_values(array_filter(
    $wpdb->rows('wp_wprism_kv'),
    static fn(array $row): bool => str_starts_with((string) ($row['k'] ?? ''), 'attachment_fs:')
));
wprism_check(count($attachmentMarkers) === 1, 'first apply left exactly one durable attachment filesystem marker');
if (count($attachmentMarkers) === 1) {
    wprism_check(
        preg_match('/^attachment_fs:[0-9a-f]{32}$/D', (string) $attachmentMarkers[0]['k']) === 1
            && str_starts_with((string) $attachmentMarkers[0]['v'], 'wprism-attachment-filesystem-transaction/v1:'),
        'attachment marker has the exact bounded key/value format'
    );
}
$journalPath = $repo . '/.wprism/attachment-filesystem/current/journal.json';
wprism_check(is_file($journalPath), 'first apply retained the exact private attachment journal path');
if (is_file($journalPath)) {
    $journal = Canon::decode((string) file_get_contents($journalPath));
    wprism_check(is_array($journal) && ($journal['phase'] ?? null) === 'originals_published', 'first apply journal records originals-published before native rebuild');
}
$originalPath = $store->uploadBaseDir . '/2026/08/recovery-note.png';
wprism_check(is_file($originalPath) && hash_equals(hash('sha256', $bytes), hash_file('sha256', $originalPath)), 'first apply published the exact original bytes');
$uploadFiles = [];
if (is_dir($store->uploadBaseDir)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($store->uploadBaseDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) if ($file->isFile()) $uploadFiles[] = substr($file->getPathname(), strlen($store->uploadBaseDir) + 1);
}
sort($uploadFiles, SORT_STRING);
wprism_check($uploadFiles === ['2026/08/recovery-note.png'], 'first apply has no derivative or metadata files before native rebuild');
$authoredMetaKeys = array_values(array_unique(array_map(static fn(array $row): string => (string) ($row['meta_key'] ?? ''), $wpdb->rows('wp_postmeta'))));
sort($authoredMetaKeys, SORT_STRING);
wprism_check($authoredMetaKeys === ['_wp_attached_file', '_wp_attachment_image_alt', '_wprism_uuid'], 'first apply committed only authored attachment metadata');
wprism_check(Ledger::kv_get('applied_revision') === null, 'first apply did not advance the applied revision before native rebuild');
$secondFailure = null;
try {
    ApplyRequestCoordinator::apply($repo, [
        'compiled' => $artifact,
        'adapter_library' => $adapterLibrary,
    ]);
} catch (Throwable $failure) {
    $secondFailure = $failure;
}
wprism_check($secondFailure !== null, 'second identical public apply reaches native rebuild then refuses unavailable cross-process convergence');
if ($secondFailure !== null) wprism_check_detail(get_class($secondFailure) . ': ' . $secondFailure->getMessage());
$childCommands = WP_CLI::$commands;
wprism_check(count($childCommands) === 1, 'native convergence launched exactly one bounded child command');
if (count($childCommands) === 1) {
    wprism_check(
        WP_CLI::accepts_verify_command($childCommands[0]),
        'bounded child command has the exact verify-canonical argv grammar and no extras'
    );
    $extra = WP_CLI::runcommand($childCommands[0] . ' --unexpected', []);
    wprism_check(($extra->return_code ?? null) === 126, 'bounded child command refuses an unexpected extra argv token');
    $missingFormat = str_replace('--format=json', '--format=xml', $childCommands[0]);
    $malformed = WP_CLI::runcommand($missingFormat, []);
    wprism_check(($malformed->return_code ?? null) === 126, 'bounded child command refuses malformed argv missing format');
}
wprism_check(count(array_filter($wpdb->rows('wp_postmeta'), static fn(array $row): bool => ($row['meta_key'] ?? '') === '_wp_attachment_metadata')) === 1, 'retry settles exactly one native attachment metadata row before convergence refusal');
wprism_check(count(array_filter($wpdb->rows('wp_postmeta'), static fn(array $row): bool => ($row['meta_key'] ?? '') === '_wp_attached_file')) === 1, 'retry preserves exactly one managed attached-file sidecar');
$metadataRows = array_values(array_filter($wpdb->rows('wp_postmeta'), static fn(array $row): bool => ($row['meta_key'] ?? '') === '_wp_attachment_metadata'));
if (count($metadataRows) === 1) {
    $metadata = maybe_unserialize($metadataRows[0]['meta_value'] ?? null);
    wprism_check(
        is_array($metadata)
            && ($metadata['file'] ?? null) === '2026/08/recovery-note.png'
            && ($metadata['sizes']['thumbnail']['file'] ?? null) === 'recovery-note-1x1.png'
            && ($metadata['sizes']['thumbnail']['mime-type'] ?? null) === 'image/png',
        'retry persists exact native metadata and derivative identity'
    );
}
wprism_check(($GLOBALS['full_apply_metadata_calls'] ?? 0) === 1, 'retry invokes the native metadata generator exactly once');
wprism_check(is_file($store->uploadBaseDir . '/2026/08/recovery-note-1x1.png'), 'retry publishes the exact generated derivative');
wprism_check(Ledger::kv_get('apply_in_progress') !== null, 'convergence refusal retains the durable incomplete-apply marker');
wprism_check(Ledger::kv_get('applied_revision') === null, 'convergence refusal does not advance applied revision');
wprism_check(count(array_filter($wpdb->rows('wp_wprism_kv'), static fn(array $row): bool => str_starts_with((string) ($row['k'] ?? ''), 'attachment_fs:'))) === 0, 'native rebuild clears the attachment filesystem marker before convergence verification');

// Exercise the deletion profile through Apply's real composition root. A
// required runtime table must refuse before START, while an optional runtime
// table stays presence-only; a stale locked witness followed by a retry on
// the same service graph proves the coordinator's finally callback reset it.
$deletionPolicy = clone $policy;
$coreManifestFound = false;
foreach ($deletionPolicy->manifests as &$manifest) {
    if (($manifest['name'] ?? null) !== 'core') continue;
    $coreManifestFound = true;
    $manifest['tables']['profile_required_refs'] = ['class' => 'runtime'];
    $manifest['tables']['profile_optional_refs'] = ['class' => 'runtime'];
    $manifest['deletions']['post:post']['guards'] = [
        [
            'column' => 'post_id',
            'id_kind' => 'post',
            'reason' => 'required composition references',
            'source_id_kind' => 'post',
            'source_pk' => 'ref_id',
            'table' => 'profile_required_refs',
        ],
        [
            'column' => 'post_id',
            'id_kind' => 'post',
            'reason' => 'optional composition references',
            'table' => 'profile_optional_refs',
            'table_absence' => 'empty',
        ],
    ];
}
unset($manifest);
wprism_check($coreManifestFound, 'composition fixture found the pinned core deletion declaration');
$deletionCompiled = RepositoryCompiler::compile($repo, $deletionPolicy);

$compositionReflection = new ReflectionClass(ApplyRequestCoordinator::class);
$compositionApply = $compositionReflection->newInstanceWithoutConstructor();
$compositionConstructor = $compositionReflection->getConstructor();
if ($compositionConstructor === null) throw new RuntimeException('ApplyRequestCoordinator constructor is absent');
$compositionConstructor->invoke($compositionApply, $repo, $deletionPolicy, $deletionCompiled);

$writerVerifications = 0;
$writerWitness = [
    'active' => true,
    'allow_deletes' => true,
    'artifact_hash' => $deletionCompiled->artifact_hash(),
    'exclusion_state' => 'held',
    'format' => 'wprism-scoped-promotion-witness/v1',
    'generation' => 7,
    'ok' => true,
    'owner' => 'apply-composition-offline',
    'receipt_format' => 'wprism-scoped-promotion-receipt/v1',
    'receipt_id' => str_repeat('r', 32),
    'receipt_payload_sha256' => str_repeat('b', 64),
    'recovery_ready' => true,
    'scope_hash' => str_repeat('c', 64),
    'signing_key_id' => 'apply-composition-test',
    'state' => 'promoting',
    'target_id' => str_repeat('t', 32),
    'terminal' => false,
];
$compositionGuard = new DeleteGuardLockCoordinator(
    $deletionPolicy,
    new DeleteGuardReferenceScanner($deletionPolicy),
    Snapshot::row_tables($deletionPolicy),
    static function (array $binding) use (&$writerVerifications): array {
        ++$writerVerifications;
        return $binding;
    }
);
$compositionGuard->bind_writer_exclusion($writerWitness);
$compositionReflection->getProperty('deleteGuardCoordinator')->setValue(
    $compositionApply,
    $compositionGuard
);
$compositionServices = $compositionReflection->getProperty('services')->getValue($compositionApply);
if (!$compositionServices instanceof ApplyServices) {
    throw new RuntimeException('ApplyRequestCoordinator did not compose ApplyServices');
}
$compositionExecutor = $compositionServices->authored_transaction_executor();

$deleteUuid = '22222222-2222-4222-8222-222222222222';
$wpdb->seedTable('wp_posts', array_merge($wpdb->rows('wp_posts'), [[
    'ID' => 77,
    'post_parent' => 0,
    'post_type' => 'post',
]]));
$wpdb->setIndexes('wp_posts', [
    $index('PRIMARY', 0, 1, 'ID'),
    $index('post_name', 1, 1, 'post_name'),
    $index('post_parent', 1, 1, 'post_parent'),
    $index('post_type', 1, 1, 'post_type'),
]);
$wpdb->seedTable('wp_wprism_map', array_merge($wpdb->rows('wp_wprism_map'), [[
    'uuid' => $deleteUuid,
    'entity_type' => 'post',
    'id_kind' => 'post',
    'local_id' => 77,
]]));
$deleteRow = [
    'deletion_kind' => 'post',
    'deletion_type' => 'post',
    'type' => 'post',
    'uuid' => $deleteUuid,
    'guard_witnesses' => [
        '0' => hash('sha256', Canon::encode([])),
        '1' => hash('sha256', Canon::encode([
            'format' => 'wprism-delete-guard-witness/v2',
            'rows' => [],
            'state' => 'absent',
            'table' => 'wp_profile_optional_refs',
        ])),
    ],
];
$compositionRequestFor = static function (array $row) use ($deleteUuid): AuthoredTransactionRequest {
    return new AuthoredTransactionRequest(
        workset: new ApplyWorkset(
            plan: ['adopt' => [], 'deleted' => []],
            tree: [],
            work: [],
            deleteWork: [$row],
            deleteUuids: [$deleteUuid => true],
            guardRepairUuids: [],
            compiledDeletions: [
                $deleteUuid => ['data' => ['kind' => 'post', 'type' => 'post']],
            ]
        ),
        deletionAuthority: new DeletionAuthority(
            execute: true,
            withDeletes: true,
            forceReferenced: false
        ),
        scoped: false,
        scopeContract: null,
        performTransaction: true,
        defaultAuthor: null,
        commitScopedAuthoring: null,
        rollbackScopedAuthoring: null
    );
};
$compositionRequest = $compositionRequestFor($deleteRow);

$promotionOwner = 'apply-composition-profile';
$promotionArtifact = $deletionCompiled->artifact_hash();
$compositionReflection->getProperty('promotionOwner')->setValue($compositionApply, $promotionOwner);
$compositionReflection->getProperty('promotionArtifact')->setValue($compositionApply, $promotionArtifact);
$promotionAcquired = false;
$profileFailure = null;
$profileAdmissionFailure = null;
$resetFailure = null;
$compositionFailure = null;
$compositionResult = null;
$compositionQueries = [];
$compositionWarnings = [];
try {
    PromotionLock::acquire_apply_preflight($promotionOwner, $promotionArtifact);
    $promotionAcquired = true;

    $wpdb->resetLog();
    try {
        $compositionExecutor->execute($compositionRequest, $compositionWarnings);
    } catch (Throwable $failure) {
        $profileFailure = $failure;
    }
    $refusedQueries = $wpdb->queries();
    wprism_check(
        $profileFailure !== null
            && str_contains($profileFailure->getMessage(), "required guard table 'wp_profile_required_refs' is absent"),
        'required guard absence refuses the composed apply transaction'
    );
    wprism_check(
        !in_array('START TRANSACTION', $refusedQueries, true)
            && !in_array('START TRANSACTION WITH CONSISTENT SNAPSHOT', $refusedQueries, true),
        'required guard absence is resolved before any authored START'
    );
    wprism_check(
        !DatabaseQueryIsolation::is_active()
            && count(array_filter($wpdb->rows('wp_posts'), static fn(array $row): bool => ($row['ID'] ?? null) === 77)) === 1,
        'pre-START refusal restores query isolation and leaves the target row untouched'
    );

    $wpdb->seedTable('wp_profile_required_refs', [])
        ->setColumns('wp_profile_required_refs', [
            'ref_id' => 'bigint unsigned',
            'post_id' => 'bigint unsigned',
        ])
        ->setIndexes('wp_profile_required_refs', [
            $index('PRIMARY', 0, 1, 'ref_id'),
            $index('post_id', 1, 1, 'post_id'),
        ])
        ->setTableEngine('wp_profile_required_refs', 'InnoDB');

    // The selection callback now holds a topology snapshot, but Db can still
    // refuse while admitting that profile after START. AuthoredTransactionExecutor
    // has not set its own transactionStarted flag in that interval, so the
    // unconditional end callback is the only reset path for a reused graph.
    $wpdb->setTableEngine('wp_profile_required_refs', 'MyISAM');
    $wpdb->resetLog();
    try {
        $compositionExecutor->execute($compositionRequest, $compositionWarnings);
    } catch (Throwable $failure) {
        $profileAdmissionFailure = $failure;
    }
    $profileAdmissionQueries = $wpdb->queries();
    wprism_check(
        $profileAdmissionFailure !== null
            && str_contains($profileAdmissionFailure->getMessage(), 'InnoDB required')
            && in_array('START TRANSACTION', $profileAdmissionQueries, true)
            && in_array('ROLLBACK AND NO CHAIN NO RELEASE', $profileAdmissionQueries, true)
            && !DatabaseQueryIsolation::is_active(),
        'profile-admission failure after START is settled by Db before authored transaction ownership begins'
    );
    $wpdb->setTableEngine('wp_profile_required_refs', 'InnoDB');

    // A stale planning witness fails only after the profile has been selected
    // and START has succeeded. The following correct request on this exact
    // executor is therefore load-bearing proof of the finally/reset callback.
    $staleDeleteRow = $deleteRow;
    $staleDeleteRow['guard_witnesses']['0'] = str_repeat('0', 64);
    $wpdb->resetLog();
    try {
        $compositionExecutor->execute(
            $compositionRequestFor($staleDeleteRow),
            $compositionWarnings
        );
    } catch (Throwable $failure) {
        $resetFailure = $failure;
    }
    $resetQueries = $wpdb->queries();
    wprism_check(
        $resetFailure !== null
            && str_contains($resetFailure->getMessage(), 'deletion guard witness changed after planning'),
        'stale guard evidence refuses after the composed transaction selected its profile'
    );
    wprism_check(
        in_array('START TRANSACTION', $resetQueries, true)
            && in_array('ROLLBACK AND NO CHAIN NO RELEASE', $resetQueries, true)
            && !DatabaseQueryIsolation::is_active()
            && count(array_filter($wpdb->rows('wp_posts'), static fn(array $row): bool => ($row['ID'] ?? null) === 77)) === 1,
        'the in-transaction refusal rolls back and clears its query/profile boundary before reuse'
    );

    $wpdb->resetLog();
    try {
        $compositionResult = $compositionExecutor->execute($compositionRequest, $compositionWarnings);
    } catch (Throwable $failure) {
        $compositionFailure = $failure;
    }
    $compositionQueries = $wpdb->queries();
} finally {
    // The shared fake intentionally keeps PromotionLease's JSON-qualified
    // release mutation outside its generic SQL grammar. This process-local
    // fixture only needs to relinquish the advisory fence after exercising
    // the real heartbeat callback; its isolated store dies with this leaf.
    if ($promotionAcquired) \WPrism\ProcessFence::release();
}

if ($compositionFailure !== null) {
    wprism_check_detail(get_class($compositionFailure) . ': ' . $compositionFailure->getMessage());
    if ($compositionFailure->getPrevious() !== null) {
        wprism_check_detail('previous: ' . get_class($compositionFailure->getPrevious()) . ': ' . $compositionFailure->getPrevious()->getMessage());
    }
}
wprism_check(
    $compositionFailure === null
        && is_array($compositionResult)
        && $compositionResult['attachment_ids'] === [],
    'the same composed services/executor graph succeeds after its failed transaction is reset'
);
wprism_check(
    !DatabaseQueryIsolation::is_active()
        && count(array_filter($wpdb->rows('wp_posts'), static fn(array $row): bool => ($row['ID'] ?? null) === 77)) === 0
        && in_array("deleted post $deleteUuid", $compositionWarnings, true),
    'successful composed execution deletes the row and restores the query-filter boundary'
);

$queryIndex = static function (array $queries, callable $matches, int $after = -1): ?int {
    foreach ($queries as $offset => $query) {
        if ($offset > $after && $matches($query)) return $offset;
    }
    return null;
};
$requiredProfileAt = $queryIndex(
    $compositionQueries,
    static fn(string $query): bool => $query === 'SELECT 1 FROM `wp_profile_required_refs` LIMIT 0'
);
$startAt = $queryIndex(
    $compositionQueries,
    static fn(string $query): bool => $query === 'START TRANSACTION'
);
$requiredAdmissionAt = $queryIndex(
    $compositionQueries,
    static fn(string $query): bool => $query === 'SELECT 1 FROM `wp_profile_required_refs` LIMIT 0',
    $startAt ?? -1
);
$requiredRecensusAt = $queryIndex(
    $compositionQueries,
    static fn(string $query): bool => $query === 'SELECT 1 FROM `wp_profile_required_refs` LIMIT 0',
    $requiredAdmissionAt ?? -1
);
$requiredLockAt = $queryIndex(
    $compositionQueries,
    static fn(string $query): bool => str_contains($query, 'FROM `wp_profile_required_refs` FORCE INDEX (`post_id`)')
        && str_ends_with($query, ' FOR UPDATE'),
    $requiredRecensusAt ?? -1
);
$postDeleteAt = $queryIndex(
    $compositionQueries,
    static fn(string $query): bool => str_starts_with($query, 'DELETE FROM `wp_posts`'),
    $requiredLockAt ?? -1
);
$childLockAt = $queryIndex(
    $compositionQueries,
    static fn(string $query): bool => $query === 'SELECT ID, post_type FROM wp_posts FORCE INDEX (`post_parent`) '
        . 'WHERE post_parent = 77 ORDER BY ID ASC LIMIT 100001 FOR UPDATE',
    $requiredLockAt ?? -1
);
$commitAt = $queryIndex(
    $compositionQueries,
    static fn(string $query): bool => $query === 'COMMIT AND NO CHAIN NO RELEASE',
    $postDeleteAt ?? -1
);
$optionalPresenceOffsets = array_keys(array_filter(
    $compositionQueries,
    static fn(string $query): bool => $query === 'SELECT 1 FROM `wp_profile_optional_refs` LIMIT 0'
));
$optionalProfileAt = $optionalPresenceOffsets === [] ? null : min($optionalPresenceOffsets);
$optionalCommitAt = $optionalPresenceOffsets === [] ? null : max($optionalPresenceOffsets);
wprism_check(
    $requiredProfileAt !== null
        && $startAt !== null
        && $requiredAdmissionAt !== null
        && $requiredRecensusAt !== null
        && $requiredLockAt !== null
        && $childLockAt !== null
        && $postDeleteAt !== null
        && $optionalProfileAt !== null
        && $optionalCommitAt !== null
        && $commitAt !== null
        && $optionalProfileAt < $startAt
        && $requiredProfileAt < $startAt
        && $startAt < $requiredAdmissionAt
        && $requiredAdmissionAt < $requiredRecensusAt
        && $requiredRecensusAt < $requiredLockAt
        && $requiredLockAt < $childLockAt
        && $childLockAt < $postDeleteAt
        && $postDeleteAt < $optionalCommitAt
        && $optionalCommitAt < $commitAt,
    'composed apply profiles before START, admits the required table, locks guards, recenses optional absence, then commits'
);
wprism_check(
    !in_array('SELECT 1 FROM `wp_profile_optional_refs` LIMIT 1', $compositionQueries, true)
        && $writerVerifications >= 6,
    'absent optional storage is never admitted as a readable table and external deletion authority spans every destructive frontier'
);

// A private core library introduces the engine declaration under test. It is
// never installed or used as plugin capability evidence; the public request
// still performs real policy, compiler, plan and transaction admission.
$mediaLibraryRoot = $tmp . '/media-library';
mkdir($mediaLibraryRoot . '/adapter-packages', 0700, true);
foreach (['profiles.json', 'capabilities/platform.json', 'capabilities/adapter-authorities.json',
    'core/manifest.json', 'core/disposition.json'] as $relative) {
    $destination = $mediaLibraryRoot . '/platform/adapter-library/' . $relative;
    if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0700, true);
    copy($repoRoot . '/platform/adapter-library/' . $relative, $destination);
}
$mediaManifestPath = $mediaLibraryRoot . '/platform/adapter-library/core/manifest.json';
$mediaManifest = Canon::decode(Canon::read_file($mediaManifestPath));
$mediaManifest['spec_version'] = 3;
$mediaManifest['engine_features'] = array_values(array_unique([
    ...($mediaManifest['engine_features'] ?? []), 'spec-window/v1', 'block-attribute-values/v1', 'block-media-derivatives/v1',
]));
sort($mediaManifest['engine_features'], SORT_STRING);
$mediaManifest['block_values']['fixture/image'] = ['image' => ['class' => 'authored',
    'json_refs' => [['path' => '$.id', 'kind' => 'post']]]];
$mediaManifest['block_media_derivatives']['fixture/image'] = [[
    'attachment' => '$.image.id', 'url' => '$.image.url', 'width' => '$.width', 'height' => '$.height',
    'crop' => true, 'filename' => 'requested-dimensions', 'dimension_cast' => 'integer',
]];
Canon::write_file($mediaManifestPath, Canon::encode($mediaManifest));
$mediaLibrary = \WPrism\AdapterLibrary::fromSourceTree($mediaLibraryRoot);
$mediaRepo = $tmp . '/media-repo';
foreach (['state/posts/attachment', 'state/posts/page', 'media'] as $dir) mkdir($mediaRepo . '/' . $dir, 0700, true);
$mediaRepo = (string) realpath($mediaRepo);
$mediaSite = Canon::decode(Canon::read_file($repo . '/site.wprism.json'));
$mediaSite['manifests'] = ['core'];
$mediaSite['policy']['post_types'] = ['attachment', 'page'];
Canon::write_file($mediaRepo . '/site.wprism.json', Canon::encode($mediaSite));
file_put_contents($mediaRepo . '/media/' . $mediaName, $bytes);
$mediaPost = full_apply_attachment_post($uuid);
$mediaPost['file'] = '2026/08/recipe-note.png';
$mediaPost['media'] = $mediaName;
Canon::write_file($mediaRepo . '/state/posts/attachment/' . $uuid . '--recovery-note.md', Canon::post_file($mediaPost, ''));
$pageUuid = '22222222-2222-4222-8222-222222222222';
$pagePost = array_replace($mediaPost, ['uuid' => $pageUuid, 'type' => 'page', 'status' => 'publish', 'slug' => 'crop-consumer']);
foreach (['alt', 'file', 'media', 'mime'] as $key) unset($pagePost[$key]);
$mediaBody = '<!-- wp:fixture/image ' . json_encode(['image' => ['id' => '{{post:' . $uuid . '}}',
    'url' => '{{uploads}}/2026/08/recipe-note-333x211.png'], 'width' => 333, 'height' => 211], JSON_UNESCAPED_SLASHES) . ' /-->';
Canon::write_file($mediaRepo . '/state/posts/page/' . $pageUuid . '--crop-consumer.md', Canon::post_file($pagePost, $mediaBody));
$mediaPolicy = Policy::load($mediaRepo, adapterLibrary: $mediaLibrary);
$mediaCompiled = RepositoryCompiler::compile($mediaRepo, $mediaPolicy);
$mediaArtifact = $tmp . '/media-compiled.json';
$mediaCompiled->write($mediaArtifact);
$wpdb->onQuery(null);
foreach (['wp_posts', 'wp_postmeta', 'wp_wprism_map', 'wp_wprism_state', 'wp_wprism_kv', 'wp_wprism_journal'] as $table) {
    $wpdb->seedTable($table, []);
}
$GLOBALS['full_apply_repo'] = $mediaRepo;
$mediaFailure = null;
try {
    ApplyRequestCoordinator::apply($mediaRepo, ['compiled' => $mediaArtifact, 'adapter_library' => $mediaLibrary]);
} catch (Throwable $failure) {
    $mediaFailure = $failure;
    wprism_check_detail('derivative full apply: ' . get_class($failure) . ': ' . $failure->getMessage());
    if ($failure->getPrevious() !== null) wprism_check_detail('previous: ' . $failure->getPrevious()->getMessage());
}
wprism_check(count($wpdb->rows('wp_posts')) === 2, 'derivative public apply commits both authored entities');
wprism_check(is_file($store->uploadBaseDir . '/2026/08/recipe-note-333x211.png'),
    'derivative public apply publishes the content-selected requested filename');
wprism_check($mediaFailure !== null && str_contains($mediaFailure->getMessage(), 'offline verifier child has no shared target database'),
    'derivative public apply completes native work before its explicit unavailable-verifier refusal');
wprism_check(!is_file($mediaRepo . '/.wprism/attachment-filesystem/current/journal.json'),
    'derivative public apply settles its native metadata and filesystem journal');

// Establish the next test's baseline from actual canonical capture bytes.
// This is fixture seeding, not a claim that the unavailable fresh-process
// verifier accepted the prior request. Only the page changes from this state.
$mediaSnapshot = \WPrism\Capture::snapshot_read_only($mediaRepo, false, $mediaCompiled, $mediaPolicy);
foreach ($mediaCompiled->tree() as $id => $entity) {
    if ($entity['hash'] !== ($mediaSnapshot[$id]['hash'] ?? null)) {
        [$observedFront, $observedBody] = Canon::parse_post_file($mediaSnapshot[$id]['content']);
        foreach (array_unique([...array_keys($entity['data']), ...array_keys($observedFront)]) as $key) {
            if (($entity['data'][$key] ?? null) !== ($observedFront[$key] ?? null)) {
                wprism_check_detail($id . ':' . $key . ': ' . json_encode([$entity['data'][$key] ?? null, $observedFront[$key] ?? null]));
            }
        }
        if ($entity['body'] !== $observedBody) wprism_check_detail('body: ' . json_encode([$entity['body'], $observedBody]));
    }
    wprism_check_same($entity['hash'], $mediaSnapshot[$id]['hash'] ?? null,
        "first derivative apply recaptures the exact compiled entity $id");
    Ledger::set_state_hash($id, 'post:' . $entity['data']['type'], $mediaSnapshot[$id]['hash']);
}
$wpdb->seedTable('wp_wprism_kv', []);
$attachmentId = Ledger::id_for($uuid, Ledger::KIND_POST);
$originalRow = array_values(array_filter($wpdb->rows('wp_posts'), static fn(array $row): bool => (int) $row['ID'] === $attachmentId));
$authoredSidecars = static fn(): array => array_values(array_filter($GLOBALS['wpdb']->rows('wp_postmeta'),
    static fn(array $row): bool => (int) $row['post_id'] === $attachmentId && $row['meta_key'] !== '_wp_attachment_metadata'));
$originalSidecars = $authoredSidecars();
$generationCalls = $GLOBALS['full_apply_metadata_calls'];
$nextBody = str_replace(['333x211', '"width":333'], ['444x211', '"width":444'], $mediaBody);
Canon::write_file($mediaRepo . '/state/posts/page/' . $pageUuid . '--crop-consumer.md', Canon::post_file($pagePost, $nextBody));
$nextCompiled = RepositoryCompiler::compile($mediaRepo, $mediaPolicy);
$nextCompiled->write($mediaArtifact);
$mediaOptions = ['compiled' => $mediaArtifact, 'adapter_library' => $mediaLibrary];
$mediaPlan = ApplyRequestCoordinator::plan($mediaRepo, $mediaOptions);
wprism_check(in_array($uuid, array_column($mediaPlan['unchanged'], 'uuid'), true)
    && in_array($pageUuid, array_column($mediaPlan['update'], 'uuid'), true),
    'content-only derivative plan keeps its attachment authored state unchanged and selects only the page update');
$pageId = Ledger::id_for($pageUuid, Ledger::KIND_POST);
$contender = (new FakeWpdb())->setConnectionId(2)->shareDatabaseStateWith($wpdb);
$consumerLockProbe = null;
$wpdb->onQuery(static function (string $sql) use (&$consumerLockProbe, $contender, $mediaRepo, $pageId): ?string {
    if ($consumerLockProbe !== null || !str_contains($sql, 'SELECT option_id, option_name FROM wp_options FORCE INDEX')) return null;
    $journal = $mediaRepo . '/.wprism/attachment-filesystem/current/journal.json';
    if (!is_file($journal) || (Canon::decode(Canon::read_file($journal))['phase'] ?? null) !== 'metadata_generated') return null;
    // This callback is the simulated second native connection after every
    // consumer post range is locked and before publication observes content.
    // Only blocked writes are modelled; this fake does not implement MVCC.
    $blocked = static function (callable $write) use ($contender): bool {
        return $write() === false && $contender->last_error === 'simulated InnoDB row lock wait timeout';
    };
    $consumerLockProbe = [
        $blocked(static fn() => $contender->insert('wp_posts', ['ID' => 900, 'post_type' => 'page'])),
        $blocked(static fn() => $contender->update('wp_posts', ['post_content' => 'foreign consumer write'], ['ID' => $pageId])),
        $blocked(static fn() => $contender->update('wp_posts', ['post_type' => 'unselected_type'], ['ID' => $pageId])),
        $blocked(static fn() => $contender->delete('wp_posts', ['ID' => $pageId])),
    ];
    return null;
});
$nextFailure = null;
try {
    ApplyRequestCoordinator::apply($mediaRepo, $mediaOptions);
} catch (Throwable $failure) {
    $nextFailure = $failure;
    wprism_check_detail('content-only derivative apply: ' . $failure->getMessage());
}
wprism_check($nextFailure !== null && str_contains($nextFailure->getMessage(), 'offline verifier child has no shared target database'),
    'content-only public apply finishes native work and reaches the explicit final-verifier boundary');
wprism_check_same($generationCalls + 1, $GLOBALS['full_apply_metadata_calls'],
    'a page-only edit regenerates its unchanged attachment exactly once');
wprism_check_same($originalRow, array_values(array_filter($wpdb->rows('wp_posts'),
    static fn(array $row): bool => (int) $row['ID'] === $attachmentId)), 'content-only crop work preserves the complete native attachment row');
wprism_check_same($originalSidecars, $authoredSidecars(), 'content-only crop work preserves exact authored attachment sidecar rows');
wprism_check(is_file($store->uploadBaseDir . '/2026/08/recipe-note-444x211.png')
    && !file_exists($store->uploadBaseDir . '/2026/08/recipe-note-333x211.png'),
    'content-only public apply publishes the new crop and removes only the stale previously owned crop');
wprism_check_same(hash('sha256', $bytes), hash_file('sha256', $store->uploadBaseDir . '/2026/08/recipe-note.png'),
    'content-only derivative work preserves its original payload bytes');
$wpdb->onQuery(null);
wprism_check_same([true, true, true, true], $consumerLockProbe,
    'public derivative publication holds consumer post ranges against a new consumer, body change, type escape and deletion');
wprism_check_same(1, $contender->insert('wp_posts', ['ID' => 900, 'post_type' => 'page']),
    'public apply releases its consumer range locks after the verifier refusal');
$contender->delete('wp_posts', ['ID' => 900]);
$nextSnapshot = \WPrism\Capture::snapshot_read_only($mediaRepo, false, $nextCompiled, $mediaPolicy);
foreach ($nextCompiled->tree() as $id => $entity) {
    wprism_check_same($entity['hash'], $nextSnapshot[$id]['hash'] ?? null,
        "content-only derivative apply recaptures the exact compiled entity $id");
    Ledger::set_state_hash($id, 'post:' . $entity['data']['type'], $nextSnapshot[$id]['hash']);
}
$wpdb->seedTable('wp_wprism_kv', []);
Canon::write_file($mediaRepo . '/state/posts/page/' . $pageUuid . '--crop-consumer.md', Canon::post_file($pagePost, ''));
$removedCompiled = RepositoryCompiler::compile($mediaRepo, $mediaPolicy);
$removedCompiled->write($mediaArtifact);
$failedMetadataCommit = false;
$wpdb->onQuery(static function (string $sql) use (&$failedMetadataCommit, $mediaRepo): ?string {
    if ($failedMetadataCommit || $sql !== 'COMMIT AND NO CHAIN NO RELEASE') return null;
    $journal = $mediaRepo . '/.wprism/attachment-filesystem/current/journal.json';
    if (!is_file($journal) || (Canon::decode(Canon::read_file($journal))['phase'] ?? null) !== 'metadata_committing') return null;
    $failedMetadataCommit = true;
    return 'injected native metadata commit failure';
});
$removalFailure = null;
try {
    ApplyRequestCoordinator::apply($mediaRepo, $mediaOptions);
} catch (Throwable $failure) {
    $removalFailure = $failure;
    wprism_check_detail('last-consumer commit failure: ' . $failure->getMessage());
}
wprism_check($failedMetadataCommit && $removalFailure !== null, 'last-consumer removal reaches the injected native metadata COMMIT failure');
wprism_check(is_file($store->uploadBaseDir . '/2026/08/recipe-note-444x211.png'),
    'failed native metadata commit retains the previously owned crop even after its last consumer was authored away');
$wpdb->onQuery(null);
$removalRetryFailure = null;
try {
    ApplyRequestCoordinator::apply($mediaRepo, $mediaOptions);
} catch (Throwable $failure) {
    $removalRetryFailure = $failure;
    wprism_check_detail('last-consumer retry: ' . $failure->getMessage());
}
wprism_check($removalRetryFailure !== null && str_contains($removalRetryFailure->getMessage(), 'offline verifier child has no shared target database'),
    'identical public retry recovers last-consumer cleanup before the explicit final-verifier boundary');
wprism_check(!file_exists($store->uploadBaseDir . '/2026/08/recipe-note-444x211.png')
    && is_file($store->uploadBaseDir . '/2026/08/recipe-note-1x1.png'),
    'last-consumer recovery removes the stale custom crop and retains the core native size');

if (wprism_check_failed() > 0) {
    exit(1);
}
