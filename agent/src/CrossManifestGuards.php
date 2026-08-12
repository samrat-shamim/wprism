<?php
namespace Duo;

require_once __DIR__ . '/Canon.php';
require_once __DIR__ . '/ReferenceRules.php';
// Circular with Policy.php's own require_once of this file: safe for the
// same reason ActionProviderGrammar.php's identical circular require is
// (DUO-3348 slice 6) -- require_once marks Policy.php's path included the
// moment Policy.php's own require statement for this file runs, before
// Policy.php's body finishes executing, so this resolves to a no-op rather
// than a re-include.
require_once __DIR__ . '/Policy.php';

/**
 * The cross-manifest "one owner, no contradiction" guard family (part of
 * DUO-3348/DUO-3335's "ManifestValidator with grammar-specific validators"
 * target seam), extracted from `agent/src/Policy.php`. Every method here
 * takes the FULL pinned-manifest set (never one manifest alone -- that is
 * what a single manifest's own grammar validator already checked) and
 * refuses a declaration that depends on pin order to resolve: two adapters
 * naming the same taxonomy object_keyspace/description grammar/option
 * rule/post-type key/table id_kind/declared name with different, silently
 * order-dependent answers (DUO-3255/DUO-3318's shared "extension must not
 * grant one adapter authority over another adapter's state" ruling).
 *
 * Moved verbatim. All six public entry points had zero external callers
 * beyond Policy's own `load()`/`from_snapshot()` (grep-verified across the
 * whole repo) -- matching PinResolver's and ActionProviderGrammar's
 * precedent (DUO-3348 slices 5-6), no compatibility facade exists; the 12
 * call sites (6 methods x 2 loaders) call this class directly.
 * `throw_conflicting_taxonomy_object_keyspace()` is a private internal
 * helper with exactly one caller, inside this same cluster.
 *
 * `validate_ledger_kind_claims()` looks like it belongs in this family at
 * first glance (it is also a cross-manifest "no conflicting claim" guard)
 * but is NOT included here: its only caller is `Policy::validate_ref_kinds()`,
 * which computes a shared `$idKinds` set once and passes it to both that
 * method and the ref/token-kind vocabulary checks "so the three [vocabularies]
 * can never be derived from different inputs" (Policy.php's own comment) --
 * moving it alone would either duplicate that computation or leave an
 * awkward cross-file call into a single sub-helper. It stays with
 * `validate_ref_kinds()` for a future slice that moves that whole cluster
 * together.
 *
 * `Policy::taxonomy_pattern_matches()` and `Policy::with_option_autoload()`
 * looked cluster-exclusive at first too (each is used here) but both have
 * substantial call sites elsewhere in Policy.php -- `taxonomy_pattern_matches()`
 * from the live `taxonomy_object_keyspace()` runtime-resolution path,
 * `with_option_autoload()` from a dozen call sites across rule/option
 * resolution generally -- caught by grepping every referenced symbol across
 * the whole file before finalizing scope (the same discipline
 * `assert_min_max_range()` needed in slice 6). Both stayed on Policy,
 * visibility widened private -> public so this class can still reach them.
 */
final class CrossManifestGuards {
    /**
     * A relationship keyspace decides which independent id counter owns a
     * wp_term_relationships.object_id. Pin order cannot choose between two
     * different answers. Exact declarations are checked eagerly; identical
     * pattern regexes are checked eagerly too; and an exact taxonomy that
     * already matches a declared pattern is checked before any WordPress
     * read. Different, potentially-overlapping dynamic patterns are finally
     * checked by taxonomy_object_keyspace() when a concrete name is used.
     * Regex intersection is not safely decidable from arbitrary PCRE, while
     * resolving a concrete name is exact and happens before a query/mutation.
     *
     * @param list<array> $manifests
     */
    public static function validate_no_conflicting_taxonomy_object_keyspaces(array $manifests): void {
        /** @var array<string,array<string,string[]>> $exact taxonomy => keyspace => sources */
        $exact = [];
        /** @var list<array{match:string,value:string,object_type:string[],callback:?string,source:string}> $patterns */
        $patterns = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            foreach ((array) ($manifest['taxonomies'] ?? []) as $tax => $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $value = array_key_exists('object_keyspace', $rule)
                    ? (string) $rule['object_keyspace']
                    : 'post';
                $source = array_key_exists('object_keyspace', $rule)
                    ? "manifest '$name' taxonomies.$tax.object_keyspace"
                    : "manifest '$name' taxonomies.$tax (legacy post default)";
                $exact[(string) $tax][$value][] = $source;
            }
            foreach ((array) ($manifest['taxonomy_patterns'] ?? []) as $i => $pattern) {
                if (!is_array($pattern)) {
                    continue;
                }
                $objectTypes = array_values(array_unique(array_map(
                    'strval',
                    (array) ($pattern['object_type'] ?? [])
                )));
                sort($objectTypes, SORT_STRING);
                $patterns[] = [
                    'match' => (string) $pattern['match'],
                    'value' => array_key_exists('object_keyspace', $pattern)
                        ? (string) $pattern['object_keyspace']
                        : 'post',
                    'object_type' => $objectTypes,
                    'callback' => isset($pattern['update_count_callback'])
                        ? (string) $pattern['update_count_callback']
                        : null,
                    'source' => "manifest '$name' taxonomy_patterns[$i]",
                ];
            }
        }

        foreach ($exact as $tax => $claims) {
            if (count($claims) > 1) {
                self::throw_conflicting_taxonomy_object_keyspace($tax, $claims);
            }
        }

        $patternClaims = [];
        foreach ($patterns as $pattern) {
            $patternClaims[$pattern['match']][$pattern['value']][] = $pattern['source'] . '.object_keyspace';
        }

        $contractsByRegex = [];
        foreach ($patterns as $pattern) {
            $contractsByRegex[$pattern['match']][] = $pattern;
        }
        foreach ($contractsByRegex as $match => $contracts) {
            $first = $contracts[0];
            foreach (array_slice($contracts, 1) as $candidate) {
                foreach (['object_type', 'callback'] as $field) {
                    if ($candidate[$field] != $first[$field]) {
                        throw new \RuntimeException(
                            "duo: taxonomy_patterns regex '$match' has conflicting $field declarations from "
                            . "{$first['source']} and {$candidate['source']} — pin order may not choose "
                            . 'dynamic taxonomy behavior'
                        );
                    }
                }
            }
        }
        foreach ($patternClaims as $match => $claims) {
            if (count($claims) > 1) {
                $rendered = [];
                foreach ($claims as $value => $sources) {
                    $rendered[] = "$value from " . implode(', ', $sources);
                }
                throw new \RuntimeException(
                    "duo: taxonomy_patterns regex '$match' has conflicting object_keyspace declarations ("
                    . implode('; ', $rendered) . ') — matching patterns must agree'
                );
            }
        }

        foreach ($exact as $tax => $claims) {
            $value = (string) array_key_first($claims);
            foreach ($patterns as $pattern) {
                if (Policy::taxonomy_pattern_matches($pattern['match'], $tax) && $pattern['value'] !== $value) {
                    throw new \RuntimeException(
                        "duo: taxonomy '$tax' has conflicting object_keyspace declarations: "
                        . implode(', ', $claims[$value]) . " says $value, but {$pattern['source']}.object_keyspace says "
                        . "{$pattern['value']} — exact and matching pattern declarations must agree"
                    );
                }
            }
        }
    }

    /**
     * A taxonomy description has one physical carrier and therefore one
     * structural-reference grammar. Pin order may not select between two
     * adapters that describe that same carrier differently. Identical
     * declarations remain shareable, including the legacy flat-map mode;
     * normalized comparison deliberately retains legacy_flat_map because it
     * controls the byte-compatible empty-map representation.
     *
     * @param list<array> $manifests
     */
    public static function validate_no_conflicting_description_reference_rules(array $manifests): void {
        /** @var array<string,array{rule:array,source:string}> $claims */
        $claims = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            foreach ((array) ($manifest['taxonomies'] ?? []) as $taxonomy => $declaration) {
                if (!is_array($declaration) || !array_key_exists('description_refs', $declaration)) {
                    continue;
                }
                $source = "manifest '$name' taxonomies.$taxonomy.description_refs";
                $rule = ReferenceRules::description($declaration['description_refs'], $source);
                if (!isset($claims[$taxonomy])) {
                    $claims[$taxonomy] = ['rule' => $rule, 'source' => $source];
                    continue;
                }
                if ($claims[$taxonomy]['rule'] != $rule) {
                    throw new \RuntimeException(
                        "duo: taxonomy '$taxonomy' has conflicting description_refs declarations from "
                        . "{$claims[$taxonomy]['source']} and $source — pin order may not choose a "
                        . 'serialized-description reference grammar'
                    );
                }
            }
        }
    }

    /** @param array<string,string[]> $claims */
    private static function throw_conflicting_taxonomy_object_keyspace(string $tax, array $claims): never {
        $rendered = [];
        foreach ($claims as $value => $sources) {
            $rendered[] = "$value from " . implode(', ', $sources);
        }
        throw new \RuntimeException(
            "duo: taxonomy '$tax' has conflicting object_keyspace declarations ("
            . implode('; ', $rendered) . ') — pin order may not choose a relationship keyspace'
        );
    }

    /**
     * DUO-3255: two non-core manifests may share an exact option name only
     * when their effective rules are identical. Pin order is incidental and
     * must never choose between contradictory authored/env/runtime/derived
     * contracts. Core-vs-plugin declarations are deliberately exempt: the
     * DUO-3249 core-yields-to-plugin rule is a ratified reclassification
     * layer and active_reclassifications() makes it plan-visible.
     *
     * A site policy rule for the colliding name is the explicit resolution
     * path. It outranks every manifest in rule_details(), so its presence
     * makes the operator's choice unambiguous and this guard skips that
     * name. `note` is the sole non-semantic option-rule annotation; every
     * other field (including required, ref/json/key/sub-key shape, lint_ok,
     * and effective autoload storage) participates in the comparison.
     *
     * @param list<array> $manifests
     * @param array<string,array> $siteOptions
     */
    public static function validate_no_conflicting_option_rules(array $manifests, array $siteOptions): void {
        $seen = [];
        foreach ($manifests as $manifest) {
            $manifestName = (string) ($manifest['name'] ?? '?');
            if ($manifestName === 'core') {
                continue;
            }
            foreach ($manifest['options'] ?? [] as $optionName => $rule) {
                $optionName = (string) $optionName;
                if (array_key_exists($optionName, $siteOptions) || !is_array($rule)) {
                    continue;
                }
                $effective = Policy::with_option_autoload($rule, $manifest);
                unset($effective['note']);
                $fingerprint = Canon::encode($effective);
                if (!isset($seen[$optionName])) {
                    $seen[$optionName] = [
                        'manifest' => $manifestName,
                        'class' => $rule['class'] ?? null,
                        'rule' => $effective,
                        'fingerprint' => $fingerprint,
                    ];
                    continue;
                }
                $prior = $seen[$optionName];
                if ($prior['fingerprint'] === $fingerprint) {
                    continue;
                }
                throw new \RuntimeException(
                    "duo: manifests '{$prior['manifest']}' and '$manifestName' declare contradictory rules"
                    . " for options.$optionName ({$prior['manifest']} class="
                    . var_export($prior['class'], true) . ", $manifestName class="
                    . var_export($rule['class'] ?? null, true) . '); effective rules differ ('
                    . Canon::encode($prior['rule']) . ' vs ' . Canon::encode($effective) . '). '
                    . "Add an explicit site.duo.json policy.options.$optionName override to resolve this option."
                );
            }
        }
    }

    /**
     * DUO-3318: one owner per post-type behavior key, across every pinned
     * manifest — the cross-manifest guard, run once after the whole set has
     * loaded (no single manifest's own validator could ever see this), and
     * the acceptance-4 half of this issue for the post surface: extension
     * must not grant one adapter authority over another adapter's entities.
     *
     * Unlike options — where DUO-3249 established a ratified
     * core-yields-to-plugin reclassification layer, which is exactly why
     * validate_no_conflicting_option_rules() exempts core — every post-type
     * behavior lookup in this class (body_mode(), post_type_phase(),
     * field_rule_details(), regen_dependency()) is a plain
     * first-declaration-in-pin-order walk with no precedence layer to appeal
     * to. So a second manifest declaring `fields` for WooCommerce's `product`
     * either silently loses or silently wins depending on where an operator
     * happened to put it in site.duo.json's list — one adapter's declaration
     * changing another adapter's entities, decided by an ordering nobody
     * intended as a decision. No exemption for core here for the same reason:
     * there is no ratified layer for this surface to express.
     *
     * Guarded per KEY rather than per whole declaration, because precedence
     * is per key: two manifests may legitimately say different THINGS about
     * one post type (a scope disposition from one, a derived-field claim from
     * another) as long as they do not contradict each other about the same
     * one. Identical declarations of the same key are redundant, not
     * ambiguous, and pass — the same allowance
     * validate_no_conflicting_adapter_claims() makes for a repeated range.
     *
     * DUO-3255 remains the open umbrella for the general "two non-core
     * manifests, one name" question; this instantiates its answer for one
     * concrete surface rather than waiting for the general ruling.
     *
     * @param list<array> $manifests
     */
    public static function validate_no_conflicting_post_type_contracts(array $manifests): void {
        $seen = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            foreach ((array) ($manifest['post_types'] ?? []) as $postType => $decl) {
                if (!is_array($decl)) {
                    continue;
                }
                foreach ($decl as $key => $value) {
                    $fingerprint = Canon::encode([$value]);
                    $slot = "$postType\0$key";
                    if (!isset($seen[$slot])) {
                        $seen[$slot] = ['manifest' => $name, 'fingerprint' => $fingerprint];
                        continue;
                    }
                    if ($seen[$slot]['manifest'] === $name || $seen[$slot]['fingerprint'] === $fingerprint) {
                        continue;
                    }
                    throw new \RuntimeException(
                        "duo: manifests '{$seen[$slot]['manifest']}' and '$name' both declare "
                        . "post_types.$postType.$key with different values (" . $seen[$slot]['fingerprint']
                        . ' vs ' . $fingerprint . ") — a post type's behavior contract has exactly one owner, and "
                        . 'this lookup resolves by pin order, so accepting both would let one adapter silently '
                        . "change another adapter's entities. The extension path is the owning adapter's own "
                        . 'manifest, or an explicit site.duo.json decision for a site-local need — never a second '
                        . 'manifest reaching into the first'
                    );
                }
            }
        }
    }

    /**
     * One owner per NAME on the three bulk-enumerated declaration surfaces —
     * `post_types.<t>`, `tables.<t>`, `widgets.<t>` (DUO-3318 review, B1).
     *
     * The per-key post-type guard above is the sharper diagnostic and runs
     * first, but it can only see a contradiction about the SAME key. Two
     * manifests declaring DISJOINT keys of one post type — or one whole table
     * / widget type — never contradicted anything under it, and yet the
     * lookups behind those surfaces resolve by pin order in three different
     * directions: post-type behavior takes the FIRST declaration, while
     * declared_tables()/widget_types() take the LAST. Which adapter wins is
     * therefore decided by where an operator happened to put a name in
     * site.duo.json's list, on a surface where the loser's declaration
     * disappears silently and completely. That is the same class of hazard
     * validate_no_conflicting_option_rules() and
     * validate_no_conflicting_adapter_claims() already refuse, and it is
     * acceptance-4 of this issue: extension must never grant one adapter
     * authority over another adapter's state.
     *
     * Byte-identical declarations pass, exactly as the option-rule and
     * version-range guards allow a repeated identical claim: two adapters
     * saying the SAME thing is redundant, not ambiguous, and there is no
     * winner to pick. Equality is Canon-encoded, so it is the wire bytes that
     * must agree, not PHP's loose comparison.
     *
     * `core` is NOT exempt here. The DUO-3249 core-yields-to-plugin layer is
     * an option/meta RULE mechanism (rule_details()); no lookup on these three
     * surfaces implements it, so exempting core would silently reintroduce the
     * pin-order coin flip it is meant to resolve.
     *
     * site.duo.json's own policy.tables is deliberately outside this walk. A
     * site override is the operator's own authority over their own site — the
     * documented, wholesale, last-word layer declared_tables() applies after
     * every manifest — not a second adapter reaching into the first.
     *
     * @param list<array> $manifests
     */
    public static function validate_one_owner_per_declared_name(array $manifests): void {
        $seen = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            // taxonomies joined the walk on independent review: its three
            // lookups (description_refs_for_taxonomy(), object_type_from_
            // option, the taxonomy class rule) are all first-pin-wins with
            // no precedence layer to appeal to — the identical takeover
            // shape the other three surfaces refuse. Only polylang declares
            // any taxonomy today and nothing overlaps; site
            // policy.taxonomies is a plain scope-name list, not a
            // declaration map, so no site exemption arises.
            foreach (['post_types', 'tables', 'taxonomies', 'widgets'] as $surface) {
                foreach ((array) ($manifest[$surface] ?? []) as $declared => $decl) {
                    $slot = "$surface\0$declared";
                    $fingerprint = Canon::encode([$decl]);
                    if (!isset($seen[$slot])) {
                        $seen[$slot] = ['manifest' => $name, 'fingerprint' => $fingerprint];
                        continue;
                    }
                    if ($seen[$slot]['manifest'] === $name || $seen[$slot]['fingerprint'] === $fingerprint) {
                        continue;
                    }
                    throw new \RuntimeException(
                        "duo: manifests '{$seen[$slot]['manifest']}' and '$name' both declare $surface.$declared "
                        . "with different declarations — $surface.<name> has exactly ONE owner, and this lookup "
                        . 'resolves by pin order, so accepting both would let one adapter silently redefine '
                        . "another adapter's state depending on the order site.duo.json happens to list them. "
                        . 'Pin only one declaring manifest, or make the two declarations byte-identical; there is '
                        . 'no composition grammar for this surface in v1. Reclassifying an individual FIELD of '
                        . "another adapter's surface is what the menu_fields-style precedence layers exist for — "
                        . 'never a whole-declaration takeover'
                    );
                }
            }
        }
    }

    /**
     * Two declared tables may never share one `id_kind` (DUO-3318 review, N4).
     *
     * duo_map's unique key is (id_kind, local_id), so two tables sharing a
     * kind collide their rows' identities the instant both hold a row with the
     * same local id — one table's uuid silently resolving to the other
     * table's row. Snapshot::row_tables() has always refused this and keeps
     * doing so as the defensive twin (it is reached by directly-constructed
     * Policy objects that never went through load()); what it cannot do is
     * refuse OFFLINE, before any target contact, on the cross-manifest case
     * this rule mostly exists for — two independently-authored adapters
     * picking the same short abbreviation. Checked against the RESOLVED
     * declaration set (declared_tables()), so a site.duo.json override that
     * retypes a table is judged on the declaration that will actually be used.
     *
     * @param array<string,array> $declaredTables
     */
    public static function validate_unique_table_id_kinds(array $declaredTables): void {
        $seen = [];
        foreach ($declaredTables as $table => $decl) {
            // The literal, not Snapshot::CLASS_ROW: this file must stay
            // loadable with no other engine class present (see
            // ManifestGrammar::TABLE_CLASSES).
            if (!is_array($decl) || ($decl['class'] ?? '') !== 'authored_snapshot') {
                continue;
            }
            $kind = (string) ($decl['id_kind'] ?? '');
            if ($kind === '') {
                continue; // width/emptiness is Snapshot::assert_id_kind_width()'s own refusal
            }
            if (isset($seen[$kind])) {
                throw new \RuntimeException(
                    "duo: id_kind '$kind' is declared by both '{$seen[$kind]}' and '$table' — each "
                    . 'authored_snapshot table needs its own unique id_kind, because duo_map is keyed by '
                    . '(id_kind, local_id): two tables sharing one kind resolve each other\'s rows the moment '
                    . 'both hold the same local id. An id_kind is the adapter\'s own namespace to choose; pick a '
                    . 'distinct one (typically a short prefix of the owning plugin)'
                );
            }
            $seen[$kind] = (string) $table;
        }
    }
}
