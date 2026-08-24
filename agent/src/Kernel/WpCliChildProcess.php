<?php
declare(strict_types=1);

namespace Duo;

/**
 * Bounded fresh WP-CLI process transport.
 *
 * WP-CLI main's runcommand(return=all) opens separate stdout/stderr pipes but
 * drains them sequentially (php/class-wp-cli.php:1607-1669, audited
 * 2026-08-24). A child that fills stderr before closing stdout deadlocks that
 * path, and either stream is accumulated without a byte or wall-clock bound.
 * Duo needs the same runtime config and alias propagation, so this helper
 * reproduces only that reviewed command construction and replaces the capture
 * loop. Receipt grammar, acceptable output, and exit policy stay with the
 * closed caller rather than becoming a generic command-success oracle.
 */
final class WpCliChildProcess {
    private const MAX_COMMAND_BYTES = 262144;
    private const MAX_COMMAND_LINE_BYTES = 327680;
    private const MAX_LAUNCH_LINE_BYTES = 1572864;
    private const MAX_CAPTURE_BYTES = 1048576;
    // Yoast and Elementor's reviewed native commands each declare 600s;
    // ConvergenceVerifier admits 900s for its full snapshot verification, so
    // that exact longest caller is the hard ceiling rather than an open value.
    private const MAX_TIMEOUT_SECONDS = 900;
    private const READ_BYTES = 65536;
    private const SELECT_MICROSECONDS = 50000;
    private const NANOSECONDS_PER_SECOND = 1000000000;
    private const TERM_GRACE_NANOSECONDS = 250000000;
    private const KILL_GRACE_NANOSECONDS = 2000000000;
    // This fixed program runs under the exact PHP binary WP-CLI selected. It
    // creates the process group before any plugin code and independently
    // refuses a child binary that lacks the required primitives.
    private const SESSION_WRAPPER = 'if(!function_exists("passthru")||!function_exists("posix_setsid")){fwrite(STDERR,"duo-child-process-profile-unavailable\\n");exit(125);}$sid=posix_setsid();if(!is_int($sid)||$sid<1){fwrite(STDERR,"duo-child-session-unavailable\\n");exit(125);}$status=126;$result=passthru("exec /bin/sh -c ".escapeshellarg((string)($argv[1]??"")),$status);if($result===false){fwrite(STDERR,"duo-child-exec-unavailable\\n");exit(126);}exit(is_int($status)&&$status>=0&&$status<=255?$status:126);';

    /**
     * Launch one fixed caller-owned WP-CLI command and capture bounded output.
     *
     * The limits are mandatory so adding a caller cannot accidentally restore
     * WP-CLI's unbounded return=all behavior. Their sum has one hard transport
     * ceiling; a caller can divide that budget according to its receipt.
     * Arbitrary child output is returned privately and is never logged here.
     *
     * @return array{return_code:int,stdout:string,stderr:string}
     */
    public static function capture(
        string $command,
        int $timeoutSeconds,
        int $stdoutLimit,
        int $stderrLimit
    ): array {
        self::assert_request($command, $timeoutSeconds, $stdoutLimit, $stderrLimit);
        self::assert_process_profile();
        $commandBoundary = self::command_line($command);
        $launchLine = self::session_launch_line(
            $commandBoundary['php_binary'],
            $commandBoundary['command_line']
        );
        if (!defined('STDIN') || !is_resource(STDIN)) {
            throw new \RuntimeException('duo: bounded WP-CLI child has no inherited standard input');
        }

        $pipes = [];
        $process = null;
        try {
            // `exec` replaces proc_open's shell with the fixed wrapper. The
            // wrapper creates one owned session/process group, then replaces
            // itself with the exact reviewed WP-CLI command. Every ordinary
            // descendant is therefore inside the same lifecycle boundary.
            $process = \WP_CLI\Utils\proc_open_compat(
                $launchLine,
                [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
        } catch (\Throwable $failure) {
            throw new \RuntimeException('duo: bounded WP-CLI child could not start', 0, $failure);
        }
        if (!is_resource($process)
            || !isset($pipes[1], $pipes[2])
            || !is_resource($pipes[1])
            || !is_resource($pipes[2])) {
            if (is_resource($process)) {
                self::terminate_and_reap($process, $pipes, null);
            }
            throw new \RuntimeException('duo: bounded WP-CLI child could not start');
        }

        $initialStatus = @proc_get_status($process);
        if (!is_array($initialStatus)
            || !isset($initialStatus['pid'])
            || !is_int($initialStatus['pid'])
            || $initialStatus['pid'] < 2) {
            self::terminate_and_reap($process, $pipes, null);
            throw new \RuntimeException('duo: bounded WP-CLI child process state became unreadable');
        }
        $leaderPid = $initialStatus['pid'];

        try {
            return self::capture_process(
                $process,
                $pipes,
                $leaderPid,
                $initialStatus,
                $timeoutSeconds,
                $stdoutLimit,
                $stderrLimit
            );
        } catch (\Throwable $failure) {
            if (is_resource($process)) {
                self::terminate_and_reap($process, $pipes, $leaderPid);
            }
            throw $failure;
        }
    }

    private static function assert_request(
        string $command,
        int $timeoutSeconds,
        int $stdoutLimit,
        int $stderrLimit
    ): void {
        $bytes = strlen($command);
        if ($bytes < 1 || $bytes > self::MAX_COMMAND_BYTES || str_contains($command, "\0")) {
            throw new \RuntimeException('duo: bounded WP-CLI child command is outside the fixed transport boundary');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > self::MAX_TIMEOUT_SECONDS) {
            throw new \RuntimeException('duo: bounded WP-CLI child timeout is outside the fixed transport boundary');
        }
        if ($stdoutLimit < 1
            || $stderrLimit < 1
            || $stdoutLimit > self::MAX_CAPTURE_BYTES
            || $stderrLimit > self::MAX_CAPTURE_BYTES
            || $stdoutLimit + $stderrLimit > self::MAX_CAPTURE_BYTES) {
            throw new \RuntimeException('duo: bounded WP-CLI child output limits exceed the fixed transport boundary');
        }
        if (!in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true)) {
            throw new \RuntimeException('duo: bounded WP-CLI child requires the reviewed POSIX process profile');
        }
    }

    /** Fail closed when this file is loaded outside the normal platform gate. */
    private static function assert_process_profile(): void {
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
                throw new \RuntimeException('duo: bounded WP-CLI child requires the reviewed POSIX process profile');
            }
        }
        if (!function_exists('is_executable') || !is_executable('/bin/sh')) {
            throw new \RuntimeException('duo: bounded WP-CLI child requires the reviewed POSIX process profile');
        }
    }

    /**
     * Reproduce WP_CLI::runcommand(launch=true)'s runtime/alias construction.
     *
     * @return array{php_binary:string,command_line:string}
     */
    private static function command_line(string $command): array {
        $requiredFunctions = [
            'WP_CLI\\Utils\\check_proc_available',
            'WP_CLI\\Utils\\get_php_binary',
            'WP_CLI\\Utils\\assoc_args_to_str',
            'WP_CLI\\Utils\\proc_open_compat',
        ];
        if (!class_exists('WP_CLI', false)
            || !is_callable(['WP_CLI', 'get_configurator'])
            || !is_callable(['WP_CLI', 'get_runner'])) {
            throw new \RuntimeException('duo: bounded WP-CLI child requires the loaded WP-CLI process runtime');
        }
        foreach ($requiredFunctions as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException('duo: bounded WP-CLI child requires the loaded WP-CLI process runtime');
            }
        }
        \WP_CLI\Utils\check_proc_available('Duo bounded child launch');

        $argv = $GLOBALS['argv'] ?? null;
        if (!is_array($argv)
            || !isset($argv[0])
            || !is_string($argv[0])
            || $argv[0] === ''
            || strlen($argv[0]) > 4096
            || str_contains($argv[0], "\0")) {
            throw new \RuntimeException('duo: bounded WP-CLI child found an invalid executable boundary');
        }
        $phpBinary = \WP_CLI\Utils\get_php_binary();
        if (!is_string($phpBinary)
            || $phpBinary === ''
            || strlen($phpBinary) > 4096
            || str_contains($phpBinary, "\0")) {
            throw new \RuntimeException('duo: bounded WP-CLI child found an invalid PHP executable boundary');
        }

        $configurator = \WP_CLI::get_configurator();
        if (!is_object($configurator) || !is_callable([$configurator, 'parse_args'])) {
            throw new \RuntimeException('duo: bounded WP-CLI child found an invalid runtime configurator');
        }
        $parseRuntime = \Closure::fromCallable([$configurator, 'parse_args']);
        $parsed = $parseRuntime(array_slice($argv, 1));
        if (!is_array($parsed) || !isset($parsed[2]) || !is_array($parsed[2])) {
            throw new \RuntimeException('duo: bounded WP-CLI child found malformed runtime configuration');
        }
        $runtimeConfig = $parsed[2];
        foreach ($runtimeConfig as $key => $_value) {
            if (!is_string($key)
                || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $key) !== 1) {
                throw new \RuntimeException('duo: bounded WP-CLI child found malformed runtime configuration');
            }
            // Same override rule as WP_CLI::runcommand(): a command that
            // explicitly starts with this runtime key owns its value.
            if (preg_match('|^--' . preg_quote($key, '|') . '=?$|', $command) === 1) {
                unset($runtimeConfig[$key]);
            }
        }
        $runtime = \WP_CLI\Utils\assoc_args_to_str($runtimeConfig);
        if (!is_string($runtime) || strlen($runtime) > 65536 || str_contains($runtime, "\0")) {
            throw new \RuntimeException('duo: bounded WP-CLI child found malformed runtime configuration');
        }

        $runner = \WP_CLI::get_runner();
        if (!is_object($runner)) {
            throw new \RuntimeException('duo: bounded WP-CLI child found an invalid alias runtime');
        }
        $alias = $runner->alias ?? null;
        $aliasPrefix = '';
        if ($alias !== null && $alias !== false && $alias !== '') {
            if (!is_string($alias)
                || strlen($alias) > 128
                || preg_match('/^[A-Za-z0-9_.:-]+$/D', $alias) !== 1) {
                throw new \RuntimeException('duo: bounded WP-CLI child found an invalid alias runtime');
            }
            if ('@' !== substr(ltrim($command), 0, 1)) {
                $aliasPrefix = '@' . $alias . ' ';
            }
        }

        $commandLine = escapeshellarg($phpBinary)
            . ' ' . escapeshellarg($argv[0])
            . ' ' . $aliasPrefix . $runtime . ' ' . $command;
        if (strlen($commandLine) > self::MAX_COMMAND_LINE_BYTES || str_contains($commandLine, "\0")) {
            throw new \RuntimeException('duo: bounded WP-CLI child command line exceeds the fixed transport boundary');
        }
        return ['php_binary' => $phpBinary, 'command_line' => $commandLine];
    }

    /** Create the fixed session wrapper without reopening caller command policy. */
    private static function session_launch_line(string $phpBinary, string $commandLine): string {
        $launchLine = 'exec ' . escapeshellarg($phpBinary)
            . ' -r ' . escapeshellarg(self::SESSION_WRAPPER)
            . ' -- ' . escapeshellarg($commandLine);
        if (strlen($launchLine) > self::MAX_LAUNCH_LINE_BYTES || str_contains($launchLine, "\0")) {
            throw new \RuntimeException('duo: bounded WP-CLI child launch line exceeds the fixed transport boundary');
        }
        return $launchLine;
    }

    /**
     * @param resource|null $process
     * @param array<int,resource> $pipes
     * @param array<string,mixed> $initialStatus
     * @return array{return_code:int,stdout:string,stderr:string}
     */
    private static function capture_process(
        &$process,
        array &$pipes,
        int $leaderPid,
        array $initialStatus,
        int $timeoutSeconds,
        int $stdoutLimit,
        int $stderrLimit
    ): array {
        if (@stream_set_blocking($pipes[1], false) !== true
            || @stream_set_blocking($pipes[2], false) !== true) {
            throw new \RuntimeException('duo: bounded WP-CLI child could not configure its output pipes');
        }
        $buffers = [1 => '', 2 => ''];
        $limits = [1 => $stdoutLimit, 2 => $stderrLimit];
        $deadline = hrtime(true) + ($timeoutSeconds * self::NANOSECONDS_PER_SECOND);
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
                        $termination = 'duo: bounded WP-CLI child output transport failed';
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

            if ($read !== []) {
                $write = null;
                $except = null;
                $selected = @stream_select($read, $write, $except, 0, self::SELECT_MICROSECONDS);
                if ($selected === false && $termination === null) {
                    $termination = 'duo: bounded WP-CLI child output transport failed';
                } elseif (is_int($selected) && $selected > 0) {
                    foreach ($read as $stream) {
                        $index = $stream === ($pipes[1] ?? null) ? 1 : 2;
                        $chunk = @fread($stream, self::READ_BYTES);
                        if (!is_string($chunk)) {
                            if ($termination === null) {
                                $termination = 'duo: bounded WP-CLI child output transport failed';
                            }
                            continue;
                        }
                        if ($chunk === '' || $termination !== null) {
                            continue;
                        }
                        if (strlen($buffers[$index]) + strlen($chunk) > $limits[$index]) {
                            $termination = 'duo: bounded WP-CLI child output exceeded its fixed byte limit';
                            continue;
                        }
                        $buffers[$index] .= $chunk;
                    }
                }
            } else {
                usleep(self::SELECT_MICROSECONDS);
            }

            if (is_resource($process)) {
                $status = @proc_get_status($process);
                if (!is_array($status) || !array_key_exists('running', $status)) {
                    if ($termination === null) {
                        $termination = 'duo: bounded WP-CLI child process state became unreadable';
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
                    // Reap the exact leader before probing its process group.
                    // Otherwise an unreaped zombie can make kill(-pgid, 0)
                    // look like a surviving descendant on some POSIX hosts.
                    // Drain every byte the exited leader already committed;
                    // proc_close invalidates its pipe resources on supported
                    // PHP builds, so no later read may safely own this data.
                    foreach ([1, 2] as $index) {
                        if (!isset($pipes[$index]) || !is_resource($pipes[$index])) {
                            unset($pipes[$index]);
                            continue;
                        }
                        while (true) {
                            $chunk = @fread($pipes[$index], self::READ_BYTES);
                            if (!is_string($chunk)) {
                                if ($termination === null) {
                                    $termination = 'duo: bounded WP-CLI child output transport failed';
                                }
                                break;
                            }
                            if ($chunk === '') {
                                break;
                            }
                            if ($termination === null) {
                                if (strlen($buffers[$index]) + strlen($chunk) > $limits[$index]) {
                                    $termination = 'duo: bounded WP-CLI child output exceeded its fixed byte limit';
                                } else {
                                    $buffers[$index] .= $chunk;
                                }
                            }
                        }
                        @fclose($pipes[$index]);
                        unset($pipes[$index]);
                    }
                    $closedExit = @proc_close($process);
                    $process = null;
                }
            }

            $now = hrtime(true);
            $groupAlive = self::process_group_exists($leaderPid);
            if ($termination === null && $now >= $deadline) {
                $termination = 'duo: bounded WP-CLI child exceeded its wall-clock limit';
            }
            if ($termination === null
                && !$running
                && $groupAlive
                && $exitObservedAt !== null
                && $now >= $exitObservedAt + self::TERM_GRACE_NANOSECONDS) {
                $termination = 'duo: bounded WP-CLI child left its process group running after exit';
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
                throw new \RuntimeException('duo: bounded WP-CLI child process group could not be reaped after termination');
            }
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                fclose($pipes[$index]);
                unset($pipes[$index]);
            }
        }
        if (is_resource($process)) {
            $closedExit = @proc_close($process);
            $process = null;
        }
        if ($termination !== null) {
            throw new \RuntimeException($termination);
        }
        $exit = $observedExit ?? $closedExit;
        if (!is_int($exit) || $exit < 0) {
            throw new \RuntimeException('duo: bounded WP-CLI child returned an unreadable exit status');
        }
        return [
            'return_code' => $exit,
            'stdout' => $buffers[1],
            'stderr' => $buffers[2],
        ];
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
            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                    @stream_set_blocking($pipes[$index], false);
                    while (($chunk = @fread($pipes[$index], self::READ_BYTES)) !== false && $chunk !== '') {
                        // Drain and discard so a terminating child cannot block
                        // on a full pipe while the parent is trying to reap it.
                    }
                }
            }
            if (is_resource($process)) {
                $status = @proc_get_status($process);
                $running = !is_array($status) || ($status['running'] ?? true) !== false;
                if (!$running) {
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
            usleep(self::SELECT_MICROSECONDS);
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
