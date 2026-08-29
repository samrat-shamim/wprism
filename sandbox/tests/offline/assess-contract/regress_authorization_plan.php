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
 *    re-computed produces the same `plan_digest`, so `.wprism/releases/` holds
 *    one file per decision and `wprism verify --plan=<digest>` has a stable
 *    name to cite. Checked against the CLOCK, not only against `frozen_at`:
 *    the two runs below are a real second apart and every clock value in the
 *    document moves between them, because the way this property broke once
 *    already was a timestamp reaching the digest through a nested document
 *    (the recovery claim's loss-boundary sentence) rather than through
 *    `frozen_at`;
 *  - **any input change invalidates it** (`plan_changed`) — the product
 *    spec's *Authorization* paragraph says "any", so this suite moves each
 *    input in turn, including ones the printed document never shows;
 *  - **`--plan-only` mutates nothing**, `.wprism/releases/` included. Writing a
 *    file into the site repository working tree is a mutation of the site
 *    repository, and MUP §2.3 says `--plan-only` "prints it and exits 0
 *    without mutating".
 *
 * Plus the `--profile` rule from the same section: a profile weaker than the
 * one the target proves — `none` included — requires the explicit
 * `--accept-weaker-recovery` authority, never a silent downgrade.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../cli/src/Contract/ApplicationContract.php';
require_once __DIR__ . '/../../../../cli/src/Recovery/RecoveryClaim.php';
require_once __DIR__ . '/../../../../cli/src/Recovery/RecoveryProfileSelection.php';
require_once __DIR__ . '/../../../../cli/src/Release/AuthorizationPlan.php';
require_once __DIR__ . '/../../../../cli/src/Release/AuthorizationPlanRenderer.php';
require_once __DIR__ . '/../../../../cli/src/Release/NextAction.php';
require_once __DIR__ . '/../../../../cli/src/Release/ReleaseOutcome.php';

use WPrism\Canon;
use WPrism\Orchestrator\ApplicationContract;
use WPrism\Orchestrator\AuthorizationPlan;
use WPrism\Orchestrator\AuthorizationPlanRenderer;
use WPrism\Orchestrator\NextAction;
use WPrism\Orchestrator\RecoveryClaim;
use WPrism\Orchestrator\RecoveryProfileSelection;
use WPrism\Orchestrator\ReleaseOutcome;

$fixtures = __DIR__ . '/../../fixtures/release';

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
    $root = sys_get_temp_dir() . '/wprism-release-' . bin2hex(random_bytes(6));
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
wprism_check_same(null, $selection['refusal'], 'a plain verified-automatic selection refuses nothing');

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
wprism_check_same(
    AuthorizationPlan::encode($first),
    AuthorizationPlan::encode($second),
    'identical inputs produce byte-identical authorization plans'
);
wprism_check_same(
    1,
    preg_match('/^sha256:[a-f0-9]{64}$/D', (string) $first['plan_digest']),
    'the plan digest is a sha256: digest'
);

$later = AuthorizationPlan::build(release_inputs(['frozen_at' => '2026-08-17T11:00:00Z']));
wprism_check_same(
    $first['plan_digest'],
    $later['plan_digest'],
    'plan_digest identifies the authorization, not the moment it was printed'
);
wprism_check(
    AuthorizationPlan::encode($first) !== AuthorizationPlan::encode($later),
    'the frozen bytes still record when the authorization was given'
);

$reordered = release_inputs();
$reordered['scope'] = array_reverse($reordered['scope'], true);
wprism_check_same(
    $first['plan_digest'],
    AuthorizationPlan::build($reordered)['plan_digest'],
    'the digest is independent of the order a caller built its input arrays in'
);

// ------------------------------------------------- the digest under a clock
// Two full builds a real second apart, driven the way `wprism release` drives
// them: one clock value, handed to the plan as `frozen_at` and to the recovery
// selection as `checkpoint_at`. This is the defect's exact shape — the claim
// interpolated the checkpoint instant into `maximum_loss_boundary`, the claim
// is digested inside the plan, and `.wprism/releases/` therefore collected one
// file per SECOND for one unchanged decision while `wprism verify --plan=` had no
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

wprism_check(
    $tick['plan']['frozen_at'] !== $tock['plan']['frozen_at'],
    'the two builds really are a clock second apart, so the checks below are not vacuous'
);
wprism_check_same(
    $tick['plan']['plan_digest'],
    $tock['plan']['plan_digest'],
    'two builds a second apart against an unchanged target produce one plan_digest, not two'
);
wprism_check_same(
    $tick['selection']['claim']['claim_digest'],
    $tock['selection']['claim']['claim_digest'],
    'the embedded recovery claim carries no clock value either, so its digest is stable too'
);
wprism_check_same(
    RecoveryClaim::encode($tick['selection']['claim']),
    RecoveryClaim::encode($tock['selection']['claim']),
    'the claim built a second later is the same bytes, which is what §2.5 byte-identity rests on'
);
wprism_check(
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
wprism_check_same(
    $tick['plan']['frozen_at'],
    $tick['plan']['recovery_profile']['checkpoint_at'],
    'the concrete checkpoint instant is carried at recovery_profile.checkpoint_at'
);
wprism_check(
    $tick['plan']['recovery_profile']['checkpoint_at'] !== $tock['plan']['recovery_profile']['checkpoint_at'],
    'checkpoint_at moves with the clock, which is precisely why it may not be inside the digest'
);
wprism_check(
    AuthorizationPlan::encode($tick['plan']) !== AuthorizationPlan::encode($tock['plan']),
    'the frozen bytes still record both clock values; only the identity is stable'
);
$redated = $tick['plan'];
$redated['recovery_profile']['checkpoint_at'] = '2099-01-01T00:00:00Z';
wprism_check_same(
    (string) $tick['plan']['plan_digest'],
    AuthorizationPlan::digest($redated),
    'digest() excludes recovery_profile.checkpoint_at exactly as it excludes frozen_at'
);
AuthorizationPlan::validate($redated);
wprism_check(true, 'a plan whose excluded clock value moved still validates against its own digest');
wprism_check_refuses(
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
wprism_check($observed['frozen_before_mutation'], 'the frozen plan exists on disk before the first mutation runs');
wprism_check_same(1, $observed['files_at_mutation'], 'exactly one frozen plan is published, with no temporary left behind');
wprism_check_same(
    AuthorizationPlan::encode($first),
    $observed['bytes_at_mutation'],
    'the mutation observes the complete canonical plan, never a truncated write'
);

$readBack = AuthorizationPlan::read($repo, (string) $first['plan_digest']);
wprism_check_json_equal($first, $readBack, 'a frozen plan reads back as the document that was frozen');
wprism_check_same(
    $path,
    AuthorizationPlan::path($repo, substr((string) $first['plan_digest'], strlen('sha256:'))),
    'path() accepts the bare hex spelling wprism verify --plan= may be given'
);
wprism_check_refuses(
    static fn () => AuthorizationPlan::path($repo, 'not-a-digest'),
    'authorization_plan_digest_invalid',
    'a plan path refuses a value that is not a sha256 digest'
);

wprism_check_refuses(
    static fn () => AuthorizationPlan::freeze($later, $repo),
    'release_plan_write_failed',
    'the same semantic plan with different presentation bytes conflicts instead of reusing older evidence'
);
wprism_check_same(
    AuthorizationPlan::encode($first),
    (string) file_get_contents($path),
    'a same-digest presentation conflict never replaces the exact bytes already frozen'
);
AuthorizationPlan::freeze($first, $repo);
wprism_check_same(
    AuthorizationPlan::encode($first),
    (string) file_get_contents($path),
    'an exact byte retry re-syncs and reuses the frozen presentation'
);

$fileSyncRepo = release_fixture_repo();
$fileSyncPath = AuthorizationPlan::path($fileSyncRepo, (string) $first['plan_digest']);
wprism_check_refuses(
    static fn () => AuthorizationPlan::freeze(
        $first,
        $fileSyncRepo,
        false,
        static fn ($handle, string $kind): bool => $kind !== 'file'
    ),
    'release_plan_write_failed',
    'a failed frozen-plan file fsync refuses before publication'
);
wprism_check_same(false, is_file($fileSyncPath), 'a failed pre-rename fsync leaves no published plan');
wprism_check_same(
    [],
    glob(dirname($fileSyncPath) . '/.wprism-release-*') ?: [],
    'a failed pre-rename fsync removes its temporary plan'
);

$directorySyncRepo = release_fixture_repo();
$directorySyncPath = AuthorizationPlan::path($directorySyncRepo, (string) $first['plan_digest']);
wprism_check_refuses(
    static fn () => AuthorizationPlan::freeze(
        $first,
        $directorySyncRepo,
        false,
        static fn ($handle, string $kind): bool => $kind !== 'directory'
    ),
    'release_plan_write_failed',
    'a failed frozen-plan parent-directory fsync refuses before mutation authority proceeds'
);
wprism_check_same(
    AuthorizationPlan::encode($first),
    (string) file_get_contents($directorySyncPath),
    'a post-rename durability refusal leaves only the exact complete plan, never truncated bytes'
);
AuthorizationPlan::freeze($first, $directorySyncRepo);
wprism_check_same(
    AuthorizationPlan::encode($first),
    (string) file_get_contents($directorySyncPath),
    'an exact retry re-syncs and read-backs an uncertain published plan before returning it'
);

// --------------------------------------------------------------- --plan-only
$planOnlyRepo = release_fixture_repo();
wprism_check_refuses(
    static fn () => AuthorizationPlan::freeze($first, $planOnlyRepo, true),
    'plan_only_must_not_freeze',
    '--plan-only refuses to freeze: printing the plan is not permission to write it'
);
wprism_check_same(
    false,
    is_dir($planOnlyRepo . '/' . AuthorizationPlan::DIRECTORY),
    '--plan-only leaves no .wprism/releases directory behind at all'
);

// ---------------------------------------------------------------- reverify
$facts = AuthorizationPlan::currentFacts(release_inputs());
AuthorizationPlan::reverify($first, $facts);
wprism_check(true, 'reverify accepts an unchanged target');

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
    wprism_check_refuses(
        static fn () => AuthorizationPlan::reverify($first, AuthorizationPlan::currentFacts(release_inputs($override))),
        'plan_changed',
        "reverify refuses after $what"
    );
}

// --------------------------------------------- the mutation-gate condition gate
//
// The hole this closes: `capabilities` is COPIED into the frozen plan, so
// before `inputs_digest.conditions_sha256` existed every condition the plan
// named re-hashed to its own frozen value and `reverify()` could not see a
// plugin deactivated or downgraded during the operator's confirmation window.
// Each refusal below therefore RELEASES against the prior defect.
$condition = static fn (array $overrides = []): array => array_replace([
    'check' => 'storefront-commerce in 11.0.0-12.0.0',
    'code' => 'plugin_version_mismatch',
    'manifest' => 'storefront-commerce',
    'observed' => '10.4.2',
    'rechecked_at' => 'mutation gate',
    'satisfied' => false,
    'subject' => 'storefront-commerce',
], $overrides);

$conditioned = release_inputs(['capabilities' => [
    [
        'certification_provenance' => 'Platform-certified',
        'conditions' => [],
        'manifest' => 'core',
        'name' => 'pages',
        'operation' => 'promote',
        'readiness' => 'Ready',
    ],
    [
        'certification_provenance' => 'Platform-certified',
        'conditions' => [$condition()],
        'manifest' => 'storefront-commerce',
        'name' => 'products',
        'operation' => 'promote',
        'readiness' => 'Ready with conditions',
    ],
]]);
$conditionedPlan = AuthorizationPlan::build($conditioned);

wprism_check(
    is_string($conditionedPlan['inputs_digest']['conditions_sha256'] ?? null)
        && preg_match('/^sha256:[a-f0-9]{64}$/D', (string) $conditionedPlan['inputs_digest']['conditions_sha256']) === 1,
    'inputs_digest carries a third key, conditions_sha256, over the per-manifest condition vector'
);
wprism_check_same(
    $conditionedPlan['inputs_digest']['conditions_sha256'],
    AuthorizationPlan::build($conditioned)['inputs_digest']['conditions_sha256'],
    'the condition digest is stable across two identical builds: it is an identity, not an observation time'
);
wprism_check(
    $conditionedPlan['inputs_digest']['conditions_sha256']
        !== AuthorizationPlan::build(release_inputs(['capabilities' => [[
            'certification_provenance' => 'Platform-certified',
            'conditions' => [$condition(['observed' => '9.9.0'])],
            'manifest' => 'storefront-commerce',
            'name' => 'products',
            'operation' => 'promote',
            'readiness' => 'Ready with conditions',
        ]]]))['inputs_digest']['conditions_sha256'],
    'the condition digest MOVES when an observed value moves — the whole point of digesting the observation'
);
wprism_check_same(
    $conditionedPlan['inputs_digest']['conditions_sha256'],
    AuthorizationPlan::currentFacts($conditioned)['conditions_sha256'],
    'currentFacts() computes the same field from the same inputs, so reverify() compares seven scalars, not six'
);
wprism_check_same(
    // Keyed by the manifest each CONDITION names, so `core` — which raises
    // none — contributes no key at all; the digest therefore does not move
    // when a condition-free surface enters scope. `recheckConditions()` still
    // re-observes `core`, because it walks the capability rows' own
    // `manifest`, which is a different question: "is the claim still there".
    // `rechecked_at` is absent: it is the constant word `mutation gate`, a
    // label rather than an observation, and two identical rows collapse to one.
    ['storefront-commerce' => [[
        'check' => 'storefront-commerce in 11.0.0-12.0.0',
        'code' => 'plugin_version_mismatch',
        'observed' => '10.4.2',
        'satisfied' => false,
        'subject' => 'storefront-commerce',
    ]]],
    AuthorizationPlan::conditionVector([
        ['conditions' => [], 'manifest' => 'core'],
        ['conditions' => [$condition(), $condition()], 'manifest' => 'storefront-commerce'],
    ]),
    'the vector is grouped per manifest, deduped, and drops rechecked_at (a constant label, not an observation)'
);

$observedSame = ['core' => [], 'storefront-commerce' => [$condition()]];
$record = AuthorizationPlan::recheckConditions($conditionedPlan, $observedSame, '2026-08-17T09:15:40Z');
wprism_check_same(
    ['at' => '2026-08-17T09:15:40Z', 'checked' => 2, 'conditions' => 1,
        'manifests' => ['core', 'storefront-commerce']],
    $record,
    'an unchanged observation is accepted and RECORDED: what, how many, and when'
);

foreach ([
    'the plugin was downgraded during the confirmation window (moved)'
        => ['core' => [], 'storefront-commerce' => [$condition(['observed' => '9.9.0'])]],
    'the plugin was deactivated, so a second condition appeared'
        => ['core' => [], 'storefront-commerce' => [$condition(), $condition([
            'check' => 'storefront-commerce active', 'code' => 'plugin_not_active', 'observed' => 'inactive',
        ])]],
    'the condition was withdrawn, so the printed readiness word is no longer the true one'
        => ['core' => [], 'storefront-commerce' => []],
] as $what => $observed) {
    wprism_check_refuses(
        static fn () => AuthorizationPlan::recheckConditions($conditionedPlan, $observed, 'now'),
        'release_condition_changed',
        "the mutation gate refuses when $what"
    );
}

foreach ([
    'a plan-named manifest is absent from the fresh report' => ['core' => []],
    'a re-observed condition names no subject to re-probe'
        => ['core' => [], 'storefront-commerce' => [$condition(['subject' => ''])]],
    'a re-observed condition is prose only, from a build that emits no machine facts'
        => ['core' => [], 'storefront-commerce' => ['storefront-commerce 10.4.2 is outside the certified range']],
] as $what => $observed) {
    wprism_check_refuses(
        static fn () => AuthorizationPlan::recheckConditions($conditionedPlan, $observed, 'now'),
        'release_condition_uncheckable',
        "an uncheckable condition BLOCKS when $what (product spec: unmet or uncheckable)"
    );
}

// The refusal is a public envelope: it names the condition, its subject, its
// manifest and what happened to it — and never the observed VALUE, the same
// rule reverify() states about target facts.
$drift = null;
try {
    AuthorizationPlan::recheckConditions(
        $conditionedPlan,
        ['core' => [], 'storefront-commerce' => [$condition(['observed' => '9.9.0'])]],
        'now'
    );
} catch (\WPrism\CommandRefusalException $refusal) {
    $drift = $refusal;
}
wprism_check_same(
    [['code' => 'plugin_version_mismatch', 'manifest' => 'storefront-commerce',
        'state' => 'moved', 'subject' => 'storefront-commerce']],
    $drift?->diagnostics,
    'the drift refusal names code, subject, manifest and state'
);
wprism_check(
    !str_contains(json_encode($drift?->diagnostics), '9.9.0')
        && !str_contains(json_encode($drift?->diagnostics), '10.4.2'),
    'and carries no observed version value: a refusal an operator pastes into a ticket is a public envelope'
);

foreach (['release_condition_changed', 'release_condition_uncheckable'] as $reasonCode) {
    wprism_check_same(
        NextAction::REQUALIFY,
        NextAction::forFailure('capability_expired'),
        "$reasonCode routes through capability_expired to requalify, never to retry"
    );
}

// The fail-closed backstop: the frozen rows are REPLACED by the observation,
// so reverify()'s digest is a real comparison even where the named refusal
// above missed a shape.
$observedRows = AuthorizationPlan::withObservedConditions(
    $conditioned['capabilities'],
    ['core' => [], 'storefront-commerce' => [$condition(['observed' => '9.9.0'])]]
);
wprism_check_refuses(
    static fn () => AuthorizationPlan::reverify(
        $conditionedPlan,
        AuthorizationPlan::currentFacts(release_inputs(['capabilities' => $observedRows]))
    ),
    'plan_changed',
    'reverify() itself refuses on a moved condition, so the gate fails closed even without the named refusal'
);

// A released outcome cannot be recorded without the recheck. That is what
// makes the gate unskippable rather than a habit of one call site.
wprism_check_refuses(
    static fn () => ReleaseOutcome::released('production', (string) $first['plan_digest'], []),
    'release_outcome_shape_invalid',
    'a released outcome that records no mutation-gate recheck refuses to exist'
);
$released = ReleaseOutcome::released('production', (string) $first['plan_digest'], [], $record);
wprism_check_same($record, $released['conditions_rechecked'], 'a released outcome carries the recheck record');
wprism_check_same(
    ['released to production', '  conditions rechecked at 2026-08-17T09:15:40Z: 1 across 2 adapter claim(s)'],
    ReleaseOutcome::humanLines($released),
    'the human view gains exactly ONE bounded line — a count, never an enumeration (MUP §4.6)'
);

// ------------------------------------------------------------ --profile rule
$weaker = RecoveryProfileSelection::decide($proof, ['requested_profile' => RecoveryClaim::OPERATOR_DIRECTED]);
wprism_check_same(
    'recovery_profile_weaker_than_provable',
    $weaker['refusal']['reason_code'] ?? null,
    'a weaker --profile than the target proves refuses without the explicit flag'
);
wprism_check_same(
    RecoveryClaim::VERIFIED_AUTOMATIC,
    $weaker['selected'],
    'the refused selection still names the profile the target proves, never the weaker request'
);
wprism_check(
    array_key_exists('gap_action', $weaker['refusal']) && $weaker['refusal']['gap_action'] === null,
    'a recovery-profile refusal carries no assessment gap action: the two closed sets are not interchangeable'
);

$none = RecoveryProfileSelection::decide($proof, [
    'accept_weaker_recovery' => true,
    'requested_profile' => RecoveryClaim::NONE,
]);
wprism_check_same(null, $none['refusal'], 'none is accepted once the explicit weaker-recovery authority is given');
wprism_check_same(RecoveryClaim::NONE, $none['selected'], '--profile=none selects the none profile');
wprism_check_same(RecoveryClaim::NONE, $none['claim']['profile'], 'the claim is rebuilt for the profile actually selected');
wprism_check(
    is_string($none['warning']) && str_contains((string) $none['warning'], 'weaker'),
    'selecting a weaker profile prints a warning naming it as weaker'
);

$stronger = RecoveryProfileSelection::decide(
    ['automatic' => false, 'profile' => RecoveryClaim::OPERATOR_DIRECTED,
        'reason' => 'missing effect provider', 'scoped' => false, 'status' => []],
    ['requested_profile' => RecoveryClaim::VERIFIED_AUTOMATIC]
);
wprism_check_same(
    'recovery_profile_unprovable',
    $stronger['refusal']['reason_code'] ?? null,
    'a --profile stronger than the target proves refuses rather than promising a restore no provider can honour'
);

// ------------------------------------------------------------ the two sets
$outcome = ReleaseOutcome::failedAfterFreeze('production', (string) $first['plan_digest'], 'incomplete_lifecycle');
wprism_check_same(NextAction::RECOVER, $outcome['failure']['next_action'], 'an incomplete lifecycle is always recover');
wprism_check_same(
    NextAction::RECONCILE,
    NextAction::forFailure('ambiguous_commitment'),
    'an ambiguous commitment is reconcile, never retry'
);
wprism_check_same(
    NextAction::RETRY_PRECONDITION,
    NextAction::preconditionFor(NextAction::RETRY),
    'retry always carries the replay-safety precondition'
);
wprism_check_refuses(
    static fn () => NextAction::forFailure('something_new'),
    'release_failure_class_unknown',
    'an unmapped failure class refuses rather than defaulting to an undocumented word'
);
foreach (NextAction::failureClasses() as $class) {
    if (!NextAction::isAction(NextAction::forFailure($class))) {
        wprism_check(false, "failure class $class maps outside the closed next-action set");
    }
}
wprism_check(true, 'every documented failure class maps into the closed next-action set');

// ------------------------------------------------------------------ renderer
$lines = AuthorizationPlanRenderer::render($first);
$rendered = implode("\n", $lines);
$position = -1;
foreach (AuthorizationPlanRenderer::SECTIONS as $section) {
    $at = array_search($section, $lines, true);
    if (!is_int($at) || $at <= $position) {
        wprism_check(false, "the renderer prints section '$section' out of the spec's order");
        break;
    }
    $position = $at;
}
wprism_check(true, 'the renderer prints the six spec sections in the order the spec lists them');
wprism_check_same(
    AuthorizationPlanRenderer::question($first),
    $lines[count($lines) - 1],
    'the human rendering ends in a single question'
);
wprism_check(
    str_contains($rendered, 'does NOT restore:'),
    'the human rendering states what the recovery profile does not restore'
);
wprism_check(
    !str_contains($rendered, (string) $first['artifact_hash']),
    'the human rendering does not print the raw artifact hash, which no documented command consumes'
);
wprism_check_refuses(
    static fn () => AuthorizationPlanRenderer::limitFromArgs(['--limit=0']),
    'invalid_arguments',
    '--limit refuses a value outside 1..200 rather than silently using the default'
);
wprism_check_same(200, AuthorizationPlanRenderer::limitFromArgs(['--limit=200']), '--limit accepts the closed ceiling');

$wide = release_inputs(['scope' => [
    'code' => ['lifecycle_phases' => [], 'plugins_changed' => 0, 'themes_changed' => 0],
    'surfaces' => array_map(static fn (int $i): string => "surface-$i", range(1, 60)),
]]);
$boundedLines = AuthorizationPlanRenderer::render(AuthorizationPlan::build($wide), 50);
wprism_check(
    in_array('  surface: 10 more (use --format=json)', $boundedLines, true),
    'a long section is bounded at the limit and says how many rows were withheld'
);
wprism_check_same(
    50,
    count(array_filter($boundedLines, static fn (string $l): bool => str_starts_with($l, '  surface: surface-'))),
    'exactly the limit is printed, and the count beside it stays the true total'
);

// ------------------------------------------------------- canonical encoding
wprism_check_same(
    Canon::encode($first),
    AuthorizationPlan::encode($first),
    'the frozen plan is canonical JSON through \WPrism\Canon, like every other .wprism/ document'
);

wprism_check_summary('regress_authorization_plan');
