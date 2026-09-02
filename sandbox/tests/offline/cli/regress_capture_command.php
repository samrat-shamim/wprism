<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Command/CaptureCommand.php';

use WPrism\Orchestrator\DriverCapabilityReport;
use WPrism\Orchestrator\EnvironmentDriver;
use WPrism\Orchestrator\CaptureCommand;

function fail_capture_command(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}
function assert_capture_command(bool $condition, string $message): void {
    if (!$condition) fail_capture_command($message);
}

final class CaptureCommandDriver implements EnvironmentDriver {
    public int $captureCalls = 0;
    /** @var array{exit:int,stdout:string,stderr:string} */
    public array $captureResult = ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    /** @var list<list<string>> */
    public array $capturedArgs = [];
    public int $streamCalls = 0;
    /** @var list<list<string>> */
    public array $streamedArgs = [];
    public int $streamExit = 0;

    public function name(): string { return 'capture-fixture'; }
    public function driverId(): string { return 'capture-fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'capture fixture'; }
    public function captureRaw(string $script): array { return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
    public function captureWp(array $wpArgs): array {
        $this->captureCalls++;
        $this->capturedArgs[] = $wpArgs;
        return $this->captureResult;
    }
    public function streamWp(array $wpArgs): int {
        $this->streamCalls++;
        $this->streamedArgs[] = $wpArgs;
        return $this->streamExit;
    }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('capture-fixture', 'capture-fixture', $operation, []);
    }
}

$fixtureBranch = 'feature/capture-command';
$fixtureBranchResolver = static fn(): string => $fixtureBranch;

// -- no --scope-contract flag: plain passthrough ------------------------------

$plain = new CaptureCommandDriver();
$plainExit = CaptureCommand::run($plain, ['--set=options:foo=runtime', '--dry-run'], null, $fixtureBranchResolver);
assert_capture_command($plainExit === 0, 'plain capture with no scope contract streams and returns the agent exit');
assert_capture_command($plain->streamCalls === 1, 'plain capture streams exactly once');
assert_capture_command(
    $plain->streamedArgs[0] === [
        'wprism', 'capture', '--repo=/fixture/repo', '--set=options:foo=runtime', '--dry-run',
        '--orchestrator-environment=capture-fixture',
        '--expected-repository-branch=feature/capture-command',
    ],
    'every other flag forwards through unchanged, in order, after the repo argument'
);

$plainNonZero = new CaptureCommandDriver();
$plainNonZero->streamExit = 3;
assert_capture_command(CaptureCommand::run($plainNonZero, [], null, $fixtureBranchResolver) === 3, 'the agent exit code propagates unchanged');

// -- reserved/internal argument refusals --------------------------------------

$reserved = new CaptureCommandDriver();
$reservedExit = CaptureCommand::run($reserved, ['--scope-request-b64=abc']);
assert_capture_command($reservedExit === 2, 'an orchestrator-reserved --scope-request-b64 argument refuses (exit 2)');
assert_capture_command($reserved->streamCalls === 0, 'a reserved-argument refusal never streams a capture call');

$bindingOverride = new CaptureCommandDriver();
assert_capture_command(
    CaptureCommand::run($bindingOverride, ['--repo=/other']) === 2
        && $bindingOverride->streamCalls === 0,
    'a caller-supplied target binding refuses before target contact'
);

$presentationOverride = new CaptureCommandDriver();
assert_capture_command(
    CaptureCommand::run($presentationOverride, ['--orchestrator-environment=other']) === 2
        && $presentationOverride->streamCalls === 0,
    'a caller-supplied host presentation context refuses before target contact'
);

$customRegistry = new CaptureCommandDriver();
$registryExit = CaptureCommand::run($customRegistry, [], '/tmp/custom registry.json', $fixtureBranchResolver);
assert_capture_command(
    $registryExit === 0
        && $customRegistry->streamedArgs[0] === [
            'wprism', 'capture', '--repo=/fixture/repo',
            '--expected-repository-branch=feature/capture-command',
        ],
    'an explicit registry remains host-local and does not alter target argv'
);

$customRegistryOut = new CaptureCommandDriver();
assert_capture_command(
    CaptureCommand::run($customRegistryOut, ['--out=/tmp/candidate'], '/tmp/custom.json') === 0
        && $customRegistryOut->streamedArgs[0] === [
            'wprism', 'capture', '--repo=/fixture/repo', '--out=/tmp/candidate',
        ],
    'output-only capture with a custom registry adds no mismatched repository-lint presentation context'
);

$explicitBranch = new CaptureCommandDriver();
assert_capture_command(
    CaptureCommand::run($explicitBranch, ['--target-branch=release/client-a'], null, static fn(): never => throw new RuntimeException('resolver must not run')) === 0
        && $explicitBranch->streamedArgs[0] === [
            'wprism', 'capture', '--repo=/fixture/repo',
            '--orchestrator-environment=capture-fixture',
            '--expected-repository-branch=release/client-a',
        ],
    'an explicit destination branch is removed from public argv and forwarded as the reserved target binding'
);

$detached = new CaptureCommandDriver();
assert_capture_command(
    CaptureCommand::run($detached, [], null, static fn(): ?string => null) === 2
        && $detached->streamCalls === 0,
    'repository-writing capture refuses a detached or non-Git caller before target contact'
);

$reservedBranch = new CaptureCommandDriver();
assert_capture_command(
    CaptureCommand::run($reservedBranch, ['--expected-repository-branch=main']) === 2
        && $reservedBranch->streamCalls === 0,
    'callers cannot forge the orchestrator-reserved target branch binding'
);

// -- malformed --scope-contract flag shapes -----------------------------------

foreach (['--scope-contract', '--scope-contractX'] as $malformed) {
    $driver = new CaptureCommandDriver();
    $exit = CaptureCommand::run($driver, [$malformed]);
    assert_capture_command($exit === 2, "a malformed scope-contract flag shape ('$malformed') refuses (exit 2)");
    assert_capture_command($driver->streamCalls === 0, "'$malformed' never streams a capture call");
}

// -- duplicate --scope-contract= ----------------------------------------------

$duplicate = new CaptureCommandDriver();
$duplicateExit = CaptureCommand::run($duplicate, ['--scope-contract=/a', '--scope-contract=/b']);
assert_capture_command($duplicateExit === 2, 'a repeated --scope-contract= refuses (exit 2)');
assert_capture_command($duplicate->streamCalls === 0, 'a duplicate scope contract never streams a capture call');

// -- empty --scope-contract= path ---------------------------------------------

$empty = new CaptureCommandDriver();
$emptyExit = CaptureCommand::run($empty, ['--scope-contract=']);
assert_capture_command($emptyExit === 2, 'an empty --scope-contract= path refuses (exit 2)');
assert_capture_command($empty->streamCalls === 0, 'an empty scope-contract path never streams a capture call');

// -- unreadable / malformed contract file --------------------------------------

$missingPath = sys_get_temp_dir() . '/wprism_regress_capture_missing_' . bin2hex(random_bytes(6)) . '.json';
$missing = new CaptureCommandDriver();
$missingExit = CaptureCommand::run($missing, ["--scope-contract=$missingPath"]);
assert_capture_command($missingExit === 2, 'a scope-contract path that does not exist refuses (exit 2)');
assert_capture_command($missing->streamCalls === 0, 'a missing contract file never streams a capture call');

$garbagePath = sys_get_temp_dir() . '/wprism_regress_capture_garbage_' . bin2hex(random_bytes(6)) . '.json';
file_put_contents($garbagePath, "not a real scope contract\n");
$garbage = new CaptureCommandDriver();
$garbageExit = CaptureCommand::run($garbage, ["--scope-contract=$garbagePath"]);
assert_capture_command($garbageExit === 2, 'an undecodable scope-contract file refuses (exit 2)');
assert_capture_command($garbage->streamCalls === 0, 'an undecodable contract never streams a capture call');
unlink($garbagePath);

// -- JSON refusal mode ----------------------------------------------------------

$jsonRefusal = new CaptureCommandDriver();
ob_start();
$jsonExit = CaptureCommand::run($jsonRefusal, ['--scope-contract=', '--format=json']);
$jsonOutput = (string) ob_get_clean();
// CommandOutput::renderRefusalJson() always returns 1, distinct from the
// plain-stderr path's hardcoded 2 -- an existing, unchanged distinction in
// the code this slice moved, not something this extraction introduces.
assert_capture_command($jsonExit === 1, 'a refusal under --format=json returns the JSON-envelope exit code (1), not the plain-stderr one (2)');
$decoded = json_decode($jsonOutput, true);
assert_capture_command(
    is_array($decoded) && ($decoded['format'] ?? null) === 'wprism-command-refusal/v1' && ($decoded['reason_code'] ?? null) === 'invalid_arguments',
    'a refusal under --format=json emits the real wprism-command-refusal/v1 envelope, not the plain stderr line'
);

// -- repository-writing JSON success receipt ----------------------------------

$jsonSuccess = new CaptureCommandDriver();
$jsonSuccess->captureResult = [
    'exit' => 0,
    'stdout' => json_encode([
        'counts' => ['post' => 3, 'term' => 2, 'menu' => 1, 'sidebar' => 0, 'options' => 4, 'deletion' => 0],
        'media' => 2,
        'notes' => ['informational observation'],
        'warnings' => [],
        'state_dir' => '/fixture/repo/state',
        'revision_hash' => str_repeat('a', 64),
        'initial_code_baseline' => null,
        'initial_publication_cleanup' => null,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    'stderr' => '',
];
ob_start();
$jsonSuccessExit = CaptureCommand::run(
    $jsonSuccess,
    ['--target-branch=feature/capture-command', '--format=json'],
    null,
    static fn(): never => throw new RuntimeException('explicit branch must suppress branch discovery')
);
$jsonSuccessOutput = (string) ob_get_clean();
$jsonSuccessReceipt = json_decode($jsonSuccessOutput, true, 512, JSON_THROW_ON_ERROR);
$jsonSuccessBody = $jsonSuccessReceipt;
unset($jsonSuccessBody['receipt_sha256']);
assert_capture_command($jsonSuccessExit === 0, 'repository-writing JSON capture succeeds through the captured target boundary');
assert_capture_command(
    $jsonSuccess->streamCalls === 0 && $jsonSuccess->captureCalls === 1
        && $jsonSuccess->capturedArgs[0] === [
            'wprism', 'capture', '--repo=/fixture/repo', '--format=json',
            '--orchestrator-environment=capture-fixture',
            '--expected-repository-branch=feature/capture-command',
        ],
    'machine capture is bounded and never streams an unvalidated implementation summary'
);
assert_capture_command(
    ($jsonSuccessReceipt['format'] ?? null) === 'wprism-capture-result/v1'
        && ($jsonSuccessReceipt['environment'] ?? null) === 'capture-fixture'
        && ($jsonSuccessReceipt['branch'] ?? null) === 'feature/capture-command'
        && ($jsonSuccessReceipt['capture']['state_revision'] ?? null) === str_repeat('a', 64)
        && ($jsonSuccessReceipt['capture']['counts']['post'] ?? null) === 3
        && ($jsonSuccessReceipt['capture']['media_count'] ?? null) === 2
        && ($jsonSuccessReceipt['next_action'] ?? null) === 'review_and_commit',
    'machine capture publishes only the closed host receipt derived from agent facts'
);
assert_capture_command(
    ($jsonSuccessReceipt['receipt_sha256'] ?? null)
        === 'sha256:' . hash('sha256', WPrism\Canon::encode($jsonSuccessBody)),
    'capture receipt digest binds every canonical public field'
);

$targetRefusal = new CaptureCommandDriver();
$targetRefusal->captureResult = [
    'exit' => 1,
    'stdout' => json_encode([
        'format' => 'wprism-command-refusal/v1',
        'ok' => false,
        'command' => 'capture',
        'error' => 'capture_branch_mismatch',
        'reason_code' => 'capture_branch_mismatch',
        'message' => 'capture branch binding changed',
        'remediation' => 'restore the intended named branch and retry capture',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    'stderr' => '',
];
ob_start();
$targetRefusalExit = CaptureCommand::run($targetRefusal, ['--format=json'], null, $fixtureBranchResolver);
$targetRefusalOutput = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
assert_capture_command(
    $targetRefusalExit === 1
        && ($targetRefusalOutput['format'] ?? null) === 'wprism-command-refusal/v1'
        && ($targetRefusalOutput['reason_code'] ?? null) === 'capture_branch_mismatch',
    'machine capture preserves a target agent refusal as its single public document'
);

echo "PASS: capture command\n";
