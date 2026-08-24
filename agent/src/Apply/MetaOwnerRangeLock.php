<?php
declare(strict_types=1);

namespace Duo;

require_once __DIR__ . '/../Delete/DeleteGuardEvaluator.php';
require_once __DIR__ . '/../Kernel/MetaRows.php';

/**
 * Transaction-bound metadata owner-range/gap lock.
 *
 * The full-width first-column owner index plus InnoDB and gap-locking
 * isolation make an absent key range as authoritative as a populated one.
 * Callers retain this object while reconciling the returned rows inside the
 * same authored transaction; concurrent inserts/updates/deletes serialize.
 */
final class MetaOwnerRangeLock {
    private function __construct(
        private readonly string $table,
        private readonly string $ownerColumn,
        private readonly string $index,
        private readonly string $purpose
    ) {
    }

    public static function prepare(string $table, string $ownerColumn, string $purpose): self {
        DeleteGuardEvaluator::assert_table_identifiers([$table], $purpose);
        DeleteGuardEvaluator::assert_innodb_tables([$table], $purpose);
        DeleteGuardEvaluator::assert_transaction_isolation($purpose);
        $index = DeleteGuardEvaluator::full_width_lock_index($table, $ownerColumn, $purpose);
        return new self($table, $ownerColumn, $index, $purpose);
    }

    public static function from_proven_descriptor(
        string $table,
        string $ownerColumn,
        string $index,
        string $purpose
    ): self {
        DeleteGuardEvaluator::assert_table_identifiers([$table], $purpose);
        foreach ([$ownerColumn, $index] as $identifier) {
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $identifier) !== 1) {
                throw new \RuntimeException("duo: $purpose received an unsafe proven lock descriptor");
            }
        }
        return new self($table, $ownerColumn, $index, $purpose);
    }

    /**
     * @return list<array{meta_id:string,meta_key:string,meta_value:?string}>
     */
    public function read(int $ownerId, string $idColumn = 'meta_id'): array {
        // Recheck immediately before FOR UPDATE: descriptor reuse must not
        // bless a later call after an intervening COMMIT/autocommit change.
        DeleteGuardEvaluator::assert_transaction_isolation($this->purpose);
        return MetaRows::ordered(
            $this->table,
            $this->ownerColumn,
            $ownerId,
            $idColumn,
            $this->purpose,
            $this->index
        );
    }

    /**
     * Return rows whose key is byte-exact after proving that the database's
     * collation-equality set contains no aliases. WordPress metadata columns
     * are normally case/accent insensitive, so filtering the already-read
     * PHP strings alone cannot tell whether `_DUO_UUID` (or another collation
     * alias) could win a later ordinary `meta_key = %s` lookup.
     *
     * @return list<array{meta_id:string,meta_key:string,meta_value:?string}>
     */
    public function exact_key_rows(int $ownerId, string $key, string $idColumn = 'meta_id'): array {
        global $wpdb;
        $characters = strlen($key) <= MetaRows::MAX_META_KEY_BYTES
            ? preg_match_all('/./us', $key)
            : false;
        if ($key === ''
            || !is_int($characters)
            || $characters > MetaRows::MAX_META_KEY_CHARACTERS
            || preg_match('/[\x00-\x1F\x7F]/', $key) === 1
            || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $idColumn) !== 1) {
            throw new \RuntimeException("duo: {$this->purpose} requested a malformed exact metadata key read");
        }

        $all = $this->read($ownerId, $idColumn);
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT `$idColumn` AS meta_id, meta_key FROM `{$this->table}` FORCE INDEX (`{$this->index}`) "
            . "WHERE `{$this->ownerColumn}` = %d AND meta_key = %s "
            . "ORDER BY `$idColumn` ASC LIMIT " . (MetaRows::MAX_OWNER_ROWS + 1) . ' FOR UPDATE',
            $ownerId,
            $key
        ), ARRAY_A);
        if (!is_array($rows)
            || !array_is_list($rows)
            || trim((string) ($wpdb->last_error ?? '')) !== ''
            || count($rows) > MetaRows::MAX_OWNER_ROWS) {
            throw new \RuntimeException("duo: {$this->purpose} exact metadata key equality read failed or exceeded its bound");
        }

        $allById = [];
        $expectedExactIds = [];
        foreach ($all as $row) {
            $allById[$row['meta_id']] = $row;
            if (hash_equals($key, $row['meta_key'])) {
                $expectedExactIds[$row['meta_id']] = true;
            }
        }
        $exact = [];
        $seen = [];
        foreach ($rows as $position => $row) {
            $id = is_array($row) ? MetaRows::positive_id($row['meta_id'] ?? null) : null;
            $rowKey = is_array($row) ? ($row['meta_key'] ?? null) : null;
            if (!is_array($row)
                || array_keys($row) !== ['meta_id', 'meta_key']
                || $id === null
                || !is_string($rowKey)
                || isset($seen[$id])
                || !isset($allById[(string) $id])) {
                throw new \RuntimeException(
                    "duo: {$this->purpose} exact metadata key equality returned a malformed row at bounded position $position"
                );
            }
            $seen[$id] = true;
            if (!hash_equals($key, $rowKey)) {
                throw new \RuntimeException(
                    "duo: {$this->purpose} found a collation-equal non-byte-exact metadata key alias"
                );
            }
            $exact[] = $allById[(string) $id];
        }
        if (array_keys($seen) !== array_map('intval', array_keys($expectedExactIds))) {
            throw new \RuntimeException(
                "duo: {$this->purpose} exact metadata key equality disagrees with its locked owner-range witness"
            );
        }
        return $exact;
    }
}
