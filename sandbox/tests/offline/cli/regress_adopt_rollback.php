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

    public function __construct(private string $distributionSha256) {}

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
        if (str_contains($script, 'loader_generation_fence=absent')) {
            return ['exit' => 0, 'stdout' => "loader_generation_fence=absent\nloader_sha256=absent\n", 'stderr' => ''];
        }
        if (str_contains($script, 'agent/scoped-promotion-control.json')
            && str_contains($script, 'hash_final($ctx)')) {
            return ['exit' => 0, 'stdout' => $this->distributionSha256, 'stderr' => ''];
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

    public function __construct(private string $distributionSha256) {}

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
        if (str_contains($script, 'loader_generation_fence=absent')) {
            return ['exit' => 0, 'stdout' => "loader_generation_fence=absent\nloader_sha256=absent\n", 'stderr' => ''];
        }
        if (str_contains($script, 'agent/scoped-promotion-control.json')
            && str_contains($script, 'hash_final($ctx)')) {
            return ['exit' => 0, 'stdout' => $this->distributionSha256, 'stderr' => ''];
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
        copy(
            dirname(__DIR__, 2) . '/fixtures/legacy-wprism-loader.php',
            $this->muDir . '/wprism-loader.php'
        );
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
        // This existing-update fixture keeps the target-loaded pre-swap
        // probe; fresh initial eligibility has its own isolated bootstrap.
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
$absentLoaderProbe = ['state' => 'absent', 'sha256' => 'absent'];
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
    null,
    $absentLoaderProbe,
    false
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
    null,
    $absentLoaderProbe,
    false
);
adopt_check(
    !str_contains($ordinaryInstall, 'wprism-scoped-promotion-control/v1')
        && !str_contains($ordinaryInstall, 'chmod 600 "$agent_new/scoped-promotion-control.json"')
        && str_contains($ordinaryInstall, 'chmod 0755 "$agent_new"')
        && str_contains($ordinaryInstall, 'find "$agent_new" -type d -exec chmod 0755')
        && str_contains($ordinaryInstall, 'find "$agent_new" -type f -exec chmod 0644')
        && str_contains($ordinaryInstall, 'chmod 0644 "$loader_new"'),
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
        'find "$agent_new" -type d -exec chmod 0755',
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
        'chmod 0644 "$loader_new"',
        'record_identity "$loader_new" "$txn/loader_new.id"',
    ],
    'the loader publish proof follows its content population'
);
adopt_assert_ordered(
    $ordinaryInstall,
    [
        'record_identity "$loader_new" "$txn/loader_new.id"',
        'generation_lock_acquire 0',
        'generation_loader_probe=$(php -r ',
        'cp -Rp "$wprism_state/." "$wprism_new/"',
        'record_identity "$wprism_new" "$txn/wprism_new.id"',
        'begin_surface "$txn/agent_move_intent" agent',
        'move_owned "$loader_new" "$loader"',
        "generation_lock_release 1\nsuccess=1",
    ],
    'the generated adoption transaction owns the cross-operation fence and revalidates the host-bound loader before snapshotting authority or publishing'
);
adopt_check(
    str_contains($ordinaryInstall, 'generation_marker="$mu/.wprism-generation-writer-pending"')
        && str_contains($ordinaryInstall, '"$mu/.wprism-unadopt-lock"'),
    'the generated adoption script claims the shared writer intent and rejects an overlapping unadoption transaction'
);
adopt_check(
    !str_contains($ordinaryInstall, 'manifest_new')
        && !str_contains($ordinaryInstall, '$stage/manifests/.'),
    'adoption publishes only the staged embedded adapter-library and control authority surfaces'
);
adopt_assert_ordered(
    $authorityInstall,
    [
        '(umask 077; mkdir "$wprism_new")',
        'record_identity "$wprism_new" "$txn/wprism_new_construction.id"',
        'cp -Rp "$wprism_state/." "$wprism_new/"',
        'chmod 700 "$wprism_new"',
        'cp -R "$stage/recovery" "$runtime_new"',
        'recovery-probe --root="$control_new"',
        'record_identity "$wprism_new" "$txn/wprism_new.id"',
    ],
    'the authority root is private before and after preserved state copy, and its publish proof follows runtime initialization and recovery configuration'
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

$fixtureDistribution = Adopt::distributionDigest(dirname(__DIR__, 4));
$transport = new AdoptDoubleFailureTransport($fixtureDistribution);
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

$cleanupTransport = new AdoptCommittedCleanupFailureTransport($fixtureDistribution);
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

// Execute the initial-only writer, including its real generation lock and
// publication journal. A dispatcher proof is not permission to update an
// authority that appeared after the proof; preserve that actor's exact bytes.
$stageDistribution = new ReflectionMethod(Adopt::class, 'stageDistribution');
$removeStage = new ReflectionMethod(Adopt::class, 'removeLocalStage');
$initialDistribution = $stageDistribution->invoke(
    null, $sourceRoot, bin2hex(random_bytes(12)), $sourceRoot . '/tools/src/AdapterLibraryAssembler.php'
);
register_shutdown_function(static fn() => $removeStage->invoke(null, $initialDistribution['path']));
foreach (['pristine', 'before-agent', 'before-repo', 'after-control', 'after-state', 'after-repo-rebind'] as $initialCase) {
    $initialFixture = rtrim(sys_get_temp_dir(), '/') . '/wprism-adopt-regress-' . bin2hex(random_bytes(8));
    register_shutdown_function(static function () use ($initialFixture): void {
        if (is_dir($initialFixture)) adopt_remove_fixture($initialFixture);
    });
    $initialTransport = new AdoptFilesystemTransactionTransport($initialFixture, $sourceRoot);
    $initialMu = $initialTransport->muDir();
    $initialRepo = $initialTransport->repoPath();
    adopt_remove_fixture($initialMu . '/wprism');
    unlink($initialMu . '/wprism-loader.php');
    adopt_remove_fixture($initialRepo);
    $initialArchive = $initialFixture . '/candidate.tar';
    exec('COPYFILE_DISABLE=1 tar -C ' . escapeshellarg($initialDistribution['path'])
        . ' -cf ' . escapeshellarg($initialArchive) . ' agent recovery', $archiveLines, $archiveExit);
    adopt_check($archiveExit === 0, 'the initial writer fixture packages the actual distribution for ' . $initialCase);
    $initialToken = bin2hex(random_bytes(12));
    $initialScript = (string) $installScript->invoke(
        null, $initialArchive, $initialMu, $initialRepo, $initialToken,
        null, null, null, null, $absentLoaderProbe, false, true
    );
    $sentinel = null;
    if ($initialCase === 'before-agent' || $initialCase === 'before-repo') {
        $foreign = $initialCase === 'before-agent' ? $initialMu . '/wprism' : $initialRepo;
        mkdir($foreign, 0700);
        $sentinel = $foreign . '/sentinel';
        file_put_contents($sentinel, 'preserve');
    } elseif (str_starts_with($initialCase, 'after-')) {
        $foreign = match ($initialCase) {
            'after-control' => $initialMu . '/wprism-control',
            'after-state' => $initialRepo . '/.wprism',
            default => $initialRepo,
        };
        $sentinel = $foreign . '/sentinel';
        $injection = $initialCase === 'after-repo-rebind'
            ? 'mv ' . escapeshellarg($initialRepo) . ' ' . escapeshellarg($initialRepo . '.prior') . "\n"
            : '';
        $injection .= 'mkdir ' . escapeshellarg($foreign) . '; printf preserve > ' . escapeshellarg($sentinel) . "\n";
        $initialScript = str_replace("generation_lock_acquire 0\n", "generation_lock_acquire 0\n" . $injection,
            $initialScript, $injections);
        adopt_check($injections === 1, 'the authority-race fixture plants exactly at the real generation-writer boundary');
    }
    $initialBefore = [adopt_tree_hash($initialMu), is_dir($initialRepo) ? adopt_tree_hash($initialRepo) : null];
    $initialResult = $initialTransport->captureRaw($initialScript);
    if ($initialCase === 'pristine') {
        if ($initialResult['exit'] !== 0) fwrite(STDERR, json_encode($initialResult, JSON_THROW_ON_ERROR) . "\n");
        adopt_check($initialResult['exit'] === 0
            && str_contains($initialResult['stdout'], 'wprism-install-complete')
            && is_file($initialMu . '/wprism/wprism.php')
            && is_file($initialMu . '/wprism-loader.php')
            && is_file($initialRepo . '/.wprism/control/target.json')
            && is_file($initialRepo . '/site.wprism.json'),
            'the initial-only generation writer installs a genuinely absent repository and control plane');
        $barrier = new ReflectionMethod(Adopt::class, 'commitBarrierScript');
        $cleanup = new ReflectionMethod(Adopt::class, 'cleanupCommittedScript');
        adopt_check($initialTransport->captureRaw($barrier->invoke(null, $initialMu, $initialRepo, $initialToken))['exit'] === 0
            && $initialTransport->captureRaw($cleanup->invoke(null, $initialMu, $initialRepo, $initialToken))['exit'] === 0,
            'the initial-only generation writer crosses its commit barrier and releases transaction ownership');
        continue;
    }
    $expected = str_starts_with($initialCase, 'before-')
        ? 'initial control authority changed before staging'
        : ($initialCase === 'after-repo-rebind'
            ? 'initial repository authority changed before publication'
            : 'initial control authority changed before publication');
    adopt_check($initialResult['exit'] !== 0 && str_contains($initialResult['stderr'], $expected)
        && !str_contains($initialResult['stdout'], 'wprism-install-complete')
        && is_file($sentinel) && file_get_contents($sentinel) === 'preserve'
        && !file_exists($initialMu . '/wprism-loader.php')
        && !file_exists($initialRepo . '/site.wprism.json'),
        'the initial writer refuses ' . $initialCase . ' without publishing or erasing the competing authority');
    if (str_starts_with($initialCase, 'before-')) {
        adopt_check([adopt_tree_hash($initialMu), is_dir($initialRepo) ? adopt_tree_hash($initialRepo) : null] === $initialBefore,
            'initial refusal before staging changes no destination node or byte for ' . $initialCase);
    }
}

$adoptSource = (string) file_get_contents($sourceRoot . '/cli/src/Onboarding/Adopt.php');
adopt_check(
    str_contains($adoptSource, "'COPYFILE_DISABLE=1 tar -C '")
        && !str_contains($adoptSource, "'tar -C ' . escapeshellarg(\$localStage)"),
    'local adoption disables macOS AppleDouble archive entries before packaging the exact agent and recovery roots'
);
$filesystemFixture = rtrim(sys_get_temp_dir(), '/') . '/wprism-adopt-regress-' . bin2hex(random_bytes(8));
register_shutdown_function(static function () use ($filesystemFixture): void {
    if (is_dir($filesystemFixture)) {
        adopt_remove_fixture($filesystemFixture);
    }
});
$filesystemTransport = new AdoptFilesystemTransactionTransport($filesystemFixture, $sourceRoot);

$legacyRootsBefore = [
    adopt_tree_hash($filesystemTransport->muDir()),
    adopt_tree_hash($filesystemTransport->repoPath()),
];
$unattestedLegacyInstall = Adopt::install($filesystemTransport, $sourceRoot);
adopt_check(
    $unattestedLegacyInstall['exit'] !== 0
        && $unattestedLegacyInstall['phase'] === 'agent generation migration'
        && str_contains($unattestedLegacyInstall['stderr'], '--attest-legacy-loader-quiesced'),
    'an exact legacy loader refuses migration without the explicit host quiescence attestation'
);
adopt_check(
    [
        adopt_tree_hash($filesystemTransport->muDir()),
        adopt_tree_hash($filesystemTransport->repoPath()),
    ] === $legacyRootsBefore
        && $filesystemTransport->uploadedArchives === [],
    'unattested legacy adoption changes no target byte and uploads no distribution'
);
$firstInstall = Adopt::install(
    $filesystemTransport,
    $sourceRoot,
    legacyLoaderQuiesced: true
);
adopt_check(
    $firstInstall['exit'] === 0 && ($firstInstall['legacy_loader_transition'] ?? false) === true,
    'the attested real filesystem transaction crosses the one-time legacy loader boundary'
);

$lookalikeFixture = rtrim(sys_get_temp_dir(), '/') . '/wprism-adopt-regress-' . bin2hex(random_bytes(8));
register_shutdown_function(static function () use ($lookalikeFixture): void {
    if (is_dir($lookalikeFixture)) adopt_remove_fixture($lookalikeFixture);
});
$lookalikeTransport = new AdoptFilesystemTransactionTransport($lookalikeFixture, $sourceRoot);
file_put_contents(
    $lookalikeTransport->muDir() . '/wprism-loader.php',
    "<?php\n/* WPRISM_AGENT_GENERATION_FENCE_PROTOCOL=1 */\n"
        . "require_once __DIR__ . '/wprism/wprism.php';\n// foreign extension\n"
);
$lookalikeBefore = [
    adopt_tree_hash($lookalikeTransport->muDir()),
    adopt_tree_hash($lookalikeTransport->repoPath()),
];
$lookalikeResult = Adopt::install($lookalikeTransport, $sourceRoot, legacyLoaderQuiesced: true);
adopt_check(
    $lookalikeResult['exit'] !== 0
        && $lookalikeResult['phase'] === 'agent generation migration'
        && str_contains($lookalikeResult['stderr'], 'non-WPrism file'),
    'a foreign loader containing both protocol and require lookalikes is not attestation-eligible'
);
adopt_check(
    [
        adopt_tree_hash($lookalikeTransport->muDir()),
        adopt_tree_hash($lookalikeTransport->repoPath()),
    ] === $lookalikeBefore
        && $lookalikeTransport->uploadedArchives === [],
    'foreign lookalike refusal changes no target byte and cannot consume the legacy attestation'
);
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

// Exercise the installed bytes, not the source checkout: a provider throwable
// becomes a private carrier in Providers, then the installed CLI records it
// below the authority root published by the real adoption transaction.
$installedAgent = $filesystemTransport->muDir() . '/wprism';
$adoptedRepo = $filesystemTransport->repoPath();
$adoptProbe = $filesystemFixture . '/provider-refusal-probe.php';
$adoptProbeSource = <<<'PHP'
<?php
declare(strict_types=1);

final class AdoptEvidenceCliHalt extends RuntimeException {
    public function __construct(public int $status) {
        parent::__construct("halt:$status");
    }
}

final class WP_CLI {
    /** @var list<string> */
    public static array $lines = [];

    public static function add_command(mixed $name, mixed $class): void {}

    public static function line(mixed $line): void {
        self::$lines[] = (string) $line;
    }

    public static function halt(mixed $status): void {
        throw new AdoptEvidenceCliHalt((int) $status);
    }

    public static function error(mixed $message, mixed $exit = true): void {
        throw new RuntimeException((string) $message);
    }
}

$agent = $argv[1];
$repo = $argv[2];
require_once $agent . '/src/Adapter/Providers.php';
require_once $agent . '/src/Command/Cli.php';

$providerCause = new RuntimeException(
    'provider failed with X-Amz-Signature=PRIVATE_ADOPTED_PROVIDER'
);
$provider = new class($providerCause) {
    public function __construct(private Throwable $failure) {}

    public function invoke(string $capability, array $args): array {
        throw $this->failure;
    }
};
$providerFailure = null;
try {
    \WPrism\Providers::invoke(
        $provider,
        ['provider' => 'adopt-evidence', 'capability' => 'repair', 'args' => []],
        ['scope' => 'site', 'reads' => [], 'writes' => [], 'timeout_seconds' => 5],
        []
    );
} catch (Throwable $failure) {
    $providerFailure = $failure;
}
if (!$providerFailure instanceof \WPrism\PrivateEvidenceException) {
    throw new RuntimeException('installed provider boundary did not retain a private cause');
}

$refusalDirectory = $repo . '/.wprism/refusals';
$directoryExisted = is_dir($refusalDirectory);
$before = glob($refusalDirectory . '/*.json') ?: [];
$boundary = new ReflectionMethod(\WPrism\Cli::class, 'halt_json_failure');
$haltStatus = null;
try {
    $boundary->invoke(
        null,
        $providerFailure,
        ['repo' => $repo, 'format' => 'json'],
        'apply'
    );
} catch (AdoptEvidenceCliHalt $halt) {
    $haltStatus = $halt->status;
}
$publicBytes = implode("\n", WP_CLI::$lines);
$after = glob($refusalDirectory . '/*.json') ?: [];
$files = array_values(array_diff($after, $before));
$privateBytes = count($files) === 1 ? (string) file_get_contents($files[0]) : '';
$public = json_decode($publicBytes, true);
$result = [
    'provider_private_cause' => $providerFailure->private_evidence_causes() === [$providerCause],
    'halt_status' => $haltStatus,
    'details_redacted' => $public['details_redacted'] ?? null,
    'public_leaked' => str_contains($publicBytes, 'PRIVATE_ADOPTED_PROVIDER'),
    'private_retained' => str_contains($privateBytes, 'PRIVATE_ADOPTED_PROVIDER'),
    'record_count' => count($files),
    'authority_mode' => fileperms($repo . '/.wprism') & 0777,
    'directory_mode' => fileperms($refusalDirectory) & 0777,
    'record_mode' => count($files) === 1 ? fileperms($files[0]) & 0777 : null,
];
foreach ($files as $file) {
    unlink($file);
}
if (!$directoryExisted) {
    rmdir($refusalDirectory);
}
echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
PHP;
if (file_put_contents($adoptProbe, $adoptProbeSource) === false || !chmod($adoptProbe, 0600)) {
    throw new RuntimeException('could not write installed-agent refusal probe');
}
$probeDescriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$probeProcess = proc_open(
    [PHP_BINARY, $adoptProbe, $installedAgent, $adoptedRepo],
    $probeDescriptors,
    $probePipes,
    $filesystemFixture
);
if (!is_resource($probeProcess)) {
    throw new RuntimeException('could not start installed-agent refusal probe');
}
fclose($probePipes[0]);
$adoptProbeStdout = stream_get_contents($probePipes[1]) ?: '';
$adoptProbeStderr = stream_get_contents($probePipes[2]) ?: '';
fclose($probePipes[1]);
fclose($probePipes[2]);
$adoptProbeStatus = proc_close($probeProcess);
unlink($adoptProbe);
if ($adoptProbeStatus !== 0 && $adoptProbeStderr !== '') {
    fwrite(STDERR, $adoptProbeStderr);
}
$adoptProbeResult = json_decode($adoptProbeStdout, true);
adopt_check(
    $adoptProbeStatus === 0
        && is_array($adoptProbeResult)
        && ($adoptProbeResult['provider_private_cause'] ?? null) === true
        && ($adoptProbeResult['halt_status'] ?? null) === 1
        && ($adoptProbeResult['details_redacted'] ?? null) === true
        && ($adoptProbeResult['public_leaked'] ?? null) === false
        && ($adoptProbeResult['private_retained'] ?? null) === true
        && ($adoptProbeResult['record_count'] ?? null) === 1
        && ($adoptProbeResult['authority_mode'] ?? null) === 0700
        && ($adoptProbeResult['directory_mode'] ?? null) === 0700
        && ($adoptProbeResult['record_mode'] ?? null) === 0600,
    'the adopted agent carries a real provider refusal through redacted CLI output into private 0700/0600 evidence'
);

$mu = $filesystemTransport->muDir();
$repo = $filesystemTransport->repoPath();
file_put_contents($mu . '/wprism/rollback-sentinel.txt', "prior-agent\n");
copy($sourceRoot . '/agent/wprism-loader.php', $mu . '/wprism-loader.php');
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
$populationResult = Adopt::install($populationTransport, $sourceRoot, legacyLoaderQuiesced: true);
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
        && is_file($mu . '/.wprism-adopt-lock/generation-writer-owner')
        && is_file($mu . '/.wprism-generation-writer-pending')
        && is_file($interruptedTxn . '/agent_move_intent')
        && is_file($interruptedTxn . '/agent_old_post.id')
        && !file_exists($interruptedTxn . '/agent_live_post.id')
        && is_dir($mu . '/.wprism-old-' . $interruptedToken)
        && file_get_contents($mu . '/.wprism-old-' . $interruptedToken . '/rollback-sentinel.txt') === $priorRoots['agent'],
    'the interrupted before-postproof state retains old backup, writer gate, intent, lock, and immutable evidence without deletion'
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
$commitResult = Adopt::install($commitTransport, $sourceRoot, legacyLoaderQuiesced: true);
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
$lockContainerResult = Adopt::install($lockContainerTransport, $sourceRoot, legacyLoaderQuiesced: true);
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
$txnContainerResult = Adopt::install($txnContainerTransport, $sourceRoot, legacyLoaderQuiesced: true);
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

// The stable directory flock is exercised with independent PHP processes,
// not an in-process mock: PHP's flock ownership and descriptor inheritance are
// the platform behavior adoption relies on when its generated shell asks a
// child PHP process to lock fd 9 on behalf of the parent shell.
$generationFixture = rtrim(sys_get_temp_dir(), '/') . '/wprism-adopt-regress-generation-' . bin2hex(random_bytes(8));
$generationMu = $generationFixture . '/mu-plugins';
mkdir($generationMu . '/wprism', 0700, true);
copy($sourceRoot . '/agent/wprism-loader.php', $generationMu . '/wprism-loader.php');
file_put_contents(
    $generationMu . '/wprism/wprism.php',
    <<<'PHP'
<?php
$boot = getenv('WPRISM_FENCE_BOOT');
if (is_string($boot) && $boot !== '') {
    file_put_contents($boot, (string) getmypid());
}
PHP
);
register_shutdown_function(static function () use ($generationFixture): void {
    if (is_dir($generationFixture)) {
        adopt_remove_fixture($generationFixture);
    }
});

/** @return array{process:resource,pipes:array<int,resource>} */
$startGenerationProcess = static function (array $command): array {
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('could not start generation-fence fixture process');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return ['process' => $process, 'pipes' => $pipes];
};

/** @param array{process:resource,pipes:array<int,resource>} $runtime @return array{exit:int,stdout:string,stderr:string} */
$finishGenerationProcess = static function (array $runtime, float $timeout = 5.0): array {
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeout;
    $lastStatus = null;
    while (microtime(true) < $deadline) {
        $stdout .= (string) stream_get_contents($runtime['pipes'][1]);
        $stderr .= (string) stream_get_contents($runtime['pipes'][2]);
        $lastStatus = proc_get_status($runtime['process']);
        if (!is_array($lastStatus) || !$lastStatus['running']) {
            break;
        }
        usleep(10_000);
    }
    if (is_array($lastStatus) && $lastStatus['running']) {
        proc_terminate($runtime['process'], 9);
        throw new RuntimeException('generation-fence fixture process exceeded its bounded deadline');
    }
    $stdout .= (string) stream_get_contents($runtime['pipes'][1]);
    $stderr .= (string) stream_get_contents($runtime['pipes'][2]);
    foreach ($runtime['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    $closed = proc_close($runtime['process']);
    $exit = is_array($lastStatus) && $lastStatus['exitcode'] >= 0 ? $lastStatus['exitcode'] : $closed;
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
};

$awaitGenerationEvidence = static function (callable $ready, string $label): void {
    $deadline = microtime(true) + 5.0;
    while (!$ready() && microtime(true) < $deadline) {
        usleep(10_000);
    }
    adopt_check($ready(), $label);
};

$loaderPath = $generationMu . '/wprism-loader.php';
$cliRunner = <<<'PHP'
putenv('WPRISM_FENCE_BOOT=' . $argv[2]);
define('WP_CLI', true);
require $argv[1];
fgets(STDIN);
PHP;
$firstBoot = $generationFixture . '/reader-one.boot';
$firstReader = $startGenerationProcess([
    PHP_BINARY,
    '-d',
    'opcache.enable_cli=0',
    '-r',
    $cliRunner,
    $loaderPath,
    $firstBoot,
]);
$awaitGenerationEvidence(
    static fn(): bool => is_file($firstBoot),
    'a real WP-CLI process eagerly loads one agent generation while holding the shared directory fence'
);

$writerGate = $generationMu . '/.wprism-adopt-lock';
$writerPending = $generationMu . '/.wprism-generation-writer-pending';
$writerPublished = $generationFixture . '/writer-published';
$writerScript = 'set -eu' . "\n"
    . 'mu=' . escapeshellarg($generationMu) . '; lock=' . escapeshellarg($writerGate) . "\n"
    . 'generation_locked=0; generation_pending=0; mkdir "$lock"' . "\n"
    . \WPrism\Orchestrator\AgentGenerationFence::shellHelpers()
    . 'generation_lock_acquire 0' . "\n"
    . 'printf published > ' . escapeshellarg($writerPublished) . "\n"
    . 'generation_lock_release 1; rm -f "$lock/generation-writer-owner"; rmdir "$lock"';
$writer = $startGenerationProcess(['/bin/sh', '-c', $writerScript]);
$awaitGenerationEvidence(
    static fn(): bool => is_file($writerPending),
    'the adoption transaction gate publishes writer intent before waiting for existing CLI readers'
);
adopt_check(
    is_file($writerGate . '/generation-writer-owner')
        && fileinode($writerGate . '/generation-writer-owner') === fileinode($writerPending),
    'the shared writer intent is a hard link to the exact transaction-owned authority'
);
adopt_check(!is_file($writerPublished), 'a paused CLI reader prevents the queued writer from publishing another generation');

$secondBoot = $generationFixture . '/reader-two.boot';
$secondReader = $startGenerationProcess([
    PHP_BINARY,
    '-d',
    'opcache.enable_cli=0',
    '-r',
    $cliRunner,
    $loaderPath,
    $secondBoot,
]);
fclose($secondReader['pipes'][0]);
$secondResult = $finishGenerationProcess($secondReader);
adopt_check(
    $secondResult['exit'] !== 0
        && str_contains($secondResult['stderr'], 'an agent generation writer is active or requires recovery')
        && !is_file($secondBoot),
    'a later CLI reader yields to the queued adoption writer before loading replaceable bytes'
);

fwrite($firstReader['pipes'][0], "\n");
fclose($firstReader['pipes'][0]);
$firstResult = $finishGenerationProcess($firstReader);
$writerResult = $finishGenerationProcess($writer);
adopt_check(
    $firstResult['exit'] === 0 && $writerResult['exit'] === 0 && is_file($writerPublished)
        && !file_exists($writerGate) && !file_exists($writerPending),
    'the writer publishes only after the prior CLI generation exits, then retires its gate and releases the fence'
);

// Adoption and unadoption deliberately retain separate recovery journals, but
// they must not retain separate writer authorities. Exercise both winner
// orderings with independent OS processes while a third process holds a reader
// fence. The losing operation must refuse at the shared intent link rather than
// queue behind the winner and act on the generation it reviewed earlier.
$crossState = $generationFixture . '/cross-generation';
$crossWriterScript = static function (string $kind, string $replacement, string $done) use (
    $generationMu,
    $crossState
): string {
    $lock = $generationMu . '/.wprism-' . $kind . '-lock';
    return 'set -eu' . "\n"
        . 'mu=' . escapeshellarg($generationMu) . '; lock=' . escapeshellarg($lock)
        . '; state=' . escapeshellarg($crossState) . '; done=' . escapeshellarg($done) . "\n"
        . 'generation_locked=0; generation_pending=0; mkdir "$lock"' . "\n"
        . \WPrism\Orchestrator\AgentGenerationFence::shellHelpers()
        . 'finish() { rc=$?; trap - EXIT; set +e; generation_lock_release 1; release_rc=$?; rm -f "$lock/generation-writer-owner"; rmdir "$lock" 2>/dev/null; [ "$release_rc" -eq 0 ] || rc=1; exit "$rc"; }' . "\n"
        . 'trap finish EXIT' . "\n"
        . 'generation_lock_acquire 0' . "\n"
        . '[ "$(cat "$state")" = generation-a ] || { echo "reviewed generation changed before publish" >&2; exit 1; }' . "\n"
        . 'printf %s ' . escapeshellarg($replacement) . ' > "$state.next"; mv "$state.next" "$state"' . "\n"
        . 'printf %s ' . escapeshellarg($kind) . ' > "$done"' . "\n"
        . 'generation_lock_release 1; rm -f "$lock/generation-writer-owner"; rmdir "$lock"; trap - EXIT';
};
$runCrossWriterRace = static function (string $winnerKind, string $loserKind, string $replacement) use (
    $startGenerationProcess,
    $finishGenerationProcess,
    $awaitGenerationEvidence,
    $crossWriterScript,
    $crossState,
    $generationFixture,
    $generationMu,
    $writerPending
): void {
    file_put_contents($crossState, 'generation-a');
    $readerReady = $generationFixture . '/cross-' . $winnerKind . '-reader';
    $readerCode = <<<'PHP'
$handle = fopen($argv[1], 'rb');
if (!is_resource($handle) || !flock($handle, LOCK_SH)) exit(2);
file_put_contents($argv[2], 'ready');
fgets(STDIN);
PHP;
    $reader = $startGenerationProcess([PHP_BINARY, '-r', $readerCode, $generationMu, $readerReady]);
    $awaitGenerationEvidence(
        static fn(): bool => is_file($readerReady),
        "$winnerKind/$loserKind race owns a real shared generation fence before either writer starts"
    );

    $winnerDone = $generationFixture . '/cross-' . $winnerKind . '-done';
    $winner = $startGenerationProcess([
        '/bin/sh',
        '-c',
        $crossWriterScript($winnerKind, $replacement, $winnerDone),
    ]);
    fclose($winner['pipes'][0]);
    $awaitGenerationEvidence(
        static fn(): bool => is_file($writerPending),
        "$winnerKind publishes the one shared writer intent while waiting for the prior reader"
    );

    $loserDone = $generationFixture . '/cross-' . $loserKind . '-lost';
    $loser = $startGenerationProcess([
        '/bin/sh',
        '-c',
        $crossWriterScript($loserKind, 'forbidden', $loserDone),
    ]);
    fclose($loser['pipes'][0]);
    $loserResult = $finishGenerationProcess($loser);
    $winnerAnchor = $generationMu . '/.wprism-' . $winnerKind . '-lock/generation-writer-owner';
    $loserAnchor = $generationMu . '/.wprism-' . $loserKind . '-lock/generation-writer-owner';
    adopt_check(
        $loserResult['exit'] !== 0
            && str_contains($loserResult['stderr'], 'active or requires recovery')
            && !is_file($loserDone)
            && file_get_contents($crossState) === 'generation-a'
            && is_file($writerPending) && is_file($winnerAnchor)
            && fileinode($writerPending) === fileinode($winnerAnchor)
            && !file_exists($loserAnchor),
        "$loserKind refuses without retiring $winnerKind's marker or transaction-owned authority"
    );

    fwrite($reader['pipes'][0], "\n");
    fclose($reader['pipes'][0]);
    $readerResult = $finishGenerationProcess($reader);
    $winnerResult = $finishGenerationProcess($winner);
    adopt_check(
        $readerResult['exit'] === 0 && $winnerResult['exit'] === 0
            && is_file($winnerDone) && file_get_contents($crossState) === $replacement
            && !file_exists($writerPending)
            && !file_exists($generationMu . '/.wprism-' . $winnerKind . '-lock')
            && !file_exists($generationMu . '/.wprism-' . $loserKind . '-lock'),
        "$winnerKind publishes one generation and cleans its authority after excluding concurrent $loserKind"
    );
};
$runCrossWriterRace('adopt', 'unadopt', 'generation-b');
$runCrossWriterRace('unadopt', 'adopt', 'offboarded');

// Hold the exclusive side before the child starts. The child passes its first
// gate check, blocks on LOCK_SH, then observes the newly-published marker in
// the post-lock check after this process releases LOCK_EX.
$raceHandle = fopen($generationMu, 'rb');
adopt_check(is_resource($raceHandle) && flock($raceHandle, LOCK_EX), 'the race fixture owns the real MU-directory writer fence');
$raceBoot = $generationFixture . '/race-reader.boot';
$raceReader = $startGenerationProcess([
    PHP_BINARY,
    '-d',
    'opcache.enable_cli=0',
    '-r',
    $cliRunner,
    $loaderPath,
    $raceBoot,
]);
usleep(500_000);
file_put_contents($writerPending, '');
flock($raceHandle, LOCK_UN);
fclose($raceHandle);
fclose($raceReader['pipes'][0]);
$raceResult = $finishGenerationProcess($raceReader);
adopt_check(
    $raceResult['exit'] !== 0
        && str_contains($raceResult['stderr'], 'agent generation changed while its loader was acquiring a read fence')
        && !is_file($raceBoot),
    'the post-lock gate recheck closes the reader/writer-intent race before agent bytes load'
);
unlink($writerPending);

$webRunner = <<<'PHP'
putenv('WPRISM_FENCE_BOOT=' . $argv[2]);
require $argv[1];
fgets(STDIN);
PHP;
$webBoot = $generationFixture . '/web-reader.boot';
$webReader = $startGenerationProcess([PHP_BINARY, '-r', $webRunner, $loaderPath, $webBoot]);
$awaitGenerationEvidence(
    static fn(): bool => is_file($webBoot),
    'a normal WordPress-style process completes the eager agent bootstrap'
);
$webWriter = fopen($generationMu, 'rb');
$webReleased = is_resource($webWriter) && flock($webWriter, LOCK_EX | LOCK_NB);
adopt_check($webReleased, 'a non-CLI request releases the generation fence immediately after eager bootstrap');
if ($webReleased) {
    flock($webWriter, LOCK_UN);
}
if (is_resource($webWriter)) {
    fclose($webWriter);
}
fwrite($webReader['pipes'][0], "\n");
fclose($webReader['pipes'][0]);
adopt_check($finishGenerationProcess($webReader)['exit'] === 0, 'the early-release web request exits cleanly');

$opcacheBoot = $generationFixture . '/opcache-reader.boot';
$opcacheReader = $startGenerationProcess([
    PHP_BINARY,
    '-d',
    'opcache.enable_cli=1',
    '-r',
    $cliRunner,
    $loaderPath,
    $opcacheBoot,
]);
fclose($opcacheReader['pipes'][0]);
$opcacheResult = $finishGenerationProcess($opcacheReader);
adopt_check(
    $opcacheResult['exit'] !== 0
        && str_contains($opcacheResult['stderr'], 'WP-CLI opcache.enable_cli=1 is unsupported')
        && !is_file($opcacheBoot),
    'WP-CLI OPcache is refused by the stable loader before replaceable agent bytes execute'
);

$loaderSource = (string) file_get_contents($sourceRoot . '/agent/wprism-loader.php');
$preGate = strpos($loaderSource, 'if ($wprismWriterPending())');
$sharedLock = strpos($loaderSource, '@flock($wprismGenerationHandle, LOCK_SH)');
$postGate = strpos($loaderSource, '|| $wprismWriterPending())');
adopt_check(
    is_int($preGate) && is_int($sharedLock) && is_int($postGate)
        && $preGate < $sharedLock && $sharedLock < $postGate,
    'the shipped loader orders its transaction gate checks on both sides of the shared flock'
);

echo "REGRESS_ADOPT_ROLLBACK PASSED\n";
