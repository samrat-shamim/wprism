<?php
/**
 * Offline regression — issue #3507: `wprism capture` OBSERVES the code-version
 * baseline, it never accepts one.
 *
 * The defect this pins: CapturePublicationWorkflow called
 * Deploy::record_code_versions() unconditionally inside the capture
 * transaction, and that writer overwrites wprism_kv['code_versions'] with no
 * drift check (LifecyclePlanner.php:record_code_versions). code_drift()
 * reads exactly that key, so a plain capture across an out-of-band plugin
 * change erased the finding from every later `wprism status`/`wprism plan` — with
 * no consent gate and no output at all, while deploy reaches the identical
 * writer only after refusing (Deploy.php:203-210) or being forced past it
 * with --force-code-drift, warning once per overridden row
 * (Deploy.php:221-224), and the drift row's own remedy text names 'wprism
 * deploy' as the accept path and never names capture.
 *
 * So this file asserts both halves and their asymmetry:
 *   1. LifecyclePlanner::observe_code_versions() writes when there is
 *      nothing to accept (no baseline yet, or zero drift) and leaves the
 *      recorded blob BYTE-identical when there is, handing the rows back.
 *   2. record_code_versions() still clobbers across drift — deploy's
 *      unconditional re-baseline must stay unconditional, so a shared skip
 *      would have been the wrong fix.
 *   3. The capture call site takes (1), not (2), and reports every row.
 *
 * Real Ledger over the shared FakeWpdb, so "byte-identical" is asserted
 * against the value that actually round-trips through wprism_kv rather than
 * against a hand-held copy.
 */
declare(strict_types=1);

namespace {
    /** wp_get_theme()'s WP_Theme, narrowed to the one field the planner reads. */
    final class WPrismCaptureBaselineTheme {
        public function __construct(private string $slug) {}
        public function get(string $field): string {
            if ($field !== 'Version') {
                return '';
            }
            return (string) ($GLOBALS['wprism_capture_baseline_themes'][$this->slug] ?? '');
        }
    }

    // The live plugin/theme surfaces sandbox/tests/lib/wp_stubs.php
    // deliberately does not model (it owns options, not wp-admin's plugin
    // API). Same shape regress_template_mismatch.php uses, and defining
    // validate_plugin() is what keeps Deploy::require_plugin_admin_functions()
    // from require'ing ABSPATH . 'wp-admin/includes/plugin.php'.
    $GLOBALS['wprism_capture_baseline_plugins'] = [];
    $GLOBALS['wprism_capture_baseline_themes'] = [];

    function get_plugins(): array { return $GLOBALS['wprism_capture_baseline_plugins']; }
    function validate_plugin(string $plugin) { return null; }
    function wp_get_theme(string $slug): WPrismCaptureBaselineTheme { return new WPrismCaptureBaselineTheme($slug); }
}

namespace WPrism {
    use WPrismTest\FakeWpdb;
    use WPrismTest\WpStore;

    require_once __DIR__ . '/../../lib/check.php';
    require_once __DIR__ . '/../../lib/wp_stubs.php';
    require_once __DIR__ . '/../../lib/FakeWpdb.php';

    // The publication workflow is the call site under test; requiring it
    // also loads Deploy/LifecyclePlanner/Ledger/Policy through the same
    // require_once chain a real load takes, so nothing here is reachable
    // that agent/wprism.php would not also reach.
    require_once __DIR__ . '/../../../../agent/src/Capture/CapturePublicationWorkflow.php';

    $workflowSource = file_get_contents(__DIR__ . '/../../../../agent/src/Capture/CapturePublicationWorkflow.php');
    wprism_check(is_string($workflowSource), 'CapturePublicationWorkflow.php is readable');
    $workflowSource = (string) $workflowSource;

    // Neither code_drift() nor record_code_versions() reads $policy — both
    // take it only to keep every planner entry point one shape (verified by
    // reading the bodies, not assumed). An unconstructed instance is
    // therefore the honest fixture: a frozen envelope would assert the
    // envelope, not this behaviour.
    $policy = (new \ReflectionClass(Policy::class))->newInstanceWithoutConstructor();

    /**
     * One environment: which plugins are active, what versions are installed
     * on disk, and which themes are live. Returns the fake $wpdb so the
     * caller can read wprism_kv rows straight out of the store.
     */
    $environment = static function (array $active, array $installed, array $themes, array $themeVersions): FakeWpdb {
        WpStore::reset()->seedOptions([
            'active_plugins' => $active,
            'stylesheet' => $themes['stylesheet'] ?? '',
            'template' => $themes['template'] ?? '',
        ]);
        $GLOBALS['wprism_capture_baseline_plugins'] = array_map(
            static fn(string $version): array => ['Version' => $version],
            $installed
        );
        $GLOBALS['wprism_capture_baseline_themes'] = $themeVersions;
        $wpdb = FakeWpdb::install();
        // wprism_kv is (k, v) with k unique — Ledger::kv_set() is an INSERT ...
        // ON DUPLICATE KEY UPDATE, which FakeWpdb refuses to interpret
        // without the declared unique key.
        $wpdb->setColumns('wp_wprism_kv', ['k' => 'varchar(191)', 'v' => 'longtext']);
        $wpdb->setUniqueKey('wp_wprism_kv', ['k']);
        return $wpdb;
    };

    // The key is private to the planner; read it rather than re-spelling it,
    // so a rename cannot leave this suite green against the wrong row.
    $codeVersionsKey = (string) (new \ReflectionClass(LifecyclePlanner::class))->getConstant('CODE_VERSIONS_KEY');
    wprism_check_same('code_versions', $codeVersionsKey, 'the baseline still lives in wprism_kv under code_versions');
    $recorded = static fn(): ?string => Ledger::kv_get($codeVersionsKey);

    // === 1. No baseline yet: nothing to accept, so capture records one. ===
    $environment(['hello/hello.php'], ['hello/hello.php' => '1.7.2'], ['stylesheet' => 'twentytwo', 'template' => 'twentytwo'], ['twentytwo' => '2.0']);
    wprism_check_same(null, $recorded(), 'fixture starts with no recorded code_versions at all');
    wprism_check_same([], LifecyclePlanner::observe_code_versions($policy), 'a first capture has nothing to accept, so it reports nothing');
    $first = (string) $recorded();
    wprism_check_json_equal(
        ['plugins' => ['hello/hello.php' => '1.7.2'], 'stylesheet' => 'twentytwo', 'stylesheet_version' => '2.0', 'template' => 'twentytwo', 'template_version' => '2.0'],
        json_decode($first, true),
        'a first capture writes the live versions as the baseline'
    );

    // === 2. Baseline == live: still nothing to accept, still writes. ===
    wprism_check_same([], LifecyclePlanner::observe_code_versions($policy), 'zero drift reports nothing');
    wprism_check_same($first, (string) $recorded(), 'zero drift leaves the same recorded bytes');

    // === 3. Drift: the finding is returned and the blob is NOT moved. ===
    // The out-of-band change is on the installed side (a wp-admin/host
    // auto-update), which is the real-world shape; the recorded baseline
    // below is whatever the capture in case 1 wrote.
    $GLOBALS['wprism_capture_baseline_plugins']['hello/hello.php'] = ['Version' => '1.7.3'];
    $drift = LifecyclePlanner::observe_code_versions($policy);
    wprism_check_same(1, count($drift), 'an unaccepted plugin drift is reported as exactly one row');
    wprism_check_same('code_drift', $drift[0]['issue'] ?? null, 'the returned row is a code_drift finding');
    wprism_check_same('hello/hello.php', $drift[0]['plugin'] ?? null, 'the row names the drifted plugin');
    wprism_check_same('1.7.3', $drift[0]['installed_version'] ?? null, 'the row names the installed version');
    wprism_check_same('1.7.2', $drift[0]['recorded_version'] ?? null, 'the row names the recorded version');
    wprism_check_same($first, (string) $recorded(), 'issue #3507: capture across an unaccepted drift leaves wprism_kv[code_versions] byte-identical');

    // The finding therefore survives to the next `wprism status`/`wprism plan`,
    // which is the whole point: before this fix the second observation
    // found nothing left to report.
    wprism_check_same(
        1,
        count(LifecyclePlanner::code_drift($policy, ['active_plugins' => ['hello/hello.php']])),
        'the drift is still visible to a later code_drift() read'
    );

    // === 4. Deploy's writer is untouched: it still clobbers across drift. ===
    // This is the asymmetry the fix depends on. `wprism deploy
    // --force-code-drift` re-baselines unconditionally at Deploy.php:472,
    // after its own refuse-or-force gate, so a shared skip inside
    // record_code_versions() would have silently broken that accept path.
    LifecyclePlanner::record_code_versions($policy);
    wprism_check_same(
        '1.7.3',
        json_decode((string) $recorded(), true)['plugins']['hello/hello.php'] ?? null,
        'record_code_versions() still overwrites across drift — deploy keeps its unconditional re-baseline'
    );
    wprism_check_same([], LifecyclePlanner::code_drift($policy, ['active_plugins' => ['hello/hello.php']]), 'that re-baseline clears the finding, exactly as a forced deploy must');

    // === 5. Theme drift freezes the baseline the same way. ===
    $environment(
        ['hello/hello.php'],
        ['hello/hello.php' => '1.7.2'],
        ['stylesheet' => 'child', 'template' => 'parent'],
        ['child' => '1.0', 'parent' => '1.0']
    );
    wprism_check_same([], LifecyclePlanner::observe_code_versions($policy), 'theme fixture starts clean');
    $themeBaseline = (string) $recorded();
    $GLOBALS['wprism_capture_baseline_themes']['child'] = '1.1';
    $themeDrift = LifecyclePlanner::observe_code_versions($policy);
    wprism_check_same(1, count($themeDrift), 'an unaccepted theme drift is reported too');
    wprism_check_same('theme', $themeDrift[0]['kind'] ?? null, 'the theme row is typed as a theme finding');
    wprism_check_same('child', $themeDrift[0]['theme'] ?? null, 'the theme row names the drifted stylesheet');
    wprism_check_same($themeBaseline, (string) $recorded(), 'a theme drift freezes the recorded blob as well');

    // === 6. The freeze is not sticky: restoring the recorded version writes again. ===
    // "restore $baseline" is one of the two remedies the row's own message
    // names, so it has to actually work without a deploy.
    $GLOBALS['wprism_capture_baseline_themes']['child'] = '1.0';
    wprism_check_same([], LifecyclePlanner::observe_code_versions($policy), 'restoring the recorded version clears the finding');
    wprism_check_same($themeBaseline, (string) $recorded(), 'and the next capture records the restored state');

    // === 7. A plugin missing from an existing baseline blocks capture's
    // observer from silently minting the first out-of-band activation. ===
    WpStore::reset()->seedOptions([
        'active_plugins' => ['hello/hello.php', 'newly/activated.php'],
        'stylesheet' => 'child',
        'template' => 'parent',
    ]);
    $GLOBALS['wprism_capture_baseline_plugins']['newly/activated.php'] = ['Version' => '9.9'];
    $missingBaseline = LifecyclePlanner::observe_code_versions($policy);
    wprism_check_same(1, count($missingBaseline), 'a plugin activated since the last baseline is one visible finding');
    wprism_check_same('code_baseline_missing', $missingBaseline[0]['issue'] ?? null, 'the finding distinguishes absence from a version mismatch');
    wprism_check_same('newly/activated.php', $missingBaseline[0]['plugin'] ?? null, 'the missing baseline names the newly active plugin');
    wprism_check_same(
        null,
        json_decode((string) $recorded(), true)['plugins']['newly/activated.php'] ?? null,
        'capture leaves the baseline frozen instead of accepting the unreviewed activation'
    );
    LifecyclePlanner::record_code_versions($policy);
    wprism_check_same('9.9', json_decode((string) $recorded(), true)['plugins']['newly/activated.php'] ?? null, 'deploy\'s explicit writer can accept the activation');

    // === 8. The capture call site takes the observing writer, not the
    // unconditional one, and reports every row it gets back. ===
    wprism_check(
        str_contains($workflowSource, "require_once __DIR__ . '/../Promotion/LifecyclePlanner.php';"),
        'the workflow requires its own new dependency (AGENTS.md rule 1 — the drop-in has no autoloader)'
    );
    wprism_check(
        !str_contains($workflowSource, 'Deploy::record_code_versions('),
        'issue #3507: capture no longer calls the unconditional writer at all'
    );
    wprism_check(
        str_contains($workflowSource, 'foreach (LifecyclePlanner::observe_code_versions($c->policy()) as $observed) {')
            && str_contains($workflowSource, "\$candidate['warnings'][] = (string) \$observed['message'] . self::CODE_DRIFT_OBSERVED;"),
        'capture appends one warning per unaccepted row to the summary warnings WP_CLI::warning() renders'
    );
    // Still inside the unscoped branch: a scoped capture publishes a bounded
    // overlay and never touched the code baseline, before or after this fix.
    $unscoped = strpos($workflowSource, 'if (!$scoped) {' . "\n" . '                        Ledger::prune_state(');
    wprism_check(
        $unscoped !== false
            && strpos($workflowSource, 'LifecyclePlanner::observe_code_versions(') > $unscoped
            && strpos($workflowSource, 'LifecyclePlanner::observe_code_versions(') < strpos($workflowSource, 'Publish::mark_commit_ready(', $unscoped),
        'the observation stays inside the unscoped branch, before the commit-ready marker'
    );

    $suffix = (new \ReflectionClass(CapturePublicationWorkflow::class))->getConstant('CODE_DRIFT_OBSERVED');
    wprism_check(is_string($suffix) && $suffix !== '', 'the warning suffix is a declared constant, not an inline literal');
    $suffix = (string) $suffix;
    wprism_check(
        str_contains($suffix, 'did NOT accept it as the new baseline'),
        'the warning says capture did not accept the drift'
    );
    wprism_check(
        str_contains($suffix, "still there on the next 'wprism status'"),
        'the warning says the finding survives, which is what makes it actionable later'
    );
    // Composed through the real row, so the operator-visible line is pinned
    // end to end: the row's own three remedies, then what capture did.
    $warning = (string) $themeDrift[0]['message'] . $suffix;
    wprism_check(
        str_contains($warning, "Re-run 'wprism deploy' to accept")
            && str_contains($warning, '--force-code-drift')
            && str_contains($warning, "did NOT accept it as the new baseline"),
        'the composed warning carries the row remedies AND the observe-not-accept statement'
    );

    wprism_check_summary('capture observes the code baseline and never accepts it (issue #3507)');
}
