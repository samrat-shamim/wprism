<?php
declare(strict_types=1);

namespace WPrism;

/** Closed canonical name for a target-local password belonging to one post. */
final class PostPasswordBinding {
    public const PREFIX = 'post_password:';

    public static function name(string $uuid): string {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid) !== 1) {
            throw new \RuntimeException('wprism: post-password binding requires a canonical UUID');
        }

        return self::PREFIX . $uuid;
    }

    public static function uuid(string $name): ?string {
        if (!str_starts_with($name, self::PREFIX)) {
            return null;
        }
        $uuid = substr($name, strlen(self::PREFIX));
        try {
            return self::name($uuid) === $name ? $uuid : null;
        } catch (\RuntimeException) {
            return null;
        }
    }
}
