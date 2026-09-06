<?php
declare(strict_types=1);

namespace WPrism;

/** Declarative physical-table scope for one audited native database callback. */
final readonly class NativeDatabaseProfile {
    /** @var list<string> */
    private array $readTables;
    /** @var list<string> */
    private array $writeTables;
    /** @var list<string> */
    private array $tablePresenceReads;

    /**
     * @param list<string> $readTables
     * @param list<string> $writeTables
     * @param list<string> $tablePresenceReads
     */
    public function __construct(
        array $readTables,
        array $writeTables = [],
        array $tablePresenceReads = []
    ) {
        $this->readTables = self::normalize_tables($readTables, 'read');
        $this->writeTables = self::normalize_tables($writeTables, 'write');
        $this->tablePresenceReads = self::normalize_tables($tablePresenceReads, 'table-presence read');
        if (count(array_unique(array_merge(
            $this->readTables,
            $this->writeTables,
            $this->tablePresenceReads
        ))) > 256) {
            throw new \InvalidArgumentException(
                'wprism: native database profile exceeds its 256-table scope bound'
            );
        }
    }

    /** @param list<string> $tables */
    public static function read_only(array $tables): self {
        return new self($tables);
    }

    /**
     * Bind exact table-presence metadata separately from existing-table reads.
     *
     * @param list<string> $readTables
     * @param list<string> $tablePresenceReads
     */
    public static function schema_read_only(array $readTables, array $tablePresenceReads): self {
        return new self($readTables, [], $tablePresenceReads);
    }

    /** @return list<string> */
    public function read_tables(): array {
        return array_values(array_diff($this->readTables, $this->writeTables));
    }

    /** @return list<string> */
    public function write_tables(): array {
        return $this->writeTables;
    }

    /** @return list<string> */
    public function readable_tables(): array {
        $tables = array_values(array_unique(array_merge($this->readTables, $this->writeTables)));
        sort($tables, SORT_STRING);
        return $tables;
    }

    public function is_read_only(): bool {
        return $this->writeTables === [];
    }

    /** @return list<string> */
    public function table_presence_reads(): array {
        return $this->tablePresenceReads;
    }

    /** @param list<string> $tables @return list<string> */
    private static function normalize_tables(array $tables, string $kind): array {
        if (!array_is_list($tables)) {
            throw new \InvalidArgumentException("wprism: native database $kind-table scope is not a list");
        }
        foreach ($tables as $table) {
            if (!is_string($table) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1) {
                throw new \InvalidArgumentException(
                    "wprism: native database $kind-table scope contains an unsafe table identifier"
                );
            }
        }
        $tables = array_values(array_unique($tables));
        sort($tables, SORT_STRING);
        return $tables;
    }
}
