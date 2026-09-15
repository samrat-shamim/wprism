<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/../Onboarding/Adopt.php';
require_once __DIR__ . '/../Onboarding/OnboardingHandoffReceipt.php';
require_once __DIR__ . '/../Onboarding/DockerDatabaseSetup.php';
require_once __DIR__ . '/../Authority/OperationAuthorization.php';
require_once __DIR__ . '/../Authority/TargetOperationStore.php';
require_once __DIR__ . '/../Contract/ContractProposal.php';
require_once __DIR__ . '/../Contract/ContractStore.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/AdoptCommand.php';
require_once __DIR__ . '/AssessCommand.php';
require_once __DIR__ . '/HostProcess.php';
require_once __DIR__ . '/InitCommand.php';
require_once __DIR__ . '/StatusCommand.php';

use WPrism\Canon;
use WPrism\CommandRefusalException;

/** Guided composition of the existing adoption, assessment, and init gates. */
final class OnboardCommand {
    private const GIT_TRANSFER_TIMEOUT_MILLISECONDS = 900000;
    private const GIT_TRANSFER_OUTPUT_LIMIT_BYTES = 8388608;
    private const TARGET_PROBE_TIMEOUT_MILLISECONDS = 120000;
    private const TARGET_PROBE_OUTPUT_LIMIT_BYTES = 1048576;
    /**
     * @param list<string> $extra everything after `<env>`
     * @param ?array{
     *   adopt?:callable(EnvironmentDriver,array,string):int,
     *   assess?:callable(EnvironmentDriver,array,string):int,
     *   init?:callable(EnvironmentDriver,array):int,
     *   status?:callable(EnvironmentDriver):int,
     *   controller_preflight?:callable(string,string):array{exit:int,stdout:string,stderr:string},
     *   handoff_preflight?:callable(BoundedControlDriver,string,string):void,
     *   handoff?:callable(BoundedControlDriver,string,string):string,
     *   handoff_resume?:callable(string,EnvironmentDriver):void
     * } $steps
     */
    public static function run(
        EnvironmentDriver $driver,
        array $extra,
        string $sourceRoot,
        ?array $steps = null
    ): int {
        $json = in_array('--format=json', $extra, true);
        try {
            [$initArgs, $gitUrl, $handoffOnly, $json, $statusOnly] = self::options($extra);
            $databaseService = null;
            foreach ($initArgs as $arg) {
                if (is_string($arg) && str_starts_with($arg, DockerDatabaseSetup::SERVICE_FLAG)) {
                    $databaseService = substr($arg, strlen(DockerDatabaseSetup::SERVICE_FLAG));
                }
            }
            $workspace = AssessCommand::siteRepo(getcwd() ?: '.');
            if (!$driver instanceof BoundedControlDriver) {
                throw new \RuntimeException('selected driver does not implement bounded target control');
            }
            if ($handoffOnly && $gitUrl === null) {
                throw new \RuntimeException('--handoff-only requires --git-url=<url>');
            }
            if (($json || $statusOnly) && $gitUrl === null) {
                throw new \RuntimeException('machine-readable onboarding requires --git-url=<url>');
            }
            if (in_array(DockerDatabaseSetup::CONFIGURE_FLAG, $initArgs, true)
                && !$driver instanceof DockerTransport) {
                throw new \RuntimeException('--configure-database is supported only for a machine-local Docker environment');
            }
        } catch (\Throwable $error) {
            return self::failure($error, $json, 'onboarding_invalid', 'the onboarding request is invalid');
        }

        if ($statusOnly) {
            try {
                echo Canon::encode(self::handoffDocument($driver, $workspace, (string) $gitUrl));
                return 0;
            } catch (\Throwable $error) {
                return self::failure(
                    $error,
                    $json,
                    'onboarding_handoff_unavailable',
                    'the current target, repository, and local evidence do not form a complete onboarding handoff'
                );
            }
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
                    fwrite(STDERR, "wprism: $message\n");
                },
                $status,
                static fn(): mixed => fgets(STDIN),
                false
            );
        $handoffPreflight = $steps['handoff_preflight'] ?? self::preflightHandoff(...);
        $controllerPreflight = $steps['controller_preflight'] ?? self::preflightControllerHandoff(...);
        $handoff = $steps['handoff'] ?? self::publishAndCheckout(...);
        $handoffResume = $steps['handoff_resume'] ?? self::renderHandoffResume(...);

        if ($handoffOnly) {
            try {
                $controllerPreflight($workspace, (string) $gitUrl);
                if (!isset($steps['handoff'])) {
                    TargetOperationStore::ensureIdentity($driver);
                }
                $branch = isset($steps['handoff'])
                    ? $handoff($driver, $workspace, (string) $gitUrl)
                    : self::publishAndCheckout($driver, $workspace, (string) $gitUrl);
            } catch (\Throwable $error) {
                return self::failure($error, $json, 'onboarding_handoff_failed', 'the initialized target could not be published safely');
            }
            if ($json) {
                try {
                    echo Canon::encode(self::handoffDocument($driver, $workspace, (string) $gitUrl));
                } catch (\Throwable $error) {
                    return self::failure($error, true, 'onboarding_handoff_unavailable', 'the published handoff could not be reconciled');
                }
            } else {
                self::renderHandoffSuccess($sourceRoot, $driver, $branch);
            }
            return 0;
        }

        $preflightReceipt = null;
        if ($gitUrl !== null) {
            try {
                // Credentials, remote emptiness, local generated bytes and
                // origin consistency are all answerable before adopt/init.
                $preflightReceipt = self::assertPristineLocalCheckout($workspace);
                $handoffPreflight($driver, $workspace, $gitUrl);
            } catch (\Throwable $error) {
                return self::failure($error, $json, 'onboarding_handoff_preflight_failed', 'the onboarding Git handoff preflight failed');
            }
        }

        if (!$json) echo "Onboarding 1/3: install WPrism transactionally.\n";
        $exit = self::step($json, static fn(): int => $adopt($driver, [], $sourceRoot));
        if ($exit !== 0) {
            return $json
                ? self::failure(new \RuntimeException('adopt exited nonzero'), true, 'onboarding_adopt_failed', 'WPrism adoption did not complete')
                : $exit;
        }
        if (!$json) echo "Onboarding 2/3: assess the installed site without changing managed state.\n";
        $exit = self::step($json, static fn(): int => $assess($driver, [], $sourceRoot));
        if ($exit !== 0 && $exit !== AssessCommand::COMPLETE_WITH_GAPS_EXIT) {
            if (!$json && $driver->driverId() === 'docker') {
                self::renderDockerBaselineResume($sourceRoot, $driver, $gitUrl, $databaseService);
            }
            return $json
                ? self::failure(new \RuntimeException('assess exited nonzero'), true, 'onboarding_assess_failed', 'the onboarding assessment did not complete')
                : $exit;
        }
        $handoffReceipt = null;
        if ($preflightReceipt !== null) {
            try {
                $handoffReceipt = self::assessmentReceipt($workspace, $preflightReceipt);
            } catch (\Throwable $error) {
                return self::failure($error, $json, 'onboarding_handoff_failed', 'the local onboarding evidence changed unexpectedly');
            }
        }
        if (!$json) echo "Onboarding 3/3: review and initialize the managed baseline.\n";
        $exit = self::step($json, static fn(): int => $init($driver, $initArgs));
        if ($exit === InitCommand::BASELINE_COMMITTED_READINESS_PENDING_EXIT) {
            $remediation = self::postInitReadinessRemediation($driver, $gitUrl);
            if (!$json) {
                self::renderPostInitReadinessResume($sourceRoot, $driver, $gitUrl);
            }
            return $json
                ? self::failure(
                    new \RuntimeException('post-init managed-scope status exited nonzero'),
                    true,
                    'onboarding_post_init_readiness_pending',
                    'the managed baseline was initialized, but its post-init readiness proof is not clean',
                    $remediation
                )
                : $exit;
        }
        if ($exit !== 0) {
            if (!$json && $driver->driverId() === 'docker') {
                self::renderDockerBaselineResume($sourceRoot, $driver, $gitUrl, $databaseService);
            }
            return $json
                ? self::failure(new \RuntimeException('init exited nonzero'), true, 'onboarding_init_failed', 'the reviewed managed baseline was not initialized')
                : $exit;
        }
        try {
            if ($gitUrl !== null && !isset($steps['handoff'])) {
                TargetOperationStore::ensureIdentity($driver);
            }
        } catch (\Throwable $error) {
            return self::failure($error, $json, 'onboarding_target_identity_failed', 'the adopted target has no stable WPrism operation identity');
        }
        if ($handoffReceipt !== null) {
            try {
                self::assertPristineLocalCheckout($workspace, $handoffReceipt);
            } catch (\Throwable $error) {
                if (!$json) $handoffResume($sourceRoot, $driver);
                return self::failure($error, $json, 'onboarding_handoff_failed', 'the local onboarding boundary changed before publication');
            }
        }

        if ($gitUrl !== null) {
            try {
                $branch = isset($steps['handoff'])
                    ? $handoff($driver, $workspace, $gitUrl)
                    : self::publishAndCheckout($driver, $workspace, $gitUrl, $handoffReceipt);
            } catch (\Throwable $error) {
                if (!$json) $handoffResume($sourceRoot, $driver);
                return self::failure($error, $json, 'onboarding_handoff_failed', 'the initialized target could not be published safely');
            }
            if ($json) {
                try {
                    echo Canon::encode(self::handoffDocument($driver, $workspace, $gitUrl));
                } catch (\Throwable $error) {
                    return self::failure($error, true, 'onboarding_handoff_unavailable', 'the published handoff could not be reconciled');
                }
            } else {
                self::renderHandoffSuccess($sourceRoot, $driver, $branch);
            }
        } else {
            $cli = realpath($sourceRoot . '/cli/wprism') ?: $sourceRoot . '/cli/wprism';
            echo "Initialized successfully. Publish it later without repeating adopt/assess/init:\n";
            echo '  ' . escapeshellarg($cli) . ' onboard ' . escapeshellarg($driver->name())
                . " --handoff-only --git-url=<empty-remote-url>\n";
            echo "The URL must be reachable with Git credentials from both this controller and the WordPress target.\n";
        }
        return 0;
    }

    private static function renderDockerBaselineResume(
        string $sourceRoot,
        EnvironmentDriver $driver,
        ?string $gitUrl,
        ?string $databaseService = null
    ): void {
        $cli = realpath($sourceRoot . '/cli/wprism') ?: $sourceRoot . '/cli/wprism';
        echo "WPrism is installed. Docker bootstrap is initial-only; do not repeat onboard or adopt.\n";
        echo "Repair the named blocker, then resume the existing target:\n";
        echo '  ' . escapeshellarg($cli) . ' assess ' . escapeshellarg($driver->name()) . "\n";
        echo '  ' . escapeshellarg($cli) . ' init ' . escapeshellarg($driver->name());
        if ($databaseService !== null) {
            echo ' --configure-database ' . escapeshellarg(DockerDatabaseSetup::SERVICE_FLAG . $databaseService);
        }
        echo "\n";
        if ($gitUrl !== null) {
            echo '  ' . escapeshellarg($cli) . ' onboard ' . escapeshellarg($driver->name())
                . " --handoff-only --git-url=<same-remote-url>\n";
        }
    }

    private static function renderPostInitReadinessResume(
        string $sourceRoot,
        EnvironmentDriver $driver,
        ?string $gitUrl
    ): void {
        $cli = realpath($sourceRoot . '/cli/wprism') ?: $sourceRoot . '/cli/wprism';
        echo "WPrism installation and baseline initialization completed. Do not rerun init, adopt, or full onboard.\n";
        echo "Resolve every readiness blocker reported by status. For each env_missing row, set its value with:\n";
        echo '  ' . escapeshellarg($cli) . ' env-set ' . escapeshellarg($driver->name())
            . " --name=<name> --stdin\n";
        echo '  ' . escapeshellarg($cli) . ' status ' . escapeshellarg($driver->name()) . "\n";
        echo '  ' . escapeshellarg($cli) . ' onboard ' . escapeshellarg($driver->name())
            . ' --handoff-only --git-url=' . ($gitUrl === null ? '<empty-remote-url>' : '<same-remote-url>') . "\n";
    }

    private static function postInitReadinessRemediation(EnvironmentDriver $driver, ?string $gitUrl): string {
        return 'do not rerun init or adopt; resolve every reported readiness blocker; for env_missing, set each required value with wprism env-set '
            . escapeshellarg($driver->name()) . ' --name=<name> --stdin; verify with wprism status '
            . escapeshellarg($driver->name()) . ', then run wprism onboard ' . escapeshellarg($driver->name())
            . ' --handoff-only --git-url=' . ($gitUrl === null ? '<empty-remote-url>' : '<same-remote-url>');
    }

    /** @return array{0:list<string>,1:?string,2:bool,3:bool,4:bool} */
    public static function options(array $extra): array {
        $init = [];
        $gitUrl = null;
        $handoffOnly = false;
        $json = false;
        $statusOnly = false;
        foreach ($extra as $arg) {
            if ($arg === 'status') {
                if ($statusOnly) {
                    throw new \RuntimeException('status was supplied more than once');
                }
                $statusOnly = true;
                continue;
            }
            if ($arg === '--format=json') {
                if ($json) {
                    throw new \RuntimeException('--format=json was supplied more than once');
                }
                $json = true;
                continue;
            }
            if (is_string($arg) && str_starts_with($arg, '--format')) {
                throw new \RuntimeException('onboard accepts only the exact machine selector --format=json');
            }
            if ($arg === '--handoff-only') {
                if ($handoffOnly) {
                    throw new \RuntimeException('--handoff-only was supplied more than once');
                }
                $handoffOnly = true;
                continue;
            }
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
        if ($handoffOnly && $init !== []) {
            throw new \RuntimeException('--handoff-only accepts no init flags');
        }
        if ($statusOnly && ($handoffOnly || $init !== [])) {
            throw new \RuntimeException('onboard status accepts only --git-url and --format=json');
        }
        if ($statusOnly && !$json) {
            throw new \RuntimeException('onboard status requires --format=json');
        }
        $configureDatabase = count(array_filter(
            $init,
            static fn(mixed $arg): bool => $arg === DockerDatabaseSetup::CONFIGURE_FLAG
        ));
        $databaseServices = count(array_filter(
            $init,
            static fn(mixed $arg): bool => is_string($arg)
                && str_starts_with($arg, DockerDatabaseSetup::SERVICE_FLAG)
        ));
        if ($configureDatabase !== 0 || $databaseServices !== 0) {
            if ($configureDatabase !== 1 || $databaseServices !== 1) {
                throw new \RuntimeException(
                    '--configure-database and --database-service=<name> must each be supplied exactly once'
                );
            }
            if ($json && !in_array('--yes', $init, true)) {
                throw new \RuntimeException(
                    'machine-readable database setup requires explicit --configure-database, '
                        . '--database-service=<name>, and --yes confirmation'
                );
            }
            foreach ($init as $arg) {
                if (is_string($arg) && str_starts_with($arg, DockerDatabaseSetup::SERVICE_FLAG)) {
                    DockerDatabaseSetup::assertServiceName(substr($arg, strlen(DockerDatabaseSetup::SERVICE_FLAG)));
                }
            }
        }
        return [$init, $gitUrl, $handoffOnly, $json, $statusOnly];
    }

    private static function preflightHandoff(
        BoundedControlDriver $driver,
        string $workspace,
        string $gitUrl
    ): void {
        $local = self::preflightControllerHandoff($workspace, $gitUrl);
        if (trim($local['stdout']) !== '') {
            throw new \RuntimeException('Git remote is not empty; use a new empty remote for initial publication');
        }
        $q = static fn(string $value): string => escapeshellarg($value);
        $target = self::captureTargetRaw(
            $driver,
            'git ls-remote --refs ' . $q($gitUrl),
            self::TARGET_PROBE_TIMEOUT_MILLISECONDS,
            self::TARGET_PROBE_OUTPUT_LIMIT_BYTES
        );
        if ($target['exit'] !== 0) {
            throw new \RuntimeException('target cannot authenticate to the Git remote: ' . trim($target['stderr']));
        }
        if (trim($target['stdout']) !== '') {
            throw new \RuntimeException('Git remote became non-empty during target credential preflight');
        }
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private static function preflightControllerHandoff(string $workspace, string $gitUrl): array {
        self::assertLocalBoundary($workspace);
        self::assertLocalOrigin($workspace, $gitUrl);
        $local = self::runGitTransfer(['git', 'ls-remote', '--refs', $gitUrl]);
        if ($local['exit'] !== 0) {
            throw new \RuntimeException('controller cannot authenticate to the Git remote: ' . trim($local['stderr']));
        }
        return $local;
    }

    private static function publishAndCheckout(
        BoundedControlDriver $driver,
        string $workspace,
        string $gitUrl,
        ?array $expectedReceipt = null
    ): string {
        self::assertLocalBoundary($workspace);
        $initialReceipt = self::assertPristineLocalCheckout($workspace, $expectedReceipt);
        self::assertLocalOrigin($workspace, $gitUrl);
        $repo = $driver->repoPath();
        $q = static fn(string $value): string => escapeshellarg($value);
        $script = 'set -eu; repo=' . $q($repo) . '; url=' . $q($gitUrl) . '; '
            . 'test -d "$repo/.git"; '
            . 'branch=$(git -C "$repo" symbolic-ref --quiet --short HEAD) || '
            . '{ echo "target Git worktree has detached HEAD" >&2; exit 1; }; '
            . 'git check-ref-format --branch "$branch" >/dev/null; '
            . 'receipt_ref="refs/wprism/handoff/$branch"; '
            . 'origin_missing=0; if git -C "$repo" remote get-url origin >/dev/null 2>&1; then '
            . 'test "$(git -C "$repo" remote get-url --all origin)" = "$url" || '
            . '{ echo "target origin URL does not match --git-url" >&2; exit 1; }; '
            . 'test "$(git -C "$repo" remote get-url --push --all origin)" = "$url" || '
            . '{ echo "target origin push URL does not match --git-url" >&2; exit 1; }; '
            . 'else origin_missing=1; fi; '
            . 'if head=$(git -C "$repo" rev-parse --verify HEAD 2>/dev/null); then '
            . 'receipt=$(git -C "$repo" rev-parse --verify "$receipt_ref" 2>/dev/null) || '
            . '{ echo "target history is not an owned WPrism handoff revision" >&2; exit 1; }; '
            . 'test "$head" = "$receipt" || { echo "target handoff receipt does not match HEAD" >&2; exit 1; }; '
            . 'git -C "$repo" for-each-ref --format="%(refname) %(objectname)" | '
            . 'awk -v branch="$branch" -v head="$head" \''
            . '$1 == "refs/heads/" branch && $2 == head { next } '
            . '$1 == "refs/wprism/handoff/" branch && $2 == head { next } '
            . '$1 == "refs/remotes/origin/" branch && $2 == head { next } '
            . '{ exit 1 }\' || { echo "target Git refs exceed the owned handoff boundary" >&2; exit 1; }; '
            . 'test -z "$(git -C "$repo" status --porcelain -- .gitattributes .gitignore site.wprism.json code state media)" || '
            . '{ echo "target managed publication tree changed after its handoff receipt" >&2; exit 1; }; '
            . 'else '
            . 'test -z "$(git -C "$repo" for-each-ref --format="%(refname)")" || '
            . '{ echo "target repository has refs before initial publication" >&2; exit 1; }; '
            . 'test -z "$(git -C "$repo" ls-files)" || '
            . '{ echo "target index is not empty before initial publication" >&2; exit 1; }; '
            . 'git -C "$repo" add .gitignore site.wprism.json code state media; '
            . 'if test -f "$repo/.gitattributes" && test ! -L "$repo/.gitattributes"; then '
            . 'git -C "$repo" add .gitattributes; '
            . 'elif test -e "$repo/.gitattributes" || test -L "$repo/.gitattributes"; then '
            . 'echo "target .gitattributes is not a regular file" >&2; exit 1; fi; '
            . 'staged=$(git -C "$repo" diff --cached --name-only); '
            . 'while IFS= read -r path; do case "$path" in '
            . '.gitattributes|.gitignore|site.wprism.json|code/*|state/*|media/*) ;; '
            . '*) echo "target staged path exceeds the managed publication boundary: $path" >&2; exit 1 ;; esac; '
            . "done <<WPRISM_STAGED\n\$staged\nWPRISM_STAGED\n"
            . 'tree=$(git -C "$repo" write-tree); '
            . 'head=$(git -C "$repo" -c user.name=wprism -c user.email=wprism@example.test '
            . 'commit-tree "$tree" -m "wprism: initial managed baseline"); '
            . 'printf "start\\ncreate refs/heads/%s %s\\ncreate %s %s\\nprepare\\ncommit\\n" '
            . '"$branch" "$head" "$receipt_ref" "$head" | git -C "$repo" update-ref --stdin; fi; '
            . 'remote=$(git -C "$repo" ls-remote --refs "$url"); '
            . 'expected=$(printf "%s\\trefs/heads/%s" "$head" "$branch"); '
            . 'if [ -n "$remote" ] && [ "$remote" != "$expected" ]; then '
            . 'echo "Git remote contains refs outside the exact prior WPrism publication" >&2; exit 1; fi; '
            . 'git -C "$repo" push "$url" "$head:refs/heads/$branch"; '
            . 'if [ "$origin_missing" -eq 1 ]; then git -C "$repo" remote add origin "$url"; fi; '
            . 'git -C "$repo" fetch origin "$branch"; '
            . 'git -C "$repo" branch --set-upstream-to="origin/$branch" "$branch" >/dev/null; '
            . 'printf "WPRISM_HANDOFF %s %s\\n" "$branch" "$head"';
        $target = self::captureTargetRaw(
            $driver,
            $script,
            self::GIT_TRANSFER_TIMEOUT_MILLISECONDS,
            self::GIT_TRANSFER_OUTPUT_LIMIT_BYTES
        );
        if ($target['exit'] !== 0) {
            throw new \RuntimeException('target Git publish failed: ' . trim($target['stderr']));
        }
        if (preg_match('/^WPRISM_HANDOFF ([^\s]+) ([a-f0-9]{40})$/m', $target['stdout'], $published) !== 1) {
            throw new \RuntimeException('target Git publish returned no branch/revision receipt');
        }
        $branch = $published[1];
        $revision = $published[2];

        self::assertPristineLocalCheckout($workspace, $initialReceipt);
        self::ensureLocalOrigin($workspace, $gitUrl);
        $remoteRef = 'refs/remotes/origin/' . $branch;
        $fetch = self::runGitTransfer([
            'git', '-C', $workspace, 'fetch', '--no-tags', 'origin',
            '+refs/heads/' . $branch . ':' . $remoteRef,
        ]);
        if ($fetch['exit'] !== 0) {
            throw new \RuntimeException('local Git fetch failed: ' . trim($fetch['stderr']));
        }
        $fetched = HostProcess::run(['git', '-C', $workspace, 'rev-parse', '--verify', $remoteRef]);
        if ($fetched['exit'] !== 0 || trim($fetched['stdout']) !== $revision) {
            throw new \RuntimeException('fetched branch moved after the target publication receipt; local boundary was not changed');
        }

        self::assertLocalBoundary($workspace);
        self::assertPristineLocalCheckout($workspace, $initialReceipt);
        $token = bin2hex(random_bytes(16));
        $backups = [
            $workspace . '/site.wprism.json' => $workspace . '/.git/wprism-handoff-site-' . $token,
            $workspace . '/.gitignore' => $workspace . '/.git/wprism-handoff-ignore-' . $token,
        ];
        $moved = [];
        $checkoutSucceeded = false;
        try {
            foreach ($backups as $path => $backup) {
                if (!rename($path, $backup)) {
                    throw new \RuntimeException("could not stage local generated boundary $path");
                }
                $moved[$path] = $backup;
            }
            $checkout = self::runGitTransfer(
                ['git', '-C', $workspace, 'checkout', '-b', $branch, '--track', $remoteRef]
            );
            if ($checkout['exit'] !== 0) {
                throw new \RuntimeException('local Git checkout failed: ' . trim($checkout['stderr']));
            }
            $checkoutSucceeded = true;
            $head = HostProcess::run(['git', '-C', $workspace, 'rev-parse', 'HEAD']);
            if ($head['exit'] !== 0 || trim($head['stdout']) !== $revision) {
                throw new \RuntimeException('local checkout does not match the target publication receipt');
            }
            if ($initialReceipt['wprism'] !== null
                && self::localTreeReceipt($workspace . '/.wprism') !== $initialReceipt['wprism']) {
                throw new \RuntimeException('local assessment artifacts changed during checkout');
            }
        } catch (\Throwable $error) {
            if ($checkoutSucceeded) {
                throw new \RuntimeException(
                    $error->getMessage() . '; checkout changed after publication; generated-boundary backups were retained in .git'
                );
            }
            try {
                self::restoreGeneratedBoundary($workspace, $moved);
            } catch (\Throwable $rollback) {
                throw new \RuntimeException(
                    $error->getMessage() . '; generated-boundary restoration paused: ' . $rollback->getMessage()
                );
            }
            throw $error;
        }
        foreach ($backups as $backup) {
            if (is_file($backup) && !is_link($backup) && !unlink($backup)) {
                throw new \RuntimeException("checkout succeeded but generated-boundary backup cleanup failed: $backup");
            }
        }
        return $branch;
    }

    private static function assertLocalBoundary(string $workspace): void {
        self::assertGeneratedFile($workspace . '/site.wprism.json', Adopt::repositorySeedBytes());
        self::assertGeneratedFile($workspace . '/.gitignore', Adopt::repositoryGitignoreBytes());
        if (file_exists($workspace . '/.gitattributes') || is_link($workspace . '/.gitattributes')) {
            throw new \RuntimeException('local .gitattributes appeared before the target baseline checkout');
        }
    }

    /**
     * @param ?array{branch:string,paths:array<string,array<string,mixed>>,wprism:?array<int,array<string,mixed>>} $expected
     * @return array{branch:string,paths:array<string,array<string,mixed>>,wprism:?array<int,array<string,mixed>>}
     */
    private static function assertPristineLocalCheckout(string $workspace, ?array $expected = null): array {
        if (is_link($workspace . '/.git') || !is_dir($workspace . '/.git')
            || is_link($workspace . '/.wprism-envs.json') || !is_file($workspace . '/.wprism-envs.json')) {
            throw new \RuntimeException('local workspace control boundary is not ordinary');
        }
        $entries = array_values(array_diff(scandir($workspace) ?: [], ['.', '..']));
        sort($entries, SORT_STRING);
        $allowed = ['.git', '.gitignore', '.wprism-envs.json', 'site.wprism.json'];
        if (in_array('.wprism', $entries, true)) {
            $allowed[] = '.wprism';
            sort($allowed, SORT_STRING);
            if (is_link($workspace . '/.wprism') || !is_dir($workspace . '/.wprism')) {
                throw new \RuntimeException('local assessment artifact boundary is not an ordinary directory');
            }
            $wprismEntries = array_values(array_diff(scandir($workspace . '/.wprism') ?: [], ['.', '..']));
            if ($wprismEntries !== ['contract'] || is_link($workspace . '/.wprism/contract')
                || !is_dir($workspace . '/.wprism/contract')) {
                throw new \RuntimeException('local .wprism boundary contains artifacts outside the assessment contract directory');
            }
        }
        if ($entries !== $allowed) {
            throw new \RuntimeException('local workspace changed before handoff; expected only connect and assessment artifacts');
        }
        $head = HostProcess::run(['git', '-C', $workspace, 'rev-parse', '--verify', 'HEAD']);
        $heads = HostProcess::run(['git', '-C', $workspace, 'for-each-ref', '--format=%(refname)', 'refs/heads']);
        $index = HostProcess::run(['git', '-C', $workspace, 'ls-files']);
        $branch = HostProcess::run(['git', '-C', $workspace, 'symbolic-ref', '--quiet', '--short', 'HEAD']);
        if ($head['exit'] === 0 || $heads['exit'] !== 0 || trim($heads['stdout']) !== ''
            || $index['exit'] !== 0 || trim($index['stdout']) !== ''
            || $branch['exit'] !== 0 || trim($branch['stdout']) === '') {
            throw new \RuntimeException('local workspace Git state is no longer the empty connect boundary');
        }
        $paths = [];
        foreach (['.wprism-envs.json', '.git', '.gitignore', 'site.wprism.json'] as $relative) {
            $paths[$relative] = self::localPathIdentity($workspace . '/' . $relative);
        }
        // Init owns this file on the target. Before checkout, its exact local
        // identity is absence; allowing controller bytes here would let them
        // shadow the target-owned publication during the handoff round trip.
        $paths['.gitattributes'] = ['type' => 'absent'];
        ksort($paths, SORT_STRING);
        $receipt = [
            'branch' => trim($branch['stdout']),
            'paths' => $paths,
            'wprism' => in_array('.wprism', $entries, true) ? self::localTreeReceipt($workspace . '/.wprism') : null,
        ];
        if ($expected !== null && $receipt !== $expected) {
            throw new \RuntimeException('local workspace changed during target publication; local boundary was not moved');
        }
        return $receipt;
    }

    /**
     * Assessment may add exactly one ignored `.wprism/contract` tree. The
     * connect-created registry, Git boundary, seeds, and branch remain the
     * authority captured before target code is installed or bootstrapped.
     *
     * @param array{branch:string,paths:array<string,array<string,mixed>>,wprism:?array<int,array<string,mixed>>} $before
     * @return array{branch:string,paths:array<string,array<string,mixed>>,wprism:?array<int,array<string,mixed>>}
     */
    private static function assessmentReceipt(string $workspace, array $before): array {
        $after = self::assertPristineLocalCheckout($workspace);
        if ($after['branch'] !== $before['branch'] || $after['paths'] !== $before['paths']
            || ($before['wprism'] !== null && $after['wprism'] !== $before['wprism'])) {
            throw new \RuntimeException('local connect authority changed during adoption or assessment');
        }
        return $after;
    }

    /** @param array<string,string> $backups */
    private static function restoreGeneratedBoundary(string $workspace, array $backups): void {
        foreach ($backups as $path => $backup) {
            if (file_exists($path) || is_link($path)) {
                throw new \RuntimeException("local checkout wrote $path; it and backup $backup were retained");
            }
            if (!is_file($backup) || is_link($backup) || !rename($backup, $path)) {
                throw new \RuntimeException("could not restore generated boundary: $path");
            }
        }
        self::assertLocalBoundary($workspace);
    }

    /** @return array{dev:string,ino:string,type:string,size:string,sha256:?string} */
    private static function localPathIdentity(string $path): array {
        $stat = lstat($path);
        if (!is_array($stat) || is_link($path) || (!is_dir($path) && !is_file($path))) {
            throw new \RuntimeException("local retained path is not ordinary: $path");
        }
        return [
            'dev' => (string) $stat['dev'],
            'ino' => (string) $stat['ino'],
            'type' => is_dir($path) ? 'directory' : 'file',
            'size' => is_file($path) ? (string) $stat['size'] : '0',
            'sha256' => is_file($path) ? hash_file('sha256', $path) : null,
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function localTreeReceipt(string $root): array {
        $rows = [['path' => '.', 'identity' => self::localPathIdentity($root)]];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
                throw new \RuntimeException("local assessment artifact is not ordinary: $path");
            }
            $rows[] = [
                'path' => substr($path, strlen($root) + 1),
                'identity' => self::localPathIdentity($path),
            ];
        }
        usort($rows, static fn(array $left, array $right): int => $left['path'] <=> $right['path']);
        return $rows;
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private static function runGitTransfer(array $argv): array {
        return HostProcess::run(
            $argv,
            null,
            [],
            false,
            self::GIT_TRANSFER_TIMEOUT_MILLISECONDS,
            self::GIT_TRANSFER_OUTPUT_LIMIT_BYTES
        );
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private static function captureTargetRaw(
        BoundedControlDriver $driver,
        string $script,
        int $timeoutMilliseconds,
        int $outputLimitBytes
    ): array {
        return $driver->captureRawBounded(
            $script,
            $timeoutMilliseconds,
            $outputLimitBytes,
            $outputLimitBytes
        );
    }

    private static function assertLocalOrigin(string $workspace, string $gitUrl): void {
        $origin = HostProcess::run(['git', '-C', $workspace, 'remote', 'get-url', 'origin']);
        if ($origin['exit'] !== 0) {
            if ($origin['exit'] === 2) {
                return;
            }
            throw new \RuntimeException('could not inspect the local origin: ' . trim($origin['stderr']));
        }
        foreach ([
            ['git', '-C', $workspace, 'remote', 'get-url', '--all', 'origin'],
            ['git', '-C', $workspace, 'remote', 'get-url', '--push', '--all', 'origin'],
        ] as $command) {
            $urls = HostProcess::run($command);
            if ($urls['exit'] !== 0 || self::urlLines($urls['stdout']) !== [$gitUrl]) {
                throw new \RuntimeException('local origin fetch/push URLs do not match --git-url');
            }
        }
    }

    private static function ensureLocalOrigin(string $workspace, string $gitUrl): void {
        $origin = HostProcess::run(['git', '-C', $workspace, 'remote', 'get-url', 'origin']);
        if ($origin['exit'] === 0) {
            self::assertLocalOrigin($workspace, $gitUrl);
            return;
        }
        if ($origin['exit'] !== 2) {
            throw new \RuntimeException('could not inspect the local origin: ' . trim($origin['stderr']));
        }
        $add = HostProcess::run(['git', '-C', $workspace, 'remote', 'add', 'origin', $gitUrl]);
        if ($add['exit'] !== 0) {
            throw new \RuntimeException('could not configure local origin: ' . trim($add['stderr']));
        }
        self::assertLocalOrigin($workspace, $gitUrl);
    }

    /** @return list<string> */
    private static function urlLines(string $stdout): array {
        $trimmed = trim($stdout);
        return $trimmed === '' ? [] : (preg_split('/\R/', $trimmed) ?: []);
    }

    private static function renderHandoffSuccess(
        string $sourceRoot,
        EnvironmentDriver $driver,
        string $branch
    ): void {
        $cli = realpath($sourceRoot . '/cli/wprism') ?: $sourceRoot . '/cli/wprism';
        echo "Published the initialized target baseline and checked out branch $branch in this workspace.\n";
        echo "Next: inspect the initialized target through the configured environment:\n";
        echo '  ' . escapeshellarg($cli) . ' assess ' . escapeshellarg($driver->name()) . "\n";
        echo "Capture always writes to the target repo_path ({$driver->repoPath()}), not this local checkout.\n";
        echo "Before feature-branch capture, point or materialize the target to that branch; see docs/guides/daily-workflow.md.\n";
        echo "Disposable preview creation additionally requires two configured environments and providers; see docs/guides/release.md.\n";
    }

    /**
     * Re-observe the durable handoff without publishing or changing target,
     * local, or remote Git state. This is also the reconciliation path after
     * a controller loses the success response from the mutating composition.
     *
     * @return array<string,mixed>
     */
    private static function handoffDocument(
        BoundedControlDriver $driver,
        string $workspace,
        string $gitUrl
    ): array {
        $repository = self::handoffRepositoryFacts($driver, $workspace, $gitUrl);
        $targetId = TargetOperationStore::readIdentity($driver);
        [$assessment, $contract] = self::contractEvidence($workspace, $driver->name());
        $authority = self::authorityPolicyEvidence($driver);
        $nextAction = $contract['status'] === 'proposed'
            ? 'review_application_contract'
            : ($authority['status'] === 'absent' ? 'enroll_operation_authority' : 'prepare_release');

        return OnboardingHandoffReceipt::build([
            'application_contract' => $contract,
            'assessment' => $assessment,
            'authority_policy' => $authority,
            'branch' => $repository['branch'],
            'commit' => $repository['commit'],
            'created_at' => $repository['created_at'],
            'driver' => $driver->driverId(),
            'environment' => $driver->name(),
            'environment_config_sha256' => self::environmentConfigDigest($workspace),
            'git_url' => $gitUrl,
            'next_action' => $nextAction,
            'repo_path' => $driver->repoPath(),
            'target_id' => $targetId,
            'tree' => $repository['tree'],
        ]);
    }

    /** @return array{branch:string,commit:string,tree:string,created_at:string} */
    private static function handoffRepositoryFacts(
        BoundedControlDriver $driver,
        string $workspace,
        string $gitUrl
    ): array {
        self::assertLocalOrigin($workspace, $gitUrl);
        $local = [];
        foreach ([
            'branch' => ['git', '-C', $workspace, 'symbolic-ref', '--quiet', '--short', 'HEAD'],
            'commit' => ['git', '-C', $workspace, 'rev-parse', '--verify', 'HEAD'],
            'tree' => ['git', '-C', $workspace, 'rev-parse', '--verify', 'HEAD^{tree}'],
            'created' => ['git', '-C', $workspace, 'show', '-s', '--format=%ct', 'HEAD'],
            'status' => ['git', '-C', $workspace, 'status', '--porcelain', '--', '.gitattributes', '.gitignore', 'site.wprism.json', 'code', 'state', 'media'],
        ] as $name => $argv) {
            $result = HostProcess::run($argv);
            if ($result['exit'] !== 0 || ($name !== 'status' && trim($result['stdout']) === '')) {
                throw new \RuntimeException("local handoff $name is unavailable");
            }
            $local[$name] = trim($result['stdout']);
        }
        if ($local['status'] !== ''
            || preg_match('/^[^\s]+$/D', $local['branch']) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $local['commit']) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $local['tree']) !== 1
            || preg_match('/^[0-9]{1,12}$/D', $local['created']) !== 1) {
            throw new \RuntimeException('local handoff repository state is not an exact clean publication');
        }
        $remote = self::runGitTransfer([
            'git', 'ls-remote', '--refs', $gitUrl, 'refs/heads/' . $local['branch'],
        ]);
        $expectedRemote = $local['commit'] . "\trefs/heads/" . $local['branch'];
        if ($remote['exit'] !== 0 || trim($remote['stdout']) !== $expectedRemote) {
            throw new \RuntimeException('the handoff remote no longer binds the local branch revision');
        }

        $repo = $driver->repoPath();
        $q = static fn(string $value): string => escapeshellarg($value);
        $script = 'set -eu; repo=' . $q($repo) . '; url=' . $q($gitUrl) . '; '
            . 'branch=$(git -C "$repo" symbolic-ref --quiet --short HEAD); '
            . 'git check-ref-format --branch "$branch" >/dev/null; '
            . 'head=$(git -C "$repo" rev-parse --verify HEAD); '
            . 'tree=$(git -C "$repo" rev-parse --verify "HEAD^{tree}"); '
            . 'created=$(git -C "$repo" show -s --format=%ct HEAD); '
            . 'receipt=$(git -C "$repo" rev-parse --verify "refs/wprism/handoff/$branch"); '
            . 'test "$head" = "$receipt"; '
            . 'test -z "$(git -C "$repo" status --porcelain -- .gitattributes .gitignore site.wprism.json code state media)"; '
            . 'test "$(git -C "$repo" remote get-url --all origin)" = "$url"; '
            . 'test "$(git -C "$repo" remote get-url --push --all origin)" = "$url"; '
            . 'remote=$(git -C "$repo" ls-remote --refs "$url" "refs/heads/$branch"); '
            . 'test "$remote" = "$(printf "%s\\trefs/heads/%s" "$head" "$branch")"; '
            . 'printf "WPRISM_HANDOFF_STATUS %s %s %s %s\\n" "$branch" "$head" "$tree" "$created"';
        $target = self::captureTargetRaw(
            $driver,
            $script,
            self::TARGET_PROBE_TIMEOUT_MILLISECONDS,
            self::TARGET_PROBE_OUTPUT_LIMIT_BYTES
        );
        if ($target['exit'] !== 0
            || preg_match(
                '/^WPRISM_HANDOFF_STATUS ([^\s]+) ([a-f0-9]{40}) ([a-f0-9]{40}) ([0-9]{1,12})$/D',
                trim($target['stdout']),
                $match
            ) !== 1) {
            throw new \RuntimeException('the target handoff repository no longer matches its durable publication receipt');
        }
        if ([$match[1], $match[2], $match[3], $match[4]]
            !== [$local['branch'], $local['commit'], $local['tree'], $local['created']]) {
            throw new \RuntimeException('local, remote, and target handoff identities disagree');
        }

        return [
            'branch' => $local['branch'],
            'commit' => $local['commit'],
            'tree' => $local['tree'],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $local['created']),
        ];
    }

    private static function environmentConfigDigest(string $workspace): string {
        $path = $workspace . '/.wprism-envs.json';
        $stat = lstat($path);
        $bytes = is_array($stat) && !is_link($path) && is_file($path)
            ? file_get_contents($path)
            : false;
        if (!is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('the machine-local environment registry is unavailable');
        }
        return 'sha256:' . hash('sha256', $bytes);
    }

    /**
     * @return array{0:array{assess_digest:string,format:string,generated_at:string,review_required_count:int},1:array{contract_digest:string,status:string}}
     */
    private static function contractEvidence(string $workspace, string $environment): array {
        $store = new ContractStore($workspace);
        $proposal = $store->readProposal($environment);
        if ($proposal === null) {
            throw new \RuntimeException('the onboarding assessment proposal is unavailable');
        }
        ContractProposal::validateProposal($proposal);
        $proposedContract = is_array($proposal['contract'] ?? null) ? $proposal['contract'] : [];
        $proposedDigest = $proposedContract['contract_digest'] ?? null;
        if (!is_string($proposedDigest)) {
            throw new \RuntimeException('the onboarding contract proposal has no digest');
        }
        $accepted = $store->readContract();
        $contract = $accepted === null
            ? ['contract_digest' => $proposedDigest, 'status' => 'proposed']
            : [
                'contract_digest' => (string) $accepted['contract_digest'],
                'status' => ($accepted['attestation']['state'] ?? null) === 'signed' ? 'attested' : 'accepted',
            ];
        return [[
            'assess_digest' => (string) $proposal['assess_digest'],
            'format' => ContractProposal::ASSESS_REPORT_FORMAT,
            'generated_at' => (string) $proposal['generated_at'],
            'review_required_count' => (int) $proposal['review_required_count'],
        ], $contract];
    }

    /** @return array{policy_digest:?string,status:string} */
    private static function authorityPolicyEvidence(EnvironmentDriver $driver): array {
        try {
            $policy = TargetOperationStore::readAuthorityPolicy($driver);
            return [
                'policy_digest' => OperationAuthorization::trustDigest($policy),
                'status' => 'enrolled',
            ];
        } catch (CommandRefusalException $refusal) {
            if ($refusal->reasonCode !== 'target_authority_policy_unavailable') {
                throw $refusal;
            }
            return ['policy_digest' => null, 'status' => 'absent'];
        }
    }

    private static function step(bool $quiet, callable $run): int {
        if (!$quiet) {
            return $run();
        }
        ob_start();
        try {
            return $run();
        } finally {
            ob_end_clean();
        }
    }

    private static function failure(
        \Throwable $error,
        bool $json,
        string $reasonCode,
        string $message,
        ?string $remediation = null
    ): int {
        if ($json) {
            fwrite(STDERR, 'wprism: onboard: ' . $error->getMessage() . "\n");
            return AssessCommand::renderRefusal(new CommandRefusalException(
                $reasonCode,
                $message,
                $remediation
                    ?? 'inspect private operator diagnostics, repair the named boundary, then reconcile with onboard <env> status --git-url=<same-url> --format=json',
                [],
                $error->getMessage(),
                $error
            ), true, 'onboard');
        }
        fwrite(STDERR, 'wprism: onboard: ' . $error->getMessage() . "\n");
        return 1;
    }

    private static function renderHandoffResume(string $sourceRoot, EnvironmentDriver $driver): void {
        fwrite(STDERR, self::handoffResumeMessage($sourceRoot, $driver));
    }

    private static function handoffResumeMessage(string $sourceRoot, EnvironmentDriver $driver): string {
        $cli = realpath($sourceRoot . '/cli/wprism') ?: $sourceRoot . '/cli/wprism';
        return "WPrism installation and initialization completed. Resume only Git publication after correcting the failure:\n"
            . '  ' . escapeshellarg($cli) . ' onboard ' . escapeshellarg($driver->name())
            . " --handoff-only --git-url=<same-remote-url>\n";
    }

    private static function assertGeneratedFile(string $path, string $expected): void {
        if (is_link($path) || !is_file($path) || file_get_contents($path) !== $expected) {
            throw new \RuntimeException("local generated boundary changed; refusing to replace $path");
        }
    }

}
