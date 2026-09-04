<?php
/**
 * Offline process-boundary proof for WPrism\WpCliChildProcess.
 *
 * WP_CLI::runcommand(return=all) drains stdout fully before stderr. The
 * stderr-first child below writes well beyond a POSIX pipe before emitting its
 * receipt, so the prior product path deadlocks. The large/TERM-ignoring cases
 * additionally prove that arbitrary boot output cannot exhaust the 32 MiB
 * parent running this suite or outlive the helper's wall-clock boundary.
 */
declare(strict_types=1);

namespace WP_CLI\Utils {
    function check_proc_available(string $context): void {
        $GLOBALS['wp_cli_child_proc_checks'][] = $context;
    }

    function get_php_binary(): string {
        return $GLOBALS['wp_cli_child_php_binary'] ?? PHP_BINARY;
    }

    /** @param array<string,mixed> $args */
    function assoc_args_to_str(array $args, array $sensitive = []): string {
        $out = '';
        foreach ($args as $key => $value) {
            if ($value === true) {
                $out .= " --$key";
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    $out .= assoc_args_to_str([$key => $item], $sensitive);
                }
            } else {
                $out .= " --$key=" . escapeshellarg((string) $value);
            }
        }
        return $out;
    }

    /** @param array<int,mixed> $descriptors @param array<int,resource> $pipes */
    function proc_open_compat(
        string $command,
        array $descriptors,
        array &$pipes,
        ?string $cwd = null,
        ?array $environment = null,
        ?array $options = null
    ) {
        $GLOBALS['wp_cli_child_commands'][] = $command;
        $process = proc_open($command, $descriptors, $pipes, $cwd, $environment, $options);
        $delay = $GLOBALS['wp_cli_child_proc_open_delay_microseconds'] ?? 0;
        if (is_int($delay) && $delay > 0) {
            usleep($delay);
        }
        return $process;
    }
}

namespace {
    require_once __DIR__ . '/../../lib/check.php';

    $priorMemoryLimit = ini_set('memory_limit', '32M');

    final class WpCliChildConfigurator {
        /** @return array{0:array{},1:array{},2:array<string,mixed>} */
        public function parse_args(array $args): array {
            return [[], [], $GLOBALS['wp_cli_child_runtime_config']];
        }
    }

    final class WP_CLI {
        public static function get_configurator(): WpCliChildConfigurator {
            return new WpCliChildConfigurator();
        }

        public static function get_runner(): object {
            return (object) ['alias' => $GLOBALS['wp_cli_child_alias']];
        }
    }

    /** @param callable():mixed $callback */
    function wp_cli_child_refuses(callable $callback, string $needle, string $message): RuntimeException {
        try {
            $callback();
            wprism_check(false, "$message (expected refusal containing '$needle')");
            throw new RuntimeException('test did not observe the required refusal');
        } catch (RuntimeException $failure) {
            wprism_check(
                str_contains($failure->getMessage(), $needle),
                "$message ({$failure->getMessage()})"
            );
            return $failure;
        }
    }

    /** Decode the one escapeshellarg() boundary carrying reviewed WP-CLI bytes. */
    function wp_cli_child_inner_command(string $launch): string {
        $marker = ' -- ';
        $offset = strrpos($launch, $marker);
        if ($offset === false) {
            throw new RuntimeException('test launch has no fixed inner-command boundary');
        }
        $encoded = substr($launch, $offset + strlen($marker));
        if (strlen($encoded) < 2 || $encoded[0] !== "'" || $encoded[strlen($encoded) - 1] !== "'") {
            throw new RuntimeException('test launch has malformed inner-command quoting');
        }
        return str_replace("'\\''", "'", substr($encoded, 1, -1));
    }

    /** A Linux PID-1 zombie is dead/inert even though kill(pid, 0) sees it. */
    function wp_cli_child_process_is_inert(int $pid): bool {
        if ($pid <= 1) {
            return false;
        }
        if (@posix_kill($pid, 0) !== true) {
            return true;
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            return false;
        }
        $stat = @file_get_contents("/proc/$pid/stat");
        if (!is_string($stat)) {
            return @posix_kill($pid, 0) !== true;
        }
        $close = strrpos($stat, ') ');
        if ($close === false) {
            return false;
        }
        $fields = explode(' ', substr($stat, $close + 2));
        return isset($fields[0]) && in_array($fields[0], ['Z', 'X'], true);
    }

    /** Install a probe briefly and report whether the exact expected handler was current. */
    function wp_cli_child_handler_is_current(callable $expected): bool {
        $probe = static fn(int $_severity, string $_message): bool => true;
        $current = set_error_handler($probe);
        restore_error_handler();
        return $current === $expected;
    }

    /** @param array<string,mixed> $result */
    function wp_cli_child_write_probe_result(string $path, array $result): never {
        $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $encoded) !== strlen($encoded)) {
            exit(97);
        }
        exit(0);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    function wp_cli_child_run_probe(array $arguments): array {
        $process = proc_open(
            array_merge([PHP_BINARY, __FILE__], $arguments),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            return ['exit' => -1, 'stdout' => '', 'stderr' => 'probe could not start'];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [
            'exit' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    $root = dirname(__DIR__, 4);
    require_once $root . '/agent/src/Kernel/WpCliChildProcess.php';

    $probeMode = $GLOBALS['argv'][1] ?? null;
    if ($probeMode === '--parent-death-probe') {
        $fixture = $GLOBALS['argv'][2] ?? '';
        $fixtureMode = $GLOBALS['argv'][3] ?? '';
        $childPidFile = $GLOBALS['argv'][4] ?? '';
        $readyFile = $GLOBALS['argv'][5] ?? '';
        $markerFile = $GLOBALS['argv'][6] ?? '';
        $groupPidFile = $GLOBALS['argv'][7] ?? '';
        $GLOBALS['argv'] = [$fixture];
        $GLOBALS['wp_cli_child_runtime_config'] = ['path' => '/srv/probe'];
        $GLOBALS['wp_cli_child_alias'] = 'probe';
        $GLOBALS['wp_cli_child_php_binary'] = PHP_BINARY;
        WPrism\WpCliChildProcess::capture(
            $fixtureMode . ' '
                . escapeshellarg($childPidFile) . ' '
                . escapeshellarg($readyFile) . ' '
                . escapeshellarg($markerFile) . ' '
                . escapeshellarg($groupPidFile),
            30,
            65536,
            65536
        );
        exit(31);
    }
    if (in_array($probeMode, ['--eintr-probe', '--non-eintr-probe'], true)) {
        $fixture = $GLOBALS['argv'][2] ?? '';
        $readyFile = $GLOBALS['argv'][3] ?? '';
        $resultFile = $GLOBALS['argv'][4] ?? '';
        $GLOBALS['argv'] = [$fixture];
        $GLOBALS['wp_cli_child_runtime_config'] = ['path' => '/srv/probe'];
        $GLOBALS['wp_cli_child_alias'] = 'probe';
        $GLOBALS['wp_cli_child_php_binary'] = PHP_BINARY;
        $GLOBALS['wp_cli_child_commands'] = [];
        $GLOBALS['wp_cli_child_proc_checks'] = [];

        if ($probeMode === '--eintr-probe') {
            $outerWarnings = [];
            $outer = static function (
                int $severity,
                string $message,
                string $_file,
                int $_line
            ) use (&$outerWarnings): bool {
                $outerWarnings[] = [$severity, $message];
                return true;
            };
            set_error_handler($outer);
            pcntl_async_signals(true);
            pcntl_signal(SIGUSR1, static function (): void {}, false);
            $workerPid = getmypid();
            $signalerPid = pcntl_fork();
            if ($signalerPid === 0) {
                $readyDeadline = hrtime(true) + 2000000000;
                while (!is_file($readyFile) && hrtime(true) < $readyDeadline) {
                    usleep(1000);
                }
                if (!is_file($readyFile)) {
                    exit(41);
                }
                for ($signal = 0; $signal < 60; $signal++) {
                    if (@posix_kill($workerPid, SIGUSR1) !== true) {
                        exit(42);
                    }
                    usleep(5000);
                }
                exit(0);
            }

            $receipt = null;
            $failure = null;
            if ($signalerPid < 1) {
                $failure = 'signal helper could not start';
            } else {
                try {
                    $receipt = WPrism\WpCliChildProcess::capture(
                        'delayed-receipt ' . escapeshellarg($readyFile),
                        5,
                        65536,
                        65536
                    );
                } catch (Throwable $exception) {
                    $failure = $exception->getMessage();
                }
            }

            $signalerStatus = 0;
            $signalerWaited = -1;
            if ($signalerPid > 0) {
                $waitDeadline = hrtime(true) + 3000000000;
                do {
                    $signalerWaited = @pcntl_waitpid($signalerPid, $signalerStatus, WNOHANG);
                    if ($signalerWaited === $signalerPid || $signalerWaited === -1) {
                        break;
                    }
                    usleep(1000);
                } while (hrtime(true) < $waitDeadline);
                if ($signalerWaited !== $signalerPid) {
                    @posix_kill($signalerPid, SIGKILL);
                    $signalerWaited = @pcntl_waitpid($signalerPid, $signalerStatus);
                }
            }

            wp_cli_child_write_probe_result($resultFile, [
                'receipt' => $receipt,
                'failure' => $failure,
                'signaler_ok' => $signalerPid > 0
                    && $signalerWaited === $signalerPid
                    && pcntl_wifexited($signalerStatus)
                    && pcntl_wexitstatus($signalerStatus) === 0,
                'outer_warnings' => $outerWarnings,
                'handler_restored' => wp_cli_child_handler_is_current($outer),
            ]);
        }

        $handles = [];
        for ($index = 0; $index < 1300; $index++) {
            $handle = @fopen('/dev/null', 'rb');
            if (!is_resource($handle)) {
                break;
            }
            $handles[] = $handle;
        }
        $probeWarning = null;
        $warningProbe = static function (
            int $_severity,
            string $message,
            string $_file,
            int $_line
        ) use (&$probeWarning): bool {
            $probeWarning = $message;
            return true;
        };
        set_error_handler($warningProbe);
        $probeRead = $handles === [] ? [] : [end($handles)];
        $probeWrite = [];
        $probeExcept = null;
        $probeSelected = $probeRead === []
            ? null
            : stream_select($probeRead, $probeWrite, $probeExcept, 0, 0);
        restore_error_handler();
        $supported = $probeSelected === false
            && is_string($probeWarning)
            && str_contains($probeWarning, 'FD_SETSIZE');

        $receipt = null;
        $failure = null;
        $outerWarnings = [];
        $handlerRestored = null;
        if ($supported) {
            $outer = static function (
                int $_severity,
                string $message,
                string $_file,
                int $_line
            ) use (&$outerWarnings): bool {
                $outerWarnings[$message] = true;
                return true;
            };
            set_error_handler($outer);
            try {
                $receipt = WPrism\WpCliChildProcess::capture(
                    'delayed-receipt ' . escapeshellarg($readyFile),
                    5,
                    65536,
                    65536
                );
            } catch (Throwable $exception) {
                $failure = $exception->getMessage();
            }
            $handlerRestored = wp_cli_child_handler_is_current($outer);
        }
        foreach ($handles as $handle) {
            fclose($handle);
        }
        wp_cli_child_write_probe_result($resultFile, [
            'supported' => $supported,
            'opened' => count($handles),
            'probe_warning' => $probeWarning,
            'receipt' => $receipt,
            'failure' => $failure,
            'outer_warnings' => array_keys($outerWarnings),
            'handler_restored' => $handlerRestored,
        ]);
    }

    wprism_check($priorMemoryLimit !== false && ini_get('memory_limit') === '32M', 'the transport suite runs under a 32 MiB parent ceiling');

    $scratch = sys_get_temp_dir() . '/wprism-cli-child-' . bin2hex(random_bytes(8));
    wprism_check(mkdir($scratch, 0700), 'the process fixture allocates a private scratch directory');
    register_shutdown_function(static function () use ($scratch): void {
        foreach (glob($scratch . '/*') ?: [] as $path) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
        @rmdir($scratch);
    });

    $child = $scratch . '/wp-cli.php';
    $childSource = <<<'PHP'
<?php
$all = array_slice($argv, 1);
$args = $all;
if (isset($args[0]) && str_starts_with($args[0], '@')) {
    array_shift($args);
}
while (isset($args[0]) && str_starts_with($args[0], '--')) {
    array_shift($args);
}
$mode = array_shift($args) ?? '';
if ($mode === 'argv') {
    echo json_encode($all, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit(0);
}
if ($mode === 'receipt') {
    echo "{\"format\":\"bounded-receipt/v1\",\"verified\":true}\n";
    exit(0);
}
if ($mode === 'guarded-start') {
    file_put_contents((string) ($args[0] ?? ''), 'plugin-ran');
    exit(0);
}
if ($mode === 'delayed-receipt') {
    $readyFile = (string) ($args[0] ?? '');
    file_put_contents($readyFile, 'ready');
    usleep(700000);
    echo "{\"format\":\"delayed-receipt/v1\"}\n";
    exit(0);
}
if ($mode === 'memory-limit') {
    echo ini_get('memory_limit');
    exit(0);
}
if ($mode === 'stdin-hash') {
    $input = stream_get_contents(STDIN);
    if (!is_string($input)) {
        exit(17);
    }
    echo json_encode([
        'bytes' => strlen($input),
        'sha256' => hash('sha256', $input),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit(0);
}
if ($mode === 'stdin-exit') {
    exit(0);
}
if ($mode === 'stdin-stderr-first') {
    for ($i = 0; $i < 32; $i++) {
        fwrite(STDERR, str_repeat('i', 4096));
    }
    $input = stream_get_contents(STDIN);
    if (!is_string($input)) {
        exit(18);
    }
    echo json_encode([
        'bytes' => strlen($input),
        'sha256' => hash('sha256', $input),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit(0);
}
if ($mode === 'stdin-output-over') {
    $input = stream_get_contents(STDIN);
    if (!is_string($input)) {
        exit(19);
    }
    for ($i = 0; $i < 8192; $i++) {
        echo $input;
    }
    exit(0);
}
if ($mode === 'stdin-term-ignore') {
    $pidFile = (string) ($args[0] ?? '');
    file_put_contents($pidFile, (string) getmypid());
    $process = proc_open(
        ['/bin/sh', '-c', 'trap "" TERM; while :; do sleep 1; done'],
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
        $pipes
    );
    exit(is_resource($process) ? proc_close($process) : 20);
}
if ($mode === 'stderr-first') {
    for ($i = 0; $i < 96; $i++) {
        fwrite(STDERR, str_repeat('w', 8192));
    }
    echo "{\"format\":\"after-stderr/v1\"}\n";
    exit(0);
}
if ($mode === 'large-stdout') {
    $chunk = str_repeat('credential-shaped-private-output-', 2048);
    for ($i = 0; $i < 1024; $i++) {
        echo $chunk;
    }
    exit(0);
}
if ($mode === 'large-stderr') {
    $chunk = str_repeat('credential-shaped-private-stderr-', 2048);
    for ($i = 0; $i < 1024; $i++) {
        fwrite(STDERR, $chunk);
    }
    exit(0);
}
if ($mode === 'interleaved-boundary') {
    for ($i = 0; $i < 128; $i++) {
        echo str_repeat('o', 4096);
        fwrite(STDERR, str_repeat('e', 4096));
    }
    exit(0);
}
if ($mode === 'interleaved-stdout-over') {
    for ($i = 0; $i < 128; $i++) {
        echo str_repeat('o', 4096);
        fwrite(STDERR, str_repeat('e', 4096));
    }
    echo 'x';
    exit(0);
}
if ($mode === 'warning') {
    echo "{\"format\":\"warning-receipt/v1\"}\n";
    fwrite(STDERR, "reviewed warning\n");
    exit(0);
}
if ($mode === 'exit') {
    echo "private failure detail\n";
    fwrite(STDERR, "private stderr detail\n");
    exit(7);
}
if ($mode === 'term-ignore') {
    $pidFile = (string) ($args[0] ?? '');
    $process = proc_open(
        ['/bin/sh', '-c', 'printf "%s" "$$" > "$1"; trap "" TERM; while :; do sleep 1; done', 'wprism-child', $pidFile],
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
        $pipes
    );
    exit(is_resource($process) ? proc_close($process) : 13);
}
if ($mode === 'chatty-term-ignore') {
    $pidFile = (string) ($args[0] ?? '');
    $process = proc_open(
        [
            '/bin/sh',
            '-c',
            'printf "%s" "$$" > "$1"; trap "" TERM; o=$(printf "%01024d" 0); e=$(printf "%01024d" 1); while :; do printf "%s" "$o"; printf "%s" "$e" >&2; sleep 0.01; done',
            'wprism-child',
            $pidFile,
        ],
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
        $pipes
    );
    exit(is_resource($process) ? proc_close($process) : 14);
}
if ($mode === 'closed-pipes-hang') {
    $pidFile = (string) ($args[0] ?? '');
    $process = proc_open(
        ['/bin/sh', '-c', 'printf "%s" "$$" > "$1"; trap "" TERM; exec 1>&- 2>&-; while :; do sleep 1; done', 'wprism-child', $pidFile],
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
        $pipes
    );
    exit(is_resource($process) ? proc_close($process) : 15);
}
if ($mode === 'parent-death-fence') {
    [$childPidFile, $readyFile, $markerFile] = array_pad($args, 3, '');
    $process = proc_open(
        [
            '/bin/sh',
            '-c',
            'trap "" TERM HUP INT; printf "%s" "$$" > "$1"; printf "%s" ready > "$2"; sleep 1.5; printf "%s" escaped-mutation > "$3"; while :; do sleep 1; done',
            'wprism-child',
            $childPidFile,
            $readyFile,
            $markerFile,
        ],
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
        $pipes
    );
    exit(is_resource($process) ? proc_close($process) : 16);
}
if ($mode === 'parent-death-after-leader') {
    [$childPidFile, $readyFile, $markerFile, $groupPidFile] = array_pad($args, 4, '');
    $groupPid = function_exists('posix_getpgid') ? posix_getpgid(0) : false;
    if (!is_int($groupPid) || file_put_contents($groupPidFile, (string) $groupPid) === false) {
        exit(21);
    }
    $launcher = proc_open(
        [
            '/bin/sh',
            '-c',
            '(trap "" TERM HUP INT; sleep 1.5; printf "%s" escaped-mutation > "$3"; while :; do sleep 1; done) & printf "%s" "$!" > "$1"; printf "%s" ready > "$2"',
            'wprism-child',
            $childPidFile,
            $readyFile,
            $markerFile,
        ],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ],
        $pipes
    );
    if (!is_resource($launcher) || proc_close($launcher) !== 0) {
        exit(22);
    }
    echo "{\"format\":\"leader-exited/v1\"}\n";
    exit(0);
}
if ($mode === 'leader-exit-chatty-descendant') {
    [$childPidFile, $markerFile] = array_pad($args, 2, '');
    $launcher = proc_open(
        [
            '/bin/sh',
            '-c',
            '(trap "" TERM HUP INT; printf "%s" "$$" > "$1"; chunk=$(printf "%01024d" 0); while :; do printf "%s" "$chunk"; done & writer=$!; sleep 1.5; printf "%s" escaped-mutation > "$2"; wait "$writer") &',
            'wprism-child',
            $childPidFile,
            $markerFile,
        ],
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
        $pipes
    );
    exit(is_resource($launcher) ? proc_close($launcher) : 23);
}
if ($mode === 'fork-descendant-timeout') {
    [$parentPidFile, $childPidFile, $markerFile] = array_pad($args, 3, '');
    file_put_contents($parentPidFile, (string) getmypid());
    $process = proc_open(
        [
            '/bin/sh',
            '-c',
            'printf "%s" "$$" > "$1"; trap "" TERM; exec 1>&- 2>&-; sleep 2.5; printf "%s" descendant-survived > "$2"; while :; do sleep 1; done',
            'wprism-child',
            $childPidFile,
            $markerFile,
        ],
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
        $pipes
    );
    exit(is_resource($process) ? proc_close($process) : 11);
}
if ($mode === 'fork-after-success') {
    [$childPidFile, $markerFile] = array_pad($args, 2, '');
    $launcher = proc_open(
        [
            '/bin/sh',
            '-c',
            '(trap "" TERM; exec 1>&- 2>&-; sleep 1.5; printf "%s" detached-mutation > "$2"; while :; do sleep 1; done) & printf "%s" "$!" > "$1"',
            'wprism-child',
            $childPidFile,
            $markerFile,
        ],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
        $pipes
    );
    if (!is_resource($launcher) || proc_close($launcher) !== 0) {
        exit(12);
    }
    echo "{\"format\":\"false-success/v1\"}\n";
    exit(0);
}
fwrite(STDERR, "unknown test mode\n");
exit(9);
PHP;
    wprism_check_same(
        strlen($childSource),
        file_put_contents($child, $childSource),
        'the process fixture writes its exact child source'
    );
    chmod($child, 0700);

    $savedArgv = $GLOBALS['argv'];
    $GLOBALS['argv'] = [$child, '--path=/ignored-by-fixture'];
    $GLOBALS['wp_cli_child_runtime_config'] = [
        'path' => '/srv/site path',
        'debug' => true,
        'context' => 'admin',
    ];
    $GLOBALS['wp_cli_child_alias'] = 'target';
    $GLOBALS['wp_cli_child_php_binary'] = PHP_BINARY;
    $GLOBALS['wp_cli_child_commands'] = [];
    $GLOBALS['wp_cli_child_proc_checks'] = [];

    $argvReceipt = WPrism\WpCliChildProcess::capture('argv payload', 5, 262144, 65536);
    wprism_check_same(0, $argvReceipt['return_code'], 'the bounded child preserves a successful exit code');
    wprism_check_same('', $argvReceipt['stderr'], 'the bounded child preserves exact empty stderr');
    wprism_check_same(
        [
            '@target',
            '--path=/srv/site path',
            '--debug',
            '--context=admin',
            'argv',
            'payload',
        ],
        json_decode($argvReceipt['stdout'], true, 16, JSON_THROW_ON_ERROR),
        'the helper preserves official runtime-config and alias propagation before the fixed command'
    );
    wprism_check_same(
        ['WPrism bounded child launch'],
        $GLOBALS['wp_cli_child_proc_checks'],
        'the helper preserves WP-CLI process-availability preflight'
    );
    $memoryReceipt = WPrism\WpCliChildProcess::capture('memory-limit', 5, 65536, 65536);
    wprism_check_same(
        ['return_code' => 0, 'stdout' => '512M', 'stderr' => ''],
        $memoryReceipt,
        'every descendant receives the explicit finite 512 MiB bootstrap ceiling instead of PHP CLI default memory'
    );
    $memoryLaunch = (string) end($GLOBALS['wp_cli_child_commands']);
    $memoryInner = wp_cli_child_inner_command($memoryLaunch);
    wprism_check(
        substr_count($memoryLaunch, 'memory_limit=512M') === 2
            && str_contains($memoryLaunch, " -d 'memory_limit=512M' -r ")
            && str_starts_with($memoryInner, escapeshellarg(PHP_BINARY) . " -d 'memory_limit=512M' "),
        'both the session wrapper and owned WP-CLI command carry the same exact finite memory flag'
    );
    wprism_check_same(
        0,
        WPrism\WpCliChildProcess::capture_until(
            'receipt',
            hrtime(true) + 5000000000,
            65536,
            65536
        )['return_code'],
        'the absolute monotonic API carries a successful command through the same bounded transport'
    );
    $delayedStartMarker = $scratch . '/absolute-deadline-start.marker';
    $GLOBALS['wp_cli_child_proc_open_delay_microseconds'] = 300000;
    $delayedStartAt = hrtime(true);
    try {
        $delayedStartFailure = wp_cli_child_refuses(
            static fn() => WPrism\WpCliChildProcess::capture_until(
                'guarded-start ' . escapeshellarg($delayedStartMarker),
                hrtime(true) + 100000000,
                65536,
                65536
            ),
            'wall-clock limit',
            'proc_open time consumes the caller deadline while plugin code remains gated'
        );
    } finally {
        unset($GLOBALS['wp_cli_child_proc_open_delay_microseconds']);
    }
    wprism_check_same(
        'wprism: bounded WP-CLI child exceeded its wall-clock limit',
        $delayedStartFailure->getMessage(),
        'an expired startup deadline keeps the fixed data-free wall-clock refusal'
    );
    wprism_check(
        hrtime(true) - $delayedStartAt >= 250000000
            && hrtime(true) - $delayedStartAt < 3000000000,
        'late proc_open return is terminated and reaped within the bounded cleanup allowance'
    );
    usleep(200000);
    wprism_check(
        !file_exists($delayedStartMarker),
        'the child start token is never released after the absolute deadline expires'
    );
    wprism_check(
        str_starts_with((string) ($GLOBALS['wp_cli_child_commands'][0] ?? ''), 'exec ')
            && str_contains((string) $GLOBALS['wp_cli_child_commands'][0], escapeshellarg(PHP_BINARY))
            && str_contains((string) $GLOBALS['wp_cli_child_commands'][0], escapeshellarg($child)),
        'the launch command replaces the shell with the exact reviewed PHP/script boundary'
    );

    $privateInput = "merchant-secret\0東京🚀\n" . str_repeat('bounded-input-', 32768);
    $inputReceipt = WPrism\WpCliChildProcess::capture_with_input(
        'stdin-hash',
        $privateInput,
        5,
        65536,
        65536
    );
    wprism_check_same(0, $inputReceipt['return_code'], 'the bounded stdin child preserves a successful exit code');
    wprism_check_same('', $inputReceipt['stderr'], 'bounded stdin preserves exact empty stderr');
    wprism_check_same(
        ['bytes' => strlen($privateInput), 'sha256' => hash('sha256', $privateInput)],
        json_decode($inputReceipt['stdout'], true, 4, JSON_THROW_ON_ERROR),
        'binary and UTF-8 private input crosses the concurrent pipe byte-exactly'
    );
    $inputLaunch = (string) end($GLOBALS['wp_cli_child_commands']);
    wprism_check(
        !str_contains($inputLaunch, 'merchant-secret') && !str_contains($inputLaunch, '東京'),
        'private stdin bytes never enter the process command line'
    );
    unset($privateInput);

    $boundaryInput = str_repeat("b\0", 4194304);
    $boundaryReceipt = WPrism\WpCliChildProcess::capture_with_input(
        'stdin-hash',
        $boundaryInput,
        10,
        65536,
        65536
    );
    wprism_check_same(
        ['bytes' => 8388608, 'sha256' => hash('sha256', $boundaryInput)],
        json_decode($boundaryReceipt['stdout'], true, 4, JSON_THROW_ON_ERROR),
        'binary private input is admitted byte-exactly at the hard 8 MiB boundary'
    );
    unset($boundaryInput);

    $stderrFirstInput = "concurrent-secret\0東京\n" . str_repeat('input-', 32768);
    $stderrFirstInputReceipt = WPrism\WpCliChildProcess::capture_with_input(
        'stdin-stderr-first',
        $stderrFirstInput,
        5,
        65536,
        262144
    );
    wprism_check_same(32 * 4096, strlen($stderrFirstInputReceipt['stderr']), 'stdin transport drains pipe-filling stderr before the child reads input');
    wprism_check_same(
        ['bytes' => strlen($stderrFirstInput), 'sha256' => hash('sha256', $stderrFirstInput)],
        json_decode($stderrFirstInputReceipt['stdout'], true, 4, JSON_THROW_ON_ERROR),
        'pipe-filling stderr and private stdin are multiplexed without deadlock or byte loss'
    );
    unset($stderrFirstInput);

    $earlyExitFailure = wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture_with_input(
            'stdin-exit',
            str_repeat('e', 1048576),
            5,
            65536,
            65536
        ),
        'input transport failed',
        'a launched child that exits before draining a pipe-capacity input refuses instead of returning false success'
    );
    wprism_check_same(
        'wprism: bounded WP-CLI child input transport failed',
        $earlyExitFailure->getMessage(),
        'early stdin closure returns one fixed value-free transport refusal'
    );

    $privateEcho = 'credential-shaped-stdin-secret';
    $inputOutputFailure = wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture_with_input(
            'stdin-output-over',
            $privateEcho,
            5,
            65536,
            65536
        ),
        'output exceeded',
        'a child echoing private stdin beyond its output cap is terminated and reaped'
    );
    wprism_check(
        !str_contains($inputOutputFailure->getMessage(), $privateEcho),
        'an input-plus-output-cap refusal never exposes caller-owned stdin or child output'
    );

    $stdinTimeoutPidFile = $scratch . '/stdin-term-ignore.pid';
    $stdinTimeoutFailure = wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture_with_input(
            'stdin-term-ignore ' . escapeshellarg($stdinTimeoutPidFile),
            str_repeat('t', 1048576),
            1,
            65536,
            65536
        ),
        'wall-clock limit',
        'a TERM-ignoring child that never reads stdin reaches the fixed wall-clock refusal'
    );
    wprism_check_same(
        'wprism: bounded WP-CLI child exceeded its wall-clock limit',
        $stdinTimeoutFailure->getMessage(),
        'blocked-stdin timeout preserves the original value-free refusal after group cleanup'
    );
    $stdinTimeoutPid = is_file($stdinTimeoutPidFile) ? (int) file_get_contents($stdinTimeoutPidFile) : 0;
    wprism_check(
        $stdinTimeoutPid > 1 && wp_cli_child_process_is_inert($stdinTimeoutPid),
        'the child that never read stdin cannot execute after timeout returns'
    );

    $GLOBALS['wp_cli_child_alias'] = 'target';
    WPrism\WpCliChildProcess::capture('@explicit receipt', 5, 262144, 65536);
    $explicitCommand = (string) end($GLOBALS['wp_cli_child_commands']);
    wprism_check(
        !str_contains($explicitCommand, '@target @explicit')
            && str_contains($explicitCommand, ' @explicit receipt'),
        'an explicit WP-CLI alias is not prefixed by the inherited runner alias'
    );
    WPrism\WpCliChildProcess::capture('--path', 5, 262144, 65536);
    $exactOverride = (string) end($GLOBALS['wp_cli_child_commands']);
    $exactOverrideInner = wp_cli_child_inner_command($exactOverride);
    wprism_check(
        !str_contains($exactOverrideInner, "--path='/srv/site path'")
            && str_ends_with($exactOverrideInner, ' --path'),
        'an exact leading runtime flag follows WP-CLI override suppression semantics'
    );
    WPrism\WpCliChildProcess::capture('--pathology', 5, 262144, 65536);
    $prefixNearMiss = (string) end($GLOBALS['wp_cli_child_commands']);
    $prefixNearMissInner = wp_cli_child_inner_command($prefixNearMiss);
    wprism_check(
        str_contains($prefixNearMissInner, "--path='/srv/site path'")
            && str_ends_with($prefixNearMissInner, ' --pathology'),
        'a runtime-key prefix near miss does not suppress the inherited runtime value'
    );

    $stderrFirst = WPrism\WpCliChildProcess::capture('stderr-first', 5, 131072, 800000);
    wprism_check_same(0, $stderrFirst['return_code'], 'stderr-first output larger than a pipe does not deadlock');
    wprism_check_same(96 * 8192, strlen($stderrFirst['stderr']), 'stderr-first capture drains every bounded warning byte');
    wprism_check_same(
        "{\"format\":\"after-stderr/v1\"}\n",
        $stderrFirst['stdout'],
        'stdout remains available after the child fills stderr first'
    );

    $warning = WPrism\WpCliChildProcess::capture('warning', 5, 131072, 131072);
    wprism_check_same(
        ['return_code' => 0, 'stdout' => "{\"format\":\"warning-receipt/v1\"}\n", 'stderr' => "reviewed warning\n"],
        $warning,
        'the transport preserves exact successful stdout/stderr for caller-owned warning policy'
    );
    $nonzero = WPrism\WpCliChildProcess::capture('exit', 5, 131072, 131072);
    wprism_check_same(7, $nonzero['return_code'], 'the transport returns a nonzero child exit for caller-owned failure policy');
    wprism_check_same("private failure detail\n", $nonzero['stdout'], 'bounded nonzero stdout remains private caller data');
    wprism_check_same("private stderr detail\n", $nonzero['stderr'], 'bounded nonzero stderr remains private caller data');

    $receipt = WPrism\WpCliChildProcess::capture('receipt', 5, 131072, 131072);
    wprism_check_same(
        0,
        $receipt['return_code'],
        'a quick successful leader is reaped before the empty-group proof without a false descendant refusal'
    );
    wprism_check_same(
        ['format' => 'bounded-receipt/v1', 'verified' => true],
        json_decode(trim($receipt['stdout']), true, 4, JSON_THROW_ON_ERROR),
        'a tiny canonical receipt crosses the bounded transport byte-exactly'
    );

    $signalFunctions = [
        'pcntl_async_signals',
        'pcntl_fork',
        'pcntl_signal',
        'pcntl_waitpid',
        'pcntl_wifexited',
        'pcntl_wexitstatus',
        'posix_kill',
    ];
    $signalProbeSupported = defined('SIGUSR1') && defined('SIGKILL') && defined('WNOHANG');
    foreach ($signalFunctions as $signalFunction) {
        $signalProbeSupported = $signalProbeSupported && function_exists($signalFunction);
    }
    if (!$signalProbeSupported) {
        echo "note: pcntl signal primitives unavailable; interrupted stream_select transport proof skipped\n";
        wprism_check(true, 'the EINTR proof has an explicit portable skip outside the pcntl test profile');
    } else {
        $signalReadyFile = $scratch . '/eintr.ready';
        $signalResultFile = $scratch . '/eintr.json';
        $signalProbe = wp_cli_child_run_probe([
            '--eintr-probe',
            $child,
            $signalReadyFile,
            $signalResultFile,
        ]);
        $signalRaw = @file_get_contents($signalResultFile);
        $signalResult = is_string($signalRaw) ? json_decode($signalRaw, true) : null;
        wprism_check_same(
            ['exit' => 0, 'stdout' => '', 'stderr' => ''],
            $signalProbe,
            'the isolated non-restarting signal worker exits without PHP diagnostics'
        );
        wprism_check_same(
            [
                'return_code' => 0,
                'stdout' => "{\"format\":\"delayed-receipt/v1\"}\n",
                'stderr' => '',
            ],
            is_array($signalResult) ? ($signalResult['receipt'] ?? null) : null,
            'a storm of non-restarting signals cannot turn valid blocked child pipes into transport failure'
        );
        wprism_check_same(
            [null, true, []],
            is_array($signalResult)
                ? [
                    $signalResult['failure'] ?? null,
                    $signalResult['signaler_ok'] ?? null,
                    $signalResult['outer_warnings'] ?? null,
                ]
                : null,
            'only numeric EINTR is consumed while the synchronized signaler exits cleanly'
        );
        wprism_check_same(
            true,
            is_array($signalResult) ? ($signalResult['handler_restored'] ?? null) : null,
            'the EINTR path restores the exact pre-existing error handler after every interrupted wait'
        );
    }

    $fdReadyFile = $scratch . '/fd-select.ready';
    $fdResultFile = $scratch . '/fd-select.json';
    $fdProbe = wp_cli_child_run_probe([
        '--non-eintr-probe',
        $child,
        $fdReadyFile,
        $fdResultFile,
    ]);
    $fdRaw = @file_get_contents($fdResultFile);
    $fdResult = is_string($fdRaw) ? json_decode($fdRaw, true) : null;
    wprism_check_same(
        ['exit' => 0, 'stdout' => '', 'stderr' => ''],
        $fdProbe,
        'the isolated high-descriptor worker exits without PHP diagnostics'
    );
    if (!is_array($fdResult) || ($fdResult['supported'] ?? false) !== true) {
        echo 'note: this PHP/ulimit profile did not expose an FD_SETSIZE stream_select refusal after '
            . (is_array($fdResult) ? (int) ($fdResult['opened'] ?? 0) : 0)
            . " descriptors; non-EINTR descriptor proof skipped\n";
        wprism_check(
            is_array($fdResult),
            'the non-EINTR proof has an explicit portable skip when descriptor pressure cannot reach FD_SETSIZE'
        );
    } else {
        wprism_check_same(
            [null, 'wprism: bounded WP-CLI child transport failed'],
            [$fdResult['receipt'] ?? null, $fdResult['failure'] ?? null],
            'a non-EINTR stream_select false retains the immediate fixed transport refusal'
        );
        $fdDiagnosticDelegated = false;
        foreach (($fdResult['outer_warnings'] ?? []) as $outerWarning) {
            $fdDiagnosticDelegated = $fdDiagnosticDelegated
                || (is_string($outerWarning) && str_contains($outerWarning, 'FD_SETSIZE'));
        }
        wprism_check(
            $fdDiagnosticDelegated,
            'non-EINTR transport diagnostics are delegated to the pre-existing error handler'
        );
        wprism_check_same(
            true,
            $fdResult['handler_restored'] ?? null,
            'the non-EINTR refusal restores the exact pre-existing error handler'
        );
    }

    wprism_check_same(
        900,
        WPrism\WpCliChildProcess::MAX_TIMEOUT_SECONDS,
        'callers share the transport-owned maximum instead of duplicating a looser timeout ceiling'
    );
    wprism_check_same(
        0,
        WPrism\WpCliChildProcess::capture(
            'receipt',
            WPrism\WpCliChildProcess::MAX_TIMEOUT_SECONDS,
            131072,
            131072
        )['return_code'],
        'the exact longest reviewed caller budget is admitted by the helper'
    );

    $largeFailure = null;
    try {
        WPrism\WpCliChildProcess::capture('large-stdout', 10, 65536, 65536);
    } catch (RuntimeException $failure) {
        $largeFailure = $failure;
    }
    wprism_check(
        $largeFailure instanceof RuntimeException
            && $largeFailure->getMessage() === 'wprism: bounded WP-CLI child output exceeded its fixed byte limit'
            && !str_contains($largeFailure->getMessage(), 'credential-shaped'),
        'huge stdout preserves the original bounded refusal without cleanup errors or arbitrary child bytes'
    );
    wprism_check(
        memory_get_peak_usage(true) < 24 * 1024 * 1024,
        'the 32 MiB suite stays well below its memory ceiling while refusing tens of MiB of child output'
    );
    $largeStderrFailure = null;
    try {
        WPrism\WpCliChildProcess::capture('large-stderr', 10, 65536, 65536);
    } catch (RuntimeException $failure) {
        $largeStderrFailure = $failure;
    }
    wprism_check(
        $largeStderrFailure instanceof RuntimeException
            && $largeStderrFailure->getMessage() === 'wprism: bounded WP-CLI child output exceeded its fixed byte limit'
            && !str_contains($largeStderrFailure->getMessage(), 'credential-shaped'),
        'huge stderr preserves the bounded refusal without leaking boot or credential-shaped bytes'
    );

    $interleaved = WPrism\WpCliChildProcess::capture('interleaved-boundary', 10, 524288, 524288);
    wprism_check_same(524288, strlen($interleaved['stdout']), 'interleaved stdout is accepted at its exact individual boundary');
    wprism_check_same(524288, strlen($interleaved['stderr']), 'interleaved stderr is accepted at its exact individual boundary');
    wprism_check_same(
        1048576,
        strlen($interleaved['stdout']) + strlen($interleaved['stderr']),
        'interleaved output is accepted at the exact aggregate transport boundary'
    );
    $interleavedFailure = null;
    try {
        WPrism\WpCliChildProcess::capture('interleaved-stdout-over', 10, 524288, 524288);
    } catch (RuntimeException $failure) {
        $interleavedFailure = $failure;
    }
    wprism_check_same(
        'wprism: bounded WP-CLI child output exceeded its fixed byte limit',
        $interleavedFailure?->getMessage(),
        'one extra interleaved stdout byte refuses at the individual boundary even when stderr is exact'
    );

    $pidFile = $scratch . '/term-ignore.pid';
    $started = microtime(true);
    $timeoutFailure = wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture(
            'term-ignore ' . escapeshellarg($pidFile),
            1,
            65536,
            65536
        ),
        'wall-clock limit',
        'a silent TERM-ignoring child reaches the fixed wall-clock refusal'
    );
    wprism_check_same(
        'wprism: bounded WP-CLI child exceeded its wall-clock limit',
        $timeoutFailure->getMessage(),
        'timeout preserves the original bounded refusal after TERM/KILL cleanup'
    );
    $elapsed = microtime(true) - $started;
    wprism_check($elapsed >= 1.0 && $elapsed < 4.0, 'TERM grace escalates to SIGKILL within the bounded wall clock');
    $pid = is_file($pidFile) ? (int) file_get_contents($pidFile) : 0;
    wprism_check($pid > 1, 'the TERM-ignoring fixture published its child PID before timeout');
    wprism_check(
        $pid > 1 && function_exists('posix_kill') && wp_cli_child_process_is_inert($pid),
        'the SIGKILL path leaves the exact TERM-ignoring child unable to execute before returning'
    );
    $chattyPidFile = $scratch . '/chatty-term-ignore.pid';
    $chattyStarted = microtime(true);
    $chattyFailure = wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture(
            'chatty-term-ignore ' . escapeshellarg($chattyPidFile),
            1,
            524288,
            524288
        ),
        'wall-clock limit',
        'continuously readable stdout/stderr cannot postpone the monotonic timeout'
    );
    $chattyElapsed = microtime(true) - $chattyStarted;
    wprism_check_same(
        'wprism: bounded WP-CLI child exceeded its wall-clock limit',
        $chattyFailure->getMessage(),
        'chatty TERM-ignore cleanup preserves the original timeout refusal without captured output'
    );
    wprism_check(
        $chattyElapsed >= 1.0 && $chattyElapsed < 4.0,
        'chatty TERM-ignore output reaches SIGKILL/reap within the same bounded wall clock'
    );
    $chattyPid = is_file($chattyPidFile) ? (int) file_get_contents($chattyPidFile) : 0;
    wprism_check(
        $chattyPid > 1 && function_exists('posix_kill') && wp_cli_child_process_is_inert($chattyPid),
        'the exact continuously chatty child cannot execute after timeout returns'
    );

    $closedPidFile = $scratch . '/closed-pipes.pid';
    $closedStarted = microtime(true);
    $closedFailure = wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture(
            'closed-pipes-hang ' . escapeshellarg($closedPidFile),
            1,
            65536,
            65536
        ),
        'wall-clock limit',
        'closing both output pipes cannot make a still-running leader reach blocking proc_close'
    );
    wprism_check_same(
        'wprism: bounded WP-CLI child exceeded its wall-clock limit',
        $closedFailure->getMessage(),
        'closed-pipe cleanup preserves the original wall-clock refusal'
    );
    wprism_check(
        microtime(true) - $closedStarted >= 1.0 && microtime(true) - $closedStarted < 4.0,
        'a pipe-less TERM-ignoring leader is group-killed and reaped within the fixed boundary'
    );
    $closedPid = is_file($closedPidFile) ? (int) file_get_contents($closedPidFile) : 0;
    wprism_check(
        $closedPid > 1 && wp_cli_child_process_is_inert($closedPid),
        'the leader that closed both output pipes cannot survive the helper refusal'
    );

    $forkParentPidFile = $scratch . '/fork-timeout-parent.pid';
    $forkChildPidFile = $scratch . '/fork-timeout-child.pid';
    $forkMarkerFile = $scratch . '/fork-timeout.marker';
    $forkFailure = wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture(
            'fork-descendant-timeout '
                . escapeshellarg($forkParentPidFile) . ' '
                . escapeshellarg($forkChildPidFile) . ' '
                . escapeshellarg($forkMarkerFile),
            1,
            65536,
            65536
        ),
        'wall-clock limit',
        'a TERM-ignoring fork tree reaches the bounded group-wide timeout refusal'
    );
    wprism_check_same(
        'wprism: bounded WP-CLI child exceeded its wall-clock limit',
        $forkFailure->getMessage(),
        'fork-tree cleanup preserves the original timeout rather than descendant output'
    );
    $forkParentPid = is_file($forkParentPidFile) ? (int) file_get_contents($forkParentPidFile) : 0;
    $forkChildPid = is_file($forkChildPidFile) ? (int) file_get_contents($forkChildPidFile) : 0;
    wprism_check(
        $forkParentPid > 1
            && $forkChildPid > 1
            && wp_cli_child_process_is_inert($forkParentPid)
            && wp_cli_child_process_is_inert($forkChildPid),
        'SIGKILL leaves both the direct child and its TERM-ignoring descendant unable to execute before returning'
    );
    usleep(1700000);
    wprism_check(!file_exists($forkMarkerFile), 'a descendant cannot mutate target state after timeout was reported');

    $leaderChattyPidFile = $scratch . '/leader-exit-chatty-child.pid';
    $leaderChattyMarkerFile = $scratch . '/leader-exit-chatty.marker';
    $leaderChattyStarted = hrtime(true);
    $leaderChattyFailure = wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture(
            'leader-exit-chatty-descendant '
                . escapeshellarg($leaderChattyPidFile) . ' '
                . escapeshellarg($leaderChattyMarkerFile),
            5,
            65536,
            65536
        ),
        'output exceeded',
        'a leader-exited continuously-writing inherited pipe reaches its fixed output refusal'
    );
    wprism_check_same(
        'wprism: bounded WP-CLI child output exceeded its fixed byte limit',
        $leaderChattyFailure->getMessage(),
        'post-leader drain retains the original fixed output classification'
    );
    $leaderChattyPid = is_file($leaderChattyPidFile)
        ? (int) file_get_contents($leaderChattyPidFile)
        : 0;
    wprism_check(
        hrtime(true) - $leaderChattyStarted < 3000000000
            && $leaderChattyPid > 1
            && wp_cli_child_process_is_inert($leaderChattyPid),
        'bounded drain interleaves group escalation and reaps the continuously-writing descendant'
    );
    usleep(1700000);
    wprism_check(
        !file_exists($leaderChattyMarkerFile),
        'the inherited-pipe descendant cannot perform its later mutation after refusal'
    );

    $deathChildPidFile = $scratch . '/parent-death-child.pid';
    $deathReadyFile = $scratch . '/parent-death.ready';
    $deathMarkerFile = $scratch . '/parent-death.marker';
    $deathProbe = proc_open(
        [
            PHP_BINARY,
            __FILE__,
            '--parent-death-probe',
            $child,
            'parent-death-fence',
            $deathChildPidFile,
            $deathReadyFile,
            $deathMarkerFile,
        ],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $deathPipes
    );
    wprism_check(is_resource($deathProbe), 'the isolated parent-death worker starts');
    $deathProbePid = 0;
    $deathReady = false;
    if (is_resource($deathProbe)) {
        $deathStatus = proc_get_status($deathProbe);
        $deathProbePid = is_array($deathStatus) && is_int($deathStatus['pid'] ?? null)
            ? $deathStatus['pid']
            : 0;
        $deathReadyDeadline = hrtime(true) + 3000000000;
        do {
            $deathReady = is_file($deathReadyFile);
            if ($deathReady) {
                break;
            }
            usleep(1000);
        } while (hrtime(true) < $deathReadyDeadline);
    }
    wprism_check(
        $deathProbePid > 1 && $deathReady,
        'the TERM-ignoring mutation descendant is running before its capture parent dies'
    );
    $deathSignal = $deathProbePid > 1 && @posix_kill($deathProbePid, 9);
    wprism_check($deathSignal, 'SIGKILL closes the sole parent liveness writer without running PHP cleanup');
    $deathChildPid = is_file($deathChildPidFile) ? (int) file_get_contents($deathChildPidFile) : 0;
    $deathFenceDeadline = hrtime(true) + 3000000000;
    $deathChildInert = false;
    do {
        $deathChildInert = $deathChildPid > 1 && wp_cli_child_process_is_inert($deathChildPid);
        if ($deathChildInert) {
            break;
        }
        usleep(5000);
    } while (hrtime(true) < $deathFenceDeadline);
    wprism_check(
        $deathChildInert,
        'kernel EOF makes the watchdog TERM/KILL the owned mutation group after parent SIGKILL'
    );
    usleep(1600000);
    wprism_check(
        !file_exists($deathMarkerFile),
        'a child cannot mutate target state after abrupt parent death releases parent-owned locks'
    );
    if (!$deathChildInert && $deathChildPid > 1) {
        @posix_kill($deathChildPid, SIGKILL);
    }
    if (is_resource($deathProbe)) {
        foreach ([1, 2] as $index) {
            if (isset($deathPipes[$index]) && is_resource($deathPipes[$index])) {
                @stream_set_blocking($deathPipes[$index], false);
                @stream_get_contents($deathPipes[$index]);
                @fclose($deathPipes[$index]);
            }
        }
        @proc_close($deathProbe);
    }

    $settlementChildPidFile = $scratch . '/parent-death-settlement-child.pid';
    $settlementReadyFile = $scratch . '/parent-death-settlement.ready';
    $settlementMarkerFile = $scratch . '/parent-death-settlement.marker';
    $settlementGroupPidFile = $scratch . '/parent-death-settlement-group.pid';
    $settlementProbe = proc_open(
        [
            PHP_BINARY,
            __FILE__,
            '--parent-death-probe',
            $child,
            'parent-death-after-leader',
            $settlementChildPidFile,
            $settlementReadyFile,
            $settlementMarkerFile,
            $settlementGroupPidFile,
        ],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $settlementPipes
    );
    wprism_check(is_resource($settlementProbe), 'the post-leader parent-death worker starts');
    $settlementProbePid = 0;
    $settlementChildPid = 0;
    $settlementLeaderPid = 0;
    $settlementWindow = false;
    if (is_resource($settlementProbe)) {
        $settlementStatus = proc_get_status($settlementProbe);
        $settlementProbePid = is_array($settlementStatus) && is_int($settlementStatus['pid'] ?? null)
            ? $settlementStatus['pid']
            : 0;
        $settlementDeadline = hrtime(true) + 3000000000;
        do {
            $settlementChildPid = is_file($settlementChildPidFile)
                ? (int) file_get_contents($settlementChildPidFile)
                : 0;
            $settlementLeaderPid = is_file($settlementGroupPidFile)
                ? (int) file_get_contents($settlementGroupPidFile)
                : 0;
            $settlementWindow = is_file($settlementReadyFile)
                && $settlementChildPid > 1
                && !wp_cli_child_process_is_inert($settlementChildPid)
                && $settlementLeaderPid > 1
                && wp_cli_child_process_is_inert($settlementLeaderPid);
            if ($settlementWindow) {
                break;
            }
            usleep(1000);
        } while (hrtime(true) < $settlementDeadline);
    }
    wprism_check(
        $settlementWindow,
        'the group leader is reaped while its TERM-ignoring descendant remains inside the settlement window'
    );
    $settlementSignal = $settlementProbePid > 1 && @posix_kill($settlementProbePid, 9);
    wprism_check(
        $settlementSignal,
        'the capture parent can die after leader exit but before its descendant settlement proof'
    );
    $settlementFenceDeadline = hrtime(true) + 3000000000;
    $settlementChildInert = false;
    do {
        $settlementChildInert = $settlementChildPid > 1
            && wp_cli_child_process_is_inert($settlementChildPid);
        if ($settlementChildInert) {
            break;
        }
        usleep(5000);
    } while (hrtime(true) < $settlementFenceDeadline);
    wprism_check(
        $settlementChildInert,
        'the parent-owned watchdog remains armed after leader exit and kills the residual group on EOF'
    );
    usleep(1600000);
    wprism_check(
        !file_exists($settlementMarkerFile),
        'the post-leader descendant cannot mutate after abrupt parent death'
    );
    if (!$settlementChildInert && $settlementChildPid > 1) {
        @posix_kill($settlementChildPid, SIGKILL);
    }
    if (is_resource($settlementProbe)) {
        foreach ([1, 2] as $index) {
            if (isset($settlementPipes[$index]) && is_resource($settlementPipes[$index])) {
                @stream_set_blocking($settlementPipes[$index], false);
                @stream_get_contents($settlementPipes[$index]);
                @fclose($settlementPipes[$index]);
            }
        }
        @proc_close($settlementProbe);
    }

    $successChildPidFile = $scratch . '/fork-success-child.pid';
    $successMarkerFile = $scratch . '/fork-success.marker';
    $successFailure = wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture(
            'fork-after-success '
                . escapeshellarg($successChildPidFile) . ' '
                . escapeshellarg($successMarkerFile),
            5,
            65536,
            65536
        ),
        'process group running after exit',
        'a zero-exit leader cannot return success while its owned descendant remains alive'
    );
    wprism_check_same(
        'wprism: bounded WP-CLI child left its process group running after exit',
        $successFailure->getMessage(),
        'the apparent-success descendant path has one fixed value-free refusal'
    );
    $successChildPid = is_file($successChildPidFile) ? (int) file_get_contents($successChildPidFile) : 0;
    wprism_check(
        $successChildPid > 1 && wp_cli_child_process_is_inert($successChildPid),
        'the owned group is empty before an apparent-success refusal returns'
    );
    usleep(1700000);
    wprism_check(!file_exists($successMarkerFile), 'an apparent-success descendant cannot mutate after refusal');

    $selectedBinary = $scratch . '/php-without-session-profile';
    $selectedSource = '#!/bin/sh' . "\n"
        . 'exec ' . escapeshellarg(PHP_BINARY)
        . ' -d disable_functions=passthru,posix_setsid "$@"' . "\n";
    wprism_check_same(
        strlen($selectedSource),
        file_put_contents($selectedBinary, $selectedSource),
        'the selected-binary profile fixture writes exact wrapper bytes'
    );
    chmod($selectedBinary, 0700);
    $GLOBALS['wp_cli_child_php_binary'] = $selectedBinary;
    $selectedReceipt = WPrism\WpCliChildProcess::capture('receipt', 5, 65536, 65536);
    wprism_check_same(
        [
            'return_code' => 125,
            'stdout' => '',
            'stderr' => "wprism-child-process-profile-unavailable\n",
        ],
        $selectedReceipt,
        'the selected PHP binary independently refuses before launching WP-CLI when session primitives are absent'
    );
    $GLOBALS['wp_cli_child_php_binary'] = PHP_BINARY;

    $withoutPcntlExec = $scratch . '/php-without-pcntl-exec';
    $withoutPcntlExecSource = '#!/bin/sh' . "\n"
        . 'exec ' . escapeshellarg(PHP_BINARY)
        . ' -d disable_functions=pcntl_async_signals,pcntl_exec,pcntl_fork,pcntl_signal,pcntl_waitpid "$@"' . "\n";
    wprism_check_same(
        strlen($withoutPcntlExecSource),
        file_put_contents($withoutPcntlExec, $withoutPcntlExecSource),
        'the no-pcntl-exec selected-binary fixture writes exact wrapper bytes'
    );
    chmod($withoutPcntlExec, 0700);
    $GLOBALS['wp_cli_child_php_binary'] = $withoutPcntlExec;
    wprism_check_same(
        0,
        WPrism\WpCliChildProcess::capture('receipt', 5, 65536, 65536)['return_code'],
        'a selected PHP binary without pcntl process primitives still runs through the pipe/watchdog profile'
    );
    $GLOBALS['wp_cli_child_php_binary'] = PHP_BINARY;

    $withoutWatchdog = $scratch . '/php-without-watchdog-profile';
    $withoutWatchdogSource = '#!/bin/sh' . "\n"
        . 'exec ' . escapeshellarg(PHP_BINARY)
        . ' -d disable_functions=posix_kill "$@"' . "\n";
    wprism_check_same(
        strlen($withoutWatchdogSource),
        file_put_contents($withoutWatchdog, $withoutWatchdogSource),
        'the missing-watchdog selected-binary fixture writes exact wrapper bytes'
    );
    chmod($withoutWatchdog, 0700);
    $guardedStartMarker = $scratch . '/guarded-start.marker';
    $GLOBALS['wp_cli_child_php_binary'] = $withoutWatchdog;
    $watchdogProfileFailure = wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture(
            'guarded-start ' . escapeshellarg($guardedStartMarker),
            5,
            65536,
            65536
        ),
        'parent-death fence could not start',
        'the selected PHP generation refuses when its watchdog cannot signal the owned group'
    );
    wprism_check_same(
        'wprism: bounded WP-CLI child parent-death fence could not start',
        $watchdogProfileFailure->getMessage(),
        'a missing watchdog primitive has one fixed value-free launch refusal'
    );
    wprism_check(
        !file_exists($guardedStartMarker),
        'plugin code remains behind the startup gate when the parent-death watchdog cannot arm'
    );
    $GLOBALS['wp_cli_child_php_binary'] = PHP_BINARY;

    $partialProbe = proc_open(
        [
            PHP_BINARY,
            '-d',
            'disable_functions=posix_kill',
            '-r',
            'require ' . var_export($root . '/agent/src/Kernel/WpCliChildProcess.php', true)
                . ';try{WPrism\\WpCliChildProcess::capture("receipt",1,1,1);exit(9);}catch(Throwable $e){fwrite(STDOUT,$e->getMessage());}',
        ],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $partialPipes
    );
    wprism_check(is_resource($partialProbe), 'the partial-load process-profile probe starts');
    if (is_resource($partialProbe)) {
        fclose($partialPipes[0]);
        $partialStdout = stream_get_contents($partialPipes[1]);
        $partialStderr = stream_get_contents($partialPipes[2]);
        fclose($partialPipes[1]);
        fclose($partialPipes[2]);
        $partialExit = proc_close($partialProbe);
        wprism_check_same(
            [0, 'wprism: bounded WP-CLI child requires the reviewed POSIX process profile', ''],
            [$partialExit, $partialStdout, $partialStderr],
            'partial loading cannot bypass the helper process-profile refusal before WP-CLI construction'
        );
    }

    $launchesBefore = count($GLOBALS['wp_cli_child_commands']);
    wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture('', 5, 1, 1),
        'command is outside',
        'empty commands refuse before process creation'
    );
    wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture("receipt\0suffix", 5, 1, 1),
        'command is outside',
        'NUL-bearing commands refuse before process creation'
    );
    wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture('receipt', 0, 1, 1),
        'timeout is outside',
        'zero timeout refuses before process creation'
    );
    wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture('receipt', 901, 1, 1),
        'timeout is outside',
        'timeouts above every reviewed caller budget refuse before process creation'
    );
    wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture_until(
            'receipt',
            hrtime(true) - 1,
            1,
            1
        ),
        'deadline is outside',
        'an elapsed absolute deadline refuses before process creation'
    );
    wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture_until(
            'receipt',
            hrtime(true) + 901000000000,
            1,
            1
        ),
        'deadline is outside',
        'an absolute deadline above the reviewed ceiling refuses before process creation'
    );
    wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture('receipt', 5, 1048576, 1),
        'output limits exceed',
        'aggregate capture limits cannot exceed the hard transport budget'
    );
    wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture_with_input('stdin-hash', '', 5, 1, 1),
        'input is outside',
        'empty private input refuses before process creation'
    );
    wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture_with_input(
            'stdin-exit',
            str_repeat('x', 8388609),
            5,
            1,
            1
        ),
        'input is outside',
        'private input above the hard transport boundary refuses before process creation'
    );
    $GLOBALS['wp_cli_child_php_binary'] = str_repeat('/p', 2048);
    $GLOBALS['argv'][0] = str_repeat('/s', 2048);
    $GLOBALS['wp_cli_child_runtime_config'] = ['context' => str_repeat('r', 65000)];
    wp_cli_child_refuses(
        static fn() => WPrism\WpCliChildProcess::capture(str_repeat('c', 262000), 5, 1, 1),
        'command line exceeds',
        'the full constructed PHP/script/runtime/command line has its own hard bound'
    );
    $GLOBALS['wp_cli_child_php_binary'] = PHP_BINARY;
    $GLOBALS['argv'] = [$child, '--path=/ignored-by-fixture'];
    $GLOBALS['wp_cli_child_runtime_config'] = [
        'path' => '/srv/site path',
        'debug' => true,
        'context' => 'admin',
    ];
    wprism_check_same(
        $launchesBefore,
        count($GLOBALS['wp_cli_child_commands']),
        'malformed command/timeout/output policies launch no child process'
    );
    $helperSource = file_get_contents($root . '/agent/src/Kernel/WpCliChildProcess.php');
    wprism_check(
        is_string($helperSource)
            && str_contains($helperSource, 'hrtime(true)')
            && !str_contains($helperSource, 'microtime(true)'),
        'all transport deadlines use a monotonic clock rather than mutable wall time'
    );

    $GLOBALS['argv'] = $savedArgv;
    wprism_check_summary('bounded WP-CLI child process');
}
