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
    require_once __DIR__ . '/../../../../agent/src/Promotion/CodeBaselineAcceptance.php';
    require_once __DIR__ . '/../../../../agent/src/Promotion/CodeBaselineCapture.php';
    require_once __DIR__ . '/../../../../agent/src/Promotion/CodeBaselinePublication.php';

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
        bool $withOptionIndex = true
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
            ->setTableEngine('wp_options', $optionsEngine)
            ->setColumns('wp_wprism_kv', ['k' => 'varchar(191)', 'v' => 'longtext'])
            ->seedTable('wp_wprism_kv', $kvRows)
            ->setUniqueKey('wp_wprism_kv', ['k'])
            ->setIndexes('wp_wprism_kv', $index('PRIMARY', 'k'))
            ->setTableEngine('wp_wprism_kv', 'InnoDB')
            ->enableInformationSchema();
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
    ] as $raw => $label) {
        $environment(activeRaw: $raw);
        wprism_check($failure(static fn() => CodeLifecycleObservation::read_unlocked($desired, null)) instanceof \RuntimeException, "$label are refused");
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
    @rmdir(dirname($pluginPath));
    @unlink($themePath);
    @rmdir(dirname($themePath));
    WpStore::reset();
    wprism_check_summary('capture code baseline regression');
}
