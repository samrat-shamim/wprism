<?php
namespace Duo;

/**
 * Transaction-scoped cache invalidation for raw WordPress writes.
 *
 * The in-process object cache is not transactional. Deleting an option key
 * before COMMIT is insufficient because a later read in the same request can
 * repopulate the pre-commit value. A persistent external option cache is even
 * less tractable: a competing request can publish old database bytes after
 * our post-commit delete, and core exposes no generation/CAS fence for the
 * options/alloptions/notoptions groups. Raw transactional option writes must
 * therefore refuse that topology. For WordPress's request-local cache, purge
 * both immediately and after the database outcome; a process crash discards
 * the cache with the process.
 */
final class CacheInvalidationTransaction {
    private static bool $active = false;
    /** @var array<string,true> */
    private static array $optionNames = [];

    public static function begin(): void {
        if (self::$active) {
            throw new \RuntimeException('duo: cache invalidation transaction was already active');
        }
        self::$active = true;
        self::$optionNames = [];
    }

    public static function assert_local_option_cache(string $purpose): void {
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            throw new \RuntimeException(
                "duo: $purpose refuses raw transactional option mutation while a persistent external "
                . 'object cache is active; WordPress exposes no version/CAS fence for options, alloptions, '
                . 'or notoptions'
            );
        }
    }

    public static function queue_option(string $name, string $purpose): void {
        if (!self::$active) {
            throw new \RuntimeException("duo: $purpose attempted cache invalidation outside the authored transaction");
        }
        self::assert_local_option_cache($purpose);
        self::$optionNames[$name] = true;
        self::purge_option($name);
    }

    /** Run only after COMMIT or ROLLBACK has returned. */
    public static function finish(): void {
        if (!self::$active) {
            return;
        }
        foreach (array_keys(self::$optionNames) as $name) {
            self::purge_option($name);
        }
        self::$optionNames = [];
    }

    public static function end(): void {
        self::$optionNames = [];
        self::$active = false;
    }

    private static function purge_option(string $name): void {
        if (!function_exists('wp_cache_delete')) {
            return;
        }
        wp_cache_delete($name, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }
}
