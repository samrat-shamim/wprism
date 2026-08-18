<?php
namespace Duo;

require_once __DIR__ . '/../Repository/Ledger.php';

/**
 * DUO-3290: names and counts what a site actually has versus what Duo can
 * see, for options and custom tables -- the two blind spots DUO-3257's
 * phase-1 aged-site fixture measured directly (469 live options rows, 24
 * captured, 445 genuinely invisible with zero discovery path; a real
 * unmanifested plugin's custom table with 407 real rows that never surfaces
 * anywhere, not even in `wp duo pending`).
 *
 * Deliberately NOT part of the loud-and-blocking gate: `Capture::gate_scan()`
 * and `$this->unclassified` stay exactly as they are. This class never
 * throws, never affects capture/plan/apply, and is never consulted by them
 * -- it exists so an operator (or an automated onboarding flow) can ask "how
 * much of my site does Duo actually see?" and get a truthful number BEFORE
 * committing to "this site is under management," independent of whether
 * anything here would ever become gated. Converting silent-invisible into
 * named-and-counted, per team-lead's own framing at the DUO-3257 checkpoint
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
    public const FORMAT = 'duo-coverage-report/v1';

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

    /** @return array<string,mixed> */
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
        $exact = $policy->authored_options();
        $subKeyed = $policy->sub_keyed_options();

        $captured = 0;
        $pending = 0;
        $invisibleNames = [];
        foreach ($rows as $row) {
            $name = (string) $row['option_name'];
            // Exact-declaration path: authored_options()/sub_keyed_options()
            // resolve independent of namespace ownership entirely (mirrors
            // build_options()'s own two-loop structure -- see DUO-3257's
            // own corrected-measurement note: option_namespace() ALONE
            // undercounts "captured" by missing this path).
            if (isset($exact[$name]) || isset($subKeyed[$name])) {
                $captured++;
                continue;
            }
            $owner = $policy->option_namespace($name);
            if ($owner === null) {
                $invisibleNames[] = $name;
                continue;
            }
            $resolved = $policy->owned_option_rule_via_interpreter($name, $allOptionValues) !== null
                || $policy->match_option_name_ref($name) !== null
                || $policy->owned_option_rule($name) !== null;
            if ($resolved) {
                $captured++;
            } else {
                // Namespace-claimed but unresolved: this is gate_scan()'s
                // OWN pending bucket, not invisible -- already loud via
                // `wp duo pending` today. Counted here for the total to
                // reconcile (captured + pending + invisible === total),
                // not because coverage is introducing a new gate.
                $pending++;
            }
        }

        [$transientNames, $realInvisibleNames] = self::partition_transients($invisibleNames);

        return [
            'total' => count($rows),
            'captured' => $captured,
            'pending' => $pending,
            'invisible_total' => count($invisibleNames),
            'invisible_transient' => count($transientNames),
            'invisible_other' => count($realInvisibleNames),
            'invisible_groups' => self::group_and_attribute($realInvisibleNames, $activeSlugs),
        ];
    }

    /** @return array{0:string[],1:string[]} */
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

        // $wpdb->tables('all', true) is WordPress's own authoritative,
        // version-proof list of tables IT considers core (global + blog +
        // ms-global as applicable) -- deliberately not a hardcoded name
        // list here, which would drift across WP core versions the way a
        // hand-maintained list always eventually does.
        $core = array_values($wpdb->tables('all', true));
        $declared = $policy->declared_tables(); // unprefixed logical names, per manifests' own "tables" key shape

        $undeclared = [];
        foreach ($live as $tableName) {
            if (in_array($tableName, $core, true)) {
                continue;
            }
            $logicalName = str_starts_with($tableName, $prefix)
                ? substr($tableName, strlen($prefix))
                : $tableName;
            if (isset($declared[$logicalName]) || in_array($logicalName, Ledger::OWN_TABLES, true)) {
                // Duo's own ledger is not site state and no adapter will ever
                // declare it; listing it as "undeclared" taught the operator
                // to classify the tool that was assessing them.
                continue;
            }
            $undeclared[] = ['table' => $tableName, 'logical_name' => $logicalName];
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
                // `duo assess` builds its `table:<logical_name>` surface row
                // from (cli/src/Assess/SurfaceCatalog.php:326 and
                // AssessReport.php:156 both require the key and skip the row
                // without it) — dropping it here is why an undeclared table
                // never appeared in a live assessment.
                'logical_name' => $t['logical_name'],
                'row_count' => $count,
                'probable_owner' => self::attribute($t['logical_name'], $activeSlugs),
            ];
        }
        usort($rows, static fn($a, $b) => strcmp($a['table'], $b['table']));

        return [
            'live_total' => count($live),
            'core_total' => count(array_intersect($core, $live)), // core tables actually present on this site
            'declared_total' => count($declared), // manifest-declared table names, regardless of live presence
            'undeclared_total' => count($undeclared),
            'undeclared' => $rows,
        ];
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
     *  grouping purposes only -- never for the option name itself. */
    private static function guess_prefix(string $name): string {
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
