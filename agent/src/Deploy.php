<?php
namespace Duo;

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
 * Phase 1 scope (no code/ materialization transport exists yet — that's
 * cli/'s orchestrator territory, §2.2, out of this engine task's reach):
 * "in code" means "installed in this environment's wp-content/", not
 * "present in a bind-mounted code/ tree" — the sandbox spike (task #40)
 * is what makes those the same directory. This class never writes
 * duo_kv['code_revision']: that marker belongs to the materialization step
 * (§2.2 — "before writing the new code revision into the ledger"), which
 * phase 1 doesn't implement; writing a marker with no corresponding
 * materialize-and-verify step would make a future code_revision_stale
 * check lie about what it's supposed to mean (see code_mismatch()'s
 * docblock for the same point applied to that check specifically).
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
     * for template/stylesheet's theme directories (both parent and child,
     * when they differ — a child theme's switch_theme() needs its parent
     * present too).
     *
     * Shared by Deploy::run() (this verb's own refuse-precondition) and
     * Apply::build_plan()'s code_mismatch bucket (surfaced at ordinary
     * `duo plan`/`duo status` time, not just at deploy time) — one
     * implementation, so the two can never disagree about what "in code"
     * means. `code_revision_stale` (§3.2 point 3 — comparing the repo's
     * code/composer.lock hash against duo_kv['code_revision']) is
     * deliberately NOT implemented here: phase 1 has no materialization
     * step to populate either side of that comparison (no code/
     * composer.lock is ever resolved, and nothing ever writes
     * duo_kv['code_revision']), so there is nothing yet to compare —
     * documented as phase 2 in the final report, not silently dropped.
     *
     * @param array $desired {
     *   active_plugins?: string[] plugin basenames the target state declares active (null = not captured/declared, skip plugin checks entirely),
     *   template?: string parent/standalone theme directory (null = not declared, skip theme checks),
     *   stylesheet?: string active theme directory (null = not declared, skip theme checks),
     * }
     * @return list<array{issue:string, kind:string, plugin?:string, theme?:string, message:string, installed_version?:string, version_range?:array, manifest?:string}>
     */
    public static function code_mismatch(Policy $policy, array $desired): array {
        $rows = [];
        self::require_plugin_admin_functions();

        $desiredActive = $desired['active_plugins'] ?? null;
        if ($desiredActive !== null) {
            $allPlugins = get_plugins();
            $ranges = $policy->version_ranges();
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
        }

        $desiredStylesheet = $desired['stylesheet'] ?? null;
        $desiredTemplate = $desired['template'] ?? null;
        if ($desiredStylesheet !== null && !wp_get_theme($desiredStylesheet)->exists()) {
            $rows[] = [
                'issue' => 'missing_in_code',
                'kind' => 'theme',
                'theme' => $desiredStylesheet,
                'message' => "stylesheet in state/options/core.json declares '$desiredStylesheet' but "
                    . "$desiredStylesheet does not exist in this environment (checked against this environment's "
                    . "wp-content/themes/). Install/vendor the theme here, or this branch's code/ changes "
                    . "haven't reached this environment yet.",
            ];
        }
        if ($desiredTemplate !== null && $desiredTemplate !== $desiredStylesheet && !wp_get_theme($desiredTemplate)->exists()) {
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
        }
        return $rows;
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
     * second run against an already-reconciled environment computes empty
     * $toActivate/$toDeactivate/theme-diff and calls zero WP APIs, so no
     * hook re-fires and the run is a genuine no-op, not just a no-visible-
     * effect one.
     *
     * @return array{activated:string[], deactivated:string[], theme_switched:?string, code_mismatch:array, warnings:string[]}
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
        $desired = isset($tree['options/core'])
            ? self::extract_desired($tree['options/core']['data'])
            : [];

        $mismatch = self::code_mismatch($policy, $desired);
        if ($mismatch && empty($opts['force_code_mismatch'])) {
            $list = implode("\n\n", array_map(fn($r) => '  - ' . $r['message'], $mismatch));
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
        if ($drift && empty($opts['force_code_drift'])) {
            $list = implode("\n\n", array_map(fn($r) => '  - ' . $r['message'], $drift));
            throw new \RuntimeException(
                "duo: deploy refused — code_drift:\n\n$list\n\n"
                . 'Reconcile the environment to a known version first, or pass --force-code-drift to proceed anyway.'
            );
        }

        self::require_plugin_admin_functions();
        // Architecture Rulings §1 (report-not-hide): reaching this line with
        // $drift non-empty is only possible via --force-code-drift (the
        // gate above already threw otherwise) — the overridden findings
        // still need to surface in BOTH human and machine output, not just
        // the machine-readable 'code_drift' key of the final return (which
        // human-mode `wp duo deploy` never separately renders — see
        // Cli::deploy()). Seeding $warnings here, unconditionally for every
        // forced-through finding, is what makes them show up as WP_CLI::
        // warning() lines in ordinary (non --format=json) deploy output.
        $warnings = array_map(
            fn($r) => 'FORCED past code_drift: ' . $r['message'],
            $drift
        );
        $activated = [];
        $deactivated = [];
        $themeSwitched = null;
        // Findings that survived the refuse-gate above (only possible when
        // --force-code-mismatch was passed) still name real absences —
        // calling activate_plugin()/switch_theme() on something with no
        // file on disk would just throw a PHP include warning/fatal, so
        // forced-through missing entries are skipped from the actual work,
        // not attempted. outside_version_range findings are NOT skipped —
        // that issue means "present but a version this manifest doesn't
        // vouch for," never "absent," so activation still makes sense.
        $missingPlugins = array_column(
            array_filter($mismatch, fn($r) => $r['kind'] === 'plugin' && $r['issue'] === 'missing_in_code'),
            'plugin'
        );
        $themeMissing = (bool) array_filter($mismatch, fn($r) => $r['kind'] === 'theme');

        $desiredActive = $desired['active_plugins'] ?? null;
        if ($desiredActive !== null) {
            $current = self::current_active_plugins();
            $toActivate = array_values(array_diff($desiredActive, $current));
            $toDeactivate = array_values(array_diff($current, $desiredActive));

            foreach ($toActivate as $plugin) {
                if (in_array($plugin, $missingPlugins, true)) {
                    $warnings[] = "skipped activating '$plugin' (missing_in_code, --force-code-mismatch was set)";
                    continue;
                }
                $result = activate_plugin($plugin); // hooks fire deliberately — this is the point of this class
                if (is_wp_error($result)) {
                    $warnings[] = "activating '$plugin' failed: " . $result->get_error_message();
                    continue;
                }
                $activated[] = $plugin;
            }
            if ($toDeactivate) {
                // Symmetric with activation, matching §3.3's own failure-mode
                // wording for the inverse case ("this environment will do so
                // automatically on the next 'duo deploy'") — deactivation is
                // not deferred to a human step in this proposal, apply's
                // own hook-free posture just means IT can never be the one
                // to do it. Hooks fire deliberately here too.
                deactivate_plugins($toDeactivate);
                $deactivated = $toDeactivate;
            }
        }

        $desiredStylesheet = $desired['stylesheet'] ?? null;
        $desiredTemplate = $desired['template'] ?? null;
        if ($desiredStylesheet !== null && get_option('stylesheet') !== $desiredStylesheet) {
            if ($themeMissing) {
                $warnings[] = "skipped theme switch to '$desiredStylesheet' (missing_in_code, --force-code-mismatch was set)";
            } else {
                switch_theme($desiredStylesheet); // hooks fire deliberately (switch_theme/after_switch_theme)
                $themeSwitched = $desiredStylesheet;
                if ($desiredTemplate !== null && get_option('template') !== $desiredTemplate) {
                    $warnings[] = "after switch_theme('$desiredStylesheet'), this environment's 'template' option "
                        . "is '" . get_option('template') . "' but state/options/core.json declares '$desiredTemplate' "
                        . "— the theme's own parent-theme header disagrees with captured state";
                }
            }
        }

        // Re-baseline unconditionally: whatever's active NOW (post-
        // reconciliation, whether clean or forced-through) becomes the new
        // "last known good" — the same versions a --force-code-drift run
        // just accepted are exactly what should stop being flagged on the
        // NEXT run. Runs regardless of whether $drift/$mismatch fired, same
        // as code_mismatch's own findings don't gate whether reconciliation
        // proceeds once past the refuse-gate above.
        self::record_code_versions($policy);

        return [
            'artifact' => [
                'hash' => $compiled->artifact_hash(),
                'revision' => $compiled->revision_hash(),
                'manifests' => $compiled->manifest_hash(),
            ],
            'activated' => $activated,
            'deactivated' => $deactivated,
            'theme_switched' => $themeSwitched,
            'code_mismatch' => $mismatch,
            'code_drift' => $drift,
            'warnings' => $warnings,
        ];
    }

    /**
     * Pulls active_plugins/template/stylesheet out of an already-decoded
     * options/core.json map. Shared with Apply::build_plan(), which already
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

    /** @return string[] */
    private static function current_active_plugins(): array {
        $raw = get_option('active_plugins');
        return is_array($raw) ? array_values(array_map('strval', $raw)) : [];
    }

    private static function in_range(string $installed, string $min, string $max): bool {
        return version_compare($installed, $min, '>=') && version_compare($installed, $max, '<');
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
