<?php
declare(strict_types=1);

namespace Duo;

require_once dirname(__DIR__) . '/Canon.php';

/**
 * Immutable, target-free projection of a validated policy load.
 *
 * Policy remains the compatibility facade used by the product. New consumers
 * can depend on this value instead of reaching into Policy's historical
 * mutable arrays or reopening declaration files.
 */
final class PolicySnapshot {
    public const FORMAT = 'duo-policy-snapshot-view/v1';

    /** @var array<string,mixed> */
    private array $data;
    /** @var array<string,array<string,list<array<string,mixed>>>> */
    private array $indexes;

    /** @param array<string,mixed> $data */
    private function __construct(array $data) {
        $frozen = Canon::decode(Canon::encode($data));
        if (!is_array($frozen) || array_is_list($frozen)) {
            throw new \RuntimeException('duo: policy snapshot view must be an object');
        }
        $this->data = $frozen;
        $this->indexes = self::buildIndexes($frozen['manifests'] ?? []);
    }

    public static function fromPolicy(Policy $policy): self {
        return new self($policy->export_snapshot());
    }

    /**
     * Construct a view from already validated frozen policy fields. This is
     * useful to host/offline callers that deliberately do not instantiate the
     * live Policy facade.
     *
     * @param list<array<string,mixed>> $manifests
     */
    public static function fromArrays(
        array $site,
        array $manifests,
        array $adapterSources = [],
        ?array $dispositions = null,
        ?array $capabilities = null,
        string $format = self::FORMAT
    ): self {
        return new self([
            'adapter_sources' => $adapterSources,
            'capabilities' => $capabilities,
            'dispositions' => $dispositions,
            'format' => $format,
            'manifests' => $manifests,
            'site' => $site,
        ]);
    }

    /** @return array<string,mixed> */
    public function data(): array {
        return $this->data;
    }

    /** @return array<string,mixed> */
    public function site(): array {
        return is_array($this->data['site'] ?? null) ? $this->data['site'] : [];
    }

    /** @return list<array<string,mixed>> */
    public function manifests(): array {
        return is_array($this->data['manifests'] ?? null) ? $this->data['manifests'] : [];
    }

    /** @return array<string,mixed>|null */
    public function manifest(string $name): ?array {
        foreach ($this->manifests() as $manifest) {
            if (($manifest['name'] ?? null) === $name) {
                return $manifest;
            }
        }
        return null;
    }

    /** @return list<string> */
    public function manifestNames(): array {
        $names = [];
        foreach ($this->manifests() as $manifest) {
            if (is_string($manifest['name'] ?? null)) {
                $names[] = $manifest['name'];
            }
        }
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return array<string,mixed>|null */
    public function adapterSources(): ?array {
        return is_array($this->data['adapter_sources'] ?? null) ? $this->data['adapter_sources'] : null;
    }

    /** @return array<string,mixed>|null */
    public function dispositions(): ?array {
        return is_array($this->data['dispositions'] ?? null) ? $this->data['dispositions'] : null;
    }

    /** @return array<string,mixed>|null */
    public function capabilities(): ?array {
        return is_array($this->data['capabilities'] ?? null) ? $this->data['capabilities'] : null;
    }

    /** @return array<string,array<string,list<array<string,mixed>>>> */
    public function indexes(): array {
        return $this->indexes;
    }

    /** @return list<array<string,mixed>> */
    public function index(string $dimension, string $key): array {
        if (!isset($this->indexes[$dimension])) {
            throw new \InvalidArgumentException("duo: unknown policy snapshot index '$dimension'");
        }
        return $this->indexes[$dimension][$key] ?? [];
    }

    public function hash(): string {
        return hash('sha256', Canon::encode($this->data));
    }

    /** @param mixed $manifests @return array<string,array<string,list<array<string,mixed>>>> */
    private static function buildIndexes(mixed $manifests): array {
        $indexes = [
            'surface' => [],
            'entity' => [],
            'field' => [],
            'action' => [],
            'provider' => [],
        ];
        if (!is_array($manifests) || !array_is_list($manifests)) {
            return $indexes;
        }
        $entitySections = ['tables', 'post_types', 'taxonomies', 'widgets'];
        $fieldSections = [
            'options', 'option_namespaces', 'option_patterns', 'dynamic_options',
            'post_meta', 'term_meta', 'user_meta', 'menu_fields', 'block_attrs', 'shortcode_attrs',
        ];
        foreach ($manifests as $manifest) {
            if (!is_array($manifest) || !is_string($manifest['name'] ?? null)) {
                continue;
            }
            $manifestName = $manifest['name'];
            foreach (array_keys($manifest) as $section) {
                if (!is_string($section) || in_array($section, ['name', 'notes'], true)) {
                    continue;
                }
                $value = $manifest[$section];
                self::add($indexes['surface'], $section, [
                    'manifest' => $manifestName,
                    'section' => $section,
                ]);
                $dimension = in_array($section, $entitySections, true) ? 'entity'
                    : (in_array($section, $fieldSections, true) ? 'field' : 'surface');
                if (is_array($value) && !array_is_list($value)) {
                    foreach ($value as $name => $_declaration) {
                        if (!is_string($name)) {
                            continue;
                        }
                        $key = $section . '.' . $name;
                        $row = [
                            'manifest' => $manifestName,
                            'section' => $section,
                            'name' => $name,
                        ];
                        self::add($indexes['surface'], $key, $row);
                        self::add($indexes[$dimension], $key, $row);
                    }
                } elseif (is_array($value) && array_is_list($value)) {
                    foreach ($value as $position => $declaration) {
                        if (!is_array($declaration)) {
                            continue;
                        }
                        $name = $declaration['id'] ?? ($declaration['name'] ?? (string) $position);
                        if (!is_string($name) && !is_int($name)) {
                            $name = (string) $position;
                        }
                        $row = [
                            'manifest' => $manifestName,
                            'section' => $section,
                            'name' => (string) $name,
                            'position' => $position,
                        ];
                        self::add($indexes['surface'], $section . '.' . $name, $row);
                        self::add($indexes[$dimension], $section . '.' . $name, $row);
                    }
                }
            }
            foreach ((array) ($manifest['actions'] ?? []) as $position => $action) {
                if (!is_array($action)) {
                    continue;
                }
                $name = $action['id'] ?? ($action['name'] ?? (string) $position);
                $row = [
                    'manifest' => $manifestName,
                    'name' => (string) $name,
                    'position' => $position,
                ];
                self::add($indexes['action'], (string) $name, $row);
            }
            foreach ((array) ($manifest['providers'] ?? []) as $position => $provider) {
                if (!is_array($provider)) {
                    continue;
                }
                $name = $provider['id'] ?? ($provider['name'] ?? (string) $position);
                $row = [
                    'manifest' => $manifestName,
                    'name' => (string) $name,
                    'position' => $position,
                ];
                self::add($indexes['provider'], (string) $name, $row);
            }
        }
        foreach ($indexes as &$dimension) {
            ksort($dimension, SORT_STRING);
            foreach ($dimension as &$rows) {
                usort($rows, static fn(array $a, array $b): int => strcmp(
                    ($a['manifest'] ?? '') . "\0" . ($a['section'] ?? '') . "\0" . ($a['name'] ?? ''),
                    ($b['manifest'] ?? '') . "\0" . ($b['section'] ?? '') . "\0" . ($b['name'] ?? '')
                ));
            }
            unset($rows);
        }
        unset($dimension);
        return $indexes;
    }

    /** @param array<string,list<array<string,mixed>>> $index */
    private static function add(array &$index, string $key, array $row): void {
        $index[$key] ??= [];
        $index[$key][] = $row;
    }
}
