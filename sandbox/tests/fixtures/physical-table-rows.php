<?php
declare(strict_types=1);

use WPrism\ManifestProviderRuntime;
use WPrism\ProviderSdk;
use WPrism\Providers;

function native_physical_descriptor(int $arity = 2): array {
    return ['table' => 'wp_wprism_physical_rows_probe',
        'columns' => ['object_id', 'term_id', 'third_id', 'fourth_id', 'payload', 'label'],
        'identity' => array_slice(['object_id', 'term_id', 'third_id', 'fourth_id'], 0, $arity),
        'max_rows' => 256, 'max_raw_bytes' => 2097152, 'mode' => 'rows'];
}

function native_physical_rows(string $stage): array {
    $rows = [];
    for ($id = 1; $id <= 130; $id++) {
        $rows[] = ['object_id' => (string) (1 + intdiv($id - 1, 65)), 'term_id' => (string) (1 + ($id - 1) % 65),
            'third_id' => '1', 'fourth_id' => '1',
            'payload' => $id === 130 ? str_repeat('x', 65536) : ($id % 3 === 0 ? null : "raw\0\xff'\\" . $id),
            'label' => $id % 2 === 0 ? '' : '日本語🦊'];
    }
    if ($stage !== 'initial') $rows[0]['payload'] = "updated\0\xfe'\\value";
    if ($stage === 'tuples') array_splice($rows, 1, 0, [
        array_replace($rows[0], ['fourth_id' => '2']), array_replace($rows[0], ['third_id' => '2']),
    ]);
    return $rows;
}

/** Native output is checked against independently framed complete known bytes. */
function native_physical_expected(array $rows, int $arity): array {
    $descriptor = native_physical_descriptor($arity);
    $frame = "wprism-physical-table-rows/v1\0";
    $frame .= pack('N', strlen($descriptor['table'])) . $descriptor['table'];
    $frame .= pack('N', count($descriptor['identity']));
    foreach ($descriptor['identity'] as $column) $frame .= pack('N', strlen($column)) . $column;
    $frame .= pack('N', count($descriptor['columns']));
    foreach ($descriptor['columns'] as $column) $frame .= pack('N', strlen($column)) . $column;
    $bytes = 0;
    foreach ($rows as $row) {
        $frame .= 'R';
        foreach ($descriptor['columns'] as $column) {
            $value = $row[$column];
            $frame .= $value === null ? "\0" : "\1" . pack('N', strlen($value)) . $value;
            $bytes += $value === null ? 0 : strlen($value);
        }
    }
    return ['row_count' => count($rows), 'raw_bytes' => $bytes,
        'rows_sha256' => hash('sha256', $frame . 'E' . pack('N', count($rows)))];
}

if (($argv[1] ?? '') === '--admit') {
    require_once __DIR__ . '/../lib/PrivateCommandOutput.php';
    $record = json_decode(WPrismTest\PrivateCommandOutput::readObject($argv[2] ?? '',
        '/^ ?Container wprism-[a-z0-9]+-cli1-run-[a-z0-9]+ (Creating|Created) *$/D'), true, 32, JSON_THROW_ON_ERROR);
    $expected = ['format' => 'wprism-native-physical-rows/v1', 'engine' => $argv[3] ?? '',
        'initial' => native_physical_expected(native_physical_rows('initial'), 2),
        'updated' => native_physical_expected(native_physical_rows('updated'), 2),
        'tuples' => native_physical_expected(native_physical_rows('tuples'), 4),
        'refusals' => ['row_budget', 'duplicate_two', 'duplicate_three', 'oversized_cell', 'driver_column'],
        'idempotent_update' => true, 'rollback' => true, 'restored' => true];
    if (!in_array($expected['engine'], ['MariaDB', 'MySQL'], true) || $record !== $expected) {
        throw new RuntimeException('native physical-row record does not prove the complete declared contract');
    }
    exit(0);
}

if (!defined('ABSPATH') || !class_exists(ProviderSdk::class)) {
    throw new RuntimeException('native physical-row fixture requires the loaded WordPress product');
}

/** Only the fixture crosses the private loader join; scopes and DML remain real SDK calls. */
final class NativePhysicalRowsProbe extends ManifestProviderRuntime {
    private static ?Closure $operation = null;
    private static mixed $result = null;

    public static function run(bool $write, callable $operation): mixed {
        $runtime = new self(['source' => 'manifest', 'id' => 'native-physical-rows-probe', 'plugin' => 'fixture/fixture.php',
            'version' => '1.0.0', 'capabilities' => ['physical_rows'], 'contracts' => ['physical_rows' => [
                'args' => [], 'idempotent' => true, 'scope' => 'site', 'timeout_seconds' => 60,
                'reads' => $write ? [] : ['table:wprism_physical_rows_probe'],
                'writes' => $write ? ['table:wprism_physical_rows_probe'] : [],
            ]]]);
        (new ReflectionMethod(Providers::class, 'bind_manifest_runtime_contracts'))->invoke(null, $runtime, $runtime->capabilities());
        self::$operation = $write
            ? static fn(): mixed => ProviderSdk::database_write_contract_transaction('native physical row mutation', $operation,
                static fn(mixed $_result): string => ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN)
            : static fn(): mixed => ProviderSdk::database_read_contract_snapshot('native physical row observation', $operation);
        try {
            $runtime->invoke('physical_rows', []);
            return self::$result;
        } finally {
            self::$operation = null;
            self::$result = null;
        }
    }

    protected function invoke_physical_rows(array $args): array {
        self::$result = (self::$operation)();
        return ['before' => [], 'after' => [], 'verified' => true];
    }
}

function native_physical_require(bool $condition, string $context): void {
    if (!$condition) throw new RuntimeException('native physical-row proof failed: ' . $context);
}

function native_physical_observe(int $arity = 2, array $changes = []): array {
    return NativePhysicalRowsProbe::run(false, static fn(): array => ProviderSdk::physical_table_rows(
        array_replace(native_physical_descriptor($arity), $changes), 'native physical rows'));
}

function native_physical_verify(string $stage, int $arity = 2): array {
    $rows = native_physical_rows($stage);
    $observed = native_physical_observe($arity);
    native_physical_require($observed['rows'] === $rows, $stage . ' complete exact native rows');
    unset($observed['rows']);
    native_physical_require($observed === native_physical_expected($rows, $arity), $stage . ' independent native frame');
    native_physical_require($observed === native_physical_observe($arity, ['mode' => 'digest']), $stage . ' digest-only witness');
    return $observed;
}

function native_physical_refuse(callable $operation, string $reason): void {
    $failure = null;
    try { $operation(); } catch (Throwable $caught) { $failure = $caught; }
    native_physical_require($failure instanceof RuntimeException && str_contains($failure->getMessage(), $reason), $reason);
}

global $wpdb;
$table = native_physical_descriptor()['table'];
native_physical_require($wpdb->prefix === 'wp_', 'fixture requires the disposable pair prefix');
native_physical_require($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === null,
    'fixture table must not exist before creation');
native_physical_require($wpdb->query("CREATE TABLE `$table` (object_id BIGINT NOT NULL, term_id BIGINT NOT NULL,
    third_id BIGINT NOT NULL, fourth_id BIGINT NOT NULL, payload LONGBLOB NULL, label LONGTEXT NOT NULL,
    PRIMARY KEY (object_id, term_id, third_id, fourth_id)) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4") !== false,
    'fixture plain InnoDB table creation');
try {
    NativePhysicalRowsProbe::run(true, static function () use ($table): void {
        foreach (array_reverse(native_physical_rows('initial')) as $row) {
            native_physical_require(ProviderSdk::database_insert($table, $row, 'native typed row seed', ['%d', '%d', '%d', '%d', '%s', '%s']) === 1,
                'one native inserted row');
        }
    });
    $initial = native_physical_verify('initial');
    foreach ([3, 4] as $arity) native_physical_verify('initial', $arity);
    native_physical_refuse(static fn(): array => native_physical_observe(2, ['max_rows' => 129]), 'row or cell-count budget exceeded');
    $where = ['object_id' => 1, 'term_id' => 1, 'third_id' => 1, 'fourth_id' => 1];
    $update = static fn(): int => ProviderSdk::database_update($table, ['payload' => native_physical_rows('updated')[0]['payload']],
        $where, 'native typed row update', '%s', '%d');
    native_physical_require(NativePhysicalRowsProbe::run(true, $update) === 1, 'native changed update count');
    native_physical_require(NativePhysicalRowsProbe::run(true, $update) === 0, 'native repeated update is a physical fixed point');
    $updated = native_physical_verify('updated');
    native_physical_refuse(static fn(): mixed => NativePhysicalRowsProbe::run(true, static function () use ($table, $where): never {
        ProviderSdk::database_update($table, ['payload' => null], $where, 'native typed rollback update', '%s', '%d');
        throw new RuntimeException('native postcondition rollback probe');
    }), 'native postcondition rollback probe');
    native_physical_require(native_physical_verify('updated') === $updated, 'failed native callback restores the complete preimage');
    NativePhysicalRowsProbe::run(true, static function () use ($table): void {
        foreach (array_slice(native_physical_rows('tuples'), 1, 2) as $row) {
            ProviderSdk::database_insert($table, $row, 'native composite tuple extension', ['%d', '%d', '%d', '%d', '%s', '%s']);
        }
    });
    native_physical_refuse(static fn(): array => native_physical_observe(2), 'duplicate or unordered identities');
    native_physical_refuse(static fn(): array => native_physical_observe(3), 'duplicate or unordered identities');
    $tuples = native_physical_verify('tuples', 4);
    // Malformed existing native bytes are fixture setup, not provider DML.
    // A short REPEAT statement crosses no reader or typed-write byte frontier.
    native_physical_require($wpdb->query("UPDATE `$table` SET payload = REPEAT('x', 1048577)
        WHERE object_id = 1 AND term_id = 1 AND third_id = 1 AND fourth_id = 1") === 1, 'oversized source setup');
    native_physical_refuse(static fn(): array => native_physical_observe(4), 'size roster has an invalid field');
    NativePhysicalRowsProbe::run(true, $update);
    native_physical_refuse(static fn(): array => native_physical_observe(4,
        ['columns' => ['object_id', 'term_id', 'third_id', 'fourth_id', 'absent_column']]), 'checked database read failed');
    native_physical_require(native_physical_verify('tuples', 4) === $tuples, 'all refusal paths preserve complete valid state');
} finally {
    native_physical_require($wpdb->query("DROP TABLE `$table`") !== false, 'fixture table cleanup');
}
native_physical_require($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === null, 'fixture table is absent after cleanup');
echo json_encode(['format' => 'wprism-native-physical-rows/v1',
    'engine' => WPrism\PlatformCompatibility::current_facts()['database']['engine'],
    'initial' => $initial, 'updated' => $updated, 'tuples' => $tuples,
    'refusals' => ['row_budget', 'duplicate_two', 'duplicate_three', 'oversized_cell', 'driver_column'],
    'idempotent_update' => true, 'rollback' => true, 'restored' => true], JSON_THROW_ON_ERROR), "\n";
