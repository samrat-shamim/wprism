<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/DatabaseTableIdentifier.php';
require_once __DIR__ . '/TransactionAuthority.php';
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/Db.php';
}

/** Physical evidence for one scalar consumed as both a term id and a TT id. */
final class TermCoordinateWitness {
    // The core schema contract proves column presence, not width or type.
    // Read one byte beyond each accepted domain so truncation cannot turn an
    // oversized physical value into an apparently exact coordinate.
    private const ID_WITNESS_BYTES = 21;
    private const TAXONOMY_WITNESS_BYTES = 33;

    /**
     * Capture uses its caller's coherent snapshot. Apply passes the exact
     * authored transaction authority and locks term before TT, preserving the
     * materializer's physical order. Connection/session predicates prevent a
     * reconnect between checkpoint and SELECT from witnessing another session.
     */
    public static function matches(int $id, string $taxonomy, ?TransactionAuthority $authority = null): bool {
        global $wpdb;
        if ($id <= 0 || $taxonomy === '' || strlen($taxonomy) > 32) {
            throw new \InvalidArgumentException('term-coordinate witness needs one positive id and bounded taxonomy');
        }
        DatabaseTableIdentifier::assert_many([$wpdb->terms, $wpdb->term_taxonomy], 'term-coordinate witness');
        $idBytes = self::ID_WITNESS_BYTES;
        $taxonomyBytes = self::TAXONOMY_WITNESS_BYTES;
        $termRows = self::read(
            "SELECT LEFT(BINARY term_id, $idBytes) AS term_id FROM {$wpdb->terms} WHERE term_id = %d",
            'term_id',
            $id,
            $authority
        );
        if (count($termRows) !== 1 || array_keys($termRows[0]) !== ['term_id']
            || $termRows[0]['term_id'] !== (string) $id) {
            return false;
        }
        $ttRows = self::read(
            "SELECT LEFT(BINARY term_taxonomy_id, $idBytes) AS term_taxonomy_id, "
                . "LEFT(BINARY term_id, $idBytes) AS term_id, LEFT(BINARY taxonomy, $taxonomyBytes) AS taxonomy "
                . "FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
            'term_taxonomy_id',
            $id,
            $authority
        );
        return count($ttRows) === 1
            && array_keys($ttRows[0]) === ['term_taxonomy_id', 'term_id', 'taxonomy']
            && $ttRows[0]['term_taxonomy_id'] === (string) $id
            && $ttRows[0]['term_id'] === (string) $id
            && $ttRows[0]['taxonomy'] === $taxonomy;
    }

    /** @return list<array<string,mixed>> */
    private static function read(string $head, string $order, int $id, ?TransactionAuthority $authority): array {
        global $wpdb;
        $args = [$id];
        $predicate = '';
        if ($authority !== null) {
            self::assert_authority($authority);
            $predicate = ' AND CONNECTION_ID() = %s AND BINARY @wprism_tx_session = BINARY %s';
            $args[] = $authority->connection_id();
            $args[] = $authority->session_nonce();
        }
        $sql = $head . $predicate . " ORDER BY $order ASC LIMIT 2" . ($authority === null ? '' : ' FOR UPDATE');
        $suppressed = $wpdb->suppress_errors(true);
        $wpdb->last_error = '';
        try {
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
            $error = (string) ($wpdb->last_error ?? '');
            if ($authority !== null) {
                self::assert_authority($authority);
            }
            if ($error !== '' || !is_array($rows) || !array_is_list($rows)
                || array_filter($rows, static fn($row): bool => !is_array($row)) !== []) {
                throw new \RuntimeException('wprism: bounded term-coordinate witness could not be read');
            }
            return $rows;
        } finally {
            $wpdb->suppress_errors($suppressed);
        }
    }

    private static function assert_authority(TransactionAuthority $authority): void {
        if (!$authority->equals(Db::transaction_authority('term-coordinate witness'))) {
            throw new \RuntimeException('wprism: term-coordinate witness lost its authored transaction');
        }
    }
}
