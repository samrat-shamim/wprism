<?php
/**
 * Offline regression for the queue-bound reviewed-classification artifact.
 * No WordPress or transport is required: this is the entire fail-closed
 * boundary that must run before the orchestrator opens a remote write path.
 */

require_once __DIR__ . '/../../../../cli/src/Onboarding/ClassificationBatch.php';

use Duo\Orchestrator\ClassificationBatch;

function fail(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function ok(bool $condition, string $message): void {
    if (!$condition) {
        fail($message);
    }
    echo "PASS: $message\n";
}

function refuses(callable $fn, string $needle, string $message): void {
    try {
        $fn();
    } catch (Throwable $e) {
        ok(str_contains($e->getMessage(), $needle), "$message (named refusal)");
        return;
    }
    fail("$message (unexpectedly accepted)");
}

function reviewed(array $items): array {
    $batch = ClassificationBatch::template('production', $items);
    foreach ($batch['decisions'] as &$row) {
        $row['class'] = $row['section'] === 'scope' ? 'runtime' : 'authored';
    }
    unset($row);
    return $batch;
}

$items = [
    [
        'section' => 'options',
        'key' => 'legacy_banner',
        'proposal' => null,
        'evidence' => ['reason' => 'unclassified option', 'owners' => ['legacy-plugin']],
        'value' => 'MUST-NOT-LEAK-IN-REVIEW-ARTIFACT',
    ],
    [
        'section' => 'post_meta',
        'key' => '_legacy_runtime_id',
        'proposal' => null,
        'evidence' => ['count' => 91, 'value_shapes' => ['integer-string']],
        'ref_hint' => ['kind' => 'post', 'id' => 42],
    ],
    [
        'section' => 'scope',
        'key' => 'post_type:legacy_record',
        'proposal' => null,
        'evidence' => ['count' => 17],
    ],
];

$template = ClassificationBatch::template('production', $items);
$encoded = ClassificationBatch::encode($template);
ok(!str_contains($encoded, 'MUST-NOT-LEAK'), 'export contains redacted evidence, never pending live values');
ok($template['queue_sha256'] === ClassificationBatch::queueHash($items), 'export binds the exact pending queue digest');

$reordered = $items;
$reordered[0] = array_reverse($reordered[0], true);
ok(
    ClassificationBatch::queueHash($reordered) === ClassificationBatch::queueHash($items),
    'queue digest ignores associative insertion order'
);

$batch = reviewed($items);
$batch['decisions'][1]['ref'] = 'post';
$batch['decisions'][1]['cast'] = 'string';
$valid = ClassificationBatch::validate($batch, 'production', $items);
ok(count($valid['decisions']) === 3, 'complete reviewed batch validates as one decision set');
ok($valid['decisions'][1]['ref'] === 'post', 'reviewed ref is preserved');
ok($valid['decisions'][1]['cast'] === 'string', 'reviewed cast is preserved');
ok($valid['needAllowSecret'] === false, 'ordinary batch does not request the secret escape hatch');

$partial = $batch;
$partial['decisions'][0]['class'] = null;
refuses(
    fn() => ClassificationBatch::validate($partial, 'production', $items),
    'incomplete',
    'partial review refuses before apply'
);

$changed = $items;
$changed[] = ['section' => 'options', 'key' => 'late_write', 'evidence' => ['count' => 1]];
refuses(
    fn() => ClassificationBatch::validate($batch, 'production', $changed),
    'stale',
    'queue change after export refuses the stale batch'
);
refuses(
    fn() => ClassificationBatch::validate($batch, 'production', []),
    'stale',
    'queue cleared after export still refuses the stale batch'
);
refuses(
    fn() => ClassificationBatch::validate($batch, 'staging', $items),
    "not 'staging'",
    'environment mismatch refuses cross-target reuse'
);

$duplicate = $batch;
$duplicate['decisions'][] = $duplicate['decisions'][0];
refuses(
    fn() => ClassificationBatch::validate($duplicate, 'production', $items),
    'duplicate',
    'duplicate decision refuses'
);

$unsupportedItems = [['section' => 'custom_table', 'key' => 'legacy_rows', 'evidence' => ['count' => 3]]];
$unsupported = reviewed($unsupportedItems);
refuses(
    fn() => ClassificationBatch::validate($unsupported, 'production', $unsupportedItems),
    'manifest/schema change',
    'non-policy surface refuses instead of pretending classification can cover it'
);

$secretItems = [[
    'section' => 'options',
    'key' => 'legacy_api_key',
    'secret' => 'hard:api-key',
    'evidence' => ['reason' => 'unclassified option'],
]];
$secret = reviewed($secretItems);
refuses(
    fn() => ClassificationBatch::validate($secret, 'production', $secretItems),
    'set allow_secret=true',
    'authored secret requires explicit per-row review'
);
$secret['decisions'][0]['allow_secret'] = true;
$secretValid = ClassificationBatch::validate($secret, 'production', $secretItems);
ok($secretValid['needAllowSecret'] === true, 'explicit secret review requests one batched allow-secret flag');
$secret['decisions'][0]['class'] = 'runtime';
refuses(
    fn() => ClassificationBatch::validate($secret, 'production', $secretItems),
    'sets allow_secret without',
    'secret override refuses when it is not required'
);

$badRef = $batch;
$badRef['decisions'][0]['ref'] = 'comment';
refuses(
    fn() => ClassificationBatch::validate($badRef, 'production', $items),
    'invalid ref',
    'invalid ref refuses before the remote write starts'
);
$badCast = $batch;
$badCast['decisions'][0]['cast'] = 'integer';
refuses(
    fn() => ClassificationBatch::validate($badCast, 'production', $items),
    'invalid cast',
    'invalid cast refuses before the remote write starts'
);
$scopeExtra = $batch;
$scopeExtra['decisions'][2]['ref'] = 'post';
refuses(
    fn() => ClassificationBatch::validate($scopeExtra, 'production', $items),
    'accepts class only',
    'scope extras refuse before the remote write starts'
);

$unsafeItems = [['section' => 'options', 'key' => 'contains;separator', 'evidence' => []]];
$unsafe = reviewed($unsafeItems);
refuses(
    fn() => ClassificationBatch::validate($unsafe, 'production', $unsafeItems),
    'semicolon-joined',
    'keys unsafe for the agent set grammar refuse explicitly'
);

echo "PASS: classification batch regression complete\n";
