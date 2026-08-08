<?php
/**
 * Offline migration regression for the WooCommerce extension fixtures.
 *
 * Each case runs in a child PHP process so the v1, fixed-v2, and deliberately
 * broken-v2 plugin files can be loaded independently.  The fake wpdb models
 * only the option and INFORMATION_SCHEMA/DDL boundaries used by these
 * fixtures, and can fail each boundary before it mutates state.
 */

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

final class EcommerceExtensionMigrationFakeWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public ?array $shape = null;
    public int $failProbe = 0;
    public int $failCreate = 0;
    public int $failAlter = 0;

    /** @var list<array{name:string,data_type:string,column_type:string,length:?int,nullable:string,extra:string,column_key:string,default:?string}> */
    public array $v1Shape = [
        ['name' => 'id', 'data_type' => 'bigint', 'column_type' => 'bigint(21) unsigned', 'length' => null, 'nullable' => 'NO', 'extra' => 'auto_increment', 'column_key' => 'PRI', 'default' => null],
        ['name' => 'label', 'data_type' => 'varchar', 'column_type' => 'varchar(191)', 'length' => 191, 'nullable' => 'NO', 'extra' => '', 'column_key' => '', 'default' => null],
        ['name' => 'created_at', 'data_type' => 'datetime', 'column_type' => 'datetime', 'length' => null, 'nullable' => 'NO', 'extra' => '', 'column_key' => '', 'default' => null],
    ];

    /** @var list<array{name:string,data_type:string,column_type:string,length:?int,nullable:string,extra:string,column_key:string,default:?string}> */
    public array $v2Shape = [
        ['name' => 'id', 'data_type' => 'bigint', 'column_type' => 'bigint(21) unsigned', 'length' => null, 'nullable' => 'NO', 'extra' => 'auto_increment', 'column_key' => 'PRI', 'default' => null],
        ['name' => 'label', 'data_type' => 'varchar', 'column_type' => 'varchar(191)', 'length' => 191, 'nullable' => 'NO', 'extra' => '', 'column_key' => '', 'default' => null],
        ['name' => 'context', 'data_type' => 'varchar', 'column_type' => 'varchar(64)', 'length' => 64, 'nullable' => 'NO', 'extra' => '', 'column_key' => '', 'default' => ''],
        ['name' => 'created_at', 'data_type' => 'datetime', 'column_type' => 'datetime', 'length' => null, 'nullable' => 'NO', 'extra' => '', 'column_key' => '', 'default' => null],
    ];

    public function get_charset_collate(): string {
        return '';
    }

    public function prepare(string $query, ...$args): string {
        $index = 0;
        return (string) preg_replace_callback(
            '/%[dsf]/',
            static function (array $match) use ($args, &$index): string {
                if (!array_key_exists($index, $args)) {
                    return $match[0];
                }
                $value = $args[$index++];
                return is_int($value) || is_float($value)
                    ? (string) $value
                    : "'" . addslashes((string) $value) . "'";
            },
            $query
        );
    }

    public function query(string $query): int|false {
        $upper = strtoupper(ltrim($query));
        if (str_starts_with($upper, 'CREATE TABLE')) {
            if ($this->failCreate > 0) {
                $this->failCreate--;
                $this->last_error = 'simulated CREATE failure';
                return false;
            }
            if ($this->shape === null) {
                $this->shape = stripos($query, 'context VARCHAR(64)') !== false
                    ? $this->v2Shape
                    : $this->v1Shape;
            }
            return 1;
        }
        if (str_starts_with($upper, 'ALTER TABLE')) {
            if ($this->failAlter > 0) {
                $this->failAlter--;
                $this->last_error = 'simulated ALTER failure';
                return false;
            }
            $shape = $this->shape;
            $columnType = is_array($shape) ? (string) ($shape[0]['column_type'] ?? '') : '';
            if ($shape === null
                || !preg_match('/^bigint(?:\(\d+\))? unsigned$/i', $columnType)) {
                $this->last_error = 'simulated ALTER shape mismatch';
                return false;
            }
            $shape[0]['column_type'] = 'bigint(21) unsigned';
            if ($shape !== $this->v1Shape) {
                $this->last_error = 'simulated ALTER shape mismatch';
                return false;
            }
            $this->shape = $this->v2Shape;
            return 1;
        }
        return 0;
    }

    public function get_results(string $query, $output = null): ?array {
        if (str_contains($query, 'INFORMATION_SCHEMA.COLUMNS')) {
            if ($this->failProbe > 0) {
                $this->failProbe--;
                $this->last_error = 'simulated INFORMATION_SCHEMA probe failure';
                return null;
            }
            if ($this->shape === null) {
                return [];
            }
            return array_map(
                static fn(array $row): array => [
                    'COLUMN_NAME' => $row['name'],
                    'DATA_TYPE' => $row['data_type'],
                    'COLUMN_TYPE' => $row['column_type'],
                    'CHARACTER_MAXIMUM_LENGTH' => $row['length'],
                    'IS_NULLABLE' => $row['nullable'],
                    'EXTRA' => $row['extra'],
                    'COLUMN_KEY' => $row['column_key'],
                    'COLUMN_DEFAULT' => $row['default'],
                ],
                $this->shape
            );
        }
        return [];
    }
}

/** @var array<string,mixed> */
$fakeOptions = [];
/** @var array<string,int> */
$fakeOptionWriteFailures = [];
$fakeActivation = null;

function get_option(string $name, mixed $default = false): mixed {
    global $fakeOptions, $wpdb;
    if (($GLOBALS['fakeOptionReadFailures'][$name] ?? 0) > 0) {
        $GLOBALS['fakeOptionReadFailures'][$name]--;
        $wpdb->last_error = 'simulated option read failure';
        return $default;
    }
    return array_key_exists($name, $fakeOptions) ? $fakeOptions[$name] : $default;
}

function update_option(string $name, mixed $value, bool $autoload = true): bool {
    global $fakeOptions, $fakeOptionWriteFailures, $wpdb;
    $remaining = (int) ($fakeOptionWriteFailures[$name] ?? 0);
    if ($remaining > 0) {
        $fakeOptionWriteFailures[$name] = $remaining - 1;
        $wpdb->last_error = "simulated option write failure: $name";
        return false;
    }
    $fakeOptions[$name] = $value;
    return true;
}

function add_option(string $name, mixed $value, string $deprecated = '', bool $autoload = true): bool {
    global $fakeOptions;
    if (array_key_exists($name, $fakeOptions)) {
        return false;
    }
    return update_option($name, $value, $autoload);
}

function register_activation_hook(string $file, callable $callback): void {
    global $fakeActivation;
    $fakeActivation = $callback;
}

function register_deactivation_hook(string $file, callable $callback): void {}
function add_action(...$args): void {}
function register_rest_route(...$args): void {}

if (!class_exists('WooCommerce')) {
    class WooCommerce {}
}

function ecommerce_extension_child_fail(string $message): never {
    throw new RuntimeException($message);
}

function ecommerce_extension_child_check(bool $condition, string $message): void {
    if (!$condition) {
        ecommerce_extension_child_fail($message);
    }
}

function ecommerce_extension_child_expect_throw(callable $callback, string $label): Throwable {
    try {
        $callback();
    } catch (Throwable $error) {
        return $error;
    }
    ecommerce_extension_child_fail("$label unexpectedly returned successfully");
}

function ecommerce_extension_child_reset(EcommerceExtensionMigrationFakeWpdb $wpdb): void {
    global $fakeOptions, $fakeOptionWriteFailures, $fakeActivation;
    $fakeOptions = ['duo_commerce_extension_gateway_secret' => 'unit-test-secret'];
    $fakeOptionWriteFailures = [];
    $GLOBALS['fakeOptionReadFailures'] = [];
    $wpdb->shape = null;
    $wpdb->last_error = '';
    $wpdb->failProbe = 0;
    $wpdb->failCreate = 0;
    $wpdb->failAlter = 0;
    $fakeActivation = null;
}

function ecommerce_extension_child_set_v1(EcommerceExtensionMigrationFakeWpdb $wpdb): void {
    global $fakeOptions;
    $wpdb->shape = $wpdb->v1Shape;
    $fakeOptions['duo_commerce_extension_settings'] = 'retail';
    $fakeOptions['duo_commerce_extension_schema'] = 1;
}

function ecommerce_extension_child_set_no_state(EcommerceExtensionMigrationFakeWpdb $wpdb): void {
    global $fakeOptions;
    $wpdb->shape = null;
    unset($fakeOptions['duo_commerce_extension_settings'], $fakeOptions['duo_commerce_extension_schema']);
}

function ecommerce_extension_child_assert_v1(EcommerceExtensionMigrationFakeWpdb $wpdb): void {
    global $fakeOptions;
    ecommerce_extension_child_check(
        duo_commerce_extension_table_shape(false) === duo_commerce_extension_expected_table_shape(false),
        'expected exact normalized v1 table shape'
    );
    ecommerce_extension_child_check(
        ($fakeOptions['duo_commerce_extension_settings'] ?? null) === 'retail',
        'expected scalar v1 settings'
    );
    ecommerce_extension_child_check(
        ($fakeOptions['duo_commerce_extension_schema'] ?? null) === 1,
        'expected schema 1'
    );
}

function ecommerce_extension_child_assert_v2(EcommerceExtensionMigrationFakeWpdb $wpdb): void {
    global $fakeOptions;
    ecommerce_extension_child_check(
        duo_commerce_extension_table_shape(true) === duo_commerce_extension_expected_table_shape(true),
        'expected exact normalized v2 table shape'
    );
    ecommerce_extension_child_check(
        ($fakeOptions['duo_commerce_extension_settings'] ?? null)
            === ['schema' => 2, 'channel' => 'retail', 'catalog_mode' => 'managed'],
        'expected exact v2 settings'
    );
    ecommerce_extension_child_check(
        ($fakeOptions['duo_commerce_extension_schema'] ?? null) === 2,
        'expected schema 2'
    );
}

function ecommerce_extension_child_run(string $fixture, string $case): void {
    global $fakeOptions, $fakeOptionWriteFailures, $fakeActivation, $wpdb;
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/offline-wordpress/');
    }
    $wpdb = new EcommerceExtensionMigrationFakeWpdb();
    ecommerce_extension_child_reset($wpdb);
    include $fixture;

    switch ($case) {
        case 'v1-create':
            ecommerce_extension_child_set_no_state($wpdb);
            $wpdb->failCreate = 1;
            ecommerce_extension_child_expect_throw(
                static fn(): mixed => duo_commerce_extension_install_v1(),
                'v1 CREATE fault'
            );
            ecommerce_extension_child_check($wpdb->shape === null, 'v1 CREATE failure changed table state');
            ecommerce_extension_child_check(!array_key_exists('duo_commerce_extension_schema', $fakeOptions), 'v1 advertised schema after CREATE failure');
            ecommerce_extension_child_check(!array_key_exists('duo_commerce_extension_settings', $fakeOptions), 'v1 wrote settings after CREATE failure');
            duo_commerce_extension_install_v1();
            ecommerce_extension_child_assert_v1($wpdb);
            return;

        case 'v1-settings':
            ecommerce_extension_child_set_no_state($wpdb);
            $fakeOptionWriteFailures['duo_commerce_extension_settings'] = 1;
            ecommerce_extension_child_expect_throw(
                static fn(): mixed => duo_commerce_extension_install_v1(),
                'v1 settings fault'
            );
            ecommerce_extension_child_check($wpdb->shape === $wpdb->v1Shape, 'v1 settings failure lost table');
            ecommerce_extension_child_check(!array_key_exists('duo_commerce_extension_settings', $fakeOptions), 'v1 settings fault wrote settings');
            ecommerce_extension_child_check(!array_key_exists('duo_commerce_extension_schema', $fakeOptions), 'v1 advertised schema after settings failure');
            duo_commerce_extension_install_v1();
            ecommerce_extension_child_assert_v1($wpdb);
            return;

        case 'v1-schema':
            ecommerce_extension_child_set_no_state($wpdb);
            $fakeOptionWriteFailures['duo_commerce_extension_schema'] = 1;
            ecommerce_extension_child_expect_throw(
                static fn(): mixed => duo_commerce_extension_install_v1(),
                'v1 schema fault'
            );
            ecommerce_extension_child_check($wpdb->shape === $wpdb->v1Shape, 'v1 schema failure lost table');
            ecommerce_extension_child_check(($fakeOptions['duo_commerce_extension_settings'] ?? null) === 'retail', 'v1 schema failure lost verified settings');
            ecommerce_extension_child_check(!array_key_exists('duo_commerce_extension_schema', $fakeOptions), 'v1 advertised schema after schema failure');
            duo_commerce_extension_install_v1();
            ecommerce_extension_child_assert_v1($wpdb);
            return;

        case 'v2-probe':
            ecommerce_extension_child_set_v1($wpdb);
            $wpdb->failProbe = 1;
            ecommerce_extension_child_expect_throw(
                static fn(): mixed => duo_commerce_extension_migrate_v1_to_v2(),
                'v2 schema probe fault'
            );
            ecommerce_extension_child_assert_v1($wpdb);
            duo_commerce_extension_migrate_v1_to_v2();
            ecommerce_extension_child_assert_v2($wpdb);
            return;

        case 'shape-normalization':
            ecommerce_extension_child_set_v1($wpdb);
            $wpdb->shape[0]['column_type'] = 'bigint(20) unsigned';
            ecommerce_extension_child_check(
                duo_commerce_extension_table_shape(false) === duo_commerce_extension_expected_table_shape(false),
                'MariaDB integer display width was treated as a schema mismatch'
            );
            $mariaShape = $wpdb->shape;
            $wrongShapes = [];
            $wrong = $wpdb->shape;
            $wrong[0]['column_type'] = 'bigint(21)';
            $wrongShapes['signed bigint'] = $wrong;
            $wrong = $wpdb->shape;
            $wrong[1]['column_type'] = 'varchar(190)';
            $wrong[1]['length'] = 190;
            $wrongShapes['wrong varchar length'] = $wrong;
            $wrong = $wpdb->shape;
            $wrong[0]['column_key'] = '';
            $wrongShapes['missing primary key'] = $wrong;
            $wrong = $wpdb->shape;
            $wrongShapes['wrong column order'] = [$wrong[1], $wrong[0], $wrong[2]];
            foreach ($wrongShapes as $label => $wrongShape) {
                $wpdb->shape = $wrongShape;
                ecommerce_extension_child_expect_throw(
                    static fn(): mixed => duo_commerce_extension_assert_table_shape(false),
                    $label . ' shape fault'
                );
            }
            $wrongContext = $wpdb->v2Shape;
            $wrongContext[2]['default'] = 'unexpected';
            $wpdb->shape = $wrongContext;
            ecommerce_extension_child_expect_throw(
                static fn(): mixed => duo_commerce_extension_assert_table_shape(true),
                'wrong context default shape fault'
            );
            $wpdb->shape = $mariaShape;
            duo_commerce_extension_migrate_v1_to_v2();
            ecommerce_extension_child_assert_v2($wpdb);
            return;

        case 'v2-alter':
            ecommerce_extension_child_set_v1($wpdb);
            $wpdb->failAlter = 1;
            ecommerce_extension_child_expect_throw(
                static fn(): mixed => duo_commerce_extension_migrate_v1_to_v2(),
                'v2 ALTER fault'
            );
            ecommerce_extension_child_assert_v1($wpdb);
            duo_commerce_extension_migrate_v1_to_v2();
            ecommerce_extension_child_assert_v2($wpdb);
            return;

        case 'v2-settings':
            ecommerce_extension_child_set_v1($wpdb);
            $fakeOptionWriteFailures['duo_commerce_extension_settings'] = 1;
            ecommerce_extension_child_expect_throw(
                static fn(): mixed => duo_commerce_extension_migrate_v1_to_v2(),
                'v2 settings fault'
            );
            ecommerce_extension_child_check($wpdb->shape === $wpdb->v2Shape, 'v2 settings fault did not verify ALTER shape');
            ecommerce_extension_child_check(($fakeOptions['duo_commerce_extension_settings'] ?? null) === 'retail', 'v2 settings fault changed scalar prematurely');
            ecommerce_extension_child_check(($fakeOptions['duo_commerce_extension_schema'] ?? null) === 1, 'v2 settings fault advertised schema 2');
            duo_commerce_extension_migrate_v1_to_v2();
            ecommerce_extension_child_assert_v2($wpdb);
            return;

        case 'v2-schema':
            ecommerce_extension_child_set_v1($wpdb);
            $fakeOptionWriteFailures['duo_commerce_extension_schema'] = 1;
            ecommerce_extension_child_expect_throw(
                static fn(): mixed => duo_commerce_extension_migrate_v1_to_v2(),
                'v2 schema fault'
            );
            ecommerce_extension_child_check($wpdb->shape === $wpdb->v2Shape, 'v2 schema fault lost verified ALTER shape');
            ecommerce_extension_child_check(
                ($fakeOptions['duo_commerce_extension_settings'] ?? null)
                    === ['schema' => 2, 'channel' => 'retail', 'catalog_mode' => 'managed'],
                'v2 schema fault did not preserve partial settings transition'
            );
            ecommerce_extension_child_check(($fakeOptions['duo_commerce_extension_schema'] ?? null) === 1, 'v2 schema fault advertised schema 2');
            duo_commerce_extension_migrate_v1_to_v2();
            ecommerce_extension_child_assert_v2($wpdb);
            return;

        case 'v2-create':
            ecommerce_extension_child_set_no_state($wpdb);
            $wpdb->failCreate = 1;
            ecommerce_extension_child_expect_throw(
                static fn(): mixed => duo_commerce_extension_install_v2(),
                'v2 CREATE fault'
            );
            ecommerce_extension_child_check($wpdb->shape === null, 'v2 CREATE failure changed table state');
            ecommerce_extension_child_check(!array_key_exists('duo_commerce_extension_schema', $fakeOptions), 'v2 advertised schema after CREATE failure');
            ecommerce_extension_child_check(!array_key_exists('duo_commerce_extension_settings', $fakeOptions), 'v2 wrote settings after CREATE failure');
            duo_commerce_extension_install_v2();
            ecommerce_extension_child_assert_v2($wpdb);
            return;

        case 'broken-activation':
            ecommerce_extension_child_set_v1($wpdb);
            duo_commerce_extension_migrate_v1_to_v2();
            ecommerce_extension_child_assert_v2($wpdb);
            ecommerce_extension_child_check(is_callable($fakeActivation), 'broken fixture did not register activation callback');
            $error = ecommerce_extension_child_expect_throw(
                static fn(): mixed => $fakeActivation(),
                'broken activation'
            );
            ecommerce_extension_child_check(
                $error->getMessage() === 'Duo Commerce Extension reviewed v2 activation failure',
                'broken activation message changed'
            );
            ecommerce_extension_child_assert_v2($wpdb);
            return;

        default:
            ecommerce_extension_child_fail("unknown migration case: $case");
    }
}

function ecommerce_extension_migration_parent(): void {
    $root = realpath(dirname(__DIR__, 2));
    if ($root === false) {
        throw new RuntimeException('could not resolve scenario root');
    }
    $fixtures = [
        'v1' => $root . '/sandbox/fixtures/duo-ecommerce-developer-grind/v1/wp-content/plugins/duo-commerce-extension/duo-commerce-extension.php',
        'fixed-v2' => $root . '/sandbox/fixtures/duo-ecommerce-developer-grind/v2/fixed/duo-commerce-extension.php',
        'broken-v2' => $root . '/sandbox/fixtures/duo-ecommerce-developer-grind/v2/broken/duo-commerce-extension.php',
    ];
    $cases = [
        ['fixture' => 'v1', 'case' => 'v1-create'],
        ['fixture' => 'v1', 'case' => 'v1-settings'],
        ['fixture' => 'v1', 'case' => 'v1-schema'],
        ['fixture' => 'fixed-v2', 'case' => 'shape-normalization'],
        ['fixture' => 'fixed-v2', 'case' => 'v2-probe'],
        ['fixture' => 'fixed-v2', 'case' => 'v2-alter'],
        ['fixture' => 'fixed-v2', 'case' => 'v2-settings'],
        ['fixture' => 'fixed-v2', 'case' => 'v2-schema'],
        ['fixture' => 'fixed-v2', 'case' => 'v2-create'],
        ['fixture' => 'broken-v2', 'case' => 'broken-activation'],
    ];
    $passed = 0;
    foreach ($cases as $entry) {
        $fixture = $fixtures[$entry['fixture']];
        if (!is_file($fixture)) {
            throw new RuntimeException("fixture not found: $fixture");
        }
        $command = implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__FILE__),
            '--child',
            escapeshellarg($fixture),
            escapeshellarg($entry['case']),
        ]);
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root
        );
        if (!is_resource($process)) {
            throw new RuntimeException("could not start child for {$entry['case']}");
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException(
                "{$entry['case']} failed (exit $exitCode): " . trim($stderr . "\n" . $stdout)
            );
        }
        $result = json_decode(trim($stdout), true);
        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            throw new RuntimeException("{$entry['case']} returned invalid child result: " . trim($stdout));
        }
        echo "ok: {$entry['case']}\n";
        $passed++;
    }
    echo "ecommerce extension migration regression: $passed/" . count($cases) . " passed\n";
}

try {
    if (($argv[1] ?? '') === '--child') {
        $fixture = (string) ($argv[2] ?? '');
        $case = (string) ($argv[3] ?? '');
        ecommerce_extension_child_run($fixture, $case);
        echo json_encode(['ok' => true, 'case' => $case], JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
    ecommerce_extension_migration_parent();
} catch (Throwable $error) {
    if (($argv[1] ?? '') === '--child') {
        fwrite(STDERR, $error->getMessage() . "\n");
        echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
