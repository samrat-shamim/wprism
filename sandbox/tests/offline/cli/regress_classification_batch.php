<?php
/**
 * Offline regression for the queue-bound reviewed-classification artifact.
 * No WordPress or transport is required: this is the entire fail-closed
 * boundary that must run before the orchestrator opens a remote write path.
 */

require_once __DIR__ . '/../../../../cli/src/Onboarding/ClassificationBatch.php';

use WPrism\Orchestrator\ClassificationBatch;

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

/**
 * A completely reviewed batch: every class filled, and — since issue #3496 —
 * the field the site grammar demands of the class chosen. An options row
 * classed authored without `autoload` is not a complete decision, so a helper
 * that produced one would be asserting against a batch the agent refuses.
 */
function reviewed(array $items): array {
    $batch = ClassificationBatch::template('production', $items);
    foreach ($batch['decisions'] as &$row) {
        $row['class'] = $row['section'] === 'scope' ? 'runtime' : 'authored';
        if ($row['section'] === 'options') {
            $row['autoload'] = 'preserve';
        }
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
ok(
    array_key_exists('allow_pii', $template['decisions'][0])
        && $template['decisions'][0]['allow_pii'] === false,
    'every exported row presents an explicit reviewed PII decision'
);
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
ok(
    !array_key_exists('allow_secret', $valid['decisions'][0])
        && !array_key_exists('allow_pii', $valid['decisions'][0]),
    'ordinary rows carry no clearance authority'
);

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
ok(
    ($secretValid['decisions'][0]['allow_secret'] ?? null) === true,
    'explicit secret review remains authority on that exact validated row'
);
$secret['decisions'][0]['class'] = 'runtime';
refuses(
    fn() => ClassificationBatch::validate($secret, 'production', $secretItems),
    'sets allow_secret without',
    'secret override refuses when it is not required'
);

$piiItems = [[
    'section' => 'post_meta',
    'key' => 'contact_email',
    'pii' => 'email address',
    'evidence' => ['reason' => 'unclassified post meta'],
]];
$pii = reviewed($piiItems);
refuses(
    fn() => ClassificationBatch::validate($pii, 'production', $piiItems),
    'set allow_pii=true',
    'authored PII requires explicit per-row review'
);
$pii['decisions'][0]['allow_pii'] = true;
$piiValid = ClassificationBatch::validate($pii, 'production', $piiItems);
ok(
    ($piiValid['decisions'][0]['allow_pii'] ?? null) === true,
    'explicit PII review remains authority on that exact validated row'
);
$pii['decisions'][0]['class'] = 'runtime';
refuses(
    fn() => ClassificationBatch::validate($pii, 'production', $piiItems),
    'sets allow_pii without',
    'PII override refuses when it is not required'
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

// ---------------------------------------------------------------------------
// issue #3496: the row must be able to carry a COMPLETE decision, and an
// incomplete one must refuse here rather than on the target.
//
// Live-observed on main 273ce5b: a fully-filled v1 batch applied, and the
// very next `wprism pending` refused the site.wprism.json it had just written —
// once per class, because agent/src/Grammar/OptionGrammar.php demands
// `autoload` on an options rule classed authored or managed (:76-96) and a
// boolean `required` on one classed env (:42-56). Both requirements are
// options-only; the same matrix run against the real SitePolicyValidator in
// sandbox/tests/offline/cli/regress_classify_command.php proves post_meta,
// term_meta and user_meta need no second field, which is why only an options
// row carries these two.
// ---------------------------------------------------------------------------

$optionItems = [['section' => 'options', 'key' => 'acme_flag', 'evidence' => ['count' => 2]]];
$optionTemplate = ClassificationBatch::template('production', $optionItems);
ok(
    array_key_exists('autoload', $optionTemplate['decisions'][0])
        && $optionTemplate['decisions'][0]['autoload'] === null
        && array_key_exists('required', $optionTemplate['decisions'][0])
        && $optionTemplate['decisions'][0]['required'] === null,
    'an exported options row offers autoload and required, both null — the exporter never prefills a decision'
);
$metaTemplate = ClassificationBatch::template('production', [['section' => 'post_meta', 'key' => 'k', 'evidence' => []]]);
ok(
    !array_key_exists('autoload', $metaTemplate['decisions'][0])
        && !array_key_exists('required', $metaTemplate['decisions'][0]),
    'a post_meta row carries neither field: nothing in the site grammar reads them there'
);

$authoredNoAutoload = $optionTemplate;
$authoredNoAutoload['decisions'][0]['class'] = 'authored';
refuses(
    fn() => ClassificationBatch::validate($authoredNoAutoload, 'production', $optionItems),
    'options:acme_flag class=authored needs autoload=preserve or an explicit supported autoload value (yes|no|auto|on|off|auto-on|auto-off); insertion may never guess',
    'an authored options row with autoload still null refuses, naming the row and the field'
);
$managedNoAutoload = $optionTemplate;
$managedNoAutoload['decisions'][0]['class'] = 'managed';
refuses(
    fn() => ClassificationBatch::validate($managedNoAutoload, 'production', $optionItems),
    'options:acme_flag class=managed needs autoload=',
    'managed carries the same storage requirement as authored'
);
$envNoRequired = $optionTemplate;
$envNoRequired['decisions'][0]['class'] = 'env';
refuses(
    fn() => ClassificationBatch::validate($envNoRequired, 'production', $optionItems),
    'options:acme_flag class=env needs an explicit boolean required',
    'an env options row with required still null refuses, naming the row and the field'
);

foreach (['preserve', 'yes', 'no', 'auto', 'on', 'off', 'auto-on', 'auto-off'] as $flag) {
    $filled = $optionTemplate;
    $filled['decisions'][0]['class'] = 'authored';
    $filled['decisions'][0]['autoload'] = $flag;
    $result = ClassificationBatch::validate($filled, 'production', $optionItems);
    ok(
        ($result['decisions'][0]['autoload'] ?? null) === $flag,
        "a filled autoload=$flag validates and reaches the decision set intact"
    );
}
$filledRequiredFalse = $optionTemplate;
$filledRequiredFalse['decisions'][0]['class'] = 'env';
$filledRequiredFalse['decisions'][0]['required'] = false;
$requiredFalse = ClassificationBatch::validate($filledRequiredFalse, 'production', $optionItems);
ok(
    array_key_exists('required', $requiredFalse['decisions'][0]) && $requiredFalse['decisions'][0]['required'] === false,
    'required=false survives as a decision — the falsy answer is not the same as an unanswered field'
);

$stringRequired = $optionTemplate;
$stringRequired['decisions'][0]['class'] = 'env';
$stringRequired['decisions'][0]['required'] = 'true';
refuses(
    fn() => ClassificationBatch::validate($stringRequired, 'production', $optionItems),
    "options:acme_flag required must be true or false, not 'true'",
    'a stringy required refuses: the site grammar accepts only a real boolean'
);
$badAutoload = $optionTemplate;
$badAutoload['decisions'][0]['class'] = 'authored';
$badAutoload['decisions'][0]['autoload'] = 'maybe';
refuses(
    fn() => ClassificationBatch::validate($badAutoload, 'production', $optionItems),
    "invalid autoload 'maybe' for options:acme_flag",
    'an unsupported autoload value refuses before the remote write starts'
);

$inertAutoload = $optionTemplate;
$inertAutoload['decisions'][0]['class'] = 'runtime';
$inertAutoload['decisions'][0]['autoload'] = 'yes';
refuses(
    fn() => ClassificationBatch::validate($inertAutoload, 'production', $optionItems),
    'options:acme_flag sets autoload with class=runtime',
    'an autoload nothing will read refuses instead of recording an answer the target ignores'
);
$inertRequired = $optionTemplate;
$inertRequired['decisions'][0]['class'] = 'authored';
$inertRequired['decisions'][0]['autoload'] = 'preserve';
$inertRequired['decisions'][0]['required'] = true;
refuses(
    fn() => ClassificationBatch::validate($inertRequired, 'production', $optionItems),
    'options:acme_flag sets required with class=authored',
    'a required nothing will read refuses for the same reason'
);
$metaWithAutoload = reviewed([['section' => 'post_meta', 'key' => 'k', 'evidence' => []]]);
$metaWithAutoload['decisions'][0]['autoload'] = 'preserve';
refuses(
    fn() => ClassificationBatch::validate($metaWithAutoload, 'production', [['section' => 'post_meta', 'key' => 'k', 'evidence' => []]]),
    'post_meta:k sets autoload/required, which belong only to an options rule',
    'a hand-added storage field on a non-options row refuses rather than travelling to the agent'
);

// An unacknowledged authored secret still refuses first: that gate is about
// what leaves the site, and it must not be reachable only after a storage
// decision the operator may never make.
$secretUndecided = ClassificationBatch::template('production', $secretItems);
$secretUndecided['decisions'][0]['class'] = 'authored';
refuses(
    fn() => ClassificationBatch::validate($secretUndecided, 'production', $secretItems),
    'set allow_secret=true',
    'the secret gate is evaluated before the completeness gate on the same row'
);

// ---- the wire bump ---------------------------------------------------------

ok(ClassificationBatch::FORMAT === 'wprism-classification-batch/v3', 'the reviewed artifact is v3');
$staleV2 = reviewed($items);
$staleV2['format'] = ClassificationBatch::FORMAT_WITHOUT_PII_DECISIONS;
refuses(
    fn() => ClassificationBatch::validate($staleV2, 'production', $items),
    'predates the per-row allow_pii review decision; re-export the batch',
    'a v2 artifact refuses by name because its review form had no PII decision'
);
$staleV1 = reviewed($items);
$staleV1['format'] = ClassificationBatch::FORMAT_WITHOUT_STORAGE_DECISIONS;
refuses(
    fn() => ClassificationBatch::validate($staleV1, 'production', $items),
    'predates the per-class decision fields an options row must carry (autoload for authored/managed, required for env); re-export the batch',
    'a v1 artifact refuses by name with re-export as the remedy, never read as a decision'
);
refuses(
    fn() => ClassificationBatch::validate(['format' => 'wprism-classification-batch/v4'] + reviewed($items), 'production', $items),
    "unsupported classification batch format 'wprism-classification-batch/v4'",
    'an unknown format keeps the generic refusal'
);

// ---- the restated vocabulary cannot drift from the agent's ------------------
//
// The orchestrator never loads agent code, so this file's autoload vocabulary
// is a copy. Read the two agent sources and fail if they disagree: the batch
// refusing a value the target accepts (or the reverse) is exactly the class of
// drift a copied vocabulary produces.
$optionState = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Kernel/OptionState.php');
$optionGrammar = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Grammar/OptionGrammar.php');
preg_match("/const AUTOLOAD_VALUES = \[([^\]]*)\]/", $optionState, $valueMatch);
preg_match("/const OPTION_AUTOLOAD_SENTINELS = \[([^\]]*)\]/", $optionGrammar, $sentinelMatch);
$parseList = static function (string $raw): array {
    preg_match_all("/'([^']+)'/", $raw, $m);
    return $m[1];
};
ok(
    $parseList($valueMatch[1] ?? '') === ClassificationBatch::OPTION_AUTOLOAD_VALUES,
    'the batch autoload values match OptionState::AUTOLOAD_VALUES exactly'
);
ok(
    $parseList($sentinelMatch[1] ?? '') === ClassificationBatch::OPTION_AUTOLOAD_SENTINELS,
    'the batch autoload sentinel matches OptionGrammar::OPTION_AUTOLOAD_SENTINELS exactly'
);

echo "PASS: classification batch regression complete\n";
