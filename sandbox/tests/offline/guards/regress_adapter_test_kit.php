<?php
/**
 * Offline invariant — the adapter test kit is ASSEMBLED from the live harness,
 * runs on `php` alone from outside this repository, and is no part of what a
 * managed site receives.
 *
 * WHY THIS EXISTS
 * ---------------
 * `cli/src/Onboarding/Adopt.php` tars exactly `agent recovery`, so nothing
 * under `sandbox/` reaches anyone — yet `sandbox/` is
 * where the entire ability to PROVE an adapter lives. A third party writing an
 * adapter therefore reinvents the harness, and `sandbox/tests/lib/README.md`
 * counts what that costs in-tree already: 42 bespoke `$wpdb` fakes and 33
 * bespoke stub sets that disagree with each other. The property every one of
 * them gets wrong is the load-bearing one — `FakeWpdb::unsupported()`
 * (`sandbox/tests/lib/FakeWpdb.php:1143-1149`) throws `\LogicException` NAMING
 * the statement, where a hand-rolled fake returns `null` and pushes the suite
 * down a "no row" branch the real database never takes. Every assertion after
 * that point is green for the wrong reason.
 *
 * `tools/adapter-kit.php` packages the already-generic half of `sandbox/` so
 * that harness can be handed out. This suite holds up the four properties that
 * make the packaging worth anything, because each of them fails silently:
 *
 *   1. ASSEMBLED, NOT COPIED. The kit's bytes are read from the live files at
 *      assembly time and exist nowhere else in the tree (clauses B, I), and
 *      `tools/adapter-kit.json`'s digests track them, so an edit to
 *      `FakeWpdb.php` moves a reviewable line instead of quietly shipping a
 *      kit that disagrees with the harness WPrism's own suites run against
 *      (clauses A, F — `make release-gate` fails the same way).
 *   2. IT RUNS WHERE IT LANDS. The generated skeleton executes with `php` and
 *      nothing else, from a directory with no `agent/` above it and no repo
 *      environment (clause C). A kit member that grows an out-of-kit
 *      `require` still works in-repo and nowhere else, so that is refused BY
 *      NAME at build time rather than discovered by whoever received it
 *      (clause G).
 *   3. THE ANTI-FALSE-GREEN PROPERTY SURVIVES THE COPY. The shipped
 *      `FakeWpdb` still refuses an uninterpretable statement by name
 *      (clause D), asserted against the ASSEMBLED file rather than the
 *      in-repo one.
 *   4. IT IS NOT SHIPPED TO SITES. Adopt's tar line is still exactly
 *      `agent recovery` (clause E). AGENTS.md rule 1 — the drop-in
 *      is dependency-free — is untouched by a test harness precisely because
 *      the harness never enters that archive, and that is checked here rather
 *      than promised in a comment.
 *
 * Scratch is a `sys_get_temp_dir()` mkdir, never a literal fixed temp path:
 * `tools/offline.php:332-359` forces a suite naming one into the mutually
 * exclusive serial group, and this suite has no reason to be there.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

// PHP strips `#!...` only from the ENTRY script, never from an include, so a
// plain require of an executable tool prints its shebang into this suite's
// stdout. The file is otherwise side-effect free: ak_main() sits behind the
// same SCRIPT_FILENAME guard tools/offline-corpus.php uses.
ob_start();
require_once __DIR__ . '/../../../../tools/adapter-kit.php';
ob_end_clean();

use WPrism\Tooling\AdapterKit;

$repo = dirname(__DIR__, 4);

/** Every scratch root this run created, removed at the end. */
$scratchRoots = [];

$makeScratch = static function (string $label) use (&$scratchRoots): string {
    $base = rtrim(sys_get_temp_dir(), '/') . '/wprism-adapter-kit-' . $label . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (!mkdir($base, 0o700, true) && !is_dir($base)) {
        throw new RuntimeException('cannot create scratch ' . $base);
    }
    $scratchRoots[] = $base;

    return $base;
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);

        return;
    }
    @unlink($path);
};

// Cleanup on the way out however this run ends. A suite whose scratch removal
// is the last statement leaks a kit-sized directory on every red run, and the
// red runs are the ones that get repeated.
register_shutdown_function(static function () use (&$scratchRoots, $removeTree): void {
    foreach ($scratchRoots as $root) {
        $removeTree($root);
    }
});

// ---------------------------------------------------------------- clause A
// The committed checksum sheet matches the tree. This is the same comparison
// `make release-gate` runs; asserting it here means the offline gate names the
// drift too, with the one remedy, instead of leaving it to a separate command.

$committedRaw = (string) file_get_contents($repo . '/' . AdapterKit::MANIFEST_PATH);

// The projection can REFUSE rather than differ — an undeclared out-of-kit
// require in a packaged file is a build refusal, not a moved digest — and a
// refusal must arrive here as this suite's failed assertion carrying the
// tool's own message, never as a fatal that replaces FAIL/PASS with exit 255.
try {
    $generatedRaw = AdapterKit::render(AdapterKit::manifest($repo));
    wprism_check_same(
        $generatedRaw,
        $committedRaw,
        'tools/adapter-kit.json is the current projection of the tree (remedy: php tools/adapter-kit.php --write)'
    );
    if ($committedRaw !== $generatedRaw) {
        foreach (AdapterKit::drift($committedRaw, $generatedRaw) as $row) {
            wprism_check_detail($row);
        }
    }
} catch (Throwable $projectionError) {
    wprism_check(false, 'tools/adapter-kit.json is the current projection of the tree (the projection refused)');
    wprism_check_detail(get_class($projectionError) . ': ' . $projectionError->getMessage());
}

$manifest = json_decode($committedRaw, true, 512, JSON_THROW_ON_ERROR);
wprism_check_same(AdapterKit::FORMAT, $manifest['format'], 'the manifest declares the kit wire generation');

/** @var list<array<string,mixed>> $members */
$members = $manifest['members'];

// ---------------------------------------------------------------- clause B
// A kit assembles into an empty directory, and every member it wrote is the
// bytes the manifest promised. For the copied half those bytes must also be
// hash-identical to the LIVE source: that is the whole "assembled, not copied"
// claim, and it is the one an ordinary `cp` into a checked-in fixture would
// break the day someone fixed the original.

$kitRoot = $makeScratch('kit');
$kit = $kitRoot . '/kit';

// assemble() refuses outright while the committed sheet is stale (clause A),
// which is the right product behaviour and the wrong suite behaviour: an
// uncaught throw here would replace this suite's own FAIL/PASS contract with a
// fatal, and exit 255 instead of 1. Catch it, report it as the failed
// assertion it is, and say which clauses could not run.
$written = [];
try {
    $written = AdapterKit::assemble($repo, $kit);
    wprism_check(true, 'the kit assembles from the live tree');
} catch (Throwable $assembleError) {
    wprism_check(false, 'the kit assembles from the live tree');
    foreach (explode("\n", $assembleError->getMessage()) as $line) {
        wprism_check_detail($line);
    }
    wprism_check_detail('clauses B, C and D below now report against a kit that was never written');
}

wprism_check_same(
    count($members) + 1,
    count($written),
    'assemble() writes every manifest member plus MANIFEST.json'
);
wprism_check(
    is_file($kit . '/' . AdapterKit::KIT_MANIFEST),
    'the assembled kit carries its own MANIFEST.json, so a recipient can tell one revision from another'
);

$copiedCount = 0;
foreach ($members as $member) {
    $path = (string) $member['path'];
    $file = $kit . '/' . $path;
    if (!is_file($file)) {
        wprism_check(false, "kit member $path was written");
        continue;
    }
    $bytes = (string) file_get_contents($file);
    wprism_check_same(
        [$member['bytes'], $member['sha256']],
        [strlen($bytes), hash('sha256', $bytes)],
        "kit member $path is exactly the bytes tools/adapter-kit.json pins"
    );
    if ($member['origin'] !== 'copied') {
        continue;
    }
    $copiedCount++;
    $source = $repo . '/' . $member['source'];
    wprism_check_same(
        hash_file('sha256', $source),
        hash('sha256', $bytes),
        "kit member $path is the live {$member['source']}, not a parallel copy"
    );
}
// Seven from WP-2.6, WP-2.7's lib/ConformanceVector.php, and the host-side
// orchestrator that proves schema/lifecycle phases without teaching an adapter
// how to impersonate the deploy CLI. Omitting that ninth file makes the kit's
// documented end-to-end path impossible outside this checkout.
wprism_check_same(10, $copiedCount, 'the kit packages all ten live authoring and host-orchestration harness files');

// The seven lib files and the two conformance files, by name: a silent drop
// (say frozen_policy.php) would still leave clauses A and B green.
$paths = array_map(static fn(array $m): string => (string) $m['path'], $members);
foreach (
    [
        'lib/check.php',
        'lib/wp_stubs.php',
        'lib/FakeWpdb.php',
        'lib/frozen_policy.php',
        'lib/ConformanceVector.php',
        'lib/pair_identity.sh',
        'lib/host_orchestrator.sh',
        'conformance/run.sh',
        'conformance/asserts.sh',
    ] as $required
) {
    wprism_check(in_array($required, $paths, true), "the kit carries $required");
}

// ---------------------------------------------------------------- clause C
// The generated skeleton runs with `php` and nothing else, from outside this
// repository. The ancestor walk is not decoration: the one out-of-kit
// dependency check.php declares is `../../../agent/src/Kernel/CommandRefusal.php`,
// so a scratch root that happened to sit inside a checkout would resolve it
// and this clause would prove nothing.
$ancestor = $kit;
$repoReal = realpath($repo);
while (($parent = dirname($ancestor)) !== $ancestor) {
    $ancestor = $parent;
    wprism_check(
        !is_file($ancestor . '/agent/src/Kernel/CommandRefusal.php') && realpath($ancestor) !== $repoReal,
        "no WPrism checkout above the assembled kit at $ancestor, so the run proves standalone execution"
    );
}

$skeleton = $kit . '/' . AdapterKit::skeletonSuitePath(AdapterKit::DEFAULT_ADAPTER);
wprism_check(is_file($skeleton), 'the kit generated a runnable skeleton suite');

// A deliberately bare environment: PATH only. No composer autoloader, no
// WordPress, no WPRISM_* variable, and a cwd outside the repository — if the kit
// needed any of those, this is where it says so.
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($skeleton),
    $descriptors,
    $pipes,
    $kitRoot,
    ['PATH' => (string) getenv('PATH')]
);
if (!is_resource($process)) {
    wprism_check(false, 'the skeleton suite could be started');
    $stdout = $stderr = '';
    $exit = 127;
} else {
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
}

wprism_check_same(0, $exit, 'the generated skeleton exits 0 under `php` alone, with PATH as its whole environment');
wprism_check_same('', trim($stderr), 'the generated skeleton emits nothing on stderr — a warning is not a pass');
wprism_check(
    str_contains($stdout, 'PASS: example adapter kit skeleton'),
    'the generated skeleton prints check.php\'s PASS line'
);
wprism_check_same(
    4,
    substr_count($stdout, "\nok: ") + (str_starts_with($stdout, 'ok: ') ? 1 : 0),
    'all four skeleton assertions ran — a suite that stops asserting is the other silent green'
);
if ($exit !== 0 || trim($stderr) !== '') {
    wprism_check_detail('stdout: ' . trim($stdout));
    wprism_check_detail('stderr: ' . trim($stderr));
}

// The skeleton must not reach out of the kit: `__DIR__ . '/../..'` or any
// absolute repo path in the generated files would run here and nowhere else.
foreach (['skeleton/regress_example_kit.php', 'skeleton/example-adapter.php'] as $generatedPath) {
    $bytes = is_file($kit . '/' . $generatedPath)
        ? (string) file_get_contents($kit . '/' . $generatedPath)
        : '<absent> ' . $repo;
    wprism_check(
        !str_contains($bytes, $repo) && !str_contains($bytes, 'sandbox/tests'),
        "$generatedPath names no path from the repository it was assembled in"
    );
    $external = AdapterKit::resolveTargets($paths, $generatedPath, AdapterKit::dependencyTargets($generatedPath, $bytes));
    wprism_check_same([], $external['external'], "$generatedPath requires only other kit members");
}

// ---------------------------------------------------------------- clause D
// The anti-false-green property, asserted against the ASSEMBLED FakeWpdb
// rather than the in-repo one: this is the file a third party receives, and a
// packaging step that quietly substituted a laxer fake would still leave every
// in-repo suite green.
// The require is guarded rather than bare: a kit that failed to assemble must
// still leave this suite reporting FAIL/PASS on its own exit code, not a fatal.
if (!is_file($kit . '/lib/FakeWpdb.php')) {
    wprism_check(false, 'the shipped FakeWpdb refuses an uninterpretable statement instead of answering null');
    wprism_check_detail('the kit holds no lib/FakeWpdb.php to assert against');
} else {
    require_once $kit . '/lib/FakeWpdb.php';
    $wpdb = new \WPrismTest\FakeWpdb();
    $wpdb->seedTable('wp_kit_probe', [['id' => 1, 'title' => 'a']]);
    // The probe is a LEFT JOIN with a TWO-condition ON, and the second
    // condition is what makes it a probe. A single-condition LEFT equi-join
    // stopped being uninterpretable when the interpreter grew the one join
    // form the engine's own term-deletion path needs
    // (FakeWpdb::parseLeftEquiJoin(); RelationshipMaterializer.php:287-295 is
    // its only product reader). Everything past that boundary — this shape,
    // INNER/RIGHT/CROSS, a comma join, UNION — still refuses BY NAME, which is
    // the property clause D is about: an author's fake must never answer a
    // statement it did not understand.
    $probe = 'SELECT f.id FROM wp_kit_probe AS f '
        . "LEFT JOIN wp_posts AS p ON p.ID = f.id AND p.post_type = 'page'";
    wprism_check_throws(
        static fn(): array => $wpdb->get_results($probe . ' WHERE p.ID IS NULL', ARRAY_A),
        LogicException::class,
        'the shipped FakeWpdb refuses an uninterpretable statement instead of answering null',
        'unsupported SQL'
    );
    // Throwable, not LogicException: a fake that was weakened to throw some
    // other type is exactly the defect under test, and it must be reported as
    // a failed assertion rather than escape as a fatal.
    try {
        $wpdb->get_results($probe, ARRAY_A);
        wprism_check(false, 'the refusal names the statement it could not interpret');
        wprism_check_detail('nothing was thrown — the statement was answered');
    } catch (Throwable $e) {
        wprism_check(
            $e instanceof LogicException && str_contains($e->getMessage(), 'LEFT JOIN'),
            'the refusal names the statement it could not interpret, so the author can act on it'
        );
        if (!($e instanceof LogicException) || !str_contains($e->getMessage(), 'LEFT JOIN')) {
            wprism_check_detail(get_class($e) . ': ' . $e->getMessage());
        }
    }
}

// ---------------------------------------------------------------- clause E
// The kit is NOT shipped to managed sites. Read the tar line out of Adopt.php
// here, independently of AdapterKit::adoptionTar(), so a bug in that reader
// cannot make this clause agree with itself.
$adoptSource = (string) file_get_contents($repo . '/' . AdapterKit::ADOPT_PATH);
wprism_check(
    str_contains($adoptSource, "escapeshellarg(\$localArchive) . ' agent recovery'"),
    'Adopt.php tars exactly `agent recovery`; adapter bytes are embedded beneath the atomic agent release'
);
wprism_check_same(
    ['agent', 'recovery'],
    AdapterKit::adoptionTar($repo),
    'the kit reads that composition out of Adopt.php rather than restating it'
);
wprism_check_same(
    ['agent', 'recovery'],
    $manifest['adoption_tar']['components'],
    'tools/adapter-kit.json records the tar a managed site receives, so a change to it lands in this diff'
);
foreach (['sandbox', 'tools', 'tests', 'kit'] as $absent) {
    wprism_check(
        !in_array($absent, $manifest['adoption_tar']['components'], true),
        "`$absent` is no part of the adoption archive — the kit is a developer artifact, not site payload"
    );
}
// Nothing that ships DEPENDS ON the kit or its assembler. If it did, AGENTS.md
// rule 1 (the drop-in is dependency-free) would have been loosened by a test
// harness, which is exactly the reading this WP has to foreclose.
//
// Read with token_get_all(), not a regex over the bytes: a docblock may
// legitimately CITE a sandbox file as evidence for a claim
// (cli/src/Assess/AssessReport.php:419 cites grind_lib.sh's leak gate;
// agent/wprism.php's load-order comment says "require blocks in agent/src and
// every sandbox…"), and a citation loads nothing. The tokenizer is what tells
// a prose "require" from the language construct.
$loadEvidence = static function (string $php): array {
    $found = [];
    $tokens = token_get_all($php);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }
        if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && str_contains((string) $token[1], 'WPrismTest')
        ) {
            $found[] = 'names the harness namespace: ' . trim((string) $token[1]);
            continue;
        }
        if (!in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
            continue;
        }
        $literal = '';
        for ($j = $i + 1; $j < $count; $j++) {
            $next = $tokens[$j];
            if ($next === ';') {
                break;
            }
            if (is_array($next) && $next[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal .= trim((string) $next[1], "'\"");
            }
        }
        if (str_contains($literal, 'sandbox') || str_contains($literal, 'adapter-kit')) {
            $found[] = 'loads ' . $literal;
        }
    }

    return $found;
};

foreach (['agent', 'cli', 'recovery'] as $shipped) {
    $hits = [];
    $walk = static function (string $dir) use (&$walk, &$hits, $repo, $loadEvidence): void {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $walk($path);
                continue;
            }
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            $relative = substr($path, strlen($repo) + 1);
            foreach ($loadEvidence((string) file_get_contents($path)) as $evidence) {
                $hits[] = $relative . ' ' . $evidence;
            }
        }
    };
    $walk($repo . '/' . $shipped);
    wprism_check_same([], $hits, "nothing under $shipped/ loads the kit or the harness it packages");
}

// ---------------------------------------------------------------- clause F
// An edit to a packaged file moves a digest. Driven against a synthetic tree so
// this suite never mutates the real one: the property under test is that the
// projection is a function of the bytes, and a synthetic tree proves it without
// leaving scratch under agent/ or manifests/ (AGENTS.md rule 3).
$synthesize = static function (string $root) use ($repo): void {
    $files = array_map(static fn(array $row): string => $row[0], AdapterKit::COPIED);
    $files[] = AdapterKit::ADOPT_PATH;
    foreach ($files as $relative) {
        $target = $root . '/' . $relative;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0o700, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('cannot create ' . dirname($target));
        }
        if (!copy($repo . '/' . $relative, $target)) {
            throw new RuntimeException('cannot copy ' . $relative);
        }
    }
};

$digestOf = static function (array $manifest, string $path): string {
    foreach ($manifest['members'] as $member) {
        if ($member['path'] === $path) {
            return (string) $member['sha256'];
        }
    }

    return '<absent>';
};

$synthetic = $makeScratch('synthetic');
$synthesize($synthetic);
$syntheticCheck = $synthetic . '/sandbox/tests/lib/check.php';
try {
    $baseline = AdapterKit::manifest($synthetic);
    wprism_check_same(
        AdapterKit::render(AdapterKit::manifest($repo)),
        AdapterKit::render($baseline),
        'a byte-identical tree projects a byte-identical manifest — the projection is a pure function of the sources'
    );

    file_put_contents($syntheticCheck, "\n// a one-line edit to a packaged harness file\n", FILE_APPEND);
    $edited = AdapterKit::manifest($synthetic);
    wprism_check(
        $digestOf($baseline, 'lib/check.php') !== $digestOf($edited, 'lib/check.php'),
        'editing a packaged harness file moves its digest, so `make release-gate` asks for a regeneration'
    );
    wprism_check_same(
        $digestOf($baseline, 'lib/FakeWpdb.php'),
        $digestOf($edited, 'lib/FakeWpdb.php'),
        'and moves only that one — the digests are per-member, not a whole-kit checksum nobody can read'
    );
} catch (Throwable $projectionError) {
    // The synthetic tree is a copy of the real packaged files, so a real tree
    // whose projection refuses (clause A) refuses here too. Report it; do not
    // let it become a fatal.
    wprism_check(false, 'the synthetic tree projects a manifest at all');
    wprism_check_detail(get_class($projectionError) . ': ' . $projectionError->getMessage());
    wprism_check_detail('clause F cannot compare digests until the projection stops refusing');
}

// ---------------------------------------------------------------- clause G
// A new out-of-kit dependency inside a packaged file is refused BY NAME. This
// is the failure that is invisible in-repo: the path resolves here, every
// offline suite stays green, and the kit works nowhere else.
$synthetic2 = $makeScratch('undeclared');
$synthesize($synthetic2);
file_put_contents(
    $synthetic2 . '/sandbox/tests/lib/check.php',
    "\nrequire_once __DIR__ . '/../../../agent/src/Kernel/Secrets.php';\n",
    FILE_APPEND
);
wprism_check_throws(
    static fn(): array => AdapterKit::manifest($synthetic2),
    RuntimeException::class,
    'an undeclared out-of-kit require in a packaged file refuses the build',
    '/../../../agent/src/Kernel/Secrets.php'
);

// The same list checked the other way: a declaration nothing reaches for any
// more is folklore, and folklore in a dependency list is how the list stops
// describing the kit.
$synthetic3 = $makeScratch('stale-declaration');
$synthesize($synthetic3);
$stale = $synthetic3 . '/sandbox/tests/lib/check.php';
$staleBytes = (string) file_get_contents($stale);
file_put_contents(
    $stale,
    str_replace("require_once __DIR__ . '/../../../agent/src/Kernel/CommandRefusal.php';", '', $staleBytes)
);
wprism_check_throws(
    static fn(): array => AdapterKit::manifest($synthetic3),
    RuntimeException::class,
    'a declared external dependency nothing reaches for any more refuses the build',
    'no longer reaches for'
);

// An unreadable tar line is a refusal, not a default: recording what a site
// receives is worthless if the reader can silently fall back to a guess.
$synthetic4 = $makeScratch('mangled-adopt');
$synthesize($synthetic4);
$adoptCopy = $synthetic4 . '/' . AdapterKit::ADOPT_PATH;
file_put_contents(
    $adoptCopy,
    str_replace(
        "escapeshellarg(\$localArchive) . ' agent recovery'",
        'escapeshellarg($localArchive) . self::COMPONENTS',
        (string) file_get_contents($adoptCopy)
    )
);
wprism_check_throws(
    static fn(): array => AdapterKit::adoptionTar($synthetic4),
    RuntimeException::class,
    'an unreadable adoption tar line refuses rather than recording a guess',
    'cannot read the adoption tar composition'
);

// ---------------------------------------------------------------- clause H
// Assembling over an existing kit is refused: a directory holding half of
// yesterday's kit and half of today's is the fork this tool exists to prevent,
// and it is the state a `--assemble` into a working directory produces.
wprism_check_throws(
    static fn(): array => AdapterKit::assemble($repo, $kit),
    RuntimeException::class,
    'assembling into a non-empty directory is refused',
    'refusing to assemble into a non-empty directory'
);

// A rejected slug never reaches the filesystem, so a kit cannot be assembled
// under a name that is not a legal class-name or file-name fragment.
wprism_check_throws(
    static fn(): array => AdapterKit::assemble($repo, $kitRoot . '/bad', 'Not A Slug'),
    RuntimeException::class,
    'an unusable adapter slug is refused before anything is written',
    'is not usable as a file and class name'
);
wprism_check(!is_dir($kitRoot . '/bad'), 'and the refusal left no directory behind');

// ---------------------------------------------------------------- clause I
// There is no second copy of a packaged file in the tree. This is the
// structural half of "assembled, not copied": as long as the only path in the
// repository holding `check.php`'s bytes is `sandbox/tests/lib/check.php`, a
// fix to the original cannot miss a shipped duplicate, because there is none.
$sizes = [];
foreach ($members as $member) {
    if ($member['origin'] === 'copied') {
        $sizes[(int) $member['bytes']][(string) $member['sha256']] = (string) $member['source'];
    }
}
$duplicates = [];
$scan = static function (string $dir, string $root) use (&$scan, $sizes, &$duplicates): void {
    foreach (scandir($dir) ?: [] as $entry) {
        if (
            $entry === '.' || $entry === '..' || $entry === 'vendor'
            || $entry === 'node_modules' || $entry === 'tmp' || $entry === 'siterepo'
        ) {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_link($path)) {
            continue;
        }
        if (is_dir($path)) {
            // Hidden directories are never repository harness content: .git is
            // object storage, and .claude/worktrees holds LIVE parallel-agent
            // worktrees whose checkouts legitimately carry the packaged files —
            // a raw filesystem walk read those as "second copies" the moment a
            // sibling work package ran (observed: wf_6de832ea worktrees). The
            // clause's claim is about the repository's own tree; dot-FILES stay
            // scanned, dot-DIRECTORIES are outside it.
            if ($entry[0] === '.') {
                continue;
            }
            // A NESTED CHECKOUT is not this repository's tree either. Git
            // itself refuses to track content below a directory carrying a
            // `.git` entry (an embedded repo or a linked worktree's gitdir
            // pointer file), and concurrent sessions on this machine park
            // exactly such checkouts at the repo root (observed: audit-f1d1/,
            // a sibling session's worktree whose full copy of the tree turned
            // every packaged harness file into a false "second copy"). The
            // clause's claim is about paths the OUTER repository can ship.
            if (file_exists($path . '/.git')) {
                continue;
            }
            $scan($path, $root);
            continue;
        }
        $size = filesize($path);
        if ($size === false || !isset($sizes[$size])) {
            continue;
        }
        $digest = hash_file('sha256', $path);
        $declared = $sizes[$size][$digest] ?? null;
        if ($declared === null) {
            continue;
        }
        $relative = substr($path, strlen($root) + 1);
        if ($relative !== $declared) {
            $duplicates[] = $relative . ' duplicates ' . $declared;
        }
    }
};
$scan($repo, $repo);
wprism_check_same(
    [],
    $duplicates,
    'no packaged harness file has a second copy in the tree — the kit is assembled from the one original'
);

// And the nested-checkout pruning above is pinned deterministically, because
// the live condition that exposed it (a sibling session's worktree parked at
// the repo root) comes and goes with that session. A scratch tree holds the
// one declared original, a nested-checkout dir (`.git` pointer file) with a
// byte-identical copy, and a plain dir with another: only the plain dir's
// copy is a duplicate. Before the pruning clause, this scan reported both.
$dupRoot = sys_get_temp_dir() . '/wprism_kit_nested_checkout_' . bin2hex(random_bytes(8));
$declaredSource = null;
foreach ($members as $member) {
    if ($member['origin'] === 'copied') {
        $declaredSource = (string) $member['source'];
        break;
    }
}
mkdir($dupRoot . '/' . dirname($declaredSource), 0700, true);
mkdir($dupRoot . '/sibling-worktree/nested', 0700, true);
mkdir($dupRoot . '/plain/nested', 0700, true);
copy($repo . '/' . $declaredSource, $dupRoot . '/' . $declaredSource);
copy($repo . '/' . $declaredSource, $dupRoot . '/sibling-worktree/nested/copy.php');
copy($repo . '/' . $declaredSource, $dupRoot . '/plain/nested/copy.php');
file_put_contents($dupRoot . '/sibling-worktree/.git', "gitdir: /elsewhere\n");
$duplicates = [];
$scan($dupRoot, $dupRoot);
wprism_check_same(
    ['plain/nested/copy.php duplicates ' . $declaredSource],
    $duplicates,
    'a nested checkout (a directory carrying a .git entry) is outside the tree the scan judges; a plain directory is not'
);
(static function (string $dir) use (&$rm): void {
    $rm = static function (string $d) use (&$rm): void {
        foreach (scandir($d) ?: [] as $e) {
            if ($e === '.' || $e === '..') { continue; }
            is_dir($d . '/' . $e) && !is_link($d . '/' . $e) ? $rm($d . '/' . $e) : unlink($d . '/' . $e);
        }
        rmdir($d);
    };
    $rm($dir);
})($dupRoot);

// Scratch removal is the registered shutdown handler's job, so it happens on
// the red path too; wprism_check_summary() exits, and an exit() runs shutdown
// functions.
wprism_check_summary('adapter test kit');
