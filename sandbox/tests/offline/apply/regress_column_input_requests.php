<?php
declare(strict_types=1);

// Actual request composition must select, preflight and roll back environment pointer work.
$root = dirname(__DIR__, 4);
$runtime = $root;
$case = $argv[1] ?? null;
require_once __DIR__ . '/../../lib/check.php';
if ($case === null) {
    foreach (['rebind', 'busy', 'tamper', 'missing', 'drift'] as $child) {
        passthru(implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, __FILE__, $child])), $status);
        wprism_check_same(0, $status, "$child public input request passes");
    }
    wprism_check_summary('column input requests');
}
$scratch = sys_get_temp_dir() . '/wprism-input-request-' . bin2hex(random_bytes(8));
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
use WPrism\ApplyFieldMaterializer;
use WPrism\DatabaseWorkAuthority;
use WPrism\OptionsMaterializer;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\RepositoryCompiler;
use WPrism\TableSchema;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

final class WP_Hook {
    public array $callbacks = [];
}
function get_taxonomies(array $args = [], string $output = 'names'): array { return []; }
function get_taxonomy(string $name): object { return (object) ['name' => $name, 'object_type' => [], 'hierarchical' => false]; }
function validate_plugin(string $plugin): bool { return true; }
function get_plugins(): array { return []; }
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
foreach (['state/posts/post', 'state/options'] as $directory) mkdir($repo . '/' . $directory, 0700, true);
$repo = (string) realpath($repo);
mkdir(WP_CONTENT_DIR . '/themes/fixture', 0700, true);
file_put_contents(WP_CONTENT_DIR . '/themes/fixture/style.css', "/*\nTheme Name: Authored work fixture\nVersion: 1.0.0\n*/\n");

$libraryRoot = $scratch . '/library';
mkdir($libraryRoot . '/adapter-packages', 0700, true);
foreach (['profiles.json', 'capabilities/platform.json', 'capabilities/adapter-authorities.json', 'core/manifest.json', 'core/disposition.json'] as $relative) {
    $destination = $libraryRoot . '/platform/adapter-library/' . $relative;
    if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0700, true);
    copy($root . '/platform/adapter-library/' . $relative, $destination);
}
$manifestPath = $libraryRoot . '/platform/adapter-library/core/manifest.json';
$manifest = Canon::decode(Canon::read_file($manifestPath));
$manifest['spec_version'] = 3;
$manifest['engine_features'] = array_values(array_unique([...($manifest['engine_features'] ?? []),
    'column-input-files/v1', 'spec-window/v1', 'typed-column-codecs/v1', 'json-column-codecs/v1', 'typed-column-values/v1']));
sort($manifest['engine_features'], SORT_STRING);
$manifest['tables']['authored_inputs'] = ['class' => 'authored_snapshot', 'pk' => 'id', 'id_kind' => 'auth_input',
    'identity' => ['mode' => 'mapped'], 'slug_column' => 'name',
    'columns' => ['name' => ['class' => 'authored'], 'data' => ['class' => 'authored']], 'refs' => []];
$manifest['column_codecs']['authored_inputs']['data'] = ['container' => 'json', 'value' => ['class' => 'authored', 'object_fields' => [
    'input' => ['class' => 'authored', 'input_file' => ['directory' => 'imports', 'extensions' => ['csv']]],
    'label' => ['class' => 'authored', 'plain_data' => true]]]];
Canon::write_file($manifestPath, Canon::encode($manifest));
$rules = ['authored_work_0' => ['class' => 'authored', 'autoload' => 'yes']];
Canon::write_file($repo . '/site.wprism.json', Canon::encode([
    'spec_version' => WPRISM_SPEC_VERSION, 'manifests' => ['core'],
    'policy' => ['post_types' => ['post'], 'taxonomies' => [], 'options' => $rules],
]));
$library = AdapterLibrary::fromSourceTree($libraryRoot);
$policy = Policy::load($repo, adapterLibrary: $library);
$records = [];
foreach ($policy->authored_options() as $name => $rule) $records[$name] = OptionState::absent();
foreach (['active_plugins' => [], 'stylesheet' => 'fixture', 'template' => 'fixture'] as $name => $value) {
    $records[$name] = OptionState::present($value, 'yes');
}
$records['authored_work_0'] = OptionState::present('Desired option', 'yes');
Canon::write_file($repo . '/state/options/core.json', Canon::encode(OptionState::document($records)));
$uuid = '11111111-1111-4111-8111-111111111111';
$binding = WPrism\InputFileBinding::name($uuid, 'data', ['input']);
$document = ['columns' => ['name' => 'Template', 'data' => json_encode(['input' => WPrism\InputFileBinding::MARKER, 'label' => 'Public'], JSON_THROW_ON_ERROR)],
    'meta' => (object) [], 'table' => 'authored_inputs', 'uuid' => $uuid];
Canon::write_file($repo . '/state/tables/authored_inputs/' . $uuid . '--template.json', Canon::encode($document));
$compiled = RepositoryCompiler::compile($repo, $policy);
$artifact = $scratch . '/compiled.json';
$compiled->write($artifact);
WpStore::reset()->seedOptions([
    'home' => 'https://work.example.test', 'siteurl' => 'https://work.example.test',
    'admin_email' => 'admin@work.example.test', 'active_plugins' => [], 'stylesheet' => 'fixture', 'template' => 'fixture',
])->ensureUploadDir();
$db = FakeWpdb::install()->enableInformationSchema()->enableFullApplySqlExtensions()->enableJoinedCaptureSql();
foreach (TableSchema::core_capture_required_columns() as $property => $columns) {
    $db->seedTable($db->$property, [])->setColumns($db->$property, array_fill_keys($columns, 'longtext'))->setTableEngine($db->$property, 'InnoDB');
}
$db->seedTable('wp_options', [
    ['option_id' => 1, 'option_name' => 'active_plugins', 'option_value' => 'a:0:{}', 'autoload' => 'yes'],
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


$native = json_encode(['input' => 'https://source.test/content/imports/original.csv', 'label' => $case === 'drift' ? 'Target edit' : 'Public'], JSON_THROW_ON_ERROR);
$db->seedTable('authored_inputs', [['id' => 21, 'name' => 'Template', 'data' => $native]])
    ->setColumns('authored_inputs', ['id' => 'bigint unsigned', 'name' => 'varchar(255)', 'data' => 'longtext'])
    ->setPrimaryKey('authored_inputs', 'id')->setTableEngine('authored_inputs', 'InnoDB');
$db->seedTable('wprism_map', [['uuid' => $uuid, 'entity_type' => 'authored_inputs', 'id_kind' => 'auth_input', 'local_id' => 21]]);
$db->seedTable('wprism_state', [['uuid' => $uuid, 'entity_type' => 'authored_inputs', 'content_hash' => $compiled->tree()[$uuid]['hash']]]);
mkdir(WP_CONTENT_DIR . '/imports', 0700, true);
file_put_contents(WP_CONTENT_DIR . '/imports/replacement.csv', "user_login\nsynthetic\n");
file_put_contents(WP_CONTENT_DIR . '/imports/rotated.csv', "user_login\nother\n");
$options = ['compiled' => $artifact, 'adapter_library' => $library];
$receipt = ApplyRequestCoordinator::set_env_option($repo, $binding, 'replacement.csv', $options);
wprism_check_same(['name' => $binding, 'previously_set' => false], $receipt, 'public env-set binds the compiled file coordinate');
wprism_check_same($native, $db->rows('authored_inputs')[0]['data'], 'public provisioning preserves native authored state');
if ($case === 'missing') {
    unlink(WP_CONTENT_DIR . '/imports/replacement.csv');
    $db->seedTable('authored_inputs', []);
    $db->seedTable('wprism_map', []);
    $db->seedTable('wprism_state', []);
}
$plan = ApplyRequestCoordinator::plan($repo, $options);
if ($case === 'drift') {
    wprism_check(in_array($uuid, array_column($plan['drift'], 'uuid'), true), 'public Plan retains authored drift');
    wprism_check(!in_array($uuid, array_column($plan['update'], 'uuid'), true), 'file intent cannot turn authored drift into permission to overwrite');
    wprism_check_summary('input request drift');
}
if ($case === 'missing') {
    wprism_check(in_array($binding, array_column($plan['env_missing'], 'name'), true), 'public Plan names unavailable input');
    wprism_check(in_array($uuid, array_column($plan['create'], 'uuid'), true), 'missing-input evidence selects actual row creation');
} else {
    wprism_check(in_array($uuid, array_column($plan['update'], 'uuid'), true), 'public Plan selects canonically equal input row for rebind');
}
$authored = false;
$starts = 0;
$terminal = [];
$rebuild = false;
$tampered = false;
$inputWrite = false;
$busy = false;
$before = $db->rows('authored_inputs');
$db->onQuery(static function (string $sql, string $method) use ($case, $repo, $binding, &$authored, &$starts, &$terminal, &$rebuild, &$tampered, &$inputWrite, &$busy): ?string {
    if ($sql === 'START TRANSACTION' && array_filter(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 64),
        static fn(array $frame): bool => ($frame['class'] ?? null) === WPrism\AuthoredTransactionExecutor::class
            && ($frame['function'] ?? null) === 'execute') !== []) {
        $authored = true;
        $starts++;
    }
    if ($authored && preg_match('/^UPDATE `?wp_authored_inputs`? SET/', $sql)) {
        $inputWrite = true;
        if ($case === 'busy') {
            try { WPrism\EnvironmentValues::set($repo, $binding, 'rotated.csv'); }
            catch (RuntimeException $failure) { $busy = str_contains($failure->getMessage(), 'environment values are in use'); }
        }
        if ($case === 'tamper' && !$tampered) {
            $tampered = true;
            file_put_contents($repo . '/' . WPrism\EnvironmentValues::FILE, Canon::encode([$binding => 'rotated.csv']));
        }
    }
    if ($authored && preg_match('/^(COMMIT|ROLLBACK) AND NO CHAIN NO RELEASE$/D', $sql, $match)) {
        $terminal[] = $match[1];
        $authored = false;
    }
    if (str_contains($sql, 'apply-rebuild')) {
        $rebuild = true;
        return 'intentional post-authored heartbeat';
    }
    return null;
});
$failure = null;
try { ApplyRequestCoordinator::apply($repo, $options); }
catch (Throwable $caught) { $failure = $caught; }
$chain = [];
for ($node = $failure; $node !== null; $node = $node->getPrevious()) $chain[] = get_class($node) . ': ' . $node->getMessage();
wprism_check_detail(implode("\n", $chain));
if ($case === 'rebind' || $case === 'busy') {
    if ($case === 'busy') wprism_check($busy, 'concurrent public store mutation refuses while authored Apply holds intent');
    wprism_check($rebuild && str_contains(implode("\n", $chain), 'intentional post-authored heartbeat'), 'public Apply reaches the named post-authored boundary');
    wprism_check_same(1, $starts, 'file rebind uses the one authored transaction');
    wprism_check_same(['COMMIT'], $terminal, 'file rebind commits in the ordinary authored transaction');
    wprism_check($inputWrite, 'real Apply dispatch reaches the typed column writer');
    wprism_check_same(['input' => WP_CONTENT_URL . '/imports/replacement.csv', 'label' => 'Public'], json_decode($db->rows('authored_inputs')[0]['data'], true),
        'public Apply resolves the local input and preserves literal siblings');
} elseif ($case === 'tamper') {
    wprism_check($tampered && $inputWrite, 'intent changes only after the real pointer write starts');
    wprism_check(str_contains(implode("\n", $chain), 'input intent changed during authored apply'), 'precommit proof detects out-of-band private intent');
    wprism_check_same(['ROLLBACK'], $terminal, 'changed intent rolls back the whole authored transaction');
    wprism_check_same($before, $db->rows('authored_inputs'), 'private intent race preserves the complete old native row');
    wprism_check(!$rebuild, 'failed input precommit cannot reach rebuild');
} else {
    wprism_check(str_contains(implode("\n", $chain), 'available readable regular file'), 'public Apply refuses unavailable input explicitly');
    wprism_check_same(0, $starts, 'missing input refuses before opening the authored transaction');
    wprism_check_same($before, $db->rows('authored_inputs'), 'missing input mutates no typed row');
    wprism_check(!$inputWrite && !$rebuild, 'missing input reaches neither typed writes nor rebuild');
}
WPrism\EnvironmentValues::set($repo, 'after_request', 'released');
wprism_check_same('released', WPrism\EnvironmentValues::read($repo)['after_request'], 'every request outcome releases its environment lock');
wprism_check_summary("column input request $case");
