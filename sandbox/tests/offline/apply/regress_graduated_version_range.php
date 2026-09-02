<?php
/**
 * Offline product regression for WP-2.8 — the graduated `outside_version_range`
 * verdict, and the six ways it must refuse to fire.
 *
 * ## What is actually driven here
 *
 * `LifecyclePlanner::code_mismatch()` over a REAL `Policy` — a
 * `wprism-policy-snapshot/v6` envelope through `Policy::from_snapshot()`, so the
 * site-level evidence grammar runs on the same fail-closed wire a live load
 * uses; `ApplyPreparationCoordinator::enforce_code_mismatch_gate()`, the public
 * gate `wprism apply` calls; and `PlanSummary::render()`, whose `ok` is the
 * readiness answer `wprism status` prints and `wprism release` refuses on
 * (`release_target_not_clean`). Nothing is re-implemented: the verdict comes
 * out of the minting site, and each refusal out of the site that refuses.
 *
 * ## The defect this fails against
 *
 * Before WP-2.8 there were exactly two states. A plugin inside its manifest's
 * declared window, or a hard block with two exits: widen the range (a reviewed,
 * fleet-visible two-file edit, AGENTS.md rule 2) or `--force-code-mismatch`
 * (proceed with no evidence at all). WordPress updates plugins by default, so
 * the second state is the one an operator meets on an ordinary Tuesday.
 * Every graduation case below produced `outside_version_range` and refused
 * before this change.
 *
 * ## Why most of this file is about NOT graduating
 *
 * The named risk is that this verdict becomes the silent fallback rule 9
 * forbids — an assumption of benignity dressed as a finding. So the suite
 * spends its weight on the refusals: no evidence at all, evidence that does not
 * reach the installed bytes, a release that boot-fataled or diverged, a probe
 * whose ARTIFACT could not be resolved (a fact about a download, never about a
 * plugin), evidence recorded under another adapter's name, and a release the
 * recorded list does not contain. Each of those still refuses, and refuses with
 * the pre-existing message byte-for-byte — pinned here as a literal, because
 * that message is a rule-8 surface.
 *
 * `--force-code-mismatch` keeps its exact prior meaning, asserted both ways:
 * it still forces a real `outside_version_range`, and the graduated verdict
 * needs no forcing and is never reported as forced.
 *
 * The other half of "not a silent pass" is that the row is never DROPPED: it
 * stays in the `code_mismatch` bucket and in its count line, is rendered in its
 * own block by both plan renderers, and only the readiness answer subtracts it.
 * A status that stayed red would leave "safe to promote?" answering no to the
 * exact condition the verdict resolves, which is what makes that subtraction
 * the point of the WP rather than a convenience.
 *
 * ## The two vocabularies that must not drift
 *
 * `agent/` never references `cli/`, so `VersionEvidenceGrammar::OUTCOMES`
 * restates `AdapterBoundary::OUTCOMES` (WP-2.2's bisector, the process that
 * WRITES these rows). This suite loads both and asserts they are equal — the
 * restatement's whole safety argument.
 */
declare(strict_types=1);

// From offline/apply/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

$root = dirname(__DIR__, 4);

define('WPRISM_SPEC_VERSION', 2);

// ---- WordPress lifecycle primitives LifecyclePlanner::code_mismatch() reads.
// Defining validate_plugin() also short-circuits Deploy's wp-admin include
// (Deploy.php:882-886), which is why no ABSPATH is needed here.
$GLOBALS['wprism_test_plugins'] = [];
$GLOBALS['wprism_test_active'] = [];

class WP_Error {
    public function __construct(public string $message = '') {}
}
function validate_plugin(string $plugin): mixed {
    return isset($GLOBALS['wprism_test_plugins'][$plugin]) ? 0 : new WP_Error("plugin '$plugin' does not exist");
}
function get_plugins(): array {
    return $GLOBALS['wprism_test_plugins'];
}
function get_option(string $name, mixed $default = false): mixed {
    return $name === 'active_plugins' ? $GLOBALS['wprism_test_active'] : $default;
}
function is_wp_error(mixed $thing): bool {
    return $thing instanceof WP_Error;
}

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Policy/ArtifactPolicyIdentity.php';
require_once $root . '/agent/src/Promotion/Deploy.php';
require_once $root . '/agent/src/Review/PlanCategorySummary.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';
// The producer half. Loaded ONLY to hold the two outcome vocabularies equal —
// no agent class here references it, and none may.
require_once $root . '/cli/src/Adapter/AdapterBoundary.php';

use WPrism\ArtifactPolicyIdentity;
use WPrism\LifecyclePlanner;
use WPrism\Orchestrator\AdapterBoundary;
use WPrism\Orchestrator\PlanContract;
use WPrism\Orchestrator\PlanSummary;
use WPrism\PlanCategorySummary;
use WPrism\Policy;
use WPrism\VersionEvidenceGrammar;
use WPrismTest\FrozenPolicy;

const GVR_PLUGIN = 'graduated-probe/graduated-probe.php';
const GVR_MANIFEST = 'graduated-probe';
const GVR_SLUG = 'graduated-probe';

/** The synthetic adapter: one plugin, one closed window, nothing else claimed. */
function gvr_manifest(): array {
    return [
        'name' => GVR_MANIFEST,
        'spec_version' => WPRISM_SPEC_VERSION,
        'plugin' => GVR_PLUGIN,
        'version_range' => ['min' => '6.0.0', 'max' => '6.9.0'],
    ];
}

/**
 * One recorded outcome row in the bisector's own shape. The signature is what
 * makes the verdict reportable rather than merely true, so it is never blank.
 */
function gvr_outcome(string $version, string $outcome): array {
    return [
        'version' => $version,
        'outcome' => $outcome,
        'signature' => "recapture sha256:$version:$outcome",
    ];
}

/**
 * A site envelope pinning the synthetic adapter, optionally carrying one
 * evidence entry under $evidenceKey.
 *
 * @param ?list<array<string,mixed>> $outcomes null omits the whole block
 */
function gvr_site(
    ?array $outcomes,
    array $releases = ['5.9.0', '6.0.0', '6.8.7', '6.9.0', '6.9.1'],
    string $manifest = GVR_MANIFEST,
    string $evidenceKey = GVR_PLUGIN
): array {
    $site = FrozenPolicy::site([gvr_manifest()]);
    if ($outcomes !== null) {
        $site[VersionEvidenceGrammar::SITE_KEY] = [
            $evidenceKey => [
                'manifest' => $manifest,
                'slug' => GVR_SLUG,
                'releases' => $releases,
                'outcomes' => $outcomes,
            ],
        ];
    }
    return $site;
}

function gvr_policy(array $site): Policy {
    return FrozenPolicy::policy([gvr_manifest()], $site);
}

/**
 * Drive the product path: install $installed, activate it, and return the one
 * code_mismatch row the planner mints for it.
 *
 * @return array<string,mixed>|null
 */
function gvr_row(Policy $policy, string $installed): ?array {
    $GLOBALS['wprism_test_plugins'] = [GVR_PLUGIN => ['Version' => $installed]];
    $GLOBALS['wprism_test_active'] = [GVR_PLUGIN];
    $rows = LifecyclePlanner::code_mismatch($policy, ['active_plugins' => [GVR_PLUGIN]]);
    return $rows[0] ?? null;
}

// =====================================================================
// 1. The two vocabularies are one vocabulary.
// =====================================================================
wprism_check_same(
    AdapterBoundary::OUTCOMES,
    VersionEvidenceGrammar::OUTCOMES,
    'the agent-side outcome vocabulary restates the bisector\'s exactly (agent/ may not reference cli/)'
);
wprism_check_same(
    AdapterBoundary::OUTCOME_GREEN,
    VersionEvidenceGrammar::OUTCOME_GREEN,
    'the one outcome that graduates is the bisector\'s own green'
);
wprism_check(
    !in_array(VersionEvidenceGrammar::OUTCOME_GREEN, [
        AdapterBoundary::OUTCOME_BOOT_FATAL,
        AdapterBoundary::OUTCOME_DIVERGES,
        AdapterBoundary::OUTCOME_UNRESOLVED,
    ], true),
    'green is disjoint from the three outcomes that are not evidence of a surviving surface'
);

// =====================================================================
// 2. The control: inside the declared window is untouched.
// =====================================================================
$inside = gvr_policy(gvr_site(null));
wprism_check_same(null, gvr_row($inside, '6.8.7'), 'a plugin inside the declared window mints no row at all');

// =====================================================================
// 3. The pinned refusal (rule 8), and the absent-evidence guard (rule 9).
//
// This exact string is the pre-WP-2.8 message. It is written out here rather
// than derived, because a derivation would move with the code it is meant to
// hold still.
// =====================================================================
$pinnedRefusal = GVR_PLUGIN . " 6.9.1 is active in this environment, outside the '" . GVR_MANIFEST
    . "' manifest's declared version_range (>=6.0.0 <6.9.0, pinned by site.wprism.json). "
    . 'Classification guarantees for this plugin are NOT validated against this version — apply may '
    . 'silently misclassify fields. Update the plugin, pin an older manifest, or pass '
    . '--force-code-mismatch to proceed at your own risk.';

$noEvidence = gvr_row(gvr_policy(gvr_site(null)), '6.9.1');
wprism_check_same('outside_version_range', $noEvidence['issue'] ?? null, 'with NO recorded evidence the finding is unchanged');
wprism_check_same($pinnedRefusal, $noEvidence['message'] ?? null, 'the pre-existing refusal message is byte-identical');
wprism_check(
    !array_key_exists('evidence', $noEvidence ?? []),
    'a blocking finding carries no evidence key — there is nothing to report'
);

// The same site, now holding evidence for releases that do not reach the
// installed bytes. This is the case that separates "graduated" from "assumed".
$partial = gvr_row(gvr_policy(gvr_site([gvr_outcome('6.9.0', 'green')])), '6.9.1');
wprism_check_same(
    'outside_version_range',
    $partial['issue'] ?? null,
    'evidence that stops short of the installed release does not graduate it'
);
wprism_check_same($pinnedRefusal, $partial['message'] ?? null, 'and it refuses with the identical message');

// =====================================================================
// 4. The graduation itself.
// =====================================================================
$graduatedPolicy = gvr_policy(gvr_site([
    gvr_outcome('6.9.0', 'green'),
    gvr_outcome('6.9.1', 'green'),
]));
$graduated = gvr_row($graduatedPolicy, '6.9.1');
wprism_check_same(
    VersionEvidenceGrammar::VERDICT,
    $graduated['issue'] ?? null,
    'a fully-evidenced interval mints the third, NAMED verdict'
);
wprism_check_same('version_range_graduated', VersionEvidenceGrammar::VERDICT, 'the verdict word is pinned');
wprism_check_same('6.9.1', $graduated['installed_version'] ?? null, 'the verdict names the installed version');
wprism_check_same(GVR_MANIFEST, $graduated['manifest'] ?? null, 'the verdict names the manifest whose window it graduated');
wprism_check_same(
    ['min' => '6.0.0', 'max' => '6.9.0'],
    $graduated['version_range'] ?? null,
    'the verdict still reports the declared window it is outside of — it never rewrites it'
);
wprism_check_same(
    [gvr_outcome('6.9.0', 'green'), gvr_outcome('6.9.1', 'green')],
    $graduated['evidence'] ?? null,
    'the verdict carries EVERY per-release row it rests on, in recorded release order'
);
wprism_check(
    str_contains($graduated['message'] ?? '', '6.9.0 green (recapture sha256:6.9.0:green)')
        && str_contains($graduated['message'] ?? '', '6.9.1 green (recapture sha256:6.9.1:green)'),
    'the human message names each release WITH its recorded probe signature'
);
wprism_check(
    str_contains($graduated['message'] ?? '', 'a release with no recorded probe blocks')
        && str_contains($graduated['message'] ?? '', 'does not widen'),
    'the message states the limit of the evidence and that no range was widened'
);
wprism_check(
    !str_contains($graduated['message'] ?? '', '--force-code-mismatch'),
    'the graduated verdict does not advertise the force flag — nothing was forced'
);
// The release INSIDE the window is vouched for by the manifest and is not
// evidence this verdict needs; it must not appear in the reported set.
wprism_check(
    !in_array('6.8.7', array_column($graduated['evidence'] ?? [], 'version'), true),
    'releases inside the declared window are not restated as evidence'
);

// A recorded probe for a release nobody asked about does not widen the interval.
$noisy = gvr_row(gvr_policy(gvr_site([
    gvr_outcome('5.9.0', 'boot-fatal'),
    gvr_outcome('6.9.0', 'green'),
    gvr_outcome('6.9.1', 'green'),
])), '6.9.1');
wprism_check_same(
    VersionEvidenceGrammar::VERDICT,
    $noisy['issue'] ?? null,
    'a failure recorded on the OTHER side of the window is outside the interval and does not block'
);

// =====================================================================
// 5. A declared surface that DID move still blocks — all three non-green
//    outcomes, each for its own reason.
// =====================================================================
foreach ([
    'boot-fatal' => 'the exact bytes would not load',
    'round-trip-diverges' => 'recapture was not byte-identical',
    'artifact-unresolved' => 'the pinned artifact could not be fetched — a fact about the download',
] as $outcome => $why) {
    $blocked = gvr_row(gvr_policy(gvr_site([
        gvr_outcome('6.9.0', 'green'),
        gvr_outcome('6.9.1', $outcome),
    ])), '6.9.1');
    wprism_check_same(
        'outside_version_range',
        $blocked['issue'] ?? null,
        "an installed release recorded '$outcome' still blocks ($why)"
    );
    wprism_check_same($pinnedRefusal, $blocked['message'] ?? null, "and '$outcome' refuses with the pinned message");
}

// An INTERIOR release that moved blocks too, even though the installed one is
// green: the interval is what the verdict claims about, not one release.
$interior = gvr_row(gvr_policy(gvr_site([
    gvr_outcome('6.9.0', 'round-trip-diverges'),
    gvr_outcome('6.9.1', 'green'),
])), '6.9.1');
wprism_check_same(
    'outside_version_range',
    $interior['issue'] ?? null,
    'a release inside the interval that diverged blocks even when the installed one probed green'
);

// =====================================================================
// 6. Evidence that cannot be joined to this contract is not evidence.
// =====================================================================
$otherManifest = gvr_row(
    gvr_policy(gvr_site([gvr_outcome('6.9.0', 'green'), gvr_outcome('6.9.1', 'green')], manifest: 'someone-else')),
    '6.9.1'
);
wprism_check_same(
    'outside_version_range',
    $otherManifest['issue'] ?? null,
    "evidence recorded against another adapter's declared surfaces does not graduate this one"
);

$otherPlugin = gvr_row(
    gvr_policy(gvr_site(
        [gvr_outcome('6.9.0', 'green'), gvr_outcome('6.9.1', 'green')],
        evidenceKey: 'unrelated/unrelated.php'
    )),
    '6.9.1'
);
wprism_check_same(
    'outside_version_range',
    $otherPlugin['issue'] ?? null,
    'evidence keyed to a different plugin basename does not graduate this one'
);

// The installed bytes are not in the recorded release list at all: the list is
// what makes an unprobed release detectable, so bytes it never names are
// unevidenced rather than benign.
$unlisted = gvr_row(
    gvr_policy(gvr_site([gvr_outcome('6.9.0', 'green')], releases: ['6.0.0', '6.8.7', '6.9.0'])),
    '6.9.1'
);
wprism_check_same(
    'outside_version_range',
    $unlisted['issue'] ?? null,
    'an installed release absent from the recorded release list cannot graduate'
);

// A version header WordPress could not read has nothing for evidence to be
// about, and graduating it would be graduating an unknown.
$GLOBALS['wprism_test_plugins'] = [GVR_PLUGIN => []];
$GLOBALS['wprism_test_active'] = [GVR_PLUGIN];
$unknown = LifecyclePlanner::code_mismatch($graduatedPolicy, ['active_plugins' => [GVR_PLUGIN]])[0] ?? null;
wprism_check_same(
    'outside_version_range',
    $unknown['issue'] ?? null,
    'an unreadable installed version blocks no matter what evidence exists'
);
wprism_check(
    str_contains($unknown['message'] ?? '', '(unknown version)'),
    'and it keeps the pre-existing "(unknown version)" wording'
);

// =====================================================================
// 7. A downgrade is as real as an auto-update: below min graduates on the
//    same evidence rule, walking the interval toward the window's floor.
// =====================================================================
$belowSite = gvr_site(
    [gvr_outcome('5.9.0', 'green'), gvr_outcome('5.9.5', 'green')],
    releases: ['5.9.0', '5.9.5', '6.0.0', '6.8.7']
);
$below = gvr_row(gvr_policy($belowSite), '5.9.0');
wprism_check_same(
    VersionEvidenceGrammar::VERDICT,
    $below['issue'] ?? null,
    'an installed release BELOW min graduates on evidence covering [installed, min)'
);
wprism_check_same(
    [gvr_outcome('5.9.0', 'green'), gvr_outcome('5.9.5', 'green')],
    $below['evidence'] ?? null,
    'and its reported interval runs from the installed bytes up to the window floor'
);
$belowPartial = gvr_row(
    gvr_policy(gvr_site([gvr_outcome('5.9.0', 'green')], releases: ['5.9.0', '5.9.5', '6.0.0', '6.8.7'])),
    '5.9.0'
);
wprism_check_same(
    'outside_version_range',
    $belowPartial['issue'] ?? null,
    'a gap on the downgrade side blocks exactly as a gap on the upgrade side does'
);

// =====================================================================
// 8. `core` takes NEITHER path. Asserted against the shipped manifest, not
//    against a fixture that could be made to say anything.
// =====================================================================
$coreManifest = \WPrism\Canon::decode(file_get_contents($root . '/platform/adapter-library/core/manifest.json'));
wprism_check(
    !array_key_exists('plugin', $coreManifest) && !array_key_exists('version_range', $coreManifest),
    'the shipped core manifest declares no plugin and no version_range'
);
$corePolicy = gvr_policy(gvr_site([gvr_outcome('6.9.1', 'green')]));
wprism_check_same(
    [],
    array_keys(array_diff_key($corePolicy->version_ranges(), [GVR_PLUGIN => true])),
    'only the plugin-claiming manifest contributes a range; a core-shaped manifest contributes none'
);
$GLOBALS['wprism_test_plugins'] = [GVR_PLUGIN => ['Version' => '6.9.1']];
$GLOBALS['wprism_test_active'] = [];
wprism_check_same(
    [],
    LifecyclePlanner::code_mismatch($corePolicy, ['active_plugins' => []]),
    'with nothing declared active neither the refusal nor the graduated verdict can fire'
);

// =====================================================================
// 9. The apply gate: the refusal envelope is byte-identical, the graduated
//    verdict is not refused, and --force-code-mismatch keeps its meaning.
// =====================================================================
require_once $root . '/agent/src/Apply/ApplyPreparationCoordinator.php';

$blockingRow = ['issue' => 'outside_version_range', 'kind' => 'plugin', 'message' => 'BLOCKING ROW MESSAGE'];
$graduatedRow = ['issue' => VersionEvidenceGrammar::VERDICT, 'kind' => 'plugin', 'message' => 'GRADUATED ROW MESSAGE'];

$applyEnvelope = "wprism: apply refused — code_mismatch:\n\n  - BLOCKING ROW MESSAGE\n\n"
    . "Run 'wprism deploy <env>' first for lifecycle reconciliation, "
    . 'or pass --force-code-mismatch to proceed despite those lifecycle mismatches.';
try {
    \WPrism\ApplyPreparationCoordinator::enforce_code_mismatch_gate([$blockingRow], []);
    wprism_check(false, 'apply still refuses an un-evidenced outside_version_range');
} catch (\RuntimeException $e) {
    wprism_check_same($applyEnvelope, $e->getMessage(), 'the apply code_mismatch refusal envelope is byte-identical');
}
try {
    wprism_check_same(
        [],
        \WPrism\ApplyPreparationCoordinator::enforce_code_mismatch_gate([$graduatedRow], []),
        'apply does not refuse the graduated verdict, and never reports it as forced'
    );
} catch (\RuntimeException $e) {
    wprism_check(false, 'apply does not refuse the graduated verdict, and never reports it as forced');
    wprism_check_detail('refused: ' . $e->getMessage());
}
try {
    \WPrism\ApplyPreparationCoordinator::enforce_code_mismatch_gate([$graduatedRow, $blockingRow], []);
    wprism_check(false, 'a graduated row does not launder an un-evidenced one beside it');
} catch (\RuntimeException $e) {
    wprism_check_same(
        $applyEnvelope,
        $e->getMessage(),
        'the refusal lists ONLY the un-evidenced row, byte-identically, when both are present'
    );
}
wprism_check_same(
    [$blockingRow],
    \WPrism\ApplyPreparationCoordinator::enforce_code_mismatch_gate(
        [$graduatedRow, $blockingRow],
        ['force_code_mismatch' => true]
    ),
    '--force-code-mismatch still forces exactly the un-evidenced row and nothing else'
);

// =====================================================================
// 10. The deploy half. run() needs a live target, so its two refusal
//     envelopes and its blocking-issue list are pinned against the source
//     they are written in — the same idiom regress_lifecycle_planner.php
//     uses to hold an extraction still.
// =====================================================================
$deploySource = (string) file_get_contents($root . '/agent/src/Promotion/Deploy.php');
wprism_check(
    str_contains($deploySource, '"wprism: deploy refused — code_mismatch:\n\n$list\n\n"' . "\n"
        . "                . 'Install/vendor whatever is missing (or update code/) in this environment first, '\n"
        . "                . 'or pass --force-code-mismatch to proceed anyway.'"),
    'the deploy code_mismatch refusal envelope is byte-identical'
);
wprism_check(
    str_contains($deploySource, '"wprism: deploy refused — code_drift:\n\n$list\n\n"' . "\n"
        . "                . 'Reconcile the environment to a known version first, or pass --force-code-drift to proceed anyway.'"),
    'the deploy code_drift refusal envelope is byte-identical'
);
wprism_check(
    str_contains(
        $deploySource,
        "&& \$r['issue'] !== VersionEvidenceGrammar::VERDICT"
    ),
    'deploy names the graduated verdict by constant in its non-blocking predicate, never by a second copy of the string'
);
wprism_check(
    !str_contains($deploySource, "'outside_version_range',\n                ]"),
    'deploy never adds outside_version_range itself to that list — the un-evidenced finding still blocks'
);
wprism_check(
    str_contains($deploySource, "\$warnings[] = 'GRADUATED outside_version_range: ' . \$r['message'];"),
    'deploy reports the graduated verdict on every run rather than silently dropping it'
);

// =====================================================================
// 10b. The host's readiness answer. A `wprism status` that stayed red would
//      leave "safe to promote?" answering no to the exact condition this
//      verdict resolves, so the graduated row is subtracted from `ok` — and
//      from nothing else: the `N code_mismatch` count line and the JSON
//      envelope are unchanged, because the row is still in that bucket.
// =====================================================================
require_once $root . '/cli/src/Plan/PlanSummary.php';

wprism_check_same(
    VersionEvidenceGrammar::VERDICT,
    PlanContract::GRADUATED_VERSION_RANGE,
    'the host spells the same wire word the agent mints (cli:Plan reaches no agent module)'
);

$emptyPlan = array_fill_keys([
    'create', 'update', 'adopt', 'unchanged', 'drift', 'conflict',
    'collision', 'delete', 'delete_conflict', 'deleted', 'code_mismatch',
], []);
$refuseSentence = 'code_mismatch findings — wprism apply will refuse until resolved (or run with --force-code-mismatch)';

$graduatedPlan = $emptyPlan;
$graduatedPlan['code_mismatch'] = [$graduated];
$graduatedRender = PlanSummary::render($graduatedPlan);
$graduatedLines = implode("\n", $graduatedRender['lines']);
wprism_check_same(true, $graduatedRender['ok'], 'a graduated verdict alone leaves the plan safe to promote');
wprism_check(
    str_contains($graduatedRender['lines'][0] ?? '', '1 code_mismatch'),
    'and is still counted in the code_mismatch summary line — the bucket is unchanged'
);
wprism_check(
    str_contains($graduatedLines, 'VERSION_RANGE_GRADUATED')
        && str_contains($graduatedLines, GVR_PLUGIN),
    'the host renders it loudly, in its own block, naming the plugin'
);
wprism_check(
    !str_contains($graduatedLines, $refuseSentence),
    'and never prints the CODE_MISMATCH remedy sentence, which would be false about it'
);

$blockedPlan = $emptyPlan;
$blockedPlan['code_mismatch'] = [$noEvidence];
$blockedRender = PlanSummary::render($blockedPlan);
wprism_check_same(false, $blockedRender['ok'], 'an un-evidenced outside_version_range still makes status not safe to promote');
wprism_check(
    str_contains(implode("\n", $blockedRender['lines']), $refuseSentence),
    'and still carries the pre-existing remedy sentence byte-for-byte'
);

$bothPlan = $emptyPlan;
$bothPlan['code_mismatch'] = [$graduated, $noEvidence];
$bothRender = PlanSummary::render($bothPlan);
$bothLines = implode("\n", $bothRender['lines']);
wprism_check_same(false, $bothRender['ok'], 'a graduated row beside a blocking one does not make the plan promotable');
wprism_check(
    str_contains($bothLines, 'VERSION_RANGE_GRADUATED') && str_contains($bothLines, $refuseSentence),
    'both blocks render; neither is folded into the other'
);

// =====================================================================
// 11. The plan summary bucket, pinned as a decision rather than an accident.
// =====================================================================
$compatibility = (new ReflectionClass(PlanCategorySummary::class))
    ->getConstant('CODE_COMPATIBILITY_ISSUES');
wprism_check_same(
    ['missing_in_code', 'outside_version_range'],
    $compatibility,
    'the graduated verdict is deliberately absent from CODE_COMPATIBILITY_ISSUES'
);
wprism_check(
    !in_array(VersionEvidenceGrammar::VERDICT, $compatibility, true),
    'because that counter is projected as unsupported_code, which is the opposite of what this verdict claims'
);

// =====================================================================
// 12. Artifact identity: evidence binds the artifact, never the state
//     revision — and a site declaring none moves neither hash.
// =====================================================================
$plainPolicy = gvr_policy(gvr_site(null));
wprism_check_same(
    ArtifactPolicyIdentity::site_hash($plainPolicy),
    ArtifactPolicyIdentity::state_site_hash($plainPolicy),
    'a site declaring no evidence and no code has identical site and state hashes (nothing was unset)'
);
wprism_check(
    ArtifactPolicyIdentity::site_hash($graduatedPolicy) !== ArtifactPolicyIdentity::site_hash($plainPolicy),
    'adding evidence moves site_hash — an artifact compiled before it is correctly rejected'
);
wprism_check_same(
    ArtifactPolicyIdentity::state_site_hash($plainPolicy),
    ArtifactPolicyIdentity::state_site_hash($graduatedPolicy),
    'and moves no state revision: evidence names no option, meta key, post type or table'
);

// =====================================================================
// 13. The site-level grammar. Every one of these refuses at LOAD time, on the
//     frozen wire and therefore on the live one (SitePolicyValidator runs the
//     identical sequence for both).
// =====================================================================
$refuses = static function (array $site, string $contains, string $message): void {
    wprism_check_throws(
        static fn() => gvr_policy($site),
        \RuntimeException::class,
        $message,
        $contains
    );
};

$listBlock = FrozenPolicy::site([gvr_manifest()]);
$listBlock[VersionEvidenceGrammar::SITE_KEY] = ['not', 'an', 'object'];
$refuses($listBlock, 'must be an object keyed by plugin basename', 'a list-shaped evidence block is refused');

$scalarEntry = FrozenPolicy::site([gvr_manifest()]);
$scalarEntry[VersionEvidenceGrammar::SITE_KEY] = [GVR_PLUGIN => 'green'];
$refuses($scalarEntry, 'must be an object', 'a scalar entry is refused');

$noManifest = gvr_site([gvr_outcome('6.9.1', 'green')]);
unset($noManifest[VersionEvidenceGrammar::SITE_KEY][GVR_PLUGIN]['manifest']);
$refuses($noManifest, "must carry a non-empty 'manifest'", 'an entry naming no adapter is refused');

$noSlug = gvr_site([gvr_outcome('6.9.1', 'green')]);
unset($noSlug[VersionEvidenceGrammar::SITE_KEY][GVR_PLUGIN]['slug']);
$refuses($noSlug, "must carry a non-empty 'slug'", 'an entry naming no upstream plugin is refused');

$emptyReleases = gvr_site([gvr_outcome('6.9.1', 'green')], releases: []);
$refuses($emptyReleases, "must carry a non-empty 'releases' list", 'an entry with no release list is refused');

$misordered = gvr_site([gvr_outcome('6.9.1', 'green')], releases: ['6.9.1', '6.9.0']);
$refuses(
    $misordered,
    'version_compare() orders them the other way',
    'a mis-ordered release list is refused by name rather than sorted'
);

$repeated = gvr_site([gvr_outcome('6.9.1', 'green')], releases: ['6.9.0', '6.9.0', '6.9.1']);
$refuses($repeated, 'a release appears once', 'a repeated release is refused');

$strayOutcome = gvr_site([gvr_outcome('7.0.0', 'green')]);
$refuses(
    $strayOutcome,
    "must carry a 'version' that appears in this entry's own releases list",
    'an outcome for a release the list does not contain is refused'
);

$duplicateOutcome = gvr_site([gvr_outcome('6.9.1', 'green'), gvr_outcome('6.9.1', 'boot-fatal')]);
$refuses($duplicateOutcome, 'a release has one outcome', 'two outcomes for one release are refused');

$badWord = gvr_site([['version' => '6.9.1', 'outcome' => 'probably-fine', 'signature' => 'x']]);
$refuses($badWord, "must carry an 'outcome' of green | boot-fatal", 'an outcome word outside the vocabulary is refused');

$blankSignature = gvr_site([['version' => '6.9.1', 'outcome' => 'green', 'signature' => '   ']]);
$refuses(
    $blankSignature,
    'a verdict with nothing behind it',
    'a green outcome with no recorded signature is refused — the evidence IS the signature'
);

// The empty-outcomes case is legal grammar and simply never graduates: a site
// may record the release list before any probe has run.
$emptyOutcomes = gvr_row(gvr_policy(gvr_site([])), '6.9.1');
wprism_check_same(
    'outside_version_range',
    $emptyOutcomes['issue'] ?? null,
    'a recorded release list with no outcomes yet is valid grammar and graduates nothing'
);

wprism_check_summary('graduated version_range verdict');
