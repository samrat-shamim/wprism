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
    public const TYPED_FORMAT = 'wprism-typed-reference/v1';

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

    /**
     * Preserve a reference's native scalar type without changing its ordinary
     * identity token. Grammar owns feature negotiation and source-ID validity;
     * this pure codec owns only the closed canonical envelope.
     *
     * The wire field is `ref`, not `token`: canonical configuration is scanned
     * for credential roles, and `token` correctly denotes a sensitive role.
     * A reference record must pass that scan without exempting its contents.
     *
     * @return array{format:string,type:string,ref:string}
     */
    public static function encode_typed(string $token, string $scalarType): array {
        $record = ['format' => self::TYPED_FORMAT, 'type' => $scalarType, 'ref' => $token];
        self::decode_typed($record);
        return $record;
    }

    /** @return array{type:string,ref:string} */
    public static function decode_typed(mixed $record, ?string $refKind = null): array {
        if (!is_array($record) || array_keys($record) !== ['format', 'type', 'ref']
            || $record['format'] !== self::TYPED_FORMAT
            || !in_array($record['type'], ['int', 'string'], true)
            || !is_string($record['ref'])) {
            throw new \RuntimeException('wprism: malformed typed reference');
        }
        try {
            $identity = self::decode($record['ref']);
        } catch (\RuntimeException) {
            throw new \RuntimeException('wprism: malformed typed reference');
        }
        // The existing decoder owns token vocabulary. Exact re-encoding also
        // closes its historical trailing-newline tolerance for this new wire
        // envelope, without changing the ordinary token API's old behavior.
        if (self::encode($identity['kind'], $identity['uuid']) !== $record['ref']
            || ($refKind !== null && $identity['kind'] !== $refKind)) {
            throw new \RuntimeException('wprism: malformed typed reference');
        }
        return ['type' => $record['type'], 'ref' => $record['ref']];
    }
}
