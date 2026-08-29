<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/RetainedCheckpoints.php';

use Duo\CommandRefusalException;

/**
 * Removing retained release checkpoints, as an EXPLICIT operator verb and
 * never as a background policy (DUO-3514's retention half).
 *
 * ## The growth this answers, and the fix it deliberately is not
 *
 * Two writers retain a whole-database dump per release and neither of them
 * ever removes one: `cli/duo`'s `cmd_promote_internal()` writes
 * `.duo/checkpoints/promote-<run-id>.sql.enc` (cli/duo:2236) and
 * `DeployCommand::run()` writes `deploy-<run-id>.sql` under the same lease
 * (DeployCommand.php:67). A target that has released weekly for a year holds
 * fifty-two whole-DB dumps and no verb in the product deletes any of them.
 *
 * The tempting fix — start populating `retention_until` on a retained row —
 * is exactly the fabrication `RetainedCheckpoints::row()` forbids: "a plain
 * checkpoint has no retention policy, and printing a fabricated one would
 * teach the operator a guarantee the file does not carry"
 * (RetainedCheckpoints.php:239-256). A `.sql` on a target's disk carries no
 * expiry, and nothing this class does makes one true. `retention_until`
 * therefore stays null, and what the operator gets instead is a verb that
 * removes files and prints exactly which ones it removed.
 *
 * ## Five rules, all decided on the catalog the same invocation just read
 *
 *  1. **`keep` is 1..50.** `--prune-retained=0` is refused by the grammar,
 *     not by a check, so "delete the only before-image" is unreachable rather
 *     than guarded against: the newest row of a group is never a candidate.
 *  2. **Keeping is per `(prefix, env)`.** Rows are grouped by
 *     `RetainedCheckpoints::prefixForRow()` (:314-320) and
 *     `RetainedCheckpoints::parse()` has already sorted them newest-first
 *     (:229-233), so the newest `keep` promotes and the newest `keep` deploys
 *     each survive. A month of deploys can never age out the last promote.
 *  3. **Only `isRetained()` rows are candidates.** A signed receipt is not a
 *     file under `.duo/checkpoints` and its `id` is a receipt id chosen by the
 *     rollback authority, not a file name — `prefixForRow()`'s own docblock
 *     (:301-312) states that a receipt id beginning `deploy-` would otherwise
 *     resolve to a path promote never wrote.
 *  4. **A nonterminal authority row refuses the whole prune.** Pruning while
 *     a signed generation is mid-rollback is pruning during an in-flight
 *     release. This costs nothing on a transport with no authority, where the
 *     row set is empty.
 *  5. **Only the `.sql` is removed.** The sibling `.duo/artifacts/<stem>.json`
 *     and the frozen plan under `.duo/releases/` stay, and
 *     `DISCLOSURE_ARTIFACTS_KEPT` says so: those are what
 *     `RecoverCommand::priorCodeRevision()` (:561) and `planHadCodePhase()`
 *     (:587) read for the checkpoints that REMAIN, so deleting them would
 *     silently degrade the code-first gate for rows this prune kept.
 *
 * ## No writer exclusion, on purpose
 *
 * `--writers-excluded` has one exact meaning — the checkpoint contains its own
 * promotion lease row, so the exclusion has to be external to the database
 * being imported. That is `RecoverCommand`'s `writer_exclusion_required`
 * refusal, "recovery imports a database that contains its own promotion lease
 * row, so it cannot start until every external writer is excluded for the
 * whole recovery window" (RecoverCommand.php:334-340). A prune imports
 * nothing, takes no lease and opens no window. Accepting the flag here would
 * re-teach it as a generic "this is dangerous" acknowledgement and dilute the
 * one place it asserts something checkable, which is why `--prune-retained`
 * combined with it is `invalid_arguments`.
 *
 * ## Shape
 *
 * `plan()`, `script()` and `parse()` are pure, exactly as
 * `RetainedCheckpoints` splits its own read, so an offline suite pins both
 * the bytes sent to a target and the decision that produced them with no
 * target at all. Execution is one `captureRaw()`, which every transport
 * already implements, so this needs no new capability and works on local,
 * docker and ssh alike.
 */
final class CheckpointPrune {
    public const FORMAT = 'duo-checkpoint-prune/v1';

    /** The closed bounds of `--prune-retained=<keep-n>`. */
    public const KEEP_MIN = 1;
    public const KEEP_MAX = 50;

    /** A signed generation is mid-flight; nothing is removed. */
    public const REASON_GENERATION_ACTIVE = 'checkpoint_prune_generation_active';

    /** The target answered the removal script with a line this build cannot read. */
    public const REASON_MALFORMED = 'checkpoint_prune_malformed';

    /** The target could not run the removal script at all. */
    public const REASON_UNAVAILABLE = 'checkpoint_prune_unavailable';

    /** The per-row outcomes `parse()` accepts, and nothing else. */
    public const STATUS_REMOVED = 'removed';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_FAILED = 'failed';

    /** What a plan row reports when `--confirm-prune` was not given. */
    public const STATUS_WOULD_PRUNE = 'would-prune';

    public const DISCLOSURE_NEWEST_KEPT =
        'the newest retained checkpoints are never deletable: the keep count applies separately to the promote '
        . 'and deploy files, so the most recent before-image of each verb survives every prune';

    public const DISCLOSURE_ARTIFACTS_KEPT =
        'only the retained .sql under .duo/checkpoints is removed; the sibling .duo/artifacts/<stem>.json and the '
        . 'frozen authorization plan under .duo/releases are kept, because those are what the code-first gate reads '
        . 'for the checkpoints this prune left in place';

    public const DISCLOSURE_NEVER_AUTOMATIC =
        'Duo prunes nothing on its own: retained checkpoints are removed only by this explicit operator verb';

    /**
     * Decide what a prune would remove. Pure.
     *
     * The catalog is the one `CheckpointCatalog::list()` just read, so the
     * decision and the listing an operator could print beside it cannot
     * disagree. Order is preserved rather than re-sorted:
     * `RetainedCheckpoints::parse()` already fixed newest-first, and
     * `CheckpointCatalog::withRetained()` appends its rows after the authority
     * rows without reordering them (:171-185).
     *
     * @param array<string,mixed> $catalog a `CheckpointCatalog::list()` result
     * @return array{
     *     delete:list<array<string,mixed>>,
     *     keep:list<array<string,mixed>>,
     *     disclosures:list<string>
     * }
     */
    public static function plan(array $catalog, int $keep): array {
        self::assertKeep($keep);
        $rows = is_array($catalog['rows'] ?? null) ? array_values($catalog['rows']) : [];

        $groups = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!RetainedCheckpoints::isRetained($row)) {
                // Rule 4. `terminal` is a key every catalog row carries
                // (CheckpointCatalog::row():263), and a retained row is always
                // true, so this reads only the signed rows — which are exactly
                // the ones that can be mid-generation.
                if (($row['terminal'] ?? false) !== true) {
                    throw new CommandRefusalException(
                        self::REASON_GENERATION_ACTIVE,
                        'a signed rollback generation on this target is not in a terminal state, so its release is '
                            . 'still in flight and no retained checkpoint may be removed',
                        'finish or roll back the active generation — duo recover <env> --list names it — then '
                            . 're-run the prune'
                    );
                }
                continue;
            }
            $groups[RetainedCheckpoints::prefixForRow($row)][] = $row;
        }
        // Sorted so the plan document and the printed lines are deterministic
        // regardless of which verb happened to write first on this target.
        ksort($groups, SORT_STRING);

        $delete = [];
        $kept = [];
        foreach ($groups as $group) {
            foreach (array_values($group) as $index => $row) {
                if ($index < $keep) {
                    $kept[] = $row;
                    continue;
                }
                $delete[] = $row;
            }
        }

        $disclosures = [self::DISCLOSURE_NEVER_AUTOMATIC, self::DISCLOSURE_NEWEST_KEPT];
        if ($delete !== []) {
            $disclosures[] = self::DISCLOSURE_ARTIFACTS_KEPT;
        }

        return ['delete' => $delete, 'disclosures' => $disclosures, 'keep' => $kept];
    }

    /**
     * The one script a confirmed prune sends. Pure.
     *
     * POSIX sh only, for the same reason `RetainedCheckpoints::script()` is
     * (:150-167): docker runs it under `bash -c`, ssh under the remote login
     * shell, local under the system shell. Every path is derived from
     * `RetainedCheckpoints::checkpointPath()` and the row's own prefix, so the
     * host cannot name a file the listing did not — which is the whole reason
     * a prune is expressible as an `rm` at all. One tab-separated
     * `<id>\t<removed|absent|failed>` line per row, and `exit 0`, because the
     * per-row outcome is the answer and a non-zero exit would collapse a
     * partially-completed removal into "the target could not enumerate".
     *
     * @param list<array<string,mixed>> $rows the `delete` half of a plan()
     */
    public static function script(string $repoPath, array $rows): string {
        $parts = [];
        foreach ($rows as $row) {
            $path = RetainedCheckpoints::checkpointPath(
                $repoPath,
                $row,
                RetainedCheckpoints::prefixForRow($row)
            );
            $id = escapeshellarg((string) ($row['id'] ?? ''));
            $q = escapeshellarg($path);
            $parts[] = 'p=' . $q . '; '
                . 'if [ -e "$p" ]; then '
                . 'if rm -f "$p"; then printf \'%s\t%s\n\' ' . $id . ' ' . self::STATUS_REMOVED . '; '
                . 'else printf \'%s\t%s\n\' ' . $id . ' ' . self::STATUS_FAILED . '; fi; '
                . 'else printf \'%s\t%s\n\' ' . $id . ' ' . self::STATUS_ABSENT . '; fi; ';
        }

        return implode('', $parts) . 'exit 0';
    }

    /**
     * Turn the script's stdout into per-row outcomes. Pure.
     *
     * Malformed lines are REFUSED rather than skipped, exactly as
     * `RetainedCheckpoints::parse()` refuses (:186-194): a target that
     * answered a deletion with something this build cannot read is a target
     * this build must not report a deletion count for.
     *
     * @return list<array{id:string,status:string}>
     */
    public static function parse(string $stdout): array {
        $out = [];
        foreach (explode("\n", $stdout) as $line) {
            $line = rtrim($line, "\r");
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) !== 2) {
                throw self::malformed('a checkpoint prune line did not carry exactly two fields');
            }
            [$id, $status] = $parts;
            if (!in_array($status, [self::STATUS_REMOVED, self::STATUS_ABSENT, self::STATUS_FAILED], true)) {
                throw self::malformed('a checkpoint prune line reported an outcome this build does not define');
            }
            $prefixed = false;
            foreach (RetainedCheckpoints::ID_PREFIXES as $prefix) {
                if (str_starts_with($id, $prefix)) {
                    $prefixed = true;
                    break;
                }
            }
            if (!$prefixed) {
                throw self::malformed('a checkpoint prune line named an id that is not a retained checkpoint');
            }
            $out[] = ['id' => $id, 'status' => $status];
        }

        return $out;
    }

    /**
     * Run one confirmed prune on a target.
     *
     * `captureRaw()` rather than a new capability member: removing a file the
     * listing already named needs nothing a transport does not already have,
     * and the alternative — a `CodePushTransport`-style seam — would make the
     * verb ssh-only for no gain (RetainedCheckpoints::list() reaches every
     * transport through exactly this primitive, :136-148).
     *
     * @param list<array<string,mixed>> $rows the `delete` half of a plan()
     * @return list<array{id:string,status:string}>
     */
    public static function prune(EnvironmentDriver $driver, array $rows): array {
        if ($rows === []) {
            return [];
        }
        $result = $driver->captureRaw(self::script($driver->repoPath(), $rows));
        if (($result['exit'] ?? 1) !== 0) {
            throw new CommandRefusalException(
                self::REASON_UNAVAILABLE,
                'the target could not remove the retained release checkpoints this prune named',
                'run duo doctor ' . $driver->name() . ' and repair the transport it reports, then re-run the prune',
                [['detail' => trim((string) (($result['stderr'] ?? '') !== ''
                    ? $result['stderr']
                    : ($result['stdout'] ?? '')))]]
            );
        }

        return self::parse((string) ($result['stdout'] ?? ''));
    }

    /**
     * The closed bound, asserted here as well as in the flag grammar.
     *
     * Both are load-bearing and neither is redundant: the grammar is what an
     * operator hits, and this is what keeps rule 1 true for any future caller
     * that reaches `plan()` without going through `duo recover`'s flags.
     */
    public static function assertKeep(int $keep): void {
        if ($keep < self::KEEP_MIN || $keep > self::KEEP_MAX) {
            throw new CommandRefusalException(
                'invalid_arguments',
                'the retained-checkpoint keep count is outside the range this command accepts',
                'pass --prune-retained=<' . self::KEEP_MIN . '..' . self::KEEP_MAX . '>; keeping zero checkpoints '
                    . 'is not expressible, because the newest before-image of each verb is never deletable'
            );
        }
    }

    private static function malformed(string $detail): CommandRefusalException {
        return new CommandRefusalException(
            self::REASON_MALFORMED,
            'the target answered the retained checkpoint prune with a line this build cannot read',
            'upgrade the host orchestrator to the build that matches this target, or inspect '
                . '.duo/checkpoints on the target by hand before pruning again',
            [['detail' => $detail]]
        );
    }
}
