<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Onboarding/Adopt.php';
require_once __DIR__ . '/../Transport/Transport.php';
require_once __DIR__ . '/../Transport/LocalTransport.php';
require_once __DIR__ . '/../Transport/DockerTransport.php';
require_once __DIR__ . '/../Transport/SshTransport.php';
require_once __DIR__ . '/HostProcess.php';

/** Create the local half of a Duo relationship after native inspection probes. */
final class ConnectCommand {
    /**
     * @param list<string> $args everything after `connect`
     * @param ?callable(string,array<string,mixed>):EnvironmentDriver $transportFactory
     * @param ?callable(list<string>,?string):array{exit:int,stdout:string,stderr:string} $processRunner
     */
    public static function run(
        array $args,
        string $sourceRoot,
        ?callable $transportFactory = null,
        ?callable $processRunner = null
    ): int {
        try {
            $request = self::parse($args, getcwd() ?: '.');
            $factory = $transportFactory ?? static fn(string $name, array $config): EnvironmentDriver =>
                Transport::make($name, $config);
            $driver = $factory($request['environment'], $request['config']);
            self::probe($driver);
            self::createWorkspace($request['workspace'], $request['environment'], $request['config'], $processRunner);
        } catch (\Throwable $error) {
            fwrite(STDERR, 'duo: connect: ' . $error->getMessage() . "\n");
            return 1;
        }

        $cli = realpath($sourceRoot . '/cli/duo') ?: $sourceRoot . '/cli/duo';
        echo "Connected after inspection: WordPress is reachable and single-site.\n";
        echo "Duo issued no explicit mutation, but topology inspection bootstrapped WordPress and site startup code may have run.\n";
        echo "Workspace: {$request['workspace']}\n";
        echo "Next:\n";
        echo '  cd ' . escapeshellarg($request['workspace']) . "\n";
        if (($request['config']['transport'] ?? null) === 'docker') {
            echo "  # Docker cannot deliver the agent. Mount/install it through the container control plane first.\n";
            echo '  ' . escapeshellarg($cli) . ' assess ' . escapeshellarg($request['environment']) . "\n";
        } else {
            echo '  ' . escapeshellarg($cli) . ' onboard ' . escapeshellarg($request['environment']) . "\n";
            echo "Add --git-url=<empty-remote-url> to preflight and automate the initialized repository handoff.\n";
        }
        return 0;
    }

    /**
     * @return array{environment:string,workspace:string,config:array<string,mixed>}
     */
    public static function parse(array $args, string $cwd): array {
        $environment = array_shift($args);
        if (!is_string($environment)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $environment) !== 1) {
            throw new \RuntimeException('usage: duo connect <env> --workspace=<path> --transport=ssh|local|docker ...');
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
            'compose_file', 'compose_env_file', 'service', 'profile', 'mode',
        ];
        $unknown = array_diff(array_keys($values), $allowed);
        if ($unknown !== []) {
            throw new \RuntimeException('unsupported flag --' . str_replace('_', '-', (string) reset($unknown)));
        }
        foreach (['workspace', 'transport', 'repo_path'] as $required) {
            if (!is_string($values[$required] ?? null) || trim((string) $values[$required]) === '') {
                throw new \RuntimeException('missing required --' . str_replace('_', '-', $required) . '=<value>');
            }
        }
        $transport = (string) $values['transport'];
        if (!in_array($transport, ['ssh', 'local', 'docker'], true)) {
            throw new \RuntimeException('--transport must be ssh, local, or docker');
        }
        $requiredByTransport = match ($transport) {
            'ssh' => ['host', 'wp_path'],
            'docker' => ['compose_file', 'service'],
            default => ['wp_path'],
        };
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
        foreach (['ssh_config', 'compose_file', 'compose_env_file'] as $pathKey) {
            if (is_string($config[$pathKey] ?? null)) {
                $config[$pathKey] = self::existingFile($cwd, (string) $config[$pathKey], '--' . str_replace('_', '-', $pathKey));
            }
        }
        if ($transport === 'local') {
            self::assertDisjointLocalBoundaries($workspace, (string) $config['repo_path']);
            // Selecting a local target is the explicit machine-local opt-in
            // LocalTransport requires before adoption can bootstrap it.
            $config['bootstrap'] = ['format' => LocalTransport::BOOTSTRAP_FORMAT];
        }

        return ['environment' => $environment, 'workspace' => $workspace, 'config' => $config];
    }

    private static function probe(EnvironmentDriver $driver): void {
        $reachable = $driver->captureRaw('echo duo-connect-ready');
        if ($reachable['exit'] !== 0 || trim($reachable['stdout']) !== 'duo-connect-ready') {
            throw new \RuntimeException('target transport is not reachable; no workspace was created');
        }
        $wordpress = $driver->captureWp(['core', 'is-installed']);
        if ($wordpress['exit'] !== 0) {
            throw new \RuntimeException('WordPress is not installed or wp-cli cannot read it; no workspace was created');
        }
        $topology = $driver->captureWp(['eval', 'echo is_multisite() ? "multisite" : "single-site";']);
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
    ): void {
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

        $stage = dirname($workspace) . '/.duo-connect-' . bin2hex(random_bytes(16));
        if (!mkdir($stage, 0700) || is_link($stage)) {
            throw new \RuntimeException('could not reserve a private workspace staging directory');
        }
        try {
            self::writeNew($stage . '/site.duo.json', Adopt::repositorySeedBytes(), 0644);
            self::writeNew($stage . '/.gitignore', Adopt::repositoryGitignoreBytes(), 0644);
            self::writeNew($stage . '/.duo-envs.json', $overlay . "\n", 0600);
            $run = $processRunner ?? HostProcess::run(...);
            $git = $run(['git', 'init', '--initial-branch=main', $stage], null);
            if ($git['exit'] !== 0 || !is_dir($stage . '/.git')) {
                throw new \RuntimeException('could not initialize the workspace Git boundary: ' . trim($git['stderr']));
            }
            if (file_exists($workspace) || is_link($workspace) || !rename($stage, $workspace)) {
                throw new \RuntimeException('workspace destination appeared before atomic publication');
            }
        } catch (\Throwable $error) {
            self::removeTree($stage);
            throw $error;
        }
    }

    private static function writeNew(string $path, string $bytes, int $mode): void {
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException("refusing to overwrite $path");
        }
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes) || !chmod($path, $mode)) {
            throw new \RuntimeException("could not publish $path");
        }
    }

    private static function removeTree(string $root): void {
        if (!file_exists($root) && !is_link($root)) {
            return;
        }
        if (is_link($root) || is_file($root)) {
            unlink($root);
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $entry->isDir() && !$entry->isLink() ? rmdir($path) : unlink($path);
        }
        rmdir($root);
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

    private static function assertDisjointLocalBoundaries(string $workspace, string $repoPath): void {
        $workspaceBoundary = self::physicalBoundary($workspace);
        $repoBoundary = self::physicalBoundary($repoPath);
        if ($workspaceBoundary === $repoBoundary
            || str_starts_with($workspaceBoundary, $repoBoundary . '/')
            || str_starts_with($repoBoundary, $workspaceBoundary . '/')) {
            throw new \RuntimeException('local --workspace and --repo-path must be disjoint, non-nested paths');
        }
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
