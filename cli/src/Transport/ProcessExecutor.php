<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__) . '/HostContracts/TransportResult.php';

/** Process boundary used by host workflows that need an explicit timeout. */
interface ProcessExecutor
{
    /** @param list<string> $argv */
    public function execute(array $argv, ?float $timeoutSeconds = null): TransportResult;
}

/**
 * Small argv-native executor. It is independent from the local/docker/SSH
 * transport implementations and therefore preserves argument boundaries for
 * new workflows without changing the established transport wire contract.
 */
final class NativeProcessExecutor implements ProcessExecutor
{
    /** @param list<string> $argv */
    public function execute(array $argv, ?float $timeoutSeconds = null): TransportResult
    {
        if ($argv === []) {
            throw new \InvalidArgumentException('process execution requires a non-empty argv');
        }
        foreach ($argv as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new \InvalidArgumentException('process argv contains an invalid argument');
            }
        }
        if ($timeoutSeconds !== null && ($timeoutSeconds <= 0 || !is_finite($timeoutSeconds))) {
            throw new \InvalidArgumentException('process timeout must be a finite positive number');
        }

        $process = proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return new TransportResult(255, '', 'failed to start process');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = $timeoutSeconds === null ? null : microtime(true) + $timeoutSeconds;
        $timedOut = false;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!is_array($status) || $status['running'] !== true) {
                break;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process, 15);
                usleep(100000);
                if ((proc_get_status($process)['running'] ?? false) === true) {
                    proc_terminate($process, 9);
                }
                break;
            }
            usleep(10000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($timedOut) {
            $stderr = rtrim($stderr) . ($stderr === '' ? '' : "\n") . 'process timed out';
            $exit = 124;
        }
        return new TransportResult(is_int($exit) ? $exit : 255, $stdout, $stderr);
    }
}
