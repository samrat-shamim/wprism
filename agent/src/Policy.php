<?php
namespace Duo;

/**
 * Layered classification policy: site policy overrides > pinned manifests
 * (in pin order) > option name-patterns. Anything unmatched is unclassified,
 * and unclassified is a loud abort at the call sites (never a silent guess).
 */
final class Policy {
    public array $site = [];
    /** @var array<int, array> */
    public array $manifests = [];
    /** @var array<string, object>|null lazily-built interpreter instances */
    private ?array $interpreterInstances = null;

    /**
     * Interpreter contract. An interpreter is a class with
     *   post_meta_rule(string $key, array $allMeta): ?array
     * returning a classification rule (same shape as manifest post_meta rules,
     * optionally with 'cast') or null to defer. Manifests opt in via
     * {"interpreter": "<name>"} — for schema-driven plugins (ACF) whose meta
     * semantics live in data, not in a static key list.
     *
     * Interpreter CODE is part of the manifest artifact, never the engine:
     * a declared name resolves to <manifests_dir>/interpreters/<name>.php,
     * which must define \Duo\Interpreters\<CamelCase(name)>. The engine holds
     * only this loading contract — no plugin names, no plugin logic. Trust
     * boundary: the manifests dir is operator-controlled and ships/mounts
     * with the agent itself (ro in the sandbox), so loading PHP from it is
     * the same trust decision as running the agent.
     */

    public static function manifests_dir(): string {
        $env = getenv('DUO_MANIFESTS_DIR');
        if ($env && is_dir($env)) {
            return $env;
        }
        $local = dirname(__DIR__, 2) . '/manifests';
        if (is_dir($local)) {
            return $local;
        }
        return '/duo-manifests';
    }

    public static function load(?string $repo, ?array $manifestNames = null): self {
        $p = new self();
        if ($repo !== null) {
            $siteFile = rtrim($repo, '/') . '/site.duo.json';
            if (!is_file($siteFile)) {
                throw new \RuntimeException("duo: $siteFile not found (not a duo site repo?)");
            }
            $p->site = Canon::decode(Canon::read_file($siteFile));
        }
        $names = $manifestNames ?? ($p->site['manifests'] ?? ['core']);
        $dir = self::manifests_dir();
        foreach ($names as $name) {
            $file = $dir . '/' . basename($name) . '.json';
            if (!is_file($file)) {
                throw new \RuntimeException("duo: manifest '$name' not found in $dir");
            }
            $manifest = Canon::decode(Canon::read_file($file));
            self::validate_field_classes($manifest);
            $p->manifests[] = $manifest;
        }
        return $p;
    }

    /**
     * Pattern-fallback manifest arrays, keyed by the section they apply to.
     * `option_patterns` predates this map (kept as its original name for
     * backward compat with shipped manifests, e.g. core.json's
     * `^_transient_` rule); `meta_patterns` is new (task #11 wave 2 /
     * docs/frontier/elementor.md's finding: "post_meta/term_meta
     * classification has no pattern-matching escape hatch" — Elementor's
     * `_elementor_migrations_state_<hash>` is exactly the versioned-suffix
     * shape that needs it). Deliberately NOT post-type-scoped, unlike the
     * report's own suggestion: exact-match post_meta/term_meta rules
     * already aren't post-type-scoped in this engine (Policy::rule() has
     * never taken a post type), so a pattern fallback that suddenly needed
     * one would be a new, inconsistent axis rather than "mirroring
     * option_patterns" — a meta key name is either safe to classify by
     * pattern everywhere it appears, or it isn't; a plugin's own key-naming
     * convention already makes collisions with an unrelated plugin's keys
     * exceedingly unlikely, the same trust the exact-match case already
     * extends. term_meta gets the same fallback for free, at zero extra
     * cost, since it shares this one lookup path.
     *
     * @var array<string, string>
     */
    private const PATTERN_KEYS = [
        'options' => 'option_patterns',
        'post_meta' => 'meta_patterns',
        'term_meta' => 'meta_patterns',
    ];

    private function rule(string $section, string $name): ?array {
        $sitePolicy = $this->site['policy'][$section][$name] ?? null;
        if ($sitePolicy !== null) {
            return $sitePolicy;
        }
        foreach ($this->manifests as $m) {
            if (isset($m[$section][$name])) {
                return $m[$section][$name];
            }
        }
        $patternKey = self::PATTERN_KEYS[$section] ?? null;
        if ($patternKey !== null) {
            foreach ($this->manifests as $m) {
                foreach ($m[$patternKey] ?? [] as $pat) {
                    if (preg_match('/' . $pat['match'] . '/', $name)) {
                        return array_diff_key($pat, ['match' => true]);
                    }
                }
            }
        }
        return null;
    }

    public function option_rule(string $name): ?array {
        return $this->rule('options', $name);
    }

    public function post_meta_rule(string $key): ?array {
        return $this->rule('post_meta', $key);
    }

    public function term_meta_rule(string $key): ?array {
        return $this->rule('term_meta', $key);
    }

    public function table_rule(string $unprefixedTable): ?array {
        return $this->rule('tables', $unprefixedTable);
    }

    /** Option names classified authored (the capture whitelist). */
    public function authored_options(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['options'] ?? [] as $name => $r) {
                if (($r['class'] ?? '') === 'authored') {
                    $out[$name] = $r;
                }
            }
        }
        foreach ($this->site['policy']['options'] ?? [] as $name => $r) {
            if (($r['class'] ?? '') === 'authored') {
                $out[$name] = $r;
            } else {
                unset($out[$name]);
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Every declared table rule, keyed by unprefixed table name, merged
     * across manifests (last pinned manifest declaring a given table wins —
     * same enumeration precedence as authored_options()/block_attr_rules(),
     * a different precedence than the single-name lookup table_rule()/
     * rule() use, which is an existing, pre-existing inconsistency in this
     * class, not one this method introduces) with site policy overrides
     * applied last. Snapshot.php filters this by `class` itself (row-shaped
     * "authored_snapshot" vs attached-meta "authored_snapshot_meta" vs the
     * honest-intent-only "authored_typed_snapshot_post_v1" markers that have
     * no engine effect) — this accessor just answers "what did every pinned
     * manifest + this site's own policy say about tables," mirroring
     * authored_options()'s shape for the tables section.
     */
    public function declared_tables(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['tables'] ?? [] as $name => $r) {
                $out[$name] = $r;
            }
        }
        foreach ($this->site['policy']['tables'] ?? [] as $name => $r) {
            $out[$name] = $r;
        }
        return $out;
    }

    /** blockName => list of {path, kind, type} rules, merged across manifests. */
    public function block_attr_rules(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['block_attrs'] ?? [] as $block => $rules) {
                $out[$block] = $rules;
            }
        }
        return $out;
    }

    /**
     * `taxonomies.<tax>.description_refs` (spec v0.8 / docs/frontier/
     * polylang.md's "typed serialized-description rewriting"): declares
     * that a taxonomy's term_taxonomy.description column holds PHP-
     * serialized data (Polylang's post_translations/term_translations
     * `{lang_slug: local_id}` shape, verified byte-for-byte) with ref-typed
     * values reachable via the ordinary json_refs primitive at path "$.*"
     * (Capture::term_description() / Apply::encode_description() own
     * deciding how to (un)serialize; this only returns the declared rule).
     *
     * Manifest-only, first declaration in pin order wins — same precedence
     * as block_attr_rules()/rebuilders()/delete_guards(): a structural fact
     * about the taxonomy's OWN data shape (like block_attrs is a structural
     * fact about a block type's shape), not a site-local policy choice, so
     * — unlike options/post_meta/term_meta — there is no site.duo.json
     * policy override. This also sidesteps a real naming collision:
     * site.duo.json's policy.taxonomies is already the flat taxonomy-scope
     * LIST (Policy::taxonomies() below); reusing that key for a name-keyed
     * rule map would silently shadow it instead of erroring, since PHP's
     * array access on a list by an unknown string key just returns null.
     *
     * @return ?array {"kind": "post"|"term"}
     */
    public function description_refs_for_taxonomy(string $tax): ?array {
        foreach ($this->manifests as $m) {
            if (isset($m['taxonomies'][$tax]['description_refs'])) {
                return $m['taxonomies'][$tax]['description_refs'];
            }
        }
        return null;
    }

    public function post_types(): array {
        return $this->site['policy']['post_types'] ?? ['post', 'page', 'attachment'];
    }

    /**
     * taxonomy_patterns (task #92): dynamic-taxonomy-NAME scope, the
     * mirror-in-INTENT (not in mechanism) of option_patterns/meta_patterns.
     * Deliberately NOT added to PATTERN_KEYS/rule() above: that map's shape
     * is "classify a single key some OTHER enumeration already produced"
     * (an options/post_meta row is discovered some other way, THEN
     * classified by pattern); taxonomy scope has no outer enumeration to
     * piggyback on — answering "which taxonomy NAMES are in scope" is
     * itself the job, so the pattern consultation has to happen inside
     * taxonomies() below, a structurally different shape by necessity, not
     * an inconsistency with the existing mechanism.
     *
     * @return array<int, array{match:string, object_type:string[]}>
     */
    public function taxonomy_pattern_rules(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['taxonomy_patterns'] ?? [] as $pat) {
                $out[] = [
                    'match' => (string) $pat['match'],
                    'object_type' => array_values((array) ($pat['object_type'] ?? [])),
                ];
            }
        }
        return $out;
    }

    /**
     * The declared object_type for the first taxonomy_pattern matching
     * $tax, or null. Consulted by Capture's/Apply's taxes_by_object_type()
     * ONLY as a fallback when get_taxonomy() fails — WooCommerce registers
     * pa_* taxonomies from a DB table read on `init`, which already ran
     * before Snapshot's own phase-1 write of that table's row this same
     * request/apply — so a taxonomy_patterns-matched name can be genuinely
     * in scope (its term_taxonomy rows exist, found live) without being
     * registered yet THIS request. A manifest-declared object_type is a
     * fact about the PLUGIN's own registration code (confirmed against
     * WooCommerce's actual source for pa_*: object_type defaults to
     * `['product']`), sidestepping the need for get_taxonomy() to have
     * caught up. get_taxonomy() stays authoritative whenever it succeeds —
     * this is a narrow fallback for one specific timing gap, never a
     * general override.
     */
    public function pattern_object_type(string $tax): ?array {
        foreach ($this->taxonomy_pattern_rules() as $pat) {
            if (preg_match('/' . $pat['match'] . '/', $tax)) {
                return $pat['object_type'];
            }
        }
        return null;
    }

    /**
     * The full in-scope taxonomy list: site.duo.json's exact
     * `policy.taxonomies` PLUS every taxonomy name actually present in
     * wp_term_taxonomy that matches a manifest's taxonomy_patterns regex
     * (WooCommerce's pa_* — task #92). Empirically confirmed live (not
     * just reasoned) which source is timing-safe: after a raw-SQL insert
     * into wp_woocommerce_attribute_taxonomies (Snapshot's own phase 1),
     * get_taxonomy('pa_x') still returns false for the REST of that SAME
     * request/process, but a term_taxonomy row for the new taxonomy (ALSO
     * written raw-SQL in phase 1, unconditionally, no registration check)
     * is immediately visible to a live SELECT DISTINCT — so expansion
     * reads LIVE TABLE DATA, never get_taxonomies()'s in-memory registry,
     * which is exactly the thing that's stale mid-request.
     *
     * SCOPE-GATED, not a blanket widen: only names that match a DECLARED
     * pattern are ever added to the exact list — never every distinct
     * taxonomy the database happens to hold. This is the identical posture
     * task #73 established for ref-typed options (a real-but-out-of-scope
     * target aborts loudly rather than silently entering canonical state);
     * silently widening scope to "whatever's in the database" would be the
     * same failure class in the opposite direction, and is deliberately
     * not what this does. When no manifest declares taxonomy_patterns,
     * this method's behavior (and its DB query) is byte-for-byte unchanged
     * from before task #92 — an empty pattern list is a fast exact-return,
     * no query at all, so every manifest that doesn't use this pays zero
     * cost.
     *
     * This is the one deliberate exception to this class's DB-free-ness
     * elsewhere (Snapshot.php's own docblock states that purity as a
     * layering principle): unlike a post_meta/option KEY (already
     * enumerated by its caller before Policy::rule() is ever consulted),
     * the taxonomy SCOPE LIST has no outer enumeration of its own to
     * piggyback on. Centralizing the live-DB expansion HERE — rather than
     * duplicating a DISTINCT-query-and-filter snippet at every one of
     * taxonomies()'s several call sites in Capture.php/Apply.php — means
     * every caller (scope_terms(), taxes_by_object_type()'s input,
     * Capture's unscoped-ref scope check, Apply::rebuild()'s recount list)
     * gets pattern support for free with zero changes of their own beyond
     * this one method.
     */
    public function taxonomies(): array {
        $exact = $this->site['policy']['taxonomies'] ?? ['category', 'post_tag'];
        $patterns = $this->taxonomy_pattern_rules();
        if (!$patterns) {
            return $exact;
        }
        global $wpdb;
        $live = $wpdb->get_col("SELECT DISTINCT taxonomy FROM {$wpdb->term_taxonomy}") ?: [];
        $matched = [];
        foreach ($live as $tax) {
            if (in_array($tax, $exact, true)) {
                continue;
            }
            foreach ($patterns as $pat) {
                if (preg_match('/' . $pat['match'] . '/', $tax)) {
                    $matched[] = $tax;
                    break;
                }
            }
        }
        return array_values(array_unique(array_merge($exact, $matched)));
    }

    /**
     * option_name_refs (task #93): options discovered by NAME PATTERN, not
     * exact-key whitelist — for options whose NAME embeds another declared
     * table's local id (WooCommerce's woocommerce_<method_id>_<instance_id>
     * _settings, instance_id being a woocommerce_shipping_zone_methods
     * row's own pk). Pure manifest merge (flat concatenated list, manifest
     * pin order then declaration order — same first-match-wins semantics
     * as the PATTERN_KEYS fallback loop in rule() above); the live
     * wp_options NAME scan this declares is Capture::build_options()'s job,
     * not this accessor's — mirrors declared_tables()/block_attr_rules()'s
     * existing split between "what did manifests declare" (pure, here) and
     * "what do we do about it against a live environment" (the DB-touching
     * caller).
     *
     * A DELIBERATE sibling of option_patterns, not a variant of it:
     * option_patterns is consulted only to CLASSIFY a key some other
     * enumeration already produced (Policy::rule()'s fallback loop);
     * Capture::build_options() is exact-whitelist-only and NEVER consults
     * option_patterns for DISCOVERY (confirmed by reading it — r1b-shop.md's
     * own finding). option_name_refs entries drive their OWN discovery scan
     * because these rows are otherwise invisible to every existing option
     * mechanism.
     *
     * @return array<int, array{match:string, id_kind:string, class:string, json_refs?:array, key_refs?:array}>
     */
    public function option_name_ref_rules(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['option_name_refs'] ?? [] as $rule) {
                $out[] = $rule;
            }
        }
        return $out;
    }

    /**
     * The first option_name_refs rule whose `match` regex matches
     * $realOptionName (a name with any embedded id already in its REAL,
     * numeric form — never a token) — or null. Shared by Capture's
     * discovery pass (matching a live wp_options row's actual name) and
     * Apply's apply-direction path (matching the DETOKENIZED name, i.e.
     * after splicing the resolved local id back in) — same regex, same
     * semantics, both directions, so capture and apply can never disagree
     * about which rows this mechanism owns.
     */
    public function match_option_name_ref(string $realOptionName): ?array {
        foreach ($this->option_name_ref_rules() as $rule) {
            if (preg_match('/' . $rule['match'] . '/', $realOptionName)) {
                return $rule;
            }
        }
        return null;
    }

    /** @return array<string, object> */
    private function interpreters(): array {
        if ($this->interpreterInstances !== null) {
            return $this->interpreterInstances;
        }
        $this->interpreterInstances = [];
        foreach ($this->manifests as $m) {
            $name = $m['interpreter'] ?? null;
            if ($name === null || isset($this->interpreterInstances[$name])) {
                continue;
            }
            if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
                throw new \RuntimeException("duo: manifest '{$m['name']}' declares invalid interpreter name '$name'");
            }
            $file = self::manifests_dir() . '/interpreters/' . $name . '.php';
            if (!is_file($file)) {
                throw new \RuntimeException(
                    "duo: manifest '{$m['name']}' wants interpreter '$name' but $file is missing — "
                    . 'interpreter code ships with its manifest, not the engine'
                );
            }
            require_once $file;
            $class = '\\Duo\\Interpreters\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
            if (!class_exists($class) || !method_exists($class, 'post_meta_rule')) {
                throw new \RuntimeException(
                    "duo: interpreter file $file must define $class with post_meta_rule(string, array): ?array"
                );
            }
            $this->interpreterInstances[$name] = new $class($this);
        }
        return $this->interpreterInstances;
    }

    /**
     * Post-context-aware meta classification: interpreters see the entity's
     * full meta map (shadow keys and all) and win over static rules.
     */
    public function meta_rule_for_post(string $key, array $allMeta): ?array {
        foreach ($this->interpreters() as $i) {
            $rule = $i->post_meta_rule($key, $allMeta);
            if ($rule !== null) {
                return $rule;
            }
        }
        return $this->post_meta_rule($key);
    }

    /**
     * 'blocks' (default: block-parser rewriting + URL tokenization) or
     * 'verbatim' (byte-preserved — for post types whose content is serialized
     * data, e.g. acf-field, where URL substitution would corrupt lengths).
     */
    public function body_mode(string $postType): string {
        foreach ($this->manifests as $m) {
            $mode = $m['post_types'][$postType]['body'] ?? null;
            if ($mode !== null) {
                return $mode;
            }
        }
        return 'blocks';
    }

    /**
     * 'early' post types finalize before everything else in apply phase 2:
     * definition CPTs (acf-field*) whose content interpreters read to type
     * OTHER entities' meta — declared ordering, never glob-alphabetical luck.
     */
    public function post_type_phase(string $postType): string {
        foreach ($this->manifests as $m) {
            $phase = $m['post_types'][$postType]['phase'] ?? null;
            if ($phase !== null) {
                return $phase;
            }
        }
        return 'normal';
    }

    /**
     * v1-supported post FIELD classification surface (task #88). A field
     * name must appear here before ANY manifest may declare it under
     * `post_types.<type>.fields.<field>` — validate_field_classes() below
     * enforces this at load() time, loudly, rather than silently ignoring
     * an unsupported declaration. Deliberately just 'title': it is the
     * only field with a proven self-healing precedent (WooCommerce's
     * product_variation, task #72's root cause). 'slug' is excluded on
     * purpose even though it's a plausible next case — a post's slug
     * participates in its canonical FILENAME and in collision/identity
     * checks (Apply::find_collision()), so "derived" would need to answer
     * questions (does the filename track the live value? does identity?)
     * this task never had to face. status/dates/menu_order/comment_status/
     * ping_status/excerpt have no self-healing precedent at all yet.
     * Widening this list is a deliberate, separate decision per field, not
     * a mechanical extension of the mechanism.
     */
    private const DERIVABLE_FIELDS = ['title'];

    /** @see DERIVABLE_FIELDS */
    private const FIELD_CLASSES = ['derived'];

    /**
     * Post-FIELD classification — NOT post_meta/options (Policy::rule()'s
     * 'post_meta'/'term_meta'/'options' sections), and not the same thing
     * as this class's own post_types()/body_mode()/post_type_phase()
     * either: post_types() is the site-policy SCOPE list (which types
     * capture at all), body_mode()/post_type_phase() classify how a whole
     * post TYPE behaves. This classifies one of the ~13 keys every post
     * FILE carries unconditionally (Capture::build_post()'s $front /
     * Apply::finalize_post()'s $wpdb->update() payload — title, slug,
     * status, dates, parent, menu_order, comment_status, ping_status,
     * excerpt) — fields no manifest could classify at all before task #88,
     * unlike meta/options which have supported `class: derived` from v0.
     *
     * The proven case: WC_Product_Variation_Data_Store_CPT::read() (task
     * #72's confirmed root cause) silently recomputes a variation's
     * post_title from the parent's attribute order + the variation's own
     * current attribute values on EVERY wc_get_product() load, writing it
     * via a raw $wpdb->update() specifically to dodge wp_update_post()/
     * save_post — hook-free, invisible to Apply's canary, no post_modified
     * bump. Two environments that have received a different number/timing
     * of ordinary WooCommerce-mediated reads can transiently disagree on
     * this ONE field's bytes while every authored input is identical.
     *
     * Manifest-only, first declaring manifest wins — same precedence as
     * body_mode()/post_type_phase() immediately above, for the identical
     * reason description_refs_for_taxonomy() gives for its own no-site-
     * override stance: this is a structural fact about how a PLUGIN's post
     * type behaves (a fact this manifest is asserting about WooCommerce's
     * own code), not a site-local policy choice. It also sidesteps the
     * same real naming collision body_mode()/post_type_phase() already
     * avoid: site.duo.json's policy.post_types is already the flat SCOPE
     * LIST post_types() reads above — a site-policy override here would
     * need a different key or silently shadow that list.
     *
     * Default 'authored': every field is authored unless a manifest says
     * otherwise, matching how every post type captures fully today with
     * zero manifest declarations. See DERIVABLE_FIELDS for what a manifest
     * may actually declare — anything else fails loudly at load() time,
     * never silently here.
     */
    public function field_class(string $postType, string $field): string {
        foreach ($this->manifests as $m) {
            $class = $m['post_types'][$postType]['fields'][$field]['class'] ?? null;
            if ($class !== null) {
                return $class;
            }
        }
        return 'authored';
    }

    /**
     * Loud, load-time guard for field_class()'s manifest input (mirrors
     * interpreters()'s "throw immediately, never degrade silently" posture
     * for a bad manifest declaration): a manifest naming an unsupported
     * field, or an unsupported class for a supported field, fails EVERY
     * command that loads this manifest (capture/plan/apply/lint/pending),
     * not just the specific post_type/field it misdeclares — task #88's
     * "start v1 scope tight" instruction, enforced structurally rather than
     * left as a convention. Called from load() for every manifest, so a
     * bad declaration can never reach field_class()'s per-post lookup.
     */
    private static function validate_field_classes(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ($manifest['post_types'] ?? [] as $postType => $decl) {
            foreach ($decl['fields'] ?? [] as $field => $rule) {
                if (!in_array($field, self::DERIVABLE_FIELDS, true)) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' declares post_types.$postType.fields.$field, but only "
                        . implode(', ', self::DERIVABLE_FIELDS) . ' may be field-classified in v1 (task #88 '
                        . 'scoped this deliberately tight — see Policy::DERIVABLE_FIELDS\' docblock)'
                    );
                }
                $class = $rule['class'] ?? null;
                if (!in_array($class, self::FIELD_CLASSES, true)) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' declares post_types.$postType.fields.$field.class="
                        . var_export($class, true) . ' but only ' . implode(', ', self::FIELD_CLASSES)
                        . ' is supported for post fields in v1'
                    );
                }
            }
        }
    }

    /** Manifest-declared rebuilders (wp-cli commands run in the rebuild pass). */
    public function rebuilders(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['rebuilders'] ?? [] as $r) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * docs/proposals/code-half.md §4.3's version_range mechanism: a manifest
     * may declare a top-level `"plugin"` (the plugin's basename, e.g.
     * "woocommerce/woocommerce.php" — the same string active_plugins/
     * get_plugins() key on) alongside `"version_range": {"min","max"}`
     * (min inclusive, max exclusive). Deliberately {min,max} + two
     * version_compare() calls, not a semver-range constraint string: the
     * agent is dependency-free (DESIGN.md §4 — "a drop-in agent must not
     * vendor libraries"), and a real semver-range parser is exactly the
     * dependency that rules out. First declaration in pin order wins per
     * plugin — same precedence as block_attr_rules()/rebuilders().
     *
     * No manifest declares this yet (no shipped plugin manifest names a
     * "plugin" key) — the mechanism is exercised by a fixture manifest in
     * the sandbox, not by pinning a real range on a live registry version.
     * Deploy::code_mismatch() / Apply::build_plan()'s code_mismatch bucket
     * are this accessor's only readers.
     *
     * @return array<string, array{min:string, max:string, manifest:string}> keyed by plugin basename
     */
    public function version_ranges(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            $plugin = $m['plugin'] ?? null;
            $range = $m['version_range'] ?? null;
            if (!is_string($plugin) || $plugin === '' || !is_array($range) || isset($out[$plugin])) {
                continue;
            }
            $out[$plugin] = [
                'min' => (string) ($range['min'] ?? '0'),
                'max' => (string) ($range['max'] ?? '999999999'),
                'manifest' => (string) ($m['name'] ?? '?'),
            ];
        }
        return $out;
    }

    /**
     * Referential delete guards, keyed "post:<post_type>" — each guard names a
     * table/column holding local ids that reference the entity; matching rows
     * block deletion at plan time.
     */
    public function delete_guards(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['delete_guards'] ?? [] as $key => $guards) {
                $out[$key] = array_merge($out[$key] ?? [], $guards);
            }
        }
        return $out;
    }

    private const SECTIONS = ['options', 'post_meta', 'term_meta'];
    private const CLASSES = ['authored', 'runtime', 'derived', 'env', 'managed'];
    private const CASTS = ['string', 'csv'];

    /**
     * Write one classification rule into site.duo.json's policy overrides
     * (`wp duo classify`'s only write path — DESIGN.md 3.1.5: "accepted
     * decisions persist to policy.yml/json"). Validates shape, then loads +
     * rewrites the file via Canon::encode so formatting stays canonical.
     */
    public static function set_rule(string $repo, string $section, string $key, array $rule): void {
        if (!in_array($section, self::SECTIONS, true)) {
            throw new \RuntimeException(
                'duo: unknown policy section \'' . $section . '\' (expected ' . implode('|', self::SECTIONS) . ')'
            );
        }
        if ($key === '') {
            throw new \RuntimeException('duo: policy key must not be empty');
        }
        $class = $rule['class'] ?? '';
        if (!in_array($class, self::CLASSES, true)) {
            throw new \RuntimeException(
                "duo: unknown class '$class' (expected " . implode('|', self::CLASSES) . ')'
            );
        }
        if (isset($rule['ref']) && !preg_match('/^(post|term|user)(\[\])?$/', (string) $rule['ref'])) {
            throw new \RuntimeException(
                "duo: invalid ref '{$rule['ref']}' (expected post|term|user, optionally suffixed with [])"
            );
        }
        if (isset($rule['cast']) && !in_array($rule['cast'], self::CASTS, true)) {
            throw new \RuntimeException("duo: invalid cast '{$rule['cast']}' (expected " . implode('|', self::CASTS) . ')');
        }
        if (isset($rule['allow_secret']) && !is_bool($rule['allow_secret'])) {
            throw new \RuntimeException('duo: allow_secret must be a boolean');
        }

        $siteFile = rtrim($repo, '/') . '/site.duo.json';
        if (!is_file($siteFile)) {
            throw new \RuntimeException("duo: $siteFile not found (not a duo site repo?)");
        }
        $site = Canon::decode(Canon::read_file($siteFile));
        $site['policy'][$section][$key] = $rule;
        Canon::write_file($siteFile, Canon::encode($site));
    }

    /**
     * Draft-manifest export (DESIGN.md 3.1.5: "accepted decisions ...
     * shareable upstream as draft manifests"): every rule in THIS site's own
     * policy overrides (not inherited manifest rules — the human is
     * promoting decisions they made) whose key matches $matchRegex, grouped
     * into a manifest-shaped {name, options, post_meta, term_meta}
     * structure. Reads site.duo.json; never writes it — promotion is a
     * deliberate, separate human act (`wp duo policy-to-manifest` only
     * prints to stdout).
     */
    public static function export_manifest(string $repo, string $matchRegex, string $name): array {
        $policy = self::load($repo);
        $sitePolicy = $policy->site['policy'] ?? [];

        $out = ['name' => $name, 'options' => [], 'post_meta' => [], 'term_meta' => []];
        foreach (self::SECTIONS as $section) {
            foreach ($sitePolicy[$section] ?? [] as $key => $rule) {
                $matched = @preg_match('/' . $matchRegex . '/', $key);
                if ($matched === false) {
                    throw new \RuntimeException("duo: invalid --match regex '$matchRegex'");
                }
                if ($matched === 1) {
                    $out[$section][$key] = $rule;
                }
            }
            $out[$section] = (object) $out[$section]; // force {} not [] when empty, matching manifest style
        }
        return $out;
    }
}
