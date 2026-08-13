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
     * @param null|callable():string|false $readStdin
     */
    public static function runEnvSet(
        EnvironmentDriver $driver,
        array $extra,
        ?callable $stdinIsTty = null,
        ?callable $setTerminalEcho = null,
        ?callable $readStdin = null
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
            if (function_exists('posix_isatty')) {
                return @posix_isatty(STDIN);
            }
            $stat = @fstat(STDIN);
            if (is_array($stat)) {
                // POSIX S_IFMT/S_IFCHR: unlike a pipe or regular redirect, a
                // terminal is a character device. This keeps secret input
                // fail-closed when both PHP convenience APIs are disabled.
                return self::streamStatIsCharacterDevice($stat);
            }
            // Unknown is not evidence of a safe non-interactive pipe.
            return true;
        };
        if (!$stdinIsTty()) {
            return self::run($driver, 'env-set', $extra);
        }
        if (!function_exists('proc_open')
            || !function_exists('proc_get_status')
            || !function_exists('proc_close')
            || !function_exists('stream_set_blocking')) {
            return self::scopeWireRefusal(
                'env-set',
                $extra,
                'stdin_isolation_unavailable',
                'this PHP host cannot isolate interactive secret input from the terminal',
                'enable PHP process/stream functions or pipe the value from a trusted non-interactive source, then retry'
            );
        }
        $readStdin ??= static function (): string|false {
            fwrite(STDERR, 'value: ');
            try {
                return fgets(STDIN);
            } finally {
                // Enter was not visible while echo was disabled.
                fwrite(STDERR, "\n");
            }
        };

        $setTerminalEcho ??= static function (bool $enabled): bool {
            if (PHP_OS_FAMILY === 'Windows' || !function_exists('exec')) {
                return false;
            }
            $output = [];
            $exitCode = 1;
            exec($enabled ? 'stty echo 2>/dev/null' : 'stty -echo 2>/dev/null', $output, $exitCode);
            return $exitCode === 0;
        };
        $echoMasked = false;
        $inputActive = false;
        $handoffActive = false;
        $deferredSignal = null;
        $restoreSignalHandlers = self::installTerminalEchoSignalGuards(
            $setTerminalEcho,
            $echoMasked,
            $inputActive,
            $handoffActive,
            $deferredSignal
        );
        if ($restoreSignalHandlers === null) {
            return self::scopeWireRefusal(
                'env-set',
                $extra,
                'stdin_signal_guard_unavailable',
                'the local terminal cannot guarantee echo restoration after an interrupt',
                'enable PHP pcntl signal support or pipe the value from a trusted non-interactive source, then retry'
            );
        }
        // Mark the intended state before touching the terminal. An async
        // signal can then restore safely even in the narrow interval between
        // stty changing the device and this call returning to PHP.
        $inputActive = true;
        $echoMasked = true;
        if (!$setTerminalEcho(false)) {
            $inputActive = false;
            $echoMasked = !$setTerminalEcho(true);
            $restoreSignalHandlers();
            return self::scopeWireRefusal(
                'env-set',
                $extra,
                'stdin_masking_unavailable',
                'the local terminal could not disable echo for secret input',
                'repair the local terminal or pipe the value from a trusted non-interactive source, then retry'
            );
        }

        register_shutdown_function(static function () use (&$echoMasked, $setTerminalEcho): void {
            if ($echoMasked) {
                $setTerminalEcho(true);
            }
        });
        $restored = false;
        $line = false;
        $readCompleted = false;
        try {
            // Read before resolving or starting the target. An unavailable
            // Docker/SSH/WP target can therefore never strand the operator in
            // a local, echo-masked prompt after it has already failed.
            $line = $readStdin();
            $readCompleted = true;
        } finally {
            $inputActive = false;
            $restored = $setTerminalEcho(true);
            $echoMasked = !$restored;
            if (!$readCompleted) {
                $restoreSignalHandlers();
            }
            if (!$restored) {
                fwrite(
                    STDERR,
                    "duo: env-set: terminal echo could not be restored; run `stty echo` now\n"
                );
            }
        }
        if (!$restored) {
            $restoreSignalHandlers();
            return 2;
        }
        if (!is_string($line) || !str_ends_with($line, "\n")) {
            $restoreSignalHandlers();
            return self::scopeWireRefusal(
                'env-set',
                $extra,
                'invalid_arguments',
                'env-set did not receive one complete line from stdin',
                'provide one newline-terminated value and rerun env-set'
            );
        }
        try {
            $exitCode = self::streamWpInput(
                $driver,
                array_merge(['duo', 'env-set', '--repo=' . $driver->repoPath()], $extra),
                $line,
                $handoffActive
            );
        } finally {
            $handoffActive = false;
            $restoreSignalHandlers();
        }
        if (is_int($deferredSignal)) {
            fwrite(
                STDERR,
                "duo: env-set: target completed with exit $exitCode after deferred signal $deferredSignal\n"
            );
        }
        return $exitCode;
    }

    /** @param array<string,mixed> $stat */
    private static function streamStatIsCharacterDevice(array $stat): bool {
        return isset($stat['mode'])
            && ((((int) $stat['mode']) & 0170000) === 0020000);
    }

    /**
     * Start the target only after the host has completed its masked read and
     * restored the terminal. The target receives a pipe—never the operator's
     * terminal—as stdin.
     */
    private static function streamWpInput(
        EnvironmentDriver $driver,
        array $wpArgs,
        string $input,
        bool &$handoffActive
    ): int {
        // Resolve the target instruction while ordinary cancellation remains
        // available. The commit boundary begins immediately before spawn,
        // closing the async orphan window around proc_open itself.
        $instruction = $driver->wpInstruction($wpArgs);
        $handoffActive = true;
        $proc = proc_open(
            $instruction,
            [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR],
            $pipes
        );
        if (!is_resource($proc)) {
            return 255;
        }

        $complete = false;
        $observedExit = null;
        try {
            // A blocking pipe write can hide PHP's async signal handlers while
            // Docker/SSH/WP is slow or never reads. Nonblocking writes keep
            // local TERM/TSTP handling live throughout the handoff.
            $writeReady = @stream_set_blocking($pipes[0], false);
            $offset = 0;
            $length = strlen($input);
            while ($writeReady && $offset < $length) {
                $written = @fwrite($pipes[0], substr($input, $offset));
                if ($written === false) {
                    break;
                }
                if ($written > 0) {
                    $offset += $written;
                    continue;
                }
                $status = proc_get_status($proc);
                if (($status['running'] ?? false) !== true) {
                    $observedExit = self::processExitCode($status);
                    break;
                }
                usleep(10000);
            }
            $complete = $offset === $length;
        } finally {
            fclose($pipes[0]);
            // Do not disappear into a blocking proc_close(): PHP async signal
            // handlers are not guaranteed to run while that syscall waits.
            // Polling keeps Ctrl-Z/termination handling live after handoff.
            while ($observedExit === null) {
                $status = proc_get_status($proc);
                if (($status['running'] ?? false) !== true) {
                    $observedExit = self::processExitCode($status);
                    break;
                }
                usleep(10000);
            }
            $closedExit = proc_close($proc);
            $exitCode = $observedExit ?? $closedExit;
        }
        // If the target has already failed, preserve its actionable exit code
        // even when its closed pipe prevented the whole value from being
        // written. Exit 255 is reserved for a short write whose target
        // otherwise claimed success.
        return !$complete && $exitCode === 0 ? 255 : $exitCode;
    }

    /** @param array<string,mixed> $status */
    private static function processExitCode(array $status): ?int {
        $candidate = $status['exitcode'] ?? -1;
        if (is_int($candidate) && $candidate >= 0) {
            return $candidate;
        }
        if (($status['signaled'] ?? false) === true
            && is_int($status['termsig'] ?? null)
            && $status['termsig'] > 0) {
            return 128 + $status['termsig'];
        }
        return null;
    }

    /**
     * Install temporary async handlers while local terminal echo is masked.
     * Normal/default termination exits with the conventional 128+signal code
     * only after echo restoration. A pre-existing ignore/callable handler is
     * allowed to continue only when the terminal can be masked again.
     *
     * @param callable(bool):bool $setTerminalEcho
     * @return null|callable():void Null means safe signal coverage is absent.
     */
    private static function installTerminalEchoSignalGuards(
        callable $setTerminalEcho,
        bool &$echoMasked,
        bool &$inputActive,
        bool &$handoffActive = false,
        ?int &$deferredSignal = null
    ): ?callable {
        foreach (['pcntl_async_signals', 'pcntl_signal', 'pcntl_signal_get_handler', 'posix_kill'] as $function) {
            if (!function_exists($function)) {
                return null;
            }
        }
        $signals = [];
        foreach (['SIGHUP', 'SIGINT', 'SIGQUIT', 'SIGTERM', 'SIGTSTP'] as $constant) {
            if (!defined($constant)) {
                return null;
            }
            $signals[] = constant($constant);
        }
        if (!defined('SIGSTOP')) {
            return null;
        }

        $previousAsync = pcntl_async_signals();
        $previousHandlers = [];
        try {
            pcntl_async_signals(true);
            foreach (array_values(array_unique($signals)) as $signal) {
                $previous = pcntl_signal_get_handler($signal);
                $previousHandlers[$signal] = $previous;
                $handler = self::terminalEchoSignalHandler(
                    $setTerminalEcho,
                    $echoMasked,
                    $inputActive,
                    $handoffActive,
                    $deferredSignal,
                    $previous
                );
                // The guarded interval covers the host read and the target
                // outcome; its behavior changes at the explicit handoff.
                $installed = pcntl_signal($signal, $handler, false);
                if (!$installed) {
                    throw new \RuntimeException('could not install terminal signal guard');
                }
            }
        } catch (\Throwable $_failure) {
            foreach ($previousHandlers as $signal => $handler) {
                @pcntl_signal($signal, $handler);
            }
            pcntl_async_signals($previousAsync);
            return null;
        }

        return static function () use ($previousHandlers, $previousAsync): void {
            foreach ($previousHandlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            pcntl_async_signals($previousAsync);
        };
    }

    /**
     * @param callable(bool):bool $setTerminalEcho
     * @param mixed $previous
     */
    private static function terminalEchoSignalHandler(
        callable $setTerminalEcho,
        bool &$echoMasked,
        bool &$inputActive,
        bool &$handoffActive,
        ?int &$deferredSignal,
        mixed $previous
    ): callable {
        $handler = null;
        $handler = static function (int $caught) use (
            &$handler,
            &$echoMasked,
            &$inputActive,
            &$handoffActive,
            &$deferredSignal,
            $setTerminalEcho,
            $previous
        ): void {
            if ($echoMasked || $inputActive) {
                $restored = $setTerminalEcho(true);
                $echoMasked = !$restored;
            } else {
                $restored = true;
            }
            if (!$restored) {
                fwrite(
                    STDERR,
                    "duo: env-set: terminal echo could not be restored; run `stty echo` now\n"
                );
                exit(128 + $caught);
            }

            if ($handoffActive && defined('SIGTSTP') && $caught === constant('SIGTSTP')) {
                fwrite(
                    STDERR,
                    "duo: env-set: suspension received after secret handoff; suspending the local wait; the target may continue\n"
                );
                // Foreground Ctrl-Z stops the local child/transport too. Stop
                // the wrapper so shell job control owns one coherent local
                // job. A non-PTY Docker/SSH target may continue remotely; fg
                // resumes the local outcome wait without claiming otherwise.
                if (posix_kill(getmypid(), constant('SIGSTOP'))) {
                    return;
                }
                fwrite(
                    STDERR,
                    "duo: env-set: could not preserve suspension after secret handoff; waiting for the target outcome\n"
                );
                return;
            }

            if ($handoffActive) {
                if ($deferredSignal === null) {
                    $deferredSignal = $caught;
                    fwrite(
                        STDERR,
                        "duo: env-set: signal $caught received after secret handoff; waiting for the target outcome\n"
                    );
                }
                return;
            }

            $ignored = defined('SIG_IGN') && $previous === constant('SIG_IGN');
            if ($ignored || is_callable($previous)) {
                if (is_callable($previous)) {
                    $previous($caught);
                }
                self::resumeMaskedInput($caught, $setTerminalEcho, $echoMasked, $inputActive);
                return;
            }

            if (defined('SIGTSTP') && $caught === constant('SIGTSTP')) {
                // SIGTSTP can be discarded for an orphaned process group.
                // SIGSTOP guarantees the same user-visible suspension after
                // echo has been restored, then execution resumes after fg.
                if (defined('SIGSTOP') && posix_kill(getmypid(), constant('SIGSTOP'))) {
                    // Execution continues here after SIGCONT/fg.
                    self::resumeMaskedInput($caught, $setTerminalEcho, $echoMasked, $inputActive);
                    return;
                }
            }
            exit(128 + $caught);
        };
        return $handler;
    }

    /** @param callable(bool):bool $setTerminalEcho */
    private static function resumeMaskedInput(
        int $caught,
        callable $setTerminalEcho,
        bool &$echoMasked,
        bool &$inputActive
    ): void {
        if (!$inputActive) {
            return;
        }
        $echoMasked = true;
        if ($setTerminalEcho(false)) {
            return;
        }
        $echoMasked = !$setTerminalEcho(true);
        fwrite(
            STDERR,
            "duo: env-set: terminal echo could not be disabled again after an interrupt; secret input was stopped\n"
        );
        exit(128 + $caught);
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
