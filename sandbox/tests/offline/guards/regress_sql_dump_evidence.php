<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/check.php';
require_once dirname(__DIR__, 2) . '/lib/SqlDumpEvidence.php';

use WPrismTest\SqlDumpEvidence;

$tables = SqlDumpEvidence::tables("wp_users\tBASE TABLE\nwp_options\tBASE TABLE\n");
wprism_check_same(['wp_options', 'wp_users'], $tables, 'full table inventory is admitted without depending on database collation order');
$dump = "-- MariaDB dump 10.19 Distrib 11.4\n";
foreach ($tables as $table) {
    $dump .= "-- Table structure for table `$table`\nCREATE TABLE `$table` (\n  `id` int NOT NULL\n);\n"
        . "-- Dumping data for table `$table`\nINSERT INTO `$table` (`id`) VALUES (1);\n";
}
$dump .= "-- Dump completed\n";
SqlDumpEvidence::assertComplete($dump, $tables, $tables);
wprism_check(true, 'native complete dump with separately observed roster and nonempty rows is admitted');
foreach (["/*M!999999\\- enable the sandbox mode */\n" . $dump, str_replace('MariaDB dump', 'MySQL dump', $dump)] as $variant) {
    SqlDumpEvidence::assertComplete($variant, $tables, $tables);
    wprism_check(true, 'native MySQL and MariaDB header forms preserve the same complete-data contract');
}
foreach (['', "wp_users\tBASE TABLE", "wp_users\tVIEW\n", "wp_users\tBASE TABLE\nwp_users\tBASE TABLE\n",
    "wp_users\tBASE TABLE\n\n", "unsafe`table\tBASE TABLE\n", "PHP Warning\n", str_repeat('x', 32769),
    implode('', array_map(static fn(int $n): string => "table_$n\tBASE TABLE\n", range(1, 129)))] as $bad) {
    wprism_check_throws(static fn() => SqlDumpEvidence::tables($bad), RuntimeException::class, 'incomplete, unsafe, duplicate, unsupported or oversized table inventory refuses');
}
foreach (['empty', 'missing-header', 'missing-footer', 'trailing-noise', 'dated-footer', 'table-subset', 'schema-subset',
    'data-subset', 'empty-data', 'duplicate-schema', 'foreign-table', 'oversized'] as $fault) {
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
    };
    wprism_check_throws(static fn() => SqlDumpEvidence::assertComplete($bad, $tables, $tables), RuntimeException::class, "dump admission refuses $fault");
}
foreach ([[], ['wp_users', 'wp_options'], ['wp_users', 'wp_users'], ['wp_options', []], ['x' => 'wp_options'], ['../unsafe']] as $badTables) {
    wprism_check_throws(static fn() => SqlDumpEvidence::assertComplete($dump, $badTables, $tables), RuntimeException::class, 'malformed caller roster cannot make partial output complete');
}
foreach ([[], ['foreign'], ['wp_users', 'wp_users'], [[]], ['x' => 'wp_users']] as $badPremise) {
    wprism_check_throws(static fn() => SqlDumpEvidence::assertComplete($dump, $tables, $badPremise), RuntimeException::class, 'nonempty caller premises are required, bounded and within the native roster');
}
wprism_check_summary('native SQL dump evidence');
