<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Adapter/Providers.php';
require_once __DIR__ . '/../Kernel/StructuredValue.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Repository/CanonicalSurfaces.php';
require_once __DIR__ . '/ApplyPlanner.php';
require_once __DIR__ . '/ApplyPlanBuilder.php';
require_once __DIR__ . '/IncompleteApplyMarker.php';
require_once __DIR__ . '/ApplyFieldMaterializer.php';
require_once __DIR__ . '/MenuMaterializer.php';
require_once __DIR__ . '/UserMetaMaterializer.php';
require_once __DIR__ . '/TermMaterializer.php';
require_once __DIR__ . '/OptionsMaterializer.php';
require_once __DIR__ . '/RelationshipMaterializer.php';
require_once __DIR__ . '/AttachmentMaterializer.php';
require_once __DIR__ . '/PostMaterializer.php';
require_once __DIR__ . '/../Delete/DeleteExecutor.php';
require_once __DIR__ . '/../Delete/DeleteGuardValueCodec.php';
require_once __DIR__ . '/../Delete/DeleteGuardEvaluator.php';
require_once __DIR__ . '/../Delete/DeleteGuardReferenceScanner.php';
require_once __DIR__ . '/../Review/ConvergenceVerifier.php';
require_once __DIR__ . '/../Review/PlanExplanation.php';
require_once __DIR__ . '/../Review/PlanCategorySummary.php';
require_once __DIR__ . '/../Review/PlanView.php';
require_once __DIR__ . '/../Adapter/ProviderActionBatchBuilder.php';
require_once __DIR__ . '/../Rebuild/RegenerationContext.php';
require_once __DIR__ . '/../Rebuild/RegenerationContextStore.php';
require_once __DIR__ . '/../Rebuild/DependencyRegenerator.php';
require_once __DIR__ . '/../Rebuild/NativeRebuildExecutor.php';
require_once __DIR__ . '/../Scope/ScopedApplyCoordinator.php';
require_once __DIR__ . '/../Rebuild/RebuildActionDispatcher.php';
require_once __DIR__ . '/ApplyLedgerFinalizer.php';
require_once __DIR__ . '/EntityAdopter.php';
require_once __DIR__ . '/AuthoredTransactionExecutor.php';
require_once __DIR__ . '/../Rebuild/RebuildActionNegotiator.php';
require_once __DIR__ . '/ApplyServices.php';
require_once __DIR__ . '/ApplyServiceCallbacks.php';
require_once __DIR__ . '/AuthoredTransactionRequest.php';
require_once __DIR__ . '/TaxonomyApplyContext.php';
require_once __DIR__ . '/../Rebuild/RebuildSelection.php';
require_once __DIR__ . '/ApplyPlanEnvironment.php';
require_once __DIR__ . '/../Scope/ScopedApplyWorkProjector.php';
require_once __DIR__ . '/ApplyRebuildCoordinator.php';
require_once __DIR__ . '/../Scope/ScopedApplyWorkflow.php';
require_once __DIR__ . '/ApplyPreparationCoordinator.php';
require_once __DIR__ . '/../Delete/DeleteGuardLockCoordinator.php';
require_once __DIR__ . '/ApplyPreparationRequest.php';
require_once __DIR__ . '/ApplyWorkset.php';
require_once __DIR__ . '/../Delete/DeletionAuthority.php';
require_once __DIR__ . '/../Rebuild/RebuildRequest.php';

/**
 * Plan + apply: repo state tree -> environment DB.
 *
 * Plan is a three-way comparison per entity: file (target), duo_state ledger
 * hash (base = last sync), env snapshot (actual). Apply is two-phase — insert
 * rows with placeholder refs, then resolve refs through the ledger — inside a
 * transaction, with the side-effect canary armed; the rebuild pass (recounts,
 * attachment metadata, cache flush) runs after the canary disarms.
 */
final class ApplyRequestCoordinator {
    private Policy $policy;
    private Tokens $tokens;
    private ApplyServices $services;
    private TaxonomyApplyContext $taxonomyContext;
    private RebuildSelection $rebuildSelection;
    private ApplyPlanEnvironment $planEnvironment;
    private ApplyRebuildCoordinator $rebuildCoordinator;
    private ScopedApplyWorkflow $scopedWorkflow;
    private ApplyPreparationCoordinator $preparationCoordinator;
    private DeleteGuardLockCoordinator $deleteGuardCoordinator;
    private CompiledRepository $compiled;
    private string $repo;
    /** @var string[] */
    private array $warnings = [];
    /** @var list<array<string,mixed>> reviewed public evidence for forced plan conflicts */
    private array $forcedOverrideEvidence = [];
    private ?int $defaultAuthor = null;
    private string $promotionOwner = '';
    private string $promotionArtifact = '';
    /** @var array<string,int>|null exact count-only context for the optional plan category projection */
    private ?array $categorySummaryContext = null;
    /** @var list<array<string,mixed>> structured rebuild-action receipts for this run's summary */
    private array $actionReceipts = [];
    /**
     * Whether this run found DUO-3206's apply_in_progress marker still set,
     * i.e. it is retrying an apply that committed something and then failed.
     * run() already reads that marker to widen the rebuild surface set with
     * already-absent tombstones; DUO-3369's `retry` batch channel hands the
     * same fact to a provider capability that declared it, so an adapter can
     * re-verify rather than assume the previous pass got that far.
     */
    private bool $retryingIncompleteApply = false;

    private function __construct(string $repo, Policy $policy, CompiledRepository $compiled) {
        $this->repo = rtrim($repo, '/');
        $this->policy = $policy;
        $this->compiled = $compiled;
        $callbacks = new ApplyServiceCallbacks(
            taxonomyOwnership: fn(): array => $this->taxonomyContext->ownership($this->warnings),
            renewPromotionLock: fn(string $phase): mixed => $this->renew_promotion_lock($phase),
            renewRegenerationLease: function (): void {
                if ($this->promotionOwner !== '' && $this->promotionArtifact !== '') {
                    $this->renew_promotion_lock('apply-rebuild-regen-batch');
                }
            },
            renewProviderLease: fn(): mixed => $this->renew_provider_lease(),
            lockDeleteGuards: fn(
                array $deleteWork,
                array $deleteUuids,
                array $deletions,
                array $tree,
                array $guardRepairUuids
            ): mixed => $this->deleteGuardCoordinator->lock_and_revalidate(
                $deleteWork,
                $deleteUuids,
                $deletions,
                $tree,
                $guardRepairUuids
            ),
            recheckDeleteGuard: fn(
                array $row,
                array $deleteUuids,
                array $deletions,
                bool $forced,
                array $tree,
                array $guardRepairUuids,
                bool $forUpdate
            ): mixed => $this->deleteGuardCoordinator->recheck(
                $row,
                $deleteUuids,
                $deletions,
                $forced,
                $tree,
                $guardRepairUuids,
                $forUpdate,
                $this->warnings
            ),
            selectionDeclaresChannelFor: fn(string $channel, string $surface): bool =>
                $this->rebuildSelection->declares_channel_for($channel, $surface),
            selectionDeclaresEntityBatchFor: fn(string $surface): bool =>
                $this->rebuildSelection->declares_entity_batch_for($surface),
            selectionTriggersProviderActionFor: fn(string $surface): bool =>
                $this->rebuildSelection->triggers_provider_action_for($surface),
            pinnedProviderActionOwns: fn(string $surface): bool =>
                $this->rebuildSelection->pinned_action_owns($surface),
            upsertMeta: fn(
                string $table,
                string $fkCol,
                int $objectId,
                string $key,
                ?string $value,
                ?string $context,
                string $idCol
            ): mixed => $this->services->field_materializer()->upsert_meta(
                $table, $fkCol, $objectId, $key, $value, $context, $idCol
            )
        );
        $this->services = new ApplyServices($policy, $compiled, $callbacks);
        $this->deleteGuardCoordinator = new DeleteGuardLockCoordinator(
            $policy,
            $this->services->delete_guard_reference_scanner(),
            $this->services->snapshot_row_tables()
        );
        $this->taxonomyContext = new TaxonomyApplyContext($policy, $compiled);
        $this->rebuildSelection = new RebuildSelection($policy);
        $this->planEnvironment = new ApplyPlanEnvironment($policy, $this->rebuildSelection);
        $this->rebuildCoordinator = new ApplyRebuildCoordinator($this->services, $this->rebuildSelection);
        $this->scopedWorkflow = new ScopedApplyWorkflow();
        $this->preparationCoordinator = new ApplyPreparationCoordinator(
            $this->repo,
            $this->policy,
            $this->services,
            $this->rebuildSelection,
            $this->scopedWorkflow,
            fn(array $options, CompiledRepository $source, bool $strict, bool $diagnose): array =>
                $this->build_plan($options, $source, $strict, $diagnose),
            fn(string $phase): mixed => $this->renew_promotion_lock($phase)
        );
        $this->tokens = $this->services->tokens();
    }

    private static function compiled(string $repo, Policy $policy, array $opts): CompiledRepository {
        $path = (string) ($opts['compiled'] ?? '');
        return $path !== ''
            ? RepositoryCompiler::read_artifact($path, $policy)
            : RepositoryCompiler::compile($repo, $policy);
    }

    // ------------------------------------------------------------------ plan

    public static function plan(string $repo, array $opts = []): array {
        $policy = Policy::load($repo);
        // Compile before constructing Tokens (which reads target options),
        // suppressing cron, or ensuring a ledger. A bad revision is a pure
        // offline result and is identical for fresh and mapped targets.
        $compiled = self::compiled($repo, $policy, $opts);
        Canary::suppress_cron_spawn();
        $a = new self($repo, $policy, $compiled);
        $scopeRequest = $opts['scope_request'] ?? null;
        $scoped = is_array($scopeRequest);
        // The SSH checkpoint profile has a read-only pre-claim projection
        // whose action diagnosis must match the receipt-bearing Apply path.
        // Ordinary and local scoped plans deliberately retain their historical
        // drift projection; only the explicit host profile opts in here.
        $scopedPromotion = $scoped && !empty($opts['scoped_promotion']);
        if ($scoped) {
            // A scoped plan is strict observation. It may not provision or
            // repair ledger identity as a side effect of asking what a future
            // bounded mutation would do.
            Ledger::assert_read_only_schema();
            $a->scopedWorkflow->scopeContract = ScopedApply::resolve_contract($scopeRequest, $compiled, $policy);
            if ($scopedPromotion) {
                ScopeContract::assert_mutation_supported($a->scopedWorkflow->scopeContract, 'scoped promote');
            }
            $activeScoped = ScopedApplySession::open(new LedgerScopedApplySessionStorage());
            if ($activeScoped !== null && !$activeScoped->is_terminal()) {
                $authority = $activeScoped->authority();
                if (!hash_equals((string) $authority['scope_hash'], (string) $a->scopedWorkflow->scopeContract['scope_hash'])
                    || !hash_equals((string) $authority['source']['artifact_hash'], $compiled->artifact_hash())) {
                    throw new \RuntimeException(
                        'duo: scoped plan refused — a different scoped mutation authority is nonterminal on this target'
                    );
                }
                $a->scopedWorkflow->session = $activeScoped;
            }
            $plan = ScopedApply::project_plan(
                $a->build_plan($opts, $compiled, true, false),
                $a->scopedWorkflow->scopeContract
            );
            $actual = Capture::snapshot_read_only(
                $repo,
                !empty($opts['force_unresolved_refs']),
                $compiled,
                $policy
            );
            $observation = ScopedApply::observe_target(
                $repo,
                $compiled,
                $policy,
                $a->scopedWorkflow->scopeContract,
                $actual,
                $a->scopedWorkflow->ledger_map_identity_hashes(),
                $a->scopedWorkflow->allows_target_old_menu_items($actual)
            );
            $work = $a->services->apply_planner()->rebuild_work(
                $plan,
                $compiled->tree(),
                $opts,
                false,
                $scopedPromotion
            );
            $surfaces = CanonicalSurfaces::for_apply(
                $work['work'],
                $compiled->tree(),
                $work['rebuild_delete_work'],
                $policy
            );
            $selectedActions = $policy->actions_for($surfaces);
            foreach ($selectedActions as $action) {
                if (!array_key_exists('triggers', $action)) {
                    throw new \RuntimeException(
                        'duo: scoped plan refused an untriggered global action; bounded execution requires explicit canonical triggers'
                    );
                }
            }
            // Report the exact scoped contract apply will gate on. This is
            // still read-only negotiation: provider identity/capabilities may
            // be inspected, but neither invoke_scoped() nor reconcile_scoped()
            // is called from plan.
            $diagnosis = Providers::negotiate_scoped($policy, $selectedActions);
            $plan['format'] = ScopedApply::PLAN_FORMAT;
            // ScopedRollbackProfile binds its checkpoint/recovery authority to
            // the exact compiled artifact and adapter-resolution set. Keep
            // both immutable witnesses in the public plan before the target
            // can negotiate or claim the scoped handoff.
            $plan['artifact_hash'] = $compiled->artifact_hash();
            $plan['resolved_adapters'] = $compiled->resolved_adapters();
            $plan['scope'] = [
                'format' => ScopeContract::FORMAT,
                'scope_hash' => (string) $a->scopedWorkflow->scopeContract['scope_hash'],
                'source_artifact_hash' => $compiled->artifact_hash(),
                'selected_identities' => count(ScopedStateOverlay::selected_identities($a->scopedWorkflow->scopeContract)),
            ];
            $plan['target'] = array_intersect_key($observation, array_fill_keys([
                'selected_before_root', 'protected_out_of_scope_root',
                'ledger_map_root', 'protected_ledger_map_root', 'selected_ledger_map_root',
                'target_observation_hash',
            ], true));
            $plan['selected_surfaces'] = $surfaces;
            $plan['selected_actions'] = array_map(
                static fn(array $action): array => [
                    'manifest' => (string) ($action['manifest'] ?? ''),
                    'index' => (int) ($action['index'] ?? 0),
                    'declaration_hash' => hash('sha256', Canon::encode($action)),
                ],
                $selectedActions
            );
            $plan['provider_problems'] = $diagnosis['problems'];
            if ($a->scopedWorkflow->session !== null) {
                $plan['scoped_recovery'] = [
                    'authority_hash' => $a->scopedWorkflow->session->authority_hash_value(),
                    'phase' => $a->scopedWorkflow->session->phase(),
                    'session_id' => $a->scopedWorkflow->session->session_id(),
                ];
            }
            $plan['warnings'] = $a->warnings;
            return $plan;
        }
        Ledger::ensure();
        $activeScoped = ScopedApplySession::open(new LedgerScopedApplySessionStorage());
        if ($activeScoped !== null && !$activeScoped->is_terminal()) {
            throw new \RuntimeException(
                'duo: full plan refused — a scoped apply session is nonterminal; recover that exact scoped authority first'
            );
        }
        Snapshot::repair_truncated_entity_types($policy); // DUO-3246
        $plan = $a->build_plan($opts, $compiled);
        if (Ledger::kv_get('apply_in_progress') !== null) {
            $a->warnings[] = 'previous apply did not complete required rebuilds; canonical entities require retry';
        }
        // Only the plan-only entry point attaches warnings to the returned
        // array itself — run() below calls build_plan() too, but folds
        // $this->warnings into ITS OWN summary separately (see run()'s
        // return), so this must not become a build_plan() return-shape
        // change or apply's 'plan' => array_map('count', $plan) count block
        // would grow a spurious 'warnings' => N entry.
        $plan['warnings'] = $a->warnings;
        // DUO-3339, closing spec/repo-format.md's bound (4) ("negotiation
        // currently runs at apply only — plan/status do not yet surface
        // missing/incompatible providers"). Attached HERE, on the plan-only
        // entry point, for the same reason `warnings` is: it is a report
        // bucket, not a precondition.
        //
        // Deliberately not inside build_plan(): run() calls build_plan() twice
        // around its own negotiation gate, so putting the diagnosis there
        // would construct every declared provider three times per apply and
        // move the first construction to before the promotion lease — a real
        // change to the apply path, bought for a report apply does not read.
        // apply's refusal stays exactly where the doctrine puts it, at the
        // negotiation immediately before the first mutation.
        //
        // The plan's own adapter_dispositions are handed over so the two
        // provider diagnoses do not report one fact twice: build_plan() has
        // already merged DUO-3314's NARROWED, gating rows
        // (Policy::provider_readiness_blockers($selectedActions)) into that
        // bucket, and what belongs here is only the remainder this revision's
        // work never reaches.
        $plan['provider_problems'] = Providers::problems($policy, $plan['adapter_dispositions'] ?? []);
        // DUO-3345 slice 5: additive, value-free category projection. Keep
        // every detailed bucket above unchanged. Counts are derived from
        // those rows plus the compiled identity/action provenance and the
        // same-snapshot nested-deletion observations saved by build_plan().
        // Machine consumers may ignore it when talking to an older agent.
        $categorySummary = $a->categorySummaryContext === null ? null : PlanCategorySummary::build(
            $plan,
            $compiled->tree(),
            $compiled->deletions(),
            $a->categorySummaryContext
        );
        if ($categorySummary !== null) {
            $plan['category_summary'] = $categorySummary;
        }
        // DUO-3345 slice 6: an explicit filter asks for a bounded
        // observation-only index from this SAME full plan snapshot.  It is
        // intentionally attached only by the plan entry point; apply/run and
        // their mutation/readiness authority do not consume or emit it.
        if (isset($opts['plan_view'])) {
            $request = $opts['plan_view'];
            if (!is_array($request)) {
                throw new CommandRefusalException(
                    'plan_view_unavailable',
                    'the requested plan view is unavailable for this plan',
                    'rerun the complete plan without view filters or repair the plan identity/provenance inconsistency before retrying',
                    [],
                    'duo: requested plan view unavailable'
                );
            }
            // A category request promises both row facets and the matching
            // full-plan category counts. If compiled/same-snapshot context
            // was insufficient for the optional summary, do not pretend a
            // category-only filter is meaningful; action/entity/limit views
            // remain available without that optional display projection.
            if (($request['category'] ?? []) !== [] && $categorySummary === null) {
                throw new CommandRefusalException(
                    'plan_view_unavailable',
                    'the requested plan view is unavailable for this plan',
                    'rerun the complete plan without category filters or repair the plan identity/provenance inconsistency before retrying',
                    [],
                    'duo: category-filtered plan view unavailable'
                );
            }
            $plan['plan_view'] = PlanView::build(
                $plan,
                $compiled->tree(),
                $compiled->deletions(),
                $request
            );
        }
        return $plan;
    }

    private function build_plan(
        array $opts,
        CompiledRepository $compiled,
        bool $strictObservation = false,
        bool $diagnoseAdapters = true
    ): array {
        $builder = new ApplyPlanBuilder(
            $this->repo,
            $this->policy,
            $this->services->apply_planner(),
            $this->services->delete_guard_reference_scanner(),
            $this->services->snapshot_row_tables(),
            $this->scopedWorkflow->scopeContract,
            fn(): array => $this->planEnvironment->regeneration_debt_projection(),
            fn(): array => $this->planEnvironment->env_missing_projection(),
            $this->warnings
        );
        $result = $builder->build($opts, $compiled, $strictObservation, $diagnoseAdapters);
        $this->warnings = $result['warnings'];
        $this->rebuildSelection->set_selected_actions($result['selected_actions']);
        $this->categorySummaryContext = $result['category_summary_context'];
        return $result['plan'];
    }

    /**
     * Explain one current entity plan row without inheriting plan's repair
     * authority. The selector is validated before repository/target reads;
     * the strict snapshot path then refuses stale ledger or identity state
     * rather than repairing it. Provider declarations are projected from the
     * pinned policy but never negotiated or invoked here.
     *
     * @return array<string,mixed>
     */
    public static function explain(string $repo, string $selector, array $opts = []): array {
        PlanExplanation::parse($selector);
        $policy = Policy::load($repo);
        $compiled = self::compiled($repo, $policy, $opts);
        Canary::suppress_cron_spawn();
        $apply = new self($repo, $policy, $compiled);
        try {
            $plan = $apply->build_plan($opts, $compiled, true, false);
        } catch (\Throwable $failure) {
            if (str_contains($failure->getMessage(), 'refresh export refused')) {
                throw CommandRefusalException::explainObservationPrecondition($failure);
            }
            throw $failure;
        }
        $selected = PlanExplanation::select($plan, $selector);
        $bucket = $selected['bucket'];
        $row = $selected['row'];
        $key = $selected['key'];
        $surfaces = [];
        // Conflicts/drift/collisions are explanations of a blocked choice,
        // and unchanged/already-deleted rows are no-ops. Only rows that the
        // default plan contributes to the mutation/rebuild selection project
        // surfaces here. A delete remains explicitly --with-deletes gated;
        // its declarations are still trigger-matched before that authority.
        if (in_array($bucket, ['create', 'update', 'adopt'], true)) {
            $entity = $compiled->tree()[$key] ?? null;
            if (is_array($entity)) {
                $surfaces = CanonicalSurfaces::for_entity($entity, $row, $policy);
            }
        } elseif ($bucket === 'delete') {
            $surfaces = CanonicalSurfaces::for_deletion($row);
        }
        $surfaces = array_values(array_unique(array_map('strval', $surfaces)));
        sort($surfaces, SORT_STRING);
        $actions = $policy->actions_for($surfaces);
        $adoptBySlug = array_values(array_filter(array_map(
            'strval',
            explode(',', (string) ($opts['adopt_by_slug'] ?? ''))
        ), static fn(string $kind): bool => in_array($kind, ['posts', 'terms', 'menus', 'tables'], true)));
        sort($adoptBySlug, SORT_STRING);
        return PlanExplanation::build(
            $selector,
            $bucket,
            $row,
            $compiled,
            $policy,
            $surfaces,
            $actions,
            [
                'adopt_by_slug' => $adoptBySlug,
                'force_unresolved_refs' => !empty($opts['force_unresolved_refs']),
                'compiled_artifact_provided' => (string) ($opts['compiled'] ?? '') !== '',
            ]
        );
    }

    /**
     * Write a single manifest-declared `class: "env"` option value directly
     * into wp_options — `wp duo env-set`'s implementation. Deliberately NOT
     * part of the ordinary authored capture/apply pipeline: env values are
     * never captured (Policy::env_options()'s own docblock), so there is no
     * canonical record to reconcile against here, no ledger/token rewriting
     * involved, and no plan/apply transaction wrapping it — this is a
     * direct, human-operator-initiated write, closer in shape (and
     * precedent) to Deploy.php's own standalone
     * update_option('active_plugins', ...) call than to this class's own
     * batch upsert_option()/Db:: pipeline (which exists for a transactional,
     * many-row, retry-classified apply — a genuinely different problem than
     * one operator setting one value once).
     *
     * Refuses, loudly, before writing anything:
     *   - a name not declared `class: "env"` anywhere in the loaded policy
     *     (Policy::env_options()) — env-set can never create an arbitrary
     *     option out of thin air, only provision one the manifest already
     *     named.
     *   - a name whose rule declares `sub_keys` — those are whole-option env
     *     blobs (Yoast's `wpseo`, Polylang's `polylang`) that mix a
     *     serialized ARRAY with named authored carve-outs; env-set only
     *     ever writes a plain scalar string, and overwriting a structured
     *     blob with one would corrupt every sub-key, including the
     *     authored carve-outs capture/apply already round-trip correctly.
     *     Every sub_keys-bearing env option shipped today is required:false
     *     (self-populated by its owning plugin) precisely because it was
     *     never meant to be hand-provisioned this way.
     *   - an empty string — build_plan()'s env_missing bucket (above)
     *     treats an empty value as equivalent to absent, so accepting one
     *     here would let env-set report success while `duo plan`
     *     immediately calls the same option still missing.
     *
     * Autoload is resolved the same way Policy::env_options() resolves
     * every other option rule (manifest/site option_autoload default,
     * with_option_autoload()) — but 'preserve' means something different
     * here than it does for a captured, authored value: there is no
     * captured source row to preserve FROM, only whatever is already live
     * on THIS target. update_option()'s own native $autoload=null contract
     * already means exactly that (keep the existing row's autoload if
     * updating; apply WordPress's own 6.6+ 'auto' heuristic if inserting
     * fresh), so 'preserve'/unset both map to null here rather than Duo
     * re-inventing that decision.
     *
     * update_option()'s own return value cannot distinguish "write failed"
     * from "the value was already exactly this" — both return false. Rather
     * than guess which, this re-reads the live value after the call and
     * compares it to what was intended: the only postcondition that
     * actually matters is "the option now holds $value", regardless of
     * which internal WordPress branch produced it.
     *
     * @return array{name:string, previously_set:bool}
     */
    public static function set_env_option(string $repo, string $name, string $value): array {
        $policy = Policy::load($repo);
        $envOptions = $policy->env_options();
        if (!isset($envOptions[$name])) {
            throw new \RuntimeException(
                "duo: env-set: '$name' is not declared class=\"env\" in any loaded manifest or "
                . 'site.duo.json — env-set only provisions a value the policy already named (see '
                . '`wp duo plan` for the current env_missing checklist)'
            );
        }
        $rule = $envOptions[$name];
        if (!empty($rule['sub_keys'])) {
            throw new \RuntimeException(
                "duo: env-set: '$name' declares sub_keys — it is a structured, plugin-managed option "
                . 'blob, not a plain scalar value env-set can safely overwrite (the plugin populates it '
                . "itself; see this manifest's own notes for '$name')"
            );
        }
        if ($value === '') {
            throw new \RuntimeException(
                "duo: env-set: refusing to set '$name' to an empty string — that would still read as "
                . "env_missing on the next 'duo plan' (missing means absent OR empty), so it can never "
                . 'satisfy provisioning'
            );
        }

        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name
        ));
        $previouslySet = $existing !== null && $existing !== '';

        $autoload = $rule['autoload'] ?? null;
        if ($autoload === 'preserve') {
            $autoload = null;
        }
        update_option($name, $value, $autoload);

        $confirm = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name
        ));
        if ($confirm !== $value) {
            throw new \RuntimeException("duo: env-set: wrote '$name' but the stored value does not match afterward");
        }

        return ['name' => $name, 'previously_set' => $previouslySet];
    }

    // ----------------------------------------------------------------- apply

    public static function apply(string $repo, array $opts = []): array {
        $policy = Policy::load($repo);
        $compiled = self::compiled($repo, $policy, $opts);
        Canary::suppress_cron_spawn();
        $scopeRequest = $opts['scope_request'] ?? null;
        $scoped = is_array($scopeRequest);
        $scopedPromotionWitness = self::assert_scoped_promotion_request($opts, $scoped);
        $allowDeletes = !empty($opts['with_deletes']);
        if ($scoped) {
            Ledger::assert_read_only_schema();
            // Recompute before target mutation so malformed/stale evidence
            // cannot create a lease or durable scoped session.
            try {
                $preflightContract = ScopedApply::resolve_contract($scopeRequest, $compiled, $policy);
                if ($scopedPromotionWitness !== null) {
                    ScopeContract::assert_mutation_supported($preflightContract, 'scoped promote');
                }
            } catch (\Throwable $failure) {
                throw CommandRefusalException::applyRefused(
                    'scoped apply refused because its scope evidence is stale or invalid for the current source artifact',
                    'rebuild the scope contract and scoped plan from the current source artifact, then retry apply',
                    'duo: scoped apply source evidence is stale or invalid; rebuild the scope contract and plan before retrying',
                    $failure
                );
            }
        } else {
            Ledger::ensure();
            $preflightContract = null;
        }
        $sessionStorage = new LedgerScopedApplySessionStorage();
        $existingScopedSession = ScopedApplySession::open($sessionStorage);
        $terminalScopedSessionToArchive = null;
        if (!$scoped && $existingScopedSession !== null && !$existingScopedSession->is_terminal()) {
            throw new \RuntimeException(
                'duo: full apply refused — a scoped apply session is nonterminal; recover that exact scoped authority first'
            );
        }
        $terminalReplaySession = null;
        if ($scoped && ($existingScopedSession === null || $existingScopedSession->is_terminal())) {
            if ($existingScopedSession !== null) {
                $authority = $existingScopedSession->authority();
                if (hash_equals((string) ($authority['scope_hash'] ?? ''), (string) $preflightContract['scope_hash'])
                    && hash_equals((string) ($authority['source']['artifact_hash'] ?? ''), $compiled->artifact_hash())) {
                    if ($scopedPromotionWitness === null) {
                        $terminalReplaySession = $existingScopedSession;
                    } else {
                        try {
                            ScopedApplySession::assert_external_promotion(
                                $authority,
                                $scopedPromotionWitness,
                                $allowDeletes
                            );
                            if (($scopedPromotionWitness['state'] ?? null) === 'promoting'
                                && !hash_equals(
                                    (string) ($authority['lease']['session_id'] ?? ''),
                                    PromotionLock::session_id(
                                        (string) $opts['promotion_owner'],
                                        $compiled->artifact_hash()
                                    )
                                )) {
                                throw CommandRefusalException::applyRefused(
                                    'the promoting scoped terminal belongs to a different target handoff generation',
                                    'retain the exclusion and recover the exact original ps-* target handoff before retrying',
                                    'duo: external scoped terminal lease generation changed'
                                );
                            }
                            $terminalReplaySession = $existingScopedSession;
                        } catch (CommandRefusalException $refusal) {
                            throw $refusal;
                        } catch (\Throwable $_differentExternalGeneration) {
                            $archivedReceipt = (string) ($authority['promotion']['receipt_payload_sha256'] ?? '');
                            $currentReceipt = (string) ($scopedPromotionWitness['receipt_payload_sha256'] ?? '');
                            $archivedGeneration = (int) ($authority['promotion']['generation'] ?? 0);
                            $currentGeneration = (int) ($scopedPromotionWitness['generation'] ?? 0);
                            if (($authority['format'] ?? null) !== ScopedApplySession::EXTERNAL_AUTHORITY_FORMAT
                                || ($archivedReceipt !== '' && hash_equals($archivedReceipt, $currentReceipt))
                                || $archivedGeneration < 1
                                || $currentGeneration < 1
                                || $currentGeneration <= $archivedGeneration) {
                                throw CommandRefusalException::applyRefused(
                                    'the prior scoped terminal lacks the exact current external generation binding',
                                    'retain the exclusion and reconcile the legacy or corrupted terminal authority before retrying',
                                    'duo: external scoped terminal generation binding mismatch'
                                );
                            }
                            // A prior terminal for this scope/artifact is not
                            // replay authority for a newer signed generation.
                            // The promoting path archives it only after all
                            // current preflight gates pass; committed remains
                            // fail-closed below when no exact archive exists.
                        }
                    }
                }
            }
            if ($terminalReplaySession === null) {
                $promotionBindingHash = $scopedPromotionWitness === null
                    ? null
                    : ScopedApplySession::external_promotion_binding_hash(
                        $scopedPromotionWitness,
                        $allowDeletes
                    );
                $terminalReplaySession = ScopedApplySession::open_terminal_for_request(
                    $sessionStorage,
                    (string) $preflightContract['scope_hash'],
                    $compiled->artifact_hash(),
                    $promotionBindingHash
                );
            }
            if ($terminalReplaySession !== null) {
                $authority = $terminalReplaySession->authority();
                if ($scopedPromotionWitness !== null) {
                    ScopedApplySession::assert_external_promotion(
                        $authority,
                        $scopedPromotionWitness,
                        $allowDeletes
                    );
                    if (($scopedPromotionWitness['state'] ?? null) === 'promoting'
                        && !hash_equals(
                        (string) ($authority['lease']['session_id'] ?? ''),
                        PromotionLock::session_id(
                            (string) $opts['promotion_owner'],
                            $compiled->artifact_hash()
                        )
                    )) {
                        throw new \RuntimeException(
                            'duo: scoped terminal authority does not match the current target handoff generation'
                        );
                    }
                }
                // A terminal receipt is an idempotent lost-response result,
                // not a timeless claim about a target that may since have
                // drifted. Re-prove the bounded authored/map witnesses before
                // returning its exact bytes; this path remains observation-
                // only and never replays authored or opaque effect work.
                $actual = Capture::snapshot_read_only(
                    $repo,
                    !empty($opts['force_unresolved_refs']),
                    $compiled,
                    $policy
                );
                $observation = ScopedApply::observe_target(
                    $repo,
                    $compiled,
                    $policy,
                    $preflightContract,
                    $actual,
                    (array) $authority['selection']['ledger_map_identity_hashes'],
                    false
                );
                $terminalReceipt = $terminalReplaySession->terminal_receipt();
                if (!ScopedApply::terminal_replay_matches(
                    $actual,
                    $compiled,
                    $policy,
                    $preflightContract,
                    $authority,
                    (array) $terminalReceipt,
                    $observation
                )) {
                    throw CommandRefusalException::applyRefused(
                        'terminal scoped receipt no longer describes the live bounded target; no mutation or replay attempted',
                        'inspect the changed selected/protected target state and reconcile it before retrying this scoped authority',
                        'duo: terminal scoped receipt no longer describes the live bounded target; no mutation or replay attempted'
                    );
                }
                return self::scoped_terminal_summary($terminalReplaySession);
            }
        }
        if (($scopedPromotionWitness['state'] ?? null) === 'committed') {
            throw CommandRefusalException::applyRefused(
                'the external scoped promotion is already committed but no exact target terminal receipt can be replayed',
                'retain the exclusion and reconcile the missing target terminal archive before retrying completion',
                'duo: committed scoped promotion has no replayable target terminal receipt'
            );
        }
        if ($scoped && $existingScopedSession !== null && $existingScopedSession->is_terminal()) {
            // A completed session may be rotated only by the protocol's exact
            // terminal-archive operation. Never overwrite it as though it were
            // an abandoned progress marker.
            if (!method_exists($existingScopedSession, 'archive_terminal')) {
                throw new \RuntimeException(
                    'duo: a prior scoped apply terminal receipt must be archived before starting a different scoped authority'
                );
            }
            $terminalScopedSessionToArchive = $existingScopedSession;
            $existingScopedSession = null;
        }
        $recoveringScopedSession = $scoped
            && $existingScopedSession !== null
            && !$existingScopedSession->is_terminal();
        if ($recoveringScopedSession && $scopedPromotionWitness === null) {
            // A crash-recovery call may omit promotion_owner because the
            // scoped apply session seals its lease identity. That must not let
            // an externally checkpointed profile shed its receipt/witness and
            // enter the generic ScopedApplySession recovery path.
            PromotionLock::assert_no_unbound_scoped_continuation();
        }
        $promotionOwner = $recoveringScopedSession
            ? (string) $existingScopedSession->lease()['owner']
            : PromotionLock::owner($opts);
        $promotionArtifact = $compiled->artifact_hash();
        $continuation = !$recoveringScopedSession && (string) ($opts['promotion_owner'] ?? '') !== '';
        self::assert_expected_artifact($promotionArtifact, $opts, $continuation);
        if ($scoped && $continuation) {
            // A pre-session-id full promotion remains recoverable through its
            // legacy generation, but scoped authority cannot safely seal one:
            // its exact crash-recovery path accepts only random ps-* leases.
            // Refuse before acquire() renews the row or any target mutation
            // authority can be recorded.
            PromotionLock::scoped_session_id($promotionOwner, $promotionArtifact);
        }
        if ($recoveringScopedSession) {
            $existingScopedSession->assert_lease(
                $promotionOwner,
                $promotionArtifact,
                (string) $existingScopedSession->lease()['session_id']
            );
            PromotionLock::recover_session(
                $promotionOwner,
                $promotionArtifact,
                (string) $existingScopedSession->lease()['session_id']
            );
        } elseif ($continuation) {
            PromotionLock::acquire($promotionOwner, $promotionArtifact, 'apply', null, true);
        } else {
            // A direct apply still takes the target-authoritative lease before
            // planning, but it must not overwrite durable session evidence
            // merely to report a pre-mutation refusal. run() publishes the
            // session after provider negotiation and the locked recheck.
            PromotionLock::acquire_apply_preflight($promotionOwner, $promotionArtifact);
        }
        $a = null;
        try {
            // The artifact was first validated before target contact. Repeat
            // that association under the lease so a concurrent checkout or
            // manifest/site-policy edit cannot alter the meaning between
            // preflight and mutation.
            $lockedPolicy = Policy::load($repo);
            $lockedCompiled = self::compiled($repo, $lockedPolicy, $opts);
            if (!hash_equals($promotionArtifact, $lockedCompiled->artifact_hash())) {
                throw new \RuntimeException('duo: compiled artifact changed before locked apply');
            }
            if ($continuation) {
                PromotionLock::assert_no_lifecycle_attempt(
                    $promotionOwner,
                    $promotionArtifact,
                    'apply'
                );
            }
            $a = new self($repo, $lockedPolicy, $lockedCompiled);
            $a->promotionOwner = $promotionOwner;
            $a->promotionArtifact = $promotionArtifact;
            $a->scopedWorkflow->session = $recoveringScopedSession ? $existingScopedSession : null;
            $a->scopedWorkflow->promotionWitness = $scopedPromotionWitness;
            $a->scopedWorkflow->terminalSessionToArchive = $terminalScopedSessionToArchive;
            if ($scoped) {
                $a->scopedWorkflow->scopeContract = ScopedApply::resolve_contract(
                    $scopeRequest,
                    $lockedCompiled,
                    $lockedPolicy
                );
            } else {
                Snapshot::repair_truncated_entity_types($lockedPolicy); // DUO-3246
            }
            PromotionLock::heartbeat($promotionOwner, $promotionArtifact, 'artifact-validated');
            if (getenv('DUO_TEST_MODE') === '1') {
                $pauseMs = (int) (getenv('DUO_TEST_PROMOTION_LOCKED_PAUSE_MS') ?: 0);
                if ($pauseMs > 0 && $pauseMs <= 10000) {
                    usleep($pauseMs * 1000);
                }
            }
            if ($scopedPromotionWitness !== null) {
                $scopeHash = (string) ($scopeRequest['scope_hash'] ?? '');
                $scopedPromotionWitness = ScopedPromotionAuthority::require_installed(
                    $promotionOwner,
                    $promotionArtifact,
                    (string) $opts['scoped_promotion_receipt'],
                    $scopeHash,
                    ['promoting'],
                    $allowDeletes
                );
                PromotionLock::assert_scoped_promotion_session(
                    $promotionOwner,
                    $promotionArtifact,
                    (string) $opts['scoped_promotion_receipt'],
                    $scopeHash,
                    $scopedPromotionWitness
                );
            }
            $summary = $a->run($opts, $lockedCompiled);
            PromotionLock::heartbeat($promotionOwner, $promotionArtifact, 'complete');
            PromotionLock::release($promotionOwner, $promotionArtifact);
            $summary['promotion_lock'] = ['owner' => $promotionOwner, 'released' => true];
            return $summary;
        } catch (\Throwable $t) {
            try {
                // Preserve DUO-3253's independent-connection cleanup when a
                // failed rollback leaves the global wpdb transaction open,
                // while using the always-initialized exact continuation
                // identity from this invocation.
                PromotionLock::release_after_failure($promotionOwner, $promotionArtifact);
            } catch (\Throwable $_releaseFailure) {
                // The original failure is the actionable cause. A lost lease
                // is already fail-closed and expires without human cleanup.
            }
            throw self::failure_with_forced_warnings($t, $a);
        }
    }

    /** Exact lost-response replay for a terminal scoped session. */
    private static function scoped_terminal_summary(ScopedApplySession $session): array {
        $terminal = $session->terminal_receipt();
        if (!is_array($terminal)) {
            throw new \RuntimeException('duo: terminal scoped apply session has no valid terminal receipt');
        }
        return [
            'format' => 'duo-scoped-apply-result/v1',
            'replayed' => true,
            'applied' => 0,
            'plan' => [],
            'drift' => [],
            'warnings' => [],
            'actions' => [],
            'canary' => 'clean',
            'verification' => null,
            'scoped_receipt' => $terminal,
        ];
    }

    /**
     * Report-not-hide applies to every explicit force gate, including when a
     * later gate, mutation, rebuild, or convergence check prevents the normal
     * JSON summary. Force warnings describe authorization/intent, not a claim
     * that the target mutation committed, so they remain truthful even when a
     * pre-mutation safety gate refuses. Without a force warning the original
     * throwable object and type pass through unchanged.
     */
    private static function failure_with_forced_warnings(\Throwable $failure, ?self $apply): \Throwable {
        $forcedWarnings = $apply === null ? [] : array_values(array_filter(
            $apply->warnings,
            fn(string $warning): bool => str_starts_with($warning, 'FORCED ')
        ));
        if (!$forcedWarnings) {
            return $failure;
        }
        $operatorMessage = implode(
            "\n",
            array_map(fn(string $w): string => 'Warning: ' . $w, $forcedWarnings)
        ) . "\n" . $failure->getMessage();
        if ($failure instanceof CommandRefusalException
            && $failure->reasonCode === 'apply_conflict_override_incomplete') {
            $wrapped = new CommandRefusalException(
                $failure->reasonCode,
                $failure->publicMessage,
                $failure->remediation,
                $failure->diagnostics,
                $operatorMessage,
                $failure,
                $failure->forcedOverrides
            );
            $wrapped->detailsRedacted = $failure->detailsRedacted || $wrapped->detailsRedacted;
            return $wrapped;
        }
        if ($apply !== null && $apply->forcedOverrideEvidence !== []) {
            return new CommandRefusalException(
                'apply_forced_override_failed',
                'apply failed after explicit plan conflict overrides were authorized',
                'inspect private operator evidence and apply recovery state; reconcile the failed gate before another attempt and do not assume the authorized override committed',
                [],
                $operatorMessage,
                $failure,
                $apply->forcedOverrideEvidence
            );
        }
        return new \RuntimeException($operatorMessage, 0, $failure);
    }

    /**
     * Fresh-process side of DUO-3220's convergence gate. The mutating apply
     * process launches this through WP_CLI::runcommand(); keeping canonical
     * recapture in a newly-booted WordPress runtime matters because plugins
     * may retain pre-apply models and persist them from shutdown callbacks.
     */
    public static function verify_canonical(string $repo, array $opts = []): array {
        $expectedArtifact = (string) ($opts['expected_artifact'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $expectedArtifact)) {
            throw new \RuntimeException(
                'duo: canonical verification requires the parent apply expected artifact sha256'
            );
        }
        $policySnapshot = (string) ($opts['policy_snapshot'] ?? '');
        $compiledPath = (string) ($opts['compiled'] ?? '');
        if ($policySnapshot === '' || $compiledPath === '') {
            throw new \RuntimeException(
                'duo: canonical verification requires the parent apply frozen policy snapshot and compiled artifact'
            );
        }
        try {
            $decodedPolicy = Canon::decode(Canon::read_file($policySnapshot));
            if (!is_array($decodedPolicy)) {
                throw new \RuntimeException('snapshot root is not an object');
            }
            $policy = Policy::from_snapshot($decodedPolicy);
        } catch (\Throwable $t) {
            throw new \RuntimeException('duo: canonical verification frozen policy is invalid: ' . $t->getMessage(), 0, $t);
        }
        $compiled = RepositoryCompiler::read_artifact($compiledPath, $policy);
        if (!hash_equals($expectedArtifact, $compiled->artifact_hash())) {
            throw new \RuntimeException(
                'duo: post-apply convergence verification refused a different compiled artifact'
            );
        }
        Canary::suppress_cron_spawn();
        $scopeRequest = $opts['scope_request'] ?? null;
        if (is_array($scopeRequest)) {
            Ledger::assert_read_only_schema();
            $scopeContract = ScopedApply::resolve_contract($scopeRequest, $compiled, $policy);
            return (new ConvergenceVerifier($repo, $policy, $scopeContract))->verify_scoped_local(
                $compiled,
                !empty($opts['force_unresolved_refs']),
                (string) ($opts['expected_protected_root'] ?? ''),
                (string) ($opts['expected_protected_map_root'] ?? ''),
                (string) ($opts['expected_authority_hash'] ?? ''),
                (string) ($opts['expected_effects_root'] ?? '')
            );
        }
        Ledger::ensure();
        Snapshot::repair_truncated_entity_types($policy);
        return (new ConvergenceVerifier($repo, $policy))->verify_local(
            $compiled,
            $compiled->tree(),
            $compiled->deletions(),
            !empty($opts['with_deletes']),
            !empty($opts['force_unresolved_refs'])
        );
    }

    private function run(array $opts, CompiledRepository $compiled): array {
        global $wpdb;
        $tree = $compiled->tree();
        $this->services->shortcode_alternate_registrar()->register($tree);
        $scoped = $this->scopedWorkflow->scopeContract !== null;
        $scopedPromotion = (string) ($opts['scoped_promotion_receipt'] ?? '') !== '';
        $recoveringScoped = $scoped && $this->scopedWorkflow->session !== null;
        $retryingIncompleteApply = Ledger::kv_get('apply_in_progress') !== null;
        if ($scoped && $retryingIncompleteApply) {
            throw new \RuntimeException(
                'duo: scoped apply refused — a full apply recovery marker is active; complete or recover that exact full apply first'
            );
        }
        $this->retryingIncompleteApply = $retryingIncompleteApply;
        $plan = $this->build_plan($opts, $compiled, $scoped, !$scoped);
        if ($scoped) {
            $plan = ScopedApply::project_plan($plan, $this->scopedWorkflow->scopeContract);
            if ($plan['incomplete_lifecycle'] ?? []) {
                throw new \RuntimeException(
                    'duo: scoped apply refused — unresolved lifecycle recovery is active; scoped state authority cannot consume it'
                );
            }
            if (($plan['regen_pending'] ?? []) !== [] || ($plan['regen_context'] ?? []) !== []) {
                throw new \RuntimeException(
                    'duo: scoped apply refused — global derived-state recovery debt exists; recover it through the original full apply before bounded mutation'
                );
            }
            $actual = Capture::snapshot_read_only(
                $this->repo,
                !empty($opts['force_unresolved_refs']),
                $compiled,
                $this->policy
            );
            $this->scopedWorkflow->observation = ScopedApply::observe_target(
                $this->repo,
                $compiled,
                $this->policy,
                $this->scopedWorkflow->scopeContract,
                $actual,
                $this->scopedWorkflow->ledger_map_identity_hashes(),
                $this->scopedWorkflow->allows_target_old_menu_items($actual)
            );
        }

        $prepared = $this->preparationCoordinator->prepare(
            new ApplyPreparationRequest(
                options: $opts,
                compiled: $compiled,
                plan: $plan,
                tree: $tree,
                scoped: $scoped,
                scopedPromotion: $scopedPromotion,
                recoveringScoped: $recoveringScoped,
                retryingIncompleteApply: $retryingIncompleteApply,
                promotionOwner: $this->promotionOwner,
                promotionArtifact: $this->promotionArtifact
            ),
            $this->warnings,
            $this->forcedOverrideEvidence
        );
        $freshPlan = $prepared->freshPlan;
        $work = $prepared->work;
        $deleteWork = $prepared->deleteWork;
        $rebuildDeleteWork = $prepared->rebuildDeleteWork;
        $deleteUuids = $prepared->deleteUuids;
        $guardRepairUuids = $prepared->guardRepairUuids;
        $negotiation = $prepared->negotiation;
        $executeDeletes = $prepared->executeDeletes;
        $this->defaultAuthor = $prepared->defaultAuthor;
        $freshActual = $prepared->freshActual;

        $performAuthoredTransaction = true;
        $authorIntent = null;
        if ($scoped) {
            if ($this->scopedWorkflow->session === null
                && (string) ($opts['promotion_owner'] ?? '') === '') {
                // Direct scoped apply acquired only a non-publishing preflight
                // lease. All selected provider, plan, target, and code gates
                // are now re-proved, so publish its random generation before
                // sealing that exact value into mutation authority.
                PromotionLock::begin_apply_session(
                    $this->promotionOwner,
                    $this->promotionArtifact
                );
            }
            $authority = $this->scopedWorkflow->session !== null
                ? $this->scopedWorkflow->session->authority()
                : $this->scopedWorkflow->authority(
                    $plan,
                    $work,
                    $executeDeletes ? $deleteWork : [],
                    $negotiation,
                    $compiled,
                    !empty($opts['with_deletes']),
                    $this->rebuildSelection->selected_actions(),
                    $this->promotionOwner,
                    $this->promotionArtifact
                );
            if (!hash_equals((string) ($authority['scope_hash'] ?? ''), (string) $this->scopedWorkflow->scopeContract['scope_hash'])
                || !hash_equals((string) ($authority['source']['artifact_hash'] ?? ''), $compiled->artifact_hash())
                || !hash_equals((string) ($authority['lease']['owner'] ?? ''), $this->promotionOwner)
                || !hash_equals((string) ($authority['lease']['artifact_hash'] ?? ''), $this->promotionArtifact)
                || !hash_equals(
                    (string) ($authority['lease']['session_id'] ?? ''),
                    PromotionLock::session_id($this->promotionOwner, $this->promotionArtifact)
                )) {
                throw new \RuntimeException('duo: scoped apply recovery authority no longer matches the frozen source and live lease');
            }
            if ($this->scopedWorkflow->promotionWitness !== null) {
                ScopedApplySession::assert_external_promotion(
                    $authority,
                    $this->scopedWorkflow->promotionWitness,
                    !empty($opts['with_deletes'])
                );
            }
            if (!hash_equals(
                (string) ($authority['code_witness_hash'] ?? ''),
                ScopedApply::code_witness_hash($freshPlan, $compiled)
            )) {
                if ($this->scopedWorkflow->session !== null && !$this->scopedWorkflow->session->is_recovery_required()) {
                    $this->scopedWorkflow->session->recover(hash('sha256', 'duo:scoped-code-witness-changed'));
                }
                throw new \RuntimeException(
                    'duo: scoped apply recovery code/lifecycle witness changed; no target effect was replayed'
                );
            }
            if (!hash_equals(
                (string) ($authority['target']['protected_out_of_scope_hash'] ?? ''),
                (string) $this->scopedWorkflow->observation['protected_out_of_scope_root']
            ) || !hash_equals(
                (string) ($authority['target']['protected_ledger_map_hash'] ?? ''),
                (string) $this->scopedWorkflow->observation['protected_ledger_map_root']
            )) {
                if ($this->scopedWorkflow->session !== null && !$this->scopedWorkflow->session->is_recovery_required()) {
                    $this->scopedWorkflow->session->recover(hash('sha256', 'duo:scoped-protected-target-drift'));
                }
                throw new \RuntimeException(
                    'duo: scoped apply recovery refused protected out-of-scope target drift'
                );
            }
            if ($this->scopedWorkflow->terminalSessionToArchive !== null) {
                // Rotation is target metadata mutation too. Keep the prior
                // terminal receipt active through every new-operation gate,
                // locked plan/target/code recheck, and authority validation;
                // an early refusal must not consume lost-response evidence.
                $this->scopedWorkflow->terminalSessionToArchive->archive_terminal();
                $this->scopedWorkflow->terminalSessionToArchive = null;
            }
            $this->scopedWorkflow->session = ScopedApplySession::begin(
                new LedgerScopedApplySessionStorage(),
                $authority
            );
            if ($this->scopedWorkflow->session->is_recovery_required()) {
                throw new \RuntimeException(
                    'duo: scoped apply session requires operator reconciliation of its exact retained authority before retry'
                );
            }
            if ($this->scopedWorkflow->session->phase() === ScopedApplySession::PHASE_PLANNED) {
                $this->scopedWorkflow->session->transition(ScopedApplySession::PHASE_AUTHORING);
            }
            $authorIntent = $this->scopedWorkflow->intent(
                1,
                'duo-scoped-authored-transaction/v1',
                'author-' . substr($this->scopedWorkflow->session->authority_hash_value(), 0, 32),
                hash('sha256', Canon::encode([
                    'plan' => $authority['plan'],
                    'selection' => [
                        'work_hash' => $authority['selection']['work_hash'],
                        'deletions_hash' => $authority['selection']['deletions_hash'],
                    ],
                ])),
                hash('sha256', Canon::encode([
                    'selected_state' => $authority['selection']['work_hash'],
                    'selected_deletions' => $authority['selection']['deletions_hash'],
                ])),
                (string) $authority['target']['selected_before_hash']
            );
            $this->scopedWorkflow->session->append_intent($authorIntent);

            $authoredState = ScopedApply::authored_state(
                $freshActual,
                $compiled,
                $this->policy,
                $this->scopedWorkflow->scopeContract,
                (string) $authority['target']['selected_before_hash']
            );
            $phase = $this->scopedWorkflow->session->phase();
            if ($authoredState === 'before' && !hash_equals(
                (string) ($authority['target']['selected_before_ledger_map_hash'] ?? ''),
                (string) $this->scopedWorkflow->observation['selected_ledger_map_root']
            )) {
                $this->scopedWorkflow->session->recover(hash('sha256', 'duo:scoped-selected-ledger-drift'));
                throw new \RuntimeException(
                    'duo: scoped apply recovery found selected identity-map drift before authored mutation'
                );
            }
            if ($authoredState === 'before'
                && (!hash_equals(
                    (string) ($authority['plan']['precondition_hash'] ?? ''),
                    ApplyPlanner::plan_precondition_hash($plan)
                ) || !hash_equals(
                    (string) ($authority['plan']['guard_witnesses_hash'] ?? ''),
                    $this->scopedWorkflow->guard_witnesses_hash($executeDeletes ? $deleteWork : [])
                ))) {
                $this->scopedWorkflow->session->recover(hash('sha256', 'duo:scoped-plan-or-guard-drift'));
                throw new \RuntimeException(
                    'duo: scoped apply recovery found changed locked plan or deletion-guard evidence'
                );
            }
            if ($phase === ScopedApplySession::PHASE_AUTHORING) {
                if ($authoredState === 'desired') {
                    $performAuthoredTransaction = false;
                    $this->scopedWorkflow->session->transition(ScopedApplySession::PHASE_AUTHORED_COMMITTED);
                    $this->scopedWorkflow->session->append_receipt($this->scopedWorkflow->receipt(
                        $authorIntent,
                        (string) $this->scopedWorkflow->observation['selected_before_root']
                    ));
                } elseif ($authoredState !== 'before') {
                    $this->scopedWorkflow->session->recover(hash('sha256', 'duo:scoped-authored-boundary-mixed'));
                    throw new \RuntimeException(
                        'duo: scoped apply recovery found a mixed authored boundary; no replay was attempted'
                    );
                }
            } else {
                $performAuthoredTransaction = false;
                if ($authoredState !== 'desired') {
                    $this->scopedWorkflow->session->recover(hash('sha256', 'duo:scoped-authored-state-regressed'));
                    throw new \RuntimeException(
                        'duo: scoped apply recovery found selected target drift after authored commit'
                    );
                }
                if ($phase === ScopedApplySession::PHASE_AUTHORED_COMMITTED) {
                    $this->scopedWorkflow->session->append_receipt($this->scopedWorkflow->receipt(
                        $authorIntent,
                        (string) $this->scopedWorkflow->observation['selected_before_root']
                    ));
                }
            }
        }

        // A host continuation already owns its checkpoint-begun session.
        // Direct apply deliberately delays this durable row until the exact
        // selected provider and final locked plan have both passed.  From
        // here onward apply_in_progress and authored mutations may follow, so
        // the session becomes truthful recovery evidence rather than residue
        // from a refused preflight.
        if (!$scoped && (string) ($opts['promotion_owner'] ?? '') === '') {
            PromotionLock::begin_apply_session($this->promotionOwner, $this->promotionArtifact);
        }

        // Written before the first target mutation and cleared only after
        // required rebuilds AND the post-apply canonical verification gate
        // succeed. It is failure state, never convergence state:
        // applied_revision and base hashes still advance afterward.
        //
        // DUO-3489: the value carries the identities this run classified as
        // environment drift and therefore will NOT write (rebuild_work()
        // excludes `drift` outside a scoped promotion). A bare marker cannot
        // tell a row this run wrote from a row it deliberately preserved, and
        // the next plan's retry widening clobbered the second kind silently.
        if (!$scoped) {
            Ledger::kv_set('apply_in_progress', IncompleteApplyMarker::encode($plan['drift']));
        }
        $authored = $this->services->authored_transaction_executor()->execute(
            new AuthoredTransactionRequest(
                workset: new ApplyWorkset(
                    plan: $plan,
                    tree: $tree,
                    work: $work,
                    deleteWork: $deleteWork,
                    deleteUuids: $deleteUuids,
                    guardRepairUuids: $guardRepairUuids,
                    compiledDeletions: $compiled->deletions()
                ),
                deletionAuthority: new DeletionAuthority(
                    execute: $executeDeletes,
                    withDeletes: !empty($opts['with_deletes']),
                    forceReferenced: !empty($opts['force_delete_referenced'])
                ),
                scoped: $scoped,
                scopeContract: $this->scopedWorkflow->scopeContract,
                performTransaction: $performAuthoredTransaction,
                defaultAuthor: $this->defaultAuthor
            ),
            $this->warnings
        );
        $attachmentIds = $authored['attachment_ids'];
        $regenContext = $authored['regen_context'];

        if ($scoped && $this->scopedWorkflow->session !== null) {
            if ($performAuthoredTransaction) {
                $afterActual = Capture::snapshot_read_only(
                    $this->repo,
                    !empty($opts['force_unresolved_refs']),
                    $compiled,
                    $this->policy
                );
                $afterObservation = ScopedApply::observe_target(
                    $this->repo,
                    $compiled,
                    $this->policy,
                    $this->scopedWorkflow->scopeContract,
                    $afterActual,
                    $this->scopedWorkflow->ledger_map_identity_hashes(),
                    false
                );
                if (!hash_equals(
                    (string) $this->scopedWorkflow->session->authority()['target']['protected_out_of_scope_hash'],
                    (string) $afterObservation['protected_out_of_scope_root']
                ) || !hash_equals(
                    (string) $this->scopedWorkflow->session->authority()['target']['protected_ledger_map_hash'],
                    (string) $afterObservation['protected_ledger_map_root']
                ) || ScopedApply::authored_state(
                    $afterActual,
                    $compiled,
                    $this->policy,
                    $this->scopedWorkflow->scopeContract,
                    (string) $this->scopedWorkflow->session->authority()['target']['selected_before_hash']
                ) !== 'desired') {
                    $this->scopedWorkflow->session->recover(hash('sha256', 'duo:scoped-authored-commit-readback-mismatch'));
                    throw new \RuntimeException('duo: scoped authored transaction committed without exact bounded readback');
                }
                $this->scopedWorkflow->observation = $afterObservation;
                $this->scopedWorkflow->session->transition(ScopedApplySession::PHASE_AUTHORED_COMMITTED);
                $this->scopedWorkflow->session->append_receipt($this->scopedWorkflow->receipt(
                    $authorIntent,
                    (string) $afterObservation['selected_before_root']
                ));
            }
            if ($this->scopedWorkflow->session->phase() === ScopedApplySession::PHASE_AUTHORED_COMMITTED) {
                $this->scopedWorkflow->session->transition(ScopedApplySession::PHASE_EFFECTS_PENDING);
            }
        }

        $this->renew_promotion_lock('apply-rebuild');

        $skipScopedCore = false;
        $scopedCoreComplete = null;
        if ($scoped && $this->scopedWorkflow->session !== null) {
            $coreInputHash = hash('sha256', Canon::encode([
                'work' => $this->scopedWorkflow->session->authority()['selection']['work_hash'],
                'deletions' => $this->scopedWorkflow->session->authority()['selection']['deletions_hash'],
                'surfaces' => CanonicalSurfaces::for_apply(
                    $work,
                    $tree,
                    $rebuildDeleteWork,
                    $this->policy
                ),
            ]));
            $coreIntent = $this->scopedWorkflow->intent(
                2,
                'duo-scoped-engine-derived-effects/v1',
                'effects-core-' . substr($this->scopedWorkflow->session->authority_hash_value(), 0, 24),
                $coreInputHash,
                hash('sha256', Canon::encode([
                    'future_schedule' => true,
                    'taxonomy_counts' => true,
                    'attachment_metadata' => false,
                ])),
                (string) $this->scopedWorkflow->observation['selected_before_root']
            );
            $this->scopedWorkflow->session->append_intent($coreIntent);
            $hasCoreWork = $work !== [] || ($executeDeletes && $deleteWork !== []);
            if (!$hasCoreWork && $this->scopedWorkflow->receipt_at(2) === null) {
                $this->scopedWorkflow->session->append_receipt($this->scopedWorkflow->receipt(
                    $coreIntent,
                    hash('sha256', 'duo:scoped-engine-effects-bounded-noop')
                ));
            }
            if ($this->scopedWorkflow->receipt_at(2) !== null) {
                $coreReadbackHash = $hasCoreWork
                    ? $this->scopedWorkflow->core_readback_hash($this->policy, $work, $tree)
                    : hash('sha256', 'duo:scoped-engine-effects-bounded-noop');
                ScopedApplySession::require_effect_receipt_hash(
                    $this->scopedWorkflow->receipt_at(2),
                    $coreReadbackHash
                );
            }
            $skipScopedCore = !$hasCoreWork || $this->scopedWorkflow->receipt_at(2) !== null;
            $scopedCoreComplete = function () use ($coreIntent, $work, $tree): void {
                if ($this->scopedWorkflow->session === null || $this->scopedWorkflow->receipt_at(2) !== null) {
                    return;
                }
                $this->scopedWorkflow->session->append_receipt($this->scopedWorkflow->receipt(
                    $coreIntent,
                    $this->scopedWorkflow->core_readback_hash($this->policy, $work, $tree)
                ));
            };
        }

        // Required derived-state rebuilds happen after authored mutations
        // commit (their WP-CLI subprocesses need to observe those writes) but
        // before ANY convergence metadata advances. A failure therefore
        // leaves the target truthfully unapplied and retryable instead of
        // recording a false-green revision. $work/$tree (DUO-3234) let the
        // regen_dependencies() pass inside rebuild() see this run's changed
        // posts alongside any regen_pending:<uuid> markers left by a prior
        // failed run — see that method's own docblock for why plan's content
        // hash alone (unchanged after a regen-verify failure, since derived
        // tables are excluded from the hash basis) can't carry this signal.
        // The `deletions` batch channel is assembled from these two tombstone
        // sources, handed over separately from the gate that decides whether
        // the first of them actually happened: $deleteWork is exactly what the
        // with_deletes block above deleted, $plan['deleted'] is what a previous
        // incomplete apply had already made absent. $rebuildDeleteWork — the
        // wider set the pre-mutation SELECTION projected its surfaces from — is
        // deliberately NOT what the channel carries: it also contains
        // tombstones this run only PLANNED (a non --with-deletes apply gates
        // every delete off), and a planned-but-ungated tombstone is
        // indistinguishable from an applied one once it reaches a provider.
        // Passing rows rather than re-deriving them keeps the selection and the
        // channel from disagreeing (see rebuild()'s own docblock).
        $this->rebuildCoordinator->rebuild(
            new RebuildRequest(
                attachmentIds: $attachmentIds,
                work: $work,
                tree: $tree,
                regenerationContext: $regenContext,
                deleteWork: $deleteWork,
                withDeletes: $executeDeletes,
                absentTombstones: $plan['deleted'],
                retryingIncompleteApply: $this->retryingIncompleteApply,
                scoped: $scoped,
                skipScopedCore: $skipScopedCore,
                scopedCoreComplete: $scopedCoreComplete === null
                    ? null
                    : \Closure::fromCallable($scopedCoreComplete),
                suppressScopedExternalEffects: $scopedPromotion,
                scopedSession: $this->scopedWorkflow->session,
                scopedObservation: $this->scopedWorkflow->observation
            ),
            $this->warnings,
            $this->actionReceipts
        );

        if ($scoped && $this->scopedWorkflow->session !== null) {
            if ($this->scopedWorkflow->receipt_at(2) === null) {
                $this->scopedWorkflow->session->recover(hash('sha256', 'duo:scoped-core-effect-receipt-missing'));
                throw new \RuntimeException('duo: scoped engine effects completed without a durable readback receipt');
            }
            if ($this->scopedWorkflow->session->phase() === ScopedApplySession::PHASE_EFFECTS_PENDING) {
                $this->scopedWorkflow->session->transition(ScopedApplySession::PHASE_VERIFYING);
            }
        }

        // DUO-3220: never infer convergence from the absence of a thrown
        // mutation/rebuild error. Re-capture the target through the same
        // canonical reader used by plan/capture and prove that every entity
        // in the immutable compiled tree landed byte-semantically (same
        // type + canonical hash). Target-only entities are deliberately not
        // failures: absence is not deletion authority in v2. When deletes
        // were explicitly requested, every compiled tombstone IS authority,
        // so its uuid must now be absent. Any mismatch throws before the
        // ledger transaction below, retaining apply_in_progress and every
        // prior base hash/revision for a truthful retry.
        //
        // DUO-3489: the gate proves the WHOLE compiled tree, while this run
        // deliberately did not write `drift` — so a drifted target makes this
        // failure structural, not incidental. Hand the preserved rows over so
        // the refusal names the cause instead of leaving the operator with a
        // subprocess exit code.
        $verification = (new ConvergenceVerifier(
            $this->repo,
            $this->policy,
            $this->scopedWorkflow->scopeContract,
            $this->scopedWorkflow->observation,
            $this->scopedWorkflow->session
        ))->verify($opts, $compiled, $plan['drift']);

        // ---- ledger bookkeeping (one atomic convergence boundary) ----
        // The retry marker, every base hash, deletes, and applied revision
        // move together. A failure at any one statement or at COMMIT rolls
        // the whole metadata transition back, retaining apply_in_progress so
        // the next run replays required finalization/rebuild work.
        $this->services->apply_ledger_finalizer()->finalize(
            $compiled,
            $plan,
            $tree,
            $work,
            $deleteWork,
            $executeDeletes,
            $scoped,
            $this->scopedWorkflow->scopeContract,
            $this->scopedWorkflow->session,
            $verification,
            (string) ($opts['revision'] ?? '')
        );

        $summary = [
            'artifact' => [
                'hash' => $compiled->artifact_hash(),
                'revision' => $compiled->revision_hash(),
                'manifests' => $compiled->manifest_hash(),
            ],
            'plan' => array_map('count', $plan),
            'applied' => count($work) + ($executeDeletes ? count($deleteWork) : 0),
            'drift' => array_column($plan['drift'], 'path'),
            'warnings' => array_merge($this->warnings, $this->tokens->warnings),
            // DUO-3338 receipts: what each selected rebuild action observed,
            // not merely that it ran. The warnings above stay the human line;
            // this is the machine-readable evidence a recovery controller can
            // correlate with the compiled effects inventory through `source`.
            'actions' => $this->actionReceipts,
            'canary' => 'clean',
            'verification' => $verification,
        ];
        if ($scoped) {
            if ($this->scopedWorkflow->session === null || !$this->scopedWorkflow->session->is_terminal()) {
                throw new \RuntimeException('duo: scoped apply completed without a durable terminal receipt');
            }
            $summary['format'] = 'duo-scoped-apply-result/v1';
            $summary['scoped_receipt'] = $this->scopedWorkflow->session->terminal_receipt();
        }
        return $summary;
    }

    private static function assert_expected_artifact(string $actual, array $opts, bool $required): void {
        $expected = (string) ($opts['artifact_hash'] ?? '');
        if (($required && $expected === '')
            || ($expected !== '' && (!preg_match('/^[0-9a-f]{64}$/', $expected) || !hash_equals($expected, $actual)))) {
            throw new \RuntimeException('duo: apply artifact does not match the host-compiled artifact hash');
        }
    }

    private function renew_promotion_lock(string $phase): void {
        PromotionLock::heartbeat($this->promotionOwner, $this->promotionArtifact, $phase);
    }

    // --------------------------------------------------------------- rebuild


    /**
     * The host's scoped-promotion flag is a tightening capability, never an
     * alternate mutation authority. It is valid only beside the compact scope
     * request and an already-begun target continuation, and force flags cannot
     * widen the externally checkpointed profile.
     */
    private static function assert_scoped_promotion_request(
        array $opts,
        bool $scoped,
        ?array $authorityWitness = null
    ): ?array {
        $receipt = (string) ($opts['scoped_promotion_receipt'] ?? '');
        if ($receipt === '') {
            if ((string) ($opts['promotion_owner'] ?? '') !== '') {
                PromotionLock::assert_no_unbound_scoped_continuation();
            }
            return null;
        }
        if (!$scoped
            || (string) ($opts['promotion_owner'] ?? '') === ''
            || preg_match('/^[a-f0-9]{64}$/D', $receipt) !== 1) {
            throw CommandRefusalException::applyRefused(
                'scoped promotion requires one compact scope request, exact host continuation, and signed receipt hash',
                'retry through SSH host `duo promote --scope-contract=<local-path>`',
                'duo: invalid scoped promotion continuation'
            );
        }
        foreach ([
            'force_delete_referenced', 'force_theirs', 'force_code_mismatch',
            'force_code_drift', 'force_unresolved_refs',
        ] as $force) {
            if (!empty($opts[$force])) {
                throw CommandRefusalException::applyRefused(
                    'scoped promotion does not permit force flags',
                    'remove force flags and reconcile the exact scoped preconditions before retrying',
                    'duo: scoped promotion force override refused'
                );
            }
        }
        $scopeHash = (string) (($opts['scope_request']['scope_hash'] ?? ''));
        $authorityWitness ??= ScopedPromotionAuthority::require_installed(
                (string) $opts['promotion_owner'],
                (string) ($opts['artifact_hash'] ?? ''),
                $receipt,
                $scopeHash,
                ['promoting', 'committed'],
                !empty($opts['with_deletes'])
            );
        if (($authorityWitness['allow_deletes'] ?? null) !== !empty($opts['with_deletes'])) {
            throw CommandRefusalException::applyRefused(
                'scoped promotion deletion authority does not match the signed checkpoint generation',
                'retry with the same --with-deletes intent used when the scoped checkpoint was claimed',
                'duo: scoped promotion deletion authority mismatch'
            );
        }
        PromotionLock::assert_scoped_promotion_session(
            (string) $opts['promotion_owner'],
            (string) ($opts['artifact_hash'] ?? ''),
            $receipt,
            $scopeHash,
            $authorityWitness
        );
        return $authorityWitness;
    }



    /**
     * Renew the promotion lease around one opaque provider invocation.
     *
     * The empty-identity seam is a no-op for the same reason
     * regen_batch_dependencies()'s heartbeat closure has one: a production
     * Apply instance always carries the lease identity run() installed, and
     * keeping the seam inert offline is what makes this dispatch executable in
     * the fake-wpdb suites without weakening the live lease path.
     */
    private function renew_provider_lease(): void {
        if ($this->promotionOwner !== '' && $this->promotionArtifact !== '') {
            $this->renew_promotion_lock('apply-rebuild-provider');
        }
    }
}
