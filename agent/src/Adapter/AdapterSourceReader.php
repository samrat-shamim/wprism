<?php
declare(strict_types=1);

namespace Duo\Adapter;

require_once dirname(__DIR__) . '/Canon.php';

/** Pure canonical declaration reader used by source discovery and tooling. */
final class AdapterSourceReader {
    public static function bytes(string $path): string {
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException("duo: adapter declaration is absent or unsafe: $path");
        }
        return \Duo\Canon::read_file($path);
    }

    /** @return array<string,mixed> */
    public static function json(string $path): array {
        $value = \Duo\Canon::decode(self::bytes($path));
        if (!is_array($value) || array_is_list($value)) {
            throw new \RuntimeException("duo: adapter declaration must be an object: $path");
        }
        return $value;
    }
}
