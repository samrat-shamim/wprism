<?php
namespace Duo;

require_once __DIR__ . '/../Grammar/Shortcodes.php';
require_once __DIR__ . '/Pending.php';
require_once __DIR__ . '/LintFinding.php';

/**
 * Manifest-declared shortcode reference scanning behind Lint's facade.
 *
 * This owns only the policy-shaped shortcode grammar: matching declared
 * tags, inspecting named or positional declared reference positions, and the
 * existing lowercased-attribute heuristic for unregistered positions. The
 * caller owns state-tree traversal and result accumulation. A resolver can
 * be supplied for offline characterization without database contact; the
 * production default preserves Lint's shared Pending resolver contract.
 */
final class ShortcodeReferenceScanner {
    /**
     * @param array<string,list<array>> $shortcodeRules
     * @param null|callable(int):?array $resolveId
     * @return list<array{class:string,path:string,locator:string,value:mixed,matches?:array,note:string}>
     */
    public static function scan(
        string $body,
        array $shortcodeRules,
        string $rel,
        string $locatorPrefix = '',
        ?callable $resolveId = null
    ): array {
        if ($shortcodeRules === [] || !str_contains($body, '[')) {
            return [];
        }
        $resolveId ??= static fn(int $id): ?array => Pending::resolve_id($id);
        $pattern = '/' . get_shortcode_regex(array_keys($shortcodeRules)) . '/';
        $matched = preg_match_all($pattern, $body, $matches, PREG_SET_ORDER);
        if ($matched === false) {
            throw new \RuntimeException('duo: shortcode lint regex failed; refusing unproven content');
        }
        if ($matched === 0) {
            return [];
        }

        $findings = [];
        foreach ($matches as $m) {
            if ($m[1] === '[' && $m[6] === ']') {
                continue; // escaped [[tag]] — literal text, never executes, nothing to check
            }
            $tag = $m[2];
            $rules = $shortcodeRules[$tag] ?? null;
            if ($rules === null) {
                continue;
            }
            $rulesByAttr = [];
            foreach ($rules as $r) {
                if (!array_key_exists('position', $r)) {
                    $rulesByAttr[$r['path']] = $r;
                }
            }
            foreach ($rules as $r) {
                if (!array_key_exists('position', $r)) {
                    continue;
                }
                $position = (int) $r['position'];
                $positional = Shortcodes::positional_spans($m[3]);
                if (!isset($positional[$position])) {
                    continue;
                }
                $value = trim((string) $positional[$position][0], "\\\"'");
                if (preg_match('/^[0-9]+$/D', $value) !== 1) {
                    continue;
                }
                $findings[] = LintFinding::make(
                    'unrewritten_registered_shortcode_ref',
                    $rel,
                    ($locatorPrefix !== '' ? $locatorPrefix . '.' : '') . "shortcode.$tag.positional[$position]",
                    (int) $value,
                    null,
                    "shortcode '$tag' positional[$position] has a numeric alternate id still present in captured state; "
                    . 'the declared alternate post-meta locator did not produce a canonical token'
                );
            }
            $atts = shortcode_parse_atts($m[3]);
            foreach ($atts as $attrKey => $attrVal) {
                if (!is_string($attrKey)) {
                    continue; // positional/bare value — no name to match a rule or heuristic against
                }
                $rule = $rulesByAttr[$attrKey] ?? null;
                if ($rule === null) {
                    if (self::looksLikeIdKey($attrKey)) {
                        foreach (Pending::numeric_candidates($attrVal) as [$id, $locSuffix]) {
                            $findings[] = LintFinding::make(
                                'unregistered_shortcode_attr', $rel,
                                ($locatorPrefix !== '' ? $locatorPrefix . '.' : '') . "shortcode.$tag.attrs.$attrKey" . $locSuffix,
                                $id,
                                $resolveId($id),
                                "shortcode '$tag' has no shortcode_attrs registry rule for attribute '$attrKey'; "
                                . 'this numeric value passes through capture/apply untouched and will point at '
                                . 'the wrong entity (or nothing) once ids diverge on another environment.'
                            );
                        }
                    }
                    continue;
                }
                foreach (Pending::numeric_candidates($attrVal) as [$id, $locSuffix]) {
                    $findings[] = LintFinding::make(
                        'unrewritten_registered_shortcode_ref', $rel,
                        ($locatorPrefix !== '' ? $locatorPrefix . '.' : '') . "shortcode.$tag.attrs.$attrKey" . $locSuffix,
                        $id,
                        $resolveId($id),
                        "shortcode '$tag' attribute '$attrKey' has a shortcode_attrs registry rule declaring it a "
                        . 'reference, but this value is still numeric in captured state — the declared rewrite to '
                        . 'a {{...}} token never ran (an unmapped/dangling id). This id is silently environment-'
                        . 'bound and will point at the wrong entity (or nothing) once ids diverge on another '
                        . 'environment.'
                    );
                }
            }
        }
        return $findings;
    }

    /**
     * shortcode_parse_atts() lowercases attributes, so the camel/caps block
     * heuristic cannot see names such as source-text `userId`. The separator
     * boundary below covers the lowercased snake/kebab forms without treating
     * ordinary words such as "grid" or "valid" as references.
     */
    private static function looksLikeIdKey(string $key): bool {
        return $key === 'id' || $key === 'ids' || $key === 'ref'
            || (bool) preg_match('/([-_][iI][dD]s?|(Id|ID)s?)$/', $key);
    }
}
