<?php
namespace Duo;

require_once __DIR__ . '/Canon.php';

/**
 * Publish: atomic tree publication for `duo capture` (DUO-3213).
 *
 * Pure filesystem, zero WordPress/$wpdb dependency — deliberate, so every
 * guarantee here is independently testable offline (no docker, no WP
 * bootstrap; the same "vendored fixture, real code" idiom as sandbox/tests/
 * regress_block_refs.php's FakeWpdb, just with no DB stub needed at all).
 * Capture.php owns the DB-side half of this issue (consistent-snapshot
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
final class Publish {
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
        $path = self::lock_path($stateDir);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("duo: cannot create directory $dir for the capture lock");
        }
        $fh = fopen($path, 'c'); // create-if-missing, never truncate on open — only flock() state matters
        if ($fh === false) {
            throw new \RuntimeException("duo: cannot open capture lock file $path");
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new \RuntimeException(
                "duo: capture refused — another capture is already publishing to $stateDir (lock held: $path).\n"
                . 'Concurrent captures to the same destination are never interleaved (DUO-3213); '
                . 'wait for the other one to finish and re-run.'
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
    public static function recover(string $stateDir, ?callable $commitStatus = null): array {
        $log = [];
        $staging = self::stage_dir($stateDir);
        $backup = self::backup_dir($stateDir);

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
                throw new \RuntimeException(
                    'duo: capture recovery refused — a committed receipt exists but published state/ is missing; '
                    . 'the database and filesystem boundary is inconsistent and needs manual inspection'
                );
            }
            $actual = self::tree_digest($stateDir);
            if (!hash_equals((string) ($matchingReceipt['candidate_sha256'] ?? ''), $actual)) {
                throw new \RuntimeException(
                    'duo: capture recovery refused — committed receipt does not match the current state/ tree; '
                    . 'possible out-of-band modification, leaving artifacts untouched'
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
            if (is_file(self::intent_path($stateDir))) {
                self::remove_record(self::intent_path($stateDir), 'intent');
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
                    self::remove_record(self::intent_path($stateDir), 'intent');
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
                    self::remove_record(self::intent_path($stateDir), 'intent');
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
                    self::remove_record(self::intent_path($stateDir), 'intent');
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
                    self::remove_record(self::intent_path($stateDir), 'intent');
                    $log[] = "RECOVERED: restored $stateDir from the retained backup before COMMIT was attempted";
                    return $log;
                }
                // First-ever publication after step 2: no backup means the
                // complete candidate is present, but COMMIT was not reached.
                if (!is_dir($backup) && is_dir($stateDir) && !is_dir($staging)) {
                    self::assert_first_publication($intent);
                    self::assert_tree_digest($stateDir, (string) $intent['candidate_sha256'], 'first-capture published candidate');
                    self::rrmdir($stateDir);
                    self::remove_record(self::intent_path($stateDir), 'intent');
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
                    self::remove_record(self::intent_path($stateDir), 'intent');
                    $log[] = "RECOVERED: restored $stateDir from the retained backup before COMMIT was attempted";
                    return $log;
                }
                if (!is_dir($backup) && is_dir($stateDir) && !is_dir($staging)) {
                    self::assert_first_publication($intent);
                    self::assert_tree_digest($stateDir, (string) $intent['candidate_sha256'], 'first-capture published candidate');
                    self::rrmdir($stateDir);
                    self::remove_record(self::intent_path($stateDir), 'intent');
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
                    self::remove_record(self::intent_path($stateDir), 'intent');
                    $log[] = 'RECOVERED: database commit marker was absent/prior; rolled back the uncommitted publication';
                    return $log;
                }
                throw self::ambiguous_recovery('COMMIT was attempted but no database commit proof or receipt is available');
            }
            throw new \RuntimeException('duo: capture recovery refused — malformed publication intent phase');
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
     * The atomic two-step swap described in the class docblock. Throws
     * loudly and leaves the filesystem in a recoverable state on any
     * failure — never silently continues past a rename that didn't happen.
     */
    public static function swap(string $stateDir, bool $retainBackup = false): void {
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
     * Start a durable capture publication record. Call this only after the
     * complete staged candidate has passed all read/validation gates and
     * while the capture transaction is still open. The returned record is
     * plain data so Capture can carry it across COMMIT without reopening or
     * guessing at mutable state.
     *
     * @return array{format:string,id:string,phase:string,candidate_sha256:string,previous_sha256:string,created_at:string}
     */
    public static function begin_intent(string $stateDir, string $candidateDir): array {
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
        $intent = self::write_record(self::intent_path($stateDir), $intent, 'intent');
        self::fault_checkpoint('intent-written');
        return $intent;
    }

    /** Mark the intent after both filesystem renames have returned. */
    public static function mark_swapped(string $stateDir, array $intent): array {
        self::assert_record($intent, 'intent', 'duo-capture-intent/v1');
        $onDisk = self::read_record(self::intent_path($stateDir), 'intent');
        if ($onDisk === null || ($onDisk['id'] ?? null) !== ($intent['id'] ?? null)) {
            throw new \RuntimeException('duo: capture swap completed but its durable intent is missing or changed');
        }
        $intent['phase'] = 'swapped';
        $intent = self::write_record(self::intent_path($stateDir), $intent, 'intent');
        self::fault_checkpoint('intent-swapped');
        return $intent;
    }

    /**
     * Mark that all callback work finished and COMMIT is the next operation.
     * This extra durable state distinguishes a crash before the DB commit was
     * attempted (safe to restore the retained backup) from a crash while the
     * client/server commit outcome is unknowable (must remain fail-closed).
     */
    public static function mark_commit_ready(string $stateDir, array $intent): array {
        self::assert_record($intent, 'intent', 'duo-capture-intent/v1');
        $onDisk = self::read_record(self::intent_path($stateDir), 'intent');
        if ($onDisk === null || ($onDisk['id'] ?? null) !== ($intent['id'] ?? null)) {
            throw new \RuntimeException('duo: capture cannot mark COMMIT-ready — durable intent is missing or changed');
        }
        $intent['phase'] = 'ready';
        $intent = self::write_record(self::intent_path($stateDir), $intent, 'intent');
        self::fault_checkpoint('intent-ready');
        return $intent;
    }

    /** Mark the exact point immediately before issuing DB COMMIT. */
    public static function mark_committing(string $stateDir, array $intent): array {
        self::assert_record($intent, 'intent', 'duo-capture-intent/v1');
        $onDisk = self::read_record(self::intent_path($stateDir), 'intent');
        if ($onDisk === null || ($onDisk['id'] ?? null) !== ($intent['id'] ?? null)) {
            throw new \RuntimeException('duo: capture cannot mark COMMIT-attempted — durable intent is missing or changed');
        }
        // An injected pre-commit failure must leave `ready` on disk so the
        // next recovery can restore the old tree. A real crash after the
        // marker write is represented by `committing` and is fail-closed.
        self::fault_checkpoint('commit-attempt');
        $intent['phase'] = 'committing';
        $intent = self::write_record(self::intent_path($stateDir), $intent, 'intent');
        self::fault_checkpoint('after-commit-marker');
        return $intent;
    }

    /**
     * Persist the post-COMMIT receipt. A receipt is retained as audit data;
     * cleanup of the old tree and intent is a separate idempotent operation.
     *
     * @return array{format:string,intent_id:string,phase:string,candidate_sha256:string,previous_sha256:string,committed_at:string}
     */
    public static function write_receipt(string $stateDir, array $intent): array {
        self::assert_record($intent, 'intent', 'duo-capture-intent/v1');
        if (!is_dir($stateDir)) {
            throw new \RuntimeException('duo: cannot write a capture receipt because state/ is missing after COMMIT');
        }
        // The transaction wrapper advances the on-disk intent to
        // `committing` immediately before COMMIT. Re-read it so the receipt
        // binds that exact record even though Capture carried the pre-commit
        // PHP array across the wrapper boundary.
        $onDisk = self::read_record(self::intent_path($stateDir), 'intent');
        if ($onDisk === null || ($onDisk['id'] ?? null) !== ($intent['id'] ?? null)) {
            throw new \RuntimeException('duo: cannot write a capture receipt because its durable intent is missing or changed');
        }
        if (($onDisk['phase'] ?? null) !== 'committing') {
            throw new \RuntimeException('duo: cannot write a capture receipt before the durable COMMIT-attempt marker');
        }
        $intent = $onDisk;
        $actual = self::tree_digest($stateDir);
        if (!hash_equals((string) $intent['candidate_sha256'], $actual)) {
            throw new \RuntimeException('duo: capture receipt refused — published state/ does not match the staged candidate');
        }
        $receipt = [
            'format' => 'duo-capture-receipt/v1',
            'intent_id' => (string) $intent['id'],
            'phase' => 'committed',
            'candidate_sha256' => (string) $intent['candidate_sha256'],
            'previous_sha256' => (string) $intent['previous_sha256'],
            'committed_at' => gmdate('c'),
        ];
        $receipt = self::write_record(self::receipt_path($stateDir), $receipt, 'receipt');
        self::fault_checkpoint('receipt-written');
        return $receipt;
    }

    /**
     * Remove post-commit artifacts only after a receipt has been durably
     * written. Failure is intentionally propagated: the receipt remains and
     * the next lock holder can retry this cleanup without replaying capture.
     */
    public static function cleanup_committed(string $stateDir, array $receipt): void {
        self::assert_record($receipt, 'receipt', 'duo-capture-receipt/v1');
        if (!is_dir($stateDir)) {
            throw new \RuntimeException('duo: post-commit cleanup refused — published state/ is missing');
        }
        if (!hash_equals((string) $receipt['candidate_sha256'], self::tree_digest($stateDir))) {
            throw new \RuntimeException('duo: post-commit cleanup refused — state/ no longer matches its receipt');
        }
        $backup = self::backup_dir($stateDir);
        $staging = self::stage_dir($stateDir);
        $intent = self::intent_path($stateDir);
        $hadRetainedArtifacts = is_dir($backup) || is_dir($staging);
        if ($hadRetainedArtifacts && !is_file($intent)) {
            throw new \RuntimeException('duo: post-commit cleanup refused — retained artifacts have no matching intent');
        }
        if (is_file($intent)) {
            $onDisk = self::read_record($intent, 'intent');
            if ($onDisk === null || ($onDisk['id'] ?? null) !== ($receipt['intent_id'] ?? null)) {
                throw new \RuntimeException('duo: post-commit cleanup refused — intent id differs from receipt');
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
        if (is_file($intent)) {
            self::remove_record($intent, 'intent');
        }
        self::fsync_dir(dirname($stateDir));
    }

    /** Deterministic digest of every regular file beneath a tree. */
    public static function tree_digest(string $dir): string {
        if (!is_dir($dir)) {
            throw new \RuntimeException("duo: cannot hash missing tree $dir");
        }
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isLink()) {
                throw new \RuntimeException("duo: refusing to hash symlink in capture tree: {$file->getPathname()}");
            }
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), strlen(rtrim($dir, '/')) + 1);
            }
        }
        sort($files, SORT_STRING);
        $ctx = hash_init('sha256');
        foreach ($files as $relative) {
            $bytes = Canon::read_file($dir . '/' . $relative);
            hash_update($ctx, $relative . "\0" . $bytes . "\0");
        }
        return hash_final($ctx);
    }

    private static function empty_tree_digest(): string {
        return hash('sha256', '');
    }

    /** @return ?array<string,mixed> */
    private static function read_record(string $path, string $label): ?array {
        if (!is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("duo: capture recovery cannot read $label record $path");
        }
        try {
            $record = Canon::decode($bytes);
        } catch (\Throwable $e) {
            throw new \RuntimeException("duo: capture recovery found malformed $label record $path", 0, $e);
        }
        if (!is_array($record)) {
            throw new \RuntimeException("duo: capture recovery found non-object $label record $path");
        }
        self::assert_sealed_record($record, $label);
        return $record;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private static function write_record(string $path, array $record, string $label): array {
        $record = self::seal_record($record);
        $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(6));
        try {
            Canon::write_file($tmp, Canon::encode($record));
            self::fsync_file($tmp);
            if (!@rename($tmp, $path)) {
                throw new \RuntimeException("duo: cannot publish durable capture $label record $path");
            }
            self::fsync_dir(dirname($path));
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        return $record;
    }

    /** @param array<string,mixed> $record */
    private static function assert_record(array $record, string $label, string $format): void {
        self::assert_sealed_record($record, $label);
        if (($record['format'] ?? null) !== $format) {
            throw new \RuntimeException("duo: capture recovery found unsupported $label record format");
        }
        if ($label === 'intent') {
            if (!is_string($record['id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/', $record['id'])) {
                throw new \RuntimeException('duo: capture recovery found malformed intent id');
            }
            if (!in_array($record['phase'] ?? null, ['prepared', 'swapped', 'ready', 'committing'], true)) {
                throw new \RuntimeException('duo: capture recovery found malformed intent phase');
            }
        } else {
            if (!is_string($record['intent_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/', $record['intent_id'])) {
                throw new \RuntimeException('duo: capture recovery found malformed receipt id');
            }
            if (($record['phase'] ?? null) !== 'committed') {
                throw new \RuntimeException('duo: capture recovery found malformed receipt phase');
            }
        }
        foreach (['candidate_sha256', 'previous_sha256'] as $key) {
            if (!is_string($record[$key] ?? null) || preg_match('/^[a-f0-9]{64}$/', $record[$key]) !== 1) {
                throw new \RuntimeException("duo: capture recovery found malformed $label $key");
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
            throw new \RuntimeException("duo: capture recovery found an unexpected $label record shape");
        }
        $seal = $record['record_sha256'] ?? null;
        if (!is_string($seal) || preg_match('/^[a-f0-9]{64}$/', $seal) !== 1) {
            throw new \RuntimeException("duo: capture recovery found an unsealed $label record");
        }
        $payload = $record;
        unset($payload['record_sha256']);
        if (!hash_equals($seal, hash('sha256', Canon::encode($payload)))) {
            throw new \RuntimeException("duo: capture recovery found a tampered $label record");
        }
        foreach (['created_at', 'committed_at'] as $timeKey) {
            if (array_key_exists($timeKey, $record)
                && (!is_string($record[$timeKey]) || trim($record[$timeKey]) === '')) {
                throw new \RuntimeException("duo: capture recovery found a malformed $label timestamp");
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

    private static function remove_record(string $path, string $label): void {
        if (is_file($path) && !@unlink($path)) {
            throw new \RuntimeException("duo: capture recovery could not remove durable $label record $path");
        }
        self::fsync_dir(dirname($path));
    }

    private static function restore_backup(string $stateDir, string $backup): void {
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
        if (is_dir($stateDir)) {
            self::rrmdir($stateDir);
        }
        self::restore_backup($stateDir, $backup);
    }

    private static function ambiguous_recovery(string $reason): \RuntimeException {
        return new \RuntimeException(
            'duo: capture recovery is blocked by an ambiguous publication/COMMIT boundary — '
            . $reason . '; refusing to retry or discard the retained backup. Inspect the intent, receipt, and database before continuing.'
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
        if (function_exists('fsync')) {
            $fh = @fopen($path, 'r+');
            if ($fh !== false) {
                @fsync($fh);
                fclose($fh);
                return;
            }
        }
        self::best_effort_sync($path);
    }

    /**
     * Durably persisting a rename (a directory-entry change, not file
     * content) requires fsync'ing the DIRECTORY on some filesystems/journal
     * modes — PHP cannot fopen() a directory for writing at all, so this is
     * shell-out best-effort only, same caveats as fsync_file() above.
     */
    private static function fsync_dir(string $path): void {
        self::best_effort_sync($path);
    }

    private static function best_effort_sync(string $path): void {
        if (!function_exists('exec')) {
            return;
        }
        @exec('sync ' . escapeshellarg($path) . ' 2>/dev/null', $out, $rc);
        if ($rc !== 0) {
            @exec('sync 2>/dev/null'); // whole-filesystem fallback: BSD/macOS sync, or a GNU sync that rejected the path
        }
    }

    public static function rrmdir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
