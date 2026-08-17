<?php
namespace Duo;

/**
 * Pure manifest projection for block and shortcode attribute registries.
 *
 * These are structural facts about one content syntax, not site-local policy
 * decisions. Each manifest replaces a prior declaration for the same block
 * or tag in pin order; rule lists themselves remain exact caller-owned bytes.
 */
final class ContentAttributeRuleResolver {
    /** @param list<array<string,mixed>> $manifests */
    public function __construct(private array $manifests) {}

    /** @return array<string,array> block name => declared rule list */
    public function block_attr_rules(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['block_attrs'] ?? [] as $block => $rules) {
                $out[$block] = $rules;
            }
        }
        return $out;
    }

    /** @return array<string,array> shortcode tag => declared rule list */
    public function shortcode_attr_rules(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['shortcode_attrs'] ?? [] as $tag => $rules) {
                $out[$tag] = $rules;
            }
        }
        return $out;
    }
}
