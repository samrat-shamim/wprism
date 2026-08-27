<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Onboarding/Adopt.php';
require_once __DIR__ . '/AdoptCommand.php';
require_once __DIR__ . '/AssessCommand.php';
require_once __DIR__ . '/InitCommand.php';
require_once __DIR__ . '/StatusCommand.php';

/** Guided composition of the existing adoption, assessment, and init gates. */
final class OnboardCommand {
    /**
     * @param list<string> $extra everything after `<env>`
     * @param ?array{
     *   adopt?:callable(EnvironmentDriver,array,string):int,
     *   assess?:callable(EnvironmentDriver,array,string):int,
     *   init?:callable(EnvironmentDriver,array):int,
     *   status?:callable(EnvironmentDriver):int,
     *   handoff?:callable(EnvironmentDriver,string,string):void
     * } $steps
     */
    public static function run(
        EnvironmentDriver $driver,
        array $extra,
        string $sourceRoot,
        ?array $steps = null
    ): int {
        try {
            [$initArgs, $gitUrl] = self::options($extra);
            AssessCommand::siteRepo(getcwd() ?: '.');
        } catch (\Throwable $error) {
            fwrite(STDERR, 'duo: onboard: ' . $error->getMessage() . "\n");
            return 1;
        }

        $adopt = $steps['adopt'] ?? static fn(EnvironmentDriver $target, array $args, string $root): int =>
            AdoptCommand::run($target, $args, $root);
        $assess = $steps['assess'] ?? static fn(EnvironmentDriver $target, array $args, string $root): int =>
            AssessCommand::run($target, $args, $root);
        $status = $steps['status'] ?? static fn(EnvironmentDriver $environment): int => StatusCommand::run(
            $environment,
            [],
            static function (string $code, string $message, string $remediation): void {
                fwrite(STDERR, "[$code] $message\n");
                fwrite(STDERR, "remedy: $remediation\n");
            },
            static function (array $refusal): void {
                fwrite(STDERR, (string) ($refusal['message'] ?? 'status refused') . "\n");
            },
            static fn(): bool => true
        );
        $init = $steps['init'] ?? static fn(EnvironmentDriver $target, array $args): int =>
            InitCommand::run(
                $target,
                $args,
                static function (array $refusal): void {
                    $message = (string) ($refusal['message'] ?? 'initialization refused');
                    fwrite(STDERR, "duo: $message\n");
                },
                $status,
                static fn(): mixed => fgets(STDIN),
                false
            );
        $handoff = $steps['handoff'] ?? self::publishAndCheckout(...);

        echo "Onboarding 1/3: install Duo transactionally.\n";
        $exit = $adopt($driver, [], $sourceRoot);
        if ($exit !== 0) {
            return $exit;
        }
        echo "Onboarding 2/3: assess the installed site without changing managed state.\n";
        $exit = $assess($driver, [], $sourceRoot);
        if ($exit !== 0) {
            return $exit;
        }
        echo "Onboarding 3/3: review and initialize the managed baseline.\n";
        $exit = $init($driver, $initArgs);
        if ($exit !== 0) {
            return $exit;
        }

        if ($gitUrl !== null) {
            try {
                $workspace = AssessCommand::siteRepo(getcwd() ?: '.');
                $handoff($driver, $workspace, $gitUrl);
            } catch (\Throwable $error) {
                fwrite(STDERR, 'duo: onboard handoff: ' . $error->getMessage() . "\n");
                return 1;
            }
            echo "Published the initialized target baseline and checked out branch main in this workspace.\n";
        } else {
            echo "Initialized successfully. Publish the target repository now, or rerun with --git-url=<url> to automate that handoff.\n";
        }
        echo "Next: create a feature branch, then use `duo preview create <env> --from=<production-env>` for an isolated rehearsal.\n";
        return 0;
    }

    /** @return array{0:list<string>,1:?string} */
    public static function options(array $extra): array {
        $init = [];
        $gitUrl = null;
        foreach ($extra as $arg) {
            if (is_string($arg) && str_starts_with($arg, '--git-url=')) {
                if ($gitUrl !== null) {
                    throw new \RuntimeException('--git-url was supplied more than once');
                }
                $gitUrl = substr($arg, strlen('--git-url='));
                if ($gitUrl === '' || preg_match('/[\x00-\x1f\x7f]/', $gitUrl) === 1) {
                    throw new \RuntimeException('--git-url requires a non-empty URL without control bytes');
                }
                continue;
            }
            $init[] = $arg;
        }
        return [$init, $gitUrl];
    }

    private static function publishAndCheckout(
        EnvironmentDriver $driver,
        string $workspace,
        string $gitUrl
    ): void {
        $repo = $driver->repoPath();
        $q = static fn(string $value): string => escapeshellarg($value);
        $script = 'set -eu; repo=' . $q($repo) . '; url=' . $q($gitUrl) . '; '
            . 'test -d "$repo/.git"; '
            . 'branch=$(git -C "$repo" symbolic-ref --quiet --short HEAD); test "$branch" = main; '
            . 'git -C "$repo" add .gitignore site.duo.json code state media; '
            . 'if ! git -C "$repo" diff --cached --quiet; then '
            . 'git -C "$repo" -c user.name=duo -c user.email=duo@example.test commit -m "duo: initial managed baseline"; fi; '
            . 'if git -C "$repo" remote get-url origin >/dev/null 2>&1; then '
            . 'test "$(git -C "$repo" remote get-url origin)" = "$url"; '
            . 'else git -C "$repo" remote add origin "$url"; fi; '
            . 'git -C "$repo" push -u origin HEAD:refs/heads/main';
        $target = $driver->captureRaw($script);
        if ($target['exit'] !== 0) {
            throw new \RuntimeException('target Git publish failed: ' . trim($target['stderr']));
        }

        $commands = [
            ['git', '-C', $workspace, 'remote', 'add', 'origin', $gitUrl],
            ['git', '-C', $workspace, 'fetch', 'origin', 'main'],
        ];
        foreach ($commands as $command) {
            $result = self::runProcess($command, null);
            if ($result['exit'] !== 0) {
                throw new \RuntimeException('local Git checkout failed: ' . trim($result['stderr']));
            }
        }

        $seed = Adopt::repositorySeedBytes();
        $gitignore = Adopt::repositoryGitignoreBytes();
        self::assertGeneratedFile($workspace . '/site.duo.json', $seed);
        self::assertGeneratedFile($workspace . '/.gitignore', $gitignore);
        if (!unlink($workspace . '/site.duo.json') || !unlink($workspace . '/.gitignore')) {
            throw new \RuntimeException('could not clear the reviewed connection seed before checkout');
        }
        $checkout = self::runProcess(
            ['git', '-C', $workspace, 'checkout', '-b', 'main', '--track', 'origin/main'],
            null
        );
        if ($checkout['exit'] !== 0) {
            file_put_contents($workspace . '/site.duo.json', $seed, LOCK_EX);
            file_put_contents($workspace . '/.gitignore', $gitignore, LOCK_EX);
            throw new \RuntimeException('local Git checkout failed: ' . trim($checkout['stderr']));
        }
    }

    private static function assertGeneratedFile(string $path, string $expected): void {
        if (is_link($path) || !is_file($path) || file_get_contents($path) !== $expected) {
            throw new \RuntimeException("local generated boundary changed; refusing to replace $path");
        }
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private static function runProcess(array $argv, ?string $cwd): array {
        $process = @proc_open(
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
