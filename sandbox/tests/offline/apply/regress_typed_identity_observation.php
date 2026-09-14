<?php
declare(strict_types=1);

// Public Apply must reject unready code before target observation enrolls identity.
$root = dirname(__DIR__, 4);
$runtime = $argv[2] ?? $root;
require_once $root . '/sandbox/tests/lib/check.php';
$mode = $argv[1] ?? null;
if ($mode === null) {
    foreach (['historical', 'current', 'changed', 'durable', 'refusal', 'graph', 'graph-historical', 'lock-key', 'lock-id', 'lock-duplicate', 'lock-map'] as $child) {
        passthru(implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, __FILE__, $child, $runtime])), $status);
        wprism_check_same(0, $status, $child . ' public typed identity case passes');
    }
    wprism_check_summary('Typed identity observation');
}
$case = 'ready';
$graph = in_array($mode, ['graph', 'graph-historical'], true);
$scratch = sys_get_temp_dir() . '/wprism-lifecycle-admission-' . bin2hex(random_bytes(8));
define('ABSPATH', $root . '/');
define('WP_CONTENT_DIR', $scratch . '/content');
define('WP_CONTENT_URL', 'https://work.example.test/content');
define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
define('WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins');
require_once $root . '/sandbox/tests/lib/agent_version.php';
wprism_test_define_agent_versions();
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
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
if ($graph) {
    $manifest['tables']['a_children'] = ['class' => 'authored_snapshot', 'pk' => 'id', 'id_kind' => 'auth_child',
        'identity' => ['mode' => 'natural_key', 'columns' => ['parent_id', 'code']], 'slug_column' => 'code',
        'columns' => ['code' => ['class' => 'authored']], 'refs' => [['column' => 'parent_id', 'kind' => 'auth_input']]];
    $manifest['tables']['z_links'] = ['class' => 'authored_snapshot', 'id_kind' => 'auth_link',
        'identity' => ['mode' => 'composite_ref', 'columns' => ['parent_id', 'child_id']],
        'columns' => [], 'refs' => [['column' => 'parent_id', 'kind' => 'auth_input'], ['column' => 'child_id', 'kind' => 'auth_child']]];
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
$uuid = WPrism\Uuid::v5(WPrism\Uuid::NAMESPACE_WPRISM, 'authored_inputs:' . (in_array($mode, ['historical', 'durable', 'refusal', 'graph-historical'], true) ? 'Historical' : 'Template'));
$document = ['columns' => ['name' => 'Template', 'data' => $mode === 'changed' ? 'Changed' : 'Public'],
    'meta' => (object) [], 'table' => 'authored_inputs', 'uuid' => $uuid];
Canon::write_file($repo . '/state/tables/authored_inputs/' . $uuid . '--template.json', Canon::encode($document));
if ($graph) {
    $childUuid = WPrism\Uuid::v5(WPrism\Uuid::NAMESPACE_WPRISM, 'a_children:parent_id=' . $uuid . ':code=slot');
    $linkUuid = WPrism\Uuid::v5(WPrism\Uuid::NAMESPACE_WPRISM, 'z_links:parent_id=' . $uuid . ':child_id=' . $childUuid);
    foreach ([
        ['a_children', $childUuid, 'slot', ['parent_id' => '{{auth_input:' . $uuid . '}}', 'code' => 'slot']],
        ['z_links', $linkUuid, substr($uuid, 0, 8) . '-' . substr($childUuid, 0, 8),
            ['parent_id' => '{{auth_input:' . $uuid . '}}', 'child_id' => '{{auth_child:' . $childUuid . '}}']],
    ] as [$table, $identity, $slug, $columns]) {
        Canon::write_file($repo . '/state/tables/' . $table . '/' . $identity . '--' . $slug . '.json',
            Canon::encode(['uuid' => $identity, 'table' => $table, 'columns' => $columns, 'meta' => (object) []]));
    }
}
$compiled = RepositoryCompiler::compile($repo, $policy);
$artifact = $scratch . '/compiled.json';
$compiled->write($artifact);
mkdir(WP_PLUGIN_DIR . '/lifecycle-fixture', 0700, true);
file_put_contents(WP_PLUGIN_DIR . '/lifecycle-fixture/plugin.php', "<?php\n/*\nPlugin Name: Lifecycle fixture\nVersion: 1.0.0\n*/\n");
if ($case === 'missing') unlink(WP_PLUGIN_DIR . '/lifecycle-fixture/plugin.php');
$liveActive = in_array($case, ['ready', 'locked'], true) ? ['lifecycle-fixture/plugin.php'] : [];
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
if ($graph) {
    $db->seedTable('a_children', [['id' => 31, 'parent_id' => '21', 'code' => 'slot']])
        ->setColumns('a_children', ['id' => 'bigint unsigned', 'parent_id' => 'bigint unsigned', 'code' => 'varchar(255)'])
        ->setPrimaryKey('a_children', 'id')->setTableEngine('a_children', 'InnoDB');
    $db->seedTable('z_links', [['parent_id' => 21, 'child_id' => 31]])
        ->setColumns('z_links', ['parent_id' => 'bigint unsigned', 'child_id' => 'bigint unsigned'])
        ->setUniqueKey('z_links', ['parent_id', 'child_id'])->setTableEngine('z_links', 'InnoDB');
}
$tables = array_merge(array_keys(TableSchema::core_capture_required_columns()),
    ['users', 'usermeta', 'wprism_map', 'wprism_state', 'wprism_kv', 'wprism_journal', 'authored_inputs', ...($graph ? ['a_children', 'z_links'] : [])]);
$observe = static function () use ($db, $tables): array {
    $state = [];
    foreach ($tables as $table) $state[$table] = $db->rows($table);
    return $state;
};

$bootstrap = WPrism\Uuid::v5(WPrism\Uuid::NAMESPACE_WPRISM, 'authored_inputs:Template');
if ($mode === 'durable') {
    WPrism\Ledger::set($bootstrap, 'authored_inputs', 'auth_input', 21);
}
$opts = ['compiled' => $artifact, 'adapter_library' => $library, 'default_author' => 'admin'];
$before = $observe();
$plan = ApplyRequestCoordinator::plan($repo, $opts);
wprism_check_same($before, $observe(), 'public Plan preserves native tables and identity ledger');
$expected = ['uuid' => $uuid, 'type' => 'authored_inputs', 'path' => 'tables/authored_inputs/' . $uuid . '--template.json', 'env_id' => 21];
$historical = in_array($mode, ['historical', 'durable', 'refusal'], true);
wprism_check_same([$expected], array_values(array_filter($plan[$historical || $mode === 'graph-historical' ? 'collision' : 'adopt'], static fn(array $row): bool => $row['type'] === 'authored_inputs')), 'Plan selects exact physical identity work, including equal-content bootstrap');
$adoption = $mode === 'refusal' ? [] : ['adopt_by_slug' => 'tables'];
$injected = false;
if (str_starts_with($mode, 'lock-')) {
    $db->onQuery(static function (string $sql) use ($db, $mode, &$injected): null {
        if (!$injected && str_starts_with($sql, 'SELECT * FROM `wp_authored_inputs` WHERE') && str_ends_with($sql, 'FOR UPDATE')) {
            $injected = true;
            $rows = $db->rows('authored_inputs');
            if ($mode === 'lock-key') $rows[0]['name'] = 'Changed key';
            if ($mode === 'lock-id') $rows[0]['id'] = 22;
            if ($mode === 'lock-duplicate') $rows[] = array_replace($rows[0], ['id' => 22]);
            if ($mode === 'lock-map') {
                $db->seedTable('wprism_map', [['uuid' => WPrism\Uuid::v5(WPrism\Uuid::NAMESPACE_WPRISM, 'different-owner'),
                    'entity_type' => 'authored_inputs', 'id_kind' => 'auth_input', 'local_id' => 21]]);
            }
            $db->seedTable('authored_inputs', $rows);
        }
        return null;
    });
}
$failure = null;
try { ApplyRequestCoordinator::apply($repo, $opts + $adoption); }
catch (Throwable $error) { $failure = $error; }
if (str_starts_with($mode, 'lock-')) {
    wprism_check($injected, 'the public Apply reaches a current locking identity read after planning');
    $reason = match ($mode) {
        'lock-key', 'lock-duplicate' => 'wprism: table adoption requires exactly one current native identity match',
        'lock-id' => 'wprism: table adoption native identity changed after planning',
        'lock-map' => 'wprism: identity contradiction:',
    };
    wprism_check($failure !== null && str_starts_with($failure->getMessage(), $reason), 'stale or contradictory identity cannot gain adoption authority');
    wprism_check_same(null, WPrism\Ledger::id_for($uuid, 'auth_input'), 'failed locked admission does not bind the requested identity');
    wprism_check_same([], $db->rows('wprism_state'), 'failed locked admission publishes no convergence metadata');
} elseif ($mode === 'durable') {
    wprism_check_same('wprism: identity contradiction: local auth_input id 21 is already bound to ' . $bootstrap . '; refusing to replace it with ' . $uuid,
        $failure?->getMessage(), 'explicit adoption cannot replace an existing durable identity');
    wprism_check_same($before['wprism_map'], $db->rows('wprism_map'), 'durable contradiction preserves both ledger directions');
    wprism_check_same($before['authored_inputs'], $db->rows('authored_inputs'), 'durable contradiction preserves the native row');
} elseif ($mode === 'refusal') {
    wprism_check($failure instanceof WPrism\CommandRefusalException && str_starts_with($failure->getMessage(), 'wprism: slug collisions need explicit resolution'),
        'retained UUID collision still needs explicit adoption');
    wprism_check_same($before, $observe(), 'unapproved collision refusal cannot enroll or mutate the target');
} else {
    // This deterministic test has no WP-CLI child process. Stop honestly at
    // its verifier refusal; native qualification must prove final convergence.
    wprism_check($failure !== null && str_starts_with($failure->getMessage(), 'wprism: post-apply convergence verification is unavailable outside wp-cli;'),
        'public Apply commits identity work and reaches the independent verification boundary');
    wprism_check_same([['uuid' => $uuid, 'entity_type' => 'authored_inputs', 'id_kind' => 'auth_input', 'local_id' => 21]],
        array_values(array_filter($db->rows('wprism_map'), static fn(array $row): bool => $row['entity_type'] === 'authored_inputs')), 'authored transaction binds only the canonical UUID to the retained local ID');
    wprism_check_same([['id' => 21, 'name' => 'Template', 'data' => $mode === 'changed' ? 'Changed' : 'Public']],
        $db->rows('authored_inputs'), 'adoption retains the native row and applies its canonical content');
    wprism_check_same([], $db->rows('wprism_state'), 'missing independent verification cannot publish convergence metadata');
}
if ($graph) {
    wprism_check_same(31, WPrism\Ledger::id_for($childUuid, 'auth_child'), 'child identity resolves through its adopted parent');
    wprism_check_same((21 << 31) | 31, WPrism\Ledger::id_for($linkUuid, 'auth_link'), 'composite identity retains its physical tuple');
    wprism_check_same($before['a_children'], $db->rows('a_children'), 'adopting child identity does not duplicate or rewrite native references');
    wprism_check_same($before['z_links'], $db->rows('z_links'), 'composite convergence retains the original native join fact');
    $bound = $db->rows('wprism_map');
    $orphan = WPrism\Uuid::v5(WPrism\Uuid::NAMESPACE_WPRISM, 'orphan-composite');
    WPrism\Ledger::set($orphan, 'z_links', 'auth_link', (21 << 31) | 99);
    $repeat = ApplyRequestCoordinator::plan($repo, $opts);
    wprism_check_same($bound, $db->rows('wprism_map'), 'ordinary composite pruning removes only the orphan tuple and retains the live derived mapping');
    wprism_check_same([], $repeat['adopt'], 'a fresh observation does not retain prior ephemeral enrollment work');

}
wprism_check_summary('Typed identity observation ' . $mode);
