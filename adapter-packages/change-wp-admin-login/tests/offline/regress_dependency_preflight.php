<?php
declare(strict_types=1);
$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Promotion/LifecyclePlanner.php';
wprism_test_define_agent_versions();
$policy = WPrism\Policy::load(null, ['core', 'change-wp-admin-login'], adapterLibrary: WPrism\AdapterLibrary::fromSourceTree($root));
$plugin = 'change-wp-admin-login/change-wp-admin-login.php';
$desired = ['active_plugins'=>[$plugin]];
$facts = ['active_plugins'=>[$plugin], 'plugins'=>[$plugin=>'2.4.1'], 'plugin_exists'=>[$plugin=>true],
    'template'=>'fixture','stylesheet'=>'fixture','themes'=>['fixture'=>'1.0'],'theme_exists'=>['fixture'=>true],'recorded_raw'=>null];
$inspect = static fn(array $changes) => WPrism\LifecyclePlanner::deployment_status_from_observation($policy, $desired, false, array_replace($facts, $changes));
$ready = $inspect([]);
wprism_check_same([], $ready['reasons'], 'exact active AIO artifact requires no activation work');
wprism_check_same([], $ready['warnings'], 'exact AIO artifact has no compatibility override');
$inactive = $inspect(['active_plugins'=>[]]);
wprism_check_same(['inactive_in_environment'], $inactive['reasons'], 'exact inactive AIO artifact requires the code lifecycle phase before apply');
foreach (['2.4.0', '2.4.2', '3.0.0', ''] as $version) {
    wprism_check_throws(static fn() => $inspect(['plugins'=>[$plugin=>$version]]), RuntimeException::class,
        'AIO lifecycle preflight refuses ' . ($version === '' ? 'unreadable version' : $version), 'declared version_range');
}
wprism_check_throws(static fn() => $inspect(['plugins'=>[], 'plugin_exists'=>[$plugin=>false]]), RuntimeException::class,
    'missing AIO code refuses before activation', 'does not exist');
wprism_check_throws(static fn() => $inspect(['plugins'=>['aio-login/aio-login.php'=>'2.4.1'], 'plugin_exists'=>['aio-login/aio-login.php'=>true]]), RuntimeException::class,
    'a version-matching wrong basename cannot impersonate AIO code', 'does not exist');
foreach (['wps-hide-login/wps-hide-login.php', 'aio-login-pro/aio-login-pro.php'] as $competitor) {
    foreach ([[$plugin,$competitor],[$competitor,$plugin]] as $active) {
        $conflicts = $policy->active_plugin_conflicts($active);
        wprism_check_same(1, count($conflicts), 'AIO competitor refuses in either native load order: ' . $competitor);
    }
}
wprism_check_summary('AIO dependency preflight');
