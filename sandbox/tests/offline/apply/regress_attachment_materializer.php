<?php
declare(strict_types=1);

namespace Duo {
    /** Rollback-only transaction double used by the real metadata generator. */
    final class Db {
        public static int $starts = 0;
        public static int $rollbacks = 0;

        public static function start_repeatable_read(string $purpose): void { ++self::$starts; }
        public static function rollback(string $purpose): void {
            ++self::$rollbacks;
            if (isset($GLOBALS['wpdb'])
                && is_object($GLOBALS['wpdb'])
                && property_exists($GLOBALS['wpdb'], 'savepointExists')) {
                $GLOBALS['wpdb']->savepointExists = false;
            }
        }
    }
}

namespace Elementor\Modules\PageTemplates {
    final class Module {
        public function filter_update_meta(mixed $check, mixed $id, mixed $key): never {
            ++$GLOBALS['duo_attachment_adapter_callback_calls'];
            throw new \RuntimeException('Elementor page-template callback must be quarantined');
        }
    }
}

namespace Elementor\Core\Files\File_Types {
    final class Svg {
        public function set_svg_meta_data(mixed $metadata, mixed $id): never {
            ++$GLOBALS['duo_attachment_adapter_callback_calls'];
            throw new \RuntimeException('Elementor SVG callback must be quarantined');
        }
    }
}

namespace TEC\Common\Integrations\Harbor {
    final class PUE {
        public function filter_pre_get_option(mixed $value, mixed $option, mixed $default): never {
            ++$GLOBALS['duo_attachment_adapter_callback_calls'];
            throw new \RuntimeException('TEC Harbor callback must never enter the supported free-plugin topology');
        }
    }
}

namespace {
    final class PLL_Sync_Post_Metas {
        public function can_synchronize_metadata(mixed $check, mixed $id, mixed $key): mixed {
            ++$GLOBALS['duo_attachment_adapter_callback_calls'];
            throw new \RuntimeException('Polylang post-meta guard must be quarantined');
        }

        public function update_metadata(
            mixed $check,
            mixed $id,
            mixed $key,
            mixed $value,
            mixed $prior
        ): mixed {
            ++$GLOBALS['duo_attachment_adapter_callback_calls'];
            throw new \RuntimeException('Polylang post-meta witness must be quarantined');
        }
    }
}

namespace {
    final class DuoTestPolylangModel {
        public function has_languages(): bool {
            return (bool) ($GLOBALS['duo_polylang_has_languages'] ?? false);
        }
    }
    final class DuoTestPolylangRuntime {
        public object $model;
        public function __construct() {
            $this->model = new DuoTestPolylangModel();
        }
    }
    $GLOBALS['duo_polylang_runtime'] = new DuoTestPolylangRuntime();
    function PLL(): object {
        return $GLOBALS['duo_polylang_runtime'];
    }

    if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
    /**
     * Product-path regression for the durable attachment filesystem/native
     * metadata boundary. The former extraction-only suite could stay green
     * while originals published before COMMIT, prior bytes lived below the
     * web-served uploads tree, prefix-neighbor files were deleted, native
     * filters ran, and every crash phase wedged the next apply.
     */
    final class WP_Hook {
        /** @var array<int,array<string,array{function:callable,accepted_args:int}>> */
        public array $callbacks = [];
    }
    final class WP_Image_Editor_GD {}
    final class WC_Regenerate_Images {
        public static function add_uncropped_metadata(mixed $metadata): never {
            ++$GLOBALS['duo_attachment_adapter_callback_calls'];
            throw new \RuntimeException('WooCommerce metadata callback must be quarantined');
        }
    }
    final class WC_Post_Data {
        public static function update_post_metadata(
            mixed $check,
            mixed $id,
            mixed $key,
            mixed $value,
            mixed $prior
        ): never {
            ++$GLOBALS['duo_attachment_adapter_callback_calls'];
            throw new \RuntimeException('WooCommerce post-meta callback must be quarantined');
        }
    }
    final class WPSEO_Meta {
        public static function remove_meta_if_default(
            mixed $check,
            mixed $id,
            mixed $key,
            mixed $value,
            mixed $prior
        ): never {
            ++$GLOBALS['duo_attachment_adapter_callback_calls'];
            throw new \RuntimeException('Yoast post-meta callback must be quarantined');
        }
    }
    final class Tribe__Tracker {
        public function filter_watch_updated_meta(
            mixed $check,
            mixed $id,
            mixed $key,
            mixed $value,
            mixed $prior
        ): never {
            ++$GLOBALS['duo_attachment_adapter_callback_calls'];
            throw new \RuntimeException('TEC tracker callback must be quarantined');
        }
    }
    final class Tribe__Meta__Chunker {
        public function filter_update_metadata(
            mixed $check,
            mixed $id,
            mixed $key,
            mixed $value
        ): never {
            ++$GLOBALS['duo_attachment_adapter_callback_calls'];
            throw new \RuntimeException('TEC request-local chunker callback must never enter the supported topology');
        }
    }
    final class AttachmentAuthorityWpdb {
        public string $prefix = 'wp_';
        public string $postmeta = 'wp_postmeta';
        public string $last_error = '';
        public bool $savepointExists = false;
        public bool $failMarkerInventory = false;
        /** @var list<array{meta_id:string,post_id:string,meta_key:string,meta_value:?string}> */
        public array $rows = [];
        /** @var list<array{k:string,v:?string}> */
        public array $kvRows = [];
        /** @var list<string> */
        public array $queries = [];

        public function prepare(string $sql, mixed ...$arguments): string {
            foreach ($arguments as $argument) {
                $replacement = is_int($argument)
                    ? (string) $argument
                    : "'" . str_replace("'", "''", (string) $argument) . "'";
                $sql = preg_replace('/%[ds]/', $replacement, $sql, 1);
            }
            return $sql;
        }

        public function get_var(string $sql): mixed {
            $this->queries[] = $sql;
            if (trim($sql) === 'SELECT @@in_transaction') return '1';
            if (preg_match("/SELECT v FROM wp_duo_kv WHERE k = '((?:''|[^'])*)'/D", $sql, $match) === 1) {
                $wanted = str_replace("''", "'", $match[1]);
                foreach ($this->kvRows as $row) {
                    if (hash_equals($wanted, $row['k'])) return $row['v'];
                }
                return null;
            }
            throw new \RuntimeException("unrecognized attachment authority get_var: $sql");
        }

        public function query(string $sql): int|false {
            $this->queries[] = $sql;
            if (preg_match('/^SAVEPOINT `duo_authored_[0-9a-f]{24}`$/D', $sql) === 1) {
                $this->savepointExists = true;
                return 1;
            }
            if (preg_match('/^RELEASE SAVEPOINT `duo_authored_[0-9a-f]{24}`$/D', $sql) === 1) {
                if (!$this->savepointExists) return false;
                $this->savepointExists = false;
                return 1;
            }
            throw new \RuntimeException("unrecognized attachment authority query: $sql");
        }

        public function get_results(string $sql, mixed $mode): mixed {
            $this->queries[] = $sql;
            if (str_contains($sql, 'FROM `wp_duo_kv`')) {
                if ($this->failMarkerInventory) {
                    $this->last_error = 'SECRET marker inventory failure payload';
                    return false;
                }
                $rows = array_values(array_filter(
                    $this->kvRows,
                    static fn(array $row): bool => str_starts_with(
                        strtolower($row['k']),
                        'attachment_fs:'
                    )
                ));
                usort($rows, static fn(array $left, array $right): int => strcmp($left['k'], $right['k']));
                $rows = array_slice($rows, 0, 2);
                return array_map(static function (array $row): array {
                    $value = $row['v'];
                    $bytes = is_string($value) ? strlen($value) : null;
                    return [
                        'k' => $row['k'],
                        'v_bytes' => $bytes === null ? null : (string) $bytes,
                        'bounded_v' => is_int($bytes) && $bytes <= 512 ? $value : null,
                    ];
                }, $rows);
            }
            if (str_starts_with($sql, 'SHOW INDEX FROM `wp_postmeta`')) {
                return [[
                    'Key_name' => 'meta_key',
                    'Seq_in_index' => '1',
                    'Column_name' => 'meta_key',
                    'Sub_part' => '191',
                    'Non_unique' => '1',
                    'Index_type' => 'BTREE',
                ]];
            }
            if (!str_contains($sql, 'attachment') && !str_contains($sql, 'FROM `wp_postmeta`')) {
                throw new \RuntimeException("unrecognized attachment authority get_results: $sql");
            }
            preg_match('/meta_id > ([0-9]+)/', $sql, $lastMatch);
            $last = (int) ($lastMatch[1] ?? 0);
            $rows = array_values(array_filter(
                $this->rows,
                static fn(array $row): bool => (int) $row['meta_id'] > $last
                    && strcasecmp($row['meta_key'], '_wp_attached_file') === 0
            ));
            usort($rows, static fn(array $left, array $right): int =>
                (int) $left['meta_id'] <=> (int) $right['meta_id']);
            $rows = array_slice($rows, 0, 512);
            return array_map(static function (array $row): array {
                $value = $row['meta_value'];
                $bytes = is_string($value) ? strlen($value) : null;
                return [
                    'meta_id' => $row['meta_id'],
                    'post_id' => $row['post_id'],
                    'meta_key' => $row['meta_key'],
                    'meta_value_bytes' => $bytes === null ? null : (string) $bytes,
                    'bounded_value' => is_int($bytes) && $bytes <= 1024 ? $value : null,
                ];
            }, $rows);
        }
    }
    final class PLL_Links_Domain {
        public function upload_dir(mixed $uploads): never {
            ++$GLOBALS['duo_attachment_adapter_callback_calls'];
            throw new \RuntimeException('Polylang domain callback must never enter the supported topology');
        }
    }

    $GLOBALS['wp_filter'] = [];
    $GLOBALS['duo_attachment_upload_root'] = '';
    $GLOBALS['duo_attachment_size_calls'] = 0;
    $GLOBALS['duo_attachment_mutate_size_call'] = 0;
    $GLOBALS['duo_attachment_size_roster'] = [
        'thumbnail' => ['width' => 300, 'height' => 300, 'crop' => true],
    ];
    $GLOBALS['duo_attachment_bad_filesize'] = false;
    $GLOBALS['duo_attachment_editor_warning'] = false;
    $GLOBALS['duo_attachment_editor_output'] = false;
    $GLOBALS['duo_attachment_big_guard_seen'] = false;
    $GLOBALS['duo_attachment_generate_calls'] = 0;
    $GLOBALS['duo_attachment_adapter_callback_calls'] = 0;
    $GLOBALS['duo_polylang_has_languages'] = false;
    $GLOBALS['wpdb'] = new AttachmentAuthorityWpdb();

    function duo_attachment_filter_id(callable $callback): string {
        return $callback instanceof \Closure
            ? spl_object_hash($callback)
            : hash('sha256', serialize($callback));
    }

    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool {
        global $wp_filter;
        $node = $wp_filter[$hook] ??= new WP_Hook();
        if (!$node instanceof WP_Hook) return false;
        $node->callbacks[$priority][duo_attachment_filter_id($callback)] = [
            'function' => $callback,
            'accepted_args' => $acceptedArgs,
        ];
        return true;
    }

    function remove_filter(string $hook, callable $callback, int $priority = 10): bool {
        global $wp_filter;
        $node = $wp_filter[$hook] ?? null;
        $id = duo_attachment_filter_id($callback);
        if (!$node instanceof WP_Hook || !isset($node->callbacks[$priority][$id])) return false;
        unset($node->callbacks[$priority][$id]);
        if (($node->callbacks[$priority] ?? []) === []) unset($node->callbacks[$priority]);
        if ($node->callbacks === []) unset($wp_filter[$hook]);
        return true;
    }

    function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed {
        global $wp_filter;
        $node = $wp_filter[$hook] ?? null;
        if (!$node instanceof WP_Hook) return $value;
        ksort($node->callbacks, SORT_NUMERIC);
        foreach ($node->callbacks as $callbacks) {
            foreach ($callbacks as $entry) {
                $args = array_slice([$value, ...$arguments], 0, $entry['accepted_args']);
                $value = ($entry['function'])(...$args);
            }
        }
        return $value;
    }

    function wp_upload_dir(mixed $time = null, bool $create = true): array {
        return ['basedir' => $GLOBALS['duo_attachment_upload_root'], 'error' => false];
    }

    function wp_get_registered_image_subsizes(): array {
        ++$GLOBALS['duo_attachment_size_calls'];
        $roster = $GLOBALS['duo_attachment_size_roster'];
        if ($GLOBALS['duo_attachment_mutate_size_call'] === $GLOBALS['duo_attachment_size_calls']) {
            $roster['thumbnail']['width'] += 1;
        }
        return $roster;
    }

    function get_post(int $id): object {
        return (object) ['ID' => $id, 'post_type' => 'attachment', 'post_mime_type' => 'image/png'];
    }
    function is_wp_error(mixed $value): bool { return false; }
    function file_is_displayable_image(string $file): bool { return true; }

    function wp_get_image_editor(string $file): WP_Image_Editor_GD {
        if ($GLOBALS['duo_attachment_editor_warning']) {
            trigger_error('hostile-editor-warning-secret', E_USER_WARNING);
        }
        if ($GLOBALS['duo_attachment_editor_output']) echo 'hostile-editor-output-secret';
        return new WP_Image_Editor_GD();
    }

    function wp_generate_attachment_metadata(int $attachmentId, string $file): array {
        ++$GLOBALS['duo_attachment_generate_calls'];
        $threshold = apply_filters('big_image_size_threshold', 2560, [4000, 3000], $file, $attachmentId);
        if ($threshold !== false) {
            throw new \RuntimeException('test core did not observe Duo\'s identity-preserving big-image guard');
        }
        $GLOBALS['duo_attachment_big_guard_seen'] = true;
        $bytes = file_get_contents($file);
        if (!is_string($bytes)) throw new \RuntimeException('test core could not read its staging original');
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $derivative = dirname($file) . '/' . pathinfo($file, PATHINFO_FILENAME) . '-300x300.' . $extension;
        if (file_put_contents($derivative, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('test core could not write its staged derivative');
        }
        $size = strlen($bytes) + ($GLOBALS['duo_attachment_bad_filesize'] ? 1 : 0);
        $metadata = [
            'file' => $file,
            'filesize' => strlen($bytes),
            'height' => 1,
            'sizes' => [
                'thumbnail' => [
                    'file' => basename($derivative),
                    'filesize' => $size,
                    'height' => 1,
                    'mime-type' => 'image/png',
                    'width' => 1,
                ],
            ],
            'width' => 1,
        ];
        if (isset($GLOBALS['duo_attachment_size_roster']['woocommerce_thumbnail'])) {
            $metadata['sizes']['woocommerce_thumbnail'] = $metadata['sizes']['thumbnail'];
        }
        $guard = apply_filters(
            'update_post_metadata',
            null,
            $attachmentId,
            '_wp_attachment_metadata',
            $metadata,
            ''
        );
        if ($guard !== true) throw new \RuntimeException('test core metadata write was not intercepted');
        return apply_filters('wp_generate_attachment_metadata', $metadata, $attachmentId, 'create');
    }

    $root = dirname(__DIR__, 4);
    require_once $root . '/agent/src/Kernel/Canon.php';
    require_once $root . '/agent/src/Kernel/DurableFilesystem.php';
    require_once $root . '/agent/src/Kernel/PlainData.php';
    require_once $root . '/agent/src/Kernel/OptionState.php';
    require_once $root . '/agent/src/Policy/Policy.php';
    require_once $root . '/agent/src/Repository/Ledger.php';
    require_once $root . '/agent/src/Grammar/Tokens.php';
    require_once $root . '/agent/src/Apply/ApplyFieldMaterializer.php';
    require_once $root . '/agent/src/Repository/CompiledArtifact.php';
    require_once $root . '/agent/src/Apply/AttachmentNativeMetadataGenerator.php';
    require_once $root . '/agent/src/Apply/AttachmentFilesystemTransaction.php';
    require_once $root . '/agent/src/Apply/AttachmentMaterializer.php';

    use Duo\ApplyFieldMaterializer;
    use Duo\AttachmentFilesystemTransaction;
    use Duo\AttachmentMaterializer;
    use Duo\AttachmentNativeMetadataGenerator;
    use Duo\CompiledRepository;
    use Duo\Db;
    use Duo\DeleteGuardEvaluator;
    use Duo\PlainData;
    use Duo\Policy;
    use Duo\Tokens;

    $failures = [];
    $check = static function (bool $ok, string $message) use (&$failures): void {
        echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$ok) $failures[] = $message;
    };
    $throws = static function (callable $operation, string $needle, string $message) use ($check): void {
        try {
            $operation();
            $check(false, $message);
        } catch (\Throwable $failure) {
            $matched = false;
            for ($cursor = $failure; $cursor instanceof \Throwable; $cursor = $cursor->getPrevious()) {
                if (str_contains($cursor->getMessage(), $needle)) {
                    $matched = true;
                    break;
                }
            }
            $check($matched, $message);
        }
    };
    $removeTree = static function (string $path) use (&$removeTree): void {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $removeTree($entry->getPathname());
        }
        @rmdir($path);
    };

    $temporary = $root . '/sandbox/tmp/duo-attachment-regress-' . bin2hex(random_bytes(8));
    $repository = $temporary . '/repository';
    $uploads = $temporary . '/wordpress/wp-content/uploads';
    if (!mkdir($repository, 0700, true) || !mkdir($uploads . '/2026/08', 0700, true)) {
        throw new \RuntimeException('could not create attachment regression roots');
    }
    $GLOBALS['duo_attachment_upload_root'] = $uploads;

    try {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );
        if (!is_string($png)) throw new \RuntimeException('invalid PNG fixture');
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        $uuid = '01a0341e-2067-7fe3-9402-530b5a0f6b34';
        $blob = hash('sha256', $png) . '.png';
        $front = [
            'alt' => 'portable alt',
            'file' => '2026/08/photo.png',
            'media' => $blob,
            'mime' => 'image/png',
            'type' => 'attachment',
            'uuid' => $uuid,
        ];
        $tree = [$uuid => ['data' => $front, 'type' => 'post']];
        $compiled = CompiledRepository::create([
            'media' => [$blob => ['base64' => base64_encode($png), 'sha256' => hash('sha256', $png)]],
            'tree' => $tree,
            'manifest_hash' => str_repeat('d', 64),
        ]);
        $work = [['uuid' => $uuid]];

        $original = $uploads . '/2026/08/photo.png';
        $stale = $uploads . '/2026/08/photo-150x150.png';
        $backup = $uploads . '/2026/08/photo-e1700000000000.png';
        $neighbor = $uploads . '/2026/08/photo-other.png';
        file_put_contents($original, 'prior-original');
        file_put_contents($stale, 'prior-owned-stale');
        file_put_contents($backup, 'prior-edited-backup');
        file_put_contents($neighbor, 'unrelated-prefix-neighbor');
        chmod($original, 0640);

        $filesystem = new AttachmentFilesystemTransaction($compiled, $repository);
        $filesystem->load_pending();
        $check($filesystem->phase() === null, 'an empty private control root has no pending attachment transaction');
        $check(!is_dir($repository . '/.duo'), 'a read-only pending probe does not create private control state');
        $handoffAttemptIdentity = null;
        $preflightGenerator = new AttachmentNativeMetadataGenerator(
            static function (int $id): string {
                DeleteGuardEvaluator::assert_transaction_isolation(
                    'native attachment metadata target-lock regression'
                );
                return 'image/png';
            },
            ['polylang'],
            $compiled->artifact_hash(),
            $compiled->manifest_hash(),
            static function () use (&$handoffAttemptIdentity): ?array {
                if (!is_array($handoffAttemptIdentity)) return null;
                return [
                    'intent_id' => $handoffAttemptIdentity['intent_id'],
                    'artifact_hash' => $handoffAttemptIdentity['artifact_hash'],
                    'roster_hash' => $handoffAttemptIdentity['roster_hash'],
                    'manifest_hash' => str_repeat('d', 64),
                ];
            }
        );
        $filesystem->prepare($work, $tree, $preflightGenerator);
        $check(
            is_file($repository . '/.duo/attachment-filesystem/current/journal.json'),
            'journal, staged bytes and before-images live under the repository-private .duo control root'
        );
        $check(
            !is_dir($uploads . '/.duo-attachment-apply') && !is_dir($uploads . '/.duo'),
            'no durable journal or before-image is published beneath the web-served uploads root'
        );
        $filesystem->register_attachment(41, $front, [
            '2026/08/photo.png',
            '2026/08/photo-150x150.png',
            '2026/08/photo-e1700000000000.png',
        ]);
        $attemptIdentity = $filesystem->attempt_identity();
        if (!is_array($attemptIdentity) || !$preflightGenerator->has_polylang_no_language_handoff()) {
            throw new \RuntimeException('attachment journal handoff fixture lacks its attempt-bound Polylang proof');
        }
        $handoffAttemptIdentity = $attemptIdentity;
        $check(
            preg_match('/^[0-9a-f]{32}$/D', $attemptIdentity['intent_id']) === 1
                && preg_match('/^[0-9a-f]{64}$/D', $attemptIdentity['artifact_hash']) === 1
                && preg_match('/^[0-9a-f]{64}$/D', $attemptIdentity['roster_hash']) === 1,
            'the durable attachment journal exposes an exact intent/artifact/registered-roster handoff identity'
        );
        $authored = $filesystem->seal_authored_transaction();
        $check(is_array($authored) && $filesystem->phase() === 'authored_prepared', 'authored marker seals exact UUID-to-post-ID authority');
        $publicationUmask = umask(0000);
        try {
            $filesystem->commit_authored_transaction($authored['value']);
        } finally {
            umask($publicationUmask);
        }
        $check(
            file_get_contents($original) === $png && (fileperms($original) & 0777) === 0640,
            'compiled original publishes only after COMMIT and preserves its exact safe prior mode under umask 0000'
        );
        $filesystem->end();

        $filesystem = new AttachmentFilesystemTransaction($compiled, $repository);
        $filesystem->load_pending();
        $filesystem->recover_pending_with_marker($authored['value']);
        $check($filesystem->phase() === 'originals_published', 'authored-marker crash recovery resumes at exact post-COMMIT original bytes');

        $normalizer = new \ReflectionMethod(
            AttachmentFilesystemTransaction::class,
            'normalize_generated_metadata'
        );
        $stageOriginal = $temporary . '/photo.png';
        file_put_contents($stageOriginal, $png);
        $emptyProjection = $normalizer->invoke(
            $filesystem,
            [
                'file' => $stageOriginal,
                'filesize' => strlen($png),
                'sizes' => [],
            ],
            ['original_path' => '2026/08/photo.png'],
            $stageOriginal,
            []
        );
        $check(
            ($emptyProjection['file'] ?? null) === '2026/08/photo.png'
                && ($emptyProjection['sizes'] ?? null) === [],
            'valid Core metadata with zero generated derivatives normalizes to an exact empty sizes map'
        );

        $GLOBALS['duo_attachment_size_calls'] = 0;
        $GLOBALS['duo_attachment_mutate_size_call'] = 0;
        $GLOBALS['duo_attachment_big_guard_seen'] = false;
        $GLOBALS['duo_attachment_generate_calls'] = 0;
        $filesystem->generate_metadata($preflightGenerator);
        $check(
            Db::$starts === 1
                && Db::$rollbacks === 1
                && !$GLOBALS['wpdb']->savepointExists,
            'native generator establishes target-lock continuity and settles its rollback-only metadata transaction'
        );
        $check(
            $GLOBALS['duo_attachment_generate_calls'] === 1,
            'one durable metadata phase invokes wp_generate_attachment_metadata exactly once'
        );
        $check($GLOBALS['duo_attachment_big_guard_seen'], 'large-image replacement is disabled while ordinary registered sizes still generate');
        $check(
            hash_equals($authored['value'], $filesystem->pending_marker_identity()['value']),
            'generation evidence does not mutate the stable authored marker authority'
        );
        $metadataRows = $filesystem->generated_metadata_rows();
        $metadata = PlainData::decode_serialized((string) $metadataRows[0]['metadata'], 'attachment test metadata');
        $check(
            ($metadata['file'] ?? null) === '2026/08/photo.png'
                && !str_contains(serialize($metadata), 'attachment-filesystem')
                && ($metadata['filesize'] ?? null) === strlen($png),
            'native metadata is canonicalized to the authored path with exact sealed filesize and no staging path'
        );
        $publicationUmask = umask(0000);
        try {
            $filesystem->publish_derivatives();
        } finally {
            umask($publicationUmask);
        }
        $derivative = $uploads . '/2026/08/photo-300x300.png';
        $check(
            (fileperms($derivative) & 0777) === 0600,
            'a new derivative uses sealed parent-derived permissions and never inherits a world-writable umask'
        );
        file_put_contents($original, 'hostile-original-drift');
        $throws(
            static fn() => $filesystem->seal_metadata_transaction(),
            'original publication lacks exact final bytes',
            'metadata COMMIT sealing re-proves the original bytes after publication'
        );
        file_put_contents($original, $png);
        chmod($original, 0640);
        file_put_contents($derivative, 'hostile-derivative-drift');
        $throws(
            static fn() => $filesystem->seal_metadata_transaction(),
            'derivative publication lacks exact final bytes',
            'metadata COMMIT sealing re-proves every derivative byte after publication'
        );
        file_put_contents($derivative, $png);
        chmod($derivative, 0600);
        chmod($derivative, 0666);
        $throws(
            static fn() => $filesystem->seal_metadata_transaction(),
            'world-writable',
            'same-hash derivative mode drift cannot be mistaken for a completed crash transition'
        );
        chmod($derivative, 0600);
        $metadataMarker = $filesystem->seal_metadata_transaction();
        $check(!hash_equals($authored['value'], $metadataMarker['value']), 'metadata phase atomically uses a distinct generation-bound marker');
        $filesystem->metadata_transaction_committed($metadataMarker['value']);
        $filesystem->end();

        $filesystem = new AttachmentFilesystemTransaction($compiled, $repository);
        $filesystem->load_pending();
        $filesystem->recover_pending_with_marker($metadataMarker['value']);
        $check($filesystem->phase() === 'metadata_committed', 'metadata-marker crash recovery resumes without repeating authored publication');
        $filesystem->remove_stale_derivatives($metadataMarker['value']);
        $check(
            !file_exists($stale) && !file_exists($backup),
            'only exact paths owned by prior native metadata and edited backup sizes are removed as stale'
        );
        $check(file_get_contents($neighbor) === 'unrelated-prefix-neighbor', 'an unrelated same-prefix upload is never inferred to be deletion-owned');
        $check(is_file($derivative), 'sealed native derivative bytes publish at the exact target path');
        $filesystem->cleanup_complete(null);
        $filesystem->end();
        $check(!is_dir($repository . '/.duo/attachment-filesystem/current'), 'terminal marker-free cleanup removes the reusable current slot');

        $secondUuid = '1a2b3c4d-5e6f-4789-8abc-def012345678';
        $secondFront = $front;
        $secondFront['uuid'] = $secondUuid;
        $secondFront['file'] = '2026/08/second.png';
        $secondTree = [$secondUuid => ['data' => $secondFront, 'type' => 'post']];
        $secondCompiled = CompiledRepository::create([
            'media' => [$blob => ['base64' => base64_encode($png), 'sha256' => hash('sha256', $png)]],
            'tree' => $secondTree,
        ]);
        $second = new AttachmentFilesystemTransaction($secondCompiled, $repository);
        $priorUmask = umask(0000);
        try {
            $second->prepare([['uuid' => $secondUuid]], $secondTree, $preflightGenerator);
        } finally {
            umask($priorUmask);
        }
        $check($second->phase() === 'prepared', 'a different attachment transaction can start after terminal cleanup');
        $journalMode = fileperms($repository . '/.duo/attachment-filesystem/current/journal.json');
        $check(
            is_int($journalMode) && ($journalMode & 0077) === 0,
            'private durable files remain owner-only under a deliberately permissive process umask'
        );
        $second->rollback_authored_transaction(null);
        $second->end();
        $lockFiles = array_map(
            static fn(\SplFileInfo $entry): string => $entry->getFilename(),
            iterator_to_array(new \FilesystemIterator(
                $repository . '/.duo/attachment-filesystem/locks',
                \FilesystemIterator::SKIP_DOTS
            ), false)
        );
        $check(
            $lockFiles === ['transaction.lock'],
            'repeated unique attachment paths retain one stable global lock inode instead of a per-path registry'
        );

        $aliasUuidA = '5e6f7081-92a3-4bcd-8ef0-123456789abc';
        $aliasUuidB = '6f708192-a3b4-4cde-8f01-23456789abcd';
        $aliasFrontA = $front;
        $aliasFrontA['uuid'] = $aliasUuidA;
        $aliasFrontA['file'] = '2026/08/Photo.png';
        $aliasFrontB = $front;
        $aliasFrontB['uuid'] = $aliasUuidB;
        $aliasFrontB['file'] = '2026/08/photo.png';
        $aliasTree = [
            $aliasUuidA => ['data' => $aliasFrontA, 'type' => 'post'],
            $aliasUuidB => ['data' => $aliasFrontB, 'type' => 'post'],
        ];
        $aliasCompiled = CompiledRepository::create([
            'media' => [$blob => ['base64' => base64_encode($png), 'sha256' => hash('sha256', $png)]],
            'tree' => $aliasTree,
        ]);
        $throws(
            static fn() => (new AttachmentFilesystemTransaction($aliasCompiled, $repository))->prepare(
                [['uuid' => $aliasUuidA], ['uuid' => $aliasUuidB]],
                $aliasTree,
                $preflightGenerator
            ),
            'filesystem-aliased original paths',
            'byte-distinct compiled paths that case-fold together refuse before durable or database mutation'
        );

        $diskAliasUuid = '708192a3-b4c5-4def-8012-3456789abcde';
        $diskAliasFront = $front;
        $diskAliasFront['uuid'] = $diskAliasUuid;
        $diskAliasFront['file'] = '2026/08/alias.png';
        $diskAliasTree = [$diskAliasUuid => ['data' => $diskAliasFront, 'type' => 'post']];
        $diskAliasCompiled = CompiledRepository::create([
            'media' => [$blob => ['base64' => base64_encode($png), 'sha256' => hash('sha256', $png)]],
            'tree' => $diskAliasTree,
        ]);
        file_put_contents($uploads . '/2026/08/Alias.png', 'foreign-case-alias');
        $throws(
            static fn() => (new AttachmentFilesystemTransaction($diskAliasCompiled, $repository))->prepare(
                [['uuid' => $diskAliasUuid]],
                $diskAliasTree,
                $preflightGenerator
            ),
            'case/Unicode-normalization filesystem alias',
            'an existing byte-different case alias refuses at the markerless pre-database boundary'
        );
        unlink($uploads . '/2026/08/Alias.png');

        if (class_exists(\Normalizer::class) && function_exists('mb_convert_case')) {
            $unicodeUuidA = '8192a3b4-c5d6-4ef0-8123-456789abcdef';
            $unicodeUuidB = '92a3b4c5-d6e7-4f01-8234-56789abcdef0';
            $unicodeFrontA = $front;
            $unicodeFrontA['uuid'] = $unicodeUuidA;
            $unicodeFrontA['file'] = "2026/08/caf\u{00E9}.png";
            $unicodeFrontB = $front;
            $unicodeFrontB['uuid'] = $unicodeUuidB;
            $unicodeFrontB['file'] = "2026/08/cafe\u{0301}.png";
            $unicodeTree = [
                $unicodeUuidA => ['data' => $unicodeFrontA, 'type' => 'post'],
                $unicodeUuidB => ['data' => $unicodeFrontB, 'type' => 'post'],
            ];
            $unicodeCompiled = CompiledRepository::create([
                'media' => [$blob => ['base64' => base64_encode($png), 'sha256' => hash('sha256', $png)]],
                'tree' => $unicodeTree,
            ]);
            $throws(
                static fn() => (new AttachmentFilesystemTransaction($unicodeCompiled, $repository))->prepare(
                    [['uuid' => $unicodeUuidA], ['uuid' => $unicodeUuidB]],
                    $unicodeTree,
                    $preflightGenerator
                ),
                'filesystem-aliased original paths',
                'Unicode normalization aliases refuse before either planned upload path is authored'
            );
        } else {
            $check(true, 'Unicode alias regression is conditionally exercised when normalization support is present');
        }

        $badUuid = '2b3c4d5e-6f70-489a-8bcd-ef0123456789';
        $badBytes = "%PDF-1.4\n%%EOF\n";
        $badBlob = hash('sha256', $badBytes) . '.pdf';
        $badFront = [
            'alt' => '',
            'file' => '2026/08/unsupported.pdf',
            'media' => $badBlob,
            'mime' => 'application/pdf',
            'type' => 'attachment',
            'uuid' => $badUuid,
        ];
        $badTree = [$badUuid => ['data' => $badFront, 'type' => 'post']];
        $startsBeforePreflight = Db::$starts;
        $throws(
            static fn() => CompiledRepository::create([
                'media' => [$badBlob => ['base64' => base64_encode($badBytes), 'sha256' => hash('sha256', $badBytes)]],
                'tree' => $badTree,
            ]),
            'unbounded Core image, audio, video, or PDF metadata branch',
            'deterministic delegated-media refusal occurs before any filesystem transaction can be prepared'
        );
        $check(
            Db::$starts === $startsBeforePreflight
                && !file_exists($uploads . '/2026/08/unsupported.pdf'),
            'delegated-media compile refusal starts no database transaction and changes no upload byte'
        );

        $renameUuid = '3c4d5e6f-7081-49ab-8cde-f0123456789a';
        $renameFront = [
            'alt' => '',
            'file' => '2026/09/renamed.png',
            'media' => $blob,
            'mime' => 'image/png',
            'type' => 'attachment',
            'uuid' => $renameUuid,
        ];
        $renameTree = [$renameUuid => ['data' => $renameFront, 'type' => 'post']];
        $renameCompiled = CompiledRepository::create([
            'media' => [$blob => ['base64' => base64_encode($png), 'sha256' => hash('sha256', $png)]],
            'tree' => $renameTree,
        ]);
        mkdir($uploads . '/2026/09', 0700, true);
        $oldOriginal = $uploads . '/2026/08/legacy.png';
        $oldDerivative = $uploads . '/2026/08/legacy-150x150.png';
        $oldNeighbor = $uploads . '/2026/08/legacy-other.png';
        file_put_contents($oldOriginal, 'legacy-original');
        file_put_contents($oldDerivative, 'legacy-owned-derivative');
        file_put_contents($oldNeighbor, 'legacy-unowned-neighbor');
        $rename = new AttachmentFilesystemTransaction($renameCompiled, $repository);
        $rename->prepare([['uuid' => $renameUuid]], $renameTree, $preflightGenerator);
        $rename->register_attachment(52, $renameFront, [
            '2026/08/legacy.png',
            '2026/08/legacy-150x150.png',
        ]);
        $renameAuthored = $rename->seal_authored_transaction();
        $rename->commit_authored_transaction($renameAuthored['value']);
        $check(
            is_file($uploads . '/2026/09/renamed.png') && is_file($oldOriginal),
            'directory/name move publishes the new original but retains old owned bytes until metadata commits'
        );
        $renameGenerator = new AttachmentNativeMetadataGenerator(static fn(int $id): string => 'image/png');
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $rename->generate_metadata($renameGenerator);
        $rename->publish_derivatives();
        $renameMetadata = $rename->seal_metadata_transaction();
        $rename->metadata_transaction_committed($renameMetadata['value']);
        $rename->remove_stale_derivatives($renameMetadata['value']);
        $check(
            !file_exists($oldOriginal)
                && !file_exists($oldDerivative)
                && file_get_contents($oldNeighbor) === 'legacy-unowned-neighbor',
            'rename removes only exact old attached-file/metadata ownership after new metadata is durable'
        );
        $rename->cleanup_complete(null);
        $rename->end();

        $collisionUuid = '4d5e6f70-8192-4abc-8def-0123456789ab';
        $collisionFront = $renameFront;
        $collisionFront['uuid'] = $collisionUuid;
        $collisionFront['file'] = '2026/09/collision.png';
        $collisionTree = [$collisionUuid => ['data' => $collisionFront, 'type' => 'post']];
        $collisionCompiled = CompiledRepository::create([
            'media' => [$blob => ['base64' => base64_encode($png), 'sha256' => hash('sha256', $png)]],
            'tree' => $collisionTree,
        ]);
        $collisionPath = $uploads . '/2026/09/collision.png';
        file_put_contents($collisionPath, $png);
        $collision = new AttachmentFilesystemTransaction($collisionCompiled, $repository);
        $collision->prepare([['uuid' => $collisionUuid]], $collisionTree, $preflightGenerator);
        $throws(
            static fn() => $collision->register_attachment(53, $collisionFront, []),
            'present without exact prior attached-file ownership',
            'even desired-hash destination bytes cannot be overwritten/adopted without exact prior attachment ownership'
        );
        $check(file_get_contents($collisionPath) === $png, 'unowned destination collision refusal changes no target byte');
        $collision->rollback_authored_transaction(null);
        $collision->end();

        $standalone = $repository . '/standalone.png';
        file_put_contents($standalone, $png);
        $generator = new AttachmentNativeMetadataGenerator(static fn(int $id): string => 'image/png');
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $generator->generate(41, $standalone);
        $probe = static fn(): bool => true;
        $priorHandler = set_error_handler($probe);
        $check($priorHandler === null, 'generator restores PHP\'s default error handler when set_error_handler originally returned null');
        restore_error_handler();

        $preexisting = static fn(): bool => true;
        set_error_handler($preexisting);
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $generator->generate(41, $standalone);
        $observed = set_error_handler($probe);
        $check($observed === $preexisting, 'generator restores an exact preexisting error handler after native work');
        restore_error_handler();
        restore_error_handler();

        $GLOBALS['duo_attachment_editor_output'] = true;
        $GLOBALS['duo_attachment_size_calls'] = 0;
        ob_start();
        $generator->generate(41, $standalone);
        $escaped = ob_get_clean();
        $GLOBALS['duo_attachment_editor_output'] = false;
        $check($escaped === '', 'editor-preflight output is captured before it can corrupt canonical command output');

        $GLOBALS['duo_attachment_editor_warning'] = true;
        $GLOBALS['duo_attachment_size_calls'] = 0;
        ob_start();
        $warning = null;
        try { $generator->generate(41, $standalone); } catch (\Throwable $failure) { $warning = $failure; }
        $escaped = ob_get_clean();
        $GLOBALS['duo_attachment_editor_warning'] = false;
        $check(
            $warning instanceof \Throwable
                && $escaped === ''
                && !str_contains($warning->getMessage(), 'hostile-editor-warning-secret'),
            'editor-preflight warnings are contained and value-free before native generation'
        );

        $hostile = static fn(mixed $value): mixed => $value;
        add_filter('pre_wp_filesize', $hostile, 10, 1);
        $throws(
            static fn() => $generator->generate(41, $standalone),
            'unreviewed callback topology',
            'WordPress 7.1 filesize callback topology refuses before parser/editor work'
        );
        remove_filter('pre_wp_filesize', $hostile, 10);
        add_filter('option_thumbnail_size_w', $hostile, 10, 1);
        $throws(
            static fn() => $generator->generate(41, $standalone),
            'image-size option callback topology',
            'dynamic image-size option filters are closed rather than bypassing the static hook roster'
        );
        remove_filter('option_thumbnail_size_w', $hostile, 10);

        $elementorPageTemplate = new \Elementor\Modules\PageTemplates\Module();
        $elementorSvg = new \Elementor\Core\Files\File_Types\Svg();
        $tecTracker = new Tribe__Tracker();
        $certifiedCallbacks = [
            ['update_post_metadata', [$elementorPageTemplate, 'filter_update_meta'], 10, 3],
            ['wp_update_attachment_metadata', [$elementorSvg, 'set_svg_meta_data'], 10, 2],
            ['wp_generate_attachment_metadata', ['WC_Regenerate_Images', 'add_uncropped_metadata'], 10, 1],
            ['update_post_metadata', ['WC_Post_Data', 'update_post_metadata'], 10, 5],
            ['update_post_metadata', ['WPSEO_Meta', 'remove_meta_if_default'], 10, 5],
            ['update_post_metadata', [$tecTracker, 'filter_watch_updated_meta'], PHP_INT_MAX - 1, 5],
            ['update_post_metadata', [new PLL_Sync_Post_Metas(), 'can_synchronize_metadata'], 1, 3],
            ['update_post_metadata', [new PLL_Sync_Post_Metas(), 'update_metadata'], 999, 5],
        ];
        foreach ($certifiedCallbacks as [$hook, $callback, $priority, $acceptedArgs]) {
            if (!add_filter($hook, $callback, $priority, $acceptedArgs)) {
                throw new \RuntimeException('could not install certified-adapter callback fixture');
            }
        }
        $certifiedTopology = [];
        foreach ($certifiedCallbacks as [$hook]) {
            $certifiedTopology[$hook] = $GLOBALS['wp_filter'][$hook]->callbacks;
        }
        $GLOBALS['duo_attachment_size_roster'] = [
            'thumbnail' => ['width' => 300, 'height' => 300, 'crop' => true],
            'woocommerce_thumbnail' => ['width' => 300, 'height' => 0, 'crop' => false],
        ];
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $GLOBALS['duo_attachment_generate_calls'] = 0;
        $GLOBALS['duo_attachment_adapter_callback_calls'] = 0;
        $GLOBALS['duo_polylang_has_languages'] = true;
        $certifiedGenerator = new AttachmentNativeMetadataGenerator(
            static fn(int $id): string => 'image/png',
            [
                'acf', 'advanced-editor-tools', 'classic-editor', 'code-snippets',
                'contact-form-7', 'elementor', 'ninja-forms', 'paid-memberships-pro',
                'polylang', 'the-events-calendar', 'woocommerce', 'wps-hide-login',
                'yoast', 'yoast-duplicate-post',
            ],
            $compiled->artifact_hash(),
            $compiled->manifest_hash()
        );
        $certifiedMetadata = $certifiedGenerator->generate(41, $standalone);
        $topologyRestored = true;
        foreach ($certifiedTopology as $hook => $expectedCallbacks) {
            $topologyRestored = $topologyRestored
                && (($GLOBALS['wp_filter'][$hook]->callbacks ?? null) === $expectedCallbacks);
        }
        $check(
            $GLOBALS['duo_attachment_generate_calls'] === 1
                && $GLOBALS['duo_attachment_adapter_callback_calls'] === 0
                && ($certifiedMetadata['sizes']['woocommerce_thumbnail']['uncropped'] ?? null) === true
                && $topologyRestored,
            'all normal pinned-adapter media callbacks are quarantined, projected where needed, and restored exactly around one Core generation'
        );
        foreach (array_reverse($certifiedCallbacks) as [$hook, $callback, $priority]) {
            if (!remove_filter($hook, $callback, $priority)) {
                throw new \RuntimeException('could not remove certified-adapter callback fixture');
            }
        }
        $GLOBALS['duo_polylang_has_languages'] = false;
        $GLOBALS['duo_attachment_size_roster'] = [
            'thumbnail' => ['width' => 300, 'height' => 300, 'crop' => true],
        ];

        $unboundWoo = ['WC_Regenerate_Images', 'add_uncropped_metadata'];
        add_filter('wp_generate_attachment_metadata', $unboundWoo, 10, 1);
        $throws(
            static fn() => $generator->generate(41, $standalone),
            'unreviewed callback topology',
            'an exact official callback is not authority unless its owning manifest is active'
        );
        $check(
            isset($GLOBALS['wp_filter']['wp_generate_attachment_metadata']),
            'callback-topology refusal preserves the unbound callback exactly'
        );
        remove_filter('wp_generate_attachment_metadata', $unboundWoo, 10);

        $polylangAbsent = (new AttachmentNativeMetadataGenerator(
            static fn(int $id): string => 'image/png',
            ['polylang'],
            $compiled->artifact_hash(),
            $compiled->manifest_hash()
        ))->generate(41, $standalone);
        $check(
            is_array($polylangAbsent),
            'Polylang post-meta synchronization callbacks may be absent before the target has languages and are not fabricated'
        );
        $transitionContext = null;
        $polylangTransition = new AttachmentNativeMetadataGenerator(
            static fn(int $id): string => 'image/png',
            ['polylang'],
            str_repeat('b', 64),
            str_repeat('d', 64),
            static function () use (&$transitionContext): ?array {
                return $transitionContext;
            }
        );
        $polylangTransition->preflight('image/png', $standalone);
        $proofContext = [
            'intent_id' => str_repeat('a', 32),
            'artifact_hash' => str_repeat('b', 64),
            'roster_hash' => str_repeat('c', 64),
            'manifest_hash' => str_repeat('d', 64),
        ];
        $check(
            $polylangTransition->has_polylang_no_language_handoff(),
            'markerless Polylang proof is held by the preflight generator rather than a boolean mode'
        );
        $throws(
            static fn() => $polylangTransition->generate(41, $standalone),
            'not sealed to a post-commit attachment attempt',
            'an unsealed markerless witness cannot cross into post-commit metadata generation'
        );
        $wrongArtifactContext = $proofContext;
        $wrongArtifactContext['artifact_hash'] = str_repeat('e', 64);
        $transitionContext = $wrongArtifactContext;
        $throws(
            static fn() => $polylangTransition->generate(41, $standalone),
            'compiled artifact identity',
            'a compiled-artifact-mismatched Polylang handoff refuses before native metadata work'
        );
        $GLOBALS['duo_polylang_has_languages'] = true;
        $transitionContext = $proofContext;
        $transitionMetadata = $polylangTransition->generate(41, $standalone);
        $check(
            is_array($transitionMetadata),
            'a markerless no-language proof remains authoritative after the authored transaction materializes Polylang languages'
        );
        $throws(
            static fn() => new AttachmentNativeMetadataGenerator(
                static fn(int $id): string => 'image/png',
                ['polylang'],
                true
            ),
            'must be of type ?string',
            'direct generator construction cannot bypass the attempt-bound Polylang proof'
        );
        $throws(
            static fn() => clone $polylangTransition,
            'cannot be cloned',
            'a markerless Polylang handoff cannot be replayed by cloning its generator'
        );
        $throws(
            static fn() => serialize($polylangTransition),
            'cannot be serialized',
            'a markerless Polylang handoff cannot be replayed through serialization'
        );
        $GLOBALS['duo_polylang_has_languages'] = false;
        $polylangPartial = new PLL_Sync_Post_Metas();
        add_filter('update_post_metadata', [$polylangPartial, 'can_synchronize_metadata'], 1, 3);
        $throws(
            static fn() => (new AttachmentNativeMetadataGenerator(
            static fn(int $id): string => 'image/png',
                ['polylang'],
                $compiled->artifact_hash(),
                $compiled->manifest_hash()
            ))->generate(41, $standalone),
            'partial Polylang post-meta callback topology',
            'one Polylang sync callback without its paired witness is refused'
        );
        remove_filter('update_post_metadata', [$polylangPartial, 'can_synchronize_metadata'], 1);
        $GLOBALS['duo_polylang_has_languages'] = true;
        $throws(
            static fn() => (new AttachmentNativeMetadataGenerator(
            static fn(int $id): string => 'image/png',
                ['polylang'],
                $compiled->artifact_hash(),
                $compiled->manifest_hash()
            ))->generate(41, $standalone),
            'languages are present',
            'an absent Polylang sync pair is refused when the native model proves languages are present'
        );
        $GLOBALS['duo_polylang_has_languages'] = false;

        $throws(
            static fn() => (new AttachmentNativeMetadataGenerator(
                static fn(int $id): string => 'image/png',
                ['woocommerce']
            ))->generate(41, $standalone),
            'lacks a required certified-adapter callback topology',
            'an active pinned adapter cannot silently omit its audited always-on media callbacks'
        );

        $polylangDomain = new PLL_Links_Domain();
        add_filter('upload_dir', [$polylangDomain, 'upload_dir'], 10, 1);
        $throws(
            static fn() => (new AttachmentNativeMetadataGenerator(
            static fn(int $id): string => 'image/png',
                ['polylang'],
                $compiled->artifact_hash(),
                $compiled->manifest_hash()
            ))->generate(41, $standalone),
            'request-conditional certified-adapter callback topology',
            'Polylang domain/subdomain upload rewriting is an explicit unsupported target-filesystem topology'
        );
        remove_filter('upload_dir', [$polylangDomain, 'upload_dir'], 10);

        $tecChunker = new Tribe__Meta__Chunker();
        add_filter('update_post_metadata', [$tecChunker, 'filter_update_metadata'], -1, 4);
        $throws(
            static fn() => (new AttachmentNativeMetadataGenerator(
                static fn(int $id): string => 'image/png',
                ['the-events-calendar']
            ))->generate(41, $standalone),
            'request-conditional certified-adapter callback topology',
            'TEC request-local meta chunking refuses before native attachment work'
        );
        remove_filter('update_post_metadata', [$tecChunker, 'filter_update_metadata'], -1);

        $tecHarbor = new \TEC\Common\Integrations\Harbor\PUE();
        add_filter('pre_option', [$tecHarbor, 'filter_pre_get_option'], 10, 3);
        $throws(
            static fn() => (new AttachmentNativeMetadataGenerator(
                static fn(int $id): string => 'image/png',
                ['the-events-calendar']
            ))->generate(41, $standalone),
            'request-conditional certified-adapter callback topology',
            'TEC premium Harbor option interception is refused while the free-plugin WP-CLI topology remains supported'
        );
        remove_filter('pre_option', [$tecHarbor, 'filter_pre_get_option'], 10);

        $tecQrUpload = static fn(mixed $uploads): mixed => $uploads;
        add_filter('upload_dir', $tecQrUpload, 10, 1);
        $throws(
            static fn() => (new AttachmentNativeMetadataGenerator(
                static fn(int $id): string => 'image/png',
                ['the-events-calendar']
            ))->generate(41, $standalone),
            'unreviewed callback topology',
            'TEC QR invocation-local upload closures remain outside the stable callable authority'
        );
        remove_filter('upload_dir', $tecQrUpload, 10);

        $GLOBALS['duo_attachment_size_calls'] = 0;
        $GLOBALS['duo_attachment_mutate_size_call'] = 2;
        $throws(
            static fn() => $generator->generate(41, $standalone),
            'roster changed at its transaction boundary',
            'registered-size TOCTOU drift refuses inside the same rollback-only transaction Core will use'
        );
        $GLOBALS['duo_attachment_mutate_size_call'] = 0;

        $GLOBALS['duo_attachment_size_roster'] = [
            'one' => ['width' => 16384, 'height' => 16384, 'crop' => true],
        ];
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $throws(
            static fn() => $generator->generate(41, $standalone),
            'aggregate pixel-work bound',
            'a syntactically valid registered-size roster cannot exceed the bounded output-pixel work authority'
        );
        $GLOBALS['duo_attachment_size_roster'] = [
            'thumbnail' => ['width' => 300, 'height' => 300, 'crop' => ['middle', 'everywhere']],
        ];
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $throws(
            static fn() => $generator->generate(41, $standalone),
            'registered image-size row is malformed',
            'crop coordinates use the exact bounded WordPress enum rather than arbitrary strings'
        );
        $GLOBALS['duo_attachment_size_roster'] = [
            'thumbnail' => ['width' => 300, 'height' => 300, 'crop' => true],
        ];

        $wideImage = imagecreatetruecolor(16384, 1);
        if ($wideImage === false) {
            throw new \RuntimeException('could not create a valid wide PNG fixture');
        }
        ob_start();
        imagepng($wideImage);
        $wide = ob_get_clean();
        unset($wideImage);
        if (!is_string($wide)) {
            throw new \RuntimeException('could not encode a valid wide PNG fixture');
        }
        $widePath = $repository . '/wide.png';
        file_put_contents($widePath, $wide);
        $GLOBALS['duo_attachment_size_roster'] = [
            'inferred-width' => ['width' => 0, 'height' => 16384, 'crop' => false],
        ];
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $throws(
            static fn() => $generator->preflight('image/png', $widePath),
            'aggregate pixel-work bound',
            'a zero registered width is charged at Core\'s source-aspect-ratio output bound rather than zero pixels'
        );
        $GLOBALS['duo_attachment_size_roster'] = [
            'thumbnail' => ['width' => 300, 'height' => 300, 'crop' => true],
        ];

        $polyglot = $repository . '/polyglot.png';
        file_put_contents($polyglot, $png . '<?php secret');
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $throws(
            static fn() => $generator->generate(41, $polyglot),
            'carries trailing bytes',
            'a valid image prefix with undeclared trailing payload is refused before the editor'
        );

        $jpeg = base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gOTAK/9sAQwADAgIDAgIDAwMDBAMDBAUIBQUEBAUKBwcGCAwKDAwLCgsLDQ4SEA0OEQ4LCxAWEBETFBUVFQwPFxgWFBgSFBUU/9sAQwEDBAQFBAUJBQUJFA0LDRQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQU/8AAEQgAAgACAwERAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+dK/DD/VM//Z',
            true
        );
        $gif = base64_decode('R0lGODdhAgACAIAAAPwCBAAAACwAAAAAAgACAAACAoRRADs=', true);
        if (!is_string($jpeg) || !is_string($gif)) {
            throw new \RuntimeException('invalid JPEG/GIF fixtures');
        }
        $jpePath = $repository . '/exact.JPE';
        $gifPath = $repository . '/exact.gif';
        file_put_contents($jpePath, $jpeg);
        file_put_contents($gifPath, $gif);
        $jpegGenerator = new AttachmentNativeMetadataGenerator(static fn(int $id): string => 'image/jpeg');
        $gifGenerator = new AttachmentNativeMetadataGenerator(static fn(int $id): string => 'image/gif');
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $jpegGenerator->preflight('image/jpeg', $jpePath);
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $gifGenerator->preflight('image/gif', $gifPath);
        $check(true, 'exact JPEG .jpe and GIF containers remain admitted at the markerless media boundary');
        $jpegTrailing = $repository . '/jpeg-trailing.jpe';
        $gifTrailing = $repository . '/gif-trailing.gif';
        file_put_contents($jpegTrailing, $jpeg . 'hidden' . "\xFF\xD9");
        file_put_contents($gifTrailing, $gif . 'hidden' . "\x3B");
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $throws(
            static fn() => $jpegGenerator->preflight('image/jpeg', $jpegTrailing),
            'carries trailing bytes',
            'JPEG authority ends at the first parsed EOI rather than a forged final EOI byte pair'
        );
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $throws(
            static fn() => $gifGenerator->preflight('image/gif', $gifTrailing),
            'carries trailing bytes',
            'GIF authority ends at the first parsed trailer rather than a forged final trailer byte'
        );
        $wrongExtension = $repository . '/mismatch.jpg';
        file_put_contents($wrongExtension, $png);
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $throws(
            static fn() => $generator->generate(41, $wrongExtension),
            'MIME/extension-mismatched',
            'declared MIME, target extension and detected bytes must select one exact native parser path'
        );
        $pdf = $repository . '/document.pdf';
        file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");
        $pdfGenerator = new AttachmentNativeMetadataGenerator(static fn(int $id): string => 'application/pdf');
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $throws(
            static fn() => $pdfGenerator->preflight('application/pdf', $pdf),
            'unsupported or MIME/extension-mismatched media class',
            'PDF/Imagick multi-page decompression is an explicit fail-closed boundary'
        );

        $bomb = "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', pack('NNCCCCC', 100000, 100000, 8, 6, 0, 0, 0))
            . $chunk('IDAT', gzcompress(''))
            . $chunk('IEND', '');
        $bombPath = $repository . '/pixel-bomb.png';
        file_put_contents($bombPath, $bomb);
        $GLOBALS['duo_attachment_size_calls'] = 0;
        $throws(
            static fn() => $generator->generate(41, $bombPath),
            'source dimensions exceed',
            'small compressed bytes cannot authorize an extreme source-pixel allocation'
        );

        $budget = new \ReflectionMethod(AttachmentFilesystemTransaction::class, 'add_transaction_bytes');
        $total = 0;
        $arguments = [&$total, 1073741823];
        $budget->invokeArgs($filesystem, $arguments);
        $arguments = [&$total, 1];
        $budget->invokeArgs($filesystem, $arguments);
        $check($total === 1073741824, 'transaction-wide budget admits its exact 1 GiB boundary without allocating it');
        $throws(
            static function () use ($budget, $filesystem, &$total): void {
                $arguments = [&$total, 1];
                $budget->invokeArgs($filesystem, $arguments);
            },
            '1 GiB aggregate',
            'transaction-wide budget refuses the first byte above its exact aggregate authority'
        );
        $many = 0;
        for ($i = 0; $i < 1024; ++$i) {
            $arguments = [&$many, 1048576];
            $budget->invokeArgs($filesystem, $arguments);
        }
        $check($many === 1073741824, 'many individually small files are charged to the same transaction-wide frontier');

        $symlinkTarget = $temporary . '/real-repository';
        $symlinkRepo = $temporary . '/repository-link';
        mkdir($symlinkTarget, 0700);
        symlink($symlinkTarget, $symlinkRepo);
        $throws(
            static fn() => (new AttachmentFilesystemTransaction($compiled, $symlinkRepo))->load_pending(),
            'exact physical repository control root',
            'a symlinked private control root refuses before any journal mutation'
        );
        $webRepo = $uploads . '/repository';
        mkdir($webRepo, 0700);
        $throws(
            static fn() => (new AttachmentFilesystemTransaction($compiled, $webRepo))->load_pending(),
            'outside the web-served uploads tree',
            'a repository/control root beneath uploads refuses rather than exposing durable bytes'
        );
        $unsafeRepo = $temporary . '/unsafe-repository';
        mkdir($unsafeRepo . '/.duo/attachment-filesystem', 0755, true);
        $throws(
            static fn() => (new AttachmentFilesystemTransaction($compiled, $unsafeRepo))->load_pending(),
            'permits group/other access',
            'a hostile precreated non-private attachment control subtree refuses before use'
        );

        $policy = (new \ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
        $tokens = (new \ReflectionClass(Tokens::class))->newInstanceWithoutConstructor();
        $fieldMaterializer = new ApplyFieldMaterializer($policy, $tokens);
        $attachmentMaterializer = new AttachmentMaterializer($policy, $fieldMaterializer, $compiled, $repository);
        $check($attachmentMaterializer instanceof AttachmentMaterializer, 'AttachmentMaterializer composes the durable boundary with an explicit private repository root');
        $constructor = (new \ReflectionClass(AttachmentMaterializer::class))->getConstructor();
        $check(
            array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters())
                === ['policy', 'fieldMaterializer', 'compiled', 'repositoryRoot'],
            'constructor authority binds frozen adapter policy, field materializer, immutable artifact and private repository root'
        );
        $proofState = new \ReflectionProperty(AttachmentMaterializer::class, 'polylangNativeGenerator');
        $proofState->setValue($attachmentMaterializer, $preflightGenerator);
        $attachmentMaterializer->end_authored_transaction();
        $check(
            $proofState->getValue($attachmentMaterializer) === null,
            'terminal authored-transaction end consumes any retained Polylang handoff authority'
        );
        $proofState->setValue($attachmentMaterializer, $preflightGenerator);
        $attachmentMaterializer->rollback_authored_transaction();
        $check(
            $proofState->getValue($attachmentMaterializer) === null,
            'rollback failure or retry preparation cannot retain a prior Polylang handoff authority'
        );
        $authoredExecutorSource = (string) file_get_contents($root . '/agent/src/Apply/AuthoredTransactionExecutor.php');
        $rebuildCoordinatorSource = (string) file_get_contents($root . '/agent/src/Apply/ApplyRebuildCoordinator.php');
        $nativeRebuildSource = (string) file_get_contents($root . '/agent/src/Rebuild/NativeRebuildExecutor.php');
        $check(
            str_contains(preg_replace('/\s+/', ' ', $authoredExecutorSource),
                'end_authored_transaction($retainNativeRebuildAuthority)'),
            'AuthoredTransactionExecutor explicitly transfers the live handoff only after committed post-commit participants'
        );
        $check(
            str_contains($rebuildCoordinatorSource, 'finally {')
                && str_contains($rebuildCoordinatorSource, 'discard_native_rebuild_authority'),
            'ApplyRebuildCoordinator clears an unconsumed handoff on every native-rebuild failure or skip path'
        );
        $check(
            str_contains($nativeRebuildSource, 'finalize_native_metadata($attachmentIds)'),
            'NativeRebuildExecutor remains the sole production consumer of the attachment handoff'
        );

        $priorOwnership = new \ReflectionMethod(AttachmentMaterializer::class, 'prior_native_owned_paths');
        $owned = $priorOwnership->invoke(
            $attachmentMaterializer,
            [[
                'meta_id' => '1',
                'meta_key' => '_wp_attachment_metadata',
                'meta_value' => serialize([
                    'file' => '2026/08/photo.png',
                    'sizes' => ['thumbnail' => ['file' => 'photo-150x150.png']],
                ]),
            ]],
            [[
                'meta_id' => '2',
                'meta_key' => '_wp_attached_file',
                'meta_value' => '2026/08/photo.png',
            ]],
            [[
                'meta_id' => '3',
                'meta_key' => '_wp_attachment_backup_sizes',
                'meta_value' => serialize([
                    'full-orig' => [
                        'file' => 'photo-e1700000000000.png',
                        'height' => 1,
                        'mime-type' => 'image/png',
                        'width' => 1,
                    ],
                ]),
            ]]
        );
        sort($owned, SORT_STRING);
        $check(
            $owned === [
                '2026/08/photo-150x150.png',
                '2026/08/photo-e1700000000000.png',
                '2026/08/photo.png',
            ],
            'prior _wp_attachment_backup_sizes extends exact stale-file ownership without granting prefix authority'
        );
        $emptySizesOwned = $priorOwnership->invoke(
            $attachmentMaterializer,
            [[
                'meta_id' => '1',
                'meta_key' => '_wp_attachment_metadata',
                'meta_value' => serialize([
                    'file' => '2026/08/photo.png',
                    'sizes' => [],
                ]),
            ]],
            [[
                'meta_id' => '2',
                'meta_key' => '_wp_attached_file',
                'meta_value' => '2026/08/photo.png',
            ]]
        );
        $check(
            $emptySizesOwned === ['2026/08/photo.png'],
            'valid Core metadata with an empty sizes map owns only its exact attached file'
        );
        $throws(
            static fn() => $priorOwnership->invoke(
                $attachmentMaterializer,
                [],
                [['meta_id' => '2', 'meta_key' => '_wp_attached_file', 'meta_value' => '2026/08/photo.png']],
                [[
                    'meta_id' => '3',
                    'meta_key' => '_wp_attachment_backup_sizes',
                    'meta_value' => serialize([
                        'full-orig' => ['file' => '../foreign.png', 'height' => 1, 'width' => 1],
                    ]),
                ]]
            ),
            'malformed row',
            'edited backup metadata cannot escape the exact attached-file directory'
        );

        $authorityWpdb = new AttachmentAuthorityWpdb();
        $GLOBALS['wpdb'] = $authorityWpdb;

        DeleteGuardEvaluator::end_authored_transaction();
        $lockWrapper = new \ReflectionMethod(
            AttachmentMaterializer::class,
            'with_locked_pending_bindings'
        );
        try {
            $lockBoundaryResult = $lockWrapper->invoke(
                $attachmentMaterializer,
                static function (): string {
                    DeleteGuardEvaluator::assert_transaction_isolation(
                        'post-commit attachment identity wrapper regression'
                    );
                    return 'bounded';
                },
                'post-commit attachment identity wrapper regression'
            );
        } catch (\Throwable $failure) {
            $lockBoundaryResult = $failure;
        }
        $check(
            $lockBoundaryResult === 'bounded' && !$authorityWpdb->savepointExists,
            'each post-commit attachment identity wrapper establishes and settles its own continuity savepoint'
        );

        $orphanIntent = str_repeat('1', 32);
        $orphanKey = 'attachment_fs:' . $orphanIntent;
        $orphanValue = 'duo-attachment-filesystem-transaction/v1:' . $orphanIntent
            . ':' . str_repeat('a', 64);
        $authorityWpdb->kvRows = [['k' => $orphanKey, 'v' => $orphanValue]];
        $throws(
            static fn() => $attachmentMaterializer->load_pending_filesystem(),
            'has no matching private control journal',
            'a committed attachment marker whose private journal is lost refuses before target capture'
        );

        $secondIntent = str_repeat('2', 32);
        $authorityWpdb->kvRows[] = [
            'k' => 'attachment_fs:' . $secondIntent,
            'v' => 'duo-attachment-filesystem-transaction/v1:' . $secondIntent
                . ':' . str_repeat('b', 64),
        ];
        $throws(
            static fn() => $attachmentMaterializer->load_pending_filesystem(),
            'contains multiple pending authorities',
            'multiple raw attachment database markers refuse at the bounded pre-capture inventory'
        );
        $check(
            count(array_filter(
                $authorityWpdb->queries,
                static fn(string $query): bool => str_contains($query, 'FROM `wp_duo_kv`')
                    && str_contains($query, 'CASE WHEN v IS NOT NULL')
                    && str_contains($query, 'LIMIT 2')
            )) >= 1,
            'attachment marker discovery transfers at most two length-gated raw rows without a cache/API shortcut'
        );

        $authorityWpdb->kvRows = [[
            'k' => 'ATTACHMENT_FS:' . $orphanIntent,
            'v' => $orphanValue,
        ]];
        $throws(
            static fn() => $attachmentMaterializer->load_pending_filesystem(),
            'malformed row',
            'a collation-equal non-byte-exact attachment marker key cannot hide from raw inventory'
        );
        $authorityWpdb->kvRows = [[
            'k' => $orphanKey,
            'v' => str_repeat('SECRET', 100),
        ]];
        try {
            $attachmentMaterializer->load_pending_filesystem();
            $check(false, 'oversized attachment marker values refuse without payload disclosure');
        } catch (\Throwable $failure) {
            $check(
                str_contains($failure->getMessage(), 'malformed row')
                    && !str_contains($failure->getMessage(), 'SECRET'),
                'oversized attachment marker values refuse without payload disclosure'
            );
        }
        $authorityWpdb->kvRows = [];
        $authorityWpdb->failMarkerInventory = true;
        try {
            $attachmentMaterializer->load_pending_filesystem();
            $check(false, 'attachment marker query failures refuse with a redacted diagnostic');
        } catch (\Throwable $failure) {
            $check(
                str_contains($failure->getMessage(), 'inventory read failed')
                    && !str_contains($failure->getMessage(), 'SECRET'),
                'attachment marker query failures refuse with a redacted diagnostic'
            );
        }
        $authorityWpdb->failMarkerInventory = false;

        $throws(
            static fn() => (new AttachmentMaterializer(
                $policy,
                $fieldMaterializer,
                $compiled,
                $unsafeRepo
            ))->load_pending_filesystem(),
            'permits group/other access',
            'the real pending-load product path refuses an unreadable or unsafe private control root'
        );

        $pendingRepository = $temporary . '/pending-repository';
        if (!mkdir($pendingRepository, 0700)) {
            throw new \RuntimeException('could not create pending attachment marker repository');
        }
        $pendingFilesystem = new AttachmentFilesystemTransaction($compiled, $pendingRepository);
        $pendingFilesystem->prepare($work, $tree, $preflightGenerator);
        $pendingIdentity = $pendingFilesystem->pending_marker_identity();
        if (!is_array($pendingIdentity)) {
            throw new \RuntimeException('pending attachment marker fixture lacks an identity');
        }
        $pendingFilesystem->end();
        $authorityWpdb->kvRows = [['k' => $orphanKey, 'v' => $orphanValue]];
        $pendingMaterializer = new AttachmentMaterializer(
            $policy,
            $fieldMaterializer,
            $compiled,
            $pendingRepository
        );
        $throws(
            static fn() => $pendingMaterializer->load_pending_filesystem(),
            'journal identities disagree',
            'a database marker for a different intent cannot authorize a private pending journal'
        );
        $authorityWpdb->kvRows = [];
        $retryMaterializer = new AttachmentMaterializer(
            $policy,
            $fieldMaterializer,
            $compiled,
            $pendingRepository
        );
        $check(
            $retryMaterializer->load_pending_filesystem(),
            'a refused mismatched inventory releases its lock so the exact markerless rollback can retry'
        );
        $retryMaterializer->recover_pending_filesystem();
        $check(
            !is_dir($pendingRepository . '/.duo/attachment-filesystem/current'),
            'markerless pending preparation rolls back through the public recovery path before the retry proceeds'
        );

        $authorityWpdb->kvRows = [];
        DeleteGuardEvaluator::begin_authored_transaction();
        $globalAuthority = new \ReflectionMethod(
            AttachmentMaterializer::class,
            'assert_global_attached_file_authority'
        );
        $authorityWpdb->rows = [[
            'meta_id' => '1',
            'post_id' => '41',
            'meta_key' => '_wp_attached_file',
            'meta_value' => '2026/08/photo.png',
        ]];
        $globalAuthority->invoke($attachmentMaterializer, 41, '2026/08/photo.png');
        $check(
            count(array_filter(
                $authorityWpdb->queries,
                static fn(string $query): bool => str_contains($query, 'LIMIT 512 FOR UPDATE')
            )) >= 1,
            'global _wp_attached_file authority uses a bounded prefix-index locking read'
        );
        $authorityWpdb->rows[] = [
            'meta_id' => '2',
            'post_id' => '42',
            'meta_key' => '_wp_attached_file',
            'meta_value' => '2026/08/photo.png',
        ];
        $throws(
            static fn() => $globalAuthority->invoke($attachmentMaterializer, 41, '2026/08/photo.png'),
            'already owned by another or duplicate',
            'a second attachment cannot claim an exact globally-owned _wp_attached_file path'
        );
        $authorityWpdb->rows = [[
            'meta_id' => '1',
            'post_id' => '42',
            'meta_key' => '_wp_attached_file',
            'meta_value' => '2026/08/PHOTO.png',
        ]];
        $throws(
            static fn() => $globalAuthority->invoke($attachmentMaterializer, 41, '2026/08/photo.png'),
            'global case/Unicode-normalization metadata alias',
            'a byte-different case alias in another attachment refuses before target ownership changes'
        );
        if (class_exists(\Normalizer::class) && function_exists('mb_convert_case')) {
            $authorityWpdb->rows = [[
                'meta_id' => '1',
                'post_id' => '42',
                'meta_key' => '_wp_attached_file',
                'meta_value' => "2026/08/cafe\u{0301}.png",
            ]];
            $throws(
                static fn() => $globalAuthority->invoke(
                    $attachmentMaterializer,
                    41,
                    "2026/08/caf\u{00E9}.png"
                ),
                'global case/Unicode-normalization metadata alias',
                'a Unicode-normalization metadata alias cannot authorize two physical attachment owners'
            );
        }
        $globalAuthorities = new \ReflectionMethod(
            AttachmentMaterializer::class,
            'assert_global_attached_file_authorities'
        );
        $authorityWpdb->rows = [[
            'meta_id' => '1',
            'post_id' => '42',
            'meta_key' => '_wp_attached_file',
            'meta_value' => '2026/08/photo-e1700000000000.png',
        ]];
        $throws(
            static fn() => $globalAuthorities->invoke(
                $attachmentMaterializer,
                41,
                ['2026/08/photo.png', '2026/08/photo-e1700000000000.png']
            ),
            'already owned by another or duplicate',
            'an edited backup path cannot become stale-deletion authority while another attachment owns it'
        );
        $authorityWpdb->rows = [[
            'meta_id' => '1',
            'post_id' => '42',
            'meta_key' => '_WP_ATTACHED_FILE',
            'meta_value' => '2026/08/other.png',
        ]];
        $throws(
            static fn() => $globalAuthority->invoke($attachmentMaterializer, 41, '2026/08/photo.png'),
            'malformed or aliased row',
            'a collation-equal non-byte-exact attached-file meta key cannot hide in the global lock range'
        );
        $authorityWpdb->rows = [[
            'meta_id' => '1',
            'post_id' => '42',
            'meta_key' => '_wp_attached_file',
            'meta_value' => str_repeat('a', 1025),
        ]];
        $throws(
            static fn() => $globalAuthority->invoke($attachmentMaterializer, 41, '2026/08/photo.png'),
            'contains an oversized path',
            'oversized attached-file state refuses before its LONGTEXT value crosses the driver boundary'
        );
        $authorityWpdb->rows = [];
        for ($metaId = 1; $metaId <= 512; ++$metaId) {
            $authorityWpdb->rows[] = [
                'meta_id' => (string) $metaId,
                'post_id' => (string) (1000 + $metaId),
                'meta_key' => '_wp_attached_file',
                'meta_value' => '2026/08/unrelated-' . $metaId . '.png',
            ];
        }
        $authorityWpdb->rows[] = [
            'meta_id' => '513',
            'post_id' => '42',
            'meta_key' => '_wp_attached_file',
            'meta_value' => '2026/08/photo.png',
        ];
        $throws(
            static fn() => $globalAuthority->invoke($attachmentMaterializer, 41, '2026/08/photo.png'),
            'already owned by another or duplicate',
            'global attached-file collision authority cannot be hidden just beyond its first bounded lock page'
        );
        DeleteGuardEvaluator::end_authored_transaction();
    } finally {
        $removeTree($temporary);
    }

    if ($failures !== []) {
        echo "\n" . count($failures) . " failure(s):\n";
        foreach ($failures as $failure) echo "  - $failure\n";
        exit(1);
    }
    echo "\nall AttachmentMaterializer checks passed\n";
}
