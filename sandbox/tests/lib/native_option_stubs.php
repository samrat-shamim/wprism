<?php
declare(strict_types=1);

/**
 * Opt-in native get_option ordering over shared hooks/cache/wpdb. The ordinary
 * wp_stubs getter intentionally bypasses these surfaces. This is deterministic
 * mechanism evidence; actual core execution remains a separate live gate.
 */
define('ABSPATH', dirname(__DIR__) . '/fixtures/native-option-core/');
define('WPINC', 'wp-includes');
require_once ABSPATH . WPINC . '/class-wp-object-cache.php';
require_once ABSPATH . WPINC . '/cache.php';
require_once __DIR__ . '/wp_stubs.php';

final class WP_Hook {
    public array $callbacks = [];
}

function wp_using_ext_object_cache(): bool {
    return ($GLOBALS['native_option_external_cache'] ?? false) === true;
}

function wp_installing(): bool {
    return ($GLOBALS['native_option_installing'] ?? false) === true;
}

function wp_load_alloptions(): array {
    global $wpdb;
    $all = apply_filters('pre_wp_load_alloptions', null, false);
    if (is_array($all)) return $all;
    $all = wp_cache_get('alloptions', 'options');
    if (!$all) {
        apply_filters('wp_autoload_values_to_autoload', ['yes', 'on', 'auto-on', 'auto']);
        $all = [];
        foreach ($wpdb->get_results("SELECT option_name, option_value FROM $wpdb->options", ARRAY_A) as $row) {
            $all[$row['option_name']] = $row['option_value'];
        }
        $all = apply_filters('pre_cache_alloptions', $all);
        wp_cache_add('alloptions', $all, 'options');
    }
    return apply_filters('alloptions', $all);
}

function get_option(string $name, mixed $default = false): mixed {
    global $wpdb;
    $pre = apply_filters('pre_option_' . $name, false, $name, $default);
    $pre = apply_filters('pre_option', $pre, $name, $default);
    if ($pre !== false) return $pre;
    if (defined('WP_SETUP_CONFIG')) return false;
    $passed = func_num_args() > 1;
    $all = wp_load_alloptions();
    if (isset($all[$name])) {
        $value = $all[$name];
    } else {
        $not = wp_cache_get('notoptions', 'options');
        if (!is_array($not)) {
            $not = [];
            wp_cache_set('notoptions', $not, 'options');
        }
        if (isset($not[$name])) return apply_filters('default_option_' . $name, $default, $name, $passed);
        $value = wp_cache_get($name, 'options');
        if ($value === false) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", $name));
            if (is_object($row)) {
                $value = $row->option_value;
                wp_cache_add($name, $value, 'options');
            } else {
                $not[$name] = true;
                wp_cache_set('notoptions', $not, 'options');
                return apply_filters('default_option_' . $name, $default, $name, $passed);
            }
        }
    }
    return apply_filters('option_' . $name, maybe_unserialize($value), $name);
}
