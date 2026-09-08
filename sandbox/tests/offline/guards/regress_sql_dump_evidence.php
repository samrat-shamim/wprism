<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/check.php';
require_once dirname(__DIR__, 2) . '/lib/SqlDumpEvidence.php';

use WPrismTest\SqlDumpEvidence;
use WPrismTest\EvidenceSizeProfile;

if (($argv[1] ?? null) === '--bounded-projection') {
    $case = $argv[2] ?? '';
    $rows = match ($case) {
        'large-unselected' => "INSERT INTO `fixture` (`id`, `body`) VALUES (1,'" . str_repeat('x', 1900000) . "');\n",
        'native-large-unselected' => "INSERT INTO `fixture` (`id`, `body`) VALUES (1,'" . str_repeat('x', 15000000) . "');\n",
        'native-large-selected' => "INSERT INTO `fixture` (`id`, `body`) VALUES (1,'" . str_repeat('x', 5386435) . "');\n",
        'row-boundary' => str_repeat("INSERT INTO `fixture` (`id`) VALUES (1);\n", 10000),
        'row-overflow' => str_repeat("INSERT INTO `fixture` (`id`) VALUES (1);\n", 50000),
        default => throw new RuntimeException('unknown constrained projection case'),
    };
    $bytes = "CREATE TABLE `fixture` (\n);\n" . $rows;
    unset($rows);
    try {
        $profile = str_starts_with($case, 'native-') ? EvidenceSizeProfile::NATIVE_DATABASE : EvidenceSizeProfile::CONFORMANCE_TREE;
        $selected = SqlDumpEvidence::projectColumns($bytes, 'fixture', $case === 'native-large-selected' ? ['id', 'body'] : ['id'], $profile);
        $result = ['accepted' => true, 'rows' => count($selected)];
        if ($case === 'native-large-selected') $result['body_bytes'] = strlen($selected[0]['body']);
    } catch (RuntimeException $failure) {
        $result = ['accepted' => false, 'rows' => null];
    }
    echo json_encode($result + ['limit' => ini_get('memory_limit'), 'peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

$tables = SqlDumpEvidence::tables("wp_users\tBASE TABLE\nwp_options\tBASE TABLE\n");
wprism_check_same(['wp_options', 'wp_users'], $tables, 'full table inventory is admitted without depending on database collation order');
wprism_check_same($tables, SqlDumpEvidence::tables("wp_users\tBASE TABLE\nwp_options\tBASE TABLE\n\n"),
    'native WP-CLI terminal separator is framing, not an unsupported table or a reason to trim interior rows');
$dump = "-- MariaDB dump 10.19 Distrib 11.4\n";
foreach ($tables as $table) {
    $dump .= "-- Table structure for table `$table`\nCREATE TABLE `$table` (\n  `id` int NOT NULL\n);\n"
        . "-- Dumping data for table `$table`\nINSERT INTO `$table` (`id`) VALUES (1);\n";
}
$dump .= "-- Dump completed\n";
SqlDumpEvidence::assertComplete($dump, $tables, $tables);
wprism_check(true, 'native complete dump with separately observed roster and nonempty rows is admitted');
foreach (["/*M!999999\\- enable the sandbox mode */\n" . $dump,
    "/*M!999999\\- enable the sandbox mode */ \n" . $dump,
    str_replace('MariaDB dump', 'MySQL dump', $dump)] as $variant) {
    SqlDumpEvidence::assertComplete($variant, $tables, $tables);
    wprism_check(true, 'native MySQL and MariaDB header forms preserve the same complete-data contract');
}
foreach (['', "wp_users\tBASE TABLE", "wp_users\tVIEW\n", "wp_users\tBASE TABLE\nwp_users\tBASE TABLE\n",
    "wp_users\tBASE TABLE\n\n\n", "wp_users\tBASE TABLE\n\nwp_options\tBASE TABLE\n",
    "\nwp_users\tBASE TABLE\n", "\n", "\n\n", "unsafe`table\tBASE TABLE\n", "PHP Warning\n", str_repeat('x', 32769),
    implode('', array_map(static fn(int $n): string => "table_$n\tBASE TABLE\n", range(1, 129)))] as $bad) {
    wprism_check_throws(static fn() => SqlDumpEvidence::tables($bad), RuntimeException::class, 'incomplete, unsafe, duplicate, unsupported or oversized table inventory refuses');
}
foreach (['empty', 'missing-header', 'missing-footer', 'trailing-noise', 'dated-footer', 'table-subset', 'schema-subset',
    'data-subset', 'empty-data', 'duplicate-schema', 'foreign-table', 'oversized', 'header-two-spaces',
    'header-tab', 'header-noise', 'header-warning'] as $fault) {
    $bad = match ($fault) {
        'empty' => '',
        'missing-header' => substr($dump, strpos($dump, "\n") + 1),
        'missing-footer' => substr($dump, 0, -18),
        'trailing-noise' => $dump . "PHP Warning\n",
        'dated-footer' => str_replace('-- Dump completed', '-- Dump completed on 2026-09-07', $dump),
        'table-subset' => str_replace("-- Table structure for table `wp_users`\n", '', $dump),
        'schema-subset' => str_replace("CREATE TABLE `wp_users` (\n", '', $dump),
        'data-subset' => str_replace("-- Dumping data for table `wp_users`\n", '', $dump),
        'empty-data' => str_replace("INSERT INTO `wp_users` (`id`) VALUES (1);\n", '', $dump),
        'duplicate-schema' => str_replace("CREATE TABLE `wp_users` (\n", "CREATE TABLE `wp_users` (\nCREATE TABLE `wp_users` (\n", $dump),
        'foreign-table' => str_replace("-- Dump completed\n", "INSERT INTO `foreign` (`id`) VALUES (1);\n-- Dump completed\n", $dump),
        'oversized' => str_replace('-- Dump completed', str_repeat('x', 2097153) . "\n-- Dump completed", $dump),
        'header-two-spaces' => "/*M!999999\\- enable the sandbox mode */  \n" . $dump,
        'header-tab' => "/*M!999999\\- enable the sandbox mode */\t\n" . $dump,
        'header-noise' => "/*M!999999\\- enable the sandbox mode */ extra\n" . $dump,
        'header-warning' => "/*M!999999\\- enable the sandbox mode */ \nPHP Warning\n" . $dump,
    };
    wprism_check_throws(static fn() => SqlDumpEvidence::assertComplete($bad, $tables, $tables), RuntimeException::class, "dump admission refuses $fault");
}
foreach ([[], ['wp_users', 'wp_options'], ['wp_users', 'wp_users'], ['wp_options', []], ['x' => 'wp_options'], ['../unsafe']] as $badTables) {
    wprism_check_throws(static fn() => SqlDumpEvidence::assertComplete($dump, $badTables, $tables), RuntimeException::class, 'malformed caller roster cannot make partial output complete');
}
foreach ([[], ['foreign'], ['wp_users', 'wp_users'], [[]], ['x' => 'wp_users']] as $badPremise) {
    wprism_check_throws(static fn() => SqlDumpEvidence::assertComplete($dump, $tables, $badPremise), RuntimeException::class, 'nonempty caller premises are required, bounded and within the native roster');
}
$structures = SqlDumpEvidence::structures($dump, $tables, $tables);
wprism_check_same($tables, array_keys($structures), 'opaque schema sections cover the entire independently observed roster');
foreach ($tables as $table) wprism_check_same("-- Table structure for table `$table`\nCREATE TABLE `$table` (\n  `id` int NOT NULL\n);\n",
    $structures[$table], 'structure retention is byte-exact and excludes the data marker');
foreach (['index' => ",\n  KEY `fixture_index` (`id`)", 'engine' => ' ENGINE=InnoDB',
    'collation' => ' COLLATE=utf8mb4_bin', 'counter' => ' AUTO_INCREMENT=701', 'comment' => " COMMENT='preserve 701 Ω'"] as $kind => $change) {
    $changed = str_replace("  `id` int NOT NULL\n);\n", "  `id` int NOT NULL" . ($kind === 'index' ? $change : '')
        . "\n)" . ($kind !== 'index' ? $change : '') . ";\n", $dump);
    $changedStructures = SqlDumpEvidence::structures($changed, $tables, $tables);
    wprism_check($changedStructures !== $structures && $changed === str_replace($structures, $changedStructures, $dump),
        'exact structure bytes expose ' . $kind . ' drift without a field-name normalizer');
}
foreach (['missing' => str_replace("-- Dumping data for table `wp_options`\n", '', $dump),
    'duplicate' => str_replace("-- Table structure for table `wp_options`\n", "-- Table structure for table `wp_options`\n-- Table structure for table `wp_options`\n", $dump),
    'foreign' => str_replace('`wp_options`', '`foreign`', $dump),
    'mispaired' => strtr($dump, ['-- Dumping data for table `wp_options`' => '-- Dumping data for table `wp_users`',
        '-- Dumping data for table `wp_users`' => '-- Dumping data for table `wp_options`']),
    'wrong schema section' => strtr($dump, ['CREATE TABLE `wp_options`' => 'CREATE TABLE `wp_users`',
        'CREATE TABLE `wp_users`' => 'CREATE TABLE `wp_options`']),
    'oversized section' => str_replace("CREATE TABLE `wp_options` (\n", "CREATE TABLE `wp_options` (\n" . str_repeat(' ', 65536) . "\n", $dump)] as $kind => $bad) {
    if (in_array($kind, ['mispaired', 'wrong schema section'], true)) {
        SqlDumpEvidence::assertComplete($bad, $tables, $tables);
        wprism_check(true, 'complete global rosters alone cannot reject ' . $kind);
    }
    wprism_check_throws(static fn() => SqlDumpEvidence::structures($bad, $tables, $tables), RuntimeException::class,
        $kind . ' schema framing cannot produce a partial preservation witness');
}
$padding = '-- ' . str_repeat('x', 65536 - strlen($structures['wp_options']) - 4) . "\n";
$boundedStructure = str_replace("CREATE TABLE `wp_options` (\n", $padding . "CREATE TABLE `wp_options` (\n", $dump);
wprism_check_same(65536, strlen(SqlDumpEvidence::structures($boundedStructure, $tables, $tables)['wp_options']),
    'opaque table structure admits its exact byte boundary');
$column = static fn(string $name): string => $name . "\tbigint(20) unsigned\tNULL\tNO\tPRI\tNULL\tauto_increment\tselect,insert,update,references\t\n";
$columnBytes = $column('id') . "body\tlongtext\tutf8mb4_unicode_ci\tYES\t\tNULL\t\tselect,insert,update,references\tUnicode Ω\n";
wprism_check_same(['id', 'body'], SqlDumpEvidence::columnRoster($columnBytes), 'native full column metadata supplies every projection field in observed order');
wprism_check_same(['id', 'body'], SqlDumpEvidence::columnRoster($columnBytes . "\n"), 'native terminal separator is retained as framing');
$boundedColumns = substr($column('id'), 0, -1) . str_repeat('x', 65536 - strlen($column('id'))) . "\n";
wprism_check_same(['id'], SqlDumpEvidence::columnRoster($boundedColumns), 'raw column metadata admits its exact byte boundary');
wprism_check_same(128, count(SqlDumpEvidence::columnRoster(implode('', array_map($column, array_map(static fn(int $i): string => 'field_' . $i, range(1, 128)))))),
    'full native column roster admits its exact bound');
foreach (['', "\n", "\n\n", rtrim($columnBytes, "\n"), $columnBytes . "\n\n", $column('id') . $column('id'),
    $column('unsafe`'), $column(''), str_replace("\tNO\t", "\tNO\textra\t", $column('id')), $column('id') . "\n" . $column('body'),
    str_replace("\tNO\t", "\tN\rO\t", $column('id')), str_replace("\tNO\t", "\tN\0O\t", $column('id')),
    str_repeat('x', 65537), implode('', array_map($column, array_map(static fn(int $i): string => 'field_' . $i, range(1, 129))))] as $bad) {
    wprism_check_throws(static fn() => SqlDumpEvidence::columnRoster($bad), RuntimeException::class,
        'incomplete, duplicate, unsafe, misframed or oversized column metadata refuses');
}
$projectDump = static fn(string $rows): string => "-- MariaDB dump 10.19 Distrib 11.4\n"
    . "-- Table structure for table `fixture`\nCREATE TABLE `fixture` (\n `id` int NOT NULL\n);\n"
    . "-- Dumping data for table `fixture`\n" . $rows . "-- Dump completed\n";
$insert = static fn(string $values, string $names = '`id`, `payload`, `unused`'): string =>
    'INSERT INTO `fixture` (' . $names . ') VALUES (' . $values . ");\n";
foreach ([
    ["'plain'", 'plain'], ["'12'", '12'], ["'quote\\' and \\\\ slash'", "quote' and \\ slash"],
    ["'doubled '' quote'", "doubled ' quote"], ["'control\\0\\b\\n\\r\\t\\Z\\\"'", "control\0\x08\n\r\t\x1a\""],
    ["'東京 🚀 ,);('", '東京 🚀 ,);('], ['0x000aFF', "\0\n\xff"], ['NULL', null],
    ['0', 0], ['-42', -42], [(string) PHP_INT_MAX, PHP_INT_MAX], [(string) PHP_INT_MIN, PHP_INT_MIN],
] as [$literal, $value]) {
    $native = $projectDump($insert("7,$literal,-1.25e+12"));
    $original = hash('sha256', $native);
    wprism_check_same([['payload' => $value, 'id' => 7]], SqlDumpEvidence::projectColumns($native, 'fixture', ['payload', 'id']),
        'bounded selected scalar projection preserves type, escaping and caller column order');
    wprism_check_same($original, hash('sha256', $native), 'projection never changes complete native bytes');
}
wprism_check_same([], SqlDumpEvidence::projectColumns($projectDump(''), 'fixture', ['id']), 'complete empty table projects an empty row roster');
wprism_check_same([['id' => 7, 'payload' => 'reordered']],
    SqlDumpEvidence::projectColumns($projectDump($insert("'reordered',NULL,7", '`payload`, `unused`, `id`')), 'fixture', ['id', 'payload']),
    'column names bind values even when the complete native insert order changes');
foreach (["7,'a',NULL),(8,'b',NULL", "7,'a',NULL); DROP TABLE fixture; --", "7,'a'", "7,'a',NULL,9",
    "7,'unterminated,NULL", "7,'bad\\q',NULL", "7,'ok','bad\\q'", '7,0x0,0', '7,0xGG,0',
    "7,'ok',NOW()", "7,'ok',NULLx", "7,'ok',01", "7,'ok',true"] as $values) {
    wprism_check_throws(static fn() => SqlDumpEvidence::projectColumns($projectDump($insert($values)), 'fixture', ['id']),
        RuntimeException::class, 'malformed unselected values and extra fields/tuples cannot hide backing identities');
}
foreach (['1.5', '1e3', '9223372036854775808', '-9223372036854775809'] as $value) {
    wprism_check_throws(static fn() => SqlDumpEvidence::projectColumns($projectDump($insert("7,$value,NULL")), 'fixture', ['payload']),
        RuntimeException::class, 'selected numeric identity must be an exact bounded integer');
}
$oneRow = $insert("7,'present',NULL");
foreach ([strtolower($oneRow), ' ' . $oneRow, str_replace('INSERT INTO', 'REPLACE INTO', $oneRow),
    str_replace('INSERT INTO', 'INSERT IGNORE INTO', $oneRow), str_replace('`fixture`', 'fixture', $oneRow),
    $insert("7,'a',NULL", '`id`, `id`, `unused`'), $insert("7,'a',NULL", '`other`, `payload`, `unused`'),
    rtrim($oneRow, "\n"), str_replace(");\n", "); noise\n", $oneRow)] as $badRow) {
    wprism_check_throws(static fn() => SqlDumpEvidence::projectColumns($projectDump($badRow), 'fixture', ['id']),
        RuntimeException::class, 'non-native row framing and missing/duplicate columns refuse instead of disappearing');
}
foreach ([[], ['id', 'id'], [[]], ['id', []], ['unsafe`'], array_fill(0, 129, 'id'), ['named' => 'id']] as $columns) {
    wprism_check_throws(static fn() => SqlDumpEvidence::projectColumns($projectDump($oneRow), 'fixture', $columns),
        RuntimeException::class, 'projection authority is a bounded unique list of plain column names');
}
wprism_check_throws(static fn() => SqlDumpEvidence::projectColumns($projectDump($oneRow), 'missing', ['id']),
    RuntimeException::class, 'missing native schema cannot prove an empty backing table');
wprism_check_same(10000, count(SqlDumpEvidence::projectColumns($projectDump(str_repeat($oneRow, 10000)), 'fixture', ['id'])),
    'bounded row projection admits its exact row limit without silently deduplicating');
wprism_check_throws(static fn() => SqlDumpEvidence::projectColumns($projectDump(str_repeat($oneRow, 10001)), 'fixture', ['id']),
    RuntimeException::class, 'one extra projected row refuses');
$four = $insert('1,2,3,4', '`a`, `b`, `c`, `d`');
wprism_check_same(8192, count(SqlDumpEvidence::projectColumns($projectDump(str_repeat($four, 8192)), 'fixture', ['a', 'b', 'c', 'd'])),
    'projected cell budget has an exact admitted boundary');
wprism_check_throws(static fn() => SqlDumpEvidence::projectColumns($projectDump(str_repeat($four, 8193)), 'fixture', ['a', 'b', 'c', 'd']),
    RuntimeException::class, 'projected cell budget refuses independently of row count');
wprism_check_throws(static fn() => SqlDumpEvidence::projectColumns(str_repeat('x', 2097153), 'fixture', ['id']),
    RuntimeException::class, 'stream size is checked before projection allocation');
require_once dirname(__DIR__, 2) . '/lib/ShellProbe.php';
foreach (['large-unselected' => 1, 'row-boundary' => 10000, 'row-overflow' => null] as $case => $expectedRows) {
    [$status, $stdout, $stderr] = \WPrismTest\ShellProbe::run('exec "$1" -d memory_limit=16M "$2" --bounded-projection "$3"',
        [PHP_BINARY, __FILE__, $case], dirname(__DIR__, 4));
    wprism_check($status === 0 && $stderr === '', "bounded projection $case completes under an actual 16-MiB PHP ceiling without runtime diagnostics");
    $result = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
    wprism_check(($result['accepted'] ?? null) === ($expectedRows !== null) && ($result['rows'] ?? null) === $expectedRows
        && ($result['limit'] ?? null) === '16M' && is_int($result['peak_bytes'] ?? null) && $result['peak_bytes'] <= 16777216,
        "bounded projection $case has its exact admission verdict rather than an allocation failure");
}
$databaseProfile = EvidenceSizeProfile::NATIVE_DATABASE;
$largeDatabase = $projectDump($insert("7,'" . str_repeat('q', 5386435) . "',NULL"));
foreach ([static fn() => SqlDumpEvidence::assertComplete($largeDatabase, ['fixture'], ['fixture']),
    static fn() => SqlDumpEvidence::structures($largeDatabase, ['fixture'], ['fixture']),
    static fn() => SqlDumpEvidence::projectColumns($largeDatabase, 'fixture', ['id'])] as $defaultRead) {
    wprism_check_throws($defaultRead, RuntimeException::class, 'complete native database cannot enlarge the default 2-MiB budget');
}
SqlDumpEvidence::assertComplete($largeDatabase, ['fixture'], ['fixture'], $databaseProfile);
wprism_check(true, 'explicit native database profile admits a complete measured-size cache row without filtering it');
wprism_check_same(['fixture'], array_keys(SqlDumpEvidence::structures($largeDatabase, ['fixture'], ['fixture'], $databaseProfile)),
    'opaque structures propagate the caller-selected database budget into complete admission');
$largeRows = SqlDumpEvidence::projectColumns($largeDatabase, 'fixture', ['id', 'payload', 'unused'], $databaseProfile);
wprism_check(count($largeRows) === 1 && $largeRows[0]['id'] === 7 && $largeRows[0]['unused'] === null
    && strlen($largeRows[0]['payload']) === 5386435 && hash('sha256', $largeRows[0]['payload']) === hash('sha256', str_repeat('q', 5386435)),
    'all-column projection preserves the entire large native scalar rather than omitting or truncating it');
unset($largeRows, $largeDatabase);
$databaseLimit = 16777216;
$emptyScalarDump = $projectDump($insert("7,'',NULL"));
$boundaryDatabase = $projectDump($insert("7,'" . str_repeat('x', $databaseLimit - strlen($emptyScalarDump)) . "',NULL"));
wprism_check_same($databaseLimit, strlen($boundaryDatabase), 'native database fixture reaches exactly the declared byte boundary');
SqlDumpEvidence::assertComplete($boundaryDatabase, ['fixture'], ['fixture'], $databaseProfile);
wprism_check_same(['fixture'], array_keys(SqlDumpEvidence::structures($boundaryDatabase, ['fixture'], ['fixture'], $databaseProfile)),
    'complete schema evidence admits the exact 16-MiB database boundary');
wprism_check_same([['id' => 7]], SqlDumpEvidence::projectColumns($boundaryDatabase, 'fixture', ['id'], $databaseProfile),
    'bounded projection admits the exact whole-database byte boundary');
$overflowDatabase = str_replace("VALUES (7,'", "VALUES (7,'x", $boundaryDatabase);
unset($boundaryDatabase);
foreach ([static fn() => SqlDumpEvidence::assertComplete($overflowDatabase, ['fixture'], ['fixture'], $databaseProfile),
    static fn() => SqlDumpEvidence::structures($overflowDatabase, ['fixture'], ['fixture'], $databaseProfile),
    static fn() => SqlDumpEvidence::projectColumns($overflowDatabase, 'fixture', ['id'], $databaseProfile)] as $largeRead) {
    wprism_check_throws($largeRead, RuntimeException::class, 'one extra native database byte refuses independently of valid framing');
}
unset($overflowDatabase);
foreach ([static fn() => SqlDumpEvidence::assertComplete($dump, $tables, $tables, 'native-database/v2'),
    static fn() => SqlDumpEvidence::structures($dump, $tables, $tables, 'native-database/v2'),
    static fn() => SqlDumpEvidence::projectColumns($dump, 'wp_options', ['id'], 'native-database/v2')] as $unknownRead) {
    wprism_check_throws($unknownRead, RuntimeException::class, 'every SQL entrypoint rejects unknown caller budgets');
}
wprism_check_throws(static fn() => SqlDumpEvidence::structures($boundedStructure . 'x', $tables, $tables, $databaseProfile),
    RuntimeException::class, 'large database authority does not relax native footer framing');
wprism_check_throws(static fn() => SqlDumpEvidence::structures(str_replace("CREATE TABLE `wp_options` (\n", "x\nCREATE TABLE `wp_options` (\n", $boundedStructure), $tables, $tables, $databaseProfile),
    RuntimeException::class, 'large database authority does not relax the per-structure bound');
wprism_check_throws(static fn() => SqlDumpEvidence::projectColumns($projectDump(str_repeat($oneRow, 10001)), 'fixture', ['id'], $databaseProfile),
    RuntimeException::class, 'large database authority does not enlarge the row roster');
wprism_check_throws(static fn() => SqlDumpEvidence::projectColumns($projectDump(str_repeat($four, 8193)), 'fixture', ['a', 'b', 'c', 'd'], $databaseProfile),
    RuntimeException::class, 'large database authority does not enlarge the projected cell roster');
foreach (['native-large-unselected', 'native-large-selected'] as $case) {
    [$status, $stdout, $stderr] = \WPrismTest\ShellProbe::run('exec "$1" -d memory_limit=64M "$2" --bounded-projection "$3"',
        [PHP_BINARY, __FILE__, $case], dirname(__DIR__, 4));
    wprism_check($status === 0 && $stderr === '', "$case completes under an actual 64-MiB ceiling without runtime diagnostics");
    $result = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
    wprism_check(($result['accepted'] ?? null) === true && ($result['rows'] ?? null) === 1
        && ($result['limit'] ?? null) === '64M' && $result['peak_bytes'] <= 67108864
        && ($case !== 'native-large-selected' || ($result['body_bytes'] ?? null) === 5386435),
        "$case preserves its complete selected projection within the measured process ceiling");
}
wprism_check_summary('native SQL dump evidence');
