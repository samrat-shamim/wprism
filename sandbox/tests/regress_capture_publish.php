<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * DUO-3213: capture's atomic tree publication.
 *
 * agent/src/Publish.php is the filesystem half of this issue's fix and was
 * deliberately written with ZERO WordPress/$wpdb dependency (see its own
 * docblock) specifically so every guarantee it makes — the capture lock,
 * the staging directory, the atomic two-step rename swap, and deterministic
 * crash recovery — is provable here, on real files, with no live database
 * or WordPress install involved. Runs the REAL, unmodified
 * agent/src/{Canon,Publish}.php, plus a Reflection-based check of Capture's
 * private check_transient_db_error() compatibility facade, backed by the
 * extracted CaptureTransaction service (its only piece needing a DB stub).
 *
 * The DB-side half of DUO-3213 (the consistent-snapshot transaction, the
 * InnoDB engine check, and the deadlock/lock-wait-timeout retry actually
 * firing against real contention) is NOT exercised here — that needs a
 * live MySQL/MariaDB and is covered by the sandbox pair test instead (see
 * the DUO-3213 PR body for that evidence). This file's job is the
 * filesystem guarantee: a crash/disk-full/kill at any point before the
 * final swap must never touch the previously-published tree.
 *
 * DUO-3236 addendum (P8 below): agent/src/RepositoryCompiler.php's own
 * docblock confirms it too is target-DB-free, so the new staged-candidate
 * compile gate Capture::run() now performs before Publish::swap() is
 * provable here as well — no docker, no WordPress bootstrap needed for it
 * either. Dependency list mirrors sandbox/tests/regress_repository_compiler.sh
 * exactly (that file already established this exact offline-testability
 * precedent for RepositoryCompiler on its own).
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

// DUO-3442: Capture owns a direct Canary dependency. Keep this probe in a
// fresh PHP process so the parent harness cannot accidentally preload Canary
// through duo.php or another fixture. The call deliberately stops at the
// next legitimate dependency wall in this WordPress-free harness; the
// regression is that Canary must not be the first failure.
$captureStandalone = __DIR__ . '/../../agent/src/Capture.php';
$probeCode = 'require_once ' . var_export($captureStandalone, true) . ';'
    . 'if (!class_exists("Duo\\\\Canary", false)) {'
    . ' fwrite(STDERR, "Capture.php did not load Duo\\\\Canary\\n"); exit(2);'
    . '}'
    . 'try { Duo\\Capture::snapshot("/tmp/duo-capture-canary-probe"); }'
    . ' catch (Throwable $e) {'
    . ' if (strpos($e->getMessage(), "Canary") !== false) {'
    . '  fwrite(STDERR, "Capture reached a Canary class failure: " . $e->getMessage() . "\\n"); exit(3);'
    . ' }'
    . ' exit(0);'
    . '}'
    . 'exit(0);';
$probe = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $probeCode], [
    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
], $probePipes);
if (!is_resource($probe)) {
    fwrite(STDERR, "FAIL: could not start Capture standalone-load probe\n");
    exit(1);
}
fclose($probePipes[0]);
$probeStdout = stream_get_contents($probePipes[1]);
$probeStderr = stream_get_contents($probePipes[2]);
fclose($probePipes[1]);
fclose($probePipes[2]);
$probeExit = proc_close($probe);
if ($probeExit !== 0) {
    fwrite(STDERR, "FAIL: Capture standalone-load probe exited $probeExit: " . trim($probeStderr . $probeStdout) . "\n");
    exit(1);
}
fwrite(STDOUT, "ok: Capture standalone load reaches its next dependency wall without a Canary class failure\n");

require __DIR__ . '/../../agent/src/Canon.php';
require __DIR__ . '/../../agent/src/OptionState.php';
require __DIR__ . '/fixtures/duo-publish-stale-is-file.php';
require __DIR__ . '/../../agent/src/Publish.php';
require __DIR__ . '/../../agent/src/TransientDbException.php';
require __DIR__ . '/../../agent/src/Capture.php';
// DUO-3236 (P8 below): RepositoryCompiler::compile_staged() is the new gate
// Capture.php now runs against the staged candidate before Publish::swap().
// Confirmed target-DB-free (agent/src/RepositoryCompiler.php's own
// docblock: "no $wpdb, no get_plugins()/wp_get_theme() calls anywhere in
// this compiler") — same offline-testability precedent already established
// by sandbox/tests/regress_repository_compiler.sh, whose dependency list
// this mirrors exactly.
require __DIR__ . '/../../agent/src/Uuid.php';
// CaptureTransaction now self-loads Db as its direct transaction dependency.
// Keep this legacy fixture load idempotent for both dependency layouts.
require_once __DIR__ . '/../../agent/src/Db.php';
require_once __DIR__ . '/../../agent/src/Policy.php';
require __DIR__ . '/../../agent/src/Ledger.php';
require __DIR__ . '/../../agent/src/Snapshot.php';
require __DIR__ . '/../../agent/src/Deletion.php';
require __DIR__ . '/../../agent/src/RepositoryAuthorization.php';
require __DIR__ . '/../../agent/src/RepositoryCompiler.php';
// RepositoryEntityParser closes the compiler's sidebar dependency. Keep this
// support load idempotent so the capture/publish harness remains valid both
// before and after the compiler parser boundary is loaded transitively.
require_once __DIR__ . '/../../agent/src/SidebarState.php';
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2); // agent/duo.php's own value; not required here to avoid its ABSPATH/WP_CLI bootstrap guard
}

use Duo\Canon;
use Duo\CommandRefusalException;
use Duo\OptionState;
use Duo\Policy;
use Duo\Publish;
use Duo\RepositoryCompilationException;
use Duo\RepositoryCompiler;

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

function rrmdir_test(string $dir): void {
    Publish::rrmdir($dir);
}

/** Fresh scratch root for one test group; auto-removed at process exit. */
function fresh_root(string $label): string {
    $root = sys_get_temp_dir() . '/duo_regress_capture_publish_' . $label . '_' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    register_shutdown_function(fn() => rrmdir_test($root));
    return $root;
}

function write_tree(string $dir, array $files): void {
    foreach ($files as $rel => $content) {
        Canon::write_file($dir . '/' . $rel, $content);
    }
}

function read_tree(string $dir): array {
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $rel = substr($f->getPathname(), strlen($dir) + 1);
            $out[$rel] = file_get_contents($f->getPathname());
        }
    }
    ksort($out);
    return $out;
}

/** @return array minimal valid front-matter for a "core"-manifest post entity */
function p8_post_front(string $id, string $type, string $slug): array {
    return [
        'author' => 'user:admin', 'comment_status' => 'open',
        'date' => '2026-08-07 00:00:00', 'date_gmt' => '2026-08-07 00:00:00',
        'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
        'modified_gmt' => '2026-08-07 00:00:00', 'parent' => null,
        'ping_status' => 'closed', 'slug' => $slug, 'status' => 'publish',
        'terms' => (object) [], 'title' => ucfirst(str_replace('-', ' ', $slug)),
        'type' => $type, 'uuid' => $id,
    ];
}

/**
 * The "core" manifest's exact authored/managed-classed option names (see
 * manifests/core.json) — env/runtime/derived-classed options are excluded,
 * same as regress_repository_compiler.sh's own build_valid() helper, whose
 * shape this mirrors deliberately (a compiled artifact requires every
 * DECLARED authored/managed option to have an explicit record, present or
 * absent — never silently missing).
 */
function p8_options_document(): array {
    $records = [];
    foreach ([
        'active_plugins', 'blogdescription', 'blogname', 'default_category', 'page_for_posts',
        'page_on_front', 'posts_per_page', 'show_on_front', 'sticky_posts', 'stylesheet',
        'template', 'wp_page_for_privacy_policy',
    ] as $name) {
        $records[$name] = OptionState::absent();
    }
    $records['blogname'] = OptionState::present('Duo P8', 'yes');
    return OptionState::document($records);
}

function p8_site_json(): string {
    return Canon::encode([
        'manifests' => ['core'],
        'policy' => [
            'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) [],
            'post_types' => ['post', 'page', 'attachment'],
            'taxonomies' => ['category', 'post_tag'],
        ],
        'spec_version' => 2,
    ]);
}

// ======================================================================
// P1 — capture lock: mutual exclusion, clean failure, release
// ======================================================================
echo "\n== P1: Publish::lock()/unlock() ==\n";
{
    $root = fresh_root('lock');
    $stateDir = "$root/state";

    $h1 = Publish::lock($stateDir);
    check(is_resource($h1), 'P1a: first lock() succeeds and returns a resource');

    $threw = null;
    try {
        Publish::lock($stateDir);
    } catch (\Throwable $t) {
        $threw = $t;
    }
    check($threw instanceof \RuntimeException, 'P1b: a second concurrent lock() on the SAME destination throws');
    check(
        $threw !== null && str_contains($threw->getMessage(), 'already publishing') && str_contains($threw->getMessage(), $stateDir),
        'P1c: the failure names the destination and reads as an actionable refusal (got: ' . ($threw->getMessage() ?? '') . ')'
    );

    // A DIFFERENT destination must be entirely independent — no contention.
    $h2 = Publish::lock("$root/other-state");
    check(is_resource($h2), 'P1d: lock() on a DIFFERENT destination is unaffected by P1a\'s still-held lock');
    Publish::unlock($h2);

    Publish::unlock($h1);
    $h3 = null;
    $threw2 = null;
    try {
        $h3 = Publish::lock($stateDir);
    } catch (\Throwable $t) {
        $threw2 = $t;
    }
    check($threw2 === null && is_resource($h3), 'P1e: after unlock(), the SAME destination can be locked again immediately');
    if ($h3 !== null) {
        Publish::unlock($h3);
    }

    check(is_file(Publish::lock_path($stateDir)), 'P1f: the lock file itself persists on disk (only its flock() STATE matters, never its content)');
}

// ======================================================================
// P2 — recover(): all four crash-state combinations
// ======================================================================
echo "\n== P2: Publish::recover() deterministic crash reconciliation ==\n";
{
    // P2a: neither staging nor backup present (first-ever capture, or a
    // clean prior run) -- no-op, no error, nothing to report.
    $root = fresh_root('recover_a');
    $stateDir = "$root/state";
    $log = Publish::recover($stateDir);
    check($log === [], 'P2a: nothing leftover -> recover() is a silent no-op (got: ' . json_encode($log) . ')');
    check(!is_dir($stateDir), 'P2a: recover() never CREATES state/ out of nothing');

    // P2b: leftover STAGING dir only -- always discarded, regardless of
    // whether 'state' exists, and regardless of whether it "looks complete."
    $root = fresh_root('recover_b');
    $stateDir = "$root/state";
    write_tree($stateDir, ['a.json' => "old\n"]);
    write_tree(Publish::stage_dir($stateDir), ['a.json' => "PARTIAL-CANDIDATE\n", 'b.json' => "also partial\n"]);
    $log = Publish::recover($stateDir);
    check(count($log) === 1 && str_contains($log[0], 'abandoned staging'), 'P2b: reports removing the abandoned staging dir (got: ' . json_encode($log) . ')');
    check(!is_dir(Publish::stage_dir($stateDir)), 'P2b: staging dir is gone');
    check(read_tree($stateDir) === ['a.json' => "old\n"], 'P2b: the PUBLISHED tree is completely untouched by discarding an abandoned candidate');

    // P2c: leftover BACKUP + state MISSING -- crashed between swap()'s two
    // renames; the backup IS the last known-good tree and must be restored.
    $root = fresh_root('recover_c');
    $stateDir = "$root/state";
    write_tree(Publish::backup_dir($stateDir), ['a.json' => "last-known-good\n"]);
    check(!is_dir($stateDir), 'P2c precondition: state/ genuinely missing before recover()');
    $log = Publish::recover($stateDir);
    check(count($log) === 1 && str_starts_with($log[0], 'RECOVERED:'), 'P2c: reports a RECOVERED (capitalized -- this is the dangerous branch) restoration (got: ' . json_encode($log) . ')');
    check(read_tree($stateDir) === ['a.json' => "last-known-good\n"], 'P2c: state/ now holds exactly the backup\'s content');
    check(!is_dir(Publish::backup_dir($stateDir)), 'P2c: the backup name itself is gone (renamed away, not copied)');

    // P2d: leftover BACKUP + state PRESENT -- swap() fully completed, only
    // its own final cleanup didn't run; backup is stale, sweep it, state/
    // is untouched (it's already the CORRECT published tree).
    $root = fresh_root('recover_d');
    $stateDir = "$root/state";
    write_tree($stateDir, ['a.json' => "current-published\n"]);
    write_tree(Publish::backup_dir($stateDir), ['a.json' => "stale-old\n"]);
    $log = Publish::recover($stateDir);
    check(count($log) === 1 && str_contains($log[0], 'stale backup'), 'P2d: reports sweeping a stale (non-dangerous) backup (got: ' . json_encode($log) . ')');
    check(read_tree($stateDir) === ['a.json' => "current-published\n"], 'P2d: state/ (already correct) is untouched');
    check(!is_dir(Publish::backup_dir($stateDir)), 'P2d: stale backup is gone');
}

// ======================================================================
// P3 — write_entities(): correctness + disk-full fault injection
// ======================================================================
echo "\n== P3: Publish::write_entities() ==\n";
{
    $root = fresh_root('write');
    $stateDir = "$root/state";
    $staging = Publish::stage_dir($stateDir);
    $entities = [
        ['path' => 'options/core.json', 'content' => "{\"a\":1}\n"],
        ['path' => 'posts/page/uuid1--home.md', 'content' => "---\n{}\n---\nbody\n"],
    ];
    Publish::write_entities($staging, $entities);
    check(
        read_tree($staging) === [
            'options/core.json' => "{\"a\":1}\n",
            'posts/page/uuid1--home.md' => "---\n{}\n---\nbody\n",
        ],
        'P3a: every entity lands at the right relative path with exact byte content'
    );
    check(!is_dir($stateDir), 'P3b: write_entities() never creates or touches the PUBLISHED dir, only the staging one');

    // P3c: fault-injected "disk full" -- a writer that throws partway
    // through. Simulates the AC's disk-full scenario deterministically,
    // without needing a real constrained filesystem.
    $root2 = fresh_root('write_fault');
    $stateDir2 = "$root2/state";
    write_tree($stateDir2, ['keep.json' => "PUBLISHED-BEFORE-THE-CRASH\n"]);
    $staging2 = Publish::stage_dir($stateDir2);
    $calls = 0;
    $faultyWriter = function (string $path, string $content) use (&$calls) {
        $calls++;
        if ($calls > 2) {
            throw new \RuntimeException('simulated disk-full on write #' . $calls);
        }
        Canon::write_file($path, $content);
    };
    $bigEntityList = [
        ['path' => 'e1.json', 'content' => "1\n"],
        ['path' => 'e2.json', 'content' => "2\n"],
        ['path' => 'e3.json', 'content' => "3\n"], // this one triggers the simulated failure
        ['path' => 'e4.json', 'content' => "4\n"],
    ];
    $threw3 = null;
    try {
        Publish::write_entities($staging2, $bigEntityList, $faultyWriter);
    } catch (\Throwable $t) {
        $threw3 = $t;
    }
    check($threw3 instanceof \RuntimeException && str_contains($threw3->getMessage(), 'simulated disk-full'), 'P3c: the injected failure propagates out of write_entities()');
    check(
        read_tree($stateDir2) === ['keep.json' => "PUBLISHED-BEFORE-THE-CRASH\n"],
        'P3d: the PUBLISHED tree is byte-for-byte untouched by a failure during staging (the core DUO-3213 guarantee)'
    );
    // Clean up per P2b's already-proven contract before this scratch root's
    // own shutdown handler runs, just to leave the fixture tidy.
    $notes = Publish::recover($stateDir2);
    check(count($notes) === 1 && str_contains($notes[0], 'abandoned staging'), 'P3e: recover() cleanly sweeps the partially-written staging dir on the next run');
}

// P3f: zero entities (a legitimate, if unusual, empty capture) must still
// create the staging dir -- otherwise swap() below would spuriously refuse
// with "no staged candidate" for a perfectly valid empty build.
{
    $root = fresh_root('write_empty');
    $stateDir = "$root/state";
    $staging = Publish::stage_dir($stateDir);
    Publish::write_entities($staging, []);
    check(is_dir($staging), 'P3f: write_entities() with zero entities still creates the staging dir');
    Publish::swap($stateDir);
    check(is_dir($stateDir) && read_tree($stateDir) === [], 'P3f: swap() then publishes a legitimately empty tree without error');
}

// ======================================================================
// P4 — swap(): the atomic two-step rename and its invariants
// ======================================================================
echo "\n== P4: Publish::swap() ==\n";
{
    // P4a: ordinary swap, prior published tree exists.
    $root = fresh_root('swap_a');
    $stateDir = "$root/state";
    write_tree($stateDir, ['old.json' => "old\n"]);
    write_tree(Publish::stage_dir($stateDir), ['new.json' => "new\n"]);
    Publish::swap($stateDir);
    check(read_tree($stateDir) === ['new.json' => "new\n"], 'P4a: state/ now holds exactly the staged candidate');
    check(!is_dir(Publish::stage_dir($stateDir)), 'P4a: staging name is gone (renamed away)');
    check(!is_dir(Publish::backup_dir($stateDir)), 'P4a: backup was cleaned up after a successful swap');

    // P4b: first-ever swap, no prior published tree at all.
    $root = fresh_root('swap_b');
    $stateDir = "$root/state";
    write_tree(Publish::stage_dir($stateDir), ['first.json' => "first capture ever\n"]);
    check(!is_dir($stateDir), 'P4b precondition: no prior state/');
    Publish::swap($stateDir);
    check(read_tree($stateDir) === ['first.json' => "first capture ever\n"], 'P4b: a first-ever capture (no prior tree) swaps in cleanly');

    // P4c: refuses when no staged candidate exists.
    $root = fresh_root('swap_c');
    $stateDir = "$root/state";
    write_tree($stateDir, ['a.json' => "a\n"]);
    $threw = null;
    try {
        Publish::swap($stateDir);
    } catch (\Throwable $t) {
        $threw = $t;
    }
    check($threw instanceof \RuntimeException && str_contains($threw->getMessage(), 'no staged candidate'), 'P4c: swap() with nothing staged refuses loudly (got: ' . ($threw->getMessage() ?? 'no exception') . ')');
    check(read_tree($stateDir) === ['a.json' => "a\n"], 'P4c: refusing to swap leaves state/ untouched');

    // P4d: refuses on an unexpected pre-existing backup (invariant guard —
    // should be unreachable while the capture lock is held correctly).
    $root = fresh_root('swap_d');
    $stateDir = "$root/state";
    write_tree($stateDir, ['a.json' => "a\n"]);
    write_tree(Publish::stage_dir($stateDir), ['b.json' => "b\n"]);
    write_tree(Publish::backup_dir($stateDir), ['stale.json' => "should not be here\n"]);
    $threw = null;
    try {
        Publish::swap($stateDir);
    } catch (\Throwable $t) {
        $threw = $t;
    }
    check($threw instanceof \RuntimeException && str_contains($threw->getMessage(), 'unexpected pre-existing'), 'P4d: swap() refuses when a backup dir already exists (invariant violation, not ordinary recovery) (got: ' . ($threw->getMessage() ?? 'no exception') . ')');
}

// ======================================================================
// P5 — end-to-end: two full capture cycles using Publish's own primitives
// ======================================================================
echo "\n== P5: end-to-end capture-cycle simulation ==\n";
{
    $root = fresh_root('e2e');
    $stateDir = "$root/state";

    // Cycle 1: first-ever capture.
    $lock = Publish::lock($stateDir);
    $notes = Publish::recover($stateDir);
    check($notes === [], 'P5a: cycle 1 has nothing to recover');
    Publish::write_entities(Publish::stage_dir($stateDir), [['path' => 'options/core.json', 'content' => "{\"rev\":1}\n"]]);
    Publish::swap($stateDir);
    Publish::unlock($lock);
    check(read_tree($stateDir) === ['options/core.json' => "{\"rev\":1}\n"], 'P5b: cycle 1 published successfully');
    check(!is_dir(Publish::stage_dir($stateDir)) && !is_dir(Publish::backup_dir($stateDir)), 'P5c: cycle 1 leaves no staging/backup artifacts behind');

    // Cycle 2: a real content change, proving the swap actually REPLACES
    // (not merges) — a stale file from cycle 1 that cycle 2 doesn't
    // re-emit must be gone afterward (matches spec's "entity-per-file,
    // capture rebuilds the whole tree" semantics, the same thing the old
    // clear_state_dir() achieved, just atomically now).
    $lock = Publish::lock($stateDir);
    Publish::recover($stateDir);
    Publish::write_entities(Publish::stage_dir($stateDir), [['path' => 'options/core.json', 'content' => "{\"rev\":2}\n"]]);
    Publish::swap($stateDir);
    Publish::unlock($lock);
    check(read_tree($stateDir) === ['options/core.json' => "{\"rev\":2}\n"], 'P5d: cycle 2 fully replaced the tree (rev 1 -> rev 2, no leftover files)');
}

// ======================================================================
// P6 — real process kill (SIGKILL) mid-staging: the actual crash scenario
// ======================================================================
echo "\n== P6: real SIGKILL mid-publish ==\n";
{
    $root = fresh_root('kill');
    $stateDir = "$root/state";
    write_tree($stateDir, ['before.json' => "PUBLISHED-BEFORE-THE-KILL\n"]);

    $driver = __DIR__ . '/support/capture_publish_kill_driver.php';
    check(is_file($driver), 'P6 precondition: kill-driver support script exists');

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['php', $driver, $stateDir], $descriptors, $pipes);
    check(is_resource($proc), 'P6a: child capture process spawned');

    if (is_resource($proc)) {
        // Give it time to acquire the lock, run recover(), and get partway
        // through writing a deliberately large, deliberately slowed-down
        // staging tree (see the driver script) -- well before it could
        // reach swap(). Then SIGKILL it, exactly like an OOM-killer would.
        usleep(300_000);
        proc_terminate($proc, SIGKILL);
        // Drain pipes so proc_close() doesn't hang on a full buffer, then
        // reap the process.
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        check(
            read_tree($stateDir) === ['before.json' => "PUBLISHED-BEFORE-THE-KILL\n"],
            'P6b: the published tree is BYTE-IDENTICAL to before the kill -- a real SIGKILL mid-staging never touched it'
        );
        check(is_dir(Publish::stage_dir($stateDir)), 'P6c: a partially-written staging dir was left behind (expected -- evidence for the next recover())');

        // The lock must be free again -- SIGKILL closes the fd, which
        // releases the flock() the OS was holding for that process.
        $relock = null;
        $lockThrew = null;
        try {
            $relock = Publish::lock($stateDir);
        } catch (\Throwable $t) {
            $lockThrew = $t;
        }
        check($lockThrew === null && is_resource($relock), 'P6d: the capture lock is free again after the holder is SIGKILLed (OS-level release, not application cleanup)');

        // And a normal follow-up cycle completes cleanly, proving recovery
        // isn't just "safe," it's actually USABLE on the next run.
        if ($relock !== null) {
            $notes = Publish::recover($stateDir);
            check(count($notes) === 1 && str_contains($notes[0], 'abandoned staging'), 'P6e: the next run\'s recover() sweeps the killed run\'s leftover staging dir');
            Publish::write_entities(Publish::stage_dir($stateDir), [['path' => 'after.json', 'content' => "recovered-and-published\n"]]);
            Publish::swap($stateDir);
            Publish::unlock($relock);
            check(read_tree($stateDir) === ['after.json' => "recovered-and-published\n"], 'P6f: the very next capture after a kill publishes normally');
        }
    }
}

// ======================================================================
// P7 — Capture::check_transient_db_error() string matching (Reflection;
// the only DB-adjacent logic that needs no live database at all)
// ======================================================================
echo "\n== P7: Capture::check_transient_db_error() (Reflection, stub \$wpdb) ==\n";
{
    // No setAccessible() call: a no-op since PHP 8.1 (private methods are
    // directly ->invoke()-able via Reflection since then) and deprecated
    // outright in 8.5 — this repo's target runtimes span both.
    $method = new ReflectionMethod(\Duo\Capture::class, 'check_transient_db_error');

    $wpdb = new stdClass();
    $wpdb->last_error = '';
    $GLOBALS['wpdb'] = $wpdb;

    $threw = null;
    try {
        $method->invoke(null, 'nowhere');
    } catch (\Throwable $t) {
        $threw = $t;
    }
    check($threw === null, 'P7a: empty last_error -> no exception (the common case: every query succeeded)');

    $GLOBALS['wpdb']->last_error = "WordPress database error Deadlock found when trying to get lock; try restarting transaction for query INSERT ...";
    $threw = null;
    try {
        $method->invoke(null, 'mint _duo_uuid for post 5');
    } catch (\Throwable $t) {
        $threw = $t;
    }
    check($threw instanceof \Duo\TransientDbException, 'P7b: a real MySQL deadlock message -> TransientDbException (retryable)');

    $GLOBALS['wpdb']->last_error = "WordPress database error Lock wait timeout exceeded; try restarting transaction for query INSERT ...";
    $threw = null;
    try {
        $method->invoke(null, 'somewhere');
    } catch (\Throwable $t) {
        $threw = $t;
    }
    check($threw instanceof \Duo\TransientDbException, 'P7c: a real MySQL lock-wait-timeout message -> TransientDbException (retryable)');

    $GLOBALS['wpdb']->last_error = "WordPress database error You have an error in your SQL syntax; ...";
    $threw = null;
    try {
        $method->invoke(null, 'somewhere');
    } catch (\Throwable $t) {
        $threw = $t;
    }
    check(
        $threw instanceof \RuntimeException && !($threw instanceof \Duo\TransientDbException),
        'P7d: a DIFFERENT SQL error -> plain RuntimeException, NOT retried (a real error must never be silently retried into a false green)'
    );
}

// ======================================================================
// P8 — DUO-3236: RepositoryCompiler::compile_staged() gates a staged
// candidate before Publish::swap() ever runs. This exercises the actual
// sequence agent/src/Capture.php's run() now performs around its own
// write_entities() -> [media copy] -> compile_staged() -> swap() steps
// (P1-P6 above cover Publish.php's primitives in isolation; this covers
// the new integration between them).
// ======================================================================
echo "\n== P8: staged-candidate compile gate (DUO-3236) ==\n";
{
    // ---- P8a: a VALID candidate, including a brand-new media reference,
    // passes the gate and swap() promotes it normally -- proving the
    // "copy media BEFORE the gate" reordering actually works, not merely
    // that a gate exists at all.
    $root = fresh_root('p8_valid');
    $repo = "$root/repo";
    mkdir($repo, 0777, true);
    Canon::write_file("$repo/site.duo.json", p8_site_json());
    $stateDir = "$repo/state";
    $pageId = '00000000-0000-4000-9000-000000000001';
    $staging = Publish::stage_dir($stateDir);
    Publish::write_entities($staging, [
        ['path' => 'options/core.json', 'content' => Canon::encode(p8_options_document())],
        [
            'path' => "posts/page/$pageId--home.md",
            'content' => Canon::post_file(p8_post_front($pageId, 'page', 'home'), '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->'),
        ],
    ]);
    // Simulate Capture::run()'s own DUO-3236 ordering: the media blob this
    // run discovered is copied to the REAL media/ root BEFORE the gate
    // runs (agent/src/Capture.php's relocated foreach), never staged
    // itself (Publish.php's own class docblock).
    $mediaBytes = "p8 media\n";
    $mediaHash = hash('sha256', $mediaBytes);
    mkdir("$repo/media", 0777, true);
    Canon::write_file("$repo/media/$mediaHash.txt", $mediaBytes);

    $policy = Policy::load($repo);
    $compiled = RepositoryCompiler::compile_staged($staging, $repo, $policy);
    check($compiled instanceof \Duo\CompiledRepository, 'P8a: a valid staged candidate (with its media already copied) compiles cleanly through the new gate');
    Publish::swap($stateDir);
    check(
        read_tree($stateDir) === [
            'options/core.json' => Canon::encode(p8_options_document()),
            "posts/page/$pageId--home.md" => Canon::post_file(p8_post_front($pageId, 'page', 'home'), '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->'),
        ],
        'P8a: the gated candidate was promoted to state/ byte-for-byte'
    );
    check(!is_dir($staging), 'P8a: staging name is gone after a successful gate + swap');

    // ---- P8b: an INVALID candidate (two posts sharing one uuid) is
    // refused by the gate; swap() is never reached (matching Capture.php's
    // real control flow, where the thrown exception propagates before
    // that line executes); the tree published by P8a is untouched.
    $publishedBefore = read_tree($stateDir);
    $staging2 = Publish::stage_dir($stateDir);
    $dupId = '00000000-0000-4000-9000-000000000002';
    Publish::write_entities($staging2, [
        ['path' => 'options/core.json', 'content' => Canon::encode(p8_options_document())],
        ['path' => "posts/page/$dupId--one.md", 'content' => Canon::post_file(p8_post_front($dupId, 'page', 'one'), 'first')],
        ['path' => "posts/page/$dupId--two.md", 'content' => Canon::post_file(p8_post_front($dupId, 'page', 'two'), 'second')],
    ]);
    $threwP8b = null;
    try {
        RepositoryCompiler::compile_staged($staging2, $repo, $policy);
    } catch (\Throwable $t) {
        $threwP8b = $t;
    }
    check($threwP8b instanceof RepositoryCompilationException, 'P8b: a structurally-invalid staged candidate (duplicate uuid) is refused by the gate');
    check(
        $threwP8b instanceof RepositoryCompilationException
            && in_array('duplicate_uuid', array_column($threwP8b->diagnostics, 'code'), true),
        'P8b: the refusal names the actual compiler diagnostic (duplicate_uuid), not a generic error'
    );
    // Never reached in the real Capture::run() path once the gate throws --
    // deliberately NOT calling Publish::swap($stateDir) here, matching
    // that control flow exactly, rather than proving something this test
    // itself chose not to attempt.
    check(read_tree($stateDir) === $publishedBefore, 'P8b: the PREVIOUSLY published tree (from P8a) is byte-for-byte untouched by the refused candidate');
    check(is_dir($staging2), 'P8b: the invalid staging dir is left in place for the next run\'s Publish::recover() to discard (same as every other loud-and-blocking gate in Capture::run())');

    // ---- P8c: THE regression this issue exists to prevent. A candidate
    // that is otherwise perfectly valid, but whose referenced media blob
    // has NOT yet been copied to the real media/ root, is refused --
    // proving why Capture.php's own copy-media-BEFORE-the-gate reordering
    // (P8a's setup) is load-bearing, not cosmetic: getting that ordering
    // backwards (media copied only AFTER a successful gate+swap, which is
    // what agent/src/Capture.php did before DUO-3236) would make EVERY
    // capture containing a brand-new media reference fail this gate
    // spuriously, every time.
    $root2 = fresh_root('p8_missing_media');
    $repo2 = "$root2/repo";
    mkdir($repo2, 0777, true);
    Canon::write_file("$repo2/site.duo.json", p8_site_json());
    $attachId = '00000000-0000-4000-9000-000000000003';
    $newMediaBytes = "never copied\n";
    $newMediaHash = hash('sha256', $newMediaBytes);
    $staging3 = Publish::stage_dir("$repo2/state");
    $attachFront = p8_post_front($attachId, 'attachment', 'photo')
        + ['alt' => 'Photo', 'file' => 'photo.txt', 'media' => "$newMediaHash.txt", 'mime' => 'text/plain'];
    Publish::write_entities($staging3, [
        ['path' => 'options/core.json', 'content' => Canon::encode(p8_options_document())],
        ['path' => "posts/attachment/$attachId--photo.md", 'content' => Canon::post_file($attachFront, '')],
    ]);
    // Deliberately NOT copying $newMediaHash.txt to $repo2/media -- this is
    // the exact bug shape the pre-DUO-3236 ordering would have hit for any
    // real capture with new media, reproduced here on purpose.
    check(!is_file("$repo2/media/$newMediaHash.txt"), 'P8c precondition: the referenced media blob genuinely does not exist in the real media root yet');
    $threwP8c = null;
    try {
        RepositoryCompiler::compile_staged($staging3, $repo2, Policy::load($repo2));
    } catch (\Throwable $t) {
        $threwP8c = $t;
    }
    check(
        $threwP8c instanceof RepositoryCompilationException
            && in_array('missing_media_blob', array_column($threwP8c->diagnostics, 'code'), true),
        'P8c: an otherwise-valid candidate whose media was not yet copied to the real root fails with missing_media_blob -- exactly the failure mode Capture.php\'s media-before-gate reordering exists to avoid for a normal capture'
    );
}

// ======================================================================
// P9 — cross-resource publication intent/receipt recovery
// ======================================================================
echo "\n== P9: transaction-bound capture publication recovery ==\n";
{
    $publishCandidate = static function (string $stateDir, array $files): array {
        write_tree(Publish::stage_dir($stateDir), $files);
        $intent = Publish::begin_intent($stateDir, Publish::stage_dir($stateDir));
        Publish::swap($stateDir, true);
        $intent = Publish::mark_swapped($stateDir, $intent);
        $intent = Publish::mark_commit_ready($stateDir, $intent);
        return Publish::mark_committing($stateDir, $intent);
    };

    // A client that died around COMMIT can be reconciled from the marker
    // written in that same DB transaction. A prior marker (false) restores
    // the exact old tree; a matching durable marker (true) keeps the new one.
    $root = fresh_root('protocol_rollback');
    $stateDir = "$root/state";
    write_tree($stateDir, ['revision.txt' => "old\n", 'only-old.txt' => "keep\n"]);
    $intent = $publishCandidate($stateDir, ['revision.txt' => "candidate\n"]);
    $log = Publish::recover($stateDir, static fn(array $found): bool => false);
    check(str_contains(implode("\n", $log), 'rolled back'), 'P9a: absent/prior DB marker reports a filesystem rollback');
    check(read_tree($stateDir) === ['only-old.txt' => "keep\n", 'revision.txt' => "old\n"], 'P9a: absent/prior DB marker restores the complete previous tree');
    check(!is_dir(Publish::backup_dir($stateDir)) && !is_file(Publish::intent_path($stateDir)), 'P9a: rollback consumes only its own backup/intent artifacts');

    $root = fresh_root('protocol_commit');
    $stateDir = "$root/state";
    write_tree($stateDir, ['revision.txt' => "old\n"]);
    $intent = $publishCandidate($stateDir, ['revision.txt' => "committed\n", 'new.txt' => "yes\n"]);
    $log = Publish::recover($stateDir, static fn(array $found): bool => true);
    check(str_contains(implode("\n", $log), 'commit marker proved'), 'P9b: matching durable DB marker reports committed recovery');
    check(read_tree($stateDir) === ['new.txt' => "yes\n", 'revision.txt' => "committed\n"], 'P9b: matching durable DB marker retains the exact candidate tree');
    check(!is_dir(Publish::backup_dir($stateDir)) && !is_file(Publish::intent_path($stateDir)), 'P9b: marker-backed recovery finalizes retained artifacts');
    check(is_file(Publish::receipt_path($stateDir)), 'P9b: marker-backed recovery writes durable audit receipt');

    // First publication has no backup. Rollback removes the candidate;
    // commit retains it. This is deliberately separate from existing-state
    // recovery because deleting state/ is safe only when previous_sha256 is
    // the protocol's empty-tree digest.
    $root = fresh_root('protocol_first_rollback');
    $stateDir = "$root/state";
    $intent = $publishCandidate($stateDir, ['first.txt' => "candidate\n"]);
    Publish::recover($stateDir, static fn(array $found): bool => false);
    check(!is_dir($stateDir), 'P9c: uncommitted first publication removes its candidate instead of inventing an old tree');

    $root = fresh_root('protocol_first_commit');
    $stateDir = "$root/state";
    $intent = $publishCandidate($stateDir, ['first.txt' => "committed\n"]);
    Publish::recover($stateDir, static fn(array $found): bool => true);
    check(read_tree($stateDir) === ['first.txt' => "committed\n"], 'P9c: committed first publication retains its candidate without requiring a backup');

    // A clean run intentionally retains its receipt as audit history. A new
    // intent must supersede, not be paired with, that receipt; rolling the
    // new attempt back leaves the old receipt intact.
    $root = fresh_root('protocol_stale_receipt');
    $stateDir = "$root/state";
    $firstIntent = $publishCandidate($stateDir, ['revision.txt' => "one\n"]);
    $firstReceipt = Publish::write_receipt($stateDir, $firstIntent);
    putenv('DUO_TEST_STALE_IS_FILE_PATH=' . Publish::intent_path($stateDir));
    try {
        Publish::cleanup_committed($stateDir, $firstReceipt);
    } finally {
        putenv('DUO_TEST_STALE_IS_FILE_PATH');
    }
    check(
        !is_dir(Publish::backup_dir($stateDir)) && !is_file(Publish::intent_path($stateDir)),
        'P9d: stale intent-path metadata cannot strand verified post-commit cleanup'
    );
    write_tree(Publish::stage_dir($stateDir), ['revision.txt' => "two\n"]);
    $secondIntent = Publish::begin_intent($stateDir, Publish::stage_dir($stateDir));
    Publish::recover($stateDir, static fn(array $found): bool => false);
    $retainedReceipt = Canon::decode((string) file_get_contents(Publish::receipt_path($stateDir)));
    check(($retainedReceipt['intent_id'] ?? null) === ($firstIntent['id'] ?? null), 'P9d: a newer refused intent does not destroy the prior audit receipt');
    check(read_tree($stateDir) === ['revision.txt' => "one\n"], 'P9d: stale prior receipt cannot promote or block the newer prepared candidate rollback');
    check(!is_dir(Publish::stage_dir($stateDir)) && !is_file(Publish::intent_path($stateDir)), 'P9d: newer prepared candidate artifacts are removed cleanly');

    // Receipt-only history must also ignore an abandoned pre-intent staging
    // tree after a normal human edit of state/. It cannot claim that later
    // tree merely because some protocol artifact happens to exist.
    write_tree($stateDir, ['revision.txt' => "human-edit\n"]);
    write_tree(Publish::stage_dir($stateDir), ['partial.txt' => "abandoned\n"]);
    Publish::recover($stateDir, static fn(array $found): bool => false);
    check(read_tree($stateDir) === ['revision.txt' => "human-edit\n"], 'P9e: receipt-only audit history never blocks or rewrites a later state edit');
    check(!is_dir(Publish::stage_dir($stateDir)), 'P9e: receipt-only audit history does not claim a later abandoned staging tree');

    // Every destructive recovery is hash-bound. Tampering with either the
    // sealed record or a candidate tree fails closed and preserves all
    // evidence for inspection.
    $root = fresh_root('protocol_tampered_record');
    $stateDir = "$root/state";
    write_tree($stateDir, ['revision.txt' => "old\n"]);
    write_tree(Publish::stage_dir($stateDir), ['revision.txt' => "candidate\n"]);
    $intent = Publish::begin_intent($stateDir, Publish::stage_dir($stateDir));
    $tampered = Canon::decode((string) file_get_contents(Publish::intent_path($stateDir)));
    $tampered['candidate_sha256'] = str_repeat('0', 64);
    Canon::write_file(Publish::intent_path($stateDir), Canon::encode($tampered));
    $recordFailure = null;
    try {
        Publish::recover($stateDir, static fn(array $found): bool => false);
    } catch (Throwable $t) {
        $recordFailure = $t;
    }
    check($recordFailure instanceof RuntimeException && str_contains($recordFailure->getMessage(), 'tampered intent'), 'P9f: modified durable intent is refused by its canonical self-hash');
    check(
        $recordFailure instanceof CommandRefusalException
            && $recordFailure->reasonCode === 'capture_recovery_ambiguous'
            && str_contains($recordFailure->remediation, 'do not retry or discard'),
        'P9f: malformed or tampered durable records expose the stable no-retry recovery refusal'
    );
    check(is_dir(Publish::stage_dir($stateDir)) && read_tree($stateDir) === ['revision.txt' => "old\n"], 'P9f: sealed-record refusal leaves both published and staged evidence untouched');

    $root = fresh_root('protocol_tampered_tree');
    $stateDir = "$root/state";
    write_tree($stateDir, ['revision.txt' => "old\n"]);
    $intent = $publishCandidate($stateDir, ['revision.txt' => "candidate\n"]);
    Canon::write_file("$stateDir/revision.txt", "out-of-band\n");
    $treeFailure = null;
    try {
        Publish::recover($stateDir, static fn(array $found): bool => false);
    } catch (Throwable $t) {
        $treeFailure = $t;
    }
    check($treeFailure instanceof RuntimeException && str_contains($treeFailure->getMessage(), 'does not match'), 'P9g: changed candidate tree refuses destructive rollback');
    check(
        $treeFailure instanceof CommandRefusalException
            && $treeFailure->reasonCode === 'capture_recovery_ambiguous'
            && str_contains($treeFailure->remediation, 'do not retry or discard'),
        'P9g: ambiguous recovery exposes a stable no-retry machine refusal'
    );
    check(read_tree($stateDir) === ['revision.txt' => "out-of-band\n"] && is_dir(Publish::backup_dir($stateDir)), 'P9g: tree-hash refusal preserves the candidate, backup, and recovery evidence');

    // A filesystem receipt cannot be manufactured before the durable intent
    // records the COMMIT boundary, and even a correctly formed receipt cannot
    // overrule the database callback used by Capture recovery.
    $root = fresh_root('protocol_receipt_boundary');
    $stateDir = "$root/state";
    write_tree($stateDir, ['revision.txt' => "old\n"]);
    write_tree(Publish::stage_dir($stateDir), ['revision.txt' => "candidate\n"]);
    $intent = Publish::begin_intent($stateDir, Publish::stage_dir($stateDir));
    Publish::swap($stateDir, true);
    $intent = Publish::mark_swapped($stateDir, $intent);
    $intent = Publish::mark_commit_ready($stateDir, $intent);
    $earlyReceiptFailure = null;
    try {
        Publish::write_receipt($stateDir, $intent);
    } catch (Throwable $t) {
        $earlyReceiptFailure = $t;
    }
    check($earlyReceiptFailure instanceof RuntimeException && str_contains($earlyReceiptFailure->getMessage(), 'COMMIT-attempt'), 'P9h: receipt cannot be written before the durable COMMIT-attempt boundary');
    check(
        $earlyReceiptFailure instanceof CommandRefusalException
            && $earlyReceiptFailure->reasonCode === 'capture_recovery_ambiguous'
            && str_contains($earlyReceiptFailure->remediation, 'do not retry or discard'),
        'P9h: premature receipt creation exposes the stable no-retry recovery refusal'
    );
    $intent = Publish::mark_committing($stateDir, $intent);
    $receipt = Publish::write_receipt($stateDir, $intent);
    $receiptContradiction = null;
    try {
        Publish::recover($stateDir, static fn(array $found): bool => false);
    } catch (Throwable $t) {
        $receiptContradiction = $t;
    }
    check($receiptContradiction instanceof RuntimeException && str_contains($receiptContradiction->getMessage(), 'receipt exists'), 'P9h: matching receipt plus absent DB commit marker fails closed as contradictory evidence');
    check(
        $receiptContradiction instanceof CommandRefusalException
            && $receiptContradiction->reasonCode === 'capture_recovery_ambiguous'
            && str_contains($receiptContradiction->remediation, 'do not retry or discard'),
        'P9h: receipt/commit-proof contradiction exposes the stable no-retry recovery refusal'
    );
    check(is_dir(Publish::backup_dir($stateDir)) && is_file(Publish::intent_path($stateDir)), 'P9h: contradictory receipt/DB evidence preserves every recovery artifact');

    // Cleanup validates the matching intent before deleting the retained old
    // tree. Losing that intent cannot turn an unknown directory into deletion
    // authority merely because a receipt file survives.
    unlink(Publish::intent_path($stateDir));
    $missingIntentFailure = null;
    try {
        Publish::cleanup_committed($stateDir, $receipt);
    } catch (Throwable $t) {
        $missingIntentFailure = $t;
    }
    check($missingIntentFailure instanceof RuntimeException && str_contains($missingIntentFailure->getMessage(), 'no matching intent'), 'P9i: retained-artifact cleanup refuses without its matching intent');
    check(
        $missingIntentFailure instanceof CommandRefusalException
            && $missingIntentFailure->reasonCode === 'capture_recovery_ambiguous'
            && str_contains($missingIntentFailure->remediation, 'do not retry or discard'),
        'P9i: cleanup evidence contradiction exposes the stable no-retry recovery refusal'
    );
    check(is_dir(Publish::backup_dir($stateDir)), 'P9i: missing-intent refusal occurs before deleting the retained tree');
}

// ======================================================================
// P12 — generic intent CAS remains crash-recoverable without broadening
// init's external-writer exclusion to ordinary capture.
// ======================================================================
echo "\n== P12: generic intent transition crash recovery ==\n";
{
    if (!function_exists('pcntl_fork')) {
        check(true, 'P12: pcntl unavailable; generic transition SIGKILL case skipped');
    } else {
        foreach (['record-transition-next', 'record-transition-previous', 'record-transition-canonical'] as $phase) {
            $root = fresh_root('generic_record_transition_' . str_replace('-', '_', $phase));
            $stateDir = "$root/state";
            mkdir($stateDir);
            Canon::write_file("$stateDir/revision.txt", "stable\n");
            $staging = Publish::stage_dir($stateDir);
            mkdir($staging);
            Canon::write_file("$staging/revision.txt", "candidate\n");
            $intent = Publish::begin_intent($stateDir, $staging);
            Publish::swap($stateDir, true);

            $pid = pcntl_fork();
            if ($pid === 0) {
                putenv('DUO_TEST_MODE=1');
                putenv("DUO_TEST_PUBLISH_KILL_PHASE=$phase");
                Publish::mark_swapped($stateDir, $intent);
                exit(97);
            }
            check($pid > 0, "P12a $phase: generic transition crash child spawned");
            pcntl_waitpid($pid, $status);
            check(
                pcntl_wifsignaled($status) && pcntl_wtermsig($status) === 9,
                "P12b $phase: child died at the durable transition checkpoint"
            );
            if ($phase === 'record-transition-previous') {
                $recoveryPid = pcntl_fork();
                if ($recoveryPid === 0) {
                    putenv('DUO_TEST_MODE=1');
                    putenv('DUO_TEST_PUBLISH_KILL_PHASE=record-transition-recover-prior');
                    Publish::recover($stateDir, static fn(array $found): bool => false);
                    exit(98);
                }
                check($recoveryPid > 0, 'P12c: interrupted-recovery child spawned');
                pcntl_waitpid($recoveryPid, $recoveryStatus);
                check(
                    pcntl_wifsignaled($recoveryStatus) && pcntl_wtermsig($recoveryStatus) === 9,
                    'P12c: recovery child died after restoring the prior canonical record'
                );
            }
            $notes = Publish::recover($stateDir, static fn(array $found): bool => false);
            check(
                str_contains(implode("\n", $notes), 'transition'),
                "P12d $phase: recovery reports the interrupted record transition"
            );
            check(
                file_get_contents("$stateDir/revision.txt") === "stable\n",
                "P12e $phase: fresh recovery restores the old tree after resolving the record transition"
            );
            check(
                !is_file(Publish::intent_path($stateDir))
                    && !file_exists(Publish::intent_path($stateDir) . '.previous')
                    && !file_exists(Publish::intent_path($stateDir) . '.next'),
                "P12f $phase: recovery consumes only its sealed transition artifacts"
            );
        }

        // A kill after the fixed `.next` link but before the canonical link
        // retains two ordinary hard links to one sealed record inode. Model
        // a stale negative is_file() result for that `.next` name: recovery
        // must validate its fresh lstat identity, remove only the matching
        // owned temp, and discard the unpublished transition without touching
        // the prior state tree.
        $root = fresh_root('generic_record_create_next_cache');
        $stateDir = "$root/state";
        mkdir($stateDir);
        Canon::write_file("$stateDir/revision.txt", "stable\n");
        $staging = Publish::stage_dir($stateDir);
        mkdir($staging);
        Canon::write_file("$staging/revision.txt", "candidate\n");
        $pid = pcntl_fork();
        if ($pid === 0) {
            putenv('DUO_TEST_MODE=1');
            putenv('DUO_TEST_PUBLISH_KILL_PHASE=record-create-next');
            Publish::begin_intent($stateDir, $staging);
            exit(99);
        }
        check($pid > 0, 'P12g-next-cache: record-create-next crash child spawned');
        pcntl_waitpid($pid, $status);
        check(
            pcntl_wifsignaled($status) && pcntl_wtermsig($status) === 9,
            'P12g-next-cache: child died after publishing the fixed next-transition link'
        );
        $intentPath = Publish::intent_path($stateDir);
        $nextPath = $intentPath . '.next';
        check(
            is_file($nextPath) && count(glob($intentPath . '.tmp.*')) === 1,
            'P12g-next-cache: interrupted create retains one regular next slot and its matching temp hard link'
        );
        putenv("DUO_TEST_STALE_IS_FILE_PATH=$nextPath");
        try {
            $notes = Publish::recover($stateDir, static fn(array $found): bool => false);
        } finally {
            putenv('DUO_TEST_STALE_IS_FILE_PATH');
        }
        check(
            str_contains(implode("\n", $notes), 'unpublished')
                && file_get_contents("$stateDir/revision.txt") === "stable\n"
                && !file_exists($nextPath)
                && glob($intentPath . '.tmp.*') === [],
            'P12g-next-cache: stale next-path metadata cannot block exact unpublished-transition recovery'
        );

        // A capture record is published by hard-linking a freshly-written
        // temp into the canonical name. Model the stale negative is_file()
        // result Docker Desktop returned for that new canonical hard link.
        // Record readback must use a fresh lstat and bind the opened
        // descriptor to the canonical dev+ino through the read. A real
        // non-file boundary remains covered immediately below.
        $root = fresh_root('generic_record_hardlink_readback');
        $stateDir = "$root/state";
        $staging = Publish::stage_dir($stateDir);
        write_tree($staging, ['revision.txt' => "candidate\n"]);
        $intentPath = Publish::intent_path($stateDir);
        putenv('DUO_TEST_STALE_IS_FILE_PREFIX=' . $intentPath . '.tmp.');
        try {
            $intent = Publish::begin_intent($stateDir, $staging);
        } finally {
            putenv('DUO_TEST_STALE_IS_FILE_PREFIX');
        }
        check(
            glob($intentPath . '.tmp.*') === [],
            'P12g-cache: stale temp-path metadata cannot strand the owned record hard link'
        );
        putenv("DUO_TEST_STALE_IS_FILE_PATH=$intentPath");
        try {
            $readback = Publish::intent_record($stateDir);
        } finally {
            putenv('DUO_TEST_STALE_IS_FILE_PATH');
        }
        check(
            ($readback['id'] ?? null) === ($intent['id'] ?? null),
            'P12g-cache: stale canonical-path metadata cannot hide the sealed regular record inode'
        );

        $root = fresh_root('generic_record_nonfile_slot');
        $stateDir = "$root/state";
        mkdir($stateDir);
        Canon::write_file("$stateDir/revision.txt", "stable\n");
        $staging = Publish::stage_dir($stateDir);
        mkdir($staging);
        Canon::write_file("$staging/revision.txt", "candidate\n");
        Publish::begin_intent($stateDir, $staging);
        mkdir(Publish::intent_path($stateDir) . '.next');
        $slotFailure = null;
        try {
            Publish::recover($stateDir, static fn(array $found): bool => false);
        } catch (Throwable $t) {
            $slotFailure = $t;
        }
        check(
            $slotFailure instanceof CommandRefusalException
                && $slotFailure->reasonCode === 'capture_recovery_ambiguous',
            'P12g: a non-file transition slot fails closed with the stable recovery refusal'
        );
        check(
            is_dir(Publish::intent_path($stateDir) . '.next')
                && is_file(Publish::intent_path($stateDir))
                && is_dir($staging),
            'P12h: non-file transition evidence and candidate bytes are preserved'
        );

        foreach (['intent', 'receipt'] as $label) {
            $root = fresh_root('generic_fresh_previous_' . $label);
            $stateDir = "$root/state";
            mkdir($stateDir);
            Canon::write_file("$stateDir/revision.txt", "stable\n");
            $staging = Publish::stage_dir($stateDir);
            mkdir($staging);
            Canon::write_file("$staging/revision.txt", "candidate\n");
            if ($label === 'intent') {
                $path = Publish::intent_path($stateDir);
                Canon::write_file($path . '.previous', "foreign\n");
                $freshFailure = null;
                try {
                    Publish::begin_intent($stateDir, $staging);
                } catch (Throwable $t) {
                    $freshFailure = $t;
                }
            } else {
                $intent = Publish::begin_intent($stateDir, $staging);
                Publish::swap($stateDir, true);
                $intent = Publish::mark_swapped($stateDir, $intent, true);
                $intent = Publish::mark_commit_ready($stateDir, $intent, true);
                $intent = Publish::mark_committing($stateDir, $intent, true);
                $path = Publish::receipt_path($stateDir);
                Canon::write_file($path . '.previous', "foreign\n");
                $freshFailure = null;
                try {
                    Publish::write_receipt($stateDir, $intent, true);
                } catch (Throwable $t) {
                    $freshFailure = $t;
                }
            }
            check(
                $freshFailure instanceof RuntimeException,
                "P12i $label: a pre-existing previous slot refuses fresh canonical record publication"
            );
            check(
                !file_exists($path) && file_get_contents($path . '.previous') === "foreign\n",
                "P12j $label: fresh refusal preserves the previous slot and creates no canonical record"
            );
        }

        foreach (['record-removal-next', 'record-removal-previous', 'record-removal-next-only'] as $phase) {
            $root = fresh_root('generic_record_removal_' . str_replace('-', '_', $phase));
            $stateDir = "$root/state";
            mkdir($stateDir);
            Canon::write_file("$stateDir/revision.txt", "stable\n");
            $staging = Publish::stage_dir($stateDir);
            mkdir($staging);
            Canon::write_file("$staging/revision.txt", "candidate\n");
            $intent = Publish::begin_intent($stateDir, $staging);
            Publish::swap($stateDir, true);
            $intent = Publish::mark_swapped($stateDir, $intent);
            $intent = Publish::mark_commit_ready($stateDir, $intent);
            $intent = Publish::mark_committing($stateDir, $intent);
            $receipt = Publish::write_receipt($stateDir, $intent);

            $pid = pcntl_fork();
            if ($pid === 0) {
                putenv('DUO_TEST_MODE=1');
                putenv("DUO_TEST_PUBLISH_KILL_PHASE=$phase");
                Publish::cleanup_committed($stateDir, $receipt);
                exit(99);
            }
            check($pid > 0, "P12k $phase: generic removal crash child spawned");
            pcntl_waitpid($pid, $status);
            check(
                pcntl_wifsignaled($status) && pcntl_wtermsig($status) === 9,
                "P12l $phase: child died at the durable removal checkpoint"
            );
            $notes = Publish::recover($stateDir, static fn(array $found): bool => true);
            check(
                file_get_contents("$stateDir/revision.txt") === "candidate\n",
                "P12m $phase: committed candidate survives interrupted record removal"
            );
            check(
                !file_exists(Publish::intent_path($stateDir))
                    && !file_exists(Publish::intent_path($stateDir) . '.previous')
                    && !file_exists(Publish::intent_path($stateDir) . '.next')
                    && is_file(Publish::receipt_path($stateDir)),
                "P12n $phase: recovery completes intent removal and retains its audit receipt"
            );
            check(
                str_contains(implode("\n", $notes), 'transition')
                    || str_contains(implode("\n", $notes), 'record removal')
                    || str_contains(implode("\n", $notes), 'retained capture artifacts'),
                "P12o $phase: recovery reports the interrupted removal outcome"
            );
        }
    }
}

// ======================================================================
echo "\n";

// ======================================================================
// P10 — protocol roots never follow symlinks outside the destination
// ======================================================================
echo "\n== P10: symlinked publication roots fail closed ==\n";
{
    // A state/ symlink used to be mistaken for an existing published tree:
    // swap() moved that link to capture-backup and rrmdir() then traversed
    // the external target. The target must remain untouched and the link
    // must remain available as evidence after refusal.
    $root = fresh_root('symlink_swap');
    $outside = fresh_root('symlink_swap_external');
    $stateDir = "$root/state";
    write_tree($outside, ['keep.txt' => "must-survive\n"]);
    symlink($outside, $stateDir);
    write_tree(Publish::stage_dir($stateDir), ['candidate.txt' => "new\n"]);
    $swapFailure = null;
    try {
        // Include a trailing separator: PHP otherwise reports is_link() false
        // for a directory symlink spelled as /state/.
        Publish::swap($stateDir . '/');
    } catch (Throwable $t) {
        $swapFailure = $t;
    }
    check(
        $swapFailure instanceof RuntimeException && str_contains($swapFailure->getMessage(), 'symlink'),
        'P10a: swap() refuses a symlinked published root before any rename/cleanup'
    );
    check(is_file("$outside/keep.txt"), 'P10a: swap() refusal never deletes the external target');
    check(is_link($stateDir) && is_dir(Publish::stage_dir($stateDir)), 'P10a: symlink and staged evidence remain intact');
    unlink($stateDir);

    // Legacy/no-intent recovery has a direct stale-backup cleanup branch.
    // A symlink at that root must not turn rrmdir() into an external-tree
    // deletion, even when state/ itself is an ordinary directory.
    $root = fresh_root('symlink_recover');
    $outside = fresh_root('symlink_recover_external');
    $stateDir = "$root/state";
    write_tree($stateDir, ['current.txt' => "published\n"]);
    write_tree($outside, ['keep.txt' => "must-survive\n"]);
    symlink($outside, Publish::backup_dir($stateDir));
    $recoverFailure = null;
    try {
        Publish::recover($stateDir);
    } catch (Throwable $t) {
        $recoverFailure = $t;
    }
    check(
        $recoverFailure instanceof RuntimeException && str_contains($recoverFailure->getMessage(), 'symlink'),
        'P10b: recover() refuses a symlinked backup root before stale cleanup'
    );
    check(is_file("$outside/keep.txt"), 'P10b: recover() refusal never deletes the external target');
    check(is_link(Publish::backup_dir($stateDir)) && read_tree($stateDir) === ['current.txt' => "published\n"], 'P10b: backup link and published evidence remain intact');
    unlink(Publish::backup_dir($stateDir));

    // The receipt path is valid, matching intent/receipt evidence is valid,
    // and the backup digest is deliberately made to match the receipt. This
    // reaches the exact cleanup_committed() branch that previously deleted
    // an external keep.txt through a symlinked backup root.
    $root = fresh_root('symlink_cleanup');
    $outside = fresh_root('symlink_cleanup_external');
    $stateDir = "$root/state";
    write_tree($stateDir, ['revision.txt' => "old\n", 'keep.txt' => "must-survive\n"]);
    write_tree(Publish::stage_dir($stateDir), ['revision.txt' => "candidate\n"]);
    $intent = Publish::begin_intent($stateDir, Publish::stage_dir($stateDir));
    Publish::swap($stateDir, true);
    $intent = Publish::mark_swapped($stateDir, $intent);
    $intent = Publish::mark_commit_ready($stateDir, $intent);
    $intent = Publish::mark_committing($stateDir, $intent);
    $receipt = Publish::write_receipt($stateDir, $intent);
    Publish::rrmdir(Publish::backup_dir($stateDir));
    write_tree($outside, ['revision.txt' => "old\n", 'keep.txt' => "must-survive\n"]);
    symlink($outside, Publish::backup_dir($stateDir));
    $cleanupFailure = null;
    try {
        Publish::cleanup_committed($stateDir, $receipt);
    } catch (Throwable $t) {
        $cleanupFailure = $t;
    }
    check(
        $cleanupFailure instanceof RuntimeException && str_contains($cleanupFailure->getMessage(), 'symlink'),
        'P10c: cleanup_committed() refuses a symlinked backup root before hashing/deletion'
    );
    check(is_file("$outside/keep.txt"), 'P10c: cleanup refusal never deletes the external target');
    check(is_link(Publish::backup_dir($stateDir)) && is_file(Publish::intent_path($stateDir)), 'P10c: retained backup link and matching intent remain for inspection/retry');
    unlink(Publish::backup_dir($stateDir));
}

// ======================================================================
echo "\n";

// ======================================================================
// P11 — strict first-publication ownership and compensation
// ======================================================================
echo "\n== P11: strict first-publication ownership ==\n";
{
    $root = fresh_root('initial_lock_identity');
    $stateDir = "$root/state";
    $lock = Publish::lock_new($stateDir);
    Publish::assert_lock_path($lock, $stateDir);
    unlink(Publish::lock_path($stateDir));
    Canon::write_file(Publish::lock_path($stateDir), "foreign-lock\n");
    $lockFailure = null;
    try {
        Publish::assert_lock_path($lock, $stateDir);
    } catch (Throwable $t) {
        $lockFailure = $t;
    }
    check($lockFailure instanceof RuntimeException, 'P11a: detached flock inode is refused before publication');
    check(file_get_contents(Publish::lock_path($stateDir)) === "foreign-lock\n", 'P11a: replacement lock bytes are preserved');
    Publish::unlock($lock);

    $root = fresh_root('initial_stage_manifest');
    $stateDir = "$root/state";
    $staging = Publish::stage_dir($stateDir);
    $manifest = Publish::write_entities_fresh($staging, [
        ['path' => 'options/core.json', 'content' => "{}\n"],
        ['path' => 'posts/a.json', 'content' => "{\"a\":1}\n"],
    ]);
    Publish::assert_owned_tree($staging, $manifest, 'strict staging fixture');
    $original = "$staging/options/core.json.original";
    rename("$staging/options/core.json", $original);
    Canon::write_file("$staging/options/core.json", "{}\n");
    $replaceFailure = null;
    try {
        Publish::remove_owned_tree($staging, $manifest, 'strict staging fixture');
    } catch (Throwable $t) {
        $replaceFailure = $t;
    }
    check($replaceFailure instanceof RuntimeException, 'P11b: same-byte inode replacement blocks recursive compensation');
    check(is_file("$staging/options/core.json") && is_file($original), 'P11b: both replacement and retained evidence survive refusal');

    $root = fresh_root('initial_stage_injection');
    $stateDir = "$root/state";
    $staging = Publish::stage_dir($stateDir);
    $manifest = Publish::write_entities_fresh($staging, [
        ['path' => 'options/core.json', 'content' => "{}\n"],
    ]);
    Canon::write_file("$staging/foreign-sentinel", "must-survive\n");
    $injectFailure = null;
    try {
        Publish::remove_owned_tree($staging, $manifest, 'strict staging fixture');
    } catch (Throwable $t) {
        $injectFailure = $t;
    }
    check($injectFailure instanceof RuntimeException, 'P11c: injected child blocks recursive compensation');
    check(file_get_contents("$staging/foreign-sentinel") === "must-survive\n", 'P11c: injected child is never deleted');

    $root = fresh_root('initial_media_parent');
    $media = "$root/media";
    mkdir($media);
    $mediaManifest = Publish::tree_ownership_manifest($media);
    rename($media, "$root/original-media");
    mkdir($media);
    Canon::write_file("$media/sentinel", "must-survive\n");
    $mediaFailure = null;
    try {
        Publish::write_file_fresh("$media/blob", "blob\n", 'media fixture', $mediaManifest['root']);
    } catch (Throwable $t) {
        $mediaFailure = $t;
    }
    check($mediaFailure instanceof RuntimeException, 'P11d: replacement media root refuses before writing a blob');
    check(!file_exists("$media/blob") && file_get_contents("$media/sentinel") === "must-survive\n", 'P11d: replacement media root receives no Duo bytes');

    $root = fresh_root('initial_state_swap');
    $stateDir = "$root/state";
    mkdir($stateDir);
    $stateManifest = Publish::tree_ownership_manifest($stateDir);
    $staging = Publish::stage_dir($stateDir);
    $stagingManifest = Publish::write_entities_fresh($staging, [
        ['path' => 'options/core.json', 'content' => "{}\n"],
    ]);
    rename($stateDir, "$root/original-state");
    mkdir($stateDir);
    Canon::write_file("$stateDir/foreign-sentinel", "must-survive\n");
    $swapFailure = null;
    try {
        Publish::swap_initial($stateDir, $stateManifest, $stagingManifest);
    } catch (Throwable $t) {
        $swapFailure = $t;
    }
    check($swapFailure instanceof RuntimeException, 'P11e: foreign state substitution blocks the initial swap');
    check(file_get_contents("$stateDir/foreign-sentinel") === "must-survive\n", 'P11e: foreign state remains at its canonical path');
    check(is_dir($staging), 'P11e: verified candidate remains staged for inspection');

    $root = fresh_root('initial_copy_digest_change');
    $source = "$root/source.txt";
    $destinationRoot = "$root/destination";
    Canon::write_file($source, "reviewed source bytes\n");
    mkdir($destinationRoot);
    $copyFailure = null;
    try {
        Publish::copy_file_fresh(
            $source,
            "$destinationRoot/copied.txt",
            str_repeat('0', 64),
            0644,
            'changed source fixture',
            Publish::directory_ownership_identity($destinationRoot)
        );
    } catch (Throwable $t) {
        $copyFailure = $t;
    }
    check(
        $copyFailure instanceof RuntimeException
            && str_contains($copyFailure->getMessage(), 'copy source digest changed'),
        'P11f: a source digest change is detected after the bound destination write'
    );
    check(
        !file_exists("$destinationRoot/copied.txt")
            && file_get_contents($source) === "reviewed source bytes\n",
        'P11f: the helper removes only its still-owned partial destination after source change'
    );

    $root = fresh_root('initial_lock_acquire_failure');
    $stateDir = "$root/state";
    putenv('DUO_TEST_MODE=1');
    putenv('DUO_TEST_INIT_FAIL_PHASE=lock-acquire-after-create');
    $lockCreateFailure = null;
    try {
        Publish::lock_new($stateDir);
    } catch (Throwable $t) {
        $lockCreateFailure = $t;
    } finally {
        putenv('DUO_TEST_INIT_FAIL_PHASE');
        putenv('DUO_TEST_MODE');
    }
    check(
        $lockCreateFailure instanceof RuntimeException,
        'P11g: post-create first-lock acquisition failure is observable'
    );
    check(
        !file_exists(Publish::lock_path($stateDir)) && !is_link(Publish::lock_path($stateDir)),
        'P11g: failed acquisition removes only the exact lock inode it just created'
    );

    $root = fresh_root('initial_contradictory_receipt');
    $stateDir = "$root/state";
    mkdir($stateDir);
    $reservation = Publish::tree_ownership_manifest($stateDir);
    $staging = Publish::stage_dir($stateDir);
    $candidate = Publish::write_entities_fresh($staging, [
        ['path' => 'options/core.json', 'content' => "{}\n"],
    ]);
    $intent = Publish::begin_intent($stateDir, $staging, true);
    Publish::swap_initial($stateDir, $reservation, $candidate);
    $intent = Publish::mark_swapped($stateDir, $intent, true);
    $intent = Publish::mark_commit_ready($stateDir, $intent, true);
    $intent = Publish::mark_committing($stateDir, $intent, true);
    Publish::write_receipt($stateDir, $intent, true);
    $receiptPath = Publish::receipt_path($stateDir);
    $receipt = Canon::decode(Canon::read_file($receiptPath));
    $receipt['candidate_sha256'] = str_repeat('f', 64);
    unset($receipt['record_sha256']);
    $receipt['record_sha256'] = hash('sha256', Canon::encode($receipt));
    Canon::write_file($receiptPath, Canon::encode($receipt));
    $receiptFailure = null;
    try {
        Publish::recover_initial($stateDir, $reservation, $candidate, static fn(array $row): bool => true);
    } catch (Throwable $t) {
        $receiptFailure = $t;
    }
    check(
        $receiptFailure instanceof RuntimeException
            && str_contains($receiptFailure->getMessage(), 'receipt contradicts'),
        'P11h: a sealed contradictory initial receipt fails before cleanup'
    );
    check(
        is_dir(Publish::backup_dir($stateDir)) && is_file(Publish::intent_path($stateDir)),
        'P11h: contradictory receipt preserves the exact reservation and intent evidence'
    );
}

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
