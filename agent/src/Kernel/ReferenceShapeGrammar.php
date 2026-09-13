<?php
namespace WPrism;

require_once __DIR__ . '/ScalarValueConstraint.php';

// This collaborator is also exercised directly by offline harnesses. Keep
// its declaration-shape dependency explicit instead of relying on Policy's
// bootstrap order.
require_once __DIR__ . '/ReferenceRules.php';
require_once __DIR__ . '/ScalarReferenceIntersection.php';
require_once __DIR__ . '/NativeValueValidation.php';
require_once __DIR__ . '/ReferenceCondition.php';
require_once __DIR__ . '/PhpContainerValue.php';
require_once __DIR__ . '/KeyBoundStrings.php';
require_once __DIR__ . '/EncodedText.php';
require_once __DIR__ . '/PostMetaInvalidation.php';
require_once __DIR__ . '/FieldLabelMap.php';
require_once __DIR__ . '/FieldTemplateMap.php';
require_once __DIR__ . '/InputFileBinding.php';

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
    public static function validate_reference_shapes(array $source, string $label, bool $manifestFeatures = false): void {
        // These classification rules do not use ReferenceRules::value_rule().
        // A misplaced write grant must refuse rather than load as inert data.
        foreach (['post_types', 'taxonomies'] as $section) {
            foreach ($source[$section] ?? [] as $name => $rule) {
                if (is_array($rule)) {
                    FieldLabelMap::assert_rule($rule, "$label.$section.$name");
                    if (array_key_exists(InputFileBinding::FIELD, $rule)) InputFileBinding::assert_rule($rule, "$label.$section.$name");
                    FieldTemplateMap::assert_rule($rule, "$label.$section.$name");
                    PostMetaInvalidation::assert_rule($rule, "$label.$section.$name");
                    ScalarValueConstraint::assert_rule($rule, "$label.$section.$name", false);
                }
            }
        }
        $conditionalRefs = $manifestFeatures && ($source['spec_version'] ?? 0) >= 3
            && in_array(ReferenceCondition::FEATURE, (array) ($source['engine_features'] ?? []), true);
        $containerOptions = $manifestFeatures && ($source['spec_version'] ?? 0) >= 3
            && in_array(PhpContainerValue::FEATURE, (array) ($source['engine_features'] ?? []), true);
        $boundStrings = $manifestFeatures && ($source['spec_version'] ?? 0) >= 3
            && in_array(KeyBoundStrings::FEATURE, (array) ($source['engine_features'] ?? []), true);
        $encodedText = $manifestFeatures && ($source['spec_version'] ?? 0) >= 3
            && in_array(EncodedText::FEATURE, (array) ($source['engine_features'] ?? []), true);
        $postMetaInvalidation = $manifestFeatures && ($source['spec_version'] ?? 0) >= 3
            && in_array(PostMetaInvalidation::FEATURE, (array) ($source['engine_features'] ?? []), true);
        foreach (['options', 'post_meta', 'term_meta', 'user_meta'] as $section) {
            foreach (($source[$section] ?? []) as $name => $rule) {
                if (!is_array($rule) || array_is_list($rule)) {
                    continue; // the section's existing validator owns its base shape
                }
                self::validate_reference_value_rule(
                    $rule,
                    "$label.$section.$name",
                    $section === 'options',
                    in_array($section, ['post_meta', 'term_meta'], true),
                    $manifestFeatures && $section === 'options'
                        && ($source['spec_version'] ?? 0) >= 3
                        && in_array(ScalarReferenceIntersection::FEATURE, (array) ($source['engine_features'] ?? []), true),
                    $manifestFeatures && $section !== 'options' && ($source['spec_version'] ?? 0) >= 3
                        && in_array(NativeValueValidation::FEATURE, (array)($source['engine_features'] ?? []), true),
                    $conditionalRefs,
                    $containerOptions && $section === 'options',
                    $boundStrings && $section === 'options',
                    $encodedText,
                    $postMetaInvalidation && $section === 'post_meta',
                    $manifestFeatures && $section === 'options' && ($source['spec_version'] ?? 0) >= 3
                        && in_array(ScalarValueConstraint::FEATURE, (array) ($source['engine_features'] ?? []), true)
                );
            }
        }
        foreach (['option_patterns', 'post_meta_patterns', 'meta_patterns', 'option_name_refs'] as $section) {
            foreach (($source[$section] ?? []) as $i => $rule) {
                if (is_array($rule) && !array_is_list($rule)) {
                    self::validate_reference_value_rule(
                        $rule,
                        "$label.{$section}[$i]",
                        false,
                        in_array($section, ['post_meta_patterns', 'meta_patterns'], true),
                        false,
                        $manifestFeatures && in_array($section, ['post_meta_patterns', 'meta_patterns'], true)
                            && ($source['spec_version'] ?? 0) >= 3
                            && in_array(NativeValueValidation::FEATURE, (array)($source['engine_features'] ?? []), true),
                        $conditionalRefs,
                        $containerOptions && $section === 'option_patterns',
                        $boundStrings && $section === 'option_patterns',
                        $encodedText && $section !== 'option_name_refs'
                    );
                }
            }
        }
        foreach (($source['dynamic_options'] ?? []) as $name => $declaration) {
            FieldLabelMap::assert_rule($declaration, "$label.dynamic_options.$name");
            if (array_key_exists(InputFileBinding::FIELD, $declaration)) InputFileBinding::assert_rule($declaration, "$label.dynamic_options.$name");
            FieldTemplateMap::assert_rule($declaration, "$label.dynamic_options.$name");
            ScalarValueConstraint::assert_rule($declaration, "$label.dynamic_options.$name", false);
            PostMetaInvalidation::assert_rule($declaration, "$label.dynamic_options.$name");
            PhpContainerValue::assert_rule($declaration, "$label.dynamic_options.$name", false);
            KeyBoundStrings::assert_rule($declaration, "$label.dynamic_options.$name", false);
            EncodedText::assert_rule($declaration, "$label.dynamic_options.$name", false);
            if (array_key_exists(NativeValueValidation::FIELD, $declaration)) {
                throw new \RuntimeException("wprism: $label.dynamic_options.$name cannot declare a native metadata predicate");
            }
            if (array_key_exists(ScalarReferenceIntersection::FIELD, $declaration)
                || array_key_exists(ScalarReferenceIntersection::TAXONOMY_FIELD, $declaration)) {
                throw new \RuntimeException(
                    "wprism: $label.dynamic_options.$name cannot declare a static scalar reference intersection"
                );
            }
            foreach (($declaration['sub_keys'] ?? []) as $key => $rule) {
                if (is_array($rule) && !array_is_list($rule)) {
                    self::validate_reference_value_rule(
                        $rule,
                        "$label.dynamic_options.$name.sub_keys.$key", false, false, false, false, $conditionalRefs
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
            FieldLabelMap::assert_rule($declaration, "$label.tables.$table");
            if (array_key_exists(InputFileBinding::FIELD, $declaration)) InputFileBinding::assert_rule($declaration, "$label.tables.$table");
            FieldTemplateMap::assert_rule($declaration, "$label.tables.$table");
            ScalarValueConstraint::assert_rule($declaration, "$label.tables.$table", false);
            PostMetaInvalidation::assert_rule($declaration, "$label.tables.$table");
            foreach ($declaration['columns'] ?? [] as $column => $rule) {
                if (is_array($rule)) {
                    FieldLabelMap::assert_rule($rule, "$label.tables.$table.columns.$column");
                    if (array_key_exists(InputFileBinding::FIELD, $rule)) InputFileBinding::assert_rule($rule, "$label.tables.$table.columns.$column");
                    FieldTemplateMap::assert_rule($rule, "$label.tables.$table.columns.$column");
                    PostMetaInvalidation::assert_rule($rule, "$label.tables.$table.columns.$column");
                    ScalarValueConstraint::assert_rule($rule, "$label.tables.$table.columns.$column", false);
                }
            }
            if (($declaration['class'] ?? '') !== 'authored_snapshot_meta') {
                continue;
            }
            foreach (($declaration['keys'] ?? []) as $key => $rule) {
                if (!is_array($rule) || array_is_list($rule)) {
                    throw new \RuntimeException(
                        "wprism: $label.tables.$table.keys.$key must be an attached-meta rule object"
                    );
                }
                self::validate_reference_value_rule($rule, "$label.tables.$table.keys.$key", false, false, false, false, $conditionalRefs);
            }
        }
    }

    private static function validate_reference_value_rule(
        array $rule,
        string $where,
        bool $allowSubKeys = false,
        bool $allowRepeatedRows = false,
        bool $allowIntersection = false,
        bool $allowNativeValidation = false,
        bool $conditionalRefs = false,
        bool $phpContainers = false,
        bool $boundStrings = false,
        bool $encodedText = false,
        bool $postMetaInvalidation = false,
        bool $scalarConstraints = false
    ): void {
        PhpContainerValue::assert_rule($rule, $where, $phpContainers);
        if (array_key_exists(NativeValueValidation::FIELD, $rule) && !$allowNativeValidation) {
            throw new \RuntimeException("wprism: $where native value validation belongs only to metadata in a v3 adapter declaring " . NativeValueValidation::FEATURE);
        }
        NativeValueValidation::assert_rule($rule, $where);
        if (array_key_exists(ScalarReferenceIntersection::FIELD, $rule) && !$allowIntersection) {
            throw new \RuntimeException(
                "wprism: $where." . ScalarReferenceIntersection::FIELD
                    . ' belongs only to an exact whole option in a v3 adapter declaring '
                    . ScalarReferenceIntersection::FEATURE
            );
        }
        ReferenceRules::value_rule($rule, $where, $conditionalRefs, $phpContainers, $boundStrings, encodedText: $encodedText, postMetaInvalidation: $postMetaInvalidation, scalarConstraints: $scalarConstraints && !$allowSubKeys);
        if (array_key_exists('repeated_rows', $rule) && !$allowRepeatedRows) {
            throw new \RuntimeException(
                "wprism: $where cannot declare repeated_rows; only post_meta and term_meta storage has repeated rows"
            );
        }
        if (array_key_exists('sub_keys', $rule) && !$allowSubKeys) {
            throw new \RuntimeException(
                "wprism: $where cannot declare sub_keys; the one-level sub_keys map belongs only on an exact or dynamic option declaration"
            );
        }
        foreach (($rule['sub_keys'] ?? []) as $name => $subRule) {
            if (is_array($subRule) && !array_is_list($subRule)) {
                self::validate_reference_value_rule($subRule, "$where.sub_keys.$name", false, false, false, false, $conditionalRefs, encodedText: $encodedText, scalarConstraints: $scalarConstraints);
            }
        }
    }
}
