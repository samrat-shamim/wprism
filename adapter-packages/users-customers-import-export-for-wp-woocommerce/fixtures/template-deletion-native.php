<?php
declare(strict_types=1);

$phase = $args[0] ?? '';
if ($phase === 'observe') {
    $args = ['raw-observe'];
    require __DIR__ . '/settings-native.php';
    return;
}
$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('Importer native deletion: ' . $why);
};
$check(is_admin() && current_user_can('manage_options') && defined('WT_U_IEW_VERSION') && WT_U_IEW_VERSION === '2.7.5',
    'exact native administrator context');
global $wpdb, $wp_filter;
$table = $wpdb->prefix . 'wt_iew_mapping_template';
$mapTable = $wpdb->prefix . 'wprism_map';
$stateTable = $wpdb->prefix . 'wprism_state';
$read = static function () use ($wpdb, $table, $check): array {
    $wpdb->last_error = '';
    $rows = $wpdb->get_results("SELECT * FROM `$table` ORDER BY id LIMIT 65", ARRAY_A);
    $check($wpdb->last_error === '' && is_array($rows) && count($rows) <= 64, 'complete bounded native template census');
    return $rows;
};
$owner = static function (string $hook, string $method) use ($wp_filter, $check): object {
    $owners = [];
    foreach ($wp_filter[$hook]->callbacks ?? [] as $group) foreach ($group as $entry) {
        $callback = $entry['function'];
        if (is_array($callback) && is_object($callback[0]) && $callback[1] === $method) $owners[] = $callback[0];
    }
    $check(count($owners) === 1, 'one actual registered callback for ' . $hook);
    return $owners[0];
};
$select = static function (array $rows) use ($check): array {
    $selected = [];
    foreach (['export-original' => ['export', 'Selected users'], 'import-original' => ['import', 'Selected users'],
        'import-draft' => ['import', 'Draft input mapping']] as $label => [$type, $name]) {
        $matches = array_values(array_filter($rows, static fn(array $row): bool => $row['template_type'] === $type
            && $row['item_type'] === 'user' && $row['name'] === $name));
        $check(count($matches) <= 1, 'unambiguous native selection ' . $label);
        if ($matches !== []) $selected[$label] = $matches[0];
    }
    return $selected;
};
$readIdentities = static function (array $localIds) use ($wpdb, $mapTable, $check): array {
    $localIds = array_values(array_unique(array_map('intval', $localIds)));
    sort($localIds, SORT_NUMERIC);
    $check(count($localIds) === 3 && $localIds[0] > 0, 'three bounded positive identity-map keys');
    $placeholders = implode(',', array_fill(0, count($localIds), '%d'));
    $wpdb->last_error = '';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT uuid, entity_type, id_kind, local_id FROM `$mapTable` WHERE id_kind=%s AND local_id IN ($placeholders) ORDER BY local_id",
        ['iew_template', ...$localIds]
    ), ARRAY_A);
    $check($wpdb->last_error === '' && is_array($rows), 'exact template identity-map read');
    return $rows;
};
$readLedger = static function (array $identities) use ($wpdb, $mapTable, $stateTable, $check): array {
    $uuids = array_values(array_unique(array_column($identities, 'uuid')));
    sort($uuids, SORT_STRING);
    $check(count($uuids) === 3
        && array_reduce($uuids, static fn(bool $valid, string $uuid): bool => $valid
            && preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $uuid) === 1, true),
        'three bounded canonical ledger identities');
    $placeholders = implode(',', array_fill(0, count($uuids), '%s'));
    $wpdb->last_error = '';
    $map = $wpdb->get_results($wpdb->prepare(
        "SELECT uuid, entity_type, id_kind, local_id FROM `$mapTable` WHERE uuid IN ($placeholders) ORDER BY uuid, id_kind",
        $uuids
    ), ARRAY_A);
    $check($wpdb->last_error === '' && is_array($map), 'exact selected identity-map read');
    $wpdb->last_error = '';
    $state = $wpdb->get_results($wpdb->prepare(
        "SELECT uuid, entity_type, content_hash FROM `$stateTable` WHERE uuid IN ($placeholders) ORDER BY uuid",
        $uuids
    ), ARRAY_A);
    $check($wpdb->last_error === '' && is_array($state), 'exact selected identity-state read');
    return ['format' => 'wprism-importer-template-ledger/v1', 'map' => $map, 'state' => $state];
};
if ($phase === 'policy') {
    require_once WPMU_PLUGIN_DIR . '/wprism/src/Delete/ExecutableOwnerBoundary.php';
    $pin = json_decode((string) stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    $slug = 'users-customers-import-export-for-wp-woocommerce';
    $plugin = $slug . '/' . $slug . '.php';
    $check(($pin['name'] ?? null) === $slug && ($pin['source'] ?? null) === 'shipped'
        && preg_match('/^[a-f0-9]{64}$/D', $pin['digest'] ?? '') === 1, 'public shipped manifest pin');
    $check(get_option('active_plugins') === [$plugin] && get_option('stylesheet') === 'twentytwentyfive'
        && get_option('template') === 'twentytwentyfive', 'reviewed complete plugin and theme roster');
    $owners = [WPrism\ExecutableOwnerBoundary::observe_owner('plugin:' . $plugin),
        WPrism\ExecutableOwnerBoundary::observe_owner('theme:twentytwentyfive')];
    $check($owners[0]['code_identity'] === ['format' => 'wprism-executable-tree/v1', 'root' => 'plugins/' . $slug,
        'sha256' => '5aa9ac5e9fc7dc10a3ad38ea3ce324592b952937d56c171e8129879fea888a2a'], 'exact reviewed Importer executable tree');
    // WordPress 7.1 archive d1ae02b5ae18428031ffc3943659fa87ab361d827f4aa804adf9276e4dc75df6:
    // its 99 theme PHP files only register/render styles, patterns and post formats.
    $check($owners[1]['code_identity'] === ['format' => 'wprism-executable-tree/v1', 'root' => 'themes/twentytwentyfive',
        'sha256' => 'cd67707d217b90bfc1876bb9750189ec7f3ab49c1ad898d5c35fae5256a0581d'], 'exact reviewed core-bundled theme');
    $owners[0]['rationale'] = 'Official 2.7.5 native template deletion removes one row; independent copies and history contain complete form snapshots.';
    $owners[1]['rationale'] = 'Exact core-bundled theme only registers styles, patterns and post-format bindings; it stores no Importer reverse references.';
    $path = '/home/wprism/site/site.wprism.json';
    $site = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    $site['manifests'] = [...array_values(array_filter($site['manifests'], static fn($entry): bool => (is_string($entry) ? $entry : $entry['name']) !== $slug)), $pin];
    $site['policy']['deletion_owner_agreements'] = ['format' => 'wprism-deletion-owner-agreements/v2',
        'selectors' => [['selector' => 'table:wt_iew_mapping_template', 'owners' => $owners]]];
    $bytes = json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $check(file_put_contents($path, $bytes) === strlen($bytes), 'complete owned fixture policy publication');
    echo json_encode(['pin' => $pin, 'owners' => $owners], JSON_THROW_ON_ERROR), "\n";
    return;
}
if ($phase === 'repository') {
    require_once WPMU_PLUGIN_DIR . '/wprism/src/Repository/RepositoryCompiler.php';
    require_once WPMU_PLUGIN_DIR . '/wprism/src/Apply/ColumnInputFiles.php';
    $policy = WPrism\Policy::load('/home/wprism/site');
    $compiled = WPrism\RepositoryCompiler::compile('/home/wprism/site', $policy);
    echo json_encode(['revision' => $compiled->revision_hash(), 'tree' => $compiled->tree(),
        'deletions' => $compiled->deletions(), 'bindings' => WPrism\ColumnInputFiles::declarations($policy, $compiled->tree())],
        JSON_THROW_ON_ERROR), "\n";
    return;
}
if ($phase === 'identity-ledger') {
    $selected = $select($read());
    $ids = array_map(static fn(array $row): int => (int) $row['id'], $selected);
    sort($ids, SORT_NUMERIC);
    $identities = $readIdentities($ids);
    $check(array_map('intval', array_column($identities, 'local_id')) === $ids, 'complete selected identity-map preimage');
    foreach ($identities as $identity) {
        $check(array_keys($identity) === ['uuid', 'entity_type', 'id_kind', 'local_id']
            && preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $identity['uuid']) === 1
            && $identity['entity_type'] === 'wt_iew_mapping_template' && $identity['id_kind'] === 'iew_template',
            'exact typed template identity-map row');
    }
    usort($identities, static fn(array $left, array $right): int => [$left['uuid'], $left['id_kind']] <=> [$right['uuid'], $right['id_kind']]);
    $ledger = $readLedger($identities);
    $check($ledger['map'] === $identities && count($ledger['state']) === 3, 'complete selected ledger preimage');
    echo json_encode($ledger, JSON_THROW_ON_ERROR), "\n";
    return;
}
if ($phase === 'observe-ledger') {
    $baseline = json_decode((string) stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    $check(is_array($baseline) && ($baseline['format'] ?? null) === 'wprism-importer-template-ledger/v1'
        && array_keys($baseline) === ['format', 'map', 'state'] && count($baseline['map']) === 3,
        'complete selected ledger observation context');
    echo json_encode($readLedger($baseline['map']), JSON_THROW_ON_ERROR), "\n";
    return;
}
if ($phase === 'seed') {
    $before = $read();
    $check(count($before) === 5, 'native export/import Save and Save As produced five templates');
    $imports = array_values(array_filter($before, static fn(array $row): bool => $row['template_type'] === 'import'
        && $row['item_type'] === 'user' && $row['name'] === 'Reusable input mapping'));
    $check(count($imports) === 1, 'one original native saved import');
    $import = $owner('wp_ajax_iew_import_ajax_basic', 'ajax_main');
    $import->import_method = 'template';
    $initialized = $import->process_action([], 'import', 'user');
    $check($initialized['response'] === false && $initialized['history_id'] === 0, 'native no-job initializer');
    require_once WT_U_IEW_PLUGIN_PATH . 'admin/modules/import/classes/class-import-ajax.php';
    $form = json_decode($imports[0]['data'], true, 32, JSON_THROW_ON_ERROR);
    $_POST = ['template_name' => 'Selected users',
        'form_data' => wp_slash(array_map(static fn(array $part): string => wp_json_encode($part), $form))];
    $ajax = new Wt_Import_Export_For_Woo_User_Basic_Import_Ajax($import, 'user', $import->get_steps(), 'template', (int) $imports[0]['id'], 0);
    $saved = $ajax->do_save_template('update', []);
    $check(($saved['status'] ?? null) === 1 && (int) $saved['id'] === (int) $imports[0]['id'],
        'native rename keeps distinct import/export identities with the same name');
    foreach ([['id' => 1001, 'template_type' => 'Import', 'item_type' => 'user', 'name' => 'Selected users', 'data' => '{local-case'],
        ['id' => 1002, 'template_type' => 'export', 'item_type' => 'product', 'name' => 'Selected users', 'data' => '{local-product']] as $row) {
        $check($wpdb->insert($table, $row) === 1, 'fresh excluded native row');
    }
    $selected = $select($read());
    $check(count($selected) === 3, 'exact original export, original import and blank draft');
    echo json_encode(['selected' => $selected], JSON_THROW_ON_ERROR), "\n";
    return;
}
if ($phase === 'restore-fixture') {
    // This prepares the target preimage after native source Delete/Capture.
    // It is deliberately separate from the signed product rollback proof.
    $input = json_decode((string) stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    $baseline = $input['native'] ?? null;
    $ledger = $input['ledger'] ?? null;
    $check(is_array($baseline) && is_array($ledger)
        && ($ledger['format'] ?? null) === 'wprism-importer-template-ledger/v1'
        && array_keys($ledger) === ['format', 'map', 'state']
        && count($ledger['map']) === 3 && count($ledger['state']) === 3, 'complete target preimage input');
    $rows = $baseline['tables']['wt_iew_mapping_template'] ?? [];
    $selected = $select($rows);
    $check(count($rows) === 7 && count($selected) === 3 && $select($read()) === [], 'owned missing-row fixture preimage');
    $ids = array_map(static fn(array $row): int => (int) $row['id'], $selected);
    sort($ids, SORT_NUMERIC);
    $check($readIdentities($ids) === [] && $readLedger($ledger['map'])['map'] === [],
        'selected local identity bindings are absent after source Capture');
    $check($wpdb->query('START TRANSACTION') !== false, 'fixture restoration transaction');
    try {
        foreach ($selected as $row) $check($wpdb->insert($table, $row) === 1, 'restore one exact owned fixture row');
        foreach ($ledger['map'] as $row) {
            $check($wpdb->insert($mapTable, $row, ['%s', '%s', '%s', '%d']) === 1, 'restore one exact target identity binding');
        }
        foreach ($ledger['state'] as $row) {
            $removed = $wpdb->delete($stateTable, ['uuid' => $row['uuid']], ['%s']);
            $check($removed === 0 || $removed === 1, 'replace at most one selected target state row');
            $check($wpdb->insert($stateTable, $row, ['%s', '%s', '%s']) === 1, 'restore one exact target state binding');
        }
        $check($read() === $rows, 'fixture restoration recreates the complete template census');
        $check($readLedger($ledger['map']) === $ledger, 'fixture restoration recreates the exact target ledger preimage');
        $check($wpdb->query('COMMIT') !== false, 'fixture restoration commit');
    } catch (Throwable $failure) {
        $wpdb->query('ROLLBACK');
        throw $failure;
    }
    echo json_encode(['restored' => array_keys($selected), 'identity_uuids' => array_column($ledger['map'], 'uuid')], JSON_THROW_ON_ERROR), "\n";
    return;
}
if ($phase === 'delete') {
    $label = $args[1] ?? '';
    $selected = $select($read());
    $check(isset($selected[$label]), 'one existing selected native row');
    $owner('wp_ajax_wt_iew_delete_template', 'delete_template');
    $_REQUEST = $_POST = ['_wpnonce' => wp_create_nonce(WT_IEW_PLUGIN_ID_BASIC), 'template_id' => $selected[$label]['id']];
    do_action('wp_ajax_wt_iew_delete_template');
    throw new RuntimeException('native template Delete did not terminate its response');
}
if ($phase === 'history-reopen') {
    $id = $args[1] ?? '';
    $check(is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id) === 1, 'exact native history identity');
    $owner('wp_ajax_iew_export_ajax_basic', 'ajax_main');
    $_REQUEST = $_POST = ['_wpnonce' => wp_create_nonce(WT_IEW_PLUGIN_ID_BASIC), 'rerun_id' => $id,
        'to_export' => 'user', 'export_action' => 'get_steps', 'data_type' => 'json', 'steps' => ['method_export']];
    do_action('wp_ajax_iew_export_ajax_basic');
    throw new RuntimeException('native history reopen did not terminate its response');
}
throw new RuntimeException('unknown native deletion fixture phase');
