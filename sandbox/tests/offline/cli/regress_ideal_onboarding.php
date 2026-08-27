<?php
/** The source-checkout path begins locally, then composes existing target gates. */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../cli/src/Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../../../../cli/src/Transport/Transport.php';
require_once __DIR__ . '/../../../../cli/src/Transport/DockerTransport.php';
require_once __DIR__ . '/../../../../cli/src/Onboarding/Adopt.php';
require_once __DIR__ . '/../../../../cli/src/Command/ConnectCommand.php';
require_once __DIR__ . '/../../../../cli/src/Command/OnboardCommand.php';
require_once __DIR__ . '/../../../../cli/src/Command/DemoCommand.php';

use Duo\Orchestrator\Adopt;
use Duo\Orchestrator\ConnectCommand;
use Duo\Orchestrator\DemoCommand;
use Duo\Orchestrator\DockerTransport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\OnboardCommand;
use Duo\Orchestrator\Transport;

final class IdealOnboardingTransport extends Transport {
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
        if ($this->executeRaw) {
            return self::process(['bash', '-c', $script]);
        }
        return ['exit' => 0, 'stdout' => "duo-connect-ready\n", 'stderr' => ''];
    }

    public function captureWp(array $wpArgs): array {
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
        $process = proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => 'could not start process'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [
            'exit' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}

$tmp = sys_get_temp_dir() . '/duo-ideal-onboarding-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
register_shutdown_function(static function () use ($tmp): void {
    exec('rm -rf ' . escapeshellarg($tmp));
});

$workspace = $tmp . '/workspace';
$resolvedWorkspace = (realpath($tmp) ?: $tmp) . '/workspace';
$gitRunner = static function (array $argv, ?string $cwd) use ($resolvedWorkspace): array {
    duo_check_same(['git', 'init', '--initial-branch=main', $resolvedWorkspace], $argv, 'connect initializes an explicit main-branch Git root');
    duo_check_same(null, $cwd, 'connect passes the complete workspace path to Git rather than relying on cwd');
    mkdir($resolvedWorkspace . '/.git', 0700, true);
    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
};
$factory = static fn(string $name, array $config): EnvironmentDriver => new IdealOnboardingTransport();

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

$overlay = json_decode((string) file_get_contents($workspace . '/.duo-envs.json'), true);
duo_check_same('local', $overlay['envs']['production']['transport'] ?? null, 'connect records the selected transport locally');
duo_check_same(
    ['format' => 'duo-local-control-plane/v1'],
    $overlay['envs']['production']['bootstrap'] ?? null,
    'choosing a local target explicitly authorizes the machine-local adoption bootstrap'
);
duo_check(!isset($overlay['envs']['production']['_dir']), 'loader provenance never leaks into the serialized registry');

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
    'handoff' => static function (EnvironmentDriver $driver, string $repo, string $url) use (&$steps, $resolvedWorkspace): void {
        duo_check_same($resolvedWorkspace, $repo, 'onboard resolves the local repository connect created before target work');
        $steps[] = 'handoff:' . $url;
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
    ['adopt', 'assess', 'init:--yes,--offline', 'handoff:ssh://git.example.test/shop.git'],
    $steps,
    'guided onboarding fixes the composition order and keeps --git-url out of init'
);
duo_check(str_contains($onboardOutput, 'Onboarding 1/3') && str_contains($onboardOutput, 'Onboarding 3/3'), 'guided onboarding makes its three phases visible');

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
    ['git', 'init', '--initial-branch=main', $targetRepo],
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
duo_check_same('main', trim($workspaceBranch['stdout']), 'the connected workspace tracks the published main branch');
duo_check(is_file($handoffWorkspace . '/code/plugin.php'), 'checkout materializes the initialized target payload locally');
duo_check(is_file($handoffWorkspace . '/.duo-envs.json'), 'checkout preserves the ignored machine-local environment registry');
duo_check(str_contains($handoffOutput, 'Published the initialized target baseline'), 'onboard reports the completed automated handoff');

[$initArgs, $gitUrl] = OnboardCommand::options(['--yes', '--git-url=https://example.test/repo.git']);
duo_check_same(['--yes'], $initArgs, 'onboard forwards init flags unchanged');
duo_check_same('https://example.test/repo.git', $gitUrl, 'onboard extracts one handoff URL');

$envFile = $tmp . '/compose.env';
file_put_contents($envFile, "DUO_PAIR=fixture\n");
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
