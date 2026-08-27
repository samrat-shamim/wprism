<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/** Deadlock-free host subprocess boundary shared by composed CLI commands. */
final class HostProcess {
    private const DEFAULT_TIMEOUT_MILLISECONDS = 30000;
    private const DEFAULT_OUTPUT_LIMIT_BYTES = 1048576;

    /**
     * @param list<string> $argv
     * @param array<string,string> $extraEnv
     * @return array{exit:int,stdout:string,stderr:string}
     */
    public static function run(
        array $argv,
        ?string $cwd = null,
        array $extraEnv = [],
        bool $passthrough = false,
        int $timeoutMilliseconds = self::DEFAULT_TIMEOUT_MILLISECONDS,
        int $outputLimitBytes = self::DEFAULT_OUTPUT_LIMIT_BYTES
    ): array {
        if ($timeoutMilliseconds < 1 || $outputLimitBytes < 1) {
            throw new \InvalidArgumentException('host process timeout and output limit must be positive');
        }
        $descriptors = $passthrough
            ? [0 => STDIN, 1 => STDOUT, 2 => STDERR]
            : [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $hostEnvironment = getenv();
        $environment = $extraEnv === []
            ? null
            : array_replace(is_array($hostEnvironment) ? $hostEnvironment : [], $extraEnv);
        $process = @proc_open($argv, $descriptors, $pipes, $cwd, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => 'could not start process'];
        }
        if ($passthrough) {
            return ['exit' => proc_close($process), 'stdout' => '', 'stderr' => ''];
        }

        fclose($pipes[0]);
        // A child can fill stderr while holding stdout open (or the reverse).
        // The 200 KiB adversarial child in regress_ideal_onboarding.php pins
        // the concurrent drain; sequential stream_get_contents() deadlocks.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buffers = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $deadline = hrtime(true) + ($timeoutMilliseconds * 1000000);
        while ($open !== []) {
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) {
                self::terminateAndDrain($process, $open);
                return ['exit' => 124, 'stdout' => '', 'stderr' => 'process timed out'];
            }
            $read = array_values($open);
            $write = null;
            $except = null;
            $seconds = intdiv($remaining, 1000000000);
            $microseconds = intdiv($remaining % 1000000000, 1000);
            $selected = @stream_select($read, $write, $except, $seconds, $microseconds);
            if ($selected === false) {
                self::terminateAndDrain($process, $open);
                return ['exit' => 125, 'stdout' => '', 'stderr' => 'could not read process output'];
            }
            if ($selected === 0) {
                continue;
            }
            foreach ($read as $stream) {
                $fd = $stream === $pipes[1] ? 1 : 2;
                $chunk = fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    fclose($stream);
                    unset($open[$fd]);
                    continue;
                }
                $buffers[$fd] .= $chunk;
                if (strlen($buffers[1]) + strlen($buffers[2]) > $outputLimitBytes) {
                    self::terminateAndDrain($process, $open);
                    return ['exit' => 125, 'stdout' => '', 'stderr' => 'process output exceeded capture limit'];
                }
            }
        }
        foreach ($open as $stream) {
            fclose($stream);
        }
        return ['exit' => proc_close($process), 'stdout' => $buffers[1], 'stderr' => $buffers[2]];
    }

    /** @param resource $process @param array<int,resource> $pipes */
    private static function terminateAndDrain($process, array $pipes): void {
        proc_terminate($process);
        $deadline = hrtime(true) + 1000000000;
        while ($pipes !== [] && hrtime(true) < $deadline) {
            foreach ($pipes as $fd => $pipe) {
                fread($pipe, 65536);
                if (feof($pipe)) {
                    fclose($pipe);
                    unset($pipes[$fd]);
                }
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(10000);
        }
        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, 9);
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
    }
}
