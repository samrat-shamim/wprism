<?php
namespace WPrism;

require_once __DIR__ . '/CaptureTransaction.php';
require_once __DIR__ . '/../Kernel/Db.php';
require_once __DIR__ . '/../Repository/Ledger.php';
require_once __DIR__ . '/../Kernel/Uuid.php';

/** Observes, mints, and verifies portable post/term capture identity. */
final class CaptureIdentity {
    private \Closure $checkTransientDbError;

    public function __construct(?callable $checkTransientDbError = null) {
        $this->checkTransientDbError = \Closure::fromCallable(
            $checkTransientDbError ?? [CaptureTransaction::class, 'check_transient_db_error']
        );
    }

    public function ensurePost(
        int $id,
        string $entityType,
        bool $mint,
        bool $strictReadOnly = false
    ): ?string {
        global $wpdb;
        $uuid = $this->readIdentity($wpdb->postmeta, 'post_id', $id, "post $id");
        if ($uuid === null) {
            if ($strictReadOnly) {
                throw new \RuntimeException(
                    "wprism: refresh export refused — post $id has no durable _wprism_uuid; "
                    . 'run the existing capture/identity recovery gate before exporting production'
                );
            }
            if (!$mint) {
                return null;
            }
            $uuid = Uuid::v7();
            Db::insert(
                $wpdb->postmeta,
                ['post_id' => $id, 'meta_key' => '_wprism_uuid', 'meta_value' => $uuid],
                null,
                'capture mint post identity'
            );
            ($this->checkTransientDbError)("mint _wprism_uuid for post $id");
        }
        if ($strictReadOnly) {
            Ledger::require_read_only_mapping($uuid, $entityType, Ledger::KIND_POST, $id, "post $id");
            return $uuid;
        }
        Ledger::set($uuid, $entityType, Ledger::KIND_POST, $id);
        ($this->checkTransientDbError)("identity ledger for post $id");
        return $uuid;
    }

    public function ensureTerm(
        object $term,
        string $entityType,
        bool $mint,
        bool $strictReadOnly = false
    ): ?string {
        global $wpdb;
        $termId = (int) $term->term_id;
        $uuid = $this->readIdentity($wpdb->termmeta, 'term_id', $termId, "term $termId");
        if ($uuid === null) {
            if ($strictReadOnly) {
                throw new \RuntimeException(
                    "wprism: refresh export refused — term $termId has no durable _wprism_uuid; "
                    . 'run the existing capture/identity recovery gate before exporting production'
                );
            }
            if (!$mint) {
                return null;
            }
            $uuid = Uuid::v7();
            Db::insert(
                $wpdb->termmeta,
                ['term_id' => $termId, 'meta_key' => '_wprism_uuid', 'meta_value' => $uuid],
                null,
                'capture mint term identity'
            );
            ($this->checkTransientDbError)("mint _wprism_uuid for term $termId");
        }
        if ($strictReadOnly) {
            Ledger::require_read_only_mapping($uuid, $entityType, Ledger::KIND_TERM, $termId, "term $termId");
            Ledger::require_read_only_mapping(
                $uuid,
                $entityType,
                Ledger::KIND_TT,
                (int) $term->term_taxonomy_id,
                "term taxonomy for term $termId"
            );
            return $uuid;
        }
        Ledger::set($uuid, $entityType, Ledger::KIND_TERM, $termId);
        ($this->checkTransientDbError)("identity ledger (term) for term $termId");
        Ledger::set($uuid, $entityType, Ledger::KIND_TT, (int) $term->term_taxonomy_id);
        ($this->checkTransientDbError)("identity ledger (term_taxonomy) for term $termId");
        return $uuid;
    }

    private function readIdentity(string $table, string $ownerColumn, int $ownerId, string $owner): ?string {
        global $wpdb;
        foreach ([$table, $ownerColumn] as $identifier) {
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $identifier) !== 1) {
                throw new \RuntimeException("wprism: capture identity for $owner received an unsafe SQL identifier");
            }
        }
        if ($ownerId <= 0) {
            throw new \RuntimeException("wprism: capture identity for $owner received a nonpositive owner identity");
        }
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT meta_key, LEFT(meta_value, 37) AS meta_value, OCTET_LENGTH(meta_value) AS meta_value_bytes '
            . "FROM `$table` WHERE `$ownerColumn` = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 3",
            $ownerId,
            '_wprism_uuid'
        ), ARRAY_A);
        $queryError = trim((string) ($wpdb->last_error ?? ''));
        ($this->checkTransientDbError)("read _wprism_uuid for $owner");
        if (!is_array($rows) || !array_is_list($rows) || $queryError !== '') {
            throw new \RuntimeException("wprism: capture identity read for $owner failed");
        }
        if (count($rows) > 1) {
            throw new \RuntimeException("wprism: capture identity for $owner found ambiguous collation-equal metadata rows");
        }
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        $bytes = is_array($row) && is_string($row['meta_value_bytes'] ?? null)
            && preg_match('/^(?:0|[1-9][0-9]*)$/D', $row['meta_value_bytes']) === 1
            ? filter_var($row['meta_value_bytes'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
            : false;
        if (!is_array($row)
            || array_keys($row) !== ['meta_key', 'meta_value', 'meta_value_bytes']
            || !is_string($row['meta_key'] ?? null)
            || !hash_equals('_wprism_uuid', $row['meta_key'])
            || !is_string($row['meta_value'] ?? null)
            || !is_int($bytes)
            || $bytes !== strlen($row['meta_value'])
            || $bytes > 36
            || !Uuid::is($row['meta_value'])) {
            throw new \RuntimeException(
                "wprism: capture identity for $owner is aliased, malformed, oversized, or not a canonical UUID"
            );
        }
        return $row['meta_value'];
    }
}
