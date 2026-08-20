#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * move-suites.php — execute a hand-reviewed sandbox/tests restructure.
 *
 * The corpus is 359 entries flat in `sandbox/tests/`. The waves this tool
 * serves move ~300 of them into `sandbox/tests/{offline/<domain>/, live/,
 * grind/, certify/, spike/}` while `lib/`, `fixtures/`, `support/` and
 * `offline_diagnostics_guard.sh` stay at the root as shared substrate. The
 * moves themselves are a human decision recorded in `tools/suite-layout.json`
 * — a flat `{"old/repo/relative/path": "new/repo/relative/path"}` object. This
 * tool does not decide anything; it executes that mapping deterministically,
 * rewrites every path expression the move invalidates, and then PROVES the
 * result by resolving each rewritten expression against the file's real new
 * location and asserting the referent exists.
 *
 * That last part is why this tool exists rather than a shell one-liner. Only
 * ~239 of the corpus entries are offline suites the merge gate executes; the
 * `live/`, `grind/`, `certify/` and `spike/` files need a pair, a container
 * estate or an SSH target, so `make regress-offline-all` never runs them and a
 * broken `../../agent/src/...` in one of them is invisible until someone books
 * an estate. `--prove` is the only pre-merge check that covers them, so its
 * COMPLETENESS is the property this tool is judged on: every path expression a
 * rewrite class recognises is re-resolved, and anything path-bearing that no
 * class recognises lands in REVIEW rather than being silently skipped.
 *
 * ------------------------------------------------------------------ classes
 *
 * The rewrite classes, each measured against the tree at authoring time:
 *
 *  1. php-dirname-depth — `dirname(__DIR__, N)` / `dirname(__DIR__)` in a moved
 *     PHP file. A file that sinks D directories needs N+D to name the same
 *     directory. Measured flat: 163 `, 2`, 20 `, 4`, 6 `, 3`, 2 `,2` (no
 *     space), 1 `, 1`, 9 bare. Token-aware, so a `dirname(__DIR__, 2)` inside a
 *     nowdoc (a program written to a temp file, whose __DIR__ is the temp
 *     directory) is invisible to it — which is correct: rewriting those would
 *     corrupt an embedded program. `sandbox/tests/regress_provider_contract.php
 *     :1113` is exactly that shape.
 *  2. php-dir-literal — `__DIR__ . '<literal>'`, re-based for the new depth and
 *     FOLLOWING the map, so a reference to a sibling that also moved lands on
 *     its new home (`regress_init_contract.php:623` reads
 *     `__DIR__ . '/regress_duo_init.sh'`; the ratified map sends that script to
 *     `live/` and the reference becomes `'/../../live/regress_duo_init.sh'`).
 *     Two things follow from "following the map", and both are load-bearing:
 *
 *       * THE MAP DECIDES WHAT MOVES, not this file's prose. Only the four
 *         MS_SUBSTRATE entries are refused as destinations; every other corpus
 *         file moves if the map says so. `manifest_fixtures.php` and
 *         `certification_fixture.php` read like substrate and are NOT —
 *         the ratified layout sends them to `offline/policy/` and
 *         `offline/adapter/`, and a referrer's `'/manifest_fixtures.php'`
 *         correctly becomes `'/../policy/manifest_fixtures.php'`. A reference
 *         to something the map leaves at the root (`'/lib/check.php'`) becomes
 *         `'/../../lib/check.php'` by the same arithmetic, not by a special
 *         case.
 *       * The pass is NOT gated on the referring file having moved. A file that
 *         stays put still needs re-pointing when the file it NAMES moved, which
 *         a subset map makes the common case — see the comment in ms_compute().
 *
 *     Measured: 864 sites under sandbox/tests, 785 rewritten by the ratified
 *     335-entry map.
 *  3. shell-anchor — the `$(dirname "$0")` self-anchor, and the `ROOT=` /
 *     `REPO_ROOT=` / `SANDBOX=` assignment forms built on it. Policy, and the
 *     one judgement call in this tool:
 *
 *       * a self-anchor carrying a `/..` RUN (`cd "$(dirname "$0")/.."`,
 *         `ROOT="$(cd "$(dirname "$0")/../.." && pwd)"`) is a claim about a
 *         NAMED repo directory — `sandbox/`, the repo root. The run is extended
 *         by the depth delta so the claim still holds, and the script's own
 *         relative tokens then need no edit at all.
 *       * a BARE `cd "$(dirname "$0")"` says "work in my own directory" and
 *         makes no repo-path claim. It is left byte-identical: extending it to
 *         `/../..` would hard-code the pre-move layout into the new tree. Its
 *         relative tokens are re-based instead, by class 4.
 *
 *     Measured over the 122 flat shell files: 14 bare `cd`, 60 `cd …/..`,
 *     3 `cd …/../..`, 30 `ROOT=…/../..`, 13 `REPO_ROOT=…/../..`, 2 with no
 *     anchor at all. The split matters: resolving every `../`-leading token in
 *     the 14 bare-anchor scripts against `sandbox/tests` hits 68 of 68 real
 *     files, which is what proves the cwd model rather than assuming it.
 *  4. shell-relative — relative path tokens in a moved shell file, rewritten
 *     ONLY when class 3 determined the script's effective cwd follows the
 *     script (the bare-anchor case). In every other case the cwd is preserved
 *     (class 3 extended the run) or belongs to the caller (`make` runs these
 *     from the repo root), so the token already names the right path and
 *     editing it would BREAK it. Both the `../`-leading tokens and the BARE
 *     sibling ones (`support/wp-shortcode-stub.php`, `manifest_fixtures.php`) —
 *     13 of the 14 bare-anchor scripts carry at least one of the latter, and
 *     they name substrate that stays at the corpus root. A token is rewritten
 *     only when it resolves against the script's old directory; that existence
 *     test is what keeps a bare word or a `--flag=value` from being mistaken
 *     for a path.
 *  4a. shell-shellcheck — `# shellcheck source=<path>` in every moved shell
 *     file: shellcheck resolves that directive against the script FILE, never
 *     the cwd, so it moves with the file regardless of anchor. 12 measured.
 *  4c. shell-cwd-climb — a climb anchored on the CWD rather than on `$0`, in a
 *     script whose cwd follows it. Six sites, one idiom, two spellings:
 *     `(cd ../.. && find manifests …)` and `$repo = dirname(getcwd(), 2);`
 *     inside a nowdoc. The nowdoc is why this is a text rule — the embedded PHP
 *     is a string as far as the shell is concerned, so no tokenizer reaches it.
 *     All six broke before this class existed, and only running the suites
 *     showed it: the climb still lands on a directory that exists, just the
 *     wrong one, so --prove reported nothing.
 *  5. makefile-recipe — a recipe line carrying a mapped old path. The path stays
 *     a literal `php|bash sandbox/tests/…`: `tools/offline.php` reads recipes
 *     UNEXPANDED, and a `$(VAR)` in the path silently disables its serial-group
 *     collision detection. 324 Makefile lines name sandbox/tests today; the
 *     `$(VAR)`s already there are all environment prefixes, never the path.
 *  6. repo-literal — any occurrence of a mapped old path in the scan roots:
 *     `tests/Tooling/*.php`, `tools/*.php`, `docs/**`, `sandbox/**`, `agent/**`,
 *     `cli/**`, the repo-root files. 161 files outside sandbox/tests name a
 *     sandbox/tests path today, including 21 `"harness"` fields in
 *     `sandbox/tests/grind_ecommerce_developer.matrix.json` and the
 *     `HARNESS_REVISION` shasum list at `sandbox/tests/certify_ssh_rollback.sh
 *     :269`. A bare `sandbox/tests` with no file tail (43 mentions) is NOT
 *     touched — it still names the directory, which still exists.
 *  7. move-modules-key — `tools/codemod/move-modules.php`'s `mm_scanner_fixes()`
 *     is keyed by six flat suite paths and consulted with `isset()`, so a key
 *     that no longer names a file makes that tool silently vacuous rather than
 *     loud. Class 6 rewrites the keys as ordinary literals; this class ASSERTS
 *     afterwards that every mapped key was followed, and refuses if one was not.
 *
 * ------------------------------------------------------------------- REVIEW
 *
 * After the rewriters run, every line of every moved file that carries path
 * evidence the move can BREAK is accounted for: it was rewritten by a class, or
 * it goes to REVIEW with file:line. `ms_collect_review()` defines that evidence
 * per file type and states why each exclusion is safe rather than convenient.
 * `--plan` exits 1 while REVIEW is non-empty and `--apply` refuses for the same
 * reason, because a path expression nobody understood is exactly the thing that
 * ships broken into a `live/` file the offline gate never runs.
 *
 * UNPROVABLE is the separate, non-blocking bucket: a referent that legitimately
 * does not exist in a source checkout, so `--prove` cannot check it.
 * `ms_runtime_created_reason()` is the only thing that softens the prover, and
 * it is a classification carrying evidence rather than a list of paths that
 * were in the way: three runtime ROOTS (`sandbox/tmp/` and `sandbox/siterepo/`
 * from `.gitignore:3-4`, and `agent/duo/`, the deployed mu-plugin layout) and
 * two named files. Anything it does not match is a dangling reference and
 * fails, which is what stops a wrong `../` run from hiding in the same bucket.
 *
 * ------------------------------------------------ what --prove cannot catch
 *
 * Stated plainly, because the alternative is trusting it further than it goes.
 * `--prove` asserts that a resolved target EXISTS. It therefore cannot see an
 * error that lands on a different path which also exists:
 *
 *   - a lost trailing separator. `__DIR__ . '/fixtures/parity/' . $name` became
 *     `'/../../fixtures/parity'` in an early revision, and the truncated target
 *     is a real directory, so the prover passed and the suite failed. Both
 *     class 2 and class 4b now preserve the separator structurally.
 *   - a cwd-anchored climb that lands one level off. `dirname(getcwd(), 2)` in a
 *     script whose cwd moved names a directory that exists; it is just the
 *     wrong one. That is class 4c's whole reason for being.
 *
 * Both were found by RUNNING the moved corpus, not by proving it. A restructure
 * wave should do the same for the offline suites it can run, and read this list
 * before trusting a green --prove on the live/ and certify/ files it cannot.
 *
 * Usage:
 *   php tools/codemod/move-suites.php --plan [--map=tools/suite-layout.json]
 *   php tools/codemod/move-suites.php --apply
 *   php tools/codemod/move-suites.php --prove
 *   php tools/codemod/move-suites.php --help
 *
 * cwd-independent (every path derives from --root, default `dirname(__DIR__, 2)`)
 * and every function is requireable: the main guard only fires when this file is
 * the entry point, so tests/Tooling/MoveSuitesTest.php calls the pieces directly.
 */

// --------------------------------------------------------------- constants

/** The one directory this tool will move files inside of. */
const MS_TESTS_ROOT = 'sandbox/tests';

/**
 * Shared substrate: named by suites everywhere, so it stays at the corpus root
 * and a mapping that moves any of it is refused. `lib/` is the check.php /
 * wp_stubs.php / FakeWpdb.php skeleton AGENTS.md rule 5 points new suites at;
 * `offline_diagnostics_guard.sh` is invoked by the Makefile's own
 * `regress-offline-corpus` wrapper line.
 *
 * THIS LIST IS EXHAUSTIVE AND DELIBERATELY SHORT. It is the only place where
 * this tool overrides the map, so anything not named here moves when the map
 * says so — including files that read like substrate and are not.
 * `manifest_fixtures.php` (required by 12 suites) and `certification_fixture.php`
 * are the two that catch people out: the ratified layout moves them to
 * `offline/policy/` and `offline/adapter/`, and every referring
 * `__DIR__ . '/manifest_fixtures.php'` correctly follows to
 * `'/../policy/manifest_fixtures.php'`. Do not grow this list to "protect" a
 * file; a hand-reviewed map that moves it has already made that decision.
 */
const MS_SUBSTRATE = ['lib', 'fixtures', 'support', 'offline_diagnostics_guard.sh'];

/**
 * Repo-relative trees scanned for literal mentions of a mapped old path
 * (class 6). `manifests/` is absent by design: those bytes are adapter identity
 * (AGENTS.md rule 2) and a mention there is reported, never rewritten.
 */
const MS_SCAN_ROOTS = [
    '.github',
    'agent',
    'cli',
    'docs',
    'recovery',
    'sandbox',
    'scripts',
    'spec',
    'tests',
    'tools',
];

/** Repo-root files scanned alongside MS_SCAN_ROOTS. */
const MS_SCAN_FILES = [
    '.gitignore',
    '.php-cs-fixer.dist.php',
    'AGENTS.md',
    'CLAUDE.md',
    'DESIGN.md',
    'Makefile',
    'README.md',
    'composer.json',
    'phpstan-baseline.neon',
    'phpstan.neon.dist',
    'phpunit.xml.dist',
];

/**
 * Never scanned, never rewritten.
 *
 * This file and its self-test are excluded for the reason move-modules.php
 * excludes its own: they speak ABOUT the pre-move layout by construction. The
 * test asserts `git mv sandbox/tests/regress_x.php sandbox/tests/offline/…`,
 * and rewriting that assertion would make it a tautology.
 *
 * The layout map and its review record are the same category, and the map is
 * additionally this tool's own INPUT. Rewriting them mid-wave turns each
 * completed row into `"<new>": "<new>"` and each reviewed rationale into a
 * tautology — `suite-layout.review.md:153` states the one ratified rename as
 * `regress_fatal_mutations.sh -> live/regress_fatal_mutations_live.sh`, a
 * sentence with no meaning once the left side is rewritten. A wave moves a
 * subset, so both files must keep naming pre-move paths until the last one
 * lands; the prover's stale-mention check would otherwise force the corruption.
 */
const MS_SCAN_EXCLUDE = [
    '.git/',
    '.phpunit.cache/',
    'manifests/',
    'sandbox/tmp/',
    'tests/Tooling/MoveSuitesTest.php',
    'tools/codemod/move-suites.php',
    'tools/suite-layout.json',
    'tools/suite-layout.review.md',
    'vendor/',
];

/** Files larger than this are fixtures, not source. */
const MS_MAX_SCAN_BYTES = 4194304;

/**
 * `mm_scanner_fixes()` in tools/codemod/move-modules.php is keyed by these six
 * repo-relative paths and consulted with `isset($scannerFixes[$relative])`. A
 * key that stops naming a file on disk does not fail — the lookup just misses,
 * and that codemod's scanner-recursion pass becomes a no-op nobody notices.
 * Class 7 asserts the keys followed the move.
 */
const MS_MOVE_MODULES_FILE = 'tools/codemod/move-modules.php';

const MS_MOVE_MODULES_KEYS = [
    'sandbox/tests/regress_agent_src_requires.php',
    'sandbox/tests/regress_capture_refactor_boundaries.php',
    'sandbox/tests/regress_duo3316_contract.php',
    'sandbox/tests/regress_manifest_validate.php',
    'sandbox/tests/regress_manifest_validate.sh',
    'sandbox/tests/regress_woocommerce_contract.php',
];

/**
 * Referents that legitimately do not exist in a source checkout, each with the
 * reason it is exempt from the prover's existence assertion.
 *
 * This is the ONLY thing that softens `--prove`, so it is a classification with
 * evidence rather than a list of paths that were in the way. Every entry names
 * what creates the referent and where that is stated; anything not matched here
 * is a dangling reference and fails, which is what keeps a wrong `../` run —
 * the defect this tool exists to prevent — from hiding in the same bucket.
 *
 * Prefixes are runtime ROOTS: nothing under them is ever a source file.
 *
 * @return array<string,string> repo-relative prefix => reason
 */
function ms_runtime_created_prefixes(): array
{
    return [
        // `.gitignore:4`. AGENTS.md rule 3 sends all scratch here, so a suite
        // that writes a fixture tree writes it under this root and nothing in
        // it is ever committed (`git ls-files sandbox/tmp` is empty).
        'sandbox/tmp/' => 'gitignored scratch root (.gitignore:4); AGENTS.md rule 3 puts all scratch here',
        // `.gitignore:3`. `sandbox/bin/pair.sh` creates `siterepo/<pair><n>` per
        // pair; `git ls-files sandbox/siterepo` is empty.
        'sandbox/siterepo/' => 'gitignored pair estate (.gitignore:3); sandbox/bin/pair.sh creates siterepo/<pair><n> at runtime',
        // The DEPLOYED drop-in layout, not the source one. `agent/duo-loader.php`
        // is the mu-plugin shim that sits in `wp-content/mu-plugins/` on a
        // managed site and requires `duo/duo.php` BESIDE IT THERE; in this tree
        // the agent is `agent/duo.php` and `agent/duo/` has no tracked files.
        'agent/duo/' => 'the deployed mu-plugin layout: agent/duo-loader.php:7 requires duo/duo.php beside itself on a managed site, never in this tree',
    ];
}

/**
 * Exact referents, same contract as the prefixes above.
 *
 * @return array<string,string> repo-relative path => reason
 */
function ms_runtime_created_targets(): array
{
    return [
        // Built and torn down inside one run: the suite mkdir()s it at
        // :1066-1069 and unlinks it from a register_shutdown_function().
        'sandbox/unsafe1' => 'created and removed within a single run by regress_init_contract.php:1066-1069',
        // Operator-installed on a managed site. installed_config() asserts a
        // regular file with 0600 permissions before reading it, which is what
        // a secrets-grade operator drop-in looks like — never a repo file.
        'agent/scoped-promotion-control.json' => 'operator-installed on a managed site; ScopedPromotionAuthority::installed_config() asserts a 0600 regular file (agent/src/Promotion/ScopedPromotionAuthority.php:133-136)',
    ];
}

/**
 * The reason `$target` is exempt from the existence assertion, or null when it
 * is an ordinary referent that must exist.
 */
function ms_runtime_created_reason(string $target): ?string
{
    $exact = ms_runtime_created_targets();
    if (isset($exact[$target])) {
        return $exact[$target];
    }
    foreach (ms_runtime_created_prefixes() as $prefix => $reason) {
        if (str_starts_with($target . '/', $prefix)) {
            return $reason;
        }
    }
    return null;
}

// ------------------------------------------------------------ path helpers

/** Textual normalisation of `.`/`..` segments; never touches the filesystem. */
function ms_norm(string $path): string
{
    $absolute = str_starts_with($path, '/');
    $out = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if ($out !== [] && end($out) !== '..') {
                array_pop($out);
                continue;
            }
            if ($absolute) {
                continue;
            }
        }
        $out[] = $segment;
    }
    return ($absolute ? '/' : '') . implode('/', $out);
}

/** Relative path from directory `$from` to path `$to`; both repo-relative. */
function ms_relpath(string $from, string $to): string
{
    $fromParts = $from === '' ? [] : explode('/', trim($from, '/'));
    $toParts = $to === '' ? [] : explode('/', trim($to, '/'));
    while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
        array_shift($fromParts);
        array_shift($toParts);
    }
    return implode('/', array_merge(array_fill(0, count($fromParts), '..'), $toParts));
}

function ms_dirname_rel(string $relative): string
{
    $slash = strrpos($relative, '/');
    return $slash === false ? '' : substr($relative, 0, $slash);
}

/** How many directories contain this repo-relative path. */
function ms_depth(string $relative): int
{
    return substr_count($relative, '/');
}

function ms_is_excluded(string $relative): bool
{
    foreach (MS_SCAN_EXCLUDE as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return true;
        }
    }
    return false;
}

function ms_is_php_path(string $relative): bool
{
    return str_ends_with($relative, '.php');
}

function ms_is_shell_path(string $relative): bool
{
    return str_ends_with($relative, '.sh');
}

// ------------------------------------------------------------- map loading

/**
 * Read and validate the layout map.
 *
 * Every refusal is named, because a map is hand-reviewed and a silent
 * correction here would ship a layout nobody approved.
 *
 * @return array{moves: array<string,string>, already: array<string,string>, placement: array<string,string>}
 */
function ms_load_map(string $root, string $mapPath): array
{
    $raw = @file_get_contents($mapPath);
    if (!is_string($raw)) {
        throw new RuntimeException("move-suites: cannot read layout map $mapPath");
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("move-suites: $mapPath is not a JSON object of {old: new} paths");
    }
    if ($decoded !== [] && array_is_list($decoded)) {
        throw new RuntimeException("move-suites: $mapPath is a JSON list; it must be a flat {old: new} object");
    }

    $errors = [];
    $placement = [];
    $moves = [];
    $already = [];
    $byValue = [];
    $byBasename = [];
    $byStem = [];

    foreach ($decoded as $oldRaw => $newRaw) {
        $oldRel = ms_norm(str_replace('\\', '/', (string) $oldRaw));
        if (!is_string($newRaw)) {
            $errors[] = "$oldRel maps to a non-string value";
            continue;
        }
        $newRel = ms_norm(str_replace('\\', '/', $newRaw));

        if (!str_starts_with($oldRel . '/', MS_TESTS_ROOT . '/')) {
            $errors[] = "$oldRel is not under " . MS_TESTS_ROOT . ' — this tool restructures that directory only';
            continue;
        }
        if (!str_starts_with($newRel . '/', MS_TESTS_ROOT . '/')) {
            $errors[] = "$oldRel maps to $newRel, which leaves " . MS_TESTS_ROOT
                . ' — a suite that moves out of the corpus is a deletion, not a move';
            continue;
        }
        if ($newRel === $oldRel) {
            $errors[] = "$oldRel maps to itself; drop the entry rather than recording a no-op move";
            continue;
        }

        $substrate = ms_substrate_member($oldRel);
        if ($substrate !== null) {
            $errors[] = "$oldRel is shared substrate ($substrate) and must stay at the corpus root — "
                . 'every suite names it by a fixed relative path, and moving it re-points all of them at once';
            continue;
        }
        if (ms_substrate_member($newRel) !== null) {
            $errors[] = "$oldRel maps into shared substrate ($newRel); substrate holds no suites";
            continue;
        }

        $oldExists = is_file($root . '/' . $oldRel);
        $newExists = is_file($root . '/' . $newRel);
        if (!$oldExists && !$newExists) {
            $errors[] = "$oldRel exists at neither its old nor its new path";
            continue;
        }
        if ($oldExists && is_dir($root . '/' . $oldRel)) {
            $errors[] = "$oldRel is a directory; the map moves files one by one so every rewrite is reviewable";
            continue;
        }

        if (isset($byValue[$newRel])) {
            $errors[] = "$oldRel and {$byValue[$newRel]} both map to $newRel";
            continue;
        }
        $byValue[$newRel] = $oldRel;

        // Mirrors regress_bundle_coverage.sh:46-67. A Makefile target name is
        // derived from the basename alone (`regress_foo_bar.sh` <->
        // `regress-foo-bar`), so two files sharing a basename claim ONE target
        // between them and whichever loses the tie is unrunnable while looking
        // wired. Nesting is what makes the collision possible at all, which is
        // why the restructure has to refuse it rather than tie-break it.
        $basename = basename($newRel);
        if (isset($byBasename[$basename])) {
            $errors[] = "$newRel and {$byBasename[$basename]} share the basename '$basename' — "
                . 'target names come from the basename alone, so only one of them could ever be wired';
            continue;
        }
        $byBasename[$basename] = $newRel;

        // A `.php` unit suite and its `.sh` product-path sibling are one
        // subject; splitting them across directories makes the pair unfindable
        // and every cross-reference between them a `../../` climb.
        $stem = basename($newRel, '.' . pathinfo($newRel, PATHINFO_EXTENSION));
        $dir = ms_dirname_rel($newRel);
        if (isset($byStem[$stem]) && $byStem[$stem][0] !== $dir) {
            $errors[] = "$newRel and {$byStem[$stem][1]} share the stem '$stem' but land in different "
                . 'directories — the two halves of one subject move together';
            continue;
        }
        $byStem[$stem] = [$dir, $newRel];

        $placement[$oldRel] = $newRel;
        if ($oldExists) {
            $moves[$oldRel] = $newRel;
        } else {
            $already[$oldRel] = $newRel;
        }
    }

    // A destination already occupied by a file the map does not move would be
    // clobbered by `git mv`, so it is refused before anything is written.
    foreach ($moves as $oldRel => $newRel) {
        if (is_file($root . '/' . $newRel) && !isset($placement[$newRel])) {
            $errors[] = "$oldRel maps onto $newRel, which already exists and is not itself moving";
        }
    }

    if ($errors !== []) {
        sort($errors, SORT_STRING);
        throw new RuntimeException(
            "move-suites: the layout map is not executable:\n  - " . implode("\n  - ", $errors)
        );
    }

    ksort($placement, SORT_STRING);
    ksort($moves, SORT_STRING);
    ksort($already, SORT_STRING);

    return ['moves' => $moves, 'already' => $already, 'placement' => $placement];
}

/**
 * Join a resolved base directory with a literal into a repo-relative target.
 *
 * Two shapes are deliberately reduced rather than dropped:
 *   - a base of `''` (the repo root) must not produce a LEADING slash, which
 *     `ms_norm()` would then read as absolute;
 *   - a glob (`$root . '/manifests/*.json'`, `dirname(__DIR__, 2) .
 *     '/recovery/*.php'`) names a pattern, not a file. The containing directory
 *     is the strongest claim that still holds, and it is a real check: it is
 *     what catches a glob whose directory lost a level in the move.
 */
function ms_join_target(string $base, string $literal): string
{
    $joined = ms_norm(ltrim($base . '/' . ltrim($literal, '/'), '/'));
    if (preg_match('#[*?\[]#', $joined) !== 1) {
        return $joined;
    }
    $dir = ms_dirname_rel($joined);
    return preg_match('#[*?\[]#', $dir) === 1 ? '' : $dir;
}

/**
 * Does this line assert that a path is ABSENT?
 *
 * A negative existence check is a claim the prover must not invert.
 * `sandbox/tests/regress_woocommerce_product_lookups.php:93` reads
 * `check(!is_file($root . '/manifests/regenerators/woocommerce-product-lookups.php'),
 * 'and the retired regenerator file is gone, not left behind as a second copy
 * of the same adapter')` — the path is supposed to resolve to nothing, and
 * requiring it to exist would turn a passing suite into a dangling reference.
 * The path is still re-based like any other; only the existence assertion is
 * suspended.
 */
function ms_line_negates_existence(string $line): bool
{
    return preg_match('#![[:space:]]*(?:is_file|is_dir|file_exists|is_readable|is_link)[[:space:]]*\(#', $line) === 1
        || preg_match('#(?:is_file|is_dir|file_exists|is_readable)[^)]*\)[[:space:]]*===[[:space:]]*false#', $line) === 1;
}

/** Which substrate member (if any) a repo-relative corpus path belongs to. */
function ms_substrate_member(string $relative): ?string
{
    $inside = substr($relative, strlen(MS_TESTS_ROOT) + 1);
    foreach (MS_SUBSTRATE as $member) {
        if ($inside === $member || str_starts_with($inside, $member . '/')) {
            return $member;
        }
    }
    return null;
}

// -------------------------------------------------- class 1+2: moved PHP

/** Decode a single-quoted or escape-free double-quoted string token. */
function ms_decode_string(string $token): ?string
{
    $quote = $token[0] ?? '';
    if (($quote !== "'" && $quote !== '"') || substr($token, -1) !== $quote) {
        return null;
    }
    $body = substr($token, 1, -1);
    if ($quote === '"' && preg_match('/[$]|\\\\[^\\\\\'"]/', $body) === 1) {
        // Interpolation, or an escape whose decoding is not round-trippable
        // here. Reported for review rather than guessed at.
        return null;
    }
    if ($quote === "'") {
        return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
    }
    return str_replace(['\\\\', '\\"'], ['\\', '"'], $body);
}

/** Re-encode a path literal in the original token's quote style. */
function ms_encode_string(string $value, string $quote): string
{
    if ($quote === '"') {
        return '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value) . '"';
    }
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
}

/**
 * Re-base every `__DIR__`-anchored path expression in a moved PHP file.
 *
 * Token-aware on purpose. Three shapes in this corpus look like path
 * expressions in the raw text and must NOT be touched, and the tokenizer
 * excludes all three for free:
 *
 *   - source-text assertion needles: `str_contains($captureSource,
 *     "require_once __DIR__ . '/CapturePublicationWorkflow.php';")` — 12 sites
 *     asserting about AGENT source, where the `__DIR__` belongs to the file
 *     under test, not to the suite.
 *   - nowdoc-embedded programs: `file_put_contents($probe, <<<'PROBE' … )`
 *     whose `__DIR__` and `dirname(__DIR__, 1)` resolve inside the temp
 *     directory the program is written to.
 *   - `__DIR__` inside a comment.
 *
 * @param array<string,string> $placement oldRel => newRel for every mapped file
 * @return array{source:string, changes:list<array{line:int, from:string, to:string, kind:string}>,
 *               review:list<array{line:int, text:string, why:string}>,
 *               targets:list<array{line:int, target:string, expr:string}>}
 */
function ms_rewrite_php(string $source, string $oldRel, string $newRel, array $placement): array
{
    $tokens = token_get_all($source);
    $changes = [];
    $review = [];
    $targets = [];

    $oldDir = ms_dirname_rel($oldRel);
    $newDir = ms_dirname_rel($newRel);
    $delta = ms_depth($newRel) - ms_depth($oldRel);

    $count = count($tokens);
    $line = 1;

    $next = static function (int $i) use (&$tokens, $count): int {
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $j;
        }
        return $count;
    };
    $text = static fn(int $i): string => is_array($tokens[$i]) ? (string) $tokens[$i][1] : (string) $tokens[$i];
    $id = static fn(int $i): int|string => is_array($tokens[$i]) ? $tokens[$i][0] : $tokens[$i];

    // Resolved against the NEW directory: these variables carry the depth class
    // 1 is about to fix, so the literals joined onto them must be checked
    // against where the file will actually sit.
    $rootVars = ms_php_root_vars($tokens, $newDir, $delta);

    /** The literal (if any) concatenated onto the expression ending at $end. */
    $joined = static function (int $end) use ($next, $id, $text, $count): ?string {
        $dot = $next($end);
        if ($dot >= $count || $id($dot) !== '.') {
            return null;
        }
        $str = $next($dot);
        if ($str >= $count || $id($str) !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        return ms_decode_string($text($str));
    };

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        $line = is_array($token) ? (int) $token[2] : $line;

        // --- class 1: dirname(__DIR__ [, N]) ------------------------------
        if (is_array($token) && $token[0] === T_STRING && strtolower((string) $token[1]) === 'dirname') {
            $open = $next($i);
            if ($open >= $count || $id($open) !== '(') {
                continue;
            }
            $arg = $next($open);
            if ($arg >= $count || $id($arg) !== T_DIR) {
                continue;
            }
            $after = $next($arg);
            $depthIndex = null;
            $depthValue = 1;
            $close = null;
            if ($after < $count && $id($after) === ',') {
                $num = $next($after);
                if ($num < $count && $id($num) === T_LNUMBER && ctype_digit($text($num))) {
                    $depthIndex = $num;
                    $depthValue = (int) $text($num);
                    $maybeClose = $next($num);
                    if ($maybeClose < $count && $id($maybeClose) === ')') {
                        $close = $maybeClose;
                    }
                }
                if ($close === null) {
                    $review[] = [
                        'line' => $line,
                        'text' => ms_source_line($source, $line),
                        'why' => 'dirname(__DIR__, …) with a non-literal depth; no static rewrite can re-base it',
                    ];
                    continue;
                }
            } elseif ($after < $count && $id($after) === ')') {
                $close = $after;
            }
            if ($close === null) {
                continue;
            }

            $newDepth = $depthValue + $delta;
            $before = 'dirname(__DIR__' . ($depthIndex === null ? '' : ', ' . $depthValue) . ')';
            if ($newDepth < 1) {
                $review[] = [
                    'line' => $line,
                    'text' => ms_source_line($source, $line),
                    'why' => "$before would need depth $newDepth after the move — the file rose above the "
                        . 'directory this expression names',
                ];
                $i = $close;
                continue;
            }
            // `dirname(__DIR__, N) . '/agent/src/X.php'` inline — 95 sites in
            // the flat corpus. The joined form is the useful target: the bare
            // directory is an ancestor and would always exist.
            $base = ms_norm($newDir . '/' . str_repeat('../', $newDepth));
            $inline = $joined($close);
            $negated = ms_line_negates_existence(ms_source_line($source, $line));
            $targets[] = $inline === null
                ? ['line' => $line, 'target' => $base, 'expr' => 'dirname(__DIR__, ' . $newDepth . ')', 'negated' => $negated]
                : [
                    'line' => $line,
                    'target' => ms_join_target($base, $inline),
                    'expr' => 'dirname(__DIR__, ' . $newDepth . ") . '" . $inline . "'",
                    'negated' => $negated,
                ];
            if ($delta !== 0) {
                $afterText = 'dirname(__DIR__, ' . $newDepth . ')';
                if ($depthIndex === null) {
                    $tokens[$close] = ', ' . $newDepth . ')';
                } else {
                    $tokens[$depthIndex] = (string) $newDepth;
                }
                $changes[] = [
                    'line' => $line,
                    'from' => $before,
                    'to' => $afterText,
                    'kind' => 'php-dirname-depth',
                ];
            }
            $i = $close;
            continue;
        }

        // --- class 2: __DIR__ . '<literal>' -------------------------------
        if (is_array($token) && $token[0] === T_DIR) {
            $dot = $next($i);
            if ($dot >= $count || $id($dot) !== '.') {
                // A bare `__DIR__` still means "the directory this file lives
                // in" after the move, which is what every such site here wants
                // (`putenv('DUO_MANIFESTS_DIR=' . __DIR__)`). Nothing to do.
                continue;
            }
            $str = $next($dot);
            if ($str >= $count || $id($str) !== T_CONSTANT_ENCAPSED_STRING) {
                $review[] = [
                    'line' => $line,
                    'text' => ms_source_line($source, $line),
                    'why' => '__DIR__ is concatenated with a non-literal expression; re-base by hand',
                ];
                continue;
            }
            $raw = $text($str);
            $literal = ms_decode_string($raw);
            if ($literal === null) {
                $review[] = [
                    'line' => $line,
                    'text' => ms_source_line($source, $line),
                    'why' => 'the path literal after __DIR__ interpolates; re-base by hand',
                ];
                continue;
            }

            $targetOld = ms_norm($oldDir . '/' . ltrim($literal, '/'));
            $targetNew = $placement[$targetOld] ?? $targetOld;
            $rebased = ms_relpath($newDir, $targetNew);
            // A trailing separator is load-bearing and ms_norm() drops it. Two
            // sites in the corpus join the literal with a variable —
            // `__DIR__ . '/fixtures/parity/' . $name . '.json'` and
            // `__DIR__ . '/../../manifests/' . $manifestName` — and losing the
            // slash silently produced `fixtures/paritycanonical-json-vectors
            // .json`. --prove could not catch it: the truncated path still
            // resolves to a directory that exists, so only running the suite
            // showed it. That is why the trailing separator is preserved
            // structurally here rather than checked for afterwards.
            $trailing = str_ends_with($literal, '/') && $rebased !== '' ? '/' : '';
            $newLiteral = (($literal !== '' && $literal[0] === '/') ? '/' . $rebased : $rebased) . $trailing;
            $targets[] = [
                'line' => $line,
                'target' => $targetNew,
                'expr' => '__DIR__ . ' . ms_encode_string($newLiteral, $raw[0]),
                'negated' => ms_line_negates_existence(ms_source_line($source, $line)),
            ];
            if ($newLiteral !== $literal) {
                $encoded = ms_encode_string($newLiteral, $raw[0]);
                $tokens[$str] = $encoded;
                $changes[] = [
                    'line' => $line,
                    'from' => '__DIR__ . ' . $raw,
                    'to' => '__DIR__ . ' . $encoded,
                    'kind' => 'php-dir-literal',
                ];
            }
            $i = $str;
            continue;
        }

        // --- prover-only: `$root . '/agent/src/X.php'` --------------------
        // No rewrite: the literal is repo-relative to a variable class 1
        // already fixed. 285 such sites in the flat corpus, and they are the
        // ones that fatal if the depth delta is wrong, so the prover resolves
        // every one of them.
        if (is_array($token) && $token[0] === T_VARIABLE) {
            $name = substr((string) $token[1], 1);
            if (!isset($rootVars[$name])) {
                continue;
            }
            $literal = $joined($i);
            if ($literal === null || $literal === '') {
                continue;
            }
            $targets[] = [
                'line' => $line,
                'target' => ms_join_target($rootVars[$name], $literal),
                'expr' => '$' . $name . " . '" . $literal . "'",
                'negated' => ms_line_negates_existence(ms_source_line($source, $line)),
            ];
            continue;
        }
    }

    $out = '';
    foreach ($tokens as $token) {
        $out .= is_array($token) ? (string) $token[1] : (string) $token;
    }

    return ['source' => $out, 'changes' => $changes, 'review' => $review, 'targets' => $targets];
}

function ms_source_line(string $source, int $line): string
{
    $lines = explode("\n", $source);
    return trim($lines[$line - 1] ?? '');
}

/**
 * The N in a `dirname(__DIR__[, N])` starting at significant index `$at`, or
 * null when the expression there is anything else.
 *
 * @param list<array{0:int,1:string,2:int}|string> $tokens
 * @param list<int> $significant indices of the non-whitespace, non-comment tokens
 */
function ms_dirname_depth_at(array $tokens, array $significant, int $at): ?int
{
    $tokenAt = static fn(int $s): array|string|null => isset($significant[$s]) ? $tokens[$significant[$s]] : null;

    $fn = $tokenAt($at);
    if (!is_array($fn) || $fn[0] !== T_STRING || strtolower((string) $fn[1]) !== 'dirname') {
        return null;
    }
    if ($tokenAt($at + 1) !== '(') {
        return null;
    }
    $arg = $tokenAt($at + 2);
    if (!is_array($arg) || $arg[0] !== T_DIR) {
        return null;
    }
    $after = $tokenAt($at + 3);
    if ($after === ')') {
        // `dirname(__DIR__)` — one level, spelled without the argument.
        return $tokenAt($at + 4) === ';' ? 1 : null;
    }
    if ($after !== ',') {
        return null;
    }
    $num = $tokenAt($at + 4);
    if (!is_array($num) || $num[0] !== T_LNUMBER || !ctype_digit((string) $num[1])) {
        return null;
    }
    if ($tokenAt($at + 5) !== ')' || $tokenAt($at + 6) !== ';') {
        // Anything other than a bare `… = dirname(__DIR__, N);` may be a
        // longer expression whose value is not that directory.
        return null;
    }
    return (int) $num[1];
}

/**
 * Every name that appears as a function/closure PARAMETER anywhere in the file.
 *
 * Used to disqualify it as a root variable: see the comment in
 * ms_php_root_vars(). Conservative by construction — one parameter of that name
 * anywhere is enough to disqualify it everywhere, because this scan cannot tell
 * which function body a use sits in.
 *
 * @param list<array{0:int,1:string,2:int}|string> $tokens
 * @return array<string,bool>
 */
function ms_php_parameter_names(array $tokens): array
{
    $out = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || ($t[0] !== T_FUNCTION && $t[0] !== T_FN)) {
            continue;
        }
        // Walk to the signature's opening paren, then to its match.
        $depth = 0;
        for ($j = $i + 1; $j < $count; $j++) {
            $tok = $tokens[$j];
            if ($tok === '(') {
                $depth++;
                continue;
            }
            if ($tok === ')') {
                if (--$depth === 0) {
                    $i = $j;
                    break;
                }
                continue;
            }
            if ($depth > 0 && is_array($tok) && $tok[0] === T_VARIABLE) {
                $out[substr((string) $tok[1], 1)] = true;
            }
            if ($depth === 0 && ($tok === '{' || $tok === ';')) {
                break;
            }
        }
    }
    return $out;
}

/**
 * Variables bound to `dirname(__DIR__[, N])`, resolved to the repo-relative
 * directory each one names once the file sits at `$dirRel`.
 *
 * This is where most of the prover's reach comes from. 285 sites in the flat
 * corpus join a repo-tree literal onto such a variable —
 * `require $root . '/agent/src/Kernel/Canon.php';` is the dominant shape, 62
 * files carry one — and none of them is a rewrite site: the literal is already
 * repo-relative and the variable is fixed by class 1. They are the sites that
 * BREAK when class 1 gets the depth delta wrong, so a prover that could not
 * resolve them would miss the very failure this restructure risks most.
 *
 * A variable assigned twice with different depths is dropped rather than
 * guessed at; a joined literal is only recorded when the variable resolved.
 *
 * `$delta` is class 1's depth adjustment and MUST be applied here: the tokens
 * still carry the pre-move depth at this point, so resolving them without it
 * lands every target one directory tree too low. That was a live defect —
 * `$root . '/agent/src/Kernel/Canon.php'` resolved to
 * `sandbox/tests/agent/src/Kernel/Canon.php` — and the prover is what caught
 * it, which is the argument for the prover in one line.
 *
 * @param list<array{0:int,1:string,2:int}|string> $tokens
 * @return array<string,string> variable name (no `$`) => repo-relative directory
 */
function ms_php_root_vars(array $tokens, string $dirRel, int $delta): array
{
    $count = count($tokens);
    $significant = [];
    foreach ($tokens as $i => $t) {
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $significant[] = $i;
    }

    $vars = [];
    // PHP variables are FUNCTION-scoped and this scan is FILE-scoped, so a name
    // bound to dirname(__DIR__, N) in one function must not be resolved in
    // another that takes the same name as an argument. Live instance:
    // cli/src/Adapter/AdapterDraft.php binds `$repo = dirname(__DIR__, 3)` in
    // boot() at :390 and separately declares `read_prior_manifest(string $repo,
    // …)` at :435, where `$repo` is a MANAGED SITE's repository. Without this,
    // `$repo . '/site.duo.json'` resolved against the duo repo root and was
    // reported as a dangling reference to a file that only exists on a site.
    $ambiguous = ms_php_parameter_names($tokens);
    $n = count($significant);
    for ($s = 0; $s + 4 < $n; $s++) {
        $v = $tokens[$significant[$s]];
        if (!is_array($v) || $v[0] !== T_VARIABLE) {
            continue;
        }
        if ($tokens[$significant[$s + 1]] !== '=') {
            continue;
        }
        // ANY assignment that is not the recognised dirname form poisons the
        // name, whichever order the two appear in.
        // `regress_actions_providers.php` binds `$root = dirname(__DIR__, 2)`
        // at :53 and then shadows it inside a helper with
        // `$root = sys_get_temp_dir() . '/duo_regress_…'` at :114; without
        // this, `$root . '/providers'` on the next line resolves against the
        // repo root and is reported as a dangling reference to a directory
        // that only ever exists in a temp tree.
        $name = substr((string) $v[1], 1);

        $fn = $tokens[$significant[$s + 2]];
        $depth = ms_dirname_depth_at($tokens, $significant, $s + 2);
        if ($depth === null) {
            $ambiguous[$name] = true;
            continue;
        }

        $target = ms_norm($dirRel . '/' . str_repeat('../', max($depth + $delta, 0)));
        if (isset($vars[$name]) && $vars[$name] !== $target) {
            $ambiguous[$name] = true;
            continue;
        }
        $vars[$name] = $target;
    }

    foreach (array_keys($ambiguous) as $name) {
        unset($vars[$name]);
    }
    return $vars;
}

// ------------------------------------------------ class 3+4: moved shell

/**
 * The self-anchor family. `$(dirname "$0")` and `$(dirname "${BASH_SOURCE[0]}")`
 * with an optional `/..` run, in the three spellings this corpus uses:
 * a bare `cd`, a `cd` of the whole `$( … && pwd )`, and a `VAR=` assignment.
 */
const MS_ANCHOR_PATTERN = '#\$\(\s*dirname\s+"(?:\$0|\$\{BASH_SOURCE\[0\]\})"\s*\)(?P<run>(?:/\.\.)*)#';

/**
 * Classify a moved shell script's effective working directory.
 *
 * Returns 'follows' when the cwd moves with the script (a bare `cd
 * "$(dirname "$0")"`), 'preserved' when class 3 keeps it naming the same repo
 * directory (any anchor carrying a `/..` run), and 'caller' when the script
 * never cds and therefore runs wherever `make` put it — the repo root.
 *
 * @return array{cwd:string, why:string}
 */
function ms_shell_cwd_kind(string $source): array
{
    // Only a top-level `cd` counts: an indented one is inside a function or a
    // subshell and does not set the script's own working directory.
    if (preg_match('#^cd\s+"?\$\(\s*dirname\s+"(?:\$0|\$\{BASH_SOURCE\[0\]\})"\s*\)(?P<run>(?:/\.\.)*)"?#m', $source, $m) === 1) {
        return $m['run'] === ''
            ? ['cwd' => 'follows', 'why' => 'bare `cd "$(dirname "$0")"` — the cwd is the script\'s own directory']
            : ['cwd' => 'preserved', 'why' => 'the self-anchor climbs ' . (int) (strlen($m['run']) / 3)
                . ' level(s); class 3 extends the run so the cwd names the same repo directory'];
    }
    if (preg_match('#^cd\s+"?\$\(\s*cd\s+"\$\(\s*dirname#m', $source) === 1) {
        return ['cwd' => 'preserved', 'why' => 'the self-anchor climbs through a `cd … && pwd` subshell; class 3 extends the run'];
    }
    if (preg_match('#^cd\s+"\$(?:\{)?[A-Z_]+#m', $source) === 1) {
        return ['cwd' => 'preserved', 'why' => 'the script cds to a variable class 3 keeps pointing at the same repo directory'];
    }
    return ['cwd' => 'caller', 'why' => 'no top-level cd; the cwd belongs to the caller (make runs these from the repo root)'];
}

/**
 * @return array{text:string, changes:list<array{line:int, from:string, to:string, kind:string}>,
 *               review:list<array{line:int, text:string, why:string}>,
 *               targets:list<array{line:int, target:string, expr:string}>,
 *               cwd:string}
 */
function ms_rewrite_shell(string $root, string $source, string $oldRel, string $newRel, array $placement): array
{
    $delta = ms_depth($newRel) - ms_depth($oldRel);
    $newDir = ms_dirname_rel($newRel);
    $oldDir = ms_dirname_rel($oldRel);
    $kind = ms_shell_cwd_kind($source);

    $changes = [];
    $review = [];
    $targets = [];
    $accounted = [];
    $lines = explode("\n", $source);

    foreach ($lines as $n => $lineText) {
        $rewritten = $lineText;

        // --- class 3: extend every `/..` run hanging off the self-anchor ---
        $rewritten = (string) preg_replace_callback(
            MS_ANCHOR_PATTERN,
            static function (array $m) use ($delta, $newDir, $n, &$targets, &$accounted): string {
                $run = (int) (strlen($m['run']) / 3);
                if ($run === 0) {
                    // Left byte-identical on purpose: a bare self-anchor makes
                    // no repo-path claim, and extending it would hard-code the
                    // pre-move layout into the new tree. Recorded as ACCOUNTED
                    // so the review scan reads it as a decision rather than as
                    // a shape nobody looked at.
                    $targets[] = ['line' => $n + 1, 'target' => $newDir, 'expr' => trim($m[0]), 'negated' => false];
                    $accounted[$n + 1] = true;
                    return $m[0];
                }
                $newRun = $run + $delta;
                $targets[] = [
                    'line' => $n + 1,
                    'target' => ms_norm($newDir . '/' . str_repeat('../', max($newRun, 0))),
                    'expr' => str_replace($m['run'], str_repeat('/..', max($newRun, 0)), $m[0]),
                    'negated' => false,
                ];
                if ($newRun < 1) {
                    return $m[0];
                }
                return str_replace($m['run'], str_repeat('/..', $newRun), $m[0]);
            },
            $rewritten
        );
        if ($rewritten !== $lineText) {
            $changes[] = ['line' => $n + 1, 'from' => trim($lineText), 'to' => trim($rewritten), 'kind' => 'shell-anchor'];
        }

        // A `"$SCRIPT_DIR/../.."` climb is the same claim spelled through a
        // variable the anchor already bound; it needs the same extension.
        $viaVar = (string) preg_replace_callback(
            '#(?P<open>"\$(?:\{)?(?:SCRIPT_DIR|HERE|TESTS_DIR)(?:\})?)(?P<run>(?:/\.\.)+)#',
            static function (array $m) use ($delta): string {
                $newRun = (int) (strlen($m['run']) / 3) + $delta;
                return $newRun < 1 ? $m[0] : $m['open'] . str_repeat('/..', $newRun);
            },
            $rewritten
        );
        if ($viaVar !== $rewritten) {
            $changes[] = ['line' => $n + 1, 'from' => trim($rewritten), 'to' => trim($viaVar), 'kind' => 'shell-anchor'];
            $rewritten = $viaVar;
        }

        // --- class 4a: `# shellcheck source=<path>` -----------------------
        // shellcheck resolves this against the script FILE, never the cwd, so
        // it follows the move whatever the anchor policy decided.
        $directive = (string) preg_replace_callback(
            '#(?P<open>\#\s*shellcheck\s+source=)(?P<path>\S+)#',
            static function (array $m) use ($oldDir, $newDir, $placement, $n, &$targets, &$accounted): string {
                $path = $m['path'];
                if (str_starts_with($path, '/')) {
                    // `source=/dev/null` — absolute, and not a repo path.
                    $accounted[$n + 1] = true;
                    return $m[0];
                }
                $targetOld = ms_norm($oldDir . '/' . $path);
                $targetNew = $placement[$targetOld] ?? $targetOld;
                $rebasedPath = ms_relpath($newDir, $targetNew);
                $targets[] = ['line' => $n + 1, 'target' => $targetNew, 'expr' => 'shellcheck source=' . $rebasedPath, 'negated' => false];
                return $m['open'] . $rebasedPath;
            },
            $rewritten
        );
        if ($directive !== $rewritten) {
            $changes[] = ['line' => $n + 1, 'from' => trim($rewritten), 'to' => trim($directive), 'kind' => 'shell-shellcheck'];
            $rewritten = $directive;
        }

        // --- class 4c: cwd-anchored climbs, only when the cwd follows -----
        // Six sites across five scripts, all one idiom — climb from the cwd to
        // the repo root — spelled two ways, and every one of them silently
        // broke before this class existed:
        //   (cd ../.. && find manifests -type f …)          4 sites
        //   $repo = dirname(getcwd(), 2);   inside a nowdoc  2 sites
        // The nowdoc one is why this is a text rule: the PHP program is a
        // string as far as the shell is concerned, so no tokenizer reaches it.
        // `cd` climbs are rewritten only when the `cd` is the first on its
        // line, so a `(cd "$TMP" && cd .. && …)` inside a fixture tree is left
        // for REVIEW rather than re-based against the repo.
        if ($kind['cwd'] === 'follows' && $delta !== 0) {
            $climbed = (string) preg_replace_callback(
                '#dirname\(getcwd\(\)(?:,\s*(?P<depth>\d+))?\)#',
                static function (array $m) use ($delta): string {
                    $depth = ($m['depth'] ?? '') === '' ? 1 : (int) $m['depth'];
                    return 'dirname(getcwd(), ' . ($depth + $delta) . ')';
                },
                $rewritten
            );
            if (!preg_match('#\bcd\b.*\bcd\b#', $rewritten)) {
                $climbed = (string) preg_replace_callback(
                    '#(?P<open>\bcd\s+)(?P<run>\.\.(?:/\.\.)*)(?![A-Za-z0-9_./-])#',
                    static fn(array $m): string => $m['open'] . '..' . str_repeat('/..', (int) (strlen($m['run']) / 3) + $delta),
                    $climbed
                );
            }
            if ($climbed !== $rewritten) {
                $changes[] = ['line' => $n + 1, 'from' => trim($rewritten), 'to' => trim($climbed), 'kind' => 'shell-cwd-climb'];
                $rewritten = $climbed;
            }
        }

        // --- class 4b: relative tokens, only when the cwd follows ---------
        // Both shapes, because both break. `../../agent/src/Policy/Policy.php`
        // is the obvious one; the quiet one is a BARE sibling token —
        // `support/wp-shortcode-stub.php`, `manifest_fixtures.php`,
        // `certification_fixture.php` — which resolves beside the script today
        // and will not after the move. 13 of the 14 cwd-follows scripts carry
        // at least one. Where such a token lands is the MAP's decision, never a
        // list in this file: `support/` stays at the corpus root so the token
        // climbs to `../../support/…`, while the ratified layout moves
        // `certification_fixture.php` into `offline/adapter/` and the same
        // arithmetic sends the token there instead. Only MS_SUBSTRATE is
        // refused as a destination; nothing else here is privileged.
        //
        // A token is rewritten only when it RESOLVES against the script's old
        // directory. That existence test is the whole safety argument: a bare
        // word in prose or a `--flag=value` cannot be mistaken for a path,
        // because no file answers to it. A path-shaped token that does not
        // resolve is left alone and surfaces in REVIEW.
        if ($kind['cwd'] === 'follows') {
            $tokens = (string) preg_replace_callback(
                '#(?<![A-Za-z0-9_/.$"\'-])(?P<path>(?:\.\./)*[A-Za-z0-9_][A-Za-z0-9_./-]*)#',
                static function (array $m) use ($root, $oldDir, $newDir, $placement, $n, &$targets): string {
                    $path = $m['path'];
                    if (!str_contains($path, '/') && !preg_match('#\.(?:php|sh|json|md|txt|neon|yml)$#D', $path)) {
                        return $m[0];
                    }
                    // A trailing slash is load-bearing and ms_norm() drops it:
                    // `for provider in ../../manifests/providers/*.php` matches
                    // up to the slash (the glob is not a path character), so
                    // losing it produced `../../../../manifests/providers*.php`
                    // and a suite that reported a syntax error in a file that
                    // does not exist.
                    $slash = str_ends_with($path, '/') ? '/' : '';
                    $targetOld = ms_norm($oldDir . '/' . $path);
                    if (!isset($placement[$targetOld]) && !file_exists($root . '/' . $targetOld)) {
                        return $m[0];
                    }
                    $targetNew = $placement[$targetOld] ?? $targetOld;
                    $rebasedPath = ms_relpath($newDir, $targetNew) . $slash;
                    $targets[] = ['line' => $n + 1, 'target' => $targetNew, 'expr' => $rebasedPath, 'negated' => false];
                    return $rebasedPath;
                },
                $rewritten
            );
            if ($tokens !== $rewritten) {
                $changes[] = ['line' => $n + 1, 'from' => trim($rewritten), 'to' => trim($tokens), 'kind' => 'shell-relative'];
                $rewritten = $tokens;
            }
        }

        $lines[$n] = $rewritten;
    }

    return [
        'text' => implode("\n", $lines),
        'changes' => $changes,
        'review' => $review,
        'targets' => $targets,
        'accounted' => $accounted,
        'cwd' => $kind['cwd'],
    ];
}

// ------------------------------------------- class 5+6: literal mentions

/**
 * Rewrite every literal mention of a mapped old path.
 *
 * The lookbehind keeps a longer path that merely ENDS in a mapped one out of
 * the match, and the lookahead keeps `regress_foo.php` from matching inside
 * `regress_foo.php.bak`. Makefile recipes are covered by the same pass: the
 * path there is a plain literal (`bash sandbox/tests/foo.sh`), which is exactly
 * how it must stay — tools/offline.php reads recipes UNEXPANDED and a `$(VAR)`
 * in the path silently disables its serial-group collision detection.
 *
 * The prover targets this pass emits are deliberately narrow: ONLY paths the
 * map actually names. A wider net — every `sandbox/tests/…`-shaped literal —
 * was tried first and produced 36 false dangling references against the
 * untouched tree, because the corpus is full of paths that are not referents:
 * glob prefixes (`sandbox/tests/regress_` at regress_bundle_coverage.sh:242),
 * prose placeholders (`sandbox/tests/X.php` in docs/dev-setup.md:143 and
 * lib/check.php:38), and synthetic fixture names naming files in a temp tree
 * (tests/Tooling/AffectedTest.php:248, offline/guards/regress_suite_wiring.php).
 * A prover that cries wolf on those gets ignored, which costs more than the
 * coverage it buys. Narrowing to the map loses nothing real: a mapping VALUE
 * must exist after the move, a mapping KEY must NOT survive anywhere, and
 * between them those two claims are the whole of what this pass can get wrong.
 *
 * @param array<string,string> $placement oldRel => newRel
 * @return array{text:string, changes:list<array{line:int, from:string, to:string, kind:string}>,
 *               targets:list<array{line:int, target:string, expr:string}>}
 */
function ms_rewrite_literals(string $text, array $placement, string $relative): array
{
    $changes = [];
    $targets = [];
    $kind = $relative === 'Makefile' ? 'makefile-recipe' : 'repo-literal';

    $values = [];
    foreach ($placement as $newRel) {
        $values[$newRel] = true;
    }

    $lines = explode("\n", $text);
    foreach ($lines as $n => $lineText) {
        if (!str_contains($lineText, MS_TESTS_ROOT . '/')) {
            continue;
        }

        $replaced = $lineText;
        foreach ($placement as $oldRel => $newRel) {
            if (!str_contains($replaced, $oldRel)) {
                continue;
            }
            $pattern = '#(?<![A-Za-z0-9_.-])' . preg_quote($oldRel, '#') . '(?![A-Za-z0-9_./-])#';
            $next = preg_replace($pattern, str_replace('$', '\\$', $newRel), $replaced);
            if (is_string($next)) {
                $replaced = $next;
            }
        }
        if ($replaced !== $lineText) {
            $changes[] = ['line' => $n + 1, 'from' => trim($lineText), 'to' => trim($replaced), 'kind' => $kind];
            $lines[$n] = $replaced;
        }

        foreach (array_keys($values) as $newRel) {
            if (ms_literal_hits($lines[$n], $newRel) > 0) {
                $targets[] = [
                    'line' => $n + 1,
                    'target' => $newRel,
                    'expr' => $newRel,
                    'negated' => ms_line_negates_existence($lines[$n]),
                ];
            }
        }
    }

    return ['text' => implode("\n", $lines), 'changes' => $changes, 'targets' => $targets];
}

/**
 * Corpus paths a file MENTIONS, read without rewriting anything.
 *
 * `--prove` cannot reuse `ms_rewrite_literals()` for this: that function
 * applies the map in memory first, so on a tree where the move has not run it
 * "finds" the new path in text that still says the old one and reports the new
 * path as dangling. That is wrong in exactly the state the waves put the repo
 * in — after W2 the Makefile legitimately still names 266 flat W3 suites, and a
 * full-map prove flagged 728 of them.
 *
 * What is checkable whatever fraction of the map has been applied:
 *   - a mention of a NEW path must resolve: the text was rewritten, so the file
 *     had better be there;
 *   - a mention of an OLD path must resolve too. It does while that move is
 *     still pending, and does NOT once the move ran and a rewrite was missed —
 *     which is the real defect this catches.
 * Both reduce to one claim, every corpus path a file names exists, and that
 * claim holds before, during and after any subset of the map.
 *
 * @param array<string,string> $placement oldRel => newRel
 * @return list<array{line:int, target:string, expr:string, negated:bool}>
 */
function ms_literal_targets(string $root, string $text, array $placement): array
{
    $out = [];
    foreach (explode("\n", $text) as $n => $lineText) {
        if (!str_contains($lineText, MS_TESTS_ROOT . '/')) {
            continue;
        }
        $negated = ms_line_negates_existence($lineText);
        foreach ($placement as $oldRel => $newRel) {
            if (ms_literal_hits($lineText, $newRel) > 0) {
                $out[] = ['line' => $n + 1, 'target' => $newRel, 'expr' => $newRel, 'negated' => $negated];
            }
            if (ms_literal_hits($lineText, $oldRel) > 0 && !file_exists($root . '/' . $oldRel)) {
                $out[] = [
                    'line' => $n + 1,
                    'target' => $oldRel,
                    'expr' => "the pre-move path (now at $newRel)",
                    'negated' => $negated,
                ];
            }
        }
    }
    return $out;
}

/**
 * Occurrences of `$path` in `$line` as a whole path token.
 *
 * The lookbehind excludes NAME characters but deliberately not `/`. Leaving `/`
 * out of it was a live defect: `$root . '/sandbox/tests/regress_ssh_adopt.sh'`
 * — a repo-root variable joined with a full repo-relative corpus path, four
 * sites in the tree — was skipped by the rewrite and then caught by --prove as
 * a dangling reference after --apply. `mysandbox/tests/x.php` is still excluded,
 * because `s` is a name character.
 *
 * The trailing lookahead splits `.` from the other name characters because a
 * corpus path also ends prose sentences: `regress_mup_leak_audit.sh:50` names a
 * suite and then a full stop, and a single `(?![A-Za-z0-9_./-])` read that stop
 * as the start of a longer name and skipped the site — a whole mention class
 * invisible to --prove, found by hand in W2. `.` is rejected only when a name
 * character follows it, so `x.sh.bak` is still not a hit and `x.sh.` is.
 */
function ms_literal_hits(string $line, string $path): int
{
    $pattern = '#(?<![A-Za-z0-9_.-])' . preg_quote($path, '#') . '(?!\.[A-Za-z0-9_-])(?![A-Za-z0-9_/-])#';
    $n = preg_match_all($pattern, $line);
    return is_int($n) ? $n : 0;
}

// -------------------------------------------------------------- scanning

/** @return list<string> repo-relative text files eligible for the literal pass */
function ms_scan_targets(string $root): array
{
    $out = [];
    foreach (MS_SCAN_ROOTS as $dir) {
        $base = $root . '/' . $dir;
        if (!is_dir($base)) {
            continue;
        }
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($walk as $entry) {
            if (!$entry instanceof SplFileInfo || !$entry->isFile() || $entry->isLink()) {
                continue;
            }
            $relative = $dir . '/' . str_replace('\\', '/', substr($entry->getPathname(), strlen($base) + 1));
            if (ms_is_excluded($relative) || str_contains($relative, '/.git/')) {
                continue;
            }
            if ($entry->getSize() > MS_MAX_SCAN_BYTES) {
                continue;
            }
            $out[] = $relative;
        }
    }
    foreach (MS_SCAN_FILES as $file) {
        if (is_file($root . '/' . $file)) {
            $out[] = $file;
        }
    }
    sort($out, SORT_STRING);
    return $out;
}

function ms_is_text(string $bytes): bool
{
    return !str_contains(substr($bytes, 0, 8000), "\0");
}

// ---------------------------------------------------------------- REVIEW

/**
 * Path evidence a moved file of this type can carry that the move can BREAK.
 *
 * Type-aware, and that is the whole point. The first cut scanned every line for
 * any repo-tree name and produced 222 review items against a full 333-file
 * mapping, of which the overwhelming majority were prose inside strings:
 * `"php cli/duo --envs-file=<pair-envs> promote target"` in
 * grind_ecommerce_developer.matrix.json, `echo "… the REAL manifests/acf.json
 * …"`, `str_contains($e->getMessage(), 'spec/repo-format.md')`. A REVIEW bucket
 * that cries wolf gets skimmed, and skimming is exactly the failure it exists
 * to prevent — so the evidence is narrowed to what the move can actually
 * invalidate, per file type:
 *
 *   PHP    — nothing here. PHP review is TOKEN-level and comes out of
 *            `ms_rewrite_php()` itself: every `__DIR__` the parser sees that it
 *            could not resolve is reported there. A text scan would be strictly
 *            worse, because the three shapes it would flag are exactly the ones
 *            the tokenizer is right to skip — `__DIR__` in a comment, in a
 *            source-text assertion needle (`$literal = "require_once __DIR__ .
 *            '/StringOnly.php';"` at regress_agent_src_requires.php:338), and
 *            inside a nowdoc program. And a plain repo-relative literal in a
 *            moved suite is safe regardless: `make`/tools/offline.php run these
 *            from the repo root, a cwd the move does not touch. The four suites
 *            that `chdir()` chdir into a fixture directory they built, never
 *            into `__DIR__`.
 *   shell   — self-anchor spellings class 3 did not recognise, and relative
 *            tokens ONLY when class 3 determined the cwd follows the script.
 *            Otherwise the cwd is preserved or the caller's and the tokens
 *            still name the same paths.
 *   other   — nothing. A data or prose file resolves no path against a cwd; its
 *            mapped mentions are class 6's job and its stale keys are caught by
 *            the prover.
 *
 * @param array<int,bool> $handled lines a class changed or deliberately left
 * @return list<array{line:int, text:string, why:string}>
 */
function ms_collect_review(string $original, array $handled, string $relative, string $cwdKind): array
{
    if (!ms_is_shell_path($relative) || $cwdKind !== 'follows') {
        return [];
    }

    $out = [];
    foreach (explode("\n", $original) as $n => $lineText) {
        if (isset($handled[$n + 1]) || trim($lineText) === '') {
            continue;
        }
        $why = null;

        // `dirname "$0"` / `${BASH_SOURCE`, never a bare `$0`: in an embedded
        // awk program `$0` is the current record, and the corpus is full of
        // them (`printf "  UNGUARDED %s line %d: %s\n", base, FNR, $0`).
        if (preg_match('#(?:dirname|basename|readlink)\s+"?\$0|\$\{BASH_SOURCE#', $lineText) === 1) {
            $why = 'self-anchor expression in a spelling class 3 does not recognise';
        } elseif (preg_match('#\bgetcwd\(\)|\$\(\s*pwd|\bcd\s+\.\.#', $lineText) === 1) {
            // The cwd follows this script, so a climb anchored on it changes
            // meaning. Class 4c handles the two idioms measured in this tree;
            // anything else is exactly what this bucket exists for.
            $why = 'cwd-anchored climb in a script whose cwd follows it, in a spelling class 4c does not '
                . 'recognise — the cwd is deeper after the move, so the climb no longer lands where it did';
        } elseif (preg_match('#(?<![A-Za-z0-9_/.$"\'-])(?:\.\./)+[A-Za-z0-9_]#', $lineText) === 1) {
            $why = 'relative path token in a script whose cwd follows it, which class 4 could not resolve '
                . '(it names nothing on disk beside the script today)';
        }

        if ($why === null) {
            continue;
        }
        $out[] = ['line' => $n + 1, 'text' => trim($lineText), 'why' => $why];
    }
    return $out;
}

// ------------------------------------------------------------------ plan

/**
 * Compute every move and every rewrite. Pure: reads the tree, writes nothing.
 *
 * @return array{moves:array<string,string>, already:array<string,string>,
 *               placement:array<string,string>,
 *               files:array<string, array{moved:bool, new:string, text:?string, changes:list<array<string,mixed>>, cwd:string}>,
 *               review:list<array{file:string, line:int, text:string, why:string}>,
 *               unprovable:list<array{file:string, line:int, target:string, expr:string}>,
 *               manifests:array<string,int>}
 */
function ms_compute(string $root, array $map): array
{
    $placement = $map['placement'];
    $moves = $map['moves'];
    $already = $map['already'];

    $files = [];
    $review = [];
    $unprovable = [];

    // Destinations of moves --apply has not performed yet: absent on disk now,
    // present the moment the move runs.
    $pending = [];
    foreach ($moves as $newRel) {
        $pending[$newRel] = true;
        // …and every directory the move will create on its way there: a bare
        // self-anchor's target IS the file's new directory.
        for ($dir = ms_dirname_rel($newRel); $dir !== ''; $dir = ms_dirname_rel($dir)) {
            $pending[$dir] = true;
        }
    }

    foreach (ms_scan_targets($root) as $relative) {
        $bytes = @file_get_contents($root . '/' . $relative);
        if (!is_string($bytes) || !ms_is_text($bytes)) {
            continue;
        }

        $newRel = $placement[$relative] ?? $relative;
        $moved = isset($moves[$relative]) || isset($already[$relative]);
        $text = $bytes;
        $changes = [];
        $targets = [];
        $handled = [];
        $cwdKind = '';

        // Classes 5 and 6 run FIRST, so the depth passes below see literals
        // that already point at post-move paths and record the targets the
        // prover will actually resolve. They are text-level and the lookbehind
        // in ms_literal_hits() never matches a `__DIR__`-relative literal (it
        // is always preceded by `/`), so they cannot disturb the token passes.
        $lit = ms_rewrite_literals($text, $placement, $relative);
        $text = $lit['text'];
        $changes = array_merge($changes, $lit['changes']);
        $targets = array_merge($targets, $lit['targets']);

        // NOT gated on $moved. A file that stays put still needs re-pointing
        // when the file it NAMES moved, and a subset map makes that the common
        // case rather than the exotic one: wave W2 moves regress_duo_init.sh
        // into live/ while regress_init_contract.php — a W3 file — stays flat
        // holding `__DIR__ . '/regress_duo_init.sh'`. Class 6 cannot see that
        // literal (it is a bare sibling name, not a repo-relative path), so
        // gating this pass on $moved left two dangling references that only
        // --prove caught. With old == new the depth delta is zero, so the only
        // rewrites a non-moved file can receive are the ones the map earned.
        if (ms_is_php_path($relative)) {
            $r = ms_rewrite_php($text, $relative, $newRel, $placement);
            $text = $r['source'];
            $changes = array_merge($changes, $r['changes']);
            $targets = array_merge($targets, $r['targets']);
            if ($moved) {
                // Review is a claim about a file this wave is RESTRUCTURING.
                // An unresolvable __DIR__ in a file nobody is touching is not
                // this tool's business and would block every plan forever.
                foreach ($r['review'] as $v) {
                    $review[] = ['file' => $newRel] + $v;
                }
            }
        }
        if (ms_is_shell_path($relative)) {
            $r = ms_rewrite_shell($root, $text, $relative, $newRel, $placement);
            $text = $r['text'];
            $changes = array_merge($changes, $r['changes']);
            $targets = array_merge($targets, $r['targets']);
            $cwdKind = $r['cwd'];
            $handled = $r['accounted'];
            if ($moved) {
                foreach ($r['review'] as $v) {
                    $review[] = ['file' => $newRel] + $v;
                }
            }
        }

        // A referent that is absent BEFORE the move cannot be proven after it.
        // Reported, never used to weaken the prover — but a target that is a
        // PENDING destination is absent only because --apply has not run yet,
        // and listing those would bury the one entry that matters under ~890
        // of them.
        //
        // Scoped to files this run actually touches. The __DIR__ pass reads
        // every PHP file in the scan roots (it has to — a file that stays put
        // may name one that moved), and most of them carry runtime paths that
        // were never on disk: `$repo . '/state'` in cli/src/Adapter/AdapterDraft
        // .php:691, `$sandbox . '/tmp/…'` in the rehearse fixtures. Nine such
        // sites surfaced against an EMPTY map, which is exactly the kind of
        // noise that teaches a reader to skip this section.
        foreach ($targets as $t) {
            if ($t['target'] === '' || $t['negated'] || file_exists($root . '/' . $t['target'])) {
                continue;
            }
            // Pending destinations are absent only because --apply has not run.
            if (isset($pending[$t['target']])) {
                continue;
            }
            $reason = ms_runtime_created_reason($t['target']);
            if ($reason !== null) {
                continue;
            }
            $unprovable[] = ['file' => $moved ? $newRel : $relative] + $t;
        }

        if ($moved) {
            foreach ($changes as $c) {
                if ($c['line'] > 0) {
                    $handled[$c['line']] = true;
                }
            }
            foreach (ms_collect_review($bytes, $handled, $relative, $cwdKind) as $v) {
                $review[] = ['file' => $newRel] + $v;
            }
        }

        if ($changes === [] && !$moved) {
            continue;
        }
        $files[$relative] = [
            'moved' => $moved,
            'new' => $newRel,
            'text' => $text === $bytes ? null : $text,
            'changes' => $changes,
            'cwd' => $cwdKind,
        ];
    }

    // Class 7: move-modules.php's mm_scanner_fixes() keys must have followed
    // the move, or that codemod's scanner-recursion pass goes silently vacuous.
    foreach (ms_move_modules_gaps($root, $placement, $files) as $gap) {
        $review[] = $gap;
    }

    usort($review, static fn(array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);
    usort($unprovable, static fn(array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

    return [
        'moves' => $moves,
        'already' => $already,
        'placement' => $placement,
        'files' => $files,
        'review' => $review,
        'unprovable' => $unprovable,
        'manifests' => ms_manifest_mentions($root, $placement),
    ];
}

/**
 * @return list<array{file:string, line:int, text:string, why:string}>
 */
function ms_move_modules_gaps(string $root, array $placement, array $files): array
{
    if (!is_file($root . '/' . MS_MOVE_MODULES_FILE)) {
        return [];
    }
    $text = isset($files[MS_MOVE_MODULES_FILE]) && is_string($files[MS_MOVE_MODULES_FILE]['text'])
        ? $files[MS_MOVE_MODULES_FILE]['text']
        : (string) file_get_contents($root . '/' . MS_MOVE_MODULES_FILE);

    $out = [];
    foreach (MS_MOVE_MODULES_KEYS as $key) {
        if (!isset($placement[$key])) {
            continue;
        }
        if (str_contains($text, "'" . $placement[$key] . "'")) {
            continue;
        }
        $out[] = [
            'file' => MS_MOVE_MODULES_FILE,
            'line' => 0,
            'text' => "mm_scanner_fixes() key '$key'",
            'why' => "the map moves $key to {$placement[$key]}, but mm_scanner_fixes() does not carry the new "
                . 'key — that table is consulted with isset(), so a stale key makes move-modules.php\'s '
                . 'scanner-recursion pass a silent no-op instead of a loud failure',
        ];
    }
    return $out;
}

/** @return array<string,int> repo-relative manifests file => mention count */
function ms_manifest_mentions(string $root, array $placement): array
{
    $dir = $root . '/manifests';
    if (!is_dir($dir) || $placement === []) {
        return [];
    }
    $out = [];
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($walk as $entry) {
        if (!$entry instanceof SplFileInfo || !$entry->isFile() || $entry->getSize() > MS_MAX_SCAN_BYTES) {
            continue;
        }
        $bytes = @file_get_contents($entry->getPathname());
        if (!is_string($bytes) || !ms_is_text($bytes)) {
            continue;
        }
        $n = 0;
        foreach (array_keys($placement) as $oldRel) {
            $n += substr_count($bytes, $oldRel);
        }
        if ($n > 0) {
            $out['manifests/' . str_replace('\\', '/', substr($entry->getPathname(), strlen($dir) + 1))] = $n;
        }
    }
    ksort($out, SORT_STRING);
    return $out;
}

// ----------------------------------------------------------------- prove

/**
 * Post-apply verifier, also runnable standalone.
 *
 * For every file that is a mapping VALUE, extract every path expression the
 * rewrite classes know about, resolve it against the file's REAL location, and
 * assert the referent exists. Then resolve every repo-relative corpus literal
 * in the rewritten non-moved files the same way.
 *
 * This is the only pre-merge check that reaches the live/, grind/, certify/ and
 * spike/ files: `make regress-offline-all` executes the offline corpus and
 * none of those, so a `../../agent/src/…` that lost a level in one of them is
 * otherwise invisible until someone books an estate.
 *
 * @return array{checked:int, files:int, dangling:list<array{file:string, line:int, target:string, expr:string}>,
 *               unprovable:list<array{file:string, line:int, target:string, expr:string}>}
 */
function ms_prove(string $root, array $map): array
{
    $placement = $map['placement'];
    $values = [];
    foreach ($placement as $newRel) {
        $values[$newRel] = true;
    }

    $dangling = [];
    $unprovable = [];
    $checked = 0;
    $files = 0;

    foreach (ms_scan_targets($root) as $relative) {
        $isValue = isset($values[$relative]);
        $bytes = @file_get_contents($root . '/' . $relative);
        if (!is_string($bytes) || !ms_is_text($bytes)) {
            continue;
        }
        // A moved file is re-resolved against itself (old == new), so every
        // expression is extracted at its post-move depth without being edited.
        $targets = [];
        if ($isValue && ms_is_php_path($relative)) {
            $targets = array_merge($targets, ms_rewrite_php($bytes, $relative, $relative, [])['targets']);
        }
        if ($isValue && ms_is_shell_path($relative)) {
            $targets = array_merge($targets, ms_rewrite_shell($root, $bytes, $relative, $relative, [])['targets']);
        }
        $targets = array_merge($targets, ms_literal_targets($root, $bytes, $placement));
        if ($targets === []) {
            continue;
        }
        $files++;

        foreach ($targets as $t) {
            if ($t['target'] === '' || $t['negated']) {
                continue;
            }
            $checked++;
            // A target still carrying a leading `..` climbed out of the
            // repository. `file_exists()` would happily confirm the directory
            // above the checkout, so it is caught structurally instead — this
            // is what an over-extended `/..` run looks like.
            $escaped = str_starts_with($t['target'], '..');
            if (!$escaped && file_exists($root . '/' . $t['target'])) {
                continue;
            }
            $row = ['file' => $relative] + $t;
            if ($escaped) {
                $dangling[] = $row;
                continue;
            }
            $reason = ms_runtime_created_reason($t['target']);
            if ($reason !== null) {
                $unprovable[] = $row + ['reason' => $reason];
                continue;
            }
            $dangling[] = $row;
        }
    }

    usort($dangling, static fn(array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);
    usort($unprovable, static fn(array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

    return ['checked' => $checked, 'files' => $files, 'dangling' => $dangling, 'unprovable' => $unprovable];
}

// ----------------------------------------------------------------- apply

/**
 * @return array{moved:int, rewritten:int, lint_failures:list<string>, notes:list<string>, linted:int}
 */
function ms_apply(string $root, array $plan): array
{
    $notes = [];

    // 1. Write every rewritten file at its OLD path, so `git mv` still finds
    //    each one where the index expects it.
    $rewritten = 0;
    foreach ($plan['files'] as $relative => $info) {
        if ($info['text'] === null) {
            continue;
        }
        if (file_put_contents($root . '/' . $relative, $info['text']) === false) {
            throw new RuntimeException("move-suites: cannot write $relative");
        }
        $rewritten++;
    }

    // 2. Move.
    $moved = 0;
    $isGit = is_dir($root . '/.git');
    foreach ($plan['moves'] as $oldRel => $newRel) {
        $targetDir = $root . '/' . ms_dirname_rel($newRel);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new RuntimeException("move-suites: cannot create $targetDir");
        }
        $done = false;
        if ($isGit) {
            $output = [];
            $cmd = 'git -C ' . escapeshellarg($root) . ' mv -- '
                . escapeshellarg($oldRel) . ' ' . escapeshellarg($newRel) . ' 2>&1';
            exec($cmd, $output, $status);
            $done = $status === 0;
            if (!$done) {
                $notes[] = "git mv refused $oldRel (" . trim(implode(' ', $output)) . '); fell back to rename()';
            }
        }
        if (!$done && !rename($root . '/' . $oldRel, $root . '/' . $newRel)) {
            throw new RuntimeException("move-suites: cannot move $oldRel to $newRel");
        }
        $moved++;
    }

    // 3. Lint sweep at the post-move paths. A move that leaves a file
    //    unparsable is the one failure mode that must never report success.
    $lintFailures = [];
    $linted = 0;
    foreach ($plan['files'] as $relative => $info) {
        $final = $info['moved'] ? $info['new'] : $relative;
        if ($info['text'] === null && !$info['moved']) {
            continue;
        }
        $path = $root . '/' . $final;
        if (!is_file($path)) {
            continue;
        }
        if (ms_is_php_path($final)) {
            $cmd = 'php -l ' . escapeshellarg($path) . ' 2>&1';
        } elseif (ms_is_shell_path($final)) {
            $cmd = 'bash -n ' . escapeshellarg($path) . ' 2>&1';
        } else {
            continue;
        }
        $lintOut = [];
        exec($cmd, $lintOut, $lintStatus);
        $linted++;
        if ($lintStatus !== 0) {
            $lintFailures[] = $final . ': ' . trim(implode(' ', $lintOut));
        }
    }

    // 4. tools/affected.php caches a path-keyed index; the move invalidates it.
    $cache = $root . '/sandbox/tmp/affected-index.json';
    if (is_file($cache)) {
        unlink($cache);
        $notes[] = 'deleted stale sandbox/tmp/affected-index.json';
    }

    return [
        'moved' => $moved,
        'rewritten' => $rewritten,
        'lint_failures' => $lintFailures,
        'notes' => $notes,
        'linted' => $linted,
    ];
}

// ------------------------------------------------------------- reporting

function ms_print_plan($out, array $plan): void
{
    fwrite($out, "=== MOVES ===\n");
    $perDir = [];
    foreach ($plan['placement'] as $oldRel => $newRel) {
        $perDir[ms_dirname_rel($newRel)][] = [$oldRel, $newRel];
    }
    ksort($perDir, SORT_STRING);
    foreach ($perDir as $dir => $rows) {
        fwrite($out, sprintf("%s (%d file(s))\n", $dir, count($rows)));
        foreach ($rows as [$oldRel, $newRel]) {
            $verb = isset($plan['already'][$oldRel]) ? 'done  ' : 'git mv';
            fwrite($out, "    $verb $oldRel -> $newRel\n");
        }
    }
    fwrite($out, sprintf(
        "\n%d move(s) pending, %d already applied.\n\n",
        count($plan['moves']),
        count($plan['already'])
    ));

    fwrite($out, "=== REWRITES ===\n");
    foreach ($plan['files'] as $relative => $info) {
        if ($info['changes'] === []) {
            continue;
        }
        $header = $relative . ($info['moved'] && $info['new'] !== $relative ? ' -> ' . $info['new'] : '');
        $tags = [];
        if ($info['moved']) {
            $tags[] = 'moved';
        }
        if ($info['cwd'] !== '') {
            $tags[] = 'cwd=' . $info['cwd'];
        }
        fwrite($out, sprintf("--- %s%s\n", $header, $tags === [] ? '' : '  [' . implode(', ', $tags) . ']'));
        foreach ($info['changes'] as $change) {
            fwrite($out, sprintf("    L%-5d [%s]\n", $change['line'], $change['kind']));
            fwrite($out, sprintf("      - %s\n      + %s\n", $change['from'], $change['to']));
        }
    }

    fwrite($out, "\n=== REVIEW (no rewrite class recognises this path expression) ===\n");
    if ($plan['review'] === []) {
        fwrite($out, "none\n");
    }
    foreach ($plan['review'] as $item) {
        fwrite($out, sprintf("%s:%d  %s\n      %s\n", $item['file'], $item['line'], $item['why'], $item['text']));
    }

    fwrite($out, "\n=== UNPROVABLE (rewritten, but the referent is not on disk) ===\n");
    if ($plan['unprovable'] === []) {
        fwrite($out, "none\n");
    }
    foreach ($plan['unprovable'] as $item) {
        fwrite($out, sprintf(
            "%s:%d  %s -> %s (absent before the move too; --prove cannot check it)\n",
            $item['file'],
            $item['line'],
            $item['expr'],
            $item['target']
        ));
    }

    fwrite($out, "\n=== manifests/ (left untouched, digest-bound) ===\n");
    if ($plan['manifests'] === []) {
        fwrite($out, "none\n");
    }
    foreach ($plan['manifests'] as $relative => $n) {
        fwrite($out, sprintf("%-80s %d mention(s)\n", $relative, $n));
    }

    fwrite($out, "\n=== SUMMARY ===\n");
    ms_print_summary($out, $plan);
}

function ms_print_summary($out, array $plan): void
{
    $byKind = [];
    $rewrittenFiles = 0;
    foreach ($plan['files'] as $info) {
        if ($info['changes'] === []) {
            continue;
        }
        $rewrittenFiles++;
        foreach ($info['changes'] as $change) {
            $byKind[$change['kind']] = ($byKind[$change['kind']] ?? 0) + 1;
        }
    }
    ksort($byKind, SORT_STRING);
    fwrite($out, sprintf("  %d file(s) move, %d file(s) rewritten\n", count($plan['moves']), $rewrittenFiles));
    foreach ($byKind as $kind => $n) {
        fwrite($out, sprintf("    %-22s %d site(s)\n", $kind, $n));
    }
    fwrite($out, sprintf("  %d review item(s)\n", count($plan['review'])));
    fwrite($out, sprintf("  %d unprovable site(s)\n", count($plan['unprovable'])));
    fwrite($out, sprintf("  %d manifests/ file(s) left untouched\n", count($plan['manifests'])));
}

function ms_print_prove($out, array $result): void
{
    fwrite($out, "=== PROVE ===\n");
    fwrite($out, sprintf(
        "resolved %d path expression(s) across %d file(s)\n",
        $result['checked'],
        $result['files']
    ));

    fwrite($out, "\n=== DANGLING ===\n");
    if ($result['dangling'] === []) {
        fwrite($out, "none\n");
    }
    foreach ($result['dangling'] as $item) {
        fwrite($out, sprintf(
            "%s:%d  %s resolves to %s, which does not exist\n",
            $item['file'],
            $item['line'],
            $item['expr'],
            $item['target']
        ));
    }

    if ($result['unprovable'] !== []) {
        fwrite($out, "\n=== UNPROVABLE (classified; see ms_runtime_created_reason()) ===\n");
        foreach ($result['unprovable'] as $item) {
            fwrite($out, sprintf(
                "%s:%d  %s -> %s\n      exempt: %s\n",
                $item['file'],
                $item['line'],
                $item['expr'],
                $item['target'],
                $item['reason'] ?? 'runtime-created'
            ));
        }
    }
}

// ------------------------------------------------------------------- main

/** @return array{mode:string, root:string, map:string} */
function ms_parse_args(array $argv): array
{
    $options = ['mode' => '', 'root' => dirname(__DIR__, 2), 'map' => ''];

    foreach (array_slice($argv, 1) as $arg) {
        if (in_array($arg, ['--plan', '--apply', '--prove'], true)) {
            if ($options['mode'] !== '') {
                throw new RuntimeException('move-suites: pass exactly one of --plan / --apply / --prove');
            }
            $options['mode'] = substr($arg, 2);
            continue;
        }
        if (str_starts_with($arg, '--root=')) {
            $options['root'] = substr($arg, 7);
            continue;
        }
        if (str_starts_with($arg, '--map=')) {
            $options['map'] = substr($arg, 6);
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            $options['mode'] = 'help';
            continue;
        }
        throw new RuntimeException("move-suites: unknown argument '$arg'");
    }

    if ($options['mode'] === '') {
        throw new RuntimeException('move-suites: pass --plan, --apply or --prove (see --help)');
    }
    $resolvedRoot = realpath($options['root']);
    if ($resolvedRoot === false) {
        throw new RuntimeException("move-suites: --root does not exist: {$options['root']}");
    }
    $options['root'] = $resolvedRoot;
    if ($options['map'] === '') {
        $options['map'] = $resolvedRoot . '/tools/suite-layout.json';
    }
    return $options;
}

const MS_USAGE = <<<'TXT'
move-suites.php — execute a hand-reviewed sandbox/tests restructure and prove it.

  --plan            validate the map, print every move and every rewrite site
                    (file:line, class, before -> after), plus REVIEW and
                    UNPROVABLE. Writes nothing. Exit 1 if REVIEW is non-empty
                    or the map does not validate.
  --apply           perform the moves and rewrites. Refuses whenever --plan
                    would refuse. Writes nothing else.
  --prove           post-apply verifier, also runnable standalone. Exit 1 on
                    any dangling reference.
  --root=<dir>      repository root (default: this script's repo)
  --map=<file>      layout map (default: <root>/tools/suite-layout.json)

The map is a flat JSON object of repo-relative paths:
  {"sandbox/tests/regress_foo.php": "sandbox/tests/offline/policy/regress_foo.php"}

REFUSALS (the map is hand-reviewed; nothing here is corrected silently)
  a key that exists at neither its old nor its new path
  a key or value outside sandbox/tests, or a value that repeats
  two values sharing a basename — a Makefile target name comes from the
    basename alone, so only one of them could ever be wired
    (mirrors regress_bundle_coverage.sh:46-67)
  two values sharing a stem but landing in different directories — the .php
    and .sh halves of one subject move together
  any move of lib/, fixtures/, support/ or offline_diagnostics_guard.sh: the
    shared substrate stays at the corpus root. That list is EXHAUSTIVE — it is
    the only place this tool overrides the map. Files that read like substrate
    and are not (manifest_fixtures.php, certification_fixture.php) move when
    the map says so, and their referrers follow.
  a value that would land on an existing file the map does not move

SUBSET MAPS
  Any mapping file is accepted on its own terms: there is no totality check, no
  "every corpus file must be mapped", and class 6 rewrites only paths the GIVEN
  map names. Waves therefore compose — applying the live/grind/certify/spike
  subset and then the offline subset produces a byte-identical tree to applying
  the whole map at once, which is asserted in tests/Tooling/MoveSuitesTest.php.
  This is also why the __DIR__ pass is not gated on the referring file having
  moved: mid-split, a file that stays put may name one that did.

WHAT IS REWRITTEN
  php-dirname-depth  dirname(__DIR__[, N]) in a moved PHP file -> N + depth delta
  php-dir-literal    __DIR__ . '<literal>' in a moved PHP file, re-based and
                     following the map (a sibling suite that also moved)
  shell-anchor       the /.. run on a $(dirname "$0") self-anchor, and the
                     ROOT=/REPO_ROOT=/SCRIPT_DIR= forms built on it
  shell-shellcheck   # shellcheck source=<path> (resolved against the FILE)
  shell-relative     ../-leading AND bare sibling tokens, ONLY in a script
                     whose cwd follows it, and only where they resolve today
  shell-cwd-climb    (cd ../.. && …) and dirname(getcwd(), N), same condition
  makefile-recipe    a recipe line carrying a mapped old path, kept literal
  repo-literal       any mapped old path anywhere in the scan roots
  (class 7)          move-modules.php's mm_scanner_fixes() keys are asserted to
                     have followed the move, since that table is isset()-keyed

WHAT --prove CANNOT CATCH
  It asserts a resolved target EXISTS, so an error that lands on a different
  path which also exists is invisible to it: a lost trailing separator, and a
  cwd-anchored climb one level off. Both are handled structurally by the
  classes above because both were found by RUNNING the moved corpus, not by
  proving it. Run the offline suites you can; read this before trusting a green
  --prove on the live/ and certify/ files you cannot.

WHAT IS NOT REWRITTEN, AND WHY
  a bare `cd "$(dirname "$0")"` — it makes no repo-path claim, and extending it
    would hard-code the pre-move layout into the new tree
  ../-leading tokens in a script whose cwd class 3 preserved, or whose cwd
    belongs to the caller: they already name the right paths
  a bare `__DIR__` with no concatenation — still the file's own directory
  a bare `sandbox/tests` mention with no file tail — the directory still exists
  anything under manifests/ — those bytes are adapter identity (AGENTS.md rule
    2); mentions are reported and re-earned by re-certifying
  the trailing comments on a self-anchor line (`# -> sandbox/tests/`) are prose,
    not paths; the plan prints the line so a reviewer can retouch them

THE REVIEW CONTRACT
  Every line of every MOVED file carrying path evidence THE MOVE CAN BREAK is
  either rewritten by a class above or lands in REVIEW with file:line. That
  evidence is per file type, and each exclusion is a claim, not a shrug:
    PHP    __DIR__-anchored expressions only. A moved suite is run from the
           repo root, so a plain repo-relative literal resolves against a cwd
           the move does not touch.
    shell  self-anchors, and relative tokens only where the cwd follows the
           script. Elsewhere the cwd is preserved or the caller's.
    other  none — a data or prose file resolves nothing against a cwd; its
           mapped mentions are class 6's and its stale keys are the prover's.
  REVIEW is a refusal, not a warning: a path expression nobody understood is
  exactly what ships broken into a live/ or certify/ file, which
  `make regress-offline-all` never executes.

  UNPROVABLE is separate and non-blocking: a site a class DID rewrite whose
  referent is not on disk, so --prove cannot check it.

TXT;

function ms_main(array $argv): int
{
    try {
        $options = ms_parse_args($argv);
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n\n" . MS_USAGE);
        return 2;
    }

    if ($options['mode'] === 'help') {
        fwrite(STDOUT, MS_USAGE);
        return 0;
    }

    try {
        $map = ms_load_map($options['root'], $options['map']);
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        return 1;
    }

    if ($options['mode'] === 'prove') {
        $result = ms_prove($options['root'], $map);
        ms_print_prove(STDOUT, $result);
        return $result['dangling'] === [] ? 0 : 1;
    }

    try {
        $plan = ms_compute($options['root'], $map);
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        return 1;
    }

    if ($options['mode'] === 'plan') {
        ms_print_plan(STDOUT, $plan);
        if ($plan['review'] !== []) {
            fwrite(STDERR, sprintf(
                "\nmove-suites: %d path-bearing line(s) in moved files that no rewrite class recognises; "
                . "resolve them or teach the tool a class before applying\n",
                count($plan['review'])
            ));
            return 1;
        }
        return 0;
    }

    if ($plan['review'] !== []) {
        fwrite(STDERR, sprintf(
            "move-suites: refusing to apply — %d unrecognised path-bearing line(s); run --plan for the list\n",
            count($plan['review'])
        ));
        return 1;
    }

    try {
        $result = ms_apply($options['root'], $plan);
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        return 1;
    }

    fwrite(STDOUT, "=== APPLIED ===\n");
    ms_print_summary(STDOUT, $plan);
    fwrite(STDOUT, sprintf(
        "  %d file(s) moved, %d rewritten, %d linted\n",
        $result['moved'],
        $result['rewritten'],
        $result['linted']
    ));
    foreach ($result['notes'] as $note) {
        fwrite(STDOUT, "  note: $note\n");
    }

    if ($result['lint_failures'] !== []) {
        fwrite(STDERR, "\n=== LINT FAILURES ===\n");
        foreach ($result['lint_failures'] as $failure) {
            fwrite(STDERR, "  $failure\n");
        }
        return 1;
    }
    fwrite(STDOUT, "  php -l / bash -n: ok\n");

    $proof = ms_prove($options['root'], $map);
    fwrite(STDOUT, "\n");
    ms_print_prove(STDOUT, $proof);
    return $proof['dangling'] === [] ? 0 : 1;
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    // $_SERVER['argv'] rather than the bare $argv superglobal: PHPStan cannot
    // prove register_argc_argv is on (it always is under the CLI SAPI this
    // script runs on), so the bare form is a permanent false positive.
    /** @var list<string> $msArgv */
    $msArgv = $_SERVER['argv'] ?? [];
    exit(ms_main($msArgv));
}
