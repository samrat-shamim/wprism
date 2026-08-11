<?php
declare(strict_types=1);

namespace Duo\Recovery;

/**
 * The one canonical JSON codec shared by the recovery authority and every
 * resource bundle.
 *
 * Recovery records are protocol bytes, not incidental PHP serialization.  A
 * single implementation keeps key ordering, list ordering, UTF-8 handling,
 * and the required trailing newline identical across all bundles.
 */
final class CanonicalJson {
    /** @return array<string,mixed> */
    public static function read(string $path, string $label, string $scope): array {
        self::assertAbsoluteRegularFile($path, $label, $scope);
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            throw new \RuntimeException("$scope: could not read $label");
        }
        return self::decode($raw, $label, $scope);
    }

    /** @return array<string,mixed> */
    public static function decode(string $raw, string $label, string $scope): array {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException("$scope: $label is malformed JSON", 0, $e);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException("$scope: $label must be a canonical JSON object");
        }
        try {
            $canonical = self::encode($decoded, $scope);
        } catch (\Throwable $e) {
            throw new \RuntimeException("$scope: $label contains unsupported canonical JSON", 0, $e);
        }
        if ($canonical . "\n" !== $raw) {
            throw new \RuntimeException("$scope: $label must be canonical JSON with one trailing newline");
        }
        return $decoded;
    }

    /** @param array<string,mixed> $value */
    public static function encode(array $value, string $scope = 'duo recovery'): string {
        try {
            return (string) json_encode(
                self::normalize($value, $scope),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException("$scope: canonical JSON encoding failed", 0, $e);
        }
    }

    /** @return mixed */
    private static function normalize(mixed $value, string $scope): mixed {
        if (!is_array($value)) {
            if (is_float($value) || is_resource($value) || is_object($value)) {
                throw new \RuntimeException("$scope: canonical JSON contains unsupported value");
            }
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => self::normalize($item, $scope), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \RuntimeException("$scope: canonical JSON object keys must be strings");
            }
            $value[$key] = self::normalize($item, $scope);
        }
        return $value;
    }

    private static function assertAbsoluteRegularFile(string $path, string $label, string $scope): void {
        if ($path === '' || $path[0] !== '/' || is_link($path) || !is_file($path)) {
            throw new \RuntimeException("$scope: $label must be an absolute regular file");
        }
    }
}
