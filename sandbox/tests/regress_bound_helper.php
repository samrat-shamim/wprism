<?php
// Offline regression for DUO-3425's persistent inode-bound filesystem helper
// (Publish\BoundHelper). Pure filesystem + subprocess, zero WordPress/docker --
// the same "real code against real temp dirs" idiom as regress_init_contract.php.
//
// The helper keeps run_bound_operation's per-op CWD-as-inode-capability
// guarantee while spawning ONE subprocess for a whole staging walk. This suite
// drives one helper through framed mkdir/write/copy, proves per-op isolation
// across distinct parent inodes, and exercises every refusal / fail-closed path
// with the EXACT reason strings and the EXACT InitialStateBoundaryException.
//
// Two mandatory items are MUTATION-BITTEN here: removing clearstatcache(true)
// lets a stale '.' stat satisfy the identity check against the WRONG inode
// (item 1), and removing exit(73) turns a refusal into report-and-continue
// (item 4). Each mutation is applied to a copy of BoundHelper::loop_script()
// and shown to change the observed behavior, so the check FAILS if either line
// is ever dropped.

declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/Publish.php';

use Duo\BoundHelper;
use Duo\InitialStateBoundaryException;
use Duo\Publish;

function fail(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function check(bool $ok, string $message): void {
    if (!$ok) fail($message);
    echo "ok: $message\n";
}

/** @var list<string> $cleanupDirs */
$cleanupDirs = [];
function make_dir(string $label): string {
    global $cleanupDirs;
    $path = sys_get_temp_dir() . '/duo-bound-' . $label . '-' . bin2hex(random_bytes(6));
    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        fail("could not create temp dir $path");
    }
    $cleanupDirs[] = $path;
    return $path;
}
function rrmdir(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        rrmdir($path . '/' . $entry);
    }
    @rmdir($path);
}
register_shutdown_function(static function () use (&$cleanupDirs): void {
    foreach ($cleanupDirs as $dir) { rrmdir($dir); }
});

/** @return array{dev:string,ino:string,type:string} */
function dir_ident(string $path): array {
    clearstatcache(true);
    $s = lstat($path);
    if (!is_array($s)) fail("could not stat $path");
    return ['type' => 'directory', 'dev' => (string) $s['dev'], 'ino' => (string) $s['ino']];
}

function inode_of(string $path): string {
    clearstatcache(true);
    $s = lstat($path);
    if (!is_array($s)) fail("could not stat $path");
    return (string) $s['dev'] . ':' . (string) $s['ino'];
}

// -- raw subprocess driver (bypasses BoundHelper so the framing itself and
// mutated loop scripts can be tested directly) -------------------------------

/** @return array{0:resource,1:array<int,resource>} */
function raw_spawn(string $script): array {
    $pipes = [];
    $proc = proc_open(
        [PHP_BINARY, '-r', $script],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) fail('could not spawn raw helper subprocess');
    return [$proc, $pipes];
}

/** @param array<int,resource> $pipes */
function raw_write(array $pipes, string $data): void {
    while ($data !== '') {
        $n = @fwrite($pipes[0], $data);
        if (!is_int($n) || $n < 1) return; // EPIPE: peer died; caller inspects
        $data = substr($data, $n);
    }
}

/** @param array<string,mixed> $request @param array<int,resource> $pipes */
function raw_send(array $pipes, array $request, string $bytes): void {
    raw_write($pipes, json_encode($request, JSON_UNESCAPED_SLASHES) . "\n" . $bytes);
}

/** @param array<int,resource> $pipes */
function raw_read_line(array $pipes): string|false {
    return fgets($pipes[1]);
}

/**
 * Close stdin (any still-live helper hits EOF and exits 0), drain stdout/stderr
 * to EOF, and reap the exit code.
 * @param array<int,resource> $pipes
 * @return array{stdout:string,stderr:string,exit:int}
 */
function raw_teardown($proc, array $pipes): array {
    if (is_resource($pipes[0])) fclose($pipes[0]);
    $out = is_resource($pipes[1]) ? (string) stream_get_contents($pipes[1]) : '';
    $err = is_resource($pipes[2]) ? (string) stream_get_contents($pipes[2]) : '';
    if (is_resource($pipes[1])) fclose($pipes[1]);
    if (is_resource($pipes[2])) fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['stdout' => $out, 'stderr' => $err, 'exit' => $exit];
}

$script = BoundHelper::loop_script();

// ===========================================================================
echo "== structure: the five mandatory items are present in loop_script ==\n";
// Item 1: clearstatcache(true) top-of-op. Item 2: identity read on '.', never
// $path. Item 3: bytes_len write terminator + premature-EOF fail. Item 4:
// exit(73) death. Item 5: verbatim reason strings.
check(str_contains($script, 'clearstatcache(true);'), 'item 1: clearstatcache(true) is present in the loop');
check(str_contains($script, "@lstat('.')") && str_contains($script, "is_dir('.')"),
    "item 2: the parent identity is read from '.' (the held CWD inode)");
check(!str_contains($script, 'lstat($path)') && !str_contains($script, 'stat($path)'),
    'item 2: the loop never stats the lexical $path for identity');
check(str_contains($script, '$remaining = $bytesLen;')
    && str_contains($script, "\$chunk === '' && feof(\$input)"),
    'item 3: the write terminator is bytes_len with premature-EOF fail-closed');
check(str_contains($script, 'exit(73)'), 'item 4: a refusal ends in exit(73)');
foreach ([
    'invalid request', 'unsafe request', 'parent identity changed',
    'fresh directory boundary changed', 'fresh directory changed after creation',
    'unsupported operation', 'copy source changed', 'copy source unreadable',
    'fresh file boundary changed', 'fresh file write failed',
    'fresh file name changed after creation', 'copy source digest changed',
    'parent path unavailable',
] as $reason) {
    check(str_contains($script, "'$reason'"), "item 5: reason string is present verbatim: $reason");
}

// ===========================================================================
echo "\n== happy path: one helper drives mkdir/write/copy into DISTINCT parent inodes ==\n";
$dirA = make_dir('a');
$dirB = make_dir('b');
$dirC = make_dir('c');
check(inode_of($dirA) !== inode_of($dirB) && inode_of($dirB) !== inode_of($dirC),
    'the three op parents are distinct inodes (per-op chdir must re-target each)');

$helper = new BoundHelper();

// op 1: mkdir in dirA
$idA = Publish::create_directory_fresh($dirA, dir_ident($dirA), 'sub', 0775, 'unit mkdir', $helper);
check(is_dir($dirA . '/sub') && !is_dir($dirB . '/sub'),
    'op 1 mkdir landed in dirA only');
check($idA['type'] === 'directory' && $idA['dev'] . ':' . $idA['ino'] === inode_of($dirA . '/sub'),
    'op 1 returns the created directory inode');

// op 2: write in dirB (a DIFFERENT parent inode than op 1)
$payload = str_repeat('duo-3425-', 4096) . "\x00\x01\x02binary-tail";
$idB = Publish::write_file_fresh($dirB . '/file.bin', $payload, 'unit write', dir_ident($dirB), $helper);
check(is_file($dirB . '/file.bin') && file_get_contents($dirB . '/file.bin') === $payload,
    'op 2 write landed the exact bytes in dirB (a different inode than op 1)');
check($idB['sha256'] === hash('sha256', $payload), 'op 2 returns the sha256 of the written bytes');

// op 3: copy in dirC (a THIRD parent inode)
$srcDir = make_dir('src');
$src = $srcDir . '/origin.bin';
$srcBytes = random_bytes(70000);
file_put_contents($src, $srcBytes);
$idC = Publish::copy_file_fresh($src, $dirC . '/copy.bin', hash('sha256', $srcBytes), 0644, 'unit copy', dir_ident($dirC), $helper);
check(is_file($dirC . '/copy.bin') && file_get_contents($dirC . '/copy.bin') === $srcBytes,
    'op 3 copy reproduced the source bytes in dirC (source read from the FILE, not the stream)');
check($idC['sha256'] === hash('sha256', $srcBytes), 'op 3 returns the sha256 of the copied bytes');

// op 4: another mkdir back in dirA proves the helper survives and re-targets.
Publish::create_directory_fresh($dirA, dir_ident($dirA), 'sub2', 0775, 'unit mkdir 2', $helper);
check(is_dir($dirA . '/sub2'), 'op 4 mkdir re-targeted dirA after ops in dirB/dirC');
$helper->close();

// ===========================================================================
echo "\n== item 1 MUTATION BITE: clearstatcache(true) defeats the stale-'.' hazard ==\n";
// op 1 legitimately operates in dirReal, caching the resolution of the literal
// path '.'. op 2 chdirs into dirDecoy but its request CLAIMS the parent is
// still dirReal. With clearstatcache the helper re-resolves and re-stats '.'
// (== dirDecoy) and REFUSES (identity mismatch). Without it, on a platform that
// caches the '.' -> cwd resolution, lstat('.') returns the STALE dirReal inode:
// the identity check passes against the WRONG inode and the op executes inside
// dirDecoy -- a redirect. clearstatcache(true) (which also drops the realpath
// cache) at the TOP of every op is the load-bearing defense.
//
// PLATFORM NOTE: PHP's '.'-across-chdir staleness is real on Linux (production
// and CI) but not on every libc/PHP build -- this box (Darwin PHP) re-resolves
// '.' fresh and never populates the realpath cache, so removing clearstatcache
// is runtime-invisible HERE. The suite therefore (a) ALWAYS asserts the source
// still contains the line and that the real script refuses the swap on every
// platform, and (b) probes the exact hazard and, WHERE it reproduces (Linux/CI),
// REQUIRES the mutant to accept the swap -- a hard, non-vacuous runtime bite.
$dirReal = make_dir('real');
$dirDecoy = make_dir('decoy');
check(inode_of($dirReal) !== inode_of($dirDecoy), 'real and decoy parents are distinct inodes');

$staleScenario = static function (string $scriptText) use ($dirReal, $dirDecoy): array {
    // Clean any prior 'a'/'b' so mkdir create-if-absent is deterministic.
    @rmdir($dirDecoy . '/b');
    @rmdir($dirReal . '/a');
    [$proc, $pipes] = raw_spawn($scriptText);
    // op 1: real mkdir 'a' in dirReal -> resolves/caches '.' = dirReal.
    raw_send($pipes, [
        'op' => 'mkdir', 'name' => 'a', 'mode' => 0777,
        'parent' => dir_ident($dirReal), 'path' => $dirReal, 'bytes_len' => 0,
    ], '');
    $r1 = raw_read_line($pipes);
    // op 2: chdir dirDecoy but CLAIM the parent identity is still dirReal.
    raw_send($pipes, [
        'op' => 'mkdir', 'name' => 'b', 'mode' => 0777,
        'parent' => dir_ident($dirReal), 'path' => $dirDecoy, 'bytes_len' => 0,
    ], '');
    $r2 = raw_read_line($pipes);
    $t = raw_teardown($proc, $pipes);
    return ['r1' => $r1, 'r2' => $r2, 'teardown' => $t];
};

// (a) The real script refuses the swap on EVERY platform, and the mandatory
// line must be present in the source (removing it fails the suite everywhere).
$real = $staleScenario($script);
check(is_string($real['r1']), 'stale-hazard op 1 (real script) succeeds in dirReal');
check($real['r2'] === false, 'stale-hazard op 2 (real script) produces NO success frame');
check(str_contains($real['teardown']['stderr'], 'parent identity changed') && $real['teardown']['exit'] === 73,
    "real script REFUSES the swapped op: dies with 'parent identity changed' (exit 73)");
check(!is_dir($dirDecoy . '/b'), 'real script did not create the op-2 name inside the decoy');
$mutantNoCsc = str_replace('clearstatcache(true);', '', $script);
check($mutantNoCsc !== $script && !str_contains($mutantNoCsc, 'clearstatcache(true)'),
    'the clearstatcache mutation removed the line from the loop (structural guard)');

// (b) Probe whether THIS platform caches '.' across chdir the way the loop
// would experience it (identity stat on '.', then a $name stat, then re-enter).
$probeDotHazard = static function (): bool {
    $r = sys_get_temp_dir() . '/duo-dothz-' . bin2hex(random_bytes(6));
    if (!@mkdir($r) || !@mkdir("$r/a") || !@mkdir("$r/b")) { rrmdir($r); return false; }
    $base = getcwd();
    chdir($base); chdir("$r/a");
    lstat('.'); is_dir('.');
    @mkdir('x'); @lstat('x'); @is_link('x'); @is_dir('x'); // op body stats $name
    chdir($base); chdir("$r/b");
    $seen = lstat('.'); is_dir('.');
    chdir($base);
    $refA = lstat("$r/a");
    $stale = is_array($seen) && is_array($refA) && (string) $seen['ino'] === (string) $refA['ino'];
    rrmdir($r);
    return $stale; // true == a stale '.' would fool the identity check here
};
$mutated = $staleScenario($mutantNoCsc);
if ($probeDotHazard()) {
    check(is_string($mutated['r2']) && is_dir($dirDecoy . '/b'),
        "MUTATION BITE: on this stat-caching platform the MUTANT ACCEPTS the swap "
        . "(stale '.' matched the WRONG inode) -> clearstatcache is load-bearing");
} else {
    check($mutated['r2'] === false && !is_dir($dirDecoy . '/b'),
        "this platform re-resolves '.' fresh, so the mutant also refuses here "
        . "(runtime accept-bite runs on Linux/CI; structural guard covers this box)");
}
@rmdir($dirDecoy . '/b');
@rmdir($dirReal . '/a');

// ===========================================================================
echo "\n== item 4 MUTATION BITE: a refusal DIES (exit 73), never report-and-continue ==\n";
// Feed a malformed header. The real $fail writes STDERR and exit(73): the
// process is dead, so it can serve no further op. Remove exit(73) and $fail
// falls through (report-and-continue), so the process does NOT die with 73 --
// the bite that proves exit(73) is load-bearing for the fail-closed contract.
$dieScenario = static function (string $scriptText): array {
    [$proc, $pipes] = raw_spawn($scriptText);
    raw_write($pipes, "not-json\n");
    $line = raw_read_line($pipes);
    $t = raw_teardown($proc, $pipes);
    return ['line' => $line, 'teardown' => $t];
};
$realDie = $dieScenario($script);
check($realDie['line'] === false, 'malformed op (real script) yields no success frame');
check(str_contains($realDie['teardown']['stderr'], 'invalid request') && $realDie['teardown']['exit'] === 73,
    "real script DIES on the malformed op: 'invalid request', exit 73");

$mutantNoExit = str_replace('exit(73);', ';', $script);
check($mutantNoExit !== $script && !str_contains($mutantNoExit, 'exit(73)'),
    'die-on-failure mutation removed exit(73) from $fail');
$mutantDie = $dieScenario($mutantNoExit);
check($mutantDie['teardown']['exit'] !== 73,
    'MUTANT does NOT die with exit 73 on the same malformed op -> exit(73) is load-bearing');

// ===========================================================================
echo "\n== item 3: the write terminator is bytes_len, not EOF (short AND long) ==\n";
$wdir = make_dir('write-frame');

// SHORT: bytes_len promises 100 but only 40 bytes arrive before EOF. The helper
// blocks draining the payload until raw_teardown() closes stdin, which surfaces
// the premature EOF -> fail-closed, never a short-but-"valid" file. (Reading a
// response here would deadlock, which is itself the point: bytes_len, not EOF,
// terminates the write.)
[$procS, $pipesS] = raw_spawn($script);
raw_write($pipesS, json_encode([
    'op' => 'write', 'name' => 'short.bin', 'mode' => 0666,
    'parent' => dir_ident($wdir), 'path' => $wdir, 'bytes_len' => 100,
], JSON_UNESCAPED_SLASHES) . "\n" . str_repeat('A', 40));
$tShort = raw_teardown($procS, $pipesS);
check($tShort['stdout'] === '', 'short payload yields no success frame');
check(str_contains($tShort['stderr'], 'fresh file write failed') && $tShort['exit'] === 73,
    "premature EOF is fail-closed: 'fresh file write failed', exit 73");
check(!is_file($wdir . '/short.bin'), 'the truncated write left no file behind');

// LONG: bytes_len says 40 but 40 + trailing garbage arrive. The helper writes
// EXACTLY 40 bytes (proving bytes_len, not EOF, terminates the write) and the
// leftover desyncs the next header read into a fail-closed death.
[$procL, $pipesL] = raw_spawn($script);
raw_write($pipesL, json_encode([
    'op' => 'write', 'name' => 'long.bin', 'mode' => 0666,
    'parent' => dir_ident($wdir), 'path' => $wdir, 'bytes_len' => 40,
], JSON_UNESCAPED_SLASHES) . "\n" . str_repeat('A', 40) . "leftover-not-json\n");
$longLine = raw_read_line($pipesL); // op-1 success frame
$tLong = raw_teardown($procL, $pipesL);
check(is_string($longLine) && is_array(json_decode($longLine, true)), 'long payload op 1 still succeeds');
check(is_file($wdir . '/long.bin') && filesize($wdir . '/long.bin') === 40
    && file_get_contents($wdir . '/long.bin') === str_repeat('A', 40),
    'the write consumed EXACTLY bytes_len (40) bytes -- not the trailing bytes');
check(str_contains($tLong['stderr'], 'invalid request') && $tLong['exit'] === 73,
    'the leftover bytes desync the next header and die fail-closed');

// ===========================================================================
echo "\n== refusal surface via BoundHelper: EXACT exception, helper poisoned (no reuse) ==\n";
$rdir = make_dir('refuse');

// Each refusal: BoundHelper::run throws the identical InitialStateBoundaryException
// and poisons the handle. A second run() on the poisoned handle must refuse
// rather than silently spawn a replacement -- "never reuse an anomalous helper".
$expectRefusal = static function (callable $op, string $needle, string $label): void {
    try {
        $op();
        fail("$label: expected a refusal but none was thrown");
    } catch (InitialStateBoundaryException $e) {
        check(str_contains($e->getMessage(), 'refused at its inode-bound parent'),
            "$label: throws the exact inode-bound-parent exception");
        check(str_contains($e->getMessage(), $needle),
            "$label: the exact reason survives: $needle");
    }
};

// unsafe name
$hUnsafe = new BoundHelper();
$expectRefusal(
    static fn() => $hUnsafe->run($rdir, ['op' => 'mkdir', 'name' => '..', 'mode' => 0777, 'parent' => dir_ident($rdir)], '', 'unsafe name'),
    'unsafe request', 'unsafe name'
);
try {
    $hUnsafe->run($rdir, ['op' => 'mkdir', 'name' => 'ok', 'mode' => 0777, 'parent' => dir_ident($rdir)], '', 'reuse');
    fail('a poisoned helper served another op (it was reused)');
} catch (InitialStateBoundaryException $e) {
    check(str_contains($e->getMessage(), 'cannot reuse a torn-down bound-filesystem helper'),
        'the poisoned helper refuses reuse instead of spawning a replacement');
}
check(!is_dir($rdir . '/ok'), 'no op ran on the poisoned helper');

// wrong parent identity
$hWrongParent = new BoundHelper();
$expectRefusal(
    static fn() => $hWrongParent->run($rdir, ['op' => 'mkdir', 'name' => 'x', 'mode' => 0777, 'parent' => ['type' => 'directory', 'dev' => '424242', 'ino' => '424242']], '', 'wrong parent'),
    'parent identity changed', 'wrong parent identity'
);

// boundary-present (mkdir where the name already exists)
mkdir($rdir . '/present', 0777);
$hPresent = new BoundHelper();
$expectRefusal(
    static fn() => $hPresent->run($rdir, ['op' => 'mkdir', 'name' => 'present', 'mode' => 0777, 'parent' => dir_ident($rdir)], '', 'boundary present'),
    'fresh directory boundary changed', 'boundary present'
);

// wrong copy digest -> the helper removes only the destination inode it created
$digestSrcDir = make_dir('digest-src');
$digestSrc = $digestSrcDir . '/s.bin';
file_put_contents($digestSrc, 'real-bytes');
$hDigest = new BoundHelper();
$expectRefusal(
    static fn() => $hDigest->run($rdir, ['op' => 'copy', 'name' => 'c.bin', 'mode' => 0644, 'parent' => dir_ident($rdir), 'source' => $digestSrc, 'sha256' => str_repeat('0', 64)], '', 'copy digest'),
    'copy source digest changed', 'wrong copy digest'
);
check(!is_file($rdir . '/c.bin') && !is_link($rdir . '/c.bin'),
    'the digest-refused copy removed only the destination it created');

// ===========================================================================
echo "\n== no fd leak across thousands of ops on one helper ==\n";
function fd_count(): ?int {
    foreach (['/dev/fd', '/proc/self/fd'] as $dir) {
        if (is_dir($dir)) {
            $entries = @scandir($dir);
            if (is_array($entries)) {
                return count(array_filter($entries, static fn($e) => $e !== '.' && $e !== '..'));
            }
        }
    }
    return null;
}
$leakDir = make_dir('leak');
$leakHelper = new BoundHelper();
$OPS = 2000;
$before = null;
for ($i = 0; $i < $OPS; $i++) {
    Publish::create_directory_fresh($leakDir, dir_ident($leakDir), 'd' . $i, 0775, 'leak op', $leakHelper);
    if ($i === 25) { $before = fd_count(); } // measure after warmup
}
$after = fd_count();
check(is_dir($leakDir . '/d0') && is_dir($leakDir . '/d' . ($OPS - 1)),
    "all $OPS ops on the single helper landed");
if ($before === null || $after === null) {
    echo "note: /dev/fd unavailable; fd-count leak assertion skipped on this platform\n";
} else {
    check($after - $before <= 4,
        "parent fd count is stable across $OPS ops ($before -> $after; a per-op leak would grow by ~$OPS)");
}
$leakHelper->close();

// ===========================================================================
echo "\n== throughput microbench: N ops via one helper vs N fresh spawns ==\n";
// Offline proxy for the removed per-op process-spawn overhead (not the real
// tree). No helper -> create_directory_fresh spawns run_bound_operation once
// per op; one helper -> a single subprocess for all N.
$N = 60;
$spawnDir = make_dir('spawn');
$t0 = microtime(true);
for ($i = 0; $i < $N; $i++) {
    Publish::create_directory_fresh($spawnDir, dir_ident($spawnDir), 's' . $i, 0775, 'spawn op'); // no helper: N spawns
}
$spawnTime = microtime(true) - $t0;
$spawnMade = is_dir($spawnDir . '/s0') && is_dir($spawnDir . '/s' . ($N - 1));

$reuseDir = make_dir('reuse');
$reuseHelper = new BoundHelper();
$t1 = microtime(true);
for ($i = 0; $i < $N; $i++) {
    Publish::create_directory_fresh($reuseDir, dir_ident($reuseDir), 'r' . $i, 0775, 'reuse op', $reuseHelper);
}
$reuseTime = microtime(true) - $t1;
$reuseHelper->close();
$reuseMade = is_dir($reuseDir . '/r0') && is_dir($reuseDir . '/r' . ($N - 1));

check($spawnMade && $reuseMade, "both paths created all $N directories");
printf("microbench: %d ops -- %d spawns %.3fs vs one helper %.3fs (%.1fx)\n",
    $N, $N, $spawnTime, $reuseTime, $reuseTime > 0 ? $spawnTime / $reuseTime : 0.0);
check($reuseTime < $spawnTime,
    'the single-helper walk is faster than N fresh spawns (per-op spawn overhead removed)');

echo "\nREGRESS_BOUND_HELPER PASSED\n";
