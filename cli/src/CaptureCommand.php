<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/EnvironmentDriver.php';
require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/PassthroughCommand.php';

/** Host command handler for capture's local --scope-contract flag handling. */
final class CaptureCommand {
    public static function run(EnvironmentDriver $driver, array $extra): int {
        $forward = [];
        $contractPath = null;
        foreach ($extra as $arg) {
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
                \Duo\ScopeContract::assert_mutation_supported($input['contract'], 'scoped capture');
                $forward[] = '--scope-request-b64=' . $input['request_b64'];
            } catch (\Duo\ScopedOptionMutationUnsupported $_failure) {
                return self::scopeRefusal(
                    $extra,
                    'scoped_option_mutation_unsupported',
                    'per-option scope contracts are currently read-only evidence',
                    "select the whole 'options' surface for the existing scoped mutation protocol"
                );
            } catch (\Throwable $e) {
                return self::scopeRefusal(
                    $extra,
                    'scope_contract_invalid',
                    'the local scope contract is malformed, tampered, or unsupported',
                    'generate a fresh contract with duo scope --contract and retry capture'
                );
            }
        }
        return $driver->streamWp(array_merge(['duo', 'capture', '--repo=' . $driver->repoPath()], $forward));
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
