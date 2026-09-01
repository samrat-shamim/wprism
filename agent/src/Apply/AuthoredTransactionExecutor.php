<?php
namespace WPrism;

require_once __DIR__ . '/ApplyPlanner.php';
require_once __DIR__ . '/AuthoredTransactionRequest.php';
require_once __DIR__ . '/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../Delete/DeleteExecutor.php';
require_once __DIR__ . '/EntityAdopter.php';
require_once __DIR__ . '/MenuMaterializer.php';
require_once __DIR__ . '/OptionsMaterializer.php';
require_once __DIR__ . '/PostMaterializer.php';
require_once __DIR__ . '/AttachmentMaterializer.php';
require_once __DIR__ . '/../Adapter/ProviderActionBatchBuilder.php';
require_once __DIR__ . '/../Rebuild/RegenerationContextStore.php';
require_once __DIR__ . '/../Scope/ScopedApply.php';
require_once __DIR__ . '/../Repository/SidebarState.php';
require_once __DIR__ . '/TermMaterializer.php';
require_once __DIR__ . '/UserMetaMaterializer.php';
require_once __DIR__ . '/CacheInvalidationTransaction.php';
require_once __DIR__ . '/../Delete/DeleteGuardEvaluator.php';
if (!class_exists(Canary::class, false)) {
    require_once __DIR__ . '/../Kernel/Canary.php';
}
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(Snapshot::class, false)) {
    require_once __DIR__ . '/../Repository/Snapshot.php';
}
if (!class_exists(Tokens::class, false)) {
    require_once __DIR__ . '/../Grammar/Tokens.php';
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
    /** @var \Closure(array,array,array,bool,array,array,bool):(\Closure():void) */
    private readonly \Closure $recheckDeleteGuard;
    /** @var \Closure():void */
    private readonly \Closure $verifyDeleteCommit;
    /** @var \Closure():void */
    private readonly \Closure $endDeleteTransaction;

    public function __construct(
        private readonly Policy $policy,
        private readonly Tokens $tokens,
        private readonly ApplyPlanner $planner,
        private readonly array $snapshotRowTables,
        private readonly ApplyFieldMaterializer $fieldMaterializer,
        private readonly EntityAdopter $adopter,
        private readonly TermMaterializer $termMaterializer,
        private readonly PostMaterializer $postMaterializer,
        private readonly AttachmentMaterializer $attachmentMaterializer,
        private readonly MenuMaterializer $menuMaterializer,
        private readonly OptionsMaterializer $optionsMaterializer,
        private readonly UserMetaMaterializer $userMetaMaterializer,
        private readonly DeleteExecutor $deleteExecutor,
        private readonly RegenerationContextStore $contextStore,
        \Closure $taxonomyOwnership,
        \Closure $renewLease,
        \Closure $lockDeleteGuards,
        \Closure $recheckDeleteGuard,
        \Closure $verifyDeleteCommit,
        \Closure $endDeleteTransaction
    ) {
        $this->taxonomyOwnership = $taxonomyOwnership;
        $this->renewLease = $renewLease;
        $this->lockDeleteGuards = $lockDeleteGuards;
        $this->recheckDeleteGuard = $recheckDeleteGuard;
        $this->verifyDeleteCommit = $verifyDeleteCommit;
        $this->endDeleteTransaction = $endDeleteTransaction;
    }

    /** @return array{attachment_ids:list<int|null>,regen_context:list<array<string,mixed>>} */
    public function execute(
        AuthoredTransactionRequest $request,
        array &$warnings
    ): array {
        $plan = $request->workset->plan;
        $tree = $request->workset->tree;
        $work = $request->workset->work;
        $deleteWork = $request->workset->deleteWork;
        $deleteUuids = $request->workset->deleteUuids;
        $guardRepairUuids = $request->workset->guardRepairUuids;
        $compiledDeletions = $request->workset->compiledDeletions;
        $executeDeletes = $request->deletionAuthority->execute;
        $withDeletes = $request->deletionAuthority->withDeletes;
        $forceDeleteReferenced = $request->deletionAuthority->forceReferenced;
        $scoped = $request->scoped;
        $scopeContract = $request->scopeContract;
        $performTransaction = $request->performTransaction;
        $defaultAuthor = $request->defaultAuthor;
        $commitScopedAuthoring = $request->commitScopedAuthoring;
        $rollbackScopedAuthoring = $request->rollbackScopedAuthoring;
        $requiresScopedParticipant = $scoped && $performTransaction;
        if (($commitScopedAuthoring !== null) !== $requiresScopedParticipant
            || ($rollbackScopedAuthoring !== null) !== $requiresScopedParticipant) {
            throw new \RuntimeException('wprism: authored transaction received an invalid scoped commit participant');
        }
        $attachmentIds = $this->attachmentMaterializer->pending_attachment_ids();
        $regenContext = [];
        if ($scoped && !$performTransaction) {
            return ['attachment_ids' => $attachmentIds, 'regen_context' => $regenContext];
        }

        // Every path below can raw-write state whose WordPress cache group is
        // not transaction-aware. This must be the first gate: refusing later
        // would roll database rows back but could already have leaked cache
        // invalidations/repopulation from adoption, terms, metadata, widgets,
        // options, or deletes. Core's in-process cache dies with this WP-CLI
        // request and is purged again after the outcome; a persistent backend
        // has no common CAS/generation fence across all of these surfaces.
        CacheInvalidationTransaction::assert_local_cache('authored apply');

        $transactionStarted = false;
        $scopedCommitParticipantStarted = false;
        $retainNativeRebuildAuthority = false;
        Canary::arm();
        try {
            $this->attachmentMaterializer->prepare_filesystem($work, $tree);
            Db::start_repeatable_read('apply transaction start');
            $transactionStarted = true;
            $this->fieldMaterializer->begin_authored_transaction();
            DeleteGuardEvaluator::assert_innodb_tables(
                $this->authored_transaction_tables(),
                'authored transaction storage-engine boundary'
            );
            DeleteGuardEvaluator::assert_transaction_isolation(
                'authored transaction storage-engine boundary'
            );
            $this->termMaterializer->begin_authored_transaction();
            $this->optionsMaterializer->begin_authored_transaction();
            CacheInvalidationTransaction::begin();
            SidebarState::begin_authored_transaction(
                static fn(string $name, string $purpose): ?array =>
                    CacheInvalidationTransaction::lock_option_row($name, $purpose),
                static function (string $name, string $purpose): void {
                    CacheInvalidationTransaction::queue_option($name, $purpose);
                },
                static function (string $name, string $value, string $autoload, string $purpose): void {
                    CacheInvalidationTransaction::assert_option_row($name, $value, $autoload, $purpose);
                }
            );
            CacheInvalidationTransaction::prepare_term_hierarchy_options(
                $this->taxonomy_hierarchy_roster($tree, $executeDeletes ? $deleteWork : [])
            );

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
                        throw new \RuntimeException("wprism: invalid compiled sidebar path {$entity['path']}");
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
                $deleteBoundaryAssertions = [];
                foreach ($deleteWork as $deleteIndex => $row) {
                    ($this->renewLease)('apply-delete');
                    $assertDeleteBoundary = ($this->recheckDeleteGuard)(
                        $row,
                        $deleteUuids,
                        $compiledDeletions,
                        $forceDeleteReferenced,
                        $tree,
                        $guardRepairUuids,
                        true
                    );
                    if (!$assertDeleteBoundary instanceof \Closure) {
                        throw new \RuntimeException(
                            'wprism: delete guard recheck did not bind an executable-owner delete boundary'
                        );
                    }
                    $deleteBoundaryAssertions[$deleteIndex] = $assertDeleteBoundary;
                }
                $regenContext = array_merge(
                    $regenContext,
                    $this->contextStore->capture_deletions(
                        array_merge($deleteWork, $plan['deleted']),
                        !$scoped
                    )
                );
                foreach ($deleteWork as $deleteIndex => $row) {
                    ($this->renewLease)('apply-delete');
                    $assertDeleteBoundary = $deleteBoundaryAssertions[$deleteIndex] ?? null;
                    if (!$assertDeleteBoundary instanceof \Closure) {
                        throw new \RuntimeException(
                            'wprism: executable-owner delete boundary is missing for an authorized row'
                        );
                    }
                    $assertDeleteBoundary();
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

            // The durable marker binds the complete registered attachment
            // UUID=>post-ID mapping. Its write belongs to this same authored
            // transaction; filesystem publication cannot start before the
            // commit outcome is confirmed below.
            $this->attachmentMaterializer->seal_authored_transaction();

            $violations = Canary::violations();
            if ($violations) {
                throw new \RuntimeException(
                    "wprism: side-effect canary tripped:\n  - " . implode("\n  - ", $violations)
                );
            }
            DeleteGuardEvaluator::assert_transaction_isolation(
                'authored transaction final commit boundary'
            );
            if ($commitScopedAuthoring !== null) {
                // This participant updates the scoped session row through the
                // same wpdb connection and transaction as authored state. Its
                // one CAS therefore cannot certify a map generation that the
                // authored COMMIT later rolls back (issue #3618).
                $scopedCommitParticipantStarted = true;
                $commitScopedAuthoring();
            }
            if ($executeDeletes && $deleteWork !== []) {
                // This is the last operation before COMMIT. The provider's
                // admission gate spans every authored/scoped participant; a
                // lost or mismatched exclusion is an ordinary transaction
                // failure and runs the complete rollback path below.
                ($this->verifyDeleteCommit)();
            }
            Db::commit('apply transaction commit');
            $transactionStarted = false;
            $postCommitFailures = [];
            foreach ([
                'attachment-filesystem' => fn(): mixed =>
                    $this->attachmentMaterializer->commit_authored_transaction(),
                'options-participant' => fn(): mixed => $this->optionsMaterializer->commit_authored_transaction(),
                'cache-purge' => static fn(): mixed => CacheInvalidationTransaction::finish(),
            ] as $label => $participant) {
                try {
                    $participant();
                } catch (\Throwable $postCommitFailure) {
                    $postCommitFailures[$label] = $postCommitFailure;
                }
            }
            if ($postCommitFailures !== []) {
                $fingerprints = [];
                foreach ($postCommitFailures as $label => $postCommitFailure) {
                    $fingerprints[] = $label . '=' . self::failure_fingerprint($postCommitFailure);
                }
                $firstPostCommitFailure = array_values($postCommitFailures)[0];
                throw new \RuntimeException(
                    'wprism: authored transaction committed but a post-commit participant failed; '
                    . 'recovery_required (' . implode('; ', $fingerprints) . ')',
                    0,
                    $firstPostCommitFailure
                );
            }
            // The attachment marker and preflight witness now belong to the
            // post-COMMIT rebuild coordinator; all failure paths leave them
            // disposable so a retry cannot reuse a stale in-memory attempt.
            $retainNativeRebuildAuthority = true;
        } catch (\Throwable $failure) {
            if (!$transactionStarted) {
                try {
                    $this->attachmentMaterializer->rollback_prepared_filesystem();
                } catch (\Throwable $preparationFailure) {
                    Canary::disarm();
                    throw new \RuntimeException(
                        'wprism: attachment preparation cleanup failed before the authored transaction; recovery_required; '
                        . 'original=' . self::failure_fingerprint($failure)
                        . '; attachment=' . self::failure_fingerprint($preparationFailure),
                        0,
                        $failure
                    );
                }
            }
            if ($transactionStarted) {
                $transactionStateFailure = null;
                $transactionActive = null;
                try {
                    $transactionActive = Db::transaction_active(
                        'authored transaction recovery boundary'
                    );
                } catch (\Throwable $stateFailure) {
                    $transactionStateFailure = $stateFailure;
                }
                if ($transactionStateFailure !== null || $transactionActive !== true) {
                    $cacheFailure = null;
                    try {
                        CacheInvalidationTransaction::finish();
                    } catch (\Throwable $cachePurgeFailure) {
                        $cacheFailure = $cachePurgeFailure;
                    }
                    Canary::disarm();
                    $recovery = [
                        'original=' . self::failure_fingerprint($failure),
                        'transaction-state=' . ($transactionStateFailure !== null
                            ? self::failure_fingerprint($transactionStateFailure)
                            : 'ended-before-rollback'),
                    ];
                    if ($cacheFailure !== null) {
                        $recovery[] = 'cache-purge=' . self::failure_fingerprint($cacheFailure);
                    }
                    throw new \RuntimeException(
                        'wprism: authored transaction ended or changed connection before recovery; '
                        . 'rollback participants were not run through autocommit; recovery_required; '
                        . implode('; ', $recovery),
                        0,
                        $failure
                    );
                }
                $participantFailure = null;
                $rollbackFailure = null;
                $cacheFailure = null;
                try {
                    $this->optionsMaterializer->rollback_authored_transaction();
                } catch (\Throwable $rollbackParticipantFailure) {
                    $participantFailure = $rollbackParticipantFailure;
                }
                try {
                    Db::rollback('apply transaction rollback');
                } catch (\Throwable $rollback) {
                    $rollbackFailure = $rollback;
                }
                $attachmentFailure = null;
                if ($rollbackFailure === null) {
                    try {
                        $this->attachmentMaterializer->rollback_authored_transaction();
                    } catch (\Throwable $attachmentRollbackFailure) {
                        $attachmentFailure = $attachmentRollbackFailure;
                    }
                }
                $scopedSessionFailure = null;
                if ($rollbackFailure === null
                    && $scopedCommitParticipantStarted
                    && $rollbackScopedAuthoring !== null) {
                    try {
                        // The in-memory session adopted the uncommitted CAS.
                        // Reload only after the database rollback is positively
                        // confirmed, never through an uncertain outcome.
                        $rollbackScopedAuthoring();
                    } catch (\Throwable $scopedRollbackFailure) {
                        $scopedSessionFailure = $scopedRollbackFailure;
                    }
                }
                try {
                    CacheInvalidationTransaction::finish();
                } catch (\Throwable $cachePurgeFailure) {
                    $cacheFailure = $cachePurgeFailure;
                }
                if ($participantFailure !== null
                    || $rollbackFailure !== null
                    || $attachmentFailure !== null
                    || $scopedSessionFailure !== null
                    || $cacheFailure !== null) {
                    Canary::disarm();
                    if ($rollbackFailure instanceof DatabaseMutationException
                        && $participantFailure === null
                        && $cacheFailure === null) {
                        throw new DatabaseMutationException($rollbackFailure->mutationContext, $failure);
                    }
                    $recovery = [];
                    foreach ([
                        'participant' => $participantFailure,
                        'database-rollback' => $rollbackFailure,
                        'attachment-filesystem' => $attachmentFailure,
                        'scoped-session' => $scopedSessionFailure,
                        'cache-purge' => $cacheFailure,
                    ] as $label => $recoveryFailure) {
                        if ($recoveryFailure instanceof \Throwable) {
                            $recovery[] = $label . '=' . self::failure_fingerprint($recoveryFailure);
                        }
                    }
                    throw new \RuntimeException(
                        'wprism: authored transaction recovery failed; recovery_required; '
                        . 'original=' . self::failure_fingerprint($failure)
                        . '; ' . implode('; ', $recovery),
                        0,
                        $failure
                    );
                }
            }
            Canary::disarm();
            throw $failure;
        } finally {
            // Metadata locks prove these descriptors only for this authored
            // transaction. A reused ApplyServices graph starts empty after
            // either commit or rollback.
            $this->termMaterializer->end_authored_transaction();
            $this->fieldMaterializer->end_authored_transaction();
            $this->optionsMaterializer->end_authored_transaction();
            $this->attachmentMaterializer->end_authored_transaction($retainNativeRebuildAuthority);
            SidebarState::end_authored_transaction();
            CacheInvalidationTransaction::end();
            ($this->endDeleteTransaction)();
        }
        Canary::disarm();
        return ['attachment_ids' => $attachmentIds, 'regen_context' => $regenContext];
    }

    /**
     * Exact table roster the authored transaction can read-lock or mutate.
     * This intentionally includes users (the owner row for authored usermeta)
     * and declared invalidation tables: proving only wprism_kv/wprism_map would let
     * an ALTER ENGINE race one of the earlier core/plugin mutations while the
     * later atomic scoped receipt still committed successfully.
     *
     * @return list<string>
     */
    private function authored_transaction_tables(): array {
        global $wpdb;
        $tables = [
            $wpdb->posts,
            $wpdb->postmeta,
            $wpdb->terms,
            $wpdb->term_taxonomy,
            $wpdb->term_relationships,
            $wpdb->termmeta,
            $wpdb->options,
            $wpdb->users,
            $wpdb->usermeta,
            $wpdb->prefix . 'wprism_map',
            $wpdb->prefix . 'wprism_state',
            $wpdb->prefix . 'wprism_kv',
        ];
        foreach ($this->snapshotRowTables as $name => $declaration) {
            $tables[] = $wpdb->prefix . (string) $name;
            $attachedMeta = $this->policy->attached_meta_table_for_owner((string) $name);
            if (is_array($attachedMeta)) {
                $tables[] = $wpdb->prefix . (string) ($attachedMeta['name'] ?? '');
            }
            foreach ((array) ($declaration['invalidate'] ?? []) as $invalidation) {
                if (is_array($invalidation) && isset($invalidation['table'])) {
                    $tables[] = $wpdb->prefix . (string) $invalidation['table'];
                }
            }
        }
        DeleteGuardEvaluator::assert_table_identifiers(
            $tables,
            'authored transaction storage-engine boundary'
        );
        $tables = array_values(array_unique($tables));
        sort($tables, SORT_STRING);
        return $tables;
    }

    private static function failure_fingerprint(\Throwable $failure): string {
        $message = $failure->getMessage();
        $class = get_class($failure);
        return $class . ':' . strlen($message) . ':' . substr(hash('sha256', $message), 0, 16);
    }

    /**
     * The cache roster is derived from the complete physical mutation plan,
     * not merely today's live policy expansion. A typed table can introduce
     * a WooCommerce pa_* registration row in phase 1 while the same compiled
     * tree already contains that taxonomy's terms; the process-local registry
     * cannot see it until the next request. Exact manifest hierarchy facts
     * are the only admitted fallback for such an unregistered planned name.
     *
     * @param array<string,array<string,mixed>> $tree
     * @param list<array<string,mixed>> $deleteWork
     * @return array<string,bool>
     */
    private function taxonomy_hierarchy_roster(array $tree, array $deleteWork): array {
        $names = array_fill_keys(array_merge($this->policy->taxonomies(), ['nav_menu']), true);
        foreach ($tree as $entity) {
            if (!is_array($entity)) continue;
            $type = $entity['type'] ?? null;
            if ($type === 'menu') {
                $names['nav_menu'] = true;
            } elseif ($type === 'term') {
                $taxonomy = is_array($entity['data'] ?? null)
                    ? ($entity['data']['taxonomy'] ?? null)
                    : null;
                if (!is_string($taxonomy)) {
                    throw new \RuntimeException('wprism: authored apply term tree lacks an exact taxonomy cache identity');
                }
                $names[$taxonomy] = true;
            }
        }
        foreach ($deleteWork as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('wprism: authored apply deletion roster is malformed');
            }
            $type = $row['type'] ?? null;
            if ($type === 'menu') {
                $names['nav_menu'] = true;
            } elseif ($type === 'term') {
                $taxonomy = $row['deletion_type'] ?? null;
                if (!is_string($taxonomy)) {
                    throw new \RuntimeException('wprism: authored apply term deletion lacks an exact taxonomy cache identity');
                }
                $names[$taxonomy] = true;
            }
        }

        $roster = [];
        foreach (array_keys($names) as $taxonomy) {
            if (!is_string($taxonomy) || preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $taxonomy) !== 1) {
                throw new \RuntimeException('wprism: authored apply resolved a malformed taxonomy cache roster');
            }
            $runtime = get_taxonomy($taxonomy);
            if ($runtime !== false) {
                if (!is_object($runtime) || !is_bool($runtime->hierarchical ?? null)) {
                    throw new \RuntimeException(
                        "wprism: authored apply taxonomy '$taxonomy' has malformed native hierarchy registration"
                    );
                }
                $roster[$taxonomy] = $runtime->hierarchical;
                continue;
            }
            $declared = $this->policy->declared_taxonomy_hierarchical($taxonomy);
            if (!is_bool($declared)) {
                throw new \RuntimeException(
                    "wprism: authored apply taxonomy '$taxonomy' is unregistered and lacks a reviewed hierarchical declaration"
                );
            }
            $roster[$taxonomy] = $declared;
        }
        ksort($roster, SORT_STRING);
        return $roster;
    }
}
