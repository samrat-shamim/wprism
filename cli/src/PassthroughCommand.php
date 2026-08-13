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
        foreach ($extra as $arg) {
            if (!is_string($arg) || self::isHostOwnedTargetFlag($arg)) {
                return self::scopeWireRefusal(
                    $verb,
                    $extra,
                    'invalid_arguments',
                    "$verb received a host-owned target binding argument",
                    'remove --repo/--path; the selected environment supplies both bindings'
                );
            }
        }
        return $driver->streamWp(array_merge(['duo', $verb, '--repo=' . $driver->repoPath()], $extra));
    }

    /**
     * Preserve the ordinary env-set wire contract while masking the terminal
     * that actually owns an interactive operator's keyboard. Docker and SSH
     * intentionally run the target without a PTY, so target-side stty cannot
     * suppress echo on the host terminal. Piped input has no terminal echo and
     * follows the ordinary passthrough path unchanged.
     *
     * @param null|callable():bool $stdinIsTty
     * @param null|callable(bool):bool $setTerminalEcho
     */
    public static function runEnvSet(
        EnvironmentDriver $driver,
        array $extra,
        ?callable $stdinIsTty = null,
        ?callable $setTerminalEcho = null
    ): int {
        $hasStdin = false;
        foreach ($extra as $arg) {
            if (!is_string($arg) || self::isHostOwnedTargetFlag($arg)) {
                return self::run($driver, 'env-set', $extra);
            }
            if ($arg === '--stdin' || str_starts_with($arg, '--stdin=')) {
                $hasStdin = true;
            }
        }
        if (!$hasStdin) {
            return self::run($driver, 'env-set', $extra);
        }

        $stdinIsTty ??= static function (): bool {
            if (function_exists('stream_isatty')) {
                return @stream_isatty(STDIN);
            }
            return function_exists('posix_isatty') && @posix_isatty(STDIN);
        };
        if (!$stdinIsTty()) {
            return self::run($driver, 'env-set', $extra);
        }

        $setTerminalEcho ??= static function (bool $enabled): bool {
            if (PHP_OS_FAMILY === 'Windows' || !function_exists('exec')) {
                return false;
            }
            $output = [];
            $exitCode = 1;
            exec($enabled ? 'stty echo 2>/dev/null' : 'stty -echo 2>/dev/null', $output, $exitCode);
            return $exitCode === 0;
        };
        if (!$setTerminalEcho(false)) {
            return self::scopeWireRefusal(
                'env-set',
                $extra,
                'stdin_masking_unavailable',
                'the local terminal could not disable echo for secret input',
                'repair the local terminal or pipe the value from a trusted non-interactive source, then retry'
            );
        }

        $echoMasked = true;
        register_shutdown_function(static function () use (&$echoMasked, $setTerminalEcho): void {
            if ($echoMasked) {
                $setTerminalEcho(true);
            }
        });
        $exitCode = 1;
        $restored = false;
        try {
            $exitCode = self::run($driver, 'env-set', $extra);
        } finally {
            $restored = $setTerminalEcho(true);
            $echoMasked = !$restored;
            if (!$restored) {
                fwrite(
                    STDERR,
                    "duo: env-set: terminal echo could not be restored; run `stty echo` now\n"
                );
            }
        }
        return $restored ? $exitCode : 2;
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
            if (self::isHostOwnedTargetFlag($arg)) {
                return self::scopeWireRefusal(
                    $verb,
                    $extra,
                    'invalid_arguments',
                    "$verb received a host-owned target binding argument",
                    'remove --repo/--path; the selected environment supplies both bindings'
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
                \Duo\ScopeContract::assert_mutation_supported($input['contract'], 'scoped ' . $verb);
                $forward[] = '--scope-request-b64=' . $input['request_b64'];
            } catch (\Duo\ScopedOptionMutationUnsupported $_failure) {
                return self::scopeWireRefusal(
                    $verb,
                    $extra,
                    'scoped_option_mutation_unsupported',
                    'per-option scope contracts are currently read-only evidence',
                    "select the whole 'options' surface for the existing scoped mutation protocol"
                );
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

    /** Repository and WordPress roots come only from the selected environment. */
    public static function isHostOwnedTargetFlag(string $arg): bool {
        foreach (['--repo', '--path'] as $flag) {
            if ($arg === $flag || str_starts_with($arg, $flag . '=')) {
                return true;
            }
        }
        return false;
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
