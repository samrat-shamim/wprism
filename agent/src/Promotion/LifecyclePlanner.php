<?php
namespace Duo;

require_once __DIR__ . '/Deploy.php';
// Circular with Deploy.php (which requires this file too) — safe: PHP marks
// a require_once path included the instant its own require begins, so by
// the time Deploy.php's own require_once of this file executes, this file
// is already mid-load and the second require becomes a no-op. Verified
// empirically (php -l and the offline suite both load cleanly either
// direction), the same pattern ActionProviderGrammar.php/
// CrossManifestGuards.php/SubKeyGrammar.php already established with
// Policy.php.

/**
 * Detects what a lifecycle phase needs to reconcile (DUO-3350 slice 6, the
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
 * (DUO-3350 slice 8, the "LifecycleExecutor" half of this same seam,
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
 * `duo plan`/`duo status`'s own code_mismatch/code_drift plan buckets
 * (verified by reading Apply.php's own call sites, not assumed), and
 * Capture::run() calls record_code_versions() directly to record a
 * "last known good" baseline after every successful capture, not only after
 * deploy. Deploy keeps thin compatibility facades over all four public
 * entry points, matching every prior slice in this issue, so none of those
 * three external call sites (or Deploy::run()'s own five internal ones)
 * need any change.
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
 * sandbox/tests/regress_manifest_validate.sh's static WordPress-reach
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
 * than introduce a new one" precedent DUO-3348 slice 5's PinResolver.php
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
                            . "is installed, but it is not active in this environment. Run 'duo deploy <env>' "
                            . 'before apply so activation hooks and schema migrations complete first.',
                    ];
                }
                if (isset($ranges[$plugin])) {
                    $r = $ranges[$plugin];
                    $installed = (string) ($allPlugins[$plugin]['Version'] ?? '');
                    if ($installed === '' || !Deploy::in_range($installed, $r['min'], $r['max'])) {
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
            Deploy::require_plugin_admin_functions();
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
                . "theme_version_range (>={$r['min']} <{$r['max']}, pinned by site.duo.json). Classification "
                . 'guarantees for this theme are NOT validated against this version — apply may silently '
                . 'misclassify fields. Update the theme, pin an older manifest, or pass --force-code-mismatch '
                . 'to proceed at your own risk.',
        ];
    }
}
