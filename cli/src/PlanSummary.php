<?php
namespace Duo\Orchestrator;

require_once __DIR__ . '/PlanContract.php';

/**
 * Turns the JSON from `wp duo plan --format=json` (agent/src/Apply.php
 * build_plan(): keys create/update/unchanged/drift/conflict/adopt/
 * collision/delete/delete_conflict/deleted, each a list of
 * {uuid,type,path?,title?,blocked?,env_id?,reason?,conflict_view?}) into
 * `duo status`'s human summary.
 * `conflict_view`, when present on conflict/delete_conflict, is the versioned
 * hash-only base/repository/target evidence and bounded choice model from
 * spec/repo-format.md; renderers must never invent raw values from hashes.
 *
 * More top-level keys live outside BUCKETS and get their own handling
 * below, mirroring agent/src/Cli.php's plan() rendering deliberately (a
 * human reading `duo status` and one reading `wp duo plan` directly must
 * never see different advice for the same plan):
 *   - code_mismatch (agent/src/Deploy.php::code_mismatch(), docs/proposals/
 *     code-half.md §3.2): a DIFFERENT row shape —
 *     {issue,kind,plugin|theme,message,...}, no uuid/path — so label()
 *     below does not apply to it. `code_revision_stale` is the one
 *     non-forceable member: it names an unfinalized code payload and must
 *     direct the operator to the host `duo deploy <env>` workflow.
 *   - code_drift (agent/src/Deploy.php::code_drift()): a similarly shaped
 *     plugin/theme finding, but it means an otherwise-compatible installed
 *     version changed after Duo's last trusted observation. Apply refuses it
 *     unless explicitly forced, so status must count, render, and block it.
 *   - warnings: a plain list<string> (only Apply::plan()'s entry point
 *     attaches this to the returned array — see its own comment).
 *   - incomplete_apply: a retained apply marker means authored writes may
 *     have committed but required rebuild/convergence work did not.
 *   - incomplete_lifecycle: a durable pre-hook receipt means a lifecycle API
 *     may have committed canonical state before throwing. It is non-forceable
 *     and requires the exact pre-lifecycle checkpoint recovery sequence.
 *   - regen_context (DUO-3342): an outstanding pre-delete inventory or
 *     pre-move receipt whose derived-state repair no consumer has verified.
 *     Same "known correctness gap, not an apply-refuse case" footing as
 *     regen_pending below, and surfaced for the same reason: DUO-3342 made
 *     those markers survive a failed apply instead of being swept, so one can
 *     now stand between a failure and its retry where an operator can see it.
 *   - regen_pending (DUO-3234's Apply::build_plan(), design review addition
 *     1): a derived table with a hard per-entity availability dependency
 *     (e.g. TEC's tec_occurrences) whose verification failed on a PRIOR
 *     apply and has not yet been resolved by a later one. Row shape
 *     {uuid,type,post_type} — label() applies (uuid/type present), but the
 *     rendering below adds post_type since a plain uuid/type pair alone
 *     doesn't say what's actually pending. Deliberately independent of
 *     incomplete_apply above rather than folded into it — both flip `ok`
 *     on their own because they mean different things (whole-apply retry
 *     vs. one entity's derived-state gap) and regen_pending resolves
 *     through a path incomplete_apply cannot reach at all (no apply needs
 *     to have failed — see Apply::regen_dependencies()'s own docblock,
 *     "RESOLVED (DUO-3245)", for the full reasoning and the live proof).
 *   - env_missing (DUO-3232's Apply::build_plan()): a manifest-declared
 *     `class: "env"` option that is unset (row absent or empty string) on
 *     THIS environment. Row shape {name, required} — no uuid/path/type at
 *     all (label() does not apply), and never a value: env_missing exists
 *     to checklist WHICH values still need provisioning, never to leak
 *     what they should contain.
 *   - adapter_dispositions (stable wire key; DUO-3224/DUO-3227): selected
 *     manifests whose generated capability verdict is blocked. Row shape
 *     {name,status,code,reason}; host promotion consumes the same compiled
 *     claim before lease/checkpoint/mutation.
 *   - provider_problems (DUO-3339's Apply::plan(), closing spec/repo-format.md
 *     bound (4)): the rows `Providers::diagnose()` produces for every provider
 *     capability the PINNED manifests declare — missing plugin, inactive
 *     plugin, out-of-range version, absent provider, contract or identity
 *     mismatch, unadvertised capability. Row shape {provider,manifest,plugin,
 *     code,expected,found,remediation,message} — no uuid/path, so label()
 *     does not apply. Rendered and counted, but deliberately NOT part of `ok`
 *     below: see that decision matrix's own entry for why.
 */
final class PlanSummary {
    private const BUCKETS = [
        'create', 'update', 'adopt', 'unchanged', 'drift', 'conflict',
        'collision', 'delete', 'delete_conflict', 'deleted',
    ];

    /** Refuse valid JSON which is not the complete agent plan contract. */
    public static function assertContract(array $plan): void {
        try {
            PlanContract::requireComplete($plan, 'status');
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('incomplete plan contract: ' . $e->getMessage(), 0, $e);
        }
        foreach ($plan['warnings'] as $warning) {
            if (!is_string($warning)) {
                throw new \RuntimeException('incomplete plan contract: warnings must be strings');
            }
        }
    }

    /**
     * @param list<string> $viewCategories empty preserves the legacy full
     *   category summary; an explicit filtered status supplies its canonical
     *   requested subset while all readiness/global evidence remains full.
     * @param ?string $environment When status supplies its environment name,
     *   render host-side remediation. Null preserves target-side advice for
     *   callers that do not own an environment registry.
     * @return array{lines: list<string>, ok: bool}
     */
    public static function render(array $plan, array $viewCategories = [], ?string $environment = null): array {
        $lines = [];
        $counts = [];
        foreach (self::BUCKETS as $k) {
            $counts[$k] = count($plan[$k] ?? []);
        }
        $codeMismatch = $plan['code_mismatch'] ?? [];
        $codeRevisionStale = array_values(array_filter(
            $codeMismatch,
            static fn(array $r): bool => ($r['issue'] ?? null) === 'code_revision_stale'
        ));
        $runtimeCompatibility = array_values(array_filter(
            $codeMismatch,
            static fn(array $r): bool => ($r['issue'] ?? null) !== 'code_revision_stale'
                && !empty($r['non_forceable'])
        ));
        $forceableCodeMismatch = array_values(array_filter(
            $codeMismatch,
            static fn(array $r): bool => ($r['issue'] ?? null) !== 'code_revision_stale'
                && empty($r['non_forceable'])
        ));
        $codeDrift = $plan['code_drift'] ?? [];
        $incompleteApply = $plan['incomplete_apply'] ?? [];
        $incompleteLifecycle = $plan['incomplete_lifecycle'] ?? [];
        $regenPending = $plan['regen_pending'] ?? [];
        $regenContext = $plan['regen_context'] ?? [];
        $envMissing = $plan['env_missing'] ?? [];
        $missingUser = $plan['missing_user'] ?? [];
        $skippedUserMeta = $plan['skipped_user_meta'] ?? [];
        $uploadsInventory = $plan['uploads_inventory'] ?? [];
        $effectsInventory = $plan['effects_inventory'] ?? [];
        $adapterDispositions = $plan['adapter_dispositions'] ?? [];
        $providerProblems = $plan['provider_problems'] ?? [];
        $envMissingRequired = array_values(array_filter($envMissing, fn($r) => !empty($r['required'])));
        $summary = 'plan: ' . implode(', ', array_map(fn($k) => "{$counts[$k]} $k", self::BUCKETS));
        $summary .= ', ' . count($codeMismatch) . ' code_mismatch';
        $summary .= ', ' . count($codeDrift) . ' code_drift';
        $summary .= ', ' . count($incompleteApply) . ' incomplete_apply';
        $summary .= ', ' . count($incompleteLifecycle) . ' incomplete_lifecycle';
        $summary .= ', ' . count($regenPending) . ' regen_pending';
        $summary .= ', ' . count($regenContext) . ' regen_context';
        $summary .= ', ' . count($envMissing) . ' env_missing';
        $summary .= ', ' . count($missingUser) . ' missing_user';
        $summary .= ', ' . count($skippedUserMeta) . ' skipped_user_meta';
        $summary .= ', ' . count($uploadsInventory) . ' upload_mutations';
        $summary .= ', ' . count($effectsInventory) . ' declared_effects';
        $summary .= ', ' . count($adapterDispositions) . ' adapter_dispositions';
        $summary .= ', ' . count($providerProblems) . ' provider_problems';
        $lines[] = $summary;

        // DUO-3345 slice 5: render only the optional, strictly validated
        // projection. Older agents omit it; the host cannot reconstruct
        // attachment provenance, selected actions, or blocker origin from
        // detailed rows alone, so it leaves category lines out.
        foreach (PlanContract::categorySummaryHumanLines($plan['category_summary'] ?? null, $viewCategories) as $line) {
            $lines[] = $line;
        }

        foreach ($uploadsInventory as $row) {
            $directory = (string) ($row['derivative_directory'] ?? '');
            $root = ($directory !== '' ? $directory . '/' : '')
                . (string) ($row['derivative_basename_prefix'] ?? '?') . '*';
            $lines[] = 'UPLOAD_MUTATION ' . ($row['original_path'] ?? '?')
                . " (derivatives: $root)";
        }

        foreach ($effectsInventory as $row) {
            $effect = (array) ($row['effect'] ?? []);
            $selector = (array) ($effect['selector'] ?? []);
            $lines[] = 'EFFECT ' . ($row['phase'] ?? '?') . ' '
                . ($effect['mode'] ?? '?') . ' ' . ($effect['id'] ?? '?') . ' '
                . ($selector['type'] ?? '?') . ':' . ($selector['value'] ?? '?');
        }

        $seenAnnotations = [];
        foreach (self::BUCKETS as $bucket) {
            foreach ($plan[$bucket] ?? [] as $row) {
                foreach ($row['annotations'] ?? [] as $annotation) {
                    if (!isset($seenAnnotations[$annotation])) {
                        $lines[] = 'PLAN NOTE: ' . $annotation;
                        $seenAnnotations[$annotation] = true;
                    }
                }
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
                foreach (self::conflictViewLines($r) as $detail) {
                    $lines[] = '    ' . $detail;
                }
            }
        }

        if (!empty($plan['delete_conflict'])) {
            $lines[] = 'DELETE_CONFLICT (target differs from the tombstone expected base):';
            foreach ($plan['delete_conflict'] as $r) {
                $lines[] = '  - ' . self::label($r) . ': ' . ($r['reason'] ?? 'deletion base mismatch');
                foreach (self::conflictViewLines($r) as $detail) {
                    $lines[] = '    ' . $detail;
                }
            }
        }

        if (!empty($plan['collision'])) {
            $lines[] = 'COLLISION (unmanaged env entity already has this slug — rerun with --adopt-by-slug or rename):';
            foreach ($plan['collision'] as $r) {
                $lines[] = '  - ' . self::label($r) . " (env id {$r['env_id']})";
            }
        }

        if ($codeRevisionStale) {
            $lines[] = 'CODE_REVISION_STALE (the compiled code payload is not verified and finalized on this environment):';
            foreach ($codeRevisionStale as $r) {
                $revision = (string) ($r['expected_revision'] ?? '?');
                $lines[] = '  - expected code revision ' . $revision . ': '
                    . ($r['message'] ?? 'run the host duo deploy workflow');
            }
            $lines[] = 'code revision is stale — run `duo deploy <env>`; this ordering invariant cannot be bypassed by force flags';
        }

        if ($runtimeCompatibility) {
            $lines[] = 'CODE_RUNTIME_INCOMPATIBLE (frozen plugin/theme requirements exceed or lack exact target PHP/WordPress evidence):';
            foreach ($runtimeCompatibility as $r) {
                $what = $r['plugin'] ?? $r['theme'] ?? $r['identity'] ?? '?';
                $lines[] = '  - ' . strtoupper((string) ($r['issue'] ?? 'runtime_requirement_unmet'))
                    . ' ' . $what . ': ' . ($r['message'] ?? 'target runtime requirement is not satisfied');
            }
            $lines[] = 'code runtime compatibility is non-forceable — correct the header or target runtime; certification baselines and force flags cannot bypass it';
        }

        if ($forceableCodeMismatch) {
            $lines[] = "CODE_MISMATCH (this environment's installed code does not match what the target state declares active):";
            foreach ($forceableCodeMismatch as $r) {
                $what = $r['plugin'] ?? $r['theme'] ?? '?';
                $lines[] = '  - ' . strtoupper((string) ($r['issue'] ?? '?')) . ' ' . $what . ': ' . ($r['message'] ?? '');
            }
            // Verbatim match of agent/src/Cli.php's plan() warning.
            $lines[] = 'code_mismatch findings — duo apply will refuse until resolved (or run with --force-code-mismatch)';
        }

        if ($codeDrift) {
            $lines[] = 'CODE_DRIFT (managed code changed outside Duo since its last trusted observation):';
            foreach ($codeDrift as $r) {
                $what = $r['plugin'] ?? $r['theme'] ?? '?';
                $lines[] = '  - ' . strtoupper((string) ($r['issue'] ?? 'code_drift')) . ' ' . $what
                    . ': ' . ($r['message'] ?? 'installed code differs from the recorded baseline');
            }
            $lines[] = 'code_drift findings — duo apply will refuse until resolved (or run with --force-code-drift)';
        }

        foreach ($plan['warnings'] ?? [] as $w) {
            // Apply attaches one target-form warning for each required row.
            // Host status owns the environment name and renders the exact
            // host command below, so repeating this warning would mix two
            // execution surfaces in one first-time-user diagnosis.
            if ($environment !== null && self::isRequiredEnvMissingWarning((string) $w, $envMissing)) {
                continue;
            }
            $lines[] = 'WARNING: ' . $w;
        }

        if ($incompleteApply) {
            $lines[] = 'INCOMPLETE_APPLY (a prior promotion failed before required rebuild/convergence completed):';
            foreach ($incompleteApply as $r) {
                $lines[] = '  - ' . ($r['reason'] ?? 'retry required');
            }
        }

        if ($incompleteLifecycle) {
            $lines[] = 'INCOMPLETE_LIFECYCLE (a hook window failed after its durable pre-hook boundary):';
            foreach ($incompleteLifecycle as $r) {
                $lines[] = '  - ' . ($r['phase'] ?? '?') . ' ' . ($r['entity'] ?? '?')
                    . ' at ' . ($r['before_hash'] ?? '?') . ': '
                    . ($r['reason'] ?? 'exact checkpoint recovery required');
            }
            $lines[] = 'lifecycle state is ambiguous — restore the exact pre-lifecycle database checkpoint; force flags cannot bypass this receipt';
        }

        if ($regenPending) {
            $lines[] = 'REGEN_PENDING (a derived-table verification failed on a prior apply and has not yet resolved):';
            foreach ($regenPending as $r) {
                $lines[] = '  - ' . self::label($r) . " (post type '" . ($r['post_type'] ?? '?') . "')";
            }
            $lines[] = 'regeneration retry pending — the next duo apply will retry it automatically';
        }

        if ($regenContext) {
            $lines[] = 'REGEN_CONTEXT (a pre-delete/pre-move receipt is outstanding and no consumer has verified its repair):';
            foreach ($regenContext as $r) {
                $lines[] = '  - ' . self::label($r) . " (post type '" . ($r['post_type'] ?? '?') . "', "
                    . ($r['kind'] ?? '?') . ' receipt)';
            }
            $lines[] = 'derived-state receipt outstanding — the next duo apply reaching that surface redelivers it to its declared consumer';
        }

        if ($envMissing) {
            $lines[] = 'ENV_MISSING (manifest-declared env-bound options not yet provisioned on this environment):';
            foreach ($envMissing as $r) {
                $flag = !empty($r['required']) ? 'required' : 'optional';
                $name = is_string($r['name'] ?? null) ? $r['name'] : null;
                $line = '  - ' . self::displayToken($name) . " ($flag)";
                if ($environment !== null && !empty($r['required']) && is_string($r['name'] ?? null)) {
                    $environmentArg = self::environmentArg($environment);
                    $nameArg = self::shellArg($r['name']);
                    $line .= $environmentArg !== null && $nameArg !== null
                        ? '; run: `duo env-set ' . $environmentArg . ' --name=' . $nameArg . ' --stdin`'
                        : '; cannot render a safe command — correct the environment/manifest name';
                }
                $lines[] = $line;
            }
            if ($envMissingRequired) {
                $lines[] = $environment !== null
                    ? 'required env value(s) missing — run each required item command above before promoting'
                    : 'required env value(s) missing — provision with `wp duo env-set --name=<name> --value=<value>` (or --stdin) before promoting';
            } else {
                $lines[] = 'only optional env value(s) missing — safe to promote, listed for visibility';
            }
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

        if ($adapterDispositions) {
            $lines[] = 'CAPABILITY_REGISTRY (a pinned manifest/revision/target is not certified for promotion):';
            foreach ($adapterDispositions as $r) {
                // Keep lockstep with agent/src/Cli.php's plan renderer: an
                // out-of-tree adapter must not read like a shipped adapter that
                // failed review, so source and trust tier stay on the row and
                // its remediation gets its own line (DUO-3314).
                $lines[] = '  - ' . ($r['name'] ?? '?') . ' [' . ($r['status'] ?? 'unreviewed')
                    . '] [source=' . ($r['source'] ?? 'shipped') . ' tier=' . ($r['trust_tier'] ?? 'unknown')
                    . ' certification=' . ($r['certification'] ?? 'registry')
                    . '] [' . ($r['code'] ?? 'not_certified') . ']: ' . ($r['reason'] ?? 'not certified');
                if (($r['remediation'] ?? '') !== '') {
                    $lines[] = '    remediation: ' . $r['remediation'];
                }
            }
            $lines[] = 'experimental, uncertified out-of-tree, unsupported, version-mismatched, or expired-evidence claims cannot make readiness green';
        }

        if ($providerProblems) {
            $lines[] = 'PROVIDER_PROBLEM (a pinned manifest declares a provider capability this environment cannot supply):';
            foreach ($providerProblems as $r) {
                // Keep lockstep with agent/src/Cli.php's plan renderer: the
                // declaring manifest and the owning plugin stay on the row —
                // an operator has to know which pin and which plugin to go fix
                // — and the remediation gets its own line (DUO-3339).
                $lines[] = '  - ' . ($r['provider'] ?? '?')
                    . ' [manifest=' . ($r['manifest'] ?? '?') . ' plugin=' . ($r['plugin'] ?? '?')
                    . '] [' . ($r['code'] ?? 'unknown') . ']: expected ' . ($r['expected'] ?? '?')
                    . ', found ' . ($r['found'] ?? '?');
                if (($r['remediation'] ?? '') !== '') {
                    $lines[] = '    remediation: ' . $r['remediation'];
                }
            }
            $lines[] = 'duo apply refuses before target mutation on any of these its own selected work reaches';
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
        //   - code_revision_stale: apply always refuses this ordering
        //                     invariant until the host `duo deploy <env>`
        //                     flow verifies/finalizes the descriptor; it is
        //                     deliberately not forceable.
        //   - code_mismatch : remaining lifecycle compatibility rows refuse
        //                     without --force-code-mismatch (Apply::run();
        //                     Deploy::run() has the same refuse-precondition
        //                     for `duo deploy`).
        //   - code_drift    : apply refuses without --force-code-drift;
        //                     unlike ordinary content drift, this is a
        //                     version/provenance precondition for state
        //                     writes and must not be omitted from status.
        //   - incomplete_apply: a previous apply already failed after or
        //                     during mutation/rebuild; status must remain
        //                     non-zero until the retry clears its marker.
        //   - incomplete_lifecycle: a hook may have committed canonical state
        //                     before failure. Only exact checkpoint recovery
        //                     clears its durable, non-forceable receipt.
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
        //   - adapter_dispositions: an external review status other than
        //                     certified cannot make readiness green, even
        //                     though lower-level agent calls stay available
        //                     to exercise experimental/test fixtures.
        // One bucket is rendered and counted above but deliberately NOT here:
        //   - provider_problems (DUO-3339): the NARROWED provider diagnosis
        //                     already exists and is already in `ok` — it just
        //                     is not this bucket. DUO-3314's
        //                     Policy::provider_readiness_blockers($selected)
        //                     negotiates exactly the actions this plan's own
        //                     work reaches and merges its rows into
        //                     adapter_dispositions above, which the `ok`
        //                     expression counts. So a provider this revision
        //                     genuinely needs and cannot get DOES make status
        //                     non-zero, through that bucket.
        //                     provider_problems is the complement: every
        //                     provider capability the PINNED manifests
        //                     declare, minus the ones already reported as
        //                     gating (Providers::problems() drops those, so
        //                     one fact is never stated twice). What is left is
        //                     real but not reached by this revision — a
        //                     pinned adapter whose plugin is absent while
        //                     nothing in this diff touches its surfaces. `ok`
        //                     answers "will promoting THIS revision refuse?",
        //                     and flipping it on a capability this revision
        //                     never reaches would predict a refusal that is
        //                     not going to happen. The rows stay loud and
        //                     counted because the gap is real and an operator
        //                     has to see it before it becomes the next
        //                     revision's blocker.
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
            && count($codeDrift) === 0
            && !$incompleteApply
            && !$incompleteLifecycle
            && !$regenPending
            && !$regenContext
            && !$envMissingRequired
            && !$missingUser
            && !$adapterDispositions
            && !$blocked
            && $counts['drift'] === 0;

        return ['lines' => $lines, 'ok' => $ok];
    }

    /** @param list<array<string,mixed>> $envMissing */
    private static function isRequiredEnvMissingWarning(string $warning, array $envMissing): bool {
        foreach ($envMissing as $row) {
            if (empty($row['required']) || !is_string($row['name'] ?? null)) {
                continue;
            }
            $name = $row['name'];
            $expected = "env_missing: option '$name' is required and not yet provisioned on "
                . "this environment — see 'wp duo env-set --name=$name --stdin'";
            if ($warning === $expected) {
                return true;
            }
        }
        return false;
    }

    /** A readable shell token when safe; POSIX quoting otherwise. */
    private static function shellArg(string $value): ?string {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }
        return preg_match('/^[A-Za-z0-9._:\/-]+$/D', $value) === 1
            ? $value
            : escapeshellarg($value);
    }

    /** Environment is a positional token, so option-looking names never render. */
    private static function environmentArg(string $value): ?string {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $value) === 1
            ? $value
            : null;
    }

    /** Keep untrusted plan names on one terminal line. */
    private static function displayToken(?string $value): string {
        if ($value === null || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return '<invalid-name>';
        }
        return $value;
    }

    private static function label(array $r): string {
        $label = is_string($r['path'] ?? null)
            ? (string) $r['path']
            : ((string) ($r['type'] ?? '?') . ' ' . (string) ($r['uuid'] ?? '?'));
        $label = self::oneLine($label);
        if ($label === '') {
            $label = '?';
        }
        // Keep lockstep with agent/src/Cli.php's plan line renderer: the raw
        // authored title stays in plan JSON; status now itemizes selected
        // clean rows in a filtered view, so strip every C0/DEL byte before
        // collapsing whitespace rather than allowing ANSI/newline injection.
        if (is_string($r['title'] ?? null)) {
            $title = self::oneLine((string) $r['title']);
            if ($title !== '') {
                $label .= " '" . $title . "'";
            }
        }
        return $label;
    }

    private static function oneLine(string $value): string {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $value);
        $value = (string) preg_replace('/\s+/', ' ', $value);
        return trim($value);
    }

    /**
     * Keep the host summary aligned with the direct `wp duo plan` renderer:
     * exact full hashes stay in JSON while the human view foregrounds roles,
     * intent, and safe resolution with only bounded hash prefixes.
     *
     * @return list<string>
     */
    private static function conflictViewLines(array $row): array {
        $view = $row['conflict_view'] ?? null;
        if (!is_array($view) || ($view['format'] ?? null) !== 'duo-plan-conflict/v1') {
            return [];
        }
        $base = (array) ($view['base'] ?? []);
        $repository = (array) ($view['repository'] ?? []);
        $target = (array) ($view['target'] ?? []);
        $lines = [
            'WHY ' . ($view['reason_code'] ?? 'plan_conflict'),
            'BASE last-synced: ' . ($base['state'] ?? 'unknown')
                . ' ' . self::hashLabel($base['content_hash'] ?? null),
            'REPOSITORY intent=' . ($repository['intent'] ?? 'unknown')
                . ' state=' . self::hashLabel($repository['content_hash'] ?? null)
                . ' expected-base=' . self::hashLabel($repository['expected_base_hash'] ?? null),
            'TARGET observation: intent=' . ($target['intent'] ?? 'unknown')
                . ' state=' . ($target['state'] ?? 'unknown')
                . ' ' . self::hashLabel($target['content_hash'] ?? null),
            'SAFE CHOICE reconcile_in_repository: preserve both intents; capture the target change, resolve it in the repository, then re-plan',
        ];
        $choices = array_values(array_filter(
            (array) ($view['choices'] ?? []),
            static fn($choice): bool => is_array($choice) && ($choice['id'] ?? null) === 'apply_repository'
        ));
        if ($choices) {
            $choice = $choices[0];
            $requires = implode(' ', array_map('strval', (array) ($choice['requires'] ?? [])));
            $effect = ($choice['effect'] ?? '') === 'delete_target_authored_state'
                ? 'delete target authored state'
                : 'replace target authored state';
            $lines[] = 'DESTRUCTIVE OVERRIDE apply_repository'
                . ($requires === '' ? '' : " ($requires)") . ": $effect";
        }
        return $lines;
    }

    private static function hashLabel(mixed $hash): string {
        if (!is_string($hash) || $hash === '') {
            return 'none';
        }
        return 'sha256:' . substr($hash, 0, 12);
    }
}
