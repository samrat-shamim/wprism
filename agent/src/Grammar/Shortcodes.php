<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/ReferenceScopeClassifier.php';

/**
 * Structure-aware rewriting of shortcode attributes -- DUO-3259, the
 * shortcode twin of Blocks.php's block_attrs mechanism (see that class's
 * own docblock for the shared dangling-vs-unscoped triage this reuses via
 * ReferenceScopeClassifier, the extracted core of the same
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
        if ($out === null) {
            throw new \RuntimeException('duo: shortcode rewrite regex failed; refusing unproven content');
        }
        return $out;
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
        if ($out === null) {
            throw new \RuntimeException('duo: shortcode rewrite regex failed; refusing unproven content');
        }
        return $out;
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
        $hasPositional = false;
        foreach ($attrRules as $rule) {
            if (array_key_exists('position', $rule)) {
                $hasPositional = true;
                break;
            }
        }
        if ($hasPositional) {
            self::assert_positional_attribute_shape($rawAttrs, $tag);
        }
        foreach ($attrRules as $rule) {
            if (array_key_exists('position', $rule)) {
                $rawAttrs = self::rewrite_positional(
                    $rawAttrs,
                    $rule,
                    $tokens,
                    $capture,
                    $postLabel,
                    $tag
                );
                continue;
            }
            $attrName = $rule['path'];
            $namePattern = '/(?<ws>\s*)(?<![\w-])(?<name>' . preg_quote($attrName, '/') . ')(?![\w-])\s*=\s*'
                . '(?:"(?<dq>[^"]*)"|\'(?<sq>[^\']*)\'|(?<bare>[^\s\'"]+))/i';
            $rewritten = preg_replace_callback($namePattern, function (array $am) use (
                $rule, $tokens, $capture, $policy, $forceUnresolvedRefs, $postLabel, $tag
            ) {
                return self::rewrite_one_attr($am, $rule, $tokens, $capture, $policy, $forceUnresolvedRefs, $postLabel, $tag);
            }, $rawAttrs, -1, $count, PREG_UNMATCHED_AS_NULL);
            if ($rewritten === null) {
                throw new \RuntimeException("duo: shortcode '$tag' attribute rewrite regex failed; refusing unproven content");
            }
            $rawAttrs = $rewritten;
        }
        return $rawAttrs;
    }

    /**
     * A positional rule models a callback which consumes the first parsed
     * attribute value (CF7's legacy callback uses array_shift($atts)).  A
     * named attribute would therefore change which value the callback sees,
     * even when a later bare span happens to be present.  Refuse the whole
     * instance rather than silently rewriting a different argument.
     */
    private static function assert_positional_attribute_shape(string $rawAttrs, string $tag): void {
        $pattern = get_shortcode_atts_regex();
        $matched = preg_match_all($pattern, $rawAttrs, $matches, PREG_SET_ORDER);
        if ($matched === false) {
            throw new \RuntimeException("duo: shortcode '$tag' attribute regex failed; refusing unproven positional content");
        }
        if ($matched === 0) {
            return;
        }
        foreach ($matches as $match) {
            foreach ([1, 3, 5] as $group) {
                if (isset($match[$group]) && $match[$group] !== '') {
                    throw new \RuntimeException(
                        "duo: shortcode '$tag' positional refs refuse named attributes; the callback consumes "
                        . 'the first parsed value and a named attribute would change its argument'
                    );
                }
            }
        }
    }

    /**
     * Rewrite one declared positional token.  The locator deliberately
     * resolves through an authored post-meta key rather than treating the
     * positional integer as a post primary key: CF7's legacy shortcode uses
     * `_old_cf7_unit_id`, which is stable content identity but not wp_posts.ID.
     * The canonical representation is still the ordinary post UUID token;
     * apply resolves that token back to the target form's alternate value.
     */
    private static function rewrite_positional(
        string $rawAttrs,
        array $rule,
        Tokens $tokens,
        bool $capture,
        string $postLabel,
        string $tag
    ): string {
        $position = (int) $rule['position'];
        $parts = self::positional_spans($rawAttrs);
        if (!isset($parts[$position])) {
            return $rawAttrs;
        }
        [$wire, $offset] = $parts[$position];
        $quote = '';
        $value = $wire;
        if (strlen($wire) >= 2 && (($wire[0] === '"' && $wire[strlen($wire) - 1] === '"')
            || ($wire[0] === "'" && $wire[strlen($wire) - 1] === "'"))) {
            $quote = $wire[0];
            $value = substr($wire, 1, -1);
        }
        $lookup = (string) $rule['lookup']['post_meta'];
        $postType = (string) $rule['lookup']['post_type'];
        if ($capture) {
            if (!self::is_positive_decimal_alternate($value)) {
                throw new \RuntimeException(
                    "duo: shortcode '$tag' positional[$position] must be a positive decimal alternate id"
                );
            }
            $postId = self::alternate_post_id($lookup, $postType, $value, $tag, $position);
            $token = $tokens->id_to_token($postId, (string) $rule['kind']);
            if ($token === null) {
                throw new \RuntimeException(
                    "duo: shortcode '$tag' positional[$position] alternate id '$value' resolves to unmanaged post $postId"
                );
            }
            $replacement = $quote . $token . $quote;
        } else {
            if (!str_starts_with($value, '{{')) {
                throw new \RuntimeException(
                    "duo: shortcode '$tag' positional[$position] retained raw alternate id '$value' at apply"
                );
            }
            $postId = $tokens->token_to_id($value);
            self::assert_post_type($postId, $postType, $tag, $position);
            $alternate = $tokens->shortcode_alternate($value, $lookup, $postType);
            if ($alternate === null && $tokens->shortcode_alternates_sealed()) {
                throw new \RuntimeException(
                    "duo: shortcode '$tag' positional[$position] token has no canonical alternate witness '$lookup' for post $postId"
                );
            }
            $alternate ??= self::alternate_post_meta($postId, $lookup, $postType, $tag, $position);
            self::assert_alternate_unique_on_target($postId, $lookup, $postType, $alternate, $tag, $position);
            $replacement = $quote . $alternate . $quote;
        }
        return substr_replace($rawAttrs, $replacement, (int) $offset, strlen($wire));
    }

    /** @return list<array{0:string,1:int}> */
    public static function positional_spans(string $rawAttrs): array {
        $out = [];
        $pattern = get_shortcode_atts_regex();
        $matched = preg_match_all($pattern, $rawAttrs, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($matched === false) {
            throw new \RuntimeException('duo: shortcode positional regex failed; refusing unproven content');
        }
        if ($matched === 0) {
            return $out;
        }
        foreach ($matches as $m) {
            // get_shortcode_atts_regex()'s groups 1/3/5 are named attrs;
            // groups 7/8/9 are the three bare positional spellings. Its
            // required whitespace/end delimiter is important: without it,
            // malformed `foo="bar"77` would be split into a named attr plus
            // a positional id and silently change CF7's own parse semantics.
            foreach ([7, 8, 9] as $group) {
                if (isset($m[$group]) && (int) $m[$group][1] >= 0 && $m[$group][0] !== '') {
                    $out[] = [(string) $m[$group][0], (int) $m[$group][1]];
                    break;
                }
            }
        }
        return $out;
    }

    private static function alternate_post_id(string $metaKey, string $postType, string $alternate, string $tag, int $position): int {
        global $wpdb;
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $status = self::runtime_status_filter('p');
        $sql = "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id "
            . "WHERE pm.meta_key = %s AND CAST(pm.meta_value AS DECIMAL) = %s "
            . "AND p.post_type = %s" . $status['sql'] . " ORDER BY pm.meta_id ASC";
        $args = [$metaKey, $alternate, $postType, ...$status['args']];
        $rows = $wpdb->get_col($wpdb->prepare($sql, $args));
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("duo: shortcode '$tag' positional[$position] alternate lookup failed; refusing an unproven identity");
        }
        $ids = array_values(array_unique(array_map('intval', $rows)));
        if (count($rows) !== 1 || count($ids) !== 1) {
            $why = $ids === [] ? 'no matching form' : 'multiple matching forms or duplicate alternate metadata';
            throw new \RuntimeException(
                "duo: shortcode '$tag' positional[$position] alternate id '$alternate' has $why via post_meta '$metaKey'"
            );
        }
        return $ids[0];
    }

    private static function alternate_post_meta(int $postId, string $metaKey, string $postType, string $tag, int $position): string {
        global $wpdb;
        self::assert_post_type($postId, $postType, $tag, $position);
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
            $postId,
            $metaKey
        )) ?: [];
        $values = array_values(array_unique(array_map('strval', $rows)));
        if (count($values) !== 1 || !self::is_positive_decimal_alternate($values[0] ?? '')) {
            throw new \RuntimeException(
                "duo: shortcode '$tag' positional[$position] target post $postId has no unique positive decimal post_meta '$metaKey'"
            );
        }
        return $values[0];
    }

    private static function assert_alternate_unique_on_target(
        int $postId,
        string $metaKey,
        string $postType,
        string $alternate,
        string $tag,
        int $position
    ): void {
        global $wpdb;
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $status = self::runtime_status_filter('p');
        $sql = "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id "
            . "WHERE pm.meta_key = %s AND CAST(pm.meta_value AS DECIMAL) = %s "
            . "AND p.post_type = %s" . $status['sql'] . " ORDER BY pm.meta_id ASC";
        $args = [$metaKey, $alternate, $postType, ...$status['args']];
        $rows = $wpdb->get_col($wpdb->prepare($sql, $args));
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("duo: shortcode '$tag' positional[$position] target collision check failed; refusing an unproven identity");
        }
        $rawIds = array_map('intval', $rows);
        $ids = array_values(array_unique($rawIds));
        $selectedCount = count(array_filter($rawIds, static fn(int $id): bool => $id === $postId));
        if ($selectedCount > 1) {
            throw new \RuntimeException(
                "duo: shortcode '$tag' positional[$position] target post $postId has duplicate '$metaKey' metadata rows"
            );
        }
        foreach ($ids as $id) {
            if ($id !== $postId) {
                throw new \RuntimeException(
                    "duo: shortcode '$tag' positional[$position] alternate '$alternate' is already owned by "
                    . "another $postType row ($id) in post_meta '$metaKey'"
                );
            }
        }
    }

    private static function assert_post_type(int $postId, string $postType, string $tag, int $position): void {
        global $wpdb;
        $actualType = $wpdb->get_var($wpdb->prepare(
            "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d",
            $postId
        ));
        if ((string) $actualType !== $postType) {
            throw new \RuntimeException(
                "duo: shortcode '$tag' positional[$position] token resolves to post $postId outside post_type '$postType'"
            );
        }
    }

    private static function is_positive_decimal_alternate(string $value): bool {
        if (!preg_match('/^[1-9][0-9]*$/D', $value)) {
            return false;
        }
        // CF7's WP_Meta_Query emits CAST(meta_value AS DECIMAL), whose
        // engine-defined bare DECIMAL domain is ten decimal digits.  The
        // portable alternate must fit both that lookup and this PHP build.
        $max = PHP_INT_SIZE >= 8 ? '9999999999' : (string) PHP_INT_MAX;
        $length = strlen($value);
        return $length < strlen($max) || ($length === strlen($max) && strcmp($value, $max) <= 0);
    }

    /** Match WP_Query's post_status=any expansion used by CF7::find(). */
    private static function runtime_status_filter(string $alias): array {
        if (!function_exists('get_post_stati')) {
            return ['sql' => '', 'args' => []];
        }
        $excluded = array_values(array_filter(
            get_post_stati(['exclude_from_search' => true]),
            static fn($status): bool => is_string($status) && $status !== ''
        ));
        if ($excluded === []) {
            return ['sql' => '', 'args' => []];
        }
        return [
            'sql' => ' AND ' . $alias . '.post_status NOT IN (' . implode(',', array_fill(0, count($excluded), '%s')) . ')',
            'args' => $excluded,
        ];
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
     * decision inline -- it calls the shared ReferenceScopeClassifier
     * directly, the extraction DUO-3259 itself added
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
        $targetType = ReferenceScopeClassifier::classify($id, $kind, $force, $policy);
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
