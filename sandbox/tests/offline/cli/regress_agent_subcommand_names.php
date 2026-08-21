<?php
/**
 * DUO-3517: the `wp duo` surface the host invokes is the surface the agent
 * registers, resolved the way WP-CLI resolves it.
 *
 * `Cli` is registered as one WP-CLI command class, so every public method is a
 * subcommand. WP-CLI names it from the method's `@subcommand` tag when one is
 * present and otherwise from the RAW method name — `CommandFactory::
 * create_subcommand()` takes the tag or `$reflection->name`, it never
 * hyphenates. `Cli::code_inventory()` shipped in #523 without a tag, so a real
 * WP-CLI registered `code_inventory` while `CodeClassifyCommand` and
 * `CodeResolveCommand` invoke `code-inventory`; live (pair cls3499, main at
 * 8f2298ba) every `duo code-classify` and `duo code-resolve` died with
 *
 *   Error: 'code-inventory' is not a registered subcommand of 'duo'.
 *   Did you mean 'code_inventory'?
 *
 * and the whole code-half migration path was unreachable. Nothing caught it:
 * the classify/resolve suites stub the transport keyed on the name the HOST
 * uses, and both the guide checker and regress_cli_json_refusals resolved the
 * fallback by hyphenating — the assumption WP-CLI does not make.
 *
 * This suite pins the two halves against each other from source, with
 * WP-CLI's rule, so the registered set and the invoked set cannot drift
 * apart again:
 *   1. every underscore-named public handler declares `@subcommand` with the
 *      hyphenated name (the class convention, 19/19 before #523 broke it);
 *   2. every `['duo', '<name>', …]` the host passes to `wp` resolves to a
 *      registered name;
 *   3. `code-inventory` specifically is registered and is what both host
 *      commands invoke — the direct pin that fails against the prior bytes.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

$root = dirname(__DIR__, 4);
$cliSource = (string) file_get_contents($root . '/agent/src/Command/Cli.php');
duo_check($cliSource !== '', 'agent/src/Command/Cli.php is readable');

// ---------------------------------------------------------------------------
// The registered surface, derived the way WP-CLI derives it.
// Each handler is `/** … */ public function name($args, $assoc)`; only
// non-static public methods are subcommands (the static helpers are private).
// ---------------------------------------------------------------------------
preg_match_all(
    '/\/\*\*(?<doc>(?:(?!\*\/).)*)\*\/\s*public function (?<method>[a-z_][a-z0-9_]*)\s*\(/s',
    $cliSource,
    $handlers,
    PREG_SET_ORDER
);
duo_check(count($handlers) >= 30, 'source scan found the subcommand handlers (' . count($handlers) . ')');

$registered = [];
$untaggedUnderscore = [];
$tagMismatch = [];
foreach ($handlers as $h) {
    $method = $h['method'];
    $tag = preg_match('/@subcommand\s+(\S+)/', $h['doc'], $m) === 1 ? $m[1] : null;
    // WP-CLI's rule, verbatim: the tag, else the raw method name.
    $registered[] = $tag ?? $method;
    if ($tag === null && str_contains($method, '_')) {
        $untaggedUnderscore[] = $method;
    }
    if ($tag !== null && $tag !== str_replace('_', '-', $method)) {
        $tagMismatch[] = "$method => $tag";
    }
}
sort($registered);

echo "\n== 1. every underscore-named handler carries its hyphenated @subcommand ==\n";
duo_check(
    $untaggedUnderscore === [],
    'no public handler is registered under a raw underscore name (untagged: ' . duo_check_repr($untaggedUnderscore) . ')'
);
duo_check(
    $tagMismatch === [],
    'every @subcommand tag is the hyphenation of its method name (mismatch: ' . duo_check_repr($tagMismatch) . ')'
);
duo_check(
    !in_array('code_inventory', $registered, true) && in_array('code-inventory', $registered, true),
    'code_inventory() is registered as `code-inventory`, the name the host invokes'
);

// ---------------------------------------------------------------------------
// The invoked surface: every `'duo', '<name>'` literal the host hands to
// `wp`, across cli/src. One regex, the whole tree, so a new host command that
// invokes a new agent subcommand is covered the day it lands.
// ---------------------------------------------------------------------------
echo "\n== 2. every subcommand cli/src invokes over `wp duo` is registered ==\n";
$invoked = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/cli/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $src = (string) file_get_contents($file->getPathname());
    if (preg_match_all("/'duo',\s*'(?<name>[a-z][a-z0-9_-]*)'/", $src, $m) > 0) {
        foreach ($m['name'] as $name) {
            $invoked[$name][] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}
ksort($invoked);
duo_check(count($invoked) >= 18, 'cli/src invokes a non-trivial `wp duo` surface (' . count($invoked) . ' names)');
$unregistered = [];
foreach ($invoked as $name => $files) {
    if (!in_array($name, $registered, true)) {
        $unregistered[$name] = array_values(array_unique($files));
    }
}
duo_check(
    $unregistered === [],
    'every invoked name resolves to a registered subcommand (unregistered: ' . duo_check_repr($unregistered) . ')'
);

// ---------------------------------------------------------------------------
// The specific defect, named: both code-half host commands ask the target for
// `code-inventory`, and that is the registered name.
// ---------------------------------------------------------------------------
echo "\n== 3. duo code-classify and duo code-resolve reach wp duo code-inventory ==\n";
foreach (['cli/src/Command/CodeClassifyCommand.php', 'cli/src/Command/CodeResolveCommand.php'] as $rel) {
    duo_check(
        in_array($rel, $invoked['code-inventory'] ?? [], true),
        "$rel invokes `wp duo code-inventory`"
    );
}
duo_check(
    preg_match('/@subcommand code-inventory\s*\*\/\s*public function code_inventory\(/s', $cliSource) === 1,
    'Cli::code_inventory() declares @subcommand code-inventory immediately above the handler'
);

duo_check_summary('DUO-3517: agent subcommand names match what the host invokes (WP-CLI resolution)');
