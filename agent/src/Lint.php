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
 * generalizes it to a whole captured state tree, across every canonical surface the
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
 * Eight detection classes (five from wave 1, plus DUO-3259's shortcode pair
 * and DUO-3260's url-query-ref class below — sub-key option refs and
 * id-keyed arrays are task #11's wave 2, deliberately not attempted here):
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
 *   unrewritten_registered_ref — the mirror image of unregistered_block_attr
 *     above: a path IS declared as a ref (block_attrs, json_refs, or a
 *     schema-owned menu ref) but its captured value is still numeric instead
 *     of a "{{...}}" token. Fires regardless of whether the number currently
 *     resolves to a live entity: the danger is the declared rewrite not
 *     having run, not today's coincidence. Structured json_refs paths are
 *     resolved with JsonRefs itself, so ref-bearing leaf keys need not look
 *     id-shaped (Polylang's language-slug-keyed nav_menus shape). Before this
 *     class existed, registered block paths were exempted unconditionally;
 *     before DUO-3241, structured paths still depended on the fallback key-
 *     name heuristic. Both made a declared ref whose rewrite failed invisible
 *     to lint — exactly the "declared ref, failed rewrite" gap byte-identical
 *     round-tripping cannot catch.
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
 *   unregistered_shortcode_attr / unrewritten_registered_shortcode_ref
 *     (DUO-3259) — the shortcode-attribute twins of unregistered_block_attr
 *     / unrewritten_registered_ref above, same two-way split (no rule at
 *     all for an id-shaped attribute name, vs. a rule exists but the value
 *     is still numeric — the declared rewrite never ran), scoped to
 *     shortcode tags this engine has actually declared shortcode_attrs
 *     for (see scan_shortcodes()'s own docblock for why an unbounded "any
 *     shortcode on the system" scan isn't attempted).
 *
 *   unrewritten_url_query_ref (DUO-3260) — a `?p=`/`?page_id=`/
 *     `?attachment_id=` query-string parameter (WordPress's own internal-
 *     link id scheme; NOT `?page=`, which is WordPress's own separate
 *     pagination var) still holding a raw digit anywhere in captured
 *     state. Unlike Tokens::tokenize_url_query_refs()'s own REWRITE
 *     (deliberately {{home}}-anchored, for safety — never touch an
 *     external URL's own unrelated `?p=`), this scan is NOT anchored: a
 *     wide net with an honest caveat, this file's own established
 *     philosophy throughout, since narrow precision is the rewrite
 *     mechanism's job, not the detector's. Fires regardless of whether
 *     the id currently resolves to a live entity, same posture as every
 *     other unrewritten-ref finding.
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
        $shortcodeRules = $policy->shortcode_attr_rules();

        $findings = [];
        foreach (self::glob_rel($stateDir, 'posts/*/*.md') as $rel) {
            self::scan_post_file($stateDir, $rel, $policy, $blockRules, $shortcodeRules, $home, $homeEscaped, $findings);
        }
        foreach (self::glob_rel($stateDir, 'terms/*/*.json') as $rel) {
            self::scan_term_file($stateDir, $rel, $policy, $home, $homeEscaped, $findings);
        }
        foreach (self::glob_rel($stateDir, 'menus/*.json') as $rel) {
            self::scan_menu_file($stateDir, $rel, $policy, $home, $homeEscaped, $findings);
        }
        foreach (self::glob_rel($stateDir, 'sidebars/*.json') as $rel) {
            self::scan_sidebar_file(
                $stateDir, $rel, $policy, $blockRules, $home, $homeEscaped, $findings
            );
        }
        if (is_file($stateDir . '/options/core.json')) {
            self::scan_options_file($stateDir, 'options/core.json', $policy, $home, $homeEscaped, $findings);
        }
        foreach (self::glob_rel($stateDir, 'user-meta/*.json') as $rel) {
            self::scan_user_meta_file($stateDir, $rel, $policy, $home, $homeEscaped, $findings);
        }
        foreach (self::glob_rel($stateDir, 'tables/*/*.json') as $rel) {
            self::scan_table_file($stateDir, $rel, $policy, $home, $homeEscaped, $findings);
        }
        return $findings;
    }

    private static function scan_user_meta_file(
        string $stateDir,
        string $rel,
        Policy $policy,
        string $home,
        string $homeEscaped,
        array &$findings
    ): void {
        $front = Canon::decode(Canon::read_file($stateDir . '/' . $rel));
        $meta = (array) ($front['meta'] ?? []);
        foreach ($meta as $key => $value) {
            $rule = $policy->meta_rule_for_user((string) $key, $meta) ?? [];
            if (isset($rule['ref']) || !empty($rule['lint_ok'])) {
                continue;
            }
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                self::scan_structured_bare_ids(
                    $value, $rel, 'meta.' . $key, $findings, (array) ($rule['json_refs'] ?? [])
                );
                continue;
            }
            foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                $hit = Pending::resolve_id($id);
                if ($hit !== null) {
                    $findings[] = self::finding(
                        'bare_id', $rel, 'meta.' . $key . $locSuffix, $id, $hit, self::bare_id_note($hit)
                    );
                }
            }
        }
        self::walk_strings($meta, 'meta', function (string $path, string $value) use (
            &$findings, $rel, $home, $homeEscaped
        ): void {
            self::flag_escaped_home($findings, $rel, $path, $value, $home, $homeEscaped);
        });
    }

    // ------------------------------------------------------------ menus

    private static function scan_sidebar_file(
        string $stateDir,
        string $rel,
        Policy $policy,
        array $blockRules,
        string $home,
        string $homeEscaped,
        array &$findings
    ): void {
        $front = Canon::decode(Canon::read_file($stateDir . '/' . $rel));
        $declared = $policy->widget_types();
        foreach ((array) ($front['widgets'] ?? []) as $i => $widget) {
            $type = (string) ($widget['type'] ?? '');
            $settings = (array) ($widget['settings'] ?? []);
            foreach ($settings as $key => $value) {
                $rule = (array) (($declared[$type]['settings'] ?? [])[$key] ?? []);
                $locator = "widgets[$i].settings.$key";
                if (($rule['codec'] ?? '') === 'blocks' && is_string($value)) {
                    self::scan_blocks(parse_blocks($value), $blockRules, $rel, $home, $findings);
                } elseif (($rule['ref'] ?? '') === 'term') {
                    foreach (Pending::numeric_candidates($value) as [$id, $suffix]) {
                        $findings[] = self::finding(
                            'unrewritten_registered_ref', $rel, $locator . $suffix, $id,
                            Pending::resolve_id($id),
                            "widget '$type' setting '$key' is a declared term ref but remains numeric"
                        );
                    }
                }
                self::walk_strings($value, $locator, function (string $path, string $text) use (
                    &$findings, $rel, $home, $homeEscaped
                ): void {
                    self::flag_escaped_home($findings, $rel, $path, $text, $home, $homeEscaped);
                });
            }
        }
    }

    // ------------------------------------------------------------ menus

    /**
     * Menu items are nav_menu_item posts, but canonical menu files embed
     * them under items[] instead of ordinary per-post Markdown files. Their plugin-owned meta
     * therefore uses the ordinary post_meta policy while the ref field has
     * a schema-owned meaning: post_type/taxonomy refs must already be tokens;
     * custom refs are URLs and must never be interpreted as numeric ids.
     */
    private static function scan_menu_file(
        string $stateDir,
        string $rel,
        Policy $policy,
        string $home,
        string $homeEscaped,
        array &$findings
    ): void {
        $front = Canon::decode(Canon::read_file($stateDir . '/' . $rel));
        foreach ((array) ($front['items'] ?? []) as $index => $item) {
            if (!is_array($item)) {
                continue; // RepositoryCompiler owns malformed item shapes.
            }
            $type = (string) ($item['type'] ?? '');
            $ref = $item['ref'] ?? '';
            $prefix = "items[$index]";
            if ($type === 'post_type' || $type === 'taxonomy') {
                foreach (Pending::numeric_candidates($ref) as [$id, $locSuffix]) {
                    $hit = Pending::resolve_id($id);
                    $kind = $type === 'post_type' ? 'post' : 'term';
                    $findings[] = self::finding(
                        'unrewritten_registered_ref',
                        $rel,
                        $prefix . '.ref' . $locSuffix,
                        $id,
                        $hit,
                        "menu item type '$type' declares its ref as a canonical $kind token, but this value is "
                            . 'still numeric in captured state — the schema-owned rewrite never ran and the id '
                            . 'is silently environment-bound.'
                    );
                }
            } elseif ($type === 'custom' && is_string($ref)) {
                // A numeric-looking custom URL is not an entity reference.
                self::flag_escaped_home($findings, $rel, $prefix . '.ref', $ref, $home, $homeEscaped);
            }

            $meta = (array) ($item['meta'] ?? []);
            foreach ($meta as $key => $value) {
                $rule = $policy->meta_rule_for_post((string) $key, $meta) ?? [];
                if (isset($rule['ref']) || !empty($rule['lint_ok'])) {
                    continue;
                }
                $locator = $prefix . '.meta.' . $key;
                if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                    self::scan_structured_bare_ids(
                        $value, $rel, $locator, $findings, (array) ($rule['json_refs'] ?? [])
                    );
                    continue;
                }
                foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                    $hit = Pending::resolve_id($id);
                    if ($hit !== null) {
                        $findings[] = self::finding(
                            'bare_id', $rel, $locator . $locSuffix, $id, $hit, self::bare_id_note($hit)
                        );
                    }
                }
            }
            self::walk_strings($meta, $prefix . '.meta', function (
                string $path,
                string $value
            ) use (&$findings, $rel, $home, $homeEscaped): void {
                self::flag_escaped_home($findings, $rel, $path, $value, $home, $homeEscaped);
            });
        }
    }

    // ------------------------------------------------------------ posts

    private static function scan_post_file(
        string $stateDir, string $rel, Policy $policy, array $blockRules, array $shortcodeRules,
        string $home, string $homeEscaped, array &$findings
    ): void {
        [$front, $body] = Canon::parse_post_file(Canon::read_file($stateDir . '/' . $rel));
        $postType = (string) ($front['type'] ?? '');
        $meta = (array) ($front['meta'] ?? []);

        // (a) bare_id — shallow scan of authored, no-ref-declared meta;
        // deep scan (below) for json_refs/key_refs-declared structures.
        foreach ($meta as $key => $value) {
            $rule = $policy->meta_rule_for_post((string) $key, $meta);
            if (isset($rule['ref'])) {
                continue; // a declared ref path already owns this value (tokenized, or dropped if dangling)
            }
            if (!empty($rule['lint_ok'])) {
                continue; // human-reviewed declaration: numeric but genuinely not a ref
            }
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                self::scan_structured_bare_ids(
                    $value, $rel, 'meta.' . $key, $findings, (array) ($rule['json_refs'] ?? [])
                );
                continue; // structured value: the deep scan above supersedes the shallow one below
            }
            foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                $hit = Pending::resolve_id($id);
                if ($hit === null) {
                    continue;
                }
                $findings[] = self::finding('bare_id', $rel, 'meta.' . $key . $locSuffix, $id, $hit, self::bare_id_note($hit));
            }
        }

        // (b) escaped_home / unrewritten_url_query_ref — recursively
        // through meta values, and the raw body as one unit.
        self::walk_strings($meta, 'meta', function (string $path, string $s) use (&$findings, $rel, $home, $homeEscaped) {
            self::flag_escaped_home($findings, $rel, $path, $s, $home, $homeEscaped);
            self::flag_unrewritten_url_query_ref($findings, $rel, $path, $s);
        });
        self::flag_escaped_home($findings, $rel, 'body', $body, $home, $homeEscaped);
        self::flag_unrewritten_url_query_ref($findings, $rel, 'body', $body);

        // (c) unregistered_block_attr / (e) shortcode findings — block/
        // shortcode content only (verbatim bodies, e.g. acf-field, aren't
        // block or shortcode content at all — Capture::build_post() itself
        // skips Blocks::capture_rewrite() for them, so nothing would ever
        // have rewritten a shortcode ref there either; same gate both scans share).
        if ($body !== '' && $policy->body_mode($postType) !== 'verbatim') {
            self::scan_blocks(parse_blocks($body), $blockRules, $rel, $home, $findings);
            self::scan_shortcodes($body, $shortcodeRules, $rel, $findings);
        }
    }

    private static function bare_id_note(array $hit): string {
        return "no ref is declared for this key; the number coincides with an existing {$hit['kind']} id "
            . "(#{$hit['id']} \"{$hit['title']}\", {$hit['post_type']}) on this environment — could be a genuine "
            . "unrewritten reference, or an unrelated small number (a count, a version, an ordering index...). "
            . "Small ids coincide; this is a signal to investigate, not proof.";
    }

    /**
     * Deep bare_id scan for a json_refs/key_refs-declared meta/option value
     * (task #11 wave 2 — the linter half of the sub-key ref machinery: a
     * declaration must make its OWN paths lint-clean while the linter keeps
     * catching everything the declaration doesn't cover).
     *
     * Declared json_refs positions are walked with JsonRefs itself — the
     * same path engine capture uses — and a raw numeric survivor at one of
     * those exact positions is an unrewritten_registered_ref regardless of
     * its key's spelling or whether the id resolves on this environment.
     * This covers shapes such as Polylang nav_menus[theme][location][lang],
     * whose ref-bearing leaf keys are language slugs rather than id-shaped
     * names. Everything outside a declared position keeps the deliberately
     * low-noise key-name heuristic below.
     *
     * The undeclared-position fallback is scoped to id-shaped KEY NAMES
     * (looks_like_id_key() below — a sibling
     * of unregistered_block_attr's looks_like_id_attr(), NOT a reuse; see
     * that method's docblock for why) rather than flagging every numeric
     * leaf — a blind full recursion would flood on
     * Elementor's own legitimate small-int settings (column widths,
     * opacity, z-index, ...) that routinely coincide with a real entity id,
     * defeating the point of a low-noise signal. Also flags integer ARRAY
     * KEYS on a non-list (associative) array — the wpseo_taxonomy_meta
     * shape: an id-keyed map surviving capture with its keys still raw
     * ints means no key_refs declaration covers it. Gated on
     * !array_is_list($node): an ordinary LIST's own positional indices
     * (0, 1, 2, ...) are never a meaningful id-keyed-map signal — they're
     * guaranteed small integers that WILL routinely coincide with a real
     * entity id (confirmed empirically: Elementor's own `wp_gallery` array
     * tripped this on index 1 before this guard existed) — only an
     * associative array's integer keys (never positional when present,
     * always semantic) are checked, the same list/map distinction
     * JsonRefs::walk() already makes for path resolution.
     */
    private static function scan_structured_bare_ids(
        $node,
        string $rel,
        string $locator,
        array &$findings,
        array $jsonRefs = []
    ): void {
        $declaredLocators = [];
        foreach ($jsonRefs as $rule) {
            $copy = $node;
            JsonRefs::walk(
                $copy,
                JsonRefs::parse_path((string) $rule['path']),
                function (&$container, $key, string $matchedLocator) use (
                    &$declaredLocators, &$findings, $rel, $rule
                ): void {
                    $declaredLocators[$matchedLocator] = true;
                    $value = $container[$key];
                    if (is_array($value)) {
                        return; // struct_capture() only rewrites scalar matches
                    }
                    foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                        if ($id <= 0) {
                            continue; // json_refs' explicit unset convention
                        }
                        $hit = Pending::resolve_id($id);
                        $kind = (string) ($rule['kind'] ?? 'entity');
                        $findings[] = self::finding(
                            'unrewritten_registered_ref',
                            $rel,
                            $matchedLocator . $locSuffix,
                            $id,
                            $hit,
                            "json_refs path '" . (string) $rule['path'] . "' declares this value as a $kind "
                                . 'reference, but it is still numeric in captured state — the declared rewrite '
                                . 'to a {{...}} token never ran. This id is silently environment-bound and will '
                                . 'point at the wrong entity (or nothing) once ids diverge on another environment.'
                        );
                    }
                },
                $locator
            );
        }
        self::scan_structured_bare_ids_by_key($node, $rel, $locator, $findings, $declaredLocators);
    }

    /** Existing undeclared-position heuristic, excluding exact json_refs matches already classified above. */
    private static function scan_structured_bare_ids_by_key(
        $node,
        string $rel,
        string $locator,
        array &$findings,
        array $declaredLocators
    ): void {
        if (!is_array($node)) {
            return;
        }
        $isList = array_is_list($node);
        foreach ($node as $key => $v) {
            $childLocator = is_int($key) ? "{$locator}[{$key}]" : "{$locator}.{$key}";
            if (is_int($key) && !$isList) {
                $hit = Pending::resolve_id($key);
                if ($hit !== null) {
                    $findings[] = self::finding('bare_id', $rel, "$locator KEY $key", $key, $hit, sprintf(
                        "this structured value has an integer ARRAY KEY that matches an existing %s id "
                        . "(#%d \"%s\", %s), with no declared key_refs path covering it — an id-keyed map "
                        . "surviving capture is exactly the wpseo_taxonomy_meta shape key_refs exists to "
                        . "rewrite; a resolved key_refs match is never still a raw integer key by this point, "
                        . "so this is a genuine gap, not a false read. Small ids coincide; this is a signal to "
                        . "investigate, not proof.",
                        $hit['kind'], $hit['id'], $hit['title'], $hit['post_type']
                    ));
                }
            } elseif (is_string($key)
                && self::looks_like_id_key($key)
                && !isset($declaredLocators[$childLocator])) {
                foreach (Pending::numeric_candidates($v) as [$id, $locSuffix]) {
                    $hit = Pending::resolve_id($id);
                    if ($hit === null) {
                        continue;
                    }
                    $findings[] = self::finding('bare_id', $rel, $childLocator . $locSuffix, $id, $hit, sprintf(
                        "key '%s' inside a json_refs/key_refs-declared structure looks like an id (matches the "
                        . "id/ids/ref/*Id/*Ids naming heuristic) and its value coincides with an existing %s id "
                        . "(#%d \"%s\", %s), but no declared json_refs path covers this exact position — a "
                        . "resolved json_refs match is never still a raw number by this point (it becomes a "
                        . "token, or null if unmapped), so this is a genuine manifest gap, not a false read. "
                        . "Small ids coincide; this is a signal to investigate, not proof.",
                        $key, $hit['kind'], $hit['id'], $hit['title'], $hit['post_type']
                    ));
                }
            }
            self::scan_structured_bare_ids_by_key($v, $rel, $childLocator, $findings, $declaredLocators);
        }
    }

    // ------------------------------------------------------------ blocks

    private static function scan_blocks(array $blocks, array $blockRules, string $rel, string $home, array &$findings): void {
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
                        if (self::looks_like_id_attr($attrKey)) {
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
                            $hit = Pending::resolve_id($id);
                            $findings[] = self::finding(
                                'unrewritten_registered_ref', $rel,
                                'blocks.' . $name . '.attrs.' . $attrKey . $locSuffix, $id, $hit,
                                "block '$name' attribute '$attrKey' has a block_attrs registry rule declaring it a "
                                . "reference, but this value is still numeric in captured state — the declared "
                                . "rewrite to a {{...}} token never ran (an unmapped/dangling id, or — for a "
                                . "kind_from-dispatched rule — a sibling value that resolved to no kind). This id "
                                . "is silently environment-bound and will point at the wrong entity (or nothing) "
                                . "once ids diverge on another environment."
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

    // ------------------------------------------------------------ shortcodes

    /**
     * DUO-3259: the shortcode twin of scan_blocks() above — same two-class
     * structure (unregistered_shortcode_attr / unrewritten_registered_
     * shortcode_ref mirror unregistered_block_attr / unrewritten_
     * registered_ref exactly), but scoped to shortcode TAGS this engine
     * has actually declared shortcode_attrs for (Policy::shortcode_attr_
     * rules()). Unlike parse_blocks(), which always fully parses a
     * document's ENTIRE block structure for free, there is no registry-
     * independent way to enumerate "every shortcode instance" in raw text
     * — get_shortcode_regex() with no tagnames falls back to the LIVE
     * $shortcode_tags global (which plugins are active at LINT time, not
     * a policy fact). Discovering unknown reference-shaped shortcodes in
     * the wild is a different, unbounded problem, deliberately out of
     * scope here — the same "ground it in what shipped manifests actually
     * declare" discipline DUO-3259's own filing already committed to.
     *
     * Read-only, so shortcode_parse_atts() is used directly to get a
     * clean {name: value} map — its capture-time lossy normalizations
     * (stripcslashes(), unicode-whitespace collapse, unclosed-HTML
     * rejection) don't affect whether a numeric id is present, and
     * nothing here writes a value back (unlike Shortcodes.php's own
     * splice-based rewrite, which avoids shortcode_parse_atts() for
     * exactly that lossiness reason).
     */
    private static function scan_shortcodes(string $body, array $shortcodeRules, string $rel, array &$findings): void {
        if ($shortcodeRules === [] || !str_contains($body, '[')) {
            return;
        }
        $pattern = '/' . get_shortcode_regex(array_keys($shortcodeRules)) . '/';
        if (!preg_match_all($pattern, $body, $matches, PREG_SET_ORDER)) {
            return;
        }
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
                $rulesByAttr[$r['path']] = $r;
            }
            $atts = shortcode_parse_atts($m[3]);
            foreach ($atts as $attrKey => $attrVal) {
                if (!is_string($attrKey)) {
                    continue; // positional/bare value — no name to match a rule or heuristic against
                }
                $rule = $rulesByAttr[$attrKey] ?? null;
                if ($rule === null) {
                    // looks_like_id_KEY(), not looks_like_id_ATTR(): shortcode_
                    // parse_atts() strtolower()s every attribute name (confirmed
                    // by reading it directly), so a source-text camelCase name
                    // like "userId" is ALREADY "userid" by the time it reaches
                    // here -- looks_like_id_attr()'s /(Id|ID)s?$/ branch is tuned
                    // for block attrs' case-PRESERVED JSON keys and can never
                    // fire on already-lowercased text (its bare id/ids/ref checks
                    // still would, but the suffix branch is dead code at this call
                    // site). looks_like_id_key()'s [-_][iI][dD]s? branch is the
                    // one actually built for a lowercased/snake_case/kebab-case
                    // naming world (Yoast's wpseo_opengraph-image-id was its own
                    // grounding case) -- the correct heuristic to reuse here.
                    if (self::looks_like_id_key($attrKey)) {
                        foreach (Pending::numeric_candidates($attrVal) as [$id, $locSuffix]) {
                            $hit = Pending::resolve_id($id);
                            $findings[] = self::finding(
                                'unregistered_shortcode_attr', $rel,
                                "shortcode.$tag.attrs.$attrKey" . $locSuffix, $id, $hit,
                                "shortcode '$tag' has no shortcode_attrs registry rule for attribute '$attrKey'; "
                                . "this numeric value passes through capture/apply untouched and will point at "
                                . "the wrong entity (or nothing) once ids diverge on another environment."
                            );
                        }
                    }
                    continue;
                }
                foreach (Pending::numeric_candidates($attrVal) as [$id, $locSuffix]) {
                    $hit = Pending::resolve_id($id);
                    $findings[] = self::finding(
                        'unrewritten_registered_shortcode_ref', $rel,
                        "shortcode.$tag.attrs.$attrKey" . $locSuffix, $id, $hit,
                        "shortcode '$tag' attribute '$attrKey' has a shortcode_attrs registry rule declaring it a "
                        . "reference, but this value is still numeric in captured state — the declared rewrite to "
                        . "a {{...}} token never ran (an unmapped/dangling id). This id is silently environment-"
                        . "bound and will point at the wrong entity (or nothing) once ids diverge on another "
                        . "environment."
                    );
                }
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
    private static function looks_like_id_attr(string $key): bool {
        return $key === 'id' || $key === 'ids' || $key === 'ref' || (bool) preg_match('/(Id|ID)s?$/', $key);
    }

    /**
     * Deliberately SEPARATE from looks_like_id_attr() above, not a reuse:
     * that one is tuned for block-attribute naming (camelCase JS/React
     * convention — mediaId, termIds), and a first attempt at reusing it
     * verbatim for scan_structured_bare_ids() silently missed Yoast's OWN
     * key-naming convention — `wpseo_opengraph-image-id` ends in lowercase
     * "-id", which `/(Id|Ids)$/` (case-sensitive) does not match — caught
     * only by testing against the real captured wpseo_taxonomy_meta state,
     * not by inspection. Widening the shared block-attr function instead
     * risked an untested behavior change to the already-passing FSE
     * conformance suite for zero benefit; a second, purpose-built
     * heuristic for the naming conventions THESE (PHP-array / JSON-plugin)
     * structures actually use is the safer fix. id / ids / ref (exact,
     * matching the block-attr heuristic's own exact cases) or a `_id`/
     * `-id`/`_ids`/`-ids`/`Id`/`Ids` suffix — covers Yoast's kebab-case,
     * Elementor's snake_case controls, and the camelCase case too.
     */
    private static function looks_like_id_key(string $key): bool {
        // [-_]ids? is safe lowercase (separator boundary); the suffix variants
        // widen to the all-caps convention per task #76, same as the attr
        // heuristic above — never a bare /i (would match "grid", "valid").
        return $key === 'id' || $key === 'ids' || $key === 'ref'
            || (bool) preg_match('/([-_][iI][dD]s?|(Id|ID)s?)$/', $key);
    }

    // ------------------------------------------------------------ terms

    private static function scan_term_file(string $stateDir, string $rel, Policy $policy, string $home, string $homeEscaped, array &$findings): void {
        $front = Canon::decode(Canon::read_file($stateDir . '/' . $rel));
        $desc = $front['description'] ?? '';
        $taxonomy = (string) ($front['taxonomy'] ?? '');
        $meta = (array) ($front['meta'] ?? []);

        foreach ($meta as $key => $value) {
            $rule = $policy->meta_rule_for_term((string) $key, $meta);
            if (isset($rule['ref']) || !empty($rule['lint_ok'])) {
                continue;
            }
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                self::scan_structured_bare_ids(
                    $value, $rel, 'meta.' . $key, $findings, (array) ($rule['json_refs'] ?? [])
                );
                continue;
            }
            foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                $hit = Pending::resolve_id($id);
                if ($hit !== null) {
                    $findings[] = self::finding('bare_id', $rel, 'meta.' . $key . $locSuffix, $id, $hit, self::bare_id_note($hit));
                }
            }
        }
        self::walk_strings($meta, 'meta', function (string $path, string $value) use (&$findings, $rel, $home, $homeEscaped) {
            self::flag_escaped_home($findings, $rel, $path, $value, $home, $homeEscaped);
        });

        // A taxonomy declaring taxonomies.<tax>.description_refs already
        // has its description rewritten through Tokens::struct_capture()
        // (Capture::term_description()) — the SAME "declared paths clean,
        // undeclared paths in the same structure still flagged" contract
        // scan_structured_bare_ids() already gives json_refs/key_refs-
        // declared meta/option values, reused verbatim here rather than a
        // parallel check: a resolved json_refs match is never still a raw
        // id by this point (it's a token, or null if unmapped), so
        // anything scan_structured_bare_ids() finds inside this structure
        // is either a raw survivor at the declared "$.*" path or a genuine
        // undeclared-position gap elsewhere in the structure.
        $descriptionRef = $policy->description_refs_for_taxonomy($taxonomy);
        if ($descriptionRef !== null) {
            self::scan_structured_bare_ids(
                $desc,
                $rel,
                'description',
                $findings,
                [['path' => '$.*', 'kind' => (string) $descriptionRef['kind']]]
            );
            return;
        }

        if (!is_string($desc) || $desc === '') {
            return;
        }

        // (b) escaped_home / unrewritten_url_query_ref
        self::flag_escaped_home($findings, $rel, 'description', $desc, $home, $homeEscaped);
        self::flag_unrewritten_url_query_ref($findings, $rel, 'description', $desc);

        // (d) serialized_desc_ids — PHP-serialized data with NO declared
        // description_refs rewrite path (Polylang's post_translations/
        // term_translations shape, for any taxonomy nobody has declared
        // description_refs for).
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
                . "existing %s id (#%d \"%s\", %s); taxonomy '%s' has no 'description_refs' declaration, so "
                . "nothing rewrites this term's description (Capture tokenize_text()'s it as an opaque string) "
                . "and this id is silently environment-bound — Polylang's post_translations/term_translations "
                . "shape before a description_refs declaration covers it.",
                $hit['kind'], $hit['id'], $hit['title'], $hit['post_type'], $taxonomy
            ));
        }
    }

    // ------------------------------------------------------------ options

    private static function scan_options_file(string $stateDir, string $rel, Policy $policy, string $home, string $homeEscaped, array &$findings): void {
        $options = OptionState::values((array) Canon::decode(Canon::read_file($stateDir . '/' . $rel)));

        // (a) bare_id
        foreach ($options as $key => $value) {
            $rule = $policy->option_rule((string) $key) ?? [];
            if (!empty($rule['sub_keys'])) {
                // DUO-3233: the SAME "declared paths clean, undeclared
                // positions in the same structure still flagged" contract,
                // nested one level — each NAMED sub-key carries its own
                // rule (json_refs/key_refs/ref/lint_ok), exactly like a
                // whole option would, so this recurses the identical checks
                // scan_options_file() already runs, once per declared
                // sub-key, instead of the single flat check below.
                self::scan_option_sub_keys($value, (array) $rule['sub_keys'], $rel, (string) $key, $findings);
                continue;
            }
            if (isset($rule['ref'])) {
                continue;
            }
            if (!empty($rule['lint_ok'])) {
                continue; // human-reviewed declaration: numeric but genuinely not a ref
            }
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                self::scan_structured_bare_ids(
                    $value, $rel, 'options.' . $key, $findings, (array) ($rule['json_refs'] ?? [])
                );
                continue; // structured value: the deep scan above supersedes the shallow one below
            }
            foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                $hit = Pending::resolve_id($id);
                if ($hit === null) {
                    continue;
                }
                $findings[] = self::finding('bare_id', $rel, 'options.' . $key . $locSuffix, $id, $hit, self::bare_id_note($hit));
            }
        }

        // (b) escaped_home / unrewritten_url_query_ref
        self::walk_strings($options, 'options', function (string $path, string $s) use (&$findings, $rel, $home, $homeEscaped) {
            self::flag_escaped_home($findings, $rel, $path, $s, $home, $homeEscaped);
            self::flag_unrewritten_url_query_ref($findings, $rel, $path, $s);
        });
    }

    /** @see scan_options_file()'s sub_keys branch */
    private static function scan_option_sub_keys($value, array $subKeys, string $rel, string $optionName, array &$findings): void {
        if (!is_array($value)) {
            return; // malformed shape -- RepositoryAuthorization's own gate is the authoritative check for this
        }
        foreach ($value as $subKey => $subVal) {
            $subRule = $subKeys[$subKey] ?? [];
            $locator = 'options.' . $optionName . '.' . $subKey;
            if (isset($subRule['ref']) || !empty($subRule['lint_ok'])) {
                continue;
            }
            if (!empty($subRule['json_refs']) || !empty($subRule['key_refs'])) {
                self::scan_structured_bare_ids(
                    $subVal, $rel, $locator, $findings, (array) ($subRule['json_refs'] ?? [])
                );
                continue;
            }
            foreach (Pending::numeric_candidates($subVal) as [$id, $locSuffix]) {
                $hit = Pending::resolve_id($id);
                if ($hit === null) {
                    continue;
                }
                $findings[] = self::finding('bare_id', $rel, $locator . $locSuffix, $id, $hit, self::bare_id_note($hit));
            }
        }
    }

    // ------------------------------------------------------------ tables

    /**
     * Typed-snapshot table rows (agent/src/Snapshot.php, task #75): the
     * finding-#8 rule extended to custom-table columns — a declared ref
     * column is already owned (tokenized on capture, or a loud THROW if
     * dangling — Snapshot.php never lets one reach canonical state
     * unresolved, so there is nothing here for lint to catch on that
     * axis); an undeclared `columns` entry that happens to hold a live
     * entity id is exactly finding #9's bare_id case, generalized from
     * post_meta/options keys to table columns — same shallow, no-naming-
     * heuristic-gate scan as scan_options_file() above (a table's column
     * set is finite and fully enumerated by the manifest, the same shape
     * as options/post_meta, not the open-ended nested structure
     * scan_structured_bare_ids() exists for).
     *
     * The attached-meta `meta` sidecar (nf3_field_meta etc., folded into
     * this same file — see Snapshot.php's docblock) is a FLAT key=>value
     * map by construction, exactly the shape scan_structured_bare_ids()
     * already expects: reused verbatim, not reimplemented. A meta key
     * Snapshot.php declares as a ref (e.g. nf3_field_meta's "parent_id")
     * is already a token string by the time it reaches this file, so
     * Pending::numeric_candidates() never matches it — no separate
     * "declared meta ref" exclusion list is needed here, mirroring why
     * scan_structured_bare_ids() itself needs none for json_refs/key_refs-
     * declared paths elsewhere in this class.
     */
    private static function scan_table_file(string $stateDir, string $rel, Policy $policy, string $home, string $homeEscaped, array &$findings): void {
        $front = Canon::decode(Canon::read_file($stateDir . '/' . $rel));
        $table = (string) ($front['table'] ?? '');
        $decl = $policy->table_rule($table) ?? [];
        $refCols = array_column($decl['refs'] ?? [], 'column');

        // (a) bare_id — columns with no declared ref
        $columns = (array) ($front['columns'] ?? []);
        foreach ($columns as $col => $value) {
            if (in_array($col, $refCols, true)) {
                continue; // declared ref: already owned (tokenized, or Snapshot.php threw if dangling)
            }
            if (!empty($decl['columns'][$col]['lint_ok'])) {
                continue; // human-reviewed declaration: numeric but genuinely not a ref
            }
            foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                $hit = Pending::resolve_id($id);
                if ($hit === null) {
                    continue;
                }
                $findings[] = self::finding('bare_id', $rel, "columns.$col" . $locSuffix, $id, $hit, sprintf(
                    "table '%s' column '%s' has no ref declared; the number coincides with an existing %s id "
                    . '(#%d "%s", %s) on this environment — could be a genuine unrewritten reference, or an '
                    . 'unrelated small number (a count, a version, an ordering index...). Small ids coincide; '
                    . 'this is a signal to investigate, not proof.',
                    $table, $col, $hit['kind'], $hit['id'], $hit['title'], $hit['post_type']
                ));
            }
        }

        // (b) escaped_home / unrewritten_url_query_ref — columns and the attached-meta sidecar
        $meta = (array) ($front['meta'] ?? []);
        self::walk_strings($columns, 'columns', function (string $path, string $s) use (&$findings, $rel, $home, $homeEscaped) {
            self::flag_escaped_home($findings, $rel, $path, $s, $home, $homeEscaped);
            self::flag_unrewritten_url_query_ref($findings, $rel, $path, $s);
        });
        self::walk_strings($meta, 'meta', function (string $path, string $s) use (&$findings, $rel, $home, $homeEscaped) {
            self::flag_escaped_home($findings, $rel, $path, $s, $home, $homeEscaped);
            self::flag_unrewritten_url_query_ref($findings, $rel, $path, $s);
        });

        // (c) the attached-meta sidecar's own id-shaped-key check
        self::scan_structured_bare_ids($meta, $rel, 'meta', $findings);
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
     * DUO-3260: a `?p=`/`?page_id=`/`?attachment_id=` query-string
     * parameter (WordPress's own internal-link id scheme, confirmed by
     * reading wp-includes/canonical.php's redirect_canonical() directly —
     * NOT `?page=`, WordPress's own separate pagination var) still
     * holding a raw digit in captured state. Deliberately NOT anchored to
     * this environment's {{home}}/home URL the way Tokens::tokenize_url_
     * query_refs()'s own REWRITE is (that anchor exists there for
     * SAFETY — never touch an external URL's own unrelated `?p=`) — this
     * is a lint SIGNAL, not a rewrite, and this file's own established
     * philosophy throughout (bare_id, escaped_home, every other class
     * here) is a wide net with an honest caveat, not narrow precision;
     * narrow precision is the rewrite mechanism's job. Fires regardless
     * of whether the id currently resolves to a live entity, matching
     * every other unrewritten-ref finding's own posture. A genuine false
     * positive here (a third-party URL that happens to use the same
     * common parameter name) is exactly the caveat the note states,
     * mirroring bare_id's own "small ids coincide" framing — not
     * something this method tries to rule out structurally.
     */
    private static function flag_unrewritten_url_query_ref(array &$findings, string $rel, string $locator, string $s): void {
        if ($s === '' || (!str_contains($s, '?') && !str_contains($s, '&'))) {
            return;
        }
        if (!preg_match_all('/[?&](p|page_id|attachment_id)=(\d+)/', $s, $matches, PREG_SET_ORDER)) {
            return;
        }
        foreach ($matches as $i => $m) {
            $id = (int) $m[2];
            $hit = Pending::resolve_id($id);
            $findings[] = self::finding('unrewritten_url_query_ref', $rel, $locator . "[url_query:$i]", $id, $hit, sprintf(
                "a '%s=%d' query-string parameter is still a raw numeric id in captured state — WordPress's own "
                . 'redirect_canonical() resolves this parameter to a real post regardless of post_type. This is '
                . 'either a genuinely external URL that happens to share this common parameter name (small ids '
                . 'coincide; this is a signal to investigate, not proof), a purely relative internal link '
                . "(tokenize_text()'s {{home}}-anchored rewrite cannot reach a URL with no scheme/host at all — the "
                . 'same pre-existing limitation plain permalink tokenization already has), or a declared rewrite '
                . 'that silently did not run.',
                $m[1], $id
            ));
        }
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
