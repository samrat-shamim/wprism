<?php
/**
 * Plugin Name: Duo Commerce Replacement
 * Description: A distinct implementation replacing the in-house ecommerce extension in the live grind.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 */

defined('ABSPATH') || exit;

function duo_commerce_replacement_read_option(string $name, mixed $default = false): mixed {
    global $wpdb;
    $wpdb->last_error = '';
    $value = get_option($name, $default);
    if ((string) ($wpdb->last_error ?? '') !== '') {
        throw new RuntimeException("Duo Commerce Replacement option read failed: $name");
    }
    return $value;
}

function duo_commerce_replacement_update_option(string $name, mixed $value): void {
    global $wpdb;
    $wpdb->last_error = '';
    update_option($name, $value, false);
    if ((string) ($wpdb->last_error ?? '') !== '') {
        throw new RuntimeException("Duo Commerce Replacement option write failed: $name");
    }
    $missing = new stdClass();
    if (duo_commerce_replacement_read_option($name, $missing) !== $value) {
        throw new RuntimeException("Duo Commerce Replacement option verification failed: $name");
    }
}

function duo_commerce_replacement_trace(string $event): void {
    $trace = duo_commerce_replacement_read_option('duo_commerce_extension_trace', []);
    $trace = is_array($trace) ? $trace : [];
    $trace[] = $event;
    duo_commerce_replacement_update_option('duo_commerce_extension_trace', $trace);
}

function duo_commerce_replacement_assert_contract(): void {
    global $wpdb;
    if (function_exists('duo_commerce_extension_table')) {
        throw new RuntimeException('Duo Commerce Replacement inherited the outgoing implementation instead of a fresh activation process');
    }
    if (!class_exists('WooCommerce')) {
        throw new RuntimeException('Duo Commerce Replacement requires WooCommerce to be active first');
    }
    if (!defined('WP_PLUGIN_DIR') || !is_dir(WP_PLUGIN_DIR . '/duo-commerce-extension')) {
        throw new RuntimeException('Duo Commerce Replacement expected the outgoing code root to remain available until finalization');
    }
    $settings = duo_commerce_replacement_read_option('duo_commerce_extension_settings', null);
    if (!is_array($settings)
        || count($settings) !== 3
        || ($settings['schema'] ?? null) !== 2
        || ($settings['channel'] ?? null) !== 'retail'
        || ($settings['catalog_mode'] ?? null) !== 'managed') {
        throw new RuntimeException('Duo Commerce Replacement cannot consume the reviewed v2 authored settings');
    }
    if ((int) duo_commerce_replacement_read_option('duo_commerce_extension_schema', 0) !== 2) {
        throw new RuntimeException('Duo Commerce Replacement requires the reviewed v2 runtime schema');
    }
    if ((string) duo_commerce_replacement_read_option('duo_commerce_extension_gateway_secret', '') === '') {
        throw new RuntimeException('Duo Commerce Replacement requires the environment-owned gateway secret');
    }
    $table = $wpdb->prefix . 'duo_commerce_extension_events';
    $wpdb->last_error = '';
    $columns = $wpdb->get_col("SHOW COLUMNS FROM `$table`", 0);
    if (!is_array($columns)
        || (string) ($wpdb->last_error ?? '') !== ''
        || array_values($columns) !== ['id', 'label', 'context', 'created_at']) {
        throw new RuntimeException('Duo Commerce Replacement cannot consume the reviewed v2 runtime table');
    }
}

register_activation_hook(__FILE__, static function (): void {
    duo_commerce_replacement_assert_contract();
    duo_commerce_replacement_trace('activate:replacement-fixed:fresh=yes:retiring-root=present');
});

register_deactivation_hook(__FILE__, static function (): void {
    duo_commerce_replacement_trace(
        'deactivate:replacement-fixed:woo=' . (class_exists('WooCommerce') ? 'yes' : 'no')
    );
});

add_action('rest_api_init', static function (): void {
    register_rest_route('duo-commerce/v1', '/status', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => static fn(): array => [
            'extension_identity' => 'duo-commerce-replacement',
            'extension_version' => '1.0.0',
            'schema' => (int) duo_commerce_replacement_read_option('duo_commerce_extension_schema', 0),
            'woocommerce' => class_exists('WooCommerce'),
        ],
    ]);
});
