<?php
declare(strict_types=1);

namespace WPrismTest;

require_once __DIR__ . '/EvidenceSizeProfile.php';

/** Admit native evidence and project bounded scalar columns; never rewrite or restore a dump. */
final class SqlDumpEvidence {
    private const MAX_PROJECTED_ROWS = 10000;
    private const MAX_PROJECTED_CELLS = 32768;

    /** SHOW FULL TABLES, --batch --raw --skip-column-names, outside WordPress. */
    public static function tables(string $bytes): array {
        if ($bytes === '' || strlen($bytes) > 32768 || !str_ends_with($bytes, "\n")) {
            throw new \RuntimeException('database table inventory is empty, incomplete or oversized');
        }
        $tables = [];
        // WP-CLI db query appends one empty terminal line to the native batch
        // result (polybiok08). Admit that framing, never arbitrary trim or an
        // interior empty row; callers retain and compare the original bytes.
        $terminator = str_ends_with($bytes, "\n\n") ? 2 : 1;
        foreach (explode("\n", substr($bytes, 0, -$terminator)) as $line) {
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
    public static function assertComplete(string $bytes, array $tables, array $nonemptyTables, string $profile = EvidenceSizeProfile::CONFORMANCE_TREE): void {
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
        if (strlen($bytes) > EvidenceSizeProfile::limits($profile)['stdout_bytes']
            || preg_match('/\A(?:\/\*(?:M)?![^\n]*\*\/ ?\n)?-- (?:MySQL|MariaDB) dump [^\n]+\n/', $bytes) !== 1
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
        $inserts = self::insertTables($bytes);
        if (array_diff($nonemptyTables, $inserts) !== [] || array_diff($inserts, $tables) !== []) {
            throw new \RuntimeException('database dump lacks its nonempty fixture rows or inserts an unobserved table');
        }
    }

    /**
     * Exact opaque schema sections from the same independently admitted dump.
     * SHOW FULL COLUMNS does not cover indexes, engine or table options. The
     * owner compares these bytes and alone predicts any permitted counter
     * change; this helper neither parses DDL nor normalizes AUTO_INCREMENT.
     *
     * @return array<string,string>
     */
    public static function structures(string $bytes, array $tables, array $nonemptyTables, string $profile = EvidenceSizeProfile::CONFORMANCE_TREE): array {
        self::assertComplete($bytes, $tables, $nonemptyTables, $profile);
        preg_match_all('/^-- (Table structure|Dumping data) for table `([A-Za-z0-9_]+)`$/m', $bytes, $markers, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $sections = [];
        for ($index = 0; $index < count($markers); $index += 2) {
            $begin = $markers[$index];
            $end = $markers[$index + 1] ?? null;
            $table = $begin[2][0];
            if ($begin[1][0] !== 'Table structure' || $end === null || $end[1][0] !== 'Dumping data'
                || $end[2][0] !== $table || isset($sections[$table])) {
                throw new \RuntimeException('database structure and data sections are not uniquely paired');
            }
            $length = $end[0][1] - $begin[0][1];
            if ($length <= 0 || $length > 65536) throw new \RuntimeException('database structure section exceeds its bound');
            $section = substr($bytes, $begin[0][1], $length);
            preg_match_all('/^CREATE TABLE `([A-Za-z0-9_]+)` \($/m', $section, $creates);
            if ($creates[1] !== [$table]) throw new \RuntimeException('database structure section lacks its exact unique native schema');
            $sections[$table] = $section;
        }
        ksort($sections, SORT_STRING);
        if (array_keys($sections) !== $tables) throw new \RuntimeException('database structures do not cover the complete native roster');
        return $sections;
    }

    /**
     * SHOW FULL COLUMNS, --batch --raw --skip-column-names, outside WordPress.
     * Retain and compare the complete raw nine-field records independently;
     * only their first field selects the existing scalar projection. Native
     * metadata containing literal tabs/newlines refuses this bounded lane.
     *
     * @return list<string>
     */
    public static function columnRoster(string $bytes): array {
        if ($bytes === '' || strlen($bytes) > 65536 || !str_ends_with($bytes, "\n") || str_contains($bytes, "\r") || str_contains($bytes, "\0")) {
            throw new \RuntimeException('database column inventory is incomplete or oversized');
        }
        $terminator = str_ends_with($bytes, "\n\n") ? 2 : 1;
        $columns = [];
        foreach (explode("\n", substr($bytes, 0, -$terminator)) as $line) {
            $fields = explode("\t", $line);
            if (count($fields) !== 9 || preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $fields[0]) !== 1) {
                throw new \RuntimeException('database column inventory has an unsupported row');
            }
            $columns[] = $fields[0];
            if (count($columns) > 128) throw new \RuntimeException('database column inventory exceeds its bound');
        }
        if (count(array_unique($columns)) !== count($columns)) throw new \RuntimeException('database column inventory repeats a field');
        return $columns;
    }

    /**
     * Project text/binary, null and exact integer columns from complete-insert,
     * skip-extended-insert native rows. Callers separately bind assertComplete
     * to their independent native roster; this projection is not that proof.
     * Every field is lexed, including unselected values, so a delimiter inside
     * a string or a second VALUES tuple cannot hide a supposedly absent PK.
     * One row is scanned at a time; unselected bodies are never decoded/copied.
     *
     * @param list<string> $columns
     * @return list<array<string,int|string|null>>
     */
    public static function projectColumns(string $bytes, string $table, array $columns, string $profile = EvidenceSizeProfile::CONFORMANCE_TREE): array {
        return self::project($bytes, $table, $columns, $profile, false);
    }

    /**
     * A complete-row witness uses an independently observed full column roster.
     * Unlike a selected projection, an extra INSERT column must refuse: otherwise
     * a changed field omitted from the caller's roster would disappear from the
     * preservation comparison. Schema sections remain a separate opaque witness.
     *
     * @return list<array<string,int|string|null>>
     */
    public static function fullRows(string $bytes, string $table, array $columns, string $profile = EvidenceSizeProfile::CONFORMANCE_TREE): array {
        return self::project($bytes, $table, $columns, $profile, true);
    }

    private static function project(string $bytes, string $table, array $columns, string $profile, bool $complete): array {
        if (strlen($bytes) > EvidenceSizeProfile::limits($profile)['stdout_bytes']
            || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $table) || $columns === [] || !array_is_list($columns)
            || count($columns) > 128) {
            throw new \RuntimeException('database column projection has invalid or oversized authority');
        }
        foreach ($columns as $column) {
            if (!is_string($column) || preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $column) !== 1) {
                throw new \RuntimeException('database column projection has an unsupported column');
            }
        }
        if (count(array_unique($columns)) !== count($columns)) throw new \RuntimeException('database column projection repeats a column');
        self::insertTables($bytes);
        if (preg_match_all('/^CREATE TABLE `' . $table . '` \($/m', $bytes) !== 1) {
            throw new \RuntimeException('database column projection requires its unique native table schema');
        }
        $wanted = array_fill_keys($columns, true);
        $rows = [];
        $offset = 0;
        while (true) {
            $found = preg_match('/^[ \t]*INSERT INTO `' . $table . '`/m', $bytes, $location, PREG_OFFSET_CAPTURE, $offset);
            if ($found === 0) break;
            if ($found !== 1 || count($rows) >= self::MAX_PROJECTED_ROWS
                || (count($rows) + 1) * count($columns) > self::MAX_PROJECTED_CELLS) {
                throw new \RuntimeException('database column projection exceeds its bounded row or cell roster');
            }
            $start = $location[0][1];
            $end = strpos($bytes, "\n", $start);
            if ($end === false) throw new \RuntimeException('database projected row is incomplete');
            $line = substr($bytes, $start, $end - $start);
            $offset = $end + 1;
            if (preg_match('/\AINSERT INTO `' . $table . '` \((`[A-Za-z0-9_]{1,64}`(?:, `[A-Za-z0-9_]{1,64}`){0,127})\) VALUES \(/', $line, $header) !== 1) {
                throw new \RuntimeException('database projection requires a bounded complete column list');
            }
            $names = array_map(static fn(string $name): string => substr($name, 1, -1), explode(', ', $header[1]));
            if (count(array_unique($names)) !== count($names) || array_diff($columns, $names) !== []) {
                throw new \RuntimeException('database projection has duplicate or missing columns');
            }
            if ($complete && array_diff($names, $columns) !== []) {
                throw new \RuntimeException('database complete-row witness omits an inserted column');
            }
            $cursor = strlen($header[0]);
            $selected = [];
            foreach ($names as $index => $name) {
                $value = self::scalar($line, $cursor, isset($wanted[$name]));
                if (isset($wanted[$name])) $selected[$name] = $value;
                while (($line[$cursor] ?? null) === ' ') $cursor++;
                $separator = $index === count($names) - 1 ? ');' : ',';
                if (substr($line, $cursor, strlen($separator)) !== $separator) {
                    throw new \RuntimeException('database projected row has an invalid field or tuple boundary');
                }
                $cursor += strlen($separator);
            }
            if ($cursor !== strlen($line)) throw new \RuntimeException('database projected row has trailing data or another tuple');
            $rows[] = array_replace(array_fill_keys($columns, null), $selected);
        }
        return $rows;
    }

    /** @return int|string|null */
    private static function scalar(string $line, int &$cursor, bool $selected): int|string|null {
        while (($line[$cursor] ?? null) === ' ') $cursor++;
        if (($line[$cursor] ?? null) === "'") {
            $cursor++;
            $value = '';
            $escapes = ['0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a", "'" => "'", '"' => '"', '\\' => '\\'];
            while (isset($line[$cursor])) {
                $char = $line[$cursor++];
                if ($char === "'") {
                    if (($line[$cursor] ?? null) !== "'") return $selected ? $value : null;
                    $cursor++;
                } elseif ($char === '\\') {
                    $escape = $line[$cursor++] ?? '';
                    if (!array_key_exists($escape, $escapes)) throw new \RuntimeException('database projected string has an unsupported escape');
                    $char = $escapes[$escape];
                }
                if ($selected) $value .= $char;
            }
            throw new \RuntimeException('database projected string is incomplete');
        }
        if (substr($line, $cursor, 4) === 'NULL') {
            $cursor += 4;
            return null;
        }
        if (preg_match('/\G0x(?:[0-9A-Fa-f]{2})+/', $line, $literal, 0, $cursor) === 1) {
            $cursor += strlen($literal[0]);
            if (!$selected) return null;
            $value = hex2bin(substr($literal[0], 2));
            if ($value === false) throw new \RuntimeException('database projected binary value is invalid');
            return $value;
        }
        if (preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $line, $literal, 0, $cursor) !== 1) {
            throw new \RuntimeException('database projected row contains an unsupported literal');
        }
        $cursor += strlen($literal[0]);
        if (!$selected) return null;
        if (preg_match('/\A-?(?:0|[1-9][0-9]*)\z/', $literal[0]) !== 1
            || filter_var($literal[0], FILTER_VALIDATE_INT) === false) {
            throw new \RuntimeException('database projected numeric value is not an exact bounded integer');
        }
        return (int) $literal[0];
    }

    /** One native, unindented INSERT prefix per row; no hidden alternate form. */
    private static function insertTables(string $bytes): array {
        $tables = [];
        $offset = 0;
        while (true) {
            $found = preg_match('/^[ \t]*(?:INSERT|REPLACE)\b/im', $bytes, $location, PREG_OFFSET_CAPTURE, $offset);
            if ($found === 0) return array_keys($tables);
            if ($found !== 1 || preg_match('/\GINSERT INTO `([A-Za-z0-9_]{1,64})` \(/', $bytes, $header, 0, $location[0][1]) !== 1) {
                throw new \RuntimeException('database dump has an unsupported row statement prefix');
            }
            $tables[$header[1]] = true;
            if (count($tables) > 128) throw new \RuntimeException('database dump inserts an oversized table roster');
            $end = strpos($bytes, "\n", $location[0][1]);
            if ($end === false) throw new \RuntimeException('database dump has an incomplete row statement');
            $offset = $end + 1;
        }
    }
}
