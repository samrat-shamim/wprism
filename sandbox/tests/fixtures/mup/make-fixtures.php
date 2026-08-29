<?php
declare(strict_types=1);

/**
 * Regenerate the recorded documents `sandbox/tests/grind/grind_mup.sh --self-check`
 * runs its pure jq/bash helpers against (round-3 MUP §6.1).
 *
 * Usage: php sandbox/tests/fixtures/mup/make-fixtures.php [<out-dir>]
 *        (default out-dir: this directory)
 *
 * ## Why the fixtures are GENERATED and not hand-written
 *
 * The grind's assertions are jq expressions over four shipped documents —
 * `duo-assess-report/v1`, `duo-authorization-plan/v1`, `duo-recovery-claim/v1`
 * and `duo-verify-report/v1`. A hand-written fixture records what the author
 * *believed* those documents look like, so a jq path that silently matches
 * nothing keeps passing after the real shape moves. Every PASS fixture here is
 * therefore built by the SHIPPED builder class and validated by the shipped
 * validator before it is written:
 *
 *   AssessReport::build()        -> ContractProposal::validateAssessReport()
 *   AuthorizationPlan::build()   -> AuthorizationPlan::validate()
 *   RecoveryClaim::build()       -> RecoveryClaim::validate()
 *   JourneyOracle::report()      -> shape asserted below
 *   CheckpointCatalog::fromStatus()
 *
 * Re-running this script after a format change makes the drift a diff rather
 * than a silently-vacuous assertion, and the grind's `--self-check` then fails
 * loudly if a helper stopped reading a key that moved.
 *
 * ## Why the FAIL fixtures are hand-mutated
 *
 * A negative fixture must be a document the shipped builder cannot produce —
 * that is the point of it. Each one is a PASS fixture with exactly one edit,
 * named in its filename, so `--self-check` proves each helper is non-vacuous:
 * a helper that never fails proves nothing about the grind that trusts it.
 *
 * WooCommerce names appear here on purpose. This is a test fixture for a
 * WooCommerce grind; the engine-adapter boundary forbids a plugin slug in
 * `cli/src/Assess`, `cli/src/Contract` and `agent/src/Assess`, not in the
 * evidence a grind records.
 *
 * ## Re-running this is the supported way to change a fixture
 *
 * A wholesale re-run reproduces every committed byte beside it, and
 * `sandbox/tests/offline/guards/regress_fixture_makers.sh` fails the corpus if
 * it stops doing so. Between #472 and DUO-3483 this script did not complete at
 * all — `RecoveryClaim::build()` refused its facts with "recovery claim facts
 * carry unknown key(s): checkpoint_at" — so the fixtures were delta-edited
 * instead. Two causes, both recorded because a future edit can reintroduce
 * either:
 *
 *   - `checkpoint_at` was passed to `RecoveryClaim::build()`. It was not
 *     renamed; #472 REMOVED it from the claim on purpose. The claim is
 *     embedded in the digested part of the authorization plan, so a clock
 *     value inside it made `plan_digest` a timestamp — two `--plan-only` runs
 *     a second apart produced two names for one decision. The instant travels
 *     BESIDE the claim now, as `recovery_profile.checkpoint_at`, excluded from
 *     `plan_digest` exactly like `frozen_at` (RecoveryClaim.php:36-58 and its
 *     FACT_KEYS comment at :192-198). It is still handed to
 *     `RecoveryProfileSelection::decide()` below, which is what publishes it;
 *   - `evidence.registry_sha256` and `generated_from.dispositions_sha256` were
 *     `sha256:`-prefixed. Both producers emit BARE 64-hex —
 *     `ManifestDispositions::sha256()` for the target's
 *     (agent/src/Policy/ManifestDispositions.php:114) and
 *     `AssessCommand::registryProvenance()` for the host's
 *     (cli/src/Command/AssessCommand.php:716). The prefixed form belongs to
 *     `AdapterObservation`, a different document.
 */

$root = dirname(__DIR__, 4);

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/cli/src/Assess/AssessReport.php';
require_once $root . '/cli/src/Contract/ApplicationContract.php';
require_once $root . '/cli/src/Contract/ContractProposal.php';
require_once $root . '/cli/src/Recovery/CheckpointCatalog.php';
require_once $root . '/cli/src/Recovery/RecoveryClaim.php';
require_once $root . '/cli/src/Recovery/RecoveryProfileSelection.php';
require_once $root . '/cli/src/Release/AuthorizationPlan.php';
require_once $root . '/cli/src/Release/JourneyOracle.php';

use Duo\Canon;
use Duo\Orchestrator\ApplicationContract;
use Duo\Orchestrator\AssessReport;
use Duo\Orchestrator\AuthorizationPlan;
use Duo\Orchestrator\CheckpointCatalog;
use Duo\Orchestrator\JourneyOracle;
use Duo\Orchestrator\RecoveryClaim;
use Duo\Orchestrator\RecoveryProfileSelection;

/** @var list<string> $argvList */
$argvList = $_SERVER['argv'] ?? [];
array_shift($argvList);
$out = $argvList[0] ?? __DIR__;
if (!is_dir($out) && !mkdir($out, 0777, true)) {
    fwrite(STDERR, "make-fixtures: cannot create $out\n");
    exit(2);
}
$out = (string) (realpath($out) ?: $out);

$written = [];
function mup_write(string $path, string $bytes): void {
    global $written;
    if (file_put_contents($path, $bytes) === false) {
        fwrite(STDERR, "make-fixtures: cannot write $path\n");
        exit(2);
    }
    $written[] = basename($path);
}
function mup_json(string $path, array $document): void {
    mup_write($path, Canon::encode($document));
}
/** @return array<string,mixed> */
function mup_read(string $path): array {
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "make-fixtures: fixture is not JSON: $path\n");
        exit(2);
    }

    return $decoded;
}

$now = '2026-08-17T09:14:02Z';

// ---------------------------------------------------------------- assess
// One row per §6.1 step 3 assertion: the products row the grind requires to
// read authored/manage/Ready/Platform-certified/prevented, the orders row it
// requires to read runtime/preserve local/Unsupported, and one unclassified
// row whose next action must not be `nothing — supported`.
$releaseProjection = static function (
    string $stateClass,
    string $handling,
    string $readiness,
    string $provenance,
    string $containment,
    string $recovery,
    string $meaning
): array {
    return [
        'certification_provenance' => $provenance,
        'conditions' => [],
        'effect_containment' => $containment,
        'effect_containment_basis' => $containment === 'prevented'
            ? 'no WordPress hooks fire in the apply window'
            : 'unknown — not enforced in this profile',
        'effect_recovery_semantics' => $recovery,
        'expiry_and_dependencies' => ['woocommerce 10.0.0–12.0.0', 'wordpress 6.8.2', 'php 8.3.x', 'MariaDB 11.x'],
        'handling' => $handling,
        'meaning' => $meaning,
        'readiness' => $readiness,
        'remediation' => null,
        'state_class' => $stateClass,
    ];
};

$surfaces = [
    [
        'decided_by' => 'platform-default',
        'handling' => 'manage',
        'id' => 'post_type:product',
        'kind' => 'post_type',
        'label' => 'post_type:product',
        'meaning' => 'authored catalog content Duo manages end to end',
        'next_action' => 'nothing — supported',
        'operations' => [
            'release' => $releaseProjection(
                'authored',
                'manage',
                'Ready',
                'Platform-certified',
                'prevented',
                'provider-state restorable',
                'authored catalog content Duo manages end to end'
            ),
        ],
        'state_class' => 'authored',
    ],
    [
        'decided_by' => 'platform-default',
        'handling' => 'preserve local',
        'id' => 'post_type:shop_order',
        'kind' => 'post_type',
        'label' => 'post_type:shop_order',
        'meaning' => 'live operational state is never copied',
        'next_action' => 'nothing — supported',
        'operations' => [
            'release' => $releaseProjection(
                'runtime',
                'preserve local',
                'Unsupported',
                'Platform-certified',
                'prevented',
                'not applicable',
                'live operational state is never copied'
            ),
        ],
        'state_class' => 'runtime',
    ],
    [
        'decided_by' => 'unresolved',
        'handling' => 'block',
        'id' => 'table:acme_catalog',
        'kind' => 'table',
        'label' => 'table:acme_catalog',
        'meaning' => 'no installed adapter can see this table',
        // T6 §3.6: this row's whole point is that no installed adapter can
        // see the table, and its remedy is one that models it. `qualify in
        // rehearsal` named an action rehearsal states it cannot perform.
        'next_action' => 'install adapter',
        'operations' => [
            'release' => $releaseProjection(
                'unclassified',
                'block',
                'Not qualified',
                'Uncertified',
                'unknown',
                'unknown',
                'no installed adapter can see this table'
            ),
        ],
        'state_class' => 'unclassified',
    ],
];

$assess = AssessReport::build(
    'mup1',
    $now,
    [
        'database' => ['engine' => 'MariaDB', 'version' => '11.8.8'],
        'home' => 'http://localhost:9400',
        'php' => '8.3.33',
        'site_mode' => 'single-site',
        'siteurl' => 'http://localhost:9400',
        'wordpress' => '6.8.2',
    ],
    ['transport' => 'docker', 'read_only' => true, 'repo' => '/siterepo'],
    $surfaces,
    // `undeclared_tables_count` is one because the `table:acme_catalog` row
    // above IS that table: `AssessReport::unknown()` returns the key on every
    // run, so a report without it is a document no assess can produce.
    [
        'invisible_names_count' => 41,
        'names_sample' => ['acme_widget_cache'],
        'pending_count' => 3,
        'undeclared_tables_count' => 1,
    ],
    // Both numbers are BARE 64-hex, the only form either producer emits (see
    // the docblock). The `sha256:` prefix belongs to AdapterObservation.
    [
        'generated_from' => [
            'dispositions_sha256' => str_repeat('9', 64),
        ],
        'registry_sha256' => str_repeat('8f', 32),
    ],
    // DUO-3484's host/target comparison, pinned AGREEING: this walk is about
    // the §6.1 words, and a skewed library would withhold the proposal and
    // change what every step after it reads. The mismatch case has its own
    // fixtures in regress_assess_composition.sh and regress_contract_accept.sh.
    [
        'agree' => true,
        'host_registry_sha256' => str_repeat('8f', 32),
        'meaning' => AssessReport::DISPOSITIONS_AGREE_MEANING,
        'target_registry_sha256' => str_repeat('8f', 32),
    ]
);
mup_json("$out/assess-report.pass.json", $assess);

// One edit: the unclassified row loses its gap action. §6.1 step 3 requires
// every unclassified row to carry one, so the helper must reject this.
$noAction = $assess;
$noAction['surfaces'][2]['next_action'] = 'nothing — supported';
mup_json("$out/assess-report.fail-unclassified-no-next-action.json", $noAction);

// One edit: the products row is no longer Ready. The grind asserts the exact
// §6.1 tuple, so a readiness word it does not name must fail.
$notReady = $assess;
$notReady['surfaces'][0]['operations']['release']['readiness'] = 'Requalification required';
mup_json("$out/assess-report.fail-products-not-ready.json", $notReady);

// ------------------------------------------------------------ recovery claim
// `operator-directed` is the profile a docker-transport pair can actually
// prove (ReleaseCommand::recovery(): only an SSH target carries a rollback
// authority runtime), so it is the boundary sentence the grind's step-11 gate
// reads. `none` is recorded because its boundary is the OTHER sentence
// RecoveryClaim::lossBoundary() can emit, and the helper must tell them apart.
$declaredEffects = [[
    'containment' => 'live',
    'effect_recovery_semantics' => 'provider-state restorable',
    'id' => 'code-lifecycle-window',
    'restored_by' => 'code release',
]];
// No `checkpoint_at` in either call: it is a request fact of
// `RecoveryProfileSelection::decide()` (used below), never a claim field —
// `RecoveryClaim::FACT_KEYS` refuses it by name so a clock cannot reach
// `plan_digest`.
$operatorClaim = RecoveryClaim::build([
    'additional_does_not_restore' => [],
    'covered_resources' => ['encrypted database checkpoint /siterepo/.duo/checkpoints/promote-1.sql.enc'],
    'declared_external_effects' => $declaredEffects,
    'profile' => RecoveryClaim::OPERATOR_DIRECTED,
]);
RecoveryClaim::validate($operatorClaim);
mup_json("$out/recovery-claim.operator-directed.json", $operatorClaim);

$noneClaim = RecoveryClaim::build([
    'additional_does_not_restore' => [],
    'covered_resources' => [],
    'declared_external_effects' => $declaredEffects,
    'profile' => RecoveryClaim::NONE,
]);
RecoveryClaim::validate($noneClaim);
mup_json("$out/recovery-claim.none.json", $noneClaim);

// One edit: a claim that says it gives nothing up. RecoveryClaim::validate()
// refuses it; the grind's own helper must refuse it too, because the grind is
// what reads the claim out of a frozen plan on the way to step 11.
$emptyClaim = $operatorClaim;
$emptyClaim['does_not_restore'] = [];
mup_json("$out/recovery-claim.fail-empty-does-not-restore.json", $emptyClaim);

// ------------------------------------------------------- authorization plan
$release = dirname(__DIR__) . '/release';
$contract = ApplicationContract::withDigest(mup_read("$release/contract-declared-unbound.json"));
ApplicationContract::validate($contract);
$selection = RecoveryProfileSelection::decide(
    [
        'automatic' => false,
        'profile' => RecoveryClaim::OPERATOR_DIRECTED,
        'reason' => 'this transport carries no rollback authority runtime, so promote uses the operator-directed '
            . 'artifact-bound lease and database checkpoint',
        'scoped' => false,
        'status' => [],
    ],
    [
        'checkpoint_at' => $now,
        'covered_resources' => ['database checkpoint'],
        'declared_external_effects' => $contract['declarations']['external_effects'],
    ]
);
if (($selection['refusal'] ?? null) !== null) {
    fwrite(STDERR, "make-fixtures: the operator-directed selection refused unexpectedly\n");
    exit(2);
}
$plan = AuthorizationPlan::build([
    'authority' => [['kind' => 'business_owner', 'reason' => 'storefront pages visible to customers change']],
    'capabilities' => [[
        'certification_provenance' => 'Platform-certified',
        'conditions' => [],
        // The shape ReleaseCommand::capabilities() actually emits: the claim
        // the row's readiness rests on is named even where it raises no
        // condition, because the mutation gate re-observes the CLAIM (is it
        // still there, does it still say this) and not only the conditions it
        // happened to raise at freeze time.
        'manifest' => 'core',
        'name' => 'core',
        'operation' => 'promote',
        'readiness' => 'Ready',
    ]],
    'contract' => $contract,
    'deletion_semantics' => [],
    'environment' => 'mup2',
    'flags' => ['plan_only' => true, 'with_deletes' => false],
    'frozen_at' => $now,
    'plan' => mup_read("$release/plan-clean.json"),
    'projection' => mup_read("$release/projection-ready.json"),
    'recovery' => $selection,
    'scope' => [
        'code' => ['lifecycle_phases' => ['retire', 'activate', 'verify'], 'plugins_changed' => 0, 'themes_changed' => 0],
        'surfaces' => ['products', 'pages'],
    ],
    'target' => mup_read("$release/target-facts.json"),
]);
AuthorizationPlan::validate($plan);
mup_json("$out/authorization-plan.pass.json", $plan);

// One edit each: no contract cited, and a recovery profile with no reason.
// §6.1 step 8 requires both, so both must fail the helper.
$noContract = $plan;
$noContract['contract_digest'] = null;
mup_json("$out/authorization-plan.fail-no-contract-digest.json", $noContract);

$noReason = $plan;
$noReason['recovery_profile']['selected_because'] = '';
mup_json("$out/authorization-plan.fail-no-recovery-reason.json", $noReason);

// ---------------------------------------------------------------- verify
$journeys = [
    [
        'affected_surfaces' => ['products', 'store settings'],
        'expect_contains' => 'Duo Ceramic Mug',
        'expect_status' => 200,
        'id' => 'shop-index',
        'url' => '/?post_type=product',
    ],
    [
        'affected_surfaces' => ['pages'],
        'expect_contains' => 'Duo grind landing page',
        'expect_status' => 200,
        'id' => 'landing-page',
        'url' => '/?page_id=1',
    ],
];
JourneyOracle::validateJourneys($journeys);
$rows = [
    ['detail' => '', 'expect_contains' => 'Duo Ceramic Mug', 'expect_status' => 200,
        'http_status' => 200, 'id' => 'shop-index', 'ok' => true,
        'status' => JourneyOracle::PASS, 'url' => '/?post_type=product'],
    ['detail' => '', 'expect_contains' => 'Duo grind landing page', 'expect_status' => 200,
        'http_status' => 200, 'id' => 'landing-page', 'ok' => true,
        'status' => JourneyOracle::PASS, 'url' => '/?page_id=1'],
];
$verify = JourneyOracle::report(
    JourneyOracle::convergence(['deletions' => 0, 'live_entities' => 213, 'result' => 'pass', 'verifier' => 'plan-reconciliation/v1']),
    $rows,
    $journeys,
    ['products', 'pages', 'store settings'],
    'mup2',
    (string) $plan['plan_digest']
);
if (($verify['verdict'] ?? null) !== JourneyOracle::PASS) {
    fwrite(STDERR, "make-fixtures: the pass verify fixture did not come out as a pass\n");
    exit(2);
}
mup_json("$out/verify-report.pass.json", $verify);

$failRows = $rows;
$failRows[1]['detail'] = 'expected HTTP 200, got 404';
$failRows[1]['http_status'] = 404;
$failRows[1]['ok'] = false;
$failRows[1]['status'] = JourneyOracle::FAIL;
$verifyFail = JourneyOracle::report(
    JourneyOracle::convergence(['deletions' => 0, 'live_entities' => 213, 'result' => 'pass', 'verifier' => 'plan-reconciliation/v1']),
    $failRows,
    $journeys,
    ['products', 'pages', 'store settings'],
    'mup2',
    (string) $plan['plan_digest']
);
mup_json("$out/verify-report.fail-journey.json", $verifyFail);

// ------------------------------------------------------- checkpoint catalog
// Shaped by the real builder from the evidence an operator-directed promotion
// on a non-SSH transport leaves behind: one active scoped receipt covering the
// database checkpoint and nothing else.
$catalog = CheckpointCatalog::fromStatus(
    [
        'active' => true,
        'artifact_hash' => str_repeat('a1', 32),
        'available' => true,
        'checkpoint_sha256' => str_repeat('c3', 32),
        'created_at' => '2026-08-17T09:13:00Z',
        'generation' => 1,
        'ok' => true,
        'owner' => 'direct-0000000000000000',
        'receipt_format' => 'duo-scoped-promotion-receipt/v1',
        'receipt_id' => 'scoped-20260817-091300-0001',
        'state' => 'committed',
        'terminal' => true,
    ],
    null,
    $now
);
mup_json("$out/checkpoint-catalog.json", $catalog);

$emptyCatalog = CheckpointCatalog::fromStatus(null, null, $now);
mup_json("$out/checkpoint-catalog.empty.json", $emptyCatalog);

// ------------------------------------------------------------- text fixtures
// `duo release --plan-only --format=json` prints the rendered page and THEN
// the canonical document, so the grind needs a tail extractor rather than a
// bare `jq .`. This records both halves exactly as the command emits them.
mup_write(
    "$out/plan-only.stdout.txt",
    "environment: mup2\n"
    . "recovery profile: operator-directed\n"
    . "  because: this transport carries no rollback authority runtime\n"
    . "release to mup2? [y/N]\n"
    . Canon::encode($plan)
);

// `promote` prints one `promote phase: <name>` line per phase, in its own
// execution order (cli/duo's cmd_promote_internal()). Deploy-before-apply is
// that order, and it is what §6.1 step 9 asserts.
mup_write(
    "$out/release-phases.ordered.txt",
    "promote phase: compile\n"
    . "promote phase: promotion-begin\n"
    . "promote phase: checkpoint\n"
    . "promote phase: code-stage\n"
    . "promote phase: lifecycle-retire\n"
    . "promote phase: lifecycle-activate\n"
    . "promote phase: code-finalize\n"
    . "promote phase: apply\n"
    . "released to mup2\n"
);
mup_write(
    "$out/release-phases.apply-first.txt",
    "promote phase: compile\n"
    . "promote phase: promotion-begin\n"
    . "promote phase: checkpoint\n"
    . "promote phase: apply\n"
    . "promote phase: lifecycle-retire\n"
    . "promote phase: lifecycle-activate\n"
    . "released to mup2\n"
);

// §5.2's leak rule as the grind reads it: the human view of assess may carry
// no artifact hash, lease owner, operation id or session id, because no
// documented command consumes one. The clean sample is a real AssessRenderer
// shape; the leaking sample differs by one appended line.
$cleanHuman = "stack: WordPress 6.8.2 · PHP 8.3.33 · MariaDB 11.8.8 · single-site\n"
    . "authority: docker cli1 · read-only for this command · repo /siterepo\n"
    . "\n"
    . "surface             state_class   handling        readiness  certification       containment  recovery\n"
    . "post_type:product   authored      manage          Ready      Platform-certified  prevented    provider-state restorable\n"
    . "  meaning: authored catalog content Duo manages end to end\n"
    . "post_type:shop_order runtime      preserve local  Unsupported Platform-certified prevented    not applicable\n"
    . "  meaning: live operational state is never copied\n"
    . "table:acme_catalog  unclassified  block           Not qualified Uncertified      unknown      unknown\n"
    . "  next action: install adapter (release)\n"
    . "\n"
    . "unknown: 41 option names invisible to every installed adapter (use --format=json)\n"
    . "proposed contract written: .duo/contract/mup1/proposed.json\n";
mup_write("$out/assess-human.clean.txt", $cleanHuman);
mup_write(
    "$out/assess-human.leaks-uuid.txt",
    $cleanHuman . "operation: 7b1c9a02-4f6d-4c3e-9b21-0a5d3e8c7f14\n"
);
mup_write(
    "$out/assess-human.leaks-artifact-hash.txt",
    $cleanHuman . 'artifact: ' . str_repeat('a1', 32) . "\n"
);

sort($written, SORT_STRING);
echo "wrote " . count($written) . " fixture(s) to $out\n";
foreach ($written as $name) {
    echo "  $name\n";
}
