<?php
declare(strict_types=1);

// Native delete_template removes exactly one saved row. These product seams
// must preserve opposite-type identities, local jobs/users and excluded rows.
$root = dirname(__DIR__, 4);
$capsule = dirname(__DIR__, 2);
$scratch = sys_get_temp_dir() . '/wprism-importer-delete-' . bin2hex(random_bytes(8));
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
require_once $capsule . '/fixtures/template-deletion-evidence.php';

use WPrism\Canon;
use WPrism\Db;
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
    ['id' => 2, 'template_type' => 'import', 'item_type' => 'user', 'name' => 'Draft input mapping', 'data' => json_encode($draft)],
    ['id' => 3, 'template_type' => 'export', 'item_type' => 'user', 'name' => 'Selected users', 'data' => json_encode($export['form'])]];
$db->seedTable($table, [...$rows, ...$foreign]);
WpStore::reset()->seedOptions(['home' => 'http://localhost:9244']);
$sourceTokens = new Tokens('http://localhost:9244', 'http://localhost:9244/wp-content/uploads');
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
$compiled = RepositoryCompiler::compile($repo, $policy);
$tree = $compiled->tree();


$capability = $policy->deletion_capability('table:' . $table);
wprism_check($capability !== null, 'shipped adapter declares saved user-template deletion');
if ($capability === null) wprism_check_summary('Importer template deletion');
wprism_check_same([], $capability['cascades'], 'native row-only deletion needs no inferred cascade');
wprism_check_same([], $capability['guards'], 'reviewed templates and history carry independent form snapshots');
wprism_check_same('all_active_owners', $capability['executable_owner_boundary'], 'unknown executable owners cannot silently extend the empty reference boundary');
$owner = 'plugin:' . $manifest['plugin'];
wprism_check_same([$owner], $capability['declaring_executable_owners'], 'only the reviewed Importer executable declares template deletion');
wprism_check_same([['format' => 'wprism-executable-tree/v1',
    'root' => 'plugins/users-customers-import-export-for-wp-woocommerce',
    'sha256' => '5aa9ac5e9fc7dc10a3ad38ea3ce324592b952937d56c171e8129879fea888a2a']],
    $capability['declaring_executable_owner_identities'][$owner], 'destructive semantics pin the exact official 2.7.5 executable tree');

$publish = static function (array $work) use ($repo): void {
    manifest_fixture_remove_tree($repo . '/state');
    foreach ($work as $entity) Canon::write_file($repo . '/state/' . $entity['path'], $entity['content']);
};
$baseline = $census();
foreach ($tree as $uuid => $entity) {
    $id = WPrism\Ledger::id_for($uuid, 'iew_template');
    $remaining = array_values(array_filter($baseline[$table], static fn(array $row): bool => (int) $row['id'] !== $id));
    $expected = $baseline;
    $expected[$table] = $remaining;
    $db->seedTable($table, $remaining);
    $live = $capture($sourceTokens);
    $intents = WPrism\Deletion::capture_tombstones($compiled, $live, $policy);
    wprism_check_same(1, count($intents), 'one native ' . $entity['data']['columns']['template_type'] . ' disappearance authors one tombstone');
    wprism_check_same(Canon::encode(['format' => WPrism\Deletion::FORMAT, 'uuid' => $uuid, 'kind' => 'table', 'type' => $table,
        'expected_hash' => $entity['hash'], 'expected_revision' => $compiled->revision_hash(), 'source_path' => $entity['path']]),
        $intents[0]['content'], 'tombstone binds the exact previously compiled identity, preimage and revision');
    wprism_check_same($expected, $census(), 'Capture preserves every remaining native row, job, user and identity');
    $publish([...$live, ...$intents]);
    $deleted = RepositoryCompiler::compile($repo, $policy);
    wprism_check_same([$uuid], array_keys($deleted->deletions()), 'actual repository compiler retains exactly the selected deletion');
    wprism_check_same($intents, WPrism\Deletion::capture_tombstones($deleted, $live, $policy), 'repeated Capture retains tombstone bytes');

    $db->seedTable($table, $baseline[$table]);
    wprism_check_same([], WPrism\Deletion::capture_tombstones($deleted, $capture($sourceTokens), $policy), 'native reappearance withdraws deletion intent without replacing identity');
    $remove = static function () use ($policy, $uuid, $table, $id): void {
        Snapshot::delete_row($policy, $uuid, $table);
        Snapshot::assert_row_deleted($policy, $table, $id);
    };
    wprism_check_throws(static fn() => $transaction(static function () use ($remove): void {
        $remove();
        throw new RuntimeException('injected failure after exact typed-row removal');
    }), RuntimeException::class, 'failure after row removal reaches transaction rollback');
    wprism_check_same($baseline, $census(), 'rollback restores the complete native fixture and identity census');
    $db->failNextQuery('injected typed deletion readback failure', 'SELECT COUNT(*) FROM `wp_' . $table . '`');
    wprism_check_throws(static fn() => $transaction($remove), mysqli_sql_exception::class,
        'strict transaction transport refuses an actual failed deletion postimage read');
    wprism_check_same($baseline, $census(), 'failed postimage verification rolls the deleted row back');
    $transaction($remove);
    wprism_check_same($expected, $census(), 'typed materialization removes only the exact selected row and leaves convergence metadata to Apply');
    $db->seedTable($table, $baseline[$table]);
    $publish($entities);
    wprism_check_same($compiled->artifact_hash(), RepositoryCompiler::compile($repo, $policy)->artifact_hash(), 'restored source reproduces its exact baseline artifact');
}
foreach ([4, 5] as $outside) {
    wprism_check_throws(static fn() => $transaction(static fn() => Snapshot::delete_local_row($policy, $table, $outside)),
        RuntimeException::class, 'generic row ownership refuses excluded local template ' . $outside);
    wprism_check_same($baseline, $census(), 'refused excluded deletion preserves the complete fixture');
}
// The live oracle consumes actual compiler IR, whose table entry has `type`
// but no synthetic `kind`. Exercise that boundary before allocating a host.
$db->seedTable($table, $foreign);
$live = $capture($sourceTokens);
$publish([...$live, ...WPrism\Deletion::capture_tombstones($compiled, $live, $policy)]);
$deleted = RepositoryCompiler::compile($repo, $policy);
$beforeRepository = ['revision' => $compiled->revision_hash(), 'tree' => $tree, 'deletions' => []];
$afterRepository = ['revision' => $deleted->revision_hash(), 'tree' => $deleted->tree(), 'deletions' => $deleted->deletions()];
ImporterTemplateDeletionEvidence::tombstones($beforeRepository, $afterRepository);
wprism_check(true, 'live tombstone oracle accepts actual compiler entries for three disappearances');
foreach (['missing-intent', 'wrong-preimage', 'wrong-revision', 'wrong-source', 'extra-survivor'] as $fault) {
    $bad = $afterRepository;
    $uuid = array_key_first($bad['deletions']);
    switch ($fault) {
        case 'missing-intent': unset($bad['deletions'][$uuid]); break;
        case 'wrong-preimage': $bad['deletions'][$uuid]['data']['expected_hash'] = str_repeat('a', 64); break;
        case 'wrong-revision': $bad['deletions'][$uuid]['data']['expected_revision'] = str_repeat('b', 64); break;
        case 'wrong-source': $bad['deletions'][$uuid]['data']['source_path'] = 'tables/other/row.json'; break;
        case 'extra-survivor': $bad['tree'][$uuid] = $tree[$uuid]; break;
    }
    wprism_check_throws(static fn() => ImporterTemplateDeletionEvidence::tombstones($beforeRepository, $bad), RuntimeException::class,
        'live tombstone oracle rejects ' . $fault);
}
wprism_check_summary('Importer template deletion');
