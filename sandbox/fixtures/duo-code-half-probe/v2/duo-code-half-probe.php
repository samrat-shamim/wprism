<?php
/**
 * Plugin Name: Duo Code Half Probe
 * Description: Controlled v2 fixture proving migration-before-state ordering.
 * Version: 2.0.0
 */

function duo_code_half_probe_trace_v2(string $event): void {
    $trace = get_option('duo_code_half_probe_trace', []);
    $trace = is_array($trace) ? $trace : [];
    $trace[] = $event;
    update_option('duo_code_half_probe_trace', $trace, false);
}

function duo_code_half_probe_install_v2(): void {
    global $wpdb;
    $table = $wpdb->prefix . 'duo_code_half_probe_rows';
    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS `$table` ("
        . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
        . 'label VARCHAR(191) NOT NULL, '
        . 'color VARCHAR(64) NOT NULL DEFAULT \'\', '
        . 'PRIMARY KEY (id)'
        . ') ' . $wpdb->get_charset_collate()
    );
    if (get_option('duo_code_half_probe_settings', null) === null) {
        add_option(
            'duo_code_half_probe_settings',
            ['schema' => 2, 'color' => 'blue'],
            '',
            false
        );
    }
    update_option('duo_code_half_probe_schema', 2, false);
}

/**
 * The upgrade boundary this fixture is designed to expose.  The v2 source
 * must encounter the v1 scalar before Duo's ordinary state apply is allowed
 * to install the canonical v2 object.  If apply ran first, this throws
 * instead of silently accepting a backwards ordering.
 */
function duo_code_half_probe_migrate_v1_to_v2(): void {
    $current = get_option('duo_code_half_probe_settings', null);
    if (!is_string($current)) {
        throw new RuntimeException(
            'Duo code-half probe v2 expected the v1 scalar before state apply; migration ordering was violated'
        );
    }
    global $wpdb;
    $table = $wpdb->prefix . 'duo_code_half_probe_rows';
    $column = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS "
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $table,
            'color'
        )
    );
    if ($column === null) {
        $wpdb->query(
            "ALTER TABLE `$table` "
            . "ADD COLUMN color VARCHAR(64) NOT NULL DEFAULT '' AFTER label"
        );
    }
    update_option(
        'duo_code_half_probe_settings',
        ['schema' => 2, 'color' => $current],
        false
    );
    update_option('duo_code_half_probe_schema', 2, false);
    duo_code_half_probe_trace_v2('migrate-v1-scalar:' . $current);
}

register_activation_hook(__FILE__, static function (): void {
    // A v1 install can be inactive when its v2 files arrive.  In that
    // variant the activation hook, rather than the active-plugin bootstrap
    // below, owns the same scalar-to-object migration.
    if ((int) get_option('duo_code_half_probe_schema', 0) === 1) {
        duo_code_half_probe_migrate_v1_to_v2();
    }
    duo_code_half_probe_install_v2();
    update_option(
        'duo_code_half_probe_activations',
        (int) get_option('duo_code_half_probe_activations', 0) + 1,
        false
    );
    duo_code_half_probe_trace_v2('activate-v2');
});

register_deactivation_hook(__FILE__, static function (): void {
    update_option(
        'duo_code_half_probe_deactivations',
        (int) get_option('duo_code_half_probe_deactivations', 0) + 1,
        false
    );
    duo_code_half_probe_trace_v2('deactivate-v2');
});

// Active plugins are loaded before WP-CLI dispatches `wp duo deploy`. That is
// exactly the upgrade boundary this fixture exercises: the new code must see
// the old scalar before state apply can introduce the final v2 object.
$duo_code_half_probe_schema = (int) get_option('duo_code_half_probe_schema', 0);
if ($duo_code_half_probe_schema === 1) {
    duo_code_half_probe_migrate_v1_to_v2();
}

add_action('rest_api_init', static function (): void {
    register_rest_route('duo-code-half/v1', '/status', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => static fn(): array => [
            'plugin_version' => '2.0.0',
            'schema' => (int) get_option('duo_code_half_probe_schema', 0),
        ],
    ]);
});
