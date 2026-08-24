<?php
/**
 * Offline process-boundary proof for Duo\WpCliChildProcess.
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
        return proc_open($command, $descriptors, $pipes, $cwd, $environment, $options);
    }
}

namespace {
    require_once __DIR__ . '/../../lib/check.php';

    $priorMemoryLimit = ini_set('memory_limit', '32M');
    duo_check($priorMemoryLimit !== false && ini_get('memory_limit') === '32M', 'the transport suite runs under a 32 MiB parent ceiling');

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
            duo_check(false, "$message (expected refusal containing '$needle')");
            throw new RuntimeException('test did not observe the required refusal');
        } catch (RuntimeException $failure) {
            duo_check(
                str_contains($failure->getMessage(), $needle),
                "$message ({$failure->getMessage()})"
            );
            return $failure;
        }
    }

    $root = dirname(__DIR__, 4);
    require_once $root . '/agent/src/Kernel/WpCliChildProcess.php';

    $scratch = sys_get_temp_dir() . '/duo-wp-cli-child-' . bin2hex(random_bytes(8));
    duo_check(mkdir($scratch, 0700), 'the process fixture allocates a private scratch directory');
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
    file_put_contents($pidFile, (string) getmypid());
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, SIG_IGN);
    while (true) {
        usleep(100000);
    }
}
if ($mode === 'chatty-term-ignore') {
    $pidFile = (string) ($args[0] ?? '');
    file_put_contents($pidFile, (string) getmypid());
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, SIG_IGN);
    while (true) {
        echo str_repeat('o', 1024);
        fwrite(STDERR, str_repeat('e', 1024));
        usleep(10000);
    }
}
fwrite(STDERR, "unknown test mode\n");
exit(9);
PHP;
    duo_check_same(
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

    $argvReceipt = Duo\WpCliChildProcess::capture('argv payload', 5, 262144, 65536);
    duo_check_same(0, $argvReceipt['return_code'], 'the bounded child preserves a successful exit code');
    duo_check_same('', $argvReceipt['stderr'], 'the bounded child preserves exact empty stderr');
    duo_check_same(
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
    duo_check_same(
        ['Duo bounded child launch'],
        $GLOBALS['wp_cli_child_proc_checks'],
        'the helper preserves WP-CLI process-availability preflight'
    );
    duo_check(
        str_starts_with((string) ($GLOBALS['wp_cli_child_commands'][0] ?? ''), 'exec ')
            && str_contains((string) $GLOBALS['wp_cli_child_commands'][0], escapeshellarg(PHP_BINARY))
            && str_contains((string) $GLOBALS['wp_cli_child_commands'][0], escapeshellarg($child)),
        'the launch command replaces the shell with the exact reviewed PHP/script boundary'
    );

    $GLOBALS['wp_cli_child_alias'] = 'target';
    Duo\WpCliChildProcess::capture('@explicit receipt', 5, 262144, 65536);
    $explicitCommand = (string) end($GLOBALS['wp_cli_child_commands']);
    duo_check(
        !str_contains($explicitCommand, '@target @explicit')
            && str_contains($explicitCommand, ' @explicit receipt'),
        'an explicit WP-CLI alias is not prefixed by the inherited runner alias'
    );
    Duo\WpCliChildProcess::capture('--path', 5, 262144, 65536);
    $exactOverride = (string) end($GLOBALS['wp_cli_child_commands']);
    duo_check(
        !str_contains($exactOverride, '--path=') && str_ends_with($exactOverride, ' --path'),
        'an exact leading runtime flag follows WP-CLI override suppression semantics'
    );
    Duo\WpCliChildProcess::capture('--pathology', 5, 262144, 65536);
    $prefixNearMiss = (string) end($GLOBALS['wp_cli_child_commands']);
    duo_check(
        str_contains($prefixNearMiss, "--path='/srv/site path'")
            && str_ends_with($prefixNearMiss, ' --pathology'),
        'a runtime-key prefix near miss does not suppress the inherited runtime value'
    );

    $stderrFirst = Duo\WpCliChildProcess::capture('stderr-first', 5, 131072, 800000);
    duo_check_same(0, $stderrFirst['return_code'], 'stderr-first output larger than a pipe does not deadlock');
    duo_check_same(96 * 8192, strlen($stderrFirst['stderr']), 'stderr-first capture drains every bounded warning byte');
    duo_check_same(
        "{\"format\":\"after-stderr/v1\"}\n",
        $stderrFirst['stdout'],
        'stdout remains available after the child fills stderr first'
    );

    $warning = Duo\WpCliChildProcess::capture('warning', 5, 131072, 131072);
    duo_check_same(
        ['return_code' => 0, 'stdout' => "{\"format\":\"warning-receipt/v1\"}\n", 'stderr' => "reviewed warning\n"],
        $warning,
        'the transport preserves exact successful stdout/stderr for caller-owned warning policy'
    );
    $nonzero = Duo\WpCliChildProcess::capture('exit', 5, 131072, 131072);
    duo_check_same(7, $nonzero['return_code'], 'the transport returns a nonzero child exit for caller-owned failure policy');
    duo_check_same("private failure detail\n", $nonzero['stdout'], 'bounded nonzero stdout remains private caller data');
    duo_check_same("private stderr detail\n", $nonzero['stderr'], 'bounded nonzero stderr remains private caller data');

    $receipt = Duo\WpCliChildProcess::capture('receipt', 5, 131072, 131072);
    duo_check_same(
        ['format' => 'bounded-receipt/v1', 'verified' => true],
        json_decode(trim($receipt['stdout']), true, 4, JSON_THROW_ON_ERROR),
        'a tiny canonical receipt crosses the bounded transport byte-exactly'
    );
    duo_check_same(
        0,
        Duo\WpCliChildProcess::capture('receipt', 600, 131072, 131072)['return_code'],
        'the exact longest reviewed caller budget is admitted by the helper'
    );

    $largeFailure = null;
    try {
        Duo\WpCliChildProcess::capture('large-stdout', 10, 65536, 65536);
    } catch (RuntimeException $failure) {
        $largeFailure = $failure;
    }
    duo_check(
        $largeFailure instanceof RuntimeException
            && $largeFailure->getMessage() === 'duo: bounded WP-CLI child output exceeded its fixed byte limit'
            && !str_contains($largeFailure->getMessage(), 'credential-shaped'),
        'huge stdout preserves the original bounded refusal without cleanup errors or arbitrary child bytes'
    );
    duo_check(
        memory_get_peak_usage(true) < 24 * 1024 * 1024,
        'the 32 MiB suite stays well below its memory ceiling while refusing tens of MiB of child output'
    );
    $largeStderrFailure = null;
    try {
        Duo\WpCliChildProcess::capture('large-stderr', 10, 65536, 65536);
    } catch (RuntimeException $failure) {
        $largeStderrFailure = $failure;
    }
    duo_check(
        $largeStderrFailure instanceof RuntimeException
            && $largeStderrFailure->getMessage() === 'duo: bounded WP-CLI child output exceeded its fixed byte limit'
            && !str_contains($largeStderrFailure->getMessage(), 'credential-shaped'),
        'huge stderr preserves the bounded refusal without leaking boot or credential-shaped bytes'
    );

    $interleaved = Duo\WpCliChildProcess::capture('interleaved-boundary', 10, 524288, 524288);
    duo_check_same(524288, strlen($interleaved['stdout']), 'interleaved stdout is accepted at its exact individual boundary');
    duo_check_same(524288, strlen($interleaved['stderr']), 'interleaved stderr is accepted at its exact individual boundary');
    duo_check_same(
        1048576,
        strlen($interleaved['stdout']) + strlen($interleaved['stderr']),
        'interleaved output is accepted at the exact aggregate transport boundary'
    );
    $interleavedFailure = null;
    try {
        Duo\WpCliChildProcess::capture('interleaved-stdout-over', 10, 524288, 524288);
    } catch (RuntimeException $failure) {
        $interleavedFailure = $failure;
    }
    duo_check_same(
        'duo: bounded WP-CLI child output exceeded its fixed byte limit',
        $interleavedFailure?->getMessage(),
        'one extra interleaved stdout byte refuses at the individual boundary even when stderr is exact'
    );

    $pidFile = $scratch . '/term-ignore.pid';
    $started = microtime(true);
    $timeoutFailure = wp_cli_child_refuses(
        static fn() => Duo\WpCliChildProcess::capture(
            'term-ignore ' . escapeshellarg($pidFile),
            1,
            65536,
            65536
        ),
        'wall-clock limit',
        'a silent TERM-ignoring child reaches the fixed wall-clock refusal'
    );
    duo_check_same(
        'duo: bounded WP-CLI child exceeded its wall-clock limit',
        $timeoutFailure->getMessage(),
        'timeout preserves the original bounded refusal after TERM/KILL cleanup'
    );
    $elapsed = microtime(true) - $started;
    duo_check($elapsed >= 1.0 && $elapsed < 4.0, 'TERM grace escalates to SIGKILL within the bounded wall clock');
    $pid = is_file($pidFile) ? (int) file_get_contents($pidFile) : 0;
    duo_check($pid > 1, 'the TERM-ignoring fixture published its child PID before timeout');
    duo_check(
        $pid > 1 && function_exists('posix_kill') && @posix_kill($pid, 0) === false,
        'the SIGKILL path reaps the exact TERM-ignoring child before returning'
    );
    $chattyPidFile = $scratch . '/chatty-term-ignore.pid';
    $chattyStarted = microtime(true);
    $chattyFailure = wp_cli_child_refuses(
        static fn() => Duo\WpCliChildProcess::capture(
            'chatty-term-ignore ' . escapeshellarg($chattyPidFile),
            1,
            524288,
            524288
        ),
        'wall-clock limit',
        'continuously readable stdout/stderr cannot postpone the monotonic timeout'
    );
    $chattyElapsed = microtime(true) - $chattyStarted;
    duo_check_same(
        'duo: bounded WP-CLI child exceeded its wall-clock limit',
        $chattyFailure->getMessage(),
        'chatty TERM-ignore cleanup preserves the original timeout refusal without captured output'
    );
    duo_check(
        $chattyElapsed >= 1.0 && $chattyElapsed < 4.0,
        'chatty TERM-ignore output reaches SIGKILL/reap within the same bounded wall clock'
    );
    $chattyPid = is_file($chattyPidFile) ? (int) file_get_contents($chattyPidFile) : 0;
    duo_check(
        $chattyPid > 1 && function_exists('posix_kill') && @posix_kill($chattyPid, 0) === false,
        'the exact continuously chatty child is reaped before timeout returns'
    );

    $launchesBefore = count($GLOBALS['wp_cli_child_commands']);
    wp_cli_child_refuses(
        static fn() => Duo\WpCliChildProcess::capture('', 5, 1, 1),
        'command is outside',
        'empty commands refuse before process creation'
    );
    wp_cli_child_refuses(
        static fn() => Duo\WpCliChildProcess::capture("receipt\0suffix", 5, 1, 1),
        'command is outside',
        'NUL-bearing commands refuse before process creation'
    );
    wp_cli_child_refuses(
        static fn() => Duo\WpCliChildProcess::capture('receipt', 0, 1, 1),
        'timeout is outside',
        'zero timeout refuses before process creation'
    );
    wp_cli_child_refuses(
        static fn() => Duo\WpCliChildProcess::capture('receipt', 901, 1, 1),
        'timeout is outside',
        'timeouts above every reviewed caller budget refuse before process creation'
    );
    wp_cli_child_refuses(
        static fn() => Duo\WpCliChildProcess::capture('receipt', 5, 1048576, 1),
        'output limits exceed',
        'aggregate capture limits cannot exceed the hard transport budget'
    );
    $GLOBALS['wp_cli_child_php_binary'] = str_repeat('/p', 2048);
    $GLOBALS['argv'][0] = str_repeat('/s', 2048);
    $GLOBALS['wp_cli_child_runtime_config'] = ['context' => str_repeat('r', 65000)];
    wp_cli_child_refuses(
        static fn() => Duo\WpCliChildProcess::capture(str_repeat('c', 262000), 5, 1, 1),
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
    duo_check_same(
        $launchesBefore,
        count($GLOBALS['wp_cli_child_commands']),
        'malformed command/timeout/output policies launch no child process'
    );
    $helperSource = file_get_contents($root . '/agent/src/Kernel/WpCliChildProcess.php');
    duo_check(
        is_string($helperSource)
            && str_contains($helperSource, 'hrtime(true)')
            && !str_contains($helperSource, 'microtime(true)'),
        'all transport deadlines use a monotonic clock rather than mutable wall time'
    );

    $GLOBALS['argv'] = $savedArgv;
    duo_check_summary('bounded WP-CLI child process');
}
