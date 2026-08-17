<?php

declare(strict_types=1);

/**
 * php-cs-fixer configuration for the Duo dev toolchain.
 *
 * This config exists to be run in --dry-run mode over *changed files only*
 * (see the `cs` / `cs:fix` composer scripts). agent/, cli/, recovery/ and
 * scripts/ are certification-closure members (see AGENTS.md non-negotiable
 * 7): a single reformatted byte under any of them expires nine
 * certifications. Two independent things keep a bare `vendor/bin/php-cs-fixer
 * fix --config=.php-cs-fixer.dist.php` (no path arguments — the shape an
 * editor integration or a developer typo would run) from touching closure
 * files:
 *   1. The Finder below only walks tools/ and tests/ — dev-only trees with
 *      zero certification cost — so closure files are never *discovered* by
 *      a bare invocation.
 *   2. The `cs` / `cs:fix` composer scripts, which are how closure files
 *      under agent/cli/recovery/scripts actually get linted when *changed*,
 *      always pass explicit file arguments. php-cs-fixer's default
 *      --path-mode=override makes this config's Finder irrelevant whenever
 *      CLI paths are supplied, so those scripts still reach closure files —
 *      only the auto-discovering bare command is scoped down.
 * Either guard alone would be enough; both are cheap, so both stay.
 *
 * Rule choices are descriptive of what 555 committed .php files already do,
 * and they apply uniformly to every file this config ever touches — whether
 * found by the Finder (tools/, tests/) or named explicitly on the command
 * line by `cs`/`cs:fix` (which can include agent/, cli/, recovery/,
 * scripts/ files):
 *   - @PSR12 is the base (4-space, no tabs, import layout); the brace and
 *     blank-line-after-open-tag rules that contradict the tree's own style
 *     are switched off below (see the measured note there). They are off
 *     for *consistency of new code with the 555 existing files* — the same
 *     reason single_quote and short array syntax are chosen below — not
 *     because of the certification closure; tools/ and tests/, which this
 *     config's Finder actually walks, already follow the same brace-on-
 *     same-line style, so nothing here is a closure-only accommodation.
 *   - declare_strict_types is switched OFF because it is a *risky* transform
 *     that would inject `declare(strict_types=1);` into the ~340 drop-in files
 *     that intentionally do not have it. New files add it by hand instead.
 *   - single_quote and short array syntax are already the prevailing style.
 *   - no_unused_imports is the one rule that catches a real defect class here,
 *     since agent/src's 224 flat `namespace Duo;` files hand-maintain their
 *     own `use` lists alongside hand-written require_once chains.
 *
 * setRiskyAllowed(false) is the load-bearing line: no rule that can change
 * behaviour may run against product source, even accidentally.
 */

$roots = array_values(array_filter(
    ['tools', 'tests'],
    static fn (string $dir): bool => is_dir(__DIR__ . '/' . $dir)
));

$finder = PhpCsFixer\Finder::create()
    ->in(array_map(static fn (string $dir): string => __DIR__ . '/' . $dir, $roots))
    ->exclude(['vendor', 'sandbox'])
    // cli/duo is a `#!/usr/bin/env php` executable with no extension; without
    // this it would be the one first-party PHP entrypoint the fixer never saw.
    ->name('*.php')
    ->name('duo')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setFinder($finder)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => false,
        'no_unused_imports' => true,
        'single_quote' => true,
        'array_syntax' => ['syntax' => 'short'],
        // House style, measured over the tree before enabling this config:
        // `<?php` is immediately followed by `namespace Duo;` (no blank line),
        // and 219/224 agent classes plus every function put the opening brace
        // on the same line. PSR-12's brace/blank-line rules would flag 289 of
        // 298 first-party files on their first unrelated edit. These five are
        // pure brace-position/blank-line layout rules and are switched off
        // tree-wide for consistency of new code with the 555 existing files —
        // the same reason single_quote/array_syntax are chosen above, not a
        // certification-closure accommodation: tools/ and tests/, which this
        // config's Finder actually walks, already follow the same brace-on-
        // same-line convention, so nothing would be gained by re-enabling
        // these for dev-tooling code either.
        'blank_line_after_opening_tag' => false,
        'braces_position' => false,
        'class_definition' => false,
        'function_declaration' => false,
        'statement_indentation' => false,
        'blank_lines_before_namespace' => false,
        // control_structure_braces is different: unlike the five rules above,
        // it is not a layout accommodation — it requires braces around every
        // control-structure body (forbids `if (cond) stmt;`), catching a real
        // defect class (goto-fail / dangling-else). It is off here for the
        // closure-protection reason the file header explains: 19/298
        // first-party files violate it today, all under agent/cli/recovery/
        // scripts, and the `cs`/`cs:fix` composer scripts reach those files
        // by explicit path argument whenever one of them is the file that
        // changed — turning this rule on would make an unrelated edit to a
        // closure file surface, or `cs:fix` rewrite, a pre-existing style
        // violation there, at certification-closure cost. tools/ and tests/
        // have zero violations today, so this costs the dev trees nothing —
        // but it is not on for them either, since one rule set is shared.
        'control_structure_braces' => false,
        'method_argument_space' => ['on_multiline' => 'ignore'],
    ]);
