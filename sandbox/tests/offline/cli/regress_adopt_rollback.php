<?php
declare(strict_types=1);

/**
 * Offline regression for the adoption double-failure boundary.
 *
 * The deterministic transport drives the complete product transaction through
 * archive creation, upload, and remote swap, then throws during the first
 * post-swap verification while the exact rollback command also fails. Before
 * issue #3309, Adopt's finally block discarded that rollback result and surfaced
 * only the verification exception.
 */

// WP-4.12: the fixture transport below reports the agent version the remote
// says it is running, and `Adopt::install()` compares it against the version
// in THIS checkout. A literal there is a copy of `agent/wprism.php`'s define, so
// it reads as an adoption failure the day the define moves rather than as a
// stale fixture. Derived from the source of record instead.
require dirname(__DIR__, 2) . '/lib/agent_version.php';
wprism_test_define_agent_versions();

require dirname(__DIR__, 4) . '/cli/src/Transport/Transport.php';
require dirname(__DIR__, 4) . '/recovery/rollback-control.php';
require dirname(__DIR__, 4) . '/cli/src/Onboarding/Adopt.php';

use WPrism\Orchestrator\AdoptionTransport;
use WPrism\Orchestrator\Adopt;

final class AdoptDoubleFailureTransport implements AdoptionTransport {
    /** @var list<string> */
    public array $rawScripts = [];
    public int $wpCalls = 0;
    public int $uploads = 0;

    public function bootstrapCapability(): array {
        return ['supported' => true, 'reason' => 'fixture adoption transport', 'remediation' => ''];
    }

    public function repoPath(): string {
        return '/fixture/repo';
    }

    public function wpPath(): string {
        return '/fixture/wordpress';
    }

    public function captureRaw(string $script): array {
        $this->rawScripts[] = $script;
        if ($script === 'echo wprism-reachable') {
            return ['exit' => 0, 'stdout' => "wprism-reachable\n", 'stderr' => ''];
        }
        if (str_contains($script, 'wprism-install-complete')) {
            return ['exit' => 0, 'stdout' => "wprism-repo-retained\nwprism-install-complete\n", 'stderr' => ''];
        }
        if (str_contains($script, 'txn=') && str_contains($script, '.wprism-adopt-txn-')) {
            return ['exit' => 23, 'stdout' => '', 'stderr' => 'restore mv failed'];
        }
        if (str_starts_with($script, 'rm -f ')) {
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }
        return ['exit' => 91, 'stdout' => '', 'stderr' => 'unexpected raw fixture command'];
    }

    public function captureWp(array $wpArgs): array {
        $this->wpCalls++;
        if ($this->wpCalls === 1 && $wpArgs === ['core', 'is-installed']) {
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }
        if ($this->wpCalls === 2 && $wpArgs === ['eval', 'echo WPMU_PLUGIN_DIR;']) {
            return ['exit' => 0, 'stdout' => "/fixture/mu-plugins\n", 'stderr' => ''];
        }
        // Call 3 is the PRE-swap topology probe. Answered single-site so this
        // fixture still reaches the post-swap failure it exists to exercise.
        if ($this->wpCalls === 3 && str_contains((string) ($wpArgs[1] ?? ''), 'wprism-single-site')) {
            return ['exit' => 0, 'stdout' => "wprism-single-site\n", 'stderr' => ''];
        }
        if ($this->wpCalls === 4) {
            throw new \RuntimeException('post-swap verification exploded');
        }
        return ['exit' => 92, 'stdout' => '', 'stderr' => 'unexpected wp fixture command'];
    }

    public function uploadFile(string $localPath, string $remotePath): array {
        $this->uploads++;
        if (!is_file($localPath) || !str_starts_with($remotePath, '/tmp/wprism-adopt-')) {
            return ['exit' => 93, 'stdout' => '', 'stderr' => 'invalid upload fixture arguments'];
        }
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }
}

final class AdoptCommittedCleanupFailureTransport implements AdoptionTransport {
    /** @var list<string> */
    public array $rawScripts = [];
    public int $wpCalls = 0;

    public function bootstrapCapability(): array {
        return ['supported' => true, 'reason' => 'fixture adoption transport', 'remediation' => ''];
    }
    public function repoPath(): string { return '/fixture/repo'; }
    public function wpPath(): string { return '/fixture/wordpress'; }
    public function uploadFile(string $localPath, string $remotePath): array {
        return is_file($localPath)
            ? ['exit' => 0, 'stdout' => '', 'stderr' => '']
            : ['exit' => 93, 'stdout' => '', 'stderr' => 'missing fixture upload'];
    }
    public function captureWp(array $wpArgs): array {
        $this->wpCalls++;
        // Call 3 is the pre-swap topology probe; the version and policy probes
        // shift one place behind it.
        return match ($this->wpCalls) {
            1 => ['exit' => 0, 'stdout' => '', 'stderr' => ''],
            2 => ['exit' => 0, 'stdout' => "/fixture/mu-plugins\n", 'stderr' => ''],
            3 => ['exit' => 0, 'stdout' => "wprism-single-site\n", 'stderr' => ''],
            4 => ['exit' => 0, 'stdout' => WPRISM_AGENT_VERSION . "\n", 'stderr' => ''],
            5 => ['exit' => 0, 'stdout' => "wprism-policy-ok\n", 'stderr' => ''],
            default => ['exit' => 94, 'stdout' => '', 'stderr' => 'unexpected wp fixture call'],
        };
    }
    public function captureRaw(string $script): array {
        $this->rawScripts[] = $script;
        if ($script === 'echo wprism-reachable') {
            return ['exit' => 0, 'stdout' => "wprism-reachable\n", 'stderr' => ''];
        }
        if (str_contains($script, 'wprism-install-complete')) {
            return ['exit' => 0, 'stdout' => "wprism-repo-retained\nwprism-install-complete\n", 'stderr' => ''];
        }
        if (str_contains($script, 'rollback-control.php') && str_contains($script, ' status --root=')) {
            return ['exit' => 0, 'stdout' => "{}\n", 'stderr' => ''];
        }
        if (str_contains($script, 'wprism-adopt-commit-barrier')) {
            return ['exit' => 0, 'stdout' => "wprism-adopt-commit-barrier\n", 'stderr' => ''];
        }
        if (str_contains($script, 'committed install retained partial backup cleanup evidence')) {
            return ['exit' => 73, 'stdout' => '', 'stderr' => 'fixture backup became undeletable'];
        }
        if (str_starts_with($script, 'rm -f ')) {
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }
        return ['exit' => 95, 'stdout' => '', 'stderr' => 'unexpected raw fixture command'];
    }
}

/**
 * Runs the generated shell transaction against an isolated local filesystem.
 * Its optional shims deliberately rebind inodes after a move, after content
 * population, or after a journal-container child publication. They reproduce
 * the Docker Desktop identity boundaries without Docker, SSH, or a
 * mount-specific fixture.
 */
final class AdoptFilesystemTransactionTransport implements AdoptionTransport {
    public bool $forceCopyUnlink = false;
    public bool $rebindLoaderAfterPopulation = false;
    public bool $rebindLockAfterChildPublish = false;
    public bool $rebindTxnAfterChildPublish = false;
    public bool $failPolicy = false;
    public bool $retainCommittedCleanup = false;
    public ?string $interruptPhase = null;

    /** @var list<string> */
    public array $rawScripts = [];
    /** @var list<string> */
    public array $uploadedArchives = [];
    /** @var list<string> */
    public array $uploadedMembers = [];

    private string $root;
    private string $muDir;
    private string $repo;
    private string $wp;
    private string $version;

    public function __construct(string $root, string $sourceRoot) {
        $this->root = $root;
        $this->muDir = $root . '/mu';
        $this->repo = $root . '/repo';
        $this->wp = $root . '/wordpress';
        $source = file_get_contents($sourceRoot . '/agent/wprism.php');
        if (!is_string($source) || preg_match("/define\\(\\s*'WPRISM_AGENT_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $source, $m) !== 1) {
            throw new \RuntimeException('could not read fixture agent version');
        }
        $this->version = $m[1];

        foreach ([$this->muDir, $this->repo, $this->wp, $root . '/bin'] as $path) {
            if (!mkdir($path, 0700, true) && !is_dir($path)) {
                throw new \RuntimeException('could not create adoption filesystem fixture');
            }
        }
        foreach ([$this->muDir . '/wprism', $this->repo . '/.wprism'] as $path) {
            if (!mkdir($path, 0700, true) && !is_dir($path)) {
                throw new \RuntimeException('could not create prior adoption surface');
            }
        }
        file_put_contents($this->muDir . '/wprism/legacy-agent.txt', "legacy-agent\n");
        file_put_contents($this->muDir . '/wprism-loader.php', "<?php // legacy loader\n");
        file_put_contents($this->repo . '/.wprism/legacy-state.txt', "legacy-state\n");
        file_put_contents($this->repo . '/site.wprism.json', "{}\n");
        $this->writePopulationRebindCpShim($root . '/bin/cp');
        $this->writeCopyUnlinkMvShim($root . '/bin/mv');
    }

    public function muDir(): string {
        return $this->muDir;
    }

    public function bootstrapCapability(): array {
        return ['supported' => true, 'reason' => 'isolated adoption filesystem fixture', 'remediation' => ''];
    }

    public function repoPath(): string {
        return $this->repo;
    }

    public function wpPath(): string {
        return $this->wp;
    }

    public function captureRaw(string $script): array {
        $this->rawScripts[] = $script;
        if ($this->retainCommittedCleanup
            && str_contains($script, 'committed install retained partial backup cleanup evidence')) {
            return ['exit' => 73, 'stdout' => '', 'stderr' => 'fixture retained committed cleanup evidence'];
        }

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['PATH'] = $this->root . '/bin:' . ($environment['PATH'] ?? '/usr/bin:/bin');
        $environment['WPRISM_ADOPT_FORCE_COPY_MOVE'] = $this->forceCopyUnlink ? '1' : '0';
        $environment['WPRISM_ADOPT_FORCE_COPY_MOVE_ROOT'] = $this->root;
        $environment['WPRISM_ADOPT_REBIND_LOADER_AFTER_POPULATION'] = $this->rebindLoaderAfterPopulation ? '1' : '0';
        $environment['WPRISM_ADOPT_REBIND_LOCK_AFTER_CHILD_PUBLISH'] = $this->rebindLockAfterChildPublish ? '1' : '0';
        $environment['WPRISM_ADOPT_REBIND_TXN_AFTER_CHILD_PUBLISH'] = $this->rebindTxnAfterChildPublish ? '1' : '0';
        $environment['WPRISM_ADOPT_REBIND_ROOT'] = $this->root;
        if ($this->interruptPhase === null) {
            unset($environment['WPRISM_TEST_MODE'], $environment['WPRISM_TEST_ADOPT_FAIL_PHASE']);
        } else {
            $environment['WPRISM_TEST_MODE'] = '1';
            $environment['WPRISM_TEST_ADOPT_FAIL_PHASE'] = $this->interruptPhase;
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open('/bin/sh -s', $descriptors, $pipes, $this->root, $environment);
        if (!is_resource($process)) {
            return ['exit' => 255, 'stdout' => '', 'stderr' => 'could not run isolated adoption shell'];
        }
        fwrite($pipes[0], $script);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    public function captureWp(array $wpArgs): array {
        if ($wpArgs === ['core', 'is-installed']) {
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }
        if ($wpArgs === ['eval', 'echo WPMU_PLUGIN_DIR;']) {
            return ['exit' => 0, 'stdout' => $this->muDir . "\n", 'stderr' => ''];
        }
        $code = $wpArgs[1] ?? '';
        // The pre-swap topology probe. Plain `wp eval` in BOTH branches, never
        // CodeDeploy::controlArgs(): that bootstrap requires the installed
        // agent, which does not exist yet at this point.
        if (($wpArgs[0] ?? '') === 'eval' && is_string($code) && str_contains($code, 'wprism-single-site')) {
            return ['exit' => 0, 'stdout' => "wprism-single-site\n", 'stderr' => ''];
        }
        if (($wpArgs[0] ?? '') === 'eval' && is_string($code) && str_contains($code, 'WPRISM_AGENT_VERSION')) {
            return ['exit' => 0, 'stdout' => $this->version . "\n", 'stderr' => ''];
        }
        if (($wpArgs[0] ?? '') === 'eval' && is_string($code) && str_contains($code, 'Policy::load')) {
            return $this->failPolicy
                ? ['exit' => 72, 'stdout' => '', 'stderr' => 'wprism: invalid JSON: Syntax error']
                : ['exit' => 0, 'stdout' => "wprism-policy-ok\n", 'stderr' => ''];
        }
        return ['exit' => 96, 'stdout' => '', 'stderr' => 'unexpected isolated wp fixture command'];
    }

    public function uploadFile(string $localPath, string $remotePath): array {
        $members = [];
        exec('tar -tf ' . escapeshellarg($localPath), $members, $status);
        if ($status !== 0) {
            return ['exit' => 98, 'stdout' => '', 'stderr' => 'could not inspect isolated adoption archive'];
        }
        $this->uploadedMembers = array_values(array_filter($members, 'is_string'));
        if (!is_file($localPath) || !str_starts_with($remotePath, '/tmp/wprism-adopt-') || !copy($localPath, $remotePath)) {
            return ['exit' => 97, 'stdout' => '', 'stderr' => 'could not stage isolated adoption archive'];
        }
        $this->uploadedArchives[] = $remotePath;
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }

    private function writePopulationRebindCpShim(string $path): void {
        $script = <<<'SH'
#!/bin/sh
if [ "${WPRISM_ADOPT_REBIND_LOADER_AFTER_POPULATION:-}" = 1 ] && [ "$#" -eq 2 ]; then
    fixture_root=${WPRISM_ADOPT_REBIND_ROOT:?}
    case "$2" in
        "$fixture_root/mu/.wprism-loader-new-"*)
            /bin/cp "$@" || exit $?
            rebound="${2}.rebound-$$"
            [ ! -e "$rebound" ] && [ ! -L "$rebound" ] || exit 96
            /bin/cp -p "$2" "$rebound" || exit $?
            /bin/mv "$rebound" "$2" || exit $?
            : > "$fixture_root/loader-population-rebound"
            exit 0
            ;;
    esac
fi
exec /bin/cp "$@"
SH;
        if (file_put_contents($path, $script) === false || !chmod($path, 0700)) {
            throw new \RuntimeException('could not write population-rebind cp fixture');
        }
    }

    private function writeCopyUnlinkMvShim(string $path): void {
        $script = <<<'SH'
#!/bin/sh
if [ "$#" -eq 2 ]; then
    fixture_root=${WPRISM_ADOPT_REBIND_ROOT:-}
    rebind_parent=""
    rebind_marker=""
    if [ "${WPRISM_ADOPT_REBIND_LOCK_AFTER_CHILD_PUBLISH:-}" = 1 ]; then
        case "$2" in
            "$fixture_root/mu/.wprism-adopt-lock/"*)
                rebind_parent="$fixture_root/mu/.wprism-adopt-lock"
                rebind_marker="$fixture_root/lock-container-rebound"
                ;;
        esac
    fi
    if [ -z "$rebind_parent" ] && [ "${WPRISM_ADOPT_REBIND_TXN_AFTER_CHILD_PUBLISH:-}" = 1 ]; then
        case "$2" in
            "$fixture_root/mu/.wprism-adopt-txn-"*/*)
                rebind_parent=${2%/*}
                rebind_marker="$fixture_root/txn-container-rebound"
                ;;
        esac
    fi
    if [ -n "$rebind_parent" ]; then
        /bin/mv "$@" || exit $?
        rebound="${rebind_parent}.rebound-$$"
        [ ! -e "$rebound" ] && [ ! -L "$rebound" ] || exit 98
        mkdir "$rebound" \
            && /bin/cp -pR "$rebind_parent/." "$rebound/" \
            && /bin/rm -rf "$rebind_parent" \
            && /bin/mv "$rebound" "$rebind_parent" \
            && : > "$rebind_marker" \
            || exit 99
        exit 0
    fi
fi
if [ "${WPRISM_ADOPT_FORCE_COPY_MOVE:-}" = 1 ] && [ "$#" -eq 2 ]; then
    fixture_root=${WPRISM_ADOPT_FORCE_COPY_MOVE_ROOT:?}
    case "$1" in
        "$fixture_root/mu/wprism"|"$fixture_root/mu/wprism-loader.php"|"$fixture_root/repo/.wprism"|\
        "$fixture_root/mu/.wprism-new-"*|"$fixture_root/mu/.wprism-loader-new-"*|"$fixture_root/repo/.wprism-new-"*|\
        "$fixture_root/mu/.wprism-old-"*|"$fixture_root/mu/.wprism-loader-old-"*|"$fixture_root/repo/.wprism-old-"*)
            if [ -d "$1" ]; then
                mkdir "$2" && cp -pR "$1/." "$2/" && rm -rf "$1"
            else
                cp -p "$1" "$2" && rm -f "$1"
            fi
            exit $?
            ;;
    esac
fi
exec /bin/mv "$@"
SH;
        if (file_put_contents($path, $script) === false || !chmod($path, 0700)) {
            throw new \RuntimeException('could not write copy-unlink mv fixture');
        }
    }
}

function adopt_remove_fixture(string $root): void {
    $prefix = rtrim(sys_get_temp_dir(), '/') . '/wprism-adopt-regress-';
    if (!str_starts_with($root, $prefix) || !is_dir($root)) {
        throw new \RuntimeException('refusing to remove an unexpected adoption fixture');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        if ($entry->isLink() || $entry->isFile()) {
            unlink($path);
        } else {
            rmdir($path);
        }
    }
    rmdir($root);
}

function adopt_tree_hash(string $root): string {
    $rows = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen(rtrim($root, '/')) + 1);
        if ($entry->isLink()) {
            $rows[] = ['link', $relative, readlink($entry->getPathname())];
        } elseif ($entry->isDir()) {
            $rows[] = ['dir', $relative];
        } else {
            $rows[] = ['file', $relative, hash_file('sha256', $entry->getPathname())];
        }
    }
    sort($rows, SORT_REGULAR);
    return hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function adopt_check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "ok: $message\n";
}

/** @param list<string> $needles */
function adopt_assert_ordered(string $script, array $needles, string $message): void {
    $previous = -1;
    $ordered = true;
    foreach ($needles as $needle) {
        $position = strpos($script, $needle);
        if (!is_int($position) || $position <= $previous) {
            $ordered = false;
            break;
        }
        $previous = $position;
    }
    adopt_check($ordered, $message);
}

$installScript = new ReflectionMethod(Adopt::class, 'installScript');
$recoveryConfig = [
    'adapters' => [],
    'checkpoint_provider' => [PHP_BINARY, '/fixture/checkpoint-provider.php'],
    'exclusion_provider' => [PHP_BINARY, '/fixture/exclusion-provider.php'],
    'format' => 'wprism-recovery-config/v1',
    'timeout_seconds' => 5,
];
$authorityInstall = (string) $installScript->invoke(
    null,
    '/tmp/fixture-adopt.tar',
    '/fixture/mu-plugins',
    '/fixture/repo',
    '0123456789abcdef01234567',
    'fixture-key',
    base64_encode(str_repeat('k', 32)),
    $recoveryConfig,
    null
);
$controlConfigOffset = strpos($authorityInstall, 'scoped-promotion-control.json');
$agentSwapOffset = strpos($authorityInstall, 'move_owned "$agent_new" "$agent" "$txn/agent_new.id" "$txn/agent_live_post.id"');
adopt_check(
    is_int($controlConfigOffset) && is_int($agentSwapOffset) && $controlConfigOffset < $agentSwapOffset
        && str_contains($authorityInstall, '"control_root":"/fixture/repo/.wprism/control"')
        && str_contains($authorityInstall, 'source artifact contains target-local scoped promotion configuration')
        && str_contains($authorityInstall, 'chmod 600 "$agent_new/scoped-promotion-control.json"')
        && str_contains($authorityInstall, '"$txn/rollback_ready"')
        && str_contains($authorityInstall, '"$txn/agent_old_post.id"'),
    'adoption refuses source-supplied trust roots and journals immutable pre/post proofs before publishing the agent'
);
$ordinaryInstall = (string) $installScript->invoke(
    null,
    '/tmp/fixture-adopt.tar',
    '/fixture/mu-plugins',
    '/fixture/repo',
    '0123456789abcdef01234567',
    null,
    null,
    null,
    null
);
adopt_check(
    !str_contains($ordinaryInstall, 'wprism-scoped-promotion-control/v1')
        && !str_contains($ordinaryInstall, 'chmod 600 "$agent_new/scoped-promotion-control.json"'),
    'adoption without a verified recovery configuration exposes no scoped-promotion trust root'
);
adopt_assert_ordered(
    $authorityInstall,
    [
        'record_identity "$stage" "$txn/stage_construction.id"',
        'tar --no-same-owner -xf "$archive" -C "$stage"',
        'chmod 600 "$stage/recovery-config.json"',
        'record_identity "$stage" "$txn/stage.id"',
    ],
    'the staged artifact identity is bound only after extraction and generated recovery configuration complete'
);
adopt_assert_ordered(
    $authorityInstall,
    [
        'record_identity "$agent_new" "$txn/agent_new_construction.id"',
        'cp -R "$stage/agent/." "$agent_new/"',
        'chmod 600 "$agent_new/scoped-promotion-control.json"',
        'record_identity "$agent_new" "$txn/agent_new.id"',
    ],
    'the agent publish proof follows copied bytes and target-local configuration'
);
adopt_assert_ordered(
    $ordinaryInstall,
    [
        'record_identity "$loader_new" "$txn/loader_new_construction.id"',
        'cp "$stage/agent/wprism-loader.php" "$loader_new"',
        'record_identity "$loader_new" "$txn/loader_new.id"',
    ],
    'the loader publish proof follows its content population'
);
adopt_check(
    !str_contains($ordinaryInstall, 'manifest_new')
        && !str_contains($ordinaryInstall, '$stage/manifests/.'),
    'adoption publishes only the staged embedded adapter-library and control authority surfaces'
);
adopt_assert_ordered(
    $authorityInstall,
    [
        'record_identity "$wprism_new" "$txn/wprism_new_construction.id"',
        'cp -R "$stage/recovery" "$runtime_new"',
        'recovery-probe --root="$control_new"',
        'record_identity "$wprism_new" "$txn/wprism_new.id"',
    ],
    'the authority publish proof follows state copy, runtime initialization, and recovery configuration'
);
adopt_assert_ordered(
    $ordinaryInstall,
    [
        'record_identity "$site_new" "$txn/site_new_construction.id"',
        '> "$site_new"; record_identity "$site_new" "$txn/site_new.id"',
        'site seed publish source',
    ],
    'the seed publish proof follows seed-byte population'
);

$transport = new AdoptDoubleFailureTransport();
$caught = null;
try {
    Adopt::install($transport, dirname(__DIR__, 4));
} catch (\Throwable $error) {
    $caught = $error;
}

adopt_check($caught instanceof \RuntimeException, 'the post-swap double failure is surfaced');
adopt_check(
    str_contains($caught->getMessage(), 'post-swap verification exploded'),
    'the original post-swap verification failure remains visible'
);
adopt_check(
    str_contains($caught->getMessage(), 'adoption rollback could not be confirmed: restore mv failed'),
    'the failed restore is appended to the surfaced error'
);
adopt_check(
    $caught->getPrevious() instanceof \RuntimeException
        && $caught->getPrevious()->getMessage() === 'post-swap verification exploded',
    'the original exception remains chained for programmatic diagnostics'
);
adopt_check($transport->uploads === 1, 'the regression reaches the product archive-upload boundary');
adopt_check(
    count(array_filter($transport->rawScripts, static fn(string $script): bool =>
        !str_contains($script, 'wprism-install-complete')
            && str_contains($script, 'txn=')
            && str_contains($script, '.wprism-adopt-txn-'))) === 1,
    'the exception path attempts the exact adoption rollback once'
);
adopt_check(
    count(array_filter($transport->rawScripts, static fn(string $script): bool =>
        str_starts_with($script, 'rm -f '))) === 1,
    'remote archive cleanup still runs before the combined failure surfaces'
);

$cleanupTransport = new AdoptCommittedCleanupFailureTransport();
$cleanupResult = Adopt::install($cleanupTransport, dirname(__DIR__, 4));
adopt_check($cleanupResult['exit'] === 0 && $cleanupResult['phase'] === 'complete', 'backup cleanup failure cannot reverse a committed green install');
adopt_check(
    str_contains($cleanupResult['stderr'], 'retained adoption cleanup evidence for operator recovery'),
    'committed cleanup failure returns bounded retained-evidence guidance'
);
adopt_check(
    count(array_filter($cleanupTransport->rawScripts, static fn(string $script): bool =>
        str_contains($script, 'live transaction identity changed before rollback'))) === 0,
    'no rollback is attempted after the commit barrier'
);
$barrierIndex = null;
$cleanupIndex = null;
$cleanupScript = null;
foreach ($cleanupTransport->rawScripts as $index => $script) {
    if (str_contains($script, 'wprism-adopt-commit-barrier')) {
        $barrierIndex = $index;
    }
    if (str_contains($script, 'committed install retained partial backup cleanup evidence')) {
        $cleanupIndex = $index;
        $cleanupScript = $script;
    }
}
adopt_check(
    is_int($barrierIndex) && is_int($cleanupIndex) && $barrierIndex < $cleanupIndex,
    'the mutation-free commit barrier precedes destructive backup cleanup'
);
adopt_check(
    is_string($cleanupScript)
        && strpos($cleanupScript, 'assert_journal_ready || { echo \'wprism adopt: committed cleanup journal is incomplete\'')
            < strpos($cleanupScript, 'if [ -e "$txn/had_wprism"')
        && strpos($cleanupScript, 'if [ -e "$txn/had_wprism"')
            < strpos($cleanupScript, 'rm -rf \'/fixture/repo/.wprism-old-'),
    'committed cleanup preflights the journal before deleting canonical staged backups'
);

$rollbackScript = (string) (new ReflectionMethod(Adopt::class, 'rollbackScript'))->invoke(
    null,
    '/fixture/mu-plugins',
    '/fixture/repo',
    '0123456789abcdef01234567'
);
$rollbackPreflight = strpos($rollbackScript, 'assert_journal_ready || { echo \'wprism adopt: transaction journal is incomplete before rollback');
$firstRollbackDelete = strpos($rollbackScript, 'remove_owned ');
adopt_check(
    is_int($rollbackPreflight) && is_int($firstRollbackDelete) && $rollbackPreflight < $firstRollbackDelete
        && str_contains($rollbackScript, 'transaction has crossed the commit barrier; retained evidence for operator recovery')
        && str_contains($rollbackScript, 'wprism_live_post.id')
        && str_contains($rollbackScript, 'wprism_old_post.id'),
    'rollback preflights the complete post-move journal before its first deletion and refuses a crossed commit barrier'
);
adopt_assert_ordered(
    $rollbackScript,
    [
        'restore_owned \'/fixture/repo/.wprism-old-',
        'remove_owned \'/fixture/mu-plugins/wprism-loader.php\'',
        'remove_owned \'/fixture/mu-plugins/wprism\'',
    ],
    'rollback restores the legacy library before it can restore the old loader and agent'
);

$sourceRoot = dirname(__DIR__, 4);
$filesystemFixture = rtrim(sys_get_temp_dir(), '/') . '/wprism-adopt-regress-' . bin2hex(random_bytes(8));
register_shutdown_function(static function () use ($filesystemFixture): void {
    if (is_dir($filesystemFixture)) {
        adopt_remove_fixture($filesystemFixture);
    }
});
$filesystemTransport = new AdoptFilesystemTransactionTransport($filesystemFixture, $sourceRoot);

$firstInstall = Adopt::install($filesystemTransport, $sourceRoot);
adopt_check($firstInstall['exit'] === 0, 'the real generated filesystem transaction installs an initial update');
adopt_check(
    is_file($filesystemTransport->muDir() . '/wprism/adapter-library/platform/core/manifest.json')
        && !file_exists($filesystemTransport->muDir() . '/manifests')
        && !is_link($filesystemTransport->muDir() . '/manifests'),
    'a committed update embeds the library in the agent and retires the flat library'
);
adopt_check(
    $filesystemTransport->uploadedMembers !== []
        && count(array_filter(
            $filesystemTransport->uploadedMembers,
            static fn(string $member): bool => !str_starts_with($member, 'agent/')
                && $member !== 'agent'
                && !str_starts_with($member, 'recovery/')
                && $member !== 'recovery'
        )) === 0
        && in_array('agent/adapter-library/platform/core/manifest.json', $filesystemTransport->uploadedMembers, true),
    'the controller archive allowlist contains only agent and recovery, with the assembled library inside agent'
);
$secondInstall = Adopt::install($filesystemTransport, $sourceRoot);
adopt_check($secondInstall['exit'] === 0, 'the real generated filesystem transaction supports an idempotent update');
adopt_check(!file_exists($filesystemTransport->muDir() . '/manifests'), 'an embedded-library update does not recreate flat manifests');

$mu = $filesystemTransport->muDir();
$repo = $filesystemTransport->repoPath();
file_put_contents($mu . '/wprism/rollback-sentinel.txt', "prior-agent\n");
file_put_contents($mu . '/wprism-loader.php', "<?php // prior loader\n");
file_put_contents($repo . '/.wprism/rollback-sentinel.txt', "prior-wprism-state\n");
$priorRoots = [
    'agent' => file_get_contents($mu . '/wprism/rollback-sentinel.txt'),
    'loader' => file_get_contents($mu . '/wprism-loader.php'),
    'wprism_state' => file_get_contents($repo . '/.wprism/rollback-sentinel.txt'),
];

$filesystemTransport->forceCopyUnlink = true;
$filesystemTransport->failPolicy = true;
$copyUnlinkFailure = Adopt::install($filesystemTransport, $sourceRoot);
adopt_check(
    $copyUnlinkFailure['exit'] !== 0 && $copyUnlinkFailure['phase'] === 'policy verification',
    'a policy-verification failure reaches rollback after controlled copy-unlink moves'
);
adopt_check(
    !str_contains($copyUnlinkFailure['stderr'], 'adoption rollback could not be confirmed'),
    'post-move proofs rebind every copied root so rollback remains confirmed'
);
adopt_check(
    file_get_contents($mu . '/wprism/rollback-sentinel.txt') === $priorRoots['agent']
        && file_get_contents($mu . '/wprism-loader.php') === $priorRoots['loader']
        && file_get_contents($repo . '/.wprism/rollback-sentinel.txt') === $priorRoots['wprism_state'],
    'copy-unlink policy rollback restores all three exact prior roots'
);
adopt_check(
    !is_dir($mu . '/.wprism-adopt-lock') && (glob($mu . '/.wprism-adopt-txn-*') ?: []) === [],
    'a confirmed copy-unlink rollback removes only its completed transaction evidence'
);

$populationFixture = rtrim(sys_get_temp_dir(), '/') . '/wprism-adopt-regress-' . bin2hex(random_bytes(8));
register_shutdown_function(static function () use ($populationFixture): void {
    if (is_dir($populationFixture)) {
        adopt_remove_fixture($populationFixture);
    }
});
$populationTransport = new AdoptFilesystemTransactionTransport($populationFixture, $sourceRoot);
$populationTransport->rebindLoaderAfterPopulation = true;
$populationTransport->failPolicy = true;
$populationMu = $populationTransport->muDir();
$populationRepo = $populationTransport->repoPath();
$populationPriorRoots = [
    'agent' => file_get_contents($populationMu . '/wprism/legacy-agent.txt'),
    'loader' => file_get_contents($populationMu . '/wprism-loader.php'),
    'wprism_state' => file_get_contents($populationRepo . '/.wprism/legacy-state.txt'),
];
$populationResult = Adopt::install($populationTransport, $sourceRoot);
adopt_check(
    is_file($populationFixture . '/loader-population-rebound'),
    'the fixture replaces the loader inode after cp finishes its content population'
);
adopt_check(
    $populationResult['exit'] !== 0
        && $populationResult['phase'] === 'policy verification'
        && !str_contains($populationResult['stderr'], 'loader source identity changed before publish'),
    'a populated-loader inode rebind reaches post-swap policy rollback instead of the old source-proof refusal'
);
adopt_check(
    !str_contains($populationResult['stderr'], 'adoption rollback could not be confirmed'),
    'the final loader source proof permits a confirmed rollback after a populated-loader inode rebind'
);
adopt_check(
    file_get_contents($populationMu . '/wprism/legacy-agent.txt') === $populationPriorRoots['agent']
        && file_get_contents($populationMu . '/wprism-loader.php') === $populationPriorRoots['loader']
        && file_get_contents($populationRepo . '/.wprism/legacy-state.txt') === $populationPriorRoots['wprism_state'],
    'the populated-loader rebind rollback restores all three exact prior roots'
);
adopt_check(
    !is_dir($populationMu . '/.wprism-adopt-lock')
        && (glob($populationMu . '/.wprism-adopt-txn-*') ?: []) === [],
    'the populated-loader rebind does not retain a completed rollback journal'
);

$filesystemTransport->forceCopyUnlink = false;
$filesystemTransport->failPolicy = false;
$filesystemTransport->interruptPhase = 'agent-live-after-move';
$interruptedInstall = Adopt::install($filesystemTransport, $sourceRoot);
adopt_check(
    $interruptedInstall['exit'] !== 0 && $interruptedInstall['phase'] === 'remote install'
        && str_contains($interruptedInstall['stderr'], 'incomplete surface-move journal retained for operator recovery'),
    'an interruption after the move but before its post-proof retains the transaction for recovery'
);
$interruptedTransactions = glob($mu . '/.wprism-adopt-txn-*', GLOB_ONLYDIR) ?: [];
adopt_check(count($interruptedTransactions) === 1, 'the interrupted transaction journal remains present');
$interruptedTxn = $interruptedTransactions[0];
$interruptedToken = substr(basename($interruptedTxn), strlen('.wprism-adopt-txn-'));
adopt_check(
    is_dir($mu . '/.wprism-adopt-lock')
        && is_file($interruptedTxn . '/agent_move_intent')
        && is_file($interruptedTxn . '/agent_old_post.id')
        && !file_exists($interruptedTxn . '/agent_live_post.id')
        && is_dir($mu . '/.wprism-old-' . $interruptedToken)
        && file_get_contents($mu . '/.wprism-old-' . $interruptedToken . '/rollback-sentinel.txt') === $priorRoots['agent'],
    'the interrupted before-postproof state retains old backup, intent, lock, and immutable evidence without deletion'
);

$filesystemTransport->interruptPhase = null;
$refusedInstall = Adopt::install($filesystemTransport, $sourceRoot);
adopt_check(
    $refusedInstall['exit'] !== 0 && $refusedInstall['phase'] === 'remote install'
        && str_contains($refusedInstall['stderr'], 'another adoption is active or requires operator recovery'),
    'the next adoption refuses an incomplete journal instead of guessing a rollback'
);
adopt_check(
    is_dir($mu . '/.wprism-adopt-lock') && is_dir($interruptedTxn)
        && is_dir($mu . '/.wprism-old-' . $interruptedToken),
    'the refusal leaves the interrupted evidence untouched'
);

$commitFixture = rtrim(sys_get_temp_dir(), '/') . '/wprism-adopt-regress-' . bin2hex(random_bytes(8));
register_shutdown_function(static function () use ($commitFixture): void {
    if (is_dir($commitFixture)) {
        adopt_remove_fixture($commitFixture);
    }
});
$commitTransport = new AdoptFilesystemTransactionTransport($commitFixture, $sourceRoot);
$commitTransport->retainCommittedCleanup = true;
$commitResult = Adopt::install($commitTransport, $sourceRoot);
adopt_check(
    $commitResult['exit'] === 0 && str_contains($commitResult['stderr'], 'retained adoption cleanup evidence for operator recovery'),
    'a response-loss fixture retains a committed transaction after the remote commit barrier'
);
$commitTransactions = glob($commitTransport->muDir() . '/.wprism-adopt-txn-*', GLOB_ONLYDIR) ?: [];
adopt_check(count($commitTransactions) === 1 && is_file($commitTransactions[0] . '/commit_started'), 'the retained transaction records its commit barrier');
$commitTxn = $commitTransactions[0];
$commitToken = substr(basename($commitTxn), strlen('.wprism-adopt-txn-'));
$commitAgent = $commitTransport->muDir() . '/wprism/wprism.php';
$committedAgentSource = file_get_contents($commitAgent);
$commitRollback = (string) (new ReflectionMethod(Adopt::class, 'rollbackScript'))->invoke(
    null,
    $commitTransport->muDir(),
    $commitTransport->repoPath(),
    $commitToken
);
$commitRollbackResult = $commitTransport->captureRaw($commitRollback);
adopt_check(
    $commitRollbackResult['exit'] !== 0
        && str_contains($commitRollbackResult['stderr'], 'transaction has crossed the commit barrier')
        && file_get_contents($commitAgent) === $committedAgentSource
        && is_dir($commitTransport->muDir() . '/.wprism-old-' . $commitToken),
    'response loss after commit refuses rollback without mutating live roots or backups'
);

$lockContainerFixture = rtrim(sys_get_temp_dir(), '/') . '/wprism-adopt-regress-' . bin2hex(random_bytes(8));
register_shutdown_function(static function () use ($lockContainerFixture): void {
    if (is_dir($lockContainerFixture)) {
        adopt_remove_fixture($lockContainerFixture);
    }
});
$lockContainerTransport = new AdoptFilesystemTransactionTransport($lockContainerFixture, $sourceRoot);
$lockContainerTransport->rebindLockAfterChildPublish = true;
$lockContainerResult = Adopt::install($lockContainerTransport, $sourceRoot);
$lockContainerMu = $lockContainerTransport->muDir();
$lockContainerTxns = glob($lockContainerMu . '/.wprism-adopt-txn-*', GLOB_ONLYDIR) ?: [];
adopt_check(
    is_file($lockContainerFixture . '/lock-container-rebound'),
    'the fixture rebinds the lock container after an immutable child proof is published'
);
adopt_check(
    $lockContainerResult['exit'] !== 0
        && $lockContainerResult['phase'] === 'install commit'
        && str_contains($lockContainerResult['stderr'], 'transaction identity changed before commit')
        && str_contains($lockContainerResult['stderr'], 'adoption rollback could not be confirmed: wprism adopt: transaction lock identity changed before rollback'),
    'a rebinding lock container fails both commit and rollback rather than weakening its ownership fence'
);
adopt_check(
    is_dir($lockContainerMu . '/.wprism-adopt-lock')
        && count($lockContainerTxns) === 1
        && is_file($lockContainerMu . '/wprism/wprism.php')
        && count(glob($lockContainerMu . '/.wprism-old-*', GLOB_ONLYDIR) ?: []) === 1,
    'a rebinding lock container retains live roots, backups, lock, and journal for operator recovery'
);

$txnContainerFixture = rtrim(sys_get_temp_dir(), '/') . '/wprism-adopt-regress-' . bin2hex(random_bytes(8));
register_shutdown_function(static function () use ($txnContainerFixture): void {
    if (is_dir($txnContainerFixture)) {
        adopt_remove_fixture($txnContainerFixture);
    }
});
$txnContainerTransport = new AdoptFilesystemTransactionTransport($txnContainerFixture, $sourceRoot);
$txnContainerTransport->rebindTxnAfterChildPublish = true;
$txnContainerResult = Adopt::install($txnContainerTransport, $sourceRoot);
$txnContainerMu = $txnContainerTransport->muDir();
$txnContainerTxns = glob($txnContainerMu . '/.wprism-adopt-txn-*', GLOB_ONLYDIR) ?: [];
adopt_check(
    is_file($txnContainerFixture . '/txn-container-rebound'),
    'the fixture rebinds the transaction container after a journal child proof is published'
);
adopt_check(
    $txnContainerResult['exit'] !== 0
        && $txnContainerResult['phase'] === 'install commit'
        && str_contains($txnContainerResult['stderr'], 'transaction identity changed before commit')
        && str_contains($txnContainerResult['stderr'], 'adoption rollback could not be confirmed: wprism adopt: transaction journal identity changed before rollback'),
    'a rebinding transaction container fails both commit and rollback rather than weakening its journal fence'
);
adopt_check(
    is_dir($txnContainerMu . '/.wprism-adopt-lock')
        && count($txnContainerTxns) === 1
        && is_file($txnContainerMu . '/wprism/wprism.php')
        && count(glob($txnContainerMu . '/.wprism-old-*', GLOB_ONLYDIR) ?: []) === 1,
    'a rebinding transaction container retains live roots, backups, lock, and journal for operator recovery'
);

echo "REGRESS_ADOPT_ROLLBACK PASSED\n";
