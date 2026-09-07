<?php
declare(strict_types=1);

// Source-separated fixture functions exercise the production provenance check.
function wp_cache_get(string|int $key, string $group = '', bool $force = false, mixed &$found = null): mixed {
    return $GLOBALS['wp_object_cache']->get((string) $key, $group === '' ? 'default' : $group, $force, $found);
}

function wp_cache_set(string|int $key, mixed $value, string $group = '', int $expire = 0): bool {
    return $GLOBALS['wp_object_cache']->set((string) $key, $value, $group === '' ? 'default' : $group);
}

function wp_cache_add(string|int $key, mixed $value, string $group = '', int $expire = 0): bool {
    return $GLOBALS['wp_object_cache']->add((string) $key, $value, $group === '' ? 'default' : $group);
}
