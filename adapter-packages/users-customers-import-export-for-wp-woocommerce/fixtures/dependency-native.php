<?php
declare(strict_types=1);

require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (!current_user_can('manage_options')) throw new RuntimeException('Importer dependency probe requires its owned administrator');
$plugin = 'users-customers-import-export-for-wp-woocommerce/users-customers-import-export-for-wp-woocommerce.php';
$plugins = get_plugins();
global $wpdb;
$ledger = [];
foreach (['wprism_map' => 'uuid,id_kind', 'wprism_state' => 'uuid', 'wprism_kv' => 'k'] as $suffix => $order) {
    $wpdb->last_error = '';
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}$suffix ORDER BY $order LIMIT 1025", ARRAY_A);
    if ($wpdb->last_error !== '' || !is_array($rows) || count($rows) > 1024
        || strlen(json_encode($rows, JSON_THROW_ON_ERROR)) > 524288) {
        throw new RuntimeException('Importer dependency ledger observation is incomplete');
    }
    $ledger[$suffix] = $rows;
}
$tables = [];
foreach (['wt_iew_mapping_template', 'wt_iew_action_history'] as $suffix) {
    $name = $wpdb->prefix . $suffix;
    $wpdb->last_error = '';
    $found = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($name)));
    if ($wpdb->last_error !== '' || !is_array($found) || count($found) > 1 || ($found !== [] && $found !== [$name])) {
        throw new RuntimeException('Importer dependency table observation is incomplete');
    }
    $tables[$suffix] = $found === [$name];
}
echo json_encode(['version' => $plugins[$plugin]['Version'] ?? null, 'active' => is_plugin_active($plugin),
    'loaded' => defined('WT_U_IEW_VERSION'), 'marker' => get_option('wt_u_iew_is_active', null),
    'tables' => $tables, 'ledger' => $ledger], JSON_THROW_ON_ERROR), "\n";
