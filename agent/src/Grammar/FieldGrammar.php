<?php
namespace WPrism;

/**
 * Pure load-time grammar for post-field and menu-field declarations.
 *
 * Policy passes the engine-owned vocabularies explicitly. The post-field
 * column map remains on Policy because runtime materializers and planning
 * consumers publish and read it; this collaborator owns only the refusal
 * grammar, not runtime classification or field application.
 */
final class FieldGrammar {
    /**
     * Refuse unsupported post-field declarations before a manifest reaches
     * field_class(). Error text and paths intentionally match Policy's former
     * implementation.
     *
     * @param array<string,string> $derivableFieldColumns
     * @param list<string> $fieldClasses
     */
    public static function validate_field_classes(
        array $manifest,
        array $derivableFieldColumns,
        array $fieldClasses
    ): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ($manifest['post_types'] ?? [] as $postType => $decl) {
            foreach ($decl['fields'] ?? [] as $field => $rule) {
                if (!array_key_exists($field, $derivableFieldColumns)) {
                    throw new \RuntimeException(
                        "wprism: manifest '$name' declares post_types.$postType.fields.$field, but only "
                        . implode(', ', array_keys($derivableFieldColumns))
                        . ' may be field-classified in v2 (the evidence-backed allowlist remains deliberately '
                        . 'tight — see Policy::DERIVABLE_FIELD_COLUMNS\' docblock). The post-field vocabulary is '
                        . 'engine-owned: a new derivable field is an engine change with a spec bump (allowlist '
                        . 'entry, wp_posts column mapping, and its own evidence), never a manifest declaration'
                    );
                }
                $class = $rule['class'] ?? null;
                if (!in_array($class, $fieldClasses, true)) {
                    throw new \RuntimeException(
                        "wprism: manifest '$name' declares post_types.$postType.fields.$field.class="
                        . var_export($class, true) . ' but only ' . implode(', ', $fieldClasses)
                        . ' is supported for post fields in v2 — the class vocabulary for this surface is '
                        . 'engine-owned (field_class() defaults every undeclared field to authored, so '
                        . 'declaring authored is a no-op and any other class has no defined apply semantics)'
                    );
                }
            }
        }
    }

    /**
     * Refuse unsupported menu-field declarations before a manifest reaches
     * menu_field_class(). This is intentionally a separate method because
     * menu fields have a flat key shape and a wider class vocabulary.
     *
     * @param list<string> $menuDerivableFields
     * @param list<string> $menuFieldClasses
     */
    public static function validate_menu_field_classes(
        array $manifest,
        array $menuDerivableFields,
        array $menuFieldClasses
    ): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ($manifest['menu_fields'] ?? [] as $field => $rule) {
            if (!in_array($field, $menuDerivableFields, true)) {
                throw new \RuntimeException(
                    "wprism: manifest '$name' declares menu_fields.$field, but only "
                    . implode(', ', $menuDerivableFields) . ' may be field-classified in v2 (issue #3272 '
                    . 'scoped this deliberately tight, mirroring task #88 — see Policy::MENU_DERIVABLE_FIELDS\' docblock)'
                );
            }
            $class = $rule['class'] ?? null;
            if (!in_array($class, $menuFieldClasses, true)) {
                throw new \RuntimeException(
                    "wprism: manifest '$name' declares menu_fields.$field.class="
                    . var_export($class, true) . ' but only ' . implode(', ', $menuFieldClasses)
                    . ' is supported for menu fields in v2'
                );
            }
        }
    }
}
