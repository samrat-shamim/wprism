<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once __DIR__ . '/CanonicalJson.php';

/** Verifies Docker's effective inline workload security policy readback. */
final class WorkloadSecurityInspection {
    private const PROFILE_LIMIT = 1048576;

    /** @param array<string,mixed> $inspection */
    public static function matches(array $inspection, string $reviewedSeccompProfile): bool {
        $host = $inspection['HostConfig'] ?? null;
        $options = is_array($host) ? ($host['SecurityOpt'] ?? null) : null;
        if (($inspection['AppArmorProfile'] ?? null) !== 'docker-default'
            || !is_array($options) || !array_is_list($options) || count($options) !== 2
            || !in_array('no-new-privileges=true', $options, true)) {
            return false;
        }

        $inline = null;
        foreach ($options as $option) {
            if (!is_string($option)) {
                return false;
            }
            if (str_starts_with($option, 'seccomp=')) {
                if ($inline !== null) {
                    return false;
                }
                $inline = substr($option, strlen('seccomp='));
            }
        }
        if (!is_string($inline)) {
            return false;
        }

        $expected = self::canonicalObject($reviewedSeccompProfile);
        $actual = self::canonicalObject($inline);
        return is_string($expected) && is_string($actual) && hash_equals($expected, $actual);
    }

    private static function canonicalObject(string $bytes): ?string {
        if ($bytes === '' || strlen($bytes) > self::PROFILE_LIMIT) {
            return null;
        }
        try {
            $value = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($value) || array_is_list($value)) {
                return null;
            }
            return CanonicalJson::encode($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
