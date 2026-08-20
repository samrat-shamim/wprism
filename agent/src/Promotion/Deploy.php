<?php
namespace Duo;

require_once __DIR__ . '/../Code/CodeCompatibility.php';
require_once __DIR__ . '/DeployPlanner.php';
require_once __DIR__ . '/LifecycleExecutor.php';
require_once __DIR__ . '/LifecyclePlanner.php';
require_once __DIR__ . '/StateHandoffVerifier.php';

/**
 * docs/proposals/code-half.md §3.4/§6: reconciles active_plugins/template/
 * stylesheet (managed-class core-manifest options, see manifests/core.json)
 * against the environment's actual state, using real WP APIs —
 * activate_plugin()/deactivate_plugins()/switch_theme() — so their
 * activation/switch hooks fire DELIBERATELY. This is the one place in the
 * engine where that's true: `duo apply`'s canary (Canary.php) requires the
 * opposite (zero content-CRUD hooks, hook-free direct SQL), and DESIGN.md
 * §3.4 states that as a blanket rule for apply. Both requirements can't be
 * satisfied inside the same transaction, so this class runs entirely
 * OUTSIDE Canary::arm()/disarm() — never call either from here, and never
 * add plugin-activation special-casing to Canary.php itself (the whole
 * value of the canary is that "armed" means one fixed thing).
 *
 * Code payload materialization itself belongs to the separate stage/finalize
 * commands. This class consumes their verified ledger markers: a normal
 * deploy must never advance code_revision, because lifecycle reconciliation
 * alone cannot prove that the repository payload reached this environment.
 */
final class Deploy {
    /**
     * Thin compatibility facade over LifecyclePlanner::code_mismatch()
     * (DUO-3350 slice 6) — kept so this method's existing internal call site
     * (run(), unchanged) and the external callers (Apply::build_plan(),
     * sandbox/tests/regress_template_mismatch.php,
     * sandbox/tests/live/regress_adapter_theme_range.sh) need no edit while this
     * decomposition proceeds.
     */
    public static function code_mismatch(Policy $policy, array $desired): array {
        return LifecyclePlanner::code_mismatch($policy, $desired);
    }

    /**
     * Thin compatibility facade over LifecyclePlanner::code_revision_mismatch()
     * (DUO-3350 slice 6) — kept so this method's existing internal call site
     * (run(), unchanged) and the external caller (Apply::build_plan()) need
     * no edit while this decomposition proceeds.
     */
    public static function code_revision_mismatch(CompiledRepository $compiled): array {
        return LifecyclePlanner::code_revision_mismatch($compiled);
    }

    /**
     * Thin compatibility facade over LifecyclePlanner::code_drift()
     * (DUO-3350 slice 6) — kept so this method's existing internal call site
     * (run(), unchanged) and the external caller (Apply::build_plan()) need
     * no edit while this decomposition proceeds.
     */
    public static function code_drift(Policy $policy, array $desired): array {
        return LifecyclePlanner::code_drift($policy, $desired);
    }

    /**
     * Thin compatibility facade over LifecyclePlanner::record_code_versions()
     * (DUO-3350 slice 6) — kept so this method's existing internal call site
     * (run(), unchanged) and the external caller (Capture::run()) need no
     * edit while this decomposition proceeds.
     */
    public static function record_code_versions(Policy $policy): void {
        LifecyclePlanner::record_code_versions($policy);
    }

    /**
     * `wp duo deploy` — the agent-side half of the proposal's `duo deploy`
     * step (§3.4's ordering: materialize code [cli/'s orchestrator, not
     * this] -> reconcile activation [here] -> plugin/theme migrations run
     * as a side effect of activation hooks firing -> THEN `duo apply`).
     * Refuses loudly, listing every blocking finding, while any
     * currently-desired-active plugin/theme is missing from this
     * environment's code or outside its manifest's version_range —
     * --force-code-mismatch overrides, matching apply's existing
     * --force-theirs/--force-delete-referenced convention. Idempotent: a
     * lifecycle-only mismatch rows are consumed here rather than refused.
     * A second run against an already-reconciled environment computes empty
     * $toActivate/$toDeactivate/order/theme diffs and calls zero lifecycle
     * APIs, so no hook re-fires and the run is a genuine no-op.
     *
     * @return array{activated:string[], deactivated:string[], theme_switched:?string, active_plugins_order_corrected:bool, code_mismatch:array, reconciled_code_mismatch:array, external_side_effects:string[], warnings:string[]}
     */
    public static function run(string $repo, array $opts = []): array {
        $repo = rtrim($repo, '/');
        $policy = Policy::load($repo);
        $compiledPath = (string) ($opts['compiled'] ?? '');
        $compiled = $compiledPath !== ''
            ? RepositoryCompiler::read_artifact($compiledPath, $policy)
            : RepositoryCompiler::compile($repo, $policy);
        // Compile before cron suppression, target reads, ledger creation, or
        // lifecycle hooks. Deploy then consumes only the immutable artifact
        // which plan/apply use; it never reopens state/options/core.json.
        $tree = $compiled->tree();
        Canary::suppress_cron_spawn();
        Ledger::ensure();
        $promotionOwner = PromotionLock::owner($opts);
        $promotionArtifact = $compiled->artifact_hash();
        $continuation = (string) ($opts['promotion_owner'] ?? '') !== '';
        $expectedArtifact = (string) ($opts['artifact_hash'] ?? '');
        self::assert_materializing_continuation($opts, $continuation);
        self::assert_state_handoff_continuation($opts, $continuation);
        $lifecyclePhase = self::lifecycle_phase($opts, $continuation);
        if (($continuation && $expectedArtifact === '')
            || ($expectedArtifact !== ''
                && (!preg_match('/^[0-9a-f]{64}$/', $expectedArtifact)
                    || !hash_equals($expectedArtifact, $promotionArtifact)))) {
            throw new \RuntimeException('duo: deploy artifact does not match the host-compiled artifact hash');
        }
        PromotionLock::acquire($promotionOwner, $promotionArtifact, 'deploy', null, $continuation);
        try {
            $lockedPolicy = Policy::load($repo);
            $lockedCompiled = $compiledPath !== ''
                ? RepositoryCompiler::read_artifact($compiledPath, $lockedPolicy)
                : RepositoryCompiler::compile($repo, $lockedPolicy);
            if (!hash_equals($promotionArtifact, $lockedCompiled->artifact_hash())) {
                throw new \RuntimeException('duo: compiled artifact changed before locked deploy');
            }
            PromotionLock::assert_no_lifecycle_attempt(
                $promotionOwner,
                $promotionArtifact,
                'lifecycle'
            );
            if ($lifecyclePhase !== 'all') {
                PromotionLock::assert_lifecycle_phase_start(
                    $promotionOwner,
                    $promotionArtifact,
                    $lifecyclePhase
                );
            }
            // The promotion lock and locked-artifact revalidation are the
            // boundary at which a staged code payload may authorize this
            // lifecycle phase. Code owns the proof: revision, artifact
            // identity, canonical staged descriptor, and target hashes.
            if (!empty($opts['materializing_code'])) {
                Code::assert_verified_staged($lockedCompiled);
            }
            $desired = isset($tree['options/core'])
                ? self::extract_desired($tree['options/core']['data'])
                : [];

        $revisionMismatch = self::code_revision_mismatch($lockedCompiled);
        $stagedMaterialization = !empty($opts['materializing_code']) && (bool) $revisionMismatch;
        if ($revisionMismatch && !$stagedMaterialization) {
            $list = implode("\n\n", array_map(fn($r) => '  - ' . $r['message'], $revisionMismatch));
            throw new \RuntimeException(
                "duo: deploy refused — code_revision_stale:\n\n$list\n\n"
                . "This lifecycle command cannot materialize code. Run the host 'duo deploy <env>' workflow, "
                . 'which stages and verifies the exact descriptor before invoking this command.'
            );
        }

        $mismatch = array_merge(
            self::code_mismatch($policy, $desired),
            $revisionMismatch
        );
        // An inactive-but-installed plugin is exactly the condition deploy
        // exists to reconcile. It blocks plan/apply, but cannot block this
        // lifecycle phase from activating it. A template_mismatch is also a
        // lifecycle row: switch_theme() can repair an independently-mutated
        // template option. code_revision_stale was checked above; it is
        // allowed here only when the verified stage marker proves the host
        // orchestrator is between code-stage and code-finalize.
        $blockingMismatch = array_values(array_filter(
            $mismatch,
            fn($r) => !in_array(
                $r['issue'],
                [
                    'inactive_in_environment',
                    'unexpected_active_plugin',
                    'active_plugin_order_mismatch',
                    'template_mismatch',
                    'code_revision_stale',
                ],
                true
            )
        ));
        if ($blockingMismatch && empty($opts['force_code_mismatch'])) {
            $list = implode("\n\n", array_map(fn($r) => '  - ' . $r['message'], $blockingMismatch));
            throw new \RuntimeException(
                "duo: deploy refused — code_mismatch:\n\n$list\n\n"
                . 'Install/vendor whatever is missing (or update code/) in this environment first, '
                . 'or pass --force-code-mismatch to proceed anyway.'
            );
        }

        // DUO-3231: checked here too, not just at apply time — a deploy on
        // top of already-drifted code would reconcile activation against a
        // plugin version nobody vouched for, compounding rather than
        // catching the risk. Checked BEFORE this run's own reconciliation
        // touches anything, same ordering rule code_mismatch above follows.
        $drift = self::code_drift($policy, $desired);
        if ($drift && empty($opts['force_code_drift']) && !$stagedMaterialization) {
            $list = implode("\n\n", array_map(fn($r) => '  - ' . $r['message'], $drift));
            throw new \RuntimeException(
                "duo: deploy refused — code_drift:\n\n$list\n\n"
                . 'Reconcile the environment to a known version first, or pass --force-code-drift to proceed anyway.'
            );
        }

        self::require_plugin_admin_functions();
        // Architecture Rulings §1 (report-not-hide): each lifecycle phase runs
        // in the narrow interval after code-stage and before code-finalize,
        // so the stale descriptor finding remains visible in both JSON and
        // human output. A version delta caused by that same verified staging
        // is reported, but is not mislabeled as a forced out-of-band change.
        $warnings = [];
        if ($stagedMaterialization) {
            foreach ($revisionMismatch as $r) {
                $warnings[] = 'staged code materialization: ' . $r['message'];
            }
        }
        if ($drift && !empty($opts['force_code_drift'])) {
            foreach ($drift as $r) {
                $warnings[] = 'FORCED past code_drift: ' . $r['message'];
            }
        } elseif ($drift && $stagedMaterialization) {
            foreach ($drift as $r) {
                $warnings[] = 'code_drift observed during verified staged code materialization: ' . $r['message'];
            }
        }
        // Same posture, one issue over: an outside_version_range code_mismatch
        // finding that survived the refuse-gate above (only possible via
        // --force-code-mismatch) still needs to surface here, not just in
        // --format=json output. missing_in_code findings are excluded — they
        // already get their own more specific "skipped activating .../skipped
        // theme switch ..." message below, once activation actually reaches
        // them; double-reporting the same finding under two different
        // messages would be noise, not signal.
        foreach (array_filter($blockingMismatch, fn($r) => $r['issue'] === 'outside_version_range') as $r) {
            $warnings[] = 'FORCED past code_mismatch: ' . $r['message'];
        }
        $activated = [];
        $deactivated = [];
        $themeSwitched = null;
        $orderCorrected = false;
        // Findings that survived the refuse-gate above (only possible when
        // --force-code-mismatch was passed) still name real absences —
        // calling activate_plugin()/switch_theme() on something with no
        // file on disk would just throw a PHP include warning/fatal, so
        // forced-through missing entries are skipped from the actual work,
        // not attempted. outside_version_range findings are NOT skipped —
        // that issue means "present but a version this manifest doesn't
        // vouch for," never "absent," so activation still makes sense.
        $missingPlugins = array_column(
            array_filter($blockingMismatch, fn($r) => $r['kind'] === 'plugin' && $r['issue'] === 'missing_in_code'),
            'plugin'
        );
        // DUO-3222: issue-scoped, mirroring $missingPlugins immediately above
        // — before this issue, EVERY theme-kind finding WAS missing_in_code
        // (theme version_range didn't exist yet), so the original blanket
        // "any theme finding at all" check was exactly right at the time.
        // Now that outside_version_range exists for themes too, that same
        // blanket check would also skip switch_theme() for a theme that
        // genuinely EXISTS on disk (wp_get_theme()->exists() already passed
        // to produce that finding at all) — switch_theme() would work fine,
        // and the plugin side's own reasoning two lines below already
        // establishes the precedent: "outside_version_range ... means
        // present but a version this manifest doesn't vouch for, never
        // absent, so [the action] still makes sense." Scoping to
        // missing_in_code keeps themes on that identical footing.
        $themeMissing = (bool) array_filter(
            $mismatch, fn($r) => $r['kind'] === 'theme' && $r['issue'] === 'missing_in_code'
        );

        $desiredActive = $desired['active_plugins'] ?? null;
        $currentActive = self::current_active_plugins();
        $toActivate = $desiredActive === null
            ? []
            : array_values(array_diff($desiredActive, $currentActive));
        $toDeactivate = $desiredActive === null
            ? []
            : array_values(array_diff($currentActive, $desiredActive));
        $desiredStylesheet = $desired['stylesheet'] ?? null;
        $desiredTemplate = $desired['template'] ?? null;
        $stylesheetMismatch = $desiredStylesheet !== null
            && get_option('stylesheet') !== $desiredStylesheet;
        $templateMismatch = $desiredStylesheet !== null
            && $desiredTemplate !== null
            && get_option('template') !== $desiredTemplate;

        if ($lifecyclePhase === 'activate' && $toDeactivate) {
            throw new \RuntimeException(
                'duo: lifecycle activation refused — retirement phase did not remove: '
                . implode(', ', $toDeactivate)
            );
        }
        if ($lifecyclePhase === 'all' && $toActivate && $toDeactivate) {
            throw new \RuntimeException(
                'duo: replacing active plugin identities requires fresh retire and activate processes; '
                . "run the host 'duo deploy <env>' or 'duo promote <env>' workflow"
            );
        }

        $overallWillMutate = (bool) ($toActivate || $toDeactivate
            || $stylesheetMismatch || $templateMismatch
            || ($desiredActive !== null && $currentActive !== $desiredActive));
        $phaseWillMutate = match ($lifecyclePhase) {
            'retire' => (bool) $toDeactivate,
            'activate' => (bool) ($toActivate || $stylesheetMismatch || $templateMismatch
                || ($desiredActive !== null && $currentActive !== $desiredActive)),
            default => $overallWillMutate,
        };
        $pendingHandoff = !empty($opts['state_handoff'])
            && $lifecyclePhase === 'activate'
            && PromotionLock::has_pending_state_transition(
                $promotionOwner,
                $promotionArtifact,
                'options/core'
            );
        $pendingRetireHandoff = !empty($opts['state_handoff'])
            && $lifecyclePhase === 'retire'
            && PromotionLock::has_pending_state_transition(
                $promotionOwner,
                $promotionArtifact,
                'options/core'
            );
        $needsHandoffSnapshot = $phaseWillMutate || (!empty($opts['state_handoff']) && match ($lifecyclePhase) {
            // A retry after a crash still needs a live-hash check against the
            // receipt's post-retirement hash, even when the first leg already
            // removed every plugin that this process would otherwise retire.
            'retire' => $overallWillMutate || $pendingRetireHandoff,
            'activate' => $phaseWillMutate || $pendingHandoff,
            default => $phaseWillMutate,
        });
        $lifecycleBeforeSnapshot = null;
        if ($needsHandoffSnapshot) {
            $lifecycleBeforeSnapshot = self::options_snapshot(
                $repo,
                $lockedPolicy,
                $lockedCompiled,
                !empty($opts['force_unresolved_refs'])
            );
            if (($lifecyclePhase === 'retire' && $pendingRetireHandoff)
                || ($lifecyclePhase === 'activate' && $pendingHandoff)) {
                PromotionLock::assert_pending_state_transition_start(
                    $promotionOwner,
                    $promotionArtifact,
                    'options/core',
                    $lifecycleBeforeSnapshot['hash']
                );
            }
            if ($phaseWillMutate) {
                PromotionLock::begin_lifecycle_attempt(
                    $promotionOwner,
                    $promotionArtifact,
                    'options/core',
                    $lifecyclePhase,
                    $lifecycleBeforeSnapshot['hash']
                );
            }
        }

        Canary::begin_external_observation();
        try {
            // DUO-3350 slice 8: the real WordPress lifecycle mutation for this
            // phase now lives in LifecycleExecutor::execute(). Kept here: the
            // PromotionLock/Canary sequencing around the call and the failure
            // augmentation below, both run()'s own orchestration rather than
            // lifecycle mutation.
            $mutation = LifecycleExecutor::execute(
                $lifecyclePhase,
                $toDeactivate,
                $toActivate,
                $missingPlugins,
                $desiredActive,
                $desiredStylesheet,
                $desiredTemplate,
                $themeMissing,
                $stylesheetMismatch,
                $templateMismatch,
                $promotionOwner,
                $promotionArtifact
            );
            $activated = $mutation['activated'];
            $deactivated = $mutation['deactivated'];
            $themeSwitched = $mutation['theme_switched'];
            $orderCorrected = $mutation['order_corrected'];
            foreach ($mutation['warnings'] as $w) {
                $warnings[] = $w;
            }
        } catch (\Throwable $t) {
            $externalSideEffects = Canary::end_external_observation();
            $detail = $externalSideEffects
                ? "\nDeploy-window external side effects observed:\n  - " . implode("\n  - ", $externalSideEffects)
                : '';
            throw new \RuntimeException($t->getMessage() . $detail, 0, $t);
        }
        $externalSideEffects = Canary::end_external_observation();
        foreach ($externalSideEffects as $observed) {
            $warnings[] = 'deploy-window observation: ' . $observed;
        }
        if ($lifecycleBeforeSnapshot !== null) {
            PromotionLock::heartbeat($promotionOwner, $promotionArtifact, 'deploy-state-handoff');
            $lifecycleAfterSnapshot = self::options_snapshot(
                $repo,
                $lockedPolicy,
                $lockedCompiled,
                !empty($opts['force_unresolved_refs'])
            );
            $unexpected = self::unexpected_lifecycle_state_changes(
                $lifecycleBeforeSnapshot['document'],
                $lifecycleAfterSnapshot['document'],
                (array) ($tree['options/core']['data'] ?? [])
            );
            if ($unexpected) {
                throw new \RuntimeException(
                    'duo: lifecycle hooks changed canonical authored option(s) outside this compiled state: '
                    . implode(', ', $unexpected)
                    . '. Capture/reconcile those changes before retrying; state apply was not run.'
                );
            }
            if (!empty($opts['state_handoff'])) {
                if ($lifecyclePhase === 'retire' && !$pendingRetireHandoff) {
                    PromotionLock::begin_state_transition(
                        $promotionOwner,
                        $promotionArtifact,
                        'options/core',
                        $lifecycleBeforeSnapshot['hash'],
                        $lifecycleAfterSnapshot['hash']
                    );
                } elseif ($lifecyclePhase === 'retire' && $pendingRetireHandoff) {
                    // The exact pending H0->H1 receipt was already published by
                    // the crashed retire process. Keep it pending for activation;
                    // recording this retry's H1->H1 snapshot would authorize
                    // Apply against the wrong pre-retire base.
                } elseif ($lifecyclePhase === 'activate' && $pendingHandoff) {
                    PromotionLock::complete_state_transition(
                        $promotionOwner,
                        $promotionArtifact,
                        'options/core',
                        $lifecycleBeforeSnapshot['hash'],
                        $lifecycleAfterSnapshot['hash']
                    );
                } else {
                    PromotionLock::record_state_transition(
                        $promotionOwner,
                        $promotionArtifact,
                        'options/core',
                        $lifecycleBeforeSnapshot['hash'],
                        $lifecycleAfterSnapshot['hash']
                    );
                }
            } else {
                PromotionLock::complete_lifecycle_attempt(
                    $promotionOwner,
                    $promotionArtifact,
                    'options/core',
                    $lifecyclePhase,
                    $lifecycleBeforeSnapshot['hash'],
                    $lifecycleAfterSnapshot['hash']
                );
            }
        }

        // Re-baseline unconditionally: whatever's active NOW (post-
        // reconciliation, whether clean or forced-through) becomes the new
        // "last known good" — the same versions a --force-code-drift run
        // just accepted are exactly what should stop being flagged on the
        // NEXT run. Runs regardless of whether $drift/$mismatch fired, same
        // as code_mismatch's own findings don't gate whether reconciliation
        // proceeds once past the refuse-gate above.
        PromotionLock::heartbeat($promotionOwner, $promotionArtifact, 'deploy-verify');
        self::record_code_versions($policy);

        $remainingMismatch = array_merge(
            self::code_mismatch($policy, $desired),
            self::code_revision_mismatch($lockedCompiled)
        );
        if ($lifecyclePhase !== 'all') {
            PromotionLock::complete_lifecycle_phase(
                $promotionOwner,
                $promotionArtifact,
                $lifecyclePhase
            );
        }
        $summary = [
            'lifecycle_phase' => $lifecyclePhase,
            'artifact' => [
                'hash' => $compiled->artifact_hash(),
                'revision' => $compiled->revision_hash(),
                'manifests' => $compiled->manifest_hash(),
            ],
            'activated' => $activated,
            'deactivated' => $deactivated,
            'theme_switched' => $themeSwitched,
            'active_plugins_order_corrected' => $orderCorrected,
            'code_mismatch' => $remainingMismatch,
            'reconciled_code_mismatch' => array_values(array_filter(
                $mismatch,
                fn($r) => in_array(
                    $r['issue'],
                    [
                        'inactive_in_environment',
                        'unexpected_active_plugin',
                        'active_plugin_order_mismatch',
                        'template_mismatch',
                    ],
                    true
                )
                    && !in_array($r, $remainingMismatch, true)
            )),
            'code_drift' => $drift,
            'external_side_effects' => $externalSideEffects,
            'warnings' => $warnings,
        ];
            if (!empty($opts['promotion_hold'])) {
                PromotionLock::heartbeat($promotionOwner, $promotionArtifact, 'deployed');
            } else {
                PromotionLock::release($promotionOwner, $promotionArtifact);
            }
            $summary['promotion_lock'] = [
                'owner' => $promotionOwner,
                'held_for_apply' => !empty($opts['promotion_hold']),
            ];
            return $summary;
        } catch (\Throwable $t) {
            try {
                PromotionLock::release($promotionOwner, $promotionArtifact);
            } catch (\Throwable $_releaseFailure) {
                // Preserve the lifecycle/precondition failure. An unreleased
                // lease is bounded and the next owner can recover it.
            }
            throw $t;
        }
    }

    /**
     * The stale-code lifecycle exception is an internal handoff from the
     * host's verified code-stage phase. A standalone agent invocation may
     * never manufacture that exception with the boolean flag alone; strict
     * PromotionLock continuation below then proves the exact begun session.
     */
    private static function assert_materializing_continuation(array $opts, bool $continuation): void {
        if (!empty($opts['materializing_code']) && !$continuation) {
            throw new \RuntimeException(
                'duo: --materializing-code is valid only inside the host orchestrator promotion continuation; '
                . "run 'duo deploy <env>'"
            );
        }
    }

    private static function assert_state_handoff_continuation(array $opts, bool $continuation): void {
        if (!empty($opts['state_handoff'])
            && (!$continuation || empty($opts['promotion_hold']))) {
            throw new \RuntimeException(
                'duo: --state-handoff is valid only inside a retained host promotion continuation'
            );
        }
    }

    private static function lifecycle_phase(array $opts, bool $continuation): string {
        $phase = (string) ($opts['lifecycle_phase'] ?? 'all');
        if (!in_array($phase, ['all', 'retire', 'activate'], true)) {
            throw new \RuntimeException(
                "duo: unsupported lifecycle phase '$phase' (expected retire or activate)"
            );
        }
        if ($phase !== 'all' && !$continuation) {
            throw new \RuntimeException(
                'duo: phased lifecycle commands are valid only inside a host promotion continuation'
            );
        }
        if ($phase === 'retire' && empty($opts['promotion_hold'])) {
            throw new \RuntimeException(
                'duo: lifecycle retirement must retain the lease for fresh-process activation'
            );
        }
        return $phase;
    }

    /**
     * Pulls active_plugins/template/stylesheet out of an already-decoded
     * options/core.json record document. Shared with Apply::build_plan(), which already
     * has the target tree's options entity decoded (from load_tree()) and
     * would otherwise have to duplicate this same key-extraction — one
     * implementation read from two different starting points (a file path
     * here, an in-memory decoded array there) is how `duo plan`/`duo
     * status` and `duo deploy` stay unable to disagree about what the
     * target state actually declares.
     *
     * @return array{active_plugins?: string[], template?: string, stylesheet?: string}
     */
    public static function extract_desired(array $options): array {
        $options = OptionState::values($options);
        $out = [];
        if (array_key_exists('active_plugins', $options)) {
            $out['active_plugins'] = array_values(array_map('strval', (array) $options['active_plugins']));
        }
        if (array_key_exists('template', $options)) {
            $out['template'] = (string) $options['template'];
        }
        if (array_key_exists('stylesheet', $options)) {
            $out['stylesheet'] = (string) $options['stylesheet'];
        }
        return $out;
    }

    /**
     * Thin compatibility facade over StateHandoffVerifier::options_snapshot()
     * (DUO-3350 slice 7) -- kept so this method's existing internal call
     * sites (run(), both the before- and after-mutation snapshots,
     * unchanged) need no edit while this decomposition proceeds.
     *
     * @return array{hash:string,document:array<string,mixed>}
     */
    private static function options_snapshot(
        string $repo,
        Policy $policy,
        CompiledRepository $compiled,
        bool $forceUnresolvedRefs
    ): array {
        return StateHandoffVerifier::options_snapshot($repo, $policy, $compiled, $forceUnresolvedRefs);
    }

    /**
     * Thin compatibility facade over
     * StateHandoffVerifier::bind_lifecycle_missing_options() (DUO-3350
     * slice 7) -- kept solely because
     * sandbox/tests/regress_lifecycle_state_handoff.php invokes it via
     * ReflectionMethod(Deploy::class, 'bind_lifecycle_missing_options') for
     * a genuine behavioral test; options_snapshot() was this method's only
     * production caller and that call moved with it, so this facade has no
     * remaining production caller of its own -- caught by grepping for
     * reflection-based callers specifically before writing any code, the
     * same discipline this series has used since DUO-3347 slice 12's
     * assign_locations() precedent.
     */
    private static function bind_lifecycle_missing_options(array $observedDocument, array $desiredDocument): array {
        return StateHandoffVerifier::bind_lifecycle_missing_options($observedDocument, $desiredDocument);
    }

    /**
     * Thin compatibility facade over
     * StateHandoffVerifier::unexpected_lifecycle_state_changes() (DUO-3350
     * slice 7) -- kept so this method's existing internal call site (run(),
     * unchanged) and the two reflection-based test callers
     * (sandbox/tests/regress_lifecycle_options_snapshot.php,
     * sandbox/tests/regress_lifecycle_state_handoff.php) need no edit while
     * this decomposition proceeds.
     *
     * @return list<string>
     */
    private static function unexpected_lifecycle_state_changes(
        array $beforeDocument,
        array $afterDocument,
        array $desiredDocument
    ): array {
        return StateHandoffVerifier::unexpected_lifecycle_state_changes($beforeDocument, $afterDocument, $desiredDocument);
    }

    /**
     * Read Requires Plugins and produce a provider-first order for additions.
     * WordPress validates a plugin's requirements at activation time, so this
     * order is independent of the desired active_plugins storage order.
     *
     * Public (DUO-3350 slice 8, was private): LifecycleExecutor::execute()
     * -- the extracted lifecycle-mutation pass, its only production caller
     * now -- calls this directly. The rest of this dependency-ordering
     * cluster (plugin_dependency_requirements()/plugin_dependency_slug()/
     * order_deactivations()/order_activations(), below) deliberately stays
     * on Deploy, unmoved: they are stateless graph/header-reading primitives
     * with no lifecycle-mutation concern of their own, and
     * sandbox/tests/regress_deploy_planner.php's own source-text assertions
     * require order_deactivations()/order_activations() to remain private
     * facades calling DeployPlanner:: directly from Deploy.php specifically.
     * sandbox/tests/regress_plugin_dependency_order.php reaches all four via
     * ReflectionMethod(Deploy::class, ...), unaffected by this widening.
     *
     * @param list<string> $plugins active plugin basenames being activated
     * @return list<string>
     */
    public static function dependency_ordered_activations(array $plugins): array {
        return self::order_activations($plugins, self::plugin_dependency_requirements($plugins));
    }

    /**
     * Read WordPress's bounded Requires Plugins headers and produce a reverse
     * dependency order for the exact removal set.
     *
     * Public (DUO-3350 slice 8, was private) -- see
     * dependency_ordered_activations()'s docblock immediately above.
     *
     * @param list<string> $plugins active plugin basenames being retired
     * @return list<string>
     */
    public static function dependency_ordered_deactivations(array $plugins): array {
        return self::order_deactivations($plugins, self::plugin_dependency_requirements($plugins));
    }

    /**
     * Read WordPress's bounded Requires Plugins headers for an exact lifecycle
     * set. A provider outside the set is already active (or otherwise not part
     * of this transition), so it does not need a lifecycle edge here.
     *
     * @param list<string> $plugins plugin basenames in the transition set
     * @return array<string,list<string>> plugin => providers in this set
     */
    private static function plugin_dependency_requirements(array $plugins): array {
        $bySlug = [];
        foreach ($plugins as $plugin) {
            $slug = self::plugin_dependency_slug($plugin);
            if (isset($bySlug[$slug]) && $bySlug[$slug] !== $plugin) {
                throw new \RuntimeException(
                    "duo: cannot prove plugin dependency lifecycle because '$slug' identifies both "
                    . "'{$bySlug[$slug]}' and '$plugin'"
                );
            }
            $bySlug[$slug] = $plugin;
        }

        $requirements = [];
        foreach ($plugins as $plugin) {
            $requirements[$plugin] = [];
            $path = rtrim(WP_PLUGIN_DIR, '/') . '/' . $plugin;
            if (!is_file($path)) {
                // A missing lifecycle plugin has no executable hook or readable
                // declaration. Other plugins can still name its slug, which is
                // enough to order the transition around this record.
                continue;
            }
            $data = get_plugin_data($path, false, false);
            $raw = trim((string) ($data['RequiresPlugins'] ?? ''));
            if ($raw === '') {
                continue;
            }
            foreach (CodeCompatibility::dependency_slugs($raw) as $requiredSlug) {
                if (isset($bySlug[$requiredSlug])) {
                    $requirements[$plugin][] = $bySlug[$requiredSlug];
                }
            }
            $requirements[$plugin] = array_values(array_unique($requirements[$plugin]));
        }
        return $requirements;
    }

    /** WordPress core's plugin-file -> dependency-slug mapping. */
    private static function plugin_dependency_slug(string $plugin): string {
        return CodeCompatibility::plugin_slug($plugin);
    }

    /**
     * Stable topological sort with dependent -> provider edges. When two
     * nodes are independent, use reverse active order as the deterministic
     * lifecycle-compatible tie break.
     *
     * @param list<string> $plugins
     * @param array<string,list<string>> $requirements plugin => providers
     * @return list<string>
     */
    private static function order_deactivations(array $plugins, array $requirements): array {
        return DeployPlanner::order_deactivations($plugins, $requirements);
    }

    /**
     * Stable topological sort with provider -> dependent edges. Independent
     * nodes retain their desired-list order; callers may write that desired
     * list back after activation to preserve authored/native state exactly.
     *
     * @param list<string> $plugins
     * @param array<string,list<string>> $requirements plugin => providers
     * @return list<string>
     */
    private static function order_activations(array $plugins, array $requirements): array {
        return DeployPlanner::order_activations($plugins, $requirements);
    }

    /**
     * Public: LifecyclePlanner.php's code_mismatch()/code_drift()/
     * record_code_versions() (DUO-3350 slice 6) share this accessor too.
     * @return string[]
     */
    public static function current_active_plugins(): array {
        $raw = get_option('active_plugins');
        return is_array($raw) ? array_values(array_map('strval', $raw)) : [];
    }

    /**
     * The live existence/lifecycle/version facts about one plugin basename,
     * read through the exact primitives code_mismatch() above uses:
     * validate_plugin() for existence (WordPress's own validation primitive,
     * correct for both `slug/slug.php` and legacy single-file plugins),
     * get_option('active_plugins') for the lifecycle state, and WordPress's
     * plugin-header parser for the installed version.
     *
     * Public because DUO-3338's provider negotiation asks the same question
     * about a provider's owning plugin from Providers::negotiate(). Sharing
     * this accessor — rather than letting a second class learn where the live
     * plugin facts live and which wp-admin include has to be loaded first —
     * is what keeps "installed", "active", and "which version" from acquiring
     * two definitions that can disagree.
     *
     * @return array{installed:bool, active:bool, version:string}
     */
    public static function plugin_runtime_state(string $plugin): array {
        self::require_plugin_admin_functions();
        $installed = !is_wp_error(validate_plugin($plugin));
        $all = $installed ? get_plugins() : [];
        return [
            'installed' => $installed,
            'active' => in_array($plugin, self::current_active_plugins(), true),
            'version' => (string) ($all[$plugin]['Version'] ?? ''),
        ];
    }

    /**
     * Min inclusive, max exclusive — the whole of the version_range mechanic
     * (Policy::version_ranges()'s docblock explains why it is two
     * version_compare() calls and not a semver-range parser). Public so
     * DUO-3338's provider negotiation bounds a provider by the identical
     * arithmetic that bounds its manifest's classification guarantees,
     * instead of a second copy that could drift on an edge case.
     */
    public static function in_range(string $installed, string $min, string $max): bool {
        return version_compare($installed, $min, '>=') && version_compare($installed, $max, '<');
    }

    /**
     * validate_plugin()/activate_plugin()/deactivate_plugins()/get_plugins()
     * all live in wp-admin/includes/plugin.php, not loaded by default
     * outside wp-admin — same require-on-demand pattern Apply::rebuild()
     * already uses for wp-admin/includes/{image,file,media}.php before
     * calling wp_generate_attachment_metadata().
     *
     * Public: LifecyclePlanner.php's code_mismatch()/code_drift()/
     * record_code_versions() (DUO-3350 slice 6) share this guard too.
     */
    public static function require_plugin_admin_functions(): void {
        if (!function_exists('validate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }
}
