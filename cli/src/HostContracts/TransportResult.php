<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/** Validated process result used at host workflow boundaries. */
final class TransportResult {
    public function __construct(
        public readonly int $exit,
        public readonly string $stdout,
        public readonly string $stderr
    ) {}

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): self {
        if (!is_int($value['exit'] ?? null)
            || !is_string($value['stdout'] ?? null)
            || !is_string($value['stderr'] ?? null)) {
            throw new \RuntimeException('duo: transport returned a malformed result');
        }
        return new self($value['exit'], $value['stdout'], $value['stderr']);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    public function toArray(): array {
        return ['exit' => $this->exit, 'stdout' => $this->stdout, 'stderr' => $this->stderr];
    }
}
