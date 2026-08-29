<?php
/**
 * issue #3489: what an apply over a drifted target actually tells the operator,
 * and what its retry does to the drift it preserved.
 *
 * Measured live (pair.sh, source 06a7c6f): two entities drifted out of band on
 * a converged target, `wprism plan prod` correctly reported `drift=2`, and then
 *
 *   run 1: Error: wprism: post-apply convergence verification subprocess failed;
 *          promotion metadata was not committed        <- names nothing
 *   run 2: Success: applied 14 entities (canary clean), plan `drift:0`,
 *          `incomplete_apply:1`                        <- the drift is gone
 *
 * Two independent defects produced that pair, and this suite pins both through
 * the product path.
 *
 * (1) The gate cannot say why. ConvergenceVerifier::verify() always launches
 *     `wp wprism verify-canonical --format=json`, and issue #3399 (aa58959) routed
 *     that command through Cli::halt_json_failure(), which publishes the
 *     value-free envelope with WP_CLI::line() and WP_CLI::halt(1). STDERR —
 *     the only channel verify() read — was empty, so every convergence failure
 *     collapsed into one constant sentence, in direct contradiction of
 *     spec/repo-format.md:1235 ("A mismatch names the failed invariant").
 *
 * (2) The retry silently overwrites preserved drift. A normal apply leaves
 *     environment-only drift for capture (ApplyPlanner::rebuild_work():624-630
 *     excludes `drift` from the write set), but issue #3206's retry widening
 *     folded the whole `drift` bucket into `update` on the next run because
 *     the marker was the constant '1' and could not tell "a row we wrote,
 *     whose wprism_state is stale" from "a row we deliberately did not write".
 *
 * (3) issue #3491, the same shape one bucket over. The widening also drains
 *     `conflict`, and ApplyPreparationCoordinator.php:58 then sees an empty
 *     bucket — so a retry under the marker silently overrode three-way
 *     conflicts that a first apply refuses without --force-theirs. It is right
 *     only where issue #3206's premise holds (the failed run wrote the row, so
 *     its stale `wprism_state` base is the whole reason it reads three ways);
 *     for an identity that run never wrote — a preserved row whose repository
 *     side moved on a recompile, or a row that diverged after the marker was
 *     written — the conflict is genuine and stays in `conflict`. The evidence
 *     is the marker's own `write_set`, added in wire v2.
 *
 * Coverage is the real product code in all three cases: the shipped
 * ConvergenceVerifier driven through a WP_CLI stub reproducing the exact live
 * subprocess shape, the shipped Cli::verify_canonical() handler, and the
 * shipped pure planner projection that ApplyPlanBuilder now delegates to.
 */
declare(strict_types=1);

// Bracketed namespaces throughout: the `WPrism\Apply` backend stub below must be
// declared before Cli.php is required, and a stub for a class the shipped
// require graph never loads is the established idiom
// (sandbox/tests/offline/cli/regress_cli_json_refusals.php:47-99).
namespace {

// From offline/apply/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';
require_once __DIR__ . '/../../support/wp_cli_child_process_fake.php';

/** WP_CLI::halt() and WP_CLI::error() both end the process live; here they unwind. */
final class WPrismConvergenceHalt extends RuntimeException {}

final class WP_CLI {
    use \WPrismTest\WpCliChildRuntime;

    /** @var list<string> stdout, as WP_CLI::line() writes it */
    public static array $lines = [];
    /** @var list<string> stderr, as WP_CLI::error() writes it */
    public static array $errors = [];
    /** @var object|null the canned `wp wprism verify-canonical` ProcessRun */
    public static ?object $result = null;
    /** @var list<string> every launched subcommand */
    public static array $commands = [];

    public static function add_command($name, $class): void {}

    public static function runcommand($command, $options) {
        self::$commands[] = (string) $command;
        return self::$result;
    }

    public static function line($line): void {
        self::$lines[] = (string) $line;
    }

    public static function halt($status): void {
        throw new WPrismConvergenceHalt('halt:' . (int) $status);
    }

    public static function error($message, $exit = true): void {
        self::$errors[] = (string) $message;
        if ($exit !== false) {
            throw new WPrismConvergenceHalt('error');
        }
    }

    public static function success($message): void {}
    public static function warning($message): void {}

    public static function reset(): void {
        self::$lines = [];
        self::$errors = [];
        self::$commands = [];
    }
}

require_once __DIR__ . '/../../../../agent/src/Kernel/Secrets.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ConvergenceVerifier.php';
require_once __DIR__ . '/../../../../agent/src/Repository/CompiledArtifact.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyPlanner.php';
require_once __DIR__ . '/../../../../agent/src/Apply/IncompleteApplyMarker.php';

}

namespace WPrism {
    /**
     * Cli::verify_canonical()'s backend, stubbed for one purpose only: make
     * the real handler cross its own catch boundary with the exact Throwable
     * ConvergenceVerifier::verify_local() raises on a non-converged tree.
     * Nothing about the formatting, redaction, channel choice or exit
     * behaviour under test is stubbed.
     */
    final class Apply {
        public static ?\Throwable $verifyFailure = null;

        public static function verify_canonical($repo, array $options): array {
            if (self::$verifyFailure !== null) {
                throw self::$verifyFailure;
            }
            return ['verifier' => 'canonical-recapture/v1', 'result' => 'pass', 'live_entities' => 0, 'deletions' => 0];
        }
    }
}

namespace {

require_once __DIR__ . '/../../../../agent/src/Command/Cli.php';

use WPrism\Canon;
use WPrism\Cli;
use WPrism\CompiledRepository;
use WPrism\ConvergenceVerifier;
use WPrism\IncompleteApplyMarker;
use WPrism\Policy;
use WPrismTest\FrozenPolicy;

// ---------------------------------------------------------------- fixtures

$policy = FrozenPolicy::policy([], FrozenPolicy::site([]));
$compiled = CompiledRepository::create(['tree' => []]);

/** The two entities the live repro drifted, in plan `drift` row shape. */
$driftRows = [
    ['uuid' => 'options/core', 'type' => 'option', 'path' => 'state/options/core.json'],
    [
        'uuid' => '8f14e45f-ceea-467a-9a3e-1b2c3d4e5f60',
        'type' => 'post',
        'path' => 'state/posts/page/8f14e45f--team.md',
    ],
];

$processRun = static fn(string $stdout, string $stderr, int $code): object => (object) [
    'stdout' => $stdout,
    'stderr' => $stderr,
    'return_code' => $code,
];

/** The redacted envelope halt_json_failure() publishes for an unclassified verify-canonical throwable. */
$redactedEnvelope = json_encode([
    'format' => 'wprism-command-refusal/v1',
    'ok' => false,
    'command' => 'verify-canonical',
    'error' => 'verify_canonical_failed',
    'reason_code' => 'verify_canonical_failed',
    'message' => 'verify-canonical refused at an unclassified safety gate',
    'remediation' => 'inspect the parent apply frozen policy snapshot, compiled artifact, and expected artifact hash, then rerun verification from that exact apply',
    'details_redacted' => true,
], JSON_UNESCAPED_SLASHES);

/** verify_local()'s own operator sentence for the live repro's drifted page. */
$convergenceProse = "wprism: post-apply convergence verification failed; promotion metadata was not committed:\n"
    . '  - post state/posts/page/8f14e45f--team.md (8f14e45f-ceea-467a-9a3e-1b2c3d4e5f60): '
    . 'canonical hash mismatch (expected ' . str_repeat('a', 64) . ', observed ' . str_repeat('b', 64) . ')';

$verifyFailureMessage = static function (array $preservedDrift) use ($policy, $compiled): string {
    try {
        (new ConvergenceVerifier('/siterepo', $policy))->verify([], $compiled, $preservedDrift);
    } catch (\Throwable $failure) {
        return $failure->getMessage();
    }
    return '';
};

// ---- 1. The live run-1 shape: rc=1, EMPTY stderr, redacted envelope on
// stdout. Before the fix this produced one constant sentence naming nothing.
WP_CLI::reset();
WP_CLI::$result = $processRun($redactedEnvelope, '', 1);
$namelessCase = $verifyFailureMessage([]);

wprism_check(
    $namelessCase !== ''
        && $namelessCase !== 'wprism: post-apply convergence verification subprocess failed; promotion metadata was not committed',
    'a halted --format=json verifier subprocess no longer collapses into the constant "subprocess failed" sentence'
);
wprism_check(
    str_contains($namelessCase, 'verify_canonical_failed'),
    'the refusal names the subprocess reason code the envelope carried'
);
wprism_check(
    str_contains($namelessCase, 'remedy: inspect the parent apply frozen policy snapshot'),
    'the refusal carries the subprocess remediation instead of discarding the envelope'
);
wprism_check(
    str_contains($namelessCase, '.wprism/refusals/'),
    'a redacted envelope points the operator at the private evidence record that holds the detail'
);

// ---- 2. Head 2: every non-scoped convergence failure states whether the
// target changed. verify() has exactly one caller — ApplyRequestCoordinator's
// post-rebuild gate — so this is a fact, not a hedge.
foreach ([
    'redacted envelope' => $processRun($redactedEnvelope, '', 1),
    'restored operator prose' => $processRun('', "Error: $convergenceProse", 1),
    'malformed success evidence' => $processRun('{"verifier":', '', 0),
    'invalid success evidence' => $processRun('{"verifier":"other/v1","result":"pass"}', '', 0),
] as $label => $canned) {
    WP_CLI::reset();
    WP_CLI::$result = $canned;
    wprism_check(
        str_contains($verifyFailureMessage([]), 'The target WAS mutated'),
        "the $label failure answers \"did the target change?\" instead of leaving it to be inferred"
    );
}

// ---- 3. The restored stderr channel carries verify_local()'s own findings
// through verbatim — the bytes certify_merge.sh and spike_b_merge.sh assert.
WP_CLI::reset();
WP_CLI::$result = $processRun('', "Error: $convergenceProse", 1);
$proseCase = $verifyFailureMessage($driftRows);
wprism_check(
    str_contains($proseCase, 'post-apply convergence verification failed')
        && str_contains($proseCase, 'canonical hash mismatch')
        && str_contains($proseCase, 'state/posts/page/8f14e45f--team.md'),
    'operator prose on stderr survives verbatim: the gate name, the failed invariant, and the entity'
);

WP_CLI::reset();
$GLOBALS['wprism_wp_cli_child_fake_stderr_first'] = true;
WP_CLI::$result = $processRun(
    '{"verifier":"canonical-recapture/v1","result":"pass","live_entities":0,"deletions":0}',
    str_repeat('credential-shaped-warning-', 12000),
    0
);
$boundedWarning = $verifyFailureMessage([]);
wprism_check(
    str_contains($boundedWarning, 'subprocess failed')
        && !str_contains($boundedWarning, 'credential-shaped'),
    'convergence verifier bounds stderr-first child output through its product path without leaking it'
);
$GLOBALS['wprism_wp_cli_child_fake_stderr_first'] = false;

// ---- 4. Head 1's structural cause: this apply preserved drift, the gate
// proves the whole tree, so the failure is guaranteed. Name it and name the
// documented remedy.
wprism_check(
    str_contains($proseCase, 'preserved 2 environment-drifted entities'),
    'the refusal names how many entities this apply deliberately did not overwrite'
);
foreach ($driftRows as $row) {
    wprism_check(
        str_contains($proseCase, $row['path']),
        "the refusal names the preserved entity {$row['path']}"
    );
}
wprism_check(
    str_contains($proseCase, 'Run `wprism capture`'),
    'the refusal names capture-first, the remedy docs/guides/capabilities-and-limits.md already documents for ordinary drift'
);
wprism_check(
    !str_contains($verifyFailureMessage([]), 'environment-drifted'),
    'an apply with no preserved drift makes no drift claim — the cause is reported, never assumed'
);

// ---- 5. The producer side: verify-canonical in --format=json must put its
// operator sentence on STDERR (where its only machine caller reads it) while
// the value-free envelope stays the one value on STDOUT.
$cli = new Cli();
WP_CLI::reset();
\WPrism\Apply::$verifyFailure = new RuntimeException($convergenceProse);
$halted = false;
try {
    $cli->verify_canonical([], [
        'repo' => '/siterepo',
        'expected-artifact' => str_repeat('a', 64),
        'compiled' => '/tmp/wprism-artifact.json',
        'policy-snapshot' => '/tmp/wprism-policy.json',
        'format' => 'json',
    ]);
} catch (WPrismConvergenceHalt $stop) {
    $halted = true;
}
wprism_check($halted, 'a refused --format=json verify-canonical still halts non-zero');
wprism_check(
    in_array($convergenceProse, WP_CLI::$errors, true),
    'the failed-invariant sentence reaches STDERR, the channel apply\'s own verifier reads'
);
wprism_check_same(1, count(WP_CLI::$lines), 'STDOUT still carries exactly one value: the refusal envelope');
$envelope = json_decode(WP_CLI::$lines[0] ?? '', true);
wprism_check_same(
    'wprism-command-refusal/v1',
    $envelope['format'] ?? null,
    'the machine envelope on STDOUT is unchanged'
);
wprism_check(
    !str_contains(WP_CLI::$lines[0] ?? '', 'state/posts/page/'),
    'the JSON envelope stays value-free: no repository path crosses into the machine contract'
);

// Human mode is untouched: one sentence on stderr, nothing on stdout.
WP_CLI::reset();
try {
    $cli->verify_canonical([], [
        'repo' => '/siterepo',
        'expected-artifact' => str_repeat('a', 64),
        'compiled' => '/tmp/wprism-artifact.json',
        'policy-snapshot' => '/tmp/wprism-policy.json',
    ]);
} catch (WPrismConvergenceHalt $stop) {
}
wprism_check_same([], WP_CLI::$lines, 'human-mode verify-canonical writes nothing to STDOUT on refusal');
wprism_check_same([$convergenceProse], WP_CLI::$errors, 'human-mode verify-canonical keeps its exact prior sentence');
\WPrism\Apply::$verifyFailure = null;

// ---------------------------------------------------------------- head 3

// ---- 6. The marker carries what one bit could not.
//
// issue #3491: the write set is the second half of that record. `both-changed`
// below is issue #3206's own case — the interrupted run wrote it, so the retry's
// three-way reading of it is that run's stale `wprism_state` base, not a real
// divergence. `written-after-commit` is the row the failed run wrote that
// re-reads as `unchanged`.
$writeSet = [
    ['uuid' => 'written-after-commit', 'type' => 'post', 'path' => 'state/posts/page/written.md'],
    ['uuid' => 'both-changed', 'type' => 'post', 'path' => 'state/posts/page/both.md'],
];
$encoded = IncompleteApplyMarker::encode($driftRows, $writeSet);
$decoded = IncompleteApplyMarker::preserved_drift($encoded);
wprism_check_same(
    ['8f14e45f-ceea-467a-9a3e-1b2c3d4e5f60', 'options/core'],
    array_keys($decoded ?? []),
    'the marker records every preserved-drift identity, in a stable order'
);
wprism_check_same(
    'state/posts/page/8f14e45f--team.md',
    $decoded['8f14e45f-ceea-467a-9a3e-1b2c3d4e5f60']['path'] ?? null,
    'each recorded identity keeps the path plan and apply report it by'
);
wprism_check_same(
    $encoded,
    IncompleteApplyMarker::encode(array_reverse($driftRows), array_reverse($writeSet)),
    'the marker value is order-independent'
);
wprism_check_same(null, IncompleteApplyMarker::preserved_drift('1'), "issue #3206's bare '1' marker records nothing");
wprism_check_same(null, IncompleteApplyMarker::preserved_drift('{not json'), 'an unreadable marker records nothing and does not throw');
wprism_check_same(null, IncompleteApplyMarker::preserved_drift(null), 'no marker records nothing');
wprism_check_same(
    [],
    IncompleteApplyMarker::preserved_drift(IncompleteApplyMarker::encode([], [])),
    'an apply that preserved no drift still writes a marker, with an empty record'
);

// ---- 6b. issue #3491: the write set, and what each wire version claims.
wprism_check_same('wprism-apply-in-progress/v2', IncompleteApplyMarker::FORMAT, 'the write-set record is a new wire version, not a field smuggled into v1');
wprism_check_same('wprism-apply-in-progress/v1', IncompleteApplyMarker::FORMAT_V1, "issue #3489's wire name is still spelled out, because targets interrupted under 9b440c3 still hold it");
wprism_check_same(
    ['both-changed', 'written-after-commit'],
    array_keys(IncompleteApplyMarker::write_set($encoded) ?? []),
    'the marker records every identity the run was authorized to write, in a stable order'
);
wprism_check_same(
    $encoded,
    IncompleteApplyMarker::encode($driftRows, array_merge($writeSet, [$writeSet[0]])),
    'a uuid the work set carries twice is recorded once — `options/core` can reach it by more than one plan path'
);
wprism_check_same(
    [],
    IncompleteApplyMarker::write_set(IncompleteApplyMarker::encode($driftRows, [])),
    'an apply with an empty authored work set says so, and that is not the same as saying nothing'
);
wprism_check_same(null, IncompleteApplyMarker::write_set('1'), "issue #3206's bare '1' marker makes no write-set claim");
wprism_check_same(null, IncompleteApplyMarker::write_set('{not json'), 'an unreadable marker makes no write-set claim and does not throw');
wprism_check_same(null, IncompleteApplyMarker::write_set(null), 'no marker makes no write-set claim');

// The exact wire a target interrupted under 9b440c3 still holds: a v1 record
// makes a preserved-drift claim and no write-set claim at all. Each is read
// for what it says — absence is never read as "wrote nothing".
$v1Marker = Canon::encode([
    'format' => IncompleteApplyMarker::FORMAT_V1,
    'preserved_drift' => [
        ['path' => $driftRows[1]['path'], 'type' => 'post', 'uuid' => $driftRows[1]['uuid']],
        ['path' => $driftRows[0]['path'], 'type' => 'option', 'uuid' => $driftRows[0]['uuid']],
    ],
]);
wprism_check_same(
    ['8f14e45f-ceea-467a-9a3e-1b2c3d4e5f60', 'options/core'],
    array_keys(IncompleteApplyMarker::preserved_drift($v1Marker) ?? []),
    "a v1 marker is still read for issue #3489's preserved-drift record"
);
wprism_check_same(null, IncompleteApplyMarker::write_set($v1Marker), 'a v1 marker makes no write-set claim, and none is invented for it');

// ---- 7. The retry projection. The plan below is the live repro's shape:
// two preserved-drift rows, one row the failed run wrote (now `unchanged`),
// one conflict, and one drift row that appeared only after the failure.
$freshDrift = ['uuid' => 'c0ffee00-0000-4000-8000-000000000001', 'type' => 'term', 'path' => 'state/terms/category/c0ffee00--news.json'];
$basePlan = [
    'create' => [],
    'update' => [],
    'unchanged' => [['uuid' => 'written-after-commit', 'type' => 'post', 'path' => 'state/posts/page/written.md']],
    'drift' => array_merge($driftRows, [$freshDrift]),
    'conflict' => [['uuid' => 'both-changed', 'type' => 'post', 'path' => 'state/posts/page/both.md']],
    'incomplete_apply' => [],
];

$retried = \WPrism\ApplyPlanner::project_incomplete_apply_retry($basePlan, $encoded);
wprism_check_same(
    ['options/core', '8f14e45f-ceea-467a-9a3e-1b2c3d4e5f60'],
    array_column($retried['drift'], 'uuid'),
    'a retry keeps every identity the interrupted apply recorded as preserved drift in `drift`, in plan order'
);
wprism_check_same(
    ['written-after-commit', $freshDrift['uuid'], 'both-changed'],
    array_column($retried['update'], 'uuid'),
    'the retry still widens unchanged/conflict and drift the interrupted run never preserved, in issue #3206 order'
);
wprism_check(
    array_reduce($retried['update'], static fn(bool $all, array $r): bool => $all && ($r['retry'] ?? false) === true, true),
    'every widened row still carries retry:true for the provider retry channel'
);
wprism_check_same([], $retried['unchanged'], 'unchanged is still drained by the widening');
wprism_check_same([], $retried['conflict'], 'conflict is still drained by the widening');
wprism_check_same(1, count($retried['incomplete_apply']), 'the retry still reports exactly one incomplete_apply condition');
wprism_check(
    str_contains((string) $retried['incomplete_apply'][0]['reason'], '2 environment-drifted entities')
        && str_contains((string) $retried['incomplete_apply'][0]['reason'], 'wprism capture'),
    'the incomplete_apply reason — the string every plan/status renderer prints — states what the retry will NOT overwrite, and the remedy'
);
wprism_check_same(
    ['state/options/core.json', 'state/posts/page/8f14e45f--team.md'],
    array_column($retried['incomplete_apply'][0]['preserved_drift'] ?? [], 'path'),
    'the machine row lists the preserved entities beside the human reason'
);

// The carve-out needs evidence. An older agent's bare marker keeps issue #3206's
// original whole-bucket widening rather than inventing a preservation claim.
$legacy = \WPrism\ApplyPlanner::project_incomplete_apply_retry($basePlan, '1');
wprism_check_same([], $legacy['drift'], "a bare '1' marker still widens the whole drift bucket");
wprism_check_same(
    ['written-after-commit', 'options/core', '8f14e45f-ceea-467a-9a3e-1b2c3d4e5f60', $freshDrift['uuid'], 'both-changed'],
    array_column($legacy['update'], 'uuid'),
    "a bare '1' marker reproduces issue #3206's exact prior bucket order"
);
wprism_check_same(
    'previous apply did not complete required rebuilds or convergence metadata',
    $legacy['incomplete_apply'][0]['reason'] ?? null,
    'without a preservation record the incomplete_apply reason keeps its exact prior bytes'
);
wprism_check(
    !array_key_exists('preserved_drift', $legacy['incomplete_apply'][0] ?? []),
    'no preservation record means no preserved_drift key — the row never claims evidence it lacks'
);

// No marker at all is not a retry: the plan is returned untouched.
wprism_check_same($basePlan, \WPrism\ApplyPlanner::project_incomplete_apply_retry($basePlan, null), 'no marker leaves the plan exactly as built');

// A recorded identity that has since converged is no longer drift, so it is
// nowhere to carve out: capture-then-apply must still clear the marker.
$captured = $basePlan;
$captured['drift'] = [];
$captured['unchanged'] = array_merge($captured['unchanged'], $driftRows);
$afterCapture = \WPrism\ApplyPlanner::project_incomplete_apply_retry($captured, $encoded);
wprism_check_same([], $afterCapture['drift'], 'after capture folds the drift in, nothing is retained as drift');
wprism_check_same(
    4,
    count($afterCapture['update']),
    'after capture every recorded identity rejoins the retry write set, so the marker can clear'
);
wprism_check_same(
    'previous apply did not complete required rebuilds or convergence metadata',
    $afterCapture['incomplete_apply'][0]['reason'] ?? null,
    'with nothing retained the reason returns to its exact prior bytes'
);

// ---------------------------------------------------------------- head 4

// ---- 7b. issue #3491: the same shape one bucket over. Draining `conflict` into
// `update` makes a retry write rows a first apply refuses outright — "wprism:
// conflicts (env and repo both changed since last sync) — capture first or
// --force-theirs" (ApplyPreparationCoordinator.php:58) — so the marker turned
// an operator decision into an automatic override.
//
// The plan below is the retry after the operator recompiled between the two
// runs, which is what moves the repository side of a preserved row:
//   - `both-changed`      the interrupted run wrote it, so the three-way
//                         reading is its own stale base — issue #3206's case;
//   - `8f14e45f…`         run 1 recorded it as preserved drift and did not
//                         write it; the recompile moved the repo side too;
//   - `late-divergence`   `unchanged` when the marker was written, then both
//                         sides moved — it leaves no trace in ANY drift
//                         record, which is exactly why the write set, and not
//                         `preserved_drift`, is the evidence that decides.
$conflictPlan = $basePlan;
$conflictPlan['drift'] = [$driftRows[0]];
$conflictPlan['conflict'] = [
    ['uuid' => 'both-changed', 'type' => 'post', 'path' => 'state/posts/page/both.md'],
    ['uuid' => $driftRows[1]['uuid'], 'type' => 'post', 'path' => $driftRows[1]['path']],
    ['uuid' => 'late-divergence', 'type' => 'term', 'path' => 'state/terms/category/late--divergence.json'],
];
$conflictRetry = \WPrism\ApplyPlanner::project_incomplete_apply_retry($conflictPlan, $encoded);
wprism_check_same(
    ['8f14e45f-ceea-467a-9a3e-1b2c3d4e5f60', 'late-divergence'],
    array_column($conflictRetry['conflict'], 'uuid'),
    'a retry keeps every three-way conflict on an identity the interrupted apply never wrote in `conflict`, in plan order — so ApplyPreparationCoordinator.php:58 still demands --force-theirs for it'
);
wprism_check_same(
    ['written-after-commit', 'both-changed'],
    array_column($conflictRetry['update'], 'uuid'),
    "issue #3206's own case is untouched: a conflict on a row the interrupted run wrote still widens into update, in the original bucket order"
);
wprism_check(
    array_reduce($conflictRetry['update'], static fn(bool $all, array $r): bool => $all && ($r['retry'] ?? false) === true, true),
    'the rows that do widen still carry retry:true'
);
wprism_check(
    array_reduce($conflictRetry['conflict'], static fn(bool $none, array $r): bool => $none && !array_key_exists('retry', $r), true),
    'a retained conflict is the same row a first apply would refuse — no retry marking is attached to it'
);
wprism_check_same(1, count($conflictRetry['incomplete_apply']), 'the retry still reports exactly one incomplete_apply condition');
wprism_check(
    str_contains((string) $conflictRetry['incomplete_apply'][0]['reason'], '2 entities conflict three ways')
        && str_contains((string) $conflictRetry['incomplete_apply'][0]['reason'], '--force-theirs'),
    'the incomplete_apply reason names what the retry will NOT override and the remedy that would'
);
wprism_check(
    str_contains((string) $conflictRetry['incomplete_apply'][0]['reason'], 'environment-drifted entities')
        && str_contains((string) $conflictRetry['incomplete_apply'][0]['reason'], 'wprism capture'),
    "the conflict clause is additive: issue #3489's preserved-drift clause is still in the same reason string"
);
wprism_check_same(
    ['state/posts/page/8f14e45f--team.md', 'state/terms/category/late--divergence.json'],
    array_column($conflictRetry['incomplete_apply'][0]['retained_conflict'] ?? [], 'path'),
    'the machine row lists the retained conflicts beside the human reason'
);
wprism_check_same(
    [$driftRows[0]['uuid']],
    array_column($conflictRetry['drift'], 'uuid'),
    "both carve-outs run over the same marker: issue #3489's recorded identity that is still drift stays in `drift` while the conflicts are decided by the write set"
);

// Exactly one retained conflict: the reason is operator-facing prose, so its
// grammar must agree with its own count ("1 entity conflicts", never
// "1 entities conflict").
$singleConflictPlan = $basePlan;
$singleConflictPlan['conflict'] = [
    ['uuid' => 'late-divergence', 'type' => 'term', 'path' => 'state/terms/category/late--divergence.json'],
];
$singleConflictRetry = \WPrism\ApplyPlanner::project_incomplete_apply_retry($singleConflictPlan, $encoded);
wprism_check(
    str_contains((string) $singleConflictRetry['incomplete_apply'][0]['reason'], '1 entity conflicts three ways'),
    'a single retained conflict reads "1 entity conflicts three ways", agreeing in number with its own count'
);

// A v1 marker proves only that its own recorded identities were not written.
// The rest keep issue #3206's widening rather than a claim v1 never made.
$v1Retry = \WPrism\ApplyPlanner::project_incomplete_apply_retry($conflictPlan, $v1Marker);
wprism_check_same(
    ['8f14e45f-ceea-467a-9a3e-1b2c3d4e5f60'],
    array_column($v1Retry['conflict'], 'uuid'),
    'under a v1 marker a conflict on a recorded preserved-drift identity still stays in `conflict` — that identity is provably not-written'
);
wprism_check_same(
    ['written-after-commit', 'both-changed', 'late-divergence'],
    array_column($v1Retry['update'], 'uuid'),
    'under a v1 marker every other conflict keeps issue #3206 widening: v1 carries no write set, and absence is not evidence'
);

// A record-less marker is the whole prior behaviour, byte for byte.
$legacyConflict = \WPrism\ApplyPlanner::project_incomplete_apply_retry($conflictPlan, '1');
wprism_check_same([], $legacyConflict['conflict'], "a bare '1' marker still widens the whole conflict bucket");
wprism_check_same(
    ['written-after-commit', 'options/core', 'both-changed', '8f14e45f-ceea-467a-9a3e-1b2c3d4e5f60', 'late-divergence'],
    array_column($legacyConflict['update'], 'uuid'),
    "a bare '1' marker reproduces issue #3206's exact prior bucket order across all three buckets"
);
wprism_check_same(
    'previous apply did not complete required rebuilds or convergence metadata',
    $legacyConflict['incomplete_apply'][0]['reason'] ?? null,
    'without a record the incomplete_apply reason keeps its exact prior bytes even with conflicts in the plan'
);
wprism_check(
    !array_key_exists('retained_conflict', $legacyConflict['incomplete_apply'][0] ?? []),
    'no record means no retained_conflict key — the row never claims evidence it lacks'
);

// A retry whose plan has no conflicts at all reads exactly as it did before.
wprism_check(
    !array_key_exists('retained_conflict', $retried['incomplete_apply'][0] ?? []),
    'a retry with nothing retained in `conflict` carries no retained_conflict key'
);

// ---- 8. The shipped wiring: the builder delegates, the coordinator records
// the drift it is about to preserve, and the gate is told about it.
$builderSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Apply/ApplyPlanBuilder.php');
$coordinatorSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Apply/ApplyRequestCoordinator.php');
wprism_check(
    str_contains($builderSource, 'ApplyPlanner::project_incomplete_apply_retry(')
        && !str_contains($builderSource, "foreach (['unchanged', 'drift', 'conflict'] as \$retryKind)"),
    'ApplyPlanBuilder delegates the widening rather than keeping a second copy of it'
);
wprism_check(
    str_contains($coordinatorSource, "Ledger::kv_set('apply_in_progress', IncompleteApplyMarker::encode(\$plan['drift'], \$work))"),
    'the marker written before the first mutation records this run\'s preserved drift AND its authored write set'
);
wprism_check(
    str_contains($coordinatorSource, "))->verify(\$opts, \$compiled, \$plan['drift']);"),
    'the post-apply gate is handed the rows this run deliberately did not write'
);

wprism_check_summary('regress_apply_drift_convergence');

}
