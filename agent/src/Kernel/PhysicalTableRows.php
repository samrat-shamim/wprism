<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/DatabaseQueryIsolation.php';
require_once __DIR__ . '/DatabaseTableIdentifier.php';
require_once __DIR__ . '/MetaRows.php';

/**
 * Complete physical rows from an already-authorized database snapshot.
 *
 * Native reconstruction needs source bytes and an unchanged-remainder witness,
 * not another package-owned SQL pager. This first contract deliberately observes
 * one whole table in one positive-integer identity order: no predicates, joins,
 * schema inference, native callbacks or caller-authored SQL. A bounded size
 * roster precedes hashing and payload allocation; every batch must meet its
 * exact identities, null shapes, lengths and hashes. The enclosing engine scope
 * owns consistency, transaction/session authority and the unchanged query quota.
 */
final class PhysicalTableRows {
    public const MAX_ROWS = 16384;
    public const MAX_COLUMNS = 32;
    public const MAX_CELLS = 262144;
    public const MAX_RAW_BYTES = 33554432;
    public const MAX_CELL_BYTES = 1048576;
    private const BATCH_ROWS = 64;
    private const BATCH_BYTES = 4194304;

    /**
     * @param array{table:string,columns:list<string>,identity:string,max_rows:int,max_raw_bytes:int,mode:string} $descriptor
     * @return array{row_count:int,raw_bytes:int,rows_sha256:string,rows?:list<array<string,?string>>}
     */
    public static function observe(array $descriptor, string $context): array {
        self::assert_descriptor($descriptor);
        $table = $descriptor['table'];
        $columns = $descriptor['columns'];
        $identity = $descriptor['identity'];
        DatabaseQueryIsolation::assert_profile_contains([$table], false, $context);

        $sizeColumns = ["LEFT(BINARY `$identity`, 21) AS _wprism_identity"];
        foreach ($columns as $position => $column) {
            $sizeColumns[] = "OCTET_LENGTH(`$column`) AS _wprism_size_$position";
        }
        $sizeSql = 'SELECT ' . implode(', ', $sizeColumns) . " FROM `$table`"
            . " ORDER BY `$identity` ASC LIMIT " . ($descriptor['max_rows'] + 1);
        $roster = self::read($sizeSql, [], $context);
        if (count($roster) > $descriptor['max_rows']
            || count($roster) > intdiv(self::MAX_CELLS, count($columns))) {
            self::fail($context, 'row or cell-count budget exceeded');
        }
        $sizeKeys = ['_wprism_identity'];
        foreach (array_keys($columns) as $position) $sizeKeys[] = '_wprism_size_' . $position;
        $rawBytes = $previousId = 0;
        foreach ($roster as $row) {
            $id = is_array($row) ? MetaRows::positive_id($row['_wprism_identity'] ?? null) : null;
            if (!is_array($row) || array_keys($row) !== $sizeKeys || $id === null || $id <= $previousId) {
                self::fail($context, 'size roster has malformed, duplicate or unordered identities');
            }
            $previousId = $id;
            $rowBytes = 0;
            foreach ($columns as $position => $column) {
                $bytes = $row['_wprism_size_' . $position];
                if ($bytes === null && $column !== $identity) continue;
                $size = self::size($bytes);
                if ($size === null || $size > self::MAX_CELL_BYTES
                    || $size > $descriptor['max_raw_bytes'] - $rawBytes
                    || ($column === $identity && $size !== strlen($row['_wprism_identity']))) {
                    self::fail($context, 'size roster has an invalid field or exceeds the byte budget');
                }
                $rawBytes += $size;
                $rowBytes += $size;
            }
            if ($rowBytes > self::BATCH_BYTES) self::fail($context, 'one row exceeds the bounded transfer size');
        }

        $digest = hash_init('sha256');
        hash_update($digest, "wprism-physical-table-rows/v1\0");
        self::hash_string($digest, $table);
        self::hash_string($digest, $identity);
        hash_update($digest, pack('N', count($columns)));
        foreach ($columns as $column) self::hash_string($digest, $column);
        $resultRows = [];
        $batch = [];
        $batchBytes = 0;
        foreach ($roster as $row) {
            $rowBytes = 0;
            foreach (array_keys($columns) as $position) $rowBytes += (int) $row['_wprism_size_' . $position];
            if ($batch !== [] && (count($batch) >= self::BATCH_ROWS || $rowBytes > self::BATCH_BYTES - $batchBytes)) {
                self::consume_batch($descriptor, $batch, $digest, $resultRows, $context);
                $batch = [];
                $batchBytes = 0;
            }
            $batch[] = $row;
            $batchBytes += $rowBytes;
        }
        if ($batch !== []) self::consume_batch($descriptor, $batch, $digest, $resultRows, $context);
        if (self::read($sizeSql, [], $context) !== $roster) {
            self::fail($context, 'complete size roster changed during observation');
        }
        hash_update($digest, 'E' . pack('N', count($roster)));
        $result = ['row_count' => count($roster), 'raw_bytes' => $rawBytes, 'rows_sha256' => hash_final($digest)];
        if ($descriptor['mode'] === 'rows') $result['rows'] = $resultRows;
        return $result;
    }

    private static function assert_descriptor(array $descriptor): void {
        $keys = array_keys($descriptor);
        sort($keys, SORT_STRING);
        if ($keys !== ['columns', 'identity', 'max_raw_bytes', 'max_rows', 'mode', 'table']
            || !is_string($descriptor['table'])
            || !is_array($descriptor['columns']) || !array_is_list($descriptor['columns'])
            || $descriptor['columns'] === [] || count($descriptor['columns']) > self::MAX_COLUMNS
            || !is_string($descriptor['identity'])
            || !is_int($descriptor['max_rows']) || $descriptor['max_rows'] < 1 || $descriptor['max_rows'] > self::MAX_ROWS
            || !is_int($descriptor['max_raw_bytes']) || $descriptor['max_raw_bytes'] < 1
            || $descriptor['max_raw_bytes'] > self::MAX_RAW_BYTES
            || !in_array($descriptor['mode'], ['rows', 'digest'], true)) {
            throw new \InvalidArgumentException('wprism: physical table observation requires a closed descriptor and finite budgets');
        }
        DatabaseTableIdentifier::assert_many([$descriptor['table']], 'physical table observation');
        $seen = [];
        foreach ($descriptor['columns'] as $column) {
            if (!is_string($column) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $column) !== 1
                || isset($seen[strtolower($column)])) {
                throw new \InvalidArgumentException('wprism: physical table observation requires distinct exact column identifiers');
            }
            $seen[strtolower($column)] = true;
        }
        if (!in_array($descriptor['identity'], $descriptor['columns'], true)) {
            throw new \InvalidArgumentException('wprism: physical table observation identity must be an explicit selected column');
        }
    }

    /** @param list<array<string,mixed>> $batch @param list<array<string,?string>> $resultRows */
    private static function consume_batch(array $descriptor, array $batch, \HashContext $digest, array &$resultRows, string $context): void {
        $table = $descriptor['table'];
        $columns = $descriptor['columns'];
        $identity = $descriptor['identity'];
        $predicates = $args = [];
        foreach ($batch as $row) {
            $parts = ["`$identity` = %d"];
            $args[] = (int) $row['_wprism_identity'];
            foreach ($columns as $position => $column) {
                $bytes = $row['_wprism_size_' . $position];
                if ($bytes === null) {
                    $parts[] = "`$column` IS NULL";
                } else {
                    $parts[] = "OCTET_LENGTH(`$column`) = %d";
                    $args[] = (int) $bytes;
                }
            }
            $predicates[] = '(' . implode(' AND ', $parts) . ')';
        }
        $tail = " FROM `$table` WHERE (" . implode(' OR ', $predicates) . ')'
            . " ORDER BY `$identity` ASC LIMIT " . (count($batch) + 1);
        $hashColumns = ["LEFT(BINARY `$identity`, 21) AS _wprism_identity"];
        $hashKeys = ['_wprism_identity'];
        foreach ($columns as $position => $column) {
            $hashColumns[] = "SHA2(`$column`, 256) AS _wprism_hash_$position";
            $hashKeys[] = '_wprism_hash_' . $position;
        }
        $hashSql = 'SELECT ' . implode(', ', $hashColumns) . $tail;
        $hashes = self::read($hashSql, $args, $context);
        if (count($hashes) !== count($batch)) self::fail($context, 'hash roster changed after size admission');
        foreach ($hashes as $offset => $row) {
            if (!is_array($row) || array_keys($row) !== $hashKeys || $row['_wprism_identity'] !== $batch[$offset]['_wprism_identity']) {
                self::fail($context, 'hash roster has malformed or changed identities');
            }
            foreach (array_keys($columns) as $position) {
                $hash = $row['_wprism_hash_' . $position];
                if ($batch[$offset]['_wprism_size_' . $position] === null ? $hash !== null
                    : (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1)) {
                    self::fail($context, 'hash roster has an invalid field witness');
                }
            }
        }
        $select = implode(', ', array_map(static fn(string $column): string => "`$column`", $columns));
        $rows = self::read('SELECT ' . $select . $tail, $args, $context);
        if (count($rows) !== count($batch)) self::fail($context, 'value roster changed after size admission');
        foreach ($rows as $offset => $row) {
            if (!is_array($row) || array_keys($row) !== $columns || $row[$identity] !== $batch[$offset]['_wprism_identity']) {
                self::fail($context, 'value roster has malformed or changed identities');
            }
            hash_update($digest, 'R');
            foreach ($columns as $position => $column) {
                $value = $row[$column];
                $bytes = $batch[$offset]['_wprism_size_' . $position];
                if ($bytes === null) {
                    if ($value !== null) self::fail($context, 'value null shape disagrees with its witness');
                    hash_update($digest, "\0");
                } else {
                    if (!is_string($value) || strlen($value) !== (int) $bytes
                        || !hash_equals($hashes[$offset]['_wprism_hash_' . $position], hash('sha256', $value))) {
                        self::fail($context, 'value bytes disagree with their admitted witness');
                    }
                    hash_update($digest, "\1");
                    self::hash_string($digest, $value);
                }
            }
            if ($descriptor['mode'] === 'rows') $resultRows[] = $row;
        }
        if (self::read($hashSql, $args, $context) !== $hashes) {
            self::fail($context, 'field hashes changed during payload observation');
        }
    }

    private static function size(mixed $value): ?int {
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]{0,8})$/D', $value) !== 1) return null;
        return (int) $value;
    }

    private static function hash_string(\HashContext $digest, string $value): void {
        hash_update($digest, pack('N', strlen($value)) . $value);
    }

    private static function read(string $sql, array $args, string $context): array {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        $wpdb->last_error = '';
        try {
            try {
                $rows = $wpdb->get_results($args === [] ? $sql : $wpdb->prepare($sql, ...$args), ARRAY_A);
            } catch (\Throwable $failure) {
                // A profiled mysqli driver can throw instead of setting
                // last_error; its text is private evidence, not a receipt.
                throw new \RuntimeException(
                    "wprism: $context physical table observation refused: checked database read failed", 0, $failure
                );
            }
            if (!is_array($rows) || !array_is_list($rows) || (string) ($wpdb->last_error ?? '') !== '') {
                self::fail($context, 'checked database read failed');
            }
            return $rows;
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }

    private static function fail(string $context, string $reason): never {
        throw new \RuntimeException("wprism: $context physical table observation refused: $reason");
    }
}
