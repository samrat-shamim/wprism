<?php
declare(strict_types=1);

namespace Duo\Adapter;

require_once dirname(__DIR__) . '/Canon.php';

/**
 * Immutable adapter-source/provenance catalog.
 *
 * It contains only source declarations and reviewed provenance. It never
 * loads interpreter/provider PHP and never asks WordPress for target facts.
 */
final class AdapterCatalog {
    public const FORMAT = 'duo-adapter-catalog-view/v1';

    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    private function __construct(array $data) {
        $frozen = \Duo\Canon::decode(\Duo\Canon::encode($data));
        if (!is_array($frozen) || array_is_list($frozen)) {
            throw new \RuntimeException('duo: adapter catalog must be an object');
        }
        $this->data = $frozen;
    }

    public static function fromSources(\Duo\AdapterSources $sources): self {
        $rows = [];
        foreach ($sources->names() as $name) {
            $rows[$name] = [
                'name' => $name,
                'path' => $sources->path($name),
                'provenance' => $sources->provenance($name),
                'source' => $sources->source($name),
            ];
        }
        ksort($rows, SORT_STRING);
        return new self([
            'catalog_format' => \Duo\AdapterSources::FORMAT,
            // Keep the historical adapter-package authority explicit. This is
            // an internal diagnostic label, not a new product certification
            // claim or a replacement for the legacy wire fields below.
            'evidence_model' => 'legacy_adapter_package_evidence',
            'format' => self::FORMAT,
            'adapters' => $rows,
            'not_installed' => $sources->not_installed(),
            'refusals' => $sources->plugin_refusals(),
            'sources' => $sources->sources(),
            'wire_format' => $sources->wire_format(),
        ]);
    }

    /** @return array<string,mixed> */
    public function data(): array {
        return $this->data;
    }

    /** @return list<string> */
    public function names(): array {
        $names = array_keys((array) ($this->data['adapters'] ?? []));
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return array<string,mixed>|null */
    public function adapter(string $name): ?array {
        $row = $this->data['adapters'][$name] ?? null;
        return is_array($row) ? $row : null;
    }

    public function source(string $name): ?string {
        $source = $this->adapter($name)['source'] ?? null;
        return is_string($source) ? $source : null;
    }

    /** @return array<string,mixed>|null */
    public function provenance(string $name): ?array {
        $value = $this->adapter($name)['provenance'] ?? null;
        return is_array($value) ? $value : null;
    }

    /** @return list<array<string,mixed>> */
    public function sources(): array {
        return is_array($this->data['sources'] ?? null) ? $this->data['sources'] : [];
    }

    /** @return list<array<string,mixed>> */
    public function refusals(): array {
        return is_array($this->data['refusals'] ?? null) ? $this->data['refusals'] : [];
    }

    /** @return list<array<string,mixed>> */
    public function notInstalled(): array {
        return is_array($this->data['not_installed'] ?? null) ? $this->data['not_installed'] : [];
    }

    public function hash(): string {
        return hash('sha256', \Duo\Canon::encode($this->data));
    }

    public function evidenceModel(): string {
        return (string) ($this->data['evidence_model'] ?? '');
    }
}
