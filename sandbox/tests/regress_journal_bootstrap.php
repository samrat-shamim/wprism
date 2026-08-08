<?php
/**
 * Offline regression for the fatal-safe journal/bootstrap boundary.
 *
 * The control-plane agent is included from WP-CLI's after_wp_config_load
 * hook, before ordinary WordPress functions exist.  This process deliberately
 * defines only the WP_CLI class surface needed by agent/duo.php; the child
 * process then proves that the ordinary opt-in path still installs hooks once
 * the three WordPress journal APIs are available.
 */

declare(strict_types=1);

final class WP_CLI {
    /** @var array<string,string> */
    public static array $commands = [];

    public static function add_command(string $name, string $class): void {
        self::$commands[$name] = $class;
    }
}

define('WP_CLI', true);
$normal = ($argv[1] ?? '') === 'normal';

if ($normal) {
    define('DUO_JOURNAL', true);
    $GLOBALS['journal_boot_calls'] = ['filters' => [], 'actions' => []];

    function get_option(string $key, $default = false) {
        return $default;
    }

    function add_filter(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): bool {
        $GLOBALS['journal_boot_calls']['filters'][] = [$hook, $priority, $acceptedArgs];
        return true;
    }

    function add_action(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): bool {
        $GLOBALS['journal_boot_calls']['actions'][] = [$hook, $priority, $acceptedArgs];
        return true;
    }
} else {
    define('DUO_CONTROL_PLANE', true);
    define('DUO_JOURNAL', true);
    foreach (['get_option', 'add_filter', 'add_action'] as $wpFunction) {
        if (function_exists($wpFunction)) {
            fwrite(STDERR, "FAIL: early regression unexpectedly has WordPress function $wpFunction\n");
            exit(1);
        }
    }
}

require dirname(__DIR__, 2) . '/agent/duo.php';

$journal = new ReflectionClass(\Duo\Journal::class);
$booted = (bool) $journal->getStaticPropertyValue('booted');

if ($normal) {
    if (!$booted) {
        fwrite(STDERR, "FAIL: ordinary DUO_JOURNAL runtime did not boot\n");
        exit(1);
    }
    $calls = $GLOBALS['journal_boot_calls'];
    if (count($calls['filters']) !== 1 || $calls['filters'][0][0] !== 'query'
        || count($calls['actions']) !== 1 || $calls['actions'][0][0] !== 'shutdown') {
        fwrite(STDERR, "FAIL: ordinary journal boot did not install query/shutdown hooks\n");
        exit(1);
    }
    echo "ok: ordinary journal opt-in boots and installs its hooks\n";
    exit(0);
}

if ($booted) {
    fwrite(STDERR, "FAIL: early control-plane include attempted journal boot\n");
    exit(1);
}
echo "ok: early control-plane include is journal-safe without WordPress functions\n";

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' normal';
$process = proc_open($command, $descriptors, $pipes);
if (!is_resource($process)) {
    fwrite(STDERR, "FAIL: could not launch ordinary journal child regression\n");
    exit(1);
}
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);
if ($status !== 0 || $stderr !== '' || !str_contains((string) $stdout, 'ordinary journal opt-in boots')) {
    fwrite(STDERR, "FAIL: ordinary journal child regression failed\n" . (string) $stdout . (string) $stderr);
    exit(1);
}
echo "ok: ordinary journal behavior remains intact\nALL PASSED\n";
