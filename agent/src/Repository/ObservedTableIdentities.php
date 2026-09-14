<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/Uuid.php';

/** Snapshot-local derived identities carry comparison evidence, never enrollment authority. */
final class ObservedTableIdentities {
    /** @var array<string,array<int,string>> */
    private array $byLocal = [];
    /** @var array<string,array<string,int>> */
    private array $byUuid = [];
    /** @var array<string,array{uuid:string,type:string,id_kind:string,env_id:int}> */
    private array $derived = [];

    public function __construct(
        private readonly \Closure $durableUuidFor,
        private readonly \Closure $durableIdFor,
        private readonly \Closure $requireDurableMapping
    ) {}

    public function uuidFor(int $localId, string $kind): ?string {
        return ($this->durableUuidFor)($localId, $kind) ?? $this->byLocal[$kind][$localId] ?? null;
    }

    public function record(string $uuid, string $table, string $kind, int $localId): void {
        if (!Uuid::is($uuid) || $localId <= 0) {
            throw new \RuntimeException('wprism: observed table identity has invalid coordinates');
        }
        $durableUuid = ($this->durableUuidFor)($localId, $kind);
        $durableId = ($this->durableIdFor)($uuid, $kind);
        if ($durableUuid !== null || $durableId !== null) {
            ($this->requireDurableMapping)($uuid, $table, $kind, $localId, 'table identity observation');
            return;
        }
        if ((isset($this->byLocal[$kind][$localId]) && $this->byLocal[$kind][$localId] !== $uuid)
            || (isset($this->byUuid[$kind][$uuid]) && $this->byUuid[$kind][$uuid] !== $localId)
            || (isset($this->derived[$uuid]) && $this->derived[$uuid] !== [
                'uuid' => $uuid, 'type' => $table, 'id_kind' => $kind, 'env_id' => $localId,
            ])) {
            throw new \RuntimeException('wprism: contradictory derived table identities in one observation');
        }
        $this->byLocal[$kind][$localId] = $uuid;
        $this->byUuid[$kind][$uuid] = $localId;
        $this->derived[$uuid] = ['uuid' => $uuid, 'type' => $table, 'id_kind' => $kind, 'env_id' => $localId];
    }

    /** @return array<string,array{uuid:string,type:string,id_kind:string,env_id:int}> */
    public function derived(): array {
        return $this->derived;
    }
}
