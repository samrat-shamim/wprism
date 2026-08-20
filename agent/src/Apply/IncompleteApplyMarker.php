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
 * back only through this class. A marker without a v1 record — one written by
 * an older agent, or hand-planted by a recovery script — reports `null`,
 * which the projection reads as "the interrupted run recorded nothing" and
 * keeps DUO-3206's original whole-bucket widening rather than inventing a
 * preservation claim it has no evidence for.
 */
final class IncompleteApplyMarker {
    public const FORMAT = 'duo-apply-in-progress/v1';

    /**
     * The marker value written immediately before the first target mutation.
     *
     * @param list<array<string,mixed>> $preservedDrift plan `drift` rows this run will not write
     */
    public static function encode(array $preservedDrift): string {
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
        return Canon::encode([
            'format' => self::FORMAT,
            'preserved_drift' => $rows,
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
        if (!is_array($decoded)
            || ($decoded['format'] ?? '') !== self::FORMAT
            || !is_array($decoded['preserved_drift'] ?? null)) {
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
}
