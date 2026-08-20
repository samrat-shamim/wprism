<?php
declare(strict_types=1);

namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';

/**
 * The payload carried by DUO-3206's `apply_in_progress` ledger marker.
 *
 * The marker used to be the constant `'1'`, and that single bit is why
 * DUO-3489 could silently overwrite a preserved local edit. A failed apply
 * leaves the marker set; the next plan folds `unchanged`/`drift`/`conflict`
 * into `update` with `retry:true` (see
 * ApplyPlanner::project_incomplete_apply_retry()) because after a partial
 * apply `duo_state` still names the pre-apply base, so a row this run
 * actually WROTE can re-read as any of the three. That reasoning holds only
 * for rows the failed run wrote. Environment-only `drift` is exactly the
 * bucket a normal apply deliberately does NOT write
 * (ApplyPlanner::rebuild_work():699-706, "A normal apply leaves
 * environment-only drift for capture"), so widening it turned "preserved"
 * into "clobbered on the next run", with the retry plan reporting `drift:0`
 * where the failed run's plan reported the true count.
 *
 * One bit cannot tell those two cases apart; the failing run's own plan can.
 * So the marker records the identities it preserved, and the payload is read
 * back only through this class. A marker without a v1/v2 record — one written
 * by an older agent, or hand-planted by a recovery script — reports `null`,
 * which the projection reads as "the interrupted run recorded nothing" and
 * keeps DUO-3206's original whole-bucket widening rather than inventing a
 * preservation claim it has no evidence for.
 *
 * DUO-3491: the preserved-drift record cannot answer the same question for
 * the `conflict` bucket, and that bucket is drained into `update` too. A
 * three-way conflict on a row the failed run wrote is DUO-3206's own artifact
 * (stale `duo_state` base) and must widen; a three-way conflict on any other
 * identity is a genuine env-and-repo divergence that a first apply refuses
 * without `--force-theirs` (ApplyPreparationCoordinator.php:58). Only one of
 * the two is derivable from `preserved_drift`, because a row the run never
 * planned as drift — an `unchanged` row that drifted after the marker was
 * written, then diverged again on recompile — is equally not-written and
 * leaves no trace in it. So v2 adds the positive fact instead: `write_set`,
 * the exact authored work set (`ApplyPlanner::rebuild_work()`'s
 * create+adopt+update+conflict) locked in immediately before the first
 * mutation. "Not in `write_set`" is a hard fact about what this run was
 * authorized to touch; "in it" means the run may have mutated that row, which
 * is precisely the condition DUO-3206's widening was built for.
 */
final class IncompleteApplyMarker {
    /**
     * The wire this agent writes: `preserved_drift` plus `write_set`. The
     * format string is the contract, so the write set arrives as a version
     * bump rather than an optional field silently added to v1 — a v1 payload
     * would then be indistinguishable from a v2 one that lost its field, and
     * "no field" would read as "wrote nothing", the exact false claim
     * write_set() exists to prevent.
     */
    public const FORMAT = 'duo-apply-in-progress/v2';

    /**
     * DUO-3489's wire, still on any target interrupted under 9b440c3. Read
     * for its `preserved_drift` record and for nothing else.
     */
    public const FORMAT_V1 = 'duo-apply-in-progress/v1';

    /**
     * The marker value written immediately before the first target mutation.
     *
     * @param list<array<string,mixed>> $preservedDrift plan `drift` rows this run will not write
     * @param list<array<string,mixed>> $writeSet the authored work rows this run is authorized to write
     */
    public static function encode(array $preservedDrift, array $writeSet): string {
        $rows = [];
        foreach ($preservedDrift as $row) {
            $uuid = (string) ($row['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $rows[] = [
                'path' => (string) ($row['path'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'uuid' => $uuid,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['uuid'], $b['uuid']));
        // Identity only: the retry needs membership, and the rows it must
        // name back to the operator come from its own fresh plan, not from
        // here. `options/core` can appear in the work set through more than
        // one plan path, so de-duplicate before sorting.
        $written = [];
        foreach ($writeSet as $row) {
            $uuid = (string) ($row['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $written[$uuid] = true;
        }
        $written = array_keys($written);
        sort($written, SORT_STRING);
        return Canon::encode([
            'format' => self::FORMAT,
            'preserved_drift' => $rows,
            'write_set' => $written,
        ]);
    }

    /**
     * The identities the interrupted apply classified as environment drift
     * and deliberately left on the target, or null when this marker carries
     * no such record.
     *
     * @return array<string,array{path:string,type:string,uuid:string}>|null uuid => row
     */
    public static function preserved_drift(?string $marker): ?array {
        $decoded = self::record($marker);
        if ($decoded === null || !is_array($decoded['preserved_drift'] ?? null)) {
            return null;
        }
        $rows = [];
        foreach ($decoded['preserved_drift'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uuid = (string) ($row['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $rows[$uuid] = [
                'path' => (string) ($row['path'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'uuid' => $uuid,
            ];
        }
        return $rows;
    }

    /**
     * The identities the interrupted apply was authorized to write, or null
     * when this marker makes no such claim — a v1 record, an older agent's
     * bare `'1'`, or a hand-planted value. Null is not "wrote nothing": the
     * projection must fall back to the evidence that marker does carry
     * (DUO-3489's preserved-drift record, or DUO-3206's blanket widening)
     * rather than read absence as a preservation claim.
     *
     * @return array<string,true>|null uuid => true
     */
    public static function write_set(?string $marker): ?array {
        $decoded = self::record($marker);
        if ($decoded === null
            || ($decoded['format'] ?? '') !== self::FORMAT
            || !is_array($decoded['write_set'] ?? null)) {
            return null;
        }
        $uuids = [];
        foreach ($decoded['write_set'] as $uuid) {
            if (!is_string($uuid) || $uuid === '') {
                continue;
            }
            $uuids[$uuid] = true;
        }
        return $uuids;
    }

    /**
     * The decoded marker object when it carries a record this class wrote,
     * else null.
     *
     * @return array<string,mixed>|null
     */
    private static function record(?string $marker): ?array {
        if ($marker === null || trim($marker) === '') {
            return null;
        }
        try {
            $decoded = Canon::decode($marker);
        } catch (\Throwable $malformed) {
            // A marker is recovery evidence, never authority: an unreadable
            // one still means "an apply was interrupted here" and must not
            // turn into a fatal on the retry that is supposed to clear it.
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $format = $decoded['format'] ?? '';
        if ($format !== self::FORMAT && $format !== self::FORMAT_V1) {
            return null;
        }
        return $decoded;
    }
}
