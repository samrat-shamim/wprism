<?php
namespace Duo;

require_once __DIR__ . '/PlainData.php';
require_once __DIR__ . '/Providers.php';
require_once __DIR__ . '/StructuredValue.php';
require_once __DIR__ . '/CommandRefusal.php';
require_once __DIR__ . '/CanonicalSurfaces.php';
require_once __DIR__ . '/ApplyPlanner.php';
require_once __DIR__ . '/ApplyPlanBuilder.php';
require_once __DIR__ . '/ApplyFieldMaterializer.php';
require_once __DIR__ . '/MenuMaterializer.php';
require_once __DIR__ . '/UserMetaMaterializer.php';
require_once __DIR__ . '/TermMaterializer.php';
require_once __DIR__ . '/OptionsMaterializer.php';
require_once __DIR__ . '/RelationshipMaterializer.php';
require_once __DIR__ . '/AttachmentMaterializer.php';
require_once __DIR__ . '/PostMaterializer.php';
require_once __DIR__ . '/DeleteExecutor.php';
require_once __DIR__ . '/DeleteGuardValueCodec.php';
require_once __DIR__ . '/DeleteGuardEvaluator.php';
require_once __DIR__ . '/DeleteGuardReferenceScanner.php';
require_once __DIR__ . '/ConvergenceVerifier.php';
require_once __DIR__ . '/PlanExplanation.php';
require_once __DIR__ . '/PlanCategorySummary.php';
require_once __DIR__ . '/PlanView.php';
require_once __DIR__ . '/ProviderActionBatchBuilder.php';
require_once __DIR__ . '/RegenerationContext.php';
require_once __DIR__ . '/RegenerationContextStore.php';
require_once __DIR__ . '/DependencyRegenerator.php';
require_once __DIR__ . '/NativeRebuildExecutor.php';
require_once __DIR__ . '/ScopedApplyCoordinator.php';
require_once __DIR__ . '/RebuildActionDispatcher.php';
require_once __DIR__ . '/ApplyLedgerFinalizer.php';
require_once __DIR__ . '/EntityAdopter.php';
require_once __DIR__ . '/AuthoredTransactionExecutor.php';
require_once __DIR__ . '/RebuildActionNegotiator.php';

/**
 * Plan + apply: repo state tree -> environment DB.
 *
 * Plan is a three-way comparison per entity: file (target), duo_state ledger
 * hash (base = last sync), env snapshot (actual). Apply is two-phase — insert
 * rows with placeholder refs, then resolve refs through the ledger — inside a
 * transaction, with the side-effect canary armed; the rebuild pass (recounts,
 * attachment metadata, cache flush) runs after the canary disarms.
 */
final class Apply {
    private Policy $policy;
    private Tokens $tokens;
    private ?ApplyFieldMaterializer $fieldMaterializer = null;
    private ?MenuMaterializer $menuMaterializer = null;
    private ?UserMetaMaterializer $userMetaMaterializer = null;
    private ?TermMaterializer $termMaterializer = null;
    private ?OptionsMaterializer $optionsMaterializer = null;
    private ?RelationshipMaterializer $relationshipMaterializer = null;
    private ?AttachmentMaterializer $attachmentMaterializer = null;
    private ?PostMaterializer $postMaterializer = null;
    private ?DeleteExecutor $deleteExecutor = null;
    private ?DeleteGuardReferenceScanner $deleteGuardReferenceScanner = null;
    private ?ProviderActionBatchBuilder $providerActionBatchBuilder = null;
    private ?RegenerationContextStore $regenerationContextStore = null;
    private ?DependencyRegenerator $dependencyRegenerator = null;
    private ?NativeRebuildExecutor $nativeRebuildExecutor = null;
    private ?RebuildActionDispatcher $rebuildActionDispatcher = null;
    private ?ApplyLedgerFinalizer $applyLedgerFinalizer = null;
    private ?EntityAdopter $entityAdopter = null;
    private ?AuthoredTransactionExecutor $authoredTransactionExecutor = null;
    private ?RebuildActionNegotiator $rebuildActionNegotiator = null;
    private ?ApplyPlanner $applyPlanner = null;
    private CompiledRepository $compiled;
    private string $repo;
    /** @var string[] */
    private array $warnings = [];
    /** @var list<array<string,mixed>> reviewed public evidence for forced plan conflicts */
    private array $forcedOverrideEvidence = [];
    private ?int $defaultAuthor = null;
    /** @var array{by_post_type: array<string,string[]>, term_object: string[]}|null
     *  memoized — see taxes_by_object_type() */
    private ?array $taxesByObjectType = null;
    /** @var array|null memoized Snapshot::row_tables() — a pure-PHP manifest
     *  merge (no DB queries of its own), but every dispatch site below
     *  needs "is this entity type a declared table" cheaply and repeatedly,
     *  same rationale as taxesByObjectType's own memoization. */
    private ?array $snapshotRowTablesCache = null;
    private string $promotionOwner = '';
    private string $promotionArtifact = '';
    /** @var list<array<string,mixed>> actions selected by this run's canonical surfaces (DUO-3338) */
    private array $selectedActions = [];
    /** @var array<string,int>|null exact count-only context for the optional plan category projection */
    private ?array $categorySummaryContext = null;
    /** @var array{providers: array<string,object>, capabilities: array<string,array>, scoped_capabilities?:array<string,array>}|null negotiated before the first mutation */
    private ?array $negotiatedProviders = null;
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
    /** Immutable source evidence for a bounded scoped invocation, never mutation authority. */
    private ?array $scopeContract = null;
    /** Hash-only target witnesses captured under the active promotion lease. */
    private ?array $scopedObservation = null;
    private ?ScopedApplySession $scopedSession = null;
    /** Exact signed external checkpoint generation, when this is scoped promote. */
    private ?array $scopedPromotionWitness = null;
    /** Prior terminal slot awaiting a fully preflighted different scoped authority. */
    private ?ScopedApplySession $terminalScopedSessionToArchive = null;

    private function __construct(string $repo, Policy $policy, CompiledRepository $compiled) {
        $this->repo = rtrim($repo, '/');
        $this->policy = $policy;
        $this->compiled = $compiled;
        $this->tokens = new Tokens();
        $this->tokens->policy = $policy;
        $this->fieldMaterializer = new ApplyFieldMaterializer($this->policy, $this->tokens);
    }

    private static function compiled(string $repo, Policy $policy, array $opts): CompiledRepository {
        $path = (string) ($opts['compiled'] ?? '');
        return $path !== ''
            ? RepositoryCompiler::read_artifact($path, $policy)
            : RepositoryCompiler::compile($repo, $policy);
    }

    /** @return array<string, array> declared authored_snapshot tables, keyed by table name (== entity type). */
    private function snapshotRowTables(): array {
        if ($this->snapshotRowTablesCache === null) {
            $this->snapshotRowTablesCache = Snapshot::row_tables($this->policy);
        }
        return $this->snapshotRowTablesCache;
    }

    private function field_materializer(): ApplyFieldMaterializer {
        return $this->fieldMaterializer ??= new ApplyFieldMaterializer($this->policy, $this->tokens);
    }

    private function menu_materializer(): MenuMaterializer {
        return $this->menuMaterializer ??= new MenuMaterializer($this->policy, $this->tokens, $this->field_materializer());
    }

    private function user_meta_materializer(): UserMetaMaterializer {
        return $this->userMetaMaterializer ??= new UserMetaMaterializer($this->policy, $this->tokens, $this->field_materializer());
    }

    private function term_materializer(): TermMaterializer {
        return $this->termMaterializer ??= new TermMaterializer($this->policy, $this->tokens, $this->field_materializer());
    }

    private function options_materializer(): OptionsMaterializer {
        return $this->optionsMaterializer ??= new OptionsMaterializer($this->policy, $this->tokens, $this->field_materializer());
    }

    private function relationship_materializer(): RelationshipMaterializer {
        return $this->relationshipMaterializer ??= new RelationshipMaterializer($this->policy);
    }

    private function attachment_materializer(): AttachmentMaterializer {
        return $this->attachmentMaterializer ??= new AttachmentMaterializer($this->field_materializer(), $this->compiled);
    }

    private function post_materializer(): PostMaterializer {
        return $this->postMaterializer ??= new PostMaterializer(
            $this->policy,
            $this->tokens,
            $this->field_materializer(),
            $this->relationship_materializer(),
            $this->attachment_materializer()
        );
    }

    private function delete_executor(): DeleteExecutor {
        return $this->deleteExecutor ??= new DeleteExecutor(
            $this->policy,
            $this->relationship_materializer(),
            $this->menu_materializer()
        );
    }

    private function delete_guard_reference_scanner(): DeleteGuardReferenceScanner {
        return $this->deleteGuardReferenceScanner ??= new DeleteGuardReferenceScanner($this->policy);
    }

    private function provider_action_batch_builder(): ProviderActionBatchBuilder {
        return $this->providerActionBatchBuilder ??= new ProviderActionBatchBuilder(
            $this->policy,
            $this->snapshotRowTables()
        );
    }

    private function regeneration_context_store(): RegenerationContextStore {
        return $this->regenerationContextStore ??= new RegenerationContextStore(
            $this->policy,
            fn(string $channel, string $surface): bool =>
                $this->selection_declares_channel_for($channel, $surface)
        );
    }

    private function dependency_regenerator(): DependencyRegenerator {
        return $this->dependencyRegenerator ??= new DependencyRegenerator(
            $this->policy,
            $this->regeneration_context_store(),
            fn(string $surface): bool => $this->selection_declares_entity_batch_for($surface),
            fn(string $channel, string $surface): bool =>
                $this->selection_declares_channel_for($channel, $surface),
            fn(string $surface): bool => $this->selection_triggers_provider_action_for($surface),
            fn(string $surface): bool => $this->pinned_provider_action_owns($surface),
            function (): void {
                if ($this->promotionOwner !== '' && $this->promotionArtifact !== '') {
                    $this->renew_promotion_lock('apply-rebuild-regen-batch');
                }
            }
        );
    }

    private function native_rebuild_executor(): NativeRebuildExecutor {
        return $this->nativeRebuildExecutor ??= new NativeRebuildExecutor(
            $this->policy,
            function (
                string $table,
                string $fkCol,
                int $objectId,
                string $key,
                ?string $value,
                ?string $context,
                string $idCol
            ): void {
                $this->upsert_meta($table, $fkCol, $objectId, $key, $value, $context, $idCol);
            }
        );
    }

    private function rebuild_action_dispatcher(): RebuildActionDispatcher {
        return $this->rebuildActionDispatcher ??= new RebuildActionDispatcher(
            $this->policy,
            $this->provider_action_batch_builder(),
            function (): void {
                $this->renew_provider_lease();
            }
        );
    }

    private function apply_ledger_finalizer(): ApplyLedgerFinalizer {
        return $this->applyLedgerFinalizer ??= new ApplyLedgerFinalizer(
            function (): void {
                $this->renew_promotion_lock('apply-ledger');
            }
        );
    }

    private function entity_adopter(): EntityAdopter {
        return $this->entityAdopter ??= new EntityAdopter(
            $this->policy,
            $this->snapshotRowTables()
        );
    }

    private function authored_transaction_executor(): AuthoredTransactionExecutor {
        return $this->authoredTransactionExecutor ??= new AuthoredTransactionExecutor(
            $this->policy,
            $this->tokens,
            $this->apply_planner(),
            $this->snapshotRowTables(),
            $this->entity_adopter(),
            $this->term_materializer(),
            $this->post_materializer(),
            $this->menu_materializer(),
            $this->options_materializer(),
            $this->user_meta_materializer(),
            $this->delete_executor(),
            $this->regeneration_context_store(),
            fn(): array => $this->taxes_by_object_type(),
            function (string $phase): void {
                $this->renew_promotion_lock($phase);
            },
            function (
                array $deleteWork,
                array $deleteUuids,
                array $deletions,
                array $tree,
                array $guardRepairUuids
            ): void {
                $this->lock_and_revalidate_delete_guards(
                    $deleteWork,
                    $deleteUuids,
                    $deletions,
                    $tree,
                    $guardRepairUuids
                );
            },
            function (
                array $row,
                array $deleteUuids,
                array $deletions,
                bool $forced,
                array $tree,
                array $guardRepairUuids,
                bool $forUpdate
            ): void {
                $this->recheck_delete_guards(
                    $row,
                    $deleteUuids,
                    $deletions,
                    $forced,
                    $tree,
                    $guardRepairUuids,
                    $forUpdate
                );
            }
        );
    }

    private function rebuild_action_negotiator(): RebuildActionNegotiator {
        return $this->rebuildActionNegotiator ??= new RebuildActionNegotiator($this->policy);
    }

    private function apply_planner(): ApplyPlanner {
        return $this->applyPlanner ??= new ApplyPlanner(
            $this->policy,
            $this->snapshotRowTables(),
            static fn(string $uuid, string $kind): ?int => Ledger::id_for(
                $uuid,
                $kind === 'tt' ? Ledger::KIND_TT : $kind
            ),
            static fn(string $uuid, string $kind): ?int => Ledger::id_for($uuid, $kind)
        );
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
            $a->scopeContract = ScopedApply::resolve_contract($scopeRequest, $compiled, $policy);
            if ($scopedPromotion) {
                ScopeContract::assert_mutation_supported($a->scopeContract, 'scoped promote');
            }
            $activeScoped = ScopedApplySession::open(new LedgerScopedApplySessionStorage());
            if ($activeScoped !== null && !$activeScoped->is_terminal()) {
                $authority = $activeScoped->authority();
                if (!hash_equals((string) $authority['scope_hash'], (string) $a->scopeContract['scope_hash'])
                    || !hash_equals((string) $authority['source']['artifact_hash'], $compiled->artifact_hash())) {
                    throw new \RuntimeException(
                        'duo: scoped plan refused — a different scoped mutation authority is nonterminal on this target'
                    );
                }
                $a->scopedSession = $activeScoped;
            }
            $plan = ScopedApply::project_plan(
                $a->build_plan($opts, $compiled, true, false),
                $a->scopeContract
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
                $a->scopeContract,
                $actual,
                $a->scoped_ledger_map_identity_hashes(),
                $a->scoped_allows_target_old_menu_items($actual)
            );
            $work = $a->rebuild_work($plan, $compiled->tree(), $opts, false, $scopedPromotion);
            $surfaces = $a->rebuild_surfaces(
                $work['work'],
                $compiled->tree(),
                $work['rebuild_delete_work']
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
                'scope_hash' => (string) $a->scopeContract['scope_hash'],
                'source_artifact_hash' => $compiled->artifact_hash(),
                'selected_identities' => count(ScopedStateOverlay::selected_identities($a->scopeContract)),
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
            if ($a->scopedSession !== null) {
                $plan['scoped_recovery'] = [
                    'authority_hash' => $a->scopedSession->authority_hash_value(),
                    'phase' => $a->scopedSession->phase(),
                    'session_id' => $a->scopedSession->session_id(),
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
            $this->apply_planner(),
            $this->delete_guard_reference_scanner(),
            $this->snapshotRowTables(),
            $this->scopeContract,
            fn(): array => $this->regeneration_debt_projection(),
            fn(): array => $this->env_missing_projection(),
            $this->warnings
        );
        $result = $builder->build($opts, $compiled, $strictObservation, $diagnoseAdapters);
        $this->warnings = $result['warnings'];
        $this->selectedActions = $result['selected_actions'];
        $this->categorySummaryContext = $result['category_summary_context'];
        return $result['plan'];
    }

    /**
     * Thin compatibility facade over ApplyPlanner::nested_delete_candidate_counts()
     * (DUO-3347 slice 2) — kept so this method's existing internal call site
     * (build_plan(), unchanged) needs no edit while this decomposition proceeds.
     *
     * @param array<string,array<string,mixed>> $env
     * @param array<string,array<string,mixed>> $tree
     * @param array<string,mixed> $plan
     * @param array{menus_by_term_id:array<string,array{uuid:?string,managed_menu_item_uuids:list<string>,all_menu_item_count:int}>}|null $observations
     * @return array{menu:int,widget:int,option:int}|null
     */
    private static function nested_delete_candidate_counts(
        array $env,
        array $tree,
        array $plan,
        ?array $observations
    ): ?array {
        return ApplyPlanner::nested_delete_candidate_counts($env, $tree, $plan, $observations);
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
                $surfaces = $apply->entity_rebuild_surfaces($entity, $row);
            }
        } elseif ($bucket === 'delete') {
            $surfaces = $apply->deletion_rebuild_surfaces($row);
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
     * Return only option names whose canonical records changed. The options
     * document format and record envelope are deliberately compared by
     * OptionState::record_hash(), so unrelated option records do not widen a
     * scoped action's authority.
     *
     * @return list<string>
     */
    private function option_rebuild_names(array $desiredDocument, ?array $env): array {
        return $this->apply_planner()->option_rebuild_names($desiredDocument, $env);
    }

    /**
     * Attach the DUO-3237 continuity observation to a plan row. A target
     * mapping is required: on a fresh target the repository UUID may differ
     * from UUIDv5(current key), but no retained local identity exists yet and
     * adopt/collision handling remains the reconciliation path.
     */
    private function annotate_natural_key_continuity(
        array &$row,
        string $uuid,
        string $table,
        ?array $desired,
        ?array $env
    ): void {
        foreach ($this->apply_planner()->natural_key_continuity_annotations(
            $uuid,
            $table,
            $desired,
            $env
        ) as $note) {
            if ($note !== null && !in_array($note, $row['annotations'] ?? [], true)) {
                $row['annotations'][] = $note;
            }
        }
    }

    /**
     * Stable, additive conflict evidence for plan JSON. The three roles are
     * deliberately semantic rather than value-bearing: repository entities
     * can contain secrets, PII, or plugin-owned opaque structures, so a plan
     * may expose their already-canonical hashes and intent but never copy raw
     * entity values into a diagnostic surface. `reconcile_in_repository` is
     * the non-destructive default; `apply_repository` maps the existing named
     * report-not-hide escape hatch and states every required flag.
     *
     * @param list<string> $applyRequirements
     * @return array<string,mixed>
     */
    private static function conflict_view(
        string $reasonCode,
        string $repositoryIntent,
        string $baseState,
        ?string $baseHash,
        ?string $repositoryHash,
        ?string $expectedBaseHash,
        ?string $intentReceiptHash,
        ?string $targetHash,
        array $applyRequirements
    ): array {
        return ApplyPlanner::conflict_view(
            $reasonCode,
            $repositoryIntent,
            $baseState,
            $baseHash,
            $repositoryHash,
            $expectedBaseHash,
            $intentReceiptHash,
            $targetHash,
            $applyRequirements
        );
    }

    /**
     * Public failure evidence for a conflict override is intentionally less
     * identifying than the successful plan row: hash the canonical entity
     * identity so option/user-meta identities and WordPress names cannot leak
     * through a later JSON refusal. All remaining fields are engine-owned
     * enums already present in the reviewed conflict view.
     *
     * A referentially blocked tombstone deliberately does not advertise the
     * destructive apply_repository choice. Report required and actually
     * supplied flags separately; only their exact equality is authorization.
     * This keeps a partial attempt truthful and never fabricates the
     * suppressed plan choice.
     *
     * @return array<string,mixed>
     */
    private static function forced_override_evidence(array $row, string $bucket, array $opts): array {
        return ApplyPlanner::forced_override_evidence($row, $bucket, $opts);
    }

    /**
     * A requested destructive conflict override is not authorization until
     * every advertised gate is present. Keep the refusal machine-readable
     * without promoting a missing flag into a false FORCED/authorized claim.
     *
     * @param list<array<string,mixed>> $evidence
     */
    private static function incomplete_override_refusal(array $evidence, string $operatorMessage): CommandRefusalException {
        return ApplyPlanner::incomplete_override_refusal($evidence, $operatorMessage);
    }

    /**
     * WordPress-facing display name for a plan row. Posts carry authored
     * `title` front matter and terms/menus carry `name`; options, sidebars,
     * and typed tables are already named by their repository path. Only the
     * authored value itself is projected — never a guessed or derived label
     * (DUO-3345: plans must speak WordPress names, not identifier-bearing
     * paths alone). The raw value lands in plan JSON; human renderers own
     * any display sanitization.
     */
    private static function entity_display_title(mixed $data): ?string {
        return ApplyPlanner::entity_display_title($data);
    }

    /**
     * Deploy already changed lifecycle-managed records through WordPress
     * APIs. Compare three-way history against the exact pre-hook snapshot
     * only while the current canonical entity still equals deploy's recorded
     * post-hook snapshot; any later or unrelated target edit falls back to
     * the ordinary conflict path.
     *
     * @param array{entity:string,before_hash:string,after_hash:string}|null $transition
     */
    private static function lifecycle_comparison_hash(
        string $uuid,
        ?string $environmentHash,
        ?array $transition
    ): ?string {
        return ApplyPlanner::lifecycle_comparison_hash($uuid, $environmentHash, $transition);
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
                . "blob, not a plain scalar value env-set can safely overwrite (the plugin populates it "
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

    /** Thin compatibility facade over ApplyPlanner::phase2_rank(). */
    private function phase2_rank(array $entity): int {
        return $this->apply_planner()->phase2_rank($entity);
    }

    /** Thin compatibility facade over ApplyPlanner::deletion_rank(). */
    private function deletion_rank(array $row): int {
        return $this->apply_planner()->deletion_rank($row);
    }

    /** Thin compatibility facade over ApplyPlanner::guard_repair_uuids(). */
    private function guard_repair_uuids(array $plan, bool $includeDrift = false): array {
        return ApplyPlanner::guard_repair_uuids($plan, $includeDrift);
    }

    /**
     * Compatibility facade retained for the transaction and reflection-based
     * callers while DeleteGuardReferenceScanner owns all target reads.
     *
     * @return array{count:int,error:?string,rows:string[],witness?:string}
     */
    private function count_guard_refs(
        array $guard,
        string $targetUuid,
        array $deleteUuids,
        array $deletions,
        array $tree = [],
        array $guardRepairUuids = [],
        bool $forUpdate = false
    ): array {
        return $this->delete_guard_reference_scanner()->count(
            $guard,
            $targetUuid,
            $deleteUuids,
            $deletions,
            $tree,
            $guardRepairUuids,
            $forUpdate
        );
    }

    /** Make --force-delete-referenced name every survivor and its supported exit path. */
    private function warn_forced_guard_refs(array $row, string $prefix): void {
        if (empty($row['guard_refs'])) {
            // Schema/mapping guard failures do not represent enumerable
            // survivor rows. Retain the historical forced-warning shape for
            // those error findings instead of silently dropping the warning.
            $this->warnings[] = "$prefix {$row['type']} {$row['uuid']} ({$row['blocked']})";
            return;
        }
        foreach ($row['guard_refs'] ?? [] as $finding) {
            $table = (string) $finding['table'];
            $rows = (array) $finding['rows'];
            $count = count($rows);
            $message = "$prefix {$row['type']} {$row['uuid']}: $count rows in $table will be orphaned; ";
            if (!empty($finding['option_name_ref'])) {
                $message .= 'the matching options/core tombstone is the supported repair; ';
            } elseif (!empty($finding['repairable'])) {
                $message .= "wp duo plan/capture on $table will refuse until resolved (wp duo orphans $table). ";
            } else {
                $message .= "$table is not a declared authored-snapshot table and must be resolved through its owning content workflow. ";
            }
            $this->warnings[] = $message . 'Surviving rows: ' . implode(', ', $rows);
        }
    }

    /**
     * Thin compatibility facade over ApplyPlanner::theme_mismatch_warnings().
     * The active stylesheet is the only WordPress-facing input; warning
     * projection stays on the planner so its exact grouping and text are
     * directly characterizable without a live target.
     */
    private function check_theme_mismatch(array $tree): void {
        if (!ApplyPlanner::theme_mismatch_has_theme_terms($tree)) {
            return;
        }
        foreach (ApplyPlanner::theme_mismatch_warnings($tree, (string) get_option('stylesheet')) as $warning) {
            $this->warnings[] = $warning;
        }
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
            $a->scopedSession = $recoveringScopedSession ? $existingScopedSession : null;
            $a->scopedPromotionWitness = $scopedPromotionWitness;
            $a->terminalScopedSessionToArchive = $terminalScopedSessionToArchive;
            if ($scoped) {
                $a->scopeContract = ScopedApply::resolve_contract(
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
        $this->register_shortcode_alternates($tree);
        $scoped = $this->scopeContract !== null;
        $scopedPromotion = (string) ($opts['scoped_promotion_receipt'] ?? '') !== '';
        $recoveringScoped = $scoped && $this->scopedSession !== null;
        $retryingIncompleteApply = Ledger::kv_get('apply_in_progress') !== null;
        if ($scoped && $retryingIncompleteApply) {
            throw new \RuntimeException(
                'duo: scoped apply refused — a full apply recovery marker is active; complete or recover that exact full apply first'
            );
        }
        $this->retryingIncompleteApply = $retryingIncompleteApply;
        $plan = $this->build_plan($opts, $compiled, $scoped, !$scoped);
        if ($scoped) {
            $plan = ScopedApply::project_plan($plan, $this->scopeContract);
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
            $this->scopedObservation = ScopedApply::observe_target(
                $this->repo,
                $compiled,
                $this->policy,
                $this->scopeContract,
                $actual,
                $this->scoped_ledger_map_identity_hashes(),
                $this->scoped_allows_target_old_menu_items($actual)
            );
        }

        if (!$recoveringScoped && $plan['collision']) {
            $list = implode("\n  - ", array_map(
                fn($r) => "{$r['type']} {$r['path']} collides with env id {$r['env_id']} (same slug, different/no uuid)",
                $plan['collision']
            ));
            throw new \RuntimeException(
                "duo: slug collisions need explicit resolution (--adopt-by-slug=posts,terms,menus,tables adopts unmanaged rows):\n  - $list"
            );
        }
        if (!$recoveringScoped && $plan['conflict'] && empty($opts['force_theirs'])) {
            $list = implode("\n  - ", array_column($plan['conflict'], 'path'));
            throw new \RuntimeException(
                "duo: conflicts (env and repo both changed since last sync) — capture first or --force-theirs:\n  - $list"
            );
        }
        if (!$recoveringScoped && $plan['delete_conflict'] && empty($opts['force_theirs'])) {
            $list = implode("\n  - ", array_map(
                fn($r) => "{$r['path']}: {$r['reason']}",
                $plan['delete_conflict']
            ));
            throw new \RuntimeException(
                "duo: deletion conflicts (target differs from the tombstone's expected base) — "
                . "capture/reconcile first or --force-theirs:\n  - $list"
            );
        }
        if (!$recoveringScoped && $plan['delete_conflict'] && empty($opts['with_deletes'])) {
            $list = implode("\n  - ", array_map(
                fn($r) => "{$r['path']}: {$r['reason']}",
                $plan['delete_conflict']
            ));
            $evidence = array_map(
                fn(array $row): array => self::forced_override_evidence($row, 'delete_conflict', $opts),
                $plan['delete_conflict']
            );
            throw self::incomplete_override_refusal(
                $evidence,
                "duo: deletion conflict override requires --with-deletes together with --force-theirs; "
                    . "no target mutation attempted:\n  - $list"
            );
        }

        $pendingOptionDeletes = [];
        $pendingOptionConflictEvidence = [];
        $pendingOptionWork = $recoveringScoped
            ? []
            : array_merge($plan['create'], $plan['update'], $plan['conflict']);
        if ($scopedPromotion) {
            // Ordinary apply deliberately leaves environment-only drift for
            // capture/reconciliation. The externally checkpointed profile is
            // different: its whole-target exclusion and encrypted before
            // image authorize replacing the selected drift with the frozen
            // repository state. Keep the deletion gate aligned with that
            // same bounded authored work set.
            $pendingOptionWork = array_merge($pendingOptionWork, (array) ($plan['drift'] ?? []));
        }
        foreach ($pendingOptionWork as $r) {
            foreach ($r['option_deletes'] ?? [] as $name) {
                $pendingOptionDeletes[] = $name;
            }
        }
        foreach ($recoveringScoped ? [] : $plan['conflict'] as $r) {
            if (($r['option_deletes'] ?? []) !== []) {
                $pendingOptionConflictEvidence[] = self::forced_override_evidence($r, 'conflict', $opts);
            }
        }
        if ($pendingOptionDeletes && empty($opts['with_deletes'])) {
            sort($pendingOptionDeletes, SORT_STRING);
            $operatorMessage = "duo: authored option deletion intent requires --with-deletes; "
                . "no target mutation attempted:\n  - "
                . implode("\n  - ", array_unique($pendingOptionDeletes));
            if ($pendingOptionConflictEvidence !== []) {
                throw self::incomplete_override_refusal($pendingOptionConflictEvidence, $operatorMessage);
            }
            throw new \RuntimeException($operatorMessage);
        }
        if (!$recoveringScoped && $plan['missing_user']) {
            $list = implode("\n  - ", array_map(
                fn($r) => "{$r['path']}: exact login '{$r['login']}' is absent",
                $plan['missing_user']
            ));
            throw new \RuntimeException(
                "duo: user-meta apply refused before target mutation — required exact login(s) are missing:\n  - $list\n"
                . 'Create/reconcile the user outside Duo, or explicitly declare missing_user="warn" on every '
                . 'authored key in that sidecar to warn-and-skip it.'
            );
        }
        // The completed code_revision is the ordering witness between the
        // code and state halves. It is deliberately NOT part of the generic
        // --force-code-mismatch escape hatch: state writes before stage ->
        // lifecycle -> finalize would recreate the exact schema/content skew
        // Duo is supposed to prevent. The remaining lifecycle compatibility
        // rows retain the existing explicit force behavior below.
        $forceableCodeMismatch = self::enforce_code_mismatch_gate(
            $plan['code_mismatch'],
            $opts
        );

        // DUO-3231: code_drift remains a separate, explicitly forceable
        // provenance gate. That is distinct from the non-forceable stale
        // descriptor ordering check above — see Deploy::code_drift()'s
        // docblock for what distinguishes the two questions.
        if ($plan['code_drift'] && empty($opts['force_code_drift'])) {
            $list = implode("\n\n", array_map(fn($r) => '  - ' . $r['message'], $plan['code_drift']));
            throw new \RuntimeException(
                "duo: apply refused — code_drift:\n\n$list\n\n"
                . "Run 'duo deploy <env>' to reconcile and re-baseline, or pass --force-code-drift to proceed anyway."
            );
        }
        // Architecture Rulings §1 (report-not-hide): reaching this line with
        // findings present is only possible via --force-code-drift — surface
        // what was overridden, same convention as "FORCED delete of guarded
        // ..." below, so `wp duo apply`'s own (non --format=json) output
        // doesn't silently swallow it.
        foreach ($plan['code_drift'] as $r) {
            $this->warnings[] = 'FORCED past code_drift: ' . $r['message'];
        }
        // Same gap, one issue over: unlike Deploy::run() (which emits a more
        // specific "skipped activating .../skipped theme switch ..." message
        // for missing_in_code findings once activation actually reaches
        // them), apply never attempts activation at all — that's deploy's
        // job (see the refuse-gate's own message above). Every forced-through
        // code_mismatch finding, missing_in_code included, is only ever
        // visible via THIS warning in apply's context, so none are excluded
        // here.
        foreach ($forceableCodeMismatch as $r) {
            $this->warnings[] = 'FORCED past code_mismatch: ' . $r['message'];
        }

        $rebuildWork = $this->rebuild_work(
            $plan,
            $tree,
            $opts,
            $retryingIncompleteApply,
            $scopedPromotion
        );
        if ($recoveringScoped) {
            $rebuildWork = $this->scoped_recovery_work($plan, $compiled);
        }
        $deleteWork = $rebuildWork['delete_work'];
        $rebuildDeleteWork = $rebuildWork['rebuild_delete_work'];
        $work = $rebuildWork['work'];
        $executeDeletes = !empty($opts['with_deletes'])
            || ($recoveringScoped
                && (array) ($this->scopedSession->authority()['selection']['deletion_items'] ?? []) !== []);

        if ($scoped && !$executeDeletes && $deleteWork !== []) {
            throw CommandRefusalException::applyRefused(
                'scoped apply selected live tombstones but --with-deletes was not supplied; '
                    . 'no scoped session or authored target mutation was created',
                'review the selected tombstones and rerun scoped apply with --with-deletes to authorize their removal',
                'duo: scoped apply selected live tombstones but --with-deletes was not supplied; '
                    . 'no scoped session or authored target mutation was created'
            );
        }

        if ($executeDeletes) {
            $blocked = array_filter($deleteWork, fn($r) => isset($r['blocked']));
            if ($blocked && empty($opts['force_delete_referenced'])) {
                $list = implode("\n  - ", array_map(
                    fn($r) => "{$r['type']} {$r['uuid']}: {$r['blocked']}",
                    $blocked
                ));
                $operatorMessage = "duo: deletes blocked by referential guards "
                    . "(this environment's runtime data references them; --force-delete-referenced to override):\n  - $list";
                $conflictEvidence = [];
                foreach ($blocked as $row) {
                    if (isset($row['conflict_view'])) {
                        $conflictEvidence[] = self::forced_override_evidence($row, 'delete_conflict', $opts);
                    }
                }
                if ($conflictEvidence !== []) {
                    throw self::incomplete_override_refusal($conflictEvidence, $operatorMessage);
                }
                throw new \RuntimeException($operatorMessage);
            }
            foreach ($blocked as $r) {
                $this->warn_forced_guard_refs($r, 'FORCED delete of guarded');
            }
        }

        // Every plan/option/referential gate above is now satisfied. Only at
        // this boundary may report-not-hide call the override FORCED and its
        // structured evidence authorized; later failures preserve that exact
        // authorization without claiming the target mutation committed.
        foreach ($plan['conflict'] as $r) {
            $this->warnings[] = "FORCED conflict {$r['uuid']} (repository intent authorized to replace target authored state)";
            $this->forcedOverrideEvidence[] = self::forced_override_evidence($r, 'conflict', $opts);
        }
        foreach ($plan['delete_conflict'] as $r) {
            $this->warnings[] = "FORCED deletion conflict {$r['uuid']} ({$r['reason']})";
            $this->forcedOverrideEvidence[] = self::forced_override_evidence($r, 'delete_conflict', $opts);
        }

        $this->defaultAuthor = $this->resolve_login($opts['default_author'] ?? '') ?? null;
        $this->tokens->defaultUserId = $this->defaultAuthor;

        $deleteUuids = array_fill_keys(array_column($deleteWork, 'uuid'), true);
        $guardRepairUuids = $this->guard_repair_uuids($plan, $scopedPromotion);

        // DUO-3338 provider negotiation, deliberately positioned here: the
        // rebuild pass at the far end of this method is what actually invokes
        // a plugin-owned provider, but the boundary doctrine requires an
        // unsupported or unverifiable capability to fail BEFORE destructive
        // writes — discovering a missing provider after phase 2 has committed
        // would leave a target half-converged with no derived-state repair.
        // rebuild_surfaces() is a pure projection over $work/$tree/tombstones
        // (its own docblock: it never reads target ids), so it is safe to run
        // before the first mutation and returns the identical selection the
        // rebuild pass will act on. An empty surface set selects nothing and
        // negotiates nothing, so a read-only apply contacts no provider code.
        $actionPreflight = $this->rebuild_action_negotiator()->negotiate(
            $work,
            $tree,
            $rebuildDeleteWork,
            $deleteWork,
            $scoped,
            $scopedPromotion
        );
        $this->selectedActions = $actionPreflight['selected_actions'];
        $negotiation = $actionPreflight['negotiation'];
        $this->negotiatedProviders = $actionPreflight['providers'];
        if ($recoveringScoped) {
            $this->assert_scoped_recovery_selection($negotiation);
        }

        // Planning is intentionally read-only and can be expensive. The
        // target-authoritative lease prevents another Duo writer from racing
        // us, then this second plan proves that live authored/runtime changes,
        // identity mappings, code state, manifest/schema assumptions, and
        // delete guards did not change during the planning window. Force
        // flags cannot bypass this stale-plan boundary.
        $this->renew_promotion_lock('precondition-recheck');
        if (getenv('DUO_TEST_MODE') === '1') {
            $pauseMs = (int) (getenv('DUO_TEST_PROMOTION_PAUSE_MS') ?: 0);
            if ($pauseMs > 0 && $pauseMs <= 10000) {
                usleep($pauseMs * 1000);
            }
        }
        // build_plan() also projects selected actions for plan/status output.
        // Under a scope contract that intermediate projection is deliberately
        // still full-repository until project_plan() narrows its rows. Preserve
        // the exact action set negotiated above, then independently rederive
        // it from the projected fresh plan. Otherwise the fresh recheck can
        // overwrite $this->selectedActions with an out-of-scope declaration
        // after negotiation and make rebuild either invoke an unauthorized
        // effect or fail only after the authored transaction begins.
        $negotiatedSelectedActions = $this->selectedActions;
        $freshPlan = $this->build_plan($opts, $compiled, $scoped, !$scoped);
        if ($scoped) {
            $freshPlan = ScopedApply::project_plan($freshPlan, $this->scopeContract);
            $freshRebuildWork = $this->rebuild_work(
                $freshPlan,
                $tree,
                $opts,
                $retryingIncompleteApply,
                $scopedPromotion
            );
            $freshSelectedActions = $this->policy->actions_for($this->rebuild_surfaces(
                $freshRebuildWork['work'],
                $tree,
                $freshRebuildWork['rebuild_delete_work']
            ));
            if (Canon::encode($freshSelectedActions) !== Canon::encode($negotiatedSelectedActions)) {
                throw new \RuntimeException(
                    'duo: scoped action selection changed after planning; no target mutation attempted'
                );
            }
        }
        $this->selectedActions = $negotiatedSelectedActions;
        if (!hash_equals($this->plan_precondition_hash($plan), $this->plan_precondition_hash($freshPlan))) {
            throw new \RuntimeException(
                'duo: promotion preconditions changed after planning; no target mutation attempted — recompile and retry'
            );
        }
        if ($scoped) {
            $freshActual = Capture::snapshot_read_only(
                $this->repo,
                !empty($opts['force_unresolved_refs']),
                $compiled,
                $this->policy
            );
            $freshObservation = ScopedApply::observe_target(
                $this->repo,
                $compiled,
                $this->policy,
                $this->scopeContract,
                $freshActual,
                $this->scoped_ledger_map_identity_hashes(),
                $this->scoped_allows_target_old_menu_items($freshActual)
            );
            foreach ([
                'selected_before_root', 'protected_out_of_scope_root',
                'ledger_map_root', 'protected_ledger_map_root', 'selected_ledger_map_root',
                'target_observation_hash',
            ] as $witness) {
                if (!hash_equals(
                    (string) ($this->scopedObservation[$witness] ?? ''),
                    (string) ($freshObservation[$witness] ?? '')
                )) {
                    throw new \RuntimeException(
                        'duo: scoped target observation changed after planning; no target mutation attempted'
                    );
                }
            }
            if (!hash_equals(
                ScopedApplySession::hash_value((array) ($this->scopedObservation['_ledger_map_identity_hashes'] ?? [])),
                ScopedApplySession::hash_value((array) ($freshObservation['_ledger_map_identity_hashes'] ?? []))
            )) {
                throw new \RuntimeException(
                    'duo: scoped ledger-map identity selection changed after planning; no target mutation attempted'
                );
            }
            $this->scopedObservation = $freshObservation;
        }

        if ($scopedPromotion) {
            $scopeHash = (string) ($this->scopeContract['scope_hash'] ?? '');
            $authorityWitness = ScopedPromotionAuthority::require_installed(
                $this->promotionOwner,
                $this->promotionArtifact,
                (string) $opts['scoped_promotion_receipt'],
                $scopeHash,
                ['promoting'],
                !empty($opts['with_deletes'])
            );
            $this->scopedPromotionWitness = $authorityWitness;
            PromotionLock::assert_scoped_promotion_session(
                $this->promotionOwner,
                $this->promotionArtifact,
                (string) $opts['scoped_promotion_receipt'],
                $scopeHash,
                $authorityWitness
            );
        }

        $performAuthoredTransaction = true;
        $authorIntent = null;
        if ($scoped) {
            if ($this->scopedSession === null
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
            $authority = $this->scopedSession !== null
                ? $this->scopedSession->authority()
                : $this->scoped_authority(
                    $plan,
                    $work,
                    $executeDeletes ? $deleteWork : [],
                    $negotiation,
                    $compiled,
                    !empty($opts['with_deletes'])
                );
            if (!hash_equals((string) ($authority['scope_hash'] ?? ''), (string) $this->scopeContract['scope_hash'])
                || !hash_equals((string) ($authority['source']['artifact_hash'] ?? ''), $compiled->artifact_hash())
                || !hash_equals((string) ($authority['lease']['owner'] ?? ''), $this->promotionOwner)
                || !hash_equals((string) ($authority['lease']['artifact_hash'] ?? ''), $this->promotionArtifact)
                || !hash_equals(
                    (string) ($authority['lease']['session_id'] ?? ''),
                    PromotionLock::session_id($this->promotionOwner, $this->promotionArtifact)
                )) {
                throw new \RuntimeException('duo: scoped apply recovery authority no longer matches the frozen source and live lease');
            }
            if ($this->scopedPromotionWitness !== null) {
                ScopedApplySession::assert_external_promotion(
                    $authority,
                    $this->scopedPromotionWitness,
                    !empty($opts['with_deletes'])
                );
            }
            if (!hash_equals(
                (string) ($authority['code_witness_hash'] ?? ''),
                ScopedApply::code_witness_hash($freshPlan, $compiled)
            )) {
                if ($this->scopedSession !== null && !$this->scopedSession->is_recovery_required()) {
                    $this->scopedSession->recover(hash('sha256', 'duo:scoped-code-witness-changed'));
                }
                throw new \RuntimeException(
                    'duo: scoped apply recovery code/lifecycle witness changed; no target effect was replayed'
                );
            }
            if (!hash_equals(
                (string) ($authority['target']['protected_out_of_scope_hash'] ?? ''),
                (string) $this->scopedObservation['protected_out_of_scope_root']
            ) || !hash_equals(
                (string) ($authority['target']['protected_ledger_map_hash'] ?? ''),
                (string) $this->scopedObservation['protected_ledger_map_root']
            )) {
                if ($this->scopedSession !== null && !$this->scopedSession->is_recovery_required()) {
                    $this->scopedSession->recover(hash('sha256', 'duo:scoped-protected-target-drift'));
                }
                throw new \RuntimeException(
                    'duo: scoped apply recovery refused protected out-of-scope target drift'
                );
            }
            if ($this->terminalScopedSessionToArchive !== null) {
                // Rotation is target metadata mutation too. Keep the prior
                // terminal receipt active through every new-operation gate,
                // locked plan/target/code recheck, and authority validation;
                // an early refusal must not consume lost-response evidence.
                $this->terminalScopedSessionToArchive->archive_terminal();
                $this->terminalScopedSessionToArchive = null;
            }
            $this->scopedSession = ScopedApplySession::begin(
                new LedgerScopedApplySessionStorage(),
                $authority
            );
            if ($this->scopedSession->is_recovery_required()) {
                throw new \RuntimeException(
                    'duo: scoped apply session requires operator reconciliation of its exact retained authority before retry'
                );
            }
            if ($this->scopedSession->phase() === ScopedApplySession::PHASE_PLANNED) {
                $this->scopedSession->transition(ScopedApplySession::PHASE_AUTHORING);
            }
            $authorIntent = $this->scoped_session_intent(
                1,
                'duo-scoped-authored-transaction/v1',
                'author-' . substr($this->scopedSession->authority_hash_value(), 0, 32),
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
            $this->scopedSession->append_intent($authorIntent);

            $authoredState = ScopedApply::authored_state(
                $freshActual,
                $compiled,
                $this->policy,
                $this->scopeContract,
                (string) $authority['target']['selected_before_hash']
            );
            $phase = $this->scopedSession->phase();
            if ($authoredState === 'before' && !hash_equals(
                (string) ($authority['target']['selected_before_ledger_map_hash'] ?? ''),
                (string) $this->scopedObservation['selected_ledger_map_root']
            )) {
                $this->scopedSession->recover(hash('sha256', 'duo:scoped-selected-ledger-drift'));
                throw new \RuntimeException(
                    'duo: scoped apply recovery found selected identity-map drift before authored mutation'
                );
            }
            if ($authoredState === 'before'
                && (!hash_equals(
                    (string) ($authority['plan']['precondition_hash'] ?? ''),
                    $this->plan_precondition_hash($plan)
                ) || !hash_equals(
                    (string) ($authority['plan']['guard_witnesses_hash'] ?? ''),
                    $this->scoped_guard_witnesses_hash($executeDeletes ? $deleteWork : [])
                ))) {
                $this->scopedSession->recover(hash('sha256', 'duo:scoped-plan-or-guard-drift'));
                throw new \RuntimeException(
                    'duo: scoped apply recovery found changed locked plan or deletion-guard evidence'
                );
            }
            if ($phase === ScopedApplySession::PHASE_AUTHORING) {
                if ($authoredState === 'desired') {
                    $performAuthoredTransaction = false;
                    $this->scopedSession->transition(ScopedApplySession::PHASE_AUTHORED_COMMITTED);
                    $this->scopedSession->append_receipt($this->scoped_session_receipt(
                        $authorIntent,
                        (string) $this->scopedObservation['selected_before_root']
                    ));
                } elseif ($authoredState !== 'before') {
                    $this->scopedSession->recover(hash('sha256', 'duo:scoped-authored-boundary-mixed'));
                    throw new \RuntimeException(
                        'duo: scoped apply recovery found a mixed authored boundary; no replay was attempted'
                    );
                }
            } else {
                $performAuthoredTransaction = false;
                if ($authoredState !== 'desired') {
                    $this->scopedSession->recover(hash('sha256', 'duo:scoped-authored-state-regressed'));
                    throw new \RuntimeException(
                        'duo: scoped apply recovery found selected target drift after authored commit'
                    );
                }
                if ($phase === ScopedApplySession::PHASE_AUTHORED_COMMITTED) {
                    $this->scopedSession->append_receipt($this->scoped_session_receipt(
                        $authorIntent,
                        (string) $this->scopedObservation['selected_before_root']
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
        if (!$scoped) {
            Ledger::kv_set('apply_in_progress', '1');
        }
        $authored = $this->authored_transaction_executor()->execute(
            $plan,
            $tree,
            $work,
            $deleteWork,
            $deleteUuids,
            $guardRepairUuids,
            $compiled->deletions(),
            $executeDeletes,
            $scoped,
            $this->scopeContract,
            !empty($opts['with_deletes']),
            !empty($opts['force_delete_referenced']),
            $performAuthoredTransaction,
            $this->defaultAuthor,
            $this->warnings
        );
        $attachmentIds = $authored['attachment_ids'];
        $regenContext = $authored['regen_context'];

        if ($scoped && $this->scopedSession !== null) {
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
                    $this->scopeContract,
                    $afterActual,
                    $this->scoped_ledger_map_identity_hashes(),
                    false
                );
                if (!hash_equals(
                    (string) $this->scopedSession->authority()['target']['protected_out_of_scope_hash'],
                    (string) $afterObservation['protected_out_of_scope_root']
                ) || !hash_equals(
                    (string) $this->scopedSession->authority()['target']['protected_ledger_map_hash'],
                    (string) $afterObservation['protected_ledger_map_root']
                ) || ScopedApply::authored_state(
                    $afterActual,
                    $compiled,
                    $this->policy,
                    $this->scopeContract,
                    (string) $this->scopedSession->authority()['target']['selected_before_hash']
                ) !== 'desired') {
                    $this->scopedSession->recover(hash('sha256', 'duo:scoped-authored-commit-readback-mismatch'));
                    throw new \RuntimeException('duo: scoped authored transaction committed without exact bounded readback');
                }
                $this->scopedObservation = $afterObservation;
                $this->scopedSession->transition(ScopedApplySession::PHASE_AUTHORED_COMMITTED);
                $this->scopedSession->append_receipt($this->scoped_session_receipt(
                    $authorIntent,
                    (string) $afterObservation['selected_before_root']
                ));
            }
            if ($this->scopedSession->phase() === ScopedApplySession::PHASE_AUTHORED_COMMITTED) {
                $this->scopedSession->transition(ScopedApplySession::PHASE_EFFECTS_PENDING);
            }
        }

        $this->renew_promotion_lock('apply-rebuild');

        $skipScopedCore = false;
        $scopedCoreComplete = null;
        if ($scoped && $this->scopedSession !== null) {
            $coreInputHash = hash('sha256', Canon::encode([
                'work' => $this->scopedSession->authority()['selection']['work_hash'],
                'deletions' => $this->scopedSession->authority()['selection']['deletions_hash'],
                'surfaces' => $this->rebuild_surfaces($work, $tree, $rebuildDeleteWork),
            ]));
            $coreIntent = $this->scoped_session_intent(
                2,
                'duo-scoped-engine-derived-effects/v1',
                'effects-core-' . substr($this->scopedSession->authority_hash_value(), 0, 24),
                $coreInputHash,
                hash('sha256', Canon::encode([
                    'future_schedule' => true,
                    'taxonomy_counts' => true,
                    'attachment_metadata' => false,
                ])),
                (string) $this->scopedObservation['selected_before_root']
            );
            $this->scopedSession->append_intent($coreIntent);
            $hasCoreWork = $work !== [] || ($executeDeletes && $deleteWork !== []);
            if (!$hasCoreWork && $this->scoped_receipt_at(2) === null) {
                $this->scopedSession->append_receipt($this->scoped_session_receipt(
                    $coreIntent,
                    hash('sha256', 'duo:scoped-engine-effects-bounded-noop')
                ));
            }
            if ($this->scoped_receipt_at(2) !== null) {
                $coreReadbackHash = $hasCoreWork
                    ? $this->scoped_core_readback_hash($work, $tree)
                    : hash('sha256', 'duo:scoped-engine-effects-bounded-noop');
                ScopedApplySession::require_effect_receipt_hash(
                    $this->scoped_receipt_at(2),
                    $coreReadbackHash
                );
            }
            $skipScopedCore = !$hasCoreWork || $this->scoped_receipt_at(2) !== null;
            $scopedCoreComplete = function () use ($coreIntent, $work, $tree): void {
                if ($this->scopedSession === null || $this->scoped_receipt_at(2) !== null) {
                    return;
                }
                $this->scopedSession->append_receipt($this->scoped_session_receipt(
                    $coreIntent,
                    $this->scoped_core_readback_hash($work, $tree)
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
        $this->rebuild(
            $attachmentIds,
            $work,
            $tree,
            $regenContext,
            $deleteWork,
            $executeDeletes,
            $plan['deleted'],
            $scoped,
            $skipScopedCore,
            $scopedCoreComplete,
            $scopedPromotion
        );

        if ($scoped && $this->scopedSession !== null) {
            if ($this->scoped_receipt_at(2) === null) {
                $this->scopedSession->recover(hash('sha256', 'duo:scoped-core-effect-receipt-missing'));
                throw new \RuntimeException('duo: scoped engine effects completed without a durable readback receipt');
            }
            if ($this->scopedSession->phase() === ScopedApplySession::PHASE_EFFECTS_PENDING) {
                $this->scopedSession->transition(ScopedApplySession::PHASE_VERIFYING);
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
        $verification = $this->verify_convergence($opts, $compiled);

        // ---- ledger bookkeeping (one atomic convergence boundary) ----
        // The retry marker, every base hash, deletes, and applied revision
        // move together. A failure at any one statement or at COMMIT rolls
        // the whole metadata transition back, retaining apply_in_progress so
        // the next run replays required finalization/rebuild work.
        $this->apply_ledger_finalizer()->finalize(
            $compiled,
            $plan,
            $tree,
            $work,
            $deleteWork,
            $executeDeletes,
            $scoped,
            $this->scopeContract,
            $this->scopedSession,
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
            if ($this->scopedSession === null || !$this->scopedSession->is_terminal()) {
                throw new \RuntimeException('duo: scoped apply completed without a durable terminal receipt');
            }
            $summary['format'] = 'duo-scoped-apply-result/v1';
            $summary['scoped_receipt'] = $this->scopedSession->terminal_receipt();
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

    /**
     * Enforce the deliberately asymmetric code/state preconditions.
     *
     * Lifecycle compatibility rows retain their explicit force escape hatch.
     * A code_revision_stale row does not: it means this artifact's code
     * descriptor has not completed stage -> lifecycle -> finalize on this
     * environment, so state writes would cross the code/state ordering
     * boundary without a verified payload witness.
     *
     * @param list<array<string,mixed>> $mismatches
     * @param array<string,mixed> $opts
     * @return list<array<string,mixed>> forceable lifecycle mismatches
     */
    private static function enforce_code_mismatch_gate(array $mismatches, array $opts): array {
        $nonForceable = array_values(array_filter(
            $mismatches,
            static fn(array $r): bool => ($r['issue'] ?? null) === 'code_revision_stale'
                || !empty($r['non_forceable'])
        ));
        if ($nonForceable) {
            $list = implode("\n\n", array_map(
                fn(array $r): string => '  - ' . ($r['message'] ?? 'non-forceable code compatibility blocker'),
                $nonForceable
            ));
            $runtime = array_values(array_filter(
                $nonForceable,
                static fn(array $r): bool => !empty($r['non_forceable'])
                    && ($r['issue'] ?? null) !== 'code_revision_stale'
            ));
            if ($runtime) {
                throw new \RuntimeException(
                    "duo: apply refused — code runtime compatibility is non-forceable:\n\n$list\n\n"
                    . 'Correct the declared Requires PHP/Requires at least header or use a target that reports compatible runtime versions. '
                    . 'Neither --force-code-mismatch nor Duo certification-baseline evidence overrides a component runtime requirement.'
                );
            }
            throw new \RuntimeException(
                "duo: apply refused — code_revision_stale:\n\n$list\n\n"
                . "Run the host 'duo deploy <env>' workflow to stage, reconcile, verify, and finalize the exact code payload. "
                . 'This ordering invariant is non-forceable; neither --force-code-mismatch nor --force-code-drift overrides it.'
            );
        }

        $forceable = array_values(array_filter(
            $mismatches,
            static fn(array $r): bool => ($r['issue'] ?? null) !== 'code_revision_stale'
                && empty($r['non_forceable'])
        ));
        if ($forceable && empty($opts['force_code_mismatch'])) {
            $list = implode("\n\n", array_map(fn(array $r): string => '  - ' . ($r['message'] ?? 'code mismatch'), $forceable));
            throw new \RuntimeException(
                "duo: apply refused — code_mismatch:\n\n$list\n\n"
                . "Run 'duo deploy <env>' first for lifecycle reconciliation, "
                . 'or pass --force-code-mismatch to proceed despite those lifecycle mismatches.'
            );
        }
        return $forceable;
    }

    /** Thin compatibility facade over ApplyPlanner::plan_precondition_hash(). */
    private function plan_precondition_hash(array $plan): string {
        return ApplyPlanner::plan_precondition_hash($plan);
    }

    /** Mint the immutable execution authority only after the locked recheck. */
    private function scoped_authority(
        array $plan,
        array $work,
        array $deleteWork,
        array $negotiation,
        CompiledRepository $compiled,
        bool $allowDeletes
    ): array {
        if ($this->scopeContract === null || $this->scopedObservation === null) {
            throw new \RuntimeException('duo: scoped mutation authority has no complete source/target evidence');
        }
        return ScopedApplyCoordinator::authority(
            $plan,
            $work,
            $deleteWork,
            $negotiation,
            $compiled,
            $allowDeletes,
            $this->scopeContract,
            $this->scopedObservation,
            $this->selectedActions,
            $this->promotionOwner,
            $this->promotionArtifact,
            $this->scopedPromotionWitness
        );
    }

    /** @return ?list<string> opaque SHA-256 UUID hashes sealed by an active authority */
    private function scoped_ledger_map_identity_hashes(): ?array {
        return ScopedApplyCoordinator::session_ledger_map_identity_hashes($this->scopedSession);
    }

    /**
     * Initial observation may admit an exact target-only draft/trash
     * menu-item map because finalize_menu() will remove it, and may inventory
     * physical items under a selected menu tombstone because delete_entity()
     * will cascade them. A retained authority can admit either only while its
     * selected target root is still exactly pre-authoring. This distinguishes
     * an authoring-phase response loss whose authored rows are already desired
     * from a genuine retry of the before-state; every other phase is
     * fail-closed.
     */
    private function scoped_allows_target_old_menu_items(array $actual): bool {
        return ScopedApplyCoordinator::allows_target_old_menu_items(
            $this->scopedSession,
            $this->scopeContract,
            $actual
        );
    }

    /** @return list<string> opaque SHA-256 UUID hashes derived before authority minting */
    private function scoped_ledger_map_identity_hashes_from_observation(): array {
        if ($this->scopedObservation === null) {
            throw new \RuntimeException('duo: scoped mutation authority has no ledger-map identity observation');
        }
        return ScopedApplyCoordinator::observation_ledger_map_identity_hashes($this->scopedObservation);
    }

    /** @return list<string> */
    private function assert_scoped_ledger_map_identity_hashes(mixed $hashes, string $source): array {
        return ScopedApplyCoordinator::assert_ledger_map_identity_hashes($hashes, $source);
    }

    private function scoped_guard_witnesses_hash(array $deleteWork): string {
        return ScopedApplyCoordinator::guard_witnesses_hash($deleteWork);
    }

    /** Re-prove immutable action declarations and scoped capability digests on recovery. */
    private function assert_scoped_recovery_selection(array $negotiation): void {
        if ($this->scopedSession === null) {
            throw new \RuntimeException('duo: scoped action recovery has no durable authority');
        }
        ScopedApplyCoordinator::assert_recovery_selection(
            $this->scopedSession,
            $this->selectedActions,
            $negotiation
        );
    }

    /** Build one value-free, authority/lease-bound durable mutation intent. */
    private function scoped_session_intent(
        int $ordinal,
        string $actionIdentity,
        string $operationIdentity,
        string $inputHash,
        string $effectHash,
        string $beforeHash
    ): array {
        if ($this->scopedSession === null) {
            throw new \RuntimeException('duo: scoped mutation intent has no durable session');
        }
        return ScopedApplyCoordinator::intent(
            $this->scopedSession,
            $ordinal,
            $actionIdentity,
            $operationIdentity,
            $inputHash,
            $effectHash,
            $beforeHash
        );
    }

    /** Bind a post-operation readback hash to an exact persisted intent. */
    private function scoped_session_receipt(array $intent, string $afterHash): array {
        return ScopedApplyCoordinator::receipt($intent, $afterHash);
    }

    private function scoped_action_effect_hash(array $action): string {
        return ScopedApplyCoordinator::action_effect_hash($action);
    }

    /** @return array{authority_hash:string,lease_session_id:string,operation_id:string,input_hash:string,effect_hash:string} */
    private function scoped_effect_operation(int $ordinal, string $inputHash, string $effectHash): array {
        if ($this->scopedSession === null) {
            throw new \RuntimeException('duo: scoped effect has no durable session');
        }
        return ScopedApplyCoordinator::effect_operation(
            $this->scopedSession,
            $ordinal,
            $inputHash,
            $effectHash
        );
    }

    /** Require the reviewed, hash-only scoped effect receipt to bind exactly. */
    private function assert_scoped_effect_result(
        array $result,
        array $operation,
        string $capabilityDigest
    ): void {
        ScopedApplyCoordinator::assert_effect_result($result, $operation, $capabilityDigest);
    }

    /** Hash-only public action evidence; provider/native raw values never escape. */
    private function scoped_public_action_receipt(
        string $source,
        string $kind,
        array $operation,
        string $capabilityDigest,
        ?array $receipt,
        string $status = 'verified'
    ): array {
        return ScopedApplyCoordinator::public_action_receipt(
            $source,
            $kind,
            $operation,
            $capabilityDigest,
            $receipt,
            $status
        );
    }

    /** @return array<string,mixed>|null */
    private function scoped_receipt_at(int $ordinal): ?array {
        return ScopedApplyCoordinator::receipt_at($this->scopedSession, $ordinal);
    }

    /** Hash-only checked projection of engine-owned derived effects. */
    private function scoped_core_readback_hash(array $work, array $tree): string {
        return ScopedApplyCoordinator::core_readback_hash($this->policy, $work, $tree);
    }

    private function renew_promotion_lock(string $phase): void {
        PromotionLock::heartbeat($this->promotionOwner, $this->promotionArtifact, $phase);
    }

    /**
     * Acquire every deletion guard's indexed current-read boundary before
     * authored phase-2 repairs begin, and prove that the live witness still
     * equals the one used by the completed fresh plan. A changed witness is
     * never forceable: --force-delete-referenced authorizes deletion of a
     * known, stable reference, not clobbering a target write which arrived
     * after planning.
     */
    private function lock_and_revalidate_delete_guards(
        array $deleteWork,
        array $deleteUuids,
        array $deletions,
        array $tree,
        array $guardRepairUuids
    ): void {
        $this->assert_delete_lock_isolation();
        // This is deliberately inside the authored transaction and before
        // the first guard SELECT ... FOR UPDATE.  InnoDB's record/gap-lock
        // semantics are not available on MyISAM (or an unknown engine), so
        // accepting a locking read there would claim a race boundary that
        // the storage engine cannot provide.  Keep this scope to the guard
        // tables actually exercised by this delete work; unrelated manifest
        // tables must not make a deletion fail closed.
        $this->assert_delete_guard_engines($deleteWork);
        DeleteGuardEvaluator::assert_revalidated_witnesses(
            $deleteWork,
            function (array $row): array {
                $capability = Deletion::capability(
                    $this->policy,
                    (string) $row['deletion_kind'],
                    (string) $row['deletion_type']
                );
                return (array) ($capability['guards'] ?? []);
            },
            function (array $guard, string $targetUuid, bool $lock) use (
                $deleteUuids,
                $deletions,
                $tree,
                $guardRepairUuids
            ): array {
                return $this->count_guard_refs(
                    $guard,
                    $targetUuid,
                    $deleteUuids,
                    $deletions,
                    $tree,
                    $guardRepairUuids,
                    $lock
                );
            }
        );
    }

    /**
     * Prove that every actual table on which this delete work will take a
     * guard lock exists and is InnoDB.  SHOW TABLES answers only existence;
     * information_schema.TABLES is the authoritative source for both the
     * row and its storage engine.  This runs after START TRANSACTION and
     * before any authored mutation.  apply_in_progress was written before
     * START TRANSACTION, so a refusal follows the normal rollback/failure
     * marker path while leaving authored target state untouched.
     */
    private function assert_delete_guard_engines(array $deleteWork): void {
        global $wpdb;

        $tables = [];
        $invalidGuards = [];
        foreach ($deleteWork as $row) {
            $capability = Deletion::capability(
                $this->policy,
                (string) ($row['deletion_kind'] ?? ''),
                (string) ($row['deletion_type'] ?? '')
            );
            foreach ($capability['guards'] ?? [] as $guard) {
                $declared = preg_replace(
                    '/[^A-Za-z0-9_]/',
                    '',
                    (string) ($guard['table'] ?? '')
                );
                if ($declared === '') {
                    $invalidGuards[] = (string) ($guard['table'] ?? '');
                    continue;
                }
                // Keep this exactly in step with count_guard_refs(), whose
                // SQL reads the same prefix + sanitized manifest name.
                $tables[(string) $wpdb->prefix . $declared] = true;
            }
        }

        if ($invalidGuards) {
            sort($invalidGuards, SORT_STRING);
            throw new \RuntimeException(
                'duo: deletion guard locking refused — guard declaration has no usable table name: '
                . implode(', ', $invalidGuards)
            );
        }
        if (!$tables) {
            return;
        }

        DeleteGuardEvaluator::assert_innodb_tables(array_keys($tables));
    }

    /**
     * Gap locks are part of this race boundary. Refuse a target whose
     * isolation level would provide only record locks, because an external
     * insert could then pass the locked read and become a dangling reference.
     */
    private function assert_delete_lock_isolation(): void {
        DeleteGuardEvaluator::assert_transaction_isolation();
    }

    private function recheck_delete_guards(
        array $row,
        array $deleteUuids,
        array $deletions,
        bool $forced,
        array $tree = [],
        array $guardRepairUuids = [],
        bool $forUpdate = false
    ): void {
        $capability = Deletion::capability(
            $this->policy,
            (string) $row['deletion_kind'],
            (string) $row['deletion_type']
        );
        $findings = DeleteGuardEvaluator::final_recheck_findings(
            $row,
            (array) ($capability['guards'] ?? []),
            function (array $guard, bool $lock) use (
                $row,
                $deleteUuids,
                $deletions,
                $tree,
                $guardRepairUuids
            ): array {
                return $this->count_guard_refs(
                    $guard,
                    (string) $row['uuid'],
                    $deleteUuids,
                    $deletions,
                    $tree,
                    $guardRepairUuids,
                    $lock
                );
            },
            fn(string $table): bool => isset($this->snapshotRowTables()[$table]),
            $forced,
            $forUpdate
        );
        $blocks = $findings['blocks'];
        $guardRefs = $findings['guard_refs'];
        if (!$blocks) {
            return;
        }
        $row['blocked'] = implode('; ', $blocks);
        $row['guard_refs'] = $guardRefs;
        $this->warn_forced_guard_refs($row, 'FORCED delete after final guard recheck');
    }

    /**
     * Mandatory post-apply canonical convergence gate (DUO-3220). Delegates
     * to ConvergenceVerifier (DUO-3347 slice 1) with exactly the state it
     * needs; see that class for the gate's rationale and both the launched
     * and scoped-launched paths.
     *
     * @return array{verifier:string,result:string,live_entities:int,deletions:int}
     */
    private function verify_convergence(array $opts, CompiledRepository $compiled): array {
        return $this->convergence_verifier()->verify($opts, $compiled);
    }

    private function convergence_verifier(): ConvergenceVerifier {
        return new ConvergenceVerifier(
            $this->repo,
            $this->policy,
            $this->scopeContract,
            $this->scopedObservation,
            $this->scopedSession
        );
    }

    /** @param array<string,mixed> $entity */
    private function verification_hash(array $entity): string {
        return ConvergenceVerifier::hash($entity);
    }

    // ------------------------------------------------------- entity plumbing

    private function adopt(array $row, array $e): void {
        $this->entity_adopter()->adopt($row, $e, $this->warnings);
    }

    private function ensure_term_row(array $front, string $entityType): void {
        $this->term_materializer()->ensure_term_row($front, $entityType);
    }

    /** @return bool true when a new row was inserted */
    /**
     * Thin compatibility facade over PostMaterializer::ensure_post_row()
     * (DUO-3347 slice 10) — kept so this method's existing internal call
     * site (run()'s phase-1 loop, unchanged) needs no edit while this
     * decomposition proceeds.
     */
    private function ensure_post_row(array $front): bool {
        return $this->post_materializer()->ensure_post_row($front);
    }

    /**
     * Thin compatibility facade over TermMaterializer::finalize_term()
     * (DUO-3347 slice 6) — kept so this method's existing internal call site
     * (run(), unchanged) needs no edit while this decomposition proceeds.
     * term_object_taxes() stays here (not on TermMaterializer): it wraps
     * Apply's own memoized, WordPress-registry-reading taxes_by_object_type(),
     * shared with the still-Apply-resident post-relationship reconciler.
     */
    private function finalize_term(array $front): void {
        $this->term_materializer()->finalize_term($front, $this->term_object_taxes());
    }

    /**
     * Thin compatibility facade over PostMaterializer::finalize_post()
     * (DUO-3347 slice 11) — kept so this method's existing internal call
     * site (run(), unchanged) needs no edit while this decomposition
     * proceeds. $this->warnings is passed by reference, the same
     * array-output-parameter idiom apply_options() already uses across this
     * class boundary; $this->defaultAuthor and the memoized
     * taxes_for_post_type() roster are per-apply-run-computed state that
     * travels as explicit parameters rather than constructor collaborators,
     * matching TermMaterializer's/RelationshipMaterializer's own
     * $termObjectTaxes/$taxesForPostType precedent.
     */
    private function finalize_post(array $front, string $body): void {
        $this->post_materializer()->finalize_post(
            $front,
            $body,
            $this->defaultAuthor,
            $this->warnings,
            $this->taxes_for_post_type($front['type'])
        );
    }

    private function register_shortcode_alternates(array $tree): void {
        foreach ($this->policy->shortcode_attr_rules() as $rules) {
            foreach ($rules as $rule) {
                if (!array_key_exists('position', $rule)) {
                    continue;
                }
                $metaKey = (string) $rule['lookup']['post_meta'];
                $postType = (string) $rule['lookup']['post_type'];
                foreach ($tree as $entity) {
                    if (($entity['type'] ?? '') !== 'post'
                        || (($entity['data']['type'] ?? '') !== $postType)) {
                        continue;
                    }
                    $uuid = (string) ($entity['data']['uuid'] ?? '');
                    $value = (array) ($entity['data']['meta'] ?? []);
                    if (!array_key_exists($metaKey, $value)) {
                        continue; // modern CF7 forms have no legacy alternate id
                    }
                    $value = $value[$metaKey];
                    if (is_array($value) || !preg_match('/^[0-9]+$/D', (string) $value)) {
                        throw new \RuntimeException(
                            "duo: positional shortcode lookup '$metaKey' on $uuid is not a unique decimal authored value"
                        );
                    }
                    $this->tokens->register_shortcode_alternate(
                        '{{post:' . $uuid . '}}',
                        $metaKey,
                        $postType,
                        (string) $value
                    );
                }
            }
        }
        $this->tokens->seal_shortcode_alternates();
    }

    /**
     * Same collision guard as Capture::taxes_by_object_type(): only
     * taxonomies whose resolved object_keyspace is `post` and whose
     * registered object_type actually includes this post type may own this
     * post's relationship rows. Without it, the "current
     * relationships" SELECT above can pick up a colliding term's own
     * term-to-term rows (object_id happens to equal this post's id) and,
     * since they're never in $desiredTt, DELETE them — destroying a
     * different object's genuine data because of a numeric coincidence.
     *
     * Computed together with term_object_taxes() below (one get_taxonomy()
     * walk, one warning per unregistered taxonomy instead of two) and
     * memoized per apply run; the taxonomy roster doesn't change mid-run.
     *
     * @return array{by_post_type: array<string,string[]>, term_object: string[]}
     */
    private function taxes_by_object_type(): array {
        if ($this->taxesByObjectType === null) {
            $byPostType = [];
            $termObject = [];
            foreach ($this->policy->taxonomies() as $tax) {
                $taxObj = get_taxonomy($tax);
                // task #92: same object_type fallback as Capture's copy of
                // this method — see its comment for the full timing
                // argument (a taxonomy_patterns-matched name landed by
                // Snapshot's OWN phase-1 write this same apply request is
                // never registered in time for get_taxonomy() to see it).
                $objectTypes = $taxObj !== false ? (array) $taxObj->object_type : $this->policy->pattern_object_type($tax);
                if ($objectTypes === null) {
                    $this->warnings[] =
                        "taxonomy '$tax' is in policy scope but not registered on this environment"
                        . " (plugin inactive?) — cannot determine which object type its relationships"
                        . " belong to, so its relationships are skipped for every post and term on apply";
                    continue;
                }
                // DUO-3280: a DIFFERENT timing gap than task #92's, for a
                // taxonomy get_taxonomy() DOES find registered —
                // register_taxonomy() fixes its object_type array once,
                // for the entire process, at `init`, which already ran
                // before THIS SAME request's own sub_keys option merge
                // (Polylang: `polylang.post_types` names which extra post
                // types `language`/`post_translations` additionally cover
                // — manifests/polylang.json's own "PHP-process-boundary
                // timing nuance" note). A manifest-declared
                // object_type_from_option (Policy::object_type_option_ref())
                // supplies exactly those extra object types, sourced from
                // THIS apply's own compiled tree (see
                // option_driven_object_type() below for why the compiled
                // tree, not a live $wpdb read) — immune to the
                // frozen-registry staleness because it never consults
                // get_taxonomy()'s in-memory snapshot at all. The
                // cross-process shadow of this same frozen-registry family
                // is #61's PLL_Cache trace (get_translated_object_types(),
                // healed only by a fresh process). Purely additive, never
                // a replacement: $tax may legitimately have a non-empty
                // base object_type with zero option-driven entries.
                $objectTypes = array_values(array_unique(array_merge(
                    $objectTypes,
                    $this->option_driven_object_type($tax)
                )));
                // Resolve the relationship keyspace before using runtime
                // (or declared option-driven) object_type to determine
                // post-type membership. An undeclared term/mixed taxonomy
                // is a loud refusal, not an engine sentinel inference.
                $keyspace = $this->policy->taxonomy_object_keyspace($tax, $objectTypes);
                if ($keyspace === 'term') {
                    $termObject[] = $tax;
                    continue;
                }
                foreach ($objectTypes as $objectType) {
                    $byPostType[$objectType][] = $tax;
                }
            }
            $this->taxesByObjectType = ['by_post_type' => $byPostType, 'term_object' => $termObject];
        }
        return $this->taxesByObjectType;
    }

    /**
     * DUO-3280: the live half of Policy::object_type_option_ref()'s
     * declaration — resolves to a plain list of extra object types.
     *
     * Reads THIS APPLY'S OWN COMPILED TREE (the options/core entity's
     * captured value for the declared sub-key), never a live $wpdb SELECT
     * of the option's current row. That looks like it contradicts "read
     * the committed value, not any snapshot" — it doesn't, once phase-2
     * ordering is accounted for: 'options' and an ordinary post/term share
     * the SAME phase2_rank() (1), and $work's pre-sort order concatenates
     * plan['create'] before plan['update'] (usort() is stable on PHP 8,
     * task #10) — so on a fresh target, brand-new posts of a
     * newly-enabled type are ALWAYS finalized before the `polylang`
     * option's own sub_keys merge (always plan['update'], since the
     * option row already exists). A live DB read at that moment would
     * see the OLD, pre-merge row — proven live, not assumed: the first
     * version of this method read $wpdb directly and reproduced this
     * exact failure on a genuinely fresh target. Reordering phase2_rank()
     * itself was considered and rejected: `nav_menus` (a DIFFERENT
     * sub-key of this SAME option) is json_refs-typed against menu TERMS,
     * which must exist before ITS OWN ref resolution runs, so promoting
     * `polylang` broadly to 'early' would trade this bug for a menu-ref
     * resolution failure instead.
     *
     * The compiled tree's own desired value is the correct source
     * instead: `run()` wraps ALL of phase 1 and phase 2 in one DB
     * transaction (Db::start(), see run()'s own doc comment) that only
     * commits after post-apply convergence verification succeeds — so
     * for any apply that ultimately succeeds, "what the compiled tree
     * says this sub-key will hold" and "what ends up committed" are the
     * SAME value by construction (apply_option_sub_keys()'s own merge
     * loop assigns the captured value for a declared authored sub-key
     * verbatim, modulo apply_value()'s ref/structure decoding — a no-op
     * here, since object-type-driving sub-keys are plain post-type slug
     * lists, never ref-typed; validate_object_type_option_refs() enforces
     * that at load time). For an apply that FAILS, the transaction rolls
     * back entirely, taking this relationship write back with it — there
     * is no scenario where this reads a value that never actually lands.
     * This is a strictly SAFER source than a live read: it cannot observe
     * a partially-applied intermediate state at all, by construction,
     * regardless of any future change to phase-2 ordering.
     *
     * @return string[]
     */
    private function option_driven_object_type(string $tax): array {
        $ref = $this->policy->object_type_option_ref($tax);
        if ($ref === null) {
            return [];
        }
        $optionsEntity = $this->compiled->tree()['options/core'] ?? null;
        if (!is_array($optionsEntity) || !is_array($optionsEntity['data'] ?? null)) {
            return [];
        }
        try {
            $records = OptionState::records($optionsEntity['data']);
        } catch (\Throwable $t) {
            return [];
        }
        $record = $records[$ref['option']] ?? null;
        if (($record['state'] ?? '') !== 'present' || !is_array($record['value'] ?? null)) {
            return [];
        }
        $subVal = $record['value'][$ref['sub_key']] ?? null;
        if (!is_array($subVal)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $subVal)));
    }

    private function taxes_for_post_type(string $postType): array {
        return $this->taxes_by_object_type()['by_post_type'][$postType] ?? [];
    }

    /** @return string[] policy-scoped taxonomies whose resolved object_keyspace is `term`. */
    private function term_object_taxes(): array {
        return $this->taxes_by_object_type()['term_object'];
    }

    /**
     * Thin compatibility facade over MenuMaterializer::finalize_menu()
     * (DUO-3347 slice 4) — kept so this method's existing internal call site
     * (run(), unchanged) needs no edit while this decomposition proceeds.
     */
    private function finalize_menu(array $front): void {
        $this->menu_materializer()->finalize_menu($front);
    }

    /**
     * Thin compatibility facade over MenuMaterializer::assign_locations()
     * (DUO-3347 slice 4). DUO-3347 slice 12 moved delete_entity() itself off
     * Apply, so this method's own last production call site is gone; it is
     * kept solely because regress_lifecycle_options_snapshot.php invokes it
     * via ReflectionMethod(Apply::class, 'assign_locations') for a genuine
     * behavioral test of exact serialized-array merge/deletion semantics —
     * a hidden reflection-based caller, not a bare method-name mention,
     * caught by grepping for it before this slice's code was written.
     */
    private function assign_locations(int $menuTermId, array $locations): void {
        $this->menu_materializer()->assign_locations($menuTermId, $locations);
    }

    /**
     * Thin compatibility facade over OptionsMaterializer::apply_options()
     * (DUO-3347 slice 7) — kept so this method's existing internal call site
     * (run(), unchanged) needs no edit while this decomposition proceeds.
     * $this->warnings is passed by reference: apply_option_sub_keys()'s own
     * diagnostics (see OptionsMaterializer's docblock) are appended directly
     * into Apply's own collection, the same array-output-parameter idiom
     * already used by PostMaterializer::finalize_post() and
     * DeleteExecutor::delete_entity().
     */
    private function apply_options(array $document, bool $withDeletes, ?array $classificationDocument = null): void {
        $this->options_materializer()->apply_options(
            $document,
            $withDeletes,
            $this->warnings,
            $classificationDocument
        );
    }

    private function upsert_option(string $name, string $value, string $autoload): void {
        $this->field_materializer()->upsert_option($name, $value, $autoload);
    }

    /**
     * WordPress's maybe_serialize() deliberately leaves scalar null/false
     * alone, after which wpdb coerces both to an empty SQL string. That is
     * lossy for a canonical format which explicitly distinguishes null,
     * false, and "". Serialize those two scalar types explicitly; retain
     * WordPress's ordinary encoding for arrays/objects and string scalars.
     */
    private function option_wire_value($value): string {
        return $this->field_materializer()->option_wire_value($value);
    }

    /**
     * Termmeta counterpart of reconcile_authored_meta(). Only keys the
     * current policy still classifies authored are deletion-owned; every
     * undeclared/runtime/env/derived target row remains byte-untouched.
     */
    private function reconcile_authored_term_meta(int $termId, array $frontMeta): void {
        $this->field_materializer()->reconcile_authored_term_meta($termId, $frontMeta);
    }

    /**
     * Thin compatibility facade over UserMetaMaterializer::finalize_user_meta()
     * (DUO-3347 slice 5) — kept so this method's existing internal call site
     * (run(), unchanged) needs no edit while this decomposition proceeds.
     */
    private function finalize_user_meta(array $front): void {
        $this->user_meta_materializer()->finalize_user_meta($front);
    }

    /** $value null writes a real SQL NULL — byte-faithful to plugins that store
     *  NULL meta_value themselves (WooCommerce's date_expires on non-expiring
     *  coupons); never a "delete the row" semantic. */
    private function upsert_meta(
        string $table,
        string $fkCol,
        int $objectId,
        string $key,
        ?string $value,
        ?string $context = null,
        string $idCol = 'meta_id'
    ): void {
        $this->field_materializer()->upsert_meta($table, $fkCol, $objectId, $key, $value, $context, $idCol);
    }

    /**
     * Thin compatibility facade over DeleteExecutor::delete_entity()
     * (DUO-3347 slice 12) — kept so this method's existing internal call
     * site (run()'s own delete loop, unchanged) needs no edit while this
     * decomposition proceeds. $this->snapshotRowTables() and $this->warnings
     * travel as explicit parameters (the latter by reference), matching
     * every prior slice's established idiom for shared, memoized/mutable,
     * per-call-computed state.
     *
     * The trailing REGEN_PENDING_PREFIX marker cleanup (DUO-3234) stays here
     * rather than moving into DeleteExecutor: clearing a stale regen-pending
     * marker for a uuid that no longer resolves to anything is
     * reconciliation bookkeeping (this issue's own separately-named
     * ReconciliationCoordinator territory, not yet extracted), not "execute
     * this entity's row deletion" — a real conceptual boundary, not just a
     * convenient place to stop. Running it after delete_executor()'s call
     * returns only reorders it after the (unrelated) "deleted $type $uuid"
     * warning append rather than before; the two never interact, so this is
     * not an observable behavior change.
     */
    private function delete_entity(string $uuid, string $type): void {
        $this->delete_executor()->delete_entity($uuid, $type, $this->snapshotRowTables(), $this->warnings);
        if ($type === 'post' && $this->scopeContract === null) {
            Ledger::kv_delete(self::REGEN_PENDING_PREFIX . $uuid);
        }
    }

    /**
     * Thin compatibility facade over PostMaterializer::resolve_login()
     * (DUO-3347 slice 11) — kept so this method's existing internal call
     * site (run(), seeding $defaultAuthor, unchanged) needs no edit while
     * this decomposition proceeds.
     */
    private function resolve_login(string $login): ?int {
        return $this->post_materializer()->resolve_login($login);
    }

    // --------------------------------------------------------------- rebuild

    private const REGEN_DELETE_CONTEXT_PREFIX = RegenerationContextStore::DELETE_PREFIX;
    private const REGEN_REPARENT_CONTEXT_PREFIX = RegenerationContextStore::REPARENT_PREFIX;

    private function regen_checked_get_var(mixed $sql, string $context): mixed {
        return $this->regeneration_context_store()->checked_get_var($sql, $context);
    }

    /**
     * Preserve the previous parent of an existing post before raw SQL moves its
     * post_parent. Plugin adapters decide whether that parent is a derived root
     * to refresh; the engine remains post-type agnostic. New rows and
     * same-parent writes are intentionally cheap no-ops; a reparent receipt is
     * durable, like a delete receipt.
     *
     * The capture is scoped to post types that have a DECLARED CONSUMER, which
     * is two things rather than one (DUO-3369 review, F2): an enabled batch
     * regen_dependency, or a provider capability in this run's negotiated
     * selection that declared the `reparents` batch channel and triggers on
     * this post type's canonical surface. Scoping it to the batch declaration
     * alone made that channel structurally empty for a provider-only manifest —
     * the capture is what writes the receipt at all, so a capability could
     * declare `reparents`, negotiate clean, and never receive a row no matter
     * what the revision moved. Widening only to declared consumers keeps the
     * original property intact: a post type nothing declares against still
     * accumulates no receipts.
     *
     * @return array<int,array<string,mixed>>
     */
    private function capture_regen_reparent_context(
        array $work,
        array $tree,
        bool $persistGenericDebt = true
    ): array {
        return $this->regeneration_context_store()->capture_reparents(
            $work,
            $tree,
            $persistGenericDebt
        );
    }

    /**
     * Does this run's negotiated selection contain a provider capability that
     * asked to be told about $channel on $surface?
     *
     * Both halves of the answer are already fixed before the first phase-1
     * write: run() resolves $this->selectedActions and negotiates
     * $this->negotiatedProviders at its pre-mutation gate, well above the
     * capture this serves. Reading the NEGOTIATED declaration rather than the
     * manifest is the point — it is the same declaration the rebuild pass will
     * assemble the channel from, so "captured" and "delivered" cannot disagree
     * about which capability asked.
     *
     * Both the null-declaration case (an action negotiation never bound) and a
     * declaration without the channel answer false: capture is a durable write,
     * and writing a receipt nothing declared would be the accumulation this
     * gate exists to prevent.
     *
     * DUO-3342 generalized this from the `reparents`-only predicate DUO-3369
     * shipped, because the delete-context capture needs the identical question
     * asked about `deletions` and the durable-marker sweep needs it asked about
     * both. The channel name is validated by Providers::declares_channel(),
     * so a mistyped one throws here rather than quietly answering "nobody
     * asked" — which would silently withhold a capture the capability's own
     * declaration did request.
     */
    private function selection_declares_channel_for(string $channel, string $surface): bool {
        foreach ($this->selectedActions as $action) {
            if (($action['kind'] ?? '') !== 'provider'
                || !in_array($surface, (array) ($action['triggers'] ?? []), true)) {
                continue;
            }
            $declaration = $this->negotiatedProviders['capabilities']
                [(string) ($action['provider'] ?? '')]
                [(string) ($action['capability'] ?? '')] ?? null;
            if (is_array($declaration) && Providers::declares_channel($declaration, $channel)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does this run's negotiated selection contain an ENTITY-SCOPED provider
     * capability triggering on $surface, whatever channels it declared?
     *
     * The retry vocabulary (`regen_pending:<uuid>`) is deliberately shared
     * between the two dispatchers rather than duplicated (DUO-3342): one
     * marker prefix, one plan/status projection, one operator-visible meaning.
     * Sharing is only safe while exactly one dispatcher OWNS a given post
     * type, which is what the negotiation-time dual-claimant refusal
     * (Providers::negotiate()) and this predicate together establish — the
     * refusal keeps a channel-declaring capability off a batch-claimed post
     * type, and this predicate is what stops regen_dependencies()' legacy
     * orphan sweep from deleting a marker the provider dispatch armed and is
     * still holding for its own retry.
     *
     * Channels are deliberately NOT consulted: a pending marker is about
     * entity work, and an entity-scoped capability receives an entity batch
     * whether or not it also asked for deletion or reparent evidence.
     */
    private function selection_declares_entity_batch_for(string $surface): bool {
        foreach ($this->selectedActions as $action) {
            if (($action['kind'] ?? '') !== 'provider'
                || !in_array($surface, (array) ($action['triggers'] ?? []), true)) {
                continue;
            }
            $declaration = $this->negotiatedProviders['capabilities']
                [(string) ($action['provider'] ?? '')]
                [(string) ($action['capability'] ?? '')] ?? null;
            if (is_array($declaration) && ($declaration['scope'] ?? '') === 'entity') {
                return true;
            }
        }
        // The selection above answers "is the owner running right now", which is
        // the right question for arming and clearing. It is the WRONG question
        // for a destructive sweep: this run's selection comes from this run's
        // authored surfaces, so an apply that touched nothing on $surface would
        // read a perfectly valid outstanding marker as an orphan and delete the
        // retry evidence — with a warning that says the manifest no longer
        // declares a regen_dependency, which for a provider-owned post type was
        // never true. A PINNED provider action triggering on the surface is the
        // conservative, run-independent answer, and it mirrors the batch path's
        // own guard one line above (regen_batch() is policy, not selection).
        return $this->pinned_provider_action_owns($surface);
    }

    /**
     * Does ANY pinned provider action trigger on $surface — by policy bytes
     * alone, no negotiation state? This is the plan projection's claimant
     * test: stable across the pre- and post-negotiation plans by
     * construction, exactly as regen_pending's projection is.
     */
    private function pinned_provider_action_triggers(string $surface): bool {
        foreach ($this->policy->actions() as $action) {
            if (($action['kind'] ?? '') === 'provider'
                && in_array($surface, (array) ($action['triggers'] ?? []), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Thin engine-boundary facade over
     * ApplyPlanner::regeneration_debt_projection(). Ledger keyspaces and
     * Policy claimant/identity checks stay here; the planner owns the shared
     * read-only rows, ordering, and warning vocabulary. Keeping this boundary
     * named and independently drivable also proves the product path without
     * making a full compiled/live build_plan() fixture part of the offline
     * contract suite.
     *
     * @return array{regen_pending:list<array>,regen_context:list<array>,warnings:list<string>}
     */
    private function regeneration_debt_projection(): array {
        return ApplyPlanner::regeneration_debt_projection(
            Ledger::kv_prefix(self::REGEN_PENDING_PREFIX),
            Ledger::kv_prefix(self::REGEN_DELETE_CONTEXT_PREFIX),
            Ledger::kv_prefix(self::REGEN_REPARENT_CONTEXT_PREFIX),
            fn(mixed $postType): bool => $this->policy->regen_dependency($postType) !== null,
            fn(string $uuid): bool => Ledger::id_for($uuid, Ledger::KIND_POST) !== null,
            fn(string $postType): bool => $this->policy->regen_batch($postType) !== null
                || $this->pinned_provider_action_triggers('post:' . $postType),
        );
    }

    /**
     * Thin engine-boundary facade over ApplyPlanner's read-only environment
     * provisioning projection. Policy resolves the declared option rules;
     * this boundary is the only part that knows how to read their live values
     * from WordPress's options table. No value is written by plan production.
     *
     * @return array{env_missing:list<array{name:string,required:bool}>,warnings:list<string>}
     */
    private function env_missing_projection(): array {
        global $wpdb;
        return ApplyPlanner::env_missing_projection(
            $this->policy->env_options(),
            fn(string $name): mixed => $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name
            ))
        );
    }

    /**
     * Is $surface claimed by a PINNED provider action, independently of what
     * this run happened to select?
     *
     * This is the run-independent half of marker ownership, and it exists for
     * one reason: a sweep is destructive, so the question it must answer is
     * "could an owner exist" rather than "is an owner running". Explicit
     * triggers only — an unscoped action claims no post type in particular, and
     * letting it claim every marker would make the sweep unreachable rather
     * than conservative.
     *
     * SCOPE, and the bound on how precisely this can answer (independent
     * review, F5): only a `scope: entity` capability can ever receive an entity
     * batch or an engine context channel, so a `scope: site` action triggering
     * on a post surface owns nothing here. Scope lives in the provider's own
     * capabilities() — code, not manifest data — so it is knowable only for a
     * provider this run actually negotiated. Where it IS known, a non-entity
     * scope disowns the surface. Where it is not (nothing this run selected
     * reached that provider, so no capability declaration was ever loaded), the
     * answer stays "owned": guessing a scope in order to authorize a delete is
     * exactly the fail-open a destructive sweep must not take, and the cost of
     * keeping is a marker that stays VISIBLE in plan/status
     * (build_plan()'s regen_context bucket) rather than one that vanishes.
     */
    private function pinned_provider_action_owns(string $surface): bool {
        foreach ($this->policy->actions() as $action) {
            if (($action['kind'] ?? '') !== 'provider'
                || !in_array($surface, (array) ($action['triggers'] ?? []), true)) {
                continue;
            }
            $declaration = $this->negotiatedProviders['capabilities']
                [(string) ($action['provider'] ?? '')]
                [(string) ($action['capability'] ?? '')] ?? null;
            if (!is_array($declaration) || ($declaration['scope'] ?? '') === 'entity') {
                return true;
            }
        }
        return false;
    }

    /**
     * Did this run's selection reach $surface at all, whatever it declared?
     *
     * The third state the two predicates above cannot express, and the one the
     * durable-context sweep turns on: "an owner was running and did not want
     * this channel" is a sweep, while "no owner ran" is not evidence about
     * anything and must keep the marker.
     */
    private function selection_triggers_provider_action_for(string $surface): bool {
        foreach ($this->selectedActions as $action) {
            if (($action['kind'] ?? '') === 'provider'
                && in_array($surface, (array) ($action['triggers'] ?? []), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Merge durable/current derived-state context without dropping roots from
     * an earlier failed reparent chain. This is deliberately generic engine
     * bookkeeping: manifest adapters decide what the ids mean.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $overlay
     * @return array<string,mixed>
     */
    private function merge_regen_contexts(array $base, array $overlay): array {
        return RegenerationContext::merge($base, $overlay);
    }

    /** @param array<string,mixed> $context @return array<int,int> keyed by id */
    private function regen_context_root_ids(array $context): array {
        return RegenerationContext::root_ids($context);
    }

    /**
     * Preserve local post/parent ids needed by a manifest batch regenerator
     * before delete_entity() removes the rows.  Receipts are written only for
     * post types with an enabled manifest batch contract, so ordinary/legacy
     * deletes never accumulate a marker that no regenerator can consume.  A
     * failed apply can retry without relying on a post type that no longer
     * exists in wp_posts.  It is cleared only after the rebuild pass succeeds;
     * a later ledger/convergence failure can therefore replay the same
     * derived cleanup safely.  The generic engine removes a receipt only
     * after the adapter's exact verification (plus its declarative safety
     * check) succeeds; later canonical convergence failures do not pretend
     * that derived state is still unverified.
     *
     * DUO-3342 widened the consumer test the same way DUO-3369's review
     * widened the reparent one, and for the identical reason: scoping the
     * capture to batch declarations alone made the `deletions` channel
     * structurally unable to carry the parent/child inventory a migrating
     * adapter consumes, because the capture is what writes that inventory at
     * all. See delete_context_consumer().
     *
     * @return array<int,array{uuid:string,id:int,post_type:string,parent_id:int,child_ids:array<int,int>}>
     */
    private function capture_regen_delete_context(
        array $deleteWork,
        bool $persistGenericDebt = true
    ): array {
        return $this->regeneration_context_store()->capture_deletions(
            $deleteWork,
            $persistGenericDebt
        );
    }

    /**
     * Does this post type's deletion inventory have a DECLARED CONSUMER?
     *
     * Two of them, exactly as the reparent capture has two
     * (selection_declares_channel_for()'s own docblock): an enabled batch
     * regen_dependency, or a provider capability in THIS run's negotiated
     * selection that declared the `deletions` batch channel and triggers on
     * this post type's canonical surface.
     *
     * The property the narrow test protected is unchanged: a post type nothing
     * declares against still accumulates no receipts, so ordinary/legacy
     * deletes never leave a marker no dispatcher can consume. What changes is
     * that "consumer" now means either dispatcher — without which a capability
     * could declare `deletions`, negotiate clean, and receive rows that
     * structurally could not carry `parent_id`/`child_ids`, because nothing
     * ever took the pre-delete inventory those two come from.
     */
    private function delete_context_consumer(string $postType): bool {
        return $this->policy->regen_batch($postType) !== null
            || $this->selection_declares_channel_for('deletions', 'post:' . $postType);
    }

    /**
     * The outstanding durable DELETE receipts, normalized exactly the way
     * durable_reparent_contexts() normalizes the reparent half — same decode,
     * same "post type plus a positive captured id or drop it" test, same
     * uuid-from-key and kind-from-prefix backfill, same `_marker_key` so the
     * clear-on-success consumer can address precisely the markers it was
     * delivered, and the same read-only posture (sweeping belongs to whichever
     * pass OWNS marker lifetime, never to a reader).
     *
     * Its existence is the deletion half of the crash-safety the batch path
     * has had since DUO-3305 and the provider path did not (DUO-3342): a
     * previous apply can commit the raw delete and fail before the derived
     * cleanup, and on that retry the tombstone may no longer be in the plan at
     * all. Reading only this run's applied tombstones would make the
     * `deletions` channel silently empty on exactly the run that still owes
     * the repair.
     *
     * @return list<array<string,mixed>>
     */
    private function durable_delete_contexts(): array {
        return $this->regeneration_context_store()->durable_deletions();
    }

    /**
     * @param array<int,array{uuid:string,type:string,path?:string}> $work
     *   this run's applied entities (create+adopt+update+forced-conflict) —
     *   DUO-3234's regen_dependencies() needs the full batch.
     * @param array<string,array> $tree the full compiled repository tree,
     *   keyed by uuid — needed to resolve a $work entry's post_type
     *   ($tree[$uuid]['data']['type']).
     *
     * @param array<int,array<string,mixed>> $regenContext this run's captured
     *   pre-mutation derived-state receipts (delete and reparent), already
     *   used by regen_dependencies(); DUO-3369 also projects the reparent half
     *   into the `reparents` provider batch channel.
     * @param array<int,array<string,mixed>> $deleteWork this run's tombstone
     *   rows — the ones the with_deletes block in run() executes.
     * @param bool $withDeletes whether run() was asked to execute them.
     * @param array<int,array<string,mixed>> $absentTombstones plan['deleted']:
     *   tombstones whose entity is ALREADY absent from the target, folded in
     *   only while retrying an incomplete apply.
     *
     * Deletion rows were deliberately not a parameter between DUO-3338 and
     * DUO-3369: they only ever fed the canonical surface projection, and
     * DUO-3338 moved that projection (and the action selection it drives) into
     * run()'s pre-mutation negotiation gate. That property is unchanged — this
     * pass still never re-derives WHICH actions run — and the rows return for
     * a different job: a capability declaring the `deletions` channel needs
     * the tombstones themselves, not merely the surfaces they projected.
     *
     * They arrive as the two sources plus the gate rather than as the merged
     * set the selection used, because those two sets answer different
     * questions and only one of them is safe to hand a provider. The selection
     * set is deliberately wide — a capability must be negotiated for a surface
     * whose tombstone this run merely PLANS, since planning is what runs before
     * the mutation — while the channel is evidence about entities that are
     * actually gone. Reconstructing "applied" from the wide set here is what
     * this signature exists to make impossible.
     */
    private function rebuild(
        array $attachmentIds,
        array $work = [],
        array $tree = [],
        array $regenContext = [],
        array $deleteWork = [],
        bool $withDeletes = false,
        array $absentTombstones = [],
        bool $scoped = false,
        bool $skipScopedCore = false,
        ?callable $scopedCoreComplete = null,
        bool $suppressScopedExternalEffects = false
    ): void {
        // The `deletions` channel's content, composed once, explicitly:
        // tombstones this run APPLIED ($withDeletes is the same
        // !empty($opts['with_deletes']) that guards the delete block in run()
        // — without it every tombstone was planned and none executed, so the
        // entities are all still present), plus tombstones a previous
        // incomplete apply already made absent (genuinely-gone entities, safe
        // to report unconditionally, and only relevant while that retry is in
        // progress).
        $appliedDeletions = array_merge(
            $withDeletes ? $deleteWork : [],
            $this->retryingIncompleteApply ? $absentTombstones : []
        );

        // The durable reparent/delete markers an earlier incomplete apply left
        // behind, read HERE because regen_dependencies() below CONSUMES them:
        // its batch half sweeps a marker no dispatcher can replay
        // (regen_batch_dependencies()'s durable scan) and clears every marker
        // it successfully regenerates. Reading them afterwards would see an
        // empty keyspace on exactly the retry the `reparents` and `deletions`
        // channels exist for. Reading is all this does — sweeping stays the
        // owning pass's job, and DUO-3342 made the provider dispatch below the
        // OWNER for a surface a channel-declaring capability triggers on: that
        // pass's durable scan leaves those markers alone (and leaves alone any
        // marker whose surface a PINNED provider action claims, whatever this
        // run selected), while the clear-on-verified consumer is in the action
        // loop.
        $durableReparents = $scoped ? [] : $this->durable_reparent_contexts();
        $durableDeletions = $scoped ? [] : $this->durable_delete_contexts();

        // DUO-3234: derived tables with a hard per-entity query-availability
        // dependency — run FIRST, deliberately, since it is the only step in
        // this method that can hard-fail the whole apply; no point spending
        // time on term recounts/attachment metadata/actions first if this
        // is about to throw. It runs regardless of authored work because a
        // prior run's still-outstanding regen_pending marker must be retried
        // even when this run's $work is empty (see regen_dependencies()'s own
        // docblock).
        if (!$scoped) {
            $this->regen_dependencies($work, $tree, $regenContext);
        }

        if (!$scoped || !$skipScopedCore) {
            $this->native_rebuild_executor()->run(
                $attachmentIds,
                $work,
                $tree,
                $appliedDeletions,
                $suppressScopedExternalEffects
            );
            if ($scoped && $scopedCoreComplete !== null) {
                $scopedCoreComplete();
            }
        }

        // Manifest-declared rebuild actions (DUO-3338): the hooks we
        // deliberately skip are also what maintain plugin derived state
        // (indexables, lookup tables), so manifests declare the repair as
        // structured data — a closed native action the engine implements, or
        // a capability of a provider owned by the plugin itself. Actions with
        // exact triggers are narrowed to the canonical surfaces touched by
        // this request; declarations without triggers remain selected
        // unscoped. An empty surface set is a no-op, including a read-only
        // apply.
        //
        // The selection and every provider it reaches were resolved and
        // negotiated in run() BEFORE the first target mutation, so nothing
        // here can discover a missing or incompatible capability after the
        // authored transaction has already committed.
        // Flush the object cache BEFORE dispatching actions, not only after
        // (the post-loop flush below remains the pre-existing invariant).
        // The retired channel ran every payload in a freshly launched WP-CLI
        // process whose runtime caches started cold; providers run in THIS
        // process, which has been reading and writing through WordPress
        // caches for the whole apply and can hold stale plugin in-memory
        // models after the authored commit (verify_convergence()'s docblock
        // records the reproduced Polylang 3.8.6 case of exactly that). A
        // cold cache is the closest in-process equivalent of the retired
        // fresh-process start, so a provider's decision reads see committed
        // rows rather than this process's memoized pre-commit views.
        $this->rebuild_action_dispatcher()->dispatch(
            $this->selectedActions,
            (array) $this->negotiatedProviders,
            $work,
            $tree,
            $appliedDeletions,
            $regenContext,
            $durableReparents,
            $durableDeletions,
            $this->retryingIncompleteApply,
            $scoped,
            $this->scopedSession,
            $this->scopedObservation,
            $this->warnings,
            $this->actionReceipts
        );
    }

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
     * First protocol version: only state whose runtime writes are DB-contained
     * plus derived cache eviction. Posts/terms/menus can schedule work or call
     * arbitrary taxonomy callbacks; attachments and declared actions can touch
     * files/external systems. Those need a future scoped inverse contract.
     *
     * @param list<array<string,mixed>> $work
     * @param list<array<string,mixed>> $deleteWork
     * @param array<string,array<string,mixed>> $tree
     */
    private function assert_scoped_promotion_selection(array $work, array $deleteWork, array $tree): void {
        RebuildActionNegotiator::assert_scoped_promotion_selection(
            $this->selectedActions,
            $work,
            $deleteWork,
            $tree
        );
    }

    /**
     * Recount only when this bounded transaction can have changed taxonomy
     * membership/count inputs. This also avoids invoking arbitrary registered
     * callbacks for option/table/sidebar/user-meta-only scoped work.
     *
     * @param list<array<string,mixed>> $work
     * @param array<string,array<string,mixed>> $tree
     * @param list<array<string,mixed>> $deletions
     */
    private function scoped_work_needs_taxonomy_recount(array $work, array $tree, array $deletions): bool {
        return NativeRebuildExecutor::needs_taxonomy_recount($work, $tree, $deletions);
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






    /**
     * The outstanding durable reparent receipts, normalized exactly the way
     * regen_batch_dependencies() normalizes the same markers when it replays
     * them: decode, require a post type and a positive captured id, fill the
     * uuid from the key when the stored row predates that field, and fill the
     * kind from the prefix. Two deliberate differences from that pass, each for
     * a reason that does not apply here:
     *   - it also drops a marker whose post type declares no batch
     *     regen_dependency. That test asks "can the BATCH consumer use this",
     *     and the answer for a provider capability is a different one — keeping
     *     it would make the union a no-op for exactly the provider-only
     *     manifests the `reparents` channel was widened for.
     *   - it DELETES what it drops. This method only reads: sweeping a marker
     *     here would race the pass that owns marker lifetime, and a marker the
     *     provider path deletes is a convergence claim nothing in that path
     *     currently verifies.
     *
     * @return list<array<string,mixed>>
     */
    private function durable_reparent_contexts(): array {
        return $this->regeneration_context_store()->durable_reparents();
    }


    /**
     * Thin compatibility facade over ApplyPlanner::rebuild_work(). A plan
     * needs the same projection as apply and scoped recovery; keeping this
     * entry point avoids widening their existing call-site contracts during
     * the one-collaborator-at-a-time migration.
     *
     * @param array<string,mixed> $plan
     * @param array<string,array<string,mixed>> $tree
     * @param array<string,mixed> $opts
     * @return array{work:list<array<string,mixed>>,delete_work:list<array<string,mixed>>,rebuild_delete_work:list<array<string,mixed>>}
     */
    private function rebuild_work(
        array $plan,
        array $tree,
        array $opts,
        bool $retryingIncompleteApply,
        bool $includeScopedPromotionDrift = false
    ): array {
        return $this->apply_planner()->rebuild_work(
            $plan,
            $tree,
            $opts,
            $retryingIncompleteApply,
            $includeScopedPromotionDrift
        );
    }

    /**
     * Reconstruct the exact original bounded selection after authored COMMIT.
     * Current plan rows legitimately become unchanged/deleted at that point;
     * the immutable authority retains only hash-safe identities, which are
     * resolved against the same frozen artifact here.
     *
     * @return array{work:list<array<string,mixed>>,delete_work:list<array<string,mixed>>,rebuild_delete_work:list<array<string,mixed>>}
     */
    private function scoped_recovery_work(array $plan, CompiledRepository $compiled): array {
        if ($this->scopedSession === null) {
            throw new \RuntimeException('duo: scoped recovery has no durable session selection');
        }
        $selection = (array) ($this->scopedSession->authority()['selection'] ?? []);
        $planRows = [];
        foreach (['create', 'adopt', 'update', 'unchanged', 'drift', 'conflict'] as $bucket) {
            foreach ((array) ($plan[$bucket] ?? []) as $row) {
                $planRows[(string) ($row['uuid'] ?? '')] = $row;
            }
        }
        $treeByHash = [];
        foreach ($compiled->tree() as $identity => $entity) {
            $treeByHash[hash('sha256', (string) $identity)] = [(string) $identity, $entity];
        }
        if (ScopedApply::has_record_scoped_options($this->scopeContract)) {
            $options = $compiled->tree()['options/core'] ?? null;
            if (is_array($options)) {
                foreach (ScopedApply::selected_option_records($options['data'], $this->scopeContract) as $name => $record) {
                    $treeByHash[hash('sha256', 'options/core#' . $name)] = [
                        'options/core', $options, 'option:' . $name, $record,
                    ];
                }
            }
        }
        $work = [];
        $optionRecoveryNames = [];
        foreach ((array) ($selection['work_items'] ?? []) as $item) {
            $resolved = $treeByHash[(string) ($item['identity_hash'] ?? '')] ?? null;
            if (!is_array($resolved)) {
                throw new \RuntimeException('duo: scoped recovery work identity is absent from the frozen artifact');
            }
            [$identity, $entity] = $resolved;
            $isOptionRecord = count($resolved) === 4;
            $desiredHash = $isOptionRecord
                ? OptionState::record_hash((array) $resolved[3])
                : $this->verification_hash($entity);
            $type = $isOptionRecord ? 'option' : (string) ($entity['type'] ?? '');
            if (!hash_equals((string) ($item['type'] ?? ''), $type)
                || !hash_equals((string) ($item['desired_hash'] ?? ''), $desiredHash)) {
                throw new \RuntimeException('duo: scoped recovery work identity no longer matches its authority');
            }
            if ($isOptionRecord) {
                $optionRecoveryNames[] = substr((string) $resolved[2], strlen('option:'));
                continue;
            }
            $work[] = $planRows[$identity] ?? [
                'uuid' => $identity,
                'type' => (string) ($entity['type'] ?? ''),
                'path' => (string) ($entity['path'] ?? ''),
                'retry' => true,
            ];
        }
        if ($optionRecoveryNames !== []) {
            $work[] = ScopedApply::recovery_option_row(
                $planRows['options/core'] ?? null,
                $optionRecoveryNames,
                (array) ($compiled->tree()['options/core'] ?? [])
            );
        }
        usort($work, fn(array $a, array $b): int =>
            $this->phase2_rank($compiled->tree()[(string) $a['uuid']])
                <=> $this->phase2_rank($compiled->tree()[(string) $b['uuid']])
        );

        $deletionPlanRows = [];
        foreach (['delete', 'delete_conflict', 'deleted'] as $bucket) {
            foreach ((array) ($plan[$bucket] ?? []) as $row) {
                $deletionPlanRows[(string) ($row['uuid'] ?? '')] = $row;
            }
        }
        $deletionsByHash = [];
        foreach ($compiled->deletions() as $identity => $deletion) {
            $deletionsByHash[hash('sha256', (string) $identity)] = [(string) $identity, $deletion];
        }
        $deleteWork = [];
        foreach ((array) ($selection['deletion_items'] ?? []) as $item) {
            $resolved = $deletionsByHash[(string) ($item['identity_hash'] ?? '')] ?? null;
            if (!is_array($resolved)) {
                throw new \RuntimeException('duo: scoped recovery deletion identity is absent from the frozen artifact');
            }
            [$identity, $deletion] = $resolved;
            $data = (array) ($deletion['data'] ?? []);
            if (!hash_equals((string) ($item['receipt_hash'] ?? ''), (string) ($deletion['hash'] ?? ''))
                || !hash_equals((string) ($item['deletion_kind'] ?? ''), (string) ($data['kind'] ?? ''))
                || !hash_equals((string) ($item['deletion_type'] ?? ''), (string) ($data['type'] ?? ''))) {
                throw new \RuntimeException('duo: scoped recovery deletion no longer matches its authority');
            }
            $deleteWork[] = $deletionPlanRows[$identity] ?? [
                'uuid' => $identity,
                'type' => (string) (($data['kind'] ?? '') === 'table'
                    ? ($data['type'] ?? '')
                    : ($data['kind'] ?? '')),
                'deletion_kind' => (string) ($data['kind'] ?? ''),
                'deletion_type' => (string) ($data['type'] ?? ''),
                'path' => (string) ($deletion['path'] ?? ''),
                'expected_hash' => (string) ($data['expected_hash'] ?? ''),
                'receipt_hash' => (string) ($deletion['hash'] ?? ''),
                'retry' => true,
            ];
        }
        usort($deleteWork, fn(array $a, array $b): int =>
            $this->deletion_rank($b) <=> $this->deletion_rank($a)
                ?: ((string) $a['uuid'] <=> (string) $b['uuid'])
        );
        return [
            'work' => $work,
            'delete_work' => $deleteWork,
            'rebuild_delete_work' => $deleteWork,
        ];
    }

    /**
     * Derive exact canonical surfaces from authored work and deletion rows.
     * This is deliberately a pure projection: it never reads target ids and
     * never turns an id into a selector. The resulting keys are only compared
     * with manifest trigger literals by Policy::actions_for().
     *
     * @param array<int,array> $work
     * @param array<string,array> $tree
     * @param array<int,array> $deleteWork
     * @return list<string>
     */
    private function rebuild_surfaces(array $work, array $tree, array $deleteWork = []): array {
        return CanonicalSurfaces::for_apply(
            $work,
            $tree,
            $deleteWork,
            isset($this->policy) ? $this->policy : null
        );
    }

    /** @return list<string> */
    private function entity_rebuild_surfaces(array $entity, array $entry = []): array {
        return CanonicalSurfaces::for_entity(
            $entity,
            $entry,
            isset($this->policy) ? $this->policy : null
        );
    }

    /** @return list<string> */
    private function deletion_rebuild_surfaces(array $row): array {
        return CanonicalSurfaces::for_deletion($row);
    }

    /**
     * DUO-3234 — derived tables with a hard per-entity query-availability
     * dependency (TEC's tec_occurrences shape): after applying a post whose
     * type declares `post_types.<type>.regen_dependency`, call the declared
     * manifest-shipped regenerator, then verify the declared table/column
     * actually gained a row for it. A failure here is a hard apply failure
     * (Architecture Rulings §2 — no third "green with warnings" state) —
     * this was written to deliberately NOT follow the manifest-declared
     * rebuild step's warn-only behavior (that gap was DUO-3206, tracked
     * separately). DUO-3206 has since landed and made that step/mutations
     * fatal too, so both steps now share the same hard-fail posture; this
     * method's own marker-retry mechanics (below) remain necessary regardless
     * — DUO-3206's apply_in_progress marker forces a full-tree retry on the
     * next plan/apply, which functionally re-surfaces this method's own
     * candidates through $work, but regen_pending:<uuid> is what lets THIS
     * method resolve a stale marker (self-heal on kv_prefix()) independent of
     * whether apply_in_progress is still set, and is the more precise signal
     * if these two mechanisms are ever reconsidered together.
     *
     * Candidate set is the UNION of two sources, not just $work — this is
     * the load-bearing correctness point design review surfaced (DUO-3234's
     * Linear thread): plan's own create/update/unchanged bucketing is driven
     * by the entity's CONTENT hash, which by design never reflects a derived
     * table's state (that is exactly why tec_events/tec_occurrences classify
     * `derived` in the first place) — so an entity whose regeneration failed
     * on a PRIOR apply, but whose captured content hasn't changed since,
     * shows as 'unchanged' and never re-enters $work on a later run. Without
     * source (b) below, a hard failure followed by a plain re-run would
     * report all clear while the dependency stays broken — the exact
     * false-green retry this mechanism exists to prevent.
     *   (a) this run's $work, filtered to posts of a declared-dependency
     *       post type — the ordinary, common path.
     *   (b) any uuid still carrying a `regen_pending:<uuid>` duo_kv marker
     *       from a past failed verification, even when it is NOT in $work
     *       this run (content unchanged) — this is what makes "the next
     *       apply retries naturally" true. The marker's value is the post
     *       type (stored at set time — see below), so re-resolving it here
     *       costs nothing beyond the one kv read already required to find it.
     *
     * A stopgap, stated plainly: this marker mechanism stands in for
     * duo_state genuinely reflecting derived-dependency status — a targeted,
     * per-uuid signal, independent of DUO-3206's coarser apply_in_progress
     * (whole-apply retry-forcing on ANY rebuild-pass failure, this method's
     * failures included). The two don't conflict — apply_in_progress forces
     * this method's candidates back into $work on retry regardless, and this
     * method's own marker is a no-op once that happens — but this method's
     * marker is what makes the retry precise (one uuid, not every entity
     * currently unchanged/drift/conflict) and keeps working even if
     * apply_in_progress's own semantics change later. Worth reconsidering
     * together if DUO-3206's mechanism is ever revisited, not a reason to
     * hold this issue on it.
     *
     * Two properties a marker mechanism invisible outside this method would
     * be missing, both required by design review before this shipped:
     *   - PLAN VISIBILITY: build_plan() (read-only, above this method in the
     *     file) surfaces every live regen_pending marker into
     *     plan['regen_pending'] and $this->warnings — an operator running
     *     `duo plan` between a failed apply and its retry must not see
     *     "nothing to do" while a regen retry is silently armed. `duo
     *     status` (cli/src/PlanSummary.php) treats a non-empty
     *     regen_pending as ok=false, on the same "safe to promote?" footing
     *     as drift/conflict/collision/code_mismatch/blocked-delete: a known
     *     regen gap is not a promotable state, even though (like drift, and
     *     unlike conflict/collision) `duo apply` itself doesn't refuse on
     *     it outright.
     *   - ORPHAN SWEEP: the two `continue`-past-an-orphan branches in this
     *     method (manifest no longer declares the post type; uuid no
     *     longer resolves) actively `Ledger::kv_delete()` the marker and
     *     warn loudly, rather than leaving it to sit in duo_kv forever with
     *     nothing left to ever consult it again.
     *
     * RESOLVED (DUO-3245): the "worth reconsidering together" question above
     * was formally revisited, not just left as a standing caveat. Verdict:
     * keep both markers exactly as they are — this is deliberate,
     * complementary layering, not incidental double coverage, and no cheaper
     * unification exists that preserves what each one alone provides.
     *   - regen_pending resolves through a code path that has NOTHING to do
     *     with apply_in_progress or a failed apply at all — sandbox/tests/
     *     regress_tec_regen.sh's step (7) proves this by hand-planting a bare
     *     regen_pending:<uuid> marker with apply_in_progress never set, and
     *     watching this method find and resolve it purely off its own
     *     kv_prefix() scan. Folding regen_pending into apply_in_progress
     *     would delete that capability outright, not merely rename it.
     *   - Precision: plan.regen_pending / `duo status` name the exact
     *     uuid+post_type at risk; incomplete_apply is a blunt "something
     *     failed, retry the whole tree" signal with no entity-level detail.
     *     An operator deciding whether a specific promotion is safe benefits
     *     from knowing WHICH entity, not just THAT something might still be
     *     wrong.
     *   - Decoupling: this method's own correctness never depends on
     *     apply_in_progress's implementation, so the two can evolve
     *     independently (already true today, reconfirmed rather than
     *     assumed).
     * Both mechanisms were re-run live against current main as part of this
     * resolution (regress_tec_regen.sh, all steps including the isolation
     * proof above and the two orphan-sweep shapes in step (8)) — see
     * PlanSummary.php's own `ok` computation for the companion half of this
     * reasoning (why both flip status independently, not just one).
     */
    private const REGEN_PENDING_PREFIX = ProviderActionBatchBuilder::REGEN_PENDING_PREFIX;

    private function regen_dependencies(array $work, array $tree, array $deleteContext = []): void {
        $this->dependency_regenerator()->run(
            $work,
            $tree,
            $deleteContext,
            $this->warnings
        );
    }
}
