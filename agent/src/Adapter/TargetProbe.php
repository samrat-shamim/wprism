<?php
declare(strict_types=1);

namespace WPrism;

// No \WPrism dependency to require: every read below is a WordPress global,
// function, or constant, which is exactly what makes this the one place a
// live-target fact enters the adapter cluster.

/**
 * Live facts about the target this agent is running on.
 *
 * Read-only and WordPress-only: outside a loaded WordPress there is no target,
 * and probe_target() answers null rather than inventing defaults — a capability
 * evaluated against a guessed runtime is the silent pass this project refuses.
 * Callers that need the versions of an installed plugin ask here too, so the
 * "what is actually installed" question has one implementation rather than one
 * per reporting surface.
 */
final class TargetProbe {
    /** Collect only facts needed to decide the supported target boundary. */
    public static function probe_target(): ?array {
        if (!function_exists('get_bloginfo')) {
            return null;
        }
        global $wpdb;
        $databaseServer = is_object($wpdb) && method_exists($wpdb, 'get_var')
            ? (string) $wpdb->get_var('SELECT VERSION()')
            : '';
        $plugins = [];
        $themes = [];
        return [
            'wordpress' => (string) get_bloginfo('version'),
            'php' => PHP_VERSION,
            'database' => [
                'client' => is_object($wpdb) && method_exists($wpdb, 'db_version') ? (string) $wpdb->db_version() : '',
                'server' => $databaseServer,
                'engine' => stripos($databaseServer, 'mariadb') !== false ? 'MariaDB' : 'unknown',
            ],
            'multisite' => function_exists('is_multisite') ? (bool) is_multisite() : false,
            'active_plugins' => function_exists('get_option')
                ? array_values((array) get_option('active_plugins', []))
                : [],
            'active_theme' => function_exists('get_option')
                ? ['template' => (string) get_option('template'), 'stylesheet' => (string) get_option('stylesheet')]
                : ['template' => '', 'stylesheet' => ''],
            'plugins' => $plugins,
            'themes' => $themes,
        ];
    }

    /**
     * The version WordPress reports for one installed plugin file, or null when
     * the plugin is absent or its header is unreadable. Null is a distinct
     * answer from "outside the supported range" and the callers keep it so.
     */
    public static function installed_plugin_version(string $plugin): ?string {
        if (!defined('WP_PLUGIN_DIR')) {
            return null;
        }
        $file = rtrim(WP_PLUGIN_DIR, '/') . '/' . ltrim($plugin, '/');
        if (!is_file($file)) {
            return null;
        }
        if (!function_exists('get_plugin_data') && defined('ABSPATH')) {
            $include = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_file($include)) {
                require_once $include;
            }
        }
        if (!function_exists('get_plugin_data')) {
            return null;
        }
        $data = get_plugin_data($file, false, false);
        $version = is_array($data) ? (string) ($data['Version'] ?? '') : '';
        return $version !== '' ? $version : null;
    }
}
