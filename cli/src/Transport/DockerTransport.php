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
final class DockerTransport extends Transport implements IdentityBoundAdoptionTransport, PersistentAdoptionArchiveTransport {
    public const BOOTSTRAP_FORMAT = 'wprism-local-docker-control-plane/v1';
    public const TOOLING_PROVENANCE_FORMAT = 'wprism-managed-docker-tooling/v1';
    private const MODES = ['run', 'exec'];
    private const CONTROL_PLANE_TIMEOUT_MILLISECONDS = 30000;
    private const CONTROL_PLANE_OUTPUT_LIMIT_BYTES = 1048576;

    private string $composeFile;
    private ?string $composeOverlayFile;
    private ?string $composeEnvFile;
    private ?string $profile;
    private ?string $composeProject;
    private string $service;
    private ?string $dockerContext = null;
    private ?string $dockerEndpoint = null;
    private string $wpPath = '';
    private ?string $wordpressService = null;
    private string $mode;
    private bool $bootstrapAuthorized = false;

    /** @var ?array<string,string> */
    private ?array $toolingProvenance = null;

    /** @var ?array<string,mixed> */
    private ?array $composeConfig = null;

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
        $composeOverlayFile = $cfg['compose_overlay_file'] ?? null;
        if ($composeOverlayFile !== null && (!is_string($composeOverlayFile) || $composeOverlayFile === '')) {
            throw new \RuntimeException("env '$name': optional key 'compose_overlay_file' must be a non-empty path string");
        }
        $this->composeOverlayFile = is_string($composeOverlayFile)
            ? self::resolvePath($dir, $composeOverlayFile)
            : null;
        if ($this->composeOverlayFile !== null && !is_file($this->composeOverlayFile)) {
            throw new \RuntimeException("env '$name': compose_overlay_file not found: {$this->composeOverlayFile}");
        }
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
        $composeProject = $cfg['compose_project'] ?? null;
        if ($composeProject !== null && (!is_string($composeProject)
            || preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $composeProject) !== 1)) {
            throw new \RuntimeException("env '$name': optional key 'compose_project' is malformed");
        }
        $this->composeProject = is_string($composeProject) ? $composeProject : null;
        $this->service = self::requireKey($cfg, $name, 'service');
        $context = $cfg['docker_context'] ?? null;
        $endpoint = $cfg['docker_endpoint'] ?? null;
        if (($context === null) !== ($endpoint === null)
            || ($context !== null && (!is_string($context)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $context) !== 1
                || !is_string($endpoint)
                || (!str_starts_with($endpoint, 'unix://') && !str_starts_with($endpoint, 'npipe://'))))) {
            throw new \RuntimeException("env '$name': Docker context provenance is malformed");
        }
        $this->dockerContext = is_string($context) ? $context : null;
        $this->dockerEndpoint = is_string($endpoint) ? $endpoint : null;
        $hasBootstrap = array_key_exists('bootstrap', $cfg);
        if ($hasBootstrap) {
            $bootstrap = $cfg['bootstrap'];
            $keys = is_array($bootstrap) ? array_keys($bootstrap) : [];
            sort($keys, SORT_STRING);
            if ($keys !== ['format'] || ($bootstrap['format'] ?? null) !== self::BOOTSTRAP_FORMAT) {
                throw new \RuntimeException(
                    "env '$name': Docker bootstrap must be exactly "
                    . '{"format":"' . self::BOOTSTRAP_FORMAT . '"}'
                );
            }
            $this->wpPath = self::requireKey($cfg, $name, 'wp_path');
            $this->wordpressService = self::requireKey($cfg, $name, 'wordpress_service');
            if (!self::safeContainerPath($this->wpPath) || !self::safeContainerPath($this->repoPath)) {
                throw new \RuntimeException(
                    "env '$name': Docker bootstrap requires absolute, normalized, non-root wp_path and repo_path"
                );
            }
        } elseif (is_string($cfg['wp_path'] ?? null)) {
            $this->wpPath = (string) $cfg['wp_path'];
        }
        $this->bootstrapAuthorized = $hasBootstrap && ($cfg['_machine_local'] ?? null) === true;
        $tooling = $cfg['tooling'] ?? null;
        if ($tooling !== null && $tooling !== 'managed') {
            throw new \RuntimeException("env '$name': Docker tooling must be managed when present");
        }
        if ($tooling === 'managed') {
            $provenance = $cfg['tooling_provenance'] ?? null;
            $keys = is_array($provenance) ? array_keys($provenance) : [];
            sort($keys, SORT_STRING);
            $expected = [
                'format', 'image_id', 'overlay_sha256', 'repository_volume',
                'toolchain_identity', 'tooling_identity',
            ];
            if ($keys !== $expected || ($provenance['format'] ?? null) !== self::TOOLING_PROVENANCE_FORMAT) {
                throw new \RuntimeException("env '$name': managed Docker tooling provenance is malformed");
            }
            foreach (['overlay_sha256', 'toolchain_identity', 'tooling_identity'] as $digest) {
                if (!is_string($provenance[$digest] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D', $provenance[$digest]) !== 1) {
                    throw new \RuntimeException("env '$name': managed Docker tooling provenance is malformed");
                }
            }
            if (!is_string($provenance['image_id'] ?? null)
                || preg_match('/^sha256:[a-f0-9]{64}$/D', $provenance['image_id']) !== 1
                || !is_string($provenance['repository_volume'] ?? null)
                || preg_match('/^wprism-managed-repository-[a-f0-9]{16}$/D', $provenance['repository_volume']) !== 1) {
                throw new \RuntimeException("env '$name': managed Docker tooling provenance is malformed");
            }
            if (!$hasBootstrap || $this->composeOverlayFile === null) {
                throw new \RuntimeException("env '$name': managed Docker tooling requires bootstrap and its private overlay");
            }
            /** @var array<string,string> $provenance */
            $this->toolingProvenance = $provenance;
        } elseif (array_key_exists('tooling_provenance', $cfg)) {
            throw new \RuntimeException("env '$name': Docker tooling provenance requires tooling=managed");
        }

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
        $project = $this->composeProject !== null ? " compose_project={$this->composeProject}" : '';
        // Appended only when non-default so `describe()` — and therefore
        // `wprism envs` — stays byte-identical for every environment that
        // never opted in (rule 8).
        $mode = $this->mode !== 'run' ? " mode={$this->mode}" : '';
        $envFile = $this->composeEnvFile !== null ? " compose_env_file={$this->composeEnvFile}" : '';
        $overlay = $this->composeOverlayFile !== null ? " compose_overlay_file={$this->composeOverlayFile}" : '';
        $bootstrap = $this->bootstrapAuthorized
            ? " wp_path={$this->wpPath} wordpress_service={$this->wordpressService} bootstrap=authorized"
            : '';
        return "docker compose_file={$this->composeFile}{$overlay}{$envFile}{$profile}{$project}{$mode} service={$this->service} repo_path={$this->repoPath}{$bootstrap}";
    }

    public function wpPath(): string {
        if ($this->wpPath === '') {
            throw new \RuntimeException("env '{$this->name}': Docker adoption requires wp_path");
        }
        return $this->wpPath;
    }

    public function adoptionArchivePath(string $token): string {
        if (preg_match('/^[a-f0-9]{24}$/D', $token) !== 1) {
            throw new \RuntimeException('Docker adoption archive token is malformed');
        }
        $mount = $this->persistentMount($this->resolvedComposeConfig(), $this->service, $this->repoPath);
        return rtrim($mount['target'], '/') . '/.wprism-adopt-upload-' . $token . '.tar';
    }

    /** @return array{supported:bool,reason:string,remediation:string} */
    public function bootstrapCapability(): array {
        if ($this->bootstrapAuthorized) {
            return [
                'supported' => true,
                'reason' => 'machine-local configuration authorizes the local Docker bootstrap mechanism; adopt still proves the daemon, running WordPress service, shared storage, and target topology before install',
                'remediation' => '',
            ];
        }
        return [
            'supported' => false,
            'reason' => 'Docker control-plane bootstrap is privileged and has no machine-local authorization',
            'remediation' => 'run wprism connect for this local Compose installation so it can inspect and write the exact machine-local bootstrap entry',
        ];
    }

    /**
     * Prove this is a local daemon and that a running web container and the
     * selected CLI service resolve the same durable WordPress storage.
     */
    public function assertLocalBootstrapControlPlane(): void {
        if (!$this->bootstrapAuthorized) {
            throw new \RuntimeException('Docker bootstrap has no machine-local authorization');
        }
        $provenance = $this->localBootstrapProvenance();
        if ($this->dockerContext === null && $this->dockerEndpoint === null) {
            $this->dockerContext = $provenance['docker_context'];
            $this->dockerEndpoint = $provenance['docker_endpoint'];
        } elseif ($provenance['docker_context'] !== $this->dockerContext
            || $provenance['docker_endpoint'] !== $this->dockerEndpoint) {
            throw new \RuntimeException('Docker bootstrap context provenance is not pinned to this machine-local environment');
        }

        $config = $this->resolvedComposeConfig();
        $this->assertManagedTooling($config);
        $muPath = rtrim($this->wpPath, '/') . '/wp-content/mu-plugins';
        $this->assertNoDescendantMounts($config, $this->service, [$muPath, $this->repoPath]);
        $this->assertNoDescendantMounts($config, (string) $this->wordpressService, [$muPath]);
        $cliWp = $this->persistentMount($config, $this->service, $this->wpPath);
        $webWp = $this->persistentMount($config, (string) $this->wordpressService, $this->wpPath);
        $cliMu = $this->persistentMount($config, $this->service, $muPath);
        $webMu = $this->persistentMount($config, (string) $this->wordpressService, $muPath);
        $cliRepo = $this->persistentMount($config, $this->service, $this->repoPath);
        if ($cliWp['type'] !== $webWp['type'] || $cliWp['source'] !== $webWp['source']
            || $cliWp['relative'] !== $webWp['relative']
            || $cliMu['type'] !== $webMu['type'] || $cliMu['source'] !== $webMu['source']
            || $cliMu['relative'] !== $webMu['relative']) {
            throw new \RuntimeException('Docker CLI and WordPress services do not share the same persistent WordPress storage');
        }

        $running = $this->captureControlPlane(array_merge(
            $this->baseTokens(), ['ps', '--status=running', '--services']
        ));
        $services = array_values(array_filter(array_map('trim', explode("\n", $running['stdout']))));
        if ($running['exit'] !== 0 || !in_array($this->wordpressService, $services, true)) {
            throw new \RuntimeException(
                "Docker WordPress service '{$this->wordpressService}' is not already running; WPrism did not start it"
            );
        }
        $container = $this->captureControlPlane(array_merge(
            $this->baseTokens(), ['ps', '-q', (string) $this->wordpressService]
        ));
        $containerId = trim($container['stdout']);
        if ($container['exit'] !== 0 || preg_match('/^[a-f0-9]{12,64}$/D', $containerId) !== 1) {
            throw new \RuntimeException('Docker could not bind the running WordPress service to one container identity');
        }
        $this->assertCurrentContainerConfiguration($containerId);
        $mounts = $this->captureControlPlane(array_merge(
            $this->dockerRootTokens(), ['inspect', '--format', '{{json .Mounts}}', $containerId]
        ));
        $actual = $mounts['exit'] === 0 ? json_decode(trim($mounts['stdout']), true) : null;
        if (!is_array($actual)
            || $this->hasActualDescendantMount($actual, $muPath)
            || !$this->actualMountMatches($actual, $this->wpPath, $webWp)
            || !$this->actualMountMatches($actual, $muPath, $webMu)) {
            throw new \RuntimeException('the running WordPress container does not expose the configured persistent WordPress path');
        }
        if ($this->mode === 'exec') {
            $cliContainer = $this->captureControlPlane(array_merge(
                $this->baseTokens(), ['ps', '-q', $this->service]
            ));
            $cliContainerId = trim($cliContainer['stdout']);
            if ($cliContainer['exit'] !== 0 || preg_match('/^[a-f0-9]{12,64}$/D', $cliContainerId) !== 1) {
                throw new \RuntimeException('Docker could not bind the running CLI service to one container identity');
            }
            $cliMounts = $this->captureControlPlane(array_merge(
                $this->dockerRootTokens(), ['inspect', '--format', '{{json .Mounts}}', $cliContainerId]
            ));
            $cliActual = $cliMounts['exit'] === 0 ? json_decode(trim($cliMounts['stdout']), true) : null;
            if (!is_array($cliActual)
                || $this->hasActualDescendantMount($cliActual, $muPath)
                || $this->hasActualDescendantMount($cliActual, $this->repoPath)
                || !$this->actualMountMatches($cliActual, $this->wpPath, $cliWp)
                || !$this->actualMountMatches($cliActual, $muPath, $cliMu)
                || !$this->actualMountMatches($cliActual, $this->repoPath, $cliRepo)) {
                throw new \RuntimeException('the running Docker CLI container storage does not match its bootstrap configuration');
            }
        }
    }

    /** @return array{docker_context:string,docker_endpoint:string} */
    public function localBootstrapProvenance(): array {
        $dockerHost = getenv('DOCKER_HOST');
        if (is_string($dockerHost) && $dockerHost !== '') {
            throw new \RuntimeException('Docker bootstrap refuses DOCKER_HOST; select a local unix/npipe Docker context explicitly');
        }
        $contextName = $this->dockerContext;
        if ($contextName === null) {
            $context = $this->captureControlPlane(['docker', 'context', 'show']);
            $contextName = trim($context['stdout']);
            if ($context['exit'] !== 0 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $contextName) !== 1) {
                throw new \RuntimeException('Docker bootstrap could not identify the active local Docker context');
            }
        }
        $endpoint = $this->captureControlPlane([
            'docker', '--context', $contextName, 'context', 'inspect', $contextName,
            '--format', '{{json .Endpoints.docker.Host}}',
        ]);
        $endpointValue = $endpoint['exit'] === 0 ? json_decode(trim($endpoint['stdout']), true) : null;
        if (!is_string($endpointValue)
            || (!str_starts_with($endpointValue, 'unix://') && !str_starts_with($endpointValue, 'npipe://'))) {
            throw new \RuntimeException('Docker bootstrap requires a local unix/npipe daemon endpoint; remote Docker contexts are not authorized');
        }
        if ($this->dockerEndpoint !== null && !hash_equals($this->dockerEndpoint, $endpointValue)) {
            throw new \RuntimeException('Docker bootstrap context endpoint changed after connect; installation was not redirected');
        }
        return ['docker_context' => $contextName, 'docker_endpoint' => $endpointValue];
    }

    /**
     * Bind one running official database service to this connected local project.
     *
     * No resolved environment value leaves this method: Compose configuration is
     * used only to select the service and compare its config hash, while the
     * returned image/container/context facts contain no database credentials.
     *
     * @return array{
     *   docker_tokens:list<string>,docker_context:string,docker_endpoint:string,
     *   compose_project:string,database_service:string,container_id:string,
     *   container_hostname:string,config_hash:string,image_id:string,
     *   image_digest:string,engine:string
     * }
     */
    public function localDatabaseServiceBinding(string $service): array {
        if (!$this->bootstrapAuthorized || $this->wordpressService === null) {
            throw new \RuntimeException('Docker database setup requires a machine-local bootstrap connection');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $service) !== 1
            || $service === $this->service || $service === $this->wordpressService) {
            throw new \RuntimeException('Docker database setup requires one distinct named Compose database service');
        }
        $provenance = $this->localBootstrapProvenance();
        $this->assertLocalBootstrapControlPlane();
        $config = $this->resolvedComposeConfig();
        $database = is_array($config['services'] ?? null) ? ($config['services'][$service] ?? null) : null;
        $declaredImage = is_array($database) ? ($database['image'] ?? null) : null;
        if (!is_string($declaredImage) || self::officialDatabaseImageFamily($declaredImage) === null) {
            throw new \RuntimeException('selected Docker database service must declare an official mysql or mariadb image');
        }
        $container = $this->captureControlPlane(array_merge($this->baseTokens(), ['ps', '--status=running', '-q', $service]));
        $containerId = trim($container['stdout']);
        if ($container['exit'] !== 0 || preg_match('/^[a-f0-9]{64}$/D', $containerId) !== 1) {
            throw new \RuntimeException('selected Docker database service must resolve to exactly one running container');
        }
        $declared = $this->captureControlPlane(array_merge($this->baseTokens(), ['config', '--hash', $service]));
        $parts = preg_split('/\s+/', trim($declared['stdout']));
        $configHash = is_array($parts) && count($parts) === 2 && $parts[0] === $service ? $parts[1] : null;
        $inspection = $this->captureControlPlane(array_merge($this->dockerRootTokens(), [
            'container', 'inspect', '--format',
            '{{json .Id}}|{{json .Image}}|{{json .Config.Image}}|{{json .Config.Hostname}}|{{json .Config.User}}|{{json .Config.Entrypoint}}|{{json .Config.Cmd}}|{{json .Config.Labels}}|{{json .State.Running}}',
            $containerId,
        ]));
        $fields = explode('|', trim($inspection['stdout']));
        $decoded = count($fields) === 9 ? array_map(static fn(string $field): mixed => json_decode($field, true), $fields) : [];
        [$inspectedId, $imageId, $configuredImage, $hostname, $user, $entrypoint, $command, $labels, $running]
            = $decoded + array_fill(0, 9, null);
        $project = is_array($labels) ? ($labels['com.docker.compose.project'] ?? null) : null;
        if ($declared['exit'] !== 0 || $inspection['exit'] !== 0
            || !is_string($configHash) || preg_match('/^[a-f0-9]{64}$/D', $configHash) !== 1
            || !is_string($inspectedId) || !hash_equals($containerId, $inspectedId)
            || !is_string($imageId) || preg_match('/^sha256:[a-f0-9]{64}$/D', $imageId) !== 1
            || !is_string($configuredImage) || !hash_equals($declaredImage, $configuredImage)
            || !is_string($hostname) || preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/D', $hostname) !== 1
            || !in_array($user, ['', '0', 'root'], true)
            || !is_array($entrypoint) || $entrypoint !== ['docker-entrypoint.sh']
            || !is_array($command) || !isset($command[0]) || !is_string($command[0])
            || (!in_array($command[0], ['mysqld', 'mariadbd'], true) && !str_starts_with($command[0], '-'))
            || !is_array($labels)
            || ($labels['com.docker.compose.service'] ?? null) !== $service
            || ($labels['com.docker.compose.config-hash'] ?? null) !== $configHash
            || !is_string($project) || preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $project) !== 1
            || ($this->composeProject !== null && !hash_equals($this->composeProject, $project))
            || $running !== true) {
            throw new \RuntimeException('selected Docker database container does not match its running official Compose service');
        }
        $image = $this->captureControlPlane(array_merge($this->dockerRootTokens(), [
            'image', 'inspect', '--format', '{{json .Id}}|{{json .RepoDigests}}', $imageId,
        ]));
        $imageFields = explode('|', trim($image['stdout']));
        $inspectedImageId = isset($imageFields[0]) ? json_decode($imageFields[0], true) : null;
        $repoDigests = isset($imageFields[1]) ? json_decode($imageFields[1], true) : null;
        $family = self::officialDatabaseImageFamily($declaredImage);
        $officialDigests = is_array($repoDigests) && is_string($family)
            ? array_values(array_filter($repoDigests, static fn(mixed $digest): bool =>
                is_string($digest)
                && preg_match('#^(?:docker\.io/)?(?:library/)?' . $family . '@sha256:[a-f0-9]{64}$#D', $digest) === 1))
            : [];
        if ($image['exit'] !== 0 || !is_string($inspectedImageId) || !hash_equals($imageId, $inspectedImageId)
            || count($officialDigests) !== 1) {
            throw new \RuntimeException('selected Docker database image has no unique official immutable repository digest');
        }
        return [
            'docker_tokens' => $this->dockerRootTokens(),
            'docker_context' => $provenance['docker_context'],
            'docker_endpoint' => $provenance['docker_endpoint'],
            'compose_project' => $project,
            'database_service' => $service,
            'container_id' => $containerId,
            'container_hostname' => $hostname,
            'config_hash' => $configHash,
            'image_id' => $imageId,
            'image_digest' => $officialDigests[0],
            'engine' => $family,
        ];
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    public function uploadFile(string $localPath, string $remotePath): array {
        if ($remotePath !== $this->adoptionArchivePathFromPath($remotePath)) {
            return ['exit' => 64, 'stdout' => '', 'stderr' => 'Docker adoption destination is outside the closed persistent repository path'];
        }
        $localStat = @lstat($localPath);
        $localDigest = is_array($localStat) && ($localStat['mode'] & 0170000) === 0100000
            ? @hash_file('sha256', $localPath)
            : false;
        if (!is_string($localDigest)) {
            return ['exit' => 65, 'stdout' => '', 'stderr' => 'Docker adoption source is not a readable regular file'];
        }
        $copy = <<<'PHP'
$path=$argv[1]; $identity=static fn(array $s):string=>(string)$s['dev'].':'.(string)$s['ino'].':'.(string)($s['mode']&0170000);
$old=umask(0077); $out=@fopen($path,'xb'); umask($old);
if(!is_resource($out)){fwrite(STDERR,'Docker adoption destination already exists or cannot be created');exit(67);}
$opened=fstat($out); $hash=hash_init('sha256'); $ok=is_array($opened)&&($opened['mode']&0170000)===0100000;
while($ok&&!feof(STDIN)){ $chunk=fread(STDIN,1048576); if(!is_string($chunk)){$ok=false;break;} if($chunk===''){continue;} hash_update($hash,$chunk); for($off=0,$len=strlen($chunk);$off<$len;){$n=fwrite($out,substr($chunk,$off));if(!is_int($n)||$n<1){$ok=false;break 2;}$off+=$n;}}
$digest=hash_final($hash); $ok=$ok&&fflush($out); if(function_exists('fsync')){$ok=$ok&&fsync($out);} $closed=fclose($out); $ok=$ok&&$closed;
$final=@lstat($path); $finalHash=is_array($final)&&($final['mode']&0170000)===0100000?@hash_file('sha256',$path):false;
if(!$ok||!is_array($opened)||!is_array($final)||$identity($opened)!==$identity($final)||!is_string($finalHash)||!hash_equals($digest,$finalHash)){
  $now=@lstat($path); if(is_array($now)&&is_array($opened)&&$identity($now)===$identity($opened)){@unlink($path);} else {fwrite(STDERR,'; destination identity changed and was retained for operator review');} exit(71);
}
echo $identity($final),"\n",$digest;
PHP;
        $script = 'php -r ' . self::esc($copy) . ' ' . self::esc($remotePath);
        $command = $this->rawCommand($script) . ' < ' . self::esc($localPath);
        $result = self::runCapturingBounded($command, 120000, 1024, 65536);
        $rows = explode("\n", trim($result['stdout']));
        if ($result['exit'] !== 0 || count($rows) !== 2
            || preg_match('/^[0-9]+:[0-9]+:32768$/D', $rows[0]) !== 1
            || !hash_equals($localDigest, $rows[1])) {
            return [
                'exit' => $result['exit'] !== 0 ? $result['exit'] : 71,
                'stdout' => '',
                'stderr' => $result['exit'] !== 0 ? $result['stderr'] : 'Docker adoption transfer verification failed',
            ];
        }
        return ['exit' => 0, 'stdout' => $rows[0], 'stderr' => ''];
    }

    public function uploadedFileIdentity(array $upload): string {
        $identity = trim($upload['stdout']);
        if ($upload['exit'] !== 0 || preg_match('/^[0-9]+:[0-9]+:32768$/D', $identity) !== 1) {
            throw new \RuntimeException('Docker adoption upload did not return a valid ownership identity');
        }
        return $identity;
    }

    public function cleanupUploadedFile(string $path, string $identity): array {
        if ($path !== $this->adoptionArchivePathFromPath($path)
            || preg_match('/^[0-9]+:[0-9]+:32768$/D', $identity) !== 1) {
            return ['exit' => 64, 'stdout' => '', 'stderr' => 'Docker adoption cleanup identity is invalid'];
        }
        $program = <<<'PHP'
$path=$arguments[0];$want=$arguments[1];$s=@lstat($path);
if($s===false)return ['exit'=>0,'stdout'=>'','stderr'=>''];
$actual=is_array($s)?(string)$s['dev'].':'.(string)$s['ino'].':'.(string)($s['mode']&0170000):'';
if($actual!==$want)return ['exit'=>73,'stdout'=>'','stderr'=>'Docker adoption archive identity changed and the path was retained for operator review'];
return @unlink($path)?['exit'=>0,'stdout'=>'','stderr'=>'']:['exit'=>74,'stdout'=>'','stderr'=>'Docker adoption archive could not be removed'];
PHP;
        $result = $this->captureRawFramed($program, [$path, $identity], 30000, 1024, 1024);
        if (($result['verified'] ?? false) !== true) {
            return ['exit' => 75, 'stdout' => '', 'stderr' => 'Docker adoption archive cleanup could not be verified'];
        }
        return ['exit' => $result['exit'], 'stdout' => $result['stdout'], 'stderr' => $result['stderr']];
    }

    private function adoptionArchivePathFromPath(string $path): string {
        if (preg_match('#/\.wprism-adopt-upload-([a-f0-9]{24})\.tar$#D', $path, $match) !== 1) {
            return '';
        }
        return $this->adoptionArchivePath($match[1]) === $path ? $path : '';
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
        $config = $this->resolvedComposeConfig();
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
            $target = rtrim($volume['target'], '/');
            if ($target !== $want && !str_starts_with($want . '/', $target . '/')) {
                continue;
            }
            $matches[] = [$volume, $target];
        }
        if ($matches === []) {
            return null;
        }
        usort($matches, static fn(array $left, array $right): int => strlen($right[1]) <=> strlen($left[1]));
        [$volume, $target] = $matches[0];
        if (isset($matches[1]) && strlen($matches[1][1]) === strlen($target)) {
            throw new \RuntimeException("Docker Compose service '{$this->service}' has ambiguous repo_path mounts");
        }
        $type = $volume['type'] ?? null;
        $readOnly = array_key_exists('read_only', $volume) ? $volume['read_only'] : false;
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
        return rtrim($source, '/') . substr($want, strlen($target));
    }

    private function baseTokens(): array {
        $t = array_merge($this->dockerRootTokens(), ['compose']);
        // Compose `run` writes container lifecycle progress to the host-side
        // stderr stream even when the target command succeeds. Suppress that
        // source only for the explicit bootstrap path; target PHP/WordPress
        // stderr remains intact and eligibility continues to fail closed on it.
        if ($this->bootstrapAuthorized) {
            array_push($t, '--progress', 'quiet');
        }
        if ($this->composeProject !== null) {
            $t[] = '--project-name';
            $t[] = $this->composeProject;
        }
        if ($this->composeEnvFile !== null) {
            $t[] = '--env-file';
            $t[] = $this->composeEnvFile;
        }
        array_push($t, '-f', $this->composeFile);
        if ($this->composeOverlayFile !== null) {
            array_push($t, '-f', $this->composeOverlayFile);
        }
        if ($this->profile !== null) {
            $t[] = '--profile';
            $t[] = $this->profile;
        }
        return $t;
    }

    /** @return list<string> */
    private function dockerRootTokens(): array {
        return $this->dockerContext === null
            ? ['docker']
            : ['docker', '--context', $this->dockerContext];
    }

    protected function wpCommand(array $wpArgs): string {
        $verb = $this->mode === 'exec'
            ? ['exec', '-T']
            : array_merge(['run', '--rm', '-T'], $this->bootstrapAuthorized ? ['--no-deps'] : []);
        $path = $this->wpPath !== '' ? ['wp', '--path=' . $this->wpPath] : ['wp'];
        return $this->tokensOrPreconditionFailure(array_merge($this->baseTokens(), $verb, [$this->service], $path, $wpArgs));
    }

    protected function rawCommand(string $script): string {
        // `run`'s and `exec`'s trailing args both become the container's
        // argv directly, not a shell command line — go through bash -c for
        // test operators, &&, pipes, etc. (same pattern the spike scripts
        // use for raw commands against the cli-* services).
        $verb = $this->mode === 'exec'
            ? ['exec', '-T']
            : array_merge(['run', '--rm', '-T'], $this->bootstrapAuthorized ? ['--no-deps'] : []);
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

    /** @return array<string,mixed> */
    private function resolvedComposeConfig(): array {
        if ($this->composeConfig !== null) {
            return $this->composeConfig;
        }
        $result = $this->captureControlPlane(array_merge($this->baseTokens(), ['config', '--format', 'json']));
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('Docker Compose repository-mount inspection failed closed: ' . trim($result['stderr']));
        }
        try {
            $config = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Docker Compose repository-mount inspection returned malformed JSON');
        }
        if (!is_array($config)) {
            throw new \RuntimeException('Docker Compose repository-mount inspection returned malformed JSON');
        }
        return $this->composeConfig = $config;
    }

    /** @return array{type:string,source:string,target:string,relative:string} */
    private function persistentMount(array $config, string $serviceName, string $path): array {
        $services = $config['services'] ?? null;
        $service = is_array($services) ? ($services[$serviceName] ?? null) : null;
        $volumes = is_array($service) ? ($service['volumes'] ?? null) : null;
        if (!is_array($volumes)) {
            throw new \RuntimeException("Docker Compose config does not contain service '$serviceName' with volumes");
        }
        $matches = [];
        foreach ($volumes as $volume) {
            if (!is_array($volume) || !is_string($volume['target'] ?? null)) {
                throw new \RuntimeException("Docker Compose service '$serviceName' has an ambiguous volume entry");
            }
            $target = rtrim($volume['target'], '/');
            if ($path !== $target && !str_starts_with($path . '/', $target . '/')) {
                continue;
            }
            $matches[] = [$volume, $target];
        }
        if ($matches === []) {
            throw new \RuntimeException("Docker Compose service '$serviceName' has no persistent mount covering '$path'");
        }
        usort($matches, static fn(array $left, array $right): int => strlen($right[1]) <=> strlen($left[1]));
        [$volume, $target] = $matches[0];
        if (isset($matches[1]) && strlen($matches[1][1]) === strlen($target)) {
            throw new \RuntimeException("Docker Compose service '$serviceName' has ambiguous mounts covering '$path'");
        }
        $type = $volume['type'] ?? null;
        $source = $volume['source'] ?? null;
        $readOnly = array_key_exists('read_only', $volume) ? $volume['read_only'] : false;
        if (!in_array($type, ['bind', 'volume'], true) || !is_string($source) || $source === ''
            || !is_bool($readOnly) || $readOnly) {
            throw new \RuntimeException("Docker Compose service '$serviceName' does not expose '$path' on writable persistent storage");
        }
        if ($type === 'volume') {
            $definition = is_array($config['volumes'] ?? null) ? ($config['volumes'][$source] ?? null) : null;
            if (is_array($definition) && is_string($definition['name'] ?? null) && $definition['name'] !== '') {
                $source = $definition['name'];
            }
        }
        return [
            'type' => $type,
            'source' => $source,
            'target' => $target,
            'relative' => substr($path, strlen($target)),
        ];
    }

    /** @param list<mixed> $mounts @param array{type:string,source:string,target:string,relative:string} $expected */
    private function actualMountMatches(array $mounts, string $path, array $expected): bool {
        $matches = [];
        foreach ($mounts as $mount) {
            if (!is_array($mount) || !is_string($mount['Destination'] ?? null)) {
                continue;
            }
            $destination = rtrim($mount['Destination'], '/');
            if ($path === $destination || str_starts_with($path . '/', $destination . '/')) {
                $matches[] = $mount;
            }
        }
        usort($matches, static fn(array $left, array $right): int =>
            strlen((string) $right['Destination']) <=> strlen((string) $left['Destination']));
        if ($matches === []) {
            return false;
        }
        if (isset($matches[1])
            && strlen(rtrim((string) $matches[1]['Destination'], '/'))
                === strlen(rtrim((string) $matches[0]['Destination'], '/'))) {
            return false;
        }
        $mount = $matches[0];
        $destination = rtrim((string) $mount['Destination'], '/');
        $source = $expected['type'] === 'volume' ? ($mount['Name'] ?? null) : ($mount['Source'] ?? null);
        return ($mount['Type'] ?? null) === $expected['type']
            && ($mount['RW'] ?? null) === true
            && $source === $expected['source']
            && substr($path, strlen($destination)) === $expected['relative'];
    }

    private function assertCurrentContainerConfiguration(string $containerId): void {
        $declared = $this->captureControlPlane(array_merge(
            $this->baseTokens(), ['config', '--hash', (string) $this->wordpressService]
        ));
        $parts = preg_split('/\s+/', trim($declared['stdout']));
        $declaredHash = is_array($parts) && count($parts) === 2
            && $parts[0] === $this->wordpressService ? $parts[1] : null;
        $running = $this->captureControlPlane(array_merge(
            $this->dockerRootTokens(), [
                'container', 'inspect', '--format',
                '{{index .Config.Labels "com.docker.compose.config-hash"}}',
                $containerId,
            ]
        ));
        if ($declared['exit'] !== 0 || $running['exit'] !== 0
            || !is_string($declaredHash) || preg_match('/^[a-f0-9]{64}$/D', $declaredHash) !== 1
            || !hash_equals($declaredHash, trim($running['stdout']))) {
            throw new \RuntimeException(
                "running Docker WordPress service '{$this->wordpressService}' does not match the current Compose configuration"
            );
        }
    }

    /** @param array<string,mixed> $config @param list<string> $roots */
    private function assertNoDescendantMounts(array $config, string $serviceName, array $roots): void {
        $services = $config['services'] ?? null;
        $service = is_array($services) ? ($services[$serviceName] ?? null) : null;
        $volumes = is_array($service) ? ($service['volumes'] ?? null) : null;
        if (!is_array($volumes)) {
            throw new \RuntimeException("Docker Compose config does not contain service '$serviceName' with volumes");
        }
        foreach ($volumes as $volume) {
            if (!is_array($volume) || !is_string($volume['target'] ?? null)) {
                throw new \RuntimeException("Docker Compose service '$serviceName' has an ambiguous volume entry");
            }
            $target = rtrim($volume['target'], '/');
            foreach ($roots as $root) {
                if (str_starts_with($target . '/', rtrim($root, '/') . '/') && $target !== rtrim($root, '/')) {
                    throw new \RuntimeException(
                        "Docker Compose service '$serviceName' has an unsupported descendant mount '$target' inside '$root'"
                    );
                }
            }
        }
    }

    /** @param list<mixed> $mounts */
    private function hasActualDescendantMount(array $mounts, string $root): bool {
        $root = rtrim($root, '/');
        foreach ($mounts as $mount) {
            $destination = is_array($mount) && is_string($mount['Destination'] ?? null)
                ? rtrim($mount['Destination'], '/')
                : '';
            if ($destination !== $root && str_starts_with($destination . '/', $root . '/')) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $config */
    private function assertManagedTooling(array $config): void {
        if ($this->toolingProvenance === null) {
            return;
        }
        if ($this->composeOverlayFile === null
            || !is_file($this->composeOverlayFile)
            || is_link($this->composeOverlayFile)
            || (fileperms($this->composeOverlayFile) & 0777) !== 0600
            || hash_file('sha256', $this->composeOverlayFile) !== $this->toolingProvenance['overlay_sha256']) {
            throw new \RuntimeException('managed Docker tooling overlay bytes changed after connect');
        }
        $service = is_array($config['services'] ?? null) ? ($config['services'][$this->service] ?? null) : null;
        $labels = is_array($service) ? ($service['labels'] ?? null) : null;
        if (!is_array($service)
            || ($service['image'] ?? null) !== $this->toolingProvenance['image_id']
            || !is_array($labels)
            || ($labels['io.wprism.managed'] ?? null) !== 'docker-tooling-service'
            || ($labels['io.wprism.identity'] ?? null) !== $this->toolingProvenance['tooling_identity']) {
            throw new \RuntimeException('managed Docker tooling service identity changed after connect');
        }
        $repo = $this->persistentMount($config, $this->service, $this->repoPath);
        if ($repo['type'] !== 'volume'
            || $repo['source'] !== $this->toolingProvenance['repository_volume']) {
            throw new \RuntimeException('managed Docker repository storage identity changed after connect');
        }
    }

    private static function safeContainerPath(string $path): bool {
        return $path !== '' && $path !== '/' && str_starts_with($path, '/')
            && preg_match('/[\x00-\x1f\x7f]/', $path) !== 1
            && !str_contains($path, '//') && !str_ends_with($path, '/')
            && preg_match('#/(?:\.|\.\.)(?:/|$)#', $path) !== 1;
    }

    private static function officialDatabaseImageFamily(string $image): ?string {
        return preg_match(
            '#^(?:docker\.io/|index\.docker\.io/)?(?:library/)?(mysql|mariadb)(?::[A-Za-z0-9][A-Za-z0-9._-]{0,127}|@sha256:[a-f0-9]{64})$#D',
            $image,
            $match
        ) === 1 ? $match[1] : null;
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
