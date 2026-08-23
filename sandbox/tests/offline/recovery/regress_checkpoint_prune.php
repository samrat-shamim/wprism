<?php
/**
 * Offline product contract for `duo recover <env> --prune-retained=<keep-n>`
 * (DUO-3514's retention half).
 *
 * Two writers retain a whole-database dump per release and nothing in the
 * product ever removed one: promote writes
 * `.duo/checkpoints/promote-<run-id>.sql` (cli/duo:2236) and deploy writes
 * `deploy-<run-id>.sql` (DeployCommand.php:67). This suite pins the ONE verb
 * that removes them, and specifically the five properties that separate it
 * from "an rm with a nice name":
 *
 *   A. the newest `keep` of EACH verb survives — promote and deploy are
 *      counted separately, so a month of deploys cannot age out the last
 *      promote — and `keep` is bounded at 1, so "delete the only before-image"
 *      has no spelling at all;
 *   B. a signed receipt is never a candidate, INCLUDING one whose receipt id
 *      happens to begin `deploy-`, which is the exact trap
 *      `RetainedCheckpoints::prefixForRow()`:301-312 was written against;
 *   C. a nonterminal signed generation refuses the WHOLE prune: pruning
 *      mid-rollback is pruning during a release;
 *   D. the default is a PLAN. `--prune-retained=<n>` alone issues zero
 *      mutating calls — proven by counting what the driver was asked, not by
 *      reading the message — and `--confirm-prune` issues exactly one;
 *   E. `--writers-excluded` is refused rather than accepted-and-ignored, and
 *      `--list`'s own output for the same catalog is byte-identical to what it
 *      was before this flag existed.
 *
 * Pure fakes: no target, no docker, no WordPress, no filesystem writes. The
 * script and its parse are byte-pinned, so the bytes a confirmed prune sends a
 * target are reviewable here rather than only on a live estate.
 */
declare(strict_types=1);

// From offline/recovery/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../cli/src/Command/RecoverCommand.php';
require_once __DIR__ . '/../../../../cli/src/Recovery/CheckpointCatalog.php';
require_once __DIR__ . '/../../../../cli/src/Recovery/CheckpointPrune.php';
require_once __DIR__ . '/../../../../cli/src/Recovery/RetainedCheckpoints.php';

use Duo\CommandRefusalException;
use Duo\Orchestrator\CheckpointCatalog;
use Duo\Orchestrator\CheckpointPrune;
use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\RecoverCommand;
use Duo\Orchestrator\RecoveryClaim;
use Duo\Orchestrator\RetainedCheckpoints;

const PRUNE_REPO = '/srv/site-repo';
const PRUNE_NOW = '2026-03-01T00:00:00Z';

/**
 * A driver that answers the retained-checkpoint listing from a scripted table
 * and records every raw script it was handed.
 *
 * Deliberately NOT a RecoveryTransport: that is what makes
 * `CheckpointCatalog::list()` take the no-authority arm and reach
 * `RetainedCheckpoints::list()` through the one primitive every transport has.
 * The authority rows this suite needs are injected into a catalog by hand
 * instead, because their SOURCE is not what is under test — their effect on
 * the prune decision is.
 */
final class PruneFixtureDriver implements EnvironmentDriver {
    /** @var list<string> every raw script, in order */
    public array $raw = [];
    /** @var list<string> the raw scripts that mutate (everything but the listing) */
    public array $mutating = [];

    /** @param list<array{0:string,1:string,2:int}> $files basename, artifact hash, mtime */
    public function __construct(private array $files, private int $pruneExit = 0) {
    }

    public function name(): string { return 'prune-fixture'; }
    public function driverId(): string { return 'prune-fixture'; }
    public function repoPath(): string { return PRUNE_REPO; }
    public function describe(): string { return 'checkpoint prune fixture'; }

    public function captureRaw(string $script): array {
        $this->raw[] = $script;
        if (str_contains($script, '/checkpoints/promote-*.sql')) {
            $stdout = '';
            foreach ($this->files as [$basename, $hash, $mtime]) {
                $stdout .= $basename . "\t" . $hash . "\t" . $mtime . "\n";
            }

            return ['exit' => 0, 'stdout' => $stdout, 'stderr' => ''];
        }
        // Anything else this verb sends is a removal.
        $this->mutating[] = $script;
        $stdout = '';
        foreach (explode('; ', $script) as $fragment) {
            if (preg_match("/'((?:promote|deploy)-[A-Za-z0-9._-]+)' removed/", $fragment, $m) === 1) {
                $stdout .= $m[1] . "\tremoved\n";
            }
        }

        return ['exit' => $this->pruneExit, 'stdout' => $stdout, 'stderr' => ''];
    }

    public function captureWp(array $wpArgs): array {
        throw new \RuntimeException('a prune must not run wp: ' . implode(' ', $wpArgs));
    }

    public function streamWp(array $wpArgs): int {
        throw new \RuntimeException('a prune must not stream wp: ' . implode(' ', $wpArgs));
    }

    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }

    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('prune-fixture', 'prune-fixture', $operation, []);
    }
}

/** One retained catalog row, exactly as RetainedCheckpoints builds it. */
function prune_retained_row(string $prefix, string $owner, int $mtime): array {
    return RetainedCheckpoints::row($owner, str_repeat('a', 64), $mtime, PRUNE_NOW, $prefix);
}

/** One signed authority row, in CheckpointCatalog::row()'s key set. */
function prune_authority_row(string $id, bool $terminal): array {
    return [
        'age_seconds' => null,
        'artifact_hash' => str_repeat('c', 64),
        'claim_expires_at' => '2026-03-01T01:00:00Z',
        'covers' => [RecoveryClaim::RESOURCE_DATABASE_CHECKPOINT],
        'created_at' => null,
        'event_chain_sha256' => null,
        'generation' => 7,
        'id' => $id,
        'kind' => CheckpointCatalog::KIND_VERIFIED,
        'owner' => 'verified-owner',
        'retention_until' => '2026-04-01T00:00:00Z',
        'state' => $terminal ? 'committed' : 'rolling_back',
        'terminal' => $terminal,
    ];
}

/** @param list<array<string,mixed>> $rows */
function prune_catalog(array $rows): array {
    return ['disclosures' => [], 'format' => CheckpointCatalog::FORMAT, 'rows' => $rows];
}

/** Run `duo recover` against a fixture driver, capturing stdout. */
function prune_run(PruneFixtureDriver $driver, array $extra): array {
    ob_start();
    try {
        $exit = RecoverCommand::run($driver, $extra, static fn(): string => PRUNE_NOW);
    } finally {
        $stdout = (string) ob_get_clean();
    }

    return ['exit' => $exit, 'stdout' => $stdout];
}

// ---------------------------------------------------------------------------
// A. Newest-N per verb, counted separately, with the bound in the grammar.
// ---------------------------------------------------------------------------

// Six releases, alternating verbs. RetainedCheckpoints::parse() sorts
// newest-first, so index 0 of each group is the most recent of that verb.
$catalog = prune_catalog([
    prune_retained_row(RetainedCheckpoints::ID_PREFIX, 'promote-6', 1_772_000_600),
    prune_retained_row(RetainedCheckpoints::DEPLOY_ID_PREFIX, 'deploy-5', 1_772_000_500),
    prune_retained_row(RetainedCheckpoints::ID_PREFIX, 'promote-4', 1_772_000_400),
    prune_retained_row(RetainedCheckpoints::DEPLOY_ID_PREFIX, 'deploy-3', 1_772_000_300),
    prune_retained_row(RetainedCheckpoints::ID_PREFIX, 'promote-2', 1_772_000_200),
    prune_retained_row(RetainedCheckpoints::DEPLOY_ID_PREFIX, 'deploy-1', 1_772_000_100),
]);

$plan = CheckpointPrune::plan($catalog, 1);
duo_check_same(
    ['deploy-deploy-5', 'promote-promote-6'],
    array_column($plan['keep'], 'id'),
    'keep=1 keeps the newest checkpoint of EACH verb, not the newest one overall'
);
duo_check_same(
    ['deploy-deploy-3', 'deploy-deploy-1', 'promote-promote-4', 'promote-promote-2'],
    array_column($plan['delete'], 'id'),
    'and everything older in each group is a candidate, newest-first within the group'
);

$plan = CheckpointPrune::plan($catalog, 2);
duo_check_same(
    ['deploy-deploy-5', 'deploy-deploy-3', 'promote-promote-6', 'promote-promote-4'],
    array_column($plan['keep'], 'id'),
    'keep=2 keeps two of each verb'
);
duo_check_same(
    ['deploy-deploy-1', 'promote-promote-2'],
    array_column($plan['delete'], 'id'),
    'leaving exactly the two oldest'
);

duo_check_same(
    [],
    CheckpointPrune::plan($catalog, 50)['delete'],
    'a keep count larger than the catalog deletes nothing at all'
);

// THE property this whole design exists for: a target holding one promote
// checkpoint cannot be pruned down to zero, at any keep count the grammar
// accepts.
$only = prune_catalog([prune_retained_row(RetainedCheckpoints::ID_PREFIX, 'sole', 1_772_000_000)]);
foreach ([CheckpointPrune::KEEP_MIN, 3, CheckpointPrune::KEEP_MAX] as $keep) {
    duo_check_same(
        [],
        CheckpointPrune::plan($only, $keep)['delete'],
        "keep=$keep never deletes the only before-image a target holds"
    );
}
duo_check_refuses(
    static fn() => CheckpointPrune::plan($only, 0),
    'invalid_arguments',
    'keep=0 is not expressible: the bound is in the grammar, not in a later check'
);
duo_check_refuses(
    static fn() => CheckpointPrune::plan($only, CheckpointPrune::KEEP_MAX + 1),
    'invalid_arguments',
    'and the upper bound refuses too, so the flag has one closed range'
);

// ---------------------------------------------------------------------------
// B. A signed receipt is never a candidate — including the deploy- trap.
// ---------------------------------------------------------------------------

$mixed = prune_catalog([
    // A receipt id chosen by the rollback authority that happens to start with
    // deploy-. `prefixForRow()`'s docblock names exactly this hazard: without
    // the isRetained() half it would resolve to a path promote never wrote.
    prune_authority_row('deploy-2026-03-01-generation-7', true),
    prune_retained_row(RetainedCheckpoints::ID_PREFIX, 'newer', 1_772_000_200),
    prune_retained_row(RetainedCheckpoints::ID_PREFIX, 'older', 1_772_000_100),
]);
$plan = CheckpointPrune::plan($mixed, 1);
duo_check_same(
    ['promote-older'],
    array_column($plan['delete'], 'id'),
    'a signed receipt whose id begins deploy- is not a prune candidate; only the retained file is'
);
duo_check_same(
    ['promote-newer'],
    array_column($plan['keep'], 'id'),
    'and the signed row is not counted toward any verb\'s keep quota either'
);
duo_check(
    !str_contains(
        CheckpointPrune::script(PRUNE_REPO, $plan['delete']),
        'deploy-2026-03-01-generation-7'
    ),
    'so the removal script can never name a receipt id as a file path'
);

// ---------------------------------------------------------------------------
// C. A nonterminal signed generation refuses the whole prune.
// ---------------------------------------------------------------------------

$inFlight = prune_catalog([
    prune_authority_row('receipt-in-flight', false),
    prune_retained_row(RetainedCheckpoints::ID_PREFIX, 'newer', 1_772_000_200),
    prune_retained_row(RetainedCheckpoints::ID_PREFIX, 'older', 1_772_000_100),
]);
duo_check_refuses(
    static fn() => CheckpointPrune::plan($inFlight, 1),
    CheckpointPrune::REASON_GENERATION_ACTIVE,
    'a nonterminal signed generation refuses the WHOLE prune: pruning mid-rollback is pruning during a release'
);
$settled = prune_catalog([
    prune_authority_row('receipt-committed', true),
    prune_retained_row(RetainedCheckpoints::ID_PREFIX, 'newer', 1_772_000_200),
    prune_retained_row(RetainedCheckpoints::ID_PREFIX, 'older', 1_772_000_100),
]);
duo_check_same(
    ['promote-older'],
    array_column(CheckpointPrune::plan($settled, 1)['delete'], 'id'),
    'and a terminal generation does not block it: the gate is in-flight-ness, not the presence of an authority'
);

// ---------------------------------------------------------------------------
// The wire: script() and parse(), byte-pinned.
// ---------------------------------------------------------------------------

$one = [prune_retained_row(RetainedCheckpoints::DEPLOY_ID_PREFIX, 'run-9', 1_772_000_100)];
duo_check_same(
    'p=\'/srv/site-repo/.duo/checkpoints/deploy-run-9.sql\'; if [ -e "$p" ]; then '
    . 'if rm -f "$p"; then printf \'%s\\t%s\\n\' \'deploy-run-9\' removed; '
    . 'else printf \'%s\\t%s\\n\' \'deploy-run-9\' failed; fi; '
    . 'else printf \'%s\\t%s\\n\' \'deploy-run-9\' absent; fi; exit 0',
    CheckpointPrune::script(PRUNE_REPO, $one),
    'the removal script is one POSIX-sh rm per row, at the path RetainedCheckpoints::checkpointPath derives'
);
duo_check_same(
    'exit 0',
    CheckpointPrune::script(PRUNE_REPO, []),
    'and an empty plan sends a script that removes nothing'
);

duo_check_same(
    [['id' => 'promote-a', 'status' => 'removed'], ['id' => 'deploy-b', 'status' => 'absent']],
    CheckpointPrune::parse("promote-a\tremoved\ndeploy-b\tabsent\n"),
    'parse() reads the per-row outcome the script printed'
);
foreach ([
    "promote-a\n" => 'a line with one field',
    "promote-a\tremoved\textra\n" => 'a line with three fields',
    "promote-a\tdeleted\n" => 'an outcome this build does not define',
    "materialize-op\tremoved\n" => 'an id that is not a retained checkpoint',
] as $stdout => $why) {
    duo_check_refuses(
        static fn() => CheckpointPrune::parse($stdout),
        CheckpointPrune::REASON_MALFORMED,
        "parse() refuses $why rather than skipping it"
    );
}

// ---------------------------------------------------------------------------
// D. The default is a plan, and confirmation is one mutating call.
// ---------------------------------------------------------------------------

$files = [
    ['promote-r6', str_repeat('a', 64), 1_772_000_600],
    ['deploy-r5', str_repeat('a', 64), 1_772_000_500],
    ['promote-r4', str_repeat('a', 64), 1_772_000_400],
    ['deploy-r3', str_repeat('a', 64), 1_772_000_300],
    ['promote-r2', str_repeat('a', 64), 1_772_000_200],
    ['deploy-r1', str_repeat('a', 64), 1_772_000_100],
];

$driver = new PruneFixtureDriver($files);
$result = prune_run($driver, ['--prune-retained=1']);
duo_check_same(0, $result['exit'], 'a plan-only prune exits 0');
duo_check_same(
    [],
    $driver->mutating,
    'THE default-safety property, counted rather than read: --prune-retained alone issues ZERO mutating calls'
);
duo_check(
    str_contains($result['stdout'], 'WOULD-PRUNE deploy-r3  2026-02-25T06:18:20Z')
        && str_contains($result['stdout'], 'WOULD-PRUNE promote-r2  2026-02-25T06:16:40Z'),
    'and prints one WOULD-PRUNE row per candidate, with the instant the file carries'
);
duo_check(
    str_contains($result['stdout'], "would prune 4, keep 2\n"),
    'with a summary that counts both halves'
);
duo_check(
    str_contains($result['stdout'], 'nothing was removed: re-run with --confirm-prune'),
    'and names the one flag that turns the plan into a deletion'
);
duo_check(
    str_contains($result['stdout'], 'note: ' . CheckpointPrune::DISCLOSURE_NEVER_AUTOMATIC)
        && str_contains($result['stdout'], 'note: ' . CheckpointPrune::DISCLOSURE_NEWEST_KEPT)
        && str_contains($result['stdout'], 'note: ' . CheckpointPrune::DISCLOSURE_ARTIFACTS_KEPT),
    'the three disclosures print as note: lines, including the one saying the artifact and frozen plan are kept'
);
duo_check(
    !str_contains($result['stdout'], 'PRUNED '),
    'and nothing in the plan output claims a removal happened'
);

$driver = new PruneFixtureDriver($files);
$result = prune_run($driver, ['--prune-retained=1', '--confirm-prune']);
duo_check_same(0, $result['exit'], 'a confirmed prune exits 0');
duo_check_same(1, count($driver->mutating), 'and issues exactly ONE mutating call: the whole removal is one script');
duo_check(
    str_contains($driver->mutating[0], "/.duo/checkpoints/promote-r2.sql'")
        && str_contains($driver->mutating[0], "/.duo/checkpoints/deploy-r1.sql'"),
    'naming exactly the candidate paths'
);
duo_check(
    !str_contains($driver->mutating[0], 'promote-r6.sql')
        && !str_contains($driver->mutating[0], 'deploy-r5.sql'),
    'and never the newest of either verb'
);
duo_check(
    !str_contains($driver->mutating[0], '.duo/artifacts')
        && !str_contains($driver->mutating[0], '.duo/releases'),
    'THE containment property: the removal touches no artifact and no frozen plan, only the .sql'
);
duo_check(
    str_contains($result['stdout'], 'REMOVED promote-r2  2026-02-25T06:16:40Z')
        && str_contains($result['stdout'], "pruned 4, kept 2\n"),
    'and the outcome prints the per-row outcome the TARGET reported, not what the host planned'
);
duo_check(
    !str_contains($result['stdout'], 'WOULD-PRUNE')
        && !str_contains($result['stdout'], 'nothing was removed'),
    'with no trace of the plan vocabulary once a removal actually happened'
);

// The JSON document is the same decision, canonically encoded.
$driver = new PruneFixtureDriver($files);
$result = prune_run($driver, ['--prune-retained=2', '--format=json']);
$document = json_decode($result['stdout'], true);
duo_check_same(CheckpointPrune::FORMAT, $document['format'] ?? null, '--format=json emits duo-checkpoint-prune/v1');
duo_check_same(false, $document['confirmed'] ?? null, 'stating that this run confirmed nothing');
duo_check_same(2, $document['keep'] ?? null, 'and the keep count it decided with');
duo_check_same(
    ['deploy-r1', 'promote-r2'],
    array_column((array) $document['pruned'], 'id'),
    'listing the rows it would remove'
);
duo_check_same(
    ['would-prune', 'would-prune'],
    array_column((array) $document['pruned'], 'status'),
    'each marked would-prune rather than removed'
);
duo_check_same([], $driver->mutating, 'and the JSON plan is as read-only as the human one');

// A target that could not remove a file reports it per row and exits 1.
$driver = new PruneFixtureDriver($files, 1);
$result = prune_run($driver, ['--prune-retained=1', '--confirm-prune', '--format=json']);
duo_check_same(1, $result['exit'], 'a target that could not run the removal script refuses');
duo_check_same(
    CheckpointPrune::REASON_UNAVAILABLE,
    (json_decode($result['stdout'], true)['reason_code'] ?? null),
    'with a reason code of its own rather than a generic failure'
);

// ---------------------------------------------------------------------------
// E. The flag pairings, and --list left exactly as it was.
// ---------------------------------------------------------------------------

foreach ([
    ['--prune-retained=2', '--writers-excluded'],
    ['--prune-retained=2', '--list'],
    ['--prune-retained=2', '--restore=promote-r6'],
    ['--prune-retained=0'],
    ['--prune-retained=51'],
    ['--prune-retained=2', '--prune-retained=3'],
    ['--confirm-prune'],
] as $extra) {
    $driver = new PruneFixtureDriver($files);
    $result = prune_run($driver, array_merge($extra, ['--format=json']));
    duo_check_same(
        'invalid_arguments',
        (json_decode($result['stdout'], true)['reason_code'] ?? null),
        'the closed grammar refuses ' . implode(' ', $extra)
    );
    duo_check_same([], $driver->mutating, 'and removes nothing while doing so');
}

// --writers-excluded is the one worth stating plainly: it asserts a
// maintenance window for a whole-database import (RecoverCommand.php:334-340). A
// prune imports nothing, so accepting it would re-teach the flag as generic
// danger.
$driver = new PruneFixtureDriver($files);
$listed = prune_run($driver, ['--list']);
duo_check_same(0, $listed['exit'], '--list still exits 0 for the same catalog');
duo_check_same([], $driver->mutating, 'and is still read-only');
duo_check(
    str_contains($listed['stdout'], "checkpoints: 6\n")
        && str_contains($listed['stdout'], '  promote-r6  retained  ' . RetainedCheckpoints::KIND)
        && str_contains($listed['stdout'], 'note: ' . RetainedCheckpoints::DISCLOSURE_RETAINED),
    'printing the byte-identical rows and disclosures it printed before the prune flag existed'
);
duo_check(
    !str_contains($listed['stdout'], 'WOULD-PRUNE') && !str_contains($listed['stdout'], 'would prune'),
    'with nothing about pruning leaking into a plain listing'
);

// The catalog read itself is shared: one listing script for both requests.
$driver = new PruneFixtureDriver($files);
prune_run($driver, ['--prune-retained=1']);
duo_check_same(
    1,
    count($driver->raw),
    'a plan reads the catalog exactly once and does nothing else: the prune decides on the rows --list would print'
);

duo_check_summary('regress_checkpoint_prune');
