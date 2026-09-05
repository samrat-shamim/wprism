<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/CommandRefusal.php';
require_once __DIR__ . '/IdentityTokenCodec.php';

/**
 * One scalar consumed by several local-id APIs needs an intersection, not a
 * union or first-success lookup. The canonical token keeps its primary kind;
 * every additional binding must name that same UUID at the same integer.
 * Lookup authority stays with the caller's snapshot/transaction, never here.
 */
final class ScalarReferenceIntersection {
    public const FEATURE = 'scalar-reference-intersection/v1';
    public const FIELD = 'ref_same_local_id_as';
    public const TAXONOMY_FIELD = 'ref_taxonomy';
    private const TAXONOMY_PATTERN = '/^[a-z0-9_-]{1,32}$/D';
    private const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    // Term entities are the only canonical entities with multiple physical
    // coordinates (CanonicalLedgerMapGuard). An arbitrary custom keyspace
    // union would claim a same-entity relationship the engine cannot witness.
    private const PRIMARY_KIND = 'term';
    private const OTHER_KINDS = ['tt'];

    /** @return array<string,mixed> Engine-owned value grammar for authoring tools. */
    public static function declaration_grammar(): array {
        return [
            'path' => 'options.<exact-name>.' . self::FIELD,
            'class' => 'authored',
            'ref' => self::PRIMARY_KIND,
            'value' => self::OTHER_KINDS,
            'required_sibling' => [self::TAXONOMY_FIELD => self::TAXONOMY_PATTERN],
            'cast' => ['absent', 'string'],
            'canonical' => 'ordinary primary reference token, or durable zero; no new token kind',
            'capture' => 'all coordinates resolve the stored integer to the same UUID',
            'apply' => 'all coordinates resolve that UUID to the same target integer',
            'authority' => 'exact static v3 manifest option only; no interpreter or site-policy declaration',
        ];
    }

    /** @return non-empty-list<string> primary kind followed by its additional coordinate */
    public static function kinds(array $rule, string $where): array {
        $primary = $rule['ref'] ?? null;
        $others = $rule[self::FIELD] ?? null;
        if (($rule['class'] ?? null) !== 'authored'
            || $primary !== self::PRIMARY_KIND || $others !== self::OTHER_KINDS
            || !is_string($rule[self::TAXONOMY_FIELD] ?? null)
            || preg_match(self::TAXONOMY_PATTERN, $rule[self::TAXONOMY_FIELD]) !== 1
            || array_intersect(array_keys($rule), [
                'sub_keys', 'json_refs', 'key_refs', 'plain_data', 'json_encoded', 'repeated_rows',
            ]) !== []
            || (array_key_exists('cast', $rule) && $rule['cast'] !== 'string')) {
            throw new \RuntimeException(
                "wprism: $where." . self::FIELD . ' requires an authored scalar term ref with exactly the tt coordinate and a canonical ref_taxonomy'
            );
        }
        return [$primary, ...$others];
    }

    /** A scope exclusion can remove propagation, never weaken its value contract. */
    public static function assert_site_override(array $declared, array $override, string $where): void {
        if (!array_key_exists(self::FIELD, $declared)) {
            return;
        }
        $excluded = in_array($override['class'] ?? null, ['runtime', 'derived', 'env'], true)
            && !array_key_exists('sub_keys', $override);
        if (!$excluded) {
            throw new \RuntimeException(
                "wprism: $where cannot replace an exact manifest scalar reference intersection with authored site policy; "
                    . 'exclude the whole option or retain its manifest value contract'
            );
        }
    }

    /** @param callable(int,string):?string $uuidFor @param callable(int,string):bool $physicalTuple */
    public static function capture(
        mixed $value,
        array $rule,
        callable $uuidFor,
        string $where,
        callable $physicalTuple,
        bool $allowUnmappedProjection = false
    ): string|int|null {
        $kinds = self::kinds($rule, $where);
        $id = self::local_id($value);
        if ($id === null) {
            self::refuse($where, 'capture found a noncanonical local integer');
        }
        if ($id === 0) {
            return 0;
        }
        if (!$physicalTuple($id, $rule[self::TAXONOMY_FIELD])) {
            self::refuse($where, 'capture found no exact physical term-coordinate tuple in the declared taxonomy');
        }
        $bindings = [];
        foreach ($kinds as $kind) {
            $bindings[] = $uuidFor($id, $kind);
        }
        // Read-only lifecycle observation can encounter a hook-created term
        // before state apply establishes ANY identity. This is the existing
        // missing-ref projection, never a publication or partial-map fallback.
        if ($allowUnmappedProjection && array_filter($bindings, static fn($uuid): bool => $uuid !== null) === []) {
            return null;
        }
        $uuid = null;
        foreach ($bindings as $candidate) {
            if (!is_string($candidate)
                || preg_match('/^' . self::UUID_PATTERN . '$/D', $candidate) !== 1
                || ($uuid !== null && $candidate !== $uuid)) {
                self::refuse($where, 'capture found missing or disagreeing local-keyspace identities');
            }
            $uuid = $candidate;
        }
        return (string) IdentityTokenCodec::encode($kinds[0], $uuid);
    }

    /** @param callable(string,string):?int $idFor @param callable(int,string):bool $physicalTuple */
    public static function apply(mixed $value, array $rule, callable $idFor, string $where, callable $physicalTuple): int|string {
        $kinds = self::kinds($rule, $where);
        if ($value === 0) {
            return ($rule['cast'] ?? null) === 'string' ? '0' : 0;
        }
        if (!is_string($value)
            || preg_match('/^\{\{([a-z][a-z0-9_]{0,15}):(' . self::UUID_PATTERN . ')\}\}$/D', $value, $match) !== 1
            || $match[1] !== $kinds[0]) {
            self::refuse($where, 'apply requires the declared primary canonical reference token');
        }
        $id = null;
        foreach ($kinds as $kind) {
            $candidate = $idFor($match[2], $kind);
            if (!is_int($candidate) || $candidate <= 0 || ($id !== null && $candidate !== $id)) {
                self::refuse($where, 'apply found missing or disagreeing local-keyspace integers');
            }
            $id = $candidate;
        }
        if (!$physicalTuple((int) $id, $rule[self::TAXONOMY_FIELD])) {
            self::refuse($where, 'apply found no exact physical term-coordinate tuple in the declared taxonomy');
        }
        return ($rule['cast'] ?? null) === 'string' ? (string) $id : (int) $id;
    }

    private static function local_id(mixed $value): ?int {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $id = (int) $value;
        return $id >= 0 && (string) $id === $value ? $id : null;
    }

    private static function refuse(string $where, string $detail): never {
        throw new CommandRefusalException(
            'reference_intersection_failed',
            'one authored scalar reference cannot satisfy all declared local keyspaces',
            'reconcile the native keyspace requirements and identity bindings before retrying; a force flag cannot select a different interpretation',
            [],
            "wprism: $where: $detail"
        );
    }
}
