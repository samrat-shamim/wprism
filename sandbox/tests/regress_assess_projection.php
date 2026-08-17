<?php
/**
 * Offline characterization for the round-3 vocabulary projection
 * (docs/proposals/round-3-minimum-usable-platform.md §1.1-§1.6).
 *
 * The §1 tables are the only definition of six product words that appear in
 * `duo assess`, in `.duo/contract/projection.json`, in the frozen
 * authorization plan and in a release refusal. They are a mapping, not a
 * mechanism, which means they can drift without anything failing to run —
 * a wrong word is still a valid string. This suite is the gate that turns a
 * drifted cell into a failure: one fact vector per table cell, asserting the
 * exact projected string.
 *
 * Three assertions are about what must NEVER appear, and they run over every
 * vector rather than over a chosen one, because each is a promise MUP makes
 * in prose that only a sweep can keep: `Site-certified` (the certification
 * gate is deferred, MUP §1.4/§7), `sandboxed` (no egress control, §1.5) and
 * `compensatable` (no declared compensation action, §1.6).
 *
 * The fourth gate is the engine-adapter boundary: no plugin slug may appear
 * in cli/src/Contract, cli/src/Assess or agent/src/Assess. The boundary
 * doctrine's rule is that supporting another plugin must not require adding
 * its name to engine core, and this is the mechanical check of it.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/check.php';

require_once __DIR__ . '/../../cli/src/Contract/ProjectionVocabulary.php';

use Duo\Orchestrator\ProjectionVocabulary as V;

/**
 * A fully-populated fact vector with every dimension in its most neutral
 * state, so each case names only the facts its table cell is about.
 *
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function duo_facts(array $overrides = []): array {
    $base = [
        'operation' => 'release',
        'policy_class' => 'authored',
        'unclassified' => false,
        'external_declared' => false,
        'in_scope' => true,
        'rebuild_declared' => false,
        'resync_declared' => false,
        'unsupported_reason' => null,
        'registry' => [
            'claim_status' => 'certified',
            'evidence_status' => 'current',
            'verdict_status' => 'certified',
            'blockers' => [],
            'conditions' => [],
            'source' => 'shipped',
            'site_certified' => false,
        ],
        'provider_negotiation' => [],
        'containment' => [
            'apply_window_only' => true,
            'lifecycle_touch' => false,
            'provider_touch' => false,
            'declared_live' => false,
        ],
        'recovery' => [
            'covered_by_bundle' => null,
            'external_effect_exists' => false,
            'irreversible' => false,
        ],
    ];
    foreach ($overrides as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($base[$key])) {
            $base[$key] = array_merge($base[$key], $value);
            continue;
        }
        $base[$key] = $value;
    }

    return $base;
}

$noRegistry = ['claim_status' => null, 'evidence_status' => null, 'verdict_status' => null,
    'blockers' => ['missing_registry_entry'], 'source' => null];

/**
 * The table. Each row is [label, facts, expected-subset, expected gap action].
 * `expected-subset` is asserted key by key against the projection, so a row
 * states only the cells its §1 table row is about.
 *
 * @var list<array{0:string,1:array<string,mixed>,2:array<string,mixed>,3:string}> $cases
 */
$cases = [];

// ---------------------------------------------------------------- §1.1 state class
$cases[] = ['1.1 authored', duo_facts(), ['state_class' => 'authored'], 'nothing — supported'];
$cases[] = ['1.1 runtime', duo_facts(['policy_class' => 'runtime']),
    ['state_class' => 'runtime', 'handling' => 'preserve local'], 'nothing — supported'];
$cases[] = ['1.1 derived', duo_facts(['policy_class' => 'derived', 'rebuild_declared' => true]),
    ['state_class' => 'derived', 'handling' => 'rebuild'], 'nothing — supported'];
$cases[] = ['1.1 env becomes environment-bound', duo_facts(['policy_class' => 'env']),
    ['state_class' => 'environment-bound', 'handling' => 'rebind'], 'nothing — supported'];
$cases[] = ['1.1 managed becomes authored', duo_facts(['policy_class' => 'managed']),
    ['state_class' => 'authored', 'handling' => 'manage'], 'nothing — supported'];
$cases[] = ['1.1 pending row is unclassified', duo_facts(['unclassified' => true, 'registry' => $noRegistry]),
    ['state_class' => 'unclassified', 'handling' => 'block'], 'classify'];
$cases[] = ['1.1 no matching rule is unclassified', duo_facts(['policy_class' => null, 'registry' => $noRegistry]),
    ['state_class' => 'unclassified'], 'classify'];
$cases[] = ['1.1 declared external', duo_facts(['external_declared' => true, 'resync_declared' => true]),
    ['state_class' => 'external', 'handling' => 're-synchronize'], 'nothing — supported'];
$cases[] = ['1.1 unclassified outranks a declaration',
    duo_facts(['unclassified' => true, 'external_declared' => true, 'registry' => $noRegistry]),
    ['state_class' => 'unclassified'], 'classify'];

// ------------------------------------------------------------------- §1.2 handling
$cases[] = ['1.2 authored in scope manages', duo_facts(['in_scope' => true]),
    ['handling' => 'manage', 'meaning' => 'Duo versions this surface and applies the repository copy to the target.'],
    'nothing — supported'];
$cases[] = ['1.2 authored out of scope preserves local', duo_facts(['in_scope' => false]),
    ['handling' => 'preserve local', 'annotations' => [V::ANNOTATION_OUT_OF_SCOPE]], 'nothing — supported'];
$cases[] = ['1.2 managed carries the lifecycle annotation', duo_facts(['policy_class' => 'managed']),
    ['handling' => 'manage', 'annotations' => [V::ANNOTATION_MANAGED]], 'nothing — supported'];
$cases[] = ['1.2 derived with a declared repair path rebuilds',
    duo_facts(['policy_class' => 'derived', 'rebuild_declared' => true]),
    ['handling' => 'rebuild', 'readiness' => 'Ready'], 'nothing — supported'];
$cases[] = ['1.2 derived without a repair path is forced Not qualified',
    duo_facts([
        'policy_class' => 'derived',
        'rebuild_declared' => false,
        'unsupported_reason' => 'no bounded independent value oracle exists for this table',
    ]),
    [
        'handling' => 'rebuild',
        'readiness' => 'Not qualified',
        'annotations' => [V::ANNOTATION_NO_REPAIR_PATH_PREFIX
            . 'no bounded independent value oracle exists for this table'],
    ],
    'install adapter'];
$cases[] = ['1.2 external without a declared resync blocks',
    duo_facts(['external_declared' => true, 'resync_declared' => false]),
    ['handling' => 'block', 'annotations' => [V::ANNOTATION_EXTERNAL_NO_RESYNC]], 'declare in contract'];
$cases[] = ['1.2 unclassified blocks', duo_facts(['unclassified' => true, 'registry' => $noRegistry]),
    ['handling' => 'block',
        'meaning' => 'Ownership and semantics are unknown, so the operation refuses rather than guess.'],
    'classify'];
$cases[] = ['1.2 unknown containment with a live-reaching operation blocks',
    duo_facts([
        'containment' => ['apply_window_only' => false, 'lifecycle_touch' => true],
        'recovery' => ['external_effect_exists' => true],
    ]),
    [
        'handling' => 'block',
        'effect_containment' => 'unknown',
        'effect_recovery_semantics' => 'unknown',
        'annotations' => [V::ANNOTATION_CONTAINMENT_BLOCK],
    ],
    'declare in contract'];
$cases[] = ['1.2 unknown containment on a read-only operation does not block',
    duo_facts([
        'operation' => 'capture',
        'containment' => ['apply_window_only' => false, 'lifecycle_touch' => true],
        'recovery' => ['external_effect_exists' => true],
    ]),
    ['handling' => 'manage', 'effect_containment' => 'unknown', 'effect_recovery_semantics' => 'unknown'],
    'nothing — supported'];
$cases[] = ['1.2 unknown containment with no external effect does not block (payment keys row)',
    duo_facts([
        'policy_class' => 'env',
        'containment' => ['apply_window_only' => false, 'provider_touch' => true],
        'registry' => ['conditions' => ['env_missing site_secret_binding - duo env-set production --name=...']],
    ]),
    [
        'handling' => 'rebind',
        'readiness' => 'Ready with conditions',
        'effect_containment' => 'unknown',
        'effect_recovery_semantics' => 'not applicable',
    ],
    'provision env value'];

// ------------------------------------------------------------------ §1.3 readiness
$cases[] = ['1.3 certified with no conditions is Ready', duo_facts(),
    ['readiness' => 'Ready', 'conditions' => [], 'remediation' => null], 'nothing — supported'];
$cases[] = ['1.3 certified with a re-checked condition is Ready with conditions',
    duo_facts(['registry' => ['conditions' => ['plugin_version_mismatch rechecked at the mutation gate']]]),
    ['readiness' => 'Ready with conditions',
        'conditions' => ['plugin_version_mismatch rechecked at the mutation gate']],
    'nothing — supported'];
$cases[] = ['1.3 evidence_not_current requires requalification',
    duo_facts(['registry' => ['blockers' => ['evidence_not_current']]]),
    ['readiness' => 'Requalification required', 'remediation' => 're-certify the pinned evidence, then re-run assess'],
    'qualify in rehearsal'];
$cases[] = ['1.3 revision_not_certified requires requalification',
    duo_facts(['registry' => ['blockers' => ['revision_not_certified']]]),
    ['readiness' => 'Requalification required'], 'qualify in rehearsal'];
$cases[] = ['1.3 profile_evidence_not_current requires requalification',
    duo_facts(['registry' => ['blockers' => ['profile_evidence_not_current']]]),
    ['readiness' => 'Requalification required'], 'qualify in rehearsal'];
$cases[] = ['1.3 an experimental claim is Experimental',
    duo_facts(['registry' => ['claim_status' => 'experimental']]),
    ['readiness' => 'Experimental', 'certification_provenance' => 'Uncertified'], 'qualify in rehearsal'];
$cases[] = ['1.3 candidate evidence is Experimental',
    duo_facts(['registry' => ['evidence_status' => 'candidate']]),
    ['readiness' => 'Experimental'], 'qualify in rehearsal'];
$cases[] = ['1.3 adapter_source_uncertified is Not qualified',
    duo_facts(['registry' => ['blockers' => ['adapter_source_uncertified']]]),
    ['readiness' => 'Not qualified'], 'install adapter'];
$cases[] = ['1.3 missing_registry_entry is Not qualified',
    duo_facts(['registry' => ['blockers' => ['missing_registry_entry']]]),
    ['readiness' => 'Not qualified'], 'install adapter'];
$cases[] = ['1.3 surface_not_registered is Not qualified',
    duo_facts(['registry' => ['blockers' => ['surface_not_registered']]]),
    ['readiness' => 'Not qualified'], 'install adapter'];
$cases[] = ['1.3 an excluded claim is Unsupported',
    duo_facts(['registry' => ['claim_status' => 'excluded']]),
    ['readiness' => 'Unsupported'], 'exclude'];
$cases[] = ['1.3 an unsupported claim is Unsupported',
    duo_facts(['registry' => ['claim_status' => 'unsupported']]),
    ['readiness' => 'Unsupported', 'handling' => 'manage'],
    'exclude'];
$cases[] = ['1.3 an unsupported claim on a preserve-local surface is Unsupported with no next action',
    duo_facts(['policy_class' => 'runtime', 'registry' => ['claim_status' => 'unsupported']]),
    ['readiness' => 'Unsupported', 'handling' => 'preserve local',
        'meaning' => 'Live operational state is never copied; the target keeps its own.'],
    'nothing — supported'];
// The product spec's worked row (§2.1 "Orders and inventory | … | Runtime |
// Preserve local | Unsupported | Platform-certified") — the certified claim
// belongs to the adapter that OWNS the runtime surface, but Duo does not copy
// or write a preserve-local surface, so no readiness claim applies to it.
// grind_mup.sh step 3 caught the projection saying `Ready` here.
foreach (['capture', 'merge', 'release', 'delete'] as $op) {
    $cases[] = ["1.3 preserve local forces Unsupported for {$op} even under a certified claim",
        duo_facts(['operation' => $op, 'policy_class' => 'runtime']),
        ['readiness' => 'Unsupported', 'handling' => 'preserve local', 'state_class' => 'runtime',
            'certification_provenance' => 'Platform-certified',
            'meaning' => 'Live operational state is never copied; the target keeps its own.'],
        'nothing — supported'];
}
$cases[] = ['1.3 surface_explicitly_unsupported is Unsupported',
    duo_facts(['registry' => ['blockers' => ['surface_explicitly_unsupported']]]),
    ['readiness' => 'Unsupported'], 'exclude'];
$cases[] = ['1.3 multisite_unsupported is Unsupported',
    duo_facts(['registry' => ['blockers' => ['multisite_unsupported']]]),
    ['readiness' => 'Unsupported'], 'exclude'];
$cases[] = ['1.3 a delete named in deletion_semantics.unsupported is Unsupported',
    duo_facts([
        'operation' => 'delete',
        'unsupported_reason' => 'the open extension graph is not enumerable',
        'recovery' => ['irreversible' => true],
    ]),
    ['readiness' => 'Unsupported', 'effect_recovery_semantics' => 'irreversible'], 'exclude'];
$cases[] = ['1.3 a provider negotiation problem is an unmet condition',
    duo_facts(['provider_negotiation' => ['missing_capability']]),
    [
        'readiness' => 'Ready with conditions',
        'conditions' => [V::CONDITION_PROVIDER_NEGOTIATION_PREFIX . 'missing_capability'],
        'annotations' => [V::ANNOTATION_PROVIDER_NEGOTIATION_UNMET],
        'remediation' => 'resolve the provider negotiation problem(s) missing_capability; '
            . 'the condition is unmet and blocks at the mutation gate',
    ],
    'install adapter'];
$cases[] = ['1.3 several negotiation codes are all named',
    duo_facts(['provider_negotiation' => ['undeclared_provider', 'outside_version_range']]),
    ['readiness' => 'Ready with conditions',
        'conditions' => [
            V::CONDITION_PROVIDER_NEGOTIATION_PREFIX . 'undeclared_provider',
            V::CONDITION_PROVIDER_NEGOTIATION_PREFIX . 'outside_version_range',
        ]],
    'install adapter'];
$cases[] = ['1.3 no certified verdict and no stated boundary is Not qualified',
    duo_facts(['registry' => ['verdict_status' => null, 'claim_status' => 'uncertified',
        'evidence_status' => null, 'source' => 'site']]),
    ['readiness' => 'Not qualified', 'certification_provenance' => 'Uncertified'], 'install adapter'];

// -------------------------------------------------------------- §1.4 provenance
$cases[] = ['1.4 shipped + certified + current evidence is Platform-certified', duo_facts(),
    ['certification_provenance' => 'Platform-certified'], 'nothing — supported'];
$cases[] = ['1.4 a site adapter is Uncertified',
    duo_facts(['registry' => ['source' => 'site']]),
    ['certification_provenance' => 'Uncertified'], 'nothing — supported'];
$cases[] = ['1.4 a plugin-bundled adapter is Uncertified',
    duo_facts(['registry' => ['source' => 'plugin']]),
    ['certification_provenance' => 'Uncertified'], 'nothing — supported'];
$cases[] = ['1.4 site-certified evidence still projects Uncertified in this profile',
    duo_facts(['registry' => ['site_certified' => true, 'source' => 'site']]),
    ['certification_provenance' => 'Uncertified',
        'annotations' => [V::ANNOTATION_SITE_CERTIFICATION_DEFERRED]],
    'nothing — supported'];

// -------------------------------------------------------------- §1.5 containment
$cases[] = ['1.5 apply-window-only writes are prevented', duo_facts(),
    ['effect_containment' => 'prevented',
        'effect_containment_basis' => 'no WordPress hooks fire in the apply window'],
    'nothing — supported'];
$cases[] = ['1.5 the lifecycle window is unknown',
    duo_facts(['containment' => ['apply_window_only' => false, 'lifecycle_touch' => true]]),
    ['effect_containment' => 'unknown',
        'effect_containment_basis' => 'unknown — not enforced in this profile'],
    'nothing — supported'];
$cases[] = ['1.5 a declared provider action is unknown',
    duo_facts(['containment' => ['provider_touch' => true]]),
    ['effect_containment' => 'unknown'], 'nothing — supported'];
$cases[] = ['1.5 a reviewed contract declaration makes the effect live',
    duo_facts([
        'containment' => ['declared_live' => true],
        'recovery' => ['external_effect_exists' => true, 'covered_by_bundle' => 'code release'],
    ]),
    ['effect_containment' => 'live',
        'effect_containment_basis' => 'unknown — not enforced in this profile',
        'effect_recovery_semantics' => 'provider-state restorable'],
    'nothing — supported'];

// ----------------------------------------------------------------- §1.6 recovery
$cases[] = ['1.6 a covered surface is provider-state restorable',
    duo_facts(['recovery' => ['covered_by_bundle' => 'database checkpoint']]),
    ['effect_recovery_semantics' => 'provider-state restorable',
        'annotations' => [V::ANNOTATION_RESTORED_BY_PREFIX . 'database checkpoint']],
    'nothing — supported'];
$cases[] = ['1.6 no external effect is not applicable', duo_facts(),
    ['effect_recovery_semantics' => 'not applicable'], 'nothing — supported'];
$cases[] = ['1.6 a delete with no restore coverage is irreversible',
    duo_facts(['operation' => 'delete', 'recovery' => ['irreversible' => true]]),
    ['effect_recovery_semantics' => 'irreversible'], 'nothing — supported'];
$cases[] = ['1.6 unknown containment with a possible effect is unknown',
    duo_facts([
        'operation' => 'recover',
        'containment' => ['apply_window_only' => false, 'provider_touch' => true],
        'recovery' => ['external_effect_exists' => true],
    ]),
    ['effect_recovery_semantics' => 'unknown', 'handling' => 'block'], 'declare in contract'];
$cases[] = ['1.6 an unclassified surface never claims not applicable',
    duo_facts(['unclassified' => true, 'registry' => $noRegistry]),
    ['effect_recovery_semantics' => 'unknown'], 'classify'];

// ------------------------------------------------------- §2.1 the closed gap set
$cases[] = ['2.1 gap action: qualify in rehearsal for an unknown-containment unclassified table',
    duo_facts([
        'unclassified' => true,
        'registry' => $noRegistry,
        'containment' => ['apply_window_only' => false],
    ]),
    ['state_class' => 'unclassified', 'handling' => 'block', 'readiness' => 'Not qualified',
        'certification_provenance' => 'Uncertified', 'effect_containment' => 'unknown',
        'effect_recovery_semantics' => 'unknown',
        'remediation' => 'qualify in rehearsal, or declare it out of scope in the contract'],
    'qualify in rehearsal'];
$cases[] = ['2.1 gap action: exclude for a delete Duo refuses',
    duo_facts(['operation' => 'delete', 'unsupported_reason' => 'deletion refuses before repository mutation']),
    ['readiness' => 'Unsupported'], 'exclude'];
$cases[] = ['2.1 gap action: nothing — supported for a fully Ready row', duo_facts(),
    ['readiness' => 'Ready'], 'nothing — supported'];

// The exemplar rows MUP §2.1 prints, asserted end to end.
$cases[] = ['2.1 products row',
    duo_facts(['recovery' => ['covered_by_bundle' => 'database checkpoint']]),
    [
        'state_class' => 'authored', 'handling' => 'manage', 'readiness' => 'Ready',
        'certification_provenance' => 'Platform-certified', 'effect_containment' => 'prevented',
        'effect_recovery_semantics' => 'provider-state restorable',
    ],
    'nothing — supported'];
$cases[] = ['2.1 orders row',
    duo_facts(['policy_class' => 'runtime', 'registry' => ['claim_status' => 'unsupported']]),
    [
        'state_class' => 'runtime', 'handling' => 'preserve local', 'readiness' => 'Unsupported',
        'certification_provenance' => 'Uncertified', 'effect_containment' => 'prevented',
        'effect_recovery_semantics' => 'not applicable',
    ],
    'nothing — supported'];
$cases[] = ['2.1 derived lookup table row',
    duo_facts([
        'policy_class' => 'derived',
        'rebuild_declared' => false,
        'unsupported_reason' => 'no bounded independent value oracle exists for this table',
        'containment' => ['apply_window_only' => false, 'provider_touch' => true],
    ]),
    [
        'state_class' => 'derived', 'handling' => 'rebuild', 'readiness' => 'Not qualified',
        'certification_provenance' => 'Platform-certified', 'effect_containment' => 'unknown',
        'effect_recovery_semantics' => 'not applicable',
    ],
    'install adapter'];

duo_check(count($cases) >= 40, 'the table carries at least 40 fact vectors (' . count($cases) . ')');

$seen = [
    'state_class' => [], 'handling' => [], 'readiness' => [],
    'certification_provenance' => [], 'effect_containment' => [], 'effect_recovery_semantics' => [],
];
$gapActionsSeen = [];

foreach ($cases as $case) {
    [$label, $facts, $expected, $expectedGap] = $case;
    $projection = V::project($facts);

    foreach ($expected as $key => $value) {
        if ($key === 'annotations') {
            foreach ($value as $annotation) {
                duo_check(
                    in_array($annotation, $projection['annotations'], true),
                    "$label: annotation present"
                );
                if (!in_array($annotation, $projection['annotations'], true)) {
                    duo_check_detail('expected annotation: ' . $annotation);
                    duo_check_detail('actual: ' . duo_check_repr($projection['annotations']));
                }
            }
            continue;
        }
        duo_check_same($value, $projection[$key], "$label: $key");
    }

    foreach (array_keys($seen) as $dimension) {
        $seen[$dimension][(string) $projection[$dimension]] = true;
    }

    // Sweep invariants: run on every vector, not on a chosen one.
    foreach (V::NEVER_EMITTED as $forbidden) {
        duo_check(
            !in_array($forbidden, [
                $projection['state_class'], $projection['handling'], $projection['readiness'],
                $projection['certification_provenance'], $projection['effect_containment'],
                $projection['effect_recovery_semantics'],
            ], true),
            "$label: never emits $forbidden"
        );
    }
    duo_check(
        $projection['effect_containment'] !== 'prevented'
            || $projection['effect_containment_basis'] === V::CONTAINMENT_BASIS_PREVENTED,
        "$label: prevented containment carries its literal basis"
    );
    duo_check(is_string($projection['meaning']) && $projection['meaning'] !== '', "$label: carries a meaning");
    duo_check(
        $projection['remediation'] === null || is_string($projection['remediation']),
        "$label: remediation is a string or null"
    );

    $gap = V::gapAction($projection);
    duo_check(in_array($gap, V::GAP_ACTIONS, true), "$label: gap action is inside the closed set");
    duo_check_same($expectedGap, $gap, "$label: gap action");
    $gapActionsSeen[$gap] = true;
}

// Every cell of every §1 dimension must be produced by at least one vector,
// minus the three words this profile can never earn.
$expectedValues = [
    'state_class' => V::STATE_CLASSES,
    'handling' => V::HANDLINGS,
    'readiness' => V::READINESS,
    'certification_provenance' => array_values(array_diff(V::CERTIFICATION_PROVENANCE, V::NEVER_EMITTED)),
    'effect_containment' => array_values(array_diff(V::EFFECT_CONTAINMENT, V::NEVER_EMITTED)),
    'effect_recovery_semantics' => array_values(array_diff(V::EFFECT_RECOVERY_SEMANTICS, V::NEVER_EMITTED)),
];
foreach ($expectedValues as $dimension => $values) {
    foreach ($values as $value) {
        duo_check(isset($seen[$dimension][$value]), "table covers $dimension = $value");
    }
}
foreach (V::GAP_ACTIONS as $action) {
    duo_check(isset($gapActionsSeen[$action]), "table covers gap action = $action");
}

// A malformed fact vector is a caller bug, and it must be loud rather than
// silently defaulted: a defaulted fact is a silently-wrong product word.
$rejected = 0;
foreach ([
    ['operation' => 'promote'],
    ['policy_class' => 'unclassified'],
    ['registry' => null],
] as $bad) {
    try {
        V::project(duo_facts($bad));
    } catch (InvalidArgumentException) {
        $rejected++;
    }
}
duo_check_same(3, $rejected, 'malformed fact vectors are rejected, never defaulted');

$extra = duo_facts();
$extra['surface'] = 'anything';
try {
    V::project($extra);
    duo_check(false, 'an unknown fact key is rejected');
} catch (InvalidArgumentException) {
    duo_check(true, 'an unknown fact key is rejected');
}

// ------------------------------------------------------- the no-plugin-slug gate
//
// docs/proposals/engine-adapter-boundary.md: supporting another plugin must
// not require adding its name, schema or business rules to engine core. MUP
// §T2's exit criteria name this grep as the mechanical proof for the three
// round-3 directories. Surface labels are data — registry claim `surfaces[]`,
// manifest declared groups, the contract's `surface_labels` map — never a
// branch on a slug.
$slugs = [
    'woocommerce', 'acf', 'elementor', 'yoast', 'polylang', 'contact-form-7',
    'ninja-forms', 'paid-memberships-pro', 'the-events-calendar', 'wpml', 'gravity',
];
$repo = dirname(__DIR__, 2);
$guarded = ['cli/src/Contract', 'cli/src/Assess', 'agent/src/Assess'];
$scanned = 0;
foreach ($guarded as $relative) {
    $directory = $repo . '/' . $relative;
    if (!is_dir($directory)) {
        // Reserved and not yet populated; MUP T2 fills two of the three.
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $scanned++;
        $source = strtolower((string) file_get_contents($file->getPathname()));
        foreach ($slugs as $slug) {
            duo_check(
                !str_contains($source, $slug),
                'engine-adapter boundary: ' . $relative . '/' . $file->getFilename() . ' names no "' . $slug . '"'
            );
        }
    }
}
duo_check($scanned > 0, "the slug gate scanned at least one file ($scanned)");

duo_check_summary('regress_assess_projection');
