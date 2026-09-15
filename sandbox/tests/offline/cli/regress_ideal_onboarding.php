<?php
/** The source-checkout path begins locally, then composes existing target gates. */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../cli/src/Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../../../../cli/src/Transport/Transport.php';
require_once __DIR__ . '/../../../../cli/src/Transport/DockerTransport.php';
require_once __DIR__ . '/../../../../cli/src/Onboarding/Adopt.php';
require_once __DIR__ . '/../../../../cli/src/Command/ConnectCommand.php';
require_once __DIR__ . '/../../../../cli/src/Command/HostProcess.php';
require_once __DIR__ . '/../../../../cli/src/Command/OnboardCommand.php';
require_once __DIR__ . '/../../../../cli/src/Command/DemoCommand.php';

use WPrism\Orchestrator\Adopt;
use WPrism\Orchestrator\ApplicationContract;
use WPrism\Orchestrator\ConnectCommand;
use WPrism\Orchestrator\ConnectionReceipt;
use WPrism\Orchestrator\ContractProposal;
use WPrism\Orchestrator\ContractStore;
use WPrism\Orchestrator\DemoCommand;
use WPrism\Orchestrator\DriverCapability;
use WPrism\Orchestrator\DriverCapabilityReport;
use WPrism\Orchestrator\DockerTransport;
use WPrism\Orchestrator\EnvironmentDriver;
use WPrism\Orchestrator\HostProcess;
use WPrism\Orchestrator\InitCommand;
use WPrism\Orchestrator\LocalTransport;
use WPrism\Orchestrator\OnboardCommand;
use WPrism\Orchestrator\OnboardingHandoffReceipt;
use WPrism\Orchestrator\Transport;

final class IdealOnboardingTransport extends Transport {
    /** @var list<string> */
    public array $rawCalls = [];
    /** @var list<list<string>> */
    public array $wpCalls = [];
    /** @var list<array{timeout:int,stdout:int,stderr:int}> */
    public array $boundedRawCalls = [];
    /** @var list<array{timeout:int,stdout:int,stderr:int}> */
    public array $boundedWpCalls = [];

    public function __construct(
        private bool $singleSite = true,
        string $repoPath = '/srv/wprism',
        private bool $executeRaw = false,
        private ?Closure $afterRaw = null,
        private ?string $hostBoundary = null
    ) {
        parent::__construct('production', ['repo_path' => $repoPath]);
    }

    public function describe(): string { return 'offline ideal-onboarding transport'; }
    public function hostRepoBoundaryPath(): ?string { return $this->hostBoundary; }
    protected function wpCommand(array $wpArgs): string { return 'unused'; }
    protected function rawCommand(string $script): string { return 'unused'; }

    public function captureRaw(string $script): array {
        $this->rawCalls[] = $script;
        if ($this->executeRaw) {
            $result = self::process(['bash', '-c', $script]);
            if ($this->afterRaw !== null) {
                ($this->afterRaw)($script, $result);
            }
            return $result;
        }
        return ['exit' => 0, 'stdout' => "wprism-connect-ready\n", 'stderr' => ''];
    }

    public function captureWp(array $wpArgs): array {
        $this->wpCalls[] = $wpArgs;
        if ($wpArgs === ['core', 'is-installed']) {
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }
        return [
            'exit' => 0,
            'stdout' => $this->singleSite ? "single-site\n" : "multisite\n",
            'stderr' => '',
        ];
    }

    public function captureRawBounded(
        string $script,
        int $timeoutMilliseconds,
        int $maxStdoutBytes,
        int $maxStderrBytes
    ): array {
        $this->boundedRawCalls[] = [
            'timeout' => $timeoutMilliseconds,
            'stdout' => $maxStdoutBytes,
            'stderr' => $maxStderrBytes,
        ];
        return $this->captureRaw($script);
    }

    public function captureWpBounded(
        array $wpArgs,
        int $timeoutMilliseconds,
        int $maxStdoutBytes,
        int $maxStderrBytes
    ): array {
        $this->boundedWpCalls[] = [
            'timeout' => $timeoutMilliseconds,
            'stdout' => $maxStdoutBytes,
            'stderr' => $maxStderrBytes,
        ];
        return $this->captureWp($wpArgs);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    public static function process(array $argv, ?string $cwd = null): array {
        return HostProcess::run($argv, $cwd);
    }
}

final class BoundedOnboardingTransport extends Transport {
    public function __construct() {
        parent::__construct('bounded', ['repo_path' => '/fixture/bounded']);
    }

    public function describe(): string { return 'bounded transport regression'; }
    protected function wpCommand(array $wpArgs): string { return implode(' ', $wpArgs); }
    protected function rawCommand(string $script): string { return $script; }
}

final class UnboundedOnboardingDriver implements EnvironmentDriver {
    public int $rawCalls = 0;
    public int $wpCalls = 0;

    public function name(): string { return 'unbounded'; }
    public function driverId(): string { return 'unbounded-fixture'; }
    public function repoPath(): string { return '/fixture/unbounded'; }
    public function describe(): string { return 'unbounded regression fixture'; }
    public function captureRaw(string $script): array { ++$this->rawCalls;
    return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
    public function captureWp(array $wpArgs): array { ++$this->wpCalls;
    return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
    public function streamWp(array $wpArgs): int { return 0; }
    public function wpInstruction(array $wpArgs): string { return 'unused'; }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('unbounded', $this->driverId(), $operation, [
            DriverCapability::ATTACH => true,
            DriverCapability::BOOTSTRAP => true,
            DriverCapability::CODE_TRANSFER => true,
            DriverCapability::RAW_CONTROL => true,
            DriverCapability::WP_CONTROL => true,
        ]);
    }
}

/** @return array<string,mixed> */
function ideal_demo_session(string $root, string $name, int $sourcePort, int $targetPort, string $scenario = 'core'): array {
    $sandbox = $root . '/sandbox';
    return [
        'format' => 'wprism-demo-session/v2',
        'name' => $name,
        'scenario' => $scenario,
        'source_port' => $sourcePort,
        'target_port' => $targetPort,
        'source_repo' => $sandbox . '/siterepo/' . $name . '1',
        'target_repo' => $sandbox . '/siterepo/' . $name . '2',
        'origin' => $sandbox . '/siterepo/origin-' . $name . '.git',
        'compose_file' => $sandbox . '/pair.yml',
        'compose_env_file' => $sandbox . '/tmp/demo-' . $name . '.env',
        'state_file' => $sandbox . '/tmp/demo-' . $name . '.json',
        'phase' => 'starting',
        'ownership_token' => hash('sha256', 'ideal-demo:' . $name),
        'runtime_before' => '',
        'last_applied_revision' => '',
        'pending_revision' => null,
        'owned_paths' => [
            'source_repo' => ['state' => 'planned', 'identity' => null],
            'target_repo' => ['state' => 'planned', 'identity' => null],
            'origin' => ['state' => 'planned', 'identity' => null],
            'compose_env_file' => ['state' => 'planned', 'identity' => null],
        ],
    ];
}

/** @param array<string,mixed> $session @return array<string,mixed> */
function legacy_ideal_demo_session(array $session): array {
    $session['format'] = 'wprism-demo-session/v1';
    unset($session['scenario']);

    return $session;
}

/** @return array{dev:string,ino:string,type:string} */
function ideal_path_identity(string $path): array {
    $stat = lstat($path);
    if (!is_array($stat)) {
        throw new RuntimeException("could not identify fixture path $path");
    }
    return ['dev' => (string) $stat['dev'], 'ino' => (string) $stat['ino'], 'type' => is_dir($path) ? 'directory' : 'file'];
}

/** @param array<string,mixed> $session @param list<string> $fields @return array<string,mixed> */
function own_ideal_demo_paths(array $session, array $fields): array {
    foreach ($fields as $field) {
        $session['owned_paths'][$field] = [
            'state' => 'owned',
            'identity' => ideal_path_identity((string) $session[$field]),
        ];
    }
    return $session;
}

function ideal_file_remote_url(string $path): string {
    if (!str_starts_with($path, '/')) {
        throw new RuntimeException('local Git LFS fixture requires an absolute remote path');
    }
    return 'file://' . $path;
}

/** @return array{driver:IdealOnboardingTransport,target:string,remote:string,url:string,workspace:string} */
function ideal_handoff_fixture(string $tmp, string $label, ?Closure $afterRaw = null, string $branch = 'develop'): array {
    $target = $tmp . '/' . $label . '-target';
    $remote = $tmp . '/' . $label . '-remote.git';
    $workspace = $tmp . '/' . $label . '-workspace';
    foreach ([$target, $target . '/code', $target . '/state', $target . '/media'] as $directory) {
        mkdir($directory, 0700);
    }
    file_put_contents($target . '/site.wprism.json', Adopt::repositorySeedBytes());
    file_put_contents($target . '/.gitattributes', "media/** filter=lfs diff=lfs merge=lfs -text\n");
    file_put_contents($target . '/.gitignore', Adopt::repositoryGitignoreBytes());
    file_put_contents($target . '/code/plugin.php', "<?php\n");
    file_put_contents($target . '/state/baseline.json', "{}\n");
    file_put_contents($target . '/media/README.md', "managed media fixture\n");
    foreach ([
        ['git', 'init', '--initial-branch=' . $branch, $target],
        ['git', 'init', '--bare', '--initial-branch=main', $remote],
    ] as $command) {
        $result = IdealOnboardingTransport::process($command);
        if ($result['exit'] !== 0) {
            throw new RuntimeException('could not prepare handoff fixture: ' . trim($result['stderr']));
        }
    }
    $driver = new IdealOnboardingTransport(true, $target, true, $afterRaw);
    ob_start();
    $exit = ConnectCommand::run([
        'production', '--workspace=' . $workspace, '--transport=local',
        '--wp-path=/var/www/html', '--repo-path=' . $target,
    ], dirname(__DIR__, 4), static fn(string $name, array $config): EnvironmentDriver => $driver);
    ob_end_clean();
    if ($exit !== 0) {
        throw new RuntimeException("could not connect handoff fixture $label");
    }
    return [
        'driver' => $driver,
        'target' => $target,
        'remote' => $remote,
        'url' => ideal_file_remote_url($remote),
        'workspace' => $workspace,
    ];
}

function ideal_push_remote_ref(string $tmp, string $remote, string $label, string $ref): string {
    $source = $tmp . '/' . $label . '-ref-source';
    $initialized = IdealOnboardingTransport::process(['git', 'init', '--initial-branch=main', $source]);
    if ($initialized['exit'] !== 0) {
        throw new RuntimeException('could not initialize remote-ref source: ' . trim($initialized['stderr']));
    }
    file_put_contents($source . '/owned', "$label\n");
    foreach ([
        ['git', '-C', $source, 'add', 'owned'],
        ['git', '-C', $source, '-c', 'user.name=test', '-c', 'user.email=test@example.test', 'commit', '-m', $label],
        ['git', '-C', $source, 'push', $remote, 'HEAD:' . $ref],
    ] as $command) {
        $result = IdealOnboardingTransport::process($command);
        if ($result['exit'] !== 0) {
            throw new RuntimeException('could not publish remote-ref fixture: ' . trim($result['stderr']));
        }
    }
    return trim(IdealOnboardingTransport::process(['git', '-C', $source, 'rev-parse', 'HEAD'])['stdout']);
}

/** @param array<string,mixed> $session */
function write_ideal_demo_session(array $session): void {
    $bytes = json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($bytes)) {
        throw new RuntimeException('could not encode test demo session');
    }
    file_put_contents((string) $session['state_file'], $bytes . "\n");
}

$tmp = sys_get_temp_dir() . '/wprism-ideal-onboarding-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
register_shutdown_function(static function () use ($tmp): void {
    exec('rm -rf ' . escapeshellarg($tmp));
});

$immutableSource = $tmp . '/immutable-distribution';
mkdir($immutableSource . '/agent', 0700, true);
mkdir($immutableSource . '/recovery', 0700, true);
mkdir($immutableSource . '/agent/src/nested', 0700, true);
mkdir($immutableSource . '/agent/src/Recovery', 0700, true);
file_put_contents($immutableSource . '/agent/runtime.php', "<?php\n");
file_put_contents($immutableSource . '/agent/src/nested/runtime.php', "<?php\n");
file_put_contents($immutableSource . '/agent/src/Recovery/DatabaseTargetIdentity.php', "<?php\n");
file_put_contents($immutableSource . '/agent/src/Recovery/RetainedCheckpointCipher.php', "<?php\n");
file_put_contents($immutableSource . '/recovery/runtime.php', "<?php\n");
chmod($immutableSource . '/agent/src/nested', 0500);
chmod($immutableSource . '/agent/src', 0500);
chmod($immutableSource . '/agent', 0500);
$stageMethod = new ReflectionMethod(Adopt::class, 'stageLocalArtifact');
$removeStageMethod = new ReflectionMethod(Adopt::class, 'removeLocalStage');
$immutableStage = $stageMethod->invoke(null, $immutableSource, bin2hex(random_bytes(12)));
wprism_check_same(
    0500,
    fileperms($immutableSource . '/agent') & 0777,
    'adoption leaves the reviewed immutable source agent mode unchanged'
);
wprism_check_same(
    0700,
    fileperms($immutableStage . '/agent') & 0777,
    'adoption makes its disposable staged agent root writable for adapter assembly'
);
wprism_check_same(
    0500,
    fileperms($immutableSource . '/agent/src/nested') & 0777,
    'adoption leaves reviewed immutable descendant modes unchanged'
);
wprism_check_same(
    0700,
    fileperms($immutableStage . '/agent/src/nested') & 0777,
    'adoption makes nested disposable staging directories removable'
);
$removeStageMethod->invoke(null, $immutableStage);
wprism_check(!file_exists($immutableStage), 'adoption completely removes a disposable stage copied from an immutable distribution');
chmod($immutableSource . '/agent', 0700);
chmod($immutableSource . '/agent/src', 0700);
chmod($immutableSource . '/agent/src/nested', 0700);

$workspace = $tmp . '/workspace';
$resolvedWorkspace = (realpath($tmp) ?: $tmp) . '/workspace';
$gitRunner = static function (array $argv, ?string $cwd) use ($tmp): array {
    wprism_check_same(['git', 'init', '--initial-branch=main'], array_slice($argv, 0, 3), 'connect initializes an explicit main-branch Git root');
    wprism_check_same(null, $cwd, 'connect passes the complete workspace path to Git rather than relying on cwd');
    $stage = $argv[3] ?? '';
    wprism_check_same(realpath($tmp), realpath(dirname($stage)), 'connect stages beside the requested destination');
    wprism_check(str_starts_with(basename($stage), '.wprism-connect-'), 'connect uses a private unpredictable staging name');
    return IdealOnboardingTransport::process($argv, $cwd);
};
$probeTransport = new IdealOnboardingTransport();
$factory = static fn(string $name, array $config): EnvironmentDriver => $probeTransport;

ob_start();
$connectExit = ConnectCommand::run([
    'production', '--workspace=' . $workspace, '--transport=local',
    '--wp-path=/var/www/html', '--repo-path=/srv/wprism',
], dirname(__DIR__, 4), $factory, $gitRunner);
$connectOutput = (string) ob_get_clean();
wprism_check_same(0, $connectExit, 'connect succeeds after three native inspection probes');
wprism_check_same(Adopt::repositorySeedBytes(), (string) file_get_contents($workspace . '/site.wprism.json'), 'connect and target adoption share one seed byte source');
wprism_check_same(Adopt::repositoryGitignoreBytes(), (string) file_get_contents($workspace . '/.gitignore'), 'connect publishes the target-compatible local-artifact ignore boundary');
$authorityIgnoreRepo = $tmp . '/authority-ignore-repository';
mkdir($authorityIgnoreRepo . '/.wprism/authority', 0700, true);
mkdir($authorityIgnoreRepo . '/.wprism/control', 0700, true);
file_put_contents($authorityIgnoreRepo . '/.gitignore', Adopt::repositoryGitignoreBytes());
file_put_contents($authorityIgnoreRepo . '/.wprism/authority/authorities.json', "{}\n");
file_put_contents($authorityIgnoreRepo . '/.wprism/authority/release.secret', "test-only-secret\n");
file_put_contents($authorityIgnoreRepo . '/.wprism/control/runtime.json', "{}\n");
IdealOnboardingTransport::process(['git', 'init', '--initial-branch=main', $authorityIgnoreRepo]);
$reviewedPolicyIgnore = IdealOnboardingTransport::process([
    'git', '-C', $authorityIgnoreRepo, 'check-ignore', '--quiet', '.wprism/authority/authorities.json',
]);
$secretIgnore = IdealOnboardingTransport::process([
    'git', '-C', $authorityIgnoreRepo, 'check-ignore', '--quiet', '.wprism/authority/release.secret',
]);
$runtimeIgnore = IdealOnboardingTransport::process([
    'git', '-C', $authorityIgnoreRepo, 'check-ignore', '--quiet', '.wprism/control/runtime.json',
]);
wprism_check_same(1, $reviewedPolicyIgnore['exit'], 'a generated site repository lets the documented release authority policy be tracked normally');
wprism_check_same(0, $secretIgnore['exit'], 'the authority exception does not expose a colocated signing secret');
wprism_check_same(0, $runtimeIgnore['exit'], 'the authority exception leaves WPrism runtime control state ignored');
wprism_check((fileperms($workspace . '/.wprism-envs.json') & 0777) === 0600, 'the privileged machine-local registry is owner-only');
wprism_check(str_contains($connectOutput, 'no explicit mutation') && str_contains($connectOutput, 'site startup code may have run') && str_contains($connectOutput, 'onboard'), 'connect reports the honest WordPress-bootstrap boundary and one next command');
wprism_check_same(['echo wprism-connect-ready'], $probeTransport->rawCalls, 'connect makes only its declared transport reachability probe');
wprism_check_same(
    [['core', 'is-installed'], ['eval', 'echo is_multisite() ? "multisite" : "single-site";']],
    $probeTransport->wpCalls,
    'connect makes only the declared WordPress and topology probes'
);
wprism_check_same(
    [['timeout' => 120000, 'stdout' => 1048576, 'stderr' => 1048576]],
    $probeTransport->boundedRawCalls,
    'connect bounds its target reachability probe'
);
wprism_check_same(
    [
        ['timeout' => 120000, 'stdout' => 1048576, 'stderr' => 1048576],
        ['timeout' => 120000, 'stdout' => 1048576, 'stderr' => 1048576],
    ],
    $probeTransport->boundedWpCalls,
    'connect bounds both WordPress bootstrap probes'
);

$overlay = json_decode((string) file_get_contents($workspace . '/.wprism-envs.json'), true);
wprism_check_same('local', $overlay['envs']['production']['transport'] ?? null, 'connect records the selected transport locally');
wprism_check_same(
    ['format' => 'wprism-local-control-plane/v1'],
    $overlay['envs']['production']['bootstrap'] ?? null,
    'choosing a local target explicitly authorizes the machine-local adoption bootstrap'
);
wprism_check(!isset($overlay['envs']['production']['_dir']), 'loader provenance never leaks into the serialized registry');

$machineWorkspace = $tmp . '/machine-workspace';
$machineTransport = new IdealOnboardingTransport();
ob_start();
$machineConnectExit = ConnectCommand::run([
    'production', '--workspace=' . $machineWorkspace, '--transport=local',
    '--wp-path=/var/www/html', '--repo-path=/srv/wprism', '--format=json',
], dirname(__DIR__, 4), static fn(string $name, array $config): EnvironmentDriver => $machineTransport, null,
    static fn(): string => '2026-09-02T12:34:56Z');
$machineConnectOutput = (string) ob_get_clean();
$machineConnect = json_decode($machineConnectOutput, true, 512, JSON_THROW_ON_ERROR);
ConnectionReceipt::validate($machineConnect);
wprism_check_same(0, $machineConnectExit, 'machine connect emits one validated connection receipt');
wprism_check_same('wprism-connection-receipt/v1', $machineConnect['format'] ?? null, 'machine connect negotiates the public connection receipt format');
wprism_check_same(realpath($machineWorkspace), $machineConnect['workspace']['path'] ?? null, 'the connection receipt binds the exact controller workspace');
wprism_check_same('onboard', $machineConnect['next_action'] ?? null, 'the inspected connection receipt names onboarding as its next action');
wprism_check_same(false, $machineConnect['mutation']['explicit'] ?? null, 'the connection receipt never upgrades inspection into explicit target mutation');

$sentinelRepo = $tmp . '/existing-repository';
mkdir($sentinelRepo . '/.git', 0700, true);
file_put_contents($sentinelRepo . '/sentinel', "owned\n");
ob_start();
$traversalExit = ConnectCommand::run([
    'production', '--workspace=' . $sentinelRepo . '/missing/..', '--transport=local',
    '--wp-path=/var/www/html', '--repo-path=/srv/wprism',
], dirname(__DIR__, 4), $factory);
ob_end_clean();
wprism_check_same(1, $traversalExit, 'connect refuses a missing-parent traversal before staging');
wprism_check(is_dir($sentinelRepo . '/.git') && is_file($sentinelRepo . '/sentinel'), 'a refused workspace cannot clean up an existing parent repository');

$localParent = $tmp . '/local-target-parent';
mkdir($localParent, 0700);
$overlaps = [
    [$tmp . '/same-boundary', $tmp . '/same-boundary', 'equal'],
    [$localParent . '/workspace', $localParent, 'workspace inside target'],
    [$tmp . '/workspace-parent', $tmp . '/workspace-parent/target', 'target inside workspace'],
];
foreach ($overlaps as [$overlapWorkspace, $overlapRepo, $label]) {
    ob_start();
    $overlapExit = ConnectCommand::run([
        'production', '--workspace=' . $overlapWorkspace, '--transport=local',
        '--wp-path=/var/www/html', '--repo-path=' . $overlapRepo,
    ], dirname(__DIR__, 4), $factory, $gitRunner);
    ob_end_clean();
    wprism_check_same(1, $overlapExit, "connect refuses $label local workspace/repo boundaries");
    wprism_check(!file_exists($overlapWorkspace), "connect publishes no workspace for $label boundaries");
}

$caseParent = $tmp . '/case-boundaries';
mkdir($caseParent, 0700);
$caseWorkspace = $caseParent . '/SiteRepo';
ob_start();
$caseExit = ConnectCommand::run([
    'production', '--workspace=' . $caseWorkspace, '--transport=local',
    '--wp-path=/var/www/html', '--repo-path=' . $caseParent . '/siterepo',
], dirname(__DIR__, 4), $factory);
ob_end_clean();
wprism_check_same(1, $caseExit, 'connect conservatively refuses case-only prospective host boundaries');
wprism_check(!file_exists($caseWorkspace), 'case-only overlap refusal publishes no workspace');

$unicodeWorkspace = $caseParent . "/Site-\u{00E9}";
ob_start();
$unicodeExit = ConnectCommand::run([
    'production', '--workspace=' . $unicodeWorkspace, '--transport=local',
    '--wp-path=/var/www/html', '--repo-path=' . $caseParent . "/Site-e\u{0301}",
], dirname(__DIR__, 4), $factory);
ob_end_clean();
wprism_check_same(1, $unicodeExit, 'connect conservatively refuses normalization-only prospective host boundaries');
wprism_check(!file_exists($unicodeWorkspace), 'normalization-only overlap refusal publishes no workspace');
$foldBoundary = new ReflectionMethod(ConnectCommand::class, 'foldComparableBoundary');
$unicodeUpper = $foldBoundary->invoke(null, "/fixture/Site-\u{00C9}", true, false);
$unicodeLower = $foldBoundary->invoke(null, "/fixture/site-\u{00E9}", true, false);
wprism_check_same(
    $unicodeUpper,
    $unicodeLower,
    'Normalizer without mbstring still collapses every non-ASCII prospective segment conservatively'
);

foreach (['publish', 'cleanup'] as $stageRace) {
    $stageWorkspace = $tmp . '/stage-race-' . $stageRace;
    $replacementStage = null;
    $ownedStage = null;
    $stageRunner = static function (array $argv, ?string $cwd) use (
        $stageRace,
        &$replacementStage,
        &$ownedStage
    ): array {
        $stage = (string) ($argv[3] ?? '');
        $initialized = IdealOnboardingTransport::process($argv, $cwd);
        if ($initialized['exit'] !== 0) {
            return $initialized;
        }
        $ownedStage = $stage . '.fixture-owned';
        rename($stage, $ownedStage);
        mkdir($stage, 0700);
        mkdir($stage . '/.git', 0700);
        file_put_contents($stage . '/.wprism-envs.json', "foreign-$stageRace\n");
        $replacementStage = $stage;
        return $stageRace === 'publish'
            ? ['exit' => 0, 'stdout' => '', 'stderr' => '']
            : ['exit' => 9, 'stdout' => '', 'stderr' => 'injected Git failure'];
    };
    ob_start();
    $stageExit = ConnectCommand::run([
        'production', '--workspace=' . $stageWorkspace, '--transport=local',
        '--wp-path=/var/www/html', '--repo-path=/srv/wprism',
    ], dirname(__DIR__, 4), $factory, $stageRunner);
    ob_end_clean();
    wprism_check_same(1, $stageExit, "connect refuses a staging-root replacement before $stageRace");
    wprism_check(!file_exists($stageWorkspace), "staging replacement before $stageRace is never published");
    wprism_check(
        is_string($replacementStage)
            && file_get_contents($replacementStage . '/.wprism-envs.json') === "foreign-$stageRace\n",
        "staging replacement before $stageRace is retained byte-identically"
    );
    wprism_check(is_string($ownedStage) && is_dir($ownedStage), "the displaced owned stage survives the $stageRace fixture");
}

$blockedWorkspace = $tmp . '/multisite';
ob_start();
$blockedExit = ConnectCommand::run([
    'production', '--workspace=' . $blockedWorkspace, '--transport=local',
    '--wp-path=/var/www/html', '--repo-path=/srv/wprism',
], dirname(__DIR__, 4), static fn(string $name, array $config): EnvironmentDriver => new IdealOnboardingTransport(false), $gitRunner);
ob_end_clean();
wprism_check_same(1, $blockedExit, 'connect refuses unsupported topology');
wprism_check(!file_exists($blockedWorkspace), 'a failed inspection probe creates no workspace');

$unboundedDriver = new UnboundedOnboardingDriver();
$unboundedWorkspace = $tmp . '/unbounded-workspace';
ob_start();
$unboundedConnectExit = ConnectCommand::run([
    'unbounded', '--workspace=' . $unboundedWorkspace, '--transport=local',
    '--wp-path=/var/www/html', '--repo-path=/srv/wprism',
], dirname(__DIR__, 4), static fn(): EnvironmentDriver => $unboundedDriver, $gitRunner);
ob_end_clean();
wprism_check_same(1, $unboundedConnectExit, 'connect refuses a driver without the explicit bounded-control protocol');
wprism_check_same(0, $unboundedDriver->rawCalls + $unboundedDriver->wpCalls, 'an unbounded driver is refused before target contact');
wprism_check(!file_exists($unboundedWorkspace), 'an unbounded driver cannot publish a connected workspace');
$unboundedReport = $unboundedDriver->capabilityReport('onboard');
wprism_check(!$unboundedReport->ready(), 'onboard capability negotiation refuses a driver without bounded control');
wprism_check_same(
    DriverCapability::BOUNDED_CONTROL,
    $unboundedReport->blockers()[0]['capability'] ?? null,
    'bounded target control is a declared capability requirement rather than a concrete-class assumption'
);

$originalCwd = getcwd();
chdir($workspace);
$steps = [];
$stepSeams = [
    'handoff_preflight' => static function (EnvironmentDriver $driver, string $repo, string $url) use (&$steps, $resolvedWorkspace): void {
        wprism_check_same($resolvedWorkspace, $repo, 'onboard preflights the workspace connect created');
        $steps[] = 'preflight:' . $url;
    },
    'adopt' => static function (EnvironmentDriver $driver, array $args, string $root) use (&$steps): int {
        $steps[] = 'adopt';
        return 0;
    },
    'assess' => static function (EnvironmentDriver $driver, array $args, string $root) use (&$steps): int {
        $steps[] = 'assess';
        return 0;
    },
    'init' => static function (EnvironmentDriver $driver, array $args) use (&$steps): int {
        $steps[] = 'init:' . implode(',', $args);
        return 0;
    },
    'handoff' => static function (EnvironmentDriver $driver, string $repo, string $url) use (&$steps, $resolvedWorkspace): string {
        wprism_check_same($resolvedWorkspace, $repo, 'onboard resolves the local repository connect created before target work');
        $steps[] = 'handoff:' . $url;
        return 'develop';
    },
];
ob_start();
$onboardExit = OnboardCommand::run(
    new IdealOnboardingTransport(),
    ['--yes', '--offline', '--git-url=ssh://git.example.test/shop.git'],
    dirname(__DIR__, 4),
    $stepSeams
);
$onboardOutput = (string) ob_get_clean();
if (is_string($originalCwd)) {
    chdir($originalCwd);
}
wprism_check_same(0, $onboardExit, 'guided onboarding completes when every existing gate completes');
wprism_check_same(
    ['preflight:ssh://git.example.test/shop.git', 'adopt', 'assess', 'init:--yes,--offline', 'handoff:ssh://git.example.test/shop.git'],
    $steps,
    'guided onboarding verifies handoff authority before target mutation and keeps --git-url out of init'
);
wprism_check(str_contains($onboardOutput, 'Onboarding 1/3') && str_contains($onboardOutput, 'Onboarding 3/3'), 'guided onboarding makes its three phases visible');

$noUrlCwd = getcwd();
chdir($workspace);
ob_start();
$noUrlExit = OnboardCommand::run(
    new IdealOnboardingTransport(),
    ['--yes'],
    dirname(__DIR__, 4),
    [
        'adopt' => static fn(EnvironmentDriver $driver, array $args, string $root): int => 0,
        'assess' => static fn(EnvironmentDriver $driver, array $args, string $root): int => 0,
        'init' => static fn(EnvironmentDriver $driver, array $args): int => 0,
    ]
);
$noUrlOutput = (string) ob_get_clean();
if (is_string($noUrlCwd)) {
    chdir($noUrlCwd);
}
wprism_check_same(0, $noUrlExit, 'onboard may stop cleanly after initialization without a remote');
wprism_check(str_contains($noUrlOutput, '--handoff-only --git-url=<empty-remote-url>'), 'the no-URL handoff prints an exact resumable command');

$handoffFailureSteps = [];
$handoffFailureCwd = getcwd();
chdir($workspace);
ob_start();
$handoffFailureExit = OnboardCommand::run(
    new IdealOnboardingTransport(),
    ['--git-url=https://operator:secret@example.test/repository.git'],
    dirname(__DIR__, 4),
    [
        'handoff_preflight' => static function () use (&$handoffFailureSteps): void { $handoffFailureSteps[] = 'preflight'; },
        'adopt' => static function () use (&$handoffFailureSteps): int { $handoffFailureSteps[] = 'adopt';
        return 0; },
        'assess' => static function () use (&$handoffFailureSteps): int { $handoffFailureSteps[] = 'assess';
        return 0; },
        'init' => static function () use (&$handoffFailureSteps): int { $handoffFailureSteps[] = 'init';
        return 0; },
        'handoff' => static function () use (&$handoffFailureSteps): string {
            $handoffFailureSteps[] = 'handoff';
            throw new RuntimeException('injected post-init target timeout');
        },
        'handoff_resume' => static function () use (&$handoffFailureSteps): void { $handoffFailureSteps[] = 'resume'; },
    ]
);
ob_end_clean();
if (is_string($handoffFailureCwd)) {
    chdir($handoffFailureCwd);
}
wprism_check_same(1, $handoffFailureExit, 'ordinary onboarding reports a bounded target handoff failure after init');
wprism_check_same(
    ['preflight', 'adopt', 'assess', 'init', 'handoff', 'resume'],
    $handoffFailureSteps,
    'a post-init handoff failure renders the resume-only continuation'
);
$resumeMessageMethod = new ReflectionMethod(OnboardCommand::class, 'handoffResumeMessage');
$resumeMessage = (string) $resumeMessageMethod->invoke(
    null,
    dirname(__DIR__, 4),
    new IdealOnboardingTransport()
);
wprism_check(str_contains($resumeMessage, 'onboard \'production\' --handoff-only --git-url=<same-remote-url>'), 'handoff recovery prints one exact resume-only command');
wprism_check(!str_contains($resumeMessage, 'operator:secret'), 'handoff recovery never echoes URL credentials');

$preflightMutations = [];
$preflightCwd = getcwd();
chdir($workspace);
ob_start();
$preflightExit = OnboardCommand::run(
    new IdealOnboardingTransport(),
    ['--git-url=' . $tmp . '/remote-does-not-exist.git'],
    dirname(__DIR__, 4),
    [
        'adopt' => static function () use (&$preflightMutations): int { $preflightMutations[] = 'adopt';
        return 0; },
        'assess' => static function () use (&$preflightMutations): int { $preflightMutations[] = 'assess';
        return 0; },
        'init' => static function () use (&$preflightMutations): int { $preflightMutations[] = 'init';
        return 0; },
    ]
);
ob_end_clean();
if (is_string($preflightCwd)) {
    chdir($preflightCwd);
}
wprism_check_same(1, $preflightExit, 'onboard refuses an unreachable controller remote during preflight');
wprism_check_same([], $preflightMutations, 'handoff preflight failure occurs before adopt, assess, or init');

$targetRepo = $tmp . '/target-repository';
$bareRemote = $tmp . '/published.git';
$bareRemoteUrl = ideal_file_remote_url($bareRemote);
$handoffWorkspace = $tmp . '/handoff-workspace';
foreach ([$targetRepo, $targetRepo . '/code', $targetRepo . '/state', $targetRepo . '/media'] as $directory) {
    mkdir($directory, 0700);
}
file_put_contents($targetRepo . '/site.wprism.json', Adopt::repositorySeedBytes());
file_put_contents($targetRepo . '/.gitattributes', "media/** filter=lfs diff=lfs merge=lfs -text\n");
file_put_contents($targetRepo . '/.gitignore', Adopt::repositoryGitignoreBytes());
file_put_contents($targetRepo . '/code/plugin.php', "<?php\n");
file_put_contents($targetRepo . '/state/baseline.json', "{}\n");
file_put_contents($targetRepo . '/media/README.md', "managed media fixture\n");
$setup = [
    ['git', 'init', '--initial-branch=develop', $targetRepo],
    ['git', 'init', '--bare', '--initial-branch=main', $bareRemote],
];
foreach ($setup as $command) {
    $result = IdealOnboardingTransport::process($command);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('could not prepare the Git handoff fixture: ' . trim($result['stderr']));
    }
}
$handoffDriver = new IdealOnboardingTransport(true, $targetRepo, true);
ob_start();
$handoffConnectExit = ConnectCommand::run([
    'production', '--workspace=' . $handoffWorkspace, '--transport=local',
    '--wp-path=/var/www/html', '--repo-path=' . $targetRepo,
], dirname(__DIR__, 4), static fn(string $name, array $config): EnvironmentDriver => $handoffDriver);
ob_end_clean();
wprism_check_same(0, $handoffConnectExit, 'the real Git handoff fixture starts through connect');
mkdir($handoffWorkspace . '/.wprism/contract/production', 0700, true);
file_put_contents($handoffWorkspace . '/.wprism/contract/production/proposed.json', "{\"format\":\"assessment-artifact-fixture\"}\n");

$beforeHandoffCwd = getcwd();
chdir($handoffWorkspace);
ob_start();
$handoffExit = OnboardCommand::run(
    $handoffDriver,
    ['--yes', '--git-url=' . $bareRemoteUrl],
    dirname(__DIR__, 4),
    [
        'adopt' => static fn(EnvironmentDriver $driver, array $args, string $root): int => 0,
        'assess' => static fn(EnvironmentDriver $driver, array $args, string $root): int => 0,
        'init' => static fn(EnvironmentDriver $driver, array $args): int => 0,
    ]
);
$handoffOutput = (string) ob_get_clean();
if (is_string($beforeHandoffCwd)) {
    chdir($beforeHandoffCwd);
}
$targetHead = IdealOnboardingTransport::process(['git', '-C', $targetRepo, 'rev-parse', 'HEAD']);
$workspaceHead = IdealOnboardingTransport::process(['git', '-C', $handoffWorkspace, 'rev-parse', 'HEAD']);
$workspaceBranch = IdealOnboardingTransport::process(['git', '-C', $handoffWorkspace, 'branch', '--show-current']);
wprism_check_same(0, $handoffExit, 'onboard publishes and checks out the initialized target repository without manual Git commands');
wprism_check_same(trim($targetHead['stdout']), trim($workspaceHead['stdout']), 'developer and target worktrees resolve the same initialized revision');
wprism_check_same('develop', trim($workspaceBranch['stdout']), 'the connected workspace preserves and tracks the target branch');
wprism_check(is_file($handoffWorkspace . '/code/plugin.php'), 'checkout materializes the initialized target payload locally');
wprism_check(
    trim(IdealOnboardingTransport::process(['git', '-C', $handoffWorkspace, 'ls-files', '.gitattributes'])['stdout']) === '.gitattributes'
        && IdealOnboardingTransport::process(['git', '-C', $targetRepo, 'status', '--porcelain', '--', '.gitattributes'])['stdout'] === '',
    'handoff tracks init-owned Git attributes and leaves no source-side canonical drift'
);
wprism_check(is_file($handoffWorkspace . '/.wprism-envs.json'), 'checkout preserves the ignored machine-local environment registry');
wprism_check_same(
    "{\"format\":\"assessment-artifact-fixture\"}\n",
    (string) file_get_contents($handoffWorkspace . '/.wprism/contract/production/proposed.json'),
    'handoff preserves the assessment artifact written by the preceding composed step'
);
wprism_check(str_contains($handoffOutput, 'Published the initialized target baseline'), 'onboard reports the completed automated handoff');
wprism_check(str_contains($handoffOutput, ' assess ') && str_contains($handoffOutput, 'Capture always writes to the target repo_path'), 'onboard recommends a command whose target-worktree effect is explicit');
wprism_check(
    in_array(['timeout' => 900000, 'stdout' => 8388608, 'stderr' => 8388608], $handoffDriver->boundedRawCalls, true),
    'target publication uses the explicit fifteen-minute bounded transfer envelope'
);

$defaultAssessRoot = $tmp . '/default-assess-handoff';
$defaultAssessBuild = IdealOnboardingTransport::process([
    PHP_BINARY,
    dirname(__DIR__, 4) . '/sandbox/tests/fixtures/assess/make-fixture.php',
    $defaultAssessRoot,
]);
if ($defaultAssessBuild['exit'] !== 0) {
    throw new RuntimeException('could not build default assess handoff fixture: ' . trim($defaultAssessBuild['stderr']));
}
$defaultAssessTarget = $defaultAssessRoot . '/repo';
foreach (['code', 'state', 'media'] as $directory) {
    mkdir($defaultAssessTarget . '/' . $directory, 0700, true);
    file_put_contents($defaultAssessTarget . '/' . $directory . '/fixture.txt', $directory . "\n");
}
file_put_contents($defaultAssessTarget . '/.gitignore', Adopt::repositoryGitignoreBytes());
$defaultAssessRemote = $defaultAssessRoot . '/remote.git';
$defaultAssessRemoteInit = IdealOnboardingTransport::process(['git', 'init', '--bare', '--initial-branch=main', $defaultAssessRemote]);
if ($defaultAssessRemoteInit['exit'] !== 0) {
    throw new RuntimeException('could not create default assess remote: ' . trim($defaultAssessRemoteInit['stderr']));
}
$defaultAssessWorkspace = $defaultAssessRoot . '/workspace';
$defaultCalls = $defaultAssessRoot . '/calls.txt';
file_put_contents($defaultCalls, '');
$savedPath = getenv('PATH');
$savedFixtures = getenv('WPRISM_FIXTURES');
$savedSiteRepo = getenv('WPRISM_SITE_REPO');
$savedCalls = getenv('WPRISM_CALLS');
putenv('PATH=' . $defaultAssessRoot . '/bin:' . (is_string($savedPath) ? $savedPath : ''));
putenv('WPRISM_FIXTURES=' . $defaultAssessRoot . '/fixtures');
putenv('WPRISM_SITE_REPO=' . $defaultAssessTarget);
putenv('WPRISM_CALLS=' . $defaultCalls);
ob_start();
$defaultAssessConnect = ConnectCommand::run([
    'fixture', '--workspace=' . $defaultAssessWorkspace, '--transport=local',
    '--wp-path=' . $defaultAssessRoot . '/wordpress', '--repo-path=' . $defaultAssessTarget,
], dirname(__DIR__, 4));
ob_end_clean();
wprism_check_same(0, $defaultAssessConnect, 'the default-assess integration starts through a real local connect');
$defaultAssessDriver = new LocalTransport('fixture', [
    'transport' => 'local',
    'wp_path' => $defaultAssessRoot . '/wordpress',
    'repo_path' => $defaultAssessTarget,
    'bootstrap' => ['format' => LocalTransport::BOOTSTRAP_FORMAT],
    '_machine_local' => true,
    '_dir' => $defaultAssessWorkspace,
]);
$proposalBeforeHandoff = null;
$defaultAssessCwd = getcwd();
chdir($defaultAssessWorkspace);
ob_start();
$defaultAssessExit = OnboardCommand::run(
    $defaultAssessDriver,
    ['--git-url=' . $defaultAssessRemote],
    dirname(__DIR__, 4),
    [
        'adopt' => static fn(EnvironmentDriver $driver, array $args, string $root): int => 0,
        'init' => static function () use ($defaultAssessWorkspace, &$proposalBeforeHandoff): int {
            $proposalBeforeHandoff = file_get_contents(
                $defaultAssessWorkspace . '/.wprism/contract/fixture/proposed.json'
            );
            return is_string($proposalBeforeHandoff) ? 0 : 1;
        },
    ]
);
ob_end_clean();
if (is_string($defaultAssessCwd)) {
    chdir($defaultAssessCwd);
}
foreach ([
    'PATH' => $savedPath,
    'WPRISM_FIXTURES' => $savedFixtures,
    'WPRISM_SITE_REPO' => $savedSiteRepo,
    'WPRISM_CALLS' => $savedCalls,
] as $name => $value) {
    is_string($value) ? putenv($name . '=' . $value) : putenv($name);
}
wprism_check_same(0, $defaultAssessExit, 'onboard completes with the real default assessment step and Git handoff');
wprism_check(is_string($proposalBeforeHandoff) && $proposalBeforeHandoff !== '', 'the default assessment writes its proposal before init and handoff');
wprism_check_same(
    $proposalBeforeHandoff,
    file_get_contents($defaultAssessWorkspace . '/.wprism/contract/fixture/proposed.json'),
    'the real handoff preserves the default assessment proposal byte-identically'
);
wprism_check(is_file($defaultAssessWorkspace . '/.wprism-envs.json'), 'the default-assess handoff preserves its machine-local registry');
wprism_check_same(
    trim(IdealOnboardingTransport::process(['git', '-C', $defaultAssessTarget, 'rev-parse', 'HEAD'])['stdout']),
    trim(IdealOnboardingTransport::process(['git', '-C', $defaultAssessWorkspace, 'rev-parse', 'HEAD'])['stdout']),
    'the default-assess target and controller finish on the same published revision'
);
$handoffStatusCwd = getcwd();
chdir($defaultAssessWorkspace);
ob_start();
$handoffStatusExit = OnboardCommand::run(
    $defaultAssessDriver,
    ['status', '--git-url=' . $defaultAssessRemote, '--format=json'],
    dirname(__DIR__, 4)
);
$handoffStatusOutput = (string) ob_get_clean();
ob_start();
$handoffReplayExit = OnboardCommand::run(
    $defaultAssessDriver,
    ['status', '--git-url=' . $defaultAssessRemote, '--format=json'],
    dirname(__DIR__, 4)
);
$handoffReplayOutput = (string) ob_get_clean();
if (is_string($handoffStatusCwd)) {
    chdir($handoffStatusCwd);
}
$handoffStatus = json_decode($handoffStatusOutput, true, 512, JSON_THROW_ON_ERROR);
OnboardingHandoffReceipt::validate($handoffStatus);
wprism_check_same(0, $handoffStatusExit, 'onboard status reconciles one complete machine-readable handoff');
wprism_check_same(0, $handoffReplayExit, 'onboard status can reconcile the same handoff again without mutation');
wprism_check_same($handoffStatusOutput, $handoffReplayOutput, 'handoff reconciliation returns byte-identical canonical evidence while its inputs are unchanged');
wprism_check_same('wprism-onboarding-handoff/v1', $handoffStatus['format'] ?? null, 'handoff status negotiates the public adoption receipt format');
wprism_check_same(
    trim(IdealOnboardingTransport::process(['git', '-C', $defaultAssessTarget, 'rev-parse', 'HEAD'])['stdout']),
    $handoffStatus['repository']['commit'] ?? null,
    'the handoff receipt binds the exact target/controller/remote commit'
);
wprism_check_same('proposed', $handoffStatus['application_contract']['status'] ?? null, 'the handoff truthfully reports a proposal as non-authoritative');
wprism_check_same('review_application_contract', $handoffStatus['next_action'] ?? null, 'the handoff stops at the human application-contract boundary');
wprism_check(
    is_string($handoffStatus['target']['id'] ?? null)
        && str_starts_with($handoffStatus['target']['id'], 'wprism-target:'),
    'the handoff receipt binds WPrism\'s stable target operation identity'
);

$localAttributes = $defaultAssessWorkspace . '/.gitattributes';
$attributesExisted = is_file($localAttributes) && !is_link($localAttributes);
$attributesBytes = $attributesExisted ? file_get_contents($localAttributes) : null;
file_put_contents($localAttributes, (is_string($attributesBytes) ? $attributesBytes : '') . "# local drift\n");
$attributesStatusCwd = getcwd();
chdir($defaultAssessWorkspace);
ob_start();
$attributesStatusExit = OnboardCommand::run(
    $defaultAssessDriver,
    ['status', '--git-url=' . $defaultAssessRemote, '--format=json'],
    dirname(__DIR__, 4)
);
$attributesStatusOutput = (string) ob_get_clean();
if (is_string($attributesStatusCwd)) {
    chdir($attributesStatusCwd);
}
if ($attributesExisted && is_string($attributesBytes)) {
    file_put_contents($localAttributes, $attributesBytes);
} else {
    unlink($localAttributes);
}
$attributesRefusal = json_decode($attributesStatusOutput, true, 512, JSON_THROW_ON_ERROR);
wprism_check_same(1, $attributesStatusExit, 'handoff status refuses local .gitattributes drift');
wprism_check_same(
    'onboarding_handoff_unavailable',
    $attributesRefusal['reason_code'] ?? null,
    'local .gitattributes drift crosses the same closed handoff reconciliation boundary'
);

$tagFixture = ideal_handoff_fixture($tmp, 'tag-only');
$tagSource = $tmp . '/tag-source';
IdealOnboardingTransport::process(['git', 'init', '--initial-branch=main', $tagSource]);
file_put_contents($tagSource . '/tagged', "tagged\n");
foreach ([
    ['git', '-C', $tagSource, 'add', 'tagged'],
    ['git', '-C', $tagSource, '-c', 'user.name=test', '-c', 'user.email=test@example.test', 'commit', '-m', 'tag only'],
    ['git', '-C', $tagSource, 'tag', 'v1'],
    ['git', '-C', $tagSource, 'push', $tagFixture['url'], 'refs/tags/v1'],
] as $command) {
    $result = IdealOnboardingTransport::process($command);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('could not prepare tag-only remote: ' . trim($result['stderr']));
    }
}
$tagMutations = [];
$tagCwd = getcwd();
chdir($tagFixture['workspace']);
ob_start();
$tagExit = OnboardCommand::run(
    $tagFixture['driver'],
    ['--git-url=' . $tagFixture['url']],
    dirname(__DIR__, 4),
    [
        'adopt' => static function () use (&$tagMutations): int { $tagMutations[] = 'adopt';
        return 0; },
        'assess' => static function () use (&$tagMutations): int { $tagMutations[] = 'assess';
        return 0; },
        'init' => static function () use (&$tagMutations): int { $tagMutations[] = 'init';
        return 0; },
    ]
);
ob_end_clean();
if (is_string($tagCwd)) {
    chdir($tagCwd);
}
wprism_check_same(1, $tagExit, 'onboard refuses a tag-only remote as nonempty');
wprism_check_same([], $tagMutations, 'a tag-only remote refuses before adopt, assess, or init');

foreach ([
    'handoff-tag' => 'refs/tags/v2',
    'handoff-branch' => 'refs/heads/unrelated',
    'handoff-custom' => 'refs/custom/owned',
] as $label => $foreignRef) {
    $remoteRefFixture = ideal_handoff_fixture($tmp, $label);
    $foreignRevision = ideal_push_remote_ref($tmp, $remoteRefFixture['url'], $label, $foreignRef);
    $remoteRefCwd = getcwd();
    chdir($remoteRefFixture['workspace']);
    ob_start();
    $remoteRefExit = OnboardCommand::run(
        $remoteRefFixture['driver'],
        ['--handoff-only', '--git-url=' . $remoteRefFixture['url']],
        dirname(__DIR__, 4)
    );
    ob_end_clean();
    if (is_string($remoteRefCwd)) {
        chdir($remoteRefCwd);
    }
    $foreignReadback = IdealOnboardingTransport::process([
        'git', '--git-dir=' . $remoteRefFixture['remote'], 'rev-parse', '--verify', $foreignRef,
    ]);
    $publishedReadback = IdealOnboardingTransport::process([
        'git', '--git-dir=' . $remoteRefFixture['remote'], 'rev-parse', '--verify', 'refs/heads/develop',
    ]);
    wprism_check_same(1, $remoteRefExit, "handoff-only refuses a remote carrying $foreignRef");
    wprism_check_same($foreignRevision, trim($foreignReadback['stdout']), "handoff-only preserves $foreignRef");
    wprism_check($publishedReadback['exit'] !== 0, "handoff-only discloses no target baseline beside $foreignRef");
}

$postPreflightFixture = ideal_handoff_fixture($tmp, 'post-preflight-ref');
$postPreflightCwd = getcwd();
chdir($postPreflightFixture['workspace']);
ob_start();
$postPreflightExit = OnboardCommand::run(
    $postPreflightFixture['driver'],
    ['--git-url=' . $postPreflightFixture['url']],
    dirname(__DIR__, 4),
    [
        'adopt' => static fn(): int => 0,
        'assess' => static function () use ($tmp, $postPreflightFixture): int {
            ideal_push_remote_ref(
                $tmp,
                $postPreflightFixture['url'],
                'post-preflight-injected',
                'refs/tags/appeared-after-preflight'
            );
            return 0;
        },
        'init' => static fn(): int => 0,
    ]
);
ob_end_clean();
if (is_string($postPreflightCwd)) {
    chdir($postPreflightCwd);
}
wprism_check_same(1, $postPreflightExit, 'onboard rechecks every remote ref after adopt/assess/init');
wprism_check(
    IdealOnboardingTransport::process([
        'git', '--git-dir=' . $postPreflightFixture['remote'], 'rev-parse', '--verify', 'refs/heads/develop',
    ])['exit'] !== 0,
    'a post-preflight remote ref race receives no target baseline'
);

$unrelatedFixture = ideal_handoff_fixture($tmp, 'unrelated-local');
file_put_contents($unrelatedFixture['workspace'] . '/notes.txt', "controller work\n");
$unrelatedMutations = [];
$unrelatedCwd = getcwd();
chdir($unrelatedFixture['workspace']);
ob_start();
$unrelatedExit = OnboardCommand::run(
    $unrelatedFixture['driver'],
    ['--git-url=' . $unrelatedFixture['url']],
    dirname(__DIR__, 4),
    [
        'adopt' => static function () use (&$unrelatedMutations): int { $unrelatedMutations[] = 'adopt';
        return 0; },
        'assess' => static function () use (&$unrelatedMutations): int { $unrelatedMutations[] = 'assess';
        return 0; },
        'init' => static function () use (&$unrelatedMutations): int { $unrelatedMutations[] = 'init';
        return 0; },
    ]
);
ob_end_clean();
if (is_string($unrelatedCwd)) {
    chdir($unrelatedCwd);
}
wprism_check_same(1, $unrelatedExit, 'normal onboarding refuses unrelated local bytes during preflight');
wprism_check_same([], $unrelatedMutations, 'unrelated local bytes refuse before adopt, assess, or init');
wprism_check(is_file($unrelatedFixture['workspace'] . '/notes.txt'), 'preflight preserves the unrelated controller file');
wprism_check(IdealOnboardingTransport::process(['git', '-C', $unrelatedFixture['target'], 'rev-parse', '--verify', 'HEAD'])['exit'] !== 0, 'preflight local-work refusal occurs before a target commit');

$registryRaceFixture = ideal_handoff_fixture($tmp, 'registry-race');
$registryRaceOriginal = $registryRaceFixture['workspace'] . '/.wprism-envs.original';
$registryRaceCwd = getcwd();
chdir($registryRaceFixture['workspace']);
ob_start();
$registryRaceExit = OnboardCommand::run(
    $registryRaceFixture['driver'],
    ['--git-url=' . $registryRaceFixture['url']],
    dirname(__DIR__, 4),
    [
        'adopt' => static fn(): int => 0,
        'assess' => static function () use ($registryRaceFixture, $registryRaceOriginal): int {
            rename($registryRaceFixture['workspace'] . '/.wprism-envs.json', $registryRaceOriginal);
            file_put_contents($registryRaceFixture['workspace'] . '/.wprism-envs.json', "foreign registry\n");
            return 0;
        },
        'init' => static fn(): int => 0,
    ]
);
ob_end_clean();
if (is_string($registryRaceCwd)) {
    chdir($registryRaceCwd);
}
wprism_check_same(1, $registryRaceExit, 'onboard refuses a machine-local registry replacement during assessment');
wprism_check_same(
    "foreign registry\n",
    file_get_contents($registryRaceFixture['workspace'] . '/.wprism-envs.json'),
    'assessment-roundtrip refusal preserves the replacement registry'
);
wprism_check(
    IdealOnboardingTransport::process(['git', '-C', $registryRaceFixture['target'], 'rev-parse', '--verify', 'HEAD'])['exit'] !== 0,
    'registry authority loss refuses before a target publication commit'
);

$commitFixture = ideal_handoff_fixture($tmp, 'local-commit');
foreach ([
    ['git', '-C', $commitFixture['workspace'], 'add', 'site.wprism.json', '.gitignore'],
    ['git', '-C', $commitFixture['workspace'], '-c', 'user.name=test', '-c', 'user.email=test@example.test', 'commit', '-m', 'local work'],
] as $command) {
    $result = IdealOnboardingTransport::process($command);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('could not prepare local-work refusal: ' . trim($result['stderr']));
    }
}
$localCommit = trim(IdealOnboardingTransport::process(['git', '-C', $commitFixture['workspace'], 'rev-parse', 'HEAD'])['stdout']);
$commitCwd = getcwd();
chdir($commitFixture['workspace']);
ob_start();
$commitExit = OnboardCommand::run(
    $commitFixture['driver'],
    ['--handoff-only', '--git-url=' . $commitFixture['url']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($commitCwd)) {
    chdir($commitCwd);
}
wprism_check_same(1, $commitExit, 'handoff refuses a controller workspace with local Git work');
wprism_check_same($localCommit, trim(IdealOnboardingTransport::process(['git', '-C', $commitFixture['workspace'], 'rev-parse', 'HEAD'])['stdout']), 'handoff refusal preserves the controller commit and visible branch');
wprism_check(IdealOnboardingTransport::process(['git', '-C', $commitFixture['target'], 'rev-parse', '--verify', 'HEAD'])['exit'] !== 0, 'controller-work refusal occurs before a target commit');

$stagedTargetFixture = ideal_handoff_fixture($tmp, 'staged-target');
file_put_contents($stagedTargetFixture['target'] . '/.wprism-envs.json', "target secret\n");
$forceStage = IdealOnboardingTransport::process([
    'git', '-C', $stagedTargetFixture['target'], 'add', '-f', '.wprism-envs.json',
]);
if ($forceStage['exit'] !== 0) {
    throw new RuntimeException('could not stage target disclosure fixture: ' . trim($forceStage['stderr']));
}
$stagedTargetCwd = getcwd();
chdir($stagedTargetFixture['workspace']);
ob_start();
$stagedTargetExit = OnboardCommand::run(
    $stagedTargetFixture['driver'],
    ['--handoff-only', '--git-url=' . $stagedTargetFixture['url']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($stagedTargetCwd)) {
    chdir($stagedTargetCwd);
}
wprism_check_same(1, $stagedTargetExit, 'handoff refuses a nonempty target index before managed staging');
wprism_check(
    IdealOnboardingTransport::process([
        'git', '--git-dir=' . $stagedTargetFixture['remote'], 'for-each-ref', '--format=%(refname)',
    ])['stdout'] === '',
    'a force-staged machine-local target registry is never disclosed'
);

$historyFixture = ideal_handoff_fixture($tmp, 'existing-history');
foreach ([
    ['git', '-C', $historyFixture['target'], 'add', '.gitignore', 'site.wprism.json', 'code', 'state', 'media'],
    ['git', '-C', $historyFixture['target'], '-c', 'user.name=existing', '-c', 'user.email=existing@example.test', 'commit', '-m', 'existing history'],
] as $command) {
    $result = IdealOnboardingTransport::process($command);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('could not prepare existing-history fixture: ' . trim($result['stderr']));
    }
}
$historyHead = trim(IdealOnboardingTransport::process(['git', '-C', $historyFixture['target'], 'rev-parse', 'HEAD'])['stdout']);
$historyCwd = getcwd();
chdir($historyFixture['workspace']);
ob_start();
$historyExit = OnboardCommand::run(
    $historyFixture['driver'],
    ['--handoff-only', '--git-url=' . $historyFixture['url']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($historyCwd)) {
    chdir($historyCwd);
}
wprism_check_same(1, $historyExit, 'guided initial publication refuses pre-existing target history without a WPrism receipt');
wprism_check_same(
    $historyHead,
    trim(IdealOnboardingTransport::process(['git', '-C', $historyFixture['target'], 'rev-parse', 'HEAD'])['stdout']),
    'existing target history is retained unchanged'
);
wprism_check(
    IdealOnboardingTransport::process([
        'git', '--git-dir=' . $historyFixture['remote'], 'for-each-ref', '--format=%(refname)',
    ])['stdout'] === '',
    'existing target history is not pushed to the empty remote'
);

$corrected = ideal_handoff_fixture($tmp, 'corrected-url', null, 'main');
$correctedCwd = getcwd();
chdir($corrected['workspace']);
ob_start();
$badUrlExit = OnboardCommand::run(
    $corrected['driver'],
    ['--handoff-only', '--git-url=' . $tmp . '/missing-corrected.git'],
    dirname(__DIR__, 4)
);
ob_end_clean();
$targetRemotesAfterBadUrl = IdealOnboardingTransport::process(['git', '-C', $corrected['target'], 'remote']);
$targetRefsAfterBadUrl = IdealOnboardingTransport::process([
    'git', '-C', $corrected['target'], 'for-each-ref', '--format=%(refname)',
]);
ob_start();
$correctedExit = OnboardCommand::run(
    $corrected['driver'],
    ['--handoff-only', '--git-url=' . $corrected['url']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($correctedCwd)) {
    chdir($correctedCwd);
}
wprism_check_same(1, $badUrlExit, 'handoff-only reports an unreachable first URL');
wprism_check_same('', trim($targetRemotesAfterBadUrl['stdout']), 'an unreachable URL is not persisted as target origin');
wprism_check_same('', trim($targetRefsAfterBadUrl['stdout']), 'controller-only authentication failure leaves target publication refs untouched');
wprism_check_same(0, $correctedExit, 'handoff-only accepts a corrected reachable URL without repeating initialization');
wprism_check_same('main', trim(IdealOnboardingTransport::process(['git', '-C', $corrected['workspace'], 'branch', '--show-current'])['stdout']), 'handoff supports the connect-default branch without force-resetting an existing ref');

$exactRetryWorkspace = $tmp . '/exact-retry-workspace';
$exactRetryInjected = false;
$exactRetryHook = static function (string $script, array $result) use (
    $exactRetryWorkspace,
    &$exactRetryInjected
): void {
    if ($exactRetryInjected || !str_contains($result['stdout'], 'WPRISM_HANDOFF ')) {
        return;
    }
    $exactRetryInjected = true;
    file_put_contents($exactRetryWorkspace . '/.wprism-envs.json', "temporary controller race\n");
};
$exactRetry = ideal_handoff_fixture($tmp, 'exact-retry', $exactRetryHook);
$exactRegistry = (string) file_get_contents($exactRetry['workspace'] . '/.wprism-envs.json');
$exactRetryCwd = getcwd();
chdir($exactRetry['workspace']);
ob_start();
$exactFirstExit = OnboardCommand::run(
    $exactRetry['driver'],
    ['--handoff-only', '--git-url=' . $exactRetry['url']],
    dirname(__DIR__, 4)
);
ob_end_clean();
file_put_contents($exactRetry['workspace'] . '/.wprism-envs.json', $exactRegistry);
ob_start();
$exactSecondExit = OnboardCommand::run(
    $exactRetry['driver'],
    ['--handoff-only', '--git-url=' . $exactRetry['url']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($exactRetryCwd)) {
    chdir($exactRetryCwd);
}
wprism_check_same(1, $exactFirstExit, 'handoff pauses when the controller changes after exact target publication');
wprism_check_same(0, $exactSecondExit, 'handoff-only accepts the one exact prior WPrism branch/revision on retry');

$pushUrlFixture = ideal_handoff_fixture($tmp, 'push-url');
$wrongPushRemote = $tmp . '/wrong-push.git';
IdealOnboardingTransport::process(['git', 'init', '--bare', '--initial-branch=main', $wrongPushRemote]);
foreach ([
    ['git', '-C', $pushUrlFixture['target'], 'remote', 'add', 'origin', $pushUrlFixture['url']],
    ['git', '-C', $pushUrlFixture['target'], 'remote', 'set-url', '--push', 'origin', $wrongPushRemote],
] as $command) {
    $result = IdealOnboardingTransport::process($command);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('could not prepare divergent push URL: ' . trim($result['stderr']));
    }
}
$pushUrlCwd = getcwd();
chdir($pushUrlFixture['workspace']);
ob_start();
$pushUrlExit = OnboardCommand::run(
    $pushUrlFixture['driver'],
    ['--handoff-only', '--git-url=' . $pushUrlFixture['url']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($pushUrlCwd)) {
    chdir($pushUrlCwd);
}
wprism_check_same(1, $pushUrlExit, 'handoff refuses a target origin with a divergent push URL');
wprism_check_same('', trim(IdealOnboardingTransport::process(['git', '--git-dir=' . $pushUrlFixture['remote'], 'for-each-ref', '--format=%(refname)'])['stdout']), 'divergent push URL refusal leaves the reviewed remote empty');
wprism_check_same('', trim(IdealOnboardingTransport::process(['git', '--git-dir=' . $wrongPushRemote, 'for-each-ref', '--format=%(refname)'])['stdout']), 'divergent push URL refusal discloses nothing to the alternate remote');

$localPushUrlFixture = ideal_handoff_fixture($tmp, 'local-push-url');
$localWrongPush = $tmp . '/local-wrong-push.git';
IdealOnboardingTransport::process(['git', 'init', '--bare', '--initial-branch=main', $localWrongPush]);
foreach ([
    ['git', '-C', $localPushUrlFixture['workspace'], 'remote', 'add', 'origin', $localPushUrlFixture['url']],
    ['git', '-C', $localPushUrlFixture['workspace'], 'remote', 'set-url', '--push', 'origin', $localWrongPush],
] as $command) {
    $result = IdealOnboardingTransport::process($command);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('could not prepare local divergent push URL: ' . trim($result['stderr']));
    }
}
$localPushCwd = getcwd();
chdir($localPushUrlFixture['workspace']);
ob_start();
$localPushExit = OnboardCommand::run(
    $localPushUrlFixture['driver'],
    ['--handoff-only', '--git-url=' . $localPushUrlFixture['url']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($localPushCwd)) {
    chdir($localPushCwd);
}
wprism_check_same(1, $localPushExit, 'handoff refuses a local origin with a divergent push URL');
wprism_check(
    IdealOnboardingTransport::process(['git', '-C', $localPushUrlFixture['target'], 'rev-parse', '--verify', 'HEAD'])['exit'] !== 0,
    'local push-URL refusal occurs before a target commit'
);
wprism_check_same(
    '',
    trim(IdealOnboardingTransport::process(['git', '--git-dir=' . $localWrongPush, 'for-each-ref', '--format=%(refname)'])['stdout']),
    'local push-URL refusal discloses nothing to the alternate remote'
);

$raceTarget = $tmp . '/receipt-race-target';
$raceRemote = ideal_file_remote_url($tmp . '/receipt-race-remote.git');
$raceInjected = false;
$raceHook = static function (string $script, array $result) use ($raceTarget, $raceRemote, &$raceInjected): void {
    if ($raceInjected || !str_contains($result['stdout'], 'WPRISM_HANDOFF ')) {
        return;
    }
    $raceInjected = true;
    file_put_contents($raceTarget . '/code/race.php', "<?php // raced\n");
    foreach ([
        ['git', '-C', $raceTarget, 'add', 'code/race.php'],
        ['git', '-C', $raceTarget, '-c', 'user.name=race', '-c', 'user.email=race@example.test', 'commit', '-m', 'race remote'],
        ['git', '-C', $raceTarget, 'push', '--force', $raceRemote, 'HEAD:refs/heads/develop'],
    ] as $command) {
        $changed = IdealOnboardingTransport::process($command);
        if ($changed['exit'] !== 0) {
            throw new RuntimeException('could not advance race remote: ' . trim($changed['stderr']));
        }
    }
};
$raceFixture = ideal_handoff_fixture($tmp, 'receipt-race', $raceHook);
$raceCwd = getcwd();
chdir($raceFixture['workspace']);
ob_start();
$raceExit = OnboardCommand::run(
    $raceFixture['driver'],
    ['--handoff-only', '--git-url=' . $raceFixture['url']],
    dirname(__DIR__, 4)
);
ob_end_clean();
$raceSeed = file_get_contents($raceFixture['workspace'] . '/site.wprism.json');
$raceBranch = IdealOnboardingTransport::process(['git', '-C', $raceFixture['workspace'], 'symbolic-ref', '--short', 'HEAD']);
ob_start();
$raceRetry = OnboardCommand::run(
    $raceFixture['driver'],
    ['--handoff-only', '--git-url=' . $raceFixture['url']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($raceCwd)) {
    chdir($raceCwd);
}
wprism_check_same(1, $raceExit, 'handoff refuses a branch that moved after the target receipt');
wprism_check_same(Adopt::repositorySeedBytes(), $raceSeed, 'receipt race refuses before moving the local generated boundary');
wprism_check_same('main', trim($raceBranch['stdout']), 'receipt race leaves the controller on its original unborn branch');
wprism_check_same(1, $raceRetry, 'handoff retry refuses target history that moved outside its durable WPrism receipt');

$localRaceWorkspace = $tmp . '/local-roundtrip-race-workspace';
$localRaceCommit = '';
$localRaceHook = static function (string $script, array $result) use ($localRaceWorkspace, &$localRaceCommit): void {
    if ($localRaceCommit !== '' || !str_contains($result['stdout'], 'WPRISM_HANDOFF ')) {
        return;
    }
    foreach ([
        ['git', '-C', $localRaceWorkspace, 'add', 'site.wprism.json', '.gitignore'],
        ['git', '-C', $localRaceWorkspace, '-c', 'user.name=local-race', '-c', 'user.email=local@example.test', 'commit', '-m', 'local concurrent work'],
    ] as $command) {
        $changed = IdealOnboardingTransport::process($command);
        if ($changed['exit'] !== 0) {
            throw new RuntimeException('could not create local handoff race: ' . trim($changed['stderr']));
        }
    }
    $localRaceCommit = trim(IdealOnboardingTransport::process(['git', '-C', $localRaceWorkspace, 'rev-parse', 'HEAD'])['stdout']);
};
$localRaceFixture = ideal_handoff_fixture($tmp, 'local-roundtrip-race', $localRaceHook);
$localRaceCwd = getcwd();
chdir($localRaceFixture['workspace']);
ob_start();
$localRaceExit = OnboardCommand::run(
    $localRaceFixture['driver'],
    ['--handoff-only', '--git-url=' . $localRaceFixture['url']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($localRaceCwd)) {
    chdir($localRaceCwd);
}
wprism_check_same(1, $localRaceExit, 'handoff refuses local Git work created during the target publication round trip');
wprism_check($localRaceCommit !== '', 'the local race fixture created a real controller commit after target publication');
wprism_check_same($localRaceCommit, trim(IdealOnboardingTransport::process(['git', '-C', $localRaceWorkspace, 'rev-parse', 'HEAD'])['stdout']), 'round-trip race refusal preserves the concurrent controller commit and ref');
wprism_check_same(Adopt::repositorySeedBytes(), (string) file_get_contents($localRaceWorkspace . '/site.wprism.json'), 'round-trip race refusal preserves the controller seed bytes');

[$initArgs, $gitUrl, $handoffOnly] = OnboardCommand::options(['--yes', '--git-url=https://example.test/repo.git']);
wprism_check_same(['--yes'], $initArgs, 'onboard forwards init flags unchanged');
wprism_check(!in_array('--configure-database', $initArgs, true),
    'ordinary --yes does not opt in to database privilege setup');
wprism_check_same('https://example.test/repo.git', $gitUrl, 'onboard extracts one handoff URL');
wprism_check_same(false, $handoffOnly, 'ordinary onboarding does not select the resume-only path');
[$resumeArgs, $resumeUrl, $resumeOnly] = OnboardCommand::options(['--handoff-only', '--git-url=https://example.test/resume.git']);
wprism_check_same([], $resumeArgs, 'handoff-only forwards no init arguments');
wprism_check_same('https://example.test/resume.git', $resumeUrl, 'handoff-only retains the requested remote');
wprism_check_same(true, $resumeOnly, 'handoff-only selects the resumable publication path');

foreach ([
    ['--configure-database'],
    ['--database-service=db'],
    ['--configure-database', '--database-service='],
] as $invalidDatabaseSetup) {
    wprism_check_throws(
        static fn() => OnboardCommand::options($invalidDatabaseSetup),
        RuntimeException::class,
        'an incomplete or empty database setup selection refuses at option parsing'
    );
}
$invalidDatabaseSteps = [];
$invalidDatabaseCwd = getcwd();
chdir($workspace);
ob_start();
$invalidDatabaseExit = OnboardCommand::run(
    new IdealOnboardingTransport(),
    ['--configure-database', '--database-service=db'],
    dirname(__DIR__, 4),
    [
        'adopt' => static function () use (&$invalidDatabaseSteps): int { $invalidDatabaseSteps[] = 'adopt';
        return 0; },
        'assess' => static function () use (&$invalidDatabaseSteps): int { $invalidDatabaseSteps[] = 'assess';
        return 0; },
        'init' => static function () use (&$invalidDatabaseSteps): int { $invalidDatabaseSteps[] = 'init';
        return 0; },
    ]
);
ob_end_clean();
if (is_string($invalidDatabaseCwd)) {
    chdir($invalidDatabaseCwd);
}
wprism_check_same(1, $invalidDatabaseExit,
    'database setup refuses a non-Docker onboarding request before adoption');
wprism_check_same([], $invalidDatabaseSteps,
    'database setup transport validation performs no adopt, assess, or init mutation');

$resumeSteps = [];
$resumeCwd = getcwd();
chdir($workspace);
ob_start();
$resumeExit = OnboardCommand::run(
    new IdealOnboardingTransport(),
    ['--handoff-only', '--git-url=ssh://git.example.test/resume.git'],
    dirname(__DIR__, 4),
    [
        'adopt' => static function () use (&$resumeSteps): int { $resumeSteps[] = 'adopt';
        return 0; },
        'assess' => static function () use (&$resumeSteps): int { $resumeSteps[] = 'assess';
        return 0; },
        'init' => static function () use (&$resumeSteps): int { $resumeSteps[] = 'init';
        return 0; },
        'controller_preflight' => static fn(): array => ['exit' => 0, 'stdout' => '', 'stderr' => ''],
        'handoff_preflight' => static function () use (&$resumeSteps): void { $resumeSteps[] = 'preflight'; },
        'handoff' => static function () use (&$resumeSteps): string { $resumeSteps[] = 'handoff';
        return 'main'; },
    ]
);
ob_end_clean();
if (is_string($resumeCwd)) {
    chdir($resumeCwd);
}
wprism_check_same(0, $resumeExit, 'handoff-only resumes publication after a completed init');
wprism_check_same(['handoff'], $resumeSteps, 'handoff-only never repeats adoption, assessment, initialization, or empty-remote preflight');

$envFile = $tmp . '/compose.env';
file_put_contents($envFile, "WPRISM_PAIR=fixture\n");
$composeFile = $tmp . '/pair.yml';
file_put_contents($composeFile, "services: {}\n");
$postInitDocker = new DockerTransport('local', [
    'transport' => 'docker',
    'compose_file' => $composeFile,
    'service' => 'cli2',
    'repo_path' => '/wprism-repository/site',
]);
$postInitSteps = [];
$postInitCwd = getcwd();
chdir($workspace);
ob_start();
$postInitExit = OnboardCommand::run(
    $postInitDocker,
    ['--yes', '--configure-database', '--database-service=db'],
    dirname(__DIR__, 4),
    [
        'adopt' => static function () use (&$postInitSteps): int { $postInitSteps[] = 'adopt';
        return 0; },
        'assess' => static function () use (&$postInitSteps): int { $postInitSteps[] = 'assess';
        return 0; },
        'init' => static function () use (&$postInitSteps): int {
            $postInitSteps[] = 'init-confirmed';
            return InitCommand::BASELINE_COMMITTED_READINESS_PENDING_EXIT;
        },
    ]
);
$postInitOutput = (string) ob_get_clean();
if (is_string($postInitCwd)) {
    chdir($postInitCwd);
}
wprism_check_same(
    InitCommand::BASELINE_COMMITTED_READINESS_PENDING_EXIT,
    $postInitExit,
    'onboard preserves a nonzero safety result when only post-init readiness is pending'
);
wprism_check_same(
    ['adopt', 'assess', 'init-confirmed'],
    $postInitSteps,
    'post-init readiness is distinguished only after the baseline-confirming init step'
);
wprism_check(
    str_contains($postInitOutput, "env-set 'local' --name=<name> --stdin")
        && str_contains($postInitOutput, "status 'local'")
        && str_contains($postInitOutput, "onboard 'local' --handoff-only --git-url=<empty-remote-url>"),
    'committed-baseline recovery prints the exact env binding, status, and handoff-only public continuation'
);
wprism_check(
    str_contains($postInitOutput, 'Do not rerun init, adopt, or full onboard.')
        && !str_contains($postInitOutput, " init 'local'"),
    'committed-baseline recovery never sends the operator back through initialization'
);

$postInitMachineSteps = [];
$postInitMachineCwd = getcwd();
chdir($workspace);
ob_start();
$postInitMachineExit = OnboardCommand::run(
    $postInitDocker,
    [
        '--format=json', '--yes', '--configure-database', '--database-service=db',
        '--git-url=ssh://git.example.test/pending-readiness.git',
    ],
    dirname(__DIR__, 4),
    [
        'handoff_preflight' => static function () use (&$postInitMachineSteps): void {
            $postInitMachineSteps[] = 'handoff-preflight';
        },
        'adopt' => static function () use (&$postInitMachineSteps): int { $postInitMachineSteps[] = 'adopt';
        return 0; },
        'assess' => static function () use (&$postInitMachineSteps): int { $postInitMachineSteps[] = 'assess';
        return 0; },
        'init' => static function () use (&$postInitMachineSteps): int {
            $postInitMachineSteps[] = 'init-confirmed';
            return InitCommand::BASELINE_COMMITTED_READINESS_PENDING_EXIT;
        },
    ]
);
$postInitMachineOutput = (string) ob_get_clean();
if (is_string($postInitMachineCwd)) {
    chdir($postInitMachineCwd);
}
$postInitMachineRefusal = json_decode($postInitMachineOutput, true, 512, JSON_THROW_ON_ERROR);
wprism_check_same(1, $postInitMachineExit, 'machine onboarding preserves its structured refusal exit after committed init');
wprism_check_same(
    'onboarding_post_init_readiness_pending',
    $postInitMachineRefusal['reason_code'] ?? null,
    'machine onboarding distinguishes committed baseline from initialization failure'
);
wprism_check(
    str_contains((string) ($postInitMachineRefusal['remediation'] ?? ''), 'resolve every reported readiness blocker')
        && str_contains((string) ($postInitMachineRefusal['remediation'] ?? ''), "env-set 'local' --name=<name> --stdin")
        && str_contains((string) ($postInitMachineRefusal['remediation'] ?? ''), "status 'local'")
        && str_contains((string) ($postInitMachineRefusal['remediation'] ?? ''), "onboard 'local' --handoff-only --git-url=<same-remote-url>")
        && str_contains((string) ($postInitMachineRefusal['remediation'] ?? ''), 'do not rerun init or adopt')
        && !str_contains((string) ($postInitMachineRefusal['remediation'] ?? ''), 'then rerun init'),
    'machine committed-baseline remediation binds every blocker and the no-init handoff-only continuation'
);
wprism_check_same(
    ['handoff-preflight', 'adopt', 'assess', 'init-confirmed'],
    $postInitMachineSteps,
    'machine committed-baseline refusal stops before Git publication without reclassifying init as absent'
);

$postInitResumeSteps = ['env-set', 'status-clean'];
$postInitResumeCwd = getcwd();
chdir($workspace);
ob_start();
$postInitResumeExit = OnboardCommand::run(
    $postInitDocker,
    ['--handoff-only', '--git-url=ssh://git.example.test/after-env-set.git'],
    dirname(__DIR__, 4),
    [
        'adopt' => static function () use (&$postInitResumeSteps): int { $postInitResumeSteps[] = 'adopt';
        return 0; },
        'assess' => static function () use (&$postInitResumeSteps): int { $postInitResumeSteps[] = 'assess';
        return 0; },
        'init' => static function () use (&$postInitResumeSteps): int { $postInitResumeSteps[] = 'init';
        return 0; },
        'controller_preflight' => static function () use (&$postInitResumeSteps): array {
            $postInitResumeSteps[] = 'controller-preflight';
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        },
        'handoff' => static function () use (&$postInitResumeSteps): string {
            $postInitResumeSteps[] = 'handoff';
            return 'main';
        },
    ]
);
ob_end_clean();
if (is_string($postInitResumeCwd)) {
    chdir($postInitResumeCwd);
}
wprism_check_same(0, $postInitResumeExit, 'documented env-set and status sequence can continue through public handoff-only');
wprism_check_same(
    ['env-set', 'status-clean', 'controller-preflight', 'handoff'],
    $postInitResumeSteps,
    'handoff-only continuation does not repeat adoption, assessment, initialization, or database setup'
);
$dockerConfig = [];
$dockerCwd = getcwd();
chdir($tmp);
ob_start();
$dockerConnectExit = ConnectCommand::run([
    'demo-source', '--workspace=docker-workspace', '--transport=docker',
    '--compose-file=pair.yml', '--compose-env-file=compose.env',
    '--service=cli1', '--repo-path=/siterepo',
], dirname(__DIR__, 4), static function (string $name, array $config) use (&$dockerConfig): EnvironmentDriver {
    $dockerConfig = $config;
    return new IdealOnboardingTransport();
}, $gitRunner);
$dockerConnectOutput = (string) ob_get_clean();
if (is_string($dockerCwd)) {
    chdir($dockerCwd);
}
wprism_check_same(0, $dockerConnectExit, 'connect accepts readable relative Docker control-plane files');
wprism_check_same(realpath($composeFile), $dockerConfig['compose_file'] ?? null, 'connect anchors a relative Compose file before the workspace changes cwd');
wprism_check_same(realpath($envFile), $dockerConfig['compose_env_file'] ?? null, 'connect anchors a relative Compose environment before persistence');
wprism_check(str_contains($dockerConnectOutput, 'assess') && !str_contains($dockerConnectOutput, ' onboard '), 'Docker attachment does not recommend onboarding without bootstrap authority');
wprism_check(
    str_contains($dockerConnectOutput, 'WPrism issued no explicit mutation, but topology inspection bootstrapped WordPress and site startup code may have run.'),
    'legacy Docker attachment retains its inspection-only mutation disclosure'
);

$managedDockerRequest = ConnectCommand::parse([
    'managed-site', '--workspace=managed-docker-workspace', '--transport=docker',
    '--compose-file=pair.yml', '--compose-env-file=compose.env',
    '--wordpress-service=wordpress', '--profile=development', '--tooling=managed',
], $tmp);
wprism_check_same('managed', $managedDockerRequest['config']['tooling'] ?? null, 'managed Docker tooling is an explicit connect opt-in');
wprism_check_same(
    ['format' => DockerTransport::BOOTSTRAP_FORMAT],
    $managedDockerRequest['config']['bootstrap'] ?? null,
    'managed Docker connect writes the exact local bootstrap authority'
);
wprism_check(
    !isset($managedDockerRequest['config']['service'], $managedDockerRequest['config']['repo_path']),
    'managed Docker service and repository identities come only from inspected tooling preparation'
);
$managedDisclosure = new ReflectionMethod(ConnectCommand::class, 'renderMutationDisclosure');
ob_start();
$managedDisclosure->invoke(null, $managedDockerRequest['config']);
$managedDisclosureOutput = (string) ob_get_clean();
wprism_check(
    str_contains($managedDisclosureOutput, 'created or reused a WPrism-owned tooling image, private overlay, and durable repository volume')
        && str_contains($managedDisclosureOutput, 'ran disposable helper containers without editing or starting the Compose application')
        && !str_contains($managedDisclosureOutput, 'WPrism issued no explicit mutation'),
    'managed Docker connect discloses its owned resource mutations without claiming application mutation'
);
try {
    ConnectCommand::parse([
        'managed-site', '--workspace=managed-docker-refusal', '--transport=docker',
        '--compose-file=pair.yml', '--wordpress-service=wordpress', '--tooling=managed',
        '--service=caller-owned',
    ], $tmp);
    throw new RuntimeException('managed Docker connect accepted a caller-selected helper service');
} catch (RuntimeException $error) {
    wprism_check(
        str_contains($error->getMessage(), '--tooling=managed owns --service'),
        'managed Docker connect refuses caller-selected helper topology'
    );
}

$dockerHostRepo = $tmp . '/docker-host-repository';
mkdir($dockerHostRepo, 0700);
file_put_contents($dockerHostRepo . '/sentinel', "target-owned\n");
$dockerOverlapTransport = new IdealOnboardingTransport(true, '/siterepo', false, null, $dockerHostRepo);
$dockerOverlapWorkspace = $dockerHostRepo . '/controller';
ob_start();
$dockerOverlapExit = ConnectCommand::run([
    'demo-target', '--workspace=' . $dockerOverlapWorkspace, '--transport=docker',
    '--compose-file=' . $composeFile, '--compose-env-file=' . $envFile,
    '--service=cli2', '--repo-path=/siterepo',
], dirname(__DIR__, 4), static fn(string $name, array $config): EnvironmentDriver => $dockerOverlapTransport, $gitRunner);
ob_end_clean();
wprism_check_same(1, $dockerOverlapExit, 'connect refuses a workspace inside a Docker writable host repository');
wprism_check_same([], $dockerOverlapTransport->rawCalls, 'Docker host overlap refuses before any target probe');
wprism_check(is_file($dockerHostRepo . '/sentinel') && !file_exists($dockerOverlapWorkspace), 'Docker overlap preserves target bytes and publishes no workspace');

$fakeDockerBin = $tmp . '/fake-docker-bin';
mkdir($fakeDockerBin, 0700);
$fakeDockerLog = $tmp . '/fake-docker.log';
file_put_contents($fakeDockerBin . '/docker', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$WPRISM_FAKE_DOCKER_LOG"
for last do :; done
case " $* " in
  *" bash -c "*) exec /bin/bash -c "$last" ;;
  *" wp core is-installed "*) exit 0 ;;
  *" wp eval "*) printf 'single-site\n'; exit 0 ;;
esac
exit 9
SH
);
chmod($fakeDockerBin . '/docker', 0700);
$priorPath = getenv('PATH');
putenv('PATH=' . $fakeDockerBin . ':' . (is_string($priorPath) ? $priorPath : ''));
putenv('WPRISM_FAKE_DOCKER_LOG=' . $fakeDockerLog);
$boundedControl = new BoundedOnboardingTransport();
$sleepingConfig = $boundedControl->captureRawBounded('sleep 1', 50, 4096, 4096);
$noisyConfig = $boundedControl->captureRawBounded('printf %05000d 0', 1000, 4096, 4096);
$configFailures = [
    'sleeping' => $sleepingConfig,
    'noisy' => $noisyConfig,
    'nonzero' => ['exit' => 9, 'stdout' => '', 'stderr' => 'compose config refused'],
    'malformed' => ['exit' => 0, 'stdout' => '{', 'stderr' => ''],
    'unknown-service' => ['exit' => 0, 'stdout' => '{"services":{"other":{"volumes":[]}}}', 'stderr' => ''],
    'ambiguous-mount' => [
        'exit' => 0,
        'stdout' => '{"services":{"cli2":{"volumes":['
            . '{"type":"bind","source":"/fixture/a","target":"/siterepo","read_only":false},'
            . '{"type":"bind","source":"/fixture/b","target":"/siterepo","read_only":false}'
            . ']}}}',
        'stderr' => '',
    ],
];
foreach ($configFailures as $label => $controlResult) {
    @unlink($fakeDockerLog);
    $controlCalls = [];
    $failedWorkspace = $tmp . '/docker-config-' . $label;
    $controlCapture = static function (
        string $command,
        int $timeout,
        int $stdoutLimit,
        int $stderrLimit
    ) use (&$controlCalls, $controlResult): array {
        $controlCalls[] = compact('command', 'timeout', 'stdoutLimit', 'stderrLimit');
        return $controlResult;
    };
    ob_start();
    $configExit = ConnectCommand::run([
        'demo-target', '--workspace=' . $failedWorkspace, '--transport=docker',
        '--compose-file=' . $composeFile, '--compose-env-file=' . $envFile,
        '--service=cli2', '--repo-path=/siterepo',
    ], dirname(__DIR__, 4), static fn(string $name, array $config): EnvironmentDriver =>
        new DockerTransport($name, $config, null, $controlCapture), $gitRunner);
    ob_end_clean();
    wprism_check_same(1, $configExit, "connect fails closed on $label Docker config inspection");
    wprism_check(!file_exists($failedWorkspace), "$label Docker config inspection publishes no workspace");
    wprism_check_same(1, count($controlCalls), "$label Docker config inspection uses one bounded control-plane capture");
    wprism_check_same(30000, $controlCalls[0]['timeout'] ?? null, "$label Docker config inspection has a finite deadline");
    wprism_check(!file_exists($fakeDockerLog), "$label Docker config refusal occurs before otherwise-successful target probes");
}

$nestedBindRoot = $tmp . '/docker-parent-bind';
mkdir($nestedBindRoot . '/site', 0700, true);
file_put_contents($nestedBindRoot . '/site/sentinel', "target-owned\n");
$nestedMountConfig = json_encode([
    'services' => ['cli2' => ['volumes' => [[
        'type' => 'bind', 'source' => $nestedBindRoot,
        'target' => '/mounted', 'read_only' => false,
    ]]]],
], JSON_THROW_ON_ERROR);
$nestedControlCalls = [];
$nestedCapture = static function (
    string $command,
    int $timeout,
    int $stdoutLimit,
    int $stderrLimit
) use (&$nestedControlCalls, $nestedMountConfig): array {
    $nestedControlCalls[] = compact('command', 'timeout', 'stdoutLimit', 'stderrLimit');
    return ['exit' => 0, 'stdout' => $nestedMountConfig, 'stderr' => ''];
};
@unlink($fakeDockerLog);
ob_start();
$nestedOverlapExit = ConnectCommand::run([
    'nested-overlap', '--workspace=' . $nestedBindRoot . '/site/controller', '--transport=docker',
    '--compose-file=' . $composeFile, '--service=cli2', '--repo-path=/mounted/site',
], dirname(__DIR__, 4), static fn(string $name, array $config): EnvironmentDriver =>
    new DockerTransport($name, $config, null, $nestedCapture), $gitRunner);
ob_end_clean();
wprism_check_same(1, $nestedOverlapExit, 'connect refuses a workspace beneath a repository reached through a parent Docker bind');
wprism_check_same(1, count($nestedControlCalls), 'parent-bind overlap resolves through one bounded Compose inspection');
wprism_check(!is_file($fakeDockerLog), 'parent-bind overlap refuses before any target probe');
wprism_check(is_file($nestedBindRoot . '/site/sentinel'), 'parent-bind overlap preserves target repository bytes');

$nestedControlCalls = [];
$nestedDisjointWorkspace = $tmp . '/nested-bind-disjoint-workspace';
@unlink($fakeDockerLog);
ob_start();
$nestedDisjointExit = ConnectCommand::run([
    'nested-disjoint', '--workspace=' . $nestedDisjointWorkspace, '--transport=docker',
    '--compose-file=' . $composeFile, '--service=cli2', '--repo-path=/mounted/site',
], dirname(__DIR__, 4), static fn(string $name, array $config): EnvironmentDriver =>
    new DockerTransport($name, $config, null, $nestedCapture), $gitRunner);
ob_end_clean();
wprism_check_same(0, $nestedDisjointExit, 'connect accepts a disjoint workspace when repo_path is beneath a writable Docker bind');
wprism_check(is_file($nestedDisjointWorkspace . '/.wprism-envs.json'), 'disjoint parent-bind connect publishes its machine-local registry');
wprism_check(is_file($fakeDockerLog), 'disjoint parent-bind connect reaches the ordinary inspected target probes');
$legacyDockerLog = file_get_contents($fakeDockerLog);
wprism_check(
    is_string($legacyDockerLog) && !str_contains($legacyDockerLog, '--progress quiet'),
    'legacy Docker attachment command lines remain byte-compatible without managed progress flags'
);

$mountConfig = static fn(array $volumes): string => json_encode([
    'services' => ['cli2' => ['volumes' => $volumes]],
], JSON_THROW_ON_ERROR);
$namedDocker = new DockerTransport('named', [
    'transport' => 'docker', 'compose_file' => $composeFile, 'service' => 'cli2', 'repo_path' => '/siterepo',
], null, static fn(): array => ['exit' => 0, 'stdout' => $mountConfig([
    ['type' => 'volume', 'source' => 'repo-data', 'target' => '/siterepo', 'read_only' => false],
]), 'stderr' => '']);
$readOnlyDocker = new DockerTransport('read-only', [
    'transport' => 'docker', 'compose_file' => $composeFile, 'service' => 'cli2', 'repo_path' => '/siterepo',
], null, static fn(): array => ['exit' => 0, 'stdout' => $mountConfig([
    ['type' => 'bind', 'source' => '/fixture/read-only-repo', 'target' => '/siterepo', 'read_only' => true],
]), 'stderr' => '']);
$writableDocker = new DockerTransport('writable', [
    'transport' => 'docker', 'compose_file' => $composeFile, 'service' => 'cli2', 'repo_path' => '/siterepo',
], null, static fn(): array => ['exit' => 0, 'stdout' => $mountConfig([
    ['type' => 'bind', 'source' => '/fixture/writable-repo', 'target' => '/siterepo', 'read_only' => false],
]), 'stderr' => '']);
wprism_check_same(null, $namedDocker->hostRepoBoundaryPath(), 'a proven named volume has no writable host repository boundary');
wprism_check_same(null, $readOnlyDocker->hostRepoBoundaryPath(), 'a proven read-only bind has no writable host repository boundary');
wprism_check_same('/fixture/writable-repo', $writableDocker->hostRepoBoundaryPath(), 'a proven writable bind returns its exact host boundary');

$execControlCalls = [];
$execDocker = new DockerTransport('exec-bounded', [
    'transport' => 'docker', 'compose_file' => $composeFile, 'service' => 'cli2',
    'repo_path' => '/siterepo', 'mode' => 'exec',
], null, static function (string $command) use (&$execControlCalls, $boundedControl): array {
    $execControlCalls[] = $command;
    return $boundedControl->captureRawBounded('sleep 1', 50, 4096, 4096);
});
$execProbe = $execDocker->captureRawBounded('echo should-not-run', 1000, 4096, 4096);
wprism_check_same(1, $execProbe['exit'], 'exec mode converts a bounded service-probe timeout into its reviewed precondition refusal');
wprism_check(count($execControlCalls) === 1 && str_contains($execControlCalls[0], "'ps' '--status=running' '--services'"), 'exec mode bounds the Docker ps probe before command construction');
$fakeProbeDocker = new DockerTransport('fake-probe', [
    'transport' => 'docker', 'compose_file' => $composeFile, 'service' => 'cli2', 'repo_path' => '/siterepo',
]);
// The first process-group launch can absorb host scheduler pressure from the
// preceding corpus; five seconds still proves a finite bound without making
// this reachability-control fixture a one-second performance assertion.
$fakeReachable = $fakeProbeDocker->captureRawBounded('echo wprism-connect-ready', 5000, 4096, 4096);
$fakeInstalled = $fakeProbeDocker->captureWpBounded(['core', 'is-installed'], 5000, 4096, 4096);
$fakeTopology = $fakeProbeDocker->captureWpBounded(
    ['eval', 'echo is_multisite() ? "multisite" : "single-site";'],
    5000,
    4096,
    4096
);
wprism_check_same('wprism-connect-ready', trim($fakeReachable['stdout']), 'the Docker config fail-closed fixtures would otherwise pass raw reachability');
wprism_check_same(0, $fakeInstalled['exit'], 'the Docker config fail-closed fixtures would otherwise pass WordPress reachability');
wprism_check_same('single-site', trim($fakeTopology['stdout']), 'the Docker config fail-closed fixtures would otherwise pass topology inspection');
putenv('WPRISM_FAKE_DOCKER_LOG');
is_string($priorPath) ? putenv('PATH=' . $priorPath) : putenv('PATH');

$docker = new DockerTransport('demo-source', [
    'transport' => 'docker',
    'compose_file' => '/fixture/pair.yml',
    'compose_env_file' => $envFile,
    'service' => 'cli1',
    'repo_path' => '/siterepo',
]);
$wpCommand = new ReflectionMethod(DockerTransport::class, 'wpCommand');
$wire = (string) $wpCommand->invoke($docker, ['wprism', 'capture']);
wprism_check(str_contains($wire, "'compose' '--env-file' '" . $envFile . "' '-f' '/fixture/pair.yml'"), 'demo registry pins Compose interpolation through an explicit machine-local env file');
wprism_check(!$docker->capabilityReport('onboard')->ready(), 'Docker still refuses the adoption composition it cannot deliver');
wprism_check($docker->capabilityReport('onboard-handoff')->ready(), 'Docker permits a post-init Git-only handoff over raw control');
$dockerHandoffCli = HostProcess::run([
    dirname(__DIR__, 4) . '/cli/wprism',
    'onboard',
    'demo-source',
    '--handoff-only',
    '--git-url=' . $tmp . '/docker-handoff.git',
], $tmp . '/docker-workspace');
wprism_check_same(1, $dockerHandoffCli['exit'], 'Docker handoff-only reaches its target Git operation and reports the fixture failure');
wprism_check(!str_contains($dockerHandoffCli['stderr'], 'driver does not support'), 'Docker handoff-only is not rejected by adoption-only capabilities');

$demo = DemoCommand::options('start', ['--scenario=woocommerce', '--name=shopdemo', '--source-port=9100', '--target-port=9101']);
wprism_check_same('shopdemo', $demo['name'], 'demo accepts an isolated pair name');
wprism_check_same(9100, $demo['source_port'], 'demo accepts an explicit source port');
wprism_check_same(9101, $demo['target_port'], 'demo accepts an explicit target port');
wprism_check_same('woocommerce', $demo['scenario'], 'demo retains the explicit advanced WooCommerce scenario');
$coreDemo = DemoCommand::options('start', []);
wprism_check_same('core', $coreDemo['scenario'], 'demo defaults to the dependency-light WordPress core journey');
$reviewDemo = DemoCommand::options('review', ['--name=corewalk', '--accept-page-only']);
wprism_check_same(true, $reviewDemo['accept_page_only'], 'demo review requires and records the exact page-only confirmation');
foreach ([
    ['--name=corewalk'],
    ['--name=corewalk', '--accept-page-only', '--accept-page-only'],
] as $reviewArgs) {
    try {
        DemoCommand::options('review', $reviewArgs);
        wprism_check(false, 'demo review refuses missing or duplicate confirmation');
    } catch (RuntimeException $error) {
        wprism_check(
            str_contains($error->getMessage(), '--accept-page-only'),
            'demo review refusal names the one accepted operator confirmation'
        );
    }
}
try {
    DemoCommand::options('start', ['--scenario=unknown']);
    wprism_check(false, 'demo refuses an unknown scenario');
} catch (RuntimeException $error) {
    wprism_check(str_contains($error->getMessage(), "'core' or 'woocommerce'"), 'demo refusal names both executable scenarios');
}

$coreOptionProfileMethod = new ReflectionMethod(DemoCommand::class, 'coreOptionProfile');
$coreOptionProfile = $coreOptionProfileMethod->invoke(null);
$coreOptionClasses = array_count_values(array_map(
    static fn (array $rule): string => (string) ($rule['class'] ?? ''),
    $coreOptionProfile
));
ksort($coreOptionClasses, SORT_STRING);
wprism_check_same(
    ['authored' => 60, 'derived' => 4, 'env' => 6, 'runtime' => 9],
    $coreOptionClasses,
    'the reviewed WordPress 7.1 profile has the exact 79-name semantic split'
);
wprism_check_same(
    ['autoload' => 'preserve', 'class' => 'authored', 'lint_ok' => true],
    $coreOptionProfile['wp_attachment_pages_enabled'] ?? null,
    'attachment-page behavior is portable authored intent with preserved autoload, not a fictional derived fact'
);
wprism_check_same(
    ['autoload' => 'preserve', 'class' => 'authored', 'ref' => 'term'],
    $coreOptionProfile['default_email_category'] ?? null,
    'the portable default email category is an authored term reference, never a target-local numeric id'
);
wprism_check_same(
    ['class' => 'derived'],
    $coreOptionProfile['link_manager_enabled'] ?? null,
    'the links-table upgrade probe is recorded as a rebuilt database fact'
);
wprism_check_same(
    ['class' => 'env', 'required' => false],
    $coreOptionProfile['mailserver_pass'] ?? null,
    'mailserver_pass is optional environment input and can never enter authored capture'
);

$profileCaptureRepo = $tmp . '/demo-profile-capture';
mkdir($profileCaptureRepo . '/state/options', 0700, true);
$categoryUuid = '12345678-1234-4234-8234-123456789abc';
$authoredRecords = [];
foreach ($coreOptionProfile as $optionName => $rule) {
    if (($rule['class'] ?? null) === 'authored') {
        $authoredRecords[$optionName] = ['state' => 'present', 'value' => 'fixture'];
    }
}
$authoredRecords['default_email_category']['value'] = '{{term:' . $categoryUuid . '}}';
ksort($authoredRecords, SORT_STRING);
file_put_contents(
    $profileCaptureRepo . '/state/options/core.json',
    json_encode(['records' => $authoredRecords], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
);
mkdir($profileCaptureRepo . '/state/terms/category', 0700, true);
file_put_contents($profileCaptureRepo . '/state/terms/category/' . $categoryUuid . '--uncategorized.json', "{}\n");
$assertCoreProfileCapture = new ReflectionMethod(DemoCommand::class, 'assertCoreProfileCapture');
$assertCoreProfileCapture->invoke(null, ['source_repo' => $profileCaptureRepo]);
wprism_check(true, 'fresh core capture resolves default_email_category through a captured category entity');
$authoredRecords['default_email_category']['value'] = '1';
file_put_contents(
    $profileCaptureRepo . '/state/options/core.json',
    json_encode(['records' => $authoredRecords], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
);
try {
    $assertCoreProfileCapture->invoke(null, ['source_repo' => $profileCaptureRepo]);
    wprism_check(false, 'core capture refuses a raw default_email_category id');
} catch (RuntimeException $error) {
    wprism_check(
        str_contains($error->getMessage(), 'term-ref product path'),
        'default_email_category cannot weaken from a portable term ref to a raw target-local id'
    );
}

$inventoryRoot = $tmp . '/demo-core-inventory';
$inventoryBin = $inventoryRoot . '/bin';
$inventoryRepo = $inventoryRoot . '/source';
foreach ([$inventoryRoot, $inventoryBin, $inventoryRepo, $inventoryRoot . '/cli'] as $directory) {
    mkdir($directory, 0700);
}
$sidebarConstant = (new ReflectionClass(DemoCommand::class))->getReflectionConstant('CORE_SIDEBAR_OPTIONS');
if (!$sidebarConstant instanceof ReflectionClassConstant) {
    throw new RuntimeException('core sidebar inventory constant is unavailable');
}
$coreSidebarOptions = $sidebarConstant->getValue();
if (!is_array($coreSidebarOptions) || !array_is_list($coreSidebarOptions)) {
    throw new RuntimeException('core sidebar inventory constant is malformed');
}
$inventoryNames = array_values(array_unique(array_merge(array_keys($coreOptionProfile), $coreSidebarOptions)));
for ($index = count($inventoryNames); $index < 134; ++$index) {
    $inventoryNames[] = sprintf('mechanism_fixture_%03d', $index);
}
sort($inventoryNames, SORT_STRING);
$inventoryWitness = [
    'names' => $inventoryNames,
    'missing_exact' => [],
    'missing_sidebar' => [],
    'missing_dynamic' => [],
    'unseen' => [],
];
$inventoryFixture = $inventoryRoot . '/inventory.json';
$coverageFixture = $inventoryRoot . '/coverage.json';
$inventoryCommand = $inventoryRoot . '/docker-command.txt';
file_put_contents(
    $coverageFixture,
    json_encode([
        'format' => 'wprism-coverage-report/v1',
        'options' => [
            'total' => 134,
            'captured' => 94,
            'declared_excluded' => 40,
            'declared_excluded_by_class' => ['derived' => 14, 'env' => 10, 'runtime' => 16],
            'pending' => 0,
            'invisible_total' => 0,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
);
file_put_contents($inventoryRoot . '/cli/wprism', <<<'SH'
#!/bin/sh
exec /bin/cat "$WPRISM_DEMO_COVERAGE_FIXTURE"
SH
);
chmod($inventoryRoot . '/cli/wprism', 0700);
file_put_contents($inventoryBin . '/docker', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" > "$WPRISM_DEMO_INVENTORY_COMMAND"
exec /bin/cat "$WPRISM_DEMO_INVENTORY_FIXTURE"
SH
);
chmod($inventoryBin . '/docker', 0700);
file_put_contents($inventoryRoot . '/pair.yml', "services: {}\n");
file_put_contents($inventoryRoot . '/pair.env', "WPRISM_PAIR=inventory\n");
$priorInventoryPath = getenv('PATH');
putenv('PATH=' . $inventoryBin . PATH_SEPARATOR . (is_string($priorInventoryPath) ? $priorInventoryPath : ''));
putenv('WPRISM_DEMO_INVENTORY_FIXTURE=' . $inventoryFixture);
putenv('WPRISM_DEMO_INVENTORY_COMMAND=' . $inventoryCommand);
putenv('WPRISM_DEMO_COVERAGE_FIXTURE=' . $coverageFixture);
$inventorySession = [
    'compose_env_file' => $inventoryRoot . '/pair.env',
    'compose_file' => $inventoryRoot . '/pair.yml',
    'source_repo' => $inventoryRepo,
];
$assertCoreOptionInventory = new ReflectionMethod(DemoCommand::class, 'assertCoreOptionInventory');
file_put_contents(
    $inventoryFixture,
    json_encode($inventoryWitness, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
);
$assertCoreOptionInventory->invoke(null, $inventorySession, $inventoryRoot);
$inventoryProbe = (string) file_get_contents($inventoryCommand);
wprism_check(
    str_contains($inventoryProbe, 'Policy::load("/siterepo")')
        && str_contains($inventoryProbe, 'SidebarState::owns_option')
        && str_contains($inventoryProbe, 'option_rule')
        && str_contains($inventoryProbe, 'dynamic_option_rule_for_prefix'),
    'core inventory closure delegates to the real exact, pattern, dynamic, and SidebarState mechanisms'
);
$hostileInventory = $inventoryWitness;
$hostileInventory['unseen'] = ['hostile_unexpected'];
file_put_contents(
    $inventoryFixture,
    json_encode($hostileInventory, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
);
try {
    $assertCoreOptionInventory->invoke(null, $inventorySession, $inventoryRoot);
    wprism_check(false, 'core inventory refuses an unexpected live option');
} catch (RuntimeException $error) {
    wprism_check(
        str_contains($error->getMessage(), 'unseen=hostile_unexpected'),
        'an unexpected live option refuses before the demo can claim exact WordPress 7.1 coverage'
    );
}
$missingInventory = $inventoryWitness;
$missingInventory['missing_exact'] = ['blogname'];
file_put_contents(
    $inventoryFixture,
    json_encode($missingInventory, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
);
try {
    $assertCoreOptionInventory->invoke(null, $inventorySession, $inventoryRoot);
    wprism_check(false, 'core inventory refuses a missing expected mechanism row');
} catch (RuntimeException $error) {
    wprism_check(
        str_contains($error->getMessage(), 'missing_exact=blogname'),
        'a missing manifest/profile exact row refuses even when the aggregate total is unchanged'
    );
}
putenv('WPRISM_DEMO_INVENTORY_FIXTURE');
putenv('WPRISM_DEMO_INVENTORY_COMMAND');
putenv('WPRISM_DEMO_COVERAGE_FIXTURE');
is_string($priorInventoryPath) ? putenv('PATH=' . $priorInventoryPath) : putenv('PATH');

$capabilityRoot = $tmp . '/demo-capability-qualification';
$capabilityRepo = $capabilityRoot . '/source';
mkdir($capabilityRoot . '/cli', 0700, true);
mkdir($capabilityRepo, 0700);
$blockedCapabilities = json_encode([
    'ready' => false,
    'blockers' => [[
        'capability' => 'environment.containment.verify',
        'message' => 'fixture containment proof is unavailable',
    ]],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents(
    $capabilityRoot . '/cli/wprism',
    "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg($blockedCapabilities) . "\nexit 1\n"
);
chmod($capabilityRoot . '/cli/wprism', 0700);
$assertCapabilityQualification = new ReflectionMethod(DemoCommand::class, 'assertCapabilityQualification');
try {
    $assertCapabilityQualification->invoke(
        null,
        [],
        $capabilityRoot,
        'demo-source',
        $capabilityRepo
    );
    wprism_check(false, 'demo capability qualification refuses a complete non-ready report');
} catch (RuntimeException $error) {
    wprism_check(
        str_contains($error->getMessage(), 'environment.containment.verify')
            && str_contains($error->getMessage(), 'fixture containment proof is unavailable'),
        'nonzero capability output retains its exact blocker instead of collapsing to a generic command failure'
    );
}
$readyCapabilities = json_encode([
    'ready' => true,
    'blockers' => [],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents(
    $capabilityRoot . '/cli/wprism',
    "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg($readyCapabilities) . "\nexit 0\n"
);
$assertCapabilityQualification->invoke(null, [], $capabilityRoot, 'demo-source', $capabilityRepo);
wprism_check(true, 'demo capability qualification accepts only a ready report');

$assessmentRoot = $tmp . '/demo-assessment';
$assessmentRepo = $assessmentRoot . '/source';
mkdir($assessmentRoot . '/cli', 0700, true);
mkdir($assessmentRepo, 0700);
$assessmentView = [
    'format' => 'wprism-assess-view/v1',
    'summary' => [
        'readiness' => 'blocked',
        'counts' => [
            'invisible_option_names' => 98,
            'pending_classifications' => 0,
            'undeclared_tables' => 0,
        ],
        'dispositions' => ['agree' => true],
    ],
    'page' => ['shown' => 1],
    'rows' => [['kind' => 'surface', 'surface' => ['id' => 'post_type:page']]],
];
$assessmentBytes = json_encode($assessmentView, JSON_UNESCAPED_SLASHES);
if (!is_string($assessmentBytes)) {
    throw new RuntimeException('could not encode demo assessment fixture');
}
file_put_contents(
    $assessmentRoot . '/cli/wprism',
    "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg($assessmentBytes) . "\nexit 3\n"
);
chmod($assessmentRoot . '/cli/wprism', 0700);
$assertCoreAssessment = new ReflectionMethod(DemoCommand::class, 'assertCoreAssessment');
try {
    $assertCoreAssessment->invoke(null, ['source_repo' => $assessmentRepo], $assessmentRoot);
    wprism_check(false, 'demo refuses a complete-looking assessment that still exits 3');
} catch (RuntimeException $error) {
    wprism_check(
        str_contains($error->getMessage(), 'complete bounded release assessment'),
        'exit 3 and its invisible option gap stop demo setup instead of being relabeled ready'
    );
}
$assessmentView['summary']['readiness'] = 'ready';
$assessmentView['summary']['counts']['invisible_option_names'] = 0;
$assessmentBytes = json_encode($assessmentView, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents(
    $assessmentRoot . '/cli/wprism',
    "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg($assessmentBytes) . "\nexit 0\n"
);
$assessmentSummary = $assertCoreAssessment->invoke(null, ['source_repo' => $assessmentRepo], $assessmentRoot);
wprism_check_same('ready', $assessmentSummary['readiness'] ?? null, 'demo accepts only the exit-0 ready assessment');
wprism_check_same(
    0,
    $assessmentSummary['counts']['invisible_option_names'] ?? null,
    'the accepted demo assessment carries no invisible option gap'
);

$reviewRoot = $tmp . '/demo-contract-review';
foreach ([
    $reviewRoot,
    $reviewRoot . '/cli',
    $reviewRoot . '/sandbox',
    $reviewRoot . '/sandbox/tmp',
    $reviewRoot . '/sandbox/siterepo',
] as $directory) {
    mkdir($directory, 0700);
}
$reviewSession = ideal_demo_session($reviewRoot, 'reviewdemo', 9260, 9261);
file_put_contents((string) $reviewSession['compose_file'], "services: {}\n");
file_put_contents((string) $reviewSession['compose_env_file'], "WPRISM_PAIR=reviewdemo\n");
foreach ([
    ['git', 'init', '--bare', '--initial-branch=main', $reviewSession['origin']],
    ['git', 'init', '--initial-branch=main', $reviewSession['source_repo']],
] as $command) {
    $result = IdealOnboardingTransport::process($command);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('could not initialize demo review fixture: ' . trim($result['stderr']));
    }
}
file_put_contents($reviewSession['source_repo'] . '/.gitignore', Adopt::repositoryGitignoreBytes());
file_put_contents($reviewSession['source_repo'] . '/site.wprism.json', Adopt::repositorySeedBytes());
foreach ([
    ['git', '-C', $reviewSession['source_repo'], 'add', '.gitignore', 'site.wprism.json'],
    ['git', '-C', $reviewSession['source_repo'], '-c', 'user.name=test', '-c', 'user.email=test@example.test',
        'commit', '-m', 'review baseline'],
    ['git', '-C', $reviewSession['source_repo'], 'remote', 'add', 'origin', $reviewSession['origin']],
    ['git', '-C', $reviewSession['source_repo'], 'push', '-u', 'origin', 'main'],
    ['git', 'clone', '--branch', 'main', $reviewSession['origin'], $reviewSession['target_repo']],
] as $command) {
    $result = IdealOnboardingTransport::process($command);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('could not establish demo review fixture: ' . trim($result['stderr']));
    }
}
$reviewContract = json_decode(
    (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/release/contract-undeclared-unbound.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$reviewContract['declarations']['surfaces'] = [
    [
        'id' => 'post_type:page',
        'label' => 'Pages',
        'state_class' => 'authored',
        'handling' => 'manage',
        'operations' => ['capture', 'merge', 'release', 'verify'],
        'identity' => 'post uuid',
        'decided_by' => 'operator',
        'decided_at' => '2026-08-31T00:00:00Z',
    ],
    // Deliberately hostile extra authority: review must remove this entire
    // release/delete surface before the `--accept-page-only` claim can hold.
    [
        'id' => 'post_type:post',
        'label' => 'Posts',
        'state_class' => 'authored',
        'handling' => 'manage',
        'operations' => ['release', 'delete'],
        'identity' => 'post uuid',
        'decided_by' => 'operator',
        'decided_at' => '2026-08-31T00:00:00Z',
    ],
];
$reviewContract['declarations']['external_effects'] = [[
    'containment' => 'live',
    'decided_by' => ApplicationContract::UNREVIEWED_DECIDED_BY,
    'effect_recovery_semantics' => 'provider-state restorable',
    'id' => ContractProposal::LIFECYCLE_EFFECT_ID,
    'reason' => ContractProposal::UNREVIEWED_REASON,
    'restored_by' => 'code release',
    'surfaces' => ['plugins/themes'],
]];
$reviewContract['declarations']['journeys'] = [];
$reviewContract['declarations']['unsupported'] = [];
$reviewContract['declarations']['surface_labels'] = [
    'post_type:page' => 'Pages',
    'post_type:post' => 'Posts',
];
$reviewContract = ApplicationContract::withDigest($reviewContract);
ApplicationContract::validate($reviewContract, false);
$reviewProposal = [
    'format' => ContractProposal::FORMAT,
    'generated_at' => '2026-08-31T00:00:00Z',
    'environment' => 'demo-target',
    'assess_digest' => 'sha256:' . str_repeat('a', 64),
    'contract' => $reviewContract,
    'review_required' => [
        'review and decide external effect ' . ContractProposal::LIFECYCLE_EFFECT_ID,
    ],
    'review_required_count' => 1,
];
(new ContractStore((string) $reviewSession['source_repo']))->writeProposal('demo-target', $reviewProposal);

$reviewStub = <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);
$root = getenv('WPRISM_DEMO_TEST_ROOT');
if (!is_string($root) || $root === '') { exit(90); }
require_once $root . '/cli/src/Contract/ApplicationContract.php';
require_once $root . '/cli/src/Contract/ContractStore.php';
$run = static function (array $argv): int {
    $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { return 127; }
    fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return proc_close($process);
};
if (($argv[1] ?? '') === 'deploy') { exit(0); }
if (($argv[1] ?? '') !== 'contract' || ($argv[2] ?? '') !== 'demo-target') { exit(91); }
$store = new \WPrism\Orchestrator\ContractStore(getcwd() ?: '.');
$subcommand = $argv[3] ?? '';
if ($subcommand === 'propose') {
    echo \WPrism\Canon::encode($store->readProposal('demo-target') ?? []);
    exit(0);
}
if ($subcommand === 'accept') {
    $proposal = $store->readProposal('demo-target') ?? [];
    if (($proposal['contract']['declarations']['external_effects'] ?? null) !== []) {
        echo json_encode([
            'format' => 'wprism-command-refusal/v1',
            'reason_code' => 'external_effect_unreviewed',
        ], JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }
    $contract = \WPrism\Orchestrator\ApplicationContract::withDigest((array) ($proposal['contract'] ?? []));
    \WPrism\Orchestrator\ApplicationContract::validate($contract);
    $store->writeContract($contract, null);
    $store->writeProjection(['format' => 'review-fixture-projection/v1', 'surfaces' => []]);
    $paths = [
        '.wprism/contract/contract.json',
        '.wprism/contract/projection.json',
    ];
    if ($run(array_merge(['git', '-C', getcwd() ?: '.', 'add', '-f', '--'], $paths)) !== 0) { exit(92); }
    echo \WPrism\Canon::encode([
        'format' => 'wprism-contract-accept/v1',
        'environment' => 'demo-target',
        'contract_digest' => $contract['contract_digest'],
        'staged' => true,
    ]);
    exit(0);
}
if ($subcommand === 'show') {
    echo \WPrism\Canon::encode(['contract' => $store->readContract(), 'projection' => $store->readProjection()]);
    exit(0);
}
exit(93);
PHP;
file_put_contents($reviewRoot . '/cli/wprism', $reviewStub);
chmod($reviewRoot . '/cli/wprism', 0700);
putenv('WPRISM_DEMO_TEST_ROOT=' . dirname(__DIR__, 4));

$prepareCoreContractReview = new ReflectionMethod(DemoCommand::class, 'prepareCoreContractReview');
$proposalBeforeUnreadAccept = (string) file_get_contents(
    $reviewSession['source_repo'] . '/.wprism/contract/demo-target/proposed.json'
);
$prepareCoreContractReview->invoke(null, $reviewSession, $reviewRoot);
wprism_check_same(
    $proposalBeforeUnreadAccept,
    (string) file_get_contents($reviewSession['source_repo'] . '/.wprism/contract/demo-target/proposed.json'),
    'real demo orchestration proves unread contract accept refuses without modifying its proposal'
);
wprism_check(
    !is_file($reviewSession['source_repo'] . '/.wprism/contract/contract.json')
        && !is_file($reviewSession['source_repo'] . '/.wprism/contract/projection.json'),
    'unread acceptance publishes no contract authority or projection artifact'
);

$reviewSession['phase'] = 'review_required';
$reviewSession['last_applied_revision'] = trim(IdealOnboardingTransport::process([
    'git', '-C', $reviewSession['source_repo'], 'rev-parse', 'HEAD',
])['stdout']);
$reviewSession = own_ideal_demo_paths(
    $reviewSession,
    ['source_repo', 'target_repo', 'origin', 'compose_env_file']
);
write_ideal_demo_session($reviewSession);
$reviewJournalBefore = (string) file_get_contents((string) $reviewSession['state_file']);
ob_start();
$unconfirmedReview = DemoCommand::run(['review', '--name=reviewdemo'], $reviewRoot);
ob_end_clean();
wprism_check_same(1, $unconfirmedReview, 'demo review without the exact confirmation refuses');
wprism_check_same(
    $reviewJournalBefore,
    (string) file_get_contents((string) $reviewSession['state_file']),
    'missing review confirmation mutates neither the session phase nor proposal authority'
);

$untrackedReviewPath = $reviewSession['source_repo'] . '/hostile-untracked.php';
file_put_contents($untrackedReviewPath, "<?php throw new RuntimeException('must not enter review');\n");
$proposalBeforeUntrackedReview = (string) file_get_contents(
    $reviewSession['source_repo'] . '/.wprism/contract/demo-target/proposed.json'
);
$reviewMethod = new ReflectionMethod(DemoCommand::class, 'review');
try {
    $reviewMethod->invoke(null, $reviewRoot, 'reviewdemo');
    wprism_check(false, 'page-only review refuses an untracked source file');
} catch (RuntimeException $error) {
    wprism_check(
        str_contains($error->getMessage(), 'including untracked files'),
        'page-only review names its all-repository clean-worktree boundary'
    );
}
wprism_check_same(
    $proposalBeforeUntrackedReview,
    (string) file_get_contents($reviewSession['source_repo'] . '/.wprism/contract/demo-target/proposed.json'),
    'untracked source bytes refuse before the generated proposal is narrowed or accepted'
);
wprism_check(
    !is_file($reviewSession['source_repo'] . '/.wprism/contract/contract.json')
        && !is_file($reviewSession['source_repo'] . '/.wprism/contract/projection.json')
        && (string) file_get_contents((string) $reviewSession['state_file']) === $reviewJournalBefore,
    'untracked source refusal publishes no authority and leaves the session journal unchanged'
);
unlink($untrackedReviewPath);

ob_start();
$confirmedReview = DemoCommand::run(
    ['review', '--name=reviewdemo', '--accept-page-only'],
    $reviewRoot
);
$confirmedReviewOutput = (string) ob_get_clean();
wprism_check_same(0, $confirmedReview, 'explicit page-only operator review accepts through the product orchestration');
$acceptedReviewContract = (new ContractStore((string) $reviewSession['source_repo']))->readContract();
wprism_check_same(
    ['post_type:page'],
    array_column((array) ($acceptedReviewContract['declarations']['surfaces'] ?? []), 'id'),
    'the accepted contract removes the adversarial second release/delete surface'
);
wprism_check_same(
    ['capture', 'merge', 'release', 'verify'],
    $acceptedReviewContract['declarations']['surfaces'][0]['operations'] ?? null,
    'page-only review grants exactly the non-delete operations named by its confirmation'
);
wprism_check_same(
    [],
    $acceptedReviewContract['declarations']['external_effects'] ?? null,
    'state-only review removes the optional code-lifecycle effect instead of auto-marking it operator reviewed'
);
$reviewCommitPaths = preg_split('/\R/', trim(IdealOnboardingTransport::process([
    'git', '-C', $reviewSession['source_repo'], 'show', '--pretty=format:', '--name-only', 'HEAD',
])['stdout']));
$reviewCommitPaths = is_array($reviewCommitPaths) ? array_values(array_filter($reviewCommitPaths, 'strlen')) : [];
sort($reviewCommitPaths, SORT_STRING);
wprism_check_same(
    ['.wprism/contract/contract.json', '.wprism/contract/projection.json'],
    $reviewCommitPaths,
    'explicit review commits only the exact contract and projection artifacts in the source repository'
);
$reviewedSession = json_decode((string) file_get_contents((string) $reviewSession['state_file']), true);
wprism_check_same('ready', $reviewedSession['phase'] ?? null, 'review publishes ready only after source push and target fast-forward');
wprism_check(
    str_contains($confirmedReviewOutput, 'accepted and committed only contract.json + projection.json'),
    'review output names the exact authority artifacts it committed'
);
putenv('WPRISM_DEMO_TEST_ROOT');

$wooFixtureRoot = $tmp . '/demo-woo-activation';
$wooFixtureSandbox = $wooFixtureRoot . '/sandbox';
$wooFixtureBin = $wooFixtureRoot . '/bin';
foreach ([$wooFixtureRoot, $wooFixtureSandbox, $wooFixtureSandbox . '/bin', $wooFixtureBin] as $directory) {
    if (!is_dir($directory)) {
        mkdir($directory, 0700);
    }
}
file_put_contents($wooFixtureSandbox . '/pair.yml', "services: {}\n");
file_put_contents($wooFixtureSandbox . '/bin/fetch-artifact.sh', <<<'SH'
fetch_artifact() {
  printf '/fixture/woocommerce-fixture.zip\n'
}
SH
);
$wooDocker = <<<'SH'
#!/usr/bin/env bash
set -eu
line=" $* "
printf '%s\n' "$line" >> "$WPRISM_DEMO_DOCKER_LOG"
case "$line" in
  *" cli2 wp plugin activate woocommerce "*)
    : > "$WPRISM_DEMO_WOO_ACTIVE"
    ;;
  *" cli2 wp eval WC_Install::maybe_enable_hpos(); "*)
    if [ ! -f "$WPRISM_DEMO_WOO_ACTIVE" ]; then
      printf 'WooCommerce inactive on cli2\n' >&2
      exit 42
    fi
    ;;
esac
exit 0
SH;
$wooDockerPath = $wooFixtureBin . '/docker';
file_put_contents($wooDockerPath, $wooDocker);
chmod($wooDockerPath, 0700);
$wooComposeEnv = $wooFixtureRoot . '/pair.env';
file_put_contents($wooComposeEnv, "WPRISM_PAIR=woo-activation-fixture\n");
$wooLog = $wooFixtureRoot . '/docker.log';
$wooActive = $wooFixtureRoot . '/cli2-woocommerce-active';
$wooSession = [
    'compose_env_file' => $wooComposeEnv,
    'compose_file' => $wooFixtureSandbox . '/pair.yml',
];
$installWoo = new ReflectionMethod(DemoCommand::class, 'installWooCommerce');
$establishHpos = new ReflectionMethod(DemoCommand::class, 'establishHpos');
$configureWooQualification = new ReflectionMethod(DemoCommand::class, 'configureWooQualification');
$wooPriorPath = getenv('PATH');
putenv('PATH=' . $wooFixtureBin . ':' . (is_string($wooPriorPath) ? $wooPriorPath : ''));
putenv('WPRISM_DEMO_DOCKER_LOG=' . $wooLog);
putenv('WPRISM_DEMO_WOO_ACTIVE=' . $wooActive);
$wooSetupError = null;
try {
    $installWoo->invoke(null, $wooSession, $wooFixtureRoot);
    $establishHpos->invoke(null, $wooSession, 2);
    $configureWooQualification->invoke(null, $wooSession, 2);
} catch (Throwable $error) {
    $wooSetupError = $error;
} finally {
    is_string($wooPriorPath) ? putenv('PATH=' . $wooPriorPath) : putenv('PATH');
    putenv('WPRISM_DEMO_DOCKER_LOG');
    putenv('WPRISM_DEMO_WOO_ACTIVE');
}
$wooCalls = is_file($wooLog) ? (string) file_get_contents($wooLog) : '';
$targetActivation = strpos($wooCalls, ' cli2 wp plugin activate woocommerce ');
$targetHpos = strpos($wooCalls, ' cli2 wp eval WC_Install::maybe_enable_hpos(); ');
$sourceStockConstant = strpos($wooCalls, ' cli1 wp config set WOOCOMMERCE_BIS_ALPHA_ENABLED true ');
$sourceStockTables = strpos($wooCalls, ' cli1 wp eval WC_Install::create_tables(); ');
$targetStockConstant = strpos($wooCalls, ' cli2 wp config set WOOCOMMERCE_BIS_ALPHA_ENABLED true ');
$targetStockTables = strpos($wooCalls, ' cli2 wp eval WC_Install::create_tables(); ');
wprism_check_same(null, $wooSetupError, 'demo activates target WooCommerce before target HPOS setup');
wprism_check(
    $targetActivation !== false && $targetHpos !== false && $targetActivation < $targetHpos,
    'the real demo installer orders cli2 activation before its WC_Install HPOS call'
);
wprism_check(
    $sourceStockConstant !== false
        && $sourceStockTables !== false
        && $sourceStockConstant < $sourceStockTables
        && $targetStockConstant !== false
        && $targetStockTables !== false
        && $targetStockConstant < $targetStockTables,
    'the demo creates Woo stock-notification tables only after the feature constant is active on both sides'
);

$largeProcess = HostProcess::run([
    PHP_BINARY,
    '-r',
    'fwrite(STDERR, str_repeat("e", 200000)); fwrite(STDOUT, "ok");',
]);
wprism_check_same(0, $largeProcess['exit'], 'the shared host process runner completes with a full stderr pipe');
wprism_check_same('ok', $largeProcess['stdout'], 'the shared runner preserves stdout while draining stderr concurrently');
wprism_check_same(200000, strlen($largeProcess['stderr']), 'the shared runner drains the entire adversarial stderr payload');
$oversizedProcess = HostProcess::run(
    [PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("x", 200000));'],
    null,
    [],
    false,
    5000,
    65536
);
wprism_check_same(125, $oversizedProcess['exit'], 'the shared runner terminates output beyond its explicit capture budget');
wprism_check_same('', $oversizedProcess['stdout'], 'oversized child output is not returned to the command boundary');
$timedProcess = HostProcess::run([PHP_BINARY, '-r', 'sleep(5);'], null, [], false, 100, 65536);
wprism_check_same(124, $timedProcess['exit'], 'the shared runner terminates a child that exceeds its deadline');
wprism_check(str_contains($timedProcess['stderr'], 'timed out'), 'the shared runner reports its own bounded timeout diagnostic');
$closedPipeProcess = HostProcess::run(
    ['sh', '-c', 'exec >/dev/null 2>&1; sleep 2'],
    null,
    [],
    false,
    100,
    65536
);
wprism_check_same(124, $closedPipeProcess['exit'], 'the shared runner enforces its deadline after a child closes both capture pipes');
$passthroughTimedProcess = HostProcess::run(
    [PHP_BINARY, '-r', 'usleep(500000);'],
    null,
    [],
    true,
    100,
    65536
);
wprism_check_same(124, $passthroughTimedProcess['exit'], 'the shared runner enforces an explicit passthrough deadline');
// This positive case proves the explicit output budget, not host throughput.
// The parallel corpus exhausted its former 1s allowance (exit 124), while the
// isolated case passed; separate 50/100ms cases above pin deadline enforcement.
$transferBudgetProcess = HostProcess::run(
    [PHP_BINARY, '-r', 'usleep(50000); fwrite(STDOUT, str_repeat("t", 2000000));'],
    null,
    [],
    false,
    10000,
    4000000
);
wprism_check_same(0, $transferBudgetProcess['exit'], 'an explicit transfer budget admits a slower multi-megabyte operation');
wprism_check_same(2000000, strlen($transferBudgetProcess['stdout']), 'the explicit transfer budget preserves the complete bounded payload');

$boundedTransport = new BoundedOnboardingTransport();
$closedPipeTarget = $boundedTransport->captureRawBounded(
    escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('fclose(STDOUT); fclose(STDERR); usleep(500000);'),
    50,
    4096,
    4096
);
wprism_check_same(124, $closedPipeTarget['exit'], 'bounded target capture terminates a child after both output pipes close');
wprism_check_same('transport command timed out', $closedPipeTarget['stderr'], 'bounded target timeout has one stable diagnostic');
$noisyTarget = $boundedTransport->captureRawBounded(
    escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('fwrite(STDOUT, str_repeat("x", 20000));'),
    1000,
    4096,
    4096
);
wprism_check_same(125, $noisyTarget['exit'], 'bounded target capture terminates stdout beyond its reviewed budget');
wprism_check_same('', $noisyTarget['stdout'], 'over-limit target output is not returned to the onboarding boundary');
// The old timer failed under a paused controller with its marker ALREADY
// present at return. Readiness precedes the flood; only this controller's
// post-return release permits a mutation, and a PID witness closes the case
// where a leaked child simply has not been scheduled during a fixed sleep.
$childIsLive = static function (int $pid): bool {
    if (!posix_kill($pid, 0)) return false;
    // Linux may retain an exited zombie until init reaps it. Observe process
    // state independently of ProcessGroup's own cleanup verdict.
    $observed = HostProcess::run(['ps', '-o', 'stat=', '-p', (string) $pid]);
    $state = trim($observed['stdout']);
    if ($observed['exit'] === 1 && $state === '' && $observed['stderr'] === '') return false;
    if ($observed['exit'] !== 0 || $observed['stderr'] !== '' || preg_match('/^[A-Za-z+<>]+$/D', $state) !== 1) {
        throw new RuntimeException('cannot observe the cancellation descendant process state');
    }
    return !str_starts_with($state, 'Z');
};
$descendantEvidence = static function (bool $positive, bool $flood, callable $capture) use ($tmp, $childIsLive): array {
    $directory = $tmp . '/output-descendant-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $nonce = bin2hex(random_bytes(16));
    if ($positive) file_put_contents($directory . '/release', $nonce);
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/cancellation_waiter.php')
        . ' ' . escapeshellarg($directory) . ' ' . escapeshellarg($nonce)
        . ' & child=$!; while [ ! -f ' . escapeshellarg($directory . '/ready') . ' ]; do sleep 0.01; done; '
        . ($flood ? 'printf %020000d 0; ' : '') . 'wait "$child"';
    $witness = null;
    try {
        $result = $capture($command);
        $witness = json_decode(file_get_contents($directory . '/ready'), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($witness) || ($witness['nonce'] ?? null) !== $nonce
            || !is_int($witness['pid'] ?? null) || $witness['pid'] < 2) {
            throw new RuntimeException('cancellation child did not publish its exact readiness witness');
        }
        clearstatcache(true, $directory . '/marker');
        $beforeRelease = file_exists($directory . '/marker');
    } finally {
        // Even the parent-only cancellation mutant gets a release and exits;
        // never signal a numeric PID that may since have been reused.
        file_put_contents($directory . '/release', $nonce);
        if (is_array($witness) && is_int($witness['pid'] ?? null) && $witness['pid'] > 1) {
            $deadline = hrtime(true) + 3000000000;
            while ($childIsLive($witness['pid']) && hrtime(true) < $deadline) usleep(10000);
        }
    }
    // A real late-exit mutant passed when marker was read before liveness:
    // it wrote between those reads. Live fails conservatively; exited means
    // the subsequent marker observation cannot miss a later child write.
    $childGone = !$childIsLive($witness['pid']);
    clearstatcache(true, $directory . '/marker');
    return ['result' => $result, 'before_release' => $beforeRelease,
        'marker' => is_file($directory . '/marker') ? file_get_contents($directory . '/marker') : null,
        'nonce' => $nonce, 'child_gone' => $childGone];
};
$captureOutput = static fn(string $command): array => $boundedTransport->captureRawBounded($command, 10000, 4096, 4096);
$positiveDescendant = $descendantEvidence(true, false, $captureOutput);
wprism_check_same(['exit' => 0, 'stdout' => '', 'stderr' => ''], $positiveDescendant['result'],
    'the released descendant positive control completes normally');
wprism_check_same($positiveDescendant['nonce'], $positiveDescendant['marker'],
    'the positive control proves the ready descendant can perform its mutation');
wprism_check($positiveDescendant['child_gone'], 'the positive control leaves no live descendant');
$targetOutputDescendant = $descendantEvidence(false, true, $captureOutput);
wprism_check_same(['exit' => 125, 'stdout' => '', 'stderr' => 'transport command output exceeded capture limit'],
    $targetOutputDescendant['result'], 'bounded target capture cancels the owned process group on output refusal');
wprism_check(!$targetOutputDescendant['before_release'], 'the descendant cannot mutate before the post-return release');
wprism_check_same(null, $targetOutputDescendant['marker'], 'a target descendant cannot mutate after output refusal returns');
wprism_check($targetOutputDescendant['child_gone'], 'output refusal leaves no live descendant waiting to mutate later');
// These cases prove descendant cancellation after readiness. The separate
// 50/100ms closed-pipe checks above own short deadline enforcement.
$hostDescendant = $descendantEvidence(false, false, static fn(string $command): array => HostProcess::run(
    ['sh', '-c', $command], null, [], false, 5000, 4096
));
wprism_check_same(124, $hostDescendant['result']['exit'], 'the shared runner times out the owned process group');
wprism_check(!$hostDescendant['before_release'], 'the host descendant waits for a post-timeout release');
wprism_check_same(null, $hostDescendant['marker'], 'a host descendant cannot mutate after the timeout returns');
wprism_check($hostDescendant['child_gone'], 'host timeout leaves no live descendant waiting to mutate later');
$targetDescendant = $descendantEvidence(false, false, static fn(string $command): array =>
    $boundedTransport->captureRawBounded($command, 5000, 4096, 4096));
wprism_check_same(124, $targetDescendant['result']['exit'], 'bounded target capture times out the owned process group');
wprism_check(!$targetDescendant['before_release'], 'the target descendant waits for a post-timeout release');
wprism_check_same(null, $targetDescendant['marker'], 'a target descendant cannot mutate after the timeout returns');
wprism_check($targetDescendant['child_gone'], 'target timeout leaves no live descendant waiting to mutate later');

$faultRoot = $tmp . '/fault-demo-root';
foreach ([$faultRoot, $faultRoot . '/bin', $faultRoot . '/sandbox', $faultRoot . '/sandbox/bin', $faultRoot . '/sandbox/tmp'] as $directory) {
    mkdir($directory, 0700);
}
$wordpressDemoImage = 'wordpress@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf';
file_put_contents($faultRoot . '/bin/docker', <<<'SH'
#!/usr/bin/env bash
set -eu
case " $* " in
  *" run --rm --entrypoint php wordpress@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf -r "*)
    printf '7.1'
    exit 0
    ;;
esac
exit 19
SH
);
chmod($faultRoot . '/bin/docker', 0700);
$faultOriginalPath = getenv('PATH');
putenv('PATH=' . $faultRoot . '/bin:' . (is_string($faultOriginalPath) ? $faultOriginalPath : ''));
$faultPairScript = "#!/usr/bin/env bash\nset -eu\nif [ \"\$1\" = up ]; then"
    . "\ncase \" \$* \" in *\" --git-cli \"*) ;; *) exit 11 ;; esac\n"
    . "[ \"\${WPRISM_CLI_IMAGE:-}\" = \"wprism-demo-cli-git:php8.3\" ] || exit 12\n"
    . '[ "${WPRISM_WP_IMAGE:-}" = ' . escapeshellarg($wordpressDemoImage) . " ] || exit 13\nmkdir -p "
    . escapeshellarg($faultRoot . '/sandbox/siterepo') . '/"$2"1 '
    . escapeshellarg($faultRoot . '/sandbox/siterepo') . '/"$2"2; '
    . 'if [ "$2" = partialdemo ]; then touch '
    . escapeshellarg($faultRoot . '/sandbox/siterepo') . '/"$2"1/partial '
    . escapeshellarg($faultRoot . '/sandbox/siterepo') . "/\"\$2\"2/partial; exit 9; fi; fi\nexit 0\n";
file_put_contents($faultRoot . '/sandbox/bin/pair.sh', $faultPairScript);
file_put_contents($faultRoot . '/sandbox/pair.yml', "services: {}\n");
file_put_contents($faultRoot . '/tracked', "fixture\n");
foreach ([
    ['git', 'init', '--initial-branch=main', $faultRoot],
    ['git', '-C', $faultRoot, 'add', 'tracked'],
    ['git', '-C', $faultRoot, '-c', 'user.name=test', '-c', 'user.email=test@example.test', 'commit', '-m', 'fixture'],
] as $command) {
    $result = IdealOnboardingTransport::process($command);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('could not prepare demo fault fixture: ' . trim($result['stderr']));
    }
}

$legacyStatus = legacy_ideal_demo_session(
    ideal_demo_session($faultRoot, 'legacyview', 9246, 9247, 'woocommerce')
);
$legacyStatus['phase'] = 'ready';
$legacyStatus['runtime_before'] = '{"items":1,"order_id":987654321,"status":"processing","stock":37,"total":"19.99"}';
write_ideal_demo_session($legacyStatus);
ob_start();
$legacyStatusExit = DemoCommand::run(['status', '--name=legacyview'], $faultRoot);
$legacyStatusOutput = (string) ob_get_clean();
$migratedLegacy = json_decode((string) file_get_contents((string) $legacyStatus['state_file']), true);
wprism_check_same(0, $legacyStatusExit, 'demo status accepts an exact historical v1 session');
wprism_check_same('wprism-demo-session/v2', $migratedLegacy['format'] ?? null, 'v1 status atomically republishes the current session format');
wprism_check_same('woocommerce', $migratedLegacy['scenario'] ?? null, 'v1 migration restores its historically fixed WooCommerce scenario');
wprism_check(
    str_contains($legacyStatusOutput, 'target-only WooCommerce order and live stock retained; exact internal row witness recorded')
        && !str_contains($legacyStatusOutput, '987654321'),
    'legacy status renders a bounded semantic witness without its target-local order id'
);
unlink((string) $legacyStatus['state_file']);

$legacyAction = legacy_ideal_demo_session(
    ideal_demo_session($faultRoot, 'legacyaction', 9258, 9259, 'woocommerce')
);
$legacyAction['phase'] = 'ready';
$legacyAction['runtime_before'] = '{"items":1}';
write_ideal_demo_session($legacyAction);
ob_start();
$legacyActionExit = DemoCommand::run(['capture', '--name=legacyaction'], $faultRoot);
ob_end_clean();
$retainedLegacyAction = json_decode((string) file_get_contents((string) $legacyAction['state_file']), true);
wprism_check_same(1, $legacyActionExit, 'v1 compatibility is limited to status and recovery actions');
wprism_check_same('wprism-demo-session/v1', $retainedLegacyAction['format'] ?? null, 'an ordinary v1 journey action cannot trigger migration');
unlink((string) $legacyAction['state_file']);

$coreStatus = ideal_demo_session($faultRoot, 'coreproof', 9248, 9249, 'core');
$coreStatus['phase'] = 'ready';
$coreCommentId = '314159265';
$coreRowHash = str_repeat('a', 64);
$coreMetaHash = str_repeat('b', 64);
$coreStatus['runtime_before'] = json_encode([
    'comment_id' => (int) $coreCommentId,
    'comment_row_sha256' => $coreRowHash,
    'commentmeta_rows' => 2,
    'commentmeta_sha256' => $coreMetaHash,
], JSON_UNESCAPED_SLASHES);
write_ideal_demo_session($coreStatus);
ob_start();
$coreStatusExit = DemoCommand::run(['status', '--name=coreproof'], $faultRoot);
$coreStatusOutput = (string) ob_get_clean();
wprism_check_same(0, $coreStatusExit, 'demo status renders the current core session');
wprism_check(
    str_contains($coreStatusOutput, 'target-only WordPress comment and comment metadata retained; exact internal row witness recorded'),
    'core status names the semantic target-only witness'
);
wprism_check(
    !str_contains($coreStatusOutput, $coreCommentId)
        && !str_contains($coreStatusOutput, $coreRowHash)
        && !str_contains($coreStatusOutput, $coreMetaHash)
        && preg_match('/[a-f0-9]{64}/D', $coreStatusOutput) !== 1,
    'core status never prints its raw comment id or full row hashes'
);
unlink((string) $coreStatus['state_file']);

$legacyStop = legacy_ideal_demo_session(
    ideal_demo_session($faultRoot, 'legacystop', 9250, 9251, 'woocommerce')
);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $legacyStop[$field], 0700, true);
}
file_put_contents((string) $legacyStop['compose_env_file'], "WPRISM_PAIR=legacystop\n");
$legacyStop = own_ideal_demo_paths($legacyStop, ['source_repo', 'target_repo', 'origin', 'compose_env_file']);
write_ideal_demo_session($legacyStop);
ob_start();
$legacyStopExit = DemoCommand::run(['stop', '--name=legacystop'], $faultRoot);
ob_end_clean();
wprism_check_same(0, $legacyStopExit, 'demo stop migrates and removes an exact v1 Woo session');
foreach (['source_repo', 'target_repo', 'origin', 'compose_env_file', 'state_file'] as $field) {
    wprism_check(!file_exists((string) $legacyStop[$field]), "v1 stop removes its owned $field");
}

$legacyClaim = legacy_ideal_demo_session(
    ideal_demo_session($faultRoot, 'legacyclaim', 9252, 9253, 'woocommerce')
);
$legacyClaim['phase'] = 'stopping';
foreach ($legacyClaim['owned_paths'] as $field => $_row) {
    $legacyClaim['owned_paths'][$field] = ['state' => 'deleted', 'identity' => null];
}
write_ideal_demo_session($legacyClaim);
$legacyClaimPath = $legacyClaim['state_file'] . '.remove-' . $legacyClaim['ownership_token'];
rename((string) $legacyClaim['state_file'], $legacyClaimPath);
ob_start();
$legacyClaimExit = DemoCommand::run(['stop', '--name=legacyclaim'], $faultRoot);
ob_end_clean();
wprism_check_same(0, $legacyClaimExit, 'demo stop restores, migrates, and completes an interrupted v1 cleanup claim');
wprism_check(
    !file_exists((string) $legacyClaim['state_file']) && !file_exists($legacyClaimPath),
    'completed v1 claim cleanup removes both canonical and claimed session journals'
);

$legacyHybrid = legacy_ideal_demo_session(
    ideal_demo_session($faultRoot, 'legacyhybrid', 9254, 9255, 'woocommerce')
);
$legacyHybrid['scenario'] = 'woocommerce';
write_ideal_demo_session($legacyHybrid);
ob_start();
$legacyHybridExit = DemoCommand::run(['status', '--name=legacyhybrid'], $faultRoot);
ob_end_clean();
$retainedHybrid = json_decode((string) file_get_contents((string) $legacyHybrid['state_file']), true);
wprism_check_same(1, $legacyHybridExit, 'demo refuses a hybrid v1 shape instead of enabling a broad compatibility path');
wprism_check_same('wprism-demo-session/v1', $retainedHybrid['format'] ?? null, 'a refused hybrid session is retained byte-semantically unmigrated');
unlink((string) $legacyHybrid['state_file']);

$legacySentinel = $tmp . '/legacy-path-sentinel';
mkdir($legacySentinel, 0700);
file_put_contents($legacySentinel . '/keep', "foreign\n");
$legacyTampered = legacy_ideal_demo_session(
    ideal_demo_session($faultRoot, 'legacypath', 9256, 9257, 'woocommerce')
);
$legacyTampered['source_repo'] = $legacySentinel;
write_ideal_demo_session($legacyTampered);
ob_start();
$legacyTamperedExit = DemoCommand::run(['stop', '--name=legacypath'], $faultRoot);
ob_end_clean();
wprism_check_same(1, $legacyTamperedExit, 'v1 acceptance still refuses a cleanup path outside its derived ownership set');
wprism_check(is_file($legacySentinel . '/keep'), 'v1 path validation retains the foreign sentinel');
unlink((string) $legacyTampered['state_file']);

$faultHookCalls = 0;
$faultHook = static function (string $phase) use (&$faultHookCalls, $faultRoot): void {
    if ($phase !== 'compose_env_published') {
        return;
    }
    wprism_check_same('compose_env_published', $phase, 'demo exposes the post-pair recoverability boundary');
    $composeEnv = file_get_contents($faultRoot . '/sandbox/tmp/demo-faultdemo.env');
    wprism_check(
        is_string($composeEnv)
            && str_contains($composeEnv, "WPRISM_CLI_IMAGE=wprism-demo-cli-git:php8.3\n")
            && str_contains(
                $composeEnv,
                "WPRISM_WP_IMAGE=wordpress@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf\n"
            ),
        'demo persists both exact Git CLI and digest-pinned WordPress 7.1 images for every Compose invocation'
    );
    ++$faultHookCalls;
    throw new RuntimeException('injected demo setup failure');
};
for ($attempt = 1; $attempt <= 2; ++$attempt) {
    ob_start();
    $faultExit = DemoCommand::run([
        'start', '--name=faultdemo', '--source-port=9200', '--target-port=9201',
    ], $faultRoot, $faultHook);
    ob_end_clean();
    wprism_check_same(1, $faultExit, "demo setup fault attempt $attempt is reported");
    $faultSession = ideal_demo_session($faultRoot, 'faultdemo', 9200, 9201);
    foreach (['source_repo', 'target_repo', 'origin', 'compose_env_file', 'state_file'] as $field) {
        wprism_check(!file_exists((string) $faultSession[$field]) && !is_link((string) $faultSession[$field]), "demo setup fault removes owned $field on attempt $attempt");
    }
}
wprism_check_same(2, $faultHookCalls, 'a failed demo start can be retried under the same name');
wprism_check(is_dir($faultRoot . '/sandbox/siterepo'), 'a fresh checkout gets its ignored demo repository parent on demand');

$sessionRace = ideal_demo_session($faultRoot, 'sessionrace', 9234, 9235);
$sessionRaceOwned = $sessionRace['state_file'] . '.owned';
$sessionRaceHook = static function (string $phase) use ($sessionRace, $sessionRaceOwned): void {
    if ($phase !== 'session_published') {
        return;
    }
    rename((string) $sessionRace['state_file'], $sessionRaceOwned);
    file_put_contents((string) $sessionRace['state_file'], "foreign session\n");
};
ob_start();
$sessionRaceExit = DemoCommand::run([
    'start', '--name=sessionrace', '--source-port=9234', '--target-port=9235',
], $faultRoot, $sessionRaceHook);
ob_end_clean();
wprism_check_same(1, $sessionRaceExit, 'demo refuses a session-file replacement before its first update');
wprism_check_same("foreign session\n", file_get_contents((string) $sessionRace['state_file']), 'session update refusal preserves the foreign replacement');
wprism_check(is_file($sessionRaceOwned), 'session update refusal retains the exact owned journal inode');
unlink((string) $sessionRace['state_file']);
rename($sessionRaceOwned, (string) $sessionRace['state_file']);
ob_start();
$sessionRaceRetry = DemoCommand::run(['stop', '--name=sessionrace'], $faultRoot);
ob_end_clean();
wprism_check_same(0, $sessionRaceRetry, 'restoring the owned session inode makes setup cleanup resumable');

$readRace = ideal_demo_session($faultRoot, 'readrace', 9240, 9241);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $readRace[$field], 0700, true);
}
file_put_contents((string) $readRace['compose_env_file'], "WPRISM_PAIR=readrace\n");
$readRace = own_ideal_demo_paths($readRace, ['source_repo', 'target_repo', 'origin', 'compose_env_file']);
write_ideal_demo_session($readRace);
$readRaceRecorded = $readRace['state_file'] . '.recorded';
$readRaceBytes = (string) file_get_contents((string) $readRace['state_file']);
$readRaceHook = static function (string $phase) use ($readRace, $readRaceRecorded, $readRaceBytes): void {
    if ($phase !== 'state_file_read') {
        return;
    }
    rename((string) $readRace['state_file'], $readRaceRecorded);
    file_put_contents((string) $readRace['state_file'], $readRaceBytes);
};
ob_start();
$readRaceExit = DemoCommand::run(['stop', '--name=readrace'], $faultRoot, $readRaceHook);
ob_end_clean();
wprism_check_same(1, $readRaceExit, 'demo refuses a valid-looking session replacement after reading the owned handle');
wprism_check(is_file((string) $readRace['state_file']) && is_file($readRaceRecorded), 'session read refusal retains both named and opened journal inodes');
unlink((string) $readRace['state_file']);
rename($readRaceRecorded, (string) $readRace['state_file']);
ob_start();
$readRaceRetry = DemoCommand::run(['stop', '--name=readrace'], $faultRoot);
ob_end_clean();
wprism_check_same(0, $readRaceRetry, 'restoring the opened journal inode makes cleanup resumable');

$stageSwap = ideal_demo_session($faultRoot, 'stageswap', 9242, 9243);
$stageSwapPath = null;
$stageSwapRecorded = null;
$stageSwapMarker = null;
$stageSwapHook = static function (string $phase) use (
    &$stageSwapPath,
    &$stageSwapRecorded,
    &$stageSwapMarker,
    $stageSwap
): void {
    if ($phase !== 'source_repo_staged') {
        return;
    }
    $published = json_decode((string) file_get_contents((string) $stageSwap['state_file']), true);
    $token = (string) ($published['ownership_token'] ?? '');
    $stageSwapPath = dirname((string) $stageSwap['source_repo'])
        . '/.wprism-demo-acquire-' . $token . '-source_repo';
    $stageSwapRecorded = $stageSwapPath . '.recorded';
    $stageSwapMarker = $stageSwapPath . '/.wprism-demo-owner-' . $token . '-source_repo';
    rename($stageSwapPath, $stageSwapRecorded);
    mkdir($stageSwapPath, 0700);
    file_put_contents($stageSwapMarker, $token . ":source_repo\n");
};
ob_start();
$stageSwapExit = DemoCommand::run([
    'start', '--name=stageswap', '--source-port=9242', '--target-port=9243',
], $faultRoot, $stageSwapHook);
ob_end_clean();
wprism_check_same(1, $stageSwapExit, 'demo refuses an acquisition-stage replacement carrying a copied marker');
wprism_check(is_string($stageSwapMarker) && is_file($stageSwapMarker) && is_string($stageSwapRecorded) && is_dir($stageSwapRecorded), 'acquisition-stage refusal retains both foreign and receipt-bound directories');
unlink((string) $stageSwapMarker);
rmdir((string) $stageSwapPath);
rename((string) $stageSwapRecorded, (string) $stageSwapPath);
ob_start();
$stageSwapRetry = DemoCommand::run(['stop', '--name=stageswap'], $faultRoot);
ob_end_clean();
wprism_check_same(0, $stageSwapRetry, 'restoring the receipt-bound acquisition directory makes cleanup resumable');

$environmentSwap = ideal_demo_session($faultRoot, 'envswap', 9244, 9245);
$environmentSwapRecorded = $environmentSwap['compose_env_file'] . '.recorded';
$environmentSwapHook = static function (string $phase) use ($environmentSwap, $environmentSwapRecorded): void {
    if ($phase !== 'compose_env_file_created') {
        return;
    }
    rename((string) $environmentSwap['compose_env_file'], $environmentSwapRecorded);
    file_put_contents(
        (string) $environmentSwap['compose_env_file'],
        (string) file_get_contents($environmentSwapRecorded)
    );
};
ob_start();
$environmentSwapExit = DemoCommand::run([
    'start', '--name=envswap', '--source-port=9244', '--target-port=9245',
], $faultRoot, $environmentSwapHook);
ob_end_clean();
wprism_check_same(1, $environmentSwapExit, 'demo refuses a byte-identical compose-environment replacement before ownership publication');
wprism_check(is_file((string) $environmentSwap['compose_env_file']) && is_file($environmentSwapRecorded), 'environment acquisition refusal retains both foreign and receipt-bound files');
unlink((string) $environmentSwap['compose_env_file']);
rename($environmentSwapRecorded, (string) $environmentSwap['compose_env_file']);
ob_start();
$environmentSwapRetry = DemoCommand::run(['stop', '--name=envswap'], $faultRoot);
ob_end_clean();
wprism_check_same(0, $environmentSwapRetry, 'restoring the receipt-bound environment file makes cleanup resumable');

$acquireFaultHook = static function (string $phase): void {
    if ($phase === 'origin_created') {
        throw new RuntimeException('injected origin reservation fault');
    }
};
ob_start();
$acquireFaultExit = DemoCommand::run([
    'start', '--name=acquirefail', '--source-port=9202', '--target-port=9203',
], $faultRoot, $acquireFaultHook);
ob_end_clean();
$acquireFault = ideal_demo_session($faultRoot, 'acquirefail', 9202, 9203);
wprism_check_same(1, $acquireFaultExit, 'demo reports a failure after origin creation but before its owned receipt');
foreach (['source_repo', 'target_repo', 'origin', 'compose_env_file', 'state_file'] as $field) {
    wprism_check(!file_exists((string) $acquireFault[$field]), "planned ownership recovery removes $field");
}

$foreignAcquire = ideal_demo_session($faultRoot, 'foreignacquire', 9208, 9209);
$foreignAcquireHook = static function (string $phase) use ($foreignAcquire): void {
    if ($phase === 'source_repo_created') {
        mkdir((string) $foreignAcquire['target_repo'], 0700);
    }
};
ob_start();
$foreignAcquireExit = DemoCommand::run([
    'start', '--name=foreignacquire', '--source-port=9208', '--target-port=9209',
], $faultRoot, $foreignAcquireHook);
ob_end_clean();
wprism_check_same(1, $foreignAcquireExit, 'demo start refuses a foreign empty canonical directory before acquisition');
wprism_check(is_dir((string) $foreignAcquire['target_repo']), 'failed start retains the foreign markerless directory');
wprism_check(is_file((string) $foreignAcquire['state_file']), 'failed acquisition retains cleanup authority');
rmdir((string) $foreignAcquire['target_repo']);
ob_start();
$foreignAcquireRetry = DemoCommand::run(['stop', '--name=foreignacquire'], $faultRoot);
ob_end_clean();
wprism_check_same(0, $foreignAcquireRetry, 'removing the foreign directory makes owned acquisition cleanup resumable');

for ($partialAttempt = 1; $partialAttempt <= 2; ++$partialAttempt) {
    ob_start();
    $partialExit = DemoCommand::run([
        'start', '--name=partialdemo', '--source-port=9204', '--target-port=9205',
    ], $faultRoot);
    ob_end_clean();
    $partial = ideal_demo_session($faultRoot, 'partialdemo', 9204, 9205);
    wprism_check_same(1, $partialExit, "partial pair startup failure $partialAttempt is reported");
    foreach (['source_repo', 'target_repo', 'origin', 'compose_env_file', 'state_file'] as $field) {
        wprism_check(!file_exists((string) $partial[$field]), "partial pair startup cleanup removes $field on attempt $partialAttempt");
    }
}

$originFaultHook = static function (string $phase): void {
    if ($phase === 'origin_initialized') {
        throw new RuntimeException('injected post-init origin failure');
    }
};
ob_start();
$originFaultExit = DemoCommand::run([
    'start', '--name=originfail', '--source-port=9206', '--target-port=9207',
], $faultRoot, $originFaultHook);
ob_end_clean();
$originFault = ideal_demo_session($faultRoot, 'originfail', 9206, 9207);
wprism_check_same(1, $originFaultExit, 'demo reports repository preparation failure after bare-origin initialization');
foreach (['source_repo', 'target_repo', 'origin', 'compose_env_file', 'state_file'] as $field) {
    wprism_check(!file_exists((string) $originFault[$field]), "post-init origin cleanup removes $field");
}

$sentinel = $tmp . '/demo-cleanup-sentinel';
mkdir($sentinel, 0700);
file_put_contents($sentinel . '/keep', "owned\n");
$tampered = ideal_demo_session($faultRoot, 'tamperdemo', 9210, 9211);
$tampered['source_repo'] = $sentinel;
write_ideal_demo_session($tampered);
ob_start();
$tamperExit = DemoCommand::run(['stop', '--name=tamperdemo'], $faultRoot);
ob_end_clean();
wprism_check_same(1, $tamperExit, 'demo stop refuses a persisted cleanup path outside its derived ownership set');
wprism_check(is_file($sentinel . '/keep'), 'tampered demo state cannot redirect recursive cleanup');
unlink((string) $tampered['state_file']);

$starting = ideal_demo_session($faultRoot, 'startingdemo', 9220, 9221);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $starting[$field], 0700, true);
}
file_put_contents((string) $starting['compose_env_file'], "WPRISM_PAIR=startingdemo\n");
$starting = own_ideal_demo_paths($starting, ['source_repo', 'target_repo', 'origin', 'compose_env_file']);
write_ideal_demo_session($starting);
ob_start();
$startingStop = DemoCommand::run(['stop', '--name=startingdemo'], $faultRoot);
ob_end_clean();
wprism_check_same(0, $startingStop, 'demo stop resumes cleanup from a provisional starting session');
foreach (['source_repo', 'target_repo', 'origin', 'compose_env_file', 'state_file'] as $field) {
    wprism_check(!file_exists((string) $starting[$field]), "resumed demo stop removes owned $field");
}

$deleteCrash = ideal_demo_session($faultRoot, 'deletecrash', 9226, 9227);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $deleteCrash[$field], 0700, true);
}
file_put_contents((string) $deleteCrash['compose_env_file'], "WPRISM_PAIR=deletecrash\n");
$deleteCrash = own_ideal_demo_paths($deleteCrash, ['source_repo', 'target_repo', 'origin', 'compose_env_file']);
write_ideal_demo_session($deleteCrash);
$deleteCrashHook = static function (string $phase): void {
    if ($phase === 'source_repo_removed') {
        throw new RuntimeException('injected deletion journal interruption');
    }
};
ob_start();
$deleteCrashExit = DemoCommand::run(['stop', '--name=deletecrash'], $faultRoot, $deleteCrashHook);
ob_end_clean();
$deleteCrashState = json_decode((string) file_get_contents((string) $deleteCrash['state_file']), true);
wprism_check_same(1, $deleteCrashExit, 'demo stop reports an interruption after a claimed root was fully removed');
wprism_check_same('deleting', $deleteCrashState['owned_paths']['source_repo']['state'] ?? null, 'deletion intent remains durable across the post-remove interruption');
wprism_check(!file_exists((string) $deleteCrash['source_repo']), 'post-remove interruption leaves the canonical root absent');
ob_start();
$deleteCrashRetry = DemoCommand::run(['stop', '--name=deletecrash'], $faultRoot);
ob_end_clean();
wprism_check_same(0, $deleteCrashRetry, 'demo stop resumes deleting-plus-absent progress and removes remaining resources');

$claimedCrash = ideal_demo_session($faultRoot, 'claimedcrash', 9232, 9233);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $claimedCrash[$field], 0700, true);
}
file_put_contents((string) $claimedCrash['compose_env_file'], "WPRISM_PAIR=claimedcrash\n");
$claimedCrash = own_ideal_demo_paths($claimedCrash, ['source_repo', 'target_repo', 'origin', 'compose_env_file']);
write_ideal_demo_session($claimedCrash);
$claimedCrashHook = static function (string $phase): void {
    if ($phase === 'source_repo_claimed') {
        throw new RuntimeException('injected post-claim interruption');
    }
};
ob_start();
$claimedCrashExit = DemoCommand::run(['stop', '--name=claimedcrash'], $faultRoot, $claimedCrashHook);
ob_end_clean();
$claimedState = json_decode((string) file_get_contents((string) $claimedCrash['state_file']), true);
$claimedPath = dirname((string) $claimedCrash['source_repo'])
    . '/.wprism-demo-remove-' . $claimedCrash['ownership_token'] . '-source_repo';
wprism_check_same(1, $claimedCrashExit, 'demo stop reports an interruption after the canonical path is claimed');
wprism_check_same('deleting', $claimedState['owned_paths']['source_repo']['state'] ?? null, 'claimed-path interruption retains durable deletion intent');
wprism_check(!file_exists((string) $claimedCrash['source_repo']) && is_dir($claimedPath), 'claimed-path interruption retains the private owned claim');
ob_start();
$claimedCrashRetry = DemoCommand::run(['stop', '--name=claimedcrash'], $faultRoot);
ob_end_clean();
wprism_check_same(0, $claimedCrashRetry, 'demo stop resumes deleting-plus-claim-present progress');

$stateUnlink = ideal_demo_session($faultRoot, 'stateunlink', 9236, 9237);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $stateUnlink[$field], 0700, true);
}
file_put_contents((string) $stateUnlink['compose_env_file'], "WPRISM_PAIR=stateunlink\n");
$stateUnlink = own_ideal_demo_paths($stateUnlink, ['source_repo', 'target_repo', 'origin', 'compose_env_file']);
write_ideal_demo_session($stateUnlink);
$ownedStateJournal = $stateUnlink['state_file'] . '.owned';
$stateUnlinkHook = static function (string $phase) use ($stateUnlink, $ownedStateJournal): void {
    if ($phase !== 'state_file_removing') {
        return;
    }
    rename((string) $stateUnlink['state_file'], $ownedStateJournal);
    file_put_contents((string) $stateUnlink['state_file'], "foreign final session\n");
};
ob_start();
$stateUnlinkExit = DemoCommand::run(['stop', '--name=stateunlink'], $faultRoot, $stateUnlinkHook);
ob_end_clean();
wprism_check_same(1, $stateUnlinkExit, 'demo refuses a session replacement before final unlink');
wprism_check_same("foreign final session\n", file_get_contents((string) $stateUnlink['state_file']), 'final-unlink refusal preserves the foreign session');
wprism_check(is_file($ownedStateJournal), 'final-unlink refusal retains the completed owned journal');
unlink((string) $stateUnlink['state_file']);
rename($ownedStateJournal, (string) $stateUnlink['state_file']);
ob_start();
$stateUnlinkRetry = DemoCommand::run(['stop', '--name=stateunlink'], $faultRoot);
ob_end_clean();
wprism_check_same(0, $stateUnlinkRetry, 'restoring the owned journal makes final cleanup resumable');

$stateClaim = ideal_demo_session($faultRoot, 'stateclaim', 9238, 9239);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $stateClaim[$field], 0700, true);
}
file_put_contents((string) $stateClaim['compose_env_file'], "WPRISM_PAIR=stateclaim\n");
$stateClaim = own_ideal_demo_paths($stateClaim, ['source_repo', 'target_repo', 'origin', 'compose_env_file']);
write_ideal_demo_session($stateClaim);
$stateClaimHook = static function (string $phase): void {
    if ($phase === 'state_file_claimed') {
        throw new RuntimeException('injected state-claim interruption');
    }
};
ob_start();
$stateClaimExit = DemoCommand::run(['stop', '--name=stateclaim'], $faultRoot, $stateClaimHook);
ob_end_clean();
$stateClaimPath = $stateClaim['state_file'] . '.remove-' . $stateClaim['ownership_token'];
wprism_check_same(1, $stateClaimExit, 'demo stop reports an interruption after claiming its completed session journal');
wprism_check(!file_exists((string) $stateClaim['state_file']) && is_file($stateClaimPath), 'completed cleanup retains the exact private session claim');
ob_start();
$stateClaimRetry = DemoCommand::run(['stop', '--name=stateclaim'], $faultRoot);
ob_end_clean();
wprism_check_same(0, $stateClaimRetry, 'demo stop restores and completes a claimed session journal');

$claimRace = ideal_demo_session($faultRoot, 'claimrace', 9228, 9229);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $claimRace[$field], 0700, true);
}
file_put_contents((string) $claimRace['compose_env_file'], "WPRISM_PAIR=claimrace\n");
$claimRace = own_ideal_demo_paths($claimRace, ['source_repo', 'target_repo', 'origin', 'compose_env_file']);
write_ideal_demo_session($claimRace);
$claimRaceOwned = $claimRace['source_repo'] . '.recorded';
$claimRaceHook = static function (string $phase) use ($claimRace, $claimRaceOwned): void {
    if ($phase !== 'source_repo_deleting') {
        return;
    }
    rename((string) $claimRace['source_repo'], $claimRaceOwned);
    mkdir((string) $claimRace['source_repo'], 0700);
    file_put_contents($claimRace['source_repo'] . '/foreign', "retain\n");
};
ob_start();
$claimRaceExit = DemoCommand::run(['stop', '--name=claimrace'], $faultRoot, $claimRaceHook);
ob_end_clean();
wprism_check_same(1, $claimRaceExit, 'demo cleanup refuses a replacement introduced after deletion intent');
wprism_check(is_file($claimRace['source_repo'] . '/foreign') && is_dir($claimRaceOwned), 'claim race preserves both the replacement and recorded tree');
unlink($claimRace['source_repo'] . '/foreign');
rmdir((string) $claimRace['source_repo']);
rename($claimRaceOwned, (string) $claimRace['source_repo']);
ob_start();
$claimRaceRetry = DemoCommand::run(['stop', '--name=claimrace'], $faultRoot);
ob_end_clean();
wprism_check_same(0, $claimRaceRetry, 'restoring the recorded inode resumes intent-before-delete cleanup');

$replaced = ideal_demo_session($faultRoot, 'replacedemo', 9222, 9223);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $replaced[$field], 0700, true);
}
file_put_contents((string) $replaced['compose_env_file'], "WPRISM_PAIR=replacedemo\n");
$replaced = own_ideal_demo_paths($replaced, ['source_repo', 'target_repo', 'origin', 'compose_env_file']);
write_ideal_demo_session($replaced);
$ownedSource = $replaced['source_repo'] . '.owned';
rename((string) $replaced['source_repo'], $ownedSource);
mkdir((string) $replaced['source_repo'], 0700);
file_put_contents($replaced['source_repo'] . '/foreign', "retain\n");
ob_start();
$replacementStop = DemoCommand::run(['stop', '--name=replacedemo'], $faultRoot);
ob_end_clean();
wprism_check_same(1, $replacementStop, 'demo stop refuses a replacement tree before pair handback or deletion');
wprism_check(is_file($replaced['source_repo'] . '/foreign') && is_file((string) $replaced['state_file']), 'replacement-tree refusal retains both foreign bytes and cleanup authority');
unlink($replaced['source_repo'] . '/foreign');
rmdir((string) $replaced['source_repo']);
rename($ownedSource, (string) $replaced['source_repo']);
ob_start();
$replacementRetry = DemoCommand::run(['stop', '--name=replacedemo'], $faultRoot);
ob_end_clean();
wprism_check_same(0, $replacementRetry, 'restoring the recorded tree identity makes cleanup resumable');

$blockedDelete = ideal_demo_session($faultRoot, 'blockeddelete', 9224, 9225);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $blockedDelete[$field], 0700, true);
}
file_put_contents($blockedDelete['source_repo'] . '/locked', "retain\n");
file_put_contents((string) $blockedDelete['compose_env_file'], "WPRISM_PAIR=blockeddelete\n");
$blockedDelete = own_ideal_demo_paths($blockedDelete, ['source_repo', 'target_repo', 'origin', 'compose_env_file']);
write_ideal_demo_session($blockedDelete);
chmod((string) $blockedDelete['source_repo'], 0500);
ob_start();
$blockedStop = DemoCommand::run(['stop', '--name=blockeddelete'], $faultRoot);
ob_end_clean();
wprism_check_same(1, $blockedStop, 'demo stop reports an owned-tree deletion failure');
wprism_check(is_file((string) $blockedDelete['state_file']), 'failed owned-tree deletion retains the resumable session');
chmod((string) $blockedDelete['source_repo'], 0700);
ob_start();
$blockedRetry = DemoCommand::run(['stop', '--name=blockeddelete'], $faultRoot);
ob_end_clean();
wprism_check_same(0, $blockedRetry, 'demo stop resumes after the owned-tree deletion condition is repaired');

is_string($faultOriginalPath) ? putenv('PATH=' . $faultOriginalPath) : putenv('PATH');

$retryRoot = $tmp . '/retry-demo-root';
foreach ([
    $retryRoot,
    $retryRoot . '/cli',
    $retryRoot . '/fake-bin',
    $retryRoot . '/sandbox',
    $retryRoot . '/sandbox/tmp',
    $retryRoot . '/sandbox/siterepo',
] as $directory) {
    mkdir($directory, 0700);
}
$retry = ideal_demo_session($retryRoot, 'retrydemo', 9230, 9231, 'woocommerce');
file_put_contents((string) $retry['compose_file'], "services: {}\n");
file_put_contents((string) $retry['compose_env_file'], "WPRISM_PAIR=retrydemo\n");
$deployMarker = $retryRoot . '/first-deploy-failed';
$wprismStub = "#!/usr/bin/env bash\nif [ \"\$1\" = deploy ] && [ ! -f " . escapeshellarg($deployMarker) . ' ]; then touch ' . escapeshellarg($deployMarker) . "; exit 9; fi\nexit 0\n";
file_put_contents($retryRoot . '/cli/wprism', $wprismStub);
chmod($retryRoot . '/cli/wprism', 0755);
file_put_contents($retryRoot . '/fake-bin/docker', "#!/usr/bin/env bash\nprintf '%s\\n' '{\"items\":1}'\n");
chmod($retryRoot . '/fake-bin/docker', 0755);
foreach ([
    ['git', 'init', '--bare', '--initial-branch=main', $retry['origin']],
    ['git', 'init', '--initial-branch=main', $retry['source_repo']],
] as $command) {
    $result = IdealOnboardingTransport::process($command);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('could not prepare retry Git fixture: ' . trim($result['stderr']));
    }
}
file_put_contents($retry['source_repo'] . '/managed.txt', "initial\n");
foreach ([
    ['git', '-C', $retry['source_repo'], 'add', 'managed.txt'],
    ['git', '-C', $retry['source_repo'], '-c', 'user.name=test', '-c', 'user.email=test@example.test', 'commit', '-m', 'initial'],
    ['git', '-C', $retry['source_repo'], 'remote', 'add', 'origin', $retry['origin']],
    ['git', '-C', $retry['source_repo'], 'push', '-u', 'origin', 'main'],
    ['git', 'clone', '--branch', 'main', $retry['origin'], $retry['target_repo']],
] as $command) {
    $result = IdealOnboardingTransport::process($command);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('could not establish retry Git fixture: ' . trim($result['stderr']));
    }
}
$initialHead = IdealOnboardingTransport::process(['git', '-C', $retry['source_repo'], 'rev-parse', 'HEAD']);
$retry['phase'] = 'ready';
$retry['runtime_before'] = '{"items":1}';
$retry['last_applied_revision'] = trim($initialHead['stdout']);
$retry = own_ideal_demo_paths($retry, ['source_repo', 'target_repo', 'origin', 'compose_env_file']);
write_ideal_demo_session($retry);
file_put_contents($retry['source_repo'] . '/managed.txt', "changed\n");
$originalPath = getenv('PATH');
putenv('PATH=' . $retryRoot . '/fake-bin:' . (is_string($originalPath) ? $originalPath : ''));
ob_start();
$firstApply = DemoCommand::run(['apply', '--name=retrydemo'], $retryRoot);
ob_end_clean();
$afterFailure = json_decode((string) file_get_contents((string) $retry['state_file']), true);
wprism_check_same(1, $firstApply, 'demo apply reports a post-commit deploy failure');
wprism_check(is_string($afterFailure['pending_revision'] ?? null), 'demo apply durably records the committed revision before deployment');
wprism_check_same('', trim(IdealOnboardingTransport::process(['git', '-C', $retry['source_repo'], 'status', '--short'])['stdout']), 'the interrupted demo source is clean after its commit');
ob_start();
$secondApply = DemoCommand::run(['apply', '--name=retrydemo'], $retryRoot);
ob_end_clean();
if (is_string($originalPath)) {
    putenv('PATH=' . $originalPath);
}
$afterRetry = json_decode((string) file_get_contents((string) $retry['state_file']), true);
wprism_check_same(0, $secondApply, 'demo apply resumes the pending clean revision without another edit');
wprism_check_same(null, $afterRetry['pending_revision'] ?? null, 'a successful retry clears the pending revision');
wprism_check_same($afterFailure['pending_revision'] ?? null, $afterRetry['last_applied_revision'] ?? null, 'a successful retry records the exact revision it completed');

$planRoot = $tmp . '/core-plan-demo-root';
foreach ([
    $planRoot,
    $planRoot . '/cli',
    $planRoot . '/fake-bin',
    $planRoot . '/sandbox',
    $planRoot . '/sandbox/tmp',
    $planRoot . '/sandbox/siterepo',
] as $directory) {
    mkdir($directory, 0700);
}
$planSession = ideal_demo_session($planRoot, 'plancore', 9262, 9263, 'core');
file_put_contents((string) $planSession['compose_file'], "services: {}\n");
file_put_contents((string) $planSession['compose_env_file'], "WPRISM_PAIR=plancore\n");
$planLog = $planRoot . '/calls.log';
file_put_contents($planLog, '');
$planRuntime = json_encode([
    'comment_id' => 7,
    'comment_row_sha256' => str_repeat('c', 64),
    'commentmeta_rows' => 0,
    'commentmeta_sha256' => str_repeat('d', 64),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents($planRoot . '/fake-bin/docker', <<<'SH'
#!/usr/bin/env bash
set -eu
case " $* " in
  *"post_content"*) printf '%s\n' "$WPRISM_DEMO_PLAN_PAGE" ;;
  *) printf '%s\n' "$WPRISM_DEMO_PLAN_RUNTIME" ;;
esac
SH
);
chmod($planRoot . '/fake-bin/docker', 0700);
$planCli = <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);
$log = getenv('WPRISM_DEMO_PLAN_LOG');
if (!is_string($log) || $log === '') { exit(90); }
$command = $argv[1] ?? '';
file_put_contents($log, $command . "\n", FILE_APPEND | LOCK_EX);
if ($command === 'deploy' || $command === 'apply') { exit(0); }
if ($command !== 'release') { exit(91); }
$run = static function (array $argv): string {
    $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { exit(92); }
    fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { exit(92); }
    return trim((string) $stdout);
};
$revision = $run(['git', '-C', getcwd() ?: '.', 'rev-parse', 'HEAD']);
$effects = [
    'containment' => 'prevented',
    'containment_basis' => 'no WordPress hooks fire in the apply window',
    'known_irreversible' => [],
    'lifecycle_window' => null,
    'unknown_blocking' => [],
];
$mode = getenv('WPRISM_DEMO_PLAN_MODE') ?: 'normal';
if ($mode === 'missing-window') { unset($effects['lifecycle_window']); }
if ($mode === 'live-window') { $effects['lifecycle_window'] = ['phases' => ['activate']]; }
if ($mode === 'mutate-target') {
    $target = getenv('WPRISM_DEMO_PLAN_TARGET');
    if (is_string($target) && $target !== '') { file_put_contents($target . '/plan-only-mutated', "bad\n"); }
}
$document = [
    'code_revision_from' => $revision,
    'contract_digest' => getenv('WPRISM_DEMO_PLAN_CONTRACT'),
    'effects' => $effects,
    'environment' => 'demo-target',
    'format' => 'wprism-authorization-plan/v1',
    'may_change' => [
        'authored_state' => ['post_type:page'],
        'code' => [],
        'external' => [],
        'runtime_adjacent' => [],
    ],
    'scope' => [
        'code' => [
            'lifecycle_phases' => ['verify'],
            'plugins_changed' => 0,
            'themes_changed' => 0,
        ],
        'entities' => ['create' => 0, 'delete' => 0, 'update' => 1],
        'surfaces' => ['post_type:page'],
    ],
];
echo "authorization preview fixture\n";
echo json_encode($document, JSON_UNESCAPED_SLASHES) . "\n";
PHP;
file_put_contents($planRoot . '/cli/wprism', $planCli);
chmod($planRoot . '/cli/wprism', 0700);
foreach ([
    ['git', 'init', '--bare', '--initial-branch=main', $planSession['origin']],
    ['git', 'init', '--initial-branch=main', $planSession['source_repo']],
] as $command) {
    $result = IdealOnboardingTransport::process($command);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('could not initialize core plan fixture: ' . trim($result['stderr']));
    }
}
$pageUuid = '87654321-4321-4321-8321-cba987654321';
$pageRelative = 'state/posts/page/' . $pageUuid . '--wprism-demo-page.md';
$planPageFront = [
    'uuid' => $pageUuid,
    'type' => 'page',
    'slug' => 'wprism-demo-page',
    'title' => 'WPrism Demo Page',
    'status' => 'publish',
    'comment_status' => 'open',
    'ping_status' => 'closed',
    'excerpt' => '',
    'menu_order' => 0,
];
$planPageBody = 'reviewed edit';
$planPageWitness = json_encode([
    'comment_status' => 'open',
    'content' => $planPageBody,
    'excerpt' => '',
    'menu_order' => 0,
    'ping_status' => 'closed',
    'slug' => 'wprism-demo-page',
    'status' => 'publish',
    'title' => 'WPrism Demo Page',
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
mkdir($planSession['source_repo'] . '/state/posts/page', 0700, true);
file_put_contents($planSession['source_repo'] . '/.gitignore', Adopt::repositoryGitignoreBytes());
file_put_contents($planSession['source_repo'] . '/site.wprism.json', Adopt::repositorySeedBytes());
file_put_contents(
    $planSession['source_repo'] . '/' . $pageRelative,
    \WPrism\Canon::post_file($planPageFront, 'baseline')
);
$planStore = new ContractStore((string) $planSession['source_repo']);
$planStore->writeContract((array) $acceptedReviewContract, null);
$planStore->writeProjection(['format' => 'plan-fixture-projection/v1', 'surfaces' => []]);
foreach ([
    ['git', '-C', $planSession['source_repo'], 'add', '.gitignore', 'site.wprism.json', $pageRelative],
    ['git', '-C', $planSession['source_repo'], 'add', '-f', '--',
        '.wprism/contract/contract.json', '.wprism/contract/projection.json'],
    ['git', '-C', $planSession['source_repo'], '-c', 'user.name=test', '-c', 'user.email=test@example.test',
        'commit', '-m', 'core plan baseline'],
    ['git', '-C', $planSession['source_repo'], 'remote', 'add', 'origin', $planSession['origin']],
    ['git', '-C', $planSession['source_repo'], 'push', '-u', 'origin', 'main'],
    ['git', 'clone', '--branch', 'main', $planSession['origin'], $planSession['target_repo']],
] as $command) {
    $result = IdealOnboardingTransport::process($command);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('could not establish core plan fixture: ' . trim($result['stderr']));
    }
}
$planBaseline = trim(IdealOnboardingTransport::process([
    'git', '-C', $planSession['source_repo'], 'rev-parse', 'HEAD',
])['stdout']);
$planSession['phase'] = 'ready';
$planSession['runtime_before'] = $planRuntime;
$planSession['last_applied_revision'] = $planBaseline;
$planSession = own_ideal_demo_paths(
    $planSession,
    ['source_repo', 'target_repo', 'origin', 'compose_env_file']
);
write_ideal_demo_session($planSession);
file_put_contents(
    $planSession['source_repo'] . '/' . $pageRelative,
    \WPrism\Canon::post_file($planPageFront, $planPageBody)
);
$planOriginalPath = getenv('PATH');
putenv('PATH=' . $planRoot . '/fake-bin:' . (is_string($planOriginalPath) ? $planOriginalPath : ''));
putenv('WPRISM_DEMO_PLAN_LOG=' . $planLog);
putenv('WPRISM_DEMO_PLAN_RUNTIME=' . $planRuntime);
putenv('WPRISM_DEMO_PLAN_PAGE=' . $planPageWitness);
putenv('WPRISM_DEMO_PLAN_CONTRACT=' . $acceptedReviewContract['contract_digest']);
putenv('WPRISM_DEMO_PLAN_TARGET=' . $planSession['target_repo']);
putenv('WPRISM_DEMO_PLAN_MODE=normal');
ob_start();
$planApply = DemoCommand::run(['apply', '--name=plancore'], $planRoot);
$planApplyOutput = (string) ob_get_clean();
wprism_check_same(0, $planApply, 'core demo applies only after its exact real-command authorization preview passes');
$planCalls = preg_split('/\R/', trim((string) file_get_contents($planLog)));
$planCalls = is_array($planCalls) ? array_values(array_filter($planCalls, 'strlen')) : [];
wprism_check_same(
    ['deploy', 'release', 'apply'],
    $planCalls,
    'plan-only authorization preview occurs after deploy and strictly before the lower-level raw apply'
);
wprism_check(
    str_contains($planApplyOutput, 'Authorization preview verified the exact page-only revision')
        && str_contains($planApplyOutput, 'target page matches its exact captured artifact')
        && str_contains($planApplyOutput, 'Production execution requires stage-source'),
    'demo proves authored convergence and separates lower-level evaluation apply from production release authority'
);
$appliedPlanSession = json_decode((string) file_get_contents((string) $planSession['state_file']), true);
$appliedRevision = (string) ($appliedPlanSession['last_applied_revision'] ?? '');
$assertCorePageConvergence = new ReflectionMethod(DemoCommand::class, 'assertCorePageConvergence');
$mismatchedPageWitness = json_decode($planPageWitness, true, 512, JSON_THROW_ON_ERROR);
$mismatchedPageWitness['content'] = 'target did not converge';
putenv('WPRISM_DEMO_PLAN_PAGE=' . json_encode(
    $mismatchedPageWitness,
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
));
try {
    $assertCorePageConvergence->invoke(null, $appliedPlanSession);
    wprism_check(false, 'core demo refuses a target page that did not converge to its captured artifact');
} catch (RuntimeException $error) {
    wprism_check(
        str_contains($error->getMessage(), 'does not equal the exact captured authored page artifact'),
        'authored page mismatch refuses even when the target-only runtime canary is unchanged'
    );
}
putenv('WPRISM_DEMO_PLAN_PAGE=' . $planPageWitness);
$assertCoreReleasePlan = new ReflectionMethod(DemoCommand::class, 'assertCoreReleasePlan');
foreach ([
    'missing-window' => 'missing lifecycle_window',
    'live-window' => 'non-null lifecycle_window',
] as $mode => $meaning) {
    putenv('WPRISM_DEMO_PLAN_MODE=' . $mode);
    try {
        $assertCoreReleasePlan->invoke(null, $appliedPlanSession, $planRoot, $appliedRevision);
        wprism_check(false, "authorization preview refuses a $meaning");
    } catch (RuntimeException $error) {
        wprism_check(
            str_contains($error->getMessage(), 'effect-clean'),
            "authorization preview distinguishes explicit null from a $meaning"
        );
    }
}
putenv('WPRISM_DEMO_PLAN_MODE=mutate-target');
try {
    $assertCoreReleasePlan->invoke(null, $appliedPlanSession, $planRoot, $appliedRevision);
    wprism_check(false, 'authorization preview refuses repository mutation');
} catch (RuntimeException $error) {
    wprism_check(
        str_contains($error->getMessage(), 'changed source repository, target repository, or target runtime'),
        'plan-only read-only proof catches a target repository mutation before raw apply authority'
    );
}
@unlink($planSession['target_repo'] . '/plan-only-mutated');
putenv('WPRISM_DEMO_PLAN_LOG');
putenv('WPRISM_DEMO_PLAN_RUNTIME');
putenv('WPRISM_DEMO_PLAN_PAGE');
putenv('WPRISM_DEMO_PLAN_CONTRACT');
putenv('WPRISM_DEMO_PLAN_TARGET');
putenv('WPRISM_DEMO_PLAN_MODE');
is_string($planOriginalPath) ? putenv('PATH=' . $planOriginalPath) : putenv('PATH');

$httpOverlay = (string) file_get_contents(dirname(__DIR__, 4) . '/sandbox/pair.http.yml');
wprism_check(
    str_contains($httpOverlay, '127.0.0.1:${WPRISM_PORT1}:80')
        && str_contains($httpOverlay, '127.0.0.1:${WPRISM_PORT2}:80'),
    'the demo HTTP overlay publishes both weak-credential sites on loopback only'
);
$pairTemplate = (string) file_get_contents(dirname(__DIR__, 4) . '/sandbox/pair.yml');
wprism_check(
    substr_count($pairTemplate, './siterepo/origin-${WPRISM_PAIR}.git:/origin-${WPRISM_PAIR}.git') === 2,
    'both demo control planes mount the one pair-owned bare origin at its transport-neutral path'
);
$demoSource = (string) file_get_contents(dirname(__DIR__, 4) . '/cli/src/Command/DemoCommand.php');
wprism_check(
    str_contains($demoSource, "'--http', '--artifacts', '--git-cli'"),
    'demo start selects the loopback-pinned HTTP overlay and its Git-capable CLI image'
);
wprism_check(
    str_contains($demoSource, "return '../origin-' . \$name . '.git';")
        && substr_count($demoSource, "['remote', 'set-url', 'origin', self::portableOriginUrl(\$session)]") === 2,
    'demo source and target replace the host-only bootstrap remote with one host/container-relative URL'
);
wprism_check(
    str_contains($demoSource, "'config', 'set', 'WOOCOMMERCE_BIS_ALPHA_ENABLED'")
        && str_contains($demoSource, "'WC_Install::create_tables(); '")
        && str_contains($demoSource, 'wc_stock_notifications')
        && str_contains($demoSource, 'wc_stock_notificationmeta')
        && str_contains($demoSource, 'change_feature_enable("fulfillments", true)')
        && str_contains($demoSource, "['action-scheduler', 'migrate']")
        && str_contains($demoSource, 'ActionScheduler_DBStore')
        && str_contains($demoSource, "['capabilities', \$environment, '--operation=promote', '--format=json']"),
    'demo setup enables Woo native prerequisite lifecycles and refuses to publish an unqualified pair'
);
wprism_check(
    str_contains($demoSource, "'scenario' => 'core'")
        && str_contains($demoSource, "['assess', 'demo-target', '--operation=release', '--limit=10', '--format=json']")
        && str_contains($demoSource, "'wprism-assess-view/v1'")
        && str_contains($demoSource, "'review_required'")
        && str_contains($demoSource, "self::demoCli(\$sourceRoot), 'release', 'demo-target', '--from=' . \$revision")
        && str_contains($demoSource, 'Whole-site release assessment: READY'),
    'default demo requires a ready bounded assessment, explicit review, and exact release authorization preview'
);
wprism_check(
    str_contains(
        $demoSource,
        'wordpress@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf'
    )
        && str_contains($demoSource, "private const WORDPRESS_VERSION = '7.1'")
        && str_contains($demoSource, 'assertWordPressImage($sourceRoot)')
        && str_contains($demoSource, "'WPRISM_WP_IMAGE' => self::WORDPRESS_IMAGE"),
    'demo pins the exact reviewed WordPress 7.1 image, probes its own bytes, and passes the pin to pair startup'
);
wprism_check(
    str_contains($demoSource, 'SELECT * FROM {$wpdb->comments} WHERE comment_ID = %d')
        && str_contains($demoSource, 'SELECT * FROM {$wpdb->commentmeta} WHERE comment_id = %d ORDER BY meta_id ASC')
        && str_contains($demoSource, '"comment_row_sha256" => hash("sha256", $commentBytes)')
        && str_contains($demoSource, '"commentmeta_sha256" => hash("sha256", $commentmetaBytes)'),
    'core demo byte-identity proof hashes the complete target-only comment row and ordered commentmeta rows'
);

$releaseGuide = (string) file_get_contents(dirname(__DIR__, 4) . '/docs/guides/release.md');
wprism_check(
    str_contains($releaseGuide, 'provider-check production --role=source')
        && str_contains($releaseGuide, 'provider-check preview --role=target'),
    'preview setup checks each provider against its actual source/target role'
);
wprism_check(
    str_contains($releaseGuide, 'BRANCH=$(git branch --show-current)')
        && str_contains($releaseGuide, '--branch "$BRANCH"'),
    'preview setup materializes the clean branch the onboarding handoff actually checked out'
);
wprism_check(
    strpos($releaseGuide, '"$WPRISM_CLI" capture preview') < strpos($releaseGuide, '"$WPRISM_CLI" preview remove preview'),
    'preview cleanup is documented only after capture and Git preservation'
);
wprism_check(
    preg_match('/^wprism (?:env provider-check|preview|capture|release|verify) /m', $releaseGuide) !== 1
        && str_contains($releaseGuide, 'WPRISM_CLI="${WPRISM_CLI:-wprism}"'),
    'the release walkthrough remains executable from the quickstart source checkout'
);

$cli = dirname(__DIR__, 4) . '/cli/wprism';
$preview = proc_open(
    [$cli, 'preview', 'create'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $tmp,
    null,
    ['bypass_shell' => true]
);
wprism_check(is_resource($preview), 'preview alias starts through the real CLI boundary');
if (is_resource($preview)) {
    fclose($pipes[0]);
    $previewOut = (string) stream_get_contents($pipes[1]);
    $previewError = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $previewExit = proc_close($preview);
    wprism_check_same(1, $previewExit, 'preview create without an environment is a normal argument refusal');
    wprism_check(str_contains($previewError . $previewOut, "'rehearse' requires an <env> argument"), 'preview create maps to the proven rehearsal command before preflight');
}
$missingRegistry = $tmp . '/preview-missing-envs.json';
$createReap = HostProcess::run([
    $cli, '--envs-file=' . $missingRegistry, 'preview', 'create', 'victim', '--reap',
], $tmp);
wprism_check_same(1, $createReap['exit'], 'preview create rejects the destructive reap flag');
wprism_check(
    str_contains($createReap['stderr'], 'preview create does not accept --reap')
        && !str_contains($createReap['stderr'], 'environment registry'),
    'preview create refuses reap before environment resolution or removal'
);
foreach (['--reap', '--from=production', '--create', '--ttl=60', '--branch=feature'] as $removeFlag) {
    $invalidRemove = HostProcess::run([
        $cli, '--envs-file=' . $missingRegistry, 'preview', 'remove', 'victim', $removeFlag,
    ], $tmp);
    wprism_check_same(1, $invalidRemove['exit'], "preview remove rejects caller flag $removeFlag");
    wprism_check(
        str_contains($invalidRemove['stderr'], 'preview remove accepts only <env> and optional --format=json')
            && !str_contains($invalidRemove['stderr'], 'environment registry'),
        "preview remove rejects $removeFlag before environment resolution"
    );
}

wprism_check_summary('ideal source-checkout onboarding');
