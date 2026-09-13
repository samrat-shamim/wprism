<?php
declare(strict_types=1);

// The locked 2.7.5 native Save fixture exercises the declared capsule through
// Snapshot's real identity/ledger and typed-column paths, not a substitute codec.
$root = dirname(__DIR__, 4);
$capsule = dirname(__DIR__, 2);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) {
    require_once "$root/sandbox/tests/lib/$file";
}
require_once "$root/sandbox/tests/offline/policy/manifest_fixtures.php";
wprism_test_define_agent_versions();
foreach (['Policy/Policy', 'Grammar/Tokens', 'Repository/Ledger', 'Repository/Snapshot',
    'Repository/IdentityNotes', 'Repository/RepositoryCompiler', 'Review/Lint'] as $file) {
    require_once "$root/agent/src/$file.php";
}

use WPrism\Canon;
use WPrism\Db;
use WPrism\Ledger;
use WPrism\NativeDatabaseProfile;
use WPrism\RepositoryCompiler;
use WPrism\Snapshot;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$manifest = Canon::decode(Canon::read_file($capsule . '/package/manifest.json'));
$fixture = Canon::decode(Canon::read_file($capsule . '/fixtures/native-export-templates.json'));
$core = Canon::decode(Canon::read_file($root . '/platform/adapter-library/core/manifest.json'));
$site = FrozenPolicy::site([$core, $manifest], WPRISM_SPEC_VERSION);
$policy = FrozenPolicy::policy([$core, $manifest], $site);
$native = json_encode($fixture['form'], JSON_THROW_ON_ERROR);
wprism_check_same($fixture['native_data_sha256'], hash('sha256', $native), 'fixture reproduces complete native Saved data');
$table = 'wt_iew_mapping_template';
$db = FakeWpdb::install()->enableInformationSchema()->enableJoinedCaptureSql()
    ->setPrimaryKey($table, 'id')->setTableEngine($table, 'InnoDB')
    ->setColumns($table, ['id' => 'int(11)', 'template_type' => 'varchar(255)', 'item_type' => 'varchar(255)',
        'name' => 'varchar(255)', 'data' => 'longtext'])
    ->setColumns('wprism_map', ['uuid' => 'char(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned'])
    ->setUniqueKey('wprism_map', ['uuid', 'id_kind'])->setTableEngine('wprism_map', 'InnoDB')->seedTable('wprism_map', [])
    ->seedTable('wp_users', $fixture['source_users'])->setTableEngine('wp_users', 'InnoDB')
    ->seedTable('wp_usermeta', [['umeta_id' => 7, 'user_id' => 2, 'meta_key' => 'session_tokens', 'meta_value' => 'local-session']])
    ->seedTable('wt_iew_action_history', [['id' => 8, 'data' => '{"file":"local-export.csv"}']]);
$rows = array_map(static fn(array $row): array => $row + ['data' => $native], $fixture['templates']);
$foreign = [
    ['id' => 3, 'template_type' => 'import', 'item_type' => 'user', 'name' => 'Local input', 'data' => '{broken'],
    ['id' => 4, 'template_type' => 'export', 'item_type' => 'product', 'name' => 'Selected users', 'data' => 'local-product'],
    ['id' => 5, 'template_type' => 'Export', 'item_type' => 'user', 'name' => 'Selected users', 'data' => 'case-variant'],
];
$db->seedTable($table, [...$rows, ...$foreign]);
WpStore::reset()->seedOptions(['home' => 'https://source.test']);
$sourceTokens = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
$transaction = static function (callable $action) use ($table): mixed {
    Db::start_repeatable_read('importer templates', new NativeDatabaseProfile(
        ['wp_' . $table, 'wp_wprism_map', 'wp_users'], ['wp_' . $table, 'wp_wprism_map']));
    try {
        $result = $action();
        Db::commit('importer templates');
        return $result;
    } catch (Throwable $failure) {
        Db::rollback_after_failure($failure, 'importer templates');
        throw $failure;
    }
};
$census = static function () use ($db, $table): array {
    $out = [];
    foreach ([$table, 'wprism_map', 'wp_users', 'wp_usermeta', 'wt_iew_action_history'] as $name) $out[$name] = $db->rows($name);
    return $out;
};
$capture = static fn(Tokens $tokens): array => $transaction(static fn() => Snapshot::capture($policy, $tokens, true));
$before = $census();
$entities = $capture($sourceTokens);
wprism_check_same(2, count($entities), 'Snapshot captures only the two exact export-user rows');
wprism_check_same($before[$table], $db->rows($table), 'Capture preserves every native template byte');
foreach ($entities as $entity) {
    $value = json_decode(Canon::decode($entity['content'])['columns']['data'], true, flags: JSON_THROW_ON_ERROR);
    $expected = $fixture['form'];
    $expected['filter_form_data']['wt_iew_email'] = ['user:template-reader', 'user:template-editor'];
    unset($expected['method_export_form_data']['selected_template']);
    wprism_check_same($expected, $value, 'all native form fields survive except the regenerated cursor and bound user IDs');
}
$sourceMap = $db->rows('wprism_map');
wprism_check_same([1, 2], array_column($sourceMap, 'local_id'), 'only owned source rows receive ledger identities');
$scratch = sys_get_temp_dir() . '/wprism-importer-templates-' . bin2hex(random_bytes(8));
register_shutdown_function(static fn() => manifest_fixture_remove_tree($scratch));
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
foreach ($entities as $entity) Canon::write_file($scratch . '/state/' . $entity['path'], $entity['content']);
$tree = RepositoryCompiler::compile($scratch, $policy)->tree();
wprism_check_same(2, count($tree), 'complete captured template documents compile');
wprism_check_same([], WPrism\Lint::scan_tree($scratch . '/state', $policy), 'native template fields lint without privacy exceptions');
$db->seedTable($table, $foreign)->setAutoIncrement($table, 800, 'id')
    ->seedTable('wprism_map', [])->seedTable('wp_users', $fixture['target_users']);
$targetTokens = new Tokens('https://target.test', 'https://target.test/wp-content/uploads');
$targetBefore = $census();
$write = static function (array $tree) use ($transaction, $policy, $targetTokens): array {
    return $transaction(static function () use ($tree, $policy, $targetTokens): array {
        $created = [];
        foreach ($tree as $entity) {
            $created[] = Snapshot::ensure_row($policy, $entity);
            Snapshot::finalize_row($policy, $targetTokens, $entity);
        }
        return $created;
    });
};
wprism_check_same([true, true], $write($tree), 'materializer creates target-local rows through real ledger bookkeeping');
$targetRows = array_slice($db->rows($table), count($foreign));
wprism_check_same([800, 801], array_column($targetRows, 'id'), 'target template IDs diverge from source IDs');
foreach ($targetRows as $row) {
    $value = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
    $expected = $fixture['form'];
    $expected['filter_form_data']['wt_iew_email'] = ['82', '93'];
    unset($expected['method_export_form_data']['selected_template']);
    wprism_check_same($expected, $value, 'target stores native string user IDs and the complete projected form');
}
$after = $census();
foreach (['wp_users', 'wp_usermeta', 'wt_iew_action_history'] as $name) {
    wprism_check_same($targetBefore[$name], $after[$name], "template Apply preserves $name");
}
wprism_check_same($foreign, array_slice($after[$table], 0, count($foreign)), 'Apply preserves import, product and case-variant rows');
wprism_check_same(array_column($entities, 'content'), array_column($capture($targetTokens), 'content'), 'complete target recapture equals source canonical templates');
wprism_check_same([false, false], $write($tree), 'repeat retains both target identities');
wprism_check_same($after, $census(), 'repeat preserves the complete target census');

$firstUuid = array_key_first($tree);
$first = $tree[$firstUuid];
$edited = $first;
$value = json_decode($edited['data']['columns']['data'], true, flags: JSON_THROW_ON_ERROR);
$value['filter_form_data']['wt_iew_email'] = ['user:template-editor'];
$edited['data']['columns']['data'] = json_encode($value, JSON_THROW_ON_ERROR);
$observed = false;
wprism_check_throws(static function () use ($transaction, $policy, $targetTokens, $edited, $db, $table, &$observed): void {
    $transaction(static function () use ($policy, $targetTokens, $edited, $db, $table, &$observed): void {
        Snapshot::finalize_row($policy, $targetTokens, $edited);
        $observed = json_decode($db->rows($table)[3]['data'], true)['filter_form_data']['wt_iew_email'] === ['93'];
        throw new RuntimeException('injected later template failure');
    });
}, RuntimeException::class, 'later failure aborts the real template write', 'injected later template failure');
wprism_check($observed, 'rollback reaches the rewritten native selection');
wprism_check_same($after, $census(), 'rollback restores templates, mappings and operational data');

foreach (['missing-login', 'raw-id', 'cursor', 'unknown-field', 'private-label', 'coerced-flag'] as $fault) {
    $invalid = $first;
    $value = json_decode($invalid['data']['columns']['data'], true, flags: JSON_THROW_ON_ERROR);
    if ($fault === 'missing-login') $value['filter_form_data']['wt_iew_email'] = ['user:absent-template-user'];
    if ($fault === 'raw-id') $value['filter_form_data']['wt_iew_email'] = ['2'];
    if ($fault === 'cursor') $value['method_export_form_data']['selected_template'] = '1';
    if ($fault === 'unknown-field') $value['filter_form_data']['new_filter'] = 1;
    if ($fault === 'private-label') $value['mapping_form_data']['mapping_selected_fields']['user_email'] = 'reader@example.test';
    if ($fault === 'coerced-flag') $value['mapping_form_data']['mapping_fields']['user_email'][1] = '1';
    $invalid['data']['columns']['data'] = json_encode($value, JSON_THROW_ON_ERROR);
    Canon::write_file($scratch . '/state/' . $first['path'], Canon::encode($invalid['data']));
    if ($fault === 'missing-login') {
        wprism_check_same(2, count(RepositoryCompiler::compile($scratch, $policy)->tree()), 'login existence remains target-local');
    } else {
        wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), RuntimeException::class,
            "compiler refuses $fault edits");
    }
    // Repository clearance is compiler-owned. The lower writer independently
    // validates shape and target bindings, but cannot authorize a private edit.
    if ($fault !== 'private-label') {
        wprism_check_throws(static fn() => $write([$firstUuid => $invalid]), RuntimeException::class, "materialization independently refuses $fault");
    }
    wprism_check_same($after, $census(), "$fault refusal preserves the entire target census");
}
Canon::write_file($scratch . '/state/' . $first['path'], $first['content']);

foreach (['missing-user', 'private-label', 'private-discarded-field', 'unknown-filter'] as $fault) {
    $invalidRows = $after[$table];
    $value = json_decode($invalidRows[3]['data'], true, flags: JSON_THROW_ON_ERROR);
    if ($fault === 'missing-user') $value['filter_form_data']['wt_iew_email'] = ['999'];
    if ($fault === 'private-label') $value['mapping_form_data']['mapping_selected_fields']['user_email'] = 'reader@example.test';
    if ($fault === 'private-discarded-field') $value['method_export_form_data']['api_key'] = 'private-credential-material';
    if ($fault === 'unknown-filter') $value['filter_form_data']['new_filter'] = 1;
    $invalidRows[3]['data'] = json_encode($value, JSON_THROW_ON_ERROR);
    $db->seedTable($table, $invalidRows);
    $beforeInvalidCapture = $census();
    wprism_check_throws(static fn() => $capture($targetTokens), RuntimeException::class, "Capture refuses native $fault");
    wprism_check_same($beforeInvalidCapture, $census(), "native $fault refusal preserves all data and identity maps");
}
$db->seedTable($table, $after[$table]);

// A native rename keeps the ledger identity; a same-name Save As is a
// separate row and cannot alias the existing identity during capture.
$renamed = $after[$table];
$renamed[3]['name'] = 'Renamed selection';
$db->seedTable($table, $renamed);
$renamedEntities = $capture($targetTokens);
wprism_check_same(array_column($entities, 'uuid'), array_column($renamedEntities, 'uuid'), 'native rename retains established ledger identity');
wprism_check_same('Renamed selection', Canon::decode($renamedEntities[0]['content'])['columns']['name'], 'renamed authored name is captured');
$duplicate = $renamed[3];
$duplicate['id'] = 900;
$db->seedTable($table, [...$renamed, $duplicate]);
$beforeDuplicate = $census();
wprism_check_throws(static fn() => $capture($targetTokens), RuntimeException::class, 'duplicate native tuple refuses full Capture');
wprism_check_same($beforeDuplicate, $census(), 'duplicate refusal rolls back any new ledger mapping');
$db->seedTable($table, $after[$table]);

// Adoption consults the complete native tuple and explicitly claims only
// the owned row. Matching names in other item/template types remain local.
$db->seedTable('wprism_map', []);
wprism_check_same(800, Snapshot::find_collision($policy, $first), 'natural-key collision resolves the owned complete tuple');
$beforeAdoption = $db->rows($table);
$transaction(static fn() => Snapshot::adopt($policy, $firstUuid, $table, 800));
wprism_check_same(800, Ledger::id_for($firstUuid, 'iew_template'), 'explicit adoption records the target identity');
wprism_check_same($beforeAdoption, $db->rows($table), 'adoption changes no native template bytes');

wprism_check_summary('importer export templates');
