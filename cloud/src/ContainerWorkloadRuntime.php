<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once __DIR__ . '/CanonicalJson.php';
require_once __DIR__ . '/ControlRefusal.php';
require_once __DIR__ . '/ImmutableOciReference.php';
require_once __DIR__ . '/WorkloadSecurityInspection.php';
require_once __DIR__ . '/WorkloadRuntime.php';
require_once dirname(__DIR__) . '/runtime/RuntimeSlotLock.php';

/**
 * A shell-free process boundary for the container engine, route authority, and Git.
 *
 * stdinFile is opened by the runner and connected directly to the child. It is
 * never translated to shell redirection, which lets large repository/snapshot
 * artifacts cross the boundary without entering argv or PHP memory.
 */
interface ContainerArgvProcessRunner {
    /**
     * @param non-empty-list<string> $argv
     * @return array{exit:int,stderr:string,stdout:string}
     */
    public function run(
        array $argv,
        ?string $stdinFile = null,
        ?int $timeoutSeconds = null
    ): array;
}

/** Production runner: argv reaches proc_open as an array, never a shell string. */
final class NativeContainerArgvProcessRunner implements ContainerArgvProcessRunner {
    private const OUTPUT_LIMIT = 1048576;
    private const GROUP_EXEC = <<<'PHP'
array_shift($argv);
$mode = array_shift($argv);
$cgroup = array_shift($argv);
$command = array_shift($argv);
if (!is_string($command) || $command === '' || !function_exists('pcntl_exec')
    || !is_string($cgroup) || $cgroup === ''
    || ($mode === 'session' && (!function_exists('posix_setsid') || posix_setsid() < 0))
    || !in_array($mode, ['inherit', 'session'], true)) {
    exit(125);
}
if ($cgroup !== '-') {
    $membership = @fopen($cgroup . '/cgroup.procs', 'wb');
    $pid = (string) getmypid() . "\n";
    if (!is_resource($membership) || fwrite($membership, $pid) !== strlen($pid)
        || !fflush($membership) || !fclose($membership)) {
        if (is_resource($membership)) {
            fclose($membership);
        }
        exit(124);
    }
}
pcntl_exec($command, $argv);
exit(125);
PHP;

    private string $phpCli;

    public function __construct(
        private int $timeoutSeconds,
        ?string $phpCli = null,
        private bool $newSession = true,
        private bool $requireCgroup = false
    ) {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 300) {
            throw new ControlRefusal('direct argv process timeout must be from 1 through 300 seconds');
        }
        if (!function_exists('posix_getpgid') || !function_exists('posix_kill')
            || !function_exists('pcntl_exec') || !function_exists('posix_setsid')) {
            throw new ControlRefusal('direct argv process runner requires POSIX process-group support');
        }
        if ($requireCgroup && !$newSession) {
            throw new ControlRefusal('nested direct argv runners must inherit the outer cgroup boundary');
        }
        $this->phpCli = $phpCli ?? PHP_BINARY;
        if ($this->phpCli === '' || $this->phpCli[0] !== '/'
            || str_contains($this->phpCli, "\0") || !is_file($this->phpCli)
            || !is_executable($this->phpCli)) {
            throw new ControlRefusal('direct argv process runner requires an absolute PHP CLI executable');
        }
    }

    public function run(
        array $argv,
        ?string $stdinFile = null,
        ?int $timeoutSeconds = null
    ): array {
        self::argv($argv);
        $timeoutSeconds ??= $this->timeoutSeconds;
        if ($timeoutSeconds < 1 || $timeoutSeconds > $this->timeoutSeconds) {
            throw new ControlRefusal('direct argv process override exceeds its configured timeout');
        }
        $argv = $this->pinnedPhpArgv($argv);
        if ($stdinFile !== null) {
            self::readableRegularFile($stdinFile);
        }
        $descriptors = [
            0 => $stdinFile === null
                ? ['file', '/dev/null', 'rb']
                : ['file', $stdinFile, 'rb'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cgroup = $this->createCgroup();
        $pipes = [];
        $process = proc_open(
            array_merge([
                $this->phpCli, '-r', self::GROUP_EXEC, '--',
                $this->newSession ? 'session' : 'inherit',
                $cgroup ?? '-',
            ], $argv),
            $descriptors,
            $pipes,
            null,
            ['GIT_TERMINAL_PROMPT' => '0', 'LANG' => 'C', 'LC_ALL' => 'C'],
            ['bypass_shell' => true]
        );
        if (!is_resource($process) || !isset($pipes[1], $pipes[2])
            || !is_resource($pipes[1]) || !is_resource($pipes[2])) {
            if (is_resource($process)) {
                $status = proc_get_status($process);
                $pid = is_array($status) && is_int($status['pid'] ?? null)
                    ? $status['pid']
                    : 0;
                if ($pid > 0) {
                    $this->stopBoundary($pid, $process, $pipes, $cgroup);
                } else {
                    $cgroupFailure = null;
                    try {
                        $this->killCgroupUntilEmpty($cgroup);
                    } catch (\Throwable $error) {
                        $cgroupFailure = $error;
                    }
                    proc_terminate($process, 9);
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($process);
                    if ($cgroupFailure !== null) {
                        throw new ControlRefusal(
                            'direct argv partial process cgroup could not be reaped',
                            0,
                            $cgroupFailure
                        );
                    }
                }
            } else {
                $this->removeEmptyCgroup($cgroup);
            }
            throw new ControlRefusal('direct argv process could not be started');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $initial = proc_get_status($process);
        $pid = is_array($initial) && is_int($initial['pid'] ?? null) ? $initial['pid'] : 0;
        if ($pid < 1) {
            $cgroupFailure = null;
            try {
                $this->killCgroupUntilEmpty($cgroup);
            } catch (\Throwable $error) {
                $cgroupFailure = $error;
            }
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            if ($cgroupFailure !== null) {
                throw new ControlRefusal(
                    'direct argv process unidentified cgroup could not be reaped',
                    0,
                    $cgroupFailure
                );
            }
            throw new ControlRefusal('direct argv process group could not be identified');
        }
        $deadline = self::monotonicNow() + $timeoutSeconds;
        $groupDeadline = min($deadline, self::monotonicNow() + 1.0);
        $status = $initial;
        while ($this->newSession && ($status['running'] ?? false) === true
            && @posix_getpgid($pid) !== $pid
            && self::monotonicNow() < $groupDeadline) {
            usleep(1000);
            $status = proc_get_status($process);
        }
        if ($this->newSession && ($status['running'] ?? false) === true
            && @posix_getpgid($pid) !== $pid) {
            $this->stopBoundary($pid, $process, $pipes, $cgroup);
            throw new ControlRefusal('direct argv process group isolation could not be established');
        }
        $stdout = '';
        $stderr = '';
        $failure = null;
        while (($status['running'] ?? false) === true) {
            $stdoutState = self::drain($pipes[1], $stdout);
            $stderrState = self::drain($pipes[2], $stderr);
            if ($stdoutState === 'error' || $stderrState === 'error') {
                $failure = 'output';
                break;
            }
            if ($stdoutState === 'overflow' || $stderrState === 'overflow') {
                $failure = 'limit';
                break;
            }
            if (self::monotonicNow() >= $deadline) {
                $failure = 'timeout';
                break;
            }
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 10000) === false) {
                $failure = 'output';
                break;
            }
            $status = proc_get_status($process);
        }
        if ($failure === null) {
            foreach ([[$pipes[1], &$stdout], [$pipes[2], &$stderr]] as &$target) {
                do {
                    $state = self::drain($target[0], $target[1]);
                    if ($state === 'error') {
                        $failure = 'output';
                    } elseif ($state === 'overflow') {
                        $failure = 'limit';
                    }
                } while ($state === 'read');
            }
            unset($target);
        }
        if ($failure !== null) {
            $this->stopBoundary($pid, $process, $pipes, $cgroup);
            throw new ControlRefusal(match ($failure) {
                'limit' => 'direct argv process output exceeded its byte limit',
                'timeout' => 'direct argv process exceeded its wall timeout',
                default => 'direct argv process output could not be read',
            });
        }
        $observedExit = $status['exitcode'] ?? -1;
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExit = proc_close($process);
        $leftDescendants = $cgroup !== null
            ? $this->cgroupPopulated($cgroup)
            : $this->boundaryAlive($pid);
        if ($leftDescendants) {
            if ($cgroup !== null) {
                $this->killCgroupUntilEmpty($cgroup);
            } else {
                $this->killBoundaryUntilAbsent($pid);
            }
        } else {
            $this->removeEmptyCgroup($cgroup);
        }
        $exit = is_int($observedExit) && $observedExit >= 0 ? $observedExit : $closedExit;
        if ($leftDescendants) {
            throw new ControlRefusal('direct argv process left a descendant after completion');
        }
        return ['exit' => $exit, 'stderr' => $stderr, 'stdout' => $stdout];
    }

    /**
     * @param resource $pipe
     * @return 'empty'|'error'|'overflow'|'read'
     */
    private static function drain($pipe, string &$output): string {
        $read = false;
        do {
            $remaining = self::OUTPUT_LIMIT + 1 - strlen($output);
            $bytes = @fread($pipe, min(65536, max(1, $remaining)));
            if (!is_string($bytes)) {
                return 'error';
            }
            if ($bytes === '') {
                break;
            }
            $read = true;
            $output .= $bytes;
            if (strlen($output) > self::OUTPUT_LIMIT) {
                return 'overflow';
            }
        } while (strlen($bytes) === min(65536, max(1, $remaining)));
        return $read ? 'read' : 'empty';
    }

    /** @param resource $process @param array<int,resource> $pipes */
    private function stopBoundary(int $pid, $process, array $pipes, ?string $cgroup): void {
        $cgroupFailure = null;
        if ($cgroup !== null) {
            try {
                $this->killCgroupUntilEmpty($cgroup);
            } catch (\Throwable $error) {
                $cgroupFailure = $error;
            }
        }
        $this->signalBoundary($pid, 15);
        $deadline = self::monotonicNow() + 1.0;
        do {
            foreach ([1, 2] as $index) {
                while (is_resource($pipes[$index] ?? null)
                    && is_string($bytes = @fread($pipes[$index], 65536))
                    && $bytes !== '') {
                }
            }
            $status = proc_get_status($process);
            if (($status['running'] ?? false) !== true && !$this->boundaryAlive($pid)) {
                break;
            }
            usleep(10000);
        } while (self::monotonicNow() < $deadline);
        $this->signalBoundary($pid, 9);
        proc_terminate($process, 9);
        foreach ([1, 2] as $index) {
            if (is_resource($pipes[$index] ?? null)) {
                fclose($pipes[$index]);
            }
        }
        proc_close($process);
        if ($cgroup === null) {
            $this->killBoundaryUntilAbsent($pid);
        } elseif (file_exists($cgroup)) {
            try {
                $this->killCgroupUntilEmpty($cgroup);
            } catch (\Throwable $error) {
                $cgroupFailure = $error;
            }
        }
        if ($cgroupFailure !== null) {
            throw new ControlRefusal(
                'direct argv process cgroup boundary could not be reaped',
                0,
                $cgroupFailure
            );
        }
    }

    private function killBoundaryUntilAbsent(int $pid): void {
        $deadline = self::monotonicNow() + 1.0;
        do {
            $this->signalBoundary($pid, 9);
            if (!$this->boundaryAlive($pid)) {
                return;
            }
            usleep(10000);
        } while (self::monotonicNow() < $deadline);
        throw new ControlRefusal('direct argv process boundary could not be reaped');
    }

    private function createCgroup(): ?string {
        if (!$this->newSession) {
            return null;
        }
        $membership = @file_get_contents('/proc/self/cgroup');
        if (PHP_OS_FAMILY !== 'Linux' || !is_string($membership)
            || preg_match('/^0::([^\r\n]*)$/m', $membership, $match) !== 1) {
            if ($this->requireCgroup) {
                throw new ControlRefusal('direct argv process requires a delegated cgroup v2 boundary');
            }
            return null;
        }
        $relative = $match[1] === '' ? '/' : $match[1];
        if ($relative[0] !== '/' || str_contains($relative, "\0")
            || preg_match('#(?:^|/)\.\.?(/|$)#D', $relative) === 1) {
            throw new ControlRefusal('direct argv process cgroup membership is invalid');
        }
        $parent = '/sys/fs/cgroup' . ($relative === '/' ? '' : $relative);
        $name = 'duo-tx-' . getmypid() . '-' . bin2hex(random_bytes(12));
        $path = $parent . '/' . $name;
        if (!is_dir($parent) || is_link($parent)
            || !is_file($parent . '/cgroup.controllers')
            || !@mkdir($path, 0700)) {
            if ($this->requireCgroup) {
                throw new ControlRefusal('direct argv process requires a writable delegated cgroup v2 boundary');
            }
            return null;
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        foreach (['cgroup.events', 'cgroup.kill', 'cgroup.procs'] as $file) {
            if (!is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0040000
                || !is_file($path . '/' . $file) || is_link($path . '/' . $file)) {
                @rmdir($path);
                throw new ControlRefusal('direct argv process cgroup v2 boundary is incomplete');
            }
        }
        return $path;
    }

    private function cgroupPopulated(string $path): bool {
        $events = @file_get_contents($path . '/cgroup.events');
        if (!is_string($events)
            || preg_match('/^populated ([01])$/m', $events, $match) !== 1) {
            throw new ControlRefusal('direct argv process cgroup population readback failed');
        }
        return $match[1] === '1';
    }

    private function writeCgroupKill(string $path): void {
        $handle = @fopen($path . '/cgroup.kill', 'wb');
        if (!is_resource($handle) || fwrite($handle, "1\n") !== 2
            || !fflush($handle) || !fclose($handle)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new ControlRefusal('direct argv process cgroup kill could not be published');
        }
    }

    private function killCgroupUntilEmpty(?string $path): void {
        if ($path === null) {
            return;
        }
        $deadline = self::monotonicNow() + 1.0;
        $killFailure = null;
        do {
            if (!$this->cgroupPopulated($path)) {
                $this->removeEmptyCgroup($path);
                return;
            }
            try {
                $this->writeCgroupKill($path);
            } catch (\Throwable $error) {
                $killFailure = $error;
                $this->signalCgroupMembers($path, 9);
            }
            usleep(10000);
        } while (self::monotonicNow() < $deadline);
        throw new ControlRefusal(
            'direct argv process cgroup could not be reaped',
            0,
            $killFailure
        );
    }

    private function signalCgroupMembers(string $path, int $signal): void {
        $members = @file_get_contents($path . '/cgroup.procs');
        if (!is_string($members)
            || preg_match('/\A(?:[1-9][0-9]*\n)*\z/D', $members) !== 1) {
            throw new ControlRefusal('direct argv process cgroup membership readback failed');
        }
        foreach (array_filter(explode("\n", $members), 'strlen') as $member) {
            @posix_kill((int) $member, $signal);
        }
    }

    private function removeEmptyCgroup(?string $path): void {
        if ($path === null || !file_exists($path)) {
            return;
        }
        if ($this->cgroupPopulated($path) || !@rmdir($path)) {
            throw new ControlRefusal('direct argv process empty cgroup could not be removed');
        }
    }

    private static function monotonicNow(): float {
        return hrtime(true) / 1_000_000_000;
    }

    private function signalBoundary(int $pid, int $signal): void {
        if (!$this->newSession) {
            $group = @posix_getpgid($pid);
            if (is_int($group) && $group > 1) {
                @posix_kill(-$group, $signal);
            } else {
                @posix_kill($pid, $signal);
            }
            return;
        }
        @posix_kill(-$pid, $signal);
        foreach (self::sessionPids($pid) as $member) {
            @posix_kill($member, $signal);
        }
    }

    private function boundaryAlive(int $pid): bool {
        if (!$this->newSession) {
            return @posix_kill($pid, 0);
        }
        if (@posix_kill(-$pid, 0)) {
            return true;
        }
        return self::sessionPids($pid) !== [];
    }

    /** @return list<int> */
    private static function sessionPids(int $session): array {
        if (DIRECTORY_SEPARATOR !== '/' || !is_dir('/proc')) {
            return [];
        }
        $members = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $path) {
            $bytes = @file_get_contents($path);
            $end = is_string($bytes) ? strrpos($bytes, ') ') : false;
            if (!is_int($end)) {
                continue;
            }
            $fields = preg_split('/\s+/', substr($bytes, $end + 2));
            $member = (int) basename(dirname($path));
            if (is_array($fields) && isset($fields[3]) && (int) $fields[3] === $session
                && $member > 1 && $member !== getmypid()) {
                $members[] = $member;
            }
        }
        sort($members, SORT_NUMERIC);
        return $members;
    }

    /** @param non-empty-list<string> $argv @return non-empty-list<string> */
    private function pinnedPhpArgv(array $argv): array {
        $path = $argv[0];
        $before = @lstat($path);
        if (!is_array($before) || is_link($path)
            || ($before['mode'] & 0170000) !== 0100000) {
            return $argv;
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return $argv;
        }
        try {
            $opened = fstat($handle);
            $first = fgets($handle, 65);
            clearstatcache(true, $path);
            $after = @lstat($path);
        } finally {
            fclose($handle);
        }
        if (!is_array($opened) || !is_array($after) || !is_string($first)
            || !self::sameExecutable($before, $opened)
            || !self::sameExecutable($before, $after)) {
            throw new ControlRefusal('direct argv executable changed while its interpreter was selected');
        }
        if ($first !== "#!/usr/bin/env php\n") {
            return $argv;
        }
        array_shift($argv);
        return array_merge([$this->phpCli, $path], $argv);
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function sameExecutable(array $left, array $right): bool {
        return (int) $left['dev'] === (int) $right['dev']
            && (int) $left['ino'] === (int) $right['ino']
            && (int) $left['mode'] === (int) $right['mode']
            && (int) $left['uid'] === (int) $right['uid']
            && (int) $left['size'] === (int) $right['size'];
    }

    /** @param array<mixed> $argv */
    private static function argv(array $argv): void {
        if ($argv === [] || !array_is_list($argv)) {
            throw new ControlRefusal('direct argv process requires a non-empty argument list');
        }
        foreach ($argv as $argument) {
            if (!is_string($argument) || $argument === '' || str_contains($argument, "\0")) {
                throw new ControlRefusal('direct argv process contains an invalid argument');
            }
        }
        if ($argv[0][0] !== '/') {
            throw new ControlRefusal('direct argv executable must be an absolute path');
        }
    }

    private static function readableRegularFile(string $path): void {
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new ControlRefusal('direct argv stdin source is not an approved regular file');
        }
    }
}

/**
 * Docker-compatible, generation-isolated implementation of WorkloadRuntime.
 *
 * The image is a trusted Duo runtime image addressed by OCI digest. Its fixed
 * helpers are part of the image/config attestation: they initialise a clean
 * WordPress base, restore content idempotently, reject unsafe archive entries,
 * and never emit credentials. A separate route authority is required because
 * the workload has one internal, non-masqueraded bridge and no egress-capable
 * network. The route authority binds an HTTPS host to that internal upstream;
 * no credential appears in the URL, labels, argv, state, or evidence.
 */
final class ContainerWorkloadRuntime implements WorkloadRuntime, ReviewedPreviewBaseProvider, RepositorySyncRuntime {
    private const MANIFEST_FORMAT = 'duo-cloud-container-workload-state/v1';
    private const REVIEWED_BASE_FORMAT = 'duo-reviewed-preview-base/v1';
    private const REVIEWED_CONTAINMENT_FORMAT = 'duo-reviewed-preview-base-containment/v1';
    private const REPOSITORY_AUTHORITY_FORMAT = 'duo-cloud-repository-authority/v1';
    private const ROUTE_FORMAT = 'duo-cloud-preview-route-state/v1';
    private const RUNTIME_STATUS_FORMAT = 'duo-cloud-preview-runtime-status/v1';
    private const STAGE_FORMAT = 'duo-cloud-preview-runtime-stage/v1';
    private const DATABASE_TARGET = '/var/lib/duo/database';
    private const FILESYSTEM_TARGET = '/var/lib/duo/wordpress';
    private const SECRET_TARGET = '/run/secrets/duo';
    private const WORKLOAD_PORT = 8080;
    private const WORKLOAD_UID = 10001;
    private const WORKLOAD_GID = 10001;
    private const STOP_SECONDS = 10;
    private const SHM_BYTES = 67108864;
    private const NOFILE_LIMIT = 4096;
    // A fixed unrouted resolver forces every lookup to originate in the
    // workload namespace; affected engines otherwise proxied internal-network
    // DNS from the trusted host namespace (CVE-2024-29018).
    private const DNS_SERVER = '192.0.2.53';
    private const MASKED_PATHS = [
        '/proc/acpi',
        '/proc/asound',
        '/proc/interrupts',
        '/proc/kcore',
        '/proc/keys',
        '/proc/latency_stats',
        '/proc/sched_debug',
        '/proc/scsi',
        '/proc/timer_list',
        '/proc/timer_stats',
        '/sys/devices/virtual/powercap',
        '/sys/firmware',
    ];
    private const READONLY_PATHS = [
        '/proc/bus',
        '/proc/fs',
        '/proc/irq',
        '/proc/sys',
        '/proc/sysrq-trigger',
    ];

    private ContainerArgvProcessRunner $runner;
    private string $engineBinary;
    private string $routerBinary;
    private string $firewallBinary;
    private string $storageBinary;
    private string $gitBinary;
    private string $image;
    private string $imageDigest;
    private string $seccompProfile;
    private string $seccompProfileSha256;
    private string $configurationSha256;
    private string $platformFingerprintSha256;
    private string $reviewReceiptSha256;
    private string $reviewedBaseSha256;
    private string $stateRoot;
    private string $repositorySource;
    private string $repositoryRemoteName;
    private string $repositoryRemoteUrl;
    private string $repositoryRemoteUrlSha256;
    private string $repositoryRefPrefix;
    private string $repositoryCredentialHelper;
    private string $repositoryCredentialHelperSha256;
    private string $snapshotObjectRoot;
    private string $previewDomain;
    private string $workloadRepositoryPath;
    private int $memoryBytes;
    private int $nanoCpus;
    private int $pidsLimit;
    /** @var \Closure(int):string */
    private \Closure $randomBytes;

    public function __construct(
        ContainerArgvProcessRunner $runner,
        string $engineBinary,
        string $routerBinary,
        string $firewallBinary,
        string $storageBinary,
        string $gitBinary,
        string $image,
        string $seccompProfile,
        string $seccompProfileSha256,
        string $configurationSha256,
        string $platformFingerprintSha256,
        string $reviewReceiptSha256,
        string $stateRoot,
        string $repositorySource,
        string $repositoryRemoteName,
        string $repositoryRemoteUrl,
        string $repositoryRemoteUrlSha256,
        string $repositoryRefPrefix,
        string $repositoryCredentialHelper,
        string $repositoryCredentialHelperSha256,
        string $snapshotObjectRoot,
        string $previewDomain,
        string $workloadRepositoryPath = '/srv/duo/repository',
        int $memoryBytes = 1073741824,
        int $nanoCpus = 1000000000,
        int $pidsLimit = 256,
        ?callable $randomBytes = null,
        bool $validateAncillaryMaterial = true
    ) {
        $this->runner = $runner;
        $this->engineBinary = self::absoluteExecutable($engineBinary, 'container engine');
        $this->routerBinary = self::absoluteExecutable($routerBinary, 'route authority');
        $this->firewallBinary = self::absoluteExecutable($firewallBinary, 'host firewall authority');
        $this->storageBinary = self::absoluteExecutable($storageBinary, 'host storage authority');
        $this->gitBinary = self::absoluteExecutable($gitBinary, 'Git');
        if (!ImmutableOciReference::valid($image)) {
            throw new ControlRefusal('workload image must be an immutable lowercase OCI digest reference');
        }
        $this->image = $image;
        $this->imageDigest = ImmutableOciReference::digest($image);
        $this->seccompProfileSha256 = self::sha256(
            $seccompProfileSha256,
            'reviewed seccomp profile digest'
        );
        $this->seccompProfile = self::approvedPinnedFile(
            $seccompProfile,
            $this->seccompProfileSha256,
            'reviewed seccomp profile'
        );
        $this->configurationSha256 = self::sha256($configurationSha256, 'runtime configuration digest');
        $this->platformFingerprintSha256 = self::sha256(
            $platformFingerprintSha256,
            'reviewed platform fingerprint'
        );
        $this->reviewReceiptSha256 = self::sha256($reviewReceiptSha256, 'review receipt');
        $this->reviewedBaseSha256 = hash(
            'sha256',
            "duo-reviewed-preview-base-containment/v1\0"
                . CanonicalJson::encode($this->reviewedBaseContainmentBasis())
        );
        $this->stateRoot = self::privateStateRoot($stateRoot);
        $this->repositorySource = self::approvedDirectory($repositorySource, 'repository source');
        $this->repositoryRemoteName = self::remoteName($repositoryRemoteName);
        $this->repositoryRemoteUrl = self::remoteUrl($repositoryRemoteUrl);
        $this->repositoryRemoteUrlSha256 = self::sha256(
            $repositoryRemoteUrlSha256,
            'repository remote URL digest'
        );
        $expectedRemoteHash = hash(
            'sha256',
            "duo-cloud-repository-remote-url/v1\0" . $this->repositoryRemoteUrl
        );
        if (!hash_equals($expectedRemoteHash, $this->repositoryRemoteUrlSha256)) {
            throw new ControlRefusal('repository remote URL does not match its pinned digest');
        }
        $this->repositoryRefPrefix = self::repositoryRefPrefix($repositoryRefPrefix);
        $this->repositoryCredentialHelperSha256 = self::sha256(
            $repositoryCredentialHelperSha256,
            'repository credential helper digest'
        );
        $this->repositoryCredentialHelper = $validateAncillaryMaterial
            ? self::approvedCredentialHelper(
                $repositoryCredentialHelper,
                $this->repositoryCredentialHelperSha256
            )
            : self::absoluteExecutable($repositoryCredentialHelper, 'repository credential helper');
        $this->snapshotObjectRoot = $validateAncillaryMaterial
            ? self::approvedDirectory($snapshotObjectRoot, 'snapshot object root')
            : self::absolutePath($snapshotObjectRoot, 'snapshot object root');
        $this->previewDomain = self::domain($previewDomain);
        $this->workloadRepositoryPath = self::workloadPath($workloadRepositoryPath);
        if ($memoryBytes < 268435456 || $memoryBytes > 17179869184) {
            throw new ControlRefusal('workload memory limit is outside the closed 256MiB through 16GiB range');
        }
        if ($nanoCpus < 100000000 || $nanoCpus > 8000000000) {
            throw new ControlRefusal('workload CPU limit is outside the closed 0.1 through 8 CPU range');
        }
        if ($pidsLimit < 32 || $pidsLimit > 4096) {
            throw new ControlRefusal('workload PID limit is outside the closed 32 through 4096 range');
        }
        $this->memoryBytes = $memoryBytes;
        $this->nanoCpus = $nanoCpus;
        $this->pidsLimit = $pidsLimit;
        $this->randomBytes = $randomBytes === null
            ? static fn (int $length): string => random_bytes($length)
            : \Closure::fromCallable($randomBytes);
    }

    /**
     * Assemble the same immutable runtime identity for teardown without
     * requiring material that no teardown operation reads.
     *
     * @param callable(int):string|null $randomBytes
     */
    public static function forExpiredPreviewReap(
        ContainerArgvProcessRunner $runner,
        string $engineBinary,
        string $routerBinary,
        string $firewallBinary,
        string $storageBinary,
        string $gitBinary,
        string $image,
        string $seccompProfile,
        string $seccompProfileSha256,
        string $configurationSha256,
        string $platformFingerprintSha256,
        string $reviewReceiptSha256,
        string $stateRoot,
        string $repositorySource,
        string $repositoryRemoteName,
        string $repositoryRemoteUrl,
        string $repositoryRemoteUrlSha256,
        string $repositoryRefPrefix,
        string $repositoryCredentialHelper,
        string $repositoryCredentialHelperSha256,
        string $snapshotObjectRoot,
        string $previewDomain,
        string $workloadRepositoryPath = '/srv/duo/repository',
        int $memoryBytes = 1073741824,
        int $nanoCpus = 1000000000,
        int $pidsLimit = 256,
        ?callable $randomBytes = null
    ): self {
        return new self(
            $runner,
            $engineBinary,
            $routerBinary,
            $firewallBinary,
            $storageBinary,
            $gitBinary,
            $image,
            $seccompProfile,
            $seccompProfileSha256,
            $configurationSha256,
            $platformFingerprintSha256,
            $reviewReceiptSha256,
            $stateRoot,
            $repositorySource,
            $repositoryRemoteName,
            $repositoryRemoteUrl,
            $repositoryRemoteUrlSha256,
            $repositoryRefPrefix,
            $repositoryCredentialHelper,
            $repositoryCredentialHelperSha256,
            $snapshotObjectRoot,
            $previewDomain,
            $workloadRepositoryPath,
            $memoryBytes,
            $nanoCpus,
            $pidsLimit,
            $randomBytes,
            false
        );
    }

    /**
     * Immutable hand-off consumed by the portable preview materializer.
     *
     * @return array<string,string>
     */
    public function reviewedBaseDescriptor(): array {
        return [
            'format' => self::REVIEWED_BASE_FORMAT,
            'image_digest' => $this->imageDigest,
            'platform_fingerprint_sha256' => $this->platformFingerprintSha256,
            'review_receipt_sha256' => $this->reviewReceiptSha256,
        ];
    }

    /**
     * Extended service evidence for the reviewed base's runtime containment.
     *
     * descriptor_sha256 is repeated in resource labels, route/runtime helper
     * evidence, the sealed manifest, and every returned lifecycle digest. The
     * nested reviewed_base is byte-compatible with PortablePreviewMaterializer;
     * the controller can persist this extension beside that four-key input.
     *
     * @return array<string,mixed>
     */
    public function reviewedBaseContainmentDescriptor(): array {
        return ['descriptor_sha256' => $this->reviewedBaseSha256]
            + $this->reviewedBaseContainmentBasis();
    }

    /** @return array<string,mixed> */
    public function repositoryAuthorityDescriptor(): array {
        $basis = [
            'credential_helper_sha256' => $this->repositoryCredentialHelperSha256,
            'format' => self::REPOSITORY_AUTHORITY_FORMAT,
            'ref_prefix' => $this->repositoryRefPrefix,
            'remote_url_sha256' => $this->repositoryRemoteUrlSha256,
        ];
        return ['descriptor_sha256' => hash(
            'sha256',
            self::REPOSITORY_AUTHORITY_FORMAT . "\0" . CanonicalJson::encode($basis)
        )] + $basis;
    }

    public function syncRepository(array $authority, array $repository): array {
        $identity = $this->identity($authority, true);
        self::exactKeys(
            $repository,
            [
                'branch_commit', 'branch_ref', 'candidate_publication_receipt_sha256',
                'repository_authority_sha256',
            ],
            'repository sync input'
        );
        $commit = self::gitOid($repository['branch_commit'] ?? null);
        $ref = self::gitRef($repository['branch_ref'] ?? null);
        $expectedAuthority = self::sha256(
            $repository['repository_authority_sha256'] ?? null,
            'repository authority descriptor'
        );
        $publicationReceipt = self::sha256(
            $repository['candidate_publication_receipt_sha256'] ?? null,
            'candidate publication receipt'
        );
        $descriptor = $this->repositoryAuthorityDescriptor();
        if (!hash_equals((string) $descriptor['descriptor_sha256'], $expectedAuthority)) {
            throw new ControlRefusal('repository sync does not name the configured remote authority');
        }
        $this->assertOperationRepositoryRef($identity, $ref);
        return $this->locked($identity, function () use (
            $identity,
            $commit,
            $ref,
            $expectedAuthority,
            $publicationReceipt
        ): array {
            $manifest = $this->requireHeldManifest($identity);
            $this->assertRepositoryCredentialHelperReadback();
            $localRef = $this->repositorySyncLocalRef($identity, $ref);
            $syncRecord = [
                'branch_commit' => $commit,
                'branch_ref' => $ref,
                'candidate_publication_receipt_sha256' => $publicationReceipt,
                'local_ref' => $localRef,
                'repository_authority_sha256' => $expectedAuthority,
                'state' => 'present',
            ];
            if ($manifest['repository_sync'] !== null) {
                if (CanonicalJson::encode($manifest['repository_sync'])
                    !== CanonicalJson::encode($syncRecord)) {
                    throw new ControlRefusal('repository sync differs from the generation-pinned candidate');
                }
                if ($this->repositoryRefCommit($localRef) !== $commit) {
                    throw new ControlRefusal('generation-pinned repository sync ref changed or disappeared');
                }
                return ['evidence_sha256' => $this->evidence('repository-sync', [
                    'authority' => $this->publicAuthority($identity),
                    'branch_commit' => $commit,
                    'branch_ref' => $ref,
                    'candidate_publication_receipt_sha256' => $publicationReceipt,
                    'local_ref' => $localRef,
                    'repository_authority_sha256' => $expectedAuthority,
                ])];
            }
            $this->assertRepositoryRemoteReadback();
            $advertised = $this->mustRun(array_merge([
                $this->gitBinary,
                '-C',
                $this->repositorySource,
            ], $this->repositoryRemoteGitConfiguration(), [
                'ls-remote',
                '--refs',
                $this->repositoryRemoteName,
                $ref,
            ]), null, 'repository remote ref readback');
            if ($advertised['stdout'] !== $commit . "\t" . $ref . "\n") {
                throw new ControlRefusal('repository remote ref does not resolve to the requested immutable commit');
            }
            $this->mustRun(array_merge([
                $this->gitBinary,
                '-C',
                $this->repositorySource,
            ], $this->repositoryRemoteGitConfiguration(), [
                'fetch',
                '--no-tags',
                '--no-recurse-submodules',
                $this->repositoryRemoteName,
                $ref . ':' . $localRef,
            ]), null, 'repository remote fetch');
            $resolved = $this->mustRun([
                $this->gitBinary,
                '-C',
                $this->repositorySource,
                '-c',
                'core.hooksPath=/dev/null',
                'rev-parse',
                '--verify',
                $localRef . '^{commit}',
            ], null, 'synced repository ref verification');
            if ($resolved['stdout'] !== $commit . "\n") {
                throw new ControlRefusal('synced repository ref changed the requested immutable commit');
            }
            $manifest['repository_sync'] = $syncRecord;
            $this->writeManifest($identity, $manifest);
            return ['evidence_sha256' => $this->evidence('repository-sync', [
                'authority' => $this->publicAuthority($identity),
                'branch_commit' => $commit,
                'branch_ref' => $ref,
                'candidate_publication_receipt_sha256' => $publicationReceipt,
                'local_ref' => $localRef,
                'repository_authority_sha256' => $expectedAuthority,
            ])];
        });
    }

    public function inspect(array $lease): array {
        $identity = $this->identity($lease, false);
        return $this->locked($identity, function () use ($identity): array {
            $manifest = $this->readManifest($identity, true);
            if ($manifest === null) {
                $absent = $this->physicalAbsence($identity);
                if (!$absent) {
                    throw new ControlRefusal('preview generation has physical state without exact runtime authority');
                }
                return [
                    'evidence_sha256' => $this->evidence('inspect-absent', $identity),
                    'presence' => 'absent',
                    'url' => $identity['url'],
                ];
            }
            $this->assertManifestLease($manifest, $identity);
            $this->assertPresent($manifest);
            return [
                'evidence_sha256' => $this->evidence('inspect-present', $this->publicManifest($manifest)),
                'presence' => 'present',
                'url' => $manifest['url'],
            ];
        });
    }

    public function provision(array $lease): array {
        $identity = $this->identity($lease, false);
        return $this->locked($identity, function () use ($identity): array {
            $manifest = $this->readManifest($identity, true);
            if ($manifest === null) {
                $this->deleteGenerationState($identity);
                if (!$this->physicalAbsence($identity)) {
                    throw new ControlRefusal('preview generation is not absent before clean-base provision');
                }
                $this->ensureGenerationDirectory($identity);
                $manifest = $this->newManifest($identity);
                $this->writeManifest($identity, $manifest);
                $manifest['credential_fingerprints'] = $this->createCredentials($manifest);
                $this->writeManifest($identity, $manifest);
            } else {
                $this->assertManifestLease($manifest, $identity);
                if (!in_array($manifest['state'], ['provisioning', 'present'], true)) {
                    throw new ControlRefusal('preview generation cannot be provisioned from its runtime state');
                }
                if ($manifest['credential_fingerprints'] === null) {
                    $this->clearUnpublishedCredentials($manifest);
                    if (!$this->physicalAbsence($identity, false)) {
                        throw new ControlRefusal(
                            'unsealed generation credentials coexist with physical runtime state'
                        );
                    }
                    $this->ensureGenerationDirectory($identity);
                    $manifest['credential_fingerprints'] = $this->createCredentials($manifest);
                    $this->writeManifest($identity, $manifest);
                } else {
                    $this->assertCredentials($manifest);
                }
            }

            $this->ensureNetwork($manifest);
            $this->ensureVolume($manifest, 'database');
            $this->ensureVolume($manifest, 'filesystem');
            $this->ensureStorage($manifest);
            $this->ensureContainer($manifest);
            $this->assertRuntimeStatus($manifest, true);
            $manifest['execution_state'] = 'running';
            $manifest['state'] = 'present';
            $this->writeManifest($identity, $manifest);
            $this->assertPresent($manifest);
            return [
                'evidence_sha256' => $this->evidence('provision', $this->publicManifest($manifest)),
                'url' => $manifest['url'],
            ];
        });
    }

    public function restoreSnapshot(array $authority, array $snapshot): array {
        $identity = $this->identity($authority, true);
        self::exactKeys($snapshot, ['database_sha256', 'media_sha256', 'snapshot_set_id'], 'snapshot input');
        $databaseHash = self::sha256($snapshot['database_sha256'] ?? null, 'database snapshot hash');
        $mediaHash = self::sha256($snapshot['media_sha256'] ?? null, 'media snapshot hash');
        $snapshotSetId = self::identifier($snapshot['snapshot_set_id'] ?? null, 'snapshot set id');
        return $this->locked($identity, function () use (
            $identity,
            $databaseHash,
            $mediaHash,
            $snapshotSetId
        ): array {
            $manifest = $this->requireHeldManifest($identity);
            $database = $this->snapshotObject($databaseHash);
            $media = $this->snapshotObject($mediaHash);
            $this->stage(
                $manifest,
                'restore-database',
                [
                    '--database-sha256', $databaseHash,
                    '--snapshot-set-id', $snapshotSetId,
                ],
                $database
            );
            $this->stage(
                $manifest,
                'restore-media',
                [
                    '--media-sha256', $mediaHash,
                    '--snapshot-set-id', $snapshotSetId,
                ],
                $media
            );
            $manifest['last_snapshot_sha256'] = $this->evidence('snapshot-input', [
                'database_sha256' => $databaseHash,
                'media_sha256' => $mediaHash,
                'snapshot_set_id' => $snapshotSetId,
            ]);
            $this->writeManifest($identity, $manifest);
            return [
                'evidence_sha256' => $this->evidence('snapshot-restore', [
                    'authority' => $this->publicAuthority($identity),
                    'database_sha256' => $databaseHash,
                    'media_sha256' => $mediaHash,
                    'snapshot_set_id' => $snapshotSetId,
                ]),
            ];
        });
    }

    public function materializeRepository(array $authority, array $repository): array {
        $identity = $this->identity($authority, true);
        self::exactKeys($repository, ['branch_commit', 'branch_ref', 'repo_path'], 'repository input');
        $commit = self::gitOid($repository['branch_commit'] ?? null);
        $ref = self::gitRef($repository['branch_ref'] ?? null);
        if (($repository['repo_path'] ?? null) !== $this->workloadRepositoryPath) {
            throw new ControlRefusal('repository destination is not the configured workload path');
        }
        return $this->locked($identity, function () use ($identity, $commit, $ref): array {
            $manifest = $this->requireHeldManifest($identity);
            $this->assertOperationRepositoryRef($identity, $ref);
            $syncRecord = $manifest['repository_sync'];
            if (!is_array($syncRecord)
                || ($syncRecord['state'] ?? null) !== 'present'
                || ($syncRecord['branch_commit'] ?? null) !== $commit
                || ($syncRecord['branch_ref'] ?? null) !== $ref
                || ($syncRecord['local_ref'] ?? null)
                    !== $this->repositorySyncLocalRef($identity, $ref)) {
                throw new ControlRefusal('repository materialization differs from generation-pinned sync');
            }
            $this->assertApprovedGitObjectSource(
                $commit,
                $this->repositorySyncLocalRef($identity, $ref)
            );
            $archive = $this->archivePath($manifest, $commit);
            try {
                $this->mustRun([
                    $this->gitBinary,
                    '-C',
                    $this->repositorySource,
                    '-c',
                    'core.hooksPath=/dev/null',
                    'archive',
                    '--format=tar',
                    '--output=' . $archive,
                    $commit,
                ], null, 'local Git archive');
                self::secureArchive($archive);
                $this->stage(
                    $manifest,
                    'materialize-repository',
                    [
                        '--branch-commit', $commit,
                        '--branch-ref', $ref,
                        '--destination', $this->workloadRepositoryPath,
                    ],
                    $archive
                );
            } finally {
                if (is_file($archive) && !is_link($archive)) {
                    @unlink($archive);
                }
            }
            $manifest['last_repository_sha256'] = $this->evidence('repository-input', [
                'branch_commit' => $commit,
                'branch_ref' => $ref,
                'repo_path' => $this->workloadRepositoryPath,
            ]);
            $this->writeManifest($identity, $manifest);
            return [
                'evidence_sha256' => $this->evidence('repository-materialize', [
                    'authority' => $this->publicAuthority($identity),
                    'branch_commit' => $commit,
                    'branch_ref' => $ref,
                    'repo_path' => $this->workloadRepositoryPath,
                ]),
            ];
        });
    }

    public function configureUrl(array $authority, string $url): array {
        $identity = $this->identity($authority, true);
        if ($url !== $identity['url']) {
            throw new ControlRefusal('route URL does not match the credential-free generation URL');
        }
        return $this->locked($identity, function () use ($identity): array {
            $manifest = $this->requireHeldManifest($identity);
            $this->stage(
                $manifest,
                'url-rebind',
                ['--url', $manifest['url']]
            );
            $this->mustRun($this->routeArgv('bind', $manifest), null, 'route binding');
            $this->assertRoute($manifest, true);
            $manifest['route_state'] = 'bound';
            $this->writeManifest($identity, $manifest);
            return [
                'evidence_sha256' => $this->evidence('url-configure', [
                    'host' => $manifest['route_host'],
                    'route_id' => $manifest['route_id'],
                    'url' => $manifest['url'],
                ]),
            ];
        });
    }

    public function revokeRouting(array $authority): array {
        $identity = $this->identity($authority, true);
        return $this->locked($identity, function () use ($identity): array {
            $manifest = $this->requireHeldManifest($identity, false);
            // The global route journal may have accepted bind even when its
            // response was lost before this local manifest advanced. Unbind is
            // the idempotent authority operation and must never trust that lagging flag.
            $this->mustRun($this->routeArgv('unbind', $manifest), null, 'route revocation');
            $this->assertRoute($manifest, false);
            $manifest['route_state'] = 'absent';
            $this->writeManifest($identity, $manifest);
            return [
                'evidence_sha256' => $this->evidence('routing-revoke', [
                    'route_id' => $manifest['route_id'],
                    'state' => 'absent',
                ]),
            ];
        });
    }

    public function revokeExecution(array $authority): array {
        $identity = $this->identity($authority, true);
        return $this->locked($identity, function () use ($identity): array {
            $manifest = $this->requireHeldManifest($identity, false);
            if ($manifest['route_state'] !== 'absent') {
                throw new ControlRefusal('execution cannot be revoked before routing is absent');
            }
            if ($this->namedResourcePresent('container', $manifest['container_name'])) {
                $this->assertContainer($manifest, null);
                $this->mustRun([
                    $this->engineBinary,
                    'container',
                    'stop',
                    '--timeout',
                    (string) self::STOP_SECONDS,
                    $manifest['container_name'],
                ], null, 'container stop');
                $this->assertContainer($manifest, false);
                $this->mustRun([
                    $this->engineBinary,
                    'container',
                    'rm',
                    $manifest['container_name'],
                ], null, 'container removal');
            }
            if ($this->namedResourcePresent('container', $manifest['container_name'])) {
                throw new ControlRefusal('container execution remains present after revocation');
            }
            $manifest['execution_state'] = 'revoked';
            $this->writeManifest($identity, $manifest);
            return [
                'evidence_sha256' => $this->evidence('execution-revoke', [
                    'container_name' => $manifest['container_name'],
                    'state' => 'absent',
                ]),
            ];
        });
    }

    public function resumeExecution(array $authority): array {
        $identity = $this->identity($authority, true);
        return $this->locked($identity, function () use ($identity): array {
            $manifest = $this->requireHeldManifest($identity, false);
            if ($manifest['state'] !== 'present'
                || !in_array($manifest['execution_state'], ['revoked', 'running'], true)
                || $manifest['route_state'] !== 'absent') {
                throw new ControlRefusal('execution can resume only for an exact retained unrouted generation');
            }
            // Sleep retains this substrate by contract. Re-proving every
            // generation-bound object before container creation prevents a
            // foreign replacement from being adopted as a wake operation.
            $this->assertCredentials($manifest);
            $this->assertNetwork($manifest);
            $this->assertFirewall($manifest, true);
            $this->assertVolume($manifest, 'database');
            $this->assertVolume($manifest, 'filesystem');
            $this->assertStorage($manifest, true);
            $this->assertRoute($manifest, false);
            $this->ensureContainer($manifest);
            $this->assertRuntimeStatus($manifest, null);
            $manifest['execution_state'] = 'running';
            $this->writeManifest($identity, $manifest);
            $this->assertPresent($manifest);
            return [
                'evidence_sha256' => $this->evidence('execution-resume', $this->publicManifest($manifest)),
            ];
        });
    }

    public function deleteState(array $authority): array {
        $identity = $this->identity($authority, true);
        return $this->locked($identity, function () use ($identity): array {
            $existing = $this->readManifest($identity, true);
            if ($existing === null) {
                $this->deleteGenerationState($identity);
                if (!$this->physicalAbsence($identity)) {
                    throw new ControlRefusal('generation state deletion replay has residual physical state');
                }
                return [
                    'evidence_sha256' => $this->evidence('state-delete', [
                        'lease_generation' => $identity['lease_generation'],
                        'resource_id' => $identity['resource_id'],
                        'state' => 'deleted',
                    ]),
                ];
            }
            $manifest = $this->requireHeldManifest($identity, false);
            if ($manifest['state'] === 'deleted') {
                if ($manifest['repository_sync'] !== null) {
                    throw new ControlRefusal('deleted runtime manifest retains a repository sync ref');
                }
                $this->deleteGenerationState($identity);
                if (!$this->physicalAbsence($identity)) {
                    throw new ControlRefusal('deleted runtime manifest does not have terminal physical absence');
                }
                $active = $this->manifestPath($identity);
                if (!@unlink($active) && is_file($active)) {
                    throw new ControlRefusal('generation runtime authority could not be cleared');
                }
                $this->syncDirectory(dirname($active), 'runtime authority manifest deletion directory');
                return [
                    'evidence_sha256' => $this->evidence('state-delete', [
                        'lease_generation' => $manifest['lease_generation'],
                        'resource_id' => $manifest['resource_id'],
                        'state' => 'deleted',
                    ]),
                ];
            }
            if ($manifest['route_state'] !== 'absent' || $manifest['execution_state'] !== 'revoked') {
                throw new ControlRefusal('generation state cannot be deleted before route and execution revocation');
            }
            if ($this->namedResourcePresent('container', $manifest['container_name'])) {
                throw new ControlRefusal('generation container remains present before state deletion');
            }
            $this->deleteRepositorySync($identity, $manifest);
            $this->mustRun(
                $this->storageArgv('unbind', $manifest),
                null,
                'generation storage quota deletion'
            );
            $this->assertStorage($manifest, false);
            foreach (['database_volume', 'filesystem_volume'] as $field) {
                if ($this->namedResourcePresent('volume', $manifest[$field])) {
                    $this->mustRun([
                        $this->engineBinary,
                        'volume',
                        'rm',
                        $manifest[$field],
                    ], null, 'generation volume deletion');
                }
                if ($this->namedResourcePresent('volume', $manifest[$field])) {
                    throw new ControlRefusal('generation volume remains present after deletion');
                }
            }
            $this->mustRun(
                $this->firewallArgv('unbind', $manifest),
                null,
                'host firewall binding deletion'
            );
            $this->assertFirewall($manifest, false);
            if ($this->namedResourcePresent('network', $manifest['network_name'])) {
                $this->mustRun([
                    $this->engineBinary,
                    'network',
                    'rm',
                    $manifest['network_name'],
                ], null, 'generation network deletion');
            }
            if ($this->namedResourcePresent('network', $manifest['network_name'])) {
                throw new ControlRefusal('generation network remains present after deletion');
            }
            if ($manifest['state'] !== 'deleting-credentials') {
                $this->assertCredentials($manifest);
                $manifest['state'] = 'deleting-credentials';
                $this->writeManifest($identity, $manifest);
            }
            $this->deleteCredentials($manifest);
            $this->deleteGenerationState($identity);
            $manifest['state'] = 'deleted';
            $this->writeManifest($identity, $manifest);
            $active = $this->manifestPath($identity);
            if (!@unlink($active) && is_file($active)) {
                throw new ControlRefusal('generation runtime authority could not be cleared');
            }
            $this->syncDirectory(dirname($active), 'runtime authority manifest deletion directory');
            return [
                'evidence_sha256' => $this->evidence('state-delete', [
                    'lease_generation' => $manifest['lease_generation'],
                    'resource_id' => $manifest['resource_id'],
                    'state' => 'deleted',
                ]),
            ];
        });
    }

    public function verifyAbsent(array $lease): array {
        $identity = $this->identity($lease, false);
        return $this->locked($identity, function () use ($identity): array {
            $manifest = $this->readManifest($identity, true);
            if ($manifest !== null) {
                $this->assertManifestLease($manifest, $identity);
            }
            $absent = $manifest === null && $this->physicalAbsence($identity);
            return [
                'absence_proof_sha256' => $this->evidence('terminal-absence', [
                    'absent' => $absent,
                    'configuration_sha256' => $this->configurationSha256,
                    'lease_generation' => $identity['lease_generation'],
                    'resource_id' => $identity['resource_id'],
                ]),
                'absent' => $absent,
            ];
        });
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private function newManifest(array $identity): array {
        return [
            'configuration_sha256' => $this->configurationSha256,
            'container_name' => $identity['container_name'],
            'credential_fingerprints' => null,
            'credentials_directory' => $identity['credentials_directory'],
            'database_volume' => $identity['database_volume'],
            'environment_identity' => $identity['environment_identity'],
            'execution_state' => 'preparing',
            'filesystem_volume' => $identity['filesystem_volume'],
            'format' => self::MANIFEST_FORMAT,
            'image' => $this->image,
            'last_mutation' => null,
            'last_repository_sha256' => null,
            'last_snapshot_sha256' => null,
            'lease_generation' => $identity['lease_generation'],
            'lease_id' => $identity['lease_id'],
            'network_name' => $identity['network_name'],
            'ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
            'repository_sync' => null,
            'resource_id' => $identity['resource_id'],
            'route_host' => $identity['route_host'],
            'route_id' => $identity['route_id'],
            'route_state' => 'absent',
            'reviewed_base_sha256' => $this->reviewedBaseSha256,
            'site_id' => $identity['site_id'],
            'state' => 'provisioning',
            'tenant_id' => $identity['tenant_id'],
            'url' => $identity['url'],
        ];
    }

    /** @param array<string,mixed> $manifest @return array<string,string> */
    private function createCredentials(array $manifest): array {
        $directory = $manifest['credentials_directory'];
        if (file_exists($directory) || is_link($directory)) {
            throw new ControlRefusal('generation credential directory already exists without published fingerprints');
        }
        if (!mkdir($directory, 0700, true) || !chmod($directory, 0700)
            || !$this->setCredentialOwnership($directory)) {
            throw new ControlRefusal('generation credential directory could not be created privately');
        }
        $specification = [
            'database-password' => 32,
            'wordpress-auth-key' => 48,
            'wordpress-auth-salt' => 48,
        ];
        $fingerprints = [];
        try {
            foreach ($specification as $name => $length) {
                $bytes = ($this->randomBytes)($length);
                if (!is_string($bytes) || strlen($bytes) !== $length) {
                    throw new ControlRefusal('credential entropy source returned the wrong byte count');
                }
                $secret = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=') . "\n";
                $path = $directory . '/' . $name;
                $handle = @fopen($path, 'x+b');
                if (!is_resource($handle)) {
                    throw new ControlRefusal('generation credential file could not be created exclusively');
                }
                try {
                    if (!flock($handle, LOCK_EX)
                        || fwrite($handle, $secret) !== strlen($secret)
                        || !fflush($handle)
                        || (function_exists('fsync') && !fsync($handle))) {
                        throw new ControlRefusal('generation credential file could not be written privately');
                    }
                } finally {
                    fclose($handle);
                }
                if (!chmod($path, 0600) || !$this->setCredentialOwnership($path)) {
                    throw new ControlRefusal('generation credential file ownership could not be sealed');
                }
                $this->workloadPrivateFile($path, 'generation credential file');
                $fingerprints[$name] = hash('sha256', $secret);
            }
        } catch (\Throwable $error) {
            foreach (array_keys($specification) as $name) {
                @unlink($directory . '/' . $name);
            }
            @rmdir($directory);
            throw $error;
        }
        $this->syncDirectory($directory, 'generation credential directory');
        $this->syncDirectory(dirname($directory), 'generation credential parent directory');
        return $fingerprints;
    }

    /** @param array<string,mixed> $manifest */
    private function assertCredentials(array $manifest): void {
        $fingerprints = $manifest['credential_fingerprints'] ?? null;
        if (!is_array($fingerprints) || array_is_list($fingerprints)) {
            throw new ControlRefusal('generation credential fingerprints are missing');
        }
        self::exactKeys(
            $fingerprints,
            ['database-password', 'wordpress-auth-key', 'wordpress-auth-salt'],
            'credential fingerprints'
        );
        $directory = $manifest['credentials_directory'];
        $this->workloadPrivateDirectory($directory, 'generation credential directory');
        foreach ($fingerprints as $name => $fingerprint) {
            self::sha256($fingerprint, 'credential fingerprint');
            $path = $directory . '/' . $name;
            $this->workloadPrivateFile($path, 'generation credential file');
            $actual = hash_file('sha256', $path);
            if (!is_string($actual) || !hash_equals($fingerprint, $actual)) {
                throw new ControlRefusal('generation credential fingerprint differs from sealed runtime state');
            }
        }
    }

    /** @param array<string,mixed> $manifest */
    private function deleteCredentials(array $manifest): void {
        $fingerprints = $manifest['credential_fingerprints'] ?? null;
        if (!is_array($fingerprints) || array_is_list($fingerprints)) {
            throw new ControlRefusal('generation credential fingerprints are missing during deletion');
        }
        self::exactKeys(
            $fingerprints,
            ['database-password', 'wordpress-auth-key', 'wordpress-auth-salt'],
            'credential deletion fingerprints'
        );
        $directory = $manifest['credentials_directory'];
        if (!file_exists($directory) && !is_link($directory)) {
            return;
        }
        $this->workloadPrivateDirectory($directory, 'generation credential directory during deletion');
        foreach ($fingerprints as $name => $fingerprint) {
            $path = $directory . '/' . $name;
            if (!file_exists($path) && !is_link($path)) {
                continue;
            }
            $this->workloadPrivateFile($path, 'generation credential file during deletion');
            $actual = hash_file('sha256', $path);
            if (!is_string($actual) || !hash_equals($fingerprint, $actual)) {
                throw new ControlRefusal('generation credential changed during deletion');
            }
            if (!@unlink($path) && (file_exists($path) || is_link($path))) {
                throw new ControlRefusal('generation credential file could not be deleted');
            }
        }
        $entries = @scandir($directory);
        if (!is_array($entries) || array_values(array_diff($entries, ['.', '..'])) !== []) {
            throw new ControlRefusal('generation credential directory contains foreign state during deletion');
        }
        if (!@rmdir($directory) && is_dir($directory)) {
            throw new ControlRefusal('generation credential directory could not be deleted');
        }
        $this->syncDirectory(dirname($directory), 'generation credential deletion parent');
    }

    /** @param array<string,mixed> $manifest */
    private function clearUnpublishedCredentials(array $manifest): void {
        $directory = $manifest['credentials_directory'];
        if (!file_exists($directory) && !is_link($directory)) {
            return;
        }
        if (is_link($directory) || !is_dir($directory)) {
            throw new ControlRefusal('unpublished credential state is not a safe directory');
        }
        foreach (['database-password', 'wordpress-auth-key', 'wordpress-auth-salt'] as $name) {
            $path = $directory . '/' . $name;
            if ((file_exists($path) || is_link($path))
                && (is_link($path) || !is_file($path) || !@unlink($path))) {
                throw new ControlRefusal('unpublished credential state could not be cleared safely');
            }
        }
        $entries = @scandir($directory);
        if (!is_array($entries) || array_values(array_diff($entries, ['.', '..'])) !== []) {
            throw new ControlRefusal('unpublished credential directory contains foreign state');
        }
        if (!@rmdir($directory)) {
            throw new ControlRefusal('unpublished credential directory could not be cleared');
        }
        $this->syncDirectory(dirname($directory), 'unpublished credential deletion parent');
    }

    /** @param array<string,mixed> $identity */
    private function ensureGenerationDirectory(array $identity): void {
        $generation = dirname($identity['credentials_directory']);
        if (!is_dir($generation)
            && (!mkdir($generation, 0700, true) || !chmod($generation, 0700))) {
            throw new ControlRefusal('runtime generation directory could not be created privately');
        }
        self::privateDirectory($generation, 'generation state directory');
    }

    /**
     * A hard death can strand Git's operation-owned archive before the caller's
     * finally block runs. Unknown bytes refuse so terminal absence never hides
     * foreign state inside a generation the lifecycle is about to forget.
     *
     * @param array<string,mixed> $identity
     */
    private function deleteGenerationState(array $identity): void {
        $generation = dirname($identity['credentials_directory']);
        if (!file_exists($generation) && !is_link($generation)) {
            return;
        }
        self::privateDirectory($generation, 'generation state directory during deletion');
        $entries = @scandir($generation);
        if (!is_array($entries)) {
            throw new ControlRefusal('generation state directory could not be inspected during deletion');
        }
        foreach (array_values(array_diff($entries, ['.', '..'])) as $name) {
            if (preg_match('/^repository-[a-f0-9]{40}(?:[a-f0-9]{24})?\.tar$/D', $name) !== 1) {
                throw new ControlRefusal('generation state directory contains foreign state during deletion');
            }
            $this->deleteGenerationArchive($generation . '/' . $name);
        }
        $remaining = @scandir($generation);
        if (!is_array($remaining) || array_values(array_diff($remaining, ['.', '..'])) !== []) {
            throw new ControlRefusal('generation state directory changed during deletion');
        }
        if (!@rmdir($generation) && is_dir($generation)) {
            throw new ControlRefusal('generation state directory could not be deleted');
        }
        $this->syncDirectory(dirname($generation), 'generation state deletion parent');
    }

    private function deleteGenerationArchive(string $path): void {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || is_link($path)
            || ((int) ($before['mode'] ?? 0) & 0170000) !== 0100000
            || (int) ($before['nlink'] ?? 0) !== 1
            || (function_exists('posix_geteuid') && (int) ($before['uid'] ?? -1) !== posix_geteuid())) {
            throw new ControlRefusal('generation repository archive is not an owned regular file');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal('generation repository archive could not be opened during deletion');
        }
        try {
            $opened = fstat($handle);
            clearstatcache(true, $path);
            $named = @lstat($path);
            if (!is_array($opened) || !is_array($named)
                || !self::sameManifestFile($before, $opened)
                || !self::sameManifestFile($before, $named)
                || !@unlink($path)) {
                throw new ControlRefusal('generation repository archive changed during deletion');
            }
            $unlinked = fstat($handle);
            clearstatcache(true, $path);
            if (!is_array($unlinked) || @lstat($path) !== false
                || (int) $unlinked['dev'] !== (int) $opened['dev']
                || (int) $unlinked['ino'] !== (int) $opened['ino']
                || (int) ($unlinked['nlink'] ?? -1) !== 0) {
                throw new ControlRefusal('generation repository archive changed while being removed');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $manifest */
    private function ensureNetwork(array $manifest): void {
        if (!$this->namedResourcePresent('network', $manifest['network_name'])) {
            $this->mustRun([
                $this->engineBinary,
                'network',
                'create',
                '--driver',
                'bridge',
                '--internal',
                '--ipv6=false',
                '--opt',
                'com.docker.network.bridge.enable_ip_masquerade=false',
                '--opt',
                'com.docker.network.bridge.enable_icc=false',
                '--opt',
                'com.docker.network.bridge.name=' . self::bridgeName($manifest['network_name']),
                '--label',
                'duo.cloud.configuration-sha256=' . $this->configurationSha256,
                '--label',
                'duo.cloud.lease-generation=' . $manifest['lease_generation'],
                '--label',
                'duo.cloud.resource-id=' . $manifest['resource_id'],
                '--label',
                'duo.cloud.reviewed-base-sha256=' . $this->reviewedBaseSha256,
                $manifest['network_name'],
            ], null, 'internal network creation');
        }
        $this->assertNetwork($manifest);
        $network = $this->networkAuthority($manifest);
        $this->mustRun($this->firewallArgv('bind', $manifest, $network), null, 'host firewall binding');
        $this->assertFirewall($manifest, true, $network);
    }

    /** @param array<string,mixed> $manifest */
    private function ensureVolume(array $manifest, string $kind): void {
        $field = $kind . '_volume';
        $name = $manifest[$field];
        if (!$this->namedResourcePresent('volume', $name)) {
            $this->mustRun([
                $this->engineBinary,
                'volume',
                'create',
                '--driver',
                'local',
                '--label',
                'duo.cloud.configuration-sha256=' . $this->configurationSha256,
                '--label',
                'duo.cloud.data-kind=' . $kind,
                '--label',
                'duo.cloud.lease-generation=' . $manifest['lease_generation'],
                '--label',
                'duo.cloud.resource-id=' . $manifest['resource_id'],
                '--label',
                'duo.cloud.reviewed-base-sha256=' . $this->reviewedBaseSha256,
                $name,
            ], null, "generation $kind volume creation");
        }
        $this->assertVolume($manifest, $kind);
    }

    /** @param array<string,mixed> $manifest */
    private function ensureStorage(array $manifest): void {
        $this->mustRun(
            $this->storageArgv('bind', $manifest),
            null,
            'generation encrypted quota binding'
        );
        $this->assertStorage($manifest, true);
    }

    /** @param array<string,mixed> $manifest */
    private function ensureContainer(array $manifest): void {
        if (!$this->namedResourcePresent('container', $manifest['container_name'])) {
            $this->mustRun($this->containerCreateArgv($manifest), null, 'workload container creation');
        }
        $this->assertContainer($manifest, null);
        $inspection = $this->containerInspection($manifest['container_name']);
        if (($inspection['State']['Running'] ?? null) !== true) {
            $this->mustRun([
                $this->engineBinary,
                'container',
                'start',
                $manifest['container_name'],
            ], null, 'workload container start');
        }
        $this->assertContainer($manifest, true);
    }

    /** @param array<string,mixed> $manifest @return non-empty-list<string> */
    private function containerCreateArgv(array $manifest): array {
        return [
            $this->engineBinary,
            'container',
            'create',
            '--name',
            $manifest['container_name'],
            '--pull',
            'never',
            '--hostname',
            'duo-' . substr(hash('sha256', $manifest['resource_id']), 0, 40),
            '--user',
            '10001:10001',
            '--read-only',
            '--cap-drop',
            'ALL',
            '--security-opt',
            'no-new-privileges=true',
            '--security-opt',
            'seccomp=' . $this->seccompProfile,
            '--pids-limit',
            (string) $this->pidsLimit,
            '--memory',
            (string) $this->memoryBytes,
            '--memory-swap',
            (string) $this->memoryBytes,
            '--shm-size',
            (string) self::SHM_BYTES,
            '--ulimit',
            'nofile=' . self::NOFILE_LIMIT . ':' . self::NOFILE_LIMIT,
            '--cpus',
            self::cpuString($this->nanoCpus),
            '--network',
            $manifest['network_name'],
            '--dns',
            self::DNS_SERVER,
            '--dns-search',
            '.',
            '--dns-opt',
            'timeout:1',
            '--dns-opt',
            'attempts:1',
            '--restart',
            'no',
            '--log-driver',
            'none',
            '--stop-timeout',
            (string) self::STOP_SECONDS,
            '--tmpfs',
            '/run:rw,noexec,nosuid,nodev,size=16777216,mode=0700,uid=10001,gid=10001',
            '--tmpfs',
            '/tmp:rw,noexec,nosuid,nodev,size=67108864,mode=0700,uid=10001,gid=10001',
            '--mount',
            'type=volume,source=' . $manifest['database_volume'] . ',target=' . self::DATABASE_TARGET,
            '--mount',
            'type=volume,source=' . $manifest['filesystem_volume'] . ',target=' . self::FILESYSTEM_TARGET,
            '--mount',
            'type=bind,source=' . $manifest['credentials_directory']
                . ',target=' . self::SECRET_TARGET . ',readonly,bind-propagation=rprivate',
            '--label',
            'duo.cloud.configuration-sha256=' . $this->configurationSha256,
            '--label',
            'duo.cloud.lease-generation=' . $manifest['lease_generation'],
            '--label',
            'duo.cloud.ownership-receipt-sha256=' . $manifest['ownership_receipt_sha256'],
            '--label',
            'duo.cloud.resource-id=' . $manifest['resource_id'],
            '--label',
            'duo.cloud.reviewed-base-sha256=' . $this->reviewedBaseSha256,
            '--entrypoint',
            '/opt/duo/bin/duo-preview-runtime',
            $this->image,
            'serve',
            '--clean-base',
            '--config-sha256',
            $this->configurationSha256,
            '--lease-generation',
            (string) $manifest['lease_generation'],
            '--reviewed-base-sha256',
            $this->reviewedBaseSha256,
        ];
    }

    /** @param array<string,mixed> $manifest */
    private function assertNetwork(array $manifest): void {
        $network = $this->inspectObject('network', $manifest['network_name']);
        $labels = $network['Labels'] ?? null;
        $options = $network['Options'] ?? null;
        if (($network['Name'] ?? null) !== $manifest['network_name']
            || ($network['Driver'] ?? null) !== 'bridge'
            || ($network['Internal'] ?? null) !== true
            || ($network['EnableIPv6'] ?? null) !== false
            || ($network['Attachable'] ?? null) !== false
            || !is_array($labels)
            || ($labels['duo.cloud.configuration-sha256'] ?? null) !== $this->configurationSha256
            || ($labels['duo.cloud.lease-generation'] ?? null) !== (string) $manifest['lease_generation']
            || ($labels['duo.cloud.resource-id'] ?? null) !== $manifest['resource_id']
            || ($labels['duo.cloud.reviewed-base-sha256'] ?? null) !== $this->reviewedBaseSha256
            || !is_array($options)
            || ($options['com.docker.network.bridge.enable_ip_masquerade'] ?? null) !== 'false'
            || ($options['com.docker.network.bridge.enable_icc'] ?? null) !== 'false'
            || ($options['com.docker.network.bridge.name'] ?? null)
                !== self::bridgeName($manifest['network_name'])) {
            throw new ControlRefusal('container runtime cannot prove default-deny outbound network containment');
        }
    }

    /** @param array<string,mixed> $manifest */
    private function assertVolume(array $manifest, string $kind): void {
        $name = $manifest[$kind . '_volume'];
        $volume = $this->inspectObject('volume', $name);
        $labels = $volume['Labels'] ?? null;
        if (($volume['Name'] ?? null) !== $name
            || ($volume['Driver'] ?? null) !== 'local'
            || !is_array($labels)
            || ($labels['duo.cloud.configuration-sha256'] ?? null) !== $this->configurationSha256
            || ($labels['duo.cloud.data-kind'] ?? null) !== $kind
            || ($labels['duo.cloud.lease-generation'] ?? null) !== (string) $manifest['lease_generation']
            || ($labels['duo.cloud.resource-id'] ?? null) !== $manifest['resource_id']
            || ($labels['duo.cloud.reviewed-base-sha256'] ?? null) !== $this->reviewedBaseSha256) {
            throw new ControlRefusal("container runtime cannot prove exact $kind volume ownership");
        }
    }

    /** @param array<string,mixed> $manifest */
    private function assertContainer(array $manifest, ?bool $running): void {
        $seccompProfile = self::approvedPinnedFile(
            $this->seccompProfile,
            $this->seccompProfileSha256,
            'reviewed seccomp profile'
        );
        $seccompProfileBytes = file_get_contents($seccompProfile);
        if (!is_string($seccompProfileBytes)) {
            throw new ControlRefusal('reviewed seccomp profile could not be read');
        }
        $container = $this->containerInspection($manifest['container_name']);
        $config = $container['Config'] ?? null;
        $host = $container['HostConfig'] ?? null;
        $mounts = $container['Mounts'] ?? null;
        $networks = $container['NetworkSettings']['Networks'] ?? null;
        if (!is_array($config) || !is_array($host) || !is_array($mounts) || !is_array($networks)) {
            throw new ControlRefusal('container runtime inspection is missing isolation state');
        }
        $labels = $config['Labels'] ?? null;
        $capDrop = $host['CapDrop'] ?? null;
        $capAdd = $host['CapAdd'] ?? null;
        $devices = $host['Devices'] ?? null;
        $requests = $host['DeviceRequests'] ?? null;
        $portBindings = $host['PortBindings'] ?? null;
        $tmpfs = $host['Tmpfs'] ?? null;
        $logConfig = $host['LogConfig'] ?? null;
        $expectedCommand = [
            'serve',
            '--clean-base',
            '--config-sha256',
            $this->configurationSha256,
            '--lease-generation',
            (string) $manifest['lease_generation'],
            '--reviewed-base-sha256',
            $this->reviewedBaseSha256,
        ];
        $runningValue = $container['State']['Running'] ?? null;
        if (($container['Name'] ?? null) !== '/' . $manifest['container_name']
            || ($config['Image'] ?? null) !== $this->image
            || ($config['User'] ?? null) !== '10001:10001'
            || ($config['Entrypoint'] ?? null) !== ['/opt/duo/bin/duo-preview-runtime']
            || ($config['Cmd'] ?? null) !== $expectedCommand
            || !is_array($labels)
            || ($labels['duo.cloud.configuration-sha256'] ?? null) !== $this->configurationSha256
            || ($labels['duo.cloud.lease-generation'] ?? null) !== (string) $manifest['lease_generation']
            || ($labels['duo.cloud.ownership-receipt-sha256'] ?? null) !== $manifest['ownership_receipt_sha256']
            || ($labels['duo.cloud.resource-id'] ?? null) !== $manifest['resource_id']
            || ($labels['duo.cloud.reviewed-base-sha256'] ?? null) !== $this->reviewedBaseSha256
            || ($host['ReadonlyRootfs'] ?? null) !== true
            || ($host['Privileged'] ?? null) !== false
            || ($host['AutoRemove'] ?? null) !== false
            || !is_array($logConfig)
            || ($logConfig['Type'] ?? null) !== 'none'
            || ($logConfig['Config'] ?? null) !== []
            || !WorkloadSecurityInspection::matches($container, $seccompProfileBytes)
            || $capDrop !== ['ALL']
            || is_array($capAdd) && $capAdd !== []
            || !is_array($devices) && $devices !== null
            || is_array($devices) && $devices !== []
            || !is_array($requests) && $requests !== null
            || is_array($requests) && $requests !== []
            || !is_array($portBindings) && $portBindings !== null
            || is_array($portBindings) && $portBindings !== []
            || !is_array($tmpfs)
            || $tmpfs !== [
                '/run' => 'rw,noexec,nosuid,nodev,size=16777216,mode=0700,uid=10001,gid=10001',
                '/tmp' => 'rw,noexec,nosuid,nodev,size=67108864,mode=0700,uid=10001,gid=10001',
            ]
            || ($host['NetworkMode'] ?? null) !== $manifest['network_name']
            || ($host['Dns'] ?? null) !== [self::DNS_SERVER]
            || ($host['DnsSearch'] ?? null) !== ['.']
            || ($host['DnsOptions'] ?? null) !== ['timeout:1', 'attempts:1']
            || ($host['Memory'] ?? null) !== $this->memoryBytes
            || ($host['MemorySwap'] ?? null) !== $this->memoryBytes
            || ($host['NanoCpus'] ?? null) !== $this->nanoCpus
            || ($host['PidsLimit'] ?? null) !== $this->pidsLimit
            || ($host['ShmSize'] ?? null) !== self::SHM_BYTES
            || ($host['Ulimits'] ?? null) !== [[
                'Hard' => self::NOFILE_LIMIT,
                'Name' => 'nofile',
                'Soft' => self::NOFILE_LIMIT,
            ]]
            || ($host['PidMode'] ?? null) !== ''
            || ($host['IpcMode'] ?? null) !== 'private'
            || ($host['CgroupnsMode'] ?? null) !== 'private'
            || ($host['Runtime'] ?? null) !== 'runc'
            || ($host['MaskedPaths'] ?? null) !== self::MASKED_PATHS
            || ($host['ReadonlyPaths'] ?? null) !== self::READONLY_PATHS
            || count($networks) !== 1
            || !isset($networks[$manifest['network_name']])
            || !is_bool($runningValue)
            || $running !== null && $runningValue !== $running) {
            throw new ControlRefusal('container runtime cannot prove the exact isolated workload configuration');
        }
        $this->assertMounts($manifest, $mounts);
        $environment = $config['Env'] ?? [];
        if (!is_array($environment)) {
            throw new ControlRefusal('container environment inspection is invalid');
        }
        foreach ($environment as $entry) {
            if (!is_string($entry)
                || preg_match(
                    '/(?:PASS|SECRET|TOKEN|CREDENTIAL|AUTH_KEY|AUTH_SALT|DOCKER_HOST|CONTAINER_HOST)=/i',
                    $entry
                ) === 1) {
                throw new ControlRefusal('container runtime cannot prove credential-free environment configuration');
            }
        }
    }

    /** @param array<string,mixed> $manifest @param list<mixed> $mounts */
    private function assertMounts(array $manifest, array $mounts): void {
        $expected = [
            self::DATABASE_TARGET => ['Name' => $manifest['database_volume'], 'RW' => true, 'Type' => 'volume'],
            self::FILESYSTEM_TARGET => ['Name' => $manifest['filesystem_volume'], 'RW' => true, 'Type' => 'volume'],
            self::SECRET_TARGET => [
                'Source' => $manifest['credentials_directory'],
                'RW' => false,
                'Type' => 'bind',
            ],
        ];
        $seen = [];
        foreach ($mounts as $mount) {
            if (!is_array($mount)) {
                throw new ControlRefusal('container mount inspection is invalid');
            }
            $destination = $mount['Destination'] ?? null;
            $source = $mount['Source'] ?? null;
            if (!is_string($destination) || !is_string($source)
                || str_contains(strtolower($destination), 'docker.sock')
                || str_contains(strtolower($source), 'docker.sock')) {
                throw new ControlRefusal('container runtime exposes a forbidden or malformed mount');
            }
            if (!isset($expected[$destination])) {
                throw new ControlRefusal('container runtime has an unapproved persistent mount');
            }
            foreach ($expected[$destination] as $key => $value) {
                if (($mount[$key] ?? null) !== $value) {
                    throw new ControlRefusal('container runtime mount differs from immutable configuration');
                }
            }
            $seen[$destination] = true;
        }
        $seenKeys = array_keys($seen);
        $expectedKeys = array_keys($expected);
        sort($seenKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($seenKeys !== $expectedKeys) {
            throw new ControlRefusal('container runtime does not expose exactly the generation-owned mounts');
        }
    }

    /** @param array<string,mixed> $manifest */
    private function assertRuntimeStatus(array $manifest, ?bool $cleanBase): void {
        $result = $this->mustRun([
            $this->engineBinary,
            'container',
            'exec',
            '--user',
            '10001:10001',
            $manifest['container_name'],
            '/opt/duo/bin/runtime-status',
            '--format',
            self::RUNTIME_STATUS_FORMAT,
            '--config-sha256',
            $this->configurationSha256,
            '--lease-generation',
            (string) $manifest['lease_generation'],
            '--reviewed-base-sha256',
            $this->reviewedBaseSha256,
        ], null, 'workload runtime status');
        $status = $this->canonicalOutput($result['stdout'], 'workload runtime status');
        self::exactKeys(
            $status,
            [
                'clean_base', 'configuration_sha256', 'format', 'lease_generation',
                'ready', 'reviewed_base_sha256',
            ],
            'workload runtime status'
        );
        if (($status['format'] ?? null) !== self::RUNTIME_STATUS_FORMAT
            || ($status['configuration_sha256'] ?? null) !== $this->configurationSha256
            || ($status['lease_generation'] ?? null) !== $manifest['lease_generation']
            || ($status['reviewed_base_sha256'] ?? null) !== $this->reviewedBaseSha256
            || ($status['ready'] ?? null) !== true
            || !is_bool($status['clean_base'] ?? null)
            || $cleanBase !== null && $status['clean_base'] !== $cleanBase) {
            throw new ControlRefusal('workload runtime did not prove exact clean-base readiness');
        }
    }

    /** @param array<string,mixed> $manifest */
    private function assertRoute(array $manifest, bool $bound): void {
        $result = $this->mustRun($this->routeArgv('inspect', $manifest), null, 'route inspection');
        $route = $this->canonicalOutput($result['stdout'], 'route inspection');
        if ($bound) {
            self::exactKeys(
                $route,
                [
                    'configuration_sha256', 'format', 'host', 'reviewed_base_sha256',
                    'route_id', 'state',
                    'upstream_container', 'upstream_network', 'upstream_port',
                ],
                'bound route state'
            );
            if (($route['format'] ?? null) !== self::ROUTE_FORMAT
                || ($route['configuration_sha256'] ?? null) !== $this->configurationSha256
                || ($route['host'] ?? null) !== $manifest['route_host']
                || ($route['reviewed_base_sha256'] ?? null) !== $this->reviewedBaseSha256
                || ($route['route_id'] ?? null) !== $manifest['route_id']
                || ($route['state'] ?? null) !== 'bound'
                || ($route['upstream_container'] ?? null) !== $manifest['container_name']
                || ($route['upstream_network'] ?? null) !== $manifest['network_name']
                || ($route['upstream_port'] ?? null) !== self::WORKLOAD_PORT) {
                throw new ControlRefusal('route authority did not prove the exact credential-free binding');
            }
            return;
        }
        self::exactKeys(
            $route,
            ['configuration_sha256', 'format', 'reviewed_base_sha256', 'route_id', 'state'],
            'absent route state'
        );
        if (($route['format'] ?? null) !== self::ROUTE_FORMAT
            || ($route['configuration_sha256'] ?? null) !== $this->configurationSha256
            || ($route['reviewed_base_sha256'] ?? null) !== $this->reviewedBaseSha256
            || ($route['route_id'] ?? null) !== $manifest['route_id']
            || ($route['state'] ?? null) !== 'absent') {
            throw new ControlRefusal('route authority did not prove terminal route absence');
        }
    }

    /** @param array<string,mixed> $manifest @return non-empty-list<string> */
    private function routeArgv(string $action, array $manifest): array {
        $argv = [
            $this->routerBinary,
            $action,
            '--config-sha256',
            $this->configurationSha256,
            '--route-id',
            $manifest['route_id'],
            '--reviewed-base-sha256',
            $this->reviewedBaseSha256,
        ];
        if ($action === 'bind') {
            return array_merge($argv, [
                '--host',
                $manifest['route_host'],
                '--upstream-container',
                $manifest['container_name'],
                '--upstream-network',
                $manifest['network_name'],
                '--upstream-port',
                (string) self::WORKLOAD_PORT,
            ]);
        }
        if (!in_array($action, ['inspect', 'unbind'], true)) {
            throw new ControlRefusal('route authority action is outside the closed protocol');
        }
        return $argv;
    }

    /** @param array<string,mixed> $manifest @param ?array<string,string> $network @return non-empty-list<string> */
    private function firewallArgv(string $action, array $manifest, ?array $network = null): array {
        $argv = [
            $this->firewallBinary,
            $action,
            '--config-sha256',
            $this->configurationSha256,
            '--network-name',
            $manifest['network_name'],
        ];
        if ($action === 'bind' && $network !== null) {
            return array_merge($argv, [
                '--bridge-name', $network['bridge_name'],
                '--gateway', $network['gateway'],
                '--network-id', $network['network_id'],
                '--subnet', $network['subnet'],
            ]);
        }
        if (!in_array($action, ['inspect', 'unbind'], true) || $network !== null) {
            throw new ControlRefusal('host firewall action is outside the closed protocol');
        }
        return $argv;
    }

    /** @param array<string,mixed> $manifest @return non-empty-list<string> */
    private function storageArgv(string $action, array $manifest): array {
        $argv = [
            $this->storageBinary,
            $action,
            '--config-sha256',
            $this->configurationSha256,
            '--lease-generation',
            (string) $manifest['lease_generation'],
            '--resource-id',
            $manifest['resource_id'],
        ];
        if ($action === 'bind') {
            return array_merge($argv, [
                '--database-volume', $manifest['database_volume'],
                '--filesystem-volume', $manifest['filesystem_volume'],
            ]);
        }
        if (!in_array($action, ['inspect', 'unbind'], true)) {
            throw new ControlRefusal('host storage action is outside the closed protocol');
        }
        return $argv;
    }

    /** @param array<string,mixed> $manifest */
    private function assertStorage(array $manifest, bool $bound): void {
        $result = $this->mustRun(
            $this->storageArgv('inspect', $manifest),
            null,
            'generation storage quota inspection'
        );
        $state = $this->canonicalOutput($result['stdout'], 'generation storage quota inspection');
        if (!$bound) {
            self::exactKeys($state, [
                'configuration_sha256', 'format', 'lease_generation', 'resource_id', 'state',
            ], 'absent storage quota state');
            if (($state['format'] ?? null) !== 'duo-cloud-xfs-quota-storage-state/v1'
                || ($state['configuration_sha256'] ?? null) !== $this->configurationSha256
                || ($state['lease_generation'] ?? null) !== $manifest['lease_generation']
                || ($state['resource_id'] ?? null) !== $manifest['resource_id']
                || ($state['state'] ?? null) !== 'absent') {
                throw new ControlRefusal('storage authority did not prove terminal quota absence');
            }
            return;
        }
        self::exactKeys($state, [
            'configuration_sha256', 'format', 'lease_generation', 'resource_id', 'state', 'volumes',
        ], 'bound storage quota state');
        $volumes = $state['volumes'] ?? null;
        if (($state['format'] ?? null) !== 'duo-cloud-xfs-quota-storage-state/v1'
            || ($state['configuration_sha256'] ?? null) !== $this->configurationSha256
            || ($state['lease_generation'] ?? null) !== $manifest['lease_generation']
            || ($state['resource_id'] ?? null) !== $manifest['resource_id']
            || ($state['state'] ?? null) !== 'bound'
            || !is_array($volumes) || !array_is_list($volumes) || count($volumes) !== 2
            || ($volumes[0]['kind'] ?? null) !== 'database'
            || ($volumes[0]['name'] ?? null) !== $manifest['database_volume']
            || ($volumes[1]['kind'] ?? null) !== 'filesystem'
            || ($volumes[1]['name'] ?? null) !== $manifest['filesystem_volume']) {
            throw new ControlRefusal('storage authority differs from the generation-owned volume tuple');
        }
    }

    /** @param array<string,mixed> $manifest @return array{bridge_name:string,gateway:string,network_id:string,subnet:string} */
    private function networkAuthority(array $manifest): array {
        $network = $this->inspectObject('network', $manifest['network_name']);
        $ipam = $network['IPAM']['Config'] ?? null;
        if (!is_string($network['Id'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $network['Id']) !== 1
            || !is_array($ipam) || !array_is_list($ipam) || count($ipam) !== 1
            || !is_array($ipam[0]) || !is_string($ipam[0]['Subnet'] ?? null)
            || !is_string($ipam[0]['Gateway'] ?? null)) {
            throw new ControlRefusal('container runtime cannot derive exact IPv4 firewall authority');
        }
        return [
            'bridge_name' => self::bridgeName($manifest['network_name']),
            'gateway' => $ipam[0]['Gateway'],
            'network_id' => $network['Id'],
            'subnet' => $ipam[0]['Subnet'],
        ];
    }

    /** @param array<string,mixed> $manifest @param ?array<string,string> $network */
    private function assertFirewall(array $manifest, bool $bound, ?array $network = null): void {
        $result = $this->mustRun(
            $this->firewallArgv('inspect', $manifest),
            null,
            'host firewall inspection'
        );
        $state = $this->canonicalOutput($result['stdout'], 'host firewall inspection');
        if (!$bound) {
            self::exactKeys(
                $state,
                ['configuration_sha256', 'format', 'network_name', 'state'],
                'absent host firewall state'
            );
            if (($state['format'] ?? null) !== 'duo-cloud-host-firewall-state/v1'
                || ($state['configuration_sha256'] ?? null) !== $this->configurationSha256
                || ($state['network_name'] ?? null) !== $manifest['network_name']
                || ($state['state'] ?? null) !== 'absent') {
                throw new ControlRefusal('host firewall authority did not prove terminal absence');
            }
            return;
        }
        $network ??= $this->networkAuthority($manifest);
        self::exactKeys($state, [
            'bridge_name', 'configuration_sha256', 'format', 'gateway', 'network_id',
            'network_name', 'state', 'subnet',
        ], 'bound host firewall state');
        if (($state['format'] ?? null) !== 'duo-cloud-host-firewall-state/v1'
            || ($state['state'] ?? null) !== 'bound'
            || ($state['configuration_sha256'] ?? null) !== $this->configurationSha256
            || ($state['network_name'] ?? null) !== $manifest['network_name']) {
            throw new ControlRefusal('host firewall authority returned foreign binding authority');
        }
        foreach ($network as $field => $value) {
            if (($state[$field] ?? null) !== $value) {
                throw new ControlRefusal('host firewall authority differs from exact Docker network readback');
            }
        }
    }

    /** @param array<string,mixed> $manifest */
    private function stage(
        array $manifest,
        string $stage,
        array $arguments,
        ?string $stdinFile = null
    ): void {
        $argv = array_merge([
            $this->engineBinary,
            'container',
            'exec',
            '--interactive',
            '--user',
            '10001:10001',
            $manifest['container_name'],
            '/opt/duo/bin/' . $stage,
            '--format',
            self::STAGE_FORMAT,
            '--config-sha256',
            $this->configurationSha256,
            '--lease-generation',
            (string) $manifest['lease_generation'],
            '--reviewed-base-sha256',
            $this->reviewedBaseSha256,
        ], $arguments);
        $result = $this->mustRun($argv, $stdinFile, "workload $stage");
        $output = $this->canonicalOutput($result['stdout'], "workload $stage");
        self::exactKeys(
            $output,
            ['configuration_sha256', 'format', 'reviewed_base_sha256', 'stage', 'state'],
            "workload $stage"
        );
        if (($output['format'] ?? null) !== self::STAGE_FORMAT
            || ($output['configuration_sha256'] ?? null) !== $this->configurationSha256
            || ($output['reviewed_base_sha256'] ?? null) !== $this->reviewedBaseSha256
            || ($output['stage'] ?? null) !== $stage
            || ($output['state'] ?? null) !== 'complete') {
            throw new ControlRefusal("workload $stage did not prove exact completion");
        }
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private function requireHeldManifest(array $identity, bool $mustRun = true): array {
        $manifest = $this->readManifest($identity, false);
        if ($manifest === null) {
            throw new ControlRefusal('preview generation has no runtime authority');
        }
        $this->assertManifestLease($manifest, $identity);
        $this->adoptMutation($manifest, $identity);
        if ($mustRun) {
            $this->assertPresent($manifest);
        }
        $this->writeManifest($identity, $manifest);
        return $manifest;
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $identity */
    private function adoptMutation(array &$manifest, array $identity): void {
        $next = [
            'generation' => $identity['mutation_generation'],
            'id' => $identity['mutation_id'],
            'owner' => $identity['mutation_owner'],
            'receipt_sha256' => $identity['mutation_receipt_sha256'],
        ];
        $current = $manifest['last_mutation'];
        if ($current === null) {
            $manifest['last_mutation'] = $next;
            return;
        }
        if (!is_array($current) || !is_int($current['generation'] ?? null)) {
            throw new ControlRefusal('runtime mutation lineage is corrupt');
        }
        if ($identity['mutation_generation'] < $current['generation']) {
            throw new ControlRefusal('runtime refused a stale mutation generation');
        }
        if ($identity['mutation_generation'] === $current['generation']) {
            if (CanonicalJson::encode($current) !== CanonicalJson::encode($next)) {
                throw new ControlRefusal('runtime mutation generation was replayed with a foreign fence tuple');
            }
            return;
        }
        $manifest['last_mutation'] = $next;
    }

    /** @param array<string,mixed> $manifest */
    private function assertPresent(array $manifest): void {
        if ($manifest['state'] !== 'present' || $manifest['execution_state'] !== 'running') {
            throw new ControlRefusal('preview generation is not in exact running runtime state');
        }
        $this->assertCredentials($manifest);
        $this->assertNetwork($manifest);
        $this->assertFirewall($manifest, true);
        $this->assertVolume($manifest, 'database');
        $this->assertVolume($manifest, 'filesystem');
        $this->assertStorage($manifest, true);
        $this->assertContainer($manifest, true);
        $this->assertRoute($manifest, $manifest['route_state'] === 'bound');
    }

    /** @param array<string,mixed> $identity */
    private function physicalAbsence(array $identity, bool $requireGenerationAbsence = true): bool {
        $absent = !$this->namedResourcePresent('container', $identity['container_name'])
            && !$this->namedResourcePresent('volume', $identity['database_volume'])
            && !$this->namedResourcePresent('volume', $identity['filesystem_volume'])
            && !$this->namedResourcePresent('network', $identity['network_name']);
        $this->assertRoute([
            'route_id' => $identity['route_id'],
        ], false);
        $this->assertFirewall([
            'network_name' => $identity['network_name'],
        ], false);
        $this->assertStorage([
            'database_volume' => $identity['database_volume'],
            'filesystem_volume' => $identity['filesystem_volume'],
            'lease_generation' => $identity['lease_generation'],
            'resource_id' => $identity['resource_id'],
        ], false);
        if (is_dir($identity['credentials_directory']) || is_link($identity['credentials_directory'])) {
            $absent = false;
        }
        $generation = dirname($identity['credentials_directory']);
        if ($requireGenerationAbsence && (file_exists($generation) || is_link($generation))) {
            $absent = false;
        }
        return $absent;
    }

    private function namedResourcePresent(string $kind, string $name): bool {
        $argv = match ($kind) {
            'container' => [
                $this->engineBinary,
                'container',
                'ls',
                '--all',
                '--filter',
                'name=^/' . $name . '$',
                '--format',
                '{{.Names}}',
            ],
            'network' => [
                $this->engineBinary,
                'network',
                'ls',
                '--filter',
                'name=^' . $name . '$',
                '--format',
                '{{.Name}}',
            ],
            'volume' => [
                $this->engineBinary,
                'volume',
                'ls',
                '--filter',
                'name=^' . $name . '$',
                '--format',
                '{{.Name}}',
            ],
            default => throw new ControlRefusal('container resource kind is outside the closed runtime'),
        };
        $result = $this->mustRun($argv, null, "container $kind presence query");
        $output = trim($result['stdout']);
        if ($output === '') {
            return false;
        }
        if ($output !== $name) {
            throw new ControlRefusal("container $kind presence query returned ambiguous state");
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function inspectObject(string $kind, string $name): array {
        $result = $this->mustRun([
            $this->engineBinary,
            $kind,
            'inspect',
            '--format',
            '{{json .}}',
            $name,
        ], null, "container $kind inspection");
        try {
            $decoded = json_decode(trim($result['stdout']), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new ControlRefusal("container $kind inspection was not JSON", 0, $error);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ControlRefusal("container $kind inspection did not return an object");
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function containerInspection(string $name): array {
        return $this->inspectObject('container', $name);
    }

    private function snapshotObject(string $hash): string {
        $path = $this->snapshotObjectRoot . '/' . $hash;
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new ControlRefusal('snapshot content-addressed object is unavailable');
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($hash, $actual)) {
            throw new ControlRefusal('snapshot content-addressed object differs from its approved hash');
        }
        return $path;
    }

    private function assertApprovedGitObjectSource(string $commit, string $ref): void {
        $common = $this->mustRun([
            $this->gitBinary,
            '-C',
            $this->repositorySource,
            '-c',
            'core.hooksPath=/dev/null',
            'rev-parse',
            '--path-format=absolute',
            '--git-common-dir',
        ], null, 'local Git common directory');
        $commonPath = realpath(trim($common['stdout']));
        if (!is_string($commonPath) || !self::inside($commonPath, $this->repositorySource)) {
            throw new ControlRefusal('Git object database escapes the approved local source');
        }
        $this->mustRun([
            $this->gitBinary,
            '-C',
            $this->repositorySource,
            '-c',
            'core.hooksPath=/dev/null',
            'cat-file',
            '-e',
            $commit . '^{commit}',
        ], null, 'local Git commit verification');
        $resolved = $this->mustRun([
            $this->gitBinary,
            '-C',
            $this->repositorySource,
            '-c',
            'core.hooksPath=/dev/null',
            'rev-parse',
            '--verify',
            $ref . '^{commit}',
        ], null, 'local Git branch verification');
        if (trim($resolved['stdout']) !== $commit) {
            throw new ControlRefusal('approved local branch does not resolve to the requested immutable commit');
        }
    }

    /** @param array<string,mixed> $identity */
    private function assertOperationRepositoryRef(array $identity, string $ref): void {
        $expected = $this->repositoryRefPrefix . $identity['operation_id'];
        if (!hash_equals($expected, $ref)) {
            throw new ControlRefusal('repository ref is not the deterministic lifecycle operation ref');
        }
    }

    /** @param array<string,mixed> $identity */
    private function repositorySyncLocalRef(array $identity, string $remoteRef): string {
        return 'refs/duo/cloud-preview-sync/' . hash(
            'sha256',
            "duo-cloud-repository-sync-ref/v1\0{$identity['tenant_id']}\0{$identity['site_id']}\0"
                . "{$identity['operation_id']}\0$remoteRef"
        );
    }

    private function assertRepositoryRemoteReadback(): void {
        $readback = $this->mustRun([
            $this->gitBinary,
            '-C',
            $this->repositorySource,
            '-c',
            'core.hooksPath=/dev/null',
            'remote',
            'get-url',
            '--all',
            $this->repositoryRemoteName,
        ], null, 'repository remote authority readback');
        if ($readback['stdout'] !== $this->repositoryRemoteUrl . "\n") {
            throw new ControlRefusal('repository remote URL differs from configured authority');
        }
        $actual = hash(
            'sha256',
            "duo-cloud-repository-remote-url/v1\0" . substr($readback['stdout'], 0, -1)
        );
        if (!hash_equals($this->repositoryRemoteUrlSha256, $actual)) {
            throw new ControlRefusal('repository remote URL readback does not match its pinned digest');
        }
    }

    private function assertRepositoryCredentialHelperReadback(): void {
        self::approvedCredentialHelper(
            $this->repositoryCredentialHelper,
            $this->repositoryCredentialHelperSha256
        );
    }

    /** @return list<string> */
    private function repositoryRemoteGitConfiguration(): array {
        return [
            '-c', 'core.hooksPath=/dev/null',
            '-c', 'protocol.file.allow=never',
            '-c', 'credential.helper=',
            '-c', 'credential.' . $this->repositoryRemoteUrl . '.helper='
                . $this->repositoryCredentialHelper,
            '-c', 'credential.interactive=never',
            '-c', 'credential.useHttpPath=true',
        ];
    }

    /** @param array<string,mixed> $identity @param array<string,mixed> $manifest */
    private function deleteRepositorySync(array $identity, array &$manifest): void {
        $record = $manifest['repository_sync'];
        if ($record === null) {
            return;
        }
        if (!is_array($record)) {
            throw new ControlRefusal('generation repository sync authority is malformed');
        }
        $localRef = (string) $record['local_ref'];
        $commit = (string) $record['branch_commit'];
        $resolved = $this->repositoryRefCommit($localRef);
        if ($resolved !== null && !hash_equals($commit, $resolved)) {
            throw new ControlRefusal('generation repository sync ref changed before deletion');
        }
        if (($record['state'] ?? null) === 'present') {
            $record['state'] = 'deleting';
            $manifest['repository_sync'] = $record;
            $this->writeManifest($identity, $manifest);
        } elseif (($record['state'] ?? null) !== 'deleting') {
            throw new ControlRefusal('generation repository sync deletion phase is invalid');
        }
        if ($resolved !== null) {
            $this->mustRun([
                $this->gitBinary,
                '-C',
                $this->repositorySource,
                '-c',
                'core.hooksPath=/dev/null',
                'update-ref',
                '-d',
                $localRef,
                $commit,
            ], null, 'generation repository sync ref deletion');
        }
        if ($this->repositoryRefCommit($localRef) !== null) {
            throw new ControlRefusal('generation repository sync ref remains after deletion');
        }
        $manifest['repository_sync'] = null;
        $this->writeManifest($identity, $manifest);
    }

    private function repositoryRefCommit(string $ref): ?string {
        try {
            $result = $this->runner->run([
                $this->gitBinary,
                '-C',
                $this->repositorySource,
                '-c',
                'core.hooksPath=/dev/null',
                'rev-parse',
                '--verify',
                '--quiet',
                $ref . '^{commit}',
            ]);
        } catch (\Throwable $error) {
            throw new ControlRefusal('repository sync ref readback outcome is indeterminate', 0, $error);
        }
        self::exactKeys($result, ['exit', 'stderr', 'stdout'], 'repository sync ref readback process result');
        if (($result['exit'] ?? null) === 1
            && ($result['stderr'] ?? null) === ''
            && ($result['stdout'] ?? null) === '') {
            return null;
        }
        if (($result['exit'] ?? null) !== 0
            || ($result['stderr'] ?? null) !== ''
            || !is_string($result['stdout'] ?? null)
            || preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?\n$/D', $result['stdout']) !== 1) {
            throw new ControlRefusal('repository sync ref readback did not prove exact presence or absence');
        }
        return substr($result['stdout'], 0, -1);
    }

    /** @param array<string,mixed> $manifest */
    private function archivePath(array $manifest, string $commit): string {
        $directory = dirname($manifest['credentials_directory']);
        self::privateDirectory($directory, 'generation state directory');
        $path = $directory . '/repository-' . $commit . '.tar';
        if (file_exists($path) || is_link($path)) {
            if (!is_file($path) || is_link($path) || !@unlink($path)) {
                throw new ControlRefusal('stale repository archive cannot be cleared safely');
            }
        }
        return $path;
    }

    private static function secureArchive(string $path): void {
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new ControlRefusal('local Git archive was not created as a regular file');
        }
        if (($stat['size'] ?? 0) < 1 || ($stat['size'] ?? PHP_INT_MAX) > 1073741824) {
            throw new ControlRefusal('local Git archive is empty or exceeds the 1GiB runtime limit');
        }
        if (!chmod($path, 0600)) {
            throw new ControlRefusal('local Git archive permissions could not be restricted');
        }
    }

    /**
     * @param non-empty-list<string> $argv
     * @return array{exit:int,stderr:string,stdout:string}
     */
    private function mustRun(array $argv, ?string $stdinFile, string $stage): array {
        try {
            $result = $this->runner->run($argv, $stdinFile);
        } catch (\Throwable $error) {
            throw new ControlRefusal("$stage outcome is indeterminate", 0, $error);
        }
        self::exactKeys($result, ['exit', 'stderr', 'stdout'], "$stage process result");
        if (!is_int($result['exit'] ?? null)
            || !is_string($result['stderr'] ?? null)
            || !is_string($result['stdout'] ?? null)) {
            throw new ControlRefusal("$stage process result is invalid");
        }
        if ($result['exit'] !== 0) {
            // Child output is deliberately omitted: engine and workload errors
            // can contain environment values or application content.
            throw new ControlRefusal("$stage failed with a non-zero exit");
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function canonicalOutput(string $bytes, string $label): array {
        try {
            return CanonicalJson::decodeObject($bytes, 1048576);
        } catch (\Throwable $error) {
            throw new ControlRefusal("$label did not return canonical evidence", 0, $error);
        }
    }

    /** @param array<string,mixed> $identity @return ?array<string,mixed> */
    private function readManifest(array $identity, bool $allowAbsent): ?array {
        $path = $this->manifestPath($identity);
        $this->reconcileManifestTemporary($path);
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }
        if (is_link($path) || !is_file($path)) {
            throw new ControlRefusal('runtime authority manifest is not a regular file');
        }
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) {
            throw new ControlRefusal('runtime authority manifest could not be read');
        }
        $manifest = CanonicalJson::decodeObject($bytes, 65536);
        $this->assertManifest($manifest);
        if (!$allowAbsent) {
            $this->assertManifestLease($manifest, $identity);
        }
        return $manifest;
    }

    /** @param array<string,mixed> $identity @param array<string,mixed> $manifest */
    private function writeManifest(array $identity, array $manifest): void {
        $this->assertManifest($manifest);
        $this->assertManifestLease($manifest, $identity);
        $path = $this->manifestPath($identity);
        $this->reconcileManifestTemporary($path);
        $temporary = $path . '.tmp';
        $bytes = CanonicalJson::encode($manifest) . "\n";
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($temporary, 'x+b');
        } finally {
            umask($previousUmask);
        }
        if (!is_resource($handle)) {
            throw new ControlRefusal('runtime authority temporary manifest could not be created');
        }
        try {
            $remaining = $bytes;
            if (!chmod($temporary, 0600)) {
                throw new ControlRefusal('runtime authority manifest could not be protected privately');
            }
            self::assertOpenedPrivateManifest($handle, $temporary, 'runtime authority temporary manifest');
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if (!is_int($written) || $written < 1) {
                    throw new ControlRefusal('runtime authority manifest could not be written completely');
                }
                $remaining = substr($remaining, $written);
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new ControlRefusal('runtime authority manifest could not be persisted privately');
            }
        } finally {
            fclose($handle);
        }
        if (!@rename($temporary, $path)) {
            throw new ControlRefusal('runtime authority manifest could not be published atomically');
        }
        $this->syncDirectory(dirname($path), 'runtime authority manifest directory');
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $readback = @file_get_contents($path);
        if (!is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0100000
            || ($stat['mode'] & 0077) !== 0
            || !is_string($readback) || !hash_equals(hash('sha256', $bytes), hash('sha256', $readback))) {
            throw new ControlRefusal('runtime authority manifest durable readback differs after publish');
        }
        self::privateFile($path, 'runtime authority manifest durable readback');
    }

    private function reconcileManifestTemporary(string $path): void {
        $temporary = $path . '.tmp';
        clearstatcache(true, $temporary);
        $before = @lstat($temporary);
        if ($before === false) {
            return;
        }
        self::assertPrivateManifest($temporary, 'runtime authority stale temporary manifest');
        if ((int) ($before['size'] ?? -1) < 0 || (int) $before['size'] > 65536) {
            throw new ControlRefusal('runtime authority stale temporary manifest has an invalid size');
        }
        $handle = @fopen($temporary, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal('runtime authority stale temporary manifest could not be opened');
        }
        try {
            self::assertOpenedPrivateManifest(
                $handle,
                $temporary,
                'runtime authority stale temporary manifest'
            );
            $opened = fstat($handle);
            clearstatcache(true, $temporary);
            $after = @lstat($temporary);
            if (!is_array($opened) || !is_array($after)
                || !self::sameManifestFile($before, $opened)
                || !self::sameManifestFile($before, $after)
                || !@unlink($temporary)) {
                throw new ControlRefusal('runtime authority stale temporary manifest changed during recovery');
            }
            $unlinked = fstat($handle);
            clearstatcache(true, $temporary);
            if (!is_array($unlinked) || @lstat($temporary) !== false
                || (int) $unlinked['dev'] !== (int) $opened['dev']
                || (int) $unlinked['ino'] !== (int) $opened['ino']
                || (int) ($unlinked['nlink'] ?? -1) !== 0) {
                throw new ControlRefusal(
                    'runtime authority stale temporary manifest changed while being removed'
                );
            }
        } finally {
            fclose($handle);
        }
        $this->syncDirectory(dirname($path), 'runtime authority manifest directory');
    }

    private static function assertPrivateManifest(string $path, string $label): void {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path)
            || ((int) $stat['mode'] & 0170000) !== 0100000
            || (int) ($stat['nlink'] ?? 0) !== 1
            || (DIRECTORY_SEPARATOR === '/' && ((int) $stat['mode'] & 0777) !== 0600)
            || (function_exists('posix_geteuid') && (int) $stat['uid'] !== posix_geteuid())) {
            throw new ControlRefusal("$label must be a process-owned single-link mode-0600 file");
        }
    }

    /** @param resource $handle */
    private static function assertOpenedPrivateManifest($handle, string $path, string $label): void {
        clearstatcache(true, $path);
        $named = @lstat($path);
        $opened = fstat($handle);
        if (!is_array($named) || !is_array($opened)
            || !self::sameManifestFile($named, $opened)) {
            throw new ControlRefusal("$label changed while opening");
        }
        self::assertPrivateManifest($path, $label);
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function sameManifestFile(array $left, array $right): bool {
        return (int) $left['dev'] === (int) $right['dev']
            && (int) $left['ino'] === (int) $right['ino']
            && (int) $left['mode'] === (int) $right['mode']
            && (int) $left['uid'] === (int) $right['uid']
            && (int) ($left['nlink'] ?? 0) === (int) ($right['nlink'] ?? 0)
            && (int) $left['size'] === (int) $right['size'];
    }

    private function syncDirectory(string $path, string $label): void {
        if (!function_exists('fsync')) {
            return;
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle) || !@fsync($handle) || !fclose($handle)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new ControlRefusal("$label could not be synchronized");
        }
    }

    /** @param array<string,mixed> $manifest */
    private function assertManifest(array $manifest): void {
        self::exactKeys($manifest, [
            'configuration_sha256', 'container_name', 'credential_fingerprints',
            'credentials_directory', 'database_volume', 'environment_identity',
            'execution_state', 'filesystem_volume', 'format', 'image', 'last_mutation',
            'last_repository_sha256', 'last_snapshot_sha256', 'lease_generation', 'lease_id',
            'network_name', 'ownership_receipt_sha256', 'resource_id', 'route_host',
            'repository_sync', 'route_id', 'route_state', 'reviewed_base_sha256', 'site_id', 'state',
            'tenant_id', 'url',
        ], 'container runtime manifest');
        if (($manifest['format'] ?? null) !== self::MANIFEST_FORMAT
            || ($manifest['configuration_sha256'] ?? null) !== $this->configurationSha256
            || ($manifest['image'] ?? null) !== $this->image
            || ($manifest['reviewed_base_sha256'] ?? null) !== $this->reviewedBaseSha256
            || !in_array(
                $manifest['state'] ?? null,
                ['deleted', 'deleting-credentials', 'present', 'provisioning'],
                true
            )
            || !in_array($manifest['execution_state'] ?? null, ['preparing', 'revoked', 'running'], true)
            || !in_array($manifest['route_state'] ?? null, ['absent', 'bound'], true)) {
            throw new ControlRefusal('container runtime manifest format or immutable configuration differs');
        }
        self::identifier($manifest['tenant_id'] ?? null, 'manifest tenant id');
        self::identifier($manifest['site_id'] ?? null, 'manifest site id');
        self::identifier($manifest['environment_identity'] ?? null, 'manifest environment identity');
        self::identifier($manifest['resource_id'] ?? null, 'manifest resource id');
        self::identifier($manifest['lease_id'] ?? null, 'manifest lease id');
        self::positiveInt($manifest['lease_generation'] ?? null, 'manifest lease generation');
        self::sha256($manifest['ownership_receipt_sha256'] ?? null, 'manifest ownership receipt');
        foreach (['last_repository_sha256', 'last_snapshot_sha256'] as $field) {
            if ($manifest[$field] !== null) {
                self::sha256($manifest[$field], "manifest $field");
            }
        }
        $repositorySync = $manifest['repository_sync'];
        if ($repositorySync !== null) {
            if (!is_array($repositorySync) || array_is_list($repositorySync)) {
                throw new ControlRefusal('manifest repository sync record is malformed');
            }
            self::exactKeys($repositorySync, [
                'branch_commit', 'branch_ref', 'candidate_publication_receipt_sha256',
                'local_ref', 'repository_authority_sha256', 'state',
            ], 'manifest repository sync record');
            self::gitOid($repositorySync['branch_commit'] ?? null);
            self::gitRef($repositorySync['branch_ref'] ?? null);
            self::sha256(
                $repositorySync['candidate_publication_receipt_sha256'] ?? null,
                'manifest candidate publication receipt'
            );
            self::sha256(
                $repositorySync['repository_authority_sha256'] ?? null,
                'manifest repository authority'
            );
            if (!is_string($repositorySync['local_ref'] ?? null)
                || preg_match('#^refs/duo/cloud-preview-sync/[a-f0-9]{64}$#D', $repositorySync['local_ref']) !== 1
                || !in_array($repositorySync['state'] ?? null, ['deleting', 'present'], true)) {
                throw new ControlRefusal('manifest repository sync record is invalid');
            }
        }
        if ($manifest['credential_fingerprints'] !== null && !is_array($manifest['credential_fingerprints'])) {
            throw new ControlRefusal('container runtime credential fingerprints are invalid');
        }
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $identity */
    private function assertManifestLease(array $manifest, array $identity): void {
        foreach ([
            'configuration_sha256', 'container_name', 'credentials_directory', 'database_volume',
            'environment_identity', 'filesystem_volume', 'lease_generation', 'lease_id',
            'network_name', 'ownership_receipt_sha256', 'resource_id', 'route_host', 'route_id',
            'reviewed_base_sha256', 'site_id', 'tenant_id', 'url',
        ] as $field) {
            $expected = match ($field) {
                'configuration_sha256' => $this->configurationSha256,
                'reviewed_base_sha256' => $this->reviewedBaseSha256,
                default => $identity[$field],
            };
            if (($manifest[$field] ?? null) !== $expected) {
                throw new ControlRefusal("runtime authority is stale or foreign at '$field'");
            }
        }
    }

    /** @param array<string,mixed> $lease @return array<string,mixed> */
    private function identity(array $lease, bool $mutation): array {
        $keys = [
            'environment_identity', 'lease_generation', 'lease_id', 'operation_id',
            'ownership_receipt_sha256', 'resource_id', 'site_id', 'tenant_id',
        ];
        if ($mutation) {
            $keys = array_merge($keys, [
                'mutation_generation', 'mutation_id', 'mutation_owner', 'mutation_receipt_sha256',
            ]);
        }
        self::exactKeys($lease, $keys, $mutation ? 'runtime mutation authority' : 'runtime lease');
        $tenantId = self::identifier($lease['tenant_id'] ?? null, 'tenant id');
        $siteId = self::identifier($lease['site_id'] ?? null, 'site id');
        $environmentIdentity = self::identifier(
            $lease['environment_identity'] ?? null,
            'environment identity'
        );
        $resourceId = self::identifier($lease['resource_id'] ?? null, 'resource id');
        $leaseId = self::identifier($lease['lease_id'] ?? null, 'lease id');
        $generation = self::positiveInt($lease['lease_generation'] ?? null, 'lease generation');
        $ownership = self::sha256($lease['ownership_receipt_sha256'] ?? null, 'ownership receipt');
        self::operationId($lease['operation_id'] ?? null);
        $expectedResource = 'cloud-slot-' . hash(
            'sha256',
            "duo-cloud-preview-physical-slot/v1\0$tenantId\0$siteId"
        );
        if ($resourceId !== $expectedResource) {
            throw new ControlRefusal('runtime resource does not belong to the named tenant/site');
        }
        $token = substr($resourceId, strlen('cloud-slot-'));
        $suffix = '-g' . str_pad((string) $generation, 10, '0', STR_PAD_LEFT);
        $routeHost = 'p-' . substr($token, 0, 40) . '.' . $this->previewDomain;
        $identity = [
            'configuration_sha256' => $this->configurationSha256,
            'container_name' => 'duo-preview-' . $token . $suffix,
            'credentials_directory' => $this->slotDirectory($resourceId)
                . '/generations/' . str_pad((string) $generation, 10, '0', STR_PAD_LEFT) . '/credentials',
            'database_volume' => 'duo-preview-db-' . $token . $suffix,
            'environment_identity' => $environmentIdentity,
            'filesystem_volume' => 'duo-preview-fs-' . $token . $suffix,
            'lease_generation' => $generation,
            'lease_id' => $leaseId,
            'network_name' => 'duo-preview-net-' . $token . $suffix,
            'operation_id' => $lease['operation_id'],
            'ownership_receipt_sha256' => $ownership,
            'resource_id' => $resourceId,
            'route_host' => $routeHost,
            'route_id' => 'duo-preview-route-' . $token,
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
            'url' => 'https://' . $routeHost,
        ];
        if ($mutation) {
            $identity += [
                'mutation_generation' => self::positiveInt(
                    $lease['mutation_generation'] ?? null,
                    'mutation generation'
                ),
                'mutation_id' => self::identifier($lease['mutation_id'] ?? null, 'mutation id'),
                'mutation_owner' => self::mutationOwner($lease['mutation_owner'] ?? null),
                'mutation_receipt_sha256' => self::sha256(
                    $lease['mutation_receipt_sha256'] ?? null,
                    'mutation receipt'
                ),
            ];
        }
        return $identity;
    }

    /** @param array<string,mixed> $identity */
    private function locked(array $identity, callable $callback): mixed {
        $slot = $this->slotDirectory($identity['resource_id']);
        if (!is_dir($slot)) {
            if (!mkdir($slot, 0700, true) || !chmod($slot, 0700)) {
                throw new ControlRefusal('runtime slot directory could not be created privately');
            }
        }
        self::privateDirectory($slot, 'runtime slot directory');
        return RuntimeSlotLock::exclusive($slot, $callback);
    }

    /** @param array<string,mixed> $identity */
    private function manifestPath(array $identity): string {
        return $this->slotDirectory($identity['resource_id']) . '/active.json';
    }

    private function slotDirectory(string $resourceId): string {
        return $this->stateRoot . '/slots/' . substr($resourceId, strlen('cloud-slot-'));
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private function publicManifest(array $manifest): array {
        return [
            'configuration_sha256' => $manifest['configuration_sha256'],
            'container_name' => $manifest['container_name'],
            'environment_identity' => $manifest['environment_identity'],
            'execution_state' => $manifest['execution_state'],
            'image' => $manifest['image'],
            'lease_generation' => $manifest['lease_generation'],
            'network_name' => $manifest['network_name'],
            'resource_id' => $manifest['resource_id'],
            'route_id' => $manifest['route_id'],
            'route_state' => $manifest['route_state'],
            'url' => $manifest['url'],
        ];
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private function publicAuthority(array $identity): array {
        return [
            'lease_generation' => $identity['lease_generation'],
            'mutation_generation' => $identity['mutation_generation'],
            'mutation_id' => $identity['mutation_id'],
            'resource_id' => $identity['resource_id'],
            'site_id' => $identity['site_id'],
            'tenant_id' => $identity['tenant_id'],
        ];
    }

    private function evidence(string $domain, mixed $value): string {
        return hash(
            'sha256',
            "duo-cloud-container-workload-$domain/v1\0" . CanonicalJson::encode([
                'reviewed_base_sha256' => $this->reviewedBaseSha256,
                'value' => $value,
            ])
        );
    }

    /** @return array<string,mixed> */
    private function reviewedBaseContainmentBasis(): array {
        return [
            'egress_evidence' => 'host-nft-input-forward-default-deny-readback/v1',
            'format' => self::REVIEWED_CONTAINMENT_FORMAT,
            'image_reference' => $this->image,
            'reviewed_base' => $this->reviewedBaseDescriptor(),
            'routing_evidence' => 'credential-free-route-authority-readback/v1',
            'runtime_configuration_sha256' => $this->configurationSha256,
            'seccomp_profile_sha256' => $this->seccompProfileSha256,
            'secrets_evidence' => 'generation-private-files-readonly-mount-readback/v1',
            'storage_evidence' => 'dm-crypt-xfs-project-quota-exact-readback/v1',
        ];
    }

    /** @param array<string,mixed> $object @param list<string> $keys */
    private static function exactKeys(array $object, array $keys, string $label): void {
        $actual = array_keys($object);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw new ControlRefusal("$label has unexpected fields");
        }
    }

    private static function absoluteExecutable(string $path, string $label): string {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")
            || preg_match('#(?:^|/)\.\.?(/|$)#D', $path) === 1) {
            throw new ControlRefusal("$label executable path is invalid");
        }
        return $path;
    }

    private static function absolutePath(string $path, string $label): string {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")
            || str_contains($path, '//')
            || preg_match('#(?:^|/)\.\.?(/|$)#D', $path) === 1
            || str_ends_with($path, '/')) {
            throw new ControlRefusal("$label path is invalid");
        }
        return $path;
    }

    private static function bridgeName(string $networkName): string {
        return 'duo' . substr(hash('sha256', $networkName), 0, 12);
    }

    private static function approvedCredentialHelper(string $path, string $expectedSha256): string {
        self::absoluteExecutable($path, 'repository credential helper');
        $stat = @lstat($path);
        $real = realpath($path);
        if (!is_array($stat)
            || !is_string($real)
            || is_link($path)
            || !is_file($path)
            || !is_executable($path)
            || ($stat['mode'] & 0022) !== 0) {
            throw new ControlRefusal(
                'repository credential helper must be a canonical protected executable file'
            );
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($expectedSha256, $actual)) {
            throw new ControlRefusal('repository credential helper differs from its pinned digest');
        }
        return $real;
    }

    private static function approvedPinnedFile(string $path, string $expectedSha256, string $label): string {
        self::absoluteExecutable($path, $label);
        $stat = @lstat($path);
        $real = realpath($path);
        if (!is_array($stat) || !is_string($real) || is_link($path) || !is_file($path)
            || ($stat['mode'] & 0022) !== 0 || !is_readable($path)) {
            throw new ControlRefusal("$label must be a canonical protected regular file");
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($expectedSha256, $actual)) {
            throw new ControlRefusal("$label differs from its pinned digest");
        }
        return $real;
    }

    private static function privateStateRoot(string $path): string {
        if (!file_exists($path)) {
            if (!mkdir($path, 0700, true) || !chmod($path, 0700)) {
                throw new ControlRefusal('container runtime state root could not be created privately');
            }
        }
        $real = realpath($path);
        if (!is_string($real) || $real === '/' || is_link($path)) {
            throw new ControlRefusal('container runtime state root is not a canonical directory');
        }
        self::privateDirectory($real, 'container runtime state root');
        $slots = $real . '/slots';
        if (!is_dir($slots) && (!mkdir($slots, 0700) || !chmod($slots, 0700))) {
            throw new ControlRefusal('container runtime slot root could not be created privately');
        }
        self::privateDirectory($slots, 'container runtime slot root');
        return $real;
    }

    private static function approvedDirectory(string $path, string $label): string {
        $real = realpath($path);
        if (!is_string($real) || $real === '/' || is_link($path) || !is_dir($real) || !is_readable($real)) {
            throw new ControlRefusal("approved $label is not a canonical readable directory");
        }
        return $real;
    }

    private static function privateDirectory(string $path, string $label): void {
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || !is_dir($path) || (($stat['mode'] ?? 0) & 0077) !== 0) {
            throw new ControlRefusal("$label is not a private process-owned directory");
        }
        if (function_exists('posix_geteuid') && ($stat['uid'] ?? -1) !== posix_geteuid()) {
            throw new ControlRefusal("$label is not a private process-owned directory");
        }
    }

    private static function privateFile(string $path, string $label): void {
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || !is_file($path) || (($stat['mode'] ?? 0) & 0077) !== 0) {
            throw new ControlRefusal("$label is not a private process-owned regular file");
        }
        if (function_exists('posix_geteuid') && ($stat['uid'] ?? -1) !== posix_geteuid()) {
            throw new ControlRefusal("$label is not a private process-owned regular file");
        }
    }

    private function setCredentialOwnership(string $path): bool {
        [$uid, $gid] = $this->credentialOwnership();
        $stat = @lstat($path);
        if (!is_array($stat)) {
            return false;
        }
        if (function_exists('chown') && (int) ($stat['uid'] ?? -1) !== $uid
            && !@chown($path, $uid)) {
            return false;
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)) {
            return false;
        }
        if (function_exists('chgrp') && (int) ($stat['gid'] ?? -1) !== $gid
            && !@chgrp($path, $gid)) {
            return false;
        }
        $mode = is_dir($path) ? 0700 : 0600;
        if (!@chmod($path, $mode)) {
            return false;
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        return is_array($stat)
            && (int) ($stat['uid'] ?? -1) === $uid
            && (int) ($stat['gid'] ?? -1) === $gid
            && ((int) ($stat['mode'] ?? 0) & 0777) === $mode;
    }

    private function workloadPrivateDirectory(string $path, string $label): void {
        [$uid, $gid] = $this->credentialOwnership();
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || !is_dir($path)
            || ((int) ($stat['mode'] ?? 0) & 0777) !== 0700
            || (int) ($stat['uid'] ?? -1) !== $uid
            || (int) ($stat['gid'] ?? -1) !== $gid) {
            throw new ControlRefusal("$label is not owned privately by the workload identity");
        }
    }

    private function workloadPrivateFile(string $path, string $label): void {
        [$uid, $gid] = $this->credentialOwnership();
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || !is_file($path)
            || ((int) ($stat['mode'] ?? 0) & 0777) !== 0600
            || (int) ($stat['uid'] ?? -1) !== $uid
            || (int) ($stat['gid'] ?? -1) !== $gid) {
            throw new ControlRefusal("$label is not owned privately by the workload identity");
        }
    }

    /** @return array{int,int} */
    private function credentialOwnership(): array {
        // The real Docker boundary consumes secrets as the fixed non-root
        // identity. Offline process fakes retain the invoking identity so the
        // deterministic model does not require root merely to assert argv.
        if ($this->runner instanceof NativeContainerArgvProcessRunner) {
            return [self::WORKLOAD_UID, self::WORKLOAD_GID];
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : self::WORKLOAD_UID;
        $gid = function_exists('posix_getegid') ? posix_getegid() : self::WORKLOAD_GID;
        return [$uid, $gid];
    }

    private static function workloadPath(string $path): string {
        if ($path === '' || $path[0] !== '/' || strlen($path) > 1024
            || str_contains($path, "\0")
            || preg_match('#(?:^|/)\.\.?(/|$)#D', $path) === 1
            || str_starts_with($path, '/proc/')
            || str_starts_with($path, '/sys/')
            || str_starts_with($path, '/dev/')
            || str_starts_with($path, self::SECRET_TARGET . '/')) {
            throw new ControlRefusal('configured workload repository path is invalid');
        }
        return rtrim($path, '/');
    }

    private static function domain(string $domain): string {
        if (strlen($domain) > 190
            || preg_match(
                '/^(?=.{1,190}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D',
                $domain
            ) !== 1) {
            throw new ControlRefusal('preview routing domain must be a lowercase DNS name');
        }
        return $domain;
    }

    private static function identifier(mixed $value, string $label): string {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@+-]{0,255}$/D', $value) !== 1) {
            throw new ControlRefusal("$label is invalid");
        }
        return $value;
    }

    private static function operationId(mixed $value): string {
        if (!is_string($value) || preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D', $value) !== 1) {
            throw new ControlRefusal('runtime operation id is invalid');
        }
        return $value;
    }

    private static function mutationOwner(mixed $value): string {
        if (!is_string($value)
            || preg_match(
                '/^duo-env-(?:materialize|reap|sleep)-[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D',
                $value
            ) !== 1) {
            throw new ControlRefusal('runtime mutation owner is outside the closed principal set');
        }
        return $value;
    }

    private static function sha256(mixed $value, string $label): string {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new ControlRefusal("$label must be lowercase SHA-256");
        }
        return $value;
    }

    private static function positiveInt(mixed $value, string $label): int {
        if (!is_int($value) || $value < 1) {
            throw new ControlRefusal("$label must be a positive integer");
        }
        return $value;
    }

    private static function gitOid(mixed $value): string {
        if (!is_string($value) || preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $value) !== 1) {
            throw new ControlRefusal('repository commit is not a lowercase Git object id');
        }
        return $value;
    }

    private static function gitRef(mixed $value): string {
        if (!is_string($value)
            || strlen($value) > 512
            || preg_match('#^refs/heads/[A-Za-z0-9][A-Za-z0-9._/-]*$#D', $value) !== 1
            || str_contains($value, '..')
            || str_contains($value, '//')
            || str_contains($value, '@{')
            || str_ends_with($value, '.')
            || str_ends_with($value, '/')
            || str_ends_with($value, '.lock')) {
            throw new ControlRefusal('repository branch ref is outside the closed local heads namespace');
        }
        return $value;
    }

    private static function remoteName(string $value): string {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new ControlRefusal('repository remote name is invalid');
        }
        return $value;
    }

    private static function remoteUrl(string $value): string {
        $parts = parse_url($value);
        if ($value === '' || strlen($value) > 2048
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || !is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || strtolower((string) $parts['scheme']) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') === '' || ($parts['path'] ?? '') === '/') {
            throw new ControlRefusal('repository remote URL must be canonical credential-free HTTPS');
        }
        $canonical = 'https://' . strtolower((string) $parts['host']);
        if (isset($parts['port']) && $parts['port'] !== 443) {
            $canonical .= ':' . $parts['port'];
        }
        $canonical .= $parts['path'];
        if ($canonical !== $value) {
            throw new ControlRefusal('repository remote URL must be canonical credential-free HTTPS');
        }
        return $value;
    }

    private static function repositoryRefPrefix(string $value): string {
        if (strlen($value) > 384
            || preg_match('#^refs/heads/[A-Za-z0-9][A-Za-z0-9._/-]*/$#D', $value) !== 1
            || str_contains($value, '..') || str_contains($value, '//')
            || str_contains($value, '@{') || str_contains($value, '.lock/')) {
            throw new ControlRefusal('repository remote ref prefix is outside the closed heads namespace');
        }
        return $value;
    }

    private static function inside(string $path, string $root): bool {
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function cpuString(int $nanoCpus): string {
        $whole = intdiv($nanoCpus, 1000000000);
        $fraction = $nanoCpus % 1000000000;
        if ($fraction === 0) {
            return (string) $whole;
        }
        return $whole . '.' . rtrim(str_pad((string) $fraction, 9, '0', STR_PAD_LEFT), '0');
    }
}
