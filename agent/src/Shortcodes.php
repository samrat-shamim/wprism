<?php
namespace Duo;

/**
 * Structure-aware rewriting of shortcode attributes -- DUO-3259, the
 * shortcode twin of Blocks.php's block_attrs mechanism (see that class's
 * own docblock for the shared dangling-vs-unscoped triage this reuses via
 * Capture::classify_unscoped_ref(), the extracted core of the same
 * decision Blocks::queue_unscoped() makes inline).
 *
 * Unlike blocks (parse_blocks() hands this engine a clean, already-parsed
 * associative array of attributes per block), WordPress's own shortcode
 * regex -- get_shortcode_regex(), called here exactly as core's own
 * do_shortcode()/strip_shortcodes() call it, no reimplementation, no
 * vendoring, the real function, always available since wp-includes/
 * shortcodes.php is core -- only locates shortcode INSTANCES in raw text;
 * each instance's attribute string (match group 3) is still raw,
 * undelimited text. WordPress ships no canonical "serialize shortcode
 * attributes back to text" counterpart to serialize_block():
 * shortcode_parse_atts() only decodes, one direction, and its own
 * unicode-whitespace normalization + stripcslashes() + unclosed-HTML
 * rejection (confirmed by reading it directly) are all lossy transforms
 * this class must never apply to bytes it wasn't asked to touch. A full
 * parse-then-reserialize round trip therefore has no blessed target
 * format and risks silently reformatting attributes this class was never
 * asked to touch (quote style, ordering, spacing) -- this project's
 * "declared paths only, byte-preserve everything else" discipline
 * (block_attrs/json_refs already hold to it) rules that out.
 *
 * Instead: each DECLARED attribute gets its own narrowly-scoped regex,
 * matched directly against the raw attribute text, and only the exact
 * byte span of that one attribute's value is spliced -- every other byte
 * of the shortcode (self-close marker, enclosed content, undeclared
 * attributes, spacing, quoting style of untouched attributes) survives
 * untouched. The one place a byte-offset reconstruction IS needed is
 * splicing the rewritten attribute string back into the outer shortcode
 * match: get_shortcode_regex()'s own group layout guarantees
 * '[' . $m[1] . $m[2] is exactly the literal prefix preceding $m[3] (the
 * one intervening regex token, a tag-boundary lookahead, is zero-width)
 * -- verified against WordPress core's own get_shortcode_regex()/
 * do_shortcode_tag() source directly, not assumed from memory.
 *
 * shortcode_attrs rules (Policy::shortcode_attr_rules()) are a per-tag
 * LIST of {path, kind, cast?} -- path names a shortcode ATTRIBUTE (not a
 * JSON path; a shortcode's own attribute grammar is already a flat
 * key=value space, shortcode_parse_atts()'s own return shape), the same
 * shape family block_attrs uses. `cast: "csv"` marks a comma-joined id
 * list (WordPress's own convention for gallery's ids/include/exclude,
 * confirmed by reading gallery_shortcode() directly, not assumed).
 *
 * Grounded against WordPress 7.0.2's real wp-includes/media.php:
 * gallery_shortcode() has FOUR genuine reference-shaped attributes -- id
 * (defaults to the CURRENT post when absent, used as post_parent when
 * include/exclude are unset -- an implicit self-reference that needs no
 * rewriting at all, since it resolves correctly against the same post's
 * own regenerated id after apply for free), ids (CSV, aliased verbatim
 * into include when non-empty: `$attr['include'] = $attr['ids'];`),
 * include (CSV, fed to get_posts()), exclude (CSV, fed to get_children()).
 * img_caption_shortcode()'s `id` attribute, by contrast, is
 * sanitize_html_class()'d and emitted verbatim as a DOM id for CSS/JS
 * targeting -- never parsed back into a numeric attachment reference
 * anywhere in core. `caption` is therefore ruled explicitly unsupported
 * for a different reason than "hard to build": there is nothing to codec
 * at all, so it gets no shortcode_attrs entry (see manifests/core.json's
 * own note on this).
 */
final class Shortcodes {
    /**
     * @param string $postLabel human-readable identifying string for the
     *   post this content belongs to (e.g. "page 'about-us'"), named in
     *   any unscoped-ref violation queued during this call -- mirrors
     *   Blocks::capture_rewrite()'s own $postLabel exactly, for the same
     *   reason (this class only ever sees a content string, never the
     *   post row).
     */
    public static function capture_rewrite_text(
        string $content,
        Policy $policy,
        Tokens $tokens,
        bool $forceUnresolvedRefs = false,
        string $postLabel = ''
    ): string {
        $rules = $policy->shortcode_attr_rules();
        if ($rules === [] || !str_contains($content, '[')) {
            return $content;
        }
        $pattern = '/' . get_shortcode_regex(array_keys($rules)) . '/';
        $out = preg_replace_callback($pattern, function (array $m) use ($rules, $tokens, $policy, $forceUnresolvedRefs, $postLabel) {
            return self::rewrite_instance($m, $rules, $tokens, true, $policy, $forceUnresolvedRefs, $postLabel);
        }, $content);
        return $out ?? $content; // preg failure (e.g. backtrack limit): leave content untouched, never null it out
    }

    public static function apply_rewrite_text(string $content, Policy $policy, Tokens $tokens): string {
        $rules = $policy->shortcode_attr_rules();
        if ($rules === [] || !str_contains($content, '[')) {
            return $content;
        }
        $pattern = '/' . get_shortcode_regex(array_keys($rules)) . '/';
        $out = preg_replace_callback($pattern, function (array $m) use ($rules, $tokens, $policy) {
            return self::rewrite_instance($m, $rules, $tokens, false, $policy, false, '');
        }, $content);
        return $out ?? $content;
    }

    /**
     * One matched shortcode instance. $m follows get_shortcode_regex()'s
     * own 6-group contract (verified against core source): 1=optional
     * escape-open '[', 2=tag name, 3=raw attribute text, 4=self-close '/'
     * marker, 5=enclosed content, 6=optional escape-close ']'. Groups
     * 1/2/3/6 always participate (none sit inside an untried alternation
     * branch) so plain numeric access is safe with no PREG_UNMATCHED_AS_
     * NULL needed here (unlike rewrite_one_attr()'s inner match, where it
     * matters -- see that method's docblock).
     *
     * The escape check mirrors do_shortcode_tag() exactly: WordPress's
     * own [[tag]] convention for literal, non-executing shortcode text
     * must never be touched.
     */
    private static function rewrite_instance(
        array $m,
        array $rules,
        Tokens $tokens,
        bool $capture,
        Policy $policy,
        bool $forceUnresolvedRefs,
        string $postLabel
    ): string {
        if ($m[1] === '[' && $m[6] === ']') {
            return $m[0];
        }
        $tag = $m[2];
        $attrRules = $rules[$tag] ?? null;
        if ($attrRules === null) {
            return $m[0];
        }
        $rawAttrs = $m[3];
        $newAttrs = self::rewrite_attrs($rawAttrs, $attrRules, $tokens, $capture, $policy, $forceUnresolvedRefs, $postLabel, $tag);
        if ($newAttrs === $rawAttrs) {
            return $m[0];
        }
        $prefix = '[' . $m[1] . $tag;
        $suffix = substr($m[0], strlen($prefix) + strlen($rawAttrs));
        return $prefix . $newAttrs . $suffix;
    }

    /**
     * Rewrites every declared attribute independently within one
     * shortcode instance's raw attribute text. Each rule gets its own
     * preg_replace_callback pass (not one combined pass), so a declared
     * attribute absent from THIS instance is simply a no-op -- matching
     * Blocks.php's own `if (!isset($block['attrs'][$path])) continue;`
     * early-skip. A malformed shortcode with a DUPLICATE attribute (e.g.
     * `id="1" id="2"`) has every occurrence rewritten independently
     * rather than picking one canonical occurrence the way
     * shortcode_parse_atts()'s array-key overwrite would -- strictly
     * safer than leaving any occurrence raw.
     */
    private static function rewrite_attrs(
        string $rawAttrs,
        array $attrRules,
        Tokens $tokens,
        bool $capture,
        Policy $policy,
        bool $forceUnresolvedRefs,
        string $postLabel,
        string $tag
    ): string {
        foreach ($attrRules as $rule) {
            $attrName = $rule['path'];
            $namePattern = '/(?<ws>\s*)(?<![\w-])(?<name>' . preg_quote($attrName, '/') . ')(?![\w-])\s*=\s*'
                . '(?:"(?<dq>[^"]*)"|\'(?<sq>[^\']*)\'|(?<bare>[^\s\'"]+))/i';
            $rewritten = preg_replace_callback($namePattern, function (array $am) use (
                $rule, $tokens, $capture, $policy, $forceUnresolvedRefs, $postLabel, $tag
            ) {
                return self::rewrite_one_attr($am, $rule, $tokens, $capture, $policy, $forceUnresolvedRefs, $postLabel, $tag);
            }, $rawAttrs, -1, $count, PREG_UNMATCHED_AS_NULL);
            $rawAttrs = $rewritten ?? $rawAttrs;
        }
        return $rawAttrs;
    }

    /**
     * One matched attribute occurrence. $am's dq/sq/bare named groups sit
     * inside an untried alternation (only one style is actually present
     * in real text), so PLAIN `$am['dq'] ?? $am['sq'] ?? ...` is NOT
     * reliable: PHP/PCRE fills lower-numbered groups that sit BEFORE the
     * one that actually matched with '' (not absent) -- empirically
     * confirmed (not assumed) before writing this, e.g. an unquoted
     * `id=42` match still reports $am['dq'] === '', indistinguishable
     * from a genuinely-empty `id=""` under plain `??` chaining. The
     * caller passes PREG_UNMATCHED_AS_NULL specifically so non-
     * participating groups are NULL (not ''), making the `??` chain
     * below unambiguous.
     *
     * $am['name'] (not the rule's own declared path spelling) is used in
     * every reconstruction -- preserves the shortcode author's original
     * attribute-name casing (`ID="5"` stays `ID=`), matching by name
     * case-insensitively (this class's own name regex carries the `/i`
     * flag, mirroring shortcode_parse_atts()'s own strtolower()-before-
     * keying behavior) without forcing a case change on bytes this class
     * was never asked to touch.
     */
    private static function rewrite_one_attr(
        array $am,
        array $rule,
        Tokens $tokens,
        bool $capture,
        Policy $policy,
        bool $forceUnresolvedRefs,
        string $postLabel,
        string $tag
    ): string {
        $ws = $am['ws'];
        $name = $am['name'];
        $value = $am['dq'] ?? $am['sq'] ?? $am['bare'] ?? '';
        $kind = $rule['kind'];
        $isCsv = ($rule['cast'] ?? null) === 'csv';

        if ($capture) {
            if ($isCsv) {
                $kept = [];
                $parts = array_values(array_filter(array_map('trim', explode(',', $value)), fn($s) => $s !== ''));
                foreach ($parts as $i => $piece) {
                    $id = (int) $piece;
                    $tok = $tokens->id_to_token($id, $kind);
                    if ($tok === null) {
                        $tokens->warnings[] = "shortcode '$tag' attribute '$name" . "[$i]': unmapped $kind id $id dropped (dangling reference)";
                        self::queue_unscoped($tokens, $policy, $forceUnresolvedRefs, $postLabel, $tag, "$name" . "[$i]", $kind, $id);
                        continue;
                    }
                    $kept[] = $tok;
                }
                // Every element dropped: no meaningful value survives --
                // drop the whole attribute (+ its own leading whitespace),
                // same posture as the scalar drop below, rather than
                // leave a purposeless attr="" artifact.
                return $kept === [] ? '' : $ws . $name . '="' . implode(',', $kept) . '"';
            }
            $id = (int) $value;
            $tok = $tokens->id_to_token($id, $kind);
            if ($tok === null) {
                $tokens->warnings[] = "shortcode '$tag' attribute '$name': unmapped $kind id $id dropped (dangling reference)";
                self::queue_unscoped($tokens, $policy, $forceUnresolvedRefs, $postLabel, $tag, $name, $kind, $id);
                return ''; // drop the attribute AND its own leading whitespace together
            }
            return $ws . $name . '="' . $tok . '"';
        }

        // Apply direction: token -> id, mirroring Blocks.php's own
        // apply-direction handling exactly -- unresolvable throws
        // (Tokens::token_to_id()'s own contract), there is no soft
        // fallback for a ref that used to resolve at capture time.
        $toId = fn($v) => str_starts_with($v, '{{') ? $tokens->token_to_id($v) : (int) $v;
        if ($isCsv) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $value)), fn($s) => $s !== ''));
            $ids = array_map($toId, $parts);
            return $ws . $name . '="' . implode(',', $ids) . '"';
        }
        return $ws . $name . '="' . $toId($value) . '"';
    }

    /**
     * DUO-3259: task #73's own unscoped-vs-dangling triage, ported a
     * second time this session (Blocks::queue_unscoped() the first).
     * Unlike that method, this one does NOT re-derive the three-way
     * decision inline -- it calls the shared Capture::classify_unscoped_
     * ref() helper directly, the extraction DUO-3259 itself added
     * specifically so a third ref-carrying surface would not need a
     * third hand-copy of the same logic. See that method's own docblock
     * for the full three-reasons reasoning (dangling / not-yet-minted /
     * forced all no-op; only a real, out-of-scope row queues).
     */
    private static function queue_unscoped(
        Tokens $tokens,
        Policy $policy,
        bool $force,
        string $postLabel,
        string $tag,
        string $attr,
        string $kind,
        int $id
    ): void {
        $targetType = Capture::classify_unscoped_ref($id, $kind, $force, $policy);
        if ($targetType === null) {
            return;
        }
        $tokens->unscopedShortcodeRefs[] = [
            'post' => $postLabel,
            'shortcode' => $tag,
            'attr' => $attr,
            'kind' => $kind,
            'id' => $id,
            'target_type' => $targetType,
        ];
    }
}
