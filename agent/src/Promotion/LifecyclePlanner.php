<?php
namespace WPrism;

if (!class_exists(Deploy::class, false)) {
    require_once __DIR__ . '/Deploy.php';
}
// WP-2.8: the site-declared per-release probe evidence the graduated
// outside_version_range verdict reads. A leaf grammar file that requires
// nothing of its own, so unlike the Code.php require this class deliberately
// avoids (see below) it widens no static-scan closure.
require_once __DIR__ . '/../Policy/VersionEvidenceGrammar.php';
// Circular with Deploy.php (which requires this file too) — safe: PHP marks
// a require_once path included the instant its own require begins, so by
// the time Deploy.php's own require_once of this file executes, this file
// is already mid-load and the second require becomes a no-op. Verified
// empirically (php -l and the offline suite both load cleanly either
// direction), the same pattern ActionProviderGrammar.php/
// CrossManifestGuards.php/SubKeyGrammar.php already established with
// Policy.php.

/**
 * Detects what a lifecycle phase needs to reconcile (issue #3350 slice 6, the
 * "LifecyclePlanner" half of the "LifecyclePlanner / LifecycleExecutor"
 * target seam, extracted from Deploy): compares desired active_plugins/
 * template/stylesheet against live WordPress facts (code_mismatch),
 * compares the compiled code descriptor's revision against Code's own
 * payload-completion proof (code_revision_mismatch), compares live installed
 * versions against the last-recorded baseline (code_drift), and writes that
 * baseline (record_code_versions). Every method here only DETECTS and
 * REPORTS findings or reads/writes the drift baseline -- none calls
 * activate_plugin()/deactivate_plugins()/switch_theme() or otherwise fires a
 * WordPress lifecycle hook. That is LifecycleExecutor::execute()'s territory
 * (issue #3350 slice 8, the "LifecycleExecutor" half of this same seam,
 * extracted from Deploy::run() afterward): at the time this class was cut,
 * unlike every other cluster this issue had cut so far, run() was a single
 * ~500-line method with promotion-lock/canary/state-handoff/hook-firing
 * concerns tightly interleaved in a very specific required order, and this
 * issue's own guardrail ("preserve ... lifecycle order, failure recovery")
 * made splitting it apart a materially larger, riskier undertaking than
 * this narrower, purely-detective cut -- deliberately deferred rather than
 * rushed at the time, later cut on its own in slice 8.
 *
 * This cluster is a genuinely shared, externally-consumed API, not just
 * Deploy::run()'s own internal orchestration: Apply::build_plan() calls
 * code_mismatch()/code_revision_mismatch()/code_drift() directly for
 * `wprism plan`/`wprism status`'s own code_mismatch/code_drift plan buckets
 * (verified by reading Apply.php's own call sites, not assumed), and
 * CapturePublicationWorkflow calls observe_code_versions() to record a
 * "last known good" baseline only when capture sees no drift; it never accepts
 * an unreviewed code change. Deploy keeps thin compatibility facades over the
 * original four public entry points, matching every prior slice in this issue.
 *
 * require_plugin_admin_functions()/current_active_plugins()/in_range() stay
 * on Deploy (the first two widened private -> public) rather than moving
 * here, for two DIFFERENT reasons, not one: require_plugin_admin_functions()/
 * current_active_plugins() are used by Deploy::run()/plugin_runtime_state()
 * too (verified by grep, not assumed -- neither is exclusive to this moved
 * cluster), while in_range() has no caller in run()/plugin_runtime_state()
 * at all (its only internal callers were always inside this moved cluster)
 * but was ALREADY public before this slice, with real external callers of
 * its own beyond Deploy entirely (agent/src/Adapter/Providers.php) -- moving it
 * would have meant re-exporting it from a second place for no reason.
 * check_theme_range() moves here instead -- its only caller anywhere in the
 * repo is code_mismatch() itself.
 *
 * sandbox/tests/offline/policy/regress_manifest_validate.sh's static WordPress-reach
 * scanner keys its allowlist on exact "file.php:function_name" pairs; its
 * four entries for the methods moved here were updated from "Deploy.php:..."
 * to "LifecyclePlanner.php:..." in the same commit as this move -- a stale
 * or missing entry there fails loud (STALE allowlist entry / UNGUARDED
 * reach), so this was not optional bookkeeping.
 *
 * Deliberately does NOT require_once Code.php, even though
 * code_revision_mismatch() calls Code::completed_code_mismatch()/
 * Code::CODE_REVISION_KEY: Deploy.php never required it either (a
 * pre-existing regress_agent_src_requires.php baseline gap this move
 * simply inherits unchanged, the same "preserve a pre-existing gap rather
 * than introduce a new one" precedent issue #3348 slice 5's PinResolver.php
 * already established). A real require_once here was tried first and
 * reverted: Code.php's own require chain reaches CodeStageTransaction.php,
 * which requires Ledger.php -- newly pulling Ledger.php into
 * regress_manifest_validate.sh's static-scan closure (via
 * Deploy.php -> LifecyclePlanner.php -> Code.php -> CodeStageTransaction.php)
 * for the first time and failing loud on dozens of that file's own
 * unrelated, previously-unscanned WordPress reaches -- a real, caught
 * consequence of adding this one require, not a hypothetical.
 */
final class LifecyclePlanner {
    /** wprism_kv key for code_drift()'s baseline — see record_code_versions(). */
    private const CODE_VERSIONS_KEY = 'code_versions';

    /** @var list<string> Findings the host lifecycle phases can reconcile. */
    private const LIFECYCLE_ISSUES = [
        'active_plugin_order_mismatch',
        'inactive_in_environment',
        'template_mismatch',
        'unexpected_active_plugin',
    ];

    public static function is_lifecycle_issue(string $issue): bool {
        return in_array($issue, self::LIFECYCLE_ISSUES, true);
    }

    /**
     * Read-only host preflight for state-only as well as code-bearing repos.
     * It shares code_mismatch() with deploy/apply so the host never guesses
     * whether activation, deactivation, ordering, or a theme switch is due.
     *
     * @return array{format:string,required:bool,reasons:list<string>}
     */
    public static function deployment_status(
        Policy $policy,
        CompiledRepository $compiled,
        bool $forceCodeMismatch = false
    ): array {
        $tree = $compiled->tree();
        $desired = isset($tree['options/core'])
            ? Deploy::extract_desired((array) ($tree['options/core']['data'] ?? []))
            : [];
        $mismatch = self::code_mismatch($policy, $desired);
        $blockers = array_values(array_filter(
            $mismatch,
            static fn(array $row): bool => !self::is_lifecycle_issue((string) ($row['issue'] ?? ''))
                && ($row['issue'] ?? null) !== VersionEvidenceGrammar::VERDICT
        ));
        if ($blockers !== [] && !$forceCodeMismatch) {
            $list = implode("\n\n", array_map(
                static fn(array $row): string => '  - ' . (string) ($row['message'] ?? 'unknown code mismatch'),
                $blockers
            ));
            throw new \RuntimeException(
                "wprism: deploy refused — code_mismatch:\n\n$list\n\n"
                . 'Install/vendor whatever is missing (or update code/) in this environment first, '
                . 'or pass --force-code-mismatch to proceed anyway.'
            );
        }
        $reasons = [];
        foreach ($mismatch as $row) {
            $issue = (string) ($row['issue'] ?? '');
            if (self::is_lifecycle_issue($issue) && !in_array($issue, $reasons, true)) {
                $reasons[] = $issue;
            }
        }
        sort($reasons, SORT_STRING);
        return [
            'format' => 'wprism-lifecycle-status/v1',
            'required' => $reasons !== [],
            'reasons' => $reasons,
        ];
    }

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
     * `wprism plan`/`wprism status` time, not just at deploy time) — one
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
     * @return list<array{issue:string, kind:string, plugin?:string, theme?:string, message:string, installed_version?:string, version_range?:array, manifest?:string}> `version_range` carries a plugin's `version_range` or (issue #3222) a theme's `theme_version_range` uniformly — one shared key regardless of `kind`, matching how both are consumed identically by Apply::build_plan()'s code_mismatch bucket
     */
    public static function code_mismatch(Policy $policy, array $desired): array {
        $rows = [];
        Deploy::require_plugin_admin_functions();

        $desiredActive = $desired['active_plugins'] ?? null;
        if ($desiredActive !== null) {
            $allPlugins = get_plugins();
            $ranges = $policy->version_ranges();
            $currentActive = Deploy::current_active_plugins();
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
                            . "is installed, but it is not active in this environment. Run 'wprism deploy <env>' "
                            . 'before apply so activation hooks and schema migrations complete first.',
                    ];
                }
                if (isset($ranges[$plugin])) {
                    $r = $ranges[$plugin];
                    $installed = (string) ($allPlugins[$plugin]['Version'] ?? '');
                    if ($installed === '' || !Deploy::in_range($installed, $r['min'], $r['max'])) {
                        // WP-2.8's third state. graduated_version_range()
                        // returns rows only when this site has RECORDED probe
                        // evidence covering every release between the declared
                        // window and these exact bytes; null — the absent, the
                        // partial, and the contradicted case alike — falls
                        // through to the refusal below with its message
                        // unchanged to the byte (rule 8).
                        $graduated = self::graduated_version_range($policy, $plugin, $r, $installed);
                        if ($graduated !== null) {
                            $rows[] = self::graduated_row($plugin, $r, $installed, $graduated);
                            continue;
                        }
                        $rows[] = [
                            'issue' => 'outside_version_range',
                            'kind' => 'plugin',
                            'plugin' => $plugin,
                            'installed_version' => $installed,
                            'version_range' => ['min' => $r['min'], 'max' => $r['max']],
                            'manifest' => $r['manifest'],
                            'message' => "$plugin " . ($installed !== '' ? $installed : '(unknown version)')
                                . " is active in this environment, outside the '{$r['manifest']}' manifest's "
                                . "declared version_range (>={$r['min']} <{$r['max']}, pinned by site.wprism.json). "
                                . 'Classification guarantees for this plugin are NOT validated against this '
                                . 'version — apply may silently misclassify fields. Update the plugin, pin an '
                                . 'older manifest, or pass --force-code-mismatch to proceed at your own risk.',
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
                        . "active_plugins. Run 'wprism deploy <env>' before apply so its deactivation hooks complete first.",
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
                        . "Run 'wprism deploy <env>' before apply so WordPress loads plugins in the declared order.",
                    'desired_order' => $desiredActive,
                    'environment_order' => $currentActive,
                ];
            }
        }

        // issue #3222: themes now get the SAME version-range treatment plugins
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
                            . "' active. Run 'wprism deploy <env>' before apply so the theme lifecycle completes first.",
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
                        . 'switch_theme() needs its parent present too. Install/vendor the parent theme here, or '
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
                    . (string) get_option('template') . "'. Run 'wprism deploy <env>' so WordPress can reconcile "
                    . 'the theme through switch_theme(); WPrism will refuse if this stylesheet cannot resolve to the '
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
                'wprism: code payload proof implementation is unavailable; refusing to trust code_revision'
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
                . "Run 'wprism deploy <env>' so WPrism can stage, reconcile, verify, and finalize this exact code payload before apply.",
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
            throw new \RuntimeException('wprism: compiled code descriptor is malformed or unsupported by this agent');
        }
        $revision = $compiled->code_revision();
        if (!is_string($revision) || $revision === '') {
            throw new \RuntimeException('wprism: compiled code descriptor has no revision');
        }
        return $revision;
    }

    /**
     * issue #3231 (docs/code-half.md's risk register #1, "wp-admin/
     * filesystem-initiated updates are silent code drift, and detection
     * alone is not a fix"): a SEPARATE question from code_mismatch() above.
     * code_mismatch asks "is what's installed compatible with what the
     * manifests/repo say is acceptable" (missing entirely, or outside a
     * pinned version_range — a wide band). code_drift asks "did this
     * specific plugin/theme's version change since the last time WPrism
     * itself reconciled or observed this environment" — a narrower,
     * provenance question a version_range can't answer: a wp-admin
     * one-click update from 7.2.0 to 7.5.0 can land comfortably inside an
     * ">=7.0 <9.0" range and stay invisible to code_mismatch entirely,
     * while still being exactly the out-of-band mutation risk #1 names.
     * The baseline this compares against is written by
     * record_code_versions() below, reached after terminal lifecycle
     * reconciliation by `wprism deploy` and through observe_code_versions()
     * by a drift-free `wprism capture`. No baseline anywhere is the one
     * bootstrap case with nothing to compare. Once a baseline exists, a
     * currently active plugin absent from it is itself drift evidence:
     * silently minting it would make the first out-of-band activation or
     * update invisible. An inactive desired plugin is instead pending work
     * for deploy's lifecycle executor.
     *
     * Scoped to exactly the entities $desired already names (the same
     * active_plugins/template/stylesheet the target state declares,
     * identical scope to code_mismatch() above) — WPrism has no opinion on
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
            Deploy::require_plugin_admin_functions();
            $allPlugins = get_plugins();
            $currentActive = Deploy::current_active_plugins();
            $recordedPlugins = (array) ($recorded['plugins'] ?? []);
            foreach ($desiredActive as $plugin) {
                $plugin = (string) $plugin;
                if (!array_key_exists($plugin, $recordedPlugins)) {
                    // Deploy::run() checks drift before LifecycleExecutor activates
                    // desired code. Only current activation can be out-of-band
                    // evidence; an inactive desired plugin is the lifecycle work
                    // this same locked deploy is authorized to perform.
                    if (!in_array($plugin, $currentActive, true)) {
                        continue;
                    }
                    $installed = (string) ($allPlugins[$plugin]['Version'] ?? '');
                    $rows[] = [
                        'issue' => 'code_baseline_missing',
                        'kind' => 'plugin',
                        'plugin' => $plugin,
                        'installed_version' => $installed,
                        'recorded_version' => '',
                        'message' => "$plugin " . ($installed === '' ? '(unknown version)' : $installed)
                            . ' is active on this environment but absent from the existing WPrism code-version baseline. '
                            . 'Its activation or first version change therefore cannot be distinguished from an '
                            . "out-of-band update. Run 'wprism deploy' to reconcile and record the installed bytes, or "
                            . 'remove the undeclared activation before capture/apply.',
                    ];
                    continue;
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
                    'message' => "$plugin is $installed on this environment, but the last successful 'wprism deploy' "
                        . "or 'wprism capture' recorded $baseline — its code changed here outside WPrism's own "
                        . 'reconciliation (a wp-admin/host auto-update is the common cause; see DISALLOW_FILE_MODS '
                        . "in 'wp wprism doctor'). Re-run 'wprism deploy' to accept $installed as the new baseline, "
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
                    . "'wprism deploy' or 'wprism capture' recorded $baseline — its code changed here outside WPrism's own "
                    . "reconciliation. Re-run 'wprism deploy' to accept $installed as the new baseline, restore "
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
     * function needs nothing passed in beyond $policy, keeping its call
     * sites to one line each.
     *
     * issue #3507: this is the UNCONDITIONAL writer, and Deploy::run() is the
     * only caller entitled to use it that way. By the time terminal deploy
     * re-baselines it has already refused on drift ("wprism: deploy refused —
     * code_drift") or been explicitly forced past it while reporting every
     * overridden row — the consent gate already happened, so this write is
     * that decision's consequence rather than the decision itself. A split
     * retire pass is not terminal and does not call this writer. Capture has
     * no such gate and goes through observe_code_versions() below instead.
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
        Deploy::require_plugin_admin_functions();
        $versions = ['plugins' => []];
        foreach (Deploy::current_active_plugins() as $plugin) {
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
     * Capture's baseline write: observe, never accept (issue #3507).
     *
     * `wprism capture` reads live state and publishes it; it does not
     * reconcile code, and it has no --force-code-drift consent gate the way
     * Deploy::run() does. Overwriting the baseline across an unaccepted
     * drift was therefore the one place a durable finding was erased by a
     * verb that never asked: code_drift() reads exactly the key
     * record_code_versions() writes (:369), so the re-baseline deleted
     * the evidence from every later `wprism status`/`wprism plan`, and the drift
     * row's own remedy text names 'wprism deploy' as the accept path
     * (:400-401, :429-430) and never names capture.
     *
     * So: nothing to accept — no baseline recorded yet, or zero drift —
     * writes exactly as before; anything to accept leaves the recorded blob
     * byte-identical and hands the rows back for the caller to report. The
     * scope handed to code_drift() is this environment's own live
     * active_plugins/template/stylesheet rather than a repository's desired
     * set, because capture is answering "what did I just observe here". Once
     * any baseline exists, a newly active plugin is returned as
     * `code_baseline_missing` and this observer leaves the baseline frozen;
     * only deploy owns the reconciliation-and-acceptance path.
     *
     * @return list<array{issue:string, kind:string, plugin?:string, theme?:string, message:string, installed_version:string, recorded_version:string}> non-empty means the baseline was NOT moved
     */
    public static function observe_code_versions(Policy $policy): array {
        $drift = self::code_drift($policy, [
            'active_plugins' => Deploy::current_active_plugins(),
            'template' => (string) get_option('template'),
            'stylesheet' => (string) get_option('stylesheet'),
        ]);
        if ($drift !== []) {
            return $drift;
        }
        self::record_code_versions($policy);
        return [];
    }

    /**
     * WP-2.8: the recorded per-release evidence that graduates ONE
     * `outside_version_range` finding, or null.
     *
     * The verdict is a composition of facts this class already computes, not a
     * new judgement: `Deploy::in_range()` is the same window predicate the
     * finding above used and the same one provider negotiation uses, so
     * "outside the certified window" has exactly one definition here. What is
     * added is the interval walk — every RECORDED release lying outside that
     * window between it and the installed bytes, inclusive — and the demand
     * that each one probed green.
     *
     * Four separate refusals, each returning null so the pinned
     * `outside_version_range` message fires unchanged:
     *
     *   - an unreadable installed version. get_plugins() returned no Version
     *     header; there is nothing for evidence to be ABOUT, and the finding
     *     above already fires on that alone.
     *   - no entry for this plugin under this manifest (VersionEvidenceGrammar
     *     ::entry()). This is the rule-9 guard and the reason this mechanism is
     *     not a silent fallback: absence of evidence refuses.
     *   - the installed release is not in the recorded release list. The list
     *     is what makes an unprobed release detectable; bytes it never names
     *     are unevidenced, not benign.
     *   - any release in the interval that is unprobed, or probed non-green.
     *     `boot-fatal`/`round-trip-diverges` are a declared surface that DID
     *     move; `artifact-unresolved` is a fact about a download and is not
     *     evidence either way (AdapterBoundary.php:94-99). All three block, and
     *     so does silence — the window's interior is probed, never exhausted.
     *
     * @param array{min:string,max:string,manifest:string} $range
     * @return ?list<array{version:string,outcome:string,signature:string}> non-empty on graduation
     */
    private static function graduated_version_range(
        Policy $policy,
        string $plugin,
        array $range,
        string $installed
    ): ?array {
        if ($installed === '') {
            return null;
        }
        $entry = VersionEvidenceGrammar::entry(
            $policy->adapter_version_evidence(),
            $plugin,
            $range['manifest']
        );
        if ($entry === null || !in_array($installed, $entry['releases'], true)) {
            return null;
        }
        // Below min and above max are both "outside", and a host downgrade is
        // as real as a host auto-update, so the interval is taken toward the
        // window from whichever side the installed bytes sit on.
        $below = version_compare($installed, $range['min'], '<');
        $rows = [];
        foreach ($entry['releases'] as $version) {
            if (Deploy::in_range($version, $range['min'], $range['max'])) {
                continue; // inside the declared window: already vouched for by the manifest
            }
            $inInterval = $below
                ? version_compare($version, $installed, '>=') && version_compare($version, $range['min'], '<')
                : version_compare($version, $installed, '<=') && version_compare($version, $range['max'], '>=');
            if (!$inInterval) {
                continue;
            }
            $row = $entry['outcomes'][$version] ?? null;
            if ($row === null || $row['outcome'] !== VersionEvidenceGrammar::OUTCOME_GREEN) {
                return null;
            }
            $rows[] = $row;
        }
        // Unreachable while the installed release is outside the window and in
        // the recorded list — it is its own interval member. Kept because a
        // graduation carrying no evidence rows would be precisely the silent
        // pass this verdict exists to not be.
        return $rows === [] ? null : $rows;
    }

    /**
     * The graduated finding. Deliberately a DISTINCT issue name rather than a
     * suppressed one: Deploy and Apply both stop refusing on it, so it has to
     * remain visible in `--format=json`, in the plan's code_mismatch bucket,
     * and as its own reported line on every run. The message carries the
     * per-release evidence verbatim, including each probe's recorded
     * signature, and states the limit of what that evidence proves.
     *
     * @param array{min:string,max:string,manifest:string} $range
     * @param list<array{version:string,outcome:string,signature:string}> $evidence
     * @return array<string,mixed>
     */
    private static function graduated_row(string $plugin, array $range, string $installed, array $evidence): array {
        $named = implode('; ', array_map(
            static fn(array $row): string => "{$row['version']} {$row['outcome']} ({$row['signature']})",
            $evidence
        ));
        return [
            'issue' => VersionEvidenceGrammar::VERDICT,
            'kind' => 'plugin',
            'plugin' => $plugin,
            'installed_version' => $installed,
            'version_range' => ['min' => $range['min'], 'max' => $range['max']],
            'manifest' => $range['manifest'],
            'evidence' => $evidence,
            'message' => "$plugin $installed is active in this environment, outside the '{$range['manifest']}' "
                . "manifest's declared version_range (>={$range['min']} <{$range['max']}, pinned by "
                . 'site.wprism.json), and is NOT blocked: every release this site recorded between that window and '
                . "these bytes probed green under this adapter's own declared surfaces — $named. That is "
                . 'evidence about exactly those releases and nothing else: a release with no recorded probe '
                . 'blocks, and so does one that boot-fataled, diverged on recapture, or could not be resolved. '
                . "This verdict does not widen the manifest's range, which stays a reviewed human edit.",
        ];
    }

    /** @param array<string,array{min:string,max:string,manifest:string}> $ranges @param list<array<string,mixed>> $rows */
    private static function check_theme_range(string $slug, array $ranges, array &$rows): void {
        if (!isset($ranges[$slug])) {
            return;
        }
        $r = $ranges[$slug];
        $installed = (string) wp_get_theme($slug)->get('Version');
        if ($installed !== '' && Deploy::in_range($installed, $r['min'], $r['max'])) {
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
                . "theme_version_range (>={$r['min']} <{$r['max']}, pinned by site.wprism.json). Classification "
                . 'guarantees for this theme are NOT validated against this version — apply may silently '
                . 'misclassify fields. Update the theme, pin an older manifest, or pass --force-code-mismatch '
                . 'to proceed at your own risk.',
        ];
    }
}
