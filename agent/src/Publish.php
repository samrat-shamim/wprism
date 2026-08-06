<?php
namespace Duo;

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
 * On-disk layout (all four names are siblings of the published directory,
 * e.g. repo/state; every one of them is gitignored — git must never see a
 * staging/backup/lock artifact even transiently):
 *   state                    the published tree; readers only ever see
 *                            this name, and it is always either the
 *                            complete PREVIOUS tree or the complete NEW
 *                            one (see swap() below), never a partial mix
 *   state.capture-staging    this run's candidate tree, built and
 *                            validated BEFORE it ever touches 'state'
 *   state.capture-backup     the previous tree, present only for the
 *                            instant between swap()'s two renames, or
 *                            left over after a crash in that instant
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
     * Three independent facts decide the outcome — $stateDir existing,
     * a leftover staging dir, a leftover backup dir:
     *
     *  - A leftover STAGING dir is always DISCARDED, unconditionally. It
     *    was never promoted (recover() runs before this run builds its own
     *    staging dir, so anything found here predates us), and there is no
     *    way to prove it's complete or still reflects a reasonable point in
     *    time — rebuilding fresh is cheap and certain, adopting a stale
     *    candidate is not.
     *  - A leftover BACKUP dir + $stateDir MISSING means the previous run
     *    died between swap()'s two renames: $stateDir was already moved
     *    aside and the new tree never arrived. The backup IS the last
     *    known-good published tree — restore it verbatim.
     *  - A leftover BACKUP dir + $stateDir PRESENT means swap() completed
     *    in full and only its own best-effort final cleanup didn't run
     *    (crash, or an interrupted process). The backup is stale; sweep it.
     *
     * @return string[] log lines describing what, if anything, was recovered
     */
    public static function recover(string $stateDir): array {
        $log = [];
        $staging = self::stage_dir($stateDir);
        $backup = self::backup_dir($stateDir);

        if (is_dir($staging)) {
            self::rrmdir($staging);
            $log[] = "recovered: removed abandoned staging dir $staging (a prior capture never completed; rebuilding fresh)";
        }

        if (is_dir($backup)) {
            if (!is_dir($stateDir)) {
                if (!@rename($backup, $stateDir)) {
                    throw new \RuntimeException(
                        "duo: capture recovery failed — $stateDir is missing and its crash-recovery backup "
                        . "$backup could not be restored (rename failed). This needs manual intervention: verify "
                        . "$backup's contents look like a complete state tree, then move it to $stateDir yourself."
                    );
                }
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
    public static function swap(string $stateDir): void {
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

        $hadPrevious = is_dir($stateDir);
        if ($hadPrevious && !@rename($stateDir, $backup)) {
            throw new \RuntimeException(
                "duo: atomic swap failed at step 1 (rename $stateDir -> $backup) — the previous tree is untouched "
                . "at $stateDir; the new candidate is left at $staging for inspection or re-run."
            );
        }

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
        if (is_dir($backup)) {
            self::rrmdir($backup);
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
