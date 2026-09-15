<?php
declare(strict_types=1);

$phase = $args[0] ?? '';
$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('Importer native saved imports: ' . $why);
};
$check(is_admin() && current_user_can('manage_options') && defined('WT_U_IEW_VERSION') && WT_U_IEW_VERSION === '2.7.5', 'locked native administrator context');
// All job data is synthetic and pair-local; notification hooks must not send mail.
add_filter('pre_wp_mail', static fn() => true);
global $wpdb, $wp_filter;
$owners = [];
foreach ($wp_filter['wp_ajax_iew_import_ajax_basic']->callbacks ?? [] as $group) foreach ($group as $entry) {
    $callback = $entry['function'];
    if (is_array($callback) && is_object($callback[0]) && $callback[1] === 'ajax_main') $owners[] = $callback[0];
}
$check(count($owners) === 1, 'one actual registered native importer');
$import = $owners[0];
$import->import_method = 'template';
require_once WT_U_IEW_PLUGIN_PATH . 'admin/modules/import/classes/class-import-ajax.php';
$table = $wpdb->prefix . 'wt_iew_mapping_template';
$newAjax = static fn(int $id) => new Wt_Import_Export_For_Woo_User_Basic_Import_Ajax($import, 'user', $import->get_steps(), 'template', $id, 0);
$read = static function (string $name) use ($wpdb, $table, $check): array {
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE BINARY template_type='import' AND BINARY item_type='user' AND BINARY name=%s", $name), ARRAY_A);
    $check($wpdb->last_error === '' && count($rows) === 1, 'one exact saved import row');
    return $rows[0];
};
$postForm = static function (array $form): void {
    $_POST = ['form_data' => wp_slash(array_map(static fn(array $part): string => wp_json_encode($part), $form))];
};
$save = static function (array $form, string $name, int $id, string $verb) use ($newAjax, $postForm, $check): int {
    $postForm($form);
    $_POST['template_name'] = wp_slash($name);
    $response = $newAjax($id)->do_save_template($verb, []);
    $check(($response['status'] ?? null) === 1 && (int) ($response['id'] ?? 0) > 0, 'native template ' . $verb);
    return (int) $response['id'];
};
$initialized = $import->process_action([], 'import', 'user');
$check($initialized['response'] === false && $initialized['history_id'] === 0, 'native no-job request initializer');
if (str_starts_with($phase, 'setup-')) {
    $side = substr($phase, 6);
    $generatedCase = str_ends_with($side, '-generated');
    if ($generatedCase) $side = substr($side, 0, -10);
    $check(in_array($side, ['source', 'target'], true), 'known setup side');
    $check((int) $wpdb->get_var("SELECT COUNT(*) FROM `$table` WHERE BINARY template_type='import' AND BINARY item_type='user'") === 0, 'setup cannot replace existing imports');
    $login = 'import-template-reader';
    $check(!username_exists($login), 'fresh local synthetic user');
    $email = $login . '-' . $side . '@example.test';
    $id = wp_insert_user(['user_login' => $login, 'user_email' => $email, 'display_name' => 'Before import',
        'role' => 'subscriber', 'user_pass' => wp_generate_password(32)]);
    $check(is_int($id) && $id > 1, 'native synthetic user creation');
    $files = $side === 'source' ? ['source-input.csv' => 'Source input']
        : ['target-input.csv' => 'Target input', 'rotated-input.csv' => 'Rotated input'];
    if ($generatedCase) $files['generated-password-' . $side . '.csv'] = 'Generated ' . $side;
    foreach ($files as $filename => $display) {
        $path = $import->get_file_path($filename);
        $check(!file_exists($path), 'setup cannot replace an existing input');
        if (str_starts_with($filename, 'generated-password-')) {
            $generatedLogin = 'generated-password-reader';
            $generatedEmail = $generatedLogin . '-' . $side . '@example.test';
            $bytes = "Login,Email,Display\n$generatedLogin,$generatedEmail,$display\n";
        } else {
            $password = wp_generate_password(32, false);
            $bytes = "Login,Email,Display,Password,LocalDisplay\n$login,$email,$display,$password,Local input\n";
        }
        $check(file_put_contents($path, $bytes) === strlen($bytes), 'complete independent native CSV');
    }
    $fixture = json_decode(file_get_contents(__DIR__ . '/native-import-templates.json'), true, flags: JSON_THROW_ON_ERROR);
    $form = $fixture['form'];
    $form['method_import_form_data']['selected_template'] = '0';
    $form['method_import_form_data']['wt_iew_local_file'] = $import->get_file_url($side . '-input.csv');
    if ($side === 'target') {
        $form['mapping_form_data']['mapping_selected_fields']['display_name'] = '{LocalDisplay}';
        $form['mapping_form_data']['mapping_fields']['display_name'] = ['{LocalDisplay}', 1];
    }
    $original = $save($form, 'Reusable input mapping', 0, 'save');
    $form['method_import_form_data']['selected_template'] = (string) $original;
    $check($save($form, 'Reusable input mapping', $original, 'update') === $original, 'native Update retains identity');
    $copy = $draft = $generated = null;
    if ($side === 'source') {
        $copy = $save($form, 'Reusable input copy', $original, 'save_as');
        $check($copy !== $original && json_decode($read('Reusable input copy')['data'], true)['method_import_form_data']['selected_template'] === (string) $original,
            'native Save As keeps the old wizard cursor in a distinct row');
        $form['method_import_form_data']['wt_iew_local_file'] = '';
        $draft = $save($form, 'Draft input mapping', $original, 'save_as');
        $check($draft !== $original && $draft !== $copy, 'blank native draft has a distinct row');
        if ($generatedCase) {
            $generatedForm = $fixture['form'];
            $generatedForm['method_import_form_data']['selected_template'] = '0';
            $generatedForm['method_import_form_data']['wt_iew_local_file'] = $import->get_file_url('generated-password-source.csv');
            $generatedForm['mapping_form_data']['mapping_fields']['user_pass'] = ['', 0];
            unset($generatedForm['mapping_form_data']['mapping_selected_fields']['user_pass']);
            $generated = $save($generatedForm, 'Generated password mapping', 0, 'save');
            $check(!in_array($generated, [$original, $copy, $draft], true), 'password-omitting native template has a distinct row');
        }
    }
    echo json_encode(['phase' => $phase, 'user' => $id, 'original' => $original, 'copy' => $copy, 'draft' => $draft,
        'generated' => $generated], JSON_THROW_ON_ERROR), "\n";
    return;
}
if (in_array($phase, ['bind', 'rotate', 'bindings'], true)) {
    require_once WPMU_PLUGIN_DIR . '/wprism/src/Apply/Apply.php';
    require_once WPMU_PLUGIN_DIR . '/wprism/src/Apply/ColumnInputFiles.php';
    require_once WPMU_PLUGIN_DIR . '/wprism/src/Repository/RepositoryCompiler.php';
    $policy = WPrism\Policy::load('/siterepo');
    $tree = WPrism\RepositoryCompiler::compile('/siterepo', $policy)->tree();
    $declarations = WPrism\ColumnInputFiles::declarations($policy, $tree);
    $bound = [];
    foreach ($declarations as $name => $declaration) {
        $templateName = $tree[$declaration['uuid']]['data']['columns']['name'];
        if ($phase === 'rotate' && $templateName !== 'Reusable input mapping') continue;
        if ($phase !== 'bindings') {
            $filename = $phase === 'rotate' ? 'rotated-input.csv'
                : ($templateName === 'Generated password mapping' ? 'generated-password-target.csv' : 'target-input.csv');
            WPrism\Apply::set_env_option('/siterepo', $name, $filename);
        }
        $bound[$templateName] = $name;
    }
    $expectedNames = ['Reusable input copy', 'Reusable input mapping'];
    if (isset($bound['Generated password mapping'])) $expectedNames[] = 'Generated password mapping';
    sort($expectedNames, SORT_STRING);
    $boundNames = array_keys($bound);
    sort($boundNames, SORT_STRING);
    $check($phase === 'rotate' ? $boundNames === ['Reusable input mapping'] : $boundNames === $expectedNames,
        'binding scope is exact');
    echo json_encode(['phase' => $phase, 'bindings' => $bound], JSON_THROW_ON_ERROR), "\n";
    return;
}
$name = $args[1] ?? 'Reusable input mapping';
$row = $read($name);
$id = (int) $row['id'];
$form = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
if ($phase === 'reopen') {
    $_POST = ['steps' => ['method_import']];
    $response = $newAjax($id)->get_steps([]);
    $check($response['status'] === 1 && $response['template_data'] === $form, 'native reopen returns the entire saved form');
    $parser = new WP_HTML_Tag_Processor($response['page_html']['method_import']);
    $local = null;
    $selected = [];
    while ($parser->next_tag()) {
        if ($parser->get_tag() === 'INPUT' && $parser->get_attribute('name') === 'wt_iew_local_file') $local = $parser->get_attribute('value');
        if ($parser->get_tag() === 'OPTION' && $parser->get_attribute('selected') !== null) $selected[] = $parser->get_attribute('value');
    }
    $check($local === $form['method_import_form_data']['wt_iew_local_file'] && in_array((string) $id, $selected, true), 'native controls use this row and its target input');
    echo json_encode(['phase' => $phase, 'id' => $id, 'local_file' => $local, 'form' => $form, 'selected' => $selected], JSON_THROW_ON_ERROR), "\n";
    return;
}
if ($phase === 'resave') {
    $form['method_import_form_data']['selected_template'] = (string) $id;
    $check($save($form, $name, $id, 'update') === $id, 'native resave retains target identity');
    echo json_encode(['phase' => $phase, 'row' => $read($name)], JSON_THROW_ON_ERROR), "\n";
    return;
}
$check($phase === 'consume', 'known native phase');
$generatedPassword = $name === 'Generated password mapping';
$generatedUser = $generatedPassword ? get_user_by('login', 'generated-password-reader') : false;
$existingUser = $generatedUser instanceof WP_User;
$passwordBefore = $existingUser ? $generatedUser->user_pass : null;
$postForm($form);
$validated = $newAjax($id)->validate_file(['msg' => '']);
$check(($validated['status'] ?? null) === 1, 'native validates the materialized saved input');
$import->temp_import_file = $validated['file_name'];
$download = $import->process_download($form, 'download', 'user');
$check($download['response'] === true && (int) $download['history_id'] > 0, 'actual native job created');
$run = $import->process_action([], 'import', 'user', '', (int) $download['history_id'], 0);
$check($run['response'] === true && (int) $run['finished'] === 1 && (int) $run['total_success'] === 1, 'actual native import completes');
$user = get_user_by('login', $generatedPassword ? 'generated-password-reader' : 'import-template-reader');
if ($generatedPassword) {
    $check($user instanceof WP_User && $user->display_name === 'Generated target'
        && str_ends_with($user->user_email, '-target@example.test') && $user->user_pass !== '', 'omitted-password mapping consumes the target CSV and retains a password hash');
    $preserved = $existingUser ? hash_equals((string) $passwordBefore, $user->user_pass) : null;
    $check(!$existingUser || $preserved === true, 'existing-user merge preserves the generated password');
    echo json_encode(['phase' => $phase, 'history_id' => $download['history_id'], 'run' => $run,
        'display_name' => $user->display_name, 'existing_user' => $existingUser,
        'password_hash_nonempty' => true, 'password_preserved' => $preserved], JSON_THROW_ON_ERROR), "\n";
    return;
}
$expected = str_ends_with($form['method_import_form_data']['wt_iew_local_file'], '/rotated-input.csv') ? 'Rotated input' : 'Target input';
$check($user instanceof WP_User && $user->display_name === $expected && str_ends_with($user->user_email, '-target@example.test'), 'saved mapping consumes the independent target CSV profile');
echo json_encode(['phase' => $phase, 'history_id' => $download['history_id'], 'run' => $run, 'display_name' => $user->display_name], JSON_THROW_ON_ERROR), "\n";
