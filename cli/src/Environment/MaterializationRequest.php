<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/** Immutable public intent passed to the environment materialization stage. */
final class MaterializationRequest
{
    public function __construct(
        public readonly string $sourceEnvironment,
        public readonly string $branch,
        public readonly bool $create,
        public readonly int $ttlSeconds
    ) {
        if ($sourceEnvironment === '' || $branch === '') {
            throw new \InvalidArgumentException('materialization requires a source environment and branch');
        }
        if ($ttlSeconds < 0) {
            throw new \InvalidArgumentException('materialization TTL cannot be negative');
        }
    }

    /** @param array{source:string,branch:string,create:bool,ttl_seconds:int} $options */
    public static function fromOptions(array $options): self
    {
        return new self($options['source'], $options['branch'], $options['create'], $options['ttl_seconds']);
    }

    /** @return array{branch:string,create:bool,ttl_seconds:int} */
    public function lifecycleOptions(): array
    {
        return [
            'branch' => $this->branch,
            'create' => $this->create,
            'ttl_seconds' => $this->ttlSeconds,
        ];
    }
}
