<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Command/PassthroughCommand.php';
require_once __DIR__ . '/../../../../agent/src/Capture/Capture.php';

use WPrism\Capture;
use WPrism\Orchestrator\DriverCapabilityReport;
use WPrism\Orchestrator\EnvironmentDriver;
use WPrism\Orchestrator\PassthroughCommand;

function fail_passthrough(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

final class RecordingInputPassthroughDriver implements EnvironmentDriver {
    /** @var list<list<string>> */
    public array $calls = [];
    public string $inputPath;
    public bool $throwOnInstruction = false;
    public bool $exitBeforeRead = false;
    public ?string $blockAfterReadMarker = null;
    public ?string $delayBeforeReadMarker = null;
    /** @var null|callable():void */
    public $onInstruction = null;

    public function __construct() {
        $path = tempnam(sys_get_temp_dir(), 'wprism-input-fixture-');
        if (!is_string($path)) throw new RuntimeException('could not create input fixture');
        $this->inputPath = $path;
    }

    public function name(): string { return 'fixture'; }
    public function driverId(): string { return 'fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'input fixture driver'; }
    public function captureRaw(string $script): array { return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
    public function captureWp(array $wpArgs): array { return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
    public function streamWp(array $wpArgs): int {
        $this->calls[] = array_values(array_map('strval', $wpArgs));
        return 23;
    }
    public function wpInstruction(array $wpArgs): string {
        if ($this->throwOnInstruction) throw new RuntimeException('fixture transport failure');
        if (is_callable($this->onInstruction)) ($this->onInstruction)();
        $this->calls[] = array_values(array_map('strval', $wpArgs));
        if ($this->exitBeforeRead) {
            return escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('fwrite(STDERR, "target unavailable\\n"); exit(42);');
        }
        $script = '';
        if ($this->delayBeforeReadMarker !== null) {
            $script .= 'file_put_contents(' . var_export($this->delayBeforeReadMarker, true)
                . ', "target-started\\n"); usleep(800000); ';
        }
        $script .= '$v = stream_get_contents(STDIN); '
            . 'file_put_contents(' . var_export($this->inputPath, true) . ', $v); ';
        if ($this->blockAfterReadMarker !== null) {
            $script .= 'file_put_contents(' . var_export($this->blockAfterReadMarker, true)
                . ', "target-read\\n"); usleep(400000); ';
        }
        $script .= 'exit(23);';
        return escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script);
    }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('fixture', 'fixture', $operation, []);
    }
}

function assert_passthrough(bool $condition, string $message): void {
    if (!$condition) fail_passthrough($message);
}

final class RecordingPassthroughDriver implements EnvironmentDriver {
    /** @var list<list<string>> */
    public array $calls = [];
    public bool $throwOnStream = false;
    public ?string $blockOnStreamMarker = null;

    public function name(): string { return 'fixture'; }
    public function driverId(): string { return 'fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'fixture driver'; }
    public function captureRaw(string $script): array { return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
    public function captureWp(array $wpArgs): array { return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
    public function streamWp(array $wpArgs): int {
        $this->calls[] = array_values(array_map('strval', $wpArgs));
        if ($this->throwOnStream) {
            throw new RuntimeException('fixture transport failure');
        }
        if ($this->blockOnStreamMarker !== null) {
            file_put_contents($this->blockOnStreamMarker, "ready\n", FILE_APPEND | LOCK_EX);
            while (true) {
                usleep(10000);
            }
        }
        return 23;
    }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('fixture', 'fixture', $operation, []);
    }
}

$driver = new RecordingPassthroughDriver();

$streamStatMethod = new ReflectionMethod(PassthroughCommand::class, 'streamStatIsCharacterDevice');
assert_passthrough(
    $streamStatMethod->invoke(null, ['mode' => 0020600]) === true
        && $streamStatMethod->invoke(null, ['mode' => 0010600]) === false
        && $streamStatMethod->invoke(null, []) === false,
    'the no-isatty fallback distinguishes a terminal character device from a pipe or unknown mode'
);

$ordinary = PassthroughCommand::run($driver, 'capabilities', ['--format=json']);
assert_passthrough($ordinary === 23, 'ordinary passthrough preserves the transport exit code');
assert_passthrough(
    $driver->calls === [['wprism', 'capabilities', '--repo=/fixture/repo', '--format=json']],
    'ordinary passthrough preserves the exact agent argv and repo binding'
);

$lint = PassthroughCommand::run($driver, 'lint', ['--format=json']);
assert_passthrough($lint === 23, 'lint passthrough preserves the transport exit code');
assert_passthrough(
    $driver->calls[1] === ['wprism', 'lint', '--repo=/fixture/repo', '--format=json'],
    'lint passthrough preserves the exact agent argv and repo binding'
);

$envDriver = new RecordingInputPassthroughDriver();
$argvValueDriver = new RecordingInputPassthroughDriver();
ob_start();
$argvValueExit = PassthroughCommand::runEnvSet(
    $argvValueDriver,
    ['--name=gateway_secret', '--value=observable-secret', '--format=json']
);
$argvValueJson = (string) ob_get_clean();
$argvValueRecord = json_decode(trim($argvValueJson), true);
assert_passthrough(
    $argvValueExit === 1
        && ($argvValueRecord['error'] ?? null) === 'invalid_arguments'
        && str_contains((string) ($argvValueRecord['remediation'] ?? ''), 'pipe one newline-terminated value')
        && $argvValueDriver->calls === [],
    'host env-set refuses an argv value before target resolution'
);

$echoTransitions = [];
$inputLifecycle = [];
$envDriver->onInstruction = static function () use (&$inputLifecycle): void {
    $inputLifecycle[] = 'target';
};
$envSet = PassthroughCommand::runEnvSet(
    $envDriver,
    ['--name=gateway_secret', '--stdin'],
    static fn(): bool => true,
    static function (bool $enabled) use (&$echoTransitions): bool {
        $echoTransitions[] = $enabled;
        return true;
    },
    static function () use (&$inputLifecycle, &$echoTransitions, $envDriver): string {
        $inputLifecycle[] = 'read';
        assert_passthrough(
            $echoTransitions === [false] && $envDriver->calls === [],
            'interactive env-set reads while masked and before target resolution'
        );
        return "secret\n";
    }
);
assert_passthrough($envSet === 23, 'interactive env-set preserves the transport exit code');
assert_passthrough(
    $echoTransitions === [false, true]
        && $inputLifecycle === ['read', 'target']
        && $envDriver->calls === [[
            'wprism', 'env-set', '--repo=/fixture/repo', '--name=gateway_secret', '--stdin',
        ]]
        && file_get_contents($envDriver->inputPath) === "secret\n",
    'interactive env-set masks one host read and sends only that value through detached target stdin'
);

foreach ([false, 'partial-secret'] as $incompleteInput) {
    $incompleteDriver = new RecordingInputPassthroughDriver();
    $incompleteTransitions = [];
    $incompleteExit = PassthroughCommand::runEnvSet(
        $incompleteDriver,
        ['--name=gateway_secret', '--stdin'],
        static fn(): bool => true,
        static function (bool $enabled) use (&$incompleteTransitions): bool {
            $incompleteTransitions[] = $enabled;
            return true;
        },
        static fn(): string|false => $incompleteInput
    );
    assert_passthrough(
        $incompleteExit === 2
            && $incompleteTransitions === [false, true]
            && $incompleteDriver->calls === [],
        'EOF or an unterminated interactive value is rejected locally before target resolution'
    );
}

$failedTargetDriver = new RecordingInputPassthroughDriver();
$failedTargetDriver->exitBeforeRead = true;
$failedTargetTransitions = [];
$failedTargetLifecycle = [];
$failedTargetDriver->onInstruction = static function () use (&$failedTargetLifecycle): void {
    $failedTargetLifecycle[] = 'target';
};
$failedTarget = PassthroughCommand::runEnvSet(
    $failedTargetDriver,
    ['--name=gateway_secret', '--stdin'],
    static fn(): bool => true,
    static function (bool $enabled) use (&$failedTargetTransitions, &$failedTargetLifecycle): bool {
        $failedTargetTransitions[] = $enabled;
        $failedTargetLifecycle[] = $enabled ? 'echo-on' : 'echo-off';
        return true;
    },
    static function () use (&$failedTargetLifecycle): string {
        $failedTargetLifecycle[] = 'read';
        // Exceed a typical pipe buffer so the already-failed target closes
        // stdin before the host can complete the write.
        return str_repeat('s', 1024 * 1024) . "\n";
    }
);
assert_passthrough(
    $failedTarget === 42
        && $failedTargetTransitions === [false, true]
        && $failedTargetLifecycle === ['echo-off', 'read', 'echo-on', 'target'],
    'an unavailable target is started only after the host read and echo restoration, then returns its exact failure'
);

$pipedDriver = new RecordingInputPassthroughDriver();
$pipedEchoTouched = false;
$pipedEnvSet = PassthroughCommand::runEnvSet(
    $pipedDriver,
    ['--name=gateway_secret', '--stdin'],
    static fn(): bool => false,
    static function (bool $_enabled) use (&$pipedEchoTouched): bool {
        $pipedEchoTouched = true;
        return true;
    },
    static fn(): string => "piped-secret\n"
);
assert_passthrough(
    $pipedEnvSet === 23
        && !$pipedEchoTouched
        && count($pipedDriver->calls) === 1
        && file_get_contents($pipedDriver->inputPath) === "piped-secret\n",
    'piped env-set input needs no terminal mutation and uses the same bounded detached handoff'
);

$timeoutDriver = new RecordingInputPassthroughDriver();
$timeoutDriver->blockAfterReadMarker = tempnam(sys_get_temp_dir(), 'wprism-env-set-timeout-');
$timeoutClock = [0.0, 0.1, 0.2, 2.0];
$timeoutStarted = microtime(true);
$timeoutExit = PassthroughCommand::runEnvSet(
    $timeoutDriver,
    ['--name=gateway_secret', '--stdin'],
    static fn(): bool => false,
    static fn(bool $_enabled): bool => true,
    static fn(): string => "same-secret\n",
    static function () use (&$timeoutClock): float {
        return $timeoutClock === [] ? 2.0 : (float) array_shift($timeoutClock);
    },
    1.0
);
$timeoutElapsed = microtime(true) - $timeoutStarted;
@unlink((string) $timeoutDriver->blockAfterReadMarker);
assert_passthrough(
    $timeoutExit === 75 && $timeoutElapsed < 2.0,
    'a stuck post-handoff target returns bounded outcome-unknown exit 75 instead of occupying the worker forever'
);
$passthroughSource = (string) file_get_contents(__DIR__ . '/../../../../cli/src/Command/PassthroughCommand.php');
assert_passthrough(
    str_contains($passthroughSource, 'Retry the identical --stdin value: env-set is idempotent')
        && str_contains($passthroughSource, 'plan remains red until')
        && str_contains($passthroughSource, 'ENV_SET_OUTCOME_TIMEOUT_SECONDS = 300.0'),
    'timeout diagnosis names the safe idempotent retry and the production five-minute bound'
);

$malformedStdinDriver = new RecordingInputPassthroughDriver();
$malformedStdinTransitions = [];
$malformedStdin = PassthroughCommand::runEnvSet(
    $malformedStdinDriver,
    ['--name=gateway_secret', '--stdin=unexpected'],
    static fn(): bool => true,
    static function (bool $enabled) use (&$malformedStdinTransitions): bool {
        $malformedStdinTransitions[] = $enabled;
        return true;
    },
    static fn(): string => "secret\n"
);
assert_passthrough(
    $malformedStdin === 23 && $malformedStdinTransitions === [false, true],
    'option-shaped stdin input cannot bypass local masking before target validation'
);

$refusedEnvDriver = new RecordingInputPassthroughDriver();
ob_start();
$maskingRefusal = PassthroughCommand::runEnvSet(
    $refusedEnvDriver,
    ['--name=gateway_secret', '--stdin', '--format=json'],
    static fn(): bool => true,
    static fn(bool $enabled): bool => $enabled
);
$maskingJson = (string) ob_get_clean();
$maskingRecord = json_decode(trim($maskingJson), true);
assert_passthrough(
    $maskingRefusal === 1
        && ($maskingRecord['error'] ?? null) === 'stdin_masking_unavailable'
        && $refusedEnvDriver->calls === [],
    'interactive env-set fails closed before target contact when local echo cannot be disabled'
);

$throwingEnvDriver = new RecordingInputPassthroughDriver();
$throwingEnvDriver->throwOnInstruction = true;
$throwingEchoTransitions = [];
try {
    PassthroughCommand::runEnvSet(
        $throwingEnvDriver,
        ['--name=gateway_secret', '--stdin'],
        static fn(): bool => true,
        static function (bool $enabled) use (&$throwingEchoTransitions): bool {
            $throwingEchoTransitions[] = $enabled;
            return true;
        },
        static fn(): string => "secret\n"
    );
    fail_passthrough('throwing env-set transport should propagate its failure');
} catch (RuntimeException $failure) {
    assert_passthrough(
        $failure->getMessage() === 'fixture transport failure'
            && $throwingEchoTransitions === [false, true],
        'interactive env-set restores local echo when the transport throws'
    );
}

if (function_exists('pcntl_fork') && function_exists('pcntl_waitpid') && function_exists('posix_kill')) {
    $handoffMarker = tempnam(sys_get_temp_dir(), 'wprism-env-set-handoff-');
    assert_passthrough(is_string($handoffMarker), 'env-set handoff fixture is created');
    $handoffPid = pcntl_fork();
    assert_passthrough($handoffPid !== -1, 'env-set handoff fixture forks');
    if ($handoffPid === 0) {
        $handoffDriver = new RecordingInputPassthroughDriver();
        $handoffDriver->blockAfterReadMarker = $handoffMarker;
        exit(PassthroughCommand::runEnvSet(
            $handoffDriver,
            ['--name=gateway_secret', '--stdin'],
            static fn(): bool => true,
            static fn(bool $_enabled): bool => true,
            static fn(): string => "secret\n"
        ));
    }
    $targetRead = false;
    for ($attempt = 0; $attempt < 200; $attempt++) {
        if (str_contains((string) @file_get_contents($handoffMarker), "target-read\n")) {
            $targetRead = true;
            break;
        }
        usleep(10000);
    }
    assert_passthrough($targetRead, 'interactive env-set hands the complete value to its target');
    assert_passthrough(posix_kill($handoffPid, SIGTERM), 'post-handoff env-set receives SIGTERM');
    usleep(100000);
    $handoffStatus = 0;
    assert_passthrough(
        pcntl_waitpid($handoffPid, $handoffStatus, WNOHANG) === 0,
        'post-handoff termination waits for the target outcome instead of orphaning it'
    );
    pcntl_waitpid($handoffPid, $handoffStatus);
    @unlink($handoffMarker);
    assert_passthrough(
        pcntl_wifexited($handoffStatus) && pcntl_wexitstatus($handoffStatus) === 23,
        'post-handoff termination returns the exact completed target outcome'
    );

    if (function_exists('posix_setpgid')) {
        $slowWriteMarker = tempnam(sys_get_temp_dir(), 'wprism-env-set-slow-write-');
        assert_passthrough(is_string($slowWriteMarker), 'slow target-write fixture is created');
        $slowWritePid = pcntl_fork();
        assert_passthrough($slowWritePid !== -1, 'slow target-write fixture forks');
        if ($slowWritePid === 0) {
            if (!posix_setpgid(0, 0)) exit(91);
            $slowWriteDriver = new RecordingInputPassthroughDriver();
            $slowWriteDriver->delayBeforeReadMarker = $slowWriteMarker;
            exit(PassthroughCommand::runEnvSet(
                $slowWriteDriver,
                ['--name=gateway_secret', '--stdin'],
                static fn(): bool => true,
                static fn(bool $_enabled): bool => true,
                static fn(): string => str_repeat('s', 1024 * 1024) . "\n"
            ));
        }
        $slowTargetStarted = false;
        for ($attempt = 0; $attempt < 200; $attempt++) {
            if (str_contains((string) @file_get_contents($slowWriteMarker), "target-started\n")) {
                $slowTargetStarted = true;
                break;
            }
            usleep(10000);
        }
        assert_passthrough($slowTargetStarted, 'slow target starts without reading its stdin pipe');
        assert_passthrough(
            posix_kill(-$slowWritePid, SIGTSTP),
            'Ctrl-Z reaches a wrapper whose large secret is still backpressured'
        );
        $slowWriteStatus = 0;
        $slowWriteSuspended = false;
        for ($attempt = 0; $attempt < 40; $attempt++) {
            if (pcntl_waitpid($slowWritePid, $slowWriteStatus, WUNTRACED | WNOHANG) === $slowWritePid
                && pcntl_wifstopped($slowWriteStatus)) {
                $slowWriteSuspended = true;
                break;
            }
            usleep(10000);
        }
        assert_passthrough(
            $slowWriteSuspended,
            'nonblocking handoff dispatches Ctrl-Z promptly while the target pipe is backpressured'
        );
        assert_passthrough(posix_kill(-$slowWritePid, SIGCONT), 'slow target-write fixture resumes');
        pcntl_waitpid($slowWritePid, $slowWriteStatus);
        @unlink($slowWriteMarker);
        assert_passthrough(
            pcntl_wifexited($slowWriteStatus) && pcntl_wexitstatus($slowWriteStatus) === 23,
            'resumed nonblocking handoff preserves the exact target outcome'
        );

        $handoffSuspendMarker = tempnam(sys_get_temp_dir(), 'wprism-env-set-handoff-suspend-');
        assert_passthrough(is_string($handoffSuspendMarker), 'post-handoff suspension fixture is created');
        $handoffSuspendPid = pcntl_fork();
        assert_passthrough($handoffSuspendPid !== -1, 'post-handoff suspension fixture forks');
        if ($handoffSuspendPid === 0) {
            if (!posix_setpgid(0, 0)) exit(91);
            $handoffSuspendDriver = new RecordingInputPassthroughDriver();
            $handoffSuspendDriver->blockAfterReadMarker = $handoffSuspendMarker;
            exit(PassthroughCommand::runEnvSet(
                $handoffSuspendDriver,
                ['--name=gateway_secret', '--stdin'],
                static fn(): bool => true,
                static fn(bool $_enabled): bool => true,
                static fn(): string => "secret\n"
            ));
        }
        $handoffSuspendRead = false;
        for ($attempt = 0; $attempt < 200; $attempt++) {
            if (str_contains((string) @file_get_contents($handoffSuspendMarker), "target-read\n")) {
                $handoffSuspendRead = true;
                break;
            }
            usleep(10000);
        }
        assert_passthrough($handoffSuspendRead, 'post-handoff suspension starts after target input');
        assert_passthrough(
            posix_kill(-$handoffSuspendPid, SIGTSTP),
            'foreground-style SIGTSTP reaches the wrapper and target process group'
        );
        $handoffSuspendStatus = 0;
        $handoffSuspended = false;
        for ($attempt = 0; $attempt < 200; $attempt++) {
            if (pcntl_waitpid($handoffSuspendPid, $handoffSuspendStatus, WUNTRACED | WNOHANG)
                    === $handoffSuspendPid
                && pcntl_wifstopped($handoffSuspendStatus)) {
                $handoffSuspended = true;
                break;
            }
            usleep(10000);
        }
        assert_passthrough(
            $handoffSuspended,
            'post-handoff Ctrl-Z suspends the wrapper instead of deadlocking it behind a stopped target'
        );
        assert_passthrough(posix_kill(-$handoffSuspendPid, SIGCONT), 'fg-style SIGCONT resumes both sides');
        pcntl_waitpid($handoffSuspendPid, $handoffSuspendStatus);
        @unlink($handoffSuspendMarker);
        assert_passthrough(
            pcntl_wifexited($handoffSuspendStatus) && pcntl_wexitstatus($handoffSuspendStatus) === 23,
            'resumed post-handoff suspension preserves the exact target outcome'
        );
    }

    $signalLog = tempnam(sys_get_temp_dir(), 'wprism-env-set-signal-');
    assert_passthrough(is_string($signalLog), 'env-set signal fixture is created');
    $signalPid = pcntl_fork();
    assert_passthrough($signalPid !== -1, 'env-set signal fixture forks');
    if ($signalPid === 0) {
        $signalExit = PassthroughCommand::runEnvSet(
            new RecordingInputPassthroughDriver(),
            ['--name=gateway_secret', '--stdin'],
            static fn(): bool => true,
            static function (bool $enabled) use ($signalLog): bool {
                file_put_contents($signalLog, $enabled ? "echo-on\n" : "echo-off\n", FILE_APPEND | LOCK_EX);
                return true;
            },
            static function () use ($signalLog): string {
                file_put_contents($signalLog, "ready\n", FILE_APPEND | LOCK_EX);
                while (true) usleep(10000);
            }
        );
        exit($signalExit);
    }
    $signalReady = false;
    for ($attempt = 0; $attempt < 200; $attempt++) {
        $signalEvidence = (string) @file_get_contents($signalLog);
        if (str_contains($signalEvidence, "echo-off\n") && str_contains($signalEvidence, "ready\n")) {
            $signalReady = true;
            break;
        }
        usleep(10000);
    }
    assert_passthrough($signalReady, 'interactive env-set reaches its local read only after signal guards and masking');
    assert_passthrough(posix_kill($signalPid, SIGTERM), 'env-set signal fixture receives SIGTERM');
    $signalStatus = 0;
    $signalEnded = false;
    for ($attempt = 0; $attempt < 200; $attempt++) {
        if (pcntl_waitpid($signalPid, $signalStatus, WNOHANG) === $signalPid) {
            $signalEnded = true;
            break;
        }
        usleep(10000);
    }
    if (!$signalEnded) {
        posix_kill($signalPid, SIGKILL);
        pcntl_waitpid($signalPid, $signalStatus);
    }
    $signalEvidence = (string) file_get_contents($signalLog);
    @unlink($signalLog);
    assert_passthrough(
        $signalEnded
            && str_contains($signalEvidence, "echo-off\nready\necho-on\n")
            && pcntl_wifexited($signalStatus)
            && pcntl_wexitstatus($signalStatus) === 128 + SIGTERM,
        'SIGTERM restores terminal echo before interactive env-set terminates'
    );

    $signalGuardMethod = new ReflectionMethod(PassthroughCommand::class, 'installTerminalEchoSignalGuards');
    $previousTermHandler = pcntl_signal_get_handler(SIGTERM);
    pcntl_signal(SIGTERM, SIG_IGN);
    $cleanupTransitions = [];
    $cleanupEchoMasked = true;
    $cleanupInputActive = false;
    $cleanupGuardArgs = [
        static function (bool $enabled) use (&$cleanupTransitions): bool {
            $cleanupTransitions[] = $enabled;
            return true;
        },
        &$cleanupEchoMasked,
        &$cleanupInputActive,
    ];
    $restoreCleanupGuard = $signalGuardMethod->invokeArgs(null, $cleanupGuardArgs);
    assert_passthrough(is_callable($restoreCleanupGuard), 'cleanup lifecycle signal guard installs');
    posix_kill(getmypid(), SIGTERM);
    assert_passthrough(
        $cleanupTransitions === [true] && !$cleanupEchoMasked,
        'an inherited ignored signal during cleanup restores echo without re-masking it'
    );
    $restoreCleanupGuard();
    pcntl_signal(SIGTERM, $previousTermHandler);

    $suspendLog = tempnam(sys_get_temp_dir(), 'wprism-env-set-suspend-');
    assert_passthrough(is_string($suspendLog), 'suspend signal fixture is created');
    $suspendPid = pcntl_fork();
    assert_passthrough($suspendPid !== -1, 'suspend signal fixture forks');
    if ($suspendPid === 0) {
        PassthroughCommand::runEnvSet(
            new RecordingInputPassthroughDriver(),
            ['--name=gateway_secret', '--stdin'],
            static fn(): bool => true,
            static function (bool $enabled) use ($suspendLog): bool {
                file_put_contents($suspendLog, $enabled ? "echo-on\n" : "echo-off\n", FILE_APPEND | LOCK_EX);
                return true;
            },
            static function () use ($suspendLog): string {
                file_put_contents($suspendLog, "ready\n", FILE_APPEND | LOCK_EX);
                while (true) usleep(10000);
            }
        );
        exit(0);
    }
    for ($attempt = 0; $attempt < 200; $attempt++) {
        if (str_contains((string) @file_get_contents($suspendLog), "ready\n")) break;
        usleep(10000);
    }
    assert_passthrough(posix_kill($suspendPid, SIGTSTP), 'interactive env-set receives SIGTSTP');
    $suspendStatus = 0;
    $suspended = false;
    for ($attempt = 0; $attempt < 200; $attempt++) {
        if (pcntl_waitpid($suspendPid, $suspendStatus, WUNTRACED | WNOHANG) === $suspendPid
            && pcntl_wifstopped($suspendStatus)) {
            $suspended = true;
            break;
        }
        usleep(10000);
    }
    assert_passthrough(
        $suspended && str_contains((string) file_get_contents($suspendLog), "echo-on\n"),
        'SIGTSTP restores echo before preserving process suspension'
    );
    assert_passthrough(posix_kill($suspendPid, SIGCONT), 'suspended env-set resumes');
    $remasked = false;
    for ($attempt = 0; $attempt < 200; $attempt++) {
        if (substr_count((string) @file_get_contents($suspendLog), "echo-off\n") >= 2) {
            $remasked = true;
            break;
        }
        usleep(10000);
    }
    assert_passthrough($remasked, 'resumed secret input re-masks echo');
    posix_kill($suspendPid, SIGTERM);
    pcntl_waitpid($suspendPid, $suspendStatus);
    @unlink($suspendLog);


    $quitLog = tempnam(sys_get_temp_dir(), 'wprism-env-set-quit-');
    assert_passthrough(is_string($quitLog), 'quit signal fixture is created');
    $quitPid = pcntl_fork();
    assert_passthrough($quitPid !== -1, 'quit signal fixture forks');
    if ($quitPid === 0) {
        PassthroughCommand::runEnvSet(
            new RecordingInputPassthroughDriver(),
            ['--name=gateway_secret', '--stdin'],
            static fn(): bool => true,
            static function (bool $enabled) use ($quitLog): bool {
                file_put_contents($quitLog, $enabled ? "echo-on\n" : "echo-off\n", FILE_APPEND | LOCK_EX);
                return true;
            },
            static function () use ($quitLog): string {
                file_put_contents($quitLog, "ready\n", FILE_APPEND | LOCK_EX);
                while (true) usleep(10000);
            }
        );
        exit(0);
    }
    for ($attempt = 0; $attempt < 200; $attempt++) {
        if (str_contains((string) @file_get_contents($quitLog), "ready\n")) break;
        usleep(10000);
    }
    assert_passthrough(posix_kill($quitPid, SIGQUIT), 'interactive env-set receives SIGQUIT');
    $quitStatus = 0;
    pcntl_waitpid($quitPid, $quitStatus);
    assert_passthrough(
        str_contains((string) file_get_contents($quitLog), "echo-on\n")
            && pcntl_wifexited($quitStatus)
            && pcntl_wexitstatus($quitStatus) === 128 + SIGQUIT,
        'SIGQUIT restores echo before preserving the conventional quit status'
    );
    @unlink($quitLog);
}

$beforeBindingRefusal = count($driver->calls);
ob_start();
$bindingRefusal = PassthroughCommand::run(
    $driver,
    'lint',
    ['--repo=/other/repository', '--format=json']
);
$bindingJson = (string) ob_get_clean();
$bindingRecord = json_decode(trim($bindingJson), true);
assert_passthrough($bindingRefusal === 1, 'lint rejects a caller-supplied repository binding');
assert_passthrough(
    ($bindingRecord['error'] ?? null) === 'invalid_arguments'
        && count($driver->calls) === $beforeBindingRefusal,
    'repository override refuses before target contact with a structured diagnostic'
);
ob_start();
$pathRefusal = PassthroughCommand::run($driver, 'lint', ['--path=/other/wordpress', '--format=json']);
ob_end_clean();
assert_passthrough(
    $pathRefusal === 1 && count($driver->calls) === $beforeBindingRefusal,
    'WordPress path override refuses before target contact'
);

$warningMethod = new ReflectionMethod(Capture::class, 'lint_warning');
$captureWarning = $warningMethod->invoke(null, 2, '/srv/site repo;literal', 'preview');
assert_passthrough(
    is_string($captureWarning)
        && str_contains($captureWarning, 'run on the host: `wprism lint preview`')
        && str_contains($captureWarning, "`wp wprism lint --repo='/srv/site repo;literal'`")
        && !str_contains($captureWarning, '<env>'),
    'capture lint warning gives copy-ready host and shell-safe direct-target commands'
);
$directCaptureWarning = $warningMethod->invoke(null, 1, '/srv/site repo;literal', null);
assert_passthrough(
    is_string($directCaptureWarning)
        && !str_contains($directCaptureWarning, 'wprism lint <env>')
        && str_contains($directCaptureWarning, "`wp wprism lint --repo='/srv/site repo;literal'`"),
    'direct target capture emits only its copy-ready target remediation'
);
$unsafeCaptureWarning = $warningMethod->invoke(null, 1, "/srv/site\n\x1bINJECT", 'preview');
assert_passthrough(
    is_string($unsafeCaptureWarning)
        && preg_match('/[\x00-\x1F\x7F]/', $unsafeCaptureWarning) !== 1
        && !str_contains($unsafeCaptureWarning, 'INJECT')
        && str_contains($unsafeCaptureWarning, 'run on the host: `wprism lint preview`')
        && str_contains($unsafeCaptureWarning, 'configured repository path is unsafe to render'),
    'capture lint warning replaces a control-bearing repository command with one bounded safe line'
);
$oversizedCaptureWarning = $warningMethod->invoke(null, 1, '/srv/' . str_repeat('a', 4096), null);
assert_passthrough(
    is_string($oversizedCaptureWarning)
        && strlen($oversizedCaptureWarning) < 256
        && str_contains($oversizedCaptureWarning, 'configured repository path is unsafe to render'),
    'capture lint warning does not print an unbounded repository path'
);
$outputOnlyCaptureWarning = $warningMethod->invoke(null, 1, '/srv/repo', 'preview', false);
assert_passthrough(
    is_string($outputOnlyCaptureWarning)
        && str_contains($outputOnlyCaptureWarning, 'output-only candidate was scanned before publication')
        && str_contains($outputOnlyCaptureWarning, 'Rerun capture without `--out`')
        && !str_contains($outputOnlyCaptureWarning, '`wprism lint preview`')
        && !str_contains($outputOnlyCaptureWarning, '`wp wprism lint'),
    'output-only capture never suggests a lint command that scans different repository state'
);

$agentCliSource = file_get_contents(__DIR__ . '/../../../../agent/src/Command/Cli.php');
$captureMethodAt = is_string($agentCliSource) ? strpos($agentCliSource, 'public function capture(') : false;
$captureDocStart = $captureMethodAt === false
    ? false
    : strrpos(substr($agentCliSource, 0, $captureMethodAt), '/**');
$captureDoc = ($captureDocStart === false || $captureMethodAt === false)
    ? ''
    : substr($agentCliSource, $captureDocStart, $captureMethodAt - $captureDocStart);
assert_passthrough(
    str_contains($captureDoc, '[--scope-request-b64=<request>]')
        && str_contains($captureDoc, '[--orchestrator-environment=<name>]'),
    'capture declares both orchestrator-reserved inputs in the WP-CLI synopsis before host forwarding'
);

$scoped = PassthroughCommand::run($driver, 'plan', ['--format=json']);
assert_passthrough($scoped === 23, 'unscoped plan remains a direct passthrough');
assert_passthrough(
    $driver->calls[2] === ['wprism', 'plan', '--repo=/fixture/repo', '--format=json'],
    'unscoped plan does not synthesize a scope wire argument'
);

$beforeRefusal = count($driver->calls);
ob_start();
$refusal = PassthroughCommand::runScoped(
    $driver,
    'plan',
    ['--format=json', '--scope-request-b64=host-must-not-inject']
);
$json = (string) ob_get_clean();
$record = json_decode(trim($json), true);
assert_passthrough($refusal === 1, 'reserved scope wire input refuses with the machine failure code');
assert_passthrough(is_array($record), 'reserved scope wire refusal is one JSON object');
assert_passthrough(($record['error'] ?? null) === 'invalid_arguments', 'reserved scope wire has the stable reason code');
assert_passthrough(count($driver->calls) === $beforeRefusal, 'scope refusal occurs before target contact');

ob_start();
$emptyPathRefusal = PassthroughCommand::runScoped($driver, 'apply', ['--format=json', '--scope-contract=']);
$emptyPathJson = (string) ob_get_clean();
$emptyPathRecord = json_decode(trim($emptyPathJson), true);
assert_passthrough($emptyPathRefusal === 1, 'empty local scope path refuses with the machine failure code');
assert_passthrough(($emptyPathRecord['error'] ?? null) === 'invalid_arguments', 'empty scope path keeps the argument refusal reason');
assert_passthrough(count($driver->calls) === $beforeRefusal, 'empty scope path is rejected before target contact');

echo "PASS: passthrough command boundary\n";
