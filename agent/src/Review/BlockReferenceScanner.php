<?php
namespace Duo;

require_once __DIR__ . '/Pending.php';
require_once __DIR__ . '/LintFinding.php';

/**
 * Manifest-declared Gutenberg block-reference scanning behind Lint's facade.
 *
 * This owns only generic parsed-block shapes: declared block_attrs rules,
 * the established id-shaped attribute heuristic, direct environment-home
 * leaks, and recursive inner-block order. The caller owns WordPress parsing,
 * state-tree traversal, and result accumulation. A resolver can be supplied
 * for offline characterization without database contact; production keeps
 * Lint's existing Pending resolver contract.
 */
final class BlockReferenceScanner {
    private const MAX_VALUE_LEN = 200;

    /**
     * @param array<int,array> $blocks parsed Gutenberg blocks
     * @param array<string,list<array>> $blockRules
     * @param null|callable(int):?array $resolveId
     * @return list<array{class:string,path:string,locator:string,value:mixed,matches?:array,note:string}>
     */
    public static function scan(
        array $blocks,
        array $blockRules,
        string $rel,
        string $home,
        ?callable $resolveId = null
    ): array {
        $resolveId ??= static fn(int $id): ?array => Pending::resolve_id($id);
        $findings = [];
        self::scanBlocks($blocks, $blockRules, $rel, $home, $resolveId, $findings);
        return $findings;
    }

    /**
     * @param array<int,array> $blocks
     * @param array<string,list<array>> $blockRules
     * @param callable(int):?array $resolveId
     * @param list<array> $findings
     */
    private static function scanBlocks(
        array $blocks,
        array $blockRules,
        string $rel,
        string $home,
        callable $resolveId,
        array &$findings
    ): void {
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            if ($name !== null) {
                $rulesByPath = [];
                foreach ($blockRules[$name] ?? [] as $r) {
                    $rulesByPath[$r['path']] = $r;
                }
                foreach ((array) ($block['attrs'] ?? []) as $attrKey => $attrVal) {
                    $attrKey = (string) $attrKey;
                    $rule = $rulesByPath[$attrKey] ?? null;
                    if ($rule === null) {
                        if (self::looksLikeIdAttr($attrKey)) {
                            foreach (Pending::numeric_candidates($attrVal) as [$id, $locSuffix]) {
                                $findings[] = LintFinding::make(
                                    'unregistered_block_attr', $rel,
                                    'blocks.' . $name . '.attrs.' . $attrKey . $locSuffix, $id, $resolveId($id),
                                    "block '$name' has no block_attrs registry rule for attribute '$attrKey'; this "
                                    . 'numeric value passes through capture/apply untouched and will point at the '
                                    . 'wrong entity (or nothing) once ids diverge on another environment — the same '
                                    . "shape as core/navigation-link's id/kind pair before it had a registry rule."
                                );
                            }
                        }
                    } elseif (empty($rule['lint_ok']) && ($rule['tokenize'] ?? null) !== 'text') {
                        // DUO-3212: a registered path is a REF rule by
                        // Blocks::resolve_kind()'s own contract (it throws
                        // unless a rule declares 'kind' or 'kind_from' once
                        // lint_ok/tokenize have been ruled out) — so a value
                        // still numeric here means the declared rewrite to a
                        // "{{...}}" token never ran (unmapped/dangling id, or
                        // a kind_from dispatch that resolved to no kind and
                        // was deliberately left untouched). The OLD guard
                        // below (`!in_array($attrKey, $rulePaths, true)`)
                        // exempted every registered path unconditionally,
                        // regardless of whether its value actually got
                        // rewritten — invisible exactly where this linter is
                        // supposed to look. Fires regardless of whether the
                        // number resolves to a live entity (matches?
                        // optional), same posture as unregistered_block_attr.
                        foreach (Pending::numeric_candidates($attrVal) as [$id, $locSuffix]) {
                            $findings[] = LintFinding::make(
                                'unrewritten_registered_ref', $rel,
                                'blocks.' . $name . '.attrs.' . $attrKey . $locSuffix, $id, $resolveId($id),
                                "block '$name' attribute '$attrKey' has a block_attrs registry rule declaring it a "
                                . 'reference, but this value is still numeric in captured state — the declared '
                                . 'rewrite to a {{...}} token never ran (an unmapped/dangling id, or — for a '
                                . 'kind_from-dispatched rule — a sibling value that resolved to no kind). This id '
                                . 'is silently environment-bound and will point at the wrong entity (or nothing) '
                                . 'once ids diverge on another environment.'
                            );
                        }
                    }
                    if (is_string($attrVal) && $attrVal !== '' && str_contains($attrVal, $home)) {
                        $findings[] = LintFinding::make(
                            'unregistered_block_attr', $rel,
                            'blocks.' . $name . '.attrs.' . $attrKey, self::truncate($attrVal), null,
                            "block '$name' attribute '$attrKey' contains this environment's home URL in plain "
                            . 'form; block attributes are parsed JSON values, never routed through '
                            . 'tokenize_text()/detokenize_text() (only innerHTML/innerContent are today), so it '
                            . "will leak this environment's host into the target regardless of any registry rule."
                        );
                    }
                }
            }
            if (!empty($block['innerBlocks'])) {
                self::scanBlocks($block['innerBlocks'], $blockRules, $rel, $home, $resolveId, $findings);
            }
        }
    }

    /**
     * id / ids / ref, or a suffixed *Id / *Ids / *ID / *IDs (task #76:
     * Ninja Forms' Gutenberg block declares "formID"; the old /(Id|Ids)$/
     * missed the all-caps convention, letting a dangling formID pass both
     * the lint gate and byte-diff round-trip, then fatal on the target when
     * NF resolved the missing form). NOT a bare /i flag — that would match
     * innocent lowercase suffixes ("grid", "valid"); the camel/caps boundary
     * is what makes the heuristic safe, so only the cased variants widen.
     */
    private static function looksLikeIdAttr(string $key): bool {
        return $key === 'id' || $key === 'ids' || $key === 'ref' || (bool) preg_match('/(Id|ID)s?$/', $key);
    }

    private static function truncate($value): string {
        $value = (string) $value;
        return strlen($value) > self::MAX_VALUE_LEN
            ? substr($value, 0, self::MAX_VALUE_LEN) . '…(truncated)'
            : $value;
    }
}
