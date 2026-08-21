<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/PassthroughCommand.php';
require_once __DIR__ . '/../Refresh/Refresh.php';
// DUO-3523: rendered here, so this file requires it. `require_once` and safe
// in every load order: cli/duo pulls CodeResolveCommand.php in with
// `require_once` too (cli/duo:98, and DeployCommand.php:43 before it), and the
// only dependency of that file which cli/duo plain-`require`s —
// EnvironmentDriver.php — is already loaded by cli/duo:17.
require_once __DIR__ . '/CodeResolveCommand.php';

/** Host parser/output boundary for refresh rebase and abort commands. */
final class RebaseCommand {
    /** @param list<string> $extra */
    public static function run(EnvironmentDriver $driver, array $extra): int {
        $fieldMode = count(array_filter($extra, static fn(string $arg): bool =>
            $arg === '--interactive' || str_starts_with($arg, '--interactive=')
            || str_starts_with($arg, '--field-resolution'))) > 0;
        $fieldFailure = [
            'message' => 'redacted field-level resolution is unavailable or stale for this refresh plan',
            'remediation' => 'verify the production target is clean and at --production-ref, then regenerate the matching redacted field diff and resolution; if field evidence remains unsupported, use the legacy whole-record resolver',
        ];
        if ($fieldMode && count(array_filter($extra, static fn(string $arg): bool => str_starts_with($arg, '--abort='))) > 0) {
            $fieldFailure = [
                'message' => 'refresh rebase abort accepts no field-resolution or interactive flags',
                'remediation' => 'retry --abort=<run-id> alone, without a field-resolution input',
            ];
        }
        try {
            $parsed = self::flags($extra);
            $flags = $parsed['flags'];
            if (isset($flags['--abort'])) {
                if (count($flags) !== 1 || $parsed['interactive'] || $parsed['strategy_seen']
                    || $parsed['resolution']['strategy'] !== 'manual' || $parsed['resolution']['records'] !== []) {
                    throw new \RuntimeException('duo rebase --abort=<run-id> accepts no production-ref or new-branch flags');
                }
                Refresh::abort($flags['--abort']);
                echo 'refresh rebase aborted: ' . $flags['--abort'] . "\n";
                return 0;
            }
            $fieldPath = isset($flags['--field-resolution']) ? $flags['--field-resolution'] : null;
            $fieldMode = $fieldPath !== null || $parsed['interactive'];
            if (($fieldPath !== null || $parsed['interactive'])
                && ($parsed['strategy_seen'] || $parsed['resolution']['strategy'] !== 'manual' || $parsed['resolution']['records'] !== [])) {
                $fieldFailure = [
                    'message' => 'redacted field-level resolution cannot be mixed with legacy --strategy or --resolve',
                    'remediation' => 'choose either the legacy whole-record resolver or the matching field-resolution contract',
                ];
                throw new \RuntimeException('duo rebase: --field-resolution/--interactive cannot be mixed with --strategy or --resolve');
            }
            if ($fieldPath !== null && $parsed['interactive']) {
                $fieldFailure = [
                    'message' => 'redacted field-level resolution accepts either a file or interactive input, not both',
                    'remediation' => 'supply one matching field-resolution input mode and retry',
                ];
                throw new \RuntimeException('duo rebase: --field-resolution and --interactive cannot be combined');
            }
            if (($fieldPath !== null || $parsed['interactive']) && isset($flags['--scope-contract'])) {
                $fieldFailure = [
                    'message' => 'redacted field-level resolution is unsupported for scoped refresh',
                    'remediation' => 'use the legacy whole-record resolver for this scope',
                ];
                throw new \RuntimeException('duo rebase: field-level resolution is unavailable for scoped refresh; use --strategy/--resolve');
            }
            $expected = (isset($flags['--scope-contract']) ? 3 : 2) + ($fieldPath !== null ? 1 : 0);
            if (count($flags) !== $expected || !isset($flags['--production-ref']) || !isset($flags['--new-branch'])) {
                throw new \RuntimeException('duo rebase requires --production-ref=<ref>, --new-branch=<name>, optional --scope-contract=<local-path>, and optional --field-resolution=<local-path> or --interactive');
            }
            if ($parsed['interactive'] && !self::interactiveTtyAvailable()) {
                $fieldFailure = [
                    'message' => 'redacted field-level interactive resolution requires TTY stdin and stdout',
                    'remediation' => 'run --interactive from an attached terminal; use --field-resolution=<local-path> for automation',
                ];
                throw new \RuntimeException('duo rebase: --interactive requires TTY stdin and stdout');
            }
            $scope = isset($flags['--scope-contract'])
                ? PassthroughCommand::readScopeContractInput($flags['--scope-contract'])['contract']
                : null;
            $result = Refresh::rebase($driver, $flags['--production-ref'], $flags['--new-branch'], $parsed['resolution'], $scope, $fieldPath, $parsed['interactive'], $parsed['strategy_seen']);
            CodeResolveCommand::renderRefreshPhase($result, 'rebase');
            echo 'refresh rebase complete: ' . $result['new_branch'] . ' at ' . $result['head'] . "\n";
            echo 'plan: ' . $result['plan_path'] . ' run: ' . $result['run_id'] . "\n";
            return 0;
        } catch (RefreshFieldResolutionCancelled) {
            fwrite(STDERR, "duo: rebase: field resolution cancelled; no run record, candidate worktree, branch, or ref was created\n");
            return 2;
        } catch (RefreshFieldResolutionRunFailed $e) {
            $runId = $e->runId();
            fwrite(STDERR, 'duo: rebase: redacted field-level resolution stopped after candidate setup; run_id=' . $runId . "\n");
            fwrite(STDERR, 'duo: rebase: remedy: inspect private local run evidence and the requested ref; duo rebase <env> --abort=' . $runId . " removes only the journal-owned worktree\n");
            return 1;
        } catch (\Throwable $e) {
            if ($fieldMode) {
                fwrite(STDERR, 'duo: rebase: ' . $fieldFailure['message'] . "\n");
                fwrite(STDERR, 'duo: rebase: remedy: ' . $fieldFailure['remediation'] . "\n");
                return 1;
            }
            fwrite(STDERR, 'duo: rebase: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    /** @return array{flags:array<string,string>,resolution:array{strategy:string,records:array<string,string>},strategy_seen:bool,interactive:bool} */
    private static function flags(array $extra): array {
        $flags = [];
        $records = [];
        $strategy = 'manual';
        $strategySeen = false;
        $interactive = false;
        foreach ($extra as $arg) {
            if ($arg === '--interactive') {
                if ($interactive) throw new \RuntimeException('duo rebase: duplicate --interactive');
                $interactive = true;
                continue;
            }
            if (!is_string($arg) || !str_starts_with($arg, '--') || !str_contains($arg, '=')) {
                throw new \RuntimeException('duo rebase: expected --interactive or --name=value flags');
            }
            [$name, $value] = explode('=', $arg, 2);
            if ($value === '') throw new \RuntimeException("duo rebase: empty flag '$arg'");
            if ($name === '--resolve') {
                $pos = strrpos($value, '=');
                if ($pos === false) throw new \RuntimeException('duo rebase: --resolve must be <stable-id>=ours|theirs');
                $id = substr($value, 0, $pos);
                $choice = substr($value, $pos + 1);
                if ($id === '' || !in_array($choice, ['ours', 'theirs'], true) || isset($records[$id])) {
                    throw new \RuntimeException('duo rebase: duplicate/invalid --resolve; use each stable id once with ours or theirs');
                }
                $records[$id] = $choice;
                continue;
            }
            if ($name === '--strategy') {
                if (!in_array($value, ['manual', 'ours', 'theirs'], true) || $strategySeen) {
                    throw new \RuntimeException('duo rebase: --strategy must occur once and be manual, ours, or theirs');
                }
                $strategy = $value;
                $strategySeen = true;
                continue;
            }
            if (!in_array($name, ['--production-ref', '--new-branch', '--abort', '--scope-contract', '--field-resolution'], true) || isset($flags[$name])) {
                throw new \RuntimeException("duo rebase: unsupported or duplicate flag '$arg'");
            }
            $flags[$name] = $value;
        }
        ksort($records, SORT_STRING);
        return ['flags' => $flags, 'resolution' => ['strategy' => $strategy, 'records' => $records], 'strategy_seen' => $strategySeen, 'interactive' => $interactive];
    }

    private static function interactiveTtyAvailable(): bool {
        if (function_exists('stream_isatty')) return @stream_isatty(STDIN) && @stream_isatty(STDOUT);
        if (function_exists('posix_isatty')) return @posix_isatty(STDIN) && @posix_isatty(STDOUT);
        return false;
    }
}
