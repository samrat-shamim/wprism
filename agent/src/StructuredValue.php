<?php
namespace Duo;

/**
 * Wire-shape codec shared by every json_refs/key_refs consumer.
 *
 * WordPress normally hands meta and option values to Duo as native arrays
 * after maybe_unserialize(). Some plugins instead store JSON text and opt in
 * with `json_encoded`. Reference traversal must see the same native shape in
 * both cases, while apply must reconstruct the plugin's original wire shape.
 */
final class StructuredValue {
    public static function decode($value, array $rule, string $context) {
        if (!empty($rule['json_encoded'])) {
            if (!is_string($value)) {
                throw new \RuntimeException(
                    "duo: $context declares json_encoded but its (unserialized) value is not a string"
                );
            }
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException(
                    "duo: $context declares json_refs/key_refs (json_encoded) but its value is not valid JSON: "
                    . json_last_error_msg()
                );
            }
            return $decoded;
        }
        if (!is_array($value)) {
            throw new \RuntimeException(
                "duo: $context declares json_refs/key_refs but its value is neither a JSON-encoded string "
                . '(declare "json_encoded": true) nor an already-structured array'
            );
        }
        return $value;
    }

    public static function encode($value, array $rule, string $context) {
        if (empty($rule['json_encoded'])) {
            return $value;
        }
        $encoded = json_encode($value);
        if ($encoded === false) {
            throw new \RuntimeException(
                "duo: could not re-encode $context json_refs/key_refs structured value: " . json_last_error_msg()
            );
        }
        return $encoded;
    }
}
