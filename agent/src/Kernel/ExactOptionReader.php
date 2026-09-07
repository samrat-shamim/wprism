<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/PlainData.php';

/** Exact, bounded, object-safe reads from the durable wp_options row. */
final class ExactOptionReader {
    private const MAX_OPTION_NAME_BYTES = 764;
    private const MAX_OPTION_NAME_CHARACTERS = 191;
    private const MAX_OPTION_VALUE_BYTES = 16777216;

    /**
     * Read one physical option row without consulting WordPress's object cache.
     *
     * The compact witness is deliberately read before the payload. It bounds
     * target-controlled allocation and gives the second query an exact length
     * and digest to meet. Callers that need a coherent view put this primitive
     * inside the engine-owned read-only snapshot or authored transaction.
     */
    public static function read_plain(
        string $name,
        mixed $default,
        string $context,
        $database = null
    ): mixed {
        self::assert_name($name);
        PlainData::assert($default, $context . ' default');
        $row = self::read_row($name, $context, $database);
        return $row === null ? $default : $row['value'];
    }

    /**
     * Retain physical absence and raw bytes for native-input witnesses. A
     * present serialized null is not a missing row, and decoded equality
     * cannot admit a stale cache's different serialized representation.
     * Callers may narrow, never expand, the existing allocation frontier.
     *
     * @return array{raw:string,value:mixed}|null
     */
    public static function read_row(
        string $name,
        string $context,
        $database = null,
        int $maxValueBytes = self::MAX_OPTION_VALUE_BYTES
    ): ?array {
        self::assert_name($name);
        if ($maxValueBytes < 1 || $maxValueBytes > self::MAX_OPTION_VALUE_BYTES) {
            throw new \InvalidArgumentException('wprism: durable option byte limit must narrow the bounded frontier');
        }

        $database ??= $GLOBALS['wpdb'] ?? null;
        $table = is_object($database) ? ($database->options ?? null) : null;
        if (!is_object($database)
            || !is_string($table)
            || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1
            || !method_exists($database, 'prepare')
            || !method_exists($database, 'get_results')) {
            throw new \RuntimeException("wprism: $context requires canonical wp_options access");
        }

        $preflight = self::checked_results(
            $database,
            $database->prepare(
                'SELECT option_name, OCTET_LENGTH(option_value) AS option_value_bytes, '
                    . 'SHA2(option_value, 256) AS option_value_sha256 '
                    . "FROM `$table` WHERE option_name = %s ORDER BY option_id ASC LIMIT 2",
                $name
            ),
            $context . ' durable option size/hash preflight'
        );
        if (count($preflight) > 1) {
            throw new \RuntimeException(
                "wprism: $context durable option read found duplicate/collation-alias rows"
            );
        }
        if ($preflight === []) {
            return null;
        }

        $witness = $preflight[0];
        $bytes = is_array($witness)
            ? self::canonical_size($witness['option_value_bytes'] ?? null)
            : null;
        $sha256 = is_array($witness)
            ? self::canonical_sha256($witness['option_value_sha256'] ?? null)
            : null;
        if (!is_array($witness)
            || array_keys($witness) !== ['option_name', 'option_value_bytes', 'option_value_sha256']
            || !is_string($witness['option_name'] ?? null)
            || !hash_equals($name, $witness['option_name'])
            || $bytes === null
            || $bytes > $maxValueBytes
            || $sha256 === null) {
            throw new \RuntimeException(
                "wprism: $context durable option size/identity preflight is malformed or over the bounded frontier"
            );
        }

        $rows = self::checked_results(
            $database,
            $database->prepare(
                "SELECT option_name, option_value FROM `$table` "
                    . 'WHERE option_name = %s ORDER BY option_id ASC LIMIT 2',
                $name
            ),
            $context . ' durable option payload read'
        );
        if (count($rows) !== 1) {
            throw new \RuntimeException(
                "wprism: $context durable option row changed after its bounded preflight"
            );
        }
        $row = $rows[0];
        if (!is_array($row)
            || array_keys($row) !== ['option_name', 'option_value']
            || !is_string($row['option_name'] ?? null)
            || !is_string($row['option_value'] ?? null)
            || !hash_equals($name, $row['option_name'])
            || strlen($row['option_value']) !== $bytes
            || !hash_equals($sha256, hash('sha256', $row['option_value']))) {
            throw new \RuntimeException(
                "wprism: $context durable option row disagrees with its bounded witness"
            );
        }

        return [
            'raw' => $row['option_value'],
            'value' => PlainData::decode($row['option_value'], $context . ' durable option'),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function checked_results(object $database, string $sql, string $context): array {
        $database->last_error = '';
        $rows = $database->get_results($sql, ARRAY_A);
        if (!is_array($rows)
            || !array_is_list($rows)
            || trim((string) ($database->last_error ?? '')) !== '') {
            throw new \RuntimeException("wprism: $context failed");
        }
        return $rows;
    }

    private static function assert_name(string $name): void {
        $characters = preg_match('//u', $name) === 1 ? preg_match_all('/./us', $name) : false;
        if ($name === ''
            || strlen($name) > self::MAX_OPTION_NAME_BYTES
            || !is_int($characters)
            || $characters > self::MAX_OPTION_NAME_CHARACTERS
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new \InvalidArgumentException(
                'wprism: durable option identity is invalid or over the schema frontier'
            );
        }
    }

    private static function canonical_size(mixed $value): ?int {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return is_int($parsed) ? $parsed : null;
    }

    private static function canonical_sha256(mixed $value): ?string {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1
            ? $value
            : null;
    }
}
