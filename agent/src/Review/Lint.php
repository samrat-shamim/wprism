<?php
namespace Duo;

require_once __DIR__ . '/BlockReferenceScanner.php';
require_once __DIR__ . '/MenuReferenceScanner.php';
require_once __DIR__ . '/SerializedTermDescriptionScanner.php';
require_once __DIR__ . '/ShortcodeReferenceScanner.php';
require_once __DIR__ . '/StructuredReferenceScanner.php';
require_once __DIR__ . '/LintFinding.php';
require_once __DIR__ . '/../Repository/StateTreeWalker.php';

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
 *     state. Unlike UrlQueryReferenceCodec::capture()'s own REWRITE
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
        foreach (StateTreeWalker::files($stateDir) as $file) {
            $rel = $file['path'];
            switch ($file['surface']) {
                case 'post':
                    self::scan_post_file($stateDir, $rel, $policy, $blockRules, $shortcodeRules, $home, $homeEscaped, $findings);
                    break;
                case 'term':
                    self::scan_term_file($stateDir, $rel, $policy, $home, $homeEscaped, $findings);
                    break;
                case 'menu':
                    self::scan_menu_file($stateDir, $rel, $policy, $home, $homeEscaped, $findings);
                    break;
                case 'sidebar':
                    self::scan_sidebar_file($stateDir, $rel, $policy, $blockRules, $home, $homeEscaped, $findings);
                    break;
                case 'options':
                    self::scan_options_file($stateDir, $rel, $policy, $home, $homeEscaped, $findings);
                    break;
                case 'user_meta':
                    self::scan_user_meta_file($stateDir, $rel, $policy, $home, $homeEscaped, $findings);
                    break;
                case 'table':
                    self::scan_table_file($stateDir, $rel, $policy, $home, $homeEscaped, $findings);
                    break;
                default:
                    throw new \LogicException('duo: unknown canonical lint surface ' . $file['surface']);
            }
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
                    $value,
                    $rel,
                    'meta.' . $key,
                    $findings,
                    (array) ($rule['json_refs'] ?? []),
                    isset($rule['key_refs']) ? (array) $rule['key_refs'] : null
                );
                continue;
            }
            foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                $hit = Pending::resolve_id($id);
                if ($hit !== null) {
                    $findings[] = LintFinding::make(
                        'bare_id', $rel, 'meta.' . $key . $locSuffix, $id, $hit, self::bare_id_note($hit)
                    );
                }
            }
        }
        StateTreeWalker::strings($meta, 'meta', function (string $path, string $value) use (
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
                        $findings[] = LintFinding::make(
                            'unrewritten_registered_ref', $rel, $locator . $suffix, $id,
                            Pending::resolve_id($id),
                            "widget '$type' setting '$key' is a declared term ref but remains numeric"
                        );
                    }
                }
                StateTreeWalker::strings($value, $locator, function (string $path, string $text) use (
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
        foreach (MenuReferenceScanner::scan(
            (array) ($front['items'] ?? []),
            $rel,
            [$policy, 'meta_rule_for_post'],
            $home,
            $homeEscaped
        ) as $finding) {
            $findings[] = $finding;
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

        self::scan_taxonomy_relationship_keyspaces(
            (array) ($front['terms'] ?? []), $rel, 'terms', 'post', $policy, $findings
        );

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
                    $value,
                    $rel,
                    'meta.' . $key,
                    $findings,
                    (array) ($rule['json_refs'] ?? []),
                    isset($rule['key_refs']) ? (array) $rule['key_refs'] : null
                );
                continue; // structured value: the deep scan above supersedes the shallow one below
            }
            foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                $hit = Pending::resolve_id($id);
                if ($hit === null) {
                    continue;
                }
                $findings[] = LintFinding::make('bare_id', $rel, 'meta.' . $key . $locSuffix, $id, $hit, self::bare_id_note($hit));
            }
        }

        // (b) escaped_home / unrewritten_url_query_ref — recursively
        // through meta values, and the raw body as one unit.
        StateTreeWalker::strings($meta, 'meta', function (string $path, string $s) use (&$findings, $rel, $home, $homeEscaped, $shortcodeRules) {
            self::flag_escaped_home($findings, $rel, $path, $s, $home, $homeEscaped);
            self::flag_unrewritten_url_query_ref($findings, $rel, $path, $s);
            self::scan_shortcodes($s, $shortcodeRules, $rel, $findings, $path);
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
            . 'unrewritten reference, or an unrelated small number (a count, a version, an ordering index...). '
            . 'Small ids coincide; this is a signal to investigate, not proof.';
    }

    /**
     * Structured-reference scanning is delegated to the pure registry-ready
     * collaborator; this facade preserves Lint's existing finding sink and
     * call-site contract while keeping filesystem traversal here.
     */
    private static function scan_structured_bare_ids(
        $node,
        string $rel,
        string $locator,
        array &$findings,
        array $jsonRefs = [],
        ?array $keyRefs = null
    ): void {
        foreach (StructuredReferenceScanner::scan($node, $rel, $locator, $jsonRefs, $keyRefs) as $finding) {
            $findings[] = $finding;
        }
    }

    // ------------------------------------------------------------ blocks

    private static function scan_blocks(array $blocks, array $blockRules, string $rel, string $home, array &$findings): void {
        foreach (BlockReferenceScanner::scan($blocks, $blockRules, $rel, $home) as $finding) {
            $findings[] = $finding;
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
    private static function scan_shortcodes(
        string $body,
        array $shortcodeRules,
        string $rel,
        array &$findings,
        string $locatorPrefix = ''
    ): void {
        foreach (ShortcodeReferenceScanner::scan($body, $shortcodeRules, $rel, $locatorPrefix) as $finding) {
            $findings[] = $finding;
        }
    }

    // ------------------------------------------------------------ terms

    private static function scan_term_file(string $stateDir, string $rel, Policy $policy, string $home, string $homeEscaped, array &$findings): void {
        $front = Canon::decode(Canon::read_file($stateDir . '/' . $rel));
        $desc = $front['description'] ?? '';
        $taxonomy = (string) ($front['taxonomy'] ?? '');
        $meta = (array) ($front['meta'] ?? []);

        self::scan_taxonomy_relationship_keyspaces(
            (array) ($front['relationships'] ?? []), $rel, 'relationships', 'term', $policy, $findings
        );

        foreach ($meta as $key => $value) {
            $rule = $policy->meta_rule_for_term((string) $key, $meta);
            if (isset($rule['ref']) || !empty($rule['lint_ok'])) {
                continue;
            }
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                self::scan_structured_bare_ids(
                    $value,
                    $rel,
                    'meta.' . $key,
                    $findings,
                    (array) ($rule['json_refs'] ?? []),
                    isset($rule['key_refs']) ? (array) $rule['key_refs'] : null
                );
                continue;
            }
            foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                $hit = Pending::resolve_id($id);
                if ($hit !== null) {
                    $findings[] = LintFinding::make('bare_id', $rel, 'meta.' . $key . $locSuffix, $id, $hit, self::bare_id_note($hit));
                }
            }
        }
        StateTreeWalker::strings($meta, 'meta', function (string $path, string $value) use (&$findings, $rel, $home, $homeEscaped) {
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
        $descriptionRef = $policy->description_reference_rule($taxonomy);
        if ($descriptionRef !== null) {
            self::scan_structured_bare_ids(
                $desc,
                $rel,
                'description',
                $findings,
                $descriptionRef['json_refs'],
                $descriptionRef['key_refs']
            );
            return;
        }

        if (!is_string($desc) || $desc === '') {
            return;
        }

        // (b) escaped_home / unrewritten_url_query_ref
        self::flag_escaped_home($findings, $rel, 'description', $desc, $home, $homeEscaped);
        self::flag_unrewritten_url_query_ref($findings, $rel, 'description', $desc);

        foreach (SerializedTermDescriptionScanner::scan($desc, $taxonomy, $rel) as $finding) {
            $findings[] = $finding;
        }
    }

    /**
     * The same Policy resolver Capture/Apply use decides whether a
     * relationship map belongs under a post's `terms` or a term's
     * `relationships`. Lint reports both a wrong declared/default keyspace
     * and a resolver ambiguity without trying to infer ownership from the
     * runtime plugin's object_type sentinel.
     */
    private static function scan_taxonomy_relationship_keyspaces(
        array $relationships,
        string $rel,
        string $field,
        string $expected,
        Policy $policy,
        array &$findings
    ): void {
        foreach (array_keys($relationships) as $taxonomy) {
            if (!is_string($taxonomy)) {
                continue; // RepositoryCompiler owns canonical map-shape diagnostics.
            }
            $locator = $field . '.' . $taxonomy;
            try {
                $actual = $policy->taxonomy_object_keyspace($taxonomy);
            } catch (\Throwable $t) {
                $findings[] = LintFinding::make(
                    'taxonomy_object_keyspace_invalid',
                    $rel,
                    $locator,
                    $taxonomy,
                    null,
                    $t->getMessage()
                );
                continue;
            }
            if ($actual !== $expected) {
                $findings[] = LintFinding::make(
                    'taxonomy_object_keyspace_mismatch',
                    $rel,
                    $locator,
                    $actual,
                    null,
                    "taxonomy '$taxonomy' resolves to object_keyspace='$actual'; $field requires '$expected'"
                );
            }
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
                    $value,
                    $rel,
                    'options.' . $key,
                    $findings,
                    (array) ($rule['json_refs'] ?? []),
                    isset($rule['key_refs']) ? (array) $rule['key_refs'] : null
                );
                continue; // structured value: the deep scan above supersedes the shallow one below
            }
            foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                $hit = Pending::resolve_id($id);
                if ($hit === null) {
                    continue;
                }
                $findings[] = LintFinding::make('bare_id', $rel, 'options.' . $key . $locSuffix, $id, $hit, self::bare_id_note($hit));
            }
        }

        // (b) escaped_home / unrewritten_url_query_ref
        StateTreeWalker::strings($options, 'options', function (string $path, string $s) use (&$findings, $rel, $home, $homeEscaped) {
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
                    $subVal,
                    $rel,
                    $locator,
                    $findings,
                    (array) ($subRule['json_refs'] ?? []),
                    isset($subRule['key_refs']) ? (array) $subRule['key_refs'] : null
                );
                continue;
            }
            foreach (Pending::numeric_candidates($subVal) as [$id, $locSuffix]) {
                $hit = Pending::resolve_id($id);
                if ($hit === null) {
                    continue;
                }
                $findings[] = LintFinding::make('bare_id', $rel, $locator . $locSuffix, $id, $hit, self::bare_id_note($hit));
            }
        }
    }

    // ------------------------------------------------------------ tables

    /**
     * Typed-snapshot table rows (agent/src/Repository/Snapshot.php, task #75): the
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
                $findings[] = LintFinding::make('bare_id', $rel, "columns.$col" . $locSuffix, $id, $hit, sprintf(
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
        StateTreeWalker::strings($columns, 'columns', function (string $path, string $s) use (&$findings, $rel, $home, $homeEscaped) {
            self::flag_escaped_home($findings, $rel, $path, $s, $home, $homeEscaped);
            self::flag_unrewritten_url_query_ref($findings, $rel, $path, $s);
        });
        StateTreeWalker::strings($meta, 'meta', function (string $path, string $s) use (&$findings, $rel, $home, $homeEscaped) {
            self::flag_escaped_home($findings, $rel, $path, $s, $home, $homeEscaped);
            self::flag_unrewritten_url_query_ref($findings, $rel, $path, $s);
        });

        // (c) attached-meta values use the exact same per-key declaration
        // Snapshot used to capture them. Structured keys get declared-path
        // lint; scalar refs must already be tokens; unstructured siblings
        // retain the historical heuristic scan unchanged.
        $sidecar = $policy->attached_meta_table_for_owner($table);
        $unstructured = [];
        foreach ($meta as $key => $value) {
            $rule = $sidecar === null
                ? []
                : ReferenceRules::attached_meta_key($sidecar['rule'], (string) $key);
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                self::scan_structured_bare_ids(
                    $value,
                    $rel,
                    'meta.' . $key,
                    $findings,
                    (array) ($rule['json_refs'] ?? []),
                    isset($rule['key_refs']) ? (array) $rule['key_refs'] : null
                );
                continue;
            }
            if (!empty($rule['ref'])) {
                foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                    if ($id <= 0) {
                        continue;
                    }
                    $findings[] = LintFinding::make(
                        'unrewritten_registered_ref',
                        $rel,
                        'meta.' . $key . $locSuffix,
                        $id,
                        Pending::resolve_id($id),
                        "attached-meta key '$key' declares a {$rule['ref']} reference, but it is still numeric "
                            . 'in captured state instead of a portable token'
                    );
                }
                continue;
            }
            $unstructured[$key] = $value;
        }
        self::scan_structured_bare_ids($unstructured, $rel, 'meta', $findings);
    }

    // ------------------------------------------------------------ shared

    private static function flag_escaped_home(array &$findings, string $rel, string $locator, string $s, string $home, string $homeEscaped): void {
        if ($s === '' || !str_contains($s, $homeEscaped)) {
            return;
        }
        $findings[] = LintFinding::make('escaped_home', $rel, $locator, self::truncate($s), null, sprintf(
            "this environment's home URL (%s) appears in JSON-escaped form (\\/ instead of /); "
            . 'Tokens::tokenize_text() only matches the plain, unescaped form (a literal str_replace()), so this '
            . "will NOT be rewritten on apply and will leak this environment's host into the target — "
            . 'the escaped-slash URL leak shape of an opaque embedded JSON blob.',
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
            $findings[] = LintFinding::make('unrewritten_url_query_ref', $rel, $locator . "[url_query:$i]", $id, $hit, sprintf(
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

    private static function truncate($s): string {
        $s = (string) $s;
        return strlen($s) > self::MAX_VALUE_LEN ? substr($s, 0, self::MAX_VALUE_LEN) . '…(truncated)' : $s;
    }
}
