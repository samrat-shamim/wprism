<?php
namespace WPrism;

/**
 * Fail-closed codecs for manifest-declared deletion guards.
 *
 * A deletion guard reads target-controlled metadata only to decide whether a
 * still-live reference blocks an otherwise requested delete. That read is an
 * authority boundary: a permissive scalar cast, unserialize() with classes
 * enabled, or accepting a valid serialized prefix would turn malformed target
 * state into a guessed "safe" answer. This collaborator owns the two pure
 * representations the guard accepts:
 *
 * - live scalar/list values that encode exact positive local ids; and
 * - desired canonical scalar/list values that encode exact identity tokens.
 *
 * `Apply` retains the database query, lock, witness, and forced-warning
 * orchestration. Keeping this codec free of Policy, wpdb, and Apply makes the
 * value boundary directly characterizable without manufacturing a plan or a
 * target transaction, while preserving the evaluator's existing refusal
 * semantics exactly.
 */
final class DeleteGuardValueCodec {
    /** @return list<int>|null null means the value shape is unsafe/unknown. */
    public static function meta_value_ids($raw, array $guard): ?array {
        $ref = (string) ($guard['ref'] ?? '');
        $cast = (string) ($guard['cast'] ?? '');
        if ($cast === 'csv') {
            if (!is_string($raw)) {
                return null;
            }
            if ($raw === '') {
                return [];
            }
            $ids = [];
            foreach (explode(',', $raw) as $member) {
                $id = self::strict_positive_meta_id($member);
                if ($id === null) {
                    return null;
                }
                $ids[] = $id;
            }
            return $ids;
        }
        $decoded = $raw;
        if (is_string($raw)) {
            // Do not use WordPress' maybe_unserialize() here. Its legacy
            // helper delegates to unserialize() without allowed_classes and
            // would instantiate an object supplied by the database. This
            // guard only needs scalar/list values, so decoding with objects
            // disabled is both sufficient and a safer boundary for a
            // target-controlled metadata value. PHP's unserialize() accepts
            // a valid value followed by arbitrary trailing bytes; require a
            // byte-for-byte serialize() round trip so the guard cannot inspect
            // only a prefix of an attacker-controlled payload. This accepts
            // all ordinary Woo forms produced by serialize() (arrays,
            // integer/string scalars, null, and false) while rejecting
            // noncanonical/trailing payloads without instantiating objects.
            [$serialized, $unserialized] = self::strict_unserialize($raw);
            if ($serialized) {
                $decoded = $unserialized;
            }
        }
        if (str_ends_with($ref, '[]')) {
            if (!is_array($decoded) || !array_is_list($decoded)) {
                return null;
            }
            $ids = [];
            foreach ($decoded as $member) {
                $id = self::strict_positive_meta_id($member);
                if ($id === null) {
                    return null;
                }
                $ids[] = $id;
            }
            return $ids;
        }
        if ($decoded === null || $decoded === '') {
            return [];
        }
        if (is_array($decoded) || is_object($decoded)) {
            return null;
        }
        $id = self::strict_positive_meta_id($decoded);
        return $id === null ? null : [$id];
    }

    /** @return bool|null null means the declared scalar/list shape is unsafe. */
    public static function canonical_meta_ref_contains_uuid(
        $value,
        string $ref,
        string $uuid,
        bool $present = true
    ): ?bool {
        if (!$present) {
            return false;
        }
        if ($value === null) {
            return null;
        }
        $kind = rtrim($ref, '[]');
        $token = '{{' . $kind . ':' . $uuid . '}}';
        // Match the same RFC UUID layout/version/variant that Uuid::is()
        // and Snapshot's canonical token grammar accept. A merely
        // 36-character hex/hyphen string is not a valid identity token and
        // must not be interpreted as an explicit desired removal.
        $tokenPattern = '/^\\{\\{' . preg_quote($kind, '/')
            . ':[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\}\\}$/';
        if (str_ends_with($ref, '[]')) {
            if (!is_array($value) || !array_is_list($value)) {
                return null;
            }
            $contains = false;
            foreach ($value as $member) {
                if (!is_string($member) || preg_match($tokenPattern, $member) !== 1) {
                    return null;
                }
                $contains = $contains || hash_equals($token, $member);
            }
            return $contains;
        }
        if (!is_string($value) || preg_match($tokenPattern, $value) !== 1) {
            return null;
        }
        return hash_equals($token, $value);
    }

    /**
     * @return array{0:bool,1:mixed} whether $raw is one canonical PHP
     * serialization value and its safely-decoded value.
     */
    private static function strict_unserialize(string $raw): array {
        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        // `false` is a valid serialized value only when its exact canonical
        // spelling is present; every other false result is malformed input.
        if ($decoded === false && $raw !== 'b:0;') {
            return [false, null];
        }
        // allowed_classes=false turns supplied objects into an incomplete
        // object marker. Reject the object boundary explicitly before any
        // round trip so no object-shaped value is treated as a scalar/list.
        if (is_object($decoded)) {
            return [false, null];
        }
        try {
            $roundTrip = serialize($decoded);
        } catch (\Throwable $e) {
            return [false, null];
        }
        if ($roundTrip !== $raw) {
            return [false, null];
        }
        return [true, $decoded];
    }

    /** @return int|null null means the value is not an exact positive id. */
    private static function strict_positive_meta_id($value): ?int {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^0*[1-9][0-9]*$/D', $value)) {
            return null;
        }
        $digits = ltrim($value, '0');
        $id = (int) $digits;
        // Reject overflow rather than letting a huge decimal string saturate
        // to PHP_INT_MAX and accidentally match a real local identity.
        return $id > 0 && (string) $id === $digits ? $id : null;
    }
}
