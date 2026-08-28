<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/CaptureGateScanner.php';
require_once __DIR__ . '/CapturePublicationRecovery.php';
require_once __DIR__ . '/CapturePublicationWorkflow.php';
require_once __DIR__ . '/CaptureSnapshotService.php';
require_once __DIR__ . '/CaptureTransaction.php';
require_once __DIR__ . '/../Repository/CompiledArtifact.php';
require_once __DIR__ . '/InitialCaptureBoundary.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Policy/AdapterLibrary.php';
require_once __DIR__ . '/../Kernel/ReferenceScopeClassifier.php';
require_once __DIR__ . '/../Scope/ScopedCaptureProjector.php';

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
 * in-place: every candidate read, plus the identity-minting writes alongside
 * them, runs inside one InnoDB `START TRANSACTION WITH CONSISTENT SNAPSHOT`
 * (owned by CaptureTransaction) so a single capture always
 * sees one coherent point-in-time view, regardless of what concurrent
 * WordPress requests commit meanwhile — never a tree assembled from two
 * different moments. The resulting entities are written to a STAGING
 * directory and only ever swapped into the published `state/` atomically
 * (agent/src/Publication/Publish.php) once every file is down and validated; a crash,
 * disk-full, or OOM-kill at any point before that swap leaves the
 * previously-published tree completely untouched. Concurrent publishers to
 * the same destination are serialized by a capture lock (Publish::lock()).
 * See Publish.php's own docblock for the filesystem mechanics and
 * CaptureTransaction for the deadlock/lock-wait-timeout retry boundary.
 */
final class Capture {
    /**
     * Preserve the historical non-instantiable boundary. Capture is a static
     * command-facing facade; mutable per-capture state belongs to its focused
     * collaborators.
     */
    private function __construct(string $repo, Policy $policy) {}

    /**
     * Full capture. Writes the state tree (repo/state, or $outDir), copies
     * media + updates the ledger only when writing into the repo itself.
     *
     * @return array summary
     */
    public static function run(
        string $repo,
        ?string $outDir = null,
        bool $forceUnresolvedRefs = false,
        ?array $scopeRequest = null,
        ?string $hostEnvironment = null,
        ?AdapterLibrary $adapterLibrary = null
    ): array {
        return self::run_internal(
            $repo, $outDir, $forceUnresolvedRefs, null, false,
            null, null, null, null, $scopeRequest, $hostEnvironment, $adapterLibrary
        );
    }

    /**
     * Init-only capture path. The caller already holds Publish's state lock
     * across its config/code publication and lends it here. Completing the
     * initial code lifecycle inside the capture transaction prevents a late
     * marker refusal from committing a ghost state/identity baseline.
     *
     * @param resource $publicationLock
     * @param null|callable(array<string,mixed>,array<string,mixed>,array<string,mixed>):void $onPayloadReady
     * @return array summary
     */
    public static function run_initial_baseline(
        string $repo,
        $publicationLock,
        string $initialStateIdentity,
        string $initialMediaIdentity,
        string $initialConfigIdentity,
        ?callable $onPayloadReady = null
    ): array {
        if (!is_resource($publicationLock)) {
            throw new \InvalidArgumentException('duo: init capture requires its held publication lock');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $initialStateIdentity) !== 1) {
            throw new \InvalidArgumentException('duo: init capture requires an exact empty-state reservation identity');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $initialMediaIdentity) !== 1) {
            throw new \InvalidArgumentException('duo: init capture requires an exact empty-media reservation identity');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $initialConfigIdentity) !== 1) {
            throw new \InvalidArgumentException('duo: init capture requires an exact confirmed-config identity');
        }
        return self::run_internal(
            $repo,
            null,
            false,
            $publicationLock,
            true,
            $initialStateIdentity,
            $initialMediaIdentity,
            $initialConfigIdentity,
            $onPayloadReady
        );
    }

    /** @param null|resource $publicationLock @return array summary */
    private static function run_internal(
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
        ?string $hostEnvironment = null,
        ?AdapterLibrary $adapterLibrary = null
    ): array {
        return CapturePublicationWorkflow::run(
            $repo,
            $outDir,
            $forceUnresolvedRefs,
            $publicationLock,
            $initialBaseline,
            $initialStateIdentity,
            $initialMediaIdentity,
            $initialConfigIdentity,
            $onInitialPayloadReady,
            $scopeRequest,
            $hostEnvironment,
            $adapterLibrary
        );
    }

    /**
     * Accept either the full local contract (direct wp-cli) or the compact
     * selectors+hash proof forwarded by the host transport. In both cases
     * the target recomputes and associates the complete contract itself.
     *
     * @return array<string,mixed>
     */
    private static function scope_contract_for_request(
        array $request,
        CompiledRepository $compiled,
        Policy $policy
    ): array {
        return ScopedCaptureProjector::contractForRequest($request, $compiled, $policy);
    }

    /**
     * DUO-3427: typed, because this refusal has an entirely reviewable shape.
     *
     * A retained init recovery journal is not an internal fault: the operator
     * is told exactly what exists and exactly what to run, in a fixed engine
     * sentence that names no path, selector, or value. As a bare
     * RuntimeException it reached JSON callers as "capture refused at an
     * unclassified safety gate" with details_redacted, sending an operator
     * holding an interrupted init to private evidence for the one instruction
     * that IS public — the DUO-3398/DUO-3399 shape, and the same treatment
     * DUO-3421 gave the init side's proven rollback. The human rendering is
     * unchanged: the operator message below is the sentence this gate has
     * always printed.
     */
    private static function assert_no_interrupted_init(string $repo): void {
        InitialCaptureBoundary::assertNoInterruptedInit($repo);
    }


    private static function lint_warning(
        int $count,
        string $repo,
        ?string $hostEnvironment,
        bool $intoRepo = true
    ): string {
        return CapturePublicationWorkflow::lintWarning($count, $repo, $hostEnvironment, $intoRepo);
    }

    /** Keep a copy-ready argument bounded and on one terminal line. */
    private static function shell_arg(string $value): ?string {
        return CapturePublicationWorkflow::shellArg($value);
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
        ?Policy $policy = null,
        ?array &$planObservations = null,
        ?array $binding = null
    ): array {
        return CaptureSnapshotService::snapshot(
            $repo,
            $forceUnresolvedRefs,
            $compiled,
            $policy,
            $planObservations,
            $binding
        );
    }

    /**
     * Strict observation twin of snapshot() for `duo explain`.
     *
     * Ordinary plan/apply intentionally retain their established maintenance
     * boundary: ensure the ledger, repair historical widths, and prune stale
     * identity rows before comparing state. An explanation has no authority
     * to perform those repairs. It proves the existing ledger schema and every
     * live identity through SELECTs, refuses missing/stale evidence, captures
     * one coherent MVCC view, and performs no DDL/DML, map pruning, provider
     * negotiation, or action invocation.
     *
     * @return array<string, array{type:string,hash:string,content:string,path:string}>
     */
    public static function snapshot_read_only(
        string $repo,
        bool $forceUnresolvedRefs = false,
        ?CompiledRepository $compiled = null,
        ?Policy $policy = null
    ): array {
        return CaptureSnapshotService::snapshotReadOnly(
            $repo,
            $forceUnresolvedRefs,
            $compiled,
            $policy
        );
    }

    /**
     * Embedded/captured identity contradictions are known repair
     * preconditions, not unclassified explain failures. Keep this wrapper
     * narrow: ordinary database, policy, and capture failures retain their
     * existing classification instead of being mislabeled as repairable.
     */
    private static function assert_read_only_identity_precondition(callable $assertion): void {
        CaptureSnapshotService::assertReadOnlyIdentityPrecondition($assertion);
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
        return CaptureSnapshotService::buildReadOnlyExport(
            $repo,
            $policy,
            $previousOptions,
            $previousUserLogins,
            $forceUnresolvedRefs
        );
    }

    /** Read-only preflight shared by RefreshExport's snapshot boundary. */
    public static function assert_read_only_export_engine_support(Policy $policy): void {
        CaptureSnapshotService::assertReadOnlyExportEngineSupport($policy);
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
        return CaptureSnapshotService::snapshotOptionsCore(
            $repo,
            $forceUnresolvedRefs,
            $compiled,
            $policy
        );
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
        return CaptureSnapshotService::repositoryOptions($repo, $policy, $compiled);
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
        return self::gate_scan_read_only($repo, Policy::load($repo));
    }

    /**
     * The collect-only half of gate_scan(), with an already loaded policy.
     *
     * This is intentionally narrower than Capture::run(): it has no output
     * directory, publication state, ledger, or write path.  Pending's bounded
     * adapter observation passes the policy it already loaded so observation
     * cannot accidentally acquire a second policy/repair path just to inspect
     * the same read-only gate facts.
     *
     * @return array{
     *   scope: array<string, array{entities:int}>,
     *   options: array<string, array{entities:int, owner_candidates:string[], value_shapes:string[], reason:string}>,
     *   post_meta: array<string, array{entities:int, post_types: string[]}>,
     *   term_meta: array<string, array{entities:int, taxonomies:string[], value_shapes:string[], reason:string}>,
     *   user_meta: array<string, array{entities:int, users:string[], value_shapes:string[], reason:string}>
     * }
     */
    public static function gate_scan_read_only(
        string $repo,
        Policy $policy,
        ?callable $observationReadCheckpoint = null
    ): array {
        return (new CaptureGateScanner($policy, $observationReadCheckpoint))->scan();
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
        CaptureTransaction::assert_engine_support($policy);
    }

    /**
     * Historical private facade retained for in-class callers and probes.
     * Production callers supply their path policy so the collaborator can
     * enforce its complete InnoDB read set immediately before every START.
     * The core fallback preserves callback-only reflection probes that
     * predate the extraction; it is not used by an engine command path.
     */
    private static function run_in_consistent_snapshot(
        callable $fn,
        ?array &$phase = null,
        ?Policy $policy = null,
        bool $optionsOnly = false
    ) {
        $policy ??= Policy::load(null, ['core']);
        return CaptureTransaction::run($policy, $fn, $phase, $optionsOnly);
    }

    private static function commit_outcome_uncertain(string $operatorMessage, \Throwable $previous): CommandRefusalException {
        return CaptureTransaction::commit_outcome_uncertain($operatorMessage, $previous);
    }

    /** Historical private facade retained for in-class callers and probes. */
    private static function check_transient_db_error(string $where): void {
        CaptureTransaction::check_transient_db_error($where);
    }

    /** Resolve lexical/symlink variants to one stable destination identity. */
    private static function publication_destination_sha256(string $stateDir): string {
        return CapturePublicationRecovery::destinationSha256($stateDir);
    }

    /** Destination-scoped marker key stays well below duo_kv.k's 191-byte limit. */
    private static function publication_marker_key(string $stateDir): string {
        return CapturePublicationRecovery::markerKey($stateDir);
    }

    /**
     * Exact/self-hashed DB commit proof. It is written as the final DML in
     * the capture transaction; rollback therefore removes it automatically.
     */
    private static function publication_marker(string $stateDir, array $intent): string {
        return CapturePublicationRecovery::marker($stateDir, $intent);
    }

    /**
     * Return true only for a valid marker belonging to THIS intent. A missing
     * or prior-run marker is a definitive rollback signal; malformed or
     * current-but-mismatched data is fail-closed corruption.
     */
    public static function publication_commit_status(string $stateDir, array $intent): bool {
        return CapturePublicationRecovery::commitStatus($stateDir, $intent);
    }

    /** First init never recovers or adopts pre-existing protocol siblings. */
    private static function assert_initial_protocol_boundaries(string $stateDir): void {
        InitialCaptureBoundary::assertProtocolBoundaries($stateDir);
    }


    private static function assert_initial_config_identity(string $path, string $expected): void {
        InitialCaptureBoundary::assertConfigIdentity($path, $expected);
    }

    /** First init reserves one exact empty state directory before Capture. */
    private static function assert_initial_state_reservation(string $path, string $expected): void {
        InitialCaptureBoundary::assertStateReservation($path, $expected);
    }

    /** Commit proof is needed only while filesystem recovery artifacts exist. */
    private static function clear_publication_marker_if_clean(string $stateDir): void {
        CapturePublicationRecovery::clearIfClean($stateDir);
    }

    // ------------------------------------------------------------------

    /**
     * Lifecycle handoff variant of verify_engine_support(). It checks only
     * tables that this narrow path can actually read (options, identity
     * metadata, core rows used by ref triage, and the Duo ledger). In
     * particular, a declared Woo/custom table is not inspected unless an
     * option_name_refs rule makes that table part of this path's own safety
     * check; plugin-owned typed tables remain outside the lifecycle boundary.
     */
    private static function verify_options_engine_support(Policy $policy): void {
        CaptureTransaction::assert_engine_support($policy, true);
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
        return ReferenceScopeClassifier::targetType($id, $kind);
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
        return ReferenceScopeClassifier::classify($id, $kind, $force, $policy);
    }

}
