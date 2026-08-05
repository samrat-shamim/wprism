<?php
namespace Duo;

/**
 * The generalized suspicious-ref linter (task #11's linter half; docs/
 * frontier/{fse,polylang,elementor}.md — each independently proved that
 * byte-identical round-tripping reports "clean" on real corruption the
 * moment a ref-shaped value reaches canonical state without ever passing
 * through a declared rewrite path: both sides just encode the same wrong
 * bytes, and the diff comes back empty). Pending::ref_hint() was this
 * check's seed — one key, one current live value. Lint::scan_tree()
 * generalizes it to a whole captured state tree, across four surfaces the
 * three frontier explorations independently found broken.
 *
 * scan_tree() reads a CAPTURED state tree from disk — the canonical files
 * are the honest input, because anything id-shaped or env-URL-shaped that
 * SURVIVED capture is, by construction, something no declared rewrite path
 * touched (a value that WAS tokenized would already read as "{{post:...}}"
 * or "{{home}}", not a raw id or a raw host). Numeric survivors are
 * cross-referenced against THIS environment's live wp_posts/wp_terms via
 * Pending::resolve_id() (the one shared lookup — not re-implemented here).
 *
 * This is a plan-time / audit-time SIGNAL, not proof of corruption: small
 * ids legitimately coincide with unrelated authored numbers (counts,
 * versions, ordering indexes). Every finding says so in its own "note" —
 * the caveat travels with the finding, never left implicit.
 *
 * Four detection classes (wave 1 — sub-key option refs and id-keyed arrays
 * are task #11's wave 2, deliberately not attempted here):
 *
 *   bare_id — a numeric scalar / array element / CSV segment inside an
 *     authored post_meta or option value whose classification rule has NO
 *     ref declared, where the number matches an existing post or term id
 *     on this environment. A declared ref means Tokens already owns that
 *     value (tokenized, or dropped-with-warning if dangling — task #21);
 *     this class exists entirely for the *undeclared* case finding #9 and
 *     the FSE/Polylang reports kept independently rediscovering.
 *
 *   escaped_home — this environment's home URL in JSON-escaped form
 *     (`https:\/\/…`), anywhere in a post body or in a post_meta/option
 *     value (recursively — an opaque JSON blob living in one meta string,
 *     Elementor's shape, is itself a single string leaf, found without
 *     understanding its internal schema). Tokens::tokenize_text() does a
 *     literal, unescaped str_replace() — it structurally cannot match the
 *     escaped form, so this never gets rewritten on apply.
 *
 *   unregistered_block_attr — walks each post's parsed blocks (skipping
 *     verbatim-body post types, which aren't block content at all). Flags
 *     (a) attrs named id/ids/ref, or ending in Id/Ids, holding a numeric
 *     value, on a block with NO block_attrs registry rule for that exact
 *     path (FSE's core/navigation-link shape — fires regardless of whether
 *     the number currently resolves to anything, because the danger is the
 *     missing rule itself, not today's coincidence); and (b) any STRING
 *     attr containing this environment's home URL in plain form, on ANY
 *     block, rule or no rule — block attrs are parsed JSON values, never
 *     routed through tokenize_text()/detokenize_text() today (only
 *     innerHTML/innerContent are), so nothing declared for a path changes
 *     that.
 *
 *   serialized_desc_ids — a term's `description` that unserializes (PHP
 *     serialize format) to data containing an integer matching an existing
 *     post or term id (Polylang's post_translations/term_translations
 *     shape: `a:2:{s:2:"en";i:1;s:2:"fr";i:2;}`). Nothing in this engine
 *     ever rewrites term descriptions — Capture treats them as opaque
 *     tokenize_text()'d strings unconditionally — so this fires whenever
 *     the data parses and an element resolves, no "declared rule" gate
 *     needed (there is no such rule to check).
 *
 * Finding shape (every class): {class, path, locator, value, matches?,
 * note}. `path` is state-relative (e.g. "posts/post/<uuid>--slug.md").
 * `locator` is a JSON-ish pointer into that file (e.g. "meta.duo_related
 * [1]", "options.sticky_posts[0]", "blocks.core/image.attrs.id",
 * "description[fr]"). `matches`, when present, is {kind, id, title,
 * post_type} — the same shape Pending::resolve_id() returns, reused
 * byte-for-byte rather than reinvented. `note` always carries the
 * class-specific honest caveat (never assume — small ids coincide).
 */
final class Lint {
    /** Findings' `value` is truncated past this length for readability
     *  (opaque JSON-blob meta values, e.g. Elementor's, can be huge). */
    private const MAX_VALUE_LEN = 200;

    /** @return array<int, array{class:string, path:string, locator:string, value:mixed, matches?:array{kind:string,id:int,title:string,post_type:string}, note:string}> */
    public static function scan_tree(string $stateDir, Policy $policy): array {
        $stateDir = rtrim($stateDir, '/');
        if (!is_dir($stateDir)) {
            throw new \RuntimeException("duo: state dir not found: $stateDir (nothing captured yet?)");
        }
        $home = untrailingslashit((string) get_option('home'));
        $homeEscaped = str_replace('/', '\/', $home);
        $blockRules = $policy->block_attr_rules();

        $findings = [];
        foreach (self::glob_rel($stateDir, 'posts/*/*.md') as $rel) {
            self::scan_post_file($stateDir, $rel, $policy, $blockRules, $home, $homeEscaped, $findings);
        }
        foreach (self::glob_rel($stateDir, 'terms/*/*.json') as $rel) {
            self::scan_term_file($stateDir, $rel, $home, $homeEscaped, $findings);
        }
        if (is_file($stateDir . '/options/core.json')) {
            self::scan_options_file($stateDir, 'options/core.json', $policy, $home, $homeEscaped, $findings);
        }
        return $findings;
    }

    // ------------------------------------------------------------ posts

    private static function scan_post_file(
        string $stateDir, string $rel, Policy $policy, array $blockRules,
        string $home, string $homeEscaped, array &$findings
    ): void {
        [$front, $body] = Canon::parse_post_file(Canon::read_file($stateDir . '/' . $rel));
        $postType = (string) ($front['type'] ?? '');
        $meta = (array) ($front['meta'] ?? []);

        // (a) bare_id — shallow scan of authored, no-ref-declared meta.
        foreach ($meta as $key => $value) {
            $rule = $policy->meta_rule_for_post((string) $key, $meta);
            if (isset($rule['ref'])) {
                continue; // a declared ref path already owns this value (tokenized, or dropped if dangling)
            }
            if (!empty($rule['lint_ok'])) {
                continue; // human-reviewed declaration: numeric but genuinely not a ref
            }
            foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                $hit = Pending::resolve_id($id);
                if ($hit === null) {
                    continue;
                }
                $findings[] = self::finding('bare_id', $rel, 'meta.' . $key . $locSuffix, $id, $hit, self::bare_id_note($hit));
            }
        }

        // (b) escaped_home — recursively through meta values, and the raw body as one unit.
        self::walk_strings($meta, 'meta', function (string $path, string $s) use (&$findings, $rel, $home, $homeEscaped) {
            self::flag_escaped_home($findings, $rel, $path, $s, $home, $homeEscaped);
        });
        self::flag_escaped_home($findings, $rel, 'body', $body, $home, $homeEscaped);

        // (c) unregistered_block_attr — block content only (verbatim bodies, e.g. acf-field, aren't blocks at all).
        if ($body !== '' && $policy->body_mode($postType) !== 'verbatim') {
            self::scan_blocks(parse_blocks($body), $blockRules, $rel, $home, $findings);
        }
    }

    private static function bare_id_note(array $hit): string {
        return "no ref is declared for this key; the number coincides with an existing {$hit['kind']} id "
            . "(#{$hit['id']} \"{$hit['title']}\", {$hit['post_type']}) on this environment — could be a genuine "
            . "unrewritten reference, or an unrelated small number (a count, a version, an ordering index...). "
            . "Small ids coincide; this is a signal to investigate, not proof.";
    }

    // ------------------------------------------------------------ blocks

    private static function scan_blocks(array $blocks, array $blockRules, string $rel, string $home, array &$findings): void {
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            if ($name !== null) {
                $rulePaths = array_column($blockRules[$name] ?? [], 'path');
                foreach ((array) ($block['attrs'] ?? []) as $attrKey => $attrVal) {
                    $attrKey = (string) $attrKey;
                    if (!in_array($attrKey, $rulePaths, true) && self::looks_like_id_attr($attrKey)) {
                        foreach (Pending::numeric_candidates($attrVal) as [$id, $locSuffix]) {
                            $hit = Pending::resolve_id($id);
                            $findings[] = self::finding(
                                'unregistered_block_attr', $rel,
                                'blocks.' . $name . '.attrs.' . $attrKey . $locSuffix, $id, $hit,
                                "block '$name' has no block_attrs registry rule for attribute '$attrKey'; this "
                                . "numeric value passes through capture/apply untouched and will point at the "
                                . "wrong entity (or nothing) once ids diverge on another environment — the same "
                                . "shape as core/navigation-link's id/kind pair before it had a registry rule."
                            );
                        }
                    }
                    if (is_string($attrVal) && $attrVal !== '' && str_contains($attrVal, $home)) {
                        $findings[] = self::finding(
                            'unregistered_block_attr', $rel,
                            'blocks.' . $name . '.attrs.' . $attrKey, self::truncate($attrVal), null,
                            "block '$name' attribute '$attrKey' contains this environment's home URL in plain "
                            . "form; block attributes are parsed JSON values, never routed through "
                            . "tokenize_text()/detokenize_text() (only innerHTML/innerContent are today), so it "
                            . "will leak this environment's host into the target regardless of any registry rule."
                        );
                    }
                }
            }
            if (!empty($block['innerBlocks'])) {
                self::scan_blocks($block['innerBlocks'], $blockRules, $rel, $home, $findings);
            }
        }
    }

    /** id / ids / ref, or camelCase-suffixed *Id / *Ids (mediaId, termIds, ...). */
    private static function looks_like_id_attr(string $key): bool {
        return $key === 'id' || $key === 'ids' || $key === 'ref' || (bool) preg_match('/(Id|Ids)$/', $key);
    }

    // ------------------------------------------------------------ terms

    private static function scan_term_file(string $stateDir, string $rel, string $home, string $homeEscaped, array &$findings): void {
        $front = Canon::decode(Canon::read_file($stateDir . '/' . $rel));
        $desc = $front['description'] ?? '';
        if (!is_string($desc) || $desc === '') {
            return;
        }

        // (b) escaped_home
        self::flag_escaped_home($findings, $rel, 'description', $desc, $home, $homeEscaped);

        // (d) serialized_desc_ids — PHP-serialized data (Polylang's post_translations/term_translations shape).
        $data = @unserialize($desc, ['allowed_classes' => false]);
        if ($data === false && $desc !== 'b:0;') {
            return; // does not parse as serialized PHP data at all
        }
        $items = is_array($data) ? $data : [$data];
        foreach ($items as $k => $v) {
            if (!is_numeric($v) || str_contains((string) $v, '.')) {
                continue;
            }
            $id = (int) $v;
            $hit = Pending::resolve_id($id);
            if ($hit === null) {
                continue;
            }
            $locator = is_array($data) ? ('description[' . $k . ']') : 'description';
            $findings[] = self::finding('serialized_desc_ids', $rel, $locator, $id, $hit, sprintf(
                "this term's description unserializes to PHP data containing an integer that matches an "
                . "existing %s id (#%d \"%s\", %s); nothing in this engine rewrites term descriptions (Capture "
                . "tokenize_text()'s them as opaque strings), so this id is silently environment-bound — "
                . "Polylang's post_translations/term_translations shape.",
                $hit['kind'], $hit['id'], $hit['title'], $hit['post_type']
            ));
        }
    }

    // ------------------------------------------------------------ options

    private static function scan_options_file(string $stateDir, string $rel, Policy $policy, string $home, string $homeEscaped, array &$findings): void {
        $options = (array) Canon::decode(Canon::read_file($stateDir . '/' . $rel));

        // (a) bare_id
        foreach ($options as $key => $value) {
            $rule = $policy->option_rule((string) $key);
            if (isset($rule['ref'])) {
                continue;
            }
            if (!empty($rule['lint_ok'])) {
                continue; // human-reviewed declaration: numeric but genuinely not a ref
            }
            foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                $hit = Pending::resolve_id($id);
                if ($hit === null) {
                    continue;
                }
                $findings[] = self::finding('bare_id', $rel, 'options.' . $key . $locSuffix, $id, $hit, self::bare_id_note($hit));
            }
        }

        // (b) escaped_home
        self::walk_strings($options, 'options', function (string $path, string $s) use (&$findings, $rel, $home, $homeEscaped) {
            self::flag_escaped_home($findings, $rel, $path, $s, $home, $homeEscaped);
        });
    }

    // ------------------------------------------------------------ shared

    private static function flag_escaped_home(array &$findings, string $rel, string $locator, string $s, string $home, string $homeEscaped): void {
        if ($s === '' || !str_contains($s, $homeEscaped)) {
            return;
        }
        $findings[] = self::finding('escaped_home', $rel, $locator, self::truncate($s), null, sprintf(
            "this environment's home URL (%s) appears in JSON-escaped form (\\/ instead of /); "
            . "Tokens::tokenize_text() only matches the plain, unescaped form (a literal str_replace()), so this "
            . "will NOT be rewritten on apply and will leak this environment's host into the target — "
            . "Elementor's _elementor_data corruption shape.",
            $home
        ));
    }

    /**
     * Recursively visits every string leaf in an array/scalar, building a
     * dotted/bracketed "JSON-ish" locator path as it goes (object keys:
     * ".key", list indexes: "[i]"). Unlike bare_id's deliberately-shallow
     * numeric_candidates(), escaped_home needs to reach into nested
     * structure: a JSON blob opaque to Duo (Elementor's shape) is itself
     * one string leaf, found without parsing its internal schema; a plain
     * nested array of strings is walked correctly too.
     */
    private static function walk_strings($value, string $path, callable $visit): void {
        if (is_string($value)) {
            $visit($path, $value);
            return;
        }
        if (is_array($value)) {
            $isList = array_is_list($value);
            foreach ($value as $k => $v) {
                self::walk_strings($v, $isList ? ($path . '[' . $k . ']') : ($path . '.' . $k), $visit);
            }
        }
    }

    private static function finding(string $class, string $path, string $locator, $value, ?array $matches, string $note): array {
        $f = ['class' => $class, 'path' => $path, 'locator' => $locator, 'value' => $value, 'note' => $note];
        if ($matches !== null) {
            $f['matches'] = $matches;
        }
        return $f;
    }

    private static function glob_rel(string $stateDir, string $pattern): array {
        $matches = glob($stateDir . '/' . $pattern) ?: [];
        sort($matches, SORT_STRING);
        return array_map(fn($p) => substr($p, strlen($stateDir) + 1), $matches);
    }

    private static function truncate($s): string {
        $s = (string) $s;
        return strlen($s) > self::MAX_VALUE_LEN ? substr($s, 0, self::MAX_VALUE_LEN) . '…(truncated)' : $s;
    }
}
