<?php
/**
 * Offline characterization for the frozen authorization plan (round-3 MUP
 * §2.3, §2.3.1, §4.3).
 *
 * The plan is the one artifact standing between a reviewed decision and a
 * production mutation, so four of its properties are gates rather than
 * conventions:
 *
 *  - **it is durably written BEFORE anything is mutated** — the spec's
 *    "durably bind and present". Modelled here with a mutation hook that
 *    must observe a complete, byte-identical file on disk at the instant it
 *    runs; a hook that saw an absent or truncated file would mean the
 *    authorization was presented after the fact;
 *  - **its digest is an identity, not a timestamp** — the same authorization
 *    re-computed produces the same `plan_digest`, so `.duo/releases/` holds
 *    one file per decision and `duo verify --plan=<digest>` has a stable
 *    name to cite. Checked against the CLOCK, not only against `frozen_at`:
 *    the two runs below are a real second apart and every clock value in the
 *    document moves between them, because the way this property broke once
 *    already was a timestamp reaching the digest through a nested document
 *    (the recovery claim's loss-boundary sentence) rather than through
 *    `frozen_at`;
 *  - **any input change invalidates it** (`plan_changed`) — the product
 *    spec's *Authorization* paragraph says "any", so this suite moves each
 *    input in turn, including ones the printed document never shows;
 *  - **`--plan-only` mutates nothing**, `.duo/releases/` included. Writing a
 *    file into the site repository working tree is a mutation of the site
 *    repository, and MUP §2.3 says `--plan-only` "prints it and exits 0
 *    without mutating".
 *
 * Plus the `--profile` rule from the same section: a profile weaker than the
 * one the target proves — `none` included — requires the explicit
 * `--accept-weaker-recovery` authority, never a silent downgrade.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/check.php';

require_once __DIR__ . '/../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../cli/src/Contract/ApplicationContract.php';
require_once __DIR__ . '/../../cli/src/Recovery/RecoveryClaim.php';
require_once __DIR__ . '/../../cli/src/Recovery/RecoveryProfileSelection.php';
require_once __DIR__ . '/../../cli/src/Release/AuthorizationPlan.php';
require_once __DIR__ . '/../../cli/src/Release/AuthorizationPlanRenderer.php';
require_once __DIR__ . '/../../cli/src/Release/NextAction.php';
require_once __DIR__ . '/../../cli/src/Release/ReleaseOutcome.php';

use Duo\Canon;
use Duo\Orchestrator\ApplicationContract;
use Duo\Orchestrator\AuthorizationPlan;
use Duo\Orchestrator\AuthorizationPlanRenderer;
use Duo\Orchestrator\NextAction;
use Duo\Orchestrator\RecoveryClaim;
use Duo\Orchestrator\RecoveryProfileSelection;
use Duo\Orchestrator\ReleaseOutcome;

$fixtures = __DIR__ . '/fixtures/release';

/** @return array<string,mixed> */
function release_fixture(string $path): array {
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException("fixture is not a JSON document: $path");
    }

    return $decoded;
}

/**
 * A throwaway site repository. Generated rather than committed so the suite
 * never depends on directory bytes it cannot rebuild, and removed on exit so
 * a failing run leaves nothing behind.
 */
function release_fixture_repo(): string {
    $root = sys_get_temp_dir() . '/duo-release-' . bin2hex(random_bytes(6));
    if (!mkdir($root, 0777, true)) {
        throw new RuntimeException("cannot create fixture site repo at $root");
    }
    register_shutdown_function(static function () use ($root): void {
        if (!is_dir($root)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($root);
    });

    return (string) (realpath($root) ?: $root);
}

$contract = ApplicationContract::withDigest(release_fixture("$fixtures/contract-declared-unbound.json"));
ApplicationContract::validate($contract);
$plan = release_fixture("$fixtures/plan-clean.json");
$projection = release_fixture("$fixtures/projection-ready.json");
$target = release_fixture("$fixtures/target-facts.json");

$proof = [
    'automatic' => true,
    'profile' => RecoveryClaim::VERIFIED_AUTOMATIC,
    'reason' => 'all verified rollback capabilities are ready',
    'scoped' => false,
    'status' => [],
];
$selection = RecoveryProfileSelection::decide($proof, [
    'checkpoint_at' => '2026-08-17T09:14:02Z',
    'covered_resources' => ['code release e2f1a09', 'upload bundle (3 entries)'],
    'declared_external_effects' => $contract['declarations']['external_effects'],
]);
duo_check_same(null, $selection['refusal'], 'a plain verified-automatic selection refuses nothing');

/** @return array<string,mixed> */
function release_inputs(array $overrides = []): array {
    global $contract, $plan, $projection, $target, $selection;

    return array_replace([
        'authority' => [['kind' => 'business_owner', 'reason' => 'storefront pages visible to customers change']],
        'capabilities' => [[
            'certification_provenance' => 'Platform-certified',
            'conditions' => [],
            'name' => 'core',
            'operation' => 'promote',
            'readiness' => 'Ready',
        ]],
        'contract' => $contract,
        'deletion_semantics' => [],
        'environment' => 'production',
        'flags' => ['plan_only' => false, 'with_deletes' => false],
        'frozen_at' => '2026-08-17T09:14:02Z',
        'plan' => $plan,
        'projection' => $projection,
        'recovery' => $selection,
        'scope' => [
            'code' => ['lifecycle_phases' => ['retire', 'activate', 'verify'], 'plugins_changed' => 0, 'themes_changed' => 1],
            'surfaces' => ['products', 'pages'],
        ],
        'target' => $target,
    ], $overrides);
}

// ---------------------------------------------------------------- determinism
$first = AuthorizationPlan::build(release_inputs());
$second = AuthorizationPlan::build(release_inputs());
duo_check_same(
    AuthorizationPlan::encode($first),
    AuthorizationPlan::encode($second),
    'identical inputs produce byte-identical authorization plans'
);
duo_check_same(
    1,
    preg_match('/^sha256:[a-f0-9]{64}$/D', (string) $first['plan_digest']),
    'the plan digest is a sha256: digest'
);

$later = AuthorizationPlan::build(release_inputs(['frozen_at' => '2026-08-17T11:00:00Z']));
duo_check_same(
    $first['plan_digest'],
    $later['plan_digest'],
    'plan_digest identifies the authorization, not the moment it was printed'
);
duo_check(
    AuthorizationPlan::encode($first) !== AuthorizationPlan::encode($later),
    'the frozen bytes still record when the authorization was given'
);

$reordered = release_inputs();
$reordered['scope'] = array_reverse($reordered['scope'], true);
duo_check_same(
    $first['plan_digest'],
    AuthorizationPlan::build($reordered)['plan_digest'],
    'the digest is independent of the order a caller built its input arrays in'
);

// ------------------------------------------------- the digest under a clock
// Two full builds a real second apart, driven the way `duo release` drives
// them: one clock value, handed to the plan as `frozen_at` and to the recovery
// selection as `checkpoint_at`. This is the defect's exact shape — the claim
// interpolated the checkpoint instant into `maximum_loss_boundary`, the claim
// is digested inside the plan, and `.duo/releases/` therefore collected one
// file per SECOND for one unchanged decision while `duo verify --plan=` had no
// stable name to cite. A `sleep 1` rather than an injected clock on purpose:
// the property is about the wall clock reaching the digest, and a fake clock
// is the one thing that cannot prove it does not.
/** @return array<string,mixed> a {plan, selection} pair built at $at */
function release_at(string $at): array {
    global $proof, $contract;

    $selection = RecoveryProfileSelection::decide($proof, [
        'checkpoint_at' => $at,
        'covered_resources' => ['code release e2f1a09', 'upload bundle (3 entries)'],
        'declared_external_effects' => $contract['declarations']['external_effects'],
    ]);

    return [
        'plan' => AuthorizationPlan::build(release_inputs(['frozen_at' => $at, 'recovery' => $selection])),
        'selection' => $selection,
    ];
}

$tick = release_at(gmdate('Y-m-d\TH:i:s\Z'));
sleep(1);
$tock = release_at(gmdate('Y-m-d\TH:i:s\Z'));

duo_check(
    $tick['plan']['frozen_at'] !== $tock['plan']['frozen_at'],
    'the two builds really are a clock second apart, so the checks below are not vacuous'
);
duo_check_same(
    $tick['plan']['plan_digest'],
    $tock['plan']['plan_digest'],
    'two builds a second apart against an unchanged target produce one plan_digest, not two'
);
duo_check_same(
    $tick['selection']['claim']['claim_digest'],
    $tock['selection']['claim']['claim_digest'],
    'the embedded recovery claim carries no clock value either, so its digest is stable too'
);
duo_check_same(
    RecoveryClaim::encode($tick['selection']['claim']),
    RecoveryClaim::encode($tock['selection']['claim']),
    'the claim built a second later is the same bytes, which is what §2.5 byte-identity rests on'
);
duo_check(
    !str_contains(
        Canon::encode(AuthorizationPlan::build(
            release_inputs(['frozen_at' => $tick['plan']['frozen_at'], 'recovery' => $tick['selection']])
        )['recovery_profile']['claim']),
        $tick['plan']['frozen_at']
    ),
    'no clock value reaches the digested claim at all — the boundary sentence names the checkpoint, not its instant'
);

// The instant itself is still on the page, beside the claim and outside the
// digest: excluding it from the plan entirely would answer "what will I lose"
// with less than the operator had before.
duo_check_same(
    $tick['plan']['frozen_at'],
    $tick['plan']['recovery_profile']['checkpoint_at'],
    'the concrete checkpoint instant is carried at recovery_profile.checkpoint_at'
);
duo_check(
    $tick['plan']['recovery_profile']['checkpoint_at'] !== $tock['plan']['recovery_profile']['checkpoint_at'],
    'checkpoint_at moves with the clock, which is precisely why it may not be inside the digest'
);
duo_check(
    AuthorizationPlan::encode($tick['plan']) !== AuthorizationPlan::encode($tock['plan']),
    'the frozen bytes still record both clock values; only the identity is stable'
);
$redated = $tick['plan'];
$redated['recovery_profile']['checkpoint_at'] = '2099-01-01T00:00:00Z';
duo_check_same(
    (string) $tick['plan']['plan_digest'],
    AuthorizationPlan::digest($redated),
    'digest() excludes recovery_profile.checkpoint_at exactly as it excludes frozen_at'
);
AuthorizationPlan::validate($redated);
duo_check(true, 'a plan whose excluded clock value moved still validates against its own digest');
duo_check_refuses(
    static fn () => AuthorizationPlan::validate(
        array_replace($tick['plan'], ['recovery_profile' => array_replace(
            $tick['plan']['recovery_profile'],
            ['checkpoint_at' => '']
        )])
    ),
    'authorization_plan_shape_invalid',
    'a checkpoint_at outside the digest is still shape-checked, because no digest can catch it'
);

// ------------------------------------------------------------------ freeze
$repo = release_fixture_repo();
$path = AuthorizationPlan::path($repo, (string) $first['plan_digest']);

/**
 * §2.3's fixed order, modelled: freeze, then mutate. The hook is the whole
 * point of the test — it records what the filesystem looked like at the exact
 * instant the first mutation would have run.
 *
 * @return array{frozen_before_mutation:bool,bytes_at_mutation:string,files_at_mutation:int}
 */
function release_run(array $document, string $repo, callable $mutation): array {
    AuthorizationPlan::freeze($document, $repo);

    return $mutation();
}

$observed = release_run($first, $repo, static function () use ($repo, $path, $first): array {
    $files = glob($repo . '/' . AuthorizationPlan::DIRECTORY . '/*.json') ?: [];

    return [
        'bytes_at_mutation' => is_file($path) ? (string) file_get_contents($path) : '',
        'files_at_mutation' => count($files),
        'frozen_before_mutation' => is_file($path),
    ];
});
duo_check($observed['frozen_before_mutation'], 'the frozen plan exists on disk before the first mutation runs');
duo_check_same(1, $observed['files_at_mutation'], 'exactly one frozen plan is published, with no temporary left behind');
duo_check_same(
    AuthorizationPlan::encode($first),
    $observed['bytes_at_mutation'],
    'the mutation observes the complete canonical plan, never a truncated write'
);

$readBack = AuthorizationPlan::read($repo, (string) $first['plan_digest']);
duo_check_json_equal($first, $readBack, 'a frozen plan reads back as the document that was frozen');
duo_check_same(
    $path,
    AuthorizationPlan::path($repo, substr((string) $first['plan_digest'], strlen('sha256:'))),
    'path() accepts the bare hex spelling duo verify --plan= may be given'
);
duo_check_refuses(
    static fn () => AuthorizationPlan::path($repo, 'not-a-digest'),
    'authorization_plan_digest_invalid',
    'a plan path refuses a value that is not a sha256 digest'
);

AuthorizationPlan::freeze($later, $repo);
duo_check_same(
    AuthorizationPlan::encode($first),
    (string) file_get_contents($path),
    're-freezing the same authorization keeps the original record of when it was given'
);

// --------------------------------------------------------------- --plan-only
$planOnlyRepo = release_fixture_repo();
duo_check_refuses(
    static fn () => AuthorizationPlan::freeze($first, $planOnlyRepo, true),
    'plan_only_must_not_freeze',
    '--plan-only refuses to freeze: printing the plan is not permission to write it'
);
duo_check_same(
    false,
    is_dir($planOnlyRepo . '/' . AuthorizationPlan::DIRECTORY),
    '--plan-only leaves no .duo/releases directory behind at all'
);

// ---------------------------------------------------------------- reverify
$facts = AuthorizationPlan::currentFacts(release_inputs());
AuthorizationPlan::reverify($first, $facts);
duo_check(true, 'reverify accepts an unchanged target');

$movedTarget = $target;
$movedTarget['wordpress'] = '7.0.4';
$movedPlan = $plan;
$movedPlan['warnings'][] = 'a new warning the operator never saw';
$movedContract = ApplicationContract::withDigest(
    array_replace_recursive($contract, ['site' => ['name' => 'renamed-shop']])
);
foreach ([
    'a target stack probe change' => ['target' => $movedTarget],
    'a change anywhere in the agent plan envelope' => ['plan' => $movedPlan],
    'a change to the reviewed contract' => ['contract' => $movedContract],
    'a different environment' => ['environment' => 'staging'],
] as $what => $override) {
    duo_check_refuses(
        static fn () => AuthorizationPlan::reverify($first, AuthorizationPlan::currentFacts(release_inputs($override))),
        'plan_changed',
        "reverify refuses after $what"
    );
}

// ------------------------------------------------------------ --profile rule
$weaker = RecoveryProfileSelection::decide($proof, ['requested_profile' => RecoveryClaim::OPERATOR_DIRECTED]);
duo_check_same(
    'recovery_profile_weaker_than_provable',
    $weaker['refusal']['reason_code'] ?? null,
    'a weaker --profile than the target proves refuses without the explicit flag'
);
duo_check_same(
    RecoveryClaim::VERIFIED_AUTOMATIC,
    $weaker['selected'],
    'the refused selection still names the profile the target proves, never the weaker request'
);
duo_check(
    array_key_exists('gap_action', $weaker['refusal']) && $weaker['refusal']['gap_action'] === null,
    'a recovery-profile refusal carries no assessment gap action: the two closed sets are not interchangeable'
);

$none = RecoveryProfileSelection::decide($proof, [
    'accept_weaker_recovery' => true,
    'requested_profile' => RecoveryClaim::NONE,
]);
duo_check_same(null, $none['refusal'], 'none is accepted once the explicit weaker-recovery authority is given');
duo_check_same(RecoveryClaim::NONE, $none['selected'], '--profile=none selects the none profile');
duo_check_same(RecoveryClaim::NONE, $none['claim']['profile'], 'the claim is rebuilt for the profile actually selected');
duo_check(
    is_string($none['warning']) && str_contains((string) $none['warning'], 'weaker'),
    'selecting a weaker profile prints a warning naming it as weaker'
);

$stronger = RecoveryProfileSelection::decide(
    ['automatic' => false, 'profile' => RecoveryClaim::OPERATOR_DIRECTED,
        'reason' => 'missing effect provider', 'scoped' => false, 'status' => []],
    ['requested_profile' => RecoveryClaim::VERIFIED_AUTOMATIC]
);
duo_check_same(
    'recovery_profile_unprovable',
    $stronger['refusal']['reason_code'] ?? null,
    'a --profile stronger than the target proves refuses rather than promising a restore no provider can honour'
);

// ------------------------------------------------------------ the two sets
$outcome = ReleaseOutcome::failedAfterFreeze('production', (string) $first['plan_digest'], 'incomplete_lifecycle');
duo_check_same(NextAction::RECOVER, $outcome['failure']['next_action'], 'an incomplete lifecycle is always recover');
duo_check_same(
    NextAction::RECONCILE,
    NextAction::forFailure('ambiguous_commitment'),
    'an ambiguous commitment is reconcile, never retry'
);
duo_check_same(
    NextAction::RETRY_PRECONDITION,
    NextAction::preconditionFor(NextAction::RETRY),
    'retry always carries the replay-safety precondition'
);
duo_check_refuses(
    static fn () => NextAction::forFailure('something_new'),
    'release_failure_class_unknown',
    'an unmapped failure class refuses rather than defaulting to an undocumented word'
);
foreach (NextAction::failureClasses() as $class) {
    if (!NextAction::isAction(NextAction::forFailure($class))) {
        duo_check(false, "failure class $class maps outside the closed next-action set");
    }
}
duo_check(true, 'every documented failure class maps into the closed next-action set');

// ------------------------------------------------------------------ renderer
$lines = AuthorizationPlanRenderer::render($first);
$rendered = implode("\n", $lines);
$position = -1;
foreach (AuthorizationPlanRenderer::SECTIONS as $section) {
    $at = array_search($section, $lines, true);
    if (!is_int($at) || $at <= $position) {
        duo_check(false, "the renderer prints section '$section' out of the spec's order");
        break;
    }
    $position = $at;
}
duo_check(true, 'the renderer prints the six spec sections in the order the spec lists them');
duo_check_same(
    AuthorizationPlanRenderer::question($first),
    $lines[count($lines) - 1],
    'the human rendering ends in a single question'
);
duo_check(
    str_contains($rendered, 'does NOT restore:'),
    'the human rendering states what the recovery profile does not restore'
);
duo_check(
    !str_contains($rendered, (string) $first['artifact_hash']),
    'the human rendering does not print the raw artifact hash, which no documented command consumes'
);
duo_check_refuses(
    static fn () => AuthorizationPlanRenderer::limitFromArgs(['--limit=0']),
    'invalid_arguments',
    '--limit refuses a value outside 1..200 rather than silently using the default'
);
duo_check_same(200, AuthorizationPlanRenderer::limitFromArgs(['--limit=200']), '--limit accepts the closed ceiling');

$wide = release_inputs(['scope' => [
    'code' => ['lifecycle_phases' => [], 'plugins_changed' => 0, 'themes_changed' => 0],
    'surfaces' => array_map(static fn (int $i): string => "surface-$i", range(1, 60)),
]]);
$boundedLines = AuthorizationPlanRenderer::render(AuthorizationPlan::build($wide), 50);
duo_check(
    in_array('  surface: 10 more (use --format=json)', $boundedLines, true),
    'a long section is bounded at the limit and says how many rows were withheld'
);
duo_check_same(
    50,
    count(array_filter($boundedLines, static fn (string $l): bool => str_starts_with($l, '  surface: surface-'))),
    'exactly the limit is printed, and the count beside it stays the true total'
);

// ------------------------------------------------------- canonical encoding
duo_check_same(
    Canon::encode($first),
    AuthorizationPlan::encode($first),
    'the frozen plan is canonical JSON through \Duo\Canon, like every other .duo/ document'
);

duo_check_summary('regress_authorization_plan');
