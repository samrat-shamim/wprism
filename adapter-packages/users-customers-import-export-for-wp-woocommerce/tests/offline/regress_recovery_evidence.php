<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$package = dirname(__DIR__, 2);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $package . '/fixtures/recovery-evidence.php';
require_once $package . '/fixtures/native-record-fixture.php';

use WPrism\Canon;
use WPrismTest\FilesystemTreeEvidence;

$settings = json_decode(file_get_contents($package . '/fixtures/native-settings.json'), true, flags: JSON_THROW_ON_ERROR)['source'];
$beforeNative = importer_test_native_record($package, $settings);
$afterNative = $beforeNative;
$afterNative['settings']['wt_iew_default_import_batch'] = 19;
$afterNative['tables']['options'][0]['option_value'] = serialize($afterNative['settings']);
foreach ([0, 2] as $index) {
    $form = json_decode($afterNative['tables']['wt_iew_mapping_template'][$index]['data'], true, flags: JSON_THROW_ON_ERROR);
    $form['advanced_form_data']['wt_iew_batch_count'] = 7;
    $afterNative['tables']['wt_iew_mapping_template'][$index]['data'] = json_encode($form, JSON_THROW_ON_ERROR);
}
$source = $afterNative;
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
$scratch = $root . '/sandbox/tmp/importer-recovery-oracle-' . bin2hex(random_bytes(6));
mkdir($scratch, 0700, true);
register_shutdown_function(static function () use ($scratch): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($scratch);
});
$uuid = static fn(int $index): string => sprintf('%08d-1111-4111-8111-111111111111', $index + 1);
foreach ([0, 2] as $index) {
    $row = $source['tables']['wt_iew_mapping_template'][$index];
    Canon::write_file($scratch . '/state/tables/wt_iew_mapping_template/' . $uuid($index) . '.json', Canon::encode([
        'uuid' => $uuid($index), 'table' => 'wt_iew_mapping_template', 'columns' => array_diff_key($row, ['id' => true]), 'meta' => new stdClass(),
    ]));
}
Canon::write_file($scratch . '/state/options/core.json', Canon::encode(['fixture' => $source['settings']]));
Canon::write_file($scratch . '/site.wprism.json', Canon::encode(['fixture' => true]));
$repository = ['state' => FilesystemTreeEvidence::capture($scratch, 'state'),
    'policy' => FilesystemTreeEvidence::capture($scratch, 'site.wprism.json')];
$intent = ImporterRecoveryEvidence::intent($repository['state']);
wprism_check_same(3, count($intent), 'canonical preimage supplies all three exact intended identities');
$artifact = str_repeat('a', 64); $revision = str_repeat('b', 40);
$session = static fn(string $identity, int $time): string => json_encode(['owner' => 'direct-' . str_repeat($identity, 32),
    'artifact_hash' => $artifact, 'begun_at' => $time, 'session_id' => 'ps-' . str_repeat($identity, 32)], JSON_THROW_ON_ERROR);
$rows = array_fill_keys(ImporterRecoveryEvidence::TABLES, []);
foreach ($beforeNative['tables'] as $table => $tableRows) $rows['wp_' . $table] = $tableRows;
$rows['wp_links'] = [['link_id' => 1, 'link_url' => 'https://local.test/']];
$rows['wp_wprism_kv'] = [['k' => 'applied_revision', 'v' => str_repeat('c', 40)],
    ['k' => 'code_versions', 'v' => 'keep-native'], ['k' => 'promotion_session', 'v' => $session('1', 90)]];
foreach ($intent as $id => $entity) $rows['wp_wprism_state'][] = ['uuid' => $id, 'entity_type' => $entity['type'], 'content_hash' => str_repeat('d', 64)];
$rows['wp_wprism_state'][] = ['uuid' => $uuid(1), 'entity_type' => 'wt_iew_mapping_template', 'content_hash' => str_repeat('f', 64)];
usort($rows['wp_wprism_state'], static fn(array $a, array $b): int => strcmp($a['uuid'], $b['uuid']));
foreach (range(0, 4) as $index) $rows['wp_wprism_map'][] = ['uuid' => $uuid($index), 'entity_type' => 'wt_iew_mapping_template',
    'id_kind' => 'iew_template', 'local_id' => 201 + $index];
$base = ['repository' => $repository, 'native' => $beforeNative, 'database' => [
    'schemas' => array_fill_keys(ImporterRecoveryEvidence::TABLES, 'independently captured schema'),
    'columns' => array_fill_keys(ImporterRecoveryEvidence::TABLES, 'independently captured columns'), 'rows' => $rows]];
$inventory = ''; $columns = []; $dump = "-- MariaDB dump 10.19 Distrib 11.8\n";
$quote = static fn(mixed $value): string => $value === null ? 'NULL' : (is_int($value) ? (string) $value
    : "'" . strtr($value, ["\\" => "\\\\", "'" => "\\'", "\0" => '\\0', "\n" => '\\n', "\r" => '\\r']) . "'");
foreach ($rows as $table => $tableRows) {
    $inventory .= $table . "\tBASE TABLE\n";
    $fields = $tableRows === [] ? ['id'] : array_keys($tableRows[0]);
    $columns[$table] = ''; $ddl = [];
    foreach ($fields as $field) {
        $columns[$table] .= $field . "\tlongtext\tutf8mb4_unicode_ci\tYES\t\tNULL\t\tselect\t\n";
        $ddl[] = '  `' . $field . '` longtext';
    }
    $dump .= '-- Table structure for table `' . $table . "`\nCREATE TABLE `" . $table . "` (\n" . implode(",\n", $ddl) . "\n);\n"
        . '-- Dumping data for table `' . $table . "`\n";
    foreach ($tableRows as $row) $dump .= 'INSERT INTO `' . $table . '` (`' . implode('`, `', $fields) . '`) VALUES ('
        . implode(',', array_map($quote, array_values($row))) . ");\n";
}
$dump .= "-- Dump completed\n";
$decoded = ImporterRecoveryEvidence::database($dump, $inventory, $columns);
wprism_check_same($rows, $decoded['rows'], 'all eighteen native SQL tables and every observed column survive admission');
foreach (['omitted-column', 'missing-metadata', 'wrong-schema', 'lost-map'] as $fault) {
    $badDump = $dump; $badColumns = $columns;
    if ($fault === 'omitted-column') $badColumns['wp_options'] = preg_replace('/^option_value[^\n]*\n/m', '', $badColumns['wp_options']);
    if ($fault === 'missing-metadata') unset($badColumns['wp_links']);
    if ($fault === 'wrong-schema') $badDump = str_replace('CREATE TABLE `wp_links`', 'CREATE TABLE `wp_other`', $badDump);
    if ($fault === 'lost-map') $badDump = preg_replace('/^INSERT INTO `wp_wprism_map`[^\n]*\n/m', '', $badDump);
    wprism_check_throws(static fn() => ImporterRecoveryEvidence::database($badDump, $inventory, $badColumns), RuntimeException::class,
        'complete database admission refuses ' . $fault);
}
$replaceKv = static function (array $image, array $changes): array {
    $kv = array_column($image['database']['rows']['wp_wprism_kv'], null, 'k');
    foreach ($changes as $key => $value) {
        if ($value === null) unset($kv[$key]); else $kv[$key] = ['k' => $key, 'v' => $value];
    }
    ksort($kv, SORT_STRING); $image['database']['rows']['wp_wprism_kv'] = array_values($kv);
    return $image;
};
$failed = $replaceKv($base, ['promotion_session' => $session('2', 101), 'apply_in_progress' => ImporterRecoveryEvidence::marker($intent)]);
$committed = $replaceKv($failed, ['promotion_session' => $session('3', 102)]);
$committed['native'] = $afterNative;
foreach ($afterNative['tables'] as $table => $tableRows) $committed['database']['rows']['wp_' . $table] = $tableRows;
$recovered = $replaceKv($committed, ['promotion_session' => $session('4', 103), 'apply_in_progress' => null, 'applied_revision' => $revision]);
foreach ($recovered['database']['rows']['wp_wprism_state'] as &$row) if (isset($intent[$row['uuid']])) $row['content_hash'] = $intent[$row['uuid']]['hash'];
unset($row);
$repeat = $replaceKv($recovered, ['promotion_session' => $session('5', 104)]);
$phases = ['authored-failure' => [$base, $failed], 'ledger-failure' => [$failed, $committed],
    'retry' => [$committed, $recovered], 'repeat' => [$recovered, $repeat]];
$window = ['before' => 100, 'after' => 110];
foreach ($phases as $phase => [$before, $after]) {
    ImporterRecoveryEvidence::transition($before, $after, $source, $intent, $artifact, $window, $phase, $revision);
    wprism_check(true, 'complete transition admits ' . $phase);

    $badBefore = $before;
    if ($phase === 'authored-failure') $badBefore = $replaceKv($badBefore, ['apply_in_progress' => ImporterRecoveryEvidence::marker($intent)]);
    elseif ($phase === 'repeat') $badBefore['database']['rows']['wp_wprism_state'][0]['content_hash'] = str_repeat('e', 64);
    else $badBefore = $replaceKv($badBefore, ['apply_in_progress' => null]);
    wprism_check_throws(static fn() => ImporterRecoveryEvidence::transition($badBefore, $after, $source, $intent,
        $artifact, $window, $phase, $revision), RuntimeException::class, 'phase requires its independent recovery preimage: ' . $phase);

    foreach (['schema', 'columns', 'extra-table', 'links', 'map', 'state', 'other-state', 'revision', 'marker', 'session-owner', 'session-artifact',
        'session-time', 'session-extra', 'code-version', 'file', 'history', 'local-setting', 'canonical'] as $fault) {
        $bad = $after;
        if ($fault === 'schema') $bad['database']['schemas']['wp_links'] .= 'changed';
        if ($fault === 'columns') $bad['database']['columns']['wp_links'] .= 'changed';
        if ($fault === 'extra-table') $bad['database']['rows']['wp_unobserved'] = [];
        if ($fault === 'links') $bad['database']['rows']['wp_links'][0]['link_url'] = 'changed';
        if ($fault === 'map') $bad['database']['rows']['wp_wprism_map'][0]['local_id'] = 999;
        if ($fault === 'state') $bad['database']['rows']['wp_wprism_state'][0]['content_hash'] = str_repeat('e', 64);
        if ($fault === 'other-state') $bad['database']['rows']['wp_wprism_state'][1]['content_hash'] = str_repeat('e', 64);
        if ($fault === 'revision') $bad = $replaceKv($bad, ['applied_revision' => 'wrong']);
        if ($fault === 'marker') $bad = $replaceKv($bad, ['apply_in_progress' => 'wrong']);
        if (str_starts_with($fault, 'session-')) {
            $kv = array_column($bad['database']['rows']['wp_wprism_kv'], 'v', 'k');
            $payload = json_decode($kv['promotion_session'], true, flags: JSON_THROW_ON_ERROR);
            if ($fault === 'session-owner') $payload['owner'] = json_decode(array_column($before['database']['rows']['wp_wprism_kv'], 'v', 'k')['promotion_session'], true)['owner'];
            if ($fault === 'session-artifact') $payload['artifact_hash'] = str_repeat('f', 64);
            if ($fault === 'session-time') $payload['begun_at'] = 111;
            if ($fault === 'session-extra') $payload['unexpected'] = true;
            $bad = $replaceKv($bad, ['promotion_session' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        }
        if ($fault === 'code-version') $bad = $replaceKv($bad, ['code_versions' => 'changed']);
        if ($fault === 'file') $bad['native']['files']['input.csv'] = str_repeat('f', 64);
        if ($fault === 'history') $bad['database']['rows']['wp_wt_iew_action_history'][] = ['id' => '9', 'data' => 'changed'];
        if ($fault === 'local-setting') {
            unset($bad['native']['settings']['other_module_key']);
            $bad['native']['tables']['options'][0]['option_value'] = serialize($bad['native']['settings']);
            $bad['database']['rows']['wp_options'] = $bad['native']['tables']['options'];
        }
        if ($fault === 'canonical') $bad['repository']['state']['files'][0]['mtime']++;
        wprism_check_throws(static fn() => ImporterRecoveryEvidence::transition($before, $bad, $source, $intent,
            $artifact, $window, $phase, $revision), RuntimeException::class, 'transition rejects ' . $phase . '/' . $fault);
    }
}
$plan = array_fill_keys(['create', 'adopt', 'collision', 'drift', 'conflict', 'delete', 'delete_conflict', 'deleted',
    'code_mismatch', 'code_drift', 'incomplete_apply', 'incomplete_lifecycle', 'missing_user', 'skipped_user_meta',
    'uploads_inventory', 'selected_actions', 'regen_pending', 'regen_context', 'env_missing', 'warnings', 'provider_problems'], []);
$plan['update'] = [];
foreach ($intent as $id => $entity) $plan['update'][] = ['uuid' => $id, 'type' => $entity['type'], 'path' => $entity['path']];
$plan['unchanged'] = [['uuid' => $uuid(1)]]; $plan['artifact_hash'] = $artifact;
wprism_check_same($artifact, ImporterRecoveryEvidence::plan($base, $base, $plan, $intent), 'initial Plan binds three updates and the complete unchanged roster without writes');
foreach (['missing', 'duplicate', 'wrong-path', 'wrong-type', 'extra-create', 'warning', 'artifact', 'missing-unchanged', 'duplicate-unchanged'] as $fault) {
    $bad = $plan;
    if ($fault === 'missing') array_pop($bad['update']);
    if ($fault === 'duplicate') $bad['update'][] = $bad['update'][0];
    if ($fault === 'wrong-path') $bad['update'][0]['path'] = 'other.json';
    if ($fault === 'wrong-type') $bad['update'][0]['type'] = 'foreign';
    if ($fault === 'extra-create') $bad['create'][] = ['uuid' => 'foreign'];
    if ($fault === 'warning') $bad['warnings'][] = 'unexpected';
    if ($fault === 'artifact') $bad['artifact_hash'] = 'unknown';
    if ($fault === 'missing-unchanged') $bad['unchanged'] = [];
    if ($fault === 'duplicate-unchanged') $bad['unchanged'][] = $bad['unchanged'][0];
    wprism_check_throws(static fn() => ImporterRecoveryEvidence::plan($base, $base, $bad, $intent), RuntimeException::class, 'initial Plan rejects ' . $fault);
}
require_once $root . '/sandbox/tests/lib/ShellProbe.php';
$sink = $scratch . '/streams'; mkdir($sink, 0700);
$stream = static function (string $label, string $bytes) use ($sink): void {
    foreach (['stdout' => $bytes, 'stderr' => '', 'exit' => "0\n"] as $suffix => $contents) {
        file_put_contents($sink . '/' . $label . '.' . $suffix, $contents);
        chmod($sink . '/' . $label . '.' . $suffix, 0600);
    }
};
foreach ($columns as $table => $bytes) $stream('recovery-columns-' . str_replace('_', '-', $table), $bytes);
foreach (['before', 'after'] as $label) {
    $stream($label . '-database', $dump); $stream($label . '-tables', $inventory);
    $stream($label . '-native', json_encode($beforeNative, JSON_THROW_ON_ERROR) . "\n");
    $stream($label . '-state', json_encode($repository, JSON_THROW_ON_ERROR) . "\n");
}
$stream('recovery-plan', json_encode($plan, JSON_THROW_ON_ERROR) . "\n");
[$status, $stdout, $stderr] = WPrismTest\ShellProbe::run('exec "$1" "$2" plan "$3" recoveryoffline before after unused "$4"',
    [PHP_BINARY, $package . '/fixtures/recovery-check-evidence.php', $sink, $revision], $root);
wprism_check($status === 0 && $stderr === '' && $stdout === "PASS: Importer recovery plan\n",
    'standalone verifier consumes complete private streams and closes its own load graph: ' . $stderr);
// Docker Compose forwards stdin: the inventory belongs to the loop, never its child query.
$inventorySource = file_get_contents($package . '/fixtures/recovery-check.sh');
$inventoryStart = strpos($inventorySource, 'while IFS=');
$inventoryEnd = strpos($inventorySource, "\nimporter_roundtrip_capture recovery-plan", $inventoryStart);
$inventoryBlock = substr($inventorySource, $inventoryStart, $inventoryEnd - $inventoryStart);
file_put_contents($sink . '/recovery-before-plan-tables.stdout', $inventory);
[$status, $stdout, $stderr] = WPrismTest\ShellProbe::run(<<<'SH'
set -euo pipefail
IMPORTER_EVIDENCE="$1"
fail() { printf '%s\n' "$*" >&2; exit 1; }
importer_dirty_capture() { cat >/dev/null; printf '%s\n' "$1"; }
SH
. "\n" . $inventoryBlock, [$sink], $root);
$expectedStages = array_map(static fn(string $table): string => 'recovery-columns-' . str_replace('_', '-', $table), ImporterRecoveryEvidence::TABLES);
wprism_check($status === 0 && $stderr === '' && $stdout === implode("\n", $expectedStages) . "\n",
    'actual inventory loop captures all 18 tables even when its query drains stdin');
foreach (['apply transaction commit', 'ledger transaction commit'] as $context) {
    [$status, $stdout, $stderr] = WPrismTest\ShellProbe::run(<<<'SH'
set -euo pipefail
fail() { printf '%s\n' "$*" >&2; exit 1; }
export CONF_PAIR=recoveryoffline COMPOSE='docker compose -p wprism-recoveryoffline -f exact.yml -f overlay.yml'
docker() { printf '%s\n' "$@"; }
. "$1"
importer_recovery_fault "$2" wprism apply --format=json
SH, [$package . '/fixtures/recovery.sh', $context], $root . '/sandbox');
    $expected = ['compose', '-p', 'wprism-recoveryoffline', '-f', 'exact.yml', '-f', 'overlay.yml', 'run', '--rm', '-T',
        '-e', 'WPRISM_TEST_MODE=1', '-e', 'WPRISM_TEST_FAIL_DB_CONTEXT=' . $context, 'cli2', 'wp', 'wprism', 'apply', '--format=json'];
    wprism_check($status === 0 && $stderr === '' && explode("\n", rtrim($stdout, "\n")) === $expected,
        'native fault transport retains both switches, the exact context and parent overlays: ' . $context);
}
wprism_check_summary('Importer recovery evidence');
