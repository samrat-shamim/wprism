<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/ReferenceScopeClassifier.php';
require_once __DIR__ . '/../Kernel/HtmlMediaReferences.php';
require_once __DIR__ . '/AttrIdCodecGrammar.php';
require_once __DIR__ . '/Shortcodes.php';
require_once __DIR__ . '/AuthoredValueCodec.php';
require_once __DIR__ . '/Tokens.php';
require_once __DIR__ . '/../Kernel/BlockContentGrammar.php';
require_once __DIR__ . '/../Kernel/BlockValueGrammar.php';

/**
 * Structure-aware content rewriting via the official block parser:
 * - block attributes per the manifest block_attrs registry (typed paths),
 * - reserved wp-image-<id> class tokens in saved HTML attributes,
 * - URL tokenization of inner content strings.
 * Classic (non-block) content shares the same HTML media and URL grammar. serialize_blocks() re-emission is the canonical form; it
 * is a fixed point after the first normalization, which the capture-twice
 * determinism test asserts.
 *
 * block_attrs rules come in four shapes. The first three may be freely mixed
 * per block name; a whole-block codec is exclusive because it owns the exact
 * attribute object rather than one independently rewritten leaf:
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
 * - a manifest-bound whole-block codec:
 *   {"codec": manifest-interpreter-name, "path": ...}. Every declared rule
 *   for that block must name the same codec, and its paths are the closed set
 *   the codec may return after capture/apply.
 *
 * A "kind"/"kind_from" ref's id_to_token() failing is either DANGLING (no
 * ledger row for that id at all — deleted target, or never existed) or
 * UNSCOPED (a real row exists, but its post_type/taxonomy was never added
 * to policy scope, so it was never minted a uuid) — task #73's own
 * distinction for options, ported here in full (issue #3212): dangling gets
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
    /**
     * Discover exact stored core/legacy-widget identities before SidebarState
     * capture. Authority is closed by the existing whole-block codec rule's
     * declared `id` path plus only widget types with that same effective
     * manifest source; merged foreign widget rules and interpreter methods
     * cannot opt themselves into this engine-owned pre-scan.
     *
     * @return list<array{type:string,local_id:int}>
     */
    public static function capture_widget_instance_references(string $content, Policy $policy): array {
        $details = $policy->block_attr_rule_details('core/legacy-widget');
        $legacyRules = $details['rule'] ?? [];
        $source = $details['source'] ?? null;
        if (!is_array($legacyRules) || !is_string($source) || $source === '') {
            return [];
        }
        $codec = null;
        $paths = [];
        foreach ($legacyRules as $rule) {
            if (!is_array($rule) || !is_string($rule['codec'] ?? null) || $rule['codec'] === '') {
                return [];
            }
            if ($codec !== null && !hash_equals($codec, $rule['codec'])) {
                throw new \RuntimeException(
                    "wprism: block 'core/legacy-widget' has an invalid or mixed whole-block codec registry"
                );
            }
            $codec = $rule['codec'];
            $paths[(string) ($rule['path'] ?? '')] = true;
        }
        if ($codec === null || !isset($paths['id']) || $content === '') {
            return [];
        }
        $widgetTypes = [];
        $foreignWidgetTypes = [];
        foreach ($policy->widget_types() as $type => $rule) {
            $widgetSource = $policy->widget_type_rule_details((string) $type)['source'] ?? null;
            if (is_string($widgetSource) && hash_equals($source, $widgetSource)) {
                $widgetTypes[(string) $type] = $rule;
            } else {
                $foreignWidgetTypes[(string) $type] = true;
            }
        }
        if ($widgetTypes === []) {
            return [];
        }
        $out = [];
        foreach (parse_blocks($content) as $block) {
            if (is_array($block)) {
                self::collect_widget_instance_keys($block, $widgetTypes, $foreignWidgetTypes, $out);
            }
        }
        ksort($out, SORT_STRING);
        return array_values($out);
    }

    /** @param array<string,array{type:string,local_id:int}> $out */
    private static function collect_widget_instance_keys(
        array $block,
        array $widgetTypes,
        array $foreignWidgetTypes,
        array &$out
    ): void {
        $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
        if ($name === 'core/legacy-widget') {
            self::collect_stored_legacy_widget_instance($block, $widgetTypes, $foreignWidgetTypes, $out);
        }
        foreach ((array) ($block['innerBlocks'] ?? []) as $inner) {
            if (is_array($inner)) {
                self::collect_widget_instance_keys($inner, $widgetTypes, $foreignWidgetTypes, $out);
            }
        }
    }

    /** @param array<string,array> $widgetTypes @param array<string,array{type:string,local_id:int}> $out */
    private static function collect_stored_legacy_widget_instance(
        array $block,
        array $widgetTypes,
        array $foreignWidgetTypes,
        array &$out
    ): void {
        if (!is_array($block['innerBlocks'] ?? null)
            || ($block['innerBlocks'] ?? []) !== []
            || !is_string($block['innerHTML'] ?? null)
            || trim((string) $block['innerHTML']) !== ''
            || !is_array($block['innerContent'] ?? null)
            || ($block['innerContent'] ?? []) !== []) {
            throw new \RuntimeException(
                'wprism: stored legacy widget reference must be one exact self-closing core block'
            );
        }
        $attrs = $block['attrs'] ?? null;
        if (!is_array($attrs) || ($attrs !== [] && array_is_list($attrs))) {
            throw new \RuntimeException('wprism: stored legacy widget reference attributes must be one closed object');
        }
        if (!array_key_exists('id', $attrs)) {
            return;
        }
        $keys = array_keys($attrs);
        sort($keys, SORT_STRING);
        if ($keys !== ['id'] || !is_string($attrs['id'])) {
            throw new \RuntimeException('wprism: stored legacy widget reference has an unknown or malformed field');
        }
        foreach (array_keys($foreignWidgetTypes) as $type) {
            if (self::widget_instance_id($attrs['id'], $type) !== null) {
                throw new \RuntimeException(
                    'wprism: stored legacy widget reference belongs to a different manifest owner'
                );
            }
        }
        $matches = [];
        foreach (array_keys($widgetTypes) as $type) {
            $localId = self::widget_instance_id($attrs['id'], $type);
            if ($localId !== null) {
                $matches[] = ['type' => $type, 'local_id' => $localId];
            }
        }
        if (count($matches) !== 1) {
            throw new \RuntimeException(
                'wprism: stored legacy widget reference does not bind one declared widget type and canonical instance'
            );
        }
        $reference = $matches[0];
        $out[$reference['type'] . '-' . $reference['local_id']] = $reference;
    }

    private static function widget_instance_id(string $id, string $type): ?int {
        $prefix = $type . '-';
        if (!str_starts_with($id, $prefix)) {
            return null;
        }
        $local = substr($id, strlen($prefix));
        if (preg_match('/^[1-9][0-9]*$/D', $local) !== 1
            || (string) (int) $local !== $local
            || (int) $local <= 0) {
            return null;
        }
        return (int) $local;
    }

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
        $rules = $policy->block_attr_rules();
        BlockValueGrammar::assert_closed_document($content, $rules);
        $blocks = parse_blocks($content);
        // WP-6.1: resolved once beside $rules, never per block, because the two
        // are one declaration about one block's attribute grammar (see
        // AttrIdCodecGrammar::rules()) and a per-block lookup would re-walk the
        // pin set for every block in a post body.
        $idCodecs = $policy->attr_id_codec_rules();
        $blocks = array_map(
            fn($b) => self::walk($b, $rules, $tokens, true, $policy, $forceUnresolvedRefs, $postLabel, $idCodecs),
            $blocks
        );
        // A body-wide pass preserves raw-text context across Gutenberg child
        // boundaries. Per-chunk parsing can mistake script text for markup.
        return HtmlMediaReferences::rewrite(serialize_blocks($blocks), static function (array $reference) use (
            $tokens, $policy, $forceUnresolvedRefs, $postLabel
        ): ?string {
            if (is_string($reference['reference'])) return $reference['suffix'];
            $id = $reference['reference'];
            $token = $tokens->id_to_token($id, 'post');
            if ($token !== null) return $token;
            $name = $reference['block'];
            $tokens->warnings[] = "block '$name' wp-image-$id class: unmapped post id $id dropped (dangling reference)";
            self::queue_unscoped($tokens, $policy, $forceUnresolvedRefs, $postLabel, $name, 'wp-image-class', 'post', $id);
            return null;
        });
    }

    public static function apply_rewrite(string $content, Policy $policy, Tokens $tokens): string {
        if ($content === '') {
            return '';
        }
        $rules = $policy->block_attr_rules();
        BlockValueGrammar::assert_closed_document($content, $rules);
        $blocks = parse_blocks($content);
        $idCodecs = $policy->attr_id_codec_rules();
        $blocks = array_map(
            fn($b) => self::walk($b, $rules, $tokens, false, $policy, false, '', $idCodecs),
            $blocks
        );
        return HtmlMediaReferences::rewrite(serialize_blocks($blocks), static function (array $reference) use ($tokens): string {
            if (!is_string($reference['reference']) || !$reference['literal']) {
                throw new \RuntimeException('wprism: HTML media class requires a canonical post token before apply');
            }
            return (string) $tokens->token_to_id($reference['reference']);
        });
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
        $name = $block['blockName'] ?? null;
        // Classic (non-block) content parses as a freeform block whose
        // `blockName` is NULL, and PHP 8.5 deprecates a null array offset —
        // both sides of the #561 merge fixed this independently (WP-6.1 here,
        // the whole-block codec change upstream). This copy keeps `$name`
        // itself null so every existing attribute warning reads
        // exactly as it did, and normalises only the LOOKUP key: no registry
        // can hold a rule under the empty string.
        // The `?? null` covers the key being ABSENT rather than null, which is
        // a distinct shape: `parse_blocks()` always emits the key, but walk()
        // is reached with hand-built nodes (#561's B0 dispatch probe feeds one
        // with no `blockName` at all) and a bare read raises "Undefined array
        // key" there — a warning, which offline_diagnostics_guard.sh rejects.
        $lookup = is_string($name) ? $name : '';
        $blockIdCodecs = $idCodecs[$lookup] ?? [];
        $declaredRules = $rules[$lookup] ?? [];
        BlockValueGrammar::assert_closed_attributes($lookup, (array) ($block['attrs'] ?? []), $declaredRules);
        // #561's whole-block codec: a block whose every rule names one codec
        // is captured/applied by that interpreter as a unit; mixing whole-block
        // and per-attribute rules refuses. A null-name freeform block has no
        // registered rules, so $codec stays null there by construction and the
        // messages below only ever interpolate a real block name.
        $codec = null;
        $codecPaths = [];
        foreach ($declaredRules as $rule) {
            if (!array_key_exists('codec', $rule)) {
                continue;
            }
            if (!is_string($rule['codec']) || $rule['codec'] === ''
                || ($codec !== null && !hash_equals($codec, $rule['codec']))) {
                throw new \RuntimeException("wprism: block '$name' has an invalid or mixed whole-block codec registry");
            }
            $codec = $rule['codec'];
            $codecPaths[(string) $rule['path']] = true;
        }
        if ($codec !== null) {
            if (count($codecPaths) !== count($declaredRules)) {
                throw new \RuntimeException(
                    "wprism: block '$name' mixes whole-block codec and per-attribute rules at runtime"
                );
            }
            $interpreter = $policy->interpreters()[$codec] ?? null;
            $contentRule = BlockContentGrammar::project($policy->manifests, $policy->site['policy'] ?? [])[$lookup] ?? null;
            $method = $contentRule === null
                ? ($capture ? 'capture_block_attributes' : 'apply_block_attributes')
                : ($capture ? 'capture_block_content' : 'apply_block_content');
            if (!is_object($interpreter) || !method_exists($interpreter, $method)) {
                throw new \RuntimeException(
                    "wprism: block '$name' codec '$codec' must implement $method(array, Tokens): array"
                );
            }
            if ($contentRule !== null && (($block['innerBlocks'] ?? null) !== []
                || !is_string($block['innerHTML'] ?? null)
                || ($block['innerContent'] ?? null) !== [$block['innerHTML']]
                || strlen($block['innerHTML']) > 1048576)) {
                throw new \RuntimeException('wprism: block content codec requires one bounded leaf HTML fragment');
            }
            $rewritten = $capture
                ? $interpreter->$method($block, $tokens, $forceUnresolvedRefs, $postLabel)
                : ($contentRule === null ? $interpreter->$method($block, $tokens)
                    : $interpreter->$method($block, $tokens, $tokens->block_environment_options($contentRule['env_options'])));
            if ($contentRule !== null) {
                if (!is_array($rewritten) || count($rewritten) !== 2 || array_diff(array_keys($rewritten), ['attrs', 'html'])
                    || !is_string($rewritten['html'] ?? null) || strlen($rewritten['html']) > 1048576
                    || preg_match('~<!--\s+/?wp:~', $rewritten['html']) !== 0) {
                    throw new \RuntimeException('wprism: block content codec returned a malformed leaf result');
                }
                $block['innerHTML'] = $rewritten['html'];
                $block['innerContent'] = [$rewritten['html']];
                $rewritten = $rewritten['attrs'] ?? null;
            }
            if (!is_array($rewritten) || ($rewritten !== [] && array_is_list($rewritten))) {
                throw new \RuntimeException(
                    "wprism: block '$name' codec '$codec' returned a malformed attribute object"
                );
            }
            foreach (array_keys($rewritten) as $path) {
                if (!is_string($path) || !isset($codecPaths[$path])) {
                    throw new \RuntimeException(
                        "wprism: block '$name' codec '$codec' returned an undeclared attribute"
                    );
                }
            }
            $block['attrs'] = $rewritten;
            // A content codec owns the complete leaf representation. Re-running
            // shortcode/URL rewriting on its output could change native saver
            // bytes or interpret a credential as authored text.
            if ($contentRule !== null) return $block;
        }
        foreach ($codec === null ? $declaredRules : [] as $rule) {
            $path = $rule['path'];
            if (isset($rule['value']) && array_key_exists($path, (array) ($block['attrs'] ?? []))) {
                $valueRule = $rule['value'];
                if ($capture && ($valueRule['class'] ?? '') === 'derived') {
                    unset($block['attrs'][$path]);
                    continue;
                }
                $where = "block '$name' attribute '$path'";
                $block['attrs'][$path] = $capture
                    ? AuthoredValueCodec::capture($block['attrs'][$path], $valueRule, $tokens,
                        static function (int $id, string $kind) use ($tokens, $policy, $forceUnresolvedRefs, $postLabel, $name, $path): void {
                            self::queue_unscoped($tokens, $policy, $forceUnresolvedRefs, $postLabel, $name, $path, $kind, $id);
                        }, $where)
                    : AuthoredValueCodec::apply($block['attrs'][$path], $valueRule, $tokens, $where);
                continue;
            }
            // Unsupported means presence, including JSON null. Unlike an
            // unset reference, it has no declared native interpretation.
            if (array_key_exists('unsupported', $rule) && array_key_exists($path, (array) ($block['attrs'] ?? []))) {
                throw new \RuntimeException(
                    "wprism: block '$name' attribute '$path' is explicitly unsupported: " . $rule['unsupported']
                );
            }
            if (!isset($block['attrs'][$path])) {
                continue;
            }
            $v = $block['attrs'][$path];

            if (!empty($rule['lint_ok'])) {
                // declared non-ref attribute (e.g. queryId — a query instance
                // index, not an entity id): exempts it from `wp wprism lint`'s
                // *Id-name heuristic, and there is nothing to rewrite here
                continue;
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
                // already happened. issue #3212: the unmapped id is ALSO
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
                            // users never participate in wprism_map scope triage.
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
                        // users never participate in wprism_map scope triage.
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

        $rewriteString = function (?string $s) use (
            $tokens, $capture, $policy, $forceUnresolvedRefs, $postLabel
        ): ?string {
            if ($s === null || $s === '') {
                return $s;
            }
            // issue #3259: shortcode-attribute ref rewriting, threaded
            // through the same per-chunk closure URL
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
                // issue #3260: $postLabel already in scope for Shortcodes'
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
        // issue #3212: deliberately NOT also rewriting $block['innerHTML'] here
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
        // but once structural rewrites gained side effects (a warning +
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
     * issue #3212 (task #73's own unscoped-vs-dangling triage, ported): queues
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
        throw new \RuntimeException("wprism: block_attrs rule for path '{$rule['path']}' needs 'kind' or 'kind_from'");
    }
}
