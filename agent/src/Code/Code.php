<?php
namespace Duo;

require_once __DIR__ . '/CodeDescriptorCompiler.php';
require_once __DIR__ . '/CodeSourceLock.php';
require_once __DIR__ . '/../Kernel/PathSafety.php';
require_once __DIR__ . '/CodeStageTransaction.php';
require_once __DIR__ . '/CodeMaterializer.php';
require_once __DIR__ . '/CodeOwnershipPruner.php';

/**
 * v0 code-half payload support.
 *
 * The code half deliberately starts with a small, explicit contract rather
 * than trying to guess how an arbitrary WordPress checkout is laid out.  A
 * site opts in from site.duo.json with:
 *
 *   "code": {"format": 1, "layout": "wp-content", "source": "code/wp-content"}
 *
 * Only the three WordPress content component roots are payload-owned.  The
 * compiler inventories their regular files and the stage/finalize commands
 * use that immutable inventory as their source of truth. Stage never removes
 * completed code: lifecycle hooks run between stage and finalize, and
 * finalize removes prior owned paths. The sole earlier recovery exception is
 * an unchanged abandoned staged-only MU file with an atomic created-path
 * receipt; user MU code has no WordPress activation/deactivation lifecycle and
 * can otherwise make the normal lifecycle process impossible to bootstrap.
 */
final class Code {
    public const DESCRIPTOR_FORMAT = 'duo-code/v1';
    public const LAYOUT = 'wp-content';
    public const SOURCE = 'code/wp-content';

    /** Keys in duo_kv. These are intentionally stable integration points. */
    public const CODE_REVISION_KEY = 'code_revision';
    public const CODE_DESCRIPTOR_KEY = 'code_descriptor';
    public const CODE_STAGE_REVISION_KEY = CodeStageTransaction::REVISION_KEY;
    /** Temporary descriptor retained until finalize succeeds. */
    public const CODE_STAGE_DESCRIPTOR_KEY = CodeStageTransaction::DESCRIPTOR_KEY;
    /** Artifact identity bound to the temporary staged descriptor. */
    public const CODE_STAGE_ARTIFACT_KEY = CodeStageTransaction::ARTIFACT_KEY;
    /** Canonical list of prior staged descriptors retained for recovery. */
    public const CODE_STAGE_HISTORY_KEY = CodeStageTransaction::HISTORY_KEY;
    /** Canonical paths proven absent before Duo first staged them. */
    public const CODE_STAGE_CREATED_PATHS_KEY = CodeStageTransaction::CREATED_PATHS_KEY;

    /** @var list<string> */
    private const ROOTS = ['mu-plugins', 'plugins', 'themes'];

    /**
     * Compile the opted-in code payload. A missing config is the legacy
     * repository shape and remains a no-op for every code-half consumer.
     *
     * @return ?array<string,mixed>
     */
    public static function compile(string $repo, ?array $config): ?array {
        return CodeDescriptorCompiler::compile($repo, $config);
    }

    /**
     * Build a deterministic descriptor from one source directory. This is
     * public for offline tests and for stage's re-check that the source did
     * not change after the immutable artifact was compiled.
     *
     * @return array<string,mixed>
     */
    public static function descriptor_from_source(string $source): array {
        return CodeDescriptorCompiler::descriptor_from_source($source);
    }

    /** Validate a site.duo.json code declaration independently of Policy. */
    public static function assert_config(array $config): void {
        CodeDescriptorCompiler::assert_config($config);
    }

    /**
     * The declared code lock path, or null for a fully vendored (format 1)
     * repository. DUO-3499.
     *
     * @param ?array<string,mixed> $config
     */
    public static function lock_path(?array $config): ?string {
        return $config === null ? null : CodeDescriptorCompiler::lock_path($config);
    }

    /**
     * `{root}/{component}` => lock entry for every component this repository
     * declares but deliberately does not carry in Git; `[]` for a format-1
     * repository. Callers use it only to explain a refusal — the authoritative
     * gate is CodeDescriptorCompiler::lock_diagnostics(), which runs inside
     * compile() before any descriptor leaves the compiler.
     *
     * @param ?array<string,mixed> $config
     * @return array<string,array<string,mixed>>
     */
    public static function locked_components(string $repo, ?array $config): array {
        if ($config === null || CodeDescriptorCompiler::lock_path($config) === null) {
            return [];
        }
        $lock = CodeDescriptorCompiler::load_lock($repo, $config);
        return $lock === null ? [] : CodeSourceLock::index($lock);
    }

    /**
     * Read-only preflight for first-run code capture. Initialization may
     * observe only the same standard roots the materializer can later own;
     * exposing this narrow check keeps that layout contract single-sourced.
     */
    public static function assert_initial_capture_layout(): void {
        self::assert_target_layout();
    }

    /** Validate a descriptor loaded from a compiled artifact or ledger. */
    public static function assert_descriptor(array $descriptor): void {
        CodeDescriptorCompiler::assert_descriptor($descriptor);
    }

    public static function revision_for(array $descriptorWithoutRevision): string {
        return CodeDescriptorCompiler::revision_for($descriptorWithoutRevision);
    }

    public static function code_revision(?array $descriptor): ?string {
        return $descriptor === null ? null : (string) ($descriptor['code_revision'] ?? '');
    }

    /**
     * Authorization gate for a lifecycle phase. Call only after Deploy has
     * acquired the promotion lock for the compiled artifact: this proves the
     * stage marker, staged descriptor, staged artifact identity, and every
     * staged target hash still describe exactly that locked artifact.
     */
    public static function assert_verified_staged(CompiledRepository $compiled): void {
        $descriptor = $compiled->code_descriptor();
        if ($descriptor === null) {
            throw new \RuntimeException('duo: materializing-code requires an opted-in compiled code descriptor');
        }
        self::assert_descriptor($descriptor);
        $stageRevision = Ledger::kv_get(self::CODE_STAGE_REVISION_KEY);
        if ($stageRevision === null || !preg_match('/^[0-9a-f]{64}$/', $stageRevision)
            || !hash_equals((string) $descriptor['code_revision'], $stageRevision)) {
            throw new \RuntimeException('duo: materializing-code refused — code_stage_revision does not match the compiled code revision');
        }
        $stageArtifact = Ledger::kv_get(self::CODE_STAGE_ARTIFACT_KEY);
        if ($stageArtifact === null || !preg_match('/^[0-9a-f]{64}$/', $stageArtifact)
            || !hash_equals($compiled->artifact_hash(), $stageArtifact)) {
            throw new \RuntimeException('duo: materializing-code refused — staged code artifact does not match the compiled artifact');
        }
        $staged = self::stored_stage_descriptor($stageRevision, $descriptor);
        if ($staged === null) {
            throw new \RuntimeException('duo: materializing-code refused — no staged code descriptor exists');
        }
        self::stored_stage_created_paths($staged);
        self::stored_stage_history();
        self::verify_payload($staged);
    }

    /**
     * Return a deterministic explanation when a completed descriptor marker
     * no longer proves the target payload.  This is read-only and intended
     * for Deploy/plan's non-forceable code_revision_stale finding.
     */
    public static function completed_code_mismatch(CompiledRepository $compiled): ?string {
        $expected = $compiled->code_descriptor();
        if ($expected === null) {
            return null;
        }
        self::assert_descriptor($expected);
        $revision = Ledger::kv_get(self::CODE_REVISION_KEY);
        if ($revision === null || $revision === '') {
            return 'no completed code_revision marker exists on this environment';
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $revision) || !hash_equals($revision, (string) $expected['code_revision'])) {
            return $revision === ''
                ? 'the completed code_revision marker is empty'
                : "this environment completed code revision '$revision'";
        }
        $raw = Ledger::kv_get(self::CODE_DESCRIPTOR_KEY);
        if ($raw === null || $raw === '') {
            return 'the completed code_revision marker has no stored code descriptor';
        }
        try {
            $stored = Canon::decode($raw);
            if (!is_array($stored) || Canon::encode($stored) !== $raw) {
                return 'the stored completed code descriptor is not canonical JSON';
            }
            self::assert_descriptor($stored);
        } catch (\Throwable $t) {
            return 'the stored completed code descriptor is invalid: ' . $t->getMessage();
        }
        if (Canon::encode($stored) !== Canon::encode($expected)) {
            return 'the stored completed code descriptor does not match this compiled artifact';
        }
        foreach ([
            self::CODE_STAGE_REVISION_KEY,
            self::CODE_STAGE_DESCRIPTOR_KEY,
            self::CODE_STAGE_ARTIFACT_KEY,
            self::CODE_STAGE_HISTORY_KEY,
            self::CODE_STAGE_CREATED_PATHS_KEY,
        ] as $key) {
            if (($pending = Ledger::kv_get($key)) !== null && $pending !== '') {
                return "temporary code-stage metadata '$key' remains after finalize";
            }
        }
        try {
            self::verify_payload($stored);
            $extras = self::owned_extra_files($stored);
        } catch (\Throwable $t) {
            return $t->getMessage();
        }
        if ($extras) {
            return 'managed code root contains unrecorded file(s): ' . implode(', ', array_slice($extras, 0, 8));
        }
        return null;
    }

    /**
     * Complete the first code baseline from bytes already executing on the
     * target. This is deliberately narrower than stage/finalize: init has
     * just copied and compiled those exact live bytes into the repository,
     * so materializing them back onto the same source environment would add
     * no safety. Refuse any pre-existing lifecycle state rather than turning
     * bootstrap into an overwrite/recovery path.
     *
     * @return array{enabled:bool,completed:bool,code_revision:?string,files:int}
     */
    public static function complete_initial_baseline(string $repo, CompiledRepository $compiled): array {
        $descriptor = self::validated_initial_baseline($repo, $compiled, true);
        if ($descriptor === null) {
            return ['enabled' => false, 'completed' => false, 'code_revision' => null, 'files' => 0];
        }
        self::publish_completed_descriptor($descriptor);
        return self::initial_baseline_summary($descriptor);
    }

    /**
     * Complete the same narrow first baseline inside Capture's already-open
     * consistent-snapshot transaction. The caller owns commit/rollback, so
     * this method must never start a nested transaction: descriptor markers,
     * state hashes, identity minting, and the publication receipt either all
     * commit together or all roll back together.
     *
     * @return array{enabled:bool,completed:bool,code_revision:?string,files:int}
     */
    public static function complete_initial_baseline_in_active_transaction(
        string $repo,
        CompiledRepository $compiled
    ): array {
        $descriptor = self::validated_initial_baseline($repo, $compiled, false);
        if ($descriptor === null) {
            return ['enabled' => false, 'completed' => false, 'code_revision' => null, 'files' => 0];
        }
        self::write_completed_descriptor($descriptor);
        return self::initial_baseline_summary($descriptor);
    }

    /** @return ?array<string,mixed> */
    private static function validated_initial_baseline(
        string $repo,
        CompiledRepository $compiled,
        bool $ensureLedger
    ): ?array {
        $descriptor = $compiled->code_descriptor();
        if ($descriptor === null) {
            return null;
        }
        self::assert_descriptor($descriptor);
        self::assert_target_layout($descriptor);
        CodeStateContract::validate($compiled, $descriptor);
        self::assert_source_matches($repo, $descriptor);
        self::assert_source_compatibility($repo, $compiled, $descriptor);
        if ($ensureLedger) {
            Ledger::ensure();
        }

        foreach ([
            self::CODE_REVISION_KEY,
            self::CODE_DESCRIPTOR_KEY,
            self::CODE_STAGE_REVISION_KEY,
            self::CODE_STAGE_DESCRIPTOR_KEY,
            self::CODE_STAGE_ARTIFACT_KEY,
            self::CODE_STAGE_HISTORY_KEY,
            self::CODE_STAGE_CREATED_PATHS_KEY,
        ] as $key) {
            if (($existing = Ledger::kv_get($key)) !== null && $existing !== '') {
                throw new \RuntimeException(
                    "duo: initial code baseline refused — lifecycle metadata '$key' already exists; use the ordinary deploy recovery workflow"
                );
            }
        }

        self::verify_payload($descriptor);
        $extras = self::owned_extra_files($descriptor);
        if ($extras) {
            throw new \RuntimeException(
                'duo: initial code baseline found unrecorded file(s) in a managed component: '
                . implode(', ', array_slice($extras, 0, 8))
            );
        }
        return $descriptor;
    }

    /** @param array<string,mixed> $descriptor
     *  @return array{enabled:bool,completed:bool,code_revision:?string,files:int} */
    private static function initial_baseline_summary(array $descriptor): array {
        return [
            'enabled' => true,
            'completed' => true,
            'code_revision' => $descriptor['code_revision'],
            'files' => count($descriptor['files']),
        ];
    }

    /**
     * Read-only target-aware gate for one immutable artifact. It runs before
     * promotion-begin and is repeated by stage under the lease; neither the
     * observed runtime nor parsed header requirements enter the artifact.
     */
    public static function preflight(string $repo, CompiledRepository $compiled, array $opts = []): array {
        $descriptor = $compiled->code_descriptor();
        if ($descriptor === null) {
            return [
                'format' => 'duo-code-runtime/v1',
                'enabled' => false,
                'compatible' => true,
                'code_revision' => null,
                'target' => self::target_runtime_versions(),
                'requirements' => [],
                'diagnostics' => [],
            ];
        }
        self::assert_descriptor($descriptor);
        CodeStateContract::validate($compiled, $descriptor);
        self::assert_expected_artifact($compiled, $opts);
        self::assert_source_matches($repo, $descriptor);
        self::assert_source_compatibility($repo, $compiled, $descriptor);
        $report = CodeCompatibility::target_report(
            rtrim($repo, '/') . '/' . self::SOURCE,
            $descriptor,
            self::target_runtime_versions()
        );
        if ($report['diagnostics']) {
            throw new CodeCompilationException($report['diagnostics']);
        }
        return ['enabled' => true, 'code_revision' => $descriptor['code_revision']] + $report;
    }

    /**
     * Exact current runtime evidence, distinct from Duo's generated
     * certification baseline. The protected agent/control-plane process is
     * the source; this record makes no support verdict of its own.
     *
     * @return array{php:string,wordpress:string,source:string}
     */
    public static function target_runtime_versions(): array {
        $wordpress = is_string($GLOBALS['wp_version'] ?? null)
            ? trim((string) $GLOBALS['wp_version'])
            : '';
        if ($wordpress === '' && function_exists('get_bloginfo')) {
            $observed = get_bloginfo('version');
            $wordpress = is_string($observed) ? trim($observed) : '';
        }
        return [
            'php' => defined('PHP_VERSION') ? (string) PHP_VERSION : '',
            'wordpress' => $wordpress,
            'source' => 'target-control-plane',
        ];
    }

    /**
     * Plan-facing form of preflight diagnostics. Every row binds the frozen
     * code revision and descriptor component hash and is non-forceable.
     *
     * @param ?array<string,mixed> $target Test seam; production reads the current runtime.
     * @return list<array<string,mixed>>
     */
    public static function target_compatibility_rows(
        string $repo,
        CompiledRepository $compiled,
        ?array $target = null
    ): array {
        $descriptor = $compiled->code_descriptor();
        if ($descriptor === null) {
            return [];
        }
        self::assert_descriptor($descriptor);
        self::assert_source_matches($repo, $descriptor);
        $report = CodeCompatibility::target_report(
            rtrim($repo, '/') . '/' . self::SOURCE,
            $descriptor,
            $target ?? self::target_runtime_versions()
        );
        return array_map(static function (array $diagnostic) use ($descriptor): array {
            return $diagnostic + [
                'issue' => (string) $diagnostic['code'],
                'kind' => (string) ($diagnostic['component'] ?? 'code'),
                'code_revision' => (string) $descriptor['code_revision'],
                'non_forceable' => true,
            ];
        }, $report['diagnostics']);
    }

    /**
     * Stage all desired files with per-file temp+rename atomicity. Completed
     * code is never removed; the bounded staged-only MU recovery documented
     * above may remove one prior abandoned path. The promotion lease remains
     * held for code-finalize (and, optionally, subsequent state apply).
     */
    public static function stage(string $repo, CompiledRepository $compiled, array $opts = []): array {
        $descriptor = $compiled->code_descriptor();
        if ($descriptor === null) {
            return ['enabled' => false, 'staged' => false, 'code_revision' => null];
        }
        self::assert_descriptor($descriptor);
        self::assert_target_layout($descriptor);
        CodeStateContract::validate($compiled, $descriptor);
        self::assert_expected_artifact($compiled, $opts);

        $owner = (string) ($opts['promotion_owner'] ?? '');
        if ($owner === '') {
            throw new \RuntimeException('duo: code-stage requires an explicit --promotion-owner; use the host duo deploy workflow');
        }
        Ledger::ensure();
        $artifact = $compiled->artifact_hash();
        // This is a strict continuation of promotion-begin. In particular,
        // a same-owner attempt with another artifact must be rejected by the
        // lock without releasing the live session it failed to continue.
        PromotionLock::acquire($owner, $artifact, 'code-stage', null, true);
        try {
            PromotionLock::assert_no_lifecycle_attempt($owner, $artifact, 'code-stage');
            $stageRevision = Ledger::kv_get(self::CODE_STAGE_REVISION_KEY);
            if ($stageRevision !== null && !preg_match('/^[0-9a-f]{64}$/', $stageRevision)) {
                throw new \RuntimeException('duo: malformed code_stage_revision; refusing recovery guesswork');
            }
            $staged = self::stored_stage_descriptor($stageRevision, null);
            $stagedCreatedPaths = self::stored_stage_created_paths($staged);
            $history = self::stored_stage_history();
            if ($staged !== null && $staged['code_revision'] !== $descriptor['code_revision']) {
                $history[$staged['code_revision']] = $staged;
            }
            // Re-check after acquiring the target lease. The host preflight
            // was an earlier no-write target check; this one detects a
            // checkout change closer to the first target write, while the
            // post-write check below catches a change during materialization.
            self::assert_source_matches($repo, $descriptor);
            CodeStateContract::validate($compiled, $descriptor);
            // Version ranges, Requires Plugins, Requires PHP, and Requires at
            // least are source/header facts, not descriptor fields. Re-read
            // them after acquiring the target lease and immediately before
            // the first payload rename so a precompiled artifact cannot stage
            // an out-of-range, dependency-incoherent, or runtime-incompatible
            // checkout.
            self::assert_source_compatibility($repo, $compiled, $descriptor);
            $runtimeReport = CodeCompatibility::target_report(
                rtrim($repo, '/') . '/' . self::SOURCE,
                $descriptor,
                self::target_runtime_versions()
            );
            if ($runtimeReport['diagnostics']) {
                throw new CodeCompilationException($runtimeReport['diagnostics']);
            }
            $previous = self::stored_descriptor();
            $materialized = self::materialize_payload(
                $repo,
                $descriptor,
                $previous,
                $staged,
                $history,
                $stagedCreatedPaths
            );
            self::assert_source_matches($repo, $descriptor);
            // A descriptor becomes deletion authority only after its entire
            // payload has materialized successfully. Persisting an attempted
            // descriptor before preflight/write would let a no-write failure
            // claim an operator's pre-existing component root on a later
            // promotion. A crash during per-file writes is therefore handled
            // conservatively: unrecorded partial bytes may require manual
            // cleanup, but they can never grant Duo root-wide ownership.
            $history[$descriptor['code_revision']] = $descriptor;
            ksort($history, SORT_STRING);
            self::publish_stage_descriptor(
                $history,
                $descriptor,
                $artifact,
                $materialized['created_paths']
            );
            PromotionLock::heartbeat($owner, $artifact, 'code-staged');
            return [
                'enabled' => true,
                'staged' => true,
                'code_revision' => $descriptor['code_revision'],
                'files' => count($descriptor['files']),
                // DUO-3501: 'files' stays the whole descriptor inventory it
                // has always been; these two partition it by what this stage
                // actually had to move, so a re-stage of an unchanged payload
                // is visible as such instead of looking like a full rewrite.
                'written' => $materialized['written'],
                'unchanged' => $materialized['unchanged'],
                'abandoned_stage_removed' => $materialized['abandoned_stage_removed'],
                'promotion_lock' => ['owner' => $owner, 'held_for_finalize' => true],
            ];
        } catch (\Throwable $t) {
            try {
                PromotionLock::release($owner, $artifact);
            } catch (\Throwable $_releaseFailure) {
                // The bounded lease remains the recovery mechanism if the
                // database connection failed while handling the original error.
            }
            throw $t;
        }
    }

    /**
     * Verify staged files, remove only prior Duo-owned paths now absent, then
     * publish the completed descriptor/revision. The lease is released unless
     * promotion-hold asks us to hand it to state apply.
     */
    public static function finalize(string $repo, CompiledRepository $compiled, array $opts = []): array {
        $descriptor = $compiled->code_descriptor();
        if ($descriptor === null) {
            return ['enabled' => false, 'finalized' => false, 'code_revision' => null];
        }
        self::assert_descriptor($descriptor);
        self::assert_target_layout($descriptor);
        self::assert_expected_artifact($compiled, $opts);
        $owner = (string) ($opts['promotion_owner'] ?? '');
        if ($owner === '') {
            throw new \RuntimeException('duo: code-finalize requires an explicit --promotion-owner; use the host duo deploy workflow');
        }
        Ledger::ensure();
        $stageRevision = Ledger::kv_get(self::CODE_STAGE_REVISION_KEY);
        if ($stageRevision === null || !preg_match('/^[0-9a-f]{64}$/', $stageRevision)
            || !hash_equals($descriptor['code_revision'], $stageRevision)) {
            throw new \RuntimeException(
                'duo: code-finalize refused — code-stage has not recorded this compiled code revision '
                . '(run code-stage first and keep the same compiled artifact)'
            );
        }
        CodeStateContract::validate($compiled, $descriptor);

        $artifact = $compiled->artifact_hash();
        PromotionLock::acquire($owner, $artifact, 'code-finalize', null, true);
        try {
            PromotionLock::assert_no_lifecycle_attempt($owner, $artifact, 'code-finalize');
            PromotionLock::assert_lifecycle_complete($owner, $artifact);
            // Finalize consumes the frozen compiled descriptor and the
            // already-staged target. The checkout may legitimately move
            // between stage and finalize; reopening mutable source here
            // would make a valid frozen artifact fail spuriously.
            CodeStateContract::validate($compiled, $descriptor);
            self::assert_verified_staged($compiled);
            self::verify_payload($descriptor);
            $previous = self::stored_descriptor();
            $staged = self::stored_stage_descriptor($stageRevision, $descriptor);
            $history = self::stored_stage_history();
            $removed = self::remove_old_owned_files($previous, $staged, $history, $descriptor);
            self::verify_payload($descriptor);
            $extras = self::owned_extra_files($descriptor);
            if ($extras) {
                throw new \RuntimeException(
                    'duo: code-finalize verification found unrecorded file(s): '
                    . implode(', ', array_slice($extras, 0, 8))
                );
            }

            self::publish_completed_descriptor($descriptor);

            if (!empty($opts['promotion_hold'])) {
                PromotionLock::heartbeat($owner, $artifact, 'code-finalized');
            } else {
                PromotionLock::release($owner, $artifact);
            }
            return [
                'enabled' => true,
                'finalized' => true,
                'code_revision' => $descriptor['code_revision'],
                'files' => count($descriptor['files']),
                'removed' => $removed,
                'promotion_lock' => ['owner' => $owner, 'held_for_apply' => !empty($opts['promotion_hold'])],
            ];
        } catch (\Throwable $t) {
            try {
                PromotionLock::release($owner, $artifact);
            } catch (\Throwable $_releaseFailure) {
                // Preserve the actionable verification/mutation error; the
                // lease is bounded and recoverable by the next promotion.
            }
            throw $t;
        }
    }

    /**
     * Publish the complete staged receipt only after materialization succeeds.
     *
     * The history entry is deletion authority, so it must become durable with
     * the staged descriptor, compiled artifact, and revision as one receipt.
     * After a confirmed rollback, no attempted descriptor can survive as
     * ownership evidence for a later prune; any already-written payload bytes
     * remain conservative/manual-cleanup territory until a successful retry.
     *
     * @param array<string,array<string,mixed>> $history
     * @param array<string,mixed> $descriptor
     * @param list<string> $createdPaths
     */
    private static function publish_stage_descriptor(
        array $history,
        array $descriptor,
        string $artifact,
        array $createdPaths
    ): void {
        CodeStageTransaction::publish($history, $descriptor, $artifact, $createdPaths);
    }

    /**
     * Move the code ledger from staged to completed as one retryable
     * convergence boundary. A process/database failure may leave either side
     * of this transition, but never code_stage_revision without its staged
     * descriptor/history or code_revision without its completed descriptor.
     */
    private static function publish_completed_descriptor(array $descriptor): void {
        $transactionStarted = false;
        try {
            Db::start('code ledger transaction start');
            $transactionStarted = true;
            self::write_completed_descriptor($descriptor);
            Db::commit('code ledger transaction commit');
            $transactionStarted = false;
        } catch (\Throwable $t) {
            if ($transactionStarted) {
                try {
                    Db::rollback('code ledger transaction rollback');
                } catch (\Throwable $rollback) {
                    throw new \RuntimeException(
                        'duo: code ledger transaction failed and rollback could not be confirmed: '
                        . $rollback->getMessage(),
                        0,
                        $t
                    );
                }
            }
            throw $t;
        }
    }

    /** Write completed lifecycle rows inside the caller's transaction. */
    private static function write_completed_descriptor(array $descriptor): void {
        Ledger::kv_set(self::CODE_DESCRIPTOR_KEY, Canon::encode($descriptor));
        Ledger::kv_delete(self::CODE_STAGE_DESCRIPTOR_KEY);
        Ledger::kv_delete(self::CODE_STAGE_ARTIFACT_KEY);
        Ledger::kv_delete(self::CODE_STAGE_HISTORY_KEY);
        Ledger::kv_delete(self::CODE_STAGE_CREATED_PATHS_KEY);
        Ledger::kv_delete(self::CODE_STAGE_REVISION_KEY);
        Ledger::kv_set(self::CODE_REVISION_KEY, $descriptor['code_revision']);
    }

    /** @return ?array<string,mixed> */
    private static function stored_descriptor(): ?array {
        $raw = Ledger::kv_get(self::CODE_DESCRIPTOR_KEY);
        $revision = Ledger::kv_get(self::CODE_REVISION_KEY);
        if ($raw === null) {
            if ($revision !== null && $revision !== '') {
                throw new \RuntimeException('duo: code ledger has code_revision but no code_descriptor; refusing deletion guesswork');
            }
            return null;
        }
        try {
            $descriptor = Canon::decode($raw);
        } catch (\Throwable $t) {
            throw new \RuntimeException('duo: stored code descriptor is not valid canonical JSON', 0, $t);
        }
        if (!is_array($descriptor)) {
            throw new \RuntimeException('duo: stored code descriptor is not an object');
        }
        if (Canon::encode($descriptor) !== $raw) {
            throw new \RuntimeException('duo: stored code descriptor is not canonical JSON');
        }
        self::assert_descriptor($descriptor);
        if ($revision !== null && $revision !== '' && !hash_equals($revision, $descriptor['code_revision'])) {
            // The descriptor is still self-verifying and is useful as a
            // deletion-ownership record, but the completion marker remains
            // stale until finalize can publish it last.  This state is
            // recoverable after an interrupted descriptor/ledger update;
            // never use the marker mismatch as a reason to guess ownership.
        }
        return $descriptor;
    }

    /** @return ?array<string,mixed> */
    private static function stored_stage_descriptor(?string $stageRevision, ?array $expected): ?array {
        $raw = Ledger::kv_get(self::CODE_STAGE_DESCRIPTOR_KEY);
        if ($stageRevision === null && ($raw === null || $raw === '')) {
            return null;
        }
        if ($stageRevision === null || $raw === null || $raw === '') {
            throw new \RuntimeException(
                'duo: code ledger has code_stage_revision but no code_stage_descriptor; refusing finalize guesswork'
            );
        }
        try {
            $descriptor = Canon::decode($raw);
        } catch (\Throwable $t) {
            throw new \RuntimeException('duo: staged code descriptor is not valid canonical JSON', 0, $t);
        }
        if (!is_array($descriptor)) {
            throw new \RuntimeException('duo: staged code descriptor is not an object');
        }
        if (Canon::encode($descriptor) !== $raw) {
            throw new \RuntimeException('duo: staged code descriptor is not canonical JSON');
        }
        self::assert_descriptor($descriptor);
        if (!hash_equals((string) $stageRevision, (string) ($descriptor['code_revision'] ?? ''))
            || ($expected !== null && !hash_equals((string) $expected['code_revision'], (string) $descriptor['code_revision']))) {
            throw new \RuntimeException(
                'duo: staged code descriptor does not match the compiled code revision; re-run code-stage'
            );
        }
        return $descriptor;
    }

    /** @return array<string,array<string,mixed>> keyed by code revision */
    private static function stored_stage_history(): array {
        $raw = Ledger::kv_get(self::CODE_STAGE_HISTORY_KEY);
        if ($raw === null || $raw === '') {
            return [];
        }
        try {
            $rows = Canon::decode($raw);
        } catch (\Throwable $t) {
            throw new \RuntimeException('duo: staged code history is not valid canonical JSON', 0, $t);
        }
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \RuntimeException('duo: staged code history is not a descriptor list');
        }
        if (Canon::encode($rows) !== $raw) {
            throw new \RuntimeException('duo: staged code history is not canonical JSON');
        }
        $history = [];
        $revisions = [];
        foreach ($rows as $i => $descriptor) {
            if (!is_array($descriptor)) {
                throw new \RuntimeException("duo: staged code history[$i] is not a descriptor");
            }
            self::assert_descriptor($descriptor);
            if (isset($history[$descriptor['code_revision']])) {
                throw new \RuntimeException("duo: staged code history contains duplicate revision '{$descriptor['code_revision']}'");
            }
            $revisions[] = $descriptor['code_revision'];
            $history[$descriptor['code_revision']] = $descriptor;
        }
        $expectedRevisions = $revisions;
        sort($expectedRevisions, SORT_STRING);
        if ($revisions !== $expectedRevisions) {
            throw new \RuntimeException('duo: staged code history is not sorted by code revision');
        }
        return $history;
    }

    /**
     * Read the staged provenance receipt used only by abandoned-MU recovery.
     * A legacy receipt without this key remains valid for finalize, but grants
     * no authority for early recovery deletion.
     *
     * @return list<string>
     */
    private static function stored_stage_created_paths(?array $staged): array {
        $raw = Ledger::kv_get(self::CODE_STAGE_CREATED_PATHS_KEY);
        if ($raw === null || $raw === '') {
            return [];
        }
        try {
            $paths = Canon::decode($raw);
        } catch (\Throwable $t) {
            throw new \RuntimeException('duo: staged created-path receipt is not valid canonical JSON', 0, $t);
        }
        if ($staged === null || !is_array($paths) || !array_is_list($paths)
            || Canon::encode($paths) !== $raw) {
            throw new \RuntimeException('duo: staged created-path receipt is malformed or has no staged descriptor');
        }
        $stagedPaths = [];
        foreach ($staged['files'] as $row) {
            $stagedPaths[(string) $row['path']] = true;
        }
        $seen = [];
        foreach ($paths as $i => $path) {
            if (!is_string($path) || !self::safe_relative($path)
                || !isset($stagedPaths[$path]) || isset($seen[$path])) {
                throw new \RuntimeException("duo: staged created-path receipt[$i] is not a unique staged file");
            }
            $seen[$path] = true;
        }
        $expected = $paths;
        sort($expected, SORT_STRING);
        if ($paths !== $expected) {
            throw new \RuntimeException('duo: staged created-path receipt is not deterministically sorted');
        }
        return $paths;
    }

    private static function assert_source_matches(string $repo, array $expected): void {
        $actual = self::descriptor_from_source(rtrim($repo, '/') . '/' . self::SOURCE);
        if (!hash_equals((string) $expected['code_revision'], (string) $actual['code_revision'])
            || Canon::encode($actual) !== Canon::encode($expected)) {
            throw new \RuntimeException(
                'duo: code payload changed after compilation; re-run `wp duo compile` and stage the new artifact'
            );
        }
    }

    private static function assert_source_compatibility(
        string $repo,
        CompiledRepository $compiled,
        array $descriptor
    ): void {
        $requirements = [];
        if (method_exists(CodeStateContract::class, 'requirements')) {
            $requirements = CodeStateContract::requirements($compiled);
        }
        $resolvedAdapters = method_exists($compiled, 'resolved_adapters')
            ? $compiled->resolved_adapters()
            : [];
        CodeCompatibility::assert_source(
            rtrim($repo, '/') . '/' . self::SOURCE,
            $descriptor,
            $resolvedAdapters,
            (array) ($requirements['active_plugins'] ?? [])
        );
    }

    private static function assert_expected_artifact(CompiledRepository $compiled, array $opts): void {
        $expected = (string) ($opts['artifact_hash'] ?? '');
        if (!preg_match('/^[0-9a-f]{64}$/', $expected)
            || !hash_equals($expected, $compiled->artifact_hash())) {
            throw new \RuntimeException(
                'duo: code materialization artifact does not match the host-compiled artifact hash'
            );
        }
    }

    /** @return array{written:int,unchanged:int} */
    private static function write_payload(string $repo, array $descriptor): array {
        return CodeMaterializer::write_payload($repo, $descriptor);
    }

    /**
     * Refuse every deterministic target/removal conflict before the first
     * payload rename. Per-file temp+rename still protects each individual
     * write; this preflight additionally prevents an already-known late path
     * conflict from leaving earlier files on the new revision.
     *
     * @param array<string,array<string,mixed>> $history
     * @param list<string> $stagedCreatedPaths
     * @return array{abandoned_stage_removed:list<string>,created_paths:list<string>,written:int,unchanged:int}
     */
    private static function materialize_payload(
        string $repo,
        array $descriptor,
        ?array $previous,
        ?array $staged,
        array $history,
        array $stagedCreatedPaths
    ): array {
        return CodeMaterializer::materialize_payload(
            $repo,
            $descriptor,
            $previous,
            $staged,
            $history,
            $stagedCreatedPaths,
            static function (?array $previous, ?array $staged, array $history, array $current): void {
                self::assert_removal_safe($previous, $staged, $history, $current);
            }
        );
    }

    /**
     * Preserve proof that a staged-only path was originally absent. This is
     * intentionally separate from descriptor ownership: adopting/overwriting
     * an existing target is allowed, but a failed promotion may not later use
     * its staged hash to erase that pre-existing path during early recovery.
     *
     * @param list<string> $stagedCreatedPaths
     * @return list<string>
     */
    private static function created_paths_for_stage(
        array $current,
        ?array $previous,
        array $stagedCreatedPaths
    ): array {
        return CodeMaterializer::created_paths_for_stage($current, $previous, $stagedCreatedPaths);
    }

    /**
     * Recover from a fully staged user MU payload which made ordinary
     * WordPress bootstrap fatal before lifecycle/finalize could run.
     *
     * Only an exact file introduced by the immediately preceding staged
     * descriptor, never present in completed code, and now absent from the
     * reviewed descriptor is eligible. Restrict this early cleanup to
     * mu-plugins: regular plugins and themes may have become lifecycle-active
     * during a partially failed run and must remain executable until the
     * fresh retirement/switch process. MU plugins have no WordPress
     * activation/deactivation API, while leaving a rejected top-level MU file
     * in place can prevent every normal lifecycle command from booting.
     *
     * Complete desired/removal preflight runs before this method. Recheck the
     * exact staged hash immediately before each unlink; a changed or replaced
     * target remains operator-owned ambiguity and fails closed.
     *
     * @return list<string>
     */
    private static function remove_abandoned_staged_mu_files(
        ?array $previous,
        ?array $staged,
        array $current,
        array $stagedCreatedPaths
    ): array {
        return CodeMaterializer::remove_abandoned_staged_mu_files(
            $previous,
            $staged,
            $current,
            $stagedCreatedPaths
        );
    }

    /** Validate the complete desired path inventory without creating it. */
    private static function assert_payload_targets(array $descriptor): void {
        CodeMaterializer::assert_payload_targets($descriptor);
    }

    private static function verify_payload(array $descriptor): void {
        CodeMaterializer::verify_payload($descriptor);
    }

    /**
     * Thin compatibility facade over CodeOwnershipPruner::remove_old_owned_files()
     * (DUO-3350 slice 5) -- kept so this method's existing internal call site
     * (finalize(), unchanged) needs no edit while this decomposition proceeds.
     */
    private static function remove_old_owned_files(?array $previous, ?array $staged, array $history, array $current): array {
        return CodeOwnershipPruner::remove_old_owned_files($previous, $staged, $history, $current, self::ROOTS);
    }

    /**
     * Thin compatibility facade over CodeOwnershipPruner::assert_removal_safe()
     * (DUO-3350 slice 5) -- kept so the callback materialize_payload()'s
     * facade constructs for CodeMaterializer::materialize_payload() needs no
     * change while this decomposition proceeds.
     */
    private static function assert_removal_safe(?array $previous, ?array $staged, array $history, array $current): void {
        CodeOwnershipPruner::assert_removal_safe($previous, $staged, $history, $current, self::ROOTS);
    }

    /**
     * Thin compatibility facade over CodeOwnershipPruner::owned_extra_files()
     * (DUO-3350 slice 5) -- kept so this method's existing internal call
     * sites (completed_code_mismatch(), validated_initial_baseline(),
     * finalize(), unchanged) need no edit while this decomposition proceeds.
     */
    private static function owned_extra_files(array $descriptor): array {
        return CodeOwnershipPruner::owned_extra_files($descriptor);
    }

    private static function safe_join(string $root, string $relative): string {
        return PathSafety::safe_join($root, $relative);
    }

    /**
     * The v0 descriptor is specifically a standard WP_CONTENT_DIR payload.
     * Refuse custom plugin/mu-plugin/theme roots: copying into the standard
     * sibling while WordPress executes another directory would falsely claim
     * a successful materialization of inert files.
     */
    private static function assert_target_layout(?array $descriptor = null): void {
        CodeMaterializer::assert_target_layout($descriptor);
    }

    private static function same_target_path(string $actual, string $expected): bool {
        return PathSafety::same_target_path($actual, $expected);
    }

    /**
     * Reject a symlink at any path segment below the configured content
     * boundary. A descriptor path can be textually below WP_CONTENT_DIR
     * while an intermediate plugins/themes/mu-plugins (or component) link
     * redirects traversal elsewhere. Recheck this before every verification
     * and prune boundary; stage already performs the same walk while creating
     * each target parent.
     */
    private static function assert_no_symlinked_target_path(
        string $relative,
        bool $includeLeaf,
        string $operation
    ): void {
        PathSafety::assert_no_symlinked_target_path($relative, $includeLeaf, $operation);
    }

    private static function safe_relative(string $path): bool {
        return PathSafety::safe_relative($path);
    }

    private static function safe_component(string $name): bool {
        return PathSafety::safe_component($name);
    }

    /** Match WordPress get_plugins(): root PHP files or PHP files one directory deep. */
    private static function plugin_main_candidate(string $path): bool {
        return PathSafety::plugin_main_candidate($path);
    }

    /** @param array<string,bool> $currentPaths */
    private static function has_current_path_at_or_below(string $path, array $currentPaths): bool {
        return PathSafety::has_current_path_at_or_below($path, $currentPaths);
    }

    /** @param array<string,bool> $ownedRoots */
    private static function owned_path(string $path, array $ownedRoots): bool {
        return PathSafety::owned_path($path, $ownedRoots);
    }

    private static function safe_component_root(string $path): bool {
        return PathSafety::safe_component_root($path, self::ROOTS);
    }

    private static function reserved_path(string $path): bool {
        return PathSafety::reserved_path($path);
    }

}
