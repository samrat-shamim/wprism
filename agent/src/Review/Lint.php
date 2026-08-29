<?php
namespace WPrism;

require_once __DIR__ . '/BlockReferenceScanner.php';
require_once __DIR__ . '/MenuReferenceScanner.php';
require_once __DIR__ . '/SerializedTermDescriptionScanner.php';
require_once __DIR__ . '/ShortcodeReferenceScanner.php';
require_once __DIR__ . '/StructuredReferenceScanner.php';
require_once __DIR__ . '/LintFinding.php';
require_once __DIR__ . '/LintEnvironment.php';
require_once __DIR__ . '/../Grammar/BodyRefGrammar.php';
require_once __DIR__ . '/../Repository/StateTreeWalker.php';

/**
 * The generalized suspicious-ref linter (task #11's linter half). The FSE,
 * Polylang and Elementor frontier explorations each independently proved that
 * byte-identical round-tripping reports "clean" on real corruption the
 * moment a ref-shaped value reaches canonical state without ever passing
 * through a declared rewrite path: both sides just encode the same wrong
 * bytes, and the diff comes back empty. Pending::ref_hint() was this
 * check's seed — one key, one current live value. Lint::scan_tree()
 * generalizes it to a whole captured state tree, across every canonical
 * surface those three explorations found broken.
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
 * Eight detection classes (five from wave 1, plus issue #3259's shortcode pair
 * and issue #3260's url-query-ref class below — sub-key option refs and
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
 *     before issue #3241, structured paths still depended on the fallback key-
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
 *     (issue #3259) — the shortcode-attribute twins of unregistered_block_attr
 *     / unrewritten_registered_ref above, same two-way split (no rule at
 *     all for an id-shaped attribute name, vs. a rule exists but the value
 *     is still numeric — the declared rewrite never ran), scoped to
 *     shortcode tags this engine has actually declared shortcode_attrs
 *     for (see scan_shortcodes()'s own docblock for why an unbounded "any
 *     shortcode on the system" scan isn't attempted).
 *
 *   unrewritten_url_query_ref (issue #3260) — a `?p=`/`?page_id=`/
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
 * `locator` is a JSON-ish pointer into that file (e.g. "meta.wprism_related
 * [1]", "options.sticky_posts[0]", "blocks.core/image.attrs.id",
 * "description[fr]"). `matches`, when present, is {kind, id, title,
 * post_type} — the same shape Pending::resolve_id() returns, reused
 * byte-for-byte rather than reinvented. `note` always carries the
 * class-specific honest caveat (never assume — small ids coincide).
 *
 * A ninth class, `proposed_lint_ok` (WP-2.4), is a bare_id on a custom-table
 * column whose LIVE MySQL TYPE — supplied by a `wprism-adapter-probe/v1` document,
 * never guessed — bounds the column's value space to {0,1}. It is a re-CLASS,
 * not a suppression: the row is still emitted, still counted, and still exits
 * 1, and it carries the type as its premise plus the exact declaration to paste
 * into the manifest. The exemption itself stays what it has always been — a
 * `lint_ok: true` an author writes and a reviewer reads (`manifests/
 * ninja-forms.json:24-25` is the hand-written precedent whose evidence-
 * gathering half this automates).
 *
 * WHERE THE SCAN'S NON-STATE INPUTS COME FROM: everything scan_tree() reads
 * that is not a byte of the state tree — the home URL, every id resolution,
 * the probe's column types, and whether this process can parse blocks or
 * shortcodes at all — arrives through one `LintEnvironment`. That is the whole
 * reason `wprism lint` (host, WordPress-free) and `wp wprism lint` (live) can be one
 * implementation with byte-identical findings: the host hands scan_tree() a
 * RECORDED environment, and nothing else about the call differs.
 */
final class Lint {
    /** Findings' `value` is truncated past this length for readability
     *  (opaque JSON-blob meta values, e.g. Elementor's, can be huge). */
    private const MAX_VALUE_LEN = 200;

    /**
     * The two scan classes that need WordPress's own parsers, named exactly
     * once so a caller printing `LintEnvironment::deferrals()` prints the
     * engine's words and not its own paraphrase. Neither can be recorded into
     * a `wprism-lint-environment/v1` transcript — see that class's docblock,
     * correction (3).
     */
    private const BLOCK_DEFERRAL = 'unregistered_block_attr / unrewritten_registered_ref (block attributes): '
        . 'WordPress parse_blocks() is unavailable in this process, so no block attribute was read. Run '
        . '`wp wprism lint` on the target for this class.';

    private const SHORTCODE_DEFERRAL = 'unregistered_shortcode_attr / unrewritten_registered_shortcode_ref: '
        . 'WordPress get_shortcode_regex()/shortcode_parse_atts() are unavailable in this process, so no shortcode '
        . 'attribute was read. Run `wp wprism lint` on the target for this class.';

    /**
     * `$env` is the one seam for every non-state input (see the class docblock).
     * It defaults to `LintEnvironment::live()`, which is exactly what this
     * method did inline before WP-2.4 — `get_option('home')` plus an
     * unmemoized `Pending::resolve_id()` per candidate — so every existing
     * caller's findings are byte-identical without passing anything.
     *
     * @return array<int, array{class:string, path:string, locator:string, value:mixed, matches?:array{kind:string,id:int,title:string,post_type:string}, note:string}>
     */
    public static function scan_tree(string $stateDir, Policy $policy, ?LintEnvironment $env = null): array {
        $stateDir = rtrim($stateDir, '/');
        if (!is_dir($stateDir)) {
            throw new \RuntimeException("wprism: state dir not found: $stateDir (nothing captured yet?)");
        }
        $env ??= LintEnvironment::live();
        // A replayed transcript describes ONE tree; this is where it says so,
        // before a single finding is computed from other bytes. Recording is a
        // no-op here — the tree being scanned is the tree being described.
        $env->assert_state_tree($stateDir);
        $home = $env->home();
        $homeEscaped = str_replace('/', '\/', $home);
        $blockRules = $policy->block_attr_rules();
        $shortcodeRules = $policy->shortcode_attr_rules();

        $findings = [];
        foreach (StateTreeWalker::files($stateDir) as $file) {
            $rel = $file['path'];
            switch ($file['surface']) {
                case 'post':
                    self::scan_post_file($stateDir, $rel, $policy, $blockRules, $shortcodeRules, $home, $homeEscaped, $env, $findings);
                    break;
                case 'term':
                    self::scan_term_file($stateDir, $rel, $policy, $home, $homeEscaped, $env, $findings);
                    break;
                case 'menu':
                    self::scan_menu_file($stateDir, $rel, $policy, $home, $homeEscaped, $env, $findings);
                    break;
                case 'sidebar':
                    self::scan_sidebar_file($stateDir, $rel, $policy, $blockRules, $home, $homeEscaped, $env, $findings);
                    break;
                case 'options':
                    self::scan_options_file($stateDir, $rel, $policy, $home, $homeEscaped, $env, $findings);
                    break;
                case 'user_meta':
                    self::scan_user_meta_file($stateDir, $rel, $policy, $home, $homeEscaped, $env, $findings);
                    break;
                case 'table':
                    self::scan_table_file($stateDir, $rel, $policy, $home, $homeEscaped, $env, $findings);
                    break;
                default:
                    throw new \LogicException('wprism: unknown canonical lint surface ' . $file['surface']);
            }
        }
        return $findings;
    }

    /**
     * The live environment, as a factory on the class both verbs already name.
     *
     * `wp wprism lint` reaches `LintEnvironment` only through here, and hands the
     * transcript back out as a plain array (`document()`), so `Cli.php` names
     * no `agent/src/Review` class it did not already name. That is not
     * cosmetic: `sandbox/tests/offline/cli/regress_cli_json_refusals.php`
     * pre-declares its own `WPrism\Canon` and `WPrism\Pending` stubs and then
     * `require`s `Cli.php`, so a new `require_once` in that file for a Review
     * class that pulls either one is an immediate "Cannot redeclare class"
     * fatal in an unrelated suite. One factory keeps `Cli.php`'s load set
     * exactly as it was.
     *
     * @param array<string,mixed>|null $probe a `wprism-adapter-probe/v1` document
     */
    public static function live_environment(?array $probe = null): LintEnvironment {
        return LintEnvironment::live($probe);
    }

    /**
     * The human rendering of a finding set, for every caller.
     *
     * Extracted from `Cli::lint()` verbatim (column widths, the `value=` and
     * ` matches=` spellings, the four-space note indent) because WP-2.4 gives
     * lint a SECOND caller — the WordPress-free `wprism lint-tree` host verb — and
     * two copies of a renderer is how two verbs that share an implementation
     * start printing different things about it. AGENTS.md rule 8 pins the
     * WP-CLI bytes; one renderer is what keeps them pinned across two callers
     * rather than two disciplines.
     *
     * The trailing blank line and the "N finding(s)" summary stay with each
     * caller: those are the caller's own envelope (`WP_CLI::warning()` on one
     * side, stderr on the other), not part of a finding.
     *
     * @param array<int,array<string,mixed>> $findings
     * @return list<string>
     */
    public static function render_lines(array $findings): array {
        return LintFinding::render_lines($findings);
    }

    private static function scan_user_meta_file(
        string $stateDir,
        string $rel,
        Policy $policy,
        string $home,
        string $homeEscaped,
        LintEnvironment $env,
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
                    isset($rule['key_refs']) ? (array) $rule['key_refs'] : null,
                    $env
                );
                continue;
            }
            foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                $hit = $env->resolve_id($id);
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
        LintEnvironment $env,
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
                    self::scan_widget_blocks($value, $blockRules, $rel, $home, $env, $findings);
                } elseif (($rule['ref'] ?? '') === 'term') {
                    foreach (Pending::numeric_candidates($value) as [$id, $suffix]) {
                        $findings[] = LintFinding::make(
                            'unrewritten_registered_ref', $rel, $locator . $suffix, $id,
                            $env->resolve_id($id),
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
        LintEnvironment $env,
        array &$findings
    ): void {
        $front = Canon::decode(Canon::read_file($stateDir . '/' . $rel));
        foreach (MenuReferenceScanner::scan(
            (array) ($front['items'] ?? []),
            $rel,
            [$policy, 'meta_rule_for_post'],
            $home,
            $homeEscaped,
            $env->resolver()
        ) as $finding) {
            $findings[] = $finding;
        }
    }

    // ------------------------------------------------------------ posts

    private static function scan_post_file(
        string $stateDir, string $rel, Policy $policy, array $blockRules, array $shortcodeRules,
        string $home, string $homeEscaped, LintEnvironment $env, array &$findings
    ): void {
        [$front, $body] = Canon::parse_post_file(Canon::read_file($stateDir . '/' . $rel));
        $postType = (string) ($front['type'] ?? '');
        $meta = (array) ($front['meta'] ?? []);

        self::scan_taxonomy_relationship_keyspaces(
            (array) ($front['terms'] ?? []), $rel, 'terms', 'post', $policy, $findings
        );
        $resolve = $env->resolver();

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
                    isset($rule['key_refs']) ? (array) $rule['key_refs'] : null,
                    $env
                );
                continue; // structured value: the deep scan above supersedes the shallow one below
            }
            foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                $hit = $resolve($id);
                if ($hit === null) {
                    continue;
                }
                $findings[] = LintFinding::make('bare_id', $rel, 'meta.' . $key . $locSuffix, $id, $hit, self::bare_id_note($hit));
            }
        }

        // (b) escaped_home / unrewritten_url_query_ref — recursively
        // through meta values, and the raw body as one unit.
        StateTreeWalker::strings($meta, 'meta', function (string $path, string $s) use (&$findings, $rel, $home, $homeEscaped, $shortcodeRules, $env) {
            self::flag_escaped_home($findings, $rel, $path, $s, $home, $homeEscaped);
            self::flag_unrewritten_url_query_ref($findings, $rel, $path, $s, $env);
            self::scan_shortcodes($s, $shortcodeRules, $rel, $env, $findings, $path);
        });
        self::flag_escaped_home($findings, $rel, 'body', $body, $home, $homeEscaped);
        self::flag_unrewritten_url_query_ref($findings, $rel, 'body', $body, $env);

        // (c) unregistered_block_attr / (e) shortcode findings — block/
        // shortcode content only (verbatim bodies, e.g. acf-field, aren't
        // block or shortcode content at all — Capture::build_post() itself
        // skips Blocks::capture_rewrite() for them, so nothing would ever
        // have rewritten a shortcode ref there either; same gate both scans share).
        if ($body !== '' && $policy->body_mode($postType) === 'blocks') {
            if ($env->parses_blocks()) {
                self::scan_blocks(parse_blocks($body), $blockRules, $rel, $home, $env, $findings);
            } else {
                $env->defer(self::BLOCK_DEFERRAL);
            }
            self::scan_shortcodes($body, $shortcodeRules, $rel, $env, $findings);
        }

        // (d) unrewritten_registered_ref inside a `json` body (WP-6.5). The
        // measured gap this closes: on the 2026-08-25 recon site four true
        // cross-entity references existed and `wp wprism lint` found TWO — both
        // block attributes — because nothing looked inside a JSON post_content
        // at all. Needs no live environment beyond the id resolver: the body is
        // already in captured state, so this is a pure read of what the
        // declared rewrite did or did not do.
        if ($body !== '' && $policy->body_mode($postType) === BodyRefGrammar::BODY_MODE) {
            self::scan_body_refs($body, $policy->body_ref_rule($postType) ?? [], $rel, $env, $findings);
        }
    }

    /**
     * The `json` body twin of scan_blocks(): DECLARED reference paths only.
     *
     * The scoping is a measurement, not a convenience, and it is the one place
     * this class deliberately does NOT reach for its own undeclared-key
     * heuristic. `StructuredReferenceScanner::scanUndeclared()` flags an
     * `id`/`*Id`-named key whose value resolves to a live entity, which is right
     * for an opaque meta blob and wrong for a form body: the same recon measured
     * `$.field_id` (a next-field-id ALLOCATOR, `"0"` on one write path and an
     * int on two others), `$.fields.<n>.id` (form-local field ids "1".."4", both
     * as the object key and as the member), and field ids embedded in prose
     * smart tags (`"replyto": "{field_id=\"2\"}"`). Every one is a small number
     * that collides with a real post id on any site and none is a reference, so
     * the heuristic would have produced three false findings per form — the
     * issue #3508 class of noise `Pending::ref_hint()`'s own guard exists to
     * suppress, reintroduced under a different name.
     *
     * @param array<string,mixed> $rule
     * @param list<array<string,mixed>> $findings
     */
    private static function scan_body_refs(
        string $body,
        array $rule,
        string $rel,
        LintEnvironment $env,
        array &$findings
    ): void {
        if (($rule['json_refs'] ?? []) === []) {
            return;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            // Not a finding here: an undecodable json body refuses at CAPTURE
            // with its own named diagnostic (BodyRefGrammar::decode()), and a
            // second differently-worded copy in the lint vocabulary would make
            // an operator reconcile two sentences about one fact.
            return;
        }
        $resolve = $env->resolver();
        foreach (BodyRefGrammar::reference_positions($decoded, $rule) as $position) {
            $value = $position['value'];
            if (is_string($value) && str_starts_with($value, '{{')) {
                continue; // the declared rewrite ran
            }
            foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                if ($id <= 0) {
                    continue;
                }
                $findings[] = LintFinding::make(
                    'unrewritten_registered_ref',
                    $rel,
                    'body' . $position['locator'] . $locSuffix,
                    $id,
                    $resolve($id),
                    "body_refs path '" . (string) $position['ref']['path'] . "' declares this value as a "
                        . (string) $position['ref']['kind'] . ' reference, but it is still numeric in captured '
                        . 'state — the declared rewrite to a {{...}} token never ran. This id is silently '
                        . 'environment-bound and will point at the wrong entity (or nothing) once ids diverge on '
                        . 'another environment.'
                );
            }
        }
    }

    /**
     * A widget setting whose codec is `blocks` (Lint.php's sidebar surface):
     * the same parse_blocks() boundary as a post body, stated once.
     */
    private static function scan_widget_blocks(
        string $value,
        array $blockRules,
        string $rel,
        string $home,
        LintEnvironment $env,
        array &$findings
    ): void {
        if (!$env->parses_blocks()) {
            $env->defer(self::BLOCK_DEFERRAL);
            return;
        }
        self::scan_blocks(parse_blocks($value), $blockRules, $rel, $home, $env, $findings);
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
        ?array $keyRefs = null,
        ?LintEnvironment $env = null
    ): void {
        $resolve = $env === null ? null : $env->resolver();
        foreach (StructuredReferenceScanner::scan($node, $rel, $locator, $jsonRefs, $keyRefs, $resolve) as $finding) {
            $findings[] = $finding;
        }
    }

    // ------------------------------------------------------------ blocks

    private static function scan_blocks(
        array $blocks,
        array $blockRules,
        string $rel,
        string $home,
        LintEnvironment $env,
        array &$findings
    ): void {
        foreach (BlockReferenceScanner::scan($blocks, $blockRules, $rel, $home, $env->resolver()) as $finding) {
            $findings[] = $finding;
        }
    }

    // ------------------------------------------------------------ shortcodes

    /**
     * issue #3259: the shortcode twin of scan_blocks() above — same two-class
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
     * declare" discipline issue #3259's own filing already committed to.
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
        LintEnvironment $env,
        array &$findings,
        string $locatorPrefix = ''
    ): void {
        if (!$env->parses_shortcodes()) {
            // Only a tree that COULD have carried a shortcode finding records
            // the deferral: the scanner's own first line returns early on
            // rules-empty / no `[` bodies (ShortcodeReferenceScanner.php:31),
            // so deferring unconditionally would report a limitation on trees
            // where this class had nothing to say either way.
            if ($shortcodeRules !== [] && str_contains($body, '[')) {
                $env->defer(self::SHORTCODE_DEFERRAL);
            }
            return;
        }
        foreach (ShortcodeReferenceScanner::scan($body, $shortcodeRules, $rel, $locatorPrefix, $env->resolver()) as $finding) {
            $findings[] = $finding;
        }
    }

    // ------------------------------------------------------------ terms

    private static function scan_term_file(string $stateDir, string $rel, Policy $policy, string $home, string $homeEscaped, LintEnvironment $env, array &$findings): void {
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
                    isset($rule['key_refs']) ? (array) $rule['key_refs'] : null,
                    $env
                );
                continue;
            }
            foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                $hit = $env->resolve_id($id);
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
                $descriptionRef['key_refs'],
                $env
            );
            return;
        }

        // Exact adapter interpreters may validate a native serialized term
        // description as owned data (Polylang language metadata). Let that
        // same policy decision suppress the generic opaque-serialization
        // detector, while retaining text-level escaped-home and URL checks.
        // Capture and Apply call this resolver too, so one declaration owns
        // the boundary instead of three divergent exemptions.
        if ($policy->taxonomy_description_lint_rule($taxonomy, $desc) !== null) {
            if (is_string($desc) && $desc !== '') {
                self::flag_escaped_home($findings, $rel, 'description', $desc, $home, $homeEscaped);
                self::flag_unrewritten_url_query_ref($findings, $rel, 'description', $desc, $env);
            }
            return;
        }

        if (!is_string($desc) || $desc === '') {
            return;
        }

        // (b) escaped_home / unrewritten_url_query_ref
        self::flag_escaped_home($findings, $rel, 'description', $desc, $home, $homeEscaped);
        self::flag_unrewritten_url_query_ref($findings, $rel, 'description', $desc, $env);

        foreach (SerializedTermDescriptionScanner::scan($desc, $taxonomy, $rel, $env->resolver()) as $finding) {
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

    private static function scan_options_file(string $stateDir, string $rel, Policy $policy, string $home, string $homeEscaped, LintEnvironment $env, array &$findings): void {
        $options = OptionState::values((array) Canon::decode(Canon::read_file($stateDir . '/' . $rel)));

        // (a) bare_id
        foreach ($options as $key => $value) {
            $rule = $policy->option_rule((string) $key) ?? [];
            if (!empty($rule['sub_keys'])) {
                // issue #3233: the SAME "declared paths clean, undeclared
                // positions in the same structure still flagged" contract,
                // nested one level — each NAMED sub-key carries its own
                // rule (json_refs/key_refs/ref/lint_ok), exactly like a
                // whole option would, so this recurses the identical checks
                // scan_options_file() already runs, once per declared
                // sub-key, instead of the single flat check below.
                self::scan_option_sub_keys($value, (array) $rule['sub_keys'], $rel, (string) $key, $env, $findings);
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
                    isset($rule['key_refs']) ? (array) $rule['key_refs'] : null,
                    $env
                );
                continue; // structured value: the deep scan above supersedes the shallow one below
            }
            foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                if ($id === 0 || $id === 1) {
                    // issue #3508's guard on Pending::ref_hint() (Pending.php:315-329)
                    // suppresses a value that is WHOLLY 0/1 -- a boolean flag can
                    // never be a reference -- but only for a scalar row read whole.
                    // numeric_candidates()'s array branch (Pending.php:406-413)
                    // walks INTO an array-shaped option one element at a time, so
                    // the identical boolean-flag shape recurs one level down and
                    // that guard never sees it: measured live on
                    // options.wpforms_settings[modern-markup] -- the '1' of
                    // s:13:"modern-markup";s:1:"1" -- which BLOCKS capture under
                    // LintTrustGate for an uncertified out-of-tree adapter
                    // (LintTrustGate.php:16, PUBLIC_MESSAGE). Deliberately NOT
                    // pushed into Pending::numeric_candidates() itself: that
                    // function is shared with eight OTHER Lint::scan_tree() call
                    // sites (Pending.php:322-325) where a bare 1 sitting inside a
                    // larger structure is a genuine candidate; this option scan is
                    // the one measured to collide, so only it is corrected. Mirrors
                    // Pending::ref_hint()'s exact posture and its documented cost:
                    // a real id genuinely stored as 1 in some OTHER array element
                    // is swallowed the same way a whole-value '1' option already
                    // is, id === 0 kept alongside id === 1 for the same reason
                    // ref_hint() checks both.
                    continue;
                }
                $hit = $env->resolve_id($id);
                if ($hit === null) {
                    continue;
                }
                $findings[] = LintFinding::make('bare_id', $rel, 'options.' . $key . $locSuffix, $id, $hit, self::bare_id_note($hit));
            }
        }

        // (b) escaped_home / unrewritten_url_query_ref
        StateTreeWalker::strings($options, 'options', function (string $path, string $s) use (&$findings, $rel, $home, $homeEscaped, $env) {
            self::flag_escaped_home($findings, $rel, $path, $s, $home, $homeEscaped);
            self::flag_unrewritten_url_query_ref($findings, $rel, $path, $s, $env);
        });
    }

    /** @see scan_options_file()'s sub_keys branch */
    private static function scan_option_sub_keys($value, array $subKeys, string $rel, string $optionName, LintEnvironment $env, array &$findings): void {
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
                    isset($subRule['key_refs']) ? (array) $subRule['key_refs'] : null,
                    $env
                );
                continue;
            }
            foreach (Pending::numeric_candidates($subVal) as [$id, $locSuffix]) {
                $hit = $env->resolve_id($id);
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
     *
     * WP-2.4 adds the TYPE axis to the undeclared-column case, and only here:
     * a column is the one lint surface whose value space is described by a
     * live schema. When the environment carries a probed MySQL type that
     * bounds the column to {0,1}, the collision is re-classed
     * `proposed_lint_ok` — same row, same count, same exit code, plus the type
     * as its premise and the declaration to paste. See proposal_note().
     */
    private static function scan_table_file(string $stateDir, string $rel, Policy $policy, string $home, string $homeEscaped, LintEnvironment $env, array &$findings): void {
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
            $columnType = $env->column_type($table, (string) $col);
            $premise = LintEnvironment::boolean_domain_premise($columnType);
            foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                $hit = $env->resolve_id($id);
                if ($hit === null) {
                    continue;
                }
                if ($premise !== null) {
                    $findings[] = LintFinding::make(
                        'proposed_lint_ok',
                        $rel,
                        "columns.$col" . $locSuffix,
                        $id,
                        $hit,
                        self::proposal_note($table, (string) $col, (string) $columnType, $premise, $hit)
                    );
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
        StateTreeWalker::strings($columns, 'columns', function (string $path, string $s) use (&$findings, $rel, $home, $homeEscaped, $env) {
            self::flag_escaped_home($findings, $rel, $path, $s, $home, $homeEscaped);
            self::flag_unrewritten_url_query_ref($findings, $rel, $path, $s, $env);
        });
        StateTreeWalker::strings($meta, 'meta', function (string $path, string $s) use (&$findings, $rel, $home, $homeEscaped, $env) {
            self::flag_escaped_home($findings, $rel, $path, $s, $home, $homeEscaped);
            self::flag_unrewritten_url_query_ref($findings, $rel, $path, $s, $env);
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
                    isset($rule['key_refs']) ? (array) $rule['key_refs'] : null,
                    $env
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
                        $env->resolve_id($id),
                        "attached-meta key '$key' declares a {$rule['ref']} reference, but it is still numeric "
                            . 'in captured state instead of a portable token'
                    );
                }
                continue;
            }
            $unstructured[$key] = $value;
        }
        self::scan_structured_bare_ids($unstructured, $rel, 'meta', $findings, [], null, $env);
    }

    /**
     * The `proposed_lint_ok` note: the finding, its premise, and the exact
     * declaration a reviewer would write if they accept it.
     *
     * Three properties are deliberate. (1) The collision is stated FIRST and
     * in full, exactly as `bare_id` states it — a proposal that led with its
     * conclusion would read as a verdict. (2) The premise is quoted from
     * `LintEnvironment::BOOLEAN_DOMAIN`, so a `tinyint(1)` proposal carries its
     * own weakness rather than borrowing `bit(1)`'s confidence. (3) The note
     * ends in the declaration text, because the whole point is to make the
     * reviewer's act cheap — not to perform it for them. Nothing in this
     * engine ever writes that declaration; `manifests/*.json` bytes are
     * adapter identity (AGENTS.md rule 2) and move only by a human's edit.
     *
     * @param array{kind:string,id:int,title:string,post_type:string} $hit
     */
    private static function proposal_note(string $table, string $column, string $type, string $premise, array $hit): string {
        return sprintf(
            "table '%s' column '%s' has no ref declared; the number coincides with an existing %s id (#%d \"%s\", "
            . '%s) on this environment. PROPOSED EXEMPTION: the live column type is %s — %s. This is a proposal '
            . 'carrying its premise, not a verdict and not a silence: the finding is still reported and still '
            . 'counted. If you agree, the reviewed declaration is tables.%s.columns.%s = {"class": "authored", '
            . '"lint_ok": true} in the owning manifest, which exempts the COLUMN rather than today\'s collision.',
            $table,
            $column,
            $hit['kind'],
            $hit['id'],
            $hit['title'],
            $hit['post_type'],
            $type,
            $premise,
            $table,
            $column
        );
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
     * issue #3260: a `?p=`/`?page_id=`/`?attachment_id=` query-string
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
    private static function flag_unrewritten_url_query_ref(array &$findings, string $rel, string $locator, string $s, LintEnvironment $env): void {
        if ($s === '' || (!str_contains($s, '?') && !str_contains($s, '&'))) {
            return;
        }
        if (!preg_match_all('/[?&](p|page_id|attachment_id)=(\d+)/', $s, $matches, PREG_SET_ORDER)) {
            return;
        }
        foreach ($matches as $i => $m) {
            $id = (int) $m[2];
            $hit = $env->resolve_id($id);
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
