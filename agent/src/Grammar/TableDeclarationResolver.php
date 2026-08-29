<?php
namespace WPrism;

/**
 * Pure projection of the effective typed-table declaration surface.
 *
 * Table grammar and cross-manifest ownership validation stay with their
 * existing validators. This class only preserves Policy's established raw
 * declaration precedence: later pinned manifests overwrite earlier values,
 * then site.wprism.json overrides them wholesale without changing a key's
 * original insertion position.
 */
final class TableDeclarationResolver {
    /**
     * @param array<string,mixed> $site
     * @param list<array<string,mixed>> $manifests
     */
    public function __construct(private array $site, private array $manifests) {}

    /** @return array<string,array<string,mixed>> */
    public function tables(): array {
        $out = [];
        foreach ($this->manifests as $manifest) {
            foreach ($manifest['tables'] ?? [] as $name => $rule) {
                $out[$name] = $rule;
            }
        }
        foreach ($this->site['policy']['tables'] ?? [] as $name => $rule) {
            $out[$name] = $rule;
        }
        return $out;
    }

    /** @return array{rule:?array,source:?string} */
    public function details(string $name): array {
        $rule = null;
        $source = null;
        foreach ($this->manifests as $manifest) {
            if (isset($manifest['tables'][$name])) {
                $rule = $manifest['tables'][$name];
                $source = (string) ($manifest['name'] ?? '?');
            }
        }
        if (isset($this->site['policy']['tables'][$name])) {
            $rule = $this->site['policy']['tables'][$name];
            $source = 'site.wprism.json';
        }
        return ['rule' => $rule, 'source' => $source];
    }

    /** @return ?array{name:string,rule:array} */
    public function attached_meta_table_for_owner(string $ownerTable): ?array {
        foreach ($this->tables() as $name => $rule) {
            if (($rule['class'] ?? '') === 'authored_snapshot_meta'
                && (string) ($rule['attached_to']['table'] ?? '') === $ownerTable) {
                return ['name' => (string) $name, 'rule' => $rule];
            }
        }
        return null;
    }
}
