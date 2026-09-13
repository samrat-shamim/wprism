<?php
declare(strict_types=1);

namespace WPrism;

/**
 * A physical table can contain several plugins' rows. The discriminator is
 * ownership, so capture, canonical authorization and every retained-id write
 * must agree on the same byte-exact predicate. SQL collation is not authority.
 */
final class TableRowScope {
    public const FEATURE = 'table-row-scopes/v1';
    public const SETS_FEATURE = 'table-row-scope-sets/v1';
    public const MAX_DISCRIMINATORS = 8;
    public const MAX_ALTERNATIVES = 16;

    public static function declaration_grammar(): array {
        return [
            'path' => 'tables.<table>.row_scope',
            'shape' => 'nonempty object: column => exact ASCII string; all discriminators must match',
            'max_discriminators' => self::MAX_DISCRIMINATORS,
            'value_pattern' => '[A-Za-z0-9_-]{1,128}',
            'comparison' => 'byte-exact, including case and trailing bytes',
            'refines' => 'ordinary authored_snapshot rows; authored text columns; natural keys include every discriminator; no column codecs, structural refs or attached sidecars; manifest feature required',
        ];
    }

    public static function sets_declaration_grammar(): array {
        return [
            'path' => 'tables.<table>.row_scope.<column>',
            'requires' => self::FEATURE,
            'shape' => 'exact ASCII string or sorted unique list of 2-16 exact ASCII strings',
            'max_alternatives' => self::MAX_ALTERNATIVES,
            'value_pattern' => '[A-Za-z0-9_-]{1,128}',
            'comparison' => 'one listed value per column; every discriminator must match byte-exactly',
            'refines' => 'same ownership, natural-key, column and transaction boundaries as table-row-scopes/v1; no implicit alternatives',
        ];
    }

    /** Validate the manifest channel before interpreting a nested declaration. */
    public static function validate_source(array $source, string $label, bool $site): void {
        foreach (($source['tables'] ?? []) as $table => $decl) {
            if (!is_array($decl) || !array_key_exists('row_scope', $decl)) {
                continue;
            }
            if ($site || !in_array(self::FEATURE, (array) ($source['engine_features'] ?? []), true)) {
                throw new \RuntimeException(
                    "wprism: $label tables.$table.row_scope requires manifest engine feature '" . self::FEATURE
                    . "'; site policy cannot grant row ownership"
                );
            }
            foreach (array_keys((array) ($decl['row_scope'] ?? [])) as $column) {
                if (is_array($decl['row_scope']) && is_array($decl['row_scope'][$column])
                    && !in_array(self::SETS_FEATURE, (array) ($source['engine_features'] ?? []), true)) {
                    throw new \RuntimeException("wprism: $label tables.$table.row_scope sets require manifest engine feature '"
                        . self::SETS_FEATURE . "'");
                }
                if (isset($source['column_codecs'][$table][$column])) {
                    throw new \RuntimeException("wprism: $label tables.$table.row_scope cannot discriminate on a decoded column");
                }
            }
        }
    }

    /** No SQL fragments, coercion, negation, wildcard or implicit default scope. */
    public static function validate(string $table, array $decl): void {
        if (!array_key_exists('row_scope', $decl)) {
            return;
        }
        $where = "table '$table' row_scope";
        $scope = $decl['row_scope'];
        if (($decl['class'] ?? '') !== 'authored_snapshot'
            || !in_array($decl['identity']['mode'] ?? 'mapped', ['mapped', 'natural_key'], true)) {
            throw new \RuntimeException("wprism: $where requires an ordinary authored_snapshot row table");
        }
        if (!is_array($scope) || $scope === [] || array_is_list($scope) || count($scope) > self::MAX_DISCRIMINATORS) {
            throw new \RuntimeException("wprism: $where must be an object of 1-8 exact text discriminators");
        }
        $identity = (array) ($decl['identity'] ?? []);
        $identityColumns = isset($identity['column']) ? [$identity['column']] : ($identity['columns'] ?? []);
        if (!is_array($identityColumns)) {
            throw new \RuntimeException("wprism: $where requires a valid identity column list");
        }
        foreach ($scope as $column => $value) {
            if (is_array($value) && !self::valid_values($value)) {
                throw new \RuntimeException("wprism: $where sets require a sorted list of 2-16 distinct nonempty ASCII discriminator strings");
            }
            if (!is_string($column) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $column) !== 1
                || !self::valid_values($value)) {
                throw new \RuntimeException("wprism: $where requires identifier columns and nonempty ASCII discriminator strings");
            }
            if (($decl['columns'][$column]['class'] ?? '') !== 'authored') {
                throw new \RuntimeException("wprism: $where column '$column' must be a declared authored column");
            }
            if (($identity['mode'] ?? 'mapped') === 'natural_key' && !in_array($column, $identityColumns, true)) {
                throw new \RuntimeException("wprism: $where column '$column' must participate in the complete natural key");
            }
        }
        if (($decl['refs'] ?? []) !== []) {
            throw new \RuntimeException("wprism: $where does not yet admit structural refs; their whole-table traversal needs scoped ownership");
        }
    }

    private static function valid_values(mixed $value): bool {
        if (is_string($value)) return preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $value) === 1;
        if (!is_array($value) || !array_is_list($value) || count($value) < 2 || count($value) > self::MAX_ALTERNATIVES) return false;
        $previous = null;
        foreach ($value as $member) {
            if (!is_string($member) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $member) !== 1
                || ($previous !== null && strcmp($previous, $member) >= 0)) return false;
            $previous = $member;
        }
        return true;
    }

    /** A sidecar's current global key inventory cannot establish row ownership. */
    public static function validate_set(array $tables): void {
        foreach ($tables as $table => $decl) {
            $owner = $decl['attached_to']['table'] ?? null;
            if (($decl['class'] ?? '') === 'authored_snapshot_meta'
                && $owner !== null && isset($tables[$owner]['row_scope'])) {
                throw new \RuntimeException("wprism: attached-meta table '$table' cannot attach to row-scoped table '$owner'");
            }
        }
    }

    public static function matches(array $decl, array $row): bool {
        self::validate('selected', $decl);
        foreach (($decl['row_scope'] ?? []) as $column => $value) {
            if (!array_key_exists($column, $row)
                || (is_array($value) ? !in_array($row[$column], $value, true) : $row[$column] !== $value)) {
                return false;
            }
        }
        return true;
    }

    public static function assert_matches(string $table, array $decl, array $row): void {
        if (!self::matches($decl, $row)) {
            // Never echo an unowned row's payload or discriminator value.
            throw new \RuntimeException("wprism: table '$table' row is outside declared row_scope; refusing ownership");
        }
    }

    /** Prepared predicate, without WHERE/AND, for a previously validated scope. */
    public static function predicate(array $decl, object $db, string $alias = ''): string {
        if (!array_key_exists('row_scope', $decl)) {
            return '';
        }
        self::validate('selected', $decl);
        if ($alias !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias) !== 1) {
            throw new \RuntimeException('wprism: invalid typed-table row scope query alias');
        }
        $parts = [];
        foreach ($decl['row_scope'] as $column => $value) {
            $prefix = $alias === '' ? '' : "`$alias`.";
            if (is_array($value)) {
                $alternatives = [];
                foreach ($value as $member) $alternatives[] = $db->prepare("BINARY $prefix`$column` = BINARY %s", $member);
                // Parentheses retain the conjunction: an allowed template type
                // cannot claim a different item_type in the same physical table.
                $parts[] = '(' . implode(' OR ', $alternatives) . ')';
            } else {
                $parts[] = $db->prepare("BINARY $prefix`$column` = BINARY %s", $value);
            }
        }
        return implode(' AND ', $parts);
    }

}
