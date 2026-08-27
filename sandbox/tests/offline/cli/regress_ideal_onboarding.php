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

    /** @return array{exit:int,stdout:string,stderr:string} */
    public static function process(array $argv, ?string $cwd = null): array {
        return HostProcess::run($argv, $cwd);
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

$blockedWorkspace = $tmp . '/multisite';
ob_start();
$blockedExit = ConnectCommand::run([
    'production', '--workspace=' . $blockedWorkspace, '--transport=local',
    '--wp-path=/var/www/html', '--repo-path=/srv/duo',
], dirname(__DIR__, 4), static fn(string $name, array $config): EnvironmentDriver => new IdealOnboardingTransport(false), $gitRunner);
ob_end_clean();
duo_check_same(1, $blockedExit, 'connect refuses unsupported topology');
duo_check(!file_exists($blockedWorkspace), 'a failed inspection probe creates no workspace');

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
duo_check_same(0, $correctedExit, 'handoff-only accepts a corrected reachable URL without repeating initialization');
duo_check_same('main', trim(IdealOnboardingTransport::process(['git', '-C', $corrected['workspace'], 'branch', '--show-current'])['stdout']), 'handoff supports the connect-default branch without force-resetting an existing ref');

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
duo_check_same(0, $raceRetry, 'handoff retry completes against the new exact target receipt');

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

$faultRoot = $tmp . '/fault-demo-root';
foreach ([$faultRoot, $faultRoot . '/sandbox', $faultRoot . '/sandbox/bin', $faultRoot . '/sandbox/tmp', $faultRoot . '/sandbox/siterepo'] as $directory) {
    mkdir($directory, 0700);
}
$faultPairScript = "#!/usr/bin/env bash\nset -eu\nif [ \"\$1\" = up ]; then mkdir -p "
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
$faultHook = static function (string $phase) use (&$faultHookCalls): void {
    if ($phase !== 'compose_env_published') {
        return;
    }
    duo_check_same('compose_env_published', $phase, 'demo exposes the post-pair recoverability boundary');
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

duo_check_summary('ideal source-checkout onboarding');
