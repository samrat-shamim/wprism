<?php
declare(strict_types=1);

namespace Duo\Capability;

require_once __DIR__ . '/LegacyCapabilityVerdict.php';
require_once dirname(__DIR__) . '/Canon.php';

/**
 * Compatibility projection of reviewed policy, adapter, and evidence inputs.
 * It adds no capability claims; it only makes the existing registry report
 * consumable through a narrow immutable model.
 */
final class CurrentCapabilityProjection {
    public const FORMAT = 'duo-current-capability-projection/v1';

    /** @var array<string,mixed> */
    private array $data;
    /** @var array<string,LegacyCapabilityVerdict> */
    private array $verdicts;

    /** @param array<string,mixed> $report */
    private function __construct(array $report) {
        $frozen = self::copyValue($report);
        if (!is_array($frozen) || array_is_list($frozen)) {
            throw new \RuntimeException('duo: current capability projection must be an object');
        }
        $this->data = $frozen;
        $this->verdicts = [];
        foreach ($frozen['manifests'] ?? [] as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null)) {
                continue;
            }
            $this->verdicts[$row['name']] = LegacyCapabilityVerdict::fromRow($row);
        }
        ksort($this->verdicts, SORT_STRING);
    }

    /** @param array<string,mixed> $report */
    public static function fromReport(array $report): self {
        if (!is_array($report['manifests'] ?? null) || !array_is_list($report['manifests'])) {
            throw new \RuntimeException('duo: capability report has no manifest projection');
        }
        return new self($report);
    }

    public static function fromRegistry(
        \Duo\CapabilityRegistry $registry,
        array $manifests,
        array $query = [],
        ?array $target = null,
        array $sources = [],
        array $externalContexts = []
    ): self {
        return self::fromReport($registry->report($manifests, $query, $target, $sources, $externalContexts));
    }

    /** @return array<string,mixed> */
    public function data(): array {
        $data = self::copyValue($this->data);
        if (!is_array($data)) {
            throw new \LogicException('duo: current capability projection copy is malformed');
        }
        unset($data['ready']);
        return $data;
    }

    /** Compatibility serializer only; new consumers must use verdict(). */
    public function legacyReport(): array {
        $data = self::copyValue($this->data);
        if (!is_array($data)) {
            throw new \LogicException('duo: legacy capability report copy is malformed');
        }
        return $data;
    }

    /** @return array<string,mixed>|null */
    public function claim(string $name): ?array {
        foreach ($this->data['manifests'] as $row) {
            if (is_array($row) && ($row['name'] ?? null) === $name) {
                return $row;
            }
        }
        return null;
    }

    public function verdict(string $name): ?LegacyCapabilityVerdict {
        return $this->verdicts[$name] ?? null;
    }

    /** @return array<string,LegacyCapabilityVerdict> */
    public function verdicts(): array {
        return $this->verdicts;
    }

    public function hash(): string {
        return hash('sha256', \Duo\Canon::encode($this->data()));
    }

    private static function copyValue(mixed $value): mixed {
        if ($value instanceof \stdClass) {
            $copy = new \stdClass();
            foreach (get_object_vars($value) as $key => $child) {
                $copy->{$key} = self::copyValue($child);
            }
            return $copy;
        }
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $child) {
                $copy[$key] = self::copyValue($child);
            }
            return $copy;
        }
        return $value;
    }
}
