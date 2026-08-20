<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once __DIR__ . '/ControlRefusal.php';

/**
 * Exact dependency-free mirror of agent Canon::encode() for origin evidence.
 *
 * Signed envelopes use compact CanonicalJson. Only hashes whose wire contract
 * explicitly names agent Canon::encode() use this two-space/trailing-LF form.
 */
final class OriginAgentCanon {
    public static function encode(mixed $value): string {
        try {
            $json = json_encode(
                self::normalize($value),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $error) {
            throw new ControlRefusal('value cannot be represented in agent canonical JSON', 0, $error);
        }
        return $json . "\n";
    }

    private static function normalize(mixed $value): mixed {
        if (is_resource($value)) {
            throw new ControlRefusal('agent canonical JSON contains an unsupported value');
        }
        if (is_object($value)) {
            $children = (array) $value;
            ksort($children, SORT_STRING);
            $normalized = new \stdClass();
            foreach ($children as $key => $child) {
                $normalized->{$key} = self::normalize($child);
            }
            return $normalized;
        }
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        foreach ($value as $key => $child) {
            $value[$key] = self::normalize($child);
        }
        if (!$isList) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }
}
