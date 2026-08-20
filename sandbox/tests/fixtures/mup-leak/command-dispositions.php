<?php
declare(strict_types=1);

/**
 * MUP §5.1 as a gate: every public `wp duo` command is either DRIVEN BY A
 * HOST VERB or NAMED IN `docs/guides/internals.md`. A command that is
 * neither is an undocumented command an operator can find and run, which is
 * exactly what the product spec's "normal operation never depends on
 * private identifiers, undocumented commands, or raw database surgery"
 * forbids — and §5.1's whole point is that naming a command is enough,
 * hiding it is not.
 *
 * Driven by sandbox/tests/regress_mup_leak_audit.sh.
 *
 * usage: php command-dispositions.php <repo-root> [--internals=<path>] [--format=json]
 *
 * `--internals` points the documented-internal half at a different copy of
 * the table; the suite's `--self-test` uses it to prove that removing a row
 * really does fail this check.
 *
 * Prints one row per command and exits 1 if any row is `undocumented`.
 *
 * ## How each side of the disjunction is decided
 *
 * **Host-driven** has two shapes in this tree, and both are read out of the
 * source rather than listed here:
 *
 *   1. a literal argv pair — `['duo', '<command>', …]` — built anywhere in
 *      `cli/duo` or `cli/src/**`. Whitespace is collapsed before the match
 *      because the pair is frequently split across lines
 *      (cli/src/Adapter/AdapterObservation.php:65-69).
 *   2. a verb `cli/duo` routes to `cmd_passthrough()` /
 *      `cmd_scoped_passthrough()`, which forward the host verb VERBATIM as
 *      the agent subcommand (`['duo', $verb, '--repo=…']`,
 *      cli/src/Command/PassthroughCommand.php:34). For those the argv pair
 *      never appears as a literal, so the dispatch arm is the evidence.
 *
 * A mention in a comment is not evidence and must not count: `verify-canonical`
 * is named in three docblocks under `cli/src` and is driven by nothing, which
 * is precisely why §5.1 lists it as internal. Comments are therefore stripped
 * with `token_get_all()` before the scan — a grep-based check would have
 * marked it host-driven and hidden the one command §2.4 had to work around.
 *
 * **Documented internal** means the command is cited in
 * `docs/guides/internals.md` as an inline `` `wp duo <command>` `` span AND
 * that line carries §5.1's literal sentence. The sentence is the contract, so
 * a row that lists a command without it does not count as documenting it.
 */

/**
 * §5.1's literal sentence, compared after backticks are stripped from both
 * sides. The proposal writes it as *"`duo` never needs this; …"*; a guide
 * that renders the same sentence without the code span around `duo` is the
 * same promise, and a gate that failed on that would be enforcing typography
 * rather than the contract. Every other word is exact.
 */
const CD_SENTENCE = 'duo never needs this; running it directly is outside the supported workflow.';

$root = $argv[1] ?? '';
if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "usage: command-dispositions.php <repo-root> [--format=json]\n");
    exit(2);
}
$options = array_slice($argv, 2);
$json = in_array('--format=json', $options, true);
$internalsOverride = null;
foreach ($options as $option) {
    if (str_starts_with($option, '--internals=')) {
        $internalsOverride = substr($option, strlen('--internals='));
    }
}

$agentCli = $root . '/agent/src/Command/Cli.php';
$hostCli = $root . '/cli/duo';
$internals = $internalsOverride ?? ($root . '/docs/guides/internals.md');
foreach ([$agentCli, $hostCli, $internals] as $required) {
    if (!is_file($required)) {
        fwrite(STDERR, "command-dispositions: missing source of truth: $required\n");
        exit(2);
    }
}

// ------------------------------------------------- 1. the public commands
// WP-CLI registers the whole class as `wp duo`, so every public method is a
// subcommand: underscores become hyphens unless `@subcommand` names it.
// Read the same way sandbox/tests/spike/check_guide_commands.sh reads it, so the
// two checks can never disagree about what the command surface is.
$agentSource = (string) file_get_contents($agentCli);
preg_match_all('/^[ \t]*public function ([a-z_]+)/m', $agentSource, $methods);
preg_match_all('/@subcommand ([a-z][a-z0-9-]*)/', $agentSource, $overrides);
$commands = array_map(
    static fn (string $method): string => str_replace('_', '-', $method),
    $methods[1]
);
$commands = array_values(array_unique(array_merge($commands, $overrides[1])));
sort($commands);
if ($commands === []) {
    fwrite(STDERR, "command-dispositions: no public wp duo commands were found\n");
    exit(2);
}

// -------------------------------------------------- 2. the host argv pairs
/** @return list<string> */
function cd_php_files(string $dir): array {
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

/** Source with every comment and docblock removed, whitespace collapsed. */
function cd_code_only(string $path): string {
    $code = '';
    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $code .= $token[1];
            continue;
        }
        $code .= $token;
    }

    return (string) preg_replace('/\s+/', '', $code);
}

$hostCode = '';
foreach (array_merge([$hostCli], cd_php_files($root . '/cli/src')) as $file) {
    $hostCode .= cd_code_only($file);
}

// Verbs cli/duo forwards verbatim to the agent through the passthrough
// helpers. Read off the dispatch arms, so adding a verb to that list is
// enough and nothing here needs editing.
$hostSource = (string) file_get_contents($hostCli);
$passthrough = [];
foreach (explode("\n", $hostSource) as $line) {
    if (!preg_match('/=>\s*cmd_(scoped_)?passthrough\(/', $line)) {
        continue;
    }
    preg_match_all("/'([a-z][a-z0-9-]*)'/", (string) strstr($line, '=>', true), $verbs);
    foreach ($verbs[1] as $verb) {
        $passthrough[$verb] = true;
    }
}

// ------------------------------------------- 3. the internals.md citations
$internalsLines = file($internals, FILE_IGNORE_NEW_LINES) ?: [];
$documented = [];
foreach ($internalsLines as $line) {
    if (!str_contains(str_replace('`', '', $line), CD_SENTENCE)) {
        continue;
    }
    preg_match_all('/`wp duo ([a-z][a-z0-9-]*)`/', $line, $cited);
    foreach ($cited[1] as $command) {
        $documented[$command] = true;
    }
}

// -------------------------------------------------------------- 4. verdict
$rows = [];
$undocumented = 0;
foreach ($commands as $command) {
    $literal = str_contains($hostCode, "'duo','$command'") || str_contains($hostCode, '"duo","' . $command . '"');
    if ($literal) {
        $disposition = 'host-driven (argv)';
    } elseif (isset($passthrough[$command])) {
        $disposition = 'host-driven (passthrough verb)';
    } elseif (isset($documented[$command])) {
        $disposition = 'documented internal';
    } else {
        $disposition = 'undocumented';
        $undocumented++;
    }
    $rows[] = ['command' => $command, 'disposition' => $disposition];
}

if ($json) {
    echo json_encode([
        'format' => 'duo-mup-command-disposition/v1',
        'rows' => $rows,
        'undocumented' => $undocumented,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    foreach ($rows as $row) {
        printf("%-28s %s\n", $row['command'], $row['disposition']);
    }
}

foreach ($rows as $row) {
    if ($row['disposition'] !== 'undocumented') {
        continue;
    }
    fwrite(STDERR, sprintf(
        "FAIL: `wp duo %s` is neither driven by a host verb nor named in docs/guides/internals.md (MUP §5.1)\n",
        $row['command']
    ));
}

// A citation for a command that is not a public subcommand is a stale row —
// it teaches an operator a command that does not exist, which is the same
// failure as an undocumented one with the sign flipped.
$stale = array_diff(array_keys($documented), $commands);
foreach ($stale as $command) {
    fwrite(STDERR, sprintf(
        "FAIL: docs/guides/internals.md documents `wp duo %s`, which agent/src/Command/Cli.php does not register\n",
        $command
    ));
    $undocumented++;
}

exit($undocumented === 0 ? 0 : 1);
