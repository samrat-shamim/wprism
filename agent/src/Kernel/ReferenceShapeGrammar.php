<?php
namespace Duo;

// This collaborator is also exercised directly by offline harnesses. Keep
// its declaration-shape dependency explicit instead of relying on Policy's
// bootstrap order.
require_once __DIR__ . '/ReferenceRules.php';

/**
 * Pure loader-time grammar for reference-valued manifest declarations.
 *
 * This class enumerates the declaration surfaces that carry a generic
 * reference value rule and delegates each rule's local vocabulary to
 * ReferenceRules. It deliberately does not resolve keyspaces against a
 * loaded policy: that cross-manifest operation belongs to
 * ReferenceKeyspaceGrammar, which receives declared table state explicitly.
 */
final class ReferenceShapeGrammar {
    /**
     * Validate every reference-valued declaration in one manifest or site
     * policy. The label is preserved verbatim so refusal paths remain
     * byte-for-byte identical to Policy's former implementation.
     */
    public static function validate_reference_shapes(array $source, string $label): void {
        foreach (['options', 'post_meta', 'term_meta', 'user_meta'] as $section) {
            foreach (($source[$section] ?? []) as $name => $rule) {
                if (!is_array($rule) || array_is_list($rule)) {
                    continue; // the section's existing validator owns its base shape
                }
                self::validate_reference_value_rule(
                    $rule,
                    "$label.$section.$name",
                    $section === 'options'
                );
            }
        }
        foreach (['option_patterns', 'meta_patterns', 'option_name_refs'] as $section) {
            foreach (($source[$section] ?? []) as $i => $rule) {
                if (is_array($rule) && !array_is_list($rule)) {
                    self::validate_reference_value_rule($rule, "$label.{$section}[$i]");
                }
            }
        }
        foreach (($source['dynamic_options'] ?? []) as $name => $declaration) {
            foreach (($declaration['sub_keys'] ?? []) as $key => $rule) {
                if (is_array($rule) && !array_is_list($rule)) {
                    self::validate_reference_value_rule(
                        $rule,
                        "$label.dynamic_options.$name.sub_keys.$key"
                    );
                }
            }
        }
        foreach (($source['taxonomies'] ?? []) as $taxonomy => $declaration) {
            if (isset($declaration['description_refs'])) {
                ReferenceRules::description(
                    $declaration['description_refs'],
                    "$label.taxonomies.$taxonomy.description_refs"
                );
            }
        }
        foreach (($source['tables'] ?? []) as $table => $declaration) {
            if (($declaration['class'] ?? '') !== 'authored_snapshot_meta') {
                continue;
            }
            foreach (($declaration['keys'] ?? []) as $key => $rule) {
                if (!is_array($rule) || array_is_list($rule)) {
                    throw new \RuntimeException(
                        "duo: $label.tables.$table.keys.$key must be an attached-meta rule object"
                    );
                }
                self::validate_reference_value_rule($rule, "$label.tables.$table.keys.$key");
            }
        }
    }

    private static function validate_reference_value_rule(
        array $rule,
        string $where,
        bool $allowSubKeys = false
    ): void {
        ReferenceRules::value_rule($rule, $where);
        if (array_key_exists('sub_keys', $rule) && !$allowSubKeys) {
            throw new \RuntimeException(
                "duo: $where cannot declare sub_keys; the one-level sub_keys map belongs only on an exact or dynamic option declaration"
            );
        }
        foreach (($rule['sub_keys'] ?? []) as $name => $subRule) {
            if (is_array($subRule) && !array_is_list($subRule)) {
                self::validate_reference_value_rule($subRule, "$where.sub_keys.$name", false);
            }
        }
    }
}
