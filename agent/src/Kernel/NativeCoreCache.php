<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/DatabaseQueryIsolation.php';

/** Engine-internal standard-cache provenance and routing; never a provider cache API. */
final class NativeCoreCache {
    private ?object $cache = null;
    private ?string $prefix = null;

    public function __construct(
        private readonly string $group,
        private readonly string $context,
        private readonly string $purpose
    ) {
        if (!in_array($group, ['options', 'posts'], true)) {
            throw new \InvalidArgumentException('wprism: native cache input group is not implemented');
        }
    }

    public static function assert_environment(string $context, string $purpose): void {
        DatabaseQueryIsolation::assert_active($context);
        if (defined('WP_SETUP_CONFIG') || !function_exists('wp_installing') || wp_installing()
            || !function_exists('wp_using_ext_object_cache') || !in_array(wp_using_ext_object_cache(), [null, false], true)
            || !defined('ABSPATH') || !defined('WPINC')) {
            DatabaseQueryIsolation::violation("wprism: $context $purpose require ordinary WordPress with its request-local core cache");
        }
    }

    public function assert_current(): void {
        self::assert_environment($this->context, $this->purpose);
        $cache = $GLOBALS['wp_object_cache'] ?? null;
        $core = rtrim(ABSPATH, '/\\') . '/' . WPINC . '/';
        // WP 7.1 load.php:810-819 returns null for its initially unset flag.
        // Admission is exact null/false plus source provenance, not truthiness.
        if (!is_object($cache) || get_class($cache) !== 'WP_Object_Cache'
            || realpath($core . 'class-wp-object-cache.php') === false
            || (new \ReflectionClass($cache))->getFileName() !== realpath($core . 'class-wp-object-cache.php')) {
            $this->refuse('cannot witness a substituted object cache');
        }
        foreach (['wp_cache_get', 'wp_cache_set', 'wp_cache_add'] as $function) {
            if (!function_exists($function) || realpath($core . 'cache.php') === false
                || (new \ReflectionFunction($function))->getFileName() !== realpath($core . 'cache.php')) {
                $this->refuse('cannot witness substituted cache functions');
            }
        }
        foreach (['cache', 'multisite', 'global_groups', 'blog_prefix'] as $property) {
            if (!$cache->__isset($property)) $this->refuse('found incomplete core cache state');
        }
        // Core get() clones before a caller can reject an object's class
        // (class-wp-object-cache.php:378-379). This public compatibility view
        // is inert only after proving the exact core class above.
        $multisite = $cache->__get('multisite');
        $groups = $cache->__get('global_groups');
        if (!is_bool($multisite) || !is_array($groups)) $this->refuse('found malformed core cache routing');
        $prefix = $multisite && !isset($groups[$this->group]) ? $cache->__get('blog_prefix') : '';
        if (!is_string($prefix) || ($prefix !== '' && preg_match('/^[1-9][0-9]*:$/D', $prefix) !== 1)
            || ($this->cache !== null && ($cache !== $this->cache || $prefix !== $this->prefix))) {
            $this->refuse('found changed core cache routing');
        }
        $this->cache = $cache;
        $this->prefix = $prefix;
    }

    /** Internal callers must admit selected values before invoking any native getter. */
    public function entries(): array {
        $this->assert_current();
        $cache = $this->cache->__get('cache');
        if (!is_array($cache) || (array_key_exists($this->group, $cache) && !is_array($cache[$this->group]))) {
            $storage = $this->group === 'options' ? 'option' : 'post';
            $this->refuse("found malformed local $storage-cache storage");
        }
        return $cache[$this->group] ?? [];
    }

    public function key(string|int $id): string {
        $this->assert_current();
        return $this->prefix . $id;
    }

    private function refuse(string $reason): never {
        DatabaseQueryIsolation::violation("wprism: {$this->context} {$this->purpose} $reason");
    }
}
