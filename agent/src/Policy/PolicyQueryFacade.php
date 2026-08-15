<?php
declare(strict_types=1);

namespace Duo;

require_once __DIR__ . '/PolicySnapshot.php';

/** Focused, immutable query surface over a validated PolicySnapshot. */
final class PolicyQueryFacade {
    public function __construct(private readonly PolicySnapshot $snapshot) {
    }

    public function snapshot(): PolicySnapshot {
        return $this->snapshot;
    }

    /** @return list<array<string,mixed>> */
    public function surface(string $key): array {
        return $this->snapshot->index('surface', $key);
    }

    /** @return list<array<string,mixed>> */
    public function entity(string $key): array {
        return $this->snapshot->index('entity', $key);
    }

    /** @return list<array<string,mixed>> */
    public function field(string $key): array {
        return $this->snapshot->index('field', $key);
    }

    /** @return list<array<string,mixed>> */
    public function action(string $key): array {
        return $this->snapshot->index('action', $key);
    }

    /** @return list<array<string,mixed>> */
    public function provider(string $key): array {
        return $this->snapshot->index('provider', $key);
    }
}
