<?php
/**
 * Plugin Name: Duo Code Half Probe
 * Description: Controlled v1 fixture for descriptor-bound code materialization.
 * Version: 1.0.0
 */

function duo_code_half_probe_trace_v1(string $event): void {
    $trace = get_option('duo_code_half_probe_trace', []);
    $trace = is_array($trace) ? $trace : [];
    $trace[] = $event;
    update_option('duo_code_half_probe_trace', $trace, false);
}

register_activation_hook(__FILE__, static function (): void {
    global $wpdb;
    $table = $wpdb->prefix . 'duo_code_half_probe_rows';
    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS `$table` ("
        . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
        . 'label VARCHAR(191) NOT NULL, '
        . 'PRIMARY KEY (id)'
        . ') ' . $wpdb->get_charset_collate()
    );
    if (get_option('duo_code_half_probe_settings', null) === null) {
        add_option('duo_code_half_probe_settings', 'blue', '', false);
    }
    update_option('duo_code_half_probe_schema', 1, false);
    update_option(
        'duo_code_half_probe_activations',
        (int) get_option('duo_code_half_probe_activations', 0) + 1,
        false
    );
    duo_code_half_probe_trace_v1('activate-v1');
});

register_deactivation_hook(__FILE__, static function (): void {
    update_option(
        'duo_code_half_probe_deactivations',
        (int) get_option('duo_code_half_probe_deactivations', 0) + 1,
        false
    );
    duo_code_half_probe_trace_v1('deactivate-v1');
});

add_action('rest_api_init', static function (): void {
    register_rest_route('duo-code-half/v1', '/status', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => static fn(): array => [
            'plugin_version' => '1.0.0',
            'schema' => (int) get_option('duo_code_half_probe_schema', 0),
        ],
    ]);
});
