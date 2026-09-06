<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/BoundedChildProcess.php';

/**
 * Bounded fresh WP-CLI process transport.
 *
 * WP-CLI main's runcommand(return=all) opens separate stdout/stderr pipes but
 * drains them sequentially (php/class-wp-cli.php:1607-1669, audited
 * 2026-08-24). A child that fills stderr before closing stdout deadlocks that
 * path, and either stream is accumulated without a byte or wall-clock bound.
 * WPrism needs the same runtime config and alias propagation, so this helper
 * reproduces only that reviewed command construction and replaces the capture
 * loop. A kernel liveness pipe also holds plugin startup behind an acknowledged
 * watchdog and fences the full process group if the lock-holding parent dies.
 * Receipt grammar, acceptable output, and exit policy stay with the closed
 * caller rather than becoming a generic command-success oracle.
 */
final class WpCliChildProcess {
    private const MAX_COMMAND_BYTES = 262144;
    private const MAX_COMMAND_LINE_BYTES = 327680;
    private const MAX_CAPTURE_BYTES = 1048576;
    private const MAX_INPUT_BYTES = 8388608;
    private const CHILD_MEMORY_LIMIT = '512M';
    public const MAX_TIMEOUT_SECONDS = BoundedChildProcess::MAX_TIMEOUT_SECONDS;
    private const NANOSECONDS_PER_SECOND = 1000000000;

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
        self::assert_timeout($timeoutSeconds);
        return self::capture_until(
            $command,
            hrtime(true) + ($timeoutSeconds * self::NANOSECONDS_PER_SECOND),
            $stdoutLimit,
            $stderrLimit
        );
    }

    /**
     * Launch one fixed command under a caller-owned absolute monotonic deadline.
     *
     * @return array{return_code:int,stdout:string,stderr:string}
     */
    public static function capture_until(
        string $command,
        int $deadlineNanoseconds,
        int $stdoutLimit,
        int $stderrLimit
    ): array {
        return self::capture_request_until(
            $command,
            null,
            $deadlineNanoseconds,
            $stdoutLimit,
            $stderrLimit
        );
    }

    /**
     * Launch one fixed command with caller-owned bytes on a bounded stdin pipe.
     *
     * Secrets and merchant-authored state must not cross the command line or
     * process environment. The pipe is drained concurrently with both output
     * streams so a child can emit diagnostics before consuming its full input
     * without recreating WP-CLI's sequential-pipe deadlock.
     *
     * @return array{return_code:int,stdout:string,stderr:string}
     */
    public static function capture_with_input(
        string $command,
        string $input,
        int $timeoutSeconds,
        int $stdoutLimit,
        int $stderrLimit
    ): array {
        self::assert_timeout($timeoutSeconds);
        return self::capture_with_input_until(
            $command,
            $input,
            hrtime(true) + ($timeoutSeconds * self::NANOSECONDS_PER_SECOND),
            $stdoutLimit,
            $stderrLimit
        );
    }

    /**
     * Launch one fixed stdin command under an absolute monotonic deadline.
     *
     * @return array{return_code:int,stdout:string,stderr:string}
     */
    public static function capture_with_input_until(
        string $command,
        string $input,
        int $deadlineNanoseconds,
        int $stdoutLimit,
        int $stderrLimit
    ): array {
        $bytes = strlen($input);
        if ($bytes < 1 || $bytes > self::MAX_INPUT_BYTES) {
            throw new \RuntimeException('wprism: bounded WP-CLI child input is outside the fixed transport boundary');
        }
        return self::capture_request_until(
            $command,
            $input,
            $deadlineNanoseconds,
            $stdoutLimit,
            $stderrLimit
        );
    }

    /** @return array{return_code:int,stdout:string,stderr:string} */
    private static function capture_request_until(
        string $command,
        ?string $input,
        int $deadlineNanoseconds,
        int $stdoutLimit,
        int $stderrLimit
    ): array {
        self::assert_request($command, $stdoutLimit, $stderrLimit);
        self::assert_deadline($deadlineNanoseconds);
        try {
            BoundedChildProcess::assert_process_profile();
            $commandBoundary = self::command_line($command);
            return BoundedChildProcess::capture_until(
                $commandBoundary['command_line'],
                $commandBoundary['php_binary'],
                $input,
                $deadlineNanoseconds,
                $stdoutLimit,
                $stderrLimit,
                null,
                static function (string $line, array $descriptors, array &$pipes, ?string $cwd) {
                    return \WP_CLI\Utils\proc_open_compat($line, $descriptors, $pipes, $cwd);
                }
            );
        } catch (\RuntimeException $failure) {
            // The command facade owns its long-standing public refusal text;
            // the shared lifecycle reports no WordPress-specific semantics.
            $prefix = 'wprism: bounded child process';
            $message = $failure->getMessage();
            if (str_starts_with($message, $prefix)) {
                $message = 'wprism: bounded WP-CLI child' . substr($message, strlen($prefix));
                throw new \RuntimeException($message, 0, $failure->getPrevious());
            }
            throw $failure;
        }
    }

    private static function assert_request(
        string $command,
        int $stdoutLimit,
        int $stderrLimit
    ): void {
        $bytes = strlen($command);
        if ($bytes < 1 || $bytes > self::MAX_COMMAND_BYTES || str_contains($command, "\0")) {
            throw new \RuntimeException('wprism: bounded WP-CLI child command is outside the fixed transport boundary');
        }
        if ($stdoutLimit < 1
            || $stderrLimit < 1
            || $stdoutLimit > self::MAX_CAPTURE_BYTES
            || $stderrLimit > self::MAX_CAPTURE_BYTES
            || $stdoutLimit + $stderrLimit > self::MAX_CAPTURE_BYTES) {
            throw new \RuntimeException('wprism: bounded WP-CLI child output limits exceed the fixed transport boundary');
        }
        if (!in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true)) {
            throw new \RuntimeException('wprism: bounded WP-CLI child requires the reviewed POSIX process profile');
        }
    }

    private static function assert_timeout(int $timeoutSeconds): void {
        if ($timeoutSeconds < 1 || $timeoutSeconds > self::MAX_TIMEOUT_SECONDS) {
            throw new \RuntimeException('wprism: bounded WP-CLI child timeout is outside the fixed transport boundary');
        }
    }

    private static function assert_deadline(int $deadlineNanoseconds): void {
        $remaining = $deadlineNanoseconds - hrtime(true);
        if ($remaining <= 0
            || $remaining > self::MAX_TIMEOUT_SECONDS * self::NANOSECONDS_PER_SECOND) {
            throw new \RuntimeException('wprism: bounded WP-CLI child deadline is outside the fixed transport boundary');
        }
    }

    private static function assert_deadline_not_elapsed(int $deadlineNanoseconds): void {
        if (hrtime(true) >= $deadlineNanoseconds) {
            throw new \RuntimeException('wprism: bounded WP-CLI child exceeded its wall-clock limit');
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
            throw new \RuntimeException('wprism: bounded WP-CLI child requires the loaded WP-CLI process runtime');
        }
        foreach ($requiredFunctions as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException('wprism: bounded WP-CLI child requires the loaded WP-CLI process runtime');
            }
        }
        \WP_CLI\Utils\check_proc_available('WPrism bounded child launch');

        $argv = $GLOBALS['argv'] ?? null;
        if (!is_array($argv)
            || !isset($argv[0])
            || !is_string($argv[0])
            || $argv[0] === ''
            || strlen($argv[0]) > 4096
            || str_contains($argv[0], "\0")) {
            throw new \RuntimeException('wprism: bounded WP-CLI child found an invalid executable boundary');
        }
        $phpBinary = \WP_CLI\Utils\get_php_binary();
        if (!is_string($phpBinary)
            || $phpBinary === ''
            || strlen($phpBinary) > 4096
            || str_contains($phpBinary, "\0")) {
            throw new \RuntimeException('wprism: bounded WP-CLI child found an invalid PHP executable boundary');
        }

        $configurator = \WP_CLI::get_configurator();
        if (!is_object($configurator) || !is_callable([$configurator, 'parse_args'])) {
            throw new \RuntimeException('wprism: bounded WP-CLI child found an invalid runtime configurator');
        }
        $parseRuntime = \Closure::fromCallable([$configurator, 'parse_args']);
        $parsed = $parseRuntime(array_slice($argv, 1));
        if (!is_array($parsed) || !isset($parsed[2]) || !is_array($parsed[2])) {
            throw new \RuntimeException('wprism: bounded WP-CLI child found malformed runtime configuration');
        }
        $runtimeConfig = $parsed[2];
        foreach ($runtimeConfig as $key => $_value) {
            if (!is_string($key)
                || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $key) !== 1) {
                throw new \RuntimeException('wprism: bounded WP-CLI child found malformed runtime configuration');
            }
            // Same override rule as WP_CLI::runcommand(): a command that
            // explicitly starts with this runtime key owns its value.
            if (preg_match('|^--' . preg_quote($key, '|') . '=?$|', $command) === 1) {
                unset($runtimeConfig[$key]);
            }
        }
        $runtime = \WP_CLI\Utils\assoc_args_to_str($runtimeConfig);
        if (!is_string($runtime) || strlen($runtime) > 65536 || str_contains($runtime, "\0")) {
            throw new \RuntimeException('wprism: bounded WP-CLI child found malformed runtime configuration');
        }

        $runner = \WP_CLI::get_runner();
        if (!is_object($runner)) {
            throw new \RuntimeException('wprism: bounded WP-CLI child found an invalid alias runtime');
        }
        $alias = $runner->alias ?? null;
        $aliasPrefix = '';
        if ($alias !== null && $alias !== false && $alias !== '') {
            if (!is_string($alias)
                || strlen($alias) > 128
                || preg_match('/^[A-Za-z0-9_.:-]+$/D', $alias) !== 1) {
                throw new \RuntimeException('wprism: bounded WP-CLI child found an invalid alias runtime');
            }
            if ('@' !== substr(ltrim($command), 0, 1)) {
                $aliasPrefix = '@' . $alias . ' ';
            }
        }

        $commandLine = escapeshellarg($phpBinary)
            . ' -d ' . escapeshellarg('memory_limit=' . self::CHILD_MEMORY_LIMIT)
            . ' ' . escapeshellarg($argv[0])
            . ' ' . $aliasPrefix . $runtime . ' ' . $command;
        if (strlen($commandLine) > self::MAX_COMMAND_LINE_BYTES || str_contains($commandLine, "\0")) {
            throw new \RuntimeException('wprism: bounded WP-CLI child command line exceeds the fixed transport boundary');
        }
        return ['php_binary' => $phpBinary, 'command_line' => $commandLine];
    }

}
