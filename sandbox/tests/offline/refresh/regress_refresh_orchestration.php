<?php
declare(strict_types=1);

/* Offline host contract: live P is mandatory, target Git topology is checked,
 * and unresolved semantic rebase work never creates/replaces a source ref. */

namespace WPrism\Orchestrator {
    require_once dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
    require_once dirname(__DIR__, 4) . '/cli/src/Refresh/RefreshFieldDiff.php';

    final class RefreshPlan {
        public static array $roles = [];
        public static bool $resolved = false;
        public static int $interactiveFieldCalls = 0;
        public static bool $interactiveCancels = true;
        public static int $validatedFieldDiffs = 0;
        public static ?array $lastInteractivePresentation = null;
        public static ?array $lastContext = null;
        public static function normalizeProductionSnapshot(array $export): array { return $export; }
        /** role => `{root}/{component}` => present, recorded at the moment the compiler was handed the worktree (issue #3523). */
        public static array $lockedBytes = [];
        /** `{root}/{component}` the next compile should look for. */
        public static array $lockedExpect = [];
        public static function compileGitWorktree(string $path, string $commit, string $role): array {
            self::$roles[] = $role;
            foreach (self::$lockedExpect as $component) {
                self::$lockedBytes[$role][$component] = is_dir($path . '/code/wp-content/' . $component);
            }
            return ['commit' => $commit, 'format' => 'wprism-refresh-compiled-test/v1', 'role' => $role];
        }
        public static function assertProductionCodeMatches(array $production, array $code): void {
            if (($production['completed_code']['revision'] ?? null) !== hash('sha256', 'code')) {
                throw new \RuntimeException('test code proof failed');
            }
        }
        public static function plan(array $base, array $production, array $branch, array $context): array {
            self::$lastContext = $context;
            return [
                'context' => $context,
                'format' => 'wprism-refresh-plan/v1',
                'plan_hash' => hash('sha256', \WPrism\Canon::encode(['context' => $context, 'test' => 'plan'])),
            ];
        }
        public static function normalizePlan(array $plan): array { return $plan; }
        public static function materialize(array $plan, string $worktree, array $resolution): array {
            // This intentionally leaves a conflict unresolved, proving the
            // host cannot create a branch merely because Git code replay ended.
            if (self::$resolved) {
                // Exercise an unstaged-only porcelain row as the first status
                // line. Its leading X column is semantically significant and
                // must survive the host Git-output boundary.
                file_put_contents($worktree . '/state/base.json', "{\"rebased\":true}\n");
                file_put_contents($worktree . '/state/rebased.json', json_encode(['resolution' => $resolution], JSON_THROW_ON_ERROR) . "\n");
                file_put_contents($worktree . "/state/ label-é.json", "{}\n");
                file_put_contents($worktree . "/state/line\nbreak.json", "{}\n");
            }
            return ['format' => 'wprism-refresh-materialization/v1', 'plan_hash' => $plan['plan_hash'], 'resolved' => self::$resolved];
        }
        public static function validateMaterialization(array $receipt, array $plan, string $worktree): void {}
        public static function fieldDiff(array $plan, array $base, array $productionCode, array $branch): array {
            $diff = self::fieldDiffDocument((string) $plan['plan_hash']);
            return [
                'diff' => $diff,
                'bundle' => [
                    'algorithm' => 'wprism-refresh-field-diff/v1',
                    'diff' => $diff,
                    'diff_hash' => $diff['diff_hash'],
                    'plan_hash' => $plan['plan_hash'],
                    'private_bundle_sentinel' => 'PRIVATE-BUNDLE-SENTINEL-OMITTED',
                    'records' => [],
                ],
            ];
        }
        public static function validateFieldDiff(array $diff): array {
            self::$validatedFieldDiffs++;
            return RefreshFieldDiff::validateDiff($diff);
        }
        public static function readFieldResolution(array $diff, string $path): array { return []; }
        /** Private-only host presentation seam; it is never part of the fake diff/bundle. */
        public static function interactiveFieldPresentation(array $plan, array $diff, array $bundle): array {
            return [
                'auto' => [],
                'diff_hash' => $diff['diff_hash'],
                'format' => RefreshFieldDiff::PRESENTATION_FORMAT,
                'labels' => [],
                'plan_hash' => $diff['plan_hash'],
            ];
        }
        public static function interactiveFieldResolution(array $diff, $in, $out, ?array $presentation = null): ?array {
            self::$interactiveFieldCalls++;
            self::$lastInteractivePresentation = $presentation;
            return self::$interactiveCancels ? null : RefreshFieldDiff::resolution($diff, []);
        }
        public static function fieldDiffPolicyFromGitWorktree(string $path, string $commit, string $label): array { return []; }
        public static function assertFieldCandidatePolicy(array $bundle, array $projection): void {}
        public static function materializeFieldResolved(array $plan, string $worktree, array $bundle, array $resolution): array {
            $receipt = self::materialize($plan, $worktree, $resolution);
            $receipt['field_diff_hash'] = $bundle['diff_hash'];
            $receipt['field_resolution_hash'] = $resolution['resolution_hash'];
            return $receipt;
        }
        private static function fieldDiffDocument(string $planHash): array {
            $diff = [
                'algorithm' => 'wprism-refresh-field-diff/v1',
                'authority' => false,
                'choices' => ['ours' => 'branch', 'theirs' => 'production'],
                'format' => 'wprism-refresh-field-diff/v1',
                'plan_hash' => $planHash,
                'policy_projection_hashes' => [
                    'base' => hash('sha256', 'orchestration-base-policy'),
                    'branch' => hash('sha256', 'orchestration-reviewed-policy'),
                    'production' => hash('sha256', 'orchestration-reviewed-policy'),
                ],
                'production_snapshot_hash' => hash('sha256', 'production-snapshot'),
                'redaction' => 'values_omitted',
                'records' => [],
                'roles' => ['base' => 'merge_base', 'ours' => 'branch', 'theirs' => 'production'],
                'summary' => ['atomic_records' => 0, 'changes' => 0, 'conflicting_choices' => 0, 'records' => 0],
            ];
            $diff['diff_hash'] = hash('sha256', \WPrism\Canon::encode($diff));
            return $diff;
        }
    }
}

namespace {
require dirname(__DIR__, 4) . '/cli/src/Transport/Transport.php';
require dirname(__DIR__, 4) . '/cli/src/Transport/CodeDeploy.php';
require dirname(__DIR__, 4) . '/cli/src/Refresh/Refresh.php';
// Required here, not leaned on through Refresh.php: the issue #3520 fixtures
// below build their lock with the writer's own helpers, and a suite that only
// saw this class because the code under test happened to load it would fail to
// LOAD against the prior bytes instead of failing on the defect.
require_once dirname(__DIR__, 4) . '/agent/src/Code/CodeSourceLock.php';
// Same reason as the line above: the issue #3523 fixture builds its registry with
// WpOrgReleases' own digest helper, so the suite must fail on the ASSERTION
// against prior bytes, not on a class it only saw because Refresh.php loaded it.
require_once dirname(__DIR__, 4) . '/cli/src/Code/CodeResolver.php';
// The renderer under test. Required here for the same reason: the report
// assertions below must fail on the ASSERTION against prior bytes.
require_once dirname(__DIR__, 4) . '/cli/src/Command/CodeResolveCommand.php';

use WPrism\Orchestrator\Refresh;
use WPrism\Orchestrator\Transport;

function fail_refresh(string $message): never { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
function ok_refresh(bool $condition, string $message): void { if (!$condition) fail_refresh($message); echo "ok: $message\n"; }
function run_refresh(array $args, ?string $cwd = null): string {
    $command = implode(' ', array_map('escapeshellarg', $args));
    $out = []; $code = 0;
    exec(($cwd !== null ? 'cd ' . escapeshellarg($cwd) . ' && ' : '') . $command . ' 2>&1', $out, $code);
    if ($code !== 0) fail_refresh("command failed ($code): $command\n" . implode("\n", $out));
    return trim(implode("\n", $out));
}
function remove_refresh(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') remove_refresh($path . '/' . $name);
    @rmdir($path);
}
/** @return array{dev:string,ino:string,mode:int,size:int} */
function refresh_spool_identity(string $path): array {
    clearstatcache(true, $path);
    $stat = lstat($path);
    if (!is_array($stat)
        || !is_int($stat['mode'] ?? null)
        || !is_int($stat['size'] ?? null)
        || (($stat['mode'] & 0170000) !== 0100000)) {
        throw new \RuntimeException('test refresh spool is not a regular file');
    }
    return [
        'dev' => (string) $stat['dev'],
        'ino' => (string) $stat['ino'],
        'mode' => $stat['mode'],
        'size' => $stat['size'],
    ];
}

final class RefreshTransport extends Transport {
    public array $raw = [];
    public array $wp = [];
    public bool $swapSpoolPathAfterCapture = false;
    private ?string $exportAfterNextRead = null;
    public function __construct(private string $head, private string $export, private string $trackedStatus = '') {
        parent::__construct('production', ['repo_path' => '/target/repository']);
    }
    public function describe(): string { return 'test'; }
    protected function wpCommand(array $wpArgs): string { return 'false'; }
    protected function rawCommand(string $script): string { return 'false'; }
    public function captureRaw(string $script): array {
        $this->raw[] = $script;
        return ['exit' => 0, 'stdout' => $this->head . "\n" . $this->trackedStatus, 'stderr' => ''];
    }
    public function captureWp(array $wpArgs): array {
        $this->wp[] = $wpArgs;
        $export = $this->export;
        if ($this->exportAfterNextRead !== null) {
            $this->export = $this->exportAfterNextRead;
            $this->exportAfterNextRead = null;
        }
        return ['exit' => 0, 'stdout' => $export . "\n", 'stderr' => ''];
    }
    public function captureWpToFile(array $wpArgs, string $path, int $maxStdout, int $maxStderr, int $timeoutNs): array {
        $this->wp[] = $wpArgs;
        $export = $this->export;
        if ($this->exportAfterNextRead !== null) {
            $this->export = $this->exportAfterNextRead;
            $this->exportAfterNextRead = null;
        }
        file_put_contents($path, $export . "\n");
        $identity = refresh_spool_identity($path);
        if ($this->swapSpoolPathAfterCapture) {
            $replacement = $path . '.replacement';
            file_put_contents($replacement, "{}\n");
            if (!rename($replacement, $path)) {
                throw new \RuntimeException('could not swap the test refresh spool pathname');
            }
        }
        return [
            'exit' => 0, 'stderr' => '', 'stdout_bytes' => strlen($export) + 1,
            'stdout_exceeded' => false, 'stderr_exceeded' => false, 'timed_out' => false,
            'stdout_identity' => $identity,
        ];
    }
    public function replaceAfterNextExport(string $export): void { $this->exportAfterNextRead = $export; }
    public function replaceCurrentExport(string $export): void { $this->export = $export; }
}

/**
 * A transport that RUNS the target script instead of answering it from a
 * canned string.
 *
 * `assertTargetHead()` composes real `git status` / `git ls-files` invocations
 * against `repoPath()`, and issue #3520 is entirely about which paths those
 * commands list on a split repository — a fake that returns pre-baked lines
 * would be asserting the fixture, not Git. This executes the exact script the
 * production transport would ship, against a real repository on disk; only the
 * remote hop is missing.
 */
final class RefreshTargetTransport extends Transport {
    public array $raw = [];
    public function __construct(string $target, private string $export) {
        parent::__construct('production', ['repo_path' => $target]);
    }
    public function describe(): string { return 'split target'; }
    protected function wpCommand(array $wpArgs): string { return 'false'; }
    protected function rawCommand(string $script): string { return 'false'; }
    public function captureRaw(string $script): array {
        $this->raw[] = $script;
        $errFile = tempnam(sys_get_temp_dir(), 'wprism-refresh-target-err');
        $out = []; $code = 0;
        exec($script . ' 2>' . escapeshellarg((string) $errFile), $out, $code);
        $stderr = $errFile === false ? '' : (string) @file_get_contents($errFile);
        if ($errFile !== false) @unlink($errFile);
        return ['exit' => $code, 'stdout' => implode("\n", $out) . "\n", 'stderr' => $stderr];
    }
    public function captureWp(array $wpArgs): array {
        return ['exit' => 0, 'stdout' => $this->export . "\n", 'stderr' => ''];
    }
    public function captureWpToFile(array $wpArgs, string $path, int $maxStdout, int $maxStderr, int $timeoutNs): array {
        file_put_contents($path, $this->export . "\n");
        return [
            'exit' => 0, 'stderr' => '', 'stdout_bytes' => strlen($this->export) + 1,
            'stdout_exceeded' => false, 'stderr_exceeded' => false, 'timed_out' => false,
            'stdout_identity' => refresh_spool_identity($path),
        ];
    }
}

$spoolChild = <<<'PHP'
namespace WPrism\Orchestrator {
    require $argv[1];
    final class BoundedRefreshFixtureTransport extends Transport {
        public function __construct() { parent::__construct('fixture', ['repo_path' => '/tmp']); }
        public function describe(): string { return 'fixture'; }
        protected function rawCommand(string $script): string { return 'false'; }
        protected function wpCommand(array $args): string {
            return escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
                'for ($i = 0; $i < 3200; $i++) { echo str_repeat("x", 65536); }'
            );
        }
    }
}
namespace {
    $path = tempnam(sys_get_temp_dir(), 'wprism-refresh-spool-proof-');
    $result = (new WPrism\Orchestrator\BoundedRefreshFixtureTransport())->captureWpToFile([], $path, 6000001, 65536, 2000000000);
    $size = is_string($path) ? filesize($path) : false;
    if (is_string($path)) @unlink($path);
    echo ((int) $result['stdout_exceeded']) . ':' . ((int) $result['stderr_exceeded']) . ':' . $size . ':' . memory_get_peak_usage(true);
}
PHP;
$spoolOutput = [];
$spoolRc = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' -d memory_limit=128M -r ' . escapeshellarg($spoolChild)
    . ' ' . escapeshellarg(dirname(__DIR__, 4) . '/cli/src/Transport/Transport.php') . ' 2>&1',
    $spoolOutput,
    $spoolRc
);
$spoolParts = explode(':', implode("\n", $spoolOutput));
ok_refresh(
    $spoolRc === 0
        && ($spoolParts[0] ?? null) === '1'
        && ($spoolParts[1] ?? null) === '0'
        && is_numeric($spoolParts[2] ?? null)
        && (int) $spoolParts[2] <= 6000001
        && is_numeric($spoolParts[3] ?? null)
        && (int) $spoolParts[3] < 33554432,
    'a 200 MiB refresh stdout stream is terminated into a 6 MB spool in a 128 MiB child without unbounded host buffering'
);

$spoolFailureChild = <<<'PHP'
namespace WPrism\Orchestrator {
    require $argv[1];
    final class FailingSpoolTransport extends Transport {
        public function __construct(private string $pidPath) { parent::__construct('fixture', ['repo_path' => '/tmp']); }
        public function describe(): string { return 'fixture'; }
        protected function rawCommand(string $script): string { return 'false'; }
        protected function wpCommand(array $args): string {
            return escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
                'file_put_contents($argv[1], (string) getmypid()); while (true) { echo str_repeat("x", 65536); usleep(1000); }'
            ) . ' ' . escapeshellarg($this->pidPath);
        }
    }
}
namespace {
    putenv('WPRISM_TEST_MODE=1');
    $GLOBALS['wprism_transport_spool_write_fault'] = static function (): never {
        throw new \RuntimeException('injected bounded spool write failure');
    };
    $pidPath = $argv[2];
    $spool = tempnam(sys_get_temp_dir(), 'wprism-refresh-spool-failure-');
    $result = (new WPrism\Orchestrator\FailingSpoolTransport($pidPath))->captureWpToFile([], $spool, 6000001, 65536, 2000000000);
    $pid = (int) @file_get_contents($pidPath);
    $alive = $pid > 0 && function_exists('posix_kill') && @posix_kill($pid, 0);
    @unlink($spool);
    @unlink($pidPath);
    echo ((int) (($result['exit'] ?? 0) !== 0)) . ':' . ((int) !$alive) . ':'
        . ((int) str_contains((string) ($result['stderr'] ?? ''), 'bounded capture spool failed'));
}
PHP;
$spoolFailurePid = tempnam(sys_get_temp_dir(), 'wprism-refresh-spool-child-');
$spoolFailureOutput = [];
$spoolFailureRc = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($spoolFailureChild)
    . ' ' . escapeshellarg(dirname(__DIR__, 4) . '/cli/src/Transport/Transport.php')
    . ' ' . escapeshellarg((string) $spoolFailurePid) . ' 2>&1',
    $spoolFailureOutput,
    $spoolFailureRc
);
@unlink((string) $spoolFailurePid);
ok_refresh(
    $spoolFailureRc === 0 && implode("\n", $spoolFailureOutput) === '1:1:1',
    'a local spool write failure terminates, drains, and reaps the target child before returning'
);

$termIgnoringChild = <<<'PHP'
namespace WPrism\Orchestrator {
    require $argv[1];
    final class TermIgnoringTransport extends Transport {
        public function __construct(private string $pidPath) { parent::__construct('fixture', ['repo_path' => '/tmp']); }
        public function describe(): string { return 'fixture'; }
        protected function rawCommand(string $script): string { return 'false'; }
        protected function wpCommand(array $args): string {
            return escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
                'pcntl_async_signals(true); pcntl_signal(SIGTERM, static function (): void {}); '
                . 'file_put_contents($argv[1], (string) getmypid()); while (true) { echo str_repeat("x", 65536); }'
            ) . ' ' . escapeshellarg($this->pidPath);
        }
    }
}
namespace {
    $pidPath = $argv[2];
    $spool = tempnam(sys_get_temp_dir(), 'wprism-refresh-spool-term-');
    $started = hrtime(true);
    $result = (new WPrism\Orchestrator\TermIgnoringTransport($pidPath))->captureWpToFile([], $spool, 100001, 65536, 2000000000);
    $elapsed = hrtime(true) - $started;
    $pid = (int) @file_get_contents($pidPath);
    $alive = $pid > 0 && function_exists('posix_kill') && @posix_kill($pid, 0);
    @unlink($spool);
    @unlink($pidPath);
    echo ((int) ($result['stdout_exceeded'] ?? false)) . ':' . ((int) !$alive) . ':' . (int) ($elapsed < 5000000000);
}
PHP;
$termIgnoringPid = tempnam(sys_get_temp_dir(), 'wprism-refresh-term-child-');
$termIgnoringOutput = [];
$termIgnoringRc = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($termIgnoringChild)
    . ' ' . escapeshellarg(dirname(__DIR__, 4) . '/cli/src/Transport/Transport.php')
    . ' ' . escapeshellarg((string) $termIgnoringPid) . ' 2>&1',
    $termIgnoringOutput,
    $termIgnoringRc
);
@unlink((string) $termIgnoringPid);
ok_refresh(
    $termIgnoringRc === 0 && implode("\n", $termIgnoringOutput) === '1:1:1',
    'a TERM-ignoring continuous stdout child is SIGKILLed and reaped on the bounded transport deadline'
);

$timeoutChild = <<<'PHP'
namespace WPrism\Orchestrator {
    require $argv[1];
    final class TimeoutFixtureTransport extends Transport {
        public function __construct(private string $pidPath, private bool $chatty) { parent::__construct('fixture', ['repo_path' => '/tmp']); }
        public function describe(): string { return 'fixture'; }
        protected function rawCommand(string $script): string { return 'false'; }
        protected function wpCommand(array $args): string {
            $loop = $this->chatty
                ? 'while (true) { echo "x"; usleep(1000); }'
                : 'while (true) { usleep(1000); }';
            return escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
                'pcntl_async_signals(true); pcntl_signal(SIGTERM, static function (): void {}); '
                . 'file_put_contents($argv[1], (string) getmypid()); ' . $loop
            ) . ' ' . escapeshellarg($this->pidPath);
        }
    }
}
namespace {
    $base = $argv[2];
    $parts = [];
    foreach (['silent' => false, 'under-cap-chatty' => true] as $label => $chatty) {
        $pidPath = $base . '-' . $label;
        $spool = tempnam(sys_get_temp_dir(), 'wprism-refresh-spool-timeout-');
        $started = hrtime(true);
        $result = (new WPrism\Orchestrator\TimeoutFixtureTransport($pidPath, $chatty))->captureWpToFile(
            [], $spool, 1048576, 65536, 500000000
        );
        $elapsed = hrtime(true) - $started;
        $pid = (int) @file_get_contents($pidPath);
        $alive = $pid > 0 && function_exists('posix_kill') && @posix_kill($pid, 0);
        @unlink($spool);
        @unlink($pidPath);
        $parts[] = ((int) ($result['timed_out'] ?? false)) . ':' . ((int) !$alive) . ':'
            . ((int) (!$result['stdout_exceeded'] && !$result['stderr_exceeded'])) . ':'
            . ((int) ($elapsed < 5000000000)) . ':'
            . ((int) str_contains((string) ($result['stderr'] ?? ''), 'bounded capture execution deadline exceeded'));
    }
    echo implode('|', $parts);
}
PHP;
$timeoutBase = tempnam(sys_get_temp_dir(), 'wprism-refresh-timeout-child-');
$timeoutOutput = [];
$timeoutRc = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($timeoutChild)
    . ' ' . escapeshellarg(dirname(__DIR__, 4) . '/cli/src/Transport/Transport.php')
    . ' ' . escapeshellarg((string) $timeoutBase) . ' 2>&1',
    $timeoutOutput,
    $timeoutRc
);
@unlink((string) $timeoutBase);
ok_refresh(
    $timeoutRc === 0 && implode("\n", $timeoutOutput) === '1:1:1:1:1|1:1:1:1:1',
    'silent and continuously readable under-cap TERM-ignoring exports time out, SIGKILL, and reap on one monotonic ceiling'
);

$transportSource = file_get_contents(dirname(__DIR__, 4) . '/cli/src/Transport/Transport.php');
ok_refresh(
    is_string($transportSource)
        && !str_contains($transportSource, 'microtime(true)')
        && str_contains($transportSource, 'hrtime(true)')
        && str_contains($transportSource, 'MAX_CAPTURE_STDOUT_BYTES')
        && str_contains($transportSource, 'MAX_CAPTURE_STDERR_BYTES')
        && str_contains($transportSource, 'MAX_CAPTURE_TIMEOUT_NS'),
    'the bounded refresh transport uses monotonic nanosecond deadlines and hard-reviewed caller caps'
);

$privateSpoolChild = <<<'PHP'
namespace WPrism\Orchestrator {
    require $argv[1];
    final class PrivateSpoolTransport extends Transport {
        public function __construct() { parent::__construct('fixture', ['repo_path' => '/tmp']); }
        public function describe(): string { return 'fixture'; }
        protected function rawCommand(string $script): string { return 'false'; }
        protected function wpCommand(array $args): string { return 'printf x'; }
    }
}
namespace {
    $spool = tempnam(sys_get_temp_dir(), 'wprism-refresh-spool-mode-');
    chmod($spool, 0666);
    $result = (new WPrism\Orchestrator\PrivateSpoolTransport())->captureWpToFile([], $spool, 100, 100, 2000000000);
    $overCap = (new WPrism\Orchestrator\PrivateSpoolTransport())->captureWpToFile([], $spool, 1610612737, 100, 2000000000);
    $overStderr = (new WPrism\Orchestrator\PrivateSpoolTransport())->captureWpToFile([], $spool, 100, 8388609, 2000000000);
    $overTimeout = (new WPrism\Orchestrator\PrivateSpoolTransport())->captureWpToFile([], $spool, 100, 100, 600000000001);
    @unlink($spool);
    echo ((int) (($result['exit'] ?? 0) !== 0)) . ':'
        . ((int) str_contains((string) ($result['stderr'] ?? ''), 'bounded capture spool failed')) . ':'
        . ((int) (($overCap['exit'] ?? 0) !== 0)) . ':'
        . ((int) str_contains((string) ($overCap['stderr'] ?? ''), 'invalid bounded capture spool')) . ':'
        . ((int) (($overStderr['exit'] ?? 0) !== 0)) . ':'
        . ((int) str_contains((string) ($overStderr['stderr'] ?? ''), 'invalid bounded capture spool')) . ':'
        . ((int) (($overTimeout['exit'] ?? 0) !== 0)) . ':'
        . ((int) str_contains((string) ($overTimeout['stderr'] ?? ''), 'invalid bounded capture spool'));
}
PHP;
$privateSpoolOutput = [];
$privateSpoolRc = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($privateSpoolChild)
    . ' ' . escapeshellarg(dirname(__DIR__, 4) . '/cli/src/Transport/Transport.php') . ' 2>&1',
    $privateSpoolOutput,
    $privateSpoolRc
);
ok_refresh(
    $privateSpoolRc === 0 && implode("\n", $privateSpoolOutput) === '1:1:1:1:1:1:1:1',
    'a changed 0666 spool mode or over-hard-cap stdout, stderr, or timeout refuses before Refresh can read or launch it'
);

$tmp = sys_get_temp_dir() . '/wprism-refresh-orchestration-' . bin2hex(random_bytes(6));
$repo = $tmp . '/repo';
mkdir($repo, 0700, true);
try {
    run_refresh(['git', 'init', '-b', 'main'], $repo);
    run_refresh(['git', 'config', 'user.email', 'test@example.invalid'], $repo);
    run_refresh(['git', 'config', 'user.name', 'Refresh Test'], $repo);
    mkdir($repo . '/state', 0700, true);
    file_put_contents($repo . '/state/base.json', "{}\n");
    file_put_contents($repo . '/prod.txt', "base\n");
    file_put_contents($repo . '/feature.txt', "base\n");
    run_refresh(['git', 'add', '.'], $repo); run_refresh(['git', 'commit', '-m', 'base'], $repo);
    $base = run_refresh(['git', 'rev-parse', 'HEAD'], $repo);
    run_refresh(['git', 'branch', 'production'], $repo);
    run_refresh(['git', 'checkout', 'production'], $repo);
    file_put_contents($repo . '/prod.txt', "production\n");
    run_refresh(['git', 'add', 'prod.txt'], $repo); run_refresh(['git', 'commit', '-m', 'production'], $repo);
    $production = run_refresh(['git', 'rev-parse', 'HEAD'], $repo);
    run_refresh(['git', 'checkout', '-b', 'feature', $base], $repo);
    file_put_contents($repo . '/feature.txt', "feature\n");
    run_refresh(['git', 'add', 'feature.txt'], $repo); run_refresh(['git', 'commit', '-m', 'feature'], $repo);
    $feature = run_refresh(['git', 'rev-parse', 'HEAD'], $repo);

    $export = json_encode([
        'completed_code' => ['descriptor' => ['format' => 'wprism-code/v1'], 'revision' => hash('sha256', 'code')],
        'format' => 'wprism-refresh-production/v1',
        'media' => [],
        'policy' => ['manifest_hash' => hash('sha256', 'manifest')],
        'records' => ['post:one' => ['content' => ['uuid' => 'one'], 'hash' => hash('sha256', 'record'), 'identity' => 'post:one', 'path' => 'state/posts/one.json', 'type' => 'post']],
        'repository' => ['compiler' => ['format' => 'wprism-refresh-repository/v1']],
        'snapshot_hash' => hash('sha256', 'production-snapshot'),
    ], JSON_THROW_ON_ERROR);
    $transport = new RefreshTransport($production, $export);
    $old = getcwd(); chdir($repo);
    $result = Refresh::refresh($transport, 'production');
    ok_refresh(($result['context']['base_commit'] ?? null) === $base, 'merge-base is the explicit B input');
    ok_refresh(($result['context']['branch_commit'] ?? null) === $feature, 'branch HEAD is W input');
    ok_refresh(($result['context']['production_commit'] ?? null) === $production, 'target HEAD must equal production-ref');
    ok_refresh(is_file($result['plan_path']), 'immutable refresh plan is persisted locally');
    $exportCall = $transport->wp[0] ?? [];
    ok_refresh(count($transport->wp) === 1
        && in_array('--skip-plugins', $exportCall, true)
        && in_array('--skip-themes', $exportCall, true)
        && count(array_filter($exportCall, static fn(string $arg): bool => str_starts_with($arg, '--exec=')
            && str_contains($arg, 'WPRISM_CONTROL_PLANE')
            && str_contains($arg, 'after_wp_config_load'))) === 1
        && array_slice($exportCall, -4) === ['wprism', 'refresh-export', '--repo=/target/repository', '--format=json'],
        'P is obtained through refresh-export under the isolated control bootstrap');
    ok_refresh(count(array_filter($transport->raw, static fn(string $script): bool =>
        str_contains($script, '--untracked-files=all')
        && str_contains($script, 'ls-files --others --ignored --exclude-standard')
        && str_contains($script, 'state media code manifests'))) > 0,
        'target verifier includes untracked and ignored canonical compiler inputs');
    ok_refresh(in_array('base', \WPrism\Orchestrator\RefreshPlan::$roles, true)
        && in_array('branch', \WPrism\Orchestrator\RefreshPlan::$roles, true)
        && in_array('production-code', \WPrism\Orchestrator\RefreshPlan::$roles, true), 'Git artifacts are compiled by role, with production code-only');
    ok_refresh(run_refresh(['git', 'rev-parse', 'HEAD'], $repo) === $feature && run_refresh(['git', 'branch', '--show-current'], $repo) === 'feature', 'refresh leaves source checkout/ref untouched');

    $swappedSpoolTransport = new RefreshTransport($production, $export);
    $swappedSpoolTransport->swapSpoolPathAfterCapture = true;
    try {
        Refresh::refresh($swappedSpoolTransport, 'production');
        fail_refresh('refresh accepted a pathname swapped after transport capture');
    } catch (\RuntimeException $e) {
        ok_refresh(
            str_contains($e->getMessage(), 'bounded transport handoff'),
            'refresh rejects a local spool pathname whose inode changed after capture'
        );
    }

    // An option root is a public scoped-refresh path. The host must forward
    // the exact immutable selector request to the agent, bind the returned
    // scope evidence, and retain the contract through its planner boundary;
    // it must not apply the capture/apply whole-options refusal here.
    $optionScope = [
        'format' => 'wprism-scope-contract/v1',
        'scope_hash' => hash('sha256', 'option-root-refresh-scope'),
        'selectors' => ['option:blogname'],
        'source' => ['artifact_hash' => hash('sha256', 'option-root-source')],
    ];
    $scopedExport = json_decode($export, true, 512, JSON_THROW_ON_ERROR);
    $scopedExport['scope'] = [
        'format' => 'wprism-refresh-scope/v1',
        'out_of_scope' => 'omitted_not_absent',
        'scope_hash' => $optionScope['scope_hash'],
        'selectors' => $optionScope['selectors'],
        'source' => $optionScope['source'],
    ];
    $scopedExport['snapshot_hash'] = hash('sha256', 'option-root-production-snapshot');
    $optionTransport = new RefreshTransport($production, json_encode($scopedExport, JSON_THROW_ON_ERROR));
    $optionRefresh = Refresh::refresh($optionTransport, 'production', $optionScope);
    $optionRequest = array_values(array_filter(
        $optionTransport->wp[0] ?? [],
        static fn(string $arg): bool => str_starts_with($arg, '--scope-request-b64=')
    ));
    $optionRequestPayload = $optionRequest === [] ? null : json_decode(
        base64_decode(substr($optionRequest[0], strlen('--scope-request-b64=')), true),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    ok_refresh(
        ($optionRefresh['context']['scope_contract'] ?? null) === $optionScope
            && $optionRequestPayload === [
                'format' => 'wprism-scope-request/v1',
                'scope_hash' => $optionScope['scope_hash'],
                'selectors' => $optionScope['selectors'],
            ]
            && (\WPrism\Orchestrator\RefreshPlan::$lastContext['scope_contract'] ?? null) === $optionScope,
        'option-root refresh forwards and retains the exact immutable scope contract without widening it'
    );
    $optionRunsBefore = glob($repo . '/.git/wprism-refresh/runs/*/run.json') ?: [];
    try {
        Refresh::rebase($optionTransport, 'production', 'option-root-refresh-candidate', ['strategy' => 'manual', 'records' => []], $optionScope);
        fail_refresh('option-root rebase unexpectedly materialized an unresolved candidate');
    } catch (\RuntimeException $e) {
        ok_refresh(str_contains($e->getMessage(), 'resolved'),
            'option-root rebase reaches the scoped planner instead of the capture/apply refusal');
    }
    $optionRunsAfter = glob($repo . '/.git/wprism-refresh/runs/*/run.json') ?: [];
    $optionRunPaths = array_values(array_diff($optionRunsAfter, $optionRunsBefore));
    $optionRun = $optionRunPaths === [] ? [] : json_decode((string) file_get_contents($optionRunPaths[0]), true, 512, JSON_THROW_ON_ERROR);
    ok_refresh(count($optionTransport->wp) === 3
        && ($optionRun['scope_hash'] ?? null) === $optionScope['scope_hash']
        && (\WPrism\Orchestrator\RefreshPlan::$lastContext['scope_contract'] ?? null) === $optionScope,
        'option-root rebase performs both scoped production observations and journals only its scope hash');
    if ($optionRunPaths !== []) Refresh::abort(basename(dirname($optionRunPaths[0])));

    $dirty = new RefreshTransport($production, $export, "?? state/deletions/untracked.json\n");
    try {
        Refresh::refresh($dirty, 'production');
        fail_refresh('tracked target repository state was accepted');
    } catch (Throwable $e) {
        ok_refresh(str_contains($e->getMessage(), 'untracked') && $dirty->wp === [],
            'untracked canonical target state refuses before refresh-export');
    }

    $runsBeforeUnresolved = glob($repo . '/.git/wprism-refresh/runs/*/run.json') ?: [];
    try {
        Refresh::rebase($transport, 'production', 'refresh-candidate', ['strategy' => 'manual', 'records' => []]);
        fail_refresh('unresolved planner materialization created a branch');
    } catch (Throwable $e) {
        ok_refresh(str_contains($e->getMessage(), 'resolved'), 'unresolved semantic materialization refuses before branch creation: ' . $e->getMessage());
    }
    $exists = []; $status = 0; exec('git -C ' . escapeshellarg($repo) . ' show-ref --verify --quiet refs/heads/refresh-candidate', $exists, $status);
    ok_refresh($status === 1, 'unresolved rebase creates no requested ref');
    ok_refresh(run_refresh(['git', 'rev-parse', 'HEAD'], $repo) === $feature && run_refresh(['git', 'branch', '--show-current'], $repo) === 'feature', 'failed rebase preserves source branch usability');
    $runs = glob($repo . '/.git/wprism-refresh/runs/*/run.json') ?: [];
    $newUnresolvedRuns = array_values(array_diff($runs, $runsBeforeUnresolved));
    ok_refresh(count($newUnresolvedRuns) === 1, 'failed candidate has a durable recovery run journal');
    $runId = basename(dirname($newUnresolvedRuns[0]));
    Refresh::abort($runId);
    ok_refresh(!is_dir($repo . '/.git/wprism-refresh/worktrees/' . $runId), 'abort removes only journal-owned candidate worktree');

    $runsBeforeCancel = glob($repo . '/.git/wprism-refresh/runs/*/run.json') ?: [];
    try {
        Refresh::rebase($transport, 'production', 'refresh-interactive-cancel', ['strategy' => 'manual', 'records' => []], null, null, true);
        fail_refresh('interactive cancellation unexpectedly returned');
    } catch (\WPrism\Orchestrator\RefreshFieldResolutionCancelled $e) {
        ok_refresh(str_contains($e->getMessage(), 'no run record, candidate worktree, branch, or ref was created'),
            'interactive cancellation explicitly guarantees no run record, candidate worktree, branch, or ref');
    }
    $cancelExists = []; $cancelStatus = 0;
    exec('git -C ' . escapeshellarg($repo) . ' show-ref --verify --quiet refs/heads/refresh-interactive-cancel', $cancelExists, $cancelStatus);
    ok_refresh($cancelStatus === 1 && \WPrism\Orchestrator\RefreshPlan::$interactiveFieldCalls === 1,
        'interactive EOF/cancel creates no requested ref after resolving the redacted diff');
    $runsAfterCancel = glob($repo . '/.git/wprism-refresh/runs/*/run.json') ?: [];
    $cancelWorktrees = glob($repo . '/.git/wprism-refresh/worktrees/*') ?: [];
    $cancelDiffs = glob($repo . '/.git/wprism-refresh/field-diffs/*.json') ?: [];
    ok_refresh($runsAfterCancel === $runsBeforeCancel && $cancelWorktrees === [] && $cancelDiffs !== [],
        'interactive cancellation creates no run journal or candidate worktree while retaining only immutable planning/diff artifacts');

    $readsBeforeExplicitManual = count($transport->wp);
    try {
        Refresh::rebase($transport, 'production', 'refresh-explicit-manual', ['strategy' => 'manual', 'records' => []], null, null, true, true);
        fail_refresh('explicit legacy manual strategy was accepted with interactive resolution');
    } catch (\RuntimeException $e) {
        ok_refresh(str_contains($e->getMessage(), 'cannot be mixed'),
            'field mode rejects an explicitly supplied legacy --strategy=manual');
    }
    ok_refresh(count($transport->wp) === $readsBeforeExplicitManual,
        'explicit legacy strategy refusal happens before another target read or scratch candidate');

    \WPrism\Orchestrator\RefreshPlan::$resolved = true;
    $complete = Refresh::rebase($transport, 'production', 'refresh-complete', ['strategy' => 'ours', 'records' => []]);
    ok_refresh(run_refresh(['git', 'rev-parse', 'refresh-complete'], $repo) === $complete['head'], 'strictly validated candidate is atomically published as a new ref');
    ok_refresh(run_refresh(['git', 'show', 'refresh-complete:state/rebased.json'], $repo) !== '', 'semantic state materialization is committed on candidate only');
    ok_refresh(run_refresh(['git', 'show', "refresh-complete:state/ label-é.json"], $repo) === '{}'
        && run_refresh(['git', 'show', "refresh-complete:state/line\nbreak.json"], $repo) === '{}',
        'byte-exact boundary accepts Unicode, whitespace, and newline state paths');
    ok_refresh(run_refresh(['git', 'rev-parse', 'HEAD'], $repo) === $feature && run_refresh(['git', 'branch', '--show-current'], $repo) === 'feature', 'successful rebase still preserves source checkout/ref');
    $completeRun = json_decode((string) file_get_contents($repo . '/.git/wprism-refresh/runs/' . $complete['run_id'] . '/run.json'), true, 512, JSON_THROW_ON_ERROR);
    ok_refresh(($completeRun['resolution']['strategy'] ?? null) === 'ours', 'declared conflict strategy is immutable run evidence');
    ok_refresh(!is_dir($repo . '/.git/wprism-refresh/worktrees/' . $complete['run_id']), 'successful rebase cleans only its disposable worktree');

    // The strict v1 public diff seam is exercised through a real host run:
    // the private bundle is intentionally marked with a sentinel so neither
    // the public diff journal nor the run evidence can accidentally serialize
    // it. This fake materializer still drives code replay, state commit, and
    // new-ref publication through Refresh itself.
    \WPrism\Orchestrator\RefreshPlan::$interactiveCancels = false;
    $fieldComplete = Refresh::rebase(
        $transport,
        'production',
        'refresh-field-complete',
        ['strategy' => 'manual', 'records' => []],
        null,
        null,
        true
    );
    $fieldRunPath = $repo . '/.git/wprism-refresh/runs/' . $fieldComplete['run_id'] . '/run.json';
    $fieldRunBytes = (string) file_get_contents($fieldRunPath);
    $fieldRun = json_decode($fieldRunBytes, true, 512, JSON_THROW_ON_ERROR);
    $fieldDiffPath = (string) ($fieldComplete['field_diff_path'] ?? '');
    $fieldDiffBytes = $fieldDiffPath === '' ? '' : (string) file_get_contents($fieldDiffPath);
    $fieldDiffJournal = $fieldDiffBytes === '' ? [] : json_decode($fieldDiffBytes, true, 512, JSON_THROW_ON_ERROR);
    $fieldReceiptEvents = glob($repo . '/.git/wprism-refresh/runs/' . $fieldComplete['run_id'] . '/events/*-state-materialized.json') ?: [];
    $fieldReceipt = $fieldReceiptEvents === [] ? [] : json_decode((string) file_get_contents($fieldReceiptEvents[0]), true, 512, JSON_THROW_ON_ERROR);
    ok_refresh(
        run_refresh(['git', 'rev-parse', 'refresh-field-complete'], $repo) === $fieldComplete['head']
            && \WPrism\Orchestrator\RefreshPlan::$validatedFieldDiffs >= 2
            && ($fieldRun['field_diff_hash'] ?? null) === ($fieldDiffJournal['diff_hash'] ?? null)
            && ($fieldRun['field_resolution']['resolution_hash'] ?? null) === ($fieldReceipt['data']['receipt']['field_resolution_hash'] ?? null),
        'successful field-mode host run validates the closed diff, publishes a new ref, and binds exact diff/resolution hashes'
    );
    ok_refresh(
        !str_contains($fieldRunBytes, 'PRIVATE-BUNDLE-SENTINEL-OMITTED')
            && !str_contains($fieldDiffBytes, 'PRIVATE-BUNDLE-SENTINEL-OMITTED')
            && (\WPrism\Orchestrator\RefreshPlan::$lastInteractivePresentation['format'] ?? null) === \WPrism\Orchestrator\RefreshFieldDiff::PRESENTATION_FORMAT
            && !str_contains($fieldRunBytes, \WPrism\Orchestrator\RefreshFieldDiff::PRESENTATION_FORMAT)
            && !str_contains($fieldDiffBytes, \WPrism\Orchestrator\RefreshFieldDiff::PRESENTATION_FORMAT)
            && !str_contains(\WPrism\Canon::encode($fieldReceipt), \WPrism\Orchestrator\RefreshFieldDiff::PRESENTATION_FORMAT),
        'field run evidence, public diff, and receipt omit private bundle and interactive-presentation data'
    );

    // Choices are collected before Refresh's mandatory second production
    // observation. A snapshot change must stop before a run journal,
    // candidate worktree, or new ref exists.
    $changedExport = json_decode($export, true, 512, JSON_THROW_ON_ERROR);
    $changedExport['snapshot_hash'] = hash('sha256', 'production-snapshot-moved');
    $transport->replaceAfterNextExport(json_encode($changedExport, JSON_THROW_ON_ERROR));
    $runsBeforeStale = glob($repo . '/.git/wprism-refresh/runs/*/run.json') ?: [];
    try {
        Refresh::rebase(
            $transport,
            'production',
            'refresh-field-stale-snapshot',
            ['strategy' => 'manual', 'records' => []],
            null,
            null,
            true
        );
        fail_refresh('field choices were applied after the production snapshot moved');
    } catch (\RuntimeException $e) {
        ok_refresh(str_contains($e->getMessage(), 'production changed after refresh planning'),
            'second production observation refuses a stale field-resolution plan');
    }
    $staleExists = []; $staleStatus = 0;
    exec('git -C ' . escapeshellarg($repo) . ' show-ref --verify --quiet refs/heads/refresh-field-stale-snapshot', $staleExists, $staleStatus);
    $runsAfterStale = glob($repo . '/.git/wprism-refresh/runs/*/run.json') ?: [];
    $staleWorktrees = glob($repo . '/.git/wprism-refresh/worktrees/*') ?: [];
    ok_refresh($staleStatus === 1 && $runsAfterStale === $runsBeforeStale && $staleWorktrees === [],
        'stale field resolution creates no run record, candidate worktree, or requested ref');

    // After a field-mode run starts, Refresh retains the candidate/recovery
    // evidence but changes the public exception type to a run-id-only handle.
    // The detailed planner reason remains in the private local event journal.
    $transport->replaceCurrentExport($export);
    \WPrism\Orchestrator\RefreshPlan::$resolved = false;
    $failedFieldRun = null;
    try {
        Refresh::rebase(
            $transport,
            'production',
            'refresh-field-private-failure',
            ['strategy' => 'manual', 'records' => []],
            null,
            null,
            true
        );
        fail_refresh('post-run field failure unexpectedly returned');
    } catch (\WPrism\Orchestrator\RefreshFieldResolutionRunFailed $e) {
        $failedFieldRun = $e->runId();
        ok_refresh(!str_contains($e->getMessage(), 'semantic planner')
            && preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D', $failedFieldRun) === 1,
            'post-run field failure exposes only a safe run-id recovery handle');
    }
    $failedEvents = $failedFieldRun === null ? [] : glob($repo . '/.git/wprism-refresh/runs/' . $failedFieldRun . '/events/*-stopped.json');
    $failedEventBytes = $failedEvents === [] ? '' : (string) file_get_contents($failedEvents[0]);
    ok_refresh(str_contains($failedEventBytes, 'semantic planner did not return a resolved'),
        'post-run field failure preserves the detailed cause only in private event evidence');
    if ($failedFieldRun !== null) Refresh::abort($failedFieldRun);

    // ------------------------------------------------------------ issue #3520
    // Since issue #3499 the DEFAULT init is split: each locked component stays on
    // disk and out of Git, excluded by a root-anchored `.gitignore` line and
    // identified by `code/wprism-code.lock.json`'s `tree_sha256` at the same ref.
    // assertTargetHead() listed every one of those trees as an "ignored
    // canonical change", so the first `wprism rehearse` on ANY split repository
    // refused — grind_adapter_walk.sh S1 on a 3-component split (woocommerce,
    // wpforms-lite, twentytwentyone) died there while the identical scenario
    // passed the day before, when init still vendored everything.
    //
    // The gate's rule is "no bytes --production-ref does not identify", and the
    // lock IS that identification. Everything it does not name still refuses.
    $refusal = 'production target repository has tracked, untracked, or ignored canonical changes; '
        . 'refusing a refresh-export from bytes not identified by --production-ref';

    /**
     * A source checkout (the host's cwd) plus its production clone (the target),
     * both real Git repositories: base commit -> `production` -> `feature`, so
     * merge-base is the base exactly as the fixture above arranges it.
     */
    $buildPair = static function (string $label, array $codeConfig, ?array $lock, array $ignoreLines) use ($tmp): array {
        $source = $tmp . '/' . $label . '-source';
        $target = $tmp . '/' . $label . '-target';
        mkdir($source . '/state', 0700, true);
        file_put_contents($source . '/state/base.json', "{}\n");
        file_put_contents($source . '/.gitignore', implode("\n", $ignoreLines) . "\n");
        file_put_contents($source . '/site.wprism.json', json_encode(
            ['code' => $codeConfig, 'manifests' => ['core'], 'spec_version' => 2],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n");
        if ($lock !== null) {
            mkdir($source . '/code', 0700, true);
            // A complete v2 lock: the two locked components and an empty
            // first_party list, since the fixture carries nothing else under
            // code/wp-content.
            file_put_contents($source . '/code/wprism-code.lock.json', json_encode(
                ['components' => $lock, 'first_party' => [], 'format' => \WPrism\CodeSourceLock::FORMAT],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n");
        }
        run_refresh(['git', 'init', '-q', '-b', 'main'], $source);
        run_refresh(['git', 'config', 'user.email', 'test@example.invalid'], $source);
        run_refresh(['git', 'config', 'user.name', 'Refresh Test'], $source);
        run_refresh(['git', 'add', '.'], $source);
        run_refresh(['git', 'commit', '-q', '-m', 'base'], $source);
        $base = run_refresh(['git', 'rev-parse', 'HEAD'], $source);
        run_refresh(['git', 'checkout', '-q', '-b', 'production'], $source);
        file_put_contents($source . '/state/prod.json', "{}\n");
        run_refresh(['git', 'add', '.'], $source);
        run_refresh(['git', 'commit', '-q', '-m', 'production'], $source);
        $production = run_refresh(['git', 'rev-parse', 'HEAD'], $source);
        run_refresh(['git', 'checkout', '-q', '-b', 'feature', $base], $source);
        file_put_contents($source . '/state/feature.json', "{}\n");
        run_refresh(['git', 'add', '.'], $source);
        run_refresh(['git', 'commit', '-q', '-m', 'feature'], $source);
        run_refresh(['git', 'clone', '-q', '--no-hardlinks', $source, $target]);
        run_refresh(['git', '-C', $target, 'checkout', '-q', $production], $source);

        return ['source' => $source, 'target' => $target, 'production' => $production];
    };
    $plant = static function (string $root, string $relative, string $bytes): void {
        $path = $root . '/' . $relative;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, $bytes);
    };

    $shopBytes = "<?php // acme-shop\n";
    $themeBytes = "/* acme-theme */\n";

    // ------------------------------------------------------------ issue #3523
    // A Git worktree of a split commit carries NO locked component bytes, so
    // every compile the host performs must have them materialized into the
    // worktree first. That makes these components genuinely resolvable rather
    // than nominal: a local `file://` registry holding real archives, reached
    // through WpOrgReleases' mirror override, with the cache pointed at scratch
    // so the suite never touches the developer's real one — and, decisively,
    // never the network. The lock still carries the CANONICAL wp.org url,
    // because CodeSourceLock::assert_origin() admits only `https://`; the
    // override rewrites it at fetch time (WpOrgReleases::fetchUrl():227-231).
    $registry = $tmp . '/wporg';
    mkdir($registry . '/plugin', 0700, true);
    mkdir($registry . '/theme', 0700, true);
    putenv('XDG_CACHE_HOME=' . $tmp . '/cache');
    putenv(\WPrism\Orchestrator\WpOrgReleases::FETCH_BASE_ENV . '=file://' . $registry);

    /** One real zip, plus the two digests the lock must declare for it. */
    $publish = static function (string $segment, string $component, string $version, string $file, string $body)
        use ($registry, $tmp): array {
        $archive = $registry . '/' . $segment . '/' . $component . '.' . $version . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            fail_refresh("could not create the fixture archive $archive");
        }
        $zip->addEmptyDir($component);
        $zip->addFromString($component . '/' . $file, $body);
        $zip->close();
        // treeDigest() over the unpacked component is the same computation the
        // resolver verifies with, so a fixture that disagreed would fail loudly
        // here rather than silently prove nothing.
        $probe = $tmp . '/probe-' . bin2hex(random_bytes(4)) . '/' . $component;
        mkdir($probe, 0700, true);
        file_put_contents($probe . '/' . $file, $body);
        $tree = \WPrism\Orchestrator\WpOrgReleases::treeDigest($probe);
        remove_refresh(dirname($probe));

        return [
            'archive_sha256' => hash_file('sha256', $archive),
            'tree_sha256' => $tree,
            'url' => \WPrism\Orchestrator\WpOrgReleases::canonicalUrl(
                $segment === 'plugin' ? 'plugins' : 'themes',
                $component,
                $version
            ),
        ];
    };
    $shopRelease = $publish('plugin', 'acme-shop', '1.0', 'acme-shop.php', $shopBytes);
    $themeRelease = $publish('theme', 'acme-theme', '2.0', 'style.css', $themeBytes);

    $splitPair = $buildPair(
        'split',
        ['format' => 2, 'layout' => 'wp-content', 'lock' => \WPrism\CodeSourceLock::PATH, 'source' => 'code/wp-content'],
        // Sorted by root then component, as assert_lock() requires.
        [[
            'component' => 'acme-shop',
            'origin' => ['archive_sha256' => $shopRelease['archive_sha256'], 'kind' => 'wp-org-release',
                'url' => $shopRelease['url']],
            'root' => 'plugins',
            'tree_sha256' => $shopRelease['tree_sha256'],
            'version' => '1.0',
        ], [
            'component' => 'acme-theme',
            'origin' => ['archive_sha256' => $themeRelease['archive_sha256'], 'kind' => 'wp-org-release',
                'url' => $themeRelease['url']],
            'root' => 'themes',
            'tree_sha256' => $themeRelease['tree_sha256'],
            'version' => '2.0',
        ]],
        [
            \WPrism\CodeSourceLock::gitignore_line('plugins', 'acme-shop'),
            \WPrism\CodeSourceLock::gitignore_line('themes', 'acme-theme'),
            // Two ignore lines the lock deliberately does NOT declare: a stray
            // file beside a locked tree, and a whole undeclared directory.
            // Both are ignored bytes under `code` that nothing identifies.
            '/code/wp-content/plugins/debug.log',
            '/code/wp-content/plugins/leftover/',
        ]
    );
    $plant($splitPair['target'], 'code/wp-content/plugins/acme-shop/acme-shop.php', $shopBytes);
    $plant($splitPair['target'], 'code/wp-content/themes/acme-theme/style.css', $themeBytes);

    $splitTransport = new RefreshTargetTransport($splitPair['target'], $export);
    \WPrism\Orchestrator\RefreshPlan::$lockedExpect = ['plugins/acme-shop', 'themes/acme-theme'];
    \WPrism\Orchestrator\RefreshPlan::$lockedBytes = [];
    chdir($splitPair['source']);
    $splitResult = [];
    try {
        $splitResult = Refresh::refresh($splitTransport, 'production');
    } catch (\RuntimeException $e) {
        // Named rather than left as an uncaught fatal: against the prior bytes
        // this is THE defect, and the report should read as the refusal the
        // grind saw rather than as a stack trace.
        fail_refresh('a split repository must pass the target-head gate, but it refused: ' . $e->getMessage());
    }
    ok_refresh(
        ($splitResult['context']['production_commit'] ?? null) === $splitPair['production'],
        'a split repository whose ignored component trees are exactly the ones its lock declares passes the '
        . 'target-head gate — the defect refused every issue #3499 default init at its first rehearse'
    );
    $splitScript = $splitTransport->raw[0] ?? '';
    ok_refresh(
        str_contains($splitScript, "':(exclude,literal)code/wp-content/plugins/acme-shop/'")
            && str_contains($splitScript, "':(exclude,literal)code/wp-content/themes/acme-theme/'")
            && str_contains($splitScript, 'ls-files --others --ignored --exclude-standard -- site.wprism.json state media code manifests'),
        'each locked tree is excluded by its own literal pathspec, appended to the unchanged partition list; '
        . '`literal` keeps a component name that contains glob metacharacters (safe_component() admits them) '
        . 'from being read as a pattern that would match something else, or nothing'
    );

    // The bytes the lock does not name are still unidentified. Each case below
    // restores the target afterwards, so the next one starts from the state
    // that just passed.
    foreach ([
        ['code/wp-content/plugins/debug.log', "stray\n",
            'an ignored stray file BESIDE a locked tree still refuses: the lock names component trees, and '
            . 'nothing identifies this one'],
        ['code/wp-content/plugins/leftover/old.php', "<?php // left behind\n",
            'and so does a whole ignored directory under code that the lock does not declare — the tombstone '
            . 'threat model the gate exists for is untouched'],
        ['code/wp-content/plugins/rogue.php', "<?php // beside\n",
            'and an UNTRACKED (not ignored) sibling beside the locked tree refuses through the status arm, '
            . 'which the exclusion never touched'],
    ] as [$relative, $bytes, $message]) {
        $plant($splitPair['target'], $relative, $bytes);
        $caught = null;
        try {
            Refresh::refresh(new RefreshTargetTransport($splitPair['target'], $export), 'production');
        } catch (\RuntimeException $e) {
            $caught = $e->getMessage();
        }
        ok_refresh($caught === $refusal, $message . ' (with the refusal byte-identical)');
        @unlink($splitPair['target'] . '/' . $relative);
        @rmdir(dirname($splitPair['target'] . '/' . $relative));
    }

    // The status arm is deliberately NOT narrowed: it never lists an ignored
    // file, so every tracked change is still a refusal on a split repository.
    file_put_contents($splitPair['target'] . '/state/base.json', "{\"edited\":true}\n");
    $trackedCaught = null;
    try {
        Refresh::refresh(new RefreshTargetTransport($splitPair['target'], $export), 'production');
    } catch (\RuntimeException $e) {
        $trackedCaught = $e->getMessage();
    }
    ok_refresh($trackedCaught === $refusal, 'a tracked canonical edit on a split target still refuses');
    file_put_contents($splitPair['target'] . '/state/base.json', "{}\n");

    // A fully vendored (format 1) repository takes none of the new path: no
    // lock exists, so no pathspec is excluded and any ignored file under code
    // refuses exactly as it did before.
    $vendoredPair = $buildPair(
        'vendored',
        ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
        null,
        ['/code/wp-content/plugins/acme-shop/']
    );
    $plant($vendoredPair['target'], 'code/wp-content/plugins/acme-shop/acme-shop.php', $shopBytes);
    $vendoredTransport = new RefreshTargetTransport($vendoredPair['target'], $export);
    chdir($vendoredPair['source']);
    $vendoredCaught = null;
    try {
        Refresh::refresh($vendoredTransport, 'production');
    } catch (\RuntimeException $e) {
        $vendoredCaught = $e->getMessage();
    }
    ok_refresh(
        $vendoredCaught === $refusal && !str_contains($vendoredTransport->raw[0] ?? '', ':(exclude'),
        'a format-1 repository excludes nothing and still refuses an ignored tree under code — only a parseable '
        . 'format-2 lock at the production ref identifies bytes'
    );

    // ------------------------------------------------------------ issue #3523
    // The compile half of the same split. `git worktree add --detach` produces
    // a checkout with NO locked component bytes — that is what the split means —
    // so RepositoryCompiler refused every host compile with
    // `[code_source_missing] code/wp-content` and no split repository could
    // rehearse, refresh or rebase (grind_adapter_walk.sh S1). Refresh now
    // materializes the declared lock into each worktree it creates, through the
    // one host-side resolver, before handing it to the compiler.
    ok_refresh(
        (\WPrism\Orchestrator\RefreshPlan::$lockedBytes['base'] ?? null) === ['plugins/acme-shop' => true, 'themes/acme-theme' => true]
            && (\WPrism\Orchestrator\RefreshPlan::$lockedBytes['branch'] ?? null) === ['plugins/acme-shop' => true, 'themes/acme-theme' => true]
            && (\WPrism\Orchestrator\RefreshPlan::$lockedBytes['production-code'] ?? null) === ['plugins/acme-shop' => true, 'themes/acme-theme' => true],
        'every worktree the host compiles is handed the locked component bytes — base, branch AND production-code, '
        . 'each verified against the lock before the compiler sees it; against the prior bytes each of these '
        . 'directories is absent and the compiler refuses code_source_missing'
    );

    // The resolution is REPORTED, in the vocabulary `wprism code-resolve` and the
    // deploy phase already use. Refresh returns the resolver's rows; the
    // command renders them (CodeResolveCommand::renderRefreshPhase()), because
    // cli/src/Refresh/Refresh.php has no `echo` in it at all.
    $resolveRows = $splitResult['code_resolve'] ?? [];
    ok_refresh(
        array_keys($resolveRows) === ['base', 'branch', 'production-code']
            && array_column($resolveRows['production-code']['rows'] ?? [], 'state') === ['resolved', 'resolved']
            && ($resolveRows['production-code']['lock'] ?? null) === \WPrism\CodeSourceLock::PATH,
        'the result carries one reported phase per compiled worktree, each naming the lock it read and the '
        . 'state of every component it materialized'
    );
    ob_start();
    \WPrism\Orchestrator\CodeResolveCommand::renderRefreshPhase($splitResult, 'refresh');
    $rendered = (string) ob_get_clean();
    ok_refresh(
        str_contains($rendered, 'wprism: refresh: code-resolve (production-code worktree): 2 component(s) declared in code/wprism-code.lock.json')
            && str_contains($rendered, 'RESOLVED plugins/acme-shop 1.0 — ')
            && str_contains($rendered, 'wprism: refresh: code-resolve (production-code worktree): 2 materialized, 0 unchanged.'),
        'and it renders as the same RESOLVED rows and `N materialized, M unchanged` summary the resolver already '
        . 'prints elsewhere, prefixed with the worktree it was for — one vocabulary for one piece of work'
    );
    ob_start();
    \WPrism\Orchestrator\CodeResolveCommand::renderRefreshPhase(['plan_path' => '/x'], 'refresh');
    ok_refresh(
        (string) ob_get_clean() === '',
        'a result with nothing resolved prints NOTHING, so a format-1 refresh keeps its output byte-identical'
    );

    // A component the lock declares but the host cannot produce is a TYPED
    // refusal, not a compile-time surprise: the archive resolves and unpacks,
    // and its tree does not hash to what the lock declares. Raised in the
    // parent process precisely so the reason code survives — the compile worker
    // boundary flattens a refusal to its message (issue #3524).
    $driftRelease = $publish('plugin', 'acme-drift', '3.0', 'acme-drift.php', "<?php // drift\n");
    $driftPair = $buildPair(
        'drift',
        ['format' => 2, 'layout' => 'wp-content', 'lock' => \WPrism\CodeSourceLock::PATH, 'source' => 'code/wp-content'],
        [[
            'component' => 'acme-drift',
            'origin' => ['archive_sha256' => $driftRelease['archive_sha256'], 'kind' => 'wp-org-release',
                'url' => $driftRelease['url']],
            'root' => 'plugins',
            // The archive is genuine and its archive_sha256 matches; only the
            // declared TREE digest is wrong, so the refusal is the resolver's
            // own verification and not a transport failure.
            'tree_sha256' => str_repeat('d', 64),
            'version' => '3.0',
        ]],
        [\WPrism\CodeSourceLock::gitignore_line('plugins', 'acme-drift')]
    );
    $plant($driftPair['target'], 'code/wp-content/plugins/acme-drift/acme-drift.php', "<?php // drift\n");
    chdir($driftPair['source']);
    $driftRefusal = null;
    try {
        Refresh::refresh(new RefreshTargetTransport($driftPair['target'], $export), 'production');
    } catch (\WPrism\CommandRefusalException $e) {
        $driftRefusal = $e;
    }
    ok_refresh(
        $driftRefusal instanceof \WPrism\CommandRefusalException
            && $driftRefusal->reasonCode === \WPrism\Orchestrator\CodeResolver::REASON_TREE_DIGEST_MISMATCH,
        'a component the host cannot resolve refuses with the resolver\'s own typed reason code '
        . '(code_resolve_tree_digest_mismatch) rather than as a bare compile failure — got '
        . ($driftRefusal === null ? 'no refusal at all' : $driftRefusal->reasonCode)
    );
    ok_refresh(
        $driftRefusal !== null && $driftRefusal->publicMessage !== '' && $driftRefusal->remediation !== ''
            && !str_contains($driftRefusal->publicMessage, $driftPair['source']),
        'and it carries the reviewed public message and remedy the resolver already owns, with no repository path in them'
    );
    // The materialization runs INSIDE the try whose finally removes the three
    // worktrees. Placed one line above it — the obvious spot, right after the
    // `worktree add` calls — a refusal would leak all three and leave a scratch
    // directory the outer finally cannot rmdir.
    ok_refresh(
        count(preg_grep('/^worktree /', preg_split('/\r?\n/', run_refresh(['git', 'worktree', 'list', '--porcelain'], $driftPair['source'])) ?: [])) === 1
            && (glob($driftPair['source'] . '/.git/wprism-refresh/scratch/*') ?: []) === [],
        'and the refusal leaves no worktree and no scratch directory behind: the three compile worktrees are '
        . 'created before it and removed by the same finally that cleans a compile failure'
    );

    // Format 1 takes none of this path at all: declaredLock() answers null for
    // a fully vendored repository, so materializeLockedCode() returns before it
    // constructs a resolver or reads a cache.
    ok_refresh(
        \WPrism\Orchestrator\CodeResolver::declaredLock($vendoredPair['source']) === null
            && \WPrism\Orchestrator\CodeResolver::declaredLock($splitPair['source']) !== null,
        'a fully vendored (format 1) repository declares no lock, so the materialization is a no-op there by '
        . 'construction rather than by a branch that could be got wrong'
    );

    chdir($old);
    echo "PASS: refresh host orchestration regression\n";
} finally {
    if (isset($old) && getcwd() !== $old) chdir($old);
    remove_refresh($tmp);
}
}
