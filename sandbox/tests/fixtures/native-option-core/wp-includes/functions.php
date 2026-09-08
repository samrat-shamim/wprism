<?php
declare(strict_types=1);

function _config_wp_home(string $url): string {
    return defined('WP_HOME') ? untrailingslashit(WP_HOME) : $url;
}

function add_query_arg(mixed ...$args): string {
    if (is_array($args[0])) [$values, $url] = $args;
    else { [$key, $value, $url] = $args; $values = [$key => $value]; }
    wp_parse_str('', $parsed);
    $values = array_merge($parsed, $values);
    $parts = [];
    foreach ($values as $key => $value) $parts[] = $key . '=' . $value;
    return $url . '?' . implode('&', $parts);
}

function wp_parse_args(array $args, array $defaults = []): array { return array_merge($defaults, $args); }
if (!function_exists('wp_filter_object_list')) {
    function wp_filter_object_list(array $objects, array $args = [], string $operator = 'and', string|false $field = false): array {
        $selected = array_filter($objects, static function (object $object) use ($args): bool {
            foreach ($args as $key => $value) if ($object->$key !== $value) return false;
            return true;
        });
        return $field === false ? $selected : array_map(static fn(object $object): mixed => $object->$field, $selected);
    }
}
