<?php
namespace Duo\Orchestrator;

/**
 * Turns the JSON from `wp duo plan --format=json` (agent/src/Apply.php
 * build_plan(): keys create/update/unchanged/drift/conflict/adopt/
 * collision/delete/delete_conflict/deleted, each a list of
 * {uuid,type,path?,blocked?,env_id?,reason?}) into
 * `duo status`'s human summary.
 *
 * More top-level keys live outside BUCKETS and get their own handling
 * below, mirroring agent/src/Cli.php's plan() rendering deliberately (a
 * human reading `duo status` and one reading `wp duo plan` directly must
 * never see different advice for the same plan):
 *   - code_mismatch (agent/src/Deploy.php::code_mismatch(), docs/proposals/
 *     code-half.md §3.2): a DIFFERENT row shape —
 *     {issue,kind,plugin|theme,message,...}, no uuid/path — so label()
 *     below does not apply to it.
 *   - warnings: a plain list<string> (only Apply::plan()'s entry point
 *     attaches this to the returned array — see its own comment).
 *   - incomplete_apply: a retained apply marker means authored writes may
 *     have committed but required rebuild/convergence work did not.
 *   - regen_pending (DUO-3234's Apply::build_plan(), design review addition
 *     1): a derived table with a hard per-entity availability dependency
 *     (e.g. TEC's tec_occurrences) whose verification failed on a PRIOR
 *     apply and has not yet been resolved by a later one. Row shape
 *     {uuid,type,post_type} — label() applies (uuid/type present), but the
 *     rendering below adds post_type since a plain uuid/type pair alone
 *     doesn't say what's actually pending.
 *   - env_missing (DUO-3232's Apply::build_plan()): a manifest-declared
 *     `class: "env"` option that is unset (row absent or empty string) on
 *     THIS environment. Row shape {name, required} — no uuid/path/type at
 *     all (label() does not apply), and never a value: env_missing exists
 *     to checklist WHICH values still need provisioning, never to leak
 *     what they should contain.
 */
final class PlanSummary {
    private const BUCKETS = [
        'create', 'update', 'adopt', 'unchanged', 'drift', 'conflict',
        'collision', 'delete', 'delete_conflict', 'deleted',
    ];

    /** @return array{lines: list<string>, ok: bool} */
    public static function render(array $plan): array {
        $lines = [];
        $counts = [];
        foreach (self::BUCKETS as $k) {
            $counts[$k] = count($plan[$k] ?? []);
        }
        $codeMismatch = $plan['code_mismatch'] ?? [];
        $incompleteApply = $plan['incomplete_apply'] ?? [];
        $regenPending = $plan['regen_pending'] ?? [];
        $envMissing = $plan['env_missing'] ?? [];
        $missingUser = $plan['missing_user'] ?? [];
        $skippedUserMeta = $plan['skipped_user_meta'] ?? [];
        $envMissingRequired = array_values(array_filter($envMissing, fn($r) => !empty($r['required'])));
        $summary = 'plan: ' . implode(', ', array_map(fn($k) => "{$counts[$k]} $k", self::BUCKETS));
        $summary .= ', ' . count($codeMismatch) . ' code_mismatch';
        $summary .= ', ' . count($incompleteApply) . ' incomplete_apply';
        $summary .= ', ' . count($regenPending) . ' regen_pending';
        $summary .= ', ' . count($envMissing) . ' env_missing';
        $summary .= ', ' . count($missingUser) . ' missing_user';
        $summary .= ', ' . count($skippedUserMeta) . ' skipped_user_meta';
        $lines[] = $summary;

        foreach (self::BUCKETS as $bucket) {
            foreach ($plan[$bucket] ?? [] as $row) {
                foreach ($row['widget_deletes'] ?? [] as $widget) {
                    $origin = !empty($widget['unmanaged']) ? 'unmanaged target default' : 'mapped target widget';
                    $lines[] = 'WIDGET_DELETE ' . self::label($row) . ': '
                        . ($widget['type'] ?? '?') . ' ' . ($widget['uuid'] ?? '?')
                        . " ($origin; absent from declared sidebar file)";
                }
            }
        }

        if (!empty($plan['drift'])) {
            $lines[] = 'drift (environment changed since last capture/apply — capture first):';
            foreach ($plan['drift'] as $r) {
                $lines[] = '  - ' . self::label($r);
            }
            // Verbatim match of agent/src/Cli.php's plan() warning for the
            // identical condition — see this class's own docblock.
            $lines[] = 'environment drift detected — capture-first workflow recommended';
        }

        $blocked = array_values(array_filter(
            array_merge($plan['delete'] ?? [], $plan['delete_conflict'] ?? []),
            fn($r) => isset($r['blocked'])
        ));
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

        if (!empty($plan['delete_conflict'])) {
            $lines[] = 'DELETE_CONFLICT (target differs from the tombstone expected base):';
            foreach ($plan['delete_conflict'] as $r) {
                $lines[] = '  - ' . self::label($r) . ': ' . ($r['reason'] ?? 'deletion base mismatch');
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

        if ($incompleteApply) {
            $lines[] = 'INCOMPLETE_APPLY (a prior promotion failed before required rebuild/convergence completed):';
            foreach ($incompleteApply as $r) {
                $lines[] = '  - ' . ($r['reason'] ?? 'retry required');
            }
        }

        if ($regenPending) {
            $lines[] = 'REGEN_PENDING (a derived-table verification failed on a prior apply and has not yet resolved):';
            foreach ($regenPending as $r) {
                $lines[] = '  - ' . self::label($r) . " (post type '" . ($r['post_type'] ?? '?') . "')";
            }
            $lines[] = 'regeneration retry pending — the next duo apply will retry it automatically';
        }

        if ($envMissing) {
            $lines[] = 'ENV_MISSING (manifest-declared env-bound options not yet provisioned on this environment):';
            foreach ($envMissing as $r) {
                $flag = !empty($r['required']) ? 'required' : 'optional';
                $lines[] = '  - ' . ($r['name'] ?? '?') . " ($flag)";
            }
            $lines[] = $envMissingRequired
                ? 'required env value(s) missing — provision with `wp duo env-set --name=<name> --value=<value>` (or --stdin) before promoting'
                : 'only optional env value(s) missing — safe to promote, listed for visibility';
        }

        if ($missingUser) {
            $lines[] = 'MISSING_USER (required exact login absent; apply will refuse before mutation):';
            foreach ($missingUser as $r) {
                $lines[] = '  - ' . self::label($r) . " (exact login '" . ($r['login'] ?? '?') . "')";
            }
        }

        if ($skippedUserMeta) {
            $lines[] = 'SKIPPED_USER_META (exact login absent; policy explicitly warns and leaves target untouched):';
            foreach ($skippedUserMeta as $r) {
                $lines[] = '  - ' . self::label($r) . " (exact login '" . ($r['login'] ?? '?') . "')";
            }
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
        //   - incomplete_apply: a previous apply already failed after or
        //                     during mutation/rebuild; status must remain
        //                     non-zero until the retry clears its marker.
        //   - regen_pending : a derived table with a hard per-entity
        //                     availability dependency (DUO-3234, e.g. TEC's
        //                     tec_occurrences) failed its post-apply
        //                     verification and has not yet resolved. `duo
        //                     apply` does NOT refuse on this alone (the
        //                     originating apply already completed — the
        //                     NEXT apply is what retries and either clears
        //                     it or hard-fails again), so this is the same
        //                     shape of decision as drift: not an
        //                     apply-will-refuse case, but a known
        //                     correctness gap in this environment's derived
        //                     state all the same, and "safe to promote?"
        //                     must say no while it stands.
        //   - env_missing   : a manifest-declared `class: "env"` option
        //                     (DUO-3232) unset on this environment. `duo
        //                     apply` never refuses on this — env values are
        //                     never captured/applied at all, so there is
        //                     nothing for apply's own preconditions to
        //                     check. But an unset REQUIRED value (e.g. a
        //                     payment gateway API key) means this
        //                     environment is running with a genuine gap an
        //                     operator must fill by hand (`wp duo env-set`),
        //                     so status must say no until it's provisioned
        //                     — same "known gap, not an apply-refuse case"
        //                     shape as regen_pending/drift above. Optional
        //                     entries (required: false) are listed for
        //                     visibility only and never flip ok — they are
        //                     plugin-internal bookkeeping the plugin itself
        //                     will populate, not an operator checklist item.
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
            && $counts['delete_conflict'] === 0
            && $counts['collision'] === 0
            && count($codeMismatch) === 0
            && !$incompleteApply
            && !$regenPending
            && !$envMissingRequired
            && !$missingUser
            && !$blocked
            && $counts['drift'] === 0;

        return ['lines' => $lines, 'ok' => $ok];
    }

    private static function label(array $r): string {
        return $r['path'] ?? (($r['type'] ?? '?') . ' ' . ($r['uuid'] ?? '?'));
    }
}
