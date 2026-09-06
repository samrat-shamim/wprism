<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/EnvironmentDriver.php';
require_once __DIR__ . '/ProcessGroup.php';

/**
 * Exact command surface required by the adoption transaction.
 *
 * Keeping this boundary narrower than SshTransport makes the double-failure
 * contract executable offline without coupling the transaction to one
 * transport. The capability row is target-free: `wprism adopt` performs a
 * separate read-only eligibility proof before calling install().
 */
interface AdoptionTransport {
    /**
     * @return array{supported:bool,reason:string,remediation:string}
     */
    public function bootstrapCapability(): array;

    public function repoPath(): string;

    public function wpPath(): string;

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function captureRaw(string $script): array;

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function captureWp(array $wpArgs): array;

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function uploadFile(string $localPath, string $remotePath): array;
}

/**
 * A transport knows how to run `wp <args...>` and arbitrary shell snippets
 * against one environment (local shell, docker compose, ssh) and how to
 * describe itself for `wprism envs`. Every environment carries a `repo_path`
 * — the site-repo path as seen *from inside that environment* — regardless
 * of transport.
 *
 * Command strings are assembled with escapeshellarg() on every variable
 * token, then executed either streamed (passthru — for the passthrough
 * verbs, so the user sees exactly what `wp wprism …` would print locally) or
 * captured (proc_open with separate stdout/stderr pipes — for doctor/status,
 * which parse output and must not have it corrupted by e.g. `docker compose
 * run`'s own container-lifecycle chatter, which lands on stderr).
 */
abstract class Transport implements BoundedControlDriver {
    private const RAW_CONTROL_FRAME_FORMAT = 'wprism-raw-control-frame/v1';
    private const RAW_CONTROL_FRAME_MAX_PROGRAM_BYTES = 65536;
    private const RAW_CONTROL_FRAME_MAX_ARGUMENTS = 256;
    private const RAW_CONTROL_FRAME_MAX_ARGUMENT_BYTES = 65536;
    private const RAW_CONTROL_FRAME_MAX_INNER_BYTES = 1048576;
    private const RAW_CONTROL_FRAME_OUTER_STDERR_BYTES = 65536;
    private const RAW_CONTROL_FRAME_OVERHEAD_BYTES = 1024;
    private const NANOS_PER_SECOND = 1000000000;
    private const TERMINATION_GRACE_NS = 250000000;
    private const DRAIN_DEADLINE_NS = 2000000000;
    private const PIPE_POLL_MICROSECONDS = 200000;
    private const MAX_CAPTURE_TIMEOUT_NS = 600000000000;
    // Onboard's initial Git publication has an explicit fifteen-minute bound;
    // Refresh's spool contract above remains capped at its reviewed ten minutes.
    private const MAX_BOUNDED_CONTROL_TIMEOUT_NS = 900000000000;
    // Refresh's 1.5 GiB envelope and 8 MiB diagnostic frontiers are process
    // boundaries, not caller tuning knobs; larger positive values are unsafe.
    private const MAX_CAPTURE_STDOUT_BYTES = 1610612736;
    private const MAX_CAPTURE_STDERR_BYTES = 8388608;

    protected string $name;
    protected string $repoPath;
    protected string $driverId;

    protected function __construct(string $name, array $cfg) {
        $this->name = $name;
        $this->repoPath = self::requireKey($cfg, $name, 'repo_path');
        $configured = $cfg['transport'] ?? null;
        $this->driverId = is_string($configured) && $configured !== '' ? $configured : 'fixture';
    }

    /** @param array<string, mixed> $cfg */
    public static function make(string $name, array $cfg): self {
        $type = $cfg['transport'] ?? null;
        if (!is_string($type) || $type === '') {
            throw new \RuntimeException("env '$name': missing required key 'transport' (expected one of: local, docker, ssh)");
        }
        return match ($type) {
            'local' => new LocalTransport($name, $cfg),
            'docker' => new DockerTransport($name, $cfg),
            'ssh' => new SshTransport($name, $cfg),
            default => throw new \RuntimeException("env '$name': unknown transport '$type' (expected one of: local, docker, ssh)"),
        };
    }

    public function name(): string {
        return $this->name;
    }

    public function driverId(): string {
        return $this->driverId;
    }

    public function repoPath(): string {
        return $this->repoPath;
    }

    public function capabilityReport(string $operation): DriverCapabilityReport {
        $supported = [
            DriverCapability::ATTACH => true,
            DriverCapability::WP_CONTROL => true,
            DriverCapability::RAW_CONTROL => true,
            DriverCapability::BOUNDED_CONTROL => true,
            DriverCapability::CODE_MATERIALIZE => true,
            DriverCapability::DB_SNAPSHOT_CREATE => true,
            DriverCapability::DB_SNAPSHOT_READ => true,
            DriverCapability::DB_SNAPSHOT_RESTORE => true,
        ];
        $unsupported = [];
        if ($this instanceof AdoptionTransport) {
            $bootstrap = $this->bootstrapCapability();
            self::assertBootstrapCapability($bootstrap);
            if ($bootstrap['supported']) {
                $supported[DriverCapability::BOOTSTRAP] = true;
                $supported[DriverCapability::CODE_TRANSFER] = true;
            } else {
                $detail = [
                    'reason' => $bootstrap['reason'],
                    'remediation' => $bootstrap['remediation'],
                ];
                $unsupported[DriverCapability::BOOTSTRAP] = $detail;
                $unsupported[DriverCapability::CODE_TRANSFER] = $detail;
            }
        }
        return DriverCapabilityReport::forDriver(
            $this->name,
            $this->driverId,
            $operation,
            $supported,
            $unsupported
        );
    }

    /** @param array<string,mixed> $capability */
    private static function assertBootstrapCapability(array $capability): void {
        $keys = array_keys($capability);
        sort($keys, SORT_STRING);
        if ($keys !== ['reason', 'remediation', 'supported']
            || !is_bool($capability['supported'] ?? null)
            || !is_string($capability['reason'] ?? null)
            || !is_string($capability['remediation'] ?? null)
            || trim((string) $capability['reason']) === ''
            || (!$capability['supported'] && trim((string) $capability['remediation']) === '')) {
            throw new \RuntimeException('adoption transport returned a malformed bootstrap capability');
        }
    }

    /** One-line description for `wprism envs`. */
    abstract public function describe(): string;

    /** Build the full, already-escaped shell command that runs `wp <wpArgs...>`. */
    abstract protected function wpCommand(array $wpArgs): string;

    /** Build the full, already-escaped shell command that runs a raw shell snippet. */
    abstract protected function rawCommand(string $script): string;

    /** Stream a `wp <wpArgs...>` invocation's stdout/stderr live; return its exit code. */
    public function streamWp(array $wpArgs): int {
        passthru($this->wpCommand($wpArgs), $exitCode);
        return $exitCode;
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function captureWp(array $wpArgs): array {
        return self::runCapturing($this->wpCommand($wpArgs));
    }

    /**
     * Connect two target WP-CLI processes without materializing the producer's
     * stdout on either host. Exit status is captured for both sides, because
     * a consumer that cleanly authenticated EOF must not hide a failed export.
     *
     * @return array{exit:int, stdout:string, stderr:string}
     */
    public function captureWpPipeline(array $producerArgs, array $consumerArgs): array {
        $statusPath = tempnam(sys_get_temp_dir(), 'wprism-pipeline-');
        if ($statusPath === false) {
            return ['exit' => 1, 'stdout' => '', 'stderr' => 'could not create pipeline status boundary'];
        }
        @chmod($statusPath, 0600);
        $status = escapeshellarg($statusPath);
        $producer = $this->wpCommand($producerArgs);
        $consumer = $this->wpCommand($consumerArgs);
        $script = '{ ' . $producer . '; printf %s "$?" > ' . $status . '; } | ' . $consumer
            . '; c=$?; p=$(cat ' . $status . '); rm -f -- ' . $status
            . '; if [ "$p" != 0 ]; then exit "$p"; fi; exit "$c"';
        try {
            return self::runCapturing($script);
        } finally {
            @unlink($statusPath);
        }
    }

    /**
     * Stream one large machine-readable WP response into a caller-owned local
     * spool instead of concatenating stdout in PHP. Refresh exports can carry
     * base64 originals, so its envelope authority must apply while pipes are
     * drained; captureWp() remains intentionally unchanged for the many small
     * control responses whose established return shape is public API.
     *
     * @return array{exit:int,stderr:string,stdout_bytes:int,stdout_exceeded:bool,stderr_exceeded:bool,timed_out:bool,stdout_identity?:array{dev:string,ino:string,mode:int,size:int}}
     */
    public function captureWpToFile(
        array $wpArgs,
        string $stdoutPath,
        int $maxStdoutBytes,
        int $maxStderrBytes,
        int $timeoutNs
    ): array {
        return self::runCapturingToFile(
            $this->wpCommand($wpArgs),
            $stdoutPath,
            $maxStdoutBytes,
            $maxStderrBytes,
            $timeoutNs
        );
    }

    /**
     * Render the exact host-side command an operator can use for recovery.
     * Promotion checkpoints live inside the target environment, so a bare
     * `wp db import` instruction is insufficient for docker/ssh transports.
     */
    public function wpInstruction(array $wpArgs): string {
        return $this->wpCommand($wpArgs);
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function captureRaw(string $script): array {
        return self::runCapturing($this->rawCommand($script));
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function captureRawBounded(
        string $script,
        int $timeoutMilliseconds,
        int $maxStdoutBytes,
        int $maxStderrBytes
    ): array {
        return self::runCapturingBounded(
            $this->rawCommand($script),
            $timeoutMilliseconds,
            $maxStdoutBytes,
            $maxStderrBytes
        );
    }

    /**
     * @param list<string> $arguments
     * @return array{
     *   verified:bool,
     *   exit:int,
     *   stdout:string,
     *   stderr:string,
     *   transport_exit:int,
     *   transport_stderr:string,
     *   failure:?string
     * }
     */
    public function captureRawFramed(
        string $phpTupleProgram,
        array $arguments,
        int $timeoutMilliseconds,
        int $maxStdoutBytes,
        int $maxStderrBytes
    ): array {
        if ($phpTupleProgram === ''
            || strlen($phpTupleProgram) > self::RAW_CONTROL_FRAME_MAX_PROGRAM_BYTES
            || !array_is_list($arguments)
            || count($arguments) > self::RAW_CONTROL_FRAME_MAX_ARGUMENTS
            || $maxStdoutBytes < 1
            || $maxStdoutBytes > self::RAW_CONTROL_FRAME_MAX_INNER_BYTES
            || $maxStderrBytes < 1
            || $maxStderrBytes > self::RAW_CONTROL_FRAME_MAX_INNER_BYTES) {
            throw new \InvalidArgumentException('framed raw control inputs are outside the reviewed envelope');
        }
        $argumentBytes = 0;
        foreach ($arguments as $argument) {
            if (!is_string($argument)
                || str_contains($argument, "\0")) {
                throw new \InvalidArgumentException('framed raw control argument is outside the reviewed envelope');
            }
            $argumentBytes += strlen($argument);
            if ($argumentBytes > self::RAW_CONTROL_FRAME_MAX_ARGUMENT_BYTES) {
                throw new \InvalidArgumentException('framed raw control arguments exceed the reviewed envelope');
            }
        }

        $nonce = bin2hex(random_bytes(16));
        $format = var_export(self::RAW_CONTROL_FRAME_FORMAT, true);
        $wrapper = '$frameFormat = ' . $format . ";\n" . <<<'PHP'
$nonce = (string) ($argv[1] ?? '');
$maxStdoutBytes = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT);
$maxStderrBytes = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT);
if (preg_match('/^[a-f0-9]{32}$/D', $nonce) !== 1
    || !is_int($maxStdoutBytes) || $maxStdoutBytes < 1
    || !is_int($maxStderrBytes) || $maxStderrBytes < 1) {
    exit(64);
}
$arguments = array_slice($argv, 4);
$operation = static function (array $arguments): array {
PHP;
        $wrapper .= "\n" . $phpTupleProgram . "\n";
        $wrapper .= <<<'PHP'
};

@ini_set('display_errors', '0');
@ini_set('log_errors', '0');
error_reporting(E_ALL);
$priorLevel = ob_get_level();
ob_start();
set_error_handler(static function (int $severity): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException('framed raw control program emitted a PHP diagnostic', 0, $severity);
});
try {
    $tuple = $operation($arguments);
    $leaked = ob_get_clean();
} catch (Throwable $failure) {
    unset($failure);
    $leaked = '';
    while (ob_get_level() > $priorLevel) {
        $chunk = ob_get_clean();
        if (is_string($chunk)) {
            $leaked = $chunk . $leaked;
        }
    }
    $tuple = ['exit' => 255, 'stderr' => 'framed raw control program failed', 'stdout' => ''];
} finally {
    restore_error_handler();
}

$keys = is_array($tuple) ? array_keys($tuple) : [];
sort($keys, SORT_STRING);
if (!is_string($leaked) || $leaked !== ''
    || $keys !== ['exit', 'stderr', 'stdout']
    || !is_int($tuple['exit'] ?? null)
    || $tuple['exit'] < 0 || $tuple['exit'] > 255
    || !is_string($tuple['stdout'] ?? null)
    || !is_string($tuple['stderr'] ?? null)
    || strlen($tuple['stdout']) > $maxStdoutBytes
    || strlen($tuple['stderr']) > $maxStderrBytes) {
    $tuple = ['exit' => 255, 'stderr' => 'framed raw control program returned an invalid tuple', 'stdout' => ''];
}

$frame = [
    'exit' => $tuple['exit'],
    'format' => $frameFormat,
    'nonce' => $nonce,
    'stderr_base64' => base64_encode($tuple['stderr']),
    'stdout_base64' => base64_encode($tuple['stdout']),
];
$encoded = json_encode($frame, JSON_UNESCAPED_SLASHES);
if (!is_string($encoded)) {
    exit(65);
}
echo $encoded;
PHP;

        $command = 'php -r ' . escapeshellarg($wrapper)
            . ' ' . escapeshellarg($nonce)
            . ' ' . escapeshellarg((string) $maxStdoutBytes)
            . ' ' . escapeshellarg((string) $maxStderrBytes);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        // This redirect lives inside the raw target snippet, so PHP engine or
        // program stderr contaminates the exact frame and fails closed while
        // Docker/SSH lifecycle stderr remains on the outer transport pipe.
        $command .= ' 2>&1';
        $encodedBytes = 4 * (
            intdiv($maxStdoutBytes + 2, 3)
            + intdiv($maxStderrBytes + 2, 3)
        );
        $frameLimit = self::RAW_CONTROL_FRAME_OVERHEAD_BYTES + $encodedBytes;
        $outer = $this->captureRawBounded(
            $command,
            $timeoutMilliseconds,
            $frameLimit,
            self::RAW_CONTROL_FRAME_OUTER_STDERR_BYTES
        );
        $invalid = static fn(string $failure): array => [
            'verified' => false,
            'exit' => 255,
            'stdout' => '',
            'stderr' => '',
            'transport_exit' => (int) ($outer['exit'] ?? 255),
            'transport_stderr' => (string) ($outer['stderr'] ?? ''),
            'failure' => $failure,
        ];
        if (($outer['exit'] ?? 255) !== 0
            || !is_string($outer['stdout'] ?? null)
            || !is_string($outer['stderr'] ?? null)) {
            return $invalid('outer_failure');
        }
        try {
            $frame = json_decode($outer['stdout'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            unset($failure);
            return $invalid('invalid_frame');
        }
        if (!is_array($frame)
            || array_keys($frame) !== ['exit', 'format', 'nonce', 'stderr_base64', 'stdout_base64']
            || !is_int($frame['exit'] ?? null)
            || $frame['exit'] < 0 || $frame['exit'] > 255
            || ($frame['format'] ?? null) !== self::RAW_CONTROL_FRAME_FORMAT
            || !is_string($frame['nonce'] ?? null)
            || !hash_equals($nonce, $frame['nonce'])
            || !is_string($frame['stdout_base64'] ?? null)
            || !is_string($frame['stderr_base64'] ?? null)) {
            return $invalid('invalid_frame');
        }
        $canonical = json_encode($frame, JSON_UNESCAPED_SLASHES);
        $stdout = base64_decode($frame['stdout_base64'], true);
        $stderr = base64_decode($frame['stderr_base64'], true);
        if (!is_string($canonical)
            || !hash_equals($canonical, $outer['stdout'])
            || !is_string($stdout) || !is_string($stderr)
            || base64_encode($stdout) !== $frame['stdout_base64']
            || base64_encode($stderr) !== $frame['stderr_base64']
            || strlen($stdout) > $maxStdoutBytes
            || strlen($stderr) > $maxStderrBytes) {
            return $invalid('invalid_frame');
        }
        return [
            'verified' => true,
            'exit' => $frame['exit'],
            'stdout' => $stdout,
            'stderr' => $stderr,
            'transport_exit' => $outer['exit'],
            'transport_stderr' => $outer['stderr'],
            'failure' => null,
        ];
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function captureWpBounded(
        array $wpArgs,
        int $timeoutMilliseconds,
        int $maxStdoutBytes,
        int $maxStderrBytes
    ): array {
        return self::runCapturingBounded(
            $this->wpCommand($wpArgs),
            $timeoutMilliseconds,
            $maxStdoutBytes,
            $maxStderrBytes
        );
    }

    /**
     * The HOST directory this environment's repository is on, or null when the
     * host cannot write it (issue #3526).
     *
     * Null is the honest default and the safe one: a transport that keeps its
     * repository on the far side of itself — ssh today — cannot be resolved
     * from here, and every caller reads null as "ask the target instead"
     * rather than as "guess". Only LocalTransport (its repo_path IS a host
     * path) and DockerTransport (the host side of its bind mount) can answer.
     */
    public function hostRepoPath(): ?string {
        return null;
    }

    /** The prospective writable host boundary, including when it does not exist yet. */
    public function hostRepoBoundaryPath(): ?string {
        return null;
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    protected static function runCapturing(string $fullCommand): array {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($fullCommand, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return ['exit' => 255, 'stdout' => '', 'stderr' => 'failed to start process'];
        }
        fclose($pipes[0]);
        // Drain BOTH pipes concurrently. The previous sequential reads
        // (stream_get_contents(stdout) then stderr) deadlocked whenever the
        // child filled the ~64KB stderr pipe buffer before closing stdout:
        // the child blocks writing stderr, this process blocks reading stdout,
        // and neither ever proceeds. Measured 2026-08-24: the SSH rollback
        // certification's `wprism adopt` install script hung exactly there on two
        // consecutive runs (an idle sshd-session on the target, a live mux
        // client on the host, zero remote processes), and a 200KB-stderr
        // child reproduces the hang in isolation. select-based draining is
        // order-independent, so a chatty child can interleave its streams
        // however it likes.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buffers = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while ($open !== []) {
            $read = array_values($open);
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, null) === false) {
                break;
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
            }
        }
        foreach ($open as $stream) {
            fclose($stream);
        }
        $exit = proc_close($proc);
        return ['exit' => $exit, 'stdout' => $buffers[1], 'stderr' => $buffers[2]];
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    protected static function runCapturingBounded(
        string $fullCommand,
        int $timeoutMilliseconds,
        int $maxStdoutBytes,
        int $maxStderrBytes
    ): array {
        if ($timeoutMilliseconds < 1
            || $timeoutMilliseconds * 1000000 > self::MAX_BOUNDED_CONTROL_TIMEOUT_NS
            || $maxStdoutBytes < 1 || $maxStdoutBytes > self::MAX_CAPTURE_STDOUT_BYTES
            || $maxStderrBytes < 1 || $maxStderrBytes > self::MAX_CAPTURE_STDERR_BYTES) {
            throw new \InvalidArgumentException('bounded transport capture limits are outside the reviewed envelope');
        }
        $opened = ProcessGroup::open(
            $fullCommand,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]
        );
        if ($opened === null) {
            return ['exit' => 255, 'stdout' => '', 'stderr' => 'failed to start process'];
        }
        $proc = $opened['process'];
        $pipes = $opened['pipes'];
        $leader = $opened['leader'];
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buffers = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $deadline = hrtime(true) + ($timeoutMilliseconds * 1000000);
        $exitCode = null;
        while ($open !== [] || $exitCode === null) {
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) {
                $owned = $open;
                if (!ProcessGroup::terminateAndReap($proc, $owned, $leader)) {
                    return ['exit' => 125, 'stdout' => '', 'stderr' => 'transport command process group could not be reaped'];
                }
                return ['exit' => 124, 'stdout' => '', 'stderr' => 'transport command timed out'];
            }
            if ($open === []) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }
                usleep((int) min(self::PIPE_POLL_MICROSECONDS, max(1, intdiv($remaining, 1000))));
                continue;
            }
            $read = array_values($open);
            $write = null;
            $except = null;
            $selected = @stream_select(
                $read,
                $write,
                $except,
                intdiv($remaining, self::NANOS_PER_SECOND),
                intdiv($remaining % self::NANOS_PER_SECOND, 1000)
            );
            if ($selected === false) {
                $owned = $open;
                if (!ProcessGroup::terminateAndReap($proc, $owned, $leader)) {
                    return ['exit' => 125, 'stdout' => '', 'stderr' => 'transport command process group could not be reaped'];
                }
                return ['exit' => 125, 'stdout' => '', 'stderr' => 'could not read transport command output'];
            }
            if ($selected === 0) {
                continue;
            }
            foreach ($read as $stream) {
                $fd = $stream === $pipes[1] ? 1 : 2;
                $chunk = @fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    fclose($stream);
                    unset($open[$fd]);
                    continue;
                }
                if ($chunk === '') {
                    continue;
                }
                $limit = $fd === 1 ? $maxStdoutBytes : $maxStderrBytes;
                if (strlen($buffers[$fd]) + strlen($chunk) > $limit) {
                    $owned = $open;
                    if (!ProcessGroup::terminateAndReap($proc, $owned, $leader)) {
                        return ['exit' => 125, 'stdout' => '', 'stderr' => 'transport command process group could not be reaped'];
                    }
                    return ['exit' => 125, 'stdout' => '', 'stderr' => 'transport command output exceeded capture limit'];
                }
                $buffers[$fd] .= $chunk;
            }
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
            }
        }
        foreach ($open as $stream) {
            fclose($stream);
        }
        $closed = proc_close($proc);
        $proc = null;
        if (ProcessGroup::exists($leader)) {
            $owned = [];
            ProcessGroup::terminateAndReap($proc, $owned, $leader);
            return ['exit' => 125, 'stdout' => '', 'stderr' => 'transport command left descendants running'];
        }
        $exit = $closed === -1 && is_int($exitCode) && $exitCode >= 0 ? $exitCode : $closed;
        return ['exit' => $exit, 'stdout' => $buffers[1], 'stderr' => $buffers[2]];
    }

    /**
     * Concurrent bounded variant for a caller that will validate stdout from
     * a physical spool. Both pipe drains continue after termination so a
     * chatty target cannot deadlock the host at the same boundary that refuses
     * its oversized output.
     *
     * @return array{exit:int,stderr:string,stdout_bytes:int,stdout_exceeded:bool,stderr_exceeded:bool,timed_out:bool,stdout_identity?:array{dev:string,ino:string,mode:int,size:int}}
     */
    private static function runCapturingToFile(
        string $fullCommand,
        string $stdoutPath,
        int $maxStdoutBytes,
        int $maxStderrBytes,
        int $timeoutNs
    ): array {
        if ($maxStdoutBytes < 0 || $maxStdoutBytes > self::MAX_CAPTURE_STDOUT_BYTES
            || $maxStderrBytes < 0 || $maxStderrBytes > self::MAX_CAPTURE_STDERR_BYTES
            || $timeoutNs <= 0 || $timeoutNs > self::MAX_CAPTURE_TIMEOUT_NS
            || is_link($stdoutPath)) {
            return [
                'exit' => 255, 'stderr' => 'invalid bounded capture spool', 'stdout_bytes' => 0,
                'stdout_exceeded' => false, 'stderr_exceeded' => false, 'timed_out' => false,
            ];
        }
        $spool = @fopen($stdoutPath, 'wb');
        if (!is_resource($spool)) {
            return [
                'exit' => 255, 'stderr' => 'could not open bounded capture spool', 'stdout_bytes' => 0,
                'stdout_exceeded' => false, 'stderr_exceeded' => false, 'timed_out' => false,
            ];
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($fullCommand, $descriptors, $pipes);
        if (!is_resource($proc)) {
            fclose($spool);
            return [
                'exit' => 255, 'stderr' => 'failed to start process', 'stdout_bytes' => 0,
                'stdout_exceeded' => false, 'stderr_exceeded' => false, 'timed_out' => false,
            ];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $stderr = '';
        $stdoutBytes = 0;
        $stderrBytes = 0;
        $stdoutExceeded = false;
        $stderrExceeded = false;
        $timedOut = false;
        $executionDeadline = hrtime(true) + $timeoutNs;
        $terminated = false;
        $terminateDeadline = null;
        $killSent = false;
        $failure = null;
        $identity = null;
        $exit = 255;
        try {
            while ($open !== []) {
                $now = hrtime(true);
                if (!$terminated && $now >= $executionDeadline) {
                    // Refresh never accepts an operator-controlled timeout:
                    // its caller supplies one reviewed ceiling. A silent or
                    // under-cap target must therefore terminate just as an
                    // oversized one does, before it can occupy the host.
                    @proc_terminate($proc);
                    $timedOut = true;
                    $terminated = true;
                    $terminateDeadline = $now + self::TERMINATION_GRACE_NS;
                }
                if ($terminated && !$killSent
                    && is_int($terminateDeadline)
                    && $now >= $terminateDeadline) {
                    // A target can ignore TERM while continuously filling
                    // stdout. The deadline is monotonic, not "quiet pipe"
                    // based, so hostile output cannot postpone reaping.
                    @proc_terminate($proc, 9);
                    $killSent = true;
                }
                $read = array_values($open);
                $write = null;
                $except = null;
                if ($terminated) {
                    $selected = @stream_select($read, $write, $except, 0, self::PIPE_POLL_MICROSECONDS);
                } else {
                    $remaining = max(0, $executionDeadline - hrtime(true));
                    $selected = @stream_select(
                        $read,
                        $write,
                        $except,
                        intdiv($remaining, self::NANOS_PER_SECOND),
                        intdiv($remaining % self::NANOS_PER_SECOND, 1000)
                    );
                }
                if ($selected === false) {
                    throw new \RuntimeException('bounded capture pipe selection failed');
                }
                if ($selected === 0) {
                    if (!$terminated) {
                        continue;
                    }
                    // A target that ignored the initial TERM must not keep a
                    // failed/capped host capture alive. The drain has its own
                    // monotonic KILL/deadline and reaps it below.
                    self::drainTerminatedPipes($proc, $open);
                    break;
                }
                foreach ($read as $stream) {
                    $fd = $stream === $pipes[1] ? 1 : 2;
                    $chunk = @fread($stream, 65536);
                    if ($chunk === false) {
                        throw new \RuntimeException('bounded capture pipe read failed');
                    }
                    if ($chunk === '' && feof($stream)) {
                        fclose($stream);
                        unset($open[$fd]);
                        continue;
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    $length = strlen($chunk);
                    if ($fd === 1) {
                        if ($length > $maxStdoutBytes - $stdoutBytes) {
                            $stdoutExceeded = true;
                        } else {
                            self::writeAll($spool, $chunk);
                            $stdoutBytes += $length;
                        }
                    } else {
                        if ($length > $maxStderrBytes - $stderrBytes) {
                            $stderrExceeded = true;
                            $remaining = max(0, $maxStderrBytes - $stderrBytes);
                            if ($remaining > 0) {
                                $stderr .= substr($chunk, 0, $remaining);
                                $stderrBytes += $remaining;
                            }
                        } else {
                            $stderr .= $chunk;
                            $stderrBytes += $length;
                        }
                    }
                    if (!$terminated && ($stdoutExceeded || $stderrExceeded)) {
                        @proc_terminate($proc);
                        $terminated = true;
                        $terminateDeadline = hrtime(true) + self::TERMINATION_GRACE_NS;
                    }
                }
            }
            if (@fflush($spool) !== true) {
                throw new \RuntimeException('bounded capture spool flush failed');
            }
            if (!$stdoutExceeded && !$stderrExceeded && !$timedOut) {
                $identity = self::spoolIdentity($spool, $stdoutPath, $stdoutBytes);
            }
        } catch (\Throwable $caught) {
            $failure = $caught;
            if (!$terminated) {
                // A local write/read failure is not a target failure. Kill
                // first, then drain/reap in finally, so the host cannot leak
                // a child or zombie when its spool is full or unavailable.
                @proc_terminate($proc, 9);
                $terminated = true;
                $killSent = true;
            }
        } finally {
            if ($open !== []) {
                self::drainTerminatedPipes($proc, $open);
            }
            fclose($spool);
            $closed = proc_close($proc);
            $exit = is_int($closed) ? $closed : 255;
        }
        if ($failure !== null) {
            $stderr = $stderr === '' ? 'bounded capture spool failed' : $stderr . "\nbounded capture spool failed";
            $exit = 255;
        }
        if ($timedOut) {
            $stderr = $stderr === ''
                ? 'bounded capture execution deadline exceeded'
                : $stderr . "\nbounded capture execution deadline exceeded";
            $exit = 255;
        }
        return [
            'exit' => $exit,
            'stderr' => $stderr,
            'stdout_bytes' => $stdoutBytes,
            'stdout_exceeded' => $stdoutExceeded,
            'stderr_exceeded' => $stderrExceeded,
            'timed_out' => $timedOut,
            ...($identity === null ? [] : ['stdout_identity' => $identity]),
        ];
    }

    /**
     * A capture can be terminated while the target is still holding either
     * pipe. Continue draining without retaining bytes, but escalate on a
     * monotonic deadline even when a hostile child remains readable; then
     * close and proc_close() reaps the exact child before this method returns.
     *
     * @param resource $proc
     * @param array<int,resource> $open
     */
    private static function drainTerminatedPipes($proc, array &$open): void {
        $killed = false;
        $killDeadline = hrtime(true) + self::TERMINATION_GRACE_NS;
        $drainDeadline = hrtime(true) + self::DRAIN_DEADLINE_NS;
        while (hrtime(true) < $drainDeadline) {
            $status = proc_get_status($proc);
            if (!$status['running'] && $open === []) {
                break;
            }
            if (!$killed && hrtime(true) >= $killDeadline) {
                @proc_terminate($proc, 9);
                $killed = true;
            }
            if ($open === []) {
                usleep(self::PIPE_POLL_MICROSECONDS);
                continue;
            }
            $read = array_values($open);
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, 0, self::PIPE_POLL_MICROSECONDS);
            if ($selected === false) {
                break;
            }
            if ($selected === 0) {
                continue;
            }
            foreach ($read as $stream) {
                $fd = $stream === ($open[1] ?? null) ? 1 : 2;
                $chunk = @fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    fclose($stream);
                    unset($open[$fd]);
                }
            }
        }
        $status = proc_get_status($proc);
        if ($status['running']) {
            @proc_terminate($proc, 9);
        }
        foreach ($open as $stream) {
            fclose($stream);
        }
        $open = [];
    }

    /**
     * Bind Refresh's later pathname read to the inode we wrote through. The
     * transport sees both fstat(open handle) and lstat(path) before closing;
     * MediaPayloadAuthority repeats that same identity at every read phase.
     *
     * @param resource $spool
     * @return array{dev:string,ino:string,mode:int,size:int}
     */
    private static function spoolIdentity($spool, string $path, int $expectedSize): array {
        clearstatcache(true, $path);
        $opened = @fstat($spool);
        $named = @lstat($path);
        $openedSize = is_array($opened) ? ($opened['size'] ?? null) : null;
        $namedSize = is_array($named) ? ($named['size'] ?? null) : null;
        $openedMode = is_array($opened) ? ($opened['mode'] ?? null) : null;
        $namedMode = is_array($named) ? ($named['mode'] ?? null) : null;
        if (!is_array($opened)
            || !is_array($named)
            || !is_int($openedSize)
            || !is_int($namedSize)
            || !is_int($openedMode)
            || !is_int($namedMode)
            || ($openedMode & 0170000) !== 0100000
            || ($namedMode & 0170000) !== 0100000
            || ($openedMode & 0777) !== 0600
            || ($namedMode & 0777) !== 0600
            || $openedSize !== $expectedSize
            || $namedSize !== $expectedSize
            || (string) ($opened['dev'] ?? '') !== (string) ($named['dev'] ?? '')
            || (string) ($opened['ino'] ?? '') !== (string) ($named['ino'] ?? '')
            || $openedMode !== $namedMode) {
            throw new \RuntimeException('bounded capture spool identity changed before handoff');
        }
        return [
            'dev' => (string) $opened['dev'],
            'ino' => (string) $opened['ino'],
            'mode' => $openedMode,
            'size' => $openedSize,
        ];
    }

    /** @param resource $stream */
    private static function writeAll($stream, string $bytes): void {
        // This one fault hook exists only in the offline regression's child
        // process; an actual transport never sets WPRISM_TEST_MODE. Its purpose
        // is to prove the exceptional write path terminates and reaps rather
        // than merely closing the local descriptor.
        if (getenv('WPRISM_TEST_MODE') === '1'
            && is_callable($GLOBALS['wprism_transport_spool_write_fault'] ?? null)) {
            ($GLOBALS['wprism_transport_spool_write_fault'])();
        }
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = @fwrite($stream, substr($bytes, $offset));
            if (!is_int($written) || $written <= 0) {
                throw new \RuntimeException('bounded capture spool write failed');
            }
            $offset += $written;
        }
    }

    /** @param array<string, mixed> $cfg */
    protected static function requireKey(array $cfg, string $envName, string $key): string {
        $v = $cfg[$key] ?? null;
        if (!is_string($v) || $v === '') {
            $type = $cfg['transport'] ?? '?';
            throw new \RuntimeException("env '$envName': missing required key '$key' for transport '$type'");
        }
        return $v;
    }

    /**
     * Resolve a possibly-relative filesystem path against the directory that
     * defined it. Public because `RecoveryConfig::parse()` resolves
     * `rollback_signing_key` for whichever transport is parsing it, and a
     * private copy there would be a second answer to one question.
     */
    public static function resolvePath(string $dir, string $path): string {
        if ($path === '' || $path[0] === '/') {
            return $path;
        }
        $joined = rtrim($dir, '/') . '/' . $path;
        return realpath($joined) ?: $joined;
    }

    /** Escape a single token. */
    protected static function esc(string $s): string {
        return escapeshellarg($s);
    }

    /** Escape every token and join with spaces. */
    protected static function tokens(array $tokens): string {
        return implode(' ', array_map('escapeshellarg', $tokens));
    }
}
