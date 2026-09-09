<?php
declare(strict_types=1);

namespace WPrism\Interpreters;

use WPrism\Policy;

/**
 * Speculative Loading persists exactly one row, `plsr_speculation_rules`, and
 * a fresh install has no row at all until the first Settings -> Reading save
 * (measured on WP 7.1: no wp_options row after activation). The plugin closes
 * that gap twice over — settings.php registers the setting with
 * `'default' => plsr_get_setting_default()`, and plsr_sanitize_setting()
 * returns that same default for any non-array input — so an absent row and a
 * stored default row drive identical behaviour through
 * plsr_get_stored_setting_value().
 *
 * Left alone, absence would capture as nothing and apply would leave a target
 * that speculates differently from its source. This interpreter therefore
 * completes the absent row from the plugin's OWN default function rather than
 * from a copy of its values, so a future default change moves with the plugin
 * instead of silently disagreeing with a hardcoded triple here.
 */
final class SpeculationRules {
    private const OPTION = 'plsr_speculation_rules';
    private const DEFAULT_FN = 'plsr_get_setting_default';

    public function __construct(Policy $policy) {
        // Classification is fully fixed by the pinned 1.7.0 storage contract;
        // nothing about the decision depends on site policy.
    }

    /** Speculative Loading declares no post meta; the contract method is mandatory. */
    public function post_meta_rule(string $key, array $allMeta): ?array {
        return null;
    }

    /**
     * Complete an absent primary row from the plugin's registered defaults.
     *
     * `$rawOptionSnapshot` is the one checked capture-attempt view, so
     * array_key_exists() distinguishes a physically present row (whatever it
     * holds, including deliberate authored-removal intent) from true absence
     * without a second read that a concurrent write could disagree with.
     *
     * @param array<string,mixed> $rawAuthored
     * @param array<string,array<string,mixed>> $declaredSubKeys
     * @param array<string,mixed> $rawOptionSnapshot
     * @return array<string,mixed>
     */
    public function normalize_captured_option_sub_keys(
        string $name,
        array $rawAuthored,
        array $declaredSubKeys,
        array $rawOptionSnapshot,
        bool $strictReadOnly = false
    ): array {
        if ($name !== self::OPTION) {
            return $rawAuthored;
        }
        if (array_key_exists(self::OPTION, $rawOptionSnapshot)) {
            return $rawAuthored;
        }
        // The lifecycle handoff snapshot deliberately observes a target whose
        // plugin files may be installed but inactive. Requiring the plugin
        // runtime there would refuse a comparison the engine only wants for
        // pre-lifecycle option bytes, so absence stays absence in that frame.
        if ($strictReadOnly) {
            return $rawAuthored;
        }
        if (!function_exists(self::DEFAULT_FN)) {
            throw new \RuntimeException(
                'wprism: Speculative Loading option ' . self::OPTION . ' is absent and '
                . self::DEFAULT_FN . '() is unavailable, so its registered defaults cannot be '
                . 'read from the plugin itself; refusing to substitute a hardcoded triple'
            );
        }
        $defaults = (self::DEFAULT_FN)();
        if (!is_array($defaults) || $defaults === []) {
            throw new \RuntimeException(
                'wprism: Speculative Loading ' . self::DEFAULT_FN . '() did not return its documented '
                . 'default setting array'
            );
        }
        $completed = [];
        foreach ($defaults as $subKey => $value) {
            $subKey = (string) $subKey;
            if (($declaredSubKeys[$subKey]['class'] ?? null) !== 'authored') {
                throw new \RuntimeException(
                    "wprism: Speculative Loading registered a default for undeclared or non-authored "
                    . "sub-key '$subKey'; the manifest's closed sub-key set no longer matches the plugin"
                );
            }
            $completed[$subKey] = $value;
        }
        foreach ($declaredSubKeys as $subKey => $subRule) {
            if (($subRule['class'] ?? null) === 'authored'
                && !array_key_exists((string) $subKey, $completed)) {
                throw new \RuntimeException(
                    "wprism: Speculative Loading declares authored sub-key '$subKey' but its registered "
                    . 'defaults do not supply it; an absent row cannot be completed to a partial setting'
                );
            }
        }
        return $completed;
    }
}
