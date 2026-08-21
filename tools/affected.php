#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Changed-files -> offline-suite selector (WP-6a).
 *
 * WHY THIS EXISTS
 * ----------------
 * `make regress-offline-all` runs every offline leaf target and costs minutes
 * even at `-j8` (tools/offline.php; the dated wall times live in
 * docs/dev-setup.md §Measured wall times, not here, where they would rot).
 * That is not fast enough to run on every edit while iterating, so this tool
 * answers a narrower question: given a set of changed files, which of those
 * targets could possibly be affected? `tools/offline.php --changed[=BASE]`
 * shells out to `php tools/affected.php --base=BASE`, treats stdout as the
 * work list (one target name per line), and intersects it with the real
 * leaf set -- so this file's only hard contract is that stdout line format.
 *
 * SOURCE OF TRUTH FOR THE LEAF LIST
 * ----------------------------------
 * Never re-derive "what counts as offline" from naming conventions alone --
 * `make -pn --no-print-directory regress-offline-corpus` is asked directly,
 * exactly the way tools/offline.php does, so this can never drift from what
 * `make regress-offline-all` actually runs. The `# Files` section of that
 * dump is a flat target->prerequisites table with continuations already
 * resolved; leaves are prerequisites with no prerequisites of their own,
 * found by walking from `regress-offline-corpus` (which pulls in
 * `code-half-unit`, its own aggregator, sharing one leaf --
 * regress-code-descriptor-compiler -- with the direct list, so the union is
 * one smaller than the sum of the two lists). How many that is, is
 * deliberately not restated here: Makefile's own `regress-offline-all: N
 * offline suites green` status line is the count of record, and
 * sandbox/tests/offline/guards/regress_bundle_coverage.sh fails the gate the
 * moment its own independent count disagrees with that line.
 *
 * TARGET -> SUITE FILE, AND WHY inv_elsewhere EXISTS
 * ----------------------------------------------------
 * A target's own suite file is found with the exact algorithm
 * sandbox/tests/offline/guards/regress_bundle_coverage.sh already uses and self-tests:
 * `regress-foo-bar` <-> `regress_foo_bar.(php|sh)`, EXCEPT a file that some
 * OTHER regress_*.{sh,php} file's code actually runs (`php x.php` /
 * `bash x.sh`) is a helper, not its own primary suite -- e.g.
 * regress_fatal_mutations_unit.sh (the offline leaf) invokes the
 * differently-named regress_fatal_mutations.php as its helper, while
 * regress_fatal_mutations.sh is an unrelated *live* target. Re-deriving this
 * without the exclusion would wrongly treat every helper as its own
 * (unwired, therefore never-selected) target.
 *
 * DEPENDENCY INDEX: WHY IT ERRS TOWARD OVER-SELECTION
 * ------------------------------------------------------
 * The full offline corpus is still mandatory at merge regardless of what
 * this tool prints (tools/offline.php runs it unfiltered by default; this
 * is only ever an opt-in `--changed` fast path during iteration), so a
 * false *positive* here costs a little wall time and a false *negative*
 * costs a real regression escaping until the full corpus runs. Three
 * independent, unioned signals build each target's referenced-file set from
 * its suite file(s):
 *   (a) require/require_once/include of a repo-relative path
 *       (`__DIR__ . '/../../agent/src/X.php'`) -- resolved via the same
 *       literal-path-token regex as (b), since every such line already
 *       embeds an `agent/src/...`-rooted substring regardless of the
 *       leading `../` count in front of it (verified against every
 *       require_once line in sandbox/tests/*.php).
 *   (b) ANY literal repo-relative path token matching
 *       `(agent|cli|recovery|manifests|scripts|sandbox|docs|spec)/....(php|
 *       json|sh|md|yml|Dockerfile)`, `cli/duo`, or `Makefile` -- this also
 *       catches non-require references (file_get_contents+eval of cli/duo,
 *       assert_file_contains($path, ...), `source "$ROOT/sandbox/lib/x.sh"`)
 *       without needing a case for each call shape.
 *   (c) a known agent/src|cli/src|recovery class token (`Duo\X`, bare `X::`,
 *       `new X(`) maps to X's declaring file(s) -- this is NOT redundant
 *       with (a)/(b): a suite that requires only agent/src/Code/Code.php and
 *       calls `PathSafety::assert_no_symlinked_target_path(...)` through
 *       Code's own internal require chain never spells
 *       "agent/src/Kernel/PathSafety.php" itself, so only the class token proves
 *       the dependency.
 *   (d) a rooted DIRECTORY literal (`$repo . '/manifests'`,
 *       `'manifests/providers'`) recorded as a whole-directory dependency,
 *       matched by prefix at selection time -- the one shape exact-path
 *       matching structurally cannot express, because a suite that does
 *       `glob($dir . '/*.json')` never spells any individual member. See
 *       af_extract_dirs() for the three gates that keep this from
 *       degenerating into `--all`.
 * A fifth signal closes the transitive gap symmetric to (c): agent/src,
 * cli/src, and recovery are "232 flat namespace Duo files with
 * self-requires" (repo fact) that pull each other in via
 * `require_once __DIR__ . '/Sibling.php'` (same-directory, no root prefix,
 * so (b) alone would miss it) or a rooted cross-directory literal that (b)
 * already catches. A one-time file-level require graph over those three
 * trees is built and each target's direct referenced set is closed over it,
 * so a suite requiring only Code.php also lands PathSafety.php et al.
 * without ever mentioning them by name.
 *
 * "involved files" (what a suite's OWN execution touches, as opposed to what
 * it requires from product code) are resolved separately and BFS-expanded:
 * a .sh leaf's same-basename .php companion, plus anything it invokes with
 * `php`/`bash`/`source`/`.` inside sandbox/tests or sandbox/lib (matched by
 * trailing basename so a `$ROOT/`-prefixed or bare invocation both resolve).
 * Every involved file is also, trivially, a "self" reference of its target,
 * which is how `--paths=sandbox/tests/offline/cli/regress_command_output.php` selects
 * `regress-command-output` and a change to a shared sandbox/lib/*.sh or
 * sandbox/tests/fixtures|support file reaches every suite that pulls it in.
 *
 * SPECIAL-CASED INPUTS
 * ---------------------
 * Makefile, sandbox/tests/offline_diagnostics_guard.sh (the corpus's own
 * guarded wrapper -- a change there can alter how every suite runs), or
 * anything under tools/ (this selector's own logic, or its sibling driver)
 * select every leaf target: none of those are provably scoped to a subset,
 * and trusting a stale answer from the tool that just changed is exactly
 * the failure mode this exists to avoid.
 *
 * CACHING
 * -------
 * The index is expensive only in the sense that it touches ~350 test files
 * and ~280 source files once; sandbox/tmp/affected-index.json (gitignored)
 * caches it keyed by a fingerprint of (path, mtime, size) over every file
 * the index depends on -- Makefile, tools/ (this file's own extraction logic
 * included: see af_index_inputs()) and all of AF_ROOT_DIRS except the
 * gitignored sandbox/tmp -- rebuilt automatically on any mismatch or via
 * --rebuild-index.
 *
 * CONSTRAINTS HONOURED HERE
 * ---------------------------
 *  - Plain PHP, no composer runtime deps, cwd-independent, PHP 8.3+ syntax.
 *  - Never reads/writes agent/, cli/, sandbox/bin/, or the Makefile itself;
 *    only sandbox/tmp/affected-index.json is ever written.
 *  - Always exits 0 (tools/offline.php merges this process's stdout+stderr
 *    when it shells out, so a non-zero exit would be read as a hard
 *    failure of `--changed`); malformed CLI usage is the sole exception
 *    (exit 2), matching tools/doctor.sh's convention.
 *
 * Usage:
 *   php tools/affected.php [--base=REF] [--paths=a,b] [--all]
 *                           [--explain] [--json] [--quiet] [--rebuild-index]
 */

const AF_ROOT_DIRS = ['agent', 'cli', 'recovery', 'manifests', 'scripts', 'sandbox', 'docs', 'spec'];
const AF_ROOT_EXTS = ['php', 'json', 'sh', 'md', 'yml', 'Dockerfile'];
const AF_SRC_DIRS = ['agent/src', 'cli/src', 'recovery'];

/**
 * Recursive since the module move (ROUND 3 TRAIN 1): agent/src and cli/src are
 * module directories now, so the previous `glob($dir . '/*.php')` would have
 * returned zero files and silently emptied both the class map and the source
 * graph. Returns repo-relative paths, which is what both callers key on.
 *
 * @return list<string>
 */
function af_tree_php_files(string $root, string $dir): array
{
    $base = $root . '/' . $dir;
    if (!is_dir($base)) {
        return [];
    }
    $out = [];
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($walk as $entry) {
        if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
            $out[] = $dir . '/' . str_replace('\\', '/', substr($entry->getPathname(), strlen($base) + 1));
        }
    }
    sort($out, SORT_STRING);
    return $out;
}
const AF_CACHE_RELATIVE = 'sandbox/tmp/affected-index.json';
const AF_REASON_PRIORITY = ['self' => 0, 'require' => 1, 'class' => 2, 'path' => 3];

function af_usage(string $message): never
{
    fwrite(STDERR, "affected: $message\n");
    fwrite(STDERR, 'usage: php tools/affected.php [--base=REF] [--paths=a,b] [--all]'
        . " [--explain] [--json] [--quiet] [--rebuild-index]\n");
    exit(2);
}

function af_repo_root(): string
{
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        if (is_file($dir . '/Makefile') && is_dir($dir . '/sandbox/tests')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return dirname(__DIR__);
}

/** @return array{exit:int,out:string,err:string} */
function af_exec(array $argv, string $cwd): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($argv, $descriptors, $pipes, $cwd);
    if (!is_resource($proc)) {
        return ['exit' => 127, 'out' => '', 'err' => 'could not spawn ' . implode(' ', $argv)];
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['exit' => $exit, 'out' => $out, 'err' => $err];
}

/** @return list<string> non-empty, trimmed lines */
function af_lines(string $blob): array
{
    $out = [];
    foreach (explode("\n", $blob) as $line) {
        $line = rtrim($line, "\r");
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

/**
 * Canonical repo-relative spelling: backslashes to slashes, then a purely
 * LEXICAL collapse of `.` and `..` segments.
 *
 * The collapse is the load-bearing half. Every selection decision in this
 * tool is an exact string comparison between an indexed reference and a path
 * git printed, and git only ever prints the collapsed spelling. A resolver
 * that stored `sandbox/tests/../../agent/src/Repository/Ledger.php` produced a key that
 * is_file() happily accepts (the OS resolves it) yet no changed file can
 * ever equal -- a silently dead index entry, which is the exact false
 * negative this selector exists to prevent. Lexical rather than realpath()
 * because the index must stay comparable to git's output even for paths that
 * traverse a symlinked directory, and because it must work for paths that do
 * not exist on disk (the `--paths=` inputs of a deleted file).
 *
 * `..` never escapes the root: a segment that would pop an empty stack is
 * dropped, so no reference can ever name something outside the repo.
 */
function af_normalize_path(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '') {
        return '';
    }
    $absolute = $path[0] === '/';
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }
    return ($absolute ? '/' : '') . implode('/', $segments);
}

// -------------------------------------------------------- make db parsing

/**
 * Parses the `# Files` section of `make -pn --no-print-directory <target>`
 * into a flat target -> prerequisites table. Mirrors the format make -p
 * documents: a `target: prereqs` line at column 0, optional recipe lines
 * (leading tab) which are ignored here, blank lines separating entries, and
 * `#`/leading-space lines that are variable-origin annotations, never real
 * prerequisite data (the bug this guards against: naively regexing the
 * WHOLE -p dump for `^name:` matches the comment lines make prints
 * immediately under a leaf target, e.g. "#  Phony target (prerequisite of
 * .PHONY)." on its own line, as if they were more prerequisites).
 *
 * @return array<string,list<string>>
 */
function af_parse_make_prereqs(string $text): array
{
    $prereqs = [];
    $inFiles = false;
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        if (!$inFiles) {
            if (rtrim($line) === '# Files') {
                $inFiles = true;
            }
            continue;
        }
        if (str_starts_with($line, '# files hash-table stats:')
            || str_starts_with($line, '# VPATH Search Paths')
            || str_starts_with($line, '# Finished Make data base')) {
            break;
        }
        if ($line === '' || $line[0] === "\t" || $line[0] === '#' || $line[0] === ' ') {
            continue;
        }
        if (!preg_match('/^([^:=#\s][^:=]*):(?!=)(.*)$/', $line, $m)) {
            continue;
        }
        $target = trim($m[1]);
        $rest = $m[2];
        if ($rest !== '' && $rest[0] === ':') {
            $rest = substr($rest, 1);
        }
        if (!array_key_exists($target, $prereqs)) {
            $prereqs[$target] = preg_split('/\s+/', trim($rest), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
    }
    return $prereqs;
}

/**
 * Depth-first walk from $root; a target with no prerequisites is a leaf.
 * De-duplicated, order not significant to callers (the result is sorted).
 *
 * @param array<string,list<string>> $prereqs
 * @return list<string>
 */
function af_expand_leaves(array $prereqs, string $root): array
{
    $seen = [];
    $leaves = [];
    $walk = static function (string $target) use (&$walk, $prereqs, &$seen, &$leaves): void {
        if (isset($seen[$target])) {
            return;
        }
        $seen[$target] = true;
        $children = $prereqs[$target] ?? [];
        if ($children === []) {
            $leaves[] = $target;
            return;
        }
        foreach ($children as $child) {
            $walk($child);
        }
    };
    $walk($root);
    return $leaves;
}

/** @return list<string> sorted, unique leaf target names */
function af_offline_leaves(string $root): array
{
    $result = af_exec(['make', '-pn', '--no-print-directory', 'regress-offline-corpus'], $root);
    if ($result['exit'] !== 0 && !str_contains($result['out'], '# Files')) {
        fwrite(STDERR, "affected: `make -pn --no-print-directory regress-offline-corpus` failed:\n"
            . $result['out'] . $result['err'] . "\n");
        exit(2);
    }
    $prereqs = af_parse_make_prereqs($result['out']);
    $leaves = af_expand_leaves($prereqs, 'regress-offline-corpus');
    $leaves = array_values(array_unique($leaves));
    sort($leaves, SORT_STRING);
    return $leaves;
}

// ---------------------------------------------------- target -> suite file

/** Strip full-line `#` comments so a doc-comment mention of another suite's
 * filename is never mistaken for that suite actually invoking it -- the
 * same precaution sandbox/tests/offline/guards/regress_bundle_coverage.sh takes. */
function af_code_lines(string $text): string
{
    $kept = [];
    foreach (explode("\n", $text) as $line) {
        if (!preg_match('/^\s*#/', $line)) {
            $kept[] = $line;
        }
    }
    return implode("\n", $kept);
}

const AF_GENERIC_INVOCATION_RX = '/\b(?:php|bash)\s+(?:\S*\/)?(regress_[a-z0-9_]+\.(?:sh|php))\b/';

/**
 * Every `regress_*.{php,sh}` file under sandbox/tests, at ANY depth, keyed by
 * basename.
 *
 * Recursive, not a `scandir()` of the top level, because the suite estate is
 * being moved into sandbox/tests/{offline/<domain>,live,grind,certify,spike}/.
 * A non-recursive enumeration here fails OPEN, which is the whole reason this
 * had to change before any file moved: a nested suite simply has no primary
 * file, so af_build_index() drops its target with a NOTICE and that target
 * then selects for nothing -- a silent hole in `--changed`, not a failure.
 *
 * Basename-keyed because that is the target-naming rule this tool shares with
 * sandbox/tests/offline/guards/regress_bundle_coverage.sh (`regress_foo_bar.sh` <->
 * `regress-foo-bar`), and a directory prefix contributes nothing to a target
 * name. Two files with the same basename therefore claim ONE target between
 * them: only the first (sorted) can ever be reached, so the second is
 * reported rather than silently dropped. regress-suite-wiring and
 * regress_bundle_coverage.sh both fail hard on that condition; this notice
 * exists because this tool's stated contract is to always exit 0.
 *
 * @return array<string,string> basename => repo-relative path
 */
function af_suite_files(string $root): array
{
    $testsDir = $root . '/sandbox/tests';
    if (!is_dir($testsDir)) {
        return [];
    }
    $paths = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $entry) {
        if (!($entry instanceof SplFileInfo) || !$entry->isFile()) {
            continue;
        }
        if (!preg_match('/^regress_.*\.(?:php|sh)$/', $entry->getFilename())) {
            continue;
        }
        $paths[] = 'sandbox/tests/'
            . str_replace('\\', '/', substr($entry->getPathname(), strlen($testsDir) + 1));
    }
    sort($paths, SORT_STRING);

    $byBasename = [];
    foreach ($paths as $relative) {
        $base = basename($relative);
        if (isset($byBasename[$base])) {
            fwrite(STDERR, "affected: NOTICE suite basename '$base' exists twice ("
                . $byBasename[$base] . ' and ' . $relative . ') -- the target-naming rule is'
                . ' basename-keyed, so only the first can name a target and the second is'
                . " invisible to this index\n");
            continue;
        }
        $byBasename[$base] = $relative;
    }

    return $byBasename;
}

/**
 * target -> primary suite path, using the exact naming/exclusion rule
 * regress_bundle_coverage.sh proves against the whole tree: a
 * regress_*.{sh,php} file names its own target (`regress_foo_bar.sh` <->
 * `regress-foo-bar`) unless some OTHER such file's code actually invokes it,
 * in which case it is a helper of that other file's target instead.
 *
 * The value is the repo-relative PATH, not the basename: with suites at
 * arbitrary depth there is no longer any way to reconstruct one from the
 * other, and af_build_index()'s old `'sandbox/tests/' . $basename` would have
 * produced a path that does not exist for every nested suite.
 *
 * @return array<string,string> target => repo-relative path
 */
function af_primary_targets(string $root): array
{
    $byBasename = af_suite_files($root);
    $basenames = array_keys($byBasename);

    $contents = [];
    foreach ($basenames as $b) {
        $contents[$b] = af_code_lines((string) file_get_contents($root . '/' . $byBasename[$b]));
    }

    $invokedBy = [];
    foreach ($contents as $owner => $text) {
        if (preg_match_all(AF_GENERIC_INVOCATION_RX, $text, $m)) {
            foreach ($m[1] as $invoked) {
                $invokedBy[$invoked][$owner] = true;
            }
        }
    }
    $invokedElsewhere = static function (string $basename) use ($invokedBy): bool {
        foreach (array_keys($invokedBy[$basename] ?? []) as $owner) {
            if ($owner !== $basename) {
                return true;
            }
        }
        return false;
    };

    $primary = [];
    foreach ($basenames as $b) {
        if ($invokedElsewhere($b)) {
            continue;
        }
        $stem = (string) preg_replace('/\.(?:sh|php)$/', '', $b);
        $target = strtr($stem, '_', '-');
        $primary[$target] = $byBasename[$b];
    }
    return $primary;
}

// ------------------------------------------------ suite composition (BFS)

/**
 * basename -> every repo-relative path with that basename, over the WHOLE of
 * sandbox/tests and sandbox/lib. Used to resolve `php x.php` / `bash x.sh` /
 * `source x.sh` / `. x.sh` invocations regardless of how the invoking line
 * spells the leading path (bare, `$ROOT/...`-prefixed, `$FIX/`-prefixed via a
 * shell variable, or fully rooted).
 *
 * RECURSIVE, replacing a hand-written allowlist of `fixtures`, `support` and
 * `lib` probed exactly one level below sandbox/tests. That shape has two
 * costs. The one that forced this change: a suite under
 * sandbox/tests/offline/<domain>/ is in no allowlist entry, so nothing it
 * invokes -- and nothing that invokes it -- resolves, and the involved-file
 * BFS silently stops at the suite itself. The one it also fixes: the 94 files
 * under sandbox/tests/fixtures/<subject>/ were already two levels down and
 * therefore already unresolvable, so a grind fixture edit selected nothing.
 *
 * MULTI-VALUED because a basename is not unique in a nested tree: 20 pairs
 * collide today (fixtures/mup vs fixtures/adapter-walk, fixtures/assess vs
 * fixtures/rehearse), and the invoking line usually cannot disambiguate them
 * -- regress_rehearse_provider.sh:316 spells `php "$FIX/make-fixture.php"`,
 * where $FIX is a shell variable this text scan does not evaluate. Picking one
 * winner would link that suite to the assess fixture and NOT to the rehearse
 * fixture it actually runs, i.e. manufacture a false negative, the one
 * direction this tool's policy forbids (see the header). Linking every
 * candidate costs at most one extra suite per collision and cannot miss one.
 *
 * @return array<string,list<string>> basename => repo-relative paths, sorted
 */
function af_basename_index(string $root): array
{
    $index = [];
    $add = static function (string $dir) use (&$index, $root): void {
        $base = $root . '/' . $dir;
        if (!is_dir($base)) {
            return;
        }
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($walk as $entry) {
            if (!($entry instanceof SplFileInfo) || !$entry->isFile()) {
                continue;
            }
            $relative = $dir . '/'
                . str_replace('\\', '/', substr($entry->getPathname(), strlen($base) + 1));
            $index[$entry->getFilename()][] = $relative;
        }
    };
    $add('sandbox/tests');
    $add('sandbox/lib');
    foreach ($index as $basename => $paths) {
        sort($paths, SORT_STRING);
        $index[$basename] = array_values(array_unique($paths));
    }
    return $index;
}

const AF_INVOKE_RUN_RX = '/\b(?:php|bash)\s+"?([^\s"]+\.(?:php|sh))"?/';
const AF_INVOKE_SOURCE_RX = '/^[ \t]*(?:source|\.)\s+"?([^\s"]+\.sh)"?/m';

/**
 * @param array<string,list<string>> $basenameIndex
 * @return list<string> repo-relative paths, primary file first
 */
function af_gather_involved(string $root, string $primaryRelative, array $basenameIndex): array
{
    $involved = [$primaryRelative => true];
    $queue = [$primaryRelative];

    if (str_ends_with($primaryRelative, '.sh')) {
        $companion = substr($primaryRelative, 0, -3) . '.php';
        if (is_file($root . '/' . $companion)) {
            $involved[$companion] = true;
            $queue[] = $companion;
        }
    }

    while ($queue !== []) {
        $current = array_shift($queue);
        $path = $root . '/' . $current;
        if (!is_file($path)) {
            continue;
        }
        $text = (string) file_get_contents($path);
        $found = [];
        if (preg_match_all(AF_INVOKE_RUN_RX, $text, $m)) {
            array_push($found, ...$m[1]);
        }
        if (preg_match_all(AF_INVOKE_SOURCE_RX, $text, $m)) {
            array_push($found, ...$m[1]);
        }
        foreach ($found as $ref) {
            // EVERY same-named candidate, not the best one: see
            // af_basename_index(). A `$FIX/make-fixture.php` invocation names
            // two real fixtures and the text scan cannot say which, so both
            // are followed rather than one guessed.
            foreach ($basenameIndex[basename($ref)] ?? [] as $resolved) {
                if (isset($involved[$resolved])) {
                    continue;
                }
                $involved[$resolved] = true;
                $queue[] = $resolved;
            }
        }
    }

    return array_keys($involved);
}

// -------------------------------------------- literal path / class tokens

/**
 * Literal repo-relative path tokens, rooted at one of AF_ROOT_DIRS.
 *
 * The negative lookbehind exists ONLY to stop a mid-identifier match
 * (`myagent/src/X.php` must not register `agent/src/X.php`), so it excludes
 * exactly the characters that can continue an identifier. It must NOT
 * exclude `/`, `.` or `-`: those are precisely the characters that precede a
 * root dir in the dominant spellings in this repo --
 * `__DIR__ . '/../../agent/src/X.php'`, `$root . '/agent/src/X.php'`,
 * `"$ROOT/sandbox/lib/pair_budget_lock.sh"`, `../../scripts/x.sh`. Excluding
 * them made signals (a) and (b) dead for every such line (~3.9k index edges
 * over 148 files), leaving sandbox/lib, scripts/ and every fixture with no
 * covering suite at all. The widened form can only ever match MORE tokens,
 * and every match is still gated by is_file() in af_extract_paths(), so the
 * cost of the relaxation is bounded by this tool's stated policy that
 * over-selection is the safe direction.
 */
function af_root_path_regex(): string
{
    $dirs = implode('|', AF_ROOT_DIRS);
    $exts = implode('|', AF_ROOT_EXTS);
    return '#(?<![A-Za-z0-9_])(?:' . $dirs . ')/[A-Za-z0-9_./-]+\.(?:' . $exts . ')#';
}

/**
 * @return list<array{path:string,reason:string}> existing repo files only
 */
function af_extract_paths(string $root, string $text): array
{
    static $rootRx = null;
    $rootRx ??= af_root_path_regex();

    $out = [];
    $seen = [];
    $add = static function (string $path, int $offset, string $text) use (&$out, &$seen, $root): void {
        $path = af_normalize_path($path);
        if (!is_file($root . '/' . $path)) {
            return;
        }
        $lineStart = strrpos(substr($text, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $before = substr($text, $lineStart, $offset - $lineStart);
        $reason = preg_match('/\b(?:require_once|require|include_once|include)\b/', $before) === 1
            ? 'require' : 'path';
        $key = $path . '|' . $reason;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $out[] = ['path' => $path, 'reason' => $reason];
    };

    if (preg_match_all($rootRx, $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$match, $offset]) {
            $add($match, $offset, $text);
        }
    }
    if (preg_match_all('/\bcli\/duo\b/', $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$match, $offset]) {
            $add($match, $offset, $text);
        }
    }
    if (preg_match_all('/\bMakefile\b/', $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$match, $offset]) {
            $add($match, $offset, $text);
        }
    }
    return $out;
}

/**
 * The `__DIR__`-anchored base of a path concatenation, as a number of
 * directory levels to climb from the referencing file's own directory.
 *
 * `__DIR__` is 0, `dirname(__DIR__)` is 1, `dirname(__DIR__, N)` is N. The
 * dirname() forms are not decoration: they produce a path token with no
 * root-directory prefix at all (regress_code_release.php resolves its three
 * fixtures as `dirname(__DIR__) . '/tests/fixtures/code-release-provider.php'`,
 * regress_ecommerce_extension_migration.php uses `dirname(__DIR__, 2)`), so
 * the rooted regex has nothing to match either and the dependency is
 * invisible unless this resolver understands the base.
 */
const AF_DIR_BASE_RX = '(?P<base>__DIR__|dirname\s*\(\s*__DIR__\s*(?:,\s*(?P<up>\d+)\s*)?\))';

/** Levels to climb for a matched AF_DIR_BASE_RX base expression. */
function af_dir_base_levels(string $base, string $up): int
{
    if (!str_contains($base, 'dirname')) {
        return 0;
    }
    return $up === '' ? 1 : max(0, (int) $up);
}

/**
 * Directories whose contents are ALREADY resolved file-by-file by the other
 * signals, and which must therefore never become a whole-directory
 * dependency: agent/src, cli/src and recovery are covered by the class map
 * plus the source require graph, sandbox/tests and sandbox/lib by the
 * basename index and the involved-file BFS. Letting one of these in would
 * make a token as incidental as `cd "$ROOT/sandbox/tests"` mean "every test
 * file in the repo is my dependency", collapsing the tool to `--all` for the
 * commonest edit there is. sandbox/tmp is scratch (gitignored) and never a
 * real input. A candidate is rejected when it is any of these, an ancestor of
 * one (which is what drops the bare `agent`, `cli` and `sandbox` roots), or a
 * DESCENDANT of one -- see af_dir_signal_excluded().
 */
const AF_DIR_SIGNAL_EXCLUDED = [
    'agent/src',
    'cli/src',
    'recovery',
    'sandbox/tests',
    'sandbox/lib',
    'sandbox/bin',
    'sandbox/tmp',
];

/**
 * Whole-DIRECTORY dependencies, the signal that exact-path matching
 * structurally cannot express.
 *
 * Several offline leaves load a tree wholesale rather than naming its
 * members -- `$dir = $repo . '/manifests'; glob($dir . '/*.json')`,
 * `glob($root . '/manifests/providers/*.php')`, `scandir(...)` loops. No individual
 * manifest is ever spelled, so no ref key can exist for it, and every one of
 * the ~87 files under manifests/ selected zero suites -- the highest-traffic
 * false negative there was, since manifests are product data edited
 * constantly.
 *
 * Rather than enumerate call shapes (glob/scandir/opendir/::load/find/ls,
 * each with its own indirection through a local variable assigned on an
 * earlier line), this takes any rooted directory LITERAL and lets three
 * cheap gates do the filtering:
 *   1. the token must look like a path, not prose -- either it has more than
 *      one segment (`manifests/providers`) or it is directly preceded by a
 *      slash (`$repo . '/manifests'`), so the English word "docs" or "spec"
 *      in a comment cannot register the whole tree;
 *   2. it must actually be a directory on disk (which is also what rejects
 *      `agent/src/Kernel/Canon.php`: the greedy match consumes the filename, is_dir
 *      fails, and no dependency on the containing directory is invented);
 *   3. it must not be an already-precisely-covered tree (see above).
 *
 * Gate 1 is deliberately loose for a single-segment token: it must accept
 * `$repo . '/manifests'` (regress_manifest_dispositions.php:68's shape, and
 * the whole reason this signal exists), which is lexically indistinguishable
 * from a same-shaped literal meant for somewhere else -- e.g.
 * regress_adapter_sources.php's `copy_tree(dirname($fixture) . '/docs', ...)`
 * registers a dependency on the repo's docs/ tree it does not really have.
 * That is the documented safe direction and the cost is one extra suite, not
 * a class of missed regressions.
 *
 * @return list<string> existing repo-relative directories, no trailing slash
 */
function af_extract_dirs(string $root, string $text): array
{
    static $dirRx = null;
    $dirRx ??= '#(?<![A-Za-z0-9_])(?:' . implode('|', AF_ROOT_DIRS) . ')(?:/[A-Za-z0-9_.-]+)*#';

    $out = [];
    if (!preg_match_all($dirRx, $text, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    foreach ($m[0] as [$token, $offset]) {
        $precededBySlash = $offset > 0 && $text[$offset - 1] === '/';
        if (!str_contains($token, '/') && !$precededBySlash) {
            continue;
        }
        $dir = af_normalize_path($token);
        if ($dir === '' || !is_dir($root . '/' . $dir)) {
            continue;
        }
        if (af_dir_signal_excluded($dir)) {
            continue;
        }
        $out[$dir] = true;
    }
    $dirs = array_keys($out);
    sort($dirs, SORT_STRING);
    return $dirs;
}

/** True when $dir is, or is an ancestor of, a tree the file-level signals
 * already resolve precisely. */
function af_dir_signal_excluded(string $dir): bool
{
    foreach (AF_DIR_SIGNAL_EXCLUDED as $precise) {
        // The third arm is new with the module move (ROUND 3 TRAIN 1):
        // agent/src and cli/src have module subdirectories now, and
        // `agent/src/Kernel` must be excluded for the same reason its parent
        // is — its contents are already resolved file-by-file.
        //
        // (This line is matched verbatim by tools/codemod/move-modules.php's
        // mm_scanner_fixes(), which uses a replacement's first line to decide
        // the rewrite is already applied; MoveModulesTest's real-repo no-op
        // case fails if it is reworded.)
        //
        // It carries the sandbox/tests restructure unchanged and is
        // load-bearing there for the same reason: once suites live under
        // sandbox/tests/offline/<domain>, a bare
        // `cd "$ROOT/sandbox/tests/offline/repository"` in one suite would
        // otherwise register that whole domain as a directory dependency and
        // select every suite in it on any edit — the `--all` collapse this
        // exclusion list exists to prevent, just one level down.
        if ($dir === $precise
            || str_starts_with($precise . '/', $dir . '/')
            || str_starts_with($dir . '/', $precise . '/')) {
            return true;
        }
    }
    return false;
}

/**
 * Same-directory self-requires (`require_once __DIR__ . '/Sibling.php'`),
 * the shape agent/src, cli/src, and recovery use for their internal
 * dependency graph. No root-directory prefix appears in these strings, so
 * af_extract_paths()'s rooted regex cannot see them; this is the
 * complementary extractor used only when building the source require graph.
 *
 * @return list<string> existing repo-relative paths
 */
function af_extract_same_dir_requires(string $root, string $fileRelative, string $text): array
{
    $dir = dirname($fileRelative);
    $out = [];
    if (preg_match_all(
        '/require(?:_once)?\s+' . AF_DIR_BASE_RX . '\s*\.\s*[\'"]\/([A-Za-z0-9_.\/-]+\.php)[\'"]/',
        $text,
        $matches,
        PREG_SET_ORDER
    )) {
        foreach ($matches as $m) {
            $levels = af_dir_base_levels($m['base'], $m['up'] ?? '');
            $resolved = af_normalize_path($dir . str_repeat('/..', $levels) . '/' . $m[3]);
            if ($resolved !== '' && is_file($root . '/' . $resolved)) {
                $out[] = $resolved;
            }
        }
    }
    return array_values(array_unique($out));
}

/**
 * Any `__DIR__ . '/relative/file.ext'` token in a SUITE file — not only
 * require statements: suites also copy, read, or diff fixtures by
 * `__DIR__`-relative path (`$fixture = __DIR__ . '/fixtures/x.php'`). Resolved
 * against the suite's own directory. Over-selection is the safe direction, so
 * every such token that names an existing repo file counts as a dependency.
 *
 * @return list<string> existing repo-relative paths
 */
function af_extract_dir_relative_paths(string $root, string $fileRelative, string $text): array
{
    $dir = dirname($fileRelative);
    $out = [];
    if (preg_match_all(
        '/' . AF_DIR_BASE_RX . '\s*\.\s*[\'"]'
            . '\/([A-Za-z0-9_.\/-]+\.(?:php|json|sh|md|yml|yaml|txt|lock|Dockerfile))[\'"]/',
        $text,
        $matches,
        PREG_SET_ORDER
    )) {
        foreach ($matches as $m) {
            $levels = af_dir_base_levels($m['base'], $m['up'] ?? '');
            $resolved = af_normalize_path($dir . str_repeat('/..', $levels) . '/' . $m[3]);
            if ($resolved !== '' && is_file($root . '/' . $resolved)) {
                $out[] = $resolved;
            }
        }
    }
    return array_values(array_unique($out));
}

/**
 * The candidate needle of the sandbox-relative suite-reference rule. The
 * leading lookbehind excludes `/` on purpose: without it every repo-relative
 * `sandbox/tests/x.sh` would also yield a candidate resolving to the
 * non-existent `sandbox/sandbox/tests/x.sh`. Byte-for-byte the regex quoted in
 * the rule block in tools/codemod/move-suites.php.
 */
const AF_SANDBOX_RELATIVE_RX = '#(?<![A-Za-z0-9_./-])tests/[A-Za-z0-9_./-]+\.(?:sh|php)#';

/**
 * The SANDBOX-RELATIVE spelling of a corpus path, in a file under sandbox/.
 *
 * THE RULE IS NOT STATED HERE. It is stated once, as prose, in the block above
 * `ms_sandbox_relative_tail()` in tools/codemod/move-suites.php — what the two
 * spellings are, why resolution rather than assumption decides between them,
 * the collision measurement the disambiguation rests on, and why three
 * standalone tools carry three implementations instead of one shared library.
 * Read that block before changing this function. (DUO-3482.)
 *
 * This is the READER's half of it, and the policy difference is the whole
 * reason it is not shared code: this tool only ever ADDS an index edge, where
 * over-selection is the documented safe direction (see the header), so an
 * unresolvable token costs nothing and there is no review bucket to route an
 * ambiguity into. Both readings are therefore kept rather than one guessed —
 * and the repo-root reading is not even expressible as a ref key, because
 * `tests` is not in AF_ROOT_DIRS.
 *
 * The edge this closes was proven, not hypothetical: nothing referenced
 * sandbox/tests/grind/grind_r1c_agency.sh, because the only suite that guards
 * it — regress_grind_r1c_manifest_preserve.sh — cds to `sandbox/` (:12) and
 * therefore spells it `G=tests/grind/grind_r1c_agency.sh` (:17). Editing that
 * grind selected nothing at all.
 *
 * @return list<string> existing repo-relative paths
 */
function af_extract_sandbox_relative_paths(string $root, string $fileRelative, string $text): array
{
    if (!str_starts_with($fileRelative, 'sandbox/')) {
        return [];
    }
    $out = [];
    if (preg_match_all(AF_SANDBOX_RELATIVE_RX, $text, $m)) {
        foreach ($m[0] as $token) {
            $resolved = af_normalize_path('sandbox/' . $token);
            if ($resolved !== '' && is_file($root . '/' . $resolved)) {
                $out[] = $resolved;
            }
        }
    }
    return array_values(array_unique($out));
}

/** class name -> declaring file(s), scanned once across agent/src, cli/src,
 * recovery. Multiple files can share a class name across namespaces (e.g.
 * Init in both agent/src and cli/src); over-selecting both is the safe
 * direction.
 *
 * @return array<string,list<string>>
 */
function af_class_map(string $root): array
{
    $map = [];
    foreach (AF_SRC_DIRS as $dir) {
        foreach (af_tree_php_files($root, $dir) as $relative) {
            $file = $root . '/' . $relative;
            $text = (string) file_get_contents($file);
            if (preg_match_all(
                '/^\s*(?:abstract\s+|final\s+)?(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/m',
                $text,
                $m
            )) {
                foreach ($m[1] as $name) {
                    $map[$name][] = $relative;
                }
            }
        }
    }
    foreach ($map as $name => $files) {
        $map[$name] = array_values(array_unique($files));
    }
    return $map;
}

/**
 * `\\{1,2}` rather than a single backslash: a class named inside a PHP
 * string literal -- which is how the load-boundary suites spell the classes
 * they assert must NOT be loaded, e.g.
 * `foreach (['Duo\\\\Capture', 'Duo\\\\Policy'] as $forbidden)` -- carries two
 * backslash bytes in the source, so the single-backslash form never matched
 * it and those suites resolved no class at all.
 */
const AF_CLASS_TOKEN_RX = '/Duo\\\\{1,2}(?:[A-Za-z_][A-Za-z0-9_]*\\\\{1,2})*([A-Za-z_][A-Za-z0-9_]*)'
    . '|\bnew\s+([A-Za-z_][A-Za-z0-9_]*)\s*\('
    . '|\b([A-Za-z_][A-Za-z0-9_]*)::/';

/** @param array<string,list<string>> $classMap
 * @return list<string> declaring files referenced by class tokens in $text
 */
function af_extract_class_files(string $text, array $classMap): array
{
    $out = [];
    if (!preg_match_all(AF_CLASS_TOKEN_RX, $text, $m, PREG_SET_ORDER)) {
        return $out;
    }
    foreach ($m as $match) {
        $name = '';
        foreach ([1, 2, 3] as $group) {
            if (($match[$group] ?? '') !== '') {
                $name = $match[$group];
                break;
            }
        }
        if ($name === '' || !isset($classMap[$name])) {
            continue;
        }
        array_push($out, ...$classMap[$name]);
    }
    return array_values(array_unique($out));
}

// ------------------------------------------------------ source-file graph

/**
 * One-time require graph over agent/src + cli/src + recovery, so a target
 * that only requires agent/src/Code/Code.php also picks up everything Code.php
 * requires internally (PathSafety.php, CodeMaterializer.php, ...).
 *
 * @return array<string,list<string>>
 */
function af_source_graph(string $root): array
{
    $graph = [];
    foreach (AF_SRC_DIRS as $dir) {
        foreach (af_tree_php_files($root, $dir) as $relative) {
            $file = $root . '/' . $relative;
            $text = (string) file_get_contents($file);
            $edges = [];
            foreach (af_extract_paths($root, $text) as $ref) {
                $edges[$ref['path']] = true;
            }
            foreach (af_extract_same_dir_requires($root, $relative, $text) as $ref) {
                $edges[$ref] = true;
            }
            // Not redundant with the require extractor above: a source file
            // can name a sibling it never requires, because it runs it as a
            // FRESH PROCESS instead -- cli/src/Refresh/RefreshPlan.php holds
            // `$worker = __DIR__ . '/RefreshPlanCompile.php';` and that
            // worker is the entire compile body of `duo refresh --plan`.
            // Without this edge the worker file had no inbound reference at
            // all and editing it selected zero suites.
            foreach (af_extract_dir_relative_paths($root, $relative, $text) as $ref) {
                $edges[$ref] = true;
            }
            unset($edges[$relative]);
            $graph[$relative] = array_keys($edges);
        }
    }
    return $graph;
}

/**
 * @param array<string,list<string>> $graph
 * @return list<string>
 */
function af_closure(string $start, array $graph): array
{
    $seen = [$start => true];
    $queue = [$start];
    while ($queue !== []) {
        $current = array_shift($queue);
        foreach ($graph[$current] ?? [] as $next) {
            if (!isset($seen[$next])) {
                $seen[$next] = true;
                $queue[] = $next;
            }
        }
    }
    unset($seen[$start]);
    return array_keys($seen);
}

function af_in_source_tree(string $path): bool
{
    foreach (AF_SRC_DIRS as $dir) {
        if (str_starts_with($path, $dir . '/')) {
            return true;
        }
    }
    return false;
}

// ------------------------------------------------------------- index build

/**
 * @return array{
 *   leaves: list<string>,
 *   targets: array<string, array{
 *     primary:string,
 *     refs: array<string,string>,
 *     dirs: list<string>
 *   }>
 * }
 */
function af_build_index(string $root): array
{
    $leaves = af_offline_leaves($root);
    $primaryByTarget = af_primary_targets($root);
    $basenameIndex = af_basename_index($root);
    $classMap = af_class_map($root);
    $sourceGraph = af_source_graph($root);

    $targets = [];
    foreach ($leaves as $target) {
        // af_primary_targets() already carries the repo-relative path: a
        // nested suite's path cannot be rebuilt from its basename, and the
        // `'sandbox/tests/' . $basename` this replaced would have named a
        // non-existent file for every suite below the top level.
        $primaryRelative = $primaryByTarget[$target] ?? null;
        if ($primaryRelative === null) {
            fwrite(STDERR, "affected: NOTICE offline leaf '$target' has no sandbox/tests/regress_*"
                . " file under the naming rule -- it will select for nothing\n");
            continue;
        }
        $involved = af_gather_involved($root, $primaryRelative, $basenameIndex);

        /** @var array<string,string> $refs path => reason */
        $refs = [];
        /** @var array<string,true> $dirs directory => true */
        $dirs = [];
        $setReason = static function (string $path, string $reason) use (&$refs): void {
            $current = $refs[$path] ?? null;
            if ($current === null || AF_REASON_PRIORITY[$reason] < AF_REASON_PRIORITY[$current]) {
                $refs[$path] = $reason;
            }
        };

        foreach ($involved as $file) {
            $setReason($file, 'self');
            $text = (string) file_get_contents($root . '/' . $file);
            foreach (af_extract_paths($root, $text) as $ref) {
                $setReason($ref['path'], $ref['reason']);
            }
            // A suite's own `require __DIR__ . '/support/x.php'` /
            // `'/lib/FakeWpdb.php'` / `'/fixtures/y.php'` carries no rooted
            // prefix, so the rooted regex above cannot see it; resolve it
            // against the suite's directory exactly as the source graph does.
            foreach (af_extract_same_dir_requires($root, $file, $text) as $ref) {
                $setReason($ref, 'require');
            }
            foreach (af_extract_dir_relative_paths($root, $file, $text) as $ref) {
                $setReason($ref, 'path');
            }
            // The same suite named under the OTHER legitimate spelling. A
            // corpus file whose cwd is sandbox/ writes `tests/grind/x.sh`, and
            // neither the rooted regex nor the __DIR__ resolver above can see
            // that token: it carries no root-directory prefix and no __DIR__
            // base. 97 such tokens over 12 corpus files today; resolving them
            // adds 15 index edges across 6 offline targets, every one of which
            // did not exist before DUO-3482. (Most of the 97 repeat one path --
            // regress_target_observation_premises.sh alone names the six
            // certify matrices 78 times -- and the tokens in live/ suites add
            // no edge, because a live suite is no offline leaf's primary file.)
            foreach (af_extract_sandbox_relative_paths($root, $file, $text) as $ref) {
                $setReason($ref, 'path');
            }
            foreach (af_extract_class_files($text, $classMap) as $file2) {
                $setReason($file2, 'class');
            }
            foreach (af_extract_dirs($root, $text) as $dir) {
                $dirs[$dir] = true;
            }
        }

        foreach (array_keys($refs) as $path) {
            if (!af_in_source_tree($path)) {
                continue;
            }
            foreach (af_closure($path, $sourceGraph) as $extra) {
                $setReason($extra, 'require');
            }
        }

        // Build-time invariant, not decoration: every selection is an exact
        // string match against a path git printed, and git only ever prints
        // the collapsed spelling. A ref key containing '/../' is therefore
        // provably unreachable -- it passes is_file() (the OS resolves it)
        // and then silently never matches anything. 607 such dead keys over
        // 176 distinct paths is what this assertion would have caught the
        // day af_normalize_path() stopped short of collapsing '..'.
        foreach (array_keys($refs) as $path) {
            if (str_contains('/' . $path . '/', '/../')) {
                fwrite(STDERR, "affected: NOTICE target '$target' stored a non-canonical ref"
                    . " '$path' -- it contains '..' and can never match a changed file\n");
            }
        }

        $dirNames = array_keys($dirs);
        sort($dirNames, SORT_STRING);
        $targets[$target] = [
            'primary' => $primaryRelative,
            'refs' => $refs,
            'dirs' => $dirNames,
        ];
    }

    return ['leaves' => $leaves, 'targets' => $targets];
}

// --------------------------------------------------------------- caching

/**
 * Every file whose content or mere existence can change the index.
 *
 * Two classes of input are easy to forget and were both missing:
 *
 *  - THIS FILE. The index is a product of the extraction regexes in
 *    tools/affected.php as much as of the corpus they run over. With the
 *    selector's own bytes outside the fingerprint, fixing an extraction bug
 *    left the fingerprint matching, so the very next run -- including
 *    `php tools/offline.php --changed`, which never passes --rebuild-index
 *    -- silently served the pre-fix answer at exit 0. A stale index is
 *    exactly the false negative this tool exists to prevent, so its own
 *    logic is fingerprinted first.
 *
 *  - The rest of AF_ROOT_DIRS. Every extracted token is gated through
 *    is_file()/is_dir(), so ADDING or REMOVING a file under manifests/,
 *    docs/, scripts/, spec/, sandbox/bin/ or sandbox/conformance/ flips
 *    index entries without touching anything the old walk covered.
 *
 * sandbox/tmp is skipped: it is gitignored scratch, it holds this very cache
 * file, and including it would make every write self-invalidate the entry it
 * just produced.
 *
 * @return list<string> files the index depends on
 */
function af_index_inputs(string $root): array
{
    $files = [$root . '/Makefile', __FILE__];
    $skip = $root . '/sandbox/tmp';
    $walk = static function (string $dir) use (&$files, $skip): void {
        if (!is_dir($dir)) {
            return;
        }
        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $current): bool => $current->getPathname() !== $skip
        );
        foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
    };
    $walk($root . '/tools');
    foreach (AF_ROOT_DIRS as $dir) {
        $walk($root . '/' . $dir);
    }
    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);
    return $files;
}

function af_fingerprint(string $root): string
{
    $parts = [];
    foreach (af_index_inputs($root) as $file) {
        $stat = @stat($file);
        $parts[] = $file . '|' . ($stat['mtime'] ?? 0) . '|' . ($stat['size'] ?? 0);
    }
    return hash('sha256', implode("\n", $parts));
}

function af_cache_path(string $root): string
{
    return $root . '/' . AF_CACHE_RELATIVE;
}

/** @return ?array{fingerprint:string,index:array} */
function af_load_cache(string $root): ?array
{
    $path = af_cache_path($root);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || !isset($decoded['fingerprint'], $decoded['index'])) {
        return null;
    }
    return $decoded;
}

function af_save_cache(string $root, string $fingerprint, array $index): void
{
    $dir = dirname(af_cache_path($root));
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $tmp = af_cache_path($root) . '.' . getmypid() . '.tmp';
    $payload = json_encode(
        ['fingerprint' => $fingerprint, 'index' => $index],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if ($payload === false) {
        return;
    }
    if (@file_put_contents($tmp, $payload) !== false) {
        @rename($tmp, af_cache_path($root));
    } else {
        @unlink($tmp);
    }
}

/** @return array{leaves: list<string>, targets: array} */
function af_get_index(string $root, bool $rebuild): array
{
    $fingerprint = af_fingerprint($root);
    if (!$rebuild) {
        $cached = af_load_cache($root);
        if ($cached !== null && $cached['fingerprint'] === $fingerprint) {
            return $cached['index'];
        }
    }
    $index = af_build_index($root);
    af_save_cache($root, $fingerprint, $index);
    return $index;
}

// ------------------------------------------------------------ changed files

function af_unquote_status_field(string $field): string
{
    $field = trim($field);
    if (strlen($field) >= 2 && $field[0] === '"' && str_ends_with($field, '"')) {
        return stripcslashes(substr($field, 1, -1));
    }
    return $field;
}

/** @return list<string> repo-relative paths */
function af_git_diff_paths(string $root, string $base): array
{
    $result = af_exec(['git', '-C', $root, 'diff', '--name-only', $base . '...HEAD'], $root);
    if ($result['exit'] !== 0) {
        $result = af_exec(['git', '-C', $root, 'diff', '--name-only', $base], $root);
    }
    if ($result['exit'] !== 0) {
        fwrite(STDERR, "affected: NOTICE `git diff --name-only $base...HEAD` failed, "
            . "continuing with working-tree changes only\n");
        return [];
    }
    return array_map('af_normalize_path', af_lines($result['out']));
}

/** @return list<string> repo-relative paths, tracked + untracked working-tree changes */
function af_git_status_paths(string $root): array
{
    $result = af_exec(['git', '-C', $root, 'status', '--porcelain', '--untracked-files=all'], $root);
    if ($result['exit'] !== 0) {
        return [];
    }
    $paths = [];
    foreach (af_lines($result['out']) as $line) {
        if (strlen($line) < 4) {
            continue;
        }
        $rest = substr($line, 3);
        $fields = str_contains($rest, ' -> ') ? explode(' -> ', $rest, 2) : [$rest];
        foreach ($fields as $field) {
            $path = af_normalize_path(af_unquote_status_field($field));
            if ($path !== '') {
                $paths[] = $path;
            }
        }
    }
    return $paths;
}

// ------------------------------------------------------------------- main

function af_main(array $argv): int
{
    $root = af_repo_root();

    $base = 'origin/main';
    $explicitPaths = null;
    $all = false;
    $explain = false;
    $json = false;
    $quiet = false;
    $rebuild = false;

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--base=')) {
            $base = substr($arg, strlen('--base='));
        } elseif (str_starts_with($arg, '--paths=')) {
            $explicitPaths = substr($arg, strlen('--paths='));
        } elseif ($arg === '--all') {
            $all = true;
        } elseif ($arg === '--explain') {
            $explain = true;
        } elseif ($arg === '--json') {
            $json = true;
        } elseif ($arg === '--quiet') {
            $quiet = true;
        } elseif ($arg === '--rebuild-index') {
            $rebuild = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            af_usage('help');
        } else {
            af_usage("unknown option '$arg'");
        }
    }

    $index = af_get_index($root, $rebuild);
    $leaves = $index['leaves'];
    $targets = $index['targets'];

    if ($all) {
        $selected = $leaves;
        sort($selected, SORT_STRING);
        af_emit($selected, [], [], $json, $explain);
        return 0;
    }

    if ($explicitPaths !== null) {
        $changed = [];
        foreach (explode(',', $explicitPaths) as $raw) {
            $raw = af_normalize_path($raw);
            if ($raw !== '') {
                $changed[$raw] = true;
            }
        }
        $changed = array_keys($changed);
    } else {
        $changed = array_values(array_unique(array_merge(
            af_git_diff_paths($root, $base),
            af_git_status_paths($root)
        )));
    }
    sort($changed, SORT_STRING);

    $wholeCorpusFiles = ['Makefile', 'sandbox/tests/offline_diagnostics_guard.sh'];

    $selectedSet = [];
    /** @var list<array{target:string,file:string,why:string}> $explainRows */
    $explainRows = [];
    $uncovered = [];

    foreach ($changed as $file) {
        $isWholeCorpus = in_array($file, $wholeCorpusFiles, true) || str_starts_with($file, 'tools/');
        if ($isWholeCorpus) {
            foreach ($leaves as $target) {
                $selectedSet[$target] = true;
                $explainRows[] = ['target' => $target, 'file' => $file, 'why' => 'makefile'];
            }
            continue;
        }

        $hit = false;
        foreach ($targets as $target => $data) {
            if (isset($data['refs'][$file])) {
                $selectedSet[$target] = true;
                $explainRows[] = ['target' => $target, 'file' => $file, 'why' => $data['refs'][$file]];
                $hit = true;
                continue;
            }
            // Directory dependency: the suite loads this tree wholesale
            // (glob/scandir/::load) and can therefore never name $file
            // individually. Prefix match, so manifests/providers/x.php is
            // caught by a suite that only ever spelled `/manifests`.
            foreach ($data['dirs'] ?? [] as $dir) {
                if (str_starts_with($file, $dir . '/')) {
                    $selectedSet[$target] = true;
                    $explainRows[] = ['target' => $target, 'file' => $file, 'why' => 'dir:' . $dir];
                    $hit = true;
                    break;
                }
            }
        }
        if (!$hit) {
            $uncovered[] = $file;
        }
    }

    $selected = array_keys($selectedSet);
    sort($selected, SORT_STRING);

    if (!$quiet) {
        foreach ($uncovered as $file) {
            fwrite(STDERR, "no suite covers: $file\n");
        }
    }

    af_emit($selected, $explainRows, $changed, $json, $explain);
    return 0;
}

/** @param list<string> $selected
 * @param list<array{target:string,file:string,why:string}> $explainRows
 * @param list<string> $changed
 */
function af_emit(array $selected, array $explainRows, array $changed, bool $json, bool $explain): void
{
    if ($json) {
        usort($explainRows, static fn (array $a, array $b): int
            => $a['target'] <=> $b['target'] ?: $a['file'] <=> $b['file']);
        $payload = ['targets' => $selected, 'changed_files' => $changed];
        if ($explain) {
            $payload['explain'] = $explainRows;
        }
        fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return;
    }

    foreach ($selected as $target) {
        fwrite(STDOUT, $target . "\n");
    }

    if ($explain) {
        usort($explainRows, static fn (array $a, array $b): int
            => $a['target'] <=> $b['target'] ?: $a['file'] <=> $b['file']);
        foreach ($explainRows as $row) {
            fwrite(STDOUT, "{$row['target']} <- {$row['file']} (why: {$row['why']})\n");
        }
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    // $_SERVER['argv'] rather than the bare $argv superglobal: PHPStan
    // cannot prove register_argc_argv is on (it always is for the CLI SAPI
    // this script runs under), so the bare form is a permanent false
    // positive here; $_SERVER is a tracked superglobal and avoids it.
    $cliArgv = $_SERVER['argv'] ?? [];
    exit(af_main(is_array($cliArgv) ? array_map('strval', $cliArgv) : []));
}
