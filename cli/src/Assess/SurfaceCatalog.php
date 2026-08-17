<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once dirname(__DIR__) . '/Contract/ApplicationContract.php';
require_once dirname(__DIR__) . '/Contract/ProjectionVocabulary.php';
require_once __DIR__ . '/GapActions.php';
require_once __DIR__ . '/StackInventory.php';

use Duo\CommandRefusalException;

/**
 * Section 2 of `duo assess` — one row per WordPress-language surface, each
 * carrying the six dimensions of MUP §1 (round-3 MUP §2.1, §4.1).
 *
 * **No plugin slug appears in this file, and none can.** Every surface here
 * is named by data: the target's own `policy.surface_groups` (which the
 * agent derives from the pinned manifests and the site's policy), the
 * registry claim's `surfaces[]`, and the reviewed contract's optional
 * `surface_labels` map. The catalog knows the *grammar* of those names —
 * that a `post_type` group corresponds to a `post_types.<name>` registry
 * selector — and nothing about which names a particular ecosystem uses.
 * That is the engine-adapter boundary stated as code: supporting another
 * plugin must not require adding its name, schema, or business rules here
 * (docs/proposals/engine-adapter-boundary.md), and
 * `regress_assess_composition.sh` greps this directory to keep it true.
 *
 * ## Where a row comes from
 *
 * Three sources, in this precedence:
 *
 *  1. `inventory.policy.surface_groups` — the authoritative set. Each group
 *     already carries the policy `class` Duo decided for it, which is the
 *     §1.1 input, and the manifest that declared it, which is the §1.3/§1.4
 *     input.
 *  2. `coverage.tables.undeclared` — a live table no manifest declares. It
 *     is deliberately NOT a surface group (the agent's inventory says so:
 *     an undeclared table is coverage's finding), and it is exactly MUP
 *     §2.1's `custom catalog tbl` row: `unclassified / block / Not
 *     qualified / Uncertified / unknown / unknown`. Leaving it out of the
 *     catalog would make the assessment's most important row invisible.
 *  3. Registry claim `surfaces[]` entries under the three sections whose
 *     grammar is one-name-per-surface (`post_types`, `taxonomies`,
 *     `tables`) that neither of the first two produced — a surface an
 *     adapter certifies but this site's policy did not report.
 *  4. `inventory.plugins.active_without_adapter[]` — an ACTIVE plugin no
 *     installed manifest declares (T6 §3.6). This is the only row kind whose
 *     id names a plugin, and it is still not a plugin name in this file's
 *     sense: the slug is data the target reported, exactly like a post type
 *     name, and no rule here keys off which slug it is. Without the row an
 *     unmanaged plugin was invisible to assess while being the single
 *     largest thing Duo could not version on the site — `duo init` refused
 *     it, `duo capture` refused its CPTs, and the assessment that is
 *     supposed to say what authority Duo has said nothing at all.
 *
 * The grouped sections (`options`, `post_meta`, `term_meta`, …) mint no
 * rows: the inventory groups them by declarant and class precisely because
 * a row per option name would be a listing bounded by the site's plugin
 * set, which MUP §4.6 forbids. Their unknown members are counted and named
 * in the report's `unknown` block instead.
 *
 * ## The two derivations that are judgement, not transcription
 *
 * **Blocker vs condition.** `CapabilityRegistry::report()` returns one flat
 * `verdict.reasons` list. MUP §1.3 splits it: six codes are *re-evaluated
 * at the mutation gate* against the live target and therefore project
 * `Ready with conditions`, while everything else is a blocker that forces
 * a readiness word. `CONDITION_CODES` is that split, and `verdict_status`
 * is derived from the blockers alone — so a certified adapter running one
 * minor version outside its evidence-bound range reads `Ready with
 * conditions`, naming the code, rather than `Not qualified`.
 *
 * **Registration.** A claim declares a bare section name (`tables`) when it
 * governs that section, and `tables.<name>` for each name it registers.
 * `surface_not_registered` is therefore raised only when the claim declares
 * the section AND omits the name: a claim that never mentions `post_types`
 * is not making a statement about post types at all, and turning its
 * silence into a blocker would mark every WordPress core post type `Not
 * qualified` on a correctly certified site.
 *
 * ## Containment and recovery defaults (§1.5, §1.6)
 *
 * MUP has no egress control, so these are derived from the policy class
 * alone and each one is a stated inference rather than a measurement:
 *
 * | policy class | apply-window only | lifecycle | provider | covered by | effect exists |
 * |---|---|---|---|---|---|
 * | `authored` | yes | no | no | database checkpoint | no |
 * | `runtime`  | yes | no | no | — | no |
 * | `derived`  | yes | no | **yes** (a regenerator runs) | — | no |
 * | `env`      | **no** | no | no | — | no |
 * | `managed`  | no | **yes** | no | code release | **yes** |
 *
 * `env` is not apply-window-only on purpose: rebinding an environment value
 * hands a credential to a system Duo does not model, so `unknown` is the
 * only honest containment — which is exactly what MUP §2.1's worked
 * `payment keys` row prints. `managed` is the code lifecycle window, where
 * activation hooks run with normal WordPress semantics; it is the one row
 * that carries a live effect by construction, and therefore the one that
 * blocks a release until the contract declares it (§1.6's consequence).
 */
final class SurfaceCatalog {
    /**
     * The product operations MUP speaks, mapped to the operation word the
     * capability registry certifies.
     *
     * The registry's vocabulary is the engine's (`apply`, `capture`,
     * `plan`, `promote`, `delete`, …); MUP's is the operator's. Without
     * this map every surface would carry `operation_not_certified` for four
     * of the six product operations and the whole catalog would read `Not
     * qualified` — a false negative produced entirely by vocabulary.
     */
    public const REGISTRY_OPERATION = [
        'capture' => 'capture',
        'merge' => 'plan',
        'release' => 'promote',
        'verify' => 'plan',
        'delete' => 'delete',
        'recover' => 'promote',
    ];

    /**
     * Registry reason codes MUP §1.3 classifies as conditions re-evaluated
     * at the mutation gate rather than as blockers.
     *
     * `theme_not_active` joins the six §1.3 names for the same reason
     * `plugin_not_active` is there: it is a fact about the live target that
     * the mutation gate re-reads, not a fact about the evidence.
     */
    public const CONDITION_CODES = [
        'plugin_version_mismatch', 'plugin_not_active', 'wordpress_version_mismatch',
        'php_version_mismatch', 'database_version_mismatch', 'theme_version_mismatch',
        'theme_not_active',
    ];

    /**
     * Registry operations that structurally contain other registry
     * operations, so a boundary declared about the inner one is a boundary
     * about the outer one too.
     *
     * `CapabilityRegistry::claim_from_disposition()` mints `promote` from
     * `deploy` + `apply` itself, which is why this is a restatement of the
     * registry's own composition rather than a new claim: a surface whose
     * `apply` is explicitly unsupported is not supported inside a promote
     * that performs that apply, and matching only the literal word would
     * silently drop the boundary at exactly the operation that reaches
     * production.
     */
    public const REGISTRY_OPERATION_INCLUDES = [
        'promote' => ['promote', 'apply', 'deploy'],
    ];

    /** Surface-group kinds whose names map one-to-one onto a claim section. */
    public const KIND_SECTION = [
        'post_type' => 'post_types',
        'taxonomy' => 'taxonomies',
        'table' => 'tables',
    ];

    /**
     * T6 §3.6's row kind for an active plugin no adapter declares.
     *
     * Deliberately NOT in KIND_SECTION: there is no manifest section a
     * plugin maps onto, and there is no registry selector for one. A
     * `plugin:` row is a statement that a whole body of state on this site
     * is outside every claim, which is why it projects with no claim at all
     * and reads `unclassified / block / Not qualified / Uncertified /
     * unknown / unknown`.
     */
    public const PLUGIN_KIND = 'plugin';

    /** The recovery bundle each policy class's bytes land in (§1.6). */
    public const CLASS_BUNDLE = [
        'authored' => 'database checkpoint',
        'managed' => 'code release',
    ];

    /**
     * The reviewed label MUP §3.2's proposal gives the code lifecycle
     * window. It is a contract-side alias for "the managed class", which is
     * exactly `active_plugins`, `template` and `stylesheet` (§1.1) — so a
     * declaration naming it covers every managed row without the operator
     * having to enumerate option-group ids.
     */
    public const LIFECYCLE_SURFACE_ALIAS = 'plugins/themes';

    /**
     * Build the catalog.
     *
     * @param array<string,mixed> $inventory a `duo-assess-inventory/v1` document
     * @param array<string,array<string,mixed>> $registryReports one
     *        `CapabilityRegistry::report()` document per REGISTRY_OPERATION
     *        value, keyed by that value
     * @param array<string,mixed>|null $contract the accepted contract, or null
     * @param array<string,mixed> $options `operations` (a list of MUP
     *        operations, default all six) and `provider_negotiation` (a
     *        manifest name -> list of `Providers::diagnose()` codes map;
     *        empty in this profile, see AssessCommand)
     * @return array{rows: list<array<string,mixed>>, facts: array<string,array<string,mixed>>}
     */
    public static function catalog(
        array $inventory,
        array $registryReports,
        ?array $contract,
        array $options = []
    ): array {
        $operations = self::operations($options);
        $negotiation = is_array($options['provider_negotiation'] ?? null)
            ? $options['provider_negotiation']
            : [];
        $labels = self::contractLabels($contract);
        $declared = self::contractSurfaces($contract);
        $liveEffects = self::contractLiveSurfaces($contract);

        $surfaces = self::identities($inventory, $registryReports, $labels);

        $rows = [];
        $facts = [];
        foreach ($surfaces as $id => $identity) {
            [$row, $vectors] = self::projectSurface(
                $id,
                $identity,
                $registryReports,
                $operations,
                $negotiation,
                $declared,
                $liveEffects
            );
            $rows[] = $row;
            $facts[$id] = $vectors;
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return ['rows' => $rows, 'facts' => $facts];
    }

    /**
     * The `ContractProjection::generate()` fact block, built from the same
     * vectors the report rows were projected from.
     *
     * Sharing one derivation is the point: `projection.json` and the assess
     * report must never disagree about a surface, and the cheapest way to
     * guarantee that is for the second document to be a re-encoding of the
     * first's inputs rather than a second computation.
     *
     * @param array{rows: list<array<string,mixed>>, facts: array<string,array<string,mixed>>} $catalog
     * @param list<string> $operations
     * @param list<array<string,mixed>> $bundles observed evidence rows
     * @return array<string,mixed>
     */
    public static function projectionFacts(
        array $catalog,
        array $operations,
        string $registrySha256,
        array $bundles
    ): array {
        $surfaces = [];
        foreach ($catalog['rows'] as $row) {
            $id = (string) $row['id'];
            $vectors = $catalog['facts'][$id] ?? [];
            $entries = [];
            foreach ($operations as $operation) {
                if (!isset($vectors[$operation])) {
                    continue;
                }
                $entries[$operation] = [
                    'facts' => $vectors[$operation]['facts'],
                    'expiry_and_dependencies' => $vectors[$operation]['expiry_and_dependencies'],
                ];
            }
            $surfaces[$id] = [
                'evidence_subjects' => $vectors['__evidence_subjects'] ?? [],
                'operations' => $entries,
            ];
        }

        return [
            'operations' => array_values($operations),
            'registry_sha256' => $registrySha256,
            'bundles' => array_values($bundles),
            'surfaces' => $surfaces,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return list<string>
     */
    private static function operations(array $options): array {
        $requested = $options['operations'] ?? null;
        if ($requested === null) {
            return ProjectionVocabulary::OPERATIONS;
        }
        if (!is_array($requested) || !array_is_list($requested) || $requested === []) {
            throw self::refuse('invalid_arguments', 'the requested operation set is empty or malformed');
        }
        $out = [];
        foreach (ProjectionVocabulary::OPERATIONS as $operation) {
            if (in_array($operation, $requested, true)) {
                $out[] = $operation;
            }
        }
        if (count($out) !== count(array_unique($requested))) {
            throw self::refuse(
                'invalid_arguments',
                'an operation was requested that this profile does not project'
            );
        }

        return $out;
    }

    /**
     * Every surface this assessment must cover, keyed by id.
     *
     * @param array<string,mixed> $inventory
     * @param array<string,array<string,mixed>> $registryReports
     * @param array<string,string> $labels
     * @return array<string,array<string,mixed>>
     */
    private static function identities(array $inventory, array $registryReports, array $labels): array {
        $identities = [];

        foreach (($inventory['policy']['surface_groups'] ?? []) as $group) {
            if (!is_array($group) || !is_string($group['id'] ?? null)) {
                continue;
            }
            $id = $group['id'];
            $identities[$id] = [
                'kind' => (string) ($group['kind'] ?? 'unknown'),
                'policy_class' => is_string($group['class'] ?? null) ? $group['class'] : null,
                'declared_by' => is_string($group['declared_by'] ?? null) ? $group['declared_by'] : null,
                'label' => $labels[$id] ?? $id,
                'source' => 'policy',
                // A declared surface has a declarant, not a guess.
                'probable_owner' => null,
            ];
        }

        // An undeclared live table: no manifest claims it, so no policy rule
        // classified it, so its state class is `unclassified` by §1.1's last
        // row and its handling is `block`. Coverage found it precisely
        // because it is invisible to every installed adapter.
        foreach (($inventory['coverage']['tables']['undeclared'] ?? []) as $table) {
            if (!is_array($table) || !is_string($table['logical_name'] ?? null)) {
                continue;
            }
            $id = 'table:' . $table['logical_name'];
            if (isset($identities[$id])) {
                continue;
            }
            $identities[$id] = [
                'kind' => 'table',
                'policy_class' => null,
                'declared_by' => null,
                'label' => $labels[$id] ?? $id,
                'source' => 'coverage',
                // `Coverage::attribute()` matched this table's name against
                // the ACTIVE plugin slugs. It is a guess and named one, but
                // it is the fact that tells `install adapter` (some plugin
                // owns this and nothing models it) from `classify` (nothing
                // owns it) — T6 §3.6.
                'probable_owner' => is_string($table['probable_owner'] ?? null)
                    ? $table['probable_owner']
                    : null,
            ];
        }

        // T6 §3.6. Every active plugin no installed manifest declares. The
        // isset() guard is the same defensive shape the other two loops use;
        // it can never fire here, because no other source mints a `plugin:`
        // id — no surface group carries kind `plugin` (the agent's
        // AssessInventory::SURFACE_KINDS is a closed list without it) and no
        // registry selector maps onto one.
        foreach (StackInventory::pluginsWithoutAdapter($inventory) as $plugin) {
            $slug = (string) ($plugin['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $id = self::PLUGIN_KIND . ':' . $slug;
            if (isset($identities[$id])) {
                continue;
            }
            $identities[$id] = [
                'kind' => self::PLUGIN_KIND,
                'policy_class' => null,
                'declared_by' => null,
                'label' => $labels[$id] ?? $id,
                'source' => 'inventory',
                // The plugin IS the owner. This is the one row where the
                // attribution is a fact rather than a name match, and it is
                // what makes the row's next action `install adapter` instead
                // of `classify`: there is nothing to classify about a plugin,
                // only an adapter to install or author.
                'probable_owner' => $slug,
            ];
        }

        // A surface an adapter certifies that this site's policy did not
        // report. It exists in the registry, so it is not unknown — but no
        // rule classified it here, which is the same gap and the same
        // handling.
        foreach ($registryReports as $report) {
            foreach (($report['manifests'] ?? []) as $manifest) {
                if (!is_array($manifest)) {
                    continue;
                }
                $name = is_string($manifest['name'] ?? null) ? $manifest['name'] : null;
                foreach (($manifest['surfaces'] ?? []) as $selector) {
                    if (!is_string($selector) || !str_contains($selector, '.')) {
                        continue;
                    }
                    [$section, $rest] = explode('.', $selector, 2);
                    $kind = array_search($section, self::KIND_SECTION, true);
                    if ($kind === false || str_contains($rest, '.')) {
                        continue;
                    }
                    $id = $kind . ':' . $rest;
                    if (isset($identities[$id])) {
                        continue;
                    }
                    $identities[$id] = [
                        'kind' => $kind,
                        'policy_class' => null,
                        'declared_by' => $name,
                        'label' => $labels[$id] ?? $id,
                        'source' => 'registry',
                        'probable_owner' => null,
                    ];
                }
            }
        }

        return $identities;
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,array<string,mixed>> $registryReports
     * @param list<string> $operations
     * @param array<string,list<string>> $negotiation
     * @param array<string,array<string,mixed>> $declared
     * @param list<string> $liveEffects
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private static function projectSurface(
        string $id,
        array $identity,
        array $registryReports,
        array $operations,
        array $negotiation,
        array $declared,
        array $liveEffects
    ): array {
        $policyClass = $identity['policy_class'];
        $declaredBy = $identity['declared_by'];
        $kind = (string) $identity['kind'];
        $name = self::surfaceName($id);
        $selector = isset(self::KIND_SECTION[$kind]) ? self::KIND_SECTION[$kind] . '.' . $name : null;
        $declaredRow = $declared[$id] ?? null;
        $declaredLive = in_array($id, $liveEffects, true)
            || ($policyClass === 'managed' && in_array(self::LIFECYCLE_SURFACE_ALIAS, $liveEffects, true));

        $projections = [];
        $vectors = [];
        $evidenceSubjects = [];
        $stateClasses = [];
        $handlings = [];

        foreach ($operations as $operation) {
            $registryOperation = self::REGISTRY_OPERATION[$operation];
            $report = $registryReports[$registryOperation] ?? null;
            $manifest = is_array($report) ? self::manifestRow($report, $declaredBy) : null;
            // Two different questions, deliberately asked separately.
            // `$matched` is "is this surface outside the guarantee FOR THIS
            // OPERATION" and drives the §1.3 Unsupported blocker.
            // `$repair` is "does a declared repair path exist for it AT
            // ALL" and drives §1.2's derived rows — a lookup table whose
            // apply-time rebuild is unsupported still has no repair path
            // when someone asks about capture.
            $matched = $manifest === null
                ? null
                : self::unsupportedReason($manifest, $selector, $registryOperation);
            $repair = $manifest === null
                ? null
                : self::unsupportedReason($manifest, $selector, null);
            // Carry the repair reason on derived surfaces even where no
            // boundary matched this operation, so §1.2's forced `Not
            // qualified` can quote the registry verbatim instead of
            // printing "no reason recorded". It also reaches §1.3's delete
            // clause and §1.6's `irreversible` row, which is the
            // conservative reading and the correct one: deleting derived
            // state that has no declared repair path cannot be undone by
            // regenerating it, because nothing declares how.
            $unsupportedReason = $matched ?? ($policyClass === 'derived' ? $repair : null);

            $registry = self::registryFacts($manifest, $selector, $matched !== null);
            if ($manifest !== null && is_string($manifest['evidence']['subject'] ?? null)) {
                $evidenceSubjects[$manifest['evidence']['subject']] = true;
            }

            $vector = [
                'operation' => $operation,
                'policy_class' => $policyClass,
                'unclassified' => $policyClass === null,
                'external_declared' => ($declaredRow['state_class'] ?? null) === 'external',
                'in_scope' => $policyClass !== null,
                'rebuild_declared' => $policyClass === 'derived' && $repair === null,
                // MUP §1.2: `re-synchronize` requires a declared action and
                // this profile ships none, so an external surface projects
                // `block` until a manifest declares otherwise. That is
                // stated in the output rather than hidden, which is why the
                // fact is a constant here and not a silent omission.
                'resync_declared' => false,
                'unsupported_reason' => $unsupportedReason,
                'probable_owner' => $identity['probable_owner'] ?? null,
                'registry' => $registry,
                'provider_negotiation' => $declaredBy !== null && isset($negotiation[$declaredBy])
                    ? array_values($negotiation[$declaredBy])
                    : [],
                'containment' => [
                    'apply_window_only' => $policyClass !== null
                        && !in_array($policyClass, ['managed', 'env'], true),
                    'lifecycle_touch' => $policyClass === 'managed',
                    'provider_touch' => $policyClass === 'derived',
                    'declared_live' => $declaredLive,
                ],
                'recovery' => [
                    'covered_by_bundle' => $policyClass === null
                        ? null
                        : (self::CLASS_BUNDLE[$policyClass] ?? null),
                    'external_effect_exists' => $policyClass === 'managed',
                    'irreversible' => $operation === 'delete' && $unsupportedReason !== null,
                ],
            ];

            $projection = ProjectionVocabulary::project($vector);
            $expiry = $manifest === null ? [] : self::expiry($manifest);
            $stateClasses[$projection['state_class']] = true;
            $handlings[$projection['handling']] = true;

            $projections[$operation] = $projection + [
                'gap_action' => ProjectionVocabulary::gapAction($projection),
                'expiry_and_dependencies' => $expiry,
            ];
            $vectors[$operation] = ['facts' => $vector, 'expiry_and_dependencies' => $expiry];
        }

        if (count($stateClasses) > 1) {
            // State class is a property of the state, not of the operation.
            // Two answers means two different fact vectors were built for
            // one surface, which is a defect here rather than a nuance
            // worth rendering.
            throw self::refuse(
                'assess_surface_inconsistent',
                'a surface projected more than one state class across its operations'
            );
        }
        $stateClass = (string) (array_key_first($stateClasses) ?? 'unclassified');
        // Handling legitimately differs per operation (delete may block
        // where release manages). A one-word row must print the restrictive
        // half; the per-operation truth stays in `operations`.
        $handling = count($handlings) === 1 ? (string) array_key_first($handlings) : 'block';

        $vectors['__evidence_subjects'] = array_keys($evidenceSubjects);

        $row = [
            'id' => $id,
            'label' => (string) $identity['label'],
            'kind' => $kind,
            'state_class' => $stateClass,
            'handling' => $handling,
            'operations' => $projections,
            'next_action' => GapActions::forSurface($projections),
            'decided_by' => self::decidedBy($declaredRow, $stateClass),
            'meaning' => ProjectionVocabulary::meaningFor($stateClass, $handling),
        ];

        return [$row, $vectors];
    }

    /**
     * Who decided this surface's handling.
     *
     * An unclassified surface is `unresolved` by construction — nothing
     * decided it, which is exactly what makes it a review item in the
     * generated proposal (`ContractProposal::reviewItems()`). A surface the
     * reviewed contract names keeps the contract's own answer; everything
     * else is the platform's default classification.
     *
     * @param array<string,mixed>|null $declaredRow
     */
    private static function decidedBy(?array $declaredRow, string $stateClass): string {
        if (is_string($declaredRow['decided_by'] ?? null)) {
            return (string) $declaredRow['decided_by'];
        }

        return $stateClass === 'unclassified' ? 'unresolved' : 'platform-default';
    }

    /**
     * The ProjectionVocabulary `registry` fact block for one claim.
     *
     * @param array<string,mixed>|null $manifest a `report()['manifests'][]` row
     * @return array<string,mixed>
     */
    private static function registryFacts(?array $manifest, ?string $selector, bool $explicitlyUnsupported): array {
        if ($manifest === null) {
            return [
                'claim_status' => null,
                'evidence_status' => null,
                'verdict_status' => null,
                // No claim at all is the registry's own `missing_registry_entry`
                // situation, and routing it through that code keeps the
                // readiness word coming from §1.3 rather than being minted here.
                'blockers' => ['missing_registry_entry'],
                'conditions' => [],
                'source' => null,
                'site_certified' => false,
                'certification' => null,
            ];
        }

        $blockers = [];
        $conditions = [];
        foreach (($manifest['verdict']['reasons'] ?? []) as $reason) {
            if (!is_array($reason) || !is_string($reason['code'] ?? null)) {
                continue;
            }
            if (in_array($reason['code'], self::CONDITION_CODES, true)) {
                $conditions[] = (string) ($reason['message'] ?? $reason['code']);
                continue;
            }
            $blockers[] = $reason['code'];
        }
        if ($explicitlyUnsupported) {
            $blockers[] = 'surface_explicitly_unsupported';
        } elseif ($selector !== null && self::sectionGoverned($manifest, $selector)
            && !in_array($selector, (array) ($manifest['surfaces'] ?? []), true)) {
            $blockers[] = 'surface_not_registered';
        }
        $blockers = array_values(array_unique($blockers));

        $evidenceStatus = $manifest['evidence']['status'] ?? null;
        $certification = (string) ($manifest['source']['certification'] ?? 'registry');

        return [
            'claim_status' => is_string($manifest['status'] ?? null) ? $manifest['status'] : null,
            'evidence_status' => is_string($evidenceStatus) ? $evidenceStatus : null,
            // §1.3 reads `Ready`/`Ready with conditions` off a certified
            // verdict. The registry's own verdict word already counts the
            // re-checkable conditions as blocking, so it is recomputed here
            // over the blockers alone — the split MUP §1.3 makes explicit.
            'verdict_status' => $blockers === [] ? 'certified' : 'blocked',
            'blockers' => $blockers,
            'conditions' => $conditions,
            'source' => is_string($manifest['source']['source'] ?? null)
                ? $manifest['source']['source']
                : null,
            // Signed evidence exists. Whether it makes the claim CERTIFIED is
            // a different question, answered by the certification block below
            // — `projectProvenance()` tests that first, so this fact is only
            // ever read on the path where a real signature did NOT produce a
            // certified claim.
            //
            // All three signed words belong here, not just `signed_unpinned`.
            // The commonest cause is an unexact pin, and that word says so
            // itself; but a `site_signed` adapter whose evidence went stale
            // reaches the same place, and dropping it would print
            // `Uncertified` beside a valid certificate with nothing saying
            // which of the two happened.
            'site_certified' => in_array(
                $certification,
                ['signed_unpinned', 'site_signed', 'third_party_signed'],
                true
            ),
            // T6 §3.2: `{source, trust_root, principal, signed_at}` from the
            // verified certificate, or null. This is the fact `Site-certified`
            // is projected from — not the adapter's source, which says only
            // where the file came from and nothing about who vouched for it.
            'certification' => self::claimCertification($manifest, $certification),
        ];
    }

    /**
     * The claim's own certification block, or null (T6 §3.2).
     *
     * Read from the claim first — `certification: {source, trust_root,
     * principal, signed_at}` is what the agent binds when a site certificate
     * verifies, and it is the authority on who vouched. The catalog row's
     * `source.certification` WORD is the fallback identity, and only for the
     * two words that mean a site certificate verified: it lets the projection
     * still say `Site-certified` on an agent build that reports the word
     * without the block, rather than silently downgrading a genuinely signed
     * adapter to `Uncertified` because one field was absent.
     *
     * `trust_root` distinguishes WHOSE root signed (`site` for a key in the
     * repository's own adapters/authorities.json, `platform` for one in the
     * agent-owned file). `source` is `site` for both, because both are
     * certificates about a site adapter — which is why a signed override of a
     * shipped name reads `Site-certified` and never `Platform-certified`
     * (T6 §3.3).
     *
     * @param array<string,mixed> $manifest a `report()['manifests'][]` row
     * @return array<string,mixed>|null
     */
    private static function claimCertification(array $manifest, string $certification): ?array {
        $block = $manifest['certification'] ?? null;
        if (is_array($block) && !array_is_list($block) && is_string($block['source'] ?? null)) {
            return [
                'source' => (string) $block['source'],
                'trust_root' => is_string($block['trust_root'] ?? null) ? $block['trust_root'] : null,
                'principal' => is_string($block['principal'] ?? null) ? $block['principal'] : null,
                'signed_at' => is_string($block['signed_at'] ?? null) ? $block['signed_at'] : null,
            ];
        }
        if (!in_array($certification, ['site_signed', 'third_party_signed'], true)) {
            return null;
        }
        $source = is_array($manifest['source'] ?? null) ? $manifest['source'] : [];

        return [
            'source' => 'site',
            'trust_root' => is_string($source['trust_root'] ?? null)
                ? $source['trust_root']
                : ($certification === 'site_signed' ? 'site' : 'platform'),
            'principal' => is_string($source['principal'] ?? null) ? $source['principal'] : null,
            'signed_at' => null,
        ];
    }

    /**
     * Does this claim govern the section the selector names?
     *
     * @param array<string,mixed> $manifest
     */
    private static function sectionGoverned(array $manifest, string $selector): bool {
        $section = explode('.', $selector, 2)[0];

        return in_array($section, (array) ($manifest['surfaces'] ?? []), true);
    }

    /**
     * The claim's own `unsupported[].reason` for this surface and operation,
     * quoted verbatim (MUP §1.2 row 5, §1.3's Unsupported row). A null
     * `$operation` matches an entry scoped to any operation.
     *
     * A claim may state one boundary over several names with a `|`
     * alternation whose first alternative carries the section prefix. It is
     * expanded here rather than matched loosely, so a reason about one table
     * cannot silently be attributed to another.
     *
     * @param array<string,mixed> $manifest
     */
    private static function unsupportedReason(array $manifest, ?string $selector, ?string $operation): ?string {
        if ($selector === null) {
            return null;
        }
        // A null operation asks the wider question: is there ANY declared
        // boundary about this surface, whatever it is scoped to.
        $accepted = $operation === null
            ? null
            : array_merge(['all'], self::REGISTRY_OPERATION_INCLUDES[$operation] ?? [$operation]);
        foreach (($manifest['unsupported'] ?? []) as $row) {
            if (!is_array($row) || !is_string($row['surface'] ?? null)) {
                continue;
            }
            $rowOperation = (string) ($row['operation'] ?? '');
            if ($accepted !== null && !in_array($rowOperation, $accepted, true)) {
                continue;
            }
            if (in_array($selector, self::expandSelector($row['surface']), true)) {
                return (string) ($row['reason'] ?? '');
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function expandSelector(string $surface): array {
        if (!str_contains($surface, '|')) {
            return [$surface];
        }
        $parts = explode('|', $surface);
        $first = array_shift($parts);
        $prefix = str_contains($first, '.') ? substr($first, 0, (int) strrpos($first, '.') + 1) : '';
        $out = [$first];
        foreach ($parts as $part) {
            $out[] = str_contains($part, '.') ? $part : $prefix . $part;
        }

        return $out;
    }

    /**
     * The exact boundaries this row's readiness expires with (§3.3's
     * `expiry_and_dependencies`).
     *
     * Every entry is read out of the claim's own certified platform block,
     * so a widened range or a re-verified core version moves the printed
     * text without anything here being edited.
     *
     * @param array<string,mixed> $manifest
     * @return list<string>
     */
    private static function expiry(array $manifest): array {
        $out = [];
        $supported = is_array($manifest['supported_versions'] ?? null) ? $manifest['supported_versions'] : [];
        $plugin = $supported['plugin'] ?? null;
        if (is_string($plugin) && $plugin !== '' && $plugin !== 'unbound') {
            $out[] = $plugin . ' ' . self::range($supported['range'] ?? null);
        }
        $compatibility = $manifest['platform']['compatibility'] ?? [];
        if (is_array($compatibility)) {
            $verified = $compatibility['wordpress']['last_verified'] ?? null;
            if (is_string($verified) && $verified !== '') {
                $out[] = 'wordpress ' . $verified;
            }
            if (is_array($compatibility['php'] ?? null)) {
                $out[] = 'php ' . self::range($compatibility['php']);
            }
            $database = $compatibility['database'] ?? null;
            if (is_array($database) && is_string($database['engine'] ?? null)) {
                $out[] = $database['engine'] . ' ' . self::range($database);
            }
        }

        return $out;
    }

    /** @param mixed $range */
    private static function range($range): string {
        if (!is_array($range) || !is_string($range['min'] ?? null) || !is_string($range['max'] ?? null)) {
            return '(unbounded)';
        }

        return $range['min'] . '-' . $range['max'];
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>|null
     */
    private static function manifestRow(array $report, ?string $name): ?array {
        if ($name === null) {
            return null;
        }
        foreach (($report['manifests'] ?? []) as $row) {
            if (is_array($row) && ($row['name'] ?? null) === $name) {
                return $row;
            }
        }

        return null;
    }

    /** `post_type:product` -> `product`; a group id is `<kind>:<name>`. */
    private static function surfaceName(string $id): string {
        $position = strpos($id, ':');

        return $position === false ? $id : substr($id, $position + 1);
    }

    /**
     * @param array<string,mixed>|null $contract
     * @return array<string,string>
     */
    private static function contractLabels(?array $contract): array {
        $labels = $contract['declarations']['surface_labels'] ?? null;
        if (!is_array($labels)) {
            return [];
        }
        $out = [];
        foreach ($labels as $id => $label) {
            if (is_string($label) && $label !== '') {
                $out[(string) $id] = $label;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed>|null $contract
     * @return array<string,array<string,mixed>>
     */
    private static function contractSurfaces(?array $contract): array {
        $out = [];
        foreach (($contract['declarations']['surfaces'] ?? []) as $surface) {
            if (is_array($surface) && is_string($surface['id'] ?? null)) {
                $out[$surface['id']] = $surface;
            }
        }

        return $out;
    }

    /**
     * Surfaces the reviewed contract declares as carrying a live external
     * effect (§1.6). A declaration converts an unknown into a known,
     * bounded live effect; only a reviewed one counts, so an entry still
     * carrying the generated `unresolved` placeholder is ignored here — the
     * same rule `ApplicationContract::validate()` enforces at accept time.
     *
     * @param array<string,mixed>|null $contract
     * @return list<string>
     */
    private static function contractLiveSurfaces(?array $contract): array {
        $out = [];
        foreach (($contract['declarations']['external_effects'] ?? []) as $effect) {
            if (!is_array($effect) || ($effect['containment'] ?? null) !== 'live') {
                continue;
            }
            if (($effect['decided_by'] ?? null) === ApplicationContract::UNREVIEWED_DECIDED_BY) {
                continue;
            }
            foreach (($effect['surfaces'] ?? []) as $surface) {
                if (is_string($surface)) {
                    $out[] = $surface;
                }
            }
        }

        return array_values(array_unique($out));
    }

    private static function refuse(string $code, string $message): CommandRefusalException {
        return new CommandRefusalException(
            $code,
            $message,
            'rerun assess; if it persists, the target agent and this orchestrator disagree about the '
                . 'surface inventory and one of them is out of date'
        );
    }
}
