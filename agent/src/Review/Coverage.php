<?php
namespace WPrism;

require_once __DIR__ . '/../Repository/Ledger.php';

/**
 * issue #3290: names and counts what a site actually has versus what WPrism can
 * see, for options and custom tables -- the two blind spots issue #3257's
 * phase-1 aged-site fixture measured directly (469 live options rows, 24
 * captured, 445 genuinely invisible with zero discovery path; a real
 * unmanifested plugin's custom table with 407 real rows that never surfaces
 * anywhere, not even in `wp wprism pending`).
 *
 * Deliberately NOT part of the loud-and-blocking gate: `Capture::gate_scan()`
 * and `$this->unclassified` stay exactly as they are. This class never
 * throws, never affects capture/plan/apply, and is never consulted by them
 * -- it exists so an operator (or an automated onboarding flow) can ask "how
 * much of my site does WPrism actually see?" and get a truthful number BEFORE
 * committing to "this site is under management," independent of whether
 * anything here would ever become gated. Converting silent-invisible into
 * named-and-counted, per team-lead's own framing at the issue #3257 checkpoint
 * that authorized this class.
 *
 * Names and counts only -- no option VALUES and no table row CONTENTS ever
 * appear in this class's own output (report()'s return value). The one
 * internal exception, not visible in the output: correctly resolving an
 * interpreter-owned or ref-pattern option name (Policy::
 * owned_option_rule_via_interpreter()/match_option_name_ref()) structurally
 * requires the live value to evaluate, exactly like Capture::gate_scan()
 * already does -- so options_report() reads name+value in the SAME single
 * query gate_scan() already runs in production (proven cheap there), uses
 * the value only to classify, and never includes it in anything this class
 * returns. Getting this wrong in either direction is a real failure mode:
 * skipping the value read would misreport an ACF-owned option as invisible
 * when it is genuinely captured; including values in the output would be
 * exactly the exposure "zero value reads" exists to avoid.
 */
final class Coverage {
    public const FORMAT = 'wprism-coverage-report/v1';

    /** A single grouping/attribution/report listing above this size gets a
     *  sanity-bound warning attached rather than silently growing forever --
     *  the count itself is always exact regardless; this only affects
     *  whether Cli.php's human-table renderer condenses the listing. */
    public const LARGE_LISTING_THRESHOLD = 200;

    public static function report(string $repo): array {
        $policy = Policy::load($repo);
        $activeSlugs = self::active_plugin_slugs();
        return [
            'format' => self::FORMAT,
            'options' => self::options_report($policy, $activeSlugs),
            'tables' => self::tables_report($policy, $activeSlugs),
        ];
    }

    /** The two classes whose declared rows OptionsCapture always writes into
     *  the artifact: authored through the whitelist loop
     *  (OptionsCapture.php:67-82) and managed through the fixed
     *  active_plugins/template/stylesheet loop (:202-216). */
    private const CAPTURED_CLASSES = ['authored', 'managed'];

    /**
     * Four buckets, one per row, and every row lands in exactly one of them
     * before the loop `continue`s — so `total === captured +
     * declared_excluded + pending + invisible_total` holds by construction
     * rather than by a runtime check this deliberately never-throwing class
     * could not raise anyway (see the class docblock). The invariant is
     * asserted where it can actually fail a gate:
     * sandbox/tests/offline/assess-contract/regress_coverage_offline.php.
     *
     * @return array<string,mixed>
     */
    private static function options_report(Policy $policy, array $activeSlugs): array {
        global $wpdb;
        // Same query shape as Capture::gate_scan()'s own options loop --
        // one pass, ORDER BY for deterministic output, values read only to
        // classify (see class docblock).
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name ASC",
            ARRAY_A
        ) ?: [];
        $allOptionValues = [];
        foreach ($rows as $row) {
            $allOptionValues[(string) $row['option_name']] = (string) $row['option_value'];
        }
        // issue #3505: the UNFILTERED exact enumeration, not authored_options()
        // + sub_keyed_options(). Visibility asks "does any rule win for this
        // name", which is not the class question capture asks; answering it
        // with capture's class-filtered enumerators is what reported every
        // declared env/runtime/derived name as invisible to every installed
        // adapter (measured: a site holding only the 19 option names
        // manifests/core.json itself declares read 10 of them invisible,
        // including the 3 managed rows the artifact always contains).
        $declared = $policy->exact_options();
        // The one shipped dynamic_options resolver (core.json's theme_mods,
        // resolver `active_stylesheet`), read once per report exactly as
        // OptionsCapture::capture():121-131 resolves it once per run.
        $stylesheet = (string) get_option('stylesheet');

        $captured = 0;
        $pending = 0;
        $declaredExcluded = self::declared_excluded_seed();
        $invisibleNames = [];
        foreach ($rows as $row) {
            $name = (string) $row['option_name'];
            $owner = null;
            $fromDynamic = false;
            // Precedence is the engine's own, in the engine's own order,
            // every step delegating to the resolver the capture/apply path
            // uses -- never a second reimplementation of the grammar.
            //
            // 1. An exact rule of ANY class, from a pinned manifest or from
            //    site.wprism.json.
            $rule = $declared[$name] ?? null;
            if ($rule === null) {
                // 2. Namespace ownership, unchanged from issue #3290 and still
                //    reached only when 1 missed: owned_option_rule() throws
                //    on cross-manifest ambiguity (Policy.php:669-672), and a
                //    name that already has an exact rule must not be put in
                //    front of that throw by this non-gating report.
                $owner = $policy->option_namespace($name);
                if ($owner !== null) {
                    $rule = $policy->owned_option_rule_via_interpreter($name, $allOptionValues)
                        ?? ($policy->match_option_name_ref($name) !== null ? ['class' => 'authored'] : null)
                        ?? $policy->owned_option_rule($name);
                }
            }
            // 3. option_patterns -- core.json's own ^_transient_ /
            //    ^_site_transient_ (derived) and ^_wp_session_ (runtime) win
            //    here, which is why every transient and session row on every
            //    site used to be counted invisible.
            $rule ??= $policy->option_rule($name);
            if ($rule === null) {
                // 4. dynamic_options by prefix: core.json's theme_mods_
                //    family, whose active-theme row OptionsCapture writes.
                $rule = $policy->dynamic_option_rule_for_prefix($name);
                $fromDynamic = $rule !== null;
            }
            if ($rule === null) {
                if ($owner !== null) {
                    // Namespace-claimed but unresolved: this is gate_scan()'s
                    // OWN pending bucket, not invisible -- already loud via
                    // `wp wprism pending` today. Counted here for the total to
                    // reconcile, not because coverage introduces a new gate.
                    $pending++;
                    continue;
                }
                // No rule from any source. This, and only this, is what
                // "invisible to every installed adapter" has ever meant.
                $invisibleNames[] = $name;
                continue;
            }
            if (self::is_captured($policy, $name, $rule, $fromDynamic, $stylesheet)) {
                $captured++;
                continue;
            }
            // Declared and deliberately excluded: an adapter models this name
            // and says WPrism must not version it. Not invisible, not pending,
            // not captured, and carrying no next action -- there is nothing
            // here to classify.
            $class = (string) ($rule['class'] ?? 'unknown');
            $declaredExcluded[$class] = ($declaredExcluded[$class] ?? 0) + 1;
        }
        ksort($declaredExcluded, SORT_STRING);

        [$transientNames, $realInvisibleNames] = self::partition_transients($invisibleNames);

        return [
            'total' => count($rows),
            'captured' => $captured,
            'declared_excluded' => array_sum($declaredExcluded),
            'declared_excluded_by_class' => $declaredExcluded,
            'pending' => $pending,
            'invisible_total' => count($invisibleNames),
            'invisible_transient' => count($transientNames),
            'invisible_other' => count($realInvisibleNames),
            'invisible_groups' => self::group_and_attribute($realInvisibleNames, $activeSlugs),
        ];
    }

    /**
     * Does WPrism write this name into the captured artifact? One branch per
     * writer in OptionsCapture::capture(), so the two can only disagree if
     * one of them changes: authored (:67-82) and managed (:202-216) whole-
     * name writes, declared sub_keys (:118-120), an option-name-ref match
     * (:93), and the ONE dynamic row the active theme resolves to (:121-140).
     *
     * A rule that matches none of those is declared and excluded: env,
     * runtime or derived with no sub-keys, which no writer ever touches.
     */
    private static function is_captured(
        Policy $policy,
        string $name,
        array $rule,
        bool $fromDynamic,
        string $stylesheet
    ): bool {
        if ($fromDynamic) {
            // A dynamic declaration carries the same sub_keys for EVERY row
            // sharing its prefix, so the sub_keys test below would call a
            // former theme's leftover row captured. Only the currently-
            // resolved name is written; its siblings are the residue
            // WordPress itself keeps against a switch back (issue #3264).
            return !$policy->is_dynamic_option_residue($name, ['active_stylesheet' => $stylesheet]);
        }
        if (in_array((string) ($rule['class'] ?? ''), self::CAPTURED_CLASSES, true)) {
            return true;
        }
        if (!empty($rule['sub_keys'])) {
            return true;
        }
        return $policy->match_option_name_ref($name) !== null;
    }

    /**
     * Every class a declared-but-uncaptured row can carry, seeded to zero:
     * Policy::CLASSES minus CAPTURED_CLASSES, so the key set is complete by
     * derivation rather than by a literal list that could fall behind the
     * closed vocabulary. All keys are always present including the zeroes --
     * GapActions.php:152-156's "the count is the signal" doctrine, so an
     * operator reads a 0 instead of inferring one from an absent key -- and
     * ksorted so the published bytes are canonical.
     *
     * @return array<string,int>
     */
    private static function declared_excluded_seed(): array {
        $seed = [];
        foreach (Policy::CLASSES as $class) {
            if (!in_array($class, self::CAPTURED_CLASSES, true)) {
                $seed[$class] = 0;
            }
        }
        ksort($seed, SORT_STRING);
        return $seed;
    }

    /**
     * The transient/other split of the genuinely-invisible set.
     *
     * issue #3505: with any manifest pinned that declares transient patterns --
     * core.json's own ^_transient_ and ^_site_transient_, class derived --
     * this partition is now structurally zero, because such a row has a
     * winning rule and never reaches the invisible set at all. It stays
     * because it is still the honest answer for a site that pins no manifest
     * declaring them, and because Cli.php:2103 publishes both counts.
     *
     * @return array{0:string[],1:string[]}
     */
    private static function partition_transients(array $names): array {
        $transient = [];
        $other = [];
        foreach ($names as $n) {
            if (str_starts_with($n, '_transient_') || str_starts_with($n, '_site_transient_')) {
                $transient[] = $n;
            } else {
                $other[] = $n;
            }
        }
        return [$transient, $other];
    }

    /** @return array<string,mixed> */
    private static function tables_report(Policy $policy, array $activeSlugs): array {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $live = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%')) ?: [];

        // `logical name => prefixed name` -- kept as a MAP rather than
        // array_values()'d, because the key is the identity
        // declared_core_tables() answers about and the value carries core's
        // own per-table prefix rules (base_prefix for global tables,
        // CUSTOM_USER_TABLE where defined, class-wpdb.php:1151-1173) that
        // nothing here should restate.
        $wpTables = $wpdb->tables('all', true);
        $declaredCore = self::declared_core_tables($wpdb);
        // With no readable declaration there is no honest split to make, so
        // nothing is claimed as registered and `core_source` publishes WHY
        // the numbers below are the pre-fix ones. A quiet fallback here would
        // report every WordPress core table as an undeclared coverage
        // subject, which is a worse answer than the defect this replaces.
        $coreSource = $declaredCore === [] ? 'wpdb_instance' : 'wpdb_class_declaration';
        $core = [];
        $registered = [];
        foreach ($wpTables as $logicalName => $prefixedName) {
            if ($coreSource === 'wpdb_instance' || isset($declaredCore[(string) $logicalName])) {
                $core[] = (string) $prefixedName;
            } else {
                $registered[] = (string) $prefixedName;
            }
        }
        $declared = $policy->declared_tables(); // unprefixed logical names, per manifests' own "tables" key shape

        $undeclared = [];
        foreach ($live as $tableName) {
            // Only genuinely-core tables are skipped now. A table a plugin
            // registered into $wpdb->tables falls through to exactly the same
            // undeclared path an unregistered plugin table already took --
            // that IS the fix: it becomes a coverage subject, gets its
            // logical_name, and reaches `wprism assess`'s `table:<name>` surface
            // and `wprism adapter-draft --seed`'s proposals.
            if (in_array($tableName, $core, true)) {
                continue;
            }
            $logicalName = str_starts_with($tableName, $prefix)
                ? substr($tableName, strlen($prefix))
                : $tableName;
            if (isset($declared[$logicalName]) || in_array($logicalName, Ledger::OWN_TABLES, true)) {
                // WPrism's own ledger is not site state and no adapter will ever
                // declare it; listing it as "undeclared" taught the operator
                // to classify the tool that was assessing them.
                continue;
            }
            $undeclared[] = [
                'table' => $tableName,
                'logical_name' => $logicalName,
                'registered' => in_array($tableName, $registered, true),
            ];
        }

        $rows = [];
        foreach ($undeclared as $t) {
            // One COUNT(*) per undeclared table -- names+counts only, never
            // row content. In practice this is a handful of tables (a
            // handful of unmanifested plugins' own schemas), not hundreds;
            // see LARGE_LISTING_THRESHOLD for the sanity-bound warning if a
            // site genuinely has an unusual number of them.
            $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($t['table']) . '`');
            $rows[] = [
                'table' => $t['table'],
                // The unprefixed name, published rather than recomputed by the
                // reader: `wp_` is this install's prefix, not a constant, so a
                // consumer stripping it itself would mis-name every row on a
                // site whose prefix is anything else. It is also the identity
                // `wprism assess` builds its `table:<logical_name>` surface row
                // from (cli/src/Assess/SurfaceCatalog.php:326 and
                // AssessReport.php:156 both require the key and skip the row
                // without it) — dropping it here is why an undeclared table
                // never appeared in a live assessment.
                'logical_name' => $t['logical_name'],
                'row_count' => $count,
                'probable_owner' => self::attribute($t['logical_name'], $activeSlugs),
                // Which of these rows the previous engine reported as core.
                // Not a different KIND of subject -- an author drafting a
                // declaration needs to know a `registered` table usually
                // belongs to a bundled library rather than to the plugin
                // whose slug attribution names (all four Action Scheduler
                // tables on the measured site attribute to nothing, because
                // no active slug is `actionscheduler`).
                'registered' => $t['registered'],
            ];
        }
        usort($rows, static fn($a, $b) => strcmp($a['table'], $b['table']));

        return [
            'live_total' => count($live),
            'core_total' => count(array_intersect($core, $live)), // core tables actually present on this site
            // Live tables `$wpdb->tables()` lists that WordPress's own class
            // declaration does not -- each one also appears in `undeclared`
            // below. Published as its own number because it is the whole
            // difference between this report and the one before it: on the
            // measured WPForms Lite site `core` reads 12 where it used to
            // read 16, and this is the 4.
            'registered_total' => count(array_intersect($registered, $live)),
            'core_source' => $coreSource,
            'declared_total' => count($declared), // manifest-declared table names, regardless of live presence
            'undeclared_total' => count($undeclared),
            'undeclared' => $rows,
        ];
    }

    /**
     * The table names WordPress's own class DECLARATION owns -- the one core
     * list a plugin cannot extend.
     *
     * `$wpdb->tables('all', true)` is not that list, though the comment this
     * replaces treated it as one. It composes from the INSTANCE properties
     * `$wpdb->tables` / `$global_tables` / `$ms_global_tables`
     * (class-wpdb.php:1122-1130), and any plugin may append to them at
     * runtime: Action Scheduler does exactly `$wpdb->tables[] = $table` for
     * each of its four tables (ActionScheduler_Abstract_Schema::
     * register_tables(), classes/abstracts/ActionScheduler_Abstract_Schema.php:54),
     * and WPForms Lite, WooCommerce and WP Mail SMTP all bundle it. Measured
     * on a WPForms Lite 2.0.0.5 site: `tables('all', true)` returned 16 names,
     * four of them `wp_actionscheduler_*` (one of which holds a real row,
     * `action_scheduler/migration_hook`), so `wprism coverage` reported them as
     * core, `wprism assess` minted no `table:` surface for them, and
     * `wprism adapter-draft --seed` proposed no declaration for a table set the
     * site's own plugin writes to.
     *
     * The class's DECLARED DEFAULTS are the honest source, and they keep what
     * the previous comment was right about: they are whatever WordPress core
     * ships for the version actually loaded, so there is still no hardcoded
     * name list here to drift. What they add is that a runtime append mutates
     * the INSTANCE and never the declaration. getDefaultProperties() reports
     * inherited declarations too, so a wpdb subclass a host drops in (HyperDB
     * and friends) resolves through to wpdb's own three lists, and a
     * replacement that declares its own is still answering "what does this DB
     * layer's own code call core" -- which is the question being asked.
     *
     * `old_tables` is deliberately not folded in: `tables('all')` never
     * returns the deprecated 2.3-era names, so a site that still holds a live
     * `wp_categories` reaches the undeclared listing exactly as it always has.
     *
     * @return array<string,true> declared-core logical names, as a set
     */
    private static function declared_core_tables(object $wpdb): array {
        try {
            $defaults = (new \ReflectionClass($wpdb))->getDefaultProperties();
        } catch (\ReflectionException) {
            return [];
        }
        $set = [];
        // Exactly the three properties tables('all') composes from, in that
        // order -- asking for more would claim core-ness the switch at
        // class-wpdb.php:1122-1130 never grants.
        foreach (['tables', 'global_tables', 'ms_global_tables'] as $property) {
            foreach ((array) ($defaults[$property] ?? []) as $name) {
                if (is_string($name) && $name !== '') {
                    $set[$name] = true;
                }
            }
        }
        return $set;
    }

    /**
     * Best-effort prefix grouping + row counts + attribution for the
     * options-invisible set -- one plugin owning 172 rows reads very
     * differently from 172 single-row plugins, so both the per-group row
     * count and the distinct-group count matter (team-lead's own framing).
     *
     * @param string[] $names
     * @return array<int,array{prefix:string,count:int,probable_owner:?string}>
     */
    private static function group_and_attribute(array $names, array $activeSlugs): array {
        $groups = [];
        foreach ($names as $name) {
            $prefix = self::guess_prefix($name);
            $groups[$prefix] = ($groups[$prefix] ?? 0) + 1;
        }
        arsort($groups);
        $out = [];
        foreach ($groups as $prefix => $count) {
            $out[] = [
                'prefix' => $prefix,
                'count' => $count,
                'probable_owner' => self::attribute($prefix, $activeSlugs),
            ];
        }
        return $out;
    }

    /** First one or two underscore-separated segments, leading underscore
     *  (WordPress's own "private/internal" convention) stripped for
     *  grouping purposes only -- never for the option name itself.
     *
     *  Public since the fleet census: `invisible_groups[].prefix` is the
     *  namespace the residual is REPORTED in, so anything asking "does this
     *  adapter's declared option key fall in that group" has to fold the key
     *  through this exact grammar (cli/src/Assess/FleetCensus.php's
     *  credits_option_prefix()). A second regex that disagreed by one
     *  underscore would credit or deny an adapter for a reason no operator
     *  could reconstruct from either file. Behaviour is unchanged: no caller
     *  inside this class moved, and the function still reads nothing. */
    public static function guess_prefix(string $name): string {
        $bare = ltrim($name, '_');
        if (preg_match('/^([a-z0-9]+(?:_[a-z0-9]+)?)_/i', $bare, $m)) {
            return $m[1];
        }
        return $bare !== '' ? $bare : $name;
    }

    /** @return string[] active plugin slugs (the directory-name half of
     *  "slug/file.php"), deduplicated. */
    private static function active_plugin_slugs(): array {
        $active = get_option('active_plugins', []);
        if (!is_array($active)) {
            return [];
        }
        $slugs = [];
        foreach ($active as $basename) {
            $slug = strtolower(strtok((string) $basename, '/'));
            if ($slug !== '') {
                $slugs[$slug] = true;
            }
        }
        return array_keys($slugs);
    }

    /**
     * Advisory only, per team-lead's explicit ruling: heuristic
     * prefix-matching against installed plugin slugs is fine for a report;
     * attribution quality must never gate the counting, and a mislabeled
     * guess is cosmetic where an uncounted namespace would be the real
     * failure mode. Always returns a labeled guess or null (counted in an
     * explicit unattributed bucket by callers) -- never a bare unlabeled
     * string that could be mistaken for a verified fact.
     */
    private static function attribute(string $needle, array $activeSlugs): ?string {
        $normalizedNeedle = str_replace('_', '-', strtolower($needle));
        $best = null;
        $bestLength = 0;
        foreach ($activeSlugs as $slug) {
            $normalizedSlug = strtolower($slug);
            if ($normalizedSlug === $normalizedNeedle
                || str_starts_with($normalizedSlug, $normalizedNeedle)
                || str_starts_with($normalizedNeedle, $normalizedSlug)) {
                return $slug;
            }
            // A plugin whose slug carries an edition suffix (`wpforms-lite`,
            // `google-site-kit`) prefixes its options and tables with the
            // family name alone (`wpforms_settings`, `wp_wpforms_tasks_meta`).
            // Match on the slug's first token when it is long enough to be a
            // name rather than a preposition, longest token wins; a family
            // name two active plugins share is left unattributed rather than
            // guessed. Found on the T6 adapter walk: every WPForms table read
            // `probable_owner: null` and its gap action fell to `classify`.
            $family = strtok($normalizedSlug, '-');
            if ($family !== false && strlen($family) >= 4 && $family !== $normalizedSlug
                && ($normalizedNeedle === $family || str_starts_with($normalizedNeedle, $family . '-'))) {
                if (strlen($family) === $bestLength && $best !== $slug) {
                    $best = null; // two edition slugs of one family: ambiguous, say nothing
                    $bestLength = -1;
                    continue;
                }
                if (strlen($family) > $bestLength) {
                    $best = $slug;
                    $bestLength = strlen($family);
                }
            }
        }
        return $best;
    }
}
