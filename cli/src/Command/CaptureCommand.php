<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/HostProcess.php';
require_once __DIR__ . '/PassthroughCommand.php';

/** Host command handler for capture's local --scope-contract flag handling. */
final class CaptureCommand {
    public static function run(
        EnvironmentDriver $driver,
        array $extra,
        ?string $envsFileOverride = null,
        ?callable $currentBranch = null
    ): int {
        $forward = [];
        $contractPath = null;
        $targetBranch = null;
        $writesRepository = true;
        foreach ($extra as $arg) {
            if (!is_string($arg) || PassthroughCommand::isHostOwnedTargetFlag($arg)) {
                return self::scopeRefusal(
                    $extra,
                    'invalid_arguments',
                    'capture received a host-owned target binding argument',
                    'remove --repo/--path; the selected environment supplies both bindings'
                );
            }
            if ($arg === '--orchestrator-environment'
                || str_starts_with($arg, '--orchestrator-environment=')) {
                return self::scopeRefusal(
                    $extra,
                    'invalid_arguments',
                    'capture received an orchestrator-reserved presentation argument',
                    'remove --orchestrator-environment and retry capture'
                );
            }
            if ($arg === '--expected-repository-branch'
                || str_starts_with($arg, '--expected-repository-branch=')) {
                return self::scopeRefusal(
                    $extra,
                    'invalid_arguments',
                    'capture received an orchestrator-reserved branch binding',
                    'remove --expected-repository-branch and use --target-branch=<name> when an explicit destination is required'
                );
            }
            if ($arg === '--target-branch' || (str_starts_with($arg, '--target-branch')
                && !str_starts_with($arg, '--target-branch='))) {
                return self::scopeRefusal(
                    $extra,
                    'invalid_arguments',
                    'capture received a malformed target branch flag',
                    'supply exactly --target-branch=<named-branch>'
                );
            }
            if (str_starts_with($arg, '--target-branch=')) {
                if ($targetBranch !== null) {
                    return self::scopeRefusal(
                        $extra,
                        'invalid_arguments',
                        'capture received more than one target branch',
                        'supply exactly one --target-branch=<named-branch>'
                    );
                }
                $targetBranch = substr($arg, strlen('--target-branch='));
                continue;
            }
            if (str_starts_with($arg, '--scope-request-b64')) {
                return self::scopeRefusal(
                    $extra,
                    'invalid_arguments',
                    'capture received an orchestrator-reserved scope argument',
                    'remove the internal scope argument and supply only --scope-contract=<local-path>'
                );
            }
            if ($arg === '--scope-contract' || (str_starts_with($arg, '--scope-contract')
                && !str_starts_with($arg, '--scope-contract='))) {
                return self::scopeRefusal(
                    $extra,
                    'invalid_arguments',
                    'capture received a malformed scope contract flag',
                    'supply exactly --scope-contract=<local-path>'
                );
            }
            if (str_starts_with($arg, '--scope-contract=')) {
                if ($contractPath !== null) {
                    return self::scopeRefusal(
                        $extra,
                        'invalid_arguments',
                        'capture received more than one scope contract',
                        'supply exactly one --scope-contract=<local-path>'
                    );
                }
                $contractPath = substr($arg, strlen('--scope-contract='));
                if ($contractPath === '') {
                    return self::scopeRefusal(
                        $extra,
                        'invalid_arguments',
                        'capture received an empty scope contract path',
                        'supply one readable canonical contract with --scope-contract=<local-path>'
                    );
                }
                continue;
            }
            if ($arg === '--out' || str_starts_with($arg, '--out=')) {
                $writesRepository = false;
            }
            $forward[] = $arg;
        }
        if ($contractPath !== null) {
            try {
                $input = PassthroughCommand::readScopeContractInput($contractPath);
                $forward[] = '--scope-request-b64=' . $input['request_b64'];
            } catch (\Throwable $e) {
                return self::scopeRefusal(
                    $extra,
                    'scope_contract_invalid',
                    'the local scope contract is malformed, tampered, or unsupported',
                    'generate a fresh contract with duo scope --contract and retry capture'
                );
            }
        }
        // An explicit registry path is private host state. Keep it out of the
        // target command and render the exact follow-up on the host instead.
        if ($envsFileOverride === null) {
            $forward[] = '--orchestrator-environment=' . $driver->name();
        }
        if ($writesRepository) {
            if ($targetBranch === null) {
                $currentBranch ??= static function (): ?string {
                    $cwd = getcwd();
                    if (!is_string($cwd) || $cwd === '') return null;
                    $result = HostProcess::run(['git', '-C', $cwd, 'symbolic-ref', '--quiet', '--short', 'HEAD']);
                    return $result['exit'] === 0 ? trim($result['stdout']) : null;
                };
                $targetBranch = $currentBranch();
            }
            if (!is_string($targetBranch) || !self::validBranch($targetBranch)) {
                return self::scopeRefusal(
                    $extra,
                    'capture_branch_unresolved',
                    'capture cannot bind its repository write to a valid named branch',
                    'run from the intended named checkout or supply --target-branch=<named-branch>'
                );
            }
            $forward[] = '--expected-repository-branch=' . $targetBranch;
        }
        return $driver->streamWp(
            array_merge(['duo', 'capture', '--repo=' . $driver->repoPath()], $forward)
        );
    }

    private static function validBranch(string $branch): bool {
        if ($branch === '' || strlen($branch) > 255 || str_contains($branch, "\0")
            || str_contains($branch, "\n") || str_contains($branch, "\r")) {
            return false;
        }
        $result = HostProcess::run(['git', 'check-ref-format', '--branch', $branch]);
        return $result['exit'] === 0 && trim($result['stdout']) === $branch;
    }

    private static function scopeRefusal(
        array $extra,
        string $reasonCode,
        string $message,
        string $remediation
    ): int {
        if (CommandOutput::wantsAgentRefusalJson('capture', $extra)) {
            return CommandOutput::renderRefusalJson('capture', $reasonCode, $message, $remediation);
        }
        fwrite(STDERR, "duo: capture: $message; $remediation\n");
        return 2;
    }
}
