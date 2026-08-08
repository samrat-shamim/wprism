<?php
/**
 * Plugin Name: Duo Commerce Extension
 * Description: Deliberately broken v2 activation fixture for recovery testing.
 * Version: 2.0.0
 * Requires Plugins: woocommerce
 */

defined('ABSPATH') || exit;

function duo_commerce_extension_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'duo_commerce_extension_events';
}

function duo_commerce_extension_db_error(): string {
    global $wpdb;
    return trim((string) ($wpdb->last_error ?? ''));
}

function duo_commerce_extension_read_option(string $name, mixed $default = false): mixed {
    global $wpdb;
    $wpdb->last_error = '';
    $value = get_option($name, $default);
    if (duo_commerce_extension_db_error() !== '') {
        throw new RuntimeException("Duo Commerce Extension option read failed: $name");
    }
    return $value;
}

function duo_commerce_extension_update_option_checked(string $name, mixed $value): void {
    global $wpdb;
    $wpdb->last_error = '';
    update_option($name, $value, false);
    if (duo_commerce_extension_db_error() !== '') {
        throw new RuntimeException("Duo Commerce Extension option write failed: $name");
    }
    $missing = new stdClass();
    $actual = duo_commerce_extension_read_option($name, $missing);
    if ($actual === $missing || $actual !== $value) {
        throw new RuntimeException("Duo Commerce Extension option verification failed: $name");
    }
}

function duo_commerce_extension_checked_query(string $sql, string $context): void {
    global $wpdb;
    $wpdb->last_error = '';
    $result = $wpdb->query($sql);
    if ($result === false || duo_commerce_extension_db_error() !== '') {
        throw new RuntimeException("Duo Commerce Extension $context query failed");
    }
}

/** @return list<array{name:string,data_type:string,unsigned:bool,length:?int,nullable:string,extra:string,column_key:string,default:?string}> */
function duo_commerce_extension_table_shape(bool $with_context): array {
    global $wpdb;
    $table = duo_commerce_extension_table();
    $wpdb->last_error = '';
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, EXTRA, COLUMN_KEY, COLUMN_DEFAULT '
            . 'FROM INFORMATION_SCHEMA.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s '
            . 'ORDER BY ORDINAL_POSITION',
            $table
        ),
        ARRAY_A
    );
    if (!is_array($rows) || duo_commerce_extension_db_error() !== '') {
        throw new RuntimeException('Duo Commerce Extension runtime table shape read failed');
    }
    $shape = [];
    foreach ($rows as $row) {
        $length = $row['CHARACTER_MAXIMUM_LENGTH'] ?? null;
        $default = ($row['COLUMN_DEFAULT'] ?? null) === null ? null : (string) $row['COLUMN_DEFAULT'];
        // MariaDB quotes literal string defaults in INFORMATION_SCHEMA while
        // MySQL returns the decoded value. Normalize only the exact empty
        // string spelling this schema declares; every other default remains
        // byte-visible to the fail-closed shape comparison.
        if ($default === "''") {
            $default = '';
        }
        $shape[] = [
            'name' => (string) ($row['COLUMN_NAME'] ?? ''),
            'data_type' => strtolower((string) ($row['DATA_TYPE'] ?? '')),
            'unsigned' => stripos((string) ($row['COLUMN_TYPE'] ?? ''), 'unsigned') !== false,
            'length' => $length === null || $length === '' ? null : (int) $length,
            'nullable' => strtoupper((string) ($row['IS_NULLABLE'] ?? '')),
            'extra' => strtolower((string) ($row['EXTRA'] ?? '')),
            'column_key' => strtoupper((string) ($row['COLUMN_KEY'] ?? '')),
            'default' => $default,
        ];
    }
    return $shape;
}

/** @return list<array{name:string,data_type:string,unsigned:bool,length:?int,nullable:string,extra:string,column_key:string,default:?string}> */
function duo_commerce_extension_expected_table_shape(bool $with_context): array {
    $shape = [
        ['name' => 'id', 'data_type' => 'bigint', 'unsigned' => true, 'length' => null, 'nullable' => 'NO', 'extra' => 'auto_increment', 'column_key' => 'PRI', 'default' => null],
        ['name' => 'label', 'data_type' => 'varchar', 'unsigned' => false, 'length' => 191, 'nullable' => 'NO', 'extra' => '', 'column_key' => '', 'default' => null],
    ];
    if ($with_context) {
        $shape[] = ['name' => 'context', 'data_type' => 'varchar', 'unsigned' => false, 'length' => 64, 'nullable' => 'NO', 'extra' => '', 'column_key' => '', 'default' => ''];
    }
    $shape[] = ['name' => 'created_at', 'data_type' => 'datetime', 'unsigned' => false, 'length' => null, 'nullable' => 'NO', 'extra' => '', 'column_key' => '', 'default' => null];
    return $shape;
}

function duo_commerce_extension_assert_table_shape(bool $with_context): void {
    if (duo_commerce_extension_table_shape($with_context) !== duo_commerce_extension_expected_table_shape($with_context)) {
        throw new RuntimeException(
            'Duo Commerce Extension runtime table shape does not match the expected '
            . ($with_context ? 'v2' : 'v1') . ' contract'
        );
    }
}

function duo_commerce_extension_trace(string $event): void {
    $trace = duo_commerce_extension_read_option('duo_commerce_extension_trace', []);
    $trace = is_array($trace) ? $trace : [];
    $trace[] = $event;
    duo_commerce_extension_update_option_checked('duo_commerce_extension_trace', $trace);
}

function duo_commerce_extension_require_woocommerce(): void {
    // Lifecycle reconciliation uses an ordinary WordPress bootstrap, so the
    // provider-first activation plan should already have loaded WooCommerce.
    // Keep a defensive direct-fixture fallback for isolated/offline execution.
    if (!class_exists('WooCommerce') && defined('WP_PLUGIN_DIR')) {
        $woo = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
        if (is_file($woo)) {
            require_once $woo;
        }
    }
    if (!class_exists('WooCommerce')) {
        throw new RuntimeException(
            'Duo Commerce Extension requires WooCommerce to be active before its lifecycle hook runs'
        );
    }
}

function duo_commerce_extension_require_gateway_secret(): void {
    if ((string) duo_commerce_extension_read_option('duo_commerce_extension_gateway_secret', '') === '') {
        throw new RuntimeException('Duo Commerce Extension gateway secret is env-owned and must be provisioned');
    }
}

function duo_commerce_extension_is_v2_settings(mixed $settings, ?string $channel = null): bool {
    if (!is_array($settings)
        || count($settings) !== 3
        || !array_key_exists('schema', $settings)
        || !array_key_exists('channel', $settings)
        || !array_key_exists('catalog_mode', $settings)
        || $settings['schema'] !== 2
        || !is_string($settings['channel'])
        || $settings['channel'] === ''
        || $settings['catalog_mode'] !== 'managed') {
        return false;
    }
    return $channel === null || $settings['channel'] === $channel;
}

function duo_commerce_extension_add_context_column(): void {
    global $wpdb;
    $table = duo_commerce_extension_table();
    $shape = duo_commerce_extension_table_shape(true);
    if ($shape === duo_commerce_extension_expected_table_shape(true)) {
        return;
    }
    if ($shape !== duo_commerce_extension_expected_table_shape(false)) {
        throw new RuntimeException('Duo Commerce Extension v1 table shape is not migratable');
    }
    duo_commerce_extension_checked_query(
        "ALTER TABLE `$table` ADD COLUMN context VARCHAR(64) NOT NULL DEFAULT '' AFTER label",
        'v2 context migration'
    );
    if (duo_commerce_extension_table_shape(true) !== duo_commerce_extension_expected_table_shape(true)) {
        throw new RuntimeException('Duo Commerce Extension context migration verification failed');
    }
}

function duo_commerce_extension_migrate_v1_to_v2(): void {
    duo_commerce_extension_require_woocommerce();
    duo_commerce_extension_require_gateway_secret();
    $settings = duo_commerce_extension_read_option('duo_commerce_extension_settings', null);
    if (is_string($settings) && $settings !== '') {
        $legacy = $settings;
    } elseif (duo_commerce_extension_is_v2_settings($settings)) {
        // A prior run may have written settings before its schema write failed.
        $legacy = $settings['channel'];
    } else {
        throw new RuntimeException(
            'Duo Commerce Extension v2 expected the v1 scalar or a verified v2 settings object'
        );
    }
    duo_commerce_extension_add_context_column();
    duo_commerce_extension_assert_table_shape(true);
    $desired = [
        'schema' => 2,
        'channel' => $legacy,
        'catalog_mode' => 'managed',
    ];
    duo_commerce_extension_update_option_checked('duo_commerce_extension_settings', $desired);
    if (!duo_commerce_extension_is_v2_settings(
        duo_commerce_extension_read_option('duo_commerce_extension_settings', null),
        $legacy
    )) {
        throw new RuntimeException('Duo Commerce Extension v2 settings verification failed');
    }
    duo_commerce_extension_update_option_checked('duo_commerce_extension_schema', 2);
    duo_commerce_extension_assert_table_shape(true);
    if ((int) duo_commerce_extension_read_option('duo_commerce_extension_schema', 0) !== 2
        || !duo_commerce_extension_is_v2_settings(
            duo_commerce_extension_read_option('duo_commerce_extension_settings', null),
            $legacy
        )) {
        throw new RuntimeException('Duo Commerce Extension v2 migration verification failed');
    }
    duo_commerce_extension_trace('migrate:v1-to-v2:' . $legacy);
}

function duo_commerce_extension_install_v2(): void {
    global $wpdb;
    duo_commerce_extension_require_woocommerce();
    duo_commerce_extension_require_gateway_secret();
    $table = duo_commerce_extension_table();
    duo_commerce_extension_checked_query(
        "CREATE TABLE IF NOT EXISTS `$table` ("
        . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
        . 'label VARCHAR(191) NOT NULL, '
        . 'context VARCHAR(64) NOT NULL DEFAULT \'\', '
        . 'created_at DATETIME NOT NULL, '
        . 'PRIMARY KEY (id)'
        . ') ' . $wpdb->get_charset_collate(),
        'v2 runtime table create'
    );
    duo_commerce_extension_assert_table_shape(true);
    $settings = duo_commerce_extension_read_option('duo_commerce_extension_settings', null);
    if ($settings === null) {
        duo_commerce_extension_update_option_checked(
            'duo_commerce_extension_settings',
            ['schema' => 2, 'channel' => 'retail', 'catalog_mode' => 'managed']
        );
        $settings = duo_commerce_extension_read_option('duo_commerce_extension_settings', null);
    }
    if (!duo_commerce_extension_is_v2_settings($settings)) {
        throw new RuntimeException('Duo Commerce Extension v2 settings are not the expected object');
    }
    duo_commerce_extension_update_option_checked('duo_commerce_extension_schema', 2);
    duo_commerce_extension_assert_table_shape(true);
    if ((int) duo_commerce_extension_read_option('duo_commerce_extension_schema', 0) !== 2
        || !duo_commerce_extension_is_v2_settings(
            duo_commerce_extension_read_option('duo_commerce_extension_settings', null)
        )) {
        throw new RuntimeException('Duo Commerce Extension v2 install verification failed');
    }
}

if ((int) duo_commerce_extension_read_option('duo_commerce_extension_schema', 0) === 1) {
    duo_commerce_extension_migrate_v1_to_v2();
}

register_activation_hook(__FILE__, static function (): void {
    duo_commerce_extension_install_v2();
    duo_commerce_extension_trace('activate:commerce-v2-broken');
    throw new RuntimeException('Duo Commerce Extension reviewed v2 activation failure');
});

register_deactivation_hook(__FILE__, static function (): void {
    duo_commerce_extension_trace(
        'deactivate:commerce-v2:woo=' . (class_exists('WooCommerce') ? 'yes' : 'no')
    );
    duo_commerce_extension_update_option_checked(
        'duo_commerce_extension_deactivations',
        (int) duo_commerce_extension_read_option('duo_commerce_extension_deactivations', 0) + 1
    );
});
