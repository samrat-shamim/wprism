<?php
declare(strict_types=1);

namespace Duo;

if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
if (!class_exists(PlainData::class, false)) {
    require_once __DIR__ . '/../Kernel/PlainData.php';
}

/**
 * Runs WordPress's attachment metadata generator against an isolated file.
 *
 * Core 6.9.2/7.0.3/7.1 saves partial image metadata after every generated
 * sub-size. A throw or process loss can therefore otherwise leave both files
 * and postmeta half-updated. Duo runs the reviewed core generator inside a
 * rollback-only DB transaction and short-circuits only the two exact core
 * intermediate postmeta writes. Exact always-on callbacks from certified
 * adapters are quarantined and restored around Core (with Woo's one relevant
 * projection reproduced from the sealed size roster); every other callback or
 * editor topology refuses. All files land in the caller-owned staging
 * directory, and the durable journal publishes them only after exact inventory
 * checks.
 */
final class AttachmentNativeMetadataGenerator {
    private const MAX_OUTPUT_BYTES = 65536;
    private const MAX_METADATA_BYTES = 16777216;
    private const MAX_REGISTERED_SIZES = 128;
    private const MAX_IMAGE_DIMENSION = 16384;
    private const MAX_SOURCE_PIXELS = 67108864;
    private const MAX_OUTPUT_PIXELS = 67108864;

    /** Hooks reached by the audited Core raster path and the explicitly refused sibling media paths. */
    private const CLOSED_FILTERS = [
        '_wp_relative_upload_path',
        'attachment_thumbnail_args',
        'big_image_size_threshold',
        'fallback_intermediate_image_sizes',
        'file_is_displayable_image',
        'image_editor_default_mime_type',
        'image_editor_output_format',
        'image_make_intermediate_size',
        'image_max_bit_depth',
        'image_memory_limit',
        'image_resize_dimensions',
        'image_save_progressive',
        'image_strip_meta',
        'intermediate_image_sizes',
        'intermediate_image_sizes_advanced',
        'jpeg_quality',
        'mime_types',
        'alloptions',
        'pre_option',
        'pre_wp_load_alloptions',
        'pre_wp_filesize',
        'pre_wp_unique_filename_file_list',
        'sanitize_post_meta__wp_attached_file',
        'sanitize_post_meta__wp_attached_file_for_attachment',
        'sanitize_post_meta__wp_attachment_metadata',
        'sanitize_post_meta__wp_attachment_metadata_for_attachment',
        'update_attached_file',
        'update_post_metadata',
        'upload_dir',
        'wp_constrain_dimensions',
        'wp_editor_set_quality',
        'wp_filesize',
        'wp_generate_attachment_metadata',
        'wp_image_editors',
        'wp_image_maybe_exif_rotate',
        'wp_image_resize_identical_dimensions',
        'wp_read_audio_metadata',
        'wp_read_image_metadata',
        'wp_read_image_metadata_types',
        'wp_read_video_metadata',
        'wp_unique_filename',
        'wp_update_attachment_metadata',
    ];

    /**
     * Official callbacks registered on the admitted WordPress media path by
     * the exact plugin families certified in manifests/*.json. Always-on
     * callbacks are quarantined and restored byte-for-byte around Core; a
     * request-local callback means this WP-CLI process crossed an unreviewed
     * importer/regenerator/domain-mode lifecycle and therefore refuses.
     */
    private const ADAPTER_CALLBACKS = [
        'elementor-bfi-editors' => [
            'manifest' => 'elementor', 'presence' => 'required',
            'hook' => 'wp_image_editors', 'priority' => 10, 'accepted_args' => 1,
            'kind' => 'function', 'callable' => 'bfi_wp_image_editor',
        ],
        'elementor-bfi-dimensions' => [
            'manifest' => 'elementor', 'presence' => 'required',
            'hook' => 'image_resize_dimensions', 'priority' => 10, 'accepted_args' => 5,
            'kind' => 'function', 'callable' => 'bfi_image_resize_dimensions',
        ],
        'elementor-page-template-meta' => [
            'manifest' => 'elementor', 'presence' => 'required',
            'hook' => 'update_post_metadata', 'priority' => 10, 'accepted_args' => 3,
            'kind' => 'instance', 'class' => 'Elementor\\Modules\\PageTemplates\\Module',
            'method' => 'filter_update_meta',
        ],
        'elementor-svg-meta' => [
            'manifest' => 'elementor', 'presence' => 'required',
            'hook' => 'wp_update_attachment_metadata', 'priority' => 10, 'accepted_args' => 2,
            'kind' => 'instance', 'class' => 'Elementor\\Core\\Files\\File_Types\\Svg',
            'method' => 'set_svg_meta_data',
        ],
        'woocommerce-uncropped-meta' => [
            'manifest' => 'woocommerce', 'presence' => 'required',
            'hook' => 'wp_generate_attachment_metadata', 'priority' => 10, 'accepted_args' => 1,
            'kind' => 'static', 'class' => 'WC_Regenerate_Images', 'method' => 'add_uncropped_metadata',
        ],
        'woocommerce-post-meta' => [
            'manifest' => 'woocommerce', 'presence' => 'required',
            'hook' => 'update_post_metadata', 'priority' => 10, 'accepted_args' => 5,
            'kind' => 'static', 'class' => 'WC_Post_Data', 'method' => 'update_post_metadata',
        ],
        'yoast-post-meta' => [
            'manifest' => 'yoast', 'presence' => 'required',
            'hook' => 'update_post_metadata', 'priority' => 10, 'accepted_args' => 5,
            'kind' => 'static', 'class' => 'WPSEO_Meta', 'method' => 'remove_meta_if_default',
        ],
        'tec-tracker-post-meta' => [
            'manifest' => 'the-events-calendar', 'presence' => 'required',
            'hook' => 'update_post_metadata', 'priority' => PHP_INT_MAX - 1, 'accepted_args' => 5,
            'kind' => 'instance', 'class' => 'Tribe__Tracker', 'method' => 'filter_watch_updated_meta',
        ],
        'polylang-domain-upload' => [
            'manifest' => 'polylang', 'presence' => 'conditional-refuse',
            'hook' => 'upload_dir', 'priority' => 10, 'accepted_args' => 1,
            'kind' => 'instance-any', 'classes' => ['PLL_Links_Domain', 'PLL_Links_Subdomain'],
            'method' => 'upload_dir',
        ],
        'woocommerce-background-sizes' => [
            'manifest' => 'woocommerce', 'presence' => 'conditional-refuse',
            'hook' => 'intermediate_image_sizes', 'priority' => 10, 'accepted_args' => 1,
            'kind' => 'instance', 'class' => 'WC_Regenerate_Images_Request',
            'method' => 'adjust_intermediate_image_sizes',
        ],
        'woocommerce-background-missing-sizes' => [
            'manifest' => 'woocommerce', 'presence' => 'conditional-refuse',
            'hook' => 'intermediate_image_sizes_advanced', 'priority' => 10, 'accepted_args' => 3,
            'kind' => 'instance', 'class' => 'WC_Regenerate_Images_Request',
            'method' => 'filter_image_sizes_to_only_missing_thumbnails',
        ],
        'woocommerce-on-demand-sizes' => [
            'manifest' => 'woocommerce', 'presence' => 'conditional-refuse',
            'hook' => 'intermediate_image_sizes', 'priority' => 10, 'accepted_args' => 1,
            'kind' => 'static', 'class' => 'WC_Regenerate_Images',
            'method' => 'adjust_intermediate_image_sizes',
        ],
        'woocommerce-download-upload-dir' => [
            'manifest' => 'woocommerce', 'presence' => 'conditional-refuse',
            'hook' => 'upload_dir', 'priority' => 10, 'accepted_args' => 1,
            'kind' => 'instance', 'class' => 'WC_Admin_Upload_Downloadable_Product',
            'method' => 'upload_dir',
        ],
        'woocommerce-download-filename' => [
            'manifest' => 'woocommerce', 'presence' => 'conditional-refuse',
            'hook' => 'wp_unique_filename', 'priority' => 10, 'accepted_args' => 3,
            'kind' => 'instance', 'class' => 'WC_Admin_Upload_Downloadable_Product',
            'method' => 'update_filename',
        ],
        'woocommerce-csv-upload-dir' => [
            'manifest' => 'woocommerce', 'presence' => 'conditional-refuse',
            'hook' => 'upload_dir', 'priority' => 10, 'accepted_args' => 1,
            'kind' => 'instance',
            'class' => 'Automattic\\WooCommerce\\Internal\\Admin\\ImportExport\\CSVUploadHelper',
            'method' => 'override_upload_dir',
        ],
        'woocommerce-csv-filename' => [
            'manifest' => 'woocommerce', 'presence' => 'conditional-refuse',
            'hook' => 'wp_unique_filename', 'priority' => 0, 'accepted_args' => 2,
            'kind' => 'instance',
            'class' => 'Automattic\\WooCommerce\\Internal\\Admin\\ImportExport\\CSVUploadHelper',
            'method' => 'override_unique_filename',
        ],
        'tec-meta-chunker' => [
            'manifest' => 'the-events-calendar', 'presence' => 'conditional-refuse',
            'hook' => 'update_post_metadata', 'priority' => -1, 'accepted_args' => 4,
            'kind' => 'instance', 'class' => 'Tribe__Meta__Chunker',
            'method' => 'filter_update_metadata',
        ],
        'tec-harbor-license-option' => [
            'manifest' => 'the-events-calendar', 'presence' => 'conditional-refuse',
            'hook' => 'pre_option', 'priority' => 10, 'accepted_args' => 3,
            'kind' => 'instance', 'class' => 'TEC\\Common\\Integrations\\Harbor\\PUE',
            'method' => 'filter_pre_get_option',
        ],
        // TEC's QR upload filter is an invocation-local anonymous closure, so
        // it has no stable callable identity to admit; CLOSED_FILTERS refuses
        // it generically while this registry binds every stable TEC callback.
    ];

    /**
     * @param \Closure(int):string $lockTarget returns the exact raw MIME type under row/meta locks
     * @param list<string> $adapterManifests manifest names from the frozen Policy version-range projection
     */
    public function __construct(
        private readonly \Closure $lockTarget,
        private readonly array $adapterManifests = []
    ) {
        if (!array_is_list($adapterManifests)
            || count($adapterManifests) > 32
            || count(array_unique($adapterManifests, SORT_STRING)) !== count($adapterManifests)) {
            throw new \RuntimeException('duo: native attachment metadata adapter authority is malformed');
        }
        foreach ($adapterManifests as $manifest) {
            if (!is_string($manifest)
                || $manifest === ''
                || strlen($manifest) > 191
                || preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $manifest) !== 1) {
                throw new \RuntimeException('duo: native attachment metadata adapter authority is malformed');
            }
        }
    }

    /**
     * Refuse deterministic byte/runtime topology before authored DB or upload
     * mutation. generate() repeats every proof under its rollback-only target
     * lock because this markerless check is an early boundary, not a receipt.
     */
    public function preflight(string $mime, string $stageFile): void {
        $this->assert_stage_file($stageFile);
        $bufferLevel = ob_get_level();
        $outputBytes = 0;
        $handlerInstalled = false;
        $priorUmask = null;
        $adapterQuarantine = null;
        $primary = null;
        $cleanupFailures = [];
        try {
            set_error_handler(static function (
                int $severity,
                string $message,
                string $file,
                int $line
            ): never {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });
            $handlerInstalled = true;
            ob_start(static function (string $chunk) use (&$outputBytes): string {
                $outputBytes += strlen($chunk);
                if ($outputBytes > self::MAX_OUTPUT_BYTES) {
                    throw new \RuntimeException('duo: native attachment metadata preflight emitted excessive output');
                }
                return '';
            }, 4096);
            $this->load_core_runtime();
            $adapterQuarantine = $this->quarantine_reviewed_adapter_callbacks();
            $this->assert_closed_filter_topology();
            $priorUmask = umask(0077);
            [$sourceWidth, $sourceHeight] = $this->assert_media_environment($mime, $stageFile);
            $sizes = $this->registered_sizes_witness($sourceWidth, $sourceHeight);
            if (!hash_equals(
                $sizes['hash'],
                $this->registered_sizes_witness($sourceWidth, $sourceHeight)['hash']
            )) {
                throw new \RuntimeException(
                    'duo: native attachment metadata registered image-size roster changed during markerless preflight'
                );
            }
        } catch (\Throwable $failure) {
            $primary = $failure;
        } finally {
            if ($adapterQuarantine !== null) {
                array_push(
                    $cleanupFailures,
                    ...$this->restore_reviewed_adapter_callbacks($adapterQuarantine)
                );
            }
            if ($priorUmask !== null) {
                try {
                    umask($priorUmask);
                } catch (\Throwable $failure) {
                    $cleanupFailures[] = 'umask=' . self::failure_fingerprint($failure);
                }
            }
            while (ob_get_level() > $bufferLevel) {
                try {
                    ob_end_clean();
                } catch (\Throwable $failure) {
                    $cleanupFailures[] = 'output=' . self::failure_fingerprint($failure);
                }
            }
            if ($handlerInstalled) {
                try {
                    restore_error_handler();
                } catch (\Throwable $failure) {
                    $cleanupFailures[] = 'error-handler=' . self::failure_fingerprint($failure);
                }
            }
        }
        if ($primary !== null || $cleanupFailures !== []) {
            $parts = [];
            if ($primary !== null) $parts[] = 'original=' . self::failure_fingerprint($primary);
            array_push($parts, ...$cleanupFailures);
            throw new \RuntimeException(
                'duo: native attachment metadata markerless preflight failed; ' . implode('; ', $parts),
                0,
                $primary
            );
        }
    }

    /** @return array<mixed> bounded native metadata */
    public function generate(int $attachmentId, string $stageFile): array {
        if ($attachmentId <= 0) {
            throw new \RuntimeException('duo: native attachment metadata generation requires a positive attachment id');
        }
        $this->assert_stage_file($stageFile);

        $guard = static function (
            mixed $check,
            mixed $objectId,
            mixed $metaKey,
            mixed $metaValue,
            mixed $previousValue
        ) use ($attachmentId): bool {
            if ($check !== null
                || $objectId !== $attachmentId
                || !in_array($metaKey, ['_wp_attachment_metadata', '_wp_attached_file'], true)
                || !is_string($metaKey)) {
                throw new \RuntimeException(
                    'duo: native attachment metadata attempted an undeclared intermediate metadata mutation'
                );
            }
            return true;
        };
        $bigImageGuard = static function (
            mixed $threshold,
            mixed $imagesize,
            mixed $file,
            mixed $objectId
        ) use ($attachmentId, $stageFile): false {
            if ($threshold !== 2560
                || !is_array($imagesize)
                || !is_string($file)
                || !hash_equals($stageFile, $file)
                || $objectId !== $attachmentId) {
                throw new \RuntimeException(
                    'duo: native attachment metadata reached an unexpected big-image threshold call shape'
                );
            }
            // The compiled _wp_attached_file and blob are authored identity.
            // Core's default large-image path changes that identity to
            // `-scaled`; disable only that derived replacement while retaining
            // ordinary registered sub-size generation in isolated staging.
            return false;
        };

        $transactionStarted = false;
        $guardInstalled = false;
        $bigImageGuardInstalled = false;
        $bufferLevel = ob_get_level();
        $outputBytes = 0;
        $handlerInstalled = false;
        $priorUmask = null;
        $metadata = null;
        $registeredSizesWitness = null;
        $adapterQuarantine = null;
        $primary = null;
        $cleanupFailures = [];
        try {
            set_error_handler(static function (
                int $severity,
                string $message,
                string $file,
                int $line
            ): never {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });
            // A null return means PHP's default handler was active, not that
            // Duo failed to install its throwing boundary.
            $handlerInstalled = true;
            ob_start(static function (string $chunk) use (&$outputBytes): string {
                $outputBytes += strlen($chunk);
                if ($outputBytes > self::MAX_OUTPUT_BYTES) {
                    throw new \RuntimeException('duo: native attachment metadata emitted excessive output');
                }
                return '';
            }, 4096);
            $this->load_core_runtime();
            $adapterQuarantine = $this->quarantine_reviewed_adapter_callbacks();
            $this->assert_closed_filter_topology();
            $priorUmask = umask(0077);
            [$sourceWidth, $sourceHeight] = $this->assert_bounded_source_image($stageFile);
            $registeredSizesWitness = $this->registered_sizes_witness($sourceWidth, $sourceHeight);
            Db::start_repeatable_read('native attachment metadata rollback-only transaction start');
            $transactionStarted = true;
            $mime = ($this->lockTarget)($attachmentId);
            if (!is_string($mime) || $mime === '' || strlen($mime) > 191) {
                throw new \RuntimeException('duo: native attachment metadata target lock returned a malformed MIME type');
            }
            [$lockedWidth, $lockedHeight] = $this->assert_media_environment($mime, $stageFile);
            if ($lockedWidth !== $sourceWidth || $lockedHeight !== $sourceHeight) {
                throw new \RuntimeException(
                    'duo: native attachment metadata source dimensions changed at its transaction boundary'
                );
            }
            $lockedSizes = $this->registered_sizes_witness($lockedWidth, $lockedHeight);
            if (!hash_equals($registeredSizesWitness['hash'], $lockedSizes['hash'])) {
                throw new \RuntimeException(
                    'duo: native attachment metadata registered image-size roster changed at its transaction boundary'
                );
            }
            if (!add_filter('update_post_metadata', $guard, PHP_INT_MIN, 5)) {
                throw new \RuntimeException('duo: native attachment metadata could not install its no-write guard');
            }
            $guardInstalled = true;
            if (!add_filter('big_image_size_threshold', $bigImageGuard, PHP_INT_MIN, 4)) {
                throw new \RuntimeException('duo: native attachment metadata could not install its identity-preserving big-image guard');
            }
            $bigImageGuardInstalled = true;
            $this->assert_guard_topology($guard);
            $this->assert_single_guard_topology('big_image_size_threshold', $bigImageGuard, 4);
            $metadata = wp_generate_attachment_metadata($attachmentId, $stageFile);
            if ($metadata === false || is_wp_error($metadata) || !is_array($metadata)) {
                throw new \RuntimeException('duo: native attachment metadata generator reported failure');
            }
            $metadata = $this->apply_quarantined_adapter_projection(
                $metadata,
                $lockedSizes['sizes'],
                $adapterQuarantine
            );
            PlainData::assert($metadata, 'native attachment metadata result');
            $encoded = serialize($metadata);
            if (strlen($encoded) > self::MAX_METADATA_BYTES) {
                throw new \RuntimeException('duo: native attachment metadata result exceeds its bounded byte limit');
            }
            $this->assert_guard_topology($guard);
            $this->assert_single_guard_topology('big_image_size_threshold', $bigImageGuard, 4);
        } catch (\Throwable $failure) {
            $primary = $failure;
        } finally {
            if ($guardInstalled) {
                try {
                    if (!remove_filter('update_post_metadata', $guard, PHP_INT_MIN)) {
                        throw new \RuntimeException('no-write guard removal returned false');
                    }
                } catch (\Throwable $failure) {
                    $cleanupFailures[] = 'metadata-guard=' . self::failure_fingerprint($failure);
                }
            }
            if ($bigImageGuardInstalled) {
                try {
                    if (!remove_filter('big_image_size_threshold', $bigImageGuard, PHP_INT_MIN)) {
                        throw new \RuntimeException('big-image guard removal returned false');
                    }
                } catch (\Throwable $failure) {
                    $cleanupFailures[] = 'big-image-guard=' . self::failure_fingerprint($failure);
                }
            }
            if ($transactionStarted) {
                try {
                    Db::rollback('native attachment metadata rollback-only transaction rollback');
                } catch (\Throwable $failure) {
                    $cleanupFailures[] = 'database-rollback=' . self::failure_fingerprint($failure);
                }
            }
            if ($adapterQuarantine !== null) {
                array_push(
                    $cleanupFailures,
                    ...$this->restore_reviewed_adapter_callbacks($adapterQuarantine)
                );
            }
            if ($priorUmask !== null) {
                try {
                    umask($priorUmask);
                } catch (\Throwable $failure) {
                    $cleanupFailures[] = 'umask=' . self::failure_fingerprint($failure);
                }
            }
            while (ob_get_level() > $bufferLevel) {
                try {
                    ob_end_clean();
                } catch (\Throwable $failure) {
                    $cleanupFailures[] = 'output=' . self::failure_fingerprint($failure);
                }
            }
            if ($handlerInstalled) {
                try {
                    restore_error_handler();
                } catch (\Throwable $failure) {
                    $cleanupFailures[] = 'error-handler=' . self::failure_fingerprint($failure);
                }
            }
        }
        if ($primary !== null || $cleanupFailures !== []) {
            $parts = [];
            if ($primary !== null) $parts[] = 'original=' . self::failure_fingerprint($primary);
            array_push($parts, ...$cleanupFailures);
            throw new \RuntimeException(
                'duo: native attachment metadata generation failed in its isolated staging boundary; '
                . implode('; ', $parts),
                0,
                $primary
            );
        }
        return $metadata;
    }

    private function load_core_runtime(): void {
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        foreach ([
            'add_filter', 'file_get_contents', 'get_post',
            'getimagesize', 'is_wp_error', 'remove_filter', 'wp_generate_attachment_metadata', 'wp_get_image_editor',
            'wp_get_registered_image_subsizes',
        ] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException('duo: native attachment metadata runtime is incomplete');
            }
        }
    }

    /**
     * @return array{
     *   callbacks:list<array{hook:string,priority:int,accepted_args:int,callback:callable,rule:string}>,
     *   originals:array<string,array>,
     *   matched:array<string,true>
     * }
     */
    private function quarantine_reviewed_adapter_callbacks(): array {
        global $wp_filter;
        if (!is_array($wp_filter)) {
            throw new \RuntimeException('duo: native attachment metadata filter registry is malformed');
        }
        $authorized = array_fill_keys($this->adapterManifests, true);
        $callbacks = [];
        $originals = [];
        $matched = [];
        foreach (self::CLOSED_FILTERS as $hook) {
            if (!isset($wp_filter[$hook])) continue;
            $node = $wp_filter[$hook];
            $rows = is_object($node) && property_exists($node, 'callbacks')
                ? $node->callbacks
                : null;
            if (!is_array($rows)) {
                throw new \RuntimeException(
                    'duo: native attachment metadata refuses an unreviewed callback topology ('
                    . self::bounded_name_fingerprint($hook) . ')'
                );
            }
            foreach ($rows as $priority => $entries) {
                if (!is_int($priority) || !is_array($entries) || $entries === []) {
                    throw new \RuntimeException(
                        'duo: native attachment metadata refuses a malformed callback topology ('
                        . self::bounded_name_fingerprint($hook) . ')'
                    );
                }
                foreach ($entries as $entry) {
                    if (!is_array($entry)
                        || array_keys($entry) !== ['function', 'accepted_args']
                        || !is_callable($entry['function'] ?? null)
                        || !is_int($entry['accepted_args'] ?? null)
                        || $entry['accepted_args'] < 0
                        || $entry['accepted_args'] > 16) {
                        throw new \RuntimeException(
                            'duo: native attachment metadata refuses a malformed callback entry ('
                            . self::bounded_name_fingerprint($hook) . ')'
                        );
                    }
                    $matches = [];
                    foreach (self::ADAPTER_CALLBACKS as $id => $rule) {
                        if (($rule['hook'] ?? null) === $hook
                            && ($rule['priority'] ?? null) === $priority
                            && ($rule['accepted_args'] ?? null) === $entry['accepted_args']
                            && isset($authorized[$rule['manifest'] ?? ''])
                            && self::callback_matches_rule($entry['function'], $rule)) {
                            $matches[] = $id;
                        }
                    }
                    if (count($matches) !== 1 || isset($matched[$matches[0]])) {
                        throw new \RuntimeException(
                            'duo: native attachment metadata refuses an unreviewed callback topology ('
                            . self::bounded_name_fingerprint($hook) . ')'
                        );
                    }
                    $id = $matches[0];
                    if ((self::ADAPTER_CALLBACKS[$id]['presence'] ?? null) === 'conditional-refuse') {
                        throw new \RuntimeException(
                            'duo: native attachment metadata refuses a request-conditional certified-adapter callback topology ('
                            . self::bounded_name_fingerprint($hook) . ')'
                        );
                    }
                    $matched[$id] = true;
                    $originals[$hook] ??= $rows;
                    $callbacks[] = [
                        'hook' => $hook,
                        'priority' => $priority,
                        'accepted_args' => $entry['accepted_args'],
                        'callback' => $entry['function'],
                        'rule' => $id,
                    ];
                }
            }
        }
        foreach (self::ADAPTER_CALLBACKS as $id => $rule) {
            if (($rule['presence'] ?? null) === 'required'
                && isset($authorized[$rule['manifest'] ?? ''])
                && !isset($matched[$id])) {
                throw new \RuntimeException(
                    'duo: native attachment metadata lacks a required certified-adapter callback topology ('
                    . self::bounded_name_fingerprint((string) ($rule['hook'] ?? '')) . ')'
                );
            }
        }

        $removed = [];
        try {
            foreach ($callbacks as $row) {
                if (!remove_filter($row['hook'], $row['callback'], $row['priority'])) {
                    throw new \RuntimeException(
                        'duo: native attachment metadata could not quarantine a certified-adapter callback'
                    );
                }
                $removed[] = $row;
            }
            $this->assert_closed_filter_topology();
        } catch (\Throwable $failure) {
            $cleanup = $this->restore_reviewed_adapter_callbacks([
                'callbacks' => $removed,
                'originals' => $originals,
                'matched' => $matched,
            ]);
            throw new \RuntimeException(
                'duo: native attachment metadata could not establish its certified-adapter callback isolation; '
                . 'original=' . self::failure_fingerprint($failure)
                . ($cleanup === [] ? '' : '; cleanup=' . implode(',', $cleanup)),
                0,
                $failure
            );
        }
        return ['callbacks' => $callbacks, 'originals' => $originals, 'matched' => $matched];
    }

    /**
     * @param array{
     *   callbacks:list<array{hook:string,priority:int,accepted_args:int,callback:callable,rule:string}>,
     *   originals:array<string,array>,
     *   matched:array<string,true>
     * } $quarantine
     * @return list<string> bounded cleanup failure fingerprints
     */
    private function restore_reviewed_adapter_callbacks(array $quarantine): array {
        global $wp_filter;
        $failures = [];
        foreach ($quarantine['callbacks'] as $row) {
            try {
                if (!add_filter($row['hook'], $row['callback'], $row['priority'], $row['accepted_args'])) {
                    throw new \RuntimeException('adapter callback restoration returned false');
                }
            } catch (\Throwable $failure) {
                $failures[] = 'adapter-restore=' . self::failure_fingerprint($failure);
            }
        }
        foreach ($quarantine['originals'] as $hook => $expected) {
            try {
                $node = is_array($wp_filter ?? null) ? ($wp_filter[$hook] ?? null) : null;
                $actual = is_object($node) && property_exists($node, 'callbacks')
                    ? $node->callbacks
                    : null;
                if (!is_array($actual) || $actual !== $expected) {
                    throw new \RuntimeException('adapter callback topology readback differs');
                }
            } catch (\Throwable $failure) {
                $failures[] = 'adapter-readback=' . self::failure_fingerprint($failure);
            }
        }
        return $failures;
    }

    private static function callback_matches_rule(mixed $callback, array $rule): bool {
        $kind = $rule['kind'] ?? null;
        if ($kind === 'function') {
            return is_string($callback)
                && hash_equals((string) ($rule['callable'] ?? ''), $callback);
        }
        if (!is_array($callback)
            || !array_is_list($callback)
            || count($callback) !== 2
            || !is_string($callback[1] ?? null)
            || !hash_equals((string) ($rule['method'] ?? ''), $callback[1])) {
            return false;
        }
        if ($kind === 'static') {
            return is_string($callback[0] ?? null)
                && hash_equals((string) ($rule['class'] ?? ''), $callback[0]);
        }
        if ($kind === 'instance') {
            return is_object($callback[0] ?? null)
                && hash_equals((string) ($rule['class'] ?? ''), get_class($callback[0]));
        }
        if ($kind === 'instance-any' && is_object($callback[0] ?? null)) {
            return in_array(get_class($callback[0]), (array) ($rule['classes'] ?? []), true);
        }
        return false;
    }

    /**
     * Reproduce WooCommerce 11.0's only admitted metadata mutation without
     * executing its filter (which crosses target options, object cache and a
     * separately extensible woocommerce_get_image_size_* hook). The exact
     * registered Core size roster already carries the same height authority.
     *
     * @param array<mixed> $metadata
     * @param array<string,array{width:int,height:int,crop:bool|array}> $registeredSizes
     * @param array{matched:array<string,true>} $quarantine
     * @return array<mixed>
     */
    private function apply_quarantined_adapter_projection(
        array $metadata,
        array $registeredSizes,
        array $quarantine
    ): array {
        if (!isset($quarantine['matched']['woocommerce-uncropped-meta'])
            || !isset($metadata['sizes']['woocommerce_thumbnail'])) {
            return $metadata;
        }
        $size = $registeredSizes['woocommerce_thumbnail'] ?? null;
        $metadataSize = $metadata['sizes']['woocommerce_thumbnail'];
        if (!is_array($size) || !is_array($metadataSize)) {
            throw new \RuntimeException(
                'duo: native attachment metadata cannot reproduce WooCommerce uncropped size authority'
            );
        }
        $metadataSize['uncropped'] = $size['height'] === 0;
        $metadata['sizes']['woocommerce_thumbnail'] = $metadataSize;
        return $metadata;
    }

    private function assert_closed_filter_topology(): void {
        global $wp_filter;
        if (!is_array($wp_filter)) {
            throw new \RuntimeException('duo: native attachment metadata filter registry is malformed');
        }
        foreach (self::CLOSED_FILTERS as $hook) {
            if (!isset($wp_filter[$hook])) continue;
            $callbacks = is_object($wp_filter[$hook]) && property_exists($wp_filter[$hook], 'callbacks')
                ? $wp_filter[$hook]->callbacks
                : null;
            if (!is_array($callbacks) || $callbacks !== []) {
                throw new \RuntimeException(
                    'duo: native attachment metadata refuses an unreviewed callback topology ('
                    . self::bounded_name_fingerprint($hook) . ')'
                );
            }
        }
        foreach ($wp_filter as $hook => $node) {
            if (!is_string($hook)
                || preg_match('/^(?:pre_option_|default_option_|option_).+_size_(?:w|h|crop)$/D', $hook) !== 1) {
                continue;
            }
            $callbacks = is_object($node) && property_exists($node, 'callbacks')
                ? $node->callbacks
                : null;
            if (!is_array($callbacks) || $callbacks !== []) {
                throw new \RuntimeException(
                    'duo: native attachment metadata refuses an unreviewed image-size option callback topology ('
                    . self::bounded_name_fingerprint($hook) . ')'
                );
            }
        }
    }

    private function assert_guard_topology(\Closure $guard): void {
        global $wp_filter;
        $node = is_array($wp_filter ?? null) ? ($wp_filter['update_post_metadata'] ?? null) : null;
        $callbacks = is_object($node) && property_exists($node, 'callbacks') ? $node->callbacks : null;
        $priority = is_array($callbacks) ? ($callbacks[PHP_INT_MIN] ?? null) : null;
        if (!is_array($callbacks)
            || count($callbacks) !== 1
            || !is_array($priority)
            || count($priority) !== 1) {
            throw new \RuntimeException('duo: native attachment metadata no-write guard topology changed');
        }
        $entry = array_values($priority)[0];
        if (!is_array($entry)
            || ($entry['function'] ?? null) !== $guard
            || ($entry['accepted_args'] ?? null) !== 5) {
            throw new \RuntimeException('duo: native attachment metadata no-write guard identity changed');
        }
    }

    private function assert_single_guard_topology(
        string $hook,
        \Closure $guard,
        int $acceptedArgs
    ): void {
        global $wp_filter;
        $node = is_array($wp_filter ?? null) ? ($wp_filter[$hook] ?? null) : null;
        $callbacks = is_object($node) && property_exists($node, 'callbacks') ? $node->callbacks : null;
        $priority = is_array($callbacks) ? ($callbacks[PHP_INT_MIN] ?? null) : null;
        if (!is_array($callbacks)
            || count($callbacks) !== 1
            || !is_array($priority)
            || count($priority) !== 1) {
            throw new \RuntimeException('duo: native attachment metadata guard topology changed');
        }
        $entry = array_values($priority)[0];
        if (!is_array($entry)
            || ($entry['function'] ?? null) !== $guard
            || ($entry['accepted_args'] ?? null) !== $acceptedArgs) {
            throw new \RuntimeException('duo: native attachment metadata guard identity changed');
        }
    }

    /**
     * @return array{hash:string,sizes:array<string,array{width:int,height:int,crop:bool|array}>}
     */
    private function registered_sizes_witness(int $sourceWidth, int $sourceHeight): array {
        $sizes = wp_get_registered_image_subsizes();
        if (!is_array($sizes)
            || array_is_list($sizes)
            || count($sizes) > self::MAX_REGISTERED_SIZES) {
            throw new \RuntimeException('duo: native attachment metadata registered image-size roster is malformed or oversized');
        }
        $aggregate = 0;
        $outputPixels = 0;
        foreach ($sizes as $name => $row) {
            $aggregate += is_string($name) ? strlen($name) : 0;
            if (!is_string($name)
                || $name === ''
                || strlen($name) > 191
                || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
                || !is_array($row)
                || array_keys($row) !== ['width', 'height', 'crop']
                || !is_int($row['width'] ?? null)
                || !is_int($row['height'] ?? null)
                || $row['width'] < 0
                || $row['height'] < 0
                || $row['width'] > self::MAX_IMAGE_DIMENSION
                || $row['height'] > self::MAX_IMAGE_DIMENSION
                || ($row['width'] === 0 && $row['height'] === 0)
                || !(is_bool($row['crop'])
                    || (is_array($row['crop'])
                        && array_is_list($row['crop'])
                        && count($row['crop']) === 2
                        && is_string($row['crop'][0] ?? null)
                        && is_string($row['crop'][1] ?? null)
                        && in_array($row['crop'][0], ['left', 'center', 'right'], true)
                        && in_array($row['crop'][1], ['top', 'center', 'bottom'], true)))) {
                throw new \RuntimeException('duo: native attachment metadata registered image-size row is malformed');
            }
            $width = $row['width'] === 0
                ? min(
                    $sourceWidth,
                    intdiv(($row['height'] * $sourceWidth) + $sourceHeight - 1, $sourceHeight)
                )
                : $row['width'];
            $height = $row['height'] === 0
                ? min(
                    $sourceHeight,
                    intdiv(($row['width'] * $sourceHeight) + $sourceWidth - 1, $sourceWidth)
                )
                : $row['height'];
            $pixels = $width * $height;
            if ($pixels > self::MAX_OUTPUT_PIXELS - $outputPixels) {
                throw new \RuntimeException(
                    'duo: native attachment metadata registered image sizes exceed their aggregate pixel-work bound'
                );
            }
            $outputPixels += $pixels;
        }
        if ($aggregate > 32768) {
            throw new \RuntimeException('duo: native attachment metadata registered image-size names exceed their byte bound');
        }
        return ['hash' => hash('sha256', serialize($sizes)), 'sizes' => $sizes];
    }

    private function displayable_image(string $mime, string $stageFile): bool {
        return $mime !== 'image/heic' && function_exists('file_is_displayable_image')
            ? file_is_displayable_image($stageFile)
            : true;
    }

    /** @return array{int,int} exact bounded source dimensions */
    private function assert_media_environment(string $mime, string $stageFile): array {
        if ($mime === '' || strlen($mime) > 191) {
            throw new \RuntimeException('duo: native attachment metadata received a malformed MIME authority');
        }
        $this->assert_media_identity($mime, $stageFile);
        if (!$this->displayable_image($mime, $stageFile)) {
            throw new \RuntimeException('duo: native attachment metadata refuses a non-displayable raster image');
        }
        $dimensions = $this->assert_bounded_source_image($stageFile);
        $editor = wp_get_image_editor($stageFile);
        if (is_wp_error($editor)) {
            throw new \RuntimeException('duo: native attachment metadata could not select a core image editor');
        }
        if (get_class($editor) !== 'WP_Image_Editor_GD') {
            throw new \RuntimeException(
                'duo: native attachment metadata refuses a non-GD image editor; '
                . 'multi-frame/delegate work is outside the bounded pixel authority'
            );
        }
        return $dimensions;
    }

    /**
     * The reviewed native boundary is raster-image metadata only. PDF
     * delegates can decompress multiple pages through Imagick; audio/video
     * metadata can create embedded-cover attachment rows; unknown/import MIME
     * spellings select parser behavior that is not declared by the compiled
     * bytes. Exact container termination also prevents a valid image prefix
     * from carrying an undeclared trailing payload.
     */
    private function assert_media_identity(string $mime, string $stageFile): void {
        $extensions = [
            'image/gif' => ['gif'],
            'image/jpeg' => ['jpe', 'jpeg', 'jpg'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
        ];
        $extension = strtolower(pathinfo($stageFile, PATHINFO_EXTENSION));
        if (!isset($extensions[$mime]) || !in_array($extension, $extensions[$mime], true)) {
            throw new \RuntimeException(
                'duo: native attachment metadata refuses an unsupported or MIME/extension-mismatched media class'
            );
        }
        if (!class_exists(\finfo::class)) {
            throw new \RuntimeException('duo: native attachment metadata lacks its byte MIME detector');
        }
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($stageFile);
        if (!is_string($detected) || !hash_equals($mime, $detected)) {
            throw new \RuntimeException(
                'duo: native attachment metadata declared MIME disagrees with the bounded staging bytes'
            );
        }
        $bytes = file_get_contents($stageFile);
        if (!is_string($bytes) || !$this->image_container_is_exact($mime, $bytes)) {
            throw new \RuntimeException(
                'duo: native attachment metadata image container is truncated, malformed, or carries trailing bytes'
            );
        }
    }

    private function image_container_is_exact(string $mime, string $bytes): bool {
        $length = strlen($bytes);
        if ($mime === 'image/jpeg') {
            return $this->jpeg_container_is_exact($bytes);
        }
        if ($mime === 'image/gif') {
            return $this->gif_container_is_exact($bytes);
        }
        if ($mime === 'image/webp') {
            if ($length < 12 || !str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WEBP') {
                return false;
            }
            $size = unpack('Vsize', substr($bytes, 4, 4));
            return is_array($size) && ($size['size'] ?? null) === $length - 8;
        }
        if ($mime !== 'image/png' || $length < 20 || !str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return false;
        }
        $offset = 8;
        while ($offset + 12 <= $length) {
            $decoded = unpack('Nlength', substr($bytes, $offset, 4));
            $chunkLength = is_array($decoded) ? ($decoded['length'] ?? null) : null;
            if (!is_int($chunkLength) || $chunkLength < 0 || $chunkLength > $length - $offset - 12) {
                return false;
            }
            $type = substr($bytes, $offset + 4, 4);
            $offset += 12 + $chunkLength;
            if ($type === 'IEND') {
                return $chunkLength === 0 && $offset === $length;
            }
        }
        return false;
    }

    private function jpeg_container_is_exact(string $bytes): bool {
        $length = strlen($bytes);
        if ($length < 4 || !str_starts_with($bytes, "\xFF\xD8")) return false;
        $offset = 2;
        $sawFrame = false;
        $sawScan = false;
        while ($offset < $length) {
            if (ord($bytes[$offset]) !== 0xFF) return false;
            while ($offset < $length && ord($bytes[$offset]) === 0xFF) ++$offset;
            if ($offset >= $length) return false;
            $marker = ord($bytes[$offset++]);
            if ($marker === 0x00) return false;
            if ($marker === 0xD9) return $sawFrame && $sawScan && $offset === $length;
            if ($marker === 0xD8
                || $marker === 0x01
                || ($marker >= 0xD0 && $marker <= 0xD7)) {
                return false;
            }
            if ($offset + 2 > $length) return false;
            $decoded = unpack('nlength', substr($bytes, $offset, 2));
            $segmentLength = is_array($decoded) ? ($decoded['length'] ?? null) : null;
            if (!is_int($segmentLength)
                || $segmentLength < 2
                || $segmentLength > $length - $offset) {
                return false;
            }
            if (in_array($marker, [
                0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7,
                0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF,
            ], true)) {
                $sawFrame = true;
            }
            $offset += $segmentLength;
            if ($marker !== 0xDA) continue;
            $sawScan = true;
            while ($offset < $length) {
                if (ord($bytes[$offset]) !== 0xFF) {
                    ++$offset;
                    continue;
                }
                $markerOffset = $offset;
                while ($offset < $length && ord($bytes[$offset]) === 0xFF) ++$offset;
                if ($offset >= $length) return false;
                $entropyMarker = ord($bytes[$offset++]);
                if ($entropyMarker === 0x00
                    || ($entropyMarker >= 0xD0 && $entropyMarker <= 0xD7)) {
                    continue;
                }
                $offset = $markerOffset;
                break;
            }
        }
        return false;
    }

    private function gif_container_is_exact(string $bytes): bool {
        $length = strlen($bytes);
        if ($length < 14
            || !(str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a'))) {
            return false;
        }
        $offset = 13;
        $packed = ord($bytes[10]);
        if (($packed & 0x80) !== 0) {
            $offset += 3 * (1 << (($packed & 0x07) + 1));
        }
        if ($offset >= $length) return false;
        $sawImage = false;
        while ($offset < $length) {
            $introducer = ord($bytes[$offset++]);
            if ($introducer === 0x3B) return $sawImage && $offset === $length;
            if ($introducer === 0x21) {
                if ($offset >= $length) return false;
                ++$offset; // Extension label; all admitted extensions then use GIF sub-blocks.
                $offset = $this->gif_sub_blocks_end($bytes, $offset, $extensionData);
                if ($offset < 0) return false;
                continue;
            }
            if ($introducer !== 0x2C || $offset + 9 > $length) return false;
            $descriptorPacked = ord($bytes[$offset + 8]);
            $offset += 9;
            if (($descriptorPacked & 0x80) !== 0) {
                $offset += 3 * (1 << (($descriptorPacked & 0x07) + 1));
            }
            if ($offset >= $length) return false;
            $codeSize = ord($bytes[$offset++]);
            if ($codeSize < 2 || $codeSize > 12) return false;
            $offset = $this->gif_sub_blocks_end($bytes, $offset, $imageData);
            if ($offset < 0 || !$imageData) return false;
            $sawImage = true;
        }
        return false;
    }

    private function gif_sub_blocks_end(string $bytes, int $offset, ?bool &$hadData): int {
        $length = strlen($bytes);
        $hadData = false;
        while ($offset < $length) {
            $size = ord($bytes[$offset++]);
            if ($size === 0) return $offset;
            $hadData = true;
            if ($size > $length - $offset) return -1;
            $offset += $size;
        }
        return -1;
    }

    /** @return array{int,int} */
    private function assert_bounded_source_image(string $stageFile): array {
        $dimensions = getimagesize($stageFile);
        $width = is_array($dimensions) ? ($dimensions[0] ?? null) : null;
        $height = is_array($dimensions) ? ($dimensions[1] ?? null) : null;
        if (!is_int($width)
            || !is_int($height)
            || $width <= 0
            || $height <= 0
            || $width > self::MAX_IMAGE_DIMENSION
            || $height > self::MAX_IMAGE_DIMENSION
            || $width * $height > self::MAX_SOURCE_PIXELS) {
            throw new \RuntimeException(
                'duo: native attachment metadata source dimensions exceed the bounded GD pixel authority'
            );
        }
        return [$width, $height];
    }

    private function assert_stage_file(string $path): void {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)
            || is_link($path)
            || !is_file($path)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || !is_int($stat['size'] ?? null)
            || $stat['size'] < 0
            || $stat['size'] > 268435456) {
            throw new \RuntimeException('duo: native attachment metadata staging input is missing, special, or oversized');
        }
    }

    private static function bounded_name_fingerprint(string $name): string {
        return 'bytes=' . strlen($name) . ',sha256=' . substr(hash('sha256', $name), 0, 16);
    }

    private static function failure_fingerprint(\Throwable $failure): string {
        return get_class($failure) . ':' . strlen($failure->getMessage()) . ':'
            . substr(hash('sha256', $failure->getMessage()), 0, 16);
    }
}
