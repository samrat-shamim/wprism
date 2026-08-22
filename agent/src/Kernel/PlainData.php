<?php
namespace Duo;

/**
 * Safe boundary for values that may be PHP-serialized in WordPress storage.
 *
 * WordPress' maybe_unserialize() is intentionally not used for target-owned
 * bytes: its legacy unserialize() call permits object construction. This
 * boundary disables classes before decoding, requires the complete trimmed
 * value to round-trip through PHP's serializer, and admits only acyclic,
 * bounded plain PHP data.
 */
final class PlainData {
    public const MAX_DEPTH = 256;

    /**
     * Decode one storage value while preserving WordPress scalar semantics.
     *
     * Non-serialized strings are returned byte-for-byte unchanged. Serialized
     * scalars and arrays are returned as their native PHP values (including
     * false and null). Native values are accepted for callers that already
     * decoded a row, but are still checked to keep this a real plain-data
     * boundary rather than a string-only convenience wrapper.
     */
    public static function decode($raw, string $ctx) {
        if (!is_string($raw)) {
            self::assert($raw, $ctx);
            return $raw;
        }

        $trimmed = trim($raw);
        if (!self::looks_serialized($trimmed)) {
            return $raw;
        }

        // The option is load-bearing: a target-controlled object must become
        // an incomplete marker, never a live instance whose __wakeup() can
        // run before our plain-data checks.
        $decoded = @unserialize($trimmed, ['allowed_classes' => false]);
        if ($decoded === false && $trimmed !== 'b:0;') {
            throw new \RuntimeException(
                "duo: $ctx contains malformed or noncanonical PHP-serialized data"
            );
        }
        if (is_object($decoded)) {
            throw new \RuntimeException(
                "duo: non-plain serialized data (PHP object) in $ctx — refusing to capture it"
            );
        }

        try {
            $roundTrip = serialize($decoded);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "duo: $ctx contains malformed or noncanonical PHP-serialized data",
                0,
                $e
            );
        }
        if ($roundTrip !== $trimmed) {
            // This catches a valid serialized prefix followed by arbitrary
            // bytes, as well as noncanonical serializer spellings. PHP's
            // unserialize() otherwise accepts and silently ignores trailing
            // payloads.
            throw new \RuntimeException(
                "duo: $ctx contains trailing or noncanonical PHP-serialized data"
            );
        }

        self::assert($decoded, $ctx);
        return $decoded;
    }

    /** Decode a value whose storage contract requires canonical serialization. */
    public static function decode_serialized(string $raw, string $ctx) {
        $decoded = self::decode($raw, $ctx);
        try {
            $encoded = serialize($decoded);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "duo: $ctx must be canonical PHP-serialized plain data",
                0,
                $e
            );
        }
        if ($encoded !== trim($raw)) {
            throw new \RuntimeException("duo: $ctx must be canonical PHP-serialized plain data");
        }
        return $decoded;
    }

    /** Assert that a native value contains only bounded, acyclic plain data. */
    public static function assert($value, string $ctx): void {
        self::assert_depth($value, $ctx, 0);
    }

    private static function looks_serialized(string $value): bool {
        $serialized = function_exists('is_serialized')
            ? is_serialized($value, false)
            : false;
        // WordPress' loose probe intentionally misses a few malformed
        // prefixes (notably N; followed by bytes). Treat all serialized-
        // looking prefixes fail-closed, while ordinary strings such as "42"
        // continue to pass through untouched.
        return $serialized || preg_match('/^(?:[aOsidbCE]:|N;)/', $value) === 1;
    }

    private static function assert_depth($value, string $ctx, int $depth): void {
        if (is_object($value)) {
            throw new \RuntimeException(
                "duo: non-plain serialized data (PHP object) in $ctx — needs the verbatim-preservation path (post-v0)"
            );
        }
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException(
                "duo: serialized data in $ctx is nested too deeply; refusing recursive/reference-shaped input"
            );
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            // PHP's serialized R:/r: references can produce a canonical
            // recursive array. ReflectionReference also identifies ordinary
            // shared references, which are not part of the portable plain
            // data contract and must be rejected before recursion.
            if (class_exists('ReflectionReference')
                && \ReflectionReference::fromArrayElement($value, $key) !== null) {
                throw new \RuntimeException(
                    "duo: serialized data in $ctx contains a PHP reference or recursive array; refusing capture"
                );
            }
            self::assert_depth($child, $ctx, $depth + 1);
        }
    }
}
