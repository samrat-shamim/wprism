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
    private const PROBE_TIMEOUT_MILLISECONDS = 120000;
    private const PROBE_OUTPUT_LIMIT_BYTES = 1048576;
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
            if ($driver instanceof Transport && ($hostRepo = $driver->hostRepoBoundaryPath()) !== null) {
                self::assertDisjointHostBoundaries($request['workspace'], $hostRepo);
            }
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
        } elseif (($request['config']['transport'] ?? null) === 'local') {
            echo "  # If the target already carries Duo, assess it; otherwise run the bootstrap-capable onboarding path.\n";
            echo '  ' . escapeshellarg($cli) . ' assess ' . escapeshellarg($request['environment']) . "\n";
            echo '  ' . escapeshellarg($cli) . ' onboard ' . escapeshellarg($request['environment']) . "\n";
            echo "Add --git-url=<empty-remote-url> to onboard to preflight and automate the initialized repository handoff.\n";
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
            self::assertDisjointHostBoundaries($workspace, (string) $config['repo_path']);
            // Selecting a local target is the explicit machine-local opt-in
            // LocalTransport requires before adoption can bootstrap it.
            $config['bootstrap'] = ['format' => LocalTransport::BOOTSTRAP_FORMAT];
        }

        return ['environment' => $environment, 'workspace' => $workspace, 'config' => $config];
    }

    private static function probe(EnvironmentDriver $driver): void {
        if (!$driver instanceof Transport) {
            throw new \RuntimeException('connect requires a transport with bounded target control');
        }
        $reachable = $driver->captureRawBounded(
            'echo duo-connect-ready',
            self::PROBE_TIMEOUT_MILLISECONDS,
            self::PROBE_OUTPUT_LIMIT_BYTES,
            self::PROBE_OUTPUT_LIMIT_BYTES
        );
        if ($reachable['exit'] !== 0 || trim($reachable['stdout']) !== 'duo-connect-ready') {
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
        $claim = $stage . '.owned';
        if (!mkdir($stage, 0700) || is_link($stage)) {
            throw new \RuntimeException('could not reserve a private workspace staging directory');
        }
        $stageIdentity = self::pathIdentity($stage);
        try {
            self::writeNew($stage . '/site.duo.json', Adopt::repositorySeedBytes(), 0644);
            self::writeNew($stage . '/.gitignore', Adopt::repositoryGitignoreBytes(), 0644);
            self::writeNew($stage . '/.duo-envs.json', $overlay . "\n", 0600);
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
        if ($entries !== ['.duo-envs.json', '.git', '.gitignore', 'site.duo.json']
            || is_link($stage . '/.git') || !is_dir($stage . '/.git')) {
            throw new \RuntimeException('workspace staging contents changed before publication');
        }
        foreach ([
            'site.duo.json' => Adopt::repositorySeedBytes(),
            '.gitignore' => Adopt::repositoryGitignoreBytes(),
            '.duo-envs.json' => $overlay,
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
