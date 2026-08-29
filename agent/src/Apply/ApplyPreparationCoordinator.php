<?php
namespace WPrism;

require_once __DIR__ . '/ApplyPreparationRequest.php';
require_once __DIR__ . '/PreparedApply.php';
require_once __DIR__ . '/../Delete/DeleteGuardLockCoordinator.php';
require_once __DIR__ . '/../Policy/Policy.php';
// WP-2.8: enforce_code_mismatch_gate() names the graduated verdict by
// constant, so the one place apply stops refusing cannot drift from the one
// place LifecyclePlanner mints it.
require_once __DIR__ . '/../Policy/VersionEvidenceGrammar.php';
require_once __DIR__ . '/ApplyServices.php';
require_once __DIR__ . '/../Rebuild/RebuildSelection.php';
require_once __DIR__ . '/../Scope/ScopedApplyWorkflow.php';
require_once __DIR__ . '/ApplyPlanner.php';
require_once __DIR__ . '/../Scope/ScopedApplyWorkProjector.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Scope/ScopedApply.php';
require_once __DIR__ . '/../Repository/CanonicalSurfaces.php';
require_once __DIR__ . '/../Kernel/Canon.php';
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}
if (!class_exists(Capture::class, false)
    && (!class_exists(Ledger::class, false) || method_exists(Ledger::class, 'ensure'))) {
    require_once __DIR__ . '/../Capture/Capture.php';
}
require_once __DIR__ . '/../Scope/ScopedApplySession.php';
require_once __DIR__ . '/../Promotion/ScopedPromotionAuthority.php';
require_once __DIR__ . '/../Promotion/PromotionLock.php';

/** Proves every plan and capability precondition before authored writes begin. */
final class ApplyPreparationCoordinator {
    public function __construct(
        private readonly string $repo,
        private readonly Policy $policy,
        private readonly ApplyServices $services,
        private readonly RebuildSelection $rebuildSelection,
        private readonly ScopedApplyWorkflow $scopedWorkflow,
        private readonly \Closure $buildPlan,
        private readonly \Closure $renewPromotionLock
    ) {}

    public function prepare(
        ApplyPreparationRequest $request,
        array &$warnings,
        array &$forcedOverrideEvidence
    ): PreparedApply {
        $opts = $request->options;
        $plan = $request->plan;
        $tree = $request->tree;

        if (!$request->recoveringScoped && $plan['collision']) {
            $list = implode("\n  - ", array_map(
                fn($r) => "{$r['type']} {$r['path']} collides with env id {$r['env_id']} (same slug, different/no uuid)",
                $plan['collision']
            ));
            throw new \RuntimeException(
                "wprism: slug collisions need explicit resolution (--adopt-by-slug=posts,terms,menus,tables adopts unmanaged rows):\n  - $list"
            );
        }
        if (!$request->recoveringScoped && $plan['conflict'] && empty($opts['force_theirs'])) {
            $list = implode("\n  - ", array_column($plan['conflict'], 'path'));
            throw new \RuntimeException(
                "wprism: conflicts (env and repo both changed since last sync) — capture first or --force-theirs:\n  - $list"
            );
        }
        if (!$request->recoveringScoped && $plan['delete_conflict'] && empty($opts['force_theirs'])) {
            $list = implode("\n  - ", array_map(
                fn($r) => "{$r['path']}: {$r['reason']}",
                $plan['delete_conflict']
            ));
            throw new \RuntimeException(
                "wprism: deletion conflicts (target differs from the tombstone's expected base) — "
                . "capture/reconcile first or --force-theirs:\n  - $list"
            );
        }
        if (!$request->recoveringScoped && $plan['delete_conflict'] && empty($opts['with_deletes'])) {
            $list = implode("\n  - ", array_map(
                fn($r) => "{$r['path']}: {$r['reason']}",
                $plan['delete_conflict']
            ));
            $evidence = array_map(
                fn(array $row): array => ApplyPlanner::forced_override_evidence($row, 'delete_conflict', $opts),
                $plan['delete_conflict']
            );
            throw ApplyPlanner::incomplete_override_refusal(
                $evidence,
                'wprism: deletion conflict override requires --with-deletes together with --force-theirs; '
                    . "no target mutation attempted:\n  - $list"
            );
        }
        if (!$request->recoveringScoped && !$request->scopedPromotion && $plan['drift']) {
            $list = implode("\n  - ", array_map(
                static fn(array $row): string => (string) ($row['path'] ?? ($row['type'] ?? '?') . ' ' . ($row['uuid'] ?? '?')),
                $plan['drift']
            ));
            throw CommandRefusalException::applyRefused(
                'the target changed after the repository baseline, so this plan is stale and cannot be partially applied',
                'capture the target changes, reconcile them in the repository, then build and apply a fresh plan',
                "wprism: ordinary target drift requires capture/reconciliation before apply; no target mutation attempted:\n  - $list"
            );
        }

        $pendingOptionDeletes = [];
        $pendingOptionConflictEvidence = [];
        $pendingOptionWork = $request->recoveringScoped
            ? []
            : array_merge($plan['create'], $plan['update'], $plan['conflict']);
        if ($request->scopedPromotion) {
            $pendingOptionWork = array_merge($pendingOptionWork, (array) ($plan['drift'] ?? []));
        }
        foreach ($pendingOptionWork as $row) {
            foreach ($row['option_deletes'] ?? [] as $name) {
                $pendingOptionDeletes[] = $name;
            }
        }
        foreach ($request->recoveringScoped ? [] : $plan['conflict'] as $row) {
            if (($row['option_deletes'] ?? []) !== []) {
                $pendingOptionConflictEvidence[] = ApplyPlanner::forced_override_evidence($row, 'conflict', $opts);
            }
        }
        if ($pendingOptionDeletes && empty($opts['with_deletes'])) {
            sort($pendingOptionDeletes, SORT_STRING);
            $operatorMessage = 'wprism: authored option deletion intent requires --with-deletes; '
                . "no target mutation attempted:\n  - "
                . implode("\n  - ", array_unique($pendingOptionDeletes));
            if ($pendingOptionConflictEvidence !== []) {
                throw ApplyPlanner::incomplete_override_refusal($pendingOptionConflictEvidence, $operatorMessage);
            }
            throw new \RuntimeException($operatorMessage);
        }
        if (!$request->recoveringScoped && $plan['missing_user']) {
            $list = implode("\n  - ", array_map(
                fn($r) => "{$r['path']}: exact login '{$r['login']}' is absent",
                $plan['missing_user']
            ));
            throw new \RuntimeException(
                "wprism: user-meta apply refused before target mutation — required exact login(s) are missing:\n  - $list\n"
                . 'Create/reconcile the user outside WPrism, or explicitly declare missing_user="warn" on every '
                . 'authored key in that sidecar to warn-and-skip it.'
            );
        }

        $forceableCodeMismatch = self::enforce_code_mismatch_gate($plan['code_mismatch'], $opts);
        if ($plan['code_drift'] && empty($opts['force_code_drift'])) {
            $list = implode("\n\n", array_map(fn($r) => '  - ' . $r['message'], $plan['code_drift']));
            throw new \RuntimeException(
                "wprism: apply refused — code_drift:\n\n$list\n\n"
                . "Run 'wprism deploy <env>' to reconcile and re-baseline, or pass --force-code-drift to proceed anyway."
            );
        }
        foreach ($plan['code_drift'] as $row) {
            $warnings[] = 'FORCED past code_drift: ' . $row['message'];
        }
        foreach ($forceableCodeMismatch as $row) {
            $warnings[] = 'FORCED past code_mismatch: ' . $row['message'];
        }
        // WP-2.8: reported on every run, exactly as Deploy::run() reports it,
        // and read off the plan rather than off the gate's return — the gate
        // no longer hands this row back, because it was never refused.
        foreach ($plan['code_mismatch'] as $row) {
            if (($row['issue'] ?? null) === VersionEvidenceGrammar::VERDICT) {
                $warnings[] = 'GRADUATED outside_version_range: ' . $row['message'];
            }
        }

        $rebuildWork = $this->services->apply_planner()->rebuild_work(
            $plan,
            $tree,
            $opts,
            $request->retryingIncompleteApply,
            $request->scopedPromotion
        );
        if ($request->recoveringScoped) {
            if ($this->scopedWorkflow->session === null) {
                throw new \RuntimeException('wprism: scoped recovery has no durable session selection');
            }
            $rebuildWork = ScopedApplyWorkProjector::project(
                $plan,
                $request->compiled,
                $this->scopedWorkflow->session,
                $this->scopedWorkflow->scopeContract,
                $this->services->apply_planner()
            );
        }
        $deleteWork = $rebuildWork['delete_work'];
        $rebuildDeleteWork = $rebuildWork['rebuild_delete_work'];
        $work = $rebuildWork['work'];
        $executeDeletes = !empty($opts['with_deletes'])
            || ($request->recoveringScoped
                && (array) ($this->scopedWorkflow->session->authority()['selection']['deletion_items'] ?? []) !== []);

        if ($request->scoped && !$executeDeletes && $deleteWork !== []) {
            throw CommandRefusalException::applyRefused(
                'scoped apply selected live tombstones but --with-deletes was not supplied; '
                    . 'no scoped session or authored target mutation was created',
                'review the selected tombstones and rerun scoped apply with --with-deletes to authorize their removal',
                'wprism: scoped apply selected live tombstones but --with-deletes was not supplied; '
                    . 'no scoped session or authored target mutation was created'
            );
        }

        // Full apply must mean full convergence. Refuse before the authored
        // transaction rather than applying creates/updates, advancing the
        // revision, and leaving every repository tombstone pending.
        if (!$request->scoped && !$executeDeletes && $deleteWork !== []) {
            throw CommandRefusalException::applyRefused(
                'the repository revision contains planned deletions that were not authorized',
                'review the deletion rows and rerun apply or promote with --with-deletes',
                ApplyPlanner::unauthorized_deletes_refusal($deleteWork)
            );
        }

        if ($executeDeletes) {
            $blocked = array_filter($deleteWork, fn($row) => isset($row['blocked']));
            if ($blocked && empty($opts['force_delete_referenced'])) {
                $list = implode("\n  - ", array_map(
                    fn($row) => "{$row['type']} {$row['uuid']}: {$row['blocked']}",
                    $blocked
                ));
                $operatorMessage = 'wprism: deletes blocked by referential guards '
                    . "(this environment's runtime data references them; --force-delete-referenced to override):\n  - $list";
                $conflictEvidence = [];
                foreach ($blocked as $row) {
                    if (isset($row['conflict_view'])) {
                        $conflictEvidence[] = ApplyPlanner::forced_override_evidence($row, 'delete_conflict', $opts);
                    }
                }
                if ($conflictEvidence !== []) {
                    throw ApplyPlanner::incomplete_override_refusal($conflictEvidence, $operatorMessage);
                }
                throw new \RuntimeException($operatorMessage);
            }
            foreach ($blocked as $row) {
                DeleteGuardLockCoordinator::append_forced_warnings(
                    $warnings,
                    $row,
                    'FORCED delete of guarded'
                );
            }
        }

        foreach ($plan['conflict'] as $row) {
            $warnings[] = "FORCED conflict {$row['uuid']} (repository intent authorized to replace target authored state)";
            $forcedOverrideEvidence[] = ApplyPlanner::forced_override_evidence($row, 'conflict', $opts);
        }
        foreach ($plan['delete_conflict'] as $row) {
            $warnings[] = "FORCED deletion conflict {$row['uuid']} ({$row['reason']})";
            $forcedOverrideEvidence[] = ApplyPlanner::forced_override_evidence($row, 'delete_conflict', $opts);
        }

        $defaultAuthor = $this->services->post_materializer()->resolve_login(
            $opts['default_author'] ?? ''
        ) ?? null;
        $this->services->tokens()->defaultUserId = $defaultAuthor;
        $deleteUuids = array_fill_keys(array_column($deleteWork, 'uuid'), true);
        $guardRepairUuids = ApplyPlanner::guard_repair_uuids($plan, $request->scopedPromotion);

        $actionPreflight = $this->services->rebuild_action_negotiator()->negotiate(
            $work,
            $tree,
            $rebuildDeleteWork,
            $deleteWork,
            $request->scoped,
            $request->scopedPromotion
        );
        $this->rebuildSelection->set_selected_actions($actionPreflight['selected_actions']);
        $negotiation = $actionPreflight['negotiation'];
        $this->rebuildSelection->set_negotiated_providers($actionPreflight['providers']);
        if ($request->recoveringScoped) {
            $this->scopedWorkflow->assert_recovery_selection(
                $this->rebuildSelection->selected_actions(),
                $negotiation
            );
        }

        ($this->renewPromotionLock)('precondition-recheck');
        if (getenv('WPRISM_TEST_MODE') === '1') {
            $pauseMs = (int) (getenv('WPRISM_TEST_PROMOTION_PAUSE_MS') ?: 0);
            if ($pauseMs > 0 && $pauseMs <= 30000) {
                usleep($pauseMs * 1000);
            }
        }
        $negotiatedSelectedActions = $this->rebuildSelection->selected_actions();
        $freshPlan = ($this->buildPlan)($opts, $request->compiled, $request->scoped, !$request->scoped);
        if ($request->scoped) {
            $freshPlan = ScopedApply::project_plan($freshPlan, $this->scopedWorkflow->scopeContract);
            $freshRebuildWork = $this->services->apply_planner()->rebuild_work(
                $freshPlan,
                $tree,
                $opts,
                $request->retryingIncompleteApply,
                $request->scopedPromotion
            );
            if ($request->recoveringScoped) {
                if ($this->scopedWorkflow->session === null) {
                    throw new \RuntimeException('wprism: scoped recovery has no durable session selection');
                }
                // Authored rows can already be converged while a sealed
                // provider/native effect remains pending. Re-project the
                // fresh plan through that frozen selection or the second
                // pre-mutation check would erase the only retry authority.
                $freshRebuildWork = ScopedApplyWorkProjector::project(
                    $freshPlan,
                    $request->compiled,
                    $this->scopedWorkflow->session,
                    $this->scopedWorkflow->scopeContract,
                    $this->services->apply_planner()
                );
            }
            $freshSelectedActions = $this->policy->actions_for(CanonicalSurfaces::for_apply(
                $freshRebuildWork['work'],
                $tree,
                $freshRebuildWork['rebuild_delete_work'],
                $this->policy
            ));
            if (Canon::encode($freshSelectedActions) !== Canon::encode($negotiatedSelectedActions)) {
                throw new \RuntimeException(
                    'wprism: scoped action selection changed after planning; no target mutation attempted'
                );
            }
        }
        $this->rebuildSelection->set_selected_actions($negotiatedSelectedActions);
        $freshActual = null;
        if (!hash_equals(
            ApplyPlanner::plan_precondition_hash($plan),
            ApplyPlanner::plan_precondition_hash($freshPlan)
        )) {
            throw new \RuntimeException(
                'wprism: promotion preconditions changed after planning; no target mutation attempted — recompile and retry'
            );
        }
        if ($request->scoped) {
            $observed = $this->scopedWorkflow->recheck_target_observation(function () use (
                $opts,
                $request
            ): array {
                $freshActual = Capture::snapshot_read_only(
                    $this->repo,
                    !empty($opts['force_unresolved_refs']),
                    $request->compiled,
                    $this->policy
                );
                $freshObservation = ScopedApply::observe_target(
                    $this->repo,
                    $request->compiled,
                    $this->policy,
                    $this->scopedWorkflow->scopeContract,
                    $freshActual,
                    $this->scopedWorkflow->ledger_map_identity_hashes(),
                    $this->scopedWorkflow->allows_target_old_menu_items($freshActual)
                );
                foreach ([
                    'selected_before_root', 'protected_out_of_scope_root',
                    'ledger_map_root', 'protected_ledger_map_root', 'selected_ledger_map_root',
                    'target_observation_hash',
                ] as $witness) {
                    if (hash_equals(
                        (string) ($this->scopedWorkflow->observation[$witness] ?? ''),
                        (string) ($freshObservation[$witness] ?? '')
                    )) {
                        continue;
                    }
                    throw new \RuntimeException(
                        'wprism: scoped target observation changed after planning; no target mutation attempted'
                    );
                }
                if (!hash_equals(
                    ScopedApplySession::hash_value((array) ($this->scopedWorkflow->observation['_ledger_map_identity_hashes'] ?? [])),
                    ScopedApplySession::hash_value((array) ($freshObservation['_ledger_map_identity_hashes'] ?? []))
                )) {
                    throw new \RuntimeException(
                        'wprism: scoped ledger-map identity selection changed after planning; no target mutation attempted'
                    );
                }
                return ['actual' => $freshActual, 'observation' => $freshObservation];
            });
            $freshActual = $observed['actual'];
            $freshObservation = $observed['observation'];
            $this->scopedWorkflow->observation = $freshObservation;
        }

        if ($request->scopedPromotion) {
            $scopeHash = (string) ($this->scopedWorkflow->scopeContract['scope_hash'] ?? '');
            $authorityWitness = ScopedPromotionAuthority::require_installed(
                $request->promotionOwner,
                $request->promotionArtifact,
                (string) $opts['scoped_promotion_receipt'],
                $scopeHash,
                ['promoting'],
                !empty($opts['with_deletes'])
            );
            $this->scopedWorkflow->promotionWitness = $authorityWitness;
            PromotionLock::assert_scoped_promotion_session(
                $request->promotionOwner,
                $request->promotionArtifact,
                (string) $opts['scoped_promotion_receipt'],
                $scopeHash,
                $authorityWitness
            );
        }

        return new PreparedApply(
            freshPlan: $freshPlan,
            work: $work,
            deleteWork: $deleteWork,
            rebuildDeleteWork: $rebuildDeleteWork,
            deleteUuids: $deleteUuids,
            guardRepairUuids: $guardRepairUuids,
            negotiation: $negotiation,
            executeDeletes: $executeDeletes,
            defaultAuthor: $defaultAuthor,
            freshActual: $freshActual
        );
    }

    /** @return list<array<string,mixed>> */
    public static function enforce_code_mismatch_gate(array $mismatches, array $opts): array {
        $nonForceable = array_values(array_filter(
            $mismatches,
            static fn(array $row): bool => ($row['issue'] ?? null) === 'code_revision_stale'
                || !empty($row['non_forceable'])
        ));
        if ($nonForceable) {
            $list = implode("\n\n", array_map(
                fn(array $row): string => '  - ' . ($row['message'] ?? 'non-forceable code compatibility blocker'),
                $nonForceable
            ));
            $runtime = array_values(array_filter(
                $nonForceable,
                static fn(array $row): bool => !empty($row['non_forceable'])
                    && ($row['issue'] ?? null) !== 'code_revision_stale'
            ));
            if ($runtime) {
                throw new \RuntimeException(
                    "wprism: apply refused — code runtime compatibility is non-forceable:\n\n$list\n\n"
                    . 'Correct the declared Requires PHP/Requires at least header or use a target that reports compatible runtime versions. '
                    . 'Neither --force-code-mismatch nor WPrism certification-baseline evidence overrides a component runtime requirement.'
                );
            }
            throw new \RuntimeException(
                "wprism: apply refused — code_revision_stale:\n\n$list\n\n"
                . "Run the host 'wprism deploy <env>' workflow to stage, reconcile, verify, and finalize the exact code payload. "
                . 'This ordering invariant is non-forceable; neither --force-code-mismatch nor --force-code-drift overrides it.'
            );
        }
        // WP-2.8: the graduated verdict leaves $forceable, and only $forceable.
        // It is still a row in the plan's code_mismatch bucket and still
        // reported by the caller; what changes is that apply no longer refuses
        // over it and no longer calls it FORCED, because nothing was forced —
        // this site recorded per-release probe evidence covering the installed
        // bytes (LifecyclePlanner::graduated_version_range()). --force-code-
        // mismatch keeps its exact prior meaning for every other row.
        $forceable = array_values(array_filter(
            $mismatches,
            static fn(array $row): bool => ($row['issue'] ?? null) !== 'code_revision_stale'
                && ($row['issue'] ?? null) !== VersionEvidenceGrammar::VERDICT
                && empty($row['non_forceable'])
        ));
        if ($forceable && empty($opts['force_code_mismatch'])) {
            $list = implode("\n\n", array_map(
                fn(array $row): string => '  - ' . ($row['message'] ?? 'code mismatch'),
                $forceable
            ));
            throw new \RuntimeException(
                "wprism: apply refused — code_mismatch:\n\n$list\n\n"
                . "Run 'wprism deploy <env>' first for lifecycle reconciliation, "
                . 'or pass --force-code-mismatch to proceed despite those lifecycle mismatches.'
            );
        }
        return $forceable;
    }

}
