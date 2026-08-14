<?php
declare(strict_types=1);

namespace Duo\Adapter;

require_once __DIR__ . '/AdapterCatalog.php';
require_once dirname(__DIR__) . '/AdapterSources.php';

/** Frozen adapter provenance snapshot with explicit encode/decode boundaries. */
final class AdapterSnapshot {
    /** @var array<string,mixed> */
    private array $data;

    private function __construct(array $data) {
        $this->data = \Duo\Canon::decode(\Duo\Canon::encode($data));
    }

    public static function fromSources(\Duo\AdapterSources $sources): self {
        return new self($sources->export());
    }

    /** @param array<string,mixed> $data */
    public static function decode(array $data): self {
        // AdapterSources is the authority for the versioned snapshot shape;
        // this value only freezes bytes until a caller supplies manifests.
        $format = $data['format'] ?? null;
        $legacy = $format === \Duo\AdapterSources::LEGACY_FORMAT;
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        $expectedKeys = $legacy ? ['format', 'out_of_tree'] : ['certificates', 'format', 'out_of_tree'];
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys
            || !in_array($format, [
            \Duo\AdapterSources::FORMAT,
            \Duo\AdapterSources::LEGACY_FORMAT,
            ], true)
            || !is_array($data['out_of_tree'] ?? null)
            || ($data['out_of_tree'] !== [] && array_is_list($data['out_of_tree']))
            || (!$legacy && (!is_array($data['certificates'] ?? null)
                || ($data['certificates'] !== [] && array_is_list($data['certificates']))))) {
            throw new \RuntimeException('duo: adapter snapshot format is unsupported');
        }
        return new self($data);
    }

    /** @return array<string,mixed> */
    public function encode(): array {
        return $this->data;
    }

    /** @param list<array<string,mixed>> $manifests */
    public function restore(array $manifests): \Duo\AdapterSources {
        return \Duo\AdapterSources::from_snapshot($this->data, $manifests);
    }

}
