<?php
/**
 * Plugin Name: WPrism Commerce Extension
 * Description: A small in-house WooCommerce extension used by the ecommerce grind.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 */

defined('ABSPATH') || exit;

function wprism_commerce_extension_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'wprism_commerce_extension_events';
}

function wprism_commerce_extension_db_error(): string {
    global $wpdb;
    return trim((string) ($wpdb->last_error ?? ''));
}

function wprism_commerce_extension_read_option(string $name, mixed $default = false): mixed {
    global $wpdb;
    $wpdb->last_error = '';
    $value = get_option($name, $default);
    if (wprism_commerce_extension_db_error() !== '') {
        throw new RuntimeException("WPrism Commerce Extension option read failed: $name");
    }
    return $value;
}

function wprism_commerce_extension_update_option_checked(string $name, mixed $value): void {
    global $wpdb;
    $wpdb->last_error = '';
    update_option($name, $value, false);
    if (wprism_commerce_extension_db_error() !== '') {
        throw new RuntimeException("WPrism Commerce Extension option write failed: $name");
    }
    $missing = new stdClass();
    $actual = wprism_commerce_extension_read_option($name, $missing);
    $matches = $actual === $value
        || (is_int($value) && is_string($actual) && $actual === (string) $value);
    if ($actual === $missing || !$matches) {
        throw new RuntimeException("WPrism Commerce Extension option verification failed: $name");
    }
}

function wprism_commerce_extension_checked_query(string $sql, string $context): void {
    global $wpdb;
    $wpdb->last_error = '';
    $result = $wpdb->query($sql);
    if ($result === false || wprism_commerce_extension_db_error() !== '') {
        throw new RuntimeException("WPrism Commerce Extension $context query failed");
    }
}

/** @return list<array{name:string,data_type:string,unsigned:bool,length:?int,nullable:string,extra:string,column_key:string,default:?string}> */
function wprism_commerce_extension_table_shape(bool $with_context): array {
    global $wpdb;
    $table = wprism_commerce_extension_table();
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
    if (!is_array($rows) || wprism_commerce_extension_db_error() !== '') {
        throw new RuntimeException('WPrism Commerce Extension runtime table shape read failed');
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
function wprism_commerce_extension_expected_table_shape(bool $with_context): array {
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

function wprism_commerce_extension_assert_table_shape(bool $with_context): void {
    if (wprism_commerce_extension_table_shape($with_context) !== wprism_commerce_extension_expected_table_shape($with_context)) {
        throw new RuntimeException(
            'WPrism Commerce Extension runtime table shape does not match the expected '
            . ($with_context ? 'v2' : 'v1') . ' contract'
        );
    }
}

function wprism_commerce_extension_trace(string $event): void {
    $trace = wprism_commerce_extension_read_option('wprism_commerce_extension_trace', []);
    $trace = is_array($trace) ? $trace : [];
    $trace[] = $event;
    wprism_commerce_extension_update_option_checked('wprism_commerce_extension_trace', $trace);
}

function wprism_commerce_extension_require_woocommerce(): void {
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
            'WPrism Commerce Extension requires WooCommerce to be active before its lifecycle hook runs'
        );
    }
}

function wprism_commerce_extension_require_gateway_secret(): void {
    if ((string) wprism_commerce_extension_read_option('wprism_commerce_extension_gateway_secret', '') === '') {
        throw new RuntimeException('WPrism Commerce Extension gateway secret is env-owned and must be provisioned');
    }
}

function wprism_commerce_extension_install_v1(): void {
    global $wpdb;
    wprism_commerce_extension_require_woocommerce();
    wprism_commerce_extension_require_gateway_secret();
    $table = wprism_commerce_extension_table();
    wprism_commerce_extension_checked_query(
        "CREATE TABLE IF NOT EXISTS `$table` ("
        . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
        . 'label VARCHAR(191) NOT NULL, '
        . 'created_at DATETIME NOT NULL, '
        . 'PRIMARY KEY (id)'
        . ') ' . $wpdb->get_charset_collate(),
        'v1 runtime table create'
    );
    wprism_commerce_extension_assert_table_shape(false);
    $settings = wprism_commerce_extension_read_option('wprism_commerce_extension_settings', null);
    if ($settings === null) {
        wprism_commerce_extension_update_option_checked('wprism_commerce_extension_settings', 'retail');
        $settings = wprism_commerce_extension_read_option('wprism_commerce_extension_settings', null);
    }
    if (!is_string($settings) || $settings === '') {
        throw new RuntimeException('WPrism Commerce Extension v1 settings are not the expected scalar');
    }
    wprism_commerce_extension_update_option_checked('wprism_commerce_extension_schema', 1);
    wprism_commerce_extension_assert_table_shape(false);
    if (wprism_commerce_extension_read_option('wprism_commerce_extension_settings', null) !== $settings
        || (int) wprism_commerce_extension_read_option('wprism_commerce_extension_schema', 0) !== 1) {
        throw new RuntimeException('WPrism Commerce Extension v1 install verification failed');
    }
}

register_activation_hook(__FILE__, static function (): void {
    wprism_commerce_extension_install_v1();
    wprism_commerce_extension_update_option_checked(
        'wprism_commerce_extension_activations',
        (int) wprism_commerce_extension_read_option('wprism_commerce_extension_activations', 0) + 1
    );
    wprism_commerce_extension_trace('activate:commerce-v1');
});

register_deactivation_hook(__FILE__, static function (): void {
    wprism_commerce_extension_trace(
        'deactivate:commerce-v1:woo=' . (class_exists('WooCommerce') ? 'yes' : 'no')
    );
    wprism_commerce_extension_update_option_checked(
        'wprism_commerce_extension_deactivations',
        (int) wprism_commerce_extension_read_option('wprism_commerce_extension_deactivations', 0) + 1
    );
});

add_action('rest_api_init', static function (): void {
    register_rest_route('wprism-commerce/v1', '/status', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => static fn(): array => [
            'extension_version' => '1.0.0',
            'schema' => (int) wprism_commerce_extension_read_option('wprism_commerce_extension_schema', 0),
            'woocommerce' => class_exists('WooCommerce'),
        ],
    ]);
});
