<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once "$root/sandbox/tests/lib/check.php";
require_once dirname(__DIR__, 2) . '/fixtures/apply-evidence.php';
$uuid = static fn(int $n): string => sprintf('12345678-1234-7123-8123-%012d', $n);
$intent = [];
foreach (['export', 'import'] as $i => $kind) $intent[] = ['id' => 101 + $i, 'uuid' => $uuid($i + 1), 'kind' => $kind,
    'hash' => str_repeat((string) ($i + 1), 64), 'desired_hash' => str_repeat((string) ($i + 5), 64), 'form' => ['mapping_form_data' => ['mapping_selected_fields' => ['billing_city' => 'New city']],
        'method_' . $kind . '_form_data' => []]];
$session = static fn(string $nonce, int $time): string => json_encode(['artifact_hash' => str_repeat('a', 64),
    'begun_at' => $time, 'owner' => 'direct-' . str_repeat($nonce, 32), 'session_id' => 'ps-' . str_repeat($nonce, 32)], JSON_THROW_ON_ERROR);
$before = [
    'wp_wt_iew_mapping_template' => [
        ['id' => 101, 'template_type' => 'export', 'item_type' => 'user', 'name' => 'Selected users', 'data' => '{"old":true}'],
        ['id' => 102, 'template_type' => 'import', 'item_type' => 'user', 'name' => 'Reusable input mapping', 'data' => '{"old":true}'],
        ['id' => 103, 'template_type' => 'export', 'item_type' => 'product', 'name' => 'Local product mapping', 'data' => 'keep-excluded'],
    ],
    'wp_wprism_state' => [
        ['uuid' => $uuid(1), 'entity_type' => 'wt_iew_mapping_template', 'content_hash' => str_repeat('d', 64)],
        ['uuid' => $uuid(2), 'entity_type' => 'wt_iew_mapping_template', 'content_hash' => str_repeat('e', 64)],
        ['uuid' => $uuid(3), 'entity_type' => 'product', 'content_hash' => str_repeat('f', 64)],
    ],
    'wp_wprism_map' => [
        ['uuid' => $uuid(1), 'entity_type' => 'wt_iew_mapping_template', 'id_kind' => 'iew_template', 'local_id' => 101],
        ['uuid' => $uuid(2), 'entity_type' => 'wt_iew_mapping_template', 'id_kind' => 'iew_template', 'local_id' => 102],
    ],
    'wp_wprism_kv' => [['k' => 'applied_revision', 'v' => str_repeat('b', 40)], ['k' => 'promotion_session', 'v' => $session('1', 50)]],
];
$neighbors = ['wp_options', 'wp_posts', 'wp_postmeta', 'wp_users', 'wp_usermeta', 'wp_wt_iew_action_history',
    'wp_wc_customer_lookup', 'wp_wc_orders', 'wp_wc_orders_meta', 'wp_wc_order_addresses', 'wp_wc_order_operational_data',
    'wp_unrecognized_extension', 'wp_wprism_journal', 'wp_wc_product_meta_lookup', 'wp_wc_product_attributes_lookup', 'wp_terms', 'wp_term_taxonomy', 'wp_term_relationships'];
foreach ($neighbors as $name) $before[$name] = [['id' => 11, 'local_value' => 'keep-local']];
ksort($before, SORT_STRING);
// The fixture's SQL emitter is deliberately independent of the production
// reader. Neighbor amounts stay raw DECIMAL and UINT64 literals, not floats.
$read = static function (array $rows, ?string $mutateTable = null, bool $schema = false): array {
    $inventory = ''; $columns = []; $dump = "-- MariaDB dump 10.19 Distrib 11.4\n";
    $quote = static fn(string $value): string => "'" . str_replace(['\\', "'", "\n", "\r"], ['\\\\', "\\'", '\\n', '\\r'], $value) . "'";
    foreach ($rows as $table => $data) {
        $inventory .= "$table\tBASE TABLE\n";
        $fields = array_keys($data[0]);
        $columns[$table] = ''; $definitions = [];
        foreach ($fields as $field) {
            $type = is_int($data[0][$field]) ? 'bigint' : 'longtext';
            $columns[$table] .= "$field\t$type\tNULL\tYES\t\tNULL\t\tselect,insert,update,references\t\n";
            $definitions[] = "  `$field` $type";
        }
        $columns[$table] .= "precision_canary\tdecimal(26,8)\tNULL\tNO\t\t0\t\tselect,insert,update,references\t\n";
        $control = in_array($table, ['wp_wt_iew_mapping_template', 'wp_wprism_state', 'wp_wprism_kv', 'wp_wprism_map'], true);
        if ($control) $columns[$table] = substr($columns[$table], 0, strpos($columns[$table], 'precision_canary'));
        else {
            $definitions[] = '  `precision_canary` decimal(26,8)';
            $definitions[] = '  `wide_identity` bigint unsigned';
            $columns[$table] .= "wide_identity\tbigint unsigned\tNULL\tNO\t\t0\t\tselect,insert,update,references\t\n";
        }
        $dump .= "-- Table structure for table `$table`\nCREATE TABLE `$table` (\n" . implode(",\n", $definitions) . "\n) ENGINE=InnoDB" . ($schema && $table === $mutateTable ? ' ROW_FORMAT=DYNAMIC' : '') . ";\n-- Dumping data for table `$table`\n";
        foreach ($data as $row) {
            $values = array_map(static fn(mixed $value): string => is_int($value) ? (string) $value : $quote($value), array_values($row));
            $names = $fields;
            if (!$control) { $names[] = 'precision_canary'; $values[] = $table === $mutateTable && !$schema ? '20.00000002' : '20.00000001'; $names[] = 'wide_identity'; $values[] = '18446744073709551615'; }
            $dump .= 'INSERT INTO `' . $table . '` (`' . implode('`, `', $names) . '`) VALUES (' . implode(',', $values) . ");\n";
        }
    }
    return ImporterWooApplyEvidence::read($dump . "-- Dump completed\n", $inventory, $columns);
};
$after = $before;
foreach ($intent as $i => $entity) {
    $after['wp_wt_iew_mapping_template'][$i]['data'] = json_encode($entity['form'], JSON_THROW_ON_ERROR);
    $after['wp_wprism_state'][$i]['content_hash'] = $entity['hash'];
}
$after['wp_wprism_kv'][0]['v'] = str_repeat('c', 40);
$after['wp_wprism_kv'][1]['v'] = $session('2', 105);
$oldImage = $read($before); $newImage = $read($after);
$verify = static fn(array $candidate) => ImporterWooApplyEvidence::transition($oldImage, $candidate, $intent,
    str_repeat('c', 40), str_repeat('a', 64), ['before' => 100, 'after' => 110]);
$plan = array_fill_keys(['create', 'adopt', 'collision', 'drift', 'conflict', 'delete', 'delete_conflict', 'deleted',
    'code_mismatch', 'code_drift', 'incomplete_apply', 'incomplete_lifecycle', 'missing_user', 'skipped_user_meta',
    'uploads_inventory', 'selected_actions', 'regen_pending', 'regen_context', 'env_missing', 'warnings', 'provider_problems'], []);
$planIntent = $intent;
foreach ($planIntent as &$entity) $entity['path'] = 'tables/wt_iew_mapping_template/' . $entity['uuid'] . '.json';
unset($entity);
$plan['update'] = array_map(static fn(array $entity): array => ['uuid' => $entity['uuid'], 'type' => 'wt_iew_mapping_template', 'path' => $entity['path']], $planIntent);
$plan['unchanged'] = [['uuid' => $uuid(3)]]; $plan['artifact_hash'] = str_repeat('a', 64);
wprism_check_same(str_repeat('a', 64), ImporterWooApplyEvidence::plan($plan, $planIntent, $oldImage), 'exact Plan binds the two independent updates and every unchanged identity');
$inventoryPlan = $plan;
$inventoryPlan['uploads_inventory'] = [['attachment_uuid' => $uuid(3), 'original_path' => 'woocommerce-placeholder.webp']];
$inventoryPlan['provider_problems'] = [['provider_id' => 'woocommerce-scheduler', 'problem' => 'missing_capability']];
$inventoryPlan['env_missing'] = [['name' => 'optional_gateway_token', 'required' => false]];
wprism_check_same(str_repeat('a', 64), ImporterWooApplyEvidence::plan($inventoryPlan, $planIntent, $oldImage),
    'declared upload/provider inventories and missing optional environment values do not select work');
foreach (['required-env', 'unknown-required', 'missing-inventory', 'non-list-inventory', 'non-row-inventory', 'selected-provider'] as $fault) {
    $badPlan = $inventoryPlan;
    if ($fault === 'required-env') $badPlan['env_missing'][0]['required'] = true;
    if ($fault === 'unknown-required') unset($badPlan['env_missing'][0]['required']);
    if ($fault === 'missing-inventory') unset($badPlan['uploads_inventory']);
    if ($fault === 'non-list-inventory') $badPlan['provider_problems'] = ['unknown' => []];
    if ($fault === 'non-row-inventory') $badPlan['uploads_inventory'] = ['not a row'];
    if ($fault === 'selected-provider') $badPlan['selected_actions'] = [['provider_id' => 'woocommerce-scheduler']];
    wprism_check_throws(static fn() => ImporterWooApplyEvidence::plan($badPlan, $planIntent, $oldImage), RuntimeException::class,
        "Plan inventories do not conceal $fault");
}
foreach (['create', 'warnings', 'selected_actions', 'regen_pending', 'update', 'unchanged', 'artifact_hash'] as $field) {
    $badPlan = $plan;
    if ($field === 'update') $badPlan[$field][] = $plan[$field][0];
    elseif ($field === 'unchanged') $badPlan[$field] = [];
    elseif ($field === 'artifact_hash') $badPlan[$field] = 'unbound';
    else $badPlan[$field] = ['unexpected'];
    wprism_check_throws(static fn() => ImporterWooApplyEvidence::plan($badPlan, $planIntent, $oldImage), RuntimeException::class,
        "unexpected Plan $field refuses before accepting an Apply verdict");
}
$receipt = ['applied' => 2, 'canary' => 'clean', 'drift' => [], 'warnings' => [], 'actions' => []];
ImporterWooApplyEvidence::receipt($receipt, false);
wprism_check(true, 'exact two-template public Apply receipt passes');
foreach (['applied', 'canary', 'drift', 'warnings', 'actions'] as $field) {
    $badReceipt = $receipt; $badReceipt[$field] = $field === 'applied' ? 3 : ($field === 'canary' ? 'dirty' : ['unexpected']);
    wprism_check_throws(static fn() => ImporterWooApplyEvidence::receipt($badReceipt, false), RuntimeException::class,
        "public Apply receipt rejects unexpected $field");
}
$receipt['applied'] = 0; ImporterWooApplyEvidence::receipt($receipt, true);
wprism_check(true, 'repeat public receipt has exactly zero applied entities');
$verify($newImage);
wprism_check(true, 'two intended forms and baseline/revision/session transitions preserve every other observed byte');
foreach ($neighbors as $table) {
    wprism_check_throws(static fn() => $verify($read($after, $table)), RuntimeException::class, "last-digit mutation in $table refuses");
    wprism_check_throws(static fn() => $verify($read($after, $table, true)), RuntimeException::class, "schema mutation in $table refuses");
}
foreach (['template-neighbor', 'template-name', 'template-id', 'stale-form', 'extra-state', 'other-baseline', 'stale-baseline',
    'revision', 'session-replay', 'session-time', 'lease', 'incomplete', 'removed-neighbor'] as $fault) {
    $bad = $after;
    if ($fault === 'template-neighbor') $bad['wp_wt_iew_mapping_template'][2]['data'] = 'changed';
    if ($fault === 'template-name') $bad['wp_wt_iew_mapping_template'][0]['name'] = 'changed';
    if ($fault === 'template-id') $bad['wp_wt_iew_mapping_template'][0]['id'] = 104;
    if ($fault === 'stale-form') $bad['wp_wt_iew_mapping_template'][0]['data'] = '{"old":true}';
    if ($fault === 'extra-state') $bad['wp_wprism_state'][] = ['uuid' => $uuid(4), 'entity_type' => 'product', 'content_hash' => str_repeat('f', 64)];
    if ($fault === 'other-baseline') $bad['wp_wprism_state'][2]['content_hash'] = str_repeat('e', 64);
    if ($fault === 'stale-baseline') $bad['wp_wprism_state'][0]['content_hash'] = str_repeat('d', 64);
    if ($fault === 'revision') $bad['wp_wprism_kv'][0]['v'] = str_repeat('d', 40);
    if ($fault === 'session-replay') $bad['wp_wprism_kv'][1]['v'] = $session('1', 105);
    if ($fault === 'session-time') $bad['wp_wprism_kv'][1]['v'] = $session('2', 111);
    if ($fault === 'lease') $bad['wp_wprism_kv'][] = ['k' => 'promotion_lock', 'v' => '{}'];
    if ($fault === 'incomplete') $bad['wp_wprism_kv'][] = ['k' => 'apply_in_progress', 'v' => '{}'];
    if ($fault === 'removed-neighbor') unset($bad['wp_unrecognized_extension']);
    wprism_check_throws(static fn() => $verify($read($bad)), RuntimeException::class, "unexpected $fault refuses");
}
$wrongMap = $before; $wrongMap['wp_wprism_map'][0]['local_id'] = 102;
$wrongMapAfter = $after; $wrongMapAfter['wp_wprism_map'] = $wrongMap['wp_wprism_map'];
wprism_check_throws(static fn() => ImporterWooApplyEvidence::transition($read($wrongMap), $read($wrongMapAfter), $intent,
    str_repeat('c', 40), str_repeat('a', 64), ['before' => 100, 'after' => 110]), RuntimeException::class,
    'an unchanged but incorrect UUID-to-local-ID binding refuses');
$changedMap = $after; $changedMap['wp_wprism_map'][0]['local_id'] = 102;
wprism_check_throws(static fn() => $verify($read($changedMap)), RuntimeException::class, 'identity-map changes outside the intended baseline hashes refuse');
$repeated = $after; $repeated['wp_wprism_kv'][1]['v'] = $session('3', 205);
ImporterWooApplyEvidence::transition($newImage, $read($repeated), $intent, str_repeat('c', 40), str_repeat('a', 64),
    ['before' => 200, 'after' => 210], true);
wprism_check(true, 'repeat changes only its fresh direct session');
foreach (['promotion_lock', 'apply_in_progress'] as $key) {
    $unsettled = $before; $unsettled['wp_wprism_kv'][] = ['k' => $key, 'v' => '{}'];
    wprism_check_throws(static fn() => ImporterWooApplyEvidence::transition($read($unsettled), $newImage, $intent,
        str_repeat('c', 40), str_repeat('a', 64), ['before' => 100, 'after' => 110]), RuntimeException::class,
        "a lone $key cannot establish the settled premise");
}
// Scoped bookkeeping is generated by the real session state machine. The
// independent database emitter then exposes mutation of any protected cell.
require_once dirname(__DIR__, 2) . '/fixtures/scoped-apply-evidence.php';
$scopeStore = new class implements WPrism\ScopedApplySessionStorage {
    public array $values = [];
    public function read(string $key): ?string { return $this->values[$key] ?? null; }
    public function compare_and_swap(string $key, ?string $expected, ?string $replacement): bool {
        if ($this->read($key) !== $expected) return false;
        if ($replacement === null) unset($this->values[$key]); else $this->values[$key] = $replacement;
        return true;
    }
};
$h = static fn(string $s): string => hash('sha256', $s);
$scopeSource = ['artifact_hash' => str_repeat('a', 64), 'manifest_hash' => $h('manifest'), 'state_revision_hash' => $h('state')];
$scopeLease = ['artifact_hash' => str_repeat('a', 64), 'owner' => 'direct-' . str_repeat('2', 32), 'session_id' => 'ps-' . str_repeat('2', 32)];
$scopeWork = [['identity_hash' => $h($intent[0]['uuid']), 'type' => 'wt_iew_mapping_template', 'desired_hash' => $intent[0]['desired_hash']]];
$scopeSelection = ['work_items' => $scopeWork, 'work_hash' => WPrism\ScopedApplySession::hash_value($scopeWork),
    'capabilities_hash' => $h('capabilities'), 'ledger_map_identity_hashes' => [$h($intent[0]['uuid'])],
    'ledger_map_identity_set_hash' => WPrism\ScopedApplySession::hash_value([$h($intent[0]['uuid'])])];
foreach (['deletion_items' => 'deletions_hash', 'action_items' => 'action_declarations_hash', 'effect_items' => 'effects_hash'] as $items => $hash) {
    $scopeSelection[$items] = []; $scopeSelection[$hash] = WPrism\ScopedApplySession::hash_value([]);
}
$scopeAuthority = WPrism\ScopedApplySession::make_authority($h('scope'), $scopeSource, $scopeLease,
    array_fill_keys(['selected_before_hash', 'selected_before_ledger_map_hash', 'protected_ledger_map_hash', 'protected_out_of_scope_hash', 'ledger_roots_hash'], $h('before')),
    ['precondition_hash' => $h('plan'), 'guard_witnesses_hash' => $h('guards')], $scopeSelection, $h('code'), null,
    WPrism\ScopedApplyRequest::binding('importer-woo-scoped', false));
$scopeSession = WPrism\ScopedApplySession::begin($scopeStore, $scopeAuthority);
$scopeSession->transition(WPrism\ScopedApplySession::PHASE_AUTHORING);
$scopeOperation = ['ordinal' => 1, 'authority_hash' => $scopeAuthority['authority_hash'],
    'lease_hash' => WPrism\ScopedApplySession::lease_hash($scopeLease)];
foreach (['action_hash', 'operation_hash', 'input_hash', 'effect_hash', 'before_hash'] as $key) $scopeOperation[$key] = $h($key);
$scopeSession->append_intent($scopeOperation);
$scopeSession->commit_authored_receipt($scopeOperation + ['after_hash' => $h('after')]);
$scopeSession->transition(WPrism\ScopedApplySession::PHASE_EFFECTS_PENDING);
$scopeSession->transition(WPrism\ScopedApplySession::PHASE_VERIFYING);
$scopeSession->complete($h('convergence'), ['protected_ledger_map_hash' => $h('before'), 'selected_ledger_map_hash' => $h('after-map')]);
$scopeAfter = $before;
$scopeAfter['wp_wt_iew_mapping_template'][0] = $after['wp_wt_iew_mapping_template'][0];
$scopeAfter['wp_wprism_state'][0] = $after['wp_wprism_state'][0];
$scopeAfter['wp_wprism_kv'][1]['v'] = $session('2', 105);
$scopeAfter['wp_wprism_kv'][] = ['k' => 'scoped_apply_session', 'v' => $scopeSession->canonical()];
$scopeReceipt = ['format' => 'wprism-scoped-apply-result/v1', 'applied' => 1,
    'canary' => 'clean', 'drift' => [], 'warnings' => [], 'actions' => [], 'verification' => ['result' => 'pass'],
    'scoped_receipt' => $scopeSession->terminal_receipt()];
$scopeVerify = static fn(array $candidate, ?array $receipt = null) => ImporterWooScopedApplyEvidence::transition($read($before),
    $read($candidate), $intent, $scopeSource, $h('scope'), 'importer-woo-scoped', ['before' => 100, 'after' => 110], $receipt ?? $scopeReceipt, false);
$scopeVerify($scopeAfter);
wprism_check(true, 'one scoped export update preserves the pending import, full revision and all native neighbors');
$scopeRepeatReceipt = array_replace($scopeReceipt, ['applied' => 0, 'replayed' => true, 'verification' => null]);
ImporterWooScopedApplyEvidence::transition($read($scopeAfter), $read($scopeAfter), $intent, $scopeSource, $h('scope'),
    'importer-woo-scoped', ['before' => 200, 'after' => 210], $scopeRepeatReceipt, true);
wprism_check(true, 'terminal replay is byte-stable with the same durable receipt');
foreach (['unselected-template', 'unselected-ledger', 'full-revision', 'extra-key', 'session-tamper', 'missing-session',
    'receipt-mismatch', 'failed-verification', 'false-replay', 'session-time', 'map', 'customer', 'order'] as $fault) {
    $bad = $scopeAfter; $badReceipt = $scopeReceipt;
    if ($fault === 'unselected-template') $bad['wp_wt_iew_mapping_template'][1] = $after['wp_wt_iew_mapping_template'][1];
    if ($fault === 'unselected-ledger') $bad['wp_wprism_state'][1] = $after['wp_wprism_state'][1];
    if ($fault === 'full-revision') $bad['wp_wprism_kv'][0]['v'] = str_repeat('c', 40);
    if ($fault === 'extra-key') $bad['wp_wprism_kv'][] = ['k' => 'unapproved', 'v' => '{}'];
    if ($fault === 'session-tamper') $bad['wp_wprism_kv'][2]['v'] = str_replace('complete', 'verifying', $bad['wp_wprism_kv'][2]['v']);
    if ($fault === 'missing-session') array_pop($bad['wp_wprism_kv']);
    if ($fault === 'receipt-mismatch') $badReceipt['scoped_receipt']['terminal_hash'] = $h('wrong');
    if ($fault === 'failed-verification') $badReceipt['verification']['result'] = 'fail';
    if ($fault === 'false-replay') $badReceipt['replayed'] = true;
    if ($fault === 'session-time') $bad['wp_wprism_kv'][1]['v'] = $session('2', 111);
    if ($fault === 'map') $bad['wp_wprism_map'][0]['local_id'] = 102;
    if ($fault === 'customer') $bad['wp_wc_customer_lookup'][0]['local_value'] = 'changed';
    if ($fault === 'order') $bad['wp_wc_orders'][0]['local_value'] = 'changed';
    wprism_check_throws(static fn() => $scopeVerify($bad, $badReceipt), RuntimeException::class, "scoped evidence rejects $fault");
}
$badRepeat = $scopeAfter; $badRepeat['wp_wprism_kv'][1]['v'] = $session('3', 205);
wprism_check_throws(static fn() => ImporterWooScopedApplyEvidence::transition($read($scopeAfter), $read($badRepeat), $intent,
    $scopeSource, $h('scope'), 'importer-woo-scoped', ['before' => 200, 'after' => 210], $scopeRepeatReceipt, true),
    RuntimeException::class, 'terminal replay cannot mint a fresh promotion session');

$directory = $root . '/sandbox/tmp/importer-woo-apply-files-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$files = [];
foreach (['webtoffee_export', 'webtoffee_import'] as $name) {
    mkdir("$directory/$name", 0700);
    file_put_contents("$directory/$name/local.csv", "local,canary\n1,retained\n");
    $files[$name] = WPrismTest\FilesystemTreeEvidence::capture($directory, $name);
}
try {
    ImporterWooApplyEvidence::files($files, $files);
    wprism_check(true, 'both nonempty native operational file trees are preserved');
    file_put_contents("$directory/webtoffee_import/local.csv", "local,canary\n1,modified\n");
    $changed = $files;
    $changed['webtoffee_import'] = WPrismTest\FilesystemTreeEvidence::capture($directory, 'webtoffee_import');
    wprism_check_throws(static fn() => ImporterWooApplyEvidence::files($files, $changed), RuntimeException::class,
        'a valid same-size native file rewrite refuses');
    $emptyFiles = $files;
    foreach ($emptyFiles as &$tree) $tree['files'] = [];
    unset($tree);
    wprism_check_throws(static fn() => ImporterWooApplyEvidence::files($emptyFiles, $emptyFiles), RuntimeException::class,
        'equal empty file witnesses refuse');
    unset($changed['webtoffee_export']);
    wprism_check_throws(static fn() => ImporterWooApplyEvidence::files($files, $changed), RuntimeException::class,
        'an omitted operational file root refuses');
} finally {
    foreach (['webtoffee_export', 'webtoffee_import'] as $name) { unlink("$directory/$name/local.csv"); rmdir("$directory/$name"); }
    rmdir($directory);
}
$canonicalRoot = $root . '/sandbox/tmp/importer-woo-intent-' . bin2hex(random_bytes(6));
mkdir($canonicalRoot . '/state/tables/wt_iew_mapping_template', 0700, true);
$forms = []; $fronts = []; $targetRows = $before;
foreach (['export', 'import'] as $i => $kind) {
    $forms[$kind] = ['advanced_form_data' => ['wt_iew_batch_count' => 10],
        'method_' . $kind . '_form_data' => [], 'mapping_form_data' => ['label' => 'Retain']];
    $fronts[$kind] = ['uuid' => $uuid($i + 1), 'table' => 'wt_iew_mapping_template',
        'columns' => ['template_type' => $kind, 'item_type' => 'user',
            'name' => $kind === 'export' ? 'Selected users' : 'Reusable input mapping',
            'data' => json_encode($forms[$kind], JSON_THROW_ON_ERROR)]];
    $targetRows['wp_wt_iew_mapping_template'][$i]['data'] = json_encode($forms[$kind], JSON_THROW_ON_ERROR);
}
$writeTree = static function (array $records) use ($canonicalRoot): array {
    foreach ($records as $front) file_put_contents($canonicalRoot . '/state/tables/wt_iew_mapping_template/' . $front['uuid'] . '.json',
        json_encode($front, JSON_THROW_ON_ERROR));
    return WPrismTest\FilesystemTreeEvidence::capture($canonicalRoot, 'state');
};
try {
    $baseline = $writeTree($fronts); $desiredFronts = $fronts;
    foreach (['export', 'import'] as $kind) {
        $forms[$kind]['advanced_form_data']['wt_iew_batch_count'] = 7;
        $desiredFronts[$kind]['columns']['data'] = json_encode($forms[$kind], JSON_THROW_ON_ERROR);
    }
    $desired = $writeTree($desiredFronts); $targetImage = $read($targetRows);
    $derived = ImporterWooApplyEvidence::intent($baseline, $desired, $targetImage);
    wprism_check_same([101, 102], array_column($derived, 'id'), 'intent uses both stable target IDs observed before Apply');
    wprism_check_same([$forms['export'], $forms['import']], array_column($derived, 'form'), 'complete expected forms derive from the converged preimage and named native Save');
    foreach ($derived as $entity) {
        $file = 'tables/wt_iew_mapping_template/' . $entity['uuid'] . '.json';
        wprism_check_same(array_column($desired['files'], 'sha256', 'path')[$file], $entity['hash'], 'baseline hash comes from independently captured desired bytes');
        wprism_check($entity['desired_hash'] !== $entity['hash'], 'scoped semantic hash stays distinct from the file-byte ledger hash');
        wprism_check_same(hash('sha256', WPrism\Canon::encode($desiredFronts[$entity['kind']])), $entity['desired_hash'], 'scoped work hash derives from the independent canonical document');
    }
    foreach (['other-field', 'name', 'wrong-batch', 'one-save'] as $fault) {
        $bad = $desiredFronts;
        if ($fault === 'other-field') { $form = $forms['export']; $form['mapping_form_data']['label'] = 'Different'; $bad['export']['columns']['data'] = json_encode($form, JSON_THROW_ON_ERROR); }
        if ($fault === 'name') $bad['export']['columns']['name'] = 'Different';
        if ($fault === 'wrong-batch') { $form = $forms['export']; $form['advanced_form_data']['wt_iew_batch_count'] = 8; $bad['export']['columns']['data'] = json_encode($form, JSON_THROW_ON_ERROR); }
        if ($fault === 'one-save') $bad['import'] = $fronts['import'];
        wprism_check_throws(static fn() => ImporterWooApplyEvidence::intent($baseline, $writeTree($bad), $targetImage), RuntimeException::class,
            "canonical $fault cannot manufacture the allowed Apply delta");
    }
    file_put_contents($canonicalRoot . '/state/unexpected.json', '{}');
    wprism_check_throws(static fn() => ImporterWooApplyEvidence::intent($baseline, $writeTree($desiredFronts), $targetImage), RuntimeException::class,
        'an extra canonical file cannot be omitted from the authored change roster');
} finally {
    foreach ($fronts as $front) unlink($canonicalRoot . '/state/tables/wt_iew_mapping_template/' . $front['uuid'] . '.json');
    if (is_file($canonicalRoot . '/state/unexpected.json')) unlink($canonicalRoot . '/state/unexpected.json');
    foreach (['/state/tables/wt_iew_mapping_template', '/state/tables', '/state', ''] as $directory) rmdir($canonicalRoot . $directory);
}
require_once "$root/sandbox/tests/lib/agent_version.php";
wprism_test_define_agent_versions();
require_once "$root/agent/src/Policy/Policy.php";
require_once dirname(__DIR__, 2) . '/fixtures/apply-policy.php';
$combinedPolicy = WPrism\Policy::load(null, ['core', 'users-customers-import-export-for-wp-woocommerce', 'woocommerce'],
    adapterLibrary: WPrism\AdapterLibrary::fromSourceTree($root));
$scope = importer_woo_authored_scope($combinedPolicy, ['product', 'shop_order', 'unknown_type'],
    ['product_cat', 'product_type', 'product_visibility', 'unknown_taxonomy'], ['post_types' => [], 'taxonomies' => []]);
wprism_check_same(['product'], $scope['post_types'], 'registered orders stay runtime and unknown types gain no authored authority');
wprism_check_same(['product_cat', 'product_type', 'product_visibility'], $scope['taxonomies'],
    'initialized reviewed Woo taxonomies are selected without inventing an optional fulfillment registry');
$initialized = importer_woo_authored_scope($combinedPolicy, [], ['wc_fulfillment_shipping_provider'], ['post_types' => [], 'taxonomies' => []]);
wprism_check_same(['wc_fulfillment_shipping_provider'], $initialized['taxonomies'],
    'an optional reviewed taxonomy enters scope when its native workflow initializes it');
$coreScope = ['post_types' => ['post', 'page', 'attachment'], 'taxonomies' => ['category', 'post_tag']];
$composedScope = importer_woo_authored_scope($combinedPolicy, ['post', 'page', 'attachment', 'product', 'shop_order'],
    ['category', 'post_tag', 'product_cat'], $coreScope);
wprism_check_same(['attachment', 'page', 'post', 'product'], $composedScope['post_types'],
    'combination scope preserves implicit core post types while adding initialized authored products');
wprism_check_same(['category', 'post_tag', 'product_cat'], $composedScope['taxonomies'],
    'combination scope preserves implicit core taxonomies');
require_once "$root/agent/src/Repository/CompiledArtifact.php";
require_once "$root/agent/src/Repository/SidebarState.php";
$typed = [];
foreach ($planIntent as $entity) {
    $typed[$entity['uuid']] = ['type' => 'wt_iew_mapping_template', 'path' => $entity['path'], 'hash' => $entity['hash'],
        'source_hash' => $entity['hash'], 'data' => ['uuid' => $entity['uuid'], 'table' => 'wt_iew_mapping_template',
            'columns' => ['name' => $entity['kind'], 'template_type' => $entity['kind'], 'item_type' => 'user', 'data' => '{}']]];
}
$compiledScope = WPrism\CompiledRepository::create(['tree' => $typed, 'deletions' => [], 'revision_hash' => $h('state'),
    'manifest_hash' => $h('manifest'), 'site_hash' => $h('site'), 'references' => [], 'media' => []]);
$contract = WPrism\ScopeContract::resolve($compiledScope, $combinedPolicy, ['table:wt_iew_mapping_template:' . $planIntent[0]['uuid']]);
$onePlan = $plan;
$onePlan['format'] = 'wprism-scoped-plan/v1';
$onePlan['scope'] = ['scope_hash' => $contract['scope_hash']];
$onePlan['artifact_hash'] = $compiledScope->artifact_hash();
$onePlan['update'] = [$plan['update'][0]];
$onePlan['unchanged'] = [];
ImporterWooScopedApplyEvidence::plan($onePlan, $contract, $planIntent);
wprism_check(true, 'real scope resolution admits exactly the independently selected export');
foreach (['other-template', 'extra-update', 'missing-update', 'unchanged-import', 'selected-action', 'scope-hash', 'artifact', 'required-env'] as $fault) {
    $bad = $onePlan;
    if ($fault === 'other-template') $bad['update'] = [$plan['update'][1]];
    if ($fault === 'extra-update') $bad['update'][] = $plan['update'][1];
    if ($fault === 'missing-update') $bad['update'] = [];
    if ($fault === 'unchanged-import') $bad['unchanged'][] = ['uuid' => $planIntent[1]['uuid']];
    if ($fault === 'selected-action') $bad['selected_actions'][] = ['manifest' => 'woocommerce'];
    if ($fault === 'scope-hash') $bad['scope']['scope_hash'] = $h('wrong');
    if ($fault === 'artifact') $bad['artifact_hash'] = $h('wrong');
    if ($fault === 'required-env') $bad['env_missing'][] = ['name' => 'token', 'required' => true];
    wprism_check_throws(static fn() => ImporterWooScopedApplyEvidence::plan($bad, $contract, $planIntent), RuntimeException::class,
        "scoped plan evidence rejects $fault");
}

// Run the actual contract transport block with only the host executable seam
// replaced. A plugin-loaded agent scope call cannot satisfy this probe.
$driver = file_get_contents(dirname(__DIR__, 2) . '/tests/live/regress_importer_woocommerce_apply.sh');
$start = strpos($driver, "  php -r '$" . 'config=');
$end = strpos($driver, '  combo_capture scoped-plan', $start === false ? 0 : $start);
if ($start === false || $end === false) throw new RuntimeException('missing isolated scope transport block');
$transportBlock = substr($driver, $start, $end - $start);
$transportRoot = sys_get_temp_dir() . '/importer-woo-scope-route-' . bin2hex(random_bytes(6));
foreach (['', '/cli', '/sink', '/repo', '/sandbox'] as $relative) mkdir($transportRoot . $relative, 0700);
$host = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
[ "$#" -eq 6 ]
[ "$1" = "--envs-file=$TEST_ROOT/sink/envs.json" ]
[ "$2 $3" = 'scope source' ]
[ "$4" = "--roots=table:wt_iew_mapping_template:$TEST_UUID" ]
[ "$5 $6" = '--contract --format=json' ]
cat "$TEST_ROOT/contract.json"
SH;
$probe = <<<'SH'
set -euo pipefail
umask 077
ROOT="$1"; sink="$ROOT/sink"; R2="$ROOT/repo"; export_uuid="$2"
export TEST_ROOT="$ROOT" TEST_UUID="$export_uuid"
combo_capture() { local label="$1"; shift; "$@" > "$sink/$label.stdout"; }
SH;
file_put_contents($transportRoot . '/cli/wprism', $host);
chmod($transportRoot . '/cli/wprism', 0700);
file_put_contents($transportRoot . '/probe.sh', $probe . "\n" . $transportBlock);
file_put_contents($transportRoot . '/contract.json', WPrism\Canon::encode($contract));
try {
    $process = proc_open(['/bin/bash', $transportRoot . '/probe.sh', $transportRoot, $planIntent[0]['uuid']],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('cannot run scope transport probe');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    wprism_check(proc_close($process) === 0 && $output === '' && $error === '', 'actual scope transport uses the isolated host CLI with exact selector');
    $copied = $transportRoot . '/repo/.tmp-importer-woo-export-scope.json';
    wprism_check(file_get_contents($copied) === WPrism\Canon::encode($contract) && (fileperms($copied) & 0777) === 0644,
        'public contract copy preserves bytes and is readable by the target UID');
    $scopeStem = $transportRoot . '/sink/export-scope';
    foreach (['stderr' => " Container wprism-routeproof-cli1-run-123456789abc Creating \n", 'exit' => "0\n"] as $suffix => $bytes) {
        file_put_contents($scopeStem . '.' . $suffix, $bytes); chmod($scopeStem . '.' . $suffix, 0600);
    }
    wprism_check_same(WPrism\Canon::encode($contract), WPrism\Canon::encode(ImporterWooScopedApplyEvidence::sourceContract($scopeStem, 'routeproof')),
        'source contract admits only the source container transport');
    foreach ([" Container wprism-routeproof-cli2-run-123456789abc Creating \n", "unexpected operator output\n"] as $badDiagnostic) {
        file_put_contents($scopeStem . '.stderr', $badDiagnostic);
        wprism_check_throws(static fn() => ImporterWooScopedApplyEvidence::sourceContract($scopeStem, 'routeproof'), RuntimeException::class,
            'source contract refuses target transport and unexpected diagnostics');
    }
    $configuration = json_decode(file_get_contents($transportRoot . '/sink/envs.json'), true, flags: JSON_THROW_ON_ERROR);
    wprism_check_same(['envs' => ['source' => ['transport' => 'docker', 'compose_file' => $transportRoot . '/sandbox/pair.yml',
        'service' => 'cli1', 'repo_path' => '/siterepo']]], $configuration, 'scope host transport selects only the source service');
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($transportRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($transportRoot);
}

require_once dirname(__DIR__, 2) . '/fixtures/catalog-evidence.php';
$catalog = ['products' => [], 'lookup' => []];
foreach (['simple', 'variable', 'variation'] as $i => $type) {
    $sku = 'combo-' . $type;
    $catalog['products'][$sku] = ['id' => 501 + $i, 'type' => $type, 'parent' => $type === 'variation' ? 502 : 0,
        'sku' => $sku, 'name' => 'Combined ' . $type, 'status' => 'publish',
        'regular_price' => $type === 'simple' ? '19.95' : '29.95', 'price' => $type === 'simple' ? '19.95' : '29.95',
        'manage_stock' => $type !== 'variable', 'stock' => $type === 'simple' ? 37 : ($type === 'variation' ? 41 : null),
        'stock_status' => 'instock', 'categories' => [23],
        'attributes' => $type === 'variable' ? ['pa_combosize' => ['options' => [24], 'variation' => true]] : ($type === 'variation' ? ['pa_combosize' => 'small'] : []),
        'defaults' => $type === 'variable' ? ['pa_combosize' => 'small'] : []];
    $catalog['lookup'][] = ['product_id' => (string) (501 + $i), 'sku' => $sku, 'stock_status' => 'instock',
        'stock_quantity' => $type === 'simple' ? '37' : ($type === 'variation' ? '41' : null)];
}
ImporterWooCatalogEvidence::preserved($catalog, $catalog);
wprism_check(true, 'populated native catalog with target-local stock and bound lookup rows');
foreach (['empty', 'missing', 'alias', 'stock', 'source-stock', 'price', 'parent', 'attribute', 'category', 'lookup-empty', 'lookup-alias', 'lookup-stock'] as $fault) {
    $bad = $catalog;
    if ($fault === 'empty') $bad['products'] = [];
    if ($fault === 'missing') unset($bad['products']['combo-simple']);
    if ($fault === 'alias') $bad['products']['combo-simple']['id'] = 502;
    if ($fault === 'stock') $bad['products']['combo-variation']['stock'] = 0;
    if ($fault === 'source-stock') $bad['products']['combo-simple']['stock'] = 19;
    if ($fault === 'price') $bad['products']['combo-simple']['price'] = '19.96';
    if ($fault === 'parent') $bad['products']['combo-variation']['parent'] = 501;
    if ($fault === 'attribute') $bad['products']['combo-variable']['attributes'] = [];
    if ($fault === 'category') $bad['products']['combo-simple']['categories'] = [];
    if ($fault === 'lookup-empty') $bad['lookup'] = [];
    if ($fault === 'lookup-alias') $bad['lookup'][0]['product_id'] = '502';
    if ($fault === 'lookup-stock') $bad['lookup'][0]['stock_quantity'] = '19';
    wprism_check_throws(static fn() => ImporterWooCatalogEvidence::preserved($bad, $bad), RuntimeException::class,
        "catalog premise rejects $fault even when both images agree");
    wprism_check_throws(static fn() => ImporterWooCatalogEvidence::preserved($catalog, $bad), RuntimeException::class,
        "native catalog transition rejects $fault");
}
foreach (array_keys($catalog['products']['combo-simple']) as $field) {
    $bad = $catalog; unset($bad['products']['combo-simple'][$field]);
    wprism_check_throws(static fn() => ImporterWooCatalogEvidence::preserved($bad, $bad), RuntimeException::class,
        "incomplete native product field $field refuses without diagnostics");
}
$changedCatalog = $catalog; $changedCatalog['products']['combo-variable']['extra'] = 'changed';
wprism_check_throws(static fn() => ImporterWooCatalogEvidence::preserved($catalog, $changedCatalog), RuntimeException::class,
    'complete native observation is preserved beyond asserted premise fields');

wprism_check_summary('Importer/Woo exact Apply database transition');
