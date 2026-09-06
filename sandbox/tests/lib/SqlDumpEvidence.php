<?php
declare(strict_types=1);

namespace WPrismTest;

require_once __DIR__ . '/EvidenceSizeProfile.php';

/** Validate an unfiltered native dump, never parse/rewrite its row values or restore it. */
final class SqlDumpEvidence {
    /** SHOW FULL TABLES, --batch --raw --skip-column-names, outside WordPress. */
    public static function tables(string $bytes): array {
        if ($bytes === '' || strlen($bytes) > 32768 || !str_ends_with($bytes, "\n")) {
            throw new \RuntimeException('database table inventory is empty, incomplete or oversized');
        }
        $tables = [];
        foreach (explode("\n", substr($bytes, 0, -1)) as $line) {
            if (preg_match('/\A([A-Za-z0-9_]{1,64})\tBASE TABLE\z/', $line, $match) !== 1) {
                throw new \RuntimeException('database table inventory has an unsupported entry');
            }
            $tables[] = $match[1];
        }
        if (count($tables) > 128 || count(array_unique($tables)) !== count($tables)) {
            throw new \RuntimeException('database table inventory is duplicate or oversized');
        }
        sort($tables, SORT_STRING);
        return $tables;
    }

    /**
     * The caller binds successful native export argv with no table/data filter,
     * --skip-dump-date and --complete-insert. A separate full table inventory
     * prevents an empty/subset dump being accepted merely because it is stable.
     * This closed disposable-fixture protocol admits base tables only.
     */
    public static function assertComplete(string $bytes, array $tables, array $nonemptyTables): void {
        if ($tables === [] || !array_is_list($tables) || count($tables) > 128
            || $nonemptyTables === [] || !array_is_list($nonemptyTables) || count($nonemptyTables) > 128) {
            throw new \RuntimeException('database dump requires a complete roster and nonempty fixture premises');
        }
        foreach (array_merge($tables, $nonemptyTables) as $table) {
            if (!is_string($table) || preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $table) !== 1) {
                throw new \RuntimeException('database dump table identity is unsupported');
            }
        }
        $sorted = $tables;
        sort($sorted, SORT_STRING);
        if ($tables !== $sorted || count(array_unique($tables)) !== count($tables)
            || count(array_unique($nonemptyTables)) !== count($nonemptyTables)
            || array_diff($nonemptyTables, $tables) !== []) {
            throw new \RuntimeException('database dump requires a complete roster and nonempty fixture premises');
        }
        if (strlen($bytes) > EvidenceSizeProfile::limits(EvidenceSizeProfile::CONFORMANCE_TREE)['stdout_bytes']
            || preg_match('/\A(?:\/\*(?:M)?![^\n]*\*\/\n)?-- (?:MySQL|MariaDB) dump [^\n]+\n/', $bytes) !== 1
            || !str_ends_with($bytes, "-- Dump completed\n")) {
            throw new \RuntimeException('database dump is oversized or missing its native header/completion marker');
        }
        foreach (['/^-- Table structure for table `([A-Za-z0-9_]+)`$/m',
            '/^CREATE TABLE `([A-Za-z0-9_]+)` \($/m',
            '/^-- Dumping data for table `([A-Za-z0-9_]+)`$/m'] as $pattern) {
            preg_match_all($pattern, $bytes, $matches);
            $observed = $matches[1];
            sort($observed, SORT_STRING);
            if ($observed !== $tables) throw new \RuntimeException('database dump does not cover the exact native table roster');
        }
        preg_match_all('/^INSERT INTO `([A-Za-z0-9_]+)` \(/m', $bytes, $inserts);
        if (array_diff($nonemptyTables, $inserts[1]) !== [] || array_diff($inserts[1], $tables) !== []) {
            throw new \RuntimeException('database dump lacks its nonempty fixture rows or inserts an unobserved table');
        }
    }
}
