<?php
declare(strict_types=1);

$phase = $args[0] ?? '';
$kind = $args[1] ?? '';
$name = $args[2] ?? '';
$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('Importer/Woo native templates: ' . $why);
};
$check(in_array($kind, ['import', 'export'], true) && in_array($phase, ['save', 'consume'], true), 'closed native operation');
$check(defined('WC_VERSION') && WC_VERSION === '11.0.1' && defined('WT_U_IEW_VERSION')
    && WT_U_IEW_VERSION === '2.7.5' && is_admin() && current_user_can('manage_options'), 'locked admin context');
add_filter('pre_wp_mail', static fn() => true);
global $wpdb, $wp_filter;
$owners = [];
foreach ($wp_filter['wp_ajax_iew_' . $kind . '_ajax_basic']->callbacks ?? [] as $group) foreach ($group as $entry) {
    $callback = $entry['function'];
    if (is_array($callback) && is_object($callback[0]) && $callback[1] === 'ajax_main') $owners[] = $callback[0];
}
$check(count($owners) === 1, 'one registered native module');
$module = $owners[0];
$module->{$kind . '_method'} = 'template';
require_once WT_U_IEW_PLUGIN_PATH . 'admin/modules/' . $kind . '/classes/class-' . $kind . '-ajax.php';
if ($kind === 'import') {
    $initialized = $module->process_action([], 'import', 'user');
    $check($initialized['response'] === false && $initialized['history_id'] === 0, 'native no-job initializer');
}
$table = $wpdb->prefix . 'wt_iew_mapping_template';
$wpdb->last_error = '';
$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE BINARY template_type=%s AND BINARY item_type='user' AND BINARY name=%s", $kind, $name), ARRAY_A);
$check($wpdb->last_error === '' && count($rows) === 1, 'one exact native saved template');
$id = (int) $rows[0]['id'];
$form = json_decode($rows[0]['data'], true, flags: JSON_THROW_ON_ERROR);
$class = 'Wt_Import_Export_For_Woo_User_Basic_' . ucfirst($kind) . '_Ajax';
$ajax = new $class($module, 'user', $module->get_steps(), 'template', $id, 0);
$post = static function (array $form): void {
    $_POST = ['form_data' => wp_slash(array_map(static fn(array $part): string => wp_json_encode($part), $form))];
};
if ($phase === 'save') {
    if ($kind === 'export') {
        // Export's sanitizer resolves filter types from private to_export;
        // process_action sets it (locked export.php:630), as in the capsule seed.
        $initialized = $module->process_action($form, 'export', 'user', 'combination-initialize-' . $id);
        $check($initialized['response'] === true && (int) $initialized['finished'] === 1
            && (int) $initialized['total_records'] === 2, 'native export initializes its request before Save');
    }
    $fields = json_decode(file_get_contents(__DIR__ . '/customer-mapping-fields.json'), true, flags: JSON_THROW_ON_ERROR)[$kind];
    foreach ($fields as $field) {
        $label = $kind === 'export' ? $field : '{' . $field . '}';
        $form['mapping_form_data']['mapping_fields'][$field] = [$label, 1];
        $form['mapping_form_data']['mapping_selected_fields'][$field] = $label;
    }
    $form['method_' . $kind . '_form_data']['selected_template'] = (string) $id;
    $post($form);
    $_POST['template_name'] = wp_slash($name);
    $response = $ajax->do_save_template('update', []);
    $check(($response['status'] ?? null) === 1 && (int) ($response['id'] ?? 0) === $id, 'native Update keeps saved identity');
    $stored = $wpdb->get_var($wpdb->prepare("SELECT data FROM `$table` WHERE id=%d", $id));
    $check($wpdb->last_error === '' && json_decode($stored, true, flags: JSON_THROW_ON_ERROR) === $form, 'complete native saved form readback');
    echo json_encode(['phase' => $phase, 'kind' => $kind, 'id' => $id, 'form' => $form], JSON_THROW_ON_ERROR), "\n";
    return;
}
if ($kind === 'import') {
    $post($form);
    $validated = $ajax->validate_file(['msg' => '']);
    $check(($validated['status'] ?? null) === 1, 'saved bound CSV validates');
    $module->temp_import_file = $validated['file_name'];
    $download = $module->process_download($form, 'download', 'user');
    $check($download['response'] === true && (int) $download['history_id'] > 0, 'native import job created');
    $result = $module->process_action([], 'import', 'user', '', (int) $download['history_id'], 0);
    $check($result['response'] === true && (int) $result['finished'] === 1 && (int) $result['total_success'] === 1, 'one native customer import completes');
    $user = get_user_by('login', 'import-template-reader');
    $check($user instanceof WP_User, 'target customer remains');
    $customer = new WC_Customer($user->ID);
    $check($customer->get_billing_city() === 'Chattogram' && $customer->get_shipping_city() === 'Osaka', 'fresh Woo customer reads imported target CSV addresses');
    echo json_encode(['phase' => $phase, 'kind' => $kind, 'result' => $result,
        'billing' => $customer->get_billing('edit'), 'shipping' => $customer->get_shipping('edit')], JSON_THROW_ON_ERROR), "\n";
    return;
}
$result = $module->process_action($form, 'export', 'user', 'customer-combination-' . $id);
$check($result['response'] === true && (int) $result['finished'] === 1 && (int) $result['total_records'] === 2, 'two selected native customers exported');
$history = Wt_Import_Export_For_Woo_Basic_History::get_history_entry_by_id((int) $result['history_id']);
$check((int) $history['status'] === 1, 'completed native export history');
$file = WP_CONTENT_DIR . '/webtoffee_export/' . $history['file_name'];
$check(is_file($file) && filesize($file) > 0 && filesize($file) < 16384, 'bounded complete native CSV');
$handle = fopen($file, 'rb');
$check(is_resource($handle), 'CSV readable');
$records = [];
while (($record = fgetcsv($handle)) !== false) $records[] = $record;
fclose($handle);
if (str_starts_with($records[0][0], "\xEF\xBB\xBF")) $records[0][0] = substr($records[0][0], 3);
$check(count($records) === 3 && $records[0] === array_values($form['mapping_form_data']['mapping_selected_fields']), 'exact saved CSV headers and row count');
$actual = array_column(array_slice($records, 1), null, 0);
$check(count($actual) === 2, 'distinct selected customer rows');
foreach (['template-reader', 'template-editor'] as $login) {
    $user = get_user_by('login', $login);
    $check($user instanceof WP_User, 'selected target customer');
    $check(($actual[$login] ?? null) === [$login, $user->user_email, $user->display_name,
        'Dhaka', 'Tokyo', '3', '20', '6.67'], 'native target address and paid/pending statistics for ' . $login);
}
echo json_encode(['phase' => $phase, 'kind' => $kind, 'records' => $records], JSON_THROW_ON_ERROR), "\n";
