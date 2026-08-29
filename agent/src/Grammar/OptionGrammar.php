<?php
namespace WPrism;

// The declaration grammar consults OptionState's closed storage vocabulary,
// so keep this collaborator independently loadable just like Policy's other
// pure grammar collaborators.
require_once __DIR__ . '/../Kernel/OptionState.php';

/**
 * The pure option declaration grammar extracted from Policy.php
 * (issue #3348 slice 16): the explicit environment-option requirement gate and
 * the option autoload/storage contract shared by options, option patterns,
 * option-name references, and dynamic options.
 *
 * These checks validate manifest and site-policy bytes only. Runtime option
 * lookup and `Policy::with_option_autoload()` remain on Policy because they
 * resolve effective rules for live query consumers rather than declaring the
 * storage grammar itself.
 */
final class OptionGrammar {
    /**
     * Validate every top-level `options.<name>` rule classified `env` at
     * load time (issue #3232): `required` (bool) is MANDATORY, no silent
     * default either way — same posture issue #3229 already established for
     * post_type/taxonomy scope ("every entity gets an audited decision,
     * neither noisy-by-default nor silent-by-default"), applied here to
     * env rules. A manifest declaring `class: "env"` with no `required`
     * key refuses to load, naming the exact manifest and key, so every
     * env-classified option is a deliberate author decision (worth
     * checklisting via env_options()/env_missing, or plugin-internal
     * bookkeeping that self-populates and isn't) rather than an implicit
     * one a future maintainer has to reverse-engineer from silence.
     *
     * Deliberately narrow, matching env_options()'s own scope: only
     * top-level `options.<name>.class === "env"` rules. A `sub_keys`
     * entry's OWN class (issue #3233's per-sub-key carve-out) is out of
     * v2 scope for the identical reason post_meta/term_meta env values
     * are (see env_options()'s docblock) — no shipped manifest declares
     * one today (confirmed empirically, not assumed), so this is a named
     * scope cut, not an oversight.
     */
    public static function validate_env_options(array $source, string $label): void {
        foreach ((array) ($source['options'] ?? []) as $name => $rule) {
            if (!is_array($rule) || ($rule['class'] ?? '') !== 'env') {
                continue;
            }
            if (!array_key_exists('required', $rule) || !is_bool($rule['required'])) {
                throw new \RuntimeException(
                    "wprism: $label options.$name.class=\"env\" needs an explicit boolean 'required' "
                    . '(true: an operator must provision this value on a fresh environment — a genuine '
                    . 'secret or site-identity value; false: plugin-internal bookkeeping that '
                    . 'self-populates and is not worth checklisting) — no silent default either way'
                );
            }
        }
    }

    /**
     * The one non-value autoload declaration: `preserve` authorizes replaying
     * the source row's own flag instead of naming a literal one. Closed and
     * engine-owned — every other spelling has to be a real storage value,
     * because insertion may never guess.
     */
    private const OPTION_AUTOLOAD_SENTINELS = ['preserve'];

    /**
     * A portable authored option must say how its wp_options row is stored.
     * `preserve` authorizes capture of the source row's exact autoload flag;
     * a concrete value is a stronger adapter contract and capture refuses a
     * source row that disagrees. Omitting this declaration is never allowed:
     * insertion would otherwise fall back to WordPress/version-local policy.
     *
     * The same rule is applied to option patterns, option-name references,
     * and dynamic options whose authored sub-keys materialize option rows.
     */
    public static function validate_option_storage(array $source, string $label): void {
        $default = $source['option_autoload'] ?? null;
        $check = static function (array $rule, string $where) use ($label, $default): void {
            $hasAuthoredSubKey = false;
            foreach ((array) ($rule['sub_keys'] ?? []) as $subRule) {
                if (($subRule['class'] ?? null) === 'authored') {
                    $hasAuthoredSubKey = true;
                    break;
                }
            }
            if (!in_array($rule['class'] ?? null, ['authored', 'managed'], true) && !$hasAuthoredSubKey) {
                return;
            }
            $autoload = $rule['autoload'] ?? $default;
            if (!in_array($autoload, self::OPTION_AUTOLOAD_SENTINELS, true)
                && !in_array($autoload, OptionState::AUTOLOAD_VALUES, true)) {
                throw new \RuntimeException(
                    "wprism: $label $where needs autoload=preserve or an explicit supported autoload value "
                    . '(' . implode('|', OptionState::AUTOLOAD_VALUES) . '); insertion may never guess'
                );
            }
        };
        foreach ((array) ($source['options'] ?? []) as $name => $rule) {
            if (is_array($rule)) {
                $check($rule, "options.$name");
            }
        }
        foreach ((array) ($source['option_patterns'] ?? []) as $i => $rule) {
            if (is_array($rule)) {
                $check($rule, "option_patterns[$i]");
            }
        }
        foreach ((array) ($source['option_name_refs'] ?? []) as $i => $rule) {
            if (is_array($rule)) {
                $check($rule, "option_name_refs[$i]");
            }
        }
        // issue #3264: dynamic_options entries are sub_keys-shaped (no bare
        // top-level class of their own) — $check()'s existing
        // $hasAuthoredSubKey detection already handles that correctly,
        // reused as-is rather than duplicated.
        foreach ((array) ($source['dynamic_options'] ?? []) as $key => $rule) {
            if (is_array($rule)) {
                $check($rule, "dynamic_options.$key");
            }
        }
    }

    /** @return list<string> Policy::closed_vocabularies()'s autoload sentinel vocabulary. */
    public static function optionAutoloadSentinels(): array {
        return self::OPTION_AUTOLOAD_SENTINELS;
    }
}
