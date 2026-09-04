<?php
declare(strict_types=1);

namespace WPrism;

/** Closed, value-typed definition for one engine-owned InnoDB table. */
final readonly class NativeTableDefinition {
    /** @var array<string,array<string,mixed>> */
    private array $columns;
    /** @var list<string> */
    private array $primaryKey;
    /** @var array<string,list<string>> */
    private array $uniqueKeys;
    /** @var array<string,list<string>> */
    private array $indexes;

    /**
     * @param array<string,array{type:string,nullable:bool,length?:int,unsigned?:bool,auto_increment?:bool,default?:int|string|null}> $columns
     * @param list<string> $primaryKey
     * @param array<string,list<string>> $uniqueKeys
     * @param array<string,list<string>> $indexes
     */
    public function __construct(
        array $columns,
        array $primaryKey,
        array $uniqueKeys = [],
        array $indexes = []
    ) {
        if ($columns === [] || array_is_list($columns) || count($columns) > 64) {
            throw new \InvalidArgumentException(
                'wprism: native table definition requires between 1 and 64 named columns'
            );
        }
        $normalized = [];
        foreach ($columns as $name => $column) {
            self::assert_identifier($name, 'column');
            if (!is_array($column) || array_is_list($column)) {
                throw new \InvalidArgumentException('wprism: native table column definition is malformed');
            }
            $unknown = array_diff(
                array_keys($column),
                ['type', 'nullable', 'length', 'unsigned', 'auto_increment', 'default']
            );
            if ($unknown !== []
                || !isset($column['type'])
                || !is_string($column['type'])
                || !array_key_exists('nullable', $column)
                || !is_bool($column['nullable'])) {
                throw new \InvalidArgumentException('wprism: native table column definition is malformed');
            }
            $type = strtolower($column['type']);
            if (!in_array($type, ['bigint', 'char', 'datetime', 'longtext', 'varchar'], true)) {
                throw new \InvalidArgumentException("wprism: native table column '$name' has an unsupported type");
            }
            $length = $column['length'] ?? null;
            $lengthType = in_array($type, ['char', 'varchar'], true);
            $maximumLength = $type === 'char' ? 255 : 16383;
            if ($lengthType !== is_int($length)
                || ($lengthType && ($length < 1 || $length > $maximumLength))) {
                throw new \InvalidArgumentException("wprism: native table column '$name' has an invalid length");
            }
            $unsigned = $column['unsigned'] ?? false;
            $autoIncrement = $column['auto_increment'] ?? false;
            if (!is_bool($unsigned)
                || !is_bool($autoIncrement)
                || ($unsigned && $type !== 'bigint')
                || ($autoIncrement && ($type !== 'bigint' || $column['nullable']))) {
                throw new \InvalidArgumentException("wprism: native table column '$name' has invalid numeric attributes");
            }
            if (array_key_exists('default', $column)) {
                self::assert_default(
                    $name,
                    $type,
                    $column['nullable'],
                    $unsigned,
                    $length,
                    $column['default']
                );
                if ($autoIncrement) {
                    throw new \InvalidArgumentException(
                        "wprism: native table auto-increment column '$name' cannot declare a default"
                    );
                }
            }
            $normalized[$name] = [
                'type' => $type,
                'nullable' => $column['nullable'],
                'length' => $length,
                'unsigned' => $unsigned,
                'auto_increment' => $autoIncrement,
            ];
            if (array_key_exists('default', $column)) {
                $normalized[$name]['default'] = $column['default'];
            }
        }

        $this->columns = $normalized;
        $this->primaryKey = self::normalize_key_columns($primaryKey, $normalized, 'primary key');
        if ($this->primaryKey === []) {
            throw new \InvalidArgumentException('wprism: native table definition requires a primary key');
        }
        foreach ($this->primaryKey as $column) {
            if ($normalized[$column]['nullable']) {
                throw new \InvalidArgumentException(
                    "wprism: native table primary-key column '$column' cannot be nullable"
                );
            }
        }
        $this->uniqueKeys = self::normalize_keys($uniqueKeys, $normalized, 'unique key');
        $this->indexes = self::normalize_keys($indexes, $normalized, 'index');
        $duplicates = array_intersect(array_keys($this->uniqueKeys), array_keys($this->indexes));
        if ($duplicates !== [] || count($this->uniqueKeys) + count($this->indexes) > 32) {
            throw new \InvalidArgumentException('wprism: native table definition has conflicting or excessive indexes');
        }
        $autoIncrement = array_keys(array_filter(
            $normalized,
            static fn(array $column): bool => $column['auto_increment']
        ));
        if (count($autoIncrement) > 1) {
            throw new \InvalidArgumentException(
                'wprism: native table definition may contain only one auto-increment column'
            );
        }
        if ($autoIncrement !== []) {
            $indexedFirst = [$this->primaryKey[0]];
            foreach (array_merge($this->uniqueKeys, $this->indexes) as $key) {
                $indexedFirst[] = $key[0];
            }
            if (!in_array($autoIncrement[0], $indexedFirst, true)) {
                throw new \InvalidArgumentException(
                    'wprism: native table auto-increment column must lead an index'
                );
            }
        }
    }

    public function create_sql(string $table, string $charsetCollate): string {
        self::assert_identifier($table, 'table');
        $charsetCollate = trim($charsetCollate);
        if ($charsetCollate !== ''
            && preg_match(
                '/^DEFAULT CHARACTER SET [A-Za-z0-9_]{1,64}(?: COLLATE [A-Za-z0-9_]{1,64})?$/D',
                $charsetCollate
            ) !== 1) {
            throw new \InvalidArgumentException('wprism: native table definition received unsafe charset metadata');
        }

        $rows = [];
        foreach ($this->columns as $name => $column) {
            $type = strtoupper((string) $column['type']);
            if (is_int($column['length'])) {
                $type .= '(' . $column['length'] . ')';
            }
            $row = "`$name` $type";
            if ($column['unsigned']) {
                $row .= ' UNSIGNED';
            }
            $row .= $column['nullable'] ? ' NULL' : ' NOT NULL';
            if (array_key_exists('default', $column)) {
                $row .= ' DEFAULT ' . self::render_default($column['default']);
            }
            if ($column['auto_increment']) {
                $row .= ' AUTO_INCREMENT';
            }
            $rows[] = $row;
        }
        $rows[] = 'PRIMARY KEY (' . self::render_key_columns($this->primaryKey) . ')';
        foreach ($this->uniqueKeys as $name => $columns) {
            $rows[] = "UNIQUE KEY `$name` (" . self::render_key_columns($columns) . ')';
        }
        foreach ($this->indexes as $name => $columns) {
            $rows[] = "KEY `$name` (" . self::render_key_columns($columns) . ')';
        }
        return "CREATE TABLE IF NOT EXISTS `$table` (\n    "
            . implode(",\n    ", $rows)
            . "\n) ENGINE=InnoDB"
            . ($charsetCollate === '' ? '' : ' ' . $charsetCollate);
    }

    private static function assert_default(
        string $name,
        string $type,
        bool $nullable,
        bool $unsigned,
        ?int $length,
        mixed $default
    ): void {
        if ($default === null) {
            if (!$nullable) {
                throw new \InvalidArgumentException(
                    "wprism: native table column '$name' has a NULL default but is not nullable"
                );
            }
            return;
        }
        if (is_int($default) && $type === 'bigint' && (!$unsigned || $default >= 0)) {
            return;
        }
        if (is_string($default)
            && in_array($type, ['char', 'varchar'], true)
            && is_int($length)
            && strlen($default) <= $length
            && preg_match('/^[A-Za-z0-9_.:-]*$/D', $default) === 1) {
            return;
        }
        throw new \InvalidArgumentException("wprism: native table column '$name' has an unsafe default");
    }

    private static function render_default(mixed $default): string {
        if ($default === null) {
            return 'NULL';
        }
        if (is_int($default)) {
            return (string) $default;
        }
        return "'" . $default . "'";
    }

    /**
     * @param array<string,array<string,mixed>> $columns
     * @return array<string,list<string>>
     */
    private static function normalize_keys(array $keys, array $columns, string $kind): array {
        if (array_is_list($keys) && $keys !== []) {
            throw new \InvalidArgumentException("wprism: native table $kind definitions must be named");
        }
        $normalized = [];
        foreach ($keys as $name => $keyColumns) {
            self::assert_identifier($name, $kind);
            if (strtoupper($name) === 'PRIMARY') {
                throw new \InvalidArgumentException("wprism: native table $kind cannot be named PRIMARY");
            }
            $normalized[$name] = self::normalize_key_columns($keyColumns, $columns, $kind);
            if ($normalized[$name] === []) {
                throw new \InvalidArgumentException("wprism: native table $kind cannot be empty");
            }
        }
        return $normalized;
    }

    /**
     * @param array<string,array<string,mixed>> $columns
     * @return list<string>
     */
    private static function normalize_key_columns(array $key, array $columns, string $kind): array {
        if (!array_is_list($key)
            || count($key) > 16
            || array_filter($key, 'is_string') !== $key
            || count($key) !== count(array_unique($key, SORT_STRING))) {
            throw new \InvalidArgumentException("wprism: native table $kind columns are malformed");
        }
        foreach ($key as $column) {
            if (!array_key_exists($column, $columns)) {
                throw new \InvalidArgumentException("wprism: native table $kind names an unknown column");
            }
            if ($columns[$column]['type'] === 'longtext') {
                throw new \InvalidArgumentException(
                    "wprism: native table $kind cannot index a LONGTEXT column without a prefix"
                );
            }
        }
        return $key;
    }

    /** @param list<string> $columns */
    private static function render_key_columns(array $columns): string {
        return implode(', ', array_map(static fn(string $column): string => "`$column`", $columns));
    }

    private static function assert_identifier(mixed $identifier, string $kind): void {
        if (!is_string($identifier)
            || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $identifier) !== 1) {
            throw new \InvalidArgumentException("wprism: native table $kind identifier is unsafe");
        }
    }
}
