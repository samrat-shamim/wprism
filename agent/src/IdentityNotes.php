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
     *
     * DUO-3318: the derivation itself is Snapshot's, for every reader — a
     * second copy of "what is this row's natural-key name" here would be free
     * to disagree with the one capture actually used, and this note's entire
     * job is to compare the two. That also carries the parent-scoped
     * multi-column form for free: a renamed slot under an unchanged room
     * reports exactly like a renamed single-column row does.
     */
    public static function natural_key_continuity(
        string $uuid,
        string $table,
        array $decl,
        array $columns
    ): ?string {
        $components = Snapshot::natural_key_components_from_front($decl, $columns);
        if ($components === null) {
            return null;
        }
        if (Uuid::v5(Uuid::NAMESPACE_DUO, Snapshot::natural_key_name($table, $decl, $components)) === $uuid) {
            return null;
        }
        return "$table row " . self::key_label($components)
            . ': renamed since first capture (uuid retained via ledger)';
    }

    /**
     * The human half of the note. A single-component key prints its bare
     * value, byte-identical to what this note has always said; a tuple names
     * each component, because "slot-a" alone would not say which room's
     * slot-a moved.
     *
     * @param array<string,string> $components
     */
    private static function key_label(array $components): string {
        if (count($components) === 1) {
            return (string) reset($components);
        }
        $parts = [];
        foreach ($components as $column => $value) {
            $parts[] = "$column=$value";
        }
        return implode(', ', $parts);
    }
}
