<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/DatabaseTableIdentifier.php';
require_once __DIR__ . '/MetaRows.php';

/**
 * Bounded physical term inputs for native read-only interpretation.
 *
 * This reads the caller's snapshot, never WordPress's filtered/cached term
 * API. Taxonomy selection and row/byte budgets are explicit. Only a bounded
 * size roster reaches hashing or value transport; each later batch is gated
 * by those exact per-field sizes so growth cannot cross the driver boundary.
 * Native language/flag/locale semantics deliberately do not live here.
 */
final class TermRows {
    public const MAX_ROWS = 4096;
    public const MAX_BYTES = 67108864;
    private const BATCH_ROWS = 64;
    private const COLUMNS = [
        'term_id' => ['t.term_id', 20],
        'name' => ['t.name', 800],
        'slug' => ['t.slug', 800],
        'term_group' => ['t.term_group', 20],
        'term_taxonomy_id' => ['tt.term_taxonomy_id', 20],
        'taxonomy' => ['tt.taxonomy', 128],
        'description' => ['tt.description', 16777216],
        'parent' => ['tt.parent', 20],
        'count' => ['tt.count', 20],
    ];

    /** @return list<array<string,string>> */
    public static function taxonomy(string $taxonomy, int $maxRows, int $maxBytes, string $purpose): array {
        global $wpdb;
        if ($taxonomy === '' || strlen($taxonomy) > 32 || preg_match('/[\x00-\x1F\x7F]/', $taxonomy) === 1
            || $maxRows < 1 || $maxRows > self::MAX_ROWS || $maxBytes < 1 || $maxBytes > self::MAX_BYTES) {
            throw new \InvalidArgumentException('wprism: bounded term observation requires an exact taxonomy and finite budgets');
        }
        DatabaseTableIdentifier::assert_many([$wpdb->terms, $wpdb->term_taxonomy], $purpose);
        // LEFT JOIN makes an orphan taxonomy row a malformed witness instead
        // of silently dropping it from the observed native language roster.
        $from = "FROM `$wpdb->term_taxonomy` tt LEFT JOIN `$wpdb->terms` t ON t.term_id = tt.term_id "
            . 'WHERE BINARY tt.taxonomy = BINARY %s';
        $sizes = ['LEFT(BINARY tt.term_taxonomy_id, 21) AS identity'];
        foreach (self::COLUMNS as $name => [$column]) $sizes[] = "OCTET_LENGTH($column) AS {$name}_bytes";
        $sizeSql = 'SELECT ' . implode(', ', $sizes) . " $from ORDER BY tt.term_taxonomy_id ASC LIMIT " . ($maxRows + 1);
        $roster = self::read($sizeSql, [$taxonomy], $purpose);
        if (count($roster) > $maxRows) self::fail($purpose, 'row budget exceeded');
        $expectedKeys = ['identity', ...array_map(static fn(string $name): string => $name . '_bytes', array_keys(self::COLUMNS))];
        $total = $previous = 0;
        foreach ($roster as $row) {
            $id = is_array($row) ? MetaRows::positive_id($row['identity'] ?? null) : null;
            if (!is_array($row) || array_keys($row) !== $expectedKeys || $id === null || $id <= $previous) {
                self::fail($purpose, 'size roster has malformed or unordered identities');
            }
            $previous = $id;
            foreach (self::COLUMNS as $name => [, $limit]) {
                $bytes = $row[$name . '_bytes'];
                if (!is_string($bytes) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $bytes) !== 1
                    || strlen($bytes) > 8 || (int) $bytes > $limit || (int) $bytes > $maxBytes - $total) {
                    self::fail($purpose, 'field or aggregate byte budget exceeded');
                }
                $total += (int) $bytes;
            }
        }
        $result = [];
        $termIds = [];
        foreach (array_chunk($roster, self::BATCH_ROWS) as $batch) {
            $predicates = [];
            $args = [$taxonomy];
            foreach ($batch as $row) {
                $parts = ['tt.term_taxonomy_id = %d'];
                $args[] = (int) $row['identity'];
                foreach (self::COLUMNS as $name => [$column]) {
                    $parts[] = "OCTET_LENGTH($column) = %d";
                    $args[] = (int) $row[$name . '_bytes'];
                }
                $predicates[] = '(' . implode(' AND ', $parts) . ')';
            }
            $tail = " $from AND (" . implode(' OR ', $predicates) . ') ORDER BY tt.term_taxonomy_id ASC LIMIT ' . (count($batch) + 1);
            $hashColumns = ['LEFT(BINARY tt.term_taxonomy_id, 21) AS identity'];
            $valueColumns = [];
            foreach (self::COLUMNS as $name => [$column]) {
                $hashColumns[] = "SHA2($column, 256) AS {$name}_sha256";
                $valueColumns[] = "$column AS $name";
            }
            $hashes = self::read('SELECT ' . implode(', ', $hashColumns) . $tail, $args, $purpose);
            if (count($hashes) !== count($batch)) self::fail($purpose, 'hash roster changed after size admission');
            $hashKeys = ['identity', ...array_map(static fn(string $name): string => $name . '_sha256', array_keys(self::COLUMNS))];
            foreach ($hashes as $index => $row) {
                if (!is_array($row) || array_keys($row) !== $hashKeys || $row['identity'] !== $batch[$index]['identity']) {
                    self::fail($purpose, 'hash roster has malformed or changed identities');
                }
                foreach (self::COLUMNS as $name => $_) {
                    if (!is_string($row[$name . '_sha256']) || preg_match('/^[a-f0-9]{64}$/D', $row[$name . '_sha256']) !== 1) {
                        self::fail($purpose, 'hash roster has an invalid field witness');
                    }
                }
            }
            $rows = self::read('SELECT ' . implode(', ', $valueColumns) . $tail, $args, $purpose);
            if (count($rows) !== count($batch)) self::fail($purpose, 'value roster changed after size admission');
            foreach ($rows as $index => $row) {
                if (!is_array($row) || array_keys($row) !== array_keys(self::COLUMNS)
                    || $row['term_taxonomy_id'] !== $batch[$index]['identity'] || $row['taxonomy'] !== $taxonomy
                    || MetaRows::positive_id($row['term_id'] ?? null) === null || isset($termIds[$row['term_id']])) {
                    self::fail($purpose, 'value roster has malformed or duplicate coordinates');
                }
                foreach (self::COLUMNS as $name => $_) {
                    if (!is_string($row[$name]) || strlen($row[$name]) !== (int) $batch[$index][$name . '_bytes']
                        || !hash_equals($hashes[$index][$name . '_sha256'], hash('sha256', $row[$name]))) {
                        self::fail($purpose, 'value bytes disagree with their admitted witness');
                    }
                }
                $termIds[$row['term_id']] = true;
                $result[] = $row;
            }
        }
        if (self::read($sizeSql, [$taxonomy], $purpose) !== $roster) {
            self::fail($purpose, 'complete taxonomy roster changed during observation');
        }
        return $result;
    }

    private static function read(string $sql, array $args, string $purpose): array {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        $wpdb->last_error = '';
        try {
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
            if (!is_array($rows) || !array_is_list($rows) || (string) ($wpdb->last_error ?? '') !== '') {
                self::fail($purpose, 'checked database read failed');
            }
            return $rows;
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }

    private static function fail(string $purpose, string $reason): never {
        throw new \RuntimeException("wprism: $purpose bounded term observation refused: $reason");
    }
}
