<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__) . '/Registry.php';

/**
 * Typed host boundary around the existing registry parser.
 *
 * Registry keeps the byte-compatible file grammar; this value object keeps
 * callers from passing unlabelled associative arrays through the application
 * layer and provides one place for the parser to be replaced by a versioned
 * repository implementation later.
 */
final class EnvironmentRegistry
{
    /** @param array<string,array<string,mixed>> $entries */
    private function __construct(private readonly array $entries) {}

    public static function load(?string $overlayOverride, string $startDir): self
    {
        return new self(Registry::load($overlayOverride, $startDir));
    }

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        return $this->entries;
    }

    /** @return array<string,mixed> */
    public function get(string $name): array
    {
        return Registry::get($this->entries, $name);
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map('strval', array_keys($this->entries));
    }
}
