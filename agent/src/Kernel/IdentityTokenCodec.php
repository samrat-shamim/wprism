<?php
namespace WPrism;

/**
 * Pure wire codec for durable entity identity tokens.
 *
 * Ledger owns the environment-bound UUID/id lookup.  This collaborator owns
 * only the stable token spelling and the one manifest-to-ledger kind alias;
 * it never opens WordPress, a database, or plugin code.  Tokens keeps the
 * historical public methods and delegates this boundary to preserve every
 * existing caller while making the format independently testable.
 */
final class IdentityTokenCodec {
    /** The historical manifest spelling -> ledger id_kind aliases. */
    private const KIND_MAP = [
        // Keep this pure: loading Ledger here would make the codec's
        // standalone contract depend on a database boundary and would also
        // collide with callers that provide their own Ledger test seam.
        'post' => 'post',
        'term' => 'term',
        'tt'   => 'term_taxonomy',
    ];

    /** Match the canonical entity-token kind vocabulary. */
    private const KIND_NAME_RE = '[a-z][a-z0-9_]*';

    /**
     * Translate a manifest ref kind to the ledger keyspace name.
     *
     * Custom manifest-declared id_kind values pass through unchanged; only
     * the three historical aliases above are renamed.
     */
    public static function ledger_kind(string $refKind): string {
        return self::KIND_MAP[$refKind] ?? $refKind;
    }

    /**
     * Format a resolved UUID as the canonical `{{kind:uuid}}` token.
     *
     * A missing lookup remains null, matching Tokens::id_to_token()'s
     * capture-direction contract.  UUID validation belongs to Ledger's
     * durable mapping boundary and is intentionally not duplicated here.
     */
    public static function encode(string $refKind, ?string $uuid): ?string {
        return $uuid === null ? null : '{{' . $refKind . ':' . $uuid . '}}';
    }

    /**
     * Parse a canonical entity token.
     *
     * The exception text is intentionally the historical Tokens contract:
     * callers and refusal regressions depend on malformed tokens being
     * distinguished from valid-but-unresolvable ledger identities.
     *
     * @return array{kind:string,uuid:string}
     */
    public static function decode(string $token): array {
        if (!preg_match('/^\{\{(' . self::KIND_NAME_RE . '):([0-9a-f-]{36})\}\}$/', $token, $m)) {
            throw new \RuntimeException("wprism: malformed ref token '$token'");
        }
        return ['kind' => $m[1], 'uuid' => $m[2]];
    }
}
