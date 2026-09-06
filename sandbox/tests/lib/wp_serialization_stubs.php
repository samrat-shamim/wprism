<?php
/**
 * Exact WordPress serialization primitives shared by lightweight offline
 * suites. Keeping these separate from WpStore lets a boundary test exercise
 * raw option bytes without importing unrelated WordPress functions whose mere
 * presence can change provider negotiation.
 */
declare(strict_types=1);

if (!function_exists('is_serialized')) {
    /**
     * Port of WordPress's is_serialized(). A scalar that merely looks like a
     * serialized value is still treated as one by Core, which is the behavior
     * the double-serialization compatibility path must reproduce.
     */
    function is_serialized(mixed $data, bool $strict = true): bool {
        if (!is_string($data)) {
            return false;
        }
        $data = trim($data);
        if ($data === 'N;') {
            return true;
        }
        if (strlen($data) < 4 || $data[1] !== ':') {
            return false;
        }
        if ($strict) {
            $lastc = substr($data, -1);
            if ($lastc !== ';' && $lastc !== '}') {
                return false;
            }
        } else {
            $semicolon = strpos($data, ';');
            $brace = strpos($data, '}');
            if ($semicolon === false && $brace === false) {
                return false;
            }
            if ($semicolon !== false && $semicolon < 3) {
                return false;
            }
            if ($brace !== false && $brace < 4) {
                return false;
            }
        }
        $token = $data[0];
        switch ($token) {
            case 's':
                if ($strict) {
                    if (substr($data, -2, 1) !== '"') {
                        return false;
                    }
                } elseif (!str_contains($data, '"')) {
                    return false;
                }
                // no break -- 's' shares the length check below
            case 'a':
            case 'O':
            case 'E':
                return (bool) preg_match("/^{$token}:[0-9]+:/s", $data);
            case 'b':
            case 'i':
            case 'd':
                $end = $strict ? '$' : '';
                return (bool) preg_match("/^{$token}:[0-9.E+-]+;$end/", $data);
        }
        return false;
    }
}

if (!function_exists('maybe_serialize')) {
    /**
     * WordPress leaves scalar null/false alone but double-serializes strings
     * that already look serialized so get_option() returns the original type.
     */
    function maybe_serialize(mixed $data): mixed {
        if (is_array($data) || is_object($data)) {
            return serialize($data);
        }
        if (is_serialized($data, false)) {
            return serialize($data);
        }
        return $data;
    }
}

if (!function_exists('maybe_unserialize')) {
    /** Unserializes only when is_serialized() says so; errors yield the input. */
    function maybe_unserialize(mixed $data): mixed {
        if (is_serialized($data)) {
            $result = @unserialize((string) $data, ['allowed_classes' => false]);
            return $result === false && trim((string) $data) !== 'b:0;' ? $data : $result;
        }
        return $data;
    }
}
