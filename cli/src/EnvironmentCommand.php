<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/Registry.php';
require_once __DIR__ . '/Environment/EnvironmentRegistry.php';
require_once __DIR__ . '/Environment/MaterializationRequest.php';
require_once __DIR__ . '/Environment/MaterializationCoordinator.php';
require_once __DIR__ . '/Environment/ReapCoordinator.php';
require_once __DIR__ . '/Environment/LifecycleResultProjector.php';
require_once __DIR__ . '/EnvironmentDriver.php';
require_once __DIR__ . '/EnvironmentLifecycle.php';
require_once __DIR__ . '/EnvironmentCommandOptions.php';
require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/Transport.php';
require_once __DIR__ . '/LocalTransport.php';
require_once __DIR__ . '/DockerTransport.php';
require_once __DIR__ . '/SshTransport.php';

/**
 * Host command boundary for branch-environment materialization and exact reap.
 *
 * Option grammar, provider evidence, journal recovery, and semantic
 * materialization remain separate contracts. This handler binds those public
 * command inputs in their existing order and accepts the promotion handoff as
 * an explicit callback, so environment provisioning does not learn the
 * monolithic promote command family.
 */
final class EnvironmentCommand {
    /**
     * @param list<string> $args
     * @param callable(EnvironmentDriver,array<string,mixed>):array<string,mixed>|int $promote
     */
    public static function run(array $args, ?string $envsFileOverride, callable $promote): int {
        if (count($args) < 2) {
            fwrite(STDERR, "duo: env requires materialize|reap and a target <env>\n");
            return 1;
        }
        $action = array_shift($args);
        $targetName = array_shift($args);
        if (!in_array($action, ['materialize', 'reap'], true)) {
            fwrite(STDERR, "duo: env: unknown action '$action' (expected materialize or reap)\n");
            return 1;
        }
        try {
            // Parse the complete public intent before registry/provider/journal
            // construction. Caller mistakes never execute a privileged provider
            // or even create operational state.
            $options = $action === 'materialize' ? EnvironmentCommandOptions::materialize($args) : null;
            $reapJson = $action === 'reap' ? EnvironmentCommandOptions::reap($args) : false;
            if ($action === 'materialize') {
                $json = is_array($options) && ($options['json'] ?? false) === true;
                if ($json) {
                    return CommandOutput::renderRefusalJson(
                        'env materialize',
                        'environment_materialization_containment_unproved',
                        'environment materialization is unavailable until snapshot data, credentials, egress, retention, and deletion are independently contained',
                        'use an approved synthetic fixture after the engineering platform installs the reviewed harness verifier'
                    );
                }
                fwrite(
                    STDERR,
                    "duo: env materialize refused: production-derived snapshot containment is not yet proven\n"
                );
                return 1;
            }
            $registry = EnvironmentRegistry::load($envsFileOverride, getcwd() ?: '.');
            // Compatibility anchors: Registry::load( remains the parser
            // authority while the typed repository facade is introduced;
            // EnvironmentMaterializer::materialize( and
            // EnvironmentMaterializer::reap( are now delegated by the
            // named coordinators below.
            $targetConfig = $registry->get($targetName);
            $targetDriver = Transport::make($targetName, $targetConfig);
            $targetProvider = CommandEnvironmentProvider::fromEnvironment($targetName, $targetConfig);
            $journal = self::journal();

            if ($action === 'reap') {
                $sourceProvider = null;
                $latest = $journal->latestForTarget($targetName);
                $sourceName = $latest === null ? null : self::reapSourceName($latest);
                if ($sourceName !== null) {
                    $sourceConfig = $registry->get($sourceName);
                    $sourceProvider = CommandEnvironmentProvider::fromEnvironment($sourceName, $sourceConfig);
                }
                $receipt = ReapCoordinator::run(
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
            $sourceConfig = $registry->get($sourceName);
            $sourceDriver = Transport::make($sourceName, $sourceConfig);
            $sourceProvider = CommandEnvironmentProvider::fromEnvironment($sourceName, $sourceConfig);
            $receipt = MaterializationCoordinator::run(
                $sourceDriver,
                $targetDriver,
                $sourceProvider,
                $targetProvider,
                $journal,
                MaterializationRequest::fromOptions($options),
                $promote
            );
            self::renderReceipt($receipt, $options['json'], 'materialize');
            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, "duo: env $action: {$e->getMessage()}\n");
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
        return new EnvironmentLifecycleJournal(rtrim(trim($stdout), '/') . '/duo-environments');
    }

    /** @param array<string,mixed> $receipt */
    public static function renderReceipt(array $receipt, bool $json, string $action): void {
        LifecycleResultProjector::render($receipt, $json, $action);
    }
}
