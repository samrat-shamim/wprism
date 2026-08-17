<?php
/**
 * Offline characterization for MUP §1.6's consequence as a release gate, and
 * for the pre-freeze refusal set of MUP §2.3.
 *
 * §1.6 is the round's hardest line, stated before it is designed around:
 * this profile declares the whole code lifecycle window `unknown`, the
 * product spec blocks any operation capable of reaching a live system on an
 * unknown, and MUP resolves that by *declaration* rather than by exemption.
 * So a release whose plan enters the lifecycle window refuses until the
 * contract carries a reviewed `external_effects[]` entry naming the window's
 * surfaces with `containment: "live"`, an explicit recovery-semantics value
 * and a reviewed reason — and once declared, the plan carries a
 * `declared_live_effect` authority row, because a known bounded live effect
 * may proceed "only with plan-bound authority".
 *
 * The second half of the suite pins the other half of §2.3's rule: every one
 * of these refusals happens BEFORE the plan is frozen and carries a §2.1 gap
 * action, never a release next action. That separation is what makes the
 * remedy correct — a release refused for an assessment reason is fixed by
 * fixing the assessment, and being told to `retry` would send an operator to
 * run a command that refuses identically.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/check.php';

require_once __DIR__ . '/../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../cli/src/Contract/ApplicationContract.php';
require_once __DIR__ . '/../../cli/src/Contract/ProjectionVocabulary.php';
require_once __DIR__ . '/../../cli/src/Recovery/RecoveryClaim.php';
require_once __DIR__ . '/../../cli/src/Recovery/RecoveryProfileSelection.php';
require_once __DIR__ . '/../../cli/src/Release/AuthorizationPlan.php';
require_once __DIR__ . '/../../cli/src/Release/NextAction.php';
require_once __DIR__ . '/../../cli/src/Release/ReleaseOutcome.php';

use Duo\Orchestrator\ApplicationContract;
use Duo\Orchestrator\AuthorizationPlan;
use Duo\Orchestrator\NextAction;
use Duo\Orchestrator\ProjectionVocabulary;
use Duo\Orchestrator\RecoveryClaim;
use Duo\Orchestrator\RecoveryProfileSelection;
use Duo\Orchestrator\ReleaseOutcome;

$fixtures = __DIR__ . '/fixtures/release';

/** @return array<string,mixed> */
function gate_fixture(string $path): array {
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException("fixture is not a JSON document: $path");
    }

    return $decoded;
}

/** @param list<array<string,mixed>> $refusals */
function gate_reasons(array $refusals): array {
    return array_map(static fn (array $refusal): string => (string) $refusal['reason_code'], $refusals);
}

/** @param list<array<string,mixed>> $refusals */
function gate_find(array $refusals, string $reasonCode): ?array {
    foreach ($refusals as $refusal) {
        if ($refusal['reason_code'] === $reasonCode) {
            return $refusal;
        }
    }

    return null;
}

$declared = ApplicationContract::withDigest(gate_fixture("$fixtures/contract-declared-unbound.json"));
$undeclared = ApplicationContract::withDigest(gate_fixture("$fixtures/contract-undeclared-unbound.json"));
$plan = gate_fixture("$fixtures/plan-clean.json");
$deletePlan = gate_fixture("$fixtures/plan-with-deletes.json");
$projection = gate_fixture("$fixtures/projection-ready.json");
$target = gate_fixture("$fixtures/target-facts.json");

$selection = RecoveryProfileSelection::decide([
    'automatic' => true,
    'profile' => RecoveryClaim::VERIFIED_AUTOMATIC,
    'reason' => 'all verified rollback capabilities are ready',
    'scoped' => false,
    'status' => [],
], ['declared_external_effects' => $declared['declarations']['external_effects']]);

/** @return array<string,mixed> */
function gate_inputs(array $overrides = []): array {
    global $declared, $plan, $projection, $target, $selection;

    return array_replace([
        'authority' => [],
        'capabilities' => [],
        'contract' => $declared,
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

// ------------------------------------------------ §1.6: the containment gate
$refusals = AuthorizationPlan::refusals(gate_inputs(['contract' => $undeclared]));
$gate = gate_find($refusals, 'release_live_effect_undeclared');
duo_check(
    $gate !== null,
    'a plan with a code lifecycle phase and no declared live effect refuses before the plan is frozen'
);
duo_check_same('declare in contract', $gate['gap_action'] ?? null, "the §1.6 refusal's next action is declare in contract");
duo_check(
    in_array((string) ($gate['gap_action'] ?? ''), ProjectionVocabulary::GAP_ACTIONS, true),
    'the §1.6 refusal carries a §2.1 gap action, not a release next action'
);
duo_check(
    str_contains((string) ($gate['remediation'] ?? ''), AuthorizationPlan::LIFECYCLE_WINDOW_SURFACE),
    'the remediation names the exact surfaces the contract entry must cover'
);

duo_check_same(
    [],
    gate_reasons(array_filter(
        AuthorizationPlan::refusals(gate_inputs()),
        static fn (array $r): bool => $r['reason_code'] === 'release_live_effect_undeclared'
    )),
    'a reviewed declaration closes the gate'
);

$unreviewed = $undeclared;
$unreviewed['declarations']['external_effects'] = [[
    'containment' => 'live',
    'decided_by' => 'unresolved',
    'effect_recovery_semantics' => 'provider-state restorable',
    'id' => 'code-lifecycle-window',
    'reason' => 'generated placeholder; a human has not reviewed this window',
    'restored_by' => 'code release',
    'surfaces' => ['plugins/themes'],
]];
$unreviewed = ApplicationContract::withDigest($unreviewed);
duo_check(
    gate_find(AuthorizationPlan::refusals(gate_inputs(['contract' => $unreviewed])), 'release_live_effect_undeclared')
        !== null,
    'the generator placeholder is not authority: an unreviewed declaration still refuses'
);

$unknownSemantics = $undeclared;
$unknownSemantics['declarations']['external_effects'] = [[
    'containment' => 'live',
    'decided_at' => '2026-08-17T09:02:11Z',
    'decided_by' => 'operator',
    'effect_recovery_semantics' => 'unknown',
    'id' => 'code-lifecycle-window',
    'reason' => 'reviewed, but the operator could not state what a failure restores',
    'surfaces' => ['plugins/themes'],
]];
$unknownSemantics = ApplicationContract::withDigest($unknownSemantics);
duo_check(
    gate_find(AuthorizationPlan::refusals(gate_inputs(['contract' => $unknownSemantics])), 'release_live_effect_undeclared')
        !== null,
    'a declaration that leaves recovery semantics unknown declares nothing and still refuses'
);

$readOnlyLifecycle = gate_inputs(['contract' => $undeclared, 'scope' => [
    'code' => ['lifecycle_phases' => ['verify'], 'plugins_changed' => 0, 'themes_changed' => 0],
    'surfaces' => ['products', 'pages'],
]]);
duo_check(
    gate_find(AuthorizationPlan::refusals($readOnlyLifecycle), 'release_live_effect_undeclared') === null,
    'a verify-only phase is a read-back, not the hook-firing window, and does not demand a declaration'
);

// ------------------------------------------- the declared_live_effect row
$document = AuthorizationPlan::build(gate_inputs());
$kinds = array_map(
    static fn (array $row): string => (string) $row['kind'],
    $document['authority_still_required']
);
duo_check(
    in_array('declared_live_effect', $kinds, true),
    'a declared live lifecycle window produces the declared_live_effect authority row (plan-bound authority)'
);
duo_check(
    in_array('operator_confirmation', $kinds, true),
    'a production-visible mutation always still needs operator confirmation'
);
duo_check_same('live', $document['effects']['containment'], 'entering the declared window makes the plan containment live');
duo_check_same(
    ProjectionVocabulary::CONTAINMENT_BASIS_UNKNOWN,
    $document['effects']['containment_basis'],
    'the live window states the profile-honest basis, never a prevention it does not enforce'
);
duo_check_same(
    'contract.declarations.external_effects[0]',
    $document['effects']['lifecycle_window']['declared_in'] ?? null,
    'the plan cites exactly which contract entry authorized the window'
);
duo_check_same([], $document['effects']['unknown_blocking'], 'a frozen plan never carries an unknown blocking effect');

$noLifecycle = AuthorizationPlan::build(gate_inputs(['scope' => [
    'code' => ['lifecycle_phases' => [], 'plugins_changed' => 0, 'themes_changed' => 0],
    'surfaces' => ['products', 'pages'],
]]));
duo_check_same(
    null,
    $noLifecycle['effects']['lifecycle_window'],
    'a release that never enters the lifecycle window says so rather than citing a declaration it did not use'
);
duo_check_same(
    'prevented',
    $noLifecycle['effects']['containment'],
    'without the lifecycle window the plan projects the prevented containment its surfaces prove'
);
duo_check_same(
    ProjectionVocabulary::CONTAINMENT_BASIS_PREVENTED,
    $noLifecycle['effects']['containment_basis'],
    'prevented carries §1.5\'s literal basis string'
);

// --------------------------------- blocking readiness, one word at a time
foreach ([
    'Experimental' => 'qualify in rehearsal',
    'Not qualified' => 'install adapter',
    'Unsupported' => 'exclude',
    'Requalification required' => 'qualify in rehearsal',
] as $readiness => $expectedAction) {
    $blocked = $projection;
    $blocked[0]['operations']['release']['readiness'] = $readiness;
    $blocked[0]['operations']['release']['gap_action'] = $expectedAction;
    $blocked[0]['operations']['release']['remediation'] = 'the assessment states the remedy for this row';
    $refusal = gate_find(
        AuthorizationPlan::refusals(gate_inputs(['projection' => $blocked])),
        'release_surface_not_releasable'
    );
    duo_check($refusal !== null, "a surface projecting $readiness in scope refuses before the plan is frozen");
    duo_check_same(
        $expectedAction,
        $refusal['gap_action'] ?? null,
        "the $readiness refusal carries the same gap action duo assess printed for that row"
    );
    duo_check(
        !NextAction::isAction((string) ($refusal['gap_action'] ?? '')),
        "the $readiness refusal never carries a release next action"
    );
}

$recomputed = $projection;
$recomputed[0]['operations']['release']['readiness'] = 'Unsupported';
unset($recomputed[0]['operations']['release']['gap_action']);
duo_check_same(
    'exclude',
    gate_find(AuthorizationPlan::refusals(gate_inputs(['projection' => $recomputed])), 'release_surface_not_releasable')['gap_action'],
    'a projection row without a stored gap action is recomputed through the one vocabulary, not guessed'
);

$unknownRecovery = $projection;
$unknownRecovery[0]['operations']['release']['effect_recovery_semantics'] = 'unknown';
duo_check(
    gate_find(AuthorizationPlan::refusals(gate_inputs(['projection' => $unknownRecovery])), 'release_recovery_semantics_unknown')
        !== null,
    'unknown effect recovery semantics on a surface this release mutates refuses'
);

// ------------------------------------------------------------- deletions
$deleteRefusals = AuthorizationPlan::refusals(gate_inputs(['plan' => $deletePlan]));
duo_check(
    gate_find($deleteRefusals, 'release_deletes_not_authorized') !== null,
    'a plan that deletes owned entities refuses without --with-deletes'
);
duo_check(
    array_key_exists('gap_action', gate_find($deleteRefusals, 'release_deletes_not_authorized'))
        && gate_find($deleteRefusals, 'release_deletes_not_authorized')['gap_action'] === null,
    'the deletes flag is an authorization flag, so its refusal carries no assessment gap action'
);

$authorizedDeletes = AuthorizationPlan::refusals(gate_inputs([
    'flags' => ['plan_only' => false, 'with_deletes' => true],
    'plan' => $deletePlan,
]));
duo_check(
    gate_find($authorizedDeletes, 'release_deletes_not_authorized') === null,
    '--with-deletes authorizes the deletion itself'
);
$unsupportedDelete = gate_find($authorizedDeletes, 'release_delete_unsupported');
duo_check(
    $unsupportedDelete !== null,
    'a surface named unsupported for delete in the contract refuses even with --with-deletes'
);
duo_check_same(
    'exclude',
    $unsupportedDelete['gap_action'] ?? null,
    'an unsupported deletion is excluded, not repaired: it is a stated boundary'
);

$registryUnsupported = AuthorizationPlan::refusals(gate_inputs([
    'contract' => $undeclared,
    'deletion_semantics' => ['unsupported' => ['pages']],
    'flags' => ['plan_only' => false, 'with_deletes' => true],
    'plan' => $deletePlan,
]));
duo_check(
    gate_find($registryUnsupported, 'release_delete_unsupported') !== null,
    "the registry's own deletion_semantics.unsupported list refuses the same way the contract's does"
);

// ------------------------------------ pre-freeze refusals stay pre-freeze
$outcome = ReleaseOutcome::refusedBeforeFreeze('production', $gate);
duo_check_same(ReleaseOutcome::REFUSED, $outcome['status'], 'a pre-freeze refusal is a refused outcome');
duo_check_same(null, $outcome['plan_digest'], 'a refused release names no frozen plan, because there is none');
duo_check_same(null, $outcome['failure'], 'a refused release carries no post-freeze failure');
duo_check_same('declare in contract', $outcome['refusal']['gap_action'], 'the gap action survives into the outcome');

duo_check_throws(
    static fn () => ReleaseOutcome::refusedBeforeFreeze('production', [
        'gap_action' => 'recover',
        'message' => 'a release next action smuggled into a pre-freeze refusal',
        'reason_code' => 'release_surface_not_releasable',
        'remediation' => 'this must not be constructible',
    ]),
    InvalidArgumentException::class,
    'a release next action cannot be attached to a pre-freeze refusal'
);

duo_check_refuses(
    static function (): void {
        $mixed = ReleaseOutcome::failedAfterFreeze(
            'production',
            'sha256:' . str_repeat('a', 64),
            'incomplete_lifecycle'
        );
        $mixed['refusal'] = ['gap_action' => 'exclude', 'message' => 'both', 'reason_code' => 'x', 'remediation' => 'y'];
        ReleaseOutcome::validate($mixed);
    },
    'release_outcome_ambiguous',
    'an outcome carrying both closed vocabularies refuses'
);

duo_check_summary('regress_release_containment_gate');
