<?php
/**
 * Offline characterization for `.wprism/contract/projection.json`
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
 * **The evidence-pin flip, and how narrow it gets.** MUP §3.4: "a mismatch
 * against pinned evidence flips affected surfaces to `Requalification
 * required`". *Affected* is the whole question, and it is decided by what can
 * be PROVED, so the suite pins both halves against each other:
 *
 * - `exact` — the caller supplied the observed `manifest_pins` and some
 *   adapter's `adapter_digest` moved. Because
 *   `ArtifactPolicyIdentity::manifest_rows()` folds each manifest's own
 *   disposition entry into that manifest's row
 *   (`agent/src/Policy/ArtifactPolicyIdentity.php:68`, hashed at `:147`),
 *   editing ONE subject in `manifests/dispositions.json` moves
 *   `registry_sha256` and exactly that adapter's digest — so the moved set
 *   names the affected surfaces, and only those flip.
 * - `whole-contract` — the registry hash moved but no PINNED adapter's digest
 *   did (a subject for an adapter this site does not load, or a document-level
 *   field), or the caller supplied no pins at all. Nothing can prove which
 *   capability is affected, so every surface flips. This is MUP §8's declared
 *   bluntness, still reachable, and the suite pins the case that used to be
 *   the narrow one (a managed surface that named no subject flips too). The
 *   fallback is what a naive per-adapter comparison would get wrong: it would
 *   flip nothing.
 *
 * The per-subject `bundles[]` half is pinned from the other side: the key is
 * REFUSED in the fact object, so a caller that still supplies observed bundle
 * rows is told, rather than having them silently ignored while `evidence_pins`
 * reports `current`. `governed_by` is held to the same standard — supplying
 * `manifest_pins` without it refuses rather than degrading to the blunt path.
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

use WPrism\Canon;
use WPrism\Orchestrator\ApplicationContract;
use WPrism\Orchestrator\ContractProjection;
use WPrism\Orchestrator\ProjectionVocabulary as V;

// Bare 64-hex, matching `contract-unbound.json`'s `evidence_pins.registry_sha256`
// and both real producers — `ManifestDispositions::sha256()`
// (agent/src/Policy/ManifestDispositions.php:114) and
// `AssessCommand::registryProvenance()` (cli/src/Command/AssessCommand.php:716).
// The `sha256:` form belongs to `AdapterObservation`, which re-prefixes this
// number on the way into a different document
// (agent/src/Adapter/AdapterObservation.php:548).
const WPRISM_REGISTRY_SHA = '8fa100000000000000000000000000000000000000000000000000000000ffff';
const WPRISM_GENERATED_AT = '2026-08-17T09:14:02Z';

// The two `declarations.manifest_pins[].adapter_digest` values
// `contract-unbound.json` carries, verbatim. Real producer: the per-manifest
// row hash in `agent/src/Policy/ArtifactPolicyIdentity.php:147`.
const WPRISM_CORE_DIGEST = '4d1e00000000000000000000000000000000000000000000000000000000ffff';
const WPRISM_COMMERCE_DIGEST = 'c07a00000000000000000000000000000000000000000000000000000000ffff';
// A dispositions edit moves the whole-document hash too — the same authored
// byte is inside both preimages (ArtifactPolicyIdentity.php:68).
const WPRISM_MOVED_REGISTRY_SHA = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const WPRISM_MOVED_COMMERCE_DIGEST = 'sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

/**
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function wprism_vector(string $operation, array $overrides = []): array {
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
function wprism_projection_facts(): array {
    return [
        'operations' => ['capture', 'release'],
        'registry_sha256' => WPRISM_REGISTRY_SHA,
        'surfaces' => [
            'products' => [
                'operations' => [
                    'capture' => [
                        'facts' => wprism_vector('capture'),
                        'expiry_and_dependencies' => ['wordpress 7.0.3', 'php 8.3.x'],
                    ],
                    'release' => [
                        'facts' => wprism_vector('release'),
                        'expiry_and_dependencies' => ['wordpress 7.0.3', 'php 8.3.x', 'MariaDB 11.x'],
                    ],
                ],
            ],
            'orders' => [
                'operations' => [
                    'capture' => ['facts' => wprism_vector('capture', ['policy_class' => 'runtime'])],
                    'release' => ['facts' => wprism_vector('release', ['policy_class' => 'runtime'])],
                ],
            ],
        ],
    ];
}

/**
 * The same facts a target that CAN observe its adapter pins supplies: the
 * observed `{name, source, adapter_digest}` rows plus the per-surface
 * attribution they oblige. Digests match `contract-unbound.json` unless an
 * override moves one.
 *
 * `products` is governed by `storefront-commerce` and `orders` by `core`, so
 * one moved digest separates the two — which is the whole claim under test.
 *
 * @param array<string,string> $moved manifest name -> the digest observed now
 * @return array<string,mixed>
 */
function wprism_pinned_facts(array $moved = []): array {
    $facts = wprism_projection_facts();
    $facts['manifest_pins'] = [
        ['name' => 'core', 'source' => 'shipped',
            'adapter_digest' => $moved['core'] ?? WPRISM_CORE_DIGEST],
        ['name' => 'storefront-commerce', 'source' => 'shipped',
            'adapter_digest' => $moved['storefront-commerce'] ?? WPRISM_COMMERCE_DIGEST],
    ];
    $facts['surfaces']['products']['governed_by'] = ['storefront-commerce'];
    $facts['surfaces']['orders']['governed_by'] = ['core'];

    return $facts;
}

/**
 * `orders` projected as an ordinary qualifying managed surface rather than the
 * preserve-local one the base fixture builds. Needed wherever the assertion is
 * "this surface did NOT flip": `Unsupported` reads the same before and after a
 * flip, so it cannot witness the narrowing, and `Ready` can.
 *
 * @param array<string,mixed> $facts
 * @return array<string,mixed>
 */
function wprism_with_qualifying_orders(array $facts): array {
    $facts['surfaces']['orders']['operations'] = [
        'capture' => ['facts' => wprism_vector('capture')],
        'release' => ['facts' => wprism_vector('release')],
    ];

    return $facts;
}

/** @return array<string,mixed> */
function wprism_inventory(): array {
    return [
        'format' => 'wprism-assess-inventory/v1',
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
function wprism_reverse_levels(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('wprism_reverse_levels', $value);
    }

    return array_map('wprism_reverse_levels', array_reverse($value, true));
}

/**
 * @param array<string,mixed> $document
 * @return array<string,array<string,mixed>>
 */
function wprism_rows_by_id(array $document): array {
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
$document = ContractProjection::generate($contract, wprism_projection_facts(), $probe, wprism_inventory(), WPRISM_GENERATED_AT);
wprism_check_same('wprism-site-capability-projection/v1', $document['format'], 'the projection has its own format key');
wprism_check_same($contract['contract_digest'], $document['contract_digest'], 'the projection cites the contract digest');
wprism_check_same(WPRISM_REGISTRY_SHA, $document['registry_sha256'], 'the projection records the observed registry hash');
wprism_check_same(WPRISM_GENERATED_AT, $document['generated_at'], 'generated_at is an input, never a clock read');
wprism_check_same($probe, $document['target_probe'], 'the target probe is carried verbatim');
wprism_check_same(true, $document['evidence_pins']['current'], 'matching pins report current evidence');

$rows = wprism_rows_by_id($document);
wprism_check_same(
    ['acme_catalog', 'orders', 'products', 'site_toolkit_queue'],
    array_map(static fn (array $row): string => (string) $row['id'], $document['surfaces']),
    'surfaces are emitted in id order, contract declarations plus observed groups'
);
wprism_check_same(
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
wprism_check_same(
    ['capture', 'release'],
    array_keys($rows['products']['operations']),
    'operations are emitted in the spec operation order'
);
wprism_check_same('Ready', $rows['products']['operations']['release']['readiness'], 'products release is Ready');
wprism_check_same(
    'Platform-certified',
    $rows['products']['operations']['release']['certification_provenance'],
    'products release is Platform-certified'
);
wprism_check_same(
    'prevented',
    $rows['products']['operations']['release']['effect_containment'],
    'products release is contained'
);
wprism_check_same(
    'no WordPress hooks fire in the apply window',
    $rows['products']['operations']['release']['effect_containment_basis'],
    'the containment basis is the literal §1.5 string'
);
wprism_check_same(
    ['wordpress 7.0.3', 'php 8.3.x', 'MariaDB 11.x'],
    $rows['products']['operations']['release']['expiry_and_dependencies'],
    'expiry and dependencies come from the caller, not from the contract'
);
wprism_check_same('Products', $rows['products']['label'], 'the label comes from the reviewed declaration');
wprism_check_same(true, $rows['products']['declared'], 'a declared surface is marked declared');

// A declaration never grants authority: acme_catalog is declared, no facts
// were supplied for it, and it projects Not qualified rather than the
// contract's own words.
wprism_check_same(false, isset(wprism_projection_facts()['surfaces']['acme_catalog']), 'the fixture supplies no acme facts');
wprism_check_same('unclassified', $rows['acme_catalog']['state_class'], 'an unclassified declaration stays unclassified');
wprism_check_same('block', $rows['acme_catalog']['handling'], 'an unclassified declaration blocks');
wprism_check_same(
    'Not qualified',
    $rows['acme_catalog']['operations']['release']['readiness'],
    'a surface with no supplied evidence is Not qualified, never Ready by declaration'
);
wprism_check_same(
    'Uncertified',
    $rows['acme_catalog']['operations']['release']['certification_provenance'],
    'no evidence means no certification provenance'
);
wprism_check_same(
    // T6 §3.6: a contract-declared surface carries no plugin attribution, so
    // the unclassified answer is the classification rule, not an adapter.
    'classify',
    $rows['acme_catalog']['operations']['release']['gap_action'],
    'the unclassified row names its gap action'
);
wprism_check_same(
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
$unclassifiedFacts = wprism_projection_facts();
$unclassifiedFacts['surfaces']['acme_catalog'] = [
    'operations' => [
        'capture' => ['facts' => wprism_vector('capture', ['policy_class' => null, 'unclassified' => true, 'registry' => ['claim_status' => null, 'verdict_status' => null, 'blockers' => ['missing_disposition_entry'], 'source' => null]])],
        'release' => ['facts' => wprism_vector('release', ['policy_class' => null, 'unclassified' => true, 'registry' => ['claim_status' => null, 'verdict_status' => null, 'blockers' => ['missing_disposition_entry'], 'source' => null]])],
    ],
];
$decided = ContractProjection::generate($decidedContract, $unclassifiedFacts, $probe, wprism_inventory(), WPRISM_GENERATED_AT);
$decidedRows = wprism_rows_by_id($decided);
wprism_check_same('runtime', $decidedRows['acme_catalog']['state_class'], 'an operator runtime/preserve local decision on an unclassified surface is honoured: state class runtime');
wprism_check_same('preserve local', $decidedRows['acme_catalog']['handling'], '… handling preserve local');
wprism_check_same('Unsupported', $decidedRows['acme_catalog']['operations']['release']['readiness'], '… readiness Unsupported (never copied), never Ready');
wprism_check_same('nothing — supported', $decidedRows['acme_catalog']['operations']['release']['gap_action'], '… and no next action: the decision IS the resolution');
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
    $otherRows = wprism_rows_by_id(ContractProjection::generate(ApplicationContract::withDigest($other), $unclassifiedFacts, $probe, wprism_inventory(), WPRISM_GENERATED_AT));
    wprism_check_same('unclassified', $otherRows['acme_catalog']['state_class'], $why);
}

// An observed-but-undeclared surface group is present as the gap it is.
wprism_check_same(false, $rows['site_toolkit_queue']['declared'], 'an undeclared observed group is marked undeclared');
wprism_check_same('site_toolkit_queue', $rows['site_toolkit_queue']['label'], 'an undeclared group falls back to its id');
wprism_check_same('unclassified', $rows['site_toolkit_queue']['state_class'], 'an undeclared group is unclassified');
wprism_check_same('block', $rows['site_toolkit_queue']['handling'], 'an undeclared group blocks');

// --------------------------------------------------------------- determinism
$first = ContractProjection::encode($document);
$second = ContractProjection::encode(
    ContractProjection::generate($contract, wprism_projection_facts(), $probe, wprism_inventory(), WPRISM_GENERATED_AT)
);
wprism_check_same($first, $second, 'identical inputs produce identical bytes');

$reversed = ContractProjection::encode(ContractProjection::generate(
    wprism_reverse_levels($contract),
    wprism_reverse_levels(wprism_projection_facts()),
    wprism_reverse_levels($probe),
    wprism_reverse_levels(wprism_inventory()),
    WPRISM_GENERATED_AT
));
wprism_check_same($first, $reversed, 'the bytes do not move with the caller key order');
wprism_check(str_ends_with($first, "\n"), 'the projection ends in exactly one LF');
wprism_check(!str_contains($first, "\r"), 'the projection contains no CR');

// ------------- the evidence-pin flip, blunt half: nothing observed the pins
// No `manifest_pins` in the fact object, so the only comparison available is
// the whole-document one and the flip is total by construction.
$movedRegistry = wprism_projection_facts();
$movedRegistry['registry_sha256'] = WPRISM_MOVED_REGISTRY_SHA;
$flipped = ContractProjection::generate($contract, $movedRegistry, $probe, wprism_inventory(), WPRISM_GENERATED_AT);
wprism_check_same(false, $flipped['evidence_pins']['current'], 'a moved registry hash is reported as stale');
wprism_check_same(true, $flipped['evidence_pins']['stale_registry'], 'the stale registry is named');
$flippedRows = wprism_rows_by_id($flipped);
foreach (['capture', 'release'] as $operation) {
    wprism_check_same(
        'Requalification required',
        $flippedRows['products']['operations'][$operation]['readiness'],
        "a moved registry hash flips products/$operation to Requalification required"
    );
    wprism_check_same(
        // T6 §3.6: expired evidence is closed by current certification
        // evidence, which rehearsal cannot produce and never could.
        'certify adapter',
        $flippedRows['products']['operations'][$operation]['gap_action'],
        "the flipped products/$operation row names a requalification gap action"
    );
    // A preserve-local surface never depended on the evidence: WPrism copies
    // nothing either way, so its word is `Unsupported` before and after the
    // flip (the spec's orders row), and it carries no next action.
    wprism_check_same(
        'Unsupported',
        $flippedRows['orders']['operations'][$operation]['readiness'],
        "a preserve-local surface stays Unsupported for $operation under a moved registry hash"
    );
    wprism_check_same(
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
    'capture' => ['facts' => wprism_vector('capture')],
    'release' => ['facts' => wprism_vector('release')],
];
$managedFlipped = ContractProjection::generate(
    $contract,
    $managedNoSubject,
    $probe,
    wprism_inventory(),
    WPRISM_GENERATED_AT
);
$managedRows = wprism_rows_by_id($managedFlipped);
wprism_check_same(
    'Requalification required',
    $managedRows['orders']['operations']['release']['readiness'],
    'a moved registry hash flips every managed surface: the reviewed document is everyone\'s pin'
);
wprism_check_same(
    're-certify the pinned evidence, then re-run assess',
    $flippedRows['products']['operations']['release']['remediation'],
    'the flipped row states how to get back'
);

// `evidence_pins` reports the comparisons it actually performed and no residue
// of one it did not. A leftover `stale_bundles: []` would read as "no bundle is
// stale" — a standing all-clear about a check nothing performs — which is the
// dishonesty the narrowing exists to remove. `stale_adapters: []` under
// `whole-contract` is NOT that: it is the literal statement "the moved set
// could not be attributed", which is why the mode word sits beside it.
wprism_check_same(
    ['current', 'invalidation', 'stale_adapters', 'stale_registry'],
    array_keys($document['evidence_pins']),
    'the projection reports exactly the four pin facts it can observe'
);
wprism_check_same(
    ['current' => false, 'invalidation' => 'whole-contract', 'stale_adapters' => [], 'stale_registry' => true],
    $flipped['evidence_pins'],
    'an unattributable drift says so in all four fields and invents no fifth'
);
wprism_check_same(
    ['current' => true, 'invalidation' => 'none', 'stale_adapters' => [], 'stale_registry' => false],
    $document['evidence_pins'],
    'matching pins report no invalidation at all'
);
wprism_check_same(
    ['id', 'label', 'declared', 'governed_by', 'state_class', 'handling', 'meaning', 'operations'],
    array_keys($rows['products']),
    'every surface row carries the dependency edge the flip turns on'
);
wprism_check_same(
    [],
    $rows['products']['governed_by'],
    'a caller that supplies no attribution gets the empty list, not a guess'
);

// -------- the evidence-pin flip, exact half: the moved adapter set is proved
// One subject edited in `manifests/dispositions.json` moves BOTH the
// whole-document hash and exactly that manifest's `adapter_digest`, because
// `ArtifactPolicyIdentity::manifest_rows()` folds the manifest's own
// disposition into the row it hashes (agent/src/Policy/ArtifactPolicyIdentity.php:68,
// :147). So the fixture moves both, and the flip must reach only the surfaces
// `storefront-commerce` governs.
$exactFacts = wprism_with_qualifying_orders(
    wprism_pinned_facts(['storefront-commerce' => WPRISM_MOVED_COMMERCE_DIGEST])
);
$exactFacts['registry_sha256'] = WPRISM_MOVED_REGISTRY_SHA;
$exact = ContractProjection::generate($contract, $exactFacts, $probe, wprism_inventory(), WPRISM_GENERATED_AT);
$exactRows = wprism_rows_by_id($exact);
wprism_check_same(
    ['current' => false, 'invalidation' => 'exact',
        'stale_adapters' => ['storefront-commerce'], 'stale_registry' => true],
    $exact['evidence_pins'],
    'one edited subject names the one adapter it moved'
);
wprism_check_same(
    ['storefront-commerce'],
    $exactRows['products']['governed_by'],
    'the flipped row records which adapter governs it'
);
foreach (['capture', 'release'] as $operation) {
    wprism_check_same(
        'Requalification required',
        $exactRows['products']['operations'][$operation]['readiness'],
        "the surface governed by the moved adapter flips for $operation"
    );
    wprism_check_same(
        'certify adapter',
        $exactRows['products']['operations'][$operation]['gap_action'],
        "… and names the same requalification gap action the blunt flip does for $operation"
    );
    wprism_check_same(
        'Ready',
        $exactRows['orders']['operations'][$operation]['readiness'],
        "a qualifying surface governed by an UNMOVED adapter keeps its word for $operation"
    );
    wprism_check(
        !in_array(
            ContractProjection::STALE_EVIDENCE_BLOCKER,
            $exactRows['orders']['operations'][$operation]['blockers'],
            true
        ),
        "… and no stale-evidence blocker was synthesized into it for $operation"
    );
}
wprism_check_same(
    're-certify the pinned evidence, then re-run assess',
    $exactRows['products']['operations']['release']['remediation'],
    'the exactly-flipped row states how to get back in the same words'
);
// A surface the caller supplied no facts for is governed by nothing, so an
// exact flip cannot reach it — and does not need to: it is already
// `Not qualified` through `missing_disposition_entry`, the stronger word.
wprism_check_same(
    'Not qualified',
    $exactRows['acme_catalog']['operations']['release']['readiness'],
    'an unqualified surface is not re-labelled Requalification required by someone else\'s drift'
);

// Adapter bytes alone. `registry_sha256` still matches its pin — nobody edited
// the reviewed document — but a manifest's own bytes moved, which
// docs/product-spec.md:646 names as its own drift class. Equality on the
// whole-document hash is not a licence, and today's code granted one.
$bytesOnly = wprism_with_qualifying_orders(
    wprism_pinned_facts(['storefront-commerce' => WPRISM_MOVED_COMMERCE_DIGEST])
);
$bytesOnlyDoc = ContractProjection::generate($contract, $bytesOnly, $probe, wprism_inventory(), WPRISM_GENERATED_AT);
wprism_check_same(
    ['current' => false, 'invalidation' => 'exact',
        'stale_adapters' => ['storefront-commerce'], 'stale_registry' => false],
    $bytesOnlyDoc['evidence_pins'],
    'adapter bytes that no longer match their pin are drift even when the reviewed document did not move'
);
wprism_check_same(
    'Requalification required',
    wprism_rows_by_id($bytesOnlyDoc)['products']['operations']['release']['readiness'],
    '… and the surface that adapter governs flips'
);

// The fail-closed fallback, with pins in hand. Every pinned digest matches, so
// nothing is attributable — yet the reviewed document moved, so a subject for
// some manifest this site does not pin was edited. A naive per-adapter
// comparison flips nothing here; the contract has to flip everything.
$unattributable = wprism_with_qualifying_orders(wprism_pinned_facts());
$unattributable['registry_sha256'] = WPRISM_MOVED_REGISTRY_SHA;
$unattributableDoc = ContractProjection::generate(
    $contract,
    $unattributable,
    $probe,
    wprism_inventory(),
    WPRISM_GENERATED_AT
);
wprism_check_same(
    ['current' => false, 'invalidation' => 'whole-contract', 'stale_adapters' => [], 'stale_registry' => true],
    $unattributableDoc['evidence_pins'],
    'a dispositions edit that touches no pinned manifest is unattributable and says so'
);
$unattributableRows = wprism_rows_by_id($unattributableDoc);
foreach (['products', 'orders'] as $surface) {
    wprism_check_same(
        'Requalification required',
        $unattributableRows[$surface]['operations']['release']['readiness'],
        "an unattributable drift still flips $surface — the blunt path is intact"
    );
}

// An adapter observed now that no review pinned. The surfaces it governs were
// never covered by that review, so they are drift.
$installedSince = wprism_with_qualifying_orders(wprism_pinned_facts());
$installedSince['manifest_pins'][] = [
    'name' => 'site-forms', 'source' => 'site',
    'adapter_digest' => 'sha256:' . str_repeat('d', 64),
];
$installedSince['surfaces']['orders']['governed_by'] = ['site-forms'];
$installedSinceDoc = ContractProjection::generate(
    $contract,
    $installedSince,
    $probe,
    wprism_inventory(),
    WPRISM_GENERATED_AT
);
wprism_check_same(
    ['current' => false, 'invalidation' => 'exact',
        'stale_adapters' => ['site-forms'], 'stale_registry' => false],
    $installedSinceDoc['evidence_pins'],
    'an adapter installed since accept is drift for the surfaces it governs'
);
wprism_check_same(
    'Requalification required',
    wprism_rows_by_id($installedSinceDoc)['orders']['operations']['release']['readiness'],
    '… and those surfaces flip, because no review covered them'
);

// The mirror: a pinned adapter the target no longer reports.
$removed = wprism_with_qualifying_orders(wprism_pinned_facts());
$removed['manifest_pins'] = array_values(array_filter(
    $removed['manifest_pins'],
    static fn (array $pin): bool => $pin['name'] !== 'core'
));
$removedDoc = ContractProjection::generate($contract, $removed, $probe, wprism_inventory(), WPRISM_GENERATED_AT);
wprism_check_same(
    ['current' => false, 'invalidation' => 'exact', 'stale_adapters' => ['core'], 'stale_registry' => false],
    $removedDoc['evidence_pins'],
    'a pinned adapter the target no longer loads is drift'
);
wprism_check_same(
    'Requalification required',
    wprism_rows_by_id($removedDoc)['orders']['operations']['release']['readiness'],
    '… for exactly the surfaces it governed'
);

// `governed_by` is a set, and the committed document is byte-compared: the
// emitted list is deduplicated and sorted whatever the caller hands over.
$twoAdapters = wprism_pinned_facts();
$twoAdapters['surfaces']['products']['governed_by'] = ['storefront-commerce', 'core', 'core'];
wprism_check_same(
    ['core', 'storefront-commerce'],
    wprism_rows_by_id(
        ContractProjection::generate($contract, $twoAdapters, $probe, wprism_inventory(), WPRISM_GENERATED_AT)
    )['products']['governed_by'],
    'governed_by is emitted deduplicated and sorted, whatever order the caller used'
);

// Determinism over the pinned shape too — `stale_adapters` and `governed_by`
// are both sets built from caller order, and an unsorted one would move bytes.
$pinnedFirst = ContractProjection::encode(
    ContractProjection::generate($contract, $exactFacts, $probe, wprism_inventory(), WPRISM_GENERATED_AT)
);
wprism_check_same(
    $pinnedFirst,
    ContractProjection::encode(ContractProjection::generate(
        wprism_reverse_levels($contract),
        wprism_reverse_levels($exactFacts),
        wprism_reverse_levels($probe),
        wprism_reverse_levels(wprism_inventory()),
        WPRISM_GENERATED_AT
    )),
    'the exact-flip bytes do not move with the caller key order either'
);

// Supplying observed pins is a promise that attribution is available. Breaking
// it refuses; it does not silently degrade to the blunt flip, which would
// under-report drift on exactly the surface whose edge went missing.
$noEdge = wprism_pinned_facts();
unset($noEdge['surfaces']['orders']['governed_by']);
wprism_check_refuses(
    static fn () => ContractProjection::generate($contract, $noEdge, $probe, wprism_inventory(), WPRISM_GENERATED_AT),
    'projection_invalid',
    'observed pins without per-surface attribution refuse rather than degrading to the blunt flip'
);
$badPin = wprism_pinned_facts();
$badPin['manifest_pins'][0] = ['name' => 'core', 'source' => 'shipped'];
wprism_check_refuses(
    static fn () => ContractProjection::generate($contract, $badPin, $probe, wprism_inventory(), WPRISM_GENERATED_AT),
    'projection_invalid',
    'an observed pin row missing its digest refuses: a pin with no digest is not a pin'
);
$listPins = wprism_pinned_facts();
$listPins['manifest_pins'] = ['core' => WPRISM_CORE_DIGEST];
wprism_check_refuses(
    static fn () => ContractProjection::generate($contract, $listPins, $probe, wprism_inventory(), WPRISM_GENERATED_AT),
    'projection_invalid',
    'observed pins must arrive as the same row list the contract holds'
);

// The other half of the narrowing: observed bundle rows are refused, not
// ignored. A caller still supplying them under a matching registry hash would
// otherwise be told `current` while its per-subject facts were dropped on the
// floor — a silent downgrade of exactly the evidence it thought it passed.
$withBundles = wprism_projection_facts();
$withBundles['bundles'] = [[
    'subject' => 'manifests.storefront-commerce',
    'bundle_digest' => 'sha256:' . str_repeat('b', 64),
    'status' => 'expired',
]];
wprism_check_refuses(
    static fn () => ContractProjection::generate(
        $contract,
        $withBundles,
        $probe,
        wprism_inventory(),
        WPRISM_GENERATED_AT
    ),
    'projection_invalid',
    'observed per-subject bundle rows refuse as an unknown fact key rather than being silently dropped'
);
// A per-surface `evidence_subjects` list is a weaker case: `validateFacts()`
// closes the TOP level of the fact object only, so a leftover surface key is
// carried past validation and read by nothing. What matters is that it can no
// longer change an answer — under the per-subject pins this list decided which
// surfaces flipped, and the assertion below is that it now decides nothing.
$withSubjects = wprism_projection_facts();
$withSubjects['surfaces']['products']['evidence_subjects'] = ['manifests.storefront-commerce'];
wprism_check_same(
    Canon::encode($document),
    Canon::encode(
        ContractProjection::generate($contract, $withSubjects, $probe, wprism_inventory(), WPRISM_GENERATED_AT)
    ),
    'a leftover per-surface evidence_subjects list changes no byte of the projection'
);

// ------------------------------------------- surface-level handling reduction
$mixed = wprism_projection_facts();
$mixed['surfaces']['products']['operations']['release']['facts'] = wprism_vector('release', [
    'containment' => ['apply_window_only' => false, 'lifecycle_touch' => true],
    'recovery' => ['external_effect_exists' => true],
]);
$mixedRows = wprism_rows_by_id(
    ContractProjection::generate($contract, $mixed, $probe, wprism_inventory(), WPRISM_GENERATED_AT)
);
wprism_check_same('manage', $mixedRows['products']['operations']['capture']['handling'], 'capture still manages');
wprism_check_same('block', $mixedRows['products']['operations']['release']['handling'], 'release blocks');
wprism_check_same(
    'block',
    $mixedRows['products']['handling'],
    'a surface whose handling differs by operation prints the restrictive word'
);
wprism_check_same(
    'Neither containment nor recovery can be shown for this operation, so it never reaches the live system.',
    $mixedRows['products']['meaning'],
    'the surface meaning follows the reduced handling'
);

// A surface whose state class disagrees across operations is a contradiction.
$inconsistent = wprism_projection_facts();
$inconsistent['surfaces']['products']['operations']['release']['facts'] =
    wprism_vector('release', ['policy_class' => 'runtime']);
wprism_check_refuses(
    static fn () => ContractProjection::generate($contract, $inconsistent, $probe, wprism_inventory(), WPRISM_GENERATED_AT),
    'projection_inconsistent',
    'two state classes for one surface refuse rather than picking one'
);

// -------------------------------------------------------------- input guards
wprism_check_refuses(
    static fn () => ContractProjection::generate(
        $contract,
        array_merge(wprism_projection_facts(), ['extra' => 1]),
        $probe,
        wprism_inventory(),
        WPRISM_GENERATED_AT
    ),
    'projection_invalid',
    'an unknown projection facts key refuses'
);
$noOperations = wprism_projection_facts();
$noOperations['operations'] = [];
wprism_check_refuses(
    static fn () => ContractProjection::generate($contract, $noOperations, $probe, wprism_inventory(), WPRISM_GENERATED_AT),
    'projection_invalid',
    'a projection covering no operation refuses'
);
wprism_check_refuses(
    static fn () => ContractProjection::generate($contract, wprism_projection_facts(), [], wprism_inventory(), WPRISM_GENERATED_AT),
    'projection_invalid',
    'an empty target probe refuses'
);
wprism_check_refuses(
    static fn () => ContractProjection::generate($contract, wprism_projection_facts(), $probe, wprism_inventory(), ''),
    'projection_invalid',
    'an empty generated_at refuses'
);

// An inventory is optional: the projection still covers every declaration.
$withoutInventory = ContractProjection::generate($contract, wprism_projection_facts(), $probe, [], WPRISM_GENERATED_AT);
wprism_check_same(
    ['acme_catalog', 'orders', 'products'],
    array_map(static fn (array $row): string => (string) $row['id'], $withoutInventory['surfaces']),
    'without an inventory the projection covers exactly the declarations'
);

// ------------------------------------------------- the three forbidden words
$sweep = [$document, $flipped, $managedFlipped, $withoutInventory,
    $exact, $bytesOnlyDoc, $unattributableDoc, $installedSinceDoc, $removedDoc];
foreach ($sweep as $index => $candidate) {
    $encoded = Canon::encode($candidate);
    foreach (V::NEVER_EMITTED as $forbidden) {
        wprism_check(
            !str_contains($encoded, '"' . $forbidden . '"'),
            "document $index never emits $forbidden"
        );
    }
    foreach ($candidate['surfaces'] as $row) {
        wprism_check(in_array($row['state_class'], V::STATE_CLASSES, true), "document $index: state class in vocabulary");
        wprism_check(in_array($row['handling'], V::HANDLINGS, true), "document $index: handling in vocabulary");
        foreach ($row['operations'] as $projected) {
            wprism_check(in_array($projected['readiness'], V::READINESS, true), "document $index: readiness in vocabulary");
            wprism_check(
                in_array($projected['gap_action'], V::GAP_ACTIONS, true),
                "document $index: gap action inside the closed set"
            );
        }
    }
}

wprism_check_summary('regress_contract_projection');
