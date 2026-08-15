<?php
declare(strict_types=1);

namespace Duo\Policy;

require_once dirname(__DIR__) . '/Canon.php';

/** Host-safe declaration reader; it never loads WordPress or provider code. */
final class PolicySourceReader {
    public static function bytes(string $path): string {
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException("duo: policy source is absent or unsafe: $path");
        }
        return \Duo\Canon::read_file($path);
    }

    /** @return array<string,mixed> */
    public static function json(string $path): array {
        $value = \Duo\Canon::decode(self::bytes($path));
        if (!is_array($value) || array_is_list($value)) {
            throw new \RuntimeException("duo: policy source must be an object: $path");
        }
        return $value;
    }

    /** @return array<string,mixed> */
    public static function site(string $repo): array {
        return self::json(rtrim($repo, '/') . '/site.duo.json');
    }

    /** @return array<string,array<string,mixed>> */
    public static function manifests(string $manifestDir): array {
        $out = [];
        foreach (glob(rtrim($manifestDir, '/') . '/*.json') ?: [] as $path) {
            if (basename($path) === 'dispositions.json') {
                continue;
            }
            $manifest = self::json($path);
            $name = $manifest['name'] ?? basename($path, '.json');
            if (!is_string($name) || $name === '') {
                throw new \RuntimeException('duo: policy source manifest has no name: ' . $path);
            }
            $out[$name] = $manifest;
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}
