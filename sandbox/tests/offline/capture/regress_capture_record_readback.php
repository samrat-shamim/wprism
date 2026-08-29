<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression for issue #3422:
 * capture record readback must survive a STALE filesystem stat.
 *
 * The bug: a clean Contact Form 7 certification run failed its second
 * output-only capture with
 *   "capture recovery found a non-file intent record boundary
 *    /siterepo/.tmp-state2.capture-intent"
 * even though a post-failure readback proved that exact path WAS a regular
 * file — a hard link to the sealed `prepared` intent inode that
 * Publish::write_record() had just @link()'d into place. On Docker Desktop's
 * bind mount, PHP's cached stat for the just-published path still classified
 * that fresh regular file as a non-file, so read_record()'s type check tripped
 * a FALSE non-file boundary. The identical staleness could also make the
 * write_record() `finally` cleanup's is_file($tmp) miss the owned temp hard
 * link and leak it.
 *
 * The fix (agent/src/Publication/PublicationJournal.php): read_record() uses a fresh lstat regular-file
 * check, opens the path read-only, and binds the opened descriptor to the named
 * inode before and after reading. write_record() likewise fresh-lstats its
 * owned temp before cleanup. Cached path predicates therefore grant neither
 * read nor cleanup authority.
 *
 * This suite runs the REAL, unmodified agent/src/{Canon,Publish}.php on real
 * files. PHP keeps its OWN single-entry userland stat cache, so the stale
 * false refusal is reproducible in pure PHP with no Docker: prime the cache
 * while the path is a non-file, then mutate the path to a regular-file hard
 * link inside a pcntl_fork()ed CHILD — the parent process's cached stat stays
 * stale (fork + pipe I/O never re-stat a path), the offline stand-in for the
 * bind mount's stale kernel stat. The mutation proof loads a COPY of
 * PublicationJournal.php with the inode-bound pre-open check reverted to the old cached
 * file_exists()/is_file()/is_link() boundary and shows the primed-stale
 * readback then throws the non-file boundary again — i.e. the fix genuinely
 * bites. A real directory, symlink, malformed, or unsealed record still
 * refuses exactly as before.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..." and
 * the script exits 1.
 */

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 4);
require "$repoRoot/agent/src/Kernel/Canon.php";
require "$repoRoot/agent/src/Publication/Publish.php";
if (!defined('WPRISM_SPEC_VERSION')) {
    define('WPRISM_SPEC_VERSION', 2);
}

use WPrism\Canon;
use WPrism\Publish;

$failures = 0;
function check(bool $cond, string $msg): void {
    global $failures;
    if ($cond) {
        echo "ok: $msg\n";
    } else {
        echo "FAIL: $msg\n";
        $failures++;
    }
}

/**
 * Fresh scratch root for one group; auto-removed at THIS process's exit only.
 * The owner-pid guard keeps a pcntl_fork()ed child (which inherits these
 * shutdown handlers) from rrmdir()-ing the parent's live test data on exit.
 */
function fresh_root(string $label): string {
    $root = sys_get_temp_dir() . '/wprism_regress_record_readback_' . $label . '_' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    $owner = getmypid();
    register_shutdown_function(static function () use ($root, $owner): void {
        if (getmypid() === $owner) {
            Publish::rrmdir($root);
        }
    });
    return $root;
}

/**
 * Prime PHP's stat cache with the CURRENT (pre-mutation) stat of $path in THIS
 * process via $primer, then run $childMutation inside a pcntl_fork()ed child so
 * the mutation lands on disk WITHOUT this process clearing its cached stat —
 * the offline stand-in for a bind mount's stale kernel stat. Returns true when
 * the stale prime was set up (pcntl available), false to skip.
 */
function prime_stale_via_fork(callable $primer, callable $childMutation): bool {
    if (!function_exists('pcntl_fork')) {
        return false;
    }
    $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        return false;
    }
    $pid = pcntl_fork();
    if ($pid === -1) {
        return false;
    }
    if ($pid === 0) {
        // Child: wait until the parent has primed, mutate on disk, signal back.
        // Exits cleanly; fresh_root's shutdown cleanup no-ops here (pid guard).
        fclose($pair[0]);
        fread($pair[1], 2);
        $childMutation();
        fwrite($pair[1], 'ok');
        fclose($pair[1]);
        exit(0);
    }
    // Parent: prime its stat cache, release the child, wait for the mutation.
    // Neither fwrite/fread on the socket nor pcntl_waitpid re-stats $path, so
    // the primed (now stale) cache entry survives to the caller's readback.
    fclose($pair[1]);
    $primer();
    fwrite($pair[0], 'go');
    fread($pair[0], 2);
    fclose($pair[0]);
    pcntl_waitpid($pid, $status);
    return true;
}

/**
 * Build a valid sealed `prepared` intent record on disk via the engine's own
 * write path (begin_intent -> write_record -> seal_record); return its path and
 * the intent array so callers can hard-link that sealed inode.
 */
function build_sealed_prepared_intent(string $root): array {
    $stateDir = "$root/state";
    mkdir($stateDir, 0777, true);
    Canon::write_file("$stateDir/revision.txt", "old\n");
    $staging = Publish::stage_dir($stateDir);
    mkdir($staging, 0777, true);
    Canon::write_file("$staging/revision.txt", "candidate\n");
    $intent = Publish::begin_intent($stateDir, $staging);
    return [Publish::intent_path($stateDir), $intent];
}

/** Build the real post-swap state used by the publication phase tests below. */
function build_swapped_intent(string $root): array {
    $stateDir = "$root/state";
    mkdir($stateDir, 0777, true);
    Canon::write_file("$stateDir/revision.txt", "old\n");
    $staging = Publish::stage_dir($stateDir);
    mkdir($staging, 0777, true);
    Canon::write_file("$staging/revision.txt", "candidate\n");
    $intent = Publish::begin_intent($stateDir, $staging);
    $intent = Publish::mark_swapped($stateDir, $intent);
    return [$stateDir, $intent];
}

/** Execute the COMMIT-ready transition under the same destination lock as capture. */
function mark_commit_ready_locked(string $stateDir, array $intent): array {
    $lock = Publish::lock($stateDir);
    try {
        return Publish::mark_commit_ready($stateDir, $intent);
    } finally {
        Publish::unlock($lock);
    }
}

/** Run one lock-held publication primitive with a one-shot missing-read seam. */
function run_readback_miss(string $scope, callable $operation): array {
    putenv('WPRISM_TEST_MODE=1');
    putenv('WPRISM_TEST_PUBLISH_READBACK_MISS_ONCE=' . $scope);
    $result = null;
    $error = null;
    try {
        $result = $operation();
    } catch (Throwable $t) {
        $error = $t;
    }
    $consumed = getenv('WPRISM_TEST_PUBLISH_READBACK_MISS_ONCE') === false;
    putenv('WPRISM_TEST_PUBLISH_READBACK_MISS_ONCE');
    putenv('WPRISM_TEST_MODE');
    return [$result, $error, $consumed];
}

/** Assert that a publication record has no unfinished fixed-slot transition. */
function record_slots_clean(string $stateDir, string $label): void {
    $intent = Publish::intent_path($stateDir);
    $receipt = Publish::receipt_path($stateDir);
    check(
        !file_exists($intent . '.previous') && !file_exists($intent . '.next')
            && !file_exists($receipt . '.previous') && !file_exists($receipt . '.next')
            && glob($intent . '.tmp.*') === [] && glob($receipt . '.tmp.*') === [],
        "$label leaves no previous/next/tmp record artifacts"
    );
}

/** Prepare a published candidate while leaving the durable intent in `prepared`. */
function build_prepared_swapped_intent(string $root): array {
    $stateDir = "$root/state";
    mkdir($stateDir, 0777, true);
    Canon::write_file("$stateDir/revision.txt", "old\n");
    $staging = Publish::stage_dir($stateDir);
    mkdir($staging, 0777, true);
    Canon::write_file("$staging/revision.txt", "candidate\n");
    $intent = Publish::begin_intent($stateDir, $staging);
    Publish::swap($stateDir, true);
    return [$stateDir, $intent];
}

/** Prepare the exact `committing` intent needed by receipt/cleanup tests. */
function build_committing_intent(string $root): array {
    [$stateDir, $intent] = build_prepared_swapped_intent($root);
    $intent = Publish::mark_swapped($stateDir, $intent);
    $intent = Publish::mark_commit_ready($stateDir, $intent);
    return [$stateDir, Publish::mark_committing($stateDir, $intent)];
}

/** Rewrite one sealed intent field while preserving its canonical self-hash. */
function rewrite_intent_field(string $stateDir, array $intent, string $field): array {
    $changed = $intent;
    $changed[$field] = str_repeat('a', 64);
    unset($changed['record_sha256']);
    $changed['record_sha256'] = hash('sha256', Canon::encode($changed));
    Canon::write_file(Publish::intent_path($stateDir), Canon::encode($changed));
    return $changed;
}

$pcntl = function_exists('pcntl_fork');
if (!$pcntl) {
    echo "note: pcntl_fork unavailable — the stale-cache repro (R1/R1'/R8) is skipped on this host;\n"
        . "      the no-weakening and cleanup proofs still run.\n";
}

// ======================================================================
// R1 — the bug: a stale-cached, just-published regular file must read back
// through the PUBLIC entry as its sealed record, NOT a false non-file boundary.
// ======================================================================
echo "\n== R1: stale-cache readback no longer trips a false non-file boundary ==\n";
if ($pcntl) {
    $root = fresh_root('bug_repro');

    // A valid sealed prepared intent whose inode we hard-link into place,
    // exactly as write_record()'s @link() publication does.
    [$sealedSource, $srcIntent] = build_sealed_prepared_intent("$root/source");

    // Destination whose intent path we prime STALE (as a non-file) before a
    // forked child replaces it with a regular-file hard link to the sealed inode.
    $stateDir = "$root/target/state";
    mkdir($stateDir, 0777, true);
    Canon::write_file("$stateDir/revision.txt", "published\n");
    $intentPath = Publish::intent_path($stateDir);
    mkdir($intentPath);

    $primed = prime_stale_via_fork(
        static function () use ($intentPath): void {
            clearstatcache();
            file_exists($intentPath); // prime stat: exists
            is_file($intentPath);     // prime stat: NOT a regular file
            is_link($intentPath);     // prime lstat too — on the bind mount BOTH
                                      // were stale, so read_record's own
                                      // assert_not_symlink_root is_link() reads
                                      // the stale value and never re-stats.
        },
        static function () use ($intentPath, $sealedSource): void {
            rmdir($intentPath);
            link($sealedSource, $intentPath);
        }
    );
    check($primed, 'R1a: stat cache primed with a non-file, then hard link published behind it (stale cache set up)');
    check(is_file($intentPath) === false,
        'R1b: this process still reads the just-published regular file as a NON-FILE (the exact stale-stat bug condition)');

    // THE FIX: intent_record() -> read_record() clearstatcache()s first, so its
    // type check is a fresh regular-file identity check and returns the sealed
    // record instead of throwing the false non-file boundary.
    $readErr = null;
    $readback = null;
    try {
        $readback = Publish::intent_record($stateDir);
    } catch (\Throwable $t) {
        $readErr = $t;
    }
    check($readErr === null,
        'R1c: the public entry no longer throws a false non-file boundary on the stale-cached regular file'
            . ($readErr ? ' (got: ' . $readErr->getMessage() . ')' : ''));
    check(is_array($readback) && ($readback['id'] ?? null) === ($srcIntent['id'] ?? null),
        'R1d: the sealed prepared intent is returned intact through the public entry');
}

// ======================================================================
// R1' — MUTATION PROOF: the same primed-stale readback, run against a copy of
// PublicationJournal.php whose inode-bound pre-open check is reverted to the old cached
// path predicates, MUST throw the non-file boundary again. This proves the
// stronger readback boundary is load-bearing.
// ======================================================================
echo "\n== R1': mutation proof (restore cached path predicates -> the boundary fires) ==\n";
if ($pcntl) {
    $root = fresh_root('mutation');
    $realSrc = "$repoRoot/agent/src";
    $realEngine = "$realSrc/Publication/PublicationJournal.php";
    $realFacade = "$realSrc/Publication/Publish.php";

    // A standalone copy of the engine tree so a mutated PublicationJournal.php still finds
    // its own require_once __DIR__ .'/CommandRefusal.php' + Canon.php siblings.
    $mutantSrc = "$root/mutant_src";
    $p = proc_open('cp -R ' . escapeshellarg($realSrc) . ' ' . escapeshellarg($mutantSrc),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]);
    $cpErr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $cpRc = proc_close($p);
    check($cpRc === 0, 'R1\'a: engine source tree copied for mutation' . ($cpRc === 0 ? '' : " ($cpErr)"));
    $mutantEngine = "$mutantSrc/Publication/PublicationJournal.php";
    $mutantFacade = "$mutantSrc/Publication/Publish.php";

    $srcText = (string) file_get_contents($mutantEngine);
    // The implementation now belongs to PublicationJournal and is public so
    // the legacy Publish facade can expose the exact same read boundary.
    // Target the first implementation declaration; the facade proxy later in
    // the file is intentionally not the mutation target.
    $methodNeedle = "    public static function read_record(string \$path, string \$label): ?array {\n";
    $handleNeedle = "        \$handle = @fopen(\$path, 'rb');\n";
    $methodOffset = strpos($srcText, $methodNeedle);
    $bodyOffset = is_int($methodOffset) ? $methodOffset + strlen($methodNeedle) : false;
    $handleOffset = is_int($bodyOffset) ? strpos($srcText, $handleNeedle, $bodyOffset) : false;
    check(is_int($methodOffset) && is_int($bodyOffset) && is_int($handleOffset),
        'R1\'b: the inode-bound read_record pre-open boundary is present (mutation target is unambiguous)');
    if (is_int($bodyOffset) && is_int($handleOffset)) {
        $legacyPrelude = <<<'PHP'
        if (is_link($path)) {
            throw new \RuntimeException("wprism: refusing to operate on symlinked $label record root $path");
        }
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }
        if (!is_file($path)) {
            throw self::ambiguous_recovery("capture recovery found a non-file $label record boundary $path");
        }
        $before = @stat($path);
PHP;
        $legacyPrelude .= "\n";
        $srcText = substr($srcText, 0, $bodyOffset)
            . $legacyPrelude
            . substr($srcText, $handleOffset);
        file_put_contents($mutantEngine, $srcText);
    }

    // The driver reproduces the primed-stale readback (via its own pcntl_fork)
    // and calls read_record() DIRECTLY, so the ONLY difference between the real
    // and mutated run is the restored cached-predicate pre-open boundary. It
    // prints one RESULT: line.
    $driver = "$root/readback_driver.php";
    file_put_contents($driver, <<<'DRIVER'
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
// argv[1] = a Publish.php facade to load (its dir must hold the engine,
//           CommandRefusal.php, and Canon.php); argv[2] = a fresh work dir.
require $argv[1];
use WPrism\Canon;
use WPrism\Publish;

if (!function_exists('pcntl_fork')) { fwrite(STDOUT, "RESULT:NO_PCNTL\n"); exit(0); }

$work = $argv[2];
@mkdir($work, 0777, true);

// A valid sealed prepared intent, via the engine's own write path.
$srcState = "$work/src/state";
mkdir($srcState, 0777, true);
Canon::write_file("$srcState/revision.txt", "old\n");
$staging = Publish::stage_dir($srcState);
mkdir($staging, 0777, true);
Canon::write_file("$staging/revision.txt", "candidate\n");
$intent = Publish::begin_intent($srcState, $staging);
$sealedSource = Publish::intent_path($srcState);

// Target intent path, primed stale with a non-file, then swapped in a child.
$stateDir = "$work/tgt/state";
mkdir($stateDir, 0777, true);
Canon::write_file("$stateDir/revision.txt", "published\n");
$intentPath = Publish::intent_path($stateDir);
mkdir($intentPath);

$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$pid = pcntl_fork();
if ($pid === 0) {
    fclose($pair[0]);
    fread($pair[1], 2);
    rmdir($intentPath);
    link($sealedSource, $intentPath);
    fwrite($pair[1], 'ok');
    fclose($pair[1]);
    exit(0);
}
fclose($pair[1]);
clearstatcache();
file_exists($intentPath);
is_file($intentPath);      // prime stat: non-file (this process's cache goes stale)
is_link($intentPath);      // prime lstat too, so read_record's own
                           // assert_not_symlink_root is_link() reads the stale
                           // value instead of re-stat'ing (both were stale on
                           // the bind mount).
fwrite($pair[0], 'go');
fread($pair[0], 2);
fclose($pair[0]);
pcntl_waitpid($pid, $st);

if (is_file($intentPath) !== false) { fwrite(STDOUT, "RESULT:NO_STALE\n"); exit(0); }

$rm = new ReflectionMethod(Publish::class, 'read_record');
try {
    $rec = $rm->invoke(null, $intentPath, 'intent');
    fwrite(STDOUT, 'RESULT:OK ' . (is_array($rec) ? (string) ($rec['id'] ?? '?') : 'not-array') . "\n");
} catch (\Throwable $t) {
    fwrite(STDOUT, 'RESULT:REFUSED ' . $t->getMessage() . "\n");
}
DRIVER);

    $runDriver = static function (string $publishPath, string $workDir): string {
        $p = proc_open(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($GLOBALS['driverGlobal'])
                . ' ' . escapeshellarg($publishPath) . ' ' . escapeshellarg($workDir),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        if (preg_match('/^RESULT:(.*)$/m', (string) $out, $m)) {
            return trim($m[1]);
        }
        return 'NO_RESULT';
    };
    $GLOBALS['driverGlobal'] = $driver;

    // Control: the REAL (fixed) source returns the sealed record for the exact
    // same primed-stale reproduction — proving the driver is a faithful repro,
    // so a REFUSED under mutation is attributable to the removed line alone.
    $realResult = $runDriver($realFacade, "$root/work_real");
    check(str_starts_with($realResult, 'OK'),
        'R1\'c: fixed source reads the stale-cached hard link back as the sealed record (control: ' . $realResult . ')');

    // Mutation: with the old cached path predicates restored, the identical
    // primed-stale readback throws the false non-file boundary again.
    $mutResult = $runDriver($mutantFacade, "$root/work_mutant");
    check(str_starts_with($mutResult, 'REFUSED')
            && str_contains($mutResult, 'non-file') && str_contains($mutResult, 'boundary'),
        'R1\'d: restoring cached path predicates reintroduces the non-file boundary refusal (mutation bites: ' . $mutResult . ')');
}

// ======================================================================
// R2..R5 — NO WEAKENING: the fresh stat only changes freshness, never the
// accepted/refused SET. A real directory, a symlink, a malformed record, and
// an unsealed/hash-mismatched record must each still refuse exactly as before.
// ======================================================================
echo "\n== R2..R5: the accepted/refused set is unchanged (fresh stat only) ==\n";

// R2 — a REAL directory still refuses with the non-file boundary.
{
    $root = fresh_root('nw_directory');
    $stateDir = "$root/state";
    mkdir($stateDir, 0777, true);
    $intentPath = Publish::intent_path($stateDir);
    mkdir($intentPath); // a genuine directory (not a stale-cache artifact)
    $err = null;
    try {
        Publish::intent_record($stateDir);
    } catch (\Throwable $t) {
        $err = $t;
    }
    check($err instanceof \Throwable
            && str_contains($err->getMessage(), 'non-file')
            && str_contains($err->getMessage(), 'boundary'),
        'R2: a real directory at the intent path still refuses with the non-file boundary');
}

// R3 — a SYMLINK at the intent path still refuses (symlinked-root guard).
{
    $root = fresh_root('nw_symlink');
    $stateDir = "$root/state";
    mkdir($stateDir, 0777, true);
    $intentPath = Publish::intent_path($stateDir);
    $target = "$root/target-file";
    Canon::write_file($target, "x\n");
    symlink($target, $intentPath);
    $err = null;
    try {
        Publish::intent_record($stateDir);
    } catch (\Throwable $t) {
        $err = $t;
    }
    check($err instanceof \Throwable && str_contains($err->getMessage(), 'symlink'),
        'R3: a symlink at the intent path still refuses (symlinked record root)');
}

// R4 — a MALFORMED (undecodable) record still refuses.
{
    $root = fresh_root('nw_malformed');
    $stateDir = "$root/state";
    mkdir($stateDir, 0777, true);
    $intentPath = Publish::intent_path($stateDir);
    file_put_contents($intentPath, "\x00 not canonical json at all @@@\n");
    $err = null;
    try {
        Publish::intent_record($stateDir);
    } catch (\Throwable $t) {
        $err = $t;
    }
    check($err instanceof \Throwable && str_contains($err->getMessage(), 'malformed'),
        'R4: a malformed (undecodable) record at the intent path still refuses');
}

// R5 — an UNSEALED / hash-mismatched record still refuses.
{
    $root = fresh_root('nw_unsealed');
    [$intentPath] = build_sealed_prepared_intent("$root/source");
    $stateDir = "$root/state";
    mkdir($stateDir, 0777, true);
    $tamperedPath = Publish::intent_path($stateDir);
    $decoded = Canon::decode((string) file_get_contents($intentPath));
    $decoded['record_sha256'] = str_repeat('0', 64); // present but wrong
    file_put_contents($tamperedPath, Canon::encode($decoded));
    $err = null;
    try {
        Publish::intent_record($stateDir);
    } catch (\Throwable $t) {
        $err = $t;
    }
    check($err instanceof \Throwable
            && (str_contains($err->getMessage(), 'tampered') || str_contains($err->getMessage(), 'unsealed')),
        'R5: a hash-mismatched (unsealed/tampered) record still refuses its canonical seal check');
}

// ======================================================================
// R6..R8 — CLEANUP: the write_record finally removes exactly the owned
// .tmp.<pid>.<rand> hard link, and the fresh-stat invariant it now relies on.
// ======================================================================
echo "\n== R6..R8: write_record temp cleanup + its fresh-stat invariant ==\n";

// R6 — a successful write_record leaves NO leftover temp.
{
    $root = fresh_root('cleanup_success');
    $stateDir = "$root/state";
    mkdir($stateDir, 0777, true);
    Canon::write_file("$stateDir/revision.txt", "old\n");
    $staging = Publish::stage_dir($stateDir);
    mkdir($staging, 0777, true);
    Canon::write_file("$staging/revision.txt", "candidate\n");
    $intentPath = Publish::intent_path($stateDir);
    Publish::begin_intent($stateDir, $staging); // write_record create path + finally cleanup
    check(glob($intentPath . '.tmp.*') === [], 'R6a: a successful write_record leaves no .tmp.<pid>.* hard link behind');
    check(is_file($intentPath), 'R6b: the canonical intent record is published');
    check(!file_exists($intentPath . '.next') && !file_exists($intentPath . '.previous'),
        'R6c: no transition slot artifacts are left behind');
}

// R7 — write_record fails right after creating the temp; the finally still
// removes exactly that owned temp (exercises the real finally on a live $tmp).
{
    $root = fresh_root('cleanup_fault');
    $stateDir = "$root/state";
    mkdir($stateDir, 0777, true);
    Canon::write_file("$stateDir/revision.txt", "old\n");
    $staging = Publish::stage_dir($stateDir);
    mkdir($staging, 0777, true);
    Canon::write_file("$staging/revision.txt", "candidate\n");
    $intentPath = Publish::intent_path($stateDir);
    putenv('WPRISM_TEST_MODE=1');
    putenv('WPRISM_TEST_PUBLISH_FAIL_PHASE=record-create-temp');
    $err = null;
    try {
        Publish::begin_intent($stateDir, $staging);
    } catch (\Throwable $t) {
        $err = $t;
    }
    putenv('WPRISM_TEST_PUBLISH_FAIL_PHASE');
    putenv('WPRISM_TEST_MODE');
    check($err instanceof \Throwable && str_contains($err->getMessage(), 'record-create-temp'),
        'R7a: the injected fault right after the temp is created propagates');
    check(glob($intentPath . '.tmp.*') === [],
        'R7b: the finally cleanup removes the owned temp hard link even when write_record fails after creating it');
    check(!file_exists($intentPath),
        'R7c: no canonical intent record was published by the failed write');
}

// R8 — the fresh-stat invariant the finally now relies on. A temp path whose
// stat was primed stale reads back as a non-file (so the pre-fix finally would
// SKIP the unlink and leak it); clearstatcache(true,$tmp) refreshes the
// identity so the owned temp is seen and removed. Demonstrated on the exact
// is_file() check the finally uses. (The R6/R7 success and fault paths cannot
// make THIS bite in pure PHP because file_put_contents/@link auto-clear PHP's
// own cache for $tmp in-process — the staleness only arises out-of-band.)
if ($pcntl) {
    $root = fresh_root('cleanup_stale_invariant');
    $stateDir = "$root/state";
    mkdir($stateDir, 0777, true);
    $tmp = Publish::intent_path($stateDir) . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(6));
    mkdir($tmp);

    $primed = prime_stale_via_fork(
        static function () use ($tmp): void {
            clearstatcache();
            file_exists($tmp);
            is_file($tmp); // prime: non-file
        },
        static function () use ($tmp): void {
            rmdir($tmp);
            file_put_contents($tmp, "owned-temp\n"); // now a regular file on disk
        }
    );
    check($primed, 'R8a: temp path primed stale, then replaced with a regular file behind the cache');
    $staleIsFile = is_file($tmp);       // stale -> false: the pre-fix finally would leak this
    clearstatcache(true, $tmp);
    $freshIsFile = is_file($tmp);       // fresh -> true: the fixed finally sees and removes the owned temp
    check($staleIsFile === false,
        'R8b: without a fresh stat the owned temp reads as a non-file and the pre-fix finally would leak it');
    check($freshIsFile === true,
        'R8c: clearstatcache(true,$tmp) refreshes the temp identity so the finally removes exactly the owned temp hard link');
    @unlink($tmp);
}

// ======================================================================
// R9 — PUBLICATION READBACK: a transient missing canonical read is retried
// only at the lock-held phase boundaries. Permanent absence and a same-ID
// but byte-different sealed record remain fail-closed.
// ======================================================================
echo "\n== R9: bounded publication readback retry remains exact and fail-closed ==\n";

// R9a — the first mark-commit-ready read can be transiently absent, but the
// exact sealed intent is accepted after the bounded retry.
{
    $root = fresh_root('phase_retry_ready');
    [$stateDir, $intent] = build_swapped_intent($root);
    putenv('WPRISM_TEST_MODE=1');
    putenv('WPRISM_TEST_PUBLISH_READBACK_MISS_ONCE=mark-commit-ready');
    $err = null;
    $ready = null;
    $seamConsumed = false;
    try {
        $ready = mark_commit_ready_locked($stateDir, $intent);
        $seamConsumed = getenv('WPRISM_TEST_PUBLISH_READBACK_MISS_ONCE') === false;
    } catch (\Throwable $t) {
        $err = $t;
    } finally {
        putenv('WPRISM_TEST_PUBLISH_READBACK_MISS_ONCE');
        putenv('WPRISM_TEST_MODE');
    }
    $readyOnDisk = Publish::intent_record($stateDir);
    $intentPath = Publish::intent_path($stateDir);
    check($err === null
            && is_array($ready)
            && ($ready['phase'] ?? null) === 'ready'
            && $seamConsumed
            && Canon::encode($readyOnDisk) === Canon::encode($ready)
            && !file_exists($intentPath . '.previous')
            && !file_exists($intentPath . '.next')
            && glob($intentPath . '.tmp.*') === [],
        'R9a: mark_commit_ready retries one transient missing read and publishes the exact ready record'
            . ($err ? ' (got: ' . $err->getMessage() . ')' : ''));
}

// R9b — the write_record() expected-existing revalidation has the same narrow
// retry, covering the second read inside mark_commit_ready without widening
// any other publication transition.
{
    $root = fresh_root('phase_retry_commit');
    [$stateDir, $intent] = build_swapped_intent($root);
    putenv('WPRISM_TEST_MODE=1');
    putenv('WPRISM_TEST_PUBLISH_READBACK_MISS_ONCE=mark-commit-ready-transition');
    $err = null;
    $ready = null;
    $seamConsumed = false;
    try {
        $ready = mark_commit_ready_locked($stateDir, $intent);
        $seamConsumed = getenv('WPRISM_TEST_PUBLISH_READBACK_MISS_ONCE') === false;
    } catch (\Throwable $t) {
        $err = $t;
    } finally {
        putenv('WPRISM_TEST_PUBLISH_READBACK_MISS_ONCE');
        putenv('WPRISM_TEST_MODE');
    }
    $readyOnDisk = Publish::intent_record($stateDir);
    $intentPath = Publish::intent_path($stateDir);
    check($err === null
            && is_array($ready)
            && ($ready['phase'] ?? null) === 'ready'
            && $seamConsumed
            && Canon::encode($readyOnDisk) === Canon::encode($ready)
            && !file_exists($intentPath . '.previous')
            && !file_exists($intentPath . '.next')
            && glob($intentPath . '.tmp.*') === [],
        'R9b: COMMIT-ready transition revalidation retries one transient missing read and publishes ready'
            . ($err ? ' (got: ' . $err->getMessage() . ')' : ''));
}

// R9c — a canonical record that remains absent through all attempts is still
// ambiguous and cannot be advanced.
{
    $root = fresh_root('phase_retry_missing');
    [$stateDir, $intent] = build_swapped_intent($root);
    unlink(Publish::intent_path($stateDir));
    $err = null;
    try {
        mark_commit_ready_locked($stateDir, $intent);
    } catch (\Throwable $t) {
        $err = $t;
    }
    $intentPath = Publish::intent_path($stateDir);
    check($err instanceof \Throwable
            && str_contains($err->getMessage(), 'COMMIT-ready')
            && str_contains($err->getMessage(), 'missing or changed')
            && Publish::intent_record($stateDir) === null
            && !file_exists($intentPath . '.previous')
            && !file_exists($intentPath . '.next'),
        'R9c: permanent missing intent remains a fail-closed COMMIT-ready refusal');
}

// R9d — the old ID-only check would accept this record. It has the same ID,
// but a different sealed candidate digest, so exact canonical comparison must
// refuse it before any ready transition.
{
    $root = fresh_root('phase_retry_mismatch');
    [$stateDir, $intent] = build_swapped_intent($root);
    $mismatch = $intent;
    $mismatch['candidate_sha256'] = str_repeat('a', 64);
    unset($mismatch['record_sha256']);
    $mismatch['record_sha256'] = hash('sha256', Canon::encode($mismatch));
    Canon::write_file(Publish::intent_path($stateDir), Canon::encode($mismatch));
    $err = null;
    try {
        mark_commit_ready_locked($stateDir, $intent);
    } catch (\Throwable $t) {
        $err = $t;
    }
    $mismatchOnDisk = Publish::intent_record($stateDir);
    $intentPath = Publish::intent_path($stateDir);
    check($err instanceof \Throwable
            && str_contains($err->getMessage(), 'COMMIT-ready')
            && str_contains($err->getMessage(), 'missing or changed')
            && Canon::encode($mismatchOnDisk) === Canon::encode($mismatch)
            && !file_exists($intentPath . '.previous')
            && !file_exists($intentPath . '.next'),
        'R9d: same-ID but byte-different sealed intent is refused before COMMIT-ready');
}

// ======================================================================
// R10 — every durable publication phase boundary retries one transient
// missing read, but permanent absence and same-ID authority changes remain
// fail-closed. The production seam is deliberately one-shot: a success must
// consume it and leave no fixed transition slot or temporary hard link.
// ======================================================================
echo "\n== R10: every publication phase readback boundary is bounded and exact ==\n";

// R10a/b — the post-swap marker and its expected-existing transition each
// retry one transient missing canonical intent read.
foreach ([
    ['mark-swapped', 'R10a'],
    ['mark-swapped-transition', 'R10b'],
] as [$scope, $case]) {
    $root = fresh_root('phase_retry_' . str_replace('-', '_', $scope));
    [$stateDir, $intent] = build_prepared_swapped_intent($root);
    [$swapped, $error, $consumed] = run_readback_miss(
        $scope,
        static fn() => Publish::mark_swapped($stateDir, $intent)
    );
    check(
        $error === null && $consumed && is_array($swapped)
            && ($swapped['phase'] ?? null) === 'swapped'
            && Canon::encode(Publish::intent_record($stateDir)) === Canon::encode($swapped),
        "$case: $scope retries one transient missing read and publishes exact swapped intent"
            . ($error ? ' (got: ' . $error->getMessage() . ')' : '')
    );
    record_slots_clean($stateDir, "$case: $scope");
}

// R10c — a permanently missing intent remains a refusal before any phase
// transition and keeps both candidate and retained backup evidence.
{
    $root = fresh_root('phase_retry_swapped_missing');
    [$stateDir, $intent] = build_prepared_swapped_intent($root);
    unlink(Publish::intent_path($stateDir));
    $error = null;
    try {
        Publish::mark_swapped($stateDir, $intent);
    } catch (Throwable $t) {
        $error = $t;
    }
    check(
        $error instanceof Throwable && str_contains($error->getMessage(), 'swap completed')
            && is_dir($stateDir) && is_dir(Publish::backup_dir($stateDir)),
        'R10c: mark-swapped permanent intent absence refuses and preserves candidate/backup'
            . ($error ? ' (got: ' . $error->getMessage() . ')' : '')
    );
}

// R10d — ID equality alone is not authority. A sealed same-ID intent with a
// changed candidate digest must not be advanced to swapped.
{
    $root = fresh_root('phase_retry_swapped_mismatch');
    [$stateDir, $intent] = build_prepared_swapped_intent($root);
    $changed = rewrite_intent_field($stateDir, $intent, 'candidate_sha256');
    $error = null;
    try {
        Publish::mark_swapped($stateDir, $intent);
    } catch (Throwable $t) {
        $error = $t;
    }
    check(
        $error instanceof Throwable && str_contains($error->getMessage(), 'swap completed')
            && Canon::encode(Publish::intent_record($stateDir)) === Canon::encode($changed)
            && is_dir(Publish::backup_dir($stateDir)),
        'R10d: mark-swapped same-ID byte-different intent refuses before transition'
            . ($error ? ' (got: ' . $error->getMessage() . ')' : '')
    );
}

// R10e/f — the pre-COMMIT marker and its transition revalidation have the
// same bounded missing-read contract.
foreach ([
    ['mark-committing', 'R10e'],
    ['mark-committing-transition', 'R10f'],
] as [$scope, $case]) {
    $root = fresh_root('phase_retry_' . str_replace('-', '_', $scope));
    [$stateDir, $intent] = build_prepared_swapped_intent($root);
    $intent = Publish::mark_swapped($stateDir, $intent);
    $intent = Publish::mark_commit_ready($stateDir, $intent);
    [$committing, $error, $consumed] = run_readback_miss(
        $scope,
        static fn() => Publish::mark_committing($stateDir, $intent)
    );
    check(
        $error === null && $consumed && is_array($committing)
            && ($committing['phase'] ?? null) === 'committing'
            && Canon::encode(Publish::intent_record($stateDir)) === Canon::encode($committing),
        "$case: $scope retries one transient missing read and publishes exact committing intent"
            . ($error ? ' (got: ' . $error->getMessage() . ')' : '')
    );
    record_slots_clean($stateDir, "$case: $scope");
}

// R10g/h — permanent absence and same-ID authority drift cannot cross the
// COMMIT-attempt boundary.
{
    $root = fresh_root('phase_retry_committing_missing');
    [$stateDir, $intent] = build_prepared_swapped_intent($root);
    $intent = Publish::mark_swapped($stateDir, $intent);
    $intent = Publish::mark_commit_ready($stateDir, $intent);
    unlink(Publish::intent_path($stateDir));
    $error = null;
    try {
        Publish::mark_committing($stateDir, $intent);
    } catch (Throwable $t) {
        $error = $t;
    }
    check(
        $error instanceof Throwable && str_contains($error->getMessage(), 'COMMIT-attempted')
            && is_dir($stateDir) && is_dir(Publish::backup_dir($stateDir)),
        'R10g: mark-committing permanent intent absence refuses and preserves candidate/backup'
            . ($error ? ' (got: ' . $error->getMessage() . ')' : '')
    );

    $root = fresh_root('phase_retry_committing_mismatch');
    [$stateDir, $intent] = build_prepared_swapped_intent($root);
    $intent = Publish::mark_swapped($stateDir, $intent);
    $intent = Publish::mark_commit_ready($stateDir, $intent);
    $changed = rewrite_intent_field($stateDir, $intent, 'candidate_sha256');
    $error = null;
    try {
        Publish::mark_committing($stateDir, $intent);
    } catch (Throwable $t) {
        $error = $t;
    }
    check(
        $error instanceof Throwable && str_contains($error->getMessage(), 'COMMIT-attempted')
            && Canon::encode(Publish::intent_record($stateDir)) === Canon::encode($changed)
            && is_dir(Publish::backup_dir($stateDir)),
        'R10h: mark-committing same-ID byte-different intent refuses before COMMIT'
            . ($error ? ' (got: ' . $error->getMessage() . ')' : '')
    );
}

// R10i — receipt publication must bind the exact committing intent, retrying
// one transient read but refusing absence or a same-ID changed authority.
{
    $root = fresh_root('phase_retry_receipt_intent');
    [$stateDir, $intent] = build_committing_intent($root);
    [$receipt, $error, $consumed] = run_readback_miss(
        'write-receipt-intent',
        static fn() => Publish::write_receipt($stateDir, $intent)
    );
    check(
        $error === null && $consumed && is_array($receipt)
            && ($receipt['intent_id'] ?? null) === ($intent['id'] ?? null)
            && Canon::encode(Publish::receipt_record($stateDir)) === Canon::encode($receipt),
        'R10i: write-receipt-intent retries one transient missing read and binds exact receipt'
            . ($error ? ' (got: ' . $error->getMessage() . ')' : '')
    );
    record_slots_clean($stateDir, 'R10i: write-receipt-intent');

    $root = fresh_root('phase_retry_receipt_missing');
    [$stateDir, $intent] = build_committing_intent($root);
    unlink(Publish::intent_path($stateDir));
    $error = null;
    try {
        Publish::write_receipt($stateDir, $intent);
    } catch (Throwable $t) {
        $error = $t;
    }
    check(
        $error instanceof Throwable && str_contains($error->getMessage(), 'write its receipt')
            && !is_file(Publish::receipt_path($stateDir)) && is_dir(Publish::backup_dir($stateDir)),
        'R10j: write-receipt-intent permanent absence refuses and preserves backup'
    );

    $root = fresh_root('phase_retry_receipt_mismatch');
    [$stateDir, $intent] = build_committing_intent($root);
    $changed = rewrite_intent_field($stateDir, $intent, 'candidate_sha256');
    $error = null;
    try {
        Publish::write_receipt($stateDir, $intent);
    } catch (Throwable $t) {
        $error = $t;
    }
    check(
        $error instanceof Throwable
            && str_contains($error->getMessage(), 'durable intent is missing or changed')
            && !str_contains($error->getMessage(), 'published state')
            && Canon::encode(Publish::intent_record($stateDir)) === Canon::encode($changed)
            && !is_file(Publish::receipt_path($stateDir)) && is_dir(Publish::backup_dir($stateDir)),
        'R10k: write-receipt-intent same-ID byte-different intent refuses before receipt'
    );
}

// R10l/m — cleanup must retry a transient intent read, but a changed intent
// cannot authorize deletion of the retained backup/staging evidence.
{
    $root = fresh_root('phase_retry_cleanup_intent');
    [$stateDir, $intent] = build_committing_intent($root);
    $receipt = Publish::write_receipt($stateDir, $intent);
    [$ignored, $error, $consumed] = run_readback_miss(
        'cleanup-committed-intent',
        static fn() => Publish::cleanup_committed($stateDir, $receipt)
    );
    check(
        $error === null && $consumed && !is_file(Publish::intent_path($stateDir))
            && !is_dir(Publish::backup_dir($stateDir)),
        'R10l: cleanup-committed-intent retries one transient missing read and finalizes cleanup'
            . ($error ? ' (got: ' . $error->getMessage() . ')' : '')
    );
    record_slots_clean($stateDir, 'R10l: cleanup-committed-intent');

    $root = fresh_root('phase_retry_cleanup_changed');
    [$stateDir, $intent] = build_committing_intent($root);
    $receipt = Publish::write_receipt($stateDir, $intent);
    $changed = rewrite_intent_field($stateDir, $intent, 'candidate_sha256');
    $error = null;
    try {
        Publish::cleanup_committed($stateDir, $receipt);
    } catch (Throwable $t) {
        $error = $t;
    }
    check(
        $error instanceof Throwable && str_contains($error->getMessage(), 'intent')
            && Canon::encode(Publish::intent_record($stateDir)) === Canon::encode($changed)
            && is_dir(Publish::backup_dir($stateDir)) && is_file(Publish::receipt_path($stateDir)),
        'R10m: cleanup-committed changed intent authority refuses and preserves backup/intent/receipt'
            . ($error ? ' (got: ' . $error->getMessage() . ')' : '')
    );
}

echo "\n";
if ($failures === 0) {
    echo "ALL PASSED\n";
    exit(0);
}
echo "FAILED: $failures check(s)\n";
exit(1);
