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
    $process = proc_open(
        ['/bin/sh', '-c', 'printf "%s" "$$" > "$1"; trap "" TERM; while :; do sleep 1; done', 'duo-child', $pidFile],
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
            'duo-child',
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
        ['/bin/sh', '-c', 'printf "%s" "$$" > "$1"; trap "" TERM; exec 1>&- 2>&-; while :; do sleep 1; done', 'duo-child', $pidFile],
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
        $pipes
    );
    exit(is_resource($process) ? proc_close($process) : 15);
}
if ($mode === 'fork-descendant-timeout') {
    [$parentPidFile, $childPidFile, $markerFile] = array_pad($args, 3, '');
    file_put_contents($parentPidFile, (string) getmypid());
    $process = proc_open(
        [
            '/bin/sh',
            '-c',
            'printf "%s" "$$" > "$1"; trap "" TERM; exec 1>&- 2>&-; sleep 2.5; printf "%s" descendant-survived > "$2"; while :; do sleep 1; done',
            'duo-child',
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
            'duo-child',
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
    $exactOverrideInner = wp_cli_child_inner_command($exactOverride);
    duo_check(
        !str_contains($exactOverrideInner, "--path='/srv/site path'")
            && str_ends_with($exactOverrideInner, ' --path'),
        'an exact leading runtime flag follows WP-CLI override suppression semantics'
    );
    Duo\WpCliChildProcess::capture('--pathology', 5, 262144, 65536);
    $prefixNearMiss = (string) end($GLOBALS['wp_cli_child_commands']);
    $prefixNearMissInner = wp_cli_child_inner_command($prefixNearMiss);
    duo_check(
        str_contains($prefixNearMissInner, "--path='/srv/site path'")
            && str_ends_with($prefixNearMissInner, ' --pathology'),
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
        0,
        $receipt['return_code'],
        'a quick successful leader is reaped before the empty-group proof without a false descendant refusal'
    );
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
        $pid > 1 && function_exists('posix_kill') && wp_cli_child_process_is_inert($pid),
        'the SIGKILL path leaves the exact TERM-ignoring child unable to execute before returning'
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
        $chattyPid > 1 && function_exists('posix_kill') && wp_cli_child_process_is_inert($chattyPid),
        'the exact continuously chatty child cannot execute after timeout returns'
    );

    $closedPidFile = $scratch . '/closed-pipes.pid';
    $closedStarted = microtime(true);
    $closedFailure = wp_cli_child_refuses(
        static fn() => Duo\WpCliChildProcess::capture(
            'closed-pipes-hang ' . escapeshellarg($closedPidFile),
            1,
            65536,
            65536
        ),
        'wall-clock limit',
        'closing both output pipes cannot make a still-running leader reach blocking proc_close'
    );
    duo_check_same(
        'duo: bounded WP-CLI child exceeded its wall-clock limit',
        $closedFailure->getMessage(),
        'closed-pipe cleanup preserves the original wall-clock refusal'
    );
    duo_check(
        microtime(true) - $closedStarted >= 1.0 && microtime(true) - $closedStarted < 4.0,
        'a pipe-less TERM-ignoring leader is group-killed and reaped within the fixed boundary'
    );
    $closedPid = is_file($closedPidFile) ? (int) file_get_contents($closedPidFile) : 0;
    duo_check(
        $closedPid > 1 && wp_cli_child_process_is_inert($closedPid),
        'the leader that closed both output pipes cannot survive the helper refusal'
    );

    $forkParentPidFile = $scratch . '/fork-timeout-parent.pid';
    $forkChildPidFile = $scratch . '/fork-timeout-child.pid';
    $forkMarkerFile = $scratch . '/fork-timeout.marker';
    $forkFailure = wp_cli_child_refuses(
        static fn() => Duo\WpCliChildProcess::capture(
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
    duo_check_same(
        'duo: bounded WP-CLI child exceeded its wall-clock limit',
        $forkFailure->getMessage(),
        'fork-tree cleanup preserves the original timeout rather than descendant output'
    );
    $forkParentPid = is_file($forkParentPidFile) ? (int) file_get_contents($forkParentPidFile) : 0;
    $forkChildPid = is_file($forkChildPidFile) ? (int) file_get_contents($forkChildPidFile) : 0;
    duo_check(
        $forkParentPid > 1
            && $forkChildPid > 1
            && wp_cli_child_process_is_inert($forkParentPid)
            && wp_cli_child_process_is_inert($forkChildPid),
        'SIGKILL leaves both the direct child and its TERM-ignoring descendant unable to execute before returning'
    );
    usleep(1700000);
    duo_check(!file_exists($forkMarkerFile), 'a descendant cannot mutate target state after timeout was reported');

    $successChildPidFile = $scratch . '/fork-success-child.pid';
    $successMarkerFile = $scratch . '/fork-success.marker';
    $successFailure = wp_cli_child_refuses(
        static fn() => Duo\WpCliChildProcess::capture(
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
    duo_check_same(
        'duo: bounded WP-CLI child left its process group running after exit',
        $successFailure->getMessage(),
        'the apparent-success descendant path has one fixed value-free refusal'
    );
    $successChildPid = is_file($successChildPidFile) ? (int) file_get_contents($successChildPidFile) : 0;
    duo_check(
        $successChildPid > 1 && wp_cli_child_process_is_inert($successChildPid),
        'the owned group is empty before an apparent-success refusal returns'
    );
    usleep(1700000);
    duo_check(!file_exists($successMarkerFile), 'an apparent-success descendant cannot mutate after refusal');

    $selectedBinary = $scratch . '/php-without-session-profile';
    $selectedSource = '#!/bin/sh' . "\n"
        . 'exec ' . escapeshellarg(PHP_BINARY)
        . ' -d disable_functions=passthru,posix_setsid "$@"' . "\n";
    duo_check_same(
        strlen($selectedSource),
        file_put_contents($selectedBinary, $selectedSource),
        'the selected-binary profile fixture writes exact wrapper bytes'
    );
    chmod($selectedBinary, 0700);
    $GLOBALS['wp_cli_child_php_binary'] = $selectedBinary;
    $selectedReceipt = Duo\WpCliChildProcess::capture('receipt', 5, 65536, 65536);
    duo_check_same(
        [
            'return_code' => 125,
            'stdout' => '',
            'stderr' => "duo-child-process-profile-unavailable\n",
        ],
        $selectedReceipt,
        'the selected PHP binary independently refuses before launching WP-CLI when session primitives are absent'
    );
    $GLOBALS['wp_cli_child_php_binary'] = PHP_BINARY;

    $withoutPcntlExec = $scratch . '/php-without-pcntl-exec';
    $withoutPcntlExecSource = '#!/bin/sh' . "\n"
        . 'exec ' . escapeshellarg(PHP_BINARY)
        . ' -d disable_functions=pcntl_exec "$@"' . "\n";
    duo_check_same(
        strlen($withoutPcntlExecSource),
        file_put_contents($withoutPcntlExec, $withoutPcntlExecSource),
        'the no-pcntl-exec selected-binary fixture writes exact wrapper bytes'
    );
    chmod($withoutPcntlExec, 0700);
    $GLOBALS['wp_cli_child_php_binary'] = $withoutPcntlExec;
    duo_check_same(
        0,
        Duo\WpCliChildProcess::capture('receipt', 5, 65536, 65536)['return_code'],
        'a selected PHP binary without pcntl_exec still runs through the declared passthru/session profile'
    );
    $GLOBALS['wp_cli_child_php_binary'] = PHP_BINARY;

    $partialProbe = proc_open(
        [
            PHP_BINARY,
            '-d',
            'disable_functions=posix_kill',
            '-r',
            'require ' . var_export($root . '/agent/src/Kernel/WpCliChildProcess.php', true)
                . ';try{Duo\\WpCliChildProcess::capture("receipt",1,1,1);exit(9);}catch(Throwable $e){fwrite(STDOUT,$e->getMessage());}',
        ],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $partialPipes
    );
    duo_check(is_resource($partialProbe), 'the partial-load process-profile probe starts');
    if (is_resource($partialProbe)) {
        fclose($partialPipes[0]);
        $partialStdout = stream_get_contents($partialPipes[1]);
        $partialStderr = stream_get_contents($partialPipes[2]);
        fclose($partialPipes[1]);
        fclose($partialPipes[2]);
        $partialExit = proc_close($partialProbe);
        duo_check_same(
            [0, 'duo: bounded WP-CLI child requires the reviewed POSIX process profile', ''],
            [$partialExit, $partialStdout, $partialStderr],
            'partial loading cannot bypass the helper process-profile refusal before WP-CLI construction'
        );
    }

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
