<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/ShellProbe.php';
if (($argv[1] ?? '') === '--probe') {
    require_once $root . '/sandbox/tests/lib/wp_stubs.php';
    final class WP_CLI {
        public static function get_runner(): object {
            return (object) ['arguments' => json_decode($GLOBALS['argv'][2], true, 16, JSON_THROW_ON_ERROR)];
        }
        public static function add_command(string $name, string $class): void {}
    }
    function is_admin(): bool {
        return defined('WP_ADMIN') && WP_ADMIN;
    }
    function did_action(string $hook): int {
        return ($GLOBALS['argv'][3] ?? '') === 'late' && $hook === 'muplugins_loaded' ? 1 : 0;
    }
    if (($argv[3] ?? '') === 'disabled') define('WP_ADMIN', false);
    if (($argv[3] ?? '') === 'existing-admin') define('WP_ADMIN', true);
    define('WP_CLI', ($argv[3] ?? '') !== 'web');
    $_SERVER['PHP_SELF'] = '/wp-cli.php';
    require $root . '/agent/wprism.php';
    // Match the real Importer 2.7.5 main-file guard: an administrative plugin
    // registers neither lifecycle callback if bootstrap chose CLI context.
    if (is_admin()) {
        add_action('fixture_activate', static fn() => update_option('fixture_activation', 1));
        add_action('fixture_deactivate', static fn() => update_option('fixture_deactivation', 1));
    }
    do_action('fixture_activate');
    do_action('fixture_deactivate');
    $ready = false;
    if (WP_CLI) {
        try { WPrism\LifecycleCommandContext::assert_ready(); $ready = true; }
        catch (RuntimeException $failure) {
            if (!str_contains($failure->getMessage(), 'requires administrative context from the MU bootstrap')) throw $failure;
        }
    }
    echo json_encode(['ready' => $ready, 'admin' => is_admin(), 'entry' => $_SERVER['PHP_SELF'],
        'activation' => get_option('fixture_activation'), 'deactivation' => get_option('fixture_deactivation')], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
foreach ([
    [['wprism', 'deploy'], true, 'cli'],
    [['wprism', 'deploy', '/unused'], true, 'cli'],
    [['wprism', 'apply'], false, 'cli'],
    [['wprism', 'capture'], false, 'cli'],
    [['wprism', 'plan', 'deploy'], false, 'cli'],
    [['wprism', 'deploy-extra'], false, 'cli'],
    [['plugin', 'activate'], false, 'cli'],
    [['wprism', 'deploy'], false, 'web'],
    [['wprism', 'deploy'], false, 'disabled'],
    [['wprism', 'deploy'], false, 'late'],
    [['wprism', 'deploy'], true, 'existing-admin'],
] as [$command, $admin, $mode]) {
    [$exit, $out, $err] = WPrismTest\ShellProbe::run('exec "$1" "$2" --probe "$3" "$4"',
        [PHP_BINARY, __FILE__, json_encode($command, JSON_THROW_ON_ERROR), $mode], $root);
    wprism_check($exit === 0 && $err === '', 'actual agent bootstrap has clean output: ' . implode(' ', $command) . ' ' . $mode);
    $actual = json_decode($out, true, 16, JSON_THROW_ON_ERROR);
    wprism_check_same($admin, $actual['ready'], 'deployment gate requires the complete early context witness');
    wprism_check_same($admin ? ($mode === 'existing-admin' ? '/wp-cli.php' : '/wp-admin/wprism-deploy.php') : '/wp-cli.php',
        $actual['entry'], 'only a newly established lifecycle context selects its administrative entry point');
    wprism_check_same($admin, $actual['admin'], 'bootstrap selects administrative context only for exact lifecycle command: ' . implode(' ', $command) . ' ' . $mode);
    wprism_check_same($admin ? 1 : false, $actual['activation'], 'activation hook registration reflects bootstrap context');
    wprism_check_same($admin ? 1 : false, $actual['deactivation'], 'deactivation hook registration reflects bootstrap context');
}
wprism_check_summary('lifecycle command context');
