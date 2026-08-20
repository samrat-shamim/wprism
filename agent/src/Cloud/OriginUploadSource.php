<?php
declare(strict_types=1);

namespace Duo;

if (!class_exists(OriginExporter::class, false)) {
    require_once __DIR__ . '/OriginExporter.php';
}
if (!class_exists(OriginStore::class, false)) {
    require_once __DIR__ . '/OriginStore.php';
}

/**
 * Immutable local boundary consumed by the outbound upload state machine.
 *
 * Tests can supply a deterministic sealed artifact without booting WordPress.
 * Production uses OriginUploadStoreSource, whose only live observation is the
 * first OriginExporter::seal() call; every later method reads sealed files.
 */
interface OriginUploadSource {
    public function seal(string $repository, string $sessionId, string $expectedCommit): void;

    /** @return array<string,mixed> */
    public function manifest(
        string $repository,
        string $sessionId,
        string $expectedCommit,
        int $demandGeneration
    ): array;

    public function readChunk(
        string $repository,
        string $sessionId,
        string $expectedCommit,
        int $index,
        string $sha256
    ): string;

    /** Remove one committed local spool after its cloud receipt is durable. */
    public function discard(string $repository, string $sessionId, string $expectedCommit): void;
}

/** OriginExporter + OriginStore adapter used by the shipped connector. */
final class OriginUploadStoreSource implements OriginUploadSource {
    private ?OriginStore $store = null;
    private ?string $repository = null;

    public function seal(string $repository, string $sessionId, string $expectedCommit): void {
        OriginExporter::seal($repository, $sessionId, $expectedCommit);
    }

    /** @return array<string,mixed> */
    public function manifest(
        string $repository,
        string $sessionId,
        string $expectedCommit,
        int $demandGeneration
    ): array {
        return $this->store($repository)->wireManifest(
            $sessionId,
            $expectedCommit,
            $demandGeneration
        );
    }

    public function readChunk(
        string $repository,
        string $sessionId,
        string $expectedCommit,
        int $index,
        string $sha256
    ): string {
        return $this->store($repository)->readChunk($sessionId, $expectedCommit, $index, $sha256);
    }

    public function discard(string $repository, string $sessionId, string $expectedCommit): void {
        $this->store($repository)->discardSealed($sessionId, $expectedCommit);
    }

    private function store(string $repository): OriginStore {
        if ($this->store === null) {
            $this->store = OriginStore::open($repository);
            $this->repository = $this->store->repository();
        }
        $resolved = realpath($repository);
        if (!is_string($resolved) || $this->repository !== rtrim($resolved, '/')) {
            throw new \RuntimeException('duo: cloud origin upload source changed repository');
        }
        return $this->store;
    }
}
