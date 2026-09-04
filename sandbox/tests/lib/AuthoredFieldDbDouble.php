<?php
declare(strict_types=1);

namespace WPrism;

/**
 * Database seam for field-materializer fixtures with purpose-built wpdb stores.
 *
 * These suites inject read failures and concurrent row-shape changes in their
 * local wpdb implementations. Keep those scenarios behind the product's Db
 * API while modelling the authored transaction identity independently of the
 * real driver's session-variable protocol, which has its own kernel suites.
 */
final class Db {
    /** Return one opaque identity while the fixture's authored transaction survives. */
    public static function transaction_authority(string $purpose): object {
        global $wpdb;

        $active = property_exists($wpdb, 'transactionState')
            ? $wpdb->transactionState === '1'
            : (property_exists($wpdb, 'inTransaction') && $wpdb->inTransaction === true);
        if (!$active) {
            throw new \RuntimeException("$purpose transaction is not active");
        }

        // begin_authored_transaction() establishes a new fixture continuity
        // witness; every product operation after it must observe that witness.
        if (str_ends_with($purpose, ' active transaction')) {
            $wpdb->savepointExists = true;
        } elseif (!property_exists($wpdb, 'savepointExists') || !$wpdb->savepointExists) {
            throw new \RuntimeException("$purpose transaction continuity was lost");
        }

        return (object) ['fixture_authority' => true];
    }

    public static function insert(
        string $table,
        array $data,
        mixed $format = null,
        ?string $context = null
    ): int {
        return self::checked(
            self::wpdb()->insert($table, $data, $format),
            $context ?? "insert into $table"
        );
    }

    public static function update(
        string $table,
        array $data,
        array $where,
        mixed $format = null,
        mixed $whereFormat = null,
        ?string $context = null
    ): int {
        return self::checked(
            self::wpdb()->update($table, $data, $where, $format, $whereFormat),
            $context ?? "update $table"
        );
    }

    public static function delete(
        string $table,
        array $where,
        mixed $whereFormat = null,
        ?string $context = null
    ): int {
        return self::checked(
            self::wpdb()->delete($table, $where, $whereFormat),
            $context ?? "delete from $table"
        );
    }

    public static function insert_id(string $context): int {
        $insertId = self::wpdb()->insert_id ?? null;
        if (!is_int($insertId) || $insertId <= 0) {
            throw new DatabaseMutationException($context . ' did not produce an id');
        }
        return $insertId;
    }

    /** Preserve Ledger::set()'s structured mutation API at the fixture seam. */
    public static function mutation(
        string $head,
        string $condition,
        string $tail,
        string $context,
        array $readTables = []
    ): int {
        $head = preg_replace('/\s+/', ' ', trim($head));
        if (!is_string($head)) {
            throw new DatabaseMutationException($context);
        }
        if (preg_match(
            '/^INSERT INTO wp_wprism_map \(uuid, entity_type, id_kind, local_id\) SELECT (.+)$/D',
            $head,
            $match
        ) === 1) {
            $head = 'INSERT INTO wp_wprism_map (uuid, entity_type, id_kind, local_id) VALUES ('
                . $match[1] . ')';
        }
        $sql = $head
            . ($condition !== '' ? ' WHERE ' . $condition : '')
            . ($tail !== '' ? ' ' . $tail : '');
        return self::checked(self::wpdb()->query($sql), $context);
    }

    private static function wpdb(): object {
        global $wpdb;
        if (!is_object($wpdb)) {
            throw new \RuntimeException('field-materializer fixture has no wpdb');
        }
        return $wpdb;
    }

    private static function checked(mixed $result, string $context): int {
        if ($result === false) {
            throw new DatabaseMutationException($context);
        }
        return (int) $result;
    }
}
