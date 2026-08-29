# Live-pair budget envelope (WP-0.7)

The adapter-decentralization program runs many work packages in parallel, and
several of them carry a live leg. Offline evidence parallelizes freely; live
pairs do not — `sandbox/bin/pair.sh` binds the shared MariaDB stack and the
docker daemon, and two concurrent pairs fight over the same host resources the
same way two suites with fixed `/tmp` paths fight over an inode
(`tools/offline.php`'s serial-group rationale, applied one level up). This
document is the envelope every live-carrying work package quotes in its
acceptance evidence, so "these phases run in parallel" stays a schedule rather
than a claim. It changes no engine behavior.

## The envelope

- **Concurrency: exactly one live pair at a time, program-wide.** A work
  package that needs a pair claims the next slot in the allocation order below;
  everything else in flight stays offline. Pair discipline itself is unchanged:
  budget, release-when-idle, destroy-when-done, `WPRISM_EXPECTED_SOURCE_SHA`
  (docs/sandbox.md, docs/agents/linear-loop.md §Evidence scoping).
- **Per-wave ceiling: 6 pair-hours.** A wave that would exceed it defers its
  cheapest-to-defer live leg (recorded in the wave report, never silently
  skipped) rather than queueing past the ceiling. The number is deliberately
  small: every live leg below has an offline replay or recording as its durable
  artifact, so pair time is spent once per fixture, not per iteration.
- **Recording over repetition.** A live run that can leave a replayable fixture
  (activation-differential snapshots, conformance vectors, probe documents,
  bisection outcome tables) must do so — the fixture is the deliverable the
  offline gate consumes forever; the pair run is only its recorder.

## Live-carrying work packages, in allocation order

The order is the program's merge order — a live leg never runs before the
offline half of its own work package is green.

| order | WP | live leg | mutually exclusive with |
| --- | --- | --- | --- |
| 1 | WP-2.3 | one-subject byte-identity proof of the certify-matrix split | any other pair use |
| 2 | WP-0.3 | activation-differential snapshots, one run per (plugin, version), recorded for offline replay | any other pair use |
| 3 | WP-2.2 | one bisection reproducing an already-committed boundary | WP-2.9 (same harness) |
| 4 | WP-2.7 | one recording per conformance vector | any other pair use |
| 5 | WP-4.12 | `conformance-core` plus one certified-site-adapter fixture — never the manifest matrix | everything (flag-day rehearsal window) |
| 6 | WP-5.1 | revocation drill with measured propagation latency | everything |
| 7 | WP-2.9 | scheduled re-bisections, steady-state | WP-2.2 |

All are single-subject by construction, per docs/agents/linear-loop.md's
minimal reasonably-safe rule; nothing in this program runs the manifest matrix
by habit.

### Order 4 — the exact command (WP-2.7)

The vector recorder is a FLAG on the existing conformance harness, so the pair
run is an ordinary sweep that leaves an artifact behind. One manifest, one
sweep, one vector:

```bash
cd sandbox
CONF_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) \
CONF_RECORD_VECTOR="$PWD/tmp/vector-code-snippets.json" \
  bash conformance/run.sh code-snippets
```

`code-snippets` is the cheapest honest subject: one declared typed table, one
pinned plugin version window, and a seed hook that already authors hostile
UTF-8/delimiter bytes and both shortcode aliases. `CONF_RECORD_VECTOR` writes
only after the round-trip acceptance passes, so a vector cannot record a sweep
nobody checked, and `conformance/record-vector.php` refuses to write one this
repository's own replay would reject — the defect surfaces while the pair is
still up rather than costing a second one.

Nothing about the offline half waits on this. The mechanism is proven against a
synthetic adapter with no pair at all by
`sandbox/tests/offline/capture/regress_conformance_vector_replay.php`, which is
what a recorded vector then joins rather than what it enables.

## Reporting

Each live-carrying work package's report states pair-hours consumed against
this envelope. The fleet census (WP-0.2) carries a per-work-package pair
consumption row once both exist; until then the wave report is the record.
