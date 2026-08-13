<?php
namespace Duo\Orchestrator;

require_once __DIR__ . '/EnvironmentDriver.php';

/**
 * Exact command surface required by the adoption transaction.
 *
 * Keeping this boundary narrower than SshTransport makes the double-failure
 * contract executable offline without coupling the transaction to one
 * transport. The capability row is target-free: `duo adopt` performs a
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
 * describe itself for `duo envs`. Every environment carries a `repo_path`
 * — the site-repo path as seen *from inside that environment* — regardless
 * of transport.
 *
 * Command strings are assembled with escapeshellarg() on every variable
 * token, then executed either streamed (proc_open with inherited standard
 * streams, so the user sees exactly what `wp duo …` would print locally) or
 * captured (proc_open with separate stdout/stderr pipes — for doctor/status,
 * which parse output and must not have it corrupted by e.g. `docker compose
 * run`'s own container-lifecycle chatter, which lands on stderr).
 */
abstract class Transport implements EnvironmentDriver {
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

    /** One-line description for `duo envs`. */
    abstract public function describe(): string;

    /** Build the full, already-escaped shell command that runs `wp <wpArgs...>`. */
    abstract protected function wpCommand(array $wpArgs): string;

    /** Build the full, already-escaped shell command that runs a raw shell snippet. */
    abstract protected function rawCommand(string $script): string;

    /** Stream a `wp <wpArgs...>` invocation's stdout/stderr live; return its exit code. */
    public function streamWp(array $wpArgs): int {
        // Replace the intermediate shell with the transport command. Besides
        // preserving inherited stdin/stdout/stderr, this gives shutdown
        // cleanup one process to terminate if an async host signal exits while
        // an interactive target command is still waiting for input.
        $proc = proc_open(
            'exec ' . $this->wpCommand($wpArgs),
            [0 => STDIN, 1 => STDOUT, 2 => STDERR],
            $pipes
        );
        if (!is_resource($proc)) {
            return 255;
        }

        $streamActive = true;
        register_shutdown_function(static function () use ($proc, &$streamActive): void {
            if (!$streamActive || !is_resource($proc)) {
                return;
            }
            $status = proc_get_status($proc);
            if (!empty($status['running'])) {
                proc_terminate($proc, defined('SIGTERM') ? SIGTERM : 15);
                $deadline = microtime(true) + 0.25;
                do {
                    usleep(10000);
                    $status = proc_get_status($proc);
                } while (!empty($status['running']) && microtime(true) < $deadline);
                if (!empty($status['running'])) {
                    proc_terminate($proc, defined('SIGKILL') ? SIGKILL : 9);
                }
            }
        });

        do {
            $status = proc_get_status($proc);
            if (!empty($status['running'])) {
                usleep(10000);
            }
        } while (!empty($status['running']));

        $streamActive = false;
        $closeExit = proc_close($proc);
        $observedExit = is_int($status['exitcode'] ?? null) ? $status['exitcode'] : -1;
        if ($observedExit >= 0) {
            return $observedExit;
        }
        if (!empty($status['signaled']) && is_int($status['termsig'] ?? null)) {
            return 128 + $status['termsig'];
        }
        return $closeExit >= 0 ? $closeExit : 255;
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function captureWp(array $wpArgs): array {
        return self::runCapturing($this->wpCommand($wpArgs));
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
    protected static function runCapturing(string $fullCommand): array {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($fullCommand, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return ['exit' => 255, 'stdout' => '', 'stderr' => "failed to start process"];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
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

    /** Resolve a possibly-relative filesystem path against the directory that defined it. */
    protected static function resolvePath(string $dir, string $path): string {
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
