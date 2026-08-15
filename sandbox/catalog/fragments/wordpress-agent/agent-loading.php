<?php
declare(strict_types=1);

/**
 * Thread 3 loading characterization.
 *
 * The agent must remain loadable from the checked-in source tree and from the
 * packaged MU-plugin layout.  The child process supplies only the WP-CLI
 * registration seam; no WordPress runtime or database is present.  Keeping
 * the two paths in one fixture prevents a loader refactor from preserving one
 * deployment shape while silently breaking the other.
 */

$root = dirname(__DIR__, 4);
$failures = [];

$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        fwrite(STDOUT, "ok: $message\n");
        return;
    }
    $failures[] = $message;
    fwrite(STDERR, "FAIL: $message\n");
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $children = scandir($path);
    if (is_array($children)) {
        foreach ($children as $child) {
            if ($child !== '.' && $child !== '..') {
                $removeTree($path . '/' . $child);
            }
        }
    }
    @rmdir($path);
};

$copyTree = static function (string $source, string $destination) use (&$copyTree): void {
    if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
        throw new RuntimeException('could not create loading fixture directory');
    }
    $children = scandir($source);
    if (!is_array($children)) {
        throw new RuntimeException('could not enumerate loading fixture source');
    }
    foreach ($children as $child) {
        if ($child === '.' || $child === '..') {
            continue;
        }
        $from = $source . '/' . $child;
        $to = $destination . '/' . $child;
        if (is_dir($from) && !is_link($from)) {
            $copyTree($from, $to);
            continue;
        }
        if (!is_file($from) || !copy($from, $to)) {
            throw new RuntimeException("could not copy loading fixture asset $child");
        }
    }
};

$child = <<<'PHP'
if (!defined('WP_CLI')) {
    define('WP_CLI', true);
}
if (!defined('ABSPATH')) {
    define('ABSPATH', '/fixture/wordpress/');
}
if (!class_exists('WP_CLI', false)) {
    class WP_CLI {
        public static function add_command(string $name, string $class): void {}
    }
}
require $argv[1];
if (!defined('DUO_AGENT_VERSION') || !class_exists('Duo\\Cli')) {
    fwrite(STDERR, "agent registration did not complete\n");
    exit(1);
}
fwrite(STDOUT, 'loaded:' . DUO_AGENT_VERSION . "\n");
PHP;

$load = static function (string $entry) use ($child): array {
    $process = proc_open(
        [PHP_BINARY, '-r', $child, $entry],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname($entry),
        ['LC_ALL' => 'C', 'TZ' => 'UTC'],
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        return [2, '', 'could not start child loader'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return [is_int($exitCode) ? $exitCode : 2, (string) $stdout, (string) $stderr];
};

$temporary = sys_get_temp_dir() . '/duo-agent-loading-' . bin2hex(random_bytes(8));
register_shutdown_function(static function () use ($temporary, $removeTree): void {
    $removeTree($temporary);
});

[$sourceExit, $sourceOutput, $sourceError] = $load($root . '/agent/duo.php');
$check($sourceExit === 0, 'source-tree agent bootstrap exits successfully without WordPress');
$check($sourceOutput === "loaded:0.5.0\n", 'source-tree agent registers the expected version');
$check($sourceError === '', 'source-tree agent emits no loader diagnostics');

$packagedAgent = $temporary . '/mu-plugins/duo';
$copyTree($root . '/agent', $packagedAgent);
if (!copy($root . '/agent/duo-loader.php', $temporary . '/mu-plugins/duo-loader.php')) {
    $check(false, 'packaged MU-plugin loader can be materialized');
} else {
    [$packagedExit, $packagedOutput, $packagedError] = $load($temporary . '/mu-plugins/duo-loader.php');
    $check($packagedExit === 0, 'packaged MU-plugin loader exits successfully without WordPress');
    $check($packagedOutput === "loaded:0.5.0\n", 'packaged MU-plugin loader registers the expected version');
    $check($packagedError === '', 'packaged MU-plugin loader emits no diagnostics');
}

if ($failures !== []) {
    fwrite(STDERR, sprintf("REGRESS_AGENT_LOADING FAILED (%d failures)\n", count($failures)));
    exit(1);
}
fwrite(STDOUT, "REGRESS_AGENT_LOADING PASSED\n");
