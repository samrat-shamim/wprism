<?php
declare(strict_types=1);

$phase = $args[0] ?? '';
$check = static function (bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException('Importer native settings evidence: ' . $reason);
};
$check(current_user_can('manage_options'), 'owned administrator identity');
// The plugin calls set_time_limit during admin bootstrap. Observe deliberately
// malformed raw seconds without loading that consumer, before public Capture.
if ($phase !== 'raw-observe') {
    $check(is_admin(), 'native administrator context');
    $check(defined('WT_U_IEW_VERSION') && WT_U_IEW_VERSION === '2.7.5', 'exact locked plugin');
}
$native = json_decode((string) file_get_contents(__DIR__ . '/native-settings.json'), true, 32, JSON_THROW_ON_ERROR);
global $wpdb, $wp_filter;
$callback = static function (string $hook, string $method) use (&$wp_filter, $check): object {
    $owners = [];
    foreach ($wp_filter[$hook]->callbacks ?? [] as $callbacks) {
        foreach ($callbacks as $entry) {
            $function = $entry['function'];
            if (is_array($function) && is_object($function[0]) && $function[1] === $method) $owners[] = $function[0];
        }
    }
    $check(count($owners) === 1, 'one loaded native owner for ' . $hook);
    return $owners[0];
};
if (str_starts_with($phase, 'setup-')) {
    $check(in_array($phase, ['setup-source', 'setup-target'], true), 'known setup');
    Wt_Import_Export_For_Woo_User_Basic_Common_Helper::set_advanced_settings($native['target']);
    if ($phase === 'setup-source') {
        WPrism\Canon::write_file('/siterepo/site.wprism.json', WPrism\Canon::encode([
            'manifests' => ['core', 'users-customers-import-export-for-wp-woocommerce'],
            'spec_version' => WPRISM_SPEC_VERSION,
            'policy' => ['post_types' => ['post', 'page', 'attachment'], 'taxonomies' => ['category', 'post_tag']],
        ]));
    }
    echo json_encode(['phase' => $phase, 'settings' => get_option('wt_iew_advanced_settings')], JSON_THROW_ON_ERROR), "\n";
    return;
}
if (str_starts_with($phase, 'save-')) {
    $side = substr($phase, 5);
    $check(in_array($side, ['source', 'target', 'retention'], true), 'known native Save');
    $settings = $native[$side === 'retention' ? 'source' : $side];
    if ($side === 'retention') $settings['wt_iew_auto_delete_history_count'] = 1;
    $_POST = array_map(static fn(mixed $value): string => (string) $value, $settings);
    foreach (['wt_iew_enable_import_log', 'wt_iew_enable_history_auto_delete', 'wt_iew_include_bom'] as $checkbox) {
        if ($_POST[$checkbox] === '0') unset($_POST[$checkbox]);
    }
    $_REQUEST = ['_wpnonce' => wp_create_nonce(WT_IEW_PLUGIN_ID_BASIC)];
    $callback('wp_ajax_wt_iew_save_settings_basic', 'save_settings')->save_settings();
    throw new RuntimeException('native Save unexpectedly returned');
}
if ($phase === 'verify-capture') {
    $path = '/siterepo/state/options/core.json';
    $document = WPrism\Canon::decode(WPrism\Canon::read_file($path));
    $captured = WPrism\OptionState::values($document)['wt_iew_advanced_settings'] ?? null;
    $check(WPrism\Canon::encode($captured) === WPrism\Canon::encode($native['source']), 'exact nine native source settings captured');
    $stored = get_option('wt_iew_advanced_settings');
    $check(WPrism\Canon::encode($stored) === WPrism\Canon::encode($native['source']), 'source native state remains unchanged');
    echo json_encode(['settings' => count($captured), 'canonical_sha256' => hash_file('sha256', $path),
        'native_sha256' => hash('sha256', serialize($stored))], JSON_THROW_ON_ERROR), "\n";
    return;
}
if (str_starts_with($phase, 'repository-')) {
    $check(in_array($phase, ['repository-invalid', 'repository-edge'], true), 'known manual repository edit');
    $path = '/siterepo/state/options/core.json';
    $records = WPrism\OptionState::records(WPrism\Canon::decode(WPrism\Canon::read_file($path)));
    $record = $records['wt_iew_advanced_settings'];
    $check($record['state'] === 'present', 'existing canonical settings record');
    $settings = $record['value'];
    $settings['wt_iew_maximum_execution_time'] = 'invalid-seconds';
    if ($phase === 'repository-edge') {
        $settings = array_replace($native['source'], ['wt_iew_maximum_execution_time' => -1,
            'wt_iew_default_import_method' => 'new', 'wt_iew_default_export_method' => 'new',
            'wt_iew_default_import_batch' => 0, 'wt_iew_default_export_batch' => 0,
            'wt_iew_auto_delete_history_count' => 0, 'wt_iew_include_bom' => 1]);
    }
    $records['wt_iew_advanced_settings'] = WPrism\OptionState::present($settings, $record['autoload']);
    WPrism\Canon::write_file($path, WPrism\Canon::encode(WPrism\OptionState::document($records)));
    echo json_encode(['phase' => $phase, 'settings' => $settings, 'autoload' => $record['autoload'],
        'canonical_sha256' => hash_file('sha256', $path)], JSON_THROW_ON_ERROR), "\n";
    return;
}
if ($phase === 'jobs') {
    $id = wp_insert_user(['user_login' => 'importer-fixture-reader', 'user_email' => 'importer-reader@example.test',
        'display_name' => 'Importer Fixture Reader', 'role' => 'subscriber', 'user_pass' => wp_generate_password(32)]);
    $check(is_int($id) && $id > 1, 'fixture reader created');
    $form = [
        'method_export_form_data' => ['method_export' => 'new'],
        'filter_form_data' => ['wt_iew_email' => [(string) $id], 'wt_iew_limit' => '1', 'wt_iew_sort_columns' => ['user_login'], 'wt_iew_order_by' => 'ASC'],
        'mapping_form_data' => ['mapping_fields' => ['user_login' => ['user_login', 1], 'display_name' => ['display_name', 1]],
            'mapping_selected_fields' => ['user_login' => 'user_login', 'display_name' => 'display_name']],
        'advanced_form_data' => ['wt_iew_batch_count' => '10', 'wt_iew_file_as' => 'csv', 'wt_iew_delimiter' => ','],
    ];
    $export = $callback('wp_ajax_iew_export_ajax_basic', 'ajax_main');
    $jobs = [];
    for ($i = 1; $i <= 3; $i++) {
        $result = $export->process_action($form, 'export', 'user', 'importer-fixture-' . $i);
        $check($result['response'] === true && (int) $result['finished'] === 1 && (int) $result['total_records'] === 1,
            'native export completed exactly one fixture user');
        $history = Wt_Import_Export_For_Woo_Basic_History::get_history_entry_by_id((int) $result['history_id']);
        $check((int) $history['status'] === 1, 'completed native history');
        $file = WP_CONTENT_DIR . '/webtoffee_export/' . $history['file_name'];
        $check(is_file($file), 'native CSV exists');
        $rows = array_map('str_getcsv', file($file, FILE_IGNORE_NEW_LINES));
        $check(count($rows) === 2 && count($rows[0]) === 2 && count($rows[1]) === 2,
            'one header and one data row with only the selected fixture columns');
        $check(in_array('importer-fixture-reader', $rows[1], true) && in_array('Importer Fixture Reader', $rows[1], true),
            'native CSV contains the selected fixture reader');
        $jobs[] = ['history_id' => (int) $result['history_id'], 'sha256' => hash_file('sha256', $file)];
    }
    $settings = get_option('wt_iew_advanced_settings');
    $settings['other_module_key'] = ['nested' => 'keep-target-local'];
    update_option('wt_iew_advanced_settings', $settings);
    echo json_encode(['phase' => $phase, 'jobs' => $jobs], JSON_THROW_ON_ERROR), "\n";
    return;
}
$check(in_array($phase, ['observe', 'raw-observe'], true), 'known observation');
$rows = static function (string $table, string $order) use ($wpdb, $check): array {
    $wpdb->last_error = '';
    $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY $order LIMIT 1025", ARRAY_A);
    $check($wpdb->last_error === '' && is_array($rows) && count($rows) <= 1024, 'complete bounded table census');
    $check(strlen(json_encode($rows, JSON_THROW_ON_ERROR)) <= 524288, 'bounded native table bytes');
    return $rows;
};
$order = ['posts' => 'ID', 'postmeta' => 'meta_id', 'options' => 'option_id', 'terms' => 'term_id',
    'term_taxonomy' => 'term_taxonomy_id', 'term_relationships' => 'object_id,term_taxonomy_id', 'termmeta' => 'meta_id',
    'users' => 'ID', 'usermeta' => 'umeta_id', 'wt_iew_action_history' => 'id', 'wt_iew_mapping_template' => 'id'];
$tables = [];
foreach ($order as $suffix => $key) $tables[$suffix] = $rows($wpdb->prefix . $suffix, $key);
$files = [];
foreach (['webtoffee_export', 'webtoffee_import'] as $directory) {
    $base = WP_CONTENT_DIR . '/' . $directory;
    if (!is_dir($base)) continue;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $entry) {
        $check(!$entry->isLink() && $entry->isFile() && $entry->getSize() <= 524288 && count($files) < 1024, 'bounded native file census');
        $files[substr($entry->getPathname(), strlen(WP_CONTENT_DIR) + 1)] = hash_file('sha256', $entry->getPathname());
    }
}
ksort($files, SORT_STRING);
$settings = $phase === 'raw-observe' ? get_option('wt_iew_advanced_settings')
    : Wt_Import_Export_For_Woo_User_Basic_Common_Helper::get_advanced_settings();
foreach ($order as $suffix => $key) $check($tables[$suffix] === $rows($wpdb->prefix . $suffix, $key), 'native settings read preserves ' . $suffix);
echo json_encode(['format' => 'wprism-importer-native-settings/v1', 'wordpress' => get_bloginfo('version'),
    'plugin' => defined('WT_U_IEW_VERSION') ? WT_U_IEW_VERSION : null, 'php' => PHP_VERSION, 'database' => $wpdb->db_server_info(),
    'tables' => $tables, 'files' => $files, 'settings' => $settings], JSON_THROW_ON_ERROR), "\n";
