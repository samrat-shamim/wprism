<?php
/** Public Apply must budget semantic records without splitting its transaction. */
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$runtime = $argv[1] ?? $root;
$case = $argv[2] ?? null;
require_once __DIR__ . '/../../lib/check.php';
if ($case === null) {
    foreach (['small', 'options', 'posts', 'adoption', 'mixed', 'callback-bounded', 'callback-excess', 'callback-swallowed', 'callback-reentrant-bounded', 'callback-reentrant', 'callback-forged', 'rollback'] as $child) {
        passthru(implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, __FILE__, $runtime, $child])), $status);
        wprism_check_same(0, $status, "$child public-request process passes");
    }
    wprism_check_summary('authored semantic work units');
}

$scratch = sys_get_temp_dir() . '/wprism-authored-work-' . bin2hex(random_bytes(8));
define('ABSPATH', $root . '/');
define('WP_CONTENT_DIR', $scratch . '/content');
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
$optionCount = match ($case) { 'options' => 600, 'mixed', 'rollback', 'callback-reentrant' => 300, 'callback-reentrant-bounded' => 180, default => 1 };
$postCount = match ($case) { 'posts', 'adoption' => 180, 'mixed', 'rollback' => 80, 'callback-bounded' => 2,
    'callback-excess', 'callback-swallowed', 'callback-reentrant', 'callback-reentrant-bounded', 'callback-forged' => 1, default => 0 };
$rules = [];
for ($index = 0; $index < $optionCount; $index++) $rules['authored_work_' . $index] = ['class' => 'authored', 'autoload' => 'yes'];
Canon::write_file($repo . '/site.wprism.json', Canon::encode([
    'spec_version' => WPRISM_SPEC_VERSION, 'manifests' => ['core'],
    'policy' => ['post_types' => ['post'], 'taxonomies' => [], 'options' => $rules],
]));
$library = AdapterLibrary::fromSourceTree($runtime);
$policy = Policy::load($repo, adapterLibrary: $library);
$records = [];
foreach ($policy->authored_options() as $name => $rule) $records[$name] = OptionState::absent();
foreach (['active_plugins' => [], 'stylesheet' => 'fixture', 'template' => 'fixture'] as $name => $value) {
    $records[$name] = OptionState::present($value, 'yes');
}
foreach ($rules as $name => $rule) $records[$name] = OptionState::present('desired ' . $name, 'yes');
Canon::write_file($repo . '/state/options/core.json', Canon::encode(OptionState::document($records)));
$uuids = [];
$nativePosts = [];
for ($index = 0; $index < $postCount; $index++) {
    $uuid = sprintf('11111111-1111-4111-8111-%012d', $index + 1);
    $uuids[] = $uuid;
    $post = [
        'author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-05 00:00:00',
        'date_gmt' => '2026-09-05 00:00:00', 'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
        'modified_gmt' => '2026-09-05 00:00:00', 'parent' => null, 'ping_status' => 'closed',
        'slug' => 'work-' . $index, 'status' => 'publish', 'terms' => (object) [],
        'title' => 'Work ' . $index, 'type' => 'post', 'uuid' => $uuid,
    ];
    Canon::write_file($repo . '/state/posts/post/' . $uuid . '--work-' . $index . '.md', Canon::post_file($post, 'work payload'));
    $nativePosts[] = array_replace(array_fill_keys(TableSchema::core_capture_required_columns()['posts'], ''), [
        'ID' => 1001 + $index, 'post_author' => 1, 'post_date' => $post['date'], 'post_date_gmt' => $post['date_gmt'],
        'post_modified' => $post['modified_gmt'], 'post_modified_gmt' => $post['modified_gmt'],
        'post_type' => 'post', 'post_name' => $post['slug'], 'post_title' => 'Target default', 'post_status' => 'publish',
        'post_parent' => 0, 'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed',
    ]);
}
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
if ($case === 'adoption') $db->seedTable('wp_posts', $nativePosts);
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

$authored = false;
$authoredStarts = 0;
$authoredQueries = [];
$authoredStart = null;
$terminal = [];
$rebuild = false;
$injected = false;
$db->onQuery(static function (string $sql, string $method) use ($case, $db, &$authored, &$authoredStarts, &$authoredQueries, &$authoredStart, &$terminal, &$rebuild, &$injected): ?string {
    if ($sql === 'START TRANSACTION' && array_filter(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 64),
        static fn(array $frame): bool => ($frame['class'] ?? null) === WPrism\AuthoredTransactionExecutor::class
            && ($frame['function'] ?? null) === 'execute') !== []) {
        $authored = true;
        $authoredStarts++;
        $authoredQueries = [];
        $terminal = [];
        $authoredStart = ['options' => $db->rows('wp_options'), 'posts' => $db->rows('wp_posts'), 'map' => $db->rows('wp_wprism_map')];
    }
    if ($authored) {
        $authoredQueries[] = $sql;
        if (preg_match('/^(COMMIT|ROLLBACK) AND NO CHAIN NO RELEASE$/D', $sql, $control) === 1) {
            $terminal[] = $control[1];
            $authored = false;
        }
        if ($case === 'rollback' && str_starts_with($sql, 'INSERT INTO `wp_options`') && str_contains($sql, "'authored_work_299'")) {
            $injected = true;
            return 'injected authored record failure';
        }
    }
    if (str_contains($sql, 'apply-rebuild')) {
        $rebuild = true;
        return 'injected post-authored rebuild heartbeat';
    }
    return null;
});
$parserCalls = 0;
$parserQueries = 0;
$innerTokens = new Tokens();
$innerTokens->policy = $policy;
$innerMaterializer = new OptionsMaterializer($policy, $innerTokens, new ApplyFieldMaterializer($policy, $innerTokens));
$GLOBALS['authored_work_parser'] = static function () use ($case, $db, $innerMaterializer, $records, &$authored, &$parserCalls, &$parserQueries): void {
    if (!$authored || !str_starts_with($case, 'callback-')) return;
    $parserCalls++;
    if ($case === 'callback-forged') {
        $warnings = [];
        $innerMaterializer->apply_options(OptionState::document($records), false, $warnings, workAuthority: new DatabaseWorkAuthority());
        return;
    }
    if (str_starts_with($case, 'callback-reentrant')) {
        $warnings = [];
        // A native parser reenters the actual option-record owner. All of its
        // records still belong to this one post's enclosing native callback.
        $innerMaterializer->apply_options(OptionState::document($records), false, $warnings);
        return;
    }
    for ($index = 0; $index < ($case === 'callback-bounded' ? 600 : 1025); $index++) {
        try {
            $db->query('SELECT option_name FROM wp_options LIMIT 1');
        } catch (WPrism\DatabaseQueryIsolationViolationException $failure) {
            if ($case === 'callback-swallowed') return;
            throw $failure;
        }
        $parserQueries++;
    }
};
$failure = null;
try {
    ApplyRequestCoordinator::apply($repo, ['compiled' => $artifact, 'adapter_library' => $library]
        + ($case === 'adoption' ? ['adopt_by_slug' => 'posts'] : []));
} catch (Throwable $caught) {
    $failure = $caught;
}
unset($GLOBALS['authored_work_parser']);
$chain = [];
for ($node = $failure; $node !== null; $node = $node->getPrevious()) $chain[] = get_class($node) . ': ' . $node->getMessage();
wprism_check_detail(implode("\n", $chain));
wprism_check($authoredStart !== null, "$case public request reached the real authored transaction");
wprism_check_same(1, $authoredStarts, "$case never splits or retries the authored transaction");
if (in_array($case, ['callback-excess', 'callback-swallowed', 'callback-reentrant', 'callback-forged'], true)) {
    $reason = match ($case) { 'callback-swallowed' => 'poisoned', 'callback-forged' => 'no authority for its bound profile', default => 'statement-count boundary' };
    wprism_check(str_contains(implode("\n", $chain), $reason), "$case remains refused by the actual query gate");
    wprism_check_same([], $terminal, 'poisoned recovery does not claim COMMIT or an unproven rollback');
    wprism_check(!$rebuild && $parserCalls === 1 && $parserQueries < 1025, 'an over-budget callback cannot reach native rebuild or run its full query roster');
} elseif ($case === 'rollback') {
    wprism_check($injected && !$rebuild, 'the late authored write fault is reached before rebuild');
    wprism_check_same(['ROLLBACK'], $terminal, 'one ordinary failure rolls back the single authored transaction');
    wprism_check_same($authoredStart['options'] ?? null, $db->rows('wp_options'), 'rollback restores every earlier option record');
    wprism_check_same($authoredStart['map'] ?? null, $db->rows('wp_wprism_map'), 'rollback restores the identity generation');
    wprism_check_same($authoredStart['posts'] ?? null, $db->rows('wp_posts'), 'rollback restores all earlier post phases');
    wprism_check(count($authoredQueries) > 1024, 'ordinary recovery follows more than 1,024 actual authored queries');
} else {
    wprism_check($rebuild && str_contains(implode("\n", $chain), 'post-authored rebuild heartbeat'), "$case reaches exactly the intentional post-authored boundary");
    wprism_check_same(['COMMIT'], $terminal, "$case commits one authored transaction with no split or rollback");
    $values = array_column($db->rows('wp_options'), 'option_value', 'option_name');
    $expected = [];
    foreach (array_keys($rules) as $name) $expected[$name] = 'desired ' . $name;
    ksort($expected, SORT_STRING);
    ksort($values, SORT_STRING);
    wprism_check_same($expected, array_intersect_key($values, $rules), "$case persisted every authored option value exactly");
    wprism_check_same($postCount, count($db->rows('wp_posts')), "$case persisted the complete post roster");
    wprism_check_same($postCount, count($db->rows('wp_wprism_map')), "$case persisted the complete identity roster");
    if ($case === 'adoption') {
        wprism_check_same(array_column($nativePosts, 'ID'), array_column($db->rows('wp_posts'), 'ID'), 'adoption preserves every target-local post identity');
        wprism_check_same($uuids, array_column($db->rows('wp_wprism_map'), 'uuid'), 'adoption installs the exact compiled UUID roster');
    }
    wprism_check_same(array_fill(0, $postCount, 'work payload'), array_column($db->rows('wp_posts'), 'post_content'), "$case finalized exact post bodies");
    if ($case !== 'small') wprism_check(count($authoredQueries) > 1024, "$case exceeds 1,024 guarded queries inside that one transaction");
    if ($case === 'callback-bounded') wprism_check($parserCalls === 2 && $parserQueries === 1200, 'two complete posts each carry their own 600-query native parser');
    if ($case === 'callback-reentrant-bounded') wprism_check_same(1, $parserCalls, 'one bounded native callback can still reenter the actual option owner');
    $kv = array_column($db->rows('wp_wprism_kv'), 'v', 'k');
    wprism_check(isset($kv['apply_in_progress']) && !isset($kv['applied_revision']), 'post-authored failure retains durable recovery intent without claiming convergence');
}
wprism_check_summary("authored work $case");
