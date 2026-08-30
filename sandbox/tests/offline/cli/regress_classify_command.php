<?php
/**
 * Offline regression for `wprism classify`'s three modes.
 *
 * Since issue #3496 this suite runs BOTH sides of the classify contract in one
 * process: the host command handler from cli/src, and — through a driver whose
 * streamWp() executes the real `wp wprism classify` handler against a real
 * site.wprism.json — the agent that receives what it streams. That is the only
 * place the defect lived: each half was internally consistent while the batch
 * artifact could not express a decision the agent's own grammar would accept,
 * so a batch that "applied" produced policy the next command refused to load.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../../../cli/src/Command/ClassifyCommand.php';

/** wp-cli's surface as `WPrism\Cli::classify()` uses it: lines, success, and a throwing error. */
final class ClassifyCommandWpCli {
    /** @var list<string> */
    public static array $lines = [];

    public static function add_command($name, $class): void {}
    public static function line($line): void { self::$lines[] = (string) $line; }
    public static function success($line): void { self::$lines[] = 'Success: ' . (string) $line; }
    public static function warning($line): void { self::$lines[] = 'Warning: ' . (string) $line; }
    public static function halt($status): void { throw new RuntimeException('halt:' . (int) $status); }
    public static function error($message, $exit = true): void {
        if ($exit !== false) {
            throw new RuntimeException((string) $message);
        }
    }
}
class_alias(ClassifyCommandWpCli::class, 'WP_CLI');

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Secrets.php';
require_once __DIR__ . '/../../../../agent/src/Code/Code.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Review/Pending.php';
require_once __DIR__ . '/../../../../agent/src/Command/Cli.php';

use WPrism\Orchestrator\DriverCapabilityReport;
use WPrism\Orchestrator\EnvironmentDriver;
use WPrism\Orchestrator\ClassificationBatch;
use WPrism\Orchestrator\ClassifyCommand;
use WPrism\Orchestrator\Triage;

// Policy::load() has demanded spec_version since issue #3247 and this file never
// boots agent/wprism.php; derive the exact shipped generation from the platform
// library this product-path fixture now loads.
if (!defined('WPRISM_SPEC_VERSION')) {
    $classifyPlatform = json_decode(
        (string) file_get_contents(dirname(__DIR__, 4) . '/platform/adapter-library/capabilities/platform.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    define('WPRISM_SPEC_VERSION', (int) $classifyPlatform['platform']['spec_version']);
}
$wpdb = \WPrismTest\FakeWpdb::install();
// classify's own pre-write secret check reads the live value through
// Pending::current_value(); an unseeded table is a LogicException here, which
// is the loud behaviour this harness wants.
$wpdb->seedTable('wp_options', []);
$wpdb->seedTable('wp_postmeta', []);

// `make -j8` runs the corpus concurrently in one shared temp dir, so every
// fixture below is uniquely named and removed on exit.
$classifyScratch = [];
register_shutdown_function(static function () use (&$classifyScratch): void {
    foreach ($classifyScratch as $dir) {
        foreach (glob(rtrim($dir, '/') . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
});

/** A site.wprism.json exactly as `wprism init` leaves it: a policy with no rules yet. */
function classify_fixture_repo(): string {
    global $classifyScratch;
    $repo = sys_get_temp_dir() . '/wprism_regress_classify_repo_' . bin2hex(random_bytes(6));
    mkdir($repo, 0777, true);
    $classifyScratch[] = $repo;
    file_put_contents($repo . '/site.wprism.json', json_encode([
        'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => \WPrism\Code::SOURCE],
        'manifests' => ['core'],
        'policy' => [
            'options' => new stdClass(),
            'post_meta' => new stdClass(),
            'post_types' => ['post'],
            'taxonomies' => ['category'],
            'term_meta' => new stdClass(),
        ],
        'spec_version' => WPRISM_SPEC_VERSION,
    ], JSON_PRETTY_PRINT));
    return $repo;
}

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
    /**
     * When set, streamWp() stops pretending: it routes the streamed argv into
     * the real `WPrism\Cli::classify()` against this repository, so an assertion
     * about what reaches site.wprism.json is about the agent's own writer and its
     * own spec grammar, not about a second implementation of them living here.
     */
    public ?string $agentRepo = null;
    /** @var list<array<string,mixed>> */
    public array $items;
    public string $pendingMode = 'items';
    /** Queue state to report starting from the Nth captureWp() call (1-indexed); falls back to $items. @var array<int,list<array<string,mixed>>> */
    public array $itemsByCall = [];
    /** A wprism-command-refusal/v1 envelope to report as a failed fetch on the Nth captureWp() call. @var array<int,array<string,mixed>> */
    public array $refusalByCall = [];

    /** @param list<array<string,mixed>> $items */
    public function __construct(array $items) { $this->items = $items; }

    public function name(): string { return 'classify-fixture'; }
    public function driverId(): string { return 'classify-fixture'; }
    public function repoPath(): string { return $this->agentRepo ?? '/fixture/repo'; }
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
        if ($this->agentRepo === null) {
            return $this->streamExit;
        }
        $assoc = [];
        foreach ($wpArgs as $arg) {
            if (str_starts_with($arg, '--repo=')) {
                $assoc['repo'] = substr($arg, strlen('--repo='));
            } elseif (str_starts_with($arg, '--set=')) {
                $assoc['set'] = substr($arg, strlen('--set='));
            } elseif ($arg === '--allow-secret') {
                $assoc['allow-secret'] = true;
            } elseif ($arg === '--allow-pii') {
                $assoc['allow-pii'] = true;
            }
        }
        try {
            (new \WPrism\Cli())->classify([], $assoc);
            return 0;
        } catch (\Throwable $e) {
            // WP_CLI::error() exits non-zero on the real target; the stub
            // throws, and this is where that becomes an exit code again.
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }
    }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('classify-fixture', 'classify-fixture', $operation, []);
    }
}

/** @return array<string,mixed> */
function classify_item(
    string $section,
    string $key,
    ?string $proposal = null,
    ?string $secret = null,
    ?string $pii = null
): array {
    return [
        'section' => $section,
        'key' => $key,
        'proposal' => $proposal,
        'secret' => $secret,
        'pii' => $pii,
        'evidence' => [],
        'ref_hint' => null,
    ];
}

// -- the bounded skipped-item listing (issue #3521) ------------------------------
//
// `--accept-proposals` prints one line per item it refused to accept, and
// before issue #3521 that listing had no ceiling: on a first classification pass
// the secret-flagged set can be the size of the whole queue, which is the
// unbounded-output leak docs/product-spec.md:776-777 names. It is written
// with fwrite(STDERR, ...), which ob_start() does not intercept, so the run
// happens in a child re-exec of THIS file — same source, same fixture driver,
// same ClassifyCommand::run() entry point.
const CLASSIFY_SECRET_ROWS = 4;
if (($argv[1] ?? '') === '--secret-skipped-view') {
    $secretItems = [];
    for ($i = 0; $i < CLASSIFY_SECRET_ROWS; $i++) {
        $secretItems[] = classify_item('options', "secret_key_$i", 'authored', 'hard:api-key');
    }
    ob_start();
    ClassifyCommand::run(new ClassifyCommandDriver($secretItems), ['--accept-proposals', '--limit=1']);
    ob_end_clean();
    exit(0);
}
$secretViewFile = tempnam(sys_get_temp_dir(), 'classify-secret-view');
if (!is_string($secretViewFile)) fail_classify_command('could not stage the skipped-listing capture file');
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --secret-skipped-view'
        . ' >/dev/null 2>' . escapeshellarg($secretViewFile),
    $classifyIgnored,
    $classifyChildExit
);
$secretView = (string) file_get_contents($secretViewFile);
@unlink($secretViewFile);
assert_classify_command(
    str_contains($secretView, 'skipped ' . CLASSIFY_SECRET_ROWS . ' secret-flagged item(s)'),
    'the skipped-item sentence keeps the TRUE total even when the listing under it is cut'
);
assert_classify_command(
    substr_count($secretView, "\n  - ") === 1,
    '--limit=1 prints exactly one skipped row'
);
assert_classify_command(
    str_contains($secretView, '  ' . (CLASSIFY_SECRET_ROWS - 1) . ' more (use --format=json)'),
    'the cut listing ends in the shared tail naming what was withheld and where to get it'
);

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

$exportPath = sys_get_temp_dir() . '/wprism_regress_classify_' . bin2hex(random_bytes(6)) . '.json';
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

// Reviewed = class AND the field that class needs (issue #3496): an options row
// classed authored is incomplete until `autoload` says how the wp_options row
// is stored, and the batch below refuses it by name.
$reviewedBatch = ClassificationBatch::template('classify-fixture', $exportItems);
$reviewedBatch['decisions'][0]['class'] = 'authored';
$reviewedBatch['decisions'][0]['autoload'] = 'preserve';
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
    $applyReviewedEmpty->streamedArgs[0] === ['wprism', 'classify', '--repo=/fixture/repo', '--set=options:acme_flag=authored,autoload=preserve'],
    'the applied --set carries the reviewed storage decision through to the agent spec grammar'
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
    'format' => 'wprism-command-refusal/v1',
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

// A reviewed decision that authors a secret-flagged item keeps allow_secret
// on its own streamed row; it never widens into command-wide authority.
$secretApplyItems = [classify_item('options', 'api_key', 'authored', 'looks like an API key')];
$secretApplyPath = sys_get_temp_dir() . '/wprism_regress_classify_secret_' . bin2hex(random_bytes(6)) . '.json';
$secretBatch = ClassificationBatch::template('classify-fixture', $secretApplyItems);
$secretBatch['decisions'][0]['class'] = 'authored';
$secretBatch['decisions'][0]['allow_secret'] = true;
$secretBatch['decisions'][0]['autoload'] = 'preserve';
file_put_contents($secretApplyPath, ClassificationBatch::encode($secretBatch));
$applySecret = new ClassifyCommandDriver($secretApplyItems);
$applySecret->itemsByCall = [2 => []];
ob_start();
$applySecretExit = ClassifyCommand::run($applySecret, ["--apply-batch=$secretApplyPath"]);
ob_get_clean();
assert_classify_command($applySecretExit === 0, 'an allow_secret-confirmed authored decision applies successfully');
assert_classify_command(
    $applySecret->streamedArgs[0] === [
        'wprism',
        'classify',
        '--repo=/fixture/repo',
        '--set=options:api_key=authored,autoload=preserve,allow_secret=true',
    ],
    'allow_secret=true travels on exactly the reviewed row'
);
unlink($secretApplyPath);

$piiApplyItems = [classify_item('post_meta', 'contact_email', 'authored', null, 'email address')];
$piiApplyPath = sys_get_temp_dir() . '/wprism_regress_classify_pii_' . bin2hex(random_bytes(6)) . '.json';
$piiBatch = ClassificationBatch::template('classify-fixture', $piiApplyItems);
$piiBatch['decisions'][0]['class'] = 'authored';
$piiBatch['decisions'][0]['allow_pii'] = true;
file_put_contents($piiApplyPath, ClassificationBatch::encode($piiBatch));
$applyPii = new ClassifyCommandDriver($piiApplyItems);
$applyPii->itemsByCall = [2 => []];
ob_start();
$applyPiiExit = ClassifyCommand::run($applyPii, ["--apply-batch=$piiApplyPath"]);
ob_get_clean();
assert_classify_command($applyPiiExit === 0, 'an allow_pii-confirmed authored decision applies successfully');
assert_classify_command(
    $applyPii->streamedArgs[0] === [
        'wprism',
        'classify',
        '--repo=/fixture/repo',
        '--set=post_meta:contact_email=authored,allow_pii=true',
    ],
    'allow_pii=true travels on exactly the reviewed row'
);
unlink($piiApplyPath);

// The batch binding closes the host-side queue read, but the target must read
// live values again before writing. Reproduce that window with two rows: only
// the first was sensitive during review and carries authority; the second has
// become sensitive by the target read. The old command-wide flag cleared both.
$secretRaceItems = [
    classify_item('options', 'reviewed_gateway_api_key', null, 'hard:stripe key'),
    classify_item('options', 'raced_gateway_api_key'),
];
$secretRaceBatch = ClassificationBatch::template('classify-fixture', $secretRaceItems);
foreach ($secretRaceBatch['decisions'] as &$secretRaceDecision) {
    $secretRaceDecision['class'] = 'authored';
    $secretRaceDecision['autoload'] = 'preserve';
}
unset($secretRaceDecision);
$secretRaceBatch['decisions'][0]['allow_secret'] = true;
$secretRacePath = sys_get_temp_dir() . '/wprism_regress_classify_secret_race_' . bin2hex(random_bytes(6)) . '.json';
file_put_contents($secretRacePath, ClassificationBatch::encode($secretRaceBatch));
$secretRaceRepo = classify_fixture_repo();
$wpdb->seedTable('wp_options', [
    [
        'option_id' => 1,
        'option_name' => 'reviewed_gateway_api_key',
        'option_value' => 'sk_live_Reviewed1234567890',
        'autoload' => 'yes',
    ],
    [
        'option_id' => 2,
        'option_name' => 'raced_gateway_api_key',
        'option_value' => 'sk_live_Raced1234567890123',
        'autoload' => 'yes',
    ],
]);
$secretRace = new ClassifyCommandDriver($secretRaceItems);
$secretRace->agentRepo = $secretRaceRepo;
ob_start();
$secretRaceExit = ClassifyCommand::run($secretRace, ["--apply-batch=$secretRacePath"]);
ob_get_clean();
$secretRaceWritten = json_decode((string) file_get_contents($secretRaceRepo . '/site.wprism.json'), true);
assert_classify_command($secretRaceExit === 1, 'a second row that becomes secret at target reread refuses');
assert_classify_command(
    $secretRace->streamedArgs[0] === [
        'wprism',
        'classify',
        '--repo=' . $secretRaceRepo,
        '--set=options:reviewed_gateway_api_key=authored,autoload=preserve,allow_secret=true;'
            . 'options:raced_gateway_api_key=authored,autoload=preserve',
    ],
    'secret authority remains attached only to the reviewed row across the host-to-agent protocol'
);
assert_classify_command(
    ($secretRaceWritten['policy']['options']['reviewed_gateway_api_key']['allow_secret'] ?? null) === true
        && !isset($secretRaceWritten['policy']['options']['raced_gateway_api_key']),
    'the reviewed secret row writes its exact exception while the raced sibling receives none'
);
unlink($secretRacePath);

$piiRaceItems = [
    classify_item('post_meta', 'reviewed_contact_email', null, null, 'email address'),
    classify_item('post_meta', 'raced_profile_value'),
];
$piiRaceBatch = ClassificationBatch::template('classify-fixture', $piiRaceItems);
foreach ($piiRaceBatch['decisions'] as &$piiRaceDecision) {
    $piiRaceDecision['class'] = 'authored';
}
unset($piiRaceDecision);
$piiRaceBatch['decisions'][0]['allow_pii'] = true;
$piiRacePath = sys_get_temp_dir() . '/wprism_regress_classify_pii_race_' . bin2hex(random_bytes(6)) . '.json';
file_put_contents($piiRacePath, ClassificationBatch::encode($piiRaceBatch));
$piiRaceRepo = classify_fixture_repo();
$wpdb->seedTable('wp_postmeta', [
    ['meta_id' => 1, 'post_id' => 10, 'meta_key' => 'reviewed_contact_email', 'meta_value' => 'reviewed@example.test'],
    ['meta_id' => 2, 'post_id' => 10, 'meta_key' => 'raced_profile_value', 'meta_value' => 'raced@example.test'],
]);
$piiRace = new ClassifyCommandDriver($piiRaceItems);
$piiRace->agentRepo = $piiRaceRepo;
ob_start();
$piiRaceExit = ClassifyCommand::run($piiRace, ["--apply-batch=$piiRacePath"]);
ob_get_clean();
$piiRaceWritten = json_decode((string) file_get_contents($piiRaceRepo . '/site.wprism.json'), true);
assert_classify_command($piiRaceExit === 1, 'a second row that becomes PII at target reread refuses');
assert_classify_command(
    $piiRace->streamedArgs[0] === [
        'wprism',
        'classify',
        '--repo=' . $piiRaceRepo,
        '--set=post_meta:reviewed_contact_email=authored,allow_pii=true;post_meta:raced_profile_value=authored',
    ],
    'PII authority remains attached only to the reviewed row across the host-to-agent protocol'
);
assert_classify_command(
    ($piiRaceWritten['policy']['post_meta']['reviewed_contact_email']['allow_pii'] ?? null) === true
        && !isset($piiRaceWritten['policy']['post_meta']['raced_profile_value']),
    'the reviewed PII row writes its exact exception while the raced sibling receives none'
);
unlink($piiRacePath);
$wpdb->seedTable('wp_options', []);
$wpdb->seedTable('wp_postmeta', []);

$staleBatchPath = sys_get_temp_dir() . '/wprism_regress_classify_stale_' . bin2hex(random_bytes(6)) . '.json';
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
    $acceptClean->streamedArgs[0] === ['wprism', 'classify', '--repo=/fixture/repo', '--set=options:auto_flag=runtime'],
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

$piiItems = [classify_item('post_meta', 'contact_email', 'authored', null, 'email address')];
$acceptPii = new ClassifyCommandDriver($piiItems);
ob_start();
$acceptPiiExit = ClassifyCommand::run($acceptPii, ['--accept-proposals']);
ob_get_clean();
assert_classify_command($acceptPiiExit === 2, 'a PII-flagged authored proposal exits 2, never silently applied');
assert_classify_command($acceptPii->streamCalls === 0, 'a PII-only queue never streams a classify call');

// A journal proposal reports which capability wrote the value on which
// surface (Journal::propose) — it cannot know how the wp_options row must be
// stored. Accepting `authored` for an options row would therefore have to
// invent the autoload the site grammar demands, so it is skipped and reported
// exactly like the secret set, rather than streamed and refused mid-batch by
// the target (issue #3496).
$storageProposal = [classify_item('options', 'legacy_banner', 'authored'), classify_item('post_meta', 'meta_key', 'authored')];
$acceptStorage = new ClassifyCommandDriver($storageProposal);
ob_start();
$acceptStorageExit = ClassifyCommand::run($acceptStorage, ['--accept-proposals']);
ob_get_clean();
assert_classify_command($acceptStorageExit === 2, 'an options row proposed authored exits 2 rather than accepting an incomplete decision');
assert_classify_command(
    $acceptStorage->streamCalls === 1
        && $acceptStorage->streamedArgs[0] === ['wprism', 'classify', '--repo=/fixture/repo', '--set=post_meta:meta_key=authored'],
    'the post_meta proposal in the same queue still applies: only the options row needs a field no proposal carries'
);

// ---------------------------------------------------------------------------
// issue #3496: interactive triage completes the same decision the batch does.
//
// Triage::run() takes its streams as arguments precisely so this is testable
// without a subprocess; only ClassifyCommand's own call site passes the real
// STDIN/STDOUT (that wiring stays proven by sandbox/tests/spike/
// cli_triage_smoke.sh, which pipes scripted stdin through the real
// `cli/wprism classify` subprocess).
// ---------------------------------------------------------------------------

/** @return array{0:array<string,mixed>,1:string} the Triage result and everything it printed */
function run_triage(array $items, string $keystrokes): array {
    $in = fopen('php://memory', 'r+');
    fwrite($in, $keystrokes);
    rewind($in);
    $out = fopen('php://memory', 'r+');
    $result = Triage::run($items, $in, $out);
    rewind($out);
    $printed = (string) stream_get_contents($out);
    fclose($in);
    fclose($out);
    return [$result, $printed];
}

[$triageAutoload, $triageAutoloadOut] = run_triage([classify_item('options', 'acme_flag')], "a\npreserve\n");
assert_classify_command(
    $triageAutoload['decisions'] === [['section' => 'options', 'key' => 'acme_flag', 'class' => 'authored', 'autoload' => 'preserve']],
    'choosing authored for an options row asks for autoload and records the answer in the same decision'
);
assert_classify_command(
    str_contains($triageAutoloadOut, '-> options:acme_flag = authored,autoload=preserve'),
    'the echoed decision shows the storage flag the operator just chose'
);

[$triageRequired, ] = run_triage([classify_item('options', 'acme_env')], "e\nfalse\n");
assert_classify_command(
    $triageRequired['decisions'] === [['section' => 'options', 'key' => 'acme_env', 'class' => 'env', 'required' => false]],
    'choosing env asks for required and records the boolean, false included'
);

[$triageReask, $triageReaskOut] = run_triage([classify_item('options', 'acme_flag')], "a\nmaybe\nyes\n");
assert_classify_command(
    ($triageReask['decisions'][0]['autoload'] ?? null) === 'yes' && str_contains($triageReaskOut, "(unrecognized: 'maybe')"),
    'an unrecognized autoload is re-asked, never defaulted'
);

[$triageSkip, ] = run_triage([classify_item('options', 'acme_flag')], "a\ns\n");
assert_classify_command(
    $triageSkip['decisions'] === [] && $triageSkip['skipped'] === 1,
    'declining the storage decision skips the item instead of classifying it half-way'
);

[$triageEof, ] = run_triage([classify_item('options', 'acme_flag')], "a\n");
assert_classify_command(
    $triageEof['decisions'] === [] && $triageEof['quit'] === true,
    'stdin ending at the storage prompt quits with nothing partially decided'
);

[$triageMeta, ] = run_triage([classify_item('post_meta', 'acme_meta')], "a\n");
assert_classify_command(
    $triageMeta['decisions'] === [['section' => 'post_meta', 'key' => 'acme_meta', 'class' => 'authored']],
    'a post_meta row is never asked: the site grammar reads neither field there'
);

[$triagePii, $triagePiiOut] = run_triage(
    [classify_item('post_meta', 'contact_email', null, null, 'email address')],
    "a\nallow\n"
);
assert_classify_command(
    $triagePii['needAllowPii'] === true
        && $triagePii['decisions'] === [['section' => 'post_meta', 'key' => 'contact_email', 'class' => 'authored']],
    'interactive PII approval records one target-side allow-pii requirement'
);
assert_classify_command(
    str_contains($triagePiiOut, 'PII: email address') && str_contains($triagePiiOut, 'Type "allow"'),
    'interactive review names the redacted PII class and requires literal approval'
);

// ---------------------------------------------------------------------------
// issue #3496 end to end, through the real agent: a batch that applies clean
// scans clean, and one that is incomplete never reaches site.wprism.json.
// ---------------------------------------------------------------------------

// The requirement matrix, read from the validator rather than from its error
// messages: exactly these (section, class) pairs are refused without a second
// field. If a later change adds one to post_meta, this fails and says so —
// the batch would silently stop being able to express a complete decision
// again.
$requirementMatrix = [];
foreach (['options', 'post_meta', 'term_meta', 'user_meta'] as $matrixSection) {
    foreach (\WPrism\Policy::CLASSES as $matrixClass) {
        try {
            \WPrism\SitePolicyValidator::validate(
                ['policy' => [$matrixSection => ['k' => ['class' => $matrixClass]]]],
                'site.wprism.json',
                \WPrism\Policy::CLASSES,
                ['block', 'warn']
            );
        } catch (\Throwable $e) {
            $requirementMatrix[] = "$matrixSection/$matrixClass";
        }
    }
}
assert_classify_command(
    $requirementMatrix === ['options/authored', 'options/env', 'options/managed'],
    'only options authored/managed (autoload) and options env (required) need a field beyond class'
);

$e2eRepo = classify_fixture_repo();
$e2eItems = [
    classify_item('options', 'acme_flag'),
    classify_item('options', 'acme_env'),
    classify_item('post_meta', 'acme_meta'),
];
$e2ePath = sys_get_temp_dir() . '/wprism_regress_classify_e2e_' . bin2hex(random_bytes(6)) . '.json';
$e2eExport = new ClassifyCommandDriver($e2eItems);
$e2eExport->agentRepo = $e2eRepo;
ob_start();
$e2eExportExit = ClassifyCommand::run($e2eExport, ["--export-batch=$e2ePath"]);
$e2eExportOutput = (string) ob_get_clean();
assert_classify_command($e2eExportExit === 0, 'the end-to-end export succeeds');
assert_classify_command(
    str_contains($e2eExportOutput, 'an options row classed authored or managed also needs autoload')
        && str_contains($e2eExportOutput, 'one classed env needs required'),
    'the export names the per-class fields while the reviewer still has the file open'
);
$e2eBatch = json_decode((string) file_get_contents($e2ePath), true);
assert_classify_command(
    ($e2eBatch['format'] ?? null) === 'wprism-classification-batch/v3',
    'the exported artifact declares the v3 shape that carries storage and PII decisions'
);

// Class only: exactly what a reviewer could produce from a v1-shaped form.
$e2eBatch['decisions'][0]['class'] = 'authored';
$e2eBatch['decisions'][1]['class'] = 'env';
$e2eBatch['decisions'][2]['class'] = 'authored';
file_put_contents($e2ePath, ClassificationBatch::encode($e2eBatch));
$siteBefore = (string) file_get_contents($e2eRepo . '/site.wprism.json');
$e2eIncomplete = new ClassifyCommandDriver($e2eItems);
$e2eIncomplete->agentRepo = $e2eRepo;
ob_start();
$e2eIncompleteExit = ClassifyCommand::run($e2eIncomplete, ["--apply-batch=$e2ePath"]);
ob_get_clean();
assert_classify_command($e2eIncompleteExit === 1, 'a class-only batch refuses');
assert_classify_command($e2eIncomplete->streamCalls === 0, 'the refusal happens on the host: no remote write is opened at all');
assert_classify_command(
    (string) file_get_contents($e2eRepo . '/site.wprism.json') === $siteBefore,
    'site.wprism.json is byte-identical after the refusal'
);

// Completed: every field the chosen class needs.
$e2eBatch['decisions'][0]['autoload'] = 'preserve';
$e2eBatch['decisions'][1]['required'] = true;
file_put_contents($e2ePath, ClassificationBatch::encode($e2eBatch));
$e2eApply = new ClassifyCommandDriver($e2eItems);
$e2eApply->agentRepo = $e2eRepo;
$e2eApply->itemsByCall = [2 => []];
ob_start();
$e2eApplyExit = ClassifyCommand::run($e2eApply, ["--apply-batch=$e2ePath"]);
$e2eApplyOutput = (string) ob_get_clean();
assert_classify_command($e2eApplyExit === 0, 'the completed batch applies through the real agent handler');
$written = json_decode((string) file_get_contents($e2eRepo . '/site.wprism.json'), true);
// Key order is Canon's, not the writer's — these are the canonical bytes.
assert_classify_command(
    ($written['policy']['options']['acme_flag'] ?? null) === ['autoload' => 'preserve', 'class' => 'authored']
        && ($written['policy']['options']['acme_env'] ?? null) === ['class' => 'env', 'required' => true]
        && ($written['policy']['post_meta']['acme_meta'] ?? null) === ['class' => 'authored'],
    'the agent wrote each reviewed decision, with the boolean still a boolean after the string spec transport'
);
// Policy::load() is the first thing Pending::scan() does
// (agent/src/Review/Pending.php:37) and is exactly where the live-observed
// refusal came from. It has to be silent now.
\WPrism\Policy::load($e2eRepo);
assert_classify_command(true, 'the site.wprism.json a clean batch produced loads without a refusal — the batch that applies clean scans clean');
unlink($e2ePath);

// ---------------------------------------------------------------------------
// The agent's own write boundary (Policy::set_rule), reached the way an
// operator reaches it: a hand-run `wp wprism classify --set`. The batch is not
// the only door into this file, so the completeness gate cannot live only in
// the batch — every one of these used to write a site.wprism.json that the next
// command refused to load.
// ---------------------------------------------------------------------------

/** @return array{0:?string,1:bool} the refusal message (null when it wrote) and whether site.wprism.json moved */
function run_agent_classify(string $set): array {
    $repo = classify_fixture_repo();
    $before = (string) file_get_contents($repo . '/site.wprism.json');
    try {
        (new \WPrism\Cli())->classify([], ['repo' => $repo, 'set' => $set]);
        $message = null;
    } catch (\Throwable $e) {
        $message = $e->getMessage();
    }
    return [$message, (string) file_get_contents($repo . '/site.wprism.json') !== $before];
}

[$authoredMessage, $authoredMoved] = run_agent_classify('options:legacy_banner=authored');
assert_classify_command(
    $authoredMessage === 'wprism: site.wprism.json options.legacy_banner needs autoload=preserve or an explicit supported autoload value (yes|no|auto|on|off|auto-on|auto-off); insertion may never guess',
    'a classify spec with no autoload refuses in the loader\'s own words, at the write boundary'
);
assert_classify_command(!$authoredMoved, 'and site.wprism.json is untouched, so nothing has to be repaired by hand');

[$envMessage, $envMoved] = run_agent_classify('options:legacy_banner=env');
assert_classify_command(
    is_string($envMessage) && str_contains($envMessage, 'options.legacy_banner.class="env" needs an explicit boolean \'required\''),
    'an env spec with no required refuses the same way'
);
assert_classify_command(!$envMoved, 'and leaves site.wprism.json untouched too');

[$badRequired, ] = run_agent_classify('options:legacy_banner=env,required=maybe');
assert_classify_command(
    $badRequired === "wprism: bad required='maybe' in --set spec 'options:legacy_banner=env,required=maybe' (expected required=true or required=false)",
    'the spec grammar takes only the two spellings that are a decision, never a truthy guess'
);
[$inertOnMeta, ] = run_agent_classify('post_meta:legacy_meta=authored,autoload=preserve');
assert_classify_command(
    $inertOnMeta === 'wprism: autoload and required are valid only for options rules',
    'autoload on a post_meta rule refuses rather than being written where nothing reads it'
);
[$inertOnRuntime, ] = run_agent_classify('options:legacy_banner=runtime,autoload=yes');
assert_classify_command(
    is_string($inertOnRuntime) && str_contains($inertOnRuntime, 'options.legacy_banner declares autoload with class=runtime'),
    'autoload on a runtime option rule refuses for the same reason'
);
[$unknownField, ] = run_agent_classify('options:legacy_banner=authored,storage=yes');
assert_classify_command(
    is_string($unknownField)
        && str_contains($unknownField, '(expected ref=|cast=|autoload=|required=|allow_secret=|allow_pii=)'),
    'the unknown-option refusal names the whole accepted field set'
);

$legacyGlobalRepo = classify_fixture_repo();
$legacyGlobalBefore = (string) file_get_contents($legacyGlobalRepo . '/site.wprism.json');
try {
    (new \WPrism\Cli())->classify([], [
        'repo' => $legacyGlobalRepo,
        'set' => 'post_meta:first=runtime;post_meta:second=runtime',
        'allow-secret' => true,
    ]);
    $legacyGlobalMessage = null;
} catch (Throwable $error) {
    $legacyGlobalMessage = $error->getMessage();
}
assert_classify_command(
    is_string($legacyGlobalMessage) && str_contains($legacyGlobalMessage, 'valid only for a single --set row'),
    'a legacy command-wide clearance flag refuses on a multi-row set'
);
assert_classify_command(
    (string) file_get_contents($legacyGlobalRepo . '/site.wprism.json') === $legacyGlobalBefore,
    'the ambiguous legacy multi-row clearance refuses before writing any row'
);

$piiRepo = classify_fixture_repo();
$wpdb->seedTable('wp_options', [[
    'option_id' => 1,
    'option_name' => 'contact_email',
    'option_value' => 'person@example.test',
    'autoload' => 'yes',
]]);
try {
    (new \WPrism\Cli())->classify([], [
        'repo' => $piiRepo,
        'set' => 'options:contact_email=authored,autoload=preserve',
    ]);
    $piiRefusal = null;
} catch (Throwable $error) {
    $piiRefusal = $error->getMessage();
}
assert_classify_command(
    is_string($piiRefusal) && str_contains($piiRefusal, 'pass --allow-pii to override'),
    'the agent refuses an authored PII-shaped current value without exact review'
);
(new \WPrism\Cli())->classify([], [
    'repo' => $piiRepo,
    'set' => 'options:contact_email=authored,autoload=preserve',
    'allow-pii' => true,
]);
$piiWritten = json_decode((string) file_get_contents($piiRepo . '/site.wprism.json'), true);
assert_classify_command(
    ($piiWritten['policy']['options']['contact_email']['allow_pii'] ?? null) === true,
    '--allow-pii records a boolean exception on only the exact authored rule'
);
$wpdb->seedTable('wp_options', []);

// The site-level default is the other legitimate way the autoload question is
// already answered, and the write boundary honours it instead of demanding a
// redundant per-row flag: the probe carries the document's own
// policy.option_autoload, exactly as SitePolicyValidator does at load time.
$defaultRepo = classify_fixture_repo();
$defaultSite = json_decode((string) file_get_contents($defaultRepo . '/site.wprism.json'), true);
$defaultSite['policy']['option_autoload'] = 'preserve';
file_put_contents($defaultRepo . '/site.wprism.json', json_encode($defaultSite, JSON_PRETTY_PRINT));
(new \WPrism\Cli())->classify([], ['repo' => $defaultRepo, 'set' => 'options:legacy_banner=authored']);
$defaultWritten = json_decode((string) file_get_contents($defaultRepo . '/site.wprism.json'), true);
assert_classify_command(
    ($defaultWritten['policy']['options']['legacy_banner'] ?? null) === ['class' => 'authored'],
    'a site that already declares policy.option_autoload accepts an authored rule with no per-row flag'
);
\WPrism\Policy::load($defaultRepo);
assert_classify_command(true, 'and that document loads, which is the only test that matters for the scoping choice');

// A row left incomplete by the defect must not block the classify that
// repairs a DIFFERENT key: the write-boundary probe is scoped to the one rule
// being written, not to the whole policy.
$legacyRepo = classify_fixture_repo();
$legacySite = json_decode((string) file_get_contents($legacyRepo . '/site.wprism.json'), true);
$legacySite['policy']['options'] = ['already_broken' => ['class' => 'authored']];
file_put_contents($legacyRepo . '/site.wprism.json', json_encode($legacySite, JSON_PRETTY_PRINT));
(new \WPrism\Cli())->classify([], ['repo' => $legacyRepo, 'set' => 'options:legacy_banner=authored,autoload=preserve']);
$legacyWritten = json_decode((string) file_get_contents($legacyRepo . '/site.wprism.json'), true);
assert_classify_command(
    isset($legacyWritten['policy']['options']['legacy_banner']['autoload']),
    'an already-incomplete row elsewhere in the document does not block repairing another key'
);

echo "PASS: classify command\n";
