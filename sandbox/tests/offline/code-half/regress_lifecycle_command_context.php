<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/ShellProbe.php';
if (($argv[1] ?? '') === '--probe') {
    require_once $root . '/sandbox/tests/lib/wp_stubs.php';
    final class WP_CLI {
        public static function get_runner(): object {
            $mode = $GLOBALS['argv'][3] ?? '';
            $config = match ($mode) {
                'skip-all' => ['skip-plugins' => true],
                'skip-plugin' => ['skip-plugins' => 'lifecycle-context-probe'],
                'skip-themes' => ['skip-themes' => true],
                'skip-theme' => ['skip-themes' => 'twentytwentyone'],
                'false-defaults' => ['skip-plugins' => false, 'skip-themes' => false],
                default => ['skip-plugins' => '', 'skip-themes' => ''],
            };
            return (object) ['arguments' => json_decode($GLOBALS['argv'][2], true, 16, JSON_THROW_ON_ERROR), 'config' => $config];
        }
        public static function add_command(string $name, string $class): void {}
        public static function error(string $message): never { throw new RuntimeException($message); }
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
            $expected = str_starts_with($argv[3] ?? '', 'skip-')
                ? 'requires all lifecycle participants to load' : 'requires administrative context from the MU bootstrap';
            if (!str_contains($failure->getMessage(), $expected)) throw $failure;
        }
    }
    $publicRefused = false;
    if (WP_CLI && !$ready) {
        try { (new WPrism\Cli())->deploy([], ['repo' => ABSPATH . 'uninitialized-context-probe']); }
        catch (RuntimeException $failure) {
            $expected = str_starts_with($argv[3] ?? '', 'skip-')
                ? 'requires all lifecycle participants to load' : 'requires administrative context from the MU bootstrap';
            if (!str_contains($failure->getMessage(), $expected)) throw $failure;
            $publicRefused = true;
        }
    }
    echo json_encode(['public_refused' => $publicRefused, 'ready' => $ready, 'admin' => is_admin(), 'entry' => $_SERVER['PHP_SELF'],
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
    [['wprism', 'deploy'], false, 'skip-all'],
    [['wprism', 'deploy'], false, 'skip-plugin'],
    [['wprism', 'deploy'], false, 'skip-themes'],
    [['wprism', 'deploy'], false, 'skip-theme'],
    [['wprism', 'deploy'], true, 'false-defaults'],
] as [$command, $admin, $mode]) {
    [$exit, $out, $err] = WPrismTest\ShellProbe::run('exec "$1" "$2" --probe "$3" "$4"',
        [PHP_BINARY, __FILE__, json_encode($command, JSON_THROW_ON_ERROR), $mode], $root);
    wprism_check($exit === 0 && $err === '', 'actual agent bootstrap has clean output: ' . implode(' ', $command) . ' ' . $mode);
    $actual = json_decode($out, true, 16, JSON_THROW_ON_ERROR);
    wprism_check_same(!$admin && $mode !== 'web', $actual['public_refused'],
        'public deploy refuses the incomplete context before attempting repository or lifecycle work');
    wprism_check_same($admin, $actual['ready'], 'deployment gate requires the complete early context witness');
    wprism_check_same($admin ? ($mode === 'existing-admin' ? '/wp-cli.php' : '/wp-admin/wprism-deploy.php') : '/wp-cli.php',
        $actual['entry'], 'only a newly established lifecycle context selects its administrative entry point');
    wprism_check_same($admin, $actual['admin'], 'bootstrap selects administrative context only for exact lifecycle command: ' . implode(' ', $command) . ' ' . $mode);
    wprism_check_same($admin ? 1 : false, $actual['activation'], 'activation hook registration reflects bootstrap context');
    wprism_check_same($admin ? 1 : false, $actual['deactivation'], 'deactivation hook registration reflects bootstrap context');
}
wprism_check_summary('lifecycle command context');
