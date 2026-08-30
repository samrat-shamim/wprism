<?php
namespace WPrism;

require_once __DIR__ . '/ApplyPlanner.php';
require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Repository/CanonicalSurfaces.php';
require_once __DIR__ . '/../Delete/DeleteGuardEvaluator.php';
require_once __DIR__ . '/../Delete/DeleteGuardReferenceScanner.php';
if (!class_exists(CompiledRepository::class, false)) {
    require_once __DIR__ . '/../Repository/CompiledArtifact.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}
if (!class_exists(PromotionLock::class, false)) {
    require_once __DIR__ . '/../Promotion/PromotionLock.php';
}
if (!class_exists(ScopedApply::class, false)) {
    require_once __DIR__ . '/../Scope/ScopedApply.php';
}
if (!class_exists(SidebarState::class, false)) {
    require_once __DIR__ . '/../Repository/SidebarState.php';
}
if (!class_exists(Deletion::class, false)) {
    require_once __DIR__ . '/../Delete/Deletion.php';
}
if (!class_exists(Deploy::class, false)) {
    require_once __DIR__ . '/../Promotion/Deploy.php';
}
if (!class_exists(Code::class, false)) {
    require_once __DIR__ . '/../Code/Code.php';
}
if (!class_exists(Capture::class, false) && !class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Capture/Capture.php';
}
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}

/**
 * Stateful target observation and immutable plan-envelope assembly.
 *
 * ApplyPlanner retains the pure classification rules. This builder owns their
 * ordering against Capture/Ledger observations, deletion-guard target reads,
 * lifecycle/code diagnostics, and selected provider-action projection. It
 * returns all Apply instance state produced by planning explicitly so the
 * facade can preserve repeated locked-recheck behavior byte-for-byte.
 */
final class ApplyPlanBuilder {
    /** @var \Closure():array */
    private readonly \Closure $regenerationDebtProjection;
    /** @var \Closure(array):array */
    private readonly \Closure $envMissingProjection;
    /** @var string[] */
    private array $warnings;
    /** @var list<array<string,mixed>> */
    private array $selectedActions = [];
    /** @var array<string,int>|null */
    private ?array $categorySummaryContext = null;

    public function __construct(
        private readonly string $repo,
        private readonly Policy $policy,
        private readonly ApplyPlanner $planner,
        private readonly DeleteGuardReferenceScanner $guardScanner,
        private readonly array $snapshotRowTables,
        private readonly ?array $scopeContract,
        \Closure $regenerationDebtProjection,
        \Closure $envMissingProjection,
        array $warnings = []
    ) {
        $this->regenerationDebtProjection = $regenerationDebtProjection;
        $this->envMissingProjection = $envMissingProjection;
        $this->warnings = $warnings;
    }

    public function build(
        array $opts,
        CompiledRepository $compiled,
        bool $strictObservation = false,
        bool $diagnoseAdapters = true
    ): array {
        $tree = $compiled->tree();
        $this->check_theme_mismatch($tree);
        // Capture::snapshot() runs the SAME build() capture.php's own `wprism
        // capture` does (drift detection needs the live environment's
        // current canonical view) — so it hits the identical task #73
        // loud-and-blocking gate on an unscoped ref-typed option. Threaded
        // through so plan/apply have the same escape hatch `wprism capture`
        // does, matching this file's existing --force-* precedents.
        $planObservations = null;
        if ($strictObservation) {
            $env = Capture::snapshot_read_only(
                $this->repo,
                !empty($opts['force_unresolved_refs']),
                $compiled,
                $this->policy
            );
        } else {
            $env = Capture::snapshot(
                $this->repo,
                !empty($opts['force_unresolved_refs']),
                $compiled,
                $this->policy,
                $planObservations
            );
        }
        // A target materialized from another environment's snapshot (`wprism
        // rehearse`) holds that environment's bytes AND its ledger: every
        // `{{home}}`/`{{uploads}}`-bearing entity then observes as "target
        // changed since base" here, because the source's literal URLs are
        // not this environment's binding and never tokenize back. That is
        // rebinding work, not authorship — grind_adoption A6's rehearsal
        // failed post-apply verification on exactly one wp_navigation post
        // for it. When the caller names the restored binding, observe once
        // more AS that environment; an entity whose foreign-bound hash equals
        // the repository or the base is that content foreign-bound, and is
        // compared as if unchanged since base (an update, marked `rebind`).
        // Anything else stays drift/conflict: only the named binding is
        // excused, never target authorship.
        $rebindFrom = ApplyPlanner::rebind_binding($opts);
        $foreign = [];
        if ($rebindFrom !== null && !$strictObservation) {
            $foreignObservations = null;
            $foreign = Capture::snapshot(
                $this->repo,
                !empty($opts['force_unresolved_refs']),
                $compiled,
                $this->policy,
                $foreignObservations,
                $rebindFrom
            );
        }
        $base = Ledger::all_state();
        $adopt = array_fill_keys(array_filter(explode(',', $opts['adopt_by_slug'] ?? '')), true);
        $lifecycleTransition = null;
        $promotionOwner = (string) ($opts['promotion_owner'] ?? '');
        if ($promotionOwner !== '') {
            $lifecycleTransition = PromotionLock::state_transition(
                $promotionOwner,
                $compiled->artifact_hash(),
                'options/core'
            );
        }

        $certificationBlockers = $diagnoseAdapters
            ? $this->policy->certification_readiness_blockers()
            : [];
        $this->categorySummaryContext = null;
        $plan = [
            'create' => [], 'update' => [], 'unchanged' => [], 'drift' => [],
            'conflict' => [], 'adopt' => [], 'collision' => [], 'delete' => [],
            'delete_conflict' => [], 'deleted' => [],
            'code_mismatch' => [], 'code_drift' => [], 'incomplete_apply' => [],
            'incomplete_lifecycle' => [],
            'missing_user' => [], 'skipped_user_meta' => [],
            // issue #3297: complete immutable declaration available before any
            // target mutation. Runtime journaling resolves these bounded
            // original/derivative roots into exact before-image and absence
            // receipts; this list itself never claims that an undeclared
            // derivative is safe.
            'uploads_inventory' => $compiled->uploads_inventory(),
            'effects_inventory' => $compiled->effects_inventory(),
            // Source/evidence facts are plan-independent. Provider runtime
            // blockers are appended at the end from this plan's exact
            // rebuild-surface selection; see rebuild_work() below.
            'adapter_dispositions' => $certificationBlockers,
        ];
        $collisionCache = [];
        foreach ($tree as $uuid => $e) {
            $fileH = $e['hash'];
            $envE = $env[$uuid] ?? null;
            $baseH = $base[$uuid]['content_hash'] ?? null;
            $comparisonEnvH = self::lifecycle_comparison_hash(
                (string) $uuid,
                $envE['hash'] ?? null,
                $lifecycleTransition
            );
            $row = ['uuid' => $uuid, 'type' => $e['type'], 'path' => $e['path']];
            $title = self::entity_display_title($e['data']);
            if ($title !== null) {
                $row['title'] = $title;
            }
            $this->annotate_natural_key_continuity(
                $row,
                (string) $uuid,
                (string) $e['type'],
                $e['data'],
                $envE
            );
            if ($uuid === 'options/core') {
                // Carry only the exact option records whose canonical state
                // differs from the captured target. A retry row is widened
                // back to all desired records in rebuild_surfaces(), because
                // its prior authored mutation may already be target-equal.
                $row['rebuild_option_names'] = $this->apply_planner()->option_rebuild_names($e['data'], $envE);
                if ($this->scopeContract !== null
                    && ScopedApply::has_record_scoped_options($this->scopeContract)) {
                    $decision = ScopedApply::option_plan_decision(
                        (array) $e['data'],
                        $envE,
                        $base,
                        $this->scopeContract,
                        (array) $row['rebuild_option_names']
                    );
                    $row = $decision['row'] + $row;
                    $plan[$decision['bucket']][] = $row;
                    continue;
                }
            }
            if ($e['type'] === SidebarState::ENTITY_TYPE && $envE !== null) {
                $row = $this->apply_planner()->project_sidebar_deletes(
                    $row,
                    $e['data'],
                    $envE,
                    $baseH
                );
            }
            if ($e['type'] === 'user-meta' && $envE === null) {
                $login = (string) ($e['data']['login'] ?? '');
                $row['login'] = $login;
                $behavior = $this->policy->user_meta_missing_behavior((array) ($e['data']['meta'] ?? []));
                if ($behavior === 'warn') {
                    $plan['skipped_user_meta'][] = $row;
                    $warning = "user-meta {$e['path']}: exact login '$login' is absent; "
                        . 'warn-and-skip policy left the target untouched';
                    if (!in_array($warning, $this->warnings, true)) {
                        $this->warnings[] = $warning;
                    }
                } else {
                    $plan['missing_user'][] = $row;
                }
                continue;
            }
            if ($uuid === 'options/core' && $envE !== null) {
                $optionDeletion = ApplyPlanner::classify_option_deletions(
                    $row,
                    $e['data'],
                    $envE,
                    $baseH,
                    (string) $fileH,
                    $comparisonEnvH
                );
                if ($optionDeletion['bucket'] === 'conflict') {
                    $plan['conflict'][] = $optionDeletion['row'];
                    continue;
                }
                $row = $optionDeletion['row'];
            }
            if ($envE !== null) {
                $rebind = ApplyPlanner::rebind_comparison_hash(
                    (string) $fileH,
                    (string) $envE['hash'],
                    $baseH,
                    $comparisonEnvH,
                    $foreign[$uuid]['hash'] ?? null
                );
                if ($rebind !== null) {
                    $comparisonEnvH = $rebind;
                    $row['rebind'] = true;
                }
                $comparison = $this->apply_planner()->classify_observed(
                    $row,
                    (string) $fileH,
                    $envE,
                    $baseH,
                    $comparisonEnvH
                );
                $plan[$comparison['bucket']][] = $comparison['row'];
                continue;
            }
            $coll = $this->apply_planner()->find_collision($e, $tree, $collisionCache);
            if ($coll !== null) {
                // Table entities' own 'type' IS the specific table name (see
                // Snapshot.php's row_tables() docblock) — pluralizing it
                // ("woocommerce_attribute_taxonomiess") the way posts/terms/
                // menus already do would be unusable, so every declared
                // table shares ONE adopt-by-slug key, "tables", regardless
                // of which specific table a natural_key collision belongs to.
                $kind = isset($this->snapshotRowTables()[$e['type']])
                    ? 'tables'
                    : ($e['type'] === 'menu' ? 'menus' : $e['type'] . 's');
                $kindOk = isset($adopt[$kind]);
                if ($kindOk) {
                    $plan['adopt'][] = $row + ['env_id' => $coll];
                } else {
                    $plan['collision'][] = $row + ['env_id' => $coll];
                }
                continue;
            }
            $plan['create'][] = $row;
        }
        $plan = $this->apply_planner()->project_reference_rebinds($plan, $tree, $env, $base);

        // Absence is not deletion authority. Only a compiled, versioned
        // tombstone can enter one of the deletion buckets below. Its
        // expected_hash is the three-way base that capture observed before
        // removing the live file; this makes delete-vs-edit a first-class
        // conflict instead of letting an omitted file erase target data.
        $deletionCaps = [];
        foreach ($compiled->deletions() as $uuid => $d) {
            $data = (array) $d['data'];
            $kind = (string) $data['kind'];
            $subtype = (string) $data['type'];
            $entityType = $kind === 'table' ? $subtype : $kind;
            $expected = (string) $data['expected_hash'];
            $receipt = (string) $d['hash'];
            $envE = $env[$uuid] ?? null;
            $baseE = $base[$uuid] ?? null;
            $row = [
                'uuid' => $uuid,
                'type' => $entityType,
                'deletion_kind' => $kind,
                'deletion_type' => $subtype,
                'path' => (string) $d['path'],
                'expected_hash' => $expected,
                'receipt_hash' => $receipt,
            ];
            $this->annotate_natural_key_continuity($row, (string) $uuid, $entityType, null, $envE);
            $deletionCaps[$uuid] = Deletion::capability($this->policy, $kind, $subtype);
            $deletion = $this->apply_planner()->classify_deletion($row, $envE, $baseE);
            $plan[$deletion['bucket']][] = $deletion['row'];
        }

        // Runtime reverse references are target facts, so check them only
        // after classifying every tombstone. A guard may exclude authored
        // child rows which are themselves safe DELETE candidates in this
        // same revision; conflicted children are deliberately not excluded.
        $deleteUuids = array_fill_keys(array_column($plan['delete'], 'uuid'), true);
        if (!empty($opts['force_theirs'])) {
            $deleteUuids += array_fill_keys(array_column($plan['delete_conflict'], 'uuid'), true);
        }
        $guardRepairUuids = $this->guard_repair_uuids($plan);
        $plan = DeleteGuardEvaluator::annotate_plan_guard_findings(
            $plan,
            $deletionCaps,
            function (array $guard, string $targetUuid, bool $lock) use (
                $deleteUuids,
                $compiled,
                $guardRepairUuids
            ): array {
                return $this->count_guard_refs(
                    $guard,
                    $targetUuid,
                    $deleteUuids,
                    $compiled->deletions(),
                    $compiled->tree(),
                    $guardRepairUuids,
                    $lock
                );
            },
            fn(string $table): bool => isset($this->snapshotRowTables()[$table]),
            hash('sha256', Canon::encode([]))
        );

        // docs/code-half.md §3.2: the cross-partition invariant's
        // plan-time checks read BOTH live lifecycle facts (active plugins /
        // themes) and, for an opt-in code descriptor, the completed verified
        // payload revision in the environment ledger. Keep these in the same
        // code_mismatch bucket: apply cannot safely write authored state when
        // either the declared lifecycle or the descriptor bytes have not yet
        // reached this environment.
        $desired = isset($tree['options/core'])
            ? Deploy::extract_desired($tree['options/core']['data'])
            : [];
        $plan['code_mismatch'] = array_merge(
            Code::target_compatibility_rows($this->repo, $compiled),
            Deploy::code_mismatch($this->policy, $desired),
            Deploy::code_revision_mismatch($compiled)
        );
        // issue #3231: same $desired, same call shape as code_mismatch above —
        // see Deploy::code_drift()'s own docblock for why it's a distinct
        // question (out-of-band version change vs. compatibility range).
        $plan['code_drift'] = Deploy::code_drift($this->policy, $desired);

        // A failed hook can commit canonical option writes before WordPress
        // reports failure. Its pre-hook receipt survives lease cleanup and is
        // intentionally non-forceable, so a read-only plan/status must expose
        // the recovery requirement even when no promotion continuation is
        // currently active.
        $incompleteLifecycle = PromotionLock::incomplete_lifecycle();
        if ($incompleteLifecycle !== null) {
            $plan['incomplete_lifecycle'][] = $incompleteLifecycle + [
                'reason' => 'unresolved lifecycle hook attempt; restore the exact pre-lifecycle database checkpoint before retrying',
            ];
        }

        // The retry widening and issue #3489's preserved-drift carve-out are a
        // pure projection over the marker's own payload; the Ledger read is
        // the only environment fact this block owns.
        // ApplyPlanner::project_incomplete_apply_retry() states why each
        // bucket is or is not widened.
        $incompleteApplyMarker = Ledger::kv_get('apply_in_progress');
        $retryingIncompleteApply = $incompleteApplyMarker !== null;
        $plan = ApplyPlanner::project_incomplete_apply_retry($plan, $incompleteApplyMarker);

        // issue #3234 / issue #3342: expose all durable derived-state retry debt
        // through one read-only planner projection. Ledger and Policy remain
        // Apply's engine boundaries; the planner owns the shared rows,
        // orphan rules, ordering, and warning vocabulary.
        $regenDebt = $this->regeneration_debt_projection();
        $plan['regen_pending'] = $regenDebt['regen_pending'];
        $plan['regen_context'] = $regenDebt['regen_context'];
        foreach ($regenDebt['warnings'] as $warning) {
            $this->warnings[] = $warning;
        }

        // issue #3232: env-bound value provisioning checklist. Read-only, like
        // regen_pending above — no write here, ever (env values are
        // deliberately excluded from Capture/Apply's ordinary content
        // pipeline; this bucket exists purely so a plain `wprism plan` tells
        // an operator the truth about what a freshly-materialized
        // environment still needs, per manifest-declared class:"env"
        // options only — see Policy::env_options()'s own docblock for why
        // meta/sub_keys env values are out of v2 scope). "Missing" means
        // the option row is absent or an empty string on THIS environment
        // — a per-environment self-check, not a cross-environment diff
        // (env values are never captured, so the repo has no record of
        // what any other environment had; an operator wanting an actual
        // source-vs-target checklist gets one by running `wprism plan`
        // against both named environments and diffing the two
        // env_missing lists client-side — see cli/README.md).
        $envMissing = $this->env_missing_projection($tree);
        $plan['env_missing'] = $envMissing['env_missing'];
        foreach ($envMissing['warnings'] as $warning) {
            $this->warnings[] = $warning;
        }

        // issue #3249: loud, plan-visible half of Policy::rule_details()'s
        // core-yields-to-plugin precedence fix — a plain warning naming
        // every core-manifest option a pinned plugin manifest is actively
        // reclassifying on THIS site (e.g. polylang.json's own
        // default_category -> derived). A structural fact about which
        // manifests are pinned together, not a per-environment condition,
        // so it belongs in $plan['warnings'] like code_mismatch's own
        // rendering precedent, never a new ok-flipping bucket — an active
        // reclassification is correct, intended behavior once the
        // overriding manifest is pinned, not a problem to refuse promotion
        // over.
        foreach ($this->policy->active_reclassifications() as $r) {
            $this->warnings[] = "reclassified: option '{$r['name']}' is core-classified "
                . "'{$r['core_class']}' but '{$r['overridden_by']}' (pinned) reclassifies it "
                . "'{$r['active_class']}' on this site — the plugin's declaration governs";
        }
        // issue #3272: the same loud-plan-warning treatment, for menu_fields
        // instead of options — see Policy::active_menu_field_reclassifications()'s
        // own docblock for why this is a separate method/loop rather than a
        // generalized shared one.
        foreach ($this->policy->active_menu_field_reclassifications() as $r) {
            $this->warnings[] = "reclassified: menu field '{$r['name']}' is core-classified "
                . "'{$r['core_class']}' but '{$r['overridden_by']}' (pinned) reclassifies it "
                . "'{$r['active_class']}' on this site — the plugin's declaration governs";
        }
        // WP-5.5: the loud half of site.wprism.json's `policy.adapter_claims`
        // resolution — every plugin/theme claim the operator's decision
        // displaced, with the range that actually bounds the subject and the
        // one that no longer does. A plain warning for the identical reason
        // the two reclassification loops above are one: a resolved collision
        // is correct, intended behaviour once the resolution is written down,
        // not a condition to refuse promotion over. The displaced manifest is
        // still pinned and still loaded — only its CLAIM about this plugin or
        // theme is displaced, which is why the sentence says so rather than
        // implying an adapter was dropped.
        foreach ($this->policy->displaced_adapter_claims() as $d) {
            $range = $d['displaced_range'] === null
                ? 'no range'
                : "{$d['displaced_range']['min']}..{$d['displaced_range']['max']}";
            $inForce = $d['in_force_range'] === null
                ? 'no range'
                : "{$d['in_force_range']['min']}..{$d['in_force_range']['max']}";
            $this->warnings[] = "displaced: {$d['kind']} '{$d['id']}' is claimed by '{$d['displaced']}' "
                . "($range) and by '{$d['in_force']}' ($inForce); site.wprism.json policy.adapter_claims puts "
                . "'{$d['in_force']}' in force, so '{$d['displaced']}' still loads but its {$d['kind']} claim "
                . 'is displaced'
                . ($d['note'] === null || $d['note'] === '' ? '' : " — {$d['note']}");
        }
        $inactiveWarning = SidebarState::inactive_warning();
        if ($inactiveWarning !== null && !in_array($inactiveWarning, $this->warnings, true)) {
            $this->warnings[] = $inactiveWarning;
        }

        // A global capability report evaluates every declared provider action,
        // but a plan/status must answer what THIS plan can actually run. Use
        // the exact work/delete/retry projection Apply::run() uses before its
        // pre-mutation negotiation, so an unrelated or empty trigger cannot
        // turn a clean plan red while a selected missing capability stays
        // visible in adapter_dispositions.
        $rebuildWork = $this->rebuild_work($plan, $tree, $opts, $retryingIncompleteApply);
        $this->selectedActions = $this->policy->actions_for(
            $this->rebuild_surfaces(
                $rebuildWork['work'],
                $tree,
                $rebuildWork['rebuild_delete_work']
            )
        );
        $selectedProviderBlockers = [];
        if ($diagnoseAdapters) {
            $selectedProviderBlockers = $this->policy->provider_readiness_blockers($this->selectedActions);
            $plan['adapter_dispositions'] = array_merge(
                $plan['adapter_dispositions'],
                $selectedProviderBlockers
            );
        }
        $nestedDeleteCounts = $strictObservation ? null : self::nested_delete_candidate_counts(
            $env,
            $tree,
            $plan,
            $planObservations
        );
        if ($nestedDeleteCounts !== null) {
            $this->categorySummaryContext = [
                'selected_native_actions' => count(array_filter(
                    $this->selectedActions,
                    static fn(array $action): bool => ($action['kind'] ?? null) === 'native'
                )),
                'selected_provider_actions' => count(array_filter(
                    $this->selectedActions,
                    static fn(array $action): bool => ($action['kind'] ?? null) === 'provider'
                )),
                'certification_source_blockers' => count($certificationBlockers),
                'selected_provider_blockers' => count($selectedProviderBlockers),
                'nested_menu_item_delete_candidates' => $nestedDeleteCounts['menu'],
                'nested_widget_delete_candidates' => $nestedDeleteCounts['widget'],
                'nested_option_delete_candidates' => $nestedDeleteCounts['option'],
            ];
        }
        return [
            'plan' => $plan,
            'warnings' => $this->warnings,
            'selected_actions' => $this->selectedActions,
            'category_summary_context' => $this->categorySummaryContext,
        ];
    }

    private function apply_planner(): ApplyPlanner {
        return $this->planner;
    }

    /** @return array<string,array> */
    private function snapshotRowTables(): array {
        return $this->snapshotRowTables;
    }

    private function check_theme_mismatch(array $tree): void {
        if (!ApplyPlanner::theme_mismatch_has_theme_terms($tree)) {
            return;
        }
        foreach (ApplyPlanner::theme_mismatch_warnings($tree, (string) get_option('stylesheet')) as $warning) {
            $this->warnings[] = $warning;
        }
    }

    private static function lifecycle_comparison_hash(
        string $uuid,
        ?string $envHash,
        ?array $transition
    ): ?string {
        return ApplyPlanner::lifecycle_comparison_hash($uuid, $envHash, $transition);
    }

    private static function entity_display_title(mixed $data): ?string {
        return ApplyPlanner::entity_display_title($data);
    }

    private function annotate_natural_key_continuity(
        array &$row,
        string $uuid,
        string $type,
        ?array $desired,
        ?array $env
    ): void {
        foreach ($this->planner->natural_key_continuity_annotations(
            $uuid,
            $type,
            $desired,
            $env
        ) as $note) {
            if ($note !== null && !in_array($note, $row['annotations'] ?? [], true)) {
                $row['annotations'][] = $note;
            }
        }
    }

    private function guard_repair_uuids(array $plan, bool $includeDrift = false): array {
        return ApplyPlanner::guard_repair_uuids($plan, $includeDrift);
    }

    /** @return array{count:int,error:?string,rows:string[],witness?:string} */
    private function count_guard_refs(
        array $guard,
        string $targetUuid,
        array $deleteUuids,
        array $deletions,
        array $tree = [],
        array $guardRepairUuids = [],
        bool $forUpdate = false
    ): array {
        return $this->guardScanner->count(
            $guard,
            $targetUuid,
            $deleteUuids,
            $deletions,
            $tree,
            $guardRepairUuids,
            $forUpdate
        );
    }

    private function regeneration_debt_projection(): array {
        return ($this->regenerationDebtProjection)();
    }

    private function env_missing_projection(array $tree): array {
        return ($this->envMissingProjection)($tree);
    }

    private function rebuild_work(
        array $plan,
        array $tree,
        array $opts,
        bool $retryingIncompleteApply = false,
        bool $includeDrift = false
    ): array {
        return $this->planner->rebuild_work(
            $plan,
            $tree,
            $opts,
            $retryingIncompleteApply,
            $includeDrift
        );
    }

    private function rebuild_surfaces(array $work, array $tree, array $deleteWork = []): array {
        return CanonicalSurfaces::for_apply(
            $work,
            $tree,
            $deleteWork,
            $this->policy
        );
    }

    private static function nested_delete_candidate_counts(
        array $env,
        array $tree,
        array $plan,
        ?array $observations
    ): ?array {
        return ApplyPlanner::nested_delete_candidate_counts($env, $tree, $plan, $observations);
    }
}
