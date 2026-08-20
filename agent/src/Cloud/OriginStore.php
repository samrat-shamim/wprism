<?php
declare(strict_types=1);

namespace Duo;

if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}

/**
 * Private, content-addressed spool for one outbound production observation.
 *
 * A database snapshot cannot be resumed after its PHP process exits. The only
 * resumable boundary is therefore a complete immutable artifact: chunks and
 * their canonical manifest are built under a private sibling, the manifest is
 * written last, and the complete directory is renamed into visibility once.
 * A caller finding that published directory reads only these sealed bytes.
 * This local recovery manifest is deliberately not the paired cloud upload
 * manifest: the outbound connector must first bind its tenant/site/demand
 * generation, then map these verified identities into that signed wire shape.
 */
final class OriginStore {
    public const MANIFEST_FORMAT = 'duo-origin-export-manifest/v1';
    public const WIRE_MANIFEST_FORMAT = 'duo-cloud-origin-export-manifest/v1';
    public const EXPORT_FORMAT = 'duo-refresh-production/v1';
    public const CHUNK_BYTES = 1048576;
    public const MAX_ARTIFACT_BYTES = 67108864;
    private const MAX_MANIFEST_BYTES = 1048576;
    private const DISCARD_FORMAT = 'duo-origin-export-discard/v1';

    private string $root;
    private string $sessions;
    /** @var array<string,array<string,mixed>> */
    private array $verifiedManifests = [];
    /** @var array<string,list<string>> */
    private array $verifiedChunks = [];

    private function __construct(private string $repo) {
        $duo = $repo . '/.duo';
        $control = $duo . '/control';
        self::assertProtectedDirectory($duo, null, 'Duo state');
        self::assertProtectedDirectory($control, 0700, 'Duo control');
        $this->root = $control . '/cloud-origin';
        $this->sessions = $this->root . '/sessions';
        self::ensurePrivateDirectory($this->root, 'cloud-origin control');
        self::ensurePrivateDirectory($this->sessions, 'cloud-origin sessions');
    }

    public static function open(string $repo): self {
        if ($repo === '' || $repo[0] !== '/' || str_contains($repo, "\0")) {
            throw new \RuntimeException('duo: origin export requires an absolute repository path');
        }
        $lexical = rtrim($repo, '/');
        $stat = self::freshLstat($lexical);
        if (!is_array($stat) || self::kind($stat) !== 0040000 || is_link($lexical)) {
            throw new \RuntimeException('duo: origin export repository must be a non-symlink directory');
        }
        $resolved = realpath($lexical);
        if (!is_string($resolved)) {
            throw new \RuntimeException('duo: origin export repository could not be resolved');
        }
        self::assertProtectedDirectory($resolved, null, 'origin export repository');
        return new self(rtrim($resolved, '/'));
    }

    public function repository(): string {
        return $this->repo;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function withExclusive(callable $callback): mixed {
        $path = $this->root . '/export.lock';
        self::assertRegularOrAbsent($path, 'origin export lock');
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('duo: origin export lock could not be opened');
        }
        try {
            if (!@chmod($path, 0600)) {
                throw new \RuntimeException('duo: origin export lock permissions could not be restricted');
            }
            $opened = fstat($handle);
            $named = self::freshLstat($path);
            if (!is_array($opened) || !is_array($named)
                || self::kind($opened) !== 0100000 || self::kind($named) !== 0100000
                || (string) ($opened['dev'] ?? '') !== (string) ($named['dev'] ?? '')
                || (string) ($opened['ino'] ?? '') !== (string) ($named['ino'] ?? '')
                || !self::privateMode($named, 0600) || !self::ownedByProcess($named)) {
                throw new \RuntimeException('duo: origin export lock path is unsafe');
            }
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('duo: origin export lock could not be acquired');
            }
            $named = self::freshLstat($path);
            if (!is_array($named)
                || (string) ($opened['dev'] ?? '') !== (string) ($named['dev'] ?? '')
                || (string) ($opened['ino'] ?? '') !== (string) ($named['ino'] ?? '')
                || !self::privateMode($named, 0600) || !self::ownedByProcess($named)) {
                throw new \RuntimeException('duo: origin export lock pathname changed while held');
            }
            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Return one verified immutable manifest, or null when no sealed session
     * exists. A malformed/partial canonical path is retained and refused; it
     * is never treated as permission to recapture over ambiguous evidence.
     *
     * @return ?array<string,mixed>
     */
    public function readSealed(string $sessionId, string $expectedCommit): ?array {
        self::assertSessionId($sessionId);
        self::assertCommit($expectedCommit);
        if (isset($this->verifiedManifests[$sessionId])) {
            $cached = $this->verifiedManifests[$sessionId];
            if (($cached['expected_production_commit'] ?? null) !== $expectedCommit) {
                throw new \RuntimeException('duo: cached origin export session binds another production commit');
            }
            return $cached;
        }
        $session = $this->sessions . '/' . $sessionId;
        $stat = self::freshLstat($session);
        if ($stat === false) {
            return null;
        }
        [$manifest, $verifiedChunks] = self::verifySealedDirectory(
            $session,
            $sessionId,
            $expectedCommit
        );
        $this->verifiedManifests[$sessionId] = $manifest;
        $this->verifiedChunks[$sessionId] = $verifiedChunks;
        return $manifest;
    }

    /**
     * Remove one cloud-committed local spool through a deterministic tombstone.
     * A crash after rename or during bounded deletion resumes from the exact
     * intent; no later export may inherit these production-content bytes.
     */
    public function discardSealed(string $sessionId, string $expectedCommit): void {
        self::assertSessionId($sessionId);
        self::assertCommit($expectedCommit);
        $this->withExclusive(function () use ($sessionId, $expectedCommit): void {
            $source = $this->sessions . '/' . $sessionId;
            $build = self::buildPath($this->sessions, $sessionId);
            $tombstone = $this->sessions . '/.deleting-' . $sessionId . '.data';
            $intent = $this->sessions . '/.deleting-' . $sessionId . '.json';
            $intentTemporary = $this->sessions . '/.deleting-' . $sessionId . '.tmp';

            // A SIGKILL bypasses publish()'s finally block. One deterministic
            // session-owned build path lets revoke/terminal cleanup remove
            // that bounded private tree without guessing random names.
            self::removeInterruptedBuild($build);
            self::discardIncompleteIntent($intentTemporary, $intent);
            $sourceStat = self::freshLstat($source);
            $tombstoneStat = self::freshLstat($tombstone);
            $intentStat = self::freshLstat($intent);
            if (is_array($sourceStat) && (is_array($tombstoneStat) || is_array($intentStat))) {
                throw new \RuntimeException('duo: origin export discard has conflicting source and tombstone state');
            }
            if ($sourceStat === false && $tombstoneStat === false && $intentStat === false) {
                unset($this->verifiedManifests[$sessionId], $this->verifiedChunks[$sessionId]);
                return;
            }

            if (is_array($sourceStat)) {
                $manifest = $this->readSealed($sessionId, $expectedCommit);
                if ($manifest === null) {
                    throw new \RuntimeException('duo: origin export discard source disappeared');
                }
                if (!@rename($source, $tombstone)) {
                    throw new \RuntimeException('duo: origin export discard could not publish its tombstone');
                }
                unset($this->verifiedManifests[$sessionId], $this->verifiedChunks[$sessionId]);
                self::syncDirectory($this->sessions);
                self::checkpoint('after-discard-rename');
                $tombstoneStat = self::freshLstat($tombstone);
            }

            if (is_array($tombstoneStat) && $intentStat === false) {
                [$manifest] = self::verifySealedDirectory($tombstone, $sessionId, $expectedCommit);
                $intentDocument = self::discardIntent($manifest, $sessionId, $expectedCommit);
                self::writeNewFile(
                    $intentTemporary,
                    Canon::encode($intentDocument),
                    'origin export discard temporary intent'
                );
                if (!@rename($intentTemporary, $intent)) {
                    throw new \RuntimeException('duo: origin export discard intent could not be published atomically');
                }
                self::syncDirectory($this->sessions);
                self::checkpoint('after-discard-intent');
                $intentStat = self::freshLstat($intent);
            }

            if (is_array($intentStat)) {
                $intentDocument = self::readCanonicalFile(
                    $intent,
                    self::MAX_MANIFEST_BYTES,
                    'origin export discard intent'
                );
                self::assertDiscardIntent($intentDocument, $sessionId, $expectedCommit);
                if (is_array(self::freshLstat($tombstone))) {
                    self::removeDiscardTombstone($tombstone, $intentDocument);
                    self::syncDirectory($this->sessions);
                }
                self::unlinkPrivateFile($intent, 'origin export discard intent');
                self::syncDirectory($this->sessions);
            }

            if (self::freshLstat($source) !== false
                || self::freshLstat($tombstone) !== false
                || self::freshLstat($intent) !== false
                || self::freshLstat($intentTemporary) !== false) {
                throw new \RuntimeException('duo: origin export discard did not reach verified absence');
            }
            unset($this->verifiedManifests[$sessionId], $this->verifiedChunks[$sessionId]);
        });
    }

    /**
     * Bind one verified local spool to the exact generation-scoped upload
     * manifest. Tenant/site/demand identities live in the signed request, not
     * this reusable content description.
     *
     * @return array<string,mixed>
     */
    public function wireManifest(string $sessionId, string $expectedCommit, int $generation): array {
        if ($generation < 1) {
            throw new \RuntimeException('duo: cloud origin wire generation must be positive');
        }
        $local = $this->readSealed($sessionId, $expectedCommit);
        if ($local === null) {
            throw new \RuntimeException('duo: cloud origin session is not sealed');
        }
        $chunks = [];
        foreach ($local['chunks'] as $chunk) {
            $chunks[] = [
                'index' => $chunk['index'],
                'offset' => $chunk['offset'],
                'sha256' => $chunk['sha256'],
                'size' => $chunk['length'],
            ];
        }
        $wire = [
            'artifact_hash' => $local['repository']['artifact_hash'],
            'chunks' => $chunks,
            'code_revision' => $local['repository']['code_revision'],
            'expected_production_commit' => $expectedCommit,
            'export_sha256' => $local['artifact']['sha256'],
            'export_size' => $local['artifact']['bytes'],
            'format' => self::WIRE_MANIFEST_FORMAT,
            'generation' => $generation,
            'repository_revision_hash' => $local['repository']['revision_hash'],
            'snapshot_hash' => $local['export']['snapshot_hash'],
        ];
        $wire['manifest_sha256'] = hash('sha256', Canon::encode($wire));
        return $wire;
    }

    /** Read one exact chunk only after the complete sealed session verified. */
    public function readChunk(
        string $sessionId,
        string $expectedCommit,
        int $index,
        string $sha256
    ): string {
        if ($index < 0 || !self::isSha256($sha256)) {
            throw new \RuntimeException('duo: cloud origin chunk identity is malformed');
        }
        $manifest = $this->readSealed($sessionId, $expectedCommit);
        if ($manifest === null) {
            throw new \RuntimeException('duo: cloud origin session is not sealed');
        }
        $descriptor = $manifest['chunks'][$index] ?? null;
        $bytes = $this->verifiedChunks[$sessionId][$index] ?? null;
        if (!is_array($descriptor)
            || ($descriptor['index'] ?? null) !== $index
            || ($descriptor['sha256'] ?? null) !== $sha256
            || !is_string($bytes)
            || strlen($bytes) !== ($descriptor['length'] ?? null)
            || !hash_equals($sha256, hash('sha256', $bytes))) {
            throw new \RuntimeException('duo: cloud origin chunk does not match the sealed session');
        }
        return $bytes;
    }

    /**
     * Seal canonical production-export bytes. The caller must hold this
     * store's exclusive lock and must already have performed both Git guards.
     *
     * @param array{format:string,snapshot_hash:string,repository:array<string,mixed>} $identity
     * @return array<string,mixed>
     */
    public function publish(
        string $sessionId,
        string $expectedCommit,
        string $canonicalExport,
        array $identity
    ): array {
        self::assertSessionId($sessionId);
        self::assertCommit($expectedCommit);
        $length = strlen($canonicalExport);
        if ($length < 1 || $length > self::MAX_ARTIFACT_BYTES) {
            throw new \RuntimeException('duo: origin export artifact exceeds the sealed spool limit');
        }
        self::assertIdentity($identity);
        self::assertCanonicalExport($canonicalExport, $identity);
        $existing = $this->readSealed($sessionId, $expectedCommit);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['artifact']['sha256'], hash('sha256', $canonicalExport))) {
                throw new \RuntimeException('duo: sealed origin export session already binds different bytes');
            }
            return $existing;
        }

        $destination = $this->sessions . '/' . $sessionId;
        $build = self::buildPath($this->sessions, $sessionId);
        $chunksDirectory = $build . '/chunks';
        $published = false;
        self::removeInterruptedBuild($build);
        if (!@mkdir($build, 0700)) {
            throw new \RuntimeException('duo: origin export build directory could not be created');
        }
        try {
            self::assertProtectedDirectory($build, 0700, 'origin export build');
            if (!@mkdir($chunksDirectory, 0700)) {
                throw new \RuntimeException('duo: origin export chunk directory could not be created');
            }
            self::assertProtectedDirectory($chunksDirectory, 0700, 'origin export build chunks');

            $chunks = [];
            for ($offset = 0, $index = 0; $offset < $length; $offset += self::CHUNK_BYTES, $index++) {
                $bytes = substr($canonicalExport, $offset, self::CHUNK_BYTES);
                $sha256 = hash('sha256', $bytes);
                $path = $chunksDirectory . '/' . self::chunkName($index, $sha256);
                self::writeNewFile($path, $bytes, 'origin export chunk');
                $chunks[] = [
                    'index' => $index,
                    'length' => strlen($bytes),
                    'offset' => $offset,
                    'sha256' => $sha256,
                ];
            }
            self::syncDirectory($chunksDirectory);

            $manifest = [
                'artifact' => [
                    'bytes' => $length,
                    'sha256' => hash('sha256', $canonicalExport),
                ],
                'chunks' => $chunks,
                'expected_production_commit' => $expectedCommit,
                'export' => [
                    'format' => $identity['format'],
                    'snapshot_hash' => $identity['snapshot_hash'],
                ],
                'format' => self::MANIFEST_FORMAT,
                'repository' => $identity['repository'],
                'session_id' => $sessionId,
            ];
            $manifest['manifest_sha256'] = hash('sha256', Canon::encode($manifest));
            $manifestPath = $build . '/manifest.json';
            // This is deliberately the final file created in the unpublished
            // tree. The following rename is the only visibility transition.
            self::writeNewFile($manifestPath, Canon::encode($manifest), 'origin export manifest');
            self::syncDirectory($build);
            self::checkpoint('after-manifest-before-publish');

            if (file_exists($destination) || is_link($destination) || !@rename($build, $destination)) {
                throw new \RuntimeException('duo: origin export session publication collided');
            }
            $published = true;
            self::syncDirectory($this->sessions);
            self::checkpoint('after-publish');
            $sealed = $this->readSealed($sessionId, $expectedCommit);
            if ($sealed === null) {
                throw new \RuntimeException('duo: published origin export session disappeared');
            }
            return $sealed;
        } finally {
            if (!$published) {
                self::removeInterruptedBuild($build);
            }
        }
    }

    /** @return array{0:array<string,mixed>,1:list<string>} */
    private static function verifySealedDirectory(
        string $session,
        string $sessionId,
        string $expectedCommit
    ): array {
        self::assertProtectedDirectory($session, 0700, 'sealed origin-export session');
        $chunksDirectory = $session . '/chunks';
        self::assertProtectedDirectory($chunksDirectory, 0700, 'sealed origin-export chunks');
        $manifest = self::readCanonicalFile(
            $session . '/manifest.json',
            self::MAX_MANIFEST_BYTES,
            'origin export manifest'
        );
        self::assertManifest($manifest, $sessionId, $expectedCommit);
        self::assertDirectoryEntries($session, ['chunks', 'manifest.json'], 'sealed origin-export session');

        $hash = hash_init('sha256');
        $offset = 0;
        $count = count($manifest['chunks']);
        $expectedChunkNames = [];
        $verifiedChunks = [];
        foreach ($manifest['chunks'] as $position => $chunk) {
            $index = (int) $chunk['index'];
            $length = (int) $chunk['length'];
            $sha256 = (string) $chunk['sha256'];
            if ($index !== $position || (int) $chunk['offset'] !== $offset
                || $length < 1 || $length > self::CHUNK_BYTES
                || ($position < $count - 1 && $length !== self::CHUNK_BYTES)) {
                throw new \RuntimeException('duo: sealed origin export has an invalid chunk sequence');
            }
            $chunkName = self::chunkName($index, $sha256);
            $expectedChunkNames[] = $chunkName;
            $bytes = self::readExactFile(
                $chunksDirectory . '/' . $chunkName,
                $length,
                'origin export chunk'
            );
            if (!hash_equals($sha256, hash('sha256', $bytes))) {
                throw new \RuntimeException('duo: sealed origin export chunk digest does not verify');
            }
            hash_update($hash, $bytes);
            $verifiedChunks[] = $bytes;
            $offset += $length;
        }
        self::assertDirectoryEntries($chunksDirectory, $expectedChunkNames, 'sealed origin-export chunks');
        if ($offset !== $manifest['artifact']['bytes']
            || !hash_equals((string) $manifest['artifact']['sha256'], hash_final($hash))) {
            throw new \RuntimeException('duo: sealed origin export artifact digest does not verify');
        }
        return [$manifest, $verifiedChunks];
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private static function discardIntent(
        array $manifest,
        string $sessionId,
        string $expectedCommit
    ): array {
        $chunks = [];
        foreach ($manifest['chunks'] as $chunk) {
            $chunks[] = self::chunkName((int) $chunk['index'], (string) $chunk['sha256']);
        }
        return [
            'chunks' => $chunks,
            'expected_production_commit' => $expectedCommit,
            'format' => self::DISCARD_FORMAT,
            'manifest_sha256' => $manifest['manifest_sha256'],
            'session_id' => $sessionId,
        ];
    }

    /** @param array<string,mixed> $intent */
    private static function assertDiscardIntent(
        array $intent,
        string $sessionId,
        string $expectedCommit
    ): void {
        self::assertExactKeys($intent, [
            'chunks', 'expected_production_commit', 'format', 'manifest_sha256', 'session_id',
        ], 'origin export discard intent');
        if (($intent['format'] ?? null) !== self::DISCARD_FORMAT
            || ($intent['session_id'] ?? null) !== $sessionId
            || ($intent['expected_production_commit'] ?? null) !== $expectedCommit
            || !self::isSha256($intent['manifest_sha256'] ?? null)
            || !is_array($intent['chunks'] ?? null) || !array_is_list($intent['chunks'])
            || $intent['chunks'] === [] || count($intent['chunks']) > 64) {
            throw new \RuntimeException('duo: origin export discard intent is malformed');
        }
        $seen = [];
        foreach ($intent['chunks'] as $position => $name) {
            if (!is_string($name)
                || preg_match('/\A[0-9]{6}-[a-f0-9]{64}\.chunk\z/D', $name) !== 1
                || (int) substr($name, 0, 6) !== $position
                || isset($seen[$name])) {
                throw new \RuntimeException('duo: origin export discard intent chunk list is malformed');
            }
            $seen[$name] = true;
        }
    }

    private static function discardIncompleteIntent(string $temporary, string $intent): void {
        $stat = self::freshLstat($temporary);
        if ($stat === false) {
            return;
        }
        // Data deletion starts only after the atomically published intent, so
        // an interrupted temporary write is safe to discard and reconstruct.
        self::unlinkPrivateFile($temporary, 'origin export discard temporary intent');
        self::syncDirectory(dirname($temporary));
    }

    /** @param array<string,mixed> $intent */
    private static function removeDiscardTombstone(string $tombstone, array $intent): void {
        self::assertProtectedDirectory($tombstone, 0700, 'origin export discard tombstone');
        self::assertDirectoryEntriesSubset(
            $tombstone,
            ['chunks', 'manifest.json'],
            'origin export discard tombstone'
        );
        $chunksDirectory = $tombstone . '/chunks';
        $chunksStat = self::freshLstat($chunksDirectory);
        if (is_array($chunksStat)) {
            self::assertProtectedDirectory($chunksDirectory, 0700, 'origin export discard chunks');
            self::assertDirectoryEntriesSubset(
                $chunksDirectory,
                $intent['chunks'],
                'origin export discard chunks'
            );
            foreach ($intent['chunks'] as $name) {
                $path = $chunksDirectory . '/' . $name;
                if (self::freshLstat($path) !== false) {
                    self::unlinkPrivateFile($path, 'origin export discard chunk');
                }
            }
            self::assertDirectoryEntries($chunksDirectory, [], 'origin export discard chunks');
            if (!@rmdir($chunksDirectory)) {
                throw new \RuntimeException('duo: origin export discard chunk directory could not be removed');
            }
        } elseif ($chunksStat !== false) {
            throw new \RuntimeException('duo: origin export discard chunk path is unsafe');
        }

        $manifestPath = $tombstone . '/manifest.json';
        if (self::freshLstat($manifestPath) !== false) {
            $manifest = self::readCanonicalFile(
                $manifestPath,
                self::MAX_MANIFEST_BYTES,
                'origin export discard manifest'
            );
            self::assertManifest(
                $manifest,
                (string) $intent['session_id'],
                (string) $intent['expected_production_commit']
            );
            if (!hash_equals((string) $intent['manifest_sha256'], (string) $manifest['manifest_sha256'])) {
                throw new \RuntimeException('duo: origin export discard manifest changed after intent');
            }
            self::unlinkPrivateFile($manifestPath, 'origin export discard manifest');
        }
        self::assertDirectoryEntries($tombstone, [], 'origin export discard tombstone');
        if (!@rmdir($tombstone)) {
            throw new \RuntimeException('duo: origin export discard tombstone could not be removed');
        }
    }

    private static function unlinkPrivateFile(string $path, string $label): void {
        $stat = self::freshLstat($path);
        if (!is_array($stat) || self::kind($stat) !== 0100000 || is_link($path)
            || !self::privateMode($stat, 0600) || !self::ownedByProcess($stat)
            || (int) ($stat['nlink'] ?? 1) !== 1 || !@unlink($path)) {
            throw new \RuntimeException("duo: $label could not be removed safely");
        }
    }

    /** @param array<string,mixed> $manifest */
    private static function assertManifest(array $manifest, string $sessionId, string $expectedCommit): void {
        self::assertExactKeys($manifest, [
            'artifact', 'chunks', 'expected_production_commit', 'export', 'format',
            'manifest_sha256', 'repository', 'session_id',
        ], 'origin export manifest');
        if ($manifest['format'] !== self::MANIFEST_FORMAT
            || $manifest['session_id'] !== $sessionId
            || $manifest['expected_production_commit'] !== $expectedCommit
            || !is_array($manifest['artifact'] ?? null)
            || !is_array($manifest['export'] ?? null)
            || !is_array($manifest['repository'] ?? null)
            || !is_array($manifest['chunks'] ?? null) || !array_is_list($manifest['chunks'])) {
            throw new \RuntimeException('duo: sealed origin export manifest is malformed');
        }
        self::assertExactKeys($manifest['artifact'], ['bytes', 'sha256'], 'origin export artifact');
        self::assertExactKeys($manifest['export'], ['format', 'snapshot_hash'], 'origin export identity');
        if (!is_int($manifest['artifact']['bytes']) || $manifest['artifact']['bytes'] < 1
            || $manifest['artifact']['bytes'] > self::MAX_ARTIFACT_BYTES
            || !self::isSha256($manifest['artifact']['sha256'])
            || $manifest['export']['format'] !== self::EXPORT_FORMAT
            || !self::isSha256($manifest['export']['snapshot_hash'])
            || $manifest['chunks'] === []) {
            throw new \RuntimeException('duo: sealed origin export artifact identity is malformed');
        }
        self::assertRepositoryIdentity($manifest['repository']);
        foreach ($manifest['chunks'] as $chunk) {
            if (!is_array($chunk) || array_is_list($chunk)) {
                throw new \RuntimeException('duo: sealed origin export chunk record is malformed');
            }
            self::assertExactKeys($chunk, ['index', 'length', 'offset', 'sha256'], 'origin export chunk');
            if (!is_int($chunk['index']) || !is_int($chunk['length']) || !is_int($chunk['offset'])
                || !self::isSha256($chunk['sha256'])) {
                throw new \RuntimeException('duo: sealed origin export chunk record is malformed');
            }
        }
        $claimed = $manifest['manifest_sha256'] ?? null;
        $basis = $manifest;
        unset($basis['manifest_sha256']);
        if (!self::isSha256($claimed)
            || !hash_equals((string) $claimed, hash('sha256', Canon::encode($basis)))) {
            throw new \RuntimeException('duo: sealed origin export manifest hash does not verify');
        }
    }

    /** @param array<string,mixed> $identity */
    private static function assertIdentity(array $identity): void {
        self::assertExactKeys($identity, ['format', 'repository', 'snapshot_hash'], 'origin export identity');
        if ($identity['format'] !== self::EXPORT_FORMAT
            || !self::isSha256($identity['snapshot_hash'])
            || !is_array($identity['repository']) || array_is_list($identity['repository'])) {
            throw new \RuntimeException('duo: origin export identity is malformed');
        }
        self::assertRepositoryIdentity($identity['repository']);
    }

    /** @param array{format:string,snapshot_hash:string,repository:array<string,mixed>} $identity */
    private static function assertCanonicalExport(string $bytes, array $identity): void {
        $export = Canon::decode($bytes);
        if (!is_array($export) || array_is_list($export) || Canon::encode($export) !== $bytes
            || ($export['format'] ?? null) !== $identity['format']
            || ($export['snapshot_hash'] ?? null) !== $identity['snapshot_hash']
            || !is_array($export['repository'] ?? null)
            || Canon::encode($export['repository']) !== Canon::encode($identity['repository'])) {
            throw new \RuntimeException('duo: origin export artifact is not the claimed canonical snapshot');
        }
        $basis = $export;
        unset($basis['snapshot_hash']);
        if (!hash_equals($identity['snapshot_hash'], hash('sha256', Canon::encode($basis)))) {
            throw new \RuntimeException('duo: origin export artifact snapshot hash does not verify');
        }
    }

    /** @param array<string,mixed> $repository */
    private static function assertRepositoryIdentity(array $repository): void {
        self::assertExactKeys(
            $repository,
            ['artifact_hash', 'code_revision', 'revision_hash'],
            'origin export repository identity'
        );
        if (!self::isSha256($repository['artifact_hash'] ?? null)
            || !self::isSha256($repository['revision_hash'] ?? null)
            || ($repository['code_revision'] !== null && !self::isSha256($repository['code_revision']))) {
            throw new \RuntimeException('duo: origin export repository identity is malformed');
        }
    }

    private static function readCanonicalFile(string $path, int $limit, string $label): array {
        $stat = self::freshLstat($path);
        if (!is_array($stat) || (int) ($stat['size'] ?? -1) < 2 || (int) $stat['size'] > $limit) {
            throw new \RuntimeException("duo: $label has an invalid size");
        }
        $bytes = self::readExactFile($path, (int) $stat['size'], $label);
        $decoded = Canon::decode($bytes);
        if (!is_array($decoded) || array_is_list($decoded) || Canon::encode($decoded) !== $bytes) {
            throw new \RuntimeException("duo: $label is not canonical JSON");
        }
        return $decoded;
    }

    private static function readExactFile(string $path, int $expectedSize, string $label): string {
        $before = self::freshLstat($path);
        if (!is_array($before) || self::kind($before) !== 0100000 || is_link($path)
            || (int) ($before['size'] ?? -1) !== $expectedSize
            || !self::privateMode($before, 0600) || !self::ownedByProcess($before)) {
            throw new \RuntimeException("duo: $label path is unsafe");
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException("duo: $label could not be opened");
        }
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || self::kind($opened) !== 0100000
                || (string) ($opened['dev'] ?? '') !== (string) ($before['dev'] ?? '')
                || (string) ($opened['ino'] ?? '') !== (string) ($before['ino'] ?? '')
                || (int) ($opened['size'] ?? -1) !== $expectedSize
                || !self::ownedByProcess($opened)) {
                throw new \RuntimeException("duo: $label changed while opening");
            }
            $bytes = stream_get_contents($handle, $expectedSize + 1);
            if (!is_string($bytes) || strlen($bytes) !== $expectedSize) {
                throw new \RuntimeException("duo: $label could not be read exactly");
            }
        } finally {
            fclose($handle);
        }
        $after = self::freshLstat($path);
        if (!is_array($after)
            || (string) ($after['dev'] ?? '') !== (string) ($before['dev'] ?? '')
            || (string) ($after['ino'] ?? '') !== (string) ($before['ino'] ?? '')
            || (int) ($after['size'] ?? -1) !== $expectedSize
            || !self::privateMode($after, 0600) || !self::ownedByProcess($after)) {
            throw new \RuntimeException("duo: $label changed while reading");
        }
        return $bytes;
    }

    private static function writeNewFile(string $path, string $bytes, string $label): void {
        self::assertRegularOrAbsent($path, $label);
        // The mode must already be private if the process dies between open()
        // and chmod(); relying on the caller's ambient umask would expose a
        // partial production-content file and make strict recovery refuse it.
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($path, 'x+b');
        } finally {
            umask($previousUmask);
        }
        if (!is_resource($handle)) {
            throw new \RuntimeException("duo: $label could not be created");
        }
        try {
            if (!@chmod($path, 0600)) {
                throw new \RuntimeException("duo: $label permissions could not be restricted");
            }
            $offset = 0;
            $length = strlen($bytes);
            while ($offset < $length) {
                $written = fwrite($handle, substr($bytes, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new \RuntimeException("duo: $label could not be written completely");
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !@fsync($handle))) {
                throw new \RuntimeException("duo: $label could not be flushed");
            }
        } finally {
            fclose($handle);
        }
        $stat = self::freshLstat($path);
        if (!is_array($stat) || self::kind($stat) !== 0100000 || is_link($path)
            || (int) ($stat['size'] ?? -1) !== strlen($bytes)
            || !self::privateMode($stat, 0600) || !self::ownedByProcess($stat)) {
            throw new \RuntimeException("duo: published $label path is unsafe");
        }
        $digest = @hash_file('sha256', $path);
        if (!is_string($digest) || !hash_equals(hash('sha256', $bytes), $digest)) {
            throw new \RuntimeException("duo: published $label bytes do not verify");
        }
    }

    private static function removeInterruptedBuild(string $build): void {
        $stat = self::freshLstat($build);
        if ($stat === false) {
            return;
        }
        self::assertProtectedDirectory($build, 0700, 'interrupted origin export build');
        self::assertDirectoryEntriesSubset(
            $build,
            ['chunks', 'manifest.json'],
            'interrupted origin export build'
        );

        $chunksDirectory = $build . '/chunks';
        $chunksStat = self::freshLstat($chunksDirectory);
        if (is_array($chunksStat)) {
            self::assertProtectedDirectory(
                $chunksDirectory,
                0700,
                'interrupted origin export build chunks'
            );
            $entries = @scandir($chunksDirectory);
            if (!is_array($entries)) {
                throw new \RuntimeException(
                    'duo: interrupted origin export build chunks could not be enumerated'
                );
            }
            $entries = array_values(array_diff($entries, ['.', '..']));
            sort($entries, SORT_STRING);
            if (count($entries) > 64) {
                throw new \RuntimeException('duo: interrupted origin export build has too many chunks');
            }
            foreach ($entries as $name) {
                if (preg_match('/\A[0-9]{6}-[a-f0-9]{64}\.chunk\z/D', $name) !== 1) {
                    throw new \RuntimeException(
                        'duo: interrupted origin export build has an unexpected chunk'
                    );
                }
                $path = $chunksDirectory . '/' . $name;
                $chunkStat = self::freshLstat($path);
                if (!is_array($chunkStat) || (int) ($chunkStat['size'] ?? -1) < 0
                    || (int) $chunkStat['size'] > self::CHUNK_BYTES) {
                    throw new \RuntimeException(
                        'duo: interrupted origin export build chunk has an invalid size'
                    );
                }
                self::unlinkPrivateFile($path, 'interrupted origin export build chunk');
            }
            self::assertDirectoryEntries(
                $chunksDirectory,
                [],
                'interrupted origin export build chunks'
            );
            if (!@rmdir($chunksDirectory)) {
                throw new \RuntimeException(
                    'duo: interrupted origin export build chunk directory could not be removed'
                );
            }
        } elseif ($chunksStat !== false) {
            throw new \RuntimeException('duo: interrupted origin export build chunk path is unsafe');
        }

        $manifestPath = $build . '/manifest.json';
        $manifestStat = self::freshLstat($manifestPath);
        if (is_array($manifestStat)) {
            if ((int) ($manifestStat['size'] ?? -1) < 0
                || (int) $manifestStat['size'] > self::MAX_MANIFEST_BYTES) {
                throw new \RuntimeException(
                    'duo: interrupted origin export build manifest has an invalid size'
                );
            }
            self::unlinkPrivateFile($manifestPath, 'interrupted origin export build manifest');
        } elseif ($manifestStat !== false) {
            throw new \RuntimeException('duo: interrupted origin export build manifest path is unsafe');
        }

        self::assertDirectoryEntries($build, [], 'interrupted origin export build');
        if (!@rmdir($build)) {
            throw new \RuntimeException('duo: interrupted origin export build could not be removed');
        }
        self::syncDirectory(dirname($build));
    }

    private static function ensurePrivateDirectory(string $path, string $label): void {
        $stat = self::freshLstat($path);
        if ($stat === false) {
            if (!@mkdir($path, 0700)) {
                throw new \RuntimeException("duo: $label directory could not be created");
            }
        } elseif (self::kind($stat) !== 0040000 || is_link($path)) {
            // Never chmod an existing pathname before proving it is not a
            // symlink: chmod follows links and would mutate an attacker-chosen
            // target before the later lstat refusal had a chance to run.
            throw new \RuntimeException("duo: $label directory is unsafe");
        }
        self::assertProtectedDirectory($path, 0700, $label);
    }

    private static function assertProtectedDirectory(string $path, ?int $mode, string $label): void {
        $stat = self::freshLstat($path);
        if (!is_array($stat) || self::kind($stat) !== 0040000 || is_link($path)
            || ($mode !== null && !self::privateMode($stat, $mode))
            || (DIRECTORY_SEPARATOR === '/' && (((int) ($stat['mode'] ?? 0)) & 0022) !== 0)
            || !self::ownedByProcess($stat)) {
            throw new \RuntimeException("duo: $label directory is not protected for origin export");
        }
    }

    /** @param list<string> $expected */
    private static function assertDirectoryEntries(string $path, array $expected, string $label): void {
        $entries = @scandir($path);
        if (!is_array($entries)) {
            throw new \RuntimeException("duo: $label directory could not be enumerated");
        }
        $actual = array_values(array_diff($entries, ['.', '..']));
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("duo: $label directory has unexpected entries");
        }
    }

    /** @param list<string> $allowed */
    private static function assertDirectoryEntriesSubset(string $path, array $allowed, string $label): void {
        $entries = @scandir($path);
        if (!is_array($entries)) {
            throw new \RuntimeException("duo: $label directory could not be enumerated");
        }
        $allowed = array_fill_keys($allowed, true);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!isset($allowed[$entry])) {
                throw new \RuntimeException("duo: $label directory has unexpected entries");
            }
        }
    }

    private static function assertRegularOrAbsent(string $path, string $label): void {
        $stat = self::freshLstat($path);
        if (is_array($stat) && self::kind($stat) !== 0100000) {
            throw new \RuntimeException("duo: $label path is unsafe");
        }
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("duo: $label has unexpected fields");
        }
    }

    private static function assertSessionId(string $sessionId): void {
        if (preg_match('/^[a-f0-9]{64}$/D', $sessionId) !== 1) {
            throw new \RuntimeException('duo: origin export session id must be lowercase SHA-256');
        }
    }

    private static function assertCommit(string $commit): void {
        if (preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $commit) !== 1) {
            throw new \RuntimeException('duo: origin export expected production commit is malformed');
        }
    }

    private static function isSha256(mixed $value): bool {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function chunkName(int $index, string $sha256): string {
        return sprintf('%06d-%s.chunk', $index, $sha256);
    }

    private static function buildPath(string $sessions, string $sessionId): string {
        return $sessions . '/.building-' . $sessionId . '.data';
    }

    /** @param array<string|int,mixed> $stat */
    private static function kind(array $stat): int {
        return ((int) ($stat['mode'] ?? 0)) & 0170000;
    }

    /** @param array<string|int,mixed> $stat */
    private static function privateMode(array $stat, int $expected): bool {
        return DIRECTORY_SEPARATOR !== '/' || (((int) ($stat['mode'] ?? 0)) & 0777) === $expected;
    }

    /** @param array<string|int,mixed> $stat */
    private static function ownedByProcess(array $stat): bool {
        return !function_exists('posix_geteuid')
            || (int) ($stat['uid'] ?? -1) === posix_geteuid();
    }

    /** @return array<string|int,mixed>|false */
    private static function freshLstat(string $path): array|false {
        clearstatcache(true, $path);
        return @lstat($path);
    }

    private static function syncDirectory(string $path): void {
        if (!function_exists('fsync')) {
            return;
        }
        $handle = @fopen($path, 'r');
        if (!is_resource($handle)) {
            throw new \RuntimeException('duo: origin export directory could not be opened for durability');
        }
        try {
            // Some supported filesystems reject fsync on directories. The
            // atomic rename remains the correctness boundary; file fsync above
            // is mandatory, while this is the strongest portable durability
            // nudge PHP can provide.
            @fsync($handle);
        } finally {
            fclose($handle);
        }
    }

    private static function checkpoint(string $phase): void {
        if (getenv('DUO_TEST_MODE') !== '1') {
            return;
        }
        if (getenv('DUO_TEST_ORIGIN_FAIL_PHASE') === $phase) {
            throw new \RuntimeException("duo: injected origin export interruption at $phase");
        }
        if (getenv('DUO_TEST_ORIGIN_KILL_PHASE') === $phase) {
            if (function_exists('posix_kill')) {
                @posix_kill(getmypid(), defined('SIGKILL') ? SIGKILL : 9);
            }
            exit(137);
        }
    }
}
