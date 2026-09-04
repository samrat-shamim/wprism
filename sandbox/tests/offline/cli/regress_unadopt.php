<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Command/UnadoptCommand.php';

use WPrism\Orchestrator\AdoptionTransport;
use WPrism\Orchestrator\DriverCapability;
use WPrism\Orchestrator\DriverCapabilityReport;
use WPrism\Orchestrator\EnvironmentDriver;
use WPrism\Orchestrator\Unadopt;
use WPrism\Orchestrator\UnadoptCommand;

function unadopt_fail(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function unadopt_check(bool $condition, string $message): void {
    if (!$condition) unadopt_fail($message);
    echo "ok: $message\n";
}

function unadopt_remove(string $path): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') unadopt_remove($path . '/' . $entry);
    }
    @rmdir($path);
}

function unadopt_tree_bytes(string $path): string {
    if (is_file($path) && !is_link($path)) return 'F:' . hash_file('sha256', $path);
    if (!is_dir($path) || is_link($path)) return 'unsafe';
    $rows = [];
    $walk = static function (string $root, string $relative) use (&$walk, &$rows): void {
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $root . '/' . $entry;
            $name = $relative === '' ? $entry : $relative . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $rows[] = 'D:' . $name;
                $walk($path, $name);
            } elseif (is_file($path) && !is_link($path)) {
                $rows[] = 'F:' . $name . ':' . hash_file('sha256', $path);
            } else {
                $rows[] = 'U:' . $name;
            }
        }
    };
    $walk($path, '');
    sort($rows, SORT_STRING);
    return hash('sha256', implode("\n", $rows));
}

final class UnadoptFilesystemTransport implements AdoptionTransport, EnvironmentDriver {
    public bool $failAbsenceVerification = false;
    /** @var list<string> */
    public array $rawScripts = [];
    /** @var list<array<int,string>> */
    public array $wpCalls = [];

    private string $mu;
    private string $repo;
    private string $wp;

    public function __construct(private string $root, string $sourceRoot, bool $legacyLoader = false) {
        $this->mu = $root . '/mu-plugins';
        $this->repo = $root . '/repository';
        $this->wp = $root . '/wordpress';
        mkdir($this->mu . '/wprism', 0700, true);
        mkdir($this->repo . '/.wprism/control/recovery-runtime', 0700, true);
        mkdir($this->repo . '/code', 0700, true);
        mkdir($this->repo . '/media', 0700, true);
        mkdir($this->repo . '/state', 0700, true);
        mkdir($this->repo . '/.git', 0700, true);
        mkdir($this->mu . '/wprism-control', 0700, true);
        mkdir($this->wp, 0700, true);
        copy($sourceRoot . '/agent/wprism.php', $this->mu . '/wprism/wprism.php');
        copy(
            $legacyLoader
                ? dirname(__DIR__, 2) . '/fixtures/legacy-wprism-loader.php'
                : $sourceRoot . '/agent/wprism-loader.php',
            $this->mu . '/wprism-loader.php'
        );
        copy(
            $sourceRoot . '/recovery/rollback-control.php',
            $this->repo . '/.wprism/control/recovery-runtime/rollback-control.php'
        );
        file_put_contents($this->mu . '/wprism/runtime-evidence.log', "agent evidence\n");
        file_put_contents($this->repo . '/.wprism/control/checkpoint-receipt.json', "{\"receipt\":true}\n");
        file_put_contents($this->repo . '/site.wprism.json', "{\"operator\":true}\n");
        file_put_contents($this->repo . '/code/sentinel', "code\n");
        file_put_contents($this->repo . '/media/sentinel', "media\n");
        file_put_contents($this->repo . '/state/sentinel', "state\n");
        file_put_contents($this->repo . '/.git/sentinel', "git\n");
        file_put_contents($this->mu . '/wprism-control/adapter-revocations.json', "{\"revoked\":[]}\n");
    }

    public function root(): string { return $this->root; }
    public function muDir(): string { return $this->mu; }
    public function bootstrapCapability(): array {
        return ['supported' => true, 'reason' => 'fixture offboarding authority', 'remediation' => ''];
    }
    public function name(): string { return 'unadopt-fixture'; }
    public function driverId(): string { return 'unadopt-fixture'; }
    public function repoPath(): string { return $this->repo; }
    public function wpPath(): string { return $this->wp; }
    public function describe(): string { return 'unadopt filesystem fixture'; }
    public function uploadFile(string $localPath, string $remotePath): array {
        return ['exit' => 97, 'stdout' => '', 'stderr' => 'unadopt must not upload source bytes'];
    }

    public function captureRaw(string $script): array {
        $this->rawScripts[] = $script;
        $process = proc_open(
            ['/bin/sh', '-c', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root
        );
        if (!is_resource($process)) return ['exit' => 98, 'stdout' => '', 'stderr' => 'could not run fixture shell'];
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return ['exit' => $exit, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }

    public function captureWp(array $wpArgs): array {
        $this->wpCalls[] = array_map('strval', $wpArgs);
        if ($wpArgs === ['eval', 'echo WPMU_PLUGIN_DIR;']) {
            return ['exit' => 0, 'stdout' => $this->mu . "\n", 'stderr' => ''];
        }
        if ($wpArgs === ['core', 'is-installed']) {
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }
        if (($wpArgs[0] ?? null) === 'eval' && str_contains((string) ($wpArgs[1] ?? ''), 'wprism-absent')) {
            $present = is_file($this->mu . '/wprism-loader.php') || $this->failAbsenceVerification;
            return ['exit' => 0, 'stdout' => ($present ? 'wprism-present' : 'wprism-absent') . "\n", 'stderr' => ''];
        }
        return ['exit' => 96, 'stdout' => '', 'stderr' => 'unexpected fixture WP call'];
    }

    public function streamWp(array $wpArgs): int { return $this->captureWp($wpArgs)['exit']; }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver(
            $this->name(),
            $this->driverId(),
            $operation,
            [
                DriverCapability::ATTACH => true,
                DriverCapability::BOOTSTRAP => true,
                DriverCapability::RAW_CONTROL => true,
                DriverCapability::WP_CONTROL => true,
            ]
        );
    }
}

$sourceRoot = dirname(__DIR__, 4);
$fixtureRoot = sys_get_temp_dir() . '/wprism-unadopt-regress-' . bin2hex(random_bytes(8));
mkdir($fixtureRoot, 0700, true);
$fixtureRoot = (string) realpath($fixtureRoot);
register_shutdown_function(static function () use ($fixtureRoot): void { unadopt_remove($fixtureRoot); });
$transport = new UnadoptFilesystemTransport($fixtureRoot . '/target', $sourceRoot);
$archive = $fixtureRoot . '/client-handoff';
$repoBefore = [
    'site' => file_get_contents($transport->repoPath() . '/site.wprism.json'),
    'code' => unadopt_tree_bytes($transport->repoPath() . '/code'),
    'media' => unadopt_tree_bytes($transport->repoPath() . '/media'),
    'state' => unadopt_tree_bytes($transport->repoPath() . '/state'),
    'git' => unadopt_tree_bytes($transport->repoPath() . '/.git'),
    'revocations' => file_get_contents($transport->muDir() . '/wprism-control/adapter-revocations.json'),
];
$blanketArchive = $fixtureRoot . '/blanket-handoff';
ob_start();
$blanketExit = UnadoptCommand::run(
    $transport,
    ["--archive-to=$blanketArchive", '--yes', '--attest-legacy-loader-quiesced']
);
ob_end_clean();
unadopt_check($blanketExit !== 0, 'unadopt rejects a blanket quiescence attestation for a fenced loader');
unadopt_check(
    !file_exists($blanketArchive)
        && is_file($transport->muDir() . '/wprism-loader.php')
        && is_dir($transport->muDir() . '/wprism'),
    'misapplied unadopt attestation creates no archive and changes no live byte'
);
ob_start();
$exit = UnadoptCommand::run($transport, ["--archive-to=$archive", '--yes']);
$output = (string) ob_get_clean();
unadopt_check($exit === 0, 'the public unadopt command completes through the real filesystem transaction');
unadopt_check(
    !file_exists($transport->muDir() . '/wprism')
        && !file_exists($transport->muDir() . '/wprism-loader.php')
        && !file_exists($transport->repoPath() . '/.wprism')
        && !file_exists($transport->muDir() . '/.wprism-unadopt-lock'),
    'commit removes exactly the live agent, MU loader, target-local control tree, and writer gate'
);
unadopt_check(
    is_file($archive . '/mu-plugins/wprism/wprism.php')
        && is_file($archive . '/mu-plugins/wprism-loader.php')
        && is_file($archive . '/repository/.wprism/control/checkpoint-receipt.json'),
    'the operator-selected archive retains executable control bytes and recovery evidence before removal'
);
$receipt = json_decode((string) file_get_contents($archive . '/receipt.json'), true, 512, JSON_THROW_ON_ERROR);
unadopt_check(
    ($receipt['format'] ?? null) === Unadopt::RECEIPT_FORMAT
        && ($receipt['archive'] ?? null) === $archive
        && count($receipt['surfaces'] ?? []) === 3,
    'the archive carries a closed receipt binding every removed surface'
);
unadopt_check(
    file_get_contents($transport->repoPath() . '/site.wprism.json') === $repoBefore['site']
        && unadopt_tree_bytes($transport->repoPath() . '/code') === $repoBefore['code']
        && unadopt_tree_bytes($transport->repoPath() . '/media') === $repoBefore['media']
        && unadopt_tree_bytes($transport->repoPath() . '/state') === $repoBefore['state']
        && unadopt_tree_bytes($transport->repoPath() . '/.git') === $repoBefore['git'],
    'offboarding leaves repository policy, code, media, state, and Git history byte-identical'
);
unadopt_check(
    file_get_contents($transport->muDir() . '/wprism-control/adapter-revocations.json') === $repoBefore['revocations'],
    'durable revocations remain in place because adoption does not own their prehistory'
);
unadopt_check(
    str_contains($output, 'complete evidence archive') && str_contains($output, 'preserved in place'),
    'success tells the operator both where evidence went and what was deliberately retained'
);

$legacyTransport = new UnadoptFilesystemTransport($fixtureRoot . '/legacy-target', $sourceRoot, true);
$legacyArchive = $fixtureRoot . '/legacy-handoff';
$legacyBefore = [
    unadopt_tree_bytes($legacyTransport->muDir()),
    unadopt_tree_bytes($legacyTransport->repoPath()),
];
ob_start();
$legacyRefusal = UnadoptCommand::run($legacyTransport, ["--archive-to=$legacyArchive", '--yes']);
ob_end_clean();
unadopt_check($legacyRefusal !== 0, 'the host unadopt command refuses an unfenced legacy loader without attestation');
unadopt_check(
    [
        unadopt_tree_bytes($legacyTransport->muDir()),
        unadopt_tree_bytes($legacyTransport->repoPath()),
    ] === $legacyBefore
        && !file_exists($legacyArchive),
    'unattested legacy unadoption creates no archive and changes no target byte'
);
ob_start();
$legacyExit = UnadoptCommand::run(
    $legacyTransport,
    ["--archive-to=$legacyArchive", '--yes', '--attest-legacy-loader-quiesced']
);
$legacyOutput = (string) ob_get_clean();
$legacyReceipt = json_decode(
    (string) file_get_contents($legacyArchive . '/receipt.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
unadopt_check($legacyExit === 0, 'the host unadopt command carries the explicit legacy-loader quiescence attestation');
unadopt_check(
    ($legacyReceipt['loader_generation_fence'] ?? null) === 'legacy-unfenced'
        && ($legacyReceipt['legacy_loader_quiescence_attested'] ?? null) === true
        && str_contains($legacyOutput, 'archived the explicit quiescence attestation'),
    'attested legacy unadoption persists and reports its one-time migration evidence'
);

$lookalikeTransport = new UnadoptFilesystemTransport($fixtureRoot . '/lookalike-target', $sourceRoot);
$lookalikeArchive = $fixtureRoot . '/lookalike-handoff';
file_put_contents(
    $lookalikeTransport->muDir() . '/wprism-loader.php',
    "<?php\n/* WPRISM_AGENT_GENERATION_FENCE_PROTOCOL=1 */\n"
        . "require_once __DIR__ . '/wprism/wprism.php';\n// foreign extension\n"
);
try {
    Unadopt::plan($lookalikeTransport, $lookalikeArchive);
    unadopt_fail('a foreign loader lookalike was accepted for unadoption');
} catch (RuntimeException $expected) {
    unadopt_check(
        str_contains($expected->getMessage(), 'non-WPrism file')
            && !file_exists($lookalikeArchive),
        'a foreign loader containing protocol and require lookalikes cannot consume the legacy attestation'
    );
}

$rollbackTransport = new UnadoptFilesystemTransport($fixtureRoot . '/rollback-target', $sourceRoot);
$rollbackArchive = $fixtureRoot . '/failed-handoff';
$agentBefore = unadopt_tree_bytes($rollbackTransport->muDir() . '/wprism');
$controlBefore = unadopt_tree_bytes($rollbackTransport->repoPath() . '/.wprism');
$rollbackTransport->failAbsenceVerification = true;
ob_start();
$rollbackExit = UnadoptCommand::run($rollbackTransport, ["--archive-to=$rollbackArchive", '--yes']);
ob_end_clean();
unadopt_check($rollbackExit !== 0, 'a post-move verification failure refuses offboarding');
unadopt_check(
    unadopt_tree_bytes($rollbackTransport->muDir() . '/wprism') === $agentBefore
        && is_file($rollbackTransport->muDir() . '/wprism-loader.php')
        && unadopt_tree_bytes($rollbackTransport->repoPath() . '/.wprism') === $controlBefore
        && !file_exists($rollbackTransport->muDir() . '/.wprism-unadopt-lock'),
    'a refused offboarding restores all live control surfaces and releases its writer gate before returning'
);
unadopt_check(
    is_file($rollbackArchive . '/receipt.json')
        && is_file($rollbackArchive . '/repository/.wprism/control/checkpoint-receipt.json'),
    'failed offboarding retains the complete selected archive as operator evidence'
);

$staleTransport = new UnadoptFilesystemTransport($fixtureRoot . '/stale-target', $sourceRoot);
$staleArchive = $fixtureRoot . '/stale-handoff';
$stalePlan = Unadopt::plan($staleTransport, $staleArchive);
file_put_contents($staleTransport->muDir() . '/wprism/runtime-evidence.log', "changed after review\n");
$stale = Unadopt::execute($staleTransport, $stalePlan);
unadopt_check(
    $stale['exit'] !== 0 && $stale['phase'] === 'stale plan' && !file_exists($staleArchive),
    'fresh ownership hashes refuse a stale reviewed plan before archive or removal'
);
unadopt_check(
    is_dir($staleTransport->muDir() . '/wprism')
        && is_file($staleTransport->muDir() . '/wprism-loader.php')
        && is_dir($staleTransport->repoPath() . '/.wprism'),
    'stale-plan refusal mutates no control-plane path'
);

// Revalidate the reviewed bytes after the exclusive fence is actually held,
// not merely before archive construction. A real reader keeps the generated
// stage script queued while the fixture changes the installed loader itself;
// the exact host-bound migration probe must refuse before its first mv and
// retire only its own writer authority.
$fenceTransport = new UnadoptFilesystemTransport($fixtureRoot . '/fence-target', $sourceRoot);
$fenceArchive = $fixtureRoot . '/fence-handoff';
$fencePlan = Unadopt::plan($fenceTransport, $fenceArchive);
$receiptMethod = new ReflectionMethod(Unadopt::class, 'receipt');
$encodeMethod = new ReflectionMethod(Unadopt::class, 'encode');
$stageMethod = new ReflectionMethod(Unadopt::class, 'stageScript');
$fenceReceipt = $receiptMethod->invoke(null, $fencePlan);
$fenceReceiptBytes = $encodeMethod->invoke(null, $fenceReceipt);
$fenceToken = bin2hex(random_bytes(12));
$fenceStageScript = $stageMethod->invoke(null, $fencePlan, $fenceToken, $fenceReceiptBytes);
if (!is_string($fenceStageScript)) unadopt_fail('could not render the generation-fenced unadopt stage script');
$fenceAcquireOffset = strpos($fenceStageScript, 'generation_lock_acquire 0');
$fenceMigrationOffset = strpos($fenceStageScript, 'generation_loader_probe=$(php -r ', $fenceAcquireOffset ?: 0);
$fenceRevalidateOffset = strpos($fenceStageScript, '[ "$(fingerprint "$agent" directory)" = "$expected_agent" ]', $fenceMigrationOffset ?: 0);
$fenceMoveOffset = strpos($fenceStageScript, 'mv "$loader" "$loader_old"', $fenceRevalidateOffset ?: 0);
$fenceReleaseOffset = strpos($fenceStageScript, 'generation_lock_release 1', $fenceMoveOffset ?: 0);
unadopt_check(
    is_int($fenceAcquireOffset) && is_int($fenceMigrationOffset) && is_int($fenceRevalidateOffset) && is_int($fenceMoveOffset)
        && is_int($fenceReleaseOffset) && $fenceAcquireOffset < $fenceRevalidateOffset
        && $fenceAcquireOffset < $fenceMigrationOffset && $fenceMigrationOffset < $fenceRevalidateOffset
        && $fenceRevalidateOffset < $fenceMoveOffset && $fenceMoveOffset < $fenceReleaseOffset
        && str_contains($fenceStageScript, 'generation_marker="$mu/.wprism-generation-writer-pending"')
        && str_contains($fenceStageScript, '"$mu/.wprism-adopt-lock"'),
    'the generated unadoption script owns the shared fence, revalidates the host-bound loader and reviewed surfaces, moves, and releases in that order'
);

$readerReady = $fixtureRoot . '/fence-reader-ready';
$readerCode = <<<'PHP'
$handle = fopen($argv[1], 'rb');
if (!is_resource($handle) || !flock($handle, LOCK_SH)) exit(2);
file_put_contents($argv[2], 'ready');
fgets(STDIN);
PHP;
$readerProcess = proc_open(
    [PHP_BINARY, '-r', $readerCode, $fenceTransport->muDir(), $readerReady],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $readerPipes,
    $fixtureRoot
);
if (!is_resource($readerProcess)) unadopt_fail('could not launch the generation-fence reader fixture');
$readerDeadline = microtime(true) + 5.0;
while (!is_file($readerReady) && microtime(true) < $readerDeadline) usleep(10_000);
unadopt_check(is_file($readerReady), 'a real process holds the generation reader fence before unadopt stages');

$stageProcess = proc_open(
    ['/bin/sh', '-c', $fenceStageScript],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $stagePipes,
    $fixtureRoot
);
if (!is_resource($stageProcess)) unadopt_fail('could not launch the generation-fenced unadopt stage fixture');
fclose($stagePipes[0]);
$sharedWriter = $fenceTransport->muDir() . '/.wprism-generation-writer-pending';
$writerDeadline = microtime(true) + 5.0;
while (!is_file($sharedWriter) && microtime(true) < $writerDeadline) usleep(10_000);
unadopt_check(is_file($sharedWriter), 'the generated unadopt script publishes shared intent before waiting for readers');
file_put_contents(
    $fenceTransport->muDir() . '/wprism-loader.php',
    "// changed while unadopt waited\n",
    FILE_APPEND
);
fwrite($readerPipes[0], "\n");
fclose($readerPipes[0]);
foreach ([1, 2] as $pipe) fclose($readerPipes[$pipe]);
$readerExit = proc_close($readerProcess);
$stageStdout = stream_get_contents($stagePipes[1]);
$stageStderr = stream_get_contents($stagePipes[2]);
fclose($stagePipes[1]);
fclose($stagePipes[2]);
$stageExit = proc_close($stageProcess);
unadopt_check(
    $readerExit === 0 && $stageExit !== 0
        && str_contains((string) $stageStderr, 'installed loader generation changed after host review')
        && is_dir($fenceTransport->muDir() . '/wprism')
        && is_file($fenceTransport->muDir() . '/wprism-loader.php')
        && is_dir($fenceTransport->repoPath() . '/.wprism')
        && !file_exists($sharedWriter)
        && !file_exists($fenceTransport->muDir() . '/.wprism-unadopt-lock'),
    'under-EX exact loader revalidation refuses stale unadopt bytes before any publish move and cleans only its own gate'
);

try {
    Unadopt::plan($staleTransport, $staleTransport->repoPath() . '/archive');
    unadopt_fail('an archive inside the repository was accepted');
} catch (RuntimeException $expected) {
    unadopt_check(
        str_contains($expected->getMessage(), 'outside the repository'),
        'archive selection refuses a path inside any surface the transaction preserves'
    );
}

echo "PASS: unadopt\n";
