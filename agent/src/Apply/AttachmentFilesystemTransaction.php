<?php
declare(strict_types=1);

namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Kernel/DurableFilesystem.php';
require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/AttachmentNativeMetadataGenerator.php';
if (!class_exists(CompiledRepository::class, false)) {
    require_once __DIR__ . '/../Repository/CompiledArtifact.php';
}

/**
 * Durable local-filesystem half of one attachment apply.
 *
 * The compiled upload inventory is the complete mutation authority. Original
 * files are never changed while the authored database transaction can still
 * roll back: prepare freezes exact before-images and absence witnesses, seal
 * writes a marker inside that transaction, and publish runs only after COMMIT.
 * A crash is classified by that marker on the next locked apply. One stable
 * private flock serializes the complete upload authority without accumulating
 * per-path inodes. All target paths reject aliases, symlinks and special files;
 * publication preserves or derives a sealed non-world-writable mode, uses a
 * destination-local temp for same-filesystem rename, and re-proves every byte
 * and mode at the metadata COMMIT boundary.
 */
final class AttachmentFilesystemTransaction {
    private const FORMAT = 'duo-attachment-filesystem-transaction/v1';
    private const DIRECTORY = 'attachment-filesystem';
    private const MAX_FILE_BYTES = 268435456;
    private const MAX_ATTACHMENT_BYTES = 536870912;
    /** Originals + before-images + generated files + metadata for one apply. */
    private const MAX_TRANSACTION_BYTES = 1073741824;
    private const MAX_DERIVATIVES = 512;
    /** Native sizes + edited backup sizes + attached/original_image. */
    private const MAX_OWNED_PRIOR_FILES = 1026;
    private const MAX_DIRECTORY_ENTRIES = 100000;
    private const MAX_RELATIVE_BYTES = 1024;
    private const MAX_METADATA_BYTES = 16777216;

    private ?array $journal = null;
    private ?string $root = null;
    private ?string $journalRoot = null;
    private bool $resumingCommitted = false;
    /** @var array<string,resource> */
    private array $locks = [];

    public function __construct(
        private readonly CompiledRepository $compiled,
        private readonly string $repositoryRoot
    ) {}

    /**
     * Load a crashed prior attempt before target capture observes files.
     *
     * The caller owns the database read so this filesystem service never
     * opens an implicit transaction or hides the exact duo_kv authority it
     * expects. Call pending_marker_identity() after this returns, read that
     * key through Ledger, then call recover_pending_with_marker().
     */
    public function load_pending(): void {
        if (!$this->initialize_roots(false)) return;
        $current = $this->current_directory();
        if (!file_exists($current) && !is_link($current)) {
            return;
        }
        try {
            $this->journal = $this->read_journal();
            $this->acquire_journal_locks();
            // The first read is only sufficient to discover the lock roster.
            // A concurrent replacement before flock acquisition must not
            // become a hybrid authority.
            $locked = $this->read_journal();
            if (!hash_equals(Canon::encode($this->journal), Canon::encode($locked))) {
                throw new \RuntimeException(
                    'duo: attachment durable journal changed while its destination locks were acquired'
                );
            }
            $this->journal = $locked;
            $this->assert_planned_filesystem_aliases($this->journal['rows']);
        } catch (\Throwable $failure) {
            $this->release_locks();
            $this->journal = null;
            throw $failure;
        }
    }

    /** @return ?array{key:string,value:string} */
    public function pending_marker_identity(): ?array {
        if ($this->journal === null) return null;
        return [
            'key' => (string) $this->journal['marker_key'],
            'value' => $this->marker_value($this->journal),
        ];
    }

    /** @return ?array{key:string,value:string} */
    public function metadata_marker_identity(): ?array {
        if ($this->journal === null || !is_string($this->journal['generation_sha256'] ?? null)) {
            return null;
        }
        return [
            'key' => (string) $this->journal['marker_key'],
            'value' => $this->metadata_marker_value($this->journal),
        ];
    }

    /** @return list<array{attachment_id:int,attachment_uuid:string,original_path:string}> */
    public function pending_bindings(): array {
        if ($this->journal === null) return [];
        $bindings = [];
        foreach ($this->journal['rows'] as $row) {
            if (!is_int($row['attachment_id'] ?? null) || $row['attachment_id'] <= 0) {
                throw new \RuntimeException('duo: attachment durable journal lacks a target attachment identity');
            }
            $bindings[] = [
                'attachment_id' => $row['attachment_id'],
                'attachment_uuid' => (string) $row['attachment_uuid'],
                'original_path' => (string) $row['original_path'],
            ];
        }
        return $bindings;
    }

    /** Recover loaded durable state against its independently-read DB marker. */
    public function recover_pending_with_marker(?string $marker): void {
        if ($this->journal === null) return;
        $phase = (string) $this->journal['phase'];
        if (!hash_equals($this->compiled->artifact_hash(), (string) $this->journal['artifact_hash'])) {
            throw new \RuntimeException(
                'duo: attachment filesystem recovery requires the exact compiled artifact that owns the pending upload intent'
            );
        }
        $this->assert_transaction_byte_budget();

        if ($marker === null) {
            if (in_array($phase, ['preparing', 'prepared', 'authored_prepared'], true)) {
                // Publication is post-COMMIT only. An absent transaction-bound
                // marker proves no target upload path was authorized to move.
                if ($phase !== 'preparing') {
                    $this->assert_prior_inventory($this->journal, false);
                }
                $this->remove_current_journal();
                $this->journal = null;
                $this->release_locks();
                return;
            }
            if ($phase === 'complete') {
                $this->cleanup_complete(null);
                $this->release_locks();
                return;
            }
            throw new \RuntimeException(
                'duo: attachment filesystem recovery found published state without its database commit marker; recovery_required'
            );
        }

        $authoredMarker = $this->marker_value($this->journal);
        $metadataMarker = is_string($this->journal['generation_sha256'] ?? null)
            ? $this->metadata_marker_value($this->journal)
            : null;
        if ($metadataMarker !== null && hash_equals($metadataMarker, $marker)) {
            if (!in_array($phase, ['metadata_committing', 'metadata_committed', 'removing_stale', 'complete'], true)) {
                throw new \RuntimeException(
                    'duo: attachment metadata marker appears before its durable generation phase; recovery_required'
                );
            }
            if ($phase === 'metadata_committing') {
                $this->metadata_transaction_committed($marker);
            }
            $this->resumingCommitted = true;
            return;
        }
        if (!hash_equals($authoredMarker, $marker)
            || in_array($phase, ['metadata_committed', 'removing_stale', 'complete'], true)) {
            throw new \RuntimeException(
                'duo: attachment filesystem recovery marker does not match the durable phase authority; recovery_required'
            );
        }
        if (in_array($phase, ['authored_prepared', 'publishing_originals', 'originals_published'], true)) {
            $this->publish_originals();
        } else {
            $this->assert_desired_originals();
        }
        $this->resumingCommitted = true;
    }

    /**
     * Freeze only attachment originals present in this apply's authored work.
     *
     * @param list<array<string,mixed>> $work
     * @param array<string,array<string,mixed>> $tree
     */
    public function prepare(
        array $work,
        array $tree,
        AttachmentNativeMetadataGenerator $metadataGenerator
    ): void {
        $planned = $this->planned_rows($work, $tree);
        if ($planned === []) {
            return;
        }
        $this->initialize_roots(true);
        $this->assert_planned_filesystem_aliases($planned);
        if ($this->journal !== null) {
            $existing = array_map(
                static fn(array $row): string => (string) $row['attachment_uuid'],
                $this->journal['rows']
            );
            $wanted = array_map(
                static fn(array $row): string => (string) $row['attachment_uuid'],
                $planned
            );
            sort($existing, SORT_STRING);
            sort($wanted, SORT_STRING);
            if ($existing !== $wanted
                || !hash_equals((string) $this->journal['artifact_hash'], $this->compiled->artifact_hash())) {
                throw new \RuntimeException(
                    'duo: attachment filesystem recovery work differs from the pending durable upload intent'
                );
            }
            foreach ($planned as $position => $wantedRow) {
                $existingRow = $this->journal['rows'][$position] ?? null;
                if (!is_array($existingRow)
                    || !hash_equals((string) $existingRow['original_path'], (string) $wantedRow['original_path'])
                    || !hash_equals((string) $existingRow['media_blob'], (string) $wantedRow['media_blob'])) {
                    throw new \RuntimeException(
                        'duo: attachment filesystem recovery rows differ from the pending durable upload intent'
                    );
                }
            }
            return;
        }
        $current = $this->current_directory();
        if (file_exists($current) || is_link($current)) {
            throw new \RuntimeException(
                'duo: attachment filesystem preparation found an unrecovered durable upload intent'
            );
        }
        if (!@mkdir($current, 0700)) {
            throw new \RuntimeException('duo: attachment filesystem could not create its durable intent directory');
        }
        $this->assert_private_directory($current, 'attachment intent directory');

        $intent = bin2hex(random_bytes(16));
        $this->journal = [
            'artifact_hash' => $this->compiled->artifact_hash(),
            'authority_sha256' => null,
            'format' => self::FORMAT,
            'generation_sha256' => null,
            'intent_id' => $intent,
            'marker_key' => 'attachment_fs:' . $intent,
            'phase' => 'preparing',
            'rows' => $planned,
        ];
        $this->write_journal();
        $this->acquire_journal_locks();

        $rows = [];
        foreach ($this->journal['rows'] as $position => $row) {
            $row['prior'] = $this->snapshot_prior_inventory($row, $position);
            $rows[] = $row;
        }
        $this->journal['rows'] = $rows;
        $preflightRoot = $this->current_directory() . '/preflight';
        if (!@mkdir($preflightRoot, 0700)) {
            throw new \RuntimeException('duo: attachment markerless media preflight directory could not be created');
        }
        $this->assert_private_directory($preflightRoot, 'attachment markerless media preflight directory');
        foreach ($this->journal['rows'] as $position => $row) {
            $bytes = $this->compiled->media_content((string) $row['media_blob']);
            $path = $preflightRoot . '/' . $position . '-' . basename((string) $row['original_path']);
            $this->write_new_file($path, $bytes, 'attachment markerless media preflight file');
            $metadataGenerator->preflight((string) $row['mime'], $path);
        }
        $this->remove_owned_tree($preflightRoot);
        $this->journal['phase'] = 'prepared';
        $this->journal['authority_sha256'] = $this->authority_hash($this->journal);
        $this->assert_transaction_byte_budget();
        $this->write_journal();
    }

    /** Bind the target post id and exact prior native file ownership. */
    public function register_attachment(int $id, array $front, array $ownedPriorPaths): void {
        if ($id <= 0 || $this->journal === null) {
            throw new \RuntimeException('duo: attachment filesystem registration lacks a prepared upload transaction');
        }
        $uuid = (string) ($front['uuid'] ?? '');
        foreach ($this->journal['rows'] as $position => $row) {
            if (!hash_equals((string) $row['attachment_uuid'], $uuid)) {
                continue;
            }
            if (!hash_equals((string) $row['original_path'], (string) ($front['file'] ?? ''))
                || !hash_equals((string) $row['media_blob'], (string) ($front['media'] ?? ''))) {
                throw new \RuntimeException('duo: attachment filesystem registration disagrees with compiled upload authority');
            }
            $owned = [];
            foreach ($ownedPriorPaths as $path) {
                if (!is_string($path)) {
                    throw new \RuntimeException('duo: attachment filesystem received malformed prior metadata ownership');
                }
                $this->assert_relative_path($path);
                if (isset($owned[$path])) {
                    throw new \RuntimeException('duo: attachment filesystem received duplicate prior metadata ownership');
                }
                $owned[$path] = true;
            }
            if (count($owned) > self::MAX_OWNED_PRIOR_FILES) {
                throw new \RuntimeException('duo: attachment prior metadata ownership exceeds its file bound');
            }
            $existing = $row['attachment_id'];
            if ($existing !== null && $existing !== $id) {
                throw new \RuntimeException('duo: attachment filesystem registration changed the target attachment identity');
            }
            // Old attached-file paths are known only after their exact target
            // metadata owner range is locked. Reacquire new and old file
            // authorities in one canonical order, re-prove the markerless new
            // prefix, then freeze any old path before `_wp_attached_file`
            // changes inside the authored transaction.
            $this->release_locks();
            $this->journal['rows'][$position]['attachment_id'] = $id;
            $this->journal['rows'][$position]['owned_prior_paths'] = array_keys($owned);
            $this->acquire_journal_locks();
            $this->assert_prior_inventory($this->journal, false);
            $priorByPath = [];
            foreach ($this->journal['rows'][$position]['prior'] as $prior) {
                $priorByPath[(string) $prior['path']] = $prior;
            }
            foreach (array_keys($owned) as $path) {
                if (!isset($priorByPath[$path])) {
                    $priorByPath[$path] = $this->snapshot_path($path, $position, count($priorByPath));
                }
            }
            ksort($priorByPath, SORT_STRING);
            $this->journal['rows'][$position]['prior'] = array_values($priorByPath);
            $original = (string) $row['original_path'];
            $originalPrior = $priorByPath[$original] ?? null;
            if (!is_array($originalPrior)) {
                throw new \RuntimeException('duo: attachment destination lacks its frozen collision witness');
            }
            if (($originalPrior['state'] ?? null) === 'present' && !isset($owned[$original])) {
                throw new \RuntimeException(
                    'duo: attachment destination is present without exact prior attached-file ownership'
                );
            }
            $this->journal['authority_sha256'] = $this->authority_hash($this->journal);
            $this->assert_transaction_byte_budget();
            $this->write_journal();
            return;
        }
        throw new \RuntimeException('duo: attachment filesystem registration is outside the prepared upload inventory');
    }

    /**
     * Seal the value whose caller-owned write participates in the authored
     * database transaction.
     *
     * @return ?array{key:string,value:string}
     */
    public function seal_authored_transaction(): ?array {
        if ($this->journal === null) return null;
        if ($this->resumingCommitted) return null;
        if (!in_array($this->journal['phase'], ['prepared', 'originals_published'], true)) {
            throw new \RuntimeException('duo: attachment filesystem transaction is not sealable from its current phase');
        }
        foreach ($this->journal['rows'] as $row) {
            if (!is_int($row['attachment_id'] ?? null) || $row['attachment_id'] <= 0) {
                throw new \RuntimeException('duo: attachment filesystem transaction lacks a target attachment identity');
            }
        }
        $this->journal['authority_sha256'] = $this->authority_hash($this->journal);
        $this->journal['phase'] = 'authored_prepared';
        $this->write_journal();
        return $this->pending_marker_identity();
    }

    /** Publish originals only after the authored DB COMMIT returned green. */
    public function commit_authored_transaction(?string $marker): void {
        if ($this->journal === null) return;
        if ($this->resumingCommitted) return;
        if ($marker === null || !hash_equals($this->marker_value($this->journal), $marker)) {
            throw new \RuntimeException(
                'duo: attachment filesystem publication lacks its exact committed database marker; recovery_required'
            );
        }
        $this->publish_originals();
    }

    /** Cleanup is safe only after a positively confirmed database rollback. */
    public function rollback_authored_transaction(?string $marker): void {
        if ($this->journal === null) return;
        if ($this->resumingCommitted) return;
        if ($marker !== null) {
            throw new \RuntimeException(
                'duo: attachment filesystem rollback found a committed database marker; recovery_required'
            );
        }
        if ($this->journal['phase'] !== 'preparing') {
            $this->assert_prior_inventory($this->journal, false);
        }
        $this->remove_current_journal();
        $this->journal = null;
    }

    /** @return list<int> */
    public function pending_attachment_ids(): array {
        if ($this->journal === null) return [];
        $ids = [];
        foreach ($this->journal['rows'] as $row) {
            if (is_int($row['attachment_id'] ?? null) && $row['attachment_id'] > 0) {
                $ids[] = $row['attachment_id'];
            }
        }
        sort($ids, SORT_NUMERIC);
        return array_values(array_unique($ids));
    }

    public function phase(): ?string {
        return $this->journal === null ? null : (string) $this->journal['phase'];
    }

    /** Generate an exact staged derivative/metadata inventory without touching target files. */
    public function generate_metadata(AttachmentNativeMetadataGenerator $generator): void {
        if ($this->journal === null) return;
        if (!in_array($this->journal['phase'], [
            'originals_published', 'generating_metadata', 'metadata_generated',
            'publishing_derivatives', 'derivatives_published', 'metadata_committing',
            'metadata_committed', 'removing_stale', 'complete',
        ], true)) {
            throw new \RuntimeException('duo: attachment metadata generation cannot start from the current durable phase');
        }
        if ($this->journal['generation_sha256'] !== null) {
            $this->read_generation_manifest();
            return;
        }
        $this->journal['phase'] = 'generating_metadata';
        $this->write_journal();
        $results = [];
        foreach ($this->journal['rows'] as $position => $row) {
            $resultPath = $this->generated_result_path($position);
            if (file_exists($resultPath) || is_link($resultPath)) {
                $results[] = $this->read_generated_result($position, $row);
                continue;
            }
            $stageDirectory = $this->stage_directory($position);
            if (file_exists($stageDirectory) || is_link($stageDirectory)) {
                $this->remove_owned_tree($stageDirectory);
            }
            if (!@mkdir($stageDirectory, 0700, true)) {
                throw new \RuntimeException('duo: attachment metadata staging directory could not be created');
            }
            $this->assert_private_directory($stageDirectory, 'attachment metadata staging directory');
            $bytes = $this->compiled->media_content((string) $row['media_blob']);
            if (strlen($bytes) > self::MAX_FILE_BYTES
                || !hash_equals((string) $row['original_sha256'], hash('sha256', $bytes))) {
                throw new \RuntimeException('duo: attachment metadata staging input disagrees with compiled bytes');
            }
            $stageOriginal = $stageDirectory . '/' . basename((string) $row['original_path']);
            $this->write_new_file($stageOriginal, $bytes, 'attachment metadata staging original');
            $metadata = $generator->generate((int) $row['attachment_id'], $stageOriginal);
            $result = $this->seal_generated_result($position, $row, $stageOriginal, $metadata);
            $this->write_json_file($resultPath, $result, 'attachment generated result');
            $results[] = $result;
        }
        $manifest = [
            'format' => 'duo-attachment-generated-inventory/v1',
            'intent_id' => $this->journal['intent_id'],
            'rows' => $results,
        ];
        $this->assert_transaction_byte_budget($manifest);
        $manifestPath = $this->generation_manifest_path();
        if (file_exists($manifestPath) || is_link($manifestPath)) {
            $existing = $this->read_json_file($manifestPath, 'attachment generation manifest');
            if (!hash_equals(Canon::encode($manifest), Canon::encode($existing))) {
                throw new \RuntimeException('duo: attachment generation manifest changed across crash recovery');
            }
        } else {
            $this->write_json_file($manifestPath, $manifest, 'attachment generation manifest');
        }
        $this->journal['generation_sha256'] = hash('sha256', Canon::encode($manifest));
        $this->journal['phase'] = 'metadata_generated';
        $this->write_journal();
    }

    /** Publish the exact staged set; stale prior derivatives remain until metadata commits. */
    public function publish_derivatives(): void {
        if ($this->journal === null) return;
        if (in_array($this->journal['phase'], [
            'derivatives_published', 'metadata_committing', 'metadata_committed', 'removing_stale', 'complete',
        ], true)) {
            $this->assert_desired_derivatives();
            return;
        }
        if (!in_array($this->journal['phase'], ['metadata_generated', 'publishing_derivatives'], true)) {
            throw new \RuntimeException('duo: attachment derivatives cannot publish from the current durable phase');
        }
        $manifest = $this->read_generation_manifest();
        $this->assert_prepublication_inventory($manifest);
        $this->journal['phase'] = 'publishing_derivatives';
        $this->write_journal();
        foreach ($manifest['rows'] as $position => $generated) {
            $row = $this->journal['rows'][$position];
            foreach ($generated['files'] as $file) {
                $stage = $this->current_directory() . '/' . $file['stage_path'];
                $bytes = $this->read_bounded_file($stage, (int) $file['size'], (string) $file['sha256']);
                $prior = $this->prior_for_target($row, (string) $file['target_path']);
                $current = $this->observe_path((string) $file['target_path']);
                if ($this->state_is_desired(
                    $current,
                    (string) $file['sha256'],
                    (int) $file['publish_mode']
                )) continue;
                if (!$this->states_equal($prior, $current)) {
                    throw new \RuntimeException(
                        'duo: attachment derivative changed after its before-image/absence witness; recovery_required'
                    );
                }
                $this->atomic_replace(
                    (string) $file['target_path'],
                    $bytes,
                    $prior,
                    (int) $file['publish_mode']
                );
            }
        }
        $this->journal['phase'] = 'derivatives_published';
        $this->write_journal();
        $this->assert_desired_derivatives();
    }

    /** @return list<array{attachment_id:int,metadata:?string}> */
    public function generated_metadata_rows(): array {
        if ($this->journal === null) return [];
        $manifest = $this->read_generation_manifest();
        $out = [];
        foreach ($manifest['rows'] as $row) {
            $serialized = base64_decode((string) $row['metadata_base64'], true);
            if (!is_string($serialized)
                || strlen($serialized) > self::MAX_METADATA_BYTES
                || !hash_equals((string) $row['metadata_sha256'], hash('sha256', $serialized))) {
                throw new \RuntimeException('duo: attachment generated metadata bytes do not verify');
            }
            $decoded = PlainData::decode_serialized($serialized, 'attachment generated metadata');
            if (!is_array($decoded)) {
                throw new \RuntimeException('duo: attachment generated metadata is not a canonical array');
            }
            $out[] = [
                'attachment_id' => (int) $row['attachment_id'],
                'metadata' => $decoded === [] ? null : $serialized,
            ];
        }
        return $out;
    }

    /**
     * Persist this value beside metadata in the second DB transaction.
     *
     * @return array{key:string,value:string}
     */
    public function seal_metadata_transaction(): array {
        if ($this->journal === null
            || !in_array($this->journal['phase'], ['derivatives_published', 'metadata_committing'], true)) {
            throw new \RuntimeException('duo: attachment metadata transaction lacks published derivative authority');
        }
        $this->assert_metadata_commit_files();
        $this->journal['phase'] = 'metadata_committing';
        $this->write_journal();
        return [
            'key' => (string) $this->journal['marker_key'],
            'value' => $this->metadata_marker_value($this->journal),
        ];
    }

    /** Re-prove every published byte/mode at the final DB COMMIT boundary. */
    public function assert_metadata_commit_files(): void {
        if ($this->journal === null
            || !in_array($this->journal['phase'], ['derivatives_published', 'metadata_committing'], true)) {
            throw new \RuntimeException('duo: attachment metadata byte proof lacks a committing durable phase');
        }
        $this->read_generation_manifest();
        $this->assert_desired_originals();
        $this->assert_desired_derivatives();
    }

    public function metadata_transaction_committed(?string $marker): void {
        if ($this->journal === null) return;
        if ($marker === null || !hash_equals($this->metadata_marker_value($this->journal), $marker)) {
            throw new \RuntimeException('duo: attachment metadata commit lacks its exact durable marker; recovery_required');
        }
        $this->journal['phase'] = 'metadata_committed';
        $this->write_journal();
    }

    /** Remove only exact frozen stale derivatives after metadata is durable. */
    public function remove_stale_derivatives(?string $marker): void {
        if ($this->journal === null) return;
        if (in_array($this->journal['phase'], ['complete'], true)) {
            $this->assert_final_inventory();
            return;
        }
        if (!in_array($this->journal['phase'], ['metadata_committed', 'removing_stale'], true)
            || $marker === null
            || !hash_equals($this->metadata_marker_value($this->journal), $marker)) {
            throw new \RuntimeException('duo: attachment stale derivative cleanup lacks committed metadata authority');
        }
        $manifest = $this->read_generation_manifest();
        $this->journal['phase'] = 'removing_stale';
        $this->write_journal();
        foreach ($this->journal['rows'] as $position => $row) {
            $desired = [];
            foreach ($manifest['rows'][$position]['files'] as $file) {
                $desired[(string) $file['target_path']] = true;
            }
            $owned = array_fill_keys((array) $row['owned_prior_paths'], true);
            foreach ($row['prior'] as $prior) {
                $path = (string) $prior['path'];
                if (hash_equals($path, (string) $row['original_path'])
                    || isset($desired[$path])
                    || !isset($owned[$path])) {
                    continue;
                }
                $current = $this->observe_path($path);
                if (($current['state'] ?? null) === 'absent') continue;
                if (!$this->states_equal($prior, $current)) {
                    throw new \RuntimeException('duo: stale attachment derivative changed before exact removal; recovery_required');
                }
                if (!@unlink($this->absolute_path($path))) {
                    throw new \RuntimeException('duo: stale attachment derivative removal failed');
                }
                $this->sync_directory(dirname($this->absolute_path($path)));
                if (($this->observe_path($path)['state'] ?? null) !== 'absent') {
                    throw new \RuntimeException('duo: stale attachment derivative remained after exact removal');
                }
            }
        }
        $this->journal['phase'] = 'complete';
        $this->write_journal();
        $this->assert_final_inventory();
    }

    /** Cleanup the complete journal only after the caller removed its DB marker. */
    public function cleanup_complete(?string $marker): void {
        if ($this->journal === null) return;
        if ($this->journal['phase'] !== 'complete' || $marker !== null) {
            throw new \RuntimeException('duo: attachment durable intent cleanup lacks complete marker-free authority');
        }
        $this->assert_final_inventory();
        $this->remove_current_journal();
        $this->journal = null;
        $this->resumingCommitted = false;
    }

    public function end(): void {
        $this->release_locks();
    }

    /** @return list<array<string,mixed>> */
    private function planned_rows(array $work, array $tree): array {
        $inventory = [];
        foreach ($this->compiled->uploads_inventory() as $row) {
            if (!is_array($row) || !is_string($row['attachment_uuid'] ?? null)) {
                throw new \RuntimeException('duo: compiled upload inventory is malformed at attachment apply');
            }
            $inventory[$row['attachment_uuid']] = $row;
        }
        $planned = [];
        foreach ($work as $entry) {
            $uuid = is_array($entry) ? ($entry['uuid'] ?? null) : null;
            $entity = is_string($uuid) ? ($tree[$uuid] ?? null) : null;
            $front = is_array($entity) && is_array($entity['data'] ?? null) ? $entity['data'] : null;
            if (($entity['type'] ?? null) !== 'post' || ($front['type'] ?? null) !== 'attachment') {
                continue;
            }
            $row = $inventory[$uuid] ?? null;
            if (!is_array($row)
                || !hash_equals((string) $row['original_path'], (string) ($front['file'] ?? ''))
                || !hash_equals((string) $row['media_blob'], (string) ($front['media'] ?? ''))
                || !is_string($front['mime'] ?? null)
                || $front['mime'] === ''
                || strlen($front['mime']) > 191
                || !preg_match('/^[0-9a-f]{64}$/D', (string) ($row['original_sha256'] ?? ''))) {
                throw new \RuntimeException('duo: authored attachment lacks exact compiled upload authority');
            }
            $this->assert_relative_path((string) $row['original_path']);
            $planned[] = [
                'attachment_id' => null,
                'attachment_uuid' => $uuid,
                'derivative_directory' => (string) $row['derivative_directory'],
                'derivative_prefix' => (string) $row['derivative_basename_prefix'],
                'media_blob' => (string) $row['media_blob'],
                'mime' => (string) ($front['mime'] ?? ''),
                'original_path' => (string) $row['original_path'],
                'original_sha256' => (string) $row['original_sha256'],
                'original_status' => 'pending',
                'owned_prior_paths' => [],
                'prior' => [],
            ];
        }
        usort($planned, static fn(array $a, array $b): int => strcmp($a['original_path'], $b['original_path']));
        $this->assert_non_overlapping_rows($planned);
        return $planned;
    }

    /** @param list<array<string,mixed>> $rows */
    private function assert_non_overlapping_rows(array $rows): void {
        $originals = [];
        $prefixes = [];
        foreach ($rows as $row) {
            $path = (string) $row['original_path'];
            $directory = (string) $row['derivative_directory'];
            $prefix = (string) $row['derivative_prefix'];
            if (str_starts_with($path, self::DIRECTORY . '/') || $path === self::DIRECTORY) {
                throw new \RuntimeException('duo: attachment upload authority collides with its durable journal namespace');
            }
            $pathIdentity = self::portable_path_identity($path);
            if (isset($originals[$pathIdentity])) {
                throw new \RuntimeException(
                    'duo: compiled upload authority contains duplicate or filesystem-aliased original paths'
                );
            }
            $originals[$pathIdentity] = $path;
            $prefixes[] = [
                'directory' => $directory,
                'directory_identity' => self::portable_path_identity($directory),
                'prefix' => $prefix,
                'prefix_identity' => self::portable_path_identity($prefix),
            ];
        }
        foreach ($prefixes as $leftIndex => $left) {
            foreach ($prefixes as $rightIndex => $right) {
                if ($leftIndex === $rightIndex
                    || !hash_equals($left['directory_identity'], $right['directory_identity'])) continue;
                if (str_starts_with($left['prefix_identity'], $right['prefix_identity'])
                    || str_starts_with($right['prefix_identity'], $left['prefix_identity'])) {
                    throw new \RuntimeException('duo: compiled attachment derivative authorities overlap');
                }
            }
            foreach ($originals as $original) {
                $originalDirectory = dirname($original) === '.' ? '' : dirname($original);
                if (hash_equals($left['directory_identity'], self::portable_path_identity($originalDirectory))
                    && str_starts_with(
                        self::portable_path_identity(basename($original)),
                        $left['prefix_identity']
                    )) {
                    throw new \RuntimeException('duo: attachment original collides with a derivative authority');
                }
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function snapshot_prior_inventory(array $row, int $position): array {
        $paths = $this->inventory_paths($row);
        $aggregate = 0;
        $prior = [];
        foreach ($paths as $index => $relative) {
            $this->assert_relative_path($relative);
            $snapshot = $this->snapshot_path($relative, $position, $index);
            $aggregate += (int) ($snapshot['size'] ?? 0);
            if ($aggregate > self::MAX_ATTACHMENT_BYTES) {
                throw new \RuntimeException('duo: attachment prior inventory exceeds its bounded byte limit');
            }
            $prior[] = $snapshot;
        }
        return $prior;
    }

    /** @return array<string,mixed> */
    private function snapshot_path(string $relative, int $position, int $index): array {
        $this->assert_path_alias_free($relative);
        $path = $this->absolute_path($relative);
        clearstatcache(true, $path);
        $named = @lstat($path);
        if (!is_array($named)) {
            if (file_exists($path) || is_link($path)) {
                throw new \RuntimeException('duo: attachment prior path has an unreadable filesystem identity');
            }
            return $this->absent_path_state($relative);
        }
        if (is_link($path) || !is_file($path) || (($named['mode'] ?? 0) & 0170000) !== 0100000) {
            throw new \RuntimeException('duo: attachment prior path is a symlink or non-regular file');
        }
        $size = $this->canonical_file_size($named['size'] ?? null);
        $mode = $this->safe_existing_publication_mode($named);
        if ($size === null || $size > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('duo: attachment prior file exceeds its bounded byte limit');
        }
        $beforeRelative = 'before/' . $position . '-' . $index . '.bin';
        $before = $this->current_directory() . '/' . $beforeRelative;
        $this->copy_witnessed_file($path, $before, $named, $size);
        $sha = @hash_file('sha256', $path);
        if (!is_string($sha) || !hash_equals($sha, (string) @hash_file('sha256', $before))) {
            throw new \RuntimeException('duo: attachment prior file changed while its before-image was sealed');
        }
        return [
            'before_image' => $beforeRelative,
            'dev' => (string) $named['dev'],
            'ino' => (string) $named['ino'],
            'mode' => $mode,
            'path' => $relative,
            'publish_mode' => $mode,
            'sha256' => $sha,
            'size' => $size,
            'state' => 'present',
        ];
    }

    private function copy_witnessed_file(string $source, string $destination, array $named, int $size): void {
        $directory = dirname($destination);
        if (!is_dir($directory) && !@mkdir($directory, 0700)) {
            throw new \RuntimeException('duo: attachment before-image directory could not be created');
        }
        $this->assert_private_directory($directory, 'attachment before-image directory');
        $input = @fopen($source, 'rb');
        $temp = $destination . '.tmp-' . bin2hex(random_bytes(8));
        $output = @fopen($temp, 'x+b');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            @unlink($temp);
            throw new \RuntimeException('duo: attachment before-image stream could not be opened');
        }
        $opened = fstat($input);
        $written = 0;
        try {
            $this->harden_private_handle($output, 'attachment before-image temp');
            while (!feof($input)) {
                $chunk = fread($input, 1048576);
                if (!is_string($chunk)) {
                    throw new \RuntimeException('duo: attachment before-image source read failed');
                }
                if ($chunk === '') break;
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $count = fwrite($output, substr($chunk, $offset));
                    if (!is_int($count) || $count <= 0) {
                        throw new \RuntimeException('duo: attachment before-image write failed');
                    }
                    $offset += $count;
                    $written += $count;
                }
            }
            if ($written !== $size || !fflush($output) || (function_exists('fsync') && !fsync($output))) {
                throw new \RuntimeException('duo: attachment before-image durability check failed');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        clearstatcache(true, $source);
        $after = @lstat($source);
        if (!is_array($opened) || !is_array($after)
            || (string) ($opened['dev'] ?? '') !== (string) ($named['dev'] ?? '')
            || (string) ($opened['ino'] ?? '') !== (string) ($named['ino'] ?? '')
            || (string) ($after['dev'] ?? '') !== (string) ($named['dev'] ?? '')
            || (string) ($after['ino'] ?? '') !== (string) ($named['ino'] ?? '')
            || $this->canonical_file_size($after['size'] ?? null) !== $size
            || is_link($source) || !is_file($source)) {
            @unlink($temp);
            throw new \RuntimeException('duo: attachment prior file changed identity while copied');
        }
        if (!@rename($temp, $destination)) {
            @unlink($temp);
            throw new \RuntimeException('duo: attachment before-image atomic publication failed');
        }
        $this->sync_file($destination);
        $this->sync_directory($directory);
    }

    private function publish_originals(): void {
        if ($this->journal === null) return;
        if (!in_array($this->journal['phase'], ['authored_prepared', 'publishing_originals', 'originals_published'], true)) {
            throw new \RuntimeException('duo: attachment originals cannot publish from the current durable phase');
        }
        if ($this->journal['phase'] === 'originals_published') {
            $this->assert_desired_originals();
            return;
        }
        $this->journal['phase'] = 'publishing_originals';
        $this->write_journal();
        foreach ($this->journal['rows'] as $position => $row) {
            $bytes = $this->compiled->media_content((string) $row['media_blob']);
            if (strlen($bytes) > self::MAX_FILE_BYTES
                || !hash_equals((string) $row['original_sha256'], hash('sha256', $bytes))) {
                throw new \RuntimeException('duo: compiled attachment original exceeds or disagrees with its bounded upload authority');
            }
            $prior = $this->original_prior($row);
            $current = $this->observe_path((string) $row['original_path']);
            if ($row['original_status'] === 'complete') {
                if (!$this->state_is_desired(
                    $current,
                    (string) $row['original_sha256'],
                    (int) $prior['publish_mode']
                )) {
                    throw new \RuntimeException('duo: completed attachment original changed after atomic publication; recovery_required');
                }
                continue;
            }
            if ($this->state_is_desired(
                $current,
                (string) $row['original_sha256'],
                (int) $prior['publish_mode']
            )) {
                // Crash after rename but before the journal phase write.
                $this->journal['rows'][$position]['original_status'] = 'complete';
                $this->write_journal();
                continue;
            }
            if (!$this->states_equal($prior, $current)) {
                throw new \RuntimeException('duo: attachment original changed after its before-image was frozen; recovery_required');
            }
            $this->atomic_replace(
                (string) $row['original_path'],
                $bytes,
                $prior,
                (int) $prior['publish_mode']
            );
            $this->journal['rows'][$position]['original_status'] = 'complete';
            $this->write_journal();
        }
        $this->journal['phase'] = 'originals_published';
        $this->write_journal();
        $this->assert_desired_originals();
    }

    private function atomic_replace(string $relative, string $bytes, array $expected, int $publishMode): void {
        $this->assert_safe_publication_mode($publishMode);
        $this->ensure_parent_directories($relative);
        $target = $this->absolute_path($relative);
        $parent = dirname($target);
        $parentIdentity = $this->contained_directory_identity($parent, 'attachment destination directory');
        if (!$this->states_equal($expected, $this->observe_path($relative))) {
            throw new \RuntimeException('duo: attachment destination changed immediately before atomic replacement');
        }
        $tempName = '.duo-attachment-' . (string) $this->journal['intent_id'] . '-' . bin2hex(random_bytes(8)) . '.tmp';
        $temp = $parent . '/' . $tempName;
        $handle = @fopen($temp, 'x+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('duo: attachment destination temp file could not be created');
        }
        try {
            if (!@chmod($temp, $publishMode)) {
                throw new \RuntimeException('duo: attachment destination temp mode could not be sealed');
            }
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if (!is_int($written) || $written <= 0) {
                    throw new \RuntimeException('duo: attachment destination temp write failed');
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new \RuntimeException('duo: attachment destination temp durability check failed');
            }
            $tempStat = fstat($handle);
            $parentStat = $this->contained_directory_identity($parent, 'attachment destination directory');
            if (!is_array($tempStat) || !is_array($parentStat)
                || !$this->directory_identities_equal($parentIdentity, $parentStat)
                || (string) ($tempStat['dev'] ?? '') !== (string) ($parentStat['dev'] ?? '')
                || (((int) ($tempStat['mode'] ?? 0)) & 0777) !== $publishMode) {
                throw new \RuntimeException('duo: attachment temp and destination are not on one filesystem');
            }
        } finally {
            fclose($handle);
        }
        if (!$this->states_equal($expected, $this->observe_path($relative))) {
            @unlink($temp);
            throw new \RuntimeException('duo: attachment destination changed before atomic rename');
        }
        $beforeRenameParent = $this->contained_directory_identity($parent, 'attachment destination directory');
        if (!$this->directory_identities_equal($parentIdentity, $beforeRenameParent)) {
            @unlink($temp);
            throw new \RuntimeException('duo: attachment destination directory changed before atomic rename');
        }
        if (!@rename($temp, $target)) {
            @unlink($temp);
            throw new \RuntimeException('duo: attachment destination atomic rename failed');
        }
        $this->sync_file($target);
        $this->sync_directory($parent);
        $afterRenameParent = $this->contained_directory_identity($parent, 'attachment destination directory');
        if (!$this->directory_identities_equal($parentIdentity, $afterRenameParent)) {
            throw new \RuntimeException('duo: attachment destination directory changed during atomic publication; recovery_required');
        }
        $after = $this->observe_path($relative);
        $desired = hash('sha256', $bytes);
        if (!$this->state_is_desired($after, $desired, $publishMode)) {
            throw new \RuntimeException('duo: attachment destination atomic replacement failed exact readback');
        }
    }

    private function ensure_parent_directories(string $relative): void {
        $segments = explode('/', $relative);
        array_pop($segments);
        $path = (string) $this->root;
        foreach ($segments as $segment) {
            $path .= '/' . $segment;
            clearstatcache(true, $path);
            if (!file_exists($path) && !is_link($path)) {
                if (!@mkdir($path, 0755)) {
                    throw new \RuntimeException('duo: attachment destination directory could not be created safely');
                }
                $this->sync_directory(dirname($path));
            }
            $this->assert_contained_directory($path, 'attachment destination directory');
        }
    }

    private function assert_desired_originals(): void {
        foreach ($this->journal['rows'] as $row) {
            $prior = $this->original_prior($row);
            if (!$this->state_is_desired(
                $this->observe_path((string) $row['original_path']),
                (string) $row['original_sha256'],
                (int) $prior['publish_mode']
            )) {
                throw new \RuntimeException('duo: attachment original publication lacks exact final bytes');
            }
        }
    }

    private function assert_prior_inventory(array $journal, bool $allowCompleted): void {
        foreach ($journal['rows'] as $row) {
            $expectedPaths = array_fill_keys(array_map(
                static fn(array $prior): string => (string) $prior['path'],
                $row['prior']
            ), true);
            $currentPaths = $this->inventory_paths($row);
            foreach ($currentPaths as $path) {
                if (!isset($expectedPaths[$path])) {
                    throw new \RuntimeException(
                        'duo: attachment derivative inventory changed after its exact roster was frozen; recovery_required'
                    );
                }
                unset($expectedPaths[$path]);
            }
            foreach (array_keys($expectedPaths) as $extra) {
                if (!in_array($extra, (array) $row['owned_prior_paths'], true)) {
                    throw new \RuntimeException(
                        'duo: attachment prior witness is outside its new-prefix or old-metadata authority'
                    );
                }
            }
            foreach ($row['prior'] as $prior) {
                $current = $this->observe_path((string) $prior['path']);
                if ($allowCompleted
                    && (string) $prior['path'] === (string) $row['original_path']
                    && $this->state_is_desired(
                        $current,
                        (string) $row['original_sha256'],
                        (int) $prior['publish_mode']
                    )) {
                    continue;
                }
                if (!$this->states_equal($prior, $current)) {
                    throw new \RuntimeException('duo: attachment prior inventory changed before rollback classification; recovery_required');
                }
            }
        }
    }

    /** @return list<string> */
    private function inventory_paths(array $row): array {
        $paths = [(string) $row['original_path']];
        $this->assert_path_alias_free((string) $row['original_path']);
        $directory = (string) $row['derivative_directory'];
        $prefix = (string) $row['derivative_prefix'];
        $prefixIdentity = self::portable_path_identity($prefix);
        $absoluteDirectory = $directory === '' ? $this->root : $this->absolute_path($directory);
        if (file_exists($absoluteDirectory) || is_link($absoluteDirectory)) {
            $this->assert_contained_directory($absoluteDirectory, 'attachment derivative directory');
            $entries = new \FilesystemIterator($absoluteDirectory, \FilesystemIterator::SKIP_DOTS);
            $entryCount = 0;
            foreach ($entries as $entry) {
                if (++$entryCount > self::MAX_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'duo: attachment derivative inventory exceeds its bounded directory-entry limit'
                    );
                }
                $name = $entry->getFilename();
                $aliasMatches = str_starts_with(self::portable_path_identity($name), $prefixIdentity);
                if ($aliasMatches && !str_starts_with($name, $prefix)) {
                    throw new \RuntimeException(
                        'duo: attachment derivative authority has a case/Unicode-normalization filesystem alias'
                    );
                }
                if (!$aliasMatches || $name === $prefix) continue;
                if (count($paths) >= self::MAX_DERIVATIVES + 1) {
                    throw new \RuntimeException('duo: attachment derivative inventory exceeds its bounded file limit');
                }
                if ($entry->isLink() || !$entry->isFile()) {
                    throw new \RuntimeException('duo: attachment derivative inventory contains a symlink or special file');
                }
                $paths[] = ($directory === '' ? '' : $directory . '/') . $name;
            }
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    private function original_prior(array $row): array {
        foreach ($row['prior'] as $prior) {
            if (hash_equals((string) $prior['path'], (string) $row['original_path'])) return $prior;
        }
        throw new \RuntimeException('duo: attachment journal lacks an exact original before-image');
    }

    /** @return array<string,mixed> */
    private function observe_path(string $relative): array {
        $path = $this->absolute_path($relative);
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)) {
            if (file_exists($path) || is_link($path)) {
                throw new \RuntimeException('duo: attachment path identity is unreadable');
            }
            return ['path' => $relative, 'sha256' => null, 'size' => 0, 'state' => 'absent'];
        }
        if (is_link($path) || !is_file($path) || (($stat['mode'] ?? 0) & 0170000) !== 0100000) {
            throw new \RuntimeException('duo: attachment path is a symlink or non-regular file');
        }
        $size = $this->canonical_file_size($stat['size'] ?? null);
        if ($size === null || $size > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('duo: attachment path exceeds its bounded byte limit');
        }
        $sha = @hash_file('sha256', $path);
        if (!is_string($sha)) {
            throw new \RuntimeException('duo: attachment path could not be hashed');
        }
        return [
            'dev' => (string) $stat['dev'],
            'ino' => (string) $stat['ino'],
            'mode' => $this->safe_existing_publication_mode($stat),
            'path' => $relative,
            'sha256' => $sha,
            'size' => $size,
            'state' => 'present',
        ];
    }

    private function states_equal(array $expected, array $actual): bool {
        if (($expected['state'] ?? null) !== ($actual['state'] ?? null)
            || !hash_equals((string) ($expected['path'] ?? ''), (string) ($actual['path'] ?? ''))) {
            return false;
        }
        if ($expected['state'] === 'absent') return true;
        return ($expected['size'] ?? null) === ($actual['size'] ?? null)
            && ($expected['mode'] ?? null) === ($actual['mode'] ?? null)
            && is_string($expected['sha256'] ?? null)
            && is_string($actual['sha256'] ?? null)
            && hash_equals($expected['sha256'], $actual['sha256']);
    }

    private function state_is_desired(array $state, string $sha256, int $publishMode): bool {
        return ($state['state'] ?? null) === 'present'
            && ($state['mode'] ?? null) === $publishMode
            && is_string($state['sha256'] ?? null)
            && hash_equals($sha256, $state['sha256']);
    }

    /** @return array{before_image:null,path:string,publish_mode:int,sha256:null,size:0,state:string} */
    private function absent_path_state(string $relative): array {
        return [
            'before_image' => null,
            'path' => $relative,
            'publish_mode' => $this->new_publication_mode($relative),
            'sha256' => null,
            'size' => 0,
            'state' => 'absent',
        ];
    }

    private function safe_existing_publication_mode(array $stat): int {
        $raw = $stat['mode'] ?? null;
        if (!is_int($raw)) {
            throw new \RuntimeException('duo: attachment file mode witness is malformed');
        }
        $mode = $raw & 0777;
        $this->assert_safe_publication_mode($mode);
        return $mode;
    }

    private function new_publication_mode(string $relative): int {
        $path = $this->absolute_path($relative);
        $directory = dirname($path);
        while (!file_exists($directory) && !is_link($directory)) {
            $parent = dirname($directory);
            if (hash_equals($parent, $directory)) {
                throw new \RuntimeException('duo: attachment publication mode lacks a physical parent directory');
            }
            $directory = $parent;
        }
        $identity = $this->contained_directory_identity($directory, 'attachment publication mode parent');
        $raw = $identity['mode'] ?? null;
        if (!is_int($raw)) {
            throw new \RuntimeException('duo: attachment publication parent mode is malformed');
        }
        // WordPress derives upload-file permissions from the containing
        // directory. Duo additionally clears world-write and positively sets
        // owner read/write, so a permissive process umask is never authority.
        $mode = (($raw & 0066) | 0600) & 0664;
        $this->assert_safe_publication_mode($mode);
        return $mode;
    }

    private function is_safe_publication_mode(mixed $mode): bool {
        return is_int($mode)
            && $mode >= 0400
            && $mode <= 0777
            && ($mode & 0400) !== 0
            && ($mode & 0002) === 0;
    }

    private function assert_safe_publication_mode(mixed $mode): void {
        if (!$this->is_safe_publication_mode($mode)) {
            throw new \RuntimeException(
                'duo: attachment publication mode is malformed, unreadable, or world-writable'
            );
        }
    }

    /** @return array<string,mixed> */
    private function seal_generated_result(
        int $position,
        array $row,
        string $stageOriginal,
        array $metadata
    ): array {
        $directory = dirname($stageOriginal);
        $this->assert_private_directory($directory, 'attachment metadata staging directory');
        $entries = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        $files = [];
        $aggregate = 0;
        $originalName = basename((string) $row['original_path']);
        $generatedByName = [];
        foreach ($entries as $entry) {
            $name = $entry->getFilename();
            if ($entry->isLink() || !$entry->isFile()) {
                throw new \RuntimeException('duo: native attachment metadata generated a symlink, directory, or special file');
            }
            $path = $entry->getPathname();
            $stat = @lstat($path);
            $size = is_array($stat) ? $this->canonical_file_size($stat['size'] ?? null) : null;
            $sha = is_array($stat) ? @hash_file('sha256', $path) : false;
            if ($size === null
                || $size > self::MAX_FILE_BYTES
                || !is_string($sha)
                || (((int) ($stat['mode'] ?? 0)) & 0077) !== 0) {
                throw new \RuntimeException('duo: native attachment metadata generated an unreadable or oversized file');
            }
            $aggregate += $size;
            if ($aggregate > self::MAX_ATTACHMENT_BYTES) {
                throw new \RuntimeException('duo: native attachment metadata generated files exceed their aggregate bound');
            }
            if (hash_equals($name, $originalName)) {
                if (!hash_equals((string) $row['original_sha256'], $sha)) {
                    throw new \RuntimeException('duo: native attachment metadata mutated the authored original in staging');
                }
                continue;
            }
            if (count($generatedByName) >= self::MAX_DERIVATIVES
                || !str_starts_with($name, (string) $row['derivative_prefix'])
                || $name === (string) $row['derivative_prefix']
                || strlen($name) > 255
                || str_contains($name, '/')
                || str_contains($name, '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
                || isset($generatedByName[$name])) {
                throw new \RuntimeException('duo: native attachment metadata generated a file outside its bounded derivative authority');
            }
            $target = ((string) $row['derivative_directory'] === ''
                ? ''
                : (string) $row['derivative_directory'] . '/') . $name;
            $this->assert_relative_path($target);
            $prior = $this->prior_for_target($row, $target);
            $generatedByName[$name] = $size;
            $files[] = [
                'publish_mode' => (int) $prior['publish_mode'],
                'sha256' => $sha,
                'size' => $size,
                'stage_path' => 'stage/' . $position . '/' . $name,
                'target_path' => $target,
            ];
        }
        usort($files, static fn(array $a, array $b): int => strcmp($a['target_path'], $b['target_path']));
        $metadata = $this->normalize_generated_metadata($metadata, $row, $stageOriginal, $generatedByName);
        $serialized = serialize($metadata);
        if (strlen($serialized) > self::MAX_METADATA_BYTES) {
            throw new \RuntimeException('duo: normalized attachment metadata exceeds its bounded storage limit');
        }
        return [
            'attachment_id' => (int) $row['attachment_id'],
            'files' => $files,
            'metadata_base64' => base64_encode($serialized),
            'metadata_sha256' => hash('sha256', $serialized),
        ];
    }

    /** @param array<string,int> $generatedByName exact staged derivative bytes */
    private function normalize_generated_metadata(
        array $metadata,
        array $row,
        string $stageOriginal,
        array $generatedByName
    ): array {
        PlainData::assert($metadata, 'native attachment metadata staging result');
        $originalName = basename((string) $row['original_path']);
        $originalStat = @lstat($stageOriginal);
        $originalSize = is_array($originalStat)
            ? $this->canonical_file_size($originalStat['size'] ?? null)
            : null;
        if ($originalSize === null) {
            throw new \RuntimeException('duo: native attachment metadata original filesize witness is malformed');
        }
        if (array_key_exists('file', $metadata)) {
            $file = $metadata['file'];
            if (!is_string($file)
                || !hash_equals(basename($file), $originalName)) {
                throw new \RuntimeException(
                    'duo: native attachment metadata tried to replace the authored attached-file identity'
                );
            }
            // Core derives this from the staged input via
            // _wp_relative_upload_path(). Only the physical staging prefix is
            // rewritten; the basename must already equal the authored file.
            $metadata['file'] = (string) $row['original_path'];
        }
        if (array_key_exists('filesize', $metadata)
            && (!is_int($metadata['filesize']) || $metadata['filesize'] !== $originalSize)) {
            throw new \RuntimeException('duo: native attachment metadata original filesize disagrees with sealed staging bytes');
        }
        $referenced = [];
        if (array_key_exists('sizes', $metadata)) {
            if (!is_array($metadata['sizes'])
                || ($metadata['sizes'] !== [] && array_is_list($metadata['sizes']))) {
                throw new \RuntimeException('duo: native attachment metadata sizes projection is malformed');
            }
            if (count($metadata['sizes']) > self::MAX_DERIVATIVES) {
                throw new \RuntimeException('duo: native attachment metadata sizes projection exceeds its bound');
            }
            foreach ($metadata['sizes'] as $sizeName => $size) {
                if (!is_string($sizeName)
                    || $sizeName === ''
                    || strlen($sizeName) > 191
                    || preg_match('/[\x00-\x1F\x7F]/', $sizeName) === 1
                    || !is_array($size)
                    || !is_string($size['file'] ?? null)) {
                    throw new \RuntimeException('duo: native attachment metadata size row is malformed');
                }
                $file = $size['file'];
                if (!hash_equals($file, basename($file)) || !isset($generatedByName[$file])) {
                    throw new \RuntimeException('duo: native attachment metadata references an undeclared derivative file');
                }
                if (array_key_exists('filesize', $size)
                    && (!is_int($size['filesize']) || $size['filesize'] !== $generatedByName[$file])) {
                    throw new \RuntimeException(
                        'duo: native attachment metadata derivative filesize disagrees with sealed staging bytes'
                    );
                }
                $referenced[$file] = true;
            }
        }
        $unreferenced = array_diff_key($generatedByName, $referenced);
        if ($unreferenced !== []) {
            throw new \RuntimeException('duo: native attachment metadata generated files absent from its metadata projection');
        }
        $stageNeedles = [$stageOriginal, $this->current_directory(), self::DIRECTORY];
        $this->assert_no_staging_reference($metadata, $stageNeedles, 0);
        PlainData::assert($metadata, 'normalized native attachment metadata');
        return $metadata;
    }

    private function assert_no_staging_reference(mixed $value, array $needles, int $depth): void {
        if ($depth > PlainData::MAX_DEPTH) {
            throw new \RuntimeException('duo: native attachment metadata exceeds its normalized depth bound');
        }
        if (is_string($value)) {
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($value, $needle)) {
                    throw new \RuntimeException('duo: native attachment metadata retained a staging path');
                }
            }
            return;
        }
        if (!is_array($value)) return;
        foreach ($value as $child) {
            $this->assert_no_staging_reference($child, $needles, $depth + 1);
        }
    }

    private function read_generation_manifest(): array {
        if ($this->journal === null
            || !is_string($this->journal['generation_sha256'] ?? null)) {
            throw new \RuntimeException('duo: attachment durable journal lacks a generated inventory identity');
        }
        $manifest = $this->read_json_file($this->generation_manifest_path(), 'attachment generation manifest');
        if (array_keys($manifest) !== ['format', 'intent_id', 'rows']
            || ($manifest['format'] ?? null) !== 'duo-attachment-generated-inventory/v1'
            || ($manifest['intent_id'] ?? null) !== $this->journal['intent_id']
            || !is_array($manifest['rows'] ?? null)
            || !array_is_list($manifest['rows'])
            || count($manifest['rows']) !== count($this->journal['rows'])
            || !hash_equals(
                (string) $this->journal['generation_sha256'],
                hash('sha256', Canon::encode($manifest))
            )) {
            throw new \RuntimeException('duo: attachment generated inventory is malformed or changed');
        }
        foreach ($manifest['rows'] as $position => $result) {
            $expected = $this->read_generated_result($position, $this->journal['rows'][$position]);
            if (!hash_equals(Canon::encode($expected), Canon::encode($result))) {
                throw new \RuntimeException('duo: attachment generated row disagrees with its sealed result');
            }
        }
        $this->assert_transaction_byte_budget($manifest);
        return $manifest;
    }

    /**
     * Bound the whole durable attempt, not merely each attachment. A valid
     * 512-row workset must not drive hundreds of GiB of decode/copy/hash work.
     */
    private function assert_transaction_byte_budget(?array $manifest = null): void {
        if ($this->journal === null) return;
        $total = 0;
        foreach ($this->journal['rows'] as $row) {
            $bytes = $this->compiled->media_content((string) $row['media_blob']);
            $size = strlen($bytes);
            if ($size > self::MAX_FILE_BYTES
                || !hash_equals((string) $row['original_sha256'], hash('sha256', $bytes))) {
                throw new \RuntimeException('duo: attachment transaction original payload is oversized or changed');
            }
            $this->add_transaction_bytes($total, $size);
            unset($bytes);
            foreach ($row['prior'] as $prior) {
                $this->add_transaction_bytes($total, (int) ($prior['size'] ?? 0));
            }
        }
        if ($manifest === null) return;
        foreach ((array) ($manifest['rows'] ?? []) as $generated) {
            foreach ((array) ($generated['files'] ?? []) as $file) {
                $this->add_transaction_bytes($total, (int) ($file['size'] ?? 0));
            }
            $serialized = is_string($generated['metadata_base64'] ?? null)
                ? base64_decode($generated['metadata_base64'], true)
                : false;
            if (!is_string($serialized) || strlen($serialized) > self::MAX_METADATA_BYTES) {
                throw new \RuntimeException('duo: attachment transaction metadata payload is malformed or oversized');
            }
            $this->add_transaction_bytes($total, strlen($serialized));
        }
    }

    private function add_transaction_bytes(int &$total, int $bytes): void {
        if ($bytes < 0 || $bytes > self::MAX_TRANSACTION_BYTES - $total) {
            throw new \RuntimeException(
                'duo: attachment filesystem transaction exceeds its 1 GiB aggregate byte authority'
            );
        }
        $total += $bytes;
    }

    private function read_generated_result(int $position, array $journalRow): array {
        $row = $this->read_json_file($this->generated_result_path($position), 'attachment generated result');
        if (array_keys($row) !== ['attachment_id', 'files', 'metadata_base64', 'metadata_sha256']
            || !is_int($row['attachment_id'] ?? null)
            || $row['attachment_id'] !== $journalRow['attachment_id']
            || !is_array($row['files'] ?? null)
            || !array_is_list($row['files'])
            || count($row['files']) > self::MAX_DERIVATIVES
            || !is_string($row['metadata_base64'] ?? null)
            || strlen($row['metadata_base64']) > (int) ceil(self::MAX_METADATA_BYTES * 4 / 3) + 4
            || preg_match('/^[0-9a-f]{64}$/D', (string) ($row['metadata_sha256'] ?? '')) !== 1) {
            throw new \RuntimeException('duo: attachment generated result has an invalid closed shape');
        }
        $serialized = base64_decode($row['metadata_base64'], true);
        if (!is_string($serialized)
            || strlen($serialized) > self::MAX_METADATA_BYTES
            || !hash_equals($row['metadata_sha256'], hash('sha256', $serialized))) {
            throw new \RuntimeException('duo: attachment generated metadata payload does not verify');
        }
        $metadata = PlainData::decode_serialized($serialized, 'attachment generated result');
        if (!is_array($metadata)) {
            throw new \RuntimeException('duo: attachment generated result metadata is not an array');
        }
        $seen = [];
        $aggregate = 0;
        foreach ($row['files'] as $file) {
            if (!is_array($file)
                || array_keys($file) !== ['publish_mode', 'sha256', 'size', 'stage_path', 'target_path']
                || !is_int($file['publish_mode'] ?? null)
                || !$this->is_safe_publication_mode($file['publish_mode'])
                || preg_match('/^[0-9a-f]{64}$/D', (string) ($file['sha256'] ?? '')) !== 1
                || !is_int($file['size'] ?? null)
                || $file['size'] < 0
                || $file['size'] > self::MAX_FILE_BYTES
                || !is_string($file['stage_path'] ?? null)
                || !is_string($file['target_path'] ?? null)
                || !str_starts_with($file['stage_path'], 'stage/' . $position . '/')) {
                throw new \RuntimeException('duo: attachment generated file witness is malformed');
            }
            $this->assert_relative_path($file['stage_path']);
            $this->assert_relative_path($file['target_path']);
            $name = basename($file['target_path']);
            $directory = dirname($file['target_path']) === '.' ? '' : dirname($file['target_path']);
            if (!hash_equals($directory, (string) $journalRow['derivative_directory'])
                || !str_starts_with($name, (string) $journalRow['derivative_prefix'])
                || !hash_equals(basename($file['stage_path']), $name)
                || isset($seen[$file['target_path']])) {
                throw new \RuntimeException('duo: attachment generated file is outside its derivative authority');
            }
            $seen[$file['target_path']] = true;
            $aggregate += $file['size'];
            if ($aggregate > self::MAX_ATTACHMENT_BYTES) {
                throw new \RuntimeException('duo: attachment generated file witnesses exceed their aggregate bound');
            }
            $this->read_bounded_file(
                $this->current_directory() . '/' . $file['stage_path'],
                $file['size'],
                $file['sha256']
            );
        }
        return $row;
    }

    private function assert_prepublication_inventory(array $manifest): void {
        foreach ($this->journal['rows'] as $position => $row) {
            $generated = [];
            foreach ($manifest['rows'][$position]['files'] as $file) {
                $generated[(string) $file['target_path']] = [
                    'publish_mode' => (int) $file['publish_mode'],
                    'sha256' => (string) $file['sha256'],
                ];
            }
            $allowed = [];
            foreach ($row['prior'] as $prior) $allowed[(string) $prior['path']] = true;
            foreach ($generated as $path => $_sha) $allowed[$path] = true;
            $currentPaths = $this->inventory_paths($row);
            foreach ($currentPaths as $path) {
                if (!isset($allowed[$path])) {
                    throw new \RuntimeException('duo: attachment derivative prefix gained an unowned file before publication');
                }
            }
            foreach ($row['prior'] as $prior) {
                $path = (string) $prior['path'];
                $current = $this->observe_path($path);
                if (hash_equals($path, (string) $row['original_path'])
                    && $this->state_is_desired(
                        $current,
                        (string) $row['original_sha256'],
                        (int) $this->original_prior($row)['publish_mode']
                    )) {
                    continue;
                }
                if (isset($generated[$path]) && $this->state_is_desired(
                    $current,
                    $generated[$path]['sha256'],
                    $generated[$path]['publish_mode']
                )) continue;
                if (!$this->states_equal($prior, $current)) {
                    throw new \RuntimeException('duo: attachment prior file changed before derivative publication');
                }
            }
            foreach ($generated as $path => $desired) {
                $prior = $this->prior_for_target($row, $path);
                if (($prior['state'] ?? null) === 'present'
                    && !in_array($path, (array) $row['owned_prior_paths'], true)) {
                    throw new \RuntimeException(
                        'duo: generated attachment derivative collides with an existing file not owned by prior native metadata'
                    );
                }
                if ($prior['state'] === 'absent') {
                    $current = $this->observe_path($path);
                    if (($current['state'] ?? null) !== 'absent' && !$this->state_is_desired(
                        $current,
                        $desired['sha256'],
                        $desired['publish_mode']
                    )) {
                        throw new \RuntimeException('duo: attachment generated target appeared after its absence witness');
                    }
                }
            }
        }
    }

    private function assert_desired_derivatives(): void {
        $manifest = $this->read_generation_manifest();
        foreach ($manifest['rows'] as $row) {
            foreach ($row['files'] as $file) {
                if (!$this->state_is_desired(
                    $this->observe_path((string) $file['target_path']),
                    (string) $file['sha256'],
                    (int) $file['publish_mode']
                )) {
                    throw new \RuntimeException('duo: attachment derivative publication lacks exact final bytes');
                }
            }
        }
    }

    private function assert_final_inventory(): void {
        $manifest = $this->read_generation_manifest();
        $this->assert_desired_originals();
        $this->assert_desired_derivatives();
        foreach ($this->journal['rows'] as $position => $row) {
            $expected = [(string) $row['original_path'] => true];
            foreach ($manifest['rows'][$position]['files'] as $file) {
                $expected[(string) $file['target_path']] = true;
            }
            $desired = $expected;
            foreach ($row['prior'] as $prior) {
                $path = (string) $prior['path'];
                if (!in_array($path, (array) $row['owned_prior_paths'], true) || isset($desired[$path])) {
                    $expected[$path] = true;
                }
            }
            $actual = array_fill_keys($this->inventory_paths($row), true);
            if (array_keys($expected) !== array_keys($actual)) {
                $expectedKeys = array_keys($expected);
                $actualKeys = array_keys($actual);
                sort($expectedKeys, SORT_STRING);
                sort($actualKeys, SORT_STRING);
                if ($expectedKeys !== $actualKeys) {
                    throw new \RuntimeException('duo: attachment final derivative inventory contains stale or unknown files');
                }
            }
        }
    }

    private function prior_for_target(array $row, string $target): array {
        foreach ($row['prior'] as $prior) {
            if (hash_equals((string) $prior['path'], $target)) return $prior;
        }
        return $this->absent_path_state($target);
    }

    private function stage_directory(int $position): string {
        return $this->current_directory() . '/stage/' . $position;
    }

    private function generated_result_path(int $position): string {
        return $this->current_directory() . '/generated/' . $position . '.json';
    }

    private function generation_manifest_path(): string {
        return $this->current_directory() . '/generated/manifest.json';
    }

    private function write_json_file(string $path, array $value, string $purpose): void {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true)) {
            throw new \RuntimeException("duo: $purpose directory could not be created");
        }
        $this->assert_private_directory($directory, "$purpose directory");
        $encoded = Canon::encode($value) . "\n";
        if (strlen($encoded) > 33554432) {
            throw new \RuntimeException("duo: $purpose exceeds its durable JSON byte bound");
        }
        $this->write_new_file($path, $encoded, $purpose);
    }

    private function read_json_file(string $path, string $purpose): array {
        $bytes = $this->read_bounded_file($path, null, null, 33554432);
        try {
            $decoded = Canon::decode($bytes);
        } catch (\Throwable $failure) {
            throw new \RuntimeException("duo: $purpose is not canonical JSON", 0, $failure);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException("duo: $purpose is not one object");
        }
        return $decoded;
    }

    private function write_new_file(string $path, string $bytes, string $purpose): void {
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException("duo: $purpose destination already exists");
        }
        $directory = dirname($path);
        $identity = $this->contained_directory_identity($directory, "$purpose directory");
        $temp = $directory . '/.duo-new-' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temp, 'x+b');
        if (!is_resource($handle)) throw new \RuntimeException("duo: $purpose temp could not be created");
        try {
            $this->harden_private_handle($handle, "$purpose temp");
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if (!is_int($written) || $written <= 0) throw new \RuntimeException("duo: $purpose write failed");
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new \RuntimeException("duo: $purpose durability check failed");
            }
        } finally {
            fclose($handle);
        }
        $after = $this->contained_directory_identity($directory, "$purpose directory");
        if (!$this->directory_identities_equal($identity, $after) || !@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException("duo: $purpose atomic publication failed");
        }
        $this->sync_file($path);
        $this->sync_directory($directory);
    }

    private function read_bounded_file(
        string $path,
        ?int $expectedSize,
        ?string $expectedSha,
        int $maximum = self::MAX_FILE_BYTES
    ): string {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $size = is_array($stat) ? $this->canonical_file_size($stat['size'] ?? null) : null;
        if (!is_array($stat)
            || is_link($path)
            || !is_file($path)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || (((int) ($stat['mode'] ?? 0)) & 0077) !== 0
            || $size === null
            || $size > $maximum
            || ($expectedSize !== null && $size !== $expectedSize)) {
            throw new \RuntimeException('duo: attachment durable file is special, malformed, or oversized');
        }
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)
            || strlen($bytes) !== $size
            || ($expectedSha !== null && !hash_equals($expectedSha, hash('sha256', $bytes)))) {
            throw new \RuntimeException('duo: attachment durable file bytes do not verify');
        }
        return $bytes;
    }

    private function metadata_marker_value(array $journal): string {
        return self::FORMAT . ':' . (string) $journal['intent_id'] . ':metadata:'
            . (string) $journal['authority_sha256'] . ':' . (string) $journal['generation_sha256'];
    }

    private function acquire_journal_locks(): void {
        if ($this->journal === null) return;
        if ($this->locks !== []) return;
        $lockDirectory = (string) $this->journalRoot . '/locks';
        if (!is_dir($lockDirectory) && !@mkdir($lockDirectory, 0700)) {
            throw new \RuntimeException('duo: attachment filesystem lock directory could not be created');
        }
        $this->assert_private_directory($lockDirectory, 'attachment lock directory');
        $lockPath = $lockDirectory . '/transaction.lock';
        try {
            $entryCount = 0;
            foreach (new \FilesystemIterator($lockDirectory, \FilesystemIterator::SKIP_DOTS) as $entry) {
                if (++$entryCount > 1 || !hash_equals($entry->getFilename(), 'transaction.lock')) {
                    throw new \RuntimeException(
                        'duo: attachment lock registry exceeds its single bounded authority'
                    );
                }
            }
            $existed = file_exists($lockPath) || is_link($lockPath);
            if (is_link($lockPath)) {
                throw new \RuntimeException('duo: attachment transaction lock identity is unsafe');
            }
            $handle = @fopen($lockPath, 'c+b');
            if (!is_resource($handle)) {
                throw new \RuntimeException('duo: attachment transaction lock could not be opened');
            }
            try {
                if (!$existed) {
                    $this->harden_private_handle($handle, 'attachment transaction lock');
                }
                $opened = fstat($handle);
                $named = @lstat($lockPath);
                if (!is_array($opened) || !is_array($named) || is_link($lockPath) || !is_file($lockPath)
                    || (((int) ($named['mode'] ?? 0)) & 0077) !== 0
                    || ($named['size'] ?? null) !== 0
                    || (string) ($opened['dev'] ?? '') !== (string) ($named['dev'] ?? '')
                    || (string) ($opened['ino'] ?? '') !== (string) ($named['ino'] ?? '')
                    || !flock($handle, LOCK_EX | LOCK_NB)) {
                    throw new \RuntimeException('duo: attachment transaction lock identity is unsafe');
                }
            } catch (\Throwable $failure) {
                fclose($handle);
                throw $failure;
            }
            // One stable inode serializes every attachment path owned by Duo.
            // Per-path files are not unlink-safe under flock and accumulate an
            // unbounded durable inode roster across ordinary renames.
            $this->locks['transaction'] = $handle;
        } catch (\Throwable $failure) {
            $this->release_locks();
            throw $failure;
        }
    }

    private function release_locks(): void {
        foreach ($this->locks as $handle) {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
        $this->locks = [];
    }

    private function initialize_roots(bool $createJournalRoot): bool {
        if ($this->root === null) {
            $uploads = wp_upload_dir(null, false);
            $root = is_array($uploads) ? ($uploads['basedir'] ?? null) : null;
            $error = is_array($uploads) ? ($uploads['error'] ?? false) : null;
            if (!is_string($root) || $root === '' || ($error !== false && $error !== '')) {
                throw new \RuntimeException('duo: attachment filesystem could not resolve a healthy uploads root');
            }
            $root = rtrim($root, '/\\');
            if ($root === '' || !str_starts_with($root, '/')) {
                throw new \RuntimeException('duo: attachment filesystem requires an absolute uploads root');
            }
            $this->assert_directory($root, 'uploads root');
            $real = realpath($root);
            if (!is_string($real) || $real === '') {
                throw new \RuntimeException('duo: attachment filesystem uploads root has no physical identity');
            }
            $this->root = rtrim($real, '/');
        }
        if ($this->journalRoot === null) {
            $candidate = rtrim($this->repositoryRoot, '/');
            $named = $candidate === '' ? false : @lstat($candidate);
            $real = $candidate === '' ? false : realpath($candidate);
            if (!str_starts_with($candidate, '/')
                || !is_array($named)
                || is_link($candidate)
                || !is_dir($candidate)
                || (($named['mode'] ?? 0) & 0170000) !== 0040000
                || !is_string($real)
                || !hash_equals($candidate, rtrim($real, '/'))) {
                throw new \RuntimeException(
                    'duo: attachment filesystem requires an exact physical repository control root'
                );
            }
            if ($real === $this->root
                || str_starts_with($real . '/', $this->root . '/')
                || str_starts_with($this->root . '/', $real . '/')) {
                throw new \RuntimeException(
                    'duo: attachment filesystem control state must be outside the web-served uploads tree'
                );
            }
            if (defined('ABSPATH')) {
                $wordpress = realpath((string) ABSPATH);
                if (is_string($wordpress)
                    && ($real === rtrim($wordpress, '/')
                        || str_starts_with($real . '/', rtrim($wordpress, '/') . '/'))) {
                    throw new \RuntimeException(
                        'duo: attachment filesystem control state must be outside the WordPress document tree'
                    );
                }
            }
            $control = $real . '/.duo';
            if (!file_exists($control) && !is_link($control)) {
                if (!$createJournalRoot) return false;
                if (!@mkdir($control, 0700)) {
                    throw new \RuntimeException('duo: attachment filesystem could not create its private control directory');
                }
                $this->sync_directory($real);
            }
            $this->assert_directory($control, 'attachment private control directory');
            $this->journalRoot = $control . '/' . self::DIRECTORY;
        }
        if (!file_exists($this->journalRoot) && !is_link($this->journalRoot)) {
            if (!$createJournalRoot) return false;
            if (!@mkdir($this->journalRoot, 0700)) {
                throw new \RuntimeException('duo: attachment filesystem journal root could not be created');
            }
            $this->sync_directory(dirname($this->journalRoot));
        }
        $this->assert_private_directory($this->journalRoot, 'attachment journal root');
        return true;
    }

    private function current_directory(): string {
        return (string) $this->journalRoot . '/current';
    }

    private function journal_path(): string {
        return $this->current_directory() . '/journal.json';
    }

    private function write_journal(): void {
        if ($this->journal === null) return;
        $bytes = Canon::encode($this->journal) . "\n";
        if (strlen($bytes) > 1048576) {
            throw new \RuntimeException('duo: attachment durable journal exceeds its encoded byte limit');
        }
        $path = $this->journal_path();
        $directory = dirname($path);
        $this->assert_private_directory($directory, 'attachment intent directory');
        $temp = $directory . '/.journal-' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temp, 'x+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('duo: attachment journal temp file could not be created');
        }
        try {
            $this->harden_private_handle($handle, 'attachment journal temp');
            $written = fwrite($handle, $bytes);
            if ($written !== strlen($bytes) || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new \RuntimeException('duo: attachment journal durable write failed');
            }
        } finally {
            fclose($handle);
        }
        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('duo: attachment journal atomic publication failed');
        }
        $this->sync_file($path);
        $this->sync_directory($directory);
    }

    private function read_journal(): array {
        $path = $this->journal_path();
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException('duo: attachment durable journal is missing or not a regular file');
        }
        $this->assert_private_file($path, 'attachment durable journal');
        $size = @filesize($path);
        if (!is_int($size) || $size < 2 || $size > 1048576) {
            throw new \RuntimeException('duo: attachment durable journal exceeds its bounded byte limit');
        }
        $decoded = Canon::decode(Canon::read_file($path));
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException('duo: attachment durable journal is malformed');
        }
        $this->assert_journal($decoded);
        return $decoded;
    }

    private function assert_journal(array $journal): void {
        $keys = array_keys($journal);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'artifact_hash', 'authority_sha256', 'format', 'generation_sha256',
            'intent_id', 'marker_key', 'phase', 'rows',
        ]
            || ($journal['format'] ?? null) !== self::FORMAT
            || preg_match('/^[0-9a-f]{32}$/D', (string) ($journal['intent_id'] ?? '')) !== 1
            || ($journal['marker_key'] ?? null) !== 'attachment_fs:' . $journal['intent_id']
            || preg_match('/^[0-9a-f]{64}$/D', (string) ($journal['artifact_hash'] ?? '')) !== 1
            || !in_array($journal['phase'] ?? null, [
                'preparing', 'prepared', 'authored_prepared', 'publishing_originals', 'originals_published',
                'generating_metadata', 'metadata_generated', 'publishing_derivatives',
                'derivatives_published', 'metadata_committing', 'metadata_committed',
                'removing_stale', 'complete',
            ], true)
            || !is_array($journal['rows'] ?? null)
            || !array_is_list($journal['rows'])
            || count($journal['rows']) < 1
            || count($journal['rows']) > self::MAX_DERIVATIVES) {
            throw new \RuntimeException('duo: attachment durable journal has an invalid closed shape');
        }
        $hasGeneration = in_array($journal['phase'], [
            'metadata_generated', 'publishing_derivatives', 'derivatives_published',
            'metadata_committing', 'metadata_committed', 'removing_stale', 'complete',
        ], true);
        if ($hasGeneration
            ? (!is_string($journal['generation_sha256'])
                || preg_match('/^[0-9a-f]{64}$/D', $journal['generation_sha256']) !== 1)
            : $journal['generation_sha256'] !== null) {
            throw new \RuntimeException('duo: attachment durable journal has an invalid metadata-generation identity');
        }
        $this->assert_journal_rows($journal);
        if ($journal['phase'] === 'preparing') {
            if ($journal['authority_sha256'] !== null) {
                throw new \RuntimeException('duo: preparing attachment journal has premature authority bytes');
            }
            return;
        }
        if (!is_string($journal['authority_sha256'])
            || preg_match('/^[0-9a-f]{64}$/D', $journal['authority_sha256']) !== 1
            || !hash_equals($journal['authority_sha256'], $this->authority_hash($journal))) {
            throw new \RuntimeException('duo: attachment durable journal authority hash does not verify');
        }
    }

    private function assert_journal_rows(array $journal): void {
        $seenUuid = [];
        $seenPath = [];
        foreach ($journal['rows'] as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \RuntimeException('duo: attachment durable journal row is malformed');
            }
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys !== [
                'attachment_id', 'attachment_uuid', 'derivative_directory', 'derivative_prefix',
                'media_blob', 'mime', 'original_path', 'original_sha256', 'original_status',
                'owned_prior_paths', 'prior',
            ]
                || (!is_null($row['attachment_id'])
                    && (!is_int($row['attachment_id']) || $row['attachment_id'] <= 0))
                || preg_match(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                    (string) ($row['attachment_uuid'] ?? '')
                ) !== 1
                || preg_match('/^[0-9a-f]{64}$/D', (string) ($row['original_sha256'] ?? '')) !== 1
                || !is_string($row['media_blob'] ?? null)
                || strlen($row['media_blob']) < 65
                || strlen($row['media_blob']) > 512
                || !is_string($row['mime'] ?? null)
                || $row['mime'] === ''
                || strlen($row['mime']) > 191
                || !is_string($row['derivative_directory'] ?? null)
                || !is_string($row['derivative_prefix'] ?? null)
                || $row['derivative_prefix'] === ''
                || strlen($row['derivative_prefix']) > 255
                || str_contains($row['derivative_prefix'], '/')
                || str_contains($row['derivative_prefix'], '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $row['derivative_prefix']) === 1
                || !in_array($row['original_status'] ?? null, ['pending', 'complete'], true)
                || !is_array($row['owned_prior_paths'] ?? null)
                || !array_is_list($row['owned_prior_paths'])
                || count($row['owned_prior_paths']) > self::MAX_OWNED_PRIOR_FILES
                || !is_array($row['prior'] ?? null)
                || !array_is_list($row['prior'])
                || count($row['prior']) > self::MAX_OWNED_PRIOR_FILES) {
                throw new \RuntimeException('duo: attachment durable journal row has an invalid closed shape');
            }
            $this->assert_relative_path((string) $row['original_path']);
            if ($row['derivative_directory'] !== '') {
                $this->assert_relative_path((string) $row['derivative_directory']);
            }
            $uuid = (string) $row['attachment_uuid'];
            $path = (string) $row['original_path'];
            $pathIdentity = self::portable_path_identity($path);
            if (isset($seenUuid[$uuid]) || isset($seenPath[$pathIdentity])) {
                throw new \RuntimeException('duo: attachment durable journal has duplicate attachment authority');
            }
            $seenUuid[$uuid] = true;
            $seenPath[$pathIdentity] = true;
            $seenPrior = [];
            $aggregate = 0;
            foreach ($row['prior'] as $prior) {
                if (!is_array($prior) || array_is_list($prior)) {
                    throw new \RuntimeException('duo: attachment durable prior row is malformed');
                }
                $priorKeys = array_keys($prior);
                sort($priorKeys, SORT_STRING);
                $state = $prior['state'] ?? null;
                $expectedKeys = $state === 'absent'
                    ? ['before_image', 'path', 'publish_mode', 'sha256', 'size', 'state']
                    : ['before_image', 'dev', 'ino', 'mode', 'path', 'publish_mode', 'sha256', 'size', 'state'];
                if ($priorKeys !== $expectedKeys
                    || !in_array($state, ['absent', 'present'], true)
                    || !is_string($prior['path'] ?? null)
                    || !is_int($prior['size'] ?? null)
                    || $prior['size'] < 0
                    || $prior['size'] > self::MAX_FILE_BYTES
                    || !$this->is_safe_publication_mode($prior['publish_mode'] ?? null)
                    || ($state === 'absent'
                        ? ($prior['before_image'] !== null || $prior['sha256'] !== null || $prior['size'] !== 0)
                        : (!is_string($prior['before_image'])
                            || preg_match('/^before\/[0-9]+-[0-9]+\.bin$/D', $prior['before_image']) !== 1
                            || !is_string($prior['dev'] ?? null)
                            || !is_string($prior['ino'] ?? null)
                            || !is_int($prior['mode'] ?? null)
                            || $prior['mode'] !== $prior['publish_mode']
                            || preg_match('/^[0-9a-f]{64}$/D', (string) $prior['sha256']) !== 1))) {
                    throw new \RuntimeException('duo: attachment durable prior row has an invalid closed shape');
                }
                $this->assert_relative_path($prior['path']);
                $priorIdentity = self::portable_path_identity($prior['path']);
                if (isset($seenPrior[$priorIdentity])) {
                    throw new \RuntimeException('duo: attachment durable prior inventory has duplicate paths');
                }
                $seenPrior[$priorIdentity] = true;
                $aggregate += $prior['size'];
                if ($aggregate > self::MAX_ATTACHMENT_BYTES) {
                    throw new \RuntimeException('duo: attachment durable prior inventory exceeds its byte limit');
                }
                if ($state === 'present') {
                    $before = $this->current_directory() . '/' . $prior['before_image'];
                    $this->assert_owned_before_image($before, $prior);
                }
            }
            $seenOwned = [];
            foreach ($row['owned_prior_paths'] as $ownedPath) {
                if (!is_string($ownedPath)) {
                    throw new \RuntimeException('duo: attachment durable owned-prior roster is malformed');
                }
                $this->assert_relative_path($ownedPath);
                $ownedIdentity = self::portable_path_identity($ownedPath);
                if (isset($seenOwned[$ownedIdentity]) || !isset($seenPrior[$ownedIdentity])) {
                    throw new \RuntimeException(
                        'duo: attachment durable owned-prior roster is duplicate or lacks a frozen witness'
                    );
                }
                $seenOwned[$ownedIdentity] = true;
            }
            if ($journal['phase'] !== 'preparing'
                && (!isset($seenPrior[$pathIdentity]) || $row['prior'] === [])) {
                throw new \RuntimeException('duo: attachment durable journal lacks its original prior witness');
            }
        }
    }

    private function assert_owned_before_image(string $path, array $prior): void {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $size = is_array($stat) ? $this->canonical_file_size($stat['size'] ?? null) : null;
        $sha = is_array($stat) && !is_link($path) && is_file($path) ? @hash_file('sha256', $path) : false;
        if (!is_array($stat)
            || is_link($path)
            || !is_file($path)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || $size !== $prior['size']
            || !is_string($sha)
            || !hash_equals((string) $prior['sha256'], $sha)) {
            throw new \RuntimeException('duo: attachment durable before-image does not match its journal witness');
        }
    }

    private function authority_hash(array $journal): string {
        $rows = [];
        foreach ($journal['rows'] as $row) {
            $copy = $row;
            unset($copy['original_status']);
            $rows[] = $copy;
        }
        return hash('sha256', Canon::encode([
            'artifact_hash' => $journal['artifact_hash'],
            'format' => $journal['format'],
            'intent_id' => $journal['intent_id'],
            'rows' => $rows,
        ]));
    }

    private function marker_value(array $journal): string {
        return self::FORMAT . ':' . (string) $journal['intent_id'] . ':' . (string) $journal['authority_sha256'];
    }

    private function remove_current_journal(): void {
        $current = $this->current_directory();
        $this->remove_owned_tree($current);
        $this->sync_directory((string) $this->journalRoot);
    }

    private function remove_owned_tree(string $directory): void {
        $this->assert_private_directory($directory, 'attachment intent directory');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink()) {
                throw new \RuntimeException('duo: attachment journal cleanup found a symbolic link');
            }
            if ($entry->isDir()) {
                if (!@rmdir($path)) throw new \RuntimeException('duo: attachment journal directory cleanup failed');
            } elseif ($entry->isFile()) {
                if (!@unlink($path)) throw new \RuntimeException('duo: attachment journal file cleanup failed');
            } else {
                throw new \RuntimeException('duo: attachment journal cleanup found a special file');
            }
        }
        if (!@rmdir($directory)) {
            throw new \RuntimeException('duo: attachment journal root cleanup failed');
        }
    }

    private function absolute_path(string $relative): string {
        $this->assert_relative_path($relative);
        $path = (string) $this->root . '/' . $relative;
        $parent = dirname($path);
        if (file_exists($parent) || is_link($parent)) {
            $this->assert_contained_directory($parent, 'attachment path parent');
        }
        return $path;
    }

    /** @param list<array<string,mixed>> $rows */
    private function assert_planned_filesystem_aliases(array $rows): void {
        foreach ($rows as $row) {
            $this->assert_path_alias_free((string) $row['original_path']);
            $directory = (string) $row['derivative_directory'];
            if ($directory !== '') {
                $this->assert_path_alias_free($directory);
            }
        }
    }

    /**
     * Refuse byte-different names that a case-insensitive or normalization-
     * folding target could resolve as the same upload authority. Directory
     * enumeration is deliberately bounded before any authored DB mutation.
     */
    private function assert_path_alias_free(string $relative): void {
        $this->assert_relative_path($relative);
        $directory = (string) $this->root;
        foreach (explode('/', $relative) as $segment) {
            if (!is_dir($directory)) return;
            $wanted = self::portable_path_identity($segment);
            $exact = false;
            $count = 0;
            foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
                if (++$count > self::MAX_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'duo: attachment filesystem alias proof exceeds its bounded directory-entry limit'
                    );
                }
                $name = $entry->getFilename();
                if (!hash_equals($wanted, self::portable_path_identity($name))) continue;
                if (!hash_equals($segment, $name)) {
                    throw new \RuntimeException(
                        'duo: attachment upload authority has a case/Unicode-normalization filesystem alias'
                    );
                }
                $exact = true;
            }
            if (!$exact) return;
            $directory .= '/' . $segment;
        }
    }

    public static function portable_path_identity(string $value): string {
        if (preg_match('//u', $value) !== 1) {
            throw new \RuntimeException('duo: attachment filesystem alias proof received invalid UTF-8');
        }
        if (preg_match('/^[\x00-\x7F]*$/D', $value) === 1) {
            return strtolower($value);
        }
        if (!class_exists(\Normalizer::class)
            || !function_exists('mb_convert_case')
            || !defined('MB_CASE_FOLD')) {
            throw new \RuntimeException(
                'duo: attachment Unicode filesystem alias proof requires normalization/case-fold support'
            );
        }
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
        if (!is_string($normalized)) {
            throw new \RuntimeException('duo: attachment Unicode filesystem alias normalization failed');
        }
        return mb_convert_case($normalized, MB_CASE_FOLD, 'UTF-8');
    }

    private function assert_relative_path(string $relative): void {
        $characters = strlen($relative) <= self::MAX_RELATIVE_BYTES ? preg_match_all('/./us', $relative) : false;
        $segments = explode('/', $relative);
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\')
            || !is_int($characters) || preg_match('/[\x00-\x1F\x7F]/', $relative) === 1
            || array_filter($segments, static fn(string $segment): bool =>
                $segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255)) {
            throw new \RuntimeException('duo: attachment upload path is not a bounded normalized relative path');
        }
    }

    private function assert_directory(string $path, string $label): void {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || !is_dir($path) || (($stat['mode'] ?? 0) & 0170000) !== 0040000) {
            throw new \RuntimeException("duo: $label is missing, symlinked, or not a directory");
        }
    }

    private function assert_private_directory(string $path, string $label): void {
        $this->assert_directory($path, $label);
        $stat = @lstat($path);
        if (!is_array($stat) || (((int) ($stat['mode'] ?? 0)) & 0077) !== 0) {
            throw new \RuntimeException("duo: $label permits group/other access to private attachment state");
        }
    }

    /** @param resource $handle */
    private function harden_private_handle($handle, string $label): void {
        $metadata = @stream_get_meta_data($handle);
        $path = is_array($metadata) ? ($metadata['uri'] ?? null) : null;
        if (!is_string($path) || $path === '' || !@chmod($path, 0600)) {
            throw new \RuntimeException("duo: $label could not be restricted to owner-only access");
        }
        $stat = @fstat($handle);
        if (!is_array($stat) || (((int) ($stat['mode'] ?? 0)) & 0077) !== 0) {
            throw new \RuntimeException("duo: $label retained group/other access");
        }
    }

    private function assert_private_file(string $path, string $label): void {
        $stat = @lstat($path);
        if (!is_array($stat)
            || is_link($path)
            || !is_file($path)
            || (((int) ($stat['mode'] ?? 0)) & 0077) !== 0) {
            throw new \RuntimeException("duo: $label is not an owner-only regular file");
        }
    }

    private function assert_contained_directory(string $path, string $label): void {
        $this->contained_directory_identity($path, $label);
    }

    /** @return array{dev:string,ino:string,mode:int,real:string} */
    private function contained_directory_identity(string $path, string $label): array {
        $this->assert_directory($path, $label);
        $stat = @lstat($path);
        $real = realpath($path);
        if (!is_array($stat)
            || !is_string($real)
            || (($real !== $this->root && !str_starts_with($real, (string) $this->root . '/'))
                && ($real !== $this->journalRoot && !str_starts_with($real, (string) $this->journalRoot . '/')))) {
            throw new \RuntimeException("duo: $label escapes the physical uploads or private control root");
        }
        return [
            'dev' => (string) ($stat['dev'] ?? ''),
            'ino' => (string) ($stat['ino'] ?? ''),
            'mode' => (int) ($stat['mode'] ?? 0),
            'real' => $real,
        ];
    }

    private function directory_identities_equal(array $left, array $right): bool {
        return ($left['dev'] ?? null) === ($right['dev'] ?? null)
            && ($left['ino'] ?? null) === ($right['ino'] ?? null)
            && ($left['mode'] ?? null) === ($right['mode'] ?? null)
            && ($left['real'] ?? null) === ($right['real'] ?? null);
    }

    private function canonical_file_size(mixed $value): ?int {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function sync_file(string $path): void {
        DurableFilesystem::syncFile($path);
    }

    private function sync_directory(string $path): void {
        DurableFilesystem::syncDirectory($path);
    }
}
