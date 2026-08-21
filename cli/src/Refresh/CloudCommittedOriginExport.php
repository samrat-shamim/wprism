<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once __DIR__ . '/RefreshProductionSource.php';

/**
 * Fully reconstructed, content-verified production observation.
 *
 * Construction verifies the service manifest, every aggregate identity, the
 * exact agent Canon bytes, and the production snapshot hash. Callers receive
 * neither a cloud path nor a chunk reader after this boundary: only immutable
 * semantic production truth bound to one exact Git commit.
 */
final class CloudCommittedOriginExport implements RefreshProductionSource {
    private const MANIFEST_FORMAT = 'duo-cloud-origin-export-manifest/v1';
    private const EXPORT_FORMAT = 'duo-refresh-production/v1';
    private const MAX_EXPORT_SIZE = 67108864;

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $snapshot
     */
    private function __construct(
        private string $environmentName,
        private string $expectedCommit,
        private array $manifest,
        private array $snapshot,
        private string $canonicalBytes
    ) {}

    /** @param array<string,mixed> $manifest */
    public static function fromWire(string $environmentName, array $manifest, string $bytes): self {
        self::identifier($environmentName, 'cloud origin production environment name');
        self::assertManifest($manifest);
        if (strlen($bytes) !== $manifest['export_size']
            || !hash_equals($manifest['export_sha256'], hash('sha256', $bytes))) {
            throw new \RuntimeException('cloud origin export bytes do not match the committed manifest');
        }
        try {
            $tree = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $snapshot = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\Throwable $error) {
            throw new \RuntimeException('cloud origin export is not valid JSON', 0, $error);
        }
        if (!$tree instanceof \stdClass || !is_array($snapshot) || array_is_list($snapshot)
            || \Duo\Canon::encode($tree) !== $bytes) {
            throw new \RuntimeException('cloud origin export is not exact agent-canonical JSON');
        }
        self::assertSnapshot($snapshot, $manifest);
        return new self(
            $environmentName,
            $manifest['expected_production_commit'],
            $manifest,
            $snapshot,
            $bytes
        );
    }

    public function productionEnvironmentName(): string {
        return $this->environmentName;
    }

    public function assertProductionRevision(string $expectedCommit): void {
        self::commit($expectedCommit, 'requested production commit');
        if (!hash_equals($this->expectedCommit, $expectedCommit)) {
            throw new \RuntimeException('cloud origin export is bound to another production commit');
        }
    }

    public function readProductionSnapshot(string $expectedCommit, ?array $scopeContract = null): array {
        $this->assertProductionRevision($expectedCommit);
        if ($scopeContract !== null) {
            throw new \RuntimeException('cloud origin portable export is full-site and cannot satisfy a scoped refresh');
        }
        return $this->snapshot;
    }

    /** @return array<string,mixed> */
    public function manifest(): array {
        return $this->manifest;
    }

    public function canonicalBytes(): string {
        return $this->canonicalBytes;
    }

    /** @param array<string,mixed> $manifest */
    private static function assertManifest(array $manifest): void {
        self::exactKeys($manifest, [
            'artifact_hash', 'chunks', 'code_revision', 'expected_production_commit',
            'export_sha256', 'export_size', 'format', 'generation', 'manifest_sha256',
            'repository_revision_hash', 'snapshot_hash',
        ], 'cloud origin manifest');
        if (($manifest['format'] ?? null) !== self::MANIFEST_FORMAT
            || !is_array($manifest['chunks'] ?? null) || !array_is_list($manifest['chunks'])
            || $manifest['chunks'] === []) {
            throw new \RuntimeException('cloud origin committed manifest is malformed');
        }
        foreach (['artifact_hash', 'export_sha256', 'manifest_sha256', 'repository_revision_hash', 'snapshot_hash'] as $field) {
            self::sha256($manifest[$field] ?? null, "cloud origin manifest $field");
        }
        if (($manifest['code_revision'] ?? null) !== null) {
            self::sha256($manifest['code_revision'], 'cloud origin manifest code revision');
        }
        self::commit($manifest['expected_production_commit'] ?? null, 'cloud origin expected production commit');
        if (!is_int($manifest['generation'] ?? null) || $manifest['generation'] < 1
            || !is_int($manifest['export_size'] ?? null) || $manifest['export_size'] < 1
            || $manifest['export_size'] > self::MAX_EXPORT_SIZE) {
            throw new \RuntimeException('cloud origin committed manifest size or generation is invalid');
        }
        $offset = 0;
        $last = count($manifest['chunks']) - 1;
        foreach ($manifest['chunks'] as $position => $chunk) {
            if (!is_array($chunk) || array_is_list($chunk)) {
                throw new \RuntimeException('cloud origin committed manifest chunk is malformed');
            }
            self::exactKeys($chunk, ['index', 'offset', 'sha256', 'size'], 'cloud origin manifest chunk');
            if (($chunk['index'] ?? null) !== $position || ($chunk['offset'] ?? null) !== $offset
                || !is_int($chunk['size'] ?? null) || $chunk['size'] < 1 || $chunk['size'] > 1048576
                || ($position < $last && $chunk['size'] !== 1048576)) {
                throw new \RuntimeException('cloud origin committed manifest chunk sequence is invalid');
            }
            self::sha256($chunk['sha256'] ?? null, 'cloud origin manifest chunk hash');
            $offset += $chunk['size'];
        }
        if ($offset !== $manifest['export_size']) {
            throw new \RuntimeException('cloud origin committed manifest chunks do not cover its export');
        }
        $basis = $manifest;
        unset($basis['manifest_sha256']);
        if (!hash_equals($manifest['manifest_sha256'], hash('sha256', \Duo\Canon::encode($basis)))) {
            throw new \RuntimeException('cloud origin committed manifest hash does not verify');
        }
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $manifest */
    private static function assertSnapshot(array $snapshot, array $manifest): void {
        self::exactKeys($snapshot, [
            'completed_code', 'deletions', 'format', 'media', 'policy', 'records',
            'repository', 'snapshot_hash', 'warnings',
        ], 'cloud origin production snapshot');
        if (($snapshot['format'] ?? null) !== self::EXPORT_FORMAT
            || !is_array($snapshot['deletions'] ?? null) || !array_is_list($snapshot['deletions'])
            || !is_array($snapshot['media'] ?? null)
            || !is_array($snapshot['policy'] ?? null) || array_is_list($snapshot['policy'])
            || !is_array($snapshot['records'] ?? null)
            || !is_array($snapshot['repository'] ?? null) || array_is_list($snapshot['repository'])
            || !is_array($snapshot['warnings'] ?? null) || !array_is_list($snapshot['warnings'])
            || ($snapshot['completed_code'] !== null
                && (!is_array($snapshot['completed_code']) || array_is_list($snapshot['completed_code'])))) {
            throw new \RuntimeException('cloud origin production snapshot shape is invalid');
        }
        self::exactKeys(
            $snapshot['repository'],
            ['artifact_hash', 'code_revision', 'revision_hash'],
            'cloud origin snapshot repository'
        );
        self::sha256($snapshot['repository']['artifact_hash'] ?? null, 'snapshot artifact hash');
        self::sha256($snapshot['repository']['revision_hash'] ?? null, 'snapshot revision hash');
        if (($snapshot['repository']['code_revision'] ?? null) !== null) {
            self::sha256($snapshot['repository']['code_revision'], 'snapshot code revision');
        }
        self::sha256($snapshot['snapshot_hash'] ?? null, 'snapshot hash');
        $basis = $snapshot;
        unset($basis['snapshot_hash']);
        if (!hash_equals($snapshot['snapshot_hash'], hash('sha256', \Duo\Canon::encode($basis)))
            || !hash_equals($manifest['snapshot_hash'], $snapshot['snapshot_hash'])
            || !hash_equals($manifest['artifact_hash'], $snapshot['repository']['artifact_hash'])
            || !hash_equals(
                $manifest['repository_revision_hash'],
                $snapshot['repository']['revision_hash']
            )
            || $manifest['code_revision'] !== $snapshot['repository']['code_revision']) {
            throw new \RuntimeException('cloud origin production snapshot identities do not verify');
        }
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("$label has unknown or missing fields");
        }
    }

    private static function sha256(mixed $value, string $label): string {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new \RuntimeException("$label must be lowercase SHA-256");
        }
        return $value;
    }

    private static function commit(mixed $value, string $label): string {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/D', $value) !== 1) {
            throw new \RuntimeException("$label is malformed");
        }
        return $value;
    }

    private static function identifier(mixed $value, string $label): string {
        if (!is_string($value)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new \RuntimeException("$label is invalid");
        }
        return $value;
    }
}
