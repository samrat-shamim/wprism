<?php
declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/PassthroughCommand.php';
require_once __DIR__ . '/../../agent/src/Capture.php';

use Duo\Capture;
use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\PassthroughCommand;

function fail_passthrough(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
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

$ordinary = PassthroughCommand::run($driver, 'capabilities', ['--format=json']);
assert_passthrough($ordinary === 23, 'ordinary passthrough preserves the transport exit code');
assert_passthrough(
    $driver->calls === [['duo', 'capabilities', '--repo=/fixture/repo', '--format=json']],
    'ordinary passthrough preserves the exact agent argv and repo binding'
);

$lint = PassthroughCommand::run($driver, 'lint', ['--format=json']);
assert_passthrough($lint === 23, 'lint passthrough preserves the transport exit code');
assert_passthrough(
    $driver->calls[1] === ['duo', 'lint', '--repo=/fixture/repo', '--format=json'],
    'lint passthrough preserves the exact agent argv and repo binding'
);

$envDriver = new RecordingPassthroughDriver();
$echoTransitions = [];
$envSet = PassthroughCommand::runEnvSet(
    $envDriver,
    ['--name=gateway_secret', '--stdin'],
    static fn(): bool => true,
    static function (bool $enabled) use (&$echoTransitions): bool {
        $echoTransitions[] = $enabled;
        return true;
    }
);
assert_passthrough($envSet === 23, 'interactive env-set preserves the transport exit code');
assert_passthrough(
    $echoTransitions === [false, true]
        && $envDriver->calls === [[
            'duo', 'env-set', '--repo=/fixture/repo', '--name=gateway_secret', '--stdin',
        ]],
    'interactive env-set masks the host terminal around the exact inherited-STDIN target call'
);

$pipedDriver = new RecordingPassthroughDriver();
$pipedEchoTouched = false;
$pipedEnvSet = PassthroughCommand::runEnvSet(
    $pipedDriver,
    ['--name=gateway_secret', '--stdin'],
    static fn(): bool => false,
    static function (bool $_enabled) use (&$pipedEchoTouched): bool {
        $pipedEchoTouched = true;
        return true;
    }
);
assert_passthrough(
    $pipedEnvSet === 23 && !$pipedEchoTouched && count($pipedDriver->calls) === 1,
    'piped env-set input needs no terminal mutation and keeps passthrough behavior'
);

$malformedStdinDriver = new RecordingPassthroughDriver();
$malformedStdinTransitions = [];
$malformedStdin = PassthroughCommand::runEnvSet(
    $malformedStdinDriver,
    ['--name=gateway_secret', '--stdin=unexpected'],
    static fn(): bool => true,
    static function (bool $enabled) use (&$malformedStdinTransitions): bool {
        $malformedStdinTransitions[] = $enabled;
        return true;
    }
);
assert_passthrough(
    $malformedStdin === 23 && $malformedStdinTransitions === [false, true],
    'option-shaped stdin input cannot bypass local masking before target validation'
);

$refusedEnvDriver = new RecordingPassthroughDriver();
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

$throwingEnvDriver = new RecordingPassthroughDriver();
$throwingEnvDriver->throwOnStream = true;
$throwingEchoTransitions = [];
try {
    PassthroughCommand::runEnvSet(
        $throwingEnvDriver,
        ['--name=gateway_secret', '--stdin'],
        static fn(): bool => true,
        static function (bool $enabled) use (&$throwingEchoTransitions): bool {
            $throwingEchoTransitions[] = $enabled;
            return true;
        }
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
    $signalLog = tempnam(sys_get_temp_dir(), 'duo-env-set-signal-');
    assert_passthrough(is_string($signalLog), 'env-set signal fixture is created');
    $signalPid = pcntl_fork();
    assert_passthrough($signalPid !== -1, 'env-set signal fixture forks');
    if ($signalPid === 0) {
        $signalDriver = new RecordingPassthroughDriver();
        $signalDriver->blockOnStreamMarker = $signalLog;
        $signalExit = PassthroughCommand::runEnvSet(
            $signalDriver,
            ['--name=gateway_secret', '--stdin'],
            static fn(): bool => true,
            static function (bool $enabled) use ($signalLog): bool {
                file_put_contents($signalLog, $enabled ? "echo-on\n" : "echo-off\n", FILE_APPEND | LOCK_EX);
                return true;
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
    assert_passthrough($signalReady, 'interactive env-set reaches target only after signal guards and masking');
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
        && str_contains($captureWarning, 'run on the host: `duo lint preview`')
        && str_contains($captureWarning, "`wp duo lint --repo='/srv/site repo;literal'`")
        && !str_contains($captureWarning, '<env>'),
    'capture lint warning gives copy-ready host and shell-safe direct-target commands'
);
$directCaptureWarning = $warningMethod->invoke(null, 1, '/srv/site repo;literal', null);
assert_passthrough(
    is_string($directCaptureWarning)
        && !str_contains($directCaptureWarning, 'duo lint <env>')
        && str_contains($directCaptureWarning, "`wp duo lint --repo='/srv/site repo;literal'`"),
    'direct target capture emits only its copy-ready target remediation'
);
$unsafeCaptureWarning = $warningMethod->invoke(null, 1, "/srv/site\n\x1bINJECT", 'preview');
assert_passthrough(
    is_string($unsafeCaptureWarning)
        && preg_match('/[\x00-\x1F\x7F]/', $unsafeCaptureWarning) !== 1
        && !str_contains($unsafeCaptureWarning, 'INJECT')
        && str_contains($unsafeCaptureWarning, 'run on the host: `duo lint preview`')
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
        && !str_contains($outputOnlyCaptureWarning, '`duo lint preview`')
        && !str_contains($outputOnlyCaptureWarning, '`wp duo lint'),
    'output-only capture never suggests a lint command that scans different repository state'
);

$agentCliSource = file_get_contents(__DIR__ . '/../../agent/src/Cli.php');
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
    $driver->calls[2] === ['duo', 'plan', '--repo=/fixture/repo', '--format=json'],
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
