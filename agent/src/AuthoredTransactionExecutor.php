<?php
namespace Duo;

require_once __DIR__ . '/ApplyPlanner.php';
require_once __DIR__ . '/DeleteExecutor.php';
require_once __DIR__ . '/EntityAdopter.php';
require_once __DIR__ . '/MenuMaterializer.php';
require_once __DIR__ . '/OptionsMaterializer.php';
require_once __DIR__ . '/PostMaterializer.php';
require_once __DIR__ . '/ProviderActionBatchBuilder.php';
require_once __DIR__ . '/RegenerationContextStore.php';
require_once __DIR__ . '/ScopedApply.php';
require_once __DIR__ . '/SidebarState.php';
require_once __DIR__ . '/TermMaterializer.php';
require_once __DIR__ . '/UserMetaMaterializer.php';
if (!class_exists(Canary::class, false)) {
    require_once __DIR__ . '/Canary.php';
}
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/Db.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/Ledger.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/Policy.php';
}
if (!class_exists(Snapshot::class, false)) {
    require_once __DIR__ . '/Snapshot.php';
}
if (!class_exists(Tokens::class, false)) {
    require_once __DIR__ . '/Tokens.php';
}

/**
 * Owns the single authored DB transaction and its invariant ordering:
 * guard locks, context capture, adoption, two-phase materialization, deletes,
 * canary verification, commit, and rollback.
 */
final class AuthoredTransactionExecutor {
    private const REGEN_PENDING_PREFIX = ProviderActionBatchBuilder::REGEN_PENDING_PREFIX;

    /** @var \Closure():array */
    private readonly \Closure $taxonomyOwnership;
    /** @var \Closure(string):void */
    private readonly \Closure $renewLease;
    /** @var \Closure(array,array,array,array,array):void */
    private readonly \Closure $lockDeleteGuards;
    /** @var \Closure(array,array,array,bool,array,array,bool):void */
    private readonly \Closure $recheckDeleteGuard;

    public function __construct(
        private readonly Policy $policy,
        private readonly Tokens $tokens,
        private readonly ApplyPlanner $planner,
        private readonly array $snapshotRowTables,
        private readonly EntityAdopter $adopter,
        private readonly TermMaterializer $termMaterializer,
        private readonly PostMaterializer $postMaterializer,
        private readonly MenuMaterializer $menuMaterializer,
        private readonly OptionsMaterializer $optionsMaterializer,
        private readonly UserMetaMaterializer $userMetaMaterializer,
        private readonly DeleteExecutor $deleteExecutor,
        private readonly RegenerationContextStore $contextStore,
        \Closure $taxonomyOwnership,
        \Closure $renewLease,
        \Closure $lockDeleteGuards,
        \Closure $recheckDeleteGuard
    ) {
        $this->taxonomyOwnership = $taxonomyOwnership;
        $this->renewLease = $renewLease;
        $this->lockDeleteGuards = $lockDeleteGuards;
        $this->recheckDeleteGuard = $recheckDeleteGuard;
    }

    /** @return array{attachment_ids:list<int|null>,regen_context:list<array<string,mixed>>} */
    public function execute(
        array $plan,
        array $tree,
        array $work,
        array $deleteWork,
        array $deleteUuids,
        array $guardRepairUuids,
        array $compiledDeletions,
        bool $executeDeletes,
        bool $scoped,
        ?array $scopeContract,
        bool $withDeletes,
        bool $forceDeleteReferenced,
        bool $performTransaction,
        ?int $defaultAuthor,
        array &$warnings
    ): array {
        $attachmentIds = [];
        $regenContext = [];
        if ($scoped && !$performTransaction) {
            return ['attachment_ids' => $attachmentIds, 'regen_context' => $regenContext];
        }

        $transactionStarted = false;
        Canary::arm();
        try {
            Db::start('apply transaction start');
            $transactionStarted = true;

            if ($executeDeletes && $deleteWork) {
                ($this->lockDeleteGuards)(
                    $deleteWork,
                    $deleteUuids,
                    $compiledDeletions,
                    $tree,
                    $guardRepairUuids
                );
            }

            $regenContext = $this->contextStore->capture_reparents($work, $tree, !$scoped);

            foreach ($plan['adopt'] as $row) {
                ($this->renewLease)('apply-adopt');
                $this->adopter->adopt($row, $tree[$row['uuid']], $warnings);
            }

            $sidebarAllocationTree = $scoped && $scopeContract !== null
                ? array_intersect_key($tree, ScopedApply::selected_set($scopeContract))
                : $tree;
            foreach ($work as $row) {
                if (($tree[$row['uuid']]['type'] ?? '') === SidebarState::ENTITY_TYPE) {
                    SidebarState::ensure_widgets($this->policy, $sidebarAllocationTree);
                    break;
                }
            }

            foreach ($work as $row) {
                ($this->renewLease)('apply-phase-1');
                $entity = $tree[$row['uuid']];
                if (isset($this->snapshotRowTables[$entity['type']])) {
                    Snapshot::ensure_row($this->policy, $entity);
                } elseif ($entity['type'] === 'term') {
                    $this->termMaterializer->ensure_term_row($entity['data'], 'term');
                } elseif ($entity['type'] === 'menu') {
                    $front = $entity['data'];
                    $this->termMaterializer->ensure_term_row([
                        'uuid' => $front['uuid'],
                        'taxonomy' => 'nav_menu',
                        'name' => $front['name'],
                        'slug' => $front['slug'],
                        'description' => '',
                        'parent' => null,
                    ], 'menu');
                } elseif ($entity['type'] === 'post') {
                    $front = $entity['data'];
                    $this->postMaterializer->ensure_post_row($front);
                    if ($front['type'] === 'attachment') {
                        $attachmentIds[] = Ledger::id_for($front['uuid'], Ledger::KIND_POST);
                    }
                }
            }

            $phase2 = $work;
            usort($phase2, fn(array $left, array $right): int =>
                $this->planner->phase2_rank($tree[$left['uuid']])
                    <=> $this->planner->phase2_rank($tree[$right['uuid']])
            );
            foreach ($phase2 as $row) {
                ($this->renewLease)('apply-phase-2');
                $entity = $tree[$row['uuid']];
                if (isset($this->snapshotRowTables[$entity['type']])) {
                    Snapshot::finalize_row($this->policy, $this->tokens, $entity);
                } elseif ($entity['type'] === 'term') {
                    $ownership = ($this->taxonomyOwnership)();
                    $this->termMaterializer->finalize_term(
                        $entity['data'],
                        (array) ($ownership['term_object'] ?? [])
                    );
                } elseif ($entity['type'] === 'post') {
                    $ownership = ($this->taxonomyOwnership)();
                    $postType = (string) ($entity['data']['type'] ?? '');
                    $this->postMaterializer->finalize_post(
                        $entity['data'],
                        $entity['body'],
                        $defaultAuthor,
                        $warnings,
                        (array) ($ownership['by_post_type'][$postType] ?? [])
                    );
                } elseif ($entity['type'] === 'menu') {
                    $this->menuMaterializer->finalize_menu($entity['data']);
                } elseif ($entity['type'] === 'options') {
                    $document = $scoped && $scopeContract !== null
                        && ScopedApply::has_record_scoped_options($scopeContract)
                        ? ScopedApply::selected_option_document($entity['data'], $scopeContract, $row)
                        : $entity['data'];
                    $classificationDocument = $scoped && $scopeContract !== null
                        && ScopedApply::has_record_scoped_options($scopeContract)
                        ? $entity['data']
                        : null;
                    $this->optionsMaterializer->apply_options(
                        $document,
                        $withDeletes,
                        $warnings,
                        $classificationDocument
                    );
                } elseif ($entity['type'] === 'user-meta') {
                    $this->userMetaMaterializer->finalize_user_meta($entity['data']);
                } elseif ($entity['type'] === SidebarState::ENTITY_TYPE) {
                    $sidebar = SidebarState::sidebar_from_path((string) $entity['path']);
                    if ($sidebar === null) {
                        throw new \RuntimeException("duo: invalid compiled sidebar path {$entity['path']}");
                    }
                    SidebarState::finalize_sidebar(
                        $this->policy,
                        $this->tokens,
                        $entity['data'],
                        $sidebar,
                        $tree,
                        $scoped
                    );
                }
            }

            if ($executeDeletes) {
                foreach ($deleteWork as $row) {
                    ($this->renewLease)('apply-delete');
                    ($this->recheckDeleteGuard)(
                        $row,
                        $deleteUuids,
                        $compiledDeletions,
                        $forceDeleteReferenced,
                        $tree,
                        $guardRepairUuids,
                        true
                    );
                }
                $regenContext = array_merge(
                    $regenContext,
                    $this->contextStore->capture_deletions(
                        array_merge($deleteWork, $plan['deleted']),
                        !$scoped
                    )
                );
                foreach ($deleteWork as $row) {
                    ($this->renewLease)('apply-delete');
                    $this->deleteExecutor->delete_entity(
                        $row['uuid'],
                        $row['type'],
                        $this->snapshotRowTables,
                        $warnings
                    );
                    if ($row['type'] === 'post' && !$scoped) {
                        Ledger::kv_delete(self::REGEN_PENDING_PREFIX . $row['uuid']);
                    }
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
        } catch (\Throwable $failure) {
            if ($transactionStarted) {
                try {
                    Db::rollback('apply transaction rollback');
                } catch (DatabaseMutationException $rollback) {
                    Canary::disarm();
                    throw new DatabaseMutationException($rollback->mutationContext, $failure);
                }
            }
            Canary::disarm();
            throw $failure;
        }
        Canary::disarm();
        return ['attachment_ids' => $attachmentIds, 'regen_context' => $regenContext];
    }
}
