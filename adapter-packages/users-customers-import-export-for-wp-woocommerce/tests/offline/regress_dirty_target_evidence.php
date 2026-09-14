<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 2) . '/fixtures/dirty-target-evidence.php';
require_once $root . '/agent/src/Apply/ApplyPlanner.php';
$package = dirname(__DIR__, 2);
$emptyDiagnostics = array_fill_keys(['code_mismatch', 'code_drift', 'incomplete_apply', 'incomplete_lifecycle',
    'missing_user', 'skipped_user_meta', 'uploads_inventory', 'selected_actions', 'regen_pending', 'regen_context',
    'env_missing', 'warnings', 'provider_problems'], []);
$fixtureSettings = json_decode(file_get_contents($package . '/fixtures/native-settings.json'), true, flags: JSON_THROW_ON_ERROR)['source'];
$native = ['format' => 'wprism-importer-native-settings/v1', 'tables' => array_fill_keys(['posts', 'postmeta', 'options', 'terms',
    'term_taxonomy', 'term_relationships', 'termmeta', 'users', 'usermeta', 'wt_iew_action_history', 'wt_iew_mapping_template'], []),
    'settings' => $fixtureSettings + ['other_module_key' => ['nested' => 'keep-target-local']], 'files' => array_fill_keys(['input.csv', 'old.csv', 'index.php'], str_repeat('a', 64))];
$native['tables']['users'] = [['ID' => '1', 'user_login' => 'admin'], ['ID' => '82', 'user_login' => 'template-reader'],
    ['ID' => '93', 'user_login' => 'template-editor'], ['ID' => '104', 'user_login' => 'import-template-reader']];
$native['tables']['wt_iew_action_history'] = [['id' => '1', 'data' => 'local-history']];
$native['tables']['options'] = [['option_id' => '13', 'option_name' => 'wt_iew_advanced_settings',
    'option_value' => serialize($fixtureSettings + ['other_module_key' => ['nested' => 'keep-target-local']]), 'autoload' => 'auto'],
    ['option_id' => '14', 'option_name' => 'local', 'option_value' => 'preserve', 'autoload' => 'off']];
foreach (['export' => ['Selected users', 'Selected users copy'], 'import' => ['Reusable input mapping', 'Reusable input copy', 'Draft input mapping']] as $type => $names) {
    foreach ($names as $name) {
        $form = json_decode(file_get_contents($package . '/fixtures/native-' . $type . '-templates.json'), true, flags: JSON_THROW_ON_ERROR)['form'];
        unset($form['method_' . $type . '_form_data']['selected_template']);
        if ($type === 'export') $form['filter_form_data']['wt_iew_email'] = ['82', '93'];
        else $form['method_import_form_data']['wt_iew_local_file'] = $name === 'Draft input mapping' ? '' : 'https://target.test/webtoffee_import/target-input.csv';
        $native['tables']['wt_iew_mapping_template'][] = ['id' => (string) (201 + count($native['tables']['wt_iew_mapping_template'])),
            'template_type' => $type, 'item_type' => 'user', 'name' => $name, 'data' => json_encode($form, JSON_THROW_ON_ERROR)];
    }
}
foreach ([['Import', 'user'], ['export', 'product']] as $index => [$type, $item]) $native['tables']['wt_iew_mapping_template'][] = [
    'id' => (string) (301 + $index), 'template_type' => $type, 'item_type' => $item, 'name' => 'local', 'data' => '{broken'];
ImporterDirtyTargetEvidence::native($native);
wprism_check(true, 'complete raw settings include the target-local member');
foreach (['missing-managed', 'missing-local', 'extra-getter', 'duplicate-option'] as $fault) {
    $bad = $native;
    if ($fault === 'missing-managed') {
        unset($bad['settings']['wt_iew_default_import_batch']);
        $bad['tables']['options'][0]['option_value'] = serialize($bad['settings']);
    }
    if ($fault === 'missing-local') unset($bad['settings']['other_module_key']);
    if ($fault === 'extra-getter') $bad['settings']['invented'] = true;
    if ($fault === 'duplicate-option') $bad['tables']['options'][] = $bad['tables']['options'][0];
    wprism_check_throws(static fn() => ImporterDirtyTargetEvidence::native($bad), RuntimeException::class, 'complete raw census rejects ' . $fault);
}
$edit = static function (array $record, string $side) use ($fixtureSettings): array {
    $settings = array_replace($fixtureSettings, ['wt_iew_default_import_batch' => $side === 'source' ? 19 : 29]);
    $record['settings'] = array_replace($record['settings'], $settings);
    $record['tables']['options'][0]['option_value'] = serialize($settings + ['other_module_key' => ['nested' => 'keep-target-local']]);
    foreach ([0, 2] as $index) {
        $row = &$record['tables']['wt_iew_mapping_template'][$index];
        $form = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
        $form['advanced_form_data']['wt_iew_batch_count'] = $side === 'source' ? 7 : 11;
        $form['method_' . $row['template_type'] . '_form_data']['selected_template'] = $row['id'];
        $row['data'] = json_encode($form, JSON_THROW_ON_ERROR);
        unset($row);
    }
    return $record;
};
foreach (['source', 'target'] as $side) {
    $edited = $edit($native, $side);
    ImporterDirtyTargetEvidence::edited($native, $edited, $side);
    wprism_check(true, 'independent three-field native edit admits ' . $side);
    foreach (['missing-table', 'extra-history', 'other-option', 'changed-file', 'lost-local-setting', 'wrong-edit', 'changed-id', 'changed-foreign', 'wrong-getter'] as $fault) {
        $bad = $edited;
        if ($fault === 'missing-table') unset($bad['tables']['usermeta']);
        if ($fault === 'extra-history') $bad['tables']['wt_iew_action_history'][] = ['id' => '99'];
        if ($fault === 'other-option') $bad['tables']['options'][1]['option_value'] = 'changed';
        if ($fault === 'changed-file') $bad['files']['input.csv'] = str_repeat('b', 64);
        if ($fault === 'lost-local-setting') $bad['tables']['options'][0]['option_value'] = serialize(array_intersect_key($bad['settings'], $fixtureSettings));
        if ($fault === 'wrong-edit') $bad['tables']['wt_iew_mapping_template'][2]['data'] = $native['tables']['wt_iew_mapping_template'][2]['data'];
        if ($fault === 'changed-id') $bad['tables']['wt_iew_mapping_template'][0]['id'] = '900';
        if ($fault === 'changed-foreign') $bad['tables']['wt_iew_mapping_template'][6]['data'] = '{}';
        if ($fault === 'wrong-getter') $bad['settings']['wt_iew_default_import_batch'] = 17;
        wprism_check_throws(static fn() => ImporterDirtyTargetEvidence::edited($native, $bad, $side), RuntimeException::class, 'native edit oracle rejects ' . $side . '/' . $fault);
    }
}
$selected = ['11111111-1111-7111-8111-111111111111' => ['path' => 'tables/wt_iew_mapping_template/one.json', 'id' => '201'],
    '22222222-2222-7222-8222-222222222222' => ['path' => 'tables/wt_iew_mapping_template/two.json', 'id' => '203']];
foreach (['collision', 'drift', 'conflict'] as $case) {
    $expected = $selected;
    if ($case !== 'collision') $expected['options/core'] = ['path' => 'options/core.json'];
    $plan = $emptyDiagnostics + array_fill_keys(['create', 'update', 'adopt', 'unchanged', 'collision', 'drift', 'conflict', 'delete', 'delete_conflict', 'deleted'], []);
    foreach ($expected as $uuid => $entity) {
        $row = ['uuid' => $uuid, 'path' => $entity['path'], 'type' => $uuid === 'options/core' ? 'option' : 'wt_iew_mapping_template'];
        if ($case === 'collision') $row['env_id'] = (int) $entity['id'];
        if ($case === 'conflict') $row['conflict_view'] = WPrism\ApplyPlanner::conflict_view('repository_and_target_changed_since_base', 'update', 'present',
            str_repeat('a', 64), str_repeat('b', 64), str_repeat('a', 64), null, str_repeat('c', 64), ['--force-theirs']);
        $plan[$case][] = $row;
    }
    ImporterDirtyTargetEvidence::plan($plan, $expected, $case);
    wprism_check(true, 'exact native decision inventory admits ' . $case);
    $profile = ImporterDirtyTargetEvidence::profile($case, $plan);
    wprism_check(str_contains($profile['nodes'][0]['message'], "\n  - ") && !str_contains($profile['nodes'][0]['message'], '\\n'), 'private operator sentence retains actual newlines');
    foreach (['missing', 'duplicate', 'wrong-owner', 'wrong-path', 'hidden-delete'] as $fault) {
        $bad = $plan;
        if ($fault === 'missing') array_pop($bad[$case]);
        if ($fault === 'duplicate') $bad[$case][] = $bad[$case][0];
        if ($fault === 'wrong-owner') $bad[$case][0]['uuid'] = 'foreign';
        if ($fault === 'wrong-path') $bad[$case][0]['path'] = 'other.json';
        if ($fault === 'hidden-delete') $bad['delete'][] = ['uuid' => 'foreign'];
        wprism_check_throws(static fn() => ImporterDirtyTargetEvidence::plan($bad, $expected, $case), RuntimeException::class, 'plan oracle rejects ' . $case . '/' . $fault);
    }
    foreach (array_keys($emptyDiagnostics) as $field) {
        foreach (['missing', 'nonempty'] as $fault) {
            $bad = $plan;
            if ($fault === 'missing') unset($bad[$field]); else $bad[$field][] = ['unexpected' => true];
            wprism_check_throws(static fn() => ImporterDirtyTargetEvidence::plan($bad, $expected, $case), RuntimeException::class, 'plan oracle rejects ancillary ' . $case . '/' . $field . '/' . $fault);
        }
    }
    if ($case === 'collision') {
        $bad = $plan; $bad[$case][0]['env_id'] = 999;
        wprism_check_throws(static fn() => ImporterDirtyTargetEvidence::plan($bad, $expected, $case), RuntimeException::class, 'wrong local collision ID is refused');
    }
    if ($case === 'conflict') {
        foreach (['hash', 'choice', 'reason'] as $fault) {
            $bad = $plan;
            if ($fault === 'hash') $bad[$case][0]['conflict_view']['target']['content_hash'] = str_repeat('a', 64);
            if ($fault === 'choice') $bad[$case][0]['conflict_view']['choices'][1]['requires'] = [];
            if ($fault === 'reason') $bad[$case][0]['conflict_view']['reason_code'] = 'other';
            wprism_check_throws(static fn() => ImporterDirtyTargetEvidence::plan($bad, $expected, $case), RuntimeException::class, 'conflict oracle rejects ' . $fault);
        }
    }
}
$before = $edit($native, 'target');
$source = $edit($native, 'source');
unset($source['settings']['other_module_key']);
$source['tables']['options'][0]['option_value'] = serialize($source['settings']);
$source['tables']['users'][1]['ID'] = '2';
$source['tables']['users'][2]['ID'] = '3';
foreach ($source['tables']['wt_iew_mapping_template'] as $index => &$row) {
    if ($index >= 5) continue;
    $row['id'] = (string) ($index + 1);
    $form = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
    if ($row['template_type'] === 'export') $form['filter_form_data']['wt_iew_email'] = ['2', '3'];
    $row['data'] = json_encode($form, JSON_THROW_ON_ERROR);
}
unset($row);
$resolved = $edit($native, 'source');
foreach ([0, 2] as $index) {
    $row = &$resolved['tables']['wt_iew_mapping_template'][$index];
    $form = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
    unset($form['method_' . $row['template_type'] . '_form_data']['selected_template']);
    $row['data'] = json_encode($form, JSON_THROW_ON_ERROR);
    unset($row);
}
ImporterDirtyTargetEvidence::resolved($source, $before, $resolved);
wprism_check(true, 'explicit resolution materializes complete portable source forms while retaining target identities and neighbors');
foreach (['local-id', 'source-user', 'template-data', 'cursor', 'local-input', 'local-setting', 'setting', 'copy', 'history', 'file'] as $fault) {
    $bad = $resolved;
    if ($fault === 'local-id') $bad['tables']['wt_iew_mapping_template'][0]['id'] = '1';
    if ($fault === 'template-data') $bad['tables']['wt_iew_mapping_template'][2]['data'] = $before['tables']['wt_iew_mapping_template'][2]['data'];
    if (in_array($fault, ['source-user', 'cursor', 'local-input'], true)) {
        $index = $fault === 'local-input' ? 2 : 0;
        $form = json_decode($bad['tables']['wt_iew_mapping_template'][$index]['data'], true, flags: JSON_THROW_ON_ERROR);
        if ($fault === 'source-user') $form['filter_form_data']['wt_iew_email'] = ['2', '3'];
        if ($fault === 'cursor') $form['method_export_form_data']['selected_template'] = '1';
        if ($fault === 'local-input') $form['method_import_form_data']['wt_iew_local_file'] = 'https://source.test/source.csv';
        $bad['tables']['wt_iew_mapping_template'][$index]['data'] = json_encode($form, JSON_THROW_ON_ERROR);
    }
    if ($fault === 'local-setting') $bad['tables']['options'][0]['option_value'] = serialize(array_intersect_key($bad['settings'], $fixtureSettings));
    if ($fault === 'setting') $bad['settings'] = $before['settings'];
    if ($fault === 'copy') $bad['tables']['wt_iew_mapping_template'][1]['name'] = 'wrong';
    if ($fault === 'history') $bad['tables']['wt_iew_action_history'] = [];
    if ($fault === 'file') $bad['files']['input.csv'] = str_repeat('c', 64);
    wprism_check_throws(static fn() => ImporterDirtyTargetEvidence::resolved($source, $before, $bad), RuntimeException::class, 'resolution oracle rejects ' . $fault);
}
require_once $root . '/sandbox/tests/lib/ShellProbe.php';
require_once $root . '/agent/src/Kernel/PrivateRefusalEvidence.php';
require_once $root . '/agent/src/Kernel/CommandRefusal.php';
$sink = $root . '/sandbox/tmp/importer-dirty-offline-' . bin2hex(random_bytes(6));
mkdir($sink, 0700, true);
$privateRoots = [];
$remove = static function (string $directory): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($directory);
};
$stream = static function (string $stem, string $out, string $err = '', int $exit = 0): void {
    foreach (['stdout' => $out, 'stderr' => $err, 'exit' => $exit . "\n"] as $suffix => $bytes) {
        file_put_contents($stem . '.' . $suffix, $bytes); chmod($stem . '.' . $suffix, 0600);
    }
};
$json = static fn(array $data): string => json_encode($data, JSON_THROW_ON_ERROR) . "\n";
$pair = 'importerdirtyoffline';
try {
    $historical = $native;
    $historical['tables']['options'][] = ['option_id' => '999', 'option_name' => '_transient_doing_cron', 'option_value' => 'original'];
    $historical['tables']['wt_iew_mapping_template'] = array_slice($historical['tables']['wt_iew_mapping_template'], 0, 5);
    foreach ([0, 2] as $index) $historical['tables']['wt_iew_mapping_template'][$index]['name'] = 'Historical ' . $historical['tables']['wt_iew_mapping_template'][$index]['name'];
    $current = $historical; $map = ['identities' => []];
    $decl = json_decode(file_get_contents($package . '/package/manifest.json'), true, flags: JSON_THROW_ON_ERROR)['tables']['wt_iew_mapping_template'];
    mkdir($sink . '/identity/state/tables/wt_iew_mapping_template', 0700, true);
    foreach ($historical['tables']['wt_iew_mapping_template'] as $index => $row) {
        $uuid = WPrism\Uuid::v5(WPrism\Uuid::NAMESPACE_WPRISM, WPrism\Snapshot::natural_key_name('wt_iew_mapping_template', $decl, $row));
        $map['identities'][] = ['uuid' => $uuid, 'entity_type' => 'wt_iew_mapping_template', 'id_kind' => 'iew_template', 'local_id' => $row['id']];
        if (in_array($index, [0, 2], true)) $row['name'] = substr($row['name'], strlen('Historical '));
        $current['tables']['wt_iew_mapping_template'][$index] = $row;
        WPrism\Canon::write_file($sink . '/identity/state/tables/wt_iew_mapping_template/' . $uuid . '.json', WPrism\Canon::encode([
            'table' => 'wt_iew_mapping_template', 'uuid' => $uuid, 'columns' => array_diff_key($row, ['id' => true]), 'meta' => new stdClass(),
        ]));
    }
    $captured = WPrismTest\FilesystemTreeEvidence::capture($sink . '/identity', 'state');
    ImporterDirtyTargetEvidence::retained($historical, $current, $map, $map, $captured);
    wprism_check(true, 'native rename proof admits both historical UUIDs retained under current names');
    foreach (['identity-before-native' => $historical, 'identity-after-native' => $current,
        'identity-before-map' => $map, 'identity-after-map' => $map, 'identity-after-state' => ['state' => $captured]] as $label => $record) {
        $stream($sink . '/' . $label, $json($record));
    }
    [$retainedStatus, $retainedOut, $retainedErr] = WPrismTest\ShellProbe::run('exec "$1" "$2" retained "$3" "$4"',
        [PHP_BINARY, $package . '/fixtures/dirty-target-evidence.php', $sink, $pair], $root);
    wprism_check($retainedStatus === 0 && $retainedErr === '' && str_contains($retainedOut, 'PASS: Importer dirty target retained'),
        'standalone retained-identity verifier loads every dependency: ' . $retainedErr);

    foreach (['missing-map', 'duplicate-map', 'wrong-kind', 'rebound', 'not-historical', 'changed-id', 'changed-form', 'changed-file', 'changed-cron', 'missing-canonical', 'wrong-canonical-name', 'wrong-canonical-uuid'] as $fault) {
        $before = $historical; $after = $current; $oldMap = $map; $newMap = $map; $tree = $captured;
        if ($fault === 'missing-map') array_pop($oldMap['identities']);
        if ($fault === 'duplicate-map') $oldMap['identities'][1] = $oldMap['identities'][0];
        if ($fault === 'wrong-kind') $oldMap['identities'][0]['id_kind'] = 'foreign';
        if ($fault === 'rebound') $newMap['identities'][0]['uuid'] = WPrism\Uuid::v5(WPrism\Uuid::NAMESPACE_WPRISM, WPrism\Snapshot::natural_key_name('wt_iew_mapping_template', $decl, $after['tables']['wt_iew_mapping_template'][0]));
        if ($fault === 'not-historical') $before['tables']['wt_iew_mapping_template'][0]['name'] = $after['tables']['wt_iew_mapping_template'][0]['name'];
        if ($fault === 'changed-id') $after['tables']['wt_iew_mapping_template'][0]['id'] = '999';
        if ($fault === 'changed-form') $after['tables']['wt_iew_mapping_template'][0]['data'] = '{}';
        if ($fault === 'changed-file') $after['files']['input.csv'] = str_repeat('b', 64);
        if ($fault === 'changed-cron') $after['tables']['options'][2]['option_value'] = 'changed';
        if ($fault === 'missing-canonical') array_pop($tree['files']);
        if (str_starts_with($fault, 'wrong-canonical-')) {
            $record = json_decode(base64_decode($tree['files'][0]['contents_base64'], true), true, flags: JSON_THROW_ON_ERROR);
            if ($fault === 'wrong-canonical-name') $record['columns']['name'] = 'Wrong name';
            else $record['uuid'] = '33333333-3333-4333-8333-333333333333';
            $bytes = WPrism\Canon::encode($record);
            $tree['files'][0] = array_replace($tree['files'][0], ['contents_base64' => base64_encode($bytes), 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        }
        if (in_array($fault, ['missing-map', 'duplicate-map', 'wrong-kind'], true)) $newMap = $oldMap;
        wprism_check_throws(static fn() => ImporterDirtyTargetEvidence::retained($before, $after, $oldMap, $newMap, $tree), RuntimeException::class, 'native retained identity oracle rejects ' . $fault);
    }
    mkdir($sink . '/site/state/tables/wt_iew_mapping_template', 0700, true);
    foreach ($selected as $uuid => $identity) {
        $row = $native['tables']['wt_iew_mapping_template'][$identity['id'] === '201' ? 0 : 2];
        WPrism\Canon::write_file($sink . '/site/state/' . $identity['path'], WPrism\Canon::encode([
            'table' => 'wt_iew_mapping_template', 'uuid' => $uuid, 'columns' => array_diff_key($row, ['id' => true]), 'meta' => new stdClass(),
        ]));
    }
    file_put_contents($sink . '/site/site.wprism.json', '{}');
    $tree = ['state' => WPrismTest\FilesystemTreeEvidence::capture($sink . '/site', 'state'),
        'policy' => WPrismTest\FilesystemTreeEvidence::capture($sink . '/site', 'site.wprism.json')];
    $roster = array_map(static fn(string $name): string => 'wp_' . $name, array_merge(array_keys($native['tables']), ['wprism_map', 'wprism_state', 'wprism_kv']));
    sort($roster);
    $tableBytes = implode("\n", array_map(static fn(string $name): string => "$name\tBASE TABLE", $roster)) . "\n";
    $dump = "-- MariaDB dump fixture\n";
    foreach ($roster as $name) $dump .= "-- Table structure for table `$name`\nCREATE TABLE `$name` (\n  `id` bigint NOT NULL\n);\n-- Dumping data for table `$name`\nINSERT INTO `$name` (`id`) VALUES (1);\n";
    $dump .= "-- Dump completed\n";
    $messages = ['collision' => 'unmanaged target rows have colliding slugs; apply requires an explicit identity decision',
        'drift' => 'the target changed after the repository baseline, so this plan is stale and cannot be partially applied',
        'conflict' => 'both target and repository changed since the last sync; apply requires an explicit conflict decision'];
    foreach (['collision', 'drift', 'conflict'] as $case) {
        $observation = $native;
        if ($case === 'collision') $observation['tables']['wt_iew_mapping_template'] = array_values(array_intersect_key($native['tables']['wt_iew_mapping_template'], array_flip([0, 2, 5, 6])));
        $expected = ImporterDirtyTargetEvidence::selected($tree['state'], $observation, $case);
        $plan = $emptyDiagnostics + array_fill_keys(['create', 'update', 'adopt', 'unchanged', 'collision', 'drift', 'conflict', 'delete', 'delete_conflict', 'deleted'], []);
        foreach ($expected as $uuid => $identity) {
            $row = ['uuid' => $uuid, 'path' => $identity['path'], 'type' => 'wt_iew_mapping_template'];
            if ($case === 'collision') $row['env_id'] = (int) $identity['id'];
            if ($case === 'conflict') $row['conflict_view'] = WPrism\ApplyPlanner::conflict_view('repository_and_target_changed_since_base', 'update', 'present',
                str_repeat('a', 64), str_repeat('b', 64), str_repeat('a', 64), null, str_repeat('c', 64), ['--force-theirs']);
            $plan[$case][] = $row;
        }
        foreach (['before', 'after'] as $when) foreach (['tables' => $tableBytes, 'database' => $dump, 'state' => $json($tree), 'native' => $json($observation)] as $suffix => $bytes) {
            $stream("$sink/$case-$when-$suffix", $bytes);
        }
        $stream("$sink/$case-plan", $json($plan));
        $private = $root . '/sandbox/tmp/wprism-conformance-apply.' . $pair . '.' . bin2hex(random_bytes(3));
        mkdir($private, 0700); $privateRoots[] = $private;
        $public = ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => 'apply', 'reason_code' => 'apply_refused', 'message' => $messages[$case]];
        $stream($private . '/command', $json($public), '', 1);
        $stream("$sink/$case-refusal", $json($public), 'private command diagnostics (unverified): ' . $private . "\n", 1);
        $stream($private . '/baseline', $json(['command' => 'apply', 'baseline' => '[]']));
        $profile = ImporterDirtyTargetEvidence::profile($case, $plan);
        $diagnostic = static function (Throwable $failure) use ($json): array {
            $bytes = $json(['format' => 'wprism-private-refusal-evidence/v2', 'command' => 'apply', 'reason_code' => 'apply_refused'] + WPrism\PrivateRefusalEvidence::graph($failure));
            return ['format' => 'wprism-private-refusal-diagnostic/v1', 'command' => 'apply', 'new_records' => 1,
                'purpose' => 'diagnostic_only', 'verified' => false, 'records' => [[
                    'name' => '20260914-010203-apply-0123456789abcdef01234567.json', 'bytes' => strlen($bytes),
                    'contents_base64' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes),
                ]]];
        };
        $good = $diagnostic(WPrism\CommandRefusalException::applyRefused($messages[$case], 'fixture remediation', $profile['nodes'][0]['message']));
        $stream($private . '/private', $json($good));
        $run = static fn(): array => WPrismTest\ShellProbe::run('exec "$1" "$2" refusal "$3" "$4" "$5"',
            [PHP_BINARY, $package . '/fixtures/dirty-target-evidence.php', $sink, $pair, $case], $root);
        [$status, $out, $err] = $run();
        wprism_check($status === 0 && $err === '' && str_contains($out, 'PASS: Importer dirty target refusal'), 'actual host verifier admits complete fresh ' . $case . ' evidence: ' . $err);
        foreach (['wrong-cause', 'wrong-class', 'stale', 'database', 'empty-roster', 'native-file', 'plan-owner', 'warning'] as $fault) {
            $stem = $private . '/private'; $bytes = $json($good); $error = '';
            if ($fault === 'wrong-cause') $bytes = $json($diagnostic(WPrism\CommandRefusalException::applyRefused('other refusal', 'fixture remediation', 'unrelated failure')));
            if ($fault === 'wrong-class') $bytes = $json($diagnostic(new RuntimeException($profile['nodes'][0]['message'])));
            if ($fault === 'stale') { $stem = $private . '/baseline'; $bytes = $json(['command' => 'apply', 'baseline' => $json([$good['records'][0]['name']])]); }
            if ($fault === 'database') { $stem = "$sink/$case-after-database"; $bytes = str_replace('VALUES (1)', 'VALUES (2)', $dump); }
            if ($fault === 'empty-roster') { $stem = "$sink/$case-before-tables"; $bytes = ''; }
            if ($fault === 'native-file') { $stem = "$sink/$case-after-native"; $bad = $observation; $bad['files']['input.csv'] = str_repeat('d', 64); $bytes = $json($bad); }
            if ($fault === 'plan-owner') { $stem = "$sink/$case-plan"; $bad = $plan; $bad[$case][0]['uuid'] = 'foreign'; $bytes = $json($bad); }
            if ($fault === 'warning') $error = "PHP Warning: retained transport in /fixture.php on line 1\n";
            $original = file_get_contents($stem . '.stdout');
            $stream($stem, $bytes, $error);
            [$status] = $run();
            wprism_check($status !== 0, 'actual refusal verifier rejects ' . $case . '/' . $fault);
            $stream($stem, $original);
        }
    }
} finally {
    foreach ($privateRoots as $directory) $remove($directory);
    $remove($sink);
}
$script = <<<'SH'
set -euo pipefail
ROOT="$1"
export WPRISM_ARTIFACT_LIBRARY_ROOT="$ROOT" CONF_PAIR=dirtytransport
export COMPOSE='record_compose -p wprism-dirtytransport -f pair.yml -f pair.http.yml -f pair.artifacts.yml -f pair.wordpress-offline.yml'
record_compose() { printf '%s\n' "$@"; }
export -f record_compose
cd "$ROOT/sandbox"
bash -c '. "$1/adapter-packages/users-customers-import-export-for-wp-woocommerce/fixtures/dirty-target.sh"; conformance_private_command_native cli2 apply snapshot' sh "$ROOT"
SH;
[$status,$out,$err] = WPrismTest\ShellProbe::run($script,[$root], $root);
wprism_check($status===0 && $err==='', 'child hook restores exported transport: '.$err);
wprism_check(str_starts_with($out,"-p\nwprism-dirtytransport\n-f\npair.yml\n-f\npair.http.yml\n-f\npair.artifacts.yml\n-f\npair.wordpress-offline.yml\nrun\n--rm\n-T\n"), 'exact parent Compose overlays precede private collector invocation');
wprism_check(str_ends_with($out,"--entrypoint\nphp\ncli2\n/wprism-test/conformance_private_command.php\nsnapshot\napply\n/siterepo/.wprism/refusals\n"), 'native baseline uses exact service, command and private path');
wprism_check_summary('Importer dirty target evidence');
