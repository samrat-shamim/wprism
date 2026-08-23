<?php
namespace Duo;

// Circular with Policy.php's own require_once of this file: safe for the
// same reason ActionProviderGrammar.php's and CrossManifestGuards.php's
// identical circular requires are (DUO-3348 slices 6-7) -- require_once
// marks Policy.php's path included the moment Policy.php's own require
// statement for this file runs, before Policy.php's body finishes
// executing, so this resolves to a no-op rather than a re-include.
require_once __DIR__ . '/../Policy/Policy.php';

/**
 * The "named sub-key of an otherwise-atomic manifest value" declaration
 * grammar (DUO-3348 slice 8), extracted from `agent/src/Policy/Policy.php`.
 * `validate_sub_keys()` (options.<name>.sub_keys) and
 * `validate_dynamic_options()` (the top-level dynamic_options key) are two
 * independent manifest surfaces sharing one inner shape once you are past
 * each one's own top-level fields -- a non-empty object of NAME => rule,
 * every rule declaring a recognized class, and the SAME "parent may not also
 * declare a whole-value field" invariant
 * (`assert_sub_key_parent_has_no_value_fields()`, called by both) -- proven
 * by grepping every referenced symbol repo-wide before finalizing scope: the
 * shared private helper is the reason these two belong in one slice rather
 * than two, not an assumption.
 *
 * Moved verbatim. Both public entry points had zero external callers beyond
 * Policy's own `load()`/`from_snapshot()` (grep-verified across the whole
 * repo) -- matching PinResolver/ActionProviderGrammar/CrossManifestGuards'
 * precedent (DUO-3348 slices 5-7), no compatibility facade exists; the 6
 * call sites (2 methods x, respectively, 4 and 2 loader call sites --
 * validate_sub_keys() runs once for site.duo.json and once per manifest, in
 * both load()/from_snapshot()) call this class directly.
 *
 * `Policy::CLASSES` (the classification-class closed vocabulary) is used by
 * both moved methods but stays on Policy -- it is read at 11 places across
 * Policy.php, nowhere near exclusive to this cluster -- visibility widened
 * private -> public so this class can still reach it.
 * `dynamic_option_resolvers()` mirrors slice 1/6's precedent of publishing a
 * moved cluster's constant through a public accessor that
 * `Policy::closed_vocabularies()` reads, rather than restating the value a
 * second place.
 */
final class SubKeyGrammar {
    private const CLOSED_UNKNOWN_DIAGNOSTIC_LIMIT = 4;

    /**
     * Loud, load-time guard for sub_keyed_options()'s manifest input (same
     * "throw immediately, never degrade silently" posture as
     * validate_field_classes() above — a bad sub_keys declaration must fail
     * every command that loads this manifest, not surface as a confusing
     * runtime shape error deep inside Capture/Apply). Two invariants:
     *   - sub_keys, when present, is a non-empty object of NAME => rule,
     *     and every named rule declares a recognized class;
     *   - class=authored and sub_keys are mutually exclusive on the SAME
     *     option rule: class=authored already captures the WHOLE value
     *     (authored_options()), so a manifest declaring both is stating two
     *     contradictory capture strategies for the same option name;
     *   - whole-value codec/safety fields are likewise mutually exclusive
     *     with sub_keys. Every capture/apply/lint/compiler consumer delegates
     *     value semantics to the named sub-key rules once sub_keys exists, so
     *     accepting one of those fields on the parent would silently ignore a
     *     declaration rather than establish a second ownership layer.
     *
     * These are the kind of ambiguous manifest states this project's posture
     * (DESIGN.md 3.1.5, "loud-and-blocking default") requires rejecting
     * outright rather than silently picking one.
     */
    public static function validate_sub_keys(array $source, string $label): void {
        foreach ($source['options'] ?? [] as $optName => $rule) {
            $subKeys = $rule['sub_keys'] ?? null;
            if (array_key_exists('closed_sub_keys', $rule)
                && !is_bool($rule['closed_sub_keys'])) {
                throw new \RuntimeException(
                    "duo: $label options.$optName.closed_sub_keys must be a boolean"
                );
            }
            if ($subKeys === null) {
                if (array_key_exists('closed_sub_keys', $rule)) {
                    throw new \RuntimeException(
                        "duo: $label options.$optName declares closed_sub_keys without sub_keys"
                    );
                }
                continue;
            }
            if (!is_array($subKeys) || !$subKeys) {
                throw new \RuntimeException(
                    "duo: $label declares options.$optName.sub_keys but it is not a non-empty object"
                );
            }
            if (($rule['class'] ?? '') === 'authored') {
                throw new \RuntimeException(
                    "duo: $label declares options.$optName with BOTH class=authored and sub_keys — "
                    . 'these are mutually exclusive (class=authored already captures the WHOLE value; sub_keys '
                    . 'narrows independent capture to named keys of an otherwise-excluded blob). Pick one.'
                );
            }
            self::assert_sub_key_parent_has_no_value_fields(
                $rule,
                "$label options.$optName"
            );
            foreach ($subKeys as $subKey => $subRule) {
                if (!is_array($subRule) || !in_array($subRule['class'] ?? null, Policy::CLASSES, true)) {
                    throw new \RuntimeException(
                        "duo: $label declares options.$optName.sub_keys.$subKey with an invalid or "
                        . 'missing class (expected one of ' . implode('|', Policy::CLASSES) . ')'
                    );
                }
            }
        }
    }

    /**
     * A mixed option can be safely target-preserving only when its manifest
     * names the complete sibling vocabulary. Closed declarations reject an
     * unknown key regardless of its current value: an empty/false value can
     * be a newly introduced feature flag, and the engine has no authority to
     * infer that it is inert. A plugin with a real scaffold exception must
     * declare that key and classify it explicitly.
     */
    public static function assert_closed_value(
        string $name,
        array $rule,
        array $value,
        string $where
    ): void {
        if (empty($rule['closed_sub_keys'])) {
            return;
        }
        $known = (array) ($rule['sub_keys'] ?? []);
        $unknownCount = 0;
        $fingerprints = [];
        foreach (array_keys($value) as $key) {
            if (!array_key_exists((string) $key, $known)) {
                ++$unknownCount;
                if (count($fingerprints) < self::CLOSED_UNKNOWN_DIAGNOSTIC_LIMIT) {
                    $raw = (string) $key;
                    $fingerprints[] = (is_int($key) ? 'integer' : 'string')
                        . ':' . strlen($raw) . ':' . substr(hash('sha256', $raw), 0, 16);
                }
            }
        }
        if ($unknownCount === 0) {
            return;
        }
        sort($fingerprints, SORT_STRING);
        throw new \RuntimeException(
            "duo: $where option '$name' contains $unknownCount undeclared sibling key(s) "
            . '(bounded key fingerprints: ' . implode(', ', $fingerprints) . ')'
            . '; closed_sub_keys requires an explicit authored/runtime/derived/env classification for every key'
        );
    }

    private const SUB_KEY_PARENT_VALUE_FIELDS = [
        'ref',
        'json_refs',
        'key_refs',
        'json_encoded',
        'cast',
        'order_preserving',
        'plain_data',
        'allow_secret',
        'lint_ok',
    ];

    private static function assert_sub_key_parent_has_no_value_fields(array $rule, string $where): void {
        $ambiguous = array_values(array_filter(
            self::SUB_KEY_PARENT_VALUE_FIELDS,
            static fn(string $field): bool => array_key_exists($field, $rule)
        ));
        if ($ambiguous) {
            throw new \RuntimeException(
                "duo: $where declares sub_keys together with whole-value field(s) "
                . implode(', ', $ambiguous) . '; put value/reference/secret/lint behavior on each named '
                . 'sub-key rule instead'
            );
        }
    }

    /**
     * v1-supported dynamic_options resolvers (DUO-3264, fork A) — a
     * manifest's `resolver` value must appear here, mirroring
     * Policy::MENU_DERIVABLE_FIELDS/DERIVABLE_FIELD_COLUMNS' own "start v1
     * scope tight" posture. Deliberately just 'active_stylesheet':
     * the one proven case (theme_mods_<stylesheet>). A future resolver is
     * anticipated by the ruling's own wording but not invented ahead of a
     * second real, grounded need.
     */
    private const DYNAMIC_OPTION_RESOLVERS = ['active_stylesheet'];

    /** @return list<string> */
    public static function dynamic_option_resolvers(): array {
        return self::DYNAMIC_OPTION_RESOLVERS;
    }

    /**
     * Loud, load-time guard for dynamic_options' manifest input — DUO-3264's
     * own version of validate_sub_keys() immediately above, kept as its own
     * function rather than merged into it for the same "mirrored for its
     * own key shape rather than extended" reason validate_menu_field_classes()
     * documents for itself: dynamic_options is a flat, top-level manifest
     * key with a DIFFERENT declaration shape (prefix + resolver, no bare
     * class of its own), not a per-option-name sub_keys nesting. Reuses
     * sub_keys' own per-sub-key class validation rule (same Policy::CLASSES
     * set) since that inner shape genuinely is identical once you are past
     * the top-level prefix/resolver fields.
     *
     * DUO-3375: a top-level `class` on a dynamic_options declaration is a
     * DEAD field — resolve_dynamic_option()/dynamic_option_rule_for_name()
     * hardwire the resolved row's class to 'env' regardless of what the
     * manifest wrote, so an operator's ownership claim (e.g. class:authored)
     * used to LOAD and then be silently discarded with no signal. Refuse it
     * here at load (and, via the identical from_snapshot() call site, when a
     * frozen snapshot is re-validated) rather than accept-then-override: name
     * the field, say it is not consumed, and name what the engine forces.
     * This does NOT widen the schema — a declaration with no top-level class
     * (the only shape any manifest ships) is untouched and loads unchanged.
     */
    public static function validate_dynamic_options(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ($manifest['dynamic_options'] ?? [] as $key => $decl) {
            if (!is_array($decl)) {
                throw new \RuntimeException("duo: manifest '$name' declares dynamic_options.$key that is not an object");
            }
            if (array_key_exists('closed_sub_keys', $decl)
                && !is_bool($decl['closed_sub_keys'])) {
                throw new \RuntimeException(
                    "duo: manifest '$name' dynamic_options.$key.closed_sub_keys must be a boolean"
                );
            }
            if (array_key_exists('class', $decl)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares dynamic_options.$key.class="
                    . var_export($decl['class'], true) . ' but a dynamic_options declaration carries no top-level '
                    . "class; the resolver owns the resolved row's class, which the engine forces to 'env' (only the "
                    . 'named sub_keys are authored). It is never consumed and would be silently discarded; remove it'
                );
            }
            $prefix = $decl['prefix'] ?? null;
            if (!is_string($prefix) || $prefix === '') {
                throw new \RuntimeException("duo: manifest '$name' declares dynamic_options.$key with a missing or empty 'prefix'");
            }
            $resolver = $decl['resolver'] ?? null;
            if (!in_array($resolver, self::DYNAMIC_OPTION_RESOLVERS, true)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares dynamic_options.$key.resolver=" . var_export($resolver, true)
                    . ' but only ' . implode('|', self::DYNAMIC_OPTION_RESOLVERS) . ' is supported in v1'
                );
            }
            $subKeys = $decl['sub_keys'] ?? null;
            if (!is_array($subKeys) || !$subKeys) {
                throw new \RuntimeException("duo: manifest '$name' declares dynamic_options.$key.sub_keys that is missing, empty, or not an object");
            }
            self::assert_sub_key_parent_has_no_value_fields(
                $decl,
                "manifest '$name' dynamic_options.$key"
            );
            foreach ($subKeys as $subKey => $subRule) {
                if (!is_array($subRule) || !in_array($subRule['class'] ?? null, Policy::CLASSES, true)) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' declares dynamic_options.$key.sub_keys.$subKey with an invalid or "
                        . 'missing class (expected one of ' . implode('|', Policy::CLASSES) . ')'
                    );
                }
            }
        }
    }
}
