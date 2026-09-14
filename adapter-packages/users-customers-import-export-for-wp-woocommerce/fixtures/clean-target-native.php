<?php
declare(strict_types=1);

$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('Importer clean target: ' . $why);
};
$check(is_admin() && current_user_can('manage_options') && defined('WT_U_IEW_VERSION') && WT_U_IEW_VERSION === '2.7.5', 'locked native administrator context');
global $wpdb, $wp_filter;
foreach (['wt_iew_mapping_template', 'wt_iew_action_history'] as $suffix) {
    $wpdb->last_error = '';
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}$suffix");
    $check($wpdb->last_error === '' && $count === '0', 'existing empty native table: ' . $suffix);
}
$users = [];
foreach (['template-reader', 'template-editor', 'import-template-reader'] as $login) {
    $check(!username_exists($login), 'fresh target user prerequisite');
    $id = wp_insert_user(['user_login' => $login, 'user_email' => $login . '-target@example.test',
        'display_name' => 'Target ' . $login, 'role' => 'subscriber', 'user_pass' => wp_generate_password(32)]);
    $check(is_int($id) && $id > 1, 'target user created through WordPress');
    $users[$login] = (string) $id;
}
$owners = [];
foreach ($wp_filter['wp_ajax_iew_import_ajax_basic']->callbacks ?? [] as $callbacks) foreach ($callbacks as $entry) {
    $callback = $entry['function'];
    if (is_array($callback) && is_object($callback[0]) && $callback[1] === 'ajax_main') $owners[] = $callback[0];
}
$check(count($owners) === 1, 'one native local-input owner');
$file = $owners[0]->get_file_path('target-input.csv');
$check(!file_exists($file) && dirname($file) === WP_CONTENT_DIR . '/webtoffee_import', 'new target-local input');
$password = wp_generate_password(32, false);
$bytes = "Login,Email,Display,Password,LocalDisplay\nimport-template-reader,import-template-reader-target@example.test,Target input,$password,Local input\n";
$check(file_put_contents($file, $bytes) === strlen($bytes), 'complete independently provisioned CSV');
echo json_encode(['users' => $users, 'input' => $owners[0]->get_file_url('target-input.csv'),
    'input_sha256' => hash_file('sha256', $file)], JSON_THROW_ON_ERROR), "\n";
