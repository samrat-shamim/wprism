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
    private const MAX_CAPTURE_BYTES = 1048576;
    // Yoast and Elementor's reviewed native commands each declare 600s; keep
    // one small hard ceiling above those exact callers, never an open timeout.
    private const MAX_TIMEOUT_SECONDS = 900;
    private const READ_BYTES = 65536;
    private const SELECT_MICROSECONDS = 50000;
    private const NANOSECONDS_PER_SECOND = 1000000000;
    private const TERM_GRACE_NANOSECONDS = 250000000;
    private const KILL_GRACE_NANOSECONDS = 2000000000;

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
        $commandLine = self::command_line($command);
        if (!defined('STDIN') || !is_resource(STDIN)) {
            throw new \RuntimeException('duo: bounded WP-CLI child has no inherited standard input');
        }

        $pipes = [];
        try {
            // The reviewed platform admits Linux and Darwin only. `exec`
            // replaces proc_open's shell with the WP-CLI PHP process, so TERM
            // and the wall-clock SIGKILL address the process that owns both
            // pipes instead of leaving an orphan behind the shell.
            $process = \WP_CLI\Utils\proc_open_compat(
                'exec ' . $commandLine,
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
                self::terminate_and_reap($process, $pipes);
            }
            throw new \RuntimeException('duo: bounded WP-CLI child could not start');
        }

        try {
            return self::capture_process(
                $process,
                $pipes,
                $timeoutSeconds,
                $stdoutLimit,
                $stderrLimit
            );
        } catch (\Throwable $failure) {
            if (is_resource($process)) {
                self::terminate_and_reap($process, $pipes);
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

    /** Reproduce WP_CLI::runcommand(launch=true)'s runtime/alias construction. */
    private static function command_line(string $command): string {
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
        return $commandLine;
    }

    /**
     * @param resource|null $process
     * @param array<int,resource> $pipes
     * @return array{return_code:int,stdout:string,stderr:string}
     */
    private static function capture_process(
        &$process,
        array &$pipes,
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
        $observedExit = null;
        $exitObservedAt = null;

        while (isset($pipes[1]) || isset($pipes[2])) {
            $read = [];
            foreach ([1, 2] as $index) {
                if (!isset($pipes[$index])) {
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

            $status = @proc_get_status($process);
            if (!is_array($status) || !array_key_exists('running', $status)) {
                if ($termination === null) {
                    $termination = 'duo: bounded WP-CLI child process state became unreadable';
                }
                $running = true;
            } else {
                $running = $status['running'] === true;
                if (!$running && is_int($status['exitcode']) && $status['exitcode'] >= 0) {
                    $observedExit = $status['exitcode'];
                }
                if (!$running && $exitObservedAt === null) {
                    $exitObservedAt = hrtime(true);
                }
            }

            $now = hrtime(true);
            if ($termination === null && $now >= $deadline) {
                $termination = 'duo: bounded WP-CLI child exceeded its wall-clock limit';
            }
            if ($termination !== null && $running && $termAt === null) {
                @proc_terminate($process, 15);
                $termAt = $now;
                $killAt = $now + self::TERM_GRACE_NANOSECONDS;
            } elseif ($termination !== null
                && $running
                && $killAt !== null
                && $now >= $killAt) {
                @proc_terminate($process, 9);
                $killAt = $now + self::KILL_GRACE_NANOSECONDS;
            }

            if (!$running && $read === []) {
                break;
            }
            if (!$running
                && $exitObservedAt !== null
                && $now >= $exitObservedAt + self::TERM_GRACE_NANOSECONDS) {
                // A descendant that inherited the pipes must not hold this
                // already-exited WP-CLI process's parent open indefinitely.
                // Closing the read ends makes further descendant writes fail;
                // proc_close below still reaps the exact launched process.
                if ($termination === null) {
                    $termination = 'duo: bounded WP-CLI child left its output transport open after exit';
                }
                break;
            }
            if ($termination !== null
                && $termAt !== null
                && $now >= $termAt + self::TERM_GRACE_NANOSECONDS + self::KILL_GRACE_NANOSECONDS) {
                throw new \RuntimeException('duo: bounded WP-CLI child could not be reaped after termination');
            }
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                fclose($pipes[$index]);
                unset($pipes[$index]);
            }
        }
        $closedExit = @proc_close($process);
        $process = null;
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

    /** @param resource|null $process @param array<int,mixed> $pipes */
    private static function terminate_and_reap(&$process, array &$pipes): void {
        if (!is_resource($process)) {
            $process = null;
            return;
        }
        @proc_terminate($process, 15);
        $termDeadline = hrtime(true) + self::TERM_GRACE_NANOSECONDS;
        $killDeadline = $termDeadline + self::KILL_GRACE_NANOSECONDS;
        $killed = false;
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
            $status = @proc_get_status($process);
            if (is_array($status) && ($status['running'] ?? true) === false) {
                break;
            }
            $now = hrtime(true);
            if (!$killed && $now >= $termDeadline) {
                @proc_terminate($process, 9);
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
        @proc_close($process);
        $process = null;
    }
}
