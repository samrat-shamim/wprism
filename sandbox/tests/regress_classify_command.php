<?php
declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/Command/ClassifyCommand.php';

use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\ClassificationBatch;
use Duo\Orchestrator\ClassifyCommand;

function fail_classify_command(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}
function assert_classify_command(bool $condition, string $message): void {
    if (!$condition) fail_classify_command($message);
}

final class ClassifyCommandDriver implements EnvironmentDriver {
    public int $captureCalls = 0;
    public int $streamCalls = 0;
    /** @var list<list<string>> */
    public array $streamedArgs = [];
    public int $streamExit = 0;
    /** @var list<array<string,mixed>> */
    public array $items;
    public string $pendingMode = 'items';
    /** Queue state to report starting from the Nth captureWp() call (1-indexed); falls back to $items. @var array<int,list<array<string,mixed>>> */
    public array $itemsByCall = [];
    /** A duo-command-refusal/v1 envelope to report as a failed fetch on the Nth captureWp() call. @var array<int,array<string,mixed>> */
    public array $refusalByCall = [];

    /** @param list<array<string,mixed>> $items */
    public function __construct(array $items) { $this->items = $items; }

    public function name(): string { return 'classify-fixture'; }
    public function driverId(): string { return 'classify-fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'classify fixture'; }
    public function captureRaw(string $script): array { return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
    public function captureWp(array $wpArgs): array {
        $this->captureCalls++;
        if ($this->pendingMode === 'failure') {
            return ['exit' => 9, 'stdout' => '', 'stderr' => 'queue unavailable'];
        }
        if (isset($this->refusalByCall[$this->captureCalls])) {
            return ['exit' => 7, 'stdout' => json_encode($this->refusalByCall[$this->captureCalls]) . "\n", 'stderr' => ''];
        }
        $items = $this->itemsByCall[$this->captureCalls] ?? $this->items;
        return ['exit' => 0, 'stdout' => json_encode(array_values($items)) . "\n", 'stderr' => ''];
    }
    public function streamWp(array $wpArgs): int {
        $this->streamCalls++;
        $this->streamedArgs[] = $wpArgs;
        return $this->streamExit;
    }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('classify-fixture', 'classify-fixture', $operation, []);
    }
}

/** @return array<string,mixed> */
function classify_item(string $section, string $key, ?string $proposal = null, ?string $secret = null): array {
    return [
        'section' => $section,
        'key' => $key,
        'proposal' => $proposal,
        'secret' => $secret,
        'evidence' => [],
        'ref_hint' => null,
    ];
}

// -- flag parsing refusals --------------------------------------------------

$noItems = new ClassifyCommandDriver([]);
ob_start();
$unknownExit = ClassifyCommand::run($noItems, ['--bogus']);
$unknownOutput = (string) ob_get_clean();
assert_classify_command($unknownExit === 1, 'unknown flag refuses');
assert_classify_command($noItems->captureCalls === 0, 'unknown flag refuses before ever fetching the queue');

$multiMode = new ClassifyCommandDriver([]);
ob_start();
$multiExit = ClassifyCommand::run($multiMode, ['--accept-proposals', '--export-batch=-']);
ob_get_clean();
assert_classify_command($multiExit === 1, 'combining two modes refuses');

$dupExport = new ClassifyCommandDriver([]);
ob_start();
$dupExportExit = ClassifyCommand::run($dupExport, ['--export-batch=a', '--export-batch=b']);
ob_get_clean();
assert_classify_command($dupExportExit === 1, 'repeated --export-batch refuses');

$dupApply = new ClassifyCommandDriver([]);
ob_start();
$dupApplyExit = ClassifyCommand::run($dupApply, ['--apply-batch=a', '--apply-batch=b']);
ob_get_clean();
assert_classify_command($dupApplyExit === 1, 'repeated --apply-batch refuses');

// -- empty queue --------------------------------------------------------------

$empty = new ClassifyCommandDriver([]);
ob_start();
$emptyExit = ClassifyCommand::run($empty, []);
$emptyOutput = (string) ob_get_clean();
assert_classify_command($emptyExit === 0, 'empty queue exits successfully');
assert_classify_command($emptyOutput === "review queue is empty\n", 'empty queue keeps exact output');
assert_classify_command($empty->streamCalls === 0, 'empty queue never streams a classify call');

// -- queue-fetch failure propagates ------------------------------------------

$badFetch = new ClassifyCommandDriver([]);
$badFetch->pendingMode = 'failure';
ob_start();
$badFetchExit = ClassifyCommand::run($badFetch, []);
ob_get_clean();
assert_classify_command($badFetchExit === 9, 'queue transport failure preserves the agent exit code');

// -- --export-batch=- (stdout) ------------------------------------------------

$exportItems = [classify_item('options', 'acme_flag', 'runtime')];
$exportDash = new ClassifyCommandDriver($exportItems);
ob_start();
$exportDashExit = ClassifyCommand::run($exportDash, ['--export-batch=-']);
$exportDashOutput = (string) ob_get_clean();
assert_classify_command($exportDashExit === 0, 'export to stdout exits successfully');
$decoded = json_decode($exportDashOutput, true);
assert_classify_command(is_array($decoded) && ($decoded['format'] ?? null) === ClassificationBatch::FORMAT, 'export to stdout emits a real classification-batch/v1 document');
assert_classify_command(($decoded['queue_sha256'] ?? null) === ClassificationBatch::queueHash($exportItems), 'export to stdout binds the exact queue hash');
assert_classify_command($exportDash->streamCalls === 0, 'exporting never streams a classify call');

// -- --export-batch=<path> ----------------------------------------------------

$exportPath = sys_get_temp_dir() . '/duo_regress_classify_' . bin2hex(random_bytes(6)) . '.json';
$exportFile = new ClassifyCommandDriver($exportItems);
ob_start();
$exportFileExit = ClassifyCommand::run($exportFile, ["--export-batch=$exportPath"]);
$exportFileOutput = (string) ob_get_clean();
assert_classify_command($exportFileExit === 0, 'export to a path exits successfully');
assert_classify_command(is_file($exportPath), 'export to a path writes the batch file');
assert_classify_command(str_contains($exportFileOutput, 'classification batch exported'), 'export to a path reports where it wrote');

ob_start();
$overwriteExit = ClassifyCommand::run(new ClassifyCommandDriver($exportItems), ["--export-batch=$exportPath"]);
ob_get_clean();
assert_classify_command($overwriteExit === 1, 'exporting over an existing path refuses rather than overwriting');

// -- --apply-batch=<path> -----------------------------------------------------

$applyMatching = new ClassifyCommandDriver($exportItems);
$applyMatching->streamExit = 0;
ob_start();
$applyExit = ClassifyCommand::run($applyMatching, ["--apply-batch=$exportPath"]);
ob_get_clean();
// The exported template leaves every decision's class null, so applying it
// unmodified must refuse as incomplete -- exercising ClassificationBatch's
// own binding rather than a hand-rolled duplicate check here.
assert_classify_command($applyExit === 1, 'applying an unmodified (all-null-class) export refuses as incomplete');
assert_classify_command($applyMatching->streamCalls === 0, 'an incomplete batch never streams a classify call');

$reviewedBatch = ClassificationBatch::template('classify-fixture', $exportItems);
$reviewedBatch['decisions'][0]['class'] = 'authored';
file_put_contents($exportPath, ClassificationBatch::encode($reviewedBatch));

$applyReviewedEmpty = new ClassifyCommandDriver($exportItems);
$applyReviewedEmpty->streamExit = 0;
// Call 1 (pre-apply fetch, must match the bound hash) sees the original
// one-item queue; call 2 (post-apply recheck) reports it cleared.
$applyReviewedEmpty->itemsByCall = [2 => []];
ob_start();
$applyReviewedExit = ClassifyCommand::run($applyReviewedEmpty, ["--apply-batch=$exportPath"]);
$applyReviewedOutput = (string) ob_get_clean();
assert_classify_command($applyReviewedExit === 0, 'a fully-reviewed batch applies successfully when the queue clears');
assert_classify_command($applyReviewedEmpty->streamCalls === 1, 'a reviewed batch streams exactly one classify call');
assert_classify_command(
    $applyReviewedEmpty->streamedArgs[0] === ['duo', 'classify', '--repo=/fixture/repo', '--set=options:acme_flag=authored'],
    'the applied --set matches the reviewed decision exactly'
);
assert_classify_command(str_contains($applyReviewedOutput, '1 reviewed classification(s) applied'), 'apply reports how many decisions it applied');
assert_classify_command(str_contains($applyReviewedOutput, 'review queue is empty'), 'apply reports the queue is clear afterward');

// A second item surfaced (or a decision was only partial) between the
// pre-apply fetch and the post-apply recheck: call 1 (bound-hash fetch
// inside run()) must still see the original one-item queue the batch was
// exported against; call 2 (the post-stream recheck) reports a second item
// now pending, so this is exercised as "exposed or retained", not silently
// dropped.
$stillPendingItems = [classify_item('options', 'acme_flag', 'runtime'), classify_item('options', 'other_flag', 'runtime')];
$applyReviewedRemaining = new ClassifyCommandDriver($exportItems);
$applyReviewedRemaining->itemsByCall = [2 => $stillPendingItems];
ob_start();
$applyRemainingExit = ClassifyCommand::run($applyReviewedRemaining, ["--apply-batch=$exportPath"]);
$applyRemainingOutput = (string) ob_get_clean();
assert_classify_command($applyReviewedRemaining->streamCalls === 1, 'apply streams once even when items remain afterward');
assert_classify_command($applyRemainingExit === 2, 'items still pending after apply exits 2, distinct from a clean 0');
assert_classify_command(!str_contains($applyRemainingOutput, 'review queue is empty'), 'apply never claims a clear queue while items remain');

// The post-apply recheck fetch can itself fail with a structured refusal
// (e.g. the agent-side queue is locked). The pre-extraction fetch_pending()
// always wired render_command_refusal_human() for exactly this call; the
// extraction must keep doing so rather than silently defaulting to null and
// falling back to a raw JSON dump.
$refusalEnvelope = [
    'format' => 'duo-command-refusal/v1',
    'ok' => false,
    'reason_code' => 'queue_locked',
    'message' => 'pending queue is locked by a concurrent capture',
];
$applyRefusalRecheck = new ClassifyCommandDriver($exportItems);
$applyRefusalRecheck->refusalByCall = [2 => $refusalEnvelope];
$renderedRefusals = [];
ob_start();
$applyRefusalExit = ClassifyCommand::run(
    $applyRefusalRecheck,
    ["--apply-batch=$exportPath"],
    function (array $refusal) use (&$renderedRefusals): void { $renderedRefusals[] = $refusal; }
);
ob_get_clean();
assert_classify_command($applyRefusalExit === 7, 'a refused post-apply recheck preserves the agent exit code');
assert_classify_command($applyRefusalRecheck->streamCalls === 1, 'the apply itself still streamed before the recheck refused');
assert_classify_command(
    count($renderedRefusals) === 1 && ($renderedRefusals[0]['reason_code'] ?? null) === 'queue_locked',
    'a refused post-apply recheck renders through the SAME callback the pre-apply fetch uses, never a raw JSON fallback'
);

unlink($exportPath);

// A reviewed decision that authors a secret-flagged item with allow_secret
// explicitly set true must append --allow-secret to the streamed --set call.
$secretApplyItems = [classify_item('options', 'api_key', 'authored', 'looks like an API key')];
$secretApplyPath = sys_get_temp_dir() . '/duo_regress_classify_secret_' . bin2hex(random_bytes(6)) . '.json';
$secretBatch = ClassificationBatch::template('classify-fixture', $secretApplyItems);
$secretBatch['decisions'][0]['class'] = 'authored';
$secretBatch['decisions'][0]['allow_secret'] = true;
file_put_contents($secretApplyPath, ClassificationBatch::encode($secretBatch));
$applySecret = new ClassifyCommandDriver($secretApplyItems);
$applySecret->itemsByCall = [2 => []];
ob_start();
$applySecretExit = ClassifyCommand::run($applySecret, ["--apply-batch=$secretApplyPath"]);
ob_get_clean();
assert_classify_command($applySecretExit === 0, 'an allow_secret-confirmed authored decision applies successfully');
assert_classify_command(
    $applySecret->streamedArgs[0] === ['duo', 'classify', '--repo=/fixture/repo', '--set=options:api_key=authored', '--allow-secret'],
    '--allow-secret is appended exactly when the reviewed batch confirms it'
);
unlink($secretApplyPath);

$staleBatchPath = sys_get_temp_dir() . '/duo_regress_classify_stale_' . bin2hex(random_bytes(6)) . '.json';
file_put_contents($staleBatchPath, ClassificationBatch::encode(ClassificationBatch::template('classify-fixture', [classify_item('options', 'gone', 'runtime')])));
$applyStale = new ClassifyCommandDriver($exportItems);
ob_start();
$staleExit = ClassifyCommand::run($applyStale, ["--apply-batch=$staleBatchPath"]);
$staleOutput = (string) ob_get_clean();
assert_classify_command($staleExit === 1, 'applying a batch bound to a different queue refuses');
assert_classify_command($applyStale->streamCalls === 0, 'a stale batch never streams a classify call');
unlink($staleBatchPath);

// -- --accept-proposals --------------------------------------------------------

$acceptItems = [classify_item('options', 'auto_flag', 'runtime')];
$acceptClean = new ClassifyCommandDriver($acceptItems);
ob_start();
$acceptExit = ClassifyCommand::run($acceptClean, ['--accept-proposals']);
$acceptOutput = (string) ob_get_clean();
assert_classify_command($acceptExit === 0, 'accepting clean proposals exits successfully');
assert_classify_command($acceptClean->streamCalls === 1, 'accepting proposals streams exactly one classify call');
assert_classify_command(
    $acceptClean->streamedArgs[0] === ['duo', 'classify', '--repo=/fixture/repo', '--set=options:auto_flag=runtime'],
    'the accepted --set matches the proposal exactly'
);
assert_classify_command(str_contains($acceptOutput, '1 accepted'), 'accept-proposals reports the accepted count');

$secretItems = [classify_item('options', 'api_key', 'authored', 'looks like an API key')];
$acceptSecret = new ClassifyCommandDriver($secretItems);
ob_start();
$acceptSecretExit = ClassifyCommand::run($acceptSecret, ['--accept-proposals']);
$acceptSecretOutput = (string) ob_get_clean();
assert_classify_command($acceptSecretExit === 2, 'a secret-flagged authored proposal exits 2, never silently applied');
assert_classify_command($acceptSecret->streamCalls === 0, 'a secret-only queue never streams a classify call');

// Interactive mode (no mode flag, non-empty queue) calls
// Triage::run($items, STDIN, STDOUT) with the real STDIN/STDOUT constants,
// unchanged from cmd_classify_interactive's own prior body -- not unit-
// testable through this entry point without a subprocess. Triage::run()'s
// own decision logic is exercised directly by cli/README.md's documented
// contract; the real interactive `duo classify` wiring (this extraction's
// own call site) is proven end-to-end by the existing live
// sandbox/tests/spike/cli_triage_smoke.sh, which pipes scripted stdin through the
// real `cli/duo classify` subprocess.

echo "PASS: classify command\n";
