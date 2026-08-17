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
 * flips affected surfaces to `Requalification required`". The blunt version
 * — the whole surface, not the exact expired capability — is MUP §8's
 * declared deferral, so the test pins the bluntness too: a moved registry
 * hash flips every surface, a single stale bundle flips only the surfaces
 * that named it as an evidence subject.
 *
 * The third assertion is structural: a surface the caller supplies no facts
 * for must project `Not qualified`, never the contract's own declaration.
 * That is MUP §3.4's "a declaration never grants authority" as a test.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/check.php';

require_once __DIR__ . '/../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../cli/src/Contract/ApplicationContract.php';
require_once __DIR__ . '/../../cli/src/Contract/ContractProjection.php';
require_once __DIR__ . '/../../cli/src/Contract/ProjectionVocabulary.php';

use Duo\Canon;
use Duo\Orchestrator\ApplicationContract;
use Duo\Orchestrator\ContractProjection;
use Duo\Orchestrator\ProjectionVocabulary as V;

const DUO_SUBJECT = 'manifests.storefront-commerce';
const DUO_REGISTRY_SHA = 'sha256:8fa100000000000000000000000000000000000000000000000000000000ffff';
const DUO_BUNDLE_SHA = 'sha256:734500000000000000000000000000000000000000000000000000000000ffff';
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
        'bundles' => [
            ['subject' => DUO_SUBJECT, 'bundle_digest' => DUO_BUNDLE_SHA, 'status' => 'current'],
        ],
        'surfaces' => [
            'products' => [
                'evidence_subjects' => [DUO_SUBJECT],
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
            // No evidence subject: this surface's readiness does not depend
            // on the per-subject bundle, only on the registry as a whole.
            'orders' => [
                'evidence_subjects' => [],
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
    json_decode((string) file_get_contents(__DIR__ . '/fixtures/contract/contract-unbound.json'), true)
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
    ['handling', 'readiness', 'certification_provenance', 'effect_containment',
        'effect_containment_basis', 'effect_recovery_semantics', 'conditions',
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
    'qualify in rehearsal',
    $rows['acme_catalog']['operations']['release']['gap_action'],
    'the unclassified row names its gap action'
);

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
foreach (['products', 'orders'] as $id) {
    foreach (['capture', 'release'] as $operation) {
        duo_check_same(
            'Requalification required',
            $flippedRows[$id]['operations'][$operation]['readiness'],
            "a moved registry hash flips $id/$operation to Requalification required"
        );
        duo_check_same(
            'qualify in rehearsal',
            $flippedRows[$id]['operations'][$operation]['gap_action'],
            "the flipped $id/$operation row names a requalification gap action"
        );
    }
}
duo_check_same(
    're-certify the pinned evidence, then re-run assess',
    $flippedRows['products']['operations']['release']['remediation'],
    'the flipped row states how to get back'
);

$staleBundle = duo_projection_facts();
$staleBundle['bundles'][0]['status'] = 'expired';
$partial = ContractProjection::generate($contract, $staleBundle, $probe, duo_inventory(), DUO_GENERATED_AT);
duo_check_same(false, $partial['evidence_pins']['current'], 'a stale bundle is reported as stale');
duo_check_same(false, $partial['evidence_pins']['stale_registry'], 'the registry itself is still current');
duo_check_same([DUO_SUBJECT], $partial['evidence_pins']['stale_bundles'], 'the stale bundle subject is named');
$partialRows = duo_rows_by_id($partial);
duo_check_same(
    'Requalification required',
    $partialRows['products']['operations']['release']['readiness'],
    'the surface that pinned the stale bundle flips'
);
duo_check_same(
    'Ready',
    $partialRows['orders']['operations']['release']['readiness'],
    'a surface that never pinned that bundle does not flip'
);

$movedDigest = duo_projection_facts();
$movedDigest['bundles'][0]['bundle_digest'] = 'sha256:' . str_repeat('b', 64);
$digestRows = duo_rows_by_id(
    ContractProjection::generate($contract, $movedDigest, $probe, duo_inventory(), DUO_GENERATED_AT)
);
duo_check_same(
    'Requalification required',
    $digestRows['products']['operations']['release']['readiness'],
    'a bundle whose digest moved flips its surfaces too'
);

$missingBundle = duo_projection_facts();
$missingBundle['bundles'] = [];
$missingRows = duo_rows_by_id(
    ContractProjection::generate($contract, $missingBundle, $probe, duo_inventory(), DUO_GENERATED_AT)
);
duo_check_same(
    'Requalification required',
    $missingRows['products']['operations']['release']['readiness'],
    'a pinned bundle that is no longer reported flips its surfaces'
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
$sweep = [$document, $flipped, $partial, $withoutInventory];
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
