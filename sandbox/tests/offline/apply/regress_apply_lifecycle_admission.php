<?php
declare(strict_types=1);

// Public Apply must reject unready code before target observation enrolls identity.
$root = dirname(__DIR__, 4);
$runtime = $root;
require_once __DIR__ . '/../../lib/check.php';
$case = $argv[1] ?? null;
if ($case === null) {
    foreach (['inactive', 'missing', 'locked', 'ready', 'forced', 'storage-missing', 'storage-stale', 'storage-ready', 'storage-locked', 'storage-forced', 'storage-plan', 'storage-explain', 'storage-capture', 'storage-row-lock'] as $child) {
        passthru(implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, __FILE__, $child])), $status);
        wprism_check_same(0, $status, $child . ' public lifecycle admission case passes');
    }
    wprism_check_summary('Apply lifecycle admission');
}
$storageCase = str_starts_with($case, 'storage-');
$scratch = sys_get_temp_dir() . '/wprism-lifecycle-admission-' . bin2hex(random_bytes(8));
define('ABSPATH', $root . '/');
define('WP_CONTENT_DIR', $scratch . '/content');
define('WP_CONTENT_URL', 'https://work.example.test/content');
define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
define('WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins');
require_once __DIR__ . '/../../lib/agent_version.php';
wprism_test_define_agent_versions();
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once $runtime . '/agent/src/Apply/ApplyRequestCoordinator.php';
require_once $runtime . '/agent/src/Repository/RepositoryAuthorization.php';

use WPrism\AdapterLibrary;
use WPrism\ApplyRequestCoordinator;
use WPrism\Canon;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\RepositoryCompiler;
use WPrism\TableSchema;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

final class WP_Hook {
    public array $callbacks = [];
}
function get_taxonomies(array $args = [], string $output = 'names'): array { return []; }
function get_taxonomy(string $name): object { return (object) ['name' => $name, 'object_type' => [], 'hierarchical' => false]; }
function validate_plugin(string $plugin): bool { return true; }
function get_plugins(): array { return ($GLOBALS['case'] ?? '') === 'missing' ? [] : ['lifecycle-fixture/plugin.php' => ['Version' => '1.0.0']]; }
function parse_blocks(string $body): array {
    if (isset($GLOBALS['authored_work_parser'])) {
        ($GLOBALS['authored_work_parser'])();
    }
    return [['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $body, 'innerContent' => [$body]]];
}
function serialize_blocks(array $blocks): string { return implode('', array_column($blocks, 'innerHTML')); }
$GLOBALS['wp_filter'] = [];
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
        }
        rmdir($path);
    } elseif (file_exists($path)) {
        unlink($path);
    }
};
register_shutdown_function(static fn() => $remove($scratch));
$repo = $scratch . '/repo';
foreach (['state/options'] as $directory) mkdir($repo . '/' . $directory, 0700, true);
$repo = (string) realpath($repo);
mkdir(WP_CONTENT_DIR . '/themes/fixture', 0700, true);
file_put_contents(WP_CONTENT_DIR . '/themes/fixture/style.css', "/*\nTheme Name: Lifecycle fixture\nVersion: 1.0.0\n*/\n");

$libraryRoot = $scratch . '/library';
mkdir($libraryRoot . '/adapter-packages', 0700, true);
foreach (['profiles.json', 'capabilities/platform.json', 'capabilities/adapter-authorities.json', 'core/manifest.json', 'core/disposition.json'] as $relative) {
    $destination = $libraryRoot . '/platform/adapter-library/' . $relative;
    if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0700, true);
    copy($root . '/platform/adapter-library/' . $relative, $destination);
}
$manifestPath = $libraryRoot . '/platform/adapter-library/core/manifest.json';
$manifest = Canon::decode(Canon::read_file($manifestPath));
$manifest['tables']['authored_inputs'] = ['class' => 'authored_snapshot', 'pk' => 'id', 'id_kind' => 'auth_input',
    'identity' => ['mode' => 'natural_key', 'column' => 'name'], 'slug_column' => 'name',
    'columns' => ['name' => ['class' => 'authored'], 'data' => ['class' => 'authored']], 'refs' => []];
if ($storageCase) {
    $manifest['spec_version'] = 3;
    $manifest['engine_features'] = ['spec-window/v1', 'storage-prerequisites/v1'];
    sort($manifest['engine_features'], SORT_STRING);
    $manifest['options']['fixture_storage_version'] = ['class' => 'runtime'];
    $manifest['storage_prerequisites'] = [['option' => 'fixture_storage_version', 'equals' => '1.0.0']];
}
Canon::write_file($manifestPath, Canon::encode($manifest));
$rules = ['authored_work_0' => ['class' => 'authored', 'autoload' => 'yes']];
Canon::write_file($repo . '/site.wprism.json', Canon::encode([
    'spec_version' => WPRISM_SPEC_VERSION, 'manifests' => ['core'],
    'policy' => ['post_types' => [], 'taxonomies' => [], 'options' => $rules],
]));
$library = AdapterLibrary::fromSourceTree($libraryRoot);
$policy = Policy::load($repo, adapterLibrary: $library);
$records = [];
foreach ($policy->authored_options() as $name => $rule) $records[$name] = OptionState::absent();
foreach (['active_plugins' => ['lifecycle-fixture/plugin.php'], 'stylesheet' => 'fixture', 'template' => 'fixture'] as $name => $value) {
    $records[$name] = OptionState::present($value, 'yes');
}
$records['authored_work_0'] = OptionState::present('Desired option', 'yes');
Canon::write_file($repo . '/state/options/core.json', Canon::encode(OptionState::document($records)));
$uuid = WPrism\Uuid::v5(WPrism\Uuid::NAMESPACE_WPRISM, 'authored_inputs:Template');
$document = ['columns' => ['name' => 'Template', 'data' => 'Public'],
    'meta' => (object) [], 'table' => 'authored_inputs', 'uuid' => $uuid];
Canon::write_file($repo . '/state/tables/authored_inputs/' . $uuid . '--template.json', Canon::encode($document));
$compiled = RepositoryCompiler::compile($repo, $policy);
$artifact = $scratch . '/compiled.json';
$compiled->write($artifact);
mkdir(WP_PLUGIN_DIR . '/lifecycle-fixture', 0700, true);
file_put_contents(WP_PLUGIN_DIR . '/lifecycle-fixture/plugin.php', "<?php\n/*\nPlugin Name: Lifecycle fixture\nVersion: 1.0.0\n*/\n");
if ($case === 'missing') unlink(WP_PLUGIN_DIR . '/lifecycle-fixture/plugin.php');
$liveActive = ($storageCase || in_array($case, ['ready', 'locked'], true)) ? ['lifecycle-fixture/plugin.php'] : [];
WpStore::reset()->seedOptions([
    'home' => 'https://work.example.test', 'siteurl' => 'https://work.example.test',
    'admin_email' => 'admin@work.example.test', 'active_plugins' => $liveActive, 'stylesheet' => 'fixture', 'template' => 'fixture',
])->ensureUploadDir();
$db = FakeWpdb::install()->enableInformationSchema()->enableFullApplySqlExtensions()->enableJoinedCaptureSql();
foreach (TableSchema::core_capture_required_columns() as $property => $columns) {
    $db->seedTable($db->$property, [])->setColumns($db->$property, array_fill_keys($columns, 'longtext'))->setTableEngine($db->$property, 'InnoDB');
}
$db->seedTable('wp_options', [
    ['option_id' => 1, 'option_name' => 'active_plugins', 'option_value' => serialize($liveActive), 'autoload' => 'yes'],
    ['option_id' => 2, 'option_name' => 'stylesheet', 'option_value' => 'fixture', 'autoload' => 'yes'],
    ['option_id' => 3, 'option_name' => 'template', 'option_value' => 'fixture', 'autoload' => 'yes'],
])->setUniqueKey('wp_options', ['option_name'])->setAutoIncrement('wp_options', 4, 'option_id')
    ->seedTable('wp_users', [['ID' => 1, 'user_login' => 'admin']])->setAutoIncrement('wp_posts', 1, 'ID')
    ->setAutoIncrement('wp_postmeta', 1, 'meta_id');

foreach ([
    'wp_wprism_map' => ['uuid' => 'varchar(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned'],
    'wp_wprism_state' => ['uuid' => 'varchar(64)', 'entity_type' => 'varchar(64)', 'content_hash' => 'varchar(64)'],
    'wp_wprism_kv' => ['k' => 'varchar(191)', 'v' => 'longtext'],
    'wp_wprism_journal' => array_fill_keys(['id', 't', 'op', 'tbl', 'item', 'surface', 'actor', 'caps', 'hook', 'proposal'], 'longtext'),
] as $table => $columns) $db->seedTable($table, [])->setColumns($table, $columns)->setTableEngine($table, 'InnoDB');
foreach ([
    'wp_options' => [['option_name']], 'wp_posts' => [['ID']], 'wp_postmeta' => [['meta_id']],
    'wp_wprism_map' => [['uuid', 'id_kind'], ['id_kind', 'local_id']],
    'wp_wprism_state' => [['uuid']], 'wp_wprism_kv' => [['k']],
] as $table => $keys) {
    $indexes = [];
    foreach ($keys as $index => $columns) {
        $db->setUniqueKey($table, $columns);
        foreach ($columns as $position => $column) $indexes[] = [
            'Key_name' => 'unique_' . $index, 'Column_name' => $column, 'Seq_in_index' => $position + 1,
            'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE',
        ];
    }
    if ($table === 'wp_postmeta') $indexes[] = [
        'Key_name' => 'post_id', 'Column_name' => 'post_id', 'Seq_in_index' => 1,
        'Sub_part' => null, 'Non_unique' => 1, 'Index_type' => 'BTREE',
    ];
    $db->setIndexes($table, $indexes);
}
$db->seedTable('authored_inputs', [['id' => 21, 'name' => 'Template', 'data' => 'Public']])
    ->setColumns('authored_inputs', ['id' => 'bigint unsigned', 'name' => 'varchar(255)', 'data' => 'longtext'])
    ->setPrimaryKey('authored_inputs', 'id')->setTableEngine('authored_inputs', 'InnoDB');
$tables = array_merge(array_keys(TableSchema::core_capture_required_columns()),
    ['users', 'usermeta', 'wprism_map', 'wprism_state', 'wprism_kv', 'wprism_journal', 'authored_inputs']);
$observe = static function () use ($db, $tables): array {
    $state = [];
    foreach ($tables as $table) $state[$table] = $db->rows($table);
    return $state;
};
if ($storageCase && $case !== 'storage-missing') {
    $rows = $db->rows('options');
    $rows[] = ['option_id' => 4, 'option_name' => 'fixture_storage_version',
        'option_value' => in_array($case, ['storage-ready', 'storage-locked', 'storage-row-lock'], true) ? '1.0.0' : '0.9.0', 'autoload' => 'no'];
    $db->seedTable('options', $rows);
}
// A warm native cache cannot certify a different physical migration cursor.
if ($storageCase) WpStore::instance()->seedOptions(['fixture_storage_version' => '1.0.0']);
$before = $observe();
$changedUnderLock = false;
$admittedObservation = false;
$db->onQuery(static function (string $sql) use ($case, $db, $observe, &$before, &$changedUnderLock, &$admittedObservation): ?string {
    if ($case === 'locked' && !$changedUnderLock && str_contains($sql, 'GET_LOCK(')) {
        $changedUnderLock = true;
        WpStore::instance()->seedOptions(['active_plugins' => []]);
        $rows = $db->rows('options');
        $rows[0]['option_value'] = serialize([]);
        $db->seedTable('options', $rows);
        $before = $observe();
    }
    if ($case === 'storage-locked' && !$changedUnderLock && str_contains($sql, 'GET_LOCK(')) {
        $changedUnderLock = true;
        $rows = $db->rows('options');
        $rows[3]['option_value'] = '0.9.0';
        $db->seedTable('options', $rows);
        $before = $observe();
    }
    if ($case === 'storage-row-lock' && str_contains($sql, 'FOR UPDATE')
        && str_contains($sql, 'fixture_storage_version')) {
        $admittedObservation = true;
        throw new RuntimeException('intentional locked storage prerequisite boundary');
    }
    if (in_array($case, ['ready', 'forced', 'storage-ready'], true) && preg_match('/\bFROM\s+`?wp_authored_inputs`?\b/i', $sql)) {
        $admittedObservation = true;
        throw new RuntimeException('intentional admitted target-observation boundary');
    }
    return null;
});
$failure = null;
try {
    $options = ['compiled' => $artifact, 'adapter_library' => $library,
        'force_code_mismatch' => in_array($case, ['forced', 'storage-forced'], true)];
    match ($case) {
        'storage-plan' => ApplyRequestCoordinator::plan($repo, $options),
        'storage-explain' => ApplyRequestCoordinator::explain($repo, 'update:options/core', $options),
        'storage-capture' => WPrism\Capture::run($repo, adapterLibrary: $library),
        default => ApplyRequestCoordinator::apply($repo, $options),
    };
} catch (RuntimeException $error) { $failure = $error; }
if ($case === 'storage-row-lock') {
    wprism_check($admittedObservation, 'public Apply locks its storage prerequisite before materialization');
    if (!$admittedObservation) echo $failure?->getMessage(), "\n";
    wprism_check_same($before['options'], $db->rows('options'), 'locked refusal precedes authored option writes');
    wprism_check_same($before['authored_inputs'], $db->rows('authored_inputs'), 'locked refusal precedes authored table writes');
    wprism_check_summary('Apply storage row lock');
}
if (in_array($case, ['ready', 'forced', 'storage-ready'], true)) {
    wprism_check($admittedObservation && $failure?->getMessage() === 'intentional admitted target-observation boundary',
        'admitted control reaches the deliberate ordinary target-observation boundary');
    wprism_check_same($before, $observe(), 'admitted observation control stops before native or ledger mutation');
    wprism_check_summary('Apply lifecycle admission ' . $case);
}
if ($storageCase) {
    wprism_check($failure instanceof WPrism\CommandRefusalException && $failure->reasonCode === 'storage_prerequisite_unmet',
        'public command refuses an unmet durable storage prerequisite');
    if ($case === 'storage-locked') wprism_check($changedUnderLock, 'storage cursor changes between admission and lock');
    wprism_check_same($before, $observe(), 'storage refusal preserves every native and ledger table');
    wprism_check_summary('Apply storage admission ' . $case);
}
wprism_check($failure !== null && str_contains($failure->getMessage(), 'apply refused — code_mismatch:'),
    'public Apply names the lifecycle dependency before authored work');
if ($failure !== null && !str_contains($failure->getMessage(), 'code_mismatch')) echo $failure->getMessage(), "\n";
if ($case === 'locked') wprism_check($changedUnderLock, 'locked control changes lifecycle facts after initial admission');
wprism_check_same([], $db->rows('wprism_map'), 'refused Apply cannot enroll a target natural-key identity');
wprism_check_same($before, $observe(), 'lifecycle refusal preserves every native and ledger table');
wprism_check_summary('Apply lifecycle admission');
