<?php
namespace Duo;

/**
 * Informational identity observations. These helpers never mint, rebind, or
 * otherwise mutate identity; callers decide how to expose the returned note.
 */
final class IdentityNotes {
    /**
     * A natural_key UUID is deterministic bootstrap identity for a row that
     * has never been seen. Once the row is mapped, Ledger::uuid_for() is the
     * continuity authority, so a later key edit intentionally leaves the UUID
     * different from UUIDv5(current key). Name that ordinary state without
     * treating it as a warning or requesting re-derivation.
     */
    public static function natural_key_continuity(
        string $uuid,
        string $table,
        array $decl,
        array $columns
    ): ?string {
        if (($decl['identity']['mode'] ?? 'mapped') !== 'natural_key') {
            return null;
        }
        $identityColumn = (string) ($decl['identity']['column'] ?? '');
        if ($identityColumn === '' || !array_key_exists($identityColumn, $columns)) {
            return null;
        }
        $key = (string) $columns[$identityColumn];
        if ($key === '' || Uuid::v5(Uuid::NAMESPACE_DUO, "$table:$key") === $uuid) {
            return null;
        }
        return "$table row $key: renamed since first capture (uuid retained via ledger)";
    }
}
