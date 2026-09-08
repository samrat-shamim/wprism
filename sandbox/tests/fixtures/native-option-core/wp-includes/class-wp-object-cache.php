<?php
declare(strict_types=1);

/** Test-only core cache shape; WpStore owns storage, not a second fixture store. */
class WP_Object_Cache {
    private bool $multisite = false;
    private string $blog_prefix = '';
    private array $global_groups = [];

    public function __get(string $name): mixed {
        return $name === 'cache' ? wprism_wp_store()->cache : $this->$name;
    }

    public function __set(string $name, mixed $value): void {
        if ($name === 'cache') wprism_wp_store()->cache = $value;
        else $this->$name = $value;
    }

    public function __isset(string $name): bool {
        return $name === 'cache' || isset($this->$name);
    }

    private function key(string $key, string $group): string {
        return $this->multisite && !isset($this->global_groups[$group]) ? $this->blog_prefix . $key : $key;
    }

    public function get(string $key, string $group = 'default', bool $force = false, mixed &$found = null): mixed {
        $key = $this->key($key, $group);
        $store = wprism_wp_store();
        $found = array_key_exists($key, $store->cache[$group] ?? []);
        $store->cacheEvents[] = ['op' => 'get', 'group' => $group, 'key' => $key];
        $value = $found ? $store->cache[$group][$key] : false;
        return is_object($value) ? clone $value : $value;
    }

    public function set(string $key, mixed $value, string $group = 'default'): bool {
        $key = $this->key($key, $group);
        $store = wprism_wp_store();
        $store->cacheEvents[] = ['op' => 'set', 'group' => $group, 'key' => $key];
        $store->cache[$group][$key] = is_object($value) ? clone $value : $value;
        return true;
    }

    public function add(string $key, mixed $value, string $group = 'default'): bool {
        $physical = $this->key($key, $group);
        if (array_key_exists($physical, wprism_wp_store()->cache[$group] ?? [])) return false;
        return $this->set($key, $value, $group);
    }
}
