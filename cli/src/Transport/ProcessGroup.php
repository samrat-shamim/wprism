<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/** POSIX session boundary shared by bounded controller-side subprocesses. */
final class ProcessGroup {
    private const TERM_GRACE_NANOSECONDS = 250000000;
    private const KILL_GRACE_NANOSECONDS = 2000000000;
    private const READ_BYTES = 65536;
    private const SESSION_WRAPPER = 'if(!function_exists("passthru")||!function_exists("posix_setsid")){fwrite(STDERR,"duo-process-group-profile-unavailable\\n");exit(125);}$sid=posix_setsid();if(!is_int($sid)||$sid<1){fwrite(STDERR,"duo-process-group-session-unavailable\\n");exit(125);}$status=126;$result=passthru("exec /bin/sh -c ".escapeshellarg((string)($argv[1]??"")),$status);if($result===false){fwrite(STDERR,"duo-process-group-exec-unavailable\\n");exit(126);}exit(is_int($status)&&$status>=0&&$status<=255?$status:126);';

    /**
     * @param array<int,mixed> $descriptors
     * @param array<string,string>|null $environment
     * @return array{process:resource,pipes:array<int,resource>,leader:int}|null
     */
    public static function open(
        string $command,
        array $descriptors,
        ?string $cwd = null,
        ?array $environment = null
    ): ?array {
        self::assertProfile();
        if ($command === '' || str_contains($command, "\0")) {
            throw new \RuntimeException('bounded process command is outside the reviewed POSIX boundary');
        }
        $pipes = [];
        $process = @proc_open(
            [PHP_BINARY, '-r', self::SESSION_WRAPPER, $command],
            $descriptors,
            $pipes,
            $cwd,
            $environment,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return null;
        }
        $status = @proc_get_status($process);
        $leader = is_array($status) && is_int($status['pid'] ?? null) ? $status['pid'] : 0;
        if ($leader < 2) {
            self::terminateAndReap($process, $pipes, $leader);
            return null;
        }
        return ['process' => $process, 'pipes' => $pipes, 'leader' => $leader];
    }

    /** @param list<string> $argv */
    public static function command(array $argv): string {
        if ($argv === []) {
            throw new \InvalidArgumentException('bounded process argv must not be empty');
        }
        $tokens = [];
        foreach ($argv as $token) {
            if (!is_string($token) || str_contains($token, "\0")) {
                throw new \InvalidArgumentException('bounded process argv contains an invalid token');
            }
            $tokens[] = escapeshellarg($token);
        }
        return implode(' ', $tokens);
    }

    public static function exists(int $leader): bool {
        if ($leader < 2 || @posix_kill(-$leader, 0) !== true) {
            return false;
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            return true;
        }
        $stats = glob('/proc/[0-9]*/stat', GLOB_NOSORT);
        if (!is_array($stats)) {
            return true;
        }
        $sawMember = false;
        foreach ($stats as $path) {
            $stat = @file_get_contents($path);
            $close = is_string($stat) ? strrpos($stat, ') ') : false;
            if ($close === false) {
                continue;
            }
            $fields = explode(' ', substr($stat, $close + 2));
            if (count($fields) < 3 || (int) $fields[2] !== $leader) {
                continue;
            }
            $sawMember = true;
            if (!in_array($fields[0], ['Z', 'X'], true)) {
                return true;
            }
        }
        return !$sawMember && @posix_kill(-$leader, 0) === true;
    }

    /**
     * TERM, then KILL, the entire owned session and reap its exact leader.
     *
     * @param resource|null $process
     * @param array<int,resource> $pipes
     */
    public static function terminateAndReap(&$process, array &$pipes, int $leader): bool {
        self::signal($process, $leader, 15);
        $killAt = hrtime(true) + self::TERM_GRACE_NANOSECONDS;
        $deadline = $killAt + self::KILL_GRACE_NANOSECONDS;
        $killed = false;
        do {
            self::drain($pipes);
            $running = false;
            if (is_resource($process)) {
                $status = @proc_get_status($process);
                $running = !is_array($status) || ($status['running'] ?? true) !== false;
                if (!$running) {
                    @proc_close($process);
                    $process = null;
                }
            }
            $group = self::exists($leader);
            if (!$running && !$group) {
                self::close($pipes);
                return true;
            }
            if (!$killed && hrtime(true) >= $killAt) {
                self::signal($process, $leader, 9);
                $killed = true;
            }
            usleep(10000);
        } while (hrtime(true) < $deadline);

        self::signal($process, $leader, 9);
        self::drain($pipes);
        self::close($pipes);
        if (is_resource($process)) {
            @proc_close($process);
            $process = null;
        }
        return !self::exists($leader);
    }

    /** @param resource|null $process */
    private static function signal(&$process, int $leader, int $signal): void {
        if ($leader > 1) {
            @posix_kill(-$leader, $signal);
        }
        // The direct signal closes the launch race before the wrapper's setsid().
        if (is_resource($process)) {
            @proc_terminate($process, $signal);
        }
    }

    /** @param array<int,resource> $pipes */
    private static function drain(array &$pipes): void {
        foreach ($pipes as $fd => $pipe) {
            if (!is_resource($pipe)) {
                unset($pipes[$fd]);
                continue;
            }
            @stream_set_blocking($pipe, false);
            while (($chunk = @fread($pipe, self::READ_BYTES)) !== false && $chunk !== '') {
                // Discard termination diagnostics so no descendant blocks on a full pipe.
            }
            if (@feof($pipe)) {
                @fclose($pipe);
                unset($pipes[$fd]);
            }
        }
    }

    /** @param array<int,resource> $pipes */
    private static function close(array &$pipes): void {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $pipes = [];
    }

    private static function assertProfile(): void {
        foreach (['passthru', 'posix_kill', 'posix_setsid', 'proc_close', 'proc_get_status', 'proc_open', 'proc_terminate'] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException('bounded process requires the reviewed POSIX process-group profile');
            }
        }
        if (!in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true) || !is_executable('/bin/sh')) {
            throw new \RuntimeException('bounded process requires the reviewed POSIX process-group profile');
        }
    }
}
