<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/ApplicationContract.php';
require_once __DIR__ . '/ProjectionVocabulary.php';

use Duo\Canon;
use Duo\CommandRefusalException;

/**
 * `projection.json` — part 5 of the application contract, generated
 * (round-3 MUP §3.3, product spec "The application contract → 5. Generated
 * projection").
 *
 * This is the one document that answers "what can Duo do on THIS site right
 * now", and MUP §3.4 pins the property that makes it trustworthy: *a
 * declaration never grants authority; readiness is always recomputed from
 * the registry plus a live probe, never read from `contract.json`*. That
 * rule is enforced structurally here rather than promised — the contract
 * contributes identity (which surfaces exist, what they are called, what
 * state class a human reviewed them as) and the fact vectors contribute
 * every readiness input. A surface the caller hands no facts for projects
 * `Not qualified`, because "no evidence was supplied" and "the proof is
 * incomplete" are the same statement.
 *
 * **Determinism.** Identical inputs must produce identical bytes: the
 * document is committed, reviewed and diffed, and a projection that churned
 * would make every review noise. `generated_at` is therefore an argument,
 * not a clock read; surfaces are emitted in `id` order and operations in the
 * spec's own operation order; everything else is Canon's key sort.
 *
 * **The evidence-pin flip.** The contract pins `registry_sha256` and a
 * per-subject bundle digest+status. When the observed registry hash differs
 * from the pin, every surface flips to `Requalification required`; when one
 * bundle is stale, only the surfaces that named it as an evidence subject
 * flip. MUP §8 records the bluntness of the whole-surface flip as a
 * deliberate deferral (bounded requalification is Phase C) — the flip is
 * implemented by injecting the registry's own `evidence_not_current`
 * blocker into the fact vector, so the readiness word still comes from
 * ProjectionVocabulary's §1.3 table and nothing here mints a status word.
 */
final class ContractProjection {
    public const FORMAT = 'duo-site-capability-projection/v1';

    /** The blocker CapabilityRegistry itself raises for expired evidence. */
    public const STALE_EVIDENCE_BLOCKER = 'evidence_not_current';

    /**
     * Generate the projection document.
     *
     * @param array<string,mixed> $contract a validated `duo-application-contract/v1`
     * @param array<string,mixed> $facts registry + probe facts:
     *        {
     *          'operations': list<operation>,       // the operations this projection covers
     *          'registry_sha256': string,           // observed now
     *          'bundles': list<{subject, bundle_digest, status}>,   // observed now
     *          'surfaces': {
     *            '<surface id>': {
     *               'evidence_subjects': list<string>,
     *               'operations': {'<op>': {'facts': <ProjectionVocabulary fact vector>,
     *                                       'expiry_and_dependencies': list<string>}}
     *            }
     *          }
     *        }
     * @param array<string,string> $targetProbe e.g. {"wordpress":"7.0.3","php":"8.3.33"}
     * @param array<string,mixed> $inventory a `duo-assess-inventory/v1` document, or []
     *        when unavailable; its `policy.surface_groups` supply the
     *        surfaces the contract does not declare, so an undeclared group
     *        appears in the projection as the gap it is rather than being
     *        invisible.
     * @return array<string,mixed>
     */
    public static function generate(
        array $contract,
        array $facts,
        array $targetProbe,
        array $inventory,
        string $generatedAt
    ): array {
        ApplicationContract::validate($contract);
        self::validateFacts($facts);
        self::validateProbe($targetProbe);
        if ($generatedAt === '') {
            throw self::refuse('projection_invalid', 'generated_at must be a non-empty timestamp');
        }

        /** @var list<string> $operations */
        $operations = array_values($facts['operations']);
        $stale = self::staleEvidence($contract, $facts);

        $rows = [];
        foreach (self::surfaceIdentities($contract, $inventory) as $id => $identity) {
            $rows[] = self::projectSurface($id, $identity, $facts, $operations, $stale);
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return [
            'format' => self::FORMAT,
            'contract_digest' => (string) $contract['contract_digest'],
            'registry_sha256' => (string) $facts['registry_sha256'],
            'generated_at' => $generatedAt,
            'target_probe' => $targetProbe,
            'evidence_pins' => [
                'current' => !$stale['registry'] && $stale['bundles'] === [],
                'stale_registry' => $stale['registry'],
                'stale_bundles' => $stale['bundles'],
            ],
            'surfaces' => $rows,
        ];
    }

    /** Canonical bytes, exactly as they land in `.duo/contract/projection.json`. */
    public static function encode(array $document): string {
        return Canon::encode($document);
    }

    /**
     * Which pinned evidence no longer matches what the target reports.
     *
     * A bundle counts as stale when it is absent from the observed set, when
     * its digest moved, or when its status is anything other than `current`
     * — the three ways `manifests/capabilities/evidence.json` can stop
     * covering the revision the contract was reviewed against.
     *
     * @param array<string,mixed> $contract
     * @param array<string,mixed> $facts
     * @return array{registry: bool, bundles: list<string>}
     */
    private static function staleEvidence(array $contract, array $facts): array {
        $pinnedRegistry = (string) $contract['evidence_pins']['registry_sha256'];
        $observedRegistry = (string) $facts['registry_sha256'];

        $observed = [];
        foreach ($facts['bundles'] as $bundle) {
            $observed[(string) $bundle['subject']] = $bundle;
        }

        $staleBundles = [];
        foreach ($contract['evidence_pins']['bundles'] as $pinned) {
            $subject = (string) $pinned['subject'];
            $seen = $observed[$subject] ?? null;
            if ($seen === null
                || (string) ($seen['status'] ?? '') !== 'current'
                || !hash_equals((string) $pinned['bundle_digest'], (string) ($seen['bundle_digest'] ?? ''))
            ) {
                $staleBundles[] = $subject;
            }
        }
        sort($staleBundles, SORT_STRING);

        return [
            'registry' => !hash_equals($pinnedRegistry, $observedRegistry),
            'bundles' => $staleBundles,
        ];
    }

    /**
     * Every surface the projection must cover: the contract's declarations
     * first, then any `policy.surface_groups` id the inventory observed that
     * no declaration names.
     *
     * @param array<string,mixed> $contract
     * @param array<string,mixed> $inventory
     * @return array<string,array<string,mixed>>
     */
    private static function surfaceIdentities(array $contract, array $inventory): array {
        $identities = [];
        foreach ($contract['declarations']['surfaces'] as $surface) {
            $id = (string) $surface['id'];
            $identities[$id] = [
                'label' => (string) $surface['label'],
                'declared_state_class' => (string) $surface['state_class'],
                'declared' => true,
            ];
        }

        $groups = $inventory['policy']['surface_groups'] ?? [];
        if (!is_array($groups)) {
            return $identities;
        }
        $labels = $contract['declarations']['surface_labels'] ?? [];
        foreach ($groups as $group) {
            if (!is_array($group) || !is_string($group['id'] ?? null)) {
                continue;
            }
            $id = $group['id'];
            if (isset($identities[$id])) {
                continue;
            }
            // Observed but undeclared. It is not `unclassified` because
            // WordPress could not name it — it is unclassified because the
            // reviewed contract does not cover it, which is the same gap
            // from the contract's point of view and the same handling.
            $identities[$id] = [
                'label' => is_string($labels[$id] ?? null) ? (string) $labels[$id] : $id,
                'declared_state_class' => 'unclassified',
                'declared' => false,
            ];
        }

        return $identities;
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $facts
     * @param list<string> $operations
     * @param array{registry: bool, bundles: list<string>} $stale
     * @return array<string,mixed>
     */
    private static function projectSurface(
        string $id,
        array $identity,
        array $facts,
        array $operations,
        array $stale
    ): array {
        /** @var array<string,mixed> $surfaceFacts */
        $surfaceFacts = $facts['surfaces'][$id] ?? [];
        $subjects = $surfaceFacts['evidence_subjects'] ?? [];
        $affected = $stale['registry']
            || (is_array($subjects) && array_intersect($subjects, $stale['bundles']) !== []);

        $projected = [];
        $stateClasses = [];
        $handlings = [];
        foreach (ProjectionVocabulary::OPERATIONS as $operation) {
            if (!in_array($operation, $operations, true)) {
                continue;
            }
            $entry = $surfaceFacts['operations'][$operation] ?? null;
            $vector = is_array($entry) && is_array($entry['facts'] ?? null)
                ? $entry['facts']
                : self::defaultFacts($operation, (string) $identity['declared_state_class']);
            if ($affected) {
                $vector = self::withStaleEvidence($vector);
            }
            $projection = ProjectionVocabulary::project($vector);
            $expiry = is_array($entry) && is_array($entry['expiry_and_dependencies'] ?? null)
                ? array_values($entry['expiry_and_dependencies'])
                : [];

            $stateClasses[$projection['state_class']] = true;
            $handlings[$projection['handling']] = true;
            $projected[$operation] = [
                'handling' => $projection['handling'],
                'readiness' => $projection['readiness'],
                'certification_provenance' => $projection['certification_provenance'],
                // T6 §3.6: projection.json EXPOSES who vouched and under
                // whose trust root, so a reviewer reading the committed
                // artifact can tell a platform claim from their own
                // organization's without re-running assess against the site.
                'certification_principal' => $projection['certification_principal'],
                'certification_trust_root' => $projection['certification_trust_root'],
                'effect_containment' => $projection['effect_containment'],
                'effect_containment_basis' => $projection['effect_containment_basis'],
                'effect_recovery_semantics' => $projection['effect_recovery_semantics'],
                'conditions' => $projection['conditions'],
                // The two inputs gapAction() needs that are not otherwise
                // recoverable from a stored row. `AuthorizationPlan::
                // gapActionFor()` re-derives an action from THIS artifact
                // when the stored word is unreadable, and without these it
                // would answer `install adapter` for a surface whose adapter
                // is installed and merely unsigned (T6 §3.6).
                'blockers' => $projection['blockers'],
                'probable_owner' => $projection['probable_owner'],
                'expiry_and_dependencies' => $expiry,
                'remediation' => $projection['remediation'],
                'gap_action' => ProjectionVocabulary::gapAction($projection),
                'annotations' => $projection['annotations'],
            ];
        }

        // A surface whose state class differs by operation is a
        // contradiction, not a nuance: state class is a property of the
        // state, and two answers means two different fact vectors were built
        // for one surface. Refuse rather than pick one.
        if (count($stateClasses) > 1) {
            throw self::refuse(
                'projection_inconsistent',
                "surface '$id' projects more than one state class across its operations"
            );
        }
        $stateClass = array_key_first($stateClasses) ?? (string) $identity['declared_state_class'];
        // Handling legitimately differs by operation (delete may block where
        // release manages). A single-word row must then print the
        // restrictive half, never the permissive one.
        $handling = count($handlings) === 1
            ? (string) array_key_first($handlings)
            : 'block';

        return [
            'id' => $id,
            'label' => (string) $identity['label'],
            'declared' => (bool) $identity['declared'],
            'state_class' => $stateClass,
            'handling' => $handling,
            'meaning' => ProjectionVocabulary::meaningFor($stateClass, $handling),
            'operations' => $projected,
        ];
    }

    /**
     * The fact vector for a surface the caller supplied no evidence for.
     *
     * `missing_registry_entry` is the registry's own blocker for exactly
     * this situation and projects `Not qualified` through the §1.3 table, so
     * even the absence of input travels through the one vocabulary.
     *
     * @return array<string,mixed>
     */
    private static function defaultFacts(string $operation, string $declaredStateClass): array {
        $policyClass = match ($declaredStateClass) {
            'authored', 'runtime', 'derived' => $declaredStateClass,
            'environment-bound' => 'env',
            'external' => 'authored',
            default => null,
        };

        return [
            'operation' => $operation,
            'policy_class' => $policyClass,
            'unclassified' => $declaredStateClass === 'unclassified',
            'external_declared' => $declaredStateClass === 'external',
            'in_scope' => false,
            'rebuild_declared' => false,
            'resync_declared' => false,
            'unsupported_reason' => null,
            'registry' => [
                'claim_status' => null,
                'evidence_status' => null,
                'verdict_status' => null,
                'blockers' => ['missing_registry_entry'],
                'conditions' => [],
                'source' => null,
                'site_certified' => false,
            ],
            'provider_negotiation' => [],
            'containment' => [
                'apply_window_only' => false,
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
    }

    /**
     * @param array<string,mixed> $vector
     * @return array<string,mixed>
     */
    private static function withStaleEvidence(array $vector): array {
        $blockers = $vector['registry']['blockers'] ?? [];
        if (!is_array($blockers)) {
            $blockers = [];
        }
        if (!in_array(self::STALE_EVIDENCE_BLOCKER, $blockers, true)) {
            $blockers[] = self::STALE_EVIDENCE_BLOCKER;
        }
        $vector['registry']['blockers'] = array_values($blockers);

        return $vector;
    }

    /** @param array<string,mixed> $facts */
    private static function validateFacts(array $facts): void {
        foreach (['operations', 'registry_sha256', 'bundles', 'surfaces'] as $key) {
            if (!array_key_exists($key, $facts)) {
                throw self::refuse('projection_invalid', "projection facts are missing '$key'");
            }
        }
        foreach (array_keys($facts) as $key) {
            if (!in_array((string) $key, ['operations', 'registry_sha256', 'bundles', 'surfaces'], true)) {
                throw self::refuse('projection_invalid', "projection facts carry the unknown key '" . (string) $key . "'");
            }
        }
        if (!is_array($facts['operations']) || !array_is_list($facts['operations']) || $facts['operations'] === []) {
            throw self::refuse('projection_invalid', 'projection facts.operations must be a non-empty list');
        }
        foreach ($facts['operations'] as $operation) {
            if (!in_array($operation, ProjectionVocabulary::OPERATIONS, true)) {
                throw self::refuse('projection_invalid', 'projection facts.operations names an unknown operation');
            }
        }
        if (!is_string($facts['registry_sha256']) || $facts['registry_sha256'] === '') {
            throw self::refuse('projection_invalid', 'projection facts.registry_sha256 must be a non-empty string');
        }
        if (!is_array($facts['bundles']) || !array_is_list($facts['bundles'])) {
            throw self::refuse('projection_invalid', 'projection facts.bundles must be a list');
        }
        if (!is_array($facts['surfaces']) || array_is_list($facts['surfaces'])) {
            throw self::refuse('projection_invalid', 'projection facts.surfaces must be an id -> facts object');
        }
    }

    /** @param array<string,string> $probe */
    private static function validateProbe(array $probe): void {
        if ($probe === [] || array_is_list($probe)) {
            throw self::refuse('projection_invalid', 'the target probe must be a non-empty name -> value object');
        }
        foreach ($probe as $value) {
            if (!is_string($value) || $value === '') {
                throw self::refuse('projection_invalid', 'every target probe value must be a non-empty string');
            }
        }
    }

    private static function refuse(string $code, string $message): CommandRefusalException {
        return new CommandRefusalException(
            $code,
            $message,
            'regenerate the projection with duo assess, or re-accept a fresh proposal'
        );
    }
}
