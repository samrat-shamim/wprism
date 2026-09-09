<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/sandbox/tests/lib/frozen_policy.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
wprism_test_define_agent_versions();

use WPrism\AdapterLibrary;
use WPrism\Canon;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\RepositoryAuthorizationException;
use WPrism\RepositoryCompiler;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$participants = ['change-wp-admin-login', 'wps-hide-login'];
$scenario = Canon::decode(Canon::read_file(dirname(__DIR__, 2) . '/scenario.json'));
wprism_check_same($participants, $scenario['participants'], 'both login-routing owners participate');
$library = AdapterLibrary::fromSourceTree($root);
foreach ([$participants, array_reverse($participants)] as $order) {
    wprism_check_throws(static fn() => Policy::load(null, array_merge(['core'], $order), adapterLibrary: $library),
        RuntimeException::class, 'actual login adapters refuse in pin order ' . implode(',', $order),
        'incompatible plugin adapters cannot share one policy');
}
$manifests = [Canon::decode(Canon::read_file($root . '/platform/adapter-library/core/manifest.json')),
    Canon::decode(Canon::read_file($root . '/adapter-packages/change-wp-admin-login/package/manifest.json'))];
$config = FrozenPolicy::site($manifests, WPRISM_SPEC_VERSION);
$config['policy']['post_types'] = $config['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy($manifests, $config);
$repo = sys_get_temp_dir() . '/wprism-aio-wps-' . bin2hex(random_bytes(8));
mkdir($repo . '/state/options', 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($repo));
Canon::write_file($repo . '/site.wprism.json', Canon::encode($config));
$write = static function (array $active) use ($repo, $policy): string {
    $records = [];
    foreach ($policy->authored_options() as $key => $rule) $records[$key] = OptionState::absent();
    $records['stylesheet'] = OptionState::absent();
    $records['template'] = OptionState::absent();
    $records['active_plugins'] = OptionState::present($active, 'yes');
    $bytes = Canon::encode(OptionState::document($records));
    Canon::write_file($repo . '/state/options/core.json', $bytes);
    return $bytes;
};
$aio = 'change-wp-admin-login/change-wp-admin-login.php';
$wps = 'wps-hide-login/wps-hide-login.php';
foreach ([[$aio, $wps], [$wps, $aio], [$wps]] as $active) {
    $bytes = $write($active);
    $payload = null;
    try {
        RepositoryCompiler::compile($repo, $policy);
    } catch (RepositoryAuthorizationException $failure) {
        $payload = $failure->payload();
    }
    wprism_check_same('repository_active_plugin_incompatible', $payload['diagnostics'][0]['code'] ?? null,
        'canonical active WPS refuses without a WPS adapter pin in order ' . implode(',', $active));
    wprism_check_same($bytes, Canon::read_file($repo . '/state/options/core.json'), 'refusal preserves canonical input bytes');
    wprism_check(!is_dir($repo . '/.wprism'), 'refusal creates no compiled artifact, transaction or recovery directory');
}
// The desired graph can retire the other router. A target's current active
// list cannot substitute for the repository's activation intent at compile.
WpStore::reset()->seedOptions(['active_plugins' => [$wps]]);
$write([$aio]);
$compiled = RepositoryCompiler::compile($repo, $policy);
wprism_check_same([$aio], OptionState::values($compiled->tree()['options/core']['data'])['active_plugins'],
    'canonical AIO-only intent compiles while target WPS still awaits code-phase retirement');
wprism_check_summary('AIO Login and WPS Hide Login incompatibility');
