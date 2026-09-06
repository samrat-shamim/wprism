<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/DatabaseExceptions.php';
require_once __DIR__ . '/DatabaseLockBoundary.php';
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/Db.php';
}
require_once __DIR__ . '/TransactionAuthority.php';

/** Bind generic table/index evidence to one tracked transaction generation. */
final class TransactionalTableBoundary {
    /** @param list<string> $tables */
    public static function assert_innodb_tables(
        array $tables,
        TransactionAuthority $authority,
        string $purpose
    ): void {
        self::assert_transaction($authority, $purpose . ' before storage proof');
        DatabaseLockBoundary::assert_innodb_tables(
            $tables,
            $purpose,
            self::continuity_proof($authority, $purpose . ' storage proof')
        );
        self::assert_transaction($authority, $purpose . ' after storage proof');
    }

    /** @param list<string> $tables */
    public static function assert_atomic_mutation_tables(
        array $tables,
        TransactionAuthority $authority,
        string $purpose
    ): void {
        self::assert_transaction($authority, $purpose . ' before mutation-table proof');
        DatabaseLockBoundary::assert_atomic_mutation_tables(
            $tables,
            $purpose,
            self::continuity_proof($authority, $purpose . ' mutation-table proof')
        );
        self::assert_transaction($authority, $purpose . ' after mutation-table proof');
    }

    public static function full_width_unique_lock_index(
        string $table,
        string $column,
        TransactionAuthority $authority,
        string $purpose
    ): string {
        self::assert_transaction($authority, $purpose . ' before index proof');
        $index = DatabaseLockBoundary::full_width_lock_index(
            $table,
            $column,
            $purpose,
            true,
            self::continuity_proof($authority, $purpose . ' index proof')
        );
        self::assert_transaction($authority, $purpose . ' after index proof');
        return $index;
    }

    /** @param list<string> $tables */
    public static function assert_table_identifiers(array $tables, string $purpose): void {
        DatabaseLockBoundary::assert_table_identifiers($tables, $purpose);
    }

    private static function assert_transaction(
        TransactionAuthority $authority,
        string $context
    ): void {
        if (!$authority->equals(Db::transaction_authority($context))) {
            throw new DatabaseTransactionOutcomeException($context . ' changed database session authority');
        }
    }

    private static function continuity_proof(
        TransactionAuthority $authority,
        string $context
    ): callable {
        return static function () use ($authority, $context): void {
            self::assert_transaction($authority, $context);
        };
    }
}
