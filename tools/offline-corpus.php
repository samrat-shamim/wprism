#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Duo\Tooling;

/**
 * Derive the offline corpus from the tree and emit it as a generated Makefile
 * include (tools/offline-corpus.mk).
 *
 * WHY THIS EXISTS
 * ---------------
 * Until this landed, `regress-offline-corpus`'s prerequisite list was a
 * hand-written flat list and the two status lines carried hand-typed integers
 * (`regress-offline-all: 294 offline suites green` / `regress-offline-corpus:
 * 293`). Three measured consequences:
 *
 *   1. Every work package that adds a suite edits the same two integers on the
 *      same two lines, so any two of them conflict deterministically. The
 *      adapter-decentralization program alone adds ~30 suites.
 *   2. The integers drift. `git log -L` on the two lines shows both were
 *      introduced equal at 207/207 in 1ef9577c (DUO-3469) and are 294/293
 *      today: the corpus line has been one short for many commits, printing a
 *      count no suite ever produced. Nothing consumed it, so nothing caught
 *      it -- `regress_bundle_coverage.sh:201-213` checks the
 *      `regress-offline-all` line only.
 *   3. Wiring was a CONVENTION enforced after the fact. `regress_bundle_
 *      coverage.sh` detects an unwired suite; detection is strictly weaker
 *      than derivation, which makes one impossible.
 *
 * So the list and both counts are now a pure function of the tree, following
 * the discipline agent/duo-classmap.php and tools/capability-doc.php already
 * use here: generate, commit the output, and byte-compare it under
 * `make release-gate` so the artifact cannot drift from its source.
 *
 * WHAT IS DERIVED AND WHAT IS NOT
 * -------------------------------
 * Derived: the prerequisite list, and the one integer both status lines carry.
 * Fixed text: the diagnostics-guard invocation in `regress-offline-all`'s
 * recipe and the `code-half-unit` aggregator prerequisite (AGGREGATOR_PREREQS
 * states why it survives). Nothing else is authored in the generated file.
 *
 * THE THREE REFUSALS
 * ------------------
 * A generator that quietly skipped an input would be worse than the hand list
 * it replaces, because nobody re-reads a generated file. So:
 *
 *   R1 no target: a suite file exists under a class directory and no Makefile
 *      target runs it, or the target that carries its name runs a different
 *      file. Either way the file cannot be placed in the list, and a suite
 *      that runs nowhere is exactly what regress_bundle_coverage.sh exists to
 *      make impossible -- here it is impossible one step earlier.
 *   R2 missing file: a target's recipe names a sandbox/tests path that is not
 *      on disk. `make` would only discover this when that suite's turn came,
 *      minutes into the gate.
 *   R3 cannot exclude: there is no exclusion list, no skip flag and no
 *      opt-out marker in this file BY CONSTRUCTION -- the only input is the
 *      tree. Deleting a line from the committed include is therefore not an
 *      exclusion, it is drift, and `--check` refuses it by name. That
 *      property is what makes derivation stronger than the convention it
 *      replaces, and it is self-tested in
 *      sandbox/tests/offline/guards/regress_bundle_coverage.sh.
 *
 * SUITE VS HELPER
 * ---------------
 * Ported verbatim in behaviour from regress_bundle_coverage.sh:81-134 and
 * regress_suite_wiring.php's header, not re-decided: a file is a suite when
 * its basename claims a class (`certify_`/`grind_`/`regress_`/`spike_` +
 * .php/.sh) AND no other file's CODE runs it (`php <name>` / `bash <name>`,
 * full-line comments stripped first). `offline/adapter/regress_adapter_
 * sources.php` is a helper by that rule -- its `.sh` sibling runs it and they
 * would otherwise claim one target name between them. If this and those two
 * guards ever disagree, this one is wrong.
 *
 * Plain PHP, no composer, requirable without side effects (same
 * SCRIPT_FILENAME guard idiom as tools/offline.php and
 * recovery/rollback-control.php) so tests/Tooling/OfflineCorpusTest.php can
 * exercise the pure halves in-process.
 *
 * Usage:
 *   php tools/offline-corpus.php              # regenerate tools/offline-corpus.mk
 *   php tools/offline-corpus.php --check      # byte-compare only; exit 1 on drift
 *   php tools/offline-corpus.php --print      # write nothing, dump to stdout
 *   php tools/offline-corpus.php --root=DIR   # operate on another tree (self-tests)
 */
final class OfflineCorpus
{
    /** Repo-relative path of the generated include the Makefile pulls in. */
    public const INCLUDE_PATH = 'tools/offline-corpus.mk';

    /** The target whose prerequisite list this file owns. */
    public const CORPUS_TARGET = 'regress-offline-corpus';

    /** The guarded wrapper the merge gate actually invokes. */
    public const GATE_TARGET = 'regress-offline-all';

    /**
     * Non-suite prerequisites the corpus keeps.
     *
     * `code-half-unit` is an aggregator, not a leaf, and all 23 offline suites
     * it groups are listed directly below it by derivation. It is kept anyway
     * so the SET OF TARGETS make builds for `regress-offline-corpus` is
     * unchanged by the switch to derivation -- the `make -pn` before/after
     * diff is then additional edges only, never a target that stopped being
     * built -- and so the code-half grouping other targets share stays intact.
     */
    public const AGGREGATOR_PREREQS = ['code-half-unit'];

    /**
     * The five execution-class directories. Only CORPUS_CLASS contributes
     * rows; the other four are still enumerated because R1 applies to every
     * suite file in the estate, not only the offline half.
     */
    public const CLASS_DIRS = ['certify', 'grind', 'live', 'offline', 'spike'];

    /** The class directory whose suites the offline corpus runs. */
    public const CORPUS_CLASS = 'offline';

    /** The four basename prefixes with which a file claims to be a suite. */
    public const SUITE_PREFIXES = ['certify_', 'grind_', 'regress_', 'spike_'];

    /** A file make could plausibly be asked to run; a .json data file could not. */
    public const RUNNABLE_SUFFIXES = ['.php', '.sh'];

    /**
     * The Makefile's text with every `include` directive folded in, in the
     * order make would read them.
     *
     * Every consumer that reads the Makefile AS TEXT rather than through
     * `make -p` needs this the moment one rule lives in an include:
     * regress_bundle_coverage.sh, regress_suite_wiring.php,
     * tools/offline.php's leaf-count tripwire and the two PHPUnit tests that
     * read the status line all carry their own copy of this fold, the same
     * way they already carry their own Makefile parsers.
     *
     * Variables and globs in an include path are refused rather than
     * resolved: this fold is not make, and a path it cannot read literally
     * would silently drop a rule -- the failure mode this whole file exists
     * to end. `-include` is honoured (a missing optional include is skipped).
     */
    public static function makefileText(string $root, string $relative = 'Makefile', int $depth = 0): string
    {
        $path = $root . '/' . $relative;
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            return '';
        }
        if ($depth > 4) {
            fwrite(STDERR, "tools/offline-corpus.php: include nesting deeper than 4 at $relative\n");

            return $raw;
        }
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $out[] = $line;
            if (preg_match('/^(-?)include\s+(.+)$/', $line, $m) !== 1) {
                continue;
            }
            $optional = $m[1] === '-';
            foreach (preg_split('/\s+/', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $included) {
                if (preg_match('#^[A-Za-z0-9_./-]+$#', $included) !== 1) {
                    fwrite(STDERR, "tools/offline-corpus.php: include path '$included' is not a literal path\n");
                    continue;
                }
                if (!is_file($root . '/' . $included)) {
                    // This file's own output is the one include that may
                    // legitimately be absent -- on the first generation into a
                    // fresh tree there is nothing to read yet, and derivation
                    // never depends on it (it is compared against, not read
                    // from). Any other missing include means make itself would
                    // refuse, so it is announced.
                    if (!$optional && $included !== self::INCLUDE_PATH) {
                        fwrite(STDERR, "tools/offline-corpus.php: include '$included' does not exist\n");
                    }
                    continue;
                }
                $out[] = self::makefileText($root, $included, $depth + 1);
            }
        }

        return implode("\n", $out);
    }

    /**
     * Makefile text -> target => recipe lines, backslash continuations folded.
     *
     * Only recipes are needed here (the prerequisite graph is what this file
     * WRITES, never what it reads), so this is deliberately smaller than
     * regress_suite_wiring.php's parser and must not grow into a second copy
     * of it.
     *
     * @return array<string, list<string>>
     */
    public static function parseRecipes(string $text): array
    {
        $lines = explode("\n", $text);
        $count = count($lines);
        /** @var array<string, list<string>> $recipes */
        $recipes = [];
        $current = null;
        for ($i = 0; $i < $count; $i++) {
            $joined = $lines[$i];
            while (str_ends_with(rtrim($joined), '\\') && $i + 1 < $count) {
                $joined = rtrim(rtrim($joined), '\\') . ' ' . ltrim($lines[++$i]);
            }
            if ($joined !== '' && $joined[0] === "\t") {
                if ($current !== null) {
                    $recipes[$current][] = substr($joined, 1);
                }
                continue;
            }
            // A blank or comment line does not close a recipe -- make ignores
            // both inside one, and this Makefile interleaves them.
            if ($joined === '' || $joined[0] === '#') {
                continue;
            }
            if (preg_match('/^([^\s:=#][^:=]*):(?!=)(.*)$/', $joined, $m) !== 1) {
                $current = null;
                continue;
            }
            $current = trim($m[1]);
            $recipes[$current] ??= [];
        }

        return $recipes;
    }

    /**
     * Every file under the five class directories, as the repo-relative token
     * a recipe would have to write to name it.
     *
     * @return list<string>
     */
    public static function classFiles(string $root): array
    {
        $out = [];
        foreach (self::CLASS_DIRS as $class) {
            $base = $root . '/sandbox/tests/' . $class;
            if (!is_dir($base)) {
                continue;
            }
            $walk = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($walk as $entry) {
                if ($entry instanceof \SplFileInfo && $entry->isFile() && !$entry->isLink()) {
                    $out[] = 'sandbox/tests/' . $class
                        . substr(str_replace('\\', '/', $entry->getPathname()), strlen($base));
                }
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }

    /** Does this path's basename claim to be a suite make runs? */
    public static function claimsSuiteName(string $token): bool
    {
        $basename = basename($token);
        $runnable = false;
        foreach (self::RUNNABLE_SUFFIXES as $suffix) {
            $runnable = $runnable || str_ends_with($basename, $suffix);
        }
        if (!$runnable) {
            return false;
        }
        foreach (self::SUITE_PREFIXES as $prefix) {
            if (str_starts_with($basename, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which of $basenames some OTHER file's code runs, `php <name>` /
     * `bash <name>`.
     *
     * Ported from regress_bundle_coverage.sh:81-134 including its two
     * judgement calls: only full-line comments are stripped, and an optional
     * `<path>/` prefix is accepted. Erring toward "invoked" is safe here too
     * -- it demotes a file to helper rather than inventing a refusal.
     *
     * @param list<string> $basenames
     * @return array<string, true>
     */
    public static function invokedElsewhere(string $root, array $basenames): array
    {
        if ($basenames === []) {
            return [];
        }
        $tests = $root . '/sandbox/tests';
        if (!is_dir($tests)) {
            return [];
        }
        // Longest first, so a basename that is a strict string prefix of
        // another cannot capture a match that belongs to the longer name.
        usort($basenames, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $pattern = '#(?:php|bash)\s+(?:\S*/)?(' . implode('|', array_map(
            static fn (string $b): string => preg_quote($b, '#'),
            $basenames
        )) . ')\b#';

        $found = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tests, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($walk as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile() || $entry->isLink()) {
                continue;
            }
            $self = $entry->getFilename();
            $code = '';
            foreach (explode("\n", (string) file_get_contents($entry->getPathname())) as $line) {
                if (preg_match('/^\s*#/', $line) !== 1) {
                    $code .= $line . "\n";
                }
            }
            if (preg_match_all($pattern, $code, $m) === false) {
                continue;
            }
            foreach ($m[1] as $name) {
                if ($name !== $self) {
                    $found[$name] = true;
                }
            }
        }

        return $found;
    }

    /**
     * The target name a suite file's basename claims: regress_a_b.php ->
     * regress-a-b.
     *
     * Not what this file maps a suite to -- that is the recipe that actually
     * names the file (see derive()). It is the spelling tools/affected.php
     * assumes and regress_suite_wiring.php clause 3 enforces, kept here so a
     * refusal can say what the target WOULD have been called.
     */
    public static function targetFor(string $token): string
    {
        $stem = (string) preg_replace('/\.(?:php|sh)$/', '', basename($token));

        return strtr($stem, '_', '-');
    }

    /**
     * Every sandbox/tests path token in one recipe line, as WRITTEN.
     *
     * @return list<string>
     */
    public static function recipePaths(string $recipe): array
    {
        $out = [];
        if (preg_match_all('#sandbox/tests/[A-Za-z0-9_./-]+\.(?:php|sh)#', $recipe, $m) === false) {
            return $out;
        }
        foreach ($m[0] as $token) {
            $out[] = $token;
        }

        return $out;
    }

    /**
     * The whole derivation: which targets the corpus runs, and every refusal.
     *
     * Refusals are collected rather than thrown one at a time: an estate-wide
     * move produces a class of them at once, and reporting the first would
     * turn one fix into N runs.
     *
     * @return array{targets: list<string>, refusals: list<string>, helpers: int, suites: int}
     */
    public static function derive(string $root): array
    {
        $recipes = self::parseRecipes(self::makefileText($root));

        $claimants = [];
        foreach (self::classFiles($root) as $token) {
            if (self::claimsSuiteName($token)) {
                $claimants[$token] = basename($token);
            }
        }
        $invoked = self::invokedElsewhere($root, array_values(array_unique($claimants)));

        // path a recipe names -> the targets that name it. The mapping is
        // recipe-first, never name-first: `spike-a` runs spike_a_round_trip.sh
        // and `grind-r1a` runs grind_r1a_forms.sh, so a name-derived map would
        // need the same hand-maintained exception list
        // regress_suite_wiring.php carries (WIRING_NAME_EXCEPTIONS) -- and an
        // exception list in a derivation is an exclusion input, which R3 says
        // this file must not have.
        $namedBy = [];
        foreach ($recipes as $target => $recipeLines) {
            foreach ($recipeLines as $recipe) {
                foreach (self::recipePaths($recipe) as $path) {
                    $namedBy[$path][$target] = true;
                }
            }
        }

        $targets = [];
        $refusals = [];
        $helpers = 0;
        $suites = 0;
        foreach ($claimants as $token => $basename) {
            if (isset($invoked[$basename])) {
                $helpers++;
                continue;
            }
            $suites++;
            $owners = array_keys($namedBy[$token] ?? []);
            if ($owners === []) {
                $refusals[] = "R1 no target: no Makefile recipe runs $token (expected a target"
                    . " named '" . self::targetFor($token) . "') -- a suite file that runs nowhere"
                    . ' cannot be derived into the corpus';
                continue;
            }
            if (count($owners) > 1) {
                $refusals[] = "R1 ambiguous: $token is run by " . implode(' and ', $owners)
                    . ' -- a derived list cannot choose which one the corpus should name';
                continue;
            }
            if (self::classOf($token) !== self::CORPUS_CLASS) {
                continue;
            }
            $target = $owners[0];
            // R2 applies to the corpus's own targets: a recipe of a suite this
            // list makes the gate run must not name a path that is absent, or
            // `make` discovers it minutes into the run instead of here.
            foreach ($recipes[$target] as $recipe) {
                foreach (self::recipePaths($recipe) as $path) {
                    if (!is_file($root . '/' . $path)) {
                        $refusals[] = "R2 missing file: corpus target '$target' names $path,"
                            . ' which is not on disk';
                    }
                }
            }
            $targets[$target] = true;
        }
        $targets = array_keys($targets);
        sort($targets, SORT_STRING);
        $refusals = array_values(array_unique($refusals));
        sort($refusals, SORT_STRING);

        return ['targets' => $targets, 'refusals' => $refusals, 'helpers' => $helpers, 'suites' => $suites];
    }

    /** The class directory a `sandbox/tests/<class>/...` token sits in. */
    public static function classOf(string $token): string
    {
        $relative = substr($token, strlen('sandbox/tests/'));
        $slash = strpos($relative, '/');

        return $slash === false ? '' : substr($relative, 0, $slash);
    }

    /**
     * The generated include's bytes.
     *
     * One target per line so a suite arriving or leaving is a one-line diff a
     * reviewer can read, and so a merge between two work packages that each
     * add a suite resolves as two independent insertions instead of a
     * conflict on one 273-token line.
     *
     * @param list<string> $targets
     */
    public static function render(array $targets): string
    {
        $count = count($targets);
        $lines = [
            '# GENERATED by tools/offline-corpus.php -- do not edit by hand.',
            '# Regenerate with `php tools/offline-corpus.php`; `make release-gate` byte-compares it.',
            '#',
            '# This file IS the offline corpus: every suite file under',
            '# sandbox/tests/offline/ that no other suite runs appears below exactly once,',
            '# and the count both status lines carry is the length of that list. Neither is',
            '# hand-maintained any more, so a new suite needs its file and its Makefile leaf',
            '# target -- nothing else -- and a suite cannot be left out, since there is no',
            '# exclusion input to leave it out of (tools/offline-corpus.php states the three',
            '# refusals that hold that up).',
            '#',
            '# `code-half-unit` stays the first prerequisite even though all 23 offline',
            '# suites it groups are listed directly below: keeping it means the set of',
            '# targets `make regress-offline-corpus` builds did not change when the list',
            '# became derived.',
            '',
            self::GATE_TARGET . ':',
            "\t@bash sandbox/tests/offline_diagnostics_guard.sh \"\$(MAKE)\" --no-print-directory "
                . self::CORPUS_TARGET,
            "\t@echo \"" . self::GATE_TARGET . ': ' . $count . ' offline suites green"',
            '',
            self::CORPUS_TARGET . ': ' . implode(' ', self::AGGREGATOR_PREREQS) . ' \\',
        ];
        $last = $count - 1;
        foreach ($targets as $i => $target) {
            $lines[] = "\t" . $target . ($i === $last ? '' : ' \\');
        }
        $lines[] = "\t@echo \"" . self::CORPUS_TARGET . ': ' . $count . ' offline suites green"';
        $lines[] = '';

        return implode("\n", $lines);
    }
}

/**
 * Report which targets the committed include and the derived one disagree
 * about. A byte diff alone would say "drift" without saying of what, and the
 * whole point of R3 is that a REMOVED suite is named.
 *
 * @return list<string>
 */
function oc_drift_report(string $committed, string $generated): array
{
    $targetsOf = static function (string $text): array {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\t([a-z0-9-]+)( \\\\)?$/', $line, $m) === 1) {
                $out[$m[1]] = true;
            }
        }

        return $out;
    };
    $have = $targetsOf($committed);
    $want = $targetsOf($generated);
    $rows = [];
    foreach (array_keys(array_diff_key($want, $have)) as $target) {
        $rows[] = '  missing from ' . OfflineCorpus::INCLUDE_PATH . ": $target"
            . ' -- a suite on disk cannot be excluded from the corpus';
    }
    foreach (array_keys(array_diff_key($have, $want)) as $target) {
        $rows[] = '  present in ' . OfflineCorpus::INCLUDE_PATH . " but not derived: $target";
    }
    $countOf = static function (string $text): string {
        return preg_match('/' . OfflineCorpus::CORPUS_TARGET . ':\s+(\d+)\s+offline suites green/', $text, $m) === 1
            ? $m[1]
            : '?';
    };
    if ($countOf($committed) !== $countOf($generated)) {
        $rows[] = '  status count says ' . $countOf($committed) . ', derivation says ' . $countOf($generated);
    }
    if ($rows === []) {
        $rows[] = '  no target-level difference -- the generated header or layout changed';
    }

    return $rows;
}

/** @param list<string> $argv */
function oc_main(array $argv): int
{
    $root = dirname(__DIR__);
    $mode = 'write';
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--check') {
            $mode = 'check';
        } elseif ($arg === '--print') {
            $mode = 'print';
        } elseif (str_starts_with($arg, '--root=')) {
            $root = rtrim(substr($arg, strlen('--root=')), '/');
        } elseif ($arg === '-h' || $arg === '--help') {
            fwrite(STDOUT, "usage: php tools/offline-corpus.php [--check | --print] [--root=DIR]\n");

            return 0;
        } else {
            fwrite(STDERR, "tools/offline-corpus.php: unknown argument '$arg'\n");

            return 2;
        }
    }
    if (!is_file($root . '/Makefile') || !is_dir($root . '/sandbox/tests')) {
        fwrite(STDERR, "tools/offline-corpus.php: $root does not look like a repo root\n");

        return 2;
    }

    $derived = OfflineCorpus::derive($root);
    if ($derived['refusals'] !== []) {
        fwrite(STDERR, "tools/offline-corpus.php: REFUSED -- the tree cannot be derived as it stands\n");
        foreach ($derived['refusals'] as $refusal) {
            fwrite(STDERR, "  $refusal\n");
        }

        return 1;
    }
    if ($derived['targets'] === []) {
        fwrite(STDERR, "tools/offline-corpus.php: derived an EMPTY corpus -- refusing to write it\n");

        return 1;
    }
    $generated = OfflineCorpus::render($derived['targets']);
    $path = $root . '/' . OfflineCorpus::INCLUDE_PATH;

    if ($mode === 'print') {
        fwrite(STDOUT, $generated);

        return 0;
    }
    $committed = is_file($path) ? (string) file_get_contents($path) : null;
    if ($mode === 'check') {
        if ($committed === $generated) {
            fwrite(STDOUT, 'offline-corpus: ' . OfflineCorpus::INCLUDE_PATH . ' matches the tree ('
                . count($derived['targets']) . " offline suites)\n");

            return 0;
        }
        fwrite(STDERR, 'tools/offline-corpus.php: ' . OfflineCorpus::INCLUDE_PATH
            . " disagrees with the tree\n");
        foreach (oc_drift_report((string) $committed, $generated) as $row) {
            fwrite(STDERR, "$row\n");
        }
        fwrite(STDERR, "  remedy: php tools/offline-corpus.php\n");

        return 1;
    }
    if ($committed === $generated) {
        fwrite(STDOUT, 'offline-corpus: ' . OfflineCorpus::INCLUDE_PATH . ' already current ('
            . count($derived['targets']) . " offline suites)\n");

        return 0;
    }
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0o777, true)) {
        fwrite(STDERR, 'tools/offline-corpus.php: cannot create ' . dirname($path) . "\n");

        return 2;
    }
    if (file_put_contents($path, $generated) === false) {
        fwrite(STDERR, "tools/offline-corpus.php: cannot write $path\n");

        return 2;
    }
    fwrite(STDOUT, 'offline-corpus: wrote ' . OfflineCorpus::INCLUDE_PATH . ' ('
        . count($derived['targets']) . " offline suites)\n");

    return 0;
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    /** @var list<string> $ocArgv */
    $ocArgv = $_SERVER['argv'] ?? [];
    exit(oc_main($ocArgv));
}
