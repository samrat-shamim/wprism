<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * The single implementation of the spec-word projection (round-3 MUP §1).
 *
 * The product spec's six per-surface dimensions — state class, handling,
 * technical readiness, certification provenance, effect containment, effect
 * recovery semantics (docs/product-spec.md, "The versionability contract") —
 * do not exist as stored facts anywhere in this tree. They are *projected*
 * from facts that do: `Policy::CLASSES`, `CapabilityRegistry::report()`,
 * `Providers::diagnose()`, the reviewed contract's declarations, and the
 * selected recovery profile's covered inventory. MUP §1 is the complete
 * mapping and this class is the only place it is written down: Assess,
 * Release and Rehearse are engine-layer siblings, so policy is the lowest
 * layer all three can read and a second copy would be a second vocabulary
 * (docs/modules/cli-Contract.md).
 *
 * Three properties are load-bearing and are asserted at the end of every
 * projection rather than merely documented, because each one is a promise
 * MUP makes in prose that a future edit could silently break:
 *
 *   - `Site-certified` is never emitted. The certification gate is deferred
 *     (MUP §7/§8); the contract carries an `unsigned` attestation, so a
 *     site-scoped claim projects `Uncertified`. This is MUP §3.2's "single
 *     most important honesty property of the whole round".
 *   - `sandboxed` is never emitted. MUP has no egress control, so the value
 *     is not structurally provable (MUP §1.5).
 *   - `compensatable` is never emitted. It needs a declared compensation
 *     action, which does not exist (MUP §1.6).
 *
 * The class is pure and static: no I/O, no clock, no globals. A malformed
 * fact vector raises \InvalidArgumentException, not a command refusal —
 * building a fact vector is the caller's job, so a bad one is a caller bug
 * and never an operator condition. Operator-facing refusals belong to the
 * classes that assemble facts (ApplicationContract, ContractProjection).
 *
 * @phpstan-type Facts array<string,mixed>
 * @phpstan-type Projection array<string,mixed>
 */
final class ProjectionVocabulary {
    /** Customer operations (product spec, "The versionability contract"). */
    public const OPERATIONS = ['capture', 'merge', 'release', 'verify', 'delete', 'recover'];

    /**
     * Operations able to reach a live system, i.e. able to *cause* an
     * external effect on the target. `capture` and `verify` read the target
     * and `merge` is host-local, so none of the three can fire a hook, a
     * mail, a webhook or a payment; `release`, `delete` and `recover` all
     * mutate. This split is what scopes the spec's "unknown containment or
     * recovery semantics blocks any operation capable of reaching a live
     * system" — see the reconciliation note on projectHandling().
     */
    public const LIVE_REACHING_OPERATIONS = ['release', 'delete', 'recover'];

    /** The `Policy::CLASSES` words this projection accepts as input. */
    public const POLICY_CLASSES = ['authored', 'runtime', 'derived', 'env', 'managed'];

    /** Product-spec vocabulary, "State class and handling". */
    public const STATE_CLASSES = [
        'authored', 'runtime', 'derived', 'environment-bound', 'external', 'unclassified',
    ];

    /** Product-spec vocabulary, "State class and handling". */
    public const HANDLINGS = [
        'manage', 'preserve local', 'rebuild', 'rebind', 're-synchronize', 'block',
    ];

    /** Product-spec vocabulary, "Technical readiness". */
    public const READINESS = [
        'Ready', 'Ready with conditions', 'Requalification required',
        'Experimental', 'Not qualified', 'Unsupported',
    ];

    /** Product-spec vocabulary, "Certification provenance". */
    public const CERTIFICATION_PROVENANCE = ['Platform-certified', 'Site-certified', 'Uncertified'];

    /** Product-spec vocabulary, "External-effect containment and recovery semantics". */
    public const EFFECT_CONTAINMENT = ['prevented', 'sandboxed', 'live', 'unknown'];

    /** Product-spec vocabulary, "External-effect containment and recovery semantics". */
    public const EFFECT_RECOVERY_SEMANTICS = [
        'not applicable', 'provider-state restorable', 'compensatable', 'irreversible', 'unknown',
    ];

    /** MUP §2.1 item 4: the closed smallest-safe-next-action set. */
    public const GAP_ACTIONS = [
        'classify', 'declare in contract', 'qualify in rehearsal', 'exclude',
        'provision env value', 'install adapter', 'nothing — supported',
    ];

    /**
     * Words this profile structurally cannot earn. Checked on every
     * projection; see the class docblock for why each one is absent.
     */
    public const NEVER_EMITTED = ['Site-certified', 'sandboxed', 'compensatable'];

    /** MUP §1.5's literal basis string for `prevented`. */
    public const CONTAINMENT_BASIS_PREVENTED = 'no WordPress hooks fire in the apply window';

    /** MUP §1.5's literal basis string for everything else, including `live`. */
    public const CONTAINMENT_BASIS_UNKNOWN = 'unknown — not enforced in this profile';

    /** MUP §1.2's verbatim annotation for the `managed` lifecycle options. */
    public const ANNOTATION_MANAGED =
        "via the code half's lifecycle reconciliation, never the generic state path";

    public const ANNOTATION_OUT_OF_SCOPE =
        'authored, but outside the captured scope: the target keeps its own copy until the scope is widened';

    /** MUP §1.2: `re-synchronize` requires a declared action and MUP ships none. */
    public const ANNOTATION_EXTERNAL_NO_RESYNC =
        're-synchronize requires a declared action and this profile ships no generic one, '
        . 'so this surface blocks until a manifest declares otherwise';

    /** MUP §1.3's last row: a negotiation problem is an *unmet* condition, so it blocks. */
    public const ANNOTATION_PROVIDER_NEGOTIATION_UNMET =
        'the provider negotiation condition is unmet: it blocks until the named code is resolved';

    /** MUP §1.4 row 2 / §3.2: site certification is deferred, so it projects Uncertified. */
    public const ANNOTATION_SITE_CERTIFICATION_DEFERRED =
        'site certification is deferred in this profile: the contract carries an unsigned attestation, '
        . 'so a site-scoped claim projects Uncertified';

    /** The §1.6 consequence, stated on the row it blocks. */
    public const ANNOTATION_CONTAINMENT_BLOCK =
        'containment is unknown and an external effect may exist, so an operation able to reach '
        . 'a live system is blocked';

    public const ANNOTATION_UNCLASSIFIED_NOT_QUALIFIED =
        'an unclassified surface has no registry entry that could make it Ready';

    /** Prefixes so callers can recognise a generated annotation without string archaeology. */
    public const ANNOTATION_NO_REPAIR_PATH_PREFIX = 'no declared repair path; the registry states: ';
    public const ANNOTATION_RESTORED_BY_PREFIX = 'restored by: ';

    /** Condition-string shapes this class mints itself. */
    public const CONDITION_PROVIDER_NEGOTIATION_PREFIX = 'unmet: provider negotiation ';

    /**
     * The registry condition code that means "an environment value is not
     * provisioned on this target" (MUP §1.3, "any `env_missing` plan row").
     * Matched as a substring of a caller-supplied condition sentence because
     * `CapabilityRegistry::report()` renders conditions as prose that always
     * names its code.
     */
    public const CONDITION_ENV_MISSING_MARKER = 'env_missing';

    /**
     * Registry blockers by the readiness word they force (MUP §1.3).
     *
     * `profile_evidence_not_current` is grouped with the two blockers §1.3
     * names for `Requalification required` because it is the same fact about
     * a recovery profile's evidence rather than a claim's
     * (agent/src/Adapter/CapabilityRegistry.php:480), and the operator remedy
     * — re-certify, then re-run assess — is identical.
     */
    public const BLOCKERS_UNSUPPORTED = [
        'surface_explicitly_unsupported', 'multisite_unsupported', 'deletion_unsupported',
    ];
    public const BLOCKERS_NOT_QUALIFIED = [
        'adapter_source_uncertified', 'missing_registry_entry', 'surface_not_registered',
    ];
    public const BLOCKERS_REQUALIFICATION = [
        'evidence_not_current', 'revision_not_certified', 'profile_evidence_not_current',
    ];

    /**
     * Project one surface × operation into the spec's vocabulary.
     *
     * @param array<string,mixed> $facts the exact fact vector documented in
     *        MUP §1 and in this round's shared interface; see assertFacts()
     *        for the closed key set and the per-key types.
     * @return array<string,mixed> keys: state_class, handling, readiness,
     *         certification_provenance, effect_containment,
     *         effect_containment_basis, effect_recovery_semantics,
     *         conditions, remediation, meaning, annotations.
     */
    public static function project(array $facts): array {
        self::assertFacts($facts);

        /** @var array<string,mixed> $registry */
        $registry = $facts['registry'];
        /** @var list<string> $negotiation */
        $negotiation = array_values($facts['provider_negotiation']);
        /** @var array<string,mixed> $containmentFacts */
        $containmentFacts = $facts['containment'];
        /** @var array<string,mixed> $recoveryFacts */
        $recoveryFacts = $facts['recovery'];
        $operation = (string) $facts['operation'];
        $policyClass = $facts['policy_class'] === null ? null : (string) $facts['policy_class'];
        $unsupportedReason = $facts['unsupported_reason'] === null
            ? null
            : (string) $facts['unsupported_reason'];

        $annotations = [];

        $stateClass = self::projectStateClass($facts, $policyClass);
        $handling = self::projectHandling($facts, $policyClass, $stateClass, $annotations);

        $containment = self::projectContainment($containmentFacts);
        $containmentBasis = $containment === 'prevented'
            ? self::CONTAINMENT_BASIS_PREVENTED
            : self::CONTAINMENT_BASIS_UNKNOWN;

        $readiness = self::projectReadiness($registry, $negotiation, $operation, $unsupportedReason);
        $readiness = self::forceReadiness(
            $readiness,
            $stateClass,
            (bool) $facts['rebuild_declared'],
            $unsupportedReason,
            $annotations
        );

        $provenance = self::projectProvenance($registry, $annotations);
        $recovery = self::projectRecovery($recoveryFacts, $stateClass, $containment, $annotations);

        // MUP §1.2's last row ("any surface whose containment is `unknown`
        // for the requested operation") and §1.6's last-but-one row
        // ("containment is `unknown` **and an effect may exist**") are the
        // same rule stated twice, and only the §1.6 wording is complete: the
        // §2.1 worked example prints `payment keys / rebind / unknown /
        // not applicable` — an unknown-containment row that is deliberately
        // NOT blocked, because no external effect exists for it. So the
        // block fires on the conjunction: unknown containment, an effect
        // that may exist, and an operation that can reach a live system.
        if ($containment === 'unknown'
            && (bool) $recoveryFacts['external_effect_exists']
            && in_array($operation, self::LIVE_REACHING_OPERATIONS, true)
        ) {
            $handling = 'block';
            $recovery = 'unknown';
            $annotations[] = self::ANNOTATION_CONTAINMENT_BLOCK;
        }

        $conditions = self::projectConditions($registry, $negotiation, $annotations);
        $remediation = self::projectRemediation(
            $stateClass,
            $handling,
            $readiness,
            $negotiation,
            $conditions,
            $unsupportedReason,
            (bool) $facts['rebuild_declared']
        );

        $projection = [
            'state_class' => $stateClass,
            'handling' => $handling,
            'readiness' => $readiness,
            'certification_provenance' => $provenance,
            'effect_containment' => $containment,
            'effect_containment_basis' => $containmentBasis,
            'effect_recovery_semantics' => $recovery,
            'conditions' => $conditions,
            'remediation' => $remediation,
            'meaning' => self::meaningFor($stateClass, $handling),
            'annotations' => array_values(array_unique($annotations)),
        ];

        self::assertProjection($projection);

        return $projection;
    }

    /**
     * The smallest safe next action for one projected row (MUP §2.1 item 4).
     *
     * Two of the seven words are close enough to need a stated rule.
     * `classify` and `qualify in rehearsal` both answer "unclassified", and
     * MUP's own two worked examples (§2.1's `custom catalog tbl` row and
     * §3.2's `acme_catalog` declaration) both print `qualify in rehearsal`
     * for an unclassified *custom table* — a surface whose containment is
     * unknown. The distinguishing fact is therefore containment: an
     * unclassified surface Duo can only reach inside the hook-free apply
     * window is one classification rule away (`classify`, the `duo pending`
     * remedy), while one whose effects are unknown needs a rehearsal to
     * learn anything at all.
     *
     * @param array<string,mixed> $projection a project() result
     */
    public static function gapAction(array $projection): string {
        $stateClass = (string) ($projection['state_class'] ?? '');
        $handling = (string) ($projection['handling'] ?? '');
        $readiness = (string) ($projection['readiness'] ?? '');
        $containment = (string) ($projection['effect_containment'] ?? '');
        /** @var list<string> $annotations */
        $annotations = is_array($projection['annotations'] ?? null)
            ? array_values($projection['annotations'])
            : [];
        /** @var list<string> $conditions */
        $conditions = is_array($projection['conditions'] ?? null)
            ? array_values($projection['conditions'])
            : [];

        if ($readiness === 'Unsupported') {
            return 'exclude';
        }
        if ($stateClass === 'unclassified') {
            return $containment === 'unknown' ? 'qualify in rehearsal' : 'classify';
        }
        if ($handling === 'block') {
            // Both remaining block causes — an external surface with no
            // declared re-sync action, and an unknown-containment live
            // effect — are closed by a reviewed declaration, which is
            // exactly MUP §1.6's stated next action.
            return 'declare in contract';
        }
        if ($readiness === 'Requalification required' || $readiness === 'Experimental') {
            return 'qualify in rehearsal';
        }
        if ($readiness === 'Not qualified') {
            return 'install adapter';
        }
        if (in_array(self::ANNOTATION_PROVIDER_NEGOTIATION_UNMET, $annotations, true)) {
            return 'install adapter';
        }
        foreach ($conditions as $condition) {
            if (str_contains((string) $condition, self::CONDITION_ENV_MISSING_MARKER)) {
                return 'provision env value';
            }
        }

        return 'nothing — supported';
    }

    /**
     * The one-line WordPress-language sentence MUP §2.1 prints per row.
     *
     * Keyed by the pair rather than by handling alone because `preserve
     * local` and `block` each mean two different things depending on why
     * they were reached, and an operator reading one line deserves the
     * specific one.
     */
    public static function meaningFor(string $stateClass, string $handling): string {
        $map = [
            'authored|manage' => 'Duo versions this surface and applies the repository copy to the target.',
            'authored|preserve local' => 'Authored state outside the captured scope: the target keeps its own copy.',
            'runtime|preserve local' => 'Live operational state is never copied; the target keeps its own.',
            'derived|rebuild' => 'Regenerated on the target after a write; never carried as authored state.',
            'environment-bound|rebind' => 'Bound per environment; the target value is rebound, never copied from another site.',
            'external|re-synchronize' => 'Owned by a system outside this WordPress install; a declared action re-synchronizes it.',
            'external|block' => 'Owned by a system outside this WordPress install and no re-synchronization action is declared, so the operation refuses.',
            'unclassified|block' => 'Ownership and semantics are unknown, so the operation refuses rather than guess.',
        ];
        $key = $stateClass . '|' . $handling;
        if (isset($map[$key])) {
            return $map[$key];
        }
        if ($handling === 'block') {
            return 'Neither containment nor recovery can be shown for this operation, so it never reaches the live system.';
        }

        return 'Handled as ' . $handling . ' for a ' . $stateClass . ' surface.';
    }

    /**
     * MUP §1.1.
     *
     * Precedence is `unclassified` > `external` > the policy class. An
     * explicit `unclassified` is produced by an engine gate that already
     * failed to classify the surface (`Capture::gate_scan()`, a `duo
     * pending` row, a `Coverage` invisible name); a contract declaration
     * cannot un-know that, so a declaration never upgrades it. `external` is
     * second because MUP §1.1 emits it **only** from a declaration and never
     * infers it, so where it appears it is strictly more specific than the
     * policy class underneath.
     *
     * @param array<string,mixed> $facts
     */
    private static function projectStateClass(array $facts, ?string $policyClass): string {
        if ((bool) $facts['unclassified'] || $policyClass === null) {
            return 'unclassified';
        }
        if ((bool) $facts['external_declared']) {
            return 'external';
        }
        if ($policyClass === 'env') {
            return 'environment-bound';
        }
        if ($policyClass === 'managed') {
            // MUP §1.1: `managed` (active_plugins, template, stylesheet)
            // projects `authored`; the difference is entirely in handling.
            return 'authored';
        }

        return $policyClass;
    }

    /**
     * MUP §1.2.
     *
     * @param array<string,mixed> $facts
     * @param list<string> $annotations
     */
    private static function projectHandling(
        array $facts,
        ?string $policyClass,
        string $stateClass,
        array &$annotations
    ): string {
        if ($stateClass === 'unclassified') {
            return 'block';
        }
        if ($stateClass === 'external') {
            if ((bool) $facts['resync_declared']) {
                return 're-synchronize';
            }
            $annotations[] = self::ANNOTATION_EXTERNAL_NO_RESYNC;

            return 'block';
        }
        if ($policyClass === 'managed') {
            $annotations[] = self::ANNOTATION_MANAGED;

            return 'manage';
        }
        if ($stateClass === 'authored') {
            if ((bool) $facts['in_scope']) {
                return 'manage';
            }
            // §1.2 names `manage` only for authored state *inside the
            // captured scope*. Outside it Duo writes nothing, which is the
            // definition of `preserve local`; calling it `block` would claim
            // a refusal where there is no hazard, only an absence of scope.
            $annotations[] = self::ANNOTATION_OUT_OF_SCOPE;

            return 'preserve local';
        }
        if ($stateClass === 'runtime') {
            return 'preserve local';
        }
        if ($stateClass === 'derived') {
            // Both §1.2 derived rows project `rebuild`; the second one only
            // forces readiness. Handling is therefore unconditional here and
            // the missing repair path is carried by forceReadiness().
            return 'rebuild';
        }

        return 'rebind';
    }

    /**
     * MUP §1.5.
     *
     * A declared live effect wins over the hook-free-window argument: the
     * declaration is a reviewed statement about the surface, so it converts
     * an inference into a known, bounded live effect (MUP §1.6's
     * consequence paragraph), and downgrading it back to `prevented` would
     * discard the review.
     *
     * @param array<string,mixed> $containment
     */
    private static function projectContainment(array $containment): string {
        if ((bool) $containment['declared_live']) {
            return 'live';
        }
        if ((bool) $containment['apply_window_only']
            && !(bool) $containment['lifecycle_touch']
            && !(bool) $containment['provider_touch']
        ) {
            return 'prevented';
        }

        return 'unknown';
    }

    /**
     * MUP §1.3, most-blocking row first.
     *
     * The table is a set of independent rows, so an evaluation order is
     * required and is chosen by how definite the statement is: `Unsupported`
     * is a stated boundary Duo understands, `Not qualified` is an admission
     * that proof is missing, `Requalification required` says proof existed
     * and expired, `Experimental` says proof exists but cannot authorize
     * production. Conditions come last because they only ever qualify an
     * otherwise-certified verdict.
     *
     * @param array<string,mixed> $registry
     * @param list<string> $negotiation
     */
    private static function projectReadiness(
        array $registry,
        array $negotiation,
        string $operation,
        ?string $unsupportedReason
    ): string {
        $claimStatus = $registry['claim_status'] === null ? null : (string) $registry['claim_status'];
        $evidenceStatus = $registry['evidence_status'] === null ? null : (string) $registry['evidence_status'];
        $verdictStatus = $registry['verdict_status'] === null ? null : (string) $registry['verdict_status'];
        /** @var list<string> $blockers */
        $blockers = array_values($registry['blockers']);
        /** @var list<string> $conditions */
        $conditions = array_values($registry['conditions']);

        if (in_array($claimStatus, ['excluded', 'unsupported'], true)
            || array_intersect($blockers, self::BLOCKERS_UNSUPPORTED) !== []
            // MUP §1.3's last Unsupported clause: a surface named in
            // `deletion_semantics.unsupported` for a delete operation.
            || ($operation === 'delete' && $unsupportedReason !== null)
        ) {
            return 'Unsupported';
        }
        if (array_intersect($blockers, self::BLOCKERS_NOT_QUALIFIED) !== []) {
            return 'Not qualified';
        }
        if (array_intersect($blockers, self::BLOCKERS_REQUALIFICATION) !== []) {
            return 'Requalification required';
        }
        if ($claimStatus === 'experimental' || $evidenceStatus === 'candidate') {
            return 'Experimental';
        }
        if ($negotiation !== []) {
            return 'Ready with conditions';
        }
        if ($verdictStatus === 'certified') {
            return $conditions === [] ? 'Ready' : 'Ready with conditions';
        }

        // No certified verdict and no stated boundary: the proof is simply
        // incomplete, which is precisely what `Not qualified` means.
        return 'Not qualified';
    }

    /**
     * The two forced overrides MUP states outside the §1.3 table.
     *
     * `Unsupported` survives both of them. It is a boundary Duo understands
     * well enough to refuse for a stated reason; rewriting it as `Not
     * qualified` would replace a fact with an admission of ignorance and
     * would lose the reason the operator needs.
     *
     * @param list<string> $annotations
     */
    private static function forceReadiness(
        string $readiness,
        string $stateClass,
        bool $rebuildDeclared,
        ?string $unsupportedReason,
        array &$annotations
    ): string {
        if ($stateClass === 'derived' && !$rebuildDeclared) {
            // MUP §1.2 row 5: readiness forced to `Not qualified` with the
            // claim's own `unsupported[].reason` quoted verbatim.
            $annotations[] = self::ANNOTATION_NO_REPAIR_PATH_PREFIX
                . ($unsupportedReason ?? 'no reason recorded');
            if ($readiness !== 'Unsupported') {
                return 'Not qualified';
            }
        }
        if ($stateClass === 'unclassified'
            && in_array($readiness, ['Ready', 'Ready with conditions'], true)
        ) {
            // Nothing in §1.3 can legitimately make an unclassified surface
            // Ready — there is no registry entry for a surface no rule
            // matched. A caller that hands one anyway is describing a
            // different surface than the one it failed to classify, and the
            // honest projection of that disagreement is `Not qualified`.
            $annotations[] = self::ANNOTATION_UNCLASSIFIED_NOT_QUALIFIED;

            return 'Not qualified';
        }

        return $readiness;
    }

    /**
     * MUP §1.4.
     *
     * @param array<string,mixed> $registry
     * @param list<string> $annotations
     */
    private static function projectProvenance(array $registry, array &$annotations): string {
        if ((bool) $registry['site_certified']) {
            $annotations[] = self::ANNOTATION_SITE_CERTIFICATION_DEFERRED;

            return 'Uncertified';
        }
        if ((string) ($registry['source'] ?? '') === 'shipped'
            && (string) ($registry['claim_status'] ?? '') === 'certified'
            && (string) ($registry['evidence_status'] ?? '') === 'current'
        ) {
            return 'Platform-certified';
        }

        return 'Uncertified';
    }

    /**
     * MUP §1.6.
     *
     * Order differs from the table's printed order, which is a list of
     * conditions rather than a decision procedure. `irreversible` is first
     * because it is the only value that describes damage already done.
     * `unclassified` is second: MUP §2.1's worked example prints
     * `unknown` recovery for the unclassified custom table, and claiming
     * `not applicable` about a surface Duo could not classify would be a
     * claim it has no basis for. Coverage by a named bundle then beats the
     * "no external effect" row, which is why §2.1 prints `products ...
     * provider-state restorable` rather than `not applicable`.
     *
     * @param array<string,mixed> $recovery
     * @param list<string> $annotations
     */
    private static function projectRecovery(
        array $recovery,
        string $stateClass,
        string $containment,
        array &$annotations
    ): string {
        $bundle = $recovery['covered_by_bundle'] === null
            ? null
            : (string) $recovery['covered_by_bundle'];

        if ((bool) $recovery['irreversible']) {
            return 'irreversible';
        }
        if ($stateClass === 'unclassified') {
            return 'unknown';
        }
        if ($containment === 'unknown' && (bool) $recovery['external_effect_exists']) {
            return 'unknown';
        }
        if ($bundle !== null && $bundle !== '') {
            $annotations[] = self::ANNOTATION_RESTORED_BY_PREFIX . $bundle;

            return 'provider-state restorable';
        }
        if (!(bool) $recovery['external_effect_exists']) {
            return 'not applicable';
        }

        return 'unknown';
    }

    /**
     * Registry conditions pass through verbatim; negotiation problems are
     * minted here as *unmet* conditions naming their code (MUP §1.3).
     *
     * @param array<string,mixed> $registry
     * @param list<string> $negotiation
     * @param list<string> $annotations
     * @return list<string>
     */
    private static function projectConditions(
        array $registry,
        array $negotiation,
        array &$annotations
    ): array {
        $conditions = [];
        foreach ($registry['conditions'] as $condition) {
            $conditions[] = (string) $condition;
        }
        foreach ($negotiation as $code) {
            $conditions[] = self::CONDITION_PROVIDER_NEGOTIATION_PREFIX . (string) $code;
        }
        if ($negotiation !== []) {
            $annotations[] = self::ANNOTATION_PROVIDER_NEGOTIATION_UNMET;
        }

        return $conditions;
    }

    /**
     * The one remediation sentence a row carries, most-blocking first.
     *
     * @param list<string> $negotiation
     * @param list<string> $conditions
     */
    private static function projectRemediation(
        string $stateClass,
        string $handling,
        string $readiness,
        array $negotiation,
        array $conditions,
        ?string $unsupportedReason,
        bool $rebuildDeclared
    ): ?string {
        if ($readiness === 'Unsupported') {
            return 'exclude this surface from the operation; Duo refuses it for a stated reason'
                . ($unsupportedReason === null ? '' : ': ' . $unsupportedReason);
        }
        if ($stateClass === 'unclassified') {
            // MUP §3.3's literal remediation for the unclassified row.
            return 'qualify in rehearsal, or declare it out of scope in the contract';
        }
        if ($stateClass === 'external' && $handling === 'block') {
            return 'declare a re-synchronization action in a manifest, or declare this surface '
                . 'out of scope in the contract';
        }
        if ($handling === 'block') {
            return 'declare this effect in the contract with a containment value, an explicit '
                . 'recovery-semantics value and a reviewed reason, or exclude the surface';
        }
        if ($stateClass === 'derived' && !$rebuildDeclared) {
            return 'install an adapter declaring a regenerator for this surface, or exclude it; '
                . 'the registry states: ' . ($unsupportedReason ?? 'no reason recorded');
        }
        if ($readiness === 'Requalification required') {
            return 're-certify the pinned evidence, then re-run assess';
        }
        if ($readiness === 'Experimental') {
            return 'qualify in rehearsal; experimental evidence never authorizes production, '
                . 'including through a conditional path';
        }
        if ($readiness === 'Not qualified') {
            return 'qualify in rehearsal, or install an adapter that registers this surface';
        }
        if ($negotiation !== []) {
            return 'resolve the provider negotiation problem(s) ' . implode(', ', $negotiation)
                . '; the condition is unmet and blocks at the mutation gate';
        }
        foreach ($conditions as $condition) {
            if (str_contains($condition, self::CONDITION_ENV_MISSING_MARKER)) {
                return 'provision the environment value named in the condition before the mutation gate';
            }
        }

        return null;
    }

    /**
     * The closed key set and per-key types of the fact vector.
     *
     * Strict on purpose: a silently-defaulted fact is a silently-wrong
     * projection, and this is the one function every product word in the
     * round flows through.
     *
     * @param array<string,mixed> $facts
     */
    private static function assertFacts(array $facts): void {
        $scalars = [
            'operation' => 'string',
            'policy_class' => '?string',
            'unclassified' => 'bool',
            'external_declared' => 'bool',
            'in_scope' => 'bool',
            'rebuild_declared' => 'bool',
            'resync_declared' => 'bool',
            'unsupported_reason' => '?string',
        ];
        $objects = ['registry', 'containment', 'recovery'];
        $known = array_merge(array_keys($scalars), $objects, ['provider_negotiation']);

        foreach (array_keys($facts) as $key) {
            if (!in_array($key, $known, true)) {
                throw new \InvalidArgumentException("projection facts: unknown key '$key'");
            }
        }
        foreach ($scalars as $key => $type) {
            if (!array_key_exists($key, $facts)) {
                throw new \InvalidArgumentException("projection facts: missing key '$key'");
            }
            $value = $facts[$key];
            $ok = match ($type) {
                'string' => is_string($value),
                '?string' => $value === null || is_string($value),
                default => is_bool($value),
            };
            if (!$ok) {
                throw new \InvalidArgumentException("projection facts: '$key' has the wrong type");
            }
        }
        foreach ($objects as $key) {
            if (!isset($facts[$key]) || !is_array($facts[$key])) {
                throw new \InvalidArgumentException("projection facts: '$key' must be an object");
            }
        }
        if (!isset($facts['provider_negotiation']) || !is_array($facts['provider_negotiation'])) {
            throw new \InvalidArgumentException('projection facts: provider_negotiation must be a list');
        }
        if (!in_array($facts['operation'], self::OPERATIONS, true)) {
            throw new \InvalidArgumentException(
                'projection facts: operation must be one of ' . implode(', ', self::OPERATIONS)
            );
        }
        if ($facts['policy_class'] !== null
            && !in_array($facts['policy_class'], self::POLICY_CLASSES, true)
        ) {
            throw new \InvalidArgumentException(
                'projection facts: policy_class must be null or one of ' . implode(', ', self::POLICY_CLASSES)
            );
        }

        self::assertKeys($facts['registry'], [
            'claim_status', 'evidence_status', 'verdict_status', 'blockers',
            'conditions', 'source', 'site_certified',
        ], 'registry');
        self::assertKeys($facts['containment'], [
            'apply_window_only', 'lifecycle_touch', 'provider_touch', 'declared_live',
        ], 'containment');
        self::assertKeys($facts['recovery'], [
            'covered_by_bundle', 'external_effect_exists', 'irreversible',
        ], 'recovery');

        foreach (['blockers', 'conditions'] as $listKey) {
            if (!is_array($facts['registry'][$listKey])) {
                throw new \InvalidArgumentException("projection facts: registry.$listKey must be a list");
            }
        }
        if (!is_bool($facts['registry']['site_certified'])) {
            throw new \InvalidArgumentException('projection facts: registry.site_certified must be a bool');
        }
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $keys
     */
    private static function assertKeys(array $value, array $keys, string $label): void {
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $keys, true)) {
                throw new \InvalidArgumentException("projection facts: unknown key '$label.$key'");
            }
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                throw new \InvalidArgumentException("projection facts: missing key '$label.$key'");
            }
        }
    }

    /**
     * The three honesty properties, enforced rather than documented.
     *
     * @param array<string,mixed> $projection
     */
    private static function assertProjection(array $projection): void {
        $enums = [
            'state_class' => self::STATE_CLASSES,
            'handling' => self::HANDLINGS,
            'readiness' => self::READINESS,
            'certification_provenance' => self::CERTIFICATION_PROVENANCE,
            'effect_containment' => self::EFFECT_CONTAINMENT,
            'effect_recovery_semantics' => self::EFFECT_RECOVERY_SEMANTICS,
        ];
        foreach ($enums as $key => $allowed) {
            if (!in_array($projection[$key], $allowed, true)) {
                throw new \LogicException("projection produced an off-vocabulary $key");
            }
            if (in_array($projection[$key], self::NEVER_EMITTED, true)) {
                throw new \LogicException(
                    'projection produced ' . (string) $projection[$key]
                    . ', which this profile can never earn'
                );
            }
        }
        if ($projection['effect_containment'] === 'prevented'
            && $projection['effect_containment_basis'] !== self::CONTAINMENT_BASIS_PREVENTED
        ) {
            throw new \LogicException('prevented containment must carry its literal basis string');
        }
        if ($projection['effect_containment'] !== 'prevented'
            && $projection['effect_containment_basis'] !== self::CONTAINMENT_BASIS_UNKNOWN
        ) {
            throw new \LogicException('non-prevented containment must carry the not-enforced basis string');
        }
    }
}
