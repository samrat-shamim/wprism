<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

/**
 * Runs wp-cli inside a compose service.
 *
 * Default (`mode` absent, or `"run"`): `docker compose -f … run --rm -T
 * <service> wp …` — a fresh container per call.
 *
 * issue #3513 opt-in (`mode: "exec"`): `docker compose -f … exec -T <service>
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
    private const CONTROL_PLANE_TIMEOUT_MILLISECONDS = 30000;
    private const CONTROL_PLANE_OUTPUT_LIMIT_BYTES = 1048576;

    private string $composeFile;
    private ?string $composeEnvFile;
    private ?string $profile;
    private string $service;
    private string $mode;

    /** @var callable(): bool */
    private $serviceRunningProbe;

    /** @var callable(string,int,int,int):array{exit:int,stdout:string,stderr:string} */
    private $controlPlaneCapture;

    /**
     * Cached per DockerTransport instance. cli/wprism builds exactly one
     * Transport per `wprism` invocation and reuses it for the whole dispatch
     * (see cli/wprism's `$transport = Transport::make(...)` call site plus the
     * verb-dispatch match arm), so an instance property already gives the
     * "probe once per process" contract the issue asks for without any
     * global/static state.
     */
    private ?bool $serviceRunning = null;

    public function __construct(
        string $name,
        array $cfg,
        ?callable $serviceRunningProbe = null,
        ?callable $controlPlaneCapture = null
    ) {
        parent::__construct($name, $cfg);
        $dir = is_string($cfg['_dir'] ?? null) ? $cfg['_dir'] : (getcwd() ?: '.');
        $this->composeFile = self::resolvePath($dir, self::requireKey($cfg, $name, 'compose_file'));
        $composeEnvFile = $cfg['compose_env_file'] ?? null;
        if ($composeEnvFile !== null && (!is_string($composeEnvFile) || $composeEnvFile === '')) {
            throw new \RuntimeException("env '$name': optional key 'compose_env_file' must be a non-empty path string");
        }
        $this->composeEnvFile = is_string($composeEnvFile)
            ? self::resolvePath($dir, $composeEnvFile)
            : null;
        if ($this->composeEnvFile !== null && !is_file($this->composeEnvFile)) {
            throw new \RuntimeException("env '$name': compose_env_file not found: {$this->composeEnvFile}");
        }
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
        $this->controlPlaneCapture = $controlPlaneCapture ?? static fn(
            string $command,
            int $timeout,
            int $stdoutLimit,
            int $stderrLimit
        ): array => self::runCapturingBounded($command, $timeout, $stdoutLimit, $stderrLimit);
    }

    public function describe(): string {
        $profile = $this->profile !== null ? " profile={$this->profile}" : '';
        // Appended only when non-default so `describe()` — and therefore
        // `wprism envs` — stays byte-identical for every environment that
        // never opted in (rule 8).
        $mode = $this->mode !== 'run' ? " mode={$this->mode}" : '';
        $envFile = $this->composeEnvFile !== null ? " compose_env_file={$this->composeEnvFile}" : '';
        return "docker compose_file={$this->composeFile}{$envFile}{$profile}{$mode} service={$this->service} repo_path={$this->repoPath}";
    }

    /**
     * The HOST directory this environment's `repo_path` is bind-mounted from,
     * or null when the host cannot write it.
     *
     * ## Why the transport owns this and not the caller
     *
     * A docker environment's defining property is that its repository IS on
     * this filesystem — the bind mount is how `pair.sh` and `wprism deploy` from
     * inside a checkout already work. But only the compose file knows WHERE:
     * `repo_path` is the CONTAINER path (`/siterepo` for both sides of a
     * pair), so two environments of one pair are indistinguishable by config
     * alone. Before issue #3526 the host guessed with
     * `CodeResolver::locateSiteRepo(getcwd())`, which answers for the
     * directory the operator happens to stand in — the SOURCE repository
     * during a rehearse, not the target — so a resolve could report success
     * against a repository nobody asked about while the target stayed empty.
     * The compose service knows the answer exactly; ask it.
     *
     * `config` is used rather than `inspect` deliberately: it resolves the
     * same file, profile and interpolation environment this transport itself
     * runs with, and needs no container to exist yet.
     *
     * Null — never a guess — only when valid Compose data proves that repo_path
     * is not a writable bind. Invocation, JSON, service and ambiguous-mount
     * failures throw, because continuing would skip Connect's host-overlap gate.
     */
    public function hostRepoPath(): ?string {
        $source = $this->hostRepoBoundaryPath();
        return $source !== null && is_dir($source) ? $source : null;
    }

    public function hostRepoBoundaryPath(): ?string {
        $tokens = array_merge($this->baseTokens(), ['config', '--format', 'json']);
        $result = $this->captureControlPlane($tokens);
        if ($result['exit'] !== 0) {
            throw new \RuntimeException(
                'Docker Compose repository-mount inspection failed closed: ' . trim($result['stderr'])
            );
        }
        try {
            $config = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Docker Compose repository-mount inspection returned malformed JSON');
        }
        $services = is_array($config) ? ($config['services'] ?? null) : null;
        $service = is_array($services) ? ($services[$this->service] ?? null) : null;
        if (!is_array($service)) {
            throw new \RuntimeException("Docker Compose config does not contain service '{$this->service}'");
        }
        $volumes = $service['volumes'] ?? [];
        if (!is_array($volumes)) {
            throw new \RuntimeException("Docker Compose service '{$this->service}' has malformed volumes");
        }
        $want = rtrim($this->repoPath, '/');
        $matches = [];
        foreach ($volumes as $volume) {
            if (!is_array($volume) || !is_string($volume['target'] ?? null)) {
                throw new \RuntimeException("Docker Compose service '{$this->service}' has an ambiguous volume entry");
            }
            if (rtrim($volume['target'], '/') !== $want) {
                continue;
            }
            $matches[] = $volume;
        }
        if (count($matches) > 1) {
            throw new \RuntimeException("Docker Compose service '{$this->service}' has ambiguous repo_path mounts");
        }
        if ($matches === []) {
            return null;
        }
        $volume = $matches[0];
        $type = $volume['type'] ?? null;
        $readOnly = $volume['read_only'] ?? false;
        if (!is_string($type) || !is_bool($readOnly)) {
            throw new \RuntimeException("Docker Compose service '{$this->service}' has an ambiguous repo_path mount");
        }
        if ($type === 'volume' || $readOnly) {
            return null;
        }
        $source = $volume['source'] ?? null;
        if ($type !== 'bind' || !is_string($source) || $source === '' || !str_starts_with($source, '/')) {
            throw new \RuntimeException("Docker Compose service '{$this->service}' has an ambiguous writable repo_path mount");
        }
        return rtrim($source, '/');
    }

    private function baseTokens(): array {
        $t = ['docker', 'compose'];
        if ($this->composeEnvFile !== null) {
            $t[] = '--env-file';
            $t[] = $this->composeEnvFile;
        }
        array_push($t, '-f', $this->composeFile);
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
        $result = $this->captureControlPlane(array_merge(
            $this->baseTokens(),
            ['ps', '--status=running', '--services']
        ));
        if ($result['exit'] !== 0) {
            return false;
        }
        $running = array_map('trim', explode("\n", $result['stdout']));
        return in_array($this->service, $running, true);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function captureControlPlane(array $tokens): array {
        $result = ($this->controlPlaneCapture)(
            self::tokens($tokens),
            self::CONTROL_PLANE_TIMEOUT_MILLISECONDS,
            self::CONTROL_PLANE_OUTPUT_LIMIT_BYTES,
            self::CONTROL_PLANE_OUTPUT_LIMIT_BYTES
        );
        if (!is_array($result)
            || !is_int($result['exit'] ?? null)
            || !is_string($result['stdout'] ?? null)
            || !is_string($result['stderr'] ?? null)) {
            throw new \RuntimeException('Docker control-plane capture returned a malformed result');
        }
        return $result;
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
