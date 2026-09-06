<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/ScalarReferenceIntersection.php';
require_once __DIR__ . '/../Kernel/NativeValueValidation.php';

/**
 * Pure selection of one exact or pattern-backed Policy classification rule.
 *
 * The resolver receives the loaded site/manifests and Policy's narrow
 * source-level autoload normalizer, rather than loading Policy or touching a
 * WordPress surface. Policy keeps its established public query facade; this
 * class owns only the core-yields-to-plugin and pattern fallback precedence.
 */
final class PolicyRuleResolver {
    /**
     * Pattern-fallback declaration arrays keyed by their classified section.
     *
     * `option_patterns` predates this map; legacy `meta_patterns` serves both
     * post and term meta. `post_meta_patterns` is the narrower fallback for a
     * family whose ownership is proved only in wp_postmeta (WordPress core's
     * `_oembed_<md5>` cache is the first shipped case). It runs before the
     * legacy shared fallback so a future post-only refinement can be stated
     * without silently classifying an identically named term-meta key.
     * Taxonomy patterns remain a different scope-discovery contract, not a
     * key-classification fallback.
     *
     * @var array<string,list<string>>
     */
    private const PATTERN_KEYS = [
        'options' => ['option_patterns'],
        'post_meta' => ['post_meta_patterns', 'meta_patterns'],
        'term_meta' => ['meta_patterns'],
    ];

    /**
     * @param array<string,mixed> $site
     * @param list<array<string,mixed>> $manifests
     * @param \Closure(array<string,mixed>, array<string,mixed>):array<string,mixed> $withOptionAutoload
     */
    public function __construct(
        private array $site,
        private array $manifests,
        private \Closure $withOptionAutoload
    ) {}

    /** @return array<string,list<string>> */
    public static function pattern_keys(): array {
        return self::PATTERN_KEYS;
    }

    /**
     * Resolve one rule together with the declaration that won. Source is
     * load-bearing authorization evidence, not display-only metadata.
     *
     * A non-core declaration of a core name always wins regardless of pin
     * order; among non-core declarations, the first pin still wins. This is
     * the deliberate core-schema → plugin-refinement layer from issue #3249, not
     * a general later-pin-wins rule. CrossManifestGuards separately rejects
     * contradictory non-core declarations, so this selection never hides a
     * competing policy decision.
     *
     * @return array{rule:?array,source:?string}
     */
    public function details(string $section, string $name): array {
        $sitePolicy = $this->site['policy'][$section][$name] ?? null;
        if ($sitePolicy !== null) {
            if (in_array($section, ['post_meta', 'term_meta', 'user_meta'], true)) {
                foreach ($this->manifests as $manifest) {
                    if (is_array($declared = $manifest[$section][$name] ?? null)) {
                        NativeValueValidation::assert_site_override($declared, $sitePolicy, "$section.$name");
                    }
                    foreach (self::PATTERN_KEYS[$section] ?? [] as $patternKey) {
                        foreach ($manifest[$patternKey] ?? [] as $pattern) {
                            if (preg_match('/' . $pattern['match'] . '/', $name)) {
                                NativeValueValidation::assert_site_override($pattern, $sitePolicy, "$section.$name");
                            }
                        }
                    }
                }
            }
            if ($section === 'options') {
                foreach ($this->manifests as $manifest) {
                    $declared = $manifest['options'][$name] ?? null;
                    if (is_array($declared)) {
                        ScalarReferenceIntersection::assert_site_override($declared, $sitePolicy, "options.$name");
                    }
                }
            }
            return [
                'rule' => $section === 'options'
                    ? ($this->withOptionAutoload)($sitePolicy, $this->site['policy'] ?? [])
                    : $sitePolicy,
                'source' => 'site.wprism.json',
            ];
        }
        $coreMatch = null;
        foreach ($this->manifests as $manifest) {
            if (!isset($manifest[$section][$name])) {
                continue;
            }
            $found = [
                'rule' => $section === 'options'
                    ? ($this->withOptionAutoload)($manifest[$section][$name], $manifest)
                    : $manifest[$section][$name],
                'source' => (string) ($manifest['name'] ?? '?'),
            ];
            if ($found['source'] === 'core') {
                $coreMatch = $found; // keep scanning: a non-core declaration still outranks core
                continue;
            }
            return $this->with_native_ownership($section, $name, $found);
        }
        if ($coreMatch !== null) {
            return $this->with_native_ownership($section, $name, $coreMatch);
        }
        $patternKeys = self::PATTERN_KEYS[$section] ?? [];
        if ($patternKeys !== []) {
            foreach ($this->manifests as $manifest) {
                foreach ($patternKeys as $patternKey) {
                    foreach ($manifest[$patternKey] ?? [] as $pattern) {
                        if (preg_match('/' . $pattern['match'] . '/', $name)) {
                            return $this->with_native_ownership($section, $name, [
                                'rule' => $section === 'options'
                                    ? ($this->withOptionAutoload)(array_diff_key($pattern, ['match' => true]), $manifest)
                                    : array_diff_key($pattern, ['match' => true]),
                                'source' => (string) ($manifest['name'] ?? '?'),
                            ]);
                        }
                    }
                }
            }
        }
        return ['rule' => null, 'source' => null];
    }

    /** A native predicate cannot be hidden by another adapter's pin order. */
    private function with_native_ownership(string $section, string $name, array $selected): array {
        if (!in_array($section, ['post_meta', 'term_meta', 'user_meta'], true)) return $selected;
        $owners = [];
        $claimants = [];
        foreach ($this->manifests as $manifest) {
            $rule = $manifest[$section][$name] ?? null;
            if ($rule === null) {
                foreach (self::PATTERN_KEYS[$section] ?? [] as $patternKey) {
                    foreach ($manifest[$patternKey] ?? [] as $pattern) {
                        if (preg_match('/' . $pattern['match'] . '/', $name)) {
                            $rule = $pattern;
                            break 2;
                        }
                    }
                }
            }
            if (is_array($rule) && array_key_exists(NativeValueValidation::FIELD, $rule)) {
                $owners[(string)($manifest['name'] ?? '?')] = true;
            }
            if (is_array($rule) && ($manifest['name'] ?? null) !== 'core') {
                $claimants[(string)($manifest['name'] ?? '?')] = true;
            }
        }
        if ($owners !== [] && (count($owners) !== 1 || count($claimants) > 1 || !isset($owners[$selected['source']]))) {
            throw new \RuntimeException("wprism: $section.$name native value validation has conflicting classification owners");
        }
        return $selected;
    }
}
