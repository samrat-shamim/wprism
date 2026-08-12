<?php
namespace Duo;

require_once __DIR__ . '/PlainData.php';
require_once __DIR__ . '/Providers.php';
require_once __DIR__ . '/StructuredValue.php';
require_once __DIR__ . '/CommandRefusal.php';
require_once __DIR__ . '/CanonicalSurfaces.php';
require_once __DIR__ . '/ApplyPlanner.php';
require_once __DIR__ . '/ApplyFieldMaterializer.php';
require_once __DIR__ . '/MenuMaterializer.php';
require_once __DIR__ . '/ConvergenceVerifier.php';
require_once __DIR__ . '/PlanExplanation.php';
require_once __DIR__ . '/PlanCategorySummary.php';
require_once __DIR__ . '/PlanView.php';

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
    private CompiledRepository $compiled;
    private string $repo;
    /** @var string[] */
    private array $warnings = [];
    /** @var list<array<string,mixed>> reviewed public evidence for forced plan conflicts */
    private array $forcedOverrideEvidence = [];
    /** @var array<string,int> login -> user id */
    private array $userIds = [];
    /** @var array<string,int> exact (binary) login -> user id */
    private array $exactUserIds = [];
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
        $tree = $compiled->tree();
        $this->check_theme_mismatch($tree);
        // Capture::snapshot() runs the SAME build() capture.php's own `duo
        // capture` does (drift detection needs the live environment's
        // current canonical view) — so it hits the identical task #73
        // loud-and-blocking gate on an unscoped ref-typed option. Threaded
        // through so plan/apply have the same escape hatch `duo capture`
        // does, matching this file's existing --force-* precedents.
        $planObservations = null;
        if ($strictObservation) {
            $env = Capture::snapshot_read_only(
                $this->repo,
                !empty($opts['force_unresolved_refs']),
                $compiled,
                $this->policy
            );
        } else {
            $env = Capture::snapshot(
                $this->repo,
                !empty($opts['force_unresolved_refs']),
                $compiled,
                $this->policy,
                $planObservations
            );
        }
        $base = Ledger::all_state();
        $adopt = array_fill_keys(array_filter(explode(',', $opts['adopt_by_slug'] ?? '')), true);
        $lifecycleTransition = null;
        $promotionOwner = (string) ($opts['promotion_owner'] ?? '');
        if ($promotionOwner !== '') {
            $lifecycleTransition = PromotionLock::state_transition(
                $promotionOwner,
                $compiled->artifact_hash(),
                'options/core'
            );
        }

        $certificationBlockers = $diagnoseAdapters
            ? $this->policy->certification_readiness_blockers()
            : [];
        $this->categorySummaryContext = null;
        $plan = [
            'create' => [], 'update' => [], 'unchanged' => [], 'drift' => [],
            'conflict' => [], 'adopt' => [], 'collision' => [], 'delete' => [],
            'delete_conflict' => [], 'deleted' => [],
            'code_mismatch' => [], 'code_drift' => [], 'incomplete_apply' => [],
            'incomplete_lifecycle' => [],
            'missing_user' => [], 'skipped_user_meta' => [],
            // DUO-3297: complete immutable declaration available before any
            // target mutation. Runtime journaling resolves these bounded
            // original/derivative roots into exact before-image and absence
            // receipts; this list itself never claims that an undeclared
            // derivative is safe.
            'uploads_inventory' => $compiled->uploads_inventory(),
            'effects_inventory' => $compiled->effects_inventory(),
            // Source/evidence facts are plan-independent. Provider runtime
            // blockers are appended at the end from this plan's exact
            // rebuild-surface selection; see rebuild_work() below.
            'adapter_dispositions' => $certificationBlockers,
        ];
        $collisionCache = [];
        foreach ($tree as $uuid => $e) {
            $fileH = $e['hash'];
            $envE = $env[$uuid] ?? null;
            $baseH = $base[$uuid]['content_hash'] ?? null;
            $comparisonEnvH = self::lifecycle_comparison_hash(
                (string) $uuid,
                $envE['hash'] ?? null,
                $lifecycleTransition
            );
            $row = ['uuid' => $uuid, 'type' => $e['type'], 'path' => $e['path']];
            $title = self::entity_display_title($e['data']);
            if ($title !== null) {
                $row['title'] = $title;
            }
            $this->annotate_natural_key_continuity(
                $row,
                (string) $uuid,
                (string) $e['type'],
                $e['data'],
                $envE
            );
            if ($uuid === 'options/core') {
                // Carry only the exact option records whose canonical state
                // differs from the captured target. A retry row is widened
                // back to all desired records in rebuild_surfaces(), because
                // its prior authored mutation may already be target-equal.
                $row['rebuild_option_names'] = $this->option_rebuild_names($e['data'], $envE);
            }
            if ($e['type'] === SidebarState::ENTITY_TYPE && $envE !== null) {
                $envFront = Canon::decode($envE['content']);
                $hasUnmanaged = false;
                foreach ((array) ($envFront['widgets'] ?? []) as $widget) {
                    $hasUnmanaged = $hasUnmanaged || !empty($widget['settings']['_duo_unmanaged']);
                }
                $missingDesiredMap = false;
                foreach ((array) ($e['data']['widgets'] ?? []) as $widget) {
                    if (Ledger::id_for(
                        (string) ($widget['uuid'] ?? ''),
                        SidebarState::kind((string) ($widget['type'] ?? ''))
                    ) === null) {
                        $missingDesiredMap = true;
                        break;
                    }
                }
                // The sidebar's own base is the exact evidence this target
                // previously knew these nested widget identities. Without
                // that base this may be a first widget rollout over ordinary
                // theme defaults, even on an otherwise long-managed site.
                // With it, missing maps are restored-ledger-loss ambiguity.
                if ($hasUnmanaged && $missingDesiredMap && $baseH !== null) {
                    throw new \RuntimeException(
                        "duo: widget identity history is missing for {$e['path']}; refusing to infer which live "
                        . 'instance owns a canonical UUID. Restore identity-export before plan/apply.'
                    );
                }
                $desiredWidgets = array_fill_keys(array_map(
                    static fn(array $w): string => (string) ($w['uuid'] ?? ''),
                    (array) ($e['data']['widgets'] ?? [])
                ), true);
                $widgetDeletes = [];
                foreach ((array) ($envFront['widgets'] ?? []) as $widget) {
                    if (!isset($desiredWidgets[(string) ($widget['uuid'] ?? '')])) {
                        $widgetDeletes[] = [
                            'uuid' => (string) ($widget['uuid'] ?? ''),
                            'type' => (string) ($widget['type'] ?? ''),
                            'unmanaged' => !empty($widget['settings']['_duo_unmanaged']),
                        ];
                    }
                }
                if ($widgetDeletes) {
                    $row['widget_deletes'] = $widgetDeletes;
                }
            }
            if ($e['type'] === 'user-meta' && $envE === null) {
                $login = (string) ($e['data']['login'] ?? '');
                $row['login'] = $login;
                $behavior = $this->policy->user_meta_missing_behavior((array) ($e['data']['meta'] ?? []));
                if ($behavior === 'warn') {
                    $plan['skipped_user_meta'][] = $row;
                    $warning = "user-meta {$e['path']}: exact login '$login' is absent; "
                        . 'warn-and-skip policy left the target untouched';
                    if (!in_array($warning, $this->warnings, true)) {
                        $this->warnings[] = $warning;
                    }
                } else {
                    $plan['missing_user'][] = $row;
                }
                continue;
            }
            if ($uuid === 'options/core' && $envE !== null) {
                $desiredRecords = OptionState::records($e['data']);
                $envDocument = Canon::decode($envE['content']);
                $envRecords = OptionState::records($envDocument);
                $pendingDeletes = [];
                $deleteConflicts = [];
                foreach ($desiredRecords as $name => $record) {
                    if ($record['state'] !== 'deleted' || !isset($envRecords[$name])
                        || $envRecords[$name]['state'] !== 'present') {
                        continue;
                    }
                    $pendingDeletes[] = (string) $name;
                    if (!hash_equals($record['expected_hash'], OptionState::record_hash($envRecords[$name]))) {
                        $deleteConflicts[] = "$name changed after the deletion base";
                    } elseif ($baseH !== null && hash_equals($fileH, $baseH)) {
                        $deleteConflicts[] = "$name was recreated after its deletion intent was applied";
                    }
                }
                if ($pendingDeletes) {
                    $row['option_deletes'] = $pendingDeletes;
                }
                if ($deleteConflicts) {
                    $plan['conflict'][] = $row + [
                        'reason' => implode('; ', $deleteConflicts),
                        'conflict_view' => self::conflict_view(
                            'option_delete_and_target_changed_since_base',
                            'update',
                            $baseH === null ? 'missing' : 'present',
                            $baseH,
                            $fileH,
                            $baseH,
                            null,
                            $comparisonEnvH,
                            ['--with-deletes', '--force-theirs']
                        ),
                    ];
                    continue;
                }
            }
            if ($envE !== null) {
                if ($fileH === $envE['hash']) {
                    $plan['unchanged'][] = $row;
                } elseif ($baseH === null || $comparisonEnvH === $baseH) {
                    $plan['update'][] = $row + ['first_sync' => $baseH === null];
                } elseif ($fileH === $baseH) {
                    $plan['drift'][] = $row;
                } else {
                    $plan['conflict'][] = $row + [
                        'conflict_view' => self::conflict_view(
                            'repository_and_target_changed_since_base',
                            'update',
                            'present',
                            $baseH,
                            $fileH,
                            $baseH,
                            null,
                            $comparisonEnvH,
                            ['--force-theirs']
                        ),
                    ];
                }
                continue;
            }
            $coll = $this->find_collision($e, $tree, $collisionCache);
            if ($coll !== null) {
                // Table entities' own 'type' IS the specific table name (see
                // Snapshot.php's row_tables() docblock) — pluralizing it
                // ("woocommerce_attribute_taxonomiess") the way posts/terms/
                // menus already do would be unusable, so every declared
                // table shares ONE adopt-by-slug key, "tables", regardless
                // of which specific table a natural_key collision belongs to.
                $kind = isset($this->snapshotRowTables()[$e['type']])
                    ? 'tables'
                    : ($e['type'] === 'menu' ? 'menus' : $e['type'] . 's');
                $kindOk = isset($adopt[$kind]);
                if ($kindOk) {
                    $plan['adopt'][] = $row + ['env_id' => $coll];
                } else {
                    $plan['collision'][] = $row + ['env_id' => $coll];
                }
                continue;
            }
            $plan['create'][] = $row;
        }
        // Absence is not deletion authority. Only a compiled, versioned
        // tombstone can enter one of the deletion buckets below. Its
        // expected_hash is the three-way base that capture observed before
        // removing the live file; this makes delete-vs-edit a first-class
        // conflict instead of letting an omitted file erase target data.
        $deletionCaps = [];
        foreach ($compiled->deletions() as $uuid => $d) {
            $data = (array) $d['data'];
            $kind = (string) $data['kind'];
            $subtype = (string) $data['type'];
            $entityType = $kind === 'table' ? $subtype : $kind;
            $expected = (string) $data['expected_hash'];
            $receipt = (string) $d['hash'];
            $envE = $env[$uuid] ?? null;
            $baseE = $base[$uuid] ?? null;
            $row = [
                'uuid' => $uuid,
                'type' => $entityType,
                'deletion_kind' => $kind,
                'deletion_type' => $subtype,
                'path' => (string) $d['path'],
                'expected_hash' => $expected,
                'receipt_hash' => $receipt,
            ];
            $this->annotate_natural_key_continuity($row, (string) $uuid, $entityType, null, $envE);
            $deletionCaps[$uuid] = Deletion::capability($this->policy, $kind, $subtype);

            if ($envE === null) {
                $plan['deleted'][] = $row;
                continue;
            }
            if ($baseE === null) {
                $plan['delete_conflict'][] = $row + [
                    'reason' => 'target entity exists but has no last-synced base',
                    'conflict_view' => self::conflict_view(
                        'target_without_last_synced_base',
                        'delete',
                        'missing',
                        null,
                        null,
                        $expected,
                        $receipt,
                        (string) $envE['hash'],
                        ['--with-deletes', '--force-theirs']
                    ),
                ];
                continue;
            }
            $baseContentHash = is_string($baseE['content_hash'] ?? null)
                ? $baseE['content_hash']
                : null;
            if (($baseE['entity_type'] ?? '') === 'deletion') {
                $plan['delete_conflict'][] = $row + [
                    'reason' => 'target entity was recreated after this deletion intent was applied',
                    'conflict_view' => self::conflict_view(
                        'target_recreated_after_delete',
                        'delete',
                        'deleted',
                        $baseContentHash,
                        null,
                        $expected,
                        $receipt,
                        (string) $envE['hash'],
                        ['--with-deletes', '--force-theirs']
                    ),
                ];
                continue;
            }
            if (!hash_equals($expected, (string) $baseContentHash)) {
                $plan['delete_conflict'][] = $row + [
                    'reason' => 'tombstone expected hash does not match the target last-synced base',
                    'conflict_view' => self::conflict_view(
                        'repository_expected_base_mismatch',
                        'delete',
                        'present',
                        $baseContentHash,
                        null,
                        $expected,
                        $receipt,
                        (string) $envE['hash'],
                        ['--with-deletes', '--force-theirs']
                    ),
                ];
                continue;
            }
            if (!hash_equals($expected, (string) $envE['hash'])) {
                $plan['delete_conflict'][] = $row + [
                    'reason' => 'target entity changed locally since the tombstone base',
                    'conflict_view' => self::conflict_view(
                        'target_changed_since_delete_base',
                        'delete',
                        'present',
                        $baseContentHash,
                        null,
                        $expected,
                        $receipt,
                        (string) $envE['hash'],
                        ['--with-deletes', '--force-theirs']
                    ),
                ];
                continue;
            }
            $plan['delete'][] = $row;
        }

        // Runtime reverse references are target facts, so check them only
        // after classifying every tombstone. A guard may exclude authored
        // child rows which are themselves safe DELETE candidates in this
        // same revision; conflicted children are deliberately not excluded.
        $deleteUuids = array_fill_keys(array_column($plan['delete'], 'uuid'), true);
        if (!empty($opts['force_theirs'])) {
            $deleteUuids += array_fill_keys(array_column($plan['delete_conflict'], 'uuid'), true);
        }
        $guardRepairUuids = $this->guard_repair_uuids($plan);
        foreach (['delete', 'delete_conflict'] as $bucket) {
            foreach ($plan[$bucket] as &$row) {
                $blocks = [];
                $guardRefs = [];
                $guardWitnesses = [];
                foreach ($deletionCaps[$row['uuid']]['guards'] ?? [] as $guardIndex => $guard) {
                    $result = $this->count_guard_refs(
                        $guard,
                        $row['uuid'],
                        $deleteUuids,
                        $compiled->deletions(),
                        $compiled->tree(),
                        $guardRepairUuids
                    );
                    $guardWitnesses[(string) $guardIndex] = (string) ($result['witness'] ?? hash('sha256', Canon::encode([])));
                    if ($result['error'] !== null) {
                        $blocks[] = $result['error'];
                    } elseif ($result['count'] > 0) {
                        $blocks[] = ($guard['reason'] ?? "referenced by {$guard['table']}.{$guard['column']}")
                            . " — {$result['count']} row(s)";
                        $guardRefs[] = [
                            'table' => (string) $guard['table'],
                            'rows' => $result['rows'],
                            'repairable' => isset($this->snapshotRowTables()[(string) $guard['table']]),
                            'option_name_ref' => !empty($guard['option_name_ref']),
                        ];
                    }
                }
                if ($blocks) {
                    $row['blocked'] = implode('; ', $blocks);
                    if ($bucket === 'delete_conflict' && isset($row['conflict_view']['choices'])) {
                        // A referential guard is a separate authorization
                        // boundary. Do not advertise the destructive
                        // repository-delete choice until those declared
                        // references are repaired; --force-delete-referenced
                        // is report-not-hide but never a "safe choice."
                        $row['conflict_view']['choices'] = array_values(array_filter(
                            (array) $row['conflict_view']['choices'],
                            static fn($choice): bool => is_array($choice)
                                && ($choice['id'] ?? null) !== 'apply_repository'
                        ));
                    }
                }
                if ($guardRefs) {
                    $row['guard_refs'] = $guardRefs;
                }
                $row['guard_witnesses'] = $guardWitnesses;
            }
            unset($row);
        }

        // docs/proposals/code-half.md §3.2: the cross-partition invariant's
        // plan-time checks read BOTH live lifecycle facts (active plugins /
        // themes) and, for an opt-in code descriptor, the completed verified
        // payload revision in the environment ledger. Keep these in the same
        // code_mismatch bucket: apply cannot safely write authored state when
        // either the declared lifecycle or the descriptor bytes have not yet
        // reached this environment.
        $desired = isset($tree['options/core'])
            ? Deploy::extract_desired($tree['options/core']['data'])
            : [];
        $plan['code_mismatch'] = array_merge(
            Code::target_compatibility_rows($this->repo, $compiled),
            Deploy::code_mismatch($this->policy, $desired),
            Deploy::code_revision_mismatch($compiled)
        );
        // DUO-3231: same $desired, same call shape as code_mismatch above —
        // see Deploy::code_drift()'s own docblock for why it's a distinct
        // question (out-of-band version change vs. compatibility range).
        $plan['code_drift'] = Deploy::code_drift($this->policy, $desired);

        // A failed hook can commit canonical option writes before WordPress
        // reports failure. Its pre-hook receipt survives lease cleanup and is
        // intentionally non-forceable, so a read-only plan/status must expose
        // the recovery requirement even when no promotion continuation is
        // currently active.
        $incompleteLifecycle = PromotionLock::incomplete_lifecycle();
        if ($incompleteLifecycle !== null) {
            $plan['incomplete_lifecycle'][] = $incompleteLifecycle + [
                'reason' => 'unresolved lifecycle hook attempt; restore the exact pre-lifecycle database checkpoint before retrying',
            ];
        }

        // A prior apply that committed authored rows but failed a required
        // rebuild deliberately left this marker. The live canonical hash can
        // now be unchanged, drift, or conflict: rebuild actions may normalize
        // just-written row after COMMIT, while duo_state intentionally still
        // names the pre-apply base. In every case the interrupted promotion's
        // repository tree remains the recovery target. Re-run every mapped
        // canonical entity through phase 2/rebuild until the marker clears;
        // otherwise the ordinary three-way gate can make a truthful failure
        // impossible to retry without an unrelated --force-theirs override.
        $retryingIncompleteApply = Ledger::kv_get('apply_in_progress') !== null;
        if ($retryingIncompleteApply) {
            $plan['incomplete_apply'][] = [
                'reason' => 'previous apply did not complete required rebuilds or convergence metadata',
            ];
            foreach (['unchanged', 'drift', 'conflict'] as $retryKind) {
                foreach ($plan[$retryKind] as $row) {
                    $plan['update'][] = $row + ['retry' => true];
                }
                $plan[$retryKind] = [];
            }
        }

        // DUO-3234 design review, addition 1: surface any still-outstanding
        // regen_pending:<uuid> marker so a plain `duo plan` — run between a
        // failed apply and its retry, with zero pending content changes —
        // does not silently say "nothing to do" while a regeneration retry
        // is armed underneath it. Read-only, like the rest of build_plan():
        // no kv mutation here. A marker whose post type is no longer
        // declared or whose uuid no longer resolves is deliberately NOT
        // surfaced — that is harmless orphaned bookkeeping the next apply's
        // regen_dependencies() sweeps on its own (with its own loud
        // warning, at the point it actually mutates), not something a
        // plan reader needs to act on.
        $plan['regen_pending'] = [];
        foreach (Ledger::kv_prefix(self::REGEN_PENDING_PREFIX) as $k => $postType) {
            $uuid = substr($k, strlen(self::REGEN_PENDING_PREFIX));
            if ($this->policy->regen_dependency($postType) === null) {
                continue; // orphaned — apply's own sweep handles this, not plan
            }
            if (Ledger::id_for($uuid, Ledger::KIND_POST) === null) {
                continue; // orphaned — apply's own sweep handles this, not plan
            }
            $plan['regen_pending'][] = ['uuid' => $uuid, 'type' => 'post', 'post_type' => $postType];
            $this->warnings[] = "regen_pending: post $uuid (type '$postType') has a regeneration "
                . 'retry pending from a prior failed verify';
        }

        // DUO-3342 / independent review F3: the same visibility, for the other
        // two durable keyspaces. `regen_delete_context:`/`regen_reparent_context:`
        // markers are outstanding derived-state DEBT in exactly the sense
        // regen_pending is — an apply captured a pre-delete inventory or a
        // pre-move receipt and has not yet had a consumer verify the repair —
        // and DUO-3342 made them survive a failed run instead of being swept,
        // which is precisely what turns "transient bookkeeping" into "a fact an
        // operator deciding on a promotion needs". Same read-only posture
        // (build_plan() never mutates kv), same orphan rule: a marker no
        // consumer of either kind claims is NOT surfaced, because apply's own
        // sweep removes it, loudly, at the point it actually mutates.
        //
        // Deliberately ONE bucket rather than two: the two prefixes differ in
        // what the receipt records, not in what a plan reader must do about it,
        // and `kind` carries the distinction for anyone who cares.
        $plan['regen_context'] = $this->regen_context_plan_rows();
        foreach ($plan['regen_context'] as $row) {
            $this->warnings[] = "regen_context: post {$row['uuid']} (type '{$row['post_type']}') has an "
                . "outstanding {$row['kind']} receipt awaiting a verified derived-state repair";
        }

        // DUO-3232: env-bound value provisioning checklist. Read-only, like
        // regen_pending above — no write here, ever (env values are
        // deliberately excluded from Capture/Apply's ordinary content
        // pipeline; this bucket exists purely so a plain `duo plan` tells
        // an operator the truth about what a freshly-materialized
        // environment still needs, per manifest-declared class:"env"
        // options only — see Policy::env_options()'s own docblock for why
        // meta/sub_keys env values are out of v2 scope). "Missing" means
        // the option row is absent or an empty string on THIS environment
        // — a per-environment self-check, not a cross-environment diff
        // (env values are never captured, so the repo has no record of
        // what any other environment had; an operator wanting an actual
        // source-vs-target checklist gets one by running `duo plan`
        // against both named environments and diffing the two
        // env_missing lists client-side — see cli/README.md).
        global $wpdb;
        $plan['env_missing'] = [];
        foreach ($this->policy->env_options() as $name => $rule) {
            $value = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name
            ));
            if ($value !== null && $value !== '') {
                continue;
            }
            $required = (bool) ($rule['required'] ?? false);
            $plan['env_missing'][] = ['name' => $name, 'required' => $required];
            if ($required) {
                $this->warnings[] = "env_missing: option '$name' is required and not yet provisioned on "
                    . "this environment — see 'wp duo env-set --name=$name --stdin'";
            }
        }

        // DUO-3249: loud, plan-visible half of Policy::rule_details()'s
        // core-yields-to-plugin precedence fix — a plain warning naming
        // every core-manifest option a pinned plugin manifest is actively
        // reclassifying on THIS site (e.g. polylang.json's own
        // default_category -> derived). A structural fact about which
        // manifests are pinned together, not a per-environment condition,
        // so it belongs in $plan['warnings'] like code_mismatch's own
        // rendering precedent, never a new ok-flipping bucket — an active
        // reclassification is correct, intended behavior once the
        // overriding manifest is pinned, not a problem to refuse promotion
        // over.
        foreach ($this->policy->active_reclassifications() as $r) {
            $this->warnings[] = "reclassified: option '{$r['name']}' is core-classified "
                . "'{$r['core_class']}' but '{$r['overridden_by']}' (pinned) reclassifies it "
                . "'{$r['active_class']}' on this site — the plugin's declaration governs";
        }
        // DUO-3272: the same loud-plan-warning treatment, for menu_fields
        // instead of options — see Policy::active_menu_field_reclassifications()'s
        // own docblock for why this is a separate method/loop rather than a
        // generalized shared one.
        foreach ($this->policy->active_menu_field_reclassifications() as $r) {
            $this->warnings[] = "reclassified: menu field '{$r['name']}' is core-classified "
                . "'{$r['core_class']}' but '{$r['overridden_by']}' (pinned) reclassifies it "
                . "'{$r['active_class']}' on this site — the plugin's declaration governs";
        }
        $inactiveWarning = SidebarState::inactive_warning();
        if ($inactiveWarning !== null && !in_array($inactiveWarning, $this->warnings, true)) {
            $this->warnings[] = $inactiveWarning;
        }

        // A global capability report evaluates every declared provider action,
        // but a plan/status must answer what THIS plan can actually run. Use
        // the exact work/delete/retry projection Apply::run() uses before its
        // pre-mutation negotiation, so an unrelated or empty trigger cannot
        // turn a clean plan red while a selected missing capability stays
        // visible in adapter_dispositions.
        $rebuildWork = $this->rebuild_work($plan, $tree, $opts, $retryingIncompleteApply);
        $this->selectedActions = $this->policy->actions_for(
            $this->rebuild_surfaces(
                $rebuildWork['work'],
                $tree,
                $rebuildWork['rebuild_delete_work']
            )
        );
        $selectedProviderBlockers = [];
        if ($diagnoseAdapters) {
            $selectedProviderBlockers = $this->policy->provider_readiness_blockers($this->selectedActions);
            $plan['adapter_dispositions'] = array_merge(
                $plan['adapter_dispositions'],
                $selectedProviderBlockers
            );
        }
        $nestedDeleteCounts = $strictObservation ? null : self::nested_delete_candidate_counts(
            $env,
            $tree,
            $plan,
            $planObservations
        );
        if ($nestedDeleteCounts !== null) {
            $this->categorySummaryContext = [
                'selected_native_actions' => count(array_filter(
                    $this->selectedActions,
                    static fn(array $action): bool => ($action['kind'] ?? null) === 'native'
                )),
                'selected_provider_actions' => count(array_filter(
                    $this->selectedActions,
                    static fn(array $action): bool => ($action['kind'] ?? null) === 'provider'
                )),
                'certification_source_blockers' => count($certificationBlockers),
                'selected_provider_blockers' => count($selectedProviderBlockers),
                'nested_menu_item_delete_candidates' => $nestedDeleteCounts['menu'],
                'nested_widget_delete_candidates' => $nestedDeleteCounts['widget'],
                'nested_option_delete_candidates' => $nestedDeleteCounts['option'],
            ];
        }
        return $plan;
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
        $desired = OptionState::records($desiredDocument);
        if ($env === null) {
            $names = [];
            foreach ($desired as $name => $record) {
                if (($record['state'] ?? null) === 'absent') {
                    continue;
                }
                if (($record['state'] ?? null) === 'present'
                    && isset($this->policy)
                    && (($this->policy->option_rule((string) $name)['class'] ?? null) === 'managed')) {
                    continue;
                }
                $names[] = (string) $name;
            }
            sort($names, SORT_STRING);
            return $names;
        }
        $envDocument = Canon::decode((string) ($env['content'] ?? ''));
        $observed = OptionState::records($envDocument);
        $names = [];
        // Target-only records are intentionally absent from this projection:
        // omission is not deletion authority, and apply_options() preserves
        // them untouched. Only desired records can be authored/deleted by
        // this revision.
        foreach ($desired as $name => $record) {
            if (($record['state'] ?? null) === 'absent') {
                continue;
            }
            if (($record['state'] ?? null) === 'present'
                && isset($this->policy)
                && (($this->policy->option_rule((string) $name)['class'] ?? null) === 'managed')) {
                continue;
            }
            if (!array_key_exists($name, $observed)
                || !hash_equals(
                    OptionState::record_hash($record),
                    OptionState::record_hash($observed[$name])
                )) {
                $names[] = (string) $name;
            }
        }
        sort($names, SORT_STRING);
        return $names;
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
        $decl = $this->snapshotRowTables()[$table] ?? null;
        if (!is_array($decl) || ($decl['identity']['mode'] ?? 'mapped') !== 'natural_key') {
            return;
        }
        if (Ledger::id_for($uuid, (string) ($decl['id_kind'] ?? '')) === null) {
            return;
        }

        $fronts = [];
        if ($desired !== null) {
            $fronts[] = $desired;
        }
        $content = $env['content'] ?? null;
        if (is_string($content)) {
            $fronts[] = Canon::decode($content);
        }
        foreach ($fronts as $front) {
            $columns = is_array($front['columns'] ?? null) ? $front['columns'] : [];
            $note = IdentityNotes::natural_key_continuity($uuid, $table, $decl, $columns);
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

    /**
     * Phase-2 finalize order: 'early' post types first, then ordinary
     * posts/terms/menus/options, then declared table rows in their own
     * topo order (parents before children — nf3_forms before nf3_fields/
     * nf3_actions) — offset past every other rank since nothing in this
     * round's scope cross-references between tables and posts/terms in
     * either direction, so their RELATIVE order to each other never
     * matters, only their INTERNAL order does.
     */
    private function phase2_rank(array $entity): int {
        if (isset($this->snapshotRowTables()[$entity['type']])) {
            return 2 + Snapshot::phase2_rank($this->policy, $entity['type']);
        }
        if ($entity['type'] === 'post'
            && $this->policy->post_type_phase($entity['post_type'] ?? '') === 'early') {
            return 0;
        }
        return 1;
    }

    /** Delete custom-table children before their declared parents. */
    private function deletion_rank(array $row): int {
        if (($row['deletion_kind'] ?? '') === 'table') {
            return 100 + Snapshot::phase2_rank($this->policy, (string) $row['deletion_type']);
        }
        return match ($row['deletion_kind'] ?? '') {
            'post' => 30,
            'menu' => 20,
            'term' => 10,
            default => 0,
        };
    }

    /**
     * UUIDs whose authored state is actually scheduled to be written in this
     * revision. Deletion guards use this narrow witness to distinguish a
     * parent update which removes a ref from a child-only delete (or a
     * conflicted parent which cannot be trusted to repair anything).
     *
     * @return array<string,bool>
     */
    private function guard_repair_uuids(array $plan, bool $includeDrift = false): array {
        $out = [];
        $buckets = ['create', 'update', 'adopt'];
        if ($includeDrift) {
            $buckets[] = 'drift';
        }
        foreach ($buckets as $bucket) {
            foreach ((array) ($plan[$bucket] ?? []) as $row) {
                $uuid = (string) ($row['uuid'] ?? '');
                if ($uuid !== '') {
                    $out[$uuid] = true;
                }
            }
        }
        return $out;
    }

    /** @return array{count:int,error:?string,rows:string[],witness?:string} */
    private function count_guard_refs(
        array $guard,
        string $targetUuid,
        array $deleteUuids,
        array $deletions,
        array $tree = [],
        array $guardRepairUuids = [],
        bool $forUpdate = false
    ): array {
        global $wpdb;
        $table = $wpdb->prefix . preg_replace('/[^A-Za-z0-9_]/', '', $guard['table']);
        $column = preg_replace('/[^A-Za-z0-9_]/', '', $guard['column']);
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            return ['count' => 0, 'error' => "required guard table '{$guard['table']}' is absent", 'rows' => []];
        }
        $localId = Ledger::id_for($targetUuid, (string) $guard['id_kind']);
        if ($localId === null) {
            return [
                'count' => 0,
                'error' => "required {$guard['id_kind']} identity mapping is absent for guard {$guard['table']}.{$guard['column']}",
                'rows' => [],
            ];
        }

        // WooCommerce shipping-method instance settings are authored option
        // rows whose NAME embeds the method's local instance_id. A method
        // tombstone must therefore carry the matching option tombstone in
        // the same compiled revision or refuse the raw table delete; leaving
        // the option behind would make a later Woo load resurrect stale
        // settings for a newly-reused instance id.
        if (!empty($guard['option_name_ref'])) {
            return $this->count_option_name_guard_refs(
                $guard,
                $targetUuid,
                $localId,
                $tree,
                $guardRepairUuids,
                $table,
                $forUpdate
            );
        }

        // Some plugin references are stored as typed PHP values in metadata,
        // not as a scalar FK column. This manifest-declared branch is still
        // deterministic and generic: it enumerates the rows, decodes only
        // the declared ref shape, excludes owners deleted in this revision,
        // and permits only an explicitly scheduled authored owner update to
        // remove the target token. It refuses malformed shapes instead of
        // guessing that an opaque value is safe.
        if (array_key_exists('meta_key', $guard) || array_key_exists('ref', $guard)) {
            return $this->count_meta_guard_refs(
                $guard,
                $targetUuid,
                $deleteUuids,
                $tree,
                $guardRepairUuids,
                $table,
                $forUpdate
            );
        }

        $where = ["`$column` = %d"];
        $args = [$localId];
        foreach ((array) ($guard['where'] ?? []) as $name => $value) {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
            if (is_int($value)) {
                $where[] = "`$name` = %d";
                $args[] = $value;
            } else {
                $where[] = "`$name` = %s";
                $args[] = (string) $value;
            }
        }
        foreach ((array) ($guard['exclude_where'] ?? []) as $name => $value) {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
            if (is_int($value)) {
                $where[] = "`$name` <> %d";
                $args[] = $value;
            } else {
                $where[] = "`$name` <> %s";
                $args[] = (string) $value;
            }
        }

        $sourceKind = (string) ($guard['source_id_kind'] ?? '');
        $sourcePk = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($guard['source_pk'] ?? ''));
        if ($sourceKind !== '' && $sourcePk !== '') {
            $allowed = [];
            foreach ($deleteUuids as $uuid => $_) {
                if (!isset($deletions[$uuid])) {
                    continue;
                }
                $id = Ledger::id_for((string) $uuid, $sourceKind);
                if ($id !== null) {
                    $allowed[] = $id;
                }
            }
            if ($allowed) {
                $where[] = "`$sourcePk` NOT IN (" . implode(',', array_fill(0, count($allowed), '%d')) . ')';
                array_push($args, ...$allowed);
            }
        }
        $identityCols = $sourcePk !== '' ? [$sourcePk] : [];
        if (!$identityCols) {
            $primary = $wpdb->get_results("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'", ARRAY_A) ?: [];
            usort($primary, fn(array $a, array $b): int =>
                ((int) ($a['Seq_in_index'] ?? 0)) <=> ((int) ($b['Seq_in_index'] ?? 0))
            );
            $identityCols = array_values(array_filter(array_map(
                fn(array $r): string => preg_replace('/[^A-Za-z0-9_]/', '', (string) ($r['Column_name'] ?? '')),
                $primary
            )));
        }
        $lockIndex = null;
        if ($forUpdate) {
            $lockIndex = $this->guard_lock_index($guard, $table);
            if ($lockIndex === null) {
                return [
                    'count' => 0,
                    'error' => "guard table '{$guard['table']}' has no complete indexed lock boundary for {$guard['column']}",
                    'rows' => [],
                    'witness' => hash('sha256', Canon::encode([])),
                ];
            }
        }
        $forceIndex = $lockIndex !== null ? " FORCE INDEX (`$lockIndex`)" : '';
        $order = $identityCols
            ? implode(', ', array_map(fn(string $c): string => "`$c` ASC", $identityCols))
            : "`$column` ASC";
        $sql = "SELECT * FROM `$table`$forceIndex WHERE " . implode(' AND ', $where) . " ORDER BY $order";
        if ($forUpdate) {
            // A locking read is deliberately used for the boundary, not a
            // second consistent snapshot. Under REPEATABLE READ, the indexed
            // equality/range also locks the gap so an insert or a ref-bearing
            // update cannot slip between this check and delete/commit.
            $sql .= ' FOR UPDATE';
            $found = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
            if ($wpdb->last_error) {
                return ['count' => 0, 'error' => "guard query failed for {$guard['table']}.{$guard['column']}: {$wpdb->last_error}", 'rows' => [], 'witness' => hash('sha256', Canon::encode([]))];
            }
        } else {
            // Preserve the pre-enumeration behavior for an empty guard result:
            // a table with no stable key is irrelevant when it has zero
            // surviving refs. Stable identity becomes mandatory only when a
            // force warning would actually need to enumerate rows.
            $countSql = "SELECT COUNT(*) FROM `$table` WHERE " . implode(' AND ', $where);
            $count = $wpdb->get_var($wpdb->prepare($countSql, ...$args));
            if ($wpdb->last_error) {
                return ['count' => 0, 'error' => "guard query failed for {$guard['table']}.{$guard['column']}", 'rows' => [], 'witness' => hash('sha256', Canon::encode([]))];
            }
            if ((int) $count === 0) {
                return ['count' => 0, 'error' => null, 'rows' => [], 'witness' => hash('sha256', Canon::encode([]))];
            }
            $found = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
            if ($wpdb->last_error) {
                return ['count' => 0, 'error' => "guard query failed for {$guard['table']}.{$guard['column']}", 'rows' => [], 'witness' => hash('sha256', Canon::encode([]))];
            }
        }
        if (!$found) {
            return ['count' => 0, 'error' => null, 'rows' => [], 'witness' => hash('sha256', Canon::encode([]))];
        }
        if (!$identityCols) {
            return [
                'count' => 0,
                'error' => "guard table '{$guard['table']}' has no stable row identity; refusing an unenumerated force-delete warning",
                'rows' => [],
                'witness' => hash('sha256', Canon::encode($found)),
            ];
        }
        $rows = array_map(function (array $foundRow) use ($guard, $identityCols): string {
            $identity = implode(',', array_map(
                fn(string $c): string => "$c=" . (string) ($foundRow[$c] ?? ''),
                $identityCols
            ));
            return (string) $guard['table'] . '.' . $identity;
        }, $found ?: []);
        return [
            'count' => count($rows),
            'error' => null,
            'rows' => $rows,
            'witness' => hash('sha256', Canon::encode(array_values($found))),
        ];
    }

    /**
     * Count live option rows whose declared option_name_refs pattern embeds
     * the target row's local id. This is manifest-driven: the engine does
     * not know WooCommerce's naming convention, it only asks the policy
     * which version-pinned name patterns own a row.
     *
     * @return array{count:int,error:?string,rows:string[],witness?:string}
     */
    private function count_option_name_guard_refs(
        array $guard,
        string $targetUuid,
        int $targetId,
        array $tree,
        array $guardRepairUuids,
        string $table,
        bool $forUpdate = false
    ): array {
        global $wpdb;
        $identityColumn = preg_replace(
            '/[^A-Za-z0-9_]/',
            '',
            (string) ($guard['identity_column'] ?? 'option_id')
        );
        $lockIndex = null;
        if ($forUpdate) {
            $lockIndex = $this->guard_lock_index($guard, $table);
            if ($lockIndex === null) {
                return [
                    'count' => 0,
                    'error' => "guard table '{$guard['table']}' has no complete indexed lock boundary for option_name",
                    'rows' => [],
                    'witness' => hash('sha256', Canon::encode([])),
                ];
            }
        }
        $forceIndex = $lockIndex !== null ? " FORCE INDEX (`$lockIndex`)" : '';
        $sql = "SELECT `$identityColumn` AS guard_id, `option_name`, `option_value`, `autoload` FROM `$table`$forceIndex";
        if ($forUpdate) {
            // Regex ownership cannot be translated into one portable SQL
            // range. Lock the complete indexed option-name range instead;
            // this is intentionally conservative but closes insert-after-
            // check races for every authored option_name_refs pattern.
            $sql .= " WHERE `option_name` >= ''";
        }
        $sql .= " ORDER BY `$identityColumn` ASC" . ($forUpdate ? ' FOR UPDATE' : '');
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error) {
            return [
                'count' => 0,
                'error' => "guard query failed for {$guard['table']} option-name refs: {$wpdb->last_error}",
                'rows' => [],
                'witness' => hash('sha256', Canon::encode([])),
            ];
        }

        $found = [];
        $witnessRows = [];
        $idKind = (string) ($guard['id_kind'] ?? '');
        $token = '{{' . $idKind . ':' . $targetUuid . '}}';
        foreach ($rows ?: [] as $row) {
            $name = (string) ($row['option_name'] ?? '');
            try {
                // Resolve all id_kinds and classes together. Filtering to the
                // guard's kind first would hide a cross-kind overlap and
                // recreate the old first-match ambiguity.
                $details = $this->policy->option_name_ref_match_details($name);
            } catch (\Throwable $e) {
                return [
                    'count' => 0,
                    'error' => $e->getMessage(),
                    'rows' => [],
                    'witness' => hash('sha256', Canon::encode([])),
                ];
            }
            if ($details === null || ($details['rule']['class'] ?? '') !== 'authored'
                || (string) ($details['rule']['id_kind'] ?? '') !== $idKind) {
                continue;
            }
            $matches = $details['matches'] ?? [];
            $rawId = $matches['id'][0] ?? null;
            $matchedId = Policy::strict_positive_local_id($rawId);
            if ($matchedId === null) {
                return [
                    'count' => 0,
                    'error' => "guard {$guard['table']} option-name rule captured an unsafe local id",
                    'rows' => [],
                    'witness' => hash('sha256', Canon::encode([])),
                ];
            }
            if ($matchedId !== $targetId) {
                continue;
            }

            // The canonical options entity is the only portable witness that
            // this live row is intentionally removed. A present record with a
            // token still points at the soon-to-be-deleted method and must
            // remain blocking; a missing record is equally unsafe because
            // apply would leave this live option untouched.
            $offset = (int) ($matches['id'][1] ?? -1);
            if ($offset < 0) {
                return [
                    'count' => 0,
                    'error' => "guard {$guard['table']} option-name rule did not expose its named id capture",
                    'rows' => [],
                    'witness' => hash('sha256', Canon::encode([])),
                ];
            }
            $canonicalName = substr($name, 0, $offset)
                . $token
                . substr($name, $offset + strlen((string) ($matches['id'][0] ?? '')));
            $optionsUuid = 'options/core';
            $record = null;
            $witnessRows[] = $row;
            $optionsDocument = $tree[$optionsUuid]['data'] ?? null;
            if (is_array($optionsDocument)
                && array_key_exists('format', $optionsDocument)
                && array_key_exists('records', $optionsDocument)) {
                $record = OptionState::records($optionsDocument)[$canonicalName] ?? null;
            }
            if (isset($guardRepairUuids[$optionsUuid])
                && is_array($record)
                && ($record['state'] ?? '') === 'deleted') {
                continue;
            }

            $found[] = (string) $guard['table'] . '.' . $identityColumn . '='
                . (string) ($row['guard_id'] ?? '') . " (option_name=$name)";
        }
        return [
            'count' => count($found),
            'error' => null,
            'rows' => $found,
            'witness' => hash('sha256', Canon::encode(array_values($witnessRows))),
        ];
    }

    /**
     * Count a manifest-declared reference held in a metadata value, such as
     * WooCommerce grouped products' serialized `_children` post[] list.
     *
     * @return array{count:int,error:?string,rows:string[],witness?:string}
     */
    private function count_meta_guard_refs(
        array $guard,
        string $targetUuid,
        array $deleteUuids,
        array $tree,
        array $guardRepairUuids,
        string $table,
        bool $forUpdate = false
    ): array {
        global $wpdb;
        $column = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($guard['column'] ?? ''));
        $metaKey = (string) ($guard['meta_key'] ?? '');
        $sourceKind = (string) ($guard['source_id_kind'] ?? '');
        $sourcePk = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($guard['source_pk'] ?? $column));
        $identityColumn = preg_replace(
            '/[^A-Za-z0-9_]/',
            '',
            (string) ($guard['identity_column'] ?? 'meta_id')
        );
        $where = ["`meta_key` = %s"];
        $args = [$metaKey];
        foreach ((array) ($guard['where'] ?? []) as $name => $value) {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
            if (is_int($value)) {
                $where[] = "`$name` = %d";
                $args[] = $value;
            } else {
                $where[] = "`$name` = %s";
                $args[] = (string) $value;
            }
        }
        foreach ((array) ($guard['exclude_where'] ?? []) as $name => $value) {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
            if (is_int($value)) {
                $where[] = "`$name` <> %d";
                $args[] = $value;
            } else {
                $where[] = "`$name` <> %s";
                $args[] = (string) $value;
            }
        }
        $lockIndex = null;
        if ($forUpdate) {
            $lockIndex = $this->guard_lock_index($guard, $table);
            if ($lockIndex === null) {
                return [
                    'count' => 0,
                    'error' => "guard table '{$guard['table']}' has no complete indexed lock boundary for metadata key '$metaKey'",
                    'rows' => [],
                    'witness' => hash('sha256', Canon::encode([])),
                ];
            }
        }
        $forceIndex = $lockIndex !== null ? " FORCE INDEX (`$lockIndex`)" : '';
        $sql = "SELECT `$identityColumn` AS guard_id, `$sourcePk` AS source_id, `meta_value` "
            . "FROM `$table`$forceIndex WHERE " . implode(' AND ', $where)
            . " ORDER BY `$identityColumn` ASC" . ($forUpdate ? ' FOR UPDATE' : '');
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
        if ($wpdb->last_error) {
            return [
                'count' => 0,
                'error' => "guard query failed for {$guard['table']} metadata key '$metaKey': {$wpdb->last_error}",
                'rows' => [],
                'witness' => hash('sha256', Canon::encode([])),
            ];
        }
        $targetId = Ledger::id_for($targetUuid, (string) ($guard['id_kind'] ?? ''));
        if ($targetId === null) {
            return [
                'count' => 0,
                'error' => "required {$guard['id_kind']} identity mapping is absent for guard {$guard['table']} metadata key '$metaKey'",
                'rows' => [],
                'witness' => hash('sha256', Canon::encode($rows ?: [])),
            ];
        }
        $found = [];
        $foundSources = [];
        foreach ($rows ?: [] as $row) {
            $sourceId = (int) ($row['source_id'] ?? 0);
            $sourceUuid = $sourceKind !== '' && $sourceId > 0
                ? Ledger::uuid_for($sourceId, $sourceKind)
                : null;
            if ($sourceUuid !== null && isset($deleteUuids[$sourceUuid])) {
                continue;
            }
            $valueIds = $this->meta_guard_value_ids($row['meta_value'] ?? null, $guard);
            if ($valueIds === null) {
                return [
                    'count' => 0,
                    'error' => "guard {$guard['table']} metadata key '$metaKey' has an unsupported {$guard['ref']} value shape",
                    'rows' => [],
                    'witness' => hash('sha256', Canon::encode($rows ?: [])),
                ];
            }
            if (!in_array($targetId, $valueIds, true)) {
                continue;
            }
            // If the owner is being updated in this exact compiled revision,
            // inspect its desired canonical metadata. A missing target token
            // is an explicit, portable repair; no live-only inference is
            // allowed. Owners absent from this map remain blocking refs.
            if ($sourceUuid !== null && isset($guardRepairUuids[$sourceUuid])) {
                $desired = (array) (($tree[$sourceUuid]['data']['meta'] ?? null));
                $desiredPresent = array_key_exists($metaKey, $desired);
                $desiredContains = $this->canonical_meta_ref_contains_uuid(
                    $desiredPresent ? $desired[$metaKey] : null,
                    (string) ($guard['ref'] ?? ''),
                    $targetUuid,
                    $desiredPresent
                );
                if ($desiredContains === null) {
                    return [
                        'count' => 0,
                        'error' => "guard {$guard['table']} metadata key '$metaKey' has an unsupported desired {$guard['ref']} value shape",
                        'rows' => [],
                        'witness' => hash('sha256', Canon::encode($rows ?: [])),
                    ];
                }
                if (!$desiredContains) {
                    continue;
                }
                $foundSources[$sourceUuid] = true;
            }
            $label = (string) ($guard['table'] ?? 'metadata') . '.' . $identityColumn . '='
                . (string) ($row['guard_id'] ?? '');
            if ($sourcePk !== '' && array_key_exists('source_id', $row)) {
                $label .= " ($sourcePk=" . $sourceId . ')';
            }
            $found[] = $label;
        }

        // A scheduled owner update can introduce a target token even when
        // the live metadata row does not currently reference the target. The
        // recheck after phase-2 would otherwise be the first place this
        // becomes visible, and a plan could misleadingly appear safe. Treat
        // the desired canonical owner state itself as a blocking reference;
        // only an owner update which removes the token (or an owner tombstone)
        // is a valid repair witness.
        foreach ($guardRepairUuids as $sourceUuid => $_) {
            if (isset($deleteUuids[$sourceUuid]) || isset($foundSources[$sourceUuid])) {
                continue;
            }
            $desired = (array) (($tree[$sourceUuid]['data']['meta'] ?? null));
            $desiredPresent = array_key_exists($metaKey, $desired);
            $desiredContains = $this->canonical_meta_ref_contains_uuid(
                $desiredPresent ? $desired[$metaKey] : null,
                (string) ($guard['ref'] ?? ''),
                $targetUuid,
                $desiredPresent
            );
            if ($desiredContains === null) {
                return [
                    'count' => 0,
                    'error' => "guard {$guard['table']} metadata key '$metaKey' has an unsupported desired {$guard['ref']} value shape",
                    'rows' => [],
                    'witness' => hash('sha256', Canon::encode($rows ?: [])),
                ];
            }
            if (!$desiredContains) {
                continue;
            }
            $found[] = (string) ($guard['table'] ?? 'metadata') . '.' . $identityColumn
                . "=desired:$sourceUuid";
        }
        return [
            'count' => count($found),
            'error' => null,
            'rows' => $found,
            'witness' => hash('sha256', Canon::encode($rows ?: [])),
        ];
    }

    /** @return list<int>|null null means the value shape is unsafe/unknown. */
    private function meta_guard_value_ids($raw, array $guard): ?array {
        $ref = (string) ($guard['ref'] ?? '');
        $cast = (string) ($guard['cast'] ?? '');
        if ($cast === 'csv') {
            if (!is_string($raw)) {
                return null;
            }
            if ($raw === '') {
                return [];
            }
            $ids = [];
            foreach (explode(',', $raw) as $member) {
                $id = $this->strict_positive_meta_id($member);
                if ($id === null) {
                    return null;
                }
                $ids[] = $id;
            }
            return $ids;
        }
        $decoded = $raw;
        if (is_string($raw)) {
            // Do not use WordPress' maybe_unserialize() here. Its legacy
            // helper delegates to unserialize() without allowed_classes and
            // would instantiate an object supplied by the database. This
            // guard only needs scalar/list values, so decoding with objects
            // disabled is both sufficient and a safer boundary for a
            // target-controlled metadata value. PHP's unserialize() accepts
            // a valid value followed by arbitrary trailing bytes; require a
            // byte-for-byte serialize() round trip so the guard cannot inspect
            // only a prefix of an attacker-controlled payload. This accepts
            // all ordinary Woo forms produced by serialize() (arrays,
            // integer/string scalars, null, and false) while rejecting
            // noncanonical/trailing payloads without instantiating objects.
            [$serialized, $unserialized] = $this->strict_unserialize($raw);
            if ($serialized) {
                $decoded = $unserialized;
            }
        }
        if (str_ends_with($ref, '[]')) {
            if (!is_array($decoded) || !array_is_list($decoded)) {
                return null;
            }
            $ids = [];
            foreach ($decoded as $member) {
                $id = $this->strict_positive_meta_id($member);
                if ($id === null) {
                    return null;
                }
                $ids[] = $id;
            }
            return $ids;
        }
        if ($decoded === null || $decoded === '') {
            return [];
        }
        if (is_array($decoded) || is_object($decoded)) {
            return null;
        }
        $id = $this->strict_positive_meta_id($decoded);
        return $id === null ? null : [$id];
    }

    /**
     * @return array{0:bool,1:mixed} whether $raw is one canonical PHP
     * serialization value and its safely-decoded value.
     */
    private function strict_unserialize(string $raw): array {
        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        // `false` is a valid serialized value only when its exact canonical
        // spelling is present; every other false result is malformed input.
        if ($decoded === false && $raw !== 'b:0;') {
            return [false, null];
        }
        // allowed_classes=false turns supplied objects into an incomplete
        // object marker. Reject the object boundary explicitly before any
        // round trip so no object-shaped value is treated as a scalar/list.
        if (is_object($decoded)) {
            return [false, null];
        }
        try {
            $roundTrip = serialize($decoded);
        } catch (\Throwable $e) {
            return [false, null];
        }
        if ($roundTrip !== $raw) {
            return [false, null];
        }
        return [true, $decoded];
    }

    /** @return int|null null means the value is not an exact positive id. */
    private function strict_positive_meta_id($value): ?int {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^0*[1-9][0-9]*$/D', $value)) {
            return null;
        }
        $digits = ltrim($value, '0');
        $id = (int) $digits;
        // Reject overflow rather than letting a huge decimal string saturate
        // to PHP_INT_MAX and accidentally match a real local identity.
        return $id > 0 && (string) $id === $digits ? $id : null;
    }

    /** @return bool|null null means the declared scalar/list shape is unsafe. */
    private function canonical_meta_ref_contains_uuid(
        $value,
        string $ref,
        string $uuid,
        bool $present = true
    ): ?bool {
        if (!$present) {
            return false;
        }
        if ($value === null) {
            return null;
        }
        $kind = rtrim($ref, '[]');
        $token = '{{' . $kind . ':' . $uuid . '}}';
        // Match the same RFC UUID layout/version/variant that Uuid::is()
        // and Snapshot's canonical token grammar accept. A merely
        // 36-character hex/hyphen string is not a valid identity token and
        // must not be interpreted as an explicit desired removal.
        $tokenPattern = '/^\{\{' . preg_quote($kind, '/')
            . ':[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\}\}$/';
        if (str_ends_with($ref, '[]')) {
            if (!is_array($value) || !array_is_list($value)) {
                return null;
            }
            $contains = false;
            foreach ($value as $member) {
                if (!is_string($member) || preg_match($tokenPattern, $member) !== 1) {
                    return null;
                }
                $contains = $contains || hash_equals($token, $member);
            }
            return $contains;
        }
        if (!is_string($value) || preg_match($tokenPattern, $value) !== 1) {
            return null;
        }
        return hash_equals($token, $value);
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

    /** Same-slug env entity: managed w/ different uuid (hard collision) or unmanaged (adoptable). */
    private function find_collision(array $e, array $tree, array &$cache): ?int {
        global $wpdb;
        $uuid = (string) ($e['data']['uuid'] ?? '');
        if ($uuid !== '' && array_key_exists($uuid, $cache)) {
            return $cache[$uuid];
        }
        if (isset($this->snapshotRowTables()[$e['type']])) {
            // natural_key-identity tables only (e.g. woocommerce_attribute_
            // taxonomies pre-provisioned by hand on the target) — see
            // Snapshot::find_collision()'s own docblock; mapped-identity
            // tables have no collision concept and return null here. $tree and
            // $cache travel with it for the same reason collision_parent_id()
            // below needs them: a parent-scoped key's ref component may name a
            // parent row that is itself only adoptable, not yet mapped.
            $id = Snapshot::find_collision($this->policy, $e, $tree, $cache);
            if ($uuid !== '') {
                $cache[$uuid] = $id;
            }
            return $id;
        }
        if ($e['type'] === 'post') {
            $front = $e['data'];
            $parentId = $this->collision_parent_id($front['parent'] ?? null, 'post', $tree, $cache);
            if (!empty($front['parent']) && $parentId === null) {
                return $cache[$uuid] = null;
            }
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_name = %s AND p.post_type = %s "
                . 'AND p.post_parent = %d ORDER BY p.ID ASC',
                $front['slug'], $front['type'], $parentId ?? 0
            )) ?: [];
            return $cache[$uuid] = $this->one_collision(
                $ids,
                "post {$front['type']}/{$front['slug']} under parent " . ($parentId ?? 0)
            );
        }
        if ($e['type'] === 'term' || $e['type'] === 'menu') {
            $front = $e['data'];
            $tax = $e['type'] === 'menu' ? 'nav_menu' : $front['taxonomy'];
            $slug = $front['slug'];
            $parentId = $e['type'] === 'menu'
                ? 0
                : $this->collision_parent_id($front['parent'] ?? null, 'term', $tree, $cache);
            if ($e['type'] !== 'menu' && !empty($front['parent']) && $parentId === null) {
                return $cache[$uuid] = null;
            }
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT t.term_id FROM {$wpdb->terms} t
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                 WHERE t.slug = %s AND tt.taxonomy = %s AND tt.parent = %d ORDER BY t.term_id ASC",
                $slug, $tax, $parentId ?? 0
            )) ?: [];
            return $cache[$uuid] = $this->one_collision(
                $ids,
                "term $tax/$slug under parent " . ($parentId ?? 0)
            );
        }
        return null;
    }

    private function collision_parent_id($parentUuid, string $kind, array $tree, array &$cache): ?int {
        if ($parentUuid === null || $parentUuid === '') {
            return 0;
        }
        // Post parents are serialized through the ordinary typed-token
        // grammar; term parents are bare UUID fields. Normalize both to the
        // canonical parent UUID before consulting either ledger or tree.
        if (is_string($parentUuid)
            && preg_match('/^\{\{' . preg_quote($kind, '/') . ':([^}]+)\}\}$/', $parentUuid, $m)) {
            $parentUuid = $m[1];
        }
        $idKind = $kind === 'post' ? Ledger::KIND_POST : Ledger::KIND_TERM;
        $mapped = Ledger::id_for((string) $parentUuid, $idKind);
        if ($mapped !== null) {
            return $mapped;
        }
        $parent = $tree[(string) $parentUuid] ?? null;
        if ($parent === null || $parent['type'] !== $kind) {
            return null;
        }
        return $this->find_collision($parent, $tree, $cache);
    }

    private function one_collision(array $ids, string $identity): ?int {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) > 1) {
            throw new \RuntimeException(
                "duo: conflicting adoption key for $identity matches local ids " . implode(', ', $ids)
                . '; full natural identity must be unique before adoption'
            );
        }
        return $ids ? $ids[0] : null;
    }

    /**
     * Active-theme-mismatch guard (docs/frontier/fse.md: "there is no
     * active-theme-mismatch guard — if the target environment's active
     * theme differs from the captured wp_theme term's slug, the applied
     * template silently becomes inert ... with zero warning anywhere in
     * the plan or apply output"). Verified empirically on the fse
     * conformance fixture: wp_theme is an ordinary POST-object taxonomy
     * (registered object_type wp_template/wp_template_part/
     * wp_global_styles) whose term identity IS the theme's own stylesheet
     * slug, so a captured wp_template/wp_template_part carries it in the
     * ordinary `terms.wp_theme` field — wp_navigation/wp_block carry no
     * wp_theme term at all (confirmed: their captured `terms` is always
     * `{}`), so this needs no post-type allowlist; it falls out for free
     * from whichever entities actually have a wp_theme relationship.
     * WordPress's template resolver only ever matches a row tagged for
     * get_option('stylesheet') — a row tagged for any OTHER theme applies
     * (the row lands, byte-identical) but never renders. Warning, never a
     * block: the data is correct: rendering is the only casualty.
     */
    private function check_theme_mismatch(array $tree): void {
        $themeSlugByUuid = [];
        foreach ($tree as $uuid => $e) {
            if ($e['type'] === 'term') {
                $front = $e['data'];
                if (($front['taxonomy'] ?? '') === 'wp_theme') {
                    $themeSlugByUuid[$uuid] = $front['slug'];
                }
            }
        }
        if (!$themeSlugByUuid) {
            return; // no wp_theme terms anywhere in this tree — not an FSE site, nothing to check
        }
        $active = (string) get_option('stylesheet');
        $affected = []; // captured theme slug => affected entity path list
        foreach ($tree as $e) {
            if ($e['type'] !== 'post') {
                continue;
            }
            $front = $e['data'];
            foreach ((array) ($front['terms']['wp_theme'] ?? []) as $themeUuid) {
                $slug = $themeSlugByUuid[$themeUuid] ?? null;
                if ($slug !== null && $slug !== $active) {
                    $affected[$slug][] = $e['path'];
                }
            }
        }
        foreach ($affected as $capturedTheme => $paths) {
            $verb = count($paths) === 1 ? 'is tagged for' : 'are tagged for';
            $this->warnings[] = "active-theme mismatch: this environment's active theme is '$active' but "
                . implode(', ', $paths) . " $verb theme '$capturedTheme'"
                . " — will apply but will NOT render until '$capturedTheme' is active here";
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
        $this->selectedActions = $this->policy->actions_for(
            $this->rebuild_surfaces($work, $tree, $rebuildDeleteWork)
        );
        if ($scopedPromotion) {
            $this->assert_scoped_promotion_selection($work, $deleteWork, $tree);
        }
        if ($scoped) {
            foreach ($this->selectedActions as $action) {
                if (!array_key_exists('triggers', $action)) {
                    throw new \RuntimeException(
                        'duo: scoped apply refused before target mutation — an untriggered global action has no bounded scope authority'
                    );
                }
            }
            foreach ($work as $entry) {
                $entity = $tree[(string) ($entry['uuid'] ?? '')] ?? null;
                $postType = is_array($entity) && ($entity['type'] ?? '') === 'post'
                    ? (string) ($entity['data']['type'] ?? '')
                    : '';
                if ($postType === 'attachment') {
                    throw new \RuntimeException(
                        'duo: scoped apply refused before target mutation — attachment metadata generation has no operation-bound reconciliation contract in this slice'
                    );
                }
                if ($postType !== '' && $this->policy->regen_dependency($postType) !== null) {
                    throw new \RuntimeException(
                        "duo: scoped apply refused before target mutation — post type '$postType' selects a legacy regenerator without operation-bound reconciliation"
                    );
                }
            }
            foreach ($deleteWork as $entry) {
                $postType = ($entry['type'] ?? '') === 'post'
                    ? (string) ($entry['deletion_type'] ?? '')
                    : '';
                if ($postType !== '' && $this->policy->regen_dependency($postType) !== null) {
                    throw new \RuntimeException(
                        "duo: scoped apply refused before target mutation — deleted post type '$postType' "
                        . 'selects a legacy regenerator without operation-bound reconciliation'
                    );
                }
            }
        }
        $negotiation = $scoped && method_exists(Providers::class, 'negotiate_scoped')
            ? Providers::negotiate_scoped($this->policy, $this->selectedActions)
            : Providers::negotiate($this->policy, $this->selectedActions);
        if ($negotiation['problems'] !== []) {
            throw new \RuntimeException(
                'duo: apply refused before target mutation — declared provider capabilities are unavailable '
                . "or incompatible in this environment:\n  - "
                . implode("\n  - ", array_column($negotiation['problems'], 'message'))
            );
        }
        if ($scoped) {
            foreach ($this->selectedActions as $action) {
                if (($action['kind'] ?? '') !== 'provider') {
                    continue;
                }
                $providerId = (string) ($action['provider'] ?? '');
                $capability = (string) ($action['capability'] ?? '');
                $declaration = $negotiation['capabilities'][$providerId][$capability] ?? null;
                if (!is_array($declaration)) {
                    continue; // negotiation problems above already own this refusal.
                }
                foreach (['deletions', 'reparents'] as $channel) {
                    if (Providers::declares_channel($declaration, $channel)) {
                        throw new \RuntimeException(
                            "duo: scoped apply refused before target mutation — provider channel '$channel' "
                            . 'requires durable environment-local recovery input not carried by this scoped protocol version'
                        );
                    }
                }
            }
        }
        $this->negotiatedProviders = [
            'providers' => $negotiation['providers'],
            'capabilities' => $negotiation['capabilities'],
            'scoped_capabilities' => (array) ($negotiation['scoped_capabilities'] ?? []),
        ];
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
        $attachmentIds = [];
        $regenContext = [];
        $transactionStarted = false;
        if (!$scoped || $performAuthoredTransaction) {
            Canary::arm();
            try {
            Db::start('apply transaction start');
            $transactionStarted = true;

            // Guard reads in the preflight plan are evidence only. Before any
            // phase-2 repair (especially parent metadata removal or option
            // tombstone deletion), take current-read locks over every guard
            // boundary and compare the exact witness captured by that plan.
            // This closes the SELECT/delete and SELECT/repair windows; the
            // locks remain held through the authored delete and COMMIT.
            if ($executeDeletes && $deleteWork) {
                $this->lock_and_revalidate_delete_guards(
                    $deleteWork,
                    $deleteUuids,
                    $compiled->deletions(),
                    $tree,
                    $guardRepairUuids
                );
            }

            // Capture an existing batch-owned child's previous parent before
            // phase 1/finalize can move its post_parent. The receipt is in the
            // authored transaction, so a later rebuild failure can replay the
            // old-root cleanup without guessing from the new parent only.
            $regenContext = $this->capture_regen_reparent_context($work, $tree, !$scoped);

            // ---- adopt: claim unmanaged env rows by writing identity ----
            foreach ($plan['adopt'] as $r) {
                $this->renew_promotion_lock('apply-adopt');
                $this->adopt($r, $tree[$r['uuid']]);
            }

            // Widget allocation is a ledger mutation. In a bounded apply the
            // selected entity set is its only authority, so never hand the
            // allocator an unrelated sidebar merely because it shares the
            // frozen compiled tree with the selected work. Finalization still
            // receives the full tree below for its read-only global desired
            // scan, which prevents cross-sidebar widget-instance deletion.
            $sidebarAllocationTree = $scoped
                ? array_intersect_key($tree, ScopedApply::selected_set($this->scopeContract))
                : $tree;
            foreach ($work as $r) {
                if (($tree[$r['uuid']]['type'] ?? '') === SidebarState::ENTITY_TYPE) {
                    SidebarState::ensure_widgets($this->policy, $sidebarAllocationTree);
                    break;
                }
            }

            // ---- phase 1: rows exist with placeholder refs ----
            foreach ($work as $r) {
                $this->renew_promotion_lock('apply-phase-1');
                $e = $tree[$r['uuid']];
                if (isset($this->snapshotRowTables()[$e['type']])) {
                    Snapshot::ensure_row($this->policy, $e);
                } elseif ($e['type'] === 'term') {
                    $this->ensure_term_row($e['data'], 'term');
                } elseif ($e['type'] === 'menu') {
                    $front = $e['data'];
                    $this->ensure_term_row([
                        'uuid' => $front['uuid'], 'taxonomy' => 'nav_menu',
                        'name' => $front['name'], 'slug' => $front['slug'],
                        'description' => '', 'parent' => null,
                    ], 'menu');
                } elseif ($e['type'] === 'post') {
                    $front = $e['data'];
                    $this->ensure_post_row($front);
                    if ($front['type'] === 'attachment') {
                        // Metadata derives from bytes, so updates/adoptions
                        // need the same rebuild as creates. Retry also lands
                        // here because incomplete work is replayed.
                        $attachmentIds[] = Ledger::id_for($front['uuid'], Ledger::KIND_POST);
                    }
                } elseif ($e['type'] === 'user-meta') {
                    // Users are target-local and are never created/adopted.
                    // Exact-login existence was proven during planning.
                } elseif ($e['type'] === SidebarState::ENTITY_TYPE) {
                    // Nested widget rows were allocated once above.
                }
            }

            // ---- phase 2: resolve refs, full field/meta/relationship state ----
            // 'early' post types (manifest post_types {"phase": "early"}, e.g.
            // acf-field*) finalize first: interpreters read their finalized
            // content from the DB to type other entities' meta. Stable sort —
            // everything else keeps tree order.
            $phase2 = $work;
            usort($phase2, fn($x, $y) =>
                $this->phase2_rank($tree[$x['uuid']]) <=> $this->phase2_rank($tree[$y['uuid']]));
            foreach ($phase2 as $r) {
                $this->renew_promotion_lock('apply-phase-2');
                $e = $tree[$r['uuid']];
                if (isset($this->snapshotRowTables()[$e['type']])) {
                    Snapshot::finalize_row($this->policy, $this->tokens, $e);
                } elseif ($e['type'] === 'term') {
                    $this->finalize_term($e['data']);
                } elseif ($e['type'] === 'post') {
                    $this->finalize_post($e['data'], $e['body']);
                } elseif ($e['type'] === 'menu') {
                    $this->finalize_menu($e['data']);
                } elseif ($e['type'] === 'options') {
                    $this->apply_options($e['data'], !empty($opts['with_deletes']));
                } elseif ($e['type'] === 'user-meta') {
                    $this->finalize_user_meta($e['data']);
                } elseif ($e['type'] === SidebarState::ENTITY_TYPE) {
                    $sidebar = SidebarState::sidebar_from_path((string) $e['path']);
                    if ($sidebar === null) {
                        throw new \RuntimeException("duo: invalid compiled sidebar path {$e['path']}");
                    }
                    SidebarState::finalize_sidebar(
                        $this->policy,
                        $this->tokens,
                        $e['data'],
                        $sidebar,
                        $tree,
                        $scoped
                    );
                }
            }

            // ---- explicit tombstone deletes (still flag-gated) ----
            if ($executeDeletes) {
                // Recheck every delete guard before writing a durable receipt.
                // This keeps the receipt behind all refusal/precondition gates,
                // while the receipt itself and the raw deletes remain in the
                // same transaction and therefore commit or roll back together.
                foreach ($deleteWork as $r) {
                    $this->renew_promotion_lock('apply-delete');
                    $this->recheck_delete_guards(
                        $r,
                        $deleteUuids,
                        $compiled->deletions(),
                        !empty($opts['force_delete_referenced']),
                        $tree,
                        $guardRepairUuids,
                        true
                    );
                }

                // Derived lookup regeneration can need a declared parent's
                // local child ids after their post rows are deleted. Capture
                // that context only after all gates above have passed,
                // immediately before the delete writes, and inside the
                // authored transaction.
                $regenContext = array_merge($regenContext, $this->capture_regen_delete_context(
                    array_merge($deleteWork, $plan['deleted']),
                    !$scoped
                ));
                foreach ($deleteWork as $r) {
                    $this->renew_promotion_lock('apply-delete');
                    $this->delete_entity($r['uuid'], $r['type']);
                }
            }

            $violations = Canary::violations();
            if ($violations) {
                throw new \RuntimeException(
                    "duo: side-effect canary tripped:\n  - " . implode("\n  - ", $violations)
                );
            }
            Db::commit('apply transaction commit');
            $transactionStarted = false;
            } catch (\Throwable $t) {
                if ($transactionStarted) {
                    try {
                        Db::rollback('apply transaction rollback');
                    } catch (DatabaseMutationException $rollback) {
                        Canary::disarm();
                        throw new DatabaseMutationException($rollback->mutationContext, $t);
                    }
                }
                Canary::disarm();
                throw $t;
            }
            Canary::disarm();
        }

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
        $ledgerTransactionStarted = false;
        try {
            $this->renew_promotion_lock('apply-ledger');
            Db::start('ledger transaction start');
            $ledgerTransactionStarted = true;
            if (!$scoped) {
                Ledger::kv_delete('apply_in_progress');
            }
            foreach ($scoped ? $work : array_merge($plan['unchanged'], $work) as $r) {
                $e = $tree[$r['uuid']];
                Ledger::set_state_hash($r['uuid'], $e['type'], $e['hash']);
            }
            if ($executeDeletes) {
                foreach (array_merge($deleteWork, $plan['deleted']) as $r) {
                    Ledger::forget($r['uuid']);
                    Ledger::set_state_hash($r['uuid'], 'deletion', $r['receipt_hash']);
                }
            }
            // The compiler revision is the truthful default receipt. An
            // orchestrator may still supply a git commit/ref for operator-
            // facing provenance, but the receipt moves atomically with every
            // state hash only after required rebuilds have succeeded.
            if ($scoped) {
                if ($this->scopedSession === null
                    || $this->scopedSession->phase() !== ScopedApplySession::PHASE_VERIFYING) {
                    throw new \RuntimeException('duo: scoped ledger finalization has no exact verifying session');
                }
                $convergenceHash = (string) ($verification['receipt_hash'] ?? '');
                if (preg_match('/^[a-f0-9]{64}$/D', $convergenceHash) !== 1) {
                    throw new \RuntimeException('duo: scoped convergence receipt has no valid identity');
                }
                // The session CAS and selected ledger updates share this exact
                // database transaction, so COMMIT resolves to either a still-
                // active verifying session or one terminal receipt plus all
                // selected base updates. Global applied_revision never moves.
                $terminalMapRoots = ScopedApply::ledger_map_roots(
                    (array) $this->scopedSession->authority()['selection']['ledger_map_identity_hashes']
                );
                if (!hash_equals(
                    (string) $this->scopedSession->authority()['target']['protected_ledger_map_hash'],
                    (string) $terminalMapRoots['protected_ledger_map_root']
                )) {
                    throw new \RuntimeException(
                        'duo: protected identity map changed during scoped ledger finalization'
                    );
                }
                $this->scopedSession->complete($convergenceHash, [
                    'protected_ledger_map_hash' => (string) $terminalMapRoots['protected_ledger_map_root'],
                    'selected_ledger_map_hash' => (string) $terminalMapRoots['selected_ledger_map_root'],
                ]);
            } else {
                Ledger::kv_set(
                    'applied_revision',
                    !empty($opts['revision']) ? (string) $opts['revision'] : $compiled->revision_hash()
                );
            }
            Db::commit('ledger transaction commit');
            $ledgerTransactionStarted = false;
        } catch (\Throwable $t) {
            if ($ledgerTransactionStarted) {
                try {
                    Db::rollback('ledger transaction rollback');
                } catch (DatabaseMutationException $rollback) {
                    throw new DatabaseMutationException($rollback->mutationContext, $t);
                }
            }
            throw $t;
        }

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

    /** Hash only facts which authorize target mutation; output/report buckets are excluded. */
    private function plan_precondition_hash(array $plan): string {
        $keys = [
            'create', 'update', 'unchanged', 'drift', 'conflict', 'adopt',
            'collision', 'delete', 'delete_conflict', 'deleted',
            'code_mismatch', 'code_drift', 'incomplete_apply', 'regen_pending', 'regen_context',
            'missing_user', 'skipped_user_meta', 'uploads_inventory', 'effects_inventory',
        ];
        $basis = [];
        foreach ($keys as $key) {
            $basis[$key] = $plan[$key] ?? [];
        }
        return hash('sha256', Canon::encode($basis));
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
        $sessionId = PromotionLock::scoped_session_id($this->promotionOwner, $this->promotionArtifact);
        $workRows = [];
        foreach ($work as $row) {
            $identity = (string) ($row['uuid'] ?? '');
            $entity = $compiled->tree()[$identity] ?? null;
            if (!is_array($entity)) {
                throw new \RuntimeException('duo: scoped work identity disappeared from frozen artifact');
            }
            $workRows[] = [
                'identity_hash' => hash('sha256', $identity),
                'type' => (string) ($entity['type'] ?? ''),
                'desired_hash' => $this->verification_hash($entity),
            ];
        }
        usort($workRows, static fn(array $a, array $b): int =>
            strcmp(Canon::encode($a), Canon::encode($b))
        );
        $deletionRows = [];
        foreach ($deleteWork as $row) {
            $identityHash = hash('sha256', (string) ($row['uuid'] ?? ''));
            $deletionRows[] = [
                'identity_hash' => $identityHash,
                'receipt_hash' => (string) ($row['receipt_hash'] ?? ''),
                'deletion_kind' => (string) ($row['deletion_kind'] ?? ''),
                'deletion_type' => (string) ($row['deletion_type'] ?? ''),
            ];
        }
        usort($deletionRows, static fn(array $a, array $b): int =>
            strcmp(Canon::encode($a), Canon::encode($b))
        );
        $actionRows = [];
        $effectRows = [];
        foreach ($this->selectedActions as $action) {
            $index = (int) ($action['index'] ?? 0);
            $row = [
                'manifest' => (string) ($action['manifest'] ?? ''),
                'index' => $index,
                'declaration_hash' => hash('sha256', Canon::encode($action)),
            ];
            $actionRows[] = $row;
            foreach (Policy::action_effects($action, $index) as $effect) {
                $effectRows[] = [
                    'action_hash' => hash('sha256', Canon::encode($row)),
                    'effect_hash' => hash('sha256', Canon::encode($effect)),
                ];
            }
        }
        usort($actionRows, static fn(array $a, array $b): int =>
            strcmp(Canon::encode($a), Canon::encode($b))
        );
        usort($effectRows, static fn(array $a, array $b): int =>
            strcmp(Canon::encode($a), Canon::encode($b))
        );
        $capabilities = (array) ($negotiation['scoped_capabilities'] ?? []);
        if ($this->selectedActions !== [] && !method_exists(Providers::class, 'negotiate_scoped')) {
            throw new \RuntimeException(
                'duo: scoped apply requires operation-bound action reconciliation before target mutation'
            );
        }
        $ledgerMapIdentityHashes = $this->scoped_ledger_map_identity_hashes_from_observation();
        return ScopedApplySession::make_authority(
            (string) $this->scopeContract['scope_hash'],
            [
                'artifact_hash' => $compiled->artifact_hash(),
                'state_revision_hash' => $compiled->revision_hash(),
                'manifest_hash' => $compiled->manifest_hash(),
            ],
            [
                'owner' => $this->promotionOwner,
                'artifact_hash' => $this->promotionArtifact,
                'session_id' => $sessionId,
            ],
            [
                'selected_before_hash' => (string) $this->scopedObservation['selected_before_root'],
                'selected_before_ledger_map_hash' => (string) $this->scopedObservation['selected_ledger_map_root'],
                'protected_ledger_map_hash' => (string) $this->scopedObservation['protected_ledger_map_root'],
                'protected_out_of_scope_hash' => (string) $this->scopedObservation['protected_out_of_scope_root'],
                'ledger_roots_hash' => hash('sha256', Canon::encode([
                    'selected' => (string) $this->scopedObservation['selected_ledger_map_root'],
                    'protected' => (string) $this->scopedObservation['protected_ledger_map_root'],
                ])),
            ],
            [
                'precondition_hash' => $this->plan_precondition_hash($plan),
                'guard_witnesses_hash' => $this->scoped_guard_witnesses_hash($deleteWork),
            ],
            [
                'work_hash' => hash('sha256', Canon::encode($workRows)),
                'work_items' => $workRows,
                'deletions_hash' => hash('sha256', Canon::encode($deletionRows)),
                'deletion_items' => $deletionRows,
                'action_declarations_hash' => hash('sha256', Canon::encode($actionRows)),
                'action_items' => $actionRows,
                'capabilities_hash' => hash('sha256', Canon::encode($capabilities)),
                'effects_hash' => hash('sha256', Canon::encode($effectRows)),
                'effect_items' => $effectRows,
                'ledger_map_identity_hashes' => $ledgerMapIdentityHashes,
                'ledger_map_identity_set_hash' => ScopedApplySession::hash_value($ledgerMapIdentityHashes),
            ],
            ScopedApply::code_witness_hash($plan, $compiled),
            $this->scopedPromotionWitness === null
                ? null
                : ScopedApplySession::external_promotion_binding(
                    $this->scopedPromotionWitness,
                    $allowDeletes
                )
        );
    }

    /** @return ?list<string> opaque SHA-256 UUID hashes sealed by an active authority */
    private function scoped_ledger_map_identity_hashes(): ?array {
        if ($this->scopedSession === null) {
            return null;
        }
        $hashes = $this->scopedSession->authority()['selection']['ledger_map_identity_hashes'] ?? null;
        return $this->assert_scoped_ledger_map_identity_hashes($hashes, 'authority');
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
        if ($this->scopedSession === null) {
            return true;
        }
        if (!in_array($this->scopedSession->phase(), [
            ScopedApplySession::PHASE_PLANNED,
            ScopedApplySession::PHASE_AUTHORING,
        ], true) || $this->scopeContract === null) {
            return false;
        }
        return ScopedApply::selected_observation_matches_before(
            $actual,
            $this->scopeContract,
            (string) ($this->scopedSession->authority()['target']['selected_before_hash'] ?? '')
        );
    }

    /** @return list<string> opaque SHA-256 UUID hashes derived before authority minting */
    private function scoped_ledger_map_identity_hashes_from_observation(): array {
        if ($this->scopedObservation === null) {
            throw new \RuntimeException('duo: scoped mutation authority has no ledger-map identity observation');
        }
        return $this->assert_scoped_ledger_map_identity_hashes(
            $this->scopedObservation['_ledger_map_identity_hashes'] ?? null,
            'observation'
        );
    }

    /** @return list<string> */
    private function assert_scoped_ledger_map_identity_hashes(mixed $hashes, string $source): array {
        if (!is_array($hashes) || !array_is_list($hashes)) {
            throw new \RuntimeException("duo: scoped ledger-map $source has no canonical opaque identity hashes");
        }
        $previous = null;
        foreach ($hashes as $identityHash) {
            if (!is_string($identityHash)
                || preg_match('/^[a-f0-9]{64}$/D', $identityHash) !== 1
                || ($previous !== null && strcmp($previous, $identityHash) >= 0)) {
                throw new \RuntimeException("duo: scoped ledger-map $source has invalid opaque identity hashes");
            }
            $previous = $identityHash;
        }
        return $hashes;
    }

    private function scoped_guard_witnesses_hash(array $deleteWork): string {
        $rows = [];
        foreach ($deleteWork as $row) {
            $rows[] = [
                'identity_hash' => hash('sha256', (string) ($row['uuid'] ?? '')),
                'witnesses_hash' => hash('sha256', Canon::encode((array) ($row['guard_witnesses'] ?? []))),
            ];
        }
        usort($rows, static fn(array $a, array $b): int =>
            strcmp(Canon::encode($a), Canon::encode($b))
        );
        return hash('sha256', Canon::encode($rows));
    }

    /** Re-prove immutable action declarations and scoped capability digests on recovery. */
    private function assert_scoped_recovery_selection(array $negotiation): void {
        if ($this->scopedSession === null) {
            throw new \RuntimeException('duo: scoped action recovery has no durable authority');
        }
        $selection = $this->scopedSession->authority()['selection'];
        $actions = [];
        $effects = [];
        foreach ($this->selectedActions as $action) {
            $index = (int) ($action['index'] ?? 0);
            $row = [
                'manifest' => (string) ($action['manifest'] ?? ''),
                'index' => $index,
                'declaration_hash' => hash('sha256', Canon::encode($action)),
            ];
            $actions[] = $row;
            foreach (Policy::action_effects($action, $index) as $effect) {
                $effects[] = [
                    'action_hash' => hash('sha256', Canon::encode($row)),
                    'effect_hash' => hash('sha256', Canon::encode($effect)),
                ];
            }
        }
        foreach ([&$actions, &$effects] as &$rows) {
            usort($rows, static fn(array $a, array $b): int =>
                strcmp(Canon::encode($a), Canon::encode($b))
            );
        }
        unset($rows);
        if (Canon::encode($actions) !== Canon::encode((array) ($selection['action_items'] ?? []))
            || Canon::encode($effects) !== Canon::encode((array) ($selection['effect_items'] ?? []))
            || !hash_equals(
                (string) ($selection['capabilities_hash'] ?? ''),
                hash('sha256', Canon::encode((array) ($negotiation['scoped_capabilities'] ?? [])))
            )) {
            throw new \RuntimeException(
                'duo: scoped apply recovery action/capability evidence changed; opaque effects were not replayed'
            );
        }
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
        return [
            'ordinal' => $ordinal,
            'authority_hash' => $this->scopedSession->authority_hash_value(),
            'lease_hash' => ScopedApplySession::lease_hash($this->scopedSession->lease()),
            'action_hash' => hash('sha256', $actionIdentity),
            'operation_hash' => hash('sha256', $operationIdentity),
            'input_hash' => $inputHash,
            'effect_hash' => $effectHash,
            'before_hash' => $beforeHash,
        ];
    }

    /** Bind a post-operation readback hash to an exact persisted intent. */
    private function scoped_session_receipt(array $intent, string $afterHash): array {
        return $intent + ['after_hash' => $afterHash];
    }

    private function scoped_action_effect_hash(array $action): string {
        return hash('sha256', Canon::encode(Policy::action_effects(
            $action,
            (int) ($action['index'] ?? 0)
        )));
    }

    /** @return array{authority_hash:string,lease_session_id:string,operation_id:string,input_hash:string,effect_hash:string} */
    private function scoped_effect_operation(int $ordinal, string $inputHash, string $effectHash): array {
        if ($this->scopedSession === null) {
            throw new \RuntimeException('duo: scoped effect has no durable session');
        }
        return [
            'authority_hash' => $this->scopedSession->authority_hash_value(),
            'lease_session_id' => $this->scopedSession->session_id(),
            'operation_id' => 'effect-' . substr($this->scopedSession->authority_hash_value(), 0, 24) . '-' . $ordinal,
            'input_hash' => $inputHash,
            'effect_hash' => $effectHash,
        ];
    }

    /** Require the reviewed, hash-only scoped effect receipt to bind exactly. */
    private function assert_scoped_effect_result(
        array $result,
        array $operation,
        string $capabilityDigest
    ): void {
        $keys = array_keys($result);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'after_hash', 'before_hash', 'capability_digest', 'format',
            'operation', 'status', 'verified',
        ]
            || ($result['format'] ?? '') !== Providers::SCOPED_RECEIPT_FORMAT
            || ($result['status'] ?? '') !== 'verified'
            || ($result['verified'] ?? null) !== true
            || Canon::encode((array) ($result['operation'] ?? [])) !== Canon::encode($operation)
            || !hash_equals($capabilityDigest, (string) ($result['capability_digest'] ?? ''))
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($result['before_hash'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($result['after_hash'] ?? '')) !== 1) {
            throw new \RuntimeException('duo: scoped effect returned an invalid reviewed receipt');
        }
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
        if ($receipt === null) {
            throw new \RuntimeException('duo: scoped action has no durable outer receipt');
        }
        return [
            'format' => Providers::SCOPED_RECEIPT_FORMAT,
            'source_hash' => hash('sha256', $source),
            'kind' => $kind,
            'operation_hash' => hash('sha256', Canon::encode($operation)),
            'capability_digest' => $capabilityDigest,
            'receipt_hash' => hash('sha256', Canon::encode($receipt)),
            'status' => $status,
            'verified' => true,
        ];
    }

    /** @return array<string,mixed>|null */
    private function scoped_receipt_at(int $ordinal): ?array {
        if ($this->scopedSession === null) {
            return null;
        }
        foreach ($this->scopedSession->receipts() as $receipt) {
            if ((int) ($receipt['ordinal'] ?? 0) === $ordinal) {
                return $receipt;
            }
        }
        return null;
    }

    /** Hash-only checked projection of engine-owned derived effects. */
    private function scoped_core_readback_hash(array $work, array $tree): string {
        global $wpdb;
        $schedules = [];
        foreach ($work as $entry) {
            $identity = (string) ($entry['uuid'] ?? '');
            $entity = $tree[$identity] ?? null;
            if (!is_array($entity) || ($entity['type'] ?? '') !== 'post') {
                continue;
            }
            $id = Ledger::id_for($identity, Ledger::KIND_POST);
            if ($id === null) {
                continue;
            }
            $schedules[] = [
                'identity_hash' => hash('sha256', $identity),
                'next_publish_hash' => hash('sha256', Canon::encode(
                    wp_next_scheduled('publish_future_post', [$id])
                )),
            ];
        }
        usort($schedules, static fn(array $a, array $b): int =>
            strcmp(Canon::encode($a), Canon::encode($b))
        );
        $counts = [];
        $taxonomies = array_values(array_unique(array_merge($this->policy->taxonomies(), ['nav_menu'])));
        sort($taxonomies, SORT_STRING);
        foreach ($taxonomies as $taxonomy) {
            $wpdb->last_error = '';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT term_taxonomy_id, count FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s ORDER BY term_taxonomy_id",
                $taxonomy
            ), ARRAY_A);
            if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException(
                    'duo: scoped engine-effect taxonomy-count readback failed; recovery_required'
                );
            }
            foreach ($rows as $row) {
                $counts[] = [
                    'taxonomy_hash' => hash('sha256', (string) $taxonomy),
                    'target_identity_hash' => hash('sha256', (string) ($row['term_taxonomy_id'] ?? '')),
                    'count' => (int) ($row['count'] ?? 0),
                ];
            }
        }
        return hash('sha256', Canon::encode([
            'future_schedules' => $schedules,
            'taxonomy_counts' => $counts,
        ]));
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
        foreach ($deleteWork as $row) {
            $capability = Deletion::capability(
                $this->policy,
                (string) $row['deletion_kind'],
                (string) $row['deletion_type']
            );
            foreach ($capability['guards'] ?? [] as $guardIndex => $guard) {
                $result = $this->count_guard_refs(
                    $guard,
                    (string) $row['uuid'],
                    $deleteUuids,
                    $deletions,
                    $tree,
                    $guardRepairUuids,
                    true
                );
                if ($result['error'] !== null) {
                    throw new \RuntimeException(
                        "duo: deletion guard lock refused for {$row['type']} {$row['uuid']}: {$result['error']}"
                    );
                }
                $expected = (string) (($row['guard_witnesses'] ?? [])[(string) $guardIndex] ?? '');
                $actual = (string) ($result['witness'] ?? '');
                if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
                    throw new \RuntimeException(
                        "duo: deletion guard witness changed after planning for {$row['type']} {$row['uuid']}; "
                        . 'no mutation attempted — recompile and retry (force flags cannot bypass this race boundary)'
                    );
                }
            }
        }
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

        $tables = array_keys($tables);
        sort($tables, SORT_STRING);
        $tableSet = array_fill_keys($tables, true);
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));
        // Lock each table's metadata before asking information_schema for its
        // engine.  A concurrent ALTER/RENAME/DROP must either finish before
        // this read (so we inspect the resulting table) or wait for this
        // transaction to end; otherwise an engine row could become stale
        // between the check and the first SELECT ... FOR UPDATE.  This is a
        // harmless data read, not a row lock, and it occurs before authored
        // target mutations.
        foreach ($tables as $table) {
            $wpdb->last_error = '';
            $probe = $wpdb->get_var("SELECT 1 FROM `$table` LIMIT 1");
            $error = trim((string) ($wpdb->last_error ?? ''));
            if ($probe === false || $error !== '') {
                $detail = $error !== '' ? $error : 'no result returned';
                throw new \RuntimeException(
                    'duo: deletion guard locking refused — unable to acquire metadata lock for guard table '
                    . "$table: $detail"
                );
            }
        }
        // wpdb can retain the previous failed-query message (notably when
        // the modern isolation variable probe falls back to tx_isolation),
        // so clear it before this independent introspection query.
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)
             ORDER BY TABLE_NAME ASC",
            ...$tables
        ), ARRAY_A);
        $error = trim((string) ($wpdb->last_error ?? ''));
        if ($rows === false || $rows === null || $error !== '') {
            $detail = $error !== '' ? $error : 'no result returned';
            throw new \RuntimeException(
                'duo: deletion guard locking refused — storage-engine introspection failed for '
                . implode(', ', $tables) . ": $detail"
            );
        }

        $engines = [];
        $duplicateRows = [];
        foreach ((array) $rows as $row) {
            $name = (string) ($row['TABLE_NAME'] ?? '');
            if ($name === '' || !isset($tableSet[$name])) {
                continue;
            }
            if (array_key_exists($name, $engines)) {
                $duplicateRows[$name] = true;
                continue;
            }
            $engine = $row['ENGINE'] ?? null;
            $engines[$name] = $engine === null || trim((string) $engine) === ''
                ? null
                : strtoupper(trim((string) $engine));
        }

        $missing = array_values(array_diff($tables, array_keys($engines)));
        $unknown = [];
        $unsupported = [];
        foreach ($engines as $name => $engine) {
            if ($engine === null) {
                $unknown[] = "$name (engine: NULL/unknown)";
            } elseif ($engine !== 'INNODB') {
                $unsupported[] = "$name (engine: $engine)";
            }
        }
        foreach (array_keys($duplicateRows) as $name) {
            $unknown[] = "$name (duplicate information_schema rows)";
        }
        sort($missing, SORT_STRING);
        sort($unknown, SORT_STRING);
        sort($unsupported, SORT_STRING);
        if ($missing || $unknown || $unsupported) {
            $details = [];
            if ($missing) {
                $details[] = 'missing from information_schema.TABLES: ' . implode(', ', $missing);
            }
            if ($unknown) {
                $details[] = 'unknown engine: ' . implode(', ', $unknown);
            }
            if ($unsupported) {
                $details[] = 'unsupported engine (InnoDB required): ' . implode(', ', $unsupported);
            }
            throw new \RuntimeException(
                'duo: deletion guard locking refused — ' . implode('; ', $details)
            );
        }
    }

    /**
     * Gap locks are part of this race boundary. Refuse a target whose
     * isolation level would provide only record locks, because an external
     * insert could then pass the locked read and become a dangling reference.
     */
    private function assert_delete_lock_isolation(): void {
        global $wpdb;
        $level = $wpdb->get_var('SELECT @@transaction_isolation');
        if ($level === null || !empty($wpdb->last_error)) {
            // MariaDB and older MySQL expose the same session setting under
            // the historical tx_isolation name; MySQL 8 keeps the modern
            // transaction_isolation spelling. Probe both without assuming a
            // particular server family.
            $wpdb->last_error = '';
            $level = $wpdb->get_var('SELECT @@tx_isolation');
        }
        if ($level === null || !in_array(strtoupper((string) $level), ['REPEATABLE-READ', 'SERIALIZABLE'], true)) {
            throw new \RuntimeException(
                'duo: deletion guard locking requires REPEATABLE-READ or SERIALIZABLE transaction isolation; refusing unsafe target'
            );
        }
    }

    /**
     * Resolve an index which covers the first equality/range column of a
     * manifest guard. A prefix index is accepted only when the declared
     * metadata key fits entirely inside that prefix; otherwise inserts with
     * the same visible prefix could still evade the gap lock.
     */
    private function guard_lock_index(array $guard, string $table): ?string {
        global $wpdb;
        $lockColumn = array_key_exists('meta_key', $guard) || array_key_exists('ref', $guard)
            ? 'meta_key'
            : (!empty($guard['option_name_ref']) ? 'option_name' : (string) ($guard['column'] ?? ''));
        $rows = $wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A) ?: [];
        $indexes = [];
        foreach ($rows as $row) {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($row['Key_name'] ?? ''));
            $seq = (int) ($row['Seq_in_index'] ?? 0);
            if ($name === '' || $seq <= 0) {
                continue;
            }
            $indexes[$name][$seq] = [
                'column' => preg_replace('/[^A-Za-z0-9_]/', '', (string) ($row['Column_name'] ?? '')),
                'prefix' => isset($row['Sub_part']) && $row['Sub_part'] !== null
                    ? (int) $row['Sub_part']
                    : null,
            ];
        }
        foreach ($indexes as $name => $parts) {
            ksort($parts, SORT_NUMERIC);
            $first = reset($parts);
            if (($first['column'] ?? '') !== $lockColumn) {
                continue;
            }
            $prefix = $first['prefix'] ?? null;
            if ($prefix !== null && array_key_exists('meta_key', $guard)
                && strlen((string) $guard['meta_key']) > $prefix) {
                continue;
            }
            return $name;
        }
        return null;
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
        $blocks = [];
        $guardRefs = [];
        foreach ($capability['guards'] ?? [] as $guard) {
            $result = $this->count_guard_refs(
                $guard,
                (string) $row['uuid'],
                $deleteUuids,
                $deletions,
                $tree,
                $guardRepairUuids,
                $forUpdate
            );
            if ($result['error'] !== null) {
                $blocks[] = $result['error'];
            } elseif ($result['count'] > 0) {
                $blocks[] = ($guard['reason'] ?? "referenced by {$guard['table']}.{$guard['column']}")
                    . " — {$result['count']} row(s)";
                $guardRefs[] = [
                    'table' => (string) $guard['table'],
                    'rows' => $result['rows'],
                    'repairable' => isset($this->snapshotRowTables()[(string) $guard['table']]),
                    'option_name_ref' => !empty($guard['option_name_ref']),
                ];
            }
        }
        if (!$blocks) {
            return;
        }
        $reason = implode('; ', $blocks);
        if (!$forced) {
            throw new \RuntimeException(
                "duo: delete guard changed before mutation for {$row['type']} {$row['uuid']}: $reason"
            );
        }
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
        global $wpdb;
        $envId = (int) $row['env_id'];
        if (isset($this->snapshotRowTables()[$e['type']])) {
            Snapshot::adopt($this->policy, $row['uuid'], $e['type'], $envId);
            $this->warnings[] = "adopted env table row {$e['type']}:$envId as {$row['uuid']} ({$row['path']})";
            return;
        }
        if ($e['type'] === 'post') {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_duo_uuid' LIMIT 1", $envId
            ));
            if (!$existing) {
                Db::insert($wpdb->postmeta, ['post_id' => $envId, 'meta_key' => '_duo_uuid', 'meta_value' => $row['uuid']], null, 'adopt post identity');
            }
            Ledger::set($row['uuid'], 'post', Ledger::KIND_POST, $envId);
            $this->warnings[] = "adopted env post $envId as {$row['uuid']} ({$row['path']})";
        } else {
            $tt = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt WHERE tt.term_id = %d LIMIT 1", $envId
            ));
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_id FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = '_duo_uuid' LIMIT 1", $envId
            ));
            if (!$existing) {
                Db::insert($wpdb->termmeta, ['term_id' => $envId, 'meta_key' => '_duo_uuid', 'meta_value' => $row['uuid']], null, 'adopt term identity');
            }
            Ledger::set($row['uuid'], $e['type'], Ledger::KIND_TERM, $envId);
            Ledger::set($row['uuid'], $e['type'], Ledger::KIND_TT, $tt);
            $this->warnings[] = "adopted env term $envId as {$row['uuid']} ({$row['path']})";
        }
    }

    private function ensure_term_row(array $front, string $entityType): void {
        global $wpdb;
        if (Ledger::id_for($front['uuid'], Ledger::KIND_TERM) !== null) {
            return;
        }
        Db::insert($wpdb->terms, ['name' => $front['name'], 'slug' => $front['slug'], 'term_group' => 0], null, 'apply insert term');
        $termId = Db::insert_id('apply insert term');
        Db::insert($wpdb->term_taxonomy, [
            'term_id' => $termId, 'taxonomy' => $front['taxonomy'],
            'description' => '', 'parent' => 0, 'count' => 0,
        ], null, 'apply insert term taxonomy');
        $tt = Db::insert_id('apply insert term taxonomy');
        Db::insert($wpdb->termmeta, ['term_id' => $termId, 'meta_key' => '_duo_uuid', 'meta_value' => $front['uuid']], null, 'apply insert term identity');
        Ledger::set($front['uuid'], $entityType, Ledger::KIND_TERM, $termId);
        Ledger::set($front['uuid'], $entityType, Ledger::KIND_TT, $tt);
    }

    /** @return bool true when a new row was inserted */
    private function ensure_post_row(array $front): bool {
        global $wpdb;
        if (Ledger::id_for($front['uuid'], Ledger::KIND_POST) !== null) {
            return false;
        }
        Db::insert($wpdb->posts, [
            'post_author' => 0,
            'post_date' => $front['date'],
            'post_date_gmt' => $front['date_gmt'],
            'post_content' => '',
            // Post-field classification: written unconditionally even for a
            // 'derived'-classified field (e.g. a Woo variation title or
            // product timestamp) — a new
            // row needs SOME starting value and there's no rebuild action to
            // conjure one; finalize_post() below is where derived fields
            // stop being overwritten, once the row actually exists.
            'post_title' => $front['title'],
            'post_excerpt' => '',
            'post_status' => $front['status'],
            'comment_status' => $front['comment_status'],
            'ping_status' => $front['ping_status'],
            'post_password' => '',
            'post_name' => $front['slug'],
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => $front['modified'] ?? $front['modified_gmt'],
            'post_modified_gmt' => $front['modified_gmt'],
            'post_content_filtered' => '',
            'post_parent' => 0,
            'guid' => $this->tokens->home() . '/?duo=' . $front['uuid'],
            'menu_order' => (int) ($front['menu_order'] ?? 0),
            'post_type' => $front['type'],
            'post_mime_type' => $front['mime'] ?? '',
            'comment_count' => 0,
        ], null, 'apply insert post');
        $id = Db::insert_id('apply insert post');
        Db::insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => '_duo_uuid', 'meta_value' => $front['uuid']], null, 'apply insert post identity');
        Ledger::set($front['uuid'], 'post', Ledger::KIND_POST, $id);
        return true;
    }

    private function finalize_term(array $front): void {
        global $wpdb;
        $termId = Ledger::id_for($front['uuid'], Ledger::KIND_TERM);
        $parentId = 0;
        if (!empty($front['parent'])) {
            $parentId = Ledger::id_for($front['parent'], Ledger::KIND_TERM)
                ?? throw new \RuntimeException("duo: term {$front['slug']}: parent {$front['parent']} not resolvable");
        }
        Db::update($wpdb->terms, ['name' => $front['name'], 'slug' => $front['slug']], ['term_id' => $termId], null, null, 'apply update term');
        Db::update($wpdb->term_taxonomy, [
            'description' => $this->encode_description($front['taxonomy'], $front['description']),
            'parent' => $parentId,
        ], ['term_id' => $termId, 'taxonomy' => $front['taxonomy']], null, null, 'apply update term taxonomy');
        $this->reconcile_authored_term_meta($termId, (array) ($front['meta'] ?? []));
        $this->reconcile_term_relationships($termId, $front['taxonomy'], (array) ($front['relationships'] ?? []));
    }

    /**
     * Mirror of Capture::term_description(): a taxonomy declaring
     * `taxonomies.<tax>.description_refs` gets its token-bearing map
     * resolved back through the ledger and re-serialized with PHP's OWN
     * serialize() — so int-typed ids come back as `i:N;`, matching
     * Polylang's own writes byte-for-byte in TYPE, not just in decoded
     * value (docs/frontier/polylang.md verified this column is genuinely
     * int-typed, not the digit-string convention ACF/Yoast use elsewhere).
     * Every other taxonomy keeps the plain detokenize_text() treatment.
     */
    private function encode_description(string $taxonomy, $description): string {
        $rule = $this->policy->description_reference_rule($taxonomy);
        if ($rule === null) {
            return $this->tokens->detokenize_text((string) $description);
        }
        $decoded = $this->tokens->struct_apply(
            $description,
            $rule['json_refs'],
            $rule['key_refs']
        );
        return serialize($decoded);
    }

    /**
     * Term-keyspace symmetry of reconcile_relationships(): a term's own
     * membership in OTHER taxonomies as object_id (docs/frontier/
     * polylang.md's "term-object relationship capture/apply" — Polylang's
     * term_language/term_translations). Scoped to term_object_taxes() — the
     * same manifest-keyspace collision guard reconcile_relationships() applies
     * for posts — so this never touches a colliding POST's own
     * relationship rows just because the numeric id matches. Two-phase-
     * safe for free: this only ever runs in phase 2 (finalize_term()),
     * after phase 1 has already inserted every term row (source AND
     * target) and its ledger entries for this whole apply run.
     */
    private function reconcile_term_relationships(int $termId, string $taxonomy, array $relField): void {
        global $wpdb;
        foreach (array_keys($relField) as $tax) {
            $keyspace = $this->policy->taxonomy_object_keyspace((string) $tax);
            if ($keyspace !== 'term') {
                throw new \RuntimeException(
                    "duo: term $termId ($taxonomy) declares relationships.$tax, but manifest object_keyspace "
                    . "is '$keyspace' — term relationships require object_keyspace=term"
                );
            }
        }
        $taxes = $this->term_object_taxes();
        if (!$taxes) {
            return;
        }
        $desiredTt = [];
        foreach ($relField as $tax => $uuids) {
            if (!in_array($tax, $taxes, true)) {
                // Not a taxonomy this environment currently owns as term-
                // object (stale file from before this capability existed,
                // or a hand edit) — never let it reach the ledger lookup /
                // INSERT below, mirroring reconcile_relationships()'s
                // identical guard on the post side.
                continue;
            }
            foreach ((array) $uuids as $u) {
                $tt = Ledger::id_for($u, Ledger::KIND_TT)
                    ?? throw new \RuntimeException("duo: term {$termId} ($taxonomy) references unresolvable term $u ($tax)");
                $desiredTt[$tt] = true;
            }
        }
        $in = "'" . implode("','", array_map('esc_sql', $taxes)) . "'";
        $current = $wpdb->get_col($wpdb->prepare(
            "SELECT tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            $termId
        )) ?: [];
        foreach ($current as $tt) {
            if (!isset($desiredTt[(int) $tt])) {
                Db::delete($wpdb->term_relationships, ['object_id' => $termId, 'term_taxonomy_id' => (int) $tt], null, 'apply delete term-object relationship');
            }
        }
        foreach (array_keys($desiredTt) as $tt) {
            if (!in_array((string) $tt, array_map('strval', $current), true)) {
                Db::insert($wpdb->term_relationships, [
                    'object_id' => $termId, 'term_taxonomy_id' => $tt, 'term_order' => 0,
                ], null, 'apply insert term-object relationship');
            }
        }
    }

    private function finalize_post(array $front, string $body): void {
        global $wpdb;
        $id = Ledger::id_for($front['uuid'], Ledger::KIND_POST)
            ?? throw new \RuntimeException("duo: post {$front['uuid']} missing from ledger after phase 1");

        $parentId = 0;
        if (!empty($front['parent'])) {
            $parentId = $this->tokens->token_to_id($front['parent']);
        }
        $authorId = 0;
        if (!empty($front['author'])) {
            $login = substr((string) $front['author'], 5); // strip "user:"
            $authorId = $this->resolve_login($login)
                ?? $this->defaultAuthor
                ?? 1;
            if ($this->resolve_login($login) === null) {
                $this->warnings[] = "post {$front['slug']}: author '$login' not in this environment; fell back to user #$authorId";
            }
        }

        $content = $this->policy->body_mode($front['type']) === 'verbatim'
            ? $body
            : Blocks::apply_rewrite($body, $this->policy, $this->tokens);
        $fields = [
            'post_author' => $authorId,
            'post_date' => $front['date'],
            'post_date_gmt' => $front['date_gmt'],
            'post_content' => $content,
            'post_title' => $front['title'],
            'post_excerpt' => $this->tokens->detokenize_text((string) $front['excerpt']),
            'post_status' => $front['status'],
            'comment_status' => $front['comment_status'],
            'ping_status' => $front['ping_status'],
            'post_name' => $front['slug'],
            'post_modified' => $front['modified'] ?? $front['modified_gmt'],
            'post_modified_gmt' => $front['modified_gmt'],
            'post_parent' => $parentId,
            'menu_order' => (int) ($front['menu_order'] ?? 0),
            'post_mime_type' => $front['mime'] ?? '',
        ];
        // Post-FIELD classification (task #88 / Woo timestamp extension):
        // a field this post_type classifies 'derived' is dropped from this
        // UPDATE entirely rather than overwritten with the captured byte
        // string, once the row already exists. The front-matter-name =>
        // wp_posts-column translation is Policy's (DUO-3318): this loop used
        // to carry its own literal copy of it, so widening the allowlist
        // without widening the copy would have left a field a manifest may
        // legally declare `derived` still overwritten here — the
        // classification honored by capture and the hash basis but silently
        // not by apply. Only fields in that map can be omitted; an
        // undeclared front key remains authored.
        // ensure_post_row() (phase 1, moments ago in this same apply for a
        // brand-new row) already wrote captured derived values as real
        // starting values — there's no rebuild action to conjure them the way
        // _wp_attachment_metadata gets one on create, and WordPress
        // requires SOME value on insert — so this only ever skips touching
        // an ALREADY-populated column, never leaves one null.
        //
        // Argued explicitly in the post-field classification report: the alternative —
        // overwrite it on every apply, same as any authored field — would
        // make a target environment's own, more-progressed self-heal
        // regress to a stale source snapshot on every single apply cycle,
        // only to re-heal itself on the very next ordinary WooCommerce read
        // (an admin view, a Store API request) — a pointless oscillation
        // for a value nothing authored actually controls. Letting the
        // plugin's own derivation stand once the row exists is what
        // 'derived' is supposed to mean; Canon::post_hash_basis() (see
        // load_tree() above) is the other half — it keeps these fields'
        // divergence from ever registering as drift/conflict in the first
        // place, so skipping the write here is consistent with what plan
        // already told the operator would happen.
        foreach (Policy::DERIVABLE_FIELD_COLUMNS as $frontField => $dbColumn) {
            if ($this->policy->field_class($front['type'], $frontField) === 'derived') {
                unset($fields[$dbColumn]);
            }
        }
        Db::update($wpdb->posts, $fields, ['ID' => $id], null, null, 'apply update post');

        // authored meta reconciliation: we own exactly the authored-classified keys
        $this->reconcile_authored_meta($id, (array) ($front['meta'] ?? []), 'post');

        // term relationships for owned taxonomies
        $this->reconcile_relationships(
            $id,
            $front['type'],
            (array) ($front['terms'] ?? []),
            (array) ($front['term_orders'] ?? [])
        );

        // attachment binary + managed meta
        if ($front['type'] === 'attachment') {
            $this->place_attachment($id, $front);
        }
    }

    private function reconcile_relationships(
        int $postId,
        string $postType,
        array $termsField,
        array $termOrders = []
    ): void {
        global $wpdb;
        foreach (array_keys($termsField) as $tax) {
            $keyspace = $this->policy->taxonomy_object_keyspace((string) $tax);
            if ($keyspace !== 'post') {
                throw new \RuntimeException(
                    "duo: post $postId declares terms.$tax, but manifest object_keyspace is '$keyspace' "
                    . '— post terms require object_keyspace=post'
                );
            }
        }
        $taxes = $this->taxes_for_post_type($postType);
        if (!$taxes) {
            return;
        }
        $desiredTt = [];
        foreach ($termsField as $tax => $uuids) {
            if (!in_array($tax, $taxes, true)) {
                // Not a taxonomy this post type actually owns (stale file from
                // before the object-type filter existed, or a hand edit) —
                // never let it reach the ledger lookup / INSERT below.
                continue;
            }
            foreach ((array) $uuids as $u) {
                $tt = Ledger::id_for($u, Ledger::KIND_TT)
                    ?? throw new \RuntimeException("duo: post $postId references unresolvable term $u ($tax)");
                $desiredTt[$tt] = (int) (($termOrders[$tax] ?? [])[$u] ?? 0);
            }
        }
        $in = "'" . implode("','", array_map('esc_sql', $taxes)) . "'";
        $currentRows = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.term_taxonomy_id, tr.term_order FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            $postId
        ), ARRAY_A) ?: [];
        $current = [];
        foreach ($currentRows as $row) {
            $current[(int) $row['term_taxonomy_id']] = (int) $row['term_order'];
        }
        foreach (array_keys($current) as $tt) {
            if (!isset($desiredTt[(int) $tt])) {
                Db::delete($wpdb->term_relationships, ['object_id' => $postId, 'term_taxonomy_id' => (int) $tt], null, 'apply delete post relationship');
            }
        }
        foreach ($desiredTt as $tt => $order) {
            if (!array_key_exists($tt, $current)) {
                Db::insert($wpdb->term_relationships, [
                    'object_id' => $postId, 'term_taxonomy_id' => $tt, 'term_order' => $order,
                ], null, 'apply insert post relationship');
            } elseif ($current[$tt] !== $order) {
                Db::update(
                    $wpdb->term_relationships,
                    ['term_order' => $order],
                    ['object_id' => $postId, 'term_taxonomy_id' => $tt],
                    null,
                    null,
                    'apply update post relationship order'
                );
            }
        }
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

    private function place_attachment(int $id, array $front): void {
        global $wpdb;
        $bytes = $this->compiled->media_content((string) $front['media']);
        $up = wp_upload_dir(null, false);
        $dst = trailingslashit($up['basedir']) . $front['file'];
        if (!is_file($dst) || hash_file('sha256', $dst) !== hash('sha256', $bytes)) {
            Canon::write_file($dst, $bytes);
        }
        $this->upsert_meta($wpdb->postmeta, 'post_id', $id, '_wp_attached_file', $front['file']);
        $this->upsert_meta($wpdb->postmeta, 'post_id', $id, '_wp_attachment_image_alt', (string) ($front['alt'] ?? ''));
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
     * (DUO-3347 slice 4) — kept so this method's existing internal call site
     * (delete_entity(), unchanged) and the regress_lifecycle_options_snapshot.php
     * Reflection-based test need no change.
     */
    private function assign_locations(int $menuTermId, array $locations): void {
        $this->menu_materializer()->assign_locations($menuTermId, $locations);
    }

    private function apply_options(array $document, bool $withDeletes): void {
        // DUO-3263: an interpreter-classified option (ACF's options-page
        // fields) needs the same document-sourced sibling map (the shadow
        // pointer) RepositoryAuthorization/RepositoryCompiler already build
        // from this same document (including valid v2 deletion witnesses) —
        // built once, reused per name below.
        $allOptions = OptionState::classification_values($document);
        foreach (OptionState::records($document) as $name => $record) {
            if ($record['state'] === 'absent') {
                continue; // explicit no-value/no-delete intent; target row is untouched
            }
            [$realName, $rule] = $this->option_apply_target((string) $name, $allOptions);
            if ($record['state'] === 'deleted') {
                if (!$withDeletes) {
                    throw new \RuntimeException("duo: internal invariant: option tombstone '$name' reached apply without --with-deletes");
                }
                global $wpdb;
                Db::delete($wpdb->options, ['option_name' => $realName], null, 'apply delete authored option');
                wp_cache_delete($realName, 'options');
                wp_cache_delete('alloptions', 'options');
                continue;
            }
            $v = $record['value'];
            $autoload = (string) $record['autoload'];
            OptionState::assert_rule_autoload($rule, $autoload, "repository option '$name'");
            // option_name_refs (task #93) — MUST run before the ordinary
            // option_rule($name) lookup below, unconditionally: a token-
            // form key like "woocommerce_flat_rate_{{wc_zone_method:...}}
            // _settings" matches no manifest's exact "options" map entry,
            // so option_rule() would return null -> an empty rule -> the
            // ordinary generic write path below, which would silently
            // upsert a REAL wp_options row whose NAME contains literal
            // "{{...}}" bytes — not a crash, a silent corruption of the
            // target's own options table. Detecting and detokenizing first
            // is what this task's own design review specifically flagged.
            if (str_contains($name, '{{')) {
                $vv = $this->tokens->struct_apply($v, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null);
                $this->upsert_option($realName, $this->option_wire_value($vv), $autoload);
                continue;
            }
            if (($rule['class'] ?? '') === 'managed') {
                // active_plugins/template/stylesheet (docs/proposals/code-half.md
                // §3.1): writing these via raw $wpdb would make WordPress believe
                // a plugin/theme is active while skipping every activation-hook
                // side effect that makes it actually work — activate_plugin()/
                // switch_theme() exist for exactly that reason. Deploy::run()
                // (`wp duo deploy`) is the ONLY place these are ever reconciled,
                // deliberately outside this canary-armed apply.
                continue;
            }
            if (!empty($rule['sub_keys'])) {
                // DUO-3233: SUB-KEY-LEVEL merge into the live blob, never a
                // whole-value replace — see apply_option_sub_keys()'s own
                // docblock for the full rationale.
                $this->apply_option_sub_keys($name, $v, $rule['sub_keys'], $autoload);
                continue;
            }
            $this->upsert_option(
                $name,
                $this->option_wire_value($this->apply_value($name, $v, $rule)),
                $autoload
            );
        }
    }

    /** @return array{0:string,1:array} canonical name -> target-local name + owning rule */
    private function option_apply_target(string $name, array $allOptions): array {
        if (!str_contains($name, '{{')) {
            // Even a raw/noncanonical repository key must pass through the
            // option-name namespace validator. In particular, a leading-zero
            // would-be instance id must not fall through to the generic
            // option path and leave a stale target row behind.
            $this->policy->option_name_ref_match_details($name);
            // DUO-3263: interpreter-aware, not the plain static option_rule()
            // — an ACF options-page field (options_<name>/_options_<name>)
            // has no exact/pattern policy entry at all; only
            // meta_rule_for_option() consults the owning manifest's
            // interpreter. Caught live: the plain static lookup silently
            // returned [] here, and assert_rule_autoload() below correctly
            // refused to guess rather than writing an unclassified row.
            // meta_rule_for_option() itself already falls back to the same
            // static option_rule_details() lookup option_rule() uses when no
            // interpreter claims $name (Policy::rule_details_for_interpreter_hook()),
            // so it is a strict superset of the plain lookup, not a
            // replacement for it — DUO-3264's dynamic_options fallback
            // (theme_mods_<active theme>, disjoint namespace from every
            // ACF options-page name) chains after it for the same reason it
            // already chained after option_rule() before this merge.
            $rule = $this->policy->meta_rule_for_option($name, $allOptions) ?? $this->dynamic_option_rule_for_name($name);
            return [$name, $rule ?? []];
        }
        if (!preg_match('/\{\{([a-z][a-z0-9_]*):([0-9a-f-]{36})\}\}/', $name, $tm)) {
            throw new \RuntimeException("duo: option key '$name' contains '{{' but is not a well-formed ref token");
        }
        // Validate canonical ownership before resolving the token. This
        // rejects same-kind and cross-kind overlaps in the same way as live
        // capture and Snapshot preservation, rather than allowing a later
        // match to silently pick a different rule.
        $canonicalDetails = $this->policy->canonical_option_name_ref_details($name);
        if (($canonicalDetails['rule'] ?? null) === null) {
            throw new \RuntimeException(
                "duo: captured option key '$name' contains an identity token but no authored option_name_refs owner"
            );
        }
        $realId = $this->tokens->token_to_id($tm[0]);
        $realName = str_replace($tm[0], (string) $realId, $name);
        $realDetails = $this->policy->option_name_ref_match_details($realName);
        $rule = $realDetails['rule'] ?? null;
        if ($rule === null
            || ($rule['class'] ?? '') !== 'authored'
            || (string) ($rule['id_kind'] ?? '') !== (string) $tm[1]) {
            throw new \RuntimeException(
                "duo: captured option key '$name' looks token-form but matches no option_name_refs rule "
                . "after detokenizing to '$realName'"
            );
        }
        return [$realName, $rule];
    }

    /**
     * DUO-3264 (fork A): fall back to a dynamic_options-declared sub_keys
     * rule when the ordinary option_rule() lookup finds nothing —
     * theme_mods_<active stylesheet> is the proven case. Computes this
     * environment's own live resolver values (Policy.php stays WordPress-
     * free by design) and delegates the match itself to
     * Policy::dynamic_option_rule_for_name(), the same lookup
     * RepositoryAuthorization::authorize_options() also uses — one shared
     * place for "does this captured key match a declaration," not two.
     *
     * A captured document key only ever matches when it is EXACTLY the
     * name Policy::resolve_dynamic_option() would produce for THIS target
     * right now: deploy's own theme-reconciliation (DUO-3216) already
     * guarantees that equality holds by the time apply's own canary-armed
     * mutation phase runs (Apply::apply()'s refuse-gate hard-blocks a
     * theme mismatch before this method is ever reached) — the identical
     * invariant assign_locations()/nav_menu_locations already depends on,
     * not a new one.
     */
    private function dynamic_option_rule_for_name(string $name): ?array {
        return $this->policy->dynamic_option_rule_for_name($name, $this->dynamic_option_resolver_values());
    }

    /**
     * This environment's live value for every resolver the pinned manifests
     * actually declare.
     *
     * DUO-3318: the map used to be the single literal
     * `['active_stylesheet' => get_option('stylesheet')]`, which silently
     * answered "no value" for any OTHER declared resolver — and
     * Policy::dynamic_option_rule_for_name()'s matching `continue` then
     * turned that into an unclassified option instead of an error. Built
     * from the declarations instead, the map is complete by construction:
     * adding a resolver to Policy::DYNAMIC_OPTION_RESOLVERS without teaching
     * this match arm about it now fails loudly, at the first manifest that
     * declares it, naming the missing engine step.
     *
     * The match is deliberately a second copy of Capture::build_options()'s,
     * not a shared helper: Policy.php is WordPress-free by design (it loads
     * in RepositoryCompiler's pure offline pass), so the one place that could
     * host a shared implementation is the one place that may not call
     * get_option(). Capture additionally honors a repository-supplied
     * override for the same resolver (a refresh export reads the captured
     * stylesheet, not this target's); apply has no such alternative source
     * because DUO-3216's theme refuse-gate has already proven the two agree.
     *
     * @return array<string,string>
     */
    private function dynamic_option_resolver_values(): array {
        $out = [];
        foreach ($this->policy->dynamic_options() as $key => $decl) {
            $resolver = (string) $decl['resolver'];
            $out[$resolver] = match ($resolver) {
                'active_stylesheet' => (string) get_option('stylesheet'),
                default => throw new \RuntimeException(
                    "duo: dynamic_options.$key declares unsupported resolver '$resolver'"
                ),
            };
        }
        return $out;
    }

    /**
     * Shared apply-direction dispatch, the mirror of Capture::capture_value()
     * — factored out for the identical reason: a sub_keys (DUO-3233) NAMED
     * sub-key's rule is a whole option rule at one nesting level down, so it
     * gets json_refs/key_refs/ref/plain-string detokenization for free, with
     * zero new dispatch logic to keep in sync with the ordinary per-option
     * path.
     */
    private function apply_value(string $ctx, $v, array $rule) {
        if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
            $v = $this->tokens->struct_apply($v, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null);
            return StructuredValue::encode($v, $rule, $ctx);
        }
        if (!empty($rule['ref'])) {
            return $this->tokens->tokens_to_value($v, $rule['ref']);
        }
        if (is_string($v)) {
            return $this->tokens->detokenize_text($v);
        }
        return $v;
    }

    /**
     * sub_keys apply (DUO-3233): SUB-KEY-LEVEL merge into the LIVE blob —
     * never a whole-value replace. Reads the target's own CURRENT value
     * (carrying every key this manifest did NOT carve out — Polylang's own
     * force_lang/rewrite/first_activation/version, populated by the
     * plugin's own activation-time add_option()/admin saves), overlays only
     * the captured, declared-authored sub-keys on top, and writes the
     * merged result back. The excluded remainder survives apply completely
     * untouched, on every environment, every run — this is the mechanism
     * manifests/polylang.json's own notes long documented as missing: "v0's
     * options model classifies a whole option name at once ... there is no
     * way to keep force_lang/default_lang/etc authored while excluding
     * first_activation/version without capturing them too."
     *
     * Absent-live-option case: starts the merge from an empty array
     * (warned) rather than refusing outright. The ordinary case where this
     * would matter — Polylang/Yoast not yet activated on this target — is
     * caught upstream of this code path: spec/repo-format.md's code-half
     * ordering runs `wp duo deploy` (real activate_plugin() calls) before
     * `wp duo apply`, so the owning plugin's own activation-time
     * add_option() has normally already populated this option by the time
     * apply reaches here. A bare `apply` run in isolation (e.g. a test)
     * against a plugin that was never activated is a real, if unusual,
     * situation this still handles honestly rather than refusing: the
     * merged option ends up containing ONLY the declared sub-keys, which is
     * observable (warned) rather than silently incomplete.
     *
     * Whole-option deletion remains ownership-exact and unrelated to the
     * tombstone below: DUO-3211's whole-row `deleted` record is authorized
     * only for a whole authored option, never for this mixed-ownership
     * shape, and represents an explicit, git-visible deletion INTENT with
     * its own record. This function draws a narrower, second distinction —
     * about one declared sub-key's own presence, not the containing
     * option's — covered next.
     *
     * Sub-key TOMBSTONE (DUO-3264): a `class: authored` sub-key that
     * $captured does not contain is REMOVED from the live blob below, not
     * left stale. Capture::capture_option_sub_keys() only ever omits a
     * declared-authored sub-key from its own output for reasons that are
     * ALL, unambiguously, "there is currently nothing valid to capture" —
     * confirmed by reading that method directly, not assumed: the live
     * blob's own key is genuinely absent (`!array_key_exists`), a ref value
     * is the WordPress "unset" convention of 0, or a ref id is dangling/
     * unscoped (warned or queued, never silently different from those two).
     * There is no capture-time reason a declared-authored sub-key goes
     * missing from $captured that means "still true, just not captured
     * this run" — so its absence here is as reliable a signal as its
     * presence, and the merge below finally treats it that way instead of
     * only ever adding/updating (its previous, asymmetric behavior, kept
     * for every OTHER key in $subKeys, is exactly why this fix is scoped to
     * $subKeys members only — see the loop below).
     *
     * Discovered live (DUO-3264 core conformance): theme_mods_<stylesheet>
     * 's own custom_logo/header_image_data.attachment_id sub-keys are
     * pointers to an attachment id, and WordPress itself deletes those SAME
     * theme_mods keys out of the live blob the instant the referenced
     * attachment is deleted (_delete_attachment_theme_mod(), core behavior,
     * not a Duo mechanism) — a completely ordinary action (an admin swaps
     * or removes a site logo). Before this fix, a target that had already
     * received the old value on an earlier apply kept serving the deleted
     * attachment's id forever; no later apply could ever remove what it
     * only ever knew how to add or overwrite. sub_keyed_options() (DUO-3233:
     * Polylang's force_lang/rewrite, Yoast's wpseo fields) shares this exact
     * code path and gets the identical fix, though its own declared
     * sub-keys have not been observed to disappear the way an attachment-
     * backed ref naturally can.
     *
     * Scoped strictly to sub-keys DECLARED `authored` in $subKeys: a
     * `runtime`/`derived`/`env`-classed declared sub-key (theme_mods' own
     * sidebars_widgets/wp_classic_sidebars, Polylang's first_activation/
     * version) is never captured in the first place and is never touched by
     * either loop below, regardless of $captured — removal must never
     * widen ownership beyond what capture actually owns, the same
     * invariant the original merge-only loop already upheld in the add/
     * update direction.
     */
    private function apply_option_sub_keys(string $name, $captured, array $subKeys, string $autoload): void {
        global $wpdb;
        if (!is_array($captured)) {
            throw new \RuntimeException(
                "duo: captured option '$name' declares sub_keys but its repository value is not an object"
            );
        }
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name
        ));
        if ($raw === null) {
            $this->warnings[] = "option $name: no live value to sub-key-merge into — creating it containing ONLY "
                . 'the declared sub-keys (its owning plugin\'s own defaults are absent; expected if that plugin '
                . 'has not been deployed/activated on this target yet)';
            $live = [];
        } else {
            $live = PlainData::decode($raw, "live option '$name'");
            if (!is_array($live)) {
                throw new \RuntimeException(
                    "duo: live option '$name' is not array-shaped — cannot sub-key-merge into it (got "
                    . get_debug_type($live) . ')'
                );
            }
        }
        foreach ($captured as $subKey => $subVal) {
            $subRule = $subKeys[$subKey] ?? null;
            if (($subRule['class'] ?? '') !== 'authored') {
                // RepositoryAuthorization::authorize_option_sub_keys() already
                // refuses an undeclared/non-authored captured sub-key before
                // apply ever starts mutating anything — this is a defensive
                // invariant guard against that gate ever being bypassed
                // (e.g. a future internal caller of apply_options() that
                // skips the preflight), not a routinely-reachable branch.
                throw new \RuntimeException(
                    "duo: captured option '$name.$subKey' has no authored sub_keys rule — repository "
                    . 'authorization should have refused this before apply'
                );
            }
            $live[(string) $subKey] = $this->apply_value("$name.$subKey", $subVal, $subRule);
        }
        foreach ($subKeys as $subKey => $subRule) {
            if (($subRule['class'] ?? '') !== 'authored' || array_key_exists((string) $subKey, $captured)) {
                continue;
            }
            if (array_key_exists((string) $subKey, $live)) {
                unset($live[(string) $subKey]);
                $this->warnings[] = "option $name.$subKey: removed from the live blob — capture no longer reports "
                    . 'this declared authored sub-key (its own source value is gone on the captured environment, '
                    . 'e.g. a referenced attachment was deleted)';
            }
        }
        $this->upsert_option($name, $this->option_wire_value($live), $autoload);
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
     * DUO-3266: authored postmeta reconciliation for one owner ($id) —
     * factored out of finalize_post() so a second postmeta owner (menu
     * items, finalize_menu() below) gets the SAME ownership discipline
     * instead of a second, drift-prone copy. "We own exactly the
     * authored-classified keys": every key in $frontMeta is resolved
     * (ref/json_refs/key_refs/detokenize as its rule declares) and
     * upserted; any row ALREADY on the target that policy classifies
     * `authored` but is no longer in $frontMeta is deleted (removed from
     * policy, or from this owner's captured state, since the last apply);
     * everything else on the target — non-authored, or a key this owner's
     * own structural fields already handle bespoke (menu items' 8
     * `_menu_item_*` keys are classified `managed`/`runtime` in
     * manifests/core.json, never `authored`, so they never appear here as
     * either desired or deletable) — is left byte-untouched.
     */
    private function reconcile_authored_meta(int $id, array $frontMeta, string $ownerLabel): void {
        $this->field_materializer()->reconcile_authored_meta($id, $frontMeta, $ownerLabel);
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
     * Reconcile only explicitly-authored user-meta keys for one exact login.
     * The owning user is target-local: no creation, adoption, rename,
     * fallback, capability change, or user deletion exists on this path.
     */
    private function finalize_user_meta(array $front): void {
        global $wpdb;
        $login = (string) ($front['login'] ?? '');
        $userId = $this->resolve_exact_login($login);
        if ($userId === null) {
            throw new \RuntimeException(
                "duo: user-meta exact login '$login' disappeared after preflight; transaction rolled back"
            );
        }
        $frontMeta = (array) ($front['meta'] ?? []);
        $desired = [];
        foreach ($frontMeta as $key => $value) {
            $rule = $this->policy->meta_rule_for_user((string) $key, $frontMeta) ?? [];
            if (($rule['class'] ?? '') !== 'authored') {
                throw new \RuntimeException(
                    "duo: user-meta '$key' for exact login '$login' is not authorized authored at apply"
                );
            }
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                $value = $this->tokens->struct_apply(
                    $value,
                    $rule['json_refs'] ?? [],
                    $rule['key_refs'] ?? null
                );
                $value = StructuredValue::encode($value, $rule, "user '$login' meta $key");
            } elseif (!empty($rule['ref'])) {
                // User refs need the meta decoder: unlike option refs it
                // understands user:<login>, arrays, and storage casts.
                $value = $this->tokens->meta_tokens_to_value($value, $rule);
            } elseif (is_string($value)) {
                $value = $this->tokens->detokenize_text($value);
            }
            $desired[(string) $key] = maybe_serialize($value);
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT umeta_id AS meta_id, meta_key, meta_value FROM {$wpdb->usermeta} "
            . 'WHERE user_id = %d ORDER BY umeta_id ASC',
            $userId
        ), ARRAY_A) ?: [];
        $flat = [];
        foreach ($rows as $row) {
            $flat[$row['meta_key']] ??= $row['meta_value'];
        }
        $kept = [];
        foreach ($rows as $row) {
            $rule = $this->policy->meta_rule_for_user((string) $row['meta_key'], $flat);
            if (($rule['class'] ?? '') !== 'authored') {
                continue;
            }
            $key = (string) $row['meta_key'];
            // Canonical authored user meta is deliberately single-valued.
            // Remove every absent owned row and all but the first existing
            // row before upsert, so a dirty target cannot retain duplicates
            // that would make the verification capture refuse.
            if (!array_key_exists($key, $desired) || isset($kept[$key])) {
                Db::delete(
                    $wpdb->usermeta,
                    ['umeta_id' => (int) $row['meta_id']],
                    null,
                    "apply delete authored user meta for exact login '$login'"
                );
                continue;
            }
            $kept[$key] = true;
        }
        foreach ($desired as $key => $value) {
            $this->upsert_meta(
                $wpdb->usermeta,
                'user_id',
                $userId,
                $key,
                $value,
                "apply reconcile authored user meta for exact login '$login'",
                'umeta_id'
            );
        }
        wp_cache_delete($userId, 'user_meta');
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

    private function delete_entity(string $uuid, string $type): void {
        global $wpdb;
        if (isset($this->snapshotRowTables()[$type])) {
            $idKind = (string) $this->snapshotRowTables()[$type]['id_kind'];
            $localId = Ledger::id_for($uuid, $idKind);
            if ($localId === null) {
                throw new \RuntimeException("duo: cannot delete $type $uuid: target identity mapping is missing");
            }
            Snapshot::delete_row($this->policy, $uuid, $type);
            Snapshot::assert_row_deleted($this->policy, $type, $localId);
            $this->warnings[] = "deleted $type $uuid";
            return;
        }
        if ($type === 'post') {
            $id = Ledger::id_for($uuid, Ledger::KIND_POST);
            if ($id === null) {
                throw new \RuntimeException("duo: cannot delete post $uuid: target identity mapping is missing");
            }
            $postType = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d", $id
            ));
            $revisionIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision' ORDER BY ID ASC",
                $id
            )) ?: []);
            foreach ($revisionIds as $revisionId) {
                Db::delete(
                    $wpdb->postmeta,
                    ['post_id' => $revisionId],
                    null,
                    'apply delete post revision meta'
                );
                Db::delete($wpdb->posts, ['ID' => $revisionId], null, 'apply delete post revision');
                $this->assert_zero(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d",
                    [$revisionId],
                    "post $uuid revision $revisionId"
                );
                $this->assert_zero(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d",
                    [$revisionId],
                    "post $uuid revision $revisionId metadata"
                );
            }
            $this->delete_post_relationships($id, $postType);
            Db::delete($wpdb->postmeta, ['post_id' => $id], null, 'apply delete post meta');
            Db::delete($wpdb->posts, ['ID' => $id], null, 'apply delete post');
            $this->assert_zero(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d",
                [$id],
                "post $uuid row"
            );
            $this->assert_zero(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d",
                [$id],
                "post $uuid metadata"
            );
            // DUO-3234: a deleted post can never usefully retry regeneration
            // again — clear any outstanding marker so it doesn't linger
            // forever for a uuid that no longer resolves to anything.
            if ($this->scopeContract === null) {
                Ledger::kv_delete(self::REGEN_PENDING_PREFIX . $uuid);
            }
        } elseif ($type === 'term' || $type === 'menu') {
            $termId = Ledger::id_for($uuid, Ledger::KIND_TERM);
            $tt = Ledger::id_for($uuid, Ledger::KIND_TT);
            if ($termId === null || $tt === null) {
                throw new \RuntimeException("duo: cannot delete $type $uuid: target term identity mapping is incomplete");
            }
            if ($type === 'menu') {
                // Authored menu locations are part of this selected menu's
                // owned state. Remove only slots whose current value is this
                // exact term id before deleting it; assign_locations() keeps
                // every other menu's location byte-for-byte. Derived
                // locations remain wholly adapter-owned and are never
                // rewritten by the generic engine.
                if ($this->policy->menu_field_class('locations') !== 'derived') {
                    $this->assign_locations($termId, []);
                }
                $itemIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                     WHERE tr.term_taxonomy_id = %d AND p.post_type = 'nav_menu_item'
                     ORDER BY p.ID ASC",
                    $tt
                )) ?: []);
                foreach ($itemIds as $itemId) {
                    $itemUuid = Ledger::uuid_for($itemId, Ledger::KIND_POST);
                    $this->delete_post_relationships($itemId, 'nav_menu_item');
                    Db::delete($wpdb->postmeta, ['post_id' => $itemId], null, 'apply delete menu item meta');
                    Db::delete($wpdb->posts, ['ID' => $itemId], null, 'apply delete menu item');
                    if ($itemUuid !== null) {
                        Ledger::forget($itemUuid);
                    }
                    $this->assert_zero(
                        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d",
                        [$itemId],
                        "menu $uuid item $itemId"
                    );
                    $this->assert_zero(
                        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d",
                        [$itemId],
                        "menu $uuid item $itemId metadata"
                    );
                }
            }
            // This term's OWN outbound relationships (term-object taxonomies
            // where THIS term is object_id) go before target-side cleanup.
            $this->delete_term_relationships($termId);
            Db::delete(
                $wpdb->term_relationships,
                ['term_taxonomy_id' => $tt],
                null,
                'apply delete taxonomy relationships'
            );
            Db::delete($wpdb->term_taxonomy, ['term_taxonomy_id' => $tt], null, 'apply delete term taxonomy');
            Db::delete($wpdb->termmeta, ['term_id' => $termId], null, 'apply delete term meta');
            Db::delete($wpdb->terms, ['term_id' => $termId], null, 'apply delete term');
            $this->assert_zero(
                "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id = %d",
                [$termId],
                "$type $uuid term row"
            );
            $this->assert_zero(
                "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
                [$tt],
                "$type $uuid taxonomy row"
            );
            $this->assert_zero(
                "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
                [$termId],
                "$type $uuid metadata"
            );
            $this->assert_zero(
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
                [$tt],
                "$type $uuid inbound relationships"
            );
        } else {
            throw new \RuntimeException("duo: cannot delete unsupported entity type '$type'");
        }
        $this->warnings[] = "deleted $type $uuid";
    }

    /** A post-delete assertion inside the active transaction. */
    private function assert_zero(string $sql, array $args, string $label): void {
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare($sql, ...$args));
        if ($count !== 0) {
            throw new \RuntimeException("duo: deletion verification failed: $count $label row(s) remain");
        }
    }

    /**
     * Delete a post's own term_relationships rows only — scoped to every
     * taxonomy REGISTERED on this runtime whose object_type includes this
     * post's type and whose resolved object_keyspace is `post` (deliberately
     * not policy-scoped: a full post delete must clean up every taxonomy
     * that legitimately relates to it, same as wp_delete_post(), not just
     * the ones Duo happens to manage).
     *
     * An unfiltered `DELETE ... WHERE object_id = $id` (the previous code)
     * hits every term_relationships row with that raw id regardless of
     * taxonomy — including a term-object taxonomy's rows for a completely
     * different TERM that happens to have the same id, since posts and
     * terms are minted from independent auto-increment counters sharing
     * one numeric space. That would silently destroy the colliding term's
     * genuine data as a side effect of deleting an unrelated post.
     */
    private function delete_post_relationships(int $id, string $postType): void {
        global $wpdb;
        $taxes = array_values(array_filter(get_taxonomies(), function (string $tax) use ($postType) {
            $taxObj = get_taxonomy($tax);
            if ($taxObj === false || !in_array($postType, (array) $taxObj->object_type, true)) {
                return false;
            }
            return $this->policy->taxonomy_object_keyspace($tax, (array) $taxObj->object_type) === 'post';
        }));
        if (!$taxes) {
            return;
        }
        $in = "'" . implode("','", array_map('esc_sql', $taxes)) . "'";
        Db::query($wpdb->prepare(
            "DELETE tr FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            $id
        ), 'apply delete post relationships');
        $this->assert_zero(
            "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            [$id],
            "post $id term relationships"
        );
    }

    /**
     * Term-object symmetry of delete_post_relationships() immediately
     * above: a deleted term's OWN relationship rows as object_id (term-
     * object taxonomies, e.g. Polylang's term_language/term_translations),
     * scoped to every taxonomy REGISTERED on this runtime whose manifest-
     * resolved object_keyspace is `term`. An undeclared runtime term/mixed
     * taxonomy refuses before mutation instead of making literal `term` an
     * engine-owned plugin sentinel. An unfiltered `DELETE ... WHERE object_id = $id`
     * would hit every term_relationships row with that raw id regardless of
     * taxonomy — including a POST-object taxonomy's row for a completely
     * different POST that happens to share this term's id.
     */
    private function delete_term_relationships(int $termId): void {
        global $wpdb;
        $taxes = array_values(array_filter(get_taxonomies(), function (string $tax) {
            $taxObj = get_taxonomy($tax);
            return $taxObj !== false
                && $this->policy->taxonomy_object_keyspace($tax, (array) $taxObj->object_type) === 'term';
        }));
        if (!$taxes) {
            return;
        }
        $in = "'" . implode("','", array_map('esc_sql', $taxes)) . "'";
        Db::query($wpdb->prepare(
            "DELETE tr FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            $termId
        ), 'apply delete term-object relationships');
        $this->assert_zero(
            "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            [$termId],
            "term $termId outbound relationships"
        );
    }

    private function resolve_login(string $login): ?int {
        global $wpdb;
        if ($login === '') {
            return null;
        }
        if (!isset($this->userIds[$login])) {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE user_login = %s LIMIT 1", $login
            ));
            $this->userIds[$login] = $id ? (int) $id : 0;
        }
        return $this->userIds[$login] ?: null;
    }

    /** Exact byte/case login lookup for the user-meta owning-user boundary. */
    private function resolve_exact_login(string $login): ?int {
        global $wpdb;
        if ($login === '') {
            return null;
        }
        if (!array_key_exists($login, $this->exactUserIds)) {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE BINARY user_login = BINARY %s LIMIT 1",
                $login
            ));
            $this->exactUserIds[$login] = $id ? (int) $id : 0;
        }
        return $this->exactUserIds[$login] ?: null;
    }

    // --------------------------------------------------------------- rebuild

    private const REGEN_DELETE_CONTEXT_PREFIX = 'regen_delete_context:';
    private const REGEN_REPARENT_CONTEXT_PREFIX = 'regen_reparent_context:';

    private function regen_checked_get_var(mixed $sql, string $context): mixed {
        global $wpdb;
        $wpdb->last_error = '';
        $value = $wpdb->get_var($sql);
        if ($value === false || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("duo: regeneration bookkeeping read failed: $context");
        }
        return $value;
    }

    private function regen_checked_get_row(mixed $sql, string $context): ?array {
        global $wpdb;
        $wpdb->last_error = '';
        $row = $wpdb->get_row($sql, ARRAY_A);
        if (($row !== null && !is_array($row)) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("duo: regeneration bookkeeping read failed: $context");
        }
        return $row;
    }

    /** @return array<int,mixed> */
    private function regen_checked_get_col(mixed $sql, string $context): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_col($sql);
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("duo: regeneration bookkeeping read failed: $context");
        }
        return $rows;
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
        global $wpdb;
        $out = [];
        foreach ($work as $entry) {
            $uuid = (string) ($entry['uuid'] ?? '');
            $entity = $tree[$uuid] ?? null;
            if ($uuid === '' || ($entity['type'] ?? '') !== 'post') {
                continue;
            }
            $front = (array) ($entity['data'] ?? []);
            $postType = (string) ($front['type'] ?? '');
            if ($postType === '') {
                continue;
            }
            if ($this->policy->regen_batch($postType) === null
                && !$this->selection_declares_channel_for('reparents', 'post:' . $postType)) {
                continue;
            }
            $id = Ledger::id_for($uuid, Ledger::KIND_POST);
            if ($id === null && isset($entry['env_id'])) {
                // Adopt rows do not receive their ledger identity until the
                // transaction's adopt phase, but their plan-time env_id came
                // from an exact slug/type/parent collision query. Reuse it
                // only after validating the post row and refusing an id that
                // is already mapped to another canonical UUID; never trust a
                // free-form authored number as an identity fallback.
                $adoptId = (int) $entry['env_id'];
                if ($adoptId > 0) {
                    $mappedUuid = Ledger::uuid_for($adoptId, Ledger::KIND_POST);
                    if ($mappedUuid !== null && $mappedUuid !== $uuid) {
                        throw new \RuntimeException(
                            "duo: cannot capture reparent context for adopted post $adoptId ($uuid): "
                            . "the local id is already mapped to canonical post $mappedUuid"
                        );
                    }
                    $id = $adoptId;
                }
            }
            if ($id === null) {
                continue; // create: no previous parent to repair
            }
            $row = $this->regen_checked_get_row($wpdb->prepare(
                "SELECT post_type, post_parent FROM {$wpdb->posts} WHERE ID = %d",
                $id
            ), "reparent source post $id");
            if (!is_array($row) || (string) ($row['post_type'] ?? '') !== $postType) {
                continue;
            }
            $oldParentId = (int) ($row['post_parent'] ?? 0);
            if ($oldParentId <= 0) {
                continue;
            }

            $parentToken = (string) ($front['parent'] ?? '');
            $newParentId = 0;
            $newParentKnown = $parentToken === '';
            if ($parentToken !== ''
                && preg_match('/^\{\{post:([0-9a-f-]{36})\}\}$/', $parentToken, $match)) {
                $newParentId = (int) (Ledger::id_for($match[1], Ledger::KIND_POST) ?? 0);
                $newParentKnown = $newParentId > 0;
                if (!$newParentKnown) {
                    // The desired parent may itself be created in this apply;
                    // its id is not available until phase 1.
                    $newParentKnown = false;
                }
            }
            $oldParentUuid = Ledger::uuid_for($oldParentId, Ledger::KIND_POST);
            $sameParent = $newParentId === $oldParentId
                || (!$newParentKnown && $oldParentUuid !== null
                    && $parentToken === '{{post:' . $oldParentUuid . '}}');
            if ($sameParent) {
                continue;
            }

            $context = [
                'kind' => 'reparent',
                'uuid' => $uuid,
                'id' => (int) $id,
                'post_type' => $postType,
                'parent_id' => $oldParentId,
                'old_parent_id' => $oldParentId,
                'new_parent_id' => $newParentId,
                'child_ids' => [],
            ];
            // A failed A->B rebuild may be followed by a B->C authored move
            // before the first receipt is retried. Keep every root touched by
            // that chain; replacing the marker with only B/C would make A's
            // lookup and attributes stale forever. The receipt and the raw
            // mutation remain in this same transaction.
            $stored = $persistGenericDebt
                ? Ledger::kv_get(self::REGEN_REPARENT_CONTEXT_PREFIX . $uuid)
                : null;
            $previous = is_string($stored) ? json_decode($stored, true) : null;
            if (is_array($previous)
                && ($previous['kind'] ?? '') === 'reparent'
                && (string) ($previous['uuid'] ?? $uuid) === $uuid) {
                $context = $this->merge_regen_contexts($previous, $context);
            } else {
                $context['root_ids'] = array_values($this->regen_context_root_ids($context));
                sort($context['root_ids'], SORT_NUMERIC);
            }
            if ($persistGenericDebt) {
                Ledger::kv_set(
                    self::REGEN_REPARENT_CONTEXT_PREFIX . $uuid,
                    json_encode($context)
                );
            }
            $out[] = $context;
        }
        return $out;
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
    /**
     * The plan projection of the two durable derived-state keyspaces
     * (DUO-3342 / independent review F3), factored out of build_plan() so it is
     * drivable on its own against a marker keyspace.
     *
     * Read-only, and the orphan rule matches regen_pending's exactly: a marker
     * that is malformed, or that no consumer of either kind claims, is NOT
     * surfaced — the next apply's own sweep removes it, loudly, at the point it
     * actually mutates, and a plan reader has nothing to do about it. What IS
     * surfaced is a receipt with a real claimant and no verified repair yet,
     * which is a promotion-relevant gap in exactly the sense regen_pending is.
     *
     * The claimant test is the pinned one rather than this run's selection for
     * the reason build_plan() has no selection at all: plan is read-only and
     * runs before any negotiation.
     *
     * @return list<array{uuid:string, type:string, post_type:string, kind:string}>
     */
    private function regen_context_plan_rows(): array {
        $rows = [];
        foreach ([
            self::REGEN_DELETE_CONTEXT_PREFIX => 'delete',
            self::REGEN_REPARENT_CONTEXT_PREFIX => 'reparent',
        ] as $contextPrefix => $contextKind) {
            foreach (Ledger::kv_prefix($contextPrefix) as $k => $encoded) {
                $context = is_string($encoded) ? json_decode($encoded, true) : null;
                $postType = is_array($context) ? (string) ($context['post_type'] ?? '') : '';
                $id = is_array($context) ? (int) ($context['id'] ?? 0) : 0;
                if ($postType === '' || $id <= 0) {
                    continue; // malformed — apply's own sweep handles this, not plan
                }
                // Policy-only claimant test, deliberately NOT the sweep's
                // negotiation-aware pinned_provider_action_owns(): run() hashes
                // this projection into the promotion precondition both BEFORE
                // negotiation and after it, so a negotiation-dependent answer
                // would make plan and freshPlan disagree over an unmutated
                // keyspace and wedge the apply behind a refusal that repeats
                // forever. Over-surfacing a row a scope-aware test would drop
                // is harmless in a read-only projection; guessing is only a
                // hazard where it authorizes a delete, which is the sweep.
                if ($this->policy->regen_batch($postType) === null
                    && !$this->pinned_provider_action_triggers('post:' . $postType)) {
                    continue; // orphaned — apply's own sweep handles this, not plan
                }
                $rows[] = [
                    'uuid' => is_array($context) && (string) ($context['uuid'] ?? '') !== ''
                        ? (string) $context['uuid']
                        : substr((string) $k, strlen($contextPrefix)),
                    'type' => 'post',
                    'post_type' => $postType,
                    'kind' => $contextKind,
                ];
            }
        }
        usort($rows, static fn(array $a, array $b): int =>
            strcmp($a['kind'], $b['kind']) ?: strcmp($a['uuid'], $b['uuid']));
        return $rows;
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
        $merged = array_merge($base, $overlay);
        if (isset($base['_marker_key']) && !isset($overlay['_marker_key'])) {
            $merged['_marker_key'] = $base['_marker_key'];
        }
        $roots = $this->regen_context_root_ids($base);
        foreach ($this->regen_context_root_ids($overlay) as $rootId) {
            $roots[$rootId] = $rootId;
        }
        if ($roots) {
            $rootIds = array_values($roots);
            sort($rootIds, SORT_NUMERIC);
            $merged['root_ids'] = $rootIds;
        }
        $children = [];
        foreach ([$base, $overlay] as $context) {
            foreach ((array) ($context['child_ids'] ?? []) as $childId) {
                $childId = (int) $childId;
                if ($childId > 0) {
                    $children[$childId] = $childId;
                }
            }
        }
        if ($children || array_key_exists('child_ids', $base) || array_key_exists('child_ids', $overlay)) {
            $merged['child_ids'] = array_values($children);
            sort($merged['child_ids'], SORT_NUMERIC);
        }
        return $merged;
    }

    /** @param array<string,mixed> $context @return array<int,int> keyed by id */
    private function regen_context_root_ids(array $context): array {
        $roots = [];
        foreach ((array) ($context['root_ids'] ?? []) as $rootId) {
            $rootId = (int) $rootId;
            if ($rootId > 0) {
                $roots[$rootId] = $rootId;
            }
        }
        foreach (['old_parent_id', 'new_parent_id', 'parent_id'] as $key) {
            $rootId = (int) ($context[$key] ?? 0);
            if ($rootId > 0) {
                $roots[$rootId] = $rootId;
            }
        }
        return $roots;
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
        global $wpdb;
        $out = [];
        $explicitIds = [];
        foreach ($deleteWork as $entry) {
            if (($entry['type'] ?? '') !== 'post') {
                continue;
            }
            $entryId = Ledger::id_for((string) ($entry['uuid'] ?? ''), Ledger::KIND_POST);
            if ($entryId !== null) {
                $explicitIds[(int) $entryId] = true;
            }
        }
        foreach ($deleteWork as $entry) {
            if (($entry['type'] ?? '') !== 'post') {
                continue;
            }
            $uuid = (string) ($entry['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $id = Ledger::id_for($uuid, Ledger::KIND_POST);
            if ($id === null) {
                if (!$persistGenericDebt) {
                    continue;
                }
                $stored = Ledger::kv_get(self::REGEN_DELETE_CONTEXT_PREFIX . $uuid);
                $decoded = is_string($stored) ? json_decode($stored, true) : null;
                $storedType = is_array($decoded) ? (string) ($decoded['post_type'] ?? '') : '';
                if ($storedType !== '' && $this->delete_context_consumer($storedType)
                    && is_array($decoded) && (int) ($decoded['id'] ?? 0) > 0) {
                    $out[] = [
                        'kind' => 'delete',
                        'uuid' => $uuid,
                        'id' => (int) $decoded['id'],
                        'post_type' => $storedType,
                        'parent_id' => (int) ($decoded['parent_id'] ?? 0),
                        'child_ids' => array_values(array_map('intval', (array) ($decoded['child_ids'] ?? []))),
                    ];
                } elseif ($stored !== null) {
                    // A declaration may have been removed or disabled after a
                    // failed delete.  Do not replay an orphan receipt through
                    // an unrelated/legacy path.
                    Ledger::kv_delete(self::REGEN_DELETE_CONTEXT_PREFIX . $uuid);
                }
                continue;
            }
            $row = $this->regen_checked_get_row($wpdb->prepare(
                "SELECT post_type, post_parent FROM {$wpdb->posts} WHERE ID = %d",
                $id
            ), "delete source post $id");
            if ($row === null) {
                if (!$persistGenericDebt) {
                    continue;
                }
                // A previous apply may have committed the post delete and
                // then failed during rebuild.  Reuse the durable pre-delete
                // receipt rather than losing the deleted child's parent id on
                // the retry just because the ledger row still exists.
                $stored = Ledger::kv_get(self::REGEN_DELETE_CONTEXT_PREFIX . $uuid);
                $decoded = is_string($stored) ? json_decode($stored, true) : null;
                $storedType = is_array($decoded) ? (string) ($decoded['post_type'] ?? '') : '';
                if ($storedType !== '' && $this->delete_context_consumer($storedType)
                    && is_array($decoded) && (int) ($decoded['id'] ?? 0) > 0) {
                    $out[] = [
                        'kind' => 'delete',
                        'uuid' => $uuid,
                        'id' => (int) $decoded['id'],
                        'post_type' => $storedType,
                        'parent_id' => (int) ($decoded['parent_id'] ?? 0),
                        'child_ids' => array_values(array_map('intval', (array) ($decoded['child_ids'] ?? []))),
                    ];
                } elseif ($stored !== null) {
                    Ledger::kv_delete(self::REGEN_DELETE_CONTEXT_PREFIX . $uuid);
                }
                continue;
            }
            $postType = (string) ($row['post_type'] ?? '');
            if ($postType === '' || !$this->delete_context_consumer($postType)) {
                // This delete has no enabled batch consumer.  Remove only a
                // stale receipt for the same uuid, and never create one.
                if ($persistGenericDebt) {
                    Ledger::kv_delete(self::REGEN_DELETE_CONTEXT_PREFIX . $uuid);
                }
                continue;
            }
            $parentId = (int) ($row['post_parent'] ?? 0);
            $childIds = [];
            foreach ($this->policy->child_post_types($postType) as $childPostType) {
                // Keep this query direct and parameterized: a manifest may
                // name only validated post-type identifiers, but it never
                // supplies SQL. Each declared type is queried separately so
                // the receipt remains deterministic without a catalog scan.
                foreach ($this->regen_checked_get_col($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s ORDER BY ID ASC",
                    $id,
                    $childPostType
                ), "delete child inventory for post $id (type $childPostType)") as $childId) {
                    $childId = (int) $childId;
                    // A parent delete can coexist with a still-managed child
                    // post in a partial revision. Only direct, declared
                    // children that are also explicit tombstones belong to
                    // this deletion receipt; target-local live children keep
                    // their own derived rows and refresh through the batch
                    // live-id pass.
                    if ($childId > 0 && isset($explicitIds[$childId])) {
                        $childIds[$childId] = $childId;
                    }
                }
            }
            $childIds = array_values($childIds);
            sort($childIds, SORT_NUMERIC);
            $context = [
                'kind' => 'delete',
                'uuid' => $uuid,
                'id' => (int) $id,
                'post_type' => $postType,
                'parent_id' => $parentId,
                'child_ids' => $childIds,
            ];
            if ($persistGenericDebt) {
                Ledger::kv_set(self::REGEN_DELETE_CONTEXT_PREFIX . $uuid, json_encode($context));
            }
            $out[] = $context;
        }
        return $out;
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
        $out = [];
        foreach (Ledger::kv_prefix(self::REGEN_DELETE_CONTEXT_PREFIX) as $key => $encoded) {
            $context = is_string($encoded) ? json_decode($encoded, true) : null;
            if (!is_array($context)) {
                continue;
            }
            $postType = (string) ($context['post_type'] ?? '');
            $id = (int) ($context['id'] ?? 0);
            if ($postType === '' || $id <= 0) {
                continue;
            }
            if (!isset($context['uuid']) || (string) $context['uuid'] === '') {
                $context['uuid'] = substr((string) $key, strlen(self::REGEN_DELETE_CONTEXT_PREFIX));
            }
            if (!isset($context['kind']) || (string) $context['kind'] === '') {
                $context['kind'] = 'delete';
            }
            $context['_marker_key'] = (string) $key;
            $out[] = $context;
        }
        return $out;
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
        global $wpdb;

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
        // Future-post cron is derived operational state. Raw SQL deliberately
        // bypasses wp_transition_post_status(), so reproduce only its narrow
        // scheduling semantic after the authored transaction commits.
        foreach ($work as $entry) {
            $entity = $tree[$entry['uuid']] ?? null;
            if (($entity['type'] ?? '') !== 'post') {
                continue;
            }
            $front = $entity['data'];
            $postId = Ledger::id_for($entry['uuid'], Ledger::KIND_POST);
            if ($postId === null) {
                continue;
            }
            $cleared = wp_clear_scheduled_hook('publish_future_post', [$postId]);
            if ($cleared === false) {
                throw new \RuntimeException("duo: failed to clear prior publication schedule for post $postId");
            }
            if (($front['status'] ?? '') !== 'future') {
                continue;
            }
            $timestamp = strtotime((string) $front['date_gmt'] . ' UTC');
            if ($timestamp === false || !wp_schedule_single_event($timestamp, 'publish_future_post', [$postId])) {
                throw new \RuntimeException("duo: failed to schedule future post $postId at {$front['date_gmt']} UTC");
            }
            if (wp_next_scheduled('publish_future_post', [$postId]) !== $timestamp) {
                throw new \RuntimeException("duo: future-post schedule verification failed for post $postId");
            }
        }

        // Use WordPress's registered taxonomy callback contract rather than
        // a post-only COUNT query. Hierarchical taxonomies, attachment
        // taxonomies, and custom update_count_callback implementations may
        // define different published/attached semantics.
        $needsTaxonomyRecount = !$suppressScopedExternalEffects
            || $this->scoped_work_needs_taxonomy_recount($work, $tree, $appliedDeletions);
        $taxes = $needsTaxonomyRecount
            ? array_merge($this->policy->taxonomies(), ['nav_menu'])
            : [];
        if ($taxes !== []) {
            Db::checkpoint('rebuild term counts');
        }
        foreach (array_unique($taxes) as $taxonomy) {
            $termTaxonomyIds = $wpdb->get_col($wpdb->prepare(
                "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
                $taxonomy
            )) ?: [];
            if (!$termTaxonomyIds) {
                continue;
            }
            $termTaxonomyIds = array_map('intval', $termTaxonomyIds);
            if (taxonomy_exists($taxonomy)) {
                if (wp_update_term_count_now($termTaxonomyIds, $taxonomy) === false) {
                    throw new \RuntimeException("duo: registered recount callback failed for taxonomy '$taxonomy'");
                }
                continue;
            }

            // A taxonomy_patterns-backed definition can be landed by a
            // typed-snapshot table in this same request, after plugins ran
            // their init registration. The version-pinned manifest carries
            // the plugin's callback and object types for exactly this gap.
            $callback = $this->policy->pattern_update_count_callback($taxonomy);
            $objectTypes = $this->policy->pattern_object_type($taxonomy);
            if ($callback === null || $objectTypes === null || !is_callable($callback)) {
                throw new \RuntimeException(
                    "duo: required taxonomy '$taxonomy' is not registered during recount and has no callable manifest count contract"
                );
            }
            $taxonomyObject = new \WP_Taxonomy($taxonomy, $objectTypes, [
                'update_count_callback' => $callback,
            ]);
            try {
                call_user_func($callback, $termTaxonomyIds, $taxonomyObject);
            } catch (\Throwable $t) {
                throw new \RuntimeException("duo: manifest recount callback failed for taxonomy '$taxonomy'", 0, $t);
            }
        }

        // attachment metadata (thumbnails etc.) — derived, regenerated
        foreach (array_filter($attachmentIds) as $id) {
            Db::checkpoint('rebuild attachment metadata');
            try {
                if (!function_exists('wp_generate_attachment_metadata')) {
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                }
                $file = get_attached_file($id);
                if (!$file || !is_file($file)) {
                    throw new \RuntimeException('attached file is missing');
                }
                $meta = wp_generate_attachment_metadata($id, $file);
                if ($meta === false || is_wp_error($meta)) {
                    throw new \RuntimeException('metadata generator reported failure');
                }
                // An empty array is valid for attachment types that have no
                // generated metadata. False/WP_Error above is the failure
                // signal; a non-empty result must persist through the same
                // checked mutation boundary as every authored write.
                if ($meta !== []) {
                    $this->upsert_meta(
                        $wpdb->postmeta,
                        'post_id',
                        (int) $id,
                        '_wp_attachment_metadata',
                        maybe_serialize($meta),
                        'rebuild attachment metadata'
                    );
                }
            } catch (\Throwable $t) {
                throw new \RuntimeException("duo: required attachment metadata rebuild failed for attachment $id", 0, $t);
            }
        }
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
        if ($this->selectedActions !== []) {
            Db::checkpoint('rebuild object cache (pre-action)');
            if (wp_cache_flush() === false) {
                throw new \RuntimeException('duo: required pre-action object-cache flush failed');
            }
        }

        $declarations = $this->selectedActions === [] ? [] : $this->policy->provider_declarations();
        $scopedOrdinal = 3;
        foreach ($this->selectedActions as $action) {
            $actionOrdinal = $scopedOrdinal++;
            $source = Policy::action_source($action, (int) ($action['index'] ?? 0));
            try {
                if (($action['kind'] ?? '') === 'native') {
                    if ($scoped) {
                        $nativeAction = (string) $action['action'];
                        $nativeArgs = (array) ($action['args'] ?? []);
                        $inputHash = NativeActions::scoped_input_hash($nativeAction, $nativeArgs);
                        $capabilityDigest = NativeActions::scoped_action_digest($nativeAction);
                        $effectHash = $this->scoped_action_effect_hash($action);
                        $operation = $this->scoped_effect_operation($actionOrdinal, $inputHash, $effectHash);
                        $intent = $this->scoped_session_intent(
                            $actionOrdinal,
                            $capabilityDigest,
                            $operation['operation_id'],
                            $inputHash,
                            $effectHash,
                            (string) $this->scopedObservation['selected_before_root']
                        );
                        $this->scopedSession->append_intent($intent);
                        $receipt = $this->scoped_receipt_at($actionOrdinal);
                        if ($receipt === null) {
                            $reviewed = NativeActions::reconcile_scoped($nativeAction, $nativeArgs, $operation);
                            if (($reviewed['status'] ?? '') === 'not_started') {
                                $this->renew_provider_lease();
                                $reviewed = NativeActions::invoke_scoped($nativeAction, $nativeArgs, $operation);
                                $this->renew_provider_lease();
                            }
                            $this->assert_scoped_effect_result($reviewed, $operation, $capabilityDigest);
                            $this->scopedSession->append_receipt($this->scoped_session_receipt(
                                $intent,
                                hash('sha256', Canon::encode($reviewed))
                            ));
                            $receipt = $this->scoped_receipt_at($actionOrdinal);
                        } else {
                            // The outer receipt proves what the previous
                            // process observed, not that the postcondition is
                            // still true. Recovery always re-reads it before
                            // trusting completion and never invokes again.
                            $reviewed = NativeActions::reconcile_scoped(
                                $nativeAction,
                                $nativeArgs,
                                $operation
                            );
                            $this->assert_scoped_effect_result(
                                $reviewed,
                                $operation,
                                $capabilityDigest
                            );
                        }
                        ScopedApplySession::require_reviewed_effect_receipt($receipt, $reviewed);
                        $this->warnings[] = "native action fired: $nativeAction (scoped, verified)";
                        $this->actionReceipts[] = $this->scoped_public_action_receipt(
                            $source,
                            'native',
                            $operation,
                            $capabilityDigest,
                            $receipt
                        );
                        continue;
                    }
                    $receipt = NativeActions::execute(
                        (string) $action['action'],
                        (array) ($action['args'] ?? [])
                    );
                    // DUO-3282: unconditional per-declaration confirmation
                    // that this pass actually invoked the declaration — the
                    // layer that was previously unverifiable from outside (a
                    // caller could only ever infer it indirectly, e.g. by
                    // querying a rebuilder's own side-effect table after the
                    // fact, as DUO-3267's grind script did before that fix
                    // existed). The structured receipt below carries the
                    // observed before/after state the warning line cannot.
                    $this->warnings[] = "native action fired: {$action['action']} (verified)";
                    $this->actionReceipts[] = [
                        'manifest' => (string) $action['manifest'],
                        'source' => $source,
                        'kind' => 'native',
                        'before' => $receipt['before'],
                        'after' => $receipt['after'],
                        'verified' => true,
                    ];
                    continue;
                }
                $id = (string) $action['provider'];
                $capability = (string) $action['capability'];
                $declaration = $this->negotiatedProviders['capabilities'][$id][$capability] ?? null;
                if ($declaration === null) {
                    // Unreachable: run() negotiates the same selection before
                    // any mutation and refuses on any problem. Fail closed
                    // rather than fatal on a null instance if that ordering
                    // is ever changed.
                    throw new \RuntimeException(
                        "duo: required manifest action '$source' was never negotiated before mutation"
                    );
                }
                $entities = [];
                $context = [];
                $pendingMarkers = [];
                $deliveredMarkerKeys = [];
                $deletedUuids = [];
                if ($declaration['scope'] === 'entity') {
                    $context = $this->action_context(
                        $action,
                        $declaration,
                        $appliedDeletions,
                        $regenContext,
                        $durableReparents,
                        $durableDeletions
                    );
                    // Deletion rows are assembled whether or not the capability
                    // asked for them, because they answer a second question no
                    // declaration can waive: which of the ids the entity batch
                    // would otherwise carry are for entities that are GONE
                    // (regen_batch_dependencies()'s own deleted-id filter, same
                    // rows, same child_ids). action_context() still assembles
                    // only declared channels — an undeclared channel delivers
                    // nothing — so this is the identical projection reused, not
                    // a second delivery path.
                    $deletionRows = $context['deletions']
                        ?? $this->action_deletions($action, $appliedDeletions, $durableDeletions);
                    $batch = $this->action_entities(
                        $action,
                        $work,
                        $tree,
                        $deletionRows,
                        !$scoped
                    );
                    $entities = $batch['entities'];
                    $pendingMarkers = $batch['markers'];
                    if (Providers::declares_channel($declaration, 'deletions')) {
                        $deliveredMarkerKeys = array_merge(
                            $deliveredMarkerKeys,
                            $this->action_marker_keys($action, $durableDeletions)
                        );
                        foreach ($deletionRows as $row) {
                            $deletedUuid = (string) ($row['uuid'] ?? '');
                            if ($deletedUuid !== '' && (string) ($row['post_type'] ?? '') !== '') {
                                $deletedUuids[$deletedUuid] = (string) $row['post_type'];
                            }
                        }
                    }
                    if (Providers::declares_channel($declaration, 'reparents')) {
                        $deliveredMarkerKeys = array_merge(
                            $deliveredMarkerKeys,
                            $this->action_marker_keys($action, $durableReparents)
                        );
                    }
                }
                if ($declaration['scope'] === 'entity'
                    && !$this->action_batch_has_work($declaration, $entities, $context)) {
                    // A trigger can select this action off deletion or retry-
                    // tombstone surfaces alone (rebuild_surfaces() includes
                    // both), and a deleted entity has no generated data left
                    // to regenerate. Invoking with an empty batch would let
                    // the provider verify the nothing it received and record
                    // a repair as done — so the skip is explicit, in both the
                    // human line and the machine receipt, never silent.
                    //
                    // DUO-3369 narrowed WHEN that is true rather than
                    // loosening it: a capability that declared the `deletions`
                    // channel asked to be told about tombstones, so a
                    // deletion-only selection is real work for it and no
                    // longer skipped. The skip survives for exactly the case
                    // it was written for — nothing declared, or nothing
                    // declared carried work. The receipt strings below stay
                    // byte-identical on the channel-less path.
                    $declared = (array) ($declaration['context'] ?? []);
                    $channelState = $this->skipped_channel_states($declared, $context);
                    if ($scoped) {
                        $inputHash = Providers::scoped_input_hash($action, $declaration, $entities, $context);
                        $capabilityDigest = (string) ($this->negotiatedProviders['scoped_capabilities'][$id][$capability]['capability_digest'] ?? '');
                        $effectHash = $this->scoped_action_effect_hash($action);
                        $operation = $this->scoped_effect_operation($actionOrdinal, $inputHash, $effectHash);
                        $intent = $this->scoped_session_intent(
                            $actionOrdinal,
                            $capabilityDigest,
                            $operation['operation_id'],
                            $inputHash,
                            $effectHash,
                            (string) $this->scopedObservation['selected_before_root']
                        );
                        $this->scopedSession->append_intent($intent);
                        if ($this->scoped_receipt_at($actionOrdinal) === null) {
                            $skipHash = hash('sha256', Canon::encode([
                                'status' => 'bounded_skip',
                                'operation' => $operation,
                                'capability_digest' => $capabilityDigest,
                            ]));
                            $this->scopedSession->append_receipt(
                                $this->scoped_session_receipt($intent, $skipHash)
                            );
                        }
                        $this->warnings[] = "provider capability skipped: $id $capability (scoped empty batch)";
                        $this->actionReceipts[] = $this->scoped_public_action_receipt(
                            $source,
                            'provider',
                            $operation,
                            $capabilityDigest,
                            $this->scoped_receipt_at($actionOrdinal),
                            'bounded_skip'
                        );
                        continue;
                    }
                    $this->warnings[] = "provider capability skipped: $id $capability "
                        . '(entity-scoped; no created/updated entity matched its triggers this run'
                        . ($declared === [] ? '' : ', and no declared batch channel carried work: '
                            . $channelState) . ')';
                    $this->actionReceipts[] = [
                        'manifest' => (string) $action['manifest'],
                        'source' => $source,
                        'kind' => 'provider',
                        'skipped' => $declared === []
                            ? 'empty entity batch (deletion/tombstone-only trigger match)'
                            : 'empty entity batch and no declared batch channel carried work ('
                                . $channelState . ')',
                    ];
                    continue;
                }
                if ($scoped) {
                    $inputHash = Providers::scoped_input_hash($action, $declaration, $entities, $context);
                    $capabilityDigest = (string) ($this->negotiatedProviders['scoped_capabilities'][$id][$capability]['capability_digest'] ?? '');
                    $effectHash = $this->scoped_action_effect_hash($action);
                    $operation = $this->scoped_effect_operation($actionOrdinal, $inputHash, $effectHash);
                    $intent = $this->scoped_session_intent(
                        $actionOrdinal,
                        $capabilityDigest,
                        $operation['operation_id'],
                        $inputHash,
                        $effectHash,
                        (string) $this->scopedObservation['selected_before_root']
                    );
                    $this->scopedSession->append_intent($intent);
                    $outerReceipt = $this->scoped_receipt_at($actionOrdinal);
                    if ($outerReceipt === null) {
                        $this->renew_provider_lease();
                        $reviewed = Providers::reconcile_scoped(
                            $this->negotiatedProviders['providers'][$id],
                            $action,
                            $declaration,
                            $operation,
                            $entities,
                            $context
                        );
                        if (($reviewed['status'] ?? '') === 'not_started') {
                            $reviewed = Providers::invoke_scoped(
                                $this->negotiatedProviders['providers'][$id],
                                $action,
                                $declaration,
                                $operation,
                                $entities,
                                $context
                            );
                        }
                        $this->renew_provider_lease();
                        $this->assert_scoped_effect_result($reviewed, $operation, $capabilityDigest);
                        $this->scopedSession->append_receipt($this->scoped_session_receipt(
                            $intent,
                            hash('sha256', Canon::encode($reviewed))
                        ));
                        $outerReceipt = $this->scoped_receipt_at($actionOrdinal);
                    } else {
                        $this->renew_provider_lease();
                        $reviewed = Providers::reconcile_scoped(
                            $this->negotiatedProviders['providers'][$id],
                            $action,
                            $declaration,
                            $operation,
                            $entities,
                            $context
                        );
                        $this->renew_provider_lease();
                        $this->assert_scoped_effect_result(
                            $reviewed,
                            $operation,
                            $capabilityDigest
                        );
                    }
                    ScopedApplySession::require_reviewed_effect_receipt($outerReceipt, $reviewed);
                    $version = (string) ($declarations[$id]['version'] ?? '?');
                    $this->warnings[] = "provider capability fired: $id@$version $capability (scoped, verified)";
                    $this->actionReceipts[] = $this->scoped_public_action_receipt(
                        $source,
                        'provider',
                        $operation,
                        $capabilityDigest,
                        $outerReceipt
                    );
                    continue;
                }
                // Arm the retry vocabulary BEFORE the opaque call, not after a
                // caught failure: a provider can exhaust memory or hit a fatal
                // that no catch block here observes, and the marker's whole
                // job is to survive that. Clearing happens only once the
                // receipt says verified === true (below), so failure — caught,
                // uncaught, or a crash mid-flight — leaves every marker armed
                // and the next apply re-delivers exactly this batch.
                foreach ($pendingMarkers as $markerUuid => $markerPostType) {
                    Ledger::kv_set(self::REGEN_PENDING_PREFIX . $markerUuid, (string) $markerPostType);
                }
                // Renew the promotion lease either side of the call, the same
                // bracket regen_batch_dependencies() puts around its own
                // opaque plugin call. The provider contract has no heartbeat
                // parameter (invoke() takes a capability name and typed args,
                // nothing else), so this bracket is all the engine can honestly
                // offer: a call that runs longer than the lease TTL is bounded
                // by timeout_seconds rather than kept alive mid-flight.
                $this->renew_provider_lease();
                $receipt = Providers::invoke(
                    $this->negotiatedProviders['providers'][$id],
                    $action,
                    $declaration,
                    $entities,
                    $context
                );
                $this->renew_provider_lease();
                // Clear-on-verified, marker-key addressed: exactly the markers
                // whose rows this invocation delivered, never a prefix sweep.
                // A verified receipt is the convergence boundary the batch
                // path's own exact-verification clearing uses (:6114-6134);
                // anything short of it keeps the marker armed.
                foreach ($deliveredMarkerKeys as $markerKey) {
                    Ledger::kv_delete($markerKey);
                }
                foreach ($pendingMarkers as $markerUuid => $_markerPostType) {
                    Ledger::kv_delete(self::REGEN_PENDING_PREFIX . $markerUuid);
                }
                // A deleted uuid can still carry a pending marker, because its
                // stale ledger mapping is what discovered the entity in the
                // first place. Only a capability that was actually TOLD about
                // the deletion may retire it — the batch path clears it on the
                // strength of the adapter's exact absence verification, and a
                // capability that never declared `deletions` performed no such
                // check. Left armed, it is swept by the resolve-or-drop pass in
                // action_entities() once the ledger forgets the mapping.
                foreach ($deletedUuids as $deletedUuid => $_deletedPostType) {
                    Ledger::kv_delete(self::REGEN_PENDING_PREFIX . $deletedUuid);
                }
                $version = (string) ($declarations[$id]['version'] ?? '?');
                $this->warnings[] = "provider capability fired: $id@$version $capability ("
                    . $receipt['duration_seconds'] . 's, verified)';
                $this->actionReceipts[] = [
                    'manifest' => (string) $action['manifest'],
                    'source' => $source,
                    'kind' => 'provider',
                    'provider_version' => $version,
                    'duration_seconds' => $receipt['duration_seconds'],
                    'before' => $receipt['before'],
                    'after' => $receipt['after'],
                    'verified' => true,
                ];
            } catch (\Throwable $t) {
                if ($scoped && $this->scopedSession !== null
                    && !$this->scopedSession->is_recovery_required()
                    && !$this->scopedSession->is_terminal()) {
                    $this->scopedSession->recover(hash('sha256', 'duo:scoped-effect-reconciliation-refused'));
                }
                // DUO-3206 posture, unchanged by the channel swap: a failed
                // required rebuild is a hard apply failure, never a warning,
                // so the target stays truthfully unapplied and retryable.
                if (str_starts_with($t->getMessage(), 'duo: required manifest action')) {
                    throw $t;
                }
                // The inner message rides in the wrapper because nothing in
                // the product path renders getPrevious() — Cli's handlers all
                // print getMessage() alone. Providers assemble exit codes and
                // stdout/stderr tails precisely so an operator sees the real
                // error (DUO-3282); swallowing them here would recreate the
                // "exited 255, go reproduce it by hand" experience that issue
                // closed (independent review of this change caught exactly
                // that regression before it shipped).
                throw new \RuntimeException(
                    "duo: required manifest action '$source' failed — " . $t->getMessage(),
                    0,
                    $t
                );
            }
        }

        Db::checkpoint('rebuild object cache');
        if (wp_cache_flush() === false) {
            throw new \RuntimeException('duo: required object-cache flush failed');
        }
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
        if ($this->selectedActions !== []) {
            throw CommandRefusalException::applyRefused(
                'scoped promotion selected a manifest/provider/native effect without a scoped inverse contract',
                'use ordinary scoped apply or narrow the contract to DB-contained state without declared actions',
                'duo: scoped promotion external effect refused'
            );
        }
        foreach ($work as $entry) {
            $uuid = (string) ($entry['uuid'] ?? '');
            $type = (string) ($tree[$uuid]['type'] ?? '');
            if ($type === 'post' || $type === 'term' || $type === 'menu' || $type === '') {
                throw CommandRefusalException::applyRefused(
                    'scoped promotion selected a post/term/menu surface whose derived effects are not checkpoint-only',
                    'narrow the contract to options, sidebars, user meta, or declared authored snapshot tables',
                    'duo: scoped promotion selected unsupported derived effects'
                );
            }
        }
        foreach ($deleteWork as $entry) {
            $kind = (string) ($entry['deletion_kind'] ?? $entry['kind'] ?? $entry['type'] ?? '');
            if (!in_array($kind, ['option', 'options', 'table'], true)) {
                throw CommandRefusalException::applyRefused(
                    'scoped promotion selected a deletion outside its checkpoint-only state profile',
                    'narrow the contract to option or declared authored snapshot-table tombstones',
                    'duo: scoped promotion selected unsupported deletion effects'
                );
            }
        }
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
        foreach ($work as $entry) {
            $type = (string) ($tree[(string) ($entry['uuid'] ?? '')]['type'] ?? '');
            if (in_array($type, ['post', 'term', 'menu'], true)) {
                return true;
            }
        }
        foreach ($deletions as $entry) {
            $kind = (string) ($entry['deletion_kind'] ?? $entry['kind'] ?? $entry['type'] ?? '');
            if (in_array($kind, ['post', 'term', 'menu'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Assemble the entity batch one `scope: entity` provider capability
     * receives: the exact rows of this run's authored work whose canonical
     * surface is named by the action's own triggers, each carrying that
     * surface and the target-local id the ledger already minted.
     *
     * Batched into one invoke() rather than one call per entity, because a
     * plugin's own repair of a derived projection is usually cheaper in bulk
     * (the WooCommerce lookup regenerator's batch entry point exists for the
     * same reason) and because a per-entity loop would multiply the declared
     * timeout budget by a number the manifest cannot see.
     *
     * A matching row whose id the ledger cannot resolve fails closed: handing
     * a provider a silently shortened batch would let it verify the entities
     * it did receive and report success for a repair that skipped the rest.
     *
     * THREE sources, not one (DUO-3342 added the second and third, both for
     * parity with what regen_batch_dependencies() has always done):
     *   (a) this run's authored work, as above;
     *   (b) any uuid still carrying an armed `regen_pending:<uuid>` marker
     *       whose post type's surface this action triggers on. Plan's content
     *       hash by design never reflects derived state, so an entity whose
     *       repair failed on a previous apply shows as 'unchanged' and never
     *       re-enters $work — without this union a hard failure followed by a
     *       plain re-run would report all clear. A marker whose uuid no longer
     *       resolves is swept with the batch path's exact warning wording
     *       rather than left to sit in duo_kv forever;
     *   (c) minus every id the deletions projection says is GONE, including
     *       the captured `child_ids` (regen_batch_dependencies():6005-6032):
     *       the ledger mapping of a deleted entity is deliberately retained
     *       until the rebuild pass succeeds, so pending discovery can still
     *       surface it — but handing it over as LIVE work would ask an adapter
     *       to repair a row that no longer exists.
     *
     * A post type the batch dispatcher claims (`regen_batch() !== null`) is
     * skipped in (b): the two dispatchers share one marker prefix on purpose,
     * and a post type is owned by exactly one of them — negotiation refuses a
     * channel-declaring capability on a batch-claimed post type outright
     * (Providers::negotiate()), and this is the same ownership rule applied to
     * discovery so neither dispatcher consumes the other's retry state.
     *
     * @param array<string,mixed> $action a selected provider-kind declaration
     * @param array<int,array> $work
     * @param array<string,array> $tree
     * @param list<array<string,mixed>> $deletionRows this action's assembled
     *   deletions projection (see rebuild(): assembled whether or not the
     *   capability declared the channel, because this filter is not waivable)
     * @param bool $includeGenericPending full apply may consume its global
     *   retry queue; scoped apply must never scan or mutate that namespace
     * @return array{entities:list<array{kind:string, id:int}>, markers:array<string,string>}
     */
    private function action_entities(
        array $action,
        array $work,
        array $tree,
        array $deletionRows = [],
        bool $includeGenericPending = true
    ): array {
        $triggers = array_fill_keys((array) ($action['triggers'] ?? []), true);
        $deletedIds = [];
        foreach ($deletionRows as $row) {
            $deletedId = (int) ($row['id'] ?? 0);
            if ($deletedId > 0) {
                $deletedIds[$deletedId] = true;
            }
            foreach ((array) ($row['child_ids'] ?? []) as $childId) {
                $childId = (int) $childId;
                if ($childId > 0) {
                    $deletedIds[$childId] = true;
                }
            }
        }
        $entities = [];
        $markers = [];
        foreach ($work as $entry) {
            $uuid = (string) ($entry['uuid'] ?? '');
            $entity = $uuid !== '' ? ($tree[$uuid] ?? null) : null;
            if (!is_array($entity)) {
                continue;
            }
            foreach ($this->entity_rebuild_surfaces($entity, $entry) as $surface) {
                if (!isset($triggers[$surface])) {
                    continue;
                }
                $id = $this->entity_local_id($entity, $uuid);
                if ($id === null) {
                    throw new \RuntimeException(
                        "duo: entity-scoped provider capability '{$action['provider']}/{$action['capability']}' "
                        . "selected canonical surface '$surface', but $uuid has no resolvable target-local id; "
                        . 'declare triggers naming only surfaces whose entities carry one'
                    );
                }
                if (isset($deletedIds[$id])) {
                    continue;
                }
                $entities[$surface . "\0" . $id] = ['kind' => $surface, 'id' => $id];
                if (str_starts_with($surface, 'post:')) {
                    $markers[$uuid] = substr($surface, strlen('post:'));
                }
            }
        }
        foreach ($includeGenericPending ? Ledger::kv_prefix(self::REGEN_PENDING_PREFIX) : [] as $key => $postType) {
            $postType = (string) $postType;
            $surface = 'post:' . $postType;
            if ($postType === '' || !isset($triggers[$surface])
                || $this->policy->regen_batch($postType) !== null) {
                continue;
            }
            $uuid = substr((string) $key, strlen(self::REGEN_PENDING_PREFIX));
            if (isset($markers[$uuid])) {
                continue; // already covered by this run's work above
            }
            $id = Ledger::id_for($uuid, Ledger::KIND_POST);
            if ($id === null) {
                Ledger::kv_delete((string) $key);
                $this->warnings[] = "regen_pending marker for post $uuid (type '$postType') dropped: "
                    . 'uuid no longer resolves to a local post id';
                continue;
            }
            if (isset($deletedIds[$id])) {
                continue;
            }
            $entities[$surface . "\0" . $id] = ['kind' => $surface, 'id' => $id];
            $markers[$uuid] = $postType;
        }
        $out = array_values($entities);
        usort($out, static fn(array $a, array $b): int =>
            strcmp($a['kind'], $b['kind']) ?: ($a['id'] <=> $b['id']));
        ksort($markers, SORT_STRING);
        return ['entities' => $out, 'markers' => $markers];
    }

    /**
     * The durable marker keys this action's channel delivery covered, so the
     * clear-on-verified consumer in rebuild() addresses exactly those rows.
     *
     * `_marker_key` is stripped from every delivered row (a capability has no
     * business addressing engine bookkeeping keys), so the association has to
     * be recomputed here from the same durable set and the same trigger
     * narrowing the channel used. Contexts that carry no marker key — this
     * run's in-memory captures reaching action_reparents() through
     * $regenContext — contribute nothing: their durable twin is already in the
     * durable set, written by the capture itself before this pass ran.
     *
     * @param array<string,mixed> $action
     * @param list<array<string,mixed>> $durableContexts
     * @return list<string>
     */
    private function action_marker_keys(array $action, array $durableContexts): array {
        $triggers = array_fill_keys((array) ($action['triggers'] ?? []), true);
        $keys = [];
        foreach ($durableContexts as $context) {
            $markerKey = (string) ($context['_marker_key'] ?? '');
            $postType = (string) ($context['post_type'] ?? '');
            if ($markerKey === '' || $postType === '' || !isset($triggers['post:' . $postType])) {
                continue;
            }
            $keys[$markerKey] = $markerKey;
        }
        return array_values($keys);
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
     * Assemble the engine batch channels one `scope: entity` capability
     * DECLARED (DUO-3369), and only those.
     *
     * Opt-in is the whole design. The entity batch above answers "what did
     * this run write"; a derived-state repair usually also needs "what did it
     * remove, and what moved" — the facts the regenerator channel already
     * hands its batch adapters, and whose absence from the provider contract
     * is what blocked migrating that dispatch (DUO-3342). Handing every
     * capability all of it instead would change what already-shipped providers
     * receive, so each channel is delivered only where the negotiated
     * declaration named it, and a capability that named none takes the exact
     * pre-DUO-3369 path (Providers::batch_payload()).
     *
     * Channels are narrowed by the action's OWN triggers, identically to the
     * entity batch: an action selected for `post:product` receives product
     * tombstones, never every tombstone in the revision. That keeps one
     * adapter's declaration from becoming a window onto another adapter's
     * entities, which is the ownership rule the closed trigger vocabulary
     * exists to enforce.
     *
     * @param array<string,mixed> $action a selected provider-kind declaration
     * @param array<string,mixed> $declaration the negotiated capability
     * @param array<int,array<string,mixed>> $appliedDeletions tombstone rows
     *   this run applied, or a previous incomplete run already made absent
     *   (rebuild() composes them; NOT the wider selection set)
     * @param array<int,array<string,mixed>> $regenContext pre-mutation receipts
     * @param array<int,array<string,mixed>> $durableReparents outstanding
     *   reparent markers from an earlier incomplete apply
     * @param array<int,array<string,mixed>> $durableDeletions outstanding
     *   delete markers from an earlier incomplete apply (DUO-3342)
     * @return array<string,mixed> channel name => assembled value
     */
    private function action_context(
        array $action,
        array $declaration,
        array $appliedDeletions,
        array $regenContext,
        array $durableReparents = [],
        array $durableDeletions = []
    ): array {
        $context = [];
        if (Providers::declares_channel($declaration, 'always_on_write')) {
            // A flag about this invocation, not evidence about the revision,
            // so it is assembled unconditionally-true whenever declared and
            // action_batch_has_work() deliberately does not consult it.
            $context['always_on_write'] = true;
        }
        if (Providers::declares_channel($declaration, 'deletions')) {
            $context['deletions'] = $this->action_deletions($action, $appliedDeletions, $durableDeletions);
        }
        if (Providers::declares_channel($declaration, 'reparents')) {
            $context['reparents'] = $this->action_reparents($action, $regenContext, $durableReparents);
        }
        if (Providers::declares_channel($declaration, 'retry')) {
            $context['retry'] = $this->retryingIncompleteApply;
        }
        return $context;
    }

    /**
     * Is there anything for this capability to do, across every channel it
     * declared?
     *
     * `always_on_write` is deliberately NOT consulted here, because the flag it
     * mirrors does not manufacture work either. In
     * regen_batch_dependencies(), an always_on_write batch declaration
     * SUPPRESSES the per-candidate existence check on a write candidate the
     * engine already had ("A conditional batch declaration retains the original
     * existence-gated behavior for a changed work item") — the candidate set
     * itself is still exactly this run's authored work, its pending markers,
     * and its deletion receipts, and an apply that wrote nothing dispatches
     * nothing however the flag is set. Treating it as work here would have
     * meant the opposite: a capability firing on every apply forever, on
     * evidence the engine never assembled. It rides in the envelope instead
     * (action_context()), so the capability knows the basis it fired on.
     *
     * A true `retry` counts as work for the same reason a regen_pending marker
     * is a candidate in its own right — a previous pass committed something
     * and then failed, so "this run wrote nothing" is not evidence the derived
     * state is converged.
     *
     * @param array<string,mixed> $declaration the negotiated capability
     * @param list<array{kind:string,id:int}> $entities
     * @param array<string,mixed> $context
     */
    private function action_batch_has_work(array $declaration, array $entities, array $context): bool {
        if ($entities !== []) {
            return true;
        }
        foreach ($context as $channel => $value) {
            if (in_array($channel, Providers::NO_WORK_CHANNELS, true)) {
                continue;
            }
            if ($value === true || (is_array($value) && $value !== [])) {
                return true;
            }
        }
        return false;
    }

    /**
     * How each declared channel came back, for the skip receipt.
     *
     * Rendered per channel rather than as one list, because the channels have
     * different shapes and "empty" is only true of the row-carrying ones: a
     * boolean channel is false or absent, never empty, and reporting a flag as
     * an empty list would read as a list the engine failed to assemble.
     * `always_on_write` is named as what it is — a flag that carries no work of
     * its own — so a receipt for a capability declaring only that channel says
     * why it was skipped anyway.
     *
     * @param array<int,mixed> $declared the declaration's own `context` list
     * @param array<string,mixed> $context this run's assembled channels
     */
    private function skipped_channel_states(array $declared, array $context): string {
        $parts = [];
        foreach ($declared as $channel) {
            $channel = (string) $channel;
            if (in_array($channel, Providers::NO_WORK_CHANNELS, true)) {
                $parts[] = "$channel (a flag; never work of its own)";
                continue;
            }
            if (!array_key_exists($channel, $context)) {
                $parts[] = "$channel absent";
                continue;
            }
            $value = $context[$channel];
            $parts[] = is_bool($value)
                ? $channel . ' ' . ($value ? 'true' : 'false')
                : "$channel empty";
        }
        return implode(', ', $parts);
    }

    /**
     * The `deletions` channel: the tombstones this run APPLIED, or that a
     * previous incomplete run had already made absent, or that an earlier
     * incomplete apply left a durable receipt for, whose canonical surface the
     * action's triggers name — each as
     * {kind, uuid, id, post_type, parent_id, child_ids}.
     *
     * WHICH tombstones is the load-bearing half, and it is not "the ones this
     * revision contains". rebuild() composes the input as
     * `(with_deletes ? this run's deleteWork : []) + (retryingIncompleteApply ?
     * plan['deleted'] : [])`, so every row is an entity that is genuinely gone:
     * applied by the gated delete block a few lines before this pass, or
     * confirmed absent by the plan on a retry. A tombstone the plan carries
     * while `--with-deletes` was NOT passed is deliberately absent from this
     * channel even though the pre-mutation SELECTION projected surfaces from
     * it — that selection has to be wide (an action must negotiate before the
     * mutation for surfaces it may act on), while this channel is evidence, and
     * a planned-but-ungated tombstone handed to a provider is indistinguishable
     * from an applied one. The narrowed empty-batch skip means such a run
     * yields an empty channel and an explicit skip receipt rather than a
     * capability firing on entities that are all still there.
     *
     * `kind` is the same exact canonical-surface literal the trigger matched,
     * so a row identifies its own surface without the capability re-deriving
     * one from a post type. `uuid` is the canonical identity, which outlives
     * the target row and is the only stable handle for an entity that no
     * longer exists. `id` is the target-local id the ledger still holds, and 0
     * has exactly two meanings, both "the engine has no single local id for
     * this row", never a guessed one:
     *   - the ledger mapping is already GONE — Ledger::forget() runs in the
     *     ledger transaction AFTER this pass, so a tombstone applied by this
     *     run still resolves, while one a previous incomplete run applied
     *     (plan['deleted']) may not;
     *   - the entity KIND has no single row id at all (an options document, a
     *     user-meta sidecar — entity_local_id() returns null for those).
     *     Negotiation keeps that nearly out of reach rather than this method
     *     doing it: an entity-scoped capability's triggers may name only
     *     post:/term:/table: surfaces (`entity_scope_unresolvable_trigger`),
     *     and post/term always carry a ledger kind, so what remains is a
     *     `table:` surface whose declared snapshot table has no id_kind —
     *     which Snapshot::assert_row_schema() refuses in its own right. Stated
     *     because those two restrictions live elsewhere: read a 0 as "no local
     *     id", never as "id zero".
     *
     * The unresolvable case is deliberately NOT the hard failure
     * action_entities() raises for the same situation: there, a just-written
     * entity with no ledger id is a bug that would silently shorten a repair
     * batch; here, a missing mapping is the expected end state of a deletion
     * and refusing on it would make retrying an incomplete apply impossible.
     *
     * PARITY WITH THE REGENERATOR CHANNEL, closed by DUO-3342 rather than
     * documented as a gap: a row now carries `post_type`, `parent_id`, and
     * `child_ids` beside the identity fields — the pre-delete inventory
     * capture_regen_delete_context() takes, which the batch path's own
     * consumer needs (regen_batch_dependencies():6015 filters deleted children
     * out of the live batch by exactly that list). Two changes made it
     * deliverable. The capture's consumer gate now recognizes a
     * `deletions`-declaring capability as a consumer in its own right
     * (delete_context_consumer()), so the inventory is TAKEN for a
     * provider-only manifest at all; and the durable `regen_delete_context:`
     * markers are unioned in below, so the inventory survives an apply that
     * committed the delete and failed before the repair. `child_ids` being a
     * LIST is not the obstacle it reads like in the DUO-3369 prose: the closed
     * scalar row grammar (Providers::FIELD_TYPES) bounds what a MANIFEST may
     * declare as a capability argument, while a batch channel is assembled by
     * the engine and validated only as a list of rows
     * (Providers::batch_payload()).
     *
     * The two sources union the way `reparents` unions its two, and for the
     * same reason: a durable receipt an earlier incomplete apply left behind is
     * the only evidence on a retry whose plan no longer carries the tombstone
     * at all. A durable row WINS the collision, because it carries the
     * inventory the tombstone projection never had; the projection still
     * contributes every applied tombstone that has no receipt (a surface no
     * consumer declared, a term/table tombstone, an id the ledger already
     * forgot), so nothing this channel used to deliver stops being delivered.
     *
     * `post_type` is the empty string, `parent_id` 0, and `child_ids` empty for
     * a row with no captured receipt, and for a tombstone on a non-post surface
     * where the concept does not apply. Every row carries all six keys either
     * way: a consumer must be able to tell "no children" from "the engine did
     * not say", and an ABSENT key would collapse those two.
     *
     * @param array<string,mixed> $action
     * @param array<int,array<string,mixed>> $appliedDeletions
     * @param array<int,array<string,mixed>> $durableDeletions
     * @return list<array{kind:string, uuid:string, id:int, post_type:string, parent_id:int, child_ids:list<int>}>
     */
    private function action_deletions(
        array $action,
        array $appliedDeletions,
        array $durableDeletions = []
    ): array {
        $triggers = array_fill_keys((array) ($action['triggers'] ?? []), true);
        $rows = [];
        foreach ($appliedDeletions as $entry) {
            $uuid = (string) ($entry['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            foreach ($this->deletion_rebuild_surfaces($entry) as $surface) {
                if (!isset($triggers[$surface])) {
                    continue;
                }
                $rows[$surface . "\0" . $uuid] = [
                    'kind' => $surface,
                    'uuid' => $uuid,
                    'id' => $this->entity_local_id($entry, $uuid) ?? 0,
                    'post_type' => str_starts_with($surface, 'post:')
                        ? substr($surface, strlen('post:'))
                        : '',
                    'parent_id' => 0,
                    'child_ids' => [],
                ];
            }
        }
        foreach ($durableDeletions as $context) {
            if (!is_array($context) || ($context['kind'] ?? 'delete') !== 'delete') {
                continue;
            }
            $postType = (string) ($context['post_type'] ?? '');
            $surface = $postType !== '' ? 'post:' . $postType : 'entity:post';
            if (!isset($triggers[$surface])) {
                continue;
            }
            $uuid = (string) ($context['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $childIds = [];
            foreach ((array) ($context['child_ids'] ?? []) as $childId) {
                $childId = (int) $childId;
                if ($childId > 0) {
                    $childIds[$childId] = $childId;
                }
            }
            $childIds = array_values($childIds);
            sort($childIds, SORT_NUMERIC);
            $rows[$surface . "\0" . $uuid] = [
                'kind' => $surface,
                'uuid' => $uuid,
                'id' => (int) ($context['id'] ?? 0),
                'post_type' => $postType,
                'parent_id' => (int) ($context['parent_id'] ?? 0),
                'child_ids' => $childIds,
            ];
        }
        $out = array_values($rows);
        usort($out, static fn(array $a, array $b): int =>
            strcmp($a['kind'], $b['kind']) ?: strcmp($a['uuid'], $b['uuid']));
        return $out;
    }

    /**
     * The `reparents` channel: the pre-mutation reparent receipts this apply
     * captured, for surfaces the action's triggers name — one row per
     * (entity, root), as {kind, uuid, id, root_id, old_parent_id,
     * new_parent_id}.
     *
     * One row per ROOT, rather than one row per entity carrying a list, is
     * what the closed row grammar costs and buys: a row field is a scalar
     * (Providers::FIELD_TYPES), so a receipt whose root set accumulated across
     * a chained A->B->C move — capture_regen_reparent_context() merges the
     * durable marker precisely so root A is not lost when a rebuild fails
     * between moves — normalizes into rows instead of smuggling a list into a
     * string. Dropping the extra roots instead would silently reintroduce the
     * stale-root bug that merge exists to prevent; the old/new pair rides on
     * every row of the same entity so a consumer that only wants "where did
     * this move" still has it without a second channel.
     *
     * TWO sources, unioned, because a receipt this run captured is not the only
     * one that matters. capture_regen_reparent_context() also writes a durable
     * `regen_reparent_context:<uuid>` marker, which regen_batch_dependencies()
     * replays as a candidate in its own right precisely because a previous
     * apply can have committed the move and failed before the derived refresh —
     * on that retry there is no fresh capture at all (the post already sits at
     * its new parent, so the capture correctly records nothing). Reading only
     * this run's in-memory receipts would make the channel silently empty on
     * exactly the run that still owes the repair. Rows dedupe on
     * (kind, uuid, root_id), and a fresh receipt wins the collision — it is the
     * merge of the marker and this run's move, never less than the marker.
     *
     * Bounds worth stating, since both are narrower than "every move the engine
     * knows about":
     *   - a receipt exists only where capture_regen_reparent_context() runs:
     *     post types with a batch regen_dependency, OR (DUO-3369 review, F2)
     *     post types whose canonical surface a reparents-declaring capability
     *     in THIS run's negotiated selection triggers on. A move on any other
     *     post type is not captured, so it is not here.
     *   - the durable half survives as long as its OWNER keeps it alive, and
     *     DUO-3342 gave the provider dispatch that ownership: the marker is
     *     deleted by rebuild()'s action loop only after the declaring capability
     *     returned a `verified === true` receipt, addressed by `_marker_key` and
     *     narrowed to that action's own triggers. A rebuild that fails at or
     *     after the capability therefore RETAINS the marker and the next apply
     *     re-delivers it, which is what makes `idempotent: true` load-bearing
     *     rather than decorative. regen_batch_dependencies()' durable scan
     *     decides whether to sweep RUN-INDEPENDENTLY (see its own comment): a
     *     pinned provider action claiming the surface keeps the marker on a run
     *     that selected nothing there, while a run that did reach the surface
     *     with no capability wanting the channel sweeps it and says so.
     *
     * @param array<string,mixed> $action
     * @param array<int,array<string,mixed>> $regenContext
     * @param array<int,array<string,mixed>> $durableReparents
     * @return list<array{kind:string, uuid:string, id:int, root_id:int, old_parent_id:int, new_parent_id:int}>
     */
    private function action_reparents(
        array $action,
        array $regenContext,
        array $durableReparents = []
    ): array {
        $triggers = array_fill_keys((array) ($action['triggers'] ?? []), true);
        $rows = [];
        foreach (array_merge($durableReparents, $regenContext) as $entry) {
            if (!is_array($entry) || ($entry['kind'] ?? 'delete') !== 'reparent') {
                continue;
            }
            $postType = (string) ($entry['post_type'] ?? '');
            $surface = $postType !== '' ? 'post:' . $postType : 'entity:post';
            if (!isset($triggers[$surface])) {
                continue;
            }
            $uuid = (string) ($entry['uuid'] ?? '');
            // capture_regen_reparent_context() records nothing without a
            // previous parent, so the root set is non-empty in practice; the
            // fallback keeps a malformed receipt VISIBLE as a move with no
            // known root rather than dropping the entity from the channel.
            $roots = $this->regen_context_root_ids($entry) ?: [0];
            foreach ($roots as $rootId) {
                $rows[$surface . "\0" . $uuid . "\0" . (int) $rootId] = [
                    'kind' => $surface,
                    'uuid' => $uuid,
                    'id' => (int) ($entry['id'] ?? 0),
                    'root_id' => (int) $rootId,
                    'old_parent_id' => (int) ($entry['old_parent_id'] ?? $entry['parent_id'] ?? 0),
                    'new_parent_id' => (int) ($entry['new_parent_id'] ?? 0),
                ];
            }
        }
        $out = array_values($rows);
        usort($out, static fn(array $a, array $b): int =>
            strcmp($a['kind'], $b['kind'])
            ?: strcmp($a['uuid'], $b['uuid'])
            ?: ($a['root_id'] <=> $b['root_id']));
        return $out;
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
        $out = [];
        foreach (Ledger::kv_prefix(self::REGEN_REPARENT_CONTEXT_PREFIX) as $key => $encoded) {
            $context = is_string($encoded) ? json_decode($encoded, true) : null;
            if (!is_array($context)) {
                continue;
            }
            $postType = (string) ($context['post_type'] ?? '');
            $id = (int) ($context['id'] ?? 0);
            if ($postType === '' || $id <= 0) {
                continue;
            }
            if (!isset($context['uuid']) || (string) $context['uuid'] === '') {
                $context['uuid'] = substr((string) $key, strlen(self::REGEN_REPARENT_CONTEXT_PREFIX));
            }
            if (!isset($context['kind']) || (string) $context['kind'] === '') {
                $context['kind'] = 'reparent';
            }
            $context['_marker_key'] = (string) $key;
            $out[] = $context;
        }
        return $out;
    }

    /**
     * The ledger-minted target-local id for one compiled entity, or null when
     * that entity kind has no single row id (an options document, a user-meta
     * sidecar). Deliberately a ledger lookup rather than a target query: the
     * ledger is the identity truth apply itself just wrote through, so this
     * cannot resurrect an id for a row phase 1 failed to create.
     */
    private function entity_local_id(array $entity, string $uuid): ?int {
        $type = (string) ($entity['type'] ?? '');
        if ($type === 'post') {
            return Ledger::id_for($uuid, Ledger::KIND_POST);
        }
        if ($type === 'term' || $type === 'menu') {
            return Ledger::id_for($uuid, Ledger::KIND_TERM);
        }
        $table = $this->snapshotRowTables()[$type] ?? null;
        if (is_array($table) && is_string($table['id_kind'] ?? null)) {
            return Ledger::id_for($uuid, $table['id_kind']);
        }
        return null;
    }

    /**
     * The one work/deletion projection every rebuild consumer must share.
     *
     * A plan needs it to diagnose only provider actions it would select;
     * run() needs the same rows for its pre-mutation negotiation and actual
     * rebuild. Keeping retry tombstones, forced conflicts, and sort order in
     * one helper prevents status from diagnosing a different action set from
     * the one Apply can later invoke.
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
        $deleteWork = (array) ($plan['delete'] ?? []);
        if (!empty($opts['force_theirs'])) {
            $deleteWork = array_merge($deleteWork, (array) ($plan['delete_conflict'] ?? []));
        }
        usort($deleteWork, fn(array $a, array $b): int =>
            $this->deletion_rank($b) <=> $this->deletion_rank($a)
            ?: ($a['uuid'] <=> $b['uuid'])
        );

        // A previous apply can have committed authored rows and failed after
        // a tombstone target was already absent. Include those immutable
        // tombstones in the retry surface set so bounded derived-state
        // actions still clear/verify their rows on the next attempt.
        $rebuildDeleteWork = $deleteWork;
        if ($retryingIncompleteApply) {
            $rebuildDeleteWork = array_merge($rebuildDeleteWork, (array) ($plan['deleted'] ?? []));
            usort($rebuildDeleteWork, fn(array $a, array $b): int =>
                $this->deletion_rank($b) <=> $this->deletion_rank($a)
                ?: ((string) ($a['uuid'] ?? '') <=> (string) ($b['uuid'] ?? ''))
            );
        }

        // Deterministic, declared ordering for BOTH phases: 'early' post
        // types (definition CPTs) lead. This is also the exact authored work
        // set whose canonical surfaces may select a provider action.
        $work = array_merge(
            (array) ($plan['create'] ?? []),
            (array) ($plan['adopt'] ?? []),
            (array) ($plan['update'] ?? []),
            array_map(fn(array $row): array => $row, (array) ($plan['conflict'] ?? []))
        );
        if ($includeScopedPromotionDrift) {
            // A normal apply leaves environment-only drift for capture. The
            // externally checkpointed scoped-promotion profile is the one
            // reviewed exception: its held all-writer exclusion and encrypted
            // before-image authorize replacing selected drift with the frozen
            // repository state. Keep this opt-in at the shared projection so
            // plan diagnostics and ordinary/scoped apply cannot accidentally
            // widen their mutation set.
            $work = array_merge($work, (array) ($plan['drift'] ?? []));
        }
        usort($work, fn(array $x, array $y): int =>
            $this->phase2_rank($tree[(string) $x['uuid']]) <=> $this->phase2_rank($tree[(string) $y['uuid']])
        );

        return [
            'work' => $work,
            'delete_work' => $deleteWork,
            'rebuild_delete_work' => $rebuildDeleteWork,
        ];
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
        $work = [];
        foreach ((array) ($selection['work_items'] ?? []) as $item) {
            $resolved = $treeByHash[(string) ($item['identity_hash'] ?? '')] ?? null;
            if (!is_array($resolved)) {
                throw new \RuntimeException('duo: scoped recovery work identity is absent from the frozen artifact');
            }
            [$identity, $entity] = $resolved;
            if (!hash_equals((string) ($item['type'] ?? ''), (string) ($entity['type'] ?? ''))
                || !hash_equals((string) ($item['desired_hash'] ?? ''), $this->verification_hash($entity))) {
                throw new \RuntimeException('duo: scoped recovery work identity no longer matches its authority');
            }
            $work[] = $planRows[$identity] ?? [
                'uuid' => $identity,
                'type' => (string) ($entity['type'] ?? ''),
                'path' => (string) ($entity['path'] ?? ''),
                'retry' => true,
            ];
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
    private const REGEN_PENDING_PREFIX = 'regen_pending:';

    private function regen_dependencies(array $work, array $tree, array $deleteContext = []): void {
        global $wpdb;

        // Batch/refresh dependencies are an explicit opt-in.  They run
        // before the legacy path so a batch adapter can reconcile an existing
        // (but stale) row even when the generic existence check would have
        // skipped it.  TEC and every pre-batch manifest continue through the
        // single-id path below unchanged.
        $this->regen_batch_dependencies($work, $tree, $deleteContext);

        $candidates = []; // uuid => post_type
        foreach ($work as $r) {
            $e = $tree[$r['uuid']] ?? null;
            if ($e === null || $e['type'] !== 'post') {
                continue;
            }
            $postType = (string) ($e['data']['type'] ?? '');
            if ($postType !== '' && $this->policy->regen_dependency($postType) !== null
                && $this->policy->regen_batch($postType) === null) {
                $candidates[$r['uuid']] = $postType;
            }
        }
        foreach (Ledger::kv_prefix(self::REGEN_PENDING_PREFIX) as $k => $postType) {
            $uuid = substr($k, strlen(self::REGEN_PENDING_PREFIX));
            if (isset($candidates[$uuid])) {
                continue; // already covered by $work above
            }
            if ($this->policy->regen_dependency($postType) !== null
                && $this->policy->regen_batch($postType) === null) {
                $candidates[$uuid] = $postType;
                continue;
            }
            if ($this->policy->regen_batch($postType) !== null) {
                continue; // batch path owns this marker
            }
            if ($this->selection_declares_entity_batch_for('post:' . $postType)) {
                // DUO-3342: the provider dispatch owns this marker. It arms
                // regen_pending for the entities it delivers and clears them
                // only on a verified receipt, so a marker armed by a failed
                // provider invocation reaches this loop with no
                // regen_dependency declared for its post type at all — the
                // exact shape the sweep below was written to remove. Sweeping
                // it here would delete the retry evidence between the failure
                // and the retry it exists for.
                continue;
            }
            // Manifest no longer declares this post type's dependency
            // (unpinned, or the declaration was removed) — nothing safe to
            // verify or regenerate against. DUO-3234 design review,
            // addition 2: a marker like this would otherwise sit in duo_kv
            // forever with nothing ever consulting it again — sweep it
            // here, in the same pass that would otherwise have processed
            // it, and say so loudly (an operator auditing duo_kv later has
            // no other way to learn a marker silently vanished, or why).
            Ledger::kv_delete($k);
            $this->warnings[] = "regen_pending marker for post $uuid (type '$postType') dropped: "
                . "manifest no longer declares a regen_dependency for post type '$postType'";
        }
        if (!$candidates) {
            return;
        }

        $regenerators = $this->policy->regenerators();
        foreach ($candidates as $uuid => $postType) {
            $localId = Ledger::id_for($uuid, Ledger::KIND_POST);
            if ($localId === null) {
                // A $work-sourced candidate was just applied and always
                // resolves; one sourced ONLY from a regen_pending marker
                // (the common retry case this mechanism exists for) may
                // not — the post was deleted since the marker was set, or
                // the marker outlived an apply that never actually created
                // it. Either way nothing is live to verify against, and
                // (DUO-3234 design review, addition 2) a marker for a uuid
                // that will never resolve again must not sit forever —
                // sweep it, loudly, but only if a marker for it actually
                // exists (a $work-sourced candidate with no local id would
                // be a different, more serious bug, not an orphan marker).
                $markerKey = self::REGEN_PENDING_PREFIX . $uuid;
                if (Ledger::kv_get($markerKey) !== null) {
                    Ledger::kv_delete($markerKey);
                    $this->warnings[] = "regen_pending marker for post $uuid (type '$postType') dropped: "
                        . 'uuid no longer resolves to a local post id';
                }
                continue;
            }
            $decl = $this->policy->regen_dependency($postType);
            $verify = $decl['verify'];
            $markerKey = self::REGEN_PENDING_PREFIX . $uuid;

            if ($this->regen_verify_exists($verify, $localId)) {
                Ledger::kv_delete($markerKey); // self-heals a marker left over from a since-resolved failure
                continue;
            }

            $regenName = (string) $decl['regenerator'];
            $regenerator = $regenerators[$regenName]
                ?? throw new \RuntimeException(
                    "duo: post type '$postType' declares regen_dependency.regenerator='$regenName' "
                    . 'but it did not load (see Policy::regenerators())'
                );
            try {
                $regenerator->regenerate($localId);
            } catch (\Throwable $t) {
                Ledger::kv_set($markerKey, $postType);
                throw new \RuntimeException(
                    "duo: regenerator '$regenName' failed for post $localId (uuid $uuid, type '$postType'): "
                    . $t->getMessage(),
                    0, $t
                );
            }

            if (!$this->regen_verify_exists($verify, $localId)) {
                Ledger::kv_set($markerKey, $postType);
                throw new \RuntimeException(
                    "duo: regen_dependency verification failed for post $localId (uuid $uuid, type '$postType') — "
                    . "expected a row in {$verify['table']} where {$verify['column']} = $localId after calling "
                    . "regenerator '$regenName', found none. Re-running apply will retry (a regen_pending marker "
                    . 'was recorded), but the underlying regeneration mechanism needs investigation.'
                );
            }
            Ledger::kv_delete($markerKey);
        }
    }

    /**
     * Execute opt-in manifest batch regenerators.  The generic engine owns
     * candidate discovery, marker/retry bookkeeping, and the small existence
     * verification contract; the manifest adapter owns plugin-specific exact
     * value verification and root/deletion semantics.
     *
     * A batch declaration with always_on_write=true receives every write
     * candidate of its declared type, even when its verification row already
     * exists. Candidates are this apply's authored work, pending retry
     * markers, and durable deletion receipts; an unrelated/no-op apply does
     * not scan the catalog or load the plugin. Deletion context is captured
     * before the raw delete and remains available until the rebuild pass
     * succeeds.
     */
    private function regen_batch_dependencies(array $work, array $tree, array $deleteContext): void {
        $jobs = []; // regenerator => {ids, id_types, uuids, deletions, post_types}
        $batchTypes = $this->policy->regen_batch_post_types();

        $ensureJob = function (string $postType, ?int $id = null, ?string $uuid = null) use (&$jobs): void {
            $decl = $this->policy->regen_dependency($postType);
            $batch = $this->policy->regen_batch($postType);
            if ($decl === null || $batch === null || empty($batch['enabled'])) {
                return;
            }
            $name = (string) ($decl['regenerator'] ?? '');
            if ($name === '') {
                return; // Policy validation names malformed declarations.
            }
            if (!isset($jobs[$name])) {
                $jobs[$name] = [
                    'ids' => [],
                    'id_types' => [],
                    'uuids' => [],
                    'deletions' => [],
                    'post_types' => [],
                ];
            }
            $jobs[$name]['post_types'][$postType] = true;
            if ($id !== null && $id > 0) {
                $jobs[$name]['ids'][$id] = $id;
                $jobs[$name]['id_types'][$id] = $postType;
            }
            if ($uuid !== null && $uuid !== '') {
                $jobs[$name]['uuids'][$uuid] = $postType;
            }
        };

        foreach ($work as $entry) {
            $entity = $tree[$entry['uuid']] ?? null;
            if (($entity['type'] ?? '') !== 'post') {
                continue;
            }
            $postType = (string) ($entity['data']['type'] ?? '');
            if (!isset($batchTypes[$postType])) {
                continue;
            }
            $id = Ledger::id_for((string) $entry['uuid'], Ledger::KIND_POST);
            $batch = $batchTypes[$postType];
            if (empty($batch['always_on_write']) && $id !== null) {
                $decl = $this->policy->regen_dependency($postType);
                if ($decl !== null && $this->regen_verify_exists($decl['verify'], $id)) {
                    // A conditional batch declaration retains the original
                    // existence-gated behavior for a changed work item.
                    // Pending markers and deletion receipts below remain
                    // unconditional retries/cleanup candidates.
                    continue;
                }
            }
            $ensureJob($postType, $id, (string) $entry['uuid']);
        }

        // A marker can outlive the content hash and is deliberately a
        // candidate in its own right.  It is also how a failed batch retries
        // naturally when apply_in_progress is no longer the only signal.
        foreach (Ledger::kv_prefix(self::REGEN_PENDING_PREFIX) as $key => $postType) {
            $postType = (string) $postType;
            if (!isset($batchTypes[$postType])) {
                continue;
            }
            $uuid = substr((string) $key, strlen(self::REGEN_PENDING_PREFIX));
            $id = Ledger::id_for($uuid, Ledger::KIND_POST);
            if ($id === null) {
                Ledger::kv_delete((string) $key);
                $this->warnings[] = "regen_pending marker for post $uuid (type '$postType') dropped: "
                    . 'uuid no longer resolves to a local post id';
                continue;
            }
            $ensureJob($postType, $id, $uuid);
        }

        // Durable derived-state contexts are candidates in their own right.  A
        // previous apply can have committed the raw delete and failed before
        // this rebuild, so the retry must not depend on the current plan still
        // carrying the tombstone.  This is a small marker scan, never a
        // mapped-post/catalog scan.  Drop malformed or now-nonbatch receipts
        // rather than replaying them through an unrelated plugin path.
        $durableContext = [];
        foreach ([
            self::REGEN_DELETE_CONTEXT_PREFIX,
            self::REGEN_REPARENT_CONTEXT_PREFIX,
        ] as $contextPrefix) {
            $channel = $contextPrefix === self::REGEN_REPARENT_CONTEXT_PREFIX ? 'reparents' : 'deletions';
            foreach (Ledger::kv_prefix($contextPrefix) as $key => $encoded) {
                $context = is_string($encoded) ? json_decode($encoded, true) : null;
                $postType = is_array($context) ? (string) ($context['post_type'] ?? '') : '';
                $id = is_array($context) ? (int) ($context['id'] ?? 0) : 0;
                $label = $contextPrefix === self::REGEN_REPARENT_CONTEXT_PREFIX
                    ? 'regen_reparent_context'
                    : 'regen_delete_context';
                $markerUuid = is_array($context) && (string) ($context['uuid'] ?? '') !== ''
                    ? (string) $context['uuid']
                    : substr((string) $key, strlen($contextPrefix));
                if ($postType === '' || $id <= 0) {
                    // Malformed either way: no dispatcher can replay a receipt
                    // with no post type or no captured id. Said out loud for
                    // the same reason the pending-marker sweep below says it:
                    // an operator auditing duo_kv has no other way to learn a
                    // durable receipt vanished, or why.
                    Ledger::kv_delete((string) $key);
                    $this->warnings[] = "$label marker for post $markerUuid dropped: the stored receipt "
                        . 'carries no post type or no captured local id, so no dispatcher can replay it';
                    continue;
                }
                $surface = 'post:' . $postType;
                if ($this->policy->regen_batch($postType) === null) {
                    if ($this->selection_declares_channel_for($channel, $surface)) {
                        // DUO-3342: owned by the provider dispatch, which
                        // delivers this marker's row on its matching channel
                        // and deletes it only after a verified receipt. This
                        // pass is not its owner and must not sweep it — doing
                        // so is what made the channel a one-shot read rather
                        // than a durable retry queue.
                        continue;
                    }
                    if (!$this->selection_triggers_provider_action_for($surface)
                        && $this->pinned_provider_action_owns($surface)) {
                        // Independent review, F1: the check above asks whether
                        // the owner is running, which an apply that touched
                        // nothing on this surface answers "no" for a marker
                        // that is perfectly valid — silently deleting the retry
                        // evidence a later apply owes work against. The sweep
                        // is therefore run-INDEPENDENT, exactly like the
                        // regen_batch() test one line up: a pinned provider
                        // action claiming this surface keeps the marker when
                        // this run selected nothing there. When the selection
                        // DOES reach the surface and no negotiated capability
                        // wanted the channel, the first branch already fell
                        // through and the sweep below is the right answer —
                        // that is a live claim about consumers, not silence.
                        continue;
                    }
                    Ledger::kv_delete((string) $key);
                    $this->warnings[] = "$label marker for post $markerUuid (type '$postType') dropped: "
                        . "post type '$postType' declares no batch regen_dependency, and no capability "
                        . "consuming the '$channel' channel claims $surface";
                    continue;
                }
                if (!isset($context['uuid']) || (string) $context['uuid'] === '') {
                    $context['uuid'] = substr((string) $key, strlen($contextPrefix));
                }
                if (!isset($context['kind']) || (string) $context['kind'] === '') {
                    $context['kind'] = $contextPrefix === self::REGEN_REPARENT_CONTEXT_PREFIX
                        ? 'reparent'
                        : 'delete';
                }
                $context['_marker_key'] = (string) $key;
                $durableContext[] = $context;
            }
        }

        // Deletion/reparent contexts are supplied even when there are no live
        // ids in this revision (for example, deleting the last declared parent).
        // Dedupe by kind+UUID+id so a tombstone and a retry receipt cannot
        // cause duplicate plugin calls.
        foreach (array_merge($durableContext, $deleteContext) as $context) {
            $postType = (string) ($context['post_type'] ?? '');
            if (!isset($batchTypes[$postType])) {
                continue;
            }
            $decl = $this->policy->regen_dependency($postType);
            $name = (string) ($decl['regenerator'] ?? '');
            if ($name === '') {
                continue;
            }
            $kind = (string) ($context['kind'] ?? 'delete');
            if ($kind === 'reparent') {
                // A marker-only retry still needs the live child id so a
                // plugin adapter can discover the new root after the old
                // parent receipt was captured. If its ledger mapping has
                // already disappeared, retain old/new context without
                // inventing a live id.
                $uuid = (string) ($context['uuid'] ?? '');
                $liveId = $uuid !== '' ? Ledger::id_for($uuid, Ledger::KIND_POST) : null;
                $ensureJob($postType, $liveId, $uuid !== '' ? $uuid : null);
            } else {
                $ensureJob($postType);
            }
            $identity = (string) ($context['kind'] ?? 'delete') . ':'
                . (string) ($context['uuid'] ?? '') . ':' . (int) ($context['id'] ?? 0);
            if (isset($jobs[$name]['deletions'][$identity])) {
                $context = $this->merge_regen_contexts(
                    $jobs[$name]['deletions'][$identity],
                    $context
                );
            }
            $jobs[$name]['deletions'][$identity] = $context;
        }

        if (!$jobs) {
            return;
        }

        $regenerators = $this->policy->regenerators();
        foreach ($jobs as $name => $job) {
            $ids = array_values(array_map('intval', $job['ids']));
            $deletions = array_values($job['deletions']);
            // A failed reparent can be followed by a tombstone before the
            // retry. The stale Ledger mapping is intentionally retained until
            // this rebuild succeeds, so pending/reparent discovery may still
            // put the deleted id in $job['ids']. Never hand that id to an
            // adapter as live work: it must observe the deletion context
            // instead. Keep all reparent roots and delete
            // cleanup contexts in the same call.
            $deletedIds = [];
            $deletedUuids = [];
            foreach ($deletions as $context) {
                if (($context['kind'] ?? 'delete') === 'reparent') {
                    continue;
                }
                $id = (int) ($context['id'] ?? 0);
                if ($id > 0) {
                    $deletedIds[$id] = true;
                }
                foreach ((array) ($context['child_ids'] ?? []) as $childId) {
                    $childId = (int) $childId;
                    if ($childId > 0) {
                        $deletedIds[$childId] = true;
                    }
                }
                $uuid = (string) ($context['uuid'] ?? '');
                $postType = (string) ($context['post_type'] ?? '');
                if ($uuid !== '' && $postType !== '') {
                    $deletedUuids[$uuid] = $postType;
                }
            }
            if ($deletedIds) {
                $ids = array_values(array_filter(
                    $ids,
                    static fn(int $id): bool => !isset($deletedIds[$id])
                ));
            }
            $idTypes = $job['id_types'];
            foreach (array_keys($deletedIds) as $deletedId) {
                unset($idTypes[$deletedId]);
            }
            if (!$ids && !$deletions) {
                continue;
            }
            $regenerator = $regenerators[$name]
                ?? throw new \RuntimeException(
                    "duo: batch regen_dependency regenerator '$name' did not load (see Policy::regenerators())"
                );
            $markers = array_fill_keys(array_keys($job['uuids']), true);
            $markFailure = function (\Throwable $t) use ($markers, $job, $name): void {
                foreach ($markers as $uuid => $_) {
                    $postType = (string) ($job['uuids'][$uuid] ?? '');
                    if ($postType !== '') {
                        Ledger::kv_set(self::REGEN_PENDING_PREFIX . $uuid, $postType);
                    }
                }
                throw new \RuntimeException(
                    "duo: batch regenerator '$name' failed: " . $t->getMessage(),
                    0,
                    $t
                );
            };

            if (!method_exists($regenerator, 'regenerate_batch')) {
                $markFailure(new \RuntimeException(
                    "regenerator '$name' opted into batch regeneration but does not define "
                    . 'regenerate_batch(array $liveIds, array $deletionContext, ?callable $heartbeat = null): void'
                ));
            }

            // Renew before and after the opaque plugin call. Newer adapters
            // may also call this callback during their own long live-id,
            // root, deletion, or verification loops; older two-argument
            // adapters remain source-compatible.
            $heartbeat = function (): void {
                // A production Apply instance always has the lease identity
                // installed by run().  Keeping the empty-identity seam a
                // no-op makes this private dispatch contract executable in
                // offline fakes without weakening the live lease path.
                if ($this->promotionOwner !== '' && $this->promotionArtifact !== '') {
                    $this->renew_promotion_lock('apply-rebuild-regen-batch');
                }
            };
            try {
                $heartbeat();
                $batchMethod = new \ReflectionMethod($regenerator, 'regenerate_batch');
                if ($batchMethod->isVariadic() || $batchMethod->getNumberOfParameters() >= 3) {
                    $regenerator->regenerate_batch($ids, $deletions, $heartbeat);
                } else {
                    $regenerator->regenerate_batch($ids, $deletions);
                }
                $heartbeat();
            } catch (\Throwable $t) {
                $markFailure($t);
            }

            // Keep the old declarative verification as a cheap generic
            // safety net. Adapters additionally check their own exact values
            // and exact absence.
            foreach ($idTypes as $id => $postType) {
                $heartbeat();
                $decl = $this->policy->regen_dependency((string) $postType);
                if ($decl === null || !$this->regen_verify_exists($decl['verify'], (int) $id)) {
                    $uuid = Ledger::uuid_for((int) $id, Ledger::KIND_POST);
                    if ($uuid !== null) {
                        Ledger::kv_set(self::REGEN_PENDING_PREFIX . $uuid, (string) $postType);
                    }
                    throw new \RuntimeException(
                        "duo: batch regen_dependency verification failed for post " . (int) $id
                        . " (type '$postType') — expected a row in {$decl['verify']['table']} where "
                        . "{$decl['verify']['column']} = " . (int) $id
                    );
                }
                $uuid = Ledger::uuid_for((int) $id, Ledger::KIND_POST);
                if ($uuid !== null) {
                    Ledger::kv_delete(self::REGEN_PENDING_PREFIX . $uuid);
                }
            }
            foreach ($deletions as $context) {
                $heartbeat();
                $uuid = (string) ($context['uuid'] ?? '');
                if ($uuid !== '') {
                    $markerKey = (string) ($context['_marker_key'] ?? '');
                    if ($markerKey === '') {
                        $markerKey = (($context['kind'] ?? 'delete') === 'reparent'
                            ? self::REGEN_REPARENT_CONTEXT_PREFIX
                            : self::REGEN_DELETE_CONTEXT_PREFIX) . $uuid;
                    }
                    Ledger::kv_delete($markerKey);
                }
            }
            // A deleted UUID may still have a pending marker because its
            // stale Ledger mapping was needed to discover the reparent job.
            // The adapter's successful exact deletion verification is now the
            // convergence boundary for that marker too; retain it on any
            // failure so the next retry repeats both cleanup and roots.
            foreach ($deletedUuids as $uuid => $_postType) {
                Ledger::kv_delete(self::REGEN_PENDING_PREFIX . $uuid);
            }
        }
    }

    /** Declarative existence check ({table, column} only — no plugin
     *  knowledge needed), matching `invalidate`'s own precedent for the
     *  purely-mechanical half of a typed-snapshot declaration. A missing
     *  table is treated as "not satisfied," not skipped — a manifest
     *  declaring a verify table this environment doesn't have is a real
     *  configuration problem the loud failure above should surface, not a
     *  silent pass. */
    private function regen_verify_exists(array $verify, int $localId): bool {
        global $wpdb;
        $table = preg_replace('/[^A-Za-z0-9_]/', '', (string) $verify['table']);
        $col = preg_replace('/[^A-Za-z0-9_]/', '', (string) $verify['column']);
        $prefixed = $wpdb->prefix . $table;
        if (!$this->regen_checked_get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $prefixed),
            "verify table $table"
        )) {
            return false;
        }
        return (bool) $this->regen_checked_get_var(
            $wpdb->prepare("SELECT 1 FROM `$prefixed` WHERE `$col` = %d LIMIT 1", $localId),
            "verify row in $table"
        );
    }
}
