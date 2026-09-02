<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/../Environment/Registry.php';
require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Environment/EnvironmentLifecycle.php';
require_once __DIR__ . '/../Onboarding/Adopt.php';
require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/EnvironmentCommandOptions.php';
require_once __DIR__ . '/EnvironmentProviderCheckCommand.php';
require_once __DIR__ . '/../Transport/Transport.php';
require_once __DIR__ . '/../Transport/LocalTransport.php';
require_once __DIR__ . '/../Transport/DockerTransport.php';
require_once __DIR__ . '/../Transport/SshTransport.php';

/**
 * Host command boundary for branch-environment materialization and exact reap.
 *
 * Option grammar, provider evidence, journal recovery, and semantic
 * materialization remain separate contracts. This handler binds those public
 * command inputs in their existing order and accepts adoption/promotion
 * handoffs as explicit callbacks, so environment provisioning learns neither
 * how the WPrism distribution is installed nor the monolithic promote command
 * family.
 */
final class EnvironmentCommand {
    /**
     * @param list<string> $args
     * @param callable(EnvironmentDriver,array<string,mixed>):array<string,mixed>|int $promote
     * @param ?callable(array<string,mixed>):void $receiptObserver
     * @param ?array{distribution_sha256:string|callable():string,install:callable(EnvironmentDriver,array{operation_id:string,target_environment:string}):array{agent_version:string,distribution_sha256:string}} $targetBootstrap
     */
    public static function run(
        array $args,
        ?string $envsFileOverride,
        callable $promote,
        ?callable $receiptObserver = null,
        ?array $targetBootstrap = null
    ): int {
        if (count($args) < 2) {
            fwrite(STDERR, "wprism: env requires materialize|reap|provider-check and a target <env>\n");
            return 1;
        }
        $action = array_shift($args);
        $targetName = array_shift($args);
        if (!in_array($action, ['materialize', 'reap', 'provider-check'], true)) {
            fwrite(STDERR, "wprism: env: unknown action '$action' (expected materialize, reap or provider-check)\n");
            return 1;
        }
        $machineJson = in_array('--format=json', $args, true);
        // provider-check owns no journal, no promotion handoff and (in its
        // default tier) no mutation. It is deliberately dispatched before the
        // registry/provider/journal construction below, so an operator whose
        // provider config is the thing that is broken still gets a diagnosis
        // instead of the refusal that config produces everywhere else.
        if ($action === 'provider-check') {
            try {
                $options = EnvironmentCommandOptions::providerCheck($args);
            } catch (\Throwable $e) {
                fwrite(STDERR, "wprism: env provider-check: {$e->getMessage()}\n");
                return 1;
            }
            return EnvironmentProviderCheckCommand::run($targetName, $options, $envsFileOverride);
        }
        try {
            // Parse the complete public intent before registry/provider/journal
            // construction. Caller mistakes never execute a privileged provider
            // or even create operational state.
            $options = $action === 'materialize' ? EnvironmentCommandOptions::materialize($args) : null;
            $reapJson = $action === 'reap' ? EnvironmentCommandOptions::reap($args) : false;
            $envs = Registry::load($envsFileOverride, getcwd() ?: '.');
            $targetConfig = Registry::get($envs, $targetName);
            $targetDriver = Transport::make($targetName, $targetConfig);
            $targetProvider = CommandEnvironmentProvider::fromEnvironment($targetName, $targetConfig);
            $journal = self::journal();

            if ($action === 'reap') {
                $sourceProvider = null;
                $latest = $journal->latestForTarget($targetName);
                $sourceName = $latest === null ? null : self::reapSourceName($latest);
                if ($sourceName !== null) {
                    $sourceConfig = Registry::get($envs, $sourceName);
                    $sourceProvider = CommandEnvironmentProvider::fromEnvironment($sourceName, $sourceConfig);
                }
                $receipt = EnvironmentMaterializer::reap(
                    $targetDriver,
                    $targetProvider,
                    $journal,
                    $sourceProvider
                );
                self::renderReceipt($receipt, $reapJson, 'reap');
                return 0;
            }

            if (!is_array($options)) throw new \RuntimeException('materialize intent is missing');
            $sourceName = $options['source'];
            $sourceConfig = Registry::get($envs, $sourceName);
            $sourceDriver = Transport::make($sourceName, $sourceConfig);
            $sourceProvider = CommandEnvironmentProvider::fromEnvironment($sourceName, $sourceConfig);
            // The frozen promotion callback is an existing human-oriented
            // command surface and may print phase lines before returning or
            // refusing. `env materialize --format=json` owns a single-document
            // public contract, so contain that nested stdout at this boundary;
            // stderr remains untouched as private operator diagnostics. The
            // receipt or stable refusal is rendered only after the buffer is
            // gone, and therefore cannot be mixed with progress prose.
            $outputLevel = ob_get_level();
            if ($options['json']) {
                ob_start(static fn(string $_output): string => '');
            }
            try {
                $receipt = EnvironmentMaterializer::materialize(
                    $sourceDriver,
                    $targetDriver,
                    $sourceProvider,
                    $targetProvider,
                    $journal,
                    [
                        'branch' => $options['branch'],
                        'containment_required' => $options['containment_required'],
                        'create' => $options['create'],
                        'ttl_seconds' => $options['ttl_seconds'],
                    ],
                    $promote,
                    $targetDriver instanceof AdoptionTransport ? $targetBootstrap : null
                );
            } finally {
                if ($options['json']) {
                    while (ob_get_level() > $outputLevel) {
                        ob_end_clean();
                    }
                }
            }
            if ($receiptObserver !== null) {
                $receiptObserver($receipt);
            }
            self::renderReceipt($receipt, $options['json'], 'materialize');
            return 0;
        } catch (\Throwable $e) {
            if ($machineJson) {
                return CommandOutput::renderRefusalJson(
                    'env ' . $action,
                    'branch_environment_operation_failed',
                    'the branch environment operation refused at a safety or recovery gate',
                    'inspect private operator diagnostics, repair the condition, then retry the same operation for journal reconciliation'
                );
            }
            fwrite(STDERR, "wprism: env $action: {$e->getMessage()}\n");
            return 1;
        }
    }

    /**
     * Resolve the source provider only when pre-target cleanup can still need it.
     * A completed immutable snapshot or an acquired/acquiring target has no
     * source session for `reap` to abort.
     *
     * @param array{run:array<string,mixed>,events:list<array<string,mixed>>} $latest
     */
    public static function reapSourceName(array $latest): ?string {
        $events = [];
        foreach ($latest['events'] as $event) {
            if (is_string($event['event'] ?? null)) $events[$event['event']] = true;
        }
        if (isset($events['target-acquired']) || isset($events['target-acquire-intent'])
            || isset($events['snapshot-read']) || isset($events['snapshot-aborted'])
            || (!isset($events['snapshot-prepared']) && !isset($events['snapshot-prepare-intent']))) {
            return null;
        }
        $source = $latest['run']['source_environment'] ?? null;
        if (!is_string($source) || $source === '') {
            throw new \RuntimeException('unfinished source snapshot has no journaled source environment');
        }
        return $source;
    }

    public static function journal(): EnvironmentLifecycleJournal {
        $process = proc_open(
            ['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            getcwd() ?: null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('could not resolve Git operational directory');
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || trim($stdout) === '') {
            throw new \RuntimeException('env materialize/reap must run inside a Git worktree');
        }
        return new EnvironmentLifecycleJournal(rtrim(trim($stdout), '/') . '/wprism-environments');
    }

    /**
     * Compose Onboarding's atomic install at the command surface. Providers
     * never receive WPrism source paths or agent bytes, and Environment's
     * engine receives only an immutable distribution pin and install receipt.
     *
     * @return array{distribution_sha256:callable():string,install:callable(EnvironmentDriver,array{operation_id:string,target_environment:string}):array{agent_version:string,distribution_sha256:string}}
     */
    public static function targetBootstrap(string $sourceRoot): array {
        $distributionSha256 = null;
        $pin = static function () use ($sourceRoot, &$distributionSha256): string {
            if (!is_string($distributionSha256)) {
                $distributionSha256 = Adopt::distributionDigest($sourceRoot);
            }
            return $distributionSha256;
        };
        $install = static function (EnvironmentDriver $driver, array $context) use ($sourceRoot, $pin): array {
            if (!$driver instanceof AdoptionTransport) {
                throw new \RuntimeException('target agent bootstrap requires an authorized adoption transport');
            }
            if (($context['target_environment'] ?? null) !== $driver->name()
                || !is_string($context['operation_id'] ?? null)
                || $context['operation_id'] === '') {
                throw new \RuntimeException('target agent bootstrap context is malformed');
            }
            $expectedDistribution = $pin();
            $result = Adopt::install($driver, $sourceRoot, null, null, null, null, null, $expectedDistribution);
            if (($result['exit'] ?? 1) !== 0) {
                $phase = is_string($result['phase'] ?? null) && $result['phase'] !== ''
                    ? $result['phase']
                    : 'unknown phase';
                throw new \RuntimeException("target agent bootstrap failed during $phase");
            }
            $version = $result['version'] ?? null;
            if (!is_string($version) || $version === '') {
                throw new \RuntimeException('target agent bootstrap completed without an agent version');
            }
            $installedDistribution = $result['distribution_sha256'] ?? null;
            if (!is_string($installedDistribution)
                || !hash_equals($expectedDistribution, $installedDistribution)) {
                throw new \RuntimeException('target agent bootstrap installed another distribution');
            }
            return [
                'agent_version' => $version,
                'distribution_sha256' => $installedDistribution,
            ];
        };
        return ['distribution_sha256' => $pin, 'install' => $install];
    }

    /** @param array<string,mixed> $receipt */
    public static function renderReceipt(array $receipt, bool $json, string $action): void {
        if ($json) {
            echo json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            return;
        }
        echo "environment $action complete: operation=" . ($receipt['operation_id'] ?? '?')
            . ' resource=' . ($receipt['resource_id'] ?? '?') . "\n";
        if ($action === 'materialize') {
            echo 'mode=' . ($receipt['mode'] ?? '?')
                . ' branch=' . ($receipt['branch_commit'] ?? '?')
                . ' snapshot=' . ($receipt['snapshot_set_id'] ?? '?') . "\n";
            echo 'release code=' . ($receipt['code_revision'] ?? '?')
                . ' state=' . ($receipt['state_revision'] ?? '?')
                . ' outer=' . ($receipt['outer_artifact_hash'] ?? '?') . "\n";
            echo 'url=' . ($receipt['url'] ?? '?')
                . ' expires_at=' . ($receipt['expires_at'] ?? 'none') . "\n";
        } else {
            echo 'disposition=' . ($receipt['disposition'] ?? '?')
                . ' absence_proof=' . ($receipt['absence_proof_sha256'] ?? '?') . "\n";
        }
        echo 'receipt=' . ($receipt['receipt_sha256'] ?? '?') . "\n";
    }
}
