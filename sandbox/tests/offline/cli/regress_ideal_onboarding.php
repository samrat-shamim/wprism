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
        private bool $executeRaw = false
    ) {
        parent::__construct('production', ['repo_path' => $repoPath]);
    }

    public function describe(): string { return 'offline ideal-onboarding transport'; }
    protected function wpCommand(array $wpArgs): string { return 'unused'; }
    protected function rawCommand(string $script): string { return 'unused'; }

    public function captureRaw(string $script): array {
        $this->rawCalls[] = $script;
        if ($this->executeRaw) {
            return self::process(['bash', '-c', $script]);
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
        'runtime_before' => '',
        'last_applied_revision' => '',
        'pending_revision' => null,
    ];
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
    mkdir($stage . '/.git', 0700, true);
    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
};
$probeTransport = new IdealOnboardingTransport();
$factory = static fn(string $name, array $config): EnvironmentDriver => $probeTransport;

ob_start();
$connectExit = ConnectCommand::run([
    'production', '--workspace=' . $workspace, '--transport=local',
    '--wp-path=/var/www/html', '--repo-path=/srv/duo',
], dirname(__DIR__, 4), $factory, $gitRunner);
$connectOutput = (string) ob_get_clean();
duo_check_same(0, $connectExit, 'connect succeeds after three read-only native probes');
duo_check_same(Adopt::repositorySeedBytes(), (string) file_get_contents($workspace . '/site.duo.json'), 'connect and target adoption share one seed byte source');
duo_check_same(Adopt::repositoryGitignoreBytes(), (string) file_get_contents($workspace . '/.gitignore'), 'connect publishes the target-compatible local-artifact ignore boundary');
duo_check((fileperms($workspace . '/.duo-envs.json') & 0777) === 0600, 'the privileged machine-local registry is owner-only');
duo_check(str_contains($connectOutput, 'no target bytes were changed') && str_contains($connectOutput, 'onboard'), 'connect reports its read-only boundary and one next command');
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
], dirname(__DIR__, 4), $factory, $gitRunner);
ob_end_clean();
duo_check_same(1, $traversalExit, 'connect refuses a missing-parent traversal before staging');
duo_check(is_dir($sentinelRepo . '/.git') && is_file($sentinelRepo . '/sentinel'), 'a refused workspace cannot clean up an existing parent repository');

$blockedWorkspace = $tmp . '/multisite';
ob_start();
$blockedExit = ConnectCommand::run([
    'production', '--workspace=' . $blockedWorkspace, '--transport=local',
    '--wp-path=/var/www/html', '--repo-path=/srv/duo',
], dirname(__DIR__, 4), static fn(string $name, array $config): EnvironmentDriver => new IdealOnboardingTransport(false), $gitRunner);
ob_end_clean();
duo_check_same(1, $blockedExit, 'connect refuses unsupported topology');
duo_check(!file_exists($blockedWorkspace), 'a failed read-only probe creates no workspace');

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
duo_check(str_contains($handoffOutput, 'Published the initialized target baseline'), 'onboard reports the completed automated handoff');

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

$faultRoot = $tmp . '/fault-demo-root';
foreach ([$faultRoot, $faultRoot . '/sandbox', $faultRoot . '/sandbox/bin', $faultRoot . '/sandbox/tmp', $faultRoot . '/sandbox/siterepo'] as $directory) {
    mkdir($directory, 0700);
}
file_put_contents($faultRoot . '/sandbox/bin/pair.sh', "#!/usr/bin/env bash\nexit 0\n");
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
write_ideal_demo_session($starting);
ob_start();
$startingStop = DemoCommand::run(['stop', '--name=startingdemo'], $faultRoot);
ob_end_clean();
duo_check_same(0, $startingStop, 'demo stop resumes cleanup from a provisional starting session');
foreach (['source_repo', 'target_repo', 'origin', 'compose_env_file', 'state_file'] as $field) {
    duo_check(!file_exists((string) $starting[$field]), "resumed demo stop removes owned $field");
}

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
