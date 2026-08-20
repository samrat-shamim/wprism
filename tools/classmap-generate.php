#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Generate the two committed classmaps that back the drop-in's additive
 * autoload fallback (agent/duo-classmap.php, cli/duo-classmap.php).
 *
 * Why this exists: the product has no autoloader by design — agent/duo.php
 * require_once's 98 files at load (duo.php:15-112), agent/src holds 232 files
 * across 18 directories that all declare the one flat `namespace Duo;` and
 * require their own dependencies, and cli/duo requires its 76 cli/src
 * files plus 7 agent/src ones. That contract stays (owner ruling D4: no
 * require line is deleted). What the maps add is a *fallback*: an autoloader
 * only ever fires for a class that is still undeclared at the moment it is
 * referenced, so with every existing require retained the map is dead weight
 * on the production path and only speaks when a partially-loaded context —
 * a sandbox suite that includes three files by hand, a new agent/src file
 * whose require chain a reviewer forgot — would otherwise fatal with
 * "Class not found".
 *
 * Constraints honoured here:
 *  - Generated, never hand-edited. The map is derived from what the source
 *    tree actually declares, so it cannot drift the way a hand-maintained
 *    list would; tests/Tooling/ClassmapTest.php re-derives it in memory and
 *    byte-compares, which is what makes "the committed file is current" a
 *    gate rather than a hope.
 *  - Deterministic bytes: FQCN-sorted with strcmp(), LF endings, single
 *    quotes, no timestamp, no absolute path, no host detail. Two runs on two
 *    machines must produce identical files or the byte-compare above is
 *    worthless: a map that differs per host turns `make release-gate` into a
 *    coin flip and puts a spurious diff in every unrelated PR.
 *  - Recursive scan of agent/src (18 directories) and cli/src (13). What is
 *    flat is the NAMESPACE, not the tree: all 232 agent/src files declare
 *    `namespace Duo;` and all 86 namespaced cli/src files declare
 *    `namespace Duo\Orchestrator;` whatever directory they sit in. So the
 *    walk finds files; it never derives a name from a path, and a future
 *    sub-namespaced subdirectory is picked up without touching this
 *    generator.
 *  - Every declaration in a file is mapped, not just the one whose name
 *    matches the filename: 19 agent/src files and 6 cli/src files declare
 *    more than one type, and some files have a declared type whose name
 *    differs from the filename (cli/src/Command/CommandOutput.php
 *    alone carries CommandOutput plus its exception types). A
 *    filename-derived map would silently miss all of them, which is exactly
 *    the class of bug an autoloader must not have.
 *  - Files that declare no type are skipped rather than treated as an error:
 *    cli/src/Refresh/RefreshPlanCompile.php is a `php <file> <args>` worker script
 *    with no namespace and no class, and must never be reachable by
 *    autoload (it executes on include).
 *  - Two files declaring the same FQCN is a refusal (exit 1), not a
 *    last-writer-wins merge. An autoloader that silently picks one of two
 *    definitions is a debugging trap.
 *
 * Exclusions are the one hand-maintained input, and each carries its reason
 * in CM_EXCLUSIONS below. A type is excluded only when *including* it would
 * change behaviour — i.e. its file has a top-level side effect that is safe
 * under the require that exists today but not under an arbitrary autoload
 * trigger. A tokenizer audit of all 271 files found exactly one such file
 * (see CM_EXCLUSIONS); every other top-level statement in agent/src is an
 * idempotent `class_exists(X::class, false)`-guarded require, which runs the
 * same way whether the file arrives by require or by autoload.
 *
 * Usage:
 *   php tools/classmap-generate.php            # write both maps (in place)
 *   php tools/classmap-generate.php --check    # byte-compare only; exit 1 on drift
 *   php tools/classmap-generate.php --print    # write nothing, dump to stdout
 */

/**
 * FQCN => reason it is deliberately absent from the generated map.
 *
 * Duo\Cli: agent/src/Command/Cli.php's last line is
 * `WP_CLI::add_command('duo', Cli::class);` — a top-level side effect. Today
 * that file is required from exactly one place, agent/duo.php's closing
 * `if (defined('WP_CLI') && WP_CLI)` block, so the call only ever runs with a
 * WP-CLI runtime present. Autoloading it would run that line from wherever
 * the first `Duo\Cli` reference happened to be: outside WP-CLI that is an
 * immediate fatal on an undefined WP_CLI class, and inside WP-CLI it is a
 * duplicate command registration. Excluding it keeps `Duo\Cli` resolvable
 * only through the require that already gates it.
 *
 * Duo\InitialStateBoundaryException: declared three times, each behind
 * `if (!class_exists(InitialStateBoundaryException::class, false))` —
 * agent/src/Kernel/DurableFilesystem.php:8, agent/src/Publication/PublicationJournal.php:7 and
 * agent/src/Publication/Publish.php:6. Whichever file loads first wins, deliberately
 * (Publish/PublicationJournal keep the historical declaration site that
 * Cli::PUBLIC_REFUSAL_CLASSES audits; DurableFilesystem keeps a copy so it is
 * independently loadable). A classmap has exactly one path per name, so any
 * entry would nominate one of three equally valid homes by scan order and
 * would drag that file's whole require chain in as a side effect of resolving
 * an exception class. Leaving it out preserves today's first-declaration-wins
 * behaviour exactly.
 *
 * @var array<string,string>
 */
const CM_EXCLUSIONS = [
    'Duo\\Cli' => 'agent/src/Command/Cli.php ends in a top-level WP_CLI::add_command() call; it must stay gated behind duo.php\'s WP_CLI require',
    'Duo\\InitialStateBoundaryException' => 'declared three times behind class_exists(..., false) guards (DurableFilesystem.php, PublicationJournal.php, Publish.php); no single file is its home',
];

/**
 * Generation targets: root directory (also the base the paths are relative
 * to) => [scanned subdirectory, generated file].
 *
 * @var array<string,array{0:string,1:string}>
 */
const CM_TARGETS = [
    'agent' => ['src', 'duo-classmap.php'],
    'cli' => ['src', 'duo-classmap.php'],
];

const CM_HEADER = 'GENERATED by tools/classmap-generate.php — do not edit; regenerate with: php tools/classmap-generate.php';

function cm_repo_root(): string
{
    return dirname(__DIR__);
}

/**
 * Every .php file under $dir, recursively, in a deterministic order.
 *
 * Sorted by full path with strcmp() rather than left to the filesystem's
 * readdir order, which differs between APFS and ext4 and would otherwise make
 * the duplicate-FQCN diagnostic (and any future first-wins behaviour)
 * host-dependent.
 *
 * @return list<string>
 */
function cm_php_files(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo || !$entry->isFile()) {
            continue;
        }
        if (strtolower($entry->getExtension()) !== 'php') {
            continue;
        }
        $files[] = $entry->getPathname();
    }
    usort($files, static fn (string $a, string $b): int => strcmp($a, $b));
    return $files;
}

/**
 * Fully-qualified names of every type declared at any position in $source.
 *
 * Written against the tokenizer rather than a regex because the tree does the
 * three things a regex gets wrong: multiple declarations per file, `::class`
 * constant fetches, and `new class(...)` anonymous classes. A declaration is
 * recognised as T_CLASS/T_INTERFACE/T_TRAIT/T_ENUM whose preceding
 * significant token is neither T_NEW (anonymous) nor T_DOUBLE_COLON
 * (`Foo::class`) and whose following significant token is a T_STRING name —
 * the name test alone also rejects `new readonly class {}`.
 *
 * @return list<string>
 */
function cm_declared_types(string $source): array
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    $skippable = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    $namespace = '';
    $declared = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }

        if ($token[0] === T_NAMESPACE) {
            $name = '';
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && in_array($next[0], $skippable, true)) {
                    continue;
                }
                if (is_array($next)
                    && in_array($next[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                    $name .= (string) $next[1];
                    continue;
                }
                break;
            }
            // A bare `namespace\Foo` relative reference produces no name here
            // and must not reset the file's namespace.
            if ($name !== '') {
                $namespace = trim($name, '\\');
            }
            continue;
        }

        if (!in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            continue;
        }

        $previous = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            $candidate = $tokens[$j];
            if (is_array($candidate) && in_array($candidate[0], $skippable, true)) {
                continue;
            }
            $previous = $candidate;
            break;
        }
        if (is_array($previous) && in_array($previous[0], [T_NEW, T_DOUBLE_COLON], true)) {
            continue;
        }

        $name = null;
        for ($j = $i + 1; $j < $count; $j++) {
            $next = $tokens[$j];
            if (is_array($next) && in_array($next[0], $skippable, true)) {
                continue;
            }
            if (is_array($next) && $next[0] === T_STRING) {
                $name = (string) $next[1];
            }
            break;
        }
        if ($name === null) {
            continue;
        }

        $declared[] = $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    return $declared;
}

/**
 * FQCN => path relative to $rootDir for every type declared under
 * $rootDir/$scanDir, minus CM_EXCLUSIONS.
 *
 * @return array<string,string>
 * @throws RuntimeException when two files declare the same FQCN
 */
function cm_build_map(string $rootDir, string $scanDir): array
{
    $map = [];
    $origin = [];
    foreach (cm_php_files($rootDir . '/' . $scanDir) as $file) {
        $relative = substr($file, strlen($rootDir) + 1);
        if (DIRECTORY_SEPARATOR !== '/') {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        }
        if (str_contains($relative, "'") || str_contains($relative, '\\')) {
            throw new RuntimeException("classmap-generate: unrepresentable path '$relative'");
        }
        foreach (cm_declared_types((string) file_get_contents($file)) as $fqcn) {
            if (isset(CM_EXCLUSIONS[$fqcn])) {
                continue;
            }
            if (isset($origin[$fqcn])) {
                throw new RuntimeException(
                    "classmap-generate: $fqcn is declared twice — {$origin[$fqcn]} and $relative"
                );
            }
            $origin[$fqcn] = $relative;
            $map[$fqcn] = $relative;
        }
    }
    uksort($map, static fn (string $a, string $b): int => strcmp($a, $b));
    return $map;
}

/**
 * The exact bytes of a generated map file.
 *
 * @param array<string,string> $map
 */
function cm_render(array $map, string $rootLabel, string $scanLabel): string
{
    $lines = [];
    $lines[] = '<?php';
    $lines[] = 'declare(strict_types=1);';
    $lines[] = '';
    $lines[] = '// ' . CM_HEADER;
    $lines[] = '//';
    $lines[] = '// Fully-qualified name => path relative to ' . $rootLabel . '/, for every type';
    $lines[] = '// declared under ' . $rootLabel . '/' . $scanLabel . '/. Consumed by an additive';
    $lines[] = '// spl_autoload_register() fallback: every require_once in the loader stays,';
    $lines[] = '// so an entry here is only ever reached for a class that is still undeclared';
    $lines[] = '// at the point it is referenced.';
    $lines[] = '';
    $lines[] = 'return [';
    foreach ($map as $fqcn => $path) {
        $lines[] = "    '" . str_replace('\\', '\\\\', $fqcn) . "' => '" . $path . "',";
    }
    $lines[] = '];';
    $lines[] = '';
    return implode("\n", $lines);
}

/**
 * Regenerate both maps in memory.
 *
 * @return array<string,array{path:string,relative:string,bytes:string,map:array<string,string>}>
 */
function cm_generate(string $repoRoot): array
{
    $out = [];
    foreach (CM_TARGETS as $root => [$scanDir, $fileName]) {
        $rootDir = $repoRoot . '/' . $root;
        $map = cm_build_map($rootDir, $scanDir);
        $out[$root] = [
            'path' => $rootDir . '/' . $fileName,
            'relative' => $root . '/' . $fileName,
            'bytes' => cm_render($map, $root, $scanDir),
            'map' => $map,
        ];
    }
    return $out;
}

/**
 * Human-readable drift between a committed map file and a freshly generated
 * one, as added / removed / moved FQCN lines.
 *
 * @param array<string,string> $committed
 * @param array<string,string> $fresh
 * @return list<string>
 */
function cm_map_differences(array $committed, array $fresh): array
{
    $lines = [];
    foreach ($fresh as $fqcn => $path) {
        if (!isset($committed[$fqcn])) {
            $lines[] = "  + $fqcn => $path";
        } elseif ($committed[$fqcn] !== $path) {
            $lines[] = "  ~ $fqcn: {$committed[$fqcn]} -> $path";
        }
    }
    foreach ($committed as $fqcn => $path) {
        if (!isset($fresh[$fqcn])) {
            $lines[] = "  - $fqcn => $path";
        }
    }
    sort($lines, SORT_STRING);
    return $lines;
}

/**
 * Parse a committed map file back into an array without executing it in this
 * process' scope. `require` on a generated data file is safe (it only returns
 * an array literal) but a syntactically broken or hand-mangled file must
 * produce a diagnostic, not a fatal, so the return value is type-checked.
 *
 * @return array<string,string>
 */
function cm_read_committed(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $value = require $path;
    if (!is_array($value)) {
        return [];
    }
    $map = [];
    foreach ($value as $fqcn => $relative) {
        if (is_string($fqcn) && is_string($relative)) {
            $map[$fqcn] = $relative;
        }
    }
    return $map;
}

/** @param list<string> $argv */
function cm_main(array $argv): int
{
    $mode = 'write';
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--check') {
            $mode = 'check';
        } elseif ($arg === '--print') {
            $mode = 'print';
        } elseif ($arg === '-h' || $arg === '--help') {
            fwrite(STDOUT, "usage: php tools/classmap-generate.php [--check | --print]\n");
            return 0;
        } else {
            fwrite(STDERR, "classmap-generate: unknown argument '$arg'\n");
            return 2;
        }
    }

    $repoRoot = cm_repo_root();
    try {
        $generated = cm_generate($repoRoot);
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        return 1;
    }

    if ($mode === 'print') {
        foreach ($generated as $target) {
            fwrite(STDOUT, "==> {$target['relative']}\n" . $target['bytes']);
        }
        return 0;
    }

    if ($mode === 'check') {
        $drift = false;
        foreach ($generated as $target) {
            $committed = is_file($target['path']) ? (string) file_get_contents($target['path']) : null;
            if ($committed === $target['bytes']) {
                fwrite(STDOUT, "ok    {$target['relative']} (" . count($target['map']) . " entries)\n");
                continue;
            }
            $drift = true;
            if ($committed === null) {
                fwrite(STDOUT, "STALE {$target['relative']}: missing\n");
                continue;
            }
            fwrite(STDOUT, "STALE {$target['relative']}:\n");
            $differences = cm_map_differences(cm_read_committed($target['path']), $target['map']);
            if ($differences === []) {
                fwrite(STDOUT, "  (same entries, different bytes — header or formatting drift)\n");
            }
            foreach ($differences as $line) {
                fwrite(STDOUT, $line . "\n");
            }
        }
        if ($drift) {
            fwrite(STDERR, "classmap-generate: regenerate with: php tools/classmap-generate.php\n");
            return 1;
        }
        return 0;
    }

    foreach ($generated as $target) {
        $committed = is_file($target['path']) ? (string) file_get_contents($target['path']) : null;
        if ($committed === $target['bytes']) {
            fwrite(STDOUT, "unchanged {$target['relative']} (" . count($target['map']) . " entries)\n");
            continue;
        }
        if (file_put_contents($target['path'], $target['bytes']) === false) {
            fwrite(STDERR, "classmap-generate: cannot write {$target['relative']}\n");
            return 1;
        }
        fwrite(STDOUT, "wrote     {$target['relative']} (" . count($target['map']) . " entries)\n");
    }
    return 0;
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    // $_SERVER['argv'] rather than the bare $argv superglobal: PHPStan cannot
    // prove register_argc_argv is on (it always is under the CLI SAPI this
    // script runs on), so the bare form is a permanent false positive.
    /** @var list<string> $cmArgv */
    $cmArgv = $_SERVER['argv'] ?? [];
    exit(cm_main($cmArgv));
}
