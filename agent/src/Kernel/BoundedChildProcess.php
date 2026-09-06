<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/PrivateEvidenceException.php';

/**
 * Engine-internal bounded POSIX child lifecycle; not part of the adapter SDK.
 *
 * The acknowledged watchdog holds startup behind the parent's liveness fence.
 * Concurrent bounded pipes, one monotonic deadline and process-group cleanup
 * remain independent of the command-specific WP-CLI facade.
 * Command construction and result semantics belong to the invoking boundary.
 */
final class BoundedChildProcess {
    private const MAX_COMMAND_LINE_BYTES = 327680;
    private const MAX_LAUNCH_LINE_BYTES = 1572864;
    private const MAX_CAPTURE_BYTES = 1048576;
    private const MAX_INPUT_BYTES = 8388608;
    // Woo + Yoast + Polylang + TEC needs 160 MiB merely to bootstrap the
    // reviewed rewrite child. The caller may have raised its own limit, but
    // PHP CLI flags are not present in $argv and therefore cannot be inferred
    // after WordPress boot. Keep every owned PHP generation at the repository's
    // measured finite 512 MiB child ceiling instead of falling back to the
    // image's 128 MiB default or inheriting an unbounded plugin-raised limit.
    private const CHILD_MEMORY_LIMIT = '512M';
    // Yoast and Elementor's reviewed native commands each declare 600s;
    // ConvergenceVerifier admits 900s for its full snapshot verification, so
    // that exact longest caller is the hard ceiling rather than an open value.
    public const MAX_TIMEOUT_SECONDS = 900;
    private const READ_BYTES = 65536;
    private const SELECT_MICROSECONDS = 50000;
    // Linux and Darwin both expose POSIX EINTR as errno 4. PHP's documented
    // stream_select() signal path reports that numeric errno in its warning;
    // regress_wp_cli_child_process.php delivers a non-restarting signal while
    // valid child pipes are blocked and pins this classification.
    private const POSIX_EINTR = 4;
    private const NANOSECONDS_PER_SECOND = 1000000000;
    private const TERM_GRACE_NANOSECONDS = 250000000;
    private const KILL_GRACE_NANOSECONDS = 2000000000;
    // This fixed watchdog is a direct child of the lock-holding PHP process and
    // outside the mutation session. Descriptor 3 has exactly one writer in that
    // parent: SIGKILL/OOM closes it in the kernel without shutdown handlers or
    // pcntl, and the watchdog can still escalate against the whole target group.
    private const PARENT_WATCHDOG = <<<'PHP'
foreach ([
    'ctype_digit',
    'fopen',
    'fread',
    'fwrite',
    'hrtime',
    'posix_kill',
    'stream_select',
    'usleep',
] as $function) {
    if (!function_exists($function)) {
        exit(124);
    }
}
$target = isset($argv[1]) && ctype_digit((string) $argv[1]) ? (int) $argv[1] : 0;
$liveness = @fopen('php://fd/3', 'rb');
$ready = @fopen('php://fd/4', 'wb');
if ($target < 2 || !is_resource($liveness) || !is_resource($ready)) {
    exit(124);
}
if (@fwrite($ready, 'R') !== 1) {
    exit(124);
}
@fclose($ready);
$fence = static function () use ($target): never {
    if (@posix_kill(-$target, 0) !== true) {
        exit(0);
    }
    @posix_kill(-$target, 15);
    usleep(250000);
    $deadline = hrtime(true) + 2000000000;
    do {
        @posix_kill(-$target, 9);
        if (@posix_kill(-$target, 0) !== true) {
            break;
        }
        usleep(50000);
    } while (hrtime(true) < $deadline);
    exit(0);
};
while (true) {
    $read = [$liveness];
    $write = [];
    $except = null;
    $selected = @stream_select($read, $write, $except, 0, 50000);
    if ($selected === false) {
        $fence();
    }
    if ($selected === 0) {
        continue;
    }
    if (in_array($liveness, $read, true)) {
        $byte = @fread($liveness, 1);
        if ($byte === 'D') {
            exit(0);
        }
        if (!is_string($byte) || $byte !== '' || feof($liveness)) {
            $fence();
        }
    }
}
PHP;

    // This fixed program creates the process group, then admits plugin code
    // only after the lock-holding parent has armed its independent watchdog.
    private const SESSION_WRAPPER = <<<'PHP'
$required = [
    'fopen',
    'fread',
    'passthru',
    'posix_setsid',
];
foreach ($required as $function) {
    if (!function_exists($function)) {
        fwrite(STDERR, "wprism-child-process-profile-unavailable\n");
        exit(125);
    }
}
$sid = posix_setsid();
if (!is_int($sid) || $sid < 1) {
    fwrite(STDERR, "wprism-child-session-unavailable\n");
    exit(125);
}
$gate = @fopen('php://fd/3', 'rb');
$start = is_resource($gate) ? @fread($gate, 1) : false;
if (is_resource($gate)) {
    @fclose($gate);
}
if ($start !== 'S') {
    fwrite(STDERR, "wprism-child-parent-fence-unavailable\n");
    exit(125);
}
$status = 126;
$result = passthru('exec /bin/sh -c ' . escapeshellarg((string) ($argv[1] ?? '')), $status);
if ($result === false) {
    fwrite(STDERR, "wprism-child-exec-unavailable\n");
    exit(126);
}
exit(is_int($status) && $status >= 0 && $status <= 255 ? $status : 126);
PHP;

    /**
     * Retain a rejected capture without teaching transport what success means.
     * The caller supplies its reviewed sentence; ordinary exception rendering
     * cannot reach either stream. The existing private graph owns byte limits,
     * binary encoding, original hashes and explicit truncation witnesses.
     *
     * @param array{return_code:int,stdout:string,stderr:string} $result
     */
    public static function failure_evidence(
        string $message,
        array $result,
        ?\Throwable $cause = null
    ): PrivateEvidenceException {
        return new PrivateEvidenceException(
            $message,
            new \RuntimeException('wprism: child process return_code=' . $result['return_code']),
            new PrivateEvidenceException('wprism: child process stdout', new \RuntimeException($result['stdout'])),
            new PrivateEvidenceException('wprism: child process stderr', new \RuntimeException($result['stderr'])),
            ...($cause === null ? [] : [$cause])
        );
    }

    /** @return array{return_code:int,stdout:string,stderr:string} */
    public static function capture_until(
        string $commandLine,
        string $phpBinary,
        ?string $input,
        int $deadlineNanoseconds,
        int $stdoutLimit,
        int $stderrLimit,
        ?string $cwd = null,
        ?\Closure $launcher = null
    ): array {
        self::assert_request($commandLine, $stdoutLimit, $stderrLimit);
        self::assert_deadline($deadlineNanoseconds);
        self::assert_process_profile();
        if ($phpBinary === '' || strlen($phpBinary) > 4096 || str_contains($phpBinary, "\0")
            || ($cwd !== null && ($cwd === '' || strlen($cwd) > 4096 || str_contains($cwd, "\0")))
            || ($input !== null && ($input === '' || strlen($input) > self::MAX_INPUT_BYTES))) {
            throw new \RuntimeException('wprism: bounded child process has an invalid executable, directory, or input boundary');
        }
        self::assert_deadline_not_elapsed($deadlineNanoseconds);
        $launchLine = self::session_launch_line(
            $phpBinary,
            $commandLine
        );
        self::assert_deadline_not_elapsed($deadlineNanoseconds);
        if ($input === null && (!defined('STDIN') || !is_resource(STDIN))) {
            throw new \RuntimeException('wprism: bounded child process has no inherited standard input');
        }

        $pipes = [];
        $process = null;
        try {
            // `exec` replaces proc_open's shell with the fixed wrapper. The
            // wrapper creates one owned session/process group, then replaces
            // itself with the exact engine-constructed command. Every ordinary
            // descendant is therefore inside the same lifecycle boundary.
            $launcher ??= static function (string $line, array $descriptors, array &$openedPipes, ?string $directory) {
                return @proc_open($line, $descriptors, $openedPipes, $directory);
            };
            $process = $launcher(
                $launchLine,
                [
                    0 => $input === null ? STDIN : ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                    3 => ['pipe', 'r'],
                ],
                $pipes,
                $cwd
            );
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: bounded child process could not start', 0, $failure);
        }
        if (!is_resource($process)
            || !isset($pipes[1], $pipes[2])
            || !isset($pipes[3])
            || ($input !== null && !isset($pipes[0]))
            || ($input !== null && !is_resource($pipes[0]))
            || !is_resource($pipes[1])
            || !is_resource($pipes[2])
            || !is_resource($pipes[3])) {
            if (is_resource($process)) {
                self::terminate_and_reap($process, $pipes, null);
            }
            throw new \RuntimeException('wprism: bounded child process could not start');
        }

        $initialStatus = @proc_get_status($process);
        if (!is_array($initialStatus)
            || !isset($initialStatus['pid'])
            || !is_int($initialStatus['pid'])
            || $initialStatus['pid'] < 2) {
            self::terminate_and_reap($process, $pipes, null);
            throw new \RuntimeException('wprism: bounded child process process state became unreadable');
        }
        $leaderPid = $initialStatus['pid'];
        $watchdog = null;
        $watchdogPipes = [];
        $watchdogExit = null;

        try {
            $sessionReady = self::await_child_session(
                $process,
                $leaderPid,
                $initialStatus,
                $deadlineNanoseconds
            );
            if (!$sessionReady) {
                @fclose($pipes[3]);
                unset($pipes[3]);
                return self::capture_process(
                    $process,
                    $pipes,
                    $leaderPid,
                    $initialStatus,
                    $deadlineNanoseconds,
                    $stdoutLimit,
                    $stderrLimit,
                    $input,
                    $watchdog,
                    $watchdogExit,
                    false
                );
            }
            self::start_parent_watchdog(
                $phpBinary,
                $leaderPid,
                $watchdog,
                $watchdogPipes,
                $watchdogExit,
                $deadlineNanoseconds
            );
            // The session wrapper cannot cross into plugin code once the
            // caller's authority has expired. Cleanup may exceed that deadline
            // solely to terminate and reap the still-gated process group.
            self::assert_deadline_not_elapsed($deadlineNanoseconds);
            // A failed write means the gated wrapper already refused or died;
            // capture its bounded diagnostics below. No plugin byte can run
            // without reading this exact start token.
            self::write_control_byte($pipes[3], 'S');
            @fclose($pipes[3]);
            unset($pipes[3]);
            $receipt = self::capture_process(
                $process,
                $pipes,
                $leaderPid,
                $initialStatus,
                $deadlineNanoseconds,
                $stdoutLimit,
                $stderrLimit,
                $input,
                $watchdog,
                $watchdogExit,
                true
            );
            self::finish_parent_watchdog($watchdog, $watchdogPipes, $watchdogExit, true, false);
            self::assert_deadline_not_elapsed($deadlineNanoseconds);
            return $receipt;
        } catch (\Throwable $failure) {
            if (is_resource($process)) {
                self::terminate_and_reap($process, $pipes, $leaderPid);
            }
            self::finish_parent_watchdog(
                $watchdog,
                $watchdogPipes,
                $watchdogExit,
                false,
                self::process_group_exists($leaderPid)
            );
            throw $failure;
        }
    }

    private static function assert_request(
        string $command,
        int $stdoutLimit,
        int $stderrLimit
    ): void {
        $bytes = strlen($command);
        if ($bytes < 1 || $bytes > self::MAX_COMMAND_LINE_BYTES || str_contains($command, "\0")) {
            throw new \RuntimeException('wprism: bounded child process command is outside the fixed transport boundary');
        }
        if ($stdoutLimit < 1
            || $stderrLimit < 1
            || $stdoutLimit > self::MAX_CAPTURE_BYTES
            || $stderrLimit > self::MAX_CAPTURE_BYTES
            || $stdoutLimit + $stderrLimit > self::MAX_CAPTURE_BYTES) {
            throw new \RuntimeException('wprism: bounded child process output limits exceed the fixed transport boundary');
        }
        if (!in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true)) {
            throw new \RuntimeException('wprism: bounded child process requires the reviewed POSIX process profile');
        }
    }

    private static function assert_deadline(int $deadlineNanoseconds): void {
        $remaining = $deadlineNanoseconds - hrtime(true);
        if ($remaining <= 0
            || $remaining > self::MAX_TIMEOUT_SECONDS * self::NANOSECONDS_PER_SECOND) {
            throw new \RuntimeException('wprism: bounded child process deadline is outside the fixed transport boundary');
        }
    }

    private static function assert_deadline_not_elapsed(int $deadlineNanoseconds): void {
        if (hrtime(true) >= $deadlineNanoseconds) {
            throw new \RuntimeException('wprism: bounded child process exceeded its wall-clock limit');
        }
    }

    /** Fail closed when this file is loaded outside the normal platform gate. */
    public static function assert_process_profile(): void {
        foreach ([
            'proc_open',
            'proc_close',
            'proc_get_status',
            'proc_terminate',
            'passthru',
            'posix_kill',
            'posix_setsid',
        ] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException('wprism: bounded child process requires the reviewed POSIX process profile');
            }
        }
        if (!function_exists('is_executable') || !is_executable('/bin/sh')) {
            throw new \RuntimeException('wprism: bounded child process requires the reviewed POSIX process profile');
        }
    }

    /** Create the fixed session wrapper without reopening caller command policy. */
    private static function session_launch_line(string $phpBinary, string $commandLine): string {
        $launchLine = 'exec ' . escapeshellarg($phpBinary)
            . ' -d ' . escapeshellarg('memory_limit=' . self::CHILD_MEMORY_LIMIT)
            . ' -r ' . escapeshellarg(self::SESSION_WRAPPER)
            . ' -- ' . escapeshellarg($commandLine);
        if (strlen($launchLine) > self::MAX_LAUNCH_LINE_BYTES || str_contains($launchLine, "\0")) {
            throw new \RuntimeException('wprism: bounded child process launch line exceeds the fixed transport boundary');
        }
        return $launchLine;
    }

    /**
     * Prove setsid() completed while plugin code is still blocked on its gate.
     *
     * @param resource $process
     * @param array<string,mixed> $status
     */
    private static function await_child_session(
        &$process,
        int $leaderPid,
        array &$status,
        int $callerDeadlineNanoseconds
    ): bool {
        $startupDeadline = min(
            hrtime(true) + self::KILL_GRACE_NANOSECONDS,
            $callerDeadlineNanoseconds
        );
        do {
            if (hrtime(true) >= $callerDeadlineNanoseconds) {
                self::assert_deadline_not_elapsed($callerDeadlineNanoseconds);
            }
            if (self::process_group_exists($leaderPid)) {
                return true;
            }
            $current = @proc_get_status($process);
            if (!is_array($current) || !array_key_exists('running', $current)) {
                throw new \RuntimeException('wprism: bounded child process process state became unreadable');
            }
            $status = $current;
            if ($current['running'] !== true) {
                return false;
            }
            usleep(1000);
        } while (hrtime(true) < $startupDeadline);
        self::assert_deadline_not_elapsed($callerDeadlineNanoseconds);
        throw new \RuntimeException('wprism: bounded child process parent-death fence could not start');
    }

    /**
     * Arm the direct parent-owned watchdog before releasing the child gate.
     *
     * @param resource|null $watchdog
     * @param array<int,resource> $watchdogPipes
     */
    private static function start_parent_watchdog(
        string $phpBinary,
        int $leaderPid,
        &$watchdog,
        array &$watchdogPipes,
        ?int &$watchdogExit,
        int $callerDeadlineNanoseconds
    ): void {
        self::assert_deadline_not_elapsed($callerDeadlineNanoseconds);
        $watchdog = @proc_open(
            [
                $phpBinary,
                '-d',
                'memory_limit=32M',
                '-r',
                self::PARENT_WATCHDOG,
                '--',
                (string) $leaderPid,
            ],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'a'],
                2 => ['file', '/dev/null', 'a'],
                3 => ['pipe', 'r'],
                4 => ['pipe', 'w'],
            ],
            $watchdogPipes
        );
        if (!is_resource($watchdog)
            || !isset($watchdogPipes[3], $watchdogPipes[4])
            || !is_resource($watchdogPipes[3])
            || !is_resource($watchdogPipes[4])
            || @stream_set_blocking($watchdogPipes[4], false) !== true) {
            self::finish_parent_watchdog($watchdog, $watchdogPipes, $watchdogExit, false, false);
            throw new \RuntimeException('wprism: bounded child process parent-death fence could not start');
        }

        $ready = '';
        $startupDeadline = min(
            hrtime(true) + self::KILL_GRACE_NANOSECONDS,
            $callerDeadlineNanoseconds
        );
        do {
            if (hrtime(true) >= $callerDeadlineNanoseconds) {
                break;
            }
            // This readiness descriptor can exceed FD_SETSIZE before the
            // ordinary transport performs its own classified select. A
            // monotonic nonblocking poll keeps watchdog startup bounded while
            // preserving that existing non-EINTR refusal path for callers.
            $chunk = @fread($watchdogPipes[4], 1);
            if (is_string($chunk)) {
                $ready .= $chunk;
            }
            $status = @proc_get_status($watchdog);
            if (is_array($status)
                && ($status['running'] ?? null) === false
                && is_int($status['exitcode'] ?? null)
                && $status['exitcode'] >= 0) {
                $watchdogExit = $status['exitcode'];
            }
            if ($ready === 'R' || !is_array($status) || ($status['running'] ?? false) !== true) {
                break;
            }
            usleep(1000);
        } while (hrtime(true) < $startupDeadline);

        @fclose($watchdogPipes[4]);
        unset($watchdogPipes[4]);
        if ($ready !== 'R') {
            self::finish_parent_watchdog($watchdog, $watchdogPipes, $watchdogExit, false, false);
            self::assert_deadline_not_elapsed($callerDeadlineNanoseconds);
            throw new \RuntimeException('wprism: bounded child process parent-death fence could not start');
        }
        self::assert_deadline_not_elapsed($callerDeadlineNanoseconds);
    }

    /**
     * Stop/reap the watchdog, or close liveness so it fences a residual group.
     *
     * @param resource|null $watchdog
     * @param array<int,resource> $watchdogPipes
     */
    private static function finish_parent_watchdog(
        &$watchdog,
        array &$watchdogPipes,
        ?int &$watchdogExit,
        bool $requireClean,
        bool $fenceGroup
    ): void {
        if (isset($watchdogPipes[3]) && is_resource($watchdogPipes[3])) {
            if (!$fenceGroup) {
                self::write_control_byte($watchdogPipes[3], 'D');
            }
            @fclose($watchdogPipes[3]);
        }
        unset($watchdogPipes[3]);
        foreach ($watchdogPipes as $index => $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
            unset($watchdogPipes[$index]);
        }
        if (!is_resource($watchdog)) {
            $watchdog = null;
            if ($requireClean) {
                throw new \RuntimeException('wprism: bounded child process parent-death fence was lost');
            }
            return;
        }

        $deadline = hrtime(true) + self::TERM_GRACE_NANOSECONDS + self::KILL_GRACE_NANOSECONDS;
        $running = true;
        do {
            $status = @proc_get_status($watchdog);
            if (!is_array($status) || !array_key_exists('running', $status)) {
                break;
            }
            $running = $status['running'] === true;
            if (!$running) {
                if (is_int($status['exitcode'] ?? null) && $status['exitcode'] >= 0) {
                    $watchdogExit = $status['exitcode'];
                }
                break;
            }
            usleep(self::SELECT_MICROSECONDS);
        } while (hrtime(true) < $deadline);
        if ($running) {
            @proc_terminate($watchdog, 9);
        }
        $closedExit = @proc_close($watchdog);
        $watchdog = null;
        $exit = $watchdogExit ?? $closedExit;
        if ($requireClean && (!$running || $closedExit >= 0) && $exit !== 0) {
            throw new \RuntimeException('wprism: bounded child process parent-death fence was lost');
        }
        if ($requireClean && $running) {
            throw new \RuntimeException('wprism: bounded child process parent-death fence was lost');
        }
    }

    /** @param resource $stream */
    private static function write_control_byte($stream, string $byte): bool {
        $previous = null;
        $previous = set_error_handler(
            static function (
                int $severity,
                string $message,
                string $file,
                int $line
            ) use (&$previous): bool {
                // EPIPE is already a closed-gate/fence outcome. Do not leak
                // this expected control race into a caller-owned handler; all
                // other diagnostics retain the handler chain unchanged.
                if (in_array($severity, [E_NOTICE, E_WARNING], true)
                    && str_starts_with($message, 'fwrite(): Write of 1 bytes failed with errno=32 ')) {
                    return true;
                }
                return $previous !== null
                    ? (bool) $previous($severity, $message, $file, $line)
                    : false;
            }
        );
        try {
            $written = @fwrite($stream, $byte);
        } finally {
            restore_error_handler();
        }
        return $written === 1;
    }

    /**
     * @param resource|null $process
     * @param array<int,resource> $pipes
     * @param array<string,mixed> $initialStatus
     * @param resource|null $watchdog
     * @return array{return_code:int,stdout:string,stderr:string}
     */
    private static function capture_process(
        &$process,
        array &$pipes,
        int $leaderPid,
        array $initialStatus,
        int $deadlineNanoseconds,
        int $stdoutLimit,
        int $stderrLimit,
        ?string $input,
        &$watchdog,
        ?int &$watchdogExit,
        bool $watchdogRequired
    ): array {
        if (@stream_set_blocking($pipes[1], false) !== true
            || @stream_set_blocking($pipes[2], false) !== true
            || ($input !== null && @stream_set_blocking($pipes[0], false) !== true)) {
            throw new \RuntimeException('wprism: bounded child process could not configure its transport pipes');
        }
        $buffers = [1 => '', 2 => ''];
        $limits = [1 => $stdoutLimit, 2 => $stderrLimit];
        $inputLength = $input === null ? 0 : strlen($input);
        $inputOffset = 0;
        $termination = null;
        $termAt = null;
        $killAt = null;
        $closedExit = null;
        $running = ($initialStatus['running'] ?? null) === true;
        $observedExit = !$running
            && isset($initialStatus['exitcode'])
            && is_int($initialStatus['exitcode'])
            && $initialStatus['exitcode'] >= 0
                ? $initialStatus['exitcode']
                : null;
        $exitObservedAt = $running ? null : hrtime(true);

        while (true) {
            $read = [];
            foreach ([1, 2] as $index) {
                if (!isset($pipes[$index])) {
                    continue;
                }
                if (!is_resource($pipes[$index])) {
                    unset($pipes[$index]);
                    if ($termination === null) {
                        $termination = 'wprism: bounded child process output transport failed';
                    }
                    continue;
                }
                if (feof($pipes[$index])) {
                    fclose($pipes[$index]);
                    unset($pipes[$index]);
                    continue;
                }
                $read[] = $pipes[$index];
            }

            $write = [];
            if ($termination === null
                && $input !== null
                && isset($pipes[0])
                && is_resource($pipes[0])
                && $inputOffset < $inputLength) {
                $write[] = $pipes[0];
            }
            if ($read !== [] || $write !== []) {
                $selected = self::select_pipes($read, $write);
                if ($selected === false && $termination === null) {
                    $termination = 'wprism: bounded child process transport failed';
                } elseif (is_int($selected) && $selected > 0) {
                    foreach ($read as $stream) {
                        $index = $stream === ($pipes[1] ?? null) ? 1 : 2;
                        $chunk = @fread($stream, self::READ_BYTES);
                        if (!is_string($chunk)) {
                            if ($termination === null) {
                                $termination = 'wprism: bounded child process output transport failed';
                            }
                            continue;
                        }
                        if ($chunk === '' || $termination !== null) {
                            continue;
                        }
                        if (strlen($buffers[$index]) + strlen($chunk) > $limits[$index]) {
                            $termination = 'wprism: bounded child process output exceeded its fixed byte limit';
                            continue;
                        }
                        $buffers[$index] .= $chunk;
                    }
                    if ($write !== [] && isset($pipes[0]) && is_resource($pipes[0])) {
                        $chunk = substr($input ?? '', $inputOffset, self::READ_BYTES);
                        $written = @fwrite($pipes[0], $chunk);
                        if (!is_int($written)) {
                            if ($termination === null) {
                                $termination = 'wprism: bounded child process input transport failed';
                            }
                        } elseif ($written > 0) {
                            $inputOffset += $written;
                            if ($inputOffset === $inputLength) {
                                fclose($pipes[0]);
                                unset($pipes[0]);
                            }
                        }
                    }
                }
            } else {
                usleep(self::SELECT_MICROSECONDS);
            }

            if (is_resource($process)) {
                $status = @proc_get_status($process);
                if (!is_array($status) || !array_key_exists('running', $status)) {
                    if ($termination === null) {
                        $termination = 'wprism: bounded child process process state became unreadable';
                    }
                    $running = true;
                } else {
                    $running = $status['running'] === true;
                    if (!$running && is_int($status['exitcode'] ?? null) && $status['exitcode'] >= 0) {
                        $observedExit = $status['exitcode'];
                    }
                    if (!$running && $exitObservedAt === null) {
                        $exitObservedAt = hrtime(true);
                    }
                }
                if (!$running) {
                    if ($input !== null && $inputOffset < $inputLength && $termination === null) {
                        $termination = 'wprism: bounded child process input transport failed';
                    }
                    if (isset($pipes[0]) && is_resource($pipes[0])) {
                        @fclose($pipes[0]);
                        unset($pipes[0]);
                    }
                    // Each outer pass reads at most one bounded chunk from
                    // each stream before checking the absolute deadline and
                    // residual process group. An inherited pipe held by a
                    // continuously-writing descendant therefore cannot trap
                    // the parent in an unbounded post-leader drain.
                    $pipesOpen = isset($pipes[1]) || isset($pipes[2]);
                    if ($termination !== null || !$pipesOpen) {
                        foreach ([1, 2] as $index) {
                            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                                @fclose($pipes[$index]);
                            }
                            unset($pipes[$index]);
                        }
                        // Reap before the group probe whenever no descendant
                        // can still own an output descriptor. On Linux the
                        // process-group probe independently discounts zombies;
                        // on Darwin normal pipe EOF reaches this path promptly.
                        $closedExit = @proc_close($process);
                        $process = null;
                    }
                }
            }

            $now = hrtime(true);
            $groupAlive = self::process_group_exists($leaderPid);
            $watchdogRunning = false;
            if (is_resource($watchdog)) {
                $watchdogStatus = @proc_get_status($watchdog);
                $watchdogRunning = is_array($watchdogStatus)
                    && ($watchdogStatus['running'] ?? null) === true;
                if (!$watchdogRunning
                    && is_array($watchdogStatus)
                    && is_int($watchdogStatus['exitcode'] ?? null)
                    && $watchdogStatus['exitcode'] >= 0) {
                    $watchdogExit = $watchdogStatus['exitcode'];
                }
            }
            if ($termination === null
                && $watchdogRequired
                && !$watchdogRunning
                && ($running || $groupAlive)) {
                $termination = 'wprism: bounded child process parent-death fence was lost';
            }
            if ($termination === null && $now >= $deadlineNanoseconds) {
                $termination = 'wprism: bounded child process exceeded its wall-clock limit';
            }
            if ($termination === null
                && !$running
                && $groupAlive
                && $exitObservedAt !== null
                && $now >= $exitObservedAt + self::TERM_GRACE_NANOSECONDS) {
                $termination = 'wprism: bounded child process left its process group running after exit';
            }

            if ($termination !== null && ($running || $groupAlive) && $termAt === null) {
                self::signal_owned_processes($process, $leaderPid, 15);
                $termAt = $now;
                $killAt = $now + self::TERM_GRACE_NANOSECONDS;
            } elseif ($termination !== null
                && ($running || $groupAlive)
                && $killAt !== null
                && $now >= $killAt) {
                self::signal_owned_processes($process, $leaderPid, 9);
                $killAt = $now + self::KILL_GRACE_NANOSECONDS;
            }

            $pipesOpen = isset($pipes[1]) || isset($pipes[2]);
            if (!$running && !$groupAlive && (!$pipesOpen || $termination !== null)) {
                break;
            }
            if ($termination !== null
                && $termAt !== null
                && $now >= $termAt + self::TERM_GRACE_NANOSECONDS + self::KILL_GRACE_NANOSECONDS) {
                throw new \RuntimeException('wprism: bounded child process process group could not be reaped after termination');
            }
        }

        foreach ([0, 1, 2, 3] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                fclose($pipes[$index]);
                unset($pipes[$index]);
            }
        }
        if (is_resource($process)) {
            $closedExit = @proc_close($process);
            $process = null;
        }
        if ($termination === null && hrtime(true) >= $deadlineNanoseconds) {
            $termination = 'wprism: bounded child process exceeded its wall-clock limit';
        }
        if ($termination !== null) {
            throw new \RuntimeException($termination);
        }
        $exit = $observedExit ?? $closedExit;
        if (!is_int($exit) || $exit < 0) {
            throw new \RuntimeException('wprism: bounded child process returned an unreadable exit status');
        }
        return [
            'return_code' => $exit,
            'stdout' => $buffers[1],
            'stderr' => $buffers[2],
        ];
    }

    /**
     * @param array<int,resource> $read
     * @param array<int,resource> $write
     */
    private static function select_pipes(array &$read, array &$write): int|false {
        $interrupted = false;
        $previous = null;
        $previous = set_error_handler(
            static function (
                int $severity,
                string $message,
                string $file,
                int $line
            ) use (&$interrupted, &$previous): bool {
                if ($severity === E_WARNING
                    && str_starts_with(
                        $message,
                        'stream_select(): Unable to select [' . self::POSIX_EINTR . ']:'
                    )) {
                    $interrupted = true;
                    return true;
                }
                return $previous !== null
                    ? (bool) $previous($severity, $message, $file, $line)
                    : false;
            }
        );
        try {
            $except = null;
            $selected = @stream_select($read, $write, $except, 0, self::SELECT_MICROSECONDS);
        } finally {
            restore_error_handler();
        }
        // Zero follows the ordinary no-ready path below, so process status,
        // the monotonic deadline and group reaping are still checked during
        // every signal-interrupted iteration. Every non-EINTR false retains
        // the fixed transport refusal in capture_process().
        return $selected === false && $interrupted ? 0 : $selected;
    }

    private static function process_group_exists(int $leaderPid): bool {
        if ($leaderPid <= 1 || @posix_kill(-$leaderPid, 0) !== true) {
            return false;
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            return true;
        }

        // A containerized WP-CLI process is commonly PID 1, so an orphaned
        // grandchild that SIGKILL already made a zombie is not reaped until
        // the outer command exits. kill(-pgid, 0) reports that inert zombie as
        // an extant group even though it can no longer mutate or hold a pipe.
        // /proc is the Linux authority for distinguishing that state; an
        // unreadable or ambiguous group remains live (fail closed).
        $stats = glob('/proc/[0-9]*/stat', GLOB_NOSORT);
        if (!is_array($stats)) {
            return true;
        }
        $sawMember = false;
        foreach ($stats as $path) {
            $stat = @file_get_contents($path);
            if (!is_string($stat)) {
                continue;
            }
            $close = strrpos($stat, ') ');
            if ($close === false) {
                continue;
            }
            $fields = explode(' ', substr($stat, $close + 2));
            if (count($fields) < 3 || (int) $fields[2] !== $leaderPid) {
                continue;
            }
            $sawMember = true;
            if (!in_array($fields[0], ['Z', 'X'], true)) {
                return true;
            }
        }
        if ($sawMember) {
            return false;
        }
        return @posix_kill(-$leaderPid, 0) === true;
    }

    /** @param resource|null $process */
    private static function signal_owned_processes(&$process, int $leaderPid, int $signal): void {
        if ($leaderPid > 1) {
            @posix_kill(-$leaderPid, $signal);
        }
        // This direct signal closes the startup race before setsid(); later
        // iterations continue addressing the entire process group.
        if (is_resource($process)) {
            @proc_terminate($process, $signal);
        }
    }

    /** @param resource|null $process @param array<int,mixed> $pipes */
    private static function terminate_and_reap(&$process, array &$pipes, ?int $leaderPid): void {
        if (!is_resource($process)) {
            $process = null;
            return;
        }
        $ownedLeader = is_int($leaderPid) && $leaderPid > 1 ? $leaderPid : 0;
        self::signal_owned_processes($process, $ownedLeader, 15);
        $termDeadline = hrtime(true) + self::TERM_GRACE_NANOSECONDS;
        $killDeadline = $termDeadline + self::KILL_GRACE_NANOSECONDS;
        $killed = false;
        $running = true;
        do {
            $readAny = false;
            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                    @stream_set_blocking($pipes[$index], false);
                    // One bounded read per stream keeps a terminating child
                    // moving without letting an inherited continuously-ready
                    // pipe postpone TERM/KILL status and monotonic deadlines.
                    $chunk = @fread($pipes[$index], self::READ_BYTES);
                    $readAny = $readAny || (is_string($chunk) && $chunk !== '');
                }
            }
            if (is_resource($process)) {
                $status = @proc_get_status($process);
                $running = !is_array($status) || ($status['running'] ?? true) !== false;
                if (!$running) {
                    foreach ($pipes as $index => $pipe) {
                        if (is_resource($pipe)) {
                            @fclose($pipe);
                        }
                        unset($pipes[$index]);
                    }
                    @proc_close($process);
                    $process = null;
                }
            } else {
                $running = false;
            }
            $groupAlive = $ownedLeader > 1 && self::process_group_exists($ownedLeader);
            if (!$running && !$groupAlive) {
                break;
            }
            $now = hrtime(true);
            if (!$killed && $now >= $termDeadline) {
                self::signal_owned_processes($process, $ownedLeader, 9);
                $killed = true;
            }
            if ($now >= $killDeadline) {
                break;
            }
            if (!$readAny) {
                usleep(self::SELECT_MICROSECONDS);
            }
        } while (true);
        foreach ($pipes as $index => $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
            unset($pipes[$index]);
        }
        if (!$running && is_resource($process)) {
            @proc_close($process);
        }
        $process = null;
    }
}
