<?php
declare(strict_types=1);

namespace Duo\Provider;

require_once dirname(__DIR__) . '/Canon.php';

/**
 * Immutable, target-free provider declaration catalog.
 *
 * The catalog contains only manifest-declared provider identities and their
 * reviewed ranges. It never loads provider PHP, asks WordPress for plugin
 * state, or treats a declaration as an executable capability.
 */
final class ProviderCatalog {
    public const FORMAT = 'duo-provider-catalog-view/v1';

    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    private function __construct(array $data) {
        $frozen = \Duo\Canon::decode(\Duo\Canon::encode($data));
        if (!is_array($frozen) || array_is_list($frozen)) {
            throw new \RuntimeException('duo: provider catalog must be an object');
        }
        $this->data = $frozen;
    }

    public static function fromPolicy(\Duo\Policy $policy): self {
        $providers = $policy->provider_declarations();
        ksort($providers, SORT_STRING);
        return new self([
            'format' => self::FORMAT,
            'providers' => $providers,
        ]);
    }

    /** @return array<string,mixed> */
    public function data(): array {
        return $this->data;
    }

    /** @return list<string> */
    public function ids(): array {
        $ids = array_keys((array) ($this->data['providers'] ?? []));
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** @return array<string,mixed>|null */
    public function declaration(string $id): ?array {
        $declaration = $this->data['providers'][$id] ?? null;
        return is_array($declaration) ? $declaration : null;
    }

    public function hash(): string {
        return hash('sha256', \Duo\Canon::encode($this->data));
    }
}
