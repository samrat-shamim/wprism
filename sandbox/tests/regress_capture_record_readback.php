<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression for DUO-3422:
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
 * The fix (agent/src/PublicationJournal.php): read_record() uses a fresh lstat regular-file
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

$repoRoot = dirname(__DIR__, 2);
require "$repoRoot/agent/src/Canon.php";
require "$repoRoot/agent/src/Publish.php";
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

use Duo\Canon;
use Duo\Publish;

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
    $root = sys_get_temp_dir() . '/duo_regress_record_readback_' . $label . '_' . bin2hex(random_bytes(4));
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
    $realEngine = "$realSrc/PublicationJournal.php";
    $realFacade = "$realSrc/Publish.php";

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
    $mutantEngine = "$mutantSrc/PublicationJournal.php";
    $mutantFacade = "$mutantSrc/Publish.php";

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
            throw new \RuntimeException("duo: refusing to operate on symlinked $label record root $path");
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
use Duo\Canon;
use Duo\Publish;

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
    putenv('DUO_TEST_MODE=1');
    putenv('DUO_TEST_PUBLISH_FAIL_PHASE=record-create-temp');
    $err = null;
    try {
        Publish::begin_intent($stateDir, $staging);
    } catch (\Throwable $t) {
        $err = $t;
    }
    putenv('DUO_TEST_PUBLISH_FAIL_PHASE');
    putenv('DUO_TEST_MODE');
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

echo "\n";
if ($failures === 0) {
    echo "ALL PASSED\n";
    exit(0);
}
echo "FAILED: $failures check(s)\n";
exit(1);
