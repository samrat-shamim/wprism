<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/ApplyFieldMaterializer.php';
require_once __DIR__ . '/AttachmentFilesystemTransaction.php';
require_once __DIR__ . '/AttachmentNativeMetadataGenerator.php';
require_once __DIR__ . '/CacheInvalidationTransaction.php';
require_once __DIR__ . '/MetaOwnerRangeLock.php';
require_once __DIR__ . '/../Delete/DeleteGuardEvaluator.php';
require_once __DIR__ . '/../Kernel/MetaRows.php';
require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Policy/Policy.php';
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}
// Deliberately NOT require_once('CompiledArtifact.php') here (the file that
// declares CompiledRepository): sandbox/tests/offline/code-half/regress_code_revision_enforcement.php
// stubs a fake Duo\CompiledRepository and reaches this file transitively
// through Apply.php (a direct require_once, verified) without ever loading
// the real CompiledArtifact.php; requiring it here fatals that suite with
// "Cannot redeclare class Duo\CompiledRepository" (caught by
// regress-offline-all while verifying this file). Checked individually,
// not assumed: no other sandbox/tests/*.php suite fakes CompiledRepository
// (grep -rlE '^\s*(final\s+)?class\s+CompiledRepository\s*(\{|extends|implements)'
// sandbox/tests/*.php returns exactly this one match; three .sh suites also
// fake it -- regress_code_compatibility.sh, regress_code_stage_lock_unit.sh,
// regress_code_stage_transaction_unit.sh -- but none of the three loads
// Apply.php at all, so none reaches this file either way). The other class
// this file references, Canon, is faked by exactly one file
// (regress_cli_json_refusals.php), which structurally cannot load the real
// Apply.php regardless of its require list: it declares its own fake
// `final class Apply` (own line ~59), so any attempt to also load the real
// Apply.php would itself fatal on redeclaration first -- the same guarantee
// this exclusion relies on, not merely an empirical trace of what its
// requires currently happen to reach. Canon.php is safely required directly
// below.

/**
 * Owns the database/filesystem split required by portable attachments.
 *
 * The compiled upload inventory is the only authority for an original file.
 * The authored transaction locks and reconciles the exact attachment row,
 * UUID/file sidecars, the global `_wp_attached_file` collision range, and exact
 * native metadata/edited-backup ownership, then durably seals that
 * UUID-to-post-ID authority before COMMIT. Originals and native
 * derivatives publish only after the database outcome is proven; a bounded
 * journal plus exact marker phase makes every crash boundary resumable without
 * inferring ownership from a filename prefix. Native metadata generation is
 * isolated in staging under a closed WordPress core/editor topology, and its
 * exact file inventory and normalized metadata commit in a second classified
 * transaction before stale, prior-metadata-owned derivatives may be removed.
 *
 * This class deliberately coordinates those boundaries instead of treating a
 * binary write or `_wp_attachment_metadata` update as an ordinary field
 * materialization: filesystem rename is not rolled back by InnoDB, WordPress
 * metadata generation creates multiple files and intermediate writes, and a
 * recycled ledger ID must never authorize either. The immutable compiled
 * repository, frozen manifest policy and shared locked-meta writer are
 * therefore its complete constructor contract. Value/token interpretation has
 * already ended; policy remains only to bind the exact certified callback
 * families that the native staging boundary must isolate.
 */
final class AttachmentMaterializer {
    private ?AttachmentNativeMetadataGenerator $polylangNativeGenerator = null;
    private const ATTACHED_FILE_KEY = '_wp_attached_file';
    private const FILESYSTEM_MARKER_PREFIX = 'attachment_fs:';
    private const MAX_FILESYSTEM_MARKERS = 1;
    private const MAX_FILESYSTEM_MARKER_BYTES = 512;
    private const MAX_ATTACHED_FILE_ROWS = 1000000;
    private const ATTACHED_FILE_LOCK_CHUNK = 512;
    private const MAX_UPLOAD_PATH_BYTES = 1024;
    private readonly AttachmentFilesystemTransaction $filesystem;
    private ?string $attachedFileLockIndex = null;

    public function __construct(
        private readonly Policy $policy,
        private readonly ApplyFieldMaterializer $fieldMaterializer,
        private readonly CompiledRepository $compiled,
        string $repositoryRoot
    ) {
        $this->filesystem = new AttachmentFilesystemTransaction($compiled, $repositoryRoot);
    }

    /** Recover a committed upload publication before target capture. */
    public function recover_pending_filesystem(): void {
        $this->polylangNativeGenerator = null;
        if ($this->filesystem->phase() === null) {
            $this->load_pending_filesystem();
        } else {
            $this->assert_pending_marker_inventory();
        }
        $identity = $this->filesystem->pending_marker_identity();
        if ($identity === null) return;
        $marker = Ledger::kv_get($identity['key']);
        try {
            if ($marker === null) {
                $this->filesystem->recover_pending_with_marker(null);
                return;
            }
            $this->with_locked_pending_bindings(
                fn(): mixed => $this->filesystem->recover_pending_with_marker($marker),
                'attachment durable recovery identity proof'
            );
        } catch (\Throwable $failure) {
            $this->filesystem->end();
            throw $failure;
        }
    }

    /** Load-only scope gate: a scoped apply must never resume a full upload intent. */
    public function load_pending_filesystem(bool $retainLocks = true): bool {
        $this->polylangNativeGenerator = null;
        try {
            $this->filesystem->load_pending();
            $this->assert_pending_marker_inventory();
            $pending = $this->filesystem->phase() !== null;
            if ($pending && !$retainLocks) {
                $this->filesystem->end();
            }
            return $pending;
        } catch (\Throwable $failure) {
            $this->filesystem->end();
            throw $failure;
        }
    }

    /**
     * Bind the private control journal to the complete raw database marker
     * inventory before target capture. `duo_kv` values are not option-cache
     * state, and a marker whose journal was lost or moved is unresolved
     * post-COMMIT authority rather than permission to start a new apply.
     */
    private function assert_pending_marker_inventory(): void {
        $markers = $this->filesystem_marker_inventory();
        $journal = $this->filesystem->pending_marker_identity();
        if ($journal === null) {
            if ($markers !== []) {
                throw new \RuntimeException(
                    'duo: attachment database marker has no matching private control journal; recovery_required'
                );
            }
            return;
        }
        if ($markers !== [] && !array_key_exists($journal['key'], $markers)) {
            throw new \RuntimeException(
                'duo: attachment database marker and private control journal identities disagree; recovery_required'
            );
        }
    }

    /** @return array<string,string> exact marker key => bounded marker bytes */
    private function filesystem_marker_inventory(): array {
        global $wpdb;
        $table = (string) $wpdb->prefix . 'duo_kv';
        DeleteGuardEvaluator::assert_table_identifiers(
            [$table],
            'attachment durable marker inventory'
        );
        $prefixBytes = strlen(self::FILESYSTEM_MARKER_PREFIX);
        $limit = self::MAX_FILESYSTEM_MARKERS + 1;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT k, OCTET_LENGTH(v) AS v_bytes, '
                . 'CASE WHEN v IS NOT NULL AND OCTET_LENGTH(v) <= %d THEN v ELSE NULL END AS bounded_v '
                . "FROM `$table` WHERE LOWER(LEFT(k, %d)) = %s "
                . "ORDER BY BINARY k ASC LIMIT $limit",
            self::MAX_FILESYSTEM_MARKER_BYTES,
            $prefixBytes,
            self::FILESYSTEM_MARKER_PREFIX
        ), ARRAY_A);
        if (!is_array($rows)
            || !array_is_list($rows)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('duo: attachment durable marker inventory read failed');
        }
        if (count($rows) > self::MAX_FILESYSTEM_MARKERS) {
            throw new \RuntimeException(
                'duo: attachment durable marker inventory contains multiple pending authorities; recovery_required'
            );
        }
        $out = [];
        foreach ($rows as $position => $row) {
            $bytes = is_array($row)
                ? $this->canonical_nonnegative_driver_integer($row['v_bytes'] ?? null)
                : null;
            $key = is_array($row) ? ($row['k'] ?? null) : null;
            $value = is_array($row) ? ($row['bounded_v'] ?? null) : null;
            if (!is_array($row)
                || array_keys($row) !== ['k', 'v_bytes', 'bounded_v']
                || !is_string($key)
                || preg_match('/^attachment_fs:([0-9a-f]{32})$/D', $key, $keyMatch) !== 1
                || $bytes === null
                || $bytes > self::MAX_FILESYSTEM_MARKER_BYTES
                || !is_string($value)
                || strlen($value) !== $bytes
                || preg_match(
                    '#^duo-attachment-filesystem-transaction/v1:([0-9a-f]{32}):'
                        . '(?:[0-9a-f]{64}|metadata:[0-9a-f]{64}:[0-9a-f]{64})$#D',
                    $value,
                    $valueMatch
                ) !== 1
                || !hash_equals($keyMatch[1], $valueMatch[1])) {
                throw new \RuntimeException(
                    "duo: attachment durable marker inventory returned a malformed row at bounded position $position"
                );
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $work */
    public function prepare_filesystem(array $work, array $tree): void {
        // The witness belongs to this one markerless-to-post-commit transition;
        // a retry must re-prove the target before accepting an absent callback pair.
        $this->polylangNativeGenerator = null;
        $generator = new AttachmentNativeMetadataGenerator(
            fn(int $id): string => $this->assert_locked_pending_binding($id),
            $this->attachment_adapter_manifests(),
            $this->compiled->artifact_hash(),
            $this->compiled->manifest_hash(),
            fn(): ?array => $this->polylang_proof_context()
        );
        $this->filesystem->prepare(
            $work,
            $tree,
            $generator
        );
        $this->polylangNativeGenerator = !$generator->has_polylang_no_language_handoff()
            ? null
            : $generator;
    }

    /** Seal the exact UUID-to-post-ID mapping inside the authored DB transaction. */
    public function seal_authored_transaction(): void {
        $identity = $this->filesystem->seal_authored_transaction();
        if ($identity !== null) {
            Ledger::kv_set($identity['key'], $identity['value']);
        }
    }

    /** Publish originals only after the authored database commit is confirmed. */
    public function commit_authored_transaction(): void {
        $identity = $this->filesystem->pending_marker_identity();
        $marker = $identity === null ? null : Ledger::kv_get($identity['key']);
        if ($identity === null) return;
        $this->with_locked_pending_bindings(
            fn(): mixed => $this->filesystem->commit_authored_transaction($marker),
            'attachment original publication identity proof'
        );
    }

    /** Cleanup preparation only after the authored database rollback is confirmed. */
    public function rollback_authored_transaction(): void {
        try {
            $identity = $this->filesystem->pending_marker_identity();
            $marker = $identity === null ? null : Ledger::kv_get($identity['key']);
            $this->filesystem->rollback_authored_transaction($marker);
        } finally {
            $this->polylangNativeGenerator = null;
        }
    }

    /** Discard a markerless prepare that failed before START established apply authority. */
    public function rollback_prepared_filesystem(): void {
        try {
            if (!in_array($this->filesystem->phase(), ['preparing', 'prepared'], true)) return;
            $this->filesystem->rollback_authored_transaction(null);
        } finally {
            $this->polylangNativeGenerator = null;
        }
    }

    /** @return list<int> */
    public function pending_attachment_ids(): array {
        return $this->filesystem->pending_attachment_ids();
    }

    /** Drop a post-commit handoff when no native rebuild will consume it. */
    public function discard_native_rebuild_authority(): void {
        $this->polylangNativeGenerator = null;
    }

    public function end_authored_transaction(bool $retainNativeRebuildAuthority = false): void {
        // Destination locks deliberately span the authored COMMIT and the
        // later native metadata phase. finalize_native_metadata() releases
        // them after terminal cleanup (or before surfacing a retryable fault).
        if ($this->filesystem->phase() === null) {
            $this->filesystem->end();
        }
        $this->attachedFileLockIndex = null;
        if (!$retainNativeRebuildAuthority || $this->filesystem->phase() === null) {
            $this->polylangNativeGenerator = null;
        }
    }

    /**
     * Complete the durable post-COMMIT metadata/filesystem state machine.
     *
     * @param list<int|null> $attachmentIds
     */
    public function finalize_native_metadata(array $attachmentIds): void {
        $pending = $this->filesystem->pending_attachment_ids();
        if ($pending === []) {
            $this->polylangNativeGenerator = null;
            return;
        }
        $provided = array_values(array_unique(array_filter($attachmentIds, static fn(mixed $id): bool => is_int($id) && $id > 0)));
        sort($provided, SORT_NUMERIC);
        if ($provided !== $pending) {
            throw new \RuntimeException('duo: attachment native rebuild roster disagrees with the durable UUID-to-post-ID authority');
        }
        CacheInvalidationTransaction::assert_local_cache('native attachment metadata finalization');
        try {
            $generator = $this->polylangNativeGenerator;
            if ($generator === null) {
                $generator = new AttachmentNativeMetadataGenerator(
                    fn(int $id): string => $this->assert_locked_pending_binding($id),
                    $this->attachment_adapter_manifests(),
                    $this->compiled->artifact_hash(),
                    $this->compiled->manifest_hash(),
                    fn(): ?array => $this->polylang_proof_context()
                );
            }
            $this->filesystem->generate_metadata($generator);
            $this->with_locked_pending_bindings(
                fn(): mixed => $this->filesystem->publish_derivatives(),
                'attachment derivative publication identity proof'
            );
            if (in_array($this->filesystem->phase(), ['derivatives_published', 'metadata_committing'], true)) {
                $this->persist_generated_metadata();
            }
            $metadataIdentity = $this->filesystem->metadata_marker_identity();
            if ($metadataIdentity === null) {
                throw new \RuntimeException('duo: attachment metadata finalization lacks a generated marker identity');
            }
            $marker = Ledger::kv_get($metadataIdentity['key']);
            if (in_array($this->filesystem->phase(), ['metadata_committed', 'removing_stale'], true)) {
                $this->with_locked_pending_bindings(
                    fn(): mixed => $this->filesystem->remove_stale_derivatives($marker),
                    'attachment stale derivative cleanup identity proof'
                );
            }
            if ($this->filesystem->phase() === 'complete') {
                $this->delete_terminal_marker($metadataIdentity['key'], $metadataIdentity['value']);
                $this->filesystem->cleanup_complete(Ledger::kv_get($metadataIdentity['key']));
            }
        } finally {
            $this->polylangNativeGenerator = null;
            $this->filesystem->end();
        }
    }

    public function place_attachment(int $id, array $front): void {
        global $wpdb;
        if ($id <= 0
            || !is_string($front['file'] ?? null)
            || !is_string($front['media'] ?? null)
            || !is_string($front['uuid'] ?? null)) {
            throw new \RuntimeException('duo: attachment materialization received a malformed compiled attachment');
        }
        $lock = $this->fieldMaterializer->meta_owner_range_lock(
            $wpdb->postmeta,
            'post_id',
            'attachment managed metadata row locking'
        );
        $this->assert_global_attached_file_authority($id, $front['file']);
        // _wp_attachment_metadata is rebuilt after COMMIT. Lock it now so a
        // concurrent admin cannot replace the old native projection between
        // authored attachment fields and the durable generation phase.
        $priorMetadataRows = $lock->exact_key_rows($id, '_wp_attachment_metadata');
        $priorAttachedRows = $lock->exact_key_rows($id, self::ATTACHED_FILE_KEY);
        $priorBackupRows = $lock->exact_key_rows($id, '_wp_attachment_backup_sizes');
        $ownedPriorPaths = $this->prior_native_owned_paths(
            $priorMetadataRows,
            $priorAttachedRows,
            $priorBackupRows
        );
        $this->assert_global_attached_file_authorities($id, $ownedPriorPaths);
        $this->filesystem->register_attachment($id, $front, $ownedPriorPaths);
        $desired = [
            self::ATTACHED_FILE_KEY => $front['file'],
            '_wp_attachment_image_alt' => (string) ($front['alt'] ?? ''),
        ];
        foreach ($desired as $key => $value) {
            $rows = $lock->exact_key_rows($id, $key);
            $firstId = null;
            foreach ($rows as $position => $row) {
                $metaId = MetaRows::positive_id($row['meta_id'] ?? null);
                if ($metaId === null) {
                    throw new \RuntimeException("duo: attachment metadata '$key' has a malformed locked row identity");
                }
                if ($position === 0) {
                    $firstId = $metaId;
                    continue;
                }
                Db::delete(
                    $wpdb->postmeta,
                    ['meta_id' => $metaId],
                    null,
                    "apply delete duplicate attachment meta $key"
                );
            }
            $this->fieldMaterializer->upsert_locked_authored_meta(
                $wpdb->postmeta,
                'post_id',
                $id,
                $key,
                $value,
                $firstId,
                "apply reconcile attachment meta $key"
            );
            $after = $lock->exact_key_rows($id, $key);
            if (count($after) !== 1
                || !is_string($after[0]['meta_value'] ?? null)
                || !hash_equals($value, $after[0]['meta_value'])) {
                throw new \RuntimeException("duo: attachment metadata '$key' lacks exact locked readback");
            }
        }
        foreach ($priorBackupRows as $row) {
            $metaId = MetaRows::positive_id($row['meta_id'] ?? null);
            if ($metaId === null) {
                throw new \RuntimeException('duo: attachment backup metadata has a malformed locked row identity');
            }
            Db::delete(
                $wpdb->postmeta,
                ['meta_id' => $metaId],
                null,
                'apply delete target-local attachment backup metadata after freezing its exact files'
            );
        }
        if ($lock->exact_key_rows($id, '_wp_attachment_backup_sizes') !== []) {
            throw new \RuntimeException('duo: attachment backup metadata deletion lacks exact locked readback');
        }
        $this->assert_global_attached_file_authority($id, $front['file']);
        CacheInvalidationTransaction::queue($id, 'post_meta', 'attachment managed metadata reconciliation');
        CacheInvalidationTransaction::queue_generation('posts', 'attachment managed metadata reconciliation');
    }

    /** Run one filesystem transition while its exact physical attachment rows are locked. */
    private function with_locked_pending_bindings(\Closure $operation, string $purpose): mixed {
        $started = false;
        $lockBoundaryStarted = false;
        try {
            Db::start_repeatable_read($purpose . ' transaction start');
            $started = true;
            // This wrapper runs after the authored COMMIT as a new physical
            // transaction. Its schema descriptors and continuity savepoint
            // must therefore be minted here rather than inherited from the
            // already-ended authored transaction.
            $this->fieldMaterializer->begin_authored_transaction();
            $lockBoundaryStarted = true;
            foreach ($this->filesystem->pending_bindings() as $binding) {
                $this->assert_locked_binding($binding, false, $purpose);
            }
            $result = $operation();
            Db::rollback($purpose . ' read-lock transaction rollback');
            $started = false;
            return $result;
        } catch (\Throwable $failure) {
            if ($started) {
                try {
                    Db::rollback_after_failure($failure, $purpose . ' read-lock recovery');
                } catch (\Throwable $rollbackFailure) {
                    throw new \RuntimeException(
                        'duo: attachment filesystem transition could not settle its identity-lock transaction; recovery_required; '
                        . 'original=' . self::failure_fingerprint($failure)
                        . '; rollback=' . self::failure_fingerprint($rollbackFailure),
                        0,
                        $failure
                    );
                }
            }
            throw $failure;
        } finally {
            if ($lockBoundaryStarted) {
                $this->fieldMaterializer->end_authored_transaction();
            }
        }
    }

    /** Called from the generator after it starts its own rollback-only transaction. */
    private function assert_locked_pending_binding(int $attachmentId): string {
        $matches = array_values(array_filter(
            $this->filesystem->pending_bindings(),
            static fn(array $binding): bool => $binding['attachment_id'] === $attachmentId
        ));
        if (count($matches) !== 1) {
            throw new \RuntimeException('duo: native attachment metadata target is absent or duplicate in durable authority');
        }
        return $this->assert_locked_binding(
            $matches[0],
            true,
            'native attachment metadata target identity proof'
        );
    }

    /**
     * Prove duo_map, primary row, exact UUID sidecar, and attached-file bytes
     * under one active repeatable-read transaction before any filesystem step.
     *
     * @param array{attachment_id:int,attachment_uuid:string,original_path:string} $binding
     */
    private function assert_locked_binding(array $binding, bool $refreshRuntime, string $purpose): string {
        global $wpdb;
        $id = $binding['attachment_id'];
        $uuid = $binding['attachment_uuid'];
        $file = $binding['original_path'];
        $mapTable = $wpdb->prefix . 'duo_map';
        DeleteGuardEvaluator::assert_table_identifiers([$wpdb->posts, $mapTable], $purpose);
        DeleteGuardEvaluator::assert_innodb_tables([$wpdb->posts, $mapTable], $purpose);
        DeleteGuardEvaluator::assert_transaction_isolation($purpose);

        $postIndex = DeleteGuardEvaluator::full_width_lock_index($wpdb->posts, 'ID', $purpose, true);
        $wpdb->last_error = '';
        $postRows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_type, post_mime_type FROM {$wpdb->posts} FORCE INDEX (`$postIndex`) "
                . 'WHERE ID = %d ORDER BY ID ASC LIMIT 2 FOR UPDATE',
            $id
        ), ARRAY_A);
        if (!is_array($postRows)
            || !array_is_list($postRows)
            || count($postRows) !== 1
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("duo: $purpose could not lock one exact attachment post row");
        }
        $post = $postRows[0];
        $postId = is_array($post) ? MetaRows::positive_id($post['ID'] ?? null) : null;
        $mime = is_array($post) ? ($post['post_mime_type'] ?? null) : null;
        if (!is_array($post)
            || array_keys($post) !== ['ID', 'post_type', 'post_mime_type']
            || $postId !== $id
            || ($post['post_type'] ?? null) !== 'attachment'
            || !is_string($mime)
            || $mime === ''
            || strlen($mime) > 191) {
            throw new \RuntimeException("duo: $purpose locked a malformed, recycled, or non-attachment post row");
        }

        $mapIndex = DeleteGuardEvaluator::full_width_lock_index($mapTable, 'uuid', $purpose);
        $wpdb->last_error = '';
        $mapRows = $wpdb->get_results($wpdb->prepare(
            "SELECT uuid, entity_type, id_kind, local_id FROM `$mapTable` FORCE INDEX (`$mapIndex`) "
                . 'WHERE uuid = %s ORDER BY id_kind ASC LIMIT 3 FOR UPDATE',
            $uuid
        ), ARRAY_A);
        if (!is_array($mapRows)
            || !array_is_list($mapRows)
            || count($mapRows) !== 1
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("duo: $purpose could not lock one exact attachment ledger mapping");
        }
        $map = $mapRows[0];
        if (!is_array($map)
            || array_keys($map) !== ['uuid', 'entity_type', 'id_kind', 'local_id']
            || !is_string($map['uuid'] ?? null)
            || !hash_equals($uuid, $map['uuid'])
            // PostMaterializer stores every wp_posts identity under the
            // canonical ledger entity_type `post`; physical post_type below
            // is the independent attachment discriminator.
            || ($map['entity_type'] ?? null) !== 'post'
            || ($map['id_kind'] ?? null) !== Ledger::KIND_POST
            || MetaRows::positive_id($map['local_id'] ?? null) !== $id) {
            throw new \RuntimeException("duo: $purpose found an aliased, stale, or recycled attachment ledger mapping");
        }

        $meta = MetaOwnerRangeLock::prepare(
            $wpdb->postmeta,
            'post_id',
            $purpose . ' metadata owner range'
        );
        $uuidRows = $meta->exact_key_rows($id, '_duo_uuid');
        $attachedRows = $meta->exact_key_rows($id, '_wp_attached_file');
        if (count($uuidRows) !== 1
            || !is_string($uuidRows[0]['meta_value'] ?? null)
            || !hash_equals($uuid, $uuidRows[0]['meta_value'])
            || count($attachedRows) !== 1
            || !is_string($attachedRows[0]['meta_value'] ?? null)
            || !hash_equals($file, $attachedRows[0]['meta_value'])) {
            throw new \RuntimeException("duo: $purpose attachment sidecar/file identity is missing, duplicate, or changed");
        }

        if ($refreshRuntime) {
            foreach ([[$id, 'posts'], [$id, 'post_meta']] as [$cacheKey, $cacheGroup]) {
                if (!function_exists('wp_cache_delete') || !function_exists('wp_cache_get')) {
                    throw new \RuntimeException("duo: $purpose lacks the required local WordPress cache primitives");
                }
                if (CacheInvalidationTransaction::is_active()) {
                    CacheInvalidationTransaction::queue($cacheKey, $cacheGroup, $purpose);
                } else {
                    wp_cache_delete($cacheKey, $cacheGroup);
                }
                $found = null;
                wp_cache_get($cacheKey, $cacheGroup, false, $found);
                if ($found !== false) {
                    throw new \RuntimeException("duo: $purpose could not prove the target runtime cache key absent");
                }
            }
            $runtime = get_post($id);
            if (!is_object($runtime)
                || (int) ($runtime->ID ?? 0) !== $id
                || ($runtime->post_type ?? null) !== 'attachment'
                || ($runtime->post_mime_type ?? null) !== $mime) {
                throw new \RuntimeException("duo: $purpose runtime attachment projection disagrees with locked storage");
            }
        }
        return $mime;
    }

    /** Persist exact generated metadata plus its distinct durable marker atomically. */
    private function persist_generated_metadata(): void {
        global $wpdb;
        $started = false;
        $committed = false;
        $primary = null;
        try {
            Db::start_repeatable_read('attachment metadata transaction start');
            $started = true;
            $this->fieldMaterializer->begin_authored_transaction();
            CacheInvalidationTransaction::begin();
            $desiredRows = $this->filesystem->generated_metadata_rows();
            foreach ($desiredRows as $desired) {
                $this->assert_locked_pending_binding((int) $desired['attachment_id']);
                $lock = $this->fieldMaterializer->meta_owner_range_lock(
                    $wpdb->postmeta,
                    'post_id',
                    'attachment generated metadata row locking'
                );
                $rows = $lock->exact_key_rows((int) $desired['attachment_id'], '_wp_attachment_metadata');
                $firstId = null;
                foreach ($rows as $position => $row) {
                    $metaId = MetaRows::positive_id($row['meta_id'] ?? null);
                    if ($metaId === null) {
                        throw new \RuntimeException('duo: attachment generated metadata has a malformed locked row identity');
                    }
                    if ($position === 0 && $desired['metadata'] !== null) {
                        $firstId = $metaId;
                        continue;
                    }
                    Db::delete(
                        $wpdb->postmeta,
                        ['meta_id' => $metaId],
                        null,
                        'apply delete stale or duplicate attachment native metadata'
                    );
                }
                if ($desired['metadata'] !== null) {
                    $this->fieldMaterializer->upsert_locked_authored_meta(
                        $wpdb->postmeta,
                        'post_id',
                        (int) $desired['attachment_id'],
                        '_wp_attachment_metadata',
                        $desired['metadata'],
                        $firstId,
                        'apply reconcile generated attachment native metadata'
                    );
                }
                $after = $lock->exact_key_rows((int) $desired['attachment_id'], '_wp_attachment_metadata');
                $exact = $desired['metadata'] === null
                    ? $after === []
                    : (count($after) === 1
                        && is_string($after[0]['meta_value'] ?? null)
                        && hash_equals($desired['metadata'], $after[0]['meta_value']));
                if (!$exact) {
                    throw new \RuntimeException('duo: attachment generated metadata lacks exact locked readback');
                }
                CacheInvalidationTransaction::queue(
                    (int) $desired['attachment_id'],
                    'post_meta',
                    'attachment generated metadata reconciliation'
                );
                CacheInvalidationTransaction::queue_generation(
                    'posts',
                    'attachment generated metadata reconciliation'
                );
            }
            $marker = $this->filesystem->seal_metadata_transaction();
            Ledger::kv_set($marker['key'], $marker['value']);
            if (!hash_equals($marker['value'], (string) Ledger::kv_get($marker['key']))) {
                throw new \RuntimeException('duo: attachment metadata marker lacks exact transactional readback');
            }
            $this->filesystem->assert_metadata_commit_files();
            DeleteGuardEvaluator::assert_transaction_isolation('attachment metadata final commit boundary');
            Db::commit('attachment metadata transaction commit');
            $started = false;
            $committed = true;
        } catch (\Throwable $failure) {
            $primary = $failure;
            if ($started) {
                try {
                    Db::rollback_after_failure($failure, 'attachment metadata transaction rollback');
                    $started = false;
                } catch (\Throwable $rollbackFailure) {
                    throw new \RuntimeException(
                        'duo: attachment metadata transaction outcome is unresolved; recovery_required; '
                        . 'original=' . self::failure_fingerprint($failure)
                        . '; rollback=' . self::failure_fingerprint($rollbackFailure),
                        0,
                        $failure
                    );
                }
            }
        } finally {
            $finishFailure = null;
            try {
                if (CacheInvalidationTransaction::is_active()) {
                    CacheInvalidationTransaction::finish();
                }
            } catch (\Throwable $failure) {
                $finishFailure = $failure;
            }
            $this->fieldMaterializer->end_authored_transaction();
            CacheInvalidationTransaction::end();
            if ($finishFailure !== null) {
                throw new \RuntimeException(
                    'duo: attachment metadata cache outcome failed; recovery_required; '
                    . 'cache=' . self::failure_fingerprint($finishFailure)
                    . ($primary === null ? '' : '; original=' . self::failure_fingerprint($primary)),
                    0,
                    $primary ?? $finishFailure
                );
            }
        }
        if ($primary !== null) throw $primary;
        if (!$committed) {
            throw new \RuntimeException('duo: attachment metadata transaction did not reach a classified commit outcome');
        }
        $identity = $this->filesystem->metadata_marker_identity();
        if ($identity === null) {
            throw new \RuntimeException('duo: committed attachment metadata lacks its durable marker identity');
        }
        $this->filesystem->metadata_transaction_committed(Ledger::kv_get($identity['key']));
    }

    /** Remove the terminal marker in its own classified database transaction. */
    private function delete_terminal_marker(string $key, string $expected): void {
        $current = Ledger::kv_get($key);
        if ($current === null) return;
        if (!hash_equals($expected, $current)) {
            throw new \RuntimeException('duo: terminal attachment marker changed before cleanup; recovery_required');
        }
        $started = false;
        try {
            Db::start_repeatable_read('attachment terminal marker transaction start');
            $started = true;
            $inside = Ledger::kv_get($key);
            if (!is_string($inside) || !hash_equals($expected, $inside)) {
                throw new \RuntimeException('duo: terminal attachment marker changed inside its cleanup transaction');
            }
            Ledger::kv_delete($key);
            if (Ledger::kv_get($key) !== null) {
                throw new \RuntimeException('duo: terminal attachment marker remained after delete');
            }
            Db::commit('attachment terminal marker transaction commit');
            $started = false;
        } catch (\Throwable $failure) {
            if ($started) {
                try {
                    Db::rollback_after_failure($failure, 'attachment terminal marker transaction rollback');
                } catch (\Throwable $rollbackFailure) {
                    throw new \RuntimeException(
                        'duo: terminal attachment marker outcome is unresolved; recovery_required; '
                        . 'original=' . self::failure_fingerprint($failure)
                        . '; rollback=' . self::failure_fingerprint($rollbackFailure),
                        0,
                        $failure
                    );
                }
            }
            throw $failure;
        }
    }

    private static function failure_fingerprint(\Throwable $failure): string {
        return get_class($failure) . ':' . strlen($failure->getMessage()) . ':'
            . substr(hash('sha256', $failure->getMessage()), 0, 16);
    }

    /**
     * Lock the complete collation-equality `_wp_attached_file` key range in
     * bounded chunks, then require the desired portable filesystem identity
     * to belong to this attachment alone. The stock postmeta `meta_key(191)`
     * index completely covers this 17-character literal; the final empty
     * page owns its terminal insertion gap under the authored RR transaction.
     */
    private function assert_global_attached_file_authority(int $attachmentId, string $desiredPath): void {
        $this->assert_global_attached_file_authorities($attachmentId, [$desiredPath]);
    }

    /** @param list<string> $desiredPaths */
    private function assert_global_attached_file_authorities(int $attachmentId, array $desiredPaths): void {
        global $wpdb;
        if ($desiredPaths === []) return;
        $wanted = [];
        foreach ($desiredPaths as $desiredPath) {
            if (!is_string($desiredPath)) {
                throw new \RuntimeException('duo: attachment desired attached-file roster is malformed');
            }
            $this->assert_relative_upload_path($desiredPath, 'desired attached file');
            $identity = AttachmentFilesystemTransaction::portable_path_identity($desiredPath);
            if (isset($wanted[$identity]) && !hash_equals($wanted[$identity], $desiredPath)) {
                throw new \RuntimeException(
                    'duo: attachment desired attached-file roster contains a case/Unicode-normalization alias'
                );
            }
            $wanted[$identity] = $desiredPath;
        }
        $index = $this->attachedFileLockIndex ??= DeleteGuardEvaluator::bounded_prefix_lock_index(
            $wpdb->postmeta,
            'meta_key',
            strlen(self::ATTACHED_FILE_KEY),
            'attachment global attached-file authority'
        );
        $lastMetaId = 0;
        $seenRows = 0;
        $owners = [];
        while (true) {
            DeleteGuardEvaluator::assert_transaction_isolation('attachment global attached-file authority');
            $wpdb->last_error = '';
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT meta_id, post_id, meta_key, OCTET_LENGTH(meta_value) AS meta_value_bytes, '
                    . 'CASE WHEN meta_value IS NOT NULL AND OCTET_LENGTH(meta_value) <= %d '
                    . "THEN meta_value ELSE NULL END AS bounded_value FROM `{$wpdb->postmeta}` "
                    . "FORCE INDEX (`$index`) WHERE meta_key = %s AND meta_id > %d "
                    . 'ORDER BY meta_key ASC, meta_id ASC LIMIT ' . self::ATTACHED_FILE_LOCK_CHUNK
                    . ' FOR UPDATE',
                self::MAX_UPLOAD_PATH_BYTES,
                self::ATTACHED_FILE_KEY,
                $lastMetaId
            ), ARRAY_A);
            if (!is_array($rows)
                || !array_is_list($rows)
                || trim((string) ($wpdb->last_error ?? '')) !== '') {
                throw new \RuntimeException('duo: attachment global attached-file lock read failed');
            }
            if ($rows === []) break;
            foreach ($rows as $position => $row) {
                $metaId = is_array($row) ? MetaRows::positive_id($row['meta_id'] ?? null) : null;
                $ownerId = is_array($row) ? MetaRows::positive_id($row['post_id'] ?? null) : null;
                $valueBytes = is_array($row)
                    ? $this->canonical_nonnegative_driver_integer($row['meta_value_bytes'] ?? null)
                    : null;
                $bounded = is_array($row) ? ($row['bounded_value'] ?? null) : null;
                if (!is_array($row)
                    || array_keys($row) !== ['meta_id', 'post_id', 'meta_key', 'meta_value_bytes', 'bounded_value']
                    || $metaId === null
                    || $ownerId === null
                    || $metaId <= $lastMetaId
                    || !is_string($row['meta_key'] ?? null)
                    || !hash_equals(self::ATTACHED_FILE_KEY, $row['meta_key'])
                    || $valueBytes === null
                    || !($bounded === null || is_string($bounded))
                    || ($valueBytes <= self::MAX_UPLOAD_PATH_BYTES
                        && (!is_string($bounded) || strlen($bounded) !== $valueBytes))
                    || ($valueBytes > self::MAX_UPLOAD_PATH_BYTES && $bounded !== null)) {
                    throw new \RuntimeException(
                        "duo: attachment global attached-file lock returned a malformed or aliased row at bounded position $position"
                    );
                }
                if ($valueBytes > self::MAX_UPLOAD_PATH_BYTES) {
                    throw new \RuntimeException(
                        'duo: attachment global attached-file authority contains an oversized path'
                    );
                }
                $lastMetaId = $metaId;
                if (++$seenRows > self::MAX_ATTACHED_FILE_ROWS) {
                    throw new \RuntimeException(
                        'duo: attachment global attached-file authority exceeds its bounded row frontier'
                    );
                }
                if (!is_string($bounded) || !$this->is_relative_upload_path($bounded)) continue;
                $identity = AttachmentFilesystemTransaction::portable_path_identity($bounded);
                if (!isset($wanted[$identity])) continue;
                if (!hash_equals($wanted[$identity], $bounded)) {
                    throw new \RuntimeException(
                        'duo: attachment desired file has a global case/Unicode-normalization metadata alias'
                    );
                }
                $owners[$wanted[$identity]][] = $ownerId;
            }
            if (count($rows) < self::ATTACHED_FILE_LOCK_CHUNK) break;
        }
        foreach ($wanted as $desiredPath) {
            $pathOwners = $owners[$desiredPath] ?? [];
            if ($pathOwners !== [] && $pathOwners !== [$attachmentId]) {
                throw new \RuntimeException(
                    'duo: attachment desired file is already owned by another or duplicate _wp_attached_file row'
                );
            }
        }
    }

    private function canonical_nonnegative_driver_integer(mixed $value): ?int {
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($integer) && $integer >= 0 ? $integer : null;
    }

    /** @return list<string> exact frozen manifest authorities for media-hook isolation */
    private function attachment_adapter_manifests(): array {
        $manifests = [];
        foreach ($this->policy->version_ranges() as $row) {
            $manifest = $row['manifest'] ?? null;
            if (!is_string($manifest) || $manifest === '') {
                throw new \RuntimeException('duo: attachment media-hook policy authority is malformed');
            }
            $manifests[$manifest] = true;
        }
        $out = array_keys($manifests);
        sort($out, SORT_STRING);
        return $out;
    }

    /** @return array{intent_id:string,artifact_hash:string,roster_hash:string,manifest_hash:string} */
    private function polylang_proof_context(): array {
        if (!in_array($this->filesystem->phase(), [
            'originals_published', 'generating_metadata', 'metadata_generated',
            'publishing_derivatives', 'derivatives_published', 'metadata_committing',
            'metadata_committed', 'removing_stale', 'complete',
        ], true)) {
            throw new \RuntimeException('duo: Polylang no-language proof is not sealed to a post-commit attachment attempt');
        }
        $attempt = $this->filesystem->attempt_identity();
        if ($attempt === null) {
            throw new \RuntimeException('duo: Polylang no-language proof lacks its durable attachment attempt');
        }
        return [
            'intent_id' => $attempt['intent_id'],
            'artifact_hash' => $attempt['artifact_hash'],
            'roster_hash' => $attempt['roster_hash'],
            'manifest_hash' => $this->compiled->manifest_hash(),
        ];
    }

    /**
     * Only files named by this attachment's exact prior native metadata are
     * deletion-owned. Prefix neighbors are collision witnesses, never stale
     * cleanup authority.
     *
     * @return list<string>
     */
    private function prior_native_owned_paths(
        array $metadataRows,
        array $attachedRows,
        array $backupRows = []
    ): array {
        if (count($metadataRows) > 1 || count($attachedRows) > 1 || count($backupRows) > 1) {
            throw new \RuntimeException('duo: attachment prior native metadata has duplicate exact rows');
        }
        if ($metadataRows === [] && $attachedRows === [] && $backupRows === []) return [];
        if ($attachedRows === []
            || !is_string($attachedRows[0]['meta_value'] ?? null)
            || $attachedRows[0]['meta_value'] === '') {
            throw new \RuntimeException('duo: attachment prior native metadata lacks its attached-file identity');
        }
        $attached = $attachedRows[0]['meta_value'];
        $this->assert_relative_upload_path($attached, 'prior attached file');
        $directory = dirname($attached) === '.' ? '' : dirname($attached);
        $owned = [$attached => true];
        if ($metadataRows !== []) {
            $raw = $metadataRows[0]['meta_value'] ?? null;
            if (!is_string($raw) || strlen($raw) > 16777216) {
                throw new \RuntimeException('duo: attachment prior native metadata is null or oversized');
            }
            $metadata = PlainData::decode_serialized($raw, 'attachment prior native metadata');
            if (!is_array($metadata)) {
                throw new \RuntimeException('duo: attachment prior native metadata is not an array');
            }
            $metadataFile = $metadata['file'] ?? $attached;
            if (!is_string($metadataFile) || !hash_equals($metadataFile, $attached)) {
                throw new \RuntimeException('duo: attachment prior metadata file identity disagrees with _wp_attached_file');
            }
            $sizes = $metadata['sizes'] ?? [];
            if (!is_array($sizes)
                || ($sizes !== [] && array_is_list($sizes))
                || count($sizes) > 512) {
                throw new \RuntimeException('duo: attachment prior metadata sizes roster is malformed or oversized');
            }
            foreach ($sizes as $name => $size) {
                if (!is_string($name)
                    || strlen($name) > 191
                    || !is_array($size)
                    || !is_string($size['file'] ?? null)) {
                    throw new \RuntimeException('duo: attachment prior metadata contains a malformed size row');
                }
                $file = $size['file'];
                if (!hash_equals($file, basename($file))) {
                    throw new \RuntimeException('duo: attachment prior metadata size escapes its attached-file directory');
                }
                $path = ($directory === '' ? '' : $directory . '/') . $file;
                $this->assert_relative_upload_path($path, 'prior attachment derivative');
                $owned[$path] = true;
            }
            if (array_key_exists('original_image', $metadata)) {
                $original = $metadata['original_image'];
                if (!is_string($original) || !hash_equals($original, basename($original))) {
                    throw new \RuntimeException('duo: attachment prior metadata original_image is malformed');
                }
                $path = ($directory === '' ? '' : $directory . '/') . $original;
                $this->assert_relative_upload_path($path, 'prior attachment original image');
                $owned[$path] = true;
            }
        }
        if ($backupRows !== []) {
            $raw = $backupRows[0]['meta_value'] ?? null;
            if (!is_string($raw) || strlen($raw) > 16777216) {
                throw new \RuntimeException('duo: attachment prior backup metadata is null or oversized');
            }
            $backups = PlainData::decode_serialized($raw, 'attachment prior backup metadata');
            if (!is_array($backups) || array_is_list($backups) || count($backups) > 512) {
                throw new \RuntimeException('duo: attachment prior backup metadata roster is malformed or oversized');
            }
            foreach ($backups as $name => $backup) {
                if (!is_string($name)
                    || $name === ''
                    || strlen($name) > 191
                    || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
                    || !is_array($backup)
                    || array_is_list($backup)) {
                    throw new \RuntimeException('duo: attachment prior backup metadata contains a malformed row');
                }
                $keys = array_keys($backup);
                sort($keys, SORT_STRING);
                $allowed = ['file', 'filesize', 'height', 'mime-type', 'width'];
                if (array_diff($keys, $allowed) !== []
                    || !is_string($backup['file'] ?? null)
                    || !hash_equals($backup['file'], basename($backup['file']))
                    || !$this->valid_backup_dimension($backup['width'] ?? null)
                    || !$this->valid_backup_dimension($backup['height'] ?? null)
                    || (array_key_exists('filesize', $backup)
                        && !$this->valid_backup_dimension($backup['filesize']))
                    || (array_key_exists('mime-type', $backup)
                        && (!is_string($backup['mime-type'])
                            || $backup['mime-type'] === ''
                            || strlen($backup['mime-type']) > 191))) {
                    throw new \RuntimeException('duo: attachment prior backup metadata contains a malformed row');
                }
                $path = ($directory === '' ? '' : $directory . '/') . $backup['file'];
                $this->assert_relative_upload_path($path, 'prior attachment edited backup');
                $owned[$path] = true;
            }
        }
        return array_keys($owned);
    }

    private function valid_backup_dimension(mixed $value): bool {
        return is_int($value) && $value >= 0 && $value <= PHP_INT_MAX;
    }

    private function assert_relative_upload_path(string $path, string $purpose): void {
        if (!$this->is_relative_upload_path($path)) {
            throw new \RuntimeException("duo: attachment $purpose is not a bounded normalized relative path");
        }
    }

    private function is_relative_upload_path(string $path): bool {
        $characters = strlen($path) <= self::MAX_UPLOAD_PATH_BYTES ? preg_match_all('/./us', $path) : false;
        $segments = explode('/', $path);
        return !($path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || !is_int($characters)
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || array_filter($segments, static fn(string $segment): bool =>
                $segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255));
    }
}
