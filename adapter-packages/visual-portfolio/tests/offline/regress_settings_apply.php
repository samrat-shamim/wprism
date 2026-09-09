<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once "$root/sandbox/tests/lib/check.php";
if ($argc === 1) {
    foreach (['move', 'clear', 'already-mapped', 'duplicates', 'disabled', 'marker-noop', 'roles-noop',
        'rewrite-noop', 'pending-rewrite', 'unrelated-meta', 'unrelated-option', 'marker-alias', 'option-filter'] as $case) {
        $process = proc_open([PHP_BINARY, __FILE__, $case], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('settings test child could not start');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        wprism_check($exit === 0 && $stderr === '' && str_contains($stdout, 'PASS:'), 'public settings Apply: ' . $case);
        if ($exit !== 0 || $stderr !== '') fwrite(STDERR, $stdout . $stderr);
    }
    wprism_check_summary('regress_visual_portfolio_settings_apply');
}
$case = $argv[1];
$refusals = [
    'marker-noop' => 'archive marker did not converge',
    'roles-noop' => 'native roles did not converge',
    'rewrite-noop' => 'durable rewrite rules disagree',
    'pending-rewrite' => 'native hard rewrite remains deferred',
    'unrelated-meta' => 'changed an input or an unrelated row',
    'unrelated-option' => 'changed an input or an unrelated row',
    'marker-alias' => 'changed an input or an unrelated row',
    'option-filter' => 'native option inputs found a pre-existing',
];
$scratch = sys_get_temp_dir() . '/wprism-vp-apply-' . bin2hex(random_bytes(6));
define('WP_CONTENT_DIR', $scratch . '/content');
define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
define('WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins');
foreach (['check.php', 'agent_version.php'] as $file) require_once "$root/sandbox/tests/lib/$file";
require_once "$root/sandbox/tests/support/wp_cli_child_process_fake.php";
require_once dirname(__DIR__, 2) . '/fixtures/settings-runtime.php';
require_once "$root/sandbox/tests/lib/native_option_stubs.php";
require_once ABSPATH . WPINC . '/class-wp-post.php';
require_once ABSPATH . WPINC . '/post.php';
require_once "$root/sandbox/tests/lib/FakeWpdb.php";
wprism_test_define_agent_versions();
require_once "$root/agent/src/Apply/Apply.php";
require_once "$root/agent/src/Repository/RepositoryAuthorization.php";
require_once "$root/sandbox/tests/support/wp-block-parser-stub.php";
require_once "$root/sandbox/tests/support/wp-shortcode-stub.php";
require_once "$root/sandbox/tests/support/wp_cli_child_process_fake.php";

use WPrism\AdapterLibrary;
use WPrism\Apply;
use WPrism\Canon;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\RepositoryCompiler;
use WPrism\ScopeContract;
use WPrism\ScopedStateOverlay;
use WPrism\TableSchema;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

function get_taxonomies(array $args = [], string $output = 'names'): array { return []; }
function get_taxonomy(string $name): object|false {
    $portfolio = in_array($name, ['portfolio_category', 'portfolio_tag'], true);
    if ($portfolio && !($GLOBALS['vp_fixture_taxonomies_registered'] ?? true)) return false;
    return (object) ['name' => $name, 'object_type' => $portfolio ? ['portfolio'] : [],
        'hierarchical' => $name === 'portfolio_category'];
}
function validate_plugin(string $plugin): bool { return true; }
function get_plugins(): array { return ['visual-portfolio/class-visual-portfolio.php' => ['Name' => 'Visual Portfolio', 'Version' => '3.8.1']]; }
$GLOBALS['wp_filter'] = [];
$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
wprism_wp_store()->version = '7.1';
FakeWpdb::install()->setServerVersion('8.4.0')->enableInformationSchema()->seedTable('wp_options', []);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$repo = $scratch . '/repo';
foreach ([$repo . '/state/posts/page', $repo . '/state/options', WP_CONTENT_DIR . '/themes/fixture', WP_PLUGIN_DIR . '/visual-portfolio'] as $dir) mkdir($dir, 0700, true);
$repo = (string) realpath($repo);
file_put_contents(WP_CONTENT_DIR . '/themes/fixture/style.css', "/*\nTheme Name: Fixture\nVersion: 1.0.0\n*/\n");
file_put_contents(WP_PLUGIN_DIR . '/visual-portfolio/class-visual-portfolio.php', "<?php\n/* Plugin Name: Visual Portfolio\nVersion: 3.8.1\n*/\n");
$plugin = 'visual-portfolio/class-visual-portfolio.php';
Canon::write_file($repo . '/site.wprism.json', Canon::encode(['spec_version' => WPRISM_SPEC_VERSION,
    'manifests' => ['core', 'visual-portfolio'], 'policy' => ['post_types' => ['page', 'portfolio'],
        'taxonomies' => ['portfolio_category', 'portfolio_tag'],
        'post_meta' => ['_VP_POST_TYPE_MAPPED' => ['class' => 'runtime']]]]));
$library = AdapterLibrary::fromSourceTree($root);
$policy = Policy::load($repo, adapterLibrary: $library);
$records = [];
foreach ($policy->authored_options() as $name => $rule) $records[$name] = OptionState::absent();
foreach ($policy->sub_keyed_options() as $name => $rule) $records[$name] = OptionState::absent();
foreach (['active_plugins' => [$plugin], 'stylesheet' => 'fixture', 'template' => 'fixture'] as $name => $value) $records[$name] = OptionState::present($value, 'yes');
$uuid = static fn(int $id): string => sprintf('11111111-1111-4111-8111-%012d', $id);
$desired = ['register_portfolio_post_type' => 'on', 'portfolio_archive_page' => '{{post:' . $uuid(10) . '}}',
    'archive_page_items_per_page' => '3', 'no_image' => '{{post:' . $uuid(3) . '}}'];
if ($case === 'clear') unset($desired['portfolio_archive_page']);
if ($case === 'disabled') $desired['register_portfolio_post_type'] = 'off';
$records['vp_general'] = OptionState::present($desired, 'auto');
Canon::write_file($repo . '/state/options/core.json', Canon::encode(OptionState::document($records)));
$schema = TableSchema::core_capture_required_columns();
$schema['posts'] = ['ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title',
    'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name',
    'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent',
    'guid', 'menu_order', 'post_type', 'post_mime_type', 'comment_count'];
$schema['users'] = ['ID', 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url',
    'user_registered', 'user_activation_key', 'user_status', 'display_name'];
$schema['terms'][] = 'term_group';
$schema['term_taxonomy'][] = 'count';
$posts = [];
foreach ([1, 3, 10] as $id) {
    $front = ['uuid' => $uuid($id), 'type' => 'page', 'slug' => 'fixture-' . $id, 'title' => 'Fixture ' . $id,
        'author' => 'user:admin', 'parent' => null, 'menu_order' => 0, 'status' => 'publish', 'comment_status' => 'closed',
        'ping_status' => 'closed', 'date' => '2026-09-09 00:00:00', 'date_gmt' => '2026-09-09 00:00:00',
        'modified' => '2026-09-09 00:00:00', 'modified_gmt' => '2026-09-09 00:00:00', 'excerpt' => '', 'meta' => (object) [], 'terms' => (object) [], 'term_orders' => (object) []];
    Canon::write_file($repo . '/state/posts/page/' . $uuid($id) . '--fixture-' . $id . '.md', Canon::post_file($front, 'Fixture'));
    $posts[] = array_replace(array_fill_keys($schema['posts'], ''), [
        'ID' => 800 + $id, 'post_author' => 1, 'post_type' => 'page', 'post_name' => $front['slug'], 'post_title' => $front['title'],
        'post_status' => 'publish', 'post_content' => 'Fixture', 'post_parent' => 0, 'menu_order' => 0,
        'comment_status' => 'closed', 'ping_status' => 'closed', 'post_date' => $front['date'], 'post_date_gmt' => $front['date_gmt'],
        'post_modified' => $front['modified'], 'post_modified_gmt' => $front['modified_gmt']]);
}
$compiled = RepositoryCompiler::compile($repo, $policy);
$artifact = $scratch . '/compiled.json';
$compiled->write($artifact);
$contract = ScopeContract::resolve($compiled, $policy, ['option:vp_general']);
wprism_check(!in_array($uuid(1), ScopedStateOverlay::selected_identities($contract), true), 'old target archive is outside the immutable authored scope');
WpStore::reset()->seedOptions(['home' => 'https://target.example.test', 'siteurl' => 'https://target.example.test',
    'admin_email' => 'admin@target.example.test', 'active_plugins' => [$plugin], 'stylesheet' => 'fixture', 'template' => 'fixture'])->ensureUploadDir();
$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
wprism_wp_store()->version = '7.1';
$GLOBALS['wp_rewrite'] = new WP_Rewrite();
$db = FakeWpdb::install()->setServerVersion('8.4.0')->enableInformationSchema()->enableFullApplySqlExtensions()->enableJoinedCaptureSql();
foreach ($schema as $property => $columns) {
    $db->seedTable($db->$property, [])->setColumns($db->$property, array_fill_keys($columns, 'longtext'))->setTableEngine($db->$property, 'InnoDB');
}
$target = array_replace($desired, ['register_portfolio_post_type' => 'on', 'portfolio_archive_page' => '801', 'no_image' => '803']);
$db->seedTable('wp_options', [
    ['option_id' => 1, 'option_name' => 'active_plugins', 'option_value' => serialize([$plugin]), 'autoload' => 'yes'],
    ['option_id' => 2, 'option_name' => 'stylesheet', 'option_value' => 'fixture', 'autoload' => 'yes'],
    ['option_id' => 3, 'option_name' => 'template', 'option_value' => 'fixture', 'autoload' => 'yes'],
    ['option_id' => 4, 'option_name' => 'vp_general', 'option_value' => serialize($target), 'autoload' => 'auto'],
])->setAutoIncrement('wp_options', 5, 'option_id')->seedTable('wp_posts', $posts)->setAutoIncrement('wp_posts', 900, 'ID')
    ->seedTable('wp_users', [array_replace(array_fill_keys($schema['users'], ''), ['ID' => 1, 'user_login' => 'admin'])])->seedTable('wp_postmeta', [
        ['meta_id' => 1, 'post_id' => 801, 'meta_key' => '_vp_post_type_mapped', 'meta_value' => 'portfolio'],
        ['meta_id' => 2, 'post_id' => 801, 'meta_key' => '_vp_views_count', 'meta_value' => '42'],
        ['meta_id' => 3, 'post_id' => 801, 'meta_key' => '_wprism_uuid', 'meta_value' => $uuid(1)],
        ['meta_id' => 4, 'post_id' => 803, 'meta_key' => '_wprism_uuid', 'meta_value' => $uuid(3)],
        ['meta_id' => 5, 'post_id' => 810, 'meta_key' => '_wprism_uuid', 'meta_value' => $uuid(10)],
    ])->setAutoIncrement('wp_postmeta', 6, 'meta_id');
foreach ([
    'wp_wprism_map' => ['uuid' => 'varchar(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned'],
    'wp_wprism_state' => ['uuid' => 'varchar(64)', 'entity_type' => 'varchar(64)', 'content_hash' => 'varchar(64)'],
    'wp_wprism_kv' => ['k' => 'varchar(191)', 'v' => 'longtext'],
    'wp_wprism_journal' => array_fill_keys(['id', 't', 'op', 'tbl', 'item', 'surface', 'actor', 'caps', 'hook', 'proposal'], 'longtext'),
] as $table => $columns) $db->seedTable($table, [])->setColumns($table, $columns)->setTableEngine($table, 'InnoDB');
$db->seedTable('wp_wprism_map', array_map(static fn(int $id): array => ['uuid' => $uuid($id), 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => 800 + $id], [1, 3, 10]));
foreach (['wp_options' => [['option_id'], ['option_name']], 'wp_posts' => [['ID']], 'wp_postmeta' => [['meta_id']],
    'wp_wprism_map' => [['uuid', 'id_kind'], ['id_kind', 'local_id']], 'wp_wprism_state' => [['uuid']], 'wp_wprism_kv' => [['k']]] as $table => $keys) {
    $indexes = [];
    foreach ($keys as $index => $columns) {
        $db->setUniqueKey($table, $columns);
        foreach ($columns as $position => $column) $indexes[] = ['Key_name' => 'unique_' . $index, 'Column_name' => $column,
            'Seq_in_index' => $position + 1, 'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE'];
    }
    if ($table === 'wp_postmeta') $indexes[] = ['Key_name' => 'post_id', 'Column_name' => 'post_id', 'Seq_in_index' => 1, 'Sub_part' => null, 'Non_unique' => 1, 'Index_type' => 'BTREE'];
    $db->setIndexes($table, $indexes);
}
$roles = [
    'administrator' => ['name' => 'Administrator', 'capabilities' => ['manage_options' => true]],
    'editor' => ['name' => 'Editor', 'capabilities' => ['edit_posts' => true]],
    'author' => ['name' => 'Author', 'capabilities' => ['publish_posts' => true, 'other_plugin_capability' => true]],
    'unrelated' => ['name' => 'Other Plugin Role', 'capabilities' => ['other_plugin_capability' => true]],
];
$GLOBALS['vp_fixture_roles'] = (object) ['roles' => $roles, 'role_key' => 'wp_user_roles', 'use_db' => true];
foreach (['home' => 'https://target.example.test', 'siteurl' => 'https://target.example.test',
    'admin_email' => 'admin@target.example.test', 'wp_user_roles' => $roles,
    '_vp_add_archive_page' => '1', 'visual_portfolio_items_count_notice_state' => 'preserve'] as $name => $value) {
    update_option($name, $value);
    if (in_array($name, ['home', 'siteurl', 'admin_email'], true)) WPrism\EnvironmentValues::set($repo, $name, $value);
}
$compiled = RepositoryCompiler::read_artifact($artifact, $policy);
$negotiated = WPrism\Providers::negotiate($policy, $policy->actions_for(['option:vp_general']));
wprism_check_same([], $negotiated['problems'], 'settings provider negotiates through the actual package loader');
$GLOBALS['vp_fixture_provider'] = $negotiated['providers']['visual-portfolio-settings'];
$GLOBALS['vp_apply_context'] = [$repo, $policy, $compiled, $contract];
$GLOBALS['vp_settings_fault'] = $case;
if ($case === 'already-mapped') $db->update('wp_postmeta', ['post_id' => 810], ['meta_id' => 1]);
if ($case === 'duplicates') $db->insert('wp_postmeta', ['post_id' => 810, 'meta_key' => '_vp_post_type_mapped', 'meta_value' => 'portfolio']);
if ($case === 'marker-alias') $db->insert('wp_postmeta', ['post_id' => 803, 'meta_key' => '_VP_POST_TYPE_MAPPED', 'meta_value' => 'other']);
if ($case === 'option-filter') add_filter('option_vp_general', static fn($value) => $value);
$beforeDerivedMeta = $db->rows('wp_postmeta');
$beforeOptions = array_values(array_filter($db->rows('wp_options'), static fn(array $row): bool => $row['option_name'] !== 'vp_general'));
$beforeMeta = array_values(array_filter($db->rows('wp_postmeta'), static fn(array $row): bool => $row['meta_key'] !== '_vp_post_type_mapped'));
$observe = static function () use ($db, $uuid): array {
    $tables = [];
    foreach (['posts', 'postmeta', 'options', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'users', 'usermeta'] as $property) {
        $tables[$property] = $db->rows($db->$property);
    }
    $engine = [];
    foreach (['wprism_map', 'wprism_state', 'wprism_kv', 'wprism_journal'] as $suffix) $engine[$suffix] = $db->rows($db->prefix . $suffix);
    return ['tables' => $tables, 'engine' => $engine, 'archive' => (int) (get_option('vp_general')['portfolio_archive_page'] ?? 0),
        'enabled' => get_option('vp_general')['register_portfolio_post_type'] === 'on',
        'ids' => ['old' => 801, 'new' => 810], 'uuids' => ['old' => $uuid(1), 'new' => $uuid(10)]];
};
$nativeBefore = $observe();
$plan = null;
if (in_array($case, ['move', 'clear'], true)) {
    $plan = Apply::plan($repo, ['compiled' => $artifact, 'adapter_library' => $library, 'scope_request' => $contract]);
}
try {
    $result = Apply::apply($repo, ['compiled' => $artifact, 'adapter_library' => $library, 'scope_request' => $contract]);
    wprism_check(!isset($refusals[$case]), 'hostile settings case cannot publish success');
    wprism_check_same('complete', $result['scoped_receipt']['phase'], 'public scoped Apply reaches verified completion');
    $notices = ['provider capability fired: visual-portfolio-settings@1.0.0 reconcile_settings (scoped, verified)'];
    if ($case === 'clear') array_unshift($notices,
        'option vp_general.portfolio_archive_page: removed from the live blob — capture no longer reports this declared authored sub-key (its own source value is gone on the captured environment, e.g. a referenced attachment was deleted)');
    wprism_check_same($notices, $result['warnings'], 'public scoped Apply reports only its exact authored-removal and verified-action notices');
    $markers = array_values(array_filter($db->rows('wp_postmeta'), static fn(array $row): bool => $row['meta_key'] === '_vp_post_type_mapped'));
    wprism_check_same($case === 'clear' ? 0 : 1, count($markers), 'native archive marker count agrees with the desired setting');
    if ($case !== 'clear') wprism_check_same(810, (int) ($markers[0]['post_id'] ?? 0), 'native archive marker moves to the materialized target-local page');
    if ($case === 'already-mapped') wprism_check_same(1, (int) $markers[0]['meta_id'], 'already-correct native marker retains physical identity');
    if ($case === 'disabled') wprism_check(!isset(get_option('wp_user_roles')['portfolio_author']), 'disabled native portfolio removes its author role');
    wprism_check_same($beforeMeta, array_values(array_filter($db->rows('wp_postmeta'), static fn(array $row): bool => $row['meta_key'] !== '_vp_post_type_mapped')),
        'all unrelated metadata and ledger identity bytes survive');
    wprism_check_same($roles['unrelated'], get_option('wp_user_roles')['unrelated'], 'unrelated plugin role remains exact');
    wprism_check_same('pass', $result['verification']['result'], 'actual canonical verifier independently checks the committed authored state');
    if ($plan !== null) {
        require_once dirname(__DIR__, 2) . '/fixtures/settings-evidence.php';
        $nativeAfter = $observe();
        $repeat = Apply::apply($repo, ['compiled' => $artifact, 'adapter_library' => $library, 'scope_request' => $contract]);
        $nativeStable = $observe();
        $nativeSource = array_replace($nativeAfter, ['ids' => ['old' => 1, 'new' => 10]]);
        $admission = [$case, $nativeBefore, $nativeSource, $contract, $plan, $result, $nativeAfter, $repeat, $nativeStable];
        VisualPortfolioSettingsEvidence::phase(...$admission);
        wprism_check(true, 'native evidence admission consumes actual public plan, Apply and replay outputs offline');
        foreach (['unrelated-option', 'unrelated-meta', 'wrong-source', 'missing-action', 'mutating-replay', 'changed-page', 'notice', 'receipt-hash'] as $fault) {
            $altered = $admission;
            match ($fault) {
                'unrelated-option' => $altered[6]['tables']['options'][0]['option_value'] = 'changed',
                'unrelated-meta' => $altered[6]['tables']['postmeta'][1]['meta_value'] = 'changed',
                'wrong-source' => $altered[5]['verification']['source_artifact_hash'] = str_repeat('0', 64),
                'missing-action' => $altered[4]['selected_actions'] = [],
                'mutating-replay' => $altered[7]['applied'] = 1,
                'changed-page' => $altered[6]['tables']['posts'][0]['post_title'] = 'changed',
                'notice' => $altered[5]['warnings'][] = 'unexpected warning',
                'receipt-hash' => $altered[5]['scoped_receipt']['authority_hash'] = str_repeat('0', 64),
            };
            if ($fault === 'receipt-hash') $altered[7]['scoped_receipt'] = $altered[5]['scoped_receipt'];
            // Avoid a generic replay-equality failure masking the preservation check.
            $altered[8] = $altered[6];
            $refused = false;
            try { VisualPortfolioSettingsEvidence::phase(...$altered); }
            catch (RuntimeException $error) { $refused = str_starts_with($error->getMessage(), 'Visual Portfolio settings admission:'); }
            wprism_check($refused, 'native admission rejects ' . $fault);
        }
        if (in_array($case, ['move', 'clear'], true)) {
            $originalBytes = file_get_contents($repo . '/state/options/core.json');
            $changed = $desired;
            $changed['register_portfolio_post_type'] = 'off';
            $alternate = $records;
            $alternate['vp_general'] = OptionState::present($changed, 'auto');
            Canon::write_file($repo . '/state/options/core.json', Canon::encode(OptionState::document($alternate)));
            $disabled = RepositoryCompiler::compile($repo, $policy);
            $disabledPath = $scratch . '/disabled.json';
            $disabled->write($disabledPath);
            $disabledContract = ScopeContract::resolve($disabled, $policy, ['option:vp_general']);
            $GLOBALS['vp_apply_context'] = [$repo, $policy, $disabled, $disabledContract];
            $disabledResult = Apply::apply($repo, ['compiled' => $disabledPath, 'adapter_library' => $library, 'scope_request' => $disabledContract]);
            wprism_check_same('complete', $disabledResult['scoped_receipt']['phase'], 'a distinct scoped authority can disable the native portfolio');
            // Native 3.8.1 class-custom-post-type.php registers neither
            // registrations when disabled. The next Apply boot therefore
            // needs the manifest hierarchy while re-enabling the option.
            $GLOBALS['vp_fixture_taxonomies_registered'] = false;
            wprism_check(get_taxonomy('portfolio_category') === false && get_taxonomy('portfolio_tag') === false,
                'reenabling starts with both native portfolio taxonomies unregistered');
            Canon::write_file($repo . '/state/options/core.json', $originalBytes);
            $GLOBALS['vp_apply_context'] = [$repo, $policy, $compiled, $contract];
            $returnPlan = Apply::plan($repo, ['compiled' => $artifact, 'adapter_library' => $library, 'scope_request' => $contract]);
            $beforeReturn = $observe();
            $returnRefusal = null;
            try { Apply::apply($repo, ['compiled' => $artifact, 'adapter_library' => $library, 'scope_request' => $contract]); }
            catch (WPrism\CommandRefusalException $error) {
                $returnRefusal = ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => 'apply',
                    'error' => $error->reasonCode, 'reason_code' => $error->reasonCode,
                    'message' => $error->publicMessage, 'remediation' => $error->remediation];
            }
            wprism_check(is_array($returnRefusal), 'returning to an earlier artifact refuses at the actual public Apply boundary');
            VisualPortfolioSettingsEvidence::revisit($contract, $contract, $returnPlan, $returnRefusal ?? [], $beforeReturn, $observe());
            wprism_check(true, 'native revisit admission matches the engine refusal and exact unchanged target');
            foreach (['different-refusal', 'changed-engine'] as $fault) {
                $alteredRefusal = $returnRefusal;
                $alteredTarget = $observe();
                if ($fault === 'different-refusal') $alteredRefusal['message'] = 'unrelated failure';
                else $alteredTarget['engine']['wprism_kv'][] = ['k' => 'unexpected', 'v' => 'mutation'];
                $refused = false;
                try { VisualPortfolioSettingsEvidence::revisit($contract, $contract, $returnPlan, $alteredRefusal, $beforeReturn, $alteredTarget); }
                catch (RuntimeException $error) { $refused = true; }
                wprism_check($refused, 'native revisit admission rejects ' . $fault);
            }
            $returnOptions = ['compiled' => $artifact, 'adapter_library' => $library,
                'scope_request' => $contract, 'request_id' => VisualPortfolioSettingsEvidence::RETURN_REQUEST_ID];
            $returned = Apply::apply($repo, $returnOptions);
            wprism_check_same('complete', $returned['scoped_receipt']['phase'], 'a new explicit request returns to the earlier authored artifact');
            wprism_check_same(true, $observe()['enabled'], 'the new request restores native portfolio enablement');
            $returnedTarget = $observe();
            $GLOBALS['vp_fixture_taxonomies_registered'] = true;
            $returnedReplay = Apply::apply($repo, $returnOptions);
            wprism_check_same($returned['scoped_receipt'], $returnedReplay['scoped_receipt'], 'the explicit request replays its own exact terminal receipt');
            wprism_check_same($returnedTarget, $observe(), 'explicit request replay preserves every native and engine row');
            if ($case === 'clear') {
                $returnAdmission = ['enable', $beforeReturn, array_replace($returnedTarget, ['ids' => ['old' => 1, 'new' => 10]]),
                    $contract, $returnPlan, $returned, $returnedTarget, $returnedReplay, $observe()];
                VisualPortfolioSettingsEvidence::phase(...$returnAdmission);
                wprism_check(true, 'native reenabling admission proves the actual explicit engine request and exact receipt');
                $withoutSession = $returnAdmission;
                $withoutSession[6]['engine']['wprism_kv'] = array_values(array_filter($withoutSession[6]['engine']['wprism_kv'],
                    static fn(array $row): bool => $row['k'] !== WPrism\ScopedApplySession::STORAGE_KEY));
                $withoutSession[8] = $withoutSession[6];
                $refused = false;
                try { VisualPortfolioSettingsEvidence::phase(...$withoutSession); } catch (RuntimeException $error) { $refused = true; }
                wprism_check($refused, 'reenabling evidence without its explicit request authority refuses');
            }
            $inner = $root . '/sandbox/tmp/wprism-conformance-capture.vpfixture.' . bin2hex(random_bytes(3));
            $outer = $scratch . '/streams';
            if (!mkdir($inner, 0700, true)) throw new RuntimeException('native stream fixture directory could not be created');
            mkdir($outer, 0700);
            register_shutdown_function(static fn() => $remove($inner));
            $notice = 'private command diagnostics (unverified): ' . $inner . "\n";
            $transport = " Container wprism-vpfixture-cli1-run-123456abcdef Created \n";
            foreach (['valid', 'warning', 'different-stdout', 'duplicate-pointer', 'child-exit'] as $fault) {
                $original = ['stdout' => "{\"ok\":true}\n", 'stderr' => $transport, 'exit' => "0\n"];
                if ($fault === 'warning') $original['stderr'] .= "PHP Warning: hostile diagnostic\n";
                if ($fault === 'child-exit') $original['exit'] = "1\n";
                $wrapped = ['stdout' => $original['stdout'], 'stderr' => $notice . $original['stderr'], 'exit' => "0\n"];
                if ($fault === 'different-stdout') $wrapped['stdout'] = "{\"ok\":false}\n";
                if ($fault === 'duplicate-pointer') $wrapped['stderr'] = $notice . $wrapped['stderr'];
                foreach ([$inner => $original, $outer => $wrapped] as $dir => $streams) {
                    foreach ($streams as $suffix => $bytes) {
                        file_put_contents("$dir/command.$suffix", $bytes);
                        chmod("$dir/command.$suffix", 0600);
                    }
                }
                clearstatcache();
                $refused = false;
                try { VisualPortfolioSettingsEvidence::command($root, $outer . '/command', 'vpfixture', 'capture'); }
                catch (RuntimeException $error) { $refused = true; }
                wprism_check($refused === ($fault !== 'valid'), 'nested native stream admission: ' . $fault);
            }
        }
    }
} catch (Throwable $failure) {
    $queue = [$failure];
    $messages = [];
    for ($i = 0; $i < count($queue) && $i < 32; $i++) {
        $node = $queue[$i];
        $messages[] = get_class($node) . ': ' . $node->getMessage();
        if ($node->getPrevious() !== null) $queue[] = $node->getPrevious();
        if ($node instanceof WPrism\PrivateEvidenceCarrierException) {
            foreach ($node->private_evidence_causes() as $cause) $queue[] = $cause;
        }
    }
    if (isset($refusals[$case])) {
        $matched = str_contains(implode("\n", $messages), $refusals[$case]);
        wprism_check($matched, 'public Apply refuses the exact native settings defect');
        if (!$matched) fwrite(STDERR, implode("\n", $messages) . "\n");
        wprism_check_same($beforeDerivedMeta, $db->rows('wp_postmeta'), 'failed native repair rolls back all derived and unrelated metadata');
        wprism_check_same($GLOBALS['vp_before_native_options'] ?? $beforeOptions, array_values(array_filter($db->rows('wp_options'), static fn(array $row): bool => $row['option_name'] !== 'vp_general')),
            'failed native repair preserves its exact pre-invocation options, including the outstanding engine intent');
    } else {
        fwrite(STDERR, implode("\n", $messages) . "\n");
        wprism_check(false, 'public scoped settings Apply must complete');
    }
}
wprism_check_summary('regress_visual_portfolio_settings_apply');
