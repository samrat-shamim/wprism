<?php
declare(strict_types=1);

/**
 * The `wprism` verb surface is symmetric: every verb the Usage block publishes
 * is a verb `main()` dispatches, and every verb `main()` dispatches is a
 * verb the Usage block publishes.
 *
 * Driven by sandbox/tests/offline/assess-contract/regress_mup_leak_audit.sh
 * part (e).
 *
 * usage: php host-verb-symmetry.php <repo-root> [--usage=<path>] [--source=<path>] [--format=json]
 *
 * ## Why this belongs in the leak audit
 *
 * MUP §5.1's rule is about `wp wprism` commands and part (b) already gates it.
 * The HOST half has the same failure mode and had no gate: a verb published
 * in Usage but reachable from no dispatch arm is a documented command that
 * does not exist, and a verb dispatched but absent from Usage is an
 * undocumented command an operator can find — which is exactly the class the
 * product spec forbids ("normal operation never depends on private
 * identifiers, undocumented commands, or raw database surgery").
 *
 * Measured on the tree this landed against, the two sides already agree. So
 * this locks an invariant rather than fixing a bug, and it is written to fail
 * in BOTH directions rather than only the one that happens to be at risk
 * today.
 *
 * ## Where "dispatched" is read from — three places, not one
 *
 * `cli/wprism`'s `main()` is not a single table, and a check that assumed one
 * would report six false leaks:
 *
 *   1. `EnvironmentCommandPreflight::ENVIRONMENT_VERBS` — the closed list
 *      `requiresEnvironment()` reads. Every `<env>`-taking verb is here, and
 *      an unknown verb is refused against exactly this list
 *      (cli/wprism:1152-1155).
 *   2. the ENV-FREE arms — `if ($verb === '…')` in `main()` before that
 *      refusal (`envs`, `env`, `manifest-validate`, `adapter-draft`,
 *      `adapter`, `code-import`). These take no environment and never reach
 *      ENVIRONMENT_VERBS.
 *   3. `driver-capabilities`, dispatched by its own `if` AFTER the transport
 *      is resolved but BEFORE the capability report (cli/wprism:1194-1196),
 *      because it is the verb that reports what the driver can do.
 *
 * Comments are stripped with `token_get_all()` before the source is scanned,
 * exactly as `command-dispositions.php` does (:116-129) and for the same
 * reason: a verb named in a docblock must never count as dispatch evidence.
 *
 * Prints one row per verb and exits 1 if either side has a verb the other
 * lacks.
 */

$root = $argv[1] ?? '';
if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "usage: host-verb-symmetry.php <repo-root> [--usage=<path>] [--source=<path>]\n");
    exit(2);
}
$options = array_slice($argv, 2);
$json = in_array('--format=json', $options, true);
$usageOverride = null;
$sourceOverride = null;
foreach ($options as $option) {
    if (str_starts_with($option, '--usage=')) {
        $usageOverride = substr($option, strlen('--usage='));
    }
    if (str_starts_with($option, '--source=')) {
        $sourceOverride = substr($option, strlen('--source='));
    }
}

$hostCli = $sourceOverride ?? ($root . '/cli/wprism');
$preflight = $root . '/cli/src/Command/EnvironmentCommandPreflight.php';
foreach ([$hostCli, $preflight] as $required) {
    if (!is_file($required)) {
        fwrite(STDERR, "host-verb-symmetry: missing source of truth: $required\n");
        exit(2);
    }
}

/** Source with every comment and docblock removed. */
function hvs_code_only(string $path): string {
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

    return $code;
}

// ------------------------------------------------------------- the Usage side
// RENDERED, not parsed out of the heredoc: `wprism` with no arguments prints
// wprism_usage() (cli/wprism:1096-1098), so a Usage block that could not render is
// not evidence of anything. `--usage=<path>` lets the suite's self-test point
// this half at a mutated copy.
if ($usageOverride !== null) {
    $usage = (string) file_get_contents($usageOverride);
} else {
    $usage = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($hostCli) . ' 2>/dev/null');
}
if (trim($usage) === '') {
    fwrite(STDERR, "host-verb-symmetry: the Usage block rendered nothing\n");
    exit(2);
}
preg_match_all('/^  wprism ([a-z][a-z0-9-]*)(?:\s|$)/m', $usage, $usageMatches);
$published = array_values(array_unique($usageMatches[1]));
sort($published);
if ($published === []) {
    fwrite(STDERR, "host-verb-symmetry: no `wprism <verb>` lines were found in the Usage block\n");
    exit(2);
}

// --------------------------------------------------------- the dispatch side
$hostCode = hvs_code_only($hostCli);
$preflightCode = hvs_code_only($preflight);

// (1) the closed environment-verb list.
$envList = [];
if (preg_match('/const ENVIRONMENT_VERBS\s*=\s*\[(.*?)\];/s', $preflightCode, $listMatch) === 1) {
    preg_match_all("/'([a-z][a-z0-9-]*)'/", $listMatch[1], $envMatches);
    $envList = $envMatches[1];
}
if ($envList === []) {
    fwrite(STDERR, "host-verb-symmetry: EnvironmentCommandPreflight::ENVIRONMENT_VERBS could not be read\n");
    exit(2);
}

// (2) + (3) every `$verb === '…'` comparison left in main() after comments
// were stripped. That covers the env-free arms and the special-cased
// `driver-capabilities` in one read, because both are spelled the same way.
preg_match_all("/\\\$verb\s*===\s*'([a-z][a-z0-9-]*)'/", $hostCode, $armMatches);
$arms = $armMatches[1];
if ($arms === []) {
    fwrite(STDERR, "host-verb-symmetry: no `\$verb === '…'` dispatch arms were found in cli/wprism\n");
    exit(2);
}

$dispatched = array_values(array_unique(array_merge($envList, $arms)));
sort($dispatched);

// ------------------------------------------------------------------- compare
$publishedOnly = array_values(array_diff($published, $dispatched));
$dispatchedOnly = array_values(array_diff($dispatched, $published));

if ($json) {
    echo json_encode([
        'format' => 'wprism-host-verb-symmetry/v1',
        'published' => $published,
        'dispatched' => $dispatched,
        'published_not_dispatched' => $publishedOnly,
        'dispatched_not_published' => $dispatchedOnly,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
} else {
    foreach ($published as $verb) {
        printf("%-24s %s\n", $verb, in_array($verb, $dispatched, true) ? 'published + dispatched' : 'PUBLISHED, NOT DISPATCHED');
    }
    foreach ($dispatchedOnly as $verb) {
        printf("%-24s %s\n", $verb, 'DISPATCHED, NOT PUBLISHED');
    }
}

foreach ($publishedOnly as $verb) {
    fwrite(STDERR, "host-verb-symmetry: `wprism $verb` is published in the Usage block and dispatched by nothing\n");
}
foreach ($dispatchedOnly as $verb) {
    fwrite(STDERR, "host-verb-symmetry: `wprism $verb` is dispatched and published in no Usage line\n");
}

exit($publishedOnly === [] && $dispatchedOnly === [] ? 0 : 1);
