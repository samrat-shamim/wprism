<?php
namespace Duo;

/**
 * Capture: environment DB -> canonical state tree.
 *
 * Read-only on content except for identity minting (_duo_uuid meta + ledger
 * rows). Unclassified meta keys on in-scope entities abort loudly — the
 * loud-and-blocking gate. Entities without a uuid are unmanaged and invisible
 * (snapshot mode never mints, so a fresh environment snapshots as empty).
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
    }

    /**
     * Full capture. Writes the state tree (repo/state, or $outDir), copies
     * media + updates the ledger only when writing into the repo itself.
     *
     * @return array summary
     */
    public static function run(string $repo, ?string $outDir = null, bool $forceUnresolvedRefs = false): array {
        Canary::suppress_cron_spawn();
        Ledger::ensure();
        Identity::assert_embedded_unique();
        Ledger::prune_dead_map();
        $policy = Policy::load($repo);
        $observedDeletedTables = Snapshot::observed_deleted_mapped_uuids($policy);
        Snapshot::prune_dead_map($policy); // declared-table id_kinds get the same dead-map hygiene as post/term/tt
        $c = new self($repo, $policy);

        $intoRepo = ($outDir === null);
        $stateDir = $intoRepo ? $c->repo . '/state' : rtrim($outDir, '/');

        self::verify_engine_support($policy);

        // DUO-3213: a capture lock serializes concurrent publishers to this
        // SAME destination (Publish::lock() fails cleanly, non-blocking, if
        // another capture already holds it — see its own docblock for why).
        // Held for the full remainder of this method, including the ledger
        // bookkeeping tail below: that's "publication," end to end, and two
        // publishers interleaving any part of it is exactly what the lock
        // exists to rule out.
        $lock = Publish::lock($stateDir);
        try {
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
            $notes = Publish::recover($stateDir);
            // The previous compiled revision is the only authority from
            // which a deletion intent can be created. A first capture has no
            // prior state and therefore cannot infer a deletion. Compiling
            // before target reads also refuses to build new state on top of
            // an already-invalid repository revision.
            $previous = is_dir($c->repo . '/state')
                ? RepositoryCompiler::compile($c->repo, $policy)
                : null;
            $previousOptions = $previous?->tree()['options/core']['data'] ?? null;

            // The one consistent-snapshot transaction: every SELECT build()
            // issues, plus the _duo_uuid/duo_map identity-minting writes
            // alongside them, see one coherent point-in-time view. Retries
            // on its own (see run_in_consistent_snapshot()) if a concurrent
            // WordPress write collides with one of THIS build's own writes.
            $build = self::run_in_consistent_snapshot(function () use (
                $c, $policy, $repo, $forceUnresolvedRefs, $observedDeletedTables, $previousOptions
            ): array {
                Identity::assert_embedded_unique();
                Snapshot::assert_mapped_history_present($policy, $repo, $observedDeletedTables);
                $candidate = $c->build(true, $forceUnresolvedRefs, $previousOptions);
                Identity::assert_entities_unique($candidate['entities']);
                return $candidate;
            });
            $build['deletions'] = Deletion::capture_tombstones(
                $previous,
                $build['entities'],
                $policy
            );

            // Build + validate the COMPLETE candidate in an isolated
            // staging location — 'state/' itself is never touched until
            // Publish::swap() below, so a failure here (disk-full mid-
            // write, a crash, anything) leaves the previously-published
            // tree completely untouched.
            $staging = Publish::stage_dir($stateDir);
            Publish::write_entities($staging, array_merge($build['entities'], $build['deletions']));
            // Lint against the STAGED candidate (relocated from the old
            // post-publish call — strictly more correct: the warning now
            // reflects the tree about to be published, not one already
            // live) — still warn-only, unchanged semantics. Upgrading this
            // to a hard gate is DUO-3208's "repository semantic compiler"
            // territory, deliberately decoupled from this issue — see the
            // DUO-3213 issue comments/PR body.
            $lint = Lint::scan_tree($staging, $c->policy);
            if ($lint) {
                $build['warnings'][] = count($lint)
                    . ' suspicious unrewritten ref(s) in captured state — run: wp duo lint --repo=' . $c->repo;
            }

            if ($intoRepo) {
                // Media blobs are copied here — BEFORE the compile gate and
                // the state-tree swap below, not after (unlike before
                // DUO-3236). They are content-addressed and idempotent
                // (Publish.php's own class docblock: "unrelated to the
                // state-tree swap"), so moving this earlier changes nothing
                // about their own safety, but it is WHY the compile gate
                // below can validate this run's own new media references at
                // all: RepositoryCompiler resolves media against the real
                // repo/media directory (RepositoryCompiler::compile_staged()'s
                // docblock), and a reference to a blob this same run just
                // discovered would otherwise still be missing from disk at
                // gate time. A refused candidate can leave an orphan blob
                // copied here with no (published) entity referencing it —
                // already a normal, anticipated condition this system
                // tolerates (RepositoryCompiler::catalog_media_directory()'s
                // own "safe orphan blobs" docblock), not a new risk.
                foreach ($build['media'] as $file => $src) {
                    $dst = $c->repo . '/media/' . $file;
                    if (!is_file($dst)) {
                        Canon::write_file($dst, Canon::read_file($src));
                    }
                }
                // DUO-3236: the staged candidate must itself compile before
                // it is ever promoted to state/ — the same blocking
                // diagnostics RepositoryCompiler::compile() already
                // produces for an already-published tree, just fired one
                // step earlier, before a bad tree can become the checked-in
                // canonical state. Scoped to $intoRepo: a --out= capture
                // never touches the checked-in state/ at all (explicitly
                // non-authoritative — same carve-out as the ledger/code-
                // version bookkeeping below), so there is no canonical
                // state for an invalid --out= candidate to corrupt.
                // RepositoryCompilationException propagates uncaught, same
                // as every other loud-and-blocking gate in this method; the
                // staging dir is simply abandoned in place — the next
                // capture's own Publish::recover() discards it
                // unconditionally (see that method's own docblock), and
                // 'state/' is untouched since swap() below never runs.
                RepositoryCompiler::compile_staged($staging, $c->repo, $c->policy);
            }

            // The only step that ever touches 'state/': an atomic two-step
            // rename swap (agent/src/Publish.php). Before this line, a
            // crash changes nothing an outside reader (git, a human) can
            // observe; after it returns, 'state/' is unconditionally the
            // complete new tree.
            Publish::swap($stateDir);

            if ($intoRepo) {
                // Ledger/base hashes advance only AFTER the swap above
                // succeeded — "in the same success protocol as tree
                // publication" (the issue's own non-negotiable constraint):
                // a crash before this point leaves duo_state exactly as it
                // was, which is safe (plan/drift then sees the untouched
                // repo state as unchanged, since 'state/' itself was never
                // touched either); a crash AFTER 'state/' updates but
                // BEFORE this completes just leaves duo_state briefly
                // stale, which fails toward "more drift visible," never
                // toward silently missing a real change.
                foreach ($build['entities'] as $e) {
                    // task #88: hash the entity's derived-aware basis when it has
                    // one (posts only, today — see build()'s post-entity
                    // construction above); every other entity type has no
                    // 'hash_basis' key and falls back to hashing its literal
                    // content, unchanged from before this task.
                    Ledger::set_state_hash($e['uuid'], $e['type'], hash('sha256', $e['hash_basis'] ?? $e['content']));
                }
                Ledger::prune_state(array_merge(
                    array_column($build['entities'], 'uuid'),
                    array_column($build['deletions'], 'uuid')
                ));
                // DUO-3231: a real (into-repo) capture is, same as a
                // successful `duo deploy`, a moment Duo legitimately
                // observed this environment's code — record it as a
                // code_drift() baseline too, not just deploy. Skipped for
                // --out= (determinism-check) captures for the same reason
                // the ledger writes above are already gated on $intoRepo:
                // that mode is explicitly non-authoritative. Independent of
                // the publish lock/staging mechanism above (duo_kv is a
                // separate piece of state from state/), so its exact
                // position relative to unlock() below is not
                // safety-critical — kept inside the same block as the other
                // "capture legitimately completed" bookkeeping for
                // readability.
                Deploy::record_code_versions($c->policy);
            }
        } finally {
            Publish::unlock($lock);
        }

        $counts = ['post' => 0, 'term' => 0, 'menu' => 0, 'options' => 0, 'deletion' => 0];
        foreach ($build['entities'] as $e) {
            $counts[$e['type']] = ($counts[$e['type']] ?? 0) + 1;
        }
        $counts['deletion'] = count($build['deletions']);
        return [
            'counts' => $counts,
            'media' => count($build['media']),
            'warnings' => array_merge($notes, $build['warnings']),
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
    public static function snapshot(string $repo, bool $forceUnresolvedRefs = false): array {
        Canary::suppress_cron_spawn();
        Ledger::ensure();
        Identity::assert_embedded_unique();
        Ledger::prune_dead_map();
        $policy = Policy::load($repo);
        Snapshot::prune_dead_map($policy);
        $c = new self($repo, $policy);
        self::verify_engine_support($policy);
        $repositoryOptions = RepositoryCompiler::compile($repo, $policy)->tree()['options/core']['data'] ?? null;

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
        $build = self::run_in_consistent_snapshot(function () use ($c, $forceUnresolvedRefs, $repositoryOptions): array {
            Identity::assert_embedded_unique();
            $candidate = $c->build(false, $forceUnresolvedRefs, $repositoryOptions);
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
     *   term_meta: array<string, array{entities:int, taxonomies:string[], value_shapes:string[], reason:string}>
     * }
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
        foreach ($optionRows as $row) {
            $key = (string) $row['option_name'];
            $owner = $c->policy->option_namespace($key);
            if ($owner === null || $c->policy->owned_option_rule($key) !== null
                || $c->policy->match_option_name_ref($key) !== null) {
                continue;
            }
            $raw = (string) $row['option_value'];
            $value = is_serialized($raw) ? @unserialize(trim($raw), ['allowed_classes' => false]) : $raw;
            $options[$key] = [
                'entities' => 1,
                'owner_candidates' => [$owner['owner']],
                'value_shapes' => [get_debug_type($value)],
                'reason' => 'owner namespace matched but no exact or pattern classification exists',
            ];
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
            foreach ($c->term_meta_map((int) $t->term_id) as $key => $raw) {
                $rule = $c->policy->term_meta_rule($key);
                if ($rule !== null && ($rule['class'] ?? '') !== 'authored') {
                    continue;
                }
                $termMeta[$key]['entities'] = ($termMeta[$key]['entities'] ?? 0) + 1;
                $termMeta[$key]['taxonomies'][$t->taxonomy] = true;
                $value = is_serialized($raw) ? @unserialize(trim($raw), ['allowed_classes' => false]) : $raw;
                $termMeta[$key]['value_shapes'][get_debug_type($value)] = true;
                $termMeta[$key]['reason'] = $rule === null
                    ? 'unclassified term meta on an in-scope taxonomy'
                    : 'authored term meta is unsupported by the v1 term-file schema; taxonomy capture is blocked';
            }
        }

        return [
            'scope' => $scope,
            'options' => $options,
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
     * reproducing the identical failure.
     */
    private static function run_in_consistent_snapshot(callable $fn) {
        global $wpdb;
        $attempt = 0;
        while (true) {
            $attempt++;
            Db::query('START TRANSACTION WITH CONSISTENT SNAPSHOT', 'capture transaction start');
            self::check_transient_db_error('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            try {
                $result = $fn();
                Db::commit('capture transaction commit');
                self::check_transient_db_error('COMMIT');
                return $result;
            } catch (TransientDbException $e) {
                Db::rollback('capture transaction rollback');
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
                Db::rollback('capture transaction rollback');
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

    // ------------------------------------------------------------------

    /** @return array{entities: array, media: array<string,string>, warnings: string[]} */
    private function build(bool $mint, bool $forceUnresolvedRefs = false, ?array $previousOptions = null): array {
        global $wpdb;
        $this->unclassified = [];
        $this->unscopedRefs = [];
        $this->unscopedOptionNameRefs = [];
        // Blocks.php has no persistent instance state of its own (see its
        // docblock), so its own unscoped-violation queue lives on $this->
        // tokens (Tokens::$unscopedBlockRefs) instead of a Capture-level
        // array — reset here anyway, defensively matching the other two
        // resets above, even though a fresh Tokens instance per build (see
        // the constructor) already guarantees this starts empty.
        $this->tokens->unscopedBlockRefs = [];
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
            $uuid = $this->ensure_post_uuid((int) $p->ID, 'post', $mint);
            if ($uuid !== null) {
                $postUuids[(int) $p->ID] = $uuid;
            }
        }
        $termUuids = [];
        foreach ($terms as $t) {
            $uuid = $this->ensure_term_uuid($t, 'term', $mint);
            if ($uuid !== null) {
                $termUuids[(int) $t->term_id] = $uuid;
            }
        }
        $menus = $this->scope_menus($mint);

        // Term files have no meta field in spec v1. Unknown term-meta must
        // therefore block, and an "authored" classification must also block
        // explicitly rather than becoming an inert rule that appears to
        // resolve the review item while its value is still dropped.
        foreach ($terms as $t) {
            foreach ($this->term_meta_map((int) $t->term_id) as $key => $_) {
                $rule = $this->policy->term_meta_rule($key);
                if ($rule === null) {
                    $this->unclassified[] = "term_meta:$key (unclassified on taxonomy {$t->taxonomy})";
                } elseif (($rule['class'] ?? '') === 'authored') {
                    $this->unclassified[] = "term_meta:$key (authored is unsupported by the v1 term-file schema; taxonomy {$t->taxonomy} blocked)";
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
        $tableEntities = Snapshot::capture($this->policy, $this->tokens, $mint);
        self::check_transient_db_error('Snapshot::capture()'); // DUO-3213 checkpoint — see its docblock

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
            $front = [
                'uuid' => $uuid,
                'taxonomy' => $t->taxonomy,
                'name' => $t->name,
                'slug' => $t->slug,
                'description' => $this->term_description($t),
                'parent' => $parentUuid,
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

        // ---- options file ----
        $options = $this->build_options($mint, $forceUnresolvedRefs, $previousOptions);
        $entities[] = [
            'uuid' => 'options/core',
            'type' => 'options',
            'path' => 'options/core.json',
            'content' => Canon::encode($options),
        ];

        foreach ($tableEntities as $e) {
            $entities[] = $e;
        }

        if ($this->unclassified) {
            $keys = array_unique($this->unclassified);
            sort($keys);
            throw new \RuntimeException(
                "duo: incomplete state discovery on manifest-owned or in-scope surfaces (loud-and-blocking gate):\n  - "
                . implode("\n  - ", $keys)
                . "\nClassify them in site.duo.json policy.post_meta / policy.term_meta or a manifest."
                . " Run: wp duo pending --repo={$this->repo} for evidence + proposals, then wp duo classify --repo={$this->repo} --set '<section>:<key>=<class>'."
            );
        }

        // Task #73's loud-and-blocking gate: an authored, ref-typed OPTION
        // whose value names a REAL row that simply isn't in policy scope
        // (as opposed to a dangling reference — deleted target, handled by
        // option_ref_tokens()'s ordinary warn-and-drop, never reaches this
        // list). This is a scope gap a policy edit can actually fix, so —
        // same posture as the unclassified-meta gate above — it aborts by
        // default instead of silently vanishing from captured state.
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
                . "target genuinely exists right now, so this is a policy scope gap, not permanent data loss.\n"
                . "Add the missing post type/taxonomy to policy scope above and re-run capture, or reclassify the "
                . "option, or pass --force-unresolved-refs to drop it anyway (same as a dangling reference)."
            );
        }

        // option_name_refs' own unscoped gate (task #93) — same posture as
        // task #73's option-ref gate immediately above, adapted for a table
        // id_kind instead of a post_type/taxonomy: the embedded id names a
        // row that genuinely exists in its declared table, just never
        // minted a uuid (the table isn't pinned as authored_snapshot in
        // currently-loaded manifests, most likely) — a fixable manifest
        // gap, not permanent data loss, so it aborts by default instead of
        // silently vanishing. --force-unresolved-refs is the identical
        // escape hatch task #73 already established, reused rather than a
        // second flag.
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

        return ['entities' => $entities, 'media' => $media, 'warnings' => $this->tokens->warnings];
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

    private function ensure_post_uuid(int $id, string $entityType, bool $mint): ?string {
        global $wpdb;
        $uuid = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_duo_uuid' LIMIT 1",
            $id
        ));
        if (!$uuid) {
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
        Ledger::set($uuid, $entityType, Ledger::KIND_POST, $id);
        // DUO-3213 checkpoint: catches a deadlock/lock-wait-timeout from
        // Ledger::set()'s own two queries — see check_transient_db_error()'s
        // docblock.
        self::check_transient_db_error("identity ledger for post $id");
        return $uuid;
    }

    private function ensure_term_uuid(object $t, string $entityType, bool $mint): ?string {
        global $wpdb;
        $termId = (int) $t->term_id;
        $uuid = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = '_duo_uuid' LIMIT 1",
            $termId
        ));
        if (!$uuid) {
            if (!$mint) {
                return null;
            }
            $uuid = Uuid::v7();
            Db::insert($wpdb->termmeta, ['term_id' => $termId, 'meta_key' => '_duo_uuid', 'meta_value' => $uuid], null, 'capture mint term identity');
            // DUO-3213 checkpoint — see ensure_post_uuid()'s identical comment.
            self::check_transient_db_error("mint _duo_uuid for term $termId");
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
        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        if ($decoded === false && $raw !== serialize(false)) {
            throw new \RuntimeException(
                "duo: taxonomy '{$t->taxonomy}' declares description_refs but term {$t->slug}'s description"
                . ' does not unserialize as PHP data: ' . var_export($raw, true)
            );
        }
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
                . 'spec v1 has no portable secret representation for post passwords, so capture refuses it'
            );
        }

        // meta, classified
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key ASC, meta_id ASC",
            $id
        ), ARRAY_A) ?: [];
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['meta_key']][] = $r['meta_value'];
        }
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
            $rule = $this->policy->meta_rule_for_post($key, $flatMeta);
            if ($rule === null) {
                $this->unclassified[] = "post_meta:$key";
                continue;
            }
            if (($rule['class'] ?? '') !== 'authored') {
                continue;
            }
            if (count($values) > 1) {
                throw new \RuntimeException("duo: multi-value authored meta '$key' on post $id unsupported in v0");
            }
            $v = maybe_unserialize($values[0]);
            self::assert_plain($v, "post $id meta $key");
            // DUO-3214: unconditional, not gated on is_string($v) — an
            // authored value that decoded to an array (a serialized
            // settings blob) must be scanned too; guard_secret() deep-scans
            // internally now (see its own docblock).
            $this->guard_secret('post_meta', $key, $v, $rule, " on post $id");
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                $decoded = $this->decode_structured($v, $rule, "post $id meta $key");
                $v = $this->tokens->struct_capture($decoded, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null);
            } elseif (!empty($rule['ref'])) {
                $v = $this->tokens->meta_value_to_tokens($v, $rule);
                if ($v === null) {
                    // dangling scalar ref: key skipped (warned inside Tokens) —
                    // a raw env-local id must never reach canonical state
                    continue;
                }
            } elseif (is_string($v)) {
                $v = $this->tokens->tokenize_text($v);
            }
            // DUO-3214(b) / task #123: applied LAST, to the fully-processed
            // value, so it composes correctly with ref/json_refs rewriting
            // above rather than racing it — order preservation is about
            // how Canon serializes the FINAL value, not an input-shape
            // concern. See Canon::normalize()'s docblock for the mechanism.
            if (!empty($rule['order_preserving'])) {
                $v = new OrderPreserved($v);
            }
            $meta[$key] = $v;
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
        if ($taxes && !$isAttachment) {
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
            $src = trailingslashit($up['basedir']) . $attachedFile;
            if (!is_file($src)) {
                throw new \RuntimeException("duo: attachment $id file missing: $src");
            }
            $sha = hash_file('sha256', $src);
            $ext = pathinfo($attachedFile, PATHINFO_EXTENSION);
            $mediaFile = $sha . ($ext ? ".$ext" : '');
            $front['file'] = $attachedFile;
            $front['media'] = $mediaFile;
            $front['mime'] = $p->post_mime_type;
            $front['alt'] = $alt;
            $mediaRef = [$mediaFile, $src];
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
    private function scope_menus(bool $mint): array {
        global $wpdb;
        $menuTerms = $wpdb->get_results(
            "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent
             FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = 'nav_menu' ORDER BY t.term_id ASC"
        ) ?: [];
        if (!$menuTerms) {
            return [];
        }

        // menu -> locations, from the active theme's mods
        $stylesheet = (string) get_option('stylesheet');
        $mods = get_option('theme_mods_' . $stylesheet);
        $locByTerm = [];
        if (is_array($mods) && !empty($mods['nav_menu_locations'])) {
            foreach ($mods['nav_menu_locations'] as $loc => $tid) {
                $locByTerm[(int) $tid][] = (string) $loc;
            }
        }

        $menus = [];
        foreach ($menuTerms as $mt) {
            $uuid = $this->ensure_term_uuid($mt, 'menu', $mint);
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
                $iu = $this->ensure_post_uuid((int) $ip->ID, 'menu_item', $mint);
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
                $classes = maybe_unserialize($m['_menu_item_classes'] ?? '');
                $classes = is_array($classes)
                    ? array_values(array_filter(array_map('strval', $classes), fn($s) => $s !== ''))
                    : [];
                $itemList[] = [
                    'uuid' => $iu,
                    'type' => $type,
                    'object' => (string) ($m['_menu_item_object'] ?? ''),
                    'ref' => $ref,
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

            $locations = $locByTerm[(int) $mt->term_id] ?? [];
            sort($locations, SORT_STRING);
            $menus[] = [
                'uuid' => $uuid,
                'slug' => $mt->slug,
                'front' => [
                    'uuid' => $uuid,
                    'name' => $mt->name,
                    'slug' => $mt->slug,
                    'locations' => $locations,
                    'items' => $itemList,
                ],
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

    /** Mirrors post_meta_map() for termmeta — used by gate_scan() only in v0
     *  (no term-meta capture pipeline exists yet; see gate_scan()'s docblock). */
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
        ?array $previousDocument = null
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
            $v = maybe_unserialize($row['option_value']);
            self::assert_plain($v, "option $name");
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
        $liveOptionNames = $this->option_name_scan();
        foreach ($liveOptionNames as $name) {
            $owner = $this->policy->option_namespace($name);
            if ($owner === null) {
                continue;
            }
            $rule = $this->policy->owned_option_rule($name);
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
            $v = maybe_unserialize($row['option_value']);
            self::assert_plain($v, "option $name");
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
            $subKeys = $rule['sub_keys'] ?? [];
            $row = $this->read_option_row($name);
            if ($row === null) {
                continue; // option doesn't exist live at all -- nothing to carve a sub-key out of
            }
            $liveCanonicalNames[$name] = true;
            $live = maybe_unserialize($row['option_value']);
            self::assert_plain($live, "option $name");
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

        // option_name_refs (task #93): options discovered by NAME PATTERN
        // — Policy::authored_options() above is exact-whitelist only and
        // never finds these rows at all (r1b-shop.md's own finding:
        // WooCommerce's woocommerce_<method_id>_<instance_id>_settings
        // rows are otherwise invisible to capture). One full option-NAME
        // scan (names only, not values — cheap, and this runs once per
        // capture, not per-option), tested against every declared pattern;
        // $forceUnresolvedRefs reuses task #73's exact escape hatch rather
        // than inventing a second flag.
        foreach ($this->policy->option_name_ref_rules() as $rule) {
            if (($rule['class'] ?? '') !== 'authored') {
                continue; // future-proofing: a runtime-classified family is discovered, never captured
            }
            foreach ($liveOptionNames as $name) {
                if (!preg_match('/' . $rule['match'] . '/', $name, $m, PREG_OFFSET_CAPTURE) || !isset($m['id'])) {
                    continue;
                }
                $id = (int) $m['id'][0];
                $offset = $m['id'][1];
                $length = strlen($m['id'][0]);
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
                    if ($mint && !$forceUnresolvedRefs && Snapshot::row_exists_for_kind($this->policy, $rule['id_kind'], $id)) {
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
                $v = maybe_unserialize($row['option_value']);
                self::assert_plain($v, "option $name");
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
            $v = maybe_unserialize($row['option_value']);
            self::assert_plain($v, "option $managedOption");
            $v = $managedOption === 'active_plugins'
                ? array_values(array_map('strval', (array) $v))
                : (string) $v;
            $rule = $this->policy->option_rule($managedOption) ?? [];
            OptionState::assert_rule_autoload($rule, $row['autoload'], "option '$managedOption'");
            $out[$managedOption] = OptionState::present($v, $row['autoload']);
        }
        foreach ($previousDocument === null ? [] : OptionState::records($previousDocument) as $name => $record) {
            if (isset($out[$name]) || isset($liveCanonicalNames[$name])) {
                continue; // still live (possibly omitted because a ref dropped) or replaced by a present record
            }
            if ($record['state'] === 'deleted') {
                $out[$name] = $record; // absence converges: preserve the durable intent byte-for-byte
                continue;
            }
            $details = str_contains((string) $name, '{{')
                ? $this->policy->canonical_option_name_ref_details((string) $name)
                : $this->policy->option_rule_details((string) $name);
            $rule = $details['rule'] ?? [];
            if ($record['state'] === 'present'
                && ($rule['class'] ?? null) === 'authored' && empty($rule['sub_keys'])) {
                $out[$name] = OptionState::deleted($record);
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

    /** All live option NAMES (not values) — the candidate set
     *  option_name_refs patterns test against (task #93). One full scan
     *  per capture, not per-pattern/per-option: cheap (option_name is
     *  indexed, and this reads only that one column), and Policy::rule()'s
     *  existing pattern-fallback loop already sets the precedent of
     *  testing a candidate against every declared pattern in PHP rather
     *  than pushing regex evaluation into SQL. */
    private function option_name_scan(): array {
        global $wpdb;
        return $wpdb->get_col("SELECT option_name FROM {$wpdb->options}") ?: [];
    }

    /**
     * Secret guard (DESIGN.md 3.1 "Secret guard"): a hard-pattern match on an
     * authored value aborts capture — naming the key, the label, and the
     * escape hatch (a rule may declare "allow_secret": true, in site policy
     * or a manifest, for a confirmed false positive). Fails fast on the
     * first match, like assert_plain() above — this is a hard security
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
     * reimplementation. assert_plain() already ran on $v before every call
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
     * matching assert_plain()'s own "throw, never guess" posture below.
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

    private static function assert_plain($v, string $ctx): void {
        if (is_object($v)) {
            throw new \RuntimeException(
                "duo: non-plain serialized data (PHP object) in $ctx — needs the verbatim-preservation path (post-v0)"
            );
        }
        if (is_array($v)) {
            foreach ($v as $x) {
                self::assert_plain($x, $ctx);
            }
        }
    }
}
