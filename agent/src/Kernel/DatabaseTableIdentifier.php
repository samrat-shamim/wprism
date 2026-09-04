<?php
declare(strict_types=1);

namespace WPrism;

/** Closed physical-table identifier grammar shared by database boundaries. */
final class DatabaseTableIdentifier {
    /** @param list<string> $tables */
    public static function assert_many(array $tables, string $purpose): void {
        foreach ($tables as $table) {
            if (!is_string($table) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1) {
                throw new \RuntimeException("wprism: $purpose refused — unsafe table identifier");
            }
        }
    }
}
