<?php
namespace Duo\Orchestrator;

/**
 * Turns the JSON from `wp duo plan --format=json` (agent/src/Apply.php
 * build_plan(): keys create/update/unchanged/drift/conflict/adopt/
 * collision/delete, each a list of {uuid,type,path?,blocked?,env_id?}) into
 * `duo status`'s human summary.
 *
 * Two more top-level keys live outside BUCKETS and get their own handling
 * below, mirroring agent/src/Cli.php's plan() rendering deliberately (a
 * human reading `duo status` and one reading `wp duo plan` directly must
 * never see different advice for the same plan):
 *   - code_mismatch (agent/src/Deploy.php::code_mismatch(), docs/proposals/
 *     code-half.md §3.2): a DIFFERENT row shape —
 *     {issue,kind,plugin|theme,message,...}, no uuid/path — so label()
 *     below does not apply to it.
 *   - warnings: a plain list<string> (only Apply::plan()'s entry point
 *     attaches this to the returned array — see its own comment).
 */
final class PlanSummary {
    private const BUCKETS = ['create', 'update', 'adopt', 'unchanged', 'drift', 'conflict', 'collision', 'delete'];

    /** @return array{lines: list<string>, ok: bool} */
    public static function render(array $plan): array {
        $lines = [];
        $counts = [];
        foreach (self::BUCKETS as $k) {
            $counts[$k] = count($plan[$k] ?? []);
        }
        $codeMismatch = $plan['code_mismatch'] ?? [];
        $summary = 'plan: ' . implode(', ', array_map(fn($k) => "{$counts[$k]} $k", self::BUCKETS));
        $summary .= ', ' . count($codeMismatch) . ' code_mismatch';
        $lines[] = $summary;

        if (!empty($plan['drift'])) {
            $lines[] = 'drift (environment changed since last capture/apply — capture first):';
            foreach ($plan['drift'] as $r) {
                $lines[] = '  - ' . self::label($r);
            }
            // Verbatim match of agent/src/Cli.php's plan() warning for the
            // identical condition — see this class's own docblock.
            $lines[] = 'environment drift detected — capture-first workflow recommended';
        }

        $blocked = array_values(array_filter($plan['delete'] ?? [], fn($r) => isset($r['blocked'])));
        if ($blocked) {
            $lines[] = 'blocked deletes (referential guard):';
            foreach ($blocked as $r) {
                $lines[] = '  - ' . self::label($r) . ': ' . $r['blocked'];
            }
        }

        if (!empty($plan['conflict'])) {
            $lines[] = 'CONFLICT (repo and environment both changed since last sync):';
            foreach ($plan['conflict'] as $r) {
                $lines[] = '  - ' . self::label($r);
            }
        }

        if (!empty($plan['collision'])) {
            $lines[] = 'COLLISION (unmanaged env entity already has this slug — rerun with --adopt-by-slug or rename):';
            foreach ($plan['collision'] as $r) {
                $lines[] = '  - ' . self::label($r) . " (env id {$r['env_id']})";
            }
        }

        if ($codeMismatch) {
            $lines[] = "CODE_MISMATCH (this environment's installed code does not match what the target state declares active):";
            foreach ($codeMismatch as $r) {
                $what = $r['plugin'] ?? $r['theme'] ?? '?';
                $lines[] = '  - ' . strtoupper((string) ($r['issue'] ?? '?')) . ' ' . $what . ': ' . ($r['message'] ?? '');
            }
            // Verbatim match of agent/src/Cli.php's plan() warning.
            $lines[] = 'code_mismatch findings — duo apply will refuse until resolved (or run with --force-code-mismatch)';
        }

        foreach ($plan['warnings'] ?? [] as $w) {
            $lines[] = 'WARNING: ' . $w;
        }

        // --- fail-closed exit semantics (DUO-3221) ---
        //
        // `duo status` answers "safe to promote?" for this environment, so
        // ok must be false whenever build_plan() found anything that means
        // `duo apply` would refuse outright, PLUS one case apply itself
        // does not refuse on but status still must, because status is a
        // readiness probe, not merely an apply-will-refuse predictor:
        //   - conflict      : apply refuses without --force-theirs.
        //   - collision     : apply refuses without --adopt-by-slug=....
        //   - code_mismatch : apply refuses without --force-code-mismatch
        //                     (Apply::run(); Deploy::run() has the same
        //                     refuse-precondition for `duo deploy`).
        //   - blocked delete: `apply --with-deletes` refuses this row
        //                     without --force-delete-referenced. A status
        //                     reader can't know in advance whether the next
        //                     apply will even pass --with-deletes, so a
        //                     blocked row always counts as not-clean.
        //   - drift         : apply does NOT refuse on drift alone (a
        //                     drifted entity either folds into 'update' the
        //                     moment the repo side changes too, or just
        //                     stays 'drift' — neither is an apply-time
        //                     error). But drift means the ledger's "last
        //                     synced" baseline no longer matches this
        //                     environment, so the three-way comparison
        //                     `duo plan`/`duo status` just ran is already
        //                     measured against a stale base — not a "safe
        //                     to promote" state. Capture first (see the
        //                     rendered hint above).
        // Plain $plan['warnings'] entries are rendered loudly above but
        // never flip this by themselves: every warning either accompanies a
        // state already counted here, or is a deliberate, ratified warn-
        // only condition (e.g. a dangling ref dropped at capture time —
        // spec'd, correct, not a regression) that this project has decided
        // is not a blocking failure. Scanning warning TEXT to guess which
        // case applies would be fragile in a way structured plan data
        // isn't — ok is computed from counts/flags only, never from strings.
        $ok = $counts['conflict'] === 0
            && $counts['collision'] === 0
            && count($codeMismatch) === 0
            && !$blocked
            && $counts['drift'] === 0;

        return ['lines' => $lines, 'ok' => $ok];
    }

    private static function label(array $r): string {
        return $r['path'] ?? (($r['type'] ?? '?') . ' ' . ($r['uuid'] ?? '?'));
    }
}
