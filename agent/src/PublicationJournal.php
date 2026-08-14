<?php
namespace Duo;

// Kept on the historical publication load path because Cli's public refusal
// allowlist audits this class by declaration file. DurableFilesystem consumes
// the same boundary type when it is loaded independently.
if (!class_exists(InitialStateBoundaryException::class, false)) {
    final class InitialStateBoundaryException extends \RuntimeException {}
}

require_once __DIR__ . '/CommandRefusal.php';

require_once __DIR__ . '/Canon.php';
require_once __DIR__ . '/DurableFilesystem.php';

/** Immutable typed view of a sealed publication intent or receipt. */
final class PublicationRecord {
    /** @param array<string,mixed> $payload */
    private function __construct(private array $payload) {}

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self {
        self::assertShape($payload);
        return new self($payload);
    }

    /** @param array<string,mixed> $payload */
    private static function assertShape(array $payload): void {
        $format = $payload['format'] ?? null;
        $isIntent = $format === 'duo-capture-intent/v1';
        $isReceipt = $format === 'duo-capture-receipt/v1';
        if (!$isIntent && !$isReceipt) {
            throw new \InvalidArgumentException('unsupported publication record format');
        }
        $idKey = $isIntent ? 'id' : 'intent_id';
        $timeKey = $isIntent ? 'created_at' : 'committed_at';
        $expectedKeys = $isIntent
            ? ['format', 'id', 'phase', 'candidate_sha256', 'previous_sha256', 'created_at', 'record_sha256']
            : ['format', 'intent_id', 'phase', 'candidate_sha256', 'previous_sha256', 'committed_at', 'record_sha256'];
        $actualKeys = array_keys($payload);
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if (!is_string($payload[$idKey] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/D', (string) $payload[$idKey])
            || ($isIntent
                ? !in_array($payload['phase'] ?? null, ['prepared', 'swapped', 'ready', 'committing'], true)
                : ($payload['phase'] ?? null) !== 'committed')
            || !is_string($payload['candidate_sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', (string) $payload['candidate_sha256'])
            || !is_string($payload['previous_sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', (string) $payload['previous_sha256'])
            || !is_string($payload[$timeKey] ?? null)
            || trim($payload[$timeKey]) === ''
            || !is_string($payload['record_sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', (string) $payload['record_sha256'])
            || $actualKeys !== $expectedKeys) {
            throw new \InvalidArgumentException('malformed publication record');
        }
        $sealed = $payload;
        $recordSha = (string) $sealed['record_sha256'];
        unset($sealed['record_sha256']);
        if (!hash_equals($recordSha, hash('sha256', Canon::encode($sealed)))) {
            throw new \InvalidArgumentException('publication record seal does not match its canonical bytes');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array { return $this->payload; }
    public function format(): string { return (string) $this->payload['format']; }
    public function phase(): string { return (string) $this->payload['phase']; }
    public function id(): string {
        return (string) ($this->payload['id'] ?? $this->payload['intent_id'] ?? '');
    }
    public function candidateDigest(): string { return (string) $this->payload['candidate_sha256']; }
    public function previousDigest(): string { return (string) $this->payload['previous_sha256']; }
}

/**
 * Publish: atomic tree publication for `duo capture` (DUO-3213).
 *
 * Pure filesystem, zero WordPress/$wpdb dependency — deliberate, so every
 * guarantee here is independently testable offline (no docker, no WP
 * bootstrap; the same "vendored fixture, real code" idiom as sandbox/tests/
 * regress_block_refs.php's FakeWpdb, just with no DB stub needed at all).
 * CaptureTransaction.php owns the DB-side half of this issue (consistent-snapshot
 * transaction, storage-engine check, deadlock retry — see its own
 * docblocks); this class owns the filesystem-side half: a capture lock,
 * a staging directory, an atomic two-step rename swap, and deterministic
 * recovery of whatever a crash mid-swap leaves behind.
 *
 * The capture path uses the small publication protocol at the bottom of this
 * class as well.  Filesystem rename and a database COMMIT cannot be one
 * instantaneous two-phase commit: the protocol therefore records an intent
 * before swapping, retains the old tree until COMMIT has returned, and writes
 * a receipt only after that acknowledgement. Capture writes an intent-bound
 * commit marker as the final DML in the same transaction. After a crash,
 * that marker decides whether recovery keeps the candidate or restores the
 * retained tree; malformed or contradictory evidence fails closed. A receipt
 * makes final cleanup idempotent and safe to resume.
 *
 * Why two renames, not one: POSIX rename(2) is atomic, but it CANNOT
 * replace a non-empty destination directory in a single call (fails
 * ENOTEMPTY on Linux) — there is no single syscall that atomically swaps
 * one populated directory tree for another. The standard workaround, used
 * here, is a rename SEQUENCE where every individual step is still atomic:
 *
 *   1. rename(state, state.capture-backup)   -- 'state' now doesn't exist
 *   2. rename(state.capture-staging, state)  -- 'state' now = new tree
 *   3. best-effort: remove state.capture-backup
 *
 * Between steps 1 and 2 the published path briefly does not exist at all —
 * a very different, much safer failure mode than a half-overwritten
 * directory: any reader (git, Lint, a human `ls`) that hits that instant
 * sees either the complete old tree, nothing, or the complete new tree,
 * NEVER a mix of old and new files. If the process dies in that window,
 * recover() below restores deterministically on the next run. This relies
 * on rename(2) being atomic per-syscall on the filesystem in question
 * (true for same-device moves on every mainstream local filesystem —
 * ext4, APFS, XFS, Btrfs, overlay2 within one layer; NOT guaranteed across
 * a bind-mount boundary that spans two different underlying devices) —
 * satisfied here by construction, since staging/backup are always created
 * as siblings of the published directory itself, never on another mount.
 *
 * On-disk layout (all names are siblings of the published directory,
 * e.g. repo/state; every one of them is gitignored — git must never see a
 * staging/backup/lock artifact even transiently):
 *   state                    the published tree; readers only ever see
 *                            this name, and it is always either the
 *                            complete PREVIOUS tree or the complete NEW
 *                            one (see swap() below), never a partial mix
 *   state.capture-staging    this run's candidate tree, built and
 *                            validated BEFORE it ever touches 'state'
 *   state.capture-backup     the previous tree, retained until database
 *                            COMMIT is proven and cleanup completes
 *   state.capture-intent     durable pre-swap publication intent
 *   state.capture-receipt    durable post-commit receipt (retained as audit)
 *   state.capture.lock       flock() target serializing concurrent
 *                            publishers to this SAME destination
 */
/**
 * Filesystem publication service.
 *
 * This is the durable publication protocol owner. It owns record codecs,
 * intent/receipt transitions, crash recovery, and the atomic tree swap; the
 * AtomicTreePublisher and Publish names below are compatibility facades.
 */
class PublicationJournal {
    public function __construct(private string $stateDir) {}

    public static function lock_path(string $stateDir): string {
        return $stateDir . '.capture.lock';
    }

    public static function stage_dir(string $stateDir): string {
        return $stateDir . '.capture-staging';
    }

    public static function backup_dir(string $stateDir): string {
        return $stateDir . '.capture-backup';
    }

    /** Durable protocol records.  They are siblings of state/ and are
     * intentionally not placed inside the tree that is being swapped. */
    public static function intent_path(string $stateDir): string {
        return $stateDir . '.capture-intent';
    }

    public static function receipt_path(string $stateDir): string {
        return $stateDir . '.capture-receipt';
    }

    /** @return ?array<string,mixed> */
    public static function intent_record(string $stateDir): ?array {
        self::assert_protocol_roots($stateDir);
        $intent = self::read_record(self::intent_path($stateDir), 'intent');
        if ($intent !== null) {
            self::assert_record($intent, 'intent', 'duo-capture-intent/v1');
        }
        return $intent;
    }

    /** @return ?array<string,mixed> */
    public static function receipt_record(string $stateDir): ?array {
        self::assert_protocol_roots($stateDir);
        $receipt = self::read_record(self::receipt_path($stateDir), 'receipt');
        if ($receipt !== null) {
            self::assert_record($receipt, 'receipt', 'duo-capture-receipt/v1');
        }
        return $receipt;
    }

    /**
     * Resolve the strict first-capture create-only window where the prepared
     * intent reached its sealed `.next` hard link but not its canonical name.
     * The caller supplies the journaled complete staging manifest; without
     * that deletion authority this method refuses and preserves everything.
     *
     * @param array{root:array<string,string>,entries:list<array<string,mixed>>} $stagingManifest
     */
    public static function recover_initial_unpublished_intent_next(
        string $stateDir,
        array $stagingManifest
    ): bool {
        self::assert_protocol_roots($stateDir);
        $path = self::intent_path($stateDir);
        $previous = self::record_previous_path($path);
        $next = self::record_next_path($path);
        if (file_exists($path) || is_link($path) || file_exists($previous) || is_link($previous)) {
            return false;
        }
        if (!file_exists($next) && !is_link($next)) {
            return false;
        }
        $record = self::read_record($next, 'intent');
        if ($record === null) {
            throw self::ambiguous_recovery('initial capture intent next-transition is missing');
        }
        self::assert_record($record, 'intent', 'duo-capture-intent/v1');
        $staging = self::stage_dir($stateDir);
        // A removal transition also leaves only `.next`, but its candidate
        // has already been published and its staging tree is normally gone.
        // Let strict recover_initial() resolve that finite transition rather
        // than treating every intent-only-next shape as a fresh create.
        if (($record['phase'] ?? null) !== 'prepared'
            || !is_dir($staging)
            || is_link($staging)) {
            return false;
        }
        self::assert_owned_tree($staging, $stagingManifest, 'initial capture staging');
        if (($record['phase'] ?? null) !== 'prepared'
            || !hash_equals((string) $record['previous_sha256'], self::empty_tree_digest())
            || !hash_equals((string) $record['candidate_sha256'], self::tree_digest($staging))) {
            throw self::ambiguous_recovery(
                'initial capture unpublished intent does not bind the journaled complete staging payload'
            );
        }
        self::remove_matching_record_temps($path, $next, $record, 'intent');
        self::remove_record_transition_artifact($next, $record, 'intent');
        self::fsync_dir(dirname($path));
        return true;
    }

    /**
     * Strict first-publication recovery. Every recursive removal is
     * authorized by the complete manifests sealed in the init journal;
     * ordinary capture's digest-only legacy cleanup is never used here.
     *
     * @param array{root:array<string,string>,entries:list<array<string,mixed>>} $stateReservation
     * @param array{root:array<string,string>,entries:list<array<string,mixed>>} $candidate
     * @param callable(array<string,mixed>):bool $commitStatus
     * @return string[]
     */
    public static function recover_initial(
        string $stateDir,
        array $stateReservation,
        array $candidate,
        callable $commitStatus
    ): array {
        self::assert_protocol_roots($stateDir);
        $log = [];
        foreach ([
            [self::intent_path($stateDir), 'intent'],
            [self::receipt_path($stateDir), 'receipt'],
        ] as [$recordPath, $recordLabel]) {
            $resolved = self::recover_record_transition($recordPath, $recordLabel);
            if ($resolved !== null) {
                $log[] = $resolved;
            }
        }
        self::assert_no_record_temps($stateDir);
        $intentPath = self::intent_path($stateDir);
        $intent = self::read_record($intentPath, 'intent');
        $receipt = self::read_record(self::receipt_path($stateDir), 'receipt');
        $staging = self::stage_dir($stateDir);
        $backup = self::backup_dir($stateDir);

        if ($receipt !== null) {
            self::assert_record($receipt, 'receipt', 'duo-capture-receipt/v1');
            if (!is_dir($stateDir)) {
                throw self::ambiguous_recovery('initial committed receipt retained no published state tree');
            }
            self::assert_owned_tree($stateDir, $candidate, 'initial committed state');
            $candidateDigest = self::tree_digest($stateDir);
            if (!hash_equals((string) ($receipt['candidate_sha256'] ?? ''), $candidateDigest)
                || !hash_equals((string) ($receipt['previous_sha256'] ?? ''), self::empty_tree_digest())) {
                throw self::ambiguous_recovery(
                    'initial committed receipt contradicts the exact candidate or empty first-publication base'
                );
            }
            if ($intent === null) {
                if (is_dir($staging) || is_link($staging) || is_dir($backup) || is_link($backup)) {
                    throw self::ambiguous_recovery('initial committed receipt retained an unbound publication sibling');
                }
                return $log;
            }
            self::assert_record($intent, 'intent', 'duo-capture-intent/v1');
            self::assert_first_publication($intent);
            if (($intent['id'] ?? null) !== ($receipt['intent_id'] ?? null)
                || !hash_equals((string) $intent['candidate_sha256'], (string) $receipt['candidate_sha256'])
                || !hash_equals((string) $intent['previous_sha256'], (string) $receipt['previous_sha256'])
                || $commitStatus($intent) !== true) {
                throw self::ambiguous_recovery('initial receipt does not match a durable committed transaction');
            }
            if (is_dir($staging) || is_link($staging)) {
                self::remove_owned_tree_initial($staging, $candidate, 'initial committed staging');
            }
            if (is_dir($backup) || is_link($backup)) {
                self::remove_owned_tree_initial($backup, $stateReservation, 'initial committed reservation');
            }
            self::remove_record($intentPath, 'intent', $intent);
            $log[] = 'RECOVERED: finalized the exact manifest-bound initial commit receipt';
            return $log;
        }
        if ($intent === null) {
            if (is_dir($staging) || is_link($staging) || is_dir($backup) || is_link($backup)) {
                throw self::ambiguous_recovery('initial publication has payload artifacts without a sealed intent');
            }
            return $log;
        }
        self::assert_record($intent, 'intent', 'duo-capture-intent/v1');
        self::assert_first_publication($intent);
        $phase = (string) ($intent['phase'] ?? '');
        if ($phase === 'committing') {
            $marker = $commitStatus($intent);
            if ($marker === true) {
                if (!is_dir($stateDir) || is_dir($staging) || is_link($staging)) {
                    throw self::ambiguous_recovery('initial committed marker has an inconsistent publication shape');
                }
                self::assert_owned_tree($stateDir, $candidate, 'initial committed state');
                if (is_dir($backup) || is_link($backup)) {
                    self::assert_owned_tree($backup, $stateReservation, 'initial committed reservation');
                }
                $receipt = self::write_receipt($stateDir, $intent, true);
                if (is_dir($backup) || is_link($backup)) {
                    self::remove_owned_tree_initial($backup, $stateReservation, 'initial committed reservation');
                }
                self::remove_record($intentPath, 'intent', $intent);
                $log[] = 'RECOVERED: transaction marker finalized the exact manifest-bound initial publication';
                return $log;
            }
            if ($marker !== false) {
                throw self::ambiguous_recovery('initial COMMIT outcome is not provable');
            }
        } elseif (!in_array($phase, ['prepared', 'swapped', 'ready'], true)) {
            throw self::ambiguous_recovery('initial publication intent phase is malformed');
        }

        $hasState = is_dir($stateDir) || is_link($stateDir);
        $hasStaging = is_dir($staging) || is_link($staging);
        $hasBackup = is_dir($backup) || is_link($backup);
        if ($hasState && $hasStaging && !$hasBackup && $phase === 'prepared') {
            self::assert_owned_tree($stateDir, $stateReservation, 'initial state reservation');
            self::remove_owned_tree_initial($staging, $candidate, 'initial capture staging');
        } elseif (!$hasState && $hasStaging && $hasBackup && $phase === 'prepared') {
            self::assert_owned_tree($backup, $stateReservation, 'initial retained reservation');
            self::remove_owned_tree_initial($staging, $candidate, 'initial capture staging');
            if (!@rename($backup, $stateDir)) {
                throw self::ambiguous_recovery('initial reservation could not be restored after first rename');
            }
            self::assert_owned_tree($stateDir, $stateReservation, 'restored initial reservation');
        } elseif ($hasState && !$hasStaging && $hasBackup) {
            self::assert_owned_tree($stateDir, $candidate, 'initial published candidate');
            self::assert_owned_tree($backup, $stateReservation, 'initial retained reservation');
            self::remove_owned_tree_initial($stateDir, $candidate, 'initial published candidate');
            if (!@rename($backup, $stateDir)) {
                throw self::ambiguous_recovery('initial reservation could not be restored after candidate rollback');
            }
            self::assert_owned_tree($stateDir, $stateReservation, 'restored initial reservation');
        } else {
            throw self::ambiguous_recovery('initial publication intent has an unexpected manifest-bound filesystem shape');
        }
        self::remove_record($intentPath, 'intent', $intent);
        self::fsync_dir(dirname($stateDir));
        $log[] = 'RECOVERED: rolled back the exact manifest-bound initial publication';
        return $log;
    }

    /**
     * Non-blocking capture lock (task #213's "concurrent captures serialize
     * or one fails cleanly" — this implements the fail-cleanly half: an
     * immediate, actionable refusal beats an indefinitely blocked CI job or
     * cron invocation, and a caller that wants to wait can simply retry).
     * flock() is released by the OS the instant this process's file
     * descriptor closes for ANY reason — normal exit, uncaught exception,
     * SIGKILL, OOM-kill — which is exactly what makes recover() below
     * deterministic: successfully ACQUIRING this lock is itself proof that
     * no other capture is currently alive, so any leftover staging/backup
     * directory found afterward is unowned and safe to reconcile.
     *
     * @return resource an open file handle; pass to unlock() when done
     */
    public static function lock(string $stateDir) {
        self::assert_protocol_roots($stateDir);
        $path = self::lock_path($stateDir);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("duo: cannot create directory $dir for the capture lock");
        }
        $fh = fopen($path, 'c'); // create-if-missing, never truncate on open — only flock() state matters
        if ($fh === false) {
            throw new \RuntimeException("duo: cannot open capture lock file $path");
        }
        return self::acquire_lock_handle($fh, $stateDir, $path);
    }

    /**
     * Init's first publication owns an absent repository boundary. Opening
     * with `x` makes creation and ownership one atomic operation: a raced
     * ordinary file is never mistaken for the inode whose flock we hold.
     *
     * @return resource
     */
    public static function lock_new(string $stateDir) {
        self::assert_protocol_roots($stateDir);
        $path = self::lock_path($stateDir);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            throw new \RuntimeException("duo: cannot create first capture lock outside an existing repository root");
        }
        $fh = @fopen($path, 'x');
        if ($fh === false) {
            throw new \RuntimeException(
                "duo: first capture lock boundary changed after review; preserving the existing path $path"
            );
        }
        $opened = @fstat($fh);
        $named = @lstat($path);
        $createdSame = is_array($opened) && is_array($named) && !is_link($path) && is_file($path)
            && (string) ($opened['dev'] ?? '') === (string) ($named['dev'] ?? '')
            && (string) ($opened['ino'] ?? '') === (string) ($named['ino'] ?? '');
        if (!$createdSame) {
            fclose($fh);
            throw new \RuntimeException('duo: first capture lock changed identity immediately after creation');
        }
        try {
            if (getenv('DUO_TEST_MODE') === '1'
                && getenv('DUO_TEST_INIT_FAIL_PHASE') === 'lock-acquire-after-create') {
                fclose($fh);
                throw new \RuntimeException('duo: injected first capture lock acquisition refusal');
            }
            return self::acquire_lock_handle($fh, $stateDir, $path);
        } catch (\Throwable $failure) {
            clearstatcache(true, $path);
            $current = @lstat($path);
            $stillCreated = is_array($current) && !is_link($path) && is_file($path)
                && (string) ($opened['dev'] ?? '') === (string) ($current['dev'] ?? '')
                && (string) ($opened['ino'] ?? '') === (string) ($current['ino'] ?? '');
            if ($stillCreated) {
                @unlink($path);
                self::fsync_dir(dirname($path));
            }
            throw $failure;
        }
    }

    /** @param resource $fh @return resource */
    private static function acquire_lock_handle($fh, string $stateDir, string $path) {
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            $operatorMessage = "duo: capture refused — another capture is already publishing to $stateDir (lock held: $path).\n"
                . 'Concurrent captures to the same destination are never interleaved (DUO-3213); '
                . 'wait for the other one to finish and re-run.';
            throw new CommandRefusalException(
                'capture_lock_held',
                'capture refused because another publisher holds the destination lock',
                'wait for the current publisher to finish, verify its receipt, then start a new capture',
                [[
                    'code' => 'capture_lock_held',
                    'message' => 'another publisher owns the capture destination',
                    'remediation' => 'wait for the publisher and verify its capture receipt',
                ]],
                $operatorMessage
            );
        }
        // Diagnostics only (never read back by any code path, including
        // recover() below — ownership is decided purely by flock() state,
        // never by this content) — a human doing forensics on a stuck lock
        // can `cat` it to see who last held it.
        @ftruncate($fh, 0);
        @fwrite($fh, sprintf("pid=%d host=%s started=%s\n", getmypid(), (string) gethostname(), date('c')));
        @fflush($fh);
        return $fh;
    }

    /**
     * Prove that the canonical lock pathname still names the inode whose
     * open descriptor owns the flock. An unlink/recreate race leaves the
     * descriptor locked but would otherwise let another publisher lock the
     * replacement pathname and interleave publication.
     *
     * @param resource $handle
     */
    public static function assert_lock_path($handle, string $stateDir): void {
        if (!is_resource($handle)) {
            throw new \InvalidArgumentException('duo: capture lock assertion requires an open descriptor');
        }
        $path = self::lock_path($stateDir);
        clearstatcache(true, $path);
        $fd = @fstat($handle);
        $named = @lstat($path);
        if (is_link($path) || !is_file($path)
            || !is_array($fd) || !is_array($named)
            || !isset($fd['dev'], $fd['ino'], $named['dev'], $named['ino'])
            || (string) $fd['dev'] !== (string) $named['dev']
            || (string) $fd['ino'] !== (string) $named['ino']) {
            throw new \RuntimeException(
                "duo: capture lock pathname changed while its original descriptor remained held: $path"
            );
        }
    }

    public static function unlock($handle): void {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Deterministic recovery of whatever a prior crashed run left behind.
     * MUST run only while holding lock() — that is what makes "found a
     * staging/backup dir" mean "abandoned by a dead process," never "in use
     * right now by a live one." Returns human-readable log lines (the
     * "actionable staging artifact" acceptance criterion: a recovery is
     * reported, never silent).
     *
     * A completed receipt is authoritative only while its matching intent is
     * still present. A receipt left by an otherwise clean run is audit history
     * and must not claim a later run's artifacts or prevent a normal edit of
     * state/ from being captured.
     *
     * Without a receipt, an intent describes a candidate that may have
     * crossed the filesystem boundary. Capture supplies a database callback:
     * the matching transaction-bound commit marker keeps the candidate, while
     * an absent/prior marker restores the old tree (or removes a first-ever
     * candidate). Missing proof or contradictory hashes fail closed.
     *
     * For legacy callers that used Publish::swap() without an intent, the
     * historical staging/backup reconciliation remains compatible: a backup
     * plus a present state is stale, while a backup plus a missing state is
     * restored.  New Capture::run() calls always create an intent first.
     *
     * For that legacy/no-intent path, three independent facts decide the
     * outcome — $stateDir existing, a leftover staging dir, a leftover
     * backup dir:
     *
     *  - A leftover STAGING dir is always DISCARDED, unconditionally. It
     *    was never promoted (recover() runs before this run builds its own
     *    staging dir, so anything found here predates us). Two sub-cases
     *    collapse to the same answer for different reasons: a crash DURING
     *    write_entities() leaves a genuinely incomplete tree, no way to
     *    prove otherwise; a crash BETWEEN swap()'s two renames leaves a
     *    staging tree that is actually complete by construction
     *    (write_entities() already returned before swap() was ever called)
     *    — but it is still discarded, because this run did not build or
     *    validate it itself, and promoting a candidate on that basis alone
     *    is the riskier semantic regardless of how complete it looks.
     *    Rebuilding fresh is cheap and certain either way.
     *  - A leftover BACKUP dir + $stateDir MISSING means the previous run
     *    died between swap()'s two renames: $stateDir was already moved
     *    aside and the new tree never arrived. The backup IS the last
     *    known-good published tree — restore it verbatim.
     *  - A leftover BACKUP dir + $stateDir PRESENT means swap() completed
     *    in full and only its own best-effort final cleanup didn't run
     *    (crash, or an interrupted process). The backup is stale; sweep it.
     *
     * @param null|callable(array<string,mixed>): bool $commitStatus Optional
     *        database-side proof that the transaction's commit marker became
     *        durable. Publish remains DB-free by default; Capture supplies
     *        this callback so a receipt-write crash can be finalized without
     *        replaying the capture transaction.
     * @return string[] log lines describing what, if anything, was recovered
     */
    public static function recoverProtocol(string $stateDir, ?callable $commitStatus = null): array {
        self::assert_protocol_roots($stateDir);
        $log = [];
        $staging = self::stage_dir($stateDir);
        $backup = self::backup_dir($stateDir);

        foreach ([
            [self::intent_path($stateDir), 'intent'],
            [self::receipt_path($stateDir), 'receipt'],
        ] as [$recordPath, $recordLabel]) {
            $transitionRecovery = self::recover_record_transition($recordPath, $recordLabel);
            if ($transitionRecovery !== null) {
                $log[] = $transitionRecovery;
            }
        }
        $intent = self::read_record(self::intent_path($stateDir), 'intent');
        $receipt = self::read_record(self::receipt_path($stateDir), 'receipt');

        // Validate marker shape before comparing ids. A prior successful
        // receipt may still be present when a new intent was durably written;
        // it remains useful audit evidence, but is never authority for the
        // newer intent.
        if ($intent !== null) {
            self::assert_record($intent, 'intent', 'duo-capture-intent/v1');
        }
        if ($receipt !== null) {
            self::assert_record($receipt, 'receipt', 'duo-capture-receipt/v1');
        }
        $matchingReceipt = $intent !== null && $receipt !== null
            && ($intent['id'] ?? null) === ($receipt['intent_id'] ?? null)
            ? $receipt
            : null;

        // A receipt is authoritative only while its matching intent still
        // exists. Once cleanup removes that intent, the receipt is retained
        // as audit history. It must not claim an abandoned staging tree from
        // a later run or block recovery after an ordinary edit to state/.
        if ($matchingReceipt !== null) {
            if ($commitStatus !== null && $commitStatus($intent) !== true) {
                throw self::ambiguous_recovery(
                    'a capture receipt exists but its transaction-bound database commit marker is absent or prior'
                );
            }
            if (!is_dir($stateDir)) {
                throw self::ambiguous_recovery(
                    'a committed receipt exists but published state/ is missing'
                );
            }
            $actual = self::tree_digest($stateDir);
            if (!hash_equals((string) ($matchingReceipt['candidate_sha256'] ?? ''), $actual)) {
                throw self::ambiguous_recovery(
                    'a committed receipt does not match the current state/ tree'
                );
            }
            if (is_dir($staging)) {
                self::assert_tree_digest($staging, (string) $matchingReceipt['candidate_sha256'], 'post-commit staging');
                self::rrmdir($staging);
                $log[] = "recovered: removed post-commit staging dir $staging";
            }
            if (is_dir($backup)) {
                self::assert_tree_digest($backup, (string) $matchingReceipt['previous_sha256'], 'post-commit backup');
                self::rrmdir($backup);
                $log[] = "recovered: removed retained post-commit backup $backup";
            }
            if ($intent !== null) {
                self::remove_record(self::intent_path($stateDir), 'intent', $intent);
                $log[] = 'recovered: finalized the durable capture intent after its commit receipt was found';
            }
            return $log;
        }

        if ($intent !== null) {
            self::assert_record($intent, 'intent', 'duo-capture-intent/v1');
            $phase = (string) ($intent['phase'] ?? '');

            if ($phase === 'prepared') {
                // No backup and a live staging tree means the swap never
                // started.  Discarding it is deterministic and the DB
                // transaction that minted the candidate was rolled back by
                // the crashed process.
                if (is_dir($staging) && !is_dir($backup) && is_dir($stateDir)) {
                    self::assert_tree_digest($staging, (string) $intent['candidate_sha256'], 'staged candidate');
                    self::assert_tree_digest($stateDir, (string) $intent['previous_sha256'], 'published state');
                    self::rrmdir($staging);
                    self::remove_record(self::intent_path($stateDir), 'intent', $intent);
                    $log[] = "recovered: removed pre-swap staging dir $staging and intent (candidate was never published)";
                    return $log;
                }
                // First-ever publication: there is no old state and hence
                // no backup. If the process died before step 2, the staged
                // candidate is the only artifact and can be discarded.
                if (is_dir($staging) && !is_dir($backup) && !is_dir($stateDir)) {
                    self::assert_first_publication($intent);
                    self::assert_tree_digest($staging, (string) $intent['candidate_sha256'], 'first-capture staged candidate');
                    self::rrmdir($staging);
                    self::remove_record(self::intent_path($stateDir), 'intent', $intent);
                    $log[] = "recovered: removed first-capture pre-swap staging dir $staging and intent";
                    return $log;
                }
                // The first rename may have happened while the intent still
                // said prepared.  A missing state plus backup is the one
                // unambiguous mid-swap shape: restore the last known-good
                // tree, discard the unpromoted staging tree, and forget the
                // intent.
                if (is_dir($backup) && !is_dir($stateDir)) {
                    self::assert_tree_digest($backup, (string) $intent['previous_sha256'], 'retained backup');
                    if (is_dir($staging)) {
                        self::assert_tree_digest($staging, (string) $intent['candidate_sha256'], 'staged candidate');
                    }
                    self::restore_backup($stateDir, $backup);
                    if (is_dir($staging)) {
                        self::rrmdir($staging);
                    }
                    self::remove_record(self::intent_path($stateDir), 'intent', $intent);
                    $log[] = "RECOVERED: restored $stateDir from the retained backup after an interrupted pre-commit swap";
                    return $log;
                }
                // State + backup with no staging means both renames already
                // happened, but the phase marker did not.  COMMIT has not
                // been attempted until the wrapper advances the intent to
                // `committing`, so restoring the old tree is deterministic.
                if (is_dir($backup) && is_dir($stateDir) && !is_dir($staging)) {
                    self::assert_tree_digest($backup, (string) $intent['previous_sha256'], 'retained backup');
                    self::assert_tree_digest($stateDir, (string) $intent['candidate_sha256'], 'published candidate');
                    self::replace_with_backup($stateDir, $backup);
                    self::remove_record(self::intent_path($stateDir), 'intent', $intent);
                    $log[] = "RECOVERED: restored $stateDir from the retained backup before COMMIT was attempted";
                    return $log;
                }
                // First-ever publication after step 2: no backup means the
                // complete candidate is present, but COMMIT was not reached.
                if (!is_dir($backup) && is_dir($stateDir) && !is_dir($staging)) {
                    self::assert_first_publication($intent);
                    self::assert_tree_digest($stateDir, (string) $intent['candidate_sha256'], 'first-capture published candidate');
                    self::rrmdir($stateDir);
                    self::remove_record(self::intent_path($stateDir), 'intent', $intent);
                    $log[] = "RECOVERED: removed first-capture candidate published before COMMIT was attempted";
                    return $log;
                }
                throw self::ambiguous_recovery('capture intent has an unexpected pre-commit filesystem shape');
            }

            if (in_array($phase, ['swapped', 'ready'], true)) {
                if (is_dir($stateDir) && is_dir($backup) && !is_dir($staging)) {
                    self::assert_tree_digest($backup, (string) $intent['previous_sha256'], 'retained backup');
                    self::assert_tree_digest($stateDir, (string) $intent['candidate_sha256'], 'published candidate');
                    self::replace_with_backup($stateDir, $backup);
                    self::remove_record(self::intent_path($stateDir), 'intent', $intent);
                    $log[] = "RECOVERED: restored $stateDir from the retained backup before COMMIT was attempted";
                    return $log;
                }
                if (!is_dir($backup) && is_dir($stateDir) && !is_dir($staging)) {
                    self::assert_first_publication($intent);
                    self::assert_tree_digest($stateDir, (string) $intent['candidate_sha256'], 'first-capture published candidate');
                    self::rrmdir($stateDir);
                    self::remove_record(self::intent_path($stateDir), 'intent', $intent);
                    $log[] = "RECOVERED: removed first-capture candidate published before COMMIT was attempted";
                    return $log;
                }
                // A swapped marker with a missing state cannot be repaired
                // by choosing either side: the marker says step 2 returned,
                // while the filesystem says otherwise.  Keep it for a human.
                throw self::ambiguous_recovery('swapped capture intent does not match the filesystem');
            }
            if ($phase === 'committing') {
                $marker = null;
                if ($commitStatus !== null) {
                    $marker = $commitStatus($intent);
                }
                if ($marker === true) {
                    if (!is_dir($stateDir) || is_dir($staging)) {
                        throw self::ambiguous_recovery('a valid commit marker exists but the publication tree shape is inconsistent');
                    }
                    self::assert_tree_digest($stateDir, (string) $intent['candidate_sha256'], 'committed published candidate');
                    if (is_dir($backup)) {
                        self::assert_tree_digest($backup, (string) $intent['previous_sha256'], 'committed retained backup');
                    }
                    $committedReceipt = self::write_receipt($stateDir, $intent);
                    self::cleanup_committed($stateDir, $committedReceipt);
                    $log[] = 'RECOVERED: database commit marker proved the publication durable; wrote its missing receipt and finalized cleanup';
                    return $log;
                }
                if ($marker === false) {
                    // A readable, absent/prior marker proves this intent did
                    // not commit. Restore the old tree (or remove a first
                    // publication) exactly as for the earlier ready phase.
                    if (is_dir($backup) && !is_dir($stateDir)) {
                        self::assert_tree_digest($backup, (string) $intent['previous_sha256'], 'retained backup');
                        if (is_dir($staging)) {
                            self::assert_tree_digest($staging, (string) $intent['candidate_sha256'], 'staged candidate');
                        }
                        self::restore_backup($stateDir, $backup);
                    } elseif (is_dir($backup) && is_dir($stateDir)) {
                        self::assert_tree_digest($backup, (string) $intent['previous_sha256'], 'retained backup');
                        self::assert_tree_digest($stateDir, (string) $intent['candidate_sha256'], 'published candidate');
                        self::replace_with_backup($stateDir, $backup);
                    } elseif (!is_dir($backup) && is_dir($stateDir)) {
                        self::assert_first_publication($intent);
                        self::assert_tree_digest($stateDir, (string) $intent['candidate_sha256'], 'first-capture published candidate');
                        self::rrmdir($stateDir);
                    } else {
                        throw self::ambiguous_recovery('database marker says rollback, but neither candidate nor backup is present');
                    }
                    if (is_dir($staging)) {
                        self::rrmdir($staging);
                    }
                    self::remove_record(self::intent_path($stateDir), 'intent', $intent);
                    $log[] = 'RECOVERED: database commit marker was absent/prior; rolled back the uncommitted publication';
                    return $log;
                }
                throw self::ambiguous_recovery('COMMIT was attempted but no database commit proof or receipt is available');
            }
            throw self::ambiguous_recovery('publication intent phase is malformed');
        }

        // Legacy/no-intent behavior retained for the low-level Publish API.
        if (is_dir($staging)) {
            self::rrmdir($staging);
            $log[] = "recovered: removed abandoned staging dir $staging (a prior capture never completed; rebuilding fresh)";
        }

        if (is_dir($backup)) {
            if (!is_dir($stateDir)) {
                self::restore_backup($stateDir, $backup);
                $log[] = "RECOVERED: $stateDir was missing (a prior capture crashed mid-publish) — restored the last known-good tree from $backup";
            } else {
                self::rrmdir($backup);
                $log[] = "recovered: removed stale backup dir $backup (a prior publish already completed; only its own cleanup step didn't run)";
            }
        }

        return $log;
    }

    /**
     * Write every entity into the staging dir and fsync each file (best
     * effort — see fsync_file()'s docblock for exactly what that means and
     * why it can never be a hard requirement). $writeFile is an injectable
     * seam so tests can simulate disk-full deterministically (fail after N
     * writes) without needing a real constrained filesystem; production
     * code always gets the default, which is Canon::write_file() verbatim.
     *
     * @param array<int, array{path: string, content: string}> $entities
     * @param null|callable(string, string): void $writeFile
     */
    public static function write_entities(string $stagingDir, array $entities, ?callable $writeFile = null): void {
        self::assert_not_symlink_root($stagingDir, 'staging');
        $writeFile ??= [Canon::class, 'write_file'];
        if (is_dir($stagingDir)) {
            // Belt only — recover() above already clears this before a
            // fresh build starts; a survivor here would mean something
            // wrote to this path mid-run, which the capture lock should
            // make impossible.
            self::rrmdir($stagingDir);
        }
        // Create the staging dir even with zero entities (a legitimately
        // empty capture — every current build() always emits at least an
        // options entity, but nothing here should silently depend on that
        // never changing): swap() below requires is_dir($staging) to be
        // true, and a foreach over an empty $entities array would never
        // call the writer at all, so nothing would create it otherwise.
        if (!is_dir($stagingDir) && !mkdir($stagingDir, 0777, true) && !is_dir($stagingDir)) {
            throw new \RuntimeException("duo: cannot create staging directory $stagingDir");
        }
        foreach ($entities as $e) {
            $path = $stagingDir . '/' . $e['path'];
            $writeFile($path, $e['content']);
            self::fsync_file($path);
        }
        self::fsync_dir($stagingDir);
    }

    /**
     * Strict first-publication writer. Unlike write_entities(), this never
     * recovers or removes an existing staging path and every child is created
     * with create-if-absent semantics. The returned manifest binds every
     * directory/file inode plus file bytes; stable changes are preserved and
     * refused. Cleanup still relies on the proposal's repository-wide
     * external-writer exclusion, as required by the v0 filesystem contract.
     *
     * @param array<int, array{path:string,content:string}> $entities
     * @return array{root:array{dev:string,ino:string},entries:list<array<string,mixed>>}
     */
    public static function write_entities_fresh(string $stagingDir, array $entities): array {
        self::assert_protocol_roots(str_replace('.capture-staging', '', $stagingDir));
        if (file_exists($stagingDir) || is_link($stagingDir)) {
            throw new InitialStateBoundaryException('duo: initial capture staging boundary was not absent');
        }
        $stagingParent = dirname($stagingDir);
        // One inode-bound helper for the whole entity walk (DUO-3425): the same
        // per-op guarantee as run_bound_operation, one subprocess instead of
        // one per staged file. The finally guarantees a mid-walk throw can never
        // leak a live child.
        $helper = new BoundHelper();
        try {
            $createdDirs = [
                '' => self::create_directory_fresh(
                    $stagingParent,
                    self::path_identity($stagingParent, 'directory'),
                    basename($stagingDir),
                    0777,
                    'capture staging root',
                    $helper
                ),
            ];
            $createdFiles = [];
            $createdFileCount = 0;
            try {
                foreach ($entities as $entity) {
                    self::assert_path_identity($stagingDir, $createdDirs[''], 'directory');
                    $relative = (string) ($entity['path'] ?? '');
                    self::assert_relative_entity_path($relative);
                    $parts = explode('/', $relative);
                    $fileName = array_pop($parts);
                    $parent = $stagingDir;
                    $prefix = '';
                    foreach ($parts as $part) {
                        $parentKey = $prefix;
                        self::assert_path_identity($parent, $createdDirs[$parentKey], 'directory');
                        $prefix = $prefix === '' ? $part : $prefix . '/' . $part;
                        if (!isset($createdDirs[$prefix])) {
                            $createdDirs[$prefix] = self::create_directory_fresh(
                                $parent,
                                $createdDirs[$parentKey],
                                $part,
                                0777,
                                'capture staging parent',
                                $helper
                            );
                        } else {
                            self::assert_path_identity($parent . '/' . $part, $createdDirs[$prefix], 'directory');
                        }
                        $parent .= '/' . $part;
                    }
                    self::assert_path_identity($stagingDir, $createdDirs[''], 'directory');
                    self::assert_path_identity($parent, $createdDirs[$prefix], 'directory');
                    $destination = $parent . '/' . $fileName;
                    if (file_exists($destination) || is_link($destination)) {
                        throw new InitialStateBoundaryException(
                            'duo: initial capture staging gained an unowned entity path'
                        );
                    }
                    $createdFiles[$relative] = self::write_file_fresh(
                        $destination,
                        (string) ($entity['content'] ?? ''),
                        'capture entity',
                        $createdDirs[$prefix],
                        $helper
                    );
                    $createdFileCount++;
                    if ($createdFileCount === 1) {
                        self::fault_checkpoint('initial-staging-partial');
                    }
                }
                self::fsync_dir($stagingDir);
                $manifest = self::tree_ownership_manifest($stagingDir);
                self::assert_created_entries($manifest, $createdDirs, $createdFiles);
                return $manifest;
            } catch (\Throwable $failure) {
                try {
                    $manifest = self::tree_ownership_manifest($stagingDir);
                    self::assert_created_entries($manifest, $createdDirs, $createdFiles);
                    self::remove_owned_tree($stagingDir, $manifest, 'initial capture staging');
                } catch (\Throwable $cleanupFailure) {
                    throw new InitialStateBoundaryException(
                        'duo: initial capture refused and retained changed staging evidence: ' . $failure->getMessage(),
                        0,
                        $failure
                    );
                }
                throw $failure;
            }
        } finally {
            $helper->close();
        }
    }

    /** @return array{root:array{dev:string,ino:string},entries:list<array<string,mixed>>} */
    public static function tree_ownership_manifest(string $dir): array {
        return DurableFilesystem::ownershipManifest($dir);
    }

    /**
     * DUO-3427: compared through the canonical encoding, not PHP's `!==`.
     *
     * Every manifest that reaches a RECOVERY authority has made a round trip
     * through the sealed init journal, and Canon::encode() ksorts object keys
     * on the way out — so a journaled entry comes back as
     * {dev, ino, path, sha256, type} while tree_ownership_manifest() builds
     * {type, dev, ino, sha256, path} in insertion order. PHP's `===` on arrays
     * requires the same key ORDER as well as the same pairs, so this predicate
     * answered "changed after Duo created it" for two manifests that describe
     * the same bytes on the same inodes, and it answered it for EVERY
     * fresh-process rollback: the whole strict first-publication recovery path
     * could only ever refuse. A proven, complete rollback then surfaced as
     * "init refused at an unclassified safety gate" with a retained journal
     * and lock, because InitConfirmation::run()'s compensation could not prove
     * an artifact that had never changed.
     *
     * Both siblings already compared canonically before this — the proposal-
     * time gate (InitRecovery::interrupted_attempt_manual_recovery_reason()'s
     * $manifestMismatch) and the file-level twin (remove_owned_file_initial()
     * below) — so this was the same-commit asymmetry family as DUO-3421's
     * git_empty_identity drift: the read-only gate advertised a confirmable
     * recovery that this authority would then refuse mid-protocol. One
     * comparison now, in all three places. Ordering is the ONLY thing this
     * loses: Canon::encode is injective over these manifests (a list of
     * fixed-key string maps, list order pinned by usort on `path`), so an
     * added, removed, retyped, re-inoded, or rewritten entry still refuses
     * exactly as before.
     *
     * @param array{root:array<string,string>,entries:list<array<string,mixed>>} $manifest
     */
    public static function assert_owned_tree(string $path, array $manifest, string $label): void {
        DurableFilesystem::assertOwned($path, $manifest, $label);
    }

    /** @param array{root:array<string,string>,entries:list<array<string,mixed>>} $manifest */
    public static function remove_owned_tree(string $path, array $manifest, string $label): void {
        DurableFilesystem::removeOwned($path, $manifest, $label);
    }

    /** @return array{type:string,dev:string,ino:string,sha256:string} */
    public static function write_file_fresh(
        string $path,
        string $bytes,
        string $label,
        ?array $expectedParent = null,
        ?BoundHelper $helper = null
    ): array {
        $parent = dirname($path);
        if (is_link($parent) || !is_dir($parent) || file_exists($path) || is_link($path)) {
            throw new InitialStateBoundaryException("duo: initial $label boundary was not absent");
        }
        $expectedParent ??= self::path_identity($parent, 'directory');
        $request = [
            'op' => 'write',
            'name' => basename($path),
            'mode' => 0666,
            'parent' => $expectedParent,
        ];
        $identity = $helper !== null
            ? $helper->run($parent, $request, $bytes, $label)
            : self::run_bound_operation($parent, $request, $bytes, $label);
        if (self::path_identity($path, 'file') !== $identity) {
            throw new InitialStateBoundaryException("duo: initial $label changed immediately after its bound write");
        }
        self::fsync_dir($parent);
        return $identity;
    }

    /** @return array{type:string,dev:string,ino:string} */
    public static function directory_ownership_identity(string $path): array {
        return DurableFilesystem::directoryIdentity($path);
    }

    /**
     * @param array{type:string,dev:string,ino:string} $expectedParent
     * @return array{type:string,dev:string,ino:string}
     */
    public static function create_directory_fresh(
        string $parent,
        array $expectedParent,
        string $name,
        int $mode,
        string $label,
        ?BoundHelper $helper = null
    ): array {
        if (file_exists($parent . '/' . $name) || is_link($parent . '/' . $name)) {
            throw new InitialStateBoundaryException("duo: initial $label boundary was not absent");
        }
        $request = [
            'op' => 'mkdir',
            'name' => $name,
            'mode' => $mode,
            'parent' => $expectedParent,
        ];
        $identity = $helper !== null
            ? $helper->run($parent, $request, '', $label)
            : self::run_bound_operation($parent, $request, '', $label);
        if (self::path_identity($parent . '/' . $name, 'directory') !== $identity) {
            throw new InitialStateBoundaryException("duo: initial $label changed immediately after its bound mkdir");
        }
        return $identity;
    }

    /**
     * @param array{type:string,dev:string,ino:string} $expectedParent
     * @return array{type:string,dev:string,ino:string,sha256:string}
     */
    public static function copy_file_fresh(
        string $source,
        string $path,
        string $expectedSha256,
        int $mode,
        string $label,
        array $expectedParent,
        ?BoundHelper $helper = null
    ): array {
        $parent = dirname($path);
        if (file_exists($path) || is_link($path)) {
            throw new InitialStateBoundaryException("duo: initial $label boundary was not absent");
        }
        $request = [
            'op' => 'copy',
            'name' => basename($path),
            'mode' => $mode,
            'parent' => $expectedParent,
            'source' => $source,
            'sha256' => $expectedSha256,
        ];
        $identity = $helper !== null
            ? $helper->run($parent, $request, '', $label)
            : self::run_bound_operation($parent, $request, '', $label);
        if (self::path_identity($path, 'file') !== $identity) {
            throw new InitialStateBoundaryException("duo: initial $label changed immediately after its bound copy");
        }
        return $identity;
    }

    /** @param array{type:string,dev:string,ino:string,sha256:string} $identity */
    public static function remove_owned_file(string $path, array $identity, string $label): void {
        if (self::path_identity($path, 'file') !== $identity) {
            throw new InitialStateBoundaryException("duo: changed $label was preserved during compensation");
        }
        $claim = dirname($path) . '/.' . basename($path) . '.duo-claim-' . bin2hex(random_bytes(8));
        if (!@rename($path, $claim)) {
            throw new InitialStateBoundaryException("duo: could not claim $label during compensation");
        }
        if (self::path_identity($claim, 'file') !== $identity) {
            if (!file_exists($path) && !is_link($path)) {
                @link($claim, $path);
            }
            throw new InitialStateBoundaryException("duo: raced $label was retained during compensation");
        }
        @unlink($claim);
    }

    /** @return array{type:string,dev:string,ino:string,sha256:string} */
    public static function file_ownership_identity(string $path): array {
        return DurableFilesystem::fileIdentity($path);
    }

    public static function sync_parent(string $path): void {
        self::fsync_dir(dirname($path));
    }

    private static function assert_relative_entity_path(string $path): void {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new \RuntimeException('duo: capture entity has an unsafe repository path');
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \RuntimeException('duo: capture entity has an unsafe repository path');
            }
        }
    }

    /**
     * Execute one child mutation with the reviewed parent directory as the
     * helper process' CWD. Once proc_open has entered that directory, `.` is
     * an inode-bound capability: replacing the lexical parent path can make
     * the identity check fail, but cannot redirect the relative mkdir/open.
     * Fresh files use fopen(x+b) and are compared with the canonical name
     * before the helper exits. The repository-wide external-writer exclusion
     * remains authoritative for the chmod/name window (PHP has no fchmod).
     *
     * @param array<string,mixed> $request
     * @return array<string,string>
     */
    private static function run_bound_operation(
        string $parent,
        array $request,
        string $bytes,
        string $label
    ): array {
        if (!function_exists('proc_open') || !defined('PHP_BINARY') || PHP_BINARY === '') {
            throw new InitialStateBoundaryException("duo: initial $label requires the bound-filesystem helper");
        }
        $script = <<<'PHP'
$fail = static function (string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(73);
};
$line = fgets(STDIN);
$request = is_string($line) ? json_decode($line, true) : null;
if (!is_array($request)) $fail('invalid request');
$name = $request['name'] ?? null;
$parent = $request['parent'] ?? null;
$op = $request['op'] ?? null;
$mode = $request['mode'] ?? null;
if (!is_string($name) || $name === '' || $name === '.' || $name === '..'
    || str_contains($name, '/') || str_contains($name, "\0")
    || !is_array($parent) || !is_int($mode)) {
    $fail('unsafe request');
}
$cwd = @lstat('.');
if (!is_array($cwd) || !is_dir('.')
    || (string) ($cwd['dev'] ?? '') !== (string) ($parent['dev'] ?? '')
    || (string) ($cwd['ino'] ?? '') !== (string) ($parent['ino'] ?? '')) {
    $fail('parent identity changed');
}
if ($op === 'mkdir') {
    if (file_exists($name) || is_link($name) || !@mkdir($name, $mode & 0777)) {
        $fail('fresh directory boundary changed');
    }
    $stat = @lstat($name);
    if (!is_array($stat) || is_link($name) || !is_dir($name)) {
        $fail('fresh directory changed after creation');
    }
    fwrite(STDOUT, json_encode([
        'type' => 'directory', 'dev' => (string) $stat['dev'], 'ino' => (string) $stat['ino'],
    ], JSON_UNESCAPED_SLASHES));
    exit(0);
}
if ($op !== 'write' && $op !== 'copy') $fail('unsupported operation');
$input = STDIN;
if ($op === 'copy') {
    $source = $request['source'] ?? null;
    if (!is_string($source) || $source === '' || is_link($source) || !is_file($source)) {
        $fail('copy source changed');
    }
    $input = @fopen($source, 'rb');
    if (!is_resource($input)) $fail('copy source unreadable');
}
$output = @fopen($name, 'x+b');
if (!is_resource($output)) {
    if ($op === 'copy' && is_resource($input)) fclose($input);
    $fail('fresh file boundary changed');
}
$hash = hash_init('sha256');
$ok = true;
while (!feof($input)) {
    $chunk = fread($input, 65536);
    if (!is_string($chunk)) { $ok = false; break; }
    if ($chunk === '') continue;
    hash_update($hash, $chunk);
    $offset = 0;
    $length = strlen($chunk);
    while ($offset < $length) {
        $written = fwrite($output, substr($chunk, $offset));
        if (!is_int($written) || $written < 1) { $ok = false; break 2; }
        $offset += $written;
    }
}
if ($op === 'copy' && is_resource($input)) fclose($input);
if (!$ok || !fflush($output) || !@chmod($name, $mode & 0777)) {
    $opened = @fstat($output);
    $named = @lstat($name);
    $same = is_array($opened) && is_array($named) && !is_link($name) && is_file($name)
        && (string) ($opened['dev'] ?? '') === (string) ($named['dev'] ?? '')
        && (string) ($opened['ino'] ?? '') === (string) ($named['ino'] ?? '');
    fclose($output);
    if ($same) @unlink($name);
    $fail('fresh file write failed');
}
if (function_exists('fsync')) @fsync($output);
$opened = fstat($output);
$digest = hash_final($hash);
$named = @lstat($name);
$same = is_array($opened) && is_array($named) && !is_link($name) && is_file($name)
    && (string) ($opened['dev'] ?? '') === (string) ($named['dev'] ?? '')
    && (string) ($opened['ino'] ?? '') === (string) ($named['ino'] ?? '');
if (!$same) {
    fclose($output);
    $fail('fresh file name changed after creation');
}
if ($op === 'copy' && (!is_string($request['sha256'] ?? null)
    || !hash_equals($request['sha256'], $digest))) {
    fclose($output);
    $current = @lstat($name);
    $stillSame = is_array($current) && !is_link($name) && is_file($name)
        && (string) ($opened['dev'] ?? '') === (string) ($current['dev'] ?? '')
        && (string) ($opened['ino'] ?? '') === (string) ($current['ino'] ?? '');
    if ($stillSame) @unlink($name);
    $fail('copy source digest changed');
}
fclose($output);
fwrite(STDOUT, json_encode([
    'type' => 'file', 'dev' => (string) $named['dev'], 'ino' => (string) $named['ino'],
    'sha256' => $digest,
], JSON_UNESCAPED_SLASHES));
PHP;
        $pipes = [];
        $process = @proc_open(
            [PHP_BINARY, '-r', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $parent
        );
        if (!is_resource($process)) {
            throw new InitialStateBoundaryException("duo: initial $label could not start its bound-filesystem helper");
        }
        $header = json_encode($request, JSON_UNESCAPED_SLASHES);
        if (!is_string($header)) {
            @proc_terminate($process);
            throw new InitialStateBoundaryException("duo: initial $label could not encode its bound-filesystem request");
        }
        $remaining = $header . "\n" . $bytes;
        while ($remaining !== '') {
            $written = @fwrite($pipes[0], $remaining);
            if (!is_int($written) || $written < 1) {
                break;
            }
            $remaining = (string) substr($remaining, $written);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $identity = is_string($stdout) ? json_decode($stdout, true) : null;
        if ($remaining !== '' || $exit !== 0 || !is_array($identity)) {
            $reason = trim(is_string($stderr) ? $stderr : '');
            throw new InitialStateBoundaryException(
                "duo: initial $label refused at its inode-bound parent"
                    . ($reason === '' ? '' : ': ' . $reason)
            );
        }
        return array_map('strval', $identity);
    }

    /** @return array{type:string,dev:string,ino:string,sha256?:string} */
    private static function path_identity(string $path, string $type): array {
        return DurableFilesystem::identity($path, $type);
    }

    /** @param array<string,string> $expected */
    private static function assert_path_identity(string $path, array $expected, string $type): void {
        if (self::path_identity($path, $type) !== $expected) {
            throw new InitialStateBoundaryException('duo: initial publication parent path changed identity');
        }
    }

    /**
     * @param array{root:array<string,string>,entries:list<array<string,mixed>>} $manifest
     * @param array<string,array<string,string>> $dirs
     * @param array<string,array<string,string>> $files
     */
    private static function assert_created_entries(array $manifest, array $dirs, array $files): void {
        $expected = [];
        foreach ($dirs as $path => $identity) {
            if ($path !== '') {
                $expected[$path] = $identity;
            }
        }
        foreach ($files as $path => $identity) {
            $expected[$path] = $identity;
        }
        ksort($expected, SORT_STRING);
        $actual = [];
        foreach ($manifest['entries'] as $row) {
            $path = (string) $row['path'];
            unset($row['path']);
            $actual[$path] = $row;
        }
        ksort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw new InitialStateBoundaryException(
                'duo: initial publication tree contains content not created by this attempt'
            );
        }
    }

    /**
     * The atomic two-step swap described in the class docblock. Throws
     * loudly and leaves the filesystem in a recoverable state on any
     * failure — never silently continues past a rename that didn't happen.
     */
    public static function swap(string $stateDir, bool $retainBackup = false): void {
        self::assert_protocol_roots($stateDir);
        $staging = self::stage_dir($stateDir);
        $backup = self::backup_dir($stateDir);

        if (!is_dir($staging)) {
            throw new \RuntimeException("duo: swap() called with no staged candidate at $staging");
        }
        if (is_dir($backup)) {
            // recover() always clears this before a build starts, and
            // nothing else writes to it during a run — a survivor here is
            // an invariant violation (two publishers to the same
            // destination, which the capture lock exists specifically to
            // prevent), not an ordinary crash-recovery case.
            throw new \RuntimeException(
                "duo: refusing to swap — unexpected pre-existing $backup. This should be impossible while holding "
                . 'the capture lock; if you see this, something bypassed Publish::lock().'
            );
        }

        self::fault_checkpoint('pre-swap');

        $hadPrevious = is_dir($stateDir);
        if ($hadPrevious && !@rename($stateDir, $backup)) {
            throw new \RuntimeException(
                "duo: atomic swap failed at step 1 (rename $stateDir -> $backup) — the previous tree is untouched "
                . "at $stateDir; the new candidate is left at $staging for inspection or re-run."
            );
        }

        self::fault_checkpoint('after-backup-rename');

        if (!@rename($staging, $stateDir)) {
            // Best-effort self-heal: put the previous tree straight back
            // rather than leave $stateDir observably missing any longer
            // than this function's own execution.
            if ($hadPrevious && !is_dir($stateDir) && is_dir($backup)) {
                @rename($backup, $stateDir);
            }
            throw new \RuntimeException(
                "duo: atomic swap failed at step 2 (rename $staging -> $stateDir) — attempted to restore the "
                . "previous tree automatically; the built candidate is left at $staging for inspection. Re-run "
                . 'capture (recover() will reconcile whatever this left behind).'
            );
        }

        self::fsync_dir(dirname($stateDir));
        self::fault_checkpoint('after-state-rename');
        if (!$retainBackup && is_dir($backup)) {
            self::rrmdir($backup);
        }
    }

    /**
     * First-publication swap over two exact owned manifests. A changed state
     * reservation is restored to its canonical name rather than being treated
     * as historical state or recursively removed.
     *
     * @param array{root:array<string,string>,entries:list<array<string,mixed>>} $stateManifest
     * @param array{root:array<string,string>,entries:list<array<string,mixed>>} $stagingManifest
     */
    public static function swap_initial(
        string $stateDir,
        array $stateManifest,
        array $stagingManifest
    ): void {
        self::assert_protocol_roots($stateDir);
        $staging = self::stage_dir($stateDir);
        $backup = self::backup_dir($stateDir);
        self::assert_owned_tree($stateDir, $stateManifest, 'initial state reservation');
        self::assert_owned_tree($staging, $stagingManifest, 'initial capture staging');
        if (file_exists($backup) || is_link($backup)) {
            throw new InitialStateBoundaryException('duo: initial capture backup boundary was not absent');
        }
        self::fault_checkpoint('pre-swap');
        if (!@rename($stateDir, $backup)) {
            throw new InitialStateBoundaryException('duo: initial state reservation could not be claimed for publication');
        }
        try {
            self::assert_owned_tree($backup, $stateManifest, 'initial state reservation');
        } catch (\Throwable $failure) {
            if (!file_exists($stateDir) && !is_link($stateDir)) {
                @rename($backup, $stateDir);
            }
            throw $failure;
        }
        self::fault_checkpoint('after-backup-rename');
        if (file_exists($stateDir) || is_link($stateDir) || !@rename($staging, $stateDir)) {
            if (!file_exists($stateDir) && !is_link($stateDir)) {
                @rename($backup, $stateDir);
            }
            throw new InitialStateBoundaryException(
                'duo: initial published-state boundary changed during the atomic swap'
            );
        }
        self::assert_owned_tree($stateDir, $stagingManifest, 'initial published state');
        self::assert_owned_tree($backup, $stateManifest, 'initial state reservation');
        self::fsync_dir(dirname($stateDir));
        self::fault_checkpoint('after-state-rename');
    }

    /**
     * Start a durable capture publication record. Call this only after the
     * complete staged candidate has passed all read/validation gates and
     * while the capture transaction is still open. The returned record is
     * plain data so Capture can carry it across COMMIT without reopening or
     * guessing at mutable state.
     *
     * @return array{format:string,id:string,phase:string,candidate_sha256:string,previous_sha256:string,created_at:string}
     */
    public static function begin_intent(string $stateDir, string $candidateDir, bool $createOnly = false): array {
        self::assert_protocol_roots($stateDir);
        self::assert_not_symlink_root($candidateDir, 'capture candidate');
        if (!is_dir($candidateDir)) {
            throw new \RuntimeException("duo: cannot create capture intent — staged candidate is missing: $candidateDir");
        }
        $candidate = self::tree_digest($candidateDir);
        $previous = is_dir($stateDir) ? self::tree_digest($stateDir) : self::empty_tree_digest();
        $intent = [
            'format' => 'duo-capture-intent/v1',
            'id' => bin2hex(random_bytes(16)),
            'phase' => 'prepared',
            'candidate_sha256' => $candidate,
            'previous_sha256' => $previous,
            'created_at' => gmdate('c'),
        ];
        $intent = self::write_record(self::intent_path($stateDir), $intent, 'intent', $createOnly);
        self::fault_checkpoint('intent-written');
        return $intent;
    }

    /** Mark the intent after both filesystem renames have returned. */
    public static function mark_swapped(string $stateDir, array $intent, bool $initExclusion = false): array {
        self::assert_protocol_roots($stateDir);
        self::assert_record($intent, 'intent', 'duo-capture-intent/v1');
        $onDisk = self::read_record(self::intent_path($stateDir), 'intent');
        if ($onDisk === null || ($onDisk['id'] ?? null) !== ($intent['id'] ?? null)) {
            throw self::ambiguous_recovery('capture swap completed but its durable intent is missing or changed');
        }
        $intent['phase'] = 'swapped';
        $intent = self::write_record(
            self::intent_path($stateDir),
            $intent,
            'intent',
            false,
            $onDisk,
            $initExclusion
        );
        self::fault_checkpoint('intent-swapped');
        return $intent;
    }

    /**
     * Mark that all callback work finished and COMMIT is the next operation.
     * This extra durable state distinguishes a crash before the DB commit was
     * attempted (safe to restore the retained backup) from a crash while the
     * client/server commit outcome is unknowable (must remain fail-closed).
     */
    public static function mark_commit_ready(string $stateDir, array $intent, bool $initExclusion = false): array {
        self::assert_protocol_roots($stateDir);
        self::assert_record($intent, 'intent', 'duo-capture-intent/v1');
        $onDisk = self::read_record(self::intent_path($stateDir), 'intent');
        if ($onDisk === null || ($onDisk['id'] ?? null) !== ($intent['id'] ?? null)) {
            throw self::ambiguous_recovery('capture cannot mark COMMIT-ready because its durable intent is missing or changed');
        }
        $intent['phase'] = 'ready';
        $intent = self::write_record(
            self::intent_path($stateDir),
            $intent,
            'intent',
            false,
            $onDisk,
            $initExclusion
        );
        self::fault_checkpoint('intent-ready');
        return $intent;
    }

    /** Mark the exact point immediately before issuing DB COMMIT. */
    public static function mark_committing(string $stateDir, array $intent, bool $initExclusion = false): array {
        self::assert_protocol_roots($stateDir);
        self::assert_record($intent, 'intent', 'duo-capture-intent/v1');
        $onDisk = self::read_record(self::intent_path($stateDir), 'intent');
        if ($onDisk === null || ($onDisk['id'] ?? null) !== ($intent['id'] ?? null)) {
            throw self::ambiguous_recovery('capture cannot mark COMMIT-attempted because its durable intent is missing or changed');
        }
        // An injected pre-commit failure must leave `ready` on disk so the
        // next recovery can restore the old tree. A real crash after the
        // marker write is represented by `committing` and is fail-closed.
        self::fault_checkpoint('commit-attempt');
        $intent['phase'] = 'committing';
        $intent = self::write_record(
            self::intent_path($stateDir),
            $intent,
            'intent',
            false,
            $onDisk,
            $initExclusion
        );
        self::fault_checkpoint('after-commit-marker');
        return $intent;
    }

    /**
     * Persist the post-COMMIT receipt. A receipt is retained as audit data;
     * cleanup of the old tree and intent is a separate idempotent operation.
     *
     * @return array{format:string,intent_id:string,phase:string,candidate_sha256:string,previous_sha256:string,committed_at:string}
     */
    public static function write_receipt(string $stateDir, array $intent, bool $createOnly = false): array {
        self::assert_protocol_roots($stateDir);
        self::assert_record($intent, 'intent', 'duo-capture-intent/v1');
        if (!is_dir($stateDir)) {
            throw self::ambiguous_recovery('capture cannot write its receipt because state/ is missing after COMMIT');
        }
        // The transaction wrapper advances the on-disk intent to
        // `committing` immediately before COMMIT. Re-read it so the receipt
        // binds that exact record even though Capture carried the pre-commit
        // PHP array across the wrapper boundary.
        $onDisk = self::read_record(self::intent_path($stateDir), 'intent');
        if ($onDisk === null || ($onDisk['id'] ?? null) !== ($intent['id'] ?? null)) {
            throw self::ambiguous_recovery('capture cannot write its receipt because its durable intent is missing or changed');
        }
        if (($onDisk['phase'] ?? null) !== 'committing') {
            throw self::ambiguous_recovery('capture cannot write its receipt before the durable COMMIT-attempt marker');
        }
        $intent = $onDisk;
        $actual = self::tree_digest($stateDir);
        if (!hash_equals((string) $intent['candidate_sha256'], $actual)) {
            throw self::ambiguous_recovery('published state/ does not match the candidate named by the capture receipt');
        }
        $receipt = [
            'format' => 'duo-capture-receipt/v1',
            'intent_id' => (string) $intent['id'],
            'phase' => 'committed',
            'candidate_sha256' => (string) $intent['candidate_sha256'],
            'previous_sha256' => (string) $intent['previous_sha256'],
            'committed_at' => gmdate('c'),
        ];
        $receiptPath = self::receipt_path($stateDir);
        $priorReceipt = $createOnly ? null : self::read_record($receiptPath, 'receipt');
        $receipt = self::write_record(
            $receiptPath,
            $receipt,
            'receipt',
            $createOnly,
            $priorReceipt,
            $createOnly
        );
        self::fault_checkpoint('receipt-written');
        return $receipt;
    }

    /**
     * Remove post-commit artifacts only after a receipt has been durably
     * written. Failure is intentionally propagated: the receipt remains and
     * the next lock holder can retry this cleanup without replaying capture.
     */
    public static function cleanup_committed(string $stateDir, array $receipt): void {
        self::assert_protocol_roots($stateDir);
        self::assert_record($receipt, 'receipt', 'duo-capture-receipt/v1');
        if (!is_dir($stateDir)) {
            throw self::ambiguous_recovery('post-commit cleanup found published state/ missing');
        }
        if (!hash_equals((string) $receipt['candidate_sha256'], self::tree_digest($stateDir))) {
            throw self::ambiguous_recovery('post-commit cleanup found state/ no longer matches its receipt');
        }
        $backup = self::backup_dir($stateDir);
        $staging = self::stage_dir($stateDir);
        $intent = self::intent_path($stateDir);
        $hadRetainedArtifacts = is_dir($backup) || is_dir($staging);
        $onDisk = self::read_record($intent, 'intent');
        if ($hadRetainedArtifacts && $onDisk === null) {
            throw self::ambiguous_recovery('post-commit cleanup found retained artifacts with no matching intent');
        }
        if ($onDisk !== null) {
            if (($onDisk['id'] ?? null) !== ($receipt['intent_id'] ?? null)) {
                throw self::ambiguous_recovery('post-commit cleanup found an intent id that differs from its receipt');
            }
        }
        if (is_dir($backup)) {
            self::assert_tree_digest($backup, (string) $receipt['previous_sha256'], 'post-commit backup');
        }
        if (is_dir($staging)) {
            self::assert_tree_digest(
                $staging,
                (string) $receipt['candidate_sha256'],
                'post-commit staging'
            );
        }
        self::fault_checkpoint('post-commit-cleanup');
        if (is_dir($backup)) {
            self::rrmdir($backup);
        }
        if (is_dir($staging)) {
            self::rrmdir($staging);
        }
        if ($onDisk !== null) {
            self::remove_record($intent, 'intent', $onDisk);
        }
        self::fsync_dir(dirname($stateDir));
    }

    /**
     * Strict first-publication cleanup deletes only the exact empty state
     * reservation and final intent inode owned by this attempt. Any changed
     * artifact is retained for fail-closed recovery.
     *
     * @param array{root:array<string,string>,entries:list<array<string,mixed>>} $backupManifest
     * @param array{root:array<string,string>,entries:list<array<string,mixed>>} $candidateManifest
     * @param array{type:string,dev:string,ino:string,sha256:string} $intentIdentity
     */
    public static function cleanup_committed_initial(
        string $stateDir,
        array $receipt,
        array $backupManifest,
        array $candidateManifest,
        array $intentIdentity
    ): void {
        self::assert_protocol_roots($stateDir);
        self::assert_record($receipt, 'receipt', 'duo-capture-receipt/v1');
        if (!is_dir($stateDir)) {
            throw self::ambiguous_recovery('initial committed state no longer matches its receipt');
        }
        self::assert_owned_tree($stateDir, $candidateManifest, 'initial committed state');
        if (!hash_equals((string) $receipt['candidate_sha256'], self::tree_digest($stateDir))) {
            throw self::ambiguous_recovery('initial committed state no longer matches its receipt');
        }
        $backup = self::backup_dir($stateDir);
        $intent = self::intent_path($stateDir);
        if (is_dir(self::stage_dir($stateDir)) || is_link(self::stage_dir($stateDir))) {
            throw self::ambiguous_recovery('initial committed capture retained an unexpected staging boundary');
        }
        self::assert_owned_tree($backup, $backupManifest, 'initial retained state reservation');
        $onDisk = self::read_record($intent, 'intent');
        if ($onDisk === null || ($onDisk['id'] ?? null) !== ($receipt['intent_id'] ?? null)) {
            throw self::ambiguous_recovery('initial committed capture intent changed before cleanup');
        }
        self::fault_checkpoint('post-commit-cleanup');
        self::remove_owned_tree_initial($backup, $backupManifest, 'initial retained state reservation');
        self::remove_owned_file_initial($intent, $intentIdentity, 'initial capture intent');
        self::fsync_dir(dirname($stateDir));
    }

    /**
     * Strict first-publication cleanup has no durable claim journal. Under
     * the documented init-wide non-Duo-writer exclusion, remove the exact
     * already-verified object in place so a SIGKILL cannot strand a hidden
     * claim that a later init would mistake for a clean completion.
     *
     * @param array{root:array<string,string>,entries:list<array<string,mixed>>} $manifest
     */
    public static function remove_owned_tree_initial(string $path, array $manifest, string $label): void {
        self::assert_owned_tree($path, $manifest, $label);
        self::rrmdir($path);
        if (file_exists($path) || is_link($path)) {
            throw self::ambiguous_recovery("$label could not be removed completely");
        }
        self::fsync_dir(dirname($path));
    }

    /** @param array{type:string,dev:string,ino:string,sha256:string} $identity */
    public static function remove_owned_file_initial(string $path, array $identity, string $label): void {
        if (is_link($path) || !is_file($path)
            || Canon::encode(self::path_identity($path, 'file')) !== Canon::encode($identity)) {
            throw self::ambiguous_recovery("$label changed before strict removal");
        }
        if (!@unlink($path)) {
            throw self::ambiguous_recovery("$label could not be removed");
        }
        if (file_exists($path) || is_link($path)) {
            throw self::ambiguous_recovery("$label remained after strict removal");
        }
        self::fsync_dir(dirname($path));
    }

    /** Deterministic digest of every regular file beneath a tree. */
    public static function tree_digest(string $dir): string {
        return DurableFilesystem::treeDigest($dir);
    }

    private static function empty_tree_digest(): string {
        return hash('sha256', '');
    }

    /** Resolve the fixed previous/next transition slots before record reads. */
    private static function recover_record_transition(string $path, string $label): ?string {
        $previousPath = self::record_previous_path($path);
        $nextPath = self::record_next_path($path);
        $hasPrevious = file_exists($previousPath) || is_link($previousPath);
        $hasNext = file_exists($nextPath) || is_link($nextPath);
        if (!$hasPrevious && !$hasNext) {
            return null;
        }
        $canonical = self::read_record($path, $label);
        $previous = $hasPrevious ? self::read_record($previousPath, $label) : null;
        $next = $hasNext ? self::read_record($nextPath, $label) : null;
        if ($next === null) {
            throw self::ambiguous_recovery("capture $label transition is missing its sealed next record");
        }
        self::remove_matching_record_temps($path, $nextPath, $next, $label);
        if ($previous !== null) {
            if (Canon::encode($previous) === Canon::encode($next)) {
                if ($canonical !== null) {
                    throw self::ambiguous_recovery(
                        "capture $label removal transition retained an unexpected canonical record"
                    );
                }
                self::remove_record_transition_artifact($previousPath, $previous, $label);
                self::remove_record_transition_artifact($nextPath, $next, $label);
                self::fsync_dir(dirname($path));
                return "RECOVERED: completed interrupted capture $label record removal";
            }
            self::assert_record_transition($previous, $next, $label);
            if ($canonical === null) {
                if (!@link($previousPath, $path)) {
                    throw self::ambiguous_recovery("capture $label transition could not restore its prior canonical record");
                }
                $canonical = self::read_record($path, $label);
                if ($canonical === null || Canon::encode($canonical) !== Canon::encode($previous)) {
                    throw self::ambiguous_recovery("capture $label transition restored a mismatched prior record");
                }
                self::fsync_dir(dirname($path));
                self::fault_checkpoint('record-transition-recover-prior');
            }
            if (Canon::encode($canonical) === Canon::encode($previous)) {
                self::remove_record_transition_artifact($previousPath, $previous, $label);
                self::remove_record_transition_artifact($nextPath, $next, $label);
                self::fsync_dir(dirname($path));
                return "RECOVERED: restored the prior capture $label after an interrupted phase transition";
            }
            if (Canon::encode($canonical) !== Canon::encode($next)) {
                throw self::ambiguous_recovery("capture $label transition canonical record matches neither owned phase");
            }
            self::remove_record_transition_artifact($previousPath, $previous, $label);
            self::remove_record_transition_artifact($nextPath, $next, $label);
            self::fsync_dir(dirname($path));
            return "RECOVERED: resolved interrupted capture $label phase transition";
        }
        if ($canonical === null) {
            // A fresh create never made its canonical hard link. No state swap
            // can depend on a prepared intent that was never published; a
            // missing receipt is reconstructed from committing+DB proof.
            self::remove_record_transition_artifact($nextPath, $next, $label);
            self::fsync_dir(dirname($path));
            return "recovered: discarded unpublished capture $label next-transition record";
        }
        if (Canon::encode($canonical) !== Canon::encode($next)) {
            self::assert_record_transition($canonical, $next, $label);
        }
        self::remove_record_transition_artifact($nextPath, $next, $label);
        self::fsync_dir(dirname($path));
        return "recovered: finalized capture $label transition cleanup";
    }

    /**
     * Is this write_record() temporary a hard link to the sealed next-slot it
     * was created for, carrying that exact record?
     *
     * The ONE binding rule, shared by the removal authority below and the
     * read-only classifier beside it (DUO-3427). Fail-closed by construction:
     * an unreadable stat, an unreadable or malformed record, a different
     * inode, or different bytes all answer false, so a temp is only ever
     * called bound on positive evidence.
     *
     * @param array<string,mixed> $record
     */
    private static function record_temp_bound_to_next(
        string $candidatePath,
        string $nextPath,
        array $record,
        string $label
    ): bool {
        clearstatcache(true, $nextPath);
        $anchor = @lstat($nextPath);
        $anchorMode = is_array($anchor) ? (int) ($anchor['mode'] ?? 0) : 0;
        if (!is_array($anchor) || ($anchorMode & 0170000) !== 0100000) {
            return false;
        }
        clearstatcache(true, $candidatePath);
        $candidateStat = @lstat($candidatePath);
        $candidateMode = is_array($candidateStat) ? (int) ($candidateStat['mode'] ?? 0) : 0;
        if (!is_array($candidateStat)
            || ($candidateMode & 0170000) !== 0100000
            || (string) ($candidateStat['dev'] ?? '') !== (string) ($anchor['dev'] ?? '')
            || (string) ($candidateStat['ino'] ?? '') !== (string) ($anchor['ino'] ?? '')) {
            return false;
        }
        try {
            $candidate = self::read_record($candidatePath, $label);
        } catch (\Throwable $unreadable) {
            return false;
        }
        return $candidate !== null && Canon::encode($candidate) === Canon::encode($record);
    }

    /**
     * DUO-3427: the read-only twin of the removal authority below, for the
     * proposal-time gate.
     *
     * `InitRecovery::interrupted_attempt_manual_recovery_reason()` refused EVERY
     * `state.capture-{intent,receipt}.tmp.*` entry by name — the exact
     * "swept by name pattern" the authority's own docblock rejects — while
     * the authority resolves any temp that is a hard link to its sealed next
     * slot. write_record() creates the temp, hard-links it to `.next`, and
     * only then reaches the `record-create-next` fault boundary, so a crash
     * there leaves precisely the bound shape: same inode, same bytes, one
     * unlink away from resolved. The gate sent it to manual archive-and-
     * recreate anyway, and a rollback the engine could perform in full was
     * never offered. Same asymmetry family as DUO-3421's git_empty_identity
     * and this issue's manifest key ordering: the read-only gate and the
     * authority answering one question two ways.
     *
     * A `true` here is a promise the authority keeps: bound implies a `.next`
     * slot exists, which is what puts recover_interrupted_attempt() on a path
     * through recover_initial_unpublished_intent_next() or
     * recover_record_transition() — and both call the removal below.
     */
    public static function record_temp_is_resolvable(string $stateDir, string $entry): bool {
        $directory = dirname($stateDir);
        foreach ([
            'intent' => self::intent_path($stateDir),
            'receipt' => self::receipt_path($stateDir),
        ] as $label => $recordPath) {
            if (!str_starts_with($entry, basename($recordPath) . '.tmp.')) {
                continue;
            }
            $nextPath = self::record_next_path($recordPath);
            if (!file_exists($nextPath) && !is_link($nextPath)) {
                return false;
            }
            try {
                $next = self::read_record($nextPath, $label);
            } catch (\Throwable $unreadable) {
                return false;
            }
            if ($next === null) {
                return false;
            }
            return self::record_temp_bound_to_next($directory . '/' . $entry, $nextPath, $next, $label);
        }
        return false;
    }

    /**
     * Remove only random write_record() temporaries that are hard links to
     * the validated fixed next slot. A temp with another inode or payload is
     * retained as ambiguous evidence rather than swept by name pattern.
     *
     * @param array<string,mixed> $record
     */
    private static function remove_matching_record_temps(
        string $path,
        string $nextPath,
        array $record,
        string $label
    ): void {
        $directory = dirname($path);
        $entries = @scandir($directory);
        if ($entries === false) {
            throw self::ambiguous_recovery("capture $label temporary-record directory cannot be enumerated");
        }
        $prefix = basename($path) . '.tmp.';
        clearstatcache(true, $nextPath);
        $anchor = @lstat($nextPath);
        $anchorMode = is_array($anchor) ? (int) ($anchor['mode'] ?? 0) : 0;
        if (!is_array($anchor) || ($anchorMode & 0170000) !== 0100000) {
            throw self::ambiguous_recovery("capture $label next-transition has no regular-file identity");
        }
        foreach ($entries as $entry) {
            if (!str_starts_with($entry, $prefix)) {
                continue;
            }
            $candidatePath = $directory . '/' . $entry;
            if (!self::record_temp_bound_to_next($candidatePath, $nextPath, $record, $label)) {
                throw self::ambiguous_recovery("capture $label retained an unrelated temporary record artifact");
            }
            if (!@unlink($candidatePath)) {
                throw self::ambiguous_recovery("capture $label temporary record artifact could not be removed");
            }
        }
        self::fsync_dir($directory);
    }

    /** Refuse payload cleanup while an unbound record temp still exists. */
    public static function assert_no_record_temps(string $stateDir): void {
        self::assert_protocol_roots($stateDir);
        $directory = dirname($stateDir);
        $entries = @scandir($directory);
        if ($entries === false) {
            throw self::ambiguous_recovery('capture record temporary directory cannot be enumerated');
        }
        $prefixes = [
            basename(self::intent_path($stateDir)) . '.tmp.',
            basename(self::receipt_path($stateDir)) . '.tmp.',
        ];
        foreach ($entries as $entry) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($entry, $prefix)) {
                    throw self::ambiguous_recovery(
                        "capture recovery retained an unbound temporary record artifact $entry"
                    );
                }
            }
        }
    }

    /** @param array<string,mixed> $expected */
    private static function remove_record_transition_artifact(
        string $path,
        array $expected,
        string $label
    ): void {
        $current = self::read_record($path, $label);
        if ($current === null || Canon::encode($current) !== Canon::encode($expected) || !@unlink($path)) {
            throw self::ambiguous_recovery("capture $label transition artifact changed before cleanup");
        }
    }

    private static function record_previous_path(string $path): string {
        return $path . '.previous';
    }

    private static function record_next_path(string $path): string {
        return $path . '.next';
    }

    /** @param array<string,mixed> $previous @param array<string,mixed> $next */
    private static function assert_record_transition(array $previous, array $next, string $label): void {
        self::assert_record($previous, $label, $label === 'intent' ? 'duo-capture-intent/v1' : 'duo-capture-receipt/v1');
        self::assert_record($next, $label, $label === 'intent' ? 'duo-capture-intent/v1' : 'duo-capture-receipt/v1');
        if ($label === 'receipt') {
            return;
        }
        $order = ['prepared' => 0, 'swapped' => 1, 'ready' => 2, 'committing' => 3];
        $from = $order[(string) ($previous['phase'] ?? '')] ?? null;
        $to = $order[(string) ($next['phase'] ?? '')] ?? null;
        $old = $previous;
        $new = $next;
        unset($old['phase'], $old['record_sha256'], $new['phase'], $new['record_sha256']);
        if (($previous['id'] ?? null) !== ($next['id'] ?? null)
            || !is_int($from) || !is_int($to) || $to !== $from + 1
            || Canon::encode($old) !== Canon::encode($new)) {
            throw self::ambiguous_recovery("capture $label transition does not describe one adjacent sealed phase");
        }
    }

    /** @return ?array<string,mixed> */
    public static function read_record(string $path, string $label): ?array {
        // Docker Desktop's bind mount can hand PHP a stale cached stat for a
        // path write_record() just @link()/@rename()'d into place, classifying
        // that fresh regular file as a non-file. Refresh the stat first so the
        // type check below is a fresh regular-file identity check — the same
        // clearstatcache discipline lock_new()/assert_lock_path() already use
        // before their is_file/inode checks. This only makes the stat fresh: a
        // directory, symlink, malformed, non-object, or unsealed record still
        // refuses exactly as before.
        clearstatcache(true, $path);
        self::assert_not_symlink_root($path, "$label record");
        // Every phase transition above publishes a regular file through a
        // same-filesystem hard link. Docker Desktop bind mounts have been
        // observed returning stale is_file() metadata immediately after the
        // successful link(2): the canonical path and the retained temp were
        // both regular links to one sealed inode, but readback called it a
        // non-file boundary and the finally block retained the temp. Use a
        // fresh lstat rather than PHP's cached path predicate, then bind both
        // validation and bytes to an opened descriptor. The pre-open type
        // check keeps known FIFOs/devices from crossing the fail-closed
        // special-file boundary; under the capture lock's documented
        // external-writer exclusion, the post-open identity check also binds
        // the canonical name to that descriptor through the read.
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before)) {
            return null;
        }
        $beforeMode = (int) ($before['mode'] ?? 0);
        if (($beforeMode & 0170000) !== 0100000) {
            throw self::ambiguous_recovery("capture recovery found a non-file $label record boundary $path");
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("duo: capture recovery cannot read $label record $path");
        }
        try {
            $opened = @fstat($handle);
            clearstatcache(true, $path);
            $named = @lstat($path);
            $openedMode = is_array($opened) ? (int) ($opened['mode'] ?? 0) : 0;
            $namedMode = is_array($named) ? (int) ($named['mode'] ?? 0) : 0;
            if (!is_array($opened) || !is_array($named)
                || ($openedMode & 0170000) !== 0100000
                || ($namedMode & 0170000) !== 0100000
                || (string) ($before['dev'] ?? '') !== (string) ($opened['dev'] ?? '')
                || (string) ($before['ino'] ?? '') !== (string) ($opened['ino'] ?? '')
                || (string) ($opened['dev'] ?? '') !== (string) ($named['dev'] ?? '')
                || (string) ($opened['ino'] ?? '') !== (string) ($named['ino'] ?? '')) {
                throw self::ambiguous_recovery("capture recovery found a non-file or changed $label record boundary $path");
            }
            $bytes = stream_get_contents($handle);
            clearstatcache(true, $path);
            $after = @lstat($path);
            $afterMode = is_array($after) ? (int) ($after['mode'] ?? 0) : 0;
            if (!is_array($after)
                || ($afterMode & 0170000) !== 0100000
                || (string) ($before['dev'] ?? '') !== (string) ($opened['dev'] ?? '')
                || (string) ($before['ino'] ?? '') !== (string) ($opened['ino'] ?? '')
                || (string) ($opened['dev'] ?? '') !== (string) ($after['dev'] ?? '')
                || (string) ($opened['ino'] ?? '') !== (string) ($after['ino'] ?? '')) {
                throw self::ambiguous_recovery("capture recovery found a changed $label record boundary $path");
            }
        } finally {
            fclose($handle);
        }
        if ($bytes === false) {
            throw new \RuntimeException("duo: capture recovery cannot read $label record $path");
        }
        try {
            $record = Canon::decode($bytes);
        } catch (\Throwable $e) {
            throw self::ambiguous_recovery("capture recovery found malformed $label record $path", $e);
        }
        if (!is_array($record)) {
            throw self::ambiguous_recovery("capture recovery found non-object $label record $path");
        }
        self::assert_sealed_record($record, $label);
        return $record;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private static function write_record(
        string $path,
        array $record,
        string $label,
        bool $createOnly = false,
        ?array $expectedExisting = null,
        bool $initExclusion = false
    ): array {
        self::assert_not_symlink_root($path, "$label record");
        $record = self::seal_record($record);
        $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(6));
        try {
            Canon::write_file($tmp, Canon::encode($record));
            self::fsync_file($tmp);
            self::fault_checkpoint('record-create-temp');
            $previousPath = self::record_previous_path($path);
            $nextPath = self::record_next_path($path);
            if ($createOnly || $expectedExisting === null) {
                self::assert_record_slot_absent($previousPath, "$label previous-transition");
                self::assert_record_slot_absent($nextPath, "$label next-transition");
                if (!@link($tmp, $nextPath)) {
                    if ($createOnly) {
                        throw new InitialStateBoundaryException(
                            "duo: initial capture $label next-transition boundary changed before durable publication"
                        );
                    }
                    throw self::ambiguous_recovery(
                        "capture $label next-transition boundary changed before create-if-absent publication"
                    );
                }
                self::fsync_dir(dirname($path));
                self::fault_checkpoint('record-create-next');
                if (!@link($nextPath, $path)) {
                    if ($createOnly) {
                        throw new InitialStateBoundaryException(
                            "duo: initial capture $label boundary changed before durable publication"
                        );
                    }
                    throw self::ambiguous_recovery(
                        "capture $label boundary changed before create-if-absent publication"
                    );
                }
                self::fsync_dir(dirname($path));
                self::remove_record_transition_artifact($nextPath, $record, $label);
            } elseif ($expectedExisting !== null) {
                $current = self::read_record($path, $label);
                if ($current === null || Canon::encode($current) !== Canon::encode($expectedExisting)) {
                    throw self::ambiguous_recovery("capture $label record changed before its transition");
                }
                if ($initExclusion) {
                    // First init's digest-bound operator exclusion permits an
                    // atomic same-filesystem replacement. Ordinary capture
                    // uses the fixed slots below so every interruption is a
                    // finite recovery state; the repository contract keeps
                    // non-Duo writers out of the state.capture* namespace.
                    if (!@rename($tmp, $path)) {
                        throw self::ambiguous_recovery("capture $label record could not publish its next phase");
                    }
                } else {
                    self::assert_record_slot_absent($previousPath, "$label previous-transition");
                    self::assert_record_slot_absent($nextPath, "$label next-transition");
                    if (!@link($tmp, $nextPath)) {
                        throw self::ambiguous_recovery("capture $label could not reserve its sealed next transition");
                    }
                    self::fsync_dir(dirname($path));
                    self::fault_checkpoint('record-transition-next');
                    if (!@rename($path, $previousPath)) {
                        throw self::ambiguous_recovery("capture $label record changed before its transition claim");
                    }
                    self::fsync_dir(dirname($path));
                    self::fault_checkpoint('record-transition-previous');
                    $claimed = self::read_record($previousPath, $label);
                    if ($claimed === null || Canon::encode($claimed) !== Canon::encode($expectedExisting)) {
                        if (!file_exists($path) && !is_link($path)) {
                            @link($previousPath, $path);
                        }
                        throw self::ambiguous_recovery("capture $label record changed during its transition");
                    }
                    self::fsync_dir(dirname($path));
                    if (!@link($nextPath, $path)) {
                        if (!file_exists($path) && !is_link($path)) {
                            @link($previousPath, $path);
                        }
                        throw self::ambiguous_recovery(
                            "capture $label record lost its create-if-absent transition boundary"
                        );
                    }
                    self::fsync_dir(dirname($path));
                    self::fault_checkpoint('record-transition-canonical');
                    self::remove_record_transition_artifact($previousPath, $expectedExisting, $label);
                    self::remove_record_transition_artifact($nextPath, $record, $label);
                }
            }
            self::fsync_dir(dirname($path));
        } finally {
            // The temp is this run's create-if-absent inode. Refresh path
            // metadata before deciding whether it still exists: the same
            // bind-mount cache window that affected canonical readback can
            // otherwise strand an owned hard link beside a valid intent.
            clearstatcache(true, $tmp);
            $tmpStat = @lstat($tmp);
            $tmpMode = is_array($tmpStat) ? (int) ($tmpStat['mode'] ?? 0) : 0;
            if (is_array($tmpStat) && ($tmpMode & 0170000) === 0100000) {
                @unlink($tmp);
            }
        }
        return $record;
    }

    private static function assert_record_slot_absent(string $path, string $label): void {
        if (file_exists($path) || is_link($path)) {
            throw self::ambiguous_recovery("capture $label slot is already present; retained it");
        }
    }

    /** @param array<string,mixed> $record */
    private static function assert_record(array $record, string $label, string $format): void {
        self::assert_sealed_record($record, $label);
        if (($record['format'] ?? null) !== $format) {
            throw self::ambiguous_recovery("capture recovery found unsupported $label record format");
        }
        if ($label === 'intent') {
            if (!is_string($record['id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/', $record['id'])) {
                throw self::ambiguous_recovery('capture recovery found malformed intent id');
            }
            if (!in_array($record['phase'] ?? null, ['prepared', 'swapped', 'ready', 'committing'], true)) {
                throw self::ambiguous_recovery('capture recovery found malformed intent phase');
            }
        } else {
            if (!is_string($record['intent_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/', $record['intent_id'])) {
                throw self::ambiguous_recovery('capture recovery found malformed receipt id');
            }
            if (($record['phase'] ?? null) !== 'committed') {
                throw self::ambiguous_recovery('capture recovery found malformed receipt phase');
            }
        }
        foreach (['candidate_sha256', 'previous_sha256'] as $key) {
            if (!is_string($record[$key] ?? null) || preg_match('/^[a-f0-9]{64}$/', $record[$key]) !== 1) {
                throw self::ambiguous_recovery("capture recovery found malformed $label $key");
            }
        }
    }

    /** Exact marker shape + canonical self-hash, shared by disk reads and
     * in-memory transition values. */
    private static function assert_sealed_record(array $record, string $label): void {
        $expected = $label === 'intent'
            ? ['format', 'id', 'phase', 'candidate_sha256', 'previous_sha256', 'created_at', 'record_sha256']
            : ['format', 'intent_id', 'phase', 'candidate_sha256', 'previous_sha256', 'committed_at', 'record_sha256'];
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        $sortedExpected = $expected;
        sort($sortedExpected, SORT_STRING);
        if ($keys !== $sortedExpected) {
            throw self::ambiguous_recovery("capture recovery found an unexpected $label record shape");
        }
        $seal = $record['record_sha256'] ?? null;
        if (!is_string($seal) || preg_match('/^[a-f0-9]{64}$/', $seal) !== 1) {
            throw self::ambiguous_recovery("capture recovery found an unsealed $label record");
        }
        $payload = $record;
        unset($payload['record_sha256']);
        if (!hash_equals($seal, hash('sha256', Canon::encode($payload)))) {
            throw self::ambiguous_recovery("capture recovery found a tampered $label record");
        }
        foreach (['created_at', 'committed_at'] as $timeKey) {
            if (array_key_exists($timeKey, $record)
                && (!is_string($record[$timeKey]) || trim($record[$timeKey]) === '')) {
                throw self::ambiguous_recovery("capture recovery found a malformed $label timestamp");
            }
        }
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private static function seal_record(array $record): array {
        unset($record['record_sha256']);
        $record['record_sha256'] = hash('sha256', Canon::encode($record));
        return $record;
    }

    /** Refuse destructive recovery when a protocol-owned tree changed. */
    private static function assert_tree_digest(string $dir, string $expected, string $label): void {
        if (!is_dir($dir) || !hash_equals($expected, self::tree_digest($dir))) {
            throw self::ambiguous_recovery("$label does not match its durable publication intent");
        }
    }

    /** A candidate with no retained backup is removable only for capture #1. */
    private static function assert_first_publication(array $intent): void {
        if (!hash_equals(self::empty_tree_digest(), (string) ($intent['previous_sha256'] ?? ''))) {
            throw self::ambiguous_recovery('an existing-state publication lost its retained backup');
        }
    }

    /** @param array<string,mixed> $expected */
    private static function remove_record(string $path, string $label, array $expected): void {
        self::assert_not_symlink_root($path, "$label record");
        $current = self::read_record($path, $label);
        if ($current === null || Canon::encode($current) !== Canon::encode($expected)) {
            throw self::ambiguous_recovery("capture $label record changed before durable removal");
        }
        $previousPath = self::record_previous_path($path);
        $nextPath = self::record_next_path($path);
        self::assert_record_slot_absent($previousPath, "$label previous-removal");
        self::assert_record_slot_absent($nextPath, "$label next-removal");
        if (!@link($path, $nextPath)) {
            throw self::ambiguous_recovery("capture $label record could not journal durable removal");
        }
        self::fsync_dir(dirname($path));
        self::fault_checkpoint('record-removal-next');
        if (!@rename($path, $previousPath)) {
            throw self::ambiguous_recovery("capture $label record changed before durable removal claim");
        }
        self::fsync_dir(dirname($path));
        self::fault_checkpoint('record-removal-previous');
        $claimed = self::read_record($previousPath, $label);
        if ($claimed === null || Canon::encode($claimed) !== Canon::encode($expected)) {
            if (!file_exists($path) && !is_link($path)) {
                @link($previousPath, $path);
            }
            throw self::ambiguous_recovery("capture $label record changed during durable removal");
        }
        self::remove_record_transition_artifact($previousPath, $expected, $label);
        self::fsync_dir(dirname($path));
        self::fault_checkpoint('record-removal-next-only');
        self::remove_record_transition_artifact($nextPath, $expected, $label);
        self::fsync_dir(dirname($path));
    }

    private static function restore_backup(string $stateDir, string $backup): void {
        self::assert_not_symlink_root($stateDir, 'published state');
        self::assert_not_symlink_root($backup, 'retained backup');
        if (!@rename($backup, $stateDir)) {
            throw new \RuntimeException(
                "duo: capture recovery failed — $stateDir is missing and retained backup $backup could not be restored; "
                . 'verify the backup and move it manually before retrying'
            );
        }
        self::fsync_dir(dirname($stateDir));
    }

    /** Replace a complete new state tree with the retained old tree. */
    private static function replace_with_backup(string $stateDir, string $backup): void {
        self::assert_not_symlink_root($stateDir, 'published state');
        self::assert_not_symlink_root($backup, 'retained backup');
        if (is_dir($stateDir)) {
            self::rrmdir($stateDir);
        }
        self::restore_backup($stateDir, $backup);
    }

    private static function ambiguous_recovery(
        string $reason,
        ?\Throwable $previous = null
    ): CommandRefusalException {
        return CommandRefusalException::ambiguousCaptureRecovery(
            'duo: capture recovery is blocked by an ambiguous publication/COMMIT boundary — '
                . $reason . '; refusing to retry or discard the retained backup. Inspect the intent, receipt, and database before continuing.',
            $previous
        );
    }

    /** Test-only fault seam; production is inert unless explicitly enabled. */
    private static function fault_checkpoint(string $phase): void {
        if (getenv('DUO_TEST_MODE') !== '1') {
            return;
        }
        $requested = (string) (getenv('DUO_TEST_PUBLISH_FAIL_PHASE') ?: getenv('DUO_TEST_CAPTURE_FAIL_PHASE'));
        if ($requested !== '' && $requested === $phase) {
            throw new \RuntimeException("duo: injected capture publication failure at $phase");
        }
        $kill = (string) (getenv('DUO_TEST_PUBLISH_KILL_PHASE') ?: getenv('DUO_TEST_CAPTURE_KILL_PHASE'));
        if ($kill === $phase) {
            if (function_exists('posix_kill')) {
                @posix_kill(getmypid(), defined('SIGKILL') ? SIGKILL : 9);
            }
            // A host without ext-posix still gets a deterministic abrupt
            // child exit; flock is released when the process exits.
            exit(137);
        }
    }

    /**
     * PHP added native fsync()/fdatasync() in 8.5 (this repo's shipped
     * sandbox images run PHP 8.3, and WordPress itself still supports much
     * older PHP — function_exists() gates every call here so this degrades
     * silently rather than fataling anywhere older). Where it's missing,
     * nudge the OS via the `sync` utility on a best-effort basis: GNU
     * coreutils' `sync <path>` syncs just that path's filesystem; BSD/
     * macOS `sync` ignores arguments and syncs everything. Either way this
     * is a durability NUDGE layered on top of the atomic-rename guarantee
     * that actually protects the previous tree (swap() above) — never a
     * hard requirement, and its absence must never fail capture: `exec` is
     * routinely disabled on locked-down hosts, and DESIGN.md's "drop-in
     * mu-plugin, no vendored deps" posture rules out an FFI/PECL dependency
     * to get a true guarantee everywhere. Documented honestly rather than
     * overclaimed — see docs/... durability note in the DUO-3213 PR.
     */
    private static function fsync_file(string $path): void {
        DurableFilesystem::syncFile($path);
    }

    /**
     * Durably persisting a rename (a directory-entry change, not file
     * content) requires fsync'ing the DIRECTORY on some filesystems/journal
     * modes — PHP cannot fopen() a directory for writing at all, so this is
     * shell-out best-effort only, same caveats as fsync_file() above.
     */
    private static function fsync_dir(string $path): void {
        DurableFilesystem::syncDirectory($path);
    }

    private static function best_effort_sync(string $path): void {
        DurableFilesystem::syncDirectory($path);
    }

    public static function rrmdir(string $dir): void {
        DurableFilesystem::removeTree($dir);
    }

    /**
     * A protocol root must be an ordinary path or absent, never a symlink.
     * PHP's is_dir()/is_file() follow links, so checking only those predicates
     * would let a published, staged, retained, or durable-record path resolve
     * outside the destination and make recovery/cleanup operate on unrelated
     * files. Broken links are rejected too: is_link() reports them even when
     * the target no longer exists.
     */
    private static function assert_not_symlink_root(string $path, string $label): void {
        DurableFilesystem::assertRoot($path, $label);
    }

    /** Validate every named sibling before any protocol boundary is read. */
    private static function assert_protocol_roots(string $stateDir): void {
        foreach ([
            'published state' => $stateDir,
            'staging' => self::stage_dir($stateDir),
            'retained backup' => self::backup_dir($stateDir),
            'intent record' => self::intent_path($stateDir),
            'receipt record' => self::receipt_path($stateDir),
            'intent previous transition' => self::record_previous_path(self::intent_path($stateDir)),
            'intent next transition' => self::record_next_path(self::intent_path($stateDir)),
            'receipt previous transition' => self::record_previous_path(self::receipt_path($stateDir)),
            'receipt next transition' => self::record_next_path(self::receipt_path($stateDir)),
            'capture lock' => self::lock_path($stateDir),
        ] as $label => $path) {
            self::assert_not_symlink_root($path, $label);
        }
    }

    public function intent(): ?PublicationRecord {
        $record = self::intent_record($this->stateDir);
        return $record === null ? null : PublicationRecord::fromArray($record);
    }

    public function receipt(): ?PublicationRecord {
        $record = self::receipt_record($this->stateDir);
        return $record === null ? null : PublicationRecord::fromArray($record);
    }

    public function begin(string $candidateDir, bool $createOnly = false): PublicationRecord {
        return PublicationRecord::fromArray(self::begin_intent($this->stateDir, $candidateDir, $createOnly));
    }

    public function markSwapped(PublicationRecord $record, bool $initExclusion = false): PublicationRecord {
        return PublicationRecord::fromArray(self::mark_swapped($this->stateDir, $record->toArray(), $initExclusion));
    }

    public function markCommitReady(PublicationRecord $record, bool $initExclusion = false): PublicationRecord {
        return PublicationRecord::fromArray(self::mark_commit_ready($this->stateDir, $record->toArray(), $initExclusion));
    }

    public function markCommitting(PublicationRecord $record, bool $initExclusion = false): PublicationRecord {
        return PublicationRecord::fromArray(self::mark_committing($this->stateDir, $record->toArray(), $initExclusion));
    }

    public function writeReceipt(PublicationRecord $record, bool $createOnly = false): PublicationRecord {
        return PublicationRecord::fromArray(self::write_receipt($this->stateDir, $record->toArray(), $createOnly));
    }

    /** @return list<string> */
    public function recover(?callable $commitStatus = null): array {
        return self::recoverProtocol($this->stateDir, $commitStatus);
    }

    /** @return list<string> */
    public function recoverInitial(
        array $stateReservation,
        array $candidate,
        callable $commitStatus
    ): array {
        return self::recover_initial($this->stateDir, $stateReservation, $candidate, $commitStatus);
    }
}

/**
 * Persistent inode-bound filesystem helper (DUO-3425).
 *
 * Publish::run_bound_operation() proves one mutation per fresh `php -r`
 * subprocess. Its crux is P1, "CWD-as-inode-capability": the child is placed
 * inside the reviewed parent directory and verifies lstat('.') dev/ino before
 * naming only direct children of `.`, so a lexical path swap can fail the
 * identity check but can never redirect the mkdir/open. A staging walk of
 * thousands of files pays one process spawn per file for that guarantee.
 *
 * BoundHelper keeps that guarantee byte-for-byte while spawning ONE subprocess
 * for a whole walk. Its loop body IS run_bound_operation's child body, wrapped
 * per-op with the five items the persistence introduces:
 *
 *  1. clearstatcache(true) at the TOP of every op. lstat()/is_*() cache by the
 *     literal string '.', which resolves to a different inode after each chdir.
 *     A fresh process had an empty cache; a long-lived one does not, so without
 *     this a STALE '.' stat could satisfy the identity check against the WRONG
 *     (previous) inode. Global (also drops the realpath cache); required for
 *     both correctness AND P1.
 *  2. Verify `.` (the held kernel CWD inode), NEVER $path. The authority stands
 *     inside a proven inode and names only its direct children; statting the
 *     lexical $path would reintroduce the swap vulnerability P1 closes. The
 *     chdir->lstat('.') TOCTOU is closed BECAUSE `.` is the installed CWD
 *     reference, read with no lexical re-resolution. (The chdir($base) reset
 *     before chdir($path) reproduces proc_open's one-shot relative resolution:
 *     run_bound_operation resolves a relative $parent against the agent CWD once
 *     via proc_open's cwd argument, and Init confirmation binds the repo then
 *     passes '.'-relative staging paths, so the long-lived process must return
 *     to its launch CWD before each op.)
 *  3. The write terminator is bytes_len, NOT EOF. STDIN stays open across ops,
 *     so a premature EOF while draining the payload is fail-closed -- a
 *     truncated stream must never yield a short-but-"valid" file.
 *  4. ANY parse/identity/verify/digest/boundary failure writes its reason to
 *     STDERR and exit(73): the helper DIES, never report-and-continue. That
 *     makes a desynced stream unexploitable by construction and matches
 *     run_bound_operation's blast radius (one refusal aborts the whole
 *     staging). The parent observes the dead pipe, throws the identical
 *     exception, and poisons the handle so the anomalous helper is never reused.
 *  5. Reason strings and the InitialStateBoundaryException message are
 *     byte-identical to run_bound_operation (P8). The two new reasons
 *     ('parent path unavailable', and truncation routed through the existing
 *     'fresh file write failed') are additive only.
 *
 * Only the high-volume staging walks (InitCodeBaseline::capture and
 * Publish::write_entities_fresh) route through one helper each. The single-op
 * callers keep run_bound_operation's per-spawn path unchanged.
 */
final class BoundHelper {
    /** @var resource|null */
    private $process = null;
    /** @var array<int,resource> */
    private array $pipes = [];
    private bool $poisoned = false;

    /**
     * The per-op loop run by the single helper subprocess. It is
     * run_bound_operation's child body verbatim except for: the framing (a
     * per-op header line plus exactly bytes_len payload bytes instead of one op
     * then STDIN EOF), the mandatory per-op clearstatcache/CWD reset, and
     * one-op-then-explicit-reset (fclose every handle, free the hash, flush the
     * response) instead of one-op-then-exit. Exposed for the offline mutation
     * harness (regress_bound_helper.php), which removes clearstatcache and the
     * exit(73) to prove both are load-bearing.
     */
    public static function loop_script(): string {
        return <<<'PHP'
$fail = static function (string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(73);
};
$base = getcwd();
while (true) {
    clearstatcache(true);
    $line = fgets(STDIN);
    if ($line === false) {
        exit(0);
    }
    $request = json_decode($line, true);
    if (!is_array($request)) $fail('invalid request');
    $name = $request['name'] ?? null;
    $parent = $request['parent'] ?? null;
    $op = $request['op'] ?? null;
    $mode = $request['mode'] ?? null;
    $path = $request['path'] ?? null;
    $bytesLen = $request['bytes_len'] ?? null;
    if (!is_string($name) || $name === '' || $name === '.' || $name === '..'
        || str_contains($name, '/') || str_contains($name, "\0")
        || !is_array($parent) || !is_int($mode)
        || !is_string($path) || $path === ''
        || !is_int($bytesLen) || $bytesLen < 0) {
        $fail('unsafe request');
    }
    if ($base === false || !@chdir($base) || !@chdir($path)) {
        $fail('parent path unavailable');
    }
    $cwd = @lstat('.');
    if (!is_array($cwd) || !is_dir('.')
        || (string) ($cwd['dev'] ?? '') !== (string) ($parent['dev'] ?? '')
        || (string) ($cwd['ino'] ?? '') !== (string) ($parent['ino'] ?? '')) {
        $fail('parent identity changed');
    }
    if ($op === 'mkdir') {
        if (file_exists($name) || is_link($name) || !@mkdir($name, $mode & 0777)) {
            $fail('fresh directory boundary changed');
        }
        $stat = @lstat($name);
        if (!is_array($stat) || is_link($name) || !is_dir($name)) {
            $fail('fresh directory changed after creation');
        }
        fwrite(STDOUT, json_encode([
            'type' => 'directory', 'dev' => (string) $stat['dev'], 'ino' => (string) $stat['ino'],
        ], JSON_UNESCAPED_SLASHES) . "\n");
        fflush(STDOUT);
        continue;
    }
    if ($op !== 'write' && $op !== 'copy') $fail('unsupported operation');
    $input = STDIN;
    if ($op === 'copy') {
        $source = $request['source'] ?? null;
        if (!is_string($source) || $source === '' || is_link($source) || !is_file($source)) {
            $fail('copy source changed');
        }
        $input = @fopen($source, 'rb');
        if (!is_resource($input)) $fail('copy source unreadable');
    }
    $output = @fopen($name, 'x+b');
    if (!is_resource($output)) {
        if ($op === 'copy' && is_resource($input)) fclose($input);
        $fail('fresh file boundary changed');
    }
    $hash = hash_init('sha256');
    $ok = true;
    if ($op === 'write') {
        $remaining = $bytesLen;
        while ($remaining > 0) {
            $chunk = fread($input, $remaining < 65536 ? $remaining : 65536);
            if (!is_string($chunk) || ($chunk === '' && feof($input))) { $ok = false; break; }
            if ($chunk === '') continue;
            hash_update($hash, $chunk);
            $offset = 0;
            $length = strlen($chunk);
            while ($offset < $length) {
                $written = fwrite($output, substr($chunk, $offset));
                if (!is_int($written) || $written < 1) { $ok = false; break 2; }
                $offset += $written;
            }
            $remaining -= $length;
        }
    } else {
        while (!feof($input)) {
            $chunk = fread($input, 65536);
            if (!is_string($chunk)) { $ok = false; break; }
            if ($chunk === '') continue;
            hash_update($hash, $chunk);
            $offset = 0;
            $length = strlen($chunk);
            while ($offset < $length) {
                $written = fwrite($output, substr($chunk, $offset));
                if (!is_int($written) || $written < 1) { $ok = false; break 2; }
                $offset += $written;
            }
        }
    }
    if ($op === 'copy' && is_resource($input)) fclose($input);
    if (!$ok || !fflush($output) || !@chmod($name, $mode & 0777)) {
        $opened = @fstat($output);
        $named = @lstat($name);
        $same = is_array($opened) && is_array($named) && !is_link($name) && is_file($name)
            && (string) ($opened['dev'] ?? '') === (string) ($named['dev'] ?? '')
            && (string) ($opened['ino'] ?? '') === (string) ($named['ino'] ?? '');
        fclose($output);
        if ($same) @unlink($name);
        $fail('fresh file write failed');
    }
    if (function_exists('fsync')) @fsync($output);
    $opened = fstat($output);
    $digest = hash_final($hash);
    $named = @lstat($name);
    $same = is_array($opened) && is_array($named) && !is_link($name) && is_file($name)
        && (string) ($opened['dev'] ?? '') === (string) ($named['dev'] ?? '')
        && (string) ($opened['ino'] ?? '') === (string) ($named['ino'] ?? '');
    if (!$same) {
        fclose($output);
        $fail('fresh file name changed after creation');
    }
    if ($op === 'copy' && (!is_string($request['sha256'] ?? null)
        || !hash_equals($request['sha256'], $digest))) {
        fclose($output);
        $current = @lstat($name);
        $stillSame = is_array($current) && !is_link($name) && is_file($name)
            && (string) ($opened['dev'] ?? '') === (string) ($current['dev'] ?? '')
            && (string) ($opened['ino'] ?? '') === (string) ($current['ino'] ?? '');
        if ($stillSame) @unlink($name);
        $fail('copy source digest changed');
    }
    fclose($output);
    fwrite(STDOUT, json_encode([
        'type' => 'file', 'dev' => (string) $named['dev'], 'ino' => (string) $named['ino'],
        'sha256' => $digest,
    ], JSON_UNESCAPED_SLASHES) . "\n");
    fflush(STDOUT);
}
PHP;
    }

    private function ensure_started(string $label): void {
        if ($this->poisoned) {
            throw new InitialStateBoundaryException(
                "duo: initial $label cannot reuse a torn-down bound-filesystem helper"
            );
        }
        if ($this->process !== null) {
            return;
        }
        if (!function_exists('proc_open') || !defined('PHP_BINARY') || PHP_BINARY === '') {
            throw new InitialStateBoundaryException("duo: initial $label requires the bound-filesystem helper");
        }
        $pipes = [];
        $process = @proc_open(
            [PHP_BINARY, '-r', self::loop_script()],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new InitialStateBoundaryException("duo: initial $label could not start its bound-filesystem helper");
        }
        $this->process = $process;
        $this->pipes = $pipes;
    }

    /**
     * Frame one op to the persistent helper and read its single success
     * response. Success is one JSON line (byte-identical to run_bound_operation
     * plus a "\n" frame); a failure is never a frame -- the helper writes its
     * reason to STDERR and dies. Any refusal (dead pipe, non-array identity, or
     * a payload write the dying helper stopped draining) tears the anomalous
     * helper down and throws the identical InitialStateBoundaryException; the
     * handle is poisoned so it can never be reused.
     *
     * @param array<string,mixed> $request
     * @return array<string,string>
     */
    public function run(string $parent, array $request, string $bytes, string $label): array {
        $this->ensure_started($label);
        $request['path'] = $parent;
        $request['bytes_len'] = strlen($bytes);
        $header = json_encode($request, JSON_UNESCAPED_SLASHES);
        if (!is_string($header)) {
            $this->close();
            throw new InitialStateBoundaryException(
                "duo: initial $label could not encode its bound-filesystem request"
            );
        }
        $remaining = $header . "\n" . $bytes;
        $wrote = true;
        while ($remaining !== '') {
            $written = @fwrite($this->pipes[0], $remaining);
            if (!is_int($written) || $written < 1) {
                $wrote = false;
                break;
            }
            $remaining = (string) substr($remaining, $written);
        }
        if ($wrote) {
            $line = @fgets($this->pipes[1]);
            $identity = is_string($line) ? json_decode($line, true) : null;
            if (is_array($identity)) {
                return array_map('strval', $identity);
            }
        }
        // Refusal: the helper died (exit 73) or produced no valid identity.
        // Capture its reason, tear the anomalous helper down, and fail closed
        // with the identical exception run_bound_operation throws.
        $this->poisoned = true;
        @proc_terminate($this->process);
        $reason = '';
        if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
            $stderr = @stream_get_contents($this->pipes[2]);
            $reason = trim(is_string($stderr) ? $stderr : '');
        }
        $this->close();
        throw new InitialStateBoundaryException(
            "duo: initial $label refused at its inode-bound parent"
                . ($reason === '' ? '' : ': ' . $reason)
        );
    }

    /**
     * Release the helper subprocess. Idempotent, and safe to call from a
     * staging walk's finally so a mid-walk exception can never leak a live
     * child (proc_terminate + proc_close).
     */
    public function close(): void {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $this->pipes = [];
        if ($this->process === null) {
            return;
        }
        @proc_terminate($this->process);
        @proc_close($this->process);
        $this->process = null;
    }
}
