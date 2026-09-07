<?php
declare(strict_types=1);

use WPrism\DatabaseMutationException;
use WPrism\Db;
use WPrism\Ledger;
use WPrism\NativeDatabaseProfile;
use WPrism\ProviderDatabaseSession;

/** Deterministic values remain reconstructible without retaining 7 MiB of quotes. */
function native_keyed_values(): Generator {
    yield 'utf8-frontiers' => str_repeat('日本語🦊', 90000);
    yield 'sql-data' => str_repeat("\0'\\;--/*%\n", 120000);
    yield 'maximum-quoting' => str_repeat("'", 7340032);
}

// Host admission uses the same closed payload roster after disposable teardown.
// It admits all transport bytes before interpreting the native assertions.
if (($argv[1] ?? '') === '--admit') {
    require_once __DIR__ . '/../lib/PrivateCommandOutput.php';
    $record = json_decode(WPrismTest\PrivateCommandOutput::readObject(
        $argv[2] ?? '',
        '/^ ?Container wprism-[a-z0-9]+-cli1-run-[a-z0-9]+ (Creating|Created) *$/D'
    ), true, 32, JSON_THROW_ON_ERROR);
    $expected = [];
    foreach (native_keyed_values() as $case => $value) {
        $expected[$case] = ['bytes' => strlen($value), 'sha256' => hash('sha256', $value)];
    }
    if (array_keys($record) !== ['format', 'engine', 'values', 'transactional', 'field_refusal', 'append_refusal',
        'commit_refusal', 'retry', 'before', 'after', 'restored']
        || $record['format'] !== 'wprism-native-keyed-values/v1'
        || $record['engine'] !== ($argv[3] ?? '') || !in_array($record['engine'], ['MariaDB', 'MySQL'], true)
        || $record['values'] !== $expected || $record['transactional'] !== true
        || $record['field_refusal'] !== true || $record['append_refusal'] !== true
        || $record['commit_refusal'] !== true || $record['retry'] !== true
        || !is_array($record['before']) || !array_is_list($record['before']) || $record['before'] === []
        || $record['after'] !== $record['before'] || $record['restored'] !== true
        || !in_array(['k' => 'wprism_large_values_untouched', 'v' => 'original exact value'], $record['before'], true)) {
        throw new RuntimeException('native keyed-value record does not prove the complete declared contract');
    }
    exit(0);
}

if (!defined('ABSPATH') || !class_exists(Ledger::class)) {
    throw new RuntimeException('native keyed-value fixture requires the loaded WordPress product');
}

function native_keyed_require(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function native_keyed_snapshot(string $table): array {
    return ProviderDatabaseSession::read_only_snapshot(
        'native keyed-value complete row observation', NativeDatabaseProfile::read_only([$table]),
        static function () use ($table): array {
            global $wpdb;
            $wpdb->last_error = '';
            $rows = $wpdb->get_results("SELECT k, v FROM `$table` ORDER BY BINARY k LIMIT 257", ARRAY_A);
            native_keyed_require(is_array($rows) && array_is_list($rows) && count($rows) <= 256
                && (string) $wpdb->last_error === '', 'native keyed-value row observation failed or exceeded its roster');
            native_keyed_require(strlen(json_encode($rows, JSON_THROW_ON_ERROR)) <= 131072,
                'native keyed-value premise exceeds the complete retained-row boundary');
            return $rows;
        }
    );
}

global $wpdb;
Ledger::ensure();
$table = $wpdb->prefix . 'wprism_kv';
$keys = ['wprism_large_values_untouched', 'wprism_large_values_value', 'wprism_large_values_revision'];
$original = native_keyed_snapshot($table);
foreach ($keys as $key) native_keyed_require(Ledger::kv_get($key) === null, 'native keyed-value fixture key is occupied');
native_keyed_require(getenv('WPRISM_TEST_MODE') === false && getenv('WPRISM_TEST_FAIL_DB_CONTEXT') === false,
    'native keyed-value failure switches must start absent');
Ledger::kv_set($keys[0], 'original exact value');
$before = native_keyed_snapshot($table);
$values = [];
foreach (native_keyed_values() as $case => $value) {
    Ledger::kv_set($keys[1], 'before replacement');
    Ledger::kv_set($keys[1], $value);
    native_keyed_require(Ledger::kv_get($keys[1]) === $value, 'native keyed-value replacement changed complete bytes');
    Ledger::kv_set($keys[1], $value);
    native_keyed_require(Ledger::kv_get($keys[1]) === $value, 'native keyed-value repeated write changed complete bytes');
    $values[$case] = ['bytes' => strlen($value), 'sha256' => hash('sha256', $value)];
    Ledger::kv_delete($keys[1]);
    native_keyed_require(native_keyed_snapshot($table) === $before, 'native keyed-value write changed unrelated rows');
}
unset($value);
$large = str_repeat('complete native value ', 60000);
Db::start('native keyed-value batch start', new NativeDatabaseProfile([], [$table]));
Ledger::kv_set_transactional([$keys[1] => $large, $keys[2] => 'complete revision'],
    Db::transaction_authority('native keyed-value batch caller'));
Db::commit('native keyed-value batch commit');
native_keyed_require(Ledger::kv_get($keys[1]) === $large && Ledger::kv_get($keys[2]) === 'complete revision',
    'native keyed-value batch did not publish both complete members');
Ledger::kv_delete($keys[1]);
Ledger::kv_delete($keys[2]);
native_keyed_require(native_keyed_snapshot($table) === $before, 'native keyed-value batch changed unrelated rows');

// 192 ASCII characters fit the engine's key-byte budget but not this real
// VARCHAR(191). wpdb must reject the whole field before the first upsert.
$fieldFailure = null;
try { Ledger::kv_set(str_repeat('k', 192), $large); } catch (Throwable $caught) { $fieldFailure = $caught; }
native_keyed_require($fieldFailure instanceof DatabaseMutationException && native_keyed_snapshot($table) === $before,
    'native field-width refusal did not preserve the complete row set');

Db::start('native keyed-value interrupted batch start', new NativeDatabaseProfile([], [$table]));
Ledger::kv_set($keys[2], 'uncommitted sibling');
putenv('WPRISM_TEST_MODE=1');
putenv('WPRISM_TEST_FAIL_DB_CONTEXT=ledger upsert key/value append');
$appendFailure = null;
try { Ledger::kv_set($keys[1], $large); } catch (Throwable $caught) { $appendFailure = $caught; }
finally {
    putenv('WPRISM_TEST_MODE');
    putenv('WPRISM_TEST_FAIL_DB_CONTEXT');
}
if (!$appendFailure instanceof DatabaseMutationException
    || $appendFailure->mutationContext !== 'ledger upsert key/value append (injected)') {
    // A fixture assertion must settle its transaction before throwing too;
    // otherwise shutdown hooks obscure the original failure with quarantine.
    if ($appendFailure !== null) Db::rollback_after_failure($appendFailure, 'native unexpected append rollback');
    else Db::rollback('native missing append fault rollback');
    throw new RuntimeException('native interrupted batch did not reach its declared append fault', 0, $appendFailure);
}
$commitFailure = null;
try { Db::commit('native keyed-value swallowed append commit'); }
catch (Throwable $caught) {
    $commitFailure = $caught;
    Db::rollback_after_failure($caught, 'native keyed-value swallowed append rollback');
}
native_keyed_require($commitFailure !== null && native_keyed_snapshot($table) === $before,
    'native swallowed append failure published a partial batch');
Ledger::kv_set($keys[1], $large);
native_keyed_require(Ledger::kv_get($keys[1]) === $large, 'native keyed-value fresh retry did not converge');
Ledger::kv_delete($keys[1]);
$after = native_keyed_snapshot($table);
native_keyed_require($after === $before, 'native keyed-value retry changed unrelated rows');
Ledger::kv_delete($keys[0]);
native_keyed_require(native_keyed_snapshot($table) === $original, 'native keyed-value fixture did not restore its original rows');

echo json_encode([
    'format' => 'wprism-native-keyed-values/v1',
    'engine' => WPrism\PlatformCompatibility::current_facts()['database']['engine'],
    'values' => $values, 'transactional' => true, 'field_refusal' => true, 'append_refusal' => true,
    'commit_refusal' => true, 'retry' => true, 'before' => $before, 'after' => $after, 'restored' => true,
], JSON_THROW_ON_ERROR), "\n";
