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
 * from facts that do: `Policy::CLASSES`, `AdapterRegistry::report()`,
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
 *   - `sandboxed` is never emitted. MUP has no egress control, so the value
 *     is not structurally provable (MUP §1.5).
 *   - `compensatable` is never emitted. It needs a declared compensation
 *     action, which does not exist (MUP §1.6).
 *
 * `Site-certified` was the third of those properties for the whole of MUP,
 * and round-3 T6 §3.2 is what earned it. It is emitted now, and ONLY on the
 * exact fact that makes it true: the governing claim is `certified` AND its
 * certification came from a site certificate
 * (`certification.source == "site"`). That certificate is an Ed25519
 * signature over the adapter's exact bytes under a key in a trust root the
 * site or the agent owns — a fact, verified by
 * `AdapterCertification::verifyFile()` before it ever reaches this class,
 * not a declaration that certified itself.
 *
 * What it does NOT mean is stated on the row rather than left to be
 * inferred: `Site-certified` is customer-organization approval, explicitly
 * not a Duo endorsement, and the contract's own `attestation.state` is
 * still `unsigned` (MUP §3.2's honesty property survives, narrowed to the
 * thing that is still true). `ANNOTATION_SITE_CERTIFIED_PREFIX` is how the
 * human view says both in one line.
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

    /**
     * MUP §2.1 item 4 + T6 §3.6: the closed smallest-safe-next-action set.
     *
     * `certify adapter` is T6's addition and it exists because `install
     * adapter` was being printed at operators who had ALREADY installed one.
     * An adapter that is present but uncertified, or signed but not exactly
     * pinned, is not a missing adapter — it is one signature or one pin away,
     * and `duo adapter certify` is the literal command. Telling that operator
     * to install an adapter was telling them to redo the thing they just did.
     *
     * `qualify in rehearsal` stays in the set and is NOT emitted by this
     * profile (T6 §3.6). Rehearsal states it cannot qualify anything, so
     * every place that used to reach for it now names the action that can
     * actually close the gap. It stays in the set rather than being deleted
     * because a stored projection written by an earlier build carries it, and
     * `GapActions::assertMember()` must keep accepting one.
     *
     * `attest contract` is the contract-attestation signer's word and it ships
     * the same way `qualify in rehearsal` sits: IN the set, emitted by
     * nothing. The signer is real — `ContractAttestation` signs and verifies
     * under `.duo/contract/authorities.json` — but the trust root ships empty,
     * so on every site this build reaches, "attest the contract" is not a
     * smallest safe next action: it is a request to provision an
     * organizational signing key, which is a decision and not a step. It is
     * added now rather than when something emits it because the set is closed
     * and a projection written by a build that HAS the word must validate
     * against a build that does not emit it — the same direction the
     * rehearsal word travels. The roll-up prints it at zero either way
     * (GapActions::summarise() seeds every action), which is the count being
     * the signal.
     */
    public const GAP_ACTIONS = [
        'classify', 'declare in contract', 'qualify in rehearsal', 'exclude',
        'provision env value', 'install adapter', 'certify adapter', 'attest contract',
        'nothing — supported',
    ];

    /**
     * Words this profile structurally cannot earn. Checked on every
     * projection; see the class docblock for why each one is absent.
     */
    public const NEVER_EMITTED = ['sandboxed', 'compensatable'];

    /**
     * Fact-vector keys T6 added that a caller may omit.
     *
     * `assertFacts()` is strict on purpose — a silently-defaulted fact is a
     * silently-wrong projection. These two are the stated exception and the
     * reason is that their absence is not ambiguous: no `probable_owner` and
     * no `certification` block both mean exactly "nobody attributed/vouched
     * for this", which is the same thing an explicit null says. A present
     * value of the wrong TYPE is still refused.
     */
    public const OPTIONAL_FACTS = ['probable_owner'];
    public const OPTIONAL_REGISTRY_FACTS = ['certification'];

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

    /**
     * MUP §1.4 row 2: signed evidence that is not a CERTIFIED claim.
     *
     * Reached only after the `Site-certified` test above has failed, so a
     * real signature is present and something else is missing: usually the
     * exact `{name,source,digest}` pin (the engine reports `signed_unpinned`
     * and the claim never reaches `certified`), sometimes evidence that went
     * stale. Both are one operator command away, and the gap action says
     * which. Without this annotation the row would print `Uncertified` beside
     * a valid certificate with nothing explaining the gap.
     */
    public const ANNOTATION_SITE_SIGNED_UNPINNED =
        'this adapter carries valid signed evidence that is not a certified claim: no site exercise was recorded, '
        . 'its repository pin is not exact, or its evidence is no longer current';

    /**
     * T6 §3.6's literal sentence, printed once per principal.
     *
     * Both halves are load-bearing. The first names WHO approved (an
     * organization, not Duo). The second keeps MUP §3.2's honesty property
     * alive in its still-true form: the application contract itself carries
     * no machine-legible signature, so a certified adapter does not make the
     * operator's contract a signed document.
     */
    public const ANNOTATION_SITE_CERTIFIED_PREFIX = 'certified by ';
    public const ANNOTATION_SITE_CERTIFIED_SUFFIX = '; contract attestation unsigned';

    /**
     * The same sentence's second half once the contract IS signed.
     *
     * `ANNOTATION_SITE_CERTIFIED_SUFFIX` is not retired and must not be: a
     * projection written before the signer existed carries it verbatim, and it
     * is still the true half on every site that has provisioned no contract
     * trust root — which is every site this build ships to. The swap happens
     * where a VERIFIED attestation is actually in hand
     * (`AssessRenderer::siteCertifiedPrincipals()`, fed from the contract
     * `AssessCommand` already read), never by rewriting the constant, because
     * the two sentences describe two different states of one site and both
     * states exist.
     */
    public const ANNOTATION_SITE_CERTIFIED_ATTESTED_SUFFIX = '; contract attested by ';

    /** The §1.6 consequence, stated on the row it blocks. */
    public const ANNOTATION_CONTAINMENT_BLOCK =
        'containment is unknown and an external effect may exist, so an operation able to reach '
        . 'a live system is blocked';

    public const ANNOTATION_UNCLASSIFIED_NOT_QUALIFIED =
        'an unclassified surface has no registry entry that could make it Ready';

    /** Prefixes so callers can recognise a generated annotation without string archaeology. */
    public const ANNOTATION_NO_REPAIR_PATH_PREFIX = 'no declared repair path; the registry states: ';
    public const ANNOTATION_RESTORED_BY_PREFIX = 'restored by: ';
    public const ANNOTATION_PRESERVE_LOCAL_UNSUPPORTED = 'preserve local: this surface stays on the target; Duo does not copy or write it, so no readiness claim applies';

    /** Condition-string shapes this class mints itself. */
    public const CONDITION_PROVIDER_NEGOTIATION_PREFIX = 'unmet: provider negotiation ';

    /**
     * The registry condition code that means "an environment value is not
     * provisioned on this target" (MUP §1.3, "any `env_missing` plan row").
     * Matched as a substring of a caller-supplied condition sentence because
     * `AdapterRegistry::report()` renders conditions as prose that always
     * names its code.
     */
    public const CONDITION_ENV_MISSING_MARKER = 'env_missing';

    /**
     * Registry blockers by the readiness word they force (MUP §1.3).
     *
     * `multisite_unsupported` is gone from the `Unsupported` row: it was raised
     * against a generated evidence record's measured platform axes, and with
     * that record removed the agent reports no topology boundary per surface at
     * all (`AdapterRegistry::target_reasons()` says so in its own docblock).
     * Topology is judged once, by `AssessCommand::assess()`, which refuses the
     * whole assessment — a code this table kept would have implied a per-surface
     * answer nothing produces.
     *
     * `BLOCKERS_REQUALIFICATION` holds exactly one code, and it is not one the
     * agent produces: `evidence_not_current` is synthesized by
     * `ContractProjection::withStaleEvidence()` when a contract's pinned
     * dispositions hash no longer matches what the target reports. The two
     * codes that used to sit beside it (`revision_not_certified`,
     * `profile_evidence_not_current`) were raised by the generated capability
     * registry against a generated evidence record; neither document exists, so
     * nothing can raise them and keeping them would describe a tier this build
     * cannot reach.
     */
    public const BLOCKERS_UNSUPPORTED = [
        'surface_explicitly_unsupported', 'deletion_unsupported',
    ];
    public const BLOCKERS_NOT_QUALIFIED = [
        'adapter_source_uncertified', 'missing_disposition_entry', 'surface_not_registered',
    ];
    public const BLOCKERS_REQUALIFICATION = [
        'evidence_not_current',
    ];

    /**
     * T6 §3.6: `Not qualified` causes an OPERATOR can close with a signature.
     *
     * `adapter_source_uncertified` is the registry's word for "installed
     * out-of-tree, carries no reviewed certification evidence".
     * `adapter_certification_unpinned` is its word for the `signed_unpinned`
     * catalog state — a valid certificate whose repository pin does not bind
     * both source and the certificate-derived digest
     * (agent/src/Adapter/AdapterRegistry.php:396). Both are one
     * `duo adapter certify … --pin` away; neither is a missing adapter.
     */
    public const BLOCKERS_CERTIFIABLE = [
        'adapter_source_uncertified', 'adapter_certification_unpinned',
    ];

    /**
     * The registry blocker that means "the adapter is certified and models
     * this surface, but its certification does not cover THIS operation"
     * (AdapterRegistry: `<op> is not certified for '<name>'`). Alone, it
     * is not a missing adapter and not a missing signature — nothing the
     * operator installs or signs adds an operation to a certification that
     * ratified none (a site certification bundle claims no `delete`, by
     * construction; a shipped adapter's reviewed set is the platform's). The
     * smallest safe action is to keep that operation off this surface:
     * `exclude`. The walk read `install adapter (delete)` under every Ready,
     * certified shop-adapter and site-adapter row before this (walk run 20).
     */
    public const BLOCKER_OPERATION_NOT_CERTIFIED = 'operation_not_certified';

    /**
     * Project one surface × operation into the spec's vocabulary.
     *
     * @param array<string,mixed> $facts the exact fact vector documented in
     *        MUP §1 and in this round's shared interface; see assertFacts()
     *        for the closed key set and the per-key types.
     * @return array<string,mixed> keys: state_class, handling, readiness,
     *         certification_provenance, certification_principal,
     *         certification_trust_root, effect_containment,
     *         effect_containment_basis, effect_recovery_semantics,
     *         conditions, blockers, probable_owner, remediation, meaning,
     *         annotations.
     */
    public static function project(array $facts): array {
        self::assertFacts($facts);
        // The two T6 additions default rather than being required, so a
        // caller describing a contract-declared surface (ContractProjection)
        // is not forced to write down two nulls it has no opinion about.
        // assertFacts() still refuses a WRONGLY-TYPED one — the strictness
        // that matters is about a fact that is present and wrong, not about
        // a fact nobody has.
        $facts += ['probable_owner' => null];
        $facts['registry'] += ['certification' => null];

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
        // The product spec's own worked row ("Orders and inventory | Capture
        // from preview | Runtime | Preserve local | Unsupported | … | Live
        // operational state is not copied"): a surface Duo deliberately leaves
        // to the target is not an operation Duo performs, so a certified claim
        // for the adapter that OWNS the surface must not project `Ready` onto
        // it. Handling `preserve local` therefore forces `Unsupported` for
        // every mutating or copying operation. Found live by grind_mup.sh step
        // 3 (orders projected `runtime / preserve local / Ready` before this).
        if ($handling === 'preserve local'
            && in_array($operation, ['capture', 'merge', 'release', 'delete'], true)
            && $readiness !== 'Unsupported'
        ) {
            $annotations[] = self::ANNOTATION_PRESERVE_LOCAL_UNSUPPORTED;
            $readiness = 'Unsupported';
        }

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
            (bool) $facts['rebuild_declared'],
            array_values($registry['blockers'])
        );

        /** @var array<string,mixed>|null $certification */
        $certification = is_array($registry['certification'] ?? null) ? $registry['certification'] : null;

        $projection = [
            'state_class' => $stateClass,
            'handling' => $handling,
            'readiness' => $readiness,
            'certification_provenance' => $provenance,
            // T6 §3.6: the projection EXPOSES who vouched and under whose
            // trust root. Carried whenever the claim has a certification
            // block, not only when the provenance word came out
            // `Site-certified` — a `signed_unpinned` row names its principal
            // too, and that is exactly the operator who needs to know which
            // key to re-pin against.
            'certification_principal' => is_string($certification['principal'] ?? null)
                ? $certification['principal']
                : null,
            'certification_trust_root' => is_string($certification['trust_root'] ?? null)
                ? $certification['trust_root']
                : null,
            'effect_containment' => $containment,
            'effect_containment_basis' => $containmentBasis,
            'effect_recovery_semantics' => $recovery,
            'conditions' => $conditions,
            // The registry blocker codes that drove `readiness`. gapAction()
            // is a pure function OF A PROJECTION (AuthorizationPlan re-derives
            // one from a stored projection.json, with no fact vector in
            // reach), so the facts it now needs to tell `certify adapter`
            // from `install adapter` have to travel on the projection itself.
            'blockers' => array_values($registry['blockers']),
            'probable_owner' => $facts['probable_owner'] === null ? null : (string) $facts['probable_owner'],
            'remediation' => $remediation,
            'meaning' => self::meaningFor($stateClass, $handling),
            'annotations' => array_values(array_unique($annotations)),
        ];

        self::assertProjection($projection);

        return $projection;
    }

    /**
     * The smallest safe next action for one projected row (MUP §2.1 item 4,
     * rewritten by T6 §3.6).
     *
     * Every word this returns has to be a command the operator can actually
     * run today, and T6 found two that were not.
     *
     * **`qualify in rehearsal` is gone from the emitted set.** MUP's own
     * worked examples printed it for an unclassified custom table, and
     * rehearsal states in its own output that it cannot qualify anything —
     * so the most urgent row on a real site's assessment named an action
     * that does not exist. What DOES close that gap depends on why the
     * surface is unknown: if some active plugin probably owns it, the answer
     * is an adapter that models it (`install adapter`); if nothing owns it,
     * the answer is a classification rule (`classify`). `probable_owner` is
     * the fact that splits them, and it comes from `Coverage`'s own
     * attribution rather than from anything guessed here.
     *
     * **`install adapter` was being printed at operators who had one.** An
     * adapter that is installed but uncertified (`adapter_source_uncertified`)
     * or signed but not exactly pinned (`signed_unpinned`) is one signature
     * or one pin from Ready, and `duo adapter certify` is the command.
     * `certify adapter` is that row's word. The same word answers
     * `Requalification required` and `Experimental`, whose two remaining
     * causes — a contract pinned to reviewed dispositions that have since
     * moved (`evidence_not_current`), and an authored `experimental` claim —
     * resolve the same way: get a reviewed, current certification.
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
        /** @var list<string> $blockers */
        $blockers = is_array($projection['blockers'] ?? null)
            ? array_values($projection['blockers'])
            : [];
        $probableOwner = is_string($projection['probable_owner'] ?? null) && $projection['probable_owner'] !== ''
            ? $projection['probable_owner']
            : null;

        if ($handling === 'preserve local') {
            // The handling IS the resolution: the surface stays on the
            // target and Duo never copies or writes it, so its `Unsupported`
            // readiness for a copying operation is a boundary statement, not
            // a gap an operator can close (the spec's worked orders row
            // prints a meaning line and no next action).
            return 'nothing — supported';
        }
        if ($readiness === 'Unsupported') {
            return 'exclude';
        }
        if ($stateClass === 'unclassified') {
            // T6 §3.6. Unknown containment means nothing modelled this
            // surface, and the honest remedy depends on whether anything
            // COULD have: an active plugin that probably owns it is an
            // adapter waiting to be written, while an unowned surface is a
            // classification rule. A known-containment unclassified surface
            // is the ordinary `duo classify` queue either way.
            if ($containment === 'unknown' && $probableOwner !== null) {
                return 'install adapter';
            }

            return 'classify';
        }
        if ($handling === 'block') {
            // Both remaining block causes — an external surface with no
            // declared re-sync action, and an unknown-containment live
            // effect — are closed by a reviewed declaration, which is
            // exactly MUP §1.6's stated next action.
            return 'declare in contract';
        }
        if ($readiness === 'Requalification required' || $readiness === 'Experimental') {
            // Dispositions that moved under an accepted contract, or a claim
            // authored `experimental`. One remedy: a reviewed, current
            // certification. Rehearsal cannot produce it and never could.
            return 'certify adapter';
        }
        if ($readiness === 'Not qualified') {
            // The adapter EXISTS and is one signature or one pin short —
            // `duo adapter certify <site-repo> --name=<n> --pin` closes both.
            if (array_intersect($blockers, self::BLOCKERS_CERTIFIABLE) !== []) {
                return 'certify adapter';
            }
            // The adapter exists and is certified; only this OPERATION is
            // outside what it certifies. Nothing to install or sign — keep
            // the operation off the surface.
            if ($blockers === [self::BLOCKER_OPERATION_NOT_CERTIFIED]) {
                return 'exclude';
            }
            // Anything else at `Not qualified` genuinely has no adapter
            // modelling the surface.
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
        // `Experimental` is now the authored status alone. The second route in
        // — a `candidate` evidence record — described a generated evidence
        // document that had been written but not authorized; there is no such
        // document, and a claim's `evidence` is the authored citation its
        // disposition carries verbatim, which has no status to be candidate.
        if ($claimStatus === 'experimental') {
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
        // The claim's own status is the whole test. It used to be conjoined
        // with `evidence_status === 'current'`, which addressed a generated
        // evidence record: a shipped claim now carries the authored citation
        // with no status at all, so that conjunct would report every
        // platform-reviewed adapter as `Uncertified`.
        $certified = (string) ($registry['claim_status'] ?? '') === 'certified';
        /** @var array<string,mixed>|null $certification */
        $certification = is_array($registry['certification'] ?? null) ? $registry['certification'] : null;

        // T6 §3.2/§3.3. The test is the CLAIM's own certification block, not
        // the adapter's source: `site_signed` and `third_party_signed` differ
        // in which trust root vouched, and both are a site certificate, so
        // both read `Site-certified`. A signed override of a shipped name is
        // therefore never `Platform-certified` — the platform did not review
        // the operator's copy, and saying it did would be the one lie this
        // whole projection exists to prevent.
        if ($certified && ($certification['source'] ?? null) === 'site') {
            $principal = is_string($certification['principal'] ?? null) && $certification['principal'] !== ''
                ? $certification['principal']
                : 'an unnamed site authority';
            $trustRoot = is_string($certification['trust_root'] ?? null) && $certification['trust_root'] !== ''
                ? $certification['trust_root']
                : 'site';
            $annotations[] = self::ANNOTATION_SITE_CERTIFIED_PREFIX . $principal
                . ' (' . $trustRoot . ' trust root)' . self::ANNOTATION_SITE_CERTIFIED_SUFFIX;

            return 'Site-certified';
        }
        if ((bool) $registry['site_certified']) {
            $annotations[] = self::ANNOTATION_SITE_SIGNED_UNPINNED;

            return 'Uncertified';
        }
        if ((string) ($registry['source'] ?? '') === 'shipped' && $certified) {
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
     * @param list<string> $blockers registry blocker codes behind $readiness
     */
    private static function projectRemediation(
        string $stateClass,
        string $handling,
        string $readiness,
        array $negotiation,
        array $conditions,
        ?string $unsupportedReason,
        bool $rebuildDeclared,
        array $blockers = []
    ): ?string {
        if ($readiness === 'Unsupported') {
            return 'exclude this surface from the operation; Duo refuses it for a stated reason'
                . ($unsupportedReason === null ? '' : ': ' . $unsupportedReason);
        }
        if ($stateClass === 'unclassified') {
            // Was MUP §3.3's "qualify in rehearsal, or declare it out of
            // scope". T6 §3.6 retires the first half everywhere, remediation
            // prose included: rehearsal states it cannot qualify anything, so
            // an operator who followed that sentence spent an afternoon
            // proving it. The two things that DO close an unclassified row
            // are a rule that classifies it and a declaration that scopes it
            // out, and where a plugin probably owns it, an adapter is what
            // writes the rule.
            return 'install or author an adapter that models this surface, classify it, '
                . 'or declare it out of scope in the contract';
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
            return 'obtain non-experimental certification evidence; experimental evidence never authorizes '
                . 'production, including through a conditional path';
        }
        if ($readiness === 'Not qualified') {
            // Same split the gap action makes, in prose. An adapter that is
            // installed but uncertified/unpinned needs a signature, not
            // another adapter — see BLOCKERS_CERTIFIABLE.
            if (array_intersect($blockers, self::BLOCKERS_CERTIFIABLE) !== []) {
                return 'certify the installed adapter and pin it exactly: '
                    . 'duo adapter certify <site-repo> --name=<adapter> --secret-key-file=<key> --pin';
            }
            if ($blockers === [self::BLOCKER_OPERATION_NOT_CERTIFIED]) {
                return 'exclude this surface from the operation: the installed adapter is certified, but its '
                    . 'certification does not cover this operation; only a certification that ratifies it would';
            }

            return 'install or author an adapter that registers this surface';
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
            // T6 §3.6: `Coverage`'s own plugin attribution for this surface,
            // or null. Optional in the vector (defaulted below) because every
            // caller outside SurfaceCatalog is describing a surface a
            // contract declared, where no attribution exists and none is
            // wanted.
            'probable_owner' => '?string',
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
                if (in_array($key, self::OPTIONAL_FACTS, true)) {
                    continue;
                }
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
            'claim_status', 'verdict_status', 'blockers',
            'conditions', 'source', 'site_certified', 'certification',
        ], 'registry', self::OPTIONAL_REGISTRY_FACTS);
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
        $certification = $facts['registry']['certification'] ?? null;
        if ($certification !== null && (!is_array($certification) || array_is_list($certification))) {
            throw new \InvalidArgumentException(
                'projection facts: registry.certification must be null or a claim certification object'
            );
        }
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $keys
     */
    /**
     * @param array<string,mixed> $value
     * @param list<string> $keys
     * @param list<string> $optional keys a caller may omit; see OPTIONAL_FACTS
     */
    private static function assertKeys(array $value, array $keys, string $label, array $optional = []): void {
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $keys, true)) {
                throw new \InvalidArgumentException("projection facts: unknown key '$label.$key'");
            }
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value) && !in_array($key, $optional, true)) {
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
