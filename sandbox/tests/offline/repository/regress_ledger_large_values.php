<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/ShellProbe.php';
require_once dirname(__DIR__, 4) . '/agent/src/Repository/Ledger.php';

use WPrism\Db;
use WPrism\DatabaseMutationException;
use WPrism\DatabaseQueryIsolationViolationException;
use WPrism\DeadlockTransactionAbortedException;
use WPrism\Ledger;
use WPrism\NativeDatabaseProfile;
use WPrismTest\FakeWpdb;
use WPrismTest\ShellProbe;

function large_ledger_fixture(): FakeWpdb {
    Db::forget_transaction_tracking();
    return FakeWpdb::install()->seedTable('wp_wprism_kv', [['k' => 'untouched', 'v' => 'original']])
        ->setTableEngine('wp_wprism_kv', 'InnoDB')->setUniqueKey('wp_wprism_kv', ['k'])
        ->setIndexes('wp_wprism_kv', [[
            'Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Seq_in_index' => 1,
            'Column_name' => 'k', 'Sub_part' => null, 'Index_type' => 'BTREE', 'Visible' => 'YES', 'Ignored' => 'NO',
        ]])->enableInformationSchema();
}

function large_ledger_failure(callable $operation): ?Throwable {
    try { $operation(); } catch (Throwable $failure) { return $failure; }
    return null;
}

function large_ledger_has_dml(FakeWpdb $database): bool {
    foreach ($database->queries() as $sql) {
        if (preg_match('/^(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql)) return true;
    }
    return false;
}

if (in_array($argv[1] ?? '', ['--reconnect-new', '--reconnect-same'], true)) {
    // An unknown old-session outcome deliberately survives inside this PHP
    // process. Test it in a real child; do not clear production quarantine to
    // make the next fixture run, or call that clearing "automatic recovery".
    $wpdb = large_ledger_fixture();
    $before = $wpdb->rows('wp_wprism_kv');
    $reconnected = false;
    $replacementId = $argv[1] === '--reconnect-same' ? 1 : 2;
    $wpdb->onQuery(static function (string $sql, string $method, FakeWpdb $database) use ($replacementId, &$reconnected): null {
        if (!$reconnected && str_contains($sql, ' = CONCAT(')) {
            $reconnected = true;
            $database->setConnectionId($replacementId);
        }
        return null;
    });
    $value = str_repeat('native value', 100000);
    $failure = large_ledger_failure(static fn() => Ledger::kv_set('key', $value));
    $wpdb->onQuery(null);
    $retry = large_ledger_failure(static fn() => Ledger::kv_set('key', $value));
    $commit = large_ledger_failure(static fn() => Db::commit('quarantined reconnect commit'));
    $idle = large_ledger_failure(static fn() => Db::connection_transaction_active('quarantined reconnect observation'));
    if (!$reconnected || !$failure instanceof WPrism\DatabaseTransactionOutcomeException
        || $retry === null || $commit === null || $idle === null || $before !== $wpdb->rows('wp_wprism_kv')) {
        throw new RuntimeException('keyed reconnect child did not preserve simulated database state and original-session quarantine');
    }
    echo json_encode(['case' => $argv[1], 'rows' => $wpdb->rows('wp_wprism_kv'), 'quarantined' => true], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

// Official WPForms 2.0.1.1 alone yields a 1,044,395-byte real code descriptor;
// SQL quoting crosses 1 MiB. Quotes, escapes and UTF-8 are data, not permission
// to widen the native callback's SQL grammar or statement/cumulative budgets.
$large = str_repeat('日本語 Ω \\"' . "\n", 70000);
foreach (['standalone', 'transactional'] as $mode) {
    $wpdb = large_ledger_fixture();
    $failure = null;
    try {
        if ($mode === 'standalone') {
            Ledger::kv_set('code_descriptor', $large);
        } else {
            Db::start('large ledger publication', new NativeDatabaseProfile([], ['wp_wprism_kv']));
            Ledger::kv_set_transactional(['code_descriptor' => $large, 'code_revision' => 'complete'],
                Db::transaction_authority('large ledger caller authority'));
            Db::commit('large ledger publication commit');
        }
    } catch (Throwable $caught) {
        $failure = $caught;
        if ($mode === 'transactional') Db::rollback_after_failure($caught, 'large ledger publication rollback');
    }
    wprism_check($failure === null, "$mode large ledger publication succeeds through the real database boundary"
        . ($failure ? ': ' . $failure->getMessage() : ''));
    if ($failure === null) {
        wprism_check_same($large, Ledger::kv_get('code_descriptor'), "$mode preserves complete value bytes");
        if ($mode === 'transactional') wprism_check_same('complete', Ledger::kv_get('code_revision'), 'transactional sibling publishes with its descriptor');
    }
    wprism_check_same('original', Ledger::kv_get('untouched'), "$mode preserves unrelated native rows");
    wprism_check(max(array_map('strlen', $wpdb->queries())) <= 1048576, "$mode never sends an oversized SQL statement");
}
$wpdb = large_ledger_fixture();
$original = $wpdb->rows('wp_wprism_kv');
$wpdb->onQuery(static fn(string $sql): ?string => str_contains($sql, ' = CONCAT(') ? 'controlled append failure' : null);
Db::start('swallowed append start', new NativeDatabaseProfile([], ['wp_wprism_kv']));
$appendFailure = null;
try {
    Ledger::kv_set('code_descriptor', $large);
} catch (Throwable $caught) {
    $appendFailure = $caught;
}
wprism_check($appendFailure !== null, 'controlled native append fault is actually exercised');
$commitFailure = null;
try {
    Db::commit('swallowed append commit');
} catch (Throwable $caught) {
    $commitFailure = $caught;
    Db::rollback_after_failure($caught, 'swallowed append rollback');
}
wprism_check($commitFailure !== null, 'catching a chunk failure cannot authorize a later partial-value commit');
wprism_check($original === $wpdb->rows('wp_wprism_kv'), 'failed multi-statement value preserves the complete original row set');

foreach ([
    'empty' => '',
    'last single chunk' => str_repeat('x', 262144),
    'first two chunks' => str_repeat('x', 262145),
    'three-byte frontier' => str_repeat('x', 262143) . '日本語',
    'four-byte frontier' => str_repeat('x', 262141) . '🦊tail',
    'inert SQL punctuation' => str_repeat("\0'\\;--/*%\n", 50000),
] as $case => $value) {
    $wpdb = large_ledger_fixture();
    $allSqlUtf8 = true;
    $wpdb->onQuery(static function (string $sql) use (&$allSqlUtf8): null {
        $allSqlUtf8 = $allSqlUtf8 && preg_match('//u', $sql) === 1;
        return null;
    });
    Ledger::kv_set('key', 'old exact value');
    Ledger::kv_set('key', $value);
    wprism_check(Ledger::kv_get('key') === $value, "$case replacement preserves every byte");
    wprism_check($allSqlUtf8, "$case never hands the database an incomplete UTF-8 fragment");
    $beforeRepeat = $wpdb->rows('wp_wprism_kv');
    Ledger::kv_set('key', $value);
    wprism_check($beforeRepeat === $wpdb->rows('wp_wprism_kv'), "$case repeated upsert has the same complete durable value");
    wprism_check(max(array_map('strlen', $wpdb->queries())) <= 1048576, "$case preserves the fixed statement frontier");
}

$wpdb = large_ledger_fixture();
$maximum = str_repeat("'", Db::KEYED_STRING_BYTE_LIMIT);
Ledger::kv_set('maximum', $maximum);
wprism_check(Ledger::kv_get('maximum') === $maximum, 'exact 7-MiB value survives worst-case doubled SQL quoting');
wprism_check(max(array_map('strlen', $wpdb->queries())) <= 1048576, 'maximum value never requires a statement-budget exception');
unset($maximum);

foreach ([
    'oversized value' => [['large', str_repeat('x', Db::KEYED_STRING_BYTE_LIMIT + 1)]],
    'oversized aggregate' => [['first', 'ok'], ['second', str_repeat('x', Db::KEYED_STRING_BYTE_LIMIT - 1)]],
    'duplicate key' => [['same', 'one'], ['same', 'two']],
    'invalid UTF-8' => [['first', 'good'], ['second', "\xc0\xaf"]],
    'oversized key' => [[str_repeat('k', 1025), 'value']],
    'non-string key' => [[42, 'value']],
    'non-string value' => [['key', 42]],
    'wrong tuple shape' => [['key', 'value', 'extra']],
    'empty roster' => [],
    'oversized roster' => array_fill(0, 257, ['key', 'value']),
] as $case => $rows) {
    $wpdb = large_ledger_fixture();
    $before = $wpdb->rows('wp_wprism_kv');
    $failure = large_ledger_failure(static fn() => Db::upsert_keyed_strings('wp_wprism_kv', 'k', 'v', $rows, 'invalid keyed rows'));
    wprism_check($failure instanceof InvalidArgumentException, "$case is refused by the shared input boundary");
    wprism_check(!large_ledger_has_dml($wpdb) && $before === $wpdb->rows('wp_wprism_kv'), "$case refuses the entire row set before any mutation");
}
unset($rows);

foreach (['missing', 'non-unique', 'prefix', 'composite'] as $case) {
    $wpdb = large_ledger_fixture();
    $index = ['Key_name' => 'candidate', 'Non_unique' => $case === 'non-unique' ? 1 : 0,
        'Seq_in_index' => 1, 'Column_name' => 'k', 'Sub_part' => $case === 'prefix' ? 32 : null, 'Index_type' => 'BTREE'];
    $indexes = $case === 'missing' ? [] : [$index];
    if ($case === 'composite') $indexes[] = array_replace($index, ['Seq_in_index' => 2, 'Column_name' => 'v']);
    $wpdb->setIndexes('wp_wprism_kv', $indexes);
    $before = $wpdb->rows('wp_wprism_kv');
    $failure = large_ledger_failure(static fn() => Ledger::kv_set('key', $large));
    wprism_check($failure !== null && str_contains($failure->getMessage(), 'index'), "$case index cannot authorize keyed publication");
    wprism_check(!large_ledger_has_dml($wpdb) && $before === $wpdb->rows('wp_wprism_kv'), "$case index refuses before row mutation");
}

foreach (['first write', 'append', 'readback', 'deadlock append', 'deadlock readback'] as $case) {
    $wpdb = large_ledger_fixture();
    $before = $wpdb->rows('wp_wprism_kv');
    $match = str_contains($case, 'readback') ? ' AS stored_key' : ($case === 'first write' ? 'INSERT INTO' : ' = CONCAT(');
    if (str_starts_with($case, 'deadlock')) $wpdb->simulateDeadlock($match);
    else $wpdb->failNextQuery('controlled keyed value failure', $match);
    $failure = large_ledger_failure(static fn() => Ledger::kv_set('key', $large));
    $expectedClass = str_starts_with($case, 'deadlock') ? DeadlockTransactionAbortedException::class : DatabaseMutationException::class;
    wprism_check($failure instanceof $expectedClass, "$case preserves the originating recovery error type");
    wprism_check($before === $wpdb->rows('wp_wprism_kv'), "$case rolls back every partial value byte");
    Ledger::kv_set('key', $large);
    wprism_check(Ledger::kv_get('key') === $large, "$case permits a fresh complete retry after settled rollback");
}

foreach (['missing', 'wrong key', 'wrong length', 'wrong hash', 'extra row'] as $case) {
    $wpdb = large_ledger_fixture();
    $before = $wpdb->rows('wp_wprism_kv');
    $row = ['stored_key' => 'key', 'stored_bytes' => (string) strlen($large), 'stored_sha256' => hash('sha256', $large)];
    if ($case === 'wrong key') $row['stored_key'] = 'KEY';
    if ($case === 'wrong length') $row['stored_bytes'] = '0' . $row['stored_bytes'];
    if ($case === 'wrong hash') $row['stored_sha256'] = str_repeat('0', 64);
    $observed = $case === 'missing' ? [] : ($case === 'extra row' ? [$row, $row] : [$row]);
    $wpdb->returnNextGetResultsAs($observed, ' AS stored_key');
    $failure = large_ledger_failure(static fn() => Ledger::kv_set('key', $large));
    wprism_check($failure instanceof DatabaseMutationException, "$case complete readback is refused");
    wprism_check($before === $wpdb->rows('wp_wprism_kv'), "$case readback cannot publish a partial or aliased result");
}

$wpdb = large_ledger_fixture();
$before = $wpdb->rows('wp_wprism_kv');
Db::start('explicit sibling start', new NativeDatabaseProfile([], ['wp_wprism_kv']));
$wpdb->failNextQuery('controlled batch append failure', ' = CONCAT(');
$failure = large_ledger_failure(static fn() => Ledger::kv_set_transactional(['sibling' => 'must roll back', 'descriptor' => $large], Db::transaction_authority('sibling authority')));
$commitFailure = large_ledger_failure(static fn() => Db::commit('failed sibling commit'));
wprism_check($failure instanceof DatabaseMutationException && $commitFailure !== null, 'one failed batch member quarantines its already-written sibling too');
if ($commitFailure !== null) Db::rollback_after_failure($commitFailure, 'failed sibling rollback');
wprism_check($before === $wpdb->rows('wp_wprism_kv'), 'failed batch preserves the complete original row set');

$wpdb = large_ledger_fixture();
Db::start('old authority start', new NativeDatabaseProfile([], ['wp_wprism_kv']));
$oldAuthority = Db::transaction_authority('old keyed authority');
Db::rollback('old authority rollback');
Db::start('new authority start', new NativeDatabaseProfile([], ['wp_wprism_kv']));
$failure = large_ledger_failure(static fn() => Db::upsert_keyed_strings('wp_wprism_kv', 'k', 'v', [['key', $large]], 'stale keyed authority', $oldAuthority));
wprism_check($failure instanceof WPrism\DatabaseTransactionOutcomeException && !large_ledger_has_dml($wpdb), 'a stale explicit transaction token cannot borrow the new transaction');
Db::rollback('new authority rollback');

$wpdb = large_ledger_fixture();
$before = $wpdb->rows('wp_wprism_kv');
Db::start('cumulative caller budget', new NativeDatabaseProfile([], ['wp_wprism_kv']));
$paddingSql = "SELECT '" . str_repeat('x', 524288) . "'";
for ($i = 0; $i < 30; ++$i) $wpdb->get_var($paddingSql);
$failure = large_ledger_failure(static fn() => Ledger::kv_set('key', $large));
wprism_check($failure instanceof DatabaseQueryIsolationViolationException && str_contains($failure->getMessage(), 'cumulative SQL-byte'), 'keyed chunks cannot replenish their enclosing callback byte quota');
if ($failure !== null) Db::rollback_after_failure($failure, 'cumulative caller rollback');
else Db::rollback('unexpected cumulative success rollback');
wprism_check($before === $wpdb->rows('wp_wprism_kv'), 'cumulative quota refusal preserves the full preimage');
foreach (['--reconnect-new', '--reconnect-same'] as $case) {
    [$status, $stdout, $stderr] = ShellProbe::run('exec "$1" -d memory_limit=128M "$2" "$3"',
        [PHP_BINARY, __FILE__, $case], dirname(__DIR__, 4));
    wprism_check($status === 0 && $stderr === '', "$case real child refuses replay, commit and in-process recovery without diagnostics");
    $record = json_decode($stdout, true);
    wprism_check($record === ['case' => $case, 'rows' => [['k' => 'untouched', 'v' => 'original']], 'quarantined' => true],
        "$case complete child record proves simulated database preservation and retained quarantine");
}

$wpdb = large_ledger_fixture();
$before = $wpdb->rows('wp_wprism_kv');
Db::start('caller statement budget', new NativeDatabaseProfile([], ['wp_wprism_kv']));
for ($i = 0; $i < 1023; ++$i) $wpdb->get_var('SELECT 1');
$failure = large_ledger_failure(static fn() => Ledger::kv_set('key', $large));
wprism_check($failure instanceof DatabaseQueryIsolationViolationException && str_contains($failure->getMessage(), 'statement-count'), 'a keyed writer cannot replenish its caller statement quota');
if ($failure !== null) Db::rollback_after_failure($failure, 'caller statement rollback');
else Db::rollback('unexpected caller statement success');
wprism_check($before === $wpdb->rows('wp_wprism_kv'), 'statement quota refusal preserves the complete preimage');

$wpdb = large_ledger_fixture();
Db::start('raw SQL frontier', new NativeDatabaseProfile([], ['wp_wprism_kv']));
$failure = large_ledger_failure(static fn() => $wpdb->get_var("SELECT '" . str_repeat('x', 1048576) . "'"));
wprism_check($failure instanceof DatabaseQueryIsolationViolationException && str_contains($failure->getMessage(), 'one-megabyte'), 'ordinary native SQL still has the exact original one-megabyte ceiling');
if ($failure !== null) Db::rollback_after_failure($failure, 'raw SQL frontier rollback');
else Db::rollback('unexpected raw SQL success');

$nativeValues = [];
foreach ([
    'utf8-frontiers' => str_repeat('日本語🦊', 90000),
    'sql-data' => str_repeat("\0'\\;--/*%\n", 120000),
    'maximum-quoting' => str_repeat("'", 7340032),
] as $case => $value) {
    $nativeValues[$case] = ['bytes' => strlen($value), 'sha256' => hash('sha256', $value)];
}
unset($value);
$nativeRows = [['k' => 'wprism_large_values_untouched', 'v' => 'original exact value']];
$nativeRecord = [
    'format' => 'wprism-native-keyed-values/v1', 'engine' => 'MariaDB', 'values' => $nativeValues,
    'transactional' => true, 'field_refusal' => true, 'append_refusal' => true, 'commit_refusal' => true,
    'retry' => true, 'before' => $nativeRows, 'after' => $nativeRows, 'restored' => true,
];
$nativeAdmission = <<<'SH'
set -euo pipefail
umask 077
native_sink=$(mktemp -d "$1/sandbox/tmp/keyed-admission.XXXXXX")
trap 'rm -f -- "$native_sink/native.stdout" "$native_sink/native.stderr" "$native_sink/native.exit"; rmdir "$native_sink"' EXIT
printf '%s\n' "$3" >"$native_sink/native.stdout"
printf '%s\n' "$4" >"$native_sink/native.stderr"
printf '%s\n' "$5" >"$native_sink/native.exit"
exec_status=0
"$2" "$1/sandbox/tests/fixtures/ledger-large-values.php" --admit "$native_sink/native" "$6" || exec_status=$?
exit "$exec_status"
SH;
foreach (['ready', 'mysql', 'wrong-engine', 'wrong-hash', 'failed-field', 'failed-append', 'failed-commit',
    'failed-retry', 'changed-row', 'empty-rows', 'php-stdout', 'php-stderr', 'nonzero', 'extra-field'] as $case) {
    $record = $nativeRecord;
    $engine = $case === 'mysql' ? 'MySQL' : 'MariaDB';
    if (in_array($case, ['mysql', 'wrong-engine'], true)) $record['engine'] = 'MySQL';
    if ($case === 'wrong-hash') $record['values']['maximum-quoting']['sha256'] = str_repeat('0', 64);
    if ($case === 'failed-field') $record['field_refusal'] = false;
    if ($case === 'failed-append') $record['append_refusal'] = false;
    if ($case === 'failed-commit') $record['commit_refusal'] = false;
    if ($case === 'failed-retry') $record['retry'] = false;
    if ($case === 'changed-row') $record['after'][0]['v'] = 'changed';
    if ($case === 'empty-rows') $record['before'] = $record['after'] = [];
    if ($case === 'extra-field') $record['unexpected'] = true;
    $bytes = json_encode($record, JSON_THROW_ON_ERROR);
    if ($case === 'php-stdout') $bytes = "PHP Warning: unexpected native diagnostic\n" . $bytes;
    [$status, $stdout, $stderr] = ShellProbe::run($nativeAdmission,
        [dirname(__DIR__, 4), PHP_BINARY, $bytes,
            $case === 'php-stderr' ? 'PHP Warning: unexpected native diagnostic'
                : " Container wprism-keyedtest-cli1-run-123abc Creating \n Container wprism-keyedtest-cli1-run-123abc Created ",
            $case === 'nonzero' ? '1' : '0', $engine], dirname(__DIR__, 4));
    wprism_check(in_array($case, ['ready', 'mysql'], true)
        ? $status === 0 && $stdout === '' && $stderr === '' : $status !== 0,
        "$case actual native keyed-value admission requires complete warning-free transport and preservation");
}
wprism_check_summary('REGRESS_LEDGER_LARGE_VALUES');
