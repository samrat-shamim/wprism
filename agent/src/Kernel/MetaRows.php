<?php
declare(strict_types=1);

namespace Duo;

/**
 * Checked, bounded reads of one post/term/user metadata owner range.
 *
 * Capture and apply must agree on the exact driver-shaped frontier: a failed
 * read is never an empty owner, IDs are canonical positive decimal strings,
 * and a compact OCTET_LENGTH preflight rejects row/value/aggregate overflow
 * before LONGTEXT bytes cross the driver boundary. Apply supplies a proven
 * lock index and requests FOR UPDATE; ordinary capture remains read-only.
 */
final class MetaRows {
    public const MAX_OWNER_ROWS = 100000;
    public const MAX_META_KEY_CHARACTERS = 255;
    public const MAX_META_KEY_BYTES = 1020;
    public const MAX_META_VALUE_BYTES = 16777216;
    public const MAX_OWNER_BYTES = 67108864;

    /**
     * @return list<array{meta_id:string,meta_key:string,meta_value:?string}>
     */
    public static function ordered(
        string $table,
        string $ownerColumn,
        int $ownerId,
        string $idColumn,
        string $purpose,
        ?string $lockIndex = null
    ): array {
        global $wpdb;
        foreach ([$table => 64, $ownerColumn => 64, $idColumn => 64] as $identifier => $max) {
            if (preg_match('/^[A-Za-z0-9_]{1,' . $max . '}$/D', $identifier) !== 1) {
                throw new \RuntimeException("duo: $purpose received an unsafe metadata identifier");
            }
        }
        if ($ownerId <= 0) {
            throw new \RuntimeException("duo: $purpose received a nonpositive owner identity");
        }
        if ($lockIndex !== null && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $lockIndex) !== 1) {
            throw new \RuntimeException("duo: $purpose received an unsafe metadata lock index");
        }

        $indexSql = $lockIndex === null ? '' : " FORCE INDEX (`$lockIndex`)";
        $lockSql = $lockIndex === null ? '' : ' FOR UPDATE';
        $from = "FROM `$table`$indexSql WHERE `$ownerColumn` = %d "
            . "ORDER BY `$idColumn` ASC LIMIT " . (self::MAX_OWNER_ROWS + 1) . $lockSql;
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $preflight = $wpdb->get_results($wpdb->prepare(
            "SELECT `$idColumn` AS meta_id, OCTET_LENGTH(meta_key) AS meta_key_bytes, "
            . "OCTET_LENGTH(meta_value) AS meta_value_bytes $from",
            $ownerId
        ), ARRAY_A);
        if (!is_array($preflight)
            || !array_is_list($preflight)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("duo: $purpose checked metadata size preflight failed");
        }
        if (count($preflight) > self::MAX_OWNER_ROWS) {
            throw new \RuntimeException("duo: $purpose exceeds the bounded owner-row limit");
        }

        $previousId = 0;
        $aggregateBytes = 0;
        $expected = [];
        foreach ($preflight as $position => $row) {
            $id = is_array($row) ? self::positive_id($row['meta_id'] ?? null) : null;
            $keyBytes = is_array($row) ? self::nonnegative_size($row['meta_key_bytes'] ?? null) : null;
            $valueBytes = is_array($row) && ($row['meta_value_bytes'] ?? null) === null
                ? null
                : (is_array($row) ? self::nonnegative_size($row['meta_value_bytes'] ?? null) : null);
            if (!is_array($row)
                || array_keys($row) !== ['meta_id', 'meta_key_bytes', 'meta_value_bytes']
                || $id === null
                || $keyBytes === null
                || ($row['meta_value_bytes'] !== null && $valueBytes === null)) {
                throw new \RuntimeException(
                    "duo: $purpose metadata size preflight returned a malformed row at bounded position $position"
                );
            }
            if ($keyBytes === 0 || $keyBytes > self::MAX_META_KEY_BYTES) {
                throw new \RuntimeException("duo: $purpose metadata size preflight found an oversized key");
            }
            if ($valueBytes !== null && $valueBytes > self::MAX_META_VALUE_BYTES) {
                throw new \RuntimeException("duo: $purpose metadata size preflight found an oversized value");
            }
            $rowBytes = strlen($row['meta_id']) + $keyBytes + ($valueBytes ?? 0);
            if ($rowBytes > self::MAX_OWNER_BYTES - $aggregateBytes) {
                throw new \RuntimeException("duo: $purpose exceeds the bounded owner-byte limit");
            }
            if ($id <= $previousId) {
                throw new \RuntimeException(
                    "duo: $purpose metadata size preflight returned duplicate or unordered identities"
                );
            }
            $aggregateBytes += $rowBytes;
            $previousId = $id;
            $expected[] = ['meta_id' => $row['meta_id'], 'key_bytes' => $keyBytes, 'value_bytes' => $valueBytes];
        }

        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT `$idColumn` AS meta_id, meta_key, meta_value $from",
            $ownerId
        ), ARRAY_A);
        if (!is_array($rows)
            || !array_is_list($rows)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("duo: $purpose checked metadata value read failed");
        }
        if (count($rows) !== count($expected)) {
            throw new \RuntimeException("duo: $purpose metadata rows changed after the bounded size preflight");
        }
        foreach ($rows as $position => $row) {
            $metaKey = is_array($row) ? ($row['meta_key'] ?? null) : null;
            $keyCharacters = is_string($metaKey)
                && strlen($metaKey) <= self::MAX_META_KEY_BYTES
                ? preg_match_all('/./us', $metaKey)
                : false;
            if (!is_array($row)
                || array_keys($row) !== ['meta_id', 'meta_key', 'meta_value']
                || self::positive_id($row['meta_id'] ?? null) === null
                || !is_string($row['meta_key'] ?? null)
                || $row['meta_key'] === ''
                || strlen($row['meta_key']) > self::MAX_META_KEY_BYTES
                || !is_int($keyCharacters)
                || $keyCharacters > self::MAX_META_KEY_CHARACTERS
                || preg_match('/[\x00-\x1F\x7F]/', $row['meta_key']) === 1
                || !(is_string($row['meta_value'] ?? null) || ($row['meta_value'] ?? null) === null)) {
                throw new \RuntimeException(
                    "duo: $purpose returned a malformed metadata row at bounded position $position"
                );
            }
            $valueBytes = is_string($row['meta_value']) ? strlen($row['meta_value']) : 0;
            $id = self::positive_id($row['meta_id']);
            $witness = $expected[$position];
            if ($id === null
                || !hash_equals($witness['meta_id'], $row['meta_id'])
                || $witness['key_bytes'] !== strlen($row['meta_key'])
                || $witness['value_bytes'] !== ($row['meta_value'] === null ? null : $valueBytes)) {
                throw new \RuntimeException(
                    "duo: $purpose metadata value read disagrees with the bounded size preflight"
                );
            }
        }
        return $rows;
    }

    /** mysqli text-protocol identity: canonical positive decimal only. */
    public static function positive_id(mixed $value): ?int {
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($id) && $id > 0 ? $id : null;
    }

    /** mysqli text-protocol OCTET_LENGTH: canonical nonnegative decimal only. */
    private static function nonnegative_size(mixed $value): ?int {
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $size = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($size) && $size >= 0 ? $size : null;
    }
}
