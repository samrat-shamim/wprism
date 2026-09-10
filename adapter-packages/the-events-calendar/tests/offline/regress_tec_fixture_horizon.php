<?php
declare(strict_types=1);

// This capsule's live evidence reaches its seeded events through the ordinary
// WP_Query path, and TEC filters that path by the event date. Measured on
// 6.17.2 in a pair: a title lookup for a past-dated tribe_events post returns
// 0 rows, the same lookup with `tribe_suppress_query_filters` returns 1, and an
// unfiltered listing of one past plus one future event returns 1 row, not 2.
//
// So a fixture date that falls into the past does not weaken the evidence, it
// deletes it: check.sh's identity lookups stop finding the event and the suite
// dies on "expected one tribe_events '...', got 0" more than a thousand lines
// away from the date that caused it. That is not hypothetical — the conformance
// and regen fixtures were authored with near-future dates in August 2026 and
// went past on 2026-09-06, turning the whole certify matrix red with a
// diagnostic that pointed at nothing.
//
// Suppressing the query filters is the wrong repair and is deliberately not
// what this guard enforces. Native query visibility IS the certified claim:
// package/manifest.json calls tec_events/tec_occurrences "a HARD
// query-availability dependency, not a soft cache", and
// tests/live/regress_tec_regen.sh asserts the applied event is findable by
// `wp post list` precisely because a missing occurrence row makes it invisible.
// A suppressed query would report success with no occurrence rows at all.
//
// This test therefore consults the wall clock on purpose. It is a rot
// detector, and the question it answers — "has this fixture expired yet?" — is
// not answerable from the fixture bytes alone. It fails offline and for free,
// naming the file and the remedy, instead of costing a live pair and a long
// diagnosis.

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';

// Every live asset that authors a tribe_events datetime. postapply.sh carries
// none today and is listed so that adding one there is covered rather than
// silently exempt.
$scanned = [
    'adapter-packages/the-events-calendar/tests/conformance/seed.sh',
    'adapter-packages/the-events-calendar/tests/conformance/check.sh',
    'adapter-packages/the-events-calendar/tests/conformance/postdeploy.sh',
    'adapter-packages/the-events-calendar/tests/conformance/postapply.sh',
    'adapter-packages/the-events-calendar/tests/live/regress_tec_regen.sh',
    'sandbox/tests/grind/grind_r3b_events.sh',
];

// The only two datetime literals in those files that are not required to be
// upcoming. Each is deliberate and is exempt for a stated reason, so a third
// one cannot appear without someone writing down why.
$exempt = [
    '2026-02-30 01:02:03' =>
        'check.sh schema-refusal probe: February 30th is not a calendar date at all. It is written '
        . 'directly into _EventStartDate to prove the adapter refuses an impossible native value, so '
        . 'it must never be "moved forward" — that would delete the probe.',
    '2020-04-05 02:03:04' =>
        'postdeploy.sh target-local cutoff sentinel: deliberately past, and read back by post ID '
        . 'rather than by query, to prove a target-owned past event survives a hook-bypassing '
        . 'settings write byte-exact.',
    '2020-04-05 04:03:04' =>
        'the same cutoff sentinel\'s end date.',
];

$now = time();
$found = 0;
$exemptSeen = [];
$stale = [];
$horizon = null;
$horizonWhere = '';

foreach ($scanned as $rel) {
    $path = $root . '/' . $rel;
    wprism_check(is_file($path), "scanned live asset exists: $rel");
    $lines = explode("\n", (string) file_get_contents($path));
    foreach ($lines as $i => $line) {
        if (preg_match_all('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $line, $m) === 0) {
            continue;
        }
        foreach ($m[0] as $literal) {
            if (isset($exempt[$literal])) {
                $exemptSeen[$literal] = true;
                continue;
            }
            $found++;
            // Parsed as UTC. The authored timezones here are UTC and
            // Asia/Kathmandu (+05:45), so a UTC reading is at most six hours
            // pessimistic — irrelevant against a multi-year horizon, and
            // pessimistic is the safe direction for an expiry check.
            $ts = strtotime($literal . ' UTC');
            wprism_check(
                is_int($ts),
                "$rel:" . ($i + 1) . " authors a parseable datetime: $literal"
            );
            if (!is_int($ts)) {
                continue;
            }
            if ($ts <= $now) {
                $stale[] = "$rel:" . ($i + 1) . " => $literal";
            }
            if ($horizon === null || $ts < $horizon) {
                $horizon = $ts;
                $horizonWhere = "$rel:" . ($i + 1) . " ($literal)";
            }
        }
    }
}

wprism_check($found > 0, 'the scan actually found tribe_events datetimes to check (a silent zero would pass vacuously)');

wprism_check(
    $stale === [],
    $stale === []
        ? 'every authored tribe_events datetime in this capsule\'s live assets is still in the future'
        : "EXPIRED TEC fixture datetimes — TEC hides past events from the ordinary query path, so the "
            . "conformance and regen suites cannot find these events any more. Move the authored dates "
            . "forward, keeping seed.sh and check.sh in step (they assert each other's literals), and do "
            . "NOT add tribe_suppress_query_filters: native query visibility is the claim under test. "
            . 'Expired: ' . implode('; ', $stale)
);

foreach ($exempt as $literal => $why) {
    wprism_check(
        isset($exemptSeen[$literal]),
        "exempt datetime $literal is still present in the scanned assets, so its exemption is live rather "
            . "than a stale entry ($why)"
    );
}

// Report the binding constraint so the next expiry is visible long before it
// fires, rather than being discovered by a red certify matrix.
if ($horizon !== null) {
    $days = (int) floor(($horizon - $now) / 86400);
    fwrite(STDOUT, sprintf(
        "  TEC live fixture horizon: %s expires first, in %d days (%s)\n",
        $horizonWhere,
        $days,
        gmdate('Y-m-d', $horizon)
    ));
}

wprism_check_summary('The Events Calendar live fixture horizon');
