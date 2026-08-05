<?php
namespace Duo;

final class Uuid {
    /** RFC 9562 UUIDv7: 48-bit ms timestamp + version + 74 random bits. */
    public static function v7(): string {
        $ms   = (int) floor(microtime(true) * 1000);
        $time = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT);
        $rand = bin2hex(random_bytes(10));
        $hex  = $time
            . '7' . substr($rand, 0, 3)
            . dechex((hexdec($rand[3]) & 0x3) | 0x8) . substr($rand, 4, 15);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
            substr($hex, 16, 4), substr($hex, 20, 12)
        );
    }

    public static function is(string $s): bool {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $s
        );
    }
}
