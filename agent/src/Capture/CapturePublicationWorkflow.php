<?php
namespace Duo;

require_once __DIR__ . '/../Review/Canary.php';
require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Kernel/MediaPayloadAuthority.php';
require_once __DIR__ . '/../Kernel/ProcessFence.php';
require_once __DIR__ . '/CaptureCandidateBuilder.php';
require_once __DIR__ . '/CapturePublicationRecovery.php';
require_once __DIR__ . '/CaptureTransaction.php';
require_once __DIR__ . '/../Code/Code.php';
require_once __DIR__ . '/../Delete/Deletion.php';
require_once __DIR__ . '/../Promotion/Deploy.php';
require_once __DIR__ . '/../Kernel/Db.php';
require_once __DIR__ . '/../Repository/Identity.php';
require_once __DIR__ . '/InitialCaptureBoundary.php';
require_once __DIR__ . '/../Repository/Ledger.php';
require_once __DIR__ . '/../Promotion/LifecyclePlanner.php';
require_once __DIR__ . '/../Review/Lint.php';
require_once __DIR__ . '/../Review/LintTrustGate.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Publication/Publish.php';
require_once __DIR__ . '/../Repository/RepositoryCompiler.php';
require_once __DIR__ . '/../Scope/ScopedApply.php';
require_once __DIR__ . '/../Scope/ScopedCaptureProjector.php';
require_once __DIR__ . '/../Scope/ScopedStateOverlay.php';
require_once __DIR__ . '/../Policy/ScopeContract.php';
require_once __DIR__ . '/../Repository/SidebarState.php';
require_once __DIR__ . '/../Repository/Snapshot.php';

/**
 * Orchestrates one atomic capture publication.
 *
 * Candidate construction, strict initial ownership, scoped-contract
 * association, transaction replay safety, and commit recovery are delegated
 * to focused collaborators. This class owns their ordering under one
 * destination lock and one target-writer fence, and produces the stable
 * capture summary.
 */
final class CapturePublicationWorkflow {
    /**
     * DUO-3507: appended to each code_drift row this capture declined to
     * accept. The row's own message already names the three ways forward --
     * accept with 'duo deploy', restore the recorded version, or pass
     * --force-code-drift (LifecyclePlanner.php:400-401, :429-430) -- so this
     * adds only the part the row cannot know: that THIS verb saw the drift,
     * left the recorded baseline byte-identical, and therefore did not
     * consume the operator's decision. Deploy's symmetric line for the
     * opposite outcome is 'FORCED past code_drift: ' . $r['message']
     * (Deploy.php:221-224).
     */
    private const CODE_DRIFT_OBSERVED = " — 'duo capture' observed this and did NOT accept it as the new baseline: "
        . 'capture reports what it sees, it does not reconcile code. The recorded versions are unchanged, so this '
        . "finding is still there on the next 'duo status'.";

    public static function run(
        string $repo,
        ?string $outDir,
        bool $forceUnresolvedRefs,
        $publicationLock,
        bool $initialBaseline,
        ?string $initialStateIdentity = null,
        ?string $initialMediaIdentity = null,
        ?string $initialConfigIdentity = null,
        ?callable $onInitialPayloadReady = null,
        ?array $scopeRequest = null,
        ?string $hostEnvironment = null
    ): array {
        Canary::suppress_cron_spawn();
        // Policy's v1 single-site boundary must run before any destination
        // lock or Ledger work: an unsupported multisite request is a clean
        // refusal, not a request that may initialize or rewrite Duo state
        // before eventually discovering it cannot be certified.
        $intoRepo = ($outDir === null);
        $repoPath = rtrim($repo, '/');
        $stateDir = $intoRepo ? $repoPath . '/state' : rtrim($outDir, '/');
        if ($scopeRequest !== null && !$intoRepo) {
            throw new \RuntimeException(
                'duo: scoped capture publishes a bounded overlay into its associated repository; --out is unsupported'
            );
        }
        // DUO-3427: this early check exists for exactly one reason — acquiring
        // the destination lock CREATES its file, and no ordinary capture may
        // write into a repository that holds an interrupted init. When the
        // canonical lock already exists there is nothing to create, so the
        // early exit buys nothing and costs the truth: a LIVE init holds that
        // lock and has already written its journal, so this arm answered a
        // running race with recovery advice — "run duo init to verify or roll
        // back that interrupted attempt" — for an init that was not
        // interrupted, was mid-publication, and went on to succeed. Following
        // that advice means starting a second init against a live one.
        //
        // Gated on the lock's ABSENCE, the no-write guarantee is unchanged
        // (the refusal still happens before anything could be created) and a
        // live race falls through to Publish::lock(), whose refusal names the
        // held destination lock. Whoever then holds the lock is by definition
        // the only live publisher, so the post-acquire check below — already
        // documented as the race-closer — is where "interrupted" can actually
        // be told apart from "in progress".
        $canonicalLock = Publish::lock_path($stateDir);
        if (!$initialBaseline && !file_exists($canonicalLock) && !is_link($canonicalLock)) {
            InitialCaptureBoundary::assertNoInterruptedInit($repoPath);
        }
        if ($initialBaseline) {
            InitialCaptureBoundary::assertConfigIdentity($repoPath . '/site.duo.json', (string) $initialConfigIdentity);
        }
        $policy = Policy::load($repo);
        if ($initialBaseline) {
            InitialCaptureBoundary::assertConfigIdentity($repoPath . '/site.duo.json', (string) $initialConfigIdentity);
        }

        // DUO-3213: a capture lock serializes concurrent publishers to this
        // SAME destination (Publish::lock() fails cleanly, non-blocking, if
        // another capture already holds it — see its own docblock for why).
        // Held for the full remainder of this method, including the ledger
        // bookkeeping tail below: that's "publication," end to end, and two
        // publishers interleaving any part of it is exactly what the lock
        // exists to rule out.
        $ownsLock = $publicationLock === null;
        $lock = $publicationLock ?? Publish::lock($stateDir);
        $ownsTargetFence = false;
        $testPhaseMarked = false;
        $publicationPhase = ['initial_baseline' => $initialBaseline];
        $initialPublicationCleanup = $initialBaseline ? 'pending' : 'not-applicable';
        try {
            // Scoped recovery first reconciles any prior durable publication,
            // then binds the new immutable evidence before this run's first
            // DDL/DML. Unlike full capture it cannot initialize/repair the
            // ledger and only later discover a stale source or policy.
            $scopedPrevious = null;
            $scopeContract = null;
            $scoped = false;
            $scopeSourceTreeSha256 = null;
            if (!$initialBaseline) {
                // Close the check/acquire race: a confirmed init can publish
                // its durable journal after the first check but before this
                // capture acquires the canonical state lock. No ordinary
                // capture may proceed while that recovery authority exists.
                InitialCaptureBoundary::assertNoInterruptedInit($repoPath);
            }
            if ($initialBaseline) {
                InitialCaptureBoundary::assertStateReservation($stateDir, (string) $initialStateIdentity);
                InitialCaptureBoundary::assertStateReservation($repoPath . '/media', (string) $initialMediaIdentity);
                Publish::assert_lock_path($lock, $stateDir);
                InitialCaptureBoundary::assertProtocolBoundaries($stateDir);
                $publicationPhase['media_manifest'] = Publish::tree_ownership_manifest($repoPath . '/media');
                $publicationPhase['state_manifest'] = Publish::tree_ownership_manifest($stateDir);
            }
            // DUO-3217's promotion fence is target-wide because the ledger,
            // embedded identity, and plugin-visible state are shared even
            // when two captures publish into DIFFERENT directories. Claim it
            // before Ledger::ensure(): schema migration and every row
            // mutation below are target writes. The destination lock remains
            // necessary for filesystem recovery/publication, but it cannot
            // serialize a second destination or an apply process.
            $ownsTargetFence = self::acquireTargetWriterFence();
            self::assertNoPromotionSessionIfLedgerExists();
            if ($scopeRequest === null) {
                Ledger::ensure();
            } else {
                // A scoped request cannot bootstrap or repair global ledger
                // schema. Existing durable identity is a precondition.
                Ledger::assert_read_only_schema();
            }
            self::assertNoPromotionSession();
            $c = new CaptureCandidateBuilder($repo, $policy);
            CaptureTransaction::assert_engine_support($policy);

            // DUO-3223 (concurrency-scenario harness): the SAME deterministic
            // test-gate idiom DUO-3217 established for PromotionLock
            // (agent/src/Apply/Apply.php's own DUO_TEST_MODE/DUO_TEST_PROMOTION_
            // PAUSE_MS), applied to the capture lock instead — a live test
            // driving two real `wp duo capture` processes against the same
            // destination needs a way to GUARANTEE the first one is still
            // holding the lock when the second one starts, rather than
            // gambling on wall-clock timing against however large the
            // fixture happens to be. Ledger::kv_set() is a cross-process,
            // DB-backed marker (the lock itself is a local flock(), not
            // observable from another wp-cli invocation's own process) —
            // the same reason PromotionLock's phase marker is DB-backed
            // rather than in-memory. No effect at all unless a caller
            // explicitly opts into a marker-reading seam; production capture
            // is unchanged, and a DUO_TEST_MODE run requesting neither seam
            // writes no marker (a SIGKILLed run must not leave a ledger row
            // that the next init reads as an existing Duo ledger).
            $pauseMs = (int) (getenv('DUO_TEST_CAPTURE_PAUSE_MS') ?: 0);
            $waitForRelease = getenv('DUO_TEST_CAPTURE_WAIT_FOR_RELEASE') === '1';
            if ($scopeRequest === null && getenv('DUO_TEST_MODE') === '1'
                && (($pauseMs > 0 && $pauseMs <= 10000) || $waitForRelease)) {
                // This marker is intentionally outside the consistent
                // snapshot: a second process must be able to observe it
                // while this process is paused inside the held flock(). It is
                // test-only and is deleted in finally so a refused/failed
                // capture cannot leave a duo_kv residue behind.
                //
                // DUO-3427: scoped to the pause it exists FOR, not to test
                // mode at large. Its only reader (regress_capture_concurrency)
                // always requests the pause, and the finally-delete keeps the
                // no-residue promise on every ordinary failure — but not
                // through a SIGKILL, and #151 later added init's SIGKILL fault
                // seams to this same path. Every killed init therefore
                // committed one wp_duo_kv row that nothing would ever read and
                // no rollback would ever clear, and a non-pristine ledger is
                // not inert: it is `existing_duo_ledger`, so the environment a
                // rolled-back init is supposed to leave RETRYABLE refused the
                // next init instead. Written only where it is observed, so the
                // promise in the paragraph above is true for every path that
                // writes it.
                $testPhaseMarked = true;
                Ledger::kv_set('capture_test_phase', 'locked');
                if ($waitForRelease) {
                    $released = false;
                    for ($attempt = 0; $attempt < 1200; $attempt++) {
                        if ((string) Ledger::kv_get('capture_test_phase') === 'release') {
                            $released = true;
                            break;
                        }
                        usleep(100000);
                    }
                    if (!$released) {
                        throw new \RuntimeException('duo: test capture release marker was not received');
                    }
                } else {
                    usleep($pauseMs * 1000);
                }
            }
            // Deterministic recovery of whatever a prior crashed run left
            // behind MUST happen before this run builds anything of its
            // own — see Publish::recover()'s docblock for why holding the
            // lock is what makes "leftover staging/backup dir" unambiguous.
            $recoveryWarnings = [];
            if (!$initialBaseline) {
                $recoveryWarnings = Publish::recover(
                    $stateDir,
                    static function (array $intent) use ($stateDir): bool {
                        return CapturePublicationRecovery::commitStatus($stateDir, $intent);
                    }
                );
                try {
                    CapturePublicationRecovery::clearIfClean($stateDir);
                } catch (\Throwable $markerCleanupFailure) {
                    $recoveryWarnings[] = 'capture recovery completed, but its stale database commit marker could not be removed: '
                        . $markerCleanupFailure->getMessage();
                }
            }
            if ($scopeRequest !== null) {
                if (!is_dir($repoPath . '/state')) {
                    throw new \RuntimeException('duo: scoped capture requires an existing compiled state revision');
                }
                // Recovery above is governed solely by its durable old
                // intent/marker/receipt. Only after it is reconciled may the
                // new contract bind the now-current source revision.
                $scopedPrevious = RepositoryCompiler::compile_for_diff($repoPath, Policy::load($repoPath));
                $scopeContract = ScopedCaptureProjector::contractForRequest(
                    $scopeRequest,
                    $scopedPrevious,
                    $policy
                );
                // A contract-selected `all` is still a scoped, state-only
                // transaction. It must not silently fall back to legacy
                // identity minting/global pruning merely because every
                // source identity was selected.
                $scoped = true;
                $scopeSourceTreeSha256 = Publish::tree_digest($repoPath . '/state');
            }
            // The previous compiled revision is the only authority from
            // which a deletion intent can be created. A first capture has no
            // prior state and therefore cannot infer a deletion. Compiling
            // before target reads also still refuses to build new state on
            // top of a genuinely corrupted repository revision (malformed
            // JSON, invalid record shapes, illegitimate tombstones — every
            // ordinary historical-integrity check in RepositoryCompiler).
            //
            // DUO-3287: compile_for_diff(), not compile() — this revision
            // was captured under whatever policy was active AT THAT TIME,
            // which the CURRENT policy may since have outgrown (a manifest
            // added to site.duo.json after the last capture, the entire
            // premise of DUO-3257's incremental adoption model). Demanding
            // current-policy completeness from a historical revision isn't
            // corruption detection, it's refusing to read history that
            // predates a policy expansion — verified live: every
            // required-option-missing diagnostic this produced (13-17 of
            // them, reproduced deterministically) was a genuinely NEW
            // authored-exact option the previous revision had never even
            // been asked to know about. It also skips the current-action
            // code/lifecycle bridge when this history predates code opt-in;
            // Code::compile() and every ordinary historical integrity check
            // stay active. RepositoryCompiler.php's own
            // $completenessOptional docblock has the full reasoning.
            //
            // DUO-3263: a FRESH Policy::load(), never the shared $policy
            // build() below will use. RepositoryCompiler::compile[_for_diff]()
            // primes every schema-driven interpreter from THIS tree via
            // prime_interpreters_from_repository() (manifests/interpreters/
            // acf.php's own field_definition() docblock: "authorization is
            // about one immutable revision," so once primed it never falls
            // back to a live DB query again for that interpreter instance).
            // Interpreter instances are cached per-Policy-object and $policy
            // is otherwise reused for the whole rest of this method — sharing
            // it here would permanently lock every interpreter into
            // repository-only mode using the PREVIOUS revision's content,
            // before build() below has captured anything new at all. Caught
            // live: a second capture that introduces a brand-new ACF field
            // (term- or options-page-attached) the previous revision had
            // never seen came back unclassified, even though the exact same
            // field classified correctly on this repo's first-ever capture —
            // proof the previous revision's own priming was leaking forward
            // into the new one's classification instead of a fresh lookup.
            $previous = $scopedPrevious ?? (!$initialBaseline && is_dir($c->repo() . '/state')
                ? RepositoryCompiler::compile_for_diff($c->repo(), Policy::load($repo))
                : null);
            $previousOptions = $previous?->tree()['options/core']['data'] ?? null;
            $previousUserLogins = [];
            foreach ($previous?->tree() ?? [] as $entity) {
                if (($entity['type'] ?? '') === 'user-meta') {
                    $previousUserLogins[] = (string) ($entity['data']['login'] ?? '');
                }
            }
            // The one consistent-snapshot transaction: every SELECT build()
            // issues, plus dead-map pruning, the _duo_uuid/duo_map identity-
            // minting writes, and deletion capability validation, see one
            // coherent point-in-time view. Retries on its own (see
            // run_in_consistent_snapshot()) if a concurrent WordPress write
            // collides with one of THIS build's own writes. In particular,
            // an unsupported deletion must roll back every row mutation made
            // while assembling the refused candidate.
            $build = self::runInConsistentSnapshot(function () use (
                $c, $policy, $repo, $forceUnresolvedRefs, $previous, $previousOptions,
                $previousUserLogins, $intoRepo, $stateDir,
                $initialStateIdentity, $initialMediaIdentity, $initialConfigIdentity,
                $lock, $initialBaseline, $scoped, $scopeContract,
                $scopeSourceTreeSha256, $repoPath, $onInitialPayloadReady,
                $hostEnvironment,
                &$publicationPhase
            ): array {
                // All map/state mutations which can happen while deciding
                // whether this candidate is publishable are transactionally
                // coupled to Deletion::capture_tombstones() below. A refusal
                // therefore cannot strand a newly-minted identity or a
                // pruned map row in the environment.
                Identity::assert_embedded_unique();
                if (!$scoped) {
                    Ledger::prune_dead_map();
                    $observedDeletedTables = Snapshot::observed_deleted_mapped_uuids($policy);
                    $observedDeletedWidgets = SidebarState::observed_deleted_mapped_uuids($policy);
                    SidebarState::prune_dead_map($policy);
                    // A typed method row may disappear before its instance-
                    // settings option. Keep the option-name identity alive
                    // through this capture so build_options() can still emit
                    // the paired canonical option tombstone.
                    Snapshot::prune_dead_map($policy, $previousOptions);
                    Snapshot::assert_mapped_history_present($policy, $repo, $observedDeletedTables);
                    SidebarState::assert_mapped_history_present($repo, $observedDeletedWidgets);
                }
                // Scoped v1 never mints or globally prunes target identity.
                // Every selectable identity already exists in the associated
                // repository revision; a missing one is a refusal below, not
                // implicit deletion authority.
                $candidate = $c->build(
                    !$scoped,
                    $forceUnresolvedRefs,
                    $previousOptions,
                    $previousUserLogins,
                    $scoped,
                    $scoped ? ScopedStateOverlay::selected_identities($scopeContract) : null
                );
                Identity::assert_entities_unique($candidate['entities']);
                $candidate['deletions'] = Deletion::capture_tombstones(
                    $previous,
                    $candidate['entities'],
                    $policy,
                    $scoped ? ScopedStateOverlay::selected_identities($scopeContract) : null
                );
                $authorizedScopedDeletions = [];
                $authorizedScopedDeauthorizations = [];
                $scopedDeletionCount = 0;
                $selectedObserved = $candidate['entities'];
                if ($scoped) {
                    $authorizedScopedDeauthorizations = ScopedStateOverlay::selected_deauthorizations(
                        $previous,
                        $scopeContract,
                        $candidate['entities'],
                        $candidate['deletions'],
                        $policy,
                        $candidate['portable_widget_scan'] ?? null
                    );
                    ScopedStateOverlay::assert_shared_row_mutation_bounded(
                        $previous,
                        $scopeContract,
                        $candidate['entities']
                    );
                    $selectedSourceTombstones = array_fill_keys(
                        array_map('strval', array_column((array) $scopeContract['tombstones'], 'uuid')),
                        true
                    );
                    foreach ($candidate['deletions'] as $deletion) {
                        $identity = (string) ($deletion['uuid'] ?? '');
                        if ($identity !== '' && !isset($selectedSourceTombstones[$identity])) {
                            $authorizedScopedDeletions[] = $identity;
                        }
                    }
                    // Validate the complete coherent target observation
                    // before projecting it onto old repository bytes. This
                    // catches target-only inbound refs, declared children,
                    // and outbound dependencies that would otherwise vanish
                    // with an excluded-row overlay and evade the final tree.
                    $targetProbeState = ScopedStateOverlay::stage_state_view(
                        ScopedStateOverlay::target_probe_rows(
                            $previous,
                            $candidate['entities'],
                            $candidate['deletions']
                        )
                    );
                    $targetProbeMedia = null;
                    try {
                        $targetProbeMedia = ScopedStateOverlay::stage_candidate_media_view(
                            $c->repo(),
                            $candidate['media']
                        );
                        $targetProbe = RepositoryCompiler::compile_staged(
                            $targetProbeState,
                            $c->repo(),
                            $policy,
                            $targetProbeMedia
                        );
                        ScopeContract::assert_candidate_bounded(
                            $scopeContract,
                            $targetProbe,
                            $policy,
                            $authorizedScopedDeletions,
                            $authorizedScopedDeauthorizations
                        );
                    } finally {
                        if (is_string($targetProbeMedia)) {
                            ScopedStateOverlay::discard_media_view($targetProbeMedia);
                        }
                        ScopedStateOverlay::discard_state_view($targetProbeState);
                    }
                    $selectedSet = array_fill_keys(ScopedStateOverlay::selected_identities($scopeContract), true);
                    $selectedObserved = array_values(array_filter(
                        $candidate['entities'],
                        static fn(array $row): bool => isset($selectedSet[(string) ($row['uuid'] ?? '')])
                    ));
                    $overlay = ScopedStateOverlay::project_capture_associated(
                        $previous,
                        $scopeContract,
                        $candidate['entities'],
                        $candidate['deletions'],
                        $authorizedScopedDeauthorizations,
                        $policy
                    );
                    $candidate['entities'] = $overlay['entities'];
                    $candidate['deletions'] = $overlay['deletions'];
                    $scopedDeletionCount = count(array_filter(
                        $candidate['deletions'],
                        static fn(array $row): bool => isset($selectedSet[(string) ($row['uuid'] ?? '')])
                    ));
                    $candidate['media'] = ScopedStateOverlay::selected_media(
                        $selectedObserved,
                        $candidate['media']
                    );
                }

                // Keep the transaction open through every candidate-side
                // publication step. A staging/lint/media/compile failure
                // therefore rolls back all identity/map DML above rather
                // than leaving a candidate identity behind a refused tree.
                $staging = Publish::stage_dir($stateDir);
                if ($initialBaseline) {
                    InitialCaptureBoundary::assertConfigIdentity(
                        $c->repo() . '/site.duo.json',
                        (string) $initialConfigIdentity
                    );
                    Publish::assert_lock_path($lock, $stateDir);
                    InitialCaptureBoundary::assertStateReservation($stateDir, (string) $initialStateIdentity);
                    InitialCaptureBoundary::assertProtocolBoundaries($stateDir);
                    $publicationPhase['staging_manifest'] = Publish::write_entities_fresh(
                        $staging,
                        array_merge($candidate['entities'], $candidate['deletions'])
                    );
                } else {
                    Publish::write_entities($staging, array_merge($candidate['entities'], $candidate['deletions']));
                }
                $lint = Lint::scan_tree($staging, $c->policy());
                if ($lint) {
                    // WP-3.1: the same finding set, read twice. An uncertified
                    // out-of-tree adapter's finding REFUSES here — an
                    // under-declared reference is the defect a stranger's
                    // manifest produces and the one a byte-identical round trip
                    // cannot see. Everything else keeps the advisory warning
                    // below, byte for byte, because a shipped adapter's
                    // declarations are reviewed and digest-bound (rule 2).
                    LintTrustGate::assert($lint, $c->policy(), $staging);
                    $candidate['warnings'][] = self::lintWarning(
                        count($lint),
                        $c->repo(),
                        $hostEnvironment,
                        $intoRepo
                    );
                }
                $compiledCandidate = null;
                if ($scopeContract !== null) {
                    $candidateMediaView = ScopedStateOverlay::stage_candidate_media_view(
                        $c->repo(),
                        $candidate['media']
                    );
                    try {
                        $compiledCandidate = RepositoryCompiler::compile_staged(
                            $staging,
                            $c->repo(),
                            $c->policy(),
                            $candidateMediaView
                        );
                    } finally {
                        ScopedStateOverlay::discard_media_view($candidateMediaView);
                    }
                    if ($scoped) {
                        ScopedStateOverlay::assert_excluded_preserved(
                            $previous,
                            $compiledCandidate,
                            $scopeContract
                        );
                        ScopeContract::assert_candidate_bounded(
                            $scopeContract,
                            $compiledCandidate,
                            $policy,
                            $authorizedScopedDeletions,
                            $authorizedScopedDeauthorizations
                        );
                    }
                }
                if ($intoRepo) {
                    // Media is content-addressed and idempotent. A rejected
                    // candidate may leave an orphan blob, but no candidate
                    // identity/map DML can commit until the transaction below
                    // reaches its post-swap COMMIT.
                    foreach ($candidate['media'] as $file => $source) {
                        $dst = $c->repo() . '/media/' . $file;
                        if ($initialBaseline) {
                            $bytes = MediaPayloadAuthority::sourceBytes((string) $file, $source);
                            Publish::assert_lock_path($lock, $stateDir);
                            $identity = Publish::write_file_fresh(
                                $dst,
                                $bytes,
                                'media blob',
                                $publicationPhase['media_manifest']['root']
                            );
                            $publicationPhase['new_media'][] = ['path' => $dst, 'identity' => $identity];
                            $row = $identity;
                            $row['path'] = basename($dst);
                            $publicationPhase['media_manifest']['entries'][] = $row;
                        } elseif (!is_file($dst)) {
                            $bytes = MediaPayloadAuthority::sourceBytes((string) $file, $source);
                            Canon::write_file($dst, $bytes);
                        }
                    }
                    if ($initialBaseline) {
                        usort(
                            $publicationPhase['media_manifest']['entries'],
                            static fn(array $a, array $b): int => $a['path'] <=> $b['path']
                        );
                        Publish::assert_owned_tree(
                            $c->repo() . '/media',
                            $publicationPhase['media_manifest'],
                            'initial media root'
                        );
                        Publish::assert_owned_tree(
                            $staging,
                            $publicationPhase['staging_manifest'],
                            'initial capture staging'
                        );
                        Publish::assert_lock_path($lock, $stateDir);
                        InitialCaptureBoundary::assertStateReservation($stateDir, (string) $initialStateIdentity);
                        foreach ([
                            Publish::backup_dir($stateDir),
                            Publish::intent_path($stateDir),
                            Publish::receipt_path($stateDir),
                            Publish::intent_path($stateDir) . '.previous',
                            Publish::intent_path($stateDir) . '.next',
                            Publish::receipt_path($stateDir) . '.previous',
                            Publish::receipt_path($stateDir) . '.next',
                        ] as $sibling) {
                            if (file_exists($sibling) || is_link($sibling)) {
                                throw new InitialStateBoundaryException(
                                    'duo: initial capture protocol boundary changed before publication'
                                );
                            }
                        }
                    }
                }
                if ($scopeContract === null && $intoRepo) {
                    // Preserve legacy full-capture semantics: output-only
                    // seeds do not require newly observed media to already
                    // exist in repo/media, while a repository publication
                    // still validates the exact staged state after writing
                    // its content-addressed blobs.
                    $compiledCandidate = RepositoryCompiler::compile_staged($staging, $c->repo(), $c->policy());
                }
                if ($initialBaseline) {
                    $candidate['_initial_code_baseline'] = Code::complete_initial_baseline_in_active_transaction(
                        $repo,
                        $compiledCandidate
                    );
                    $candidate['_revision_hash'] = $compiledCandidate->revision_hash();
                }
                if ($scopeContract !== null) {
                    if (!is_string($scopeSourceTreeSha256)
                        || !hash_equals($scopeSourceTreeSha256, Publish::tree_digest($repoPath . '/state'))) {
                        throw new \RuntimeException(
                            'duo: scoped capture source revision changed after contract association; refusing publication'
                        );
                    }
                    $sourceExport = $previous->export();
                    $sourceMediaView = ScopedStateOverlay::stage_associated_source_media_view(
                        $repoPath,
                        (array) ($sourceExport['media_catalog'] ?? []),
                        $candidate['media']
                    );
                    try {
                        $currentPolicy = Policy::load($repoPath);
                        $currentSource = RepositoryCompiler::compile_for_diff(
                            $repoPath,
                            $currentPolicy,
                            $sourceMediaView
                        );
                        ScopeContract::assert_associated($scopeContract, $currentSource, $currentPolicy);
                    } finally {
                        ScopedStateOverlay::discard_media_view($sourceMediaView);
                    }
                }

                // The intent is durable before either rename. The old tree
                // remains in capture-backup until COMMIT is acknowledged;
                // this is explicit compensation for the fact that a
                // filesystem rename and a DB COMMIT cannot be instantaneous
                // two-phase commit.
                // Mark the recovery boundary before the first durable intent
                // write. A fault inside begin_intent() can leave a complete
                // intent even though no rename has started yet; init must run
                // the publication recovery protocol instead of treating that
                // case like an ordinary unpublished staging failure.
                if ($initialBaseline) {
                    Publish::assert_lock_path($lock, $stateDir);
                    InitialCaptureBoundary::assertStateReservation($stateDir, (string) $initialStateIdentity);
                    Publish::assert_owned_tree(
                        $staging,
                        $publicationPhase['staging_manifest'],
                        'initial capture staging'
                    );
                    Publish::assert_owned_tree(
                        $c->repo() . '/media',
                        $publicationPhase['media_manifest'],
                        'initial media root'
                    );
                    if ($onInitialPayloadReady !== null) {
                        $onInitialPayloadReady(
                            $publicationPhase['staging_manifest'],
                            $publicationPhase['media_manifest'],
                            $publicationPhase['state_manifest']
                        );
                    }
                }
                $publicationPhase['publication_started'] = true;
                $intent = $initialBaseline
                    ? Publish::begin_intent($stateDir, $staging, true)
                    : Publish::begin_intent($stateDir, $staging);
                $publicationPhase['filesystem_swapped'] = true;
                if ($initialBaseline) {
                    InitialCaptureBoundary::assertStateReservation($stateDir, (string) $initialStateIdentity);
                }
                if ($initialBaseline) {
                    Publish::swap_initial(
                        $stateDir,
                        $publicationPhase['state_manifest'],
                        $publicationPhase['staging_manifest']
                    );
                } else {
                    Publish::swap($stateDir, true);
                }
                if ($initialBaseline) {
                    InitialCaptureBoundary::assertConfigIdentity(
                        $c->repo() . '/site.duo.json',
                        (string) $initialConfigIdentity
                    );
                    InitialCaptureBoundary::assertStateReservation(
                        Publish::backup_dir($stateDir),
                        (string) $initialStateIdentity
                    );
                    if (getenv('DUO_TEST_MODE') === '1'
                        && getenv('DUO_TEST_INIT_FAIL_PHASE') === 'post-swap-unmanifested-empty') {
                        @mkdir($stateDir . '/unmanifested-empty-directory', 0777);
                    }
                    Publish::assert_owned_tree(
                        $stateDir,
                        $publicationPhase['staging_manifest'],
                        'initial published state'
                    );
                }
                $intent = Publish::mark_swapped($stateDir, $intent, $initialBaseline);
                if ($initialBaseline) {
                    Db::checkpoint('init capture after filesystem swap');
                }
                if ($intoRepo) {
                    $ledgerEntities = $scoped ? $selectedObserved : $candidate['entities'];
                    foreach ($ledgerEntities as $e) {
                        Ledger::set_state_hash(
                            $e['uuid'],
                            $e['type'],
                            hash('sha256', $e['hash_basis'] ?? $e['content'])
                        );
                    }
                    if ($scoped && ScopedApply::has_record_scoped_options($scopeContract)) {
                        $options = null;
                        foreach ($candidate['entities'] as $entity) {
                            if (($entity['uuid'] ?? null) === 'options/core') {
                                $options = $entity;
                                break;
                            }
                        }
                        if (!is_array($options)) {
                            throw new \RuntimeException('duo: scoped capture lost its options carrier before ledger finalization');
                        }
                        try {
                            $document = Canon::decode((string) ($options['content'] ?? ''));
                            foreach (ScopedApply::option_state_hashes((array) $document, $scopeContract) as $identity => $hash) {
                                Ledger::set_state_hash($identity, 'option', $hash);
                            }
                        } catch (\Throwable $failure) {
                            throw new \RuntimeException('duo: scoped capture options carrier is malformed before ledger finalization', 0, $failure);
                        }
                    }
                    if ($scoped) {
                        foreach ($authorizedScopedDeletions as $identity) {
                            // This is the only map/state removal in scoped
                            // v1, and its UUID already passed selected-scope,
                            // capability, inbound, and candidate gates.
                            Ledger::forget((string) $identity);
                        }
                    }
                    if (!$scoped) {
                        Ledger::prune_state(array_merge(
                            array_column($candidate['entities'], 'uuid'),
                            array_column($candidate['deletions'], 'uuid')
                        ));
                        // DUO-3507: capture observes code, it never accepts
                        // it. The unconditional re-baseline stays deploy's
                        // alone -- Deploy.php:464-472 reaches
                        // LifecyclePlanner::record_code_versions() only after
                        // that verb's own refuse-or-force gate
                        // (Deploy.php:203-210, :221-224). Capture has no such
                        // gate, so an unaccepted drift leaves the recorded
                        // blob byte-identical and every row is reported
                        // (Architecture Rulings §1, report-not-hide) rather
                        // than erased by a silent re-baseline that produced
                        // no output at all.
                        foreach (LifecyclePlanner::observe_code_versions($c->policy()) as $observed) {
                            $candidate['warnings'][] = (string) $observed['message'] . self::CODE_DRIFT_OBSERVED;
                        }
                    }
                }
                $intent = Publish::mark_commit_ready($stateDir, $intent, $initialBaseline);
                // Final DML in this transaction: a destination-scoped,
                // self-hashed proof that COMMIT makes this exact intent
                // durable. Recovery uses its absence/mismatch to roll back
                // an uncommitted filesystem swap, and its presence to finish
                // a receipt-write crash without replaying capture.
                Ledger::kv_set(
                    CapturePublicationRecovery::markerKey($stateDir),
                    CapturePublicationRecovery::marker($stateDir, $intent)
                );
                $publicationPhase['state_dir'] = $stateDir;
                $publicationPhase['intent'] = $intent;
                // Carry only the durable intent across COMMIT. It is removed
                // by cleanup_committed() after the receipt is written.
                $candidate['_publication_intent'] = $intent;
                if ($scopeContract !== null) {
                    $candidate['_scope'] = [
                        'format' => ScopeContract::FORMAT,
                        'scope_hash' => (string) $scopeContract['scope_hash'],
                        'source_artifact_hash' => (string) $scopeContract['source']['artifact_hash'],
                        'source_state_revision_hash' => (string) $scopeContract['source']['state_revision_hash'],
                        'selected_live' => count($selectedObserved),
                        'selected_tombstones' => $scoped
                            ? $scopedDeletionCount
                            : count($scopeContract['tombstones']),
                        'projection' => ScopedStateOverlay::is_all($scopeContract)
                            ? 'all-overlay'
                            : 'bounded-overlay',
                    ];
                }
                return $candidate;
            }, $publicationPhase, $policy);

            // The transaction wrapper has now returned only after COMMIT
            // succeeded. Publish a durable receipt before releasing the
            // capture lock. If receipt publication fails, recovery refuses
            // to guess whether COMMIT was durable and never retries.
            if (isset($build['_publication_intent'])) {
                if ($initialBaseline) {
                    InitialCaptureBoundary::assertConfigIdentity(
                        $repoPath . '/site.duo.json',
                        (string) $initialConfigIdentity
                    );
                    Publish::assert_lock_path($lock, $stateDir);
                    $publicationPhase['intent_identity'] = Publish::file_ownership_identity(
                        Publish::intent_path($stateDir)
                    );
                    Publish::assert_owned_tree(
                        $stateDir,
                        $publicationPhase['staging_manifest'],
                        'initial committed state before receipt'
                    );
                    Publish::assert_owned_tree(
                        Publish::backup_dir($stateDir),
                        $publicationPhase['state_manifest'],
                        'initial retained reservation before receipt'
                    );
                }
                $receipt = Publish::write_receipt($stateDir, $build['_publication_intent'], $initialBaseline);
                try {
                    if ($initialBaseline) {
                        Publish::assert_lock_path($lock, $stateDir);
                        Publish::cleanup_committed_initial(
                            $stateDir,
                            $receipt,
                            $publicationPhase['state_manifest'],
                            $publicationPhase['staging_manifest'],
                            $publicationPhase['intent_identity']
                        );
                    } else {
                        Publish::cleanup_committed($stateDir, $receipt);
                    }
                    CapturePublicationRecovery::clearIfClean($stateDir);
                    if ($initialBaseline) {
                        $initialPublicationCleanup = 'clean';
                    }
                } catch (\Throwable $cleanupFailure) {
                    // Receipt durability makes this cleanup idempotent. Keep
                    // the successful capture successful; the next run will
                    // retry cleanup while holding the same destination lock.
                    $build['warnings'][] = 'capture committed; retained publication cleanup will retry on the next run: '
                        . $cleanupFailure->getMessage();
                    if ($initialBaseline) {
                        $initialPublicationCleanup = 'retained';
                    }
                }
                unset($build['_publication_intent']);
            }
        } catch (\Throwable $failure) {
            // Init has no prior revision to recover. Before swap begins, all
            // database effects have rolled back, so remove the unpublished
            // candidate and only the content-addressed blobs this attempt
            // created. Ordinary capture retains its historical recovery
            // behavior unchanged.
            $safeToCompensate = $initialBaseline && empty($publicationPhase['publication_started']);
            $hasInitialTransitionSlot = false;
            if ($initialBaseline) {
                foreach ([
                    Publish::intent_path($stateDir) . '.previous',
                    Publish::intent_path($stateDir) . '.next',
                    Publish::receipt_path($stateDir) . '.previous',
                    Publish::receipt_path($stateDir) . '.next',
                ] as $transitionSlot) {
                    if (file_exists($transitionSlot) || is_link($transitionSlot)) {
                        $hasInitialTransitionSlot = true;
                        break;
                    }
                }
            }
            if ($initialBaseline && $failure instanceof InitialStateBoundaryException) {
                // Strict first-publication refusals never invoke ordinary
                // recovery because it may remove legacy-named siblings.
                $safeToCompensate = !file_exists(Publish::intent_path($stateDir))
                    && !is_link(Publish::intent_path($stateDir))
                    && !file_exists(Publish::receipt_path($stateDir))
                    && !is_link(Publish::receipt_path($stateDir))
                    && !$hasInitialTransitionSlot;
            } elseif ($initialBaseline && !empty($publicationPhase['publication_started'])) {
                if ($hasInitialTransitionSlot) {
                    // A fixed transition slot is durable recovery authority.
                    // Init's sealed journal carries the strict payload
                    // manifests needed to resolve it without legacy cleanup.
                    $safeToCompensate = false;
                } else {
                    try {
                        if (!isset($publicationPhase['state_manifest'], $publicationPhase['staging_manifest'])
                            || !is_array($publicationPhase['state_manifest'])
                            || !is_array($publicationPhase['staging_manifest'])) {
                            throw new InitialStateBoundaryException(
                                'duo: initial publication recovery has no complete state manifests'
                            );
                        }
                        Publish::recover_initial(
                            $stateDir,
                            $publicationPhase['state_manifest'],
                            $publicationPhase['staging_manifest'],
                            static function (array $intent) use ($stateDir): bool {
                                return CapturePublicationRecovery::commitStatus($stateDir, $intent);
                            }
                        );
                        // A rolled-back first publication has neither a state
                        // tree nor a retained intent. A durable/ambiguous commit
                        // keeps at least one and must be retained as one tuple.
                        $safeToCompensate = !is_dir($stateDir)
                            && !file_exists(Publish::intent_path($stateDir));
                    } catch (\Throwable $recoveryFailure) {
                        // Preserve every artifact when recovery proof is missing.
                        // Init will surface the retained-recovery diagnostic and
                        // must not delete config/code around an ambiguous commit.
                        $safeToCompensate = false;
                    }
                }
            }
            if ($safeToCompensate) {
                foreach (array_reverse((array) ($publicationPhase['new_media'] ?? [])) as $created) {
                    if (is_array($created) && is_string($created['path'] ?? null)
                        && is_array($created['identity'] ?? null)) {
                        Publish::remove_owned_file($created['path'], $created['identity'], 'initial media blob');
                    }
                }
                if (isset($publicationPhase['staging_manifest'])
                    && (is_dir(Publish::stage_dir($stateDir)) || is_link(Publish::stage_dir($stateDir)))) {
                    Publish::remove_owned_tree(
                        Publish::stage_dir($stateDir),
                        $publicationPhase['staging_manifest'],
                        'initial capture staging'
                    );
                }
            }
            throw $failure;
        } finally {
            if ($testPhaseMarked) {
                try {
                    Ledger::kv_delete('capture_test_phase');
                } catch (\Throwable $markerFailure) {
                    // The marker is only a live-test aid. Never mask the
                    // capture's real outcome with a best-effort cleanup
                    // failure, and never let it make a successful publish
                    // fail after the tree and authoritative ledger commit.
                }
            }
            try {
                if ($ownsLock) {
                    Publish::unlock($lock);
                }
            } finally {
                if ($ownsTargetFence) {
                    ProcessFence::release();
                }
            }
        }

        $counts = ['post' => 0, 'term' => 0, 'menu' => 0, 'sidebar' => 0, 'options' => 0, 'deletion' => 0];
        $countedEntities = $scoped
            ? array_values(array_filter($build['entities'], static function (array $row) use ($scopeContract): bool {
                $identity = (string) ($row['uuid'] ?? '');
                return in_array($identity, ScopedStateOverlay::selected_identities($scopeContract), true)
                    // Exact option roots are virtual identities inside the
                    // physical options/core carrier. Count its selected
                    // capture once, without implying whole-carrier authority.
                    || ($identity === 'options/core' && ScopeContract::option_root_names($scopeContract) !== []);
            }))
            : $build['entities'];
        foreach ($countedEntities as $e) {
            $counts[$e['type']] = ($counts[$e['type']] ?? 0) + 1;
        }
        $counts['deletion'] = $scoped ? (int) $build['_scope']['selected_tombstones'] : count($build['deletions']);
        $summary = [
            'counts' => $counts,
            'media' => count($build['media']),
            'notes' => $build['notes'],
            'warnings' => array_merge($recoveryWarnings, $build['warnings']),
            'state_dir' => $stateDir,
            'revision_hash' => $build['_revision_hash'] ?? null,
            'initial_code_baseline' => $build['_initial_code_baseline'] ?? null,
            'initial_publication_cleanup' => $initialPublicationCleanup,
        ];
        if (isset($build['_scope'])) {
            $summary['scope'] = $build['_scope'];
        }
        return $summary;
    }

    /** Fence every capture mutation against other live target writers. */
    private static function acquireTargetWriterFence(): bool {
        $alreadyHeld = ProcessFence::isContinuous();
        try {
            ProcessFence::acquire();
        } catch (\Throwable $failure) {
            throw self::targetWriterRefusal(
                'duo: capture refused because another live target process owns the target-writer fence',
                $failure
            );
        }
        return !$alreadyHeld;
    }

    /**
     * Reject an externally checkpointed promotion between its short-lived
     * WP-CLI processes. The already-held advisory fence closes the check/
     * acquire race. An expired row is still recovery authority, not
     * permission for capture to rewrite its ledger underneath it.
     */
    private static function assertNoPromotionSession(): void {
        if (Ledger::kv_get('promotion_lock') !== null) {
            throw self::targetWriterRefusal(
                'duo: capture refused because the target retains a promotion lock'
            );
        }
    }

    /**
     * Established targets must refuse before even idempotent schema repair;
     * a first capture has no duo_kv table (and therefore cannot have a
     * promotion session), so it proceeds to Ledger::ensure() and the
     * unconditional post-ensure check above.
     */
    private static function assertNoPromotionSessionIfLedgerExists(): void {
        global $wpdb;
        $wpdb->last_error = '';
        $table = $wpdb->prefix . 'duo_kv';
        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        ));
        if ($found === false || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException('duo: capture could not inspect the target ledger boundary');
        }
        if (is_string($found) && hash_equals($table, $found)) {
            self::assertNoPromotionSession();
        }
    }

    private static function targetWriterRefusal(
        string $operatorMessage,
        ?\Throwable $previous = null
    ): CommandRefusalException {
        return new CommandRefusalException(
            'capture_target_writer_active',
            'capture refused because another Duo target writer or promotion session is active',
            'wait for the active writer to finish; if none is running, inspect and recover or abort the retained promotion session before retrying capture',
            [],
            $operatorMessage,
            $previous
        );
    }

    private static function runInConsistentSnapshot(
        callable $fn,
        ?array &$phase = null,
        ?Policy $policy = null,
        bool $optionsOnly = false
    ) {
        $policy ??= Policy::load(null, ['core']);
        return CaptureTransaction::run($policy, $fn, $phase, $optionsOnly);
    }

    public static function lintWarning(
        int $count,
        string $repo,
        ?string $hostEnvironment,
        bool $intoRepo = true
    ): string {
        $warning = $count . ' suspicious unrewritten ref(s) in captured state — ';
        if (!$intoRepo) {
            return $warning . 'the output-only candidate was scanned before publication; '
                . '`duo lint` scans repository state, so no mismatched rescan command is shown. '
                . 'Rerun capture without `--out` before following its lint remediation';
        }
        if ($hostEnvironment !== null) {
            $environmentArg = self::shellArg($hostEnvironment);
            if ($environmentArg !== null) {
                $warning .= 'run on the host: `duo lint ' . $environmentArg . '`; or ';
            }
        }
        $repoArg = self::shellArg($repo);
        if ($repoArg === null) {
            return $warning . 'run directly on the target with a control-free `--repo` path; '
                . 'the configured repository path is unsafe to render';
        }
        return $warning . 'run directly on the target: `wp duo lint --repo=' . $repoArg . '`';
    }

    public static function shellArg(string $value): ?string {
        if (strlen($value) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }
        return preg_match('/^[A-Za-z0-9._:\/-]+$/D', $value) === 1
            ? $value
            : escapeshellarg($value);
    }
}
