<?php
/**
 * Offline proof for the provider-facing database session boundary.
 *
 * Db owns physical-session authority and exact SQL controls. Providers own
 * the semantic postimage that distinguishes a durably applied native write
 * from an unchanged preimage. This suite pins that join across driver false,
 * throw, lost-response, reconnect, reused-id and completion_type faults.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/ProviderDatabaseSession.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/ProviderSdk.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/Providers.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/ExactOptionWriter.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/WordPressOptionValueCodec.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';

use WPrism\DatabaseMutationException;
use WPrism\DatabaseLockBoundary;
use WPrism\DatabaseQueryIsolation;
use WPrism\DatabaseQueryIsolationViolationException;
use WPrism\DatabaseTransactionOutcomeException;
use WPrism\Db;
use WPrism\ExactOptionWriter;
use WPrism\Ledger;
use WPrism\LegacyRuntimeExecutionDebt;
use WPrism\ManifestProviderRuntime;
use WPrism\NativeDatabaseProfile;
use WPrism\ProviderDatabaseSession;
use WPrism\ProviderDatabaseTransactionNotAppliedException;
use WPrism\ProviderSdk;
use WPrism\Providers;
use WPrism\TransientDbException;
use WPrism\WordPressOptionValueCodec;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

/** Deterministic cache-delete failure seam for the exact option writer. */
function wp_cache_delete(string|int $key, string $group = ''): bool {
    $store = wprism_wp_store();
    $group = $group === '' ? 'default' : $group;
    $key = (string) $key;
    $store->cacheEvents[] = ['op' => 'delete', 'group' => $group, 'key' => $key];
    $throws = (int) (($GLOBALS['wprism_exact_option_cache_delete_throws'][$key] ?? 0));
    if ($throws > 0) {
        $GLOBALS['wprism_exact_option_cache_delete_throws'][$key] = $throws - 1;
        throw new RuntimeException('fixture cache delete threw');
    }
    $remaining = (int) (($GLOBALS['wprism_exact_option_cache_delete_failures'][$key] ?? 0));
    if ($remaining > 0) {
        $GLOBALS['wprism_exact_option_cache_delete_failures'][$key] = $remaining - 1;
        return false;
    }
    if (!isset($store->cache[$group]) || !array_key_exists($key, $store->cache[$group])) {
        return false;
    }
    unset($store->cache[$group][$key]);
    return true;
}

function wp_using_ext_object_cache(): bool {
    return ($GLOBALS['wprism_exact_option_ext_object_cache'] ?? false) === true;
}

final class ProviderDurableOptionWakeupProbe {
    public function __wakeup(): void {
        $GLOBALS['wprism_provider_option_wakeup'] = true;
    }
}

final class LegacyNamedMutexProviderProbe {
}

/** @param array{adapter:string,id:string,sha256:string} $identity */
function bind_legacy_named_mutex_probe(object $provider, array $identity): void {
    (new ReflectionMethod(Providers::class, 'bind_manifest_provider_identity'))
        ->invoke(null, $provider, $identity);
}

function invoke_legacy_named_mutex_probe(
    object $provider,
    string $providerId,
    string $capability,
    callable $callback
): mixed {
    return (new ReflectionMethod(Providers::class, 'invoke_provider_callback'))
        ->invoke(null, $provider, $providerId, $capability, $callback);
}

function provider_database_session_fixture(): FakeWpdb {
    Db::forget_transaction_tracking();
    // Row existence is not transactional storage evidence. The exact
    // metadata dispatcher now reports only an explicitly declared engine;
    // positive profile fixtures must establish that premise themselves.
    return FakeWpdb::install()
        ->seedTable('wp_wprism_provider_state', [])
        ->setTableEngine('wp_wprism_provider_state', 'InnoDB')
        ->enableInformationSchema();
}

wprism_check_same(
    [
        'profile' => 'complete-innodb-foreign-key-census/v1',
        'required_global_privilege' => 'PROCESS',
        'metadata_sources' => [
            'MariaDB' => 'INNODB_SYS_FOREIGN',
            'MySQL' => 'INNODB_FOREIGN',
        ],
    ],
    DatabaseLockBoundary::foreign_key_metadata_profile(),
    'the generic mutation boundary publishes one exact cross-engine foreign-key metadata prerequisite'
);

function provider_database_read_profile(): NativeDatabaseProfile {
    return NativeDatabaseProfile::read_only(['wp_wprism_provider_state']);
}

function provider_database_write_profile(): NativeDatabaseProfile {
    return new NativeDatabaseProfile([], ['wp_wprism_provider_state']);
}

/** Execute an SDK operation under one runtime-owned validated contract. */
final class ProviderSdkContractProbe extends ManifestProviderRuntime {
    private static ?Closure $operation = null;
    private static mixed $result = null;

    /** @param list<string> $reads @param list<string> $writes */
    public static function run(array $reads, array $writes, callable $operation): mixed {
        $runtime = self::runtime($reads, $writes);
        self::bindEngineContract($runtime);
        return self::execute($runtime, $operation);
    }

    /** @param list<string> $reads @param list<string> $writes */
    public static function runUnbound(array $reads, array $writes, callable $operation): mixed {
        return self::execute(self::runtime($reads, $writes), $operation);
    }

    /** @param list<string> $reads @param list<string> $writes */
    public static function runtime(array $reads, array $writes, bool $bind = false): self {
        $runtime = new self([
            'source' => 'manifest',
            'id' => 'provider-sdk-contract-probe',
            'plugin' => 'fixture/fixture.php',
            'version' => '1.0.0',
            'capabilities' => ['database_probe'],
            'contracts' => [
                'database_probe' => [
                    'args' => [],
                    'idempotent' => true,
                    'reads' => $reads,
                    'scope' => 'site',
                    'timeout_seconds' => 60,
                    'writes' => $writes,
                ],
            ],
        ]);
        if ($bind) {
            self::bindEngineContract($runtime);
        }
        return $runtime;
    }

    public static function execute(self $runtime, callable $operation): mixed {
        self::$operation = $operation(...);
        self::$result = null;
        try {
            $runtime->invoke('database_probe', []);
            return self::$result;
        } finally {
            self::$operation = null;
            self::$result = null;
        }
    }

    private static function bindEngineContract(self $runtime): void {
        if (!class_exists(Providers::class, false)) {
            require_once __DIR__ . '/../../../../agent/src/Adapter/Providers.php';
        }
        // Production has no public authority-minting seam. This low-level
        // fixture crosses the private loader join so it can exercise the SDK
        // below package discovery; product-path suites cover the real loader.
        $bind = new ReflectionMethod(Providers::class, 'bind_manifest_runtime_contracts');
        $bind->invoke(null, $runtime, $runtime->capabilities());
    }

    protected function invoke_database_probe(array $args): array {
        if ($args !== [] || self::$operation === null) {
            throw new RuntimeException('fixture provider SDK probe received an invalid invocation');
        }
        self::$result = (self::$operation)();
        return ['before' => [], 'after' => [], 'verified' => true];
    }
}

/** @param callable():mixed $operation */
function provider_database_session_failure(callable $operation): ?Throwable {
    try {
        $operation();
    } catch (Throwable $failure) {
        return $failure;
    }
    return null;
}

/** @return list<array<string,mixed>> */
function provider_database_session_rows(FakeWpdb $wpdb): array {
    return $wpdb->rows('wp_wprism_provider_state');
}

$wpdb = provider_database_session_fixture()->seedTable('wp_wprism_provider_state', [[
    'provider_key' => 'must_survive',
    'provider_value' => 'private_fixture_value',
]]);
$outerFilterStack = ['wprism_provider_outer_fixture'];
$GLOBALS['wp_current_filter'] = $outerFilterStack;
$sequentialUnsafeShows = [
    "SHOW TABLES LIKE 'wp_wprism_provider_state'",
    "SHOW TABLE STATUS LIKE 'wp_wprism_provider_state'",
];
$sequentialShowFailures = [];
$sequentialShowSettled = [];
foreach ($sequentialUnsafeShows as $offset => $sql) {
    $sequentialShowFailures[] = provider_database_session_failure(
        static fn(): mixed => ProviderDatabaseSession::read_only_snapshot(
            'sequential unsafe SHOW fixture ' . ($offset + 1),
            provider_database_read_profile(),
            static fn(): mixed => $wpdb->get_results($sql)
        )
    );
    $sequentialShowSettled[] = !DatabaseQueryIsolation::is_active()
        && $GLOBALS['wp_current_filter'] === $outerFilterStack;
}
wprism_check(
    count(array_filter(
        $sequentialShowFailures,
        static fn(?Throwable $failure): bool => $failure instanceof DatabaseQueryIsolationViolationException
    )) === 2
        && $sequentialShowSettled === [true, true]
        && array_intersect($sequentialUnsafeShows, $wpdb->queries()) === []
        && provider_database_session_rows($wpdb) === [[
            'provider_key' => 'must_survive',
            'provider_value' => 'private_fixture_value',
        ]],
    'sequential query-gate refusals restore the exact outer hook stack without transporting target SQL'
);
unset($GLOBALS['wp_current_filter']);

$wpdb = provider_database_session_fixture();
ProviderDatabaseSession::read_only_snapshot(
    'absent current-filter topology fixture',
    provider_database_read_profile(),
    static fn(): null => null
);
$absentCurrentFilterRestored = !array_key_exists('wp_current_filter', $GLOBALS);
$GLOBALS['wp_current_filter'] = null;
$wpdb = provider_database_session_fixture();
ProviderDatabaseSession::read_only_snapshot(
    'null current-filter topology fixture',
    provider_database_read_profile(),
    static fn(): null => null
);
$nullCurrentFilterRestored = array_key_exists('wp_current_filter', $GLOBALS)
    && $GLOBALS['wp_current_filter'] === null;
unset($GLOBALS['wp_current_filter']);
wprism_check(
    $absentCurrentFilterRestored && $nullCurrentFilterRestored,
    'query isolation preserves absent and explicit-null current-filter topology after settlement'
);

$wpdb = FakeWpdb::install()->seedTable('wp_options', [[
    'option_id' => 1,
    'option_name' => 'durable_fixture',
    'option_value' => serialize(['enabled' => true]),
    'autoload' => 'yes',
]]);
wprism_check_same(
    ['enabled' => true],
    ProviderSdk::checked_durable_option(
        'durable_fixture',
        [],
        'provider durable option fixture',
        $wpdb
    ),
    'the provider SDK reads and safely decodes one exact physical option row'
);

$wpdb = FakeWpdb::install()->seedTable('wp_options', [[
    'option_id' => 1,
    'option_name' => 'durable_fixture',
    'option_value' => 'small',
    'autoload' => 'yes',
]])->returnNextGetResultsAs([[
    'option_name' => 'durable_fixture',
    'option_value_bytes' => '16777217',
    'option_value_sha256' => hash('sha256', 'small'),
]], 'option_value_bytes');
$oversizedOption = provider_database_session_failure(
    static fn(): mixed => ProviderSdk::checked_durable_option(
        'durable_fixture',
        null,
        'provider oversized durable option fixture',
        $wpdb
    )
);
wprism_check(
    $oversizedOption instanceof RuntimeException
        && str_contains($oversizedOption->getMessage(), 'bounded frontier')
        && count(array_filter(
            $wpdb->queries(),
            static fn(string $sql): bool => str_contains($sql, 'SELECT option_name, option_value FROM')
        )) === 0,
    'an oversized durable option refuses from compact size/hash evidence before payload transfer'
);

$wpdb = FakeWpdb::install()->seedTable('wp_options', [
    [
        'option_id' => 1,
        'option_name' => 'durable_fixture',
        'option_value' => 'first',
        'autoload' => 'yes',
    ],
    [
        'option_id' => 2,
        'option_name' => 'durable_fixture',
        'option_value' => 'second',
        'autoload' => 'yes',
    ],
]);
$duplicateOption = provider_database_session_failure(
    static fn(): mixed => ProviderSdk::checked_durable_option(
        'durable_fixture',
        null,
        'provider duplicate durable option fixture',
        $wpdb
    )
);
wprism_check(
    $duplicateOption instanceof RuntimeException
        && str_contains($duplicateOption->getMessage(), 'duplicate/collation-alias'),
    'collation-equal duplicate durable option rows cannot collapse into one provider decision'
);

$wpdb = FakeWpdb::install()->seedTable('wp_options', [[
    'option_id' => 1,
    'option_name' => 'Durable_Fixture',
    'option_value' => 'aliased',
    'autoload' => 'yes',
]]);
$aliasedOption = provider_database_session_failure(
    static fn(): mixed => ProviderSdk::checked_durable_option(
        'durable_fixture',
        null,
        'provider aliased durable option fixture',
        $wpdb
    )
);
wprism_check(
    $aliasedOption instanceof RuntimeException
        && str_contains($aliasedOption->getMessage(), 'size/identity preflight'),
    'a case-folded option-name alias cannot satisfy the exact durable identity'
);

$GLOBALS['wprism_provider_option_wakeup'] = false;
$wpdb = FakeWpdb::install()->seedTable('wp_options', [[
    'option_id' => 1,
    'option_name' => 'durable_fixture',
    'option_value' => serialize(new ProviderDurableOptionWakeupProbe()),
    'autoload' => 'yes',
]]);
$objectOption = provider_database_session_failure(
    static fn(): mixed => ProviderSdk::checked_durable_option(
        'durable_fixture',
        null,
        'provider object durable option fixture',
        $wpdb
    )
);
wprism_check(
    $objectOption instanceof RuntimeException
        && str_contains($objectOption->getMessage(), 'non-plain serialized data')
        && $GLOBALS['wprism_provider_option_wakeup'] === false,
    'target-owned serialized objects are refused without constructing or waking a class'
);

$checkedReadMethods = [
    'get_var' => static fn(string $sql, FakeWpdb $database): mixed => ProviderSdk::checked_get_var(
        $sql,
        'dynamic checked get-var fixture',
        $database
    ),
    'get_col' => static fn(string $sql, FakeWpdb $database): mixed => ProviderSdk::checked_get_col(
        $sql,
        'dynamic checked get-col fixture',
        $database
    ),
    'get_row' => static fn(string $sql, FakeWpdb $database): mixed => ProviderSdk::checked_get_row(
        $sql,
        'dynamic checked get-row fixture',
        $database
    ),
    'get_results' => static fn(string $sql, FakeWpdb $database): mixed => ProviderSdk::checked_get_results(
        $sql,
        'dynamic checked get-results fixture',
        $database
    ),
];
foreach ($checkedReadMethods as $method => $checkedRead) {
    $wpdb = FakeWpdb::install()->seedTable('wp_wprism_provider_state', [[
        'provider_key' => 'must_survive',
        'provider_value' => 'private_fixture_value',
    ]]);
    $dynamicVerb = 'DE' . 'LETE';
    $failure = provider_database_session_failure(
        static fn(): mixed => $checkedRead(
            "$dynamicVerb FROM wp_wprism_provider_state",
            $wpdb
        )
    );
    wprism_check(
        $failure instanceof DatabaseQueryIsolationViolationException
            && $failure->getMessage()
                === 'wprism: provider checked read requires one non-mutating database statement'
            && $wpdb->queries() === []
            && provider_database_session_rows($wpdb) === [[
                'provider_key' => 'must_survive',
                'provider_value' => 'private_fixture_value',
            ]],
        "checked_$method cannot transport runtime-assembled DELETE through a wpdb read method"
    );
}

$unsafeCheckedReads = [
    'INSERT INTO wp_wprism_provider_state (provider_key) VALUES (\'unsafe\')',
    'UPDATE wp_wprism_provider_state SET provider_value = \'unsafe\'',
    'REPLACE INTO wp_wprism_provider_state (provider_key) VALUES (\'unsafe\')',
    'TRUNCATE TABLE wp_wprism_provider_state',
    'CREATE TABLE wp_wprism_side_effect (id BIGINT)',
    'ALTER TABLE wp_wprism_provider_state ADD unsafe BIGINT',
    'DROP TABLE wp_wprism_provider_state',
    'RENAME TABLE wp_wprism_provider_state TO wp_wprism_side_effect',
    'SET @wprism_side_effect = 1',
    'CALL wprism_side_effect()',
    'DO wprism_side_effect()',
    'LOCK TABLES wp_wprism_provider_state WRITE',
    'UNLOCK TABLES',
    'WITH victim AS (SELECT provider_key FROM wp_wprism_provider_state) '
        . 'DELETE FROM wp_wprism_provider_state',
    '(DELETE FROM wp_wprism_provider_state)',
    '(SELECT provider_key FROM wp_wprism_provider_state) UNION ALL '
        . '(DELETE FROM wp_wprism_provider_state)',
    '(SELECT provider_key FROM wp_wprism_provider_state) UNION ALL '
        . '(WITH victim AS (SELECT provider_key FROM wp_wprism_provider_state) '
        . 'DELETE FROM wp_wprism_provider_state)',
    'SELECT provider_key FROM wp_wprism_provider_state; DELETE FROM wp_wprism_provider_state',
    'SELECT provider_key FROM wp_wprism_provider_state # hidden mutation',
    'SELECT provider_key FROM wp_wprism_provider_state -- hidden mutation',
    'SELECT provider_key FROM wp_wprism_provider_state /* hidden mutation */',
    '/*!50000 DELETE FROM wp_wprism_provider_state */',
    "SELECT provider_key INTO OUTFILE '/tmp/wprism-private' FROM wp_wprism_provider_state",
    "SELECT provider_key INTO DUMPFILE '/tmp/wprism-private' FROM wp_wprism_provider_state",
    "SELECT LOAD_FILE('/tmp/wprism-private')",
    "SELECT GET_LOCK('wprism-private', 1)",
    "SELECT RELEASE_LOCK('wprism-private')",
    'SELECT SLEEP(1)',
    'SELECT BENCHMARK(1, MD5(\'wprism-private\'))',
    'SELECT @wprism_side_effect := provider_key FROM wp_wprism_provider_state',
    'SELECT provider_key INTO @wprism_side_effect FROM wp_wprism_provider_state',
    'SELECT wprism_side_effect(provider_key) FROM wp_wprism_provider_state',
    'SELECT wprism_private.wprism_side_effect(provider_key) FROM wp_wprism_provider_state',
    'SELECT `COUNT`(provider_key) FROM wp_wprism_provider_state',
    'SELECT COUNT (provider_key) FROM wp_wprism_provider_state',
    'SELECT DECIMAL(19,4)',
    'EXPLAIN ANALYZE SELECT provider_key FROM wp_wprism_provider_state',
    'EXPLAIN UPDATE wp_wprism_provider_state SET provider_value = \'unsafe\'',
    'EXPLAIN INSERT INTO wp_wprism_provider_state (provider_key) VALUES (\'unsafe\')',
    'EXPLAIN REPLACE INTO wp_wprism_provider_state (provider_key) VALUES (\'unsafe\')',
    'EXPLAIN wp_wprism_provider_state',
    'SELECT NEXT VALUE FOR wp_wprism_private_sequence',
    'SELECT PREVIOUS VALUE FOR wp_wprism_private_sequence',
    "SHOW TABLES LIKE 'wp_wprism_provider_state'",
    "SHOW TABLE STATUS LIKE 'wp_wprism_provider_state'",
    'SHOW TABLES FROM wp_wprism_provider_state',
    'SHOW TRIGGERS FROM wp_wprism_provider_state',
    'SHOW OPEN TABLES FROM wp_wprism_provider_state',
];
foreach ($unsafeCheckedReads as $offset => $sql) {
    $wpdb = FakeWpdb::install()->seedTable('wp_wprism_provider_state', [[
        'provider_key' => 'must_survive',
        'provider_value' => 'private_fixture_value',
    ]]);
    $failure = provider_database_session_failure(
        static fn(): array => ProviderSdk::checked_get_results(
            $sql,
            'adversarial checked read fixture',
            $wpdb
        )
    );
    wprism_check(
        $failure instanceof DatabaseQueryIsolationViolationException
            && !str_contains($failure->getMessage(), 'wp_wprism_provider_state')
            && !str_contains($failure->getMessage(), 'wprism-private')
            && $wpdb->queries() === []
            && count(provider_database_session_rows($wpdb)) === 1,
        'adversarial checked-read statement ' . ($offset + 1) . ' is value-free and refused before transport'
    );
}

$acceptedCheckedReadGrammar = [
    'SELECT provider_key FROM wp_wprism_provider_state',
    "SELECT '-- ; # /* value */' AS literal_value",
    "SHOW TABLES LIKE 'wp\\\\_wprism\\\\_provider\\\\_state'",
    'SHOW CREATE TABLE `wp_wprism_provider_state`',
    "SHOW TABLE STATUS LIKE 'wp\\\\_wprism\\\\_provider\\\\_state'",
    'SHOW FULL COLUMNS FROM `wp_wprism_provider_state`',
    'SHOW INDEX FROM `wp_wprism_provider_state`',
    "SHOW KEYS FROM `wp_wprism_provider_state` WHERE Key_name = 'PRIMARY'",
    'DESCRIBE `wp_wprism_provider_state`',
    'EXPLAIN SELECT provider_key FROM wp_wprism_provider_state',
    'SELECT DATABASE(), COUNT(*) FROM wp_wprism_provider_state',
    "SELECT CAST('1.250000' AS DECIMAL(19,4)) AS normalized_value",
    '(SELECT provider_key FROM wp_wprism_provider_state LIMIT 2) UNION ALL '
        . '(SELECT provider_key FROM wp_wprism_provider_state LIMIT 2) '
        . 'ORDER BY provider_key ASC LIMIT 3',
    'SELECT @@SESSION.sql_mode AS sql_mode',
    'SELECT provider_key FROM wp_wprism_provider_state FOR UPDATE',
    'SELECT provider_key FROM wp_wprism_provider_state LOCK IN SHARE MODE',
];
foreach ($acceptedCheckedReadGrammar as $sql) {
    DatabaseQueryIsolation::assert_provider_read_statement($sql);
}
wprism_check(
    true,
    'the checked-read grammar retains audited SELECT/SHOW/DESCRIBE/EXPLAIN, parenthesized UNION, and legacy locking reads'
);

$legacyMutexPath = 'adapter-packages/woocommerce/package/runtime/providers/woocommerce-scheduler-settings.php';
$legacyMutexDigest = LegacyRuntimeExecutionDebt::ROWS[$legacyMutexPath]['sha256'];
$legacyMutexName = 'wprism:woocommerce:scheduler:' . str_repeat('a', 32);
$legacyMutexAcquire = "SELECT GET_LOCK('$legacyMutexName', 0)";
$legacyMutexRelease = "SELECT RELEASE_LOCK('$legacyMutexName')";
$wpdb = FakeWpdb::install();
$unboundLegacyMutex = provider_database_session_failure(
    static fn(): mixed => ProviderSdk::checked_get_var(
        $legacyMutexAcquire,
        'unbound legacy mutex fixture',
        $wpdb
    )
);
wprism_check(
    $unboundLegacyMutex instanceof DatabaseQueryIsolationViolationException
        && $wpdb->queries() === [],
    'the frozen named-mutex spelling has no authority outside an engine-bound provider invocation'
);

$legacyMutexProvider = new LegacyNamedMutexProviderProbe();
bind_legacy_named_mutex_probe($legacyMutexProvider, [
    'adapter' => 'woocommerce',
    'id' => 'woocommerce-scheduler-settings',
    'sha256' => $legacyMutexDigest,
]);
$wpdb = FakeWpdb::install();
$legacyMutexResults = invoke_legacy_named_mutex_probe(
    $legacyMutexProvider,
    'woocommerce-scheduler-settings',
    'reconcile_analytics_import_schedule',
    static fn(): array => [
        ProviderSdk::checked_get_var($legacyMutexAcquire, 'legacy mutex acquisition fixture', $wpdb),
        ProviderSdk::checked_get_var($legacyMutexRelease, 'legacy mutex release fixture', $wpdb),
    ]
);
wprism_check(
    array_map('strval', $legacyMutexResults) === ['1', '1']
        && array_values(array_filter(
            $wpdb->queries(),
            static fn(string $sql): bool => $sql === $legacyMutexAcquire || $sql === $legacyMutexRelease
        )) === [$legacyMutexAcquire, $legacyMutexRelease],
    'only the exact loader identity and capability admit the frozen get-var named-mutex pair'
);

foreach ([
    'direct object' => [new LegacyNamedMutexProviderProbe(), 'woocommerce-scheduler-settings', $legacyMutexDigest,
        'reconcile_analytics_import_schedule', false],
    'wrong provider' => [new LegacyNamedMutexProviderProbe(), 'woocommerce-other-provider', $legacyMutexDigest,
        'reconcile_analytics_import_schedule', true],
    'wrong digest' => [new LegacyNamedMutexProviderProbe(), 'woocommerce-scheduler-settings', str_repeat('0', 64),
        'reconcile_analytics_import_schedule', true],
    'wrong capability' => [new LegacyNamedMutexProviderProbe(), 'woocommerce-scheduler-settings', $legacyMutexDigest,
        'unreviewed_capability', true],
] as $label => [$candidate, $providerId, $digest, $capability, $bind]) {
    if ($bind) {
        bind_legacy_named_mutex_probe($candidate, [
            'adapter' => 'woocommerce',
            'id' => $providerId,
            'sha256' => $digest,
        ]);
    }
    $wpdb = FakeWpdb::install();
    $failure = provider_database_session_failure(
        static fn(): mixed => invoke_legacy_named_mutex_probe(
            $candidate,
            $providerId,
            $capability,
            static fn(): mixed => ProviderSdk::checked_get_var(
                $legacyMutexAcquire,
                'legacy mutex authority refusal fixture',
                $wpdb
            )
        )
    );
    wprism_check(
        $failure instanceof DatabaseQueryIsolationViolationException
            && $wpdb->queries() === [],
        "$label cannot acquire the frozen provider named-mutex authority"
    );
}

$wrongInvocationId = provider_database_session_failure(
    static fn(): mixed => invoke_legacy_named_mutex_probe(
        $legacyMutexProvider,
        'woocommerce-other-provider',
        'reconcile_analytics_import_schedule',
        static fn(): bool => true
    )
);
wprism_check(
    $wrongInvocationId instanceof RuntimeException
        && str_contains($wrongInvocationId->getMessage(), 'identity disagrees'),
    'an engine invocation cannot relabel an exact bound provider object'
);

$invalidLegacyMutexStatements = [
    "SELECT GET_LOCK('$legacyMutexName', 1)",
    "SELECT GET_LOCK('wprism:other:scheduler:" . str_repeat('a', 32) . "', 0)",
    "SELECT GET_LOCK('wprism:woocommerce:scheduler:" . str_repeat('a', 31) . "', 0)",
    "SELECT GET_LOCK('wprism:woocommerce:scheduler:" . str_repeat('a', 33) . "', 0)",
    "SELECT GET_LOCK('wprism:woocommerce:scheduler:" . str_repeat('A', 32) . "', 0)",
    " SELECT GET_LOCK('$legacyMutexName', 0)",
    "select GET_LOCK('$legacyMutexName', 0)",
    "SELECT RELEASE_LOCK('$legacyMutexName') ",
];
foreach ($invalidLegacyMutexStatements as $offset => $sql) {
    $wpdb = FakeWpdb::install();
    $failure = provider_database_session_failure(
        static fn(): mixed => invoke_legacy_named_mutex_probe(
            $legacyMutexProvider,
            'woocommerce-scheduler-settings',
            'reconcile_stock_notification_retention',
            static fn(): mixed => ProviderSdk::checked_get_var(
                $sql,
                'legacy mutex statement refusal fixture',
                $wpdb
            )
        )
    );
    wprism_check(
        $failure instanceof DatabaseQueryIsolationViolationException
            && $wpdb->queries() === [],
        'legacy named-mutex statement variant ' . ($offset + 1) . ' is refused before transport'
    );
}

foreach (['checked_get_col', 'checked_get_row', 'checked_get_results'] as $method) {
    $wpdb = FakeWpdb::install();
    $failure = provider_database_session_failure(
        static fn(): mixed => invoke_legacy_named_mutex_probe(
            $legacyMutexProvider,
            'woocommerce-scheduler-settings',
            'reconcile_analytics_import_schedule',
            static fn(): mixed => ProviderSdk::$method(
                $legacyMutexAcquire,
                'legacy mutex checked-reader refusal fixture',
                $wpdb
            )
        )
    );
    wprism_check(
        $failure instanceof DatabaseQueryIsolationViolationException
            && $wpdb->queries() === [],
        "$method cannot inherit the one checked-get-var legacy mutex exception"
    );
}

$nestedLegacyMutex = invoke_legacy_named_mutex_probe(
    $legacyMutexProvider,
    'woocommerce-scheduler-settings',
    'reconcile_analytics_import_schedule',
    static function () use ($legacyMutexProvider, $legacyMutexAcquire): array {
        $nested = provider_database_session_failure(
            static fn(): mixed => invoke_legacy_named_mutex_probe(
                new LegacyNamedMutexProviderProbe(),
                'unbound-provider',
                'nested_capability',
                static fn(): bool => true
            )
        );
        $wpdb = FakeWpdb::install();
        $acquired = ProviderSdk::checked_get_var(
            $legacyMutexAcquire,
            'legacy mutex post-reentry fixture',
            $wpdb
        );
        return [$nested, $acquired, $wpdb->queries()];
    }
);
wprism_check(
    $nestedLegacyMutex[0] instanceof RuntimeException
        && str_contains($nestedLegacyMutex[0]->getMessage(), 'cannot re-enter')
        && (string) $nestedLegacyMutex[1] === '1'
        && array_values(array_filter(
            $nestedLegacyMutex[2],
            static fn(string $sql): bool => $sql === $legacyMutexAcquire
        )) === [$legacyMutexAcquire],
    'nested provider dispatch is refused without clearing the outer exact legacy authority'
);

$legacyCallbackFailure = provider_database_session_failure(
    static fn(): mixed => invoke_legacy_named_mutex_probe(
        $legacyMutexProvider,
        'woocommerce-scheduler-settings',
        'reconcile_analytics_import_schedule',
        static fn(): never => throw new RuntimeException('legacy fixture callback failed')
    )
);
$wpdb = FakeWpdb::install();
$postFailureLegacyMutex = provider_database_session_failure(
    static fn(): mixed => ProviderSdk::checked_get_var(
        $legacyMutexAcquire,
        'post-failure legacy mutex fixture',
        $wpdb
    )
);
wprism_check(
    $legacyCallbackFailure instanceof RuntimeException
        && $legacyCallbackFailure->getMessage() === 'legacy fixture callback failed'
        && $postFailureLegacyMutex instanceof DatabaseQueryIsolationViolationException
        && $wpdb->queries() === [],
    'provider invocation cleanup clears frozen legacy authority after a callback throws'
);

foreach ($checkedReadMethods as $method => $checkedRead) {
    $wpdb = provider_database_session_fixture()->seedTable('wp_wprism_provider_state', [[
        'provider_key' => 'must_survive',
        'provider_value' => 'private_fixture_value',
    ]]);
    $dynamicVerb = 'DE' . 'LETE';
    $failure = provider_database_session_failure(
        static fn(): mixed => ProviderDatabaseSession::repeatable_read_write(
            "checked $method writable-profile fixture",
            provider_database_write_profile(),
            static fn(): mixed => $checkedRead(
                "$dynamicVerb FROM wp_wprism_provider_state",
                $wpdb
            ),
            static fn(mixed $_result): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN
        )
    );
    wprism_check(
        $failure instanceof DatabaseQueryIsolationViolationException
            && count(array_filter(
                $wpdb->queries(),
                static fn(string $query): bool => str_starts_with($query, 'DELETE FROM')
            )) === 0
            && provider_database_session_rows($wpdb) === [[
                'provider_key' => 'must_survive',
                'provider_value' => 'private_fixture_value',
            ]]
            && $wpdb->activeTransactionIsolation() === null,
        "checked_$method cannot disguise DML even when the active native profile declares that table writable"
    );
}

$checkedReadSecret = 'sk_provider_checked_read_throw_must_not_escape';
$wpdb = FakeWpdb::install()
    ->seedTable('wp_wprism_provider_state', [])
    ->onQuery(static fn(): never => throw new RuntimeException($checkedReadSecret));
$throwingCheckedRead = provider_database_session_failure(
    static fn(): array => ProviderSdk::checked_get_results(
        'SELECT provider_key FROM wp_wprism_provider_state',
        'throwing provider checked read',
        $wpdb
    )
);
wprism_check(
    $throwingCheckedRead instanceof RuntimeException
        && $throwingCheckedRead->getMessage()
            === 'wprism: provider checked read failed: throwing provider checked read'
        && !str_contains($throwingCheckedRead->getMessage(), $checkedReadSecret)
        && $throwingCheckedRead->getPrevious() instanceof RuntimeException,
    'a throwing compatible-driver read preserves its private cause behind the SDK value-free diagnostic'
);

/**
 * @param null|callable(string):string $classifier
 * @return array{value:string}
 */
function provider_database_session_write(
    FakeWpdb $wpdb,
    string $value,
    ?callable $classifier = null
): array {
    return ProviderDatabaseSession::repeatable_read_write(
        'fixture native regeneration',
        provider_database_write_profile(),
        static function () use ($wpdb, $value): array {
            wprism_check_same(
                'REPEATABLE-READ',
                $wpdb->activeTransactionIsolation(),
                'provider mutation runs inside the engine-owned repeatable-read transaction'
            );
            Db::insert(
                'wp_wprism_provider_state',
                ['provider_key' => 'derived', 'provider_value' => $value],
                null,
                'fixture provider write'
            );
            return ['value' => $value];
        },
        static function (array $result) use ($wpdb, $classifier): string {
            wprism_check_same(
                'REPEATABLE-READ',
                $wpdb->activeTransactionIsolation(),
                'physical postimage classification runs inside a fresh repeatable-read snapshot'
            );
            if ($classifier !== null) {
                return $classifier($result['value']);
            }
            $rows = provider_database_session_rows($wpdb);
            if ($rows === []) {
                return ProviderDatabaseSession::POSTIMAGE_NOT_APPLIED;
            }
            return $rows === [[
                'provider_key' => 'derived',
                'provider_value' => $result['value'],
            ]]
                ? ProviderDatabaseSession::POSTIMAGE_APPLIED
                : ProviderDatabaseSession::POSTIMAGE_UNKNOWN;
        }
    );
}

$wpdb = provider_database_session_fixture();
$readResult = ProviderDatabaseSession::read_only_snapshot(
    'fixture provider projection',
    provider_database_read_profile(),
    static function () use ($wpdb): array {
        wprism_check_same(
            'REPEATABLE-READ',
            $wpdb->activeTransactionIsolation(),
            'provider read callback observes the requested one-shot isolation'
        );
        return provider_database_session_rows($wpdb);
    }
);
$readQueries = $wpdb->queries();
wprism_check_same([], $readResult, 'read-only session returns the provider projection unchanged');
wprism_check(
    in_array('START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT', $readQueries, true)
        && in_array('ROLLBACK AND NO CHAIN NO RELEASE', $readQueries, true),
    'the read boundary emits the production dialect accepted by MariaDB 11.8 and MySQL 8.4'
);
wprism_check_same(
    null,
    $wpdb->activeTransactionIsolation(),
    'the read-only session leaves no transaction open'
);

$profiledStatement = 'SELECT provider_key FROM wp_wprism_provider_state LIMIT 1';
$wpdb = provider_database_session_fixture();
$baselineSession = $wpdb->wprism_test_database_session_state();
$wpdb->resetLog();
$sqlModeDrift = provider_database_session_failure(
    static fn(): mixed => ProviderDatabaseSession::read_only_snapshot(
        'provider mutable SQL-mode fixture',
        provider_database_read_profile(),
        static function () use ($wpdb, $profiledStatement): mixed {
            $wpdb->set_sql_mode(['NO_BACKSLASH_ESCAPES']);
            return $wpdb->query($profiledStatement);
        }
    )
);
wprism_check(
    $sqlModeDrift instanceof DatabaseQueryIsolationViolationException
        && !in_array($profiledStatement, $wpdb->queries(), true)
        && $wpdb->wprism_test_database_session_state() === $baselineSession
        && $wpdb->activeTransactionIsolation() === null,
    'stock wpdb SQL-mode mutation is refused before the next profiled transport and cleanup restores the exact session'
);

$wpdb = provider_database_session_fixture();
$baselineSession = $wpdb->wprism_test_database_session_state();
$charsetDrift = provider_database_session_failure(
    static fn(): mixed => ProviderDatabaseSession::read_only_snapshot(
        'provider mutable charset fixture',
        provider_database_read_profile(),
        static function () use ($wpdb): string {
            $wpdb->set_charset(null, 'latin1', 'latin1_swedish_ci');
            return 'no provider query after mutation';
        }
    )
);
$charsetDriftCause = $charsetDrift;
$charsetDriftIsolationCause = false;
for ($depth = 0; $depth < 16 && $charsetDriftCause instanceof Throwable; $depth++) {
    if ($charsetDriftCause instanceof DatabaseQueryIsolationViolationException) {
        $charsetDriftIsolationCause = true;
        break;
    }
    $charsetDriftCause = $charsetDriftCause->getPrevious();
}
wprism_check(
    $charsetDrift instanceof DatabaseTransactionOutcomeException
        && $charsetDriftIsolationCause
        && $wpdb->wprism_test_database_session_state() === $baselineSession
        && $wpdb->activeTransactionIsolation() === null,
    'zero-query stock wpdb charset mutation is caught by continuity settlement and cleanup restores every bound charset field'
);

$wpdb = provider_database_session_fixture();
$baselineSession = $wpdb->wprism_test_database_session_state();
$wpdb->resetLog();
$schemaDrift = provider_database_session_failure(
    static fn(): mixed => ProviderDatabaseSession::read_only_snapshot(
        'provider selected-schema fixture',
        provider_database_read_profile(),
        static function () use ($wpdb, $profiledStatement): mixed {
            $wpdb->select('wordpress_shadow');
            return $wpdb->query($profiledStatement);
        }
    )
);
wprism_check(
    $schemaDrift instanceof DatabaseQueryIsolationViolationException
        && !in_array($profiledStatement, $wpdb->queries(), true)
        && $wpdb->wprism_test_database_session_state() === $baselineSession
        && $wpdb->activeTransactionIsolation() === null,
    'stock wpdb schema selection cannot redirect an unqualified profiled query and cleanup reselects the exact database'
);

foreach (['NO_BACKSLASH_ESCAPES', 'ANSI_QUOTES', 'IGNORE_SPACE', 'STRICT_TRANS_TABLES, NO_ZERO_DATE'] as $mode) {
    $callbackCalls = 0;
    $wpdb = provider_database_session_fixture()->setSessionSqlMode($mode);
    $modeFailure = provider_database_session_failure(
        static function () use (&$callbackCalls): mixed {
            return ProviderDatabaseSession::read_only_snapshot(
                'provider SQL-mode premise',
                provider_database_read_profile(),
                static function () use (&$callbackCalls): null {
                    $callbackCalls++;
                    return null;
                }
            );
        }
    );
    wprism_check(
        $modeFailure instanceof DatabaseQueryIsolationViolationException
            && str_contains($modeFailure->getMessage(), 'SQL-mode premise')
            && !str_contains($modeFailure->getMessage(), $mode)
            && $callbackCalls === 0
            && count(array_filter(
                $wpdb->queries(),
                static fn(string $sql): bool => stripos($sql, 'information_schema') !== false
                    || stripos($sql, 'INNODB_SYS_FOREIGN') !== false
                    || stripos($sql, 'INNODB_FOREIGN') !== false
            )) === 0
            && $wpdb->activeTransactionIsolation() === null,
        "SQL mode '$mode' is refused before metadata SQL, the closed provider parser, or callback can run"
    );
}

foreach (['gbk', 'big5', 'sjis'] as $characterSet) {
    $callbackCalls = 0;
    $wpdb = provider_database_session_fixture()
        ->setSessionCharacterSetClient($characterSet, 2);
    $characterSetFailure = provider_database_session_failure(
        static function () use (&$callbackCalls): mixed {
            return ProviderDatabaseSession::read_only_snapshot(
                'provider character-set premise',
                NativeDatabaseProfile::read_only([]),
                static function () use (&$callbackCalls): null {
                    $callbackCalls++;
                    return null;
                }
            );
        }
    );
    wprism_check(
        $characterSetFailure instanceof DatabaseQueryIsolationViolationException
            && str_contains($characterSetFailure->getMessage(), 'character-set premise')
            && !str_contains($characterSetFailure->getMessage(), $characterSet)
            && $callbackCalls === 0
            && count(array_filter(
                $wpdb->queries(),
                static fn(string $sql): bool => stripos($sql, 'information_schema') !== false
            )) === 0
            && $wpdb->activeTransactionIsolation() === null,
        "multibyte client character set '$characterSet' is refused after only its width proof and before table metadata or provider code"
    );
}

foreach (['latin2', 'cp1251'] as $characterSet) {
    $callbackCalls = 0;
    $wpdb = provider_database_session_fixture()
        ->setSessionCharacterSetClient($characterSet, 1);
    $result = ProviderDatabaseSession::read_only_snapshot(
        'provider single-byte character-set premise',
        NativeDatabaseProfile::read_only([]),
        static function () use (&$callbackCalls, $characterSet): string {
            $callbackCalls++;
            return $characterSet;
        }
    );
    wprism_check(
        $result === $characterSet
            && $callbackCalls === 1
            && $wpdb->activeTransactionIsolation() === null,
        "byte-safe single-byte client character set '$characterSet' retains profiled provider compatibility"
    );
}

$callbackCalls = 0;
$wpdb = provider_database_session_fixture()
    ->failNextDatabaseSessionObservation('fixture character-set width secret');
$characterSetWidthFailure = provider_database_session_failure(
    static function () use (&$callbackCalls): mixed {
        return ProviderDatabaseSession::read_only_snapshot(
            'failed provider character-set width premise',
            NativeDatabaseProfile::read_only([]),
            static function () use (&$callbackCalls): null {
                $callbackCalls++;
                return null;
            }
        );
    }
);
wprism_check(
    $characterSetWidthFailure instanceof DatabaseQueryIsolationViolationException
        && str_contains($characterSetWidthFailure->getMessage(), 'session-state premise')
        && !str_contains($characterSetWidthFailure->getMessage(), 'fixture character-set width secret')
        && $callbackCalls === 0
        && $wpdb->activeTransactionIsolation() === null,
    'an unreadable character-set width premise refuses value-free before provider code'
);

$callbackCalls = 0;
$wpdb = provider_database_session_fixture()
    ->failNextDatabaseSessionObservation('fixture SQL-mode value secret');
$modeReadFailure = provider_database_session_failure(
    static function () use (&$callbackCalls): mixed {
        return ProviderDatabaseSession::read_only_snapshot(
            'failed provider SQL-mode premise',
            provider_database_read_profile(),
            static function () use (&$callbackCalls): null {
                $callbackCalls++;
                return null;
            }
        );
    }
);
wprism_check(
    $modeReadFailure instanceof DatabaseQueryIsolationViolationException
        && !str_contains($modeReadFailure->getMessage(), 'fixture SQL-mode value secret')
        && $callbackCalls === 0
        && $wpdb->activeTransactionIsolation() === null,
    'an unreadable SQL-mode premise refuses with a value-free diagnostic before provider code'
);

$wpdb = provider_database_session_fixture();
$statement = 'SELECT provider_key FROM wp_wprism_provider_state LIMIT 1';
$statementBudgetFailure = provider_database_session_failure(
    static function () use ($wpdb, $statement): mixed {
        return ProviderDatabaseSession::read_only_snapshot(
            'provider statement budget',
            provider_database_read_profile(),
            static function () use ($wpdb, $statement): null {
                for ($index = 0; $index <= 1024; $index++) {
                    $wpdb->query($statement);
                }
                return null;
            }
        );
    }
);
$transportedStatements = count(array_filter(
    $wpdb->queries(),
    static fn(string $sql): bool => $sql === $statement
));
wprism_check(
    $statementBudgetFailure instanceof DatabaseQueryIsolationViolationException
        && str_contains($statementBudgetFailure->getMessage(), 'statement-count boundary')
        && $transportedStatements === 1024
        && $wpdb->activeTransactionIsolation() === null,
    'the 1,025th provider statement is refused before transport and rolls back the bounded callback'
);

$wpdb = provider_database_session_fixture();
$atStatementFrontier = ProviderDatabaseSession::read_only_snapshot(
    'provider exact statement frontier',
    provider_database_read_profile(),
    static function () use ($wpdb, $statement): string {
        for ($index = 0; $index < 1024; $index++) {
            $wpdb->query($statement);
        }
        return 'bounded';
    }
);
wprism_check(
    $atStatementFrontier === 'bounded'
        && count(array_filter($wpdb->queries(), static fn(string $sql): bool => $sql === $statement)) === 1024
        && $wpdb->activeTransactionIsolation() === null,
    '1,024 provider statements leave exact engine continuity and rollback permits outside the callback budget'
);

// The physical profile owner alone receives work-partition authority. A
// provider callback gets neither that return value nor a getter for it, and
// construction is not authority even while its native snapshot is active.
foreach (['engine-scope', 'work-item'] as $entry) {
    $wpdb = provider_database_session_fixture();
    $deniedWork = provider_database_session_failure(static fn() => ProviderDatabaseSession::read_only_snapshot(
        'provider cannot partition its callback',
        provider_database_read_profile(),
        static function () use ($entry, $wpdb, $statement): void {
            $forged = new WPrism\DatabaseWorkAuthority();
            $operation = static fn() => $wpdb->query($statement);
            if ($entry === 'engine-scope') {
                DatabaseQueryIsolation::with_engine_work_units($forged, $operation);
            } else {
                DatabaseQueryIsolation::work_unit($forged, $operation);
            }
        }
    ));
    wprism_check(
        $deniedWork instanceof DatabaseQueryIsolationViolationException
            && count(array_filter($wpdb->queries(), static fn(string $sql): bool => $sql === $statement)) === 0
            && $wpdb->activeTransactionIsolation() === null,
        "a provider cannot mint $entry authority from its active native profile"
    );
}

$wpdb = provider_database_session_fixture();
$nestedProviderBudget = provider_database_session_failure(static fn() => ProviderDatabaseSession::read_only_snapshot(
    'provider helper reentry keeps one budget',
    provider_database_read_profile(),
    static function () use ($wpdb, $statement): void {
        for ($index = 0; $index < 1025; $index++) {
            DatabaseQueryIsolation::work_unit(null, static fn() => $wpdb->query($statement));
        }
    }
));
wprism_check(
    $nestedProviderBudget instanceof DatabaseQueryIsolationViolationException
        && str_contains($nestedProviderBudget->getMessage(), 'statement-count boundary')
        && count(array_filter($wpdb->queries(), static fn(string $sql): bool => $sql === $statement)) === 1024
        && $wpdb->activeTransactionIsolation() === null,
    'provider reentry through authority-free core helpers preserves its one 1,024-statement callback budget'
);

$wpdb->resetLog();
$afterBudgetResult = ProviderDatabaseSession::read_only_snapshot(
    'provider statement budget reset',
    provider_database_read_profile(),
    static fn(): mixed => $wpdb->query($statement)
);
wprism_check(
    $afterBudgetResult === 0
        && count(array_filter($wpdb->queries(), static fn(string $sql): bool => $sql === $statement)) === 1,
    'a later bound profile receives a fresh statement and byte budget'
);

$wpdb = provider_database_session_fixture();
$largePrefix = 'SELECT provider_key FROM wp_wprism_provider_state LIMIT 1';
$largeStatement = $largePrefix . str_repeat(' ', 1048576 - strlen($largePrefix));
$byteBudgetFailure = provider_database_session_failure(
    static fn() => ProviderDatabaseSession::read_only_snapshot(
        'provider cumulative SQL budget',
        provider_database_read_profile(),
        static function () use ($wpdb, $largeStatement): null {
            for ($index = 0; $index <= 16; $index++) {
                $wpdb->query($largeStatement);
            }
            return null;
        }
    )
);
$transportedLargeStatements = count(array_filter(
    $wpdb->queries(),
    static fn(string $sql): bool => str_starts_with($sql, 'SELECT provider_key FROM wp_wprism_provider_state')
));
wprism_check(
    $byteBudgetFailure instanceof DatabaseQueryIsolationViolationException
        && str_contains($byteBudgetFailure->getMessage(), 'cumulative SQL-byte boundary')
        && $transportedLargeStatements === 16
        && $wpdb->activeTransactionIsolation() === null,
    'the first byte above the 16 MiB callback SQL budget is refused before transport'
);

$wpdb = provider_database_session_fixture();
$wpdb->resetLog();
$unboundSnapshot = provider_database_session_failure(
    static fn() => ProviderSdk::database_read_snapshot(
        'unbound SDK snapshot',
        ['wp_wprism_provider_state'],
        static fn(): null => null
    )
);
wprism_check(
    $unboundSnapshot instanceof RuntimeException
        && str_contains($unboundSnapshot->getMessage(), 'active validated manifest-provider contract')
        && $wpdb->queries() === [],
    'the public SDK cannot construct a database profile outside one active validated provider contract'
);

$bindingMethod = new ReflectionMethod(Providers::class, 'bind_manifest_runtime_contracts');
wprism_check(
    $bindingMethod->isPrivate(),
    'only the engine manifest loader can bind an exact runtime object to ProviderSdk database authority'
);
$malformedLoaderIdentity = provider_database_session_failure(
    static fn() => bind_legacy_named_mutex_probe(new LegacyNamedMutexProviderProbe(), [
        'adapter' => 'woocommerce',
        'id' => 'woocommerce-scheduler-settings',
        'sha256' => str_repeat('a', 64),
        'caller_supplied' => true,
    ])
);
wprism_check(
    $malformedLoaderIdentity instanceof LogicException
        && str_contains($malformedLoaderIdentity->getMessage(), 'malformed runtime identity'),
    'the private loader join rejects an expanded or malformed provider identity before binding it'
);

$wpdb = provider_database_session_fixture();
$directRuntimeFailure = provider_database_session_failure(
    static fn(): mixed => ProviderSdkContractProbe::runUnbound(
        ['table:wprism_provider_state'],
        ['table:wprism_provider_state'],
        static fn(): mixed => ProviderSdk::database_read_contract_snapshot(
            'direct runtime authority probe',
            static fn(): null => null
        )
    )
);
wprism_check(
    $directRuntimeFailure instanceof RuntimeException
        && $directRuntimeFailure->getMessage()
            === 'wprism: direct runtime authority probe requires an active validated manifest-provider contract'
        && $wpdb->queries() === [],
    'direct construction can exercise ordinary provider code but cannot mint its declared SDK database authority'
);
if (!$directRuntimeFailure instanceof RuntimeException
    || $directRuntimeFailure->getMessage()
        !== 'wprism: direct runtime authority probe requires an active validated manifest-provider contract'
    || $wpdb->queries() !== []) {
    wprism_check_detail(
        'direct authority refusal=' . get_debug_type($directRuntimeFailure)
        . ' message=' . ($directRuntimeFailure?->getMessage() ?? 'none')
        . ' queries=' . count($wpdb->queries())
    );
}

$boundRuntime = ProviderSdkContractProbe::runtime(
    ['table:wprism_provider_state'],
    ['table:wprism_provider_state'],
    true
);
$boundRuntimeResult = ProviderSdkContractProbe::execute(
    $boundRuntime,
    static fn(): mixed => ProviderSdk::database_read_contract_snapshot(
        'exact engine-bound runtime probe',
        static fn(): string => 'engine-bound'
    )
);
wprism_check_same(
    'engine-bound',
    $boundRuntimeResult,
    'the exact runtime object bound through the private engine join receives its canonical SDK contract'
);

$unserializedRuntime = unserialize(serialize($boundRuntime));
if (!$unserializedRuntime instanceof ProviderSdkContractProbe) {
    throw new RuntimeException('fixture could not round-trip its runtime substitute');
}
$runtimeSubstitutes = [
    'clone' => clone $boundRuntime,
    'unserialized substitute' => $unserializedRuntime,
    'same-declaration second object' => ProviderSdkContractProbe::runtime(
        ['table:wprism_provider_state'],
        ['table:wprism_provider_state']
    ),
];
foreach ($runtimeSubstitutes as $label => $runtimeSubstitute) {
    $wpdb = provider_database_session_fixture();
    $failure = provider_database_session_failure(
        static fn(): mixed => ProviderSdkContractProbe::execute(
            $runtimeSubstitute,
            static function () use ($label): mixed {
                if ($label === 'clone') {
                    return ProviderSdk::database_write_contract_transaction(
                        'cloned runtime write-authority probe',
                        static fn(): null => null,
                        static fn(null $_result): string => ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN
                    );
                }
                return ProviderSdk::database_read_contract_snapshot(
                    'runtime substitute read-authority probe',
                    static fn(): null => null
                );
            }
        )
    );
    wprism_check(
        $failure instanceof RuntimeException
            && str_contains($failure->getMessage(), 'active validated manifest-provider contract')
            && $wpdb->queries() === [],
        "a $label cannot inherit SDK database authority from identical bytes or object state"
    );
    if (!$failure instanceof RuntimeException
        || !str_contains($failure->getMessage(), 'active validated manifest-provider contract')
        || $wpdb->queries() !== []) {
        wprism_check_detail(
            "$label refusal=" . get_debug_type($failure)
            . ' message=' . ($failure?->getMessage() ?? 'none')
            . ' queries=' . count($wpdb->queries())
        );
    }
}

$wpdb = provider_database_session_fixture();
$boundAfterSubstitutes = ProviderSdkContractProbe::execute(
    $boundRuntime,
    static fn(): mixed => ProviderSdk::database_read_contract_snapshot(
        'engine-bound runtime identity retention',
        static fn(): string => 'still-bound'
    )
);
wprism_check_same(
    'still-bound',
    $boundAfterSubstitutes,
    'substitute refusals neither transfer nor clear the exact engine-bound object authority'
);

$wpdb = provider_database_session_fixture()
    ->seedTable('wp_wprism_undeclared', [['provider_key' => 'outside', 'provider_value' => 'secret']]);
$subsetCallbackRan = false;
$wpdb->resetLog();
$outsideContractSnapshot = provider_database_session_failure(
    static fn() => ProviderSdkContractProbe::run(
        ['table:wprism_provider_state'],
        [],
        static fn() => ProviderSdk::database_read_snapshot(
            'SDK requested-table subset',
            ['wp_wprism_undeclared'],
            static function () use (&$subsetCallbackRan): null {
                $subsetCallbackRan = true;
                return null;
            }
        )
    )
);
wprism_check(
    $outsideContractSnapshot instanceof RuntimeException
        && str_contains($outsideContractSnapshot->getMessage(), 'outside its active manifest-provider contract')
        && !$subsetCallbackRan
        && $wpdb->queries() === [],
    'a provider cannot request a read snapshot wider than the physical tables derived from its active contract'
);

$wpdb = provider_database_session_fixture();
$wpdb->resetLog();
$schemaProjection = ProviderSdkContractProbe::run(
    ['table:wprism_provider_state', 'table:wprism_provider_absent'],
    [],
    static fn(): mixed => ProviderSdk::database_schema_snapshot(
        'provider schema topology fixture',
        ['wp_wprism_provider_state', 'wp_wprism_provider_absent'],
        static function (array $presence) use ($wpdb): array {
            $rows = ProviderSdk::checked_get_results(
                'SELECT provider_key FROM wp_wprism_provider_state ORDER BY provider_key ASC',
                'provider schema present-table read',
                $wpdb
            );
            return ['presence' => $presence, 'rows' => $rows];
        }
    )
);
$schemaPresenceQueries = array_values(array_filter(
    $wpdb->queries(),
    static fn(string $sql): bool => preg_match('/^SELECT 1 FROM `[A-Za-z0-9_]+` LIMIT 0$/D', $sql) === 1
));
wprism_check(
    $schemaProjection === [
        'presence' => [
            'wp_wprism_provider_absent' => false,
            'wp_wprism_provider_state' => true,
        ],
        'rows' => [],
    ]
        && count($schemaPresenceQueries) === 7
        && count(array_filter(
            $wpdb->queries(),
            static fn(string $sql): bool => $sql === 'SHOW WARNINGS'
        )) === 3
        && $wpdb->activeTransactionIsolation() === null,
    'the schema SDK brackets exact resolvable/1146 topology around one profiled InnoDB projection'
);

$wpdb = provider_database_session_fixture();
$schemaRosterEscape = provider_database_session_failure(
    static fn(): mixed => ProviderSdkContractProbe::run(
        ['table:wprism_provider_state', 'table:wprism_provider_absent'],
        [],
        static fn(): mixed => ProviderSdk::database_schema_snapshot(
            'provider narrowed schema roster fixture',
            ['wp_wprism_provider_state'],
            static fn(array $_presence): mixed => ProviderSdk::checked_get_var(
                $wpdb->prepare(
                    'SHOW TABLES LIKE %s',
                    $wpdb->esc_like('wp_wprism_provider_absent')
                ),
                'provider undeclared table-presence read',
                $wpdb
            )
        )
    )
);
wprism_check(
    $schemaRosterEscape instanceof RuntimeException
        && $schemaRosterEscape->getPrevious() instanceof DatabaseQueryIsolationViolationException
        && str_contains(
            $schemaRosterEscape->getPrevious()->getMessage(),
            'table-presence read escaped its declared physical-table profile'
        )
        && $wpdb->activeTransactionIsolation() === null,
    'schema discovery grants metadata authority only for its contract-derived requested-table roster'
);

$wpdb = provider_database_session_fixture();
$absentTableRead = provider_database_session_failure(
    static fn(): mixed => ProviderSdkContractProbe::run(
        ['table:wprism_provider_state', 'table:wprism_provider_absent'],
        [],
        static fn(): mixed => ProviderSdk::database_schema_snapshot(
            'provider absent schema table fixture',
            ['wp_wprism_provider_state', 'wp_wprism_provider_absent'],
            static fn(array $_presence): array => ProviderSdk::checked_get_results(
                'SHOW FULL COLUMNS FROM `wp_wprism_provider_absent`',
                'provider absent schema table read',
                $wpdb
            )
        )
    )
);
wprism_check(
    $absentTableRead instanceof RuntimeException
        && str_contains($absentTableRead->getMessage(), 'provider checked read failed')
        && $absentTableRead->getPrevious() instanceof DatabaseQueryIsolationViolationException
        && str_contains(
            $absentTableRead->getPrevious()->getMessage(),
            'escaped its declared physical-table profile'
        )
        && $wpdb->activeTransactionIsolation() === null,
    'a declared-but-absent schema table never becomes readable physical-table authority'
);
if (!$absentTableRead instanceof RuntimeException
    || !str_contains($absentTableRead->getMessage(), 'provider checked read failed')
    || !$absentTableRead->getPrevious() instanceof DatabaseQueryIsolationViolationException
    || $wpdb->activeTransactionIsolation() !== null) {
    wprism_check_detail(
        'absent schema read refusal=' . get_debug_type($absentTableRead)
        . ' message=' . ($absentTableRead?->getMessage() ?? 'none')
        . ' previous=' . get_debug_type($absentTableRead?->getPrevious())
        . ' active=' . var_export($wpdb->activeTransactionIsolation(), true)
    );
}

$wpdb = provider_database_session_fixture();
$schemaTopologyDrift = provider_database_session_failure(
    static fn(): mixed => ProviderSdkContractProbe::run(
        ['table:wprism_provider_state', 'table:wprism_provider_absent'],
        [],
        static fn(): mixed => ProviderSdk::database_schema_snapshot(
            'provider schema topology drift fixture',
            ['wp_wprism_provider_state', 'wp_wprism_provider_absent'],
            static function (array $presence) use ($wpdb): array {
                $wpdb->seedTable('wp_wprism_provider_absent', []);
                return $presence;
            }
        )
    )
);
wprism_check(
    $schemaTopologyDrift instanceof RuntimeException
        && str_contains($schemaTopologyDrift->getMessage(), 'topology changed during')
        && $wpdb->activeTransactionIsolation() === null,
    'a table created during schema projection invalidates the bracketed topology evidence'
);

$wpdb = provider_database_session_fixture()->setTableEngine('wp_wprism_provider_state', 'MyISAM');
$schemaCallbackCalls = 0;
$schemaCallback = static function (array $presence) use (&$schemaCallbackCalls): array {
    $schemaCallbackCalls++;
    return $presence;
};
$schemaNonTransactional = provider_database_session_failure(
    static fn(): mixed => ProviderSdkContractProbe::run(
        ['table:wprism_provider_state'],
        [],
        static fn(): mixed => ProviderSdk::database_schema_snapshot(
            'provider nontransactional schema fixture',
            ['wp_wprism_provider_state'],
            $schemaCallback
        )
    )
);
wprism_check(
    $schemaNonTransactional instanceof RuntimeException
        && str_contains($schemaNonTransactional->getMessage(), 'InnoDB required')
        && $schemaCallbackCalls === 0
        && $wpdb->activeTransactionIsolation() === null,
    'a present non-InnoDB table cannot back a consistent provider schema projection'
);

$wpdb = FakeWpdb::install()->seedTable('wp_wprism_provider_state', [])->enableInformationSchema();
$unknownEngineCallbackCalls = 0;
$unknownEngineCallback = static function () use (&$unknownEngineCallbackCalls): null {
    $unknownEngineCallbackCalls++;
    return null;
};
$unknownEngine = provider_database_session_failure(
    static fn(): mixed => ProviderDatabaseSession::read_only_snapshot(
        'provider unknown engine fixture',
        provider_database_read_profile(),
        $unknownEngineCallback
    )
);
wprism_check(
    $unknownEngine instanceof RuntimeException
        && str_contains($unknownEngine->getMessage(), 'unknown engine: wp_wprism_provider_state (engine: NULL/unknown)')
        && $unknownEngineCallbackCalls === 0
        && $wpdb->activeTransactionIsolation() === null,
    'row existence without a declared engine still refuses before the provider callback'
);

$wpdb = provider_database_session_fixture();
ProviderDatabaseSession::read_only_snapshot(
    'provider explicit engine fixture',
    provider_database_read_profile(),
    $unknownEngineCallback
);
$knownEngineProjection = ProviderSdkContractProbe::run(
    ['table:wprism_provider_state'],
    [],
    static fn(): mixed => ProviderSdk::database_schema_snapshot(
        'provider explicit schema engine fixture',
        ['wp_wprism_provider_state'],
        $schemaCallback
    )
);
wprism_check(
    $unknownEngineCallbackCalls === 1 && $schemaCallbackCalls === 1
        && $knownEngineProjection === ['wp_wprism_provider_state' => true]
        && $wpdb->activeTransactionIsolation() === null,
    'the same callback spies run once after the owned table explicitly proves InnoDB'
);

$wpdb = provider_database_session_fixture();
$wpdb->resetLog();
$nestedSchemaProjection = ProviderSdkContractProbe::run(
    ['table:wprism_provider_state'],
    [],
    static fn(): mixed => ProviderSdk::database_read_contract_snapshot(
        'provider outer fresh-observer fixture',
        static fn(): mixed => ProviderSdk::database_schema_snapshot(
            'provider nested schema fixture',
            ['wp_wprism_provider_state'],
            static fn(array $presence): array => $presence
        )
    )
);
$nestedSchemaQueries = $wpdb->queries();
wprism_check(
    $nestedSchemaProjection === ['wp_wprism_provider_state' => true]
        && count(array_filter(
            $nestedSchemaQueries,
            static fn(string $sql): bool => $sql === 'START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT'
        )) === 1
        && count(array_filter(
            $nestedSchemaQueries,
            static fn(string $sql): bool => $sql === 'ROLLBACK AND NO CHAIN NO RELEASE'
        )) === 1
        && count(array_filter(
            $nestedSchemaQueries,
            static fn(string $sql): bool => $sql === 'SELECT 1 FROM `wp_wprism_provider_state` LIMIT 0'
        )) === 3
        && !in_array('SHOW WARNINGS', $nestedSchemaQueries, true)
        && $wpdb->activeTransactionIsolation() === null,
    'schema evidence reuses one complete read-only fresh-observer profile without nesting a transaction'
);

$wpdb = provider_database_session_fixture();
$nestedWritableCallbackRan = false;
$nestedWritableSchema = provider_database_session_failure(
    static fn(): mixed => ProviderSdkContractProbe::run(
        ['table:wprism_provider_state'],
        ['table:wprism_provider_state'],
        static fn(): mixed => ProviderSdk::database_write_contract_transaction(
            'provider outer writable schema fixture',
            static fn(): mixed => ProviderSdk::database_schema_snapshot(
                'provider nested writable schema fixture',
                ['wp_wprism_provider_state'],
                static function (array $presence) use (&$nestedWritableCallbackRan): array {
                    $nestedWritableCallbackRan = true;
                    return $presence;
                }
            ),
            static fn(mixed $_result): string => ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN
        )
    )
);
wprism_check(
    $nestedWritableSchema instanceof RuntimeException
        && str_contains($nestedWritableSchema->getMessage(), 'cannot reuse a writable or unbound database profile')
        && !$nestedWritableCallbackRan
        && $wpdb->activeTransactionIsolation() === null,
    'schema evidence cannot turn an active writable profile into a nested read authority'
);

$wpdb = provider_database_session_fixture();
$nestedContractFailure = null;
$outerContractResult = ProviderSdkContractProbe::run(
    ['table:wprism_provider_state'],
    [],
    static function () use ($wpdb, &$nestedContractFailure): mixed {
        $nestedContractFailure = provider_database_session_failure(
            static fn() => ProviderSdkContractProbe::run(
                ['table:wprism_provider_state'],
                [],
                static fn(): null => null
            )
        );
        return ProviderSdk::database_read_snapshot(
            'outer SDK contract after nested refusal',
            ['wp_wprism_provider_state'],
            static fn(): mixed => $wpdb->query(
                'SELECT provider_key FROM wp_wprism_provider_state LIMIT 1'
            )
        );
    }
);
$afterContractFailure = provider_database_session_failure(
    static fn() => ProviderSdk::database_read_snapshot(
        'SDK contract cleanup proof',
        ['wp_wprism_provider_state'],
        static fn(): null => null
    )
);
wprism_check(
    $nestedContractFailure instanceof RuntimeException
        && str_contains($nestedContractFailure->getMessage(), 'cannot re-enter')
        && $outerContractResult === 0
        && $afterContractFailure instanceof RuntimeException
        && str_contains($afterContractFailure->getMessage(), 'active validated manifest-provider contract')
        && $wpdb->activeTransactionIsolation() === null,
    'provider contract authority is non-reentrant, survives a caught nested refusal, and is cleared after invocation'
);

$wpdb = provider_database_session_fixture()->seedTable('wp_wprism_provider_state', [
    ['provider_key' => 'keep', 'provider_value' => 'one'],
    ['provider_key' => 'remove', 'provider_value' => 'two'],
]);
$wpdb->resetLog();
$standaloneDelete = provider_database_session_failure(
    static fn() => ProviderSdkContractProbe::run(
        [],
        ['table:wprism_provider_state'],
        static fn(): int => ProviderSdk::database_delete(
            'wp_wprism_provider_state',
            ['provider_key' => 'remove'],
            'standalone provider structured delete'
        )
    )
);
wprism_check(
    $standaloneDelete instanceof RuntimeException
        && str_contains($standaloneDelete->getMessage(), 'active bound provider database write profile')
        && count(provider_database_session_rows($wpdb)) === 2
        && $wpdb->queries() === [],
    'structured provider DELETE cannot fall through to Db standalone transaction mode'
);

$wpdb = provider_database_session_fixture()->seedTable('wp_wprism_provider_state', [
    ['provider_key' => 'keep', 'provider_value' => 'one'],
    ['provider_key' => 'remove', 'provider_value' => 'two'],
]);
$readOnlyDelete = provider_database_session_failure(
    static fn() => ProviderSdkContractProbe::run(
        [],
        ['table:wprism_provider_state'],
        static fn() => ProviderSdk::database_read_snapshot(
            'read-only provider delete attempt',
            ['wp_wprism_provider_state'],
            static fn(): int => ProviderSdk::database_delete(
                'wp_wprism_provider_state',
                ['provider_key' => 'remove'],
                'read-only provider structured delete'
            )
        )
    )
);
wprism_check(
    $readOnlyDelete instanceof DatabaseQueryIsolationViolationException
        && count(provider_database_session_rows($wpdb)) === 2
        && count(array_filter(
            $wpdb->queries(),
            static fn(string $sql): bool => str_starts_with($sql, 'DELETE FROM')
        )) === 0
        && $wpdb->activeTransactionIsolation() === null,
    'a contract-writable table still cannot be deleted through a bound read-only profile'
);

$wpdb = provider_database_session_fixture()
    ->seedTable('wp_wprism_provider_state', [['provider_key' => 'keep', 'provider_value' => 'one']])
    ->seedTable('wp_wprism_undeclared', [['provider_key' => 'outside', 'provider_value' => 'two']]);
$undeclaredTypedDelete = provider_database_session_failure(
    static fn() => ProviderSdkContractProbe::run(
        [],
        ['table:wprism_provider_state'],
        static fn() => ProviderSdk::database_write_contract_transaction(
            'typed delete declared profile',
            static fn(): int => ProviderSdk::database_delete_all(
                'wp_wprism_undeclared',
                'typed delete outside declared profile'
            ),
            static fn(int $_affected): string => ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN
        )
    )
);
wprism_check(
    $undeclaredTypedDelete instanceof DatabaseQueryIsolationViolationException
        && $wpdb->rows('wp_wprism_undeclared') === [
            ['provider_key' => 'outside', 'provider_value' => 'two'],
        ]
        && count(array_filter(
            $wpdb->queries(),
            static fn(string $sql): bool => str_starts_with($sql, 'DELETE FROM `wp_wprism_undeclared`')
        )) === 0
        && $wpdb->activeTransactionIsolation() === null,
    'typed whole-table deletion refuses a table absent from the active contract write profile before DML'
);

$wpdb = provider_database_session_fixture()->seedTable('wp_wprism_provider_state', [
    ['provider_key' => 'keep', 'provider_value' => 'one'],
]);
$emptyTypedPredicate = provider_database_session_failure(
    static fn() => ProviderSdkContractProbe::run(
        [],
        ['table:wprism_provider_state'],
        static fn() => ProviderSdk::database_write_contract_transaction(
            'empty typed delete predicate',
            static fn(): int => ProviderSdk::database_delete(
                'wp_wprism_provider_state',
                [],
                'empty provider structured delete'
            ),
            static fn(int $_affected): string => ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN
        )
    )
);
wprism_check(
    $emptyTypedPredicate instanceof InvalidArgumentException
        && str_contains($emptyTypedPredicate->getMessage(), 'empty mutation predicate')
        && count(provider_database_session_rows($wpdb)) === 1
        && count(array_filter(
            $wpdb->queries(),
            static fn(string $sql): bool => str_starts_with($sql, 'DELETE FROM')
        )) === 0
        && $wpdb->activeTransactionIsolation() === null,
    'the predicate-bearing provider API cannot disguise a whole-table delete as an empty structured where map'
);

$wpdb = provider_database_session_fixture()
    ->seedTable('wp_wprism_provider_state', [
        ['provider_key' => 'keep', 'provider_value' => 'one'],
        ['provider_key' => 'remove', 'provider_value' => 'two'],
    ])
    ->seedTable('wp_wprism_provider_archive', [
        ['provider_key' => 'old-one', 'provider_value' => 'one'],
        ['provider_key' => 'old-two', 'provider_value' => 'two'],
    ])
    ->setTableEngine('wp_wprism_provider_archive', 'InnoDB');
$typedDeleteResult = ProviderSdkContractProbe::run(
    [],
    ['table:wprism_provider_archive', 'table:wprism_provider_state'],
    static fn() => ProviderSdk::database_write_contract_transaction(
        'successful typed provider deletes',
        static fn(): array => [
            ProviderSdk::database_delete(
                'wp_wprism_provider_state',
                ['provider_key' => 'remove'],
                'delete one provider row',
                '%s'
            ),
            ProviderSdk::database_delete_all(
                'wp_wprism_provider_archive',
                'delete all provider archive rows'
            ),
        ],
        static fn(array $_affected): string => ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN
    )
);
$typedDeleteQueries = array_values(array_filter(
    $wpdb->queries(),
    static fn(string $sql): bool => str_starts_with($sql, 'DELETE FROM')
));
wprism_check(
    $typedDeleteResult === [1, 2]
        && provider_database_session_rows($wpdb) === [
            ['provider_key' => 'keep', 'provider_value' => 'one'],
        ]
        && $wpdb->rows('wp_wprism_provider_archive') === []
        && count($typedDeleteQueries) === 2
        && array_reduce(
            $typedDeleteQueries,
            static fn(bool $bounded, string $sql): bool => $bounded
                && str_contains($sql, 'CONNECTION_ID()'),
            true
        )
        && !method_exists(ProviderSdk::class, 'database_write_transaction')
        && $wpdb->activeTransactionIsolation() === null,
    'contract-derived write profiles authorize only structured predicate/all-row deletes under one Db authority'
);

$wpdb = provider_database_session_fixture()
    ->seedTable('wp_wprism_undeclared', [['provider_key' => 'outside', 'provider_value' => 'secret']]);
$undeclaredRead = provider_database_session_failure(
    static fn() => ProviderSdkContractProbe::run(
        ['table:wprism_provider_state'],
        [],
        static fn() => ProviderSdk::database_read_snapshot(
            'SDK declared read scope',
            ['wp_wprism_provider_state'],
            static fn(): array => $wpdb->get_results(
                'SELECT provider_key, provider_value FROM wp_wprism_undeclared',
                ARRAY_A
            )
        )
    )
);
wprism_check(
    $undeclaredRead instanceof DatabaseQueryIsolationViolationException
        && $wpdb->activeTransactionIsolation() === null,
    'the public provider SDK refuses an undeclared physical-table read before transport'
);
if (!$undeclaredRead instanceof DatabaseQueryIsolationViolationException) {
    wprism_check_detail($undeclaredRead === null
        ? 'no exception returned'
        : get_class($undeclaredRead) . ': ' . $undeclaredRead->getMessage()
            . (($undeclaredRead->getPrevious() ?? null) instanceof Throwable
                ? ' previous=' . get_class($undeclaredRead->getPrevious())
                    . ': ' . $undeclaredRead->getPrevious()->getMessage()
                : ''));
}

$wpdb = provider_database_session_fixture()
    ->seedTable('wp_wprism_undeclared', []);
$continuedAfterScopeViolation = false;
$undeclaredWrite = provider_database_session_failure(
    static function () use ($wpdb, &$continuedAfterScopeViolation): mixed {
        return ProviderSdkContractProbe::run(
            [],
            ['table:wprism_provider_state'],
            static fn(): mixed => ProviderSdk::database_write_contract_transaction(
                'SDK declared mutation scope',
                static function () use ($wpdb, &$continuedAfterScopeViolation): null {
                    try {
                        $wpdb->query(
                            "INSERT INTO wp_wprism_undeclared (provider_key, provider_value) VALUES ('outside', 'write')"
                        );
                    } catch (DatabaseQueryIsolationViolationException) {
                        try {
                            $wpdb->query(
                                "INSERT INTO wp_wprism_provider_state (provider_key, provider_value) VALUES ('derived', 'late')"
                            );
                            $continuedAfterScopeViolation = true;
                        } catch (DatabaseQueryIsolationViolationException) {
                        }
                        throw new DatabaseQueryIsolationViolationException('fixture undeclared mutation refused');
                    }
                    return null;
                },
                static fn(null $_result): string => ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN
            )
        );
    }
);
wprism_check(
    $undeclaredWrite instanceof DatabaseQueryIsolationViolationException
        && !$continuedAfterScopeViolation
        && provider_database_session_rows($wpdb) === []
        && $wpdb->rows('wp_wprism_undeclared') === []
        && $wpdb->activeTransactionIsolation() === null,
    'declared table A cannot authorize a write to B or continuation after the scope violation'
);

$callbackCalls = 0;
$wpdb = provider_database_session_fixture()
    ->seedTable('wp_wprism_provider_child', [])
    ->addForeignKey(
        'fk_provider_child_state',
        'wp_wprism_provider_child',
        'wp_wprism_provider_state',
        'CASCADE',
        'SET NULL'
    );
$escapingForeignKey = provider_database_session_failure(
    static function () use (&$callbackCalls): mixed {
        return ProviderDatabaseSession::repeatable_read_write(
            'escaping foreign-key destination',
            provider_database_write_profile(),
            static function () use (&$callbackCalls): null {
                $callbackCalls++;
                return null;
            },
            static fn(null $_result): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN
        );
    }
);
wprism_check(
    $escapingForeignKey instanceof RuntimeException
        && str_contains($escapingForeignKey->getMessage(), 'referential action escapes')
        && $callbackCalls === 0
        && $wpdb->activeTransactionIsolation() === null,
    'CASCADE/SET NULL cannot make an undeclared child table an implicit mutation destination'
);

$callbackCalls = 0;
$wpdb = provider_database_session_fixture()->addForeignKey(
    'fk_external_schema_child',
    'wp_wprism_provider_state',
    'wp_wprism_provider_state',
    'CASCADE',
    'RESTRICT',
    false
);
$externalSchemaForeignKey = provider_database_session_failure(
    static function () use (&$callbackCalls): mixed {
        return ProviderDatabaseSession::repeatable_read_write(
            'cross-schema foreign-key destination',
            provider_database_write_profile(),
            static function () use (&$callbackCalls): null {
                $callbackCalls++;
                return null;
            },
            static fn(null $_result): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN
        );
    }
);
wprism_check(
    $externalSchemaForeignKey instanceof RuntimeException
        && str_contains($externalSchemaForeignKey->getMessage(), 'referential action escapes')
        && $callbackCalls === 0
        && $wpdb->activeTransactionIsolation() === null,
    'a same-named child in another schema cannot masquerade as a declared mutation destination'
);

$callbackCalls = 0;
$wpdb = provider_database_session_fixture()
    ->seedTable('wp_wprism_provider_child', [])
    ->seedTable('wp_wprism_provider_grandchild', [])
    ->setTableEngine('wp_wprism_provider_child', 'InnoDB')
    ->setTableEngine('wp_wprism_provider_grandchild', 'InnoDB')
    ->addForeignKey(
        'fk_provider_child_state',
        'wp_wprism_provider_child',
        'wp_wprism_provider_state',
        'CASCADE'
    )
    ->addForeignKey(
        'fk_provider_grandchild_child',
        'wp_wprism_provider_grandchild',
        'wp_wprism_provider_child',
        'RESTRICT',
        'SET NULL'
    );
$closedForeignKeyResult = ProviderDatabaseSession::repeatable_read_write(
    'closed foreign-key destinations',
    new NativeDatabaseProfile([], [
        'wp_wprism_provider_state',
        'wp_wprism_provider_child',
        'wp_wprism_provider_grandchild',
    ]),
    static function () use (&$callbackCalls): string {
        $callbackCalls++;
        return 'closed';
    },
    static fn(string $_result): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN
);
wprism_check(
    $closedForeignKeyResult === 'closed'
        && $callbackCalls === 1
        && $wpdb->activeTransactionIsolation() === null,
    'a transitive referential-action graph is admitted only when every destination is declared and transactional'
);

foreach ([
    'MySQL' => ['source' => 'INNODB_FOREIGN', 'schema_query' => 'SELECT DATABASE()'],
    'MariaDB' => [
        'source' => 'INNODB_SYS_FOREIGN',
        'schema_query' => 'SELECT CAST(CONVERT(DATABASE() USING filename) AS BINARY)',
    ],
] as $family => $metadata) {
    $wpdb = provider_database_session_fixture()->addForeignKey(
        'fk_provider_state_self',
        'wp_wprism_provider_state',
        'wp_wprism_provider_state',
        'CASCADE'
    );
    $wpdb->dbname = 'wordpress-db';
    if ($family === 'MariaDB') {
        $wpdb->setServerVersion('11.8.8-MariaDB')
            ->returnNextGetResultsAs(
            [['TABLE_NAME' => $metadata['source']]],
            "WHERE BINARY TABLE_SCHEMA = BINARY 'information_schema'"
        );
    }
    $result = ProviderDatabaseSession::repeatable_read_write(
        "$family foreign-key dictionary",
        provider_database_write_profile(),
        static fn(): string => 'closed',
        static fn(string $_result): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN
    );
    wprism_check(
        $result === 'closed'
            && in_array($metadata['schema_query'], $wpdb->queries(), true)
            && str_contains(
                implode("\n", $wpdb->queries()),
                'FROM information_schema.' . $metadata['source']
            )
            && $wpdb->activeTransactionIsolation() === null,
        "$family resolves its exact InnoDB foreign-key dictionary and schema-name representation"
    );
}

$callbackCalls = 0;
$wpdb = provider_database_session_fixture()
    ->seedTable('wp_wprism_provider_child', [])
    ->setTableEngine('wp_wprism_provider_child', 'MyISAM')
    ->addForeignKey(
        'fk_provider_child_state',
        'wp_wprism_provider_child',
        'wp_wprism_provider_state',
        'CASCADE'
    );
$nonTransactionalForeignKey = provider_database_session_failure(
    static function () use (&$callbackCalls): mixed {
        return ProviderDatabaseSession::repeatable_read_write(
            'nontransactional foreign-key destination',
            new NativeDatabaseProfile([], ['wp_wprism_provider_state', 'wp_wprism_provider_child']),
            static function () use (&$callbackCalls): null {
                $callbackCalls++;
                return null;
            },
            static fn(null $_result): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN
        );
    }
);
wprism_check(
    $nonTransactionalForeignKey instanceof RuntimeException
        && str_contains($nonTransactionalForeignKey->getMessage(), 'InnoDB required')
        && $callbackCalls === 0
        && $wpdb->activeTransactionIsolation() === null,
    'a declared foreign-key destination still refuses when its storage engine cannot roll back'
);

$callbackCalls = 0;
$wpdb = provider_database_session_fixture()->setForeignKeyMetadataVisible(false);
$hiddenForeignKeys = provider_database_session_failure(
    static function () use (&$callbackCalls): mixed {
        return ProviderDatabaseSession::repeatable_read_write(
            'hidden foreign-key metadata',
            provider_database_write_profile(),
            static function () use (&$callbackCalls): null {
                $callbackCalls++;
                return null;
            },
            static fn(null $_result): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN
        );
    }
);
wprism_check(
    $hiddenForeignKeys instanceof RuntimeException
        && str_contains($hiddenForeignKeys->getMessage(), 'lacks direct global PROCESS foreign-key metadata authority')
        && $callbackCalls === 0
        && $wpdb->activeTransactionIsolation() === null,
    'an empty foreign-key census is not trusted without direct global PROCESS metadata authority'
);

$foreignKeyRow = [
    'CONSTRAINT_NAME' => 'wordpress/fk_provider_self',
    'TABLE_NAME' => 'wp_wprism_provider_state',
    'REFERENCED_TABLE_NAME' => 'wp_wprism_provider_state',
    'DESTINATION_IN_CURRENT_SCHEMA' => '1',
    'TYPE' => '4',
];
$callbackCalls = 0;
$wpdb = provider_database_session_fixture()->returnNextGetResultsAs(
    [],
    "WHERE BINARY TABLE_SCHEMA = BINARY 'information_schema'"
);
$missingForeignKeySource = provider_database_session_failure(
    static function () use (&$callbackCalls): mixed {
        return ProviderDatabaseSession::repeatable_read_write(
            'missing foreign-key source',
            provider_database_write_profile(),
            static function () use (&$callbackCalls): null {
                $callbackCalls++;
                return null;
            },
            static fn(null $_result): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN
        );
    }
);
wprism_check(
    $missingForeignKeySource instanceof RuntimeException
        && str_contains($missingForeignKeySource->getMessage(), 'metadata source is unavailable, mismatched, or ambiguous')
        && $callbackCalls === 0
        && $wpdb->activeTransactionIsolation() === null,
    'a missing engine-specific foreign-key metadata source refuses before mutation'
);

$callbackCalls = 0;
$wpdb = provider_database_session_fixture()->returnNextGetResultsAs(
    [
        ['TABLE_NAME' => 'INNODB_FOREIGN'],
        ['TABLE_NAME' => 'INNODB_SYS_FOREIGN'],
    ],
    "WHERE BINARY TABLE_SCHEMA = BINARY 'information_schema'"
);
$ambiguousForeignKeySource = provider_database_session_failure(
    static function () use (&$callbackCalls): mixed {
        return ProviderDatabaseSession::repeatable_read_write(
            'ambiguous foreign-key source',
            provider_database_write_profile(),
            static function () use (&$callbackCalls): null {
                $callbackCalls++;
                return null;
            },
            static fn(null $_result): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN
        );
    }
);
wprism_check(
    $ambiguousForeignKeySource instanceof RuntimeException
        && str_contains($ambiguousForeignKeySource->getMessage(), 'metadata source is unavailable, mismatched, or ambiguous')
        && $callbackCalls === 0
        && $wpdb->activeTransactionIsolation() === null,
    'multiple engine-specific foreign-key metadata sources remain ambiguous and fail closed'
);

$callbackCalls = 0;
$wpdb = provider_database_session_fixture()
    ->setServerVersion('11.8.8-MariaDB')
    ->returnNextGetResultsAs(
        [['TABLE_NAME' => 'INNODB_FOREIGN']],
        "WHERE BINARY TABLE_SCHEMA = BINARY 'information_schema'"
    );
$wrongFamilyForeignKeySource = provider_database_session_failure(
    static function () use (&$callbackCalls): mixed {
        return ProviderDatabaseSession::repeatable_read_write(
            'wrong-family foreign-key source',
            provider_database_write_profile(),
            static function () use (&$callbackCalls): null {
                $callbackCalls++;
                return null;
            },
            static fn(null $_result): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN
        );
    }
);
wprism_check(
    $wrongFamilyForeignKeySource instanceof RuntimeException
        && str_contains($wrongFamilyForeignKeySource->getMessage(), 'MariaDB')
        && str_contains($wrongFamilyForeignKeySource->getMessage(), 'mismatched')
        && $callbackCalls === 0,
    'a lone foreign-key dictionary from the wrong database family cannot authorize mutation'
);

$callbackCalls = 0;
$wpdb = provider_database_session_fixture()->returnNextGetResultsAs(
    [$foreignKeyRow, $foreignKeyRow],
    'FROM information_schema.INNODB_FOREIGN'
);
$duplicateForeignKey = provider_database_session_failure(
    static function () use (&$callbackCalls): mixed {
        return ProviderDatabaseSession::repeatable_read_write(
            'duplicate foreign-key metadata',
            provider_database_write_profile(),
            static function () use (&$callbackCalls): null {
                $callbackCalls++;
                return null;
            },
            static fn(null $_result): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN
        );
    }
);
wprism_check(
    $duplicateForeignKey instanceof RuntimeException
        && str_contains($duplicateForeignKey->getMessage(), 'duplicate evidence')
        && $callbackCalls === 0
        && $wpdb->activeTransactionIsolation() === null,
    'duplicate InnoDB foreign-key dictionary metadata refuses before a provider mutation callback'
);

$callbackCalls = 0;
$malformedForeignKeyRow = $foreignKeyRow;
unset($malformedForeignKeyRow['TYPE']);
$wpdb = provider_database_session_fixture()->returnNextGetResultsAs(
    [$malformedForeignKeyRow],
    'FROM information_schema.INNODB_FOREIGN'
);
$malformedForeignKey = provider_database_session_failure(
    static function () use (&$callbackCalls): mixed {
        return ProviderDatabaseSession::repeatable_read_write(
            'malformed foreign-key metadata',
            provider_database_write_profile(),
            static function () use (&$callbackCalls): null {
                $callbackCalls++;
                return null;
            },
            static fn(null $_result): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN
        );
    }
);
wprism_check(
    $malformedForeignKey instanceof RuntimeException
        && str_contains($malformedForeignKey->getMessage(), 'malformed evidence')
        && $callbackCalls === 0
        && $wpdb->activeTransactionIsolation() === null,
    'malformed InnoDB foreign-key dictionary metadata refuses before a provider mutation callback'
);

$callbackCalls = 0;
$wpdb = provider_database_session_fixture()->returnNextGetResultsAs(
    array_fill(0, 4097, $foreignKeyRow),
    'FROM information_schema.INNODB_FOREIGN'
);
$truncatedForeignKeyCensus = provider_database_session_failure(
    static function () use (&$callbackCalls): mixed {
        return ProviderDatabaseSession::repeatable_read_write(
            'truncated foreign-key census',
            provider_database_write_profile(),
            static function () use (&$callbackCalls): null {
                $callbackCalls++;
                return null;
            },
            static fn(null $_result): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN
        );
    }
);
wprism_check(
    $truncatedForeignKeyCensus instanceof RuntimeException
        && str_contains($truncatedForeignKeyCensus->getMessage(), 'census could not be proven')
        && $callbackCalls === 0
        && $wpdb->activeTransactionIsolation() === null,
    'a bounded foreign-key census refuses rather than trusting truncated metadata'
);

$wpdb = provider_database_session_fixture();
$outfile = provider_database_session_failure(
    static fn() => ProviderSdkContractProbe::run(
        ['table:wprism_provider_state'],
        [],
        static fn() => ProviderSdk::database_read_snapshot(
            'SDK irreversible read refusal',
            ['wp_wprism_provider_state'],
            static fn(): mixed => $wpdb->query("SELECT provider_key FROM wp_wprism_provider_state INTO OUTFILE '/tmp/wprism-leak'")
        )
    )
);
wprism_check(
    $outfile instanceof DatabaseQueryIsolationViolationException
        && $wpdb->activeTransactionIsolation() === null,
    'SELECT INTO OUTFILE is quarantined before the database transport'
);

$wpdb = provider_database_session_fixture();
$groupedProjection =
    'SELECT COUNT(*) AS row_count, '
    . 'COALESCE(SUM(COALESCE(OCTET_LENGTH(`provider_value`), 0)), 0) AS total_bytes '
    . 'FROM `wp_wprism_provider_state` '
    . 'WHERE (COALESCE(OCTET_LENGTH(`provider_value`), 0)) <= 1048576';
$groupedFailure = provider_database_session_failure(
    static fn() => ProviderSdkContractProbe::run(
        ['table:wprism_provider_state'],
        [],
        static fn() => ProviderSdk::database_read_snapshot(
            'SDK grouped aggregate projection',
            ['wp_wprism_provider_state'],
            static fn(): mixed => $wpdb->query($groupedProjection)
        )
    )
);
wprism_check(
    $groupedFailure === null && in_array($groupedProjection, $wpdb->queries(), true),
    'closed SQL profiling distinguishes WHERE expression groups from stored-function calls'
);

$profileGrammarRefusals = [
    'alternate INSERT TABLE source' => [
        'write' => true,
        'sql' => 'INSERT INTO wp_wprism_provider_state TABLE wp_wprism_undeclared',
    ],
    'multi-table DELETE USING source' => [
        'write' => true,
        'sql' => 'DELETE FROM wp_wprism_provider_state, wp_wprism_undeclared '
            . 'USING wp_wprism_provider_state, wp_wprism_undeclared '
            . 'WHERE wp_wprism_provider_state.provider_key = wp_wprism_undeclared.provider_key',
    ],
    'comma read source' => [
        'write' => false,
        'sql' => 'SELECT * FROM wp_wprism_provider_state declared, wp_wprism_undeclared hidden',
    ],
    'parenthesized table-reference read source' => [
        'write' => false,
        'sql' => 'SELECT hidden.provider_value FROM '
            . '(wp_wprism_undeclared hidden JOIN wp_wprism_provider_state declared '
            . 'ON hidden.provider_key = declared.provider_key)',
    ],
    'parenthesized quoted-keyword table source' => [
        'write' => false,
        'sql' => 'SELECT provider_value FROM (`SELECT`)',
    ],
    'nested TABLE query-expression source' => [
        'write' => false,
        'sql' => 'SELECT provider_key FROM wp_wprism_provider_state '
            . 'WHERE EXISTS (TABLE wp_wprism_undeclared)',
    ],
    'parenthesized EXCEPT TABLE source' => [
        'write' => false,
        'sql' => '(SELECT provider_key FROM wp_wprism_provider_state) '
            . 'EXCEPT TABLE wp_wprism_undeclared',
    ],
    'nested INTERSECT TABLE source' => [
        'write' => false,
        'sql' => 'SELECT provider_key FROM wp_wprism_provider_state WHERE EXISTS '
            . '((SELECT provider_key FROM wp_wprism_provider_state) '
            . 'INTERSECT TABLE wp_wprism_undeclared)',
    ],
    'sequence increment expression' => [
        'write' => false,
        'sql' => 'SELECT NEXT VALUE FOR wp_wprism_undeclared_sequence',
    ],
    'sequence previous-value expression' => [
        'write' => false,
        'sql' => 'SELECT PREVIOUS VALUE FOR wp_wprism_undeclared_sequence',
    ],
    'sequence expression inside a profiled update' => [
        'write' => true,
        'sql' => 'UPDATE wp_wprism_provider_state '
            . 'SET provider_value = NEXT VALUE FOR wp_wprism_undeclared_sequence',
    ],
    'STRAIGHT_JOIN read source' => [
        'write' => false,
        'sql' => 'SELECT * FROM wp_wprism_provider_state STRAIGHT_JOIN wp_wprism_undeclared',
    ],
    'unreviewed stored function' => [
        'write' => false,
        'sql' => 'SELECT wprism_fixture_side_effect()',
    ],
    'quoted built-in-name stored function' => [
        'write' => false,
        'sql' => 'SELECT `COUNT`()',
    ],
    'spaced built-in-name stored function' => [
        'write' => false,
        'sql' => 'SELECT COUNT ()',
    ],
    'schema-qualified WHERE stored function' => [
        'write' => false,
        'sql' => 'SELECT wprism_private.WHERE()',
    ],
    'schema-qualified VALUE stored function' => [
        'write' => false,
        'sql' => 'SELECT wprism_private.VALUE()',
    ],
    'schema-qualified EXISTS stored function' => [
        'write' => false,
        'sql' => 'SELECT wprism_private.EXISTS()',
    ],
    'schema-qualified UNION stored function' => [
        'write' => false,
        'sql' => 'SELECT wprism_private.UNION()',
    ],
    'EXPLAIN ANALYZE execution' => [
        'write' => false,
        'sql' => 'EXPLAIN ANALYZE SELECT * FROM wp_wprism_provider_state',
    ],
    'unescaped SHOW TABLES wildcard presence' => [
        'write' => false,
        'sql' => "SHOW TABLES LIKE 'wp_wprism_provider_state'",
    ],
    'unescaped SHOW TABLE STATUS wildcard presence' => [
        'write' => false,
        'sql' => "SHOW TABLE STATUS LIKE 'wp_wprism_provider_state'",
    ],
    'SHOW TABLES database operand' => [
        'write' => false,
        'sql' => 'SHOW TABLES FROM wp_wprism_provider_state',
    ],
    'SHOW TRIGGERS database operand' => [
        'write' => false,
        'sql' => 'SHOW TRIGGERS FROM wp_wprism_provider_state',
    ],
    'SHOW OPEN TABLES database operand' => [
        'write' => false,
        'sql' => 'SHOW OPEN TABLES FROM wp_wprism_provider_state',
    ],
];
foreach ($profileGrammarRefusals as $label => $case) {
    $wpdb = provider_database_session_fixture()->seedTable('wp_wprism_undeclared', []);
    $sql = $case['sql'];
    $failure = provider_database_session_failure(
        static function () use ($wpdb, $sql, $case, $label): mixed {
            $query = static fn(): mixed => $wpdb->query($sql);
            return ProviderSdkContractProbe::run(
                $case['write'] ? [] : ['table:wprism_provider_state'],
                $case['write'] ? ['table:wprism_provider_state'] : [],
                static fn(): mixed => $case['write']
                    ? ProviderSdk::database_write_contract_transaction(
                        "profile grammar $label",
                        $query,
                        static fn(mixed $_result): string => ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN
                    )
                    : ProviderSdk::database_read_snapshot(
                        "profile grammar $label",
                        ['wp_wprism_provider_state'],
                        $query
                    )
            );
        }
    );
    wprism_check(
        $failure instanceof DatabaseQueryIsolationViolationException
            && !in_array($sql, $wpdb->queries(), true)
            && $wpdb->activeTransactionIsolation() === null,
        "$label is outside the closed provider SQL grammar and never reaches transport"
    );
}

$wpdb = provider_database_session_fixture();
$callbackFailure = provider_database_session_failure(
    static fn() => ProviderDatabaseSession::read_only_snapshot(
        'failing provider projection',
        provider_database_read_profile(),
        static fn() => throw new RuntimeException('fixture projection failure')
    )
);
wprism_check(
    $callbackFailure instanceof RuntimeException
        && $callbackFailure->getMessage() === 'fixture projection failure'
        && in_array('ROLLBACK AND NO CHAIN NO RELEASE', $wpdb->queries(), true)
        && $wpdb->activeTransactionIsolation() === null,
    'a provider read failure preserves its cause after exact read-only rollback'
);

$wpdb = provider_database_session_fixture();
$earlyReadCommitFailure = provider_database_session_failure(
    static fn() => ProviderDatabaseSession::read_only_snapshot(
        'early-commit provider projection',
        provider_database_read_profile(),
        static function () use ($wpdb): string {
            $wpdb->simulateExternalTransactionControl('COMMIT AND CHAIN NO RELEASE');
            return 'projection from a replacement snapshot';
        }
    )
);
wprism_check(
    $earlyReadCommitFailure instanceof DatabaseTransactionOutcomeException
        && str_contains($earlyReadCommitFailure->transactionContext, 'read callback changed')
        && $wpdb->activeTransactionIsolation() === null,
    'a read callback cannot commit and continue from a replacement snapshot'
);

$wpdb = provider_database_session_fixture();
$earlyWriteCommitFailure = provider_database_session_failure(
    static fn() => ProviderDatabaseSession::repeatable_read_write(
        'early-commit provider mutation',
        provider_database_write_profile(),
        static function () use ($wpdb): string {
            Db::insert(
                'wp_wprism_provider_state',
                ['provider_key' => 'derived', 'provider_value' => 'published-early'],
                null,
                'early provider write'
            );
            $wpdb->simulateExternalTransactionControl('COMMIT AND CHAIN NO RELEASE');
            return 'published-early';
        },
        static fn(string $_result): string => ProviderDatabaseSession::POSTIMAGE_APPLIED
    )
);
wprism_check(
    $earlyWriteCommitFailure instanceof DatabaseTransactionOutcomeException
        && $wpdb->activeTransactionIsolation() === null
        && provider_database_session_rows($wpdb) === [[
            'provider_key' => 'derived',
            'provider_value' => 'published-early',
        ]],
    'a write callback early COMMIT is recovery-required and is never accepted through postimage classification'
);

$wpdb = provider_database_session_fixture();
$forgedTransientFailure = provider_database_session_failure(
    static fn() => ProviderDatabaseSession::repeatable_read_write(
        'forged transient provider mutation',
        provider_database_write_profile(),
        static function () use ($wpdb): never {
            Db::insert(
                'wp_wprism_provider_state',
                ['provider_key' => 'derived', 'provider_value' => 'published-before-forgery'],
                null,
                'forged transient provider write'
            );
            $wpdb->simulateExternalTransactionControl('COMMIT AND CHAIN NO RELEASE');
            throw new TransientDbException('plugin-constructed transient signal');
        },
        static fn(mixed $_result): string => ProviderDatabaseSession::POSTIMAGE_APPLIED
    )
);
wprism_check(
    $forgedTransientFailure instanceof DatabaseTransactionOutcomeException
        && $wpdb->activeTransactionIsolation() === null
        && provider_database_session_rows($wpdb) === [[
            'provider_key' => 'derived',
            'provider_value' => 'published-before-forgery',
        ]],
    'a plugin-constructed transient exception cannot bless an early committed transaction'
);

foreach ([1205 => 'lock-timeout', 1213 => 'deadlock'] as $forgedErrno => $label) {
    $wpdb = provider_database_session_fixture();
    $forgedDriverFailure = provider_database_session_failure(
        static fn() => ProviderDatabaseSession::repeatable_read_write(
            "forged $label driver provider mutation",
            provider_database_write_profile(),
            static function () use ($wpdb, $forgedErrno, $label): never {
                Db::insert(
                    'wp_wprism_provider_state',
                    ['provider_key' => 'derived', 'provider_value' => "published-before-$label-forgery"],
                    null,
                    "forged $label driver provider write"
                );
                $wpdb->simulateExternalTransactionControl('COMMIT AND CHAIN NO RELEASE');
                throw new mysqli_sql_exception('plugin-constructed driver signal', $forgedErrno);
            },
            static fn(mixed $_result): string => ProviderDatabaseSession::POSTIMAGE_APPLIED
        )
    );
    wprism_check(
        $forgedDriverFailure instanceof DatabaseTransactionOutcomeException
            && !$forgedDriverFailure instanceof TransientDbException
            && $wpdb->activeTransactionIsolation() === null
            && provider_database_session_rows($wpdb) === [[
                'provider_key' => 'derived',
                'provider_value' => "published-before-$label-forgery",
            ]],
        "a plugin-constructed mysqli $forgedErrno cannot bless an early committed transaction"
    );
}

foreach (['before_false', 'before_throw', 'after_false', 'after_throw', 'after_reconnect', 'after_reconnect_same_id'] as $outcome) {
    $wpdb = provider_database_session_fixture()->injectTransactionOutcome('ROLLBACK', $outcome);
    $result = ProviderDatabaseSession::read_only_snapshot(
        "read close $outcome",
        provider_database_read_profile(),
        static fn(): string => 'stable projection'
    );
    wprism_check(
        $result === 'stable projection' && $wpdb->activeTransactionIsolation() === null,
        "a read-only $outcome terminal response converges to one closed session"
    );
}

foreach (['CHAIN', 'RELEASE'] as $completionType) {
    $classified = false;
    $wpdb = provider_database_session_fixture()->setCompletionType($completionType);
    $result = provider_database_session_write(
        $wpdb,
        strtolower($completionType),
        static function () use (&$classified): string {
            $classified = true;
            return ProviderDatabaseSession::POSTIMAGE_UNKNOWN;
        }
    );
    wprism_check(
        $result === ['value' => strtolower($completionType)]
            && !$classified
            && in_array('COMMIT AND NO CHAIN NO RELEASE', $wpdb->queries(), true)
            && $wpdb->activeTransactionIsolation() === null,
        "completion_type=$completionType cannot chain or release a successful provider write"
    );
}

foreach (['before_false', 'before_throw', 'after_false', 'after_throw'] as $outcome) {
    $writeCalls = 0;
    $wpdb = provider_database_session_fixture()->injectTransactionOutcome('SET TRANSACTION', $outcome);
    $failure = provider_database_session_failure(
        static fn() => ProviderDatabaseSession::repeatable_read_write(
            "isolation $outcome",
            provider_database_write_profile(),
            static function () use (&$writeCalls): null {
                $writeCalls++;
                return null;
            },
            static fn(null $_result): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN
        )
    );
    $isolation = $wpdb->transactionIsolationState();
    wprism_check(
        $failure instanceof DatabaseMutationException
            && $writeCalls === 0
            && $isolation['next'] === null
            && $isolation['active'] === null,
        "a SET TRANSACTION $outcome refusal is data-free and consumes its one-shot state"
    );
}

foreach (['before_false', 'before_throw'] as $outcome) {
    $writeCalls = 0;
    $wpdb = provider_database_session_fixture()->injectTransactionOutcome('START', $outcome);
    $failure = provider_database_session_failure(
        static fn() => ProviderDatabaseSession::repeatable_read_write(
            "start $outcome",
            provider_database_write_profile(),
            static function () use (&$writeCalls): null {
                $writeCalls++;
                return null;
            },
            static fn(null $_result): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN
        )
    );
    wprism_check(
        $failure instanceof DatabaseMutationException
            && $writeCalls === 0
            && $wpdb->activeTransactionIsolation() === null,
        "a START $outcome refusal runs no provider write and leaves an idle connection"
    );
}

foreach (['after_false', 'after_throw'] as $outcome) {
    $wpdb = provider_database_session_fixture()->injectTransactionOutcome('START', $outcome);
    $result = provider_database_session_write($wpdb, "start-$outcome");
    wprism_check_same(
        ['value' => "start-$outcome"],
        $result,
        "a lost $outcome START response is accepted only after engine authority proof"
    );
}

$wpdb = provider_database_session_fixture();
$wpdb->failNextQuery('fixture insert false', 'INSERT INTO');
$falseWriteFailure = provider_database_session_failure(
    static fn() => provider_database_session_write($wpdb, 'false-write')
);
wprism_check(
    $falseWriteFailure instanceof DatabaseMutationException
        && provider_database_session_rows($wpdb) === []
        && $wpdb->activeTransactionIsolation() === null,
    'a false provider mutation result is rolled back before its typed failure escapes'
);

$wpdb = provider_database_session_fixture();
$wpdb->onQuery(static function (string $sql, string $method): null {
    if ($method === 'query' && str_starts_with($sql, 'INSERT INTO `wp_wprism_provider_state`')) {
        throw new RuntimeException('fixture insert throw');
    }
    return null;
});
$throwWriteFailure = provider_database_session_failure(
    static fn() => provider_database_session_write($wpdb, 'throw-write')
);
$wpdb->onQuery(null);
wprism_check(
    $throwWriteFailure instanceof RuntimeException
        && $throwWriteFailure->getMessage() === 'fixture insert throw'
        && provider_database_session_rows($wpdb) === []
        && $wpdb->activeTransactionIsolation() === null,
    'a throwing provider mutation is rolled back without changing its semantic failure'
);

foreach (['before_false', 'before_throw'] as $outcome) {
    $classified = false;
    $wpdb = provider_database_session_fixture()->injectTransactionOutcome('COMMIT', $outcome);
    $failure = provider_database_session_failure(
        static function () use ($wpdb, $outcome, &$classified): array {
            return provider_database_session_write(
            $wpdb,
            "commit-$outcome",
            static function () use (&$classified): string {
                $classified = true;
                return ProviderDatabaseSession::POSTIMAGE_UNKNOWN;
            }
            );
        }
    );
    wprism_check(
        $failure instanceof DatabaseMutationException
            && !$classified
            && provider_database_session_rows($wpdb) === []
            && $wpdb->activeTransactionIsolation() === null,
        "a definitely unapplied COMMIT $outcome is rolled back without semantic classification"
    );
}

foreach (['after_false', 'after_throw', 'after_reconnect', 'after_reconnect_same_id'] as $outcome) {
    $connectionBefore = null;
    $wpdb = provider_database_session_fixture();
    $connectionBefore = (string) $wpdb->get_var('SELECT CONNECTION_ID()');
    $wpdb->injectTransactionOutcome('COMMIT', $outcome)->resetLog();
    $result = provider_database_session_write($wpdb, "commit-$outcome");
    $connectionAfter = (string) $wpdb->get_var('SELECT CONNECTION_ID()');
    $classificationStarts = array_values(array_filter(
        $wpdb->queries(),
        static fn(string $sql): bool => $sql === 'START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT'
    ));
    wprism_check(
        $result === ['value' => "commit-$outcome"]
            && count($classificationStarts) === 1
            && provider_database_session_rows($wpdb) === [[
                'provider_key' => 'derived',
                'provider_value' => "commit-$outcome",
            ]]
            && ($outcome !== 'after_reconnect' || $connectionAfter !== $connectionBefore)
            && ($outcome !== 'after_reconnect_same_id' || $connectionAfter === $connectionBefore),
        "an applied COMMIT $outcome is accepted only from its physical postimage"
    );
}

foreach (['inactive_false', 'success_no_apply'] as $outcome) {
    $classifierCalls = 0;
    $wpdb = provider_database_session_fixture()->injectTransactionOutcome('COMMIT', $outcome);
    $failure = provider_database_session_failure(
        static function () use ($wpdb, $outcome, &$classifierCalls): array {
            return provider_database_session_write(
            $wpdb,
            "commit-$outcome",
            static function () use (&$classifierCalls): string {
                $classifierCalls++;
                return ProviderDatabaseSession::POSTIMAGE_NOT_APPLIED;
            }
            );
        }
    );
    wprism_check(
        $failure instanceof ProviderDatabaseTransactionNotAppliedException
            && provider_database_session_rows($wpdb) === []
            && $wpdb->activeTransactionIsolation() === null
            && ($outcome !== 'inactive_false' || $classifierCalls === 1)
            && ($outcome !== 'success_no_apply' || $classifierCalls === 0),
        "an unchanged COMMIT $outcome has one explicit retry-safe classification"
    );
    $wpdb->clearTransactionOutcomes();
    wprism_check_same(
        ['value' => "retry-$outcome"],
        provider_database_session_write($wpdb, "retry-$outcome"),
        "the caller can retry $outcome only from a fresh engine transaction"
    );
}

$wpdb = provider_database_session_fixture()->injectTransactionOutcome('COMMIT', 'after_false');
$unknownFailure = provider_database_session_failure(
    static fn() => provider_database_session_write(
        $wpdb,
        'partial-postimage',
        static fn(string $_value): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN
    )
);
wprism_check(
    $unknownFailure instanceof DatabaseTransactionOutcomeException
        && str_contains($unknownFailure->getMessage(), 'recovery_required')
        && provider_database_session_rows($wpdb) !== [],
    'a partial or otherwise unknown physical postimage remains recovery-required'
);

$wpdb = provider_database_session_fixture()->injectTransactionOutcome('COMMIT', 'after_throw');
$classificationFailure = provider_database_session_failure(
    static fn() => provider_database_session_write(
        $wpdb,
        'unreadable-postimage',
        static fn(string $_value): string => throw new RuntimeException('fixture classifier failure')
    )
);
wprism_check(
    $classificationFailure instanceof DatabaseTransactionOutcomeException
        && str_contains($classificationFailure->transactionContext, 'physical postimage classification failed'),
    'a failed physical-postimage read never guesses the ambiguous write outcome'
);

$wpdb = FakeWpdb::install()->enableInformationSchema();
Ledger::ensure();
$ledgerDdl = $wpdb->ddlLog();
foreach (Ledger::OWN_TABLES as $logicalTable) {
    $physicalTable = 'wp_' . $logicalTable;
    $creates = array_values(array_filter(
        $ledgerDdl,
        static fn(string $sql): bool => str_starts_with(
            $sql,
            "CREATE TABLE IF NOT EXISTS `$physicalTable` ("
        )
    ));
    wprism_check(
        count($creates) === 1
            && preg_match('/\)\s+ENGINE=InnoDB\s+DEFAULT CHARACTER SET /', $creates[0]) === 1,
        "$logicalTable is provisioned through Ledger::ensure()'s typed InnoDB schema boundary"
    );
}

$wpdb = provider_database_session_fixture();
$badContextFailure = provider_database_session_failure(
    static fn() => ProviderDatabaseSession::read_only_snapshot(
        "bad\ncontext",
        provider_database_read_profile(),
        static fn(): null => null
    )
);
wprism_check(
    $badContextFailure instanceof InvalidArgumentException && $wpdb->queries() === [],
    'malformed provider context is rejected before any database control'
);

/** @param list<array<string,mixed>> $rows */
function exact_option_writer_fixture(array $rows = []): FakeWpdb {
    Db::forget_transaction_tracking();
    WpStore::reset();
    $GLOBALS['wprism_exact_option_cache_delete_failures'] = [];
    $GLOBALS['wprism_exact_option_cache_delete_throws'] = [];
    return FakeWpdb::install()
        ->setColumns('wp_options', [
            'option_id' => 'bigint unsigned',
            'option_name' => 'varchar(191)',
            'option_value' => 'longtext',
            'autoload' => 'varchar(20)',
        ])
        ->seedTable('wp_options', $rows)
        ->setPrimaryKey('wp_options', 'option_id')
        ->setUniqueKey('wp_options', ['option_name'])
        ->setIndexes('wp_options', [[
            'Key_name' => 'option_name',
            'Non_unique' => 0,
            'Seq_in_index' => 1,
            'Column_name' => 'option_name',
            'Sub_part' => null,
            'Index_type' => 'BTREE',
        ]])
        ->setTableEngine('wp_options', 'InnoDB')
        ->enableInformationSchema();
}

/** @return ?array<string,mixed> */
function exact_option_writer_row(FakeWpdb $wpdb, string $name): ?array {
    foreach ($wpdb->rows('wp_options') as $row) {
        if (($row['option_name'] ?? null) === $name) {
            return $row;
        }
    }
    return null;
}

/** @param list<string> $queries */
function exact_option_query_position(array $queries, callable $matches): ?int {
    foreach ($queries as $position => $query) {
        if ($matches($query)) {
            return $position;
        }
    }
    return null;
}

$serializedLookingScalar = 'a:1:{s:1:"x";s:1:"y";}';
$serializedLookingWire = WordPressOptionValueCodec::encode_scalar_string($serializedLookingScalar);
wprism_check(
    $serializedLookingWire === serialize($serializedLookingScalar)
        && maybe_unserialize($serializedLookingWire) === $serializedLookingScalar
        && WordPressOptionValueCodec::encode_scalar_string('ordinary-value') === 'ordinary-value',
    'the hook-free option codec preserves serialized-looking logical strings through WordPress read semantics'
);

$wpdb = exact_option_writer_fixture();
$cache = WpStore::instance();
$cache->cache['options'] = [
    'fresh_option' => 'stale-value',
    'alloptions' => ['fresh_option' => 'stale-value'],
    'notoptions' => ['fresh_option' => true],
];
$GLOBALS['wprism_exact_option_cache_delete_failures']['fresh_option'] = 1;
$GLOBALS['wprism_exact_option_cache_delete_throws']['alloptions'] = 1;
$inserted = ExactOptionWriter::insert_plain_if_absent(
    'fresh_option',
    'fresh-value',
    null,
    'fixture exact option insert'
);
$fresh = exact_option_writer_row($wpdb, 'fresh_option');
$queries = $wpdb->queries();
$indexAt = exact_option_query_position(
    $queries,
    static fn(string $sql): bool => str_starts_with($sql, 'SHOW INDEX FROM')
);
$insertAt = exact_option_query_position(
    $queries,
    static fn(string $sql): bool => str_starts_with($sql, 'INSERT INTO `wp_options`')
);
wprism_check(
    $inserted
        && ($fresh['option_value'] ?? null) === 'fresh-value'
        && ($fresh['autoload'] ?? null) === 'auto'
        && is_int($indexAt)
        && is_int($insertAt)
        && $indexAt < $insertAt
        && !isset($cache->cache['options']['fresh_option'])
        && !isset($cache->cache['options']['alloptions'])
        && !isset($cache->cache['options']['notoptions'])
        && count(array_filter(
            $cache->cacheEvents,
            static fn(array $event): bool => $event === [
                'op' => 'delete',
                'group' => 'options',
                'key' => 'fresh_option',
            ]
        )) === 2
        && count(array_filter(
            $cache->cacheEvents,
            static fn(array $event): bool => $event === [
                'op' => 'delete',
                'group' => 'options',
                'key' => 'alloptions',
            ]
        )) === 2
        && count(array_filter(
            $cache->cacheEvents,
            static fn(array $event): bool => $event === [
                'op' => 'delete',
                'group' => 'options',
                'key' => 'notoptions',
            ]
        )) === 2
        && $wpdb->activeTransactionIsolation() === null,
    'exact option create proves the unique lock index before DML, uses the WP 6.9 auto state, commits, and exhausts one bounded full-composite cache retry across false and throwable deletes'
);

$preservedCreate = ExactOptionWriter::upsert_plain(
    'preserved_create_option',
    'value',
    'preserve',
    'fixture exact option preserve create policy'
);
$explicitCreate = ExactOptionWriter::upsert_plain(
    'explicit_create_option',
    'value',
    'no',
    'fixture exact option explicit create policy'
);
wprism_check(
    $preservedCreate['autoload'] === 'auto'
        && (exact_option_writer_row($wpdb, 'preserved_create_option')['autoload'] ?? null) === 'auto'
        && $explicitCreate['autoload'] === 'no'
        && (exact_option_writer_row($wpdb, 'explicit_create_option')['autoload'] ?? null) === 'no',
    'new-row preserve resolves to deterministic auto while an explicit policy remains byte-exact'
);

$wpdb = exact_option_writer_fixture([[
    'option_id' => 7,
    'option_name' => 'existing_option',
    'option_value' => 'before',
    'autoload' => 'yes',
]]);
$updated = ExactOptionWriter::upsert_plain(
    'existing_option',
    'after',
    'no',
    'fixture exact option update'
);
$existing = exact_option_writer_row($wpdb, 'existing_option');
wprism_check(
    $updated === [
        'changed' => true,
        'previously_present' => true,
        'previously_nonempty' => true,
        'autoload' => 'yes',
    ]
        && ($existing['option_value'] ?? null) === 'after'
        && ($existing['autoload'] ?? null) === 'yes',
    'exact option update preserves the target row autoload even when create policy differs'
);

$wpdb = exact_option_writer_fixture([[
    'option_id' => 8,
    'option_name' => 'unchanged_option',
    'option_value' => 'same',
    'autoload' => 'auto-off',
]]);
$unchanged = ExactOptionWriter::upsert_plain(
    'unchanged_option',
    'same',
    'yes',
    'fixture exact option unchanged value'
);
wprism_check(
    $unchanged === [
        'changed' => false,
        'previously_present' => true,
        'previously_nonempty' => true,
        'autoload' => 'auto-off',
    ]
        && exact_option_query_position(
            $wpdb->queries(),
            static fn(string $sql): bool => str_starts_with($sql, 'UPDATE `wp_options`')
        ) === null
        && WpStore::instance()->cacheEvents === [],
    'an exact unchanged value preserves physical autoload and commits without DML or cache churn'
);

$wpdb = exact_option_writer_fixture([[
    'option_id' => 7,
    'option_name' => 'existing_option',
    'option_value' => 'after',
    'autoload' => 'yes',
]]);
$collision = ExactOptionWriter::insert_plain_if_absent(
    'existing_option',
    'collision',
    'no',
    'fixture exact option create collision'
);
$staleCas = ExactOptionWriter::replace_plain_if_value(
    'existing_option',
    'stale',
    'replacement',
    'fixture exact option stale compare-and-replace'
);
$matchingCas = ExactOptionWriter::replace_plain_if_value(
    'existing_option',
    'after',
    'replacement',
    'fixture exact option compare-and-replace'
);
wprism_check(
    !$collision
        && !$staleCas
        && $matchingCas
        && (exact_option_writer_row($wpdb, 'existing_option')['option_value'] ?? null) === 'replacement'
        && (exact_option_writer_row($wpdb, 'existing_option')['autoload'] ?? null) === 'yes',
    'create and compare-and-replace preconditions are decided under the exact row lock without clobbering a competing receipt'
);

$wpdb = exact_option_writer_fixture([[
    'option_id' => 8,
    'option_name' => 'cache_failure_option',
    'option_value' => 'before',
    'autoload' => 'no',
]]);
$cache = WpStore::instance();
$cache->cache['options']['cache_failure_option'] = 'before';
$GLOBALS['wprism_exact_option_cache_delete_failures']['cache_failure_option'] = 2;
$cacheFailure = provider_database_session_failure(
    static fn(): mixed => ExactOptionWriter::upsert_plain(
        'cache_failure_option',
        'committed-value',
        'yes',
        'fixture exact option cache failure'
    )
);
wprism_check(
    $cacheFailure instanceof RuntimeException
        && str_contains($cacheFailure->getMessage(), 'database transaction committed')
        && str_contains($cacheFailure->getMessage(), 'recovery_required')
        && !str_contains($cacheFailure->getMessage(), 'cache_failure_option')
        && (exact_option_writer_row($wpdb, 'cache_failure_option')['option_value'] ?? null) === 'committed-value'
        && $wpdb->activeTransactionIsolation() === null,
    'cache invalidation exhaustion reports a committed database outcome instead of misclassifying it as a database failure'
);

$wpdb = exact_option_writer_fixture();
$cache = WpStore::instance();
$cache->cache['options'] = [
    'external_cache_option' => 'pre-transaction-value',
    'alloptions' => ['external_cache_option' => 'pre-transaction-value'],
    'notoptions' => [],
];
$externalCacheBefore = $cache->cache;
$externalCacheEventsBefore = $cache->cacheEvents;
$lateFillTopology = $externalCacheBefore['options'];
$inFlightOldName = $lateFillTopology['external_cache_option'];
$inFlightOldAlloptions = $lateFillTopology['alloptions'];
unset(
    $lateFillTopology['external_cache_option'],
    $lateFillTopology['alloptions'],
    $lateFillTopology['notoptions']
);
$pointInTimePurgeVerified = $lateFillTopology === [];
// A request that read the database before the commit can publish after that
// proof; core exposes no generation token with which to reject this late fill.
$lateFillTopology['external_cache_option'] = $inFlightOldName;
$lateFillTopology['alloptions'] = $inFlightOldAlloptions;
$GLOBALS['wprism_exact_option_ext_object_cache'] = true;
$externalCacheFailure = provider_database_session_failure(
    static fn(): mixed => ExactOptionWriter::upsert_plain(
        'external_cache_option',
        'value',
        'no',
        'fixture external option cache'
    )
);
$GLOBALS['wprism_exact_option_ext_object_cache'] = false;
wprism_check(
    $externalCacheFailure instanceof RuntimeException
        && str_contains($externalCacheFailure->getMessage(), 'persistent external object cache')
        && str_contains($externalCacheFailure->getMessage(), 'version/CAS fence')
        && str_contains($externalCacheFailure->getMessage(), 'options/alloptions/notoptions')
        && $wpdb->queries() === []
        && $pointInTimePurgeVerified
        && $lateFillTopology['external_cache_option'] === 'pre-transaction-value'
        && $lateFillTopology['alloptions'] === ['external_cache_option' => 'pre-transaction-value']
        && $cache->cache === $externalCacheBefore
        && $cache->cacheEvents === $externalCacheEventsBefore,
    'a persistent external option cache is refused before database/cache mutation because an in-flight old database read can late-fill after any point-in-time three-key purge proof'
);

$wpdb = exact_option_writer_fixture();
$invalidAutoload = provider_database_session_failure(
    static fn(): mixed => ExactOptionWriter::upsert_plain(
        'bounded_option',
        'value',
        'sometimes',
        'fixture invalid autoload'
    )
);
$oversizedValue = provider_database_session_failure(
    static fn(): mixed => ExactOptionWriter::upsert_plain(
        'bounded_option',
        str_repeat('x', 16777217),
        'no',
        'fixture oversized value'
    )
);
$malformedContext = provider_database_session_failure(
    static fn(): mixed => ExactOptionWriter::upsert_plain(
        '',
        'value',
        'no',
        "fixture secret\ncontext"
    )
);
$protectedOption = provider_database_session_failure(
    static fn(): mixed => ExactOptionWriter::upsert_plain(
        'alloptions',
        'value',
        'no',
        'fixture protected option identity'
    )
);
wprism_check(
    $invalidAutoload instanceof InvalidArgumentException
        && $oversizedValue instanceof InvalidArgumentException
        && $malformedContext instanceof InvalidArgumentException
        && $protectedOption instanceof InvalidArgumentException
        && !str_contains($malformedContext->getMessage(), 'secret')
        && $wpdb->queries() === [],
    'protected identities, malformed context/autoload, and oversized values are bounded before transaction or DML'
);

$wpdb = exact_option_writer_fixture();
$maximumUtf8Name = str_repeat("\u{1F600}", 191);
$maximumUtf8Inserted = ExactOptionWriter::insert_plain_if_absent(
    $maximumUtf8Name,
    'value',
    'off',
    'fixture maximum UTF-8 option identity'
);
$oversizedUtf8Name = provider_database_session_failure(
    static fn(): mixed => ExactOptionWriter::upsert_plain(
        str_repeat("\u{1F600}", 192),
        'value',
        'off',
        'fixture oversized UTF-8 option identity'
    )
);
wprism_check(
    $maximumUtf8Inserted
        && strlen($maximumUtf8Name) === 764
        && exact_option_writer_row($wpdb, $maximumUtf8Name) !== null
        && $oversizedUtf8Name instanceof InvalidArgumentException,
    'option identity validation admits exactly 191 UTF-8 characters within 764 bytes and refuses the next character'
);

$wpdb = exact_option_writer_fixture()->setIndexes('wp_options', [[
    'Key_name' => 'option_name_with_id',
    'Non_unique' => 0,
    'Seq_in_index' => 1,
    'Column_name' => 'option_name',
    'Sub_part' => null,
    'Index_type' => 'BTREE',
], [
    'Key_name' => 'option_name_with_id',
    'Non_unique' => 0,
    'Seq_in_index' => 2,
    'Column_name' => 'option_id',
    'Sub_part' => null,
    'Index_type' => 'BTREE',
]]);
$missingIndex = provider_database_session_failure(
    static fn(): mixed => ExactOptionWriter::upsert_plain(
        'unlocked_option',
        'value',
        'no',
        'fixture exact option missing index'
    )
);
wprism_check(
    $missingIndex instanceof RuntimeException
        && str_contains($missingIndex->getMessage(), 'full-width unique')
        && exact_option_writer_row($wpdb, 'unlocked_option') === null
        && exact_option_query_position(
            $wpdb->queries(),
            static fn(string $sql): bool => str_starts_with($sql, 'INSERT INTO `wp_options`')
        ) === null
        && $wpdb->activeTransactionIsolation() === null,
    'exact option persistence refuses and rolls back before DML when option_name is only the first column of a composite unique index'
);

$wpdb = exact_option_writer_fixture([[
    'option_id' => 10,
    'option_name' => 'refused_commit_option',
    'option_value' => 'before',
    'autoload' => 'no',
]])->injectTransactionOutcome('COMMIT', 'before_false');
$cache = WpStore::instance();
$cache->cache['options']['refused_commit_option'] = 'before';
$refusedCommit = provider_database_session_failure(
    static fn(): mixed => ExactOptionWriter::upsert_plain(
        'refused_commit_option',
        'must-roll-back',
        'yes',
        'fixture refused option commit'
    )
);
wprism_check(
    $refusedCommit instanceof DatabaseMutationException
        && (exact_option_writer_row($wpdb, 'refused_commit_option')['option_value'] ?? null) === 'before'
        && ($cache->cache['options']['refused_commit_option'] ?? null) === 'before'
        && $cache->cacheEvents === []
        && $wpdb->activeTransactionIsolation() === null,
    'a definitely unapplied commit rolls back exact option DML without claiming or invalidating a committed postimage'
);

$postimageReads = 0;
$wpdb = exact_option_writer_fixture([[
    'option_id' => 11,
    'option_name' => 'postimage_failure_option',
    'option_value' => 'before',
    'autoload' => 'no',
]])->onQuery(static function (string $sql, string $_method, FakeWpdb $_database) use (&$postimageReads): ?string {
    if (str_contains($sql, 'OCTET_LENGTH(option_value) AS option_value_bytes')) {
        ++$postimageReads;
        if ($postimageReads === 2) {
            return 'injected exact postimage read failure';
        }
    }
    return null;
});
$postimageFailure = provider_database_session_failure(
    static fn(): mixed => ExactOptionWriter::upsert_plain(
        'postimage_failure_option',
        'must-roll-back',
        'no',
        'fixture exact option postimage failure'
    )
);
$wpdb->onQuery(null);
wprism_check(
    $postimageFailure instanceof RuntimeException
        && $postimageReads === 2
        && (exact_option_writer_row($wpdb, 'postimage_failure_option')['option_value'] ?? null) === 'before'
        && WpStore::instance()->cacheEvents === []
        && $wpdb->activeTransactionIsolation() === null,
    'a failed exact postimage witness rolls DML back before commit or cache invalidation'
);

$providerSource = file_get_contents(__DIR__ . '/../../../../agent/src/Adapter/Providers.php');
$applyCoordinatorSource = file_get_contents(__DIR__ . '/../../../../agent/src/Apply/ApplyRequestCoordinator.php');
$exactWriterSource = file_get_contents(__DIR__ . '/../../../../agent/src/Kernel/ExactOptionWriter.php');
$optionCodecSource = file_get_contents(__DIR__ . '/../../../../agent/src/Kernel/WordPressOptionValueCodec.php');
$databaseBoundaryLiveSource = file_get_contents(
    __DIR__ . '/../../live/regress_database_boundary_live.sh'
);
$livePairOwnershipSource = file_get_contents(__DIR__ . '/../../lib/pair_live_ownership.sh');
$databaseBoundaryPass = is_string($databaseBoundaryLiveSource)
    ? strpos($databaseBoundaryLiveSource, 'REGRESS_DATABASE_BOUNDARY_LIVE PASSED')
    : false;
$databaseBoundaryMaria = is_string($databaseBoundaryLiveSource)
    ? strpos($databaseBoundaryLiveSource, 'start_pair mariadb mariadb MariaDB')
    : false;
$databaseBoundaryMysql = is_string($databaseBoundaryLiveSource)
    ? strpos($databaseBoundaryLiveSource, 'start_pair mysql mysql MySQL')
    : false;
wprism_check(
    is_string($providerSource)
        && !preg_match('/\b(?:add_option|update_option)\s*\(/', $providerSource)
        && str_contains($providerSource, 'ExactOptionWriter::insert_plain_if_absent(')
        && str_contains($providerSource, 'ExactOptionWriter::replace_plain_if_value(')
        && is_string($applyCoordinatorSource)
        && !preg_match('/\b(?:add_option|update_option)\s*\(/', $applyCoordinatorSource)
        && str_contains(
            $applyCoordinatorSource,
            'WordPressOptionValueCodec::encode_scalar_string($value)'
        )
        && str_contains($applyCoordinatorSource, 'ExactOptionWriter::upsert_plain(')
        && is_string($optionCodecSource)
        && str_contains($optionCodecSource, "\\function_exists('maybe_serialize')")
        && str_contains($optionCodecSource, '\\maybe_serialize($value)')
        && is_string($exactWriterSource)
        && str_contains($exactWriterSource, 'LockedOptionRows::read_optional(')
        && !str_contains($exactWriterSource, 'OCTET_LENGTH(option_value)')
        && !str_contains($exactWriterSource, 'SHA2(option_value, 256)'),
    'provider receipts and plain env-set cannot regress to hookful or namespace-shadowed writers or fork LockedOptionRows witness machinery'
);
wprism_check(
    is_string($databaseBoundaryLiveSource)
        && substr_count(
            $databaseBoundaryLiveSource,
            'cat > "$R1/.wprism-database-boundary-'
        ) === 5
        && preg_match(
            '/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/',
            $databaseBoundaryLiveSource
        ) === 0
        && substr_count($databaseBoundaryLiveSource, 'READS SQL DATA') === 2
        && !str_contains($databaseBoundaryLiveSource, 'CONTAINS SQL')
        && str_contains($databaseBoundaryLiveSource, 'PAIR="${DATABASE_BOUNDARY_PAIR:-}"')
        && str_contains($databaseBoundaryLiveSource, 'PORT1_RAW="${DATABASE_BOUNDARY_PORT1:-}"')
        && str_contains($databaseBoundaryLiveSource, 'PORT2_RAW="${DATABASE_BOUNDARY_PORT2:-}"')
        && str_contains($databaseBoundaryLiveSource, '. "$REPO_ROOT/sandbox/tests/lib/pair_live_ownership.sh"')
        && str_contains($databaseBoundaryLiveSource, 'pair_live_ownership_acquire "$engine"')
        && str_contains($databaseBoundaryLiveSource, 'pair_live_ownership_finish_leg')
        && !str_contains($databaseBoundaryLiveSource, 'cleanup() {')
        && is_string($livePairOwnershipSource)
        && str_contains($livePairOwnershipSource, 'pair_live_ownership_remove_pair_roots')
        && str_contains($livePairOwnershipSource, 'pair_live_ownership_remove_scratch')
        && str_contains($livePairOwnershipSource, 'lease-batch-release')
        && $databaseBoundaryPass !== false
        && $databaseBoundaryMaria !== false
        && $databaseBoundaryMysql !== false
        && $databaseBoundaryMaria < $databaseBoundaryMysql
        && $databaseBoundaryMysql < $databaseBoundaryPass
        && str_contains($databaseBoundaryLiveSource, 'unsafe-character-set-refused')
        && str_contains($databaseBoundaryLiveSource, 'unsafe-show-forms-refused')
        && str_contains($databaseBoundaryLiveSource, 'exact-like-presence-passed')
        && !str_contains($databaseBoundaryLiveSource, 'db.mysql.yml down -v')
        && str_contains($databaseBoundaryLiveSource, "pair_live_ownership_complete '✔ REGRESS_DATABASE_BOUNDARY_LIVE PASSED'"),
    'all five database-boundary fixtures stay WP-CLI-safe, prove lexer/SHOW premises, and use fresh engine-bound leases with verified cleanup before reuse/PASS'
);

$wpdb = exact_option_writer_fixture([[
    'option_id' => 9,
    'option_name' => 'ambiguous_commit_option',
    'option_value' => 'before',
    'autoload' => 'no',
]])->injectTransactionOutcome('COMMIT', 'after_false');
$cache = WpStore::instance();
$cache->cache['options'] = [
    'ambiguous_commit_option' => 'before',
    'alloptions' => ['ambiguous_commit_option' => 'before'],
    'notoptions' => [],
];
$GLOBALS['wprism_exact_option_cache_delete_failures']['ambiguous_commit_option'] = 1;
$ambiguousCommit = provider_database_session_failure(
    static fn(): mixed => ExactOptionWriter::upsert_plain(
        'ambiguous_commit_option',
        'possible-postimage',
        'no',
        'fixture ambiguous option commit'
    )
);
wprism_check(
    $ambiguousCommit instanceof DatabaseTransactionOutcomeException
        && (exact_option_writer_row($wpdb, 'ambiguous_commit_option')['option_value'] ?? null) === 'possible-postimage'
        && !isset($cache->cache['options']['ambiguous_commit_option'])
        && !isset($cache->cache['options']['alloptions'])
        && !isset($cache->cache['options']['notoptions'])
        && count(array_filter(
            $cache->cacheEvents,
            static fn(array $event): bool => $event === [
                'op' => 'delete',
                'group' => 'options',
                'key' => 'ambiguous_commit_option',
            ]
        )) === 2,
    'an ambiguous COMMIT remains Db outcome uncertainty while bounded best-effort cache retry covers the possibly committed postimage'
);

// New typed shapes cross the same engine-owned authority boundary as DELETE;
// they cannot reach Db's standalone transaction mode through a provider facade.
$typedRowBefore = [['provider_key' => 'keep', 'provider_value' => 'before']];
$typedRowFixture = static fn(): FakeWpdb => provider_database_session_fixture()
    ->seedTable('wp_wprism_provider_state', $typedRowBefore)
    ->seedTable('wp_wprism_undeclared', $typedRowBefore)
    ->setTableEngine('wp_wprism_undeclared', 'InnoDB');
$typedRowInvoke = static function (string $operation, string $table = 'wp_wprism_provider_state',
    ?array $data = null, ?array $where = null, mixed $format = null): int {
    return $operation === 'insert'
        ? ProviderSdk::database_insert($table, $data ?? ['provider_key' => 'new', 'provider_value' => "exact\0東京'\\bytes"],
            'typed provider insert fixture', $format)
        : ProviderSdk::database_update($table, $data ?? ['provider_value' => "exact\0東京'\\bytes"],
            $where ?? ['provider_key' => 'keep'], 'typed provider update fixture', $format, '%s');
};
$typedRowTransaction = static fn(callable $write): mixed => ProviderSdkContractProbe::run([], ['table:wprism_provider_state'],
    static fn(): mixed => ProviderSdk::database_write_contract_transaction('typed provider row transaction', $write,
        static fn(mixed $_result): string => ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN));
foreach (['insert', 'update'] as $typedOperation) {
    $wpdb = $typedRowFixture();
    wprism_check_throws(static fn(): int => $typedRowInvoke($typedOperation), RuntimeException::class,
        'typed ' . $typedOperation . ' cannot establish its own database authority', 'active bound provider database write profile');
    wprism_check_same([], $wpdb->queries(), 'unbound typed ' . $typedOperation . ' performs no transport');
    wprism_check_throws(static fn(): mixed => ProviderSdkContractProbe::run([], ['table:wprism_provider_state'],
        static fn(): int => $typedRowInvoke($typedOperation)), RuntimeException::class,
        'bound contract alone cannot authorize typed ' . $typedOperation . ' outside its write transaction', 'active bound provider database write profile');
    wprism_check_same([], $wpdb->queries(), 'contract-only typed ' . $typedOperation . ' performs no transport');

    foreach (['read-snapshot', 'read-only-contract', 'foreign-table'] as $scopeFault) {
        $wpdb = $typedRowFixture();
        $attempt = match ($scopeFault) {
            'read-snapshot' => static fn(): mixed => ProviderSdkContractProbe::run([], ['table:wprism_provider_state'],
                static fn(): mixed => ProviderSdk::database_read_contract_snapshot('typed mutation in read snapshot',
                    static fn(): int => $typedRowInvoke($typedOperation))),
            'read-only-contract' => static fn(): mixed => ProviderSdkContractProbe::run(['table:wprism_provider_state'], [],
                static fn(): mixed => ProviderSdk::database_write_contract_transaction('typed mutation in read-only contract',
                    static fn(): int => $typedRowInvoke($typedOperation),
                    static fn(int $_result): string => ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN)),
            default => static fn(): mixed => $typedRowTransaction(static fn(): int => $typedRowInvoke($typedOperation, 'wp_wprism_undeclared')),
        };
        wprism_check_throws($attempt, $scopeFault === 'read-only-contract' ? InvalidArgumentException::class : RuntimeException::class,
            'typed ' . $typedOperation . ' refuses ' . $scopeFault);
        wprism_check_same($typedRowBefore, provider_database_session_rows($wpdb), $scopeFault . ' leaves the owned rows unchanged');
        wprism_check_same($typedRowBefore, $wpdb->rows('wp_wprism_undeclared'), $scopeFault . ' leaves foreign rows unchanged');
        wprism_check(count(array_filter($wpdb->queries(), static fn(string $sql): bool => preg_match('/^(?:INSERT|UPDATE)\b/', $sql) === 1)) === 0
            && !DatabaseQueryIsolation::has_bound_profile() && $wpdb->activeTransactionIsolation() === null,
            $scopeFault . ' refuses before DML and clears the transaction/profile scope');
    }

    foreach (['empty-data', 'unsafe-column', 'unsafe-table', 'non-scalar-data'] as $inputFault) {
        $wpdb = $typedRowFixture();
        $data = match ($inputFault) {
            'empty-data' => [], 'unsafe-column' => ['provider_value` = 1' => 'unsafe'],
            'non-scalar-data' => ['provider_value' => ['unsafe-array']], default => null,
        };
        $table = $inputFault === 'unsafe-table' ? 'wp_wprism_provider_state; DELETE' : 'wp_wprism_provider_state';
        wprism_check_throws(static fn(): mixed => $typedRowTransaction(static fn(): int => $typedRowInvoke($typedOperation, $table, $data)),
            Throwable::class, 'typed ' . $typedOperation . ' rejects ' . $inputFault . ' before its statement');
        wprism_check_same($typedRowBefore, provider_database_session_rows($wpdb), $inputFault . ' cannot modify owned rows');
        wprism_check(count(array_filter($wpdb->queries(), static fn(string $sql): bool => preg_match('/^(?:INSERT|UPDATE)\b/', $sql) === 1)) === 0,
            $inputFault . ' issues no typed DML');
    }

    $wpdb = $typedRowFixture();
    $affected = $typedRowTransaction(static fn(): int => $typedRowInvoke($typedOperation));
    $expectedTypedRows = $typedOperation === 'insert'
        ? [$typedRowBefore[0], ['provider_key' => 'new', 'provider_value' => "exact\0東京'\\bytes"]]
        : [['provider_key' => 'keep', 'provider_value' => "exact\0東京'\\bytes"]];
    wprism_check_same(1, $affected, 'typed ' . $typedOperation . ' returns the exact affected-row count');
    wprism_check_same($expectedTypedRows, provider_database_session_rows($wpdb), 'typed ' . $typedOperation . ' preserves binary, Unicode, quotes and slashes');
    $statements = array_values(array_filter($wpdb->queries(), static fn(string $sql): bool => str_starts_with($sql, strtoupper($typedOperation) . ' ')));
    wprism_check(count($statements) === 1 && str_contains($statements[0], 'CONNECTION_ID()')
        && !DatabaseQueryIsolation::has_bound_profile() && $wpdb->activeTransactionIsolation() === null,
        'typed ' . $typedOperation . ' uses exactly one physical-session-guarded mutation and closes its engine scope');

    $wpdb = $typedRowFixture();
    wprism_check_throws(static fn(): mixed => $typedRowTransaction(static function () use ($typedRowInvoke, $typedOperation): never {
        $typedRowInvoke($typedOperation);
        throw new RuntimeException('native postcondition fixture failed');
    }), RuntimeException::class, 'failure after typed ' . $typedOperation . ' rolls back through the session owner', 'native postcondition fixture failed');
    wprism_check_same($typedRowBefore, provider_database_session_rows($wpdb), 'rollback after typed ' . $typedOperation . ' restores the complete preimage');

    foreach (['false', 'throw', 'reconnect'] as $driverFault) {
        $wpdb = $typedRowFixture()->onQuery(static function (string $sql, string $method, FakeWpdb $db) use ($typedOperation, $driverFault): ?string {
            if (!str_starts_with($sql, strtoupper($typedOperation) . ' ')) return null;
            if ($driverFault === 'throw') throw new mysqli_sql_exception('private typed driver bytes', 1064);
            if ($driverFault === 'reconnect') {
                $db->setConnectionId(199);
                return null;
            }
            return 'private typed driver bytes';
        });
        $failure = provider_database_session_failure(static fn(): mixed => $typedRowTransaction(static fn(): int => $typedRowInvoke($typedOperation)));
        wprism_check($failure instanceof RuntimeException && !str_contains($failure->getMessage(), 'private typed driver bytes'),
            'typed ' . $typedOperation . ' retains the engine refusal on driver ' . $driverFault . ' without leaking driver content');
        if ($driverFault === 'throw') {
            wprism_check($failure instanceof DatabaseMutationException && $failure->getPrevious() instanceof mysqli_sql_exception
                && $failure->getPrevious()->getMessage() === 'private typed driver bytes',
                'typed ' . $typedOperation . ' preserves strict-mysqli evidence only in its private cause');
        }
        wprism_check_same($typedRowBefore, provider_database_session_rows($wpdb),
            'typed ' . $typedOperation . ' cannot mutate outside the admitted session on driver ' . $driverFault);
        if ($driverFault === 'reconnect') {
            wprism_check($failure instanceof DatabaseTransactionOutcomeException,
                'typed ' . $typedOperation . ' reconnect remains unresolved outcome debt, not an invented rollback');
            wprism_check_throws(static fn(): bool => Db::connection_transaction_active('typed reconnect forbidden ordinary read'),
                DatabaseQueryIsolationViolationException::class, 'unsettled reconnect quarantines ordinary provider continuation');
            // Fixture teardown enters the engine's cleanup-only gate; proving
            // an idle replacement does not classify the refused native action.
            DatabaseQueryIsolation::prepare_cleanup('typed reconnect fixture cleanup');
            wprism_check_same(false, Db::connection_transaction_active('typed reconnect fixture idle-session proof'),
                'replacement connection must independently prove idle before the fixture can release tracking');
            Db::forget_transaction_tracking();
        }
        wprism_check(!DatabaseQueryIsolation::has_bound_profile() && $wpdb->activeTransactionIsolation() === null,
            'typed ' . $typedOperation . ' driver ' . $driverFault . ' leaves no authority after required settlement');
    }
    $semanticFailure = new RuntimeException('typed query-hook semantic refusal');
    $wpdb = $typedRowFixture()->onQuery(static function (string $sql) use ($typedOperation, $semanticFailure): void {
        if (str_starts_with($sql, strtoupper($typedOperation) . ' ')) throw $semanticFailure;
    });
    wprism_check_same($semanticFailure,
        provider_database_session_failure(static fn(): mixed => $typedRowTransaction(static fn(): int => $typedRowInvoke($typedOperation))),
        'typed ' . $typedOperation . ' preserves semantic hook exceptions instead of inventing driver evidence');
    wprism_check_same($typedRowBefore, provider_database_session_rows($wpdb),
        'typed ' . $typedOperation . ' hook failure preserves complete physical state');
}
$wpdb = $typedRowFixture();
wprism_check_throws(static fn(): mixed => $typedRowTransaction(static fn(): int => $typedRowInvoke('update', 'wp_wprism_provider_state', null, [])),
    InvalidArgumentException::class, 'typed UPDATE cannot disguise whole-table mutation as an empty predicate', 'empty mutation predicate');
wprism_check_same($typedRowBefore, provider_database_session_rows($wpdb), 'empty typed UPDATE predicate preserves all rows');
$wpdb = $typedRowFixture();
wprism_check_same(0, $typedRowTransaction(static fn(): int => $typedRowInvoke('update', 'wp_wprism_provider_state',
    ['provider_value' => 'before'])), 'an in-place fixed-point UPDATE reports zero affected rows without regenerating identity');
wprism_check_same($typedRowBefore, provider_database_session_rows($wpdb), 'fixed-point UPDATE leaves exact native bytes and identity unchanged');
$wpdb = $typedRowFixture();
$typedRowTransaction(static fn(): int => $typedRowInvoke('update', 'wp_wprism_provider_state', ['provider_value' => null]));
wprism_check_same([['provider_key' => 'keep', 'provider_value' => null]], provider_database_session_rows($wpdb),
    'typed UPDATE retains SQL null instead of coercing it to an empty string');
$wpdb = $typedRowFixture()->seedTable('wp_wprism_provider_state', [
    ['provider_key' => '7', 'provider_value' => 'before'], ['provider_key' => '7.4', 'provider_value' => 'untouched'],
]);
wprism_check_same(1, $typedRowTransaction(static fn(): int => ProviderSdk::database_update('wp_wprism_provider_state',
    ['provider_value' => '0009'], ['provider_key' => '7.4'], 'distinct SDK update formats', '%s', '%d')),
    'SDK update keeps distinct explicit data and predicate formats');
wprism_check_same([['provider_key' => '7', 'provider_value' => '0009'], ['provider_key' => '7.4', 'provider_value' => 'untouched']],
    provider_database_session_rows($wpdb), 'data format cannot be dropped or swapped with the numeric predicate format');
$wpdb = $typedRowFixture();
wprism_check_same(1, $typedRowTransaction(static fn(): int => ProviderSdk::database_update('wp_wprism_provider_state',
    ['provider_value' => '0009'], ['provider_key' => 'keep'], 'explicit SDK update data format', '%d', '%s')),
    'SDK update independently forwards a non-default data format');
wprism_check_same('9', (string) provider_database_session_rows($wpdb)[0]['provider_value'],
    'update data formatting cannot silently fall back to string inference');
$wpdb = $typedRowFixture();
$typedRowTransaction(static fn(): int => ProviderSdk::database_insert('wp_wprism_provider_state',
    ['provider_key' => 'formatted', 'provider_value' => '0009'], 'explicit SDK insert formats', ['%s', '%d']));
wprism_check_same('9', (string) provider_database_session_rows($wpdb)[1]['provider_value'],
    'SDK insert forwards its explicit field-format roster rather than falling back to string inference');
$wpdb = $typedRowFixture()->seedTable('wp_wprism_provider_state', [
    ['provider_key' => 'null', 'provider_value' => null], ['provider_key' => 'empty', 'provider_value' => ''],
]);
wprism_check_same(1, $typedRowTransaction(static fn(): int => ProviderSdk::database_update('wp_wprism_provider_state',
    ['provider_value' => 'changed'], ['provider_value' => null], 'SDK null equality predicate', '%s', '%d')),
    'SDK null equality uses IS NULL independently of the explicit predicate format');
wprism_check_same([['provider_key' => 'null', 'provider_value' => 'changed'], ['provider_key' => 'empty', 'provider_value' => '']],
    provider_database_session_rows($wpdb), 'null equality cannot accidentally update an empty-string sibling');

// A physical row descriptor is observation data, never authority. Exercise
// the SDK facade under the same engine-bound runtime used by real scopes.
$physicalDescriptor = ['table' => 'wp_posts', 'columns' => ['ID', 'post_content'],
    'identity' => ['ID'], 'max_rows' => 8, 'max_raw_bytes' => 1024, 'mode' => 'rows'];
$physicalFixture = static function (): FakeWpdb {
    Db::forget_transaction_tracking();
    return FakeWpdb::install()->enableInformationSchema()
        ->seedTable('wp_posts', [['ID' => 7, 'post_content' => "exact\0raw"]])
        ->setColumns('wp_posts', ['ID' => 'bigint', 'post_content' => 'longtext'])
        ->setTableEngine('wp_posts', 'InnoDB')
        ->seedTable('wp_options', [])->setTableEngine('wp_options', 'InnoDB');
};
$physicalRead = static fn(): array => ProviderSdk::physical_table_rows($physicalDescriptor, 'SDK physical fixture');
$wpdb = $physicalFixture();
wprism_check_throws($physicalRead, RuntimeException::class, 'physical SDK cannot borrow a descriptor as manifest authority');
wprism_check_throws(static fn(): mixed => ProviderSdkContractProbe::runUnbound(['table:posts'], [],
    static fn(): mixed => ProviderSdk::database_read_contract_snapshot('unbound physical fixture', $physicalRead)),
    RuntimeException::class, 'a directly constructed runtime cannot authorize the physical reader');
wprism_check_throws(static fn(): mixed => ProviderSdkContractProbe::run(['table:posts'], [], $physicalRead),
    RuntimeException::class, 'bound provider identity without a database scope is not physical read authority');
$wpdb = $physicalFixture();
$physicalObserved = ProviderSdkContractProbe::run(['table:posts'], [],
    static fn(): mixed => ProviderSdk::database_read_contract_snapshot('admitted physical fixture', $physicalRead));
wprism_check_same([['ID' => '7', 'post_content' => "exact\0raw"]], $physicalObserved['rows'],
    'engine-bound provider observes exact native rows inside its declared read-only scope');
wprism_check_same(false, DatabaseQueryIsolation::has_bound_profile(), 'physical reader leaves scope lifetime with its transaction owner');
$wpdb = $physicalFixture();
wprism_check_throws(static fn(): mixed => ProviderSdkContractProbe::run(['table:options'], [],
    static fn(): mixed => ProviderSdk::database_read_contract_snapshot('wrong physical contract', $physicalRead)),
    RuntimeException::class, 'physical descriptor cannot introduce an undeclared table', 'outside its active manifest-provider contract');
$wpdb = $physicalFixture();
wprism_check_throws(static fn(): mixed => ProviderSdkContractProbe::run(['table:options', 'table:posts'], [],
    static fn(): mixed => ProviderSdk::database_read_snapshot('narrow physical contract', ['wp_options'], $physicalRead)),
    RuntimeException::class, 'physical reader cannot re-expand a narrowed active snapshot', 'escaped the tables');
$wpdb = $physicalFixture();
$beforePhysicalWrite = $wpdb->rows('wp_posts');
ProviderSdkContractProbe::run([], ['table:posts'], static fn(): mixed => ProviderSdk::database_write_contract_transaction(
    'physical write snapshot fixture', static function () use ($physicalRead, $beforePhysicalWrite): array {
        $observed = $physicalRead();
        wprism_check_same((string) $beforePhysicalWrite[0]['ID'], $observed['rows'][0]['ID'],
            'physical reader can reuse a writable transaction without nesting or gaining mutation authority');
        return $observed;
    }, static fn(array $observed): string => ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN));
wprism_check_same($beforePhysicalWrite, $wpdb->rows('wp_posts'), 'observation under a writable profile still performs no native mutation');

wprism_check_summary('regress_provider_database_session');
