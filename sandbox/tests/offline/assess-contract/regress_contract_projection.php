<?php
/**
 * Offline characterization for `.duo/contract/projection.json`
 * (round-3 MUP §3.3, §3.4).
 *
 * Two properties are the whole point of this document and neither is
 * observable by reading the code.
 *
 * **Determinism.** `projection.json` is committed and reviewed. If the same
 * contract, the same registry facts and the same probe produced different
 * bytes on two runs, every review would be noise and the diff would stop
 * being evidence. The suite generates twice, and generates once more from
 * inputs whose every associative level is reversed, and compares bytes.
 *
 * **The evidence-pin flip.** MUP §3.4: "a mismatch against pinned evidence
 * flips affected surfaces to `Requalification required`". There is exactly one
 * pin left to mismatch — `registry_sha256`, the content address of the
 * reviewed dispositions the verdict was read from — so the flip is total by
 * construction: every surface, whatever it declares and whatever it used to
 * cite. That bluntness is MUP §8's declared deferral and the suite pins it,
 * including the case that used to be the narrow one (a managed surface that
 * named no subject flips too). The per-subject `bundles[]` half is now pinned
 * from the other side: the key is REFUSED in the fact object, so a caller that
 * still supplies observed bundle rows is told, rather than having them
 * silently ignored while `evidence_pins` reports `current`.
 *
 * The third assertion is structural: a surface the caller supplies no facts
 * for must project `Not qualified`, never the contract's own declaration.
 * That is MUP §3.4's "a declaration never grants authority" as a test.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../cli/src/Contract/ApplicationContract.php';
require_once __DIR__ . '/../../../../cli/src/Contract/ContractProjection.php';
require_once __DIR__ . '/../../../../cli/src/Contract/ProjectionVocabulary.php';

use Duo\Canon;
use Duo\Orchestrator\ApplicationContract;
use Duo\Orchestrator\ContractProjection;
use Duo\Orchestrator\ProjectionVocabulary as V;

// Bare 64-hex, matching `contract-unbound.json`'s `evidence_pins.registry_sha256`
// and both real producers — `ManifestDispositions::sha256()`
// (agent/src/Policy/ManifestDispositions.php:114) and
// `AssessCommand::registryProvenance()` (cli/src/Command/AssessCommand.php:716).
// The `sha256:` form belongs to `AdapterObservation`, which re-prefixes this
// number on the way into a different document
// (agent/src/Adapter/AdapterObservation.php:548).
const DUO_REGISTRY_SHA = '8fa100000000000000000000000000000000000000000000000000000000ffff';
const DUO_GENERATED_AT = '2026-08-17T09:14:02Z';

/**
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function duo_vector(string $operation, array $overrides = []): array {
    $base = [
        'operation' => $operation,
        'policy_class' => 'authored',
        'unclassified' => false,
        'external_declared' => false,
        'in_scope' => true,
        'rebuild_declared' => false,
        'resync_declared' => false,
        'unsupported_reason' => null,
        'registry' => [
            'claim_status' => 'certified',
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
            'covered_by_bundle' => 'database checkpoint',
            'external_effect_exists' => false,
            'irreversible' => false,
        ],
    ];
    foreach ($overrides as $key => $value) {
        if (is_array($value) && is_array($base[$key] ?? null) && !array_is_list($base[$key])) {
            $base[$key] = array_merge($base[$key], $value);
            continue;
        }
        $base[$key] = $value;
    }

    return $base;
}

/** @return array<string,mixed> */
function duo_projection_facts(): array {
    return [
        'operations' => ['capture', 'release'],
        'registry_sha256' => DUO_REGISTRY_SHA,
        'surfaces' => [
            'products' => [
                'operations' => [
                    'capture' => [
                        'facts' => duo_vector('capture'),
                        'expiry_and_dependencies' => ['wordpress 7.0.3', 'php 8.3.x'],
                    ],
                    'release' => [
                        'facts' => duo_vector('release'),
                        'expiry_and_dependencies' => ['wordpress 7.0.3', 'php 8.3.x', 'MariaDB 11.x'],
                    ],
                ],
            ],
            'orders' => [
                'operations' => [
                    'capture' => ['facts' => duo_vector('capture', ['policy_class' => 'runtime'])],
                    'release' => ['facts' => duo_vector('release', ['policy_class' => 'runtime'])],
                ],
            ],
        ],
    ];
}

/** @return array<string,mixed> */
function duo_inventory(): array {
    return [
        'format' => 'duo-assess-inventory/v1',
        'policy' => [
            'surface_groups' => [
                ['id' => 'products', 'kind' => 'post_type', 'class' => 'authored',
                    'declared_by' => 'core', 'count' => 214],
                ['id' => 'acme_catalog', 'kind' => 'table', 'class' => 'authored',
                    'declared_by' => 'core', 'count' => null],
                // Observed by the site, named by no declaration in the contract.
                ['id' => 'site_toolkit_queue', 'kind' => 'table', 'class' => 'authored',
                    'declared_by' => 'core', 'count' => 7],
            ],
        ],
    ];
}

/**
 * @param mixed $value
 * @return mixed
 */
function duo_reverse_levels(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('duo_reverse_levels', $value);
    }

    return array_map('duo_reverse_levels', array_reverse($value, true));
}

/**
 * @param array<string,mixed> $document
 * @return array<string,array<string,mixed>>
 */
function duo_rows_by_id(array $document): array {
    $rows = [];
    foreach ($document['surfaces'] as $row) {
        $rows[(string) $row['id']] = $row;
    }

    return $rows;
}

$contract = ApplicationContract::withDigest(
    json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/contract/contract-unbound.json'), true)
);
$probe = ['wordpress' => '7.0.3', 'php' => '8.3.33'];

// ------------------------------------------------------------- shape (§3.3)
$document = ContractProjection::generate($contract, duo_projection_facts(), $probe, duo_inventory(), DUO_GENERATED_AT);
duo_check_same('duo-site-capability-projection/v1', $document['format'], 'the projection has its own format key');
duo_check_same($contract['contract_digest'], $document['contract_digest'], 'the projection cites the contract digest');
duo_check_same(DUO_REGISTRY_SHA, $document['registry_sha256'], 'the projection records the observed registry hash');
duo_check_same(DUO_GENERATED_AT, $document['generated_at'], 'generated_at is an input, never a clock read');
duo_check_same($probe, $document['target_probe'], 'the target probe is carried verbatim');
duo_check_same(true, $document['evidence_pins']['current'], 'matching pins report current evidence');

$rows = duo_rows_by_id($document);
duo_check_same(
    ['acme_catalog', 'orders', 'products', 'site_toolkit_queue'],
    array_map(static fn (array $row): string => (string) $row['id'], $document['surfaces']),
    'surfaces are emitted in id order, contract declarations plus observed groups'
);
duo_check_same(
    // T6 §3.6 adds four: two that EXPOSE who vouched and under whose trust
    // root, and two that carry the facts gapAction() needs when it is
    // re-derived from this stored artifact rather than from a fact vector.
    ['handling', 'readiness', 'certification_provenance', 'certification_principal',
        'certification_trust_root', 'effect_containment',
        'effect_containment_basis', 'effect_recovery_semantics', 'conditions',
        'blockers', 'probable_owner',
        'expiry_and_dependencies', 'remediation', 'gap_action', 'annotations'],
    array_keys($rows['products']['operations']['release']),
    'the per-operation object carries the §3.3 keys'
);
duo_check_same(
    ['capture', 'release'],
    array_keys($rows['products']['operations']),
    'operations are emitted in the spec operation order'
);
duo_check_same('Ready', $rows['products']['operations']['release']['readiness'], 'products release is Ready');
duo_check_same(
    'Platform-certified',
    $rows['products']['operations']['release']['certification_provenance'],
    'products release is Platform-certified'
);
duo_check_same(
    'prevented',
    $rows['products']['operations']['release']['effect_containment'],
    'products release is contained'
);
duo_check_same(
    'no WordPress hooks fire in the apply window',
    $rows['products']['operations']['release']['effect_containment_basis'],
    'the containment basis is the literal §1.5 string'
);
duo_check_same(
    ['wordpress 7.0.3', 'php 8.3.x', 'MariaDB 11.x'],
    $rows['products']['operations']['release']['expiry_and_dependencies'],
    'expiry and dependencies come from the caller, not from the contract'
);
duo_check_same('Products', $rows['products']['label'], 'the label comes from the reviewed declaration');
duo_check_same(true, $rows['products']['declared'], 'a declared surface is marked declared');

// A declaration never grants authority: acme_catalog is declared, no facts
// were supplied for it, and it projects Not qualified rather than the
// contract's own words.
duo_check_same(false, isset(duo_projection_facts()['surfaces']['acme_catalog']), 'the fixture supplies no acme facts');
duo_check_same('unclassified', $rows['acme_catalog']['state_class'], 'an unclassified declaration stays unclassified');
duo_check_same('block', $rows['acme_catalog']['handling'], 'an unclassified declaration blocks');
duo_check_same(
    'Not qualified',
    $rows['acme_catalog']['operations']['release']['readiness'],
    'a surface with no supplied evidence is Not qualified, never Ready by declaration'
);
duo_check_same(
    'Uncertified',
    $rows['acme_catalog']['operations']['release']['certification_provenance'],
    'no evidence means no certification provenance'
);
duo_check_same(
    // T6 §3.6: a contract-declared surface carries no plugin attribution, so
    // the unclassified answer is the classification rule, not an adapter.
    'classify',
    $rows['acme_catalog']['operations']['release']['gap_action'],
    'the unclassified row names its gap action'
);
duo_check_same(
    null,
    $rows['acme_catalog']['operations']['release']['certification_principal'],
    'an unvouched surface names no principal'
);

// ------------------------------------------ T6: the operator's leave-local decision
// The one direction a declaration may move an unclassified surface: an
// OPERATOR decision of runtime / preserve local ("declare it out of scope in
// the contract" — the remediation every unclassified row prints) is honoured
// over the site's own unclassified facts, so the row projects Unsupported
// (never copied) and stays outside every release gate. Anything else — an
// authored declaration, a platform-default decision, a runtime decision that
// is not preserve local — keeps deferring to the site facts.
$decidedContract = $contract;
foreach ($decidedContract['declarations']['surfaces'] as $i => $surface) {
    if ($surface['id'] === 'acme_catalog') {
        $decidedContract['declarations']['surfaces'][$i] = [
            'state_class' => 'runtime', 'handling' => 'preserve local', 'decided_by' => 'operator',
        ] + $surface;
    }
}
$decidedContract = ApplicationContract::withDigest($decidedContract);
// The site's own facts for acme_catalog say unclassified (no adapter declares
// the table); the decision must win over THOSE, not only fill a fact vacuum.
$unclassifiedFacts = duo_projection_facts();
$unclassifiedFacts['surfaces']['acme_catalog'] = [
    'operations' => [
        'capture' => ['facts' => duo_vector('capture', ['policy_class' => null, 'unclassified' => true, 'registry' => ['claim_status' => null, 'verdict_status' => null, 'blockers' => ['missing_disposition_entry'], 'source' => null]])],
        'release' => ['facts' => duo_vector('release', ['policy_class' => null, 'unclassified' => true, 'registry' => ['claim_status' => null, 'verdict_status' => null, 'blockers' => ['missing_disposition_entry'], 'source' => null]])],
    ],
];
$decided = ContractProjection::generate($decidedContract, $unclassifiedFacts, $probe, duo_inventory(), DUO_GENERATED_AT);
$decidedRows = duo_rows_by_id($decided);
duo_check_same('runtime', $decidedRows['acme_catalog']['state_class'], 'an operator runtime/preserve local decision on an unclassified surface is honoured: state class runtime');
duo_check_same('preserve local', $decidedRows['acme_catalog']['handling'], '… handling preserve local');
duo_check_same('Unsupported', $decidedRows['acme_catalog']['operations']['release']['readiness'], '… readiness Unsupported (never copied), never Ready');
duo_check_same('nothing — supported', $decidedRows['acme_catalog']['operations']['release']['gap_action'], '… and no next action: the decision IS the resolution');
foreach ([
    ['authored', 'manage', 'operator', 'an authored declaration cannot make an unclassified surface anything but unclassified'],
    ['runtime', 'preserve local', 'platform-default', 'a platform-default runtime decision is not an operator review and does not override the site facts'],
    ['runtime', 'block', 'operator', 'a runtime decision that is not preserve local is not the leave-local decision'],
] as [$class, $handling, $by, $why]) {
    $other = $contract;
    foreach ($other['declarations']['surfaces'] as $i => $surface) {
        if ($surface['id'] === 'acme_catalog') {
            $other['declarations']['surfaces'][$i] = ['state_class' => $class, 'handling' => $handling, 'decided_by' => $by] + $surface;
        }
    }
    $otherRows = duo_rows_by_id(ContractProjection::generate(ApplicationContract::withDigest($other), $unclassifiedFacts, $probe, duo_inventory(), DUO_GENERATED_AT));
    duo_check_same('unclassified', $otherRows['acme_catalog']['state_class'], $why);
}

// An observed-but-undeclared surface group is present as the gap it is.
duo_check_same(false, $rows['site_toolkit_queue']['declared'], 'an undeclared observed group is marked undeclared');
duo_check_same('site_toolkit_queue', $rows['site_toolkit_queue']['label'], 'an undeclared group falls back to its id');
duo_check_same('unclassified', $rows['site_toolkit_queue']['state_class'], 'an undeclared group is unclassified');
duo_check_same('block', $rows['site_toolkit_queue']['handling'], 'an undeclared group blocks');

// --------------------------------------------------------------- determinism
$first = ContractProjection::encode($document);
$second = ContractProjection::encode(
    ContractProjection::generate($contract, duo_projection_facts(), $probe, duo_inventory(), DUO_GENERATED_AT)
);
duo_check_same($first, $second, 'identical inputs produce identical bytes');

$reversed = ContractProjection::encode(ContractProjection::generate(
    duo_reverse_levels($contract),
    duo_reverse_levels(duo_projection_facts()),
    duo_reverse_levels($probe),
    duo_reverse_levels(duo_inventory()),
    DUO_GENERATED_AT
));
duo_check_same($first, $reversed, 'the bytes do not move with the caller key order');
duo_check(str_ends_with($first, "\n"), 'the projection ends in exactly one LF');
duo_check(!str_contains($first, "\r"), 'the projection contains no CR');

// ------------------------------------------------- the evidence-pin flip
$movedRegistry = duo_projection_facts();
$movedRegistry['registry_sha256'] = 'sha256:' . str_repeat('a', 64);
$flipped = ContractProjection::generate($contract, $movedRegistry, $probe, duo_inventory(), DUO_GENERATED_AT);
duo_check_same(false, $flipped['evidence_pins']['current'], 'a moved registry hash is reported as stale');
duo_check_same(true, $flipped['evidence_pins']['stale_registry'], 'the stale registry is named');
$flippedRows = duo_rows_by_id($flipped);
foreach (['capture', 'release'] as $operation) {
    duo_check_same(
        'Requalification required',
        $flippedRows['products']['operations'][$operation]['readiness'],
        "a moved registry hash flips products/$operation to Requalification required"
    );
    duo_check_same(
        // T6 §3.6: expired evidence is closed by current certification
        // evidence, which rehearsal cannot produce and never could.
        'certify adapter',
        $flippedRows['products']['operations'][$operation]['gap_action'],
        "the flipped products/$operation row names a requalification gap action"
    );
    // A preserve-local surface never depended on the evidence: Duo copies
    // nothing either way, so its word is `Unsupported` before and after the
    // flip (the spec's orders row), and it carries no next action.
    duo_check_same(
        'Unsupported',
        $flippedRows['orders']['operations'][$operation]['readiness'],
        "a preserve-local surface stays Unsupported for $operation under a moved registry hash"
    );
    duo_check_same(
        'nothing — supported',
        $flippedRows['orders']['operations'][$operation]['gap_action'],
        "a preserve-local surface carries no next action for $operation, stale evidence or not"
    );
}
// The flip is total, and this is the case that proves it rather than merely
// illustrating it: `orders` cited no subject in the old shape, so under the
// per-subject pins it was the surface a narrow flip would have SPARED. There
// is no narrow flip any more — one pin covers the whole reviewed document —
// so projected as a managed surface it flips like every other.
$managedNoSubject = $movedRegistry;
$managedNoSubject['surfaces']['orders']['operations'] = [
    'capture' => ['facts' => duo_vector('capture')],
    'release' => ['facts' => duo_vector('release')],
];
$managedFlipped = ContractProjection::generate(
    $contract,
    $managedNoSubject,
    $probe,
    duo_inventory(),
    DUO_GENERATED_AT
);
$managedRows = duo_rows_by_id($managedFlipped);
duo_check_same(
    'Requalification required',
    $managedRows['orders']['operations']['release']['readiness'],
    'a moved registry hash flips every managed surface: the reviewed document is everyone\'s pin'
);
duo_check_same(
    're-certify the pinned evidence, then re-run assess',
    $flippedRows['products']['operations']['release']['remediation'],
    'the flipped row states how to get back'
);

// `evidence_pins` reports the one comparison and no residue of the other. A
// leftover `stale_bundles: []` would read as "no bundle is stale" — a standing
// all-clear about a check nothing performs — which is the dishonesty the
// narrowing exists to remove.
duo_check_same(
    ['current', 'stale_registry'],
    array_keys($document['evidence_pins']),
    'the projection reports exactly the two pin facts it can still observe'
);
duo_check_same(
    ['current' => false, 'stale_registry' => true],
    $flipped['evidence_pins'],
    'a drifted pin says so in both fields and invents no third'
);

// The other half of the narrowing: observed bundle rows are refused, not
// ignored. A caller still supplying them under a matching registry hash would
// otherwise be told `current` while its per-subject facts were dropped on the
// floor — a silent downgrade of exactly the evidence it thought it passed.
$withBundles = duo_projection_facts();
$withBundles['bundles'] = [[
    'subject' => 'manifests.storefront-commerce',
    'bundle_digest' => 'sha256:' . str_repeat('b', 64),
    'status' => 'expired',
]];
duo_check_refuses(
    static fn () => ContractProjection::generate(
        $contract,
        $withBundles,
        $probe,
        duo_inventory(),
        DUO_GENERATED_AT
    ),
    'projection_invalid',
    'observed per-subject bundle rows refuse as an unknown fact key rather than being silently dropped'
);
// A per-surface `evidence_subjects` list is a weaker case: `validateFacts()`
// closes the TOP level of the fact object only, so a leftover surface key is
// carried past validation and read by nothing. What matters is that it can no
// longer change an answer — under the per-subject pins this list decided which
// surfaces flipped, and the assertion below is that it now decides nothing.
$withSubjects = duo_projection_facts();
$withSubjects['surfaces']['products']['evidence_subjects'] = ['manifests.storefront-commerce'];
duo_check_same(
    Canon::encode($document),
    Canon::encode(
        ContractProjection::generate($contract, $withSubjects, $probe, duo_inventory(), DUO_GENERATED_AT)
    ),
    'a leftover per-surface evidence_subjects list changes no byte of the projection'
);

// ------------------------------------------- surface-level handling reduction
$mixed = duo_projection_facts();
$mixed['surfaces']['products']['operations']['release']['facts'] = duo_vector('release', [
    'containment' => ['apply_window_only' => false, 'lifecycle_touch' => true],
    'recovery' => ['external_effect_exists' => true],
]);
$mixedRows = duo_rows_by_id(
    ContractProjection::generate($contract, $mixed, $probe, duo_inventory(), DUO_GENERATED_AT)
);
duo_check_same('manage', $mixedRows['products']['operations']['capture']['handling'], 'capture still manages');
duo_check_same('block', $mixedRows['products']['operations']['release']['handling'], 'release blocks');
duo_check_same(
    'block',
    $mixedRows['products']['handling'],
    'a surface whose handling differs by operation prints the restrictive word'
);
duo_check_same(
    'Neither containment nor recovery can be shown for this operation, so it never reaches the live system.',
    $mixedRows['products']['meaning'],
    'the surface meaning follows the reduced handling'
);

// A surface whose state class disagrees across operations is a contradiction.
$inconsistent = duo_projection_facts();
$inconsistent['surfaces']['products']['operations']['release']['facts'] =
    duo_vector('release', ['policy_class' => 'runtime']);
duo_check_refuses(
    static fn () => ContractProjection::generate($contract, $inconsistent, $probe, duo_inventory(), DUO_GENERATED_AT),
    'projection_inconsistent',
    'two state classes for one surface refuse rather than picking one'
);

// -------------------------------------------------------------- input guards
duo_check_refuses(
    static fn () => ContractProjection::generate(
        $contract,
        array_merge(duo_projection_facts(), ['extra' => 1]),
        $probe,
        duo_inventory(),
        DUO_GENERATED_AT
    ),
    'projection_invalid',
    'an unknown projection facts key refuses'
);
$noOperations = duo_projection_facts();
$noOperations['operations'] = [];
duo_check_refuses(
    static fn () => ContractProjection::generate($contract, $noOperations, $probe, duo_inventory(), DUO_GENERATED_AT),
    'projection_invalid',
    'a projection covering no operation refuses'
);
duo_check_refuses(
    static fn () => ContractProjection::generate($contract, duo_projection_facts(), [], duo_inventory(), DUO_GENERATED_AT),
    'projection_invalid',
    'an empty target probe refuses'
);
duo_check_refuses(
    static fn () => ContractProjection::generate($contract, duo_projection_facts(), $probe, duo_inventory(), ''),
    'projection_invalid',
    'an empty generated_at refuses'
);

// An inventory is optional: the projection still covers every declaration.
$withoutInventory = ContractProjection::generate($contract, duo_projection_facts(), $probe, [], DUO_GENERATED_AT);
duo_check_same(
    ['acme_catalog', 'orders', 'products'],
    array_map(static fn (array $row): string => (string) $row['id'], $withoutInventory['surfaces']),
    'without an inventory the projection covers exactly the declarations'
);

// ------------------------------------------------- the three forbidden words
$sweep = [$document, $flipped, $managedFlipped, $withoutInventory];
foreach ($sweep as $index => $candidate) {
    $encoded = Canon::encode($candidate);
    foreach (V::NEVER_EMITTED as $forbidden) {
        duo_check(
            !str_contains($encoded, '"' . $forbidden . '"'),
            "document $index never emits $forbidden"
        );
    }
    foreach ($candidate['surfaces'] as $row) {
        duo_check(in_array($row['state_class'], V::STATE_CLASSES, true), "document $index: state class in vocabulary");
        duo_check(in_array($row['handling'], V::HANDLINGS, true), "document $index: handling in vocabulary");
        foreach ($row['operations'] as $projected) {
            duo_check(in_array($projected['readiness'], V::READINESS, true), "document $index: readiness in vocabulary");
            duo_check(
                in_array($projected['gap_action'], V::GAP_ACTIONS, true),
                "document $index: gap action inside the closed set"
            );
        }
    }
}

duo_check_summary('regress_contract_projection');
