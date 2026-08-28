<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * Read-only proof that one adoption-capable driver points at a safe target.
 *
 * DriverCapabilityReport remains deliberately target-free. This report is
 * the second gate: `duo adopt` obtains it after static negotiation and before
 * allocating an archive, creating a directory, or changing target bytes.
 */
final class BootstrapEligibilityReport {
    public const FORMAT = 'duo-bootstrap-eligibility/v1';

    /**
     * Discover the real standard MU leaf while shadowing it before WordPress
     * can load any existing MU plugin. A pre-adoption target has no protected
     * Duo agent yet, so this is intentionally smaller than CodeDeploy's normal
     * control bootstrap and refuses an explicitly relocated MU directory.
     */
    private const READ_ONLY_BOOTSTRAP = <<<'PHP'
$duoWpRoot = (string) (\WP_CLI::get_runner()->config['path'] ?? '');
if ($duoWpRoot === '') {
    $duoWpRoot = (string) getcwd();
}
$duoResolvedRoot = realpath($duoWpRoot);
if ($duoResolvedRoot === false) {
    throw new \RuntimeException('duo: bootstrap eligibility could not resolve the WordPress root');
}
$duoWpRoot = rtrim($duoResolvedRoot, '/');
if (defined('WPMU_PLUGIN_DIR')) {
    throw new \RuntimeException('duo: bootstrap eligibility started with WPMU_PLUGIN_DIR already defined');
}
\WP_CLI::add_hook('after_wp_config_load', static function () use ($duoWpRoot): void {
    if (defined('SUNRISE')) {
        throw new \RuntimeException('duo: bootstrap eligibility cannot isolate a configured SUNRISE loader');
    }
    $duoStandardContent = $duoWpRoot . '/wp-content';
    $duoConfiguredContent = defined('WP_CONTENT_DIR')
        ? rtrim(str_replace('\\', '/', (string) constant('WP_CONTENT_DIR')), '/')
        : $duoStandardContent;
    if ($duoConfiguredContent !== $duoStandardContent || defined('WPMU_PLUGIN_DIR')) {
        throw new \RuntimeException(
            'duo: local bootstrap eligibility requires the standard wp-content/mu-plugins control-plane root'
        );
    }
    define('DUO_BOOTSTRAP_WPMU_PLUGIN_DIR', $duoStandardContent . '/mu-plugins');
    define('WPMU_PLUGIN_DIR', $duoStandardContent . '/.duo-bootstrap-read-only-' . bin2hex(random_bytes(16)));
});
PHP;

    /**
     * @param list<array{code:string,state:string,reason:string,remediation:string}> $checks
     * @param array<string,mixed> $body
     */
    private function __construct(
        private array $body,
        private array $checks,
        private string $repoPath,
        private ?string $muDir
    ) {}

    public static function inspect(
        AdoptionTransport $transport,
        string $environment,
        string $driverId,
        string $sourceRoot
    ): self {
        $checks = [];
        $repo = $transport->repoPath();
        $wp = $transport->wpPath();

        $sourceOk = self::sourceComplete($sourceRoot);
        $checks[] = self::check(
            'source_artifact',
            $sourceOk,
            $sourceOk
                ? 'the controller source contains the complete fixed control-plane artifact'
                : 'the controller source is missing part of the fixed control-plane artifact',
            'restore this Duo checkout\'s agent, adapter packages, platform adapter library, assembler, and complete recovery runtime, then retry'
        );
        if (!$sourceOk) {
            return self::finish($environment, $driverId, '[invalid]', $checks, null);
        }

        $pathsSafe = self::safeAbsolutePath($repo)
            && self::safeAbsolutePath($wp)
            && !self::overlap($repo, $wp)
            && (!$transport instanceof LocalTransport
                || (!self::overlap($repo, $sourceRoot) && !self::overlap($wp, $sourceRoot)));
        $checks[] = self::check(
            'configured_paths',
            $pathsSafe,
            $pathsSafe
                ? 'configured WordPress and repository roots are absolute, normalized, non-root, and disjoint'
                : 'configured WordPress and repository roots are not a safe disjoint local layout',
            'set absolute normalized non-root wp_path and repo_path values that do not contain one another'
        );
        if (!$pathsSafe) {
            return self::finish($environment, $driverId, '[invalid]', $checks, null);
        }

        $reachable = self::capture(static fn(): array => $transport->captureRaw('echo duo-reachable'));
        $reachableOk = $reachable['exit'] === 0 && trim($reachable['stdout']) === 'duo-reachable';
        $checks[] = self::check(
            'transport_reachable',
            $reachableOk,
            $reachableOk
                ? 'the configured raw control path is reachable'
                : 'the configured raw control path did not return the expected bounded response',
            'repair the local shell/runtime configuration and retry; no target write was attempted'
        );
        if (!$reachableOk) {
            return self::finish($environment, $driverId, $repo, $checks, null);
        }

        $wordpress = self::capture(static fn(): array => $transport->captureWp(
            self::controlArgs(['core', 'is-installed'])
        ));
        $wordpressOk = $wordpress['exit'] === 0;
        $checks[] = self::check(
            'wordpress_installed',
            $wordpressOk,
            $wordpressOk
                ? 'WordPress is installed at the configured path'
                : 'the configured path is not a reachable installed WordPress site',
            'install WordPress or correct wp_path, then retry adoption'
        );
        if (!$wordpressOk) {
            return self::finish($environment, $driverId, $repo, $checks, null);
        }

        $mu = self::capture(static fn(): array => $transport->captureWp(
            self::controlArgs(['eval', 'echo DUO_BOOTSTRAP_WPMU_PLUGIN_DIR;'])
        ));
        $muDir = trim($mu['stdout']);
        $muOk = $mu['exit'] === 0
            && self::safeAbsolutePath($muDir)
            && !self::overlap($repo, $muDir)
            && (!$transport instanceof LocalTransport || !self::overlap($muDir, $sourceRoot));
        $checks[] = self::check(
            'control_plane_layout',
            $muOk,
            $muOk
                ? 'WordPress reported an absolute control-plane root disjoint from the repository'
                : 'WordPress did not report a safe control-plane root disjoint from the repository',
            'configure an ordinary absolute WPMU_PLUGIN_DIR outside repo_path, then retry adoption'
        );
        if (!$muOk) {
            return self::finish($environment, $driverId, $repo, $checks, null);
        }

        $topology = self::capture(static fn(): array => $transport->captureRaw(
            self::topologyScript($wp, $muDir, $repo)
        ));
        $topologyCode = $topology['exit'] === 0 ? trim($topology['stdout']) : 'topology_unreadable';
        $topologyDetails = self::topologyDetails($topologyCode);
        $topologyOk = $topologyCode === 'safe';
        $checks[] = self::check(
            'filesystem_topology',
            $topologyOk,
            $topologyOk ? 'every bootstrap destination and existing ancestor has a safe ordinary type' : $topologyDetails[0],
            $topologyDetails[1]
        );

        if ($topologyOk && $transport instanceof LocalTransport) {
            $authorityOk = self::localControlPlaneAbsent($muDir, $repo);
            $checks[] = self::check(
                'control_authority',
                $authorityOk,
                $authorityOk
                    ? 'the local target has no pre-existing Duo control plane or recovery authority'
                    : 'local bootstrap is initial-only and found a pre-existing Duo control-plane or authority path',
                'use the existing environment update path for an installed Duo target, or move the prior control plane aside only after operator review'
            );
            $topologyOk = $authorityOk;
        }

        return self::finish($environment, $driverId, $repo, $checks, $topologyOk ? $muDir : null);
    }

    public function ready(): bool {
        return ($this->body['ready'] ?? false) === true;
    }

    /** @return array<string,mixed> */
    public function toArray(): array {
        return $this->body;
    }

    /** @return list<array{code:string,state:string,reason:string,remediation:string}> */
    public function checks(): array {
        return $this->checks;
    }

    /** @return list<array{code:string,state:string,reason:string,remediation:string}> */
    public function blockers(): array {
        return array_values(array_filter(
            $this->checks,
            static fn(array $check): bool => $check['state'] !== 'passed'
        ));
    }

    public function muDir(): string {
        if (!$this->ready() || !is_string($this->muDir) || $this->muDir === '') {
            throw new \RuntimeException('bootstrap eligibility is not ready');
        }
        return $this->muDir;
    }

    public function assertMatches(AdoptionTransport $transport): void {
        if (!$this->ready() || $transport->repoPath() !== $this->repoPath) {
            throw new \RuntimeException('bootstrap eligibility does not authorize this adoption target');
        }
    }

    /** @return array{code:string,state:string,reason:string,remediation:string} */
    private static function check(string $code, bool $ok, string $reason, string $remediation): array {
        return [
            'code' => $code,
            'state' => $ok ? 'passed' : 'blocked',
            'reason' => $reason,
            'remediation' => $ok ? '' : $remediation,
        ];
    }

    /**
     * @param list<array{code:string,state:string,reason:string,remediation:string}> $checks
     */
    private static function finish(
        string $environment,
        string $driverId,
        string $repo,
        array $checks,
        ?string $muDir
    ): self {
        $ready = $checks !== [];
        foreach ($checks as $check) {
            $ready = $ready && $check['state'] === 'passed';
        }
        $body = [
            'format' => self::FORMAT,
            'driver' => ['environment' => self::label($environment), 'id' => self::label($driverId)],
            'repo_path' => $repo,
            'ready' => $ready,
            'checks' => $checks,
        ];
        $body['digest'] = 'sha256:' . hash('sha256', self::canonicalJson($body));
        return new self($body, $checks, $repo, $muDir);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private static function capture(callable $call): array {
        try {
            $result = $call();
            if (!is_array($result)
                || !is_int($result['exit'] ?? null)
                || !is_string($result['stdout'] ?? null)
                || !is_string($result['stderr'] ?? null)) {
                return ['exit' => 255, 'stdout' => '', 'stderr' => ''];
            }
            return $result;
        } catch (\Throwable) {
            return ['exit' => 255, 'stdout' => '', 'stderr' => ''];
        }
    }

    private static function topologyScript(string $wp, string $mu, string $repo): string {
        $q = static fn(string $value): string => escapeshellarg($value);
        $dirTargets = [
            $mu . '/duo',
            $mu . '/manifests',
            $mu . '/duo-control',
            $repo . '/.duo',
            $repo . '/.duo/control',
            $repo . '/.duo/control/recovery-runtime',
            $repo . '/.duo/rollback',
        ];
        $fileTargets = [
            $mu . '/duo-loader.php',
            $mu . '/duo-control/adapter-revocations.json',
            $repo . '/site.duo.json',
        ];

        $script = 'set -u' . "\n"
            . 'wp=' . $q($wp) . "\n"
            . 'mu=' . $q($mu) . "\n"
            . 'repo=' . $q($repo) . "\n"
            . "for command in php tar cp mv rm mkdir rmdir chmod find dirname ln; do command -v \"\$command\" >/dev/null 2>&1 || { echo missing_tool; exit 0; }; done\n"
            . self::temporaryPathCheckScript('/tmp')
            . "check_ancestors() ( p=\"\$1\"; while [ \"\$p\" != / ]; do [ ! -L \"\$p\" ] || { echo ancestor_symlink; exit 1; }; [ ! -e \"\$p\" ] || [ -d \"\$p\" ] || { echo ancestor_type; exit 1; }; p=\$(dirname \"\$p\"); done; )\n"
            . "check_parent_write() ( p=\$(dirname \"\$1\"); while [ ! -e \"\$p\" ]; do p=\$(dirname \"\$p\"); done; [ -d \"\$p\" ] && [ ! -L \"\$p\" ] && [ -w \"\$p\" ] && [ -x \"\$p\" ] || { echo destination_unwritable; exit 1; }; )\n"
            . "check_writable_root() ( p=\"\$1\"; check_ancestors \"\$p\" || exit 1; if [ -e \"\$p\" ]; then [ -d \"\$p\" ] && [ -w \"\$p\" ] && [ -x \"\$p\" ] || { echo destination_unwritable; exit 1; }; else parent=\$(dirname \"\$p\"); [ -d \"\$parent\" ] && [ ! -L \"\$parent\" ] && [ -w \"\$parent\" ] && [ -x \"\$parent\" ] || { echo destination_unwritable; exit 1; }; fi; )\n"
            . "[ -d \"\$wp\" ] && [ ! -L \"\$wp\" ] && [ -r \"\$wp\" ] && [ -x \"\$wp\" ] || { echo wordpress_path_unsafe; exit 0; }; check_ancestors \"\$wp\" || exit 0\n"
            . "check_writable_root \"\$mu\" || exit 0; check_writable_root \"\$repo\" || exit 0\n";
        foreach ($dirTargets as $path) {
            $quoted = $q($path);
            $script .= "[ ! -L $quoted ] || { echo destination_symlink; exit 0; }; "
                . "[ ! -e $quoted ] || [ -d $quoted ] || { echo destination_type; exit 0; }; "
                . "check_ancestors $quoted || exit 0; check_parent_write $quoted || exit 0\n";
        }
        foreach ($fileTargets as $path) {
            $quoted = $q($path);
            $script .= "[ ! -L $quoted ] || { echo destination_symlink; exit 0; }; "
                . "[ ! -e $quoted ] || [ -f $quoted ] || { echo destination_type; exit 0; }; "
                . 'check_ancestors ' . $q(dirname($path)) . " || exit 0; check_parent_write $quoted || exit 0\n";
        }
        $script .= "[ ! -e \"\$mu/.duo-adopt-lock\" ] || { echo adoption_in_progress; exit 0; }\n"
            . "if [ -e \"\$mu\" ]; then stale=\$(find \"\$mu\" -maxdepth 1 \( -name '.duo-adopt-txn-*' -o -name '.duo-new-*' -o -name '.duo-old-*' -o -name '.duo-loader-new-*' -o -name '.duo-loader-old-*' -o -name '.duo-manifests-old-*' \) -print -quit 2>/dev/null) || { echo topology_unreadable; exit 0; }; [ -z \"\$stale\" ] || { echo stale_transaction; exit 0; }; fi\n"
            . "if [ -e \"\$repo\" ]; then stale=\$(find \"\$repo\" -maxdepth 1 \( -name '.duo-new-*' -o -name '.duo-old-*' -o -name '.site.duo.new-*' \) -print -quit 2>/dev/null) || { echo topology_unreadable; exit 0; }; [ -z \"\$stale\" ] || { echo stale_transaction; exit 0; }; fi\n"
            . "for tree in \"\$mu/duo\" \"\$mu/manifests\" \"\$mu/duo-control\" \"\$repo/.duo\"; do if [ -e \"\$tree\" ]; then [ -r \"\$tree\" ] || { echo source_unreadable; exit 0; }; special=\$(find \"\$tree\" ! -type d ! -type f -print -quit 2>/dev/null) || { echo source_unreadable; exit 0; }; [ -z \"\$special\" ] || { echo control_special; exit 0; }; unreadable=\$(find \"\$tree\" -type f ! -exec test -r '{}' \; -print -quit 2>/dev/null) || { echo source_unreadable; exit 0; }; [ -z \"\$unreadable\" ] || { echo source_unreadable; exit 0; }; fi; done\n"
            . "for file in \"\$mu/duo-loader.php\" \"\$repo/site.duo.json\"; do [ ! -e \"\$file\" ] || [ -r \"\$file\" ] || { echo source_unreadable; exit 0; }; done\n"
            . "legacy_revocations=\"\$mu/manifests/capabilities/adapter-revocations.json\"; durable_revocations=\"\$mu/duo-control/adapter-revocations.json\"; if [ -e \"\$legacy_revocations\" ] || [ -L \"\$legacy_revocations\" ]; then [ -f \"\$legacy_revocations\" ] && [ ! -L \"\$legacy_revocations\" ] && [ -r \"\$legacy_revocations\" ] && [ -f \"\$durable_revocations\" ] && [ ! -L \"\$durable_revocations\" ] && [ -r \"\$durable_revocations\" ] && php -r '\$a = @file_get_contents(\$argv[1]); \$b = @file_get_contents(\$argv[2]); exit(is_string(\$a) && is_string(\$b) && hash_equals(\$a, \$b) ? 0 : 1);' \"\$legacy_revocations\" \"\$durable_revocations\" || { echo legacy_revocation_unmigrated; exit 0; }; fi\n"
            . "echo safe\n";
        return $script;
    }

    /**
     * The transaction uses /tmp for an exclusive, identity-bound archive and
     * stage. macOS exposes that system directory through the OS-provided
     * /tmp -> /private/tmp alias. Resolve only this fixed staging path; every
     * transaction child remains exclusive and identity-bound, while links in
     * every bootstrap destination itself are still refused.
     */
    private static function temporaryPathCheckScript(string $path): string {
        $quoted = escapeshellarg($path);
        return 'temporary_path=' . $quoted . "\n"
            . "temporary_physical=\$(cd -P \"\$temporary_path\" 2>/dev/null && pwd -P) || { echo temporary_path_unsafe; exit 0; }\n"
            . "[ \"\$temporary_physical\" != / ] && [ -d \"\$temporary_physical\" ] && [ ! -L \"\$temporary_physical\" ] && [ -w \"\$temporary_physical\" ] && [ -x \"\$temporary_physical\" ] || { echo temporary_path_unsafe; exit 0; }\n";
    }

    /** @param list<string> $command @return list<string> */
    private static function controlArgs(array $command): array {
        $bootstrap = trim(str_replace(["\r", "\n"], ' ', self::READ_ONLY_BOOTSTRAP));
        return array_merge([
            '--exec=' . $bootstrap,
            '--skip-plugins',
            '--skip-themes',
        ], $command);
    }

    /** @return array{string,string} */
    private static function topologyDetails(string $code): array {
        return match ($code) {
            'safe' => ['every destination is safe', ''],
            'missing_tool' => [
                'the target lacks a command required by the atomic bootstrap transaction',
                'install php, tar, cp, mv, rm, mkdir, rmdir, chmod, find, dirname, and ln, then retry',
            ],
            'temporary_path_unsafe' => [
                'the target temporary path does not resolve to an ordinary writable directory',
                'repair the local /tmp target type and permissions, then retry',
            ],
            'destination_symlink', 'ancestor_symlink', 'control_special' => [
                'a bootstrap destination or its authority tree crosses a symbolic link',
                'replace the linked bootstrap path with an ordinary directory/file layout, then retry',
            ],
            'destination_type', 'ancestor_type' => [
                'a bootstrap destination or existing ancestor has an incompatible filesystem type',
                'move the conflicting path aside and create ordinary directory/file destinations, then retry',
            ],
            'destination_unwritable' => [
                'the local WordPress user cannot atomically create or replace a bootstrap destination',
                'grant the local WordPress user write and traversal access to the destination parent, then retry',
            ],
            'wordpress_path_unsafe' => [
                'the configured WordPress root is not an ordinary readable directory',
                'correct wp_path and its ownership before retrying adoption',
            ],
            'source_unreadable' => [
                'the prior Duo authority tree cannot be safely read for staging',
                'repair ownership and read access for repo_path/.duo, then retry',
            ],
            'legacy_revocation_unmigrated' => [
                'the legacy flat library carries adapter revocations without a byte-identical durable control copy',
                'copy manifests/capabilities/adapter-revocations.json byte-for-byte to duo-control/adapter-revocations.json, then retry',
            ],
            'adoption_in_progress' => [
                'an adoption lock already exists',
                'inspect the existing .duo-adopt-lock and recover or finish that adoption before retrying',
            ],
            'stale_transaction' => [
                'stale adoption transaction paths require operator review',
                'inspect and recover the stale .duo-adopt-* paths before retrying; do not delete an active transaction',
            ],
            default => [
                'the target filesystem eligibility probe did not return a recognized result',
                'repair local shell access and filesystem permissions, then retry; no bootstrap write was attempted',
            ],
        };
    }

    private static function safeAbsolutePath(string $path): bool {
        return $path !== ''
            && $path !== '/'
            && $path[0] === '/'
            && preg_match('/[\x00-\x1f\x7f]/', $path) !== 1
            && !str_contains($path, '//')
            && preg_match('#/(?:\.|\.\.)(?:/|$)#', $path) !== 1
            && !str_ends_with($path, '/');
    }

    private static function sourceComplete(string $sourceRoot): bool {
        $root = rtrim($sourceRoot, '/');
        $files = [
            '/agent/duo.php',
            '/agent/duo-loader.php',
            '/platform/adapter-library/core/manifest.json',
            '/platform/adapter-library/capabilities/platform.json',
            '/tools/src/AdapterPackageProjection.php',
            '/tools/src/AdapterLibraryAssembler.php',
            '/recovery/CanonicalJson.php',
            '/recovery/AtomicStore.php',
            '/recovery/ProtocolLock.php',
            '/recovery/ProviderClient.php',
            '/recovery/rollback-control.php',
            '/recovery/RecoveryExecutor.php',
            '/recovery/CheckpointBundle.php',
            '/recovery/CodeRelease.php',
            '/recovery/UploadBundle.php',
            '/recovery/EffectBundle.php',
        ];
        if (!self::safeAbsolutePath($root)) {
            return false;
        }
        foreach ($files as $file) {
            $path = $root . $file;
            $stat = @lstat($path);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || !is_readable($path)) {
                return false;
            }
        }
        require_once $root . '/tools/src/AdapterPackageProjection.php';
        try {
            \Duo\Tooling\AdapterPackageProjection::plan($root);
        } catch (\Throwable) {
            return false;
        }
        return self::ordinaryReadableTree($root . '/agent')
            && self::ordinaryReadableTree($root . '/adapter-packages')
            && self::ordinaryReadableTree($root . '/platform/adapter-library')
            && self::ordinaryReadableTree($root . '/recovery');
    }

    private static function ordinaryReadableTree(string $root): bool {
        $pending = [$root];
        while ($pending !== []) {
            $path = array_pop($pending);
            $stat = @lstat($path);
            if (!is_array($stat)) {
                return false;
            }
            $type = $stat['mode'] & 0170000;
            if ($type === 0100000) {
                if (!is_readable($path)) {
                    return false;
                }
                continue;
            }
            if ($type !== 0040000 || !is_readable($path)) {
                return false;
            }
            $children = @scandir($path);
            if (!is_array($children)) {
                return false;
            }
            foreach ($children as $child) {
                if ($child !== '.' && $child !== '..') {
                    $pending[] = $path . '/' . $child;
                }
            }
        }
        return true;
    }

    private static function localControlPlaneAbsent(string $mu, string $repo): bool {
        foreach ([$mu . '/duo', $mu . '/duo-loader.php', $mu . '/manifests', $repo . '/.duo'] as $path) {
            if (file_exists($path) || is_link($path)) {
                return false;
            }
        }
        return true;
    }

    private static function overlap(string $left, string $right): bool {
        return $left === $right
            || str_starts_with($left . '/', $right . '/')
            || str_starts_with($right . '/', $left . '/');
    }

    private static function label(string $value): string {
        if ($value !== '' && strlen($value) <= 128 && preg_match('/^[A-Za-z0-9._-]+$/D', $value) === 1) {
            return $value;
        }
        return 'invalid';
    }

    /** @param array<string,mixed> $value */
    private static function canonicalJson(array $value): string {
        $json = json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('could not encode bootstrap eligibility report');
        }
        return $json;
    }

    private static function canonicalize(mixed $value): mixed {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalize($child);
        }
        return $value;
    }
}
