<?php
namespace Duo;

/**
 * Provenance journal (proposal generator, never authority): observes DB write
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

    public static function boot(): void {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        // DUO_JOURNAL (wp-config.php) is the deployment-time switch, but it's
        // sourced from WORDPRESS_CONFIG_EXTRA and eval()'d fresh every
        // request straight from the container's environment — nothing
        // reachable at runtime (file edits, wp-cli, opcache invalidation)
        // can override it without recreating the container. This option is
        // a live kill switch for the same effect (e.g. overhead A/B
        // measurement) without needing that.
        if (get_option('duo_journal_disabled')) {
            return;
        }
        add_filter('query', [self::class, 'observe'], -2147483646);
        add_action('shutdown', [self::class, 'flush'], PHP_INT_MAX);
    }

    public static function observe($sql) {
        if (!is_string($sql) || !preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql, $m)) {
            return $sql;
        }
        $op = strtoupper($m[1]);
        $tbl = self::table_of($sql, $op);
        if ($tbl === null) {
            return $sql;
        }
        $tbl = self::unprefix($tbl);
        if (str_starts_with($tbl, 'duo_')) {
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
        global $wpdb;
        if (!self::$buffer || !isset($wpdb)) {
            return;
        }
        $rows = self::$buffer;
        self::$buffer = [];
        remove_filter('query', [self::class, 'observe'], -2147483646);
        Ledger::ensure();
        $values = [];
        $params = [];
        foreach ($rows as $r) {
            $values[] = '(%s,%s,%s,%s,%s,%d,%s,%s,%s)';
            array_push($params, $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7], $r[8]);
        }
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}duo_journal (t, op, tbl, item, surface, actor, caps, hook, proposal) VALUES "
            . implode(',', $values),
            $params
        ));
        add_filter('query', [self::class, 'observe'], -2147483646);
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
     * Aggregate proposals and score them against manifest ground truth.
     * Manifest-classified rows measure agreement (provenance is moot there);
     * unclassified rows ARE the product surface: the review queue.
     */
    public static function report(array $manifestNames): array {
        global $wpdb;
        Ledger::ensure();
        $policy = Policy::load(null, $manifestNames);
        $rows = $wpdb->get_results(
            "SELECT tbl, item, surface, caps, proposal, COUNT(*) AS n
             FROM {$wpdb->prefix}duo_journal
             GROUP BY tbl, item, surface, caps, proposal
             ORDER BY tbl, item, surface",
            ARRAY_A
        ) ?: [];
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

    /** Public so Pending::scan() can reuse the exact same ground-truth lookup
     *  (options/postmeta/termmeta -> manifest+policy class) instead of
     *  re-deriving it — report()'s own behavior/output is unchanged. */
    public static function ground_truth(Policy $policy, string $tbl, string $item): ?string {
        if ($tbl === 'options' && $item !== '') {
            return $policy->option_rule($item)['class'] ?? null;
        }
        if ($tbl === 'postmeta' && $item !== '') {
            return $policy->post_meta_rule($item)['class'] ?? null;
        }
        if ($tbl === 'termmeta' && $item !== '') {
            return $policy->term_meta_rule($item)['class'] ?? null;
        }
        $t = $policy->table_rule($tbl);
        return $t['class'] ?? null;
    }
}
