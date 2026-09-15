<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/../Onboarding/Adopt.php';
require_once __DIR__ . '/../Onboarding/ConnectionReceipt.php';
require_once __DIR__ . '/../Onboarding/DockerTooling.php';
require_once __DIR__ . '/../Transport/Transport.php';
require_once __DIR__ . '/../Transport/LocalTransport.php';
require_once __DIR__ . '/../Transport/DockerTransport.php';
require_once __DIR__ . '/../Transport/SshTransport.php';
require_once __DIR__ . '/HostProcess.php';
require_once __DIR__ . '/CommandOutput.php';

use WPrism\Canon;

/** Create the local half of a WPrism relationship after native inspection probes. */
final class ConnectCommand {
    private const PROBE_TIMEOUT_MILLISECONDS = 120000;
    private const PROBE_OUTPUT_LIMIT_BYTES = 1048576;
    /**
     * @param list<string> $args everything after `connect`
     * @param ?callable(string,array<string,mixed>):EnvironmentDriver $transportFactory
     * @param ?callable(list<string>,?string):array{exit:int,stdout:string,stderr:string} $processRunner
     * @param ?callable():string $clock
     */
    public static function run(
        array $args,
        string $sourceRoot,
        ?callable $transportFactory = null,
        ?callable $processRunner = null,
        ?callable $clock = null
    ): int {
        $json = in_array('--format=json', $args, true);
        $toolingReceipt = null;
        try {
            [$json, $arguments] = self::machineArguments($args);
            $request = self::parse($arguments, getcwd() ?: '.');
            if (($request['config']['transport'] ?? null) === 'docker'
                && ($request['config']['tooling'] ?? null) === 'managed') {
                $toolingReceipt = DockerTooling::prepare(
                    $request['environment'],
                    (string) $request['config']['compose_file'],
                    is_string($request['config']['compose_env_file'] ?? null)
                        ? $request['config']['compose_env_file']
                        : null,
                    is_string($request['config']['profile'] ?? null) ? $request['config']['profile'] : null,
                    (string) $request['config']['wordpress_service']
                );
                foreach (['compose_overlay_file', 'service', 'wordpress_service', 'wp_path', 'repo_path'] as $key) {
                    if (!is_string($toolingReceipt[$key] ?? null) || $toolingReceipt[$key] === '') {
                        throw new \RuntimeException("managed Docker tooling returned no $key");
                    }
                    $request['config'][$key] = $toolingReceipt[$key];
                }
                $request['config']['docker_context'] = self::toolingString($toolingReceipt, 'docker_context');
                $request['config']['docker_endpoint'] = self::toolingString($toolingReceipt, 'docker_endpoint');
                $request['config']['compose_project'] = self::toolingString($toolingReceipt, 'compose_project');
                $request['config']['tooling_provenance'] = [
                    'format' => DockerTransport::TOOLING_PROVENANCE_FORMAT,
                    'image_id' => self::toolingString($toolingReceipt, 'image_id'),
                    'repository_volume' => self::toolingString($toolingReceipt, 'repository_volume'),
                    'tooling_identity' => self::toolingString($toolingReceipt, 'tooling_identity'),
                    'toolchain_identity' => self::toolingString($toolingReceipt, 'toolchain_identity'),
                    'overlay_sha256' => self::toolingString($toolingReceipt, 'overlay_sha256'),
                ];
            }
            $factory = $transportFactory ?? static fn(string $name, array $config): EnvironmentDriver =>
                Transport::make($name, $config);
            $driver = $factory($request['environment'], $request['config']);
            if (!$driver instanceof BoundedControlDriver) {
                throw new \RuntimeException('selected driver does not implement bounded target control');
            }
            if ($driver instanceof DockerTransport && isset($request['config']['bootstrap'])) {
                $provenance = $driver->localBootstrapProvenance();
                $request['config']['docker_context'] = $provenance['docker_context'];
                $request['config']['docker_endpoint'] = $provenance['docker_endpoint'];
                $driver = $factory($request['environment'], $request['config']);
                if (!$driver instanceof DockerTransport) {
                    throw new \RuntimeException('Docker transport factory did not preserve the pinned control plane');
                }
                $driver->assertLocalBootstrapControlPlane();
            }
            if ($driver instanceof Transport && ($hostRepo = $driver->hostRepoBoundaryPath()) !== null) {
                self::assertDisjointHostBoundaries($request['workspace'], $hostRepo);
            }
            self::probe($driver);
            $environmentConfig = self::createWorkspace(
                $request['workspace'],
                $request['environment'],
                $request['config'],
                $processRunner
            );
            if (is_array($toolingReceipt)) {
                DockerTooling::release($toolingReceipt);
            }
        } catch (\Throwable $error) {
            if (is_array($toolingReceipt)) {
                try {
                    DockerTooling::rollback($toolingReceipt);
                } catch (\Throwable $rollbackError) {
                    $error = new \RuntimeException(
                        $error->getMessage() . '; managed Docker tooling rollback also failed: '
                        . $rollbackError->getMessage(),
                        0,
                        $error
                    );
                }
            }
            if ($json) {
                return CommandOutput::renderRefusalJson(
                    'connect',
                    'connection_failed',
                    'WPrism could not establish the inspected local connection boundary',
                    'repair the named transport, WordPress topology, or workspace boundary, then retry connect',
                    []
                );
            }
            fwrite(STDERR, 'wprism: connect: ' . $error->getMessage() . "\n");
            return 1;
        }

        if ($json) {
            echo Canon::encode(ConnectionReceipt::build(
                $request['environment'],
                $request['workspace'],
                (string) $request['config']['transport'],
                $environmentConfig,
                ($clock ?? static fn(): string => gmdate('Y-m-d\TH:i:s\Z'))()
            ));
            return 0;
        }

        $cli = realpath($sourceRoot . '/cli/wprism') ?: $sourceRoot . '/cli/wprism';
        echo "Connected after inspection: WordPress is reachable and single-site.\n";
        self::renderMutationDisclosure($request['config']);
        echo "Workspace: {$request['workspace']}\n";
        echo "Next:\n";
        echo '  cd ' . escapeshellarg($request['workspace']) . "\n";
        if (($request['config']['transport'] ?? null) === 'docker') {
            if (isset($request['config']['bootstrap'])) {
                echo "  # The inspected local Docker control plane can deliver WPrism without changing the Compose application.\n";
                echo '  ' . escapeshellarg($cli) . ' assess ' . escapeshellarg($request['environment']) . "\n";
                echo '  ' . escapeshellarg($cli) . ' onboard ' . escapeshellarg($request['environment']) . "\n";
                echo "Add --git-url=<empty-remote-url> to onboard to preflight and automate the initialized repository handoff.\n";
            } else {
                echo "  # This attachment has no Docker bootstrap authority; assess the preinstalled agent.\n";
                echo '  ' . escapeshellarg($cli) . ' assess ' . escapeshellarg($request['environment']) . "\n";
            }
        } elseif (($request['config']['transport'] ?? null) === 'local') {
            echo "  # If the target already carries WPrism, assess it; otherwise run the bootstrap-capable onboarding path.\n";
            echo '  ' . escapeshellarg($cli) . ' assess ' . escapeshellarg($request['environment']) . "\n";
            echo '  ' . escapeshellarg($cli) . ' onboard ' . escapeshellarg($request['environment']) . "\n";
            echo "Add --git-url=<empty-remote-url> to onboard to preflight and automate the initialized repository handoff.\n";
        } else {
            echo '  ' . escapeshellarg($cli) . ' onboard ' . escapeshellarg($request['environment']) . "\n";
            echo "Add --git-url=<empty-remote-url> to preflight and automate the initialized repository handoff.\n";
        }
        return 0;
    }

    /** @param array<string,mixed> $config */
    private static function renderMutationDisclosure(array $config): void {
        if (($config['transport'] ?? null) === 'docker' && ($config['tooling'] ?? null) === 'managed') {
            echo "Managed Docker setup created or reused a WPrism-owned tooling image, private overlay, and durable repository volume.\n";
            echo "It ran disposable helper containers without editing or starting the Compose application; WordPress and site startup code may have run during inspection.\n";
            return;
        }
        echo "WPrism issued no explicit mutation, but topology inspection bootstrapped WordPress and site startup code may have run.\n";
    }

    /** @param array<string,mixed> $receipt */
    private static function toolingString(array $receipt, string $key): string {
        $value = $receipt[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException("managed Docker tooling returned no $key");
        }
        return $value;
    }

    /** @param list<mixed> $args @return array{0:bool,1:list<string>} */
    private static function machineArguments(array $args): array {
        $json = false;
        $out = [];
        foreach ($args as $arg) {
            if (!is_string($arg)) {
                throw new \RuntimeException('connect received a non-string argument');
            }
            if ($arg === '--format=json') {
                if ($json) {
                    throw new \RuntimeException('--format=json was supplied more than once');
                }
                $json = true;
                continue;
            }
            if (str_starts_with($arg, '--format')) {
                throw new \RuntimeException('connect accepts only the exact machine selector --format=json');
            }
            $out[] = $arg;
        }
        return [$json, $out];
    }

    /**
     * @return array{environment:string,workspace:string,config:array<string,mixed>}
     */
    public static function parse(array $args, string $cwd): array {
        $environment = array_shift($args);
        if (!is_string($environment)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $environment) !== 1) {
            throw new \RuntimeException('usage: wprism connect <env> --workspace=<path> --transport=ssh|local|docker ...');
        }

        $values = [];
        foreach ($args as $arg) {
            if (!is_string($arg) || preg_match('/^--([a-z][a-z-]*)=(.+)$/D', $arg, $match) !== 1) {
                throw new \RuntimeException("unsupported argument '$arg'; connect accepts only --name=value flags");
            }
            $key = str_replace('-', '_', $match[1]);
            if (isset($values[$key])) {
                throw new \RuntimeException("--{$match[1]} was supplied more than once");
            }
            $values[$key] = $match[2];
        }

        $allowed = [
            'workspace', 'transport', 'host', 'wp_path', 'repo_path', 'ssh_config',
            'compose_file', 'compose_overlay_file', 'compose_env_file', 'service', 'wordpress_service',
            'profile', 'mode', 'tooling',
        ];
        $unknown = array_diff(array_keys($values), $allowed);
        if ($unknown !== []) {
            throw new \RuntimeException('unsupported flag --' . str_replace('_', '-', (string) reset($unknown)));
        }
        foreach (['workspace', 'transport'] as $required) {
            if (!is_string($values[$required] ?? null) || trim((string) $values[$required]) === '') {
                throw new \RuntimeException('missing required --' . str_replace('_', '-', $required) . '=<value>');
            }
        }
        $transport = (string) $values['transport'];
        if (!in_array($transport, ['ssh', 'local', 'docker'], true)) {
            throw new \RuntimeException('--transport must be ssh, local, or docker');
        }
        $tooling = $values['tooling'] ?? null;
        if ($tooling !== null && ($transport !== 'docker' || $tooling !== 'managed')) {
            throw new \RuntimeException('--tooling accepts only managed with --transport=docker');
        }
        if ($transport === 'docker' && $tooling === 'managed') {
            foreach (['service', 'wp_path', 'repo_path', 'compose_overlay_file', 'mode'] as $managedKey) {
                if (isset($values[$managedKey])) {
                    throw new \RuntimeException(
                        '--tooling=managed owns --' . str_replace('_', '-', $managedKey) . '; remove that flag'
                    );
                }
            }
        }
        $requiredByTransport = match (true) {
            $transport === 'ssh' => ['host', 'wp_path'],
            $transport === 'docker' && $tooling === 'managed' => ['compose_file', 'wordpress_service'],
            $transport === 'docker' => ['compose_file', 'service', 'repo_path'],
            default => ['wp_path', 'repo_path'],
        };
        if ($transport === 'ssh') {
            $requiredByTransport[] = 'repo_path';
        }
        if ($transport === 'docker' && $tooling !== 'managed') {
            $hasWpPath = isset($values['wp_path']);
            $hasWordpressService = isset($values['wordpress_service']);
            if ($hasWpPath !== $hasWordpressService) {
                throw new \RuntimeException(
                    'Docker bootstrap requires --wp-path and --wordpress-service together; omit both for attachment only'
                );
            }
        }
        foreach ($requiredByTransport as $required) {
            if (!is_string($values[$required] ?? null) || trim((string) $values[$required]) === '') {
                throw new \RuntimeException("--transport=$transport requires --" . str_replace('_', '-', $required));
            }
        }

        $workspace = self::absolutePath($cwd, (string) $values['workspace']);
        $config = ['transport' => $transport, '_dir' => $workspace, '_machine_local' => true];
        foreach (array_diff($allowed, ['workspace', 'transport']) as $key) {
            if (isset($values[$key])) {
                $config[$key] = $values[$key];
            }
        }
        foreach (['ssh_config', 'compose_file', 'compose_overlay_file', 'compose_env_file'] as $pathKey) {
            if (is_string($config[$pathKey] ?? null)) {
                $config[$pathKey] = self::existingFile($cwd, (string) $config[$pathKey], '--' . str_replace('_', '-', $pathKey));
            }
        }
        if ($transport === 'local') {
            self::assertDisjointHostBoundaries($workspace, (string) $config['repo_path']);
            // Selecting a local target is the explicit machine-local opt-in
            // LocalTransport requires before adoption can bootstrap it.
            $config['bootstrap'] = ['format' => LocalTransport::BOOTSTRAP_FORMAT];
        } elseif ($transport === 'docker'
            && ($tooling === 'managed' || isset($values['wordpress_service']))) {
            $config['bootstrap'] = ['format' => DockerTransport::BOOTSTRAP_FORMAT];
        }

        return ['environment' => $environment, 'workspace' => $workspace, 'config' => $config];
    }

    private static function probe(BoundedControlDriver $driver): void {
        $reachable = $driver->captureRawBounded(
            'echo wprism-connect-ready',
            self::PROBE_TIMEOUT_MILLISECONDS,
            self::PROBE_OUTPUT_LIMIT_BYTES,
            self::PROBE_OUTPUT_LIMIT_BYTES
        );
        if ($reachable['exit'] !== 0 || trim($reachable['stdout']) !== 'wprism-connect-ready') {
            throw new \RuntimeException('target transport is not reachable; no workspace was created');
        }
        $wordpress = $driver->captureWpBounded(
            ['core', 'is-installed'],
            self::PROBE_TIMEOUT_MILLISECONDS,
            self::PROBE_OUTPUT_LIMIT_BYTES,
            self::PROBE_OUTPUT_LIMIT_BYTES
        );
        if ($wordpress['exit'] !== 0) {
            throw new \RuntimeException('WordPress is not installed or wp-cli cannot read it; no workspace was created');
        }
        $topology = $driver->captureWpBounded(
            ['eval', 'echo is_multisite() ? "multisite" : "single-site";'],
            self::PROBE_TIMEOUT_MILLISECONDS,
            self::PROBE_OUTPUT_LIMIT_BYTES,
            self::PROBE_OUTPUT_LIMIT_BYTES
        );
        if ($topology['exit'] !== 0 || trim($topology['stdout']) !== 'single-site') {
            throw new \RuntimeException('the certified onboarding path supports single-site WordPress only; no workspace was created');
        }
    }

    /** @param array<string,mixed> $config */
    private static function createWorkspace(
        string $workspace,
        string $environment,
        array $config,
        ?callable $processRunner
    ): string {
        if (file_exists($workspace) || is_link($workspace)) {
            throw new \RuntimeException("workspace must not already exist: $workspace");
        }

        $overlayConfig = $config;
        unset($overlayConfig['_dir'], $overlayConfig['_machine_local']);
        $overlay = json_encode(
            ['envs' => [$environment => $overlayConfig]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($overlay)) {
            throw new \RuntimeException('could not encode the machine-local environment registry');
        }

        $stage = dirname($workspace) . '/.wprism-connect-' . bin2hex(random_bytes(16));
        $claim = $stage . '.owned';
        if (!mkdir($stage, 0700) || is_link($stage)) {
            throw new \RuntimeException('could not reserve a private workspace staging directory');
        }
        $stageIdentity = self::pathIdentity($stage);
        try {
            self::writeNew($stage . '/site.wprism.json', Adopt::repositorySeedBytes(), 0644);
            self::writeNew($stage . '/.gitignore', Adopt::repositoryGitignoreBytes(), 0644);
            self::writeNew($stage . '/.wprism-envs.json', $overlay . "\n", 0600);
            $run = $processRunner ?? HostProcess::run(...);
            $git = $run(['git', 'init', '--initial-branch=main', $stage], null);
            if ($git['exit'] !== 0 || !is_dir($stage . '/.git')) {
                throw new \RuntimeException('could not initialize the workspace Git boundary: ' . trim($git['stderr']));
            }
            if (file_exists($claim) || is_link($claim) || !@rename($stage, $claim)) {
                throw new \RuntimeException('could not claim the private workspace staging directory');
            }
            if (self::pathIdentity($claim) !== $stageIdentity) {
                if (!file_exists($stage) && !is_link($stage)) {
                    @rename($claim, $stage);
                }
                throw new \RuntimeException('workspace staging identity changed before publication');
            }
            self::assertWorkspaceStage($claim, $stageIdentity, $overlay . "\n");
            if (file_exists($workspace) || is_link($workspace) || !@rename($claim, $workspace)) {
                throw new \RuntimeException('workspace destination appeared before atomic publication');
            }
            if (self::pathIdentity($workspace) !== $stageIdentity) {
                if (!file_exists($claim) && !is_link($claim)) {
                    @rename($workspace, $claim);
                }
                throw new \RuntimeException('workspace staging identity changed during publication');
            }
            return $overlay . "\n";
        } catch (\Throwable $error) {
            $retained = false;
            foreach ([$claim, $stage] as $candidate) {
                if (!file_exists($candidate) && !is_link($candidate)) {
                    continue;
                }
                if (!self::removeOwnedTree($candidate, $stageIdentity)) {
                    $retained = true;
                }
            }
            if ($retained) {
                throw new \RuntimeException($error->getMessage() . '; changed staging bytes were retained');
            }
            throw $error;
        }
    }

    private static function writeNew(string $path, string $bytes, int $mode): void {
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException("refusing to overwrite $path");
        }
        $handle = @fopen($path, 'x+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException("could not exclusively create $path");
        }
        $offset = 0;
        try {
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new \RuntimeException("could not publish $path");
                }
                $offset += $written;
            }
            $opened = fstat($handle);
            $named = @lstat($path);
            if (!is_array($opened) || !is_array($named)
                || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']
                || !@chmod($path, $mode) || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) {
                throw new \RuntimeException("could not publish $path");
            }
        } catch (\Throwable $error) {
            $opened = fstat($handle);
            fclose($handle);
            $named = @lstat($path);
            if (is_array($opened) && is_array($named)
                && $opened['dev'] === $named['dev'] && $opened['ino'] === $named['ino']) {
                @unlink($path);
            }
            throw $error;
        }
        fclose($handle);
    }

    /** @param array{dev:string,ino:string,type:string} $identity */
    private static function removeOwnedTree(string $root, array $identity): bool {
        try {
            $current = self::pathIdentity($root);
        } catch (\Throwable) {
            return false;
        }
        if ($current !== $identity) {
            return false;
        }
        $claim = $root . '.remove-' . bin2hex(random_bytes(16));
        if (file_exists($claim) || is_link($claim) || !@rename($root, $claim)) {
            return false;
        }
        if (self::pathIdentity($claim) !== $identity) {
            if (!file_exists($root) && !is_link($root)) {
                @rename($claim, $root);
            }
            return false;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($claim, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $entry->isDir() && !$entry->isLink() ? @rmdir($path) : @unlink($path);
        }
        return @rmdir($claim);
    }

    /** @param array{dev:string,ino:string,type:string} $identity */
    private static function assertWorkspaceStage(string $stage, array $identity, string $overlay): void {
        if (self::pathIdentity($stage) !== $identity) {
            throw new \RuntimeException('workspace staging identity changed before publication');
        }
        $entries = array_values(array_diff(scandir($stage) ?: [], ['.', '..']));
        sort($entries, SORT_STRING);
        if ($entries !== ['.git', '.gitignore', '.wprism-envs.json', 'site.wprism.json']
            || is_link($stage . '/.git') || !is_dir($stage . '/.git')) {
            throw new \RuntimeException('workspace staging contents changed before publication');
        }
        foreach ([
            'site.wprism.json' => Adopt::repositorySeedBytes(),
            '.gitignore' => Adopt::repositoryGitignoreBytes(),
            '.wprism-envs.json' => $overlay,
        ] as $name => $expected) {
            $path = $stage . '/' . $name;
            if (is_link($path) || !is_file($path) || file_get_contents($path) !== $expected) {
                throw new \RuntimeException('workspace staging contents changed before publication');
            }
        }
    }

    /** @return array{dev:string,ino:string,type:string} */
    private static function pathIdentity(string $path): array {
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || (!is_dir($path) && !is_file($path))) {
            throw new \RuntimeException("workspace staging path is not ordinary: $path");
        }
        return [
            'dev' => (string) $stat['dev'],
            'ino' => (string) $stat['ino'],
            'type' => is_dir($path) ? 'directory' : 'file',
        ];
    }

    private static function absolutePath(string $cwd, string $path): string {
        if ($path === '' || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            throw new \RuntimeException('--workspace must name a non-empty path without control bytes');
        }
        $absolute = str_starts_with($path, '/') ? $path : rtrim($cwd, '/') . '/' . $path;
        $parent = realpath(dirname($absolute));
        $basename = basename($absolute);
        if ($parent === false || !is_dir($parent) || $basename === '.' || $basename === '..') {
            throw new \RuntimeException('workspace must be a new direct child of an existing directory');
        }
        return rtrim($parent, '/') . '/' . $basename;
    }

    private static function existingFile(string $cwd, string $path, string $flag): string {
        if ($path === '' || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            throw new \RuntimeException("$flag must name a readable local file without control bytes");
        }
        $candidate = str_starts_with($path, '/') ? $path : rtrim($cwd, '/') . '/' . $path;
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new \RuntimeException("$flag file not found or unreadable: $path");
        }
        return $resolved;
    }

    private static function assertDisjointHostBoundaries(string $workspace, string $repoPath): void {
        $workspaceBoundary = self::comparableBoundary(self::physicalBoundary($workspace));
        $repoBoundary = self::comparableBoundary(self::physicalBoundary($repoPath));
        if ($workspaceBoundary === $repoBoundary
            || str_starts_with($workspaceBoundary, $repoBoundary . '/')
            || str_starts_with($repoBoundary, $workspaceBoundary . '/')) {
            throw new \RuntimeException('--workspace and the writable host repository must be disjoint, non-nested paths');
        }
    }

    private static function comparableBoundary(string $path): string {
        $normalized = false;
        if (class_exists('Normalizer')) {
            $normalized = \Normalizer::normalize($path, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                $path = $normalized;
                $normalized = true;
            }
        }
        return self::foldComparableBoundary($path, $normalized, function_exists('mb_strtolower'));
    }

    private static function foldComparableBoundary(
        string $path,
        bool $normalizationAvailable,
        bool $unicodeCasefoldAvailable
    ): string {
        if (!$normalizationAvailable || !$unicodeCasefoldAvailable) {
            $segments = explode('/', $path);
            $path = implode('/', array_map(
                static fn(string $segment): string => preg_match('/[^\x00-\x7f]/', $segment) === 1
                    ? "\x01unicode-boundary"
                    : $segment,
                $segments
            ));
        }
        return $unicodeCasefoldAvailable ? mb_strtolower($path, 'UTF-8') : strtolower($path);
    }

    private static function physicalBoundary(string $path): string {
        if (!str_starts_with($path, '/') || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            throw new \RuntimeException('local path boundaries must be absolute and normalized');
        }
        $segments = explode('/', substr($path, 1));
        if ($path === '/' || $path !== rtrim($path, '/')
            || array_filter($segments, static fn(string $part): bool => $part === '' || $part === '.' || $part === '..') !== []) {
            throw new \RuntimeException('local path boundaries must be absolute and normalized');
        }
        $suffix = [];
        $cursor = rtrim($path, '/');
        while (($resolved = realpath($cursor)) === false) {
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                throw new \RuntimeException('local path boundary has no existing physical ancestor');
            }
            array_unshift($suffix, basename($cursor));
            $cursor = $parent;
        }
        return rtrim($resolved, '/') . ($suffix === [] ? '' : '/' . implode('/', $suffix));
    }
}
