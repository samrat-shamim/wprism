<?php
declare(strict_types=1);

namespace WPrism;

if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
if (!class_exists(PlainData::class, false)) {
    require_once __DIR__ . '/../Kernel/PlainData.php';
}
require_once __DIR__ . '/../Kernel/NativeDatabaseProfile.php';
if (!class_exists(DeleteGuardEvaluator::class, false)) {
    require_once __DIR__ . '/../Delete/DeleteGuardEvaluator.php';
}
require_once __DIR__ . '/../Kernel/MediaPayloadAuthority.php';
if (!class_exists(CompiledRepository::class, false)) {
    require_once __DIR__ . '/../Repository/CompiledArtifact.php';
}
if (!class_exists(AttachmentFilesystemTransaction::class, false)) {
    require_once __DIR__ . '/AttachmentFilesystemTransaction.php';
}
if (!class_exists(AttachmentMaterializer::class, false)) {
    require_once __DIR__ . '/AttachmentMaterializer.php';
}

/**
 * Materializer-owned native metadata authority. The compiled artifact and
 * durable filesystem transaction stay behind this capability; callers cannot
 * replace them with hashes or a synthetic attempt reader.
 */
final class AttachmentNativeMetadataAuthority {
    private function __construct(
        private readonly ?CompiledRepository $compiled,
        private readonly ?AttachmentFilesystemTransaction $filesystem,
        private readonly array $adapterManifests,
        private readonly \Closure $lockTarget
    ) {}

    public static function from_materializer(
        AttachmentMaterializer $owner,
        object $secret,
        CompiledRepository $compiled,
        AttachmentFilesystemTransaction $filesystem,
        array $adapterManifests,
        \Closure $lockTarget
    ): self {
        $owner->assert_native_authority_secret($secret);
        return new self($compiled, $filesystem, $adapterManifests, $lockTarget);
    }

    /** @return list<string> */
    public function adapter_manifests(): array {
        return $this->adapterManifests;
    }

    public function lock_target(): \Closure {
        return $this->lockTarget;
    }

    public function compiled_artifact_hash(): ?string {
        return $this->compiled?->artifact_hash();
    }

    public function compiled_manifest_hash(): ?string {
        return $this->compiled?->manifest_hash();
    }

    /** @return ?array{intent_id:string,artifact_hash:string,roster_hash:string,manifest_hash:string} */
    public function post_commit_context(): ?array {
        if ($this->compiled === null || $this->filesystem === null || !in_array($this->filesystem->phase(), [
            'originals_published', 'generating_metadata', 'metadata_generated',
            'publishing_derivatives', 'derivatives_published', 'metadata_committing',
            'metadata_committed', 'removing_stale', 'complete',
        ], true)) {
            return null;
        }
        $attempt = $this->filesystem->attempt_identity();
        if ($attempt === null) return null;
        return [
            'intent_id' => $attempt['intent_id'],
            'artifact_hash' => $attempt['artifact_hash'],
            'roster_hash' => $attempt['roster_hash'],
            'manifest_hash' => $this->compiled->manifest_hash(),
        ];
    }
}

/**
 * Runs WordPress's attachment metadata generator against an isolated file.
 *
 * Core 6.9.2/7.0.3/7.1 saves partial image metadata after every generated
 * sub-size. A throw or process loss can therefore otherwise leave both files
 * and postmeta half-updated. WPrism runs the reviewed core generator inside a
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
    private bool $polylangNoLanguagesObserved = false;
    private bool $markerlessPreflightActive = false;
    private bool $markerlessProofAvailable = false;
    private bool $nativeRebuildHandoffConsumed = false;
    /** @var array<int,true> */
    private array $nativeRebuildAuthorizedAttachmentIds = [];
    private ?int $currentAttachmentId = null;
    private readonly AttachmentNativeMetadataAuthority $authority;
    private \Closure $lockTarget;
    /** @var list<string> */
    private array $adapterManifests;

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
        'polylang-post-meta-guard' => [
            'manifest' => 'polylang', 'presence' => 'conditional-accept',
            'hook' => 'update_post_metadata', 'priority' => 1, 'accepted_args' => 3,
            'kind' => 'instance', 'class' => 'PLL_Sync_Post_Metas',
            'method' => 'can_synchronize_metadata',
        ],
        'polylang-post-meta-witness' => [
            'manifest' => 'polylang', 'presence' => 'conditional-accept',
            'hook' => 'update_post_metadata', 'priority' => 999, 'accepted_args' => 5,
            'kind' => 'instance', 'class' => 'PLL_Sync_Post_Metas',
            'method' => 'update_metadata',
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
            'manifest' => 'woocommerce', 'presence' => 'required',
            'hook' => 'upload_dir', 'priority' => 10, 'accepted_args' => 1,
            'kind' => 'instance', 'class' => 'WC_Admin_Upload_Downloadable_Product',
            'method' => 'upload_dir',
        ],
        'woocommerce-download-filename' => [
            'manifest' => 'woocommerce', 'presence' => 'required',
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

    private function __construct(AttachmentNativeMetadataAuthority $authority) {
        $this->authority = $authority;
        $this->lockTarget = $authority->lock_target();
        $this->adapterManifests = $authority->adapter_manifests();
        if (!array_is_list($this->adapterManifests)
            || count($this->adapterManifests) > 32
            || count(array_unique($this->adapterManifests, SORT_STRING)) !== count($this->adapterManifests)) {
            throw new \RuntimeException('wprism: native attachment metadata adapter authority is malformed');
        }
        foreach ($this->adapterManifests as $manifest) {
            if (!is_string($manifest)
                || $manifest === ''
                || strlen($manifest) > 191
                || preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $manifest) !== 1) {
                throw new \RuntimeException('wprism: native attachment metadata adapter authority is malformed');
            }
        }
        if (in_array('polylang', $this->adapterManifests, true)
            && ($authority->compiled_artifact_hash() === null || $authority->compiled_manifest_hash() === null)) {
            throw new \RuntimeException('wprism: Polylang attachment metadata authority lacks its compiled artifact identity');
        }
    }

    public static function from_authority(AttachmentNativeMetadataAuthority $authority): self {
        return new self($authority);
    }

    public function has_polylang_no_language_handoff(): bool {
        return $this->markerlessProofAvailable && !$this->nativeRebuildHandoffConsumed;
    }

    public function __clone(): void {
        throw new \RuntimeException('wprism: native attachment metadata generator cannot be cloned');
    }

    /** @return array<string,mixed> */
    public function __serialize(): array {
        throw new \RuntimeException('wprism: native attachment metadata generator cannot be serialized');
    }

    /** @param array<string,mixed> $data */
    public function __unserialize(array $data): void {
        throw new \RuntimeException('wprism: native attachment metadata generator cannot be unserialized');
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
        $this->markerlessPreflightActive = true;
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
                    throw new \RuntimeException('wprism: native attachment metadata preflight emitted excessive output');
                }
                return '';
            }, 4096);
            $this->load_core_runtime();
            $adapterQuarantine = $this->quarantine_reviewed_adapter_callbacks();
            $this->assert_polylang_sync_topology($adapterQuarantine['matched']);
            $this->assert_closed_filter_topology();
            $priorUmask = umask(0077);
            $classification = $this->classify_stage_file($stageFile, $mime);
            if ($classification['kind'] === 'raster') {
                [$sourceWidth, $sourceHeight] = $this->assert_media_environment($mime, $stageFile);
                $sizes = $this->registered_sizes_witness($sourceWidth, $sourceHeight);
                if (!hash_equals(
                    $sizes['hash'],
                    $this->registered_sizes_witness($sourceWidth, $sourceHeight)['hash']
                )) {
                    throw new \RuntimeException(
                        'wprism: native attachment metadata registered image-size roster changed during markerless preflight'
                    );
                }
            }
        } catch (\Throwable $failure) {
            $primary = $failure;
        } finally {
            $this->markerlessPreflightActive = false;
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
                'wprism: native attachment metadata markerless preflight failed; ' . implode('; ', $parts),
                0,
                $primary
            );
        }
    }

    /** @return array<mixed> bounded native metadata */
    public function generate(int $attachmentId, string $stageFile): array {
        global $wpdb;
        if ($attachmentId <= 0) {
            throw new \RuntimeException('wprism: native attachment metadata generation requires a positive attachment id');
        }
        $this->currentAttachmentId = $attachmentId;
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
                    'wprism: native attachment metadata attempted an undeclared intermediate metadata mutation'
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
                    'wprism: native attachment metadata reached an unexpected big-image threshold call shape'
                );
            }
            // The compiled _wp_attached_file and blob are authored identity.
            // Core's default large-image path changes that identity to
            // `-scaled`; disable only that derived replacement while retaining
            // ordinary registered sub-size generation in isolated staging.
            return false;
        };

        $transactionStarted = false;
        $transactionContinuityStarted = false;
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
            // WPrism failed to install its throwing boundary.
            $handlerInstalled = true;
            ob_start(static function (string $chunk) use (&$outputBytes): string {
                $outputBytes += strlen($chunk);
                if ($outputBytes > self::MAX_OUTPUT_BYTES) {
                    throw new \RuntimeException('wprism: native attachment metadata emitted excessive output');
                }
                return '';
            }, 4096);
            $this->load_core_runtime();
            $adapterQuarantine = $this->quarantine_reviewed_adapter_callbacks();
            $this->assert_closed_filter_topology();
            $priorUmask = umask(0077);
            Db::start_repeatable_read(
                'native attachment metadata rollback-only transaction start',
                NativeDatabaseProfile::read_only([
                    $wpdb->posts,
                    $wpdb->postmeta,
                    $wpdb->prefix . 'wprism_map',
                ])
            );
            $transactionStarted = true;
            DeleteGuardEvaluator::begin_authored_transaction();
            $transactionContinuityStarted = true;
            $mime = ($this->lockTarget)($attachmentId);
            if (!is_string($mime) || $mime === '' || strlen($mime) > 191) {
                throw new \RuntimeException('wprism: native attachment metadata target lock returned a malformed MIME type');
            }
            $lockedClassification = $this->classify_stage_file($stageFile, $mime);
            $lockedSizes = ['hash' => hash('sha256', serialize([])), 'sizes' => []];
            if ($lockedClassification['kind'] === 'raster') {
                [$lockedWidth, $lockedHeight] = $this->assert_media_environment($mime, $stageFile);
                $lockedSizes = $this->registered_sizes_witness($lockedWidth, $lockedHeight);
                $repeatClassification = $this->classify_stage_file($stageFile, $mime);
                $registeredSizesWitness = $this->registered_sizes_witness($lockedWidth, $lockedHeight);
                if ($repeatClassification !== $lockedClassification
                    || !hash_equals($registeredSizesWitness['hash'], $lockedSizes['hash'])) {
                    throw new \RuntimeException(
                        'wprism: native attachment metadata source or size roster changed at its transaction boundary'
                    );
                }
            }
            if (!add_filter('update_post_metadata', $guard, PHP_INT_MIN, 5)) {
                throw new \RuntimeException('wprism: native attachment metadata could not install its no-write guard');
            }
            $guardInstalled = true;
            $this->assert_guard_topology($guard);
            if ($lockedClassification['kind'] === 'raster') {
                if (!add_filter('big_image_size_threshold', $bigImageGuard, PHP_INT_MIN, 4)) {
                    throw new \RuntimeException('wprism: native attachment metadata could not install its identity-preserving big-image guard');
                }
                $bigImageGuardInstalled = true;
                $this->assert_single_guard_topology('big_image_size_threshold', $bigImageGuard, 4);
                $metadata = wp_generate_attachment_metadata($attachmentId, $stageFile);
                if ($metadata === false || is_wp_error($metadata) || !is_array($metadata)) {
                    throw new \RuntimeException('wprism: native attachment metadata generator reported failure');
                }
            } else {
                // Exact Core 6.9.2-7.1 generic branch: no delegate, only the
                // sealed filesize before the closed metadata filter.
                $metadata = ['filesize' => $lockedClassification['size']];
            }
            $metadata = $this->apply_quarantined_adapter_projection(
                $metadata,
                $lockedSizes['sizes'],
                $adapterQuarantine
            );
            PlainData::assert($metadata, 'native attachment metadata result');
            $encoded = serialize($metadata);
            if (strlen($encoded) > self::MAX_METADATA_BYTES) {
                throw new \RuntimeException('wprism: native attachment metadata result exceeds its bounded byte limit');
            }
            $this->assert_guard_topology($guard);
            if ($bigImageGuardInstalled) {
                $this->assert_single_guard_topology('big_image_size_threshold', $bigImageGuard, 4);
            }
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
            if ($transactionContinuityStarted) {
                try {
                    DeleteGuardEvaluator::end_authored_transaction();
                } catch (\Throwable $failure) {
                    $cleanupFailures[] = 'transaction-continuity=' . self::failure_fingerprint($failure);
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
                'wprism: native attachment metadata generation failed in its isolated staging boundary; '
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
                throw new \RuntimeException('wprism: native attachment metadata runtime is incomplete');
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
            throw new \RuntimeException('wprism: native attachment metadata filter registry is malformed');
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
                    'wprism: native attachment metadata refuses an unreviewed callback topology ('
                    . self::bounded_name_fingerprint($hook) . ')'
                );
            }
            foreach ($rows as $priority => $entries) {
                if (!is_int($priority) || !is_array($entries) || $entries === []) {
                    throw new \RuntimeException(
                        'wprism: native attachment metadata refuses a malformed callback topology ('
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
                            'wprism: native attachment metadata refuses a malformed callback entry ('
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
                            'wprism: native attachment metadata refuses an unreviewed callback topology ('
                            . self::bounded_name_fingerprint($hook) . ')'
                        );
                    }
                    $id = $matches[0];
                    if ((self::ADAPTER_CALLBACKS[$id]['presence'] ?? null) === 'conditional-refuse') {
                        throw new \RuntimeException(
                            'wprism: native attachment metadata refuses a request-conditional certified-adapter callback topology ('
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
                    'wprism: native attachment metadata lacks a required certified-adapter callback topology ('
                    . self::bounded_name_fingerprint((string) ($rule['hook'] ?? '')) . ')'
                );
            }
        }
        $this->assert_woocommerce_download_topology($callbacks);
        $this->assert_polylang_sync_topology($matched);

        $removed = [];
        try {
            foreach ($callbacks as $row) {
                if (!remove_filter($row['hook'], $row['callback'], $row['priority'])) {
                    throw new \RuntimeException(
                        'wprism: native attachment metadata could not quarantine a certified-adapter callback'
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
                'wprism: native attachment metadata could not establish its certified-adapter callback isolation; '
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
     * Exact WooCommerce 11.0.0/11.0.1 admin bootstrap registers this pair on
     * one object in every WP-CLI process (class source sha256 9429ae47…). The
     * filters mutate paths only for the downloadable-product request, which a
     * WPrism apply must never impersonate; the ordinary empty CLI request is
     * safe only after both callbacks are quarantined as one closed unit.
     *
     * @param list<array{hook:string,priority:int,accepted_args:int,callback:callable,rule:string}> $callbacks
     */
    private function assert_woocommerce_download_topology(array $callbacks): void {
        if (!in_array('woocommerce', $this->adapterManifests, true)) return;
        $pair = [];
        foreach ($callbacks as $row) {
            if (in_array($row['rule'], [
                'woocommerce-download-upload-dir',
                'woocommerce-download-filename',
            ], true)) {
                $pair[$row['rule']] = $row['callback'];
            }
        }
        if (array_keys($pair) !== [
            'woocommerce-download-upload-dir',
            'woocommerce-download-filename',
        ]) {
            throw new \RuntimeException(
                'wprism: native attachment metadata refuses a partial WooCommerce downloadable-upload callback topology'
            );
        }
        $upload = $pair['woocommerce-download-upload-dir'];
        $filename = $pair['woocommerce-download-filename'];
        if (!is_array($upload)
            || !is_object($upload[0] ?? null)
            || !is_array($filename)
            || ($filename[0] ?? null) !== $upload[0]) {
            throw new \RuntimeException(
                'wprism: native attachment metadata refuses split WooCommerce downloadable-upload callback authority'
            );
        }
        // Superglobals are mutable process state: read through the dynamic
        // registry so a plugin cannot replace the request map and inherit
        // PHP's compile-time array assumption at this safety boundary.
        $postRegistryKey = '_POST';
        $request = $GLOBALS[$postRegistryKey] ?? null;
        if (!is_array($request)) {
            throw new \RuntimeException(
                'wprism: native attachment metadata cannot audit WooCommerce downloadable-upload request state'
            );
        }
        if (array_key_exists('type', $request)) {
            throw new \RuntimeException(
                'wprism: native attachment metadata refuses an active WooCommerce downloadable-upload request topology'
            );
        }
    }

    /**
     * Polylang registers both sync callbacks only after its model reports at
     * least one language. An absent pair is therefore admissible only when
     * the native model proves the bounded no-language state; one missing
     * callback is never a valid intermediate topology.
     */
    private function assert_polylang_no_languages(): void {
        if ($this->markerlessProofAvailable && !$this->markerlessPreflightActive) {
            $this->consume_polylang_no_language_handoff();
            $this->polylangNoLanguagesObserved = true;
            return;
        }
        if ($this->polylangNoLanguagesObserved) {
            return;
        }
        if (!function_exists('PLL')) {
            throw new \RuntimeException(
                'wprism: native attachment metadata cannot prove Polylang no-language state'
            );
        }
        $runtime = PLL();
        $model = is_object($runtime) ? ($runtime->model ?? null) : null;
        if (!is_object($model) || !is_callable([$model, 'has_languages'])) {
            throw new \RuntimeException(
                'wprism: native attachment metadata cannot audit Polylang language state'
            );
        }
        $hasLanguages = $model->has_languages();
        if (!is_bool($hasLanguages) || $hasLanguages) {
            throw new \RuntimeException(
                'wprism: native attachment metadata refuses absent Polylang sync callbacks while languages are present'
            );
        }
        $this->polylangNoLanguagesObserved = true;
        if ($this->markerlessPreflightActive) {
            $this->markerlessProofAvailable = true;
        }
    }

    private function consume_polylang_no_language_handoff(): void {
        if ($this->nativeRebuildHandoffConsumed) {
            if ($this->currentAttachmentId === null
                || isset($this->nativeRebuildAuthorizedAttachmentIds[$this->currentAttachmentId])) {
                throw new \RuntimeException('wprism: Polylang no-language handoff was replayed for the same attachment attempt');
            }
            $this->nativeRebuildAuthorizedAttachmentIds[$this->currentAttachmentId] = true;
            return;
        }
        $context = $this->authority->post_commit_context();
        if (!is_array($context)) {
            throw new \RuntimeException('wprism: Polylang no-language handoff is not sealed to a post-commit attachment attempt');
        }
        self::assert_attempt_context($context);
        $compiledArtifactHash = $this->authority->compiled_artifact_hash();
        if ($compiledArtifactHash !== null
            && !hash_equals($compiledArtifactHash, $context['artifact_hash'])) {
            throw new \RuntimeException('wprism: Polylang no-language handoff does not match the compiled artifact identity');
        }
        $compiledManifestHash = $this->authority->compiled_manifest_hash();
        if ($compiledManifestHash !== null
            && !hash_equals($compiledManifestHash, $context['manifest_hash'])) {
            throw new \RuntimeException('wprism: Polylang no-language handoff does not match the compiled manifest identity');
        }
        $this->nativeRebuildHandoffConsumed = true;
        if ($this->currentAttachmentId !== null) {
            $this->nativeRebuildAuthorizedAttachmentIds[$this->currentAttachmentId] = true;
        }
    }

    /** @param array<string,mixed> $context */
    private static function assert_attempt_context(array $context): void {
        if (array_keys($context) !== ['intent_id', 'artifact_hash', 'roster_hash', 'manifest_hash']
            || preg_match('/^[0-9a-f]{32}$/D', (string) ($context['intent_id'] ?? '')) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', (string) ($context['artifact_hash'] ?? '')) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', (string) ($context['roster_hash'] ?? '')) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', (string) ($context['manifest_hash'] ?? '')) !== 1) {
            throw new \RuntimeException('wprism: Polylang no-language handoff attempt identity is malformed');
        }
    }

    /** @param array<string,true> $matched */
    private function assert_polylang_sync_topology(array $matched): void {
        if (!in_array('polylang', $this->adapterManifests, true)) return;
        $syncIds = ['polylang-post-meta-guard', 'polylang-post-meta-witness'];
        $missing = array_values(array_diff($syncIds, array_keys($matched)));
        if ($missing === []) return;
        if (count($missing) !== count($syncIds)) {
            throw new \RuntimeException(
                'wprism: native attachment metadata refuses a partial Polylang post-meta callback topology'
            );
        }
        $this->assert_polylang_no_languages();
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
                'wprism: native attachment metadata cannot reproduce WooCommerce uncropped size authority'
            );
        }
        $metadataSize['uncropped'] = $size['height'] === 0;
        $metadata['sizes']['woocommerce_thumbnail'] = $metadataSize;
        return $metadata;
    }

    private function assert_closed_filter_topology(): void {
        global $wp_filter;
        if (!is_array($wp_filter)) {
            throw new \RuntimeException('wprism: native attachment metadata filter registry is malformed');
        }
        foreach (self::CLOSED_FILTERS as $hook) {
            if (!isset($wp_filter[$hook])) continue;
            $callbacks = is_object($wp_filter[$hook]) && property_exists($wp_filter[$hook], 'callbacks')
                ? $wp_filter[$hook]->callbacks
                : null;
            if (!is_array($callbacks) || $callbacks !== []) {
                throw new \RuntimeException(
                    'wprism: native attachment metadata refuses an unreviewed callback topology ('
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
                    'wprism: native attachment metadata refuses an unreviewed image-size option callback topology ('
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
            throw new \RuntimeException('wprism: native attachment metadata no-write guard topology changed');
        }
        $entry = array_values($priority)[0];
        if (!is_array($entry)
            || ($entry['function'] ?? null) !== $guard
            || ($entry['accepted_args'] ?? null) !== 5) {
            throw new \RuntimeException('wprism: native attachment metadata no-write guard identity changed');
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
            throw new \RuntimeException('wprism: native attachment metadata guard topology changed');
        }
        $entry = array_values($priority)[0];
        if (!is_array($entry)
            || ($entry['function'] ?? null) !== $guard
            || ($entry['accepted_args'] ?? null) !== $acceptedArgs) {
            throw new \RuntimeException('wprism: native attachment metadata guard identity changed');
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
            throw new \RuntimeException('wprism: native attachment metadata registered image-size roster is malformed or oversized');
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
                throw new \RuntimeException('wprism: native attachment metadata registered image-size row is malformed');
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
                    'wprism: native attachment metadata registered image sizes exceed their aggregate pixel-work bound'
                );
            }
            $outputPixels += $pixels;
        }
        if ($aggregate > 32768) {
            throw new \RuntimeException('wprism: native attachment metadata registered image-size names exceed their byte bound');
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
            throw new \RuntimeException('wprism: native attachment metadata received a malformed MIME authority');
        }
        $classification = $this->classify_stage_file($stageFile, $mime);
        if ($classification['kind'] !== 'raster') {
            throw new \RuntimeException('wprism: native attachment metadata expected a reviewed raster authority');
        }
        if (!$this->displayable_image($mime, $stageFile)) {
            throw new \RuntimeException('wprism: native attachment metadata refuses a non-displayable raster image');
        }
        $dimensions = $this->assert_bounded_source_image($stageFile);
        $editor = wp_get_image_editor($stageFile);
        if (is_wp_error($editor)) {
            throw new \RuntimeException('wprism: native attachment metadata could not select a core image editor');
        }
        if (get_class($editor) !== 'WP_Image_Editor_GD') {
            throw new \RuntimeException(
                'wprism: native attachment metadata refuses a non-GD image editor; '
                . 'multi-frame/delegate work is outside the bounded pixel authority'
            );
        }
        return $dimensions;
    }

    /** @return array{extension:string,kind:string,mime:string,size:int} */
    private function classify_stage_file(string $stageFile, string $mime): array {
        try {
            return MediaPayloadAuthority::classifyFile($stageFile, basename($stageFile), $mime);
        } catch (\Throwable $failure) {
            if (str_contains($failure->getMessage(), 'raster container')) {
                throw new \RuntimeException(
                    'wprism: native attachment metadata image container is truncated, malformed, or carries trailing bytes',
                    0,
                    $failure
                );
            }
            if (str_contains($failure->getMessage(), 'raster dimensions or decoder MIME exceed')) {
                throw new \RuntimeException(
                    'wprism: native attachment metadata source dimensions exceed the bounded GD pixel authority',
                    0,
                    $failure
                );
            }
            if (str_contains($failure->getMessage(), 'unbounded Core image, audio, video, or PDF metadata branch')) {
                throw new \RuntimeException(
                    'wprism: native attachment metadata refuses an unsupported or MIME/extension-mismatched media class',
                    0,
                    $failure
                );
            }
            throw $failure;
        }
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
                'wprism: native attachment metadata source dimensions exceed the bounded GD pixel authority'
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
            || $stat['size'] > MediaPayloadAuthority::MAX_FILE_BYTES) {
            throw new \RuntimeException('wprism: native attachment metadata staging input is missing, special, or oversized');
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
