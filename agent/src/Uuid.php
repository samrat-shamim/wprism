<?php
namespace Duo;

final class Uuid {
    /**
     * Fixed namespace root for v5() below — any constant, validly-formatted
     * UUID works per RFC 4122 §4.3; this one was minted once (Python
     * uuid.uuid4()) for this project and must NEVER change: changing it
     * would silently re-derive different uuids for every existing
     * natural-key-identified table row across every environment that has
     * ever captured one (Snapshot.php's typed-snapshot identity model —
     * see its docblock).
     */
    public const NAMESPACE_DUO = 'cbb54905-b001-4f6f-8a79-a275a4e8112d';

    /** RFC 9562 UUIDv7: 48-bit ms timestamp + version + 74 random bits. */
    public static function v7(): string {
        $ms   = (int) floor(microtime(true) * 1000);
        $time = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT);
        $rand = bin2hex(random_bytes(10));
        $hex  = $time
            . '7' . substr($rand, 0, 3)
            . dechex((hexdec($rand[3]) & 0x3) | 0x8) . substr($rand, 4, 15);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
            substr($hex, 16, 4), substr($hex, 20, 12)
        );
    }

    /**
     * RFC 4122 §4.3 UUIDv5 (SHA-1, namespace + name): deterministic, not
     * random — the SAME ($namespace, $name) always yields the SAME uuid.
     *
     * Why this exists (Snapshot.php's "natural_key" identity mode): rows in
     * an authored custom table have no meta table of their own to carry a
     * durable `_duo_uuid`-equivalent (posts/terms get that via postmeta/
     * termmeta), so identity for a declared table's rows lives ONLY in the
     * duo_map ledger, keyed by (id_kind, local_id). For most such tables
     * (nf3_forms, nf3_fields, ...) that's the best available and the ledger
     * IS the durable store — losing it loses identity, an accepted, honestly
     * documented limitation (see Snapshot.php). But a table with a genuinely
     * stable, human-chosen, unique natural key (e.g.
     * woocommerce_attribute_taxonomies.attribute_name) can do better: minting
     * the uuid as v5(NAMESPACE_DUO, "<table>:<natural key value>") instead of
     * a random v7() means the SAME uuid is re-derived from the live row even
     * if duo_map is ever lost — recapture reconciles with the existing
     * canonical file instead of minting a phantom duplicate. Callers must
     * still record the result in duo_map via Ledger::set() for normal-path
     * bidirectional lookups; determinism is purely a MINTING-time property.
     */
    public static function v5(string $namespace, string $name): string {
        $ns = str_replace('-', '', $namespace);
        if (!preg_match('/^[0-9a-f]{32}$/', $ns)) {
            throw new \RuntimeException("duo: Uuid::v5() namespace '$namespace' is not a valid UUID");
        }
        $hash = sha1(hex2bin($ns) . $name);
        $hex = substr($hash, 0, 12)
            . '5' . substr($hash, 13, 3)
            . dechex((hexdec($hash[16]) & 0x3) | 0x8) . substr($hash, 17, 15);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
            substr($hex, 16, 4), substr($hex, 20, 12)
        );
    }

    public static function is(string $s): bool {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $s
        );
    }
}
