<?php
declare(strict_types=1);

[$kind, $side] = $args;
$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('Importer dirty target: ' . $why);
};
$check(is_admin() && current_user_can('manage_options') && defined('WT_U_IEW_VERSION') && WT_U_IEW_VERSION === '2.7.5', 'locked native administrator');
$check(in_array($kind, ['settings', 'import', 'export'], true) && in_array($side, ['source', 'target'], true), 'explicit native edit');
global $wpdb, $wp_filter;
$hook = $kind === 'settings' ? 'wp_ajax_wt_iew_save_settings_basic' : 'wp_ajax_iew_' . $kind . '_ajax_basic';
$method = $kind === 'settings' ? 'save_settings' : 'ajax_main';
$owners = [];
foreach ($wp_filter[$hook]->callbacks ?? [] as $group) foreach ($group as $entry) {
    $callback = $entry['function'];
    if (is_array($callback) && is_object($callback[0]) && $callback[1] === $method) $owners[] = $callback[0];
}
$check(count($owners) === 1, 'one registered native controller');
$_REQUEST = ['_wpnonce' => wp_create_nonce(WT_IEW_PLUGIN_ID_BASIC)];
if ($kind === 'settings') {
    $settings = json_decode(file_get_contents(__DIR__ . '/native-settings.json'), true, flags: JSON_THROW_ON_ERROR)['source'];
    $settings['wt_iew_default_import_batch'] = $side === 'source' ? 19 : 29;
    $_POST = array_map(static fn(mixed $value): string => (string) $value, $settings);
    foreach (['wt_iew_enable_import_log', 'wt_iew_enable_history_auto_delete', 'wt_iew_include_bom'] as $key) {
        if ($_POST[$key] === '0') unset($_POST[$key]);
    }
} else {
    $name = $kind === 'export' ? 'Selected users' : 'Reusable input mapping';
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wt_iew_mapping_template WHERE BINARY template_type=%s AND BINARY item_type='user' AND BINARY name=%s", $kind, $name), ARRAY_A);
    $check($wpdb->last_error === '' && count($rows) === 1, 'one exact native edit owner');
    $form = json_decode($rows[0]['data'], true, flags: JSON_THROW_ON_ERROR);
    $form['advanced_form_data']['wt_iew_batch_count'] = $side === 'source' ? 7 : 11;
    $form['method_' . $kind . '_form_data']['selected_template'] = (string) $rows[0]['id'];
    // Native ajax_main initializes its private request state before Save;
    // process_action would instead create an unrelated export/history job.
    $_POST = ['to_' . $kind => 'user', $kind . '_method' => 'template', 'selected_template' => $rows[0]['id'],
        $kind . '_action' => 'update_template', 'data_type' => 'json', 'template_name' => wp_slash($name),
        'form_data' => wp_slash(array_map(static fn(array $part): string => wp_json_encode($part), $form))];
}
$owners[0]->{$method}();
throw new RuntimeException('native controller unexpectedly returned');
