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
}
