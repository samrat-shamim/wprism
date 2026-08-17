<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/PassthroughCommand.php';

/** Host command handler for capture's local --scope-contract flag handling. */
final class CaptureCommand {
    public static function run(
        EnvironmentDriver $driver,
        array $extra,
        ?string $envsFileOverride = null
    ): int {
        $forward = [];
        $contractPath = null;
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
        return $driver->streamWp(
            array_merge(['duo', 'capture', '--repo=' . $driver->repoPath()], $forward)
        );
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
