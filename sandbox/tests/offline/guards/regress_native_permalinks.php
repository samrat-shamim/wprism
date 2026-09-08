<?php
declare(strict_types=1);

// Isolated source-substitution controls cannot redefine functions in the
// parent's already-loaded fixture core. Each child provides exactly one
// foreign function before the opt-in loader and must refuse before invoking it.
$sourceControl = $argv[1] ?? '';
if ($sourceControl === '--source-parser') {
    function wp_parse_str(string $value, mixed &$result): void { $GLOBALS['foreign_native_calls']++; $result = []; }
} elseif ($sourceControl === '--source-multisite') {
    function is_multisite(): bool { $GLOBALS['foreign_native_calls']++; return false; }
} elseif ($sourceControl === '--source-registry') {
    function wp_filter_object_list(array $objects, array $args = [], string $operator = 'and', string|false $field = false): array {
        $GLOBALS['foreign_native_calls']++;
        return [];
    }
} elseif ($sourceControl === '--source-dispatcher') {
    function apply_filters(string $name, mixed $value, mixed ...$args): mixed {
        $GLOBALS['foreign_native_calls'] = ($GLOBALS['foreign_native_calls'] ?? 0) + 1;
        return wprism_fixture_filter_dispatch($name, $value, ...$args);
    }
}
if (str_starts_with($sourceControl, '--constant-')) {
    define('WP_HOME', match ($sourceControl) {
        '--constant-different' => 'http://constant.invalid/target/',
        '--constant-same' => 'http://current.invalid/base',
        default => 'not-an-absolute-home',
    });
}

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/native_permalink_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once dirname(__DIR__, 4) . '/agent/src/Adapter/ProviderDatabaseSession.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/NativePermalinks.php';

use WPrism\DatabaseQueryIsolation;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\NativePermalinks;
use WPrism\ProviderDatabaseSession;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

final class PermalinkCloneProbe {
    public static int $clones = 0;
    public function __clone(): void { self::$clones++; }
}

function permalink_post(array $changes = []): array {
    return array_replace([
        'ID' => 7, 'post_author' => 1, 'post_date' => '2026-09-08 01:02:03',
        'post_date_gmt' => '2026-09-08 01:02:03', 'post_content' => 'fixture body',
        'post_title' => 'Fixture', 'post_excerpt' => '', 'post_status' => 'publish',
        'comment_status' => 'closed', 'ping_status' => 'closed', 'post_password' => '',
        'post_name' => 'fixture', 'to_ping' => '', 'pinged' => '',
        'post_modified' => '2026-09-08 01:02:03', 'post_modified_gmt' => '2026-09-08 01:02:03',
        'post_content_filtered' => '', 'post_parent' => 0, 'guid' => 'https://original.invalid/?p=7',
        'menu_order' => 0, 'post_type' => 'post', 'post_mime_type' => '', 'comment_count' => '0',
    ], $changes);
}

function permalink_fixture(?array $posts = null, array $options = []): FakeWpdb {
    Db::forget_transaction_tracking();
    WpStore::reset();
    $GLOBALS['wp_filter'] = [];
    $GLOBALS['wp_object_cache'] = new WP_Object_Cache();
    $GLOBALS['native_option_external_cache'] = false;
    $GLOBALS['native_option_installing'] = false;
    $GLOBALS['wp_post_types'] = ['post' => new WP_Post_Type(), 'page' => new WP_Post_Type()];
    foreach ($GLOBALS['wp_post_types'] as $name => $type) $type->name = $name;
    $GLOBALS['wp_post_statuses'] = [];
    $GLOBALS['current_user'] = new WP_User();
    foreach (['publish', 'draft', 'pending', 'future', 'private'] as $status) {
        $GLOBALS['wp_post_statuses'][$status] = (object) ['internal' => false,
            'protected' => in_array($status, ['draft', 'pending', 'future'], true), 'private' => $status === 'private',
            'publicly_queryable' => $status === 'publish', '_builtin' => true, 'public' => $status === 'publish'];
    }
    unset($GLOBALS['post'], $_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
    PermalinkCloneProbe::$clones = 0;
    $rows = [];
    foreach (array_replace(['home' => 'http://current.invalid/base', 'permalink_structure' => '/%year%/%postname%/',
        'show_on_front' => 'posts', 'page_on_front' => '0'], $options) as $name => $value) {
        if ($value === null) continue;
        $rows[] = ['option_id' => count($rows) + 1, 'option_name' => $name, 'option_value' => $value, 'autoload' => 'on'];
    }
    $db = FakeWpdb::install()->enableInformationSchema()
        ->seedTable('wp_posts', $posts ?? [permalink_post()])
        ->setColumns('wp_posts', array_fill_keys(array_keys(permalink_post()), 'text'))
        ->setTableEngine('wp_posts', 'InnoDB')->seedTable('wp_options', $rows)->setTableEngine('wp_options', 'InnoDB');
    $GLOBALS['wp_rewrite'] = new WP_Rewrite();
    // The shared dispatcher has separate callback execution storage. This
    // exact core-shaped topology is intentional fixture setup, not repair by
    // the production reader under test.
    add_filter('option_home', '_config_wp_home');
    $GLOBALS['wp_filter']['option_home']->callbacks = [10 => ['_config_wp_home' => ['function' => '_config_wp_home', 'accepted_args' => 1]]];
    foreach (['wp_maybe_grant_install_languages_cap' => 1, 'wp_maybe_grant_resume_extensions_caps' => 1,
        'wp_maybe_grant_site_health_caps' => 4] as $callback => $args) add_filter('user_has_cap', $callback, 1, $args);
    $GLOBALS['wp_filter']['user_has_cap']->callbacks = [1 => [
        'wp_maybe_grant_install_languages_cap' => ['function' => 'wp_maybe_grant_install_languages_cap', 'accepted_args' => 1],
        'wp_maybe_grant_resume_extensions_caps' => ['function' => 'wp_maybe_grant_resume_extensions_caps', 'accepted_args' => 1],
        'wp_maybe_grant_site_health_caps' => ['function' => 'wp_maybe_grant_site_health_caps', 'accepted_args' => 4],
    ]];
    return $db;
}

function permalink_read(array $ids = [7], array $tables = ['wp_posts', 'wp_options']): array {
    return ProviderDatabaseSession::read_only_snapshot('native permalink fixture', NativeDatabaseProfile::read_only($tables),
        static fn(): array => NativePermalinks::read($ids, 'native permalink fixture'));
}

function permalink_custom_type(array|bool $rewrite = false, bool $hierarchical = false): void {
    $type = new WP_Post_Type();
    $type->name = 'book';
    $type->_builtin = false;
    $type->hierarchical = $hierarchical;
    $type->query_var = 'book';
    $type->rewrite = $rewrite;
    $GLOBALS['wp_post_types']['book'] = $type;
    if (is_array($rewrite)) $GLOBALS['wp_rewrite']->add_permastruct('book', $rewrite['slug'] . '/%book%',
        array_replace($rewrite, ['feed' => $rewrite['feeds']]));
}

function permalink_refuses(string $label, array $ids = [7], string $message = ''): void {
    global $wpdb;
    $before = [$wpdb->rows('wp_posts'), $wpdb->rows('wp_options')];
    wprism_check_throws(static fn() => permalink_read($ids), RuntimeException::class, $label, $message);
    wprism_check_same($before, [$wpdb->rows('wp_posts'), $wpdb->rows('wp_options')], $label . ': complete physical inputs survive');
    wprism_check(!DatabaseQueryIsolation::is_active(), $label . ': owner settles isolation');
}

if (str_starts_with($sourceControl, '--source-')) {
    $db = permalink_fixture([permalink_post(['post_type' => 'book'])], ['permalink_structure' => '']);
    permalink_custom_type(true);
    $safe = false;
    try {
        ProviderDatabaseSession::read_only_snapshot('source substitution', NativeDatabaseProfile::read_only(['wp_posts', 'wp_options']),
            static function () use (&$safe): void {
                $GLOBALS['foreign_native_calls'] = 0;
                try { NativePermalinks::read([7], 'source substitution'); }
                catch (RuntimeException $failure) {
                    $safe = str_contains($failure->getMessage(), 'standard core permalink functions') && $GLOBALS['foreign_native_calls'] === 0;
                    throw $failure;
                }
            });
    } catch (RuntimeException) {}
    if (!$safe || DatabaseQueryIsolation::is_active()) throw new RuntimeException('foreign source executed or escaped refusal');
    echo "source refused before call\n";
    exit(0);
}
if (str_starts_with($sourceControl, '--constant-')) {
    $db = permalink_fixture();
    $before = $db->rows('wp_options');
    if ($sourceControl === '--constant-invalid') {
        $refused = false;
        try { permalink_read(); } catch (RuntimeException $failure) { $refused = str_contains($failure->getMessage(), 'home authority'); }
        if (!$refused) throw new RuntimeException('invalid constant home escaped admission');
    } else {
        $home = $sourceControl === '--constant-same' ? 'http://current.invalid/base' : 'http://constant.invalid/target';
        if (permalink_read() !== ['home' => $home, 'permalinks' => [$home . '/2026/fixture/']]) {
            throw new RuntimeException('constant home did not govern the entire native batch');
        }
    }
    if ($db->rows('wp_options') !== $before) throw new RuntimeException('constant participant changed durable home');
    echo "constant participant verified\n";
    exit(0);
}

$db = permalink_fixture();
$expected = ['home' => 'http://current.invalid/base', 'permalinks' => ['http://current.invalid/base/2026/fixture/', false]];
wprism_check_same($expected, permalink_read([7, 99]), 'cold native reads preserve exact order and absence');
wprism_check_same($expected, permalink_read([7, 99]), 'warm native reads preserve exact current inputs');
wprism_check_same(['home' => $expected['home'], 'permalinks' => []], permalink_read([]), 'empty ID batch still admits its actual native home');
wprism_check(isset($GLOBALS['wp_filter']['option_home']), 'the admitted stock participant is retained');

foreach (['post', 'page'] as $type) {
    foreach (['publish', 'draft', 'pending', 'future', 'private'] as $status) {
        permalink_fixture([permalink_post(['post_type' => $type, 'post_status' => $status])]);
        $suffix = $status === 'publish' ? ($type === 'post' ? '/2026/fixture/' : '/fixture/')
            : ($type === 'post' ? '/?p=7' : '/?page_id=7');
        wprism_check_same(['home' => $expected['home'], 'permalinks' => [$expected['home'] . $suffix]], permalink_read(),
            'native status and type route: ' . $type . '/' . $status);
    }
}

$hierarchy = [permalink_post(['post_type' => 'page', 'post_parent' => 19]),
    permalink_post(['ID' => 19, 'post_type' => 'page', 'post_name' => 'parent', 'post_parent' => 21]),
    permalink_post(['ID' => 21, 'post_type' => 'page', 'post_name' => 'grandparent'])];
permalink_fixture($hierarchy);
wprism_check_same([$expected['home'] . '/grandparent/parent/fixture/'], permalink_read()['permalinks'], 'complete two-level native ancestor walk');
wprism_check_same('%pagename%', $GLOBALS['wp_rewrite']->page_structure, 'native lazy page structure may populate itself');
wprism_check_same([$expected['home'] . '/grandparent/parent/fixture/'], permalink_read()['permalinks'], 'stable populated native page structure');

foreach (['', '/%postname%', '/index.php/%postname%/'] as $structure) {
    permalink_fixture($hierarchy, ['permalink_structure' => $structure]);
    $suffix = $structure === '' ? '/?page_id=7' : ($structure === '/%postname%'
        ? '/grandparent/parent/fixture' : '/index.php/grandparent/parent/fixture/');
    wprism_check_same([$expected['home'] . $suffix], permalink_read()['permalinks'], 'plain, no-slash and index.php page forms remain native');
}
permalink_fixture($hierarchy, ['show_on_front' => 'page', 'page_on_front' => '7']);
wprism_check_same([$expected['home'] . '/'], permalink_read()['permalinks'], 'physical front-page options select native home');
permalink_fixture();
$_SERVER['HTTPS'] = 'on';
wprism_check_same('https://current.invalid/base', permalink_read()['home'], 'native HTTPS request context is an explicit input');

foreach ([[0], ['7'], [-1], [true], [7, 7], [1 => 7], range(1, 129)] as $ids) {
    permalink_fixture();
    permalink_refuses('invalid ID roster cannot substitute global post', $ids);
}
foreach (['post_name' => 'stale', 'post_parent' => 99, 'post_date' => '2020-01-01 00:00:00',
    'post_status' => 'draft', 'post_content' => 'even bounded stale full-row content'] as $name => $value) {
    permalink_fixture();
    $stale = (object) array_replace(permalink_post(), ['filter' => 'raw', $name => $value]);
    wprism_wp_store()->cache['posts'][7] = $stale;
    permalink_refuses('current full post cache must agree: ' . $name, message: 'cached post fields');
    wprism_check_same($stale, wprism_wp_store()->cache['posts'][7], 'stale primary cache is never repaired');
}
permalink_fixture($hierarchy);
wprism_wp_store()->cache['posts'][19] = (object) array_replace($hierarchy[1], ['filter' => 'raw', 'post_name' => 'old-parent']);
permalink_refuses('secondary ancestor cache cannot substitute old route', message: 'cached post fields');
permalink_fixture([permalink_post(['post_type' => 'page', 'post_parent' => 19])]);
wprism_wp_store()->cache['posts'][19] = (object) array_replace($hierarchy[1], ['filter' => 'raw']);
permalink_refuses('physically absent ancestor cannot become a cached ghost', message: 'ghost');
foreach ([new PermalinkCloneProbe(), (object) ['ID' => 7], new class extends stdClass {}, false, null] as $cache) {
    permalink_fixture();
    wprism_wp_store()->cache['posts'][7] = $cache;
    permalink_refuses('unsafe native cache value refuses before getter');
    wprism_check_same(0, PermalinkCloneProbe::$clones, 'hostile clone never runs');
}
foreach (['home', 'permalink_structure', 'show_on_front', 'page_on_front'] as $name) {
    foreach (['alloptions', 'individual', 'notoptions'] as $path) {
        permalink_fixture();
        if ($path === 'alloptions') wprism_wp_store()->cache['options']['alloptions'][$name] = 'stale';
        elseif ($path === 'individual') wprism_wp_store()->cache['options'][$name] = 'stale';
        else wprism_wp_store()->cache['options']['notoptions'][$name] = true;
        permalink_refuses('stale native option route ' . $name . '/' . $path, message: 'cache');
    }
}
foreach (['permalink_structure' => '/old/%postname%/', 'front' => '/old/', 'root' => 'old/',
    'index' => 'old.php', 'use_trailing_slashes' => false, 'page_structure' => '/old/%pagename%'] as $name => $value) {
    permalink_fixture($hierarchy);
    $GLOBALS['wp_rewrite']->$name = $value;
    permalink_refuses('stale native rewrite input: ' . $name, message: 'rewrite');
    wprism_check_same($value, $GLOBALS['wp_rewrite']->$name, 'reader does not repair stale rewrite state');
}
foreach (['home_url', 'set_url_scheme', 'pre_post_link', 'post_link', 'page_link', '_get_page_link',
    'get_page_uri', 'user_trailingslashit', 'get_post_status', 'is_post_status_viewable',
    'pre_option', 'pre_option_home', 'option_home', 'alloptions'] as $hook) {
    permalink_fixture();
    $calls = 0;
    add_filter($hook, static function ($value) use (&$calls) { $calls++; return $value; });
    permalink_refuses('unknown native participant refuses: ' . $hook, message: 'participant');
    wprism_check_same(0, $calls, 'unknown participant never executes');
}
foreach ([['post_type' => 'attachment'], ['post_type' => 'dynamic_type'], ['post_status' => 'unknown_status']] as $changes) {
    permalink_fixture([permalink_post($changes)]);
    permalink_refuses('unclosed native branch cannot receive a partial claim', message: 'unclosed');
}
foreach (['/%category%/%postname%/', '/%author%/%postname%/'] as $structure) {
    permalink_fixture(options: ['permalink_structure' => $structure]);
    permalink_refuses('unclosed term/user dependency must be explicit', message: 'unclosed');
}
permalink_fixture();
$GLOBALS['wp_post_statuses']['publish']->private = true;
permalink_refuses('a familiar status name cannot hide a capability branch', message: 'status flags');
permalink_fixture();
$GLOBALS['wp_post_types']['post']->_builtin = false;
permalink_refuses('post registry cannot reroute a core post into the CPT branch', message: 'reclassified');

$db = permalink_fixture();
$db->onQuery(static function (string $sql): void {
    if (str_contains($sql, 'SELECT * FROM wp_posts')) add_filter('home_url', static fn($value) => 'http://foreign.invalid');
});
permalink_refuses('topology drift cannot publish a native result', message: 'topology changed');
$db = permalink_fixture();
$db->onQuery(static function (string $sql): void {
    if (str_contains($sql, 'SELECT * FROM wp_posts')) $_SERVER['HTTPS'] = 'on';
});
permalink_refuses('native scheme drift cannot disagree with batch home', message: 'inputs changed');
permalink_fixture();
wprism_check_throws(static fn() => ProviderDatabaseSession::repeatable_read_write('caught permalink refusal',
    new NativeDatabaseProfile(['wp_options'], ['wp_posts']), static function (): void {
        try { NativePermalinks::read([0], 'caught permalink'); } catch (Throwable) {}
    }, static fn(): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN), RuntimeException::class,
    'swallowed native permalink refusal poisons the writer transaction');
wprism_check(!DatabaseQueryIsolation::is_active(), 'owner settles swallowed refusal');

foreach (['user_has_cap', 'map_meta_cap'] as $hook) {
    permalink_fixture([permalink_post(['post_status' => 'private'])]);
    $calls = 0;
    add_filter($hook, static function ($value) use (&$calls) { $calls++; return $value; });
    permalink_refuses('private permalink cannot run an unreviewed capability participant', message: 'participant');
    wprism_check_same(0, $calls, 'unreviewed capability callback is not invoked');
}
foreach ([null, new class extends WP_User {}, (object) ['ID' => 0, 'allcaps' => []]] as $user) {
    permalink_fixture([permalink_post(['post_status' => 'private'])]);
    $GLOBALS['current_user'] = $user;
    permalink_refuses('private permalink cannot resolve or replace an uninitialized/substituted user', message: 'anonymous');
}
foreach (['ID' => 1, 'allcaps' => ['read_private_posts' => true]] as $name => $value) {
    permalink_fixture([permalink_post(['post_status' => 'private'])]);
    $GLOBALS['current_user']->$name = $value;
    permalink_refuses('private permalink requires the declared anonymous capability context', message: 'anonymous');
}
permalink_fixture($hierarchy, ['show_on_front' => 'page', 'page_on_front' => '7']);
wprism_wp_store()->cache['posts'][7] = (object) array_replace($hierarchy[0], ['ID' => '7', 'filter' => 'raw']);
permalink_refuses('raw cached string ID cannot bypass the strict native front-page identity test', message: 'cached post fields');

foreach (['publish', 'draft', 'pending', 'future', 'private'] as $status) {
    permalink_fixture([permalink_post(['post_type' => 'book', 'post_status' => $status])]);
    permalink_custom_type(['slug' => 'library', 'with_front' => true, 'feeds' => false]);
    wprism_check_same([$expected['home'] . ($status === 'publish' ? '/library/fixture/' : '/?post_type=book&p=7')],
        permalink_read()['permalinks'], 'registered custom type native pretty/private/status form: ' . $status);
}
permalink_fixture([permalink_post(['post_type' => 'book', 'post_parent' => 19]), $hierarchy[1], $hierarchy[2]]);
permalink_custom_type(['slug' => 'library', 'with_front' => false, 'feeds' => false], true);
wprism_check_same([$expected['home'] . '/library/grandparent/parent/fixture/'], permalink_read()['permalinks'],
    'registered hierarchical custom type consumes admitted ancestor graph');
permalink_fixture([permalink_post(['post_type' => 'book'])], ['permalink_structure' => '']);
permalink_custom_type(true);
$_SERVER['REQUEST_URI'] = new PermalinkCloneProbe();
wprism_check_same([$expected['home'] . '/?book=fixture'], permalink_read()['permalinks'],
    'plain custom type with unnormalized rewrite uses explicit empty base, not REQUEST_URI');
unset($_SERVER['REQUEST_URI']);
foreach (['missing', 'struct', 'feed'] as $fault) {
    permalink_fixture([permalink_post(['post_type' => 'book'])]);
    permalink_custom_type(['slug' => 'library', 'with_front' => true, 'feeds' => false]);
    if ($fault === 'missing') unset($GLOBALS['wp_rewrite']->extra_permastructs['book']);
    else $GLOBALS['wp_rewrite']->extra_permastructs['book'][$fault] = $fault === 'feed' ? true : '/old/%book%';
    permalink_refuses('custom type must retain exact current native permastruct: ' . $fault, message: 'permastruct');
}
permalink_fixture([permalink_post(['post_type' => 'book', 'post_status' => 'private'])]);
permalink_custom_type();
$GLOBALS['wp_post_types']['book']->cap->read_private_posts = 'exist';
permalink_refuses('custom private capability cannot borrow the unconditional exist grant', message: 'anonymous');
permalink_fixture();
$alias = new WP_Post_Type();
$alias->name = 'post';
$alias->_builtin = false;
$GLOBALS['wp_post_types']['foreign'] = $alias;
permalink_refuses('an unrelated custom-type name cannot alias and reroute the core post branch', message: 'identity');
foreach (['wp_template', 'wp_template_part', 'wp_block', 'nav_menu_item', 'user_request'] as $builtin) {
    permalink_fixture([permalink_post(['post_type' => $builtin])]);
    $type = new WP_Post_Type();
    $type->name = $builtin;
    $GLOBALS['wp_post_types'][$builtin] = $type;
    wprism_check_same([$expected['home'] . '/2026/fixture/'], permalink_read()['permalinks'],
        'other builtin types use the same admitted core post-link branch, without an HTTP viewability claim: ' . $builtin);
}

foreach (['--source-parser', '--source-multisite', '--source-registry', '--source-dispatcher',
    '--constant-different', '--constant-same', '--constant-invalid'] as $control) {
    $process = proc_open([PHP_BINARY, __FILE__, $control], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    wprism_check_same(0, $exit, 'isolated native source/configuration control: ' . $control);
    wprism_check_same('', $stderr, 'source/configuration control has no warnings: ' . $control);
    wprism_check_same(str_starts_with($control, '--source-') ? "source refused before call\n" : "constant participant verified\n",
        $stdout, 'source/configuration control proves its complete expected result');
}

foreach ([str_repeat('x', 1048577), new PermalinkCloneProbe(), ['unrelated nonraw array']] as $value) {
    permalink_fixture();
    wprism_wp_store()->cache['options']['alloptions']['unrelated'] = $value;
    permalink_refuses('unrelated alloptions value still crosses the native allocation frontier', message: 'allocation frontier');
}
permalink_fixture(options: ['unrelated' => str_repeat('x', 1048577)]);
wprism_wp_store()->cache['options']['alloptions'] = [];
permalink_refuses('cold alloptions allocation cannot hide oversized unrelated physical values', message: 'admission failed');
permalink_fixture();
wprism_wp_store()->cache['options']['alloptions'] += array_fill_keys(array_map(static fn(int $i): string => 'extra_' . $i, range(1, 8193)), '');
permalink_refuses('native alloptions has a finite entry frontier', message: 'entry frontier');

foreach ([0, 7, 99] as $parent) {
    permalink_fixture([permalink_post(['post_type' => 'page', 'post_parent' => $parent])]);
    wprism_check_same([$expected['home'] . '/fixture/'], permalink_read()['permalinks'],
        'lazy native ancestor property handles root, self cycle and absent parent without invented cache entries');
}
permalink_fixture([permalink_post(['post_type' => 'page', 'post_parent' => 19]),
    permalink_post(['ID' => 19, 'post_type' => 'page', 'post_parent' => 7, 'post_name' => 'parent'])]);
wprism_check_same([$expected['home'] . '/parent/fixture/'], permalink_read()['permalinks'], 'lazy native ancestor property terminates a two-post cycle');

$evidenceIds = array_combine(['grand', 'parent', 'post_publish', 'post_draft', 'post_pending', 'post_future', 'post_private',
    'page_publish', 'page_draft', 'page_pending', 'page_future', 'page_private', 'book_publish', 'book_private', 'template', 'absent'], range(1, 16));
$evidenceHome = 'http://fixture1.invalid';
$evidenceState = ['posts' => ['row_count' => 2, 'raw_bytes' => 200, 'rows_sha256' => str_repeat('a', 64)],
    'options' => ['row_count' => 4, 'raw_bytes' => 400, 'rows_sha256' => str_repeat('b', 64)]];
$evidenceUrls = [
    'post_statuses' => ['/2026/post-publish/', '/?p=4', '/?p=5', '/?p=6', '/?p=7'],
    'page_statuses' => ['/grand/parent/page-publish/', '/?page_id=9', '/?page_id=10', '/?page_id=11', '/?page_id=12'],
    'warm_pages' => ['/grand/parent/page-publish/', '/?page_id=9', '/?page_id=10', '/?page_id=11', '/?page_id=12'],
    'custom_types' => ['/library/grand/parent/book/', '/?post_type=wprism_probe_book&p=14'],
    'builtin_template' => ['/2026/native-template/'],
    'front_page' => ['/'], 'plain' => ['/?p=3', '/?page_id=8', '/?wprism_probe_book=grand/parent/book'],
    'index' => ['/index.php/grand/parent/page-publish/'], 'no_slash' => ['/grand/parent/page-publish'], 'absent' => [false],
];
$evidenceObservations = [];
foreach ($evidenceUrls as $name => $urls) $evidenceObservations[$name] = ['home' => $evidenceHome,
    'permalinks' => array_map(static fn(string|false $url): string|false => $url === false ? false : $evidenceHome . $url, $urls)];
$evidenceObservations['constant_home'] = ['home' => 'http://constant.example.test/base',
    'permalinks' => ['http://constant.example.test/base/2026/post-publish/']];
$evidence = ['format' => 'wprism-native-permalinks/v1', 'engine' => 'MariaDB', 'ids' => $evidenceIds,
    'initial' => $evidenceState, 'final' => $evidenceState, 'observations' => $evidenceObservations,
    'refusals' => ['stale_primary', 'stale_ancestor', 'stale_home_cache', 'stale_page_structure', 'foreign_home_hook',
        'foreign_capability_hook', 'observer_callback', 'swallowed_failure']];
$scratch = dirname(__DIR__, 3) . '/tmp/native-permalink-evidence-' . bin2hex(random_bytes(6));
mkdir($scratch, 0700, true);
$admit = static function (array $data, string $stderr = '', string $exit = '0') use ($scratch, $evidenceHome): int {
    foreach (['stdout' => json_encode($data, JSON_THROW_ON_ERROR) . "\n", 'stderr' => $stderr, 'exit' => $exit . "\n"] as $suffix => $bytes) {
        file_put_contents($scratch . '/native.' . $suffix, $bytes);
        chmod($scratch . '/native.' . $suffix, 0600);
    }
    $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/native-permalinks.php', '--admit',
        $scratch . '/native', $evidenceHome], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return proc_close($process);
};
try {
    wprism_check_same(0, $admit($evidence), 'host admits an independently specified complete concrete URL record');
    foreach (array_keys($evidence) as $key) {
        $bad = $evidence;
        unset($bad[$key]);
        wprism_check($admit($bad) !== 0, 'native evidence cannot omit a complete-record field: ' . $key);
    }
    foreach (array_keys($evidenceObservations) as $case) {
        $bad = $evidence;
        $bad['observations'][$case]['permalinks'][0] = 'http://unrelated.invalid';
        wprism_check($admit($bad) !== 0, 'host independently checks the concrete native vector: ' . $case);
    }
    foreach (['initial', 'final'] as $phase) {
        $bad = $evidence;
        $bad[$phase]['posts']['rows_sha256'] = str_repeat('c', 64);
        wprism_check($admit($bad) !== 0, 'complete physical preservation cannot omit a changed phase');
    }
    $bad = $evidence;
    $bad['initial']['posts']['row_count'] = $bad['final']['posts']['row_count'] = 0;
    wprism_check($admit($bad) !== 0, 'coherently empty preservation is not a nonempty native fixture');
    wprism_check($admit($evidence, "PHP Warning: native warning\n") !== 0, 'a complete JSON record cannot hide native warnings');
    wprism_check($admit($evidence, '', '1') !== 0, 'a complete JSON record cannot hide native transport failure');
} finally {
    foreach (['stdout', 'stderr', 'exit'] as $suffix) unlink($scratch . '/native.' . $suffix);
    rmdir($scratch);
}

wprism_check_summary('regress_native_permalinks');
