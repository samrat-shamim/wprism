<?php
namespace Duo;

/**
 * The pure block/shortcode attribute declaration grammar extracted from
 * Policy.php (DUO-3348 slice 17). It validates selector shape, positional
 * shortcode lookup domains, ref-kind dispatch, and the closed attribute
 * value/tokenize vocabularies before a block or shortcode reaches rewriting.
 *
 * The generic value-cast vocabulary is intentionally supplied by Policy: it
 * is shared with classification and deletion-guard declarations. Keeping
 * that input explicit prevents this collaborator from creating a second cast
 * vocabulary while allowing its own attribute vocabularies to travel with
 * the grammar and its published accessors.
 */
final class AttributeGrammar {
    /**
     * Loud, load-time guard for the two attribute-rewriting registries
     * (DUO-3318): `block_attrs` (blockName => list of rules) and its flatter
     * shortcode twin `shortcode_attrs` (tagName => list of rules).
     *
     * Structure only — the ref KIND vocabulary is checked once, across every
     * pinned manifest, by ReferenceKindGrammar::validate_ref_kinds(), because a legal kind
     * includes any declared table's id_kind and no single manifest can see
     * that set. What this catches is the shape errors that used to fail
     * silently: Blocks::apply_rewrite() skips any rule whose `path` names an
     * attribute the block does not carry, so a misspelled `path` is
     * indistinguishable from "this block simply had no such attribute" — a
     * declared ref that is never rewritten, leaving a raw environment-local
     * id in canonical state. `type` decides scalar-vs-list handling and
     * defaults to 'int', so 'array' or 'int[] ' quietly truncated a gallery's
     * id list to one dropped attribute.
     *
     * Every rule must carry exactly one disposition, because these are
     * mutually exclusive dispatch branches in Blocks.php, not composable
     * flags: `lint_ok` (declared non-ref, nothing to rewrite),
     * `tokenize: "text"` (the URL-bearing string leaves of an attribute),
     * `unsupported` (a reviewed blocking boundary), `codec` (one adapter's
     * manifest-bound whole-block codec), `kind` (a static ref kind), or
     * `kind_from` (a ref kind dispatched from a sibling attribute's value).
     * A rule with none of them reaches
     * Blocks::resolve_kind()'s own throw at REWRITE time, mid-capture, on
     * whichever post happened to contain that block first.
     */
    public static function validate_attr_rules(array $manifest, array $casts): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach (['block_attrs', 'shortcode_attrs'] as $section) {
            $registry = $manifest[$section] ?? [];
            if (!is_array($registry) || (array_is_list($registry) && $registry !== [])) {
                throw new \RuntimeException("duo: manifest '$name' $section must be an object keyed by name");
            }
            foreach ($registry as $subject => $rules) {
                $where = "manifest '$name' $section.$subject";
                if (!is_array($rules) || !array_is_list($rules) || $rules === []) {
                    throw new \RuntimeException("duo: $where must be a non-empty list of rules");
                }
                $hasPositionRule = false;
                $hasPathRule = false;
                foreach ($rules as $candidate) {
                    if (!is_array($candidate)) {
                        continue;
                    }
                    $hasPositionRule = $hasPositionRule || array_key_exists('position', $candidate);
                    $hasPathRule = $hasPathRule || array_key_exists('path', $candidate);
                }
                if ($hasPositionRule && $hasPathRule) {
                    throw new \RuntimeException(
                        "duo: $where cannot mix positional and named path rules; positional callbacks consume "
                        . 'the first parsed value and have no defined selector for named attributes'
                    );
                }
                $codec = null;
                $codecPaths = [];
                foreach ($rules as $i => $candidate) {
                    if (!is_array($candidate) || !array_key_exists('codec', $candidate)) {
                        continue;
                    }
                    if ($codec === null) {
                        $codec = $candidate['codec'];
                    } elseif ($codec !== $candidate['codec']) {
                        throw new \RuntimeException(
                            "duo: $where mixes whole-block codecs; one block grammar has exactly one codec owner"
                        );
                    }
                    $path = $candidate['path'] ?? null;
                    if (is_string($path) && isset($codecPaths[$path])) {
                        throw new \RuntimeException(
                            "duo: $where[$i].path duplicates codec-owned attribute '$path' already declared at "
                            . 'index ' . $codecPaths[$path]
                        );
                    }
                    if (is_string($path)) {
                        $codecPaths[$path] = $i;
                    }
                }
                if ($codec !== null && count($codecPaths) !== count($rules)) {
                    throw new \RuntimeException(
                        "duo: $where cannot mix a whole-block codec with per-attribute dispositions"
                    );
                }
                $seenPositions = [];
                foreach ($rules as $i => $rule) {
                    if (is_array($rule) && array_key_exists('position', $rule)) {
                        $position = $rule['position'];
                        if (is_int($position) && isset($seenPositions[$position])) {
                            throw new \RuntimeException(
                                "duo: $where[$i].position duplicates position $position already declared at index "
                                . $seenPositions[$position]
                            );
                        }
                        if (is_int($position)) {
                            $seenPositions[$position] = $i;
                        }
                    }
                    self::validate_attr_rule(
                        $rule,
                        $section,
                        $where . "[$i]",
                        $casts,
                        $manifest['interpreter'] ?? null
                    );
                }
            }
        }
    }

    /** The closed `block_attrs`/`shortcode_attrs` `type` vocabulary. */
    private const ATTR_VALUE_TYPES = ['int', 'int[]'];
    /** The closed attribute `tokenize` codec vocabulary (recursive home/uploads URL pass). */
    private const ATTR_TOKENIZE_CODECS = ['text'];

    /** One `block_attrs`/`shortcode_attrs` entry. @see validate_attr_rules() */
    private static function validate_attr_rule(
        mixed $rule,
        string $section,
        string $where,
        array $casts,
        mixed $interpreter
    ): void {
        if (!is_array($rule) || (array_is_list($rule) && $rule !== [])) {
            throw new \RuntimeException("duo: $where must be an object");
        }
        $hasPathKey = array_key_exists('path', $rule);
        $hasPath = is_string($rule['path'] ?? null) && $rule['path'] !== '';
        $hasPosition = array_key_exists('position', $rule);
        if ($hasPosition && $hasPathKey) {
            throw new \RuntimeException(
                "duo: $where must declare exactly one of path or position — the two locator forms cannot be combined"
            );
        }
        if ($hasPathKey && !$hasPath && !$hasPosition) {
            // Preserve the long-standing diagnostic for malformed path rules;
            // callers and the manifest grammar regression depend on this
            // precise refusal while positional rules use their own vocabulary.
            throw new \RuntimeException(
                "duo: $where.path must be a non-empty attribute name — an unmatched path is silently skipped at "
                . 'rewrite time, so a declared ref would never actually be tokenized'
            );
        }
        if (($hasPath ? 1 : 0) + ($hasPosition ? 1 : 0) !== 1) {
            throw new \RuntimeException(
                "duo: $where must declare exactly one non-empty path or positional index — an unmatched path is "
                . 'silently skipped at rewrite time, so a declared ref would never actually be tokenized'
            );
        }
        if ($hasPosition) {
            if ($section !== 'shortcode_attrs' || !is_int($rule['position']) || $rule['position'] < 0) {
                throw new \RuntimeException(
                    "duo: $where.position must be a non-negative integer and is supported only for shortcode_attrs"
                );
            }
            $ruleKeys = array_keys($rule);
            $expectedRuleKeys = ['kind', 'position', 'lookup'];
            sort($ruleKeys, SORT_STRING);
            sort($expectedRuleKeys, SORT_STRING);
            if ($ruleKeys !== $expectedRuleKeys) {
                throw new \RuntimeException(
                    "duo: $where positional refs have a closed vocabulary: exactly {kind,position,lookup}"
                );
            }
            if (!is_array($rule['lookup'] ?? null)
                || !is_string($rule['lookup']['post_meta'] ?? null)
                || $rule['lookup']['post_meta'] === ''
                || !is_string($rule['lookup']['post_type'] ?? null)
                || $rule['lookup']['post_type'] === '') {
                throw new \RuntimeException(
                    "duo: $where.lookup must declare non-empty post_meta and post_type domains for a positional ref"
                );
            }
            if (array_diff(array_keys($rule['lookup']), ['post_meta', 'post_type']) !== []
                || count(array_unique(array_keys($rule['lookup']))) !== 2
                || array_key_exists('cast', $rule)
                || array_key_exists('type', $rule)
                || array_key_exists('lint_ok', $rule)) {
                throw new \RuntimeException("duo: $where positional refs have a closed vocabulary: lookup={post_meta,post_type}, kind=post, position only");
            }
            if (($rule['kind'] ?? null) !== 'post'
                || array_key_exists('kind_from', $rule)
                || array_key_exists('tokenize', $rule)) {
                throw new \RuntimeException(
                    "duo: $where positional refs require static kind=post and cannot use kind_from, tokenize, or lint_ok"
                );
            }
        } elseif (array_key_exists('lookup', $rule)) {
            if ($section !== 'shortcode_attrs') {
                throw new \RuntimeException("duo: $where.lookup is supported only for shortcode refs");
            }
            $ruleKeys = array_keys($rule);
            $expectedRuleKeys = ['kind', 'lookup', 'path', 'required'];
            sort($ruleKeys, SORT_STRING);
            sort($expectedRuleKeys, SORT_STRING);
            if ($ruleKeys !== $expectedRuleKeys) {
                throw new \RuntimeException(
                    "duo: $where named alternate refs have a closed vocabulary: exactly {kind,lookup,path,required}"
                );
            }
            $lookup = $rule['lookup'];
            $lookupKeys = is_array($lookup) ? array_keys($lookup) : [];
            $hasStoredLength = is_array($lookup) && array_key_exists('stored_length', $lookup);
            $hasStoredLengths = is_array($lookup) && array_key_exists('stored_lengths', $lookup);
            $expectedLookupKeys = [
                'codec',
                'post_meta',
                'post_type',
                'prefix_length',
                $hasStoredLengths && !$hasStoredLength ? 'stored_lengths' : 'stored_length',
            ];
            sort($lookupKeys, SORT_STRING);
            sort($expectedLookupKeys, SORT_STRING);
            if (!is_array($lookup) || $lookupKeys !== $expectedLookupKeys
                || ($lookup['codec'] ?? null) !== 'hex-prefix'
                || !is_string($lookup['post_meta'] ?? null) || $lookup['post_meta'] === ''
                || !is_string($lookup['post_type'] ?? null) || $lookup['post_type'] === ''
                || !is_int($lookup['prefix_length'] ?? null) || $lookup['prefix_length'] < 1) {
                throw new \RuntimeException(
                    "duo: $where.lookup must be exactly {codec:hex-prefix,post_meta,post_type,prefix_length,stored_length}, "
                    . 'with non-empty domains and 1 <= prefix_length <= stored_length <= 128'
                );
            }
            if ($hasStoredLengths) {
                $storedLengths = $lookup['stored_lengths'];
                $validStoredLengths = is_array($storedLengths)
                    && array_is_list($storedLengths)
                    && count($storedLengths) >= 2;
                if ($validStoredLengths) {
                    foreach ($storedLengths as $length) {
                        if (!is_int($length) || $length < $lookup['prefix_length'] || $length > 128) {
                            $validStoredLengths = false;
                            break;
                        }
                    }
                }
                if ($validStoredLengths) {
                    $sortedLengths = $storedLengths;
                    sort($sortedLengths, SORT_NUMERIC);
                    $validStoredLengths = $storedLengths === $sortedLengths
                        && count(array_unique($storedLengths, SORT_REGULAR)) === count($storedLengths);
                }
                if (!$validStoredLengths) {
                    throw new \RuntimeException(
                        "duo: $where.lookup stored_lengths must be a strictly increasing list of at least two unique "
                        . 'integers with prefix_length <= each stored length <= 128'
                    );
                }
            } elseif (!is_int($lookup['stored_length'] ?? null)
                || $lookup['stored_length'] < $lookup['prefix_length']
                || $lookup['stored_length'] > 128) {
                throw new \RuntimeException(
                    "duo: $where.lookup must be exactly {codec:hex-prefix,post_meta,post_type,prefix_length,stored_length}, "
                    . 'with non-empty domains and 1 <= prefix_length <= stored_length <= 128'
                );
            }
            if (($rule['kind'] ?? null) !== 'post' || ($rule['required'] ?? null) !== true) {
                throw new \RuntimeException(
                    "duo: $where named alternate refs require static kind=post and required=true"
                );
            }
        } elseif (array_key_exists('required', $rule)) {
            throw new \RuntimeException("duo: $where.required is allowed only on named alternate shortcode refs");
        }
        if (array_key_exists('lint_ok', $rule) && !is_bool($rule['lint_ok'])) {
            throw new \RuntimeException("duo: $where.lint_ok must be a boolean");
        }
        if (array_key_exists('type', $rule) && !in_array($rule['type'], self::ATTR_VALUE_TYPES, true)) {
            throw new \RuntimeException(
                "duo: $where.type=" . var_export($rule['type'], true) . ' but the attribute-value vocabulary is '
                . 'closed and engine-owned (int, int[]); an id-bearing attribute is either one id or a native '
                . 'list of them, and any other shape needs engine support before it can be declared'
            );
        }
        if (array_key_exists('cast', $rule) && !in_array($rule['cast'], $casts, true)) {
            throw new \RuntimeException(
                "duo: $where.cast=" . var_export($rule['cast'], true) . ' but only '
                . implode('|', $casts) . ' are supported'
            );
        }
        if (array_key_exists('tokenize', $rule) && !in_array($rule['tokenize'], self::ATTR_TOKENIZE_CODECS, true)) {
            throw new \RuntimeException(
                "duo: $where.tokenize=" . var_export($rule['tokenize'], true)
                . ' but the only supported codec for an attribute is "text" (the ordinary home/uploads URL pass)'
            );
        }
        if (array_key_exists('unsupported', $rule)
            && (!is_string($rule['unsupported']) || trim($rule['unsupported']) === ''
                || strlen($rule['unsupported']) > 512)) {
            throw new \RuntimeException(
                "duo: $where.unsupported must be a non-empty reviewed reason of at most 512 bytes"
            );
        }
        if ($section !== 'block_attrs' && array_key_exists('unsupported', $rule)) {
            throw new \RuntimeException("duo: $where.unsupported is supported only for block_attrs");
        }
        if (array_key_exists('codec', $rule)) {
            if ($section !== 'block_attrs') {
                throw new \RuntimeException("duo: $where.codec is supported only for block_attrs");
            }
            $keys = array_keys($rule);
            sort($keys, SORT_STRING);
            if ($keys !== ['codec', 'path']) {
                throw new \RuntimeException(
                    "duo: $where codec rules have a closed vocabulary: exactly {codec,path}"
                );
            }
            if (!is_string($rule['codec'])
                || preg_match('/^[a-z0-9_-]+$/D', $rule['codec']) !== 1
                || !is_string($interpreter)
                || !hash_equals($interpreter, $rule['codec'])) {
                throw new \RuntimeException(
                    "duo: $where.codec must equal the declaring manifest's own non-empty interpreter name; "
                    . 'whole-block executable authority cannot be borrowed from another adapter'
                );
            }
        }
        if (array_key_exists('kind_from', $rule)) {
            $from = $rule['kind_from'];
            if (!is_array($from) || !is_string($from['attr'] ?? null) || ($from['attr'] ?? '') === ''
                || !is_array($from['map'] ?? null) || ($from['map'] ?? []) === []) {
                throw new \RuntimeException(
                    "duo: $where.kind_from must declare a non-empty sibling `attr` and a non-empty `map` of that "
                    . "attribute's values to ref kinds"
                );
            }
            if (array_key_exists('kind', $rule)) {
                throw new \RuntimeException(
                    "duo: $where declares BOTH kind and kind_from — a rule's ref kind is either static or "
                    . 'dispatched from a sibling attribute, never both'
                );
            }
        }
        $dispositions = array_filter([
            'lint_ok' => !empty($rule['lint_ok']),
            'tokenize' => array_key_exists('tokenize', $rule),
            'unsupported' => array_key_exists('unsupported', $rule),
            'codec' => array_key_exists('codec', $rule),
            'kind' => array_key_exists('kind', $rule),
            'kind_from' => array_key_exists('kind_from', $rule),
        ]);
        if ($dispositions === []) {
            throw new \RuntimeException(
                "duo: $where declares none of kind, kind_from, tokenize, unsupported, codec, or lint_ok — every "
                . ($section === 'block_attrs' ? 'block' : 'shortcode') . ' attribute rule must say what the '
                . 'engine should do with the value it names; a rule with no disposition is refused here rather '
                . 'than reaching its throw mid-capture, on whichever entity happened to carry it first'
            );
        }
        if (count($dispositions) !== 1) {
            throw new \RuntimeException(
                "duo: $where must declare exactly one disposition "
                . '(kind, kind_from, tokenize, unsupported, codec, or lint_ok)'
            );
        }
    }

    /** @return list<string> Policy::closed_vocabularies()'s attribute type vocabulary. */
    public static function attributeValueTypes(): array {
        return self::ATTR_VALUE_TYPES;
    }

    /** @return list<string> Policy::closed_vocabularies()'s attribute tokenize vocabulary. */
    public static function attributeTokenizeCodecs(): array {
        return self::ATTR_TOKENIZE_CODECS;
    }
}
