<?php
namespace Duo;

require_once __DIR__ . '/PlainData.php';
require_once __DIR__ . '/Canon.php';

/**
 * Capture: environment DB -> canonical state tree.
 *
 * Read-only on content except for identity minting (_duo_uuid meta + ledger
 * rows). Unclassified meta keys on in-scope entities abort loudly — the
 * loud-and-blocking gate. Ordinary entities without a uuid are unmanaged and
 * invisible. Sidebar snapshots are the deliberate exception: unmapped live
 * defaults receive non-durable deterministic markers so their scoped removal
 * is plan-visible, while snapshot mode still never mints ledger identity.
 *
 * DUO-3213 — publication is atomic and DB-consistent, not clear-then-write-
 * in-place: every read build() performs, plus the identity-minting writes
 * alongside them, runs inside one InnoDB `START TRANSACTION WITH CONSISTENT
 * SNAPSHOT` (run_in_consistent_snapshot() below) so a single build() always
 * sees one coherent point-in-time view, regardless of what concurrent
 * WordPress requests commit meanwhile — never a tree assembled from two
 * different moments. The resulting entities are written to a STAGING
 * directory and only ever swapped into the published `state/` atomically
 * (agent/src/Publish.php) once every file is down and validated; a crash,
 * disk-full, or OOM-kill at any point before that swap leaves the
 * previously-published tree completely untouched. Concurrent publishers to
 * the same destination are serialized by a capture lock (Publish::lock()).
 * See Publish.php's own docblock for the filesystem mechanics and
 * check_transient_db_error()'s for the deadlock/lock-wait-timeout retry.
 */
final class Capture {
    /** 1 initial attempt + 2 retries on TransientDbException (see
     *  check_transient_db_error()) — tuned for brief lock contention
     *  against ordinary concurrent WordPress writes, not a sustained
     *  outage; a sustained failure should surface immediately; retrying it
     *  would just burn attempts reproducing the identical failure. */
    private const MAX_DB_ATTEMPTS = 3;

    private Policy $policy;
    private Tokens $tokens;
    private string $repo;
    /** @var string[] */
    private array $unclassified = [];
    /** @var array<int, array{option:string, kind:string, id:int, target_type:string}>
     *  authored, ref-typed OPTION values whose target row is real but out
     *  of policy scope — task #73's loud-and-blocking gate; see
     *  option_ref_tokens()/queue_or_warn_unscoped(). */
    private array $unscopedRefs = [];
    /** @var array<int, array{option:string, id_kind:string, id:int}>
     *  option_name_refs (task #93) rows whose embedded id names a row that
     *  genuinely exists in its declared table but was never minted a uuid
     *  (table not pinned as authored_snapshot in currently-loaded manifests,
     *  or an equivalent scope gap) — the #73 unscoped class, mirrored onto
     *  table id_kinds; see Snapshot::row_exists_for_kind()/build()'s gate. */
    private array $unscopedOptionNameRefs = [];
    /** @var array<int, string> user id -> login */
    private array $userLogins = [];
    /** @var array<string, string[]> post_type -> taxonomy[], scoped by each
     *  taxonomy's own registered object_type — see taxes_by_object_type(). */
    private array $taxesForPostType = [];
    /** @var string[] policy-scoped taxonomies whose registered object_type
     *  includes 'term' (Polylang's term_language/term_translations shape)
     *  — see taxes_by_object_type(). */
    private array $termObjectTaxes = [];

    private function __construct(string $repo, Policy $policy) {
        $this->repo = rtrim($repo, '/');
        $this->policy = $policy;
        $this->tokens = new Tokens();
        // DUO-3260: Tokens::tokenize_text()'s own unscoped-ref check needs
        // a Policy to judge scope against, but is called from too many
        // sites to thread one through as a per-call parameter (see
        // Tokens::$policy's own docblock for why that's a real safety
        // concern, not just style) — set once, here, guaranteed to exist
        // for every tokenize_text() call this Capture instance ever makes.
        $this->tokens->policy = $policy;
    }

    /**
     * Full capture. Writes the state tree (repo/state, or $outDir), copies
     * media + updates the ledger only when writing into the repo itself.
     *
     * @return array summary
     */
    public static function run(string $repo, ?string $outDir = null, bool $forceUnresolvedRefs = false): array {
        Canary::suppress_cron_spawn();
        // Policy's v1 single-site boundary must run before any destination
        // lock or Ledger work: an unsupported multisite request is a clean
        // refusal, not a request that may initialize or rewrite Duo state
        // before eventually discovering it cannot be certified.
        $policy = Policy::load($repo);
        $intoRepo = ($outDir === null);
        $repoPath = rtrim($repo, '/');
        $stateDir = $intoRepo ? $repoPath . '/state' : rtrim($outDir, '/');

        // DUO-3213: a capture lock serializes concurrent publishers to this
        // SAME destination (Publish::lock() fails cleanly, non-blocking, if
        // another capture already holds it — see its own docblock for why).
        // Held for the full remainder of this method, including the ledger
        // bookkeeping tail below: that's "publication," end to end, and two
        // publishers interleaving any part of it is exactly what the lock
        // exists to rule out.
        $lock = Publish::lock($stateDir);
        $testPhaseMarked = false;
        try {
            // The destination lock is deliberately acquired BEFORE even
            // Ledger::ensure(): schema migration and every row mutation below
            // belong to the same destination's serialized publication.
            Ledger::ensure();
            $c = new self($repo, $policy);
            self::verify_engine_support($policy);

            // DUO-3223 (concurrency-scenario harness): the SAME deterministic
            // test-pause idiom DUO-3217 established for PromotionLock
            // (agent/src/Apply.php's own DUO_TEST_MODE/DUO_TEST_PROMOTION_
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
            // explicitly opts into both env vars; production capture is
            // unchanged.
            if (getenv('DUO_TEST_MODE') === '1') {
                // This marker is intentionally outside the consistent
                // snapshot: a second process must be able to observe it
                // while this process is paused inside the held flock(). It is
                // test-only and is deleted in finally so a refused/failed
                // capture cannot leave a duo_kv residue behind.
                $testPhaseMarked = true;
                Ledger::kv_set('capture_test_phase', 'locked');
                $pauseMs = (int) (getenv('DUO_TEST_CAPTURE_PAUSE_MS') ?: 0);
                if ($pauseMs > 0 && $pauseMs <= 10000) {
                    usleep($pauseMs * 1000);
                }
            }
            // Deterministic recovery of whatever a prior crashed run left
            // behind MUST happen before this run builds anything of its
            // own — see Publish::recover()'s docblock for why holding the
            // lock is what makes "leftover staging/backup dir" unambiguous.
            $recoveryWarnings = Publish::recover(
                $stateDir,
                static function (array $intent) use ($stateDir): bool {
                    return self::publication_commit_status($stateDir, $intent);
                }
            );
            try {
                self::clear_publication_marker_if_clean($stateDir);
            } catch (\Throwable $markerCleanupFailure) {
                $recoveryWarnings[] = 'capture recovery completed, but its stale database commit marker could not be removed: '
                    . $markerCleanupFailure->getMessage();
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
            $previous = is_dir($c->repo . '/state')
                ? RepositoryCompiler::compile_for_diff($c->repo, Policy::load($repo))
                : null;
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
            $publicationPhase = [];
            $build = self::run_in_consistent_snapshot(function () use (
                $c, $policy, $repo, $forceUnresolvedRefs, $previous, $previousOptions,
                $previousUserLogins, $intoRepo, $stateDir, &$publicationPhase
            ): array {
                // All map/state mutations which can happen while deciding
                // whether this candidate is publishable are transactionally
                // coupled to Deletion::capture_tombstones() below. A refusal
                // therefore cannot strand a newly-minted identity or a
                // pruned map row in the environment.
                Identity::assert_embedded_unique();
                Ledger::prune_dead_map();
                $observedDeletedTables = Snapshot::observed_deleted_mapped_uuids($policy);
                $observedDeletedWidgets = SidebarState::observed_deleted_mapped_uuids($policy);
                SidebarState::prune_dead_map($policy);
                // A typed method row may disappear before its instance-
                // settings option. Keep the option-name identity alive
                // through this capture so build_options() can still emit the
                // paired canonical option tombstone instead of dropping the
                // live row as unmapped.
                Snapshot::prune_dead_map($policy, $previousOptions);
                Snapshot::assert_mapped_history_present($policy, $repo, $observedDeletedTables);
                SidebarState::assert_mapped_history_present($repo, $observedDeletedWidgets);
                $candidate = $c->build(true, $forceUnresolvedRefs, $previousOptions, $previousUserLogins);
                Identity::assert_entities_unique($candidate['entities']);
                $candidate['deletions'] = Deletion::capture_tombstones(
                    $previous,
                    $candidate['entities'],
                    $policy
                );

                // Keep the transaction open through every candidate-side
                // publication step. A staging/lint/media/compile failure
                // therefore rolls back all identity/map DML above rather
                // than leaving a candidate identity behind a refused tree.
                $staging = Publish::stage_dir($stateDir);
                Publish::write_entities($staging, array_merge($candidate['entities'], $candidate['deletions']));
                $lint = Lint::scan_tree($staging, $c->policy);
                if ($lint) {
                    $candidate['warnings'][] = count($lint)
                        . ' suspicious unrewritten ref(s) in captured state — run: wp duo lint --repo=' . $c->repo;
                }
                if ($intoRepo) {
                    // Media is content-addressed and idempotent. A rejected
                    // candidate may leave an orphan blob, but no candidate
                    // identity/map DML can commit until the transaction below
                    // reaches its post-swap COMMIT.
                    foreach ($candidate['media'] as $file => $source) {
                        $dst = $c->repo . '/media/' . $file;
                        if (!is_file($dst)) {
                            $bytes = array_key_exists('bytes', $source)
                                ? $source['bytes']
                                : Canon::read_file($source['path']);
                            Canon::write_file($dst, $bytes);
                        }
                    }
                    RepositoryCompiler::compile_staged($staging, $c->repo, $c->policy);
                }

                // The intent is durable before either rename. The old tree
                // remains in capture-backup until COMMIT is acknowledged;
                // this is explicit compensation for the fact that a
                // filesystem rename and a DB COMMIT cannot be instantaneous
                // two-phase commit.
                $intent = Publish::begin_intent($stateDir, $staging);
                $publicationPhase['filesystem_swapped'] = true;
                Publish::swap($stateDir, true);
                $intent = Publish::mark_swapped($stateDir, $intent);
                if ($intoRepo) {
                    foreach ($candidate['entities'] as $e) {
                        Ledger::set_state_hash(
                            $e['uuid'],
                            $e['type'],
                            hash('sha256', $e['hash_basis'] ?? $e['content'])
                        );
                    }
                    Ledger::prune_state(array_merge(
                        array_column($candidate['entities'], 'uuid'),
                        array_column($candidate['deletions'], 'uuid')
                    ));
                    Deploy::record_code_versions($c->policy);
                }
                $intent = Publish::mark_commit_ready($stateDir, $intent);
                // Final DML in this transaction: a destination-scoped,
                // self-hashed proof that COMMIT makes this exact intent
                // durable. Recovery uses its absence/mismatch to roll back
                // an uncommitted filesystem swap, and its presence to finish
                // a receipt-write crash without replaying capture.
                Ledger::kv_set(
                    self::publication_marker_key($stateDir),
                    self::publication_marker($stateDir, $intent)
                );
                $publicationPhase['state_dir'] = $stateDir;
                $publicationPhase['intent'] = $intent;
                // Carry only the durable intent across COMMIT. It is removed
                // by cleanup_committed() after the receipt is written.
                $candidate['_publication_intent'] = $intent;
                return $candidate;
            }, $publicationPhase);

            // The transaction wrapper has now returned only after COMMIT
            // succeeded. Publish a durable receipt before releasing the
            // capture lock. If receipt publication fails, recovery refuses
            // to guess whether COMMIT was durable and never retries.
            if (isset($build['_publication_intent'])) {
                $receipt = Publish::write_receipt($stateDir, $build['_publication_intent']);
                try {
                    Publish::cleanup_committed($stateDir, $receipt);
                    self::clear_publication_marker_if_clean($stateDir);
                } catch (\Throwable $cleanupFailure) {
                    // Receipt durability makes this cleanup idempotent. Keep
                    // the successful capture successful; the next run will
                    // retry cleanup while holding the same destination lock.
                    $build['warnings'][] = 'capture committed; retained publication cleanup will retry on the next run: '
                        . $cleanupFailure->getMessage();
                }
                unset($build['_publication_intent']);
            }
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
            Publish::unlock($lock);
        }

        $counts = ['post' => 0, 'term' => 0, 'menu' => 0, 'sidebar' => 0, 'options' => 0, 'deletion' => 0];
        foreach ($build['entities'] as $e) {
            $counts[$e['type']] = ($counts[$e['type']] ?? 0) + 1;
        }
        $counts['deletion'] = count($build['deletions']);
        return [
            'counts' => $counts,
            'media' => count($build['media']),
            'notes' => $build['notes'],
            'warnings' => array_merge($recoveryWarnings, $build['warnings']),
            'state_dir' => $stateDir,
        ];
    }

    /**
     * In-memory canonical view of this environment (no minting, no writes;
     * ledger map rows are synced from existing _duo_uuid meta — identity
     * repair, not content mutation).
     *
     * @return array<string, array{type: string, hash: string, content: string, path: string}>
     */
    public static function snapshot(
        string $repo,
        bool $forceUnresolvedRefs = false,
        ?CompiledRepository $compiled = null,
        ?Policy $policy = null
    ): array {
        Canary::suppress_cron_spawn();
        Ledger::ensure();
        Identity::assert_embedded_unique();
        Ledger::prune_dead_map();
        // Apply supplies the policy that was validated with its frozen
        // artifact under the promotion lease. Direct diagnostic callers do
        // not own that boundary and retain the historical load-on-entry path.
        $policy ??= Policy::load($repo);
        SidebarState::prune_dead_map($policy);
        $c = new self($repo, $policy);
        self::verify_engine_support($policy);
        // Apply/plan supply the immutable artifact already validated under
        // their promotion boundary, so their target snapshot never reopens
        // mutable state. Direct callers still compile here with a fresh
        // Policy object: DUO-3263 requires repository interpreter priming not
        // to contaminate the live-DB fallback used by $c->build() below.
        // DUO-3287: compile_for_diff() — this is the read-only drift-check
        // path (plan/status calling snapshot() with no pre-supplied
        // artifact); it must tolerate a repository revision captured under
        // an older, narrower policy exactly like Capture::run()'s own
        // previous-revision compile above. Verified live: `wp duo plan`
        // hit the identical false-positive as capture on the same
        // manifest-expansion scenario before this fix.
        $repository = $compiled ?? RepositoryCompiler::compile_for_diff($repo, Policy::load($repo));
        $repositoryOptions = self::repository_options($repo, $policy, $repository);
        Snapshot::prune_dead_map($policy, $repositoryOptions);
        $repositoryUserLogins = [];
        foreach ($repository->tree() as $entity) {
            if (($entity['type'] ?? '') === 'user-meta') {
                $repositoryUserLogins[] = (string) ($entity['data']['login'] ?? '');
            }
        }

        // DUO-3213: read-only (mint=false — build() never writes in this
        // mode), but still wrapped in the SAME consistent-snapshot
        // transaction as run() above. Apply::build_plan() diffs this
        // against the repo's own files for its three-way compare, and a
        // torn read here — half this build's SELECTs from before a
        // concurrent edit, half from after — could manufacture a bogus
        // plan/conflict/drift finding just as easily as an inconsistent
        // read could corrupt a captured tree. No capture lock needed: this
        // mode performs zero writes, so it can't collide with a concurrent
        // publisher's filesystem operations, and MVCC gives it a coherent
        // view regardless of what a concurrent capture() is doing on the
        // DB side.
        $build = self::run_in_consistent_snapshot(function () use (
            $c, $forceUnresolvedRefs, $repositoryOptions, $repositoryUserLogins
        ): array {
            Identity::assert_embedded_unique();
            $candidate = $c->build(false, $forceUnresolvedRefs, $repositoryOptions, $repositoryUserLogins);
            Identity::assert_entities_unique($candidate['entities']);
            return $candidate;
        });
        $out = [];
        foreach ($build['entities'] as $e) {
            // task #88: same derived-aware basis as run() above — this is
            // the env-side snapshot Apply::build_plan() diffs against the
            // repo file's own hash, so both sides must agree on what
            // "the same" means for a field a manifest classifies derived.
            $out[$e['uuid']] = [
                'type' => $e['type'],
                'hash' => hash('sha256', $e['hash_basis'] ?? $e['content']),
                'content' => $e['content'],
                'path' => $e['path'],
            ];
        }
        return $out;
    }

    /**
     * Build a production-export candidate inside RefreshExport's already
     * opened READ ONLY consistent snapshot.  This is intentionally not a
     * variation of snapshot(): that older diagnostic path is allowed to
     * reconcile legacy ledger rows for plan/apply, while a production export
     * must prove every identity was durable before it started observing.
     *
     * The caller owns transaction scope so the export can bind its lock,
     * completed-code receipt, maps, and authored records to one DB view.
     * No destination is opened, no publication/recovery helper is called,
     * and `$strictReadOnly` propagates to each identity-bearing capture
     * surface instead of treating a missing mapping as unmanaged content.
     */
    public static function build_read_only_export(
        string $repo,
        Policy $policy,
        ?array $previousOptions,
        array $previousUserLogins,
        bool $forceUnresolvedRefs = false
    ): array {
        Canary::suppress_cron_spawn();
        $c = new self($repo, $policy);
        return $c->build(
            false,
            $forceUnresolvedRefs,
            $previousOptions,
            $previousUserLogins,
            true
        );
    }

    /** Read-only preflight shared by RefreshExport's snapshot boundary. */
    public static function assert_read_only_export_engine_support(Policy $policy): void {
        self::verify_engine_support($policy);
    }

    /**
     * Capture only the canonical options/core document for a lifecycle
     * handoff. WordPress lifecycle hooks run before a plugin has necessarily
     * created its own tables (and after a plugin may have removed them), so a
     * full snapshot is the wrong boundary here: it would validate and walk
     * unrelated posts, terms, sidebars, and typed tables merely to compare
     * the one document whose hooks are allowed to change.
     *
     * This deliberately shares build_options(), the option discovery rules,
     * ref-token safety gates, Canon encoding, and the same consistent-read
     * transaction as snapshot(). Ledger/embedded-identity hygiene remains in
     * place because option references resolve through that identity map. The
     * only custom-table work here is the narrow liveness prune for
     * option_name_refs id_kinds; unrelated typed tables and their schema/data
     * capture stay outside this lifecycle boundary.
     *
     * @return array<string, array{type:string,hash:string,content:string,path:string}>
     */
    public static function snapshot_options_core(
        string $repo,
        bool $forceUnresolvedRefs = false,
        ?CompiledRepository $compiled = null,
        ?Policy $policy = null
    ): array {
        Canary::suppress_cron_spawn();
        Ledger::ensure();
        Identity::assert_embedded_unique();
        Ledger::prune_dead_map();
        // Deploy supplies the policy/artifact pair already validated under
        // its promotion lease. Direct callers retain snapshot()'s historical
        // load/compile fallback, but only the options entity is read below.
        $policy ??= Policy::load($repo);
        self::verify_options_engine_support($policy);
        $c = new self($repo, $policy);
        $repository = $compiled ?? RepositoryCompiler::compile_for_diff($repo, Policy::load($repo));
        $repositoryOptions = self::repository_options($repo, $policy, $repository);
        Snapshot::prune_option_name_ref_map($policy, $repositoryOptions);
        $repositoryValues = $repositoryOptions === null ? [] : OptionState::values($repositoryOptions);
        // DUO-3292: lifecycle snapshots straddle the theme switch itself.
        // Resolving an active-theme-bound option from the live PRE-switch stylesheet on
        // the first side and the desired POST-switch stylesheet on the
        // second side changes the canonical namespace mid-handoff: the
        // target theme_mods row appears as an unrelated authored mutation.
        // Pin that resolver to the frozen artifact instead. This is an
        // internal comparison boundary only; ordinary capture continues to
        // resolve against the live active stylesheet and therefore keeps
        // inactive theme_mods rows as residue, exactly as before.
        $dynamicResolverValues = [];
        if (isset($repositoryValues['stylesheet'])) {
            $dynamicResolverValues['active_stylesheet'] = (string) $repositoryValues['stylesheet'];
        }

        $document = self::run_in_consistent_snapshot(function () use (
            $c, $forceUnresolvedRefs, $repositoryOptions, $dynamicResolverValues
        ): array {
            Identity::assert_embedded_unique();
            return $c->build_options_only(
                $forceUnresolvedRefs,
                $repositoryOptions,
                $dynamicResolverValues,
                true
            );
        });
        $content = Canon::encode($document);
        return [
            'options/core' => [
                'type' => 'options',
                'hash' => hash('sha256', $content),
                'content' => $content,
                'path' => 'options/core.json',
            ],
        ];
    }

    /**
     * Read canonical option intent from the caller's frozen artifact when it
     * has one. Kept as a pure helper so the no-reopen invariant has a fast
     * offline regression independent of WordPress/DB snapshot mechanics.
     */
    private static function repository_options(
        string $repo,
        Policy $policy,
        ?CompiledRepository $compiled
    ): ?array {
        // DUO-3287: same compile_for_diff() reasoning as snapshot()'s own
        // fallback above — this helper exists specifically for that
        // no-pre-supplied-artifact path.
        $tree = $compiled !== null
            ? $compiled->tree()
            : RepositoryCompiler::compile_for_diff($repo, Policy::load($repo))->tree();
        $options = $tree['options/core']['data'] ?? null;
        return is_array($options) ? $options : null;
    }

    /**
     * Collect-only classification walk for `wp duo pending` (the review
     * queue's gate-item source, DESIGN.md 3.1.5): the same in-scope entities
     * and the same Policy rule lookups build() uses below — scope_posts(),
     * scope_terms(), post_meta_map() are literally the same private methods,
     * not reimplemented, so the two can never disagree about what "in
     * scope" or "classified" means. Every unclassified key is recorded as
     * evidence instead of aborting. Never mints uuids and never writes;
     * malformed or ambiguous manifest ownership still fails loudly.
     *
     * @return array{
     *   scope: array<string, array{entities:int}>,
     *   options: array<string, array{entities:int, owner_candidates:string[], value_shapes:string[], reason:string}>,
     *   post_meta: array<string, array{entities:int, post_types: string[]}>,
     *   term_meta: array<string, array{entities:int, taxonomies:string[], value_shapes:string[], reason:string}>,
     *   user_meta: array<string, array{entities:int, users:string[], value_shapes:string[], reason:string}>
     * }
     *
     * (Menu-item meta findings fold into post_meta above, tagged
     * 'nav_menu_item' in that entry's post_types — DUO-3275, no separate
     * menu_item_meta key.)
     */
    public static function gate_scan(string $repo): array {
        $c = new self($repo, Policy::load($repo));
        $scope = $c->scope_gaps();

        // A plugin manifest claims only its own option namespace. That
        // makes a full wp_options name scan complete for the claimed
        // surface without pretending Duo owns WordPress/core or another
        // plugin's unrelated rows. Exact and option_patterns rules resolve
        // candidates; a namespace match with no rule is a real unknown even
        // if the provenance journal was disabled or installed too late.
        global $wpdb;
        $options = [];
        $optionRows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name ASC",
            ARRAY_A
        ) ?: [];
        // DUO-3263: same option_name=>value map an interpreter's option_rule()
        // needs (a shadow-key lookup, exactly like post/term meta) — built
        // once from the rows already fetched above, not a second query.
        $allOptionValues = [];
        foreach ($optionRows as $row) {
            $allOptionValues[(string) $row['option_name']] = (string) $row['option_value'];
        }
        foreach ($optionRows as $row) {
            $key = (string) $row['option_name'];
            $owner = $c->policy->option_namespace($key);
            if ($owner === null || $c->policy->owned_option_rule_via_interpreter($key, $allOptionValues) !== null
                || $c->policy->match_option_name_ref($key) !== null) {
                continue;
            }
            $raw = (string) $row['option_value'];
            $value = PlainData::decode($raw, "option $key");
            $options[$key] = [
                'entities' => 1,
                'owner_candidates' => [$owner['owner']],
                'value_shapes' => [get_debug_type($value)],
                'reason' => 'owner namespace matched but no exact or pattern classification exists',
            ];
        }

        // DUO-3278's own widget-type registry (block/nav_menu/text at
        // shipping time), and DUO-3264's own corrected understanding of
        // this section's role — recorded with the correction included,
        // not just the final answer, because the walk-back is itself the
        // useful record for the next reader of this loop. This
        // $gate['widgets'] section is a DIAGNOSTIC ENRICHMENT, deliberately
        // never blocking on its own (nothing below merges it into
        // Capture::$unclassified — confirmed by grep, not assumed).
        // DUO-3264 first assumed (and shipped, briefly) a SECOND net one
        // layer down — a core.json option_namespaces declaration for
        // ^sidebars_widgets$/^widget_ plus per-name `runtime`
        // classifications — believing THAT was the universal blocking net
        // this section stayed informational alongside. Wrong, caught live
        // by that same declaration's own conformance sweep: the REAL,
        // sufficient, already-shipped blocking net is SidebarState::
        // capture()'s own load_widget_options() (this class, private
        // method) — an unconditional guard that refuses any widget_<type>
        // row with real instances and an undeclared type, running BEFORE
        // build_options() even executes in build()'s own call order. The
        // option_namespaces declaration was therefore provably unreachable
        // dead weight for this family and has been reverted (see
        // manifests/core.json's own note at dynamic_options for the full
        // evolution) — this diagnostic section needed no companion net; it
        // already had one, one file over, the whole time. It stays
        // informational because SidebarState's own guard already enforces;
        // this only adds per-sidebar/per-instance detail a bare exception
        // message can't carry.
        //
        // Second, separate finding while verifying the above (DUO-3283,
        // filed as a closed record — the fix rode this same PR, not a
        // follow-up): SidebarState::load_widget_options()'s own guard
        // originally advertised a remedy — classify options.widget_<type>
        // =runtime as a deliberate exclusion — that did not function; the
        // guard only ever consulted widget_types() (this manifest key),
        // never options.* classifications. Live-verified before any fix
        // existed: the classification did nothing, capture refused again.
        // Fixed AT THAT GUARD (not here): Policy is now threaded into
        // load_widget_options(), which treats an explicit runtime/env
        // options classification as first-class acknowledgment, the same
        // tier as a widgets{} entry — see that method's own docblock for
        // the full behavioral addition. Noted here too because this
        // section's own reasoning above ("SidebarState's own guard already
        // enforces") depends on that guard's remedies actually working,
        // which is no longer merely asserted.
        $widgetTypes = $c->policy->widget_types();
        $widgets = [];
        foreach ($optionRows as $row) {
            $name = (string) $row['option_name'];
            if (!str_starts_with($name, 'widget_')) {
                continue;
            }
            $type = substr($name, 7);
            $raw = (string) $row['option_value'];
            $value = PlainData::decode($raw, "option $name");
            if (!is_array($value)) {
                $widgets[$type] = [
                    'entities' => 1, 'value_shapes' => [get_debug_type($value)],
                    'reason' => "widget option '$name' is not a multi-instance array",
                ];
                continue;
            }
            $instances = array_filter(
                $value,
                static fn($settings, $key): bool => (string) $key !== '_multiwidget',
                ARRAY_FILTER_USE_BOTH
            );
            if ($instances && !isset($widgetTypes[$type])) {
                $widgets[$type] = [
                    'entities' => count($instances), 'value_shapes' => ['multi-instance array'],
                    'reason' => 'live widget instances exist but no pinned manifest declares this widget type',
                ];
            }
        }

        $postMeta = [];
        foreach ($c->scope_posts() as $p) {
            $flatMeta = $c->post_meta_map((int) $p->ID);
            foreach ($flatMeta as $key => $_) {
                if ($key === '_wp_attached_file' || $key === '_wp_attachment_image_alt') {
                    continue; // handled as dedicated front-matter fields, never generic meta
                }
                if ($c->policy->meta_rule_for_post($key, $flatMeta) !== null) {
                    continue;
                }
                $postMeta[$key]['entities'] = ($postMeta[$key]['entities'] ?? 0) + 1;
                $postMeta[$key]['post_types'][$p->post_type] = true;
            }
        }

        $termMeta = [];
        foreach ($c->scope_terms() as $t) {
            $flatMeta = $c->term_meta_map((int) $t->term_id);
            foreach ($flatMeta as $key => $raw) {
                $rule = $c->policy->meta_rule_for_term($key, $flatMeta);
                if ($rule !== null) {
                    continue;
                }
                $termMeta[$key]['entities'] = ($termMeta[$key]['entities'] ?? 0) + 1;
                $termMeta[$key]['taxonomies'][$t->taxonomy] = true;
                $value = PlainData::decode($raw, "term meta $key");
                $termMeta[$key]['value_shapes'][get_debug_type($value)] = true;
                $termMeta[$key]['reason'] = 'unclassified term meta on an in-scope taxonomy';
            }
        }

        // DUO-3266/DUO-3275: menu items are posts (nav_menu_item) but never
        // reach scope_posts()'s generic post_meta scan above — they're
        // handled by the dedicated scope_menus() capture path instead.
        // Before DUO-3266, that meant menu-item meta had NO discovery-time
        // visibility at all: an unclassified plugin-added key on a menu
        // item silently vanished with no wp duo pending entry, no warning,
        // nothing. DUO-3266's first pass fixed that but invented a
        // SEPARATE 'menu_item_meta' discovery section — which broke the
        // invariant every OTHER pending section already had (the section
        // name pending shows IS the real, copy-pasteable
        // Policy::SECTIONS name `wp duo classify --set` needs):
        // 'menu_item_meta' was never a member of Policy::SECTIONS, so
        // classifying exactly what pending suggested hard-refused with
        // "unknown policy section". Menu-item meta and ordinary post_meta
        // are not actually separate classification domains — nav_menu_item
        // IS a real post_type, and both go through the identical
        // Policy::meta_rule_for_post()/policy.post_meta namespace — so
        // DUO-3275 folds this scan into the SAME $postMeta structure
        // above instead, tagging 'nav_menu_item' into that entry's own
        // post_types set exactly like any other post type would appear.
        // A direct, read-only query here (no scope_menus(), which mints
        // identity — a side effect a pure dry-run scan must not have).
        // The 8 core _menu_item_* keys are classified "managed"/"runtime"
        // in manifests/core.json (never null, never 'authored'), so
        // they're skipped here for free — no separate allowlist needed,
        // same as the live capture-time path.
        $menuItemIds = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tt.taxonomy = 'nav_menu' AND p.post_type = 'nav_menu_item' AND p.post_status = 'publish'"
        ) ?: [];
        foreach ($menuItemIds as $iid) {
            $flatMeta = $c->post_meta_map((int) $iid);
            foreach ($flatMeta as $key => $_) {
                if ($c->policy->meta_rule_for_post($key, $flatMeta) !== null) {
                    continue;
                }
                $postMeta[$key]['entities'] = ($postMeta[$key]['entities'] ?? 0) + 1;
                $postMeta[$key]['post_types']['nav_menu_item'] = true;
            }
        }

        // Users remain environment-local and unscoped, so an unclassified
        // user-meta key is not implicitly claimed. Authored rules now have a
        // real login-keyed sidecar and therefore are no longer pending gate
        // findings; value-level PII/secret refusals surface during capture.
        $userMeta = [];

        return [
            'scope' => $scope,
            'options' => $options,
            'widgets' => $widgets,
            'post_meta' => array_map(
                fn($ev) => ['entities' => $ev['entities'], 'post_types' => array_keys($ev['post_types'] ?? [])],
                $postMeta
            ),
            'term_meta' => array_map(
                fn($ev) => [
                    'entities' => $ev['entities'],
                    'taxonomies' => array_keys($ev['taxonomies'] ?? []),
                    'value_shapes' => array_keys($ev['value_shapes'] ?? []),
                    'reason' => $ev['reason'],
                ],
                $termMeta
            ),
            'user_meta' => array_map(
                fn($ev) => [
                    'entities' => $ev['entities'],
                    'users' => array_keys($ev['users'] ?? []),
                    'value_shapes' => array_keys($ev['value_shapes'] ?? []),
                    'reason' => $ev['reason'],
                ],
                $userMeta
            ),
        ];
    }

    // ------------------------------------------------------------------
    // DUO-3213: consistent-snapshot transaction helpers (run()/snapshot())
    // ------------------------------------------------------------------

    /**
     * The consistent-snapshot transaction above only means what it claims
     * on InnoDB (MVCC + undo logs give every SELECT in the transaction one
     * stable point-in-time view). MyISAM (WordPress's historical default
     * on some old hosts, and occasionally hand-picked per table) has
     * neither — a plain SELECT there always reads the latest committed
     * data regardless of any surrounding transaction, so `START
     * TRANSACTION WITH CONSISTENT SNAPSHOT` would silently give NO real
     * isolation guarantee for it. Rather than claim a guarantee the
     * storage engine can't back — a "warning-only correctness failure,"
     * exactly what the project constitution rules out — this refuses
     * loudly and names every offending table before any read happens.
     *
     * Checked against every table Capture's own build() reads from
     * directly, Ledger's own tables (duo_map/duo_state/duo_kv — created by
     * Ledger::ensure(), already run by the time this is called), and every
     * manifest-declared custom table (Snapshot::capture() reads those
     * too). A declared table that doesn't exist on this environment (its
     * plugin isn't installed here) is silently skipped — mirroring
     * Snapshot.php's own tolerant `SHOW TABLES LIKE` pattern: there's
     * nothing to protect if there's no table.
     */
    private static function verify_engine_support(Policy $policy): void {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $tables = [
            $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy,
            $wpdb->term_relationships, $wpdb->termmeta, $wpdb->options, $wpdb->users,
            $wpdb->usermeta,
            $prefix . 'duo_map', $prefix . 'duo_state', $prefix . 'duo_kv',
        ];
        foreach (array_keys($policy->declared_tables()) as $name) {
            $tables[] = $prefix . preg_replace('/[^A-Za-z0-9_]/', '', $name);
        }
        $tables = array_values(array_unique($tables));

        $placeholders = implode(',', array_fill(0, count($tables), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)",
            $tables
        ), ARRAY_A) ?: [];

        $bad = [];
        foreach ($rows as $r) {
            $engine = strtoupper((string) ($r['ENGINE'] ?? ''));
            if ($engine !== '' && $engine !== 'INNODB') {
                $bad[] = "{$r['TABLE_NAME']} (engine: $engine)";
            }
        }
        if ($bad) {
            sort($bad);
            throw new \RuntimeException(
                'duo: capture refused — consistent-snapshot isolation requires InnoDB, but the following table(s) '
                . "capture reads from use a different storage engine (no MVCC/undo log, so a consistent-snapshot "
                . "transaction gives no real point-in-time guarantee for them):\n  - " . implode("\n  - ", $bad)
                . "\nConvert the table(s) to InnoDB (e.g. ALTER TABLE <table> ENGINE=InnoDB) and re-run capture."
            );
        }
    }

    /**
     * Runs $fn() inside one InnoDB consistent-read transaction so every
     * SELECT it issues (build() performs many, across posts/terms/menus/
     * options/tables) sees one coherent point-in-time view — "a candidate
     * tree assembled from different moments" is the exact failure mode
     * this whole mechanism exists to close. $wpdb never throws on a failed
     * query (see check_transient_db_error()'s docblock), so a deadlock or
     * lock-wait timeout only surfaces at the checkpoints this class
     * explicitly checks.
     *
     * Bounded retry: those two specific errors are the standard signature
     * of a concurrent, ordinary WordPress write losing a race with one of
     * THIS build's own mutating statements (the _duo_uuid mint inserts;
     * Snapshot::capture()'s typed-snapshot writes) — transient by nature,
     * and the standard fix is "roll back, retry the whole transaction from
     * a fresh snapshot." Any OTHER \Throwable — every existing loud-and-
     * blocking gate in build() included — is a real, deterministic failure
     * and is never retried; retrying it would just burn attempts
     * reproducing the identical failure. A transient error reported by the
     * post-COMMIT checkpoint is also never retried: COMMIT has already
     * returned, so its outcome may be durable and replaying the callback could
     * duplicate a committed identity/state mutation.
     */
    private static function run_in_consistent_snapshot(callable $fn, ?array &$phase = null) {
        $phase ??= [];
        $attempt = 0;
        while (true) {
            $attempt++;
            $transactionOpen = false;
            $commitAttempted = false;
            // A retry is safe only before the filesystem swap boundary. The
            // callback sets this immediately before swap(); once true, a
            // transient DB error must fail closed instead of rebuilding or
            // replaying a candidate against a tree that may already be new.
            $phase['filesystem_swapped'] = false;
            try {
                Db::query('START TRANSACTION WITH CONSISTENT SNAPSHOT', 'capture transaction start');
                // Mark the transaction open immediately after query() returns:
                // the following checkpoint can still report a transient
                // driver error even though START succeeded and therefore
                // needs a rollback before retrying.
                $transactionOpen = true;
                self::check_transient_db_error('START TRANSACTION WITH CONSISTENT SNAPSHOT');
                $result = $fn();
                if (isset($phase['state_dir'], $phase['intent'])
                    && is_string($phase['state_dir']) && is_array($phase['intent'])) {
                    // The durable `committing` marker is written before the
                    // client issues COMMIT. Recovery may therefore restore a
                    // swapped tree in `ready`/`swapped` states, but must
                    // refuse to guess once this marker exists.
                    $phase['intent'] = Publish::mark_committing($phase['state_dir'], $phase['intent']);
                }
                $commitAttempted = true;
                Db::commit('capture transaction commit');
                // A successful COMMIT closes the transaction even if its
                // post-query error checkpoint reports a stale driver message;
                // never issue ROLLBACK after that commit. If the checkpoint
                // does report an error, the commit outcome is ambiguous: the
                // callback must not be retried because the database may have
                // accepted its writes already.
                $transactionOpen = false;
                try {
                    self::check_transient_db_error('COMMIT');
                } catch (\Throwable $commitCheck) {
                    throw new \RuntimeException(
                        'duo: capture commit outcome uncertain — COMMIT returned, but its database error '
                        . 'checkpoint failed; refusing to retry because the candidate may already be durable',
                        0,
                        $commitCheck
                    );
                }
                return $result;
            } catch (TransientDbException $e) {
                if ($commitAttempted) {
                    // Db::commit() can throw when the server accepted or
                    // rejected COMMIT; the client cannot distinguish those
                    // outcomes. Never retry or issue a compensating
                    // rollback after crossing that boundary.
                    throw new \RuntimeException(
                        'duo: capture commit outcome uncertain — COMMIT did not return a definitive success; '
                        . 'refusing to retry because candidate DML may already be durable',
                        0,
                        $e
                    );
                }
                if ($transactionOpen) {
                    Db::rollback('capture transaction rollback');
                }
                if (!empty($phase['filesystem_swapped'])) {
                    throw new \RuntimeException(
                        'duo: capture failed after filesystem publication began — refusing to retry the candidate; '
                        . 'the next run must reconcile its durable intent/backup artifacts',
                        0,
                        $e
                    );
                }
                if ($attempt >= self::MAX_DB_ATTEMPTS) {
                    throw new \RuntimeException(
                        "duo: capture failed after $attempt attempt(s) — repeated transient database contention "
                        . "(a concurrent WordPress write kept colliding with capture's own identity-minting "
                        . 'writes): ' . $e->getMessage()
                    );
                }
                usleep(200_000 * $attempt); // 200ms, 400ms, ... — short: this targets brief lock contention, not an outage
                continue;
            } catch (\Throwable $t) {
                if ($commitAttempted) {
                    throw new \RuntimeException(
                        'duo: capture commit outcome uncertain — COMMIT returned no definitive success; '
                        . 'refusing to retry because candidate DML may already be durable',
                        0,
                        $t
                    );
                }
                if ($transactionOpen) {
                    Db::rollback('capture transaction rollback');
                }
                throw $t;
            }
        }
    }

    /**
     * DUO-3213's "documented retry" — see run_in_consistent_snapshot()'s
     * docblock for the full rationale. $wpdb never throws on a failed
     * query: it records the driver's error string into $wpdb->last_error
     * and returns false/null instead, so this is the one signal available
     * in addition to the checked Db mutation layer. Db promotes false
     * mutation results immediately (including transient contention), while
     * these checkpoints also catch a driver error left by a read or by a
     * nested operation whose public contract does not expose its result.
     *
     * Matches literal MySQL/MariaDB error text for errno 1213 (deadlock)
     * and 1205 (lock wait timeout) — stable across server versions, and
     * avoids depending on $wpdb->dbh's concrete driver type to extract a
     * numeric errno.
     *
     * Any OTHER SQL error at these checkpoints is treated as real, not
     * transient: silently continuing past a mutation that didn't do what
     * the code assumed is exactly the kind of half-consistent state this
     * issue exists to prevent, so it throws immediately, non-retryably.
     */
    private static function check_transient_db_error(string $where): void {
        global $wpdb;
        $err = (string) $wpdb->last_error;
        if ($err === '') {
            return;
        }
        if (stripos($err, 'Deadlock found') !== false || stripos($err, 'Lock wait timeout') !== false) {
            throw new TransientDbException("duo: transient DB contention at $where");
        }
        throw new \RuntimeException("duo: unexpected SQL error at $where");
    }

    /** Resolve lexical/symlink variants to one stable destination identity. */
    private static function publication_destination_sha256(string $stateDir): string {
        $parent = realpath(dirname($stateDir));
        if ($parent === false) {
            throw new \RuntimeException('duo: cannot resolve capture publication destination parent: ' . dirname($stateDir));
        }
        return hash('sha256', rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($stateDir));
    }

    /** Destination-scoped marker key stays well below duo_kv.k's 191-byte limit. */
    private static function publication_marker_key(string $stateDir): string {
        return 'capture_publication:' . self::publication_destination_sha256($stateDir);
    }

    /**
     * Exact/self-hashed DB commit proof. It is written as the final DML in
     * the capture transaction; rollback therefore removes it automatically.
     */
    private static function publication_marker(string $stateDir, array $intent): string {
        $record = [
            'format' => 'duo-capture-commit-marker/v1',
            'state_sha256' => self::publication_destination_sha256($stateDir),
            'intent_id' => (string) ($intent['id'] ?? ''),
            'candidate_sha256' => (string) ($intent['candidate_sha256'] ?? ''),
            'previous_sha256' => (string) ($intent['previous_sha256'] ?? ''),
        ];
        $record['record_sha256'] = hash('sha256', Canon::encode($record));
        return Canon::encode($record);
    }

    /**
     * Return true only for a valid marker belonging to THIS intent. A missing
     * or prior-run marker is a definitive rollback signal; malformed or
     * current-but-mismatched data is fail-closed corruption.
     */
    private static function publication_commit_status(string $stateDir, array $intent): bool {
        $raw = Ledger::kv_get(self::publication_marker_key($stateDir));
        if ($raw === null || $raw === '') {
            return false;
        }
        try {
            $record = Canon::decode($raw);
        } catch (\Throwable $e) {
            throw new \RuntimeException('duo: capture recovery found malformed database commit marker', 0, $e);
        }
        if (!is_array($record)) {
            throw new \RuntimeException('duo: capture recovery found a non-object database commit marker');
        }
        $expectedKeys = ['format', 'state_sha256', 'intent_id', 'candidate_sha256', 'previous_sha256', 'record_sha256'];
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        $sortedExpected = $expectedKeys;
        sort($sortedExpected, SORT_STRING);
        if ($keys !== $sortedExpected || ($record['format'] ?? null) !== 'duo-capture-commit-marker/v1') {
            throw new \RuntimeException('duo: capture recovery found an unsupported database commit marker shape');
        }
        $seal = (string) $record['record_sha256'];
        unset($record['record_sha256']);
        if (!preg_match('/^[a-f0-9]{64}$/', $seal)
            || !hash_equals($seal, hash('sha256', Canon::encode($record)))) {
            throw new \RuntimeException('duo: capture recovery found a tampered database commit marker');
        }
        if (!is_string($record['state_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $record['state_sha256']) !== 1
            || !is_string($record['intent_id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/', $record['intent_id']) !== 1
            || !is_string($record['candidate_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $record['candidate_sha256']) !== 1
            || !is_string($record['previous_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $record['previous_sha256']) !== 1) {
            throw new \RuntimeException('duo: capture recovery found malformed database commit marker fields');
        }
        if (($record['state_sha256'] ?? null) !== self::publication_destination_sha256($stateDir)) {
            throw new \RuntimeException('duo: capture recovery found a commit marker for a different destination');
        }
        if (($record['intent_id'] ?? null) !== ($intent['id'] ?? null)) {
            return false; // a prior capture's marker; not proof for this intent
        }
        if (($record['candidate_sha256'] ?? null) !== ($intent['candidate_sha256'] ?? null)
            || ($record['previous_sha256'] ?? null) !== ($intent['previous_sha256'] ?? null)) {
            throw new \RuntimeException('duo: capture recovery found a current-intent commit marker with mismatched digests');
        }
        return true;
    }

    /** Commit proof is needed only while filesystem recovery artifacts exist. */
    private static function clear_publication_marker_if_clean(string $stateDir): void {
        if (is_file(Publish::intent_path($stateDir))
            || is_dir(Publish::stage_dir($stateDir))
            || is_dir(Publish::backup_dir($stateDir))) {
            return;
        }
        Ledger::kv_delete(self::publication_marker_key($stateDir));
    }

    // ------------------------------------------------------------------

    /** Reset per-build capture state shared by full and options-only paths. */
    private function reset_build_state(bool $forceUnresolvedRefs): void {
        $this->unclassified = [];
        $this->unscopedRefs = [];
        $this->unscopedOptionNameRefs = [];
        // Blocks.php/Shortcodes.php have no persistent instance state of
        // their own (see their docblocks), so their unscoped queues live on
        // Tokens rather than a Capture-level array. Reset them here too: an
        // options-only snapshot must not inherit findings from another build.
        $this->tokens->unscopedBlockRefs = [];
        $this->tokens->unscopedShortcodeRefs = [];
        $this->tokens->unscopedUrlQueryRefs = [];
        // tokenize_text() reads these values for its unscoped URL-ref gate;
        // set them once per build, just as the policy is set by the ctor.
        $this->tokens->forceUnresolvedRefs = $forceUnresolvedRefs;
    }

    /**
     * Options-only build used by lifecycle handoff snapshots. It executes
     * the same option gates as full build(), but never enters any
     * post/term/menu/sidebar/table path.
     */
    private function build_options_only(
        bool $forceUnresolvedRefs,
        ?array $previousOptions = null,
        array $dynamicResolverValues = [],
        bool $bindMissingDynamicDesired = false
    ): array {
        $this->reset_build_state($forceUnresolvedRefs);
        $options = $this->build_options(
            false,
            $forceUnresolvedRefs,
            $previousOptions,
            $dynamicResolverValues,
            $bindMissingDynamicDesired
        );
        $this->assert_option_gates();
        return $options;
    }

    /** Exact option discovery/ref safety gates shared with full build(). */
    private function assert_option_gates(): void {
        if ($this->unclassified) {
            $keys = array_unique($this->unclassified);
            sort($keys);
            throw new \RuntimeException(
                "duo: incomplete state discovery on manifest-owned or in-scope surfaces (loud-and-blocking gate):\n  - "
                . implode("\n  - ", $keys)
                . "\nClassify them in site.duo.json policy.options / policy.post_meta / policy.term_meta or a manifest."
                . " Run: wp duo pending --repo={$this->repo} for evidence + proposals, then wp duo classify --repo={$this->repo} --set '<section>:<key>=<class>'."
            );
        }
        if ($this->unscopedRefs) {
            $lines = [];
            foreach ($this->unscopedRefs as $r) {
                $scopeKey = $r['kind'] === 'term' ? 'policy.taxonomies' : 'policy.post_types';
                $lines[] = "option '{$r['option']}' references {$r['kind']} id {$r['id']}, which is a real "
                    . "'{$r['target_type']}' — but '{$r['target_type']}' is not in $scopeKey, so its identity was "
                    . 'never tracked and the reference cannot resolve';
            }
            throw new \RuntimeException(
                "duo: unresolvable ref-typed option(s) point at real, out-of-scope entities (loud-and-blocking gate):\n  - "
                . implode("\n  - ", $lines)
                . "\nThis differs from a dangling reference (deleted target — dropped with a warning, unchanged): the "
                . "target genuinely exists right now, so this is a scope gap, not permanent data loss.\n"
                . "Add the missing post type/taxonomy to policy scope above and re-run capture, or reclassify the "
                . "option, or pass --force-unresolved-refs to drop it anyway (same as a dangling reference)."
            );
        }
        if ($this->unscopedOptionNameRefs) {
            $lines = [];
            foreach ($this->unscopedOptionNameRefs as $r) {
                $lines[] = "option '{$r['option']}' embeds {$r['id_kind']} id {$r['id']}, which is a real row in "
                    . "its declared table — but that table's rows were never minted a uuid (not pinned as "
                    . "authored_snapshot in a currently-loaded manifest?), so the reference cannot resolve";
            }
            throw new \RuntimeException(
                "duo: option_name_refs option(s) point at real, unminted table rows (loud-and-blocking gate):\n  - "
                . implode("\n  - ", $lines)
                . "\nThis differs from a dangling reference (no such row anywhere — dropped with a warning, "
                . "unchanged): the row genuinely exists right now, so this is a manifest/table-pinning gap, not "
                . "permanent data loss.\nPin the owning table as authored_snapshot and re-run capture, or pass "
                . "--force-unresolved-refs to drop it anyway (same as a dangling reference)."
            );
        }
        // URL-query refs can be discovered while tokenizing an authored
        // option value (not only post/menu content), so this gate belongs to
        // the shared option boundary as well as the full build.
        if ($this->tokens->unscopedUrlQueryRefs) {
            $lines = [];
            foreach ($this->tokens->unscopedUrlQueryRefs as $r) {
                $where = $r['context'] !== '' ? "{$r['context']}: " : '';
                $lines[] = "{$where}url query ref '{$r['param']}' references post id {$r['id']}, which is a real "
                    . "'{$r['target_type']}' — but '{$r['target_type']}' is not in policy.post_types, so its "
                    . "identity was never tracked and the reference cannot resolve";
            }
            throw new \RuntimeException(
                "duo: unresolvable url-query-typed reference(s) point at real, out-of-scope entities (loud-and-blocking gate):\n  - "
                . implode("\n  - ", $lines)
                . "\nThis differs from a dangling reference (deleted target — dropped with a warning, unchanged): the "
                . "target genuinely exists right now, so this is a policy scope gap, not permanent data loss.\n"
                . "Add the missing post type to policy scope above and re-run capture, or pass "
                . "--force-unresolved-refs to drop it anyway (same as a dangling reference)."
            );
        }
    }

    /** @return array{
     *   entities: array,
     *   media: array<string,array{path?:string,bytes?:string}>,
     *   notes: string[],
     *   warnings: string[]
     * } */
    private function build(
        bool $mint,
        bool $forceUnresolvedRefs = false,
        ?array $previousOptions = null,
        array $carriedUserLogins = [],
        bool $strictReadOnly = false
    ): array {
        global $wpdb;
        $this->reset_build_state($forceUnresolvedRefs);
        $entities = [];
        $media = [];

        // ---- scope ----
        $scopeGaps = $this->scope_gaps();
        if ($scopeGaps) {
            $lines = [];
            foreach ($scopeGaps as $key => $evidence) {
                [$kind, $name] = explode(':', $key, 2);
                $policyKey = $kind === 'post_type' ? 'policy.post_types' : 'policy.taxonomies';
                $noun = $evidence['entities'] === 1 ? 'entity' : 'entities';
                $lines[] = "$kind '$name' has {$evidence['entities']} capturable $noun but is absent from $policyKey; "
                    . "include it there, or record a deliberate exclusion with scope:$kind:$name=runtime|derived|env";
            }
            throw new \RuntimeException(
                "duo: registered or adapter-declared authored state exists outside policy scope (loud-and-blocking gate):\n  - "
                . implode("\n  - ", $lines)
                . "\nRun: wp duo pending --repo={$this->repo} for evidence, then either add the type/taxonomy "
                . "to policy scope or run wp duo classify --repo={$this->repo} --set='scope:<kind>:<name>=<class>'."
            );
        }
        $posts = $this->scope_posts();
        $terms = $this->scope_terms();
        $taxesByObjectType = $this->taxes_by_object_type($this->policy->taxonomies(), $this->policy->post_types());
        $this->taxesForPostType = $taxesByObjectType['by_post_type'];
        $this->termObjectTaxes = $taxesByObjectType['term_object'];

        // ---- identity ----
        $postUuids = [];
        foreach ($posts as $p) {
            $uuid = $this->ensure_post_uuid((int) $p->ID, 'post', $mint, $strictReadOnly);
            if ($uuid !== null) {
                $postUuids[(int) $p->ID] = $uuid;
            }
        }
        $termUuids = [];
        foreach ($terms as $t) {
            $uuid = $this->ensure_term_uuid($t, 'term', $mint, $strictReadOnly);
            if ($uuid !== null) {
                $termUuids[(int) $t->term_id] = $uuid;
            }
        }

        $menus = $this->scope_menus($mint, $strictReadOnly);

        // Spec v2 term files carry authored meta. Unknown term-meta still
        // blocks loudly; every classified non-authored disposition remains
        // target-local and every classified authored key is captured below.
        foreach ($terms as $t) {
            $flatMeta = $this->term_meta_map((int) $t->term_id);
            foreach ($flatMeta as $key => $_) {
                $rule = $this->policy->meta_rule_for_term($key, $flatMeta);
                if ($rule === null) {
                    $this->unclassified[] = "term_meta:$key (unclassified on taxonomy {$t->taxonomy})";
                }
            }
        }

        // ---- table rows (typed snapshot; agent/src/Snapshot.php, task #75) ----
        // Declared authored_snapshot tables (Ninja Forms' nf3_forms/nf3_fields/
        // nf3_actions + their _meta twins, WooCommerce's woocommerce_attribute_
        // taxonomies, ...) — a manifest pinning one is both necessary and
        // sufficient to activate it, same as authored_options() needs no
        // separate site-policy scope toggle (see manifests/ninja-forms.json's
        // own note on why this differs from Polylang's taxonomy-scoping trap).
        // Schema-completeness ("every live column must be declared") is
        // enforced inside Snapshot::capture() itself, loudly, before any row
        // is read — the same posture as the unclassified-meta gate below.
        //
        // Deliberately run HERE — after post/term/menu IDENTITY minting
        // above, but BEFORE any post body is actually built below — because
        // a manifest may declare a block_attrs rule pointing at a table's own
        // id_kind (e.g. Ninja Forms' `ninja-forms/form` block's "formID"
        // attribute, ref kind "nf3_form": manifests/ninja-forms.json). That
        // rule resolves through Tokens::id_to_token(), which needs this
        // table's rows already minted into duo_map; capturing tables only
        // AFTER build_post()'s Blocks::capture_rewrite() calls would silently
        // leave every such block attribute as a raw, unrewritten local id —
        // caught the hard way running this exact fixture, not designed in
        // from the start. $tableEntities is merged into $entities below,
        // after post files — its POSITION in the array is cosmetic; only the
        // TIMING of the capture() call itself (identity side effects) matters.
        $tableEntities = Snapshot::capture($this->policy, $this->tokens, $mint, $strictReadOnly);
        self::check_transient_db_error('Snapshot::capture()'); // DUO-3213 checkpoint — see its docblock

        // Widget block content uses the same block-ref grammar as posts, so
        // capture it only after core and typed-table identities are complete.
        $sidebarBuild = SidebarState::capture(
            $this->policy, $this->tokens, $mint, $forceUnresolvedRefs, $strictReadOnly
        );

        // ---- term files ----
        foreach ($terms as $t) {
            $uuid = $termUuids[(int) $t->term_id] ?? null;
            if ($uuid === null) {
                continue;
            }
            $parentUuid = null;
            if ((int) $t->parent > 0) {
                $parentUuid = Ledger::uuid_for((int) $t->parent, Ledger::KIND_TERM);
                if ($parentUuid === null) {
                    $this->tokens->warnings[] = "term {$t->slug}: unmanaged parent term {$t->parent} dropped";
                }
            }
            $termByKey = $this->term_meta_by_key((int) $t->term_id);
            $termFlatMeta = array_map(fn($values) => $values[0], $termByKey);
            $termMeta = [];
            foreach ($termByKey as $key => $values) {
                [$store, $value] = $this->classify_meta_value(
                    $key,
                    $values,
                    $termFlatMeta,
                    "term {$t->taxonomy}:{$t->slug}",
                    'term_meta',
                    true
                );
                if ($store) {
                    $termMeta[$key] = $value;
                }
            }
            $front = [
                'uuid' => $uuid,
                'taxonomy' => $t->taxonomy,
                'name' => $t->name,
                'slug' => $t->slug,
                'description' => $this->term_description($t),
                'parent' => $parentUuid,
                'meta' => (object) $termMeta,
                'relationships' => (object) $this->term_relationships((int) $t->term_id),
            ];
            $entities[] = [
                'uuid' => $uuid,
                'type' => 'term',
                'path' => "terms/{$t->taxonomy}/{$uuid}--{$t->slug}.json",
                'content' => Canon::encode($front),
            ];
        }

        // ---- post files ----
        foreach ($posts as $p) {
            $id = (int) $p->ID;
            $uuid = $postUuids[$id] ?? null;
            if ($uuid === null) {
                continue;
            }
            [$front, $body, $mediaRef] = $this->build_post($p, $uuid, $forceUnresolvedRefs);
            if ($mediaRef !== null) {
                $media[$mediaRef[0]] = $mediaRef[1];
            }
            $entities[] = [
                'uuid' => $uuid,
                'type' => 'post',
                'path' => "posts/{$p->post_type}/{$uuid}--{$p->post_name}.md",
                'content' => Canon::post_file($front, $body),
                // task #88: the DRIFT/PLAN hash basis, not necessarily the
                // same bytes as 'content' above — a post_type may classify
                // a field 'derived' (e.g. product_variation's title), which
                // stays in 'content' verbatim but is excluded from what
                // gets hashed below, so self-heal timing alone never reads
                // as authored change. See Canon::post_hash_basis().
                'hash_basis' => Canon::post_hash_basis($front, $body, $this->policy),
            ];
        }

        // ---- menu files ----
        foreach ($menus as $menu) {
            $entities[] = [
                'uuid' => $menu['uuid'],
                'type' => 'menu',
                'path' => "menus/{$menu['slug']}.json",
                'content' => Canon::encode($menu['front']),
            ];
        }

        foreach ($sidebarBuild['entities'] as $sidebarEntity) {
            $entities[] = $sidebarEntity;
        }

        // ---- options file ----
        $options = $this->build_options($mint, $forceUnresolvedRefs, $previousOptions, [], false, $strictReadOnly);
        $entities[] = [
            'uuid' => 'options/core',
            'type' => 'options',
            'path' => 'options/core.json',
            'content' => Canon::encode($options),
        ];

        foreach ($tableEntities as $e) {
            $entities[] = $e;
        }

        // Users themselves remain environment-local and receive no UUID or
        // duo_map row. Only explicitly-authored metadata is emitted, keyed
        // by the exact login. A previously/repository-present login is
        // carried even when its authored map is now empty, making removal
        // of the final owned key explicit without treating user deletion as
        // portable authority.
        foreach ($this->build_user_meta_entities($carriedUserLogins) as $e) {
            $entities[] = $e;
        }

        $this->assert_option_gates();

        // Block refs' own unscoped gate (DUO-3212, task #73's mirror for
        // "kind"/"kind_from" block_attrs refs and the wp-image-N class
        // rewrite — both funnel through Blocks::queue_unscoped()): the id
        // names a REAL row whose post_type/taxonomy simply isn't in policy
        // scope, as opposed to a dangling reference (deleted target) or a
        // real row of an in-scope type simply not minted on this build yet
        // — both of those are handled by Blocks::walk()'s ordinary warn-
        // and-drop, never reaching this list. Same posture as the two
        // option gates above: a policy edit can actually fix this, so it
        // aborts by default instead of silently vanishing from captured
        // state. Accumulates across every post in this build (Tokens::
        // $unscopedBlockRefs, not a per-post-reset array) the same way
        // $this->unscopedRefs accumulates across every option above.
        if ($this->tokens->unscopedBlockRefs) {
            $lines = [];
            foreach ($this->tokens->unscopedBlockRefs as $r) {
                $scopeKey = $r['kind'] === 'term' ? 'policy.taxonomies' : 'policy.post_types';
                $lines[] = "{$r['post']} block '{$r['block']}' attribute '{$r['attr']}' references {$r['kind']} id "
                    . "{$r['id']}, which is a real '{$r['target_type']}' — but '{$r['target_type']}' is not in "
                    . "$scopeKey, so its identity was never tracked and the reference cannot resolve";
            }
            throw new \RuntimeException(
                "duo: unresolvable ref-typed block attribute(s) point at real, out-of-scope entities (loud-and-blocking gate):\n  - "
                . implode("\n  - ", $lines)
                . "\nThis differs from a dangling reference (deleted target — dropped with a warning, unchanged): the "
                . "target genuinely exists right now, so this is a policy scope gap, not permanent data loss.\n"
                . "Add the missing post type/taxonomy to policy scope above and re-run capture, or pass "
                . "--force-unresolved-refs to drop it anyway (same as a dangling reference)."
            );
        }

        // Shortcode refs' own unscoped gate (DUO-3259, task #73's mirror a
        // second time — for `shortcode_attrs` refs, funneled through
        // Shortcodes::queue_unscoped()): the id names a REAL row whose
        // post_type/taxonomy simply isn't in policy scope, as opposed to a
        // dangling reference (deleted target) or a real row of an in-scope
        // type simply not minted on this build yet — both of those are
        // handled by Shortcodes.php's ordinary warn-and-drop, never
        // reaching this list. Same posture as the option/block gates
        // above: a policy edit can actually fix this, so it aborts by
        // default instead of silently vanishing from captured state.
        // Accumulates across every post in this build (Tokens::
        // $unscopedShortcodeRefs, not a per-post-reset array) the same way
        // $this->unscopedRefs accumulates across every option above.
        if ($this->tokens->unscopedShortcodeRefs) {
            $lines = [];
            foreach ($this->tokens->unscopedShortcodeRefs as $r) {
                $scopeKey = $r['kind'] === 'term' ? 'policy.taxonomies' : 'policy.post_types';
                $lines[] = "{$r['post']} shortcode '{$r['shortcode']}' attribute '{$r['attr']}' references {$r['kind']} id "
                    . "{$r['id']}, which is a real '{$r['target_type']}' — but '{$r['target_type']}' is not in "
                    . "$scopeKey, so its identity was never tracked and the reference cannot resolve";
            }
            throw new \RuntimeException(
                "duo: unresolvable ref-typed shortcode attribute(s) point at real, out-of-scope entities (loud-and-blocking gate):\n  - "
                . implode("\n  - ", $lines)
                . "\nThis differs from a dangling reference (deleted target — dropped with a warning, unchanged): the "
                . "target genuinely exists right now, so this is a policy scope gap, not permanent data loss.\n"
                . "Add the missing post type/taxonomy to policy scope above and re-run capture, or pass "
                . "--force-unresolved-refs to drop it anyway (same as a dangling reference)."
            );
        }

        return [
            'entities' => $entities,
            'media' => $media,
            'notes' => $this->tokens->notes,
            'warnings' => array_merge($sidebarBuild['warnings'], $this->tokens->warnings),
        ];
    }

    private function scope_posts(): array {
        global $wpdb;
        $types = $this->policy->post_types();
        $nonAttach = array_values(array_diff($types, ['attachment']));
        $statuses = ['publish', 'draft', 'pending', 'private', 'future'];
        $conds = [];
        if ($nonAttach) {
            $conds[] = "(post_type IN ('" . implode("','", array_map('esc_sql', $nonAttach)) . "')"
                . " AND post_status IN ('" . implode("','", $statuses) . "'))";
        }
        if (in_array('attachment', $types, true)) {
            $conds[] = "(post_type = 'attachment' AND post_status = 'inherit')";
        }
        if (!$conds) {
            return [];
        }
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->posts} WHERE " . implode(' OR ', $conds) . " ORDER BY ID ASC"
        ) ?: [];
    }

    /**
     * Find live authored-looking entities that the current site scope would
     * silently omit. The candidate boundary is intentional and finite:
     * WordPress-registered public surfaces plus whole-type contracts from
     * pinned manifests. A non-authored whole-type class is an explicit,
     * auditable exclusion; absence of any disposition is not.
     *
     * @return array<string,array{entities:int}> keyed post_type:<name> or taxonomy:<name>
     */
    private function scope_gaps(): array {
        global $wpdb;

        $publicPostTypes = array_values(get_post_types(['public' => true], 'names'));
        $postCandidates = array_fill_keys(array_unique(array_merge(
            $publicPostTypes, $this->policy->declared_post_types()
        )), true);
        $scopedPostTypes = array_fill_keys($this->policy->post_types(), true);
        $postCounts = $wpdb->get_results(
            "SELECT post_type, COUNT(*) AS entities FROM {$wpdb->posts}
             WHERE (post_status IN ('publish','draft','pending','private','future')
                    OR (post_type = 'attachment' AND post_status = 'inherit'))
             GROUP BY post_type",
            ARRAY_A
        ) ?: [];

        $out = [];
        foreach ($postCounts as $row) {
            $name = (string) $row['post_type'];
            if (!isset($postCandidates[$name]) || isset($scopedPostTypes[$name])) {
                continue;
            }
            $class = $this->policy->post_type_rule_details($name)['rule']['class'] ?? null;
            if ($class !== null && $class !== 'authored') {
                continue; // explicit manifest/site runtime|derived|env exclusion
            }
            $out["post_type:$name"] = ['entities' => (int) $row['entities']];
        }

        $publicTaxonomies = array_values(get_taxonomies(['public' => true], 'names'));
        $taxCandidates = array_fill_keys(array_unique(array_merge(
            $publicTaxonomies, $this->policy->declared_taxonomies()
        )), true);
        $scopedTaxonomies = array_fill_keys($this->policy->taxonomies(), true);
        $taxCounts = $wpdb->get_results(
            "SELECT taxonomy, COUNT(*) AS entities FROM {$wpdb->term_taxonomy} GROUP BY taxonomy",
            ARRAY_A
        ) ?: [];
        foreach ($taxCounts as $row) {
            $name = (string) $row['taxonomy'];
            if (!isset($taxCandidates[$name]) || isset($scopedTaxonomies[$name])) {
                continue;
            }
            $class = $this->policy->taxonomy_rule_details($name)['rule']['class'] ?? null;
            if ($class !== null && $class !== 'authored') {
                continue;
            }
            $out["taxonomy:$name"] = ['entities' => (int) $row['entities']];
        }

        ksort($out, SORT_STRING);
        return $out;
    }

    private function scope_terms(): array {
        global $wpdb;
        $taxes = $this->policy->taxonomies();
        if (!$taxes) {
            return [];
        }
        $in = "'" . implode("','", array_map('esc_sql', $taxes)) . "'";
        return $wpdb->get_results(
            "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent
             FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy IN ($in) ORDER BY t.term_id ASC"
        ) ?: [];
    }

    /**
     * Precompute, once per build, which of the policy's scoped taxonomies
     * actually apply to each in-scope post type — keyed on the taxonomy's
     * own registered object_type, never on raw numeric object_id — AND,
     * symmetrically, which scoped taxonomies are TERM-object (object_type
     * includes the literal string 'term': Polylang's term_language/
     * term_translations, confirmed empirically — not a post_type name, WP
     * lets a taxonomy's object_type be any string a plugin chooses to
     * register). Both facts come from the exact same per-taxonomy
     * get_taxonomy() walk, so this now does in one pass what used to be
     * (and still would need to be, done twice) doing it as two separate
     * post-side-only and term-side-only passes.
     *
     * Posts and terms are minted from independent auto-increment counters
     * that share one numeric space: a term_relationships row with
     * object_id = N can belong to a post OR — for a term-object taxonomy —
     * to a completely different term that happens to have term_id = N.
     * Filtering the `IN (...)` taxonomy list per object kind, using
     * WordPress's own object_type declaration, is what keeps a post's (or a
     * term's) relationship query from ever matching another object's rows
     * just because the ids coincide — see build_post()'s relationship
     * query and term_relationships() below for the two call sites this
     * guards.
     *
     * A scoped taxonomy that isn't registered at runtime (its plugin is
     * inactive on this environment) can't be checked at all — silently
     * trusting it would reintroduce the same hazard, so it's excluded
     * entirely and named in a loud warning instead.
     *
     * @param string[] $taxes policy-scoped taxonomy names
     * @param string[] $postTypes policy-scoped post types
     * @return array{by_post_type: array<string,string[]>, term_object: string[]}
     */
    private function taxes_by_object_type(array $taxes, array $postTypes): array {
        $byPostType = array_fill_keys($postTypes, []);
        $termObject = [];
        foreach ($taxes as $tax) {
            $taxObj = get_taxonomy($tax);
            // task #92: a taxonomy_patterns-matched name (e.g. pa_size) can
            // be in scope (Policy::taxonomies() found its term_taxonomy
            // rows live) without being REGISTERED yet this same request —
            // WooCommerce reads its defining table on `init`, which already
            // ran before Snapshot's own phase-1 write of that table's row.
            // A manifest-declared object_type (Policy::pattern_object_type())
            // is a fact about the PLUGIN's own registration code, sidestepping
            // the need for get_taxonomy() to have caught up. get_taxonomy()
            // stays authoritative whenever it succeeds; this is a narrow
            // fallback for the one specific timing gap, not a general
            // override — an exact-list taxonomy with no declared pattern
            // still warns+skips exactly as before if unregistered.
            $objectTypes = $taxObj !== false ? (array) $taxObj->object_type : $this->policy->pattern_object_type($tax);
            if ($objectTypes === null) {
                $this->tokens->warnings[] =
                    "taxonomy '$tax' is in policy scope but not registered on this environment"
                    . " (plugin inactive?) — cannot determine which object type its relationships"
                    . " belong to, so its relationships are skipped for every post and term";
                continue;
            }
            // DUO-3280: deliberately NOT extended with Apply's own
            // object_type_from_option supplement (Policy::
            // object_type_option_ref()). That fix reads Apply's compiled
            // TREE (the apply's own not-yet-committed desired state,
            // safe only because run() wraps it all in one transaction —
            // see Apply::option_driven_object_type()'s own comment for
            // why a live DB read is UNSAFE there: phase-2's stable sort
            // can finalize a brand-new post before the declaring option's
            // own sub_keys merge in the SAME apply). Capture has no
            // compiled tree at all — it only ever reads the LIVE
            // environment, and only ever runs as its OWN fresh process
            // (an ordinary `wp duo capture`, or the always-spawned `wp
            // duo verify-canonical` subprocess — Apply::
            // verify_convergence()'s one call site) — so get_taxonomy()
            // here has already re-booted against whatever the option's
            // committed value was at THAT process's own `init`, by the
            // time this ever runs. A same-request race with a sub_keys
            // option write is structurally impossible here; adding a
            // live-DB version of the supplement would not fix anything
            // real and would falsely suggest this method has the same
            // hazard Apply's does.
            foreach ($objectTypes as $objectType) {
                if ($objectType === 'term') {
                    $termObject[] = $tax;
                } elseif (isset($byPostType[$objectType])) {
                    $byPostType[$objectType][] = $tax;
                }
            }
        }
        return ['by_post_type' => $byPostType, 'term_object' => $termObject];
    }

    private function ensure_post_uuid(int $id, string $entityType, bool $mint, bool $strictReadOnly = false): ?string {
        global $wpdb;
        $uuid = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_duo_uuid' LIMIT 1",
            $id
        ));
        if (!$uuid) {
            if ($strictReadOnly) {
                throw new \RuntimeException(
                    "duo: refresh export refused — post $id has no durable _duo_uuid; "
                    . 'run the existing capture/identity recovery gate before exporting production'
                );
            }
            if (!$mint) {
                return null;
            }
            $uuid = Uuid::v7();
            Db::insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => '_duo_uuid', 'meta_value' => $uuid], null, 'capture mint post identity');
            // Retain DUO-3213's immediate checkpoint in addition to Db's
            // strict false check so a later query cannot overwrite a driver
            // error associated with this identity mint.
            self::check_transient_db_error("mint _duo_uuid for post $id");
        }
        if ($strictReadOnly) {
            Ledger::require_read_only_mapping($uuid, $entityType, Ledger::KIND_POST, $id, "post $id");
            return $uuid;
        }
        Ledger::set($uuid, $entityType, Ledger::KIND_POST, $id);
        // DUO-3213 checkpoint: catches a deadlock/lock-wait-timeout from
        // Ledger::set()'s own two queries — see check_transient_db_error()'s
        // docblock.
        self::check_transient_db_error("identity ledger for post $id");
        return $uuid;
    }

    private function ensure_term_uuid(object $t, string $entityType, bool $mint, bool $strictReadOnly = false): ?string {
        global $wpdb;
        $termId = (int) $t->term_id;
        $uuid = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = '_duo_uuid' LIMIT 1",
            $termId
        ));
        if (!$uuid) {
            if ($strictReadOnly) {
                throw new \RuntimeException(
                    "duo: refresh export refused — term $termId has no durable _duo_uuid; "
                    . 'run the existing capture/identity recovery gate before exporting production'
                );
            }
            if (!$mint) {
                return null;
            }
            $uuid = Uuid::v7();
            Db::insert($wpdb->termmeta, ['term_id' => $termId, 'meta_key' => '_duo_uuid', 'meta_value' => $uuid], null, 'capture mint term identity');
            // DUO-3213 checkpoint — see ensure_post_uuid()'s identical comment.
            self::check_transient_db_error("mint _duo_uuid for term $termId");
        }
        if ($strictReadOnly) {
            Ledger::require_read_only_mapping($uuid, $entityType, Ledger::KIND_TERM, $termId, "term $termId");
            Ledger::require_read_only_mapping(
                $uuid,
                $entityType,
                Ledger::KIND_TT,
                (int) $t->term_taxonomy_id,
                "term taxonomy for term $termId"
            );
            return $uuid;
        }
        Ledger::set($uuid, $entityType, Ledger::KIND_TERM, $termId);
        self::check_transient_db_error("identity ledger (term) for term $termId");
        Ledger::set($uuid, $entityType, Ledger::KIND_TT, (int) $t->term_taxonomy_id);
        self::check_transient_db_error("identity ledger (term_taxonomy) for term $termId");
        return $uuid;
    }

    /**
     * A term's own membership in OTHER taxonomies, as object_id — the
     * term-side symmetry of build_post()'s `terms` field (docs/frontier/
     * polylang.md's "term-object relationship capture/apply": Polylang
     * relates a TERM to its language/translation-group via an ordinary
     * term_relationships row where the TERM ITSELF is object_id, e.g.
     * News's term_id as object_id, term_language's pll_en term as the
     * target — confirmed empirically, not the post that happens to share
     * News's numeric id). Filtered to $this->termObjectTaxes — taxonomies
     * whose registered object_type includes 'term', computed once per
     * build() by taxes_by_object_type() — the exact same collision guard
     * build_post() already applies for post-object taxonomies: posts and
     * terms share one auto-increment id space, so an unfiltered `WHERE
     * object_id = $termId` could otherwise pick up an unrelated POST's
     * post-object relationship rows purely because the numbers coincide.
     *
     * @return array<string, string[]> taxonomy => sorted term uuid list
     */
    private function term_relationships(int $termId): array {
        global $wpdb;
        if (!$this->termObjectTaxes) {
            return [];
        }
        $in = "'" . implode("','", array_map('esc_sql', $this->termObjectTaxes)) . "'";
        $rels = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.taxonomy, tt.term_id FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            $termId
        )) ?: [];
        $out = [];
        foreach ($rels as $rel) {
            // Silent drop mirrors build_post()'s identical $tu !== null check
            // below: a target outside this same policy-scoped, object-type-
            // filtered taxonomy list can't happen by construction (the query
            // above is already restricted to those taxonomies, and every term
            // in them was uuid'd in the identity pass above), so this is
            // defensive, not an expected path — same posture, not a reuse.
            $tu = Ledger::uuid_for((int) $rel->term_id, Ledger::KIND_TERM);
            if ($tu !== null) {
                $out[$rel->taxonomy][] = $tu;
            }
        }
        foreach ($out as &$list) {
            sort($list, SORT_STRING);
        }
        unset($list);
        return $out;
    }

    /**
     * A taxonomy declaring `taxonomies.<tax>.description_refs` (manifest-
     * only — Policy::description_refs_for_taxonomy()) stores term_taxonomy.
     * description as PHP-serialized `{lang_slug: local_id}` (Polylang's
     * post_translations/term_translations shape, verified byte-for-byte:
     * `a:2:{s:2:"en";i:1;s:2:"fr";i:2;}`). WordPress never auto-unserializes
     * this column the way maybe_unserialize() does for postmeta/options —
     * it's read here as a raw string and explicitly unserialized, then
     * rewritten with the SAME json_refs primitive post_meta/option values
     * already use (Tokens::struct_capture(), a single path "$.*" over the
     * flat map — every top-level VALUE is a ref of the declared kind; the
     * KEYS are language slugs, never ids, so no key_refs is declared).
     *
     * Every OTHER taxonomy's description keeps the original opaque-string
     * treatment unconditionally: Capture has never unserialized term
     * descriptions in general (the `language` taxonomy's own plugin-config
     * blob — locale/rtl/flag_code — is exactly that shape, safe as opaque
     * text because it holds no ids), and a manifest that doesn't declare
     * description_refs for a taxonomy is asserting "no rewrite needed,"
     * not "capture nothing."
     *
     * Throws loudly on a shape mismatch — same "assert, don't silently
     * degrade" posture as decode_structured() below: a description_refs
     * declaration asserts the value's shape, and silently falling back to
     * opaque-string capture would silently reopen the exact id-leak this
     * mechanism exists to close.
     *
     * @return string|object plain tokenized string (undeclared taxonomy) or
     *   a native token-bearing map (declared taxonomy, cast to object so an
     *   empty map still encodes as "{}" — Canon::encode() renders either
     *   correctly; Lint::scan_term_file() dispatches on the SAME declared-
     *   or-not rule to decide which of its two checks applies).
     */
    private function term_description(object $t) {
        $rule = $this->policy->description_refs_for_taxonomy($t->taxonomy);
        if ($rule === null) {
            return $this->tokens->tokenize_text((string) $t->description);
        }
        $raw = (string) $t->description;
        $decoded = PlainData::decode(
            $raw,
            "taxonomy '{$t->taxonomy}' term {$t->slug}'s description"
        );
        if (!is_array($decoded)) {
            throw new \RuntimeException(
                "duo: taxonomy '{$t->taxonomy}' declares description_refs but term {$t->slug}'s description"
                . ' is not an array once unserialized'
            );
        }
        return (object) $this->tokens->struct_capture($decoded, [['path' => '$.*', 'kind' => $rule['kind']]], null);
    }

    /** @return array{0: array, 1: string, 2: ?array{0:string,1:string}} [front, body, mediaRef] */
    private function build_post(object $p, string $uuid, bool $forceUnresolvedRefs = false): array {
        global $wpdb;
        $id = (int) $p->ID;
        $isAttachment = ($p->post_type === 'attachment');

        // post_password is authored behavior but repository plaintext would
        // violate the secret boundary. Until a portable encrypted field is
        // ratified, protected posts are an explicit unsupported shape.
        if ((string) $p->post_password !== '') {
            throw new \RuntimeException(
                "duo: protected {$p->post_type} '{$p->post_name}' (post $id) has post_password; "
                . 'spec v2 has no portable secret representation for post passwords, so capture refuses it'
            );
        }

        // meta, classified
        $byKey = $this->post_meta_by_key($id);
        $meta = [];
        $attachedFile = null;
        $alt = '';
        $flatMeta = array_map(fn($vals) => $vals[0], $byKey);
        foreach ($byKey as $key => $values) {
            if ($key === '_wp_attached_file') {
                $attachedFile = $values[0];
                continue;
            }
            if ($key === '_wp_attachment_image_alt') {
                $alt = (string) $values[0];
                continue;
            }
            [$store, $v] = $this->classify_meta_value($key, $values, $flatMeta, "post $id", 'post_meta');
            if ($store) {
                $meta[$key] = $v;
            }
        }

        // parent
        $parent = null;
        if ((int) $p->post_parent > 0) {
            $tok = $this->tokens->id_to_token((int) $p->post_parent, 'post');
            if ($tok === null) {
                throw new \RuntimeException(
                    "duo: post {$p->post_name} has unmanaged parent post {$p->post_parent} — capture scope must include it"
                );
            }
            $parent = $tok;
        }

        // term relationships (owned taxonomies only, filtered to taxonomies
        // whose registered object_type actually includes THIS post type —
        // see taxes_by_object_type() for why raw object_id equality alone
        // is unsafe: posts and terms share one auto-increment id space)
        $taxes = $this->taxesForPostType[$p->post_type] ?? [];
        $termsField = [];
        $termOrders = [];
        if ($taxes) {
            $in = "'" . implode("','", array_map('esc_sql', $taxes)) . "'";
            $rels = $wpdb->get_results($wpdb->prepare(
                "SELECT tt.taxonomy, tt.term_id, tr.term_order FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
                $id
            )) ?: [];
            foreach ($rels as $rel) {
                $tu = Ledger::uuid_for((int) $rel->term_id, Ledger::KIND_TERM);
                if ($tu !== null) {
                    $termsField[$rel->taxonomy][] = $tu;
                    $termOrders[$rel->taxonomy][$tu] = (int) $rel->term_order;
                }
            }
            foreach ($termsField as &$list) {
                sort($list, SORT_STRING);
            }
            unset($list);
        }

        $front = [
            'uuid' => $uuid,
            'type' => $p->post_type,
            'slug' => $p->post_name,
            'title' => $p->post_title,
            'status' => $p->post_status,
            'date' => $p->post_date,
            'date_gmt' => $p->post_date_gmt,
            'modified' => $p->post_modified,
            'modified_gmt' => $p->post_modified_gmt,
            'author' => $this->author_token((int) $p->post_author),
            'parent' => $parent,
            'menu_order' => (int) $p->menu_order,
            'comment_status' => $p->comment_status,
            'ping_status' => $p->ping_status,
            'excerpt' => $this->tokens->tokenize_text((string) $p->post_excerpt),
            'meta' => (object) $meta,
            'terms' => (object) $termsField,
            'term_orders' => (object) array_map(fn($orders) => (object) $orders, $termOrders),
        ];

        $mediaRef = null;
        if ($isAttachment) {
            if (!$attachedFile) {
                throw new \RuntimeException("duo: attachment $id has no _wp_attached_file");
            }
            $up = wp_upload_dir(null, false);
            $localPath = trailingslashit($up['basedir']) . $attachedFile;
            $source = is_file($localPath) ? ['path' => $localPath] : null;

            /**
             * Lets an offload adapter supply attachment bytes without
             * requiring a persistent local uploads copy. The strict result
             * contract is exactly one of:
             *
             *   ['path' => '/readable/materialized/file']
             *   ['bytes' => $rawBytes]
             *
             * The ordinary local upload path is the default when present,
             * so providers may leave it alone, replace it with a temporary
             * materialization, or return bytes from their own API.
             */
            $source = apply_filters(
                'duo_attachment_capture_source',
                $source,
                $id,
                (string) $attachedFile,
                $localPath
            );
            if ($source === null) {
                throw new \RuntimeException(
                    "duo: attachment $id file '$attachedFile' is not present locally and no offload provider"
                    . ' supplied bytes via duo_attachment_capture_source; capture cannot proceed for this attachment'
                );
            }
            if (!is_array($source)) {
                throw new \RuntimeException(
                    "duo: attachment $id offload provider returned an invalid duo_attachment_capture_source value;"
                    . " expected exactly ['path' => <readable path>] or ['bytes' => <raw bytes>]"
                );
            }
            $hasPath = array_key_exists('path', $source);
            $hasBytes = array_key_exists('bytes', $source);
            if ($hasPath === $hasBytes) {
                throw new \RuntimeException(
                    "duo: attachment $id offload provider returned an invalid duo_attachment_capture_source value;"
                    . " expected exactly one of 'path' or 'bytes'"
                );
            }
            if ($hasPath) {
                if (!is_string($source['path']) || $source['path'] === ''
                    || !is_file($source['path']) || !is_readable($source['path'])) {
                    throw new \RuntimeException(
                        "duo: attachment $id offload provider path is not a readable file"
                    );
                }
                $sha = hash_file('sha256', $source['path']);
                if ($sha === false) {
                    throw new \RuntimeException(
                        "duo: attachment $id offload provider path could not be hashed"
                    );
                }
            } else {
                if (!is_string($source['bytes'])) {
                    throw new \RuntimeException(
                        "duo: attachment $id offload provider bytes must be a string"
                    );
                }
                $sha = hash('sha256', $source['bytes']);
            }
            $ext = pathinfo($attachedFile, PATHINFO_EXTENSION);
            $mediaFile = $sha . ($ext ? ".$ext" : '');
            $front['file'] = $attachedFile;
            $front['media'] = $mediaFile;
            $front['mime'] = $p->post_mime_type;
            $front['alt'] = $alt;
            $mediaRef = [$mediaFile, $source];
        }

        // Secret guard on bodies: loud warning, never an abort — people
        // legitimately write posts *about* tokens/keys (docs, changelogs).
        $secretLabel = Secrets::hard_match((string) $p->post_content);
        if ($secretLabel !== null) {
            $this->tokens->warnings[] =
                "{$p->post_type} '{$p->post_name}' body looks like it contains a $secretLabel — review before committing (not blocked: bodies may legitimately discuss credentials)";
        }

        if ($this->policy->body_mode($p->post_type) === 'verbatim') {
            // Serialized-data bodies (e.g. acf-field config): byte-preserved —
            // URL substitution would corrupt serialized string lengths.
            $body = (string) $p->post_content;
            if ($body !== '' && str_contains($body, $this->tokens->home())) {
                $this->tokens->warnings[] =
                    "verbatim body of {$p->post_type} '{$p->post_name}' contains this environment's home URL — it will NOT be re-bound on apply";
            }
        } else {
            $body = Blocks::capture_rewrite(
                (string) $p->post_content,
                $this->policy,
                $this->tokens,
                $forceUnresolvedRefs,
                "{$p->post_type} '{$p->post_name}'"
            );
        }
        return [$front, $body, $mediaRef];
    }

    private function author_token(int $userId): ?string {
        global $wpdb;
        if ($userId <= 0) {
            return null;
        }
        if (!isset($this->userLogins[$userId])) {
            $login = $wpdb->get_var($wpdb->prepare(
                "SELECT user_login FROM {$wpdb->users} WHERE ID = %d", $userId
            ));
            $this->userLogins[$userId] = $login ?: '';
        }
        $login = $this->userLogins[$userId];
        if ($login === '') {
            $this->tokens->warnings[] = "post author user $userId not found; author dropped";
            return null;
        }
        return 'user:' . $login;
    }

    /** @return array<int, array{uuid: string, slug: string, front: array}> */
    private function scope_menus(bool $mint, bool $strictReadOnly = false): array {
        global $wpdb;
        $menuTerms = $wpdb->get_results(
            "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent
             FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = 'nav_menu' ORDER BY t.term_id ASC"
        ) ?: [];
        if (!$menuTerms) {
            return [];
        }

        // menu -> locations, from the active theme's mods. DUO-3272: under a
        // manifest that reclassifies menu_fields.locations 'derived' (e.g.
        // Polylang — its own Languages::update_default() unconditionally
        // rewrites this exact raw slot from ITS OWN nav_menus/default_lang
        // bookkeeping any time the default language changes or is
        // re-resolved, entirely outside this capture/apply cycle), the raw
        // slot is not carried at all: not read here, not written into any
        // menu's 'locations' key below. See Policy::menu_field_class()'s
        // docblock and manifests/polylang.json's own DUO-3272 note for the
        // full empirical grounding.
        $locationsDerived = $this->policy->menu_field_class('locations') === 'derived';
        $locByTerm = [];
        if (!$locationsDerived) {
            $stylesheet = (string) get_option('stylesheet');
            // WordPress' get_option() path uses maybe_unserialize(), whose
            // legacy unserialize() call can construct target-controlled PHP
            // objects before this capture ever sees the value. Read the
            // exact row instead, then cross the shared plain-data boundary
            // with object hooks disabled while retaining WordPress's false
            // default for a missing row.
            $name = 'theme_mods_' . $stylesheet;
            $raw = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $name
            ));
            $mods = $raw === null ? false : PlainData::decode($raw, "option '$name'");
            if (is_array($mods) && !empty($mods['nav_menu_locations'])) {
                foreach ($mods['nav_menu_locations'] as $loc => $tid) {
                    $locByTerm[(int) $tid][] = (string) $loc;
                }
            }
        }

        $menus = [];
        foreach ($menuTerms as $mt) {
            $uuid = $this->ensure_term_uuid($mt, 'menu', $mint, $strictReadOnly);
            if ($uuid === null) {
                continue;
            }
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT p.* FROM {$wpdb->posts} p
                 JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                 WHERE tr.term_taxonomy_id = %d AND p.post_type = 'nav_menu_item' AND p.post_status = 'publish'
                 ORDER BY p.menu_order ASC, p.ID ASC",
                (int) $mt->term_taxonomy_id
            )) ?: [];

            // first pass: identity for parent refs
            $itemUuidById = [];
            foreach ($items as $ip) {
                $iu = $this->ensure_post_uuid((int) $ip->ID, 'menu_item', $mint, $strictReadOnly);
                if ($iu !== null) {
                    $itemUuidById[(int) $ip->ID] = $iu;
                }
            }

            $itemList = [];
            foreach ($items as $ip) {
                $iid = (int) $ip->ID;
                $iu = $itemUuidById[$iid] ?? null;
                if ($iu === null) {
                    continue;
                }
                $m = $this->post_meta_map($iid);
                $type = $m['_menu_item_type'] ?? 'custom';
                $objectId = (int) ($m['_menu_item_object_id'] ?? 0);
                $ref = '';
                if ($type === 'post_type') {
                    $ref = $this->tokens->id_to_token($objectId, 'post')
                        ?? throw new \RuntimeException("duo: menu '{$mt->slug}' item $iid points at unmanaged post $objectId");
                } elseif ($type === 'taxonomy') {
                    $ref = $this->tokens->id_to_token($objectId, 'term')
                        ?? throw new \RuntimeException("duo: menu '{$mt->slug}' item $iid points at unmanaged term $objectId");
                } else {
                    $ref = $this->tokens->tokenize_text((string) ($m['_menu_item_url'] ?? ''));
                }
                $parentItem = (int) ($m['_menu_item_menu_item_parent'] ?? 0);
                $classes = PlainData::decode(
                    $m['_menu_item_classes'] ?? '',
                    "menu '{$mt->slug}' item $iid _menu_item_classes"
                );
                $classes = is_array($classes)
                    ? array_values(array_filter(array_map('strval', $classes), fn($s) => $s !== ''))
                    : [];
                // DUO-3266: every OTHER key in this item's meta — anything
                // beyond the 8 WordPress-core _menu_item_* keys read above
                // — used to be silently dropped here, never reaching
                // Policy::meta_rule_for_post() or $this->unclassified[]
                // the way ordinary post_meta already does (Capture.php's
                // build_post(), a few hundred lines up). manifests/core.json
                // already classifies all 8 core keys "managed" (bespoke-
                // handled, same as _wp_attached_file) or "runtime"
                // (_menu_item_orphaned) — neither is 'authored' — so
                // classify_meta_value() skips them here for free, with NO
                // separate allowlist needed; a genuinely unclassified key
                // (a plugin's own menu-item meta) now hits the SAME loud
                // gate every other post type's meta already does, and a
                // key a manifest DOES classify authored now actually
                // captures instead of vanishing.
                $itemByKey = $this->post_meta_by_key($iid);
                $itemFlatMeta = array_map(fn($vals) => $vals[0], $itemByKey);
                $itemMeta = [];
                foreach ($itemByKey as $ikey => $ivalues) {
                    [$store, $iv] = $this->classify_meta_value(
                        $ikey,
                        $ivalues,
                        $itemFlatMeta,
                        "menu '{$mt->slug}' item $iid",
                        'menu_item_meta'
                    );
                    if ($store) {
                        $itemMeta[$ikey] = $iv;
                    }
                }
                $itemList[] = [
                    'uuid' => $iu,
                    'type' => $type,
                    'object' => (string) ($m['_menu_item_object'] ?? ''),
                    'ref' => $ref,
                    'meta' => $itemMeta,
                    'parent' => $parentItem > 0 ? ($itemUuidById[$parentItem] ?? null) : null,
                    'position' => (int) $ip->menu_order,
                    'title' => $ip->post_title,
                    'description' => $this->tokens->tokenize_text((string) $ip->post_content),
                    'attr_title' => (string) $ip->post_excerpt,
                    'target' => (string) ($m['_menu_item_target'] ?? ''),
                    'classes' => $classes,
                    'xfn' => (string) ($m['_menu_item_xfn'] ?? ''),
                ];
            }

            $front = [
                'uuid' => $uuid,
                'name' => $mt->name,
                'slug' => $mt->slug,
                'items' => $itemList,
            ];
            if (!$locationsDerived) {
                // DUO-3272: omitted entirely (not captured as []) when
                // derived — Apply::finalize_menu()'s own '?? []' fallback
                // for a missing key is exactly what keeps a derived-under-
                // Polylang menu file forward-compatible with an
                // engine/manifest pairing that predates this override.
                $locations = $locByTerm[(int) $mt->term_id] ?? [];
                sort($locations, SORT_STRING);
                $front['locations'] = $locations;
            }
            $menus[] = [
                'uuid' => $uuid,
                'slug' => $mt->slug,
                'front' => $front,
            ];
        }
        return $menus;
    }

    private function post_meta_map(int $postId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC",
            $postId
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            if (!isset($out[$r['meta_key']])) {
                $out[$r['meta_key']] = $r['meta_value'];
            }
        }
        return $out;
    }

    /**
     * Multi-value form of post_meta_map(): key -> ALL raw values, in
     * meta_id order. build_post()'s own authored-meta pipeline needs this
     * shape (not the flat single-value one) because a real duplicate-key
     * row is exactly what "multi-value authored meta ... unsupported in
     * v0" (classify_meta_value() below) must detect — post_meta_map()
     * silently keeps only the first row per key, which would hide that
     * case rather than refuse it.
     */
    private function post_meta_by_key(int $postId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key ASC, meta_id ASC",
            $postId
        ), ARRAY_A) ?: [];
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['meta_key']][] = $r['meta_value'];
        }
        return $byKey;
    }

    /**
     * DUO-3266: classify and fully resolve ONE post-meta-shaped key through
     * the ordinary meta_rule_for_post()-driven pipeline — multi-value
     * rejection, secret scanning, ref/json_refs/key_refs resolution, plain
     * string tokenization, order-preservation. Factored out of build_post()
     * so a second owner of postmeta rows (menu items, whose item-building
     * loop in scope_menus() previously read a fixed 8-key allowlist and
     * silently dropped everything else — the exact silent-authored-data-
     * loss bug this issue exists to close) gets the SAME classification
     * discipline ordinary posts already have, not a second, drift-prone
     * reimplementation of it.
     *
     * $ownerLabel/$unclassifiedPrefix let call sites keep their own
     * existing message shape (`post $id` / `post_meta:$key` for ordinary
     * posts; a menu-item-specific label for DUO-3266) rather than forcing
     * one generic wording on every caller.
     *
     * @param string[] $values raw multi-value meta_value list for this key
     *   (WordPress meta rows are multi-valued at the schema level even
     *   though authored meta here is v0-restricted to single-valued).
     * @param array<string,string> $flatMeta first-value-per-key map, as
     *   Policy::meta_rule_for_post() expects (some rules key off sibling
     *   values, e.g. ACF's shadow-key pointer lookup).
     * @return array{0: bool, 1: mixed} [$store, $value]. $store is false
     *   for unclassified (already appended to $this->unclassified[]),
     *   non-authored, or dangling-ref keys — $value is meaningless then.
     *   $store is true otherwise, with $value the fully resolved value to
     *   store; $value MAY legitimately be PHP null (an authored meta row
     *   whose stored bytes are serialize(null) is rare but real, and must
     *   stay distinguishable from "don't store this key at all" — hence
     *   the explicit $store flag rather than "null return means skip").
     */
    private function classify_meta_value(
        string $key,
        array $values,
        array $flatMeta,
        string $ownerLabel,
        string $unclassifiedPrefix,
        bool $termMeta = false
    ): array {
        $rule = $termMeta
            ? $this->policy->meta_rule_for_term($key, $flatMeta)
            : $this->policy->meta_rule_for_post($key, $flatMeta);
        if ($rule === null) {
            $this->unclassified[] = "$unclassifiedPrefix:$key";
            return [false, null];
        }
        if (($rule['class'] ?? '') !== 'authored') {
            return [false, null];
        }
        if (count($values) > 1) {
            throw new \RuntimeException("duo: multi-value authored meta '$key' on $ownerLabel unsupported in v0");
        }
        $v = PlainData::decode($values[0], "$ownerLabel meta $key");
        PlainData::assert($v, "$ownerLabel meta $key");
        // DUO-3214: unconditional, not gated on is_string($v) — an
        // authored value that decoded to an array (a serialized settings
        // blob) must be scanned too; guard_secret() deep-scans internally
        // now (see its own docblock).
        $this->guard_secret($termMeta ? 'term_meta' : 'post_meta', $key, $v, $rule, " on $ownerLabel");
        if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
            $decoded = $this->decode_structured($v, $rule, "$ownerLabel meta $key");
            $v = $this->tokens->struct_capture($decoded, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null);
        } elseif (!empty($rule['ref'])) {
            $v = $this->tokens->meta_value_to_tokens($v, $rule);
            if ($v === null) {
                // dangling scalar ref: key skipped (warned inside Tokens) —
                // a raw env-local id must never reach canonical state
                return [false, null];
            }
        } elseif (is_string($v)) {
            $v = $this->tokens->tokenize_text($v);
        }
        // DUO-3214(b) / task #123: applied LAST, to the fully-processed
        // value, so it composes correctly with ref/json_refs rewriting
        // above rather than racing it — order preservation is about how
        // Canon serializes the FINAL value, not an input-shape concern.
        // See Canon::normalize()'s docblock for the mechanism.
        if (!empty($rule['order_preserving'])) {
            $v = new OrderPreserved($v);
        }
        return [true, $v];
    }

    /** First-value-per-key termmeta context for static/interpreter rules. */
    private function term_meta_map(int $termId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d ORDER BY meta_id ASC",
            $termId
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            if (!isset($out[$r['meta_key']])) {
                $out[$r['meta_key']] = $r['meta_value'];
            }
        }
        return $out;
    }

    /** Multi-value termmeta read, preserving meta_id order per key. */
    private function term_meta_by_key(int $termId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d ORDER BY meta_key ASC, meta_id ASC",
            $termId
        ), ARRAY_A) ?: [];
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['meta_key']][] = $row['meta_value'];
        }
        return $byKey;
    }

    /**
     * Whole-user meta context for the optional interpreter hook. Users stay
     * environment-local: this read neither mints identity nor emits an
     * entity. The join deliberately excludes orphaned usermeta rows, which
     * have no owning user/login and therefore cannot be a user-attached
     * authored surface under DUO-3268's login-keyed design.
     *
     * @return array<int, array{login:string,meta:array<string,mixed>,values:array<string,string[]>}>
     */
    private function user_meta_maps(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT u.ID AS user_id, u.user_login, um.meta_key, um.meta_value
             FROM {$wpdb->users} u
             LEFT JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
             ORDER BY u.ID ASC, um.umeta_id ASC",
            ARRAY_A
        ) ?: [];
        self::check_transient_db_error('Capture::user_meta_maps()');
        $out = [];
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $out[$userId]['login'] = (string) $row['user_login'];
            $out[$userId]['meta'] ??= [];
            $out[$userId]['values'] ??= [];
            if ($row['meta_key'] === null) {
                continue;
            }
            $key = (string) $row['meta_key'];
            $out[$userId]['values'][$key][] = (string) $row['meta_value'];
            if (!array_key_exists($key, $out[$userId]['meta'])) {
                $out[$userId]['meta'][$key] = (string) $row['meta_value'];
            }
        }
        return $out;
    }

    /** @return array<int,array{uuid:string,type:string,path:string,content:string}> */
    private function build_user_meta_entities(array $carriedLogins): array {
        $carry = array_fill_keys(array_filter(array_map('strval', $carriedLogins)), true);
        $users = [];
        foreach ($this->user_meta_maps() as $user) {
            UserMetaState::assert_login($user['login']);
            $users[$user['login']] = $user;
        }
        ksort($users, SORT_STRING);

        $out = [];
        foreach ($users as $login => $user) {
            $authored = [];
            foreach ($user['values'] as $key => $values) {
                [$store, $value] = $this->classify_user_meta_value(
                    (string) $key,
                    $values,
                    $user['meta'],
                    $login
                );
                if ($store) {
                    $authored[(string) $key] = $value;
                }
            }
            if (!$authored && !isset($carry[$login])) {
                continue;
            }
            $document = UserMetaState::document($login, $authored);
            $out[] = [
                // Canonical-state key only: not a UUID, never duo_map.
                'uuid' => UserMetaState::key($login),
                'type' => 'user-meta',
                'path' => UserMetaState::path($login),
                'content' => Canon::encode($document),
            ];
        }
        return $out;
    }

    /** @return array{0:bool,1:mixed} */
    private function classify_user_meta_value(string $key, array $values, array $flatMeta, string $login): array {
        $rule = $this->policy->meta_rule_for_user($key, $flatMeta);
        if (($rule['class'] ?? '') !== 'authored') {
            return [false, null];
        }
        if (count($values) !== 1) {
            throw new \RuntimeException(
                "duo: multi-value authored user meta '$key' on exact login '$login' is unsupported; "
                . 'refusing to choose one row'
            );
        }
        $value = PlainData::decode($values[0], "user '$login' meta $key");
        PlainData::assert($value, "user '$login' meta $key");
        $this->guard_secret('user_meta', $key, $value, $rule, " on exact login '$login'");
        $this->guard_personal_data($key, $value, $rule, $login);
        if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
            $decoded = $this->decode_structured($value, $rule, "user '$login' meta $key");
            $value = $this->tokens->struct_capture(
                $decoded,
                $rule['json_refs'] ?? [],
                $rule['key_refs'] ?? null
            );
        } elseif (!empty($rule['ref'])) {
            $value = $this->tokens->meta_value_to_tokens($value, $rule);
            if ($value === null) {
                return [false, null];
            }
        } elseif (is_string($value)) {
            $value = $this->tokens->tokenize_text($value);
        }
        if (!empty($rule['order_preserving'])) {
            $value = new OrderPreserved($value);
        }
        return [true, $value];
    }

    /**
     * Shared scalar/structured capture dispatch for one option-shaped VALUE
     * given its RULE (class/ref/json_refs/key_refs) — the same four-way
     * branch build_options()' ordinary per-option loop always used, factored
     * out so a sub_keys (DUO-3233) NAMED sub-key gets it too: a sub-key's
     * rule is a whole option rule at one nesting level down (the same
     * json_refs/key_refs/ref/plain-string vocabulary, nothing new), so this
     * is a correctness statement as much as a de-duplication — a sub-key
     * MUST behave exactly like an option, or "narrowing option ownership to
     * sub-key ownership" (DUO-3211's review comment) would be a different,
     * weaker mechanism wearing the same manifest vocabulary.
     *
     * $ctx is a human label for warnings only (e.g. "polylang.nav_menus"),
     * never parsed back — option_ref_tokens()'s own $name param is reused
     * unchanged for this, so its existing dangling/unscoped messages read
     * naturally for a sub-key too ("option polylang.nav_menus: ...").
     *
     * The explicit included bit is load-bearing: PHP null is a legitimate
     * option value (`N;` on the SQL wire), while a scalar ref that resolves
     * to nothing means "omit this record." Returning bare null used to
     * conflate those two states and made the null-like matrix lossy.
     *
     * @return array{included:bool,value:mixed}
     */
    private function capture_value(string $ctx, $v, array $rule, bool $forceUnresolvedRefs): array {
        if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
            $decoded = $this->decode_structured($v, $rule, "option $ctx");
            return ['included' => true, 'value' => $this->tokens->struct_capture(
                $decoded, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null
            )];
        }
        if (!empty($rule['ref'])) {
            $captured = $this->option_ref_tokens($ctx, $v, $rule['ref'], $forceUnresolvedRefs);
            return ['included' => $captured !== null, 'value' => $captured];
        }
        if (is_string($v)) {
            return ['included' => true, 'value' => $this->tokens->tokenize_text($v)];
        }
        return ['included' => true, 'value' => $v];
    }

    private function build_options(
        bool $mint,
        bool $forceUnresolvedRefs = false,
        ?array $previousDocument = null,
        array $dynamicResolverValues = [],
        bool $bindMissingDynamicDesired = false,
        bool $strictReadOnly = false
    ): array {
        $out = [];
        $processed = [];
        $liveCanonicalNames = [];
        foreach ($this->policy->authored_options() as $name => $rule) {
            $processed[$name] = true;
            $row = $this->read_option_row($name);
            if ($row === null) {
                continue;
            }
            $liveCanonicalNames[$name] = true;
            $v = PlainData::decode($row['option_value'], "option $name");
            PlainData::assert($v, "option $name");
            // DUO-3214: unconditional — see the identical comment in
            // build_post()'s post_meta loop above.
            $this->guard_secret('options', $name, $v, $rule);
            $captured = $this->capture_value($name, $v, $rule, $forceUnresolvedRefs);
            if (!$captured['included']) {
                continue;
            }
            OptionState::assert_rule_autoload($rule, $row['autoload'], "option '$name'");
            $out[$name] = OptionState::present($captured['value'], $row['autoload']);
        }

        // Journal-independent discovery for manifest-owned option
        // namespaces. A full name scan happens at capture time, so options
        // created before agent activation and writes made while journaling
        // is disabled are still seen. Namespace ownership and value
        // classification are separate declarations: a claimed name with no
        // exact/pattern rule is queued as unknown; an authored pattern is a
        // real dynamic-family capture rule, not merely a lookup fallback.
        $allOptionValues = $this->all_options_map();
        foreach (array_keys($allOptionValues) as $name) {
            $owner = $this->policy->option_namespace($name);
            if ($owner === null) {
                continue;
            }
            $rule = $this->policy->owned_option_rule_via_interpreter($name, $allOptionValues);
            if (isset($processed[$name]) || $this->policy->match_option_name_ref($name) !== null) {
                continue;
            }
            if ($rule === null) {
                $this->unclassified[] = "options:$name (owner candidate {$owner['owner']}; namespace matched without a classification)";
                continue;
            }
            $processed[$name] = true;
            if (($rule['class'] ?? '') !== 'authored' || !empty($rule['sub_keys'])) {
                continue;
            }
            $row = $this->read_option_row($name);
            if ($row === null) {
                continue;
            }
            $liveCanonicalNames[$name] = true;
            $v = PlainData::decode($row['option_value'], "option $name");
            PlainData::assert($v, "option $name");
            // DUO-3214: unconditional — see the identical comment in
            // build_post()'s post_meta loop above. This call site auto-
            // merged past the DUO-3211 rebase's own conflict marker without
            // being flagged (the surrounding lines changed enough on both
            // sides that git's 3-way merge considered this one already
            // resolved) — caught by re-auditing every guard_secret() call
            // site after the rebase rather than trusting the single
            // flagged conflict, not by a failing test.
            $this->guard_secret('options', $name, $v, $rule);
            $captured = $this->capture_value($name, $v, $rule, $forceUnresolvedRefs);
            if ($captured['included']) {
                OptionState::assert_rule_autoload($rule, $row['autoload'], "option '$name'");
                $out[$name] = OptionState::present($captured['value'], $row['autoload']);
            }
        }

        // sub_keys (DUO-3233): NAMED sub-keys of one option blob classified
        // independently — capture SOME keys of a blob, exclude the rest.
        // Distinct from authored_options() above (a WHOLE option's class)
        // and from option_name_refs below (options discovered by NAME
        // pattern): a sub_keys option is discovered by its own EXACT name
        // (Policy::sub_keyed_options(), same enumeration shape as
        // authored_options()), but only the DECLARED subset of the live
        // value's own top-level keys is ever read into canonical state —
        // the undeclared remainder (Polylang's force_lang/rewrite/
        // first_activation/version, Yoast's first_activated_on/version, …)
        // never enters state/ and is never touched at apply (see
        // Apply::apply_option_sub_keys()'s merge-into-live-blob path). This
        // is the capability manifests/polylang.json's own notes long
        // documented as missing — see Policy::sub_keyed_options()'s
        // docblock for the full history.
        foreach ($this->policy->sub_keyed_options() as $name => $rule) {
            $this->capture_option_sub_keys($name, $rule, $forceUnresolvedRefs, $liveCanonicalNames, $out);
        }

        // dynamic_options (DUO-3264, fork A): the SAME sub_keys-shaped
        // capture as the loop immediately above, against a COMPUTED option
        // name instead of an exactly-declared one -- theme_mods_<active
        // stylesheet> is the proven case (manifests/core.json's own
        // declaration + note has the full empirical grounding). Only ONE
        // resolved name is ever read here; every OTHER live option name
        // sharing the same prefix (a theme_mods_* row for a theme that is
        // not currently active) is simply never looked at by this loop --
        // not a separate exclusion step, a structural non-effect of only
        // ever computing the one currently-resolved name. See
        // Policy::is_dynamic_option_residue() for the queryable form of
        // that same fact, available to a future caller that needs to
        // recognize such a row explicitly (none does yet in this codebase).
        foreach ($this->policy->dynamic_options() as $key => $decl) {
            $resolvedValue = match ($decl['resolver']) {
                'active_stylesheet' => array_key_exists('active_stylesheet', $dynamicResolverValues)
                    ? (string) $dynamicResolverValues['active_stylesheet']
                    : (string) get_option('stylesheet'),
                default => throw new \RuntimeException(
                    "duo: dynamic_options.$key declares unsupported resolver '{$decl['resolver']}'"
                ),
            };
            $resolved = $this->policy->resolve_dynamic_option($key, $resolvedValue);
            if ($resolved === null) {
                continue;
            }
            $this->capture_option_sub_keys(
                $resolved['name'],
                ['sub_keys' => $resolved['sub_keys'], 'autoload' => $resolved['autoload']],
                $forceUnresolvedRefs,
                $liveCanonicalNames,
                $out
            );
        }

        // option_name_refs (task #93): options discovered by NAME PATTERN
        // — Policy::authored_options() above is exact-whitelist only and
        // never finds these rows at all (r1b-shop.md's own finding:
        // WooCommerce's woocommerce_<method_id>_<instance_id>_settings
        // rows are otherwise invisible to capture). One full option-NAME
        // scan (names only, not values — cheap, and this runs once per
        // capture, not per-option), tested against every declared pattern;
        // $forceUnresolvedRefs reuses task #73's exact escape hatch rather
        // than inventing a second flag.
        // The complete option_name_ref_rules() declaration set is resolved
        // once per concrete live name below; do not reintroduce per-rule
        // first/last-match loops here.
        foreach (array_keys($allOptionValues) as $name) {
            // Resolve every declared option-name rule through the shared
            // Policy matcher, even runtime/derived rules which this capture
            // path will not emit. That is what makes cross-kind and
            // cross-class overlaps fail closed instead of being hidden by a
            // first/last declaration choice.
            $details = $this->policy->option_name_ref_match_details((string) $name);
            if ($details === null || ($details['rule']['class'] ?? '') !== 'authored') {
                continue; // runtime-classified families are discovered, never captured
            }
            $rule = $details['rule'];
            $m = $details['matches'];
            $rawId = $m['id'][0] ?? null;
            $id = Policy::strict_positive_local_id($rawId);
            if ($id === null) {
                throw new \RuntimeException(
                    "duo: option '$name' captures an invalid local id in option_name_refs; refusing capture"
                );
            }
            $offset = (int) $m['id'][1];
            $length = strlen((string) $m['id'][0]);
                $token = $this->tokens->id_to_token($id, $rule['id_kind']);
                if ($token === null) {
                    // task #73's dangling-vs-unscoped distinction, mirrored
                    // onto table id_kinds — but GATED on $mint === true,
                    // which #73's OWN original mechanism never needed to do
                    // (post_type/taxonomy scope is a fact about a FIXED core
                    // table, checkable regardless of minting state; a
                    // custom table's very identity is only knowable via its
                    // OWN declaration, so "declared" and "in policy scope"
                    // are not analogous the same way). Reproduced directly,
                    // not just reasoned about: calling Capture::snapshot()
                    // (mint=false — Apply::build_plan()'s own drift-check
                    // path) against a genuinely-declared table's row that
                    // simply hadn't been through a real `duo capture` yet
                    // threw this gate. This IS the same design rule as
                    // queue_or_warn_unscoped()'s own documented false
                    // positive below (default_category on a never-captured
                    // fresh install — "id_to_token()'s success is a MINTING
                    // check, not a POLICY check" — caught empirically
                    // running THAT task's own core-manifest conformance
                    // validation) — one rule, two instances: an unresolved
                    // ref on a non-minting snapshot is never, by itself,
                    // proof of a scope gap, only of "hasn't been captured
                    // through Duo yet." The reason mint=true never
                    // legitimately reaches this branch at all: Snapshot::
                    // capture() (called earlier in the SAME build(), before
                    // build_options() runs) already mints EVERY row of
                    // every DECLARED table unconditionally — so for
                    // mint=true, row_exists_for_kind() returning true
                    // alongside a failed id_to_token() would be a genuine
                    // invariant violation, worth flagging loudly; for
                    // mint=false it is the ordinary, expected shape of
                    // "hasn't been captured through Duo yet" and must fall
                    // through to the same warn-and-drop dangling gets.
                    if (($mint || $strictReadOnly) && !$forceUnresolvedRefs
                        && Snapshot::row_exists_for_kind($this->policy, $rule['id_kind'], $id)) {
                        $this->unscopedOptionNameRefs[] = ['option' => $name, 'id_kind' => $rule['id_kind'], 'id' => $id];
                    } else {
                        $this->tokens->warnings[] = "option $name: unmapped {$rule['id_kind']} id $id dropped (option_name_refs)";
                    }
                    continue;
                }
                $key = substr_replace($name, $token, $offset, $length);
                $row = $this->read_option_row($name);
                if ($row === null) {
                    continue;
                }
                $liveCanonicalNames[$key] = true;
                $v = PlainData::decode($row['option_value'], "option $name");
                PlainData::assert($v, "option $name");
                // Deep secret scan, not the shallow is_string() guard the
                // ordinary options loop above uses: this value is typically
                // an ARRAY (a settings blob — title/cost/tax_status for
                // flat_rate), and Secrets::hard_match_deep() is what
                // actually recurses into it (a plain is_string() check
                // would silently never scan an array's own string leaves —
                // caught during this task's own design review before any
                // code shipped; see Snapshot::guard_secret()'s identical
                // reasoning for typed-snapshot table/attached-meta values).
                if (empty($rule['allow_secret'])) {
                    $secretLabel = Secrets::hard_match_deep($v);
                    if ($secretLabel !== null) {
                        throw new \RuntimeException(
                            "duo: secret guard tripped — option '$name' looks like a $secretLabel but is classified "
                            . "authored (option_name_refs); refusing to capture it into state/.\n"
                            . "If this is really a secret, reclassify it runtime/derived/env instead of authored.\n"
                            . 'If this is a false positive, declare "allow_secret": true on its option_name_refs rule.'
                        );
                    }
                }
                // Unconditional struct_capture (not gated on json_refs/
                // key_refs being non-empty, unlike the ordinary options
                // loop above): this is what gives an array-shaped settings
                // blob "plain authored + normal URL tokenization" on every
                // string leaf with zero per-method-id special-casing —
                // struct_capture() tokenizes leaves regardless of whether
                // $jsonRefs/$keyRefs are empty. Deliberately NOT the same
                // default as the ordinary authored_options() loop above
                // (which leaves an array value untouched unless json_refs/
                // key_refs is declared) — changing THAT loop's default
                // risks already-shipped manifests; this is a new, narrower
                // path with its own default, scoped only to option_name_
                // refs-discovered rows.
                $v = $this->tokens->struct_capture($v, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null);
                OptionState::assert_rule_autoload($rule, $row['autoload'], "option '$name'");
                $out[$key] = OptionState::present($v, $row['autoload']);
        }

        // docs/proposals/code-half.md §3.1: active_plugins/template/
        // stylesheet are core-manifest options classified 'managed', not
        // 'authored' — bespoke read here, alongside (not through) the
        // authored_options()-driven loop above, because Apply must
        // reconcile them via activate_plugin()/switch_theme() (Deploy.php),
        // never the generic direct-SQL options path a raw write here would
        // otherwise feed. Plain portable strings — no ref-tokenization (that
        // cross-environment stability IS the invariant), no secret guard
        // (never secrets). Unconditional, matching the existing 'managed'
        // post_meta precedent (_menu_item_*/_wp_attached_file): bespoke
        // capture code that runs regardless of which manifests are pinned,
        // the same way those fields do.
        foreach (['active_plugins', 'template', 'stylesheet'] as $managedOption) {
            $row = $this->read_option_row($managedOption);
            if ($row === null) {
                continue;
            }
            $liveCanonicalNames[$managedOption] = true;
            $v = PlainData::decode($row['option_value'], "option $managedOption");
            PlainData::assert($v, "option $managedOption");
            $v = $managedOption === 'active_plugins'
                ? array_values(array_map('strval', (array) $v))
                : (string) $v;
            $rule = $this->policy->option_rule($managedOption) ?? [];
            OptionState::assert_rule_autoload($rule, $row['autoload'], "option '$managedOption'");
            $out[$managedOption] = OptionState::present($v, $row['autoload']);
        }
        // DUO-3263: an interpreter-classified option's shadow pointer is
        // atomically removed alongside its value (empirically confirmed for
        // ACF: delete_field() leaves no orphaned _options_<name> row), so
        // the live map above can no longer answer "was this authored" for
        // a name that just went missing. The PREVIOUS capture's own
        // document still carries it (the shadow key is its own captured
        // 'present' record, same as the value) — built lazily, only if a
        // non-ref-token name actually needs it below.
        $previousOptionValues = null;
        foreach ($previousDocument === null ? [] : OptionState::records($previousDocument) as $name => $record) {
            // DUO-3292: the lifecycle-only resolver override above may
            // deliberately inspect a desired dynamic row while it is still inactive on
            // the target. When that row is absent, or exists with none of
            // its authored sub-keys populated, bind the missing canonical
            // record to the exact frozen desired value. The existing deploy
            // gate can then permit switch_theme() to initialize the row and
            // Apply to merge the frozen sub-keys, while a pre-existing row
            // changed to any non-desired value remains fail-closed. Ordinary
            // capture never enables this: inactive dynamic rows remain
            // uncaptured environment-local residue.
            $bindDynamic = $bindMissingDynamicDesired
                && ($record['state'] ?? null) === 'present'
                && $this->policy->dynamic_option_rule_for_prefix((string) $name) !== null
                && !isset($out[$name]);
            if ($bindDynamic) {
                $out[$name] = OptionState::deleted($record);
                continue;
            }
            if (isset($out[$name]) || isset($liveCanonicalNames[$name])) {
                continue; // still live (possibly omitted because a ref dropped) or replaced by a present record
            }
            if ($record['state'] === 'deleted') {
                $out[$name] = $record; // absence converges: preserve the durable intent byte-for-byte
                continue;
            }
            $details = str_contains((string) $name, '{{')
                ? $this->policy->canonical_option_name_ref_details((string) $name)
                : $this->policy->option_rule_details_for_option(
                    (string) $name,
                    $previousOptionValues ??= OptionState::values($previousDocument)
                );
            $rule = $details['rule'] ?? [];
            if ($record['state'] === 'present'
                && ($rule['class'] ?? null) === 'authored' && empty($rule['sub_keys'])) {
                $out[$name] = OptionState::deleted($record, !empty($rule['deletion_witness']));
            } else {
                $out[$name] = OptionState::absent();
            }
        }
        $required = array_fill_keys(array_keys($this->policy->authored_options()), true);
        $required += array_fill_keys(array_keys($this->policy->sub_keyed_options()), true);
        foreach (['active_plugins', 'template', 'stylesheet'] as $managedOption) {
            if (($this->policy->option_rule($managedOption)['class'] ?? null) === 'managed') {
                $required[$managedOption] = true;
            }
        }
        foreach ($required as $name => $_) {
            if (!isset($out[$name])) {
                $out[$name] = OptionState::absent();
            }
        }
        return OptionState::document($out);
    }

    /**
     * Lifecycle handoff variant of verify_engine_support(). It checks only
     * tables that this narrow path can actually read (options, identity
     * metadata, core rows used by ref triage, and the Duo ledger). In
     * particular, a declared Woo/custom table is not inspected unless an
     * option_name_refs rule makes that table part of this path's own safety
     * check; plugin-owned typed tables remain outside the lifecycle boundary.
     */
    private static function verify_options_engine_support(Policy $policy): void {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $tables = [
            $wpdb->postmeta, $wpdb->termmeta, $wpdb->posts, $wpdb->terms,
            $wpdb->term_taxonomy, $wpdb->options,
            $prefix . 'duo_map', $prefix . 'duo_state', $prefix . 'duo_kv',
        ];
        $refKinds = array_fill_keys(array_map(
            static fn(array $rule): string => (string) ($rule['id_kind'] ?? ''),
            $policy->option_name_ref_rules()
        ), true);
        foreach ($policy->declared_tables() as $name => $decl) {
            if (!isset($refKinds[(string) ($decl['id_kind'] ?? '')])) {
                continue;
            }
            $tables[] = $prefix . preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
        }
        $tables = array_values(array_unique(array_filter($tables, static fn($table): bool => (string) $table !== '')));
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)",
            $tables
        ), ARRAY_A) ?: [];
        $bad = [];
        foreach ($rows as $row) {
            $engine = strtoupper((string) ($row['ENGINE'] ?? ''));
            if ($engine !== '' && $engine !== 'INNODB') {
                $bad[] = "{$row['TABLE_NAME']} (engine: $engine)";
            }
        }
        if ($bad) {
            sort($bad);
            throw new \RuntimeException(
                'duo: lifecycle options snapshot refused — consistent-snapshot isolation requires InnoDB, but '
                . "the following lifecycle-read table(s) use a different storage engine:\n  - "
                . implode("\n  - ", $bad)
                . "\nConvert the table(s) to InnoDB and re-run deploy."
            );
        }
    }

    /**
     * Shared per-sub-key capture loop for BOTH Policy::sub_keyed_options()
     * (DUO-3233, exactly-named blobs — polylang/wpseo) and
     * Policy::dynamic_options() (DUO-3264, a blob whose own NAME is
     * computed, e.g. theme_mods_<active stylesheet>) — the two differ only
     * in how $name itself was found; once found, capturing named,
     * authored, live-populated sub-keys through the same guard_secret()/
     * capture_value() path every other authored value uses is identical
     * either way, so this is the one place that logic lives. $rule needs
     * only 'sub_keys' and (when anything gets captured) 'autoload' —
     * sub_keyed_options()'s own return shape already has both;
     * dynamic_options callers synthesize the same small shape from
     * Policy::resolve_dynamic_option()'s own return.
     *
     * @param array<string,bool> $liveCanonicalNames
     * @param array<string,mixed> $out
     */
    private function capture_option_sub_keys(
        string $name,
        array $rule,
        bool $forceUnresolvedRefs,
        array &$liveCanonicalNames,
        array &$out
    ): void {
        $subKeys = $rule['sub_keys'] ?? [];
        $row = $this->read_option_row($name);
        if ($row === null) {
            return; // option doesn't exist live at all -- nothing to carve a sub-key out of
        }
        $liveCanonicalNames[$name] = true;
        $live = PlainData::decode($row['option_value'], "option $name");
        PlainData::assert($live, "option $name");
        if (!is_array($live)) {
            throw new \RuntimeException(
                "duo: option '$name' declares sub_keys but its live value is not array-shaped (got "
                . get_debug_type($live) . ') — sub_keys assumes a plain PHP-serialized map, matching every '
                . 'verified case so far (Polylang\'s polylang option, Yoast\'s wpseo option)'
            );
        }
        $captured = [];
        foreach ($subKeys as $subKey => $subRule) {
            if (($subRule['class'] ?? '') !== 'authored') {
                continue; // declared (documents intent) but not authored -- never captured, mirrors option_name_refs' own precedent
            }
            if (!array_key_exists($subKey, $live)) {
                continue; // this environment's live blob simply doesn't have this sub-key populated yet -- nothing to capture
            }
            $subVal = $live[$subKey];
            $ctx = "$name.$subKey";
            if (is_string($subVal)) {
                $this->guard_secret('options', $ctx, $subVal, $subRule);
            } elseif (empty($subRule['allow_secret'])) {
                // Array-shaped sub-key value: deep scan, deliberately
                // NOT the is_string()-gated shallow guard_secret() call
                // above -- the same reasoning option_name_refs' own
                // Secrets::hard_match_deep() call already documents.
                // (DUO-3214 has since widened guard_secret() itself to
                // deep-scan unconditionally, closing task #127 -- this
                // call site predates that fix and is left as its own
                // implementation rather than folded into guard_secret()
                // as part of that unrelated rebase, to avoid changing
                // this rule's tested error message as a side effect.)
                $secretLabel = Secrets::hard_match_deep($subVal);
                if ($secretLabel !== null) {
                    throw new \RuntimeException(
                        "duo: secret guard tripped — option '$ctx' looks like a $secretLabel but is classified "
                        . "authored (sub_keys); refusing to capture it into state/.\n"
                        . "If this is really a secret, reclassify it runtime/derived/env instead of authored.\n"
                        . 'If this is a false positive, declare "allow_secret": true on its sub_keys rule.'
                    );
                }
            }
            $capturedValue = $this->capture_value($ctx, $subVal, $subRule, $forceUnresolvedRefs);
            if ($capturedValue['included']) {
                $captured[$subKey] = $capturedValue['value'];
            }
        }
        if ($captured) {
            OptionState::assert_rule_autoload($rule, $row['autoload'], "option '$name'");
            $out[$name] = OptionState::present($captured, $row['autoload']);
        }
    }

    /** @return ?array{option_value:string,autoload:string} */
    private function read_option_row(string $name): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        return ['option_value' => (string) $row['option_value'], 'autoload' => (string) $row['autoload']];
    }

    /** All live options as a name => raw value map — the candidate set
     *  option_name_refs patterns test against (task #93), and (DUO-3263)
     *  the sibling-lookup context a namespace-owned option's interpreter
     *  hook needs (a shadow-key pointer, exactly like post/term meta's
     *  $allMeta). One full scan per capture, not per-pattern/per-option:
     *  cheap (option_name is indexed), and Policy::rule()'s existing
     *  pattern-fallback loop already sets the precedent of testing a
     *  candidate against every declared pattern in PHP rather than pushing
     *  regex evaluation into SQL. */
    private function all_options_map(): array {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options}", ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['option_name']] = (string) $row['option_value'];
        }
        return $out;
    }

    /**
     * Secret guard (DESIGN.md 3.1 "Secret guard"): a hard-pattern match on an
     * authored value aborts capture — naming the key, the label, and the
     * escape hatch (a rule may declare "allow_secret": true, in site policy
     * or a manifest, for a confirmed false positive). Fails fast on the
     * first match, like PlainData::assert() above — this is a hard security
     * abort, not the batched loud-and-blocking classification gate.
     *
     * DUO-3214: $v is untyped (was `string $v`) and this now calls
     * Secrets::hard_match_deep(), not hard_match() — both call sites used to
     * gate their call behind `is_string($v)`, so an authored post_meta/
     * option value that decoded to an ARRAY (a plugin's serialized settings
     * blob) got ZERO secret scanning in any branch downstream, unlike
     * Snapshot::guard_secret() (typed-snapshot columns/attached-meta) and
     * the option_name_refs inline scan (both already deep-scanning since
     * the wave-1 security subset — see their own docblocks for the
     * identical reasoning). Widening the method and dropping the gate at
     * both call sites reuses that same proven mechanism instead of a third
     * reimplementation. PlainData::assert() already ran on $v before every call
     * site reaches this (it throws on any PHP object anywhere in the
     * structure), so hard_match_deep()'s array/string/other-scalar walk
     * covers every shape $v can actually have here.
     *
     * 64KB-skip semantics (DUO-3214 wave-2 decision, stated explicitly
     * since it wasn't obvious which reading was intended): PER-LEAF, not
     * whole-value. Secrets::MAX_LEN gates each individual string hard_match()
     * runs against, and hard_match_deep() calls hard_match() once per string
     * LEAF of the structure — so a large array whose individual string
     * values are all under 64KB is still fully scanned leaf-by-leaf, even
     * if the array's total serialized size is not; only a single leaf
     * itself over 64KB skips (matching the pre-existing scalar behavior
     * exactly, just applied at the leaf granularity an array introduces).
     * This keeps the "cheap-scan requirement" Secrets.php's own docblock
     * states — no single regex ever runs against more than MAX_LEN bytes —
     * without needing a separate whole-structure size check.
     *
     * allow_secret already covers array values with no further change: the
     * rule-level escape hatch below is checked before any type-specific
     * scanning logic runs, so it short-circuits identically regardless of
     * whether $v is a scalar or an array.
     */
    private function guard_secret(string $section, string $key, $v, array $rule, string $context = ''): void {
        if (!empty($rule['allow_secret'])) {
            return;
        }
        $label = Secrets::hard_match_deep($v);
        if ($label === null) {
            return;
        }
        throw new \RuntimeException(
            "duo: secret guard tripped — $section '$key'$context looks like a $label but is classified authored; "
            . "refusing to capture it into state/.\n"
            . "If this is really a secret, reclassify it env-bound or runtime instead of authored.\n"
            . "If this is a false positive, allow it explicitly:\n"
            . "  wp duo classify --repo={$this->repo} --set '$section:$key=authored' --allow-secret"
        );
    }

    /** User-meta-only PII gate; explicit authored classification is not consent. */
    private function guard_personal_data(string $key, $value, array $rule, string $login): void {
        if (!empty($rule['allow_pii'])) {
            return;
        }
        $label = PersonalData::match_deep($key, $value);
        if ($label === null) {
            return;
        }
        throw new \RuntimeException(
            "duo: PII guard tripped — user_meta '$key' on exact login '$login' looks like $label but is "
            . "classified authored; refusing to capture it into state/.\n"
            . 'Keep it runtime/env, or declare "allow_pii": true on this exact user_meta rule after review.'
        );
    }

    /**
     * Options must never propagate env-local numeric ids: unmapped ref => skip
     * key. Id 0 is WordPress's ordinary "unset" for these options (fresh sites
     * have page_on_front=0 etc.) — skipped silently, not warned as dangling.
     *
     * Task #73: an unmapped id is either DANGLING (no such row exists at
     * all — the target was deleted, or never existed; e.g. a stale
     * wp_page_for_privacy_policy after its page was removed) or UNSCOPED
     * (the row genuinely exists but its post_type/taxonomy was never added
     * to policy scope, so it was never minted a uuid — e.g.
     * elementor_active_kit when elementor_library isn't in
     * policy.post_types). Dangling keeps today's exact warn-and-drop
     * behavior (spec'd, correct, must not regress). Unscoped is a policy
     * gap a human can actually fix, so it queues into $this->unscopedRefs
     * for build()'s loud-and-blocking gate instead of silently vanishing
     * — unless $forceUnresolvedRefs (--force-unresolved-refs) asks for the
     * old best-effort drop explicitly. Array-ref elements get the exact
     * same per-element treatment as the scalar case (acceptance criterion
     * 3 — scalar and array refs must not diverge in severity).
     */
    private function option_ref_tokens(string $name, $value, string $ref, bool $forceUnresolvedRefs = false) {
        if (str_ends_with($ref, '[]')) {
            $kind = substr($ref, 0, -2);
            $ok = [];
            foreach ((array) $value as $v) {
                $id = (int) $v;
                if ($id === 0) {
                    continue;
                }
                $tok = $this->tokens->id_to_token($id, $kind);
                if ($tok === null) {
                    if (!$this->queue_or_warn_unscoped($name, $kind, $id, $forceUnresolvedRefs)) {
                        $this->tokens->warnings[] = "option $name: unmanaged $kind id $v dropped";
                    }
                    continue;
                }
                $ok[] = $tok;
            }
            return $ok;
        }
        $id = (int) $value;
        if ($id === 0) {
            return null;
        }
        $tok = $this->tokens->id_to_token($id, $ref);
        if ($tok === null) {
            if (!$this->queue_or_warn_unscoped($name, $ref, $id, $forceUnresolvedRefs)) {
                $this->tokens->warnings[] = "option $name: unmanaged $ref id $id — key skipped";
            }
            return null;
        }
        return $tok;
    }

    /**
     * Shared dangling-vs-unscoped triage for option_ref_tokens()'s scalar
     * and array branches. Returns true when the violation was queued as
     * UNSCOPED (caller must NOT also emit its own warning — build()'s gate
     * reports this instead) or false when the caller should fall through
     * to its ordinary warn-and-drop, for any of three reasons:
     *   - the target is genuinely DANGLING (ref_target_type() found no
     *     real row at all — out of scope for this task, spec'd, unchanged);
     *   - the target's type IS already in policy scope, but THIS build
     *     simply hasn't minted it a uuid yet — Capture::snapshot()'s
     *     non-minting mode (plan/apply's drift check against a target
     *     environment before its own first capture) fails id_to_token()
     *     for EVERY not-yet-minted entity regardless of scope, so that
     *     alone can never be the unscoped signal: checking id_to_token()'s
     *     success is a MINTING check, not a POLICY check, and conflating
     *     the two would hard-abort `duo apply` on essentially any fresh
     *     target site using core.json's default_category (caught
     *     empirically running this task's own core-manifest conformance
     *     validation — a fresh install's own term_id 1 "Uncategorized" is
     *     unminted-but-in-scope, not unscoped, the first time anything
     *     snapshots it). Scope is decided ONLY by policy membership below,
     *     the same source of truth build_post()'s own meta gate uses,
     *     never by whether identity happens to exist yet on this build;
     *   - $forceUnresolvedRefs explicitly asked for the old best-effort
     *     behavior regardless of which of the above this is.
     */
    private function queue_or_warn_unscoped(string $option, string $kind, int $id, bool $force): bool {
        if ($force) {
            return false;
        }
        $targetType = self::ref_target_type($id, $kind);
        if ($targetType === null) {
            return false; // dangling — caller's normal warn-and-drop handles it
        }
        $inPolicyScope = $kind === 'term'
            ? in_array($targetType, $this->policy->taxonomies(), true)
            : in_array($targetType, $this->policy->post_types(), true);
        if ($inPolicyScope) {
            return false; // real row, correctly scoped, just not minted on THIS build yet
        }
        $this->unscopedRefs[] = ['option' => $option, 'kind' => $kind, 'id' => $id, 'target_type' => $targetType];
        return true;
    }

    /**
     * The target row's own post_type ('post' kind) or taxonomy ('term'
     * kind) name, if $id names a real, addressable row — independent of
     * whether it's in THIS build's policy scope (i.e. independent of
     * whether Tokens::id_to_token() can resolve it, which requires a
     * ledger uuid, which in turn requires the row's type to already be in
     * policy.post_types/taxonomies). Null means no such row exists at all:
     * DANGLING. A non-null return alongside a failed id_to_token() means
     * UNSCOPED. Only 'post'/'term' are checked (options' only ref kinds
     * per Policy::set_rule()'s validation, aside from 'user' — no shipped
     * manifest declares a user-ref option today, and this returns null for
     * any other kind, i.e. the safe, pre-existing dangling-style fallback).
     *
     * Excludes post_type=revision/post_status=auto-draft for the 'post'
     * kind, mirroring Pending::resolve_id()'s identical exclusion: neither
     * is ever a valid policy.post_types scope target, so reporting either
     * as "just add this to policy.post_types" would be actionable-sounding
     * but wrong advice — closer to dangling than unscoped.
     *
     * public static (DUO-3212): has no instance dependency at all (only
     * global $wpdb and its own two parameters) — Blocks.php's own unscoped-
     * vs-dangling triage for block_attrs refs calls this directly rather
     * than duplicating the query shapes (and their documented revision/
     * auto-draft exclusion) a second time.
     */
    public static function ref_target_type(int $id, string $kind): ?string {
        global $wpdb;
        if ($id <= 0) {
            return null;
        }
        if ($kind === 'post') {
            $type = $wpdb->get_var($wpdb->prepare(
                "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d AND post_type != 'revision' AND post_status != 'auto-draft'",
                $id
            ));
            return $type === null ? null : (string) $type;
        }
        if ($kind === 'term') {
            $tax = $wpdb->get_var($wpdb->prepare(
                "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d LIMIT 1", $id
            ));
            return $tax === null ? null : (string) $tax;
        }
        return null;
    }

    /**
     * DUO-3259: the shape-agnostic core of queue_or_warn_unscoped()/
     * Blocks::queue_unscoped() above, extracted so a THIRD ref-carrying
     * surface (Shortcodes.php) can reuse the identical three-way decision
     * without a third hand-copy of it. Deliberately NOT refactored into
     * the two existing (already-shipped, already-tested) callers — this
     * is purely additive; queue_or_warn_unscoped() and Blocks::
     * queue_unscoped() keep their own inline copies rather than risk a
     * regression in working code for a DRY improvement with zero
     * behavior change. See queue_or_warn_unscoped()'s own docblock two
     * methods up for the full three-reasons reasoning — reproduced in
     * miniature here: returns the real target type string when $id is
     * genuinely UNSCOPED (a real row whose type is outside $policy's
     * scope), or null for any of DANGLING (no real row), in-scope-but-
     * not-yet-minted (a minting fact, not a policy fact), or $force.
     */
    public static function classify_unscoped_ref(int $id, string $kind, bool $force, Policy $policy): ?string {
        if ($force) {
            return null;
        }
        $targetType = self::ref_target_type($id, $kind);
        if ($targetType === null) {
            return null; // dangling
        }
        $inPolicyScope = $kind === 'term'
            ? in_array($targetType, $policy->taxonomies(), true)
            : in_array($targetType, $policy->post_types(), true);
        return $inPolicyScope ? null : $targetType;
    }

    /**
     * Decode a meta/option value for json_refs/key_refs rewriting (task #11
     * wave 2): either a JSON-encoded TEXT string — rule declares
     * `"json_encoded": true`, e.g. Elementor's `_elementor_data`, which
     * Elementor's own code manually `wp_json_encode()`s into a postmeta
     * TEXT column before WordPress's ordinary maybe_unserialize()/
     * maybe_serialize() layer ever sees it (a no-op passthrough on an
     * already-string value) — or an already-native PHP array, the ordinary
     * case where maybe_unserialize() (already run by the caller) did all
     * the decoding needed, e.g. Yoast's wpseo_taxonomy_meta.
     *
     * Throws loudly on a shape mismatch rather than silently falling back
     * to opaque-string capture: a manifest declaring json_refs/key_refs for
     * a key is asserting its shape, and silently degrading would silently
     * reopen exactly the id-leak gap this mechanism exists to close —
     * matching PlainData::assert()'s own "throw, never guess" posture below.
     */
    private function decode_structured($v, array $rule, string $ctx) {
        if (!empty($rule['json_encoded'])) {
            if (!is_string($v)) {
                throw new \RuntimeException("duo: $ctx declares json_encoded but its (unserialized) value is not a string");
            }
            $decoded = json_decode($v, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException(
                    "duo: $ctx declares json_refs/key_refs (json_encoded) but its value is not valid JSON: " . json_last_error_msg()
                );
            }
            return $decoded;
        }
        if (!is_array($v)) {
            throw new \RuntimeException(
                "duo: $ctx declares json_refs/key_refs but its value is neither a JSON-encoded string (declare \"json_encoded\": true) nor an already-structured array"
            );
        }
        return $v;
    }

}
