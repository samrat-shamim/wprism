<?php
declare(strict_types=1);

namespace Duo;

require_once __DIR__ . '/TargetRuntimeInspector.php';

/** WordPress adapter supplied at the live agent composition boundary. */
final class WordPressTargetRuntimeInspector implements TargetRuntimeInspectionPort {
    public function available(): bool {
        return defined('ABSPATH')
            && defined('WP_PLUGIN_DIR')
            && function_exists('apply_filters')
            && function_exists('get_option');
    }

    public function plugin(string $basename): array {
        if (!$this->available()) {
            throw new \RuntimeException('duo: target runtime inspection is unavailable outside WordPress');
        }
        if (!function_exists('validate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $installed = !is_wp_error(validate_plugin($basename));
        $all = $installed ? get_plugins() : [];
        $active = get_option('active_plugins');
        $active = is_array($active) ? array_values(array_map('strval', $active)) : [];
        return [
            'installed' => $installed,
            'active' => in_array($basename, $active, true),
            'version' => (string) ($all[$basename]['Version'] ?? ''),
        ];
    }

    public function wordpressVersion(): string {
        return function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '';
    }

    public function phpVersion(): string {
        return PHP_VERSION;
    }
}
