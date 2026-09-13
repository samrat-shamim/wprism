<?php
namespace WPrism;

// This collaborator is exercised directly by offline harnesses. Keep its
// reference-keyspace dependency explicit instead of relying on Policy's
// bootstrap order.
require_once __DIR__ . '/ReferenceRules.php';
require_once __DIR__ . '/BlockValueGrammar.php';

/**
 * Pure cross-source grammar for reference keyspaces and attached-meta
 * ownership.
 *
 * ReferenceShapeGrammar validates the local shape of one reference-valued
 * declaration. This pass runs after all manifests are known: it closes the
 * allowed keyspace vocabulary against authored tables and rejects the flat
 * wire ambiguity where two EAV sidecars attach to one row table. It accepts
 * explicit arrays rather than a Policy object so the grammar has no runtime
 * or orchestration dependency.
 */
final class ReferenceKeyspaceGrammar {
    /**
     * Resolve keyspace names only after every pinned manifest is loaded, so
     * one adapter may safely refer to an authored table declared by another
     * without making pin order semantic. Also reject the pre-existing flat
     * wire ambiguity where two EAV sidecars attach to one row table.
     *
     * @param array<string,mixed> $sitePolicy site.wprism.json's policy object
     * @param list<array<string,mixed>> $manifests pinned manifest sources
     * @param array<string,array<string,mixed>> $declaredTables merged table declarations
     */
    public static function validate_reference_keyspaces_and_sidecars(
        array $sitePolicy,
        array $manifests,
        array $declaredTables
    ): void {
        $allowed = ['post', 'term', 'tt'];
        foreach ($declaredTables as $declaration) {
            if (($declaration['class'] ?? '') === 'authored_snapshot') {
                $kind = (string) ($declaration['id_kind'] ?? '');
                if ($kind !== '') {
                    $allowed[] = $kind;
                }
            }
        }
        $allowed = array_values(array_unique($allowed));

        $checkSource = static function (array $source, string $label) use ($allowed): void {
            foreach (BlockValueGrammar::attribute_maps($source) as $block => $attributes) {
                foreach ($attributes as $attribute => $rule) {
                    self::assert_reference_rule_keyspaces($rule, $allowed, "$label.block_values.$block.$attribute");
                }
            }
            foreach (($source['column_codecs'] ?? []) as $table => $columns) {
                foreach ($columns as $column => $codec) {
                    if (isset($codec['value'])) {
                        self::assert_reference_rule_keyspaces($codec['value'], $allowed, "$label.column_codecs.$table.$column.value");
                    }
                }
            }
            foreach (['options', 'post_meta', 'term_meta', 'user_meta'] as $section) {
                foreach (($source[$section] ?? []) as $name => $rule) {
                    if (is_array($rule) && !array_is_list($rule)) {
                        self::assert_reference_rule_keyspaces($rule, $allowed, "$label.$section.$name");
                    }
                }
            }
            foreach (['option_patterns', 'post_meta_patterns', 'meta_patterns', 'option_name_refs'] as $section) {
                foreach (($source[$section] ?? []) as $i => $rule) {
                    if (is_array($rule) && !array_is_list($rule)) {
                        self::assert_reference_rule_keyspaces($rule, $allowed, "$label.{$section}[$i]");
                    }
                }
            }
            foreach (($source['dynamic_options'] ?? []) as $name => $declaration) {
                foreach (($declaration['sub_keys'] ?? []) as $key => $rule) {
                    if (is_array($rule) && !array_is_list($rule)) {
                        self::assert_reference_rule_keyspaces(
                            $rule,
                            $allowed,
                            "$label.dynamic_options.$name.sub_keys.$key"
                        );
                    }
                }
            }
            foreach (($source['taxonomies'] ?? []) as $taxonomy => $declaration) {
                if (isset($declaration['description_refs'])) {
                    $normalized = ReferenceRules::description(
                        $declaration['description_refs'],
                        "$label.taxonomies.$taxonomy.description_refs"
                    );
                    ReferenceRules::assert_keyspaces(
                        $normalized,
                        $allowed,
                        "$label.taxonomies.$taxonomy.description_refs"
                    );
                }
            }
            foreach (($source['tables'] ?? []) as $table => $declaration) {
                if (($declaration['class'] ?? '') !== 'authored_snapshot_meta') {
                    continue;
                }
                foreach (($declaration['keys'] ?? []) as $key => $rule) {
                    if (is_array($rule) && !array_is_list($rule)) {
                        self::assert_reference_rule_keyspaces(
                            $rule,
                            $allowed,
                            "$label.tables.$table.keys.$key"
                        );
                    }
                }
            }
        };

        $checkSource($sitePolicy, 'site.wprism.json');
        foreach ($manifests as $manifest) {
            $checkSource($manifest, "manifest '" . ($manifest['name'] ?? '?') . "'");
        }

        $owners = [];
        foreach ($declaredTables as $table => $declaration) {
            if (($declaration['class'] ?? '') !== 'authored_snapshot_meta') {
                continue;
            }
            $attached = $declaration['attached_to'] ?? null;
            if (!is_array($attached) || array_is_list($attached)
                || !is_string($attached['table'] ?? null) || $attached['table'] === ''
                || !is_string($attached['column'] ?? null) || $attached['column'] === '') {
                throw new \RuntimeException(
                    "wprism: attached-meta table '$table' must declare attached_to {table, column}"
                );
            }
            $owner = $attached['table'];
            if (isset($owners[$owner])) {
                throw new \RuntimeException(
                    "wprism: attached-meta tables '{$owners[$owner]}' and '$table' both attach to '$owner'; "
                    . 'the canonical row has one flat meta map, so multiple sidecars are ambiguous'
                );
            }
            $owners[$owner] = $table;
        }
    }

    /** @param string[] $allowed */
    private static function assert_reference_rule_keyspaces(array $rule, array $allowed, string $where): void {
        ReferenceRules::assert_keyspaces($rule, $allowed, $where);
        foreach (($rule['object_fields'] ?? []) as $name => $child) {
            self::assert_reference_rule_keyspaces($child, $allowed, "$where.object_fields.$name");
        }
        foreach (($rule['sub_keys'] ?? []) as $name => $subRule) {
            if (is_array($subRule) && !array_is_list($subRule)) {
                self::assert_reference_rule_keyspaces($subRule, $allowed, "$where.sub_keys.$name");
            }
        }
    }
}
