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

use Duo\Orchestrator\Adopt;
use Duo\Orchestrator\ConnectCommand;
use Duo\Orchestrator\DemoCommand;
use Duo\Orchestrator\DriverCapability;
use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\DockerTransport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\HostProcess;
use Duo\Orchestrator\LocalTransport;
use Duo\Orchestrator\OnboardCommand;
use Duo\Orchestrator\Transport;

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
        string $repoPath = '/srv/duo',
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
        return ['exit' => 0, 'stdout' => "duo-connect-ready\n", 'stderr' => ''];
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
        parent::__construct('bounded', ['repo_path' => '/tmp/bounded']);
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
    public function repoPath(): string { return '/tmp/unbounded'; }
    public function describe(): string { return 'unbounded regression fixture'; }
    public function captureRaw(string $script): array { ++$this->rawCalls; return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
    public function captureWp(array $wpArgs): array { ++$this->wpCalls; return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
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
function ideal_demo_session(string $root, string $name, int $sourcePort, int $targetPort): array {
    $sandbox = $root . '/sandbox';
    return [
        'format' => 'duo-demo-session/v1',
        'name' => $name,
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

/** @return array{driver:IdealOnboardingTransport,target:string,remote:string,workspace:string} */
function ideal_handoff_fixture(string $tmp, string $label, ?Closure $afterRaw = null, string $branch = 'develop'): array {
    $target = $tmp . '/' . $label . '-target';
    $remote = $tmp . '/' . $label . '-remote.git';
    $workspace = $tmp . '/' . $label . '-workspace';
    foreach ([$target, $target . '/code', $target . '/state', $target . '/media'] as $directory) {
        mkdir($directory, 0700);
    }
    file_put_contents($target . '/site.duo.json', Adopt::repositorySeedBytes());
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
    return ['driver' => $driver, 'target' => $target, 'remote' => $remote, 'workspace' => $workspace];
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

$tmp = sys_get_temp_dir() . '/duo-ideal-onboarding-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
register_shutdown_function(static function () use ($tmp): void {
    exec('rm -rf ' . escapeshellarg($tmp));
});

$workspace = $tmp . '/workspace';
$resolvedWorkspace = (realpath($tmp) ?: $tmp) . '/workspace';
$gitRunner = static function (array $argv, ?string $cwd) use ($tmp): array {
    duo_check_same(['git', 'init', '--initial-branch=main'], array_slice($argv, 0, 3), 'connect initializes an explicit main-branch Git root');
    duo_check_same(null, $cwd, 'connect passes the complete workspace path to Git rather than relying on cwd');
    $stage = $argv[3] ?? '';
    duo_check_same(realpath($tmp), realpath(dirname($stage)), 'connect stages beside the requested destination');
    duo_check(str_starts_with(basename($stage), '.duo-connect-'), 'connect uses a private unpredictable staging name');
    return IdealOnboardingTransport::process($argv, $cwd);
};
$probeTransport = new IdealOnboardingTransport();
$factory = static fn(string $name, array $config): EnvironmentDriver => $probeTransport;

ob_start();
$connectExit = ConnectCommand::run([
    'production', '--workspace=' . $workspace, '--transport=local',
    '--wp-path=/var/www/html', '--repo-path=/srv/duo',
], dirname(__DIR__, 4), $factory, $gitRunner);
$connectOutput = (string) ob_get_clean();
duo_check_same(0, $connectExit, 'connect succeeds after three native inspection probes');
duo_check_same(Adopt::repositorySeedBytes(), (string) file_get_contents($workspace . '/site.duo.json'), 'connect and target adoption share one seed byte source');
duo_check_same(Adopt::repositoryGitignoreBytes(), (string) file_get_contents($workspace . '/.gitignore'), 'connect publishes the target-compatible local-artifact ignore boundary');
duo_check((fileperms($workspace . '/.duo-envs.json') & 0777) === 0600, 'the privileged machine-local registry is owner-only');
duo_check(str_contains($connectOutput, 'no explicit mutation') && str_contains($connectOutput, 'site startup code may have run') && str_contains($connectOutput, 'onboard'), 'connect reports the honest WordPress-bootstrap boundary and one next command');
duo_check_same(['echo duo-connect-ready'], $probeTransport->rawCalls, 'connect makes only its declared transport reachability probe');
duo_check_same(
    [['core', 'is-installed'], ['eval', 'echo is_multisite() ? "multisite" : "single-site";']],
    $probeTransport->wpCalls,
    'connect makes only the declared WordPress and topology probes'
);
duo_check_same(
    [['timeout' => 120000, 'stdout' => 1048576, 'stderr' => 1048576]],
    $probeTransport->boundedRawCalls,
    'connect bounds its target reachability probe'
);
duo_check_same(
    [
        ['timeout' => 120000, 'stdout' => 1048576, 'stderr' => 1048576],
        ['timeout' => 120000, 'stdout' => 1048576, 'stderr' => 1048576],
    ],
    $probeTransport->boundedWpCalls,
    'connect bounds both WordPress bootstrap probes'
);

$overlay = json_decode((string) file_get_contents($workspace . '/.duo-envs.json'), true);
duo_check_same('local', $overlay['envs']['production']['transport'] ?? null, 'connect records the selected transport locally');
duo_check_same(
    ['format' => 'duo-local-control-plane/v1'],
    $overlay['envs']['production']['bootstrap'] ?? null,
    'choosing a local target explicitly authorizes the machine-local adoption bootstrap'
);
duo_check(!isset($overlay['envs']['production']['_dir']), 'loader provenance never leaks into the serialized registry');

$sentinelRepo = $tmp . '/existing-repository';
mkdir($sentinelRepo . '/.git', 0700, true);
file_put_contents($sentinelRepo . '/sentinel', "owned\n");
ob_start();
$traversalExit = ConnectCommand::run([
    'production', '--workspace=' . $sentinelRepo . '/missing/..', '--transport=local',
    '--wp-path=/var/www/html', '--repo-path=/srv/duo',
], dirname(__DIR__, 4), $factory);
ob_end_clean();
duo_check_same(1, $traversalExit, 'connect refuses a missing-parent traversal before staging');
duo_check(is_dir($sentinelRepo . '/.git') && is_file($sentinelRepo . '/sentinel'), 'a refused workspace cannot clean up an existing parent repository');

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
    duo_check_same(1, $overlapExit, "connect refuses $label local workspace/repo boundaries");
    duo_check(!file_exists($overlapWorkspace), "connect publishes no workspace for $label boundaries");
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
duo_check_same(1, $caseExit, 'connect conservatively refuses case-only prospective host boundaries');
duo_check(!file_exists($caseWorkspace), 'case-only overlap refusal publishes no workspace');

$unicodeWorkspace = $caseParent . "/Site-\u{00E9}";
ob_start();
$unicodeExit = ConnectCommand::run([
    'production', '--workspace=' . $unicodeWorkspace, '--transport=local',
    '--wp-path=/var/www/html', '--repo-path=' . $caseParent . "/Site-e\u{0301}",
], dirname(__DIR__, 4), $factory);
ob_end_clean();
duo_check_same(1, $unicodeExit, 'connect conservatively refuses normalization-only prospective host boundaries');
duo_check(!file_exists($unicodeWorkspace), 'normalization-only overlap refusal publishes no workspace');
$foldBoundary = new ReflectionMethod(ConnectCommand::class, 'foldComparableBoundary');
$unicodeUpper = $foldBoundary->invoke(null, "/tmp/Site-\u{00C9}", true, false);
$unicodeLower = $foldBoundary->invoke(null, "/tmp/site-\u{00E9}", true, false);
duo_check_same(
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
        file_put_contents($stage . '/.duo-envs.json', "foreign-$stageRace\n");
        $replacementStage = $stage;
        return $stageRace === 'publish'
            ? ['exit' => 0, 'stdout' => '', 'stderr' => '']
            : ['exit' => 9, 'stdout' => '', 'stderr' => 'injected Git failure'];
    };
    ob_start();
    $stageExit = ConnectCommand::run([
        'production', '--workspace=' . $stageWorkspace, '--transport=local',
        '--wp-path=/var/www/html', '--repo-path=/srv/duo',
    ], dirname(__DIR__, 4), $factory, $stageRunner);
    ob_end_clean();
    duo_check_same(1, $stageExit, "connect refuses a staging-root replacement before $stageRace");
    duo_check(!file_exists($stageWorkspace), "staging replacement before $stageRace is never published");
    duo_check(
        is_string($replacementStage)
            && file_get_contents($replacementStage . '/.duo-envs.json') === "foreign-$stageRace\n",
        "staging replacement before $stageRace is retained byte-identically"
    );
    duo_check(is_string($ownedStage) && is_dir($ownedStage), "the displaced owned stage survives the $stageRace fixture");
}

$blockedWorkspace = $tmp . '/multisite';
ob_start();
$blockedExit = ConnectCommand::run([
    'production', '--workspace=' . $blockedWorkspace, '--transport=local',
    '--wp-path=/var/www/html', '--repo-path=/srv/duo',
], dirname(__DIR__, 4), static fn(string $name, array $config): EnvironmentDriver => new IdealOnboardingTransport(false), $gitRunner);
ob_end_clean();
duo_check_same(1, $blockedExit, 'connect refuses unsupported topology');
duo_check(!file_exists($blockedWorkspace), 'a failed inspection probe creates no workspace');

$unboundedDriver = new UnboundedOnboardingDriver();
$unboundedWorkspace = $tmp . '/unbounded-workspace';
ob_start();
$unboundedConnectExit = ConnectCommand::run([
    'unbounded', '--workspace=' . $unboundedWorkspace, '--transport=local',
    '--wp-path=/var/www/html', '--repo-path=/srv/duo',
], dirname(__DIR__, 4), static fn(): EnvironmentDriver => $unboundedDriver, $gitRunner);
ob_end_clean();
duo_check_same(1, $unboundedConnectExit, 'connect refuses a driver without the explicit bounded-control protocol');
duo_check_same(0, $unboundedDriver->rawCalls + $unboundedDriver->wpCalls, 'an unbounded driver is refused before target contact');
duo_check(!file_exists($unboundedWorkspace), 'an unbounded driver cannot publish a connected workspace');
$unboundedReport = $unboundedDriver->capabilityReport('onboard');
duo_check(!$unboundedReport->ready(), 'onboard capability negotiation refuses a driver without bounded control');
duo_check_same(
    DriverCapability::BOUNDED_CONTROL,
    $unboundedReport->blockers()[0]['capability'] ?? null,
    'bounded target control is a declared capability requirement rather than a concrete-class assumption'
);

$originalCwd = getcwd();
chdir($workspace);
$steps = [];
$stepSeams = [
    'handoff_preflight' => static function (EnvironmentDriver $driver, string $repo, string $url) use (&$steps, $resolvedWorkspace): void {
        duo_check_same($resolvedWorkspace, $repo, 'onboard preflights the workspace connect created');
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
        duo_check_same($resolvedWorkspace, $repo, 'onboard resolves the local repository connect created before target work');
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
duo_check_same(0, $onboardExit, 'guided onboarding completes when every existing gate completes');
duo_check_same(
    ['preflight:ssh://git.example.test/shop.git', 'adopt', 'assess', 'init:--yes,--offline', 'handoff:ssh://git.example.test/shop.git'],
    $steps,
    'guided onboarding verifies handoff authority before target mutation and keeps --git-url out of init'
);
duo_check(str_contains($onboardOutput, 'Onboarding 1/3') && str_contains($onboardOutput, 'Onboarding 3/3'), 'guided onboarding makes its three phases visible');

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
duo_check_same(0, $noUrlExit, 'onboard may stop cleanly after initialization without a remote');
duo_check(str_contains($noUrlOutput, '--handoff-only --git-url=<empty-remote-url>'), 'the no-URL handoff prints an exact resumable command');

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
        'adopt' => static function () use (&$handoffFailureSteps): int { $handoffFailureSteps[] = 'adopt'; return 0; },
        'assess' => static function () use (&$handoffFailureSteps): int { $handoffFailureSteps[] = 'assess'; return 0; },
        'init' => static function () use (&$handoffFailureSteps): int { $handoffFailureSteps[] = 'init'; return 0; },
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
duo_check_same(1, $handoffFailureExit, 'ordinary onboarding reports a bounded target handoff failure after init');
duo_check_same(
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
duo_check(str_contains($resumeMessage, 'onboard \'production\' --handoff-only --git-url=<same-remote-url>'), 'handoff recovery prints one exact resume-only command');
duo_check(!str_contains($resumeMessage, 'operator:secret'), 'handoff recovery never echoes URL credentials');

$preflightMutations = [];
$preflightCwd = getcwd();
chdir($workspace);
ob_start();
$preflightExit = OnboardCommand::run(
    new IdealOnboardingTransport(),
    ['--git-url=' . $tmp . '/remote-does-not-exist.git'],
    dirname(__DIR__, 4),
    [
        'adopt' => static function () use (&$preflightMutations): int { $preflightMutations[] = 'adopt'; return 0; },
        'assess' => static function () use (&$preflightMutations): int { $preflightMutations[] = 'assess'; return 0; },
        'init' => static function () use (&$preflightMutations): int { $preflightMutations[] = 'init'; return 0; },
    ]
);
ob_end_clean();
if (is_string($preflightCwd)) {
    chdir($preflightCwd);
}
duo_check_same(1, $preflightExit, 'onboard refuses an unreachable controller remote during preflight');
duo_check_same([], $preflightMutations, 'handoff preflight failure occurs before adopt, assess, or init');

$targetRepo = $tmp . '/target-repository';
$bareRemote = $tmp . '/published.git';
$handoffWorkspace = $tmp . '/handoff-workspace';
foreach ([$targetRepo, $targetRepo . '/code', $targetRepo . '/state', $targetRepo . '/media'] as $directory) {
    mkdir($directory, 0700);
}
file_put_contents($targetRepo . '/site.duo.json', Adopt::repositorySeedBytes());
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
duo_check_same(0, $handoffConnectExit, 'the real Git handoff fixture starts through connect');
mkdir($handoffWorkspace . '/.duo/contract/production', 0700, true);
file_put_contents($handoffWorkspace . '/.duo/contract/production/proposed.json', "{\"format\":\"assessment-artifact-fixture\"}\n");

$beforeHandoffCwd = getcwd();
chdir($handoffWorkspace);
ob_start();
$handoffExit = OnboardCommand::run(
    $handoffDriver,
    ['--yes', '--git-url=' . $bareRemote],
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
duo_check_same(0, $handoffExit, 'onboard publishes and checks out the initialized target repository without manual Git commands');
duo_check_same(trim($targetHead['stdout']), trim($workspaceHead['stdout']), 'developer and target worktrees resolve the same initialized revision');
duo_check_same('develop', trim($workspaceBranch['stdout']), 'the connected workspace preserves and tracks the target branch');
duo_check(is_file($handoffWorkspace . '/code/plugin.php'), 'checkout materializes the initialized target payload locally');
duo_check(is_file($handoffWorkspace . '/.duo-envs.json'), 'checkout preserves the ignored machine-local environment registry');
duo_check_same(
    "{\"format\":\"assessment-artifact-fixture\"}\n",
    (string) file_get_contents($handoffWorkspace . '/.duo/contract/production/proposed.json'),
    'handoff preserves the assessment artifact written by the preceding composed step'
);
duo_check(str_contains($handoffOutput, 'Published the initialized target baseline'), 'onboard reports the completed automated handoff');
duo_check(str_contains($handoffOutput, ' assess ') && str_contains($handoffOutput, 'Capture always writes to the target repo_path'), 'onboard recommends a command whose target-worktree effect is explicit');
duo_check(
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
$savedFixtures = getenv('DUO_FIXTURES');
$savedSiteRepo = getenv('DUO_SITE_REPO');
$savedCalls = getenv('DUO_CALLS');
putenv('PATH=' . $defaultAssessRoot . '/bin:' . (is_string($savedPath) ? $savedPath : ''));
putenv('DUO_FIXTURES=' . $defaultAssessRoot . '/fixtures');
putenv('DUO_SITE_REPO=' . $defaultAssessTarget);
putenv('DUO_CALLS=' . $defaultCalls);
ob_start();
$defaultAssessConnect = ConnectCommand::run([
    'fixture', '--workspace=' . $defaultAssessWorkspace, '--transport=local',
    '--wp-path=' . $defaultAssessRoot . '/wordpress', '--repo-path=' . $defaultAssessTarget,
], dirname(__DIR__, 4));
ob_end_clean();
duo_check_same(0, $defaultAssessConnect, 'the default-assess integration starts through a real local connect');
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
                $defaultAssessWorkspace . '/.duo/contract/fixture/proposed.json'
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
    'DUO_FIXTURES' => $savedFixtures,
    'DUO_SITE_REPO' => $savedSiteRepo,
    'DUO_CALLS' => $savedCalls,
] as $name => $value) {
    is_string($value) ? putenv($name . '=' . $value) : putenv($name);
}
duo_check_same(0, $defaultAssessExit, 'onboard completes with the real default assessment step and Git handoff');
duo_check(is_string($proposalBeforeHandoff) && $proposalBeforeHandoff !== '', 'the default assessment writes its proposal before init and handoff');
duo_check_same(
    $proposalBeforeHandoff,
    file_get_contents($defaultAssessWorkspace . '/.duo/contract/fixture/proposed.json'),
    'the real handoff preserves the default assessment proposal byte-identically'
);
duo_check(is_file($defaultAssessWorkspace . '/.duo-envs.json'), 'the default-assess handoff preserves its machine-local registry');
duo_check_same(
    trim(IdealOnboardingTransport::process(['git', '-C', $defaultAssessTarget, 'rev-parse', 'HEAD'])['stdout']),
    trim(IdealOnboardingTransport::process(['git', '-C', $defaultAssessWorkspace, 'rev-parse', 'HEAD'])['stdout']),
    'the default-assess target and controller finish on the same published revision'
);

$tagFixture = ideal_handoff_fixture($tmp, 'tag-only');
$tagSource = $tmp . '/tag-source';
IdealOnboardingTransport::process(['git', 'init', '--initial-branch=main', $tagSource]);
file_put_contents($tagSource . '/tagged', "tagged\n");
foreach ([
    ['git', '-C', $tagSource, 'add', 'tagged'],
    ['git', '-C', $tagSource, '-c', 'user.name=test', '-c', 'user.email=test@example.test', 'commit', '-m', 'tag only'],
    ['git', '-C', $tagSource, 'tag', 'v1'],
    ['git', '-C', $tagSource, 'push', $tagFixture['remote'], 'refs/tags/v1'],
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
    ['--git-url=' . $tagFixture['remote']],
    dirname(__DIR__, 4),
    [
        'adopt' => static function () use (&$tagMutations): int { $tagMutations[] = 'adopt'; return 0; },
        'assess' => static function () use (&$tagMutations): int { $tagMutations[] = 'assess'; return 0; },
        'init' => static function () use (&$tagMutations): int { $tagMutations[] = 'init'; return 0; },
    ]
);
ob_end_clean();
if (is_string($tagCwd)) {
    chdir($tagCwd);
}
duo_check_same(1, $tagExit, 'onboard refuses a tag-only remote as nonempty');
duo_check_same([], $tagMutations, 'a tag-only remote refuses before adopt, assess, or init');

foreach ([
    'handoff-tag' => 'refs/tags/v2',
    'handoff-branch' => 'refs/heads/unrelated',
    'handoff-custom' => 'refs/custom/owned',
] as $label => $foreignRef) {
    $remoteRefFixture = ideal_handoff_fixture($tmp, $label);
    $foreignRevision = ideal_push_remote_ref($tmp, $remoteRefFixture['remote'], $label, $foreignRef);
    $remoteRefCwd = getcwd();
    chdir($remoteRefFixture['workspace']);
    ob_start();
    $remoteRefExit = OnboardCommand::run(
        $remoteRefFixture['driver'],
        ['--handoff-only', '--git-url=' . $remoteRefFixture['remote']],
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
    duo_check_same(1, $remoteRefExit, "handoff-only refuses a remote carrying $foreignRef");
    duo_check_same($foreignRevision, trim($foreignReadback['stdout']), "handoff-only preserves $foreignRef");
    duo_check($publishedReadback['exit'] !== 0, "handoff-only discloses no target baseline beside $foreignRef");
}

$postPreflightFixture = ideal_handoff_fixture($tmp, 'post-preflight-ref');
$postPreflightCwd = getcwd();
chdir($postPreflightFixture['workspace']);
ob_start();
$postPreflightExit = OnboardCommand::run(
    $postPreflightFixture['driver'],
    ['--git-url=' . $postPreflightFixture['remote']],
    dirname(__DIR__, 4),
    [
        'adopt' => static fn(): int => 0,
        'assess' => static function () use ($tmp, $postPreflightFixture): int {
            ideal_push_remote_ref(
                $tmp,
                $postPreflightFixture['remote'],
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
duo_check_same(1, $postPreflightExit, 'onboard rechecks every remote ref after adopt/assess/init');
duo_check(
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
    ['--git-url=' . $unrelatedFixture['remote']],
    dirname(__DIR__, 4),
    [
        'adopt' => static function () use (&$unrelatedMutations): int { $unrelatedMutations[] = 'adopt'; return 0; },
        'assess' => static function () use (&$unrelatedMutations): int { $unrelatedMutations[] = 'assess'; return 0; },
        'init' => static function () use (&$unrelatedMutations): int { $unrelatedMutations[] = 'init'; return 0; },
    ]
);
ob_end_clean();
if (is_string($unrelatedCwd)) {
    chdir($unrelatedCwd);
}
duo_check_same(1, $unrelatedExit, 'normal onboarding refuses unrelated local bytes during preflight');
duo_check_same([], $unrelatedMutations, 'unrelated local bytes refuse before adopt, assess, or init');
duo_check(is_file($unrelatedFixture['workspace'] . '/notes.txt'), 'preflight preserves the unrelated controller file');
duo_check(IdealOnboardingTransport::process(['git', '-C', $unrelatedFixture['target'], 'rev-parse', '--verify', 'HEAD'])['exit'] !== 0, 'preflight local-work refusal occurs before a target commit');

$registryRaceFixture = ideal_handoff_fixture($tmp, 'registry-race');
$registryRaceOriginal = $registryRaceFixture['workspace'] . '/.duo-envs.original';
$registryRaceCwd = getcwd();
chdir($registryRaceFixture['workspace']);
ob_start();
$registryRaceExit = OnboardCommand::run(
    $registryRaceFixture['driver'],
    ['--git-url=' . $registryRaceFixture['remote']],
    dirname(__DIR__, 4),
    [
        'adopt' => static fn(): int => 0,
        'assess' => static function () use ($registryRaceFixture, $registryRaceOriginal): int {
            rename($registryRaceFixture['workspace'] . '/.duo-envs.json', $registryRaceOriginal);
            file_put_contents($registryRaceFixture['workspace'] . '/.duo-envs.json', "foreign registry\n");
            return 0;
        },
        'init' => static fn(): int => 0,
    ]
);
ob_end_clean();
if (is_string($registryRaceCwd)) {
    chdir($registryRaceCwd);
}
duo_check_same(1, $registryRaceExit, 'onboard refuses a machine-local registry replacement during assessment');
duo_check_same(
    "foreign registry\n",
    file_get_contents($registryRaceFixture['workspace'] . '/.duo-envs.json'),
    'assessment-roundtrip refusal preserves the replacement registry'
);
duo_check(
    IdealOnboardingTransport::process(['git', '-C', $registryRaceFixture['target'], 'rev-parse', '--verify', 'HEAD'])['exit'] !== 0,
    'registry authority loss refuses before a target publication commit'
);

$commitFixture = ideal_handoff_fixture($tmp, 'local-commit');
foreach ([
    ['git', '-C', $commitFixture['workspace'], 'add', 'site.duo.json', '.gitignore'],
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
    ['--handoff-only', '--git-url=' . $commitFixture['remote']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($commitCwd)) {
    chdir($commitCwd);
}
duo_check_same(1, $commitExit, 'handoff refuses a controller workspace with local Git work');
duo_check_same($localCommit, trim(IdealOnboardingTransport::process(['git', '-C', $commitFixture['workspace'], 'rev-parse', 'HEAD'])['stdout']), 'handoff refusal preserves the controller commit and visible branch');
duo_check(IdealOnboardingTransport::process(['git', '-C', $commitFixture['target'], 'rev-parse', '--verify', 'HEAD'])['exit'] !== 0, 'controller-work refusal occurs before a target commit');

$stagedTargetFixture = ideal_handoff_fixture($tmp, 'staged-target');
file_put_contents($stagedTargetFixture['target'] . '/.duo-envs.json', "target secret\n");
$forceStage = IdealOnboardingTransport::process([
    'git', '-C', $stagedTargetFixture['target'], 'add', '-f', '.duo-envs.json',
]);
if ($forceStage['exit'] !== 0) {
    throw new RuntimeException('could not stage target disclosure fixture: ' . trim($forceStage['stderr']));
}
$stagedTargetCwd = getcwd();
chdir($stagedTargetFixture['workspace']);
ob_start();
$stagedTargetExit = OnboardCommand::run(
    $stagedTargetFixture['driver'],
    ['--handoff-only', '--git-url=' . $stagedTargetFixture['remote']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($stagedTargetCwd)) {
    chdir($stagedTargetCwd);
}
duo_check_same(1, $stagedTargetExit, 'handoff refuses a nonempty target index before managed staging');
duo_check(
    IdealOnboardingTransport::process([
        'git', '--git-dir=' . $stagedTargetFixture['remote'], 'for-each-ref', '--format=%(refname)',
    ])['stdout'] === '',
    'a force-staged machine-local target registry is never disclosed'
);

$historyFixture = ideal_handoff_fixture($tmp, 'existing-history');
foreach ([
    ['git', '-C', $historyFixture['target'], 'add', '.gitignore', 'site.duo.json', 'code', 'state', 'media'],
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
    ['--handoff-only', '--git-url=' . $historyFixture['remote']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($historyCwd)) {
    chdir($historyCwd);
}
duo_check_same(1, $historyExit, 'guided initial publication refuses pre-existing target history without a Duo receipt');
duo_check_same(
    $historyHead,
    trim(IdealOnboardingTransport::process(['git', '-C', $historyFixture['target'], 'rev-parse', 'HEAD'])['stdout']),
    'existing target history is retained unchanged'
);
duo_check(
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
    ['--handoff-only', '--git-url=' . $corrected['remote']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($correctedCwd)) {
    chdir($correctedCwd);
}
duo_check_same(1, $badUrlExit, 'handoff-only reports an unreachable first URL');
duo_check_same('', trim($targetRemotesAfterBadUrl['stdout']), 'an unreachable URL is not persisted as target origin');
duo_check_same('', trim($targetRefsAfterBadUrl['stdout']), 'controller-only authentication failure leaves target publication refs untouched');
duo_check_same(0, $correctedExit, 'handoff-only accepts a corrected reachable URL without repeating initialization');
duo_check_same('main', trim(IdealOnboardingTransport::process(['git', '-C', $corrected['workspace'], 'branch', '--show-current'])['stdout']), 'handoff supports the connect-default branch without force-resetting an existing ref');

$exactRetryWorkspace = $tmp . '/exact-retry-workspace';
$exactRetryInjected = false;
$exactRetryHook = static function (string $script, array $result) use (
    $exactRetryWorkspace,
    &$exactRetryInjected
): void {
    if ($exactRetryInjected || !str_contains($result['stdout'], 'DUO_HANDOFF ')) {
        return;
    }
    $exactRetryInjected = true;
    file_put_contents($exactRetryWorkspace . '/.duo-envs.json', "temporary controller race\n");
};
$exactRetry = ideal_handoff_fixture($tmp, 'exact-retry', $exactRetryHook);
$exactRegistry = (string) file_get_contents($exactRetry['workspace'] . '/.duo-envs.json');
$exactRetryCwd = getcwd();
chdir($exactRetry['workspace']);
ob_start();
$exactFirstExit = OnboardCommand::run(
    $exactRetry['driver'],
    ['--handoff-only', '--git-url=' . $exactRetry['remote']],
    dirname(__DIR__, 4)
);
ob_end_clean();
file_put_contents($exactRetry['workspace'] . '/.duo-envs.json', $exactRegistry);
ob_start();
$exactSecondExit = OnboardCommand::run(
    $exactRetry['driver'],
    ['--handoff-only', '--git-url=' . $exactRetry['remote']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($exactRetryCwd)) {
    chdir($exactRetryCwd);
}
duo_check_same(1, $exactFirstExit, 'handoff pauses when the controller changes after exact target publication');
duo_check_same(0, $exactSecondExit, 'handoff-only accepts the one exact prior Duo branch/revision on retry');

$pushUrlFixture = ideal_handoff_fixture($tmp, 'push-url');
$wrongPushRemote = $tmp . '/wrong-push.git';
IdealOnboardingTransport::process(['git', 'init', '--bare', '--initial-branch=main', $wrongPushRemote]);
foreach ([
    ['git', '-C', $pushUrlFixture['target'], 'remote', 'add', 'origin', $pushUrlFixture['remote']],
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
    ['--handoff-only', '--git-url=' . $pushUrlFixture['remote']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($pushUrlCwd)) {
    chdir($pushUrlCwd);
}
duo_check_same(1, $pushUrlExit, 'handoff refuses a target origin with a divergent push URL');
duo_check_same('', trim(IdealOnboardingTransport::process(['git', '--git-dir=' . $pushUrlFixture['remote'], 'for-each-ref', '--format=%(refname)'])['stdout']), 'divergent push URL refusal leaves the reviewed remote empty');
duo_check_same('', trim(IdealOnboardingTransport::process(['git', '--git-dir=' . $wrongPushRemote, 'for-each-ref', '--format=%(refname)'])['stdout']), 'divergent push URL refusal discloses nothing to the alternate remote');

$localPushUrlFixture = ideal_handoff_fixture($tmp, 'local-push-url');
$localWrongPush = $tmp . '/local-wrong-push.git';
IdealOnboardingTransport::process(['git', 'init', '--bare', '--initial-branch=main', $localWrongPush]);
foreach ([
    ['git', '-C', $localPushUrlFixture['workspace'], 'remote', 'add', 'origin', $localPushUrlFixture['remote']],
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
    ['--handoff-only', '--git-url=' . $localPushUrlFixture['remote']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($localPushCwd)) {
    chdir($localPushCwd);
}
duo_check_same(1, $localPushExit, 'handoff refuses a local origin with a divergent push URL');
duo_check(
    IdealOnboardingTransport::process(['git', '-C', $localPushUrlFixture['target'], 'rev-parse', '--verify', 'HEAD'])['exit'] !== 0,
    'local push-URL refusal occurs before a target commit'
);
duo_check_same(
    '',
    trim(IdealOnboardingTransport::process(['git', '--git-dir=' . $localWrongPush, 'for-each-ref', '--format=%(refname)'])['stdout']),
    'local push-URL refusal discloses nothing to the alternate remote'
);

$raceTarget = $tmp . '/receipt-race-target';
$raceRemote = $tmp . '/receipt-race-remote.git';
$raceInjected = false;
$raceHook = static function (string $script, array $result) use ($raceTarget, $raceRemote, &$raceInjected): void {
    if ($raceInjected || !str_contains($result['stdout'], 'DUO_HANDOFF ')) {
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
    ['--handoff-only', '--git-url=' . $raceFixture['remote']],
    dirname(__DIR__, 4)
);
ob_end_clean();
$raceSeed = file_get_contents($raceFixture['workspace'] . '/site.duo.json');
$raceBranch = IdealOnboardingTransport::process(['git', '-C', $raceFixture['workspace'], 'symbolic-ref', '--short', 'HEAD']);
ob_start();
$raceRetry = OnboardCommand::run(
    $raceFixture['driver'],
    ['--handoff-only', '--git-url=' . $raceFixture['remote']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($raceCwd)) {
    chdir($raceCwd);
}
duo_check_same(1, $raceExit, 'handoff refuses a branch that moved after the target receipt');
duo_check_same(Adopt::repositorySeedBytes(), $raceSeed, 'receipt race refuses before moving the local generated boundary');
duo_check_same('main', trim($raceBranch['stdout']), 'receipt race leaves the controller on its original unborn branch');
duo_check_same(1, $raceRetry, 'handoff retry refuses target history that moved outside its durable Duo receipt');

$localRaceWorkspace = $tmp . '/local-roundtrip-race-workspace';
$localRaceCommit = '';
$localRaceHook = static function (string $script, array $result) use ($localRaceWorkspace, &$localRaceCommit): void {
    if ($localRaceCommit !== '' || !str_contains($result['stdout'], 'DUO_HANDOFF ')) {
        return;
    }
    foreach ([
        ['git', '-C', $localRaceWorkspace, 'add', 'site.duo.json', '.gitignore'],
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
    ['--handoff-only', '--git-url=' . $localRaceFixture['remote']],
    dirname(__DIR__, 4)
);
ob_end_clean();
if (is_string($localRaceCwd)) {
    chdir($localRaceCwd);
}
duo_check_same(1, $localRaceExit, 'handoff refuses local Git work created during the target publication round trip');
duo_check($localRaceCommit !== '', 'the local race fixture created a real controller commit after target publication');
duo_check_same($localRaceCommit, trim(IdealOnboardingTransport::process(['git', '-C', $localRaceWorkspace, 'rev-parse', 'HEAD'])['stdout']), 'round-trip race refusal preserves the concurrent controller commit and ref');
duo_check_same(Adopt::repositorySeedBytes(), (string) file_get_contents($localRaceWorkspace . '/site.duo.json'), 'round-trip race refusal preserves the controller seed bytes');

[$initArgs, $gitUrl, $handoffOnly] = OnboardCommand::options(['--yes', '--git-url=https://example.test/repo.git']);
duo_check_same(['--yes'], $initArgs, 'onboard forwards init flags unchanged');
duo_check_same('https://example.test/repo.git', $gitUrl, 'onboard extracts one handoff URL');
duo_check_same(false, $handoffOnly, 'ordinary onboarding does not select the resume-only path');
[$resumeArgs, $resumeUrl, $resumeOnly] = OnboardCommand::options(['--handoff-only', '--git-url=https://example.test/resume.git']);
duo_check_same([], $resumeArgs, 'handoff-only forwards no init arguments');
duo_check_same('https://example.test/resume.git', $resumeUrl, 'handoff-only retains the requested remote');
duo_check_same(true, $resumeOnly, 'handoff-only selects the resumable publication path');

$resumeSteps = [];
$resumeCwd = getcwd();
chdir($workspace);
ob_start();
$resumeExit = OnboardCommand::run(
    new IdealOnboardingTransport(),
    ['--handoff-only', '--git-url=ssh://git.example.test/resume.git'],
    dirname(__DIR__, 4),
    [
        'adopt' => static function () use (&$resumeSteps): int { $resumeSteps[] = 'adopt'; return 0; },
        'assess' => static function () use (&$resumeSteps): int { $resumeSteps[] = 'assess'; return 0; },
        'init' => static function () use (&$resumeSteps): int { $resumeSteps[] = 'init'; return 0; },
        'controller_preflight' => static fn(): array => ['exit' => 0, 'stdout' => '', 'stderr' => ''],
        'handoff_preflight' => static function () use (&$resumeSteps): void { $resumeSteps[] = 'preflight'; },
        'handoff' => static function () use (&$resumeSteps): string { $resumeSteps[] = 'handoff'; return 'main'; },
    ]
);
ob_end_clean();
if (is_string($resumeCwd)) {
    chdir($resumeCwd);
}
duo_check_same(0, $resumeExit, 'handoff-only resumes publication after a completed init');
duo_check_same(['handoff'], $resumeSteps, 'handoff-only never repeats adoption, assessment, initialization, or empty-remote preflight');

$envFile = $tmp . '/compose.env';
file_put_contents($envFile, "DUO_PAIR=fixture\n");
$composeFile = $tmp . '/pair.yml';
file_put_contents($composeFile, "services: {}\n");
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
duo_check_same(0, $dockerConnectExit, 'connect accepts readable relative Docker control-plane files');
duo_check_same(realpath($composeFile), $dockerConfig['compose_file'] ?? null, 'connect anchors a relative Compose file before the workspace changes cwd');
duo_check_same(realpath($envFile), $dockerConfig['compose_env_file'] ?? null, 'connect anchors a relative Compose environment before persistence');
duo_check(str_contains($dockerConnectOutput, 'assess') && !str_contains($dockerConnectOutput, ' onboard '), 'Docker connect does not recommend an adoption path it cannot execute');

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
duo_check_same(1, $dockerOverlapExit, 'connect refuses a workspace inside a Docker writable host repository');
duo_check_same([], $dockerOverlapTransport->rawCalls, 'Docker host overlap refuses before any target probe');
duo_check(is_file($dockerHostRepo . '/sentinel') && !file_exists($dockerOverlapWorkspace), 'Docker overlap preserves target bytes and publishes no workspace');

$fakeDockerBin = $tmp . '/fake-docker-bin';
mkdir($fakeDockerBin, 0700);
$fakeDockerLog = $tmp . '/fake-docker.log';
file_put_contents($fakeDockerBin . '/docker', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$DUO_FAKE_DOCKER_LOG"
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
putenv('DUO_FAKE_DOCKER_LOG=' . $fakeDockerLog);
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
            . '{"type":"bind","source":"/tmp/a","target":"/siterepo","read_only":false},'
            . '{"type":"bind","source":"/tmp/b","target":"/siterepo","read_only":false}'
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
    duo_check_same(1, $configExit, "connect fails closed on $label Docker config inspection");
    duo_check(!file_exists($failedWorkspace), "$label Docker config inspection publishes no workspace");
    duo_check_same(1, count($controlCalls), "$label Docker config inspection uses one bounded control-plane capture");
    duo_check_same(30000, $controlCalls[0]['timeout'] ?? null, "$label Docker config inspection has a finite deadline");
    duo_check(!file_exists($fakeDockerLog), "$label Docker config refusal occurs before otherwise-successful target probes");
}

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
    ['type' => 'bind', 'source' => '/tmp/read-only-repo', 'target' => '/siterepo', 'read_only' => true],
]), 'stderr' => '']);
$writableDocker = new DockerTransport('writable', [
    'transport' => 'docker', 'compose_file' => $composeFile, 'service' => 'cli2', 'repo_path' => '/siterepo',
], null, static fn(): array => ['exit' => 0, 'stdout' => $mountConfig([
    ['type' => 'bind', 'source' => '/tmp/writable-repo', 'target' => '/siterepo', 'read_only' => false],
]), 'stderr' => '']);
duo_check_same(null, $namedDocker->hostRepoBoundaryPath(), 'a proven named volume has no writable host repository boundary');
duo_check_same(null, $readOnlyDocker->hostRepoBoundaryPath(), 'a proven read-only bind has no writable host repository boundary');
duo_check_same('/tmp/writable-repo', $writableDocker->hostRepoBoundaryPath(), 'a proven writable bind returns its exact host boundary');

$execControlCalls = [];
$execDocker = new DockerTransport('exec-bounded', [
    'transport' => 'docker', 'compose_file' => $composeFile, 'service' => 'cli2',
    'repo_path' => '/siterepo', 'mode' => 'exec',
], null, static function (string $command) use (&$execControlCalls, $boundedControl): array {
    $execControlCalls[] = $command;
    return $boundedControl->captureRawBounded('sleep 1', 50, 4096, 4096);
});
$execProbe = $execDocker->captureRawBounded('echo should-not-run', 1000, 4096, 4096);
duo_check_same(1, $execProbe['exit'], 'exec mode converts a bounded service-probe timeout into its reviewed precondition refusal');
duo_check(count($execControlCalls) === 1 && str_contains($execControlCalls[0], "'ps' '--status=running' '--services'"), 'exec mode bounds the Docker ps probe before command construction');
$fakeProbeDocker = new DockerTransport('fake-probe', [
    'transport' => 'docker', 'compose_file' => $composeFile, 'service' => 'cli2', 'repo_path' => '/siterepo',
]);
// The first process-group launch can absorb host scheduler pressure from the
// preceding corpus; five seconds still proves a finite bound without making
// this reachability-control fixture a one-second performance assertion.
$fakeReachable = $fakeProbeDocker->captureRawBounded('echo duo-connect-ready', 5000, 4096, 4096);
$fakeInstalled = $fakeProbeDocker->captureWpBounded(['core', 'is-installed'], 5000, 4096, 4096);
$fakeTopology = $fakeProbeDocker->captureWpBounded(
    ['eval', 'echo is_multisite() ? "multisite" : "single-site";'],
    5000,
    4096,
    4096
);
duo_check_same('duo-connect-ready', trim($fakeReachable['stdout']), 'the Docker config fail-closed fixtures would otherwise pass raw reachability');
duo_check_same(0, $fakeInstalled['exit'], 'the Docker config fail-closed fixtures would otherwise pass WordPress reachability');
duo_check_same('single-site', trim($fakeTopology['stdout']), 'the Docker config fail-closed fixtures would otherwise pass topology inspection');
putenv('DUO_FAKE_DOCKER_LOG');
is_string($priorPath) ? putenv('PATH=' . $priorPath) : putenv('PATH');

$docker = new DockerTransport('demo-source', [
    'transport' => 'docker',
    'compose_file' => '/tmp/pair.yml',
    'compose_env_file' => $envFile,
    'service' => 'cli1',
    'repo_path' => '/siterepo',
]);
$wpCommand = new ReflectionMethod(DockerTransport::class, 'wpCommand');
$wire = (string) $wpCommand->invoke($docker, ['duo', 'capture']);
duo_check(str_contains($wire, "'compose' '--env-file' '" . $envFile . "' '-f' '/tmp/pair.yml'"), 'demo registry pins Compose interpolation through an explicit machine-local env file');
duo_check(!$docker->capabilityReport('onboard')->ready(), 'Docker still refuses the adoption composition it cannot deliver');
duo_check($docker->capabilityReport('onboard-handoff')->ready(), 'Docker permits a post-init Git-only handoff over raw control');
$dockerHandoffCli = HostProcess::run([
    dirname(__DIR__, 4) . '/cli/duo',
    'onboard',
    'demo-source',
    '--handoff-only',
    '--git-url=' . $tmp . '/docker-handoff.git',
], $tmp . '/docker-workspace');
duo_check_same(1, $dockerHandoffCli['exit'], 'Docker handoff-only reaches its target Git operation and reports the fixture failure');
duo_check(!str_contains($dockerHandoffCli['stderr'], 'driver does not support'), 'Docker handoff-only is not rejected by adoption-only capabilities');

$demo = DemoCommand::options('start', ['--scenario=woocommerce', '--name=shopdemo', '--source-port=9100', '--target-port=9101']);
duo_check_same('shopdemo', $demo['name'], 'demo accepts an isolated pair name');
duo_check_same(9100, $demo['source_port'], 'demo accepts an explicit source port');
duo_check_same(9101, $demo['target_port'], 'demo accepts an explicit target port');
try {
    DemoCommand::options('start', ['--scenario=unknown']);
    duo_check(false, 'demo refuses an unknown scenario');
} catch (RuntimeException $error) {
    duo_check(str_contains($error->getMessage(), "first demo scenario is 'woocommerce'"), 'demo refusal names the one executable scenario');
}

$largeProcess = HostProcess::run([
    PHP_BINARY,
    '-r',
    'fwrite(STDERR, str_repeat("e", 200000)); fwrite(STDOUT, "ok");',
]);
duo_check_same(0, $largeProcess['exit'], 'the shared host process runner completes with a full stderr pipe');
duo_check_same('ok', $largeProcess['stdout'], 'the shared runner preserves stdout while draining stderr concurrently');
duo_check_same(200000, strlen($largeProcess['stderr']), 'the shared runner drains the entire adversarial stderr payload');
$oversizedProcess = HostProcess::run(
    [PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("x", 200000));'],
    null,
    [],
    false,
    5000,
    65536
);
duo_check_same(125, $oversizedProcess['exit'], 'the shared runner terminates output beyond its explicit capture budget');
duo_check_same('', $oversizedProcess['stdout'], 'oversized child output is not returned to the command boundary');
$timedProcess = HostProcess::run([PHP_BINARY, '-r', 'sleep(5);'], null, [], false, 100, 65536);
duo_check_same(124, $timedProcess['exit'], 'the shared runner terminates a child that exceeds its deadline');
duo_check(str_contains($timedProcess['stderr'], 'timed out'), 'the shared runner reports its own bounded timeout diagnostic');
$closedPipeProcess = HostProcess::run(
    ['sh', '-c', 'exec >/dev/null 2>&1; sleep 2'],
    null,
    [],
    false,
    100,
    65536
);
duo_check_same(124, $closedPipeProcess['exit'], 'the shared runner enforces its deadline after a child closes both capture pipes');
$hostTimeoutMarker = $tmp . '/host-timeout-descendant';
$hostDescendant = HostProcess::run([
    'sh', '-c', '(sleep 0.3; printf mutation > ' . escapeshellarg($hostTimeoutMarker) . ') & wait',
], null, [], false, 50, 4096);
usleep(500000);
duo_check_same(124, $hostDescendant['exit'], 'the shared runner times out the owned process group');
duo_check(!file_exists($hostTimeoutMarker), 'a host descendant cannot mutate after the timeout returns');
$passthroughTimedProcess = HostProcess::run(
    [PHP_BINARY, '-r', 'usleep(500000);'],
    null,
    [],
    true,
    100,
    65536
);
duo_check_same(124, $passthroughTimedProcess['exit'], 'the shared runner enforces an explicit passthrough deadline');
$transferBudgetProcess = HostProcess::run(
    [PHP_BINARY, '-r', 'usleep(50000); fwrite(STDOUT, str_repeat("t", 2000000));'],
    null,
    [],
    false,
    1000,
    4000000
);
duo_check_same(0, $transferBudgetProcess['exit'], 'an explicit transfer budget admits a slower multi-megabyte operation');
duo_check_same(2000000, strlen($transferBudgetProcess['stdout']), 'the explicit transfer budget preserves the complete bounded payload');

$boundedTransport = new BoundedOnboardingTransport();
$closedPipeTarget = $boundedTransport->captureRawBounded(
    escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('fclose(STDOUT); fclose(STDERR); usleep(500000);'),
    50,
    4096,
    4096
);
duo_check_same(124, $closedPipeTarget['exit'], 'bounded target capture terminates a child after both output pipes close');
duo_check_same('transport command timed out', $closedPipeTarget['stderr'], 'bounded target timeout has one stable diagnostic');
$noisyTarget = $boundedTransport->captureRawBounded(
    escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('fwrite(STDOUT, str_repeat("x", 20000));'),
    1000,
    4096,
    4096
);
duo_check_same(125, $noisyTarget['exit'], 'bounded target capture terminates stdout beyond its reviewed budget');
duo_check_same('', $noisyTarget['stdout'], 'over-limit target output is not returned to the onboarding boundary');
$targetTimeoutMarker = $tmp . '/target-timeout-descendant';
$targetDescendant = $boundedTransport->captureRawBounded(
    '(sleep 0.3; printf mutation > ' . escapeshellarg($targetTimeoutMarker) . ') & wait',
    50,
    4096,
    4096
);
usleep(500000);
duo_check_same(124, $targetDescendant['exit'], 'bounded target capture times out the owned process group');
duo_check(!file_exists($targetTimeoutMarker), 'a target descendant cannot mutate after the timeout returns');
$targetOutputMarker = $tmp . '/target-output-descendant';
$targetOutputDescendant = $boundedTransport->captureRawBounded(
    '(sleep 1; printf mutation > ' . escapeshellarg($targetOutputMarker) . ') & '
        . escapeshellarg(PHP_BINARY) . ' -r '
        . escapeshellarg('fwrite(STDOUT, str_repeat("x", 20000));') . '; wait',
    1000,
    4096,
    4096
);
usleep(500000);
duo_check_same(125, $targetOutputDescendant['exit'], 'bounded target capture cancels the owned process group on output refusal');
duo_check(!file_exists($targetOutputMarker), 'a target descendant cannot mutate after output refusal returns');

$faultRoot = $tmp . '/fault-demo-root';
foreach ([$faultRoot, $faultRoot . '/sandbox', $faultRoot . '/sandbox/bin', $faultRoot . '/sandbox/tmp'] as $directory) {
    mkdir($directory, 0700);
}
$faultPairScript = "#!/usr/bin/env bash\nset -eu\nif [ \"\$1\" = up ]; then"
    . "\ncase \" \$* \" in *\" --git-cli \"*) ;; *) exit 11 ;; esac\n"
    . "[ \"\${DUO_CLI_IMAGE:-}\" = \"duo-demo-cli-git:php8.3\" ] || exit 12\nmkdir -p "
    . escapeshellarg($faultRoot . '/sandbox/siterepo') . "/\"\$2\"1 "
    . escapeshellarg($faultRoot . '/sandbox/siterepo') . "/\"\$2\"2; "
    . "if [ \"\$2\" = partialdemo ]; then touch "
    . escapeshellarg($faultRoot . '/sandbox/siterepo') . "/\"\$2\"1/partial "
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
$faultHookCalls = 0;
$faultHook = static function (string $phase) use (&$faultHookCalls, $faultRoot): void {
    if ($phase !== 'compose_env_published') {
        return;
    }
    duo_check_same('compose_env_published', $phase, 'demo exposes the post-pair recoverability boundary');
    $composeEnv = file_get_contents($faultRoot . '/sandbox/tmp/demo-faultdemo.env');
    duo_check(is_string($composeEnv) && str_contains($composeEnv, "DUO_CLI_IMAGE=duo-demo-cli-git:php8.3\n"),
        'demo persists the Git-enabled CLI image for every later compose invocation');
    ++$faultHookCalls;
    throw new RuntimeException('injected demo setup failure');
};
for ($attempt = 1; $attempt <= 2; ++$attempt) {
    ob_start();
    $faultExit = DemoCommand::run([
        'start', '--name=faultdemo', '--source-port=9200', '--target-port=9201',
    ], $faultRoot, $faultHook);
    ob_end_clean();
    duo_check_same(1, $faultExit, "demo setup fault attempt $attempt is reported");
    $faultSession = ideal_demo_session($faultRoot, 'faultdemo', 9200, 9201);
    foreach (['source_repo', 'target_repo', 'origin', 'compose_env_file', 'state_file'] as $field) {
        duo_check(!file_exists((string) $faultSession[$field]) && !is_link((string) $faultSession[$field]), "demo setup fault removes owned $field on attempt $attempt");
    }
}
duo_check_same(2, $faultHookCalls, 'a failed demo start can be retried under the same name');
duo_check(is_dir($faultRoot . '/sandbox/siterepo'), 'a fresh checkout gets its ignored demo repository parent on demand');

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
duo_check_same(1, $sessionRaceExit, 'demo refuses a session-file replacement before its first update');
duo_check_same("foreign session\n", file_get_contents((string) $sessionRace['state_file']), 'session update refusal preserves the foreign replacement');
duo_check(is_file($sessionRaceOwned), 'session update refusal retains the exact owned journal inode');
unlink((string) $sessionRace['state_file']);
rename($sessionRaceOwned, (string) $sessionRace['state_file']);
ob_start();
$sessionRaceRetry = DemoCommand::run(['stop', '--name=sessionrace'], $faultRoot);
ob_end_clean();
duo_check_same(0, $sessionRaceRetry, 'restoring the owned session inode makes setup cleanup resumable');

$readRace = ideal_demo_session($faultRoot, 'readrace', 9240, 9241);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $readRace[$field], 0700, true);
}
file_put_contents((string) $readRace['compose_env_file'], "DUO_PAIR=readrace\n");
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
duo_check_same(1, $readRaceExit, 'demo refuses a valid-looking session replacement after reading the owned handle');
duo_check(is_file((string) $readRace['state_file']) && is_file($readRaceRecorded), 'session read refusal retains both named and opened journal inodes');
unlink((string) $readRace['state_file']);
rename($readRaceRecorded, (string) $readRace['state_file']);
ob_start();
$readRaceRetry = DemoCommand::run(['stop', '--name=readrace'], $faultRoot);
ob_end_clean();
duo_check_same(0, $readRaceRetry, 'restoring the opened journal inode makes cleanup resumable');

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
        . '/.duo-demo-acquire-' . $token . '-source_repo';
    $stageSwapRecorded = $stageSwapPath . '.recorded';
    $stageSwapMarker = $stageSwapPath . '/.duo-demo-owner-' . $token . '-source_repo';
    rename($stageSwapPath, $stageSwapRecorded);
    mkdir($stageSwapPath, 0700);
    file_put_contents($stageSwapMarker, $token . ":source_repo\n");
};
ob_start();
$stageSwapExit = DemoCommand::run([
    'start', '--name=stageswap', '--source-port=9242', '--target-port=9243',
], $faultRoot, $stageSwapHook);
ob_end_clean();
duo_check_same(1, $stageSwapExit, 'demo refuses an acquisition-stage replacement carrying a copied marker');
duo_check(is_string($stageSwapMarker) && is_file($stageSwapMarker) && is_string($stageSwapRecorded) && is_dir($stageSwapRecorded), 'acquisition-stage refusal retains both foreign and receipt-bound directories');
unlink((string) $stageSwapMarker);
rmdir((string) $stageSwapPath);
rename((string) $stageSwapRecorded, (string) $stageSwapPath);
ob_start();
$stageSwapRetry = DemoCommand::run(['stop', '--name=stageswap'], $faultRoot);
ob_end_clean();
duo_check_same(0, $stageSwapRetry, 'restoring the receipt-bound acquisition directory makes cleanup resumable');

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
duo_check_same(1, $environmentSwapExit, 'demo refuses a byte-identical compose-environment replacement before ownership publication');
duo_check(is_file((string) $environmentSwap['compose_env_file']) && is_file($environmentSwapRecorded), 'environment acquisition refusal retains both foreign and receipt-bound files');
unlink((string) $environmentSwap['compose_env_file']);
rename($environmentSwapRecorded, (string) $environmentSwap['compose_env_file']);
ob_start();
$environmentSwapRetry = DemoCommand::run(['stop', '--name=envswap'], $faultRoot);
ob_end_clean();
duo_check_same(0, $environmentSwapRetry, 'restoring the receipt-bound environment file makes cleanup resumable');

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
duo_check_same(1, $acquireFaultExit, 'demo reports a failure after origin creation but before its owned receipt');
foreach (['source_repo', 'target_repo', 'origin', 'compose_env_file', 'state_file'] as $field) {
    duo_check(!file_exists((string) $acquireFault[$field]), "planned ownership recovery removes $field");
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
duo_check_same(1, $foreignAcquireExit, 'demo start refuses a foreign empty canonical directory before acquisition');
duo_check(is_dir((string) $foreignAcquire['target_repo']), 'failed start retains the foreign markerless directory');
duo_check(is_file((string) $foreignAcquire['state_file']), 'failed acquisition retains cleanup authority');
rmdir((string) $foreignAcquire['target_repo']);
ob_start();
$foreignAcquireRetry = DemoCommand::run(['stop', '--name=foreignacquire'], $faultRoot);
ob_end_clean();
duo_check_same(0, $foreignAcquireRetry, 'removing the foreign directory makes owned acquisition cleanup resumable');

for ($partialAttempt = 1; $partialAttempt <= 2; ++$partialAttempt) {
    ob_start();
    $partialExit = DemoCommand::run([
        'start', '--name=partialdemo', '--source-port=9204', '--target-port=9205',
    ], $faultRoot);
    ob_end_clean();
    $partial = ideal_demo_session($faultRoot, 'partialdemo', 9204, 9205);
    duo_check_same(1, $partialExit, "partial pair startup failure $partialAttempt is reported");
    foreach (['source_repo', 'target_repo', 'origin', 'compose_env_file', 'state_file'] as $field) {
        duo_check(!file_exists((string) $partial[$field]), "partial pair startup cleanup removes $field on attempt $partialAttempt");
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
duo_check_same(1, $originFaultExit, 'demo reports repository preparation failure after bare-origin initialization');
foreach (['source_repo', 'target_repo', 'origin', 'compose_env_file', 'state_file'] as $field) {
    duo_check(!file_exists((string) $originFault[$field]), "post-init origin cleanup removes $field");
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
duo_check_same(1, $tamperExit, 'demo stop refuses a persisted cleanup path outside its derived ownership set');
duo_check(is_file($sentinel . '/keep'), 'tampered demo state cannot redirect recursive cleanup');
unlink((string) $tampered['state_file']);

$starting = ideal_demo_session($faultRoot, 'startingdemo', 9220, 9221);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $starting[$field], 0700, true);
}
file_put_contents((string) $starting['compose_env_file'], "DUO_PAIR=startingdemo\n");
$starting = own_ideal_demo_paths($starting, ['source_repo', 'target_repo', 'origin', 'compose_env_file']);
write_ideal_demo_session($starting);
ob_start();
$startingStop = DemoCommand::run(['stop', '--name=startingdemo'], $faultRoot);
ob_end_clean();
duo_check_same(0, $startingStop, 'demo stop resumes cleanup from a provisional starting session');
foreach (['source_repo', 'target_repo', 'origin', 'compose_env_file', 'state_file'] as $field) {
    duo_check(!file_exists((string) $starting[$field]), "resumed demo stop removes owned $field");
}

$deleteCrash = ideal_demo_session($faultRoot, 'deletecrash', 9226, 9227);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $deleteCrash[$field], 0700, true);
}
file_put_contents((string) $deleteCrash['compose_env_file'], "DUO_PAIR=deletecrash\n");
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
duo_check_same(1, $deleteCrashExit, 'demo stop reports an interruption after a claimed root was fully removed');
duo_check_same('deleting', $deleteCrashState['owned_paths']['source_repo']['state'] ?? null, 'deletion intent remains durable across the post-remove interruption');
duo_check(!file_exists((string) $deleteCrash['source_repo']), 'post-remove interruption leaves the canonical root absent');
ob_start();
$deleteCrashRetry = DemoCommand::run(['stop', '--name=deletecrash'], $faultRoot);
ob_end_clean();
duo_check_same(0, $deleteCrashRetry, 'demo stop resumes deleting-plus-absent progress and removes remaining resources');

$claimedCrash = ideal_demo_session($faultRoot, 'claimedcrash', 9232, 9233);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $claimedCrash[$field], 0700, true);
}
file_put_contents((string) $claimedCrash['compose_env_file'], "DUO_PAIR=claimedcrash\n");
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
    . '/.duo-demo-remove-' . $claimedCrash['ownership_token'] . '-source_repo';
duo_check_same(1, $claimedCrashExit, 'demo stop reports an interruption after the canonical path is claimed');
duo_check_same('deleting', $claimedState['owned_paths']['source_repo']['state'] ?? null, 'claimed-path interruption retains durable deletion intent');
duo_check(!file_exists((string) $claimedCrash['source_repo']) && is_dir($claimedPath), 'claimed-path interruption retains the private owned claim');
ob_start();
$claimedCrashRetry = DemoCommand::run(['stop', '--name=claimedcrash'], $faultRoot);
ob_end_clean();
duo_check_same(0, $claimedCrashRetry, 'demo stop resumes deleting-plus-claim-present progress');

$stateUnlink = ideal_demo_session($faultRoot, 'stateunlink', 9236, 9237);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $stateUnlink[$field], 0700, true);
}
file_put_contents((string) $stateUnlink['compose_env_file'], "DUO_PAIR=stateunlink\n");
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
duo_check_same(1, $stateUnlinkExit, 'demo refuses a session replacement before final unlink');
duo_check_same("foreign final session\n", file_get_contents((string) $stateUnlink['state_file']), 'final-unlink refusal preserves the foreign session');
duo_check(is_file($ownedStateJournal), 'final-unlink refusal retains the completed owned journal');
unlink((string) $stateUnlink['state_file']);
rename($ownedStateJournal, (string) $stateUnlink['state_file']);
ob_start();
$stateUnlinkRetry = DemoCommand::run(['stop', '--name=stateunlink'], $faultRoot);
ob_end_clean();
duo_check_same(0, $stateUnlinkRetry, 'restoring the owned journal makes final cleanup resumable');

$stateClaim = ideal_demo_session($faultRoot, 'stateclaim', 9238, 9239);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $stateClaim[$field], 0700, true);
}
file_put_contents((string) $stateClaim['compose_env_file'], "DUO_PAIR=stateclaim\n");
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
duo_check_same(1, $stateClaimExit, 'demo stop reports an interruption after claiming its completed session journal');
duo_check(!file_exists((string) $stateClaim['state_file']) && is_file($stateClaimPath), 'completed cleanup retains the exact private session claim');
ob_start();
$stateClaimRetry = DemoCommand::run(['stop', '--name=stateclaim'], $faultRoot);
ob_end_clean();
duo_check_same(0, $stateClaimRetry, 'demo stop restores and completes a claimed session journal');

$claimRace = ideal_demo_session($faultRoot, 'claimrace', 9228, 9229);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $claimRace[$field], 0700, true);
}
file_put_contents((string) $claimRace['compose_env_file'], "DUO_PAIR=claimrace\n");
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
duo_check_same(1, $claimRaceExit, 'demo cleanup refuses a replacement introduced after deletion intent');
duo_check(is_file($claimRace['source_repo'] . '/foreign') && is_dir($claimRaceOwned), 'claim race preserves both the replacement and recorded tree');
unlink($claimRace['source_repo'] . '/foreign');
rmdir((string) $claimRace['source_repo']);
rename($claimRaceOwned, (string) $claimRace['source_repo']);
ob_start();
$claimRaceRetry = DemoCommand::run(['stop', '--name=claimrace'], $faultRoot);
ob_end_clean();
duo_check_same(0, $claimRaceRetry, 'restoring the recorded inode resumes intent-before-delete cleanup');

$replaced = ideal_demo_session($faultRoot, 'replacedemo', 9222, 9223);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $replaced[$field], 0700, true);
}
file_put_contents((string) $replaced['compose_env_file'], "DUO_PAIR=replacedemo\n");
$replaced = own_ideal_demo_paths($replaced, ['source_repo', 'target_repo', 'origin', 'compose_env_file']);
write_ideal_demo_session($replaced);
$ownedSource = $replaced['source_repo'] . '.owned';
rename((string) $replaced['source_repo'], $ownedSource);
mkdir((string) $replaced['source_repo'], 0700);
file_put_contents($replaced['source_repo'] . '/foreign', "retain\n");
ob_start();
$replacementStop = DemoCommand::run(['stop', '--name=replacedemo'], $faultRoot);
ob_end_clean();
duo_check_same(1, $replacementStop, 'demo stop refuses a replacement tree before pair handback or deletion');
duo_check(is_file($replaced['source_repo'] . '/foreign') && is_file((string) $replaced['state_file']), 'replacement-tree refusal retains both foreign bytes and cleanup authority');
unlink($replaced['source_repo'] . '/foreign');
rmdir((string) $replaced['source_repo']);
rename($ownedSource, (string) $replaced['source_repo']);
ob_start();
$replacementRetry = DemoCommand::run(['stop', '--name=replacedemo'], $faultRoot);
ob_end_clean();
duo_check_same(0, $replacementRetry, 'restoring the recorded tree identity makes cleanup resumable');

$blockedDelete = ideal_demo_session($faultRoot, 'blockeddelete', 9224, 9225);
foreach (['source_repo', 'target_repo', 'origin'] as $field) {
    mkdir((string) $blockedDelete[$field], 0700, true);
}
file_put_contents($blockedDelete['source_repo'] . '/locked', "retain\n");
file_put_contents((string) $blockedDelete['compose_env_file'], "DUO_PAIR=blockeddelete\n");
$blockedDelete = own_ideal_demo_paths($blockedDelete, ['source_repo', 'target_repo', 'origin', 'compose_env_file']);
write_ideal_demo_session($blockedDelete);
chmod((string) $blockedDelete['source_repo'], 0500);
ob_start();
$blockedStop = DemoCommand::run(['stop', '--name=blockeddelete'], $faultRoot);
ob_end_clean();
duo_check_same(1, $blockedStop, 'demo stop reports an owned-tree deletion failure');
duo_check(is_file((string) $blockedDelete['state_file']), 'failed owned-tree deletion retains the resumable session');
chmod((string) $blockedDelete['source_repo'], 0700);
ob_start();
$blockedRetry = DemoCommand::run(['stop', '--name=blockeddelete'], $faultRoot);
ob_end_clean();
duo_check_same(0, $blockedRetry, 'demo stop resumes after the owned-tree deletion condition is repaired');

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
$retry = ideal_demo_session($retryRoot, 'retrydemo', 9230, 9231);
file_put_contents((string) $retry['compose_file'], "services: {}\n");
file_put_contents((string) $retry['compose_env_file'], "DUO_PAIR=retrydemo\n");
$deployMarker = $retryRoot . '/first-deploy-failed';
$duoStub = "#!/usr/bin/env bash\nif [ \"\$1\" = deploy ] && [ ! -f " . escapeshellarg($deployMarker) . " ]; then touch " . escapeshellarg($deployMarker) . "; exit 9; fi\nexit 0\n";
file_put_contents($retryRoot . '/cli/duo', $duoStub);
chmod($retryRoot . '/cli/duo', 0755);
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
duo_check_same(1, $firstApply, 'demo apply reports a post-commit deploy failure');
duo_check(is_string($afterFailure['pending_revision'] ?? null), 'demo apply durably records the committed revision before deployment');
duo_check_same('', trim(IdealOnboardingTransport::process(['git', '-C', $retry['source_repo'], 'status', '--short'])['stdout']), 'the interrupted demo source is clean after its commit');
ob_start();
$secondApply = DemoCommand::run(['apply', '--name=retrydemo'], $retryRoot);
ob_end_clean();
if (is_string($originalPath)) {
    putenv('PATH=' . $originalPath);
}
$afterRetry = json_decode((string) file_get_contents((string) $retry['state_file']), true);
duo_check_same(0, $secondApply, 'demo apply resumes the pending clean revision without another edit');
duo_check_same(null, $afterRetry['pending_revision'] ?? null, 'a successful retry clears the pending revision');
duo_check_same($afterFailure['pending_revision'] ?? null, $afterRetry['last_applied_revision'] ?? null, 'a successful retry records the exact revision it completed');

$httpOverlay = (string) file_get_contents(dirname(__DIR__, 4) . '/sandbox/pair.http.yml');
duo_check(
    str_contains($httpOverlay, '127.0.0.1:${DUO_PORT1}:80')
        && str_contains($httpOverlay, '127.0.0.1:${DUO_PORT2}:80'),
    'the demo HTTP overlay publishes both weak-credential sites on loopback only'
);
$demoSource = (string) file_get_contents(dirname(__DIR__, 4) . '/cli/src/Command/DemoCommand.php');
duo_check(
    str_contains($demoSource, "'--http', '--artifacts', '--git-cli'"),
    'demo start selects the loopback-pinned HTTP overlay and its Git-capable CLI image'
);
duo_check(
    str_contains($demoSource, "'config', 'set', 'WOOCOMMERCE_BIS_ALPHA_ENABLED'")
        && str_contains($demoSource, 'change_feature_enable("fulfillments", true)')
        && str_contains($demoSource, "['capabilities', \$environment, '--operation=promote', '--format=json']"),
    'demo setup enables Woo native prerequisite lifecycles and refuses to publish an unqualified pair'
);

$releaseGuide = (string) file_get_contents(dirname(__DIR__, 4) . '/docs/guides/release.md');
duo_check(
    str_contains($releaseGuide, 'provider-check production --role=source')
        && str_contains($releaseGuide, 'provider-check preview --role=target'),
    'preview setup checks each provider against its actual source/target role'
);
duo_check(
    str_contains($releaseGuide, 'BRANCH=$(git branch --show-current)')
        && str_contains($releaseGuide, '--branch "$BRANCH"'),
    'preview setup materializes the clean branch the onboarding handoff actually checked out'
);
duo_check(
    strpos($releaseGuide, '"$DUO_CLI" capture preview') < strpos($releaseGuide, '"$DUO_CLI" preview remove preview'),
    'preview cleanup is documented only after capture and Git preservation'
);
duo_check(
    preg_match('/^duo (?:env provider-check|preview|capture|release|verify) /m', $releaseGuide) !== 1
        && str_contains($releaseGuide, 'DUO_CLI="${DUO_CLI:-duo}"'),
    'the release walkthrough remains executable from the quickstart source checkout'
);

$cli = dirname(__DIR__, 4) . '/cli/duo';
$preview = proc_open(
    [$cli, 'preview', 'create'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $tmp,
    null,
    ['bypass_shell' => true]
);
duo_check(is_resource($preview), 'preview alias starts through the real CLI boundary');
if (is_resource($preview)) {
    fclose($pipes[0]);
    $previewOut = (string) stream_get_contents($pipes[1]);
    $previewError = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $previewExit = proc_close($preview);
    duo_check_same(1, $previewExit, 'preview create without an environment is a normal argument refusal');
    duo_check(str_contains($previewError . $previewOut, "'rehearse' requires an <env> argument"), 'preview create maps to the proven rehearsal command before preflight');
}
$missingRegistry = $tmp . '/preview-missing-envs.json';
$createReap = HostProcess::run([
    $cli, '--envs-file=' . $missingRegistry, 'preview', 'create', 'victim', '--reap',
], $tmp);
duo_check_same(1, $createReap['exit'], 'preview create rejects the destructive reap flag');
duo_check(
    str_contains($createReap['stderr'], 'preview create does not accept --reap')
        && !str_contains($createReap['stderr'], 'environment registry'),
    'preview create refuses reap before environment resolution or removal'
);
foreach (['--reap', '--from=production', '--create', '--ttl=60', '--branch=feature'] as $removeFlag) {
    $invalidRemove = HostProcess::run([
        $cli, '--envs-file=' . $missingRegistry, 'preview', 'remove', 'victim', $removeFlag,
    ], $tmp);
    duo_check_same(1, $invalidRemove['exit'], "preview remove rejects caller flag $removeFlag");
    duo_check(
        str_contains($invalidRemove['stderr'], 'preview remove accepts only <env> and optional --format=json')
            && !str_contains($invalidRemove['stderr'], 'environment registry'),
        "preview remove rejects $removeFlag before environment resolution"
    );
}

duo_check_summary('ideal source-checkout onboarding');
