<?php
/**
 * Offline regression for `wprism merge-check` — env-free repository-side merge
 * validation and ref-vs-ref conflict planning.
 *
 * WHAT FAILS WITHOUT THE CHANGE
 * -----------------------------
 * Every one of these questions was unanswerable before, and not by accident:
 * `Refresh::assertTargetHead()` (cli/src/Refresh/Refresh.php:540-575) refuses
 * unless a live production target's HEAD equals `--production-ref` with an
 * empty status walk, so B/P/W could only ever be assembled with production
 * reachable and clean. This suite drives the real executable against real Git
 * fixtures and asserts the answers arrive with NO environment at all — no
 * registry, no transport, no target.
 *
 * The headline case is C: DESIGN.md:182's Spike B ("edit X in A; edit Y plus
 * a conflicting field of X in B; git-merge"), asserted end to end through the
 * shipped planner rather than a test double.
 *
 * WHY IT DRIVES THE EXECUTABLE
 * ----------------------------
 * The deliverable IS an exit-code contract a customer's CI binds to (0 clean,
 * 1 refusal, 2 usage, 3 conflicts). An in-process call to `MergeCheck::run()`
 * would assert the document and miss the contract entirely, so case D is a
 * table over the real process's real exit status, and every other case runs
 * the same way.
 */
declare(strict_types=1);

// From offline/refresh/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../cli/src/Refresh/MergeCheck.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';

use WPrism\Orchestrator\Refresh;

$repoRoot = dirname(__DIR__, 4);
$wprism = $repoRoot . '/cli/wprism';

// --------------------------------------------------------------- fixtures

function mc_git(string $repo, array $args, bool $allowFailure = false): string {
    $command = 'git -C ' . escapeshellarg($repo);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg((string) $arg);
    }
    $lines = [];
    $status = 0;
    exec($command . ' 2>&1', $lines, $status);
    $output = implode("\n", $lines);
    if ($status !== 0 && !$allowFailure) {
        throw new RuntimeException("git failed ($status): $command\n$output");
    }
    return $output;
}

function mc_write(string $path, string $bytes): void {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("could not create fixture directory: $directory");
    }
    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
        throw new RuntimeException("could not write fixture file: $path");
    }
}

function mc_remove(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $child) {
        if ($child !== '.' && $child !== '..') {
            mc_remove($path . '/' . $child);
        }
    }
    @rmdir($path);
}

/**
 * One canonical post document.
 *
 * `comment_status` and `title` sit 13 lines apart in the sorted front matter,
 * which is what lets case C's `git merge` compose an edit to each WITHOUT a
 * textual conflict — the line-merged tree the operator actually holds, and
 * the only starting state that makes the semantic question interesting.
 */
function mc_post(string $uuid, string $slug, string $title, string $commentStatus = 'open', array $meta = []): string {
    $front = [
        'author' => null,
        'comment_status' => $commentStatus,
        'date' => '2026-08-09 00:00:00',
        'date_gmt' => '2026-08-09 00:00:00',
        'excerpt' => '',
        'menu_order' => 0,
        'meta' => $meta === [] ? new stdClass() : $meta,
        'modified' => '2026-08-09 00:00:00',
        'modified_gmt' => '2026-08-09 00:00:00',
        'parent' => null,
        'ping_status' => 'closed',
        'slug' => $slug,
        'status' => 'publish',
        'terms' => [],
        'title' => $title,
        'type' => 'post',
        'uuid' => $uuid,
    ];
    return "---\n"
        . json_encode($front, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        . "\n---\nbody\n";
}

/** @param list<string> $manifests */
function mc_site(array $manifests = []): string {
    return json_encode([
        'manifests' => $manifests,
        'policy' => [
            'options' => new stdClass(),
            'post_meta' => new stdClass(),
            'term_meta' => new stdClass(),
            'post_types' => ['post'],
            'taxonomies' => [],
        ],
        'spec_version' => 2,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

/** Exact Canon::encode() bytes for an intentionally empty option document. */
function mc_options(): string {
    return "{\n    \"format\": \"wprism-options/v1\",\n    \"records\": []\n}\n";
}

/** A `wprism-code-lock/v2` naming one component at one version, for case G. */
function mc_lock(string $version, string $treeDigest): string {
    return json_encode([
        'components' => [[
            'component' => 'woocommerce',
            'origin' => [
                'archive_sha256' => str_repeat('1', 64),
                'kind' => 'wp-org-release',
                'url' => 'https://downloads.wordpress.org/plugin/woocommerce.' . $version . '.zip',
            ],
            'root' => 'plugins',
            'tree_sha256' => $treeDigest,
            'version' => $version,
        ]],
        'first_party' => [],
        'format' => 'wprism-code-lock/v2',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

/**
 * Run the real `wprism` executable from inside $cwd.
 *
 * @return array{exit:int,stdout:string,stderr:string}
 */
function mc_run(string $wprism, string $cwd, array $args): array {
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, $wprism], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('could not start the wprism executable');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** @return array<string,mixed>|null */
function mc_json(string $raw): ?array {
    $decoded = json_decode(trim($raw), true);
    return is_array($decoded) ? $decoded : null;
}

$scratch = rtrim(sys_get_temp_dir(), '/') . '/wprism_merge_check_' . bin2hex(random_bytes(6));
$main = $scratch . '/site';
$probe = $scratch . '/probe';
register_shutdown_function(static function () use ($scratch): void {
    mc_remove($scratch);
});

$X = '00000000-0000-4000-8000-000000000001';
$Y = '00000000-0000-4000-8000-000000000002';
$missing = '00000000-0000-4000-8000-0000000000ff';
$pathX = 'state/posts/post/' . $X . '--x.md';
$pathY = 'state/posts/post/' . $Y . '--y.md';

mkdir($main, 0777, true);
mc_git($main, ['init', '-q', '-b', 'main']);
mc_git($main, ['config', 'user.email', 'wprism-merge-check-regression@example.test']);
mc_git($main, ['config', 'user.name', 'WPrism merge-check regression']);
mc_write($main . '/site.wprism.json', mc_site());
mc_write($main . '/state/options/core.json', mc_options());
mc_write($main . '/' . $pathX, mc_post($X, 'x', 'X title'));
mc_write($main . '/' . $pathY, mc_post($Y, 'y', 'Y title'));
mc_git($main, ['add', '.']);
mc_git($main, ['commit', '-qm', 'base']);
$baseCommit = trim(mc_git($main, ['rev-parse', 'HEAD']));

// The other side (P): one field of X changed.
mc_git($main, ['checkout', '-q', '-b', 'branch-a']);
mc_write($main . '/' . $pathX, mc_post($X, 'x', 'X title', 'closed'));
mc_git($main, ['commit', '-qam', 'a: X comment_status']);
$commitA = trim(mc_git($main, ['rev-parse', 'HEAD']));

// This side (W): a different field of X, plus an unrelated edit to Y.
mc_git($main, ['checkout', '-q', 'main']);
mc_git($main, ['checkout', '-q', '-b', 'branch-b']);
mc_write($main . '/' . $pathX, mc_post($X, 'x', 'X retitled'));
mc_write($main . '/' . $pathY, mc_post($Y, 'y', 'Y retitled'));
mc_git($main, ['add', '.']);
mc_git($main, ['commit', '-qm', 'b: X title and Y title']);
$commitB = trim(mc_git($main, ['rev-parse', 'HEAD']));

// The line-merged tree the operator actually holds after `git merge`.
mc_git($main, ['checkout', '-q', '-b', 'merged']);
$mergeOutput = mc_git($main, ['merge', '-q', '--no-edit', 'branch-a']);
$merged = trim(mc_git($main, ['rev-parse', 'HEAD']));
wprism_check(
    trim(mc_git($main, ['diff', '--name-only', '--diff-filter=U'])) === '',
    'fixture: git merge composed both edits to X textually, leaving no Git conflict'
        . ($mergeOutput === '' ? '' : " ($mergeOutput)")
);

// Three structurally incoherent trees, each a different compiler refusal class.
mc_git($main, ['checkout', '-q', 'main']);
mc_git($main, ['checkout', '-q', '-b', 'broken-duplicate-uuid']);
mc_write($main . '/state/posts/post/' . $X . '--duplicate.md', mc_post($X, 'duplicate', 'X duplicate'));
mc_git($main, ['add', '.']);
mc_git($main, ['commit', '-qm', 'two files claim one uuid']);

mc_git($main, ['checkout', '-q', 'main']);
mc_git($main, ['checkout', '-q', '-b', 'broken-options']);
mc_write($main . '/state/options/core.json', "{\n    \"format\": \"wprism-options/v99\",\n    \"records\": []\n}\n");
mc_git($main, ['commit', '-qam', 'options document declares an unknown format']);

// Case G's two refs: identical state, divergent locked component versions.
mc_git($main, ['checkout', '-q', 'main']);
mc_git($main, ['checkout', '-q', '-b', 'skew-newer']);
mc_write($main . '/code/wprism-code.lock.json', mc_lock('9.9.1', str_repeat('2', 64)));
mc_git($main, ['add', '.']);
mc_git($main, ['commit', '-qm', 'lock woocommerce 9.9.1']);
mc_git($main, ['checkout', '-q', 'main']);
mc_git($main, ['checkout', '-q', '-b', 'skew-older']);
mc_write($main . '/code/wprism-code.lock.json', mc_lock('9.8.0', str_repeat('3', 64)));
mc_git($main, ['add', '.']);
mc_git($main, ['commit', '-qm', 'lock woocommerce 9.8.0']);
mc_git($main, ['checkout', '-q', 'merged']);

// ------------------------------------------------- case A: mode 1 coherent

$caseA = mc_run($wprism, $main, ['merge-check', '--format=json']);
$documentA = mc_json($caseA['stdout']);
wprism_check_same(0, $caseA['exit'], 'case A: a coherent committed tree validates with no environment (exit 0)');
wprism_check(
    is_array($documentA) && ($documentA['format'] ?? null) === 'wprism-merge-check/v1'
        && ($documentA['verdict'] ?? null) === 'coherent'
        && ($documentA['mode'] ?? null) === 'validate',
    'case A: mode 1 publishes wprism-merge-check/v1 with verdict=coherent'
);
wprism_check(
    is_array($documentA)
        && ($documentA['coherence']['compiled'] ?? null) === true
        && preg_match('/^[a-f0-9]{64}$/D', (string) ($documentA['coherence']['site_hash'] ?? '')) === 1
        && preg_match('/^[a-f0-9]{64}$/D', (string) ($documentA['coherence']['manifest_hash'] ?? '')) === 1
        && preg_match('/^[a-f0-9]{64}$/D', (string) ($documentA['coherence']['artifact_hash'] ?? '')) === 1,
    'case A: the compiler\'s own site/manifest/artifact hashes are echoed, so "compiled" is evidence not a claim'
);
wprism_check_same(
    ['base' => null, 'left' => $merged, 'right' => null],
    is_array($documentA) ? ($documentA['refs'] ?? null) : null,
    'case A: mode 1 names exactly the one ref it validated'
);
wprism_check(
    is_array($documentA)
        && array_key_exists('plan', $documentA) && $documentA['plan'] === null
        && array_key_exists('counts', $documentA) && $documentA['counts'] === null,
    'case A: validation mode publishes no plan, because it planned nothing'
);

// --------------------------------------- case B: mode 1 typed refusals

$incoherent = [
    ['broken-duplicate-uuid', '/duplicate_uuid/', 'two files claiming one uuid'],
    ['broken-options', '/wprism-options|format/i', 'an options document with an unknown format'],
];
foreach ($incoherent as [$ref, $pattern, $what]) {
    $result = mc_run($wprism, $main, ['merge-check', '--ref=' . $ref, '--format=json']);
    $envelope = mc_json($result['stdout']);
    wprism_check_same(1, $result['exit'], "case B: $what refuses with exit 1 and no environment");
    wprism_check(
        is_array($envelope)
            && ($envelope['format'] ?? null) === 'wprism-command-refusal/v1'
            && ($envelope['command'] ?? null) === 'merge-check'
            && ($envelope['reason_code'] ?? null) === 'repository_incoherent'
            && ($envelope['ok'] ?? null) === false,
        "case B: $what arrives as a typed repository_incoherent envelope"
    );
    $detail = is_array($envelope) ? (string) ($envelope['diagnostics'][0]['detail'] ?? '') : '';
    wprism_check(
        preg_match($pattern, $detail) === 1,
        "case B: the compiler's own diagnostic survives into the envelope for $what rather than being flattened"
            . ($detail === '' ? ' (no diagnostic carried)' : '')
    );
    $human = mc_run($wprism, $main, ['merge-check', '--ref=' . $ref]);
    wprism_check(
        $human['exit'] === 1 && $human['stdout'] === '' && preg_match($pattern, $human['stderr']) === 1,
        "case B: human mode keeps the same diagnostic on stderr and stdout clean for $what"
    );
}

// A dangling typed reference needs a manifest that declares one, so it gets
// its own minimal repository rather than polluting the fixture every other
// case compiles.
mkdir($probe, 0777, true);
mc_git($probe, ['init', '-q', '-b', 'main']);
mc_git($probe, ['config', 'user.email', 'wprism-merge-check-regression@example.test']);
mc_git($probe, ['config', 'user.name', 'WPrism merge-check regression']);
mc_write($probe . '/site.wprism.json', mc_site(['probe']));
mc_write($probe . '/manifests/probe.json', json_encode([
    'name' => 'probe',
    'spec_version' => 2,
    'interpreter' => 'probe',
    'post_types' => ['post' => ['class' => 'authored']],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
mc_write($probe . '/manifests/interpreters/probe.php', <<<'PHP'
<?php
namespace WPrism\Interpreters;
final class Probe {
    public function __construct($policy) {}
    public function post_meta_rule(string $key, array $allMeta): ?array {
        return $key === '_probe' ? ['class' => 'authored', 'ref' => 'post'] : null;
    }
}
PHP);
mc_write($probe . '/state/options/core.json', mc_options());
mc_write($probe . '/' . $pathX, mc_post($X, 'x', 'X title', 'open', ['_probe' => '{{post:' . $Y . '}}']));
mc_write($probe . '/' . $pathY, mc_post($Y, 'y', 'Y title'));
\WPrismTest\FrozenPolicy::adapterLibrary($probe . '/manifests');
mc_write(
    $probe . '/adapter-packages/probe/package/manifest.json',
    (string) file_get_contents($probe . '/manifests/probe.json')
);
mc_write(
    $probe . '/adapter-packages/probe/package/disposition.json',
    (string) file_get_contents($probe . '/manifests/dispositions/probe.json')
);
mc_write(
    $probe . '/adapter-packages/probe/package/runtime/interpreters/probe.php',
    (string) file_get_contents($probe . '/manifests/interpreters/probe.php')
);
foreach ([
    'core/manifest.json',
    'core/disposition.json',
    'profiles.json',
    'capabilities/platform.json',
    'capabilities/adapter-authorities.json',
] as $relative) {
    mc_write(
        $probe . '/platform/adapter-library/' . $relative,
        (string) file_get_contents($repoRoot . '/platform/adapter-library/' . $relative)
    );
}
mc_remove($probe . '/manifests');
mc_git($probe, ['add', '.']);
mc_git($probe, ['commit', '-qm', 'probe base with a resolvable typed reference']);
$probeResolvable = mc_run($wprism, $probe, ['merge-check', '--format=json']);
wprism_check_same(
    0,
    $probeResolvable['exit'],
    'case B: the typed-reference fixture is coherent before the reference is broken (the control)'
);
mc_write($probe . '/' . $pathX, mc_post($X, 'x', 'X title', 'open', ['_probe' => '{{post:' . $missing . '}}']));
mc_git($probe, ['commit', '-qam', 'probe: point the typed reference at nothing']);
$probeDangling = mc_run($wprism, $probe, ['merge-check', '--format=json']);
$danglingEnvelope = mc_json($probeDangling['stdout']);
wprism_check_same(1, $probeDangling['exit'], 'case B: a dangling typed reference refuses with exit 1 and no environment');
wprism_check(
    is_array($danglingEnvelope)
        && ($danglingEnvelope['reason_code'] ?? null) === 'repository_incoherent'
        && preg_match(
            '/' . preg_quote($missing, '/') . '/',
            (string) ($danglingEnvelope['diagnostics'][0]['detail'] ?? '')
        ) === 1,
    'case B: the dangling reference is named by uuid in the typed envelope'
);

// ------------------------------------- case C: DESIGN.md:182 Spike B verdict

$caseC = mc_run($wprism, $main, ['merge-check', '--ref=branch-b', '--against=branch-a', '--format=json']);
$documentC = mc_json($caseC['stdout']);
wprism_check_same(3, $caseC['exit'], 'case C: a genuine editorial conflict between two refs exits 3');
wprism_check(
    is_array($documentC) && ($documentC['verdict'] ?? null) === 'conflicts'
        && ($documentC['refs'] ?? null) === ['base' => $baseCommit, 'left' => $commitB, 'right' => $commitA],
    'case C: B is the computed merge base and W/P are the two named refs'
);
$conflicts = is_array($documentC) ? (array) ($documentC['conflicts'] ?? []) : [];
wprism_check_same(1, count($conflicts), 'case C: exactly one entry conflicts');
wprism_check_same(
    ['id' => 'post:' . $X, 'identity' => $X, 'reason' => 'production_and_branch_changed_differently', 'type' => 'post'],
    $conflicts[0] ?? null,
    'case C: the conflict names X by WordPress identity, entity type and reason'
);
wprism_check_same(
    ['branch-only' => 1, 'compatible' => 0, 'conflicting' => 1, 'production-only' => 0, 'unchanged' => 0],
    is_array($documentC) ? ($documentC['counts'] ?? null) : null,
    'case C: Y is branch-only, exactly as Spike B requires — one decision, not two'
);

// The merged tree is the same question asked the other way round: after
// `git merge branch-a`, W already contains P, so there is nothing left to
// decide and the verdict must be clean.
$caseCMerged = mc_run($wprism, $main, ['merge-check', '--ref=merged', '--against=branch-a', '--format=json']);
$documentCMerged = mc_json($caseCMerged['stdout']);
wprism_check(
    $caseCMerged['exit'] === 0 && is_array($documentCMerged) && ($documentCMerged['verdict'] ?? null) === 'clean',
    'case C: the completed merge is reported clean against the ref it merged'
);

// ------------------------------------------------ case D: exit-code contract

$exitTable = [
    [['merge-check', '--ref=merged', '--against=branch-a'], 0, 'clean'],
    [['merge-check', '--ref=broken-duplicate-uuid'], 1, 'refusal'],
    [['merge-check', '--not-a-flag=1'], 2, 'usage'],
    [['merge-check', '--ref=branch-b', '--against=branch-a'], 3, 'conflicts'],
];
foreach ($exitTable as [$args, $expected, $label]) {
    $result = mc_run($wprism, $main, $args);
    wprism_check_same($expected, $result['exit'], "case D: $label exits $expected");
    $json = mc_run($wprism, $main, array_merge($args, ['--format=json']));
    wprism_check_same($expected, $json['exit'], "case D: $label exits $expected under --format=json too");
    wprism_check(
        mc_json($json['stdout']) !== null,
        "case D: $label publishes one parseable document on stdout"
    );
}
$conflictJson = mc_run($wprism, $main, ['merge-check', '--ref=branch-b', '--against=branch-a', '--format=json']);
$conflictDocument = mc_json($conflictJson['stdout']);
wprism_check(
    is_array($conflictDocument)
        && ($conflictDocument['format'] ?? null) === 'wprism-merge-check/v1'
        && ($conflictDocument['format'] ?? null) !== 'wprism-command-refusal/v1'
        && ($conflictDocument['exit_code'] ?? null) === 3,
    'case D: exit 3 is an ANSWER — the success document with verdict=conflicts, never a refusal envelope'
);
$usageJson = mc_run($wprism, $main, ['merge-check', '--not-a-flag=1', '--format=json']);
wprism_check(
    (mc_json($usageJson['stdout'])['format'] ?? null) === 'wprism-command-refusal/v1'
        && (mc_json($usageJson['stdout'])['reason_code'] ?? null) === 'invalid_arguments',
    'case D: a usage error still gives a machine caller one parseable envelope'
);

// ---------------------------------------------- case E: non-authorizing plan

$planContext = is_array($documentC) ? ($documentC['plan']['context'] ?? null) : null;
wprism_check(
    is_array($planContext)
        && ($planContext['advisory'] ?? null) === true
        && ($planContext['production_source'] ?? null) === 'git-ref'
        && !array_key_exists('production_env', $planContext)
        && !array_key_exists('production_snapshot_hash', $planContext),
    'case E: the plan context marks itself advisory and carries no live production identity'
);
wprism_check_throws(
    static fn() => Refresh::assertAuthorizingPlan(is_array($documentC) ? (array) $documentC['plan'] : []),
    RuntimeException::class,
    'case E: Refresh::assertAuthorizingPlan refuses that exact plan'
);
try {
    Refresh::assertAuthorizingPlan(is_array($documentC) ? (array) $documentC['plan'] : []);
    wprism_check(false, 'case E: the refusal names the authorized alternative');
} catch (Throwable $refusal) {
    wprism_check_same(
        'a merge-check plan is advisory and carries no production authority; '
            . 'run wprism rebase <production-env> --production-ref=<ref> to materialize',
        $refusal->getMessage(),
        'case E: the refusal names the authorized alternative'
    );
}
Refresh::assertAuthorizingPlan(['context' => [
    'base_commit' => $baseCommit,
    'production_env' => 'production',
    'production_snapshot_hash' => str_repeat('a', 64),
]]);
wprism_check(true, 'case E: an ordinary refresh plan context passes the same guard untouched');

$journal = trim(mc_git($main, ['rev-parse', '--path-format=absolute', '--git-common-dir'])) . '/wprism-refresh';
wprism_check(
    !is_dir($journal),
    'case E: no merge-check invocation in this whole suite created a refresh run journal'
);
wprism_check_same(
    1,
    count(array_filter(
        explode("\n", trim(mc_git($main, ['worktree', 'list']))),
        static fn(string $row): bool => trim($row) !== ''
    )),
    'case E: no merge-check invocation left a candidate worktree behind'
);
wprism_check_same(
    ['branch-a', 'branch-b', 'broken-duplicate-uuid', 'broken-options', 'main', 'merged', 'skew-newer', 'skew-older'],
    array_values(array_filter(array_map(
        static fn(string $row): string => trim($row),
        explode("\n", mc_git($main, ['for-each-ref', '--format=%(refname:short)', 'refs/heads/']))
    ), static fn(string $row): bool => $row !== '')),
    'case E: no merge-check invocation created a branch ref'
);

// ------------------------------------------- case F: env-free structurally

$mergeCheckSource = (string) file_get_contents($repoRoot . '/cli/src/Refresh/MergeCheck.php');
foreach (['EnvironmentDriver', 'captureRaw', 'captureWp', 'streamWp', 'Registry::', 'Transport'] as $forbidden) {
    wprism_check(
        !str_contains($mergeCheckSource, $forbidden),
        "case F: MergeCheck.php references no '$forbidden', so a later refactor cannot quietly reintroduce a live dependency"
    );
}
wprism_check(
    str_contains((string) file_get_contents($repoRoot . '/cli/wprism'), "if (\$verb === 'merge-check') {")
        && !str_contains(
            (string) file_get_contents($repoRoot . '/cli/src/Command/EnvironmentCommandPreflight.php'),
            "'merge-check'"
        ),
    'case F: merge-check is dispatched in the env-free block and is not an environment verb'
);

// ------------------------------------------------------- case G: code skew

$caseG = mc_run($wprism, $main, ['merge-check', '--ref=skew-older', '--against=skew-newer', '--format=json']);
$documentG = mc_json($caseG['stdout']);
wprism_check_same(3, $caseG['exit'], 'case G: cross-branch code skew blocks the merge gate with exit 3');
wprism_check_same('code_skew', $documentG['verdict'] ?? null, 'case G: the success document distinguishes code skew from an editorial conflict');
wprism_check_same(
    [[
        'component' => 'plugins/woocommerce',
        'left_version' => '9.8.0',
        'right_version' => '9.9.1',
        'status' => 'version_skew',
    ]],
    is_array($documentG) ? ($documentG['code_skew'] ?? null) : null,
    'case G: the skew names the component and both locked versions'
);
$caseGHuman = mc_run($wprism, $main, ['merge-check', '--ref=skew-older', '--against=skew-newer']);
wprism_check(
    str_contains($caseGHuman['stdout'], 'blocked: code skew plugins/woocommerce left=9.8.0 right=9.9.1')
        && str_contains(
            $caseGHuman['stdout'],
            'blocked: remedy: merge code first, run migrations, re-capture, then merge state'
        ),
    'case G: the human view carries DESIGN.md:125\'s own remedy verbatim'
);
$caseGNone = mc_run($wprism, $main, ['merge-check', '--ref=branch-b', '--against=branch-a', '--format=json']);
wprism_check_same(
    [],
    mc_json($caseGNone['stdout'])['code_skew'] ?? null,
    'case G: two refs with no lock at all report no skew rather than a guess'
);

// ------------------------------- case H: --field-diff is an explicit non-claim

$caseH = mc_run($wprism, $main, ['merge-check', '--ref=branch-b', '--against=branch-a', '--field-diff']);
wprism_check_same(
    2,
    $caseH['exit'],
    'case H: --field-diff is absent from the surface, not accepted-and-refused '
        . '(RefreshFieldDiff::project() requires a live production_snapshot_hash)'
);
wprism_check(
    !str_contains((string) file_get_contents($repoRoot . '/cli/src/Command/MergeCheckCommand.php'), "'--field-diff',"),
    'case H: the flag list itself records the non-claim, so nothing half-works'
);

// ---------------------------- case I: --base overrides the computed merge base

$caseI = mc_run($wprism, $main, [
    'merge-check', '--ref=branch-b', '--against=branch-a', '--base=' . $baseCommit, '--format=json',
]);
wprism_check(
    $caseI['exit'] === 3
        && (mc_json($caseI['stdout'])['plan']['plan_hash'] ?? null)
            === (is_array($documentC) ? ($documentC['plan']['plan_hash'] ?? null) : null),
    'case I: an explicit --base equal to the computed merge base produces the identical plan_hash'
);
$caseIRefusal = mc_run($wprism, $main, ['merge-check', '--ref=branch-b', '--against=branch-a', '--base=nope']);
wprism_check(
    $caseIRefusal['exit'] === 1 && str_contains($caseIRefusal['stderr'], '--base does not resolve to a commit'),
    'case I: an unresolvable --base refuses by name rather than silently falling back'
);

// --------------------------- case J: a dirty checkout is refused, not guessed

mc_write($main . '/' . $pathY, mc_post($Y, 'y', 'Y edited but never committed'));
$caseJ = mc_run($wprism, $main, ['merge-check', '--format=json']);
$documentJ = mc_json($caseJ['stdout']);
wprism_check(
    $caseJ['exit'] === 1 && ($documentJ['reason_code'] ?? null) === 'working_tree_dirty',
    'case J: merge-check refuses to answer for a dirty checkout instead of validating the last commit'
);
// The guard is scoped to "the tree the operator is looking at". Naming a
// DIFFERENT committed ref still answers while this checkout is dirty, because
// the compile happens in a detached worktree at that commit and the dirty
// bytes cannot reach it.
$caseJNamed = mc_run($wprism, $main, ['merge-check', '--ref=branch-a', '--format=json']);
wprism_check_same(
    0,
    $caseJNamed['exit'],
    'case J: naming another committed ref still answers, because a detached worktree compiles that commit'
);
$caseJExplicitHead = mc_run($wprism, $main, ['merge-check', '--ref=merged', '--format=json']);
wprism_check(
    $caseJExplicitHead['exit'] === 1
        && (mc_json($caseJExplicitHead['stdout'])['reason_code'] ?? null) === 'working_tree_dirty',
    'case J: naming the checked-out ref by name is the same question and gets the same refusal'
);
mc_git($main, ['checkout', '-q', '--', $pathY]);

wprism_check_summary('merge-check');
