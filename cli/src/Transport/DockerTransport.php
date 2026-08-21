<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * Runs wp-cli inside a compose service.
 *
 * Default (`mode` absent, or `"run"`): `docker compose -f … run --rm -T
 * <service> wp …` — a fresh container per call.
 *
 * DUO-3513 opt-in (`mode: "exec"`): `docker compose -f … exec -T <service>
 * wp …` against an already-running container. Measured on this host:
 * `exec -T` floors at ~0.14s versus `run --rm`'s ~0.40s container create +
 * ~0.51s dependency resolution (the legacy docker-compose.yml estate only —
 * sandbox/pair.yml deliberately has no depends_on, see its own header and
 * docs/sandbox.md) + ~0.33s wp-cli startup — ~0.77s/call saved on the legacy
 * estate, ~0.26s/call on a pair. The saving is real but not free: unlike
 * `run --rm`, `exec` does not create a fresh container per call, so wp-cli's
 * own cache/tmp residue persists across calls, and any env/mount edit on the
 * service goes stale until the operator recreates it (docs/sandbox.md,
 * cli/README.md). `exec` also requires the target container to already be
 * up — `wordpress:cli`'s default CMD (`wp shell`) exits immediately without
 * a TTY, so a resident service needs its own `command: ["tail","-f",
 * "/dev/null"]` (or equivalent) to stay alive between calls.
 */
final class DockerTransport extends Transport {
    private const MODES = ['run', 'exec'];

    private string $composeFile;
    private ?string $profile;
    private string $service;
    private string $mode;

    /** @var callable(): bool */
    private $serviceRunningProbe;

    /**
     * Cached per DockerTransport instance. cli/duo builds exactly one
     * Transport per `duo` invocation and reuses it for the whole dispatch
     * (see cli/duo's `$transport = Transport::make(...)` call site plus the
     * verb-dispatch match arm), so an instance property already gives the
     * "probe once per process" contract the issue asks for without any
     * global/static state.
     */
    private ?bool $serviceRunning = null;

    public function __construct(string $name, array $cfg, ?callable $serviceRunningProbe = null) {
        parent::__construct($name, $cfg);
        $dir = is_string($cfg['_dir'] ?? null) ? $cfg['_dir'] : (getcwd() ?: '.');
        $this->composeFile = self::resolvePath($dir, self::requireKey($cfg, $name, 'compose_file'));
        $profile = $cfg['profile'] ?? null;
        $this->profile = (is_string($profile) && $profile !== '') ? $profile : null;
        $this->service = self::requireKey($cfg, $name, 'service');

        // Strict, not lenient like `profile` above: an unrecognized `mode`
        // must refuse loudly rather than silently coerce to the (much
        // slower but always-correct) `run` default — the whole point of
        // this key is an explicit, informed opt-in (rule 9: no silent
        // fallbacks).
        $mode = $cfg['mode'] ?? null;
        if ($mode === null) {
            $this->mode = 'run';
        } elseif (in_array($mode, self::MODES, true)) {
            $this->mode = $mode;
        } else {
            throw new \RuntimeException(
                "env '$name': invalid value for key 'mode': " . self::describeValue($mode)
                . ' (expected "run" or "exec")'
            );
        }

        // Seam for offline testing (sandbox/tests/offline/environment/
        // regress_docker_exec_mode.php): the suite injects a fixed answer
        // here so the exec-mode precondition is exercised without a docker
        // daemon. Production leaves this null and gets the real probe.
        $this->serviceRunningProbe = $serviceRunningProbe ?? function (): bool {
            return $this->defaultServiceRunningProbe();
        };
    }

    public function describe(): string {
        $profile = $this->profile !== null ? " profile={$this->profile}" : '';
        // Appended only when non-default so `describe()` — and therefore
        // `duo envs` — stays byte-identical for every environment that
        // never opted in (rule 8).
        $mode = $this->mode !== 'run' ? " mode={$this->mode}" : '';
        return "docker compose_file={$this->composeFile}{$profile}{$mode} service={$this->service} repo_path={$this->repoPath}";
    }

    private function baseTokens(): array {
        $t = ['docker', 'compose', '-f', $this->composeFile];
        if ($this->profile !== null) {
            $t[] = '--profile';
            $t[] = $this->profile;
        }
        return $t;
    }

    protected function wpCommand(array $wpArgs): string {
        $verb = $this->mode === 'exec' ? ['exec', '-T'] : ['run', '--rm', '-T'];
        return $this->tokensOrPreconditionFailure(array_merge($this->baseTokens(), $verb, [$this->service, 'wp'], $wpArgs));
    }

    protected function rawCommand(string $script): string {
        // `run`'s and `exec`'s trailing args both become the container's
        // argv directly, not a shell command line — go through bash -c for
        // test operators, &&, pipes, etc. (same pattern the spike scripts
        // use for raw commands against the cli-* services).
        $verb = $this->mode === 'exec' ? ['exec', '-T'] : ['run', '--rm', '-T'];
        return $this->tokensOrPreconditionFailure(array_merge($this->baseTokens(), $verb, [$this->service, 'bash', '-c', $script]));
    }

    /**
     * `exec` targets a container that must already be resident; `run --rm`
     * creates one on demand instead. Skipping this precondition would let a
     * stopped service either surface docker's own opaque "service ... is
     * not running" error, or — far worse — invite a silent fallback to
     * `run`, which would defeat the entire reason `mode: exec` exists (rule
     * 9). The probe runs at most once per process ($serviceRunning caches
     * it) and, on failure, this is the single choke point every wp/raw
     * command string is built through — captureWp/captureRaw/streamWp
     * (Transport.php) AND wpInstruction (env-set's stdin handoff via
     * PassthroughCommand::streamWpInput) — so none of them can reach a
     * docker command line while the precondition is unmet; every one of
     * them instead executes this local, docker-free failure fragment and
     * reports the exact remedy.
     */
    private function tokensOrPreconditionFailure(array $tokens): string {
        if ($this->mode !== 'exec' || $this->isServiceRunning()) {
            return self::tokens($tokens);
        }
        $profile = $this->profile !== null ? " --profile {$this->profile}" : '';
        $message = "env '{$this->name}': transport mode \"exec\" requires service '{$this->service}' to be running. "
            . "remedy: docker compose -f {$this->composeFile}{$profile} up -d {$this->service}";
        return 'echo ' . self::esc($message) . ' 1>&2; exit 1';
    }

    private function isServiceRunning(): bool {
        if ($this->serviceRunning === null) {
            $this->serviceRunning = ($this->serviceRunningProbe)();
        }
        return $this->serviceRunning;
    }

    private function defaultServiceRunningProbe(): bool {
        $result = self::runCapturing(self::tokens(array_merge(
            $this->baseTokens(),
            ['ps', '--status=running', '--services']
        )));
        if ($result['exit'] !== 0) {
            return false;
        }
        $running = array_map('trim', explode("\n", $result['stdout']));
        return in_array($this->service, $running, true);
    }

    private static function describeValue(mixed $value): string {
        return match (true) {
            is_string($value) => "'$value'",
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }
}
