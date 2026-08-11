<?php
declare(strict_types=1);

namespace Duo\Recovery;

/**
 * Bounded argv-only provider execution shared by recovery bundles.
 *
 * Providers are untrusted evidence sources: their output is size-limited,
 * canonicalized, and never allowed to broaden a bundle's declared receipt.
 * Domain bundles still validate the returned fields and secrets after this
 * transport-level envelope check.
 */
final class ProviderClient {
    /** @return array<string,mixed> */
    public static function request(
        array $command,
        array $request,
        int $timeout,
        string $scope,
        string $startMessage,
        string $timeoutMessage,
        string $outputLimitMessage,
        string $failureMessage,
        bool $includeFailureDetail = false,
        string $malformedMessage = 'duo recovery: provider returned malformed JSON',
        string $nonCanonicalMessage = 'duo recovery: provider returned noncanonical evidence',
        string $writeMessage = ''
    ): array {
        if ($command === []) {
            throw new \RuntimeException($startMessage);
        }
        $pipes = [];
        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException($startMessage);
        }
        try {
            $requestBytes = CanonicalJson::encode($request, $scope) . "\n";
            if (@fwrite($pipes[0], $requestBytes) !== strlen($requestBytes)) {
                self::terminate($process, $pipes);
                throw new \RuntimeException($writeMessage !== '' ? $writeMessage : $failureMessage);
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $stdout = '';
            $stderr = '';
            $deadline = microtime(true) + max(1, $timeout);
            $observedExit = null;
            while (true) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                if (strlen($stdout) + strlen($stderr) > 1048576) {
                    self::terminate($process, $pipes);
                    throw new \RuntimeException($outputLimitMessage);
                }
                $state = proc_get_status($process);
                if (!$state['running']) {
                    $observedExit = (int) $state['exitcode'];
                    break;
                }
                if (microtime(true) >= $deadline) {
                    self::terminate($process, $pipes);
                    throw new \RuntimeException($timeoutMessage);
                }
                usleep(10000);
            }
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (strlen($stdout) + strlen($stderr) > 1048576) {
                self::terminate($process, $pipes);
                throw new \RuntimeException($outputLimitMessage);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closedExit = proc_close($process);
            $exit = $observedExit ?? $closedExit;
            $process = null;
            if ($exit !== 0) {
                $detail = $includeFailureDetail ? trim($stderr !== '' ? $stderr : $stdout) : '';
                throw new \RuntimeException(
                    $failureMessage . ($detail !== '' ? ': ' . substr($detail, 0, 1000) : '')
                );
            }
            try {
                $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                throw new \RuntimeException($malformedMessage, 0, $e);
            }
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new \RuntimeException($nonCanonicalMessage);
            }
            try {
                $canonical = CanonicalJson::encode($decoded, $scope) . "\n";
            } catch (\Throwable $e) {
                throw new \RuntimeException($nonCanonicalMessage, 0, $e);
            }
            if ($canonical !== $stdout) {
                throw new \RuntimeException($nonCanonicalMessage);
            }
            return $decoded;
        } finally {
            if (is_resource($process)) {
                self::terminate($process, $pipes);
            }
        }
    }

    /** @param resource $process @param array<int,mixed> $pipes */
    private static function terminate($process, array $pipes): void {
        @proc_terminate($process, 9);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        @proc_close($process);
    }
}
