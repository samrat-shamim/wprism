<?php
/**
 * Plugin Name: WPrism Commerce Extension
 * Description: A small in-house WooCommerce extension used by the ecommerce grind.
 * Version: 2.0.0
 * Requires PHP: 8.3
 * Requires at least: 6.0
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

function wprism_commerce_extension_is_v2_settings(mixed $settings, ?string $channel = null): bool {
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

function wprism_commerce_extension_add_context_column(): void {
    global $wpdb;
    $table = wprism_commerce_extension_table();
    $shape = wprism_commerce_extension_table_shape(true);
    if ($shape === wprism_commerce_extension_expected_table_shape(true)) {
        return;
    }
    if ($shape !== wprism_commerce_extension_expected_table_shape(false)) {
        throw new RuntimeException('WPrism Commerce Extension v1 table shape is not migratable');
    }
    wprism_commerce_extension_checked_query(
        "ALTER TABLE `$table` ADD COLUMN context VARCHAR(64) NOT NULL DEFAULT '' AFTER label",
        'v2 context migration'
    );
    if (wprism_commerce_extension_table_shape(true) !== wprism_commerce_extension_expected_table_shape(true)) {
        throw new RuntimeException('WPrism Commerce Extension context migration verification failed');
    }
}

function wprism_commerce_extension_migrate_v1_to_v2(): void {
    wprism_commerce_extension_require_woocommerce();
    wprism_commerce_extension_require_gateway_secret();
    $settings = wprism_commerce_extension_read_option('wprism_commerce_extension_settings', null);
    if (is_string($settings) && $settings !== '') {
        $legacy = $settings;
    } elseif (wprism_commerce_extension_is_v2_settings($settings)) {
        // A prior run may have written settings before its schema write failed.
        $legacy = $settings['channel'];
    } else {
        throw new RuntimeException(
            'WPrism Commerce Extension v2 expected the v1 scalar or a verified v2 settings object'
        );
    }
    wprism_commerce_extension_add_context_column();
    wprism_commerce_extension_assert_table_shape(true);
    $desired = [
        'schema' => 2,
        'channel' => $legacy,
        'catalog_mode' => 'managed',
    ];
    wprism_commerce_extension_update_option_checked('wprism_commerce_extension_settings', $desired);
    if (!wprism_commerce_extension_is_v2_settings(
        wprism_commerce_extension_read_option('wprism_commerce_extension_settings', null),
        $legacy
    )) {
        throw new RuntimeException('WPrism Commerce Extension v2 settings verification failed');
    }
    wprism_commerce_extension_update_option_checked('wprism_commerce_extension_schema', 2);
    wprism_commerce_extension_assert_table_shape(true);
    if ((int) wprism_commerce_extension_read_option('wprism_commerce_extension_schema', 0) !== 2
        || !wprism_commerce_extension_is_v2_settings(
            wprism_commerce_extension_read_option('wprism_commerce_extension_settings', null),
            $legacy
        )) {
        throw new RuntimeException('WPrism Commerce Extension v2 migration verification failed');
    }
    wprism_commerce_extension_trace('migrate:v1-to-v2:' . $legacy);
}

function wprism_commerce_extension_install_v2(): void {
    global $wpdb;
    wprism_commerce_extension_require_woocommerce();
    wprism_commerce_extension_require_gateway_secret();
    $table = wprism_commerce_extension_table();
    wprism_commerce_extension_checked_query(
        "CREATE TABLE IF NOT EXISTS `$table` ("
        . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
        . 'label VARCHAR(191) NOT NULL, '
        . 'context VARCHAR(64) NOT NULL DEFAULT \'\', '
        . 'created_at DATETIME NOT NULL, '
        . 'PRIMARY KEY (id)'
        . ') ' . $wpdb->get_charset_collate(),
        'v2 runtime table create'
    );
    wprism_commerce_extension_assert_table_shape(true);
    $settings = wprism_commerce_extension_read_option('wprism_commerce_extension_settings', null);
    if ($settings === null) {
        wprism_commerce_extension_update_option_checked(
            'wprism_commerce_extension_settings',
            ['schema' => 2, 'channel' => 'retail', 'catalog_mode' => 'managed']
        );
        $settings = wprism_commerce_extension_read_option('wprism_commerce_extension_settings', null);
    }
    if (!wprism_commerce_extension_is_v2_settings($settings)) {
        throw new RuntimeException('WPrism Commerce Extension v2 settings are not the expected object');
    }
    wprism_commerce_extension_update_option_checked('wprism_commerce_extension_schema', 2);
    wprism_commerce_extension_assert_table_shape(true);
    if ((int) wprism_commerce_extension_read_option('wprism_commerce_extension_schema', 0) !== 2
        || !wprism_commerce_extension_is_v2_settings(
            wprism_commerce_extension_read_option('wprism_commerce_extension_settings', null)
        )) {
        throw new RuntimeException('WPrism Commerce Extension v2 install verification failed');
    }
}

// Active plugin files are loaded before `wprism promote`'s activation phase.
// This is intentionally early: the migration must see the v1 scalar before
// WPrism applies the canonical v2 object.
if ((int) wprism_commerce_extension_read_option('wprism_commerce_extension_schema', 0) === 1) {
    wprism_commerce_extension_migrate_v1_to_v2();
}

register_activation_hook(__FILE__, static function (): void {
    wprism_commerce_extension_install_v2();
    wprism_commerce_extension_update_option_checked(
        'wprism_commerce_extension_activations',
        (int) wprism_commerce_extension_read_option('wprism_commerce_extension_activations', 0) + 1
    );
    wprism_commerce_extension_trace('activate:commerce-v2');
});

register_deactivation_hook(__FILE__, static function (): void {
    wprism_commerce_extension_trace(
        'deactivate:commerce-v2:woo=' . (class_exists('WooCommerce') ? 'yes' : 'no')
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
            'extension_version' => '2.0.0',
            'schema' => (int) wprism_commerce_extension_read_option('wprism_commerce_extension_schema', 0),
            'woocommerce' => class_exists('WooCommerce'),
        ],
    ]);
});
