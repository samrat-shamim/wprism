<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once __DIR__ . '/ControlRefusal.php';

/**
 * Canonical JSON used by the signed cloud-control and authority-store formats.
 *
 * The protocol is intentionally a closed JSON subset: object keys sort by
 * bytes, array order is significant, and floating-point values are refused.
 * That leaves one byte representation for every accepted value and prevents a
 * signature or replay hash from depending on decoder-specific number rules.
 */
final class CanonicalJson {
    /** @return array<string,mixed> */
    public static function decodeObject(string $bytes, int $limit = 1048576): array {
        if ($bytes === '' || strlen($bytes) > $limit) {
            throw new ControlRefusal('canonical JSON is empty or exceeds its byte limit');
        }
        try {
            $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new ControlRefusal('canonical JSON could not be decoded', 0, $error);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new ControlRefusal('canonical JSON root must be an object');
        }
        if (self::encode($value) . "\n" !== $bytes) {
            throw new ControlRefusal('JSON input is not in canonical form');
        }
        return $value;
    }

    public static function encode(mixed $value): string {
        try {
            return json_encode(
                self::canonicalize($value),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (ControlRefusal $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new ControlRefusal('value cannot be represented as canonical JSON', 0, $error);
        }
    }

    private static function canonicalize(mixed $value): mixed {
        if (is_float($value) || is_object($value) || is_resource($value)) {
            throw new ControlRefusal('canonical JSON contains an unsupported value type');
        }
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            foreach (array_keys($value) as $key) {
                if (!is_string($key)) {
                    throw new ControlRefusal('canonical JSON object keys must be strings');
                }
            }
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalize($child);
        }
        return $value;
    }
}
