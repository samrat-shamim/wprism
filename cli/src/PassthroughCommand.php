<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/EnvironmentDriver.php';
require_once dirname(__DIR__, 2) . '/agent/src/Canon.php';
require_once dirname(__DIR__, 2) . '/agent/src/ScopeContract.php';

/**
 * Host forwarding boundary for the ordinary and scoped agent commands.
 *
 * The host owns only the local scope-envelope validation and the exact
 * transport argv. Target semantics remain in the agent; this class never
 * bootstraps WordPress or interprets a target response.
 */
final class PassthroughCommand {
    public static function run(EnvironmentDriver $driver, string $verb, array $extra): int {
        if (in_array($verb, ['plan', 'apply'], true)) {
            return self::runScoped($driver, $verb, $extra);
        }
        return $driver->streamWp(array_merge(['duo', $verb, '--repo=' . $driver->repoPath()], $extra));
    }

    public static function runScoped(EnvironmentDriver $driver, string $verb, array $extra): int {
        $forward = [];
        $contractPath = null;
        $hasPlanView = false;
        foreach ($extra as $arg) {
            if (!is_string($arg)) {
                return self::scopeWireRefusal(
                    $verb,
                    $extra,
                    'invalid_arguments',
                    "$verb received a malformed scope argument",
                    'supply exactly --scope-contract=<local-path>'
                );
            }
            if (self::isScopeRequestWireFlag($arg)) {
                return self::scopeWireRefusal(
                    $verb,
                    $extra,
                    'invalid_arguments',
                    "$verb received an orchestrator-reserved scope argument",
                    'remove the internal scope argument and supply only --scope-contract=<local-path>'
                );
            }
            if ($arg === '--scope-contract' || (str_starts_with($arg, '--scope-contract')
                && !str_starts_with($arg, '--scope-contract='))) {
                return self::scopeWireRefusal(
                    $verb,
                    $extra,
                    'invalid_arguments',
                    "$verb received a malformed scope contract flag",
                    'supply exactly --scope-contract=<local-path>'
                );
            }
            if (str_starts_with($arg, '--scope-contract=')) {
                if ($contractPath !== null) {
                    return self::scopeWireRefusal(
                        $verb,
                        $extra,
                        'invalid_arguments',
                        "$verb received more than one scope contract",
                        'supply exactly one --scope-contract=<local-path>'
                    );
                }
                $contractPath = substr($arg, strlen('--scope-contract='));
                if ($contractPath === '') {
                    return self::scopeWireRefusal(
                        $verb,
                        $extra,
                        'invalid_arguments',
                        "$verb received an empty scope contract path",
                        'supply one readable canonical contract with --scope-contract=<local-path>'
                    );
                }
                continue;
            }
            if ($verb === 'plan' && self::isPlanViewFlag($arg)) {
                $hasPlanView = true;
            }
            $forward[] = $arg;
        }
        if ($contractPath !== null && $verb === 'plan' && $hasPlanView) {
            return self::scopeWireRefusal(
                $verb,
                $extra,
                'plan_view_unavailable',
                'the requested plan view is unavailable for a scoped plan',
                'rerun the scoped plan without view filters; its closed scoped projection is already bounded to the selected contract'
            );
        }
        if ($contractPath !== null) {
            try {
                $input = self::readScopeContractInput($contractPath);
                $forward[] = '--scope-request-b64=' . $input['request_b64'];
            } catch (\Throwable $_failure) {
                return self::scopeWireRefusal(
                    $verb,
                    $extra,
                    'scope_contract_invalid',
                    'the local scope contract is malformed, tampered, or unsupported',
                    'generate a fresh contract with duo scope --contract and retry the command'
                );
            }
        }
        return $driver->streamWp(array_merge(['duo', $verb, '--repo=' . $driver->repoPath()], $forward));
    }

    public static function hasJsonFlag(array $extra): bool {
        foreach ($extra as $index => $arg) {
            if ($arg === '--json' || $arg === '--format=json'
                || ($arg === '--format' && ($extra[$index + 1] ?? null) === 'json')) {
                return true;
            }
        }
        return false;
    }

    public static function isScopeRequestWireFlag(string $arg): bool {
        return str_starts_with($arg, '--scope-request-b64');
    }

    public static function isScopeContractFlag(string $arg): bool {
        return str_starts_with($arg, '--scope-contract');
    }

    public static function scopeWireRefusal(
        string $verb,
        array $extra,
        string $reasonCode,
        string $message,
        string $remediation
    ): int {
        if (CommandOutput::wantsAgentRefusalJson($verb, $extra) || self::hasJsonFlag($extra)) {
            return CommandOutput::renderRefusalJson($verb, $reasonCode, $message, $remediation);
        }
        fwrite(STDERR, "duo: $verb: $message; $remediation\n");
        return 2;
    }

    public static function isPlanViewFlag(string $arg): bool {
        foreach (['category', 'action', 'entity', 'limit'] as $name) {
            if ($arg === "--$name" || str_starts_with($arg, "--$name=")) {
                return true;
            }
        }
        return false;
    }

    /** @return array{contract:array<string,mixed>,request_b64:string} */
    public static function readScopeContractInput(string $path): array {
        $bytes = @file_get_contents($path);
        if ($bytes === false || strlen($bytes) > 4 * 1024 * 1024) {
            throw new \RuntimeException('cannot read bounded local scope contract');
        }
        $decoded = \Duo\Canon::decode($bytes);
        if (!is_array($decoded)) {
            throw new \RuntimeException('scope contract must be one object');
        }
        $contract = \Duo\ScopeContract::from_array($decoded);
        $request = [
            'format' => 'duo-scope-request/v1',
            'scope_hash' => (string) $contract['scope_hash'],
            'selectors' => $contract['selectors'],
        ];
        return [
            'contract' => $contract,
            'request_b64' => base64_encode(\Duo\Canon::encode($request)),
        ];
    }
}
