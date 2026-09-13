<?php
declare(strict_types=1);

// Importer 2.7.5 saves user templates beside other WebToffee item types in
// one table. The engine must neither capture their payloads nor acquire their
// ids through a stale mapping, collation match or authored-file edit.
$root = dirname(__DIR__, 4);
if (($argv[1] ?? '') === '--compile-without-wordpress') {
    require_once __DIR__ . '/../../lib/agent_version.php';
    require_once __DIR__ . '/../../lib/frozen_policy.php';
    require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
    wprism_test_define_agent_versions();
    $input = json_decode(file_get_contents($argv[2] . '/probe.json'), true, flags: JSON_THROW_ON_ERROR);
    $policy = WPrismTest\FrozenPolicy::policy([$input['manifest']], $input['site']);
    $compiled = WPrism\RepositoryCompiler::compile($argv[2], $policy);
    echo json_encode(['entities' => count($compiled->tree()), 'wordpress' => function_exists('get_option'),
        'database' => isset($GLOBALS['wpdb'])], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/agent_version.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';
require_once __DIR__ . '/../policy/manifest_fixtures.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Repository/Snapshot.php';
require_once $root . '/agent/src/Repository/IdentityNotes.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Apply/TypedTableMaterializer.php';
require_once $root . '/agent/src/Capture/OptionsCapture.php';
require_once $root . '/agent/src/Capture/CaptureSafetyGates.php';
wprism_test_define_agent_versions();

use WPrism\Canon;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\Policy;
use WPrism\RepositoryCompiler;
use WPrism\Snapshot;
use WPrism\TableRowScope;
use WPrism\Tokens;
use WPrism\TypedTableCapture;
use WPrism\TypedTableMaterializer;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$manifest = ['name' => 'acme-templates', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'engine_features' => ['spec-window/v1', TableRowScope::FEATURE],
    'tables' => ['acme_templates' => ['class' => 'authored_snapshot', 'pk' => 'id',
        'id_kind' => 'acme_template', 'slug_column' => 'name',
        'identity' => ['mode' => 'natural_key', 'columns' => ['item_type', 'template_type', 'name']],
        'row_scope' => ['item_type' => 'user'],
        'columns' => ['item_type' => ['class' => 'authored'], 'template_type' => ['class' => 'authored'],
            'name' => ['class' => 'authored'], 'data' => ['class' => 'authored']], 'refs' => []]]];
$setMember = str_starts_with($argv[1] ?? '', '--scope-set=') ? substr($argv[1], 12) : null;
if ($setMember !== null) {
    if (!in_array($setMember, ['export', 'import'], true)) throw new RuntimeException('unknown scope-set fixture');
    $manifest['engine_features'][] = 'table-row-scope-sets/v1';
    sort($manifest['engine_features'], SORT_STRING);
    $manifest['tables']['acme_templates']['row_scope']['template_type'] = ['export', 'import'];
}
$scratch = sys_get_temp_dir() . '/wprism-row-scopes-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700, true);
register_shutdown_function(static fn() => manifest_fixture_remove_tree($scratch));
$load = static function (array $input) use ($scratch): Policy {
    $dir = $scratch . '/library-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    Canon::write_file("$dir/acme-templates.json", Canon::encode($input));
    return Policy::load(null, ['acme-templates'], adapterLibrary: manifest_fixture_adapter_library($dir));
};
$policy = $load($manifest);
$decl = $policy->declared_tables()['acme_templates'];
wprism_check_same($manifest['tables']['acme_templates']['row_scope'], $decl['row_scope'], 'real policy loader preserves explicit ownership');
foreach ([null, 1, true, 'user', [], ['user'], ['item_type' => 1], ['item_type' => ''], ['item_type' => '%'],
    ['item_type' => 'User Name'], ['item_type' => ['user']], ['bad`column' => 'user']] as $badScope) {
    $bad = $manifest;
    $bad['tables']['acme_templates']['row_scope'] = $badScope;
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, 'malformed scope refuses at load');
}
foreach (['feature', 'class', 'identity', 'identity-list', 'column', 'refs', 'codec'] as $fault) {
    $bad = $manifest;
    $row = &$bad['tables']['acme_templates'];
    if ($fault === 'feature') $bad['engine_features'] = ['spec-window/v1'];
    if ($fault === 'class') $row['class'] = 'runtime';
    if ($fault === 'identity') $row['identity']['columns'] = ['template_type', 'name'];
    if ($fault === 'identity-list') $row['identity']['columns'] = 'item_type';
    if ($fault === 'column') $row['columns']['item_type']['class'] = 'runtime';
    if ($fault === 'refs') $row['refs'] = [['column' => 'owner_id', 'kind' => 'post']];
    if ($fault === 'codec') {
        $bad['engine_features'][] = 'typed-column-codecs/v1';
        $bad['column_codecs'] = ['acme_templates' => ['item_type' => ['container' => 'php_serialized', 'leaves' => 'text']]];
    }
    unset($row);
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, "inert or ambiguous $fault declaration refuses");
}
wprism_check_throws(static fn() => WPrism\ManifestGrammar::validate_tables($manifest, 'site', true),
    RuntimeException::class, 'site policy cannot self-grant a feature');
foreach ([[$manifest, array_replace($manifest, ['name' => 'foreign'])]] as $identical) {
    wprism_check_same(1, count(FrozenPolicy::policy($identical, FrozenPolicy::site($identical, 3))->declared_tables()),
        'existing byte-identical owner sharing remains valid');
}
$unknown = $manifest;
$unknown['engine_features'] = ['spec-window/v1', 'table-row-scopes/v9'];
wprism_check_throws(static fn() => $load($unknown), RuntimeException::class, 'unknown scope feature refuses at load');
$oldVersion = $manifest;
$oldVersion['spec_version'] = 2;
wprism_check_throws(static fn() => $load($oldVersion), RuntimeException::class, 'scope feature cannot bypass the v3 channel');
$tooMany = $manifest;
$tooMany['tables']['acme_templates']['row_scope'] = array_fill_keys(array_map(static fn(int $i): string => 'kind_' . $i, range(1, 9)), 'user');
wprism_check_throws(static fn() => $load($tooMany), RuntimeException::class, 'scope discriminator count is bounded');
$twoFields = $decl;
$twoFields['row_scope']['template_type'] = 'export';
wprism_check(TableRowScope::matches($twoFields, ['item_type' => 'user', 'template_type' => 'export']),
    'all discriminator values admit an owned row');
wprism_check(!TableRowScope::matches($twoFields, ['item_type' => 'user', 'template_type' => 'import']),
    'matching only one discriminator grants no ownership');
$foreign = $manifest;
$foreign['name'] = 'foreign';
$foreign['tables']['acme_templates']['row_scope']['item_type'] = 'product';
foreach ([[$manifest, $foreign], [$foreign, $manifest]] as $pins) {
    wprism_check_throws(static fn() => FrozenPolicy::policy($pins, FrozenPolicy::site($pins, 3)),
        RuntimeException::class, 'different physical-table owners refuse in both pin orders');
}
$sidecar = $manifest;
$sidecar['tables']['acme_meta'] = ['class' => 'authored_snapshot_meta', 'attached_to' => ['table' => 'acme_templates', 'column' => 'owner_id'],
    'key_column' => 'meta_key', 'value_column' => 'meta_value', 'default_class' => 'authored'];
wprism_check_throws(static fn() => $load($sidecar), RuntimeException::class, 'unscoped sidecar inventory is refused');

$uuid = '11111111-1111-4111-8111-111111111111';
$source = ['id' => 2, 'item_type' => 'user', 'template_type' => 'export', 'name' => 'Selected', 'data' => 'https://source.test/path'];
$foreignRows = [
    ['id' => 3, 'item_type' => 'product', 'template_type' => 'export', 'name' => 'Selected', 'data' => 'alice@example.test'],
    ['id' => 4, 'item_type' => 'User', 'template_type' => 'export', 'name' => 'Selected', 'data' => 'sk_live_FOREIGNCREDENTIAL1234567890'],
    ['id' => 5, 'item_type' => 'user ', 'template_type' => 'export', 'name' => 'Selected', 'data' => 'foreign trailing space'],
];
$database = static function (array $rows): FakeWpdb {
    Db::forget_transaction_tracking();
    $db = FakeWpdb::install()->enableInformationSchema()->enableJoinedCaptureSql()->seedTable('acme_templates', $rows)
        ->setPrimaryKey('acme_templates', 'id')->setAutoIncrement('acme_templates', 800, 'id')
        ->setColumns('acme_templates', ['id' => 'int(11)', 'item_type' => 'varchar(255)', 'template_type' => 'varchar(255)',
            'name' => 'varchar(255)', 'data' => 'longtext'])->setTableEngine('acme_templates', 'InnoDB');
    $db->seedTable('wprism_map', [])->setColumns('wprism_map', ['uuid' => 'varchar(36)', 'id_kind' => 'varchar(64)',
        'local_id' => 'bigint unsigned', 'entity_type' => 'varchar(64)'])->setTableEngine('wprism_map', 'InnoDB');
    return $db;
};
WpStore::reset()->seedOptions(['home' => 'https://source.test']);
if ($setMember !== null) {
    $source['template_type'] = $setMember;
    $foreignRows[0]['template_type'] = 'import';
    $foreignRows[1]['item_type'] = 'user';
    $foreignRows[1]['template_type'] = 'Import';
    $foreignRows[2]['item_type'] = 'user';
    $foreignRows[2]['template_type'] = 'import ';
}
$db = $database([$source, ...$foreignRows]);
$identity = new class($uuid) {
    public array $seen = [];
    public function __construct(private string $uuid) {}
    public function identifyRow(string $table, array $decl, array $row, int $id): string {
        $this->seen[] = $id;
        return $this->uuid;
    }
};
$capture = new TypedTableCapture($identity, static function (): void {}, static fn() => null, static fn(string $name) => strtolower($name));
$tokens = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
$entities = $capture->capture_table('acme_templates', $decl, [], $tokens, true);
wprism_check_same([2], $identity->seen, 'Capture sees only byte-exact owned rows before minting identity');
wprism_check_same(1, count($entities), 'foreign secrets and PII never enter the owned capture stream');
wprism_check_same([$source, ...$foreignRows], $db->rows('acme_templates'), 'capture leaves all native rows intact');
$site = FrozenPolicy::site([$manifest], 3);
$policy = FrozenPolicy::policy([$manifest], $site);
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
Canon::write_file($scratch . '/state/' . $entities[0]['path'], $entities[0]['content']);
$compiled = RepositoryCompiler::compile($scratch, $policy);
wprism_check_same(1, count($compiled->tree()), 'real compiler admits an owned canonical row');
Canon::write_file($scratch . '/probe.json', Canon::encode(['manifest' => $manifest, 'site' => $site]));
$child = proc_open([PHP_BINARY, __FILE__, '--compile-without-wordpress', $scratch],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($child)) throw new RuntimeException('could not start isolated row scope compiler');
fclose($pipes[0]);
$childOut = stream_get_contents($pipes[1]);
$childErr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
wprism_check_same(0, proc_close($child), 'scoped table compiles in a fresh process');
wprism_check_same('', $childErr, 'isolated compilation has no diagnostics');
wprism_check_same(['entities' => 1, 'wordpress' => false, 'database' => false], json_decode($childOut, true),
    'ownership authorization needs neither WordPress nor a live database');

$entity = $compiled->tree()[$uuid];
foreach (['product', 'User', 'user ', null] as $outside) {
    $bad = Canon::decode($entities[0]['content']);
    $bad['columns']['item_type'] = $outside;
    Canon::write_file($scratch . '/state/' . $entities[0]['path'], Canon::encode($bad));
    wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), RuntimeException::class,
        'compiler rejects an edited canonical row outside ownership');
}
if ($setMember !== null) {
    foreach (['unknown', 'Import', 'import ', null, true, 1, ['import']] as $outside) {
        $bad = Canon::decode($entities[0]['content']);
        $bad['columns']['template_type'] = $outside;
        Canon::write_file($scratch . '/state/' . $entities[0]['path'], Canon::encode($bad));
        wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), RuntimeException::class,
            'set ownership rejects unknown or non-byte-exact canonical variants');
    }
}
Canon::write_file($scratch . '/state/' . $entities[0]['path'], $entities[0]['content']);

$db = $database($foreignRows);
wprism_check_same(null, Snapshot::find_collision($policy, $entity), 'natural adoption cannot select a case-only foreign discriminator');
$mapping = [];
$writer = new TypedTableMaterializer(static fn() => ['acme_templates' => $decl], static fn() => [],
    static function (string $key) use (&$mapping): ?int { return $mapping[$key] ?? null; },
    static function (string $key, string $table, string $kind, int $id) use (&$mapping): void { $mapping[$key] = $id; },
    static fn() => 0, static fn() => [], static fn() => false, static fn($value) => $value,
    static function (): void {}, static fn() => []);
$transaction = static function (callable $action) use (&$mapping): mixed {
    $mapBefore = $mapping;
    Db::start_repeatable_read('scoped row fixture', new NativeDatabaseProfile(['wp_acme_templates', 'wp_wprism_map'], ['wp_acme_templates']));
    try {
        $result = $action();
        Db::commit('scoped row fixture');
        return $result;
    } catch (Throwable $failure) {
        Db::rollback_after_failure($failure, 'scoped row fixture');
        $mapping = $mapBefore;
        throw $failure;
    }
};
$targetTokens = new Tokens('https://target.test/longer', 'https://target.test/longer/wp-content/uploads');
wprism_check_throws(static fn() => $writer->ensureRow($entity), RuntimeException::class,
    'new scoped rows require the transaction that protects ownership', 'no tracked transaction identity');
$transaction(static function () use ($writer, $entity, $targetTokens): void {
    wprism_check($writer->ensureRow($entity), 'phase one inserts a new owned row');
    $writer->finalizeRow($targetTokens, $entity);
});
wprism_check_same($foreignRows, array_slice($db->rows('acme_templates'), 0, 3), 'Apply preserves complete foreign rows');
$targetRow = $db->rows('acme_templates')[3];
wprism_check_same('https://target.test/longer/path', $targetRow['data'], 'owned payload uses the ordinary environment codec');
$transaction(static function () use ($writer, $entity, $targetTokens): void {
    wprism_check(!$writer->ensureRow($entity), 'repeat apply retains the mapped id');
    $writer->finalizeRow($targetTokens, $entity);
});
wprism_check_same(4, count($db->rows('acme_templates')), 'repeat apply creates no duplicate row');
$mapping[$uuid] = (int) $targetRow['id'];
$beforeFailure = $db->rows('acme_templates');
$changed = $entity;
$changed['data']['columns']['data'] = 'Changed before later failure';
$observedWrite = false;
wprism_check_throws(static function () use ($transaction, $writer, $changed, $targetTokens, &$observedWrite, $db): void {
    $transaction(static function () use ($writer, $changed, $targetTokens, &$observedWrite, $db): void {
        $writer->finalizeRow($targetTokens, $changed);
        $observedWrite = $db->rows('acme_templates')[3]['data'] === 'Changed before later failure';
        throw new RuntimeException('injected late failure');
    });
}, RuntimeException::class, 'a later failure aborts the owned write', 'injected late failure');
wprism_check($observedWrite, 'late failure occurs after the actual checked update');
wprism_check_same($beforeFailure, $db->rows('acme_templates'), 'rollback restores owned and foreign rows');
$db->simulateSnapshotConflict('FOR UPDATE');
wprism_check_throws(static fn() => $transaction(static function () use ($writer, $changed, $targetTokens, $targetRow): void {
    Db::update('wp_acme_templates', ['data' => 'pending before ownership read'], ['id' => (int) $targetRow['id']]);
    $writer->finalizeRow($targetTokens, $changed);
}), WPrism\TransientDbException::class, 'ownership locking read preserves verified native snapshot-conflict evidence', 'database snapshot conflict');
wprism_check_same($beforeFailure, $db->rows('acme_templates'), 'server-aborted ownership read restores every prior write');
wprism_check(!Db::connection_transaction_active('scoped conflict cleanup proof'), 'ownership conflict leaves a settled idle connection');
wprism_check_throws(static fn() => WPrism\TableRowOwnership::assert_live_row('acme_templates', $decl, 2, new FakeWpdb(), true),
    RuntimeException::class, 'ownership lock cannot read through a different database object', 'requires the bound database connection');
$recaptured = $capture->capture_table('acme_templates', $decl, [], $targetTokens, true);
wprism_check_same($entities[0]['content'], $recaptured[0]['content'], 'target recapture is byte-identical');
$beforeDelete = $db->rows('acme_templates');
$transaction(static function () use ($writer, $targetRow): void {
    $writer->deleteLocalRow('acme_templates', (int) $targetRow['id']);
    $writer->assertRowDeleted('acme_templates', (int) $targetRow['id']);
});
wprism_check_same($foreignRows, $db->rows('acme_templates'), 'owned deletion preserves all foreign rows');
$db->seedTable('acme_templates', $beforeDelete);

foreach (['ensure', 'finalize', 'delete', 'reparent'] as $operation) {
    $mapping[$uuid] = 4; // A retained id now points at the case-only foreign owner.
    $before = $db->rows('acme_templates');
    wprism_check_throws(static fn() => $transaction(static function () use ($writer, $entity, $targetTokens, $operation): void {
        if ($operation === 'ensure') $writer->ensureRow($entity);
        if ($operation === 'finalize') $writer->finalizeRow($targetTokens, $entity);
        if ($operation === 'delete') $writer->deleteLocalRow('acme_templates', 4);
        if ($operation === 'reparent') $writer->reparentLocalRow('acme_templates', 4, 'owner_id', 9);
    }), RuntimeException::class, "$operation refuses a foreign retained id", 'outside declared row_scope');
    wprism_check_same($before, $db->rows('acme_templates'), "$operation refusal preserves all native rows");
}
$db->seedTable('wprism_map', [['uuid' => $uuid, 'entity_type' => 'acme_templates', 'id_kind' => 'acme_template', 'local_id' => 4]]);
foreach (['prune_dead_map', 'observed_deleted_mapped_uuids', 'assert_all_mapped_rows_managed'] as $method) {
    $before = $db->rows('wprism_map');
    wprism_check_throws(static fn() => Snapshot::$method($policy), RuntimeException::class,
        "$method cannot convert ownership drift into disappearance", 'outside declared row_scope');
    wprism_check_same($before, $db->rows('wprism_map'), "$method refusal retains recovery evidence");
}
wprism_check_throws(static fn() => Snapshot::read_only_mapped_row_exists($policy, 'acme_templates', 4),
    WPrism\CommandRefusalException::class, 'scoped recovery refuses a foreign retained row', 'not backed by the exact strict target observation');

$beforeAdoption = $db->rows('wprism_map');
wprism_check_throws(static fn() => $transaction(static fn() => Snapshot::adopt($policy, $uuid, 'acme_templates', 4)),
    RuntimeException::class, 'adoption cannot claim a foreign native id', 'outside declared row_scope');
wprism_check_same($beforeAdoption, $db->rows('wprism_map'), 'refused adoption preserves the identity ledger');
$db->onQuery(static fn(string $sql): ?string => str_contains($sql, 'FROM `wp_acme_templates`') ? 'injected ownership read failure' : null);
wprism_check_throws(static fn() => $capture->capture_table('acme_templates', $decl, [], $targetTokens, true),
    RuntimeException::class, 'failed scoped capture cannot publish an empty roster', 'cannot read owned rows');
wprism_check_throws(static fn() => WPrism\TableRowOwnership::assert_live_row('acme_templates', $decl, 4, $db),
    RuntimeException::class, 'failed ownership observation is never permission to write', 'cannot verify row ownership');
$db->onQuery(null);
$mappedManifest = $manifest;
$mappedManifest['tables']['acme_templates']['identity'] = ['mode' => 'mapped'];
$mappedPolicy = FrozenPolicy::policy([$mappedManifest], FrozenPolicy::site([$mappedManifest], 3));
$db->seedTable('acme_templates', $foreignRows)->seedTable('wprism_map', []);
Snapshot::assert_all_mapped_rows_managed($mappedPolicy);
wprism_check_same([], $db->rows('wprism_map'), 'DR coverage ignores unmanaged foreign rows without minting identities');
$db->seedTable('acme_templates', [$source, ...$foreignRows]);
wprism_check_throws(static fn() => Snapshot::assert_all_mapped_rows_managed($mappedPolicy), RuntimeException::class,
    'DR coverage still refuses an unmanaged owned row', 'has no ledger identity');
$db->setColumns('acme_templates', ['id' => 'int(11)', 'item_type' => 'int(11)', 'template_type' => 'varchar(255)',
    'name' => 'varchar(255)', 'data' => 'longtext']);
wprism_check_throws(static fn() => Snapshot::assert_row_schema('acme_templates', $decl), RuntimeException::class,
    'numeric native discriminator cannot reinterpret text ownership', 'must have a native text type');

// Exercise the option-name producer with Snapshot's actual liveness callback.
// A PK present in a foreign slice is not an owned row awaiting identity minting.
$optionManifest = $manifest;
$optionManifest['option_name_refs'] = [['class' => 'authored', 'id_kind' => 'acme_template',
    'match' => '^acme_template_(?<id>[1-9][0-9]*)$', 'autoload' => 'yes']];
$optionPolicy = FrozenPolicy::policy([$optionManifest], FrozenPolicy::site([$optionManifest], 3));
foreach ([false, true] as $strict) {
    $db = $database([$source, ...$foreignRows]);
    $optionRows = [];
    foreach ([3, 4, 5] as $id) {
        $optionRows[] = ['option_id' => $id, 'option_name' => 'acme_template_' . $id, 'option_value' => 'foreign setting', 'autoload' => 'yes'];
    }
    $db->seedTable('options', $optionRows);
    $optionTokens = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
    $optionTokens->policy = $optionPolicy;
    $options = new WPrism\OptionsCapture($optionPolicy, $optionTokens, static function (): void {},
        static fn() => null, static fn(Policy $selected, string $kind, int $id): bool => Snapshot::row_exists_for_kind($selected, $kind, $id));
    $result = $options->capture(!$strict, strictReadOnly: $strict);
    wprism_check_same([], $result['unscoped_option_name_refs'], 'foreign, case-only and trailing-space option ids do not claim owned liveness');
    (new WPrism\CaptureSafetyGates($scratch))->assertOptions($result['unclassified'], $result['unscoped_refs'],
        $result['unscoped_option_name_refs'], $optionTokens);
    wprism_check_same(3, count($optionTokens->warnings), 'foreign option-name references retain existing unresolved warnings');
    wprism_check_same($optionRows, $db->rows('options'), 'foreign option references leave native options intact');
    $optionRows[] = ['option_id' => 2, 'option_name' => 'acme_template_2', 'option_value' => 'owned setting', 'autoload' => 'yes'];
    $db->seedTable('options', $optionRows);
    $result = $options->capture(!$strict, strictReadOnly: $strict);
    wprism_check_same([['option' => 'acme_template_2', 'id_kind' => 'acme_template', 'id' => 2]],
        $result['unscoped_option_name_refs'], 'an actual owned unminted row still reaches the blocking capture gate');
    wprism_check_throws(static fn() => (new WPrism\CaptureSafetyGates($scratch))->assertOptions([], [],
        $result['unscoped_option_name_refs'], $optionTokens), WPrism\CommandRefusalException::class,
        'the real option-name safety gate preserves owned identity refusal', 'real, unminted table rows');
    $db->onQuery(static fn(string $sql): ?string => str_contains($sql, 'FROM `wp_acme_templates`') ? 'injected row liveness read failure' : null);
    wprism_check_throws(static fn() => $options->capture(!$strict, strictReadOnly: $strict), RuntimeException::class,
        'failed scoped liveness cannot silently drop an option-name reference', 'cannot verify owned row existence');
    $db->onQuery(null);
}

if ($setMember === null) {
    $setManifest = $manifest;
    $setManifest['engine_features'][] = 'table-row-scope-sets/v1';
    sort($setManifest['engine_features'], SORT_STRING);
    $setManifest['tables']['acme_templates']['row_scope']['template_type'] = ['export', 'import'];
    $setPolicy = $load($setManifest);
    $setDecl = $setPolicy->declared_tables()['acme_templates'];
    $multi = $setManifest;
    $multi['tables']['acme_templates']['row_scope']['item_type'] = ['customer', 'user'];
    $multiPolicy = $load($multi);
    $db = $database([$source, array_replace($source, ['id' => 6, 'template_type' => 'import']),
        array_replace($source, ['id' => 7, 'item_type' => 'customer']),
        array_replace($source, ['id' => 8, 'item_type' => 'customer', 'template_type' => 'import']), ...$foreignRows]);
    $db->setUniqueKey('wprism_map', ['uuid', 'id_kind']);
    Db::start_repeatable_read('set scope natural identity', new NativeDatabaseProfile(
        ['wp_acme_templates', 'wp_wprism_map'], ['wp_acme_templates', 'wp_wprism_map']));
    try {
        $both = Snapshot::capture($multiPolicy, $tokens, true);
        Db::commit('set scope natural identity');
    } catch (Throwable $failure) {
        Db::rollback_after_failure($failure, 'set scope natural identity');
        throw $failure;
    }
    wprism_check_same(4, count($both), 'one real Snapshot intersects two set-valued discriminators and captures every owned variant');
    wprism_check_same(4, count(array_unique(array_column($db->rows('wprism_map'), 'uuid'))),
        'same native name in different variants receives distinct real ledger identities');
    $max = $setManifest;
    $max['tables']['acme_templates']['row_scope']['template_type'] = array_map(
        static fn(int $i): string => sprintf('v%02d', $i), range(1, 16));
    $maxDecl = $load($max)->declared_tables()['acme_templates'];
    wprism_check(TableRowScope::matches($maxDecl, ['item_type' => 'user', 'template_type' => 'v16']),
        'maximum declared set retains its final alternative');
    foreach (['export', 'import'] as $member) {
        wprism_check(TableRowScope::matches($setDecl, ['item_type' => 'user', 'template_type' => $member]),
            'each explicit set member grants ownership with the other discriminator');
        wprism_check(!TableRowScope::matches($setDecl, ['item_type' => 'product', 'template_type' => $member]),
            'a set member cannot bypass another discriminator');
    }
    foreach ([[], ['export'], ['import', 'export'], ['export', 'export'], ['export', 1],
        ['export', ''], ['export', 'import '], ['export', '%'], ['a' => 'export', 'b' => 'import'],
        array_map(static fn(int $i): string => sprintf('v%02d', $i), range(1, 17))] as $badSet) {
        $bad = $setManifest;
        $bad['tables']['acme_templates']['row_scope']['template_type'] = $badSet;
        wprism_check_throws(static fn() => $load($bad), RuntimeException::class,
            'set scope rejects empty, singleton, unsorted, duplicate, malformed and excessive alternatives');
    }
    foreach (['spec-window/v1', 'table-row-scopes/v1', 'table-row-scope-sets/v1'] as $missing) {
        $bad = $setManifest;
        $bad['engine_features'] = array_values(array_diff($bad['engine_features'], [$missing]));
        wprism_check_throws(static fn() => $load($bad), RuntimeException::class,
            'scope sets require every owning feature: ' . $missing);
    }
    wprism_check_throws(static fn() => WPrism\ManifestGrammar::validate_tables($setManifest, 'site', true),
        RuntimeException::class, 'site policy cannot grant set ownership');
    foreach (['export', 'import'] as $member) {
        $child = proc_open([PHP_BINARY, __FILE__, '--scope-set=' . $member],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($child)) throw new RuntimeException('could not start scope-set product-path proof');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        wprism_check_same(0, proc_close($child), 'complete ownership product-path suite passes for ' . $member);
        wprism_check_same('', $err, 'set ownership subprocess is warning-free');
        wprism_check(preg_match('/\A(?:ok: [^\r\n]+\n)+PASS: typed-table row scopes \(\d+ assertions\)\n\z/D', $out) === 1,
            'set ownership subprocess returns its complete admitted suite verdict');
    }
}

wprism_check_summary('typed-table row scopes');
