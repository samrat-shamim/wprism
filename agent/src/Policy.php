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
    /** @var array<string, object>|null lazily-built regenerator instances (DUO-3234) */
    private ?array $regeneratorInstances = null;

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
            self::validate_scope_classes($p->site, 'site.duo.json', true);
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
            self::validate_regen_dependencies($manifest);
            self::validate_scope_classes($manifest, "manifest '$name'", false);
            self::validate_sub_keys($manifest);
            self::validate_adapter_contract($manifest);
            $p->manifests[] = $manifest;
        }
        self::validate_no_conflicting_adapter_claims($p->manifests);
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

    /**
     * Resolve a policy rule together with the declaration that won. Apply's
     * repository authorization gate needs the source as evidence: a refusal
     * that only says "runtime" but not whether site.duo.json or which pinned
     * manifest made that decision is not actionable enough to repair safely.
     *
     * @return array{rule:?array, source:?string}
     */
    private function rule_details(string $section, string $name): array {
        $sitePolicy = $this->site['policy'][$section][$name] ?? null;
        if ($sitePolicy !== null) {
            return ['rule' => $sitePolicy, 'source' => 'site.duo.json'];
        }
        foreach ($this->manifests as $m) {
            if (isset($m[$section][$name])) {
                return [
                    'rule' => $m[$section][$name],
                    'source' => (string) ($m['name'] ?? '?'),
                ];
            }
        }
        $patternKey = self::PATTERN_KEYS[$section] ?? null;
        if ($patternKey !== null) {
            foreach ($this->manifests as $m) {
                foreach ($m[$patternKey] ?? [] as $pat) {
                    if (preg_match('/' . $pat['match'] . '/', $name)) {
                        return [
                            'rule' => array_diff_key($pat, ['match' => true]),
                            'source' => (string) ($m['name'] ?? '?'),
                        ];
                    }
                }
            }
        }
        return ['rule' => null, 'source' => null];
    }

    private function rule(string $section, string $name): ?array {
        return $this->rule_details($section, $name)['rule'];
    }

    public function option_rule(string $name): ?array {
        return $this->rule('options', $name);
    }

    /** @return array{rule:?array, source:?string} */
    public function option_rule_details(string $name): array {
        return $this->rule_details('options', $name);
    }

    public function post_meta_rule(string $key): ?array {
        return $this->rule('post_meta', $key);
    }

    /** @return array{rule:?array, source:?string} */
    public function post_meta_rule_details(string $key): array {
        return $this->rule_details('post_meta', $key);
    }

    public function term_meta_rule(string $key): ?array {
        return $this->rule('term_meta', $key);
    }

    public function table_rule(string $unprefixedTable): ?array {
        return $this->rule('tables', $unprefixedTable);
    }

    /**
     * Snapshot consumes declared_tables(), whose established precedence is
     * last pinned manifest then site override. Report the source of that same
     * effective declaration; using rule_details() here would reproduce the
     * older first-manifest single-name inconsistency instead of the rule the
     * typed-snapshot writer actually follows.
     *
     * @return array{rule:?array, source:?string}
     */
    public function declared_table_details(string $name): array {
        $rule = null;
        $source = null;
        foreach ($this->manifests as $m) {
            if (isset($m['tables'][$name])) {
                $rule = $m['tables'][$name];
                $source = (string) ($m['name'] ?? '?');
            }
        }
        if (isset($this->site['policy']['tables'][$name])) {
            $rule = $this->site['policy']['tables'][$name];
            $source = 'site.duo.json';
        }
        return ['rule' => $rule, 'source' => $source];
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
     * Options declaring `sub_keys` (DUO-3233): name => full rule (including
     * the `sub_keys` map). Sibling enumeration to authored_options() above,
     * same merge precedence (site policy replaces a manifest's whole rule
     * wholesale, never a deep merge — a site overriding options.<name> is
     * expected to repeat sub_keys if it still wants any of it, exactly like
     * it already must repeat 'class' today) — but keyed on "declares
     * sub_keys" instead of "class === authored", because a sub_keys option's
     * OWN top-level class is legitimately something else (Polylang's
     * `polylang`/Yoast's `wpseo` are both 'env': excluded whole, except the
     * named sub-keys carved out below them).
     *
     * This is the engine capability manifests/polylang.json's own notes
     * long flagged as missing: "v0's options model classifies a whole
     * option name at once ... there is no way to keep force_lang/
     * default_lang/etc authored while excluding first_activation/version
     * without capturing them too. Building that sub-key classification
     * split is a genuinely separate, unscoped engine capability." This is
     * that capability — a NAMED sub-key of one option blob captured/
     * excluded independently, with apply-side merge into the live blob
     * (Apply::apply_option_sub_keys()) so the undeclared remainder is never
     * clobbered. DUO-3211's review comment asked for exactly this: "'exact'
     * [option] reconciliation should be written so per-key ownership can
     * later narrow to sub-key ownership without another format change" —
     * `sub_keys` on an ordinary options.<name> rule IS that narrowing, not
     * a parallel format.
     *
     * @return array<string, array{class:string, sub_keys:array<string,array>}>
     */
    public function sub_keyed_options(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['options'] ?? [] as $name => $r) {
                if (!empty($r['sub_keys'])) {
                    $out[$name] = $r;
                }
            }
        }
        foreach ($this->site['policy']['options'] ?? [] as $name => $r) {
            if (!empty($r['sub_keys'])) {
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
     * as block_attr_rules()/rebuilders()/deletion_capability(): a structural fact
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
        $exact = $this->site['policy']['post_types'] ?? ['post', 'page', 'attachment'];
        foreach ($this->site['policy']['scope']['post_type'] ?? [] as $name => $rule) {
            if (($rule['class'] ?? null) === 'authored') {
                $exact[] = (string) $name;
            }
        }
        return array_values(array_unique($exact));
    }

    /** @return string[] post types for which a pinned manifest declares a whole-type contract. */
    public function declared_post_types(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            $out = array_merge($out, array_keys($m['post_types'] ?? []));
        }
        return array_values(array_unique($out));
    }

    /** @return string[] taxonomies for which a pinned manifest declares a structural contract. */
    public function declared_taxonomies(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            $out = array_merge($out, array_keys($m['taxonomies'] ?? []));
        }
        return array_values(array_unique($out));
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
        foreach ($this->site['policy']['scope']['taxonomy'] ?? [] as $name => $rule) {
            if (($rule['class'] ?? null) === 'authored') {
                $exact[] = (string) $name;
            }
        }
        $exact = array_values(array_unique($exact));
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
     * Resolve a canonical option name containing one identity token against
     * option_name_refs without consulting a target ledger. Replacing the
     * token with a representative positive integer lets the declaration's
     * existing numeric-name regex decide ownership, while checking id_kind
     * separately prevents a hand-edited token of the wrong keyspace from
     * borrowing that authorization.
     *
     * @return array{rule:?array, source:?string}
     */
    public function canonical_option_name_ref_details(string $name): array {
        if (!preg_match('/\{\{([a-z][a-z0-9_]*):[0-9a-f-]{36}\}\}/', $name, $m)) {
            return ['rule' => null, 'source' => null];
        }
        $representative = str_replace($m[0], '1', $name);
        foreach ($this->manifests as $manifest) {
            foreach ($manifest['option_name_refs'] ?? [] as $rule) {
                if (($rule['id_kind'] ?? '') === $m[1]
                    && preg_match('/' . $rule['match'] . '/', $representative)) {
                    return [
                        'rule' => $rule,
                        'source' => (string) ($manifest['name'] ?? '?'),
                    ];
                }
            }
        }
        return ['rule' => null, 'source' => null];
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
     * DUO-3234 regenerator loading — the exact same trust boundary and
     * validate/load/instantiate shape as interpreters() above (same
     * rationale: manifest-shipped PHP, engine holds only the loading
     * contract, never plugin-specific logic), deliberately mirrored rather
     * than sharing code with interpreters(), for the same reason
     * assert_meta_schema() stays separate from assert_row_schema() in
     * Snapshot.php — the two mechanisms' discovery differs enough
     * (interpreters: one name per manifest, off a top-level `interpreter`
     * key; regenerators: potentially several names per manifest, one per
     * declaring post_types{} entry's `regen_dependency.regenerator`) that
     * a shared helper would need its own branching, buying nothing over two
     * short, independently-readable methods.
     *
     * A declared name resolves to <manifests_dir>/regenerators/<name>.php,
     * which must define \Duo\Regenerators\<CamelCase(name)> with
     * regenerate(int $localId): void. Any exception it throws is the
     * caller's (Apply::regen_dependencies()) hard-failure signal — there is
     * no success/failure return-value protocol, matching interpreters' own
     * all-or-throw shape.
     *
     * Public (unlike interpreters(), which only Policy's own methods call):
     * Apply::regen_dependencies() is the caller, in a different class.
     *
     * @return array<string, object> regenerator name => instance
     */
    public function regenerators(): array {
        if ($this->regeneratorInstances !== null) {
            return $this->regeneratorInstances;
        }
        $this->regeneratorInstances = [];
        foreach ($this->manifests as $m) {
            foreach ($m['post_types'] ?? [] as $postType => $decl) {
                $name = $decl['regen_dependency']['regenerator'] ?? null;
                if ($name === null || isset($this->regeneratorInstances[$name])) {
                    continue;
                }
                if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
                    throw new \RuntimeException(
                        "duo: manifest '{$m['name']}' post_types.$postType declares invalid regenerator name '$name'"
                    );
                }
                $file = self::manifests_dir() . '/regenerators/' . $name . '.php';
                if (!is_file($file)) {
                    throw new \RuntimeException(
                        "duo: manifest '{$m['name']}' post_types.$postType wants regenerator '$name' but $file is missing — "
                        . 'regenerator code ships with its manifest, not the engine'
                    );
                }
                require_once $file;
                $class = '\\Duo\\Regenerators\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
                if (!class_exists($class) || !method_exists($class, 'regenerate')) {
                    throw new \RuntimeException(
                        "duo: regenerator file $file must define $class with regenerate(int \$localId): void"
                    );
                }
                $this->regeneratorInstances[$name] = new $class($this);
            }
        }
        return $this->regeneratorInstances;
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

    /** @return array{rule:?array, source:?string} */
    public function meta_rule_details_for_post(string $key, array $allMeta): array {
        foreach ($this->interpreters() as $name => $i) {
            $rule = $i->post_meta_rule($key, $allMeta);
            if ($rule === null) {
                continue;
            }
            foreach ($this->manifests as $m) {
                if (($m['interpreter'] ?? null) === $name) {
                    return [
                        'rule' => $rule,
                        'source' => (string) ($m['name'] ?? '?') . " (interpreter $name)",
                    ];
                }
            }
            return ['rule' => $rule, 'source' => "interpreter $name"];
        }
        return $this->post_meta_rule_details($key);
    }

    /**
     * Give schema-driven interpreters the immutable repository tree before
     * authorization. ACF field definitions are themselves canonical posts;
     * priming from those files keeps a fresh target and an already-mapped
     * target from classifying the same payload differently merely because
     * only one target has the definitions in its database yet.
     */
    public function prime_interpreters_from_repository(array $tree): void {
        foreach ($this->interpreters() as $i) {
            if (method_exists($i, 'prime_repository')) {
                $i->prime_repository($tree);
            }
        }
    }

    /**
     * Optional offline cross-entity constraints supplied by the same pinned
     * interpreter artifact that already classifies schema-driven meta. This
     * is deliberately not a second extension loader or a plugin callback:
     * compiler constraints execute only manifest-shipped interpreter code,
     * and that interpreter's bytes are part of the compiled artifact hash.
     *
     * @return array<int,array<string,mixed>> stable compiler diagnostics
     */
    public function repository_constraint_diagnostics(array $tree): array {
        $out = [];
        foreach ($this->interpreters() as $name => $i) {
            if (!method_exists($i, 'repository_diagnostics')) {
                continue;
            }
            foreach ((array) $i->repository_diagnostics($tree) as $d) {
                if (!is_array($d)) {
                    throw new \RuntimeException("duo: interpreter '$name' returned a non-array repository diagnostic");
                }
                $d['adapter'] ??= $name;
                $out[] = $d;
            }
        }
        return $out;
    }

    /** @return array{rule:?array, source:?string} */
    public function post_type_rule_details(string $postType): array {
        $site = $this->site['policy']['scope']['post_type'][$postType] ?? null;
        if (is_array($site)) {
            return ['rule' => $site, 'source' => 'site.duo.json'];
        }
        foreach ($this->manifests as $m) {
            if (isset($m['post_types'][$postType]['class'])) {
                return [
                    'rule' => ['class' => $m['post_types'][$postType]['class']],
                    'source' => (string) ($m['name'] ?? '?'),
                ];
            }
        }
        return ['rule' => null, 'source' => null];
    }

    /** Whole-taxonomy disposition, parallel to post_type_rule_details().
     * A structural manifest declaration without an explicit class defaults
     * to authored: it names portable data the adapter understands, but the
     * site must still opt that taxonomy into its authored scope. */
    public function taxonomy_rule_details(string $taxonomy): array {
        $site = $this->site['policy']['scope']['taxonomy'][$taxonomy] ?? null;
        if (is_array($site)) {
            return ['rule' => $site, 'source' => 'site.duo.json'];
        }
        foreach ($this->manifests as $m) {
            if (isset($m['taxonomies'][$taxonomy])) {
                return [
                    'rule' => ['class' => (string) ($m['taxonomies'][$taxonomy]['class'] ?? 'authored')],
                    'source' => (string) ($m['name'] ?? '?'),
                ];
            }
        }
        return ['rule' => null, 'source' => null];
    }

    /**
     * Pure repository-side taxonomy authorization. taxonomies() expands
     * pattern matches from the live target database, which is right for
     * capture discovery but wrong for immutable-revision preflight: the same
     * repository must not pass on a mapped target and fail on a fresh one.
     *
     * @return array{authorized:bool, source:?string}
     */
    public function taxonomy_scope_details(string $taxonomy): array {
        if (in_array($taxonomy, $this->site['policy']['taxonomies'] ?? ['category', 'post_tag'], true)
            || (($this->site['policy']['scope']['taxonomy'][$taxonomy]['class'] ?? null) === 'authored')) {
            return ['authorized' => true, 'source' => 'site.duo.json'];
        }
        foreach ($this->manifests as $m) {
            foreach ($m['taxonomy_patterns'] ?? [] as $pat) {
                if (preg_match('/' . $pat['match'] . '/', $taxonomy)) {
                    return ['authorized' => true, 'source' => (string) ($m['name'] ?? '?')];
                }
            }
        }
        return ['authorized' => false, 'source' => null];
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
     * DUO-3234 — a post type's derived-table hard-dependency declaration, if
     * any: `{"regenerator": "<name>", "verify": {"table": "<t>", "column": "<c>"}}`.
     * v1 scope, stated loudly: post_types{}-keyed only (a per-post-type
     * property, matching the phase/fields precedents immediately above and
     * below — never a `tables{}` declaration, since the derived table itself
     * has no independent identity to declare; see agent/src/Snapshot.php's
     * own "gives no special meaning to any class value besides
     * authored_snapshot/authored_snapshot_meta" precedent, unchanged by
     * this). If a derived table ever hangs off a TERM or a declared
     * custom-table row instead of a post, that needs its own design — not
     * assumed covered here. First-declaring-manifest wins, same precedence
     * as its post_types{} siblings (phase/body) immediately around it, for
     * the same reason: consistency with how this section already resolves,
     * not an independently-chosen precedence rule for this one field.
     */
    public function regen_dependency(string $postType): ?array {
        foreach ($this->manifests as $m) {
            $decl = $m['post_types'][$postType]['regen_dependency'] ?? null;
            if ($decl !== null) {
                return $decl;
            }
        }
        return null;
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
        return $this->field_rule_details($postType, $field)['class'];
    }

    /** @return array{class:string, source:string} */
    public function field_rule_details(string $postType, string $field): array {
        foreach ($this->manifests as $m) {
            $class = $m['post_types'][$postType]['fields'][$field]['class'] ?? null;
            if ($class !== null) {
                return [
                    'class' => $class,
                    'source' => (string) ($m['name'] ?? '?'),
                ];
            }
        }
        return ['class' => 'authored', 'source' => 'repo-format'];
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

    /**
     * Validate `post_types.<type>.regen_dependency` shape at load time
     * (DUO-3234) — same "catch a bad declaration before it reaches a lookup
     * call site" posture as validate_field_classes() immediately above,
     * mirrored for its own key shape rather than extended, for the same
     * reason regenerators() doesn't share code with interpreters(). Checks
     * SHAPE only (required keys present, correct scalar types) — same as
     * validate_field_classes() never touches interpreter files, this never
     * touches the regenerator PHP file or class; that stays regenerators()'s
     * lazy-load-on-first-use job, so a manifest pinning a regen_dependency
     * declaration it never actually exercises this run pays no file-system
     * cost merely for being loaded.
     */
    private static function validate_regen_dependencies(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ($manifest['post_types'] ?? [] as $postType => $decl) {
            $regen = $decl['regen_dependency'] ?? null;
            if ($regen === null) {
                continue;
            }
            $regenerator = $regen['regenerator'] ?? null;
            if (!is_string($regenerator) || $regenerator === '') {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types.$postType.regen_dependency needs a non-empty string 'regenerator'"
                );
            }
            $verify = $regen['verify'] ?? null;
            if (!is_array($verify) || !is_string($verify['table'] ?? null) || ($verify['table'] ?? '') === ''
                || !is_string($verify['column'] ?? null) || ($verify['column'] ?? '') === '') {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types.$postType.regen_dependency needs "
                    . "verify: {table: <non-empty string>, column: <non-empty string>}"
                );
            }
        }
    }

    /** Validate whole-entity scope dispositions at policy load time. Site
     * rules live under policy.scope.{post_type,taxonomy}; manifests reuse
     * their existing post_types/taxonomies declarations. Invalid scope
     * input must fail every consumer, never turn into an implicit include
     * or exclusion. */
    private static function validate_scope_classes(array $source, string $label, bool $site): void {
        $groups = $site
            ? ($source['policy']['scope'] ?? [])
            : ['post_type' => $source['post_types'] ?? [], 'taxonomy' => $source['taxonomies'] ?? []];
        foreach (['post_type', 'taxonomy'] as $kind) {
            foreach ($groups[$kind] ?? [] as $name => $rule) {
                if (!is_string($name) || $name === '' || !is_array($rule)) {
                    throw new \RuntimeException("duo: $label has an invalid scope.$kind declaration");
                }
                $class = $rule['class'] ?? ($site ? null : 'authored');
                if (!in_array($class, self::SCOPE_CLASSES, true)) {
                    throw new \RuntimeException(
                        "duo: $label scope.$kind.$name.class=" . var_export($class, true)
                        . ' (expected ' . implode('|', self::SCOPE_CLASSES) . ')'
                    );
                }
            }
        }
    }

    /**
     * Loud, load-time guard for sub_keyed_options()'s manifest input (same
     * "throw immediately, never degrade silently" posture as
     * validate_field_classes() above — a bad sub_keys declaration must fail
     * every command that loads this manifest, not surface as a confusing
     * runtime shape error deep inside Capture/Apply). Two invariants:
     *   - sub_keys, when present, is a non-empty object of NAME => rule,
     *     and every named rule declares a recognized class;
     *   - class=authored and sub_keys are mutually exclusive on the SAME
     *     option rule: class=authored already captures the WHOLE value
     *     (authored_options()), so a manifest declaring both is stating two
     *     contradictory capture strategies for the same option name — the
     *     kind of ambiguous manifest state this project's posture (DESIGN.md
     *     3.1.5, "loud-and-blocking default") requires rejecting outright
     *     rather than silently picking one.
     */
    private static function validate_sub_keys(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ($manifest['options'] ?? [] as $optName => $rule) {
            $subKeys = $rule['sub_keys'] ?? null;
            if ($subKeys === null) {
                continue;
            }
            if (!is_array($subKeys) || !$subKeys) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares options.$optName.sub_keys but it is not a non-empty object"
                );
            }
            if (($rule['class'] ?? '') === 'authored') {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares options.$optName with BOTH class=authored and sub_keys — "
                    . 'these are mutually exclusive (class=authored already captures the WHOLE value; sub_keys '
                    . 'narrows independent capture to named keys of an otherwise-excluded blob). Pick one.'
                );
            }
            foreach ($subKeys as $subKey => $subRule) {
                if (!is_array($subRule) || !in_array($subRule['class'] ?? null, self::CLASSES, true)) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' declares options.$optName.sub_keys.$subKey with an invalid or "
                        . 'missing class (expected one of ' . implode('|', self::CLASSES) . ')'
                    );
                }
            }
        }
    }

    /**
     * DUO-3222: loud, load-time guard for the adapter compatibility
     * contract — same "throw immediately" posture as every validator
     * above. A manifest that names a plugin/theme without an exact,
     * well-formed version range is exactly the "unbounded support" this
     * issue's own non-negotiable constraint forbids ("No latest, wildcard,
     * or unbounded version support may be certified") —
     * Policy::version_ranges()'s own pre-existing behavior of silently
     * SKIPPING a plugin with no/malformed version_range (rather than
     * rejecting) is the failure mode DUO-3222 was filed to close, so this
     * validator now makes that combination a hard load-time error instead
     * of a silent no-op that would otherwise surface (if at all) only much
     * later, at deploy time.
     *
     * spec_version has ONE asymmetric rule, not simple presence/absence:
     * ABSENT is lenient (no shipped manifest declares it yet, and
     * DUO_SPEC_VERSION has had exactly one value in this project's history
     * — an absence can't be "wrong" when there is nothing else it could
     * have meant). DECLARED-AND-WRONG is never lenient — a manifest that
     * names a spec_version this engine doesn't recognize is making an
     * active, checkable claim, and silently accepting it would be exactly
     * the "unsupported behavior hidden behind a broad compatibility claim"
     * DESIGN.md's vision invariant forbids. This asymmetry is deliberate,
     * not a placeholder: it stays true even after DUO_SPEC_VERSION's first
     * real bump, and BECOMES MANDATORY (see the TODO below) the moment a
     * second historical value exists to be silently wrong about — a
     * decision pre-committed at DUO-3222's own design review, not left for
     * that bump to re-litigate.
     *
     * TODO(spec_version-mandatory): the commit that changes
     * DUO_SPEC_VERSION's value must also flip spec_version from optional
     * to required in this validator — that bump's own checklist item, not
     * a future debate. See spec/repo-format.md's adapter-contract section
     * (once ratified) for the matching prose commitment.
     */
    private static function validate_adapter_contract(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        $spec = $manifest['spec_version'] ?? null;
        if ($spec !== null) {
            $supported = defined('DUO_SPEC_VERSION') ? DUO_SPEC_VERSION : 0;
            if (!is_int($spec) || $spec !== $supported) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares spec_version " . var_export($spec, true)
                    . " but this engine supports spec_version $supported — pin a compatible manifest or update it"
                );
            }
        }
        foreach ([['plugin', 'version_range'], ['theme', 'theme_version_range']] as [$idKey, $rangeKey]) {
            $id = $manifest[$idKey] ?? null;
            if ($id === null) {
                continue;
            }
            if (!is_string($id) || $id === '') {
                throw new \RuntimeException("duo: manifest '$name' declares a non-string or empty '$idKey'");
            }
            $range = $manifest[$rangeKey] ?? null;
            if (!is_array($range)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares '$idKey' ('$id') but no '$rangeKey' — an adapter naming a "
                    . "$idKey with no exact version range is unbounded support, which this project's contract "
                    . 'forbids (DUO-3222). Declare {"min":..,"max":..} or drop the ' . "$idKey claim."
                );
            }
            $min = $range['min'] ?? null;
            $max = $range['max'] ?? null;
            if (!is_string($min) || $min === '' || !is_string($max) || $max === ''
                || version_compare($min, $max, '>=')
            ) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares '$rangeKey' with a malformed range (min="
                    . var_export($min, true) . ', max=' . var_export($max, true) . ') — both must be non-empty '
                    . 'version strings with min strictly less than max; wildcards/empty/unbounded are not '
                    . 'certifiable'
                );
            }
        }
    }

    /**
     * DUO-3222: cross-manifest guard, run once after every pinned manifest
     * has loaded (not per-manifest, unlike every validator above — this is
     * inherently a comparison BETWEEN manifests, so no single manifest's
     * own validator could ever catch it). Two PINNED manifests naming the
     * SAME plugin or theme with DIFFERENT version_range/theme_version_range
     * is "conflicting ownership" / "overlapping rules without explicit
     * composition" (DUO-3222's own acceptance criteria) — today's
     * version_ranges()/theme_ranges() silently let the first-in-pin-order
     * declaration win, which is exactly the load-order-dependent
     * precedence this issue's own non-negotiable constraint forbids
     * ("Manifest precedence cannot depend on load order").
     *
     * v1 has NO composition/override escape hatch (no "supersedes" field
     * or similar): every manifest pinned by every real site in this
     * project models a DISTINCT plugin or theme today, so there is no
     * genuine case requiring two manifests to legitimately co-declare the
     * same one — adding override grammar for a need nobody has yet is
     * exactly the untested-guess discipline this project avoids elsewhere
     * (manifests/yoast.json's own notes make the identical call
     * repeatedly, e.g. declining to guess wpseo_rss's shape). A real case,
     * if one ever appears, is a fast-follow with its own evidence, not a
     * default baked in speculatively here.
     *
     * Two manifests declaring the IDENTICAL range for the same plugin/
     * theme are deliberately allowed through (redundant, not ambiguous —
     * they produce the same answer regardless of load order, which is the
     * only thing this guard actually protects against).
     */
    private static function validate_no_conflicting_adapter_claims(array $manifests): void {
        foreach ([['plugin', 'version_range'], ['theme', 'theme_version_range']] as [$idKey, $rangeKey]) {
            $seen = [];
            foreach ($manifests as $m) {
                $id = $m[$idKey] ?? null;
                if (!is_string($id) || $id === '') {
                    continue;
                }
                $range = $m[$rangeKey] ?? [];
                $name = (string) ($m['name'] ?? '?');
                if (isset($seen[$id])) {
                    $prev = $seen[$id];
                    if ($prev['range'] != $range) {
                        throw new \RuntimeException(
                            "duo: manifests '{$prev['name']}' and '$name' both declare $idKey '$id' with "
                            . "different $rangeKey values (" . json_encode($prev['range']) . ' vs '
                            . json_encode($range) . ') — conflicting ownership with no v1 composition rule; '
                            . 'pin only one, or narrow one range to a disjoint window'
                        );
                    }
                    continue; // identical range declared twice — redundant, not conflicting; allow
                }
                $seen[$id] = ['name' => $name, 'range' => $range];
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
     * DUO-3222: theme twin of version_ranges() above — same {min,max} +
     * version_compare() shape, same first-pin-order-wins internal fallback
     * (never actually exercised in practice: validate_no_conflicting_
     * adapter_claims() at load time already refuses two pinned manifests
     * naming the same theme with different ranges, so this accessor's only
     * reader — Deploy::code_mismatch() — always sees a pre-validated,
     * unambiguous answer by the time it asks). Deliberately theme-
     * directory-keyed (not template/stylesheet-slot-keyed), for the same
     * reason version_ranges() is plugin-basename-keyed rather than
     * active_plugins-index-keyed — the CONTRACT is about an installed
     * artifact's identity, not which options field happens to name it on a
     * given environment; a manifest pinning a parent theme applies equally
     * whether that theme is loaded via `template` or `stylesheet`.
     *
     * @return array<string, array{min:string, max:string, manifest:string}> keyed by theme directory name
     */
    public function theme_ranges(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            $theme = $m['theme'] ?? null;
            $range = $m['theme_version_range'] ?? null;
            if (!is_string($theme) || $theme === '' || !is_array($range) || isset($out[$theme])) {
                continue;
            }
            $out[$theme] = [
                'min' => (string) ($range['min'] ?? '0'),
                'max' => (string) ($range['max'] ?? '999999999'),
                'manifest' => (string) ($m['name'] ?? '?'),
            ];
        }
        return $out;
    }

    /**
     * Version-1 deletion capabilities are exact entity selectors
     * (`post:page`, `term:category`, `menu:nav_menu`, or
     * `table:nf3_forms`). Multiple pinned manifests may add guards to the
     * same selector, but their cascade contract must agree exactly.
     *
     * @return ?array{cascades:string[],guards:array<int,array<string,mixed>>,declared_by:string[]}
     */
    public function deletion_capability(string $selector): ?array {
        $out = null;
        foreach ($this->manifests as $manifest) {
            $decl = $manifest['deletions'][$selector] ?? null;
            if ($decl === null) {
                continue;
            }
            if (!is_array($decl) || !isset($decl['cascades']) || !is_array($decl['cascades'])) {
                throw new \RuntimeException("duo: manifest deletion capability '$selector' must declare a cascades list");
            }
            $cascades = array_values(array_unique(array_map('strval', $decl['cascades'])));
            sort($cascades, SORT_STRING);
            $guards = $decl['guards'] ?? [];
            if (!is_array($guards) || !array_is_list($guards)) {
                throw new \RuntimeException("duo: manifest deletion capability '$selector' guards must be a list");
            }
            foreach ($guards as $i => $guard) {
                if (!is_array($guard)
                    || !preg_match('/^[A-Za-z0-9_]+$/', (string) ($guard['table'] ?? ''))
                    || !preg_match('/^[A-Za-z0-9_]+$/', (string) ($guard['column'] ?? ''))
                    || !preg_match('/^[a-z][a-z0-9_]*$/', (string) ($guard['id_kind'] ?? ''))) {
                    throw new \RuntimeException(
                        "duo: manifest deletion capability '$selector' guard[$i] must declare table, column, and id_kind"
                    );
                }
                foreach (['where', 'exclude_where'] as $predicate) {
                    $values = $guard[$predicate] ?? [];
                    if (!is_array($values) || (isset($guard[$predicate]) && array_is_list($values))) {
                        throw new \RuntimeException(
                            "duo: manifest deletion capability '$selector' guard[$i].$predicate must be an object"
                        );
                    }
                    foreach ($values as $column => $value) {
                        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $column)
                            || (!is_string($value) && !is_int($value))) {
                            throw new \RuntimeException(
                                "duo: manifest deletion capability '$selector' guard[$i].$predicate must contain scalar column predicates"
                            );
                        }
                    }
                }
                $hasSourceKind = isset($guard['source_id_kind']);
                $hasSourcePk = isset($guard['source_pk']);
                if ($hasSourceKind !== $hasSourcePk
                    || ($hasSourceKind && !preg_match('/^[a-z][a-z0-9_]*$/', (string) $guard['source_id_kind']))
                    || ($hasSourcePk && !preg_match('/^[A-Za-z0-9_]+$/', (string) $guard['source_pk']))) {
                    throw new \RuntimeException(
                        "duo: manifest deletion capability '$selector' guard[$i] must declare source_id_kind and source_pk together"
                    );
                }
            }
            $source = (string) ($manifest['name'] ?? '?');
            if ($out === null) {
                $out = ['cascades' => $cascades, 'guards' => [], 'declared_by' => []];
            } elseif ($out['cascades'] !== $cascades) {
                throw new \RuntimeException(
                    "duo: pinned manifests disagree on cascade effects for deletion capability '$selector'"
                );
            }
            $out['guards'] = array_merge($out['guards'], $guards);
            $out['declared_by'][] = $source;
        }
        return $out;
    }

    private const SECTIONS = ['options', 'post_meta', 'term_meta'];
    private const CLASSES = ['authored', 'runtime', 'derived', 'env', 'managed'];
    private const SCOPE_CLASSES = ['authored', 'runtime', 'derived', 'env'];
    private const CASTS = ['string', 'csv'];

    /**
     * Write one classification rule into site.duo.json's policy overrides
     * (`wp duo classify`'s only write path — DESIGN.md 3.1.5: "accepted
     * decisions persist to policy.yml/json"). Validates shape, then loads +
     * rewrites the file via Canon::encode so formatting stays canonical.
     */
    public static function set_rule(string $repo, string $section, string $key, array $rule): void {
        if ($section === 'scope') {
            if (!preg_match('/^(post_type|taxonomy):(.+)$/', $key, $m)) {
                throw new \RuntimeException(
                    "duo: scope key '$key' must be post_type:<name> or taxonomy:<name>"
                );
            }
            $class = $rule['class'] ?? '';
            if (!in_array($class, self::SCOPE_CLASSES, true)) {
                throw new \RuntimeException(
                    "duo: unknown scope class '$class' (expected " . implode('|', self::SCOPE_CLASSES) . ')'
                );
            }
            if (array_diff_key($rule, ['class' => true])) {
                throw new \RuntimeException('duo: scope rules accept class only (no ref, cast, or secret override)');
            }
            $siteFile = rtrim($repo, '/') . '/site.duo.json';
            if (!is_file($siteFile)) {
                throw new \RuntimeException("duo: $siteFile not found (not a duo site repo?)");
            }
            $site = Canon::decode(Canon::read_file($siteFile));
            $site['policy']['scope'][$m[1]][$m[2]] = $rule;
            Canon::write_file($siteFile, Canon::encode($site));
            return;
        }
        if (!in_array($section, self::SECTIONS, true)) {
            throw new \RuntimeException(
                'duo: unknown policy section \'' . $section . '\' (expected '
                . implode('|', array_merge(self::SECTIONS, ['scope'])) . ')'
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
