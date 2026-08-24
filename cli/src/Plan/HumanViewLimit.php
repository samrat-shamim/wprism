<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * The ONE bound every human view in `duo` obeys, and the one sentence it
 * prints when it cuts (round-3 MUP §4.6).
 *
 * ## Why one class, and why here
 *
 * Before DUO-3521 the same `1..200` grammar and the same
 * `50` / `200` pair were written out four times — `PlanView::MAX_LIMIT`
 * (Plan/PlanView.php:33), `AssessRenderer` (Assess/AssessRenderer.php:45-48),
 * `AuthorizationPlanRenderer` (Release/AuthorizationPlanRenderer.php:51-54)
 * and `RehearsalPlanPreview` (Rehearse/RehearsalPlanPreview.php:106-109) —
 * each citing the others in a comment as the authority. Four copies of a
 * ceiling is four chances for an operator to learn two rules, and the verbs
 * that had NO bound at all (`duo pending`, `duo classify`'s skipped lists,
 * `duo contract show`'s effect list) had nowhere to inherit one from.
 *
 * It lives in `cli:Plan` and not in `cli:Command` because of the module
 * ladder (tools/modules.json rule 3): `cli:Plan` is `kernel`, the lowest
 * rung, and `cli:Assess`, `cli:Release`, `cli:Rehearse` are `engine`. A
 * shared class in the `surface` module `cli:Command` would make all three
 * engine modules reference upward, which is exactly the edge
 * `AuthorizationPlanRenderer`'s docblock said it was duplicating seven lines
 * to avoid. Downward to `kernel` is legal for every one of them, and the
 * ceiling's existing source of truth (`PlanView::MAX_LIMIT`) was already in
 * this module.
 *
 * ## The refusal stays with the caller
 *
 * `parse()` takes a factory for the exception it throws instead of throwing
 * one of its own. Each verb's `--limit` refusal is operator-visible text
 * that AGENTS.md rule 8 keeps byte-identical, and they legitimately differ
 * (`assess accepts at most one canonical --limit=<1..200>` vs
 * `--limit must be given once as --limit=N with N between 1 and 200`).
 * Sharing the GRAMMAR while each caller keeps its own envelope is what lets
 * this land without moving a single refusal byte.
 *
 * ## `--format=json` is the other half
 *
 * A bound is only honest because the complete document is one flag away, so
 * `cut()` names that flag and nothing else. The machine document is never
 * bounded, and the counts printed beside a cut list stay the TRUE totals: a
 * truncated sample is honest, a truncated count is a lie about the site.
 */
final class HumanViewLimit {
    /** MUP §4.6: rows per section when `--limit` is not given. */
    public const DEFAULT_LIMIT = 50;

    /** MUP §4.6: the closed ceiling `--limit=<1..200>` publishes. */
    public const MAX_LIMIT = 200;

    /**
     * `1..200`, decimal, no leading zeros, no sign, no whitespace.
     *
     * Spelled as an explicit alternation rather than a range compare so the
     * grammar refuses `+5`, `050` and ` 5 ` at the same place it refuses
     * `0` and `201` — an operator who asked for 20 rows and silently got 50
     * would read the missing tail line as "there are no more rows".
     */
    public const GRAMMAR = '/^(?:[1-9]|[1-9][0-9]|1[0-9]{2}|200)$/D';

    /** The exact tail a cut list ends with, used verbatim everywhere. */
    public const CUT_SUFFIX = ' more (use --format=json)';

    /** Whether a raw `--limit=` value is inside the published grammar. */
    public static function valid(string $raw): bool {
        return preg_match(self::GRAMMAR, $raw) === 1;
    }

    /**
     * Read `--limit` out of a verb's own extra args.
     *
     * At most one, always `--limit=N`: a bare `--limit` and a repeated one
     * both refuse rather than resolving to a default, for the same reason
     * `GRAMMAR` is closed.
     *
     * @param list<string> $args
     * @param callable():\Throwable $onInvalid the CALLER's typed refusal, so
     *        no existing refusal envelope moves (AGENTS.md rule 8)
     */
    public static function parse(array $args, callable $onInvalid): int {
        $limit = self::DEFAULT_LIMIT;
        $seen = false;
        foreach ($args as $arg) {
            if (!is_string($arg) || !str_starts_with($arg, '--limit')) {
                continue;
            }
            if ($seen || !str_starts_with($arg, '--limit=')) {
                throw $onInvalid();
            }
            $seen = true;
            $raw = substr($arg, strlen('--limit='));
            if (!self::valid($raw)) {
                throw $onInvalid();
            }
            $limit = (int) $raw;
        }

        return $limit;
    }

    /**
     * The tail line for a list that was cut, or null when it was not.
     *
     * `$total` is the TRUE row count and `$shown` is what was printed, so a
     * caller that slices first cannot accidentally report the slice as the
     * whole.
     */
    public static function cut(int $total, int $shown, string $indent = '  '): ?string {
        $remaining = $total - $shown;

        return $remaining > 0 ? $indent . $remaining . self::CUT_SUFFIX : null;
    }
}
