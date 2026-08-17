#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Report which scoped certifications a set of changed paths expires.
 *
 * Why this exists: nine subjects (eight certified manifests + the `fse`
 * profile) are sealed against a source closure that includes the *entire*
 * walked trees agent/, cli/, sandbox/bin/, plus Makefile and a handful of
 * named sandbox/, docs/, manifests/, and scripts/ files. Touching one byte in
 * any of those expires every subject that binds it, which costs a full
 * certification round. Before this reporter the only way to learn that was to
 * run the release gate after the fact.
 *
 * Constraints honoured here:
 *  - The closure is NEVER reimplemented. Membership comes from the single
 *    authority, \Duo\ScopedCertificationBundle::subjectInputPaths(), invoked
 *    exactly as scripts/capability-registry.php's cap_scoped_current_inputs()
 *    does. This file only intersects that authority's answer with a changed
 *    path set, so it cannot drift from the gate.
 *  - Bootstrap mirrors scripts/capability-registry.php lines ~17-40: the
 *    DUO_* constants are regex-parsed out of agent/duo.php (loading duo.php
 *    itself would demand a WordPress runtime), is_multisite() is stubbed, and
 *    the four closure-relevant agent/src files are required.
 *  - Plain PHP, no composer runtime deps, cwd-independent, PHP 8.3+ syntax.
 *  - Read-only with respect to the repo except tools/cert-impact-history.jsonl
 *    under --record. It writes nothing under agent/, cli/, or sandbox/bin/,
 *    because the closure walks those trees on the filesystem: a scratch file
 *    there would itself expire all nine certifications.
 *
 * Evidence for the filesystem-walk hazard: subjectInputPaths()'s $addTree over
 * ['agent','cli','sandbox/bin'] enumerates real directory entries with no
 * gitignore awareness, so an *ignored* or *untracked* file inside those trees
 * is a closure input. A deployed agent then fails
 * ScopedCertificationBundle::assertRuntimeInputsCurrent() during Policy::load()
 * and refuses to operate. Hence --paths/--staged/--range modes are advisory
 * for review, while the default working-tree mode additionally lists ignored
 * entries inside the walked trees as hazards.
 *
 * Usage:
 *   php tools/cert-impact.php [--staged | --range=A..B | --paths=a,b,c]
 *                             [--json] [--fail-on-impact]
 *                             [--record [--note="…"]] | --history
 */

$repo = dirname(__DIR__);

if (!defined('DUO_AGENT_VERSION')) {
    $agentSource = (string) file_get_contents($repo . '/agent/duo.php');
    if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $agentSource, $m) !== 1) {
        fwrite(STDERR, "cert-impact: could not resolve DUO_AGENT_VERSION\n");
        exit(2);
    }
    define('DUO_AGENT_VERSION', $m[1]);
}
if (!defined('DUO_SPEC_VERSION')) {
    $agentSource ??= (string) file_get_contents($repo . '/agent/duo.php');
    if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $agentSource, $m) !== 1) {
        fwrite(STDERR, "cert-impact: could not resolve DUO_SPEC_VERSION\n");
        exit(2);
    }
    define('DUO_SPEC_VERSION', (int) $m[1]);
}
if (!function_exists('is_multisite')) {
    function is_multisite(): bool { return false; }
}

require_once $repo . '/agent/src/Canon.php';
require_once $repo . '/agent/src/ManifestDispositions.php';
require_once $repo . '/agent/src/CapabilityRegistry.php';
require_once $repo . '/agent/src/ScopedCertificationBundle.php';

use Duo\Canon;
use Duo\ManifestDispositions;
use Duo\ScopedCertificationBundle;

const CI_HISTORY_FILE = '/tools/cert-impact-history.jsonl';
const CI_WALKED_TREES = ['agent/', 'cli/', 'sandbox/bin/'];

function ci_usage(string $message): never {
    fwrite(STDERR, "cert-impact: $message\n");
    fwrite(STDERR, 'usage: php tools/cert-impact.php [--staged|--range=A..B|--paths=a,b,c]'
        . " [--json] [--fail-on-impact] [--record [--note=…]] | --history\n");
    exit(2);
}

/** @return array{0:int,1:string} */
function ci_git(string $repo, array $args): array {
    // core.quotePath=false so a non-ASCII path (e.g. agent/src/café.php) comes
    // back as the raw UTF-8 byte string rather than git's C-quoted escape
    // form ("agent/src/caf\303\251.php"). ci_parse_porcelain()'s ci_unquote()
    // already decodes the quoted form for --porcelain callers, but the plain
    // `git diff --name-only` callers (--staged/--range) do not go through it,
    // so without this flag those two modes would silently fail to match a
    // non-ASCII closure path against subjectInputPaths()'s raw byte strings.
    $cmd = 'git -C ' . escapeshellarg($repo) . ' -c core.quotePath=false';
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string) $arg);
    }
    $out = [];
    $status = 0;
    exec($cmd . ' 2>/dev/null', $out, $status);
    return [$status, implode("\n", $out)];
}

/** @return list<string> */
function ci_lines(string $blob): array {
    $lines = [];
    foreach (explode("\n", $blob) as $line) {
        $line = rtrim($line, "\r");
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    return $lines;
}

/**
 * Expand a `git status --porcelain` directory entry (trailing '/') into the
 * real files underneath it, because the closure binds files, not directories.
 *
 * @return list<string>
 */
function ci_expand(string $repo, string $relative): array {
    if (!str_ends_with($relative, '/')) {
        return [$relative];
    }
    $absolute = $repo . '/' . rtrim($relative, '/');
    if (!is_dir($absolute) || is_link($absolute)) {
        return [];
    }
    $found = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if ($file->isFile() || $file->isLink()) {
            $found[] = substr($file->getPathname(), strlen($repo) + 1);
        }
    }
    sort($found, SORT_STRING);
    return $found;
}

/** Strip quoting/rename syntax from one porcelain path field. */
function ci_unquote(string $path): string {
    $path = trim($path);
    if (strlen($path) >= 2 && $path[0] === '"' && str_ends_with($path, '"')) {
        $decoded = stripcslashes(substr($path, 1, -1));
        $path = $decoded;
    }
    return $path;
}

/**
 * @param list<string> $status porcelain v1 lines
 * @return array{paths:list<string>,statuses:array<string,string>}
 */
function ci_parse_porcelain(string $repo, array $status): array {
    $paths = [];
    $codes = [];
    foreach ($status as $line) {
        if (strlen($line) < 4) {
            continue;
        }
        $code = substr($line, 0, 2);
        $rest = substr($line, 3);
        $fields = [$rest];
        if (str_contains($rest, ' -> ')) {
            // Rename/copy: both the vanished source and the new destination
            // change the filesystem the closure walks.
            $fields = explode(' -> ', $rest, 2);
        }
        foreach ($fields as $field) {
            foreach (ci_expand($repo, ci_unquote($field)) as $path) {
                if ($path === '') {
                    continue;
                }
                $paths[$path] = true;
                $codes[$path] = trim($code) === '' ? $code : trim($code);
            }
        }
    }
    $out = array_keys($paths);
    sort($out, SORT_STRING);
    return ['paths' => $out, 'statuses' => $codes];
}

/**
 * @return array{mode:string,range:?string,changed:list<string>,hazards:list<array{path:string,state:string}>}
 */
function ci_collect(string $repo, string $mode, ?string $range, ?string $explicit): array {
    $hazards = [];
    if ($mode === 'paths') {
        $changed = [];
        foreach (explode(',', (string) $explicit) as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            // Strip only a leading "./" prefix (repeated, e.g. "././x"), not
            // every leading dot/slash character: ltrim() takes a character
            // list, so ltrim($x, './') would also eat a top-level dotfile
            // like ".gitignore" down to "gitignore".
            $normalized = preg_replace('#^(?:\./)+#', '', str_replace('\\', '/', $raw));
            $raw = $normalized ?? $raw;
            $changed[$raw] = true;
        }
        $changed = array_keys($changed);
        sort($changed, SORT_STRING);
        return ['mode' => 'paths', 'range' => null, 'changed' => $changed, 'hazards' => []];
    }
    if ($mode === 'staged') {
        [$status, $out] = ci_git($repo, ['diff', '--cached', '--name-only']);
        if ($status !== 0) {
            ci_usage('git diff --cached failed');
        }
        $changed = ci_lines($out);
        sort($changed, SORT_STRING);
        return ['mode' => 'staged', 'range' => null, 'changed' => $changed, 'hazards' => []];
    }
    if ($mode === 'range') {
        [$status, $out] = ci_git($repo, ['diff', '--name-only', (string) $range]);
        if ($status !== 0) {
            ci_usage("git diff --name-only $range failed (unknown revisions?)");
        }
        $changed = ci_lines($out);
        sort($changed, SORT_STRING);
        return ['mode' => 'range', 'range' => $range, 'changed' => $changed, 'hazards' => []];
    }

    // Working tree: tracked modifications + untracked files, then the ignored
    // entries that live inside the three walked trees. The latter are invisible
    // to git-based review yet are full closure inputs.
    [$status, $out] = ci_git($repo, ['status', '--porcelain', '--untracked-files=all']);
    if ($status !== 0) {
        ci_usage('git status failed');
    }
    $parsed = ci_parse_porcelain($repo, ci_lines($out));
    $changed = $parsed['paths'];
    foreach ($changed as $path) {
        if (($parsed['statuses'][$path] ?? '') === '??' && ci_in_walked_tree($path)) {
            $hazards[$path] = ['path' => $path, 'state' => 'untracked'];
        }
    }

    [, $ignored] = ci_git($repo, ['status', '--porcelain', '--ignored', '--', 'agent', 'cli', 'sandbox/bin']);
    $ignoredParsed = ci_parse_porcelain($repo, ci_lines($ignored));
    $seen = array_flip($changed);
    foreach ($ignoredParsed['paths'] as $path) {
        if (!ci_in_walked_tree($path)) {
            continue;
        }
        if (($ignoredParsed['statuses'][$path] ?? '') === '!!') {
            $hazards[$path] = ['path' => $path, 'state' => 'ignored'];
        } elseif (($ignoredParsed['statuses'][$path] ?? '') === '??') {
            $hazards[$path] = ['path' => $path, 'state' => 'untracked'];
        }
        if (!isset($seen[$path])) {
            $changed[] = $path;
            $seen[$path] = true;
        }
    }
    sort($changed, SORT_STRING);
    ksort($hazards, SORT_STRING);
    return [
        'mode' => 'working-tree',
        'range' => null,
        'changed' => $changed,
        'hazards' => array_values($hazards),
    ];
}

function ci_in_walked_tree(string $path): bool {
    foreach (CI_WALKED_TREES as $tree) {
        if (str_starts_with($path, $tree)) {
            return true;
        }
    }
    return false;
}

/**
 * Fixed named (non-walked-tree) closure inputs, mirrored from the exact/glob
 * paths \Duo\ScopedCertificationBundle::subjectInputPaths() names outside its
 * three $addTree() calls: the shared $add() list, the sandbox/lib/pair_*.sh
 * and sandbox/pair*.yml globs, every manifests/*.json, the per-subject
 * sandbox/conformance/{entries,seeds,postdeploy,checks} files, the
 * per-test sandbox/certification/{tests,version-matrix} trees, and the
 * manifests/{interpreters,providers,regenerators} sidecars. This list is
 * used ONLY to decide whether a path that no longer exists on disk WAS a
 * closure member (see ci_deleted_closure_hazards()) -- it is never used to
 * compute the closure itself, which always comes from subjectInputPaths().
 * Keep this in step with that function's named inputs.
 *
 * @return list<string>
 */
function ci_named_closure_input_patterns(): array {
    return [
        'Makefile',
        'docs/compatibility-baseline.json',
        'sandbox/conformance/asserts.sh',
        'sandbox/conformance/run.sh',
        'sandbox/db.yml',
        'sandbox/init-cli.Dockerfile',
        'sandbox/tests/certify_subject_bundle.sh',
        'scripts/capability-registry.php',
        'sandbox/lib/pair_*.sh',
        'sandbox/pair*.yml',
        'manifests/*.json',
        'manifests/interpreters/*.php',
        'manifests/providers/*.php',
        'manifests/regenerators/*.php',
        'sandbox/conformance/entries/*.json',
        'sandbox/conformance/seeds/*.sh',
        'sandbox/conformance/postdeploy/*.sh',
        'sandbox/conformance/checks/*.sh',
        'sandbox/certification/tests/*',
        'sandbox/certification/version-matrix/*',
    ];
}

function ci_is_named_closure_input(string $path): bool {
    foreach (ci_named_closure_input_patterns() as $pattern) {
        if (fnmatch($pattern, $path)) {
            return true;
        }
    }
    return false;
}

/**
 * A changed path that no longer exists on disk can never appear in
 * subjectInputPaths()'s answer: both its $add() and $addTree() closures
 * skip anything file_exists() cannot see (agent/src/ScopedCertificationBundle
 * .php ~166 and ~186), so a pure deletion (or a rename's vanished source)
 * intersects nothing and is misreported as free-zone -- while
 * ScopedCertificationBundle::assertCurrent() byte-compares the recorded
 * closure against the now-shorter current one and correctly expires every
 * subject that bound the file. This is the conservative counter-check: a
 * deleted path inside a walked tree, or matching a fixed named input, is
 * unconditionally treated as a closure hit for every subject, because we
 * cannot recover from the filesystem which of the nine subjects' closures
 * it used to belong to (deletion destroyed that information).
 *
 * @param list<string> $changed
 * @return list<string>
 */
function ci_deleted_closure_hazards(string $repo, array $changed): array {
    $hazards = [];
    foreach ($changed as $path) {
        if ($path === '' || file_exists($repo . '/' . $path) || is_link($repo . '/' . $path)) {
            continue;
        }
        if (ci_in_walked_tree($path) || ci_is_named_closure_input($path)) {
            $hazards[] = $path;
        }
    }
    sort($hazards, SORT_STRING);
    return $hazards;
}

function ci_category(string $path): string {
    if (str_starts_with($path, 'agent/')) {
        return 'agent';
    }
    if (str_starts_with($path, 'cli/')) {
        return 'cli';
    }
    if (str_starts_with($path, 'sandbox/bin/')) {
        return 'sandbox_bin';
    }
    if ($path === 'Makefile') {
        return 'makefile';
    }
    return 'other';
}

function ci_group_label(string $category): string {
    return match ($category) {
        'agent' => 'agent',
        'cli' => 'cli',
        'sandbox_bin' => 'sandbox/bin',
        'makefile' => 'Makefile',
        default => 'other-closure',
    };
}

/**
 * Enumerate every certified subject and its authoritative closure.
 *
 * Replicates the minimum of cap_subject_context()/cap_manifests() needed to
 * hand subjectInputPaths() the same ($manifest, $claim['evidence']['tests'])
 * pair the registry gate hands it.
 *
 * @return array<string,array{kind:string,name:string,paths:list<string>}>
 */
function ci_subject_closures(string $repo): array {
    $manifestDir = $repo . '/manifests';
    $dispositions = ManifestDispositions::load($manifestDir);
    if ($dispositions === null) {
        fwrite(STDERR, "cert-impact: manifest dispositions are absent\n");
        exit(2);
    }
    $manifests = [];
    foreach (glob($manifestDir . '/*.json') ?: [] as $file) {
        if (basename($file) === 'dispositions.json') {
            continue;
        }
        $manifest = Canon::decode(Canon::read_file($file));
        $manifests[(string) ($manifest['name'] ?? basename($file, '.json'))] = $manifest;
    }

    $subjects = [];
    foreach (($dispositions->data()['manifests'] ?? []) as $name => $claim) {
        if (!is_array($claim) || ($claim['status'] ?? null) !== 'certified') {
            continue;
        }
        $subjects[] = ['kind' => 'manifest', 'name' => (string) $name, 'claim' => $claim];
    }
    foreach ($dispositions->profiles() as $name => $claim) {
        // Mirror scripts/capability-registry.php's cap_generate() exactly:
        // it only requires closure currency for a profile whose status is
        // 'certified' ($requireCurrent && ($profile['status'] ?? null) ===
        // 'certified', currently ~line 495 there). Enumerating every profile
        // here regardless of status would report a subject the gate never
        // polices (and would hard-exit(2) below for a non-certified profile
        // with no resolvable manifest, where the gate is simply indifferent).
        // Keep this filter in lockstep with that condition if it ever moves.
        if (!is_array($claim) || ($claim['status'] ?? null) !== 'certified') {
            continue;
        }
        $subjects[] = ['kind' => 'profile', 'name' => (string) $name, 'claim' => $claim];
    }

    $out = [];
    foreach ($subjects as $subject) {
        $kind = $subject['kind'];
        $name = $subject['name'];
        $claim = $subject['claim'];
        $manifestName = $kind === 'manifest' ? $name : ($claim['manifest'] ?? null);
        $manifest = is_string($manifestName) ? ($manifests[$manifestName] ?? null) : null;
        if (!is_array($manifest)) {
            fwrite(STDERR, "cert-impact: subject '$kind:$name' has no shipped manifest\n");
            exit(2);
        }
        $key = ScopedCertificationBundle::subjectKey($kind, $name);
        $out[$key] = [
            'kind' => $kind,
            'name' => $name,
            'paths' => ScopedCertificationBundle::subjectInputPaths(
                $repo,
                $kind,
                $name,
                $manifest,
                $claim['evidence']['tests'] ?? []
            ),
        ];
    }
    ksort($out, SORT_STRING);
    return $out;
}

/**
 * Classify what drove a closure impact, for the D6 decision record.
 *
 * 'suite-declaration-only' means the sole closure path is Makefile and every
 * changed Makefile line is a regression-suite prerequisite/target line — i.e.
 * a certification round was spent on test wiring, not on shipped code. The
 * line match is deliberately approximate and stated as such.
 *
 * @param list<string> $closurePaths
 */
function ci_driver(string $repo, array $closurePaths, string $mode, ?string $range): string {
    if ($closurePaths === [] || $closurePaths !== ['Makefile']) {
        return $closurePaths === [] ? 'none' : 'code';
    }
    $args = ['diff'];
    if ($mode === 'staged') {
        $args[] = '--cached';
    } elseif ($mode === 'range' && $range !== null) {
        $args[] = $range;
    } elseif ($mode === 'working-tree') {
        // Working-tree mode is what --record is actually run in day to day,
        // and ci_collect()'s working-tree branch sees BOTH staged and
        // unstaged changes (git status --porcelain). A plain `git diff` here
        // is unstaged-only, so a fully staged Makefile hunk would vanish from
        // this diff entirely (misclassified 'code' as 'suite-declaration-only'
        // when mixed with an unstaged suite-only tweak, or under-attributed
        // when staged alone). Diffing against HEAD covers both index and
        // worktree, matching what actually drove the closure hit.
        $args[] = 'HEAD';
    }
    $args[] = '--';
    $args[] = 'Makefile';
    [, $diff] = ci_git($repo, $args);
    $body = ci_lines($diff);
    $sawChange = false;
    foreach ($body as $line) {
        if ($line === '' || str_starts_with($line, '+++') || str_starts_with($line, '---')
            || str_starts_with($line, '@@') || str_starts_with($line, 'diff ')
            || str_starts_with($line, 'index ')) {
            continue;
        }
        if ($line[0] !== '+' && $line[0] !== '-') {
            continue;
        }
        $sawChange = true;
        $payload = substr($line, 1);
        if (preg_match('/(regress-|code-half-unit|offline suites green)/', $payload) !== 1) {
            return 'code';
        }
    }
    return $sawChange ? 'suite-declaration-only' : 'code';
}

function ci_history_summary(string $repo): int {
    $file = $repo . CI_HISTORY_FILE;
    if (!is_file($file)) {
        fwrite(STDOUT, "cert-impact history: no records yet ($file)\n");
        return 0;
    }
    $byDriver = [];
    $total = 0;
    $malformed = 0;
    foreach (ci_lines((string) file_get_contents($file)) as $line) {
        $row = json_decode($line, true);
        if (!is_array($row)) {
            $malformed++;
            continue;
        }
        $total++;
        $driver = (string) ($row['driver'] ?? 'unknown');
        $byDriver[$driver] = ($byDriver[$driver] ?? 0) + 1;
    }
    ksort($byDriver, SORT_STRING);
    fwrite(STDOUT, "cert-impact history: $total recorded trains\n");
    foreach ($byDriver as $driver => $count) {
        fwrite(STDOUT, sprintf("  %-24s %d\n", $driver, $count));
    }
    if ($malformed > 0) {
        fwrite(STDOUT, "  (skipped $malformed malformed line(s))\n");
    }
    return 0;
}

// ---------------------------------------------------------------- arguments

$mode = 'working-tree';
$range = null;
$explicitPaths = null;
$json = false;
$failOnImpact = false;
$record = false;
$history = false;
$note = '';

/** @var list<string> $cliArgv */
$cliArgv = $_SERVER['argv'] ?? [];
foreach (array_slice($cliArgv, 1) as $arg) {
    if ($arg === '--staged') {
        $mode = 'staged';
    } elseif (str_starts_with($arg, '--range=')) {
        $mode = 'range';
        $range = substr($arg, strlen('--range='));
        if ($range === '' || !str_contains($range, '..')) {
            ci_usage('--range needs A..B or A...B');
        }
    } elseif (str_starts_with($arg, '--paths=')) {
        $mode = 'paths';
        $explicitPaths = substr($arg, strlen('--paths='));
    } elseif ($arg === '--json') {
        $json = true;
    } elseif ($arg === '--fail-on-impact') {
        $failOnImpact = true;
    } elseif ($arg === '--record') {
        $record = true;
    } elseif (str_starts_with($arg, '--note=')) {
        $note = substr($arg, strlen('--note='));
    } elseif ($arg === '--history') {
        $history = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        ci_usage('help');
    } else {
        ci_usage("unknown option '$arg'");
    }
}

if ($history) {
    exit(ci_history_summary($repo));
}

$inputs = ci_collect($repo, $mode, $range, $explicitPaths);
$changed = $inputs['changed'];
$changedIndex = array_flip($changed);

$closures = ci_subject_closures($repo);
// Deleted paths never intersect any subject's (filesystem-derived) closure
// list, yet ScopedCertificationBundle::assertCurrent() would correctly
// expire every subject bound to a now-shorter closure. Treat these
// conservatively as a hit against every subject; see
// ci_deleted_closure_hazards() for why "every subject" rather than a
// specific one.
$deletedHazards = ci_deleted_closure_hazards($repo, $changed);
$subjectReport = [];
$closurePathSet = [];
foreach ($closures as $key => $subject) {
    $hits = [];
    foreach ($subject['paths'] as $path) {
        if (isset($changedIndex[$path])) {
            $hits[] = $path;
            $closurePathSet[$path] = true;
        }
    }
    foreach ($deletedHazards as $path) {
        $hits[] = $path;
        $closurePathSet[$path] = true;
    }
    sort($hits, SORT_STRING);
    $subjectReport[$key] = ['expired' => $hits !== [], 'paths' => $hits];
}
$closurePaths = array_keys($closurePathSet);
sort($closurePaths, SORT_STRING);
$freeZone = array_values(array_diff($changed, $closurePaths));
sort($freeZone, SORT_STRING);

$categories = ['agent' => 0, 'cli' => 0, 'sandbox_bin' => 0, 'makefile' => 0, 'other' => 0];
foreach ($closurePaths as $path) {
    $categories[ci_category($path)]++;
}

$expiredKeys = [];
foreach ($subjectReport as $key => $row) {
    if ($row['expired']) {
        $expiredKeys[] = $key;
    }
}
$total = count($subjectReport);
$summary = sprintf(
    'cert-impact: %d/%d subjects expire; %d closure paths; %d free-zone paths',
    count($expiredKeys),
    $total,
    count($closurePaths),
    count($freeZone)
);

// ------------------------------------------------------------------- report

if ($json) {
    fwrite(STDOUT, json_encode([
        'inputs' => ['mode' => $inputs['mode'], 'range' => $inputs['range'], 'changed' => $changed],
        'subjects' => $subjectReport,
        'free_zone' => $freeZone,
        'hazards' => $inputs['hazards'],
        'categories' => $categories,
        'summary' => $summary,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
} else {
    $label = $inputs['mode'] . ($inputs['range'] !== null ? ' ' . $inputs['range'] : '');
    fwrite(STDOUT, "cert-impact (input mode: $label; " . count($changed) . " changed path(s))\n\n");
    foreach ($subjectReport as $key => $row) {
        if (!$row['expired']) {
            fwrite(STDOUT, sprintf("  current  %s\n", $key));
            continue;
        }
        fwrite(STDOUT, sprintf("  EXPIRED  %s\n", $key));
        $grouped = [];
        foreach ($row['paths'] as $path) {
            $grouped[ci_group_label(ci_category($path))][] = $path;
        }
        ksort($grouped, SORT_STRING);
        foreach ($grouped as $group => $paths) {
            fwrite(STDOUT, sprintf("             %s (%d)\n", $group, count($paths)));
            foreach ($paths as $path) {
                fwrite(STDOUT, "               - $path\n");
            }
        }
    }
    fwrite(STDOUT, "\n  free-zone changes (" . count($freeZone) . " path(s), no subject closure)\n");
    foreach ($freeZone as $path) {
        fwrite(STDOUT, "    . $path\n");
    }
    if ($inputs['hazards'] !== []) {
        fwrite(STDOUT, "\n  HAZARD: untracked/ignored files inside the walked trees agent/, cli/, sandbox/bin/\n");
        fwrite(STDOUT, "  These are closure inputs even though git review never shows them. They expire\n");
        fwrite(STDOUT, "  ALL certifications, and a deployed agent whose Policy::load() runs\n");
        fwrite(STDOUT, "  ScopedCertificationBundle::assertRuntimeInputsCurrent() will REFUSE to operate.\n");
        foreach ($inputs['hazards'] as $hazard) {
            fwrite(STDOUT, sprintf("    ! %-9s %s\n", $hazard['state'], $hazard['path']));
        }
    }
    fwrite(STDOUT, "\n$summary\n");
}

// ------------------------------------------------------------------- record

if ($record) {
    $file = $repo . CI_HISTORY_FILE;
    $row = [
        'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'mode' => $inputs['mode'],
        'range' => $inputs['range'],
        'subjects_expired' => $expiredKeys,
        'closure_paths' => $closurePaths,
        'categories' => $categories,
        'driver' => ci_driver($repo, $closurePaths, $inputs['mode'], $inputs['range']),
        'note' => $note,
    ];
    $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
        fwrite(STDERR, "cert-impact: could not append to $file\n");
        exit(2);
    }
    // Under --json, STDOUT must stay pure JSON for machine consumers
    // (`php tools/cert-impact.php --json --record | jq .`), so the human
    // confirmation goes to STDERR instead of trailing the JSON document.
    fwrite($json ? STDERR : STDOUT, 'recorded to ' . CI_HISTORY_FILE . " (driver: {$row['driver']})\n");
}

exit($failOnImpact && $expiredKeys !== [] ? 1 : 0);
