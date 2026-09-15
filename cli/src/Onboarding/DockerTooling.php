<?php

declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/../Command/HostProcess.php';

/**
 * Prepares an explicitly requested, controller-owned WP-CLI Compose service.
 *
 * The overlay extends the application service so Compose, rather than WPrism,
 * carries its environment, networks and persistent webroot. Consequently no
 * interpolated secret is copied into the private file. The application service
 * is inspected but never started, stopped or recreated here. This boundary is
 * deliberately the standard official WordPress Compose layout: arbitrary
 * custom images, webroots and service identities refuse instead of receiving
 * an unproved approximation of their runtime.
 */
final class DockerTooling
{
    private const WP_PATH = '/var/www/html';
    private const MU_PATH = '/var/www/html/wp-content/mu-plugins';
    private const REPOSITORY_ROOT = '/wprism-repository';
    private const REPOSITORY_PATH = '/wprism-repository/site';
    private const MANAGED_LABEL = 'io.wprism.managed';
    private const IDENTITY_LABEL = 'io.wprism.identity';

    /**
     * @param null|callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $runner
     *
     * @return array{
     *   compose_overlay_file:string,
     *   service:string,
     *   wordpress_service:string,
     *   wp_path:string,
     *   repo_path:string,
     *   image_id:string,
     *   image_name:string,
     *   repository_volume:string,
     *   lease_container:string,
     *   tooling_identity:string,
     *   docker_context:string,
     *   docker_endpoint:string,
     *   compose_project:string,
     *   created_image:bool,
     *   created_volume:bool,
     *   created_overlay:bool,
     *   toolchain_identity:string,
     *   overlay_sha256:string
     * }
     */
    public static function prepare(
        string $environment,
        string $composeFile,
        ?string $composeEnvFile,
        ?string $profile,
        string $wordpressService,
        ?callable $runner = null,
        ?string $privateRoot = null
    ): array {
        self::validateName($environment, 'environment');
        self::validateName($wordpressService, 'WordPress service');
        if ($profile !== null && $profile === '') {
            throw new \RuntimeException('Docker Compose profile must be non-empty when provided');
        }

        $composeFile = self::regularFile($composeFile, 'Compose file');
        $composeEnvFile = $composeEnvFile === null
            ? null
            : self::regularFile($composeEnvFile, 'Compose environment file');
        $run = $runner ?? static fn(array $argv, ?string $cwd, int $timeout): array => HostProcess::run(
            $argv,
            $cwd,
            [],
            false,
            $timeout,
            4 * 1024 * 1024
        );

        [$dockerContext, $dockerEndpoint] = self::localDaemon($run);
        $run = self::boundRunner($run, $dockerContext);
        $discoveryBase = self::composeTokens($composeFile, $composeEnvFile, $profile, null);
        $container = self::runningContainer($run, $discoveryBase, $wordpressService);
        $composeProject = self::containerProject($run, $container);
        $base = self::composeTokens($composeFile, $composeEnvFile, $profile, $composeProject);
        if (self::runningContainer($run, $base, $wordpressService) !== $container) {
            throw new \RuntimeException('Pinned Docker Compose project resolves to a different WordPress container');
        }
        $config = self::composeConfig($run, $base, $wordpressService);
        self::assertCurrentContainerConfiguration($run, $base, $container, $wordpressService);
        self::assertPersistentWebroot($run, $container, $config, $wordpressService);
        [$uid, $gid] = self::wordpressIdentity($run, $container);

        $dockerfile = dirname(__DIR__, 2) . '/resources/docker-tooling/Dockerfile';
        $dockerfile = self::regularFile($dockerfile, 'managed tooling Dockerfile');
        $toolchainHash = hash_file('sha256', $dockerfile);
        if (!is_string($toolchainHash)) {
            throw new \RuntimeException('Could not hash the managed tooling Dockerfile');
        }
        $identity = hash(
            'sha256',
            'wprism-managed-docker-tooling/v1' . "\0"
                . $environment . "\0" . $dockerContext . "\0" . $dockerEndpoint . "\0"
                . $composeProject . "\0" . $composeFile . "\0" . $wordpressService
        );
        $short = substr($identity, 0, 16);
        $service = 'wprism-managed-' . $short;
        $volume = 'wprism-managed-repository-' . $short;
        $lease = 'wprism-managed-lease-' . $short;
        $imageName = 'wprism-managed-cli:' . substr($toolchainHash, 0, 16);
        $root = self::privateRoot($privateRoot);
        $directory = $root . '/' . $short;
        self::privateDirectory($directory);
        $lock = self::lock($directory . '/prepare.lock');

        $createdImage = false;
        $createdVolume = false;
        $createdLease = false;
        $createdOverlay = false;
        $overlayHash = '';
        $overlay = $directory . '/compose.json';
        try {
            [$imageId, $createdImage] = self::image($run, $dockerfile, $imageName, $toolchainHash, $directory);
            [$createdVolume] = self::volume($run, $volume, $identity);
            self::assertVolumeIdle($run, $volume);
            $createdOverlay = self::writeOverlay(
                $overlay,
                $composeFile,
                $wordpressService,
                $service,
                $imageId,
                $volume,
                $uid,
                $gid,
                $identity
            );
            self::validateOverlay($run, $base, $overlay, $service);
            self::initializeRepositoryVolume($run, $base, $overlay, $service, $uid, $gid, $createdVolume);
            self::createLease($run, $lease, $imageId, $identity);
            $createdLease = true;
            $overlayHash = hash_file('sha256', $overlay);
            if (!is_string($overlayHash)) {
                throw new \RuntimeException('Could not bind the private managed Docker Compose overlay bytes');
            }
        } catch (\Throwable $error) {
            if ($createdLease) {
                self::bestEffort($run, ['docker', 'container', 'rm', $lease]);
            }
            if ($createdVolume) {
                self::bestEffort($run, ['docker', 'volume', 'rm', $volume]);
            }
            if ($createdImage) {
                self::bestEffort($run, ['docker', 'image', 'rm', $imageName]);
            }
            if ($createdOverlay) {
                @unlink($overlay);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
            throw $error;
        }
        flock($lock, LOCK_UN);
        fclose($lock);

        $receipt = [
            'compose_overlay_file' => $overlay,
            'service' => $service,
            'wordpress_service' => $wordpressService,
            'wp_path' => self::WP_PATH,
            'repo_path' => self::REPOSITORY_PATH,
            'image_id' => $imageId,
            'image_name' => $imageName,
            'repository_volume' => $volume,
            'lease_container' => $lease,
            'tooling_identity' => $identity,
            'docker_context' => $dockerContext,
            'docker_endpoint' => $dockerEndpoint,
            'compose_project' => $composeProject,
            'created_image' => $createdImage,
            'created_volume' => $createdVolume,
            'created_overlay' => $createdOverlay,
            'toolchain_identity' => $toolchainHash,
            'overlay_sha256' => $overlayHash,
        ];
        if ($runner === null) {
            register_shutdown_function(static function () use ($receipt): void {
                self::release($receipt);
            });
        }

        return $receipt;
    }

    /**
     * Reverts resources created by a successful prepare when its caller later
     * fails to publish the connection. Every deletion is identity-checked;
     * reused resources are never candidates.
     *
     * @param array<string,mixed> $receipt
     * @param null|callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $runner
     */
    public static function rollback(array $receipt, ?callable $runner = null): void
    {
        self::release($receipt, $runner);
        $context = $receipt['docker_context'] ?? null;
        $endpoint = $receipt['docker_endpoint'] ?? null;
        $identity = $receipt['tooling_identity'] ?? null;
        if (!is_string($context) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $context)
            || !is_string($endpoint) || !self::localEndpoint($endpoint)
            || !is_string($identity) || !preg_match('/^[a-f0-9]{64}$/D', $identity)) {
            return;
        }
        $run = $runner ?? static fn(array $argv, ?string $cwd, int $timeout): array => HostProcess::run(
            $argv,
            $cwd,
            [],
            false,
            $timeout
        );
        $observed = self::command($run, [
            'docker', '--context', $context, 'context', 'inspect', $context,
            '--format', '{{json .Endpoints.docker.Host}}',
        ], 30000);
        if ($observed['exit'] !== 0 || json_decode(trim($observed['stdout']), true) !== $endpoint) {
            return;
        }
        $run = self::boundRunner($run, $context);

        $volume = $receipt['repository_volume'] ?? null;
        if (($receipt['created_volume'] ?? null) === true && is_string($volume)
            && preg_match('/^wprism-managed-repository-[a-f0-9]{16}$/D', $volume)) {
            $inspect = self::command($run, ['docker', 'volume', 'inspect', '--format', '{{json .Labels}}', $volume], 30000);
            $labels = json_decode(trim($inspect['stdout']), true);
            $attached = self::command($run, ['docker', 'ps', '-aq', '--filter', 'volume=' . $volume], 30000);
            if ($inspect['exit'] === 0 && (!is_array($labels)
                || ($labels[self::MANAGED_LABEL] ?? null) !== 'docker-tooling-repository'
                || ($labels[self::IDENTITY_LABEL] ?? null) !== $identity
                || $attached['exit'] !== 0 || trim($attached['stdout']) !== '')) {
                throw new \RuntimeException('Managed repository volume was retained because its ownership or attachment changed');
            }
            if ($inspect['exit'] === 0 && is_array($labels)
                && ($labels[self::MANAGED_LABEL] ?? null) === 'docker-tooling-repository'
                && ($labels[self::IDENTITY_LABEL] ?? null) === $identity
                && $attached['exit'] === 0 && trim($attached['stdout']) === '') {
                $imageId = $receipt['image_id'] ?? null;
                if (!is_string($imageId) || !preg_match('/^sha256:[a-f0-9]{64}$/D', $imageId)) {
                    throw new \RuntimeException('Managed repository volume was retained because its contents could not be inspected');
                }
                $empty = self::command($run, [
                    'docker', 'run', '--rm', '--read-only', '--entrypoint', 'sh',
                    '--mount', 'type=volume,src=' . $volume . ',dst=/wprism-check,readonly',
                    $imageId, '-c', 'test -z "$(find /wprism-check -mindepth 1 -maxdepth 1 -print -quit)"',
                ], 30000);
                if ($empty['exit'] !== 0) {
                    throw new \RuntimeException('Managed repository volume was retained because target data appeared after preparation');
                }
                $removed = self::command($run, ['docker', 'volume', 'rm', $volume], 30000);
                if ($removed['exit'] !== 0) {
                    throw new \RuntimeException('Managed repository volume was retained because its ownership changed during rollback');
                }
            }
        }

        $image = $receipt['image_name'] ?? null;
        $toolchain = $receipt['toolchain_identity'] ?? null;
        if (($receipt['created_image'] ?? null) === true && is_string($image)
            && preg_match('/^wprism-managed-cli:[a-f0-9]{16}$/D', $image)
            && is_string($toolchain) && preg_match('/^[a-f0-9]{64}$/D', $toolchain)) {
            $inspect = self::command(
                $run,
                ['docker', 'image', 'inspect', '--format', '{{json .Id}}|{{json .Config.Labels}}', $image],
                30000
            );
            try {
                if ($inspect['exit'] === 0) {
                    self::verifiedImageInspection($inspect['stdout'], $toolchain);
                    self::bestEffort($run, ['docker', 'image', 'rm', $image]);
                }
            } catch (\Throwable $ignored) {
            }
        }

        $overlay = $receipt['compose_overlay_file'] ?? null;
        $overlayHash = $receipt['overlay_sha256'] ?? null;
        if (($receipt['created_overlay'] ?? null) === true && is_string($overlay)
            && is_string($overlayHash) && preg_match('/^[a-f0-9]{64}$/D', $overlayHash)
            && is_file($overlay) && !is_link($overlay)
            && (fileperms($overlay) & 0777) === 0600
            && hash_file('sha256', $overlay) === $overlayHash) {
            @unlink($overlay);
        }
    }

    /**
     * Releases only the stopped, transient exclusivity marker. The immutable
     * image, private overlay and repository volume intentionally survive.
     *
     * @param array{lease_container?:mixed,tooling_identity?:mixed,docker_context?:mixed,docker_endpoint?:mixed} $receipt
     * @param null|callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $runner
     */
    public static function release(array $receipt, ?callable $runner = null): void
    {
        $lease = $receipt['lease_container'] ?? null;
        $identity = $receipt['tooling_identity'] ?? null;
        $context = $receipt['docker_context'] ?? null;
        $endpoint = $receipt['docker_endpoint'] ?? null;
        if (!is_string($lease) || !preg_match('/^wprism-managed-lease-[a-f0-9]{16}$/D', $lease)) {
            return;
        }
        if (!is_string($identity) || !preg_match('/^[a-f0-9]{64}$/D', $identity)) {
            return;
        }
        if (!is_string($context) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $context)
            || !is_string($endpoint) || !self::localEndpoint($endpoint)) {
            return;
        }
        $run = $runner ?? static fn(array $argv, ?string $cwd, int $timeout): array => HostProcess::run(
            $argv,
            $cwd,
            [],
            false,
            $timeout
        );
        $endpointInspection = self::command($run, [
            'docker', '--context', $context, 'context', 'inspect', $context,
            '--format', '{{json .Endpoints.docker.Host}}',
        ], 30000);
        $observedEndpoint = json_decode(trim($endpointInspection['stdout']), true);
        if ($endpointInspection['exit'] !== 0 || $observedEndpoint !== $endpoint) {
            return;
        }
        $run = self::boundRunner($run, $context);
        $inspection = self::command($run, [
            'docker', 'container', 'inspect', '--format',
            '{{json .Config.Labels}}',
            $lease,
        ], 30000);
        $labels = json_decode(trim($inspection['stdout']), true);
        if ($inspection['exit'] === 0 && is_array($labels)
            && ($labels[self::MANAGED_LABEL] ?? null) === 'docker-tooling-lease'
            && ($labels[self::IDENTITY_LABEL] ?? null) === $identity) {
            self::bestEffort($run, ['docker', 'container', 'rm', $lease]);
        }
    }

    /**
     * @param callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $run
     * @return array{0:string,1:string}
     */
    private static function localDaemon(callable $run): array
    {
        $hostOverride = getenv('DOCKER_HOST');
        if (is_string($hostOverride) && $hostOverride !== '') {
            throw new \RuntimeException(
                'Managed Docker tooling requires a named local Docker context; unset DOCKER_HOST and select that context explicitly'
            );
        }
        $shown = self::command($run, ['docker', 'context', 'show'], 30000);
        $context = trim($shown['stdout']);
        if ($shown['exit'] !== 0 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $context)) {
            throw new \RuntimeException('Could not bind the active Docker context');
        }
        $result = self::command(
            $run,
            ['docker', 'context', 'inspect', $context, '--format', '{{json .Endpoints.docker.Host}}'],
            30000
        );
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('Could not inspect the active Docker context');
        }
        $endpoint = json_decode(trim($result['stdout']), true);
        if (!is_string($endpoint) || !self::localEndpoint($endpoint)) {
            throw new \RuntimeException('Managed Docker tooling requires a local Docker daemon context');
        }
        return [$context, $endpoint];
    }

    /**
     * @param callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $run
     * @return callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string}
     */
    private static function boundRunner(callable $run, string $context): callable
    {
        return static function (array $argv, ?string $cwd, int $timeout) use ($run, $context): array {
            if (($argv[0] ?? null) !== 'docker') {
                throw new \RuntimeException('Managed Docker tooling attempted an unbound control process');
            }
            array_splice($argv, 1, 0, ['--context', $context]);
            return $run($argv, $cwd, $timeout);
        };
    }

    private static function localEndpoint(string $endpoint): bool
    {
        return str_starts_with($endpoint, 'unix://') || str_starts_with($endpoint, 'npipe://');
    }

    /**
     * @param callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $run
     * @param list<string> $base
     * @return array<string,mixed>
     */
    private static function composeConfig(callable $run, array $base, string $service): array
    {
        $result = self::command($run, array_merge($base, ['config', '--format', 'json']), 30000);
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('Docker Compose configuration inspection failed');
        }
        try {
            $config = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Docker Compose configuration inspection returned malformed JSON');
        }
        if (!is_array($config) || !is_array($config['services'] ?? null) || !is_array($config['services'][$service] ?? null)) {
            throw new \RuntimeException("Docker Compose configuration does not contain service '$service'");
        }
        $image = $config['services'][$service]['image'] ?? null;
        if (!is_string($image)
            || !preg_match('~^(?:(?:docker\.io/)?library/)?wordpress(?::|@|$)~D', $image)) {
            throw new \RuntimeException(
                "Managed Docker tooling supports the standard official WordPress image; service '$service' uses another runtime"
            );
        }
        $serviceConfig = $config['services'][$service];
        if (array_key_exists('build', $serviceConfig) || array_key_exists('container_name', $serviceConfig)
            || array_key_exists('network_mode', $serviceConfig) || ($serviceConfig['privileged'] ?? false) !== false) {
            throw new \RuntimeException(
                "Docker Compose service '$service' uses runtime overrides that cannot be safely inherited by managed tooling"
            );
        }
        return $config;
    }

    /**
     * @param callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $run
     * @param list<string> $base
     */
    private static function runningContainer(callable $run, array $base, string $service): string
    {
        $result = self::command($run, array_merge($base, ['ps', '--status', 'running', '-q', $service]), 30000);
        $ids = array_values(array_filter(array_map('trim', explode("\n", $result['stdout']))));
        if ($result['exit'] !== 0 || count($ids) !== 1 || !preg_match('/^[a-f0-9]{12,64}$/D', $ids[0])) {
            throw new \RuntimeException("Managed Docker tooling requires exactly one running '$service' container");
        }
        return $ids[0];
    }

    /** @param callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $run */
    private static function containerProject(callable $run, string $container): string
    {
        $result = self::command($run, [
            'docker', 'container', 'inspect', '--format',
            '{{index .Config.Labels "com.docker.compose.project"}}',
            $container,
        ], 30000);
        $project = trim($result['stdout']);
        if ($result['exit'] !== 0 || !preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $project)) {
            throw new \RuntimeException('Running WordPress container has no safe Docker Compose project identity');
        }
        return $project;
    }

    /**
     * @param callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $run
     * @param list<string> $base
     */
    private static function assertCurrentContainerConfiguration(
        callable $run,
        array $base,
        string $container,
        string $service
    ): void {
        $declared = self::command($run, array_merge($base, ['config', '--hash', $service]), 30000);
        $parts = preg_split('/\s+/', trim($declared['stdout']));
        $declaredHash = is_array($parts) && count($parts) === 2 && $parts[0] === $service ? $parts[1] : null;
        $running = self::command($run, [
            'docker', 'container', 'inspect', '--format',
            '{{index .Config.Labels "com.docker.compose.config-hash"}}',
            $container,
        ], 30000);
        $runningHash = trim($running['stdout']);
        if ($declared['exit'] !== 0 || $running['exit'] !== 0
            || !is_string($declaredHash) || !preg_match('/^[a-f0-9]{64}$/D', $declaredHash)
            || !hash_equals($declaredHash, $runningHash)) {
            throw new \RuntimeException(
                "Running Docker service '$service' does not match the current Compose configuration; recreate it explicitly first"
            );
        }
    }

    /**
     * @param callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $run
     * @param array<string,mixed> $config
     */
    private static function assertPersistentWebroot(callable $run, string $container, array $config, string $service): void
    {
        $serviceConfig = $config['services'][$service];
        $volumes = $serviceConfig['volumes'] ?? null;
        if (!is_array($volumes)) {
            throw new \RuntimeException("Docker Compose service '$service' must explicitly mount the WordPress webroot");
        }
        $declared = array_values(array_filter($volumes, static fn(mixed $volume): bool =>
            is_array($volume) && rtrim((string) ($volume['target'] ?? ''), '/') === self::WP_PATH
        ));
        $declaredDescendants = array_values(array_filter($volumes, static function (mixed $volume): bool {
            $target = is_array($volume) && is_string($volume['target'] ?? null)
                ? rtrim($volume['target'], '/')
                : '';
            return $target !== self::MU_PATH && str_starts_with($target . '/', self::MU_PATH . '/');
        }));
        $webrootReadOnly = array_key_exists('read_only', $declared[0] ?? [])
            ? $declared[0]['read_only']
            : false;
        if (count($declared) !== 1 || !is_string($declared[0]['type'] ?? null)
            || !in_array($declared[0]['type'], ['bind', 'volume'], true)
            || !is_bool($webrootReadOnly) || $webrootReadOnly) {
            throw new \RuntimeException("Docker Compose service '$service' has no unambiguous writable persistent webroot");
        }
        if ($declaredDescendants !== []) {
            throw new \RuntimeException(
                "Docker Compose service '$service' has unsupported mounts below the MU control path"
            );
        }

        $result = self::command(
            $run,
            ['docker', 'container', 'inspect', '--format', '{{json .Mounts}}', $container],
            30000
        );
        $mounts = json_decode(trim($result['stdout']), true);
        $mounted = is_array($mounts) ? array_values(array_filter($mounts, static fn(mixed $mount): bool =>
            is_array($mount) && rtrim((string) ($mount['Destination'] ?? ''), '/') === self::WP_PATH
        )) : [];
        $mountedDescendants = is_array($mounts) ? array_values(array_filter($mounts, static function (mixed $mount): bool {
            $destination = is_array($mount) && is_string($mount['Destination'] ?? null)
                ? rtrim($mount['Destination'], '/')
                : '';
            return $destination !== self::MU_PATH
                && str_starts_with($destination . '/', self::MU_PATH . '/');
        })) : [];
        if ($result['exit'] !== 0 || count($mounted) !== 1 || ($mounted[0]['RW'] ?? null) !== true) {
            throw new \RuntimeException('Running Docker container has no unambiguous writable persistent webroot');
        }
        if ($mountedDescendants !== []) {
            throw new \RuntimeException('Running Docker container has unsupported mounts below the MU control path');
        }
        $declaredType = $declared[0]['type'];
        if (($mounted[0]['Type'] ?? null) !== $declaredType) {
            throw new \RuntimeException('Running Docker webroot storage disagrees with its Compose declaration');
        }
        $source = $declared[0]['source'] ?? null;
        if (!is_string($source) || $source === '') {
            throw new \RuntimeException('Docker Compose webroot storage has no stable source');
        }
        if ($declaredType === 'bind') {
            $declaredSource = realpath($source);
            $mountedSource = realpath((string) ($mounted[0]['Source'] ?? ''));
            if ($declaredSource === false || $mountedSource === false || $declaredSource === '/' || $declaredSource !== $mountedSource) {
                throw new \RuntimeException('Docker Compose webroot bind is unsafe or disagrees with the running container');
            }
            self::assertEffectiveControlStorage($config, $volumes, $mounts);
            return;
        }
        $top = $config['volumes'][$source] ?? null;
        $actualName = is_array($top) && is_string($top['name'] ?? null) ? $top['name'] : $source;
        if (($mounted[0]['Name'] ?? null) !== $actualName) {
            throw new \RuntimeException('Docker Compose webroot volume disagrees with the running container');
        }
        self::assertEffectiveControlStorage($config, $volumes, $mounts);
    }

    /** @param array<string,mixed> $config @param list<mixed> $declared @param list<mixed> $actual */
    private static function assertEffectiveControlStorage(array $config, array $declared, array $actual): void
    {
        $declaredMatches = [];
        foreach ($declared as $mount) {
            $target = is_array($mount) && is_string($mount['target'] ?? null)
                ? rtrim($mount['target'], '/')
                : '';
            if (self::MU_PATH === $target || str_starts_with(self::MU_PATH . '/', $target . '/')) {
                $declaredMatches[] = [$mount, $target];
            }
        }
        usort($declaredMatches, static fn(array $left, array $right): int => strlen($right[1]) <=> strlen($left[1]));
        if ($declaredMatches === []
            || (isset($declaredMatches[1]) && strlen($declaredMatches[1][1]) === strlen($declaredMatches[0][1]))) {
            throw new \RuntimeException('Docker Compose MU control path has ambiguous persistent storage');
        }
        [$declaredMount, $declaredTarget] = $declaredMatches[0];
        $type = is_array($declaredMount) ? ($declaredMount['type'] ?? null) : null;
        $source = is_array($declaredMount) ? ($declaredMount['source'] ?? null) : null;
        $readOnly = is_array($declaredMount) && array_key_exists('read_only', $declaredMount)
            ? $declaredMount['read_only']
            : false;
        if (!in_array($type, ['bind', 'volume'], true) || !is_string($source) || $source === ''
            || !is_bool($readOnly) || $readOnly) {
            throw new \RuntimeException('Docker Compose MU control path is not on writable persistent storage');
        }
        if ($type === 'volume') {
            $definition = is_array($config['volumes'] ?? null) ? ($config['volumes'][$source] ?? null) : null;
            if (is_array($definition) && is_string($definition['name'] ?? null) && $definition['name'] !== '') {
                $source = $definition['name'];
            }
        }

        $actualMatches = [];
        foreach ($actual as $mount) {
            $destination = is_array($mount) && is_string($mount['Destination'] ?? null)
                ? rtrim($mount['Destination'], '/')
                : '';
            if (self::MU_PATH === $destination || str_starts_with(self::MU_PATH . '/', $destination . '/')) {
                $actualMatches[] = [$mount, $destination];
            }
        }
        usort($actualMatches, static fn(array $left, array $right): int => strlen($right[1]) <=> strlen($left[1]));
        if ($actualMatches === []
            || (isset($actualMatches[1]) && strlen($actualMatches[1][1]) === strlen($actualMatches[0][1]))) {
            throw new \RuntimeException('Running Docker MU control path has ambiguous persistent storage');
        }
        [$actualMount, $actualTarget] = $actualMatches[0];
        $actualSource = $type === 'volume'
            ? (is_array($actualMount) ? ($actualMount['Name'] ?? null) : null)
            : (is_array($actualMount) ? ($actualMount['Source'] ?? null) : null);
        if (!is_array($actualMount) || ($actualMount['Type'] ?? null) !== $type
            || ($actualMount['RW'] ?? null) !== true || $actualSource !== $source
            || substr(self::MU_PATH, strlen($actualTarget)) !== substr(self::MU_PATH, strlen($declaredTarget))) {
            throw new \RuntimeException('Running Docker MU control storage disagrees with its Compose declaration');
        }
    }

    /**
     * @param callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $run
     * @return array{0:string,1:string}
     */
    private static function wordpressIdentity(callable $run, string $container): array
    {
        $uid = self::command($run, ['docker', 'exec', $container, 'id', '-u', 'www-data'], 30000);
        $gid = self::command($run, ['docker', 'exec', $container, 'id', '-g', 'www-data'], 30000);
        $uidValue = trim($uid['stdout']);
        $gidValue = trim($gid['stdout']);
        if ($uid['exit'] !== 0 || $gid['exit'] !== 0
            || !preg_match('/^[1-9][0-9]*$/D', $uidValue)
            || !preg_match('/^[1-9][0-9]*$/D', $gidValue)) {
            throw new \RuntimeException('Could not determine the running WordPress www-data identity');
        }
        return [$uidValue, $gidValue];
    }

    /**
     * @param callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $run
     * @return array{0:string,1:bool}
     */
    private static function image(
        callable $run,
        string $dockerfile,
        string $name,
        string $hash,
        string $directory
    ): array {
        $inspect = self::command(
            $run,
            ['docker', 'image', 'inspect', '--format', '{{json .Id}}|{{json .Config.Labels}}', $name],
            30000
        );
        if ($inspect['exit'] === 0) {
            $imageId = self::verifiedImageInspection($inspect['stdout'], $hash);
            self::verifyTools($run, $imageId);
            return [$imageId, false];
        }
        $iidFile = $directory . '/image-id.next';
        @unlink($iidFile);
        $build = self::command($run, [
            'docker', 'build', '--file', $dockerfile, '--tag', $name, '--iidfile', $iidFile,
            '--label', self::IDENTITY_LABEL . '=' . $hash, dirname($dockerfile),
        ], 300000);
        if ($build['exit'] !== 0 || !is_file($iidFile)) {
            @unlink($iidFile);
            throw new \RuntimeException('Managed WP-CLI image build failed');
        }
        $imageId = trim((string) file_get_contents($iidFile));
        @unlink($iidFile);
        if (!preg_match('/^sha256:[a-f0-9]{64}$/D', $imageId)) {
            throw new \RuntimeException('Managed WP-CLI image build returned an invalid immutable image ID');
        }
        $verify = self::command(
            $run,
            ['docker', 'image', 'inspect', '--format', '{{json .Id}}|{{json .Config.Labels}}', $name],
            30000
        );
        try {
            if ($verify['exit'] !== 0 || self::verifiedImageInspection($verify['stdout'], $hash) !== $imageId) {
                throw new \RuntimeException('Managed WP-CLI image identity verification failed');
            }
            self::verifyTools($run, $imageId);
        } catch (\Throwable $error) {
            self::bestEffort($run, ['docker', 'image', 'rm', $name]);
            throw $error;
        }
        return [$imageId, true];
    }

    /** @param callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $run */
    private static function verifyTools(callable $run, string $imageId): void
    {
        $tools = self::command($run, [
            'docker', 'run', '--rm', '--entrypoint', 'sh', $imageId, '-c',
            'test "$(git --version)" = "git version 2.54.0" && test "$(git lfs version | cut -d" " -f1)" = "git-lfs/3.7.1"',
        ], 30000);
        if ($tools['exit'] !== 0) {
            throw new \RuntimeException('Managed WP-CLI image tool version verification failed');
        }
    }

    private static function verifiedImageInspection(string $output, string $hash): string
    {
        $parts = explode('|', trim($output), 2);
        $id = json_decode($parts[0] ?? '', true);
        $labels = json_decode($parts[1] ?? '', true);
        if (!is_string($id) || !preg_match('/^sha256:[a-f0-9]{64}$/D', $id)
            || !is_array($labels)
            || ($labels[self::MANAGED_LABEL] ?? null) !== 'docker-tooling'
            || ($labels[self::IDENTITY_LABEL] ?? null) !== $hash) {
            throw new \RuntimeException('Existing managed WP-CLI image has an unexpected identity');
        }
        return $id;
    }

    /**
     * @param callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $run
     * @return array{0:bool}
     */
    private static function volume(callable $run, string $volume, string $identity): array
    {
        $inspect = self::command(
            $run,
            ['docker', 'volume', 'inspect', '--format', '{{json .Labels}}', $volume],
            30000
        );
        if ($inspect['exit'] === 0) {
            $labels = json_decode(trim($inspect['stdout']), true);
            if (!is_array($labels)
                || ($labels[self::MANAGED_LABEL] ?? null) !== 'docker-tooling-repository'
                || ($labels[self::IDENTITY_LABEL] ?? null) !== $identity) {
                throw new \RuntimeException("Existing Docker volume '$volume' is not owned by this managed environment");
            }
            return [false];
        }
        $create = self::command($run, [
            'docker', 'volume', 'create',
            '--label', self::MANAGED_LABEL . '=docker-tooling-repository',
            '--label', self::IDENTITY_LABEL . '=' . $identity,
            $volume,
        ], 30000);
        if ($create['exit'] !== 0 || trim($create['stdout']) !== $volume) {
            if ($create['exit'] === 0) {
                self::bestEffort($run, ['docker', 'volume', 'rm', $volume]);
            }
            throw new \RuntimeException('Could not create the managed repository volume');
        }
        return [true];
    }

    /** @param callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $run */
    private static function assertVolumeIdle(callable $run, string $volume): void
    {
        $result = self::command($run, ['docker', 'ps', '-aq', '--filter', 'volume=' . $volume], 30000);
        if ($result['exit'] !== 0 || trim($result['stdout']) !== '') {
            throw new \RuntimeException('Managed repository volume is already attached; refusing concurrent writers');
        }
    }

    private static function writeOverlay(
        string $path,
        string $composeFile,
        string $wordpressService,
        string $service,
        string $imageId,
        string $volume,
        string $uid,
        string $gid,
        string $identity
    ): bool {
        $document = [
            'services' => [
                $service => [
                    'extends' => ['file' => $composeFile, 'service' => $wordpressService],
                    'image' => $imageId,
                    'entrypoint' => [],
                    'working_dir' => self::WP_PATH,
                    'user' => $uid . ':' . $gid,
                    'volumes' => [$volume . ':' . self::REPOSITORY_ROOT],
                    'labels' => [
                        self::MANAGED_LABEL => 'docker-tooling-service',
                        self::IDENTITY_LABEL => $identity,
                    ],
                ],
            ],
            'volumes' => [
                $volume => ['external' => true, 'name' => $volume],
            ],
        ];
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (is_file($path)) {
            clearstatcache(true, $path);
            if ((fileperms($path) & 0777) !== 0600 || file_get_contents($path) !== $json) {
                throw new \RuntimeException('Existing private managed Docker Compose overlay has unexpected bytes or permissions');
            }
            return false;
        }
        $next = $path . '.next.' . getmypid();
        if (file_put_contents($next, $json, LOCK_EX) !== strlen($json) || !chmod($next, 0600) || !rename($next, $path)) {
            @unlink($next);
            throw new \RuntimeException('Could not publish the private managed Docker Compose overlay');
        }
        clearstatcache(true, $path);
        if ((fileperms($path) & 0777) !== 0600) {
            @unlink($path);
            throw new \RuntimeException('Private managed Docker Compose overlay permissions are not 0600');
        }
        return true;
    }

    /**
     * @param callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $run
     * @param list<string> $base
     */
    private static function validateOverlay(callable $run, array $base, string $overlay, string $service): void
    {
        $result = self::command($run, array_merge($base, ['-f', $overlay, 'config', '--services']), 30000);
        $services = array_map('trim', explode("\n", $result['stdout']));
        if ($result['exit'] !== 0 || !in_array($service, $services, true)) {
            throw new \RuntimeException('Managed Docker Compose overlay validation failed');
        }
    }

    /**
     * @param callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $run
     * @param list<string> $base
     */
    private static function initializeRepositoryVolume(
        callable $run,
        array $base,
        string $overlay,
        string $service,
        string $uid,
        string $gid,
        bool $created
    ): void {
        $test = $created
            ? 'chown ' . $uid . ':' . $gid . ' ' . self::REPOSITORY_ROOT . ' && test ! -e ' . self::REPOSITORY_PATH
            : 'test ! -e ' . self::REPOSITORY_PATH . ' || test -d ' . self::REPOSITORY_PATH;
        $result = self::command($run, array_merge($base, [
            '-f', $overlay, 'run', '--rm', '-T', '--no-deps', '--user', '0:0',
            $service, 'sh', '-c', $test,
        ]), 30000);
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('Managed repository volume initialization failed');
        }
    }

    /** @param callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $run */
    private static function createLease(callable $run, string $lease, string $imageId, string $identity): void
    {
        $result = self::command($run, [
            'docker', 'create', '--name', $lease,
            '--label', self::MANAGED_LABEL . '=docker-tooling-lease',
            '--label', self::IDENTITY_LABEL . '=' . $identity,
            $imageId, 'true',
        ], 30000);
        if ($result['exit'] !== 0 || !preg_match('/^[a-f0-9]{12,64}\s*$/D', $result['stdout'])) {
            throw new \RuntimeException('Managed Docker tooling is already in use by another process');
        }
    }

    /** @return list<string> */
    private static function composeTokens(
        string $file,
        ?string $envFile,
        ?string $profile,
        ?string $project
    ): array
    {
        $tokens = ['docker', 'compose'];
        if ($project !== null) {
            array_push($tokens, '--project-name', $project);
        }
        if ($envFile !== null) {
            array_push($tokens, '--env-file', $envFile);
        }
        array_push($tokens, '-f', $file);
        if ($profile !== null) {
            array_push($tokens, '--profile', $profile);
        }
        return $tokens;
    }

    private static function regularFile(string $path, string $label): string
    {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new \RuntimeException("$label is not a readable regular file: $path");
        }
        return $real;
    }

    private static function validateName(string $value, string $label): void
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $value)) {
            throw new \RuntimeException("Invalid $label name");
        }
    }

    private static function privateRoot(?string $requested): string
    {
        if ($requested !== null) {
            if (!str_starts_with($requested, '/')) {
                throw new \RuntimeException('Managed tooling private root must be absolute');
            }
            $root = rtrim($requested, '/');
        } else {
            $home = getenv('HOME');
            if (!is_string($home) || !str_starts_with($home, '/')) {
                throw new \RuntimeException('HOME must be an absolute path for managed Docker tooling');
            }
            $root = rtrim($home, '/') . '/.wprism/docker-tooling';
        }
        self::privateDirectory($root);
        return $root;
    }

    private static function privateDirectory(string $directory): void
    {
        if (is_link($directory)) {
            throw new \RuntimeException("Managed tooling private directory must not be a symlink: $directory");
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new \RuntimeException("Could not create managed tooling private directory: $directory");
        }
        if (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid()) {
            throw new \RuntimeException("Managed tooling private directory is not owned by this user: $directory");
        }
        if (!chmod($directory, 0700)) {
            throw new \RuntimeException("Could not secure managed tooling private directory: $directory");
        }
    }

    /** @return resource */
    private static function lock(string $path)
    {
        $lock = fopen($path, 'c+');
        if ($lock === false || !chmod($path, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new \RuntimeException('Managed Docker tooling preparation is already in progress');
        }
        return $lock;
    }

    /**
     * @param callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $run
     * @param list<string> $argv
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private static function command(callable $run, array $argv, int $timeout): array
    {
        $result = $run($argv, null, $timeout);
        if (!is_array($result) || !is_int($result['exit'] ?? null)
            || !is_string($result['stdout'] ?? null) || !is_string($result['stderr'] ?? null)) {
            throw new \RuntimeException('Docker control process returned a malformed result');
        }
        return $result;
    }

    /**
     * @param callable(list<string>,?string,int):array{exit:int,stdout:string,stderr:string} $run
     * @param list<string> $argv
     */
    private static function bestEffort(callable $run, array $argv): void
    {
        try {
            self::command($run, $argv, 30000);
        } catch (\Throwable $ignored) {
        }
    }
}
