<?php
declare(strict_types=1);

// The native Save fixture crosses the package's actual row cases and ledger;
// CSV contents remain outside the repository even when template names overlap.
$root = dirname(__DIR__, 4);
$capsule = dirname(__DIR__, 2);
$scratch = sys_get_temp_dir() . '/wprism-import-templates-' . bin2hex(random_bytes(8));
mkdir($scratch . '/content/webtoffee_import', 0700, true);
define('WP_CONTENT_DIR', $scratch . '/content');
define('WP_CONTENT_URL', 'https://target.test/wp-content');
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/sandbox/tests/lib/frozen_policy.php';
require_once $root . '/sandbox/tests/offline/policy/manifest_fixtures.php';
wprism_test_define_agent_versions();
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Repository/IdentityNotes.php';
require_once $root . '/agent/src/Repository/Snapshot.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Apply/ColumnInputFiles.php';
require_once $root . '/agent/src/Review/Lint.php';

use WPrism\Canon;
use WPrism\ColumnInputFiles;
use WPrism\Db;
use WPrism\InputFileBinding;
use WPrism\NativeDatabaseProfile;
use WPrism\RepositoryCompiler;
use WPrism\Snapshot;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

register_shutdown_function(static fn() => manifest_fixture_remove_tree($scratch));
$manifest = Canon::decode(Canon::read_file($capsule . '/package/manifest.json'));
$fixture = Canon::decode(Canon::read_file($capsule . '/fixtures/native-import-templates.json'));
$export = Canon::decode(Canon::read_file($capsule . '/fixtures/native-export-templates.json'));
$core = Canon::decode(Canon::read_file($root . '/platform/adapter-library/core/manifest.json'));
$site = FrozenPolicy::site([$core, $manifest], WPRISM_SPEC_VERSION);
$policy = FrozenPolicy::policy([$core, $manifest], $site);
$native = json_encode($fixture['form'], JSON_THROW_ON_ERROR);
wprism_check_same($fixture['native_data_sha256'], hash('sha256', $native), 'fixture reproduces the complete native import Save');
$table = 'wt_iew_mapping_template';
$db = FakeWpdb::install()->enableInformationSchema()->enableJoinedCaptureSql()
    ->setPrimaryKey($table, 'id')->setTableEngine($table, 'InnoDB')
    ->setColumns($table, ['id' => 'int(11)', 'template_type' => 'varchar(255)', 'item_type' => 'varchar(255)', 'name' => 'varchar(255)', 'data' => 'longtext'])
    ->setColumns('wprism_map', ['uuid' => 'char(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned'])
    ->setUniqueKey('wprism_map', ['uuid', 'id_kind'])->setTableEngine('wprism_map', 'InnoDB')->seedTable('wprism_map', [])
    ->seedTable('wp_users', $export['source_users'])->setTableEngine('wp_users', 'InnoDB')
    ->seedTable('wp_usermeta', [['umeta_id' => 7, 'user_id' => 2, 'meta_key' => 'session_tokens', 'meta_value' => 'local-session']])
    ->seedTable('wt_iew_action_history', [['id' => 9, 'data' => '{"file":"local-job.csv"}']]);
$draft = $fixture['form'];
$draft['method_import_form_data']['wt_iew_local_file'] = '';
$foreign = [['id' => 4, 'template_type' => 'import', 'item_type' => 'product', 'name' => 'Selected users', 'data' => '{broken'],
    ['id' => 5, 'template_type' => 'Import', 'item_type' => 'user', 'name' => 'Selected users', 'data' => '{broken']];
$rows = [['id' => 1, 'template_type' => 'import', 'item_type' => 'user', 'name' => 'Selected users', 'data' => $native],
    ['id' => 2, 'template_type' => 'import', 'item_type' => 'user', 'name' => 'Draft', 'data' => json_encode($draft)],
    ['id' => 3, 'template_type' => 'export', 'item_type' => 'user', 'name' => 'Selected users', 'data' => json_encode($export['form'])]];
$db->seedTable($table, [...$rows, ...$foreign]);
WpStore::reset()->seedOptions(['home' => 'http://localhost:9244']);
$sourceTokens = new Tokens('http://localhost:9244', 'http://localhost:9244/wp-content/uploads');
$targetTokens = new Tokens('https://target.test', 'https://target.test/wp-content/uploads');
$transaction = static function (callable $action) use ($table): mixed {
    Db::start_repeatable_read('import templates', new NativeDatabaseProfile(['wp_' . $table, 'wp_wprism_map', 'wp_users'], ['wp_' . $table, 'wp_wprism_map']));
    try { $result = $action(); Db::commit('import templates'); return $result; }
    catch (Throwable $failure) { Db::rollback_after_failure($failure, 'import templates'); throw $failure; }
};
$census = static function () use ($db, $table): array {
    $out = [];
    foreach ([$table, 'wprism_map', 'wp_users', 'wp_usermeta', 'wt_iew_action_history'] as $name) $out[$name] = $db->rows($name);
    return $out;
};
$capture = static fn(Tokens $tokens): array => $transaction(static fn() => Snapshot::capture($policy, $tokens, true));
$entities = $capture($sourceTokens);
wprism_check_same(3, count($entities), 'both import templates and the same-name export are owned');
wprism_check_same([...$rows, ...$foreign], $db->rows($table), 'Capture preserves complete native rows');
$repo = $scratch . '/repo';
Canon::write_file($repo . '/site.wprism.json', Canon::encode($site));
foreach ($entities as $entity) Canon::write_file($repo . '/state/' . $entity['path'], $entity['content']);
$tree = RepositoryCompiler::compile($repo, $policy)->tree();
$inputs = ColumnInputFiles::declarations($policy, $tree);
wprism_check_same(1, count($inputs), 'only the nonempty import file creates a binding, despite the same-name export');
$binding = array_key_first($inputs);
$uuid = $inputs[$binding]['uuid'];
$original = $tree[$uuid];
$value = json_decode($original['data']['columns']['data'], true, flags: JSON_THROW_ON_ERROR);
wprism_check_same(InputFileBinding::MARKER, $value['method_import_form_data']['wt_iew_local_file'], 'source input becomes only dependency presence');
wprism_check(!isset($value['method_import_form_data']['selected_template']), 'source wizard cursor is projected');
wprism_check_same([['field' => 'Email']], $value['mapping_form_data']['mapping_selected_fields']['user_email'], 'import headers are expression references, not export labels');
wprism_check(!str_contains(Canon::encode($tree), 'remembered.csv'), 'source filename is absent from every canonical entity');
wprism_check_same([], WPrism\Lint::scan_tree($repo . '/state', $policy), 'both row cases lint without a whole-column privacy waiver');
$db->seedTable($table, $foreign)->setAutoIncrement($table, 800, 'id')->seedTable('wprism_map', [])->seedTable('wp_users', $export['target_users']);
$before = $census();
$write = static function (array $work) use ($policy, $targetTokens, $transaction): array {
    return $transaction(static function () use ($policy, $targetTokens, $work): array {
        $created = [];
        foreach ($work as $entity) {
            $created[] = Snapshot::ensure_row($policy, $entity, $targetTokens);
            Snapshot::finalize_row($policy, $targetTokens, $entity);
        }
        return $created;
    });
};
wprism_check_throws(static fn() => $write([$uuid => $original]), RuntimeException::class, 'missing input refuses before row or identity publication');
wprism_check_same($before, $census(), 'missing input changes no table');
file_put_contents(WP_CONTENT_DIR . '/webtoffee_import/replacement.csv', "Login,Email\nindependent,independent@example.test\n");
ColumnInputFiles::provision($repo, $policy, $tree, $binding, 'replacement.csv');
wprism_check_same($before, $census(), 'private provisioning does not write templates or users');
$targetTokens->bind_input_files(ColumnInputFiles::resolve_work($repo, $policy, $tree));
wprism_check_same([true, true, true], $write($tree), 'checked materialization creates all three distinct natural-key identities');
$after = $census();
$localId = WPrism\Ledger::id_for($uuid, 'iew_template');
$read = static fn(): array => array_values(array_filter($db->rows($table), static fn(array $row): bool => (int) $row['id'] === $localId))[0];
wprism_check($localId >= 800, 'target import ID differs from source');
$expected = $fixture['form'];
unset($expected['method_import_form_data']['selected_template']);
$expected['method_import_form_data']['wt_iew_local_file'] = WP_CONTENT_URL . '/webtoffee_import/replacement.csv';
wprism_check_same($expected, json_decode($read()['data'], true), 'every native import field survives with the independent target pointer');
foreach (['wp_users', 'wp_usermeta', 'wt_iew_action_history'] as $name) wprism_check_same($before[$name], $after[$name], "Apply preserves $name");
wprism_check_same($foreign, array_slice($after[$table], 0, 2), 'non-user and case-variant templates preserve exact bytes');
wprism_check_same(Canon::encode(array_column($entities, 'content', 'uuid')), Canon::encode(array_column($capture($targetTokens), 'content', 'uuid')),
    'complete import/export recapture reaches the source canonical tree independently of local row order');
wprism_check_same([false, false, false], $write($tree), 'repeat preserves all identities');
wprism_check_same($after, $census(), 'repeat preserves the complete native census');
foreach (['missing-selected', 'empty-selected', 'disabled-definition'] as $fault) {
    $invalidValue = $value;
    if ($fault === 'missing-selected') unset($invalidValue['mapping_form_data']['mapping_selected_fields']['user_pass']);
    if ($fault === 'empty-selected') $invalidValue['mapping_form_data']['mapping_selected_fields']['user_pass'] = [];
    if ($fault === 'disabled-definition') $invalidValue['mapping_form_data']['mapping_fields']['user_pass'] = [[], 0];
    $invalid = $original['data'];
    $invalid['columns']['data'] = json_encode($invalidValue, JSON_THROW_ON_ERROR);
    Canon::write_file($repo . '/state/' . $original['path'], Canon::encode($invalid));
    wprism_check_throws(static fn() => RepositoryCompiler::compile($repo, $policy), RuntimeException::class,
        "compiler refuses $fault password mapping before Apply", 'schema_content_mismatch');
    wprism_check_same($after, $census(), "$fault compiler refusal preserves complete native state");
}
Canon::write_file($repo . '/state/' . $original['path'], $original['content']);
$nativeRows = $after[$table];
foreach (['missing-selected', 'empty-selected', 'disabled-definition'] as $fault) {
    $badRows = $nativeRows;
    foreach ($badRows as &$row) if ((int) $row['id'] === $localId) {
        $bad = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
        if ($fault === 'missing-selected') unset($bad['mapping_form_data']['mapping_selected_fields']['user_pass']);
        if ($fault === 'empty-selected') $bad['mapping_form_data']['mapping_selected_fields']['user_pass'] = '';
        if ($fault === 'disabled-definition') $bad['mapping_form_data']['mapping_fields']['user_pass'] = ['', 0];
        $row['data'] = json_encode($bad, JSON_THROW_ON_ERROR);
    }
    unset($row);
    $db->seedTable($table, $badRows);
    $beforeBad = $census();
    wprism_check_throws(static fn() => $capture($targetTokens), RuntimeException::class,
        "Capture refuses native $fault password mapping", 'user_pass');
    wprism_check_same($beforeBad, $census(), "$fault Capture refusal preserves complete native state");
}
$db->seedTable($table, $nativeRows);
$edited = $original;
$editedValue = $value;
$editedValue['mapping_form_data']['mapping_selected_fields']['display_name'] = [['field' => 'LocalDisplay']];
$edited['data']['columns']['data'] = json_encode($editedValue);
$observed = false;
wprism_check_throws(static function () use ($transaction, $policy, $targetTokens, $edited, $read, &$observed): void {
    $transaction(static function () use ($policy, $targetTokens, $edited, $read, &$observed): void {
        Snapshot::finalize_row($policy, $targetTokens, $edited);
        $observed = json_decode($read()['data'], true)['mapping_form_data']['mapping_selected_fields']['display_name'] === '{LocalDisplay}';
        throw new RuntimeException('later native write failure');
    });
}, RuntimeException::class, 'post-write failure rolls back through the real transaction', 'later native write failure');
wprism_check($observed, 'rollback witness reaches the changed native expression');
wprism_check_same($after, $census(), 'rollback restores templates, ledger and runtime rows');
foreach (['cursor', 'source-file', 'raw-expression', 'private-literal', 'display-prefix', 'disabled-secret'] as $fault) {
    $invalid = $original['data'];
    $bad = $value;
    if ($fault === 'cursor') $bad['method_import_form_data']['selected_template'] = '1';
    if ($fault === 'source-file') $bad['method_import_form_data']['wt_iew_local_file'] = $fixture['form']['method_import_form_data']['wt_iew_local_file'];
    if ($fault === 'raw-expression') $bad['mapping_form_data']['mapping_selected_fields']['user_email'] = '{Email}';
    if ($fault === 'private-literal') $bad['mapping_form_data']['mapping_selected_fields']['user_email'] = [['text' => 'private@example.net']];
    if ($fault === 'display-prefix') $bad['mapping_form_data']['mapping_selected_fields']['display_name'] = [['text' => 'Local '], ['field' => 'Display']];
    if ($fault === 'disabled-secret') $bad['mapping_form_data']['mapping_fields']['user_pass'] = [[['text' => 'private-credential-material']], 0];
    $invalid['columns']['data'] = json_encode($bad);
    Canon::write_file($repo . '/state/' . $original['path'], Canon::encode($invalid));
    wprism_check_throws(static fn() => RepositoryCompiler::compile($repo, $policy), RuntimeException::class, "compiler refuses $fault before Apply",
        $fault === 'display-prefix' ? 'repository_pii_not_allowed' : null);
    wprism_check_same($after, $census(), "$fault refusal preserves native state");
}
Canon::write_file($repo . '/state/' . $original['path'], $original['content']);
foreach (['private-discarded', 'literal-password', 'display-prefix', 'remote-mode', 'malformed-owned-json'] as $fault) {
    $badRows = $after[$table];
    foreach ($badRows as &$row) if ((int) $row['id'] === $localId) {
        $bad = json_decode($row['data'], true);
        if ($fault === 'private-discarded') $bad['method_import_form_data']['api_key'] = 'private-credential-material';
        if ($fault === 'literal-password') $bad['mapping_form_data']['mapping_fields']['user_pass'] = ['private-credential-material', 0];
        if ($fault === 'display-prefix') $bad['mapping_form_data']['mapping_fields']['display_name'] = ['Local {Display}', 1];
        if ($fault === 'remote-mode') $bad['method_import_form_data']['wt_iew_file_from'] = 'url';
        $row['data'] = $fault === 'malformed-owned-json' ? '{broken' : json_encode($bad);
    }
    unset($row);
    $db->seedTable($table, $badRows);
    $beforeBad = $census();
    wprism_check_throws(static fn() => $capture($targetTokens), RuntimeException::class, "Capture refuses owned native $fault",
        $fault === 'display-prefix' ? 'personal name' : null);
    wprism_check_same($beforeBad, $census(), "$fault Capture preserves all native state");
}
wprism_check_summary('importer saved import templates');
