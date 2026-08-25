<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/ReferenceScopeClassifier.php';
require_once __DIR__ . '/AttrIdCodecGrammar.php';

/**
 * Structure-aware content rewriting via the official block parser:
 * - block attributes per the manifest block_attrs registry (typed paths),
 * - wp-image-<id> classes inside media blocks' inner HTML,
 * - URL tokenization of inner content strings.
 * Classic (non-block) content parses as a single freeform block and gets URL
 * tokenization only. serialize_blocks() re-emission is the canonical form; it
 * is a fixed point after the first normalization, which the capture-twice
 * determinism test asserts.
 *
 * block_attrs rules come in three shapes, freely mixed per block name:
 * - a static ref: {"kind": "post"|"term"|"tt", "path": ..., "type": "int"|"int[]"}
 * - a polymorphic ref, kind dispatched from a sibling attribute:
 *   {"kind_from": {"attr": ..., "map": {sibling-value: kind}, "default"?: kind},
 *    "path": ..., "type": ...} — e.g. core/navigation-link's "id" is a post
 *   or term ref depending on its own "kind" attribute ("post-type"/"taxonomy"/
 *   "custom"/"post-type-archive"); dispatch resolving to no kind (no map hit,
 *   no default) leaves that attribute untouched rather than guessing.
 * - a text tokenizer: {"path": ..., "tokenize": "text"} — routes every
 *   string leaf of the attribute value through the same {{home}}/{{uploads}}
 *   substitution as body text. The recursive shape covers core/video's
 *   tracks array; scalar URL attributes remain the common case. Attribute
 *   values are otherwise invisible to the innerHTML/innerContent pass below
 *   (self-closing blocks like core/navigation-link carry no inner content at
 *   all), so this is the only way a URL-shaped attribute gets rebound across
 *   environments.
 *
 * A "kind"/"kind_from" ref's id_to_token() failing is either DANGLING (no
 * ledger row for that id at all — deleted target, or never existed) or
 * UNSCOPED (a real row exists, but its post_type/taxonomy was never added
 * to policy scope, so it was never minted a uuid) — task #73's own
 * distinction for options, ported here in full (DUO-3212): dangling gets
 * the uniform drop-with-warning treatment matching options'/meta's
 * dangling-reference semantics (spec/repo-format.md) — a scalar ref drops
 * the whole attribute key, an int[] ref drops just that element, both with
 * a warning naming the block/attribute/id. Unscoped queues onto
 * Tokens::$unscopedBlockRefs instead, for CaptureCandidateBuilder's batched
 * loud-and-blocking gate (mirroring task #73's option-ref gate exactly) —
 * a real row of an in-scope type simply not minted YET on this build is
 * neither dangling nor unscoped and still falls through to the ordinary
 * drop (the same false-positive guard task #73's own mechanism needs: see
 * ReferenceScopeClassifier's docblock for why a fresh target's
 * own not-yet-minted default_category-shaped case must never abort). A raw
 * env-local id must never survive into canonical state either way —
 * Lint::scan_blocks()'s unrewritten_registered_ref finding is what catches
 * it if it ever does.
 */
final class Blocks {
    /** Blocks whose inner HTML may carry wp-image-<id> classes. */
    private const IMAGE_CLASS_BLOCKS = ['core/image', 'core/gallery', 'core/media-text', 'core/cover'];

    /**
     * @param string $postLabel human-readable identifying string for the
     *   post this content belongs to (e.g. "page 'about-us'"), named in any
     *   unscoped-ref violation queued during this call — Blocks.php itself
     *   only ever sees a content string, never the post row, so this is the
     *   one piece of context the caller (PostCapture) must supply
     *   for the batched abort message to be as actionable as options' own.
     */
    public static function capture_rewrite(
        string $content,
        Policy $policy,
        Tokens $tokens,
        bool $forceUnresolvedRefs = false,
        string $postLabel = ''
    ): string {
        if ($content === '') {
            return '';
        }
        $blocks = parse_blocks($content);
        $rules = $policy->block_attr_rules();
        // WP-6.1: resolved once beside $rules, never per block, because the two
        // are one declaration about one block's attribute grammar (see
        // AttrIdCodecGrammar::rules()) and a per-block lookup would re-walk the
        // pin set for every block in a post body.
        $idCodecs = $policy->attr_id_codec_rules();
        $blocks = array_map(
            fn($b) => self::walk($b, $rules, $tokens, true, $policy, $forceUnresolvedRefs, $postLabel, $idCodecs),
            $blocks
        );
        return serialize_blocks($blocks);
    }

    public static function apply_rewrite(string $content, Policy $policy, Tokens $tokens): string {
        if ($content === '') {
            return '';
        }
        $blocks = parse_blocks($content);
        $rules = $policy->block_attr_rules();
        $idCodecs = $policy->attr_id_codec_rules();
        $blocks = array_map(
            fn($b) => self::walk($b, $rules, $tokens, false, $policy, false, '', $idCodecs),
            $blocks
        );
        return serialize_blocks($blocks);
    }

    /** @param array<string,array<string,array{id_type:string}>> $idCodecs */
    private static function walk(
        array $block,
        array $rules,
        Tokens $tokens,
        bool $capture,
        Policy $policy,
        bool $forceUnresolvedRefs,
        string $postLabel,
        array $idCodecs = []
    ): array {
        $name = $block['blockName'];
        // Classic (non-block) content parses as a freeform block whose
        // `blockName` is NULL, and PHP 8.5 deprecates a null array offset —
        // measured as two notices per freeform block from the `$rules[$name]`
        // lookup alone, before WP-6.1 added a second lookup beside it. No
        // registry can hold a rule under the empty string (a block name is
        // WordPress's own namespace/name pair), so normalising the LOOKUP key
        // and leaving `$name` itself null keeps every warning and every
        // IMAGE_CLASS_BLOCKS check reading exactly as it did.
        $lookup = is_string($name) ? $name : '';
        $blockIdCodecs = $idCodecs[$lookup] ?? [];
        foreach ($rules[$lookup] ?? [] as $rule) {
            $path = $rule['path'];
            if (!isset($block['attrs'][$path])) {
                continue;
            }
            $v = $block['attrs'][$path];

            if (!empty($rule['lint_ok'])) {
                // declared non-ref attribute (e.g. queryId — a query instance
                // index, not an entity id): exempts it from `wp duo lint`'s
                // *Id-name heuristic, and there is nothing to rewrite here
                continue;
            }

            if (array_key_exists('unsupported', $rule)) {
                throw new \RuntimeException(
                    "duo: block '$name' attribute '$path' is explicitly unsupported: " . $rule['unsupported']
                );
            }

            if (($rule['tokenize'] ?? null) === 'text') {
                $block['attrs'][$path] = self::rewrite_text_value($v, $tokens, $capture);
                continue;
            }

            $kind = self::resolve_kind($rule, $block['attrs']);
            if ($kind === null) {
                continue;
            }
            $isArray = ($rule['type'] ?? 'int') === 'int[]';
            if ($capture) {
                // Dangling-reference semantics matching options/meta refs
                // (spec/repo-format.md "Dangling references"): an unmapped
                // id must never reach canonical state as a raw env-local
                // int — on another environment it may silently resolve to
                // an unrelated live row after auto-increment reuse. The
                // previous `?? (int) $id` here kept the raw id instead of
                // dropping it — the exact gap Lint::scan_blocks()'s new
                // unrewritten_registered_ref finding now catches when it
                // already happened. DUO-3212: the unmapped id is ALSO
                // triaged into dangling vs. unscoped (self::queue_unscoped()
                // below, mirroring Capture::queue_or_warn_unscoped() for
                // options exactly) — the drop-with-warning below happens
                // either way (an unscoped ref is still dropped from THIS
                // candidate value; the abort, if any, is a later batched
                // gate in CaptureCandidateBuilder, the same posture options use).
                if ($isArray) {
                    $kept = [];
                    foreach ((array) $v as $i => $id) {
                        if ((int) $id === 0) {
                            continue; // declared unset convention, never a dangling id
                        }
                        $tok = $kind === 'user'
                            ? $tokens->user_id_to_token((int) $id)
                            : $tokens->id_to_token((int) $id, $kind);
                        if ($tok === null) {
                            // user_id_to_token() owns its env-local warning;
                            // users never participate in duo_map scope triage.
                            if ($kind !== 'user') {
                                $tokens->warnings[] = "block '$name' attribute '$path" . "[$i]': unmapped $kind id "
                                    . (int) $id . ' dropped (dangling reference)';
                                self::queue_unscoped(
                                    $tokens, $policy, $forceUnresolvedRefs, $postLabel,
                                    $name, "$path" . "[$i]", $kind, (int) $id
                                );
                            }
                            continue;
                        }
                        $kept[] = $tok;
                    }
                    $block['attrs'][$path] = $kept;
                } else {
                    if ((int) $v === 0) {
                        unset($block['attrs'][$path]); // absence restores WordPress's scalar default
                        continue;
                    }
                    // WP-6.1's identity round-trip precondition, evaluated
                    // BEFORE the value is tokenized: apply writes the DECLARED
                    // stored type, so a source whose type disagrees would come
                    // back with different bytes even when the id resolved to
                    // itself. See AttrIdCodecGrammar::assert_source_type().
                    AttrIdCodecGrammar::assert_source_type($v, $blockIdCodecs, $path, $name);
                    $tok = $kind === 'user'
                        ? $tokens->user_id_to_token((int) $v)
                        : $tokens->id_to_token((int) $v, $kind);
                    if ($tok === null) {
                        // user_id_to_token() owns its env-local warning;
                        // users never participate in duo_map scope triage.
                        if ($kind !== 'user') {
                            $tokens->warnings[] = "block '$name' attribute '$path': unmapped $kind id " . (int) $v
                                . ' dropped (dangling reference)';
                            self::queue_unscoped(
                                $tokens, $policy, $forceUnresolvedRefs, $postLabel,
                                $name, $path, $kind, (int) $v
                            );
                        }
                        unset($block['attrs'][$path]);
                    } else {
                        $block['attrs'][$path] = $tok;
                    }
                }
            } else {
                if ($isArray) {
                    $block['attrs'][$path] = array_map(
                        fn($t) => $kind === 'user' && is_string($t) && str_starts_with($t, 'user:')
                            ? $tokens->user_token_to_id($t)
                            : (is_string($t) && str_starts_with($t, '{{')
                                ? $tokens->token_to_id($t)
                                : (int) $t),
                        (array) $v
                    );
                } else {
                    // WP-6.1: the ONE place the resolved id's JSON type is
                    // decided. With no codec declared this is `(int) $v` byte
                    // for byte, which is what every rule did before; with
                    // `id_type: "string"` the id goes back as the string the
                    // plugin stores, so a round trip that substituted nothing
                    // reproduces the block's bytes exactly.
                    $resolved = $kind === 'user' && is_string($v) && str_starts_with($v, 'user:')
                        ? $tokens->user_token_to_id($v)
                        : (is_string($v) && str_starts_with($v, '{{') ? $tokens->token_to_id($v) : (int) $v);
                    $block['attrs'][$path] = AttrIdCodecGrammar::encode_id($resolved, $blockIdCodecs, $path);
                }
            }
        }

        $rewriteImageClass = in_array($name, self::IMAGE_CLASS_BLOCKS, true);
        $rewriteString = function (?string $s) use (
            $tokens, $capture, $rewriteImageClass, $policy, $forceUnresolvedRefs, $postLabel, $name
        ): ?string {
            if ($s === null || $s === '') {
                return $s;
            }
            if ($rewriteImageClass) {
                if ($capture) {
                    // DUO-3212: this used to fail OPEN on an unmapped id —
                    // $m[0] (the raw "wp-image-999" text) returned unchanged,
                    // leaking the raw env-local id into canonical state
                    // (harness case B4) — the one place in this class that
                    // didn't already match attrs.id's own drop-with-warning
                    // treatment two mechanisms up, despite reading the SAME
                    // ledger entry via the SAME id_to_token() call. Capture
                    // group 1 is whatever whitespace precedes the token (or
                    // '' at the start of a class list); on drop, both the
                    // token AND its own leading separator are removed
                    // together, so "foo wp-image-999 bar" -> "foo bar" (the
                    // separator AFTER "wp-image-999" already there before
                    // bar is left untouched) rather than leaving a double
                    // space or an orphaned separator behind.
                    $s = preg_replace_callback('/(\s*)wp-image-(\d+)/', function ($m) use (
                        $tokens, $policy, $forceUnresolvedRefs, $postLabel, $name
                    ) {
                        $ws = $m[1];
                        $id = (int) $m[2];
                        $tok = $tokens->id_to_token($id, 'post');
                        if ($tok !== null) {
                            return $ws . 'wp-image-' . $tok;
                        }
                        $tokens->warnings[] = "block '$name' wp-image-$id class: unmapped post id $id "
                            . 'dropped (dangling reference)';
                        self::queue_unscoped(
                            $tokens, $policy, $forceUnresolvedRefs, $postLabel,
                            $name, 'wp-image-class', 'post', $id
                        );
                        return ''; // drop the class AND its own leading separator together
                    }, $s);
                } else {
                    $s = preg_replace_callback('/wp-image-(\{\{post:[0-9a-f-]{36}\}\})/', function ($m) use ($tokens) {
                        return 'wp-image-' . $tokens->token_to_id($m[1]);
                    }, $s);
                }
            }
            // DUO-3259: shortcode-attribute ref rewriting, threaded
            // through the SAME per-chunk closure wp-image-N/URL
            // tokenization already runs on -- a shortcode instance is
            // just more raw text sitting in innerContent, whether it's
            // hand-typed into a Classic/Paragraph block or the entire
            // freeform body of pre-block classic content (Blocks.php's
            // own docblock: "Classic content parses as a single freeform
            // block"). Ordered structural-rewrite-first, generic-text-
            // tokenization-last on capture (mirrors Tokens::struct_
            // capture()'s own json_refs-then-tokenize_leaves() ordering);
            // reversed on apply (mirrors struct_apply()'s detokenize-
            // first ordering) -- neither pass's substrings overlap the
            // other's in practice, so this is precedent-consistency, not
            // a correctness requirement.
            if ($capture) {
                $s = Shortcodes::capture_rewrite_text($s, $policy, $tokens, $forceUnresolvedRefs, $postLabel);
                // DUO-3260: $postLabel already in scope for Shortcodes'
                // own call above -- passed through as tokenize_text()'s
                // own optional $contextLabel too, so a url-query-ref
                // violation from THIS call site names its post, the same
                // way block/shortcode violations already do (best-effort
                // only; most of this codebase's other tokenize_text()
                // call sites have no equally cheap label -- see Tokens::
                // $unscopedUrlQueryRefs's own docblock).
                return $tokens->tokenize_text($s, $postLabel);
            }
            $s = $tokens->detokenize_text($s);
            return Shortcodes::apply_rewrite_text($s, $policy, $tokens);
        };

        if (!empty($block['innerContent'])) {
            $block['innerContent'] = array_map(
                fn($chunk) => is_string($chunk) ? $rewriteString($chunk) : $chunk,
                $block['innerContent']
            );
        }
        // DUO-3212: deliberately NOT also rewriting $block['innerHTML'] here
        // (the previous code did). serialize_block() (parse_blocks()'s own
        // counterpart, verified by reading it directly) exclusively walks
        // innerContent to reconstruct output — innerHTML is WordPress's own
        // parser-convention duplicate, populated at parse time for API
        // completeness, never read by anything downstream of this method
        // (grepped agent/src/ — zero readers). For a block with no nested
        // innerBlocks, innerContent is exactly [innerHTML] (identical
        // string, confirmed against the block-parser stub's own parse
        // loop), so rewriting both was always redundant work on the same
        // input — harmless while $rewriteString was a pure substitution,
        // but once the wp-image-N branch gained side effects (a warning +
        // an unscoped-ref queue push), redundant execution became a real
        // double-fire bug: caught by this task's own new B4/B10 checks
        // asserting on warning/queue COUNTS, not just final values, before
        // it shipped.
        if (!empty($block['innerBlocks'])) {
            $block['innerBlocks'] = array_map(
                fn($b) => self::walk($b, $rules, $tokens, $capture, $policy, $forceUnresolvedRefs, $postLabel, $idCodecs),
                $block['innerBlocks']
            );
        }
        return $block;
    }

    private static function rewrite_text_value($value, Tokens $tokens, bool $capture) {
        if (is_string($value)) {
            return $capture ? $tokens->tokenize_text($value) : $tokens->detokenize_text($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::rewrite_text_value($item, $tokens, $capture);
            }
        }
        return $value;
    }

    /**
     * DUO-3212 (task #73's own unscoped-vs-dangling triage, ported): queues
     * an UNSCOPED violation onto $tokens->unscopedBlockRefs for Capture::
     * build()'s batched abort, or no-ops for any of the three reasons
     * Capture::queue_or_warn_unscoped() no-ops for options (see that
     * method's own docblock for the full reasoning, reproduced exactly
     * here): the target is genuinely DANGLING (ReferenceScopeClassifier
     * found no real row at all), the target's type IS in policy scope but
     * this build simply hasn't minted it a uuid yet (a MINTING question,
     * not a POLICY question — checking id_to_token() alone can never tell
     * the two apart), or $force ($forceUnresolvedRefs / --force-unresolved-
     * refs) explicitly asked for the old best-effort drop regardless. Does
     * NOT itself perform the drop — every caller already does that
     * unconditionally, the same way option_ref_tokens() does; this only
     * decides whether the drop ALSO counts as a reportable scope gap.
     */
    private static function queue_unscoped(
        Tokens $tokens,
        Policy $policy,
        bool $force,
        string $postLabel,
        string $block,
        string $attr,
        string $kind,
        int $id
    ): void {
        $targetType = ReferenceScopeClassifier::classify($id, $kind, $force, $policy);
        if ($targetType === null) {
            return;
        }
        $tokens->unscopedBlockRefs[] = [
            'post' => $postLabel,
            'block' => $block,
            'attr' => $attr,
            'kind' => $kind,
            'id' => $id,
            'target_type' => $targetType,
        ];
    }

    /**
     * A rule's ref kind is either static ("kind") or dispatched from a
     * sibling attribute's current value ("kind_from": {attr, map, default?}).
     * Null means "no applicable kind" (e.g. a custom-kind navigation link,
     * where the sibling value has no map entry and no default is declared) —
     * the caller leaves that attribute untouched rather than guessing a kind.
     */
    private static function resolve_kind(array $rule, array $attrs): ?string {
        if (isset($rule['kind_from'])) {
            $kf = $rule['kind_from'];
            $sibling = $attrs[$kf['attr']] ?? null;
            return $kf['map'][$sibling] ?? $kf['default'] ?? null;
        }
        if (isset($rule['kind'])) {
            return $rule['kind'];
        }
        throw new \RuntimeException("duo: block_attrs rule for path '{$rule['path']}' needs 'kind' or 'kind_from'");
    }
}
