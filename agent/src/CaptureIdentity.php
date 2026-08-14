<?php
namespace Duo;

require_once __DIR__ . '/CaptureTransaction.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/Uuid.php';

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
        $uuid = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_duo_uuid' LIMIT 1",
            $id
        ));
        if (!$uuid) {
            if ($strictReadOnly) {
                throw new \RuntimeException(
                    "duo: refresh export refused — post $id has no durable _duo_uuid; "
                    . 'run the existing capture/identity recovery gate before exporting production'
                );
            }
            if (!$mint) {
                return null;
            }
            $uuid = Uuid::v7();
            Db::insert(
                $wpdb->postmeta,
                ['post_id' => $id, 'meta_key' => '_duo_uuid', 'meta_value' => $uuid],
                null,
                'capture mint post identity'
            );
            ($this->checkTransientDbError)("mint _duo_uuid for post $id");
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
        $uuid = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = '_duo_uuid' LIMIT 1",
            $termId
        ));
        if (!$uuid) {
            if ($strictReadOnly) {
                throw new \RuntimeException(
                    "duo: refresh export refused — term $termId has no durable _duo_uuid; "
                    . 'run the existing capture/identity recovery gate before exporting production'
                );
            }
            if (!$mint) {
                return null;
            }
            $uuid = Uuid::v7();
            Db::insert(
                $wpdb->termmeta,
                ['term_id' => $termId, 'meta_key' => '_duo_uuid', 'meta_value' => $uuid],
                null,
                'capture mint term identity'
            );
            ($this->checkTransientDbError)("mint _duo_uuid for term $termId");
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
}
