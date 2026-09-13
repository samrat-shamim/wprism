<?php
declare(strict_types=1);

$phase = $args[0] ?? '';
if ($phase === 'observe') {
    $args = ['observe'];
    require __DIR__ . '/settings-native.php';
    return;
}
$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('Importer native templates: ' . $why);
};
$check(is_admin() && current_user_can('manage_options') && defined('WT_U_IEW_VERSION') && WT_U_IEW_VERSION === '2.7.5',
    'locked native administrator context');
global $wpdb, $wp_filter;
$table = $wpdb->prefix . 'wt_iew_mapping_template';
$owners = [];
foreach ($wp_filter['wp_ajax_iew_export_ajax_basic']->callbacks ?? [] as $group) {
    foreach ($group as $entry) {
        $callback = $entry['function'];
        if (is_array($callback) && is_object($callback[0]) && $callback[1] === 'ajax_main') $owners[] = $callback[0];
    }
}
$check(count($owners) === 1, 'one actual native exporter owner');
$export = $owners[0];
require_once WT_U_IEW_PLUGIN_PATH . 'admin/modules/export/classes/class-export-ajax.php';
$export->export_method = 'template';
$newAjax = static fn(int $id) => new Wt_Import_Export_For_Woo_User_Basic_Export_Ajax($export, 'user', $export->get_steps(), 'template', $id, 0);
$read = static function (string $name) use ($wpdb, $table, $check): array {
    $wpdb->last_error = '';
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE template_type='export' AND item_type='user' AND name=%s", $name), ARRAY_A);
    $check($wpdb->last_error === '' && count($rows) === 1, 'one owned native template ' . $name);
    return $rows[0];
};
$save = static function (array $form, string $name, int $id, string $verb) use ($newAjax, $check): int {
    $_POST = ['template_name' => wp_slash($name), 'form_data' => wp_slash(array_map(static fn(array $part): string => wp_json_encode($part), $form))];
    $response = $newAjax($id)->do_save_template($verb, []);
    $check(($response['status'] ?? null) === 1 && (int) ($response['id'] ?? 0) > 0, 'native ' . $verb . ' saved a template');
    return (int) $response['id'];
};
$logins = ['template-reader', 'template-editor'];
$run = static function (array $form, string $filename) use ($export, $wpdb, $check, $logins): array {
    $before = $wpdb->get_results("SELECT * FROM {$wpdb->users} ORDER BY ID", ARRAY_A);
    $result = $export->process_action($form, 'export', 'user', $filename);
    $check(($result['response'] ?? false) === true && (int) $result['finished'] === 1 && (int) $result['total_records'] === 2,
        'actual exporter completes exactly the two selected users');
    $history = Wt_Import_Export_For_Woo_Basic_History::get_history_entry_by_id((int) $result['history_id']);
    $check((int) $history['status'] === 1, 'actual export history is complete');
    $file = WP_CONTENT_DIR . '/webtoffee_export/' . $history['file_name'];
    $check(is_file($file) && filesize($file) > 0 && filesize($file) < 8192, 'complete bounded native CSV');
    $handle = fopen($file, 'rb');
    $check(is_resource($handle), 'native CSV can be reopened');
    $records = [];
    while (($record = fgetcsv($handle)) !== false) $records[] = $record;
    fclose($handle);
    if (str_starts_with($records[0][0], "\xEF\xBB\xBF")) $records[0][0] = substr($records[0][0], 3);
    $check(count($records) === 3 && $records[0] === array_values($form['mapping_form_data']['mapping_selected_fields']),
        'CSV has exactly the saved headers and two data rows');
    $actual = array_column(array_slice($records, 1), null, 0);
    $check(count($actual) === 2, 'CSV selected logins are distinct');
    foreach ($logins as $login) {
        $user = get_user_by('login', $login);
        $check($user instanceof WP_User && ($actual[$login] ?? null) === [$login, $user->user_email, $user->display_name],
            'CSV reads this environment user profile for ' . $login);
    }
    $check($before === $wpdb->get_results("SELECT * FROM {$wpdb->users} ORDER BY ID", ARRAY_A), 'native export preserves all users');
    return ['history_id' => (int) $result['history_id'], 'records' => $records, 'sha256' => hash_file('sha256', $file)];
};
if (str_starts_with($phase, 'setup-')) {
    $side = substr($phase, 6);
    $check(in_array($side, ['source', 'target'], true), 'known native template setup');
    $check((int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`") === 0, 'setup never replaces existing templates');
    if ($side === 'target') {
        foreach (['filler-one', 'filler-two', 'filler-three'] as $login) {
            $check(!username_exists($login), 'fresh target filler login');
            $check(is_int(wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(32)])), 'target filler user created');
        }
        foreach ([['id' => 101, 'template_type' => 'import', 'item_type' => 'user', 'name' => 'Local input', 'data' => '{broken-local-input'],
            ['id' => 102, 'template_type' => 'export', 'item_type' => 'product', 'name' => 'Selected users', 'data' => 'local-product']] as $foreign) {
            $check($wpdb->insert($table, $foreign) === 1, 'foreign template fixture inserted');
            $check($wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE id=%d", $foreign['id']), ARRAY_A) === array_map('strval', $foreign),
                'foreign fixture readback is complete');
        }
    }
    $ids = [];
    foreach ($logins as $login) {
        $check(!username_exists($login), 'fresh selected user login');
        $id = wp_insert_user(['user_login' => $login, 'user_email' => $login . '-' . $side . '@example.test',
            'display_name' => ucfirst($side) . ' ' . $login, 'role' => 'subscriber', 'user_pass' => wp_generate_password(32)]);
        $check(is_int($id) && $id > 1, 'native selected user created');
        $ids[] = (string) $id;
    }
    $fixture = json_decode(file_get_contents(__DIR__ . '/native-export-templates.json'), true, flags: JSON_THROW_ON_ERROR);
    $form = $fixture['form'];
    $form['filter_form_data']['wt_iew_email'] = $ids;
    $form['method_export_form_data']['selected_template'] = '0';
    // process_action initializes the exporter's private request state itself;
    // assigning its private to_export property is not a native workflow.
    $job = $run($form, 'templates-initialize-' . $side);
    if ($side === 'target') $form['mapping_form_data']['mapping_selected_fields']['display_name'] = 'Local_Display';
    $original = $save($form, 'Selected users', 0, 'save');
    $form['method_export_form_data']['selected_template'] = (string) $original;
    $check($save($form, 'Selected users', $original, 'update') === $original, 'native Update retains template identity');
    $copy = $side === 'source' ? $save($form, 'Selected users copy', $original, 'save_as') : null;
    if ($copy !== null) {
        $stored = json_decode($read('Selected users copy')['data'], true, flags: JSON_THROW_ON_ERROR);
        $check($copy !== $original && $stored['method_export_form_data']['selected_template'] === (string) $original,
            'native Save As retains the previous wizard cursor');
    }
    echo json_encode(['phase' => $phase, 'users' => $ids, 'original' => $original, 'copy' => $copy, 'job' => $job], JSON_THROW_ON_ERROR), "\n";
    return;
}
$name = $args[1] ?? 'Selected users';
$row = $read($name);
$id = (int) $row['id'];
$form = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
if ($phase === 'reopen') {
    // Native page files use include_once: one response per PHP request,
    // matching real AJAX and avoiding a fixture-only empty second render.
    $_POST = ['steps' => ['method_export', 'filter', 'mapping', 'advanced']];
    $response = $newAjax($id)->get_steps([]);
    $selected = [];
    $processor = new WP_HTML_Tag_Processor($response['page_html']['method_export'] ?? '');
    while ($processor->next_tag('OPTION')) {
        if ($processor->get_attribute('selected') !== null) $selected[] = (string) $processor->get_attribute('value');
    }
    $check(in_array((string) $id, $selected, true), 'native wizard selects the actual requested template ID');
    $check(($response['template_data'] ?? null) === $form, 'native wizard reopens every stored form field');
    $check(str_contains($response['page_html']['mapping'] ?? '', $form['mapping_form_data']['mapping_selected_fields']['display_name']),
        'native mapping page renders the saved display header');
    echo json_encode(['id' => $id, 'name' => $name, 'selected' => $selected, 'form' => $form], JSON_THROW_ON_ERROR), "\n";
    return;
}
$check(in_array($phase, ['export', 'rename', 'resave'], true), 'known native template operation');
$job = $run($form, 'templates-' . $phase . '-' . $id);
if ($phase === 'rename') {
    $name = 'Renamed selection';
    $form['mapping_form_data']['mapping_selected_fields']['display_name'] = 'Renamed_Display';
    $form['mapping_form_data']['mapping_fields']['display_name'][0] = 'Renamed_Display';
    $form['filter_form_data']['wt_iew_order_by'] = 'ASC';
}
if ($phase !== 'export') {
    $form['method_export_form_data']['selected_template'] = (string) $id;
    $check($save($form, $name, $id, 'update') === $id, 'native Save retains the target-local row ID');
    $check(json_decode($read($name)['data'], true, flags: JSON_THROW_ON_ERROR) === $form, 'native Save retains the complete reopened form');
}
echo json_encode(['phase' => $phase, 'id' => $id, 'name' => $name, 'job' => $job], JSON_THROW_ON_ERROR), "\n";
