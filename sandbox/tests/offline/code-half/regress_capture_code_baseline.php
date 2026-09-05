<?php
/**
 * Offline product proof for exact code-baseline observation and publication.
 *
 * The baseline is an authority record, not a WordPress cache projection: all
 * writers must lock the raw lifecycle rows and ledger keys in one proven
 * transaction, sample headers directly, and roll back both baseline and
 * receipt when the final sample changes.
 */
declare(strict_types=1);

namespace {
    final class WPrismCodeBaselineWakeupBomb {
        public function __wakeup(): void {
            ++$GLOBALS['wprism_code_baseline_wakeups'];
        }
    }

    // These deliberately fail if the product regresses to cached/admin APIs.
    function get_plugins(): array {
        throw new \RuntimeException('get_plugins cache API must not author a code baseline');
    }
    function get_file_data(): array {
        throw new \RuntimeException('get_file_data cache API must not author a code baseline');
    }
    function wp_get_theme(): object {
        throw new \RuntimeException('wp_get_theme cache API must not author a code baseline');
    }
}

namespace WPrism {
    use WPrismTest\FakeWpdb;
    use WPrismTest\WpStore;

    require_once __DIR__ . '/../../lib/check.php';
    require_once __DIR__ . '/../../lib/wp_stubs.php';
    require_once __DIR__ . '/../../lib/FakeWpdb.php';
    // Select one complete runtime tree for counterfactual execution; mixing
    // two physical dependency roots would redeclare the same Kernel classes.
    $runtimeRoot = $argv[1] ?? dirname(__DIR__, 4);
    require_once $runtimeRoot . '/agent/src/Promotion/CodeBaselineAcceptance.php';
    require_once $runtimeRoot . '/agent/src/Promotion/CodeBaselineCapture.php';
    require_once $runtimeRoot . '/agent/src/Promotion/CodeBaselinePublication.php';

    $pluginSlug = 'wprism-baseline-' . getmypid();
    $plugin = $pluginSlug . '/plugin.php';
    $theme = 'wprism-theme-' . getmypid();
    $pluginPath = rtrim(WP_PLUGIN_DIR, '/\\') . '/' . $plugin;
    $themePath = rtrim(WP_CONTENT_DIR, '/\\') . '/themes/' . $theme . '/style.css';
    $extraThemeRoot = sys_get_temp_dir() . '/wprism-baseline-extra-' . getmypid();
    $desired = [
        'active_plugins' => [$plugin],
        'stylesheet' => $theme,
        'template' => $theme,
    ];

    $writePlugin = static function (string $version, bool $name = true) use ($pluginPath): void {
        if (!is_dir(dirname($pluginPath))) {
            mkdir(dirname($pluginPath), 0777, true);
        }
        $header = "<?php\n";
        if ($name) {
            $header .= "/*\nPlugin Name: WPrism Baseline Fixture\n";
        } else {
            $header .= "/*\n";
        }
        if ($version !== '') {
            $header .= "Version: $version\n";
        }
        file_put_contents($pluginPath, $header . "*/\n");
        clearstatcache(true, $pluginPath);
    };
    $writeTheme = static function (string $version, ?string $root = null) use ($theme, $themePath): string {
        $path = $root === null
            ? $themePath
            : rtrim($root, '/\\') . '/' . $theme . '/style.css';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, "/*\nTheme Name: WPrism Baseline Theme\nVersion: $version\n*/\n");
        clearstatcache(true, $path);
        return $path;
    };
    $baseline = static function (string $pluginVersion, string $themeVersion) use ($plugin, $theme): string {
        return (string) wp_json_encode([
            'plugins' => [$plugin => $pluginVersion],
            'stylesheet' => $theme,
            'stylesheet_version' => $themeVersion,
            'template' => $theme,
            'template_version' => $themeVersion,
        ]);
    };
    $index = static fn(string $name, string $column): array => [[
        'Key_name' => $name,
        'Non_unique' => 0,
        'Seq_in_index' => 1,
        'Column_name' => $column,
        'Sub_part' => null,
        'Index_type' => 'BTREE',
        'Visible' => 'YES',
        'Ignored' => 'NO',
    ]];

    /** @return FakeWpdb */
    $environment = static function (
        string $installed = '1.7.2',
        string $themeVersion = '2.0',
        ?string $recorded = null,
        ?string $receipt = null,
        ?string $activeRaw = null,
        bool $withTemplate = true,
        string $optionsEngine = 'InnoDB',
        bool $withOptionIndex = true,
        bool $withLedger = true
    ) use ($plugin, $theme, $writePlugin, $writeTheme, $index): FakeWpdb {
        try {
            ProcessFence::release();
        } catch (\Throwable) {
            // The previous fixture may not have installed a database yet.
        }
        Db::forget_transaction_tracking();
        $writePlugin($installed);
        $writeTheme($themeVersion);
        $GLOBALS['wp_theme_directories'] = [];
        $GLOBALS['wprism_code_baseline_wakeups'] = 0;
        WpStore::reset()->seedOptions([
            // Contradictory cache values prove the product reads raw SQL.
            'active_plugins' => ['cache/cache.php'],
            'stylesheet' => 'cache-theme',
            'template' => 'cache-theme',
        ]);

        $optionRows = [
            [
                'option_id' => 1,
                'option_name' => 'active_plugins',
                'option_value' => $activeRaw ?? serialize([$plugin]),
                'autoload' => 'yes',
            ],
            ['option_id' => 2, 'option_name' => 'stylesheet', 'option_value' => $theme, 'autoload' => 'yes'],
        ];
        if ($withTemplate) {
            $optionRows[] = ['option_id' => 3, 'option_name' => 'template', 'option_value' => $theme, 'autoload' => 'yes'];
        }
        $kvRows = [];
        if ($receipt !== null) {
            $kvRows[] = ['k' => CodeBaselineTransaction::RECEIPT_KEY, 'v' => $receipt];
        }
        if ($recorded !== null) {
            $kvRows[] = ['k' => CodeBaselineTransaction::BASELINE_KEY, 'v' => $recorded];
        }

        $wpdb = FakeWpdb::install();
        $wpdb->setColumns('wp_options', [
            'option_id' => 'bigint unsigned',
            'option_name' => 'varchar(191)',
            'option_value' => 'longtext',
            'autoload' => 'varchar(20)',
        ])->seedTable('wp_options', $optionRows)
            ->setPrimaryKey('wp_options', 'option_id')
            ->setUniqueKey('wp_options', ['option_name'])
            ->setIndexes('wp_options', $withOptionIndex ? $index('option_name', 'option_name') : [])
            ->setTableEngine('wp_options', $optionsEngine);
        if ($withLedger) {
            $wpdb->setColumns('wp_wprism_kv', ['k' => 'varchar(191)', 'v' => 'longtext'])
                ->seedTable('wp_wprism_kv', $kvRows)
                ->setUniqueKey('wp_wprism_kv', ['k'])
                ->setIndexes('wp_wprism_kv', $index('PRIMARY', 'k'))
                ->setTableEngine('wp_wprism_kv', 'InnoDB');
        }
        $wpdb->enableInformationSchema();
        ProcessFence::acquire();
        return $wpdb;
    };

    $kv = static function (FakeWpdb $wpdb, string $key): ?string {
        foreach ($wpdb->rows('wp_wprism_kv') as $row) {
            if (($row['k'] ?? null) === $key) {
                return is_string($row['v'] ?? null) ? $row['v'] : null;
            }
        }
        return null;
    };
    $failure = static function (callable $operation): ?\Throwable {
        try {
            $operation();
        } catch (\Throwable $caught) {
            return $caught;
        }
        return null;
    };
    $capture = static function () use ($desired): array {
        $open = false;
        try {
            global $wpdb;
            $ledgerTable = $wpdb->prefix . 'wprism_kv';
            Db::start_repeatable_read(
                'baseline regression capture start',
                new NativeDatabaseProfile([$wpdb->options, $ledgerTable], [$ledgerTable])
            );
            $open = true;
            $rows = CodeBaselineCapture::observe_or_publish($desired);
            Db::commit('baseline regression capture commit');
            $open = false;
            return $rows;
        } catch (\Throwable $caught) {
            if ($open) {
                Db::rollback_after_failure($caught, 'baseline regression capture rollback');
            }
            throw $caught;
        }
    };

    $policy = new Policy();
    $compiled = CompiledRepository::create([
        'tree' => ['options/core' => [
            'data' => OptionState::document([
                'active_plugins' => OptionState::present([$plugin], 'yes'),
                'stylesheet' => OptionState::present($theme, 'yes'),
                'template' => OptionState::present($theme, 'yes'),
            ]),
        ]],
    ]);

    // A virgin deploy asks for lifecycle status before any writer provisions
    // the ledger. Absence of the whole store is explicit evidence of an absent
    // baseline, not permission to query a table that is not there.
    $wpdb = $environment(withLedger: false);
    $wpdb->resetLog();
    $virginStatus = LifecyclePlanner::deployment_status($policy, $compiled);
    wprism_check_same('wprism-lifecycle-status/v2', $virginStatus['format'] ?? null, 'virgin target emits the reviewed lifecycle-status wire');
    wprism_check_same('absent', $virginStatus['baseline_state'] ?? null, 'virgin lifecycle status reports an explicitly absent baseline');
    wprism_check_same([], $virginStatus['code_drift'] ?? null, 'virgin lifecycle status reports no recorded drift');
    $virginQueries = $wpdb->queries();
    wprism_check_same(
        1,
        count(array_filter(
            $virginQueries,
            static fn(string $sql): bool => $sql === 'SELECT 1 FROM `wp_wprism_kv` LIMIT 0'
        )),
        'virgin lifecycle observation probes the exact ledger-table identity once'
    );
    wprism_check(
        !array_filter($virginQueries, static fn(string $sql): bool => str_contains($sql, 'SELECT k, v FROM wp_wprism_kv')),
        'virgin lifecycle status never reads rows from the absent ledger table'
    );
    wprism_check(
        (bool) array_filter($virginQueries, static fn(string $sql): bool => $sql === 'SELECT 1 FROM `wp_wprism_kv` LIMIT 0')
            && (bool) array_filter($virginQueries, static fn(string $sql): bool => $sql === 'SHOW WARNINGS'),
        'virgin lifecycle status accepts absence only from the exact 1146 server diagnostic'
    );
    wprism_check_same([], $wpdb->ddlLog(), 'virgin lifecycle observation provisions or repairs no schema');

    $wpdb = $environment(withLedger: false);
    $wpdb->failNextQuery('simulated ledger presence failure', 'SELECT 1 FROM `wp_wprism_kv`', 1);
    $presenceFailure = $failure(static fn() => LifecyclePlanner::deployment_status($policy, $compiled));
    wprism_check(
        $presenceFailure instanceof \RuntimeException
            && str_contains($presenceFailure->getMessage(), 'key/value table presence lookup'),
        'an unreadable ledger-presence fact is refused rather than reported as absent'
    );

    $wpdb = $environment();
    $wpdb->failNextQuery('SELECT command denied', 'SELECT 1 FROM `wp_wprism_kv`', 1);
    $restrictedFailure = $failure(static fn() => LifecyclePlanner::deployment_status($policy, $compiled));
    wprism_check(
        $restrictedFailure instanceof \RuntimeException
            && str_contains($restrictedFailure->getMessage(), 'key/value table presence lookup'),
        'an unreadable existing ledger is refused rather than reported as absent'
    );

    $wpdb = $environment();
    $wpdb->setTemporaryTableEngine('wp_wprism_kv', 'InnoDB');
    $shadowFailure = $failure(static fn() => LifecyclePlanner::deployment_status($policy, $compiled));
    wprism_check(
        $shadowFailure instanceof \RuntimeException
            && str_contains($shadowFailure->getMessage(), 'key/value table presence lookup'),
        'a session-local table shadow cannot borrow the physical ledger identity'
    );

    $wpdb = $environment();
    $wpdb->failNextQuery('simulated ledger resolution race', 'SHOW CREATE TABLE', 1);
    $resolutionFailure = $failure(static fn() => LifecyclePlanner::deployment_status($policy, $compiled));
    wprism_check(
        $resolutionFailure instanceof \RuntimeException
            && str_contains($resolutionFailure->getMessage(), 'key/value table presence lookup'),
        'a ledger that becomes unreadable between census and session resolution is refused'
    );

    $wpdb = $environment();
    $wpdb->failNextQuery('simulated ledger row failure', 'SELECT k, v FROM', 1);
    $rowFailure = $failure(static fn() => LifecyclePlanner::deployment_status($policy, $compiled));
    wprism_check(
        $rowFailure instanceof \RuntimeException
            && str_contains($rowFailure->getMessage(), 'key/value lookup'),
        'an existing but unreadable ledger is refused rather than reported as baseline-absent'
    );

    $wpdb = $environment(recorded: $baseline('1.7.1', '2.0'));
    $establishedStatus = LifecyclePlanner::deployment_status($policy, $compiled);
    wprism_check_same('drift', $establishedStatus['baseline_state'] ?? null, 'established lifecycle status consumes the durable baseline');
    wprism_check_same([[
        'issue' => 'code_drift',
        'kind' => 'plugin',
        'plugin' => $plugin,
        'installed_version' => '1.7.2',
        'recorded_version' => '1.7.1',
        'message' => "$plugin is 1.7.2 on this environment, but the last successful 'wprism deploy' "
            . "or 'wprism capture' recorded 1.7.1 — its code changed here outside WPrism's own "
            . 'reconciliation (a wp-admin/host auto-update is the common cause; see DISALLOW_FILE_MODS '
            . "in 'wp wprism doctor'). Re-run 'wprism deploy' to accept 1.7.2 as the new baseline, "
            . 'restore 1.7.1, or pass --force-code-drift to proceed at your own risk.',
    ]], $establishedStatus['code_drift'] ?? null, 'established lifecycle status projects the exact stored-version drift row');

    // Advisory observation is one raw SQL snapshot plus direct header reads.
    $wpdb = $environment();
    $observation = CodeLifecycleObservation::read_unlocked($desired, null);
    wprism_check_same([$plugin], $observation['active_plugins'], 'raw active_plugins beats contradictory WordPress cache state');
    wprism_check_same('1.7.2', $observation['plugins'][$plugin] ?? null, 'plugin version comes from the direct bounded header sample');
    wprism_check_same('2.0', $observation['themes'][$theme] ?? null, 'theme version comes from the direct bounded header sample');
    $snapshotReads = array_values(array_filter(
        $wpdb->queries(),
        static fn(string $sql): bool => str_contains($sql, 'bounded_option_value')
    ));
    wprism_check_same(1, count($snapshotReads), 'all unlocked lifecycle options come from one bounded SQL statement');

    // WP 7.1's actual deactivate_plugins() (plugin.php:758-850, function SHA256
    // 26c64db62537462b421897f26e91c8af10d2d1ea2d87064d2703667336284061)
    // leaves [1,2] / [0,2] after first/middle removal. Native isolated execution
    // and the combined 5142fef1 private refusal identify this storage postimage.
    // Reproduce those raw bytes here; do not repair them through update_option.
    $roster = [$plugin, $pluginSlug . '/second.php', $pluginSlug . '/third.php'];
    $compileLifecycle = static fn(array $active): CompiledRepository => CompiledRepository::create([
        'tree' => ['options/core' => ['data' => OptionState::document([
            'active_plugins' => OptionState::present($active, 'yes'),
            'stylesheet' => OptionState::present($theme, 'yes'),
            'template' => OptionState::present($theme, 'yes'),
        ])]],
    ]);
    foreach (['first', 'middle', 'last', 'all', 'dense', 'ordered-sparse'] as $case) {
        $active = $roster;
        if ($case === 'first') unset($active[0]);
        if ($case === 'middle') unset($active[1]);
        if ($case === 'last') unset($active[2]);
        if ($case === 'all') $active = [];
        if ($case === 'ordered-sparse') $active = [9 => $roster[2], 2 => $roster[0], PHP_INT_MAX => $roster[1]];
        $raw = serialize($active);
        $wpdb = $environment(activeRaw: $raw, receipt: 'retire-on-success');
        foreach (array_slice($roster, 1) as $nativePlugin) {
            file_put_contents(rtrim(WP_PLUGIN_DIR, '/\\') . '/' . $nativePlugin,
                "<?php\n/*\nPlugin Name: Native Lifecycle Fixture\nVersion: 1.7.2\n*/\n");
        }
        $nativeDesired = [...$desired, 'active_plugins' => array_values($active)];
        $beforeRows = $wpdb->rows('wp_options');
        $observed = null;
        $readFailure = $failure(static function () use ($nativeDesired, &$observed): void {
            $observed = CodeLifecycleObservation::read_unlocked($nativeDesired, null);
        });
        wprism_check($readFailure === null, "$case native deactivation storage is accepted without changing the WordPress row");
        if ($readFailure !== null) continue;
        wprism_check_same(array_values($active), $observed['active_plugins'], "$case observation preserves native iteration order, not numeric-key order");
        $status = LifecyclePlanner::deployment_status($policy, $compileLifecycle(array_values($active)));
        wprism_check_same(false, $status['required'], "$case exact native lifecycle does not invent activation work");
        wprism_check_same([], $status['warnings'], "$case lifecycle status is warning-free");
        wprism_check_same($beforeRows, $wpdb->rows('wp_options'), "$case advisory observation never reindexes native storage");
        if (in_array($case, ['first', 'middle', 'last', 'all'], true)) {
            $pending = LifecyclePlanner::deployment_status($policy, $compileLifecycle($roster));
            wprism_check($pending['required'] && in_array('inactive_in_environment', $pending['reasons'], true),
                "$case the public lifecycle planner identifies legitimate pending activation instead of malformed evidence");
            $unsettled = $failure(static fn() => CodeBaselinePublication::publish_terminal([...$desired, 'active_plugins' => $roster], null));
            wprism_check($unsettled instanceof \RuntimeException, "$case unsettled lifecycle still cannot publish a baseline");
            wprism_check_same(null, $kv($wpdb, CodeBaselineTransaction::BASELINE_KEY), "$case activation refusal creates no baseline authority");
        }
        $published = $failure(static fn() => CodeBaselinePublication::publish_terminal($nativeDesired, $status['code_boundary_sha256']));
        wprism_check($published === null, "$case the same native shape passes the real locked terminal writer");
        $publishedBytes = $kv($wpdb, CodeBaselineTransaction::BASELINE_KEY);
        $publishedBaseline = $publishedBytes === null ? [] : json_decode($publishedBytes, true, flags: JSON_THROW_ON_ERROR);
        wprism_check_same(array_fill_keys(array_values($active), '1.7.2'), $publishedBaseline['plugins'] ?? null,
            "$case terminal authority contains only the observed ordered plugin identities");
        wprism_check_same(null, $kv($wpdb, CodeBaselineTransaction::RECEIPT_KEY), "$case terminal publication removes only its stale receipt");
        wprism_check_same($beforeRows, $wpdb->rows('wp_options'), "$case locked observation/publication preserves every raw lifecycle byte");
    }
    // Canonical desired artifacts remain lists; only native storage positions
    // are projected away. Non-native keys and malformed payloads still refuse.
    $environment();
    wprism_check($failure(static fn() => CodeLifecycleObservation::read_unlocked(
        [...$desired, 'active_plugins' => [4 => $plugin]], null
    )) instanceof \RuntimeException, 'sparse canonical desired lifecycle declarations remain invalid');

    // First capture publishes the exact baseline and deletes a stale receipt.
    $wpdb = $environment(receipt: '{"stale":true}');
    $wpdb->resetLog();
    wprism_check_same([], $capture(), 'capture initializes an absent exact code baseline');
    wprism_check_same($baseline('1.7.2', '2.0'), $kv($wpdb, CodeBaselineTransaction::BASELINE_KEY), 'capture publishes exact direct-header facts');
    wprism_check_same(null, $kv($wpdb, CodeBaselineTransaction::RECEIPT_KEY), 'capture atomically removes stale acceptance authority');

    $queries = $wpdb->queries();
    $position = static function (array $queries, callable $match): ?int {
        foreach ($queries as $offset => $sql) {
            if ($match($sql)) {
                return $offset;
            }
        }
        return null;
    };
    $activeAt = $position($queries, static fn(string $sql): bool => str_contains($sql, 'OCTET_LENGTH(option_value)') && str_contains($sql, "option_name = 'active_plugins'"));
    $styleAt = $position($queries, static fn(string $sql): bool => str_contains($sql, 'OCTET_LENGTH(option_value)') && str_contains($sql, "option_name = 'stylesheet'"));
    $templateAt = $position($queries, static fn(string $sql): bool => str_contains($sql, 'OCTET_LENGTH(option_value)') && str_contains($sql, "option_name = 'template'"));
    $receiptAt = $position($queries, static fn(string $sql): bool => str_contains($sql, 'FORCE INDEX') && str_contains($sql, "k = 'code_baseline_acceptance'"));
    $baselineAt = $position($queries, static fn(string $sql): bool => str_contains($sql, 'FORCE INDEX') && str_contains($sql, "k = 'code_versions'"));
    wprism_check(
        is_int($activeAt) && is_int($styleAt) && is_int($templateAt)
            && is_int($receiptAt) && is_int($baselineAt)
            && $activeAt < $styleAt && $styleAt < $templateAt
            && $templateAt < $receiptAt && $receiptAt < $baselineAt,
        'shared writer lock order is lifecycle lexical then receipt/baseline lexical'
    );
    $touchAt = $position($queries, static fn(string $sql): bool => str_contains($sql, 'SELECT 1 FROM') && str_contains($sql, 'wp_options'));
    wprism_check(
        is_int($touchAt) && is_int($activeAt) && $touchAt < $activeAt,
        'metadata locks precede lifecycle row locks'
    );

    // Capture never consumes drift and never clears its receipt as a side effect.
    $old = $baseline('1.7.1', '2.0');
    $wpdb = $environment(recorded: $old, receipt: 'keep-me');
    $drift = $capture();
    wprism_check_same('code_drift', $drift[0]['issue'] ?? null, 'capture reports installed plugin drift');
    wprism_check_same($old, $kv($wpdb, CodeBaselineTransaction::BASELINE_KEY), 'capture leaves a drifted baseline byte-identical');
    wprism_check_same('keep-me', $kv($wpdb, CodeBaselineTransaction::RECEIPT_KEY), 'capture leaves receipt state untouched when it refuses publication');

    // Terminal lifecycle publication replaces both durable coordinates.
    $wpdb = $environment(recorded: $old, receipt: 'discard-me');
    $terminalObservation = CodeLifecycleObservation::read_unlocked($desired, $old);
    $terminal = LifecyclePlanner::code_baseline_publication_snapshot($desired, $terminalObservation);
    CodeBaselinePublication::publish_terminal($desired, $terminal['code_boundary_sha256']);
    wprism_check_same($baseline('1.7.2', '2.0'), $kv($wpdb, CodeBaselineTransaction::BASELINE_KEY), 'terminal publication accepts the reconciled exact code set');
    wprism_check_same(null, $kv($wpdb, CodeBaselineTransaction::RECEIPT_KEY), 'terminal publication deletes an obsolete acceptance receipt');

    $wpdb = $environment(recorded: $old, receipt: 'preserve-me');
    $wrongBoundary = str_repeat('0', 64);
    $boundaryFailure = $failure(static fn() => CodeBaselinePublication::publish_terminal($desired, $wrongBoundary));
    wprism_check($boundaryFailure instanceof \RuntimeException, 'terminal publication refuses a stale host code boundary');
    wprism_check_same($old, $kv($wpdb, CodeBaselineTransaction::BASELINE_KEY), 'boundary refusal rolls baseline bytes back');
    wprism_check_same('preserve-me', $kv($wpdb, CodeBaselineTransaction::RECEIPT_KEY), 'boundary refusal rolls receipt deletion back');

    // The isolated acceptance phase is receipt-bearing and exactly replayable.
    $wpdb = $environment();
    $accept = new \ReflectionMethod(CodeBaselineAcceptance::class, 'accept_or_replay');
    $absentObservation = CodeLifecycleObservation::read_unlocked($desired, null);
    $absentSnapshot = LifecyclePlanner::code_baseline_acceptance_snapshot($policy, $desired, false, $absentObservation);
    $artifact = hash('sha256', 'baseline-artifact');
    $operation = 'baseline-op-' . getmypid();
    $args = [$policy, $desired, false, false, 'absent', $absentSnapshot['observation_sha256'], $operation, $artifact];
    $accepted = $accept->invoke(null, ...$args);
    wprism_check_same('initialized', $accepted['outcome'] ?? null, 'absent acceptance initializes a receipt-bearing baseline');
    $replayed = $accept->invoke(null, ...$args);
    wprism_check_same(true, $replayed['replayed'] ?? null, 'response-loss retry replays the committed acceptance receipt');
    $writePlugin('1.7.3');
    $replayFailure = $failure(static fn() => $accept->invoke(null, ...$args));
    wprism_check($replayFailure instanceof \RuntimeException, 'receipt replay refuses changed direct-header facts');

    $wpdb = $environment(recorded: $old);
    $driftObservation = CodeLifecycleObservation::read_unlocked($desired, $old);
    $driftSnapshot = LifecyclePlanner::code_baseline_acceptance_snapshot($policy, $desired, false, $driftObservation);
    $driftArgs = [$policy, $desired, false, false, 'drift', $driftSnapshot['observation_sha256'], 'drift-op-' . getmypid(), $artifact];
    $unforced = $failure(static fn() => $accept->invoke(null, ...$driftArgs));
    wprism_check($unforced instanceof \RuntimeException && str_contains($unforced->getMessage(), '--force-code-drift'), 'drift acceptance requires explicit force authority');
    $driftArgs[2] = true;
    $forced = $accept->invoke(null, ...$driftArgs);
    wprism_check_same('accepted', $forced['outcome'] ?? null, 'forced drift acceptance publishes the observed exact bytes');

    // Filesystem facts cannot be DB-locked; paired sampling detects a change.
    $wpdb = $environment(recorded: $old, receipt: 'preserve-on-change');
    $mutated = false;
    $wpdb->onQuery(static function (string $sql) use (&$mutated, $writePlugin): ?string {
        if (!$mutated
            && str_starts_with(ltrim($sql), 'INSERT INTO')
            && str_contains($sql, 'wp_wprism_kv')) {
            $mutated = true;
            $writePlugin('1.7.4');
        }
        return null;
    });
    $changedFailure = $failure(static fn() => CodeBaselinePublication::publish_terminal($desired, null));
    $wpdb->onQuery(null);
    wprism_check($changedFailure instanceof \RuntimeException, 'paired header sampling refuses code changed during publication');
    wprism_check_same($old, $kv($wpdb, CodeBaselineTransaction::BASELINE_KEY), 'paired-sample refusal rolls baseline publication back');
    wprism_check_same('preserve-on-change', $kv($wpdb, CodeBaselineTransaction::RECEIPT_KEY), 'paired-sample refusal rolls receipt deletion back');

    // Raw row grammar is closed and never invokes object lifecycle methods.
    $serializedObject = serialize(new \WPrismCodeBaselineWakeupBomb());
    $environment(activeRaw: $serializedObject);
    $objectFailure = $failure(static fn() => CodeLifecycleObservation::read_unlocked($desired, null));
    wprism_check($objectFailure instanceof \RuntimeException, 'serialized objects are refused as lifecycle evidence');
    wprism_check_same(0, $GLOBALS['wprism_code_baseline_wakeups'], 'serialized-object refusal never invokes __wakeup');
    foreach ([
        serialize([$plugin, $plugin]) => 'duplicate active plugin identities',
        serialize(['../escape/plugin.php']) => 'unsafe active plugin identities',
        serialize([$plugin]) . "\n" => 'noncanonical trailing option bytes',
        serialize(['plugin' => $plugin]) => 'associative plugin storage keys',
        serialize(['01' => $plugin]) => 'noncanonical numeric-string plugin storage keys',
        serialize([-1 => $plugin]) => 'negative plugin storage positions',
        serialize([2 => $plugin, 8 => $plugin]) => 'duplicate sparse plugin identities',
        serialize([2 => '../escape/plugin.php']) => 'unsafe sparse plugin identities',
        serialize([2 => 17]) => 'non-string sparse plugin identities',
        serialize([2 => [$plugin]]) => 'nested sparse plugin values',
        serialize(array_combine(range(0, 8192, 2), array_map(static fn(int $i): string => "fixture$i/plugin.php", range(0, 4096)))) => 'oversized sparse plugin inventories',
    ] as $raw => $label) {
        $wpdb = $environment(activeRaw: $raw, receipt: 'keep-invalid-roster');
        $beforeRows = $wpdb->rows('wp_options');
        wprism_check($failure(static fn() => CodeLifecycleObservation::read_unlocked($desired, null)) instanceof \RuntimeException, "$label are refused");
        wprism_check($failure(static fn() => CodeBaselinePublication::publish_terminal($desired, null)) instanceof \RuntimeException,
            "$label also refuse the real locked writer");
        wprism_check_same($beforeRows, $wpdb->rows('wp_options'), "$label leave raw lifecycle rows unchanged");
        wprism_check_same(null, $kv($wpdb, CodeBaselineTransaction::BASELINE_KEY), "$label publish no baseline");
        wprism_check_same('keep-invalid-roster', $kv($wpdb, CodeBaselineTransaction::RECEIPT_KEY), "$label preserve prior receipt bytes");
    }

    $environment(withTemplate: false);
    wprism_check($failure($capture) instanceof \RuntimeException, 'a missing canonical lifecycle option row refuses publication');

    $environment();
    $writePlugin('1.7.2', false);
    wprism_check($failure($capture) instanceof \RuntimeException, 'a plugin file without Plugin Name cannot author a baseline');
    $environment();
    $writePlugin('');
    wprism_check($failure($capture) instanceof \RuntimeException, 'a plugin file without Version cannot author a baseline');

    $environment();
    $duplicateTheme = $writeTheme('2.0', $extraThemeRoot);
    $GLOBALS['wp_theme_directories'] = [$extraThemeRoot];
    wprism_check($failure(static fn() => CodeLifecycleObservation::read_unlocked($desired, null)) instanceof \RuntimeException, 'multiple registered style.css identities refuse theme observation');
    @unlink($duplicateTheme);
    @rmdir(dirname($duplicateTheme));
    @rmdir($extraThemeRoot);

    $wpdb = $environment(optionsEngine: 'MyISAM');
    wprism_check($failure($capture) instanceof \RuntimeException, 'nontransactional lifecycle storage refuses baseline authority');
    wprism_check_same(null, $kv($wpdb, CodeBaselineTransaction::BASELINE_KEY), 'storage refusal publishes no baseline');
    $wpdb = $environment(withOptionIndex: false);
    wprism_check($failure($capture) instanceof \RuntimeException, 'missing full-width unique lifecycle index refuses locking authority');
    wprism_check_same(null, $kv($wpdb, CodeBaselineTransaction::BASELINE_KEY), 'index refusal publishes no baseline');

    ProcessFence::release();
    Db::forget_transaction_tracking();
    @unlink($pluginPath);
    foreach (array_slice($roster, 1) as $nativePlugin) {
        @unlink(rtrim(WP_PLUGIN_DIR, '/\\') . '/' . $nativePlugin);
    }
    @rmdir(dirname($pluginPath));
    @unlink($themePath);
    @rmdir(dirname($themePath));
    WpStore::reset();
    wprism_check_summary('capture code baseline regression');
}
