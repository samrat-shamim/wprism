# Dispatch Loop (`LINEAR-LOOP`) — duo-wp

Simplified adaptation of genesis-monorepo's `docs/agents/dispatch-loop.md` for
this repo. It applies only when the dispatch prompt contains the literal token
`LINEAR-LOOP` (accept `LINEAR_LOOP` as the same token). Re-read this file at
the start of the run, after every close, and at every polling resume. If the
dispatch prompt conflicts with this file, prompt parameters win; this
procedure still wins over improvisation.

Differences from genesis, deliberate: duo-wp ships work as **direct commits to
`main`** (no PR/squash-merge/worktree machinery), so the close gate here is
verification evidence + a commit on `main` referencing the issue, not merge
ancestry. Genesis's "no partial ships" becomes "no *silent* partial ships" —
slices are legitimate in this project only with an owner scope note (see Close
Gate step 4).

## Required parameters

- `AGENT_NAME` — claim prefix for comments (and titles when concurrent).
- `PROJECT_URL(s)` — default when omitted: the "Duo WP Branchability —
  Correctness Closure" project.
- Poll cadence and limit. Default: every 10 minutes, up to 60 minutes.

Ask before claiming if a required parameter is genuinely ambiguous.

## Read first, every run

1. The Linear project documents: **Architecture Rulings** (adopted decisions —
   escape-hatch pattern, ratified warn-only exceptions, sequencing) and the
   **Implementation Protocol** (issue-closure quality gate). They override an
   issue's text where they conflict; if code, issue, and rulings disagree,
   stop and ask for a ruling instead of silently choosing.
2. `DESIGN.md` posture (loud, blocking, scoped; honest refusal over hollow
   coverage) and `spec/repo-format.md` for anything touching the wire format.
3. The issue's own comments — evidence digests, scope notes, and re-homed
   acceptance criteria live there, not only in the description.

## Environment bootstrap

- Sandbox envs come from `sandbox/bin/pair.sh` (see `docs/sandbox.md`). Create
  scratch pairs with unique names/ports, **destroy them when done**. Heed the
  >2-live-pairs warning; check `pair.sh list` before adding load.
- Never run `docker compose down` / `make clean` against stacks you did not
  create; never touch another agent's pair (the conformance `conf` pair
  belongs to whoever is running the sweep).
- A missing tool is a setup step, not a blocker: provision and proceed;
  escalate only when provisioning itself fails.

## Candidate selection

1. List the project's Backlog issues; sort by priority, then unblock impact,
   then identifier.
2. Inspect serious candidates with `get_issue(includeRelations: true)`. Skip
   anything not Backlog, already assigned/claimed/prefixed by another agent,
   blocked by an open issue, or a parent with unfinished children. No
   relation-capable read = not claimable.
3. **Held-files check (duo-wp-specific):** run `git status`. If the issue's
   likely files carry uncommitted changes you don't own, another session is
   mid-work — the issue is not claimable; report it instead. Never edit a
   file with foreign uncommitted changes; never commit or revert them.
4. Re-fetch the issue immediately before claiming. If anything changed or a
   blocker appeared, drop it and pick again.

## Claim gate

A claim is complete only when Linear readback proves it. Do not edit files
before this passes.

1. Set state In Progress; assign yourself if the account allows.
2. Comment: `Claimed by AGENT_NAME. Original title: {title}.` Prefix the title
   `[AGENT_NAME] ` only when the prompt says multiple agents share the pool.
3. Re-read with relations and confirm: state, claim comment, no new blocker.
   Missing marker → fix and re-read, or release. Never work an unclaimed or
   blocked issue.

## Work and verify

1. Fix the **root cause** within the pinned architecture — no quick fixes,
   silent fallbacks, or compat shims. Match the codebase's comment style
   (rationale-dense docblocks stating constraints and evidence).
2. Held files stay held: if the fix requires one, stop and report — do not
   work around it by editing.
3. Every fix ships with regression coverage that fails against the prior
   defect (a `sandbox/tests/regress_*.sh` or a conformance fixture — read an
   existing one for the idiom). Tests use the product path, not shortcuts.
4. Verify per the issue's Evidence section, plus mechanically: `php -l` every
   touched PHP file, `bash -n` every touched script. Anything touching
   `agent/src`, `manifests/`, or the harness needs the conformance sweep
   (`bash sandbox/conformance/run.sh <manifest>` for affected manifests; the
   full 9-manifest sweep for engine-wide changes). Warnings are not green:
   human output, machine output, and exit status must agree.
5. Out-of-scope discoveries: report on the issue (or file a new one), never
   fix silently.

## Close gate

Strict order; do not flip Linear to Done before the evidence exists.

1. Commit to `main` with the issue id in the message (`DUO-XXXX: …`) so Linear
   auto-links it. One issue per commit where practical.
2. Comment the evidence on the issue: commit SHA, what changed (file:line),
   test tails (paste, don't paraphrase), and anything re-homed or discovered.
3. Scope check: compare the commit against the issue's acceptance criteria.
   Fully satisfied → mark Done.
4. **Slice case:** if deliberately partial, do not mark Done silently — append
   a `## Scope note` to the issue description stating what was delivered
   (with the SHA) and where every remaining criterion was re-homed, then
   return the issue to Backlog with your claim removed. A Done issue must
   never hide unchecked boxes.
5. Re-read the issue and confirm: state, evidence comment, scope note if any.
   Then re-read this file before selecting the next issue.

## Stale claim release

A leftover claim marker is not a tombstone. Before declaring the queue empty:
inspect prefixed/claimed open issues (relations + comments). Release only when
there is no fresh claim comment, no foreign assignee doing visible work, and
no human hold — comment
`Releasing stale claim from [{old}]. Reason: no active ownership found.`,
strip the prefix, return to Backlog, then claim through the normal gate. If
staleness can't be proven, it is not claimable — report it.

## Escalation / empty queue

Blocked (needs a human decision, a held file, a dependency, or verification
that won't provision): comment the blocker on the issue with exact evidence,
leave the claim in place, and report. When nothing is claimable, report each
blocked issue and its blocker, poll at the configured cadence, and stop when
the limit expires — never idle silently, never mark anything Done to make the
queue look better than it is.
