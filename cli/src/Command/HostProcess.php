<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Transport/ProcessGroup.php';

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
            ? [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]
            : [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $hostEnvironment = getenv();
        $environment = $extraEnv === []
            ? null
            : array_replace(is_array($hostEnvironment) ? $hostEnvironment : [], $extraEnv);
        $opened = ProcessGroup::open(ProcessGroup::command($argv), $descriptors, $cwd, $environment);
        if ($opened === null) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => 'could not start process'];
        }
        $process = $opened['process'];
        $pipes = $opened['pipes'];
        $leader = $opened['leader'];
        $deadline = hrtime(true) + ($timeoutMilliseconds * 1000000);
        if (!$passthrough) {
            fclose($pipes[0]);
        }
        // A child can fill stderr while holding stdout open (or the reverse).
        // The 200 KiB adversarial child in regress_ideal_onboarding.php pins
        // the concurrent drain; sequential stream_get_contents() deadlocks.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buffers = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $exitCode = null;
        while ($open !== [] || $exitCode === null) {
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) {
                $owned = $open;
                if (!ProcessGroup::terminateAndReap($process, $owned, $leader)) {
                    return ['exit' => 125, 'stdout' => '', 'stderr' => 'process group could not be reaped'];
                }
                return ['exit' => 124, 'stdout' => '', 'stderr' => 'process timed out'];
            }
            if ($open === []) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }
                usleep((int) min(10000, max(1, intdiv($remaining, 1000))));
                continue;
            }
            $read = array_values($open);
            $write = null;
            $except = null;
            $seconds = intdiv($remaining, 1000000000);
            $microseconds = intdiv($remaining % 1000000000, 1000);
            $selected = @stream_select($read, $write, $except, $seconds, $microseconds);
            if ($selected === false) {
                $owned = $open;
                if (!ProcessGroup::terminateAndReap($process, $owned, $leader)) {
                    return ['exit' => 125, 'stdout' => '', 'stderr' => 'process group could not be reaped'];
                }
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
                if ($passthrough) {
                    @fwrite($fd === 1 ? STDOUT : STDERR, $chunk);
                    @fflush($fd === 1 ? STDOUT : STDERR);
                    continue;
                }
                $buffers[$fd] .= $chunk;
                if (strlen($buffers[1]) + strlen($buffers[2]) > $outputLimitBytes) {
                    $owned = $open;
                    if (!ProcessGroup::terminateAndReap($process, $owned, $leader)) {
                        return ['exit' => 125, 'stdout' => '', 'stderr' => 'process group could not be reaped'];
                    }
                    return ['exit' => 125, 'stdout' => '', 'stderr' => 'process output exceeded capture limit'];
                }
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
            }
        }
        foreach ($open as $stream) {
            fclose($stream);
        }
        $closed = proc_close($process);
        $process = null;
        if (ProcessGroup::exists($leader)) {
            $owned = [];
            ProcessGroup::terminateAndReap($process, $owned, $leader);
            return ['exit' => 125, 'stdout' => '', 'stderr' => 'process left descendants running'];
        }
        $exit = $closed === -1 && is_int($exitCode) && $exitCode >= 0 ? $exitCode : $closed;
        return [
            'exit' => $exit,
            'stdout' => $passthrough ? '' : $buffers[1],
            'stderr' => $passthrough ? '' : $buffers[2],
        ];
    }
}
