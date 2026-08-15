<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/**
 * @phpstan-import-type CatalogAggregate from Catalog
 * @phpstan-import-type Suite from Catalog
 * @phpstan-import-type Profile from Catalog
 * @phpstan-type SuiteResult array{
 *     id:string,
 *     state:string,
 *     exit_code:?int,
 *     timed_out:bool,
 *     signal:?int,
 *     cleanup:string,
 *     duration_ms:int,
 *     log_sha256:?string,
 *     message:?string
 * }
 */
final class Runner
{
    /** @var CatalogAggregate */
    private readonly array $catalog;

    /** @param CatalogAggregate $catalog */
    public function __construct(
        private readonly string $root,
        array $catalog,
        private readonly string $resultPath,
    ) {
        $this->catalog = $catalog;
    }

    /** @param list<string> $suiteIds */
    public function run(array $suiteIds, ?string $profileId): int
    {
        $suiteMap = [];
        foreach ($this->catalog['suites'] as $suite) {
            $suiteMap[$suite['id']] = $suite;
        }
        if ($profileId !== null) {
            $profile = null;
            foreach ($this->catalog['profiles'] as $candidate) {
                if ($candidate['id'] === $profileId) {
                    $profile = $candidate;
                    break;
                }
            }
            if ($profile === null) {
                throw new CatalogException("unknown profile: $profileId");
            }
            $suiteIds = $profile['suite_ids'];
            if (!in_array($this->relative($this->resultPath), $profile['expected_outputs'], true)) {
                throw new CatalogException("result path is not declared by profile $profileId");
            }
        }
        if ($suiteIds === []) {
            throw new CatalogException('runner selection is empty');
        }
        foreach ($suiteIds as $suiteId) {
            if (!isset($suiteMap[$suiteId])) {
                throw new CatalogException("unknown suite: $suiteId");
            }
        }

        $candidateSha = trim($this->capture(['git', 'rev-parse', 'HEAD']));
        $dirtyBefore = $this->capture(['git', 'status', '--porcelain=v1', '-z']);
        $started = gmdate('Y-m-d\TH:i:s\Z');
        $runId = gmdate('Ymd\THis\Z') . '-' . substr($candidateSha, 0, 12) . '-' . bin2hex(random_bytes(4));
        $logRoot = $this->root . '/artifacts/test-results/runs/' . $runId;
        if (!mkdir($logRoot, 0755, true) && !is_dir($logRoot)) {
            throw new CatalogException('cannot create result log directory');
        }

        $results = [];
        $aggregateState = 'pass';
        foreach ($suiteIds as $suiteId) {
            $suite = $suiteMap[$suiteId];
            $result = $this->runSuite($suite, $logRoot);
            $results[] = $result;
            if ($result['state'] !== 'pass') {
                if ($result['state'] === 'fail' || $aggregateState === 'pass') {
                    $aggregateState = $result['state'];
                }
            }
        }

        $dirtyAfter = $this->capture(['git', 'status', '--porcelain=v1', '-z']);
        if ($dirtyAfter !== $dirtyBefore) {
            $aggregateState = 'infra_error';
            $results[] = [
                'id' => 'runner.workspace-integrity',
                'state' => 'infra_error',
                'exit_code' => null,
                'timed_out' => false,
                'signal' => null,
                'cleanup' => 'not_applicable',
                'duration_ms' => 0,
                'log_sha256' => null,
                'message' => 'workspace state changed during read-only catalog run',
            ];
        }

        $receipt = [
            'format' => 'duo-test-run-receipt/v1',
            'state' => $aggregateState,
            'candidate_sha' => $candidateSha,
            'candidate_dirty' => $dirtyBefore !== '',
            'catalog_sha256' => 'sha256:' . hash('sha256', $this->canonical($this->catalog)),
            'profile_id' => $profileId,
            'selected_suite_ids' => $suiteIds,
            'selected_set_sha256' => 'sha256:' . hash('sha256', implode("\0", $suiteIds)),
            'invocation_schema_sha256' => 'sha256:' . hash_file('sha256', __DIR__ . '/schema.json'),
            'runner_sha256' => 'sha256:' . hash_file('sha256', __FILE__),
            'platform' => [
                'php' => PHP_VERSION,
                'os_family' => PHP_OS_FAMILY,
                'machine_binding' => 'sha256:' . hash('sha256', php_uname('m') . "\0" . php_uname('s')),
            ],
            'started_at' => $started,
            'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'results' => $results,
        ];
        $this->publish($this->resultPath, $this->canonical($receipt) . "\n");
        printf("runner: %s (%d/%d suites executed); result %s\n", $aggregateState, count($results), count($suiteIds), $this->relative($this->resultPath));
        return $aggregateState === 'pass' ? 0 : 1;
    }

    /**
     * @param Suite $suite
     * @return SuiteResult
     */
    private function runSuite(array $suite, string $logRoot): array
    {
        $id = $suite['id'];
        foreach ($suite['required_tools'] as $tool) {
            if (!$this->hasTool($tool)) {
                return $this->infrastructureResult($id, "required tool is unavailable: $tool");
            }
        }
        if ($suite['required_services'] !== []) {
            return $this->infrastructureResult($id, 'P0 serial runner cannot provision required services');
        }
        if ($suite['environment_class'] !== 'offline') {
            return $this->infrastructureResult($id, 'non-offline suite requires harness-approval preflight');
        }
        if ($suite['workspace_mode'] !== 'read_only') {
            return $this->infrastructureResult($id, 'P0 serial runner supports read_only workspaces only');
        }

        $temporary = sys_get_temp_dir() . '/duo-test-' . preg_replace('/[^a-z0-9.-]+/', '-', $id) . '-' . bin2hex(random_bytes(6));
        if (!mkdir($temporary, 0700)) {
            return $this->infrastructureResult($id, 'cannot create unique temporary directory');
        }
        $logPath = $logRoot . '/' . $id . '.log';
        $log = fopen($logPath, 'wb');
        if (!is_resource($log)) {
            $this->removeTree($temporary);
            return $this->infrastructureResult($id, 'cannot create retained log');
        }

        $command = $suite['command'];
        $setsid = $this->hasTool('setsid');
        $processCommand = $setsid ? array_merge(['setsid'], $command) : $command;
        $inheritedEnvironment = getenv();
        $environment = array_merge($inheritedEnvironment, [
            'DUO_TEST_TMPDIR' => $temporary,
            'TMPDIR' => $temporary,
            'LC_ALL' => 'C',
            'TZ' => 'UTC',
        ]);
        $started = hrtime(true);
        $process = proc_open(
            $processCommand,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            $environment,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            fclose($log);
            $this->removeTree($temporary);
            return $this->infrastructureResult($id, 'cannot start suite process');
        }
        $stdin = $pipes[0] ?? null;
        $stdout = $pipes[1] ?? null;
        $stderr = $pipes[2] ?? null;
        if (!is_resource($stdin) || !is_resource($stdout) || !is_resource($stderr)) {
            proc_terminate($process, 9);
            proc_close($process);
            fclose($log);
            $this->removeTree($temporary);
            return $this->infrastructureResult($id, 'suite process pipes are unavailable');
        }
        fclose($stdin);
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);
        $buffers = [1 => '', 2 => ''];
        $timedOut = false;
        $signal = null;
        $lastStatus = proc_get_status($process);
        while (true) {
            $read = [];
            if (!feof($stdout)) {
                $read[] = $stdout;
            }
            if (!feof($stderr)) {
                $read[] = $stderr;
            }
            if ($read !== []) {
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, 0, 200000);
                foreach ($read as $stream) {
                    $streamId = $stream === $stdout ? 1 : 2;
                    $chunk = fread($stream, 8192);
                    if (is_string($chunk) && $chunk !== '') {
                        fwrite($log, $chunk);
                        $buffers[$streamId] .= $chunk;
                        $this->emitLines($id, $buffers[$streamId], $streamId === 2 ? STDERR : STDOUT);
                    }
                }
            }
            $lastStatus = proc_get_status($process);
            if (!$lastStatus['running']) {
                break;
            }
            $elapsed = (hrtime(true) - $started) / 1_000_000_000;
            if ($elapsed >= $suite['timeout_seconds']) {
                $timedOut = true;
                $signal = 15;
                $pid = (int) $lastStatus['pid'];
                if ($setsid && function_exists('posix_kill')) {
                    @posix_kill(-$pid, 15);
                } else {
                    proc_terminate($process, 15);
                }
                usleep(500000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    $signal = 9;
                    if ($setsid && function_exists('posix_kill')) {
                        @posix_kill(-$pid, 9);
                    } else {
                        proc_terminate($process, 9);
                    }
                }
                break;
            }
        }
        foreach ([1 => $stdout, 2 => $stderr] as $streamId => $stream) {
            $tail = stream_get_contents($stream);
            if (is_string($tail) && $tail !== '') {
                fwrite($log, $tail);
                $buffers[$streamId] .= $tail;
            }
            if ($buffers[$streamId] !== '') {
                $line = $buffers[$streamId];
                fwrite($streamId === 2 ? STDERR : STDOUT, "[$id] $line" . (str_ends_with($line, "\n") ? '' : "\n"));
            }
            fclose($stream);
        }
        $exitCode = proc_close($process);
        if (!$timedOut && $exitCode === -1 && $lastStatus['exitcode'] >= 0) {
            $exitCode = $lastStatus['exitcode'];
        }
        fflush($log);
        fclose($log);
        $cleanup = $this->removeTree($temporary) ? 'pass' : 'fail';
        $duration = (int) round((hrtime(true) - $started) / 1_000_000);
        $state = !$timedOut && $exitCode === 0 && $cleanup === 'pass' ? 'pass' : 'fail';
        return [
            'id' => $id,
            'state' => $state,
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'signal' => $signal,
            'cleanup' => $cleanup,
            'duration_ms' => $duration,
            'log_sha256' => 'sha256:' . hash_file('sha256', $logPath),
            'message' => null,
        ];
    }

    /** @return SuiteResult */
    private function infrastructureResult(string $id, string $message): array
    {
        return [
            'id' => $id,
            'state' => 'infra_error',
            'exit_code' => null,
            'timed_out' => false,
            'signal' => null,
            'cleanup' => 'not_applicable',
            'duration_ms' => 0,
            'log_sha256' => null,
            'message' => $message,
        ];
    }

    /** @param resource $stream */
    private function emitLines(string $id, string &$buffer, $stream): void
    {
        while (($position = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $position + 1);
            $buffer = substr($buffer, $position + 1);
            fwrite($stream, "[$id] $line");
        }
    }

    private function hasTool(string $tool): bool
    {
        if ($tool === '' || str_contains($tool, '/')) {
            return false;
        }
        $path = getenv('PATH');
        foreach (explode(PATH_SEPARATOR, is_string($path) ? $path : '') as $directory) {
            if ($directory !== '' && is_file($directory . '/' . $tool) && is_executable($directory . '/' . $tool)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $argv */
    private function capture(array $argv): string
    {
        $process = proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot run ' . $argv[0]);
        }
        $stdinPipe = $pipes[0] ?? null;
        $stdoutPipe = $pipes[1] ?? null;
        $stderrPipe = $pipes[2] ?? null;
        if (!is_resource($stdinPipe) || !is_resource($stdoutPipe) || !is_resource($stderrPipe)) {
            proc_terminate($process, 9);
            proc_close($process);
            throw new CatalogException($argv[0] . ' did not provide process pipes');
        }
        fclose($stdinPipe);
        $stdout = stream_get_contents($stdoutPipe);
        $stderr = stream_get_contents($stderrPipe);
        fclose($stdoutPipe);
        fclose($stderrPipe);
        if (proc_close($process) !== 0 || $stdout === false) {
            throw new CatalogException($argv[0] . ' failed: ' . trim($stderr === false ? '' : $stderr));
        }
        return $stdout;
    }

    private function publish(string $path, string $bytes): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new CatalogException('cannot create result directory');
        }
        $temporary = tempnam($directory, '.result.');
        if (!is_string($temporary)) {
            throw new CatalogException('cannot create result temporary file');
        }
        try {
            if (file_put_contents($temporary, $bytes) !== strlen($bytes) || !rename($temporary, $path)) {
                throw new CatalogException('cannot publish result');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function removeTree(string $path): bool
    {
        if (!is_dir($path) || is_link($path)) {
            return !file_exists($path) || unlink($path);
        }
        $entries = scandir($path);
        if (!is_array($entries)) {
            return false;
        }
        $ok = true;
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $ok = $this->removeTree($path . '/' . $entry) && $ok;
            }
        }
        return rmdir($path) && $ok;
    }

    private function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $value = array_map(fn(mixed $item): mixed => $this->canonicalValue($item), $value);
            } else {
                ksort($value, SORT_STRING);
                foreach ($value as $key => $item) {
                    $value[$key] = $this->canonicalValue($item);
                }
            }
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalValue($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalValue($item);
        }
        return $value;
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, $this->root . '/') ? substr($path, strlen($this->root) + 1) : $path;
    }
}
