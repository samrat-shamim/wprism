<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once dirname(__DIR__) . '/Contract/ProjectionVocabulary.php';

use Duo\CommandRefusalException;

/**
 * Section 4 of `duo assess` — the smallest safe next action per gap, drawn
 * from a closed set (round-3 MUP §2.1 item 4, §4.1).
 *
 * The set itself is `ProjectionVocabulary::GAP_ACTIONS` and the per-row
 * decision is `ProjectionVocabulary::gapAction()`: one implementation of a
 * product vocabulary, in the policy-layer module all three round-3 engine
 * modules can read. This class holds the two things that are genuinely
 * assess's and not the vocabulary's:
 *
 *  1. **Reducing a surface's six per-operation actions to the one an
 *     operator should do next.** A surface can be `nothing — supported` for
 *     capture and `exclude` for delete in the same breath; printing six
 *     actions per row would make the section unreadable and printing the
 *     first would make it wrong. `URGENCY` is the reduction, ordered by how
 *     much is unknown: a surface nobody has classified is the most urgent
 *     thing on the page, and `nothing — supported` is last so that it wins
 *     only when every operation agrees.
 *
 *  2. **The unknown section's own actions.** MUP §2.1 item 3 counts and
 *     names the `pending` queue, the option names invisible to every
 *     installed adapter, and the undeclared tables. Those are not surface
 *     rows (a row per option name would be an unbounded listing, §4.6), so
 *     they need their own mapping into the same closed set — otherwise the
 *     largest source of unknowns on a real site would be the one part of
 *     the assessment with no next action.
 *
 * `MUP §2.3` makes the closedness load-bearing rather than tidy: a release
 * refused *before* its plan is frozen carries a gap action from this set
 * and never a release next action, because the fix is an assessment fix.
 * The two closed sets are not interchangeable, so this one asserts its
 * membership instead of documenting it.
 */
final class GapActions {
    /** The closed set, owned by the vocabulary and re-asserted here. */
    public const ACTIONS = ProjectionVocabulary::GAP_ACTIONS;

    /**
     * Most-urgent first.
     *
     * `classify` leads because an unclassified surface blocks every
     * operation and its remedy is the cheapest on the list. `exclude` is
     * second to last because `Unsupported` is a stated boundary rather than
     * a defect — there is nothing to repair, only a decision to record.
     */
    public const URGENCY = [
        'classify',
        'declare in contract',
        'qualify in rehearsal',
        'install adapter',
        'provision env value',
        'exclude',
        'nothing — supported',
    ];

    /** The unknown-section kinds MUP §2.1 item 3 enumerates. */
    public const UNKNOWN_KINDS = ['pending', 'invisible_option', 'undeclared_table'];

    /**
     * Reduce one surface's per-operation actions to the row's next action.
     *
     * @param array<string,array<string,mixed>> $projections operation -> projection
     */
    public static function forSurface(array $projections): string {
        return self::forSurfaceDetailed($projections)['action'];
    }

    /**
     * The same reduction, with the operations that produced the winning
     * action.
     *
     * A row can read `Ready` in the columns and still carry a next action,
     * because the columns show one operation and the action reduces over
     * all of them. Naming the operations is what stops that reading as a
     * contradiction: "install adapter (delete)" is a complete sentence,
     * "install adapter" beside a `Ready` release row is a puzzle.
     *
     * @param array<string,array<string,mixed>> $projections operation -> projection
     * @return array{action:string,operations:list<string>}
     */
    public static function forSurfaceDetailed(array $projections): array {
        $seen = [];
        foreach ($projections as $operation => $projection) {
            $action = is_string($projection['gap_action'] ?? null)
                ? $projection['gap_action']
                : ProjectionVocabulary::gapAction($projection);
            self::assertMember($action);
            $seen[$action][] = (string) $operation;
        }
        if ($seen === []) {
            // A surface with no projected operation was never assessed. It
            // is not "supported"; it is unclassified for the purposes of
            // this run, and `classify` is the honest instruction.
            return ['action' => 'classify', 'operations' => []];
        }
        foreach (self::URGENCY as $action) {
            if (isset($seen[$action])) {
                return ['action' => $action, 'operations' => $seen[$action]];
            }
        }

        throw self::refuse('a surface produced a gap action outside the closed set');
    }

    /**
     * The next action for one unknown-section finding.
     *
     * `pending` and `invisible_option` are both option/meta *names* Duo can
     * only reach inside apply's hook-free window, so one classification rule
     * closes them — `duo classify <env>` is the literal remedy and
     * `classify` is the word. An undeclared table is different in kind: no
     * adapter models it, so nothing is known about what writes it or what
     * that write reaches, and MUP §2.1's own worked row prints `qualify in
     * rehearsal` for exactly this finding.
     */
    public static function forUnknown(string $kind): string {
        $action = match ($kind) {
            'pending', 'invisible_option' => 'classify',
            'undeclared_table' => 'qualify in rehearsal',
            default => throw self::refuse("unknown assessment gap kind '$kind'"),
        };
        self::assertMember($action);

        return $action;
    }

    /**
     * How many rows sit behind each action, for the human summary.
     *
     * Every action in the closed set is present with a count, including the
     * zeroes: a section that silently omits `declare in contract` when
     * nothing needs it, and prints it when something does, teaches an
     * operator to read the presence of a line as the signal. The count is
     * the signal.
     *
     * @param list<array<string,mixed>> $rows assess report surface rows
     * @param array<string,int> $unknownCounts kind -> count
     * @return array<string,int>
     */
    public static function summarise(array $rows, array $unknownCounts): array {
        $counts = array_fill_keys(self::ACTIONS, 0);
        foreach ($rows as $row) {
            $action = (string) ($row['next_action'] ?? '');
            self::assertMember($action);
            $counts[$action]++;
        }
        foreach ($unknownCounts as $kind => $count) {
            if ($count <= 0) {
                continue;
            }
            $counts[self::forUnknown((string) $kind)] += (int) $count;
        }

        return $counts;
    }

    private static function assertMember(string $action): void {
        if (!in_array($action, self::ACTIONS, true)) {
            throw self::refuse("'$action' is not one of the closed assessment gap actions");
        }
    }

    private static function refuse(string $message): CommandRefusalException {
        return new CommandRefusalException(
            'assess_gap_action_invalid',
            $message,
            'rerun assess; a gap action outside the closed set is an orchestrator defect, not a site condition'
        );
    }
}
