<?php
namespace Duo;

/**
 * Pure resolution of manifest-declared widget type contracts.
 *
 * Widgets are structural adapter declarations. This resolver preserves the
 * established last-pinned-manifest precedence without loading Policy, a
 * compiler, or any WordPress runtime surface.
 */
final class WidgetTypeResolver {
    /** @param list<array<string,mixed>> $manifests */
    public function __construct(private array $manifests) {}

    /** @return array<string,array<string,mixed>> widget type => effective rule */
    public function types(): array {
        $out = [];
        foreach ($this->manifests as $manifest) {
            foreach ((array) ($manifest['widgets'] ?? []) as $type => $rule) {
                $out[(string) $type] = (array) $rule;
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array{rule:?array,source:?string} */
    public function details(string $type): array {
        $rule = null;
        $source = null;
        foreach ($this->manifests as $manifest) {
            if (isset($manifest['widgets'][$type]) && is_array($manifest['widgets'][$type])) {
                $rule = $manifest['widgets'][$type];
                $source = (string) ($manifest['name'] ?? '?');
            }
        }
        return ['rule' => $rule, 'source' => $source];
    }
}
