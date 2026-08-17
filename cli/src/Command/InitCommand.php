<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Onboarding/Init.php';

/** Host command handler for the digest-bound initialization workflow. */
final class InitCommand {
    /**
     * Run init's host orchestration while leaving proposal/confirmation
     * validation and target protocol ownership in Init.
     *
     * @param list<string> $extra
     * @param callable(array):void $renderRefusal
     * @param callable(EnvironmentDriver):int $statusRunner
     * @param callable():mixed $readLine
     */
    public static function run(
        EnvironmentDriver $transport,
        array $extra,
        callable $renderRefusal,
        callable $statusRunner,
        callable $readLine
    ): int {
        $yes = false;
        foreach ($extra as $arg) {
            if ($arg === '--yes') {
                $yes = true;
                continue;
            }
            fwrite(STDERR, "duo: init accepts only --yes; unsupported argument '$arg'\n");
            return 1;
        }

        try {
            $proposal = Init::proposal($transport);
        } catch (InitRefusalException $e) {
            fwrite(STDERR, 'duo: ' . $e->getMessage() . "\n");
            $renderRefusal($e->refusal);
            return 1;
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains(strtolower($message), 'not a registered wp command')
                || str_contains(strtolower($message), 'duo is not a registered')) {
                $message .= "; install the Duo agent first (run 'duo adopt {$transport->name()}' for an SSH or explicitly authorized local environment)";
            }
            fwrite(STDERR, "duo: $message\n");
            return 1;
        }

        foreach (Init::render($proposal) as $line) {
            echo $line . "\n";
        }
        if (empty($proposal['ready'])) {
            return 2;
        }
        $digest = (string) ($proposal['digest'] ?? '');
        if (!$yes) {
            fwrite(STDOUT, "Initialize '{$transport->name()}' from proposal $digest? [y/N] ");
            $answer = $readLine();
            if (!is_string($answer) || !in_array(strtolower(trim($answer)), ['y', 'yes'], true)) {
                echo "Initialization cancelled; no configuration, state, identity, or ledger mutation was made.\n";
                return 1;
            }
        }

        try {
            $result = Init::confirm($transport, $digest);
        } catch (InitRefusalException $e) {
            fwrite(STDERR, 'duo: ' . $e->getMessage() . "\n");
            $renderRefusal($e->refusal);
            return 1;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'duo: ' . $e->getMessage() . "\n");
            return 1;
        }

        $baseline = $result['baseline'] ?? [];
        if (($result['recovery'] ?? null) === 'committed-finalized') {
            echo "Verified the interrupted committed init and cleared its sealed recovery journal.\n";
            echo 'Recovered canonical state baseline ' . ($baseline['revision_hash'] ?? '(unknown)') . ".\n";
            echo 'Recovered separate code baseline ' . ($result['code']['revision_hash'] ?? '(unknown)') . ".\n";
        } else {
            echo 'Initialized canonical state baseline ' . ($baseline['revision_hash'] ?? '(unknown)') . ".\n";
            echo 'Initialized separate code baseline ' . ($result['code']['revision_hash'] ?? '(unknown)') . ".\n";
        }
        echo ($baseline['rollback_note'] ?? 'This is a state baseline, not a code-and-database rollback checkpoint.') . "\n";
        echo "Verifying selected managed scope:\n";
        $status = $statusRunner($transport);
        if ($status !== 0) {
            fwrite(STDERR, "duo: initialization captured a baseline, but the selected managed scope is not clean\n");
            return $status;
        }
        foreach (Init::nextSteps($transport->name(), (string) ($result['state']['repository'] ?? $transport->repoPath())) as $line) {
            echo $line . "\n";
        }
        return 0;
    }
}
