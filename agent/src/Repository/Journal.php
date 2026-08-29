<?php
namespace WPrism;

if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
if (!class_exists(CommandRefusalException::class, false)) {
    require_once __DIR__ . '/../Kernel/CommandRefusal.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/Ledger.php';
}

/**
 * Durable provenance journal (proposal generator, never authority): observes DB write
 * queries via the wpdb 'query' filter and records (table, item) with the
 * cause — surface × actor capability × executing hook. The authored signal is
 * capability×surface: bare "authenticated" never proposes authored (customers
 * write runtime data through authenticated surfaces constantly), and CLI is a
 * 'review' abstain (production cron commonly runs via wp-cli).
 *
 * Known blind spots by design (see DESIGN.md): fires on reads too (fast
 * regexp bail), can't see insert ids or rollbacks — ledger-vs-DB diff stays
 * the ground truth for WHAT changed; the journal only explains WHY.
 */
final class Journal {
    private static bool $booted = false;
    private static array $buffer = [];
    /**
     * A bounded command-level switch, used only by adapter observation after
     * ordinary WordPress/plugin bootstrap has completed.  A plugin/provider
     * may still have written during bootstrap, but this prevents the journal
     * query hook from turning that request's buffer (or a later callback) into
     * a WPrism-owned INSERT at shutdown.
     */
    private static bool $observationSuspended = false;

    public static function boot(): void {
        if (self::$booted || self::$observationSuspended) {
            return;
        }
        self::$booted = true;
        // WPRISM_JOURNAL (wp-config.php) is the deployment-time switch, but it's
        // sourced from WORDPRESS_CONFIG_EXTRA and eval()'d fresh every
        // request straight from the container's environment — nothing
        // reachable at runtime (file edits, wp-cli, opcache invalidation)
        // can override it without recreating the container. This option is
        // a live kill switch for the same effect (e.g. overhead A/B
        // measurement) without needing that.
        if (get_option('wprism_journal_disabled')) {
            return;
        }
        // The one door that DECLINES instead of refusing. boot() runs inside
        // every front-end and admin request of every blog (the drop-in is one
        // network-wide mu-plugin), so a refusal here has no operator to hear it
        // and a fatal would take the site down -- a deliberate asymmetry with
        // the verbs, which refuse loudly through
        // SiteTopology::assert_single_site(). What declining prevents is
        // concrete: the shutdown flush reaches Ledger::ensure() (:116) and its
        // four `CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wprism_*`
        // (agent/src/Repository/Ledger.php:81-105) against the SERVING blog's
        // prefix, so an enabled WPRISM_JOURNAL seeded a `wp_N_wprism_*` set on every
        // blog that served a write -- residue no rollback removes
        // (cli/src/Onboarding/Adopt.php's rollbackScript() restores filesystem
        // paths only, and there is no DROP TABLE anywhere in the shipped tree).
        // Fixed at the mechanism rather than at wprism.php's opt-in condition so
        // every caller of boot(), adapter observation included, inherits it.
        if (function_exists('is_multisite') && is_multisite()) {
            return;
        }
        add_filter('query', [self::class, 'observe'], -2147483646);
        add_action('shutdown', [self::class, 'flush'], PHP_INT_MAX);
    }

    public static function observe($sql) {
        if (self::$observationSuspended) {
            return $sql;
        }
        if (!is_string($sql) || !preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql, $m)) {
            return $sql;
        }
        $op = strtoupper($m[1]);
        $tbl = self::table_of($sql, $op);
        if ($tbl === null) {
            return $sql;
        }
        // The query filter runs before the database executes the statement.
        // An overlong match can therefore only be an invalid physical table
        // identifier, never successful plugin provenance. Ignore it instead
        // of truncating it into a false table name during journal flush.
        if (strlen($tbl) > Ledger::TABLE_IDENTIFIER_WIDTH) {
            return $sql;
        }
        $tbl = self::unprefix($tbl);
        if (str_starts_with($tbl, 'wprism_')) {
            return $sql;
        }
        $ctx = self::context();
        self::$buffer[] = [
            gmdate('Y-m-d H:i:s'),
            $op,
            $tbl,
            self::item_of($sql, $tbl),
            $ctx['surface'],
            $ctx['actor'],
            $ctx['caps'],
            substr((string) (function_exists('current_action') ? current_action() : ''), 0, 190),
            self::propose($ctx),
        ];
        return $sql;
    }

    public static function flush(): void {
        if (self::$observationSuspended) {
            self::$buffer = [];
            return;
        }
        global $wpdb;
        if (!self::$buffer || !isset($wpdb)) {
            return;
        }
        $rows = self::$buffer;
        self::$buffer = [];
        remove_filter('query', [self::class, 'observe'], -2147483646);
        try {
            Ledger::ensure();
            $values = [];
            $params = [];
            foreach ($rows as $r) {
                $values[] = '(%s,%s,%s,%s,%s,%d,%s,%s,%s)';
                array_push($params, $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7], $r[8]);
            }
            Db::query($wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}wprism_journal (t, op, tbl, item, surface, actor, caps, hook, proposal) VALUES "
                . implode(',', $values),
                $params
            ), 'journal flush observations');
        } finally {
            add_filter('query', [self::class, 'observe'], -2147483646);
        }
    }

    /**
     * Stop this request's optional provenance observer without changing its
     * durable configuration.  `adapter-observe` deliberately keeps normal
     * plugin bootstrap enabled, so it must also discard any pre-command
     * bootstrap observations and remove the shutdown flush before it reads
     * policy/provider evidence.  The next process starts with the normal
     * opt-in journal behavior unchanged.
     */
    public static function suspend_for_observation(): void {
        self::$observationSuspended = true;
        self::$buffer = [];
        if (function_exists('remove_filter')) {
            remove_filter('query', [self::class, 'observe'], -2147483646);
        }
        if (function_exists('remove_action')) {
            remove_action('shutdown', [self::class, 'flush'], PHP_INT_MAX);
        }
    }

    private static function table_of(string $sql, string $op): ?string {
        $pat = match ($op) {
            'INSERT', 'REPLACE' => '/^\s*(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?([A-Za-z0-9_]+)`?/i',
            'UPDATE' => '/^\s*UPDATE\s+(?:IGNORE\s+)?`?([A-Za-z0-9_]+)`?/i',
            'DELETE' => '/^\s*DELETE\s+FROM\s+`?([A-Za-z0-9_]+)`?/i',
            default => null,
        };
        return ($pat && preg_match($pat, $sql, $m)) ? $m[1] : null;
    }

    private static function unprefix(string $table): string {
        global $wpdb;
        $p = $wpdb->prefix;
        return str_starts_with($table, $p) ? substr($table, strlen($p)) : $table;
    }

    /** Best-effort item extraction for keyed tables. */
    private static function item_of(string $sql, string $tbl): string {
        $col = match (true) {
            $tbl === 'options' => 'option_name',
            str_ends_with($tbl, 'meta') => 'meta_key',
            default => null,
        };
        if ($col === null) {
            return '';
        }
        if (preg_match('/`?' . $col . "`?\\s*(?:=|,)?\\s*'((?:[^'\\\\]|\\\\.)*)'/i", $sql, $m)) {
            return substr(stripslashes($m[1]), 0, 190);
        }
        // wpdb->insert emits column lists: (`col1`, `col2`, ...) VALUES ('v1', 'v2', ...)
        if (preg_match('/\(([^)]*`' . $col . '`[^)]*)\)\s*VALUES\s*\((.*)\)/is', $sql, $m)) {
            $cols = array_map(fn($c) => trim($c, " `\t\n"), explode(',', $m[1]));
            $idx = array_search($col, $cols, true);
            if ($idx !== false && preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'|(NULL)|([0-9.]+)/i", $m[2], $vals)) {
                $v = $vals[1][$idx] ?? '';
                return substr(stripslashes($v), 0, 190);
            }
        }
        return '';
    }

    /** @return array{surface: string, actor: int, caps: string} */
    private static function context(): array {
        $surface = 'front';
        if (defined('WP_CLI') && WP_CLI) {
            $surface = 'cli';
        } elseif (function_exists('wp_doing_cron') && wp_doing_cron()) {
            $surface = 'cron';
        } elseif (defined('REST_REQUEST') && REST_REQUEST) {
            $surface = 'rest';
        } elseif (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            $surface = 'ajax';
        } elseif (function_exists('is_admin') && is_admin()) {
            $surface = 'admin';
        } elseif (isset($_SERVER['REQUEST_URI']) && str_contains((string) $_SERVER['REQUEST_URI'], '/wp-json/')) {
            $surface = 'rest';
        }
        // Read the already-resolved user straight from the global; never via
        // wp_get_current_user()/current_user_can(). Those trigger
        // determine_current_user resolution on first call, and application
        // password auth (wp_authenticate_application_password) writes
        // last_used/last_ip usermeta *during* that very resolution, before
        // $current_user is set. Calling back into resolution from here — a
        // query observer — re-enters determine_current_user mid-flight,
        // which re-authenticates and re-writes, observed again: unbounded
        // recursion (confirmed: OOM, one stack frame per retry).
        $actor = 0;
        $caps = '';
        global $current_user;
        if ($current_user instanceof \WP_User && $current_user->ID > 0) {
            $actor = (int) $current_user->ID;
            if ($current_user->has_cap('manage_options')) {
                $caps = 'manage_options';
            } elseif ($current_user->has_cap('edit_posts')) {
                $caps = 'edit_posts';
            }
        }
        return ['surface' => $surface, 'actor' => $actor, 'caps' => $caps];
    }

    /**
     * capability × surface. Only an editor-capable actor on an authoring
     * surface (wp-admin, authenticated REST) proposes authored. CLI and
     * ambiguous combinations abstain ('review'); everything else is runtime.
     */
    private static function propose(array $ctx): string {
        $capable = $ctx['caps'] !== '';
        return match (true) {
            ($ctx['surface'] === 'admin' || $ctx['surface'] === 'rest') && $capable => 'authored',
            $ctx['surface'] === 'cli' => 'review',
            ($ctx['surface'] === 'ajax' || $ctx['surface'] === 'front') && $capable => 'review',
            default => 'runtime',
        };
    }

    // ---------------------------------------------------------------- report

    /**
     * Presence of the provenance journal table, as a FACT rather than a verdict.
     *
     * Two readers need this probe and each owns a different refusal contract
     * for the answer — AdapterObservation's
     * `adapter_observation_prerequisite_absent`/`..._unreadable` pair
     * (AdapterObservation.php:180-206) and EffectDeclarationCoverage's own —
     * so what they share is the probe, never the prose: a reason code IS the
     * command's public contract and cannot be borrowed.
     *
     * `unusable` and `absent` stay separate answers because they are different
     * facts (no usable `$wpdb` at all, versus a usable one whose journal table
     * is not there); AdapterObservation folds both into its one prerequisite
     * refusal, which is exactly the behaviour it had before this extraction.
     *
     * Never repairs: no `Ledger::ensure()` on this path. A missing table is
     * evidence no report can honestly claim to have read, not an invitation to
     * create one.
     *
     * @return 'absent'|'present'|'unreadable'|'unusable'
     */
    public static function table_state(mixed $wpdb): string {
        if (!is_object($wpdb) || !is_string($wpdb->prefix ?? null)
            || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
            return 'unusable';
        }
        $table = $wpdb->prefix . 'wprism_journal';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $readError = $wpdb->last_error ?? '';
        // A failed probe is not evidence that the table is absent: reporting
        // `absent` here would let a broken database read be published as "this
        // site performed no writes", the exact silent-zero this whole read-only
        // path exists to refuse.
        if (!is_string($readError) || $readError !== '' || $found === false) {
            return 'unreadable';
        }
        return is_string($found) && $found === $table ? 'present' : 'absent';
    }

    /**
     * Aggregate proposals and score them against manifest ground truth.
     * Manifest-classified rows measure agreement (provenance is moot there);
     * unclassified rows ARE the product surface: the review queue.
     */
    public static function report(array $manifestNames): array {
        Ledger::ensure();
        return self::report_with_policy(Policy::load(null, $manifestNames), false);
    }

    /**
     * Read-only twin of report() for bounded evidence collection.
     *
     * The ordinary report is intentionally self-healing: the public command
     * has historically made the journal table available through Ledger::ensure()
     * before it asks the aggregate question.  That is the right posture for a
     * command whose normal prerequisite is "the agent owns its journal", but
     * it is the wrong posture for an observation export: a missing table is
     * evidence that no observation can honestly claim to have read, not an
     * invitation to create one.  AdapterObservation proves the table exists
     * before it calls this method; this method itself performs SELECT-only
     * aggregation and never repairs ledger state. Callers own the public
     * command refusal that translates a neutral repository-read failure.
     */
    public static function report_read_only(Policy $policy): array {
        return self::report_with_policy($policy, true);
    }

    private static function report_with_policy(Policy $policy, bool $strictRead): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT tbl, item, surface, caps, proposal, COUNT(*) AS n
             FROM {$wpdb->prefix}wprism_journal
             GROUP BY tbl, item, surface, caps, proposal
             ORDER BY tbl, item, surface",
            ARRAY_A
        );
        if ($strictRead && (!is_array($rows) || self::database_read_error($wpdb))) {
            self::refuse_read_error();
        }
        $rows = $rows ?: [];
        $out = ['rows' => [], 'agree' => 0, 'disagree' => 0, 'abstain' => 0, 'unclassified' => 0];
        foreach ($rows as $r) {
            $ground = self::ground_truth($policy, $r['tbl'], $r['item']);
            $expected = match ($ground) {
                'authored' => 'authored',
                'runtime', 'derived', 'env' => 'runtime',
                null => null,
                default => 'skip',
            };
            $verdict = 'unclassified';
            if ($expected === 'skip') {
                $verdict = 'managed';
            } elseif ($expected !== null) {
                if ($r['proposal'] === 'review') {
                    $verdict = 'abstain';
                    $out['abstain'] += (int) $r['n'];
                } elseif ($r['proposal'] === $expected) {
                    $verdict = 'agree';
                    $out['agree'] += (int) $r['n'];
                } else {
                    $verdict = 'disagree';
                    $out['disagree'] += (int) $r['n'];
                }
            } else {
                $out['unclassified'] += (int) $r['n'];
            }
            $out['rows'][] = [
                'table' => $r['tbl'], 'item' => $r['item'], 'surface' => $r['surface'],
                'caps' => $r['caps'], 'n' => (int) $r['n'],
                'proposal' => $r['proposal'], 'manifest' => $ground ?? '—', 'verdict' => $verdict,
            ];
        }
        $den = $out['agree'] + $out['disagree'];
        $out['agreement_pct'] = $den > 0 ? round(100 * $out['agree'] / $den, 1) : null;
        return $out;
    }

    /** A stale or malformed journal must not become a misleading zero report. */
    private static function database_read_error(mixed $wpdb): bool {
        $error = is_object($wpdb) ? ($wpdb->last_error ?? '') : '';
        return !is_string($error) || $error !== '';
    }

    private static function refuse_read_error(): never {
        throw new CommandRefusalException(
            'journal_evidence_unreadable',
            'the existing provenance journal could not be read',
            'inspect and repair the journal through the existing controlled workflow before reading provenance evidence',
            [[
                'code' => 'journal_evidence_unreadable',
                'message' => 'a failed journal read is not evidence that the journal is empty',
                'remediation' => 'restore readable provenance state before reading its aggregate evidence',
            ]],
            'wprism: provenance journal aggregate SELECT failed'
        );
    }

    /** Public so Pending::scan() can reuse the exact same ground-truth lookup
     *  (options/postmeta/termmeta -> manifest+policy class) instead of
     *  re-deriving it — report()'s own behavior/output is unchanged. */
    public static function ground_truth(Policy $policy, string $tbl, string $item): ?string {
        return self::ground_truth_details($policy, $tbl, $item)['class'];
    }

    /**
     * The same lookup, plus the manifest the winning rule came FROM.
     *
     * The `_details()` siblings on Policy return `{rule, source}` from exactly
     * the resolvers `option_rule()`/`post_meta_rule()`/`term_meta_rule()`/
     * `table_rule()` already call, so this is one lookup reported two ways
     * rather than a second walk: `ground_truth()` above now delegates here and
     * keeps returning only the class, so no existing caller's answer moves.
     *
     * `source` is the ATTRIBUTION channel a journal row otherwise lacks — the
     * table has no adapter column, and EffectDeclarationCoverage has to know
     * whose territory a write is in before it can say anything about whose
     * `effects[]` should have declared it. It is null whenever the class is
     * null, because a source without a rule names a manifest that classified
     * nothing.
     *
     * @return array{class:?string, source:?string}
     */
    public static function ground_truth_details(Policy $policy, string $tbl, string $item): array {
        if ($tbl === 'options' && $item !== '') {
            $details = $policy->option_rule_details($item);
        } elseif ($tbl === 'postmeta' && $item !== '') {
            $details = $policy->post_meta_rule_details($item);
        } elseif ($tbl === 'termmeta' && $item !== '') {
            $details = $policy->term_meta_rule_details($item);
        } else {
            $details = $policy->declared_table_details($tbl);
        }
        $rule = $details['rule'] ?? null;
        return [
            'class' => is_array($rule) ? ($rule['class'] ?? null) : null,
            'source' => is_array($rule) ? ($details['source'] ?? null) : null,
        ];
    }
}
