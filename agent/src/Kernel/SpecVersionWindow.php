<?php
namespace WPrism;

/**
 * The wire-version acceptance window: exactly {N-1, N}, and one definition of
 * it (spec/repo-format.md § v3.1).
 *
 * WHY THIS IS A KERNEL PRIMITIVE AND NOT A METHOD ON THE GRAMMAR
 * -------------------------------------------------------------
 * The window used to be private to `AdapterContractGrammar`, which was right
 * while exactly one document carried a wire version. TWO do: a manifest's
 * `spec_version` and `site.wprism.json`'s own `spec_version` — the same integer,
 * the same grammar, judged in two places.
 *
 * WP-4.12 made the second one matter. `RepositoryCompiler::compile()` judged
 * the repository's version by EXACT EQUALITY, which was invisible while
 * `WPRISM_SPEC_VERSION` never moved and became a fleet-wide compile refusal the
 * moment it did: every repository in the field declares the version of the
 * agent that adopted it, so the flag day would have refused compilation on
 * every deployed site at once — through a door the no-restamp rule
 * (§ v3.12) does not guard, because that rule is about manifests.
 *
 * The obvious fix — have the compiler call the grammar — is refused by the
 * layer ladder: `Repository` is layer 3 and `Adapter` is layer 5, so
 * `RepositoryCompiler.php -> AdapterContractGrammar.php` is an upward
 * reference `regress_agent_src_requires.php` reports by name, and requiring the
 * grammar from the compiler additionally drags the whole adapter graph into
 * every harness that loads the compiler alone (measured: it broke
 * `regress_cli_json_refusals.php`, which declares its own `WPrism\Canon`). The
 * other obvious fix — a second `[$n - 1, $n]` in the compiler — is the one
 * thing the release gate exists to prevent: `tools/wire-surface.php` gate 5
 * asserts the floor is exactly `WPRISM_SPEC_VERSION - 1` by PROBING one
 * validator, and a second window it does not probe could drift to N-2 with
 * nothing to report it.
 *
 * So the window moves down to the layer both readers already depend on. It is
 * dependency-free integer arithmetic over one constant — the kernel's own
 * stated purpose — and it stays the single thing the release gate has to hold
 * to N-1.
 *
 * NOT A POLICY OBJECT. There is no per-caller width, no configuration and no
 * "relaxed" mode. Widening the floor to N-2 costs nothing on the day it is
 * done and converts a staging channel with an expiry into permanent tolerance
 * (`docs/wire-surface.md` row R-18), which is why the width is a literal here
 * rather than an argument anywhere.
 */
final class SpecVersionWindow {
    /**
     * The `spec_version` integers an engine at `$supported` accepts.
     *
     * @return list<int> ascending, always exactly two entries
     */
    public static function accepted(int $supported): array {
        return [$supported - 1, $supported];
    }

    /**
     * The window as a refusal prints it: `{2, 3}`.
     *
     * Shared so that the manifest refusal and the repository refusal print one
     * integer pair in one shape — an operator reading both about the same
     * number must not be shown two grammars.
     *
     * @param list<int> $accepted
     */
    public static function text(array $accepted): string {
        return '{' . implode(', ', $accepted) . '}';
    }
}
