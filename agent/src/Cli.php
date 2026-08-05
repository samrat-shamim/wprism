<?php
namespace Duo;

use WP_CLI;

/**
 * wp duo <capture|plan|apply|journal-report|journal-reset>
 */
final class Cli {
    /**
     * Capture this environment's authored state into the site repo.
     *
     * ## OPTIONS
     * --repo=<path>    : Site repo root (contains site.duo.json).
     * [--out=<path>]   : Write the state tree elsewhere (determinism checks); skips ledger/media updates.
     * [--json]           : JSON summary (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     */
    public function capture($args, $assoc) {
        try {
            $summary = Capture::run($assoc['repo'] ?? WP_CLI::error('--repo required'), $assoc['out'] ?? null);
        } catch (\Throwable $t) {
            WP_CLI::error($t->getMessage());
        }
        // WP-CLI's dispatcher rewrites a bare --json into format=json and unsets
        // 'json' before the command runs (Runner::run_command()) — there is never
        // an $assoc['json'] key to isset() against.
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($summary, JSON_UNESCAPED_SLASHES));
            return;
        }
        foreach ($summary['warnings'] as $w) {
            WP_CLI::warning($w);
        }
        WP_CLI::success(sprintf(
            'captured %d posts, %d terms, %d menus, %d options file(s), %d media blob(s) -> %s',
            $summary['counts']['post'] ?? 0,
            $summary['counts']['term'] ?? 0,
            $summary['counts']['menu'] ?? 0,
            $summary['counts']['options'] ?? 0,
            $summary['media'],
            $summary['state_dir']
        ));
    }

    /**
     * Preview what apply would do (terraform-style).
     *
     * ## OPTIONS
     * --repo=<path>
     * [--adopt-by-slug=<kinds>] : e.g. terms,posts,menus
     * [--json]           : JSON output (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     */
    public function plan($args, $assoc) {
        try {
            $plan = Apply::plan($assoc['repo'] ?? WP_CLI::error('--repo required'), [
                'adopt_by_slug' => $assoc['adopt-by-slug'] ?? '',
            ]);
        } catch (\Throwable $t) {
            WP_CLI::error($t->getMessage());
        }
        // See capture(): --json arrives here as $assoc['format'] === 'json', never $assoc['json'].
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($plan, JSON_UNESCAPED_SLASHES));
            return;
        }
        foreach (['create', 'update', 'adopt', 'unchanged', 'drift', 'conflict', 'collision', 'delete'] as $kind) {
            foreach ($plan[$kind] as $r) {
                $line = strtoupper(str_pad($kind, 9)) . ' ' . ($r['path'] ?? ($r['type'] . ' ' . $r['uuid']));
                if (isset($r['blocked'])) {
                    $line .= '  [BLOCKED: ' . $r['blocked'] . ']';
                }
                WP_CLI::line($line);
            }
        }
        $counts = implode(', ', array_map(fn($k) => count($plan[$k]) . " $k", array_keys($plan)));
        WP_CLI::success("plan: $counts");
        if ($plan['drift']) {
            WP_CLI::warning('environment drift detected — capture-first workflow recommended');
        }
    }

    /**
     * Materialize the repo state into this environment.
     *
     * ## OPTIONS
     * --repo=<path>
     * [--adopt-by-slug=<kinds>]
     * [--with-deletes]
     * [--force-delete-referenced] : override referential delete guards.
     * [--force-theirs]
     * [--default-author=<login>]
     * [--revision=<rev>]
     * [--json]           : JSON output (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     */
    public function apply($args, $assoc) {
        try {
            $summary = Apply::apply($assoc['repo'] ?? WP_CLI::error('--repo required'), [
                'adopt_by_slug' => $assoc['adopt-by-slug'] ?? '',
                'with_deletes' => isset($assoc['with-deletes']),
                'force_delete_referenced' => isset($assoc['force-delete-referenced']),
                'force_theirs' => isset($assoc['force-theirs']),
                'default_author' => $assoc['default-author'] ?? '',
                'revision' => $assoc['revision'] ?? '',
            ]);
        } catch (\Throwable $t) {
            WP_CLI::error($t->getMessage());
        }
        // See capture(): --json arrives here as $assoc['format'] === 'json', never $assoc['json'].
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($summary, JSON_UNESCAPED_SLASHES));
            return;
        }
        foreach ($summary['warnings'] as $w) {
            WP_CLI::warning($w);
        }
        foreach ($summary['drift'] as $d) {
            WP_CLI::warning("drift (env ahead, untouched): $d");
        }
        WP_CLI::success(sprintf(
            'applied %d entities (canary %s) — plan was: %s',
            $summary['applied'],
            $summary['canary'],
            json_encode($summary['plan'])
        ));
    }

    /**
     * Aggregate the provenance journal and score proposals against manifests.
     *
     * ## OPTIONS
     * [--manifests=<names>] : comma-separated, default "core".
     * [--json]           : JSON output (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     *
     * @subcommand journal-report
     */
    public function journal_report($args, $assoc) {
        $names = array_filter(explode(',', $assoc['manifests'] ?? 'core'));
        try {
            $report = Journal::report($names);
        } catch (\Throwable $t) {
            WP_CLI::error($t->getMessage());
        }
        // wp-cli rewrites a bare --json into $assoc['format']='json' before
        // this method ever sees it (verified empirically: no 'json' key is
        // ever present) — check both so it's correct regardless of wp-cli
        // version/convention.
        if (isset($assoc['json']) || ($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($report, JSON_UNESCAPED_SLASHES));
            return;
        }
        foreach ($report['rows'] as $r) {
            WP_CLI::line(sprintf(
                '%-24s %-36s %-7s %-15s n=%-4d proposed=%-8s manifest=%-10s %s',
                $r['table'], $r['item'] !== '' ? $r['item'] : '—', $r['surface'],
                $r['caps'] !== '' ? $r['caps'] : 'anon', $r['n'], $r['proposal'], $r['manifest'], $r['verdict']
            ));
        }
        WP_CLI::line('');
        WP_CLI::success(sprintf(
            'agreement on manifest-classified writes: %s%% (agree %d / disagree %d), abstained %d, unclassified (the review queue) %d',
            $report['agreement_pct'] ?? 'n/a',
            $report['agree'], $report['disagree'], $report['abstain'], $report['unclassified']
        ));
    }

    /**
     * Agent + spec version — the stable probe for external tooling
     * (orchestrators check this instead of internal class names).
     */
    public function version($args, $assoc) {
        WP_CLI::line(json_encode([
            'agent' => DUO_AGENT_VERSION,
            'spec_version' => DUO_SPEC_VERSION,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Truncate the provenance journal.
     *
     * @subcommand journal-reset
     */
    public function journal_reset($args, $assoc) {
        global $wpdb;
        Ledger::ensure();
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}duo_journal");
        WP_CLI::success('journal truncated');
    }
}

WP_CLI::add_command('duo', Cli::class);
