<?php
namespace Duo;

require_once __DIR__ . '/CodeCompatibility.php';

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
    /** duo_kv key for code_drift()'s baseline — see record_code_versions(). */
    private const CODE_VERSIONS_KEY = 'code_versions';

    /**
     * §3.2's plan-time checks, pure detection (no writes, no hook fires):
     * for every plugin the target state declares active, confirm it exists
     * in this environment (WordPress's own plugin-validation primitive —
     * the same one activate_plugin() itself calls internally, so it
     * correctly handles both `slug/slug.php` and legacy single-file
     * `slug.php` plugins) and, where a loaded manifest pins a version_range
     * for that plugin (Policy::version_ranges()), that the installed
     * version (read via WordPress's own plugin-header parser — correct for
     * both composer-managed and vendored plugins, since vendored plugins
     * have no lockfile to check at all) falls inside it. Symmetric checks
     * for template/stylesheet's theme directories. It also compares desired
     * activation membership, exact plugin load order, and active theme against
     * the live managed options. Those lifecycle rows block apply but are the
     * precise work Deploy::run() is allowed to reconcile.
     *
     * Shared by Deploy::run() (this verb's own refuse-precondition) and
     * Apply::build_plan()'s code_mismatch bucket (surfaced at ordinary
     * `duo plan`/`duo status` time, not just at deploy time) — one
     * implementation, so the two can never disagree about what "in code"
     * means. Descriptor-to-ledger identity is deliberately a separate
     * `code_revision_mismatch()` check below: this method remains about the
     * target's live WordPress lifecycle/code facts, while the descriptor
     * check remains valid even for a payload with no active components.
     *
     * @param array $desired {
     *   active_plugins?: string[] plugin basenames the target state declares active (null = not captured/declared, skip plugin checks entirely),
     *   template?: string parent/standalone theme directory (null = not declared, skip theme checks),
     *   stylesheet?: string active theme directory (null = not declared, skip theme checks),
     * }
     * @return list<array{issue:string, kind:string, plugin?:string, theme?:string, message:string, installed_version?:string, version_range?:array, manifest?:string}> `version_range` carries a plugin's `version_range` or (DUO-3222) a theme's `theme_version_range` uniformly — one shared key regardless of `kind`, matching how both are consumed identically by Apply::build_plan()'s code_mismatch bucket
     */
    public static function code_mismatch(Policy $policy, array $desired): array {
        $rows = [];
        self::require_plugin_admin_functions();

        $desiredActive = $desired['active_plugins'] ?? null;
        if ($desiredActive !== null) {
            $allPlugins = get_plugins();
            $ranges = $policy->version_ranges();
            $currentActive = self::current_active_plugins();
            foreach ($desiredActive as $plugin) {
                $plugin = (string) $plugin;
                $valid = validate_plugin($plugin);
                if (is_wp_error($valid)) {
                    $rows[] = [
                        'issue' => 'missing_in_code',
                        'kind' => 'plugin',
                        'plugin' => $plugin,
                        'message' => "active_plugins in state/options/core.json declares '$plugin' but "
                            . "$plugin does not exist in this environment (checked against this environment's "
                            . "wp-content/plugins/ — phase 1 has no code/ deploy transport, so 'in code' means "
                            . "'installed on the env'). Install/vendor the plugin here, or this branch's code/ "
                            . "changes haven't reached this environment yet.",
                    ];
                    continue; // can't read a version off a plugin that isn't there
                }
                if (!in_array($plugin, $currentActive, true)) {
                    $rows[] = [
                        'issue' => 'inactive_in_environment',
                        'kind' => 'plugin',
                        'plugin' => $plugin,
                        'message' => "active_plugins in state/options/core.json declares '$plugin', and its code "
                            . "is installed, but it is not active in this environment. Run 'duo deploy <env>' "
                            . 'before apply so activation hooks and schema migrations complete first.',
                    ];
                }
                if (isset($ranges[$plugin])) {
                    $r = $ranges[$plugin];
                    $installed = (string) ($allPlugins[$plugin]['Version'] ?? '');
                    if ($installed === '' || !self::in_range($installed, $r['min'], $r['max'])) {
                        $rows[] = [
                            'issue' => 'outside_version_range',
                            'kind' => 'plugin',
                            'plugin' => $plugin,
                            'installed_version' => $installed,
                            'version_range' => ['min' => $r['min'], 'max' => $r['max']],
                            'manifest' => $r['manifest'],
                            'message' => "$plugin " . ($installed !== '' ? $installed : '(unknown version)')
                                . " is active in this environment, outside the '{$r['manifest']}' manifest's "
                                . "declared version_range (>={$r['min']} <{$r['max']}, pinned by site.duo.json). "
                                . "Classification guarantees for this plugin are NOT validated against this "
                                . "version — apply may silently misclassify fields. Update the plugin, pin an "
                                . "older manifest, or pass --force-code-mismatch to proceed at your own risk.",
                        ];
                    }
                }
            }
            foreach (array_values(array_diff($currentActive, $desiredActive)) as $plugin) {
                $rows[] = [
                    'issue' => 'unexpected_active_plugin',
                    'kind' => 'plugin',
                    'plugin' => $plugin,
                    'message' => "plugin '$plugin' is active in this environment but absent from canonical "
                        . "active_plugins. Run 'duo deploy <env>' before apply so its deactivation hooks complete first.",
                ];
            }
            if (!$rows
                && count($currentActive) === count($desiredActive)
                && !array_diff($currentActive, $desiredActive)
                && $currentActive !== $desiredActive) {
                $rows[] = [
                    'issue' => 'active_plugin_order_mismatch',
                    'kind' => 'plugin_order',
                    'message' => 'active_plugins contains the canonical plugin set but its load order differs. '
                        . "Run 'duo deploy <env>' before apply so WordPress loads plugins in the declared order.",
                    'desired_order' => $desiredActive,
                    'environment_order' => $currentActive,
                ];
            }
        }

        // DUO-3222: themes now get the SAME version-range treatment plugins
        // already had above — existence-only was the exact gap the issue's
        // own review confirmed live ("themes get existence-checked only...
        // no version treatment"). Read theme_ranges() once, check it after
        // each slot's existing existence check passes (mirrors the plugin
        // loop's own missing_in_code -> continue -> range-check order: a
        // theme that doesn't exist has no version to read).
        $themeRanges = $policy->theme_ranges();
        $desiredStylesheet = $desired['stylesheet'] ?? null;
        $desiredTemplate = $desired['template'] ?? null;
        if ($desiredStylesheet !== null) {
            if (!wp_get_theme($desiredStylesheet)->exists()) {
                $rows[] = [
                    'issue' => 'missing_in_code',
                    'kind' => 'theme',
                    'theme' => $desiredStylesheet,
                    'message' => "stylesheet in state/options/core.json declares '$desiredStylesheet' but "
                        . "$desiredStylesheet does not exist in this environment (checked against this "
                        . "environment's wp-content/themes/). Install/vendor the theme here, or this branch's "
                        . "code/ changes haven't reached this environment yet.",
                ];
            } else {
                self::check_theme_range($desiredStylesheet, $themeRanges, $rows);
                if (get_option('stylesheet') !== $desiredStylesheet) {
                    $rows[] = [
                        'issue' => 'inactive_in_environment',
                        'kind' => 'theme',
                        'theme' => $desiredStylesheet,
                        'message' => "stylesheet in state/options/core.json declares '$desiredStylesheet', and its code "
                            . "is installed, but this environment has theme '" . (string) get_option('stylesheet')
                            . "' active. Run 'duo deploy <env>' before apply so the theme lifecycle completes first.",
                    ];
                }
            }
        }
        if ($desiredTemplate !== null && $desiredTemplate !== $desiredStylesheet) {
            if (!wp_get_theme($desiredTemplate)->exists()) {
                $rows[] = [
                    'issue' => 'missing_in_code',
                    'kind' => 'theme',
                    'theme' => $desiredTemplate,
                    'message' => "template in state/options/core.json declares '$desiredTemplate' (this "
                        . "environment's child theme's parent) but $desiredTemplate does not exist in this "
                        . "environment (checked against this environment's wp-content/themes/). A child theme's "
                        . "switch_theme() needs its parent present too. Install/vendor the parent theme here, or "
                        . "this branch's code/ changes haven't reached this environment yet.",
                ];
            } else {
                self::check_theme_range($desiredTemplate, $themeRanges, $rows);
            }
        }
        // A child theme's stylesheet can already be correct while the
        // template option has been mutated independently (or inherited from
        // an old theme). WordPress renders against both options. Treat that
        // as a lifecycle mismatch instead of silently accepting inert FSE
        // rows; run() replays switch_theme() and then proves the parent it
        // resolved is exactly the canonical one.
        if ($desiredStylesheet !== null
            && $desiredTemplate !== null
            && get_option('stylesheet') === $desiredStylesheet
            && get_option('template') !== $desiredTemplate) {
            $rows[] = [
                'issue' => 'template_mismatch',
                'kind' => 'theme',
                'theme' => $desiredStylesheet,
                'template' => $desiredTemplate,
                'environment_template' => (string) get_option('template'),
                'message' => "stylesheet '$desiredStylesheet' is active, but state/options/core.json declares "
                    . "template '$desiredTemplate' and this environment has template '"
                    . (string) get_option('template') . "'. Run 'duo deploy <env>' so WordPress can reconcile "
                    . 'the theme through switch_theme(); Duo will refuse if this stylesheet cannot resolve to the '
                    . 'declared parent.',
            ];
        }
        return $rows;
    }

    /**
     * Descriptor-to-target materialization proof. A descriptor is opt-in:
     * legacy compiled artifacts deliberately return null and retain the
     * lifecycle-only behavior they had before code payload management. Once
     * a descriptor exists, however, a missing completed marker is every bit
     * as stale as a different one — merely observing compatible installed
     * plugin headers is not proof that this artifact's bytes were deployed.
     *
     * @return list<array{issue:string,kind:string,expected_revision:string,completed_revision:?string,message:string}>
     */
    public static function code_revision_mismatch(CompiledRepository $compiled): array {
        $revision = self::compiled_code_revision($compiled);
        if ($revision === null) {
            return [];
        }
        if (!class_exists(Code::class) || !method_exists(Code::class, 'completed_code_mismatch')) {
            throw new \RuntimeException(
                'duo: code payload proof implementation is unavailable; refusing to trust code_revision'
            );
        }
        // Code owns the payload proof. This bridge only preserves the
        // structured plan/apply finding shape used by older callers.
        $detail = Code::completed_code_mismatch($compiled);
        if ($detail === null) {
            return [];
        }
        $completed = Ledger::kv_get(Code::CODE_REVISION_KEY);
        return [[
            'issue' => 'code_revision_stale',
            'kind' => 'code',
            'expected_revision' => $revision,
            'completed_revision' => $completed,
            'message' => "compiled code revision '$revision' is stale on this environment: $detail. "
                . "Run 'duo deploy <env>' so Duo can stage, reconcile, verify, and finalize this exact code payload before apply.",
        ]];
    }

    /**
     * Return the opt-in descriptor revision, or null for a legacy artifact.
     * The method_exists guard lets an older drop-in agent continue to read a
     * legacy artifact during a rolling agent upgrade; a current descriptor
     * must still have both compiler accessors and a non-empty revision.
     */
    private static function compiled_code_revision(CompiledRepository $compiled): ?string {
        if (!method_exists($compiled, 'code_descriptor')) {
            return null;
        }
        $descriptor = $compiled->code_descriptor();
        if ($descriptor === null) {
            return null;
        }
        if (!is_array($descriptor) || !method_exists($compiled, 'code_revision')) {
            throw new \RuntimeException('duo: compiled code descriptor is malformed or unsupported by this agent');
        }
        $revision = $compiled->code_revision();
        if (!is_string($revision) || $revision === '') {
            throw new \RuntimeException('duo: compiled code descriptor has no revision');
        }
        return $revision;
    }

    /**
     * DUO-3231 (docs/proposals/code-half.md's risk register #1, "wp-admin/
     * filesystem-initiated updates are silent code drift, and detection
     * alone is not a fix"): a SEPARATE question from code_mismatch() above.
     * code_mismatch asks "is what's installed compatible with what the
     * manifests/repo say is acceptable" (missing entirely, or outside a
     * pinned version_range — a wide band). code_drift asks "did this
     * specific plugin/theme's version change since the last time Duo
     * itself reconciled or observed this environment" — a narrower,
     * provenance question a version_range can't answer: a wp-admin
     * one-click update from 7.2.0 to 7.5.0 can land comfortably inside an
     * ">=7.0 <9.0" range and stay invisible to code_mismatch entirely,
     * while still being exactly the out-of-band mutation risk #1 names.
     * The baseline this compares against is written by
     * record_code_versions() below, called at the end of a successful
     * `duo deploy` AND `duo capture` (Capture::run()) — either is a moment
     * Duo legitimately observed the environment's code, so either is a
     * valid "last known good" checkpoint. No baseline yet for a given
     * plugin (never deployed/captured since this mechanism shipped, or
     * newly activated this run) means nothing to compare against — silent,
     * not a false positive, mirroring task #73's own minted-vs-unminted
     * distinction for state entities.
     *
     * Scoped to exactly the entities $desired already names (the same
     * active_plugins/template/stylesheet the target state declares,
     * identical scope to code_mismatch() above) — Duo has no opinion on
     * drift for a plugin it was never told to manage.
     *
     * @return list<array{issue:string, kind:string, plugin?:string, theme?:string, message:string, installed_version:string, recorded_version:string}>
     */
    public static function code_drift(Policy $policy, array $desired): array {
        $rows = [];
        $recordedRaw = Ledger::kv_get(self::CODE_VERSIONS_KEY);
        if ($recordedRaw === null) {
            return $rows; // no baseline recorded yet anywhere — nothing to compare
        }
        $recorded = json_decode($recordedRaw, true);
        $recorded = is_array($recorded) ? $recorded : [];

        $desiredActive = $desired['active_plugins'] ?? null;
        if ($desiredActive !== null) {
            self::require_plugin_admin_functions();
            $allPlugins = get_plugins();
            $recordedPlugins = (array) ($recorded['plugins'] ?? []);
            foreach ($desiredActive as $plugin) {
                $plugin = (string) $plugin;
                if (!array_key_exists($plugin, $recordedPlugins)) {
                    continue; // never had a baseline for this specific plugin — not drift, just unminted
                }
                $installed = (string) ($allPlugins[$plugin]['Version'] ?? '');
                $baseline = (string) $recordedPlugins[$plugin];
                if ($installed === '' || $installed === $baseline) {
                    continue;
                }
                $rows[] = [
                    'issue' => 'code_drift',
                    'kind' => 'plugin',
                    'plugin' => $plugin,
                    'installed_version' => $installed,
                    'recorded_version' => $baseline,
                    'message' => "$plugin is $installed on this environment, but the last successful 'duo deploy' "
                        . "or 'duo capture' recorded $baseline — its code changed here outside Duo's own "
                        . "reconciliation (a wp-admin/host auto-update is the common cause; see DISALLOW_FILE_MODS "
                        . "in 'wp duo doctor'). Re-run 'duo deploy' to accept $installed as the new baseline, "
                        . "restore $baseline, or pass --force-code-drift to proceed at your own risk.",
                ];
            }
        }

        foreach (['template', 'stylesheet'] as $slot) {
            $desiredSlug = $desired[$slot] ?? null;
            $baselineSlug = $recorded[$slot] ?? null;
            $baselineVersion = $recorded["{$slot}_version"] ?? null;
            if ($desiredSlug === null || $baselineSlug === null || $baselineVersion === null) {
                continue; // no baseline, or nothing declared this run
            }
            if ($desiredSlug !== $baselineSlug) {
                continue; // the theme ITSELF changed — code_mismatch's/plan's territory, not a version drift on one theme
            }
            $installed = (string) wp_get_theme($desiredSlug)->get('Version');
            $baseline = (string) $baselineVersion;
            if ($installed === '' || $installed === $baseline) {
                continue;
            }
            $rows[] = [
                'issue' => 'code_drift',
                'kind' => 'theme',
                'theme' => $desiredSlug,
                'installed_version' => $installed,
                'recorded_version' => $baseline,
                'message' => "$desiredSlug theme is $installed on this environment, but the last successful "
                    . "'duo deploy' or 'duo capture' recorded $baseline — its code changed here outside Duo's own "
                    . "reconciliation. Re-run 'duo deploy' to accept $installed as the new baseline, restore "
                    . "$baseline, or pass --force-code-drift to proceed at your own risk.",
            ];
        }
        return $rows;
    }

    /**
     * Writes the "last known good" code-version baseline code_drift() above
     * compares against. Reads the environment's OWN CURRENT live state
     * directly (get_plugins()/get_option()), not the just-captured/just-
     * deployed canonical tree — the two are expected to agree at the
     * instant this runs (deploy just reconciled activation; capture just
     * read live state), and reading live state directly means this
     * function needs nothing passed in beyond $policy, keeping both call
     * sites (Deploy::run() and Capture::run()) to one line each.
     *
     * Records EVERY currently-active plugin's version, not just ones a
     * $desired list happens to name — code_drift() only ever CONSULTS the
     * subset $desired scopes it to, so recording a wider baseline here is
     * simply harmless, forward-compatible data (a plugin activated later
     * already has a baseline the moment it's captured/deployed again,
     * rather than needing a special first-run carve-out). Overwrites
     * (never merges stale entries forward) — the goal is "what's true as
     * of right now," not an append-only history.
     */
    public static function record_code_versions(Policy $policy): void {
        self::require_plugin_admin_functions();
        $versions = ['plugins' => []];
        foreach (self::current_active_plugins() as $plugin) {
            $info = get_plugins()[$plugin] ?? null;
            if ($info !== null) {
                $versions['plugins'][$plugin] = (string) $info['Version'];
            }
        }
        $stylesheet = (string) get_option('stylesheet');
        $template = (string) get_option('template');
        if ($stylesheet !== '') {
            $versions['stylesheet'] = $stylesheet;
            $versions['stylesheet_version'] = (string) wp_get_theme($stylesheet)->get('Version');
        }
        if ($template !== '') {
            $versions['template'] = $template;
            $versions['template_version'] = (string) wp_get_theme($template)->get('Version');
        }
        Ledger::kv_set(self::CODE_VERSIONS_KEY, wp_json_encode($versions));
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
            if ($lifecyclePhase !== 'activate' && $toDeactivate) {
                PromotionLock::heartbeat($promotionOwner, $promotionArtifact, 'deploy-retire');
                // WordPress's Requires Plugins header is a dependency graph,
                // not a promise that active_plugins happens to be ordered.
                // Retire dependents before their providers even when an
                // operator or old WordPress version stored a different list
                // order. Independent plugins retain the historical reverse-
                // load-order behavior.
                $deactivated = self::dependency_ordered_deactivations($toDeactivate);
                deactivate_plugins($deactivated);
                $afterRetire = self::current_active_plugins();
                foreach ($deactivated as $plugin) {
                    if (in_array($plugin, $afterRetire, true)) {
                        throw new \RuntimeException("duo: plugin deactivation did not persist for '$plugin'");
                    }
                }
            }

            if ($lifecyclePhase !== 'retire') {
                // WordPress's Requires Plugins header is a dependency graph,
                // not a promise that active_plugins happens to be ordered.
                // Activate providers first so a clean target can satisfy the
                // native requirement check even when the desired state is the
                // alphabetical/native order produced by activate_plugin().
                // The exact authored order is restored below after all
                // membership changes have succeeded.
                $activationOrder = self::dependency_ordered_activations($toActivate);
                foreach ($activationOrder as $plugin) {
                    PromotionLock::heartbeat($promotionOwner, $promotionArtifact, 'deploy-activate');
                    if (in_array($plugin, $missingPlugins, true)) {
                        $warnings[] = "skipped activating '$plugin' (missing_in_code, --force-code-mismatch was set)";
                        continue;
                    }
                    $result = activate_plugin($plugin); // hooks fire deliberately — this is the point of this class
                    if (is_wp_error($result)) {
                        throw new \RuntimeException("duo: required plugin activation failed for '$plugin'");
                    }
                    $activated[] = $plugin;
                }
                $after = self::current_active_plugins();
                foreach ($toActivate as $plugin) {
                    if (!in_array($plugin, $missingPlugins, true) && !in_array($plugin, $after, true)) {
                        throw new \RuntimeException("duo: plugin activation did not persist for '$plugin'");
                    }
                }
                if ($desiredActive !== null && !$missingPlugins && $after !== $desiredActive) {
                    PromotionLock::heartbeat($promotionOwner, $promotionArtifact, 'deploy-plugin-order');
                    // WordPress exposes no lifecycle API for load-order changes.
                    // Membership has already been reconciled through activate/
                    // deactivate above; this managed option write changes order
                    // only and is verified immediately.
                    update_option('active_plugins', $desiredActive);
                    $after = self::current_active_plugins();
                    if ($after !== $desiredActive) {
                        throw new \RuntimeException('duo: exact active plugin order did not persist');
                    }
                    $orderCorrected = true;
                }

                if ($desiredStylesheet !== null && ($stylesheetMismatch || $templateMismatch)) {
                    PromotionLock::heartbeat($promotionOwner, $promotionArtifact, 'deploy-theme');
                    if ($themeMissing) {
                        $warnings[] = "skipped theme switch to '$desiredStylesheet' (missing_in_code, --force-code-mismatch was set)";
                    } else {
                        switch_theme($desiredStylesheet); // hooks fire deliberately (switch_theme/after_switch_theme)
                        $themeSwitched = $desiredStylesheet;
                        if (get_option('stylesheet') !== $desiredStylesheet) {
                            throw new \RuntimeException("duo: theme switch did not persist for '$desiredStylesheet'");
                        }
                        if ($desiredTemplate !== null && get_option('template') !== $desiredTemplate) {
                            $actualTemplate = (string) get_option('template');
                            throw new \RuntimeException(
                                "duo: canonical template '$desiredTemplate' cannot be realized by stylesheet "
                                . "'$desiredStylesheet': WordPress resolved '$actualTemplate'. Check this theme's Template "
                                . 'header and state/options/core.json; refusing to write template directly because that would '
                                . 'bypass WordPress theme lifecycle.'
                            );
                        }
                    }
                }
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

    /** @return array{hash:string,document:array<string,mixed>} */
    private static function options_snapshot(
        string $repo,
        Policy $policy,
        CompiledRepository $compiled,
        bool $forceUnresolvedRefs
    ): array {
        $snapshot = Capture::snapshot_options_core($repo, $forceUnresolvedRefs, $compiled, $policy);
        $row = $snapshot['options/core'] ?? null;
        $hash = is_array($row) ? (string) ($row['hash'] ?? '') : '';
        $content = is_array($row) ? (string) ($row['content'] ?? '') : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $hash) || $content === '') {
            throw new \RuntimeException('duo: lifecycle state handoff could not snapshot canonical options/core');
        }
        try {
            $document = Canon::decode($content);
            if (!is_array($document)) {
                throw new \RuntimeException('snapshot document is not an object');
            }
            OptionState::records($document);
            $desired = $compiled->tree()['options/core']['data'] ?? null;
            if (is_array($desired)) {
                $document = self::bind_lifecycle_missing_options($document, $desired);
                $content = Canon::encode($document);
                $hash = hash('sha256', $content);
            }
        } catch (\Throwable $t) {
            throw new \RuntimeException(
                'duo: lifecycle state handoff captured malformed canonical options/core',
                0,
                $t
            );
        }
        return ['hash' => $hash, 'document' => $document];
    }

    /**
     * Bind a lifecycle snapshot's missing portable projection to the frozen
     * desired record. This is intentionally local to the deploy handoff: an
     * ordinary canonical state=absent remains non-authoritative, and this
     * transient document is never consumed as apply intent.
     *
     * Exact authored options already arrive as bound tombstones from
     * Capture::build_options(). Sub-key and dynamic-name options cannot use
     * a durable whole-row deletion tombstone (Duo owns only part of their
     * value), so their missing live projection arrives as state=absent. A
     * theme switch is the concrete case: before switch_theme(), the dynamic
     * theme_mods resolver names the old theme; afterward it names the frozen
     * desired theme. Converting only desired-present/missing observations to
     * a hash-bound transient marker gives the record gate the same proof
     * without granting deletion authority or reviving the unsafe generic
     * absent-to-present exception.
     */
    private static function bind_lifecycle_missing_options(array $observedDocument, array $desiredDocument): array {
        $observed = OptionState::records($observedDocument);
        foreach (OptionState::records($desiredDocument) as $name => $desiredRecord) {
            $observedRecord = $observed[$name] ?? null;
            if (($desiredRecord['state'] ?? null) === 'present'
                && ($observedRecord === null || ($observedRecord['state'] ?? null) === 'absent')) {
                // The immutable desired document is already available to the
                // record gate, so this transient marker needs only its hash.
                // Omitting a classification witness also makes a ref-bearing
                // post-hook projection that remains unresolved byte-identical
                // to the pre-hook bound tombstone instead of manufacturing a
                // false lifecycle change from witness metadata alone.
                $observed[$name] = OptionState::deleted($desiredRecord);
            }
        }
        return OptionState::document($observed);
    }

    /**
     * A whole-entity hash handoff may cover lifecycle-managed records,
     * authored records whose post-hook value is exactly the frozen desired
     * value, or a missing authored record whose pre-hook deleted tombstone is
     * cryptographically bound to that desired present record. Otherwise
     * apply could mistake an unrelated hook migration for expected lifecycle
     * progress and overwrite it with stale repository data. Return every
     * unsafe name so deploy can stop before state apply.
     *
     * @return list<string>
     */
    private static function unexpected_lifecycle_state_changes(
        array $beforeDocument,
        array $afterDocument,
        array $desiredDocument
    ): array {
        $before = OptionState::records($beforeDocument);
        $after = OptionState::records($afterDocument);
        $desired = OptionState::records($desiredDocument);
        $managed = array_fill_keys(['active_plugins', 'template', 'stylesheet'], true);
        $names = array_unique(array_merge(array_keys($before), array_keys($after)));
        sort($names, SORT_STRING);
        $unexpected = [];
        foreach ($names as $name) {
            $beforeRecord = $before[$name] ?? null;
            $afterRecord = $after[$name] ?? null;
            if (Canon::encode($beforeRecord) === Canon::encode($afterRecord)
                || isset($managed[$name])) {
                continue;
            }
            $desiredRecord = $desired[$name] ?? null;
            // On a first activation Capture::build_options(previous desired)
            // represents a missing exact authored row as a deleted tombstone
            // whose expected_hash is the hash of the frozen desired present
            // record. The hook may then create an ordinary default (present),
            // or a ref-bearing row may be omitted as absent until its identity
            // is minted. Only that cryptographic proof authorizes Apply to
            // reconcile the hook result; a plain state=absent record, a stale
            // expected_hash, or absent/deleted desired intent stays blocked.
            $beforeWasBoundMissing = is_array($beforeRecord)
                && ($beforeRecord['state'] ?? null) === 'deleted'
                && is_array($desiredRecord)
                && ($desiredRecord['state'] ?? null) === 'present'
                && is_array($afterRecord)
                && in_array(($afterRecord['state'] ?? null), ['present', 'absent'], true)
                && hash_equals(
                    (string) $beforeRecord['expected_hash'],
                    OptionState::record_hash($desiredRecord)
                );
            if ($beforeWasBoundMissing) {
                continue;
            }
            if (is_array($afterRecord) && is_array($desiredRecord)
                && ($desiredRecord['state'] ?? null) !== 'absent'
                && Canon::encode($afterRecord) === Canon::encode($desiredRecord)) {
                continue;
            }
            $unexpected[] = (string) $name;
        }
        return $unexpected;
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

    /**
     * Read Requires Plugins and produce a provider-first order for additions.
     * WordPress validates a plugin's requirements at activation time, so this
     * order is independent of the desired active_plugins storage order.
     *
     * @param list<string> $plugins active plugin basenames being activated
     * @return list<string>
     */
    private static function dependency_ordered_activations(array $plugins): array {
        return self::order_activations($plugins, self::plugin_dependency_requirements($plugins));
    }

    /**
     * Read WordPress's bounded Requires Plugins headers and produce a reverse
     * dependency order for the exact removal set.
     *
     * @param list<string> $plugins active plugin basenames being retired
     * @return list<string>
     */
    private static function dependency_ordered_deactivations(array $plugins): array {
        return self::order_deactivations($plugins, self::plugin_dependency_requirements($plugins));
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
        $nodes = array_fill_keys($plugins, true);
        $indegree = array_fill_keys($plugins, 0);
        $edges = array_fill_keys($plugins, []);
        foreach ($plugins as $plugin) {
            foreach ($requirements[$plugin] ?? [] as $provider) {
                if (!isset($nodes[$provider]) || isset($edges[$plugin][$provider])) {
                    continue;
                }
                $edges[$plugin][$provider] = true;
                $indegree[$provider]++;
            }
        }

        $priority = array_reverse($plugins);
        $ordered = [];
        while (count($ordered) < count($plugins)) {
            $next = null;
            foreach ($priority as $candidate) {
                if (isset($nodes[$candidate]) && $indegree[$candidate] === 0) {
                    $next = $candidate;
                    break;
                }
            }
            if ($next === null) {
                $cycle = array_keys($nodes);
                sort($cycle, SORT_STRING);
                throw new \RuntimeException(
                    'duo: plugin dependency cycle prevents safe teardown: ' . implode(', ', $cycle)
                );
            }
            $ordered[] = $next;
            unset($nodes[$next]);
            foreach (array_keys($edges[$next]) as $provider) {
                $indegree[$provider]--;
            }
        }
        return $ordered;
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
        $nodes = array_fill_keys($plugins, true);
        $indegree = array_fill_keys($plugins, 0);
        $edges = array_fill_keys($plugins, []);
        foreach ($plugins as $plugin) {
            foreach ($requirements[$plugin] ?? [] as $provider) {
                if (!isset($nodes[$provider]) || isset($edges[$provider][$plugin])) {
                    continue;
                }
                $edges[$provider][$plugin] = true;
                $indegree[$plugin]++;
            }
        }

        $ordered = [];
        while (count($ordered) < count($plugins)) {
            $next = null;
            foreach ($plugins as $candidate) {
                if (isset($nodes[$candidate]) && $indegree[$candidate] === 0) {
                    $next = $candidate;
                    break;
                }
            }
            if ($next === null) {
                $cycle = array_keys($nodes);
                sort($cycle, SORT_STRING);
                throw new \RuntimeException(
                    'duo: plugin dependency cycle prevents safe activation: ' . implode(', ', $cycle)
                );
            }
            $ordered[] = $next;
            unset($nodes[$next]);
            foreach (array_keys($edges[$next]) as $dependent) {
                $indegree[$dependent]--;
            }
        }
        return $ordered;
    }

    /** @return string[] */
    private static function current_active_plugins(): array {
        $raw = get_option('active_plugins');
        return is_array($raw) ? array_values(array_map('strval', $raw)) : [];
    }

    private static function in_range(string $installed, string $min, string $max): bool {
        return version_compare($installed, $min, '>=') && version_compare($installed, $max, '<');
    }

    /**
     * DUO-3222: theme twin of the plugin version_range check in
     * code_mismatch()'s loop above — same shape (in_range() against a
     * Policy::theme_ranges() row, same outside_version_range row), called
     * for each theme slot (stylesheet, and template when it differs) once
     * that slot's existence is already confirmed by the caller — mirrors
     * the plugin loop's own missing_in_code -> continue -> range-check
     * order: a theme that doesn't exist has no version to read.
     * wp_get_theme($slug)->get('Version') is an already-proven read path:
     * code_drift() below uses the identical call to track theme version
     * drift, just against a different baseline (last-observed vs. this
     * method's declared-range).
     *
     * @param array<string, array{min:string,max:string,manifest:string}> $ranges Policy::theme_ranges()
     * @param list<array{issue:string, kind:string, plugin?:string, theme?:string, message:string, installed_version?:string, version_range?:array, manifest?:string}> &$rows appended to in place
     */
    private static function check_theme_range(string $slug, array $ranges, array &$rows): void {
        if (!isset($ranges[$slug])) {
            return;
        }
        $r = $ranges[$slug];
        $installed = (string) wp_get_theme($slug)->get('Version');
        if ($installed !== '' && self::in_range($installed, $r['min'], $r['max'])) {
            return;
        }
        $rows[] = [
            'issue' => 'outside_version_range',
            'kind' => 'theme',
            'theme' => $slug,
            'installed_version' => $installed,
            'version_range' => ['min' => $r['min'], 'max' => $r['max']],
            'manifest' => $r['manifest'],
            'message' => "$slug " . ($installed !== '' ? $installed : '(unknown version)')
                . " is active in this environment, outside the '{$r['manifest']}' manifest's declared "
                . "theme_version_range (>={$r['min']} <{$r['max']}, pinned by site.duo.json). Classification "
                . 'guarantees for this theme are NOT validated against this version — apply may silently '
                . 'misclassify fields. Update the theme, pin an older manifest, or pass --force-code-mismatch '
                . 'to proceed at your own risk.',
        ];
    }

    /**
     * validate_plugin()/activate_plugin()/deactivate_plugins()/get_plugins()
     * all live in wp-admin/includes/plugin.php, not loaded by default
     * outside wp-admin — same require-on-demand pattern Apply::rebuild()
     * already uses for wp-admin/includes/{image,file,media}.php before
     * calling wp_generate_attachment_metadata().
     */
    private static function require_plugin_admin_functions(): void {
        if (!function_exists('validate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }
}
