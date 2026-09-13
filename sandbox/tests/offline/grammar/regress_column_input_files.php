<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
$scratch = sys_get_temp_dir() . '/wprism-column-input-' . bin2hex(random_bytes(8));
mkdir($scratch . '/content/imports', 0700, true);
define('WP_CONTENT_DIR', $scratch . '/content');
define('WP_CONTENT_URL', 'https://target.test/custom-content');
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/agent_version.php';
require_once __DIR__ . '/../policy/manifest_fixtures.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';
$root = dirname(__DIR__, 4);
wprism_test_define_agent_versions();
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Capture/TypedTableCapture.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Apply/TypedTableMaterializer.php';
require_once $root . '/agent/src/Apply/ColumnInputFiles.php';
require_once $root . '/agent/src/Apply/ApplyPlanner.php';
require_once $root . '/agent/src/Apply/Apply.php';
require_once $root . '/agent/src/Review/Lint.php';

use WPrism\AuthoredValueCodec;
use WPrism\Canon;
use WPrism\ColumnCodecGrammar;
use WPrism\ColumnInputFiles;
use WPrism\EnvironmentValues;
use WPrism\InputFileBinding;
use WPrism\Policy;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

register_shutdown_function(static fn() => manifest_fixture_remove_tree($scratch));
$spec = ['directory' => 'imports', 'extensions' => ['csv', 'txt']];
$leaf = ['class' => 'authored', 'input_file' => $spec];
$contract = ['class' => 'authored', 'object_fields' => ['method' => ['class' => 'authored', 'object_fields' => [
    'file' => $leaf, 'mode' => ['class' => 'authored', 'enum' => ['local']]]],
    'ordinary' => ['class' => 'authored', 'plain_data' => true]]];
$codec = ['container' => 'json', 'value' => $contract];
$manifest = ['name' => 'column-input', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'engine_features' => ['column-input-files/v1', 'json-column-codecs/v1', 'spec-window/v1', 'typed-column-codecs/v1', 'typed-column-values/v1'],
    'tables' => ['authored_inputs' => ['class' => 'authored_snapshot', 'pk' => 'id', 'id_kind' => 'auth_input',
        'slug_column' => 'name', 'identity' => ['mode' => 'mapped'],
        'columns' => ['name' => ['class' => 'authored'], 'data' => ['class' => 'authored']], 'refs' => []]],
    'column_codecs' => ['authored_inputs' => ['data' => $codec]]];
$load = static function (array $input) use ($scratch): Policy {
    $dir = $scratch . '/library-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    Canon::write_file($dir . '/column-input.json', Canon::encode($input));
    return Policy::load(null, ['column-input'], adapterLibrary: manifest_fixture_adapter_library($dir));
};
$policy = $load($manifest);
wprism_check_same(Canon::encode(['data' => $codec]), Canon::encode($policy->column_codec_rules('authored_inputs')), 'real policy admits explicit input dependencies');
$bad = $manifest;
$bad['column_codecs']['authored_inputs']['data']['value'] = $leaf;
wprism_check_throws(static fn() => $load($bad), RuntimeException::class, 'whole-column input leaves refuse', 'exact nested object field');
$bad = $manifest;
$bad['tables']['authored_inputs']['identity'] = ['mode' => 'composite_ref'];
wprism_check_throws(static fn() => ColumnCodecGrammar::validate_column_codecs($bad, 'input fixture'), RuntimeException::class,
    'column grammar refuses composite input owners', 'ordinary typed-table identity');
foreach (['column-input-files/v1', 'typed-column-values/v1', 'typed-column-codecs/v1'] as $feature) {
    $bad = $manifest;
    $bad['engine_features'] = array_values(array_diff($bad['engine_features'], [$feature]));
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, "input files require $feature");
}
foreach ([null, [], ['directory' => '../imports', 'extensions' => ['csv']], ['directory' => '/imports', 'extensions' => ['csv']],
    ['directory' => 'imports', 'extensions' => []], ['directory' => 'imports', 'extensions' => ['txt', 'csv']],
    ['directory' => 'imports', 'extensions' => ['CSV']], ['directory' => 'imports', 'extensions' => ['csv', 'csv']],
    $spec + ['default' => 'source.csv'], $spec + ['alias' => 'shared']] as $invalid) {
    $bad = $manifest;
    $bad['column_codecs']['authored_inputs']['data']['value']['object_fields']['method']['object_fields']['file'] = ['class' => 'authored', 'input_file' => $invalid];
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, 'input declarations have a closed directory and extension grammar');
}
foreach (['options', 'post_meta', 'term_meta', 'user_meta', 'post_types', 'taxonomies'] as $surface) {
    $bad = $manifest;
    $bad[$surface]['fixture'] = $leaf;
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, "$surface cannot borrow column input authority");
}
foreach ([$leaf + ['plain_data' => true], $leaf + ['allow_pii' => true], $leaf + ['field_labels' => 'label'],
    ['class' => 'env', 'input_file' => $spec]] as $invalid) {
    $bad = $manifest;
    $bad['column_codecs']['authored_inputs']['data']['value']['object_fields']['method']['object_fields']['file'] = $invalid;
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, 'dependency semantics cannot combine with another value role');
}

WpStore::reset()->seedOptions(['home' => 'https://source.test']);
$wpdb = FakeWpdb::install()->enableInformationSchema()->enableJoinedCaptureSql()
    ->setPrimaryKey('authored_inputs', 'id')->setAutoIncrement('authored_inputs', 800, 'id')
    ->setColumns('authored_inputs', ['id' => 'int(11)', 'name' => 'varchar(255)', 'data' => 'longtext'])
    ->setTableEngine('authored_inputs', 'InnoDB');
$sourceTokens = new Tokens('https://source.test', 'https://source.test/uploads');
$targetTokens = new Tokens('https://target.test', 'https://target.test/uploads');
$native = ['method' => ['file' => 'https://source.test/wp-content/imports/private-source.csv', 'mode' => 'local'],
    'ordinary' => 'https://source.test/public'];
$expected = ['method' => ['file' => InputFileBinding::MARKER, 'mode' => 'local'], 'ordinary' => '{{home}}/public'];
$target = ['method' => ['file' => WP_CONTENT_URL . '/imports/replacement.csv', 'mode' => 'local'], 'ordinary' => 'https://target.test/public'];
foreach (['json', 'php_serialized'] as $container) {
    $one = ['container' => $container, 'value' => $contract];
    $encode = static fn($v) => $container === 'json' ? json_encode($v, JSON_THROW_ON_ERROR) : serialize($v);
    $canonical = ColumnCodecGrammar::capture_value($encode($native), $one, $sourceTokens, 'input file', 'data');
    wprism_check_same($encode($expected), $canonical, "$container canonical dependency carries no source path");
    wprism_check_throws(static fn() => ColumnCodecGrammar::apply_value($canonical, $one, $targetTokens, 'input file'), RuntimeException::class,
        "$container refuses missing target capability");
    $calls = [];
    $result = ColumnCodecGrammar::apply_value($canonical, $one, $targetTokens, 'input file', static function ($spec, $path) use (&$calls, $target) {
        $calls[] = $path;
        return $target['method']['file'];
    });
    wprism_check_same([['method', 'file']], $calls, 'binding capability receives the exact declared field coordinate');
    wprism_check_same($encode($target), $result, "$container rebinds only the local file and ordinary URL");
    wprism_check_same($canonical, ColumnCodecGrammar::capture_value($result, $one, $targetTokens, 'input file', 'data'), "$container recapture is a fixed point");
    $draft = ['method' => ['file' => '', 'mode' => 'local']];
    wprism_check_same($encode($draft), ColumnCodecGrammar::apply_value($encode($draft), $one, $targetTokens, 'draft'), 'empty drafts need no file binding');
    wprism_check_same($encode($draft), ColumnCodecGrammar::capture_value($encode($draft), $one, $sourceTokens, 'draft'), 'empty drafts stay empty');
}
foreach (['source.csv', '/imports/source.csv', 'ftp://source.test/imports/source.csv',
    'https://source.test/imports/source.csv?token=secret', 'https://name:secret@source.test/imports/source.csv',
    'https://source.test/imports/source%20file.csv', 'https://source.test/imports/../other/source.csv',
    'https://source.test/imports/source.php', 'https://source.test/imports/nested/source.csv'] as $bad) {
    wprism_check_throws(static fn() => AuthoredValueCodec::capture($bad, $leaf, $sourceTokens, static fn() => null, 'input'),
        RuntimeException::class, 'malformed native input cannot manufacture target authority');
}
foreach ([null, false, [], ['environment' => 'wrong'], InputFileBinding::MARKER + ['filename' => 'source.csv'], 'https://source.test/imports/a.csv'] as $bad) {
    wprism_check_throws(static fn() => AuthoredValueCodec::assert_value($bad, $leaf, true, 'input'), RuntimeException::class,
        'canonical files permit only dependency presence or empty draft');
}
$private = $native;
$private['ordinary'] = ['email' => 'customer@example.net'];
wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value(json_encode($private), $codec, $sourceTokens, 'private sibling', 'data'),
    RuntimeException::class, 'file dependency does not waive sibling privacy');

$uuid = '11111111-1111-4111-8111-111111111111';
$name = InputFileBinding::name($uuid, 'data', ['method', 'file']);
wprism_check_same('column_file:' . $uuid . ':data.method.file', $name, 'binding name is derived from canonical ownership and field location');
wprism_check_throws(static fn() => InputFileBinding::name($uuid . "\n", 'data', []), RuntimeException::class, 'binding UUID rejects trailing bytes');
wprism_check_throws(static fn() => InputFileBinding::name($uuid, 'data', ['method.file']), RuntimeException::class, 'literal dots cannot alias a field path');
$wpdb->seedTable('authored_inputs', [['id' => 2, 'name' => 'Template', 'data' => json_encode($native, JSON_THROW_ON_ERROR)]]);
$decl = $manifest['tables']['authored_inputs'];
$identity = new class($uuid) {
    public function __construct(private string $uuid) {}
    public function identifyRow(string $table, array $decl, array $row, int $id): string { return $this->uuid; }
};
$capture = new WPrism\TypedTableCapture($identity, static function (): void {}, static fn() => null, static fn($v) => strtolower($v));
$captureRows = static fn(Tokens $tokens): array => $capture->capture_table('authored_inputs', $decl, [], $tokens,
    true, false, $policy->column_codec_rules('authored_inputs'));
$entities = $captureRows($sourceTokens);
$repo = $scratch . '/repository';
$site = WPrismTest\FrozenPolicy::site([$manifest], 3);
$frozen = WPrismTest\FrozenPolicy::policy([$manifest], $site);
Canon::write_file($repo . '/site.wprism.json', Canon::encode($site));
foreach ($entities as $entity) Canon::write_file($repo . '/state/' . $entity['path'], $entity['content']);
$tree = WPrism\RepositoryCompiler::compile($repo, $frozen)->tree();
wprism_check_same(1, count($tree), 'immutable compilation admits an environment dependency without resolving its file');
wprism_check_same([$name], array_keys(ColumnInputFiles::declarations($policy, $tree)), 'compiled tree yields only its exact declared binding');
$variants = $manifest;
$variants['engine_features'] = [...$variants['engine_features'], 'column-value-cases/v1', 'table-row-scopes/v1', 'table-row-scope-sets/v1'];
sort($variants['engine_features'], SORT_STRING);
$variants['tables']['authored_inputs']['columns']['mode'] = ['class' => 'authored'];
$variants['tables']['authored_inputs']['row_scope'] = ['mode' => ['export', 'import']];
$variants['column_codecs']['authored_inputs']['data'] = ['container' => 'json', 'value_cases' => ['column' => 'mode', 'cases' => [
    ['equals' => 'export', 'value' => ['class' => 'authored', 'plain_data' => true]],
    ['equals' => 'import', 'value' => $contract]]]];
$variantPolicy = $load($variants);
$variantTree = $tree;
$variantTree[$uuid]['data']['columns']['mode'] = 'import';
wprism_check_same([$name], array_keys(ColumnInputFiles::declarations($variantPolicy, $variantTree)), 'row-selected import contract derives the same exact owner');
$variantTree[$uuid]['data']['columns']['mode'] = 'export';
$variantTree[$uuid]['data']['columns']['data'] = json_encode(['Public' => 'label'], JSON_THROW_ON_ERROR);
wprism_check_same([], ColumnInputFiles::declarations($variantPolicy, $variantTree), 'unselected input contracts create no environment dependency');
wprism_check_same([], WPrism\Lint::scan_tree($repo . '/state', $policy), 'canonical dependency has no source URL or undeclared reference lint');
$missing = ColumnInputFiles::projection($repo, $policy, $tree);
wprism_check_same([['name' => $name, 'required' => true]], $missing['env_missing'], 'Plan exposes missing target provisioning');
wprism_check_throws(static fn() => ColumnInputFiles::resolve_work($repo, $policy, $tree), RuntimeException::class, 'selected Apply work refuses before mutation without a file');

file_put_contents(WP_CONTENT_DIR . '/imports/replacement.csv', "user_login\nsynthetic\n");
$fileBytes = file_get_contents(WP_CONTENT_DIR . '/imports/replacement.csv');
$provisioned = ColumnInputFiles::provision($repo, $policy, $tree, $name, 'replacement.csv');
wprism_check_same(['name' => $name, 'previously_set' => false], $provisioned, 'provisioning records target intent');
wprism_check_same([$name => 'replacement.csv'], EnvironmentValues::read($repo), 'existing private environment store owns the filename');
wprism_check_same(0600, fileperms($repo . '/' . EnvironmentValues::FILE) & 0777, 'binding intent file is private');
wprism_check_same($fileBytes, file_get_contents(WP_CONTENT_DIR . '/imports/replacement.csv'), 'provisioning preserves input file bytes');
wprism_check_same(json_encode($native), $wpdb->rows('authored_inputs')[0]['data'], 'provisioning does not mutate authored rows');
$public = WPrism\Apply::set_env_option($repo, $name, 'replacement.csv', ['adapter_library' => $policy->adapter_library()]);
wprism_check_same(['name' => $name, 'previously_set' => true], $public, 'public env-set compiles and authorizes the exact owner before provisioning');
wprism_check_throws(static fn() => WPrism\Apply::set_env_option($repo, $name . '.forged', 'replacement.csv', ['adapter_library' => $policy->adapter_library()]),
    RuntimeException::class, 'public env-set refuses a forged coordinate');
$lease = ColumnInputFiles::lock_work($repo, $policy, $tree);
wprism_check_same([$name => WP_CONTENT_URL . '/imports/replacement.csv'], $lease->values(), 'Apply locks the private intent used by its selected fields');
wprism_check_throws(static fn() => EnvironmentValues::set($repo, 'parallel_option', 'new value'), RuntimeException::class,
    'concurrent intent publication refuses while Apply holds its read lock', 'environment values are in use');
$child = $scratch . '/set-intent.php';
file_put_contents($child, '<?php require ' . var_export($root . '/agent/src/Apply/EnvironmentValues.php', true)
    . '; try { \\WPrism\\EnvironmentValues::set($argv[1], "parallel_option", "new value"); echo "written"; }'
    . ' catch (RuntimeException $failure) { echo $failure->getMessage(); exit(17); }');
$runChild = static function () use ($child, $repo): array {
    $process = proc_open([PHP_BINARY, $child, $repo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('cannot start environment lock evidence child');
    $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $out, $error];
};
$blockedChild = $runChild();
wprism_check_same(17, $blockedChild[0], 'a separate PHP process cannot publish through the held Apply lock');
wprism_check(str_contains($blockedChild[1], 'environment values are in use') && $blockedChild[2] === '', 'cross-process refusal is exact and warning-free');
$lease->assert_current();
$lease->release();
wprism_check_same([0, 'written', ''], $runChild(), 'the same setter succeeds after Apply releases its lock');
wprism_check_same('replacement.csv', EnvironmentValues::read($repo)[$name], 'serialized map publication preserves existing input intent');
wprism_check_same('new value', EnvironmentValues::read($repo)['parallel_option'], 'serialized map publication adds only the new binding');
$lockPath = $repo . '/.wprism/environment-values.lock';
$held = EnvironmentValues::lock($repo);
rename($lockPath, $lockPath . '.held');
file_put_contents($lockPath, ''); chmod($lockPath, 0600);
wprism_check_throws(static fn() => EnvironmentValues::assert_lock($repo, $held), RuntimeException::class,
    'a replacement lock inode cannot inherit operation authority', 'changed identity');
fclose($held);
unlink($lockPath); rename($lockPath . '.held', $lockPath);
$lease = ColumnInputFiles::lock_work($repo, $policy, $tree);
$lease->release();
$beforeIntent = file_get_contents($repo . '/' . EnvironmentValues::FILE);
foreach (['absent.csv', '../replacement.csv', '/replacement.csv', 'replacement.php', 'replacement.csv?x=1'] as $bad) {
    wprism_check_throws(static fn() => ColumnInputFiles::provision($repo, $policy, $tree, $name, $bad), RuntimeException::class,
        'invalid local file refuses before intent publication');
    wprism_check_same($beforeIntent, file_get_contents($repo . '/' . EnvironmentValues::FILE), 'failed provisioning preserves previous intent');
}
wprism_check_throws(static fn() => ColumnInputFiles::provision($repo, $policy, $tree, $name . '.other', 'replacement.csv'), RuntimeException::class,
    'operator cannot provision an undeclared alias');
symlink(WP_CONTENT_DIR . '/imports/replacement.csv', WP_CONTENT_DIR . '/imports/linked.csv');
wprism_check_throws(static fn() => ColumnInputFiles::resolve_file('linked.csv', $spec), RuntimeException::class, 'symlink leaf refuses');
mkdir(WP_CONTENT_DIR . '/imports/directory.csv');
wprism_check_throws(static fn() => ColumnInputFiles::resolve_file('directory.csv', $spec), RuntimeException::class, 'directory disguised as CSV refuses');
symlink(WP_CONTENT_DIR . '/imports', WP_CONTENT_DIR . '/alias');
wprism_check_throws(static fn() => ColumnInputFiles::resolve_file('replacement.csv', ['directory' => 'alias', 'extensions' => ['csv']]), RuntimeException::class,
    'symlink directory refuses');

$wpdb->seedTable('wprism_map', [['uuid' => $uuid, 'entity_type' => 'authored_inputs', 'id_kind' => 'auth_input', 'local_id' => 2]]);
$projection = ColumnInputFiles::projection($repo, $policy, $tree);
wprism_check_same([], $projection['env_missing'], 'available target intent clears its missing checklist');
wprism_check_same([$uuid => true], $projection['input_rebinds'], 'different live pointer requires a planned rebind');
$row = ['uuid' => $uuid, 'type' => 'authored_inputs', 'path' => $entities[0]['path']];
$plan = ['unchanged' => [$row], 'update' => [], 'drift' => [], 'conflict' => []];
$rebound = WPrism\ApplyPlanner::project_input_rebinds($plan, $projection['input_rebinds']);
wprism_check_same([], $rebound['unchanged'], 'input pointer changes become planned writes even when canonical state is equal');
wprism_check_same($uuid, $rebound['update'][0]['uuid'], 'rebind keeps the exact row owner');
foreach (['drift', 'conflict'] as $bucket) {
    $hostilePlan = ['unchanged' => [], 'update' => [], 'drift' => [], 'conflict' => []];
    $hostilePlan[$bucket] = [$row];
    wprism_check_same($hostilePlan, WPrism\ApplyPlanner::project_input_rebinds($hostilePlan, [$uuid => true]), "$bucket cannot be widened by environment intent");
}
$targetTokens->bind_input_files(ColumnInputFiles::resolve_work($repo, $policy, $tree));
$mapping = [];
$wpdb->seedTable('authored_inputs', []);
$writer = new WPrism\TypedTableMaterializer(static fn() => ['authored_inputs' => $decl], static fn() => [],
    static function (string $id) use (&$mapping): ?int { return $mapping[$id] ?? null; },
    static function (string $id, string $table, string $kind, int $local) use (&$mapping): void { $mapping[$id] = $local; },
    static fn() => 0, static fn() => [], static fn() => false, static fn($v) => $v, static function (): void {},
    static fn() => $policy->column_codec_rules('authored_inputs'));
wprism_check_throws(static fn() => $writer->ensureRow($tree[$uuid]), RuntimeException::class, 'phase one independently requires binding preflight');
wprism_check_same([], $wpdb->rows('authored_inputs'), 'failed phase one inserted no placeholder');
wprism_check_same([], $mapping, 'failed phase one published no identity');
$transaction = static function (callable $action) use (&$mapping): mixed {
    $before = $mapping;
    WPrism\Db::start_repeatable_read('column input files', new WPrism\NativeDatabaseProfile(['wp_authored_inputs'], ['wp_authored_inputs']));
    try { $result = $action(); WPrism\Db::commit('column input files'); return $result; }
    catch (Throwable $failure) { WPrism\Db::rollback_after_failure($failure, 'column input files'); $mapping = $before; throw $failure; }
};
$apply = static function () use ($writer, $tree, $targetTokens): void {
    foreach ($tree as $entity) { $writer->ensureRow($entity, $targetTokens); $writer->finalizeRow($targetTokens, $entity); }
};
$transaction($apply);
wprism_check_same($target, json_decode($wpdb->rows('authored_inputs')[0]['data'], true), 'real typed writes bind target file and preserve authored siblings');
$rows = $wpdb->rows('authored_inputs');
$transaction($apply);
wprism_check_same($rows, $wpdb->rows('authored_inputs'), 'repeat Apply is byte-identical');
wprism_check_same($entities[0]['content'], $captureRows($targetTokens)[0]['content'], 'real recapture is independent of target filename');
$wpdb->seedTable('wprism_map', [['uuid' => $uuid, 'entity_type' => 'authored_inputs', 'id_kind' => 'auth_input', 'local_id' => $mapping[$uuid]]]);
wprism_check_same([], ColumnInputFiles::projection($repo, $policy, $tree)['input_rebinds'], 'successful Apply clears the rebind observation');
$targetTokens->bind_input_files([$name => WP_CONTENT_URL . '/imports/changed.csv']);
$observed = false;
wprism_check_throws(static function () use ($transaction, $apply, $wpdb, &$observed): void { $transaction(static function () use ($apply, $wpdb, &$observed): void {
    $apply();
    $observed = str_contains($wpdb->rows('authored_inputs')[0]['data'], 'changed.csv');
    throw new RuntimeException('native failure after file pointer write');
}); }, RuntimeException::class, 'later failure rolls the pointer write back', 'native failure after file pointer write');
wprism_check($observed, 'rollback test observes the changed pointer before failure');
wprism_check_same($rows, $wpdb->rows('authored_inputs'), 'rollback restores every native row byte');
unlink(WP_CONTENT_DIR . '/imports/replacement.csv');
wprism_check_same([['name' => $name, 'required' => true]], ColumnInputFiles::projection($repo, $policy, $tree)['env_missing'],
    'removed file makes availability missing even after successful Apply');
wprism_check_throws(static fn() => ColumnInputFiles::resolve_work($repo, $policy, $tree), RuntimeException::class, 'deleted input is rechecked for each Apply');
wprism_check_same([], ColumnInputFiles::resolve_work($repo, $policy, []), 'unselected bindings do not block unrelated scoped work');

wprism_check_summary('column input files');
